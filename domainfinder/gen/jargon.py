"""Quelle C -- selbstreferenzielle Fachbegriffe.

Ausgangsmenge sind ausschliesslich Begriffe, die in einem Standard oder einer
Implementierung wirklich so heissen. Dazu kommen die kanonischen Kurzformen --
errno ohne fuehrendes `e`, Signale ohne `sig` --, weil das dieselben Begriffe
sind, nur anders geschrieben. Es wird nichts frei kombiniert.
"""

from __future__ import annotations

from collections.abc import Iterator

from ..filters import passes
from .lexicon import JARGON

_ERRNO_PREFIX = "errno"
_SIGNAL_PREFIX = "SIG"


def _variants(label: str, origin: str) -> Iterator[tuple[str, str]]:
    yield label, origin
    if origin.startswith(_ERRNO_PREFIX) and label.startswith("e") and len(label) >= 6:
        yield label[1:], f"{origin} (Kurzform ohne e)"
    if label.startswith("sig") and len(label) >= 7:
        yield label[3:], f"{origin} (ohne sig-Praefix)"


def generate() -> Iterator[tuple[str, str]]:
    """Liefert (label, herkunft) fuer alles, was die harten Kriterien besteht."""
    seen: set[str] = set()
    for label, origin in JARGON:
        for cand, note in _variants(label.lower(), origin):
            if cand in seen:
                continue
            seen.add(cand)
            if passes(cand):
                yield cand, note


def rejected() -> list[tuple[str, str]]:
    """Diagnose: welche Fachbegriffe an welchem harten Kriterium scheitern."""
    from ..filters import check

    out = []
    for label, origin in JARGON:
        r = check(label.lower())
        if r:
            out.append((label, f"{origin} -> {r}"))
    return out
