<?php
declare(strict_types=1);

namespace Nexus\Core;

use PDO;

/** Legt das Schema an und führt idempotente Migrationen aus. */
final class Migrations
{
    public static function run(PDO $db): void
    {
        $tables = [
            // ---- Benutzer inkl. Rolle, Status, Quota -----------------
            "CREATE TABLE IF NOT EXISTS users (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                username     TEXT UNIQUE NOT NULL,
                email        TEXT NOT NULL,
                pass_hash    TEXT NOT NULL,
                display_name TEXT,
                role         TEXT NOT NULL DEFAULT 'user',      -- user | admin
                status       TEXT NOT NULL DEFAULT 'pending',   -- pending | active | suspended
                quota_bytes  INTEGER NOT NULL DEFAULT 536870912,
                ticket_email TEXT DEFAULT '',                   -- nur Admin: externe Ticket-Adresse
                theme        TEXT DEFAULT 'dark',
                accent       TEXT DEFAULT '#4d7ea8',
                created_at   TEXT DEFAULT (datetime('now')),
                approved_at  TEXT,
                approved_by  INTEGER
            )",
            // ---- Freischalt-Tickets ---------------------------------
            "CREATE TABLE IF NOT EXISTS tickets (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                code        TEXT UNIQUE NOT NULL,
                user_id     INTEGER NOT NULL,
                status      TEXT NOT NULL DEFAULT 'open',        -- open | approved | rejected
                created_at  TEXT DEFAULT (datetime('now')),
                resolved_at TEXT,
                resolved_by INTEGER,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )",
            // ---- Interne Nachrichten (komprimiert gespeichert) ------
            "CREATE TABLE IF NOT EXISTS imsg (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                sender_id    INTEGER NOT NULL,
                recipient_id INTEGER NOT NULL,
                subject      TEXT DEFAULT '',
                body_gz      BLOB,                                -- gzdeflate
                bytes        INTEGER NOT NULL DEFAULT 0,          -- Roh-Größe (Quota)
                seen         INTEGER NOT NULL DEFAULT 0,
                ticket_code  TEXT DEFAULT '',
                created_at   TEXT DEFAULT (datetime('now')),
                del_sender   INTEGER NOT NULL DEFAULT 0,
                del_recip    INTEGER NOT NULL DEFAULT 0
            )",
            // ---- Notizen --------------------------------------------
            "CREATE TABLE IF NOT EXISTS notes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                title      TEXT DEFAULT '',
                body       TEXT DEFAULT '',
                color      TEXT DEFAULT '#b3893f',
                pinned     INTEGER DEFAULT 0,
                updated_at TEXT DEFAULT (datetime('now'))
            )",
            // ---- Aufgaben -------------------------------------------
            "CREATE TABLE IF NOT EXISTS tasks (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL,
                title      TEXT NOT NULL,
                done       INTEGER NOT NULL DEFAULT 0,
                due        TEXT DEFAULT '',
                priority   INTEGER NOT NULL DEFAULT 1,           -- 0 niedrig,1 normal,2 hoch
                created_at TEXT DEFAULT (datetime('now'))
            )",
            // ---- Termine --------------------------------------------
            "CREATE TABLE IF NOT EXISTS events (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL,
                title       TEXT NOT NULL,
                day         TEXT NOT NULL,
                time        TEXT DEFAULT '',
                end_time    TEXT DEFAULT '',
                description TEXT DEFAULT '',
                color       TEXT DEFAULT '#c25a5a'
            )",
            // ---- Kontakte -------------------------------------------
            "CREATE TABLE IF NOT EXISTS contacts (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name    TEXT NOT NULL,
                email   TEXT DEFAULT '',
                phone   TEXT DEFAULT '',
                note    TEXT DEFAULT ''
            )",
            // ---- Lesezeichen ----------------------------------------
            "CREATE TABLE IF NOT EXISTS bookmarks (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id  INTEGER NOT NULL,
                title    TEXT NOT NULL,
                url      TEXT NOT NULL,
                color    TEXT DEFAULT '#4d7ea8',
                position INTEGER DEFAULT 0
            )",
            // ---- Externe Mailkonten (IMAP/SMTP) ---------------------
            "CREATE TABLE IF NOT EXISTS mail_accounts (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id   INTEGER NOT NULL,
                label     TEXT NOT NULL,
                email     TEXT NOT NULL,
                imap_host TEXT NOT NULL,
                imap_port INTEGER DEFAULT 993,
                imap_enc  TEXT DEFAULT 'ssl',
                smtp_host TEXT NOT NULL,
                smtp_port INTEGER DEFAULT 465,
                smtp_enc  TEXT DEFAULT 'ssl',
                username  TEXT NOT NULL,
                enc_pass  TEXT NOT NULL,
                validate_cert INTEGER NOT NULL DEFAULT 0
            )",
            // ---- Login-Drossel (Brute-Force) ------------------------
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                ip       TEXT NOT NULL,
                username TEXT DEFAULT '',
                ts       INTEGER NOT NULL
            )",
        ];
        foreach ($tables as $sql) {
            $db->exec($sql);
        }

        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_imsg_recip ON imsg(recipient_id, del_recip)',
            'CREATE INDEX IF NOT EXISTS idx_imsg_sender ON imsg(sender_id, del_sender)',
            'CREATE INDEX IF NOT EXISTS idx_notes_user ON notes(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_events_user_day ON events(user_id, day)',
            'CREATE INDEX IF NOT EXISTS idx_tasks_user ON tasks(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_login_ip ON login_attempts(ip, ts)',
        ];
        foreach ($indexes as $sql) {
            $db->exec($sql);
        }
    }
}
