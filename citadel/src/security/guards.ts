import type { FastifyReply, FastifyRequest } from 'fastify'
import { config } from '../config.js'
import { loadSession, COOKIE_NAME, type Session, type Scope } from '../auth/session.js'
import { safeEqual } from './crypto.js'

const MUTATING = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

// Reject cross-site state changes: the Origin (or Referer) must match PUBLIC_ORIGIN.
export function sameOrigin(req: FastifyRequest): boolean {
  if (!MUTATING.has(req.method)) return true
  const origin = req.headers.origin
  if (origin) return origin === config.origin.origin
  const referer = req.headers.referer
  if (referer) {
    try {
      return new URL(referer).origin === config.origin.origin
    } catch {
      return false
    }
  }
  return false
}

export function currentSession(req: FastifyRequest): Session | null {
  const raw = req.cookies[COOKIE_NAME]
  return loadSession(raw)
}

// Authenticated + CSRF-protected. Returns the session or sends an error and returns null.
export function requireAuth(req: FastifyRequest, reply: FastifyReply, scope: Scope = 'full'): Session | null {
  const session = currentSession(req)
  if (!session || session.scope !== scope) {
    reply.code(401).send({ error: 'unauthorized' })
    return null
  }
  if (MUTATING.has(req.method)) {
    const header = req.headers['x-csrf-token']
    const provided = Array.isArray(header) ? header[0] : header
    if (!provided || !safeEqual(provided, session.csrf)) {
      reply.code(403).send({ error: 'csrf' })
      return null
    }
  }
  return session
}
