"""Kommandozeile.

    domainfinder generate --tld com --limit-a 8000 --limit-b 2500
    domainfinder run      --tld com
    domainfinder run      --tld fail --tld computer ...
    domainfinder report   --tld com --top 10
    domainfinder recheck  domain.com ...

Fortschritt geht auf stderr, Ergebnisse auf stdout beziehungsweise in die CSV.
Ein Lauf ist jederzeit abbrechbar und wird beim naechsten Aufruf fortgesetzt.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from . import __version__
from .config import (DEFAULT_ENV_FILE, DEFAULT_RATES, DEFAULT_WORKERS, ConfigError,
                     cloudflare_creds, load_env_file, redact)
from .db import Store
from .filters import check
from .gen import jargon, morpheme, phonotactic
from .output import write_rejections, write_results
from .scoring import classify, explain, score
from .select import cap
from .stages import recheck, stage_dns, stage_rdap, stage_registrar, stage_zone

ALT_TLDS = ("fail", "computer", "email", "exposed", "haus", "codes", "host")


def log(msg: str) -> None:
    print(msg, file=sys.stderr, flush=True)


# --- Kandidaten --------------------------------------------------------------
def build_candidates(store: Store, tld: str, *, limit_a: int, limit_b: int,
                     limit_c: int | None = None) -> dict[str, int]:
    """Erzeugt die drei Quellen getrennt und legt die besten in der Datenbank ab.

    Die Quellen bleiben getrennt, und jede laeuft durch die Vielfaltsgrenze --
    sonst besteht die Spitze aus Varianten desselben Morphems.
    """
    added: dict[str, int] = {}

    log("Quelle C: Fachbegriffe aus Standards")
    c_all = sorted(((score(lab, 'C'), lab, org) for lab, org in jargon.generate()), reverse=True)
    if limit_c:
        c_all = c_all[:limit_c]
    # Quelle C wird nicht gedeckelt: die Begriffe sind vorgegeben, nicht erzeugt.
    added["C"] = store.add_candidates(
        [(f"{lab}.{tld}", lab, tld, "C", "fachwitz", sc) for sc, lab, _ in c_all])
    log(f"  C: {len(c_all)} bestehen die harten Kriterien, {added['C']} neu")

    log("Quelle B: semantische Komposita")
    b_raw = sorted(((score(lab, 'B'), lab, org) for lab, org in morpheme.generate()), reverse=True)
    b_top = list(cap(b_raw, per_morpheme=3, per_prefix=4, per_rhyme=4, limit=limit_b))
    added["B"] = store.add_candidates(
        [(f"{lab}.{tld}", lab, tld, "B", classify(lab, "B"), sc) for sc, lab, _ in b_top])
    log(f"  B: {len(b_raw)} erzeugt, nach Vielfaltsgrenze {len(b_top)}, {added['B']} neu")

    log("Quelle A: phonotaktische Vollaufzaehlung (dauert einen Moment)")
    a_raw = sorted(((score(lab, 'A'), lab, None) for lab in phonotactic.generate()), reverse=True)
    a_top = list(cap(a_raw, limit=limit_a))
    added["A"] = store.add_candidates(
        [(f"{lab}.{tld}", lab, tld, "A", classify(lab, "A"), sc) for sc, lab, _ in a_top])
    log(f"  A: {len(a_raw)} bestehen die harten Kriterien, nach Vielfaltsgrenze"
        f" {len(a_top)}, {added['A']} neu")
    return added


# --- Unterbefehle ------------------------------------------------------------
def cmd_generate(args: argparse.Namespace) -> int:
    store = Store(args.db)
    try:
        for tld in args.tld:
            log(f"== Kandidaten fuer .{tld} ==")
            build_candidates(store, tld, limit_a=args.limit_a, limit_b=args.limit_b,
                             limit_c=args.limit_c)
    finally:
        store.close()
    return 0


def cmd_run(args: argparse.Namespace) -> int:
    load_env_file(args.env_file)
    creds = cloudflare_creds(required=not args.no_registrar)
    if creds:
        log(f"Cloudflare: Konto {creds.account_id}, Token {redact(creds.token)}")
    else:
        log("Cloudflare: keine Zugangsdaten, Lauf endet nach Stufe 2")

    store = Store(args.db)
    try:
        for tld in args.tld:
            log(f"===== .{tld} =====")
            if not store.pending("dns", tld, limit=1) and not store.results(tld, "dns", "free"):
                build_candidates(store, tld, limit_a=args.limit_a, limit_b=args.limit_b,
                                 limit_c=args.limit_c)
            stage_zone(store, tld, args.zonefile)
            stage_dns(store, tld, workers=args.dns_workers, rate=args.dns_rate,
                      limit=args.limit_stage1)
            stage_rdap(store, tld, workers=args.rdap_workers, rate=args.rdap_rate,
                       limit=args.limit_stage2)
            if creds:
                stage_registrar(store, tld, creds, rate=args.registrar_rate,
                                limit=args.limit_stage3)
            log(f"Zwischenstand .{tld}: {store.counts(tld)}")
            _emit(store, tld, args)
    finally:
        store.close()
    return 0


def _emit(store: Store, tld: str, args: argparse.Namespace) -> None:
    stage = "registrar" if store.results(tld, "registrar", "free") else "rdap"
    out = args.out_dir / f"ergebnis-{tld}.csv"
    rej = args.out_dir / f"abgelehnt-{tld}.csv"
    n = write_results(store, tld, out, stage=stage, limit=args.top, also_stdout=not args.quiet)
    m = write_rejections(store, tld, rej, stage=stage)
    log(f"Ergebnis .{tld}: {n} Zeilen in {out} (Stufe {stage}), {m} Ablehnungen in {rej}")
    if stage != "registrar":
        log("ACHTUNG: ohne Stufe 3 ist das Registry-Zustand, keine Registrierbarkeit.")


def cmd_report(args: argparse.Namespace) -> int:
    store = Store(args.db)
    try:
        for tld in args.tld:
            log(f"Bestand .{tld}: {store.counts(tld)}")
            _emit(store, tld, args)
    finally:
        store.close()
    return 0


def cmd_refresh(args: argparse.Namespace) -> int:
    """Wendet die aktuellen Filter und Gewichte auf gespeicherte Kandidaten an.

    Die harten Kriterien wachsen mit jedem Lauf -- eine Marke, eine deutsche
    Peinlichkeit, die vorher niemand bedacht hat. Ohne diesen Befehl muesste
    man dafuer alle Netzanfragen wiederholen.
    """
    store = Store(args.db)
    try:
        for tld in args.tld:
            raus, neu = [], 0
            for row in store.all_candidates(tld):
                verdict = check(row["label"])
                if verdict is not None:
                    raus.append((row["domain"], str(verdict)))
                    continue
                neuer = score(row["label"], row["source"])
                if abs(neuer - row["score"]) > 1e-9:
                    store.update_score(row["domain"], neuer, classify(row["label"], row["source"]))
                    neu += 1
            store.commit()
            if raus:
                store.drop_candidates([d for d, _ in raus])
            log(f".{tld}: {len(raus)} Kandidaten fallen unter den aktuellen Kriterien,"
                f" {neu} Scores angepasst")
            for domain, why in raus[:15]:
                log(f"    - {domain}: {why}")
            if len(raus) > 15:
                log(f"    ... und {len(raus) - 15} weitere")
    finally:
        store.close()
    return 0


def cmd_recheck(args: argparse.Namespace) -> int:
    """Unmittelbar vor der Registrierung erneut fragen."""
    load_env_file(args.env_file)
    creds = cloudflare_creds(required=True)
    assert creds is not None
    results = recheck(args.domains, creds)
    ok = 0
    for item in results:
        registrable = bool(item.get("registrable"))
        ok += registrable
        pricing = item.get("pricing") or {}
        price = pricing.get("registration_cost")
        line = f"{item.get('name'):<24} {'REGISTRIERBAR' if registrable else 'NICHT VERFUEGBAR'}"
        if registrable and price is not None:
            line += f"  {price} {pricing.get('currency', '')} tier={item.get('tier', '')}"
        if not registrable:
            line += f"  grund={item.get('reason', 'unbekannt')}"
        print(line)
    return 0 if ok else 1


def cmd_score(args: argparse.Namespace) -> int:
    for label in args.labels:
        print(explain(label))
    return 0


# --- Argumente ---------------------------------------------------------------
def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="domainfinder",
                                description="Findet verifiziert registrierbare Domainnamen.")
    p.add_argument("--version", action="version", version=__version__)
    p.add_argument("--db", type=Path, default=Path("out/state.sqlite3"),
                   help="SQLite-Zustand (Standard: out/state.sqlite3)")
    p.add_argument("--out-dir", type=Path, default=Path("out"))
    p.add_argument("--env-file", type=Path, default=DEFAULT_ENV_FILE,
                   help="Datei mit Secrets, Modus 0600")
    p.add_argument("--quiet", action="store_true", help="keine CSV auf stdout")
    sub = p.add_subparsers(dest="cmd", required=True)

    def add_gen_opts(sp: argparse.ArgumentParser) -> None:
        sp.add_argument("--tld", action="append", default=None)
        sp.add_argument("--alt-tlds", action="store_true",
                        help=f"zweiter Durchlauf ueber {', '.join(ALT_TLDS)}")
        sp.add_argument("--limit-a", type=int, default=9000)
        sp.add_argument("--limit-b", type=int, default=2500)
        sp.add_argument("--limit-c", type=int, default=None)

    g = sub.add_parser("generate", help="nur Kandidaten erzeugen")
    add_gen_opts(g)
    g.set_defaults(func=cmd_generate)

    r = sub.add_parser("run", help="kompletter Trichter")
    add_gen_opts(r)
    r.add_argument("--zonefile", type=Path, default=None, help="CZDS-Zonendatei fuer Stufe 0")
    r.add_argument("--no-registrar", action="store_true", help="Stufe 3 auslassen")
    r.add_argument("--dns-workers", type=int, default=DEFAULT_WORKERS["dns"])
    r.add_argument("--dns-rate", type=float, default=DEFAULT_RATES["dns"])
    r.add_argument("--rdap-workers", type=int, default=DEFAULT_WORKERS["rdap"])
    r.add_argument("--rdap-rate", type=float, default=DEFAULT_RATES["rdap"])
    r.add_argument("--registrar-rate", type=float, default=DEFAULT_RATES["registrar"])
    r.add_argument("--limit-stage1", type=int, default=None)
    r.add_argument("--limit-stage2", type=int, default=None)
    r.add_argument("--limit-stage3", type=int, default=None)
    r.add_argument("--top", type=int, default=None, help="CSV auf N Zeilen kuerzen")
    r.set_defaults(func=cmd_run)

    rep = sub.add_parser("report", help="CSV aus dem vorhandenen Zustand")
    rep.add_argument("--tld", action="append", default=None)
    rep.add_argument("--alt-tlds", action="store_true")
    rep.add_argument("--top", type=int, default=None)
    rep.set_defaults(func=cmd_report)

    rf = sub.add_parser("refresh", help="aktuelle Filter/Gewichte auf den Bestand anwenden")
    rf.add_argument("--tld", action="append", default=None)
    rf.add_argument("--alt-tlds", action="store_true")
    rf.set_defaults(func=cmd_refresh)

    rc = sub.add_parser("recheck", help="Cloudflare unmittelbar vor der Registrierung fragen")
    rc.add_argument("domains", nargs="+")
    rc.set_defaults(func=cmd_recheck)

    sc = sub.add_parser("score", help="Score eines Labels erklaeren")
    sc.add_argument("labels", nargs="+")
    sc.set_defaults(func=cmd_score)
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    if hasattr(args, "tld"):
        tlds = list(args.tld or [])
        if getattr(args, "alt_tlds", False):
            tlds.extend(ALT_TLDS)
        args.tld = tlds or ["com"]
    if hasattr(args, "out_dir"):
        args.out_dir.mkdir(parents=True, exist_ok=True)
    try:
        return args.func(args)
    except ConfigError as exc:
        log(f"Konfigurationsfehler: {exc}")
        return 2
    except KeyboardInterrupt:
        log("abgebrochen -- der Zustand steht in der Datenbank, ein neuer Aufruf setzt fort")
        return 130


if __name__ == "__main__":
    raise SystemExit(main())
