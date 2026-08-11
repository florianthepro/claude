import { el, mount } from './dom.js'
import { api } from './api.js'
import { kvGet, kvSet } from './store.js'
import * as x3dh from './crypto/x3dh.js'
import * as session from './crypto/session.js'
import * as backup from './crypto/backup.js'
import { b64e, b64d } from './crypto/primitives.js'

const OPK_COUNT = 64
const POLL_MS = 3000

export function openMessenger(root, account, onExit) {
  const ctx = {
    root,
    account,
    onExit,
    identity: null,
    prekeys: null,
    sessions: new Map(),
    convos: new Map(),
    active: null,
    timer: null,
    polling: false,
  }
  boot(ctx)
}

// --- persistence ---------------------------------------------------------
const saveSecrets = (c) => Promise.all([kvSet(c.account, 'identity', c.identity), kvSet(c.account, 'prekeys', c.prekeys)])
const saveSessions = (c) => kvSet(c.account, 'sessions', Object.fromEntries(c.sessions))
const saveConvos = (c) => kvSet(c.account, 'convos', Object.fromEntries(c.convos))

async function loadLocal(c) {
  c.identity = await kvGet(c.account, 'identity')
  if (!c.identity) return false
  c.prekeys = await kvGet(c.account, 'prekeys')
  const s = (await kvGet(c.account, 'sessions')) || {}
  for (const [k, v] of Object.entries(s)) c.sessions.set(k, v)
  const cv = (await kvGet(c.account, 'convos')) || {}
  for (const [k, v] of Object.entries(cv)) c.convos.set(k, v)
  return true
}

// --- boot / key lifecycle ------------------------------------------------
async function boot(c) {
  renderLoading(c, 'Schlüssel werden geladen …')
  if (await loadLocal(c)) {
    await ensureServerKeys(c)
    renderApp(c)
    startPolling(c)
    return
  }
  const blob = (await api('GET', '/api/backup')).data?.blob
  if (blob) renderUnlock(c, blob)
  else renderSetup(c)
}

async function publishKeys(c) {
  const b = x3dh.publicBundle(c.identity, c.prekeys)
  await api('POST', '/api/keys', {
    ikSign: b64e(b.ikSign),
    ikDh: b64e(b.ikDh),
    spkId: b.spkId,
    spk: b64e(b.spk),
    spkSig: b64e(b.spkSig),
    opks: b.opks.map((o) => ({ id: o.id, pub: b64e(o.pub) })),
  })
}

async function ensureServerKeys(c) {
  const st = (await api('GET', '/api/keys/self/status')).data
  if (!st?.hasBundle) await publishKeys(c)
}

async function provision(c, passphrase) {
  c.identity = await x3dh.generateIdentity()
  c.prekeys = await x3dh.generatePrekeys(c.identity, OPK_COUNT)
  await saveSecrets(c)
  await publishKeys(c)
  const blob = await backup.createBackup(passphrase, c.identity, c.prekeys)
  await api('PUT', '/api/backup', { blob })
}

async function restore(c, passphrase, blob) {
  const secrets = await backup.openBackup(passphrase, blob)
  c.identity = secrets.identity
  c.prekeys = secrets.prekeys
  await saveSecrets(c)
  await ensureServerKeys(c)
}

// --- messaging -----------------------------------------------------------
async function ensureSession(c, peer) {
  if (c.sessions.has(peer)) return c.sessions.get(peer)
  const r = await api('POST', '/api/keys/fetch', { username: peer })
  if (!r.ok) throw new Error(r.data?.error || 'no_user')
  const d = r.data
  const bundle = {
    ikSign: b64d(d.ikSign),
    ikDh: b64d(d.ikDh),
    spkId: d.spkId,
    spk: b64d(d.spk),
    spkSig: b64d(d.spkSig),
    opk: d.opk ? { id: d.opk.id, pub: b64d(d.opk.pub) } : null,
  }
  const s = await session.startOutbound(c.identity, peer, bundle)
  c.sessions.set(peer, s)
  await saveSessions(c)
  return s
}

function pushConvo(c, peer, entry) {
  if (!c.convos.has(peer)) c.convos.set(peer, [])
  c.convos.get(peer).push(entry)
}

async function send(c, peer, text) {
  const s = await ensureSession(c, peer)
  const message = await session.encrypt(s, text)
  const r = await api('POST', '/api/messages', { to: peer, message })
  if (!r.ok) throw new Error(r.data?.error || 'send_failed')
  pushConvo(c, peer, { dir: 'out', text, ts: Date.now() })
  await Promise.all([saveSessions(c), saveConvos(c)])
}

async function poll(c) {
  if (c.polling) return
  c.polling = true
  try {
    const r = await api('GET', '/api/messages')
    if (!r.ok || !Array.isArray(r.data) || !r.data.length) return
    for (const env of r.data) {
      try {
        const res = await session.receive(c.identity, c.prekeys, c.sessions.get(env.from), env.from, env.message)
        c.sessions.set(env.from, res.session)
        if (res.consumedOpkId) {
          c.prekeys.oneTime = c.prekeys.oneTime.filter((o) => o.id !== res.consumedOpkId)
          await saveSecrets(c)
        }
        pushConvo(c, env.from, { dir: 'in', text: res.plaintext, ts: env.ts })
      } catch {
        /* undecryptable envelope — skip */
      }
    }
    await api('POST', '/api/messages/ack', { ids: r.data.map((e) => e.id) })
    await Promise.all([saveSessions(c), saveConvos(c)])
    renderList(c)
    if (c.active) renderThread(c)
  } finally {
    c.polling = false
  }
}

function startPolling(c) {
  poll(c)
  c.timer = setInterval(() => poll(c), POLL_MS)
}
function stopPolling(c) {
  if (c.timer) clearInterval(c.timer)
  c.timer = null
}

// --- views ---------------------------------------------------------------
function card(c, ...kids) {
  mount(c.root, el('div', { class: 'card' }, brand(), ...kids))
}
function brand() {
  return el('div', { class: 'brand' }, el('span', { class: 'dot' }), el('span', { text: 'Messenger' }))
}

function renderLoading(c, msg) {
  card(c, el('div', { class: 'hint', text: msg }))
}

function renderSetup(c) {
  const p1 = el('input', { type: 'password', autocomplete: 'new-password', placeholder: 'Recovery-Passphrase' })
  const p2 = el('input', { type: 'password', autocomplete: 'new-password', placeholder: 'wiederholen' })
  const msg = el('div', { class: 'msg' })
  const btn = el('button', { class: 'primary', text: 'Einrichten' })
  btn.addEventListener('click', async () => {
    if (p1.value.length < 10) return fail(msg, 'Mindestens 10 Zeichen.')
    if (p1.value !== p2.value) return fail(msg, 'Passphrasen stimmen nicht überein.')
    btn.disabled = true
    try {
      renderLoading(c, 'Schlüssel werden erzeugt …')
      await provision(c, p1.value)
      renderApp(c)
      startPolling(c)
    } catch {
      renderSetup(c)
    }
  })
  card(
    c,
    el('div', { class: 'hint', text: 'Einmalige Einrichtung. Die Passphrase schützt dein Schlüssel-Backup — der Server sieht sie nie.' }),
    el('form', { onSubmit: (e) => e.preventDefault() }, p1, p2, btn, msg),
    exitLink(c),
  )
}

function renderUnlock(c, blob) {
  const p = el('input', { type: 'password', autocomplete: 'current-password', placeholder: 'Recovery-Passphrase' })
  const msg = el('div', { class: 'msg' })
  const btn = el('button', { class: 'primary', text: 'Entsperren' })
  btn.addEventListener('click', async () => {
    btn.disabled = true
    try {
      await restore(c, p.value, blob)
      renderApp(c)
      startPolling(c)
    } catch {
      btn.disabled = false
      fail(msg, 'Passphrase falsch.')
    }
  })
  card(
    c,
    el('div', { class: 'hint', text: 'Konto auf diesem Gerät entsperren. Deine Schlüssel werden lokal aus dem verschlüsselten Backup wiederhergestellt.' }),
    el('form', { onSubmit: (e) => e.preventDefault() }, p, btn, msg),
    exitLink(c),
  )
}

function exitLink(c) {
  return el('button', {
    class: 'linkbtn',
    text: '← zurück',
    onClick: () => {
      stopPolling(c)
      c.onExit()
    },
  })
}

function fail(node, text) {
  node.className = 'msg err'
  node.textContent = text
}

function renderApp(c) {
  const wrap = el('div', { class: 'msg-app' })

  const newInput = el('input', { placeholder: 'Benutzername', autocapitalize: 'none' })
  const newBtn = el('button', { text: 'Chat', class: 'ghost' })
  const newMsg = el('div', { class: 'msg' })
  newBtn.addEventListener('click', async () => {
    const peer = newInput.value.trim().toLowerCase()
    if (!peer || peer === c.account) return
    newBtn.disabled = true
    try {
      await ensureSession(c, peer)
      if (!c.convos.has(peer)) c.convos.set(peer, [])
      newInput.value = ''
      select(c, peer)
    } catch {
      fail(newMsg, 'Kein Konto mit Schlüsseln gefunden.')
    } finally {
      newBtn.disabled = false
    }
  })

  const aside = el(
    'aside',
    { class: 'convos' },
    el(
      'div',
      { class: 'convos-top' },
      el('button', { class: 'linkbtn', text: '←', onClick: () => (stopPolling(c), c.onExit()) }),
      el('span', { class: 'me', text: c.account }),
    ),
    el('div', { class: 'new-chat' }, newInput, newBtn),
    newMsg,
    el('ul', { class: 'convo-list', id: 'convo-list' }),
  )

  const chat = el('section', { class: 'chat', id: 'chat' }, el('div', { class: 'chat-empty', text: 'Konversation wählen' }))

  wrap.append(aside, chat)
  mount(c.root, wrap)
  renderList(c)
  if (c.active) renderThread(c)
}

function select(c, peer) {
  c.active = peer
  renderList(c)
  renderThread(c)
}

function renderList(c) {
  const list = document.getElementById('convo-list')
  if (!list) return
  const peers = [...new Set([...c.convos.keys(), ...c.sessions.keys()])].sort()
  list.replaceChildren(
    ...peers.map((p) => {
      const item = el('li', {
        class: 'convo-item' + (p === c.active ? ' active' : ''),
        onClick: () => select(c, p),
      })
      const msgs = c.convos.get(p) || []
      const last = msgs[msgs.length - 1]
      item.append(el('div', { class: 'convo-name', text: p }), el('div', { class: 'convo-last', text: last ? last.text.slice(0, 40) : 'neu' }))
      return item
    }),
  )
}

async function renderThread(c) {
  const chat = document.getElementById('chat')
  if (!chat || !c.active) return
  const peer = c.active

  const snArea = el('div', { class: 'sn', id: 'sn' })
  const snBtn = el('button', {
    class: 'ghost',
    text: 'Safety-Number',
    onClick: async () => {
      const s = c.sessions.get(peer)
      if (!s) return
      const num = await session.safetyNumber(c.identity.sign.pub, s.peerIkSign)
      snArea.replaceChildren(el('div', { class: 'sn-num', text: num }), el('div', { class: 'hint', text: 'Vergleicht ihr beide diese Zahl out-of-band, ist ein Mithörer ausgeschlossen.' }))
    },
  })

  const msgsBox = el('div', { class: 'messages', id: 'messages' })
  const input = el('input', { placeholder: 'Nachricht', autocomplete: 'off' })
  const sendBtn = el('button', { class: 'primary', text: 'Senden' })
  const composer = el('form', { class: 'composer', onSubmit: (e) => e.preventDefault() }, input, sendBtn)
  const doSend = async () => {
    const text = input.value
    if (!text.trim()) return
    input.value = ''
    sendBtn.disabled = true
    try {
      await send(c, peer, text)
      renderThread(c)
      renderList(c)
    } catch {
      pushConvo(c, peer, { dir: 'err', text: 'Senden fehlgeschlagen', ts: Date.now() })
      renderThread(c)
    } finally {
      sendBtn.disabled = false
    }
  }
  sendBtn.addEventListener('click', doSend)

  chat.replaceChildren(
    el('header', { class: 'chat-head' }, el('span', { class: 'peer', text: peer }), snBtn),
    snArea,
    msgsBox,
    composer,
  )
  for (const m of c.convos.get(peer) || []) {
    msgsBox.append(el('div', { class: 'bubble ' + m.dir }, el('span', { text: m.text })))
  }
  msgsBox.scrollTop = msgsBox.scrollHeight
}
