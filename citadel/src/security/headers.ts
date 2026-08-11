import type { FastifyInstance } from 'fastify'
import { config } from '../config.js'

const CSP = [
  "default-src 'none'",
  "script-src 'self'",
  "style-src 'self'",
  "img-src 'self' data:",
  "connect-src 'self'",
  "font-src 'self'",
  "manifest-src 'self'",
  "base-uri 'none'",
  "form-action 'self'",
  "frame-ancestors 'none'",
  "object-src 'none'",
  "require-trusted-types-for 'script'",
].join('; ')

const PERMISSIONS = [
  'accelerometer=()',
  'autoplay=()',
  'camera=()',
  'display-capture=()',
  'encrypted-media=()',
  'fullscreen=(self)',
  'geolocation=()',
  'gyroscope=()',
  'magnetometer=()',
  'microphone=()',
  'midi=()',
  'payment=()',
  'usb=()',
].join(', ')

export function securityHeaders(app: FastifyInstance): void {
  app.addHook('onRequest', async (_req, reply) => {
    reply.header('Content-Security-Policy', CSP)
    reply.header('X-Content-Type-Options', 'nosniff')
    reply.header('X-Frame-Options', 'DENY')
    reply.header('Referrer-Policy', 'no-referrer')
    reply.header('Cross-Origin-Opener-Policy', 'same-origin')
    reply.header('Cross-Origin-Embedder-Policy', 'require-corp')
    reply.header('Cross-Origin-Resource-Policy', 'same-origin')
    reply.header('Permissions-Policy', PERMISSIONS)
    reply.header('X-Permitted-Cross-Domain-Policies', 'none')
    reply.header('Origin-Agent-Cluster', '?1')
    if (config.COOKIE_SECURE) {
      reply.header('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload')
    }
    reply.removeHeader('X-Powered-By')
  })
}
