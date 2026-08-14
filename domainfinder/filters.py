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
# Massstab ist laut Aufgabe ausschliesslich der deutschsprachige Hoerer. Eine
# Regel, die sich auf englische Aussprache stuetzt, ist damit nicht gedeckt.
#
# Verboten und warum:
#   c  -> c/k-Konflikt fuer den k-Laut, ausdruecklich in der Vorgabe genannt
#   v  -> in der Vorgabe genannt; deutsch schwankt es zwischen /f/ und /v/
#   w  -> in der Vorgabe genannt
#   y  -> in der Vorgabe genannt (als Vokal mehrdeutig, i/ue). Als Konsonant
#         waere es zwar eindeutig hoerbar -- aber der Hoerer schreibt dafuer im
#         Deutschen `j`, und damit kollidiert es mit dem jetzt erlaubten j.
#   z  -> in der Vorgabe genannt
#   q  -> `qu` gibt es nur vor u; ein Fantasiename gewinnt dadurch nichts, was
#         `kw` nicht auch gaebe, und `kw` ist ohnehin ueber w gesperrt
#   x  -> /ks/ ist hoerbar nicht von "ks" zu unterscheiden (Hexe/Hekse) --
#         das ist ein Fehler des deutschen Hoerers, die Regel bleibt
#
# `j` stand hier lange mit der Begruendung "deutsch /j/, englisch /dZ/". Das war
# der falsche Massstab: die Vorgabe nennt `j` nirgends, und ein deutscher Hoerer
# schreibt /j/ ohne Zoegern als j (Jahr, jetzt, jung, Jodel). Genau mit diesem
# Argument sind weiter unten `kn`, `pf` und `gn` zugelassen worden -- `j` mit dem
# englischen Massstab zu verwerfen war dazu unvereinbar. Es kostete rund ein
# Fuenftel des Namensraums.
VOWELS = frozenset("aeiou")
CONSONANTS = frozenset("bdfghjklmnprst")
ALLOWED = VOWELS | CONSONANTS
FORBIDDEN_LETTERS = frozenset("cvwyzqx")

# --- Verbotene Buchstabenfolgen ----------------------------------------------
FORBIDDEN_SEQS: tuple[tuple[str, str], ...] = (
    ("th", "th ist im Deutschen nicht produktiv"),
    ("ph", "ph/f-Konflikt"),
    ("ee", "Digraph fuer den i-Laut"),
    ("ea", "Digraph fuer den i-Laut"),
    ("ie", "Digraph fuer den i-Laut"),
    # `ei` und `eu` standen hier, obwohl die Vorgabe nur ee/ea/ie nennt -- alle
    # drei fuer den i-Laut. Fuer /aI/ gibt es zwar zwei Schreibungen, aber der
    # deutsche Hoerer schreibt ohne Zoegern `ei` (Bein, Wein, klein, Reise);
    # `ai` ist die Ausnahme und bleibt deshalb gesperrt. Fuer /OY/ gilt dasselbe:
    # `eu` ist der Normalfall, `aeu` gibt es nur zu einem Umlautstamm, und
    # Umlaute kennt das Alphabet ohnehin nicht.
    ("ai", "fuer /aI/ ist `ei` die Normalschreibung, `ai` die Ausnahme"),
    # ae, oe und ue sind im Deutschen die Ersatzschreibung der drei Umlaute.
    # Wer einen Namen mit "oe" hoert, weiss nicht, ob er den Umlaut oder zwei
    # getrennte Vokale schreiben soll -- genau die Mehrdeutigkeit, die die
    # Vorgabe ausschliessen will. Aufgefallen, als die vollstaendige Aufzaehlung
    # solche Formen erstmals erzeugt hat; die Musterschablonen hatten nie zwei
    # Vokale nebeneinander gestellt und die Luecke damit verdeckt.
    ("ae", "Ersatzschreibung fuer den a-Umlaut"),
    ("oe", "Ersatzschreibung fuer den o-Umlaut"),
    ("ue", "Ersatzschreibung fuer den u-Umlaut"),
    ("ou", "englisch /aU/ vs deutsch /u:/"),
    ("oo", "deutsch /o:/ vs englisch /u:/"),
    ("sh", "sh/sch-Konflikt"),
    ("ck", "c/k-Konflikt in der Verdopplung"),
    ("dt", "d/t-Konflikt am Wortende"),
)

# Folgen, die nur vor bestimmten Buchstaben stoeren. `dge` in gabledge ist die
# englische Schreibung fuer /d3/; das `dg` in badglas ist ein hartes g an einer
# Morphemfuge und voellig unproblematisch.
FORBIDDEN_BEFORE: tuple[tuple[str, str, str], ...] = (
    ("dg", "ei", "dge/dgi ist die englische Schreibung fuer /d3/"),
)

DOUBLE_LETTER = re.compile(r"(.)\1")

# Konsonantencluster, die als Silbenanlaut ohne Buchstabieren funktionieren.
LEGAL_ONSET_CLUSTERS = frozenset({
    "bl", "br", "dr", "fl", "fr", "gl", "gr", "kl", "kr", "pl", "pr", "tr",
    "sp", "st", "sk", "sl", "sn", "sm", "str", "spr", "skr", "spl",
    # Echte deutsche Anlaute, die vorher fehlten. Massstab ist laut Aufgabe der
    # deutschsprachige Hoerer -- fuer den sind Knoten, Pfad und Gnade eindeutig.
    "kn", "pf", "gn",
})
# Cluster, die im Auslaut noch diktierbar sind.
LEGAL_CODA_CLUSTERS = frozenset({
    "st", "ft", "lt", "nt", "rt", "lf", "lk", "lm", "lp", "mp", "nd", "ng",
    "nk", "ns", "ps", "rd", "rf", "rg", "rk", "rl", "rm", "rn", "rp", "rs",
    "sk", "sp", "ts", "ks", "bt", "pt", "kt", "ds", "gs", "ls", "ms", "ns",
    "fs", "rb", "rd", "sd", "md", "nf", "ln", "lg", "lb", "ld", "ld", "gd",
    "mt", "nst", "rst", "lst", "mpf", "rgt", "lft",
})

# Einzelkonsonanten, die eine Silbe schliessen bzw. eroeffnen duerfen.
# `h` fehlt in der Coda-Menge mit Absicht: es ist dort nicht hoerbar.
# `j` ebenso -- im Deutschen schliesst es nie eine Silbe.
LEGAL_CODA_CONSONANTS = frozenset("bdfgklmnprst")
LEGAL_ONSET_CONSONANTS = frozenset("bdfghjklmnprst")

# Zweiercluster im Wortinneren. Der Sprecher muss die Silbenfuge finden koennen,
# ohne zu raten. Zugelassen ist, was im Deutschen oder Englischen wirklich
# vorkommt: Nasal oder Liquid plus Konsonant (Senf, Wurf, Kampf), s plus
# Konsonant (Kasten), f plus t (Kraft), Plosiv plus Liquid als Anlaut der
# zweiten Silbe (A-dler, Si-gnal) und die gelaeufigen Plosivpaare.
#
# Ohne diese Liste laesst sich jedes Paar in zwei Einzelbuchstaben zerlegen und
# gilt damit als zulaessig -- so kamen `dusfa`, `bufdo`, `bekga` und `pokfa`
# in eine Ergebnisliste, die "leicht aussprechbar" sein sollte.
LEGAL_MEDIAL_CLUSTERS = frozenset({
    # Nasal + Konsonant
    "mp", "mb", "mf", "ms", "mt", "md",
    "nt", "nd", "nk", "ng", "ns", "nf", "nd",
    # Liquid + Konsonant
    "lt", "ld", "lk", "lg", "lp", "lb", "lf", "ls", "lm", "ln",
    "rt", "rd", "rk", "rg", "rp", "rb", "rf", "rs", "rm", "rn", "rl",
    # s + Konsonant
    "sp", "st", "sk", "sm", "sn", "sl",
    # Reibelaut + Plosiv
    "ft",
    # Plosiv + Liquid: eroeffnet die zweite Silbe (A-dler, Zi-trone)
    "br", "bl", "dr", "gr", "gl", "kr", "kl", "pr", "pl", "tr", "fl", "fr", "dl",
    # gelaeufige Plosivpaare und Zischauslaute
    "kt", "pt", "ts", "ps", "ds", "bs", "gs", "gn", "kn", "pf",
    # Nachgetragen, weil sie sonst echte Woerter verwerfen:
    "gm",  # Figma, Pigment, Segment
    "gt",  # sagte, sigterm
    "bt",  # abteilen
    "dm",  # Admiral
    "tm",  # atmen
    "fs",  # Chefs, rootfs
    "ks",  # Keks
    "tl",  # Ortlich, Atlas
    "dn",  # Ordner
    "tn",  # Ordnung
    # Konsonant + j eroeffnet die zweite Silbe. Jeder deutsche Hoerer schreibt
    # Sonja, Marja, Katja und Ronja richtig, ohne zu fragen.
    "nj", "rj", "lj", "tj",
    # Die Standardfugen der deutschen Praefixe an-, un-, aus-, ab-. Sie fehlten,
    # obwohl die spiegelbildlichen Paare (nd, nt, st, sk, dn, tn, dm) alle
    # drinstehen -- eine Asymmetrie ohne Grund, die `konra`, `anmut`, `lisbo`
    # und `asgar` verworfen hat.
    "nr", "nm", "nl", "nb",        # Anruf, Anmut, Anlauf, Anbau
    "sb", "sg", "sd", "sr", "sf",  # Ausbau, Ausgabe, Ausdruck, Israel, Ausfahrt
    "bg", "bd", "bn", "gd",        # Abgas, Abdruck, Abnahme, Magdeburg
})

# Sonorant + h. Anhalt, erholen, Wilhelm, Bernhard, Amhara: hier ist das h
# hoerbar und die Silbenfuge liegt davor. Nach einem Plosiv gibt es das nicht,
# deshalb bleibt `bekho` draussen.
LEGAL_H_AFTER = frozenset("lmnr")

# Klangeinordnung der Binnencluster. Das ist KEIN Filter -- `nf`, `rf` und `mf`
# sind diktiersicher und bleiben zugelassen. Fuer einen Markennamen klingen sie
# aber hart, und `gen/brand5.py` sowie `startup.py` brauchen dieselbe Einteilung.
# Sie steht hier, weil alles uebrige Clusterwissen auch hier steht.
PRIME_MEDIAL = frozenset({
    "nt", "nd", "nk", "ng", "mp", "mb",      # Nasal + Plosiv: Vanta, Canva
    "rt", "rd", "rk", "rg", "lt", "ld", "lk", "lg",   # Liquid + Plosiv
    "st", "sp", "sk",                        # s + Plosiv: Gusto
    "gr", "br", "dr", "tr", "kr", "pr", "gl", "bl", "kl", "pl", "fl", "fr",
    "gm", "dm", "tm",                        # Plosiv + Nasal: Figma
})
FAIR_MEDIAL = frozenset({"ns", "rs", "ls", "ms", "rl", "rn", "lm", "ln", "ft",
                         "kt", "pt", "sl", "sm", "sn"})

# --- Anstoessige / komische Teilzeichenfolgen ---------------------------------
# Nur Eintraege, die im erlaubten Alphabet ueberhaupt vorkommen koennen, sind
# wirksam -- der Rest bleibt zur Dokumentation stehen.
BLACKLIST_HARD_EN = (
    "anal", "boner", "damn", "dildo", "fart", "gonad", "hooker",
    "milf", "nude", "orgas", "pedo", "penis", "porn", "pube",
    "rectal", "rectum", "slag", "slut", "smut", "sperm", "std", "stds", "tard",
    "testis", "turd", "gimp", "hitler", "nazi", "kkk",
    "genocid", "rapist", "molest", "incest", "bdsm", "fetish", "hentai",
    "shit", "shag", "prostit", "hooligan", "bomb", "murder", "suicid",
    "knob", "bugger", "tosser", "minge",
    "spic", "kike", "gook", "chink", "tranny", "retard",
)
# Gestrichen, weil sie als unverankerte Teilzeichenfolge harmlose Namen
# erschlagen haben und der deutsche Hoerer -- laut Vorgabe der alleinige
# Massstab -- darin nichts hoert:
#   tit   -> titan, titel, titus, titon   (titte/titten stehen unten und decken
#                                          den deutschen Fall vollstaendig ab)
#   klan  -> klang, klani, klano          (das schoenste verfuegbare Wortmuster)
#   isis  -> bisis, misis, risis
#   arse  -> parse, sparse, marse
#   arse  -> parse, sparse, marse
#   rape  -> grape, drape, trape
#   loin  -> kloin, floin, loina
#   kok   -> kokos, kokon, kokel
BLACKLIST_HARD_DE = (
    "arsch", "hure", "nutte", "titte", "titten", "pimmel", "muschi", "hoden",
    "sperma", "bums", "poppen", "penner", "idiot", "spast", "kruepp", "mongo",
    "geil", "notgeil", "puff", "bordell", "strichr", "freier", "unhold",
    "kacke", "kot", "pups", "pipi", "popo", "rotz", "dreck",
    "mord", "morden", "leiche", "hass", "nutten", "luder",
    "opfer", "spasti", "kanake", "prolet", "abschaum",
    "unfall", "tumor", "krebs", "seuche", "pandemi",
    # Gestrichen, weil nicht anstoessig oder als Fragment zu breit:
    #   asi   -> basis, basil, kasia, masio   (nur als freistehendes Wort eine
    #                                          Beleidigung)
    #   sau   -> sauna, sauro, saudi, sauli
    #   furt  -> Frankfurt, Schweinfurt; eine Flussquerung
    #   behind -> englisch "hinter", im Deutschen bedeutungslos
    #   brand, mist, pest, totes, pudel -> stehen jetzt auf der weichen Liste
    # Aus einem echten Lauf nachgetragen: kopule, pupore, pubod standen in der
    # Ergebnisliste, bevor diese Eintraege da waren.
    "kopul", "pupo", "pubo", "pimp", "grapsch", "lusche",
    "hurn", "nutt", "prut",
)

# Dreibuchstabige Lautfragmente. Die Liste enthielt urspruenglich nur
# Schreibweisen mit c, die im erlaubten Alphabet gar nicht vorkommen koennen --
# lautgleiche Varianten kamen ungehindert durch (`fikno`, `fikne` standen in
# einer Ergebnisliste). Blacklists muessen im selben Alphabet gedacht werden,
# in dem erzeugt wird.
#
# Sie greifen nur am WORTANFANG. Dort bilden sie die erste, betonte Silbe und
# sind zu hoeren; mitten im Wort sind sie es nicht, und als freie
# Teilzeichenfolge haben sie `kokos`, `kakao`, `fakir`, `dikta`, `kosak`,
# `puste` und `fagot` mit erschlagen. `_entkraeftet` gilt weiterhin: `opus`
# und `diktat` bleiben unangetastet.
BLACKLIST_PREFIX = (
    "fik", "fak", "fuk", "kak", "sak", "pup", "pus", "dik", "fag",
)

# --- Bekannte Marken ----------------------------------------------------------
# Kein Markenrecherche-Ersatz, sondern ein billiger Vorfilter: ein Label, das
# eine dieser Marken als Teilzeichenfolge enthaelt, ist die Pruefung nicht wert.
# Nur Eintraege, die im erlaubten Alphabet ueberhaupt vorkommen koennen.
#
# Vierzeichige Marken werden nur als ganzes Label verworfen. Als
# Teilzeichenfolge trafen sie sonst Namen, in denen niemand die Marke sieht:
# audi -> audio, audit, gaudi; opel -> kopel, popel; aldi -> baldi, saldi;
# puma -> spuma, pumal; otto -> lotto; dell -> faellt ohnehin an der Verdopplung.
TRADEMARKS = (
    "intel", "adobe", "nokia", "dell", "aldi", "audi", "opel", "otto", "puma",
    "miele", "medion", "kodak", "sonos", "tesla", "honda", "figma", "notion",
    "stripe", "gitlab", "github", "redis", "kafka", "grafana", "traefik",
    "komodo", "portainer", "hetzner", "ionos", "strato", "plesk", "unifi",
    "truenas", "proton", "telekom", "siemens", "bosch", "lufthansa", "airbnb",
    "spotify", "netflix", "reddit", "discord", "telegram", "signal", "oracle",
)
# Unschuldige Woerter, die eine Teilzeichenfolge der harten Liste enthalten.
# Deckt das Ausnahmewort den Treffer vollstaendig ab, gilt er als entkraeftet:
# `opus` und `korpus` tragen `pus`, `diktat` traegt `dik`. Ohne das muesste man
# entweder die Teilzeichenfolge streichen -- dann kaeme `pusno` durch -- oder
# echte Woerter verwerfen. Beides ist falsch.
BLACKLIST_EXCEPTIONS = (
    "opus", "korpus", "kampus", "impuls", "puls", "spule", "kapsul",
    "diktat", "indikat", "predikat", "diktion", "sakral", "sakrament",
    "kaktus", "traktus", "duktus", "fokus", "lotus", "modus", "bonus",
    "salut", "absud", "sudo", "pupil", "populus",
    # Echte Woerter, die an den Anlautfragmenten scheitern wuerden.
    "fakir", "fakt", "faktor", "fakul", "fagot", "fagus",
    "sakra", "sakro", "dikta", "dikto", "diktu", "kakao", "kakte",
    "puste", "pusta", "pusto", "pusul",
    # `anal` bleibt hart -- `badanal` liest sich, wie es sich liest. Die
    # gelaeufigen deutschen Woerter auf -anal sind die Ausnahme.
    "kanal", "banal", "fanal", "analog", "analys", "analyt",
)

# Weiche Liste: nur Punktabzug, kein Ausschluss (mehrdeutig oder harmlos-schief).
BLACKLIST_SOFT = (
    "tod", "grab", "sarg", "blut", "gift", "narb", "fies", "made", "pilz",
    "dumm", "faul", "lahm", "muede", "krank", "abfall", "muell", "stink",
    "grind", "eiter", "furunk", "dead", "kill", "sick", "lame", "dumb",
    "toxic", "junk", "trash", "crash", "leak", "flop",
    # Von der harten Liste hierher: schiefer Beiklang, aber nicht anstoessig.
    "brand", "mist", "pest", "totes", "pudel",
)
# "fail" und "bug" stehen bewusst NICHT auf der weichen Liste: der Fachwitz aus
# Quelle C lebt davon, und .fail ist eine der Ziel-TLDs.


@dataclass(frozen=True)
class Rejection:
    rule: str
    detail: str

    def __str__(self) -> str:
        return f"{self.rule}: {self.detail}"


def _entkraeftet(label: str, start: int, end: int) -> bool:
    """True, wenn ein Ausnahmewort den Treffer vollstaendig ueberdeckt."""
    for gut in BLACKLIST_EXCEPTIONS:
        pos = label.find(gut)
        while pos >= 0:
            if pos <= start and pos + len(gut) >= end:
                return True
            pos = label.find(gut, pos + 1)
    return False


def _syllables(label: str) -> int:
    """Silbenzahl ueber Vokalgruppen. Im erlaubten Alphabet exakt genug."""
    return len(re.findall(r"[aeiou]+", label))


def _clusters(label: str) -> list[tuple[int, str]]:
    return [(m.start(), m.group()) for m in re.finditer(r"[^aeiou]{2,}", label)]


def check(label: str, *, compound: bool = False) -> Rejection | None:
    """Gibt None zurueck, wenn das Label alle harten Kriterien erfuellt.

    `compound=True` sagt: dieses Label ist ein echtes Kompositum aus einem
    Standard, die Morphemfuge ist bekannt. Dann darf ein `h` hinter einem
    Konsonanten stehen -- `sig|hup` und `sink|hole` liest jeder richtig.
    Bei erfundenen Namen fehlt diese Fuge, und `durho` oder `bemha` zwingen
    den Sprecher zum Raten. Nur Quelle C setzt das Flag.
    """
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

    for seq, followers, why in FORBIDDEN_BEFORE:
        for pos in range(len(label) - len(seq)):
            if label[pos:pos + len(seq)] == seq and label[pos + len(seq)] in followers:
                return Rejection("folge", f"{seq + label[pos + len(seq)]!r} -- {why}")

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

    # j ist eindeutig -- aber nur an den Stellen, an denen das Deutsche es kennt:
    # am Silbenanfang vor einem Vokal (ja, Sonja, Katja). Zwischen zwei Vokalen
    # konkurriert es mit i und y (Maja/Maia/Maya), vor einem Konsonanten und am
    # Wortende gibt es es gar nicht.
    for i, ch in enumerate(label):
        if ch != "j":
            continue
        if i + 1 == len(label) or label[i + 1] not in VOWELS:
            return Rejection("jstellung", "j ohne folgenden Vokal gibt es im Deutschen nicht")
        if i > 0 and label[i - 1] in VOWELS:
            return Rejection("jstellung", "j zwischen zwei Vokalen konkurriert mit i und y")

    max_cluster = 4 if compound else 3   # an der Wortfuge treffen zwei Raender
    for pos, cl in _clusters(label):
        if len(cl) > max_cluster:
            return Rejection("cluster", f"{cl!r} ist zu lang zum Mitschreiben")
        if pos == 0:
            if cl not in LEGAL_ONSET_CLUSTERS:
                return Rejection("cluster", f"Anlautcluster {cl!r} ist nicht diktiersicher")
        elif pos + len(cl) == len(label):
            if cl not in LEGAL_CODA_CLUSTERS:
                return Rejection("cluster", f"Auslautcluster {cl!r} ist nicht diktiersicher")
        else:
            if "h" in cl:
                # Ein h im Cluster ist eine Silbenfuge. Bei einem echten
                # Kompositum ist sie bekannt und die Cluster-Liste gilt nicht --
                # sig|hup steht in keiner Silbentabelle.
                if compound:
                    continue
                # Auch ohne Kompositum ist sie hoerbar, wenn davor ein Sonorant
                # steht: Anhalt, erholen, Wilhelm. Das galt hier pauschal als
                # Ratespiel und hat `solho`, `torho`, `kirha` mitverworfen.
                if len(cl) == 2 and cl[1] == "h" and cl[0] in LEGAL_H_AFTER:
                    continue
                return Rejection("cluster", f"h im Binnencluster {cl!r} zwingt zum Raten,"
                                            " wo die Silbenfuge liegt")
            if compound:
                # An einer bekannten Wortfuge treffen Coda und Onset zweier
                # echter Woerter aufeinander. `daten|pfad` ergibt `npf`, das es
                # innerhalb eines Morphems nie gibt -- der Leser stolpert
                # trotzdem nicht, weil er die Fuge sieht.
                continue
            if len(cl) == 2:
                if cl not in LEGAL_MEDIAL_CLUSTERS:
                    return Rejection("cluster", f"Binnencluster {cl!r} kommt in keiner der"
                                                " beiden Sprachen vor")
            else:
                # Dreiercluster nur, wenn er sich in eine echte Coda und einen
                # echten Onset zerlegen laesst -- ohne Schlupfloch fuer
                # Einzelbuchstaben, sonst ist jede Kombination erlaubt.
                ok = any(
                    (a in LEGAL_CODA_CONSONANTS or a in LEGAL_CODA_CLUSTERS)
                    and (b in LEGAL_ONSET_CONSONANTS or b in LEGAL_ONSET_CLUSTERS)
                    for a, b in ((cl[:i], cl[i:]) for i in range(1, len(cl)))
                )
                if not ok:
                    return Rejection("cluster", f"Binnencluster {cl!r} ist nicht diktiersicher")

    grenze = 4 if compound else 3       # Komposita duerfen eine Silbe mehr
    if _syllables(label) > grenze:
        return Rejection("silben", f"{_syllables(label)} Silben, hoechstens {grenze} erlaubt")

    for sprache, liste in (("de", BLACKLIST_HARD_DE), ("en", BLACKLIST_HARD_EN)):
        for word in liste:
            start = label.find(word)
            if start < 0:
                continue
            if _entkraeftet(label, start, start + len(word)):
                continue
            return Rejection(f"blacklist_{sprache}", f"enthaelt {word!r}")
    for frag in BLACKLIST_PREFIX:
        if label.startswith(frag) and not _entkraeftet(label, 0, len(frag)):
            return Rejection("blacklist_de", f"beginnt mit {frag!r}")
    for mark in TRADEMARKS:
        treffer = (mark in label) if len(mark) >= 5 else (mark == label)
        if treffer:
            return Rejection("marke", f"enthaelt die bekannte Marke {mark!r}")

    return None


def passes(label: str, *, compound: bool = False) -> bool:
    return check(label, compound=compound) is None


def soft_hits(label: str) -> list[str]:
    """Treffer der weichen Blacklist -- fliessen als Abzug in den Score."""
    return [w for w in BLACKLIST_SOFT if w in label.lower()]
