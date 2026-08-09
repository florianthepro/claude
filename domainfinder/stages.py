"""Der vierstufige Trichter.

    Stufe 0  Zonendatei   lokale Mengendifferenz, optional (ICANN CZDS)
    Stufe 1  DNS ueber DoH  NS-Record vorhanden  -> sicher vergeben
    Stufe 2  RDAP           HTTP 200 im Registry -> registriert, 404 -> frei
    Stufe 3  Cloudflare     registrable true/false -> die eigentliche Antwort

Jede Stufe schreibt ihr Ergebnis nach SQLite, bevor sie weitergeht. Ein Abbruch
kostet hoechstens die gerade laufenden Anfragen.
"""

from __future__ import annotations

import json
import sys
import time
from collections.abc import Callable, Iterable, Sequence
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

from .config import CloudflareCreds
from .db import Store
from .net import HttpError, RateLimiter, request

DOH_ENDPOINTS = (
    "https://cloudflare-dns.com/dns-query",
    "https://dns.google/resolve",
)
VERISIGN_RDAP = "https://rdap.verisign.com/com/v1/domain/"
IANA_BOOTSTRAP = "https://data.iana.org/rdap/dns.json"
CF_API = "https://api.cloudflare.com/client/v4"
CF_BATCH = 20  # harte Obergrenze der API


def _progress(msg: str) -> None:
    print(msg, file=sys.stderr, flush=True)


class StageBroken(RuntimeError):
    """Die Stufe scheitert systematisch, nicht vereinzelt."""


def _fail_fast(stage: str, tally: dict[str, int], done: int, sample: str,
               *, after: int = 150, share: float = 0.9) -> None:
    """Bricht ab, wenn fast jede Antwort unbrauchbar ist.

    Ein Konfigurations- oder Netzfehler betrifft alle Anfragen gleichzeitig.
    Ohne diese Bremse laeuft die Stufe stundenlang und schreibt Muell.
    """
    if done < after:
        return
    bad = tally.get("unknown", 0) + tally.get("error", 0)
    if bad / done >= share:
        raise StageBroken(
            f"Stufe {stage}: {bad} von {done} Antworten unbrauchbar. "
            f"Beispiel: {sample}. Abbruch, damit der Lauf nicht Muell schreibt."
        )


# --- Stufe 0 -----------------------------------------------------------------
def stage_zone(store: Store, tld: str, zonefile: Path | None) -> int:
    """Mengendifferenz gegen eine CZDS-Zonendatei.

    Ohne Zugang wird die Stufe uebersprungen; die Pipeline beginnt dann bei
    Stufe 1. Erwartet wird das uebliche Zonenformat (Name in Spalte 1).
    """
    if not zonefile:
        _progress("Stufe 0: keine Zonendatei angegeben, uebersprungen")
        return 0
    if not zonefile.exists():
        raise FileNotFoundError(f"Zonendatei nicht gefunden: {zonefile}")

    _progress(f"Stufe 0: lese {zonefile}")
    delegated: set[str] = set()
    opener: Callable = open
    if zonefile.suffix == ".gz":
        import gzip
        opener = gzip.open
    with opener(zonefile, "rt", encoding="utf-8", errors="replace") as fh:
        for line in fh:
            if not line or line[0] in ";$ \t":
                continue
            name = line.split(None, 1)[0].rstrip(".").lower()
            if name.endswith("." + tld) or name.endswith(tld):
                delegated.add(name)
    _progress(f"Stufe 0: {len(delegated)} delegierte Namen in der Zone")

    hits = 0
    pending = store.pending("zone", tld)
    rows = []
    for row in pending:
        if row["domain"] in delegated:
            rows.append((row["domain"], "zone", "taken", "in Zonendatei delegiert"))
            hits += 1
        else:
            rows.append((row["domain"], "zone", "free", "nicht in Zonendatei"))
    store.record_many(rows)
    _progress(f"Stufe 0: {hits} von {len(pending)} bereits delegiert")
    return hits


# --- Stufe 1 -----------------------------------------------------------------
def _doh_once(domain: str, endpoint: str, limiter: RateLimiter) -> tuple[str, str]:
    url = f"{endpoint}?name={domain}&type=NS"
    resp = request(url, headers={"accept": "application/dns-json"}, limiter=limiter,
                   accept_status=(200,), timeout=12.0)
    data = resp.json()
    status = data.get("Status")
    if status == 3:
        return "free", "NXDOMAIN"
    if status == 0:
        answers = [a for a in data.get("Answer", []) if a.get("type") == 2]
        if answers:
            return "taken", f"NS delegiert ({len(answers)})"
        # NOERROR ohne NS: Name existiert im Baum, aber ohne Delegation.
        return "free", "NOERROR ohne NS-Record"
    return "unknown", f"DNS-Status {status}"


def stage_dns(store: Store, tld: str, *, workers: int, rate: float,
              limit: int | None = None) -> dict[str, int]:
    pending = store.pending("dns", tld, limit=limit)
    if not pending:
        _progress("Stufe 1: nichts offen")
        return {}
    limiter = RateLimiter(rate, burst=workers)
    tally: dict[str, int] = {}
    done = 0
    started = time.monotonic()

    def work(domain: str) -> tuple[str, str, str]:
        last: Exception | None = None
        fallback = ("unknown", "kein Resolver hat geantwortet")
        for endpoint in DOH_ENDPOINTS:
            try:
                verdict, detail = _doh_once(domain, endpoint, limiter)
            except Exception as exc:  # noqa: BLE001 - eine Domain darf den Lauf nicht kippen
                last = exc
                continue
            if verdict != "unknown":
                return domain, verdict, detail
            # SERVFAIL kann am Resolver liegen. Erst wenn der zweite Anbieter
            # dasselbe sagt, ist die Auskunft wirklich unbrauchbar.
            fallback = (verdict, detail)
        if last is not None and fallback[0] == "unknown":
            return domain, "unknown", f"DoH fehlgeschlagen ({type(last).__name__}: {last})"
        return domain, fallback[0], fallback[1]

    _progress(f"Stufe 1: {len(pending)} Domains ueber DoH, {workers} Worker")
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(work, row["domain"]) for row in pending]
        batch = []
        for fut in as_completed(futures):
            domain, verdict, detail = fut.result()
            batch.append((domain, "dns", verdict, detail))
            tally[verdict] = tally.get(verdict, 0) + 1
            done += 1
            _fail_fast("1 (DoH)", tally, done, detail)
            if len(batch) >= 200:
                store.record_many(batch)
                batch.clear()
            if done % 500 == 0:
                rps = done / max(time.monotonic() - started, 0.001)
                _progress(f"  Stufe 1: {done}/{len(pending)} ({rps:.0f}/s) {tally}")
        if batch:
            store.record_many(batch)
    _progress(f"Stufe 1 fertig: {tally}")
    return tally


# --- Stufe 2 -----------------------------------------------------------------
_RDAP_BASE_CACHE: dict[str, str] = {"com": VERISIGN_RDAP}


def rdap_base(tld: str) -> str:
    """Loest den RDAP-Server einer TLD ueber den IANA-Bootstrap auf."""
    if tld in _RDAP_BASE_CACHE:
        return _RDAP_BASE_CACHE[tld]
    data = request(IANA_BOOTSTRAP, accept_status=(200,), timeout=20.0).json()
    for entry in data.get("services", []):
        tlds, urls = entry[0], entry[1]
        for name in tlds:
            if name.lower() == tld:
                base = urls[0]
                if not base.endswith("/"):
                    base += "/"
                _RDAP_BASE_CACHE[tld] = base + "domain/"
                return _RDAP_BASE_CACHE[tld]
    raise RuntimeError(f"kein RDAP-Server fuer .{tld} im IANA-Bootstrap")


def stage_rdap(store: Store, tld: str, *, workers: int, rate: float,
               limit: int | None = None) -> dict[str, int]:
    pending = store.pending("rdap", tld, limit=limit, after_stage="dns")
    if not pending:
        _progress("Stufe 2: nichts offen")
        return {}
    base = rdap_base(tld)
    limiter = RateLimiter(rate, burst=max(1.0, rate))
    tally: dict[str, int] = {}
    done = 0
    started = time.monotonic()

    def work(domain: str) -> tuple[str, str, str]:
        try:
            resp = request(base + domain, headers={"accept": "application/rdap+json"},
                           limiter=limiter, accept_status=(200, 404), timeout=20.0)
        except HttpError as exc:
            return domain, "unknown", f"RDAP-Fehler {exc.status}"
        if resp.status == 404:
            return domain, "free", "RDAP 404, nicht im Registry"
        try:
            statuses = ",".join(resp.json().get("status", [])) or "registriert"
        except ValueError:
            statuses = "registriert"
        return domain, "taken", f"RDAP 200: {statuses}"

    _progress(f"Stufe 2: {len(pending)} Domains ueber RDAP ({base}), {rate}/s")
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(work, row["domain"]) for row in pending]
        batch = []
        for fut in as_completed(futures):
            domain, verdict, detail = fut.result()
            batch.append((domain, "rdap", verdict, detail))
            tally[verdict] = tally.get(verdict, 0) + 1
            done += 1
            _fail_fast("2 (RDAP)", tally, done, detail)
            if len(batch) >= 50:
                store.record_many(batch)
                batch.clear()
            if done % 200 == 0:
                rps = done / max(time.monotonic() - started, 0.001)
                _progress(f"  Stufe 2: {done}/{len(pending)} ({rps:.1f}/s) {tally}")
        if batch:
            store.record_many(batch)
    _progress(f"Stufe 2 fertig: {tally}")
    return tally


# --- Stufe 3 -----------------------------------------------------------------
def stage_registrar(store: Store, tld: str, creds: CloudflareCreds, *, rate: float,
                    limit: int | None = None) -> dict[str, int]:
    """Cloudflare Registrar -- die einzige Stufe, die die Frage beantwortet."""
    pending = store.pending("registrar", tld, limit=limit, after_stage="rdap")
    if not pending:
        _progress("Stufe 3: nichts offen")
        return {}

    url = f"{CF_API}/accounts/{creds.account_id}/registrar/domain-check"
    headers = {
        "authorization": f"Bearer {creds.token}",
        "content-type": "application/json",
        "accept": "application/json",
    }
    limiter = RateLimiter(rate, burst=max(1.0, rate))
    tally: dict[str, int] = {}
    domains = [row["domain"] for row in pending]
    _progress(f"Stufe 3: {len(domains)} Domains bei Cloudflare, {CF_BATCH} je Anfrage")

    for start in range(0, len(domains), CF_BATCH):
        chunk = domains[start:start + CF_BATCH]
        payload = json.dumps({"domains": chunk}).encode()
        try:
            resp = request(url, method="POST", headers=headers, body=payload,
                           limiter=limiter, accept_status=(200,), timeout=30.0)
            data = resp.json()
        except HttpError as exc:
            # Statuscode ja, Token nein -- HttpError traegt nie Header.
            _progress(f"  Stufe 3: Anfrage fehlgeschlagen (HTTP {exc.status})")
            store.record_many([(d, "registrar", "error", f"API-Fehler HTTP {exc.status}")
                               for d in chunk])
            tally["error"] = tally.get("error", 0) + len(chunk)
            continue

        if not data.get("success", False):
            errs = "; ".join(str(e.get("message", e)) for e in data.get("errors", []))[:300]
            _progress(f"  Stufe 3: API meldet Fehler: {errs}")
            store.record_many([(d, "registrar", "error", f"API: {errs}") for d in chunk])
            tally["error"] = tally.get("error", 0) + len(chunk)
            continue

        results = data.get("result") or []
        if isinstance(results, dict):
            results = results.get("domains", results.get("result", []))
        by_name = {str(r.get("name", "")).lower(): r for r in results if isinstance(r, dict)}
        rows = []
        for domain in chunk:
            item = by_name.get(domain)
            if item is None:
                rows.append((domain, "registrar", "unknown", "keine Antwort zu diesem Namen"))
                tally["unknown"] = tally.get("unknown", 0) + 1
                continue
            registrable = bool(item.get("registrable"))
            pricing = item.get("pricing") or {}
            price = pricing.get("registration_cost")
            try:
                price = float(price) if price is not None else None
            except (TypeError, ValueError):
                price = None
            reason = item.get("reason") or ("" if registrable else "ohne Begruendung abgelehnt")
            verdict = "free" if registrable else "taken"
            tally[verdict] = tally.get(verdict, 0) + 1
            rows.append((domain, "registrar", verdict, reason,
                         price, pricing.get("currency"), item.get("tier")))
        store.record_many(rows)
        _progress(f"  Stufe 3: {min(start + CF_BATCH, len(domains))}/{len(domains)} {tally}")

    _progress(f"Stufe 3 fertig: {tally}")
    return tally


def recheck(domains: Sequence[str], creds: CloudflareCreds, *, rate: float = 2.0) -> list[dict]:
    """Direkte Nachpruefung unmittelbar vor der Registrierung.

    Verfuegbarkeit ist fluechtig -- das Ergebnis eines Laufs von gestern ist eine
    Vermutung, kein Befund.
    """
    url = f"{CF_API}/accounts/{creds.account_id}/registrar/domain-check"
    headers = {
        "authorization": f"Bearer {creds.token}",
        "content-type": "application/json",
        "accept": "application/json",
    }
    limiter = RateLimiter(rate)
    out: list[dict] = []
    for start in range(0, len(domains), CF_BATCH):
        chunk = list(domains[start:start + CF_BATCH])
        resp = request(url, method="POST", headers=headers,
                       body=json.dumps({"domains": chunk}).encode(),
                       limiter=limiter, accept_status=(200,), timeout=30.0)
        data = resp.json()
        results = data.get("result") or []
        if isinstance(results, dict):
            results = results.get("domains", [])
        out.extend(r for r in results if isinstance(r, dict))
    return out


def iterable_len(x: Iterable) -> int:  # pragma: no cover - Hilfsmittel
    return sum(1 for _ in x)
