// Kryptographische Primitive im Browser.
//
// WebCrypto liefert SHA-256, HMAC, X25519 und Ed25519. Was fehlt, ist
// ChaCha20-Poly1305 — das ist hier nachgebaut, byteweise identisch zur
// Python- und PHP-Fassung. Ohne diese Uebereinstimmung koennte der
// Web-Client keine Ablage lesen, die ein anderer Client geschrieben hat.
//
// Wie in den anderen Fassungen gilt: dieser Code ist nicht laufzeitkonstant.

export const enc = new TextEncoder();
export const dec = new TextDecoder();

export const hex = (b) => [...new Uint8Array(b)].map(x => x.toString(16).padStart(2, '0')).join('');
export const unhex = (s) => new Uint8Array(s.match(/../g)?.map(h => parseInt(h, 16)) ?? []);

export function concat(...parts) {
  const total = parts.reduce((n, p) => n + p.length, 0);
  const out = new Uint8Array(total);
  let at = 0;
  for (const p of parts) { out.set(p, at); at += p.length; }
  return out;
}

export const equal = (a, b) =>
  a.length === b.length && a.every((v, i) => v === b[i]);

export const random = (n) => crypto.getRandomValues(new Uint8Array(n));

// -- Hash und Ableitung ----------------------------------------------------

export async function sha256(...parts) {
  return new Uint8Array(await crypto.subtle.digest('SHA-256', concat(...parts)));
}

export async function hmac(key, ...parts) {
  const k = await crypto.subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-256' },
                                          false, ['sign']);
  return new Uint8Array(await crypto.subtle.sign('HMAC', k, concat(...parts)));
}

/** HKDF mit Protokoll-Label — dieselbe Ableitung wie nyx/primitives/kdf.py. */
export async function hkdf(ikm, label, length = 32, salt = new Uint8Array(32)) {
  const prk = await hmac(salt.length ? salt : new Uint8Array(32), ikm);
  const info = enc.encode(label);
  let out = new Uint8Array(0), block = new Uint8Array(0), counter = 1;
  while (out.length < length) {
    block = await hmac(prk, block, info, new Uint8Array([counter++]));
    out = concat(out, block);
  }
  return out.slice(0, length);
}

// -- ChaCha20-Poly1305 (RFC 8439) -----------------------------------------

const SIGMA = enc.encode('expand 32-byte k');

function rotl(v, n) { return ((v << n) | (v >>> (32 - n))) >>> 0; }

function quarter(s, a, b, c, d) {
  s[a] = (s[a] + s[b]) >>> 0; s[d] = rotl(s[d] ^ s[a], 16);
  s[c] = (s[c] + s[d]) >>> 0; s[b] = rotl(s[b] ^ s[c], 12);
  s[a] = (s[a] + s[b]) >>> 0; s[d] = rotl(s[d] ^ s[a], 8);
  s[c] = (s[c] + s[d]) >>> 0; s[b] = rotl(s[b] ^ s[c], 7);
}

function chachaBlock(key, counter, nonce) {
  const view = new DataView(concat(SIGMA, key, new Uint8Array(4), nonce).buffer);
  const state = new Uint32Array(16);
  for (let i = 0; i < 16; i++) state[i] = view.getUint32(i * 4, true);
  state[12] = counter >>> 0;

  const work = state.slice();
  for (let i = 0; i < 10; i++) {
    quarter(work, 0, 4, 8, 12); quarter(work, 1, 5, 9, 13);
    quarter(work, 2, 6, 10, 14); quarter(work, 3, 7, 11, 15);
    quarter(work, 0, 5, 10, 15); quarter(work, 1, 6, 11, 12);
    quarter(work, 2, 7, 8, 13); quarter(work, 3, 4, 9, 14);
  }

  const out = new Uint8Array(64);
  const dv = new DataView(out.buffer);
  for (let i = 0; i < 16; i++) dv.setUint32(i * 4, (work[i] + state[i]) >>> 0, true);
  return out;
}

export function chacha20(key, counter, nonce, data) {
  const out = new Uint8Array(data.length);
  for (let off = 0; off < data.length; off += 64) {
    const block = chachaBlock(key, counter + off / 64, nonce);
    for (let i = 0; i < 64 && off + i < data.length; i++) {
      out[off + i] = data[off + i] ^ block[i];
    }
  }
  return out;
}

function poly1305(key, msg) {
  // 130-Bit-Arithmetik ueber BigInt: kurz und nachpruefbar.
  const P = (1n << 130n) - 5n;
  const le = (bytes) => bytes.reduceRight((acc, b) => (acc << 8n) | BigInt(b), 0n);
  const r = le(key.slice(0, 16)) & 0x0ffffffc0ffffffc0ffffffc0fffffffn;
  const s = le(key.slice(16, 32));

  let acc = 0n;
  for (let off = 0; off < msg.length; off += 16) {
    const chunk = msg.slice(off, off + 16);
    acc = ((acc + le(concat(chunk, new Uint8Array([1])))) * r) % P;
  }
  acc = (acc + s) & ((1n << 128n) - 1n);

  const out = new Uint8Array(16);
  for (let i = 0; i < 16; i++) out[i] = Number((acc >> BigInt(8 * i)) & 0xffn);
  return out;
}

const pad16 = (d) => new Uint8Array(d.length % 16 ? 16 - (d.length % 16) : 0);

function lengths(aad, ct) {
  const out = new Uint8Array(16);
  new DataView(out.buffer).setBigUint64(0, BigInt(aad.length), true);
  new DataView(out.buffer).setBigUint64(8, BigInt(ct.length), true);
  return out;
}

function tagFor(key, nonce, aad, ct) {
  const polyKey = chachaBlock(key, 0, nonce).slice(0, 32);
  return poly1305(polyKey, concat(aad, pad16(aad), ct, pad16(ct), lengths(aad, ct)));
}

export function seal(key, nonce, plaintext, aad = new Uint8Array(0)) {
  const ct = chacha20(key, 1, nonce, plaintext);
  return concat(ct, tagFor(key, nonce, aad, ct));
}

export class AuthError extends Error {}

export function open(key, nonce, boxed, aad = new Uint8Array(0)) {
  if (boxed.length < 16) throw new AuthError('Chiffrat zu kurz');
  const ct = boxed.slice(0, -16);
  if (!equal(boxed.slice(-16), tagFor(key, nonce, aad, ct))) {
    throw new AuthError('Authentifizierungs-Tag stimmt nicht');
  }
  return chacha20(key, 1, nonce, ct);
}

// -- Kurven ----------------------------------------------------------------

export async function x25519Keypair() {
  const pair = await crypto.subtle.generateKey({ name: 'X25519' }, true, ['deriveBits']);
  const priv = await crypto.subtle.exportKey('pkcs8', pair.privateKey);
  const pub = await crypto.subtle.exportKey('raw', pair.publicKey);
  return { priv: new Uint8Array(priv), pub: new Uint8Array(pub) };
}

export async function x25519Shared(privPkcs8, peerPub) {
  const priv = await crypto.subtle.importKey('pkcs8', privPkcs8, { name: 'X25519' },
                                             false, ['deriveBits']);
  const pub = await crypto.subtle.importKey('raw', peerPub, { name: 'X25519' }, false, []);
  const bits = await crypto.subtle.deriveBits({ name: 'X25519', public: pub }, priv, 256);
  const shared = new Uint8Array(bits);
  if (shared.every(b => b === 0)) throw new Error('entartetes X25519-Ergebnis');
  return shared;
}

export async function ed25519Keypair() {
  const pair = await crypto.subtle.generateKey({ name: 'Ed25519' }, true, ['sign', 'verify']);
  return {
    priv: new Uint8Array(await crypto.subtle.exportKey('pkcs8', pair.privateKey)),
    pub: new Uint8Array(await crypto.subtle.exportKey('raw', pair.publicKey)),
  };
}

export async function ed25519Sign(privPkcs8, msg) {
  const key = await crypto.subtle.importKey('pkcs8', privPkcs8, { name: 'Ed25519' },
                                            false, ['sign']);
  return new Uint8Array(await crypto.subtle.sign('Ed25519', key, msg));
}

export async function ed25519Verify(pub, msg, sig) {
  try {
    const key = await crypto.subtle.importKey('raw', pub, { name: 'Ed25519' },
                                              false, ['verify']);
    return await crypto.subtle.verify('Ed25519', key, sig, msg);
  } catch {
    return false;
  }
}

// -- Loeschung -------------------------------------------------------------

/**
 * Ueberschreibt einen Schluesselpuffer.
 *
 * In JavaScript ist das eine schwaechere Zusage als in C: die Laufzeit darf
 * Kopien angelegt haben, an die wir nicht herankommen. Wir tun trotzdem,
 * was moeglich ist, und sagen in docs/sicherheitshinweis.md deutlich, wo
 * die Grenze liegt.
 */
export function wipe(buf) {
  if (buf instanceof Uint8Array) buf.fill(0);
}

// -- Base32 fuer Adressen --------------------------------------------------

const B32 = 'abcdefghijklmnopqrstuvwxyz234567';

export function base32(raw) {
  let bits = '', out = '';
  for (const byte of raw) bits += byte.toString(2).padStart(8, '0');
  for (let i = 0; i < bits.length; i += 5) {
    out += B32[parseInt(bits.slice(i, i + 5).padEnd(5, '0'), 2)];
  }
  return out;
}

export function unbase32(text) {
  let bits = '';
  for (const ch of text.toLowerCase()) {
    const index = B32.indexOf(ch);
    if (index < 0) throw new Error('ungueltiges Zeichen in der Adresse');
    bits += index.toString(2).padStart(5, '0');
  }
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return new Uint8Array(bytes);
}
