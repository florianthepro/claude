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
from .gen import (brand5, branding, compound, itpair, itroot, jargon, morpheme,
                  phonotactic, vollstaendig)
from .output import write_rejections, write_results
from .scoring import classify, explain, score
from .select import cap
from .schoenheit import rank as schoen_rank
from .startup import rank as startup_rank
from .vertraut import rank as vertraut_rank
from .stages import recheck, stage_dns, stage_rdap, stage_registrar, stage_zone

ALT_TLDS = ("fail", "computer", "email", "exposed", "haus", "codes", "host")

# Vier Zwecke, vier Rangordnungen. Sie werden bewusst nicht verrechnet -- was
# fuer eine Homelab-Basisdomain traegt, traegt nicht fuer eine Marke.
#   infra     Proxmox, Traefik, Komodo -- klingt nach Infrastruktur
#   startup   Figma, Gusto -- klingt nach Produkt
#   vertraut  hafen, nadel, riegel -- klingt wie ein Wort, das es geben koennte
#   schoen    Prisma, Vanta, Solana -- klingt schoen, ohne etwas zu bedeuten
# Welche die score-Spalte fuellt, entscheidet --rank.
RANKERS = {
    "infra": score,
    "startup": lambda label, source=None: startup_rank(label),
    "vertraut": lambda label, source=None: vertraut_rank(label),
    "schoen": lambda label, source=None: schoen_rank(label),
}


def log(msg: str) -> None:
    print(msg, file=sys.stderr, flush=True)


# --- Kandidaten --------------------------------------------------------------
def build_candidates(store: Store, tld: str, *, limit_a: int, limit_b: int,
                     limit_c: int | None = None, limit_d: int = 0,
                     limit_e: int = 0, limit_f: int = 0, limit_g: int = 0,
                     limit_h: int = 0, limit_i: int = 0, limit_m: int = 0,
                     only_length: int | None = None, rank: str = "infra",
                     cap_prefix: int = 5, cap_rhyme: int = 5,
                     cap_skeleton: int = 3) -> dict[str, int]:
    """Erzeugt die drei Quellen getrennt und legt die besten in der Datenbank ab.

    Die Quellen bleiben getrennt, und jede laeuft durch die Vielfaltsgrenze --
    sonst besteht die Spitze aus Varianten desselben Morphems.
    """
    added: dict[str, int] = {}
    keep = (lambda lab: only_length is None or len(lab) == only_length)
    bewerte = RANKERS[rank]
    log(f"Rangordnung: {rank}")

    if limit_m:
        # Quelle M kennt keine Musterschablone und ist damit die einzige, deren
        # Abdeckung sich beweisen laesst. Sie laeuft ueber genau eine Laenge --
        # ohne --only-length waere nicht definiert, welche.
        laenge = only_length or 5
        log(f"Quelle M: vollstaendige Aufzaehlung, {laenge} Zeichen")
        m_raw = sorted(((bewerte(lab, "M"), lab, org)
                        for lab, org in vollstaendig.generate(laenge)), reverse=True)
        m_top = list(cap(m_raw, per_morpheme=10 ** 6, per_prefix=cap_prefix,
                         per_rhyme=cap_rhyme, per_skeleton=cap_skeleton, limit=limit_m))
        added["M"] = store.add_candidates(
            [(f"{lab}.{tld}", lab, tld, "M", "kunstwort", sc) for sc, lab, _ in m_top])
        log(f"  M: {len(m_raw)} bestehen die harten Kriterien, nach Vielfaltsgrenze"
            f" {len(m_top)}, {added['M']} neu")

    if limit_i:
        log("Quelle I: Markennamen aus Bildwortschatz")
        i_raw = sorted(((bewerte(lab, "I"), lab, org) for lab, org in branding.generate()
                        if keep(lab)), reverse=True)
        i_top = i_raw[:limit_i]          # kleiner Raum, vollstaendig pruefen
        added["I"] = store.add_candidates(
            [(f"{lab}.{tld}", lab, tld, "I", "marke", sc) for sc, lab, _ in i_top])
        log(f"  I: {len(i_raw)} bestehen die harten Kriterien, {added['I']} neu")

    if limit_h:
        log("Quelle H: zwei IT-Kuerzel, fuenf oder sechs Zeichen")
        h_raw = sorted(((bewerte(lab, "H"), lab, org) for lab, org in itpair.generate()
                        if keep(lab)), reverse=True)
        h_top = h_raw[:limit_h]          # kleiner Raum, vollstaendig pruefen
        added["H"] = store.add_candidates(
            [(f"{lab}.{tld}", lab, tld, "H", "it", sc) for sc, lab, _ in h_top])
        log(f"  H: {len(h_raw)} bestehen die harten Kriterien, {added['H']} neu")

    for limit, quelle, erzeuger, kat, was in (
            (limit_f, "F", compound.generate, "marke", "Komposita aus echten Woertern"),
            (limit_g, "G", compound.generate_it, "it", "IT-Komposita aus echten Woertern")):
        if not limit:
            continue
        log(f"Quelle {quelle}: {was}")
        raw = sorted(((bewerte(lab, quelle), lab, org) for lab, org in erzeuger()
                      if keep(lab)), reverse=True)
        # Bei Komposita wiederholt sich das erste Wort naturgemaess -- eine
        # enge Grenze wuerde `berg*` nach sechs Treffern abschneiden. Der Raum
        # ist klein genug, um ihn ganz zu pruefen.
        top = list(cap(raw, per_morpheme=10 ** 6, per_prefix=40, per_rhyme=40,
                       per_skeleton=10 ** 6, limit=limit))
        added[quelle] = store.add_candidates(
            [(f"{lab}.{tld}", lab, tld, quelle, kat, sc) for sc, lab, _ in top])
        log(f"  {quelle}: {len(raw)} bestehen die harten Kriterien, nach"
            f" Vielfaltsgrenze {len(top)}, {added[quelle]} neu")

    if limit_e:
        log("Quelle E: Markenform CVCCV/CCVCV, vollstaendig aufgezaehlt")
        e_raw = sorted(((bewerte(lab, "E"), lab, org) for lab, org in brand5.generate()
                        if keep(lab)), reverse=True)
        # Kein Deckel: der Raum ist klein genug fuer eine vollstaendige Pruefung,
        # und eine Vorauswahl waere hier genau der blinde Fleck, den Quelle E
        # vermeiden soll.
        e_top = e_raw[:limit_e]
        added["E"] = store.add_candidates(
            [(f"{lab}.{tld}", lab, tld, "E", "marke", sc) for sc, lab, _ in e_top])
        log(f"  E: {len(e_raw)} bestehen die harten Kriterien, nach Vielfaltsgrenze"
            f" {len(e_top)}, {added['E']} neu")

    if limit_d:
        log("Quelle D: IT-Wurzel in genau fuenf Zeichen")
        d_raw = sorted(((bewerte(lab, "D"), lab, org) for lab, org in itroot.generate()
                        if keep(lab)), reverse=True)
        d_top = list(cap(d_raw, per_morpheme=10 ** 6, per_prefix=cap_prefix,
                         per_rhyme=cap_rhyme, per_skeleton=cap_skeleton,
                         limit=limit_d))
        added["D"] = store.add_candidates(
            [(f"{lab}.{tld}", lab, tld, "D", "it", sc) for sc, lab, _ in d_top])
        log(f"  D: {len(d_raw)} bestehen die harten Kriterien, nach Vielfaltsgrenze"
            f" {len(d_top)}, {added['D']} neu")

    log("Quelle C: Fachbegriffe aus Standards")
    c_all = sorted(((bewerte(lab, "C"), lab, org) for lab, org in jargon.generate()
                    if keep(lab)), reverse=True)
    if limit_c:
        c_all = c_all[:limit_c]
    # Quelle C wird nicht gedeckelt: die Begriffe sind vorgegeben, nicht erzeugt.
    added["C"] = store.add_candidates(
        [(f"{lab}.{tld}", lab, tld, "C", "fachwitz", sc) for sc, lab, _ in c_all])
    log(f"  C: {len(c_all)} bestehen die harten Kriterien, {added['C']} neu")

    log("Quelle B: semantische Komposita")
    b_raw = sorted(((bewerte(lab, "B"), lab, org) for lab, org in morpheme.generate()
                    if keep(lab)), reverse=True)
    b_top = list(cap(b_raw, per_morpheme=3, per_prefix=4, per_rhyme=4, limit=limit_b))
    added["B"] = store.add_candidates(
        [(f"{lab}.{tld}", lab, tld, "B", classify(lab, "B"), sc) for sc, lab, _ in b_top])
    log(f"  B: {len(b_raw)} erzeugt, nach Vielfaltsgrenze {len(b_top)}, {added['B']} neu")

    log("Quelle A: phonotaktische Vollaufzaehlung (dauert einen Moment)")
    a_raw = sorted(((bewerte(lab, "A"), lab, None) for lab in phonotactic.generate()
                    if keep(lab)), reverse=True)
    a_top = list(cap(a_raw, per_prefix=cap_prefix, per_rhyme=cap_rhyme,
                     per_skeleton=cap_skeleton, limit=limit_a))
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
                             limit_c=args.limit_c, limit_d=args.limit_d, limit_e=args.limit_e,
                             limit_f=args.limit_f, limit_g=args.limit_g, limit_h=args.limit_h,
                             limit_i=args.limit_i, limit_m=args.limit_m,
                             only_length=args.only_length, rank=args.rank,
                             cap_prefix=args.cap_prefix, cap_rhyme=args.cap_rhyme,
                             cap_skeleton=args.cap_skeleton)
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
                                 limit_c=args.limit_c, limit_d=args.limit_d, limit_e=args.limit_e,
                             limit_f=args.limit_f, limit_g=args.limit_g, limit_h=args.limit_h,
                             limit_i=args.limit_i, limit_m=args.limit_m,
                                 only_length=args.only_length, rank=args.rank,
                             cap_prefix=args.cap_prefix, cap_rhyme=args.cap_rhyme,
                             cap_skeleton=args.cap_skeleton)
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
    bewerte = RANKERS[args.rank]
    log(f"Rangordnung: {args.rank}")
    try:
        for tld in args.tld:
            raus, neu = [], 0
            for row in store.all_candidates(tld):
                # Quelle C traegt echte Standardbegriffe mit bekannter Morphemfuge.
                verdict = check(row["label"], compound=row["source"] in ("C", "F", "G"))
                if verdict is not None:
                    raus.append((row["domain"], str(verdict)))
                    continue
                neuer = bewerte(row["label"], row["source"])
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
        sp.add_argument("--limit-i", type=int, default=0,
                        help="Quelle I: Markennamen aus Bildwortschatz")
        sp.add_argument("--limit-h", type=int, default=0,
                        help="Quelle H: zwei IT-Kuerzel, 5 oder 6 Zeichen")
        sp.add_argument("--limit-f", type=int, default=0,
                        help="Quelle F: Komposita aus echten Woertern")
        sp.add_argument("--limit-g", type=int, default=0,
                        help="Quelle G: IT-Komposita aus echten Woertern")
        sp.add_argument("--limit-e", type=int, default=0,
                        help="Quelle E: Markenform, vollstaendig aufgezaehlt")
        sp.add_argument("--limit-m", type=int, default=0,
                        help="Quelle M: vollstaendige Aufzaehlung ueber --only-length"
                             " (Vorgabe 5). Ohne Muster, dafuer beweisbar vollstaendig.")
        sp.add_argument("--limit-d", type=int, default=0,
                        help="Quelle D: IT-Wurzel in genau fuenf Zeichen")
        sp.add_argument("--only-length", type=int, default=None,
                        help="nur Labels mit genau dieser Zeichenzahl")
        sp.add_argument("--rank", choices=sorted(RANKERS), default="infra",
                        help="infra = Proxmox/Traefik, startup = Figma/Gusto,"
                             " vertraut = hafen/nadel/riegel")
        sp.add_argument("--cap-prefix", type=int, default=5)
        sp.add_argument("--cap-rhyme", type=int, default=5)
        sp.add_argument("--cap-skeleton", type=int, default=3)

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
    rf.add_argument("--rank", choices=sorted(RANKERS), default="infra",
                    help="muss zur Rangordnung des Laufs passen, sonst werden die"
                         " gespeicherten Werte mit der falschen Skala ueberschrieben")
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
