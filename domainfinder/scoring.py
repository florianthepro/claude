"""Bewertung der weichen Kriterien.

Kalibrierung (in `tests/test_scoring.py` festgenagelt):
    score("google") > score("ahefid")
    score("bitfabric") > score("ahefid")

Der Score bewertet ausschliesslich, wie sehr ein Label nach einem existierenden
Produkt klingt. Die harten Kriterien stehen in `filters.py` und werden hier
absichtlich nicht wiederholt -- `google` und `bitfabric` wuerden sie nicht
bestehen, muessen aber trotzdem gut bewertet werden.
"""

from __future__ import annotations

import math
import re
from collections import Counter
from functools import lru_cache

from .filters import VOWELS, soft_hits
from .gen.lexicon import CORPUS, MORPHEMES_IT, MORPHEMES_MATTER

ALPHABET = "abcdefghijklmnopqrstuvwxyz"
_BOUND = "^"
_END = "$"

# Anlautstaerke: Plosive tragen einen Markennamen, Vokale und h tragen nicht.
ONSET_STRENGTH = {
    **{c: 1.00 for c in "bdgkpt"},
    **{c: 0.85 for c in "fs"},
    **{c: 0.75 for c in "mn"},
    **{c: 0.70 for c in "lr"},
    "h": 0.35,
    **{v: 0.30 for v in "aeiou"},
}
CODA_STRENGTH = {
    **{c: 0.95 for c in "knrtsl"},
    **{c: 0.85 for c in "dmp"},
    **{c: 0.75 for c in "bgf"},
    "h": 0.20,
    **{v: 0.60 for v in "aeiou"},
}

_MORPHEMES = tuple(sorted(set(MORPHEMES_IT) | set(MORPHEMES_MATTER), key=len, reverse=True))
_SHORT_MORPHS = tuple(m for m in _MORPHEMES if 3 <= len(m) <= 6)


def _build_ngrams() -> tuple[dict[str, float], dict[str, float]]:
    """Bigramm- und Trigramm-Logwahrscheinlichkeiten aus dem eingebetteten Korpus."""
    bi: Counter[str] = Counter()
    bi_ctx: Counter[str] = Counter()
    tri: Counter[str] = Counter()
    tri_ctx: Counter[str] = Counter()
    for word in CORPUS:
        w = f"{_BOUND}{word.lower()}{_END}"
        for i in range(len(w) - 1):
            bi[w[i:i + 2]] += 1
            bi_ctx[w[i]] += 1
        for i in range(len(w) - 2):
            tri[w[i:i + 3]] += 1
            tri_ctx[w[i:i + 2]] += 1

    v = len(ALPHABET) + 2
    k_bi, k_tri = 0.5, 0.2
    bi_lp = {g: math.log((c + k_bi) / (bi_ctx[g[0]] + k_bi * v)) for g, c in bi.items()}
    tri_lp = {g: math.log((c + k_tri) / (tri_ctx[g[:2]] + k_tri * v)) for g, c in tri.items()}
    bi_lp["__floor__"] = math.log(k_bi / (max(bi_ctx.values()) + k_bi * v))
    tri_lp["__floor__"] = math.log(k_tri / (max(tri_ctx.values()) + k_tri * v))
    return bi_lp, tri_lp


_BI_LP, _TRI_LP = _build_ngrams()


def _ngram_score(label: str) -> float:
    """Mittlere Uebergangswahrscheinlichkeit, auf 0..1 gestaucht."""
    w = f"{_BOUND}{label}{_END}"
    bi = [_BI_LP.get(w[i:i + 2], _BI_LP["__floor__"]) for i in range(len(w) - 1)]
    tri = [_TRI_LP.get(w[i:i + 3], _TRI_LP["__floor__"]) for i in range(len(w) - 2)]
    mean = 0.55 * (sum(bi) / len(bi)) + 0.45 * (sum(tri) / max(len(tri), 1))
    # -2.0 entspricht sehr gaengig, -9.0 entspricht praktisch nie gesehen.
    return max(0.0, min(1.0, (mean + 9.0) / 7.0))


def _shape_score(label: str) -> float:
    """Silbenrhythmus. Sauberes CV-Wechselspiel traegt, Cluster kosten."""
    pattern = "".join("V" if ch in VOWELS else "C" for ch in label)
    runs = re.findall(r"C+|V+", pattern)
    penalty = 0.0
    for run in runs:
        if len(run) >= 3:
            penalty += 0.30 if run[0] == "C" else 0.45
        elif len(run) == 2:
            penalty += 0.08 if run[0] == "C" else 0.25
    base = 1.0 - min(penalty, 1.0)
    # Reine Kette aus offenen Silben (bababa) ist rhythmisch, aber inhaltsleer.
    if re.fullmatch(r"(CV)+", pattern) and len(label) >= 6:
        base -= 0.10
    return max(0.0, base)


def _variety_score(label: str) -> float:
    """Buchstabenarmut und monotone Vokalfolgen kosten Punkte."""
    distinct = len(set(label)) / len(label)
    vowels = [ch for ch in label if ch in VOWELS]
    vdistinct = len(set(vowels)) / len(vowels) if vowels else 0.0
    monotone = 0.0
    if len(vowels) >= 2 and len(set(vowels)) == 1:
        monotone = 0.35
    elif len(vowels) >= 3 and len(set(vowels)) == 2:
        monotone = 0.10
    vratio = len(vowels) / len(label)
    balance = 1.0 - min(abs(vratio - 0.42) / 0.42, 1.0)
    return max(0.0, 0.45 * distinct + 0.30 * vdistinct + 0.25 * balance - monotone)


def _length_score(label: str) -> float:
    n = len(label)
    if 5 <= n <= 8:
        return 1.0 - abs(n - 6.5) * 0.04
    if n == 4:
        return 0.82
    if n == 9:
        return 0.80
    if n == 10:
        return 0.62
    return 0.30


@lru_cache(maxsize=100_000)
def _morpheme_bonus(label: str) -> float:
    """Belohnt erkennbare Morpheme -- der Unterschied zwischen Wort und Rauschen.

    Ein einzelnes Morphem mit einem angeklebten Buchstaben (k+inode) ist kein
    neues Wort, sondern ein Baukastenteil, und wird gedeckelt. Volle Punkte gibt
    es nur, wenn zwei erkennbare Teile das Label fast vollstaendig abdecken --
    das Portainer-Muster.
    """
    known = set(_MORPHEMES)
    best = 0.0
    for m in _MORPHEMES:
        if len(m) < 3 or m not in label:
            continue
        rest = label.replace(m, "", 1)
        cover = len(m) / len(label)
        anchored = label.startswith(m) or label.endswith(m)
        credit = cover * (1.0 if anchored else 0.75)
        if len(rest) <= 2 and rest not in known:
            # Morphem plus ein, zwei angeklebte Buchstaben (k+inode, ki+node):
            # erkennbar, aber ein Baukastenteil und kein eigenstaendiges Wort.
            credit = min(credit, 0.55)
        best = max(best, credit)

    hits = [m for m in _SHORT_MORPHS if m in label]
    if len(hits) >= 2:
        pair = max(
            (len(a) + len(b) for a in hits for b in hits
             if a != b and a not in b and b not in a),
            default=0,
        )
        if pair:
            best = max(best, min(1.0, pair / (len(label) + 1)))
    return min(best, 1.0)


def _penalties(label: str) -> tuple[float, list[str]]:
    pen, notes = 0.0, []
    if label.endswith("h"):
        pen += 0.12
        notes.append("End-h")
    for m in re.finditer(r"[aeiou]h[aeiou]", label):
        pen += 0.10
        notes.append(f"Dehnungs-h {m.group()}")
    if label[0] in VOWELS:
        pen += 0.06
        notes.append("Vokalanlaut")
    if len(set(label)) <= 3:
        pen += 0.15
        notes.append("Buchstabenarmut")
    soft = soft_hits(label)
    if soft:
        pen += 0.12 * len(soft)
        notes.append("weiche Blacklist: " + ",".join(soft))
    return pen, notes


WEIGHTS = {
    "ngram": 0.34,
    "morpheme": 0.24,
    "onset": 0.13,
    "shape": 0.12,
    "variety": 0.10,
    "length": 0.07,
}

# Quelle C wird angehoben, weil der phonetische Teil des Scores nicht sehen
# kann, dass `badsig` ein echter DNS-Rcode ist. Die Aufgabe gewichtet den
# selbstreferenziellen Fachwitz ausdruecklich, also steht der Bonus hier offen
# im Modell statt als stille Nachsortierung. Auf die Kalibrierung wirkt er
# nicht: google, bitfabric und ahefid haben keine Quelle.
SOURCE_BONUS = {"A": 0.0, "B": 0.0, "C": 12.0, "D": 10.0, "E": 0.0,
                "F": 0.0, "G": 0.0}


def breakdown(label: str, source: str | None = None) -> dict[str, float]:
    label = label.lower()
    parts = {
        "ngram": _ngram_score(label),
        "morpheme": _morpheme_bonus(label),
        "onset": 0.70 * ONSET_STRENGTH.get(label[0], 0.5)
                 + 0.30 * CODA_STRENGTH.get(label[-1], 0.5),
        "shape": _shape_score(label),
        "variety": _variety_score(label),
        "length": _length_score(label),
    }
    pen, _ = _penalties(label)
    parts["penalty"] = pen
    parts["bonus"] = SOURCE_BONUS.get(source or "", 0.0)
    parts["total"] = round(
        100.0 * max(0.0, sum(WEIGHTS[k] * v for k, v in parts.items() if k in WEIGHTS) - pen)
        + parts["bonus"], 2
    )
    return parts


def score(label: str, source: str | None = None) -> float:
    return breakdown(label, source)["total"]


def explain(label: str) -> str:  # pragma: no cover - Diagnose
    b = breakdown(label)
    _, notes = _penalties(label.lower())
    body = "  ".join(f"{k}={b[k]:.2f}" for k in WEIGHTS)
    return f"{label:<12} {b['total']:6.2f}  {body}  abzug={b['penalty']:.2f} {';'.join(notes)}"


def classify(label: str, source: str) -> str:
    """Kategorie fuer die CSV-Spalte."""
    if source == "C":
        return "fachwitz"
    if source == "D":
        return "it"
    low = label.lower()
    if any(m in low for m in MORPHEMES_IT if len(m) >= 4):
        return "it"
    if any(m in low for m in MORPHEMES_MATTER if len(m) >= 4):
        return "marke"
    if source == "B":
        return "marke"
    # Namensform: zwei offene Silben, endet auf Vokal -- klingt wie ein Eigenname.
    if re.fullmatch(r"[^aeiou][aeiou][^aeiou][aeiou]([^aeiou][aeiou])?", low):
        return "name"
    return "zufall"
