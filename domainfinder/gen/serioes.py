"""Quelle J -- serioese Namen aus kaufmaennischem Wortschatz.

Quelle H liefert Fachjargon (`badsig`), Quelle I Bildwoerter mit Markenendung
(`pfadio`). Beides klingt nicht serioes: das eine nach Innenwitz, das andere
nach Produktlaunch. Was auf einem Briefkopf traegt, kommt aus einer dritten
Ecke -- dem kaufmaennischen und behoerdlichen Wortschatz:

    Kontor, Tresor, Depot, Lager, Akte, Fundus, Register, Kataster.

Diese Woerter sind seit Jahrhunderten im Gebrauch, jeder kennt sie, und keines
klingt nach Startup. Zusammen mit einem IT-Wort ergibt das einen Namen, der
gleichzeitig sachlich, fachlich und muendlich eindeutig ist.

    log + kontor  -> logkontor
    kern + depot  -> kerndepot
    daten + akte  -> datenakte

Einsprachigkeit ist Pflicht, wie bei Quelle F: `nodekontor` mischt Englisch und
Deutsch und klingt deshalb schief. Zugelassen sind nur IT-Woerter, die im
Deutschen wirklich gebraucht werden -- Log, Bit, Daten, Kern, Host, Port.
"""

from __future__ import annotations

from collections.abc import Iterator

from ..filters import check

# IT-Woerter, die in deutscher Fachsprache selbstverstaendlich sind.
# `node`, `grid`, `link` und `data` fehlen mit Absicht: dafuer sagt man im
# Deutschen Knoten, Gitter, Verweis und Daten.
IT_DEUTSCH: dict[str, str] = {
    "bit": "Bit", "daten": "Daten", "log": "Log", "kern": "Kern",
    "host": "Host", "port": "Port", "takt": "Takt", "puls": "Puls",
    "signal": "Signal", "paket": "Paket", "kanal": "Kanal", "modul": "Modul",
}

# Kaufmaennischer und behoerdlicher Wortschatz. Alt, sachlich, jedem gelaeufig.
SERIOES: dict[str, str] = {
    "kontor": "Handelskontor", "tresor": "Tresor", "depot": "Depot",
    "lager": "Lager", "akte": "Akte", "fundus": "Fundus",
    "register": "Register", "kataster": "Kataster", "mandat": "Mandat",
    "statut": "Statut", "patent": "Patent", "katalog": "Katalog",
    "inventar": "Inventar", "audit": "Audit", "siegel": "Siegel",
    "urkunde": "Urkunde", "ablage": "Ablage", "referat": "Referat",
    "gremium": "Gremium", "agentur": "Agentur", "kammer": "Kammer",
    "hort": "Hort", "band": "Band", "norm": "Norm", "stand": "Stand",
    "amt": "Amt", "dienst": "Dienst", "gilde": "Gilde", "pult": "Pult",
}

MIN_LEN, MAX_LEN = 5, 10


def generate() -> Iterator[tuple[str, str]]:
    """Liefert (label, herkunft) fuer alle gueltigen Verbindungen."""
    seen: set[str] = set()
    for it, it_bed in IT_DEUTSCH.items():
        for wort, wort_bed in SERIOES.items():
            label = it + wort
            if not (MIN_LEN <= len(label) <= MAX_LEN) or label in seen:
                continue
            seen.add(label)
            if it in wort or wort in it:
                continue
            # Die Wortfuge ist bekannt, dort darf ein Cluster stehen, den es
            # innerhalb eines Morphems nie gaebe.
            if check(label, compound=True) is None:
                yield label, f"{it_bed} + {wort_bed}"
