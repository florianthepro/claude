import { db } from '../db.js'
import { token, sha256hex } from '../security/crypto.js'
import { config } from '../config.js'
import type { FastifyReply } from 'fastify'

const IDLE_MS = 30 * 60 * 1000
const ABSOLUTE_MS = 12 * 60 * 60 * 1000
const ENROLL_MS = 15 * 60 * 1000

export type Scope = 'enroll' | 'full'
export const COOKIE_NAME = config.COOKIE_SECURE ? '__Host-sid' : 'sid'

export interface Session {
  id: string
  userId: string
  csrf: string
  scope: Scope
}

export function createSession(
  userId: string,
  scope: Scope,
  ip: string | null,
  ua: string | null,
): { raw: string; csrf: string } {
  const raw = token(32)
  const id = sha256hex(raw)
  const csrf = token(32)
  const now = Date.now()
  const ttl = scope === 'enroll' ? ENROLL_MS : ABSOLUTE_MS
  db.prepare(
    'INSERT INTO sessions (id, user_id, scope, csrf, created_at, last_seen, expires_at, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
  ).run(id, userId, scope, csrf, now, now, now + ttl, ip, ua)
  return { raw, csrf }
}

export function loadSession(raw: string | undefined): Session | null {
  if (!raw) return null
  const id = sha256hex(raw)
  const row = db
    .prepare('SELECT id, user_id, scope, csrf, last_seen, expires_at FROM sessions WHERE id = ?')
    .get(id) as
    | { id: string; user_id: string; scope: Scope; csrf: string; last_seen: number; expires_at: number }
    | undefined
  if (!row) return null
  const now = Date.now()
  if (now > row.expires_at || now - row.last_seen > IDLE_MS) {
    db.prepare('DELETE FROM sessions WHERE id = ?').run(id)
    return null
  }
  db.prepare('UPDATE sessions SET last_seen = ? WHERE id = ?').run(now, id)
  return { id: row.id, userId: row.user_id, scope: row.scope, csrf: row.csrf }
}

export function destroySession(raw: string | undefined): void {
  if (!raw) return
  db.prepare('DELETE FROM sessions WHERE id = ?').run(sha256hex(raw))
}

export function destroyAllForUser(userId: string): void {
  db.prepare('DELETE FROM sessions WHERE user_id = ?').run(userId)
}

export function setSessionCookie(reply: FastifyReply, raw: string, scope: Scope): void {
  reply.setCookie(COOKIE_NAME, raw, {
    path: '/',
    httpOnly: true,
    secure: config.COOKIE_SECURE,
    sameSite: 'strict',
    maxAge: Math.floor((scope === 'enroll' ? ENROLL_MS : ABSOLUTE_MS) / 1000),
  })
}

export function clearSessionCookie(reply: FastifyReply): void {
  reply.clearCookie(COOKIE_NAME, { path: '/' })
}
