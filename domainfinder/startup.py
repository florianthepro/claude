"""Startup-Tauglichkeit -- eine zweite Rangordnung neben `scoring.py`.

`scoring.py` ist auf Infrastrukturnamen kalibriert: Proxmox, Traefik, Portainer.
Die tragen einen harten Auslaut und ein erkennbares Morphem. Produktnamen der
Startup-Sorte funktionieren anders -- Figma, Gusto, Vanta, Canva, Okta, Asana --
und zwar fast immer nach demselben Muster:

    zwei Silben, offener Auslaut auf -a oder -o, Form CVCCV.

Deshalb steht diese Bewertung getrennt. Wuerde man sie in `scoring.py` mischen,
faellt die dort festgenagelte Kalibrierung (`google` > `ahefid`) um, und die
Infrastrukturnamen wuerden schlechter. Zwei Zwecke, zwei Funktionen.

Referenzen im diktiersicheren Alphabet: `gusto` und `figma` sind die Messlatte,
`ahefid` und `huhuhu` sind das Gegenteil.
"""

from __future__ import annotations

import re

from .filters import FAIR_MEDIAL, PRIME_MEDIAL, VOWELS
from .scoring import _ngram_score, _variety_score

# Die Form traegt den Namen. CVCCV ist das Muster von Figma, Gusto, Vanta,
# Canva, Datto -- der Binnencluster gibt dem Wort einen Anschlag, der offene
# Auslaut macht es sprechbar.
SHAPE_BONUS = {
    "CVCCV": 1.00,   # figma, gusto, vanta
    "CCVCV": 0.90,   # brela, plato
    "CVCV": 0.86,    # miro, loom-artig
    "CVCVC": 0.74,   # eher Infrastruktur als Startup
    "CCVCVC": 0.66,
    "CVCCVC": 0.64,
}

# Offener Auslaut. -a und -o klingen nach Produkt, -u klingt nach Versehen.
FINAL_VOWEL = {"a": 1.00, "o": 0.94, "i": 0.78, "e": 0.66, "u": 0.42}
# Harter Auslaut ist nicht verboten, traegt aber weniger weit.
FINAL_CONSONANT = {**{c: 0.55 for c in "knrtslmp"}, **{c: 0.42 for c in "bdgfh"}}

ONSET = {
    **{c: 1.00 for c in "bdgkpt"},   # Plosive tragen
    **{c: 0.88 for c in "fs"},
    **{c: 0.74 for c in "mn"},
    **{c: 0.66 for c in "lr"},
    "h": 0.30,
    **{v: 0.22 for v in "aeiou"},    # Vokalanlaut ist selten ein guter Markenanfang
}

# Welcher Binnencluster in der Wortmitte steht, entscheidet mit darueber, ob ein
# Name nach Marke klingt. Die harten Kriterien lassen `nf`, `rf`, `lf` und `mf`
# zu Recht durch -- Senf, Wurf, Elfe, Kampf sind diktiersicher. Fuer einen
# Markennamen klingen sie trotzdem hart. Das ist Geschmack, kein Fehler, und
# gehoert deshalb hierher und nicht in den Filter.

WEIGHTS = {"ngram": 0.24, "shape": 0.20, "final": 0.20, "cluster": 0.16,
           "onset": 0.12, "variety": 0.08}


def _cluster_quality(label: str) -> float:
    inner = re.findall(r"(?<=[aeiou])[^aeiou]{2,}(?=[aeiou])", label)
    if not inner:
        return 0.72                       # Einzelkonsonant in der Mitte: neutral
    worst = 1.0
    for cl in inner:
        if cl in PRIME_MEDIAL:
            worst = min(worst, 1.0)
        elif cl in FAIR_MEDIAL:
            worst = min(worst, 0.55)
        else:
            worst = min(worst, 0.18)      # nf, rf, mf, kn, gn: hart im Klang
    return worst


def shape(label: str) -> str:
    return "".join("V" if ch in VOWELS else "C" for ch in label)


def syllables(label: str) -> int:
    return len(re.findall(r"[aeiou]+", label))


def breakdown(label: str) -> dict[str, float]:
    label = label.lower()
    pat = shape(label)
    last = label[-1]

    parts = {
        "ngram": _ngram_score(label),
        "shape": SHAPE_BONUS.get(pat, 0.45),
        "final": FINAL_VOWEL.get(last, 0.0) if last in VOWELS
                 else FINAL_CONSONANT.get(last, 0.35),
        "onset": ONSET.get(label[0], 0.5),
        "variety": _variety_score(label),
        "cluster": _cluster_quality(label),
    }

    pen = 0.0
    if syllables(label) != 2:
        pen += 0.12                      # ein- oder dreisilbig traegt kuerzer
    if len(set(label)) <= 3:
        pen += 0.18                      # bababa-Effekt
    if label[0] in VOWELS:
        pen += 0.05
    if re.search(r"[aeiou]h", label):
        pen += 0.08                      # Dehnungs-h klingt nach Rachen

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
    return f"{label:<10} {b['total']:6.2f}  {shape(label):<7} {body}  abzug={b['penalty']:.2f}"
