// Per-conversation session: binds X3DH + Double Ratchet, defines the JSON wire
// format, computes safety numbers, and (de)serializes session state for storage.
import * as x3dh from './x3dh.js'
import * as dr from './doubleratchet.js'
import { b64e, b64d, concat, utf8e, utf8d, sha256 } from './primitives.js'

// --- wire format ---------------------------------------------------------
// normal:  { t:'m', h:{dh,pn,n}, ct }
// prekey:  { t:'p', pre:{ikSign,ikDh,ek,spkId,opkId}, h:{dh,pn,n}, ct }

function encHeader(h) {
  return { dh: b64e(h.dh), pn: h.pn, n: h.n }
}
function decHeader(h) {
  return { dh: b64d(h.dh), pn: h.pn, n: h.n }
}

export async function startOutbound(identity, peer, bundle) {
  const init = await x3dh.initiator(identity, bundle)
  const ratchet = await dr.initAlice(init.sk, init.theirSpk)
  return {
    peer,
    ad: init.ad,
    ratchet,
    peerIkSign: bundle.ikSign,
    prekey: init.prekey,
    established: false,
  }
}

export async function encrypt(session, text) {
  const { header, ct } = await dr.encrypt(session.ratchet, utf8e(text), session.ad)
  const msg = { t: 'm', h: encHeader(header), ct: b64e(ct) }
  if (session.prekey && !session.established) {
    msg.t = 'p'
    msg.pre = {
      ikSign: b64e(session.prekey.ikSign),
      ikDh: b64e(session.prekey.ikDh),
      ek: b64e(session.prekey.ek),
      spkId: session.prekey.spkId,
      opkId: session.prekey.opkId,
    }
  }
  return msg
}

// Establishes a responder session from a prekey message when none exists yet.
export async function receive(identity, prekeys, existing, peer, wire) {
  let session = existing
  let consumedOpkId = null

  if (!session) {
    if (wire.t !== 'p') throw new Error('no_session')
    const pre = {
      ikSign: b64d(wire.pre.ikSign),
      ikDh: b64d(wire.pre.ikDh),
      ek: b64d(wire.pre.ek),
      spkId: wire.pre.spkId,
      opkId: wire.pre.opkId,
    }
    const resp = await x3dh.responder(identity, prekeys, pre)
    session = {
      peer,
      ad: resp.ad,
      ratchet: dr.initBob(resp.sk, resp.mySpk),
      peerIkSign: pre.ikSign,
      prekey: null,
      established: true,
    }
    consumedOpkId = resp.consumedOpkId
  }

  const plaintext = utf8d(await dr.decrypt(session.ratchet, decHeader(wire.h), b64d(wire.ct), session.ad))
  session.established = true
  return { session, plaintext, consumedOpkId }
}

// --- safety number (out-of-band MITM check) ------------------------------
const FPR_ITERS = 5200

async function fingerprint(ikSign) {
  let acc = concat(utf8e('Citadel_SN_v1'), ikSign)
  for (let i = 0; i < FPR_ITERS; i++) acc = await sha256(concat(acc, ikSign))
  const digits = []
  for (let i = 0; i < 6; i++) {
    const chunk = acc.slice(i * 5, i * 5 + 5)
    let n = 0
    for (const b of chunk) n = n * 256 + b
    digits.push(String(n % 100000).padStart(5, '0'))
  }
  return digits.join('')
}

// Order-independent 60-digit number; identical on both devices iff no MITM.
export async function safetyNumber(myIkSign, peerIkSign) {
  const a = b64e(myIkSign)
  const b = b64e(peerIkSign)
  const [lo, hi] = a < b ? [myIkSign, peerIkSign] : [peerIkSign, myIkSign]
  const combined = (await fingerprint(lo)) + (await fingerprint(hi))
  return combined.match(/.{1,5}/g).join(' ')
}

// --- serialization -------------------------------------------------------
export function serializeSession(session) {
  const r = session.ratchet
  return {
    peer: session.peer,
    ad: b64e(session.ad),
    peerIkSign: b64e(session.peerIkSign),
    prekey: session.prekey
      ? {
          ikSign: b64e(session.prekey.ikSign),
          ikDh: b64e(session.prekey.ikDh),
          ek: b64e(session.prekey.ek),
          spkId: session.prekey.spkId,
          opkId: session.prekey.opkId,
        }
      : null,
    established: session.established,
    ratchet: {
      rk: b64e(r.rk),
      dhs: { priv: b64e(r.dhs.priv), pub: b64e(r.dhs.pub) },
      dhr: r.dhr ? b64e(r.dhr) : null,
      cks: r.cks ? b64e(r.cks) : null,
      ckr: r.ckr ? b64e(r.ckr) : null,
      ns: r.ns,
      nr: r.nr,
      pn: r.pn,
      skipped: [...r.skipped.entries()].map(([k, v]) => [k, b64e(v)]),
    },
  }
}

export function deserializeSession(s) {
  const r = s.ratchet
  return {
    peer: s.peer,
    ad: b64d(s.ad),
    peerIkSign: b64d(s.peerIkSign),
    prekey: s.prekey
      ? {
          ikSign: b64d(s.prekey.ikSign),
          ikDh: b64d(s.prekey.ikDh),
          ek: b64d(s.prekey.ek),
          spkId: s.prekey.spkId,
          opkId: s.prekey.opkId,
        }
      : null,
    established: s.established,
    ratchet: {
      rk: b64d(r.rk),
      dhs: { priv: b64d(r.dhs.priv), pub: b64d(r.dhs.pub) },
      dhr: r.dhr ? b64d(r.dhr) : null,
      cks: r.cks ? b64d(r.cks) : null,
      ckr: r.ckr ? b64d(r.ckr) : null,
      ns: r.ns,
      nr: r.nr,
      pn: r.pn,
      skipped: new Map(r.skipped.map(([k, v]) => [k, b64d(v)])),
    },
  }
}
