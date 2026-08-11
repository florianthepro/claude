"""Kalibrierung des Rankings."""

from domainfinder.scoring import breakdown, classify, score


def test_kalibrierung_aus_der_aufgabenstellung():
    """google und bitfabric sind Referenz fuer gut, ahefid fuer schlecht."""
    assert score("google") > score("ahefid")
    assert score("bitfabric") > score("ahefid")


def test_reale_produktnamen_liegen_deutlich_ueber_der_schlechten_referenz():
    schlecht = score("ahefid")
    for name in ("proxmox", "traefik", "grafana", "portainer", "komodo"):
        assert score(name) > schlecht, f"{name} sollte ueber ahefid liegen"


def test_immich_ist_ein_bekannter_ausreisser():
    """Immich steht in der Aufgabe als Vorbild, verletzt aber die harten
    Kriterien gleich dreifach: Vokalanlaut, Doppelkonsonant, Auslaut -ch.
    Der Scorer straft genau diese Merkmale ab -- das ist gewollt und wird hier
    festgehalten, damit es niemand fuer einen Fehler haelt."""
    from domainfinder.filters import check
    assert check("immich").rule == "alphabet"      # c
    assert score("immich") < score("komodo")


def test_buchstabenarmut_und_monotonie_kosten():
    assert score("huhuhu") < score("bitrot")
    assert score("bababa") < score("granot")


def test_morphem_mit_angeklebtem_buchstaben_wird_gedeckelt():
    """k+inode ist ein Baukastenteil, kein eigenstaendiges Wort."""
    assert breakdown("kinode")["morpheme"] <= 0.55


def test_penalty_fuer_dehnungs_h_und_vokalanlaut():
    assert breakdown("ahefid")["penalty"] > 0
    assert breakdown("bitrot")["penalty"] == 0


def test_klassifikation():
    assert classify("softfail", "C") == "fachwitz"
    assert classify("purlink", "B") in {"it", "marke"}
    assert classify("bidoka", "A") in {"name", "zufall"}


def test_quellenbonus_hebt_den_fachwitz_ohne_die_kalibrierung_zu_stoeren():
    """badsig ist ein echter DNS-Rcode -- das sieht der phonetische Teil nicht."""
    assert score("badsig", "C") > score("badsig")
    assert score("badsig", "C") - score("badsig") == 12.0
    assert score("google") > score("ahefid")      # Referenzen haben keine Quelle
    assert score("mastud", "B") == score("mastud")


def test_startup_rangordnung_bevorzugt_weiche_binnencluster():
    """Senf und Wurf sind diktiersicher, klingen als Marke aber hart."""
    from domainfinder.startup import rank
    assert rank("gusto") > rank("punfo")     # st schlaegt nf
    assert rank("kanto") > rank("kilfa")     # nt schlaegt lf
    assert min(rank("gusto"), rank("figma")) > max(rank("gokna"), rank("dumfa"))


def test_beide_rangordnungen_bleiben_getrennt():
    """startup darf die auf Proxmox kalibrierte Skala nicht veraendern."""
    from domainfinder.startup import rank
    assert score("google") > score("ahefid")
    assert rank("gusto") > rank("ahefid")
    assert score("gusto") != rank("gusto")


def test_module_lassen_sich_einzeln_importieren():
    """gen/brand5 -> startup -> scoring -> gen/__init__ -> gen/brand5 war ein
    Ringschluss. Er blieb verborgen, weil die CLI zufaellig in der richtigen
    Reihenfolge importiert."""
    import subprocess
    import sys
    for modul in ("domainfinder.startup", "domainfinder.gen.brand5",
                  "domainfinder.scoring", "domainfinder.filters"):
        r = subprocess.run([sys.executable, "-c", f"import {modul}"],
                           capture_output=True, text=True)
        assert r.returncode == 0, f"{modul} einzeln importiert: {r.stderr}"
