import type { FastifyInstance } from 'fastify'
import { randomUUID } from 'node:crypto'
import { z } from 'zod'
import { db } from '../db.js'
import { requireAuth } from '../security/guards.js'

const b64 = z.string().min(1).max(256)
const opkList = z.array(z.object({ id: z.string().uuid(), pub: b64 })).max(200)

const publishBody = z.object({
  ikSign: b64,
  ikDh: b64,
  spkId: z.string().uuid(),
  spk: b64,
  spkSig: b64,
  opks: opkList,
})

const wire = z.object({
  t: z.enum(['m', 'p']),
  h: z.object({ dh: b64, pn: z.number().int().min(0), n: z.number().int().min(0) }),
  ct: z.string().min(1).max(16384),
  pre: z
    .object({
      ikSign: b64,
      ikDh: b64,
      ek: b64,
      spkId: z.string().uuid(),
      opkId: z.string().uuid().nullable(),
    })
    .optional(),
})

const MAILBOX_CAP = 5000
const resolveUser = db.prepare("SELECT id FROM users WHERE username = ? AND status = 'active'")
const usernameOf = db.prepare('SELECT username FROM users WHERE id = ?')

const popOpk = db.transaction((userId: string) => {
  const row = db.prepare('SELECT id, pub FROM opks WHERE user_id = ? LIMIT 1').get(userId) as
    | { id: string; pub: string }
    | undefined
  if (row) db.prepare('DELETE FROM opks WHERE id = ?').run(row.id)
  return row
})

export function messengerRoutes(app: FastifyInstance): void {
  // Publish or replace this user's prekey bundle and add one-time prekeys.
  app.post('/api/keys', async (req, reply) => {
    const s = requireAuth(req, reply)
    if (!s) return
    const p = publishBody.safeParse(req.body)
    if (!p.success) return reply.code(400).send({ error: 'invalid' })
    const now = Date.now()
    const tx = db.transaction(() => {
      db.prepare(
        `INSERT INTO mkeys (user_id, ik_sign, ik_dh, spk_id, spk, spk_sig, updated_at)
         VALUES (@u, @iks, @ikd, @sid, @spk, @sig, @now)
         ON CONFLICT(user_id) DO UPDATE SET
           ik_sign=@iks, ik_dh=@ikd, spk_id=@sid, spk=@spk, spk_sig=@sig, updated_at=@now`,
      ).run({ u: s.userId, iks: p.data.ikSign, ikd: p.data.ikDh, sid: p.data.spkId, spk: p.data.spk, sig: p.data.spkSig, now })
      const ins = db.prepare('INSERT OR IGNORE INTO opks (id, user_id, pub, created_at) VALUES (?, ?, ?, ?)')
      for (const o of p.data.opks) ins.run(o.id, s.userId, o.pub, now)
    })
    tx()
    const { c } = db.prepare('SELECT COUNT(*) c FROM opks WHERE user_id = ?').get(s.userId) as { c: number }
    return { ok: true, opkCount: c }
  })

  // Replenish one-time prekeys.
  app.post('/api/keys/opks', async (req, reply) => {
    const s = requireAuth(req, reply)
    if (!s) return
    const p = z.object({ opks: opkList }).safeParse(req.body)
    if (!p.success) return reply.code(400).send({ error: 'invalid' })
    const now = Date.now()
    const ins = db.prepare('INSERT OR IGNORE INTO opks (id, user_id, pub, created_at) VALUES (?, ?, ?, ?)')
    const tx = db.transaction(() => {
      for (const o of p.data.opks) ins.run(o.id, s.userId, o.pub, now)
    })
    tx()
    const { c } = db.prepare('SELECT COUNT(*) c FROM opks WHERE user_id = ?').get(s.userId) as { c: number }
    return { opkCount: c }
  })

  app.get('/api/keys/self/status', async (req, reply) => {
    const s = requireAuth(req, reply)
    if (!s) return
    const bundle = db.prepare('SELECT 1 FROM mkeys WHERE user_id = ?').get(s.userId)
    const { c } = db.prepare('SELECT COUNT(*) c FROM opks WHERE user_id = ?').get(s.userId) as { c: number }
    reply.header('Cache-Control', 'no-store')
    return { hasBundle: !!bundle, opkCount: c }
  })

  // Fetch a peer's bundle and consume one one-time prekey. Mutating → CSRF-protected.
  app.post('/api/keys/fetch', async (req, reply) => {
    const s = requireAuth(req, reply)
    if (!s) return
    const p = z.object({ username: z.string().min(1).max(64) }).safeParse(req.body)
    if (!p.success) return reply.code(400).send({ error: 'invalid' })
    const peer = resolveUser.get(p.data.username) as { id: string } | undefined
    if (!peer) return reply.code(404).send({ error: 'no_user' })
    const bundle = db.prepare('SELECT ik_sign, ik_dh, spk_id, spk, spk_sig FROM mkeys WHERE user_id = ?').get(peer.id) as
      | { ik_sign: string; ik_dh: string; spk_id: string; spk: string; spk_sig: string }
      | undefined
    if (!bundle) return reply.code(404).send({ error: 'no_keys' })
    const opk = popOpk(peer.id)
    reply.header('Cache-Control', 'no-store')
    return {
      ikSign: bundle.ik_sign,
      ikDh: bundle.ik_dh,
      spkId: bundle.spk_id,
      spk: bundle.spk,
      spkSig: bundle.spk_sig,
      opk: opk ? { id: opk.id, pub: opk.pub } : null,
    }
  })

  // Relay an opaque encrypted envelope.
  app.post('/api/messages', async (req, reply) => {
    const s = requireAuth(req, reply)
    if (!s) return
    const p = z.object({ to: z.string().min(1).max(64), message: wire }).safeParse(req.body)
    if (!p.success) return reply.code(400).send({ error: 'invalid' })
    const peer = resolveUser.get(p.data.to) as { id: string } | undefined
    if (!peer) return reply.code(404).send({ error: 'no_user' })
    const { c } = db.prepare('SELECT COUNT(*) c FROM mailbox WHERE recipient_id = ?').get(peer.id) as { c: number }
    if (c >= MAILBOX_CAP) return reply.code(429).send({ error: 'mailbox_full' })
    const id = randomUUID()
    db.prepare('INSERT INTO mailbox (id, recipient_id, sender_id, body, created_at) VALUES (?, ?, ?, ?, ?)').run(
      id,
      peer.id,
      s.userId,
      JSON.stringify(p.data.message),
      Date.now(),
    )
    return { ok: true, id }
  })

  // Poll the inbox. Envelopes remain until acked.
  app.get('/api/messages', async (req, reply) => {
    const s = requireAuth(req, reply)
    if (!s) return
    const rows = db
      .prepare('SELECT id, sender_id, body, created_at FROM mailbox WHERE recipient_id = ? ORDER BY created_at LIMIT 200')
      .all(s.userId) as { id: string; sender_id: string; body: string; created_at: number }[]
    reply.header('Cache-Control', 'no-store')
    return rows.map((r) => ({
      id: r.id,
      from: (usernameOf.get(r.sender_id) as { username: string } | undefined)?.username ?? null,
      message: JSON.parse(r.body),
      ts: r.created_at,
    }))
  })

  app.post('/api/messages/ack', async (req, reply) => {
    const s = requireAuth(req, reply)
    if (!s) return
    const p = z.object({ ids: z.array(z.string().uuid()).max(500) }).safeParse(req.body)
    if (!p.success) return reply.code(400).send({ error: 'invalid' })
    const del = db.prepare('DELETE FROM mailbox WHERE id = ? AND recipient_id = ?')
    let deleted = 0
    const tx = db.transaction(() => {
      for (const id of p.data.ids) deleted += del.run(id, s.userId).changes
    })
    tx()
    return { ok: true, deleted }
  })

  app.get('/api/backup', async (req, reply) => {
    const s = requireAuth(req, reply)
    if (!s) return
    const row = db.prepare('SELECT blob FROM kbackup WHERE user_id = ?').get(s.userId) as { blob: string } | undefined
    reply.header('Cache-Control', 'no-store')
    return { blob: row ? JSON.parse(row.blob) : null }
  })

  app.put('/api/backup', async (req, reply) => {
    const s = requireAuth(req, reply)
    if (!s) return
    const p = z
      .object({
        blob: z.object({
          v: z.number().int(),
          iters: z.number().int().min(1).max(10_000_000),
          salt: b64,
          iv: b64,
          ct: z.string().min(1).max(65536),
        }),
      })
      .safeParse(req.body)
    if (!p.success) return reply.code(400).send({ error: 'invalid' })
    db.prepare(
      `INSERT INTO kbackup (user_id, blob, updated_at) VALUES (?, ?, ?)
       ON CONFLICT(user_id) DO UPDATE SET blob=excluded.blob, updated_at=excluded.updated_at`,
    ).run(s.userId, JSON.stringify(p.data.blob), Date.now())
    return { ok: true }
  })
}
