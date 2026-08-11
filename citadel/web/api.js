// Shared fetch wrapper. Holds the per-session CSRF token for mutating requests.
let csrf = null

export function setCsrf(v) {
  csrf = v
}

export async function api(method, path, body) {
  const headers = {}
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (csrf && method !== 'GET') headers['X-CSRF-Token'] = csrf
  const res = await fetch(path, {
    method,
    headers,
    credentials: 'same-origin',
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })
  let data = null
  try {
    data = await res.json()
  } catch {
    /* empty */
  }
  return { ok: res.ok, status: res.status, data }
}
