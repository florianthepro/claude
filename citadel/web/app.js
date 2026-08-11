import { el, mount } from './dom.js'
import { api, setCsrf } from './api.js'
import { openMessenger } from './messenger.js'

const root = document.getElementById('root')
const show = (node) => mount(root, node)

function field(labelText, attrs) {
  const input = el('input', attrs)
  return { input, node: el('label', { text: labelText }, input) }
}

function brand() {
  return el('div', { class: 'brand' }, el('span', { class: 'dot' }), el('span', { text: 'Citadel' }))
}

function renderAuth(tab = 'login') {
  const card = el('div', { class: 'card' })
  const tabs = el(
    'div',
    { class: 'tabs' },
    el('button', { text: 'Anmelden', class: tab === 'login' ? 'active' : '', onClick: () => renderAuth('login') }),
    el('button', { text: 'Registrieren', class: tab === 'register' ? 'active' : '', onClick: () => renderAuth('register') }),
  )
  card.append(brand(), tabs, tab === 'login' ? loginForm() : registerForm())
  show(card)
}

function loginForm() {
  const u = field('Benutzername', { autocomplete: 'username', autocapitalize: 'none', required: 'required' })
  const p = field('Passwort', { type: 'password', autocomplete: 'current-password', required: 'required' })
  const o = field('TOTP / Backup-Code', { inputmode: 'numeric', autocomplete: 'one-time-code', required: 'required' })
  const msg = el('div', { class: 'msg' })
  const btn = el('button', { class: 'primary', type: 'submit', text: 'Anmelden' })

  return el(
    'form',
    {
      onSubmit: async (e) => {
        e.preventDefault()
        btn.disabled = true
        msg.className = 'msg'
        msg.textContent = ''
        const r = await api('POST', '/api/login', { username: u.input.value, password: p.input.value, otp: o.input.value })
        if (r.ok && r.data?.ok) {
          setCsrf(r.data.csrf)
          return boot()
        }
        btn.disabled = false
        msg.className = 'msg err'
        msg.textContent = 'Anmeldung fehlgeschlagen.'
      },
    },
    u.node,
    p.node,
    o.node,
    btn,
    msg,
  )
}

function registerForm() {
  const u = field('Benutzername', { autocomplete: 'username', autocapitalize: 'none', required: 'required' })
  const p = field('Passwort (min. 12 Zeichen)', { type: 'password', autocomplete: 'new-password', required: 'required' })
  const msg = el('div', { class: 'msg' })
  const btn = el('button', { class: 'primary', type: 'submit', text: 'Weiter' })

  return el(
    'form',
    {
      onSubmit: async (e) => {
        e.preventDefault()
        btn.disabled = true
        msg.className = 'msg'
        msg.textContent = ''
        const r = await api('POST', '/api/register', { username: u.input.value, password: p.input.value })
        if (r.ok) {
          setCsrf(r.data.csrf)
          return renderEnroll(r.data)
        }
        btn.disabled = false
        msg.className = 'msg err'
        if (r.data?.error === 'username_taken') msg.textContent = 'Benutzername vergeben.'
        else if (r.data?.error === 'username_invalid') msg.textContent = 'Benutzername ungültig.'
        else if (r.data?.error === 'password_weak') msg.textContent = (r.data.issues || []).join(' ')
        else if (r.data?.error === 'registration_disabled') msg.textContent = 'Registrierung deaktiviert.'
        else msg.textContent = 'Fehler.'
      },
    },
    u.node,
    p.node,
    btn,
    msg,
  )
}

function renderEnroll(data) {
  const card = el('div', { class: 'card' })
  const img = el('img', { class: 'qr', alt: 'TOTP QR' })
  img.src = data.qr
  const o = field('6-stelliger Code', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', required: 'required' })
  const msg = el('div', { class: 'msg' })
  const btn = el('button', { class: 'primary', type: 'submit', text: 'Bestätigen' })

  const form = el(
    'form',
    {
      onSubmit: async (e) => {
        e.preventDefault()
        btn.disabled = true
        msg.className = 'msg'
        msg.textContent = ''
        const r = await api('POST', '/api/register/confirm', { token: o.input.value })
        if (r.ok && r.data?.ok) {
          setCsrf(r.data.csrf)
          return renderBackup(r.data.backupCodes)
        }
        btn.disabled = false
        msg.className = 'msg err'
        msg.textContent = 'Code ungültig.'
      },
    },
    img,
    el('div', { class: 'secret', text: data.secret }),
    o.node,
    btn,
    msg,
  )
  card.append(brand(), el('div', { class: 'hint', text: 'Scanne den Code in einer Authenticator-App.' }), form)
  show(card)
}

function renderBackup(codes) {
  const card = el('div', { class: 'card' })
  const list = el('ul', { class: 'codes' })
  for (const code of codes) list.append(el('li', { text: code }))
  card.append(
    brand(),
    el('div', { class: 'hint', text: 'Backup-Codes — jetzt sichern, werden nur einmal gezeigt.' }),
    list,
    el('button', { class: 'primary', text: 'Weiter', onClick: () => boot() }),
  )
  show(card)
}

const MODULES = [
  { key: 'messenger', name: 'Messenger', state: 'E2E · X3DH + Double Ratchet', locked: false },
  { name: 'Mail', state: 'in Vorbereitung', locked: true },
  { name: 'Dateien', state: 'in Vorbereitung', locked: true },
  { name: 'Kalender', state: 'in Vorbereitung', locked: true },
]

function renderShell(user) {
  const wrap = el('div', { class: 'shell' })
  const logout = el('button', {
    text: 'Abmelden',
    onClick: async () => {
      await api('POST', '/api/logout')
      setCsrf(null)
      renderAuth('login')
    },
  })
  const top = el(
    'div',
    { class: 'topbar' },
    brand(),
    el('div', { class: 'topbar-right' }, el('span', { class: 'user', text: user }), logout),
  )
  const grid = el('div', { class: 'grid' })
  for (const m of MODULES) {
    const tile = el(
      'div',
      { class: m.locked ? 'tile locked' : 'tile', role: m.locked ? null : 'button', tabindex: m.locked ? null : '0' },
      el('span', { class: 'badge', text: m.locked ? 'gesperrt' : 'aktiv' }),
      el('div', {}, el('div', { class: 'name', text: m.name }), el('div', { class: 'state', text: m.state })),
    )
    if (m.key === 'messenger') {
      tile.addEventListener('click', () => openMessenger(root, user, () => renderShell(user)))
    }
    grid.append(tile)
  }
  wrap.append(top, grid)
  show(wrap)
}

async function boot() {
  const r = await api('GET', '/api/me')
  if (r.ok && r.data?.authenticated) {
    setCsrf(r.data.csrf)
    renderShell(r.data.username || 'user')
  } else {
    renderAuth('login')
  }
}

boot()
