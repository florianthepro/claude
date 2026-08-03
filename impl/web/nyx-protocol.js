// Das Protokoll im Browser.
//
// Portierung von impl/python/nyx auf WebCrypto. Die Byte-Ebene ist
// identisch: derselbe Adressaufbau, dieselben kanonischen JSON-Bytes,
// dieselbe Zerlegung, dieselben Tags. Ein Gespraech, das hier beginnt,
// laesst sich in der Python-Fassung fortsetzen und umgekehrt.

import {
  AuthError, base32, chacha20, concat, dec, enc, equal, hex, hkdf, hmac, open,
  random, seal, sha256, unbase32, unhex, wipe,
  ed25519Sign, ed25519Verify, x25519Keypair, x25519Shared,
} from './nyx-crypto.js';

export { AuthError, hex, unhex, enc, dec };

// PKCS8-Vorspann, damit ein 32-Byte-Saatgut aus der Python-Fassung hier
// unveraendert benutzbar ist. Identitaetsdateien sind dadurch zwischen den
// Implementierungen austauschbar.
const PKCS8_ED = unhex('302e020100300506032b657004220420');
const PKCS8_X = unhex('302e020100300506032b656e04220420');
const pkcs8 = (kind, seed) => concat(kind === 'ed' ? PKCS8_ED : PKCS8_X, seed);

// -- Kanonisches JSON ------------------------------------------------------

/** Byteweise gleich zu json.dumps(sort_keys, separators, ensure_ascii). */
export function canonical(value) {
  const text = JSON.stringify(sortDeep(value));
  const ascii = text.replace(/[\u0080-\uffff]/g,
    (ch) => '\\u' + ch.charCodeAt(0).toString(16).padStart(4, '0'));
  return enc.encode(ascii);
}

function sortDeep(value) {
  if (Array.isArray(value)) return value.map(sortDeep);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map(k => [k, sortDeep(value[k])]));
  }
  return value;
}

// -- Identitaet ------------------------------------------------------------

export async function addressFromKey(ikPub) {
  const body = (await sha256(enc.encode('nyx/v1/addr'), ikPub)).slice(0, 20);
  const sum = (await sha256(enc.encode('nyx/v1/addrsum'), body)).slice(0, 2);
  return base32(concat(body, sum));
}

export async function addressValid(addr) {
  try {
    const raw = unbase32(addr);
    if (raw.length !== 22) return false;
    const sum = (await sha256(enc.encode('nyx/v1/addrsum'), raw.slice(0, 20))).slice(0, 2);
    return equal(raw.slice(20), sum);
  } catch { return false; }
}

export class Identity {
  constructor(ikSeed, idkSeed) {
    this.ikSeed = ikSeed;
    this.idkSeed = idkSeed;
  }

  static async create() {
    const ident = new Identity(random(32), random(32));
    await ident.derive();
    return ident;
  }

  async derive() {
    const ed = await crypto.subtle.importKey('pkcs8', pkcs8('ed', this.ikSeed),
                                             { name: 'Ed25519' }, true, ['sign']);
    // Der oeffentliche Teil ergibt sich aus dem Saatgut; WebCrypto gibt ihn
    // ueber den JWK-Umweg heraus.
    const jwk = await crypto.subtle.exportKey('jwk', ed);
    this.ikPub = b64urlToBytes(jwk.x);

    const x = await crypto.subtle.importKey('pkcs8', pkcs8('x', this.idkSeed),
                                            { name: 'X25519' }, true, ['deriveBits']);
    const xjwk = await crypto.subtle.exportKey('jwk', x);
    this.idkPub = b64urlToBytes(xjwk.x);

    this.address = await addressFromKey(this.ikPub);
    return this;
  }

  get idkPriv() { return pkcs8('x', this.idkSeed); }

  /** Signiert Verzeichniseintraege. Nichts anderes. */
  sign(data) { return ed25519Sign(pkcs8('ed', this.ikSeed), data); }

  export() {
    return JSON.stringify({ v: 1, ik_seed: hex(this.ikSeed), idk_priv: hex(this.idkSeed) });
  }

  static async import(blob) {
    const d = JSON.parse(blob);
    return await new Identity(unhex(d.ik_seed), unhex(d.idk_priv)).derive();
  }
}

const b64urlToBytes = (s) =>
  Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')
    .padEnd(Math.ceil(s.length / 4) * 4, '=')), c => c.charCodeAt(0));

// -- Verzeichniseintraege --------------------------------------------------

const RECORD_LABEL = enc.encode('nyx/v1/record');

export async function makeRecord(identity, type, body) {
  const record = {
    type, addr: identity.address, ik_pub: hex(identity.ikPub),
    ts: Math.floor(Date.now() / 1000), body,
  };
  record.sig = hex(await identity.sign(concat(RECORD_LABEL, canonical(record))));
  return record;
}

export async function verifyRecord(record) {
  try {
    const { sig, recovery_sig, height, ...unsigned } = record;
    const ikPub = unhex(record.ik_pub);
    if (await addressFromKey(ikPub) !== record.addr) return false;
    return await ed25519Verify(ikPub, concat(RECORD_LABEL, canonical(unsigned)), unhex(sig));
  } catch { return false; }
}

// -- GF(256) und Zerlegung -------------------------------------------------

const EXP = new Uint8Array(512), LOG = new Uint8Array(256);
(() => {
  let x = 1;
  for (let i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 0x100) x ^= 0x11d; }
  for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
})();

const gmul = (a, b) => (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]];
const gdiv = (a, b) => a === 0 ? 0 : EXP[(LOG[a] - LOG[b] + 255) % 255];

function ginvert(matrix) {
  const n = matrix.length;
  const work = matrix.map((row, i) =>
    [...row, ...Array.from({ length: n }, (_, j) => (i === j ? 1 : 0))]);

  for (let col = 0; col < n; col++) {
    let pivot = -1;
    for (let r = col; r < n; r++) if (work[r][col]) { pivot = r; break; }
    if (pivot < 0) throw new Error('Matrix ist singulaer — Teile nicht unabhaengig');
    [work[col], work[pivot]] = [work[pivot], work[col]];

    const f = gdiv(1, work[col][col]);
    work[col] = work[col].map(v => gmul(v, f));
    for (let r = 0; r < n; r++) {
      if (r !== col && work[r][col]) {
        const g = work[r][col];
        work[r] = work[r].map((v, i) => v ^ gmul(g, work[col][i]));
      }
    }
  }
  return work.map(row => row.slice(n));
}

function generator(k, n) {
  const vand = Array.from({ length: n }, (_, i) => {
    const row = []; let val = 1;
    for (let j = 0; j < k; j++) { row.push(val); val = gmul(val, i); }
    return row;
  });
  const topInv = ginvert(vand.slice(0, k).map(r => [...r]));
  return vand.map(row => Array.from({ length: k }, (_, j) =>
    row.reduce((acc, v, t) => acc ^ gmul(v, topInv[t][j]), 0)));
}

// -- Alles oder nichts -----------------------------------------------------

const AONT_LABEL = enc.encode('nyx/v1/aont');
const ZERO_NONCE = new Uint8Array(12);

export async function aontTransform(data) {
  const key = random(32);
  const body = chacha20(key, 1, ZERO_NONCE, data);
  const digest = await sha256(AONT_LABEL, body);
  return concat(body, key.map((b, i) => b ^ digest[i]));
}

export async function aontInvert(pkg) {
  if (pkg.length < 32) throw new Error('AONT-Paket zu kurz');
  const body = pkg.slice(0, -32), tail = pkg.slice(-32);
  const digest = await sha256(AONT_LABEL, body);
  const key = tail.map((b, i) => b ^ digest[i]);
  return chacha20(key, 1, ZERO_NONCE, body);
}

export const DEFAULT_K = 10, DEFAULT_N = 20;

export async function disperse(data, k = DEFAULT_K, n = DEFAULT_N) {
  const pkg = await aontTransform(data);
  const matrix = generator(k, n);
  const padded = concat(pkg, new Uint8Array((k - pkg.length % k) % k));
  const chunk = padded.length / k;
  const columns = Array.from({ length: k }, (_, i) => padded.slice(i * chunk, (i + 1) * chunk));

  return Array.from({ length: n }, (_, i) => {
    let payload;
    if (i < k) {
      payload = columns[i];
    } else {
      payload = new Uint8Array(chunk);
      matrix[i].forEach((coeff, j) => {
        if (coeff) for (let b = 0; b < chunk; b++) payload[b] ^= gmul(coeff, columns[j][b]);
      });
    }
    return { index: i, k, n, length: pkg.length, data: payload };
  });
}

export async function reassemble(shares) {
  if (!shares.length) throw new Error('keine Teile vorhanden');
  const { k, n } = shares[0];
  const picked = new Map();
  for (const s of shares) if (!picked.has(s.index)) picked.set(s.index, s);
  if (picked.size < k) throw new Error(`unterhalb der Schwelle: ${picked.size} von ${k}`);

  const chosen = [...picked.keys()].sort((a, b) => a - b).slice(0, k).map(i => picked.get(i));
  const matrix = generator(k, n);
  const inverse = ginvert(chosen.map(s => [...matrix[s.index]]));

  const chunk = chosen[0].data.length;
  const out = new Uint8Array(k * chunk);
  for (let i = 0; i < k; i++) {
    const acc = new Uint8Array(chunk);
    inverse[i].forEach((coeff, j) => {
      if (coeff) for (let b = 0; b < chunk; b++) acc[b] ^= gmul(coeff, chosen[j].data[b]);
    });
    out.set(acc, i * chunk);
  }
  return await aontInvert(out.slice(0, chosen[0].length));
}

// -- Tags und Arbeitsnachweis ----------------------------------------------

export const tagSecret = (material) => hkdf(material, 'nyx/v1/drop-tag-secret');

export async function dropTag(skTag, counter, shareIndex) {
  const c = new Uint8Array(8), j = new Uint8Array(4);
  new DataView(c.buffer).setBigUint64(0, BigInt(counter));
  new DataView(j.buffer).setUint32(0, shareIndex);
  return hex(await hmac(skTag, c, j));
}

export const coverTag = () => hex(random(32));

export async function solvePow(tag, blob, bits) {
  if (bits <= 0) return 0;
  const label = enc.encode('nyx/v1/put-pow'), tagBytes = enc.encode(tag);
  for (let nonce = 0; ; nonce++) {
    const n = new Uint8Array(8);
    new DataView(n.buffer).setBigUint64(0, BigInt(nonce));
    const digest = await sha256(label, tagBytes, blob, n);
    let zeros = 0;
    for (const byte of digest) {
      if (byte) { zeros += 8 - (32 - Math.clz32(byte)); break; }
      zeros += 8;
    }
    if (zeros >= bits) return nonce;
  }
}

// -- Knoten ----------------------------------------------------------------

export class NodeClient {
  constructor(url) { this.url = url.replace(/\/$/, ''); }

  async call(path, body = {}, method = 'POST') {
    const res = await fetch(this.url + path, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: method === 'GET' ? undefined : JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  }

  async info() {
    const info = await this.call('/v1/info', {}, 'GET');
    this.powBits = info.pow_bits ?? 0;
    this.name = info.name;
    return info;
  }

  async put(tag, blob, ttl = null) {
    const nonce = await solvePow(tag, blob, this.powBits ?? 0);
    return this.call('/v1/store', { tag, blob: hex(blob), ttl, nonce });
  }

  async fetchTags(tags) {
    const { results } = await this.call('/v1/fetch', { tags });
    return results;
  }

  void(tags) { return this.call('/v1/void', { tags }); }

  submitRecord(record) { return this.call('/v1/dir/submit', { record }); }

  resolve(addr) { return this.call('/v1/dir/resolve', { addr }); }
}

/** Mehrere Knoten als ein Speichernetz. */
export class DropNetwork {
  constructor(nodes) { this.nodes = nodes; }

  async ready() { await Promise.all(this.nodes.map(n => n.info())); return this; }

  async store(skTag, counter, ciphertext, k = DEFAULT_K, n = DEFAULT_N, ttl = null) {
    const shares = await disperse(ciphertext, k, n);
    await Promise.all(shares.map(async (share, i) => {
      const node = this.nodes[i % this.nodes.length];
      await node.put(await dropTag(skTag, counter, share.index), encodeShare(share), ttl);
    }));
  }

  async fetch(skTag, counter, k = DEFAULT_K, n = DEFAULT_N, release = true) {
    const wanted = [];
    for (let j = 0; j < n; j++) wanted.push(await dropTag(skTag, counter, j));
    const queries = [...wanted, coverTag(), coverTag(), coverTag(), coverTag()]
      .sort(() => Math.random() - 0.5);

    const collected = [];
    for (const node of this.nodes) {
      if (collected.length >= k) break;
      const results = await node.fetchTags(queries);
      for (const value of Object.values(results)) {
        if (value) collected.push(decodeShare(unhex(value)));
      }
    }
    if (collected.length < k) return null;

    const message = await reassemble(collected);
    if (release) await Promise.all(this.nodes.map(node => node.void(wanted)));
    return message;
  }
}

function encodeShare(share) {
  const head = new Uint8Array(10), dv = new DataView(head.buffer);
  dv.setUint16(0, share.index); dv.setUint16(2, share.k);
  dv.setUint16(4, share.n); dv.setUint32(6, share.length);
  return concat(head, share.data);
}

function decodeShare(blob) {
  const dv = new DataView(blob.buffer, blob.byteOffset);
  return {
    index: dv.getUint16(0), k: dv.getUint16(2), n: dv.getUint16(4),
    length: dv.getUint32(6), data: blob.slice(10),
  };
}

// -- Handshake und Ratsche -------------------------------------------------

const BUNDLE_LABEL = enc.encode('nyx/v1/prekey-bundle');

export class PrekeyStore {
  constructor(identity) { this.identity = identity; this.opks = new Map(); this.nextId = 0; }

  async publish(count = 32) {
    const spk = await x25519Keypair();
    this.spk = spk;
    this.validUntil = Math.floor(Date.now() / 1000) + 7 * 24 * 3600;

    const opks = [];
    for (let i = 0; i < count; i++) {
      const pair = await x25519Keypair();
      this.opks.set(this.nextId, pair);
      opks.push({ id: this.nextId++, pub: hex(pair.pub) });
    }
    const sig = await this.identity.sign(
      concat(BUNDLE_LABEL, spk.pub, enc.encode(String(this.validUntil))));
    return { spk_pub: hex(spk.pub), spk_sig: hex(sig),
             valid_until: this.validUntil, opks };
  }

  /** Holt einen Einmal-Prekey und vernichtet ihn dabei. */
  take(id) {
    if (id === null || id === undefined) return null;
    const pair = this.opks.get(id);
    if (!pair) return null;
    this.opks.delete(id);
    return pair;
  }
}

export async function initiate(sender, peerIdkPub, bundle, peerIkPub) {
  const signedPart = concat(BUNDLE_LABEL, unhex(bundle.spk_pub),
                            enc.encode(String(bundle.valid_until)));
  if (!await ed25519Verify(peerIkPub, signedPart, unhex(bundle.spk_sig))) {
    throw new Error('Prekey-Buendel traegt keine gueltige Signatur');
  }
  if (bundle.valid_until < Math.floor(Date.now() / 1000)) {
    throw new Error('Prekey-Buendel ist abgelaufen');
  }

  const ek = await x25519Keypair();
  const opk = (bundle.opks && bundle.opks.length)
    ? bundle.opks[Math.floor(Math.random() * bundle.opks.length)] : null;

  const dh1 = await x25519Shared(sender.idkPriv, unhex(bundle.spk_pub));
  const dh2 = await x25519Shared(ek.priv, peerIdkPub);
  const dh3 = await x25519Shared(ek.priv, unhex(bundle.spk_pub));
  const dh4 = opk ? await x25519Shared(ek.priv, unhex(opk.pub)) : new Uint8Array(0);

  const root = await hkdf(concat(dh1, dh2, dh3, dh4), 'nyx/v1/x3dh-root', 64);
  [dh1, dh2, dh3, dh4].forEach(wipe);

  return {
    root,
    header: {
      ik_pub: hex(sender.ikPub), idk_pub: hex(sender.idkPub),
      ek_pub: hex(ek.pub), spk_pub: bundle.spk_pub,
      opk_id: opk ? opk.id : null,
    },
  };
}

export async function respond(receiver, store, header) {
  const opk = store.take(header.opk_id);
  if (header.opk_id !== null && !opk) throw new Error('Einmal-Prekey bereits verbraucht');

  const dh1 = await x25519Shared(store.spk.priv, unhex(header.idk_pub));
  const dh2 = await x25519Shared(receiver.idkPriv, unhex(header.ek_pub));
  const dh3 = await x25519Shared(store.spk.priv, unhex(header.ek_pub));
  const dh4 = opk ? await x25519Shared(opk.priv, unhex(header.ek_pub)) : new Uint8Array(0);

  const root = await hkdf(concat(dh1, dh2, dh3, dh4), 'nyx/v1/x3dh-root', 64);
  [dh1, dh2, dh3, dh4].forEach(wipe);
  return root;
}

const MAX_SKIPPED = 1000;
const MAX_SKIP_PER_MESSAGE = 256;

export class Ratchet {
  constructor() {
    this.root = new Uint8Array(32);
    this.sendChain = null; this.recvChain = null;
    this.dh = null; this.peerDh = null;
    this.nSend = 0; this.nRecv = 0; this.pn = 0;
    this.skipped = new Map();
  }

  static async asSender(root, peerSpk) {
    const r = new Ratchet();
    r.root = root.slice(0, 32);
    r.dh = await x25519Keypair();
    r.peerDh = peerSpk;
    const [newRoot, chain] = await kdfRoot(r.root, await x25519Shared(r.dh.priv, peerSpk));
    wipe(r.root);
    r.root = newRoot; r.sendChain = chain;
    return r;
  }

  static asReceiver(root, spk) {
    const r = new Ratchet();
    r.root = root.slice(0, 32);
    r.dh = spk;
    return r;
  }

  async encrypt(plaintext) {
    if (!this.sendChain) throw new Error('Sendekette nicht bereit');
    const [chain, mk] = await kdfChain(this.sendChain);
    wipe(this.sendChain); this.sendChain = chain;

    const header = { dh: hex(this.dh.pub), pn: this.pn, n: this.nSend++ };
    try {
      return { header, ct: seal(mk, nonceFor(header.n), plaintext, headerBytes(header)) };
    } finally {
      wipe(mk);   // der Sender behaelt den Schluessel nicht
    }
  }

  async decrypt(header, boxed) {
    const cached = this.skipped.get(`${header.dh}:${header.n}`);
    if (cached) {
      this.skipped.delete(`${header.dh}:${header.n}`);
      return this.openAndWipe(cached, header, boxed);
    }

    if (this.peerDh === null || hex(this.peerDh) !== header.dh) {
      await this.skipTo(header.pn);
      await this.dhRatchet(header);
    }
    await this.skipTo(header.n);

    const [chain, mk] = await kdfChain(this.recvChain);
    wipe(this.recvChain); this.recvChain = chain; this.nRecv++;
    return this.openAndWipe(mk, header, boxed);
  }

  /** Entschluesseln, pruefen, Schluessel ueberschreiben. In dieser Reihenfolge. */
  openAndWipe(mk, header, boxed) {
    try {
      return open(mk, nonceFor(header.n), boxed, headerBytes(header));
    } finally {
      wipe(mk);
    }
  }

  async skipTo(until) {
    if (!this.recvChain) return;
    if (until - this.nRecv > MAX_SKIP_PER_MESSAGE) {
      throw new Error('zu viele uebersprungene Nachrichten auf einmal');
    }
    while (this.nRecv < until) {
      const [chain, mk] = await kdfChain(this.recvChain);
      wipe(this.recvChain); this.recvChain = chain;
      if (this.skipped.size >= MAX_SKIPPED) {
        // Hartes Limit: aeltester Schluessel faellt weg, die zugehoerige
        // Nachricht ist damit dauerhaft unlesbar. So ist es gemeint.
        const oldest = this.skipped.keys().next().value;
        wipe(this.skipped.get(oldest)); this.skipped.delete(oldest);
      }
      this.skipped.set(`${hex(this.peerDh)}:${this.nRecv++}`, mk);
    }
  }

  async dhRatchet(header) {
    this.pn = this.nSend; this.nSend = 0; this.nRecv = 0;
    this.peerDh = unhex(header.dh);

    let [newRoot, recvChain] = await kdfRoot(this.root,
      await x25519Shared(this.dh.priv, this.peerDh));
    wipe(this.root); if (this.recvChain) wipe(this.recvChain);
    this.root = newRoot; this.recvChain = recvChain;

    this.dh = await x25519Keypair();
    let [nextRoot, sendChain] = await kdfRoot(this.root,
      await x25519Shared(this.dh.priv, this.peerDh));
    wipe(this.root); if (this.sendChain) wipe(this.sendChain);
    this.root = nextRoot; this.sendChain = sendChain;
  }

  destroy() {
    [this.root, this.sendChain, this.recvChain].forEach(wipe);
    for (const mk of this.skipped.values()) wipe(mk);
    this.skipped.clear();
    this.sendChain = this.recvChain = null;
  }
}

async function kdfRoot(root, dhOut) {
  const material = await hkdf(dhOut, 'nyx/v1/ratchet-root', 64, root);
  return [material.slice(0, 32), material.slice(32)];
}

async function kdfChain(chain) {
  return [await hkdf(chain, 'nyx/v1/ratchet-chain'),
          await hkdf(chain, 'nyx/v1/ratchet-message')];
}

/** Drahtform des Ratschenkopfs: 32 Byte Schluessel, zweimal 4 Byte Zaehler. */
export function headerToHex(header) {
  return hex(headerBytes(header));
}

export function headerFromHex(raw) {
  const bytes = unhex(raw);
  if (bytes.length !== 40) throw new Error('Nachrichtenkopf muss 40 Byte sein');
  const dv = new DataView(bytes.buffer, bytes.byteOffset);
  return { dh: hex(bytes.slice(0, 32)), pn: dv.getUint32(32), n: dv.getUint32(36) };
}

function headerBytes(header) {
  const out = new Uint8Array(40);
  out.set(unhex(header.dh), 0);
  new DataView(out.buffer).setUint32(32, header.pn);
  new DataView(out.buffer).setUint32(36, header.n);
  return out;
}

function nonceFor(n) {
  const out = new Uint8Array(12);
  new DataView(out.buffer).setBigUint64(4, BigInt(n));
  return out;
}

// -- Client ----------------------------------------------------------------

const CELL = 1024, LENGTH_PREFIX = 4, INBOX_SLOTS = 8, INBOX_EPOCH = 3600;

const inboxSecret = async (idkPub, epoch, slot) => {
  const e = new Uint8Array(8), s = new Uint8Array(4);
  new DataView(e.buffer).setBigUint64(0, BigInt(epoch));
  new DataView(s.buffer).setUint32(0, slot);
  return tagSecret(await hkdf(concat(idkPub, e, s), 'nyx/v1/inbox'));
};

export class Client {
  constructor(identity, drops, directoryNode) {
    this.identity = identity;
    this.drops = drops;
    this.dir = directoryNode;
    this.prekeys = new PrekeyStore(identity);
    this.sessions = new Map();
  }

  async announce() {
    await this.dir.submitRecord(await makeRecord(this.identity, 'BIND', {
      idk_pub: hex(this.identity.idkPub), recovery_pub: null,
    }));
    await this.dir.submitRecord(
      await makeRecord(this.identity, 'PREKEY', await this.prekeys.publish()));
  }

  async startSession(address) {
    if (this.sessions.has(address)) return this.sessions.get(address);

    const { identity, bundle } = await this.dir.resolve(address);
    if (!identity || !bundle) throw new Error('Adresse steht nicht im Verzeichnis');

    const peerIdk = unhex(identity.idk_pub), peerIk = unhex(identity.ik_pub);
    const { root, header } = await initiate(this.identity, peerIdk, bundle, peerIk);
    const session = {
      address, peerIdk, peerIk,
      ratchet: await Ratchet.asSender(root, unhex(bundle.spk_pub)),
      sendSecret: await tagSecret(await hkdf(root, 'nyx/v1/tags/initiator')),
      recvSecret: await tagSecret(await hkdf(root, 'nyx/v1/tags/responder')),
      sendCounter: 0, recvCounter: 0, initialHeader: header, sentInitial: false,
    };
    this.sessions.set(address, session);
    return session;
  }

  async acceptSession(header) {
    const address = await addressFromKey(unhex(header.ik_pub));
    const root = await respond(this.identity, this.prekeys, header);
    const session = {
      address, peerIdk: unhex(header.idk_pub), peerIk: unhex(header.ik_pub),
      ratchet: Ratchet.asReceiver(root, this.prekeys.spk),
      sendSecret: await tagSecret(await hkdf(root, 'nyx/v1/tags/responder')),
      recvSecret: await tagSecret(await hkdf(root, 'nyx/v1/tags/initiator')),
      sendCounter: 0, recvCounter: 0, initialHeader: null, sentInitial: true,
    };
    this.sessions.set(address, session);
    return session;
  }

  async send(address, envelope, ttl = null) {
    const session = this.sessions.get(address) || await this.startSession(address);
    const { header, ct } = await session.ratchet.encrypt(
      enc.encode(JSON.stringify(envelope)));

    const packet = { hdr: headerToHex(header), ct: hex(ct) };
    const first = !session.sentInitial && session.initialHeader;
    if (first) packet.init = session.initialHeader;
    const blob = enc.encode(JSON.stringify(packet));

    let secret, start;
    if (first) {
      const epoch = Math.floor(Date.now() / 1000 / INBOX_EPOCH);
      secret = await inboxSecret(session.peerIdk, epoch,
                                 Math.floor(Math.random() * INBOX_SLOTS));
      start = 0;
      session.sentInitial = true;
    } else {
      secret = session.sendSecret;
      start = session.sendCounter;
    }

    const cells = await this.storeFrames(secret, start, blob, ttl);
    if (!first) session.sendCounter += cells;
    return { cells, erstkontakt: !!first };
  }

  // Jede Ablage ist genau CELL Byte gross. Kurznachricht, Brief, Dateiblock
  // und Anrufsignal sehen im Speichernetz deshalb gleich aus.
  async storeFrames(secret, start, blob, ttl) {
    const prefix = new Uint8Array(LENGTH_PREFIX);
    new DataView(prefix.buffer).setUint32(0, blob.length);
    const framed = concat(prefix, blob);
    const padded = concat(framed, new Uint8Array((CELL - framed.length % CELL) % CELL));

    const cells = padded.length / CELL;
    for (let i = 0; i < cells; i++) {
      await this.drops.store(secret, start + i, padded.slice(i * CELL, (i + 1) * CELL),
                             DEFAULT_K, DEFAULT_N, ttl);
    }
    return cells;
  }

  async fetchFrames(secret, start) {
    const first = await this.drops.fetch(secret, start);
    if (!first) return null;
    const total = new DataView(first.buffer, first.byteOffset).getUint32(0);

    let buf = first, cells = 1;
    while (buf.length - LENGTH_PREFIX < total) {
      const next = await this.drops.fetch(secret, start + cells);
      if (!next) return null;
      buf = concat(buf, next); cells++;
    }
    return { blob: buf.slice(LENGTH_PREFIX, LENGTH_PREFIX + total), cells };
  }

  async poll() {
    const out = [];
    const epoch = Math.floor(Date.now() / 1000 / INBOX_EPOCH);
    for (const e of [epoch, epoch - 1]) {
      for (let slot = 0; slot < INBOX_SLOTS; slot++) {
        const got = await this.fetchFrames(await inboxSecret(this.identity.idkPub, e, slot), 0);
        if (!got) continue;
        try {
          const packet = JSON.parse(dec.decode(got.blob));
          const session = await this.acceptSession(packet.init);
          out.push([session.address, await openPacket(session, packet)]);
        } catch { /* nicht fuer uns oder beschaedigt */ }
      }
    }

    for (const session of this.sessions.values()) {
      for (;;) {
        const got = await this.fetchFrames(session.recvSecret, session.recvCounter);
        if (!got) break;
        session.recvCounter += got.cells;
        try {
          out.push([session.address,
                    await openPacket(session, JSON.parse(dec.decode(got.blob)))]);
        } catch { /* beschaedigt */ }
      }
    }
    return out;
  }

  close(address) {
    const session = this.sessions.get(address);
    if (session) { session.ratchet.destroy(); this.sessions.delete(address); }
  }
}

async function openPacket(session, packet) {
  const plain = await session.ratchet.decrypt(headerFromHex(packet.hdr), unhex(packet.ct));
  return JSON.parse(dec.decode(plain));
}

/** Vergleichswert fuer den Abgleich ausserhalb des Netzes. */
export async function safetyNumber(aIk, aIdk, bIk, bIdk) {
  const first = concat(aIk, aIdk), second = concat(bIk, bIdk);
  const [x, y] = hex(first) < hex(second) ? [first, second] : [second, first];
  const digest = await sha256(enc.encode('nyx/v1/safety'), x, y);
  let value = 0n;
  for (const byte of digest.slice(0, 15)) value = (value << 8n) | BigInt(byte);
  const digits = value.toString().padStart(36, '0').slice(0, 36);
  return digits.match(/.{6}/g).join(' ');
}
