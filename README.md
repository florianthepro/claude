# Nyx

Ein anonymes, dezentrales Nachrichtensystem mit ephemeren
Transportschlüsseln — Entwurf und lauffähige Referenzimplementierung.

Der Kern: eine Nachricht liegt bis zur Abholung ausfallsicher bei zwanzig
unabhängigen Knoten und ist danach wertlos — ohne dass die Sicherheit davon
abhinge, dass ein fremder Rechner ein Löschversprechen einhält.

```
whitepaper/nyx-whitepaper.md   Der Entwurf, im Stil des Bitcoin-Papiers
docs/protokoll.md              Verbindliche Drahtformate
docs/api.md                    Knoten-Schnittstelle
docs/betrieb.md                Wie man es startet
docs/sicherheitshinweis.md     Was der Code ist und was nicht

impl/python/                   Referenzimplementierung, ohne Fremdpakete
impl/php/                      Knoten und Verzeichnis, für jeden Webspace
impl/web/                      Browser-Client mit WebCrypto
tools/berechnungen.py          Rechnet die Tabellen des Whitepapers nach
```

## Sofort ausprobieren

```bash
python3 impl/python/demo.py                              # Vorführung
cd impl/python && python3 -m unittest discover -s tests -t .   # 123 Tests
python3 tools/berechnungen.py                            # Zahlen nachrechnen
```

Die Vorführung braucht kein Netz und keine Installation.

## Was der Entwurf tut

**Identität ist ein Schlüsselpaar.** Keine Telefonnummer, keine
Registrierung. Die Adresse *ist* der Schlüssel — 36 Zeichen, selbst erzeugt,
selbst überprüfbar.

**Das Verzeichnis ist eine Blockchain.** Nicht für Nachrichten, sondern für
Schlüsselereignisse. Der Zweck ist nicht, einen untergeschobenen Schlüssel
unmöglich zu machen, sondern ihn öffentlich und dauerhaft nachweisbar zu
machen.

**Jede Nachricht bekommt ihren eigenen Schlüssel.** X3DH-Handshake plus
Doppelratsche wie bei Signal. Der Nachrichtenschlüssel wird unmittelbar nach
der Entschlüsselung überschrieben; zwischen Entschlüsselung und Löschung
liegt keine Ein-/Ausgabe.

**Der Transport trennt Wissen.** Drei Hops, konstante Paketgröße von 2 KiB,
Mischen statt Weiterleiten, Deckverkehr. Kein Knoten kennt Sender und
Empfänger zugleich.

**Die Ablage ist zerlegt.** Eine Nachricht liegt als 20 Teile bei 20 Knoten,
10 genügen zur Wiederherstellung, und weniger als 10 ergeben wegen der
vorgeschalteten Alles-oder-nichts-Transformation buchstäblich nichts. Jeder
Teil trägt seinen eigenen, unverkettbaren Tag.

**Dieselbe Schwelle liefert beides.** Bis zur Abholung ist die Nachricht
auch bei Ausfall der Hälfte aller Knoten verfügbar. Ab der Abholung genügt
es, dass 11 von 20 Knoten löschen, um sie für alle unwiederbringlich zu
vernichten — einschließlich derjenigen, die nicht gelöscht haben.

**Löschen wird nicht vorausgesetzt.** Löschung auf fremder Hardware ist
weder erzwingbar noch beweisbar. Deshalb hängt keine Garantie daran:
gelöscht wird der Schlüssel, an genau einer Stelle, die dem Nutzer gehört.
Was bei den Knoten zurückbleibt, ist danach von Rauschen nicht zu
unterscheiden.

## Drei Implementierungen, ein Protokoll

Die Fassungen in Python, PHP und JavaScript sprechen wirklich miteinander —
nachgewiesen, nicht behauptet:

- `tests/test_interop.py` vergleicht kanonisches JSON, Adressbildung,
  Signaturprüfung, Merkle-Wurzeln und die punktierbare PRF zwischen Python
  und PHP, und führt ein Gespräch über PHP-Ablagen.
- `tests/test_crosslang.py` lässt den unveränderten Browser-Client eine
  Nachricht schreiben und holt sie mit dem Python-Client ab:
  JavaScript → PHP-Knoten → Python.

| | Python | PHP | JavaScript |
|---|---|---|---|
| Protokollkern | vollständig | — | vollständig |
| Knoten und Verzeichnis | vollständig | vollständig | — |
| Chat, Post, Dateien | vollständig | — | vollständig |
| Anrufe | Signalisierung | — | Signalisierung + WebRTC |
| Mixnetz | vollständig | — | — |
| Krypto | reines Python | libsodium | WebCrypto + eigenes ChaCha20 |

## Dienste

Chat, Post, Dateiübertragung und Anrufe sind keine getrennten Systeme. Sie
benutzen dieselbe Identität, dieselbe Ratsche und dieselben Ablagen und
unterscheiden sich nur im Umschlag. Weil jede Ablage genau 1024 Byte groß
ist, sieht eine Kurznachricht im Speichernetz aus wie ein Dateiblock und ein
Anrufsignal wie ein Brief.

## Status

Referenzimplementierung, kein Produkt. Sie ist dazu da, den Entwurf prüfbar
zu machen. Die bekannten Schwächen stehen vollständig in
`docs/sicherheitshinweis.md` — darunter: die Python-Primitive sind nicht
laufzeitkonstant, das Mixnetz ist gebaut aber nicht angeschlossen, und der
Arbeitsnachweis der Kette ist auf Demo-Niveau eingestellt.

Wer damit etwas schützt, das tatsächlich geschützt werden muss, sollte
zuerst diese Seite lesen.
