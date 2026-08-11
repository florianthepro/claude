// X3DH key agreement (Signal spec) over the primitives layer.
// Separate Ed25519 signing key + X25519 DH key per identity (WebCrypto has no XEdDSA).
import { genX25519, genEd25519, dh, sign, verify, hkdf, concat, utf8e } from './primitives.js'

const F = new Uint8Array(32).fill(0xff)
const SALT = new Uint8Array(32)
const INFO = utf8e('Citadel_X3DH_v1')

export async function generateIdentity() {
  return { sign: await genEd25519(), dh: await genX25519() }
}

export async function generatePrekeys(identity, opkCount = 32) {
  const signedKey = await genX25519()
  const sig = await sign(identity.sign.priv, signedKey.pub)
  const oneTime = []
  for (let i = 0; i < opkCount; i++) oneTime.push({ id: crypto.randomUUID(), key: await genX25519() })
  return { signed: { id: crypto.randomUUID(), key: signedKey, sig }, oneTime }
}

// Public directory bundle (private halves stay on the device).
export function publicBundle(identity, prekeys) {
  return {
    ikSign: identity.sign.pub,
    ikDh: identity.dh.pub,
    spkId: prekeys.signed.id,
    spk: prekeys.signed.key.pub,
    spkSig: prekeys.signed.sig,
    opks: prekeys.oneTime.map((o) => ({ id: o.id, pub: o.key.pub })),
  }
}

async function kdf(dh1, dh2, dh3, dh4) {
  return hkdf(concat(F, dh1, dh2, dh3, dh4), SALT, INFO, 32)
}

// Initiator side. `bundle` is a fetched public bundle with one opk (or null).
export async function initiator(identity, bundle) {
  const ok = await verify(bundle.ikSign, bundle.spkSig, bundle.spk)
  if (!ok) throw new Error('spk_signature_invalid')

  const ek = await genX25519()
  const dh1 = await dh(identity.dh.priv, bundle.spk)
  const dh2 = await dh(ek.priv, bundle.ikDh)
  const dh3 = await dh(ek.priv, bundle.spk)
  const dh4 = bundle.opk ? await dh(ek.priv, bundle.opk.pub) : new Uint8Array(0)
  const sk = await kdf(dh1, dh2, dh3, dh4)

  return {
    sk,
    ad: concat(identity.sign.pub, bundle.ikSign),
    theirSpk: bundle.spk,
    prekey: {
      ikSign: identity.sign.pub,
      ikDh: identity.dh.pub,
      ek: ek.pub,
      spkId: bundle.spkId,
      opkId: bundle.opk ? bundle.opk.id : null,
    },
  }
}

// Responder side. `prekey` fields are raw bytes. Consumes the one-time prekey.
export async function responder(identity, prekeys, prekey) {
  if (prekey.spkId !== prekeys.signed.id) throw new Error('unknown_spk')
  const spkPriv = prekeys.signed.key.priv

  let opk = null
  if (prekey.opkId) {
    const idx = prekeys.oneTime.findIndex((o) => o.id === prekey.opkId)
    if (idx < 0) throw new Error('unknown_opk')
    opk = prekeys.oneTime[idx]
    prekeys.oneTime.splice(idx, 1)
  }

  const dh1 = await dh(spkPriv, prekey.ikDh)
  const dh2 = await dh(identity.dh.priv, prekey.ek)
  const dh3 = await dh(spkPriv, prekey.ek)
  const dh4 = opk ? await dh(opk.key.priv, prekey.ek) : new Uint8Array(0)
  const sk = await kdf(dh1, dh2, dh3, dh4)

  return {
    sk,
    ad: concat(prekey.ikSign, identity.sign.pub),
    mySpk: prekeys.signed.key,
    consumedOpkId: opk ? opk.id : null,
  }
}
