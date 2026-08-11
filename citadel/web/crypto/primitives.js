// Isomorphic crypto primitives over WebCrypto — identical in browser and Node.
// Keypairs are represented as raw bytes ({ priv: pkcs8, pub: raw }) so that all
// protocol state is trivially serializable.

const subtle = globalThis.crypto.subtle
const g = globalThis.crypto

export const randomBytes = (n) => g.getRandomValues(new Uint8Array(n))
export const utf8e = (s) => new TextEncoder().encode(s)
export const utf8d = (b) => new TextDecoder().decode(b)

export function concat(...arrs) {
  let len = 0
  for (const a of arrs) len += a.length
  const out = new Uint8Array(len)
  let o = 0
  for (const a of arrs) {
    out.set(a, o)
    o += a.length
  }
  return out
}

export function b64e(bytes) {
  const b = new Uint8Array(bytes)
  let s = ''
  const chunk = 0x8000
  for (let i = 0; i < b.length; i += chunk) s += String.fromCharCode.apply(null, b.subarray(i, i + chunk))
  return btoa(s)
}

export function b64d(str) {
  const s = atob(str)
  const out = new Uint8Array(s.length)
  for (let i = 0; i < s.length; i++) out[i] = s.charCodeAt(i)
  return out
}

export function ctEq(a, b) {
  if (a.length !== b.length) return false
  let d = 0
  for (let i = 0; i < a.length; i++) d |= a[i] ^ b[i]
  return d === 0
}

export async function genX25519() {
  const kp = await subtle.generateKey({ name: 'X25519' }, true, ['deriveBits'])
  return {
    priv: new Uint8Array(await subtle.exportKey('pkcs8', kp.privateKey)),
    pub: new Uint8Array(await subtle.exportKey('raw', kp.publicKey)),
  }
}

export async function genEd25519() {
  const kp = await subtle.generateKey({ name: 'Ed25519' }, true, ['sign', 'verify'])
  return {
    priv: new Uint8Array(await subtle.exportKey('pkcs8', kp.privateKey)),
    pub: new Uint8Array(await subtle.exportKey('raw', kp.publicKey)),
  }
}

export async function dh(privBytes, pubBytes) {
  const priv = await subtle.importKey('pkcs8', privBytes, { name: 'X25519' }, false, ['deriveBits'])
  const pub = await subtle.importKey('raw', pubBytes, { name: 'X25519' }, false, [])
  return new Uint8Array(await subtle.deriveBits({ name: 'X25519', public: pub }, priv, 256))
}

export async function sign(privBytes, data) {
  const priv = await subtle.importKey('pkcs8', privBytes, { name: 'Ed25519' }, false, ['sign'])
  return new Uint8Array(await subtle.sign({ name: 'Ed25519' }, priv, data))
}

export async function verify(pubBytes, sig, data) {
  const pub = await subtle.importKey('raw', pubBytes, { name: 'Ed25519' }, false, ['verify'])
  return subtle.verify({ name: 'Ed25519' }, pub, sig, data)
}

export async function hkdf(ikm, salt, info, len) {
  const key = await subtle.importKey('raw', ikm, 'HKDF', false, ['deriveBits'])
  return new Uint8Array(await subtle.deriveBits({ name: 'HKDF', hash: 'SHA-256', salt, info }, key, len * 8))
}

export async function hmac(keyBytes, data) {
  const key = await subtle.importKey('raw', keyBytes, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
  return new Uint8Array(await subtle.sign('HMAC', key, data))
}

export async function sha256(data) {
  return new Uint8Array(await subtle.digest('SHA-256', data))
}

export async function aeadEncrypt(keyBytes, iv, plaintext, aad) {
  const key = await subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, false, ['encrypt'])
  return new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv, additionalData: aad, tagLength: 128 }, key, plaintext))
}

export async function aeadDecrypt(keyBytes, iv, ct, aad) {
  const key = await subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, false, ['decrypt'])
  return new Uint8Array(await subtle.decrypt({ name: 'AES-GCM', iv, additionalData: aad, tagLength: 128 }, key, ct))
}

// PBKDF2 wrapping key for the client-side encrypted key backup.
export async function pbkdf2(password, salt, iterations, len) {
  const key = await subtle.importKey('raw', utf8e(password), 'PBKDF2', false, ['deriveBits'])
  return new Uint8Array(
    await subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, key, len * 8),
  )
}
