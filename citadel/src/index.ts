import Fastify, { type FastifyError } from 'fastify'
import cookie from '@fastify/cookie'
import rateLimit from '@fastify/rate-limit'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join, extname } from 'node:path'
import { config } from './config.js'
import { securityHeaders } from './security/headers.js'
import { sameOrigin } from './security/guards.js'
import { authRoutes } from './auth/routes.js'
import { messengerRoutes } from './messenger/routes.js'

const webDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'web')

const MIME: Record<string, string> = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
}

// Static assets are an allowlist built at boot by walking web/. Requests are served
// by exact map lookup — no path is ever derived from input, so traversal is impossible.
function walk(dir: string, base = ''): [string, string][] {
  const out: [string, string][] = []
  for (const name of readdirSync(dir)) {
    const full = join(dir, name)
    const rel = `${base}/${name}`
    if (statSync(full).isDirectory()) out.push(...walk(full, rel))
    else out.push([rel, full])
  }
  return out
}

const assets = new Map<string, { body: Buffer; type: string }>()
for (const [urlPath, full] of walk(webDir)) {
  const type = MIME[extname(full)]
  if (type) assets.set(urlPath, { body: readFileSync(full), type })
}
const indexHtml = assets.get('/index.html')?.body
if (!indexHtml) throw new Error('web/index.html missing')

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
await messengerRoutes(app)

app.get('/healthz', async () => ({ ok: true }))

app.get('/', async (_req, reply) => {
  reply.header('Cache-Control', 'no-store')
  return reply.type('text/html; charset=utf-8').send(indexHtml)
})

for (const [urlPath, asset] of assets) {
  if (urlPath === '/index.html') continue
  app.get(urlPath, async (_req, reply) => {
    reply.header('Cache-Control', 'public, max-age=3600')
    return reply.type(asset.type).send(asset.body)
  })
}

app.setNotFoundHandler((req, reply) => {
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
