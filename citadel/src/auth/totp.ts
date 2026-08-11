import * as OTPAuth from 'otpauth'
import QRCode from 'qrcode'
import { config } from '../config.js'
import { seal, open } from '../security/crypto.js'
import { hashPassword, verifyPassword } from './password.js'
import { randomBytes } from 'node:crypto'

const ISSUER = 'Citadel'

function totpFor(username: string, base32: string): OTPAuth.TOTP {
  return new OTPAuth.TOTP({
    issuer: ISSUER,
    label: `${username}@${config.origin.hostname}`,
    algorithm: 'SHA1',
    digits: 6,
    period: 30,
    secret: OTPAuth.Secret.fromBase32(base32),
  })
}

export function newSecretSealed(): { base32: string; sealed: string } {
  const secret = new OTPAuth.Secret({ size: 20 })
  return { base32: secret.base32, sealed: seal(Buffer.from(secret.base32, 'utf8')) }
}

export async function enrollmentQr(username: string, base32: string): Promise<{ uri: string; qr: string }> {
  const uri = totpFor(username, base32).toString()
  const qr = await QRCode.toDataURL(uri, { errorCorrectionLevel: 'M', margin: 1, scale: 6 })
  return { uri, qr }
}

// Returns the absolute 30s counter the token belongs to, or null if invalid.
// The caller rejects reuse by requiring a strictly greater counter than last time.
export function totpCounter(sealed: string, token: string): number | null {
  if (!/^\d{6}$/.test(token)) return null
  const base32 = open(sealed).toString('utf8')
  const delta = totpFor('x', base32).validate({ token, window: 1 })
  if (delta === null) return null
  return Math.floor(Date.now() / 1000 / 30) + delta
}

// Backup codes: shown once, stored only as Argon2id hashes.
const normalizeCode = (c: string) => c.toLowerCase().replace(/[^a-z0-9]/g, '')

export async function generateBackupCodes(): Promise<{ plain: string[]; hashes: string[] }> {
  const plain: string[] = []
  const hashes: string[] = []
  for (let i = 0; i < 10; i++) {
    const raw = randomBytes(5).toString('hex') // 10 hex chars
    plain.push(`${raw.slice(0, 5)}-${raw.slice(5)}`)
    hashes.push(await hashPassword(normalizeCode(raw)))
  }
  return { plain, hashes }
}

export function verifyBackupCode(hash: string, code: string): Promise<boolean> {
  return verifyPassword(hash, normalizeCode(code))
}
