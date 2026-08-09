"""SQLite-Zustand.

Jede gepruefte Domain wird mit Stufe, Ergebnis und Zeitstempel festgehalten.
Daraus folgt Wiederaufnahme nach Abbruch: `pending()` liefert nur, was fuer die
jeweilige Stufe noch kein Ergebnis hat.
"""

from __future__ import annotations

import sqlite3
import threading
import time
from collections.abc import Iterable, Sequence
from pathlib import Path

SCHEMA = """
CREATE TABLE IF NOT EXISTS candidates (
    domain     TEXT PRIMARY KEY,
    label      TEXT NOT NULL,
    tld        TEXT NOT NULL,
    source     TEXT NOT NULL,          -- A | B | C
    category   TEXT NOT NULL,          -- it | marke | name | fachwitz | zufall
    score      REAL NOT NULL,
    created_at REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS checks (
    domain     TEXT NOT NULL,
    stage      TEXT NOT NULL,          -- zone | dns | rdap | registrar
    verdict    TEXT NOT NULL,          -- free | taken | unknown | error
    detail     TEXT,                   -- reason / rcode / HTTP-Status
    price      REAL,
    currency   TEXT,
    tier       TEXT,
    checked_at REAL NOT NULL,
    PRIMARY KEY (domain, stage)
);

CREATE INDEX IF NOT EXISTS idx_checks_stage_verdict ON checks(stage, verdict);
CREATE INDEX IF NOT EXISTS idx_cand_tld_score ON candidates(tld, score DESC);
"""

STAGE_ORDER = ("zone", "dns", "rdap", "registrar")


class Store:
    """Thread-sicherer, sehr kleiner Wrapper um sqlite3."""

    def __init__(self, path: Path):
        path.parent.mkdir(parents=True, exist_ok=True)
        self._lock = threading.Lock()
        self._conn = sqlite3.connect(str(path), check_same_thread=False, timeout=30)
        self._conn.row_factory = sqlite3.Row
        with self._lock:
            self._conn.execute("PRAGMA journal_mode=WAL")
            self._conn.execute("PRAGMA synchronous=NORMAL")
            self._conn.executescript(SCHEMA)
            self._conn.commit()
        try:
            path.chmod(0o600)
        except OSError:  # pragma: no cover - z.B. exotische Dateisysteme
            pass

    # -- Kandidaten ---------------------------------------------------------
    def add_candidates(self, rows: Iterable[tuple[str, str, str, str, str, float]]) -> int:
        """rows: (domain, label, tld, source, category, score). Idempotent."""
        now = time.time()
        payload = [(*r, now) for r in rows]
        with self._lock:
            cur = self._conn.executemany(
                "INSERT OR IGNORE INTO candidates"
                " (domain,label,tld,source,category,score,created_at) VALUES (?,?,?,?,?,?,?)",
                payload,
            )
            self._conn.commit()
            return cur.rowcount

    def pending(self, stage: str, tld: str, *, limit: int | None = None,
                after_stage: str | None = None) -> list[sqlite3.Row]:
        """Kandidaten ohne Ergebnis in `stage`.

        `after_stage` verlangt zusaetzlich ein 'free'-Ergebnis der Vorstufe --
        so wandert nur weiter, was der Trichter durchgelassen hat.
        """
        sql = [
            "SELECT c.* FROM candidates c",
            "LEFT JOIN checks k ON k.domain = c.domain AND k.stage = ?",
        ]
        params: list[object] = [stage]
        if after_stage:
            sql.append("JOIN checks p ON p.domain = c.domain AND p.stage = ? AND p.verdict = 'free'")
            params.append(after_stage)
        sql.append("WHERE c.tld = ? AND k.domain IS NULL")
        params.append(tld)
        sql.append("ORDER BY c.score DESC")
        if limit:
            sql.append("LIMIT ?")
            params.append(limit)
        with self._lock:
            return list(self._conn.execute(" ".join(sql), params))

    # -- Ergebnisse ---------------------------------------------------------
    def record(self, domain: str, stage: str, verdict: str, detail: str | None = None,
               price: float | None = None, currency: str | None = None,
               tier: str | None = None) -> None:
        with self._lock:
            self._conn.execute(
                "INSERT INTO checks (domain,stage,verdict,detail,price,currency,tier,checked_at)"
                " VALUES (?,?,?,?,?,?,?,?)"
                " ON CONFLICT(domain,stage) DO UPDATE SET"
                " verdict=excluded.verdict, detail=excluded.detail, price=excluded.price,"
                " currency=excluded.currency, tier=excluded.tier, checked_at=excluded.checked_at",
                (domain, stage, verdict, detail, price, currency, tier, time.time()),
            )

    def record_many(self, rows: Sequence[tuple]) -> None:
        for row in rows:
            self.record(*row)
        self.commit()

    def commit(self) -> None:
        with self._lock:
            self._conn.commit()

    # -- Auswertung ---------------------------------------------------------
    def counts(self, tld: str | None = None) -> dict[str, dict[str, int]]:
        sql = ("SELECT k.stage, k.verdict, COUNT(*) n FROM checks k"
               " JOIN candidates c ON c.domain = k.domain")
        params: list[object] = []
        if tld:
            sql += " WHERE c.tld = ?"
            params.append(tld)
        sql += " GROUP BY k.stage, k.verdict"
        out: dict[str, dict[str, int]] = {}
        with self._lock:
            for row in self._conn.execute(sql, params):
                out.setdefault(row["stage"], {})[row["verdict"]] = row["n"]
        return out

    def results(self, tld: str, stage: str = "registrar", verdict: str = "free") -> list[sqlite3.Row]:
        with self._lock:
            return list(self._conn.execute(
                "SELECT c.domain, c.label, c.source, c.category, c.score,"
                "       k.verdict, k.detail, k.price, k.currency, k.tier"
                " FROM candidates c JOIN checks k ON k.domain = c.domain"
                " WHERE c.tld = ? AND k.stage = ? AND k.verdict = ?"
                " ORDER BY c.score DESC",
                (tld, stage, verdict),
            ))

    def rejections(self, tld: str, stage: str = "registrar") -> list[sqlite3.Row]:
        """Abgelehnte Domains samt `reason` -- Abnahmekriterium."""
        with self._lock:
            return list(self._conn.execute(
                "SELECT c.domain, c.source, c.category, c.score, k.verdict, k.detail"
                " FROM candidates c JOIN checks k ON k.domain = c.domain"
                " WHERE c.tld = ? AND k.stage = ? AND k.verdict != 'free'"
                " ORDER BY c.score DESC",
                (tld, stage),
            ))

    def all_candidates(self, tld: str) -> list[sqlite3.Row]:
        with self._lock:
            return list(self._conn.execute(
                "SELECT * FROM candidates WHERE tld = ? ORDER BY score DESC", (tld,)))

    def update_score(self, domain: str, score: float, category: str) -> None:
        with self._lock:
            self._conn.execute("UPDATE candidates SET score = ?, category = ? WHERE domain = ?",
                               (score, category, domain))

    def drop_candidates(self, domains: Sequence[str]) -> int:
        """Entfernt Kandidaten, behaelt aber ihre Pruefergebnisse.

        Ein Pruefergebnis ist eine Tatsache ueber die Welt, ein Filter ist
        unsere Meinung. Aendert sich die Meinung, darf die teuer erkaufte
        Tatsache nicht verloren gehen -- sonst kostet jede Filterkorrektur
        wieder Netzanfragen.
        """
        with self._lock:
            cur = self._conn.executemany("DELETE FROM candidates WHERE domain = ?",
                                         [(d,) for d in domains])
            self._conn.commit()
            return cur.rowcount

    def close(self) -> None:
        with self._lock:
            self._conn.commit()
            self._conn.close()
