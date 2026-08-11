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
PER_PREFIX = 5     # gleicher Wortanfang
PER_RHYME = 5      # gleicher Wortausgang
PER_SKELETON = 3   # gleiches Konsonantengeruest

# Bei einem Fuenfzeichner sind vier Zeichen fast das ganze Wort -- ein Fenster
# dieser Groesse trennt `gukra` und `gukre` und deckelt damit nichts. Das Fenster
# muss zur Wortlaenge passen.
def _window(label: str) -> int:
    # Bei fuenf Zeichen war das Fenster frueher 2 -- damit fielen saemtliche
    # Woerter auf -er in eine einzige Familie und die Grenze schnitt 69614
    # Kandidaten auf 430. Drei Zeichen trennt -der, -ter, -ker voneinander.
    if len(label) >= 7:
        return 4
    return 3 if len(label) >= 5 else 2


def skeleton(label: str) -> str:
    """Konsonantengeruest. `gukra`, `gikre` und `gakre` teilen sich `gkr`."""
    return "".join(ch for ch in label if ch not in "aeiou")


def morpheme_family(label: str) -> str | None:
    for m in _FAMILY_MORPHS:
        if m in label:
            return m
    return None


def cap(items: Iterable[tuple[float, str, object]], *, per_morpheme: int = PER_MORPHEME,
        per_prefix: int = PER_PREFIX, per_rhyme: int = PER_RHYME,
        per_skeleton: int = PER_SKELETON,
        limit: int | None = None) -> Iterator[tuple[float, str, object]]:
    """Erwartet nach Score absteigend sortierte (score, label, payload)-Tupel."""
    seen_m: dict[str, int] = {}
    seen_p: dict[str, int] = {}
    seen_r: dict[str, int] = {}
    seen_s: dict[str, int] = {}
    taken = 0
    for scr, label, payload in items:
        if limit is not None and taken >= limit:
            return
        fam = morpheme_family(label)
        w = _window(label)
        pre, rhy, ske = label[:w], label[-w:], skeleton(label)
        if fam and seen_m.get(fam, 0) >= per_morpheme:
            continue
        if seen_p.get(pre, 0) >= per_prefix:
            continue
        if seen_r.get(rhy, 0) >= per_rhyme:
            continue
        if seen_s.get(ske, 0) >= per_skeleton:
            continue
        if fam:
            seen_m[fam] = seen_m.get(fam, 0) + 1
        seen_p[pre] = seen_p.get(pre, 0) + 1
        seen_r[rhy] = seen_r.get(rhy, 0) + 1
        seen_s[ske] = seen_s.get(ske, 0) + 1
        taken += 1
        yield scr, label, payload
