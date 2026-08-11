// IndexedDB key/value store, scoped per account. Values are stored via structured
// clone, so identity keys, prekeys and live session state (typed arrays + Maps)
// round-trip without manual serialization. Private keys never leave the browser.
const DB = 'citadel'
const STORE = 'kv'
let dbp = null

function open() {
  if (dbp) return dbp
  dbp = new Promise((res, rej) => {
    const r = indexedDB.open(DB, 1)
    r.onupgradeneeded = () => r.result.createObjectStore(STORE, { keyPath: 'k' })
    r.onsuccess = () => res(r.result)
    r.onerror = () => rej(r.error)
  })
  return dbp
}

async function store(mode) {
  const db = await open()
  return db.transaction(STORE, mode).objectStore(STORE)
}

export async function kvGet(account, name) {
  const s = await store('readonly')
  return new Promise((res, rej) => {
    const r = s.get(`${account}:${name}`)
    r.onsuccess = () => res(r.result ? r.result.v : null)
    r.onerror = () => rej(r.error)
  })
}

export async function kvSet(account, name, v) {
  const s = await store('readwrite')
  return new Promise((res, rej) => {
    const r = s.put({ k: `${account}:${name}`, v })
    r.onsuccess = () => res()
    r.onerror = () => rej(r.error)
  })
}
