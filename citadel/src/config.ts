import { z } from 'zod'

const bool = z
  .string()
  .transform((v) => v === '1' || v.toLowerCase() === 'true')

const schema = z.object({
  NODE_ENV: z.enum(['development', 'production']).default('development'),
  HOST: z.string().default('127.0.0.1'),
  PORT: z.coerce.number().int().min(1).max(65535).default(8787),
  PUBLIC_ORIGIN: z.string().url(),
  KEK_BASE64: z.string().min(1),
  COOKIE_SECURE: bool.default('true'),
  TRUST_PROXY: z.coerce.number().int().min(0).max(8).default(1),
  ALLOW_REGISTRATION: bool.default('true'),
  RATE_MAX: z.coerce.number().int().min(1).default(120),
  AUTH_RATE_MAX: z.coerce.number().int().min(1).default(10),
})

const parsed = schema.safeParse(process.env)
if (!parsed.success) {
  console.error('Invalid environment:\n' + parsed.error.issues.map((i) => `  ${i.path.join('.')}: ${i.message}`).join('\n'))
  process.exit(1)
}

const kek = Buffer.from(parsed.data.KEK_BASE64, 'base64')
if (kek.length !== 32) {
  console.error('KEK_BASE64 must decode to exactly 32 bytes. Generate one with: npm run keygen')
  process.exit(1)
}

export const config = {
  ...parsed.data,
  kek,
  isProd: parsed.data.NODE_ENV === 'production',
  origin: new URL(parsed.data.PUBLIC_ORIGIN),
} as const
