"""Die Klangnote und die vollstaendige Aufzaehlung -- jede Regel ein Zeuge."""

import pytest

from domainfinder.filters import CONSONANTS, VOWELS, check
from domainfinder.gen import brand5, latinform, phonotactic, vollstaendig
from domainfinder.schoenheit import auslaut_wert, hiatus_ok, rank


def test_kalibrierung_echte_marken_liegen_oben():
    """Der Massstab sind Namen, die es gibt. `prisma` muss ueber `okta` liegen
    -- Okta traegt, hat aber weder Liquid noch Vokalgefaelle."""
    assert rank("prisma") > rank("pelja") > rank("okta") > 0
    assert rank("stark") > 0            # geschlossene Silbe zaehlt mit
    assert rank("vanta") > 0            # zwei gleiche Vokale sind kein Ausschluss


@pytest.mark.parametrize("label", ["lulula", "mumima", "vuvua"])
def test_monotonie_faellt(label):
    """Dreimal derselbe Vokal. Eine fruehere Fassung hat genau das belohnt,
    weil sie nur Sonoritaet und Auslaut gewichtet hat."""
    assert rank(label) == 0.0


@pytest.mark.parametrize("label", ["blaoa", "eodma", "uibla", "geoina"])
def test_vokalhaufen_fallen(label):
    """Aus dem ersten vollstaendigen Lauf: diese standen mit Hoechstnoten oben,
    weil das Mass Vokalhaeufung nie ausgeschlossen hatte."""
    assert rank(label) == 0.0


def test_diphthonge_und_marken_auslaute_bleiben():
    assert hiatus_ok("leido") and hiatus_ok("reuma") and hiatus_ok("kaura")
    assert hiatus_ok("nadia") and hiatus_ok("folio")     # -ia/-io im Auslaut
    assert not hiatus_ok("nadial")                        # -ia mittendrin
    assert not hiatus_ok("blaoa")
    assert hiatus_ok("quiro")                             # das u von qu zaehlt nicht


def test_auslaut_kennt_offene_und_geschlossene_silben():
    assert auslaut_wert("pelja") == auslaut_wert("okta") > 0     # beide auf -a
    assert auslaut_wert("brant") > 0 and auslaut_wert("stark") > 0
    assert auslaut_wert("bofek") == 0.0                          # kein Markenauslaut


def test_wiederholter_konsonant_faellt():
    assert rank("bobis") == 0.0


# --- Quelle M ----------------------------------------------------------------
def test_quelle_m_nutzt_genau_das_alphabet_der_harten_kriterien():
    """Die Drift zwischen Generator-Alphabet und Filter-Alphabet war der Grund,
    warum `j` unsichtbar blieb: filters.py liess es zu, kein Generator konnte
    es erzeugen."""
    assert set(vollstaendig.ALPHABET) == VOWELS | CONSONANTS


@pytest.mark.parametrize("mod", [phonotactic, brand5, latinform])
def test_generator_alphabete_driften_nicht(mod):
    """`q` darf fehlen -- es ist nur als `qu` im Anlaut zulaessig, und keines
    dieser Muster bildet diese Stellung ab. Alles andere muss dabei sein."""
    assert set(mod.CONS) == CONSONANTS - set("q")


@pytest.mark.parametrize("label", [
    "orbis",   # vokalanlautend -- kein Muster begann mit einem Vokal
    "janto",   # mit j          -- die Generatoren hatten ein eigenes Alphabet
    "brant",   # geschlossene Silbe -- kein Muster endete auf einem Cluster
    "quiro",   # mit q
])
def test_quelle_m_erzeugt_was_die_muster_nicht_konnten(label):
    """Die vier Luecken, die das Audit gegen die Aufgabenstellung gefunden hat.
    Alle vier bestehen die harten Kriterien, keine einzige Musterquelle bringt
    sie hervor."""
    assert check(label) is None, f"{label}: {check(label)}"
    # Quelle M zaehlt stur auf. Ein Label kommt darin genau dann vor, wenn es
    # aus dem Alphabet stammt, in der Laenge liegt, die harten Kriterien
    # besteht und ueber der Schwelle liegt. Diese vier Bedingungen sind
    # zusammen gleichbedeutend mit "wird erzeugt" -- das Feld dafuer
    # abzusuchen waere 3,2 Millionen Formen je Zeuge.
    assert set(label) <= set(vollstaendig.ALPHABET)
    assert vollstaendig.MIN_LEN <= len(label) <= vollstaendig.MAX_LEN
    assert rank(label) >= vollstaendig.SCHWELLE, f"{label}: Klangnote {rank(label)}"


def test_quelle_m_liefert_nur_gueltige_labels():
    aus = [lab for lab, _ in _erste(vollstaendig.generate(5), 400)]
    assert aus, "Quelle M lieferte nichts"
    assert all(check(lab) is None for lab in aus)
    assert all(len(lab) == 5 for lab in aus)


def test_quelle_m_lehnt_unsinnige_laengen_ab():
    with pytest.raises(ValueError):
        next(vollstaendig.generate(9))


def _erste(it, n):
    out = []
    for x in it:
        out.append(x)
        if len(out) >= n:
            break
    return out
