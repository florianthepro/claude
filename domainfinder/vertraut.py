"""Vertrautheit -- die dritte Rangordnung.

`scoring.py` misst Infrastrukturklang (Proxmox, Traefik). `startup.py` misst
Markenform (Figma, Gusto). Beide belohnen den offenen Auslaut auf -a und -o,
und genau der ist der Grund, warum `fodma`, `gukra` und `bralo` einem
deutschsprachigen Ohr fremd vorkommen: **so endet kein deutsches Wort**.

Deutsche Substantive enden auf -er, -en, -el, -e, seltener auf -al, -is, -us,
-at, -ur. Wer einen Namen sucht, der vertraut statt international klingen soll,
braucht deshalb eine andere Messlatte.

    Referenz gut:      hafen, nadel, kessel, riegel, torte, kante
    Referenz fremd:    fodma, gukra, bralo, ahefid

Diese Bewertung ersetzt die anderen nicht. Sie beantwortet eine andere Frage:
nicht "klingt das nach Produkt", sondern "klingt das nach einem Wort, das es
geben koennte".
"""

from __future__ import annotations

import re

from .filters import VOWELS
from .gen.lexicon import CORPUS
from .scoring import _ngram_score

# Auslaute, sortiert danach, wie selbstverstaendlich sie im Deutschen sind.
ENDUNGEN: tuple[tuple[str, float], ...] = (
    ("er", 1.00), ("en", 0.98), ("el", 0.96),      # Hafen, Nadel, Riegel
    ("et", 0.86), ("ig", 0.84), ("is", 0.82),
    ("al", 0.80), ("us", 0.78), ("at", 0.78), ("ur", 0.76), ("on", 0.74),
    ("um", 0.74), ("or", 0.74), ("ar", 0.72), ("in", 0.72),
)
# Einzelner Schlussbuchstabe, wenn keine der Endungen greift.
SCHLUSS = {**{c: 0.80 for c in "tnslrmdkfgpb"}, "e": 0.92,
           "a": 0.34, "o": 0.32, "i": 0.30, "u": 0.24}

# Wortstaemme, an denen ein Leser sich festhalten kann. Der Korpus allein
# reicht nicht: er enthaelt Satzwoerter, keine Dingwoerter wie `hafen` oder
# `tal`, und der Term feuerte deshalb nie.
STAEMME: tuple[str, ...] = (
    "tal", "hof", "berg", "feld", "mond", "sand", "gold", "glas", "korn",
    "luft", "stab", "ring", "steg", "rand", "ton", "trog", "kran", "brot",
    "herd", "horn", "halm", "hang", "helm", "fels", "firn", "flur", "rast",
    "raum", "rost", "span", "spur", "nest", "laub", "land", "torf", "most",
    "turm", "ufer", "grat", "kamm", "pfad", "tor", "hut", "rad", "lot", "pol",
    "mut", "ruf", "tag", "arm", "not", "gut", "bit", "log", "port", "host",
    "node", "link", "grid", "kern", "takt", "puls", "star", "stein", "haft",
    "stone", "gate", "post", "forge", "dome", "board", "beam", "frame",
)
_WORTE = frozenset(w for w in CORPUS if len(w) >= 3) | frozenset(STAEMME)

WEIGHTS = {"ngram": 0.45, "auslaut": 0.30, "stamm": 0.15, "anlaut": 0.10}


def auslaut(label: str) -> float:
    for endung, wert in ENDUNGEN:
        if label.endswith(endung):
            return wert
    return SCHLUSS.get(label[-1], 0.5)


def stamm(label: str) -> float:
    """Belohnt einen erkennbaren Wortanfang oder ein erkennbares Wortende."""
    best = 0.0
    for n in range(len(label) - 1, 2, -1):
        if label[:n] in _WORTE or label[-n:] in _WORTE:
            best = n / len(label)
            break
    return best


def breakdown(label: str) -> dict[str, float]:
    label = label.lower()
    parts = {
        "ngram": _ngram_score(label),
        "auslaut": auslaut(label),
        "stamm": stamm(label),
        "anlaut": 0.35 if label[0] in VOWELS else 1.0,
    }
    pen = 0.0
    if len(set(label)) <= 3:
        pen += 0.18
    if re.search(r"[aeiou]{3}", label):
        pen += 0.15                       # drei Vokale am Stueck liest niemand
    parts["penalty"] = pen
    parts["total"] = round(
        100.0 * max(0.0, sum(WEIGHTS[k] * v for k, v in parts.items() if k in WEIGHTS) - pen), 2
    )
    return parts


def rank(label: str) -> float:
    return breakdown(label)["total"]


def explain(label: str) -> str:  # pragma: no cover - Diagnose
    b = breakdown(label)
    body = "  ".join(f"{k}={b[k]:.2f}" for k in WEIGHTS)
    return f"{label:<10} {b['total']:6.2f}  {body}  abzug={b['penalty']:.2f}"
