"""Quelle M -- vollstaendige Aufzaehlung ohne Musterschablone.

Alle anderen Quellen gehen ueber feste Muster: CVCVC, CVCCV, CCVCV, Stamm plus
Endung, Wort plus Wort. Muster sind bequem, aber sie sind selbst ein Filter --
und zwar der schaerfste im ganzen Werkzeug. Ein Audit gegen die Aufgabenstellung
hat gezeigt, was keines der Muster erzeugen konnte, obwohl es die harten
Kriterien besteht:

    orbis, album, atlas   vokalanlautend -- jedes Muster begann mit einem Konsonanten
    janto, sonja, marja   mit j          -- die Generatoren trugen ein eigenes,
                                            aelteres Alphabet fest im Code
    brant, grund, stark   geschlossene Silbe -- kein Muster endete auf einem Cluster
    quiro, quado          mit q
    nadia, kaura          zwei Vokale nebeneinander

Deshalb hier ohne Muster: jede Kombination der erlaubten Buchstaben in der
gewuenschten Laenge, durch `filters.check()` und `schoenheit.rank()`. Bei fuenf
Zeichen sind das 20^5 = 3,2 Millionen Rohformen -- gross genug, dass es sich
lohnt, und klein genug, dass es in unter einer Minute durchlaeuft.

Das Alphabet wird nicht wiederholt, sondern aus `filters` gelesen. Sonst
entsteht wieder die Drift, die `j` jahrelang unsichtbar gemacht hat.
"""

from __future__ import annotations

import itertools
from collections.abc import Iterator

from ..filters import CONSONANTS, VOWELS, check
from ..schoenheit import rank

# Genau das Alphabet der harten Kriterien -- keine zweite Quelle der Wahrheit.
ALPHABET = "".join(sorted(VOWELS | CONSONANTS))

MIN_LEN, MAX_LEN = 4, 6
SCHWELLE = 1.0          # unter dieser Klangnote lohnt keine Netzabfrage


def generate(laenge: int = 5, *, schwelle: float = SCHWELLE) -> Iterator[tuple[str, str]]:
    """Liefert (label, herkunft) fuer jede gueltige Kombination der Laenge.

    `herkunft` nennt die Klangnote, damit in der CSV nachvollziehbar bleibt,
    warum ein Name in der Liste steht.
    """
    if not (MIN_LEN <= laenge <= MAX_LEN):
        raise ValueError(f"Laenge {laenge} liegt ausserhalb von {MIN_LEN}..{MAX_LEN}")
    for tupel in itertools.product(ALPHABET, repeat=laenge):
        label = "".join(tupel)
        note = rank(label)
        if note < schwelle:
            continue
        if check(label) is None:
            yield label, f"vollstaendige Aufzaehlung, Klangnote {note:.1f}"
