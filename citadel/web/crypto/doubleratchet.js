// Double Ratchet (Signal spec) over the primitives layer.
// Per-message keys via a DH ratchet + symmetric KDF chains → forward secrecy and
// post-compromise security. Handles out-of-order and dropped messages.
import { genX25519, dh, hkdf, hmac, aeadEncrypt, aeadDecrypt, concat, b64e, ctEq, utf8e } from './primitives.js'

const MAX_SKIP = 1000
const INFO_RK = utf8e('Citadel_RK_v1')
const INFO_MK = utf8e('Citadel_MK_v1')
const ZERO32 = new Uint8Array(32)

function u32be(n) {
  const b = new Uint8Array(4)
  new DataView(b.buffer).setUint32(0, n, false)
  return b
}

function headerBytes(h) {
  return concat(h.dh, u32be(h.pn), u32be(h.n))
}

async function kdfRK(rk, dhOut) {
  const out = await hkdf(dhOut, rk, INFO_RK, 64)
  return [out.slice(0, 32), out.slice(32, 64)]
}

async function kdfCK(ck) {
  const mk = await hmac(ck, new Uint8Array([1]))
  const next = await hmac(ck, new Uint8Array([2]))
  return [next, mk]
}

async function msgKeys(mk) {
  const out = await hkdf(mk, ZERO32, INFO_MK, 44)
  return { key: out.slice(0, 32), iv: out.slice(32, 44) }
}

export async function initAlice(sk, theirSpkPub) {
  const dhs = await genX25519()
  const [rk, cks] = await kdfRK(sk, await dh(dhs.priv, theirSpkPub))
  return { rk, dhs, dhr: theirSpkPub, cks, ckr: null, ns: 0, nr: 0, pn: 0, skipped: new Map() }
}

export function initBob(sk, mySpkKeypair) {
  return { rk: sk, dhs: mySpkKeypair, dhr: null, cks: null, ckr: null, ns: 0, nr: 0, pn: 0, skipped: new Map() }
}

export async function encrypt(state, plaintext, ad) {
  const [cks, mk] = await kdfCK(state.cks)
  state.cks = cks
  const header = { dh: state.dhs.pub, pn: state.pn, n: state.ns }
  state.ns++
  const { key, iv } = await msgKeys(mk)
  const ct = await aeadEncrypt(key, iv, plaintext, concat(ad, headerBytes(header)))
  return { header, ct }
}

export async function decrypt(state, header, ct, ad) {
  const skKey = b64e(header.dh) + ':' + header.n
  if (state.skipped.has(skKey)) {
    const mk = state.skipped.get(skKey)
    state.skipped.delete(skKey)
    const { key, iv } = await msgKeys(mk)
    return aeadDecrypt(key, iv, ct, concat(ad, headerBytes(header)))
  }

  if (state.dhr === null || !ctEq(header.dh, state.dhr)) {
    await skipMessageKeys(state, header.pn)
    await dhRatchet(state, header)
  }
  await skipMessageKeys(state, header.n)

  const [ckr, mk] = await kdfCK(state.ckr)
  state.ckr = ckr
  state.nr++
  const { key, iv } = await msgKeys(mk)
  return aeadDecrypt(key, iv, ct, concat(ad, headerBytes(header)))
}

async function skipMessageKeys(state, until) {
  if (state.ckr === null) return
  if (state.nr + MAX_SKIP < until) throw new Error('too_many_skipped')
  while (state.nr < until) {
    const [ckr, mk] = await kdfCK(state.ckr)
    state.ckr = ckr
    state.skipped.set(b64e(state.dhr) + ':' + state.nr, mk)
    state.nr++
  }
}

async function dhRatchet(state, header) {
  state.pn = state.ns
  state.ns = 0
  state.nr = 0
  state.dhr = header.dh
  ;[state.rk, state.ckr] = await kdfRK(state.rk, await dh(state.dhs.priv, state.dhr))
  state.dhs = await genX25519()
  ;[state.rk, state.cks] = await kdfRK(state.rk, await dh(state.dhs.priv, state.dhr))
}
