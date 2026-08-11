"""Zustand, Wiederaufnahme, Auswahl, Ausgabe, Secrets -- alles ohne Netz."""

import stat

import pytest

from domainfinder.config import ConfigError, cloudflare_creds, load_env_file, redact
from domainfinder.db import Store
from domainfinder.filters import passes
from domainfinder.gen import jargon, morpheme, phonotactic
from domainfinder.net import RateLimiter, _backoff
from domainfinder.output import write_rejections, write_results
from domainfinder.select import cap


@pytest.fixture()
def store(tmp_path):
    s = Store(tmp_path / "state.sqlite3")
    yield s
    s.close()


def _seed(store, n=3):
    store.add_candidates([(f"lab{i}.com", f"lab{i}", "com", "A", "zufall", 90.0 - i)
                          for i in range(n)])


# --- Wiederaufnahme ----------------------------------------------------------
def test_pending_liefert_nur_ungeprueftes(store):
    _seed(store)
    assert len(store.pending("dns", "com")) == 3
    store.record("lab0.com", "dns", "free", "NXDOMAIN")
    store.commit()
    rest = [r["domain"] for r in store.pending("dns", "com")]
    assert "lab0.com" not in rest and len(rest) == 2


def test_stufe_prueft_nichts_doppelt_nach_abbruch(store):
    _seed(store, 5)
    for d in ("lab0.com", "lab1.com"):
        store.record(d, "dns", "free", "NXDOMAIN")
    store.commit()
    # Simulierter Neustart: dieselbe Datenbank, neue Abfrage.
    assert len(store.pending("dns", "com")) == 3


def test_naechste_stufe_bekommt_nur_freie_der_vorstufe(store):
    _seed(store, 3)
    store.record("lab0.com", "dns", "free", "NXDOMAIN")
    store.record("lab1.com", "dns", "taken", "NS delegiert")
    store.commit()
    weiter = [r["domain"] for r in store.pending("rdap", "com", after_stage="dns")]
    assert weiter == ["lab0.com"]


def test_ergebnis_wird_ueberschrieben_nicht_verdoppelt(store):
    _seed(store, 1)
    store.record("lab0.com", "dns", "unknown", "timeout")
    store.record("lab0.com", "dns", "free", "NXDOMAIN")
    store.commit()
    assert store.counts("com")["dns"] == {"free": 1}


def test_preis_und_tier_ueberleben_den_schreibvorgang(store):
    _seed(store, 1)
    store.record("lab0.com", "registrar", "free", "", 10.44, "USD", "standard")
    store.commit()
    row = store.results("com", "registrar", "free")[0]
    assert (row["price"], row["currency"], row["tier"]) == (10.44, "USD", "standard")


# --- Auswahl -----------------------------------------------------------------
def test_vielfaltsgrenze_bricht_monokulturen_auf():
    items = [(90.0 - i, lab, None) for i, lab in enumerate(
        ["kinode", "ginode", "binode", "tinode", "sinode", "finode", "granot"])]
    out = [lab for _, lab, _ in cap(items, per_morpheme=3)]
    assert out.count("kinode") == 1
    assert len([x for x in out if x.endswith("inode")]) <= 3
    assert "granot" in out


def test_vielfaltsgrenze_haelt_die_reihenfolge():
    items = [(90.0 - i, f"bado{c}", None) for i, c in enumerate("bdfgh")]
    out = [lab for _, lab, _ in cap(items)]
    assert out == sorted(out, key=lambda x: -dict(zip([f"bado{c}" for c in "bdfgh"],
                                                      range(90, 85, -1)))[x])


# --- Generatoren -------------------------------------------------------------
def test_generatoren_liefern_ausschliesslich_gueltige_labels():
    for label, _ in jargon.generate():
        # Quelle C darf ein h an der Morphemfuge tragen (sig|hup).
        assert passes(label, compound=True), f"Quelle C liefert ungueltiges Label {label}"
    for i, (label, _) in enumerate(morpheme.generate()):
        assert passes(label), f"Quelle B liefert ungueltiges Label {label}"
        if i > 2000:
            break
    gen = phonotactic.generate()
    for i, label in enumerate(gen):
        assert passes(label), f"Quelle A liefert ungueltiges Label {label}"
        if i > 2000:
            break


def test_quelle_b_verwirft_reines_aneinanderhaengen():
    """port+host darf nicht als 'porthost' durchgehen."""
    labels = {lab for lab, _ in morpheme.generate()}
    assert "porthost" not in labels
    assert "hostnode" not in labels


def test_quelle_c_enthaelt_nur_echte_fachbegriffe():
    labels = {lab for lab, _ in jargon.generate()}
    assert {"bitrot", "notimp", "badalg", "tarpit", "sighup"} <= labels


# --- Ausgabe -----------------------------------------------------------------
def test_csv_hat_die_geforderten_spalten_und_modus_0600(store, tmp_path):
    _seed(store, 2)
    store.record("lab0.com", "registrar", "free", "", 10.44, "USD", "standard")
    store.record("lab1.com", "registrar", "taken", "already registered")
    store.commit()
    out = tmp_path / "ergebnis.csv"
    n = write_results(store, "com", out, also_stdout=False)
    assert n == 1
    head = out.read_text().splitlines()[0]
    assert head == "domain,registrierbar,preis,waehrung,tier,grund,score,kategorie,quelle"
    assert stat.S_IMODE(out.stat().st_mode) == 0o600


def test_nur_bestaetigte_domains_landen_in_der_ausgabe(store, tmp_path):
    _seed(store, 2)
    store.record("lab0.com", "registrar", "taken", "premium")
    store.record("lab1.com", "registrar", "free", "")
    store.commit()
    out = tmp_path / "e.csv"
    write_results(store, "com", out, also_stdout=False)
    body = out.read_text()
    assert "lab1.com" in body and "lab0.com" not in body


def test_jede_ablehnung_wird_mit_grund_protokolliert(store, tmp_path):
    _seed(store, 2)
    store.record("lab0.com", "registrar", "taken", "already registered")
    store.record("lab1.com", "registrar", "error", "API: rate limited")
    store.commit()
    rej = tmp_path / "abgelehnt.csv"
    assert write_rejections(store, "com", rej) == 2
    body = rej.read_text()
    assert "already registered" in body and "rate limited" in body


# --- Secrets und Netz --------------------------------------------------------
def test_secret_datei_mit_falschem_modus_wird_abgelehnt(tmp_path):
    f = tmp_path / "secrets.env"
    f.write_text("CLOUDFLARE_API_TOKEN=geheim\n")
    f.chmod(0o644)
    with pytest.raises(ConfigError, match="0600"):
        load_env_file(f)


def test_secret_datei_mit_modus_0600_wird_geladen(tmp_path, monkeypatch):
    monkeypatch.delenv("CLOUDFLARE_API_TOKEN", raising=False)
    monkeypatch.delenv("CLOUDFLARE_ACCOUNT_ID", raising=False)
    f = tmp_path / "secrets.env"
    f.write_text("CLOUDFLARE_ACCOUNT_ID=abc\nexport CLOUDFLARE_API_TOKEN=\"geheim\"\n")
    f.chmod(0o600)
    assert load_env_file(f) == 2
    creds = cloudflare_creds()
    assert creds.account_id == "abc"


def test_token_taucht_in_keiner_darstellung_auf(monkeypatch):
    monkeypatch.setenv("CLOUDFLARE_ACCOUNT_ID", "konto")
    monkeypatch.setenv("CLOUDFLARE_API_TOKEN", "streng-geheimes-token")
    creds = cloudflare_creds()
    assert "streng-geheimes-token" not in repr(creds)
    assert "streng-geheimes-token" not in str(creds)
    assert "streng-geheimes-token" not in redact(creds.token)


def test_fehlende_zugangsdaten_sind_optional(monkeypatch):
    monkeypatch.delenv("CLOUDFLARE_API_TOKEN", raising=False)
    monkeypatch.delenv("CF_API_TOKEN", raising=False)
    assert cloudflare_creds(required=False) is None
    with pytest.raises(ConfigError):
        cloudflare_creds(required=True)


def test_ratelimiter_bremst_tatsaechlich():
    import time
    limiter = RateLimiter(20.0, burst=1)
    t0 = time.monotonic()
    for _ in range(5):
        limiter.acquire()
    assert time.monotonic() - t0 >= 0.15


def test_backoff_waechst_exponentiell_und_achtet_retry_after():
    assert _backoff(0, None) < _backoff(4, None)
    assert _backoff(0, "7") == 7.0
    assert _backoff(0, "600") == 60.0  # gedeckelt


def test_refresh_wirft_teure_pruefergebnisse_nicht_weg(store):
    """Ein Filter darf eine Tatsache ueber die Welt nicht loeschen."""
    _seed(store, 2)
    store.record("lab0.com", "dns", "free", "NXDOMAIN")
    store.record("lab0.com", "rdap", "free", "RDAP 404")
    store.commit()
    store.drop_candidates(["lab0.com"])
    # Kandidat weg ...
    assert [r["domain"] for r in store.all_candidates("com")] == ["lab1.com"]
    # ... Pruefergebnis bleibt, damit ein spaeteres Wiederaufnehmen nichts kostet.
    store.add_candidates([("lab0.com", "lab0", "com", "A", "zufall", 90.0)])
    assert store.pending("dns", "com") == [] or \
        "lab0.com" not in [r["domain"] for r in store.pending("dns", "com")]


def test_quelle_d_haelt_die_wurzel_am_stueck_und_am_rand():
    """Eine zerstueckelte Wurzel ist kein IT-Bezug mehr."""
    from domainfinder.gen import itroot
    for label, herkunft in itroot.generate():
        assert len(label) == 5, f"{label} ist kein Fuenfzeichner"
        assert passes(label), f"{label} besteht die harten Kriterien nicht"
        assert itroot.roots_in(label), f"{label} traegt keine Wurzel am Rand"
        assert "steht vorn" in herkunft or "steht hinten" in herkunft


def test_refresh_kennt_alle_rangordnungen():
    """refresh darf eine Skala nicht mit den Werten einer anderen ueberschreiben."""
    from domainfinder.cli import RANKERS
    from domainfinder.startup import rank as startup_rank
    from domainfinder.vertraut import rank as vertraut_rank
    assert set(RANKERS) == {"infra", "startup", "vertraut"}
    assert RANKERS["startup"]("gusto") == startup_rank("gusto")
    assert RANKERS["vertraut"]("gusto") == vertraut_rank("gusto")
    assert len({RANKERS[k]("gusto") for k in RANKERS}) == 3


def test_vielfaltsgrenze_greift_auch_bei_fuenfzeichnern():
    """Bei einem Fuenfzeichner ist ein Vier-Zeichen-Fenster fast das ganze Wort.
    Ohne Konsonantengeruest bestand die Spitze aus gukra/gikre/gakre/pukre."""
    from domainfinder.select import skeleton
    assert skeleton("gukra") == skeleton("gikre") == skeleton("gakre") == "gkr"
    items = [(90.0 - i, lab, None) for i, lab in enumerate(
        ["gukra", "gikre", "gakre", "gukre", "gakri", "minta"])]
    out = [lab for _, lab, _ in cap(items)]
    assert len([x for x in out if skeleton(x) == "gkr"]) <= 3
    assert "minta" in out


def test_komposita_bleiben_einsprachig():
    """forge+stern liest ein Deutscher als 'for gestern'. Ein Kompositum aus
    zwei Sprachen klingt genau so fremd wie eine erfundene Silbe."""
    from domainfinder.gen import compound
    for erzeuger in (compound.generate, compound.generate_it):
        for label, herkunft in erzeuger():
            assert herkunft.endswith("(deutsch)") or herkunft.endswith("(englisch)")
    alle = {lab for lab, _ in compound.generate()}
    assert "forgestern" not in alle and "domestern" not in alle
    assert "platestern" not in alle


def test_komposita_pruefen_mit_bekannter_wortfuge():
    """An der Wortfuge treffen Coda und Onset zweier Woerter aufeinander.
    `daten|strom` ergibt `nstr` -- vier Konsonanten, die es innerhalb eines
    Morphems nie gibt, die aber niemand falsch liest."""
    from domainfinder.filters import check
    assert check("datenstrom") is not None                # als erfundenes Wort
    assert check("datenstrom", compound=True) is None     # als Kompositum
    assert check("bitstrom", compound=True) is None
    # `pf` ist ein echter deutscher Anlaut, datenpfad braucht die Fuge nicht.
    assert check("datenpfad") is None


def test_markennamen_haben_keine_vokalhaufen_an_der_fuge():
    """stro+era, geo+ina, nimbu+io waren die Spitze der ersten Fassung."""
    from domainfinder.gen import branding
    alle = {lab for lab, _ in branding.generate()}
    for schlecht in ("stroera", "geoina", "nimbuio", "stoaera"):
        assert schlecht not in alle, f"Vokalhaufen: {schlecht}"
    # Und der Stamm muss erkennbar bleiben: gasket -> gask -> gaskio war zu viel.
    assert "gaskio" not in alle
    assert "granita" in alle and "pfada" in alle


def test_serioese_namen_bleiben_einsprachig():
    """nodekontor mischt Englisch und Deutsch und klingt deshalb schief."""
    from domainfinder.gen import serioes
    alle = {lab for lab, _ in serioes.generate()}
    for gemischt in ("nodekontor", "gridkontor", "linktresor", "datafundus"):
        assert gemischt not in alle, f"{gemischt} mischt zwei Sprachen"
    assert "logkontor" in alle and "kerndepot" in alle and "datenakte" in alle
