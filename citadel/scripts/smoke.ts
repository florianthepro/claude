import * as OTPAuth from 'otpauth'

const BASE = process.env.BASE ?? 'http://127.0.0.1:8787'
const ORIGIN = process.env.PUBLIC_ORIGIN ?? BASE

const jar = new Map<string, string>()
let csrf: string | null = null

function cookieHeader(): string {
  return [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ')
}

async function call(method: string, path: string, body?: unknown) {
  const headers: Record<string, string> = { Origin: ORIGIN }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (jar.size) headers['Cookie'] = cookieHeader()
  if (csrf && method !== 'GET') headers['X-CSRF-Token'] = csrf
  const res = await fetch(BASE + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  })
  for (const c of res.headers.getSetCookie()) {
    const [pair] = c.split(';')
    const eq = pair.indexOf('=')
    if (eq > 0) jar.set(pair.slice(0, eq), pair.slice(eq + 1))
  }
  let data: any = null
  try {
    data = await res.json()
  } catch {
    /* empty */
  }
  return { status: res.status, data }
}

function totp(secret: string): string {
  return new OTPAuth.TOTP({
    algorithm: 'SHA1',
    digits: 6,
    period: 30,
    secret: OTPAuth.Secret.fromBase32(secret),
  }).generate()
}

let failures = 0
function check(name: string, cond: boolean) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}`)
  if (!cond) failures++
}

const user = 'smoke_' + Buffer.from(process.hrtime.bigint().toString()).toString('hex').slice(0, 8)
const pass = 'correct horse battery staple 42'

const reg = await call('POST', '/api/register', { username: user, password: pass })
check('register 200', reg.status === 200)
check('register returns secret + csrf', !!reg.data?.secret && !!reg.data?.csrf)
csrf = reg.data?.csrf ?? null
const secret = reg.data?.secret as string

const confirm = await call('POST', '/api/register/confirm', { token: totp(secret) })
check('confirm 200', confirm.status === 200)
check('confirm returns 10 backup codes', Array.isArray(confirm.data?.backupCodes) && confirm.data.backupCodes.length === 10)
csrf = confirm.data?.csrf ?? csrf
const backup = confirm.data?.backupCodes?.[0] as string

const me1 = await call('GET', '/api/me')
check('me authenticated after enroll', me1.status === 200 && me1.data?.authenticated === true && me1.data?.username === user)

const badCsrf = csrf
csrf = 'wrong-token'
const csrfTest = await call('POST', '/api/logout')
check('logout blocked with bad csrf', csrfTest.status === 403)
csrf = badCsrf

const logout = await call('POST', '/api/logout')
check('logout 200', logout.status === 200)

const me2 = await call('GET', '/api/me')
check('me 401 after logout', me2.status === 401)

const badLogin = await call('POST', '/api/login', { username: user, password: 'wrong', otp: totp(secret) })
check('login rejects wrong password', badLogin.status === 401)

csrf = null
const login = await call('POST', '/api/login', { username: user, password: pass, otp: totp(secret) })
check('login 200 with totp', login.status === 200 && login.data?.ok === true)
csrf = login.data?.csrf ?? null

const me3 = await call('GET', '/api/me')
check('me authenticated after login', me3.status === 200 && me3.data?.authenticated === true)

await call('POST', '/api/logout')
csrf = null
const backupLogin = await call('POST', '/api/login', { username: user, password: pass, otp: backup })
check('login 200 with backup code', backupLogin.status === 200 && backupLogin.data?.ok === true)

console.log(failures === 0 ? '\nALL PASS' : `\n${failures} FAILURE(S)`)
process.exit(failures === 0 ? 0 : 1)
