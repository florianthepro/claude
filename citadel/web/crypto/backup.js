// Client-side encrypted key backup. Private keys are wrapped under a key derived
// from a recovery passphrase the server never sees, so a user can restore their
// identity on any browser while the server holds only ciphertext.
import { pbkdf2, aeadEncrypt, aeadDecrypt, randomBytes, b64e, b64d, utf8e, utf8d } from './primitives.js'

const ITERS = 310000
const kp = (k) => ({ priv: b64e(k.priv), pub: b64e(k.pub) })
const unkp = (k) => ({ priv: b64d(k.priv), pub: b64d(k.pub) })

function serializeSecrets(identity, prekeys) {
  return {
    identity: { sign: kp(identity.sign), dh: kp(identity.dh) },
    prekeys: {
      signed: { id: prekeys.signed.id, key: kp(prekeys.signed.key), sig: b64e(prekeys.signed.sig) },
      oneTime: prekeys.oneTime.map((o) => ({ id: o.id, key: kp(o.key) })),
    },
  }
}

function deserializeSecrets(s) {
  return {
    identity: { sign: unkp(s.identity.sign), dh: unkp(s.identity.dh) },
    prekeys: {
      signed: { id: s.prekeys.signed.id, key: unkp(s.prekeys.signed.key), sig: b64d(s.prekeys.signed.sig) },
      oneTime: s.prekeys.oneTime.map((o) => ({ id: o.id, key: unkp(o.key) })),
    },
  }
}

export async function createBackup(passphrase, identity, prekeys) {
  const salt = randomBytes(16)
  const iv = randomBytes(12)
  const wrapKey = await pbkdf2(passphrase, salt, ITERS, 32)
  const payload = utf8e(JSON.stringify(serializeSecrets(identity, prekeys)))
  const ct = await aeadEncrypt(wrapKey, iv, payload, utf8e('Citadel_backup_v1'))
  return { v: 1, iters: ITERS, salt: b64e(salt), iv: b64e(iv), ct: b64e(ct) }
}

export async function openBackup(passphrase, blob) {
  const wrapKey = await pbkdf2(passphrase, b64d(blob.salt), blob.iters, 32)
  const pt = await aeadDecrypt(wrapKey, b64d(blob.iv), b64d(blob.ct), utf8e('Citadel_backup_v1'))
  return deserializeSecrets(JSON.parse(utf8d(pt)))
}
