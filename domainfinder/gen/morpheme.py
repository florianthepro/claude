"""Quelle B -- semantische Komposita.

Es werden keine Baukasten-Kreuzprodukte erzeugt. Zwei Morpheme duerfen nur dann
verschmelzen, wenn die Fuge unsichtbar wird:

  1. Ueberlappung -- Ende von A und Anfang von B teilen sich mindestens zwei
     Buchstaben, die im Ergebnis nur einmal stehen (port + tainer).
  2. Silbenblende -- Kopfsilbe von A und Schlusssilbe von B, beide also
     beschnitten.

Reines Aneinanderhaengen zweier vollstaendiger Morpheme wird verworfen: genau
das sieht nach Praefix mal Suffix aus.
"""

from __future__ import annotations

import re
from collections.abc import Iterator

from ..filters import VOWELS, passes
from .lexicon import MORPHEMES_IT, MORPHEMES_MATTER, MORPHEMES_SKY

POOL = tuple(sorted(set(MORPHEMES_MATTER) | set(MORPHEMES_IT) | set(MORPHEMES_SKY)))
_FULL = frozenset(POOL)


def syllables(word: str) -> list[str]:
    """Grobe Silbentrennung: schneide vor dem Konsonanten, der einen Vokal traegt."""
    parts, cur = [], ""
    i = 0
    while i < len(word):
        cur += word[i]
        if word[i] in VOWELS:
            j = i + 1
            cons = ""
            while j < len(word) and word[j] not in VOWELS:
                cons += word[j]
                j += 1
            if j >= len(word):
                cur += cons
                break
            # Ein Konsonant wandert zur naechsten Silbe, mehrere werden geteilt.
            keep = cons[:-1] if len(cons) > 1 else ""
            cur += keep
            parts.append(cur)
            cur = ""
            i = i + 1 + len(keep)
            continue
        i += 1
    if cur:
        parts.append(cur)
    return [p for p in parts if p]


def _overlap_merges(a: str, b: str) -> Iterator[str]:
    for k in (4, 3, 2):
        if len(a) > k and len(b) > k and a[-k:] == b[:k]:
            yield a + b[k:]
            break


def _blends(a: str, b: str) -> Iterator[str]:
    """Kopfsilbe(n) von A plus Schlusssilbe(n) von B, beide beschnitten."""
    sa, sb = syllables(a), syllables(b)
    if not sa or not sb:
        return
    for na in (1, 2):
        head = "".join(sa[:na])
        if not head or head == a:
            continue
        for nb in (1, 2):
            tail = "".join(sb[-nb:])
            if not tail or tail == b:
                continue
            cand = head + tail
            if 4 <= len(cand) <= 10:
                yield cand
            # Fuge zusaetzlich glaetten, wenn Kopf und Schwanz einen Buchstaben teilen.
            if head[-1] == tail[0] and len(cand) > 4:
                yield head + tail[1:]


def _is_plain_concat(label: str) -> bool:
    """True, wenn sich das Label als zwei vollstaendige Morpheme lesen laesst."""
    return any(
        label[:i] in _FULL and label[i:] in _FULL
        for i in range(3, len(label) - 2)
    )


def generate(max_len: int = 10) -> Iterator[tuple[str, str]]:
    """Liefert (label, herkunft)."""
    seen: set[str] = set()
    for a in POOL:
        for b in POOL:
            if a == b:
                continue
            for cand in (*_overlap_merges(a, b), *_blends(a, b)):
                if cand in seen or len(cand) > max_len:
                    continue
                seen.add(cand)
                if _is_plain_concat(cand) or not passes(cand):
                    continue
                # Ein Blend, der exakt eines der Ausgangsmorpheme ist, bringt nichts.
                if cand in _FULL:
                    continue
                # Mindestens drei Buchstaben Substanz aus jedem Teil.
                if not (re.match(rf"^{re.escape(a[:3])}", cand) or cand.endswith(b[-3:])):
                    continue
                yield cand, f"{a}+{b}"
