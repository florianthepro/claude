-- Stimmwerk – Datenbankschema (SQLite, WAL)
-- Kernregeln sind zusätzlich zur Anwendungslogik als Constraints verankert:
--   * 1 Thema pro Autor und Kalendertag  -> UNIQUE(author_id, created_date)
--   * 1 Stimme pro Thema und Pseudonym   -> PRIMARY KEY(topic_id, user_id)
--   * 1 offene Meldung pro Thema         -> partieller UNIQUE-Index
--   * 1 Meldung je Melder und Thema      -> UNIQUE(topic_id, reporter_id)
--   * 1 Jury-Sitz je Meldung und Person  -> PRIMARY KEY(report_id, user_id)

CREATE TABLE IF NOT EXISTS schema_info (
    k TEXT PRIMARY KEY,
    v TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    pseudonym_hash      TEXT    NOT NULL UNIQUE,
    lang                TEXT    NOT NULL DEFAULT 'de' CHECK (lang IN ('de','en')),
    is_system           INTEGER NOT NULL DEFAULT 0 CHECK (is_system IN (0,1)),
    is_seed             INTEGER NOT NULL DEFAULT 0 CHECK (is_seed IN (0,1)),
    jury_cooldown_until TEXT,
    created_at          TEXT    NOT NULL,
    last_login_at       TEXT
);

CREATE TABLE IF NOT EXISTS categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,
    name_de    TEXT    NOT NULL,
    name_en    TEXT    NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS topics (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL REFERENCES users(id),
    title        TEXT    NOT NULL,
    goal         TEXT    NOT NULL,
    reasoning    TEXT    NOT NULL,
    category_id  INTEGER NOT NULL REFERENCES categories(id),
    scope_level  TEXT    NOT NULL CHECK (scope_level IN ('kommune','landkreis','bundesland','bund')),
    scope_name   TEXT,
    status       TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','removed')),
    created_at   TEXT    NOT NULL,
    created_date TEXT    NOT NULL,
    UNIQUE (author_id, created_date)
);
CREATE INDEX IF NOT EXISTS ix_topics_status_created ON topics(status, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_topics_category       ON topics(category_id);
CREATE INDEX IF NOT EXISTS ix_topics_scope          ON topics(scope_level, scope_name);

CREATE TABLE IF NOT EXISTS votes (
    topic_id   INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id)  ON DELETE CASCADE,
    choice     TEXT    NOT NULL CHECK (choice IN ('for','against')),
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    PRIMARY KEY (topic_id, user_id)
);
CREATE INDEX IF NOT EXISTS ix_votes_user ON votes(user_id);

CREATE TABLE IF NOT EXISTS favorites (
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind       TEXT    NOT NULL CHECK (kind IN ('category','scope')),
    ref        TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    PRIMARY KEY (user_id, kind, ref)
);

CREATE TABLE IF NOT EXISTS reports (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_id         INTEGER NOT NULL REFERENCES topics(id),
    -- NULL nach DSGVO-Kontolöschung des Melders; die Meldung selbst bleibt gültig.
    reporter_id      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    criteria         TEXT    NOT NULL,
    freetext         TEXT,
    status           TEXT    NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending','voting','decided_removed','decided_kept')),
    jury_size        INTEGER NOT NULL,
    quorum           INTEGER NOT NULL,
    created_at       TEXT    NOT NULL,
    voting_starts_at TEXT    NOT NULL,
    decided_at       TEXT,
    UNIQUE (topic_id, reporter_id)
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_reports_open
    ON reports(topic_id) WHERE status IN ('pending','voting');
CREATE INDEX IF NOT EXISTS ix_reports_status ON reports(status);

CREATE TABLE IF NOT EXISTS report_jurors (
    report_id INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    user_id   INTEGER NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
    vote      TEXT    CHECK (vote IN ('confirm','reject','neutral')),
    voted_at  TEXT,
    PRIMARY KEY (report_id, user_id)
);
CREATE INDEX IF NOT EXISTS ix_jurors_user ON report_jurors(user_id);

CREATE TABLE IF NOT EXISTS rate_limits (
    k            TEXT    PRIMARY KEY,
    window_start INTEGER NOT NULL,
    cnt          INTEGER NOT NULL
);
