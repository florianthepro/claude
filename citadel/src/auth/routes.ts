import type { FastifyInstance, FastifyRequest } from 'fastify'
import { randomUUID } from 'node:crypto'
import { z } from 'zod'
import { db, audit } from '../db.js'
import { config } from '../config.js'
import { hashPassword, verifyPassword, passwordIssues } from './password.js'
import { newSecretSealed, enrollmentQr, totpCounter, generateBackupCodes, verifyBackupCode } from './totp.js'
import { createSession, setSessionCookie, clearSessionCookie, destroySession, COOKIE_NAME } from './session.js'
import { requireAuth } from '../security/guards.js'

const USERNAME = /^[a-z0-9](?:[a-z0-9_.-]{1,30}[a-z0-9])$/
const MAX_FAILED = 5
const LOCK_MS = 15 * 60 * 1000

interface UserRow {
  id: string
  username: string
  password_hash: string
  totp_secret: string | null
  status: string
  failed_attempts: number
  locked_until: number
  totp_last: number
}

const findUser = db.prepare('SELECT * FROM users WHERE username = ?')

// Constant dummy hash to keep login timing uniform for unknown users.
let dummyHash: string | null = null
async function timingSink(pw: string): Promise<void> {
  if (!dummyHash) dummyHash = await hashPassword(randomUUID())
  await verifyPassword(dummyHash, pw)
}

const ip = (req: FastifyRequest) => req.ip
const ua = (req: FastifyRequest) => (req.headers['user-agent'] ?? '').slice(0, 256)
const strict = { config: { rateLimit: { max: config.AUTH_RATE_MAX, timeWindow: '5 minutes' } } }

const registerBody = z.object({ username: z.string().trim().toLowerCase(), password: z.string() })
const confirmBody = z.object({ token: z.string() })
const loginBody = z.object({ username: z.string().trim().toLowerCase(), password: z.string(), otp: z.string() })

export function authRoutes(app: FastifyInstance): void {
  app.post('/api/register', strict, async (req, reply) => {
    if (!config.ALLOW_REGISTRATION) return reply.code(403).send({ error: 'registration_disabled' })
    const parsed = registerBody.safeParse(req.body)
    if (!parsed.success) return reply.code(400).send({ error: 'invalid' })
    const { username, password } = parsed.data
    if (!USERNAME.test(username)) return reply.code(400).send({ error: 'username_invalid' })
    const pwIssues = passwordIssues(password)
    if (pwIssues.length) return reply.code(400).send({ error: 'password_weak', issues: pwIssues })

    if (findUser.get(username)) return reply.code(409).send({ error: 'username_taken' })

    const id = randomUUID()
    const now = Date.now()
    const { base32: secretBase32, sealed } = newSecretSealed()
    const pwHash = await hashPassword(password)
    try {
      db.prepare(
        'INSERT INTO users (id, username, password_hash, totp_secret, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
      ).run(id, username, pwHash, sealed, 'pending', now, now)
    } catch {
      return reply.code(409).send({ error: 'username_taken' })
    }

    const { csrf, raw } = createSession(id, 'enroll', ip(req), ua(req))
    setSessionCookie(reply, raw, 'enroll')
    const { qr, uri } = await enrollmentQr(username, secretBase32)
    audit('register_start', { userId: id, ip: ip(req) })
    reply.header('Cache-Control', 'no-store')
    return { qr, uri, secret: secretBase32, csrf }
  })

  app.post('/api/register/confirm', strict, async (req, reply) => {
    const session = requireAuth(req, reply, 'enroll')
    if (!session) return
    const parsed = confirmBody.safeParse(req.body)
    if (!parsed.success) return reply.code(400).send({ error: 'invalid' })

    const user = db.prepare('SELECT * FROM users WHERE id = ?').get(session.userId) as UserRow | undefined
    if (!user || !user.totp_secret) return reply.code(400).send({ error: 'invalid' })
    const counter = totpCounter(user.totp_secret, parsed.data.token)
    if (counter === null) {
      audit('enroll_fail', { userId: user.id, ip: ip(req) })
      return reply.code(401).send({ error: 'totp_invalid' })
    }

    const { plain, hashes } = await generateBackupCodes()
    const now = Date.now()
    const tx = db.transaction(() => {
      db.prepare('UPDATE users SET status = ?, totp_last = ?, updated_at = ? WHERE id = ?').run('active', counter, now, user.id)
      const ins = db.prepare('INSERT INTO backup_codes (id, user_id, code_hash) VALUES (?, ?, ?)')
      for (const h of hashes) ins.run(randomUUID(), user.id, h)
    })
    tx()

    destroySession(req.cookies[COOKIE_NAME])
    const { csrf, raw } = createSession(user.id, 'full', ip(req), ua(req))
    setSessionCookie(reply, raw, 'full')
    audit('enroll_ok', { userId: user.id, ip: ip(req) })
    reply.header('Cache-Control', 'no-store')
    return { ok: true, csrf, backupCodes: plain }
  })

  app.post('/api/login', strict, async (req, reply) => {
    const parsed = loginBody.safeParse(req.body)
    if (!parsed.success) return reply.code(400).send({ error: 'invalid' })
    const { username, password, otp } = parsed.data
    const generic = () => reply.code(401).send({ error: 'invalid_credentials' })

    const user = findUser.get(username) as UserRow | undefined
    if (!user || user.status !== 'active' || !user.totp_secret) {
      await timingSink(password)
      audit('login_fail', { ip: ip(req), detail: 'no_user' })
      return generic()
    }
    if (user.locked_until > Date.now()) {
      audit('login_locked', { userId: user.id, ip: ip(req) })
      return generic()
    }

    const pwOk = await verifyPassword(user.password_hash, password)
    let otpOk = false
    let newTotpLast = user.totp_last
    if (pwOk) {
      if (/^\d{6}$/.test(otp)) {
        const counter = totpCounter(user.totp_secret, otp)
        if (counter !== null && counter > user.totp_last) {
          otpOk = true
          newTotpLast = counter
        }
      } else {
        otpOk = await checkBackupCode(user, otp)
      }
    }
    if (!pwOk || !otpOk) {
      registerFailure(user)
      audit('login_fail', { userId: user.id, ip: ip(req), detail: pwOk ? 'otp' : 'password' })
      return generic()
    }

    db.prepare('UPDATE users SET failed_attempts = 0, locked_until = 0, totp_last = ?, updated_at = ? WHERE id = ?').run(
      newTotpLast,
      Date.now(),
      user.id,
    )
    const { csrf, raw } = createSession(user.id, 'full', ip(req), ua(req))
    setSessionCookie(reply, raw, 'full')
    audit('login_ok', { userId: user.id, ip: ip(req) })
    reply.header('Cache-Control', 'no-store')
    return { ok: true, csrf, username: user.username }
  })

  app.post('/api/logout', async (req, reply) => {
    const session = requireAuth(req, reply)
    if (!session) return
    destroySession(req.cookies[COOKIE_NAME])
    clearSessionCookie(reply)
    audit('logout', { userId: session.userId, ip: ip(req) })
    return { ok: true }
  })

  app.get('/api/me', async (req, reply) => {
    const session = requireAuth(req, reply)
    if (!session) return
    const user = db.prepare('SELECT username FROM users WHERE id = ?').get(session.userId) as
      | { username: string }
      | undefined
    reply.header('Cache-Control', 'no-store')
    return { authenticated: true, username: user?.username ?? null, csrf: session.csrf }
  })
}

async function checkBackupCode(user: UserRow, otp: string): Promise<boolean> {
  const codes = db
    .prepare('SELECT id, code_hash FROM backup_codes WHERE user_id = ? AND used_at IS NULL')
    .all(user.id) as { id: string; code_hash: string }[]
  for (const c of codes) {
    if (await verifyBackupCode(c.code_hash, otp)) {
      db.prepare('UPDATE backup_codes SET used_at = ? WHERE id = ?').run(Date.now(), c.id)
      return true
    }
  }
  return false
}

// Lock out new logins after repeated failure. Existing sessions are deliberately
// left intact so an attacker cannot log a victim out with bad-password spam.
function registerFailure(user: UserRow): void {
  const attempts = user.failed_attempts + 1
  if (attempts >= MAX_FAILED) {
    db.prepare('UPDATE users SET failed_attempts = 0, locked_until = ? WHERE id = ?').run(Date.now() + LOCK_MS, user.id)
  } else {
    db.prepare('UPDATE users SET failed_attempts = ? WHERE id = ?').run(attempts, user.id)
  }
}
