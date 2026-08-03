# Betrieb

Alles laeuft ohne Fremdpakete. Gebraucht werden Python 3.11, PHP 8 mit der
Erweiterung `sodium` und ein Browser mit X25519 in der WebCrypto-API
(Chrome ab 133, Firefox ab 130, Safari ab 17). Node 22 wird nur fuer die
Tests des Web-Clients benutzt.

---

## Kurz und ganz

```bash
# 1. Alles pruefen
cd impl/python && python3 -m unittest discover -s tests -t .

# 2. Vorfuehrung in einem Prozess
python3 impl/python/demo.py

# 3. Zahlen des Whitepapers nachrechnen
python3 tools/berechnungen.py
```

Die Vorfuehrung braucht keinen Knoten und kein Netz.

---

## Einen Knoten betreiben

### Python

```bash
cd impl/python
python3 -m nyx.cli knoten --host 0.0.0.0 --port 8443 --pow 12
```

### PHP

```bash
NYX_DATA=/var/lib/nyx NYX_POW_BITS=12 \
  php -S 0.0.0.0:8080 -t impl/php/public
```

Hinter Apache oder nginx: `impl/php/public` als DocumentRoot, alle Anfragen
auf `index.php` leiten. Das Datenverzeichnis muss fuer den Webserver
schreibbar und fuer alle anderen unlesbar sein (`0700`).

| Variable | Vorgabe | Bedeutung |
|---|---|---|
| `NYX_NAME` | `php-knoten` | Name in `/v1/info` |
| `NYX_DATA` | `/tmp/nyx-store` | Datenverzeichnis |
| `NYX_TTL` | `604800` | Verfallszeit einer Ablage |
| `NYX_POW_BITS` | `12` | Arbeitsnachweis je Ablage |
| `NYX_BITS` | `12` | Schwierigkeit der Kette |

Ein Knoten braucht keine Datenbank, keinen Zustand zwischen Anfragen und
keine Anmeldung. Was er braucht, ist Plattenplatz und die Bereitschaft,
nach der Abholung zu loeschen.

---

## Terminal-Client

```bash
cd impl/python
export NYX_NODE=http://127.0.0.1:8443

python3 -m nyx.cli init          # Identitaet anlegen und ankuendigen
python3 -m nyx.cli wer           # eigene Adresse
python3 -m nyx.cli senden <adresse> "Text"
python3 -m nyx.cli holen
python3 -m nyx.cli pruefen <adresse>
```

Der Zustand liegt unter `~/.nyx` (mit `NYX_HOME` verlegbar): Identitaet,
private Prekeys, Zaehlerstaende. Nachrichten werden nicht gespeichert.

Zwei Teilnehmer auf einem Rechner:

```bash
NYX_HOME=/tmp/a python3 -m nyx.cli init
NYX_HOME=/tmp/b python3 -m nyx.cli init
NYX_HOME=/tmp/a python3 -m nyx.cli senden <adresse-von-b> "Hallo"
NYX_HOME=/tmp/b python3 -m nyx.cli holen
```

---

## Web-Client

Der Client besteht aus statischen Dateien und braucht keinen Bauschritt:

```bash
cd impl/web && python3 -m http.server 8080
```

Dann `http://127.0.0.1:8080` oeffnen und im Einrichtungsdialog die Adresse
eines Knotens eintragen.

Er laeuft auch von einem beliebigen Webspace — die Dateien sind statisch und
sprechen ausschliesslich ueber die Knoten-Schnittstelle. Ein `file://`-Aufruf
funktioniert nicht, weil ES-Module das nicht erlauben.

Selbsttest ausserhalb des Browsers:

```bash
node impl/web/selftest.mjs http://127.0.0.1:8443
```

---

## Interoperabilitaet pruefen

Der eigentliche Nachweis, dass die drei Fassungen dasselbe meinen:

```bash
# PHP-Knoten starten
NYX_DATA=/tmp/nyx-x NYX_POW_BITS=8 NYX_BITS=6 \
  php -S 127.0.0.1:8479 -t impl/php/public &

cd impl/python
NYX_PHP=http://127.0.0.1:8479 python3 -m unittest tests.test_interop
NYX_PHP=http://127.0.0.1:8479 python3 -m unittest tests.test_crosslang
```

`test_interop` vergleicht kanonisches JSON, Adressbildung, Signaturpruefung,
Merkle-Wurzeln und die punktierbare PRF zwischen Python und PHP, und faehrt
ein vollstaendiges Gespraech ueber PHP-Ablagen.

`test_crosslang` laesst den unveraenderten Web-Client unter Node eine
Nachricht schreiben und holt sie mit dem Python-Client ab — JavaScript ->
PHP -> Python.

Ohne laufenden Knoten werden diese Tests uebersprungen, nicht als
fehlgeschlagen gemeldet.

---

## Ein kleines Netz aufsetzen

Die Zerlegung ist auf k = 10 von n = 20 eingestellt. Damit die Rechnung aus
Whitepaper 10.3 gilt, braucht es zwanzig **unabhaengige** Knoten:
verschiedene Betreiber, verschiedene autonome Systeme, verschiedene
Rechtsraeume.

Gibt es weniger, laeuft das Protokoll weiter, aber ein Knoten haelt dann
mehrere Teile. Wer ihn beschlagnahmt, bekommt entsprechend mehr.
`DropNetwork.threshold_margin()` sagt, wie weit die Schwelle davon noch
traegt. Ein Wert unter 1,0 heisst: das Netz ist zu klein fuer die
angenommene Sicherheit.

---

## Was vor einem ernsthaften Betrieb zu tun ist

Siehe `sicherheitshinweis.md`. Die Kurzfassung: Primitive gegen libsodium
tauschen, Transport durch ein Mixnetz fuehren, Kettenschwierigkeit
hochsetzen, Identitaetsdatei verschluesseln, Knoten wirklich verteilen — und
den Code von jemandem pruefen lassen, der nicht daran beteiligt war.
