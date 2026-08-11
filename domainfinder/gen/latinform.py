"""Quelle L -- lateinisch klingende Kunstwoerter, vollstaendig aufgezaehlt.

Quelle K nimmt echte lateinische Staemme. Ergebnis: von 488 Formen ist keine
einzige frei -- `nodus`, `retia`, `tekta`, `ordan`, alles vergeben. Wer in
dieser Klasse etwas finden will, muss weiter ins Erfundene, ohne den Klang zu
verlieren.

Zwei Muster fehlten allen bisherigen Quellen:

    VCCV   okta, alta, orta      vokalanlautend, vier Zeichen
    VCCVC  orbis, aptus, indus   vokalanlautend, fuenf Zeichen

`phonotactic.py` erzeugt ausschliesslich konsonantisch anlautende Formen, und
`startup.py` bestraft den Vokalanlaut ausdruecklich -- fuer Figma und Gusto zu
Recht, fuer Okta und Orbis falsch. Der lateinische Ernst kommt gerade daher.

Dazu die Endung: `-us`, `-is`, `-um`, `-or`, `-an`, `-on` klingen nuechtern,
`-io` und `-ola` klingen nach Produktlaunch. Gefiltert wird auf die nuechternen.
"""

from __future__ import annotations

from collections.abc import Iterator

from ..filters import LEGAL_MEDIAL_CLUSTERS, LEGAL_ONSET_CLUSTERS, check

CONS = "bdfghklmnprst"
VOWS = "aeiou"
ONSET_CLUSTERS = tuple(sorted(c for c in LEGAL_ONSET_CLUSTERS
                              if len(c) == 2 and set(c) <= set(CONS)))
MEDIAL = tuple(sorted(c for c in LEGAL_MEDIAL_CLUSTERS if set(c) <= set(CONS)))

# Nuechterne Auslaute. Der Wert sagt, wie serioes die Endung traegt.
AUSLAUT: dict[str, float] = {
    "us": 1.00, "is": 1.00, "um": 0.98, "or": 0.96, "on": 0.94, "an": 0.92,
    "ar": 0.90, "en": 0.90, "as": 0.86, "os": 0.86, "at": 0.84, "it": 0.84,
    "ur": 0.82, "el": 0.80, "im": 0.78, "in": 0.78, "ur": 0.82,
    # Okta und Drata enden offen. Das traegt, wenn der Rest lateinisch klingt.
    "a": 0.88, "o": 0.84,
}


def auslaut_wert(label: str) -> float:
    """Wie nuechtern der Auslaut ist. 0 heisst: gehoert nicht in diese Klasse."""
    for endung in sorted(AUSLAUT, key=len, reverse=True):
        if label.endswith(endung):
            return AUSLAUT[endung]
    return 0.0


def _formen() -> Iterator[str]:
    """Alle Vier- und Fuenfzeichner der lateinischen Muster."""
    for v1 in VOWS:                          # VCCV  -- okta
        for cc in MEDIAL:
            for v2 in VOWS:
                yield v1 + cc + v2
    for v1 in VOWS:                          # VCCVC -- orbis
        for cc in MEDIAL:
            for v2 in VOWS:
                for c in CONS:
                    yield v1 + cc + v2 + c
    for v1 in VOWS:                          # VCVCV / VCVC -- ateno, adus
        for c1 in CONS:
            for v2 in VOWS:
                for c2 in CONS:
                    yield v1 + c1 + v2 + c2
                    for v3 in VOWS:
                        yield v1 + c1 + v2 + c2 + v3
    for c1 in CONS:                          # CVCVC / CVCCV -- tines, vanta
        for v1 in VOWS:
            for c2 in CONS:
                for v2 in VOWS:
                    for c3 in CONS:
                        yield c1 + v1 + c2 + v2 + c3
            for cc in MEDIAL:
                for v2 in VOWS:
                    yield c1 + v1 + cc + v2
    for cc in ONSET_CLUSTERS:                # CCVCV -- drata
        for v1 in VOWS:
            for c2 in CONS:
                for v2 in VOWS:
                    yield cc + v1 + c2 + v2


def generate() -> Iterator[tuple[str, float]]:
    """Liefert (label, auslaut_wert) fuer alles mit nuechternem Auslaut."""
    seen: set[str] = set()
    for label in _formen():
        if label in seen or not (4 <= len(label) <= 5):
            continue
        seen.add(label)
        wert = auslaut_wert(label)
        if wert == 0.0:
            continue
        if check(label) is None:
            yield label, wert
