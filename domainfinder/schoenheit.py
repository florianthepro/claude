"""Vierte Rangordnung: Klang.

`scoring.py` fragt, ob ein Name nach Infrastruktur klingt (Proxmox, Traefik).
`startup.py` fragt, ob er nach Produkt klingt (Figma, Gusto). `vertraut.py`
fragt, ob er klingt wie ein Wort, das es geben koennte. Keine der drei fragt,
ob er *schoen* ist -- und genau danach war zuletzt gefragt: ein Fantasiewort,
hoechstens fuenf Zeichen, serioes, klangsicher, in erster Linie schoen.

Was einen kurzen Kunstnamen tragen laesst, laesst sich an den Vorbildern
ablesen -- Okta, Vanta, Prisma, Sentry, Solana, Kadena, Tundra, Kestra:

  Vokalgefaelle   Zwei verschiedene Vokale, und der zweite faellt gegen den
                  ersten ab. `marlin` a-i, `kestra` e-a, `tundra` u-a. Dreimal
                  derselbe Vokal ist Monotonie -- daran ist eine fruehere
                  Fassung gescheitert, die `tusura` und `lulula` nach oben
                  sortiert hat, weil sie nur Sonoritaet und Auslaut gewichtete.
  Kontrast        Ein Liquid oder Nasal gegen einen Plosiv oder Reibelaut. Ohne
                  Liquid geht es (Okta hat keins), aber es kostet.
  Gelenk          Sitzt in der Mitte ein Konsonantenpaar, entscheidet es ueber
                  Kante oder Geholper. `nt`, `rd`, `st` tragen, `fd` nicht.
  Auslaut         Offen auf -a und -o, nuechtern auf -is, -or, -us, -on, fest
                  auf -ant, -ark, -ist. Alle drei Klassen tragen; was nicht in
                  der Tabelle steht, ist kein Markenauslaut.
  Kein Hiatus     Zwei Vokale nebeneinander nur als echter Diphthong (ei, eu,
                  au) oder als Auslaut -ia/-io. Diese Regel fehlte lange, weil
                  die Muster-Generatoren nie zwei Vokale nebeneinandergestellt
                  haben; die vollstaendige Aufzaehlung hat dann `blaoa` und
                  `eodma` mit Hoechstnoten nach oben gespuelt.

Der Rueckgabewert liegt wie bei den anderen Rangordnungen auf 0..100.
"""

from __future__ import annotations

from collections import Counter

from .filters import PRIME_MEDIAL, VOWELS

LIQUIDE = frozenset("lmnr")
PLOSIVE = frozenset("bdgkpt")
REIBELAUTE = frozenset("fhsjq")
# Konsonant + j eroeffnet die zweite Silbe und traegt wie ein Primaergelenk:
# Sonja, Marja, Katja, Ronja.
J_GELENKE = frozenset({"nj", "rj", "lj", "tj"})
DIPHTHONGE = frozenset({"ei", "eu", "au"})
HIATUS_AUSLAUT = frozenset({"ia", "io"})

# Auslaute, die einen Markennamen tragen. Der Wert ist reine Klangnote.
AUSLAUT_OFFEN: dict[str, float] = {
    "a": 1.00, "o": 0.92, "e": 0.70, "i": 0.66, "ia": 0.92, "io": 0.86,
    "is": 0.98, "on": 0.96, "or": 0.96, "us": 0.94, "er": 0.94, "in": 0.92,
    "el": 0.92, "um": 0.90, "an": 0.90, "ar": 0.90, "en": 0.88, "om": 0.84,
    "as": 0.84, "os": 0.84, "al": 0.84, "ir": 0.84, "im": 0.82, "il": 0.82,
    "ol": 0.82, "am": 0.80, "at": 0.80, "it": 0.80, "ur": 0.76, "ik": 0.76,
    "ak": 0.74, "ok": 0.74, "ad": 0.70, "id": 0.70, "od": 0.70, "ap": 0.70,
    "op": 0.70,
}
# Geschlossene Auslaute -- Stark, Print, Brant, Orbit. Fest und sachlich.
# Sie fehlten lange ganz: das Mass verlangte einen Auslaut aus der offenen
# Tabelle, und einsilbige Namen bekamen deshalb Note 0, obwohl sie alle harten
# Kriterien bestehen.
AUSLAUT_GESCHLOSSEN: dict[str, float] = {
    "ant": 0.96, "ent": 0.94, "int": 0.92, "ont": 0.90, "unt": 0.84,
    "and": 0.94, "end": 0.92, "ind": 0.92, "ond": 0.88, "und": 0.88,
    "art": 0.94, "ort": 0.94, "ert": 0.92, "irt": 0.86, "urt": 0.80,
    "ast": 0.94, "est": 0.94, "ist": 0.96, "ost": 0.92, "ust": 0.86,
    "ark": 0.94, "erk": 0.88, "irk": 0.86, "ork": 0.90, "urk": 0.80,
    "alt": 0.92, "elt": 0.90, "ilt": 0.86, "olt": 0.88, "ult": 0.84,
    "ald": 0.90, "eld": 0.90, "ild": 0.88, "old": 0.92, "uld": 0.82,
    "ank": 0.92, "enk": 0.86, "ink": 0.90, "onk": 0.84, "unk": 0.82,
    "ang": 0.92, "eng": 0.88, "ing": 0.90, "ong": 0.86, "ung": 0.84,
    "arg": 0.86, "erg": 0.90, "org": 0.88, "urg": 0.84,
    "arm": 0.86, "erm": 0.86, "orm": 0.90, "urm": 0.80,
    "arn": 0.86, "ern": 0.90, "orn": 0.90, "irn": 0.82, "urn": 0.84,
    "alm": 0.86, "elm": 0.88, "olm": 0.86, "ulm": 0.80,
    "amp": 0.84, "imp": 0.84, "omp": 0.84, "ump": 0.78,
    "orb": 0.90, "erb": 0.84, "arb": 0.84, "urb": 0.80,
    "opf": 0.84, "apf": 0.80, "orf": 0.82, "urf": 0.80,
}
AUSLAUT = {**AUSLAUT_OFFEN, **AUSLAUT_GESCHLOSSEN}

# Vokalfolgen, wie sie in tragenden Marken vorkommen. Der erste Vokal traegt
# die Betonung, der zweite soll abfallen, nicht wiederholen.
VOKALPAAR: dict[tuple[str, str], float] = {
    ("a", "i"): 1.00, ("a", "o"): 0.96, ("a", "e"): 0.94, ("a", "u"): 0.86,
    ("e", "a"): 1.00, ("e", "o"): 0.94, ("e", "i"): 0.94, ("e", "u"): 0.88,
    ("i", "a"): 0.98, ("i", "o"): 0.94, ("i", "e"): 0.84, ("i", "u"): 0.76,
    ("o", "a"): 1.00, ("o", "e"): 0.94, ("o", "i"): 0.94, ("o", "u"): 0.78,
    ("u", "a"): 0.96, ("u", "i"): 0.92, ("u", "o"): 0.88, ("u", "e"): 0.86,
    ("a", "a"): 0.70, ("o", "o"): 0.56, ("e", "e"): 0.48, ("i", "i"): 0.48,
    ("u", "u"): 0.46,
}
WEIGHTS = {"auslaut": 0.29, "vokal": 0.30, "kontrast": 0.23, "gelenk": 0.18}
EINSILBIG = 0.92        # kein Vokalgefaelle vorhanden, also weder Lob noch Tadel
OHNE_LIQUID = 0.86      # Okta hat keins und traegt trotzdem


def _kern(label: str) -> str:
    """Das `u` von `qu` gehoert zum Konsonanten, nicht zum Vokalgeruest.

    Nur das `u` faellt weg -- das `q` bleibt und zaehlt als Konsonant. Es ganz
    abzuschneiden hat `quiro` die Note 0 gegeben, weil danach nur noch ein
    einziger Konsonant uebrig war.
    """
    return label[0] + label[2:] if label.startswith("qu") else label


def hiatus_ok(label: str) -> bool:
    """True, wenn keine unerlaubte Vokalhaeufung vorkommt."""
    i = 2 if label.startswith("qu") else 0
    while i < len(label) - 1:
        if label[i] in VOWELS and label[i + 1] in VOWELS:
            paar = label[i:i + 2]
            folgt_vokal = i + 2 < len(label) and label[i + 2] in VOWELS
            if paar in DIPHTHONGE and not folgt_vokal:
                i += 2
                continue
            if paar in HIATUS_AUSLAUT and i + 2 == len(label):
                i += 2
                continue
            return False
        i += 1
    return True


def auslaut_wert(label: str) -> float:
    """0.0 heisst: kein Markenauslaut."""
    for endung in sorted(AUSLAUT, key=len, reverse=True):
        if label.endswith(endung):
            return AUSLAUT[endung]
    return 0.0


def breakdown(label: str) -> dict[str, float]:
    """Die Einzelnoten. `total` ist 0, wenn ein Ausschluss greift."""
    label = label.lower()
    leer = {**{k: 0.0 for k in WEIGHTS}, "total": 0.0}
    if not hiatus_ok(label):
        return leer
    a = auslaut_wert(label)
    if a == 0.0:
        return leer

    kern = _kern(label)
    vokale = [c for c in kern if c in VOWELS]
    konsonanten = [c for c in kern if c not in VOWELS]
    if len(konsonanten) < 2 or not vokale:
        return leer
    # Ein Konsonant zweimal klingt nach Kinderwort.
    if len(set(konsonanten)) < len(konsonanten):
        return leer
    # Dreimal derselbe Vokal ist Monotonie und faellt raus -- daran ist die
    # Fassung gescheitert, die `tusura` und `lulula` nach oben sortiert hat.
    # Zweimal ist kein Ausschluss: Vanta, Canva und Manta leben davon. Das
    # regelt die Paartabelle, die (a,a) mit 0.70 und (e,e) mit 0.48 bewertet.
    if len(vokale) >= 2:
        if Counter(vokale).most_common(1)[0][1] > 2:
            return leer
        paare = [VOKALPAAR.get((vokale[i], vokale[i + 1]), 0.6)
                 for i in range(len(vokale) - 1)]
        vokalnote = sum(paare) / len(paare)
    else:
        vokalnote = EINSILBIG
    hart = set(konsonanten) & (PLOSIVE | REIBELAUTE)
    if not hart and not label.startswith("qu"):
        return leer

    liquide = set(konsonanten) & LIQUIDE
    weich = 1.0 if liquide else OHNE_LIQUID
    kontrast = 1.0 - abs(len(liquide) / len(konsonanten) - 0.5) / 0.5

    gelenk = 1.0
    for i in range(len(label) - 1):
        paar = label[i:i + 2]
        if paar[0] not in VOWELS and paar[1] not in VOWELS:
            gelenk = 1.0 if (paar in PRIME_MEDIAL or paar in J_GELENKE) else 0.74
            break

    teile = {"auslaut": a, "vokal": vokalnote, "kontrast": kontrast, "gelenk": gelenk}
    total = weich * sum(WEIGHTS[k] * teile[k] for k in WEIGHTS)
    return {**teile, "total": round(100.0 * total, 2)}


def rank(label: str) -> float:
    return breakdown(label)["total"]


def explain(label: str) -> str:  # pragma: no cover - Diagnose
    b = breakdown(label)
    body = "  ".join(f"{k}={b[k]:.2f}" for k in WEIGHTS)
    return f"{label:<12} {b['total']:6.2f}  {body}"
