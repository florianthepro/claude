"""Quelle A -- phonotaktische Vollaufzaehlung.

Vollstaendige Aufzaehlung ueber das diktiersichere Alphabet in den Mustern
CVCVC, CVCCV, CCVCV und CVCVCV. Die Menge ist klein genug fuer eine echte
Enumeration (rund 400 000 Rohformen), der harte Filter raeumt sie auf, der
Score sortiert den Rest.
"""

from __future__ import annotations

from collections.abc import Iterator

from ..filters import LEGAL_ONSET_CLUSTERS, passes

CONS = "bdfghklmnprst"
VOWS = "aeiou"

# Nur Cluster, die aus dem erlaubten Alphabet stammen und als Anlaut zugelassen sind.
ONSETS = tuple(sorted(c for c in LEGAL_ONSET_CLUSTERS if len(c) == 2 and set(c) <= set(CONS)))
# Binnencluster fuer CVCCV: erlaubte Coda + erlaubter Onset an der Silbenfuge.
INNER = tuple(sorted({
    a + b for a in "lrnsmk" for b in CONS if a != b
} & {a + b for a in CONS for b in CONS if a != b}))

PATTERNS = ("CVCVC", "CVCCV", "CCVCV", "CVCVCV")


def _cvcvc() -> Iterator[str]:
    for c1 in CONS:
        for v1 in VOWS:
            for c2 in CONS:
                for v2 in VOWS:
                    for c3 in CONS:
                        yield c1 + v1 + c2 + v2 + c3


def _cvccv() -> Iterator[str]:
    for c1 in CONS:
        for v1 in VOWS:
            for cc in INNER:
                for v2 in VOWS:
                    yield c1 + v1 + cc + v2


def _ccvcv() -> Iterator[str]:
    for cc in ONSETS:
        for v1 in VOWS:
            for c2 in CONS:
                for v2 in VOWS:
                    yield cc + v1 + c2 + v2


def _cvcvcv() -> Iterator[str]:
    for c1 in CONS:
        for v1 in VOWS:
            for c2 in CONS:
                for v2 in VOWS:
                    for c3 in CONS:
                        for v3 in VOWS:
                            yield c1 + v1 + c2 + v2 + c3 + v3


_BUILDERS = {"CVCVC": _cvcvc, "CVCCV": _cvccv, "CCVCV": _ccvcv, "CVCVCV": _cvcvcv}


def generate(patterns: tuple[str, ...] = PATTERNS) -> Iterator[str]:
    """Liefert alle Formen, die die harten Kriterien bestehen."""
    seen: set[str] = set()
    for name in patterns:
        for label in _BUILDERS[name]():
            if label not in seen and passes(label):
                seen.add(label)
                yield label
