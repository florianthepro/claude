"""Harte Kriterien.

Ein Label besteht die Pruefung nur, wenn es einem deutschsprachigen Menschen am
Telefon genannt werden kann, ohne dass buchstabiert werden muss. Alles hier ist
Ausschlusskriterium, nicht Bewertung -- Bewertung steht in `scoring.py`.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

MAX_LEN = 10
MIN_LEN = 4
OPTIMUM = range(5, 9)  # 5..8

# --- Diktiersicheres Alphabet -------------------------------------------------
# Verboten und warum:
#   c  -> c/k-Konflikt fuer den k-Laut, ausserdem ch = /x/ (de) vs /tS/ (en)
#   v  -> deutsch /f/, englisch /v/
#   w  -> deutsch /v/, englisch /w/
#   y  -> als Vokal mehrdeutig (i/ue/j)
#   z  -> deutsch /ts/, englisch /z/
#   j  -> deutsch /j/, englisch /dZ/
#   q  -> qu = /kv/ (de) vs /kw/ (en)
#   x  -> /ks/ ist hoerbar nicht von "ks" zu unterscheiden
VOWELS = frozenset("aeiou")
CONSONANTS = frozenset("bdfghklmnprst")
ALLOWED = VOWELS | CONSONANTS
FORBIDDEN_LETTERS = frozenset("cvwyzjqx")

# --- Verbotene Buchstabenfolgen ----------------------------------------------
FORBIDDEN_SEQS: tuple[tuple[str, str], ...] = (
    ("th", "th ist im Deutschen nicht produktiv"),
    ("ph", "ph/f-Konflikt"),
    ("ee", "Digraph fuer den i-Laut"),
    ("ea", "Digraph fuer den i-Laut"),
    ("ie", "Digraph fuer den i-Laut"),
    ("ei", "ei/ai fuer /aI/ ist beim Diktat mehrdeutig"),
    ("ai", "ei/ai fuer /aI/ ist beim Diktat mehrdeutig"),
    ("eu", "eu/aeu-Mehrdeutigkeit"),
    ("ou", "englisch /aU/ vs deutsch /u:/"),
    ("oo", "deutsch /o:/ vs englisch /u:/"),
    ("sh", "sh/sch-Konflikt"),
    ("ck", "c/k-Konflikt in der Verdopplung"),
    ("dt", "d/t-Konflikt am Wortende"),
    ("dg", "dge ist eine englische Schreibung fuer /d3/, im Deutschen nicht ableitbar"),
)

DOUBLE_LETTER = re.compile(r"(.)\1")

# Konsonantencluster, die als Silbenanlaut ohne Buchstabieren funktionieren.
LEGAL_ONSET_CLUSTERS = frozenset({
    "bl", "br", "dr", "fl", "fr", "gl", "gr", "kl", "kr", "pl", "pr", "tr",
    "sp", "st", "sk", "sl", "sn", "sm", "str", "spr", "skr", "spl",
})
# Cluster, die im Auslaut noch diktierbar sind.
LEGAL_CODA_CLUSTERS = frozenset({
    "st", "ft", "lt", "nt", "rt", "lf", "lk", "lm", "lp", "mp", "nd", "ng",
    "nk", "ns", "ps", "rd", "rf", "rg", "rk", "rl", "rm", "rn", "rp", "rs",
    "sk", "sp", "ts", "ks", "bt", "pt", "kt", "ds", "gs", "ls", "ms", "ns",
    "fs", "rb", "rd", "sd", "md", "nf", "ln", "lg", "lb", "ld", "ld", "gd",
    "mt", "nst", "rst", "lst", "mpf", "rgt", "lft",
})

# --- Anstoessige / komische Teilzeichenfolgen ---------------------------------
# Nur Eintraege, die im erlaubten Alphabet ueberhaupt vorkommen koennen, sind
# wirksam -- der Rest bleibt zur Dokumentation stehen.
BLACKLIST_HARD_EN = (
    "anal", "arse", "boner", "damn", "dildo", "fart", "fuk", "gonad", "hooker",
    "loin", "milf", "nude", "orgas", "pedo", "penis", "porn", "pube", "rape",
    "rectal", "rectum", "slag", "slut", "smut", "sperm", "std", "stds", "tard",
    "testis", "tit", "turd", "gimp", "hitler", "isis", "nazi", "klan", "kkk",
    "genocid", "rapist", "molest", "incest", "bdsm", "fetish", "hentai",
    "shit", "shag", "prostit", "hooligan", "bomb", "murder", "suicid",
)
BLACKLIST_HARD_DE = (
    "arsch", "hure", "nutte", "titte", "titten", "pimmel", "muschi", "hoden",
    "sperma", "bums", "poppen", "penner", "idiot", "spast", "kruepp", "mongo",
    "geil", "notgeil", "puff", "bordell", "strichr", "freier", "unhold",
    "kacke", "kot", "furt", "pups", "pipi", "popo", "rotz", "mist", "dreck",
    "mord", "morden", "totes", "leiche", "sau", "hass", "nutten", "luder",
    "opfer", "spasti", "behind", "kanake", "asi", "prolet", "abschaum",
    "brand", "unfall", "tumor", "krebs", "seuche", "pest", "pandemi",
    # Aus einem echten Lauf nachgetragen: kopule, pupore, pubod standen in der
    # Ergebnisliste, bevor diese Eintraege da waren.
    "kopul", "pupo", "pubo", "pudel", "pimp", "grapsch", "lusche",
)

# --- Bekannte Marken ----------------------------------------------------------
# Kein Markenrecherche-Ersatz, sondern ein billiger Vorfilter: ein Label, das
# eine dieser Marken als Teilzeichenfolge enthaelt, ist die Pruefung nicht wert.
# Nur Eintraege, die im erlaubten Alphabet ueberhaupt vorkommen koennen.
TRADEMARKS = (
    "intel", "adobe", "nokia", "dell", "aldi", "audi", "opel", "otto", "puma",
    "miele", "medion", "kodak", "sonos", "tesla", "honda", "figma", "notion",
    "stripe", "gitlab", "github", "redis", "kafka", "grafana", "traefik",
    "komodo", "portainer", "hetzner", "ionos", "strato", "plesk", "unifi",
    "truenas", "proton", "telekom", "siemens", "bosch", "lufthansa", "airbnb",
    "spotify", "netflix", "reddit", "discord", "telegram", "signal", "oracle",
)
# Weiche Liste: nur Punktabzug, kein Ausschluss (mehrdeutig oder harmlos-schief).
BLACKLIST_SOFT = (
    "tod", "grab", "sarg", "blut", "gift", "narb", "fies", "made", "pilz",
    "dumm", "faul", "lahm", "muede", "krank", "abfall", "muell", "stink",
    "grind", "eiter", "furunk", "dead", "kill", "sick", "lame", "dumb",
    "toxic", "junk", "trash", "crash", "leak", "flop",
)
# "fail" und "bug" stehen bewusst NICHT auf der weichen Liste: der Fachwitz aus
# Quelle C lebt davon, und .fail ist eine der Ziel-TLDs.


@dataclass(frozen=True)
class Rejection:
    rule: str
    detail: str

    def __str__(self) -> str:
        return f"{self.rule}: {self.detail}"


def _syllables(label: str) -> int:
    """Silbenzahl ueber Vokalgruppen. Im erlaubten Alphabet exakt genug."""
    return len(re.findall(r"[aeiou]+", label))


def _clusters(label: str) -> list[tuple[int, str]]:
    return [(m.start(), m.group()) for m in re.finditer(r"[^aeiou]{2,}", label)]


def check(label: str) -> Rejection | None:
    """Gibt None zurueck, wenn das Label alle harten Kriterien erfuellt."""
    label = label.lower()

    if not label.isascii() or not label.isalpha():
        return Rejection("zeichen", "nur Buchstaben a-z erlaubt, keine Ziffern, keine Bindestriche")
    if len(label) > MAX_LEN:
        return Rejection("laenge", f"{len(label)} Zeichen, erlaubt sind hoechstens {MAX_LEN}")
    if len(label) < MIN_LEN:
        return Rejection("laenge", f"{len(label)} Zeichen, unter dem Minimum {MIN_LEN}")

    bad = sorted(set(label) & FORBIDDEN_LETTERS)
    if bad:
        return Rejection("alphabet", f"nicht diktiersicherer Buchstabe {','.join(bad)}")
    unknown = sorted(set(label) - ALLOWED)
    if unknown:
        return Rejection("alphabet", f"ausserhalb des Alphabets: {','.join(unknown)}")

    for seq, why in FORBIDDEN_SEQS:
        if seq in label:
            return Rejection("folge", f"{seq!r} -- {why}")

    if not (set(label) & VOWELS):
        return Rejection("vokal", "kein Vokal")

    m = DOUBLE_LETTER.search(label)
    if m:
        return Rejection("doppel", f"optionale Verdopplung {m.group()!r}")

    # h ist nur als Silbenanlaut hoerbar. Steht es vor einem Konsonanten oder am
    # Wortende, ist es Dehnungs-h und beim Diktat nicht von seinem Fehlen zu
    # unterscheiden (Ban/Bahn, Flo/Floh). Das erlaubt sighup (sig-hup) und
    # verwirft bahnhof und lighter.
    for i, ch in enumerate(label):
        if ch == "h" and (i + 1 == len(label) or label[i + 1] not in VOWELS):
            return Rejection("dehnungsh", "h ohne folgenden Vokal ist nicht hoerbar")
    # Konsonant + h am Wortanfang ist immer eine fremde Schreibung (ghost, khan).
    if len(label) > 1 and label[1] == "h" and label[0] not in VOWELS:
        return Rejection("anlaut", f"Anlaut {label[:2]!r} ist keine deutsche Schreibung")

    for pos, cl in _clusters(label):
        if len(cl) > 3:
            return Rejection("cluster", f"{cl!r} ist zu lang zum Mitschreiben")
        if pos == 0:
            if cl not in LEGAL_ONSET_CLUSTERS:
                return Rejection("cluster", f"Anlautcluster {cl!r} ist nicht diktiersicher")
        elif pos + len(cl) == len(label):
            if cl not in LEGAL_CODA_CLUSTERS:
                return Rejection("cluster", f"Auslautcluster {cl!r} ist nicht diktiersicher")
        else:
            # Binnencluster: muss sich in legalen Coda- + Onset-Teil zerlegen lassen.
            ok = any(
                (not a or a in LEGAL_CODA_CLUSTERS or len(a) == 1)
                and (not b or b in LEGAL_ONSET_CLUSTERS or len(b) == 1)
                for a, b in ((cl[:i], cl[i:]) for i in range(len(cl) + 1))
            )
            if not ok:
                return Rejection("cluster", f"Binnencluster {cl!r} ist nicht diktiersicher")

    if _syllables(label) > 3:
        return Rejection("silben", f"{_syllables(label)} Silben, hoechstens 3 erlaubt")

    for word in BLACKLIST_HARD_DE:
        if word in label:
            return Rejection("blacklist_de", f"enthaelt {word!r}")
    for word in BLACKLIST_HARD_EN:
        if word in label:
            return Rejection("blacklist_en", f"enthaelt {word!r}")
    for mark in TRADEMARKS:
        if mark in label:
            return Rejection("marke", f"enthaelt die bekannte Marke {mark!r}")

    return None


def passes(label: str) -> bool:
    return check(label) is None


def soft_hits(label: str) -> list[str]:
    """Treffer der weichen Blacklist -- fliessen als Abzug in den Score."""
    return [w for w in BLACKLIST_SOFT if w in label.lower()]
