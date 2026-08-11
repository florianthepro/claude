// Security regression tests against a running server (COOKIE_SECURE=false):
// TOTP replay rejection, lockout not destroying sessions, Origin/CSRF guards.
import * as OTPAuth from 'otpauth'

const BASE = process.env.BASE ?? 'http://127.0.0.1:8787'
const ORIGIN = process.env.PUBLIC_ORIGIN ?? BASE

let fails = 0
const check = (name: string, cond: boolean) => {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}`)
  if (!cond) fails++
}

const totp = (secret: string, step = 0) =>
  new OTPAuth.TOTP({ algorithm: 'SHA1', digits: 6, period: 30, secret: OTPAuth.Secret.fromBase32(secret) }).generate({
    timestamp: Date.now() + step * 30000,
  })

function client() {
  const jar = new Map<string, string>()
  const c = {
    csrf: null as string | null,
    async call(method: string, path: string, body?: unknown, origin: string = ORIGIN) {
      const headers: Record<string, string> = { Origin: origin }
      if (body !== undefined) headers['Content-Type'] = 'application/json'
      if (jar.size) headers['Cookie'] = [...jar].map(([k, v]) => `${k}=${v}`).join('; ')
      if (c.csrf && method !== 'GET') headers['X-CSRF-Token'] = c.csrf
      const res = await fetch(BASE + path, { method, headers, body: body ? JSON.stringify(body) : undefined })
      for (const ck of res.headers.getSetCookie()) {
        const [pair] = ck.split(';')
        const eq = pair.indexOf('=')
        if (eq > 0) jar.set(pair.slice(0, eq), pair.slice(eq + 1))
      }
      let data: any = null
      try {
        data = await res.json()
      } catch {
        /* empty */
      }
      return { status: res.status, data }
    },
  }
  return c
}

async function signup(c: ReturnType<typeof client>, username: string, password: string) {
  const reg = await c.call('POST', '/api/register', { username, password })
  c.csrf = reg.data.csrf
  const secret = reg.data.secret as string
  const conf = await c.call('POST', '/api/register/confirm', { token: totp(secret) })
  c.csrf = conf.data.csrf
  return secret
}

const stamp = process.hrtime.bigint().toString(16).slice(-6)
const pw = 'a strong password 123'

// --- 1: TOTP replay -------------------------------------------------------
{
  const u = 'sec1_' + stamp
  const c = client()
  const secret = await signup(c, u, pw)
  await c.call('POST', '/api/logout')
  c.csrf = null

  const code = totp(secret, 1)
  const first = await client().call('POST', '/api/login', { username: u, password: pw, otp: code })
  check('1: fresh TOTP login succeeds', first.status === 200)
  const replay = await client().call('POST', '/api/login', { username: u, password: pw, otp: code })
  check('1: replayed TOTP code rejected', replay.status === 401)
  const older = await client().call('POST', '/api/login', { username: u, password: pw, otp: totp(secret, 0) })
  check('1: an equal-or-older code is rejected', older.status === 401)
}

// --- 2: lockout does not destroy existing sessions ------------------------
{
  const u = 'sec2_' + stamp
  const victim = client()
  const secret = await signup(victim, u, pw)
  check('2: victim session valid before attack', (await victim.call('GET', '/api/me')).status === 200)

  for (let i = 0; i < 5; i++) await client().call('POST', '/api/login', { username: u, password: 'wrong', otp: '000000' })

  check('2: victim session still valid after 5 failed logins', (await victim.call('GET', '/api/me')).status === 200)
  const locked = await client().call('POST', '/api/login', { username: u, password: pw, otp: totp(secret, 1) })
  check('2: account is locked to new logins', locked.status === 401)

  const saved = victim.csrf
  victim.csrf = null
  check('2: CSRF token required for mutations', (await victim.call('POST', '/api/logout')).status === 403)
  victim.csrf = saved
}

// --- 3: cross-origin state change blocked ---------------------------------
{
  const bad = await client().call('POST', '/api/login', { username: 'x', password: 'y', otp: '000000' }, 'https://evil.example')
  check('3: mismatched Origin rejected', bad.status === 403 && bad.data?.error === 'bad_origin')
}

console.log(fails === 0 ? '\nALL PASS' : `\n${fails} FAILURE(S)`)
process.exit(fails === 0 ? 0 : 1)
