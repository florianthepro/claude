"""Quelle I -- Markennamen aus Bildwortschatz.

Alle bisherigen Quellen liefern entweder Fachbegriffe (`badsig`, `runpid`) oder
erfundene Silben (`fodma`, `gukra`). Ein Startup-Name ist beides nicht. Stripe,
Figma, Vanta, Grafana, Prisma, Twilio funktionieren nach einem dritten Muster:

    ein Bildwort, das jeder kennt, plus eine Markenendung.

    granit + a   -> granita
    orbit  + is  -> orbitis
    lumen  + ora -> lumenora

Das Bildwort traegt die Bedeutung, ohne fachlich zu sein -- Gestein, Licht,
Vogel, Handwerk, Navigation. Die Endung macht daraus einen Namen statt eines
Substantivs. Einzelne Bildwoerter stehen nicht zur Wahl: `granit.com`,
`orbit.com` und `prisma.com` sind seit Jahrzehnten vergeben.
"""

from __future__ import annotations

from collections.abc import Iterator

from ..filters import VOWELS, passes

# Bildwortschatz. Konkret und anschaulich, aber nicht fachlich -- das ist der
# Unterschied zu Quelle H. Nur Woerter, die das diktiersichere Alphabet zulaesst.
STAEMME: dict[str, str] = {
    # Gestein und Material
    "granit": "Granit", "basalt": "Basalt", "slate": "Schiefer", "flint": "Feuerstein",
    "opal": "Opal", "agat": "Achat", "halit": "Steinsalz", "spat": "Spat",
    "talk": "Talk", "magma": "Magma", "sinter": "Sinter", "geode": "Geode",
    "druse": "Druse", "marmor": "Marmor", "amber": "Bernstein", "kobalt": "Kobalt",
    # Licht und Himmel
    "lumen": "Lumen", "luna": "Mond", "solar": "Sonnen-", "polar": "Polar-",
    "orbit": "Orbit", "nimbus": "Regenwolke", "aurora": "Polarlicht",
    "boreal": "noerdlich", "helio": "Sonne", "komet": "Komet", "meteor": "Meteor",
    "planet": "Planet", "rigel": "Stern im Orion", "atlas": "Atlas",
    "astral": "gestirn-", "prisma": "Prisma", "lens": "Linse",
    # Vogel und Tier
    "fern": "Farn", "aspen": "Espe", "alder": "Erle", "heron": "Reiher",
    "tern": "Seeschwalbe", "merlin": "Merlin, ein Falke", "kestrel": "Turmfalke",
    "marten": "Marder", "bison": "Bison", "ibis": "Ibis", "egret": "Silberreiher",
    "dunlin": "Alpenstrandlaeufer", "snipe": "Schnepfe", "petrel": "Sturmvogel",
    "fulmar": "Eissturmvogel", "gannet": "Basstoelpel", "lemur": "Lemur",
    "tapir": "Tapir", "stoat": "Hermelin", "auk": "Alk", "skua": "Raubmoewe",
    # Handwerk und Struktur
    "forge": "Schmiede", "kiln": "Brennofen", "spindel": "Spindel", "mast": "Mast",
    "helm": "Ruder", "tenon": "Zapfen", "mortise": "Stemmloch", "gasket": "Dichtung",
    "flange": "Flansch", "anker": "Anker", "riegel": "Riegel", "hebel": "Hebel",
    # Navigation und Bewegung
    "kurs": "Kurs", "bahn": "Bahn", "pfad": "Pfad", "port": "Hafen",
    "hafen": "Hafen", "lot": "Senklot", "drift": "Drift", "strom": "Strom",
    "flut": "Flut", "monsun": "Monsun", "passat": "Passat", "orkan": "Orkan",
    "delta": "Delta", "fjord": "Fjord", "atol": "Atoll", "riff": "Riff",
}

# Markenendungen. Was aus einem Substantiv einen Namen macht.
ENDUNGEN: tuple[str, ...] = (
    "a", "o", "e", "is", "us", "um", "on", "an", "en", "el", "ar", "or", "ur",
    "ia", "io", "ora", "era", "ika", "ana", "ino", "eta", "ita", "ola", "ela",
    "is", "os", "as", "ade", "ide", "ine", "ina", "ono", "aro", "ero",
)

MIN_LEN, MAX_LEN = 5, 9


def _stumpf(stamm: str) -> Iterator[str]:
    """Der Stamm, ganz und um hoechstens einen Buchstaben gekuerzt.

    Zwei Buchstaben waren zu viel: aus `gasket` wurde `gask`, daraus `gaskio`,
    und die Dichtung war nicht mehr zu erkennen. Ein Markenname lebt davon,
    dass man das Bildwort noch sieht.
    """
    yield stamm
    if len(stamm) - 1 >= 4:
        yield stamm[:-1]


def generate() -> Iterator[tuple[str, str]]:
    """Liefert (label, herkunft)."""
    seen: set[str] = set()
    for stamm, bedeutung in STAEMME.items():
        for basis in _stumpf(stamm):
            for endung in ENDUNGEN:
                label = basis + endung
                if not (MIN_LEN <= len(label) <= MAX_LEN) or label in seen:
                    continue
                seen.add(label)
                # Der Stamm muss noch erkennbar sein: mindestens drei
                # Buchstaben, und der Anfang darf nicht verschwinden.
                if not label.startswith(stamm[:3]):
                    continue
                # Die Fuge muss auf einem Konsonanten sitzen. Sonst treffen
                # zwei Vokale aufeinander und es entsteht `stro|era`,
                # `geo|ina`, `nimbu|io` -- Vokalhaufen, die niemand liest.
                if basis[-1] in VOWELS and endung[0] in VOWELS:
                    continue
                if passes(label):
                    yield label, f"{bedeutung} + Endung -{endung}"
