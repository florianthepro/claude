// Real-browser proof: two users register (incl. TOTP), set up the messenger, and
// exchange E2E messages. Verifies decryption on both sides and matching safety
// numbers. Requires the app running (COOKIE_SECURE=false) at BASE.
import { chromium } from 'playwright'
import * as OTPAuth from 'otpauth'

const BASE = process.env.BASE ?? 'http://127.0.0.1:8799'
const CHROME = process.env.CHROME_PATH ?? '/opt/pw-browsers/chromium'

let fails = 0
const check = (name, cond) => {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}`)
  if (!cond) fails++
}
const totp = (secret) =>
  new OTPAuth.TOTP({ algorithm: 'SHA1', digits: 6, period: 30, secret: OTPAuth.Secret.fromBase32(secret) }).generate()

async function register(page, username) {
  await page.goto(BASE)
  await page.click('.tabs button:has-text("Registrieren")')
  await page.fill('input[autocomplete="username"]', username)
  await page.fill('input[autocomplete="new-password"]', 'a strong password 123')
  await page.click('button:has-text("Weiter")')
  await page.waitForSelector('.secret')
  const secret = (await page.textContent('.secret')).trim()
  await page.fill('input[autocomplete="one-time-code"]', totp(secret))
  await page.click('button:has-text("Bestätigen")')
  await page.waitForSelector('.codes')
  await page.click('.card button:has-text("Weiter")')
  await page.waitForSelector('.tile:has-text("Messenger")')
}

async function openMessenger(page) {
  await page.click('.tile:has-text("Messenger")')
  await page.waitForSelector('input[placeholder="Recovery-Passphrase"]')
  await page.fill('input[placeholder="Recovery-Passphrase"]', 'recovery passphrase')
  await page.fill('input[placeholder="wiederholen"]', 'recovery passphrase')
  await page.click('button:has-text("Einrichten")')
  await page.waitForSelector('.msg-app')
}

async function startChat(page, peer) {
  await page.fill('.new-chat input', peer)
  await page.click('.new-chat button:has-text("Chat")')
  await page.waitForSelector(`.chat-head .peer:has-text("${peer}")`)
}

async function sendMessage(page, text) {
  await page.fill('.composer input', text)
  await page.click('.composer button:has-text("Senden")')
}

async function safetyNumber(page) {
  await page.click('button:has-text("Safety-Number")')
  await page.waitForSelector('.sn-num')
  return (await page.textContent('.sn-num')).trim()
}

const stamp = process.hrtime.bigint().toString(16).slice(-6)
const aliceName = 'alice' + stamp
const bobName = 'bob' + stamp

const browser = await chromium.launch({ executablePath: CHROME, headless: true })
try {
  const aliceCtx = await browser.newContext()
  const bobCtx = await browser.newContext()
  const alice = await aliceCtx.newPage()
  const bob = await bobCtx.newPage()
  alice.setDefaultTimeout(20000)
  bob.setDefaultTimeout(20000)

  // subtle crypto must be available on the loopback (secure context)
  await alice.goto(BASE)
  check('WebCrypto available in browser', await alice.evaluate(() => !!(window.crypto && crypto.subtle && crypto.subtle.generateKey)))

  await register(alice, aliceName)
  await register(bob, bobName)
  check('both reach the app shell', true)

  await openMessenger(alice)
  await openMessenger(bob)
  check('both set up messenger keys', true)

  await startChat(alice, bobName)
  await sendMessage(alice, 'hallo bob im browser')
  await alice.waitForSelector('.bubble.out:has-text("hallo bob im browser")')

  await bob.waitForSelector(`.convo-item:has-text("${aliceName}")`)
  await bob.click(`.convo-item:has-text("${aliceName}")`)
  await bob.waitForSelector('.bubble.in:has-text("hallo bob im browser")')
  check('bob decrypts alice message in-browser', true)

  await sendMessage(bob, 'hallo alice zurueck')
  await alice.waitForSelector('.bubble.in:has-text("hallo alice zurueck")')
  check('alice decrypts bob reply in-browser', true)

  const snA = await safetyNumber(alice)
  const snB = await safetyNumber(bob)
  check('safety numbers match in both browsers', snA === snB && snA.length > 0)

  // reload alice to prove local persistence (no passphrase re-prompt)
  await alice.reload()
  await alice.waitForSelector('.tile:has-text("Messenger")')
  await alice.click('.tile:has-text("Messenger")')
  await alice.waitForSelector('.msg-app')
  await alice.click(`.convo-item:has-text("${bobName}")`)
  await alice.waitForSelector('.bubble.out:has-text("hallo bob im browser")')
  check('history persists locally after reload', true)
} catch (e) {
  console.log('FAIL  exception: ' + (e?.message || e))
  fails++
} finally {
  await browser.close()
}

console.log(fails === 0 ? '\nALL PASS' : `\n${fails} FAILURE(S)`)
process.exit(fails === 0 ? 0 : 1)
