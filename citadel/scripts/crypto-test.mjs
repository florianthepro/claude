// Headless verification of the E2E crypto — runs the exact modules the browser uses.
import * as x3dh from '../web/crypto/x3dh.js'
import * as session from '../web/crypto/session.js'
import * as backup from '../web/crypto/backup.js'
import { b64e } from '../web/crypto/primitives.js'

let fails = 0
const check = (name, cond) => {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}`)
  if (!cond) fails++
}
const expectThrow = async (name, fn) => {
  try {
    await fn()
    console.log(`FAIL  ${name} (expected throw)`)
    fails++
  } catch {
    console.log(`PASS  ${name}`)
  }
}
const eqBytes = (a, b) => b64e(a) === b64e(b)

// In-memory directory: fetching a bundle pops one one-time prekey (server behaviour).
function directory(pub) {
  const opks = [...pub.opks]
  return {
    fetch() {
      const opk = opks.length ? opks.shift() : null
      return { ikSign: pub.ikSign, ikDh: pub.ikDh, spkId: pub.spkId, spk: pub.spk, spkSig: pub.spkSig, opk }
    },
  }
}

const aliceId = await x3dh.generateIdentity()
const alicePre = await x3dh.generatePrekeys(aliceId)
const bobId = await x3dh.generateIdentity()
const bobPre = await x3dh.generatePrekeys(bobId)
const bobDir = directory(x3dh.publicBundle(bobId, bobPre))

// --- Scenario A: establish + bidirectional -------------------------------
{
  const a = await session.startOutbound(aliceId, 'bob', bobDir.fetch())
  const bob = new Map()
  const a2b = async (t) => {
    const r = await session.receive(bobId, bobPre, bob.get('alice'), 'alice', await session.encrypt(a, t))
    bob.set('alice', r.session)
    return r.plaintext
  }
  const b2a = async (t) => {
    const r = await session.receive(aliceId, alicePre, a, 'bob', await session.encrypt(bob.get('alice'), t))
    return r.plaintext
  }
  check('A: first message decrypts', (await a2b('hello bob')) === 'hello bob')
  check('A: second in same chain', (await a2b('still alice')) === 'still alice')
  check('A: reply decrypts', (await b2a('hi alice')) === 'hi alice')
  check('A: send after ratchet', (await a2b('ratcheted')) === 'ratcheted')
  check('A: reply after ratchet', (await b2a('ok')) === 'ok')
}

// --- Scenario B: out-of-order delivery in one chain ----------------------
{
  const a = await session.startOutbound(aliceId, 'bob', bobDir.fetch())
  const bob = new Map()
  const w0 = await session.encrypt(a, 'm0')
  const w1 = await session.encrypt(a, 'm1')
  const w2 = await session.encrypt(a, 'm2')
  const recv = async (w) => {
    const r = await session.receive(bobId, bobPre, bob.get('alice'), 'alice', w)
    bob.set('alice', r.session)
    return r.plaintext
  }
  check('B: newest first (skips stored)', (await recv(w2)) === 'm2')
  check('B: oldest next (from skipped)', (await recv(w0)) === 'm0')
  check('B: middle last (from skipped)', (await recv(w1)) === 'm1')
}

// --- Scenario C: skipped keys across a DH ratchet ------------------------
{
  const a = await session.startOutbound(aliceId, 'bob', bobDir.fetch())
  const bob = new Map()
  const recvB = async (w) => {
    const r = await session.receive(bobId, bobPre, bob.get('alice'), 'alice', w)
    bob.set('alice', r.session)
    return r.plaintext
  }
  await recvB(await session.encrypt(a, 'm0'))
  const reply = await session.encrypt(bob.get('alice'), 'r0')
  check('C: alice receives reply', (await session.receive(aliceId, alicePre, a, 'bob', reply)).plaintext === 'r0')
  const h1 = await session.encrypt(a, 'm1') // new sending chain after ratchet
  const h2 = await session.encrypt(a, 'm2')
  check('C: deliver m2 before m1', (await recvB(h2)) === 'm2')
  check('C: deliver m1 from skipped', (await recvB(h1)) === 'm1')
}

// --- Scenario D: tamper detection (AEAD integrity) -----------------------
{
  const a = await session.startOutbound(aliceId, 'bob', bobDir.fetch())
  const w = await session.encrypt(a, 'secret')
  const raw = atob(w.ct)
  const bytes = Uint8Array.from(raw, (c) => c.charCodeAt(0))
  bytes[0] ^= 0xff
  w.ct = btoa(String.fromCharCode(...bytes))
  await expectThrow('D: flipped ciphertext byte rejected', () =>
    session.receive(bobId, bobPre, undefined, 'alice', w),
  )
}

// --- Scenario E: identity binding (substituted signing key rejected) -----
{
  const a = await session.startOutbound(aliceId, 'bob', bobDir.fetch())
  const w = await session.encrypt(a, 'mitm?')
  const eve = await x3dh.generateIdentity()
  w.pre.ikSign = b64e(eve.sign.pub) // attacker swaps the claimed identity key
  await expectThrow('E: swapped identity key breaks AAD', () =>
    session.receive(bobId, bobPre, undefined, 'alice', w),
  )
}

// --- Scenario F: safety numbers ------------------------------------------
{
  const snAB = await session.safetyNumber(aliceId.sign.pub, bobId.sign.pub)
  const snBA = await session.safetyNumber(bobId.sign.pub, aliceId.sign.pub)
  check('F: safety number is order-independent', snAB === snBA)
  check('F: safety number is 60 digits', snAB.replace(/ /g, '').length === 60)
  const eve = await x3dh.generateIdentity()
  const snAE = await session.safetyNumber(aliceId.sign.pub, eve.sign.pub)
  check('F: different peer → different number (MITM visible)', snAB !== snAE)
}

// --- Scenario G: session serialization round-trip ------------------------
{
  let a = await session.startOutbound(aliceId, 'bob', bobDir.fetch())
  const bob = new Map()
  const recvB = async (w) => {
    const r = await session.receive(bobId, bobPre, bob.get('alice'), 'alice', w)
    bob.set('alice', r.session)
    return r.plaintext
  }
  await recvB(await session.encrypt(a, 'before save'))
  a = session.deserializeSession(JSON.parse(JSON.stringify(session.serializeSession(a))))
  check('G: conversation continues after reload', (await recvB(await session.encrypt(a, 'after save'))) === 'after save')
}

// --- Scenario H: encrypted key backup ------------------------------------
{
  const blob = await backup.createBackup('recovery passphrase 9', aliceId, alicePre)
  const restored = await backup.openBackup('recovery passphrase 9', blob)
  check('H: backup restores identity keys', eqBytes(restored.identity.sign.priv, aliceId.sign.priv))
  check('H: backup restores dh keys', eqBytes(restored.identity.dh.priv, aliceId.dh.priv))
  check('H: server-stored blob carries no plaintext key', !JSON.stringify(blob).includes(b64e(aliceId.sign.priv)))
  await expectThrow('H: wrong passphrase fails', () => backup.openBackup('wrong', blob))
}

// --- Scenario I: no one-time prekey available ----------------------------
{
  const pub = x3dh.publicBundle(bobId, bobPre)
  const noOpk = { ikSign: pub.ikSign, ikDh: pub.ikDh, spkId: pub.spkId, spk: pub.spk, spkSig: pub.spkSig, opk: null }
  const a = await session.startOutbound(aliceId, 'bob', noOpk)
  const r = await session.receive(bobId, bobPre, undefined, 'alice', await session.encrypt(a, 'no opk'))
  check('I: works without one-time prekey', r.plaintext === 'no opk' && r.consumedOpkId === null)
}

console.log(fails === 0 ? '\nALL PASS' : `\n${fails} FAILURE(S)`)
process.exit(fails === 0 ? 0 : 1)
