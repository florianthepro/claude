"""Harte Kriterien -- jede Regel bekommt einen Zeugen."""

import pytest

from domainfinder.filters import check, passes, soft_hits

GOOD = ["bitrot", "purlink", "granot", "nodepot", "tarpit", "sighup", "badalg",
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
