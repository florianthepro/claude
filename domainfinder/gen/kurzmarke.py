"""Quelle K -- Kunstwoerter der Okta-Klasse, vier oder fuenf Zeichen.

Okta, Orbis, Drata, Tines, Veeam, Zerto: kurze Kunstwoerter, die serioes
klingen, ohne etwas zu bedeuten. Zwei Dinge, die die bisherigen Quellen daran
gehindert haben, so etwas zu finden:

  * Sie bestanden auf genau fuenf Zeichen. `Okta` hat vier.
  * Sie bestraften den Vokalanlaut. `Okta`, `Orbis` und `Ordo` fangen mit
    einem an, und gerade das gibt ihnen den lateinischen Ernst.

Gebaut wird aus einem lateinischen oder griechischen Fachstamm und einer
nuechternen Endung. Der Stamm macht den Namen ableitbar -- `nodus` ist der
Knoten, `rete` das Netz, `signum` das Zeichen --, die Endung macht ihn zur
Marke statt zum Wort.

    nod + us  -> nodus      Knoten, also node
    ret + ia  -> retia      Netz
    ord + an  -> ordan      Ordnung

Keine Bildwoerter, keine Fachkuerzel, keine Komposita. Nur Stamm und Endung.
"""

from __future__ import annotations

from collections.abc import Iterator

from ..filters import VOWELS, check

# Stamm -> woran der Leser ihn festmachen kann. Lateinisch oder griechisch,
# jeder Stamm traegt eine Bedeutung, die sich auf IT beziehen laesst.
STAEMME: dict[str, str] = {
    "nod": "nodus, der Knoten -- node",
    "ret": "rete, das Netz",
    "dat": "datum, das Gegebene -- Daten",
    "sig": "signum, das Zeichen -- Signal",
    "ord": "ordo, die Ordnung",
    "reg": "regula, die Regel",
    "norm": "norma, das Richtmass",
    "form": "forma, die Gestalt",
    "mod": "modus, die Art und Weise",
    "stat": "status, der Zustand",
    "tens": "tensio, die Spannung",
    "sol": "solidus, fest",
    "lum": "lumen, das Licht",
    "pol": "polus, der Pol",
    "apt": "aptus, passend",
    "opt": "optimus, das Beste",
    "met": "metrum, das Mass",
    "ter": "terra, die Erde",
    "tri": "tres, drei",
    "uni": "unus, eins",
    "kern": "der Kern -- kernel",
    "tekt": "tekton, der Baumeister -- Architektur",
    "log": "logos, das Wort und das Verhaeltnis -- Log",
    "num": "numerus, die Zahl",
    "rat": "ratio, das Verhaeltnis",
    "firm": "firmus, fest",
    "fort": "fortis, stark",
    "ark": "arcus, der Bogen",
    "orb": "orbis, der Kreis",
    "akt": "actus, die Handlung",
    "grad": "gradus, der Schritt",
    "port": "porta, das Tor",
    "kap": "caput, der Kopf",
    "spek": "spectrum, das Bild",
    "plan": "planus, eben",
    "sekt": "sectio, der Schnitt",
    "trakt": "tractus, der Zug",
    "punkt": "punctum, der Punkt",
    "index": "index, das Verzeichnis",
    "kod": "codex, das Verzeichnis",
    "prim": "primus, der Erste",
    "sekur": "securus, sicher",
    "integ": "integer, unversehrt",
}

# Nuechterne Endungen. Kein -io, kein -ola: die klingen nach Produktlaunch.
ENDUNGEN: tuple[str, ...] = (
    "us", "is", "um", "or", "ar", "an", "en", "on", "at", "it", "ur", "el",
    "a", "o", "e", "i", "as", "os", "im", "ia", "ea",
)

MIN_LEN, MAX_LEN = 4, 5


def generate() -> Iterator[tuple[str, str]]:
    """Liefert (label, herkunft) fuer alle gueltigen Vier- und Fuenfzeichner."""
    seen: set[str] = set()
    for stamm, herkunft in STAEMME.items():
        for endung in ENDUNGEN:
            label = stamm + endung
            if not (MIN_LEN <= len(label) <= MAX_LEN) or label in seen:
                continue
            seen.add(label)
            # Die Fuge muss auf einem Konsonanten sitzen, sonst entstehen
            # Vokalhaufen wie `tri|ea`.
            if stamm[-1] in VOWELS and endung[0] in VOWELS:
                continue
            if check(label) is None:
                yield label, herkunft
