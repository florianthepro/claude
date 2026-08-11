// Full E2E proof through the real HTTP server: two clients register, exchange keys
// via the directory, and message through the relay. Asserts the server sees only
// ciphertext — never plaintext or private keys.
import * as OTPAuth from 'otpauth'
import * as x3dh from '../web/crypto/x3dh.js'
import * as session from '../web/crypto/session.js'
import * as backup from '../web/crypto/backup.js'
import { b64e, b64d } from '../web/crypto/primitives.js'

const BASE = process.env.BASE ?? 'http://127.0.0.1:8787'
const ORIGIN = process.env.PUBLIC_ORIGIN ?? BASE

let fails = 0
const check = (name, cond) => {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}`)
  if (!cond) fails++
}

const totp = (secret) =>
  new OTPAuth.TOTP({ algorithm: 'SHA1', digits: 6, period: 30, secret: OTPAuth.Secret.fromBase32(secret) }).generate()

class Client {
  constructor(name) {
    this.name = name
    this.jar = new Map()
    this.csrf = null
    this.sessions = new Map()
  }

  async call(method, path, body) {
    const headers = { Origin: ORIGIN }
    if (body !== undefined) headers['Content-Type'] = 'application/json'
    if (this.jar.size) headers['Cookie'] = [...this.jar].map(([k, v]) => `${k}=${v}`).join('; ')
    if (this.csrf && method !== 'GET') headers['X-CSRF-Token'] = this.csrf
    const res = await fetch(BASE + path, { method, headers, body: body ? JSON.stringify(body) : undefined })
    for (const c of res.headers.getSetCookie()) {
      const [pair] = c.split(';')
      const eq = pair.indexOf('=')
      if (eq > 0) this.jar.set(pair.slice(0, eq), pair.slice(eq + 1))
    }
    let data = null
    try {
      data = await res.json()
    } catch {
      /* empty */
    }
    return { status: res.status, data }
  }

  async signup(password) {
    const reg = await this.call('POST', '/api/register', { username: this.name, password })
    this.csrf = reg.data.csrf
    const conf = await this.call('POST', '/api/register/confirm', { token: totp(reg.data.secret) })
    this.csrf = conf.data.csrf
  }

  async generateKeys() {
    this.identity = await x3dh.generateIdentity()
    this.prekeys = await x3dh.generatePrekeys(this.identity, 8)
  }

  async publishKeys() {
    const b = x3dh.publicBundle(this.identity, this.prekeys)
    return this.call('POST', '/api/keys', {
      ikSign: b64e(b.ikSign),
      ikDh: b64e(b.ikDh),
      spkId: b.spkId,
      spk: b64e(b.spk),
      spkSig: b64e(b.spkSig),
      opks: b.opks.map((o) => ({ id: o.id, pub: b64e(o.pub) })),
    })
  }

  async fetchBundle(peer) {
    const r = await this.call('POST', '/api/keys/fetch', { username: peer })
    const d = r.data
    return {
      ikSign: b64d(d.ikSign),
      ikDh: b64d(d.ikDh),
      spkId: d.spkId,
      spk: b64d(d.spk),
      spkSig: b64d(d.spkSig),
      opk: d.opk ? { id: d.opk.id, pub: b64d(d.opk.pub) } : null,
    }
  }

  async send(peer, text) {
    let s = this.sessions.get(peer)
    if (!s) {
      s = await session.startOutbound(this.identity, peer, await this.fetchBundle(peer))
      this.sessions.set(peer, s)
    }
    const message = await session.encrypt(s, text)
    await this.call('POST', '/api/messages', { to: peer, message })
    return message
  }

  async poll() {
    const r = await this.call('GET', '/api/messages')
    const out = []
    for (const env of r.data) {
      const res = await session.receive(this.identity, this.prekeys, this.sessions.get(env.from), env.from, env.message)
      this.sessions.set(env.from, res.session)
      out.push({ from: env.from, text: res.plaintext, raw: env.message })
    }
    if (r.data.length) await this.call('POST', '/api/messages/ack', { ids: r.data.map((e) => e.id) })
    return out
  }
}

const stamp = process.hrtime.bigint().toString(16).slice(-6)
const alice = new Client('alice_' + stamp)
const bob = new Client('bob_' + stamp)
const pw = 'a very strong passphrase 7'

await alice.signup(pw)
await bob.signup(pw)
await alice.generateKeys()
await bob.generateKeys()
check('alice publishes bundle', (await alice.publishKeys()).data.opkCount === 8)
check('bob publishes bundle', (await bob.publishKeys()).data.opkCount === 8)

// Alice → Bob (establishes session via X3DH through the directory).
const wire1 = await alice.send(bob.name, 'hallo bob, streng geheim')
const bobIn = await bob.poll()
check('bob decrypts first message', bobIn[0]?.text === 'hallo bob, streng geheim')

// Server-blindness: the relayed envelope carries only ciphertext.
check('relayed envelope has no plaintext', !JSON.stringify(wire1).includes('streng geheim'))
check('envelope fields are opaque only', Object.keys(wire1).sort().join() === 'ct,h,pre,t')

// Bob → Alice (reply on the ratchet).
await bob.send(alice.name, 'hallo alice, verstanden')
const aliceIn = await alice.poll()
check('alice decrypts reply', aliceIn[0]?.text === 'hallo alice, verstanden')

// Several more, interleaved.
await alice.send(bob.name, 'm1')
await alice.send(bob.name, 'm2')
const bobIn2 = await bob.poll()
check('bob receives batch in order', bobIn2.map((m) => m.text).join(',') === 'm1,m2')

// One-time prekey was consumed by Alice's fetch.
const bobStatus = await bob.call('GET', '/api/keys/self/status')
check('one-time prekey consumed on server', bobStatus.data.opkCount === 7)

// Safety numbers match on both sides (out-of-band MITM check).
const snA = await session.safetyNumber(alice.identity.sign.pub, (await alice.fetchBundle(bob.name)).ikSign)
const snB = await session.safetyNumber(bob.identity.sign.pub, bob.sessions.get(alice.name).peerIkSign)
check('safety numbers match across parties', snA === snB)

// Encrypted key backup round-trips through the server.
const blob = await backup.createBackup('recovery-9', alice.identity, alice.prekeys)
await alice.call('PUT', '/api/backup', { blob })
const fetched = (await alice.call('GET', '/api/backup')).data.blob
const restored = await backup.openBackup('recovery-9', fetched)
check('identity restored from server-held backup', b64e(restored.identity.sign.priv) === b64e(alice.identity.sign.priv))
check('server backup blob is ciphertext only', !JSON.stringify(fetched).includes(b64e(alice.identity.sign.priv)))

console.log(fails === 0 ? '\nALL PASS' : `\n${fails} FAILURE(S)`)
process.exit(fails === 0 ? 0 : 1)
