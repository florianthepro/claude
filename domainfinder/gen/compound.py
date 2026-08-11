"""Quelle F und G -- Komposita aus echten Woertern.

Die Quellen A bis E erzeugen Silben. Sie treffen die Form von Figma und Gusto,
aber sie bleiben erfunden, und genau so klingen sie auch: ein bisschen fremd.
Vertraut wirkt ein Name, wenn beide Haelften Woerter sind, die der Hoerer schon
kennt -- so baut das Deutsche seit jeher Woerter.

Entscheidend ist, dass beide Haelften **aus derselben Sprache** kommen.
`forge+stern` ergibt `forgestern`, und das liest ein Deutscher als "for
gestern". `gate+stern`, `dome+stern`, `plate+stern` klingen aus demselben Grund
schief. Ein Mischmasch aus zwei Sprachen ist genau der fremde Klang, den
Komposita eigentlich vermeiden sollen -- deshalb wird hier nur innerhalb einer
Sprache kombiniert.

    berg + turm    -> bergturm      (deutsch)
    stone + gate   -> stonegate     (englisch)
    daten + strom  -> datenstrom    (deutsch, IT)
    node + link    -> nodelink      (englisch, IT)

Das ist bewusst das Gegenteil dessen, was Quelle B tut. Dort wird reines
Aneinanderhaengen verworfen, weil es bei erfundenen Marken nach Baukasten
aussieht. Hier ist die sichtbare Fuge kein Makel, sondern der Grund, warum der
Name vertraut klingt. Zwei Ziele, zwei Quellen.

Einzelne Woerter stehen nicht zur Wahl: die sind in `.com` vergeben.
"""

from __future__ import annotations

from collections.abc import Iterator

from ..filters import check

# --- Deutsch, Allgemeinwortschatz --------------------------------------------
# Konkrete Dinge: Landschaft, Bauwerk, Handwerk. Keine Abstrakta, kein Marketing.
DE_VORNE: tuple[str, ...] = (
    "berg", "feld", "gold", "mond", "nord", "sand", "stern", "strom", "tal",
    "torf", "traum", "sturm", "dorf", "fels", "firn", "flur", "forst", "glas",
    "granit", "halm", "hang", "helm", "herd", "horn", "korn", "kraft", "land",
    "laub", "luft", "nest", "rast", "raum", "rost", "span", "spur", "strand",
    "sumpf", "trog", "ton", "hafen", "turm", "brot", "punkt", "rand", "stab",
    "garten", "boden", "insel", "kanal", "kran", "lager", "markt", "mauer",
    "nadel", "ofen", "orden", "rahmen", "regal", "ring", "segel", "silber",
    "sprung", "stand", "steg", "stufe", "tafel", "takt", "halde", "grube",
)
DE_HINTEN: tuple[str, ...] = (
    "hafen", "turm", "hof", "haus", "berg", "feld", "garten", "boden", "insel",
    "kanal", "lager", "markt", "mauer", "pfad", "punkt", "rand", "ring",
    "segel", "stand", "steg", "stufe", "tafel", "tor", "tal", "ufer", "bogen",
    "kammer", "stube", "pforte", "strom", "stern", "grund", "grat", "kamm",
    "gipfel", "halde", "grube", "born", "rain", "steig", "warte",
)

# --- Englisch, Allgemeinwortschatz -------------------------------------------
EN_VORNE: tuple[str, ...] = (
    "stone", "harbor", "atlas", "pilot", "signal", "orbit", "timber", "marble",
    "hamlet", "bastion", "spire", "portal", "gate", "bolt", "beam", "plank",
    "board", "plate", "frame", "dome", "spade", "forge", "kiln", "moat",
    "anvil", "lantern", "mantel", "lintel", "rampart", "turret", "granite",
)
EN_HINTEN: tuple[str, ...] = (
    "stone", "gate", "post", "forge", "dome", "portal", "spire", "harbor",
    "board", "beam", "frame", "moat", "field", "hold", "mark", "span",
    "bolt", "plate", "point", "path", "gard", "ridge", "hall",
)

# --- Deutsch, IT --------------------------------------------------------------
IT_DE_VORNE: tuple[str, ...] = (
    "bit", "daten", "knoten", "log", "takt", "pfad", "paket", "kern", "stapel",
    "signal", "regel", "treiber", "sektor", "gitter", "raster", "puls",
    "flanke", "impuls", "makro", "modul", "sonde", "strom", "netto", "labor",
)
IT_DE_HINTEN: tuple[str, ...] = (
    "strom", "pfad", "feld", "lager", "punkt", "rand", "hof", "turm", "hafen",
    "kanal", "gitter", "raster", "regal", "stapel", "haus", "knoten", "kern",
    "takt", "puls", "flanke", "sonde", "spur", "bogen", "grund", "stand",
)

# --- Englisch, IT -------------------------------------------------------------
IT_EN_VORNE: tuple[str, ...] = (
    "node", "link", "grid", "port", "host", "stack", "trace", "route", "batch",
    "shard", "unit", "disk", "dump", "proto", "token", "hash", "bit", "data",
    "packet", "socket", "kernel", "buffer", "signal", "logic", "index",
)
IT_EN_HINTEN: tuple[str, ...] = (
    "node", "link", "grid", "port", "host", "gate", "post", "stone", "forge",
    "dome", "board", "path", "mark", "span", "point", "field", "hold", "stack",
)


def _paare(vorne: tuple[str, ...], hinten: tuple[str, ...], sprache: str,
           max_len: int) -> Iterator[tuple[str, str]]:
    seen: set[str] = set()
    for a in vorne:
        for b in hinten:
            if a == b or len(a) + len(b) > max_len:
                continue
            label = a + b
            if label in seen:
                continue
            seen.add(label)
            # Eine Haelfte darf nicht in der anderen stecken (portport, bitbit).
            if a in b or b in a:
                continue
            # `compound=True`: die Wortfuge ist bekannt, dort darf ein Cluster
            # stehen, den es innerhalb eines Morphems nie gaebe (daten|pfad).
            if check(label, compound=True) is None:
                yield label, f"{a}+{b} ({sprache})"


def generate(max_len: int = 10) -> Iterator[tuple[str, str]]:
    """Allgemeine Komposita, streng einsprachig."""
    seen: set[str] = set()
    for vorne, hinten, sprache in ((DE_VORNE, DE_HINTEN, "deutsch"),
                                   (EN_VORNE, EN_HINTEN, "englisch")):
        for label, herkunft in _paare(vorne, hinten, sprache, max_len):
            if label not in seen:
                seen.add(label)
                yield label, herkunft


def generate_it(max_len: int = 10) -> Iterator[tuple[str, str]]:
    """Komposita mit Fachbezug, ebenfalls streng einsprachig."""
    seen: set[str] = set()
    for vorne, hinten, sprache in ((IT_DE_VORNE, IT_DE_HINTEN, "deutsch"),
                                   (IT_DE_VORNE, DE_HINTEN, "deutsch"),
                                   (IT_EN_VORNE, IT_EN_HINTEN, "englisch"),
                                   (IT_EN_VORNE, EN_HINTEN, "englisch")):
        for label, herkunft in _paare(vorne, hinten, sprache, max_len):
            if label not in seen:
                seen.add(label)
                yield label, herkunft
