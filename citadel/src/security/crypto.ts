import { createCipheriv, createDecipheriv, createHash, randomBytes, timingSafeEqual } from 'node:crypto'
import { config } from '../config.js'

const IV_LEN = 12
const TAG_LEN = 16

// AES-256-GCM sealing for secrets at rest (TOTP seeds). Layout: iv | tag | ciphertext.
export function seal(plaintext: Buffer): string {
  const iv = randomBytes(IV_LEN)
  const cipher = createCipheriv('aes-256-gcm', config.kek, iv)
  const ct = Buffer.concat([cipher.update(plaintext), cipher.final()])
  const tag = cipher.getAuthTag()
  return Buffer.concat([iv, tag, ct]).toString('base64')
}

export function open(blob: string): Buffer {
  const raw = Buffer.from(blob, 'base64')
  const iv = raw.subarray(0, IV_LEN)
  const tag = raw.subarray(IV_LEN, IV_LEN + TAG_LEN)
  const ct = raw.subarray(IV_LEN + TAG_LEN)
  const decipher = createDecipheriv('aes-256-gcm', config.kek, iv)
  decipher.setAuthTag(tag)
  return Buffer.concat([decipher.update(ct), decipher.final()])
}

export function token(bytes = 32): string {
  return randomBytes(bytes).toString('base64url')
}

export function sha256(input: string | Buffer): Buffer {
  return createHash('sha256').update(input).digest()
}

export function sha256hex(input: string | Buffer): string {
  return createHash('sha256').update(input).digest('hex')
}

export function safeEqual(a: string, b: string): boolean {
  const ab = Buffer.from(a)
  const bb = Buffer.from(b)
  if (ab.length !== bb.length) return false
  return timingSafeEqual(ab, bb)
}
