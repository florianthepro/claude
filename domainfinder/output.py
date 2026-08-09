"""CSV-Ausgabe. Ergebnisdateien bekommen Modus 0600."""

from __future__ import annotations

import csv
import sys
from pathlib import Path

from .config import secure_write_path
from .db import Store

COLUMNS = ["domain", "registrierbar", "preis", "waehrung", "tier",
           "grund", "score", "kategorie", "quelle"]


def _rows(store: Store, tld: str, stage: str, verdict: str) -> list[list]:
    out = []
    for r in store.results(tld, stage=stage, verdict=verdict):
        out.append([
            r["domain"],
            "ja" if r["verdict"] == "free" else "nein",
            f"{r['price']:.2f}" if r["price"] is not None else "",
            r["currency"] or "",
            r["tier"] or "",
            r["detail"] or "",
            f"{r['score']:.2f}",
            r["category"],
            r["source"],
        ])
    return out


def write_results(store: Store, tld: str, path: Path, *, stage: str = "registrar",
                  limit: int | None = None, also_stdout: bool = True) -> int:
    """Schreibt ausschliesslich, was `stage` als frei bestaetigt hat."""
    rows = _rows(store, tld, stage, "free")
    if limit:
        rows = rows[:limit]
    secure_write_path(path)
    with path.open("w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(COLUMNS)
        w.writerows(rows)
    if also_stdout:
        w = csv.writer(sys.stdout)
        w.writerow(COLUMNS)
        w.writerows(rows)
    return len(rows)


def write_rejections(store: Store, tld: str, path: Path, *, stage: str = "registrar") -> int:
    """Protokolliert jede abgelehnte Domain samt `reason` -- Abnahmekriterium."""
    rows = [[r["domain"], r["verdict"], r["detail"] or "", f"{r['score']:.2f}",
             r["category"], r["source"]]
            for r in store.rejections(tld, stage=stage)]
    secure_write_path(path)
    with path.open("w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["domain", "ergebnis", "grund", "score", "kategorie", "quelle"])
        w.writerows(rows)
    return len(rows)
