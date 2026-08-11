import { hash, verify, Algorithm } from '@node-rs/argon2'

// OWASP-aligned Argon2id parameters, raised for a security-first deployment.
const params = { algorithm: Algorithm.Argon2id, memoryCost: 65536, timeCost: 3, parallelism: 1 }

const COMMON = new Set([
  'password', 'passwort', '123456', '12345678', '123456789', 'qwerty', 'qwertz',
  'iloveyou', 'admin', 'welcome', 'letmein', 'password1', '111111', '000000',
])

export function passwordIssues(pw: string): string[] {
  const issues: string[] = []
  if (pw.length < 12) issues.push('Mindestens 12 Zeichen.')
  if (pw.length > 256) issues.push('Höchstens 256 Zeichen.')
  if (COMMON.has(pw.toLowerCase())) issues.push('Zu verbreitet.')
  return issues
}

export function hashPassword(pw: string): Promise<string> {
  return hash(pw, params)
}

export function verifyPassword(stored: string, pw: string): Promise<boolean> {
  return verify(stored, pw).catch(() => false)
}
