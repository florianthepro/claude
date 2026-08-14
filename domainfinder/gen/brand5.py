"""Quelle E -- die Figma/Gusto/Vanta-Form, vollstaendig aufgezaehlt.

Quelle A zaehlt alle sprechbaren Formen auf und ueberlaesst der Bewertung die
Auswahl. Fuer einen kurzen Markennamen ist das der falsche Weg herum: die Form
steht vorher fest, und der Raum ist klein genug, um ihn ganz zu pruefen statt
ihn zu bewerten.

    zwei Silben, Binnencluster aus der PRIME-Menge, offener Auslaut

    figma   CVCCV  gm  a
    gusto   CVCCV  st  o
    vanta   CVCCV  nt  a
    canva   CVCCV  nv  a

Rund 15 000 Rohformen, nach den harten Kriterien deutlich weniger. Das passt
vollstaendig durch den Trichter -- keine Vorauswahl, kein blinder Fleck.
"""

from __future__ import annotations

from collections.abc import Iterator

from ..filters import CONSONANTS, LEGAL_ONSET_CLUSTERS, PRIME_MEDIAL, passes

# Das Alphabet kommt aus den harten Kriterien, nicht aus einer zweiten Liste.
# Es hier zu wiederholen war der Grund, warum `j` unsichtbar blieb: filters.py
# hat es zugelassen, die Generatoren kannten es nicht und konnten kein einziges
# Label damit erzeugen. `q` faellt heraus, weil es nur als `qu` im Wortanlaut
# zulaessig ist und keines der Muster hier diese Stellung modelliert -- dafuer
# gibt es Quelle M.
CONS = "".join(sorted(CONSONANTS - set("q")))
VOWS = "aeiou"
# Auslaut, der einen Markennamen traegt. -u klingt nach Versehen und faellt weg.
FINALS = "aoie"
ONSET_CLUSTERS = tuple(sorted(c for c in LEGAL_ONSET_CLUSTERS
                              if len(c) == 2 and set(c) <= set(CONS)))
MEDIAL = tuple(sorted(PRIME_MEDIAL))


def generate() -> Iterator[tuple[str, str]]:
    """Liefert (label, herkunft) fuer die vollstaendige Aufzaehlung der Form."""
    seen: set[str] = set()

    for c1 in CONS:                      # CVCCV -- figma, gusto, vanta
        for v1 in VOWS:
            for cc in MEDIAL:
                for v2 in FINALS:
                    label = c1 + v1 + cc + v2
                    if label not in seen:
                        seen.add(label)
                        if passes(label):
                            yield label, f"CVCCV, Binnencluster {cc}"

    for cc in ONSET_CLUSTERS:            # CCVCV -- brela, stiro
        for v1 in VOWS:
            for c2 in CONS:
                for v2 in FINALS:
                    label = cc + v1 + c2 + v2
                    if label not in seen:
                        seen.add(label)
                        if passes(label):
                            yield label, f"CCVCV, Anlautcluster {cc}"
