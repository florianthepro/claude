import Fastify, { type FastifyError } from 'fastify'
import cookie from '@fastify/cookie'
import rateLimit from '@fastify/rate-limit'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { config } from './config.js'
import { securityHeaders } from './security/headers.js'
import { sameOrigin } from './security/guards.js'
import { authRoutes } from './auth/routes.js'

const webDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'web')

// Static assets are an explicit allowlist loaded at boot — no path is ever derived
// from request input, so directory traversal is structurally impossible.
const indexHtml = readFileSync(join(webDir, 'index.html'))
const assets: Record<string, { body: Buffer; type: string }> = {
  '/styles.css': { body: readFileSync(join(webDir, 'styles.css')), type: 'text/css; charset=utf-8' },
  '/app.js': { body: readFileSync(join(webDir, 'app.js')), type: 'text/javascript; charset=utf-8' },
}

const app = Fastify({
  trustProxy: config.TRUST_PROXY,
  bodyLimit: 32 * 1024,
  logger: {
    level: config.isProd ? 'warn' : 'info',
    redact: ['req.headers.cookie', 'req.headers.authorization', 'req.headers["x-csrf-token"]'],
  },
})

await app.register(cookie)
await app.register(rateLimit, {
  max: 120,
  timeWindow: '1 minute',
  hook: 'onRequest',
  keyGenerator: (req) => req.ip,
})

securityHeaders(app)

app.addHook('onRequest', async (req, reply) => {
  if (!sameOrigin(req)) {
    await reply.code(403).send({ error: 'bad_origin' })
  }
})

app.setErrorHandler((err: FastifyError, req, reply) => {
  if (err.statusCode && err.statusCode < 500) {
    return reply.code(err.statusCode).send({ error: err.code ?? 'request_error' })
  }
  req.log.error({ err }, 'unhandled')
  return reply.code(500).send({ error: 'internal' })
})

await authRoutes(app)

app.get('/healthz', async () => ({ ok: true }))

app.get('/', async (_req, reply) => {
  reply.header('Cache-Control', 'no-store')
  return reply.type('text/html; charset=utf-8').send(indexHtml)
})

for (const [path, asset] of Object.entries(assets)) {
  app.get(path, async (_req, reply) => {
    reply.header('Cache-Control', 'public, max-age=3600')
    return reply.type(asset.type).send(asset.body)
  })
}

app.setNotFoundHandler((req, reply) => {
  // SPA fallback for extensionless GET paths; everything else is a hard 404.
  if (req.method === 'GET' && !req.url.startsWith('/api') && !req.url.slice(1).includes('.')) {
    reply.header('Cache-Control', 'no-store')
    return reply.type('text/html; charset=utf-8').send(indexHtml)
  }
  return reply.code(404).send({ error: 'not_found' })
})

try {
  await app.listen({ host: config.HOST, port: config.PORT })
  app.log.warn(`citadel listening on ${config.HOST}:${config.PORT}`)
} catch (err) {
  app.log.error(err)
  process.exit(1)
}
