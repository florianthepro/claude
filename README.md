# domainfinder

Findet Domainnamen, die tatsaechlich registrierbar sind. Keine Vorschlaege,
keine Vermutungen: jeder Name in der Endausgabe ist maschinell geprueft, und zu
jedem abgelehnten Namen steht der Grund in einer CSV.

Python 3.11+, **keine Abhaengigkeiten** ausser der Standardbibliothek.

```bash
python3 -m venv .venv && . .venv/bin/activate
pip install -e .

domainfinder run --tld com                       # kompletter Trichter
domainfinder run --alt-tlds                      # zweiter Durchlauf
domainfinder recheck meinname.com                # unmittelbar vor dem Kauf
```

## Der Trichter

Jede Stufe verkleinert die Menge fuer die naechste, teurere Stufe. Jedes
Ergebnis geht sofort nach SQLite, deshalb ist ein Lauf jederzeit abbrechbar und
prueft danach nichts doppelt.

| Stufe | Quelle | Aussage | Kosten |
|---|---|---|---|
| 0 | ICANN CZDS Zonendatei | delegiert oder nicht | lokale Mengendifferenz, optional |
| 1 | DoH, Cloudflare und Google | NS vorhanden -> sicher vergeben | 32 Worker, ~60/s |
| 2 | RDAP, Verisign bzw. IANA-Bootstrap | 200 registriert, 404 nicht im Registry | ~3,5/s, 429 mit Backoff |
| 3 | Cloudflare Registrar | `registrable` true/false | 20 Domains je Anfrage |

**Stufe 2 ist nicht Stufe 3.** RDAP 404 heisst *nicht im Registry*. Ob Cloudflare
den Namen auch verkauft, beantwortet allein Stufe 3. Laeuft der Trichter ohne
Zugangsdaten, sagt das Werkzeug das im Log und in der Zusammenfassung.

Stufe 0 wird uebersprungen, wenn keine Zonendatei vorliegt. Mit CZDS-Zugang:

```bash
domainfinder run --tld com --zonefile ~/czds/com.zone.gz
```

## Zugangsdaten

Ausschliesslich aus Umgebungsvariablen. Optional aus einer Datei, die Modus
0600 haben muss -- sonst bricht der Lauf ab:

```bash
install -m 600 /dev/null ~/.config/domainfinder/secrets.env
cat > ~/.config/domainfinder/secrets.env <<'EOF'
CLOUDFLARE_ACCOUNT_ID=...
CLOUDFLARE_API_TOKEN=...
EOF
```

Der Token steht nie in einem Argument, einem Log oder einer Fehlermeldung.
`CloudflareCreds` hat ein eigenes `__repr__`, `HttpError` traegt nie Header.
Das Konto braucht ausserdem einen hinterlegten Registrant-Kontakt und das
akzeptierte Domain Registration Agreement unter
`dash.cloudflare.com/<ACCOUNT_ID>/domains/registration`.

## Kandidaten

Drei unabhaengige Quellen, getrennt gehalten, getrennt gedeckelt.

**A -- phonotaktische Vollaufzaehlung.** Vollstaendige Enumeration ueber
`bdfghklmnprst` und `aeiou` in den Mustern CVCVC, CVCCV, CCVCV, CVCVCV. Rund
349 000 Formen bestehen die harten Kriterien.

**B -- semantische Komposita.** Bau-, Schiffs- und Geologievokabular plus
IT-Morpheme. Verschmolzen wird nur ueber Ueberlappung (`purlin`+`hardlink` ->
`purlink`) oder Silbenblende. Reines Aneinanderhaengen zweier vollstaendiger
Morpheme wird verworfen -- genau das sieht nach Praefix mal Suffix aus.

**C -- selbstreferenzielle Fachbegriffe.** Nur Begriffe, die in einem Standard
wirklich so heissen: DNS-Rcodes (RFC 1035), SMTP und Enhanced Status (RFC 3463,
5321), errno (POSIX), SPF und DMARC (RFC 7208, 7489), HTTP-Statustexte,
Signalnamen, BGP- und TCP-Zustaende. Dazu die kanonischen Kurzformen -- errno
ohne fuehrendes `e`, Signale ohne `sig`. Es wird nichts frei kombiniert.

### Vielfaltsgrenze

Ein reiner Score-Schnitt liefert Monokulturen: `kinode, ginode, binode, tinode`.
Das ist der Baukasten-Eindruck, der eine Liste als generiert entlarvt. Deshalb
deckelt `select.py` pro Familie -- gemeinsames Morphem, gemeinsamer Anfang,
gemeinsamer Reim.

## Harte Kriterien

Ausschlusskriterien, keine Bewertung. Alles in `filters.py`, jede Regel hat in
`tests/test_filters.py` einen Zeugen.

* `.com` als Label hoechstens 10 Zeichen, Optimum 5 bis 8, keine Ziffern, keine
  Bindestriche.
* Alphabet `bdfghklmnprst` + `aeiou`. Verboten: `c` (c/k-Konflikt), `v` und `w`
  (deutsch w = /v/, v = /f/), `y` als Vokal, `z`, dazu `j` (/j/ vs /dʒ/),
  `q` (`qu` = /kv/ vs /kw/) und `x` (von `ks` nicht zu unterscheiden).
* Verbotene Folgen: `th`, `ph`, `ee`, `ea`, `ie`, dazu `ei`/`ai` (beide /aɪ/),
  `eu`, `ou`, `oo`, `sh`, `ck`, `dt`.
* Keine Verdopplung -- `bb`, `ll`, `ss` sind im Deutschen optional hoerbar gleich.
* `h` nur als Silbenanlaut. `bahnhof` und `floh` fallen (Dehnungs-h),
  `sighup` bleibt (sig-hup).
* Konsonantencluster nur aus zugelassenen Anlaut- und Auslautmengen,
  hoechstens drei Silben.
* Substring-Blacklist deutsch und englisch. Zusaetzlich eine weiche Liste, die
  nur Punkte kostet. `fail` und `bug` stehen bewusst **nicht** darauf: der
  Fachwitz aus Quelle C lebt davon, und `.fail` ist eine der Ziel-TLDs.

## Bewertung

`scoring.py` gewichtet n-Gramm-Plausibilitaet (0,34), Morphem-Erkennbarkeit
(0,24), Anlaut- und Auslautstaerke (0,13), Silbenrhythmus (0,12), Buchstaben-
und Vokalvielfalt (0,10) und Laenge (0,07), abzueglich Strafen fuer End-h,
Dehnungs-h, Vokalanlaut, Buchstabenarmut und weiche Blacklist.

Die geforderte Kalibrierung steht als Test:

```
score("google") > score("ahefid")        60,87 > 43,69
score("bitfabric") > score("ahefid")     59,48 > 43,69
```

Zur Einordnung: `portainer` 80,5, `traefik` 68,1, `grafana` 63,4, `komodo` 62,8.
`immich` faellt mit 38,9 durch -- es verletzt die harten Kriterien dreifach
(Vokalanlaut, Doppelkonsonant, `ch`). Das ist gewollt und in
`tests/test_scoring.py` festgehalten, damit es niemand fuer einen Fehler haelt.

Ein Morphem mit ein, zwei angeklebten Buchstaben (`k`+`inode`) wird gedeckelt:
das ist ein Baukastenteil, kein eigenstaendiges Wort.

```bash
domainfinder score bitrot purlink ahefid     # Score aufgeschluesselt
```

Der Score sortiert 349 000 Kandidaten zu einer Auswahlliste. Er ersetzt das
Urteil nicht: ob ein Name auf einer Rechnung tragt, entscheidet ein Mensch am
Ende der Liste, nicht die Gewichtung.

## Filter nachziehen

Die harten Kriterien wachsen mit jedem Lauf -- eine Marke, eine deutsche
Peinlichkeit, eine englische Schreibung, die vorher niemand bedacht hat:

```bash
domainfinder refresh --tld com     # aktuelle Filter auf den Bestand anwenden
```

Kandidaten, die durchfallen, verschwinden aus der Auswahl. Ihre
Pruefergebnisse bleiben in der Datenbank: ein Ergebnis ist eine Tatsache ueber
die Welt, ein Filter ist unsere Meinung. Wird die Meinung spaeter wieder
korrigiert, kostet das keine einzige Netzanfrage.

## Ausgabe

`out/ergebnis-<tld>.csv`, absteigend nach Score, Modus 0600:

```
domain, registrierbar, preis, waehrung, tier, grund, score, kategorie, quelle
```

`quelle` ist A, B oder C, `kategorie` eine Einordnung wie `it`, `marke`, `name`,
`fachwitz`, `zufall`. Daneben `out/abgelehnt-<tld>.csv` mit dem `reason`-Feld
jeder abgelehnten Domain.

## Betrieb

Rate-Limits und Worker sind je Stufe einstellbar (`--dns-rate`, `--rdap-rate`,
`--registrar-rate`, `--dns-workers`, `--rdap-workers`). Bei 429 und 5xx greift
exponentieller Backoff mit Jitter, `Retry-After` wird beachtet, und ein 429
bremst ueber den gemeinsamen Token-Bucket alle Worker zugleich. TLS wird
geprueft, jede Anfrage hat ein Timeout. Fortschritt geht auf stderr, Ergebnisse
auf stdout und in die CSV.

Abbruch mit Strg-C ist folgenlos: derselbe Aufruf setzt fort und prueft nichts
doppelt.

## Vor dem Kauf

Verfuegbarkeit ist fluechtig. Das Ergebnis von gestern ist eine Vermutung:

```bash
domainfinder recheck meinname.com
```

Exit-Code 0, wenn mindestens ein Name registrierbar ist, sonst 1.

## Tests

```bash
python3 -m pytest tests/ -q      # 94 Tests, ohne Netz
```
