"""Harte Kriterien -- jede Regel bekommt einen Zeugen."""

import pytest

from domainfinder.filters import check, passes, soft_hits

GOOD = ["bitrot", "purlink", "granot", "nodepot", "tarpit", "badalg",
        "notimp", "stratum", "helo", "fastopen", "sigterm"]


@pytest.mark.parametrize("label", GOOD)
def test_gute_labels_bestehen(label):
    assert passes(label), f"{label} sollte bestehen, wurde aber verworfen: {check(label)}"


@pytest.mark.parametrize("label,rule", [
    ("kacid", "alphabet"),      # c
    ("vault", "alphabet"),      # v
    ("waldo", "alphabet"),      # w
    ("nullmx", "alphabet"),     # x
    ("syslog", "alphabet"),     # y
    ("zonefil", "alphabet"),    # z
    ("jitter", "alphabet"),     # j
    ("quorum", "alphabet"),     # q
    ("norther", "folge"),       # th
    ("graphit", "folge"),       # ph
    ("keeper", "folge"),        # ee
    ("beadle", "folge"),        # ea
    ("relief", "folge"),        # ie
    ("meister", "folge"),       # ei
    ("mailbot", "folge"),       # ai
    ("neutron", "folge"),       # eu
    ("mounter", "folge"),       # ou
    ("moonlit", "folge"),       # oo
    ("shard", "folge"),         # sh
    ("lighter", "dehnungsh"),  # gh = stummes h
    ("locker", "alphabet"),     # c vor k
    ("tunnel", "doppel"),       # nn
    ("null", "doppel"),         # ll
    ("bahnhof", "dehnungsh"),   # Dehnungs-h vor Konsonant
    ("dfghk", "vokal"),         # kein Vokal
    ("floh", "dehnungsh"),      # stummes End-h
    ("ghoster", "anlaut"),      # Konsonant + h im Anlaut
    ("bit", "laenge"),          # zu kurz
    ("subnetmaske", "laenge"),  # zu lang
    ("bit-rot", "zeichen"),     # Bindestrich
    ("bitrot7", "zeichen"),     # Ziffer
    ("badanal", "blacklist_en"),
    ("hurenet", "blacklist_de"),
])
def test_schlechte_labels_fallen_mit_der_richtigen_regel(label, rule):
    r = check(label)
    assert r is not None, f"{label} haette scheitern muessen"
    assert r.rule == rule, f"{label}: erwartet {rule}, bekam {r.rule} ({r.detail})"


def test_laengengrenze_ist_zehn():
    assert passes("nodepotane") or check("nodepotane").rule != "laenge"
    assert check("nodepotanes").rule == "laenge"


def test_weiche_liste_verwirft_nicht():
    assert passes("todlink") or check("todlink").rule != "blacklist_de"
    assert "tod" in soft_hits("todlink")


def test_fail_und_bug_sind_bewusst_erlaubt():
    """Der Fachwitz aus Quelle C lebt davon, .fail ist Ziel-TLD."""
    assert soft_hits("softfail") == []
    assert soft_hits("bugtrap") == []


@pytest.mark.parametrize("label,rule", [
    ("kopule", "blacklist_de"),    # Kopulation
    ("pupore", "blacklist_de"),
    ("purlintel", "marke"),        # enthaelt intel
    ("nokiator", "marke"),
])
def test_nachgetragene_regeln_aus_einem_echten_lauf(label, rule):
    r = check(label)
    assert r is not None and r.rule == rule, f"{label}: bekam {r}"


def test_englische_dge_schreibung_faellt():
    """gabledge klingt englisch nach /d3/ -- ein Deutscher schreibt nie 'dge'."""
    assert check("gabledge").rule == "folge"
    assert check("ridgest").rule == "folge"


def test_dg_stoert_nur_vor_e_und_i():
    """gabledge ist englisch, badglue ist eine harte Morphemfuge."""
    assert check("gabledge").rule == "folge"
    assert passes("badglue"), check("badglue")


@pytest.mark.parametrize("label", [
    "dusfa",   # sf
    "bufdo",   # fd
    "bekga",   # kg
    "pokfa",   # kf
    "pumno",   # mn
    "durho",   # h im Cluster
    "bemha",   # h im Cluster
    "pulho",   # h im Cluster
])
def test_unsprechbare_binnencluster_fallen(label):
    """Aus einem echten Lauf: diese standen in einer Liste, die
    'leicht aussprechbar' sein sollte. Jedes Paar laesst sich zwar in zwei
    Einzelbuchstaben zerlegen -- genau das war das Schlupfloch."""
    r = check(label)
    assert r is not None and r.rule == "cluster", f"{label}: bekam {r}"


@pytest.mark.parametrize("label", [
    "gusto", "dogma", "minta", "kanto", "bralo", "salte", "purlink", "bitrot",
    "karsten", "silber", "kurbel", "tundra",
])
def test_natuerliche_binnencluster_bleiben(label):
    assert passes(label), check(label)


def test_h_hinter_konsonant_nur_bei_echten_komposita():
    """sig|hup hat eine bekannte Morphemfuge, durho hat keine.

    `compound=True` ist ein Vertrauensflag, kein Filter: es sagt dem Pruefer,
    dass die Fuge bekannt ist. Nur Quelle C setzt es, und die speist eine
    kuratierte Liste echter Standardbegriffe ein. Fuer alles Erzeugte gilt der
    strenge Weg -- und nur der entscheidet ueber die Ergebnisliste.
    """
    for erfunden in ("durho", "bemha", "pulho"):
        assert not passes(erfunden), f"{erfunden} muss im Normalmodus fallen"
    assert check("sighup") is not None            # als erfundener Name: raus
    assert passes("sighup", compound=True)        # als Standardbegriff: bleibt
    assert passes("sinkhole", compound=True)


@pytest.mark.parametrize("label", ["fikno", "fikne", "pusno", "pusmi", "kakto", "sakle"])
def test_lautgleiche_kraftausdruecke_im_erlaubten_alphabet(label):
    """Die Blacklist stand in Schreibweisen, die das Alphabet gar nicht kennt.
    'fick' kann nie vorkommen (c verboten), 'fik' klingt identisch und kam
    ungehindert durch."""
    r = check(label)
    assert r is not None and r.rule.startswith("blacklist"), f"{label}: bekam {r}"


def test_fag_faellt():
    """Stand auf Platz 10 einer Ergebnisliste, bevor der Eintrag da war."""
    assert check("fagmo").rule == "blacklist_en"
