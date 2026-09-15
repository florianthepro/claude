# Anhang B: API-Referenz

## A2.1 Geltung und Abgrenzung

Dieser Anhang legt die öffentliche Schnittstelle fest, die die Atrium Console, `atriumctl` und jede externe Automatisierung ohne Unterschied benutzen (INV-01). Er beschreibt Protokollrahmen, Autorisierung, Nebenläufigkeit, Fehlerformat, Abfragesyntax, Vorgangssteuerung, Ereignisstrom und Stabilitätszusage. Die Feldstruktur der Nutzlasten steht in [Anhang A](A1-schemata.md) und wird hier nicht wiederholt; die Entitäten und ihre Zustandsmengen stehen in [Kapitel 07](07-objektmodell.md), die Rechtematrix in [Kapitel 19](19-mandanten-rechte-audit.md), der Konnektorvertrag in [Kapitel 09](09-konnektoren.md).

Drei Abgrenzungen sind bindend. Der Konnektorvertrag (`describe`, `observe`, `plan`, `apply`, `healthcheck`) ist kein Bestandteil dieser Schnittstelle; er läuft über einen Unix-Socket und ist über das Netz nicht erreichbar (INV-21). Der Kopplungsendpunkt auf 8403/tcp spricht ein eigenes Protokoll nach RFC 9382 und ist nur im Wartemodus offen (INV-27); er erscheint hier nur als Randbedingung des Beispielablaufs in A2.10.3. Der Agentenkanal auf 8402/tcp überträgt signierte Sollzustandsauszüge und kennt keine Schreiboperation, die ein Bediener auslösen könnte (INV-03).

## A2.2 Grundsätze

| Grundsatz | Festlegung | Verworfene Alternative | Grund |
|---|---|---|---|
| Ressourcenorientierung | Jeder Pfad benennt eine Entität des Objektmodells oder einen Vorgang. Abgeleitete Artefakte sind Ressourcen ohne Schreibverb (INV-09). | Aufgabenorientierte Endpunkte (`/nutzer-anlegen`) | Aufgabenendpunkte verdoppeln sich mit jeder Oberflächenvariante und machen die Rechteprüfung an Objekten unmöglich, weil kein Objekt im Pfad steht. |
| Namensraum und Pluralform | `/v1/<entitätstyp-plural>/<ulid>`, deutscher Plural ohne Umlaute (`/v1/personen`, `/v1/veroeffentlichungen`, `/v1/dnseintraege`). | Englische Pfadsegmente | KANON.md 3 legt deutsche Feld- und Pfadnamen fest; eine zweite Sprachebene im Pfad erzeugt eine Übersetzungstabelle, die niemand pflegt. |
| Versionierung im Pfad | Genau ein Segment `v1`. Es wechselt ausschließlich bei einer MAJOR-Schemaänderung (KANON.md 3, INV-24). Additive Änderungen erhöhen `schema_version` in der Antwort und lassen den Pfad unberührt. | Version im Header; Version je Ressource | Eine Version im Header ist in Protokollen, Zwischenspeichern und Fehlerberichten unsichtbar; eine Version je Ressource erzeugt Kombinationen, die niemand vollständig testet. |
| Verbmenge | `GET`, `POST`, `PATCH`, `DELETE` und Aktionsendpunkte der Form `POST /v1/<plural>/<ulid>:<aktion>`. | `PUT`; `OPTIONS` als Beschreibungsweg | `PUT` verlangt eine vollständige Ersetzung; bei Objekten mit fremdbesessenen und abgeleiteten Feldern (INV-13, INV-09) ist eine vollständige Ersetzung nicht ausdrückbar, ohne dass der Aufrufer Felder zurückschreibt, die ihm nicht gehören. |
| Aktionsendpunkte | Zustandsübergänge, die kein Feld setzen, tragen einen Doppelpunkt-Suffix (`:freigeben`, `:ausfuehren`, `:zuruecknehmen`, `:raeumen`, `:sperren`). | Unterressource je Aktion (`/vorgaenge/{id}/freigabe`) | Eine Unterressource macht die Freigabe zu einem eigenständig adressierbaren Objekt mit eigenem Lebenszyklus und verdoppelt damit den Vorgang, der die Freigabe bereits trägt. |
| Antwortform | Einzelobjekte stehen unverpackt auf oberster Ebene. Sammlungen stehen in `{ "objekte": [...], "seite": {...} }`. | Einheitlicher Umschlag für beides | Ein Umschlag um ein Einzelobjekt wiederholt nur, was Statuszeile, `ETag` und `Atrium-Schema-Version` bereits tragen. |
| Kein Sammelaufruf | Es gibt keinen Endpunkt, der mehrere unabhängige Operationen in einem Aufruf ausführt. | Batch-Endpunkt | Ein Sammelaufruf hat kein eindeutiges Ergebnis, keine eindeutige Rechteprüfung und keine eindeutige Wirkungsvorschau; Massenwirkung entsteht in Atrium über Gruppen und Richtlinien, nicht über Sammelaufrufe. |
| Schreibende Antwort | Jeder schreibende Aufruf antwortet mit `202` und verweist auf einen Vorgang. Ein Objekt gilt nie als fertig versorgt, solange ein Zielsystem aussteht (INV-12). | `201 Created` mit fertigem Objekt | Ein `201` behauptet Abschluss, obwohl die Versorgung in Fremdsystemen erst beginnt; das ist genau der stille halbe Erfolg, den INV-12 verbietet. |

Die Antwortregel hat eine benannte Schwäche: Aufrufer, die nach einem `POST` unmittelbar mit dem angelegten Objekt weiterarbeiten wollen, müssen entweder den mitgelieferten Objektrumpf aus der `202`-Antwort verwenden oder den Vorgang beobachten. Der Objektrumpf ist im Sollzustand bereits gültig (die Sollzustandsversion ist erhöht), nur die abgeleiteten Artefakte und die Fremdkonten sind es noch nicht. Die Antwort führt deshalb beides: das Objekt unter `objekt` und den Vorgang unter `vorgang`.

### Inhaltstypen, Zeichencodierung, Zeitformat

| Gegenstand | Festlegung |
|---|---|
| Transport | HTTPS auf 443/tcp und 443/udp über HTTP/2 (RFC 9113) oder HTTP/3 (RFC 9114) auf QUIC (RFC 9000), Semantik nach RFC 9110, TLS 1.3 nach RFC 8446. Knotenlokal zusätzlich 8400/tcp mit gegenseitigem TLS (KANON.md 7). HTTP/1.1 wird angenommen, aber ohne Ereignisstrom und ohne Zusage zur Reaktionszeit. |
| Anfrage und Antwort | `application/json`. Ein `charset`-Parameter wird nicht gesetzt und nicht ausgewertet; JSON ist UTF-8. |
| Fehler | `application/vnd.atrium.fehler+json`, Struktur nach A2.6. |
| Ereignisstrom | `text/event-stream` (Server-Sent Events, benannt ohne Nummer). |
| Export | `application/vnd.atrium.export+json` für den Sollzustandsexport, `application/vnd.atrium.audit+json` für den Auditexport, beide mit Signatur nach RFC 8032 über die kanonische Form nach RFC 8785. |
| Manifeste und Katalogeinträge | Import ausschließlich als JSON. Die YAML-Schreibweise aus [Kapitel 09](09-konnektoren.md) ist die Autorenform und wird vor der Einreichung umgewandelt. Grund: ein YAML-Leser am unauthentisierten Rand ist eine nennenswerte Angriffsfläche (Ankerauflösung, Typ-Tags, Aliasvervielfachung) für einen Komfortgewinn, der im Bauwerkzeug billiger zu haben ist. |
| Zeichencodierung | UTF-8, Unicode-Normalform NFC. Eine Anfrage mit nicht normalisierten Zeichenketten in Namensfeldern wird mit `schemafehler` abgelehnt, nicht stillschweigend normalisiert, weil eine stille Normalisierung den Vergleich mit dem gespeicherten Wert für den Aufrufer unerklärlich macht. |
| Zeitpunkte | Formatbezeichner `date-time` nach [Anhang A](A1-schemata.md), ausschließlich UTC mit Suffix `Z`, Auflösung Millisekunden. Lokalzeiten und Zonenversätze werden abgelehnt. |
| Zeitspannen | Ganzzahlige Millisekunden in Feldern mit Endung `_ms`. Kalenderbezogene Fristen (Aufbewahrung, Sperrfrist) im Formatbezeichner `duration`. |
| Kennungen | ULID in Pfaden, URN (`urn:atrium:<typ>:<ulid>`) in Verweisfeldern (KANON.md 3). |
| Kopfzeilen | Protokollkopfzeilen behalten ihre Protokollnamen (`Authorization`, `If-Match`, `ETag`, `Retry-After`, `Last-Event-ID`). Eigene Kopfzeilen tragen das Präfix `Atrium-`: `Atrium-Schema-Version`, `Atrium-Mandant`, `Atrium-Idempotenz-Schluessel`, `Atrium-Korrelation`, `Atrium-Ratenrest`, `Atrium-Abkuendigung`. |

Jede Antwort, auch jede Fehlerantwort, führt `Atrium-Schema-Version` und `Atrium-Korrelation`. Die Korrelationskennung ist eine ULID je Vorgang und erscheint in jedem Auditereignis, jedem Konnektoraufruf und jeder Protokollzeile (KANON.md 3); bei lesenden Aufrufen wird sie je Anfrage erzeugt.

## A2.3 Authentisierung und Autorisierung

### Tokenarten und Beschaffung

| Art | Beschaffung | Träger | Lebensdauer (Zielwert) | Einsatz |
|---|---|---|---|---|
| Sitzungstoken einer Person | OpenID Connect Core 1.0, Authorization Code mit PKCE (RFC 7636) gegen den Protokollkopf; Anmeldung mit Passkey oder MFA | Zugriffstoken nach RFC 9068 (JWT nach RFC 7519), an das Gerätezertifikat oder an die TLS-Verbindung gebunden | 10 min, Erneuerung über ein an die Sitzung gebundenes Erneuerungsmerkmal | Atrium Console, `atriumctl` an einem Arbeitsplatz |
| Dienstkontozertifikat | Ausstellung aus der Mandanten-Zwischen-CA beim Anlegen des Dienstkontos ([Kapitel 11](11-pki.md)) | X.509 nach RFC 5280, gegenseitiges TLS | Zertifikatslaufzeit, Pflichtablauf am Objekt | Automatisierung mit stabiler Quelladresse; kein Token nötig |
| Dienstkontotoken | Tokenaustausch nach RFC 8693 gegen das Dienstkontozertifikat, mit Verengung der Gültigkeitsbereiche | Zugriffstoken nach RFC 9068 | 10 min, nicht erneuerbar | Automatisierung, die ein Token weiterreichen muss, ohne den privaten Schlüssel weiterzugeben |
| Gerätegebundenes Token ohne Browser | Device Authorization Grant nach RFC 8628 | Zugriffstoken nach RFC 9068 | 10 min | `atriumctl` auf einer Maschine ohne Anzeigegerät |
| SCIM-Token | Dienstkonto mit ausschließlich SCIM-Gültigkeitsbereichen, Bearer nach RFC 6750 | Zugriffstoken | 10 min | SCIM-Server auf 8405/tcp nach RFC 7643 und RFC 7644 |

Die dynamische Clientregistrierung nach RFC 7591 wird **nicht** angeboten. Jeder nichtmenschliche Aufrufer ist ein Dienstkonto im Objektgraphen mit Mandant, Zweck, Pflichtablauf und erlaubten Quelladressen; eine dynamische Registrierung erzeugte Identitäten außerhalb des Sollzustands und damit eine zweite Wahrheitsquelle (INV-02) sowie einen Aufrufer ohne Mandantenbezug (INV-19).

### Gültigkeitsbereiche

Ein Gültigkeitsbereich verengt ein Token; er verleiht kein Recht. Das wirksame Recht ist der Durchschnitt aus drei Mengen: den Gültigkeitsbereichen des Tokens, den Rechten der Rollen des Subjekts nach der Matrix in [Kapitel 19](19-mandanten-rechte-audit.md) und dem Mandantenbezug. Diese Regel ist der Grund, warum ein kompromittiertes Token eines Helpdesk-Mitarbeiters mit `personen.schreiben` keine Verwaltungsrolle vergeben kann: der Gültigkeitsbereich deckt den Pfad ab, die Rolle trägt die Aktionsklasse nicht.

| Gültigkeitsbereich | Deckt ab | Mandantenbindung | Übliche Rollen |
|---|---|---|---|
| `personen.lesen` | Personen, Gruppen, Dienstkonten lesen | Mandant des Tokens | MA, HD, PR, NL |
| `personen.schreiben` | Personen und Gruppen anlegen, ändern, sperren | Mandant des Tokens | MA, HD |
| `zuweisungen.schreiben` | Zuweisungen setzen und entziehen | Mandant des Tokens | MA, HD (beantragend) |
| `rollen.schreiben` | Rollen und Rechteregeln ändern | Mandant oder Plattform | PE, MA |
| `geraete.lesen` / `geraete.schreiben` | Geräteinventar, Registrierung, Sperrung | Mandant des Tokens | MA, HD |
| `dienste.lesen` / `dienste.schreiben` | Dienste bereitstellen, aktualisieren, verschieben, entfernen | Mandant des Tokens | MA, DA |
| `veroeffentlichungen.schreiben` | Veröffentlichungen anlegen und ändern | Mandant des Tokens | MA, DA |
| `namen.lesen` / `namen.schreiben` | Domänen, DNS-Einträge, Netzzonen | Mandant des Tokens | MA |
| `mail.schreiben` | Maildomänen, Postfächer, Mailadressen | Mandant des Tokens | MA, HD |
| `knoten.lesen` / `knoten.schreiben` | Knoten aufnehmen, räumen, entkoppeln | Plattform | PE |
| `speicher.lesen` / `speicher.schreiben` | Speicherbereiche, Sicherungspläne, Wiederherstellungspunkte | Mandant oder Plattform | PE, MA |
| `konnektoren.schreiben` | Konnektorbindungen einrichten und ändern | Mandant des Tokens | MA |
| `geheimnisse.schreiben` | Geheimnisse hinterlegen und wechseln | Mandant des Tokens | PE, MA, DA |
| `richtlinien.schreiben` | Richtlinien setzen und versionieren | Mandant oder Plattform | PE, MA |
| `katalog.lesen` / `katalog.freigeben` | Katalogeinträge lesen, freigeben, zurückziehen | Plattform | PE |
| `mandanten.lesen` / `mandanten.schreiben` | Mandanten und Isolationsstufen | Plattform | PE |
| `vorgaenge.lesen` | Vorgänge und Wirkungsvorschauen lesen | Mandant des Tokens | alle |
| `vorgaenge.ausfuehren` | Vorgänge ausführen und zurücknehmen | Mandant des Tokens | PE, MA, DA |
| `vorgaenge.freigeben` | Vorgänge freigeben oder ablehnen | Mandant oder Plattform | FG |
| `audit.lesen` / `audit.exportieren` | Auditköpfe und Auditexport | Mandant oder Plattform | PR, MA |
| `ereignisse.abonnieren` | Ereignisstrom nach A2.9 | Mandant des Tokens | alle lesenden Rollen |
| `notzugang.ausloesen` | Notzugang in einen Mandanten | Plattform | PE, freigabepflichtig |

Es existiert kein Gültigkeitsbereich `geheimnisse.lesen`. Die Leerstelle ist Absicht und keine Lücke: die API kennt für Felder der Klasse Geheimnis keine Leseoperation (INV-20), und der Bauprüfstand lehnt einen Gültigkeitsbereich mit lesender Geheimnissemantik ab.

**Mandantenbindung.** Jedes Token trägt genau einen Mandantenbezug oder den Vermerk Plattformgeltung. Ein Token mit Mandantenbezug erhält den Mandantenfilter implizit; eine Anfrage, die ein abweichendes `Atrium-Mandant` setzt, wird abgelehnt. Ein Token mit Plattformgeltung **muss** `Atrium-Mandant` setzen, sobald der Pfad eine mandantengebundene Entität betrifft; ohne diese Angabe lehnt die Datenzugriffsschicht die Abfrage ab, weil kein Mandantenprädikat gebildet werden kann (INV-19). Mandantenübergreifende Bezüge entstehen ausschließlich über eine ausdrückliche Freigabeverknüpfung und sind an ihr auditiert.

### Widerruf

Tokens sind kurzlebig, damit der Widerruf im Normalfall Ablauf heißt. Für den Sofortfall (Person gesperrt, Gerät verloren, Dienstkonto widerrufen) führt jeder Verwaltungsknoten eine knotenlokale Widerrufsliste der Tokenkennungen, die über den Agentenkanal verteilt wird; der Zielwert für die Verteilung ist ≤ 5 s. Die Liste liegt nicht im replizierten Sollzustand, weil sie hochfrequent und kurzlebig ist und das Änderungsprotokoll fluten würde (KANON.md 4, Telemetrie).

**Rechnung zum Missbrauchsfenster.** Annahme: Tokengültigkeit 10 min = 600 s, Verteilzeit der Widerrufsliste ≤ 5 s. Das Fenster, in dem ein bereits ausgegebenes Token nach der Sperrung noch wirkt, beträgt höchstens 5 s statt 600 s; die Verkürzung beträgt 600/5 = Faktor 120. Ohne Widerrufsliste und mit einer Gültigkeit von 60 min läge das Fenster bei 3.600 s, also um den Faktor 720 höher. Das ist eine Modellrechnung aus den genannten Zielwerten, keine Messung. Fällt ein Knoten aus der Kontrollebene heraus, greift die stärkere Regel: ohne Quorum ist der Sollzustand eingefroren, und schreibende Aufrufe werden ohnehin abgelehnt (INV-04).

Sichere Praxis an dieser Kante: Tokensignaturen werden gegen den Schlüsselsatz des Protokollkopfs geprüft, nie gegen einen im Token benannten Schlüsselort; die Prüfung der Tokenkennung gegen die Widerrufsliste und jeder Vergleich eines Geheimnisses, eines Idempotenzschlüssels oder eines Kopplungscodes erfolgen laufzeitkonstant; ein unbekannter Gültigkeitsbereich führt zur Ablehnung, nicht zum Ignorieren (Default-Deny, INV-10).

## A2.4 Nebenläufigkeit

### Versionsangabe beim Lesen

Jede Leseantwort führt `sollzustand_version` im Objekt und denselben Wert als starken Entitätsmarker in `ETag`. Der Marker ist die Sollzustandsversion des Objekts und kein Hashwert über die Darstellung; er ändert sich damit genau dann, wenn eine Änderungstransaktion das Objekt berührt hat, und nicht, wenn sich eine Feldauswahl oder eine Sprache ändert. Verworfene Alternative: ein Hashwert über die serialisierte Antwort; er wechselt bei jeder Darstellungsänderung und erzeugt Bedingungsfehlschläge ohne fachlichen Anlass.

### Bedingungsprüfung beim Schreiben

`PATCH` und `DELETE` verlangen `If-Match` mit dem zuletzt gelesenen Marker. Fehlt die Kopfzeile, wird die Anfrage mit der Klasse `vorbedingung_fehlt` abgelehnt. Das ist eine harte Pflicht und kein Vorschlag: ein blindes Überschreiben ist in einem System, dessen Wirkungen bis in Fremdsysteme reichen, nicht rücknehmbar genug, um es zuzulassen. `POST` auf eine Sammlung trägt kein `If-Match`, weil kein Vorgängerzustand existiert; es trägt stattdessen einen Idempotenzschlüssel.

### Idempotenzschlüssel

| Regel | Festlegung |
|---|---|
| Kopfzeile | `Atrium-Idempotenz-Schluessel`, 16 bis 64 Zeichen aus `[a-z0-9-]`, üblicherweise eine ULID. |
| Pflicht | Bei jedem `POST` auf eine Sammlung und bei jedem Aktionsendpunkt, der einen Vorgang ausführt. |
| Ableitung | Der Kern leitet daraus und aus Vorgang und Schritt den internen `idempotenzschluessel` als Hashwert ab ([Anhang A](A1-schemata.md), A1.2.14). Der äußere Schlüssel des Aufrufers und der innere Schlüssel des Konnektoraufrufs sind verschiedene Werte mit verschiedener Lebensdauer; sie werden nicht vermengt. |
| Aufbewahrung | 24 h ab erstem Eingang. |
| Wiederholung mit gleichem Inhalt | Antwort der ersten Ausführung, Statuszeile identisch, zusätzlich `Atrium-Idempotenz-Treffer: wiederholung`. Es entsteht kein zweiter Vorgang und kein Auditereignis vom Typ "geändert" (INV-07). |
| Wiederholung mit abweichendem Inhalt | Ablehnung mit Klasse `konflikt` und Handlungsangabe "neuen Schlüssel verwenden". Der Inhaltsvergleich erfolgt über den Hashwert der kanonischen Form nach RFC 8785, nicht über die Rohbytes, damit Schlüsselreihenfolge und Leerraum keine Rolle spielen. |
| Wiederholung während der Ausführung | Antwort `202` mit demselben Vorgangsverweis; der Aufrufer sieht den laufenden Vorgang und startet keinen zweiten. |

**Rechnung zum Speicherbedarf der Idempotenztabelle.** Annahme: 2.000 schreibende Aufrufe je Tag in einer mittleren Installation (Größenordnung abgeleitet aus K-12: rund 15.000 Objekte). Je Eintrag: 64 B Schlüssel + 32 B Hashwert des kanonisierten Inhalts + 26 B Vorgangskennung + 8 B Zeitstempel + 128 B Verwaltungsaufwand ≈ 258 B. Bedarf bei 24 h Aufbewahrung: 2.000 × 258 B ≈ 516 KB. Deutung: Die Aufbewahrungsfrist ist nicht durch Speicher begrenzt; sie ist auf 24 h gesetzt, weil ein Aufrufer, der einen Aufruf länger als einen Tag später wiederholt, keine Wiederholung mehr meint, sondern eine neue Absicht hat. Das ist ein Modell, keine Messung.

### Verhalten bei Konflikt

| Fall | Antwort | Inhalt |
|---|---|---|
| `If-Match` passt nicht zur aktuellen Sollzustandsversion | `409`, Klasse `konflikt` | aktuelle Version, Liste der seit der gelesenen Version geänderten Felder, Handlungsangabe "erneut laden und Änderung wiederholen" |
| Wirkungsvorschau veraltet (`vorschau_basis_version` < aktuelle Sollzustandsversion) | `409`, Klasse `konflikt` | Verweis auf den Vorgang und die Aktion `:vorschau`, die neu berechnet |
| Fremdsystem hat das Objekt seit der letzten Beobachtung geändert | Vorgang geht in `zur Entscheidung vorgelegt`, Aufruf antwortet `202` | Feldeigentumskonflikt nach [Kapitel 09](09-konnektoren.md); die API meldet keinen Fehler, weil der Vorgang lebt |
| Zwei Aufrufer legen dasselbe Objekt mit demselben eindeutigen Namen an | `409`, Klasse `konflikt` | betroffenes Eindeutigkeitsfeld und der bestehende Objektverweis, sofern der Aufrufer ihn sehen darf; sonst nur das Feld |

## A2.5 Blätterung, Filterung, Sortierung, Feldauswahl

### Blätterung

Die Blätterung ist ausschließlich zeigerbasiert. `GET /v1/personen?grenze=50` liefert `seite: { "weiter": "<ulid>", "ende": false }`; der Folgeaufruf setzt `seite_nach=<ulid>`. Der Zeiger ist die Kennung des letzten gelieferten Objekts, weil eine ULID lexikographisch nach Erzeugungszeit sortiert ist (KANON.md 3) und die Fortsetzung damit ein Indexsprung ist.

Verworfene Alternative: eine Versatzblätterung (`offset`). Sie kostet bei Seite k einen Durchlauf über k × Seitengröße Zeilen und liefert bei gleichzeitigen Einfügungen doppelte oder ausgelassene Objekte. **Rechnung:** bei 10.000 Objekten und Seitengröße 50 liest die Versatzblätterung über alle 200 Seiten summiert 50 × (1+2+…+200) = 50 × 20.100 = 1.005.000 Zeilen, die Zeigerblätterung 200 × 50 = 10.000 Zeilen zuzüglich 200 Indexsprüngen. Das Verhältnis beträgt rund 100:1 und wächst linear mit der Objektzahl. Das ist eine Abschätzung aus der Zugriffsstruktur, keine Messung.

Die Zeigerblätterung liefert keine Momentaufnahme über Seitengrenzen hinweg. Ein Objekt, das während des Blätterns angelegt wird, erscheint nur, wenn seine Kennung hinter dem Zeiger liegt; ein gelöschtes Objekt fehlt. Wer eine konsistente Menge braucht, setzt `bei_version=<n>` und liest gegen das materialisierte Lesemodell dieser Sollzustandsversion. Das ist nur innerhalb des Aufbewahrungsfensters des Lesemodells möglich; der Zielwert ist 15 min, danach antwortet die API mit der Klasse `version_verfallen` und der Handlungsangabe, ohne Versionsbindung neu zu lesen. Diese Begrenzung ist eine echte Einschränkung und steht unter A2.13.

### Filterung, Sortierung, Feldauswahl

| Gegenstand | Festlegung | Sichere Praxis |
|---|---|---|
| Filtersyntax | `filter=<feld><operator><wert>`, mehrfach angebbar, ausschließlich UND-verknüpft. Operatoren: `=`, `!=`, `<`, `>`, `~` (Präfix, nur auf `anzeige_name` und `technischer_name`). | Feldnamen werden gegen eine Positivliste je Ressource geprüft und auf einen festen Spaltenbezeichner abgebildet; der Wert geht als gebundener Parameter in die Abfrage. Es findet keine Zeichenkettenverkettung statt und keine Auswertung eines Ausdrucks. |
| Keine Ausdruckssprache | Es gibt kein ODER, keine Klammern, keine regulären Ausdrücke, keine Pfadausdrücke über verschachtelte Felder. | Eine vom Aufrufer gelieferte Ausdruckssprache ist eine dynamische Auswertung fremder Eingabe und zugleich ein unkalkulierbarer Abfrageplan; beides ist ausgeschlossen. |
| Sortierung | `sortierung=<feld>` und `richtung=auf|ab`, Feld aus derselben Positivliste. Vorgabe `kennung` absteigend, also neueste zuerst. | Ein nicht gelistetes Sortierfeld wird abgelehnt und nicht ignoriert. |
| Feldauswahl | `felder=<liste>`. Die Antwort führt immer `kennung`, `typ`, `mandant`, `zustand` und `sollzustand_version`, unabhängig von der Auswahl. | Die Feldauswahl verengt nie die Rechteprüfung: ein nicht sichtbares Feld ist auch dann nicht enthalten, wenn es genannt wird, und die Antwort nennt es nicht als abgelehnt. |
| Istzustandsfelder | Felder mit beobachtetem Ursprung tragen `beobachtet_am` (INV-28) und sind nicht filterbar, weil sie nicht im Konsens liegen. | Ein Filter auf ein Istzustandsfeld wird mit `schemafehler` und der Angabe des Feldes abgelehnt. |

### Grenzwerte

| Grenze | Wert | Herleitung |
|---|---|---|
| Seitengröße | Vorgabe 50, Höchstwert 200 | siehe Rechnung unten |
| Filterausdrücke je Anfrage | 8 | Jeder weitere Ausdruck senkt die Indexselektivität, ohne die Trefferzahl nennenswert zu verringern; acht decken die Filterleisten der Konsole ab. |
| Länge der Anfragezeile | 4 KB | Grenze der Zwischenstellen und des Protokolls; längere Filter deuten auf eine Abfrage, die eine eigene Ansicht braucht. |
| Nutzlast einer Anfrage | 256 KB, für Katalog- und Manifestimport 4 MB | Ein Sollzustandsobjekt ist nach K-12 im Mittel 2 KB groß; 256 KB lassen Reserve für große Listenfelder. |
| Gleichzeitige Ereignisströme je Token | 4 | Die Konsole braucht einen; vier decken mehrere geöffnete Ansichten ab. |
| Gleichzeitige Ereignisströme je Verwaltungsknoten | 200 | Zielwert; siehe A2.9. |

**Rechnung zur Seitengröße.** Zielwert K-18 fordert Erstanzeige jeder Liste p95 ≤ 300 ms bei 10.000 Objekten. Annahme: Rechteprüfung, Mandantenprädikat und Serialisierung kosten je Objekt ≤ 1 ms, feste Kosten je Anfrage (Tokenprüfung, Abfrageplanung, Verbindungsaufwand) ≤ 50 ms. Bei Seitengröße 200: 50 ms + 200 × 1 ms = 250 ms < 300 ms. Bei Seitengröße 500 wären es 550 ms und der Zielwert wäre verfehlt. Der Höchstwert 200 folgt damit aus K-18 und der genannten Annahme, nicht aus einer Messung; ändert sich die Annahme über die Kosten je Objekt, ändert sich der Höchstwert mit.

## A2.6 Fehlerformat

```json
{
  "klasse": "konflikt",
  "schluessel": "vorgang.vorschau_veraltet",
  "text": "Die Wirkungsvorschau wurde vor einer Aenderung berechnet.",
  "handlung": "Wirkungsvorschau neu berechnen und erneut freigeben.",
  "handlung_ziel": "/verlauf/vorgaenge/01JB9AAAB2C3D4E5F6G7H8J9KM",
  "wiederholbar": true,
  "naechster_versuch_nach_ms": 0,
  "feld": null,
  "vorgang": "urn:atrium:vorgang:01JB9AAAB2C3D4E5F6G7H8J9KM",
  "korrelationskennung": "01JB9BBBC3D4E5F6G7H8J9KMNP",
  "schema_version": "1.0"
}
```

| Feld | Bedeutung | Pflicht |
|---|---|---|
| `klasse` | maschinenlesbare Fehlerklasse aus der Liste unten; der Aufrufer wertet ausschließlich dieses Feld aus | ja |
| `schluessel` | übersetzbarer Bezeichner `<objekt>.<ursache>`; stabil über Sprachwechsel | ja |
| `text` | Meldung in der Sprache des Subjekts, ohne Befehl, ohne Dateipfad, ohne Fremdtext (INV-16, INV-17) | ja |
| `handlung` | ausführbare Folgehandlung in der Konsole; nie "wenden Sie sich an den Administrator", nie ein Konsolenbefehl | ja |
| `handlung_ziel` | Verweis in die Konsole auf das Objekt oder Formular, an dem die Handlung ausgeführt wird | ja, wenn eine Handlung existiert |
| `wiederholbar` | ob eine Wiederholung derselben Anfrage sinnvoll ist | ja |
| `naechster_versuch_nach_ms` | frühester sinnvoller Wiederholungszeitpunkt; 0 bei sofort, `null` bei nicht wiederholbar | ja |
| `feld` | bei `schemafehler` der Zeigerpfad auf das beanstandete Feld | bedingt |
| `vorgang` | Verweis auf den betroffenen Vorgang | bedingt |
| `korrelationskennung` | ULID, identisch mit `Atrium-Korrelation` und mit dem Auditereignis | ja |

**Regel ohne Ausnahme.** Jede Fehlerantwort trägt eine maschinenlesbare Klasse und eine bedienertaugliche Handlungsangabe; ein Fehlerfall ohne Handlungsangabe gilt als nicht fertig entwickelt (INV-17). Keine Fehlerantwort enthält Aufrufspuren, SQL-Text, Dateipfade, interne Adressen, Fremdsystemtexte oder Fragmente von Geheimnissen. Das Rohsignal des Fremdsystems (`fremdsignal` aus [Kapitel 09](09-konnektoren.md)) geht ausschließlich in das Betriebsprotokoll und ist über die Korrelationskennung auffindbar.

| Klasse | Status | Bedeutung | Wiederholung | Typische Handlungsangabe |
|---|---|---|---|---|
| `schemafehler` | 400 | Nutzlast verletzt das Schema aus [Anhang A](A1-schemata.md) | nein | Feld korrigieren; `feld` benennt die Stelle |
| `nicht_authentisiert` | 401 | Token fehlt, ist abgelaufen, widerrufen oder nicht prüfbar | nach neuer Anmeldung | erneut anmelden |
| `nicht_berechtigt` | 403 | Rolle oder Gültigkeitsbereich deckt die Aktion im eigenen Mandanten nicht | nein | Recht beantragen; Ziel ist der Freigabeweg |
| `nicht_gefunden` | 404 | Objekt existiert nicht, oder es liegt außerhalb des Mandantenbezugs des Tokens | nein | Objekt im richtigen Mandanten suchen |
| `nicht_erlaubt` | 405 | Verb auf dieser Ressource nicht vorhanden, etwa Schreiben auf ein abgeleitetes Artefakt (INV-09) | nein | Quellobjekt ändern; Ziel ist die Quelle des Artefakts |
| `konflikt` | 409 | Versionskonflikt, veraltete Wirkungsvorschau, Eindeutigkeitsverletzung, abweichende Wiederholung | nach Neuladen | neu laden und wiederholen |
| `vorbedingung_fehlt` | 412 | `If-Match` fehlt oder passt nicht | nach Neuladen | Objekt neu laden |
| `invariante_verletzt` | 422 | Anfrage ist schemagültig, verletzt aber eine Invariante: gerade Stimmzahl (INV-05), Kaskadenlöschung (INV-11), Zyklus in Gruppen, Absenkung der Isolationsstufe ohne Bestätigung | nein | benannte Bedingung herstellen |
| `kontingent` | 429 | Ratengrenze der API oder Kontingentgrenze eines Zielsystems | ja, ab `naechster_versuch_nach_ms` | warten; bei Lizenzgrenze Kontingent erhöhen (INV-29) |
| `intern` | 500 | Fehler in atrium-core, der keiner anderen Klasse zugeordnet werden kann | ja, einmalig | Störung ist im Überblick sichtbar; Korrelationskennung mitgeben |
| `zielsystem_fehler` | 502 | Konnektor meldet `dauerhaft`, `rechte` oder `schema` | abhängig von der Konnektorklasse | Bindung, Fremdrecht oder Manifest berichtigen |
| `rueckstau` | 503 | Warteschlange der Kontrollebene ist voll | ja, ab `naechster_versuch_nach_ms` | später wiederholen |
| `eingefroren` | 503 | Kein Quorum; der Sollzustand ist eingefroren (INV-04) | ja, nach Wiederherstellung des Quorums | Knotenzustand prüfen; Ziel ist Knoten & Speicher |
| `zeitueberschreitung` | 504 | Frist nach A2.11 überschritten | ja | wiederholen; bei Wirkungsvorschau Bindung prüfen |
| `version_verfallen` | 410 | `bei_version` liegt außerhalb des Aufbewahrungsfensters des Lesemodells | ja, ohne Versionsbindung | ohne Versionsbindung neu lesen |

Die sechs Konnektorfehlerklassen aus [Kapitel 09](09-konnektoren.md) bilden auf diese Liste ab: `voruebergehend` → `zeitueberschreitung` oder `rueckstau`, `kontingent` → `kontingent`, `rechte`, `schema` und `dauerhaft` → `zielsystem_fehler`, `konflikt` → `konflikt`. Die Abbildung ist eine Verengung: die API unterscheidet die drei dauerhaften Konnektorklassen im Statuscode nicht, wohl aber im `schluessel` und in der Handlungsangabe.

**Preisgabeentscheidung und ihre Schwäche.** Ein Objekt außerhalb des Mandantenbezugs erzeugt `nicht_gefunden` und nicht `nicht_berechtigt`; andernfalls wäre die API ein Existenzorakel über fremde Mandanten. Die Schwäche entsteht sofort: ein Bediener, der sich schlicht im Mandanten vertan hat, sieht dieselbe Meldung wie bei einem Tippfehler in der Kennung, und die Konsole kann ihm den wahren Grund nicht nennen, ohne die Entscheidung aufzuheben. Der Entwurf mildert das an einer Stelle und nur dort: hat das Subjekt in einem anderen Mandanten überhaupt eine Rolle, nennt die Handlungsangabe die Liste seiner Mandanten, ohne das gesuchte Objekt zu bestätigen. Für ein Subjekt mit genau einem Mandanten bleibt die Meldung mehrdeutig.

## A2.7 Ressourcenübersicht

Die Spalte "Freigabe" nennt den Vorgabewert aus der Rechtematrix in [Kapitel 19](19-mandanten-rechte-audit.md). Sie ist keine Eigenschaft des Endpunkts: der tatsächliche Freigabezwang folgt aus dem Freigabeweg des Mandanten und kann strenger sein. Ein Endpunkt mit "nein" kann in einem Mandanten freigabepflichtig sein; umgekehrt gilt das nicht, weil die Tabelle den Mindestwert nennt.

| Pfad | Verben | Zweck | Gültigkeitsbereich | Freigabe |
|---|---|---|---|---|
| `/v1/mandanten` | GET, POST | Mandanten anlegen und auflisten | `mandanten.*` | nein |
| `/v1/mandanten/{id}` | GET, PATCH | Isolationsstufe, Zustand, Standardrichtlinien | `mandanten.*` | ja bei Absenkung und Stilllegung |
| `/v1/personen` | GET, POST | Nutzer anlegen und auflisten | `personen.*` | nein |
| `/v1/personen/{id}` | GET, PATCH, DELETE | Nutzer ändern, sperren, löschen | `personen.*` | ja beim Löschen |
| `/v1/personen/{id}:sperren`, `:entsperren`, `:ausscheiden` | POST | Zustandsübergänge | `personen.schreiben` | nein |
| `/v1/gruppen`, `/v1/gruppen/{id}`, `/v1/gruppen/{id}/mitglieder` | GET, POST, PATCH, DELETE | Gruppen und Mitgliedschaften | `personen.*` | nein |
| `/v1/rollen`, `/v1/rechteregeln` | GET, POST, PATCH | Rollen und Regeln | `rollen.schreiben` | ja |
| `/v1/zuweisungen` | GET, POST | Schalter je Dienst, der Auslöser jeder Versorgung | `zuweisungen.schreiben` | ja bei Verwaltungsrollen |
| `/v1/zuweisungen/{id}` | GET, DELETE | Zuweisung entziehen | `zuweisungen.schreiben` | ja bei Verwaltungsrollen |
| `/v1/dienstkonten` | GET, POST, PATCH | nichtmenschliche Identitäten | `personen.schreiben` | nein |
| `/v1/geraete`, `/v1/geraete/{id}` | GET, POST, PATCH, DELETE | Geräteinventar | `geraete.*` | ja beim vollständigen Löschen |
| `/v1/geraete/{id}:sperren` | POST | Verlustmeldung, sofortige Sperrlistenwirkung | `geraete.schreiben` | nein |
| `/v1/katalogeintraege` | GET | einsetzbare Software mit Produktgrenzdeklaration | `katalog.lesen` | – |
| `/v1/katalogeintraege/{id}:freigeben`, `:zurueckziehen` | POST | Katalogpflege | `katalog.freigeben` | ja |
| `/v1/dienste`, `/v1/dienste/{id}` | GET, POST, PATCH, DELETE | Dienste bereitstellen, ändern, entfernen | `dienste.*` | ja beim Entfernen mit Daten |
| `/v1/dienste/{id}:aktualisieren`, `:verschieben`, `:anhalten` | POST | Dienstbetrieb | `dienste.schreiben` | nein |
| `/v1/veroeffentlichungen`, `/v1/veroeffentlichungen/{id}` | GET, POST, PATCH, DELETE | Erreichbarkeit eines Dienstes | `veroeffentlichungen.schreiben` | ja bei Sichtbarkeit extern |
| `/v1/domaenen`, `/v1/domaenen/{id}` | GET, POST, PATCH, DELETE | Namensräume intern und extern | `namen.*` | nein |
| `/v1/dnseintraege`, `/v1/dnseintraege/{id}` | GET, POST, PATCH, DELETE | handeingegebene Einträge; abgeleitete sind schreibgeschützt | `namen.*` | nein |
| `/v1/netzzonen` | GET, POST, PATCH | Netzsegmente und Resolver-Sichten | `namen.*` | nein |
| `/v1/maildomaenen` | GET, POST, PATCH | Anbieterbindung und Mail-Namenseinträge | `mail.schreiben` | nein |
| `/v1/postfaecher`, `/v1/mailadressen` | GET, POST, PATCH, DELETE | Postfächer und Adressen | `mail.schreiben` | ja beim Löschen eines Postfachs |
| `/v1/mailadressen:pruefen` | POST | Verfügbarkeitsprüfung, nebenwirkungsfrei (INV-08) | `mail.schreiben` | nein |
| `/v1/knoten`, `/v1/knoten/{id}` | GET, PATCH | Maschinenliste, Rollen, Fehlerzone | `knoten.*` | nein |
| `/v1/knoten/{id}:raeumen`, `:entkoppeln` | POST | Knotenwartung | `knoten.schreiben` | nein |
| `/v1/kopplungsvorgaenge` | GET, POST | Kopplungscode entgegennehmen, Zweck festlegen | `knoten.schreiben` | nein |
| `/v1/speicherbereiche`, `/v1/sicherungsplaene` | GET, PATCH | Datensicherheitsstufe und Sicherungspläne | `speicher.*` | nein |
| `/v1/wiederherstellungspunkte` | GET, POST | Sicherungsobjekte und Prüfstatus | `speicher.*` | nein |
| `/v1/wiederherstellungspunkte/{id}:einspielen` | POST | Wiederherstellung | `speicher.schreiben` | ja |
| `/v1/konnektorbindungen`, `/v1/konnektorbindungen/{id}` | GET, POST, PATCH, DELETE | Fremdsystemverbindungen | `konnektoren.schreiben` | nein; Entfernen verlangt die Fremdkontenbehandlung als Pflichtfeld |
| `/v1/geheimnisse` | POST, PATCH | Hinterlegen und Wechseln; **kein GET** (INV-20) | `geheimnisse.schreiben` | nein |
| `/v1/richtlinien`, `/v1/richtlinien/{id}` | GET, POST, PATCH | Vorbelegungsquellen | `richtlinien.schreiben` | ja |
| `/v1/zertifikate`, `/v1/cas` | GET | Zertifikatsübersicht und eigene CA | `namen.lesen` | – |
| `/v1/artefakte` | GET | abgeleitete Artefakte mit Quellverweis; **kein Schreibverb** (INV-09) | ressourcenabhängig lesend | – |
| `/v1/vorgaenge`, `/v1/vorgaenge/{id}` | GET, POST | Vorgänge anlegen und beobachten | `vorgaenge.lesen` | – |
| `/v1/vorgaenge/{id}:vorschau`, `:ausfuehren`, `:zuruecknehmen`, `:abbrechen` | POST | Vorgangssteuerung | `vorgaenge.ausfuehren` | – |
| `/v1/vorgaenge/{id}:freigeben`, `:ablehnen` | POST | Freigabeentscheidung | `vorgaenge.freigeben` | – |
| `/v1/auditereignisse` | GET | Auditköpfe mit Filter | `audit.lesen` | – |
| `/v1/auditexporte` | POST, GET | signierter Export mit Kettennachweis | `audit.exportieren` | – |
| `/v1/ereignisse` | GET | Ereignisstrom nach A2.9 | `ereignisse.abonnieren` | – |
| `/v1/aufgaben` | GET | maschinenlesbare Aufgabendefinitionen für die Prüfung von INV-14 | `vorgaenge.lesen` | – |

Die letzte Zeile ist kein Komfortendpunkt. Die Drei-Entscheidungs-Regel wird im Bau gegen maschinenlesbare Aufgabendefinitionen geprüft (INV-14, K-27); diese Definitionen müssen über dieselbe API erreichbar sein wie alles andere, sonst entstünde der private Pfad, den INV-01 ausschließt.

## A2.8 Vorgang und Wirkungsvorschau

### Zuordnung zu den Konnektorverben

Die Konsole kennt zwei Handlungen: "Wirkung anzeigen" und "Ausführen". Sie bilden auf die Konnektorverben ab, ohne sie im Pfad zu nennen.

```
POST /v1/vorgaenge { ..., "entwurf": true }
  -> je beteiligter Konnektorbindung ein plan()-Aufruf ueber den Unix-Socket
  -> Vorgang im Zustand "vorschau_berechnet", nebenwirkungsfrei (INV-08)

POST /v1/vorgaenge/{id}:vorschau
  -> Neuberechnung, wenn vorschau_basis_version veraltet ist

POST /v1/vorgaenge/{id}:freigeben     (nur wenn der Freigabeweg es verlangt)
POST /v1/vorgaenge/{id}:ausfuehren
  -> je Bindung apply() mit Idempotenzschluessel je Schritt (INV-07)

GET  /v1/vorgaenge/{id}
  -> Fortschritt, Teilzustand je Zielsystem, benannte Reste (INV-12)

POST /v1/vorgaenge/{id}:zuruecknehmen
  -> erzeugt einen neuen Vorgang mit der Umkehrwirkung, nie eine stille Ruecksetzung
```

### Antwortformat der Wirkungsliste

```json
{
  "kennung": "01JB9AAAB2C3D4E5F6G7H8J9KM",
  "typ": "vorgang",
  "zustand": "vorschau_berechnet",
  "vorschau_basis_version": 41207,
  "freigabestatus": "offen",
  "wirkungsvorschau": [
    { "zielsystem": "01JBA2B3C4D5E6F7G8H9J0KMNP",
      "aktion": "fremdkonto.anlegen",
      "objekt": "urn:atrium:person:01JB7K2M4N5P6Q7R8S9T0VWXYZ",
      "umkehrbar": true,
      "kostenwirkung": { "wirkung": "lizenz",
                         "messgroesse": "1 Agentenplatz",
                         "anzeige": "Belegt einen Agentenplatz des Vertrags." },
      "quelle_vorbelegung": "Richtlinie lizenzprofil_ticketsystem" },
    { "zielsystem": "01JBA2B3C4D5E6F7G8H9J0KMNP",
      "aktion": "gruppenmitgliedschaft.setzen",
      "objekt": "urn:atrium:gruppe:01JBG8H9J0KMNPQRSTVWXYZ234",
      "umkehrbar": true,
      "kostenwirkung": { "wirkung": "keine" },
      "quelle_vorbelegung": "Gruppenmitgliedschaft der Person" }
  ],
  "fehlende_voraussetzungen": [],
  "vorschau_unvollstaendig": false
}
```

Jede Zeile nennt Zielsystem, Aktion, betroffenes Objekt, Umkehrbarkeit, Kostenwirkung (INV-29) und die Quelle jeder Vorbelegung (INV-15). Überschreitet ein `plan`-Aufruf seine Frist, bleibt die Freigabe möglich, der betroffene Eintrag trägt aber `vorschau_unvollstaendig: true`, und der Vorgang trägt den Vermerk, dass eine Bindung ungeprüft ist. Der Zielwert für die Vorschau ist p95 ≤ 2 s (K-18); bei mehr als sieben beteiligten Bindungen bestimmt die langsamste Bindung die Zeit.

### Statusabfrage und Teilerfolg

```json
{
  "zustand": "teilweise_fehlgeschlagen",
  "teilzustand": [
    { "zielsystem": "01JBA2B3C4D5E6F7G8H9J0KMNP",
      "zustand": "erfolgreich", "wiederholbar": false,
      "beobachtet_am": "2026-09-15T09:14:22.310Z" },
    { "zielsystem": "01JBB3C4D5E6F7G8H9J0KMNPQR",
      "zustand": "fehlgeschlagen",
      "grund": "Kontingent des Vertrags erschoepft",
      "wiederholbar": true,
      "beobachtet_am": "2026-09-15T09:14:25.902Z" }
  ],
  "reste": [
    { "objekt": "urn:atrium:mailadresse:01JBH9J0KMNPQRSTVWXYZ23456",
      "was_nicht_wirkt": "Die Adresse empfaengt noch keine Nachrichten.",
      "handlung": "Kontingent erhoehen und Vorgang wiederholen." }
  ]
}
```

Der Zustand `abgeschlossen` ist unzulässig, solange ein Eintrag in `teilzustand` nicht `erfolgreich` ist (INV-12). Der Zustand `unbekannt` eines Zielsystems ist kein Fehler, sondern die ehrliche Aussage, dass ein Abbruch zwischen Schreiben und Antwort lag; er erzwingt eine nachfolgende Beobachtung und blockiert `abgeschlossen` ebenfalls. `reste` ist die maschinenlesbare Form dessen, was die Konsole als "was noch nicht wirkt" anzeigt; ein Vorgang ohne Reste führt die Liste leer und nicht als fehlend.

## A2.9 Ereignisstrom

| Gegenstand | Festlegung | Begründung |
|---|---|---|
| Transport | `GET /v1/ereignisse` mit `text/event-stream` über HTTP/2 oder HTTP/3 | Ein gerichteter Strom über dieselbe Verbindung, dieselbe Autorisierung und dieselbe Fehlerbehandlung wie der Rest der API. |
| Verworfene Alternative | WebSocket | Ein bidirektionaler Kanal verlangt ein zweites Nachrichtenformat, eine zweite Autorisierungsprüfung je Nachricht und eine zweite Ratenbegrenzung, ohne dass die Oberfläche je aufwärts schreibt: jede Schreiboperation ist ein Vorgang über einen gewöhnlichen Aufruf. |
| Filter | `mandant`, `typ`, `objekt`, `vorgang`, `korrelation`, `klasse` (`sollzustand`, `istzustand`, `vorgang`, `stoerung`) | Ein Strom ohne Filter zwingt jede Ansicht, alles zu empfangen und zu verwerfen. |
| Ereigniskennung | `<sollzustand_version>-<ordnungszahl>`, monoton steigend | Die Sollzustandsversion ist ohnehin die Ordnung des Änderungsprotokolls; eine zweite Zählung wäre eine zweite Wahrheit. |
| Wiederaufnahme | `Last-Event-ID` beim Neuverbinden; der Server sendet alle Ereignisse ab der genannten Kennung | Ein Abriss ist der Normalfall in Netzen mit Zwischenstellen und darf keine Neusynchronisierung der Oberfläche erzwingen. |
| Puffergrenze | Zielwert 5.000 Ereignisse oder 15 min, was zuerst eintritt | siehe Rechnung |
| Verhalten bei Pufferüberlauf | Ereignis `luecke` mit der ältesten noch verfügbaren Kennung; der Aufrufer liest die betroffenen Listen neu | Ein stiller Sprung erzeugt eine Oberfläche, die dauerhaft Falsches zeigt, ohne es zu wissen. |
| Lebenszeichen | Kommentarzeile alle 15 s | Zwischenstellen schließen stille Verbindungen; ohne Lebenszeichen ist ein Abriss nicht von Ruhe zu unterscheiden. |
| Inhalt | Objektverweis, Ereignisart, Sollzustandsversion, Zeitpunkt, bei Istzustandsereignissen `beobachtet_am` (INV-28) | Der Strom trägt Verweise, keine vollständigen Objekte: die Rechteprüfung findet beim Nachlesen statt, und der Strom kann so kein Feld ausliefern, das der Empfänger nicht sehen darf. |
| Ausschlüsse | keine Geheimnisse (INV-20), keine Auditanhänge, keine Fremdsystemtexte | Der Strom ist der am breitesten verteilte Kanal der Installation. |

**Rechnung zur Pufferdimensionierung.** Annahme: eine Ereignisnutzlast ist ≤ 512 B. Bei 5.000 Ereignissen belegt der Puffer je Strom ≤ 2,56 MB; bei 200 gleichzeitigen Strömen je Verwaltungsknoten wären das 512 MB und damit mehr als das Achtel des Budgets aus K-19 (≤ 4 GB für die gesamte Kontrollebene samt Eingang, DNS, Resolver und Protokollkopf). Der Puffer wird deshalb **einmal je Knoten** gehalten und nicht je Strom: 5.000 × 512 B ≈ 2,56 MB gesamt, jeder Strom hält nur seinen Lesezeiger. Deutung: die Wiederaufnahme ist ein Zeiger in einen gemeinsamen Ringpuffer; das ist die einzige Auslegung, die mit K-19 verträglich ist. Das ist eine Auslegungsrechnung, keine Messung.

## A2.10 Beispielabläufe

Alle Kennungen sind Beispielwerte im ULID-Format. Nutzlasten sind gekürzt; die vollständige Feldstruktur steht in [Anhang A](A1-schemata.md).

### A2.10.1 Nutzer anlegen mit Ticketsystem-Zuweisung

Zwei Entscheidungen (K-03, Aufgabe 1): Anzeigename und Gruppen/Rollen. Der Schalter "Ticketsystem" ist eine Zuweisung auf den Dienst und zählt zur zweiten Entscheidung, wenn er im Anlegeformular steht.

```
1  POST /v1/personen
   Atrium-Idempotenz-Schluessel: 01JBP4QRSTVWXYZ234567890AB
   { "anzeige_name": "Anna Beispiel", "gruppen": ["01JBG8H9J0KMNPQRSTVWXYZ234"] }
   -> 202
   { "objekt": { "kennung": "01JB7K2M4N5P6Q7R8S9T0VWXYZ",
                 "typ": "person", "zustand": "angelegt",
                 "technischer_name": "anna-beispiel",     # abgeleitet (INV-15)
                 "sollzustand_version": 41206 },
     "vorgang": "urn:atrium:vorgang:01JB9AAAB2C3D4E5F6G7H8J9KM" }

2  POST /v1/vorgaenge
   { "art": "zuweisung_setzen", "entwurf": true,
     "subjekt": "urn:atrium:person:01JB7K2M4N5P6Q7R8S9T0VWXYZ",
     "ziel":    "urn:atrium:dienst:01JB8ZQ3R7T5V9WXY2ABCDEFGH",
     "rolle":   "urn:atrium:rolle:01JBM3NPQRSTVWXYZ234567890" }
   -> 200  Wirkungsliste nach A2.8: fremdkonto.anlegen (Kostenwirkung lizenz),
           gruppenmitgliedschaft.setzen, zertifikat.beantragen

3  POST /v1/vorgaenge/01JB9AAAB2C3D4E5F6G7H8J9KM:ausfuehren
   Atrium-Idempotenz-Schluessel: 01JBP5QRSTVWXYZ234567890CD
   -> 202

4  GET /v1/ereignisse?vorgang=01JB9AAAB2C3D4E5F6G7H8J9KM
   -> event: vorgang.fortschritt   data: { "zielsystem": "...", "zustand": "in_arbeit" }
   -> event: vorgang.fortschritt   data: { "zielsystem": "...", "zustand": "erfolgreich" }
   -> event: vorgang.abgeschlossen data: { "sollzustand_version": 41208 }

5  GET /v1/personen/01JB7K2M4N5P6Q7R8S9T0VWXYZ?felder=zuweisungen,versorgungszustand
   -> 200  ETag: "41208"
   { "versorgungszustand": [ { "zielsystem": "01JBA2B3C4D5E6F7G8H9J0KMNP",
                              "zustand": "wirksam",
                              "beobachtet_am": "2026-09-15T09:12:04.771Z" } ] }
```

Schritt 2 erzeugt genau einen `plan`-Aufruf an die Konnektorbindung des Ticketsystems; die Fachkonfiguration in Znuny bleibt in Znuny (INV-30). Die Wirkungsliste nennt den Agentenplatz als Kostenwirkung vor der Bestätigung (INV-29). Wird derselbe Aufruf aus Schritt 1 mit demselben Idempotenzschlüssel wiederholt, antwortet die API mit derselben `202` und `Atrium-Idempotenz-Treffer: wiederholung`, ohne eine zweite Person anzulegen.

### A2.10.2 Mailadresse hinzufügen

Zwei Entscheidungen (K-03, Aufgabe 21): Maildomäne und lokaler Teil. Der Ablauf folgt [Kapitel 14](14-mail.md) und gilt unabhängig davon, wo das Postfach liegt.

```
1  GET /v1/maildomaenen?mandant=01JB7K2M4N5P6Q7R8S9T0ABCDE&zustand=aktiv&grenze=20
   -> 200 { "objekte": [ { "kennung": "01JBB3C4D5E6F7G8H9J0KMNPQR",
                           "anzeige_name": "beispiel.de",
                           "anbieterbindung": "urn:atrium:konnektorbindung:01JBA2B3C4D5E6F7G8H9J0KMNP" } ],
            "seite": { "ende": true } }

2  POST /v1/mailadressen:pruefen
   { "lokaler_teil": "anna.beispiel", "maildomaene": "01JBB3C4D5E6F7G8H9J0KMNPQR" }
   -> 200 { "frei": true, "domaene_zustand": "aktiv",
            "kontingent": { "frei": 12, "gesamt": 50 } }
   nebenwirkungsfrei: 0 Schreibzugriffe, 0 apply()-Aufrufe (INV-08)

3  POST /v1/vorgaenge
   { "art": "mail_hinzufuegen", "entwurf": true,
     "subjekt": "urn:atrium:person:01JB7K2M4N5P6Q7R8S9T0VWXYZ",
     "maildomaene": "01JBB3C4D5E6F7G8H9J0KMNPQR",
     "lokaler_teil": "anna.beispiel" }
   -> 200 wirkungsvorschau: postfach.sicherstellen (Kostenwirkung lizenz),
          mailadresse.setzen (primaer), zugehoerigkeit.setzen

4  POST /v1/vorgaenge/{vorgang}:ausfuehren
   Atrium-Idempotenz-Schluessel: 01JBP6QRSTVWXYZ234567890EF
   -> 202

5  GET /v1/vorgaenge/{vorgang}
   -> 200 { "zustand": "teilweise_fehlgeschlagen",
            "teilzustand": [ { "zustand": "fehlgeschlagen",
                               "grund": "Kontingent des Vertrags erschoepft",
                               "wiederholbar": true } ] }
   Fehlerantwort bei erneuter Ausfuehrung ohne Kontingentaenderung:
   { "klasse": "kontingent", "schluessel": "postfach.kontingent_erschoepft",
     "handlung": "Kontingent des Vertrags erhoehen und Vorgang wiederholen.",
     "naechster_versuch_nach_ms": 0, "wiederholbar": true }
```

Die Adresse ist ein eigenes Objekt neben dem Postfach; eine entfernte Adresse unterliegt einer Sperrfrist von 12 Monaten (K-25), bevor sie neu vergeben werden kann. `POST /v1/mailadressen:pruefen` ist der einzige Endpunkt dieses Ablaufs ohne Vorgang, weil er nichts ändert.

### A2.10.3 Knoten koppeln

Zwei Entscheidungen (K-03, Aufgabe 24): Kopplungscode und Zweck. Der wartende Knoten zeigt den Code auf seinem Bildschirm; die API sieht ihn nur als Eingabe am Ankerknoten.

```
1  GET /v1/knoten?zustand=wartemodus
   -> 200 { "objekte": [ { "sitzung_id": "01JBQ7RSTVWXYZ234567890GHJ",
                           "bootstrap_fp": "sha2-256:3f9a...",
                           "gesehen_am": "2026-09-15T09:31:02.004Z" } ] }
   Ankuendigung im lokalen Segment; noch kein Knotenobjekt.

2  POST /v1/kopplungsvorgaenge
   Atrium-Idempotenz-Schluessel: 01JBP7QRSTVWXYZ234567890KM
   { "sitzung_id": "01JBQ7RSTVWXYZ234567890GHJ", "code": "XXXX-XXXX-XXXX" }
   -> 202 { "kennung": "01JBD5E6F7G8H9J0KMNPQRSTVW", "zustand": "erzeugt",
            "gueltig_bis": "2026-09-15T09:46:11.000Z",
            "fehlversuche": 0, "fehlversuche_max": 5 }
   Der Code wird laufzeitkonstant verglichen; die API gibt ihn nie zurueck.

3  GET /v1/ereignisse?objekt=urn:atrium:kopplungsvorgang:01JBD5E6F7G8H9J0KMNPQRSTVW
   -> event: kopplung.bestaetigt   (SPAKE2 nach RFC 9382, Kanalbindung geprueft)
   -> event: kopplung.zertifikat_ausgestellt
   -> event: kopplung.rollendialog_offen

4  POST /v1/kopplungsvorgaenge/01JBD5E6F7G8H9J0KMNPQRSTVW:zweck
   { "zweck": "verwaltungsknoten" }
   -> 202 { "vorgang": "urn:atrium:vorgang:01JBR8STVWXYZ234567890NPQ",
            "abgeleitete_rollen": ["stimmknoten","diensttraeger","speichertraeger"],
            "stimmzahl_nachher": 3 }
   Bei stimmzahl_nachher = 2 oder 4 antwortet die API 422 invariante_verletzt (INV-05)
   mit der Handlungsangabe, einen Zeugen aufzunehmen oder mitlesend zu koppeln.

5  GET /v1/knoten/01JBC4D5E6F7G8H9J0KMNPQRST
   -> 200 { "zustand": "produktiv", "aufnahmeweg": "kopplungscode",
            "fehlerzone": "rack-b", "redundanz_anzeige": "1 Knotenausfall vertraeglich" }
```

Ein zweiter Aufruf von Schritt 2 mit demselben Code scheitert: der Kopplungsvorgang ist verbraucht und nicht wiederverwendbar (INV-27). Fünf fehlgeschlagene Bestätiger vernichten den Code; die API antwortet dann `422` mit dem Schlüssel `kopplung.code_vernichtet` und der Handlungsangabe, am wartenden Knoten einen neuen Code zu erzeugen. Die Antwort in Schritt 4 nennt die resultierende Stimmzahl vor der Bestätigung, damit die Ablehnung einer geraden Stimmzahl nicht als unerklärlicher Fehler erscheint.

### A2.10.4 Interne Domäne mit Einträgen und Geltungsbereich

Drei Entscheidungen (K-03, Aufgabe 18): Name, Sichtbarkeit, Geltungsbereich. Der Geltungsbereich steht an der Domäne und nicht am Eintrag ([Kapitel 12](12-dns-netzwerk.md)).

```
1  POST /v1/domaenen
   Atrium-Idempotenz-Schluessel: 01JBP8QRSTVWXYZ234567890RS
   { "anzeige_name": "intern.beispiel.de",
     "sichtbarkeit": "intern",
     "geltungsbereich": { "art": "gruppe",
                          "kennung": "01JBG8H9J0KMNPQRSTVWXYZ234" } }
   -> 202
   { "objekt": { "kennung": "01JBE6F7G8H9J0KMNPQRSTVWXY",
                 "dnssec_status": "signiert",        # Richtlinie (INV-15)
                 "serie": 41209,                     # = Sollzustandsversion
                 "standard_ttl": 300 },              # Richtlinie, interne Sicht
     "vorgang": "urn:atrium:vorgang:01JBS9TVWXYZ234567890PQRS",
     "hinweise": [ { "schluessel": "geltungsbereich.nicht_durchsetzbar",
                     "text": "Fuer 4 Geraete in einer fremdverwalteten Zone ist der
                              Geltungsbereich nicht durchsetzbar.",
                     "objekte": ["urn:atrium:geraet:01JBT2VWXYZ234567890PQRST"] } ] }

2  POST /v1/dnseintraege
   Atrium-Idempotenz-Schluessel: 01JBP9QRSTVWXYZ234567890TV
   { "domaene": "01JBE6F7G8H9J0KMNPQRSTVWXY",
     "art": "A", "name": "ablage", "wert": "10.42.7.15", "sicht": "intern" }
   -> 202 { "objekt": { "kennung": "01JBN4PQRSTVWXYZ2345678901",
                        "ttl": 300, "quelle": "handeingegeben" } }

3  PATCH /v1/dnseintraege/01JBN4PQRSTVWXYZ2345678901
   If-Match: "41210"
   { "ttl": 600 }
   -> 202
   Ohne If-Match: 412 vorbedingung_fehlt.

4  GET /v1/artefakte?quell_objekt=urn:atrium:domaene:01JBE6F7G8H9J0KMNPQRSTVWXY
   -> 200 { "objekte": [ { "art": "dnseintrag", "name": "ticket",
                           "quelle": "urn:atrium:veroeffentlichung:01JBU3WXYZ...",
                           "zustand": "aktiv" } ] }
   PATCH auf ein Artefakt dieser Liste: 405 nicht_erlaubt (INV-09),
   Handlungsangabe verweist auf die Veroeffentlichung als Quelle.

5  GET /v1/domaenen/01JBE6F7G8H9J0KMNPQRSTVWXY?felder=serie,zustand,geltungsbereich
   -> 200 { "zustand": "aktiv", "serie": 41211 }
   Zielwert bis zur Aufloesbarkeit im Zielbereich p95 <= 5 s (K-17).
```

Der Hinweis in Schritt 1 ist keine Fehlermeldung und blockiert nichts; er ist die maschinenlesbare Form der Aussage, dass Gerätegranularität in einer fremdverwalteten Zone nicht durchsetzbar ist. Eine Zusicherung, die die Technik nicht einlöst, wäre eine Falschaussage in der Oberfläche.

## A2.11 Ratenbegrenzung, Rückstau, Fristen, Wiederholung

### Ratenbegrenzung

Zwei Eimer gleichzeitig: je Token und je Mandant. Der Tokeneimer schützt den Mandanten vor einem fehlerhaften Aufrufer, der Mandanteneimer schützt die Installation vor einem Mandanten.

| Eimer | Dauerrate (Zielwert) | Spitze | Bemessungsgrund |
|---|---|---|---|
| Sitzungstoken einer Person, lesend | 120/min | 240 | Eine bedienende Person löst nach Annahme höchstens 2 Aufrufe je Sekunde aus; darüber arbeitet ein Skript. |
| Sitzungstoken einer Person, schreibend | 30/min | 60 | Jeder schreibende Aufruf erzeugt einen Vorgang mit Wirkungsvorschau. |
| Dienstkontotoken, lesend | 600/min | 1.200 | Abgleichläufe externer Automatisierung. |
| Dienstkontotoken, schreibend | 120/min | 240 | Begrenzt die Konnektorlast, die eine Massenzuweisung erzeugt. |
| Mandant gesamt, schreibend | 300/min | 600 | Obergrenze aller Dienstkonten und Personen eines Mandanten zusammen. |

Kopfzeilen jeder Antwort: `Atrium-Ratenrest` (verbleibende Anfragen im Fenster) und bei Ablehnung `Retry-After` zusammen mit `naechster_versuch_nach_ms`. Die Ablehnung trägt die Klasse `kontingent`.

**Rechnung zur Verträglichkeit mit den Beispielabläufen.** Der Ablauf A2.10.1 benötigt 2 schreibende und 3 lesende Aufrufe, A2.10.2 je 2 und 3, A2.10.4 3 und 2. Bei 30 schreibenden Aufrufen je Minute legt eine Person nach diesem Modell 30/2 = 15 Nutzer je Minute an, bevor die Begrenzung greift; mit der Spitze von 60 sind es 30 in der ersten Minute. Eine Massenanlage von 500 Nutzern dauert 500 × 2 / 30 ≈ 33 min über die Oberfläche und 500 × 2 / 120 ≈ 8,3 min über ein Dienstkonto. Deutung: Für Massenanlage ist der Weg über ein Dienstkonto oder über SCIM vorgesehen; die Oberflächenbegrenzung ist so gesetzt, dass sie bedienendes Arbeiten nie trifft und ein irrtümlich gestartetes Skript in der Oberfläche schnell trifft. Das ist ein Modell aus den genannten Annahmen, keine Messung.

### Rückstau

Ratenbegrenzung schützt vor zu vielen Anfragen, Rückstau vor zu langer Arbeit. Überschreitet die Warteschlange der Kontrollebene ihre Länge, antwortet die API sofort mit `rueckstau` und `Retry-After`, statt die Anfrage zu halten. Verworfene Alternative: unbegrenztes Warten. Es verschiebt die Überlast in Verbindungen und Zeitüberschreitungen des Aufrufers und macht die Ursache unsichtbar; eine sofortige, klassifizierte Ablehnung ist für den Aufrufer auswertbar. Der Rückstau wird im Überblick als Störung mit verlinktem Objekt sichtbar und nicht nur in einer Kennzahl.

### Fristen

| Operation | Frist (Zielwert) | Herleitung |
|---|---|---|
| Lesender Aufruf | 5 s | K-18 fordert p95 ≤ 300 ms; die Frist liegt um den Faktor 16 darüber und fängt Ausreißer ab, statt sie zu verdecken. |
| Schreibender Aufruf bis `202` | 10 s | Der Aufruf wartet nur auf die Protokollbestätigung des Sollzustands, nicht auf die Versorgung; K-05 nennt für den Führungswechsel ≤ 5 s. |
| Wirkungsvorschau | 20 s | K-18 nennt p95 ≤ 2 s; die Frist deckt sieben parallele Bindungen mit je ≤ 2 s Trockenlaufantwort (K-22) und Reserve. |
| Ausführung eines Vorgangs | harte Obergrenze 15 min | K-15; die Oberfläche zeigt nie unbegrenzt "in Arbeit". |
| Ereignisstrom im Leerlauf | unbegrenzt, Lebenszeichen alle 15 s | Ein Strom ohne Ereignisse ist der Normalzustand. |

### Empfohlenes Wiederholungsverhalten

| Klasse | Wiederholen | Verfahren |
|---|---|---|
| `zeitueberschreitung`, `rueckstau`, `intern` | ja | exponentiell, Basis 500 ms, Faktor 2, Höchstwert 60 s, Streuung 20 %, höchstens 6 Versuche |
| `kontingent` | ja | frühestens ab `naechster_versuch_nach_ms`, nie früher; die Rate kommt vom Zielsystem und wird nie selbst gewählt |
| `eingefroren` | ja | mit 30 s Abstand, ohne Verkürzung; die Ursache ist fehlendes Quorum und nicht Last |
| `konflikt`, `vorbedingung_fehlt` | nur nach Neuladen | die identische Anfrage scheitert erneut |
| `schemafehler`, `nicht_berechtigt`, `nicht_gefunden`, `nicht_erlaubt`, `invariante_verletzt` | nein | die Anfrage ist falsch, nicht unglücklich |
| `zielsystem_fehler` | abhängig | die Konnektorklasse entscheidet ([Kapitel 09](09-konnektoren.md)) |

Die Streuung ist Pflicht und nicht Kür: ohne sie wiederholen alle Aufrufer nach einer gemeinsamen Störung gleichzeitig und erzeugen genau die Lastspitze, gegen die die Rückstaffelung schützen soll. Die Wiederholung trägt immer denselben Idempotenzschlüssel; eine Wiederholung mit neuem Schlüssel ist eine zweite Absicht und wird als solche ausgeführt.

## A2.12 Stabilität und Abkündigung

| Zusage | Festlegung |
|---|---|
| Stabilitätsstufen | `stabil`, `in Erprobung`, `abgekündigt`. Die Stufe steht je Endpunkt in der Ressourcenübersicht der Auslieferung und wird über `GET /v1/aufgaben` und die Endpunktbeschreibung maschinenlesbar geführt. |
| Rückwärtsverträgliche Änderungen | Neue optionale Felder, neue Endpunkte, neue Aufzählungswerte mit definiertem Rückfallverhalten. Sie erhöhen `schema_version` im MINOR-Teil und lassen `v1` unberührt (KANON.md 3). |
| Brechende Änderungen | Entfernen eines Feldes, Verengen eines Wertebereichs, Ändern der Bedeutung eines bestehenden Feldes, Ändern eines Statuscodes. Sie erfordern `v2` und eine Schemamigration als eigenen, separat rückrollbaren Vorgang (INV-24). |
| Unbekannte Aufzählungswerte | Ein Aufrufer, der einen unbekannten Wert empfängt, behandelt ihn als `unbekannt` und zeigt ihn unverändert an; ein Aufrufer, der einen unbekannten Wert sendet, erhält `schemafehler`. Die Asymmetrie ist Absicht: Lesen muss verträglich sein, Schreiben muss eindeutig sein. |
| Abkündigungsfrist | Zielwert 12 Monate zwischen Ankündigung und Abschaltung, mindestens aber eine vollständige LTS-Basisspanne des Kunden. |
| Ankündigungsweg | Kopfzeile `Atrium-Abkuendigung: <Abschaltdatum>; ersatz="<pfad>"` an jeder Antwort des betroffenen Endpunkts, zusätzlich ein Eintrag im Überblick mit der Liste der Dienstkonten, die den Endpunkt in den letzten 30 Tagen benutzt haben. |
| Ausnahme Sicherheit | Muss ein Endpunkt wegen einer Schwachstelle vor Ablauf der Frist entfallen, gilt die Ausrollfrist für die Außenkante aus K-23 (≤ 72 h); die Ausnahme ist als solche auditiert und nennt den Grund. |
| Was nie zugesagt wird | Die Reihenfolge von Feldern in JSON, die Reihenfolge gleichrangiger Listeneinträge ohne angegebene Sortierung, die Textform von `text` und `handlung` sowie die Zahl der Aufrufe, die die Konsole für eine Aufgabe benutzt. |

Die Ankündigungsliste der benutzenden Dienstkonten ist der Punkt, an dem die Abkündigung von einer Absichtserklärung zu einer prüfbaren Handlung wird: ohne sie kennt niemand die betroffenen Aufrufer, und die Frist verstreicht ungenutzt.

## A2.13 Benannte Schwächen dieses Entwurfs

1. Die Zuordnung der Gültigkeitsbereiche zu den Aktionsklassen der Rechtematrix ist Entwurfsstand. [Kapitel 19](19-mandanten-rechte-audit.md) hält fest, dass ein verbindlicher Katalog der Aktionsklassen fehlt; solange er fehlt, ist die Tabelle in A2.3 eine plausible, aber nicht abgeleitete Aufteilung.
2. Die Durchschnittsregel aus Token, Rolle und Mandant erzeugt einen Fehlerfall, der schwer verständlich zu formulieren ist: der Bediener darf, sein Token darf nicht. Die Meldung muss ohne die Begriffe Token und Gültigkeitsbereich auskommen (INV-16), und der Entwurf hat dafür bisher nur die Formulierung "diese Verbindung ist für diese Handlung nicht eingerichtet".
3. Die Zeigerblätterung liefert über Seitengrenzen hinweg keine konsistente Menge, und die Abhilfe `bei_version` ist auf das Aufbewahrungsfenster des Lesemodells begrenzt. Für einen Export über 10.000 Objekte bei laufender Änderung gibt es damit keine Zusage der Form "genau der Stand zum Zeitpunkt t", außer über einen Sollzustandsexport, der ein anderes Objekt ist.
4. Der Zustand `unbekannt` eines Zielsystems wandert unverändert von der Konnektorkante in die API. Der Aufrufer erhält damit einen Vorgang, der weder erfolgreich noch fehlgeschlagen ist, bis eine Beobachtung eintrifft. Das ist ehrlich, aber es ist auch ein Zustand, für den eine Oberfläche keine befriedigende Darstellung hat.

## Anforderungen

- **R-A2-01** — Die Atrium Console löst keine Operation aus, die nicht über einen in diesem Anhang geführten Endpunkt erreichbar ist. Prüfbar: Bau gegen eine API-Fassade, die nicht dokumentierte Endpunkte sperrt; ein Zugriff bricht den Bau (INV-01).
- **R-A2-02** — Jede Antwort führt `Atrium-Schema-Version` und `Atrium-Korrelation`. Prüfbar: Kopfzeilenprüfung über alle Endpunkte und alle Fehlerklassen; eine fehlende Kopfzeile bricht den Bau (KANON.md 3).
- **R-A2-03** — Jeder schreibende Aufruf antwortet mit `202` und einem Vorgangsverweis; es existiert kein Endpunkt, der eine Änderung ohne Vorgang ausführt. Prüfbar: Endpunktabzählung; jede `200`- oder `201`-Antwort auf ein schreibendes Verb bricht den Bau (INV-03).
- **R-A2-04** — Für abgeleitete Artefakte existiert keine Schreiboperation; `PATCH`, `POST` und `DELETE` auf `/v1/artefakte` antworten `405` mit Verweis auf die Quelle. Prüfbar: Schreibversuch je Artefaktart (INV-09).
- **R-A2-05** — Es existiert kein Endpunkt, der ein Geheimnis im Klartext zurückgibt, und kein Gültigkeitsbereich mit lesender Geheimnissemantik. Prüfbar: Endpunktabzählung plus Ausgabeprüfung gegen Geheimnismuster über alle Antworten (INV-20).
- **R-A2-06** — `PATCH` und `DELETE` ohne `If-Match` werden mit `vorbedingung_fehlt` abgelehnt. Prüfbar: Aufruf ohne Kopfzeile je schreibbarer Ressource.
- **R-A2-07** — Zwei identische Aufrufe mit demselben Idempotenzschlüssel erzeugen genau ein Objekt, genau einen Vorgang und 0 Auditereignisse vom Typ "geändert" beim zweiten Aufruf. Prüfbar: Doppellauftest im Bau (INV-07).
- **R-A2-08** — Ein Aufruf mit bekanntem Idempotenzschlüssel und abweichendem Inhalt wird mit `konflikt` abgelehnt; der Vergleich erfolgt über die kanonische Form nach RFC 8785. Prüfbar: Wiederholung mit geändertem Feld und mit ausschließlich geänderter Schlüsselreihenfolge; die erste wird abgelehnt, die zweite als Wiederholung erkannt.
- **R-A2-09** — Jede Fehlerantwort trägt `klasse`, `schluessel`, `text`, `handlung` und `korrelationskennung`; die Zahl der Fehlerantworten ohne Handlungsangabe ist 0. Prüfbar: Fehlerinjektion über alle Klassen und Musterprüfung (INV-17).
- **R-A2-10** — Keine Fehlerantwort enthält Aufrufspuren, Dateipfade, Abfragetext, interne Adressen oder Fremdsystemtexte. Prüfbar: Musterprüfung aller Fehlerantworten im Bau; ein Treffer bricht den Bau (INV-16).
- **R-A2-11** — Eine Anfrage auf ein Objekt außerhalb des Mandantenbezugs des Tokens antwortet `nicht_gefunden` und nicht `nicht_berechtigt`. Prüfbar: mandantenübergreifender Leseversuch für jede Ressource (INV-19).
- **R-A2-12** — Jede Abfrage trägt ein Mandantenprädikat; eine Anfrage ohne ableitbaren Mandantenbezug wird abgelehnt. Prüfbar: Aufruf mit plattformweitem Token ohne `Atrium-Mandant` auf einer mandantengebundenen Ressource (INV-19).
- **R-A2-13** — Filter-, Sortier- und Feldnamen stammen aus einer Positivliste je Ressource; ein nicht gelisteter Name wird abgelehnt und nicht ignoriert. Prüfbar: Aufruf mit erfundenem Feldnamen für jede Ressource.
- **R-A2-14** — Kein Filterwert erreicht die Abfrage anders als als gebundener Parameter; es existiert keine Zeichenkettenverkettung im Abfragepfad. Prüfbar: statische Prüfung der Datenzugriffsschicht plus Einschleusungstest über alle Operatoren.
- **R-A2-15** — Die Seitengröße ist auf 200 begrenzt, die Vorgabe beträgt 50. Prüfbar: Aufruf mit `grenze=500` wird mit `schemafehler` abgelehnt (K-18).
- **R-A2-16** — Ein `plan`-Aufruf über `entwurf: true` verändert 0 Objekte in Atrium und 0 Objekte in Fremdsystemen. Prüfbar: Konnektor-Vertragstest mit Istzustandsvergleich vor und nach dem Aufruf (INV-08).
- **R-A2-17** — Ein Vorgang erreicht `abgeschlossen` nur, wenn jeder Eintrag in `teilzustand` `erfolgreich` ist. Prüfbar: Fehlerinjektion in einen von mehreren Konnektoren; der Vorgang muss `teilweise_fehlgeschlagen` mit benannten Resten melden (INV-12).
- **R-A2-18** — Eine Wirkungsvorschau mit `vorschau_basis_version` kleiner als die aktuelle Sollzustandsversion wird bei `:ausfuehren` mit `konflikt` abgelehnt. Prüfbar: Änderung zwischen Vorschau und Ausführung.
- **R-A2-19** — Jeder Eintrag der Wirkungsliste nennt Zielsystem, Aktion, Objekt, Umkehrbarkeit, Kostenwirkung und die Quelle jeder Vorbelegung. Prüfbar: Feldprüfung über alle Vorgangsarten (INV-15, INV-29).
- **R-A2-20** — Der Ereignisstrom nimmt nach einem Abriss ab `Last-Event-ID` ohne Ereignisverlust wieder auf; ist die Kennung nicht mehr im Puffer, sendet er `luecke` mit der ältesten verfügbaren Kennung. Prüfbar: Abrisstest mit 1.000 Ereignissen während der Unterbrechung und Pufferüberlauftest.
- **R-A2-21** — Der Ereignisstrom überträgt 0 Geheimnisse, 0 Auditanhänge und 0 Fremdsystemtexte. Prüfbar: Ausgabeprüfung des Stroms gegen Geheimnis- und Fremdtextmuster (INV-20).
- **R-A2-22** — Eine Mitgliedschaftsänderung, die zu einer Stimmzahl von 2 oder 4 führt, wird mit `invariante_verletzt` abgelehnt; die Antwort nennt die resultierende Stimmzahl. Prüfbar: Kopplung eines zweiten und eines vierten Verwaltungsknotens (INV-05).
- **R-A2-23** — Ohne Quorum antwortet jeder schreibende Endpunkt mit `eingefroren`; lesende Endpunkte antworten weiter. Prüfbar: Partitionstest auf der Minderheitsseite über alle schreibenden Endpunkte (INV-04).
- **R-A2-24** — Ein abgekündigter Endpunkt liefert `Atrium-Abkuendigung` mit Abschaltdatum und Ersatzpfad an jeder Antwort. Prüfbar: Abkündigung eines Testendpunkts und Kopfzeilenprüfung.
- **R-A2-25** — Ein Aufrufer, der einen unbekannten Aufzählungswert sendet, erhält `schemafehler`; ein Aufrufer, der einen unbekannten Wert empfängt, erhält ihn unverändert. Prüfbar: Vor- und Rückwärtstest gegen ein Schema mit zusätzlichem Wert (KANON.md 3).
- **R-A2-26** — Tokenkennungen, Idempotenzschlüssel und Kopplungscodes werden laufzeitkonstant verglichen. Prüfbar: statische Prüfung der Vergleichsstellen; ein früh abbrechender Vergleich bricht den Bau.

## Akzeptanzkriterien

| Kriterium | Anforderung | Nachweis |
|---|---|---|
| Die Konsole läuft vollständig gegen die Fassade, die nicht dokumentierte Endpunkte sperrt; Zugriffe darauf: 0 | R-A2-01 | Bauprüfung mit gesperrter Fassade über den vollständigen Aufgabenkatalog |
| Über alle Endpunkte und alle 15 Fehlerklassen: 100 % der Antworten mit `Atrium-Schema-Version` und `Atrium-Korrelation` | R-A2-02 | Kopfzeilenprüfung mit Fehlerinjektion je Klasse |
| Schreibende Verben mit Antwort ungleich `202`: 0 | R-A2-03 | Endpunktabzählung im Bau |
| Schreibversuche auf abgeleitete Artefakte: 100 % `405` mit Quellverweis | R-A2-04 | Schreibversuch je Artefaktart (Firewallregel, Proxyroute, DNS-Eintrag, Zertifikatsantrag, Fremdkonto) |
| Endpunkte mit Klartextrückgabe eines Geheimnisses: 0; Gültigkeitsbereiche mit lesender Geheimnissemantik: 0 | R-A2-05 | Endpunktabzählung plus Ausgabeprüfung über alle Antworten und den Ereignisstrom |
| Doppellauf mit gleichem Idempotenzschlüssel: 1 Objekt, 1 Vorgang, 0 Änderungsereignisse beim zweiten Lauf | R-A2-07 | Doppellauftest über alle vier Beispielabläufe aus A2.10 |
| Wiederholung mit geänderter Schlüsselreihenfolge: als Wiederholung erkannt; mit geändertem Feldwert: `konflikt` | R-A2-08 | Kanonisierungstest nach RFC 8785 |
| Fehlerantworten ohne Handlungsangabe: 0; Treffer auf Befehls-, Pfad- und Fremdtextmuster: 0 | R-A2-09, R-A2-10 | Musterprüfung aller Fehlerantworten im Bau (K-27) |
| Mandantenübergreifender Leseversuch: 100 % `nicht_gefunden`, 0 `nicht_berechtigt` | R-A2-11 | Leseversuch je Ressource mit fremdem Mandantenbezug |
| Einschleusungsversuche über alle fünf Filteroperatoren: 0 erfolgreiche | R-A2-14 | Einschleusungstest plus statische Prüfung der Datenzugriffsschicht |
| Erstanzeige einer Liste bei 10.000 Objekten und Seitengröße 200: p95 ≤ 300 ms | R-A2-15 | Lasttest gegen das materialisierte Lesemodell (K-18) |
| Istzustand eines Fremdsystems nach `plan`: unverändert in 100 % der Vertragstests | R-A2-16 | Konnektor-Vertragstest mit Vorher-Nachher-Vergleich |
| Fehlerinjektion in 1 von 3 Bindungen: Vorgang meldet `teilweise_fehlgeschlagen` mit ≥ 1 benanntem Rest; 0 Meldungen `abgeschlossen` | R-A2-17 | Teilerfolgstest über den Ablauf aus A2.10.2 |
| Abriss des Ereignisstroms während 1.000 Ereignissen: 0 verlorene Ereignisse bei Wiederaufnahme; bei Überlauf genau 1 `luecke` | R-A2-20 | Abriss- und Überlauftest |
| Kopplung eines zweiten Verwaltungsknotens: `422` mit genannter Stimmzahl 2 und Handlungsangabe | R-A2-22 | Kopplungstest nach A2.10.3 |
| Auf der Minderheitsseite einer Partition: 100 % der schreibenden Endpunkte `eingefroren`, 100 % der lesenden erfolgreich | R-A2-23 | Partitionstest über den vollständigen Endpunktsatz |
| Vergleichsstellen mit früh abbrechendem Vergleich von Token, Idempotenzschlüssel oder Kopplungscode: 0 | R-A2-26 | statische Prüfung im Bau |

## Offene Punkte

1. **Der Katalog der Aktionsklassen fehlt, und damit die Ableitung der Gültigkeitsbereiche.** [Kapitel 19](19-mandanten-rechte-audit.md) führt rund dreißig Aktionsklassen in der Rechtematrix und nimmt für die Indexselektivität zwölf an; dieser Anhang führt 30 Gültigkeitsbereiche. Drei Zahlen, drei Quellen, keine Ableitung. Vor der ersten Implementierung der Rechteprüfung muss ein verbindlicher Katalog entstehen, der jeder API-Operation genau eine Aktionsklasse und jedem Gültigkeitsbereich eine Menge von Aktionsklassen zuordnet. Ohne diese Zuordnung ist weder R-A2-13 noch die Rechtematrix maschinell prüfbar.
2. **Die Sprache der Fehlertexte für nichtmenschliche Aufrufer ist unbestimmt.** `text` und `handlung` werden in der Sprache des Subjekts geliefert; ein Dienstkonto hat keine Sprache. Drei Wege sind denkbar: die Sprache des Mandanten verwenden (dann hängt der Text an einer Einstellung, die niemand für die Automatisierung setzt), die Felder für Dienstkonten weglassen (dann verletzt die Antwort R-A2-09) oder eine feste Rückfallsprache festlegen (dann erscheinen in Protokollen zwei Sprachen nebeneinander). Keiner ist entschieden.
3. **Die Bemessungsgröße der Ratenbegrenzung ist nicht entschieden.** Der Mandanteneimer lässt ein einzelnes fehlerhaftes Dienstkonto die Konsole desselben Mandanten aushungern. Eine getrennte Reserve für Sitzungstoken würde das verhindern, erzeugt aber eine zweite Größe, die bei Lastspitzen erklärt werden muss, und verschiebt die Frage nur auf die Reservegröße. Eine dritte Möglichkeit, die Begrenzung an die Quelladresse zu binden, scheitert an Zwischenstellen mit gemeinsamer Adresse.
4. **Die Konsistenzzusage über Seitengrenzen hinweg ist ungelöst.** `bei_version` verlangt, dass das materialisierte Lesemodell alte Sollzustandsversionen vorhält. Der Zielwert 15 min ist gesetzt, nicht hergeleitet, und der Speicherbedarf hängt von der Änderungsrate ab, für die keine Annahme belastbar ist. Offen ist, ob ein Export über die API überhaupt konsistent sein muss oder ob der Sollzustandsexport aus [Kapitel 17](17-speicher-backup.md) der einzige zugesagte konsistente Weg bleibt.
5. **Die Abkündigungsfrist von 12 Monaten ist mit der Sicherheitsausnahme nicht verträglich gedacht.** Ein Endpunkt, der binnen 72 h entfallen muss, bricht jeden Aufrufer, der ihn benutzt. Ob es dafür einen Übergangsweg gibt (Endpunkt bleibt bestehen und antwortet dauerhaft mit einer Fehlerklasse, statt zu verschwinden) oder ob der Bruch hinzunehmen ist, ist nicht entschieden.
6. **Die Prüfung von INV-01 deckt nur die Konsole ab.** Die API-Fassade im Bau beweist, dass die Konsole keinen privaten Pfad benutzt. Sie beweist nicht, dass in atrium-core kein Endpunkt existiert, der nur von einer internen Komponente benutzt wird und faktisch ein privater Pfad wäre. Ein Nachweis über den vollständigen Endpunktsatz des Kerns — etwa eine erzeugte Liste aller registrierten Routen, abgeglichen gegen die Ressourcenübersicht dieses Anhangs — fehlt und ist zu entwerfen.
