"""Vielfaltsgrenze bei der Auswahl.

Ein reiner Score-Schnitt liefert Monokulturen: `kinode, ginode, binode, tinode`
oder `baser, basen, baset`. Genau das ist der Baukasten-Eindruck, der die Liste
als generiert entlarvt. Deshalb wird pro Familie gedeckelt -- nach gemeinsamem
Morphem, gemeinsamem Anfang und gemeinsamem Reim.
"""

from __future__ import annotations

from collections.abc import Iterable, Iterator

from .gen.lexicon import MORPHEMES_IT, MORPHEMES_MATTER

_FAMILY_MORPHS = tuple(sorted(
    (m for m in set(MORPHEMES_IT) | set(MORPHEMES_MATTER) if len(m) >= 4),
    key=len, reverse=True,
))

PER_MORPHEME = 3   # gleiches tragendes Morphem
PER_PREFIX = 5     # gleicher Wortanfang (4 Zeichen)
PER_RHYME = 5      # gleicher Wortausgang (4 Zeichen)


def morpheme_family(label: str) -> str | None:
    for m in _FAMILY_MORPHS:
        if m in label:
            return m
    return None


def cap(items: Iterable[tuple[float, str, object]], *, per_morpheme: int = PER_MORPHEME,
        per_prefix: int = PER_PREFIX, per_rhyme: int = PER_RHYME,
        limit: int | None = None) -> Iterator[tuple[float, str, object]]:
    """Erwartet nach Score absteigend sortierte (score, label, payload)-Tupel."""
    seen_m: dict[str, int] = {}
    seen_p: dict[str, int] = {}
    seen_r: dict[str, int] = {}
    taken = 0
    for scr, label, payload in items:
        if limit is not None and taken >= limit:
            return
        fam = morpheme_family(label)
        pre, rhy = label[:4], label[-4:]
        if fam and seen_m.get(fam, 0) >= per_morpheme:
            continue
        if seen_p.get(pre, 0) >= per_prefix:
            continue
        if seen_r.get(rhy, 0) >= per_rhyme:
            continue
        if fam:
            seen_m[fam] = seen_m.get(fam, 0) + 1
        seen_p[pre] = seen_p.get(pre, 0) + 1
        seen_r[rhy] = seen_r.get(rhy, 0) + 1
        taken += 1
        yield scr, label, payload
