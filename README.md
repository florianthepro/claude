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

## Ergebnis

[`ergebnis/fuenfzeichner-com.csv`](ergebnis/fuenfzeichner-com.csv) -- 28 freie
Fuenfzeichner, jeder per RDAP geprueft. Gesucht war ein Fantasiewort,
hoechstens fuenf Zeichen, serioes, klangsicher, in erster Linie schoen.

| Domain | Klangnote | warum |
|---|---|---|
| `pelja.com` | 92,3 | Zweisilbig, Katja/Sonja-Muster -- einmal hoeren, richtig schreiben |
| `gelja.com` | 92,3 | Weich, zweisilbig, unverbraucht |
| `ritja.com` | 91,7 | Harter Anlaut, weicher Schluss |
| `hirja.com` | 91,7 | Klarer Wortkoerper, offener Schluss |
| `nulja.com` | 91,1 | Fuer den IT-Leser steckt `null` darin, ohne dass der Name danach schreit |
| `iljon.com` | 89,4 | Vokalanlaut wie Okta und Orbis |
| `otjan.com` | 89,4 | Vokalanlaut, nuechterner Konsonantenschluss |
| `iljor.com` | 89,4 | `-or` wie Motor, Sensor, Reaktor |
| `galjo.com` | 88,8 | Offener o-Schluss |
| `reljo.com` | 88,2 | Gleichmaessiger Fluss |

Zwei Befunde gehoeren dazu, weil sie das Ergebnis erklaeren:

* **Die Spitze ist vergeben.** Von den 1918 bestbewerteten Fuenfzeichnern sind
  45 frei -- 2,3 %. Wer bei fuenf Zeichen sucht, waehlt nicht aus dem
  Schoensten, sondern aus dem Schoensten, was uebrig ist.
* **Wo etwas frei ist, liegt es an einem seltenen Buchstaben.** Bei Namen mit
  `j` sind 14,8 % frei, im uebrigen Raum 1,1 %. Deshalb klingt die Liste
  baltisch-slawisch -- dort ist der unbesetzte Raum.

Verfuegbarkeit ist fluechtig: `kilge.com` war bei einer Pruefung frei und wurde
am 11.08.2026 um 07:45:52Z registriert, Minuten spaeter. Vor dem Kauf immer
`domainfinder recheck`.

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
Zugangsdaten, sagt das Werkzeug das im Log und in der Zusammenfassung -- die
Ergebnisdatei oben ist deshalb mit `registrierbar=wahrscheinlich` ausgewiesen,
nicht mit `ja`.

**Bei kurzen Labels taugt Stufe 1 als Vorsieb nichts.** Gemessen: 20 von 20
handverlesenen Fuenfzeichnern ohne NS-Eintrag waren im Registry vergeben --
attraktive kurze Namen werden geparkt, nicht delegiert. Ab fuenf Zeichen also
direkt Stufe 2.

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

## Harte Kriterien

Ausschlusskriterien, keine Bewertung. Alles in `filters.py`, jede Regel hat in
`tests/test_filters.py` einen Zeugen.

Der Massstab ist **der deutschsprachige Hoerer**: er muss den Namen nach
einmaligem Hoeren richtig schreiben koennen. Eine Regel, die sich auf englische
Aussprache stuetzt, ist damit nicht gedeckt.

* Label hoechstens 10 Zeichen, Optimum 5 bis 8, keine Ziffern, keine
  Bindestriche.
* Alphabet `bdfghjklmnpqrst` + `aeiou`. Verboten: `c` (c/k-Konflikt), `v` und
  `w`, `y` als Vokal, `z` -- alle vier ausdruecklich in der Aufgabe genannt --
  sowie `x`, weil `/ks/` hoerbar nicht von `ks` zu unterscheiden ist
  (Hexe/Hekse) und `ks` als Cluster zugelassen bleibt.
* `j` nur am Silbenanfang vor einem Vokal (`ja`, `Sonja`, `Katja`). Zwischen
  zwei Vokalen konkurriert es mit `i` und `y` (Maja/Maia/Maya) und faellt.
* `q` nur im Anlaut als `qu` + Vokal. Im Wortinneren stuende an einer Fuge auch
  `kw` zur Wahl (rueckwaerts, Backware).
* Verbotene Folgen: `th`, `ph`, `ee`, `ea`, `ie`, `ai`, `ou`, `oo`, `sh`, `ck`,
  `dt` sowie `ae`, `oe`, `ue` -- das sind die Ersatzschreibungen der Umlaute.
  `ei` und `eu` sind erlaubt: fuer `/aɪ/` und `/ɔʏ/` ist die Normalschreibung
  eindeutig, sobald die Ausnahmeschreibung `ai` gesperrt ist.
* Keine Verdopplung -- `bb`, `ll`, `ss` sind im Deutschen optional hoerbar gleich.
* `h` nur als Silbenanlaut oder nach einem Sonoranten (Anhalt, erholen,
  Wilhelm). `bahnhof` und `floh` fallen (Dehnungs-h), `sighup` bleibt als
  Kompositum (sig-hup).
* Konsonantencluster nur aus zugelassenen Anlaut-, Binnen- und Auslautmengen,
  hoechstens drei Silben.
* Substring-Blacklist deutsch und englisch. Kurze Lautfragmente (`fik`, `pus`,
  `dik` ...) greifen nur am Wortanfang, vierzeichige Marken nur als ganzes
  Label -- sonst sterben `basis`, `titan`, `kanal`, `klang`, `audio`. Dazu eine
  weiche Liste, die nur Punkte kostet. `fail` und `bug` stehen bewusst **nicht**
  darauf: der Fachwitz aus Quelle C lebt davon, und `.fail` ist Ziel-TLD.

### Korrekturen gegen die Aufgabenstellung

Ein Audit der Filter gegen die Aufgabenstellung hat neun Regeln gefunden, die
strenger waren als gefordert und dadurch brauchbare Namen verworfen haben. Sie
stehen hier, weil sie erklaeren, warum ein frueherer Lauf "fuenf Zeichen sind
vergeben" gemeldet hat -- das war ein Artefakt der eigenen Filter, kein Befund
ueber den Namensraum.

| Regel | war | ist |
|---|---|---|
| `j` | gesperrt, begruendet mit englisch `/dʒ/` | erlaubt am Silbenanfang |
| `q` | gesperrt, begruendet mit Nutzen statt Diktat | erlaubt als `qu` im Anlaut |
| `ei`, `eu` | gesperrt, standen nie in der Vorgabe | erlaubt |
| `ae`, `oe`, `ue` | erlaubt | gesperrt, Umlaut-Ersatzschreibung |
| `klan`, `asi`, `tit`, `sau` als Teilstring | toeteten `klang`, `basis`, `titan`, `sauna` | gestrichen oder verankert |
| `audi`, `aldi`, `opel` als Teilstring | toeteten `audio`, `baldi`, `kopel` | nur als ganzes Label |
| `nr`, `nm`, `nl`, `sb`, `sg`, `sd` | fehlten | erlaubt (Anruf, Anmut, Ausbau) |
| `pf`, `nkt`, `rkt`, `nft` im Auslaut | fehlten | erlaubt (Kopf, Punkt, Markt, sanft) |
| `h` im Binnencluster | immer verworfen | nach Sonorant erlaubt |

Wirkung, gemessen: der Raum gueltiger, klanglich bewerteter Fuenfzeichner
waechst von 17 045 auf 85 013.

## Kandidaten

Dreizehn unabhaengige Quellen, getrennt gehalten, getrennt gedeckelt. Die
wichtigsten:

**A -- phonotaktische Vollaufzaehlung** ueber die Muster CVCVC, CVCCV, CCVCV,
CVCVCV.

**B -- semantische Komposita.** Verschmolzen wird nur ueber Ueberlappung
(`purlin`+`hardlink` -> `purlink`) oder Silbenblende. Reines Aneinanderhaengen
zweier vollstaendiger Morpheme wird verworfen -- genau das sieht nach Praefix
mal Suffix aus.

**C -- selbstreferenzielle Fachbegriffe.** Nur Begriffe, die in einem Standard
wirklich so heissen: DNS-Rcodes (RFC 1035), SMTP und Enhanced Status (RFC 3463,
5321), errno (POSIX), SPF und DMARC (RFC 7208, 7489), HTTP-Statustexte,
Signalnamen, BGP- und TCP-Zustaende. Es wird nichts frei kombiniert.

**M -- vollstaendige Aufzaehlung ohne Muster.** Jede Kombination der erlaubten
Buchstaben in einer festen Laenge. Bei fuenf Zeichen sind das 20⁵ = 3,2
Millionen Rohformen. Diese Quelle gibt es, weil die Muster selbst der schaerfste
Filter im Werkzeug waren: `orbis` (Vokalanlaut), `janto` (`j`), `brant`
(geschlossene Silbe) und `quiro` (`q`) bestehen alle harten Kriterien, aber
keine Musterquelle bringt sie hervor.

```bash
domainfinder generate --tld com --only-length 5 --limit-m 2000 --rank schoen
```

Die Generatoren lesen ihr Alphabet aus `filters.py`, statt es zu wiederholen.
Das war der Grund, warum `j` unsichtbar blieb: der Filter liess es zu, kein
Generator konnte es erzeugen. `tests/test_schoenheit.py` haelt das fest.

### Vielfaltsgrenze

Ein reiner Score-Schnitt liefert Monokulturen: `kinode, ginode, binode, tinode`.
Das ist der Baukasten-Eindruck, der eine Liste als generiert entlarvt. Deshalb
deckelt `select.py` pro Familie -- gemeinsames Morphem, gemeinsamer Anfang,
gemeinsamer Reim, gemeinsames Konsonantenskelett.

Der Deckel kann sich gegen einen wenden: mit einem alphabetischen
Stichentscheid bei Punktgleichheit haelt er je Skelett den Vertreter mit dem
"groessten" Vokal fest und wirft alle anderen als Dublette weg. In einem Lauf
fingen dadurch alle 709 gefundenen Namen mit `u` an. Wer nach Rang auswaehlt,
muss die Faecherung deshalb ueber den Anfangsbuchstaben legen.

## Bewertung

Vier Rangordnungen, bewusst nicht verrechnet. Was fuer eine Homelab-Basisdomain
traegt, traegt nicht fuer eine Marke. `--rank` entscheidet, welche die
`score`-Spalte fuellt.

| Rangordnung | Massstab | Modul |
|---|---|---|
| `infra` | Proxmox, Traefik, Komodo | `scoring.py` |
| `startup` | Figma, Gusto | `startup.py` |
| `vertraut` | hafen, nadel, riegel | `vertraut.py` |
| `schoen` | Prisma, Vanta, Solana, Okta | `schoenheit.py` |

Die geforderte Kalibrierung von `infra` steht als Test:

```
score("google") > score("ahefid")        60,87 > 43,69
score("bitfabric") > score("ahefid")     59,48 > 43,69
```

Zur Einordnung: `portainer` 80,5, `traefik` 68,1, `grafana` 63,4, `komodo` 62,8.
`immich` faellt mit 38,9 durch -- es verletzt die harten Kriterien dreifach
(Vokalanlaut, Doppelkonsonant, `ch`). Das ist gewollt und in
`tests/test_scoring.py` festgehalten, damit es niemand fuer einen Fehler haelt.

`schoenheit.py` gewichtet Vokalgefaelle (0,30), Auslaut (0,29), Kontrast aus
Liquid und Plosiv (0,23) und das Gelenk in der Wortmitte (0,18), mal einem
Abzug, wenn kein Liquid vorkommt. Kalibrierung: `prisma` 99,4 > `pelja` 92,3 >
`vanta` 83,3 > `okta` 62,2. Ausgeschlossen wird dreifach derselbe Vokal
(`lulula`), ein wiederholter Konsonant und jede Vokalhaeufung ausser den echten
Diphthongen (`blaoa`).

```bash
domainfinder score bitrot purlink ahefid     # Score aufgeschluesselt
```

Der Score sortiert. Er ersetzt das Urteil nicht: ob ein Name auf einer Rechnung
traegt, entscheidet ein Mensch am Ende der Liste, nicht die Gewichtung.

## Filter nachziehen

Die harten Kriterien wachsen mit jedem Lauf -- eine Marke, eine deutsche
Peinlichkeit, eine englische Schreibung, die vorher niemand bedacht hat:

```bash
domainfinder refresh --tld com --rank schoen
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

`quelle` ist der Quellenbuchstabe, `kategorie` eine Einordnung wie `it`,
`marke`, `kunstwort`, `fachwitz`. Daneben `out/abgelehnt-<tld>.csv` mit dem
`reason`-Feld jeder abgelehnten Domain.

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
domainfinder recheck pelja.com
```

Exit-Code 0, wenn mindestens ein Name registrierbar ist, sonst 1.

Markenrecherche ersetzt das Werkzeug nicht. Vor der Registrierung durch TMview,
EUIPO und DPMA laufen lassen, Klassen 9 und 42. Keine Rechtsberatung.

## Tests

```bash
python3 -m pytest tests/ -q      # 217 Tests, ohne Netz
```
