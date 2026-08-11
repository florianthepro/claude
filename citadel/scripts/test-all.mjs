// One-command verification: boots a throwaway server and runs every suite.
import { spawn } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { existsSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const PORT = Number(process.env.PORT ?? 8890)
const BASE = `http://127.0.0.1:${PORT}`
const DB_PATH = join(tmpdir(), `citadel-test-${Date.now()}.sqlite`)
const CHROME = process.env.CHROME_PATH ?? '/opt/pw-browsers/chromium'

const env = {
  ...process.env,
  NODE_ENV: 'development',
  COOKIE_SECURE: 'false',
  HOST: '127.0.0.1',
  PORT: String(PORT),
  PUBLIC_ORIGIN: BASE,
  BASE,
  KEK_BASE64: randomBytes(32).toString('base64'),
  RATE_MAX: '100000',
  AUTH_RATE_MAX: '100000',
  DB_PATH,
}

function run(cmd, args) {
  return new Promise((res) => {
    const p = spawn(cmd, args, { env, stdio: 'inherit' })
    p.on('close', (code) => res(code ?? 1))
  })
}

async function waitHealth() {
  for (let i = 0; i < 60; i++) {
    try {
      const r = await fetch(`${BASE}/healthz`)
      if (r.ok) return true
    } catch {
      /* not up yet */
    }
    await new Promise((r) => setTimeout(r, 500))
  }
  return false
}

const suites = [
  ['crypto-test', 'node', ['scripts/crypto-test.mjs']],
  ['smoke', 'npx', ['tsx', 'scripts/smoke.ts']],
  ['sec-test', 'npx', ['tsx', 'scripts/sec-test.ts']],
  ['messenger-e2e', 'node', ['scripts/messenger-e2e.mjs']],
]
if (existsSync(CHROME)) suites.push(['ui-e2e', 'node', ['scripts/ui-e2e.mjs']])
else console.log('SKIP  ui-e2e (no chromium at ' + CHROME + ')')

const server = spawn('npx', ['tsx', 'src/index.ts'], { env, stdio: 'ignore' })
let failed = 0
try {
  if (!(await waitHealth())) {
    console.error('server did not start')
    process.exit(1)
  }
  for (const [name, cmd, args] of suites) {
    console.log(`\n──────── ${name} ────────`)
    const code = await run(cmd, args)
    if (code !== 0) failed++
  }
} finally {
  server.kill('SIGTERM')
  try {
    rmSync(DB_PATH, { force: true })
    rmSync(DB_PATH + '-wal', { force: true })
    rmSync(DB_PATH + '-shm', { force: true })
  } catch {
    /* ignore */
  }
}

console.log(failed === 0 ? '\n✓ ALL SUITES PASS' : `\n✗ ${failed} SUITE(S) FAILED`)
process.exit(failed === 0 ? 0 : 1)
