# 07 Objektmodell und Datenhaltung

## 7.1 Modellschichten und Feldklassen

Das Objektmodell zerfällt in vier Schichten mit getrennter Speicherung, getrenntem Schreibweg und getrennter Aufbewahrung. Die Trennung ist die Voraussetzung dafür, dass INV-02, INV-09, INV-23 und INV-28 gleichzeitig gelten können.

| Schicht | Inhalt | Speicherort | Schreibweg | Quorumpflicht | Teil des Exports |
|---|---|---|---|---|---|
| Sollzustand | Absicht des Bedieners: Mandant, Person, Gruppe, Gerät, Zuweisung, Dienst, Domäne, Richtlinie, Konnektorbindung | hashverkettetes Änderungsprotokoll in atrium-core | ausschließlich Vorgang (INV-03) | ja (INV-04) | ja |
| Lesemodell | deterministische Materialisierung des Sollzustands plus Indizes | eingebettete SQL-Engine je Verwaltungsknoten | nur Reconciler, nie ein Bediener | nein, wiederherstellbar | nein, neu baubar |
| Istzustand | Beobachtungen mit Beobachtungszeitpunkt: Gesundheit, Platzierung, Versorgungszustand je Zielsystem, Replikationsstand | knotenlokal, verdichtet repliziert (≤ 1 KB je Objekt, K-12) | nur Beobachter und Konnektoren | nein | nein |
| Nachweis | Auditereignisse, Grabsteine, Vernichtungsbelege | getrennter, anhängbarer Strom außerhalb des replizierten Kernzustands (INV-23) | nur Anhängen | nein | Auditkette im Export enthalten |

Jedes Feld eines Objekts trägt genau eine Feldklasse. Die Klasse entscheidet, wer schreiben darf, ob das Feld repliziert wird und ob es im Export erscheint.

| Feldklasse | Kürzel | Wer schreibt | Repliziert | Im Export | Beispiel |
|---|---|---|---|---|---|
| Schlüssel | K | einmalig bei Anlage, danach unveränderlich | ja | ja | `kennung`, `mandant` |
| Absichtsfeld | S | Bediener über einen Vorgang | ja | ja | `gueltig_bis` einer Zuweisung |
| Ableitungsfeld | A | ausschließlich der Reconciler | ja, wenn Teil des Sollzustands | ja | `technischer_name`, `anmelde_name` |
| Beobachtungsfeld | I | Beobachter, nie ein Bediener | verdichtet | nein | `letzte_meldung` eines Geräts |
| Geheimnisreferenz | G | Bediener schreibt den Wert, liest ihn nie (INV-20) | nur die Referenz | nur die Referenz | `geheimnis_ref` |

Ein Schreibversuch eines Bedieners auf ein Feld der Klasse A oder I wird von der API abgelehnt, nicht stillschweigend verworfen. Das ist die Durchsetzung von INV-09 auf Feldebene: abgeleitete Artefakte sind nicht editierbar, und abgeleitete Felder sind es ebenso wenig.

## 7.2 Gemeinsamer Objektrumpf

Jedes Objekt jeder Entität trägt denselben Rumpf. Der Rumpf ist der Ort, an dem Mandantenpflicht (INV-19), Schemaversion, Vorgangsherkunft und Grabsteinfähigkeit einheitlich verankert sind, statt je Entität wiederholt zu werden.

| Feld | Typ | Pflicht | Wertebereich / Regel | Klasse |
|---|---|---|---|---|
| `kennung` | ULID | ja | 26 Zeichen Crockford-Base32, global eindeutig, unveränderlich | K |
| `typ` | Aufzählung | ja | einwortiger Entitätstyp kleingeschrieben, siehe Identifikatorabschnitt | K |
| `mandant` | ULID | ja | Verweis auf einen Mandanten; Plattformobjekte verweisen auf den reservierten Plattform-Mandanten | K |
| `schema_version` | Text | ja | `MAJOR.MINOR`, beide ganzzahlig, keine Patch-Ebene | K |
| `anzeige_name` | Text ≤ 128 | ja | Unicode, NFC-normalisiert, je Mandant und Typ eindeutig | S |
| `technischer_name` | Text ≤ 63 | ja | `[a-z][a-z0-9-]{0,62}`, abgeleitet, DNS-tauglich | A |
| `zustand` | Aufzählung | ja | je Entität definierter Lebenszyklus | S oder A |
| `erzeugt_am` | Zeitpunkt | ja | UTC, Sekundenauflösung, aus der geprüften Zeitquelle (INV-32) | K |
| `erzeugt_durch_vorgang` | ULID | ja | Verweis auf den Vorgang, der das Objekt anlegte | K |
| `geaendert_am` | Zeitpunkt | ja | UTC, ≥ `erzeugt_am` | A |
| `geaendert_durch_vorgang` | ULID | ja | Verweis auf den letzten ändernden Vorgang | A |
| `sollzustand_version` | Ganzzahl | ja | monoton steigende Version des Sollzustands bei der letzten Änderung | A |
| `etikett` | Abbildung Text→Text | nein | höchstens 32 Paare, Schlüssel `[a-z][a-z0-9_.-]{0,62}`, Wert ≤ 256 Zeichen | S |
| `zusatz` | Objekt | nein | unveränderlich durchgereichte Felder einer neueren MINOR-Version | A |
| `geloescht_am` | Zeitpunkt | nein | gesetzt genau dann, wenn das Objekt ein Grabstein ist | A |

```json
{
  "$id": "urn:atrium:schema:objektrumpf:1.0",
  "type": "object",
  "required": ["kennung", "typ", "mandant", "schema_version", "anzeige_name",
               "technischer_name", "zustand", "erzeugt_am", "erzeugt_durch_vorgang",
               "geaendert_am", "geaendert_durch_vorgang", "sollzustand_version"],
  "properties": {
    "kennung":        { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "typ":            { "type": "string", "pattern": "^[a-z]{3,32}$" },
    "mandant":        { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "schema_version": { "type": "string", "pattern": "^[0-9]+\\.[0-9]+$" },
    "anzeige_name":   { "type": "string", "minLength": 1, "maxLength": 128 },
    "technischer_name": { "type": "string", "pattern": "^[a-z][a-z0-9-]{0,62}$" },
    "zustand":        { "type": "string", "pattern": "^[a-z_]{3,32}$" },
    "erzeugt_am":     { "type": "string", "format": "date-time" },
    "geaendert_am":   { "type": "string", "format": "date-time" },
    "erzeugt_durch_vorgang":   { "$ref": "#/properties/kennung" },
    "geaendert_durch_vorgang": { "$ref": "#/properties/kennung" },
    "sollzustand_version": { "type": "integer", "minimum": 1 },
    "etikett": {
      "type": "object", "maxProperties": 32,
      "propertyNames": { "pattern": "^[a-z][a-z0-9_.-]{0,62}$" },
      "additionalProperties": { "type": "string", "maxLength": 256 }
    },
    "zusatz": { "type": "object" },
    "geloescht_am": { "type": "string", "format": "date-time" }
  }
}
```

Das Rumpfschema trägt selbst keine Abschlussangabe. `additionalProperties: false` bewertet die vollständige Instanz und nicht nur die Felder des Teilschemas, in dem es steht; in `objektrumpf:1.0` notiert, würde es jede entitätseigene Eigenschaft der einbindenden Entität als zusätzliche Eigenschaft verwerfen und damit jedes gültige Objekt ablehnen. Der Abschluss gehört deshalb ausschließlich auf die äußerste Ebene des jeweiligen Entitätsschemas, und zwar als `unevaluatedProperties: false`, weil nur dieses Schlüsselwort die Ergebnisse der über `$ref` eingebundenen Teilschemata berücksichtigt (R-A1-01).

Regeln, die das Schema nicht ausdrückt und die die Datenzugriffsschicht prüft: `anzeige_name` wird vor dem Eindeutigkeitsvergleich nach NFC normalisiert und auf Groß-/Kleinschreibung reduziert; `geaendert_am` ist niemals kleiner als `erzeugt_am`; `mandant` ist bei jedem Lesen Teil des Abfrageprädikats, sonst lehnt die Datenzugriffsschicht die Abfrage ab (INV-19); `zusatz` wird bei der kanonischen Serialisierung nach RFC 8785 mitsigniert, damit ein Knoten älterer MINOR-Version ein Objekt nicht durch bloßes Zurückschreiben verstümmelt.

## 7.3 Kernentitäten

Die Konsole nennt die Entität Person durchgehend "Nutzer" und die laufende Instanz eines Katalogeintrags "Dienst"; im Modell heißen sie Person und Dienst. Die folgenden Tabellen führen nur die Felder über den Rumpf hinaus. Spalte "Kl." ist die Feldklasse.

### Mandant

Zweck: oberste Eigentums-, Isolations- und Fehlerdomänengrenze. Der Mandant ist das einzige Objekt, dessen `mandant`-Feld auf sich selbst verweist; damit bleibt das Pflichtprädikat aus INV-19 ohne Sonderfall.

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `isolationsstufe` | Aufzählung | ja | `m0`, `m1`, `m2`, `m3`; Erhöhung online, Absenkung nur mit Bestätigung und Auditeintrag | S |
| `kurzname` | Text ≤ 16 | ja | `[a-z][a-z0-9-]{0,15}`, global eindeutig, unveränderlich; Bestandteil von Systembenutzer- und Speicherpfadnamen | K |
| `zwischen_ca` | ULID | ja | Verweis auf die Mandanten-Zwischen-CA, ab M0 vorhanden | A |
| `hauptschluessel_ref` | Text | ja | Referenzname, nie Wert | G |
| `sicherungsziel` | ULID | ja | Verweis auf ein Auslagerungsziel | S |
| `standardrichtlinien` | Liste\<ULID\> | ja | ≥ 1; Quelle jeder Vorbelegung (INV-15) | S |
| `exklusive_knotenklasse` | Text ≤ 32 | nur bei M3 | Pflichtfeld genau dann, wenn `isolationsstufe = m3` | S |
| `fehlerdomaene_anzeige` | Text | ja | berechnete Klartextaussage nach K-28 | A |

Eindeutigkeit: `kurzname` global; `anzeige_name` global. Der Plattform-Mandant wird bei der Erstinstallation mit fester Kennung angelegt und kann nicht gelöscht, nicht umbenannt und nicht gesperrt werden.

### Person

Zweck: Identität eines Menschen und alleinige Quelle aller abgeleiteten Fremdkonten. Eine Person ist niemals ein Fremdkonto; das Fremdkonto ist ein abgeleitetes Artefakt einer Zuweisung.

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `anmelde_name` | Text ≤ 64 | ja | abgeleitet nach Mandantenrichtlinie, kollisionsauflösend, nach Erzeugung unveränderlich | A |
| `nachname`, `vorname` | Text ≤ 64 | ja / nein | Unicode NFC; `nachname` Pflicht, `vorname` optional (einnamige Personen) | S |
| `authentisierungsmittel` | Liste\<Objekt\> | ja | ≥ 1 Eintrag; Art `passkey`, `totp`, `zertifikat`; Kennwort nur, wenn die Richtlinie es erlaubt | S |
| `gueltig_von` / `gueltig_bis` | Zeitpunkt | ja / nein | `gueltig_bis` > `gueltig_von`; Erreichen löst den Übergang nach `ausgeschieden` aus | S |
| `sprache` | Text | ja | Sprachkennung, Vorbelegung aus der Mandantenrichtlinie | S |
| `personal_kennzeichen` | Text ≤ 64 | nein | fremdgeführte Personalnummer; je Mandant eindeutig, wenn gesetzt | S |
| `letzte_anmeldung` | Zeitpunkt | nein | Beobachtung, nie Entscheidungsgrundlage außer für Anzeigezwecke | I |

Eindeutigkeit: `anmelde_name` je Mandant; `personal_kennzeichen` je Mandant, wenn gesetzt; `anzeige_name` je Mandant. Die Kombination Vorname/Nachname ist ausdrücklich nicht eindeutig, weil Namensgleichheit ein Normalfall ist.

### Gruppe

Zweck: Bündelung von Personen, Geräten und Gruppen für Rechte, Adressierung und Geltungsbereiche. Die Gruppe ist der Ort der Massenwirkung; sie ersetzt keine Rolle.

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `art` | Aufzählung | ja | `rechte`, `verteiler`, `geltungsbereich` | S |
| `mitgliedschaftsregel` | Objekt | ja | `{ "modus": "statisch" }` oder `{ "modus": "abgeleitet", "ausdruck": ... }` | S |
| `mitglieder` | Liste\<ULID\> | nur bei `statisch` | Verweise auf Person, Gerät, Dienstkonto oder Gruppe | S |
| `tiefe` | Ganzzahl | ja | berechnete Schachtelungstiefe, ≤ 8; Zyklen werden beim Schreiben abgelehnt | A |
| `wirksame_mitglieder_zahl` | Ganzzahl | ja | berechnet, dient der Anzeige der Massenwirkung vor einer Änderung | A |

Eindeutigkeit: `anzeige_name` und `technischer_name` je Mandant. Mitgliedschaften sind eigene Sätze mit den Feldern `gruppe`, `mitglied`, `seit`, `herkunft`; die Trennung erlaubt Befristung einer einzelnen Mitgliedschaft, ohne die Gruppe zu versionieren.

### Gerät

Zweck: verwaltetes Endgerät, das kein Knoten ist; Träger von Zertifikaten und Netzzugangsrechten. Details zur Registrierung in [Kapitel 13](13-geraeteverwaltung.md).

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `art` | Aufzählung | ja | `arbeitsplatz`, `mobilgeraet`, `drucker`, `netzgeraet`, `sonstiges` | S |
| `eigentuemer` | ULID | ja | Verweis auf Person oder Gruppe; polymorph, Zieltyp im Feld `eigentuemer_typ` | S |
| `hardwarebindung` | Objekt | nein | `{ "art": "tpm_ek" \| "seriennummer", "wert_hash": ... }`; nur Hashwert, nie Rohwert | A |
| `netzzone` | ULID | ja | Verweis auf eine Netzzone desselben Mandanten | S |
| `zertifikate` | Liste\<ULID\> | nein | abgeleitet aus Registrierung und Zuweisungen | A |
| `letzte_meldung` | Zeitpunkt | nein | Beobachtungszeitpunkt; jede Anzeige nennt ihn (INV-28) | I |
| `erreichbar` | Wahrheitswert | nein | Beobachtung, ausdrücklich kein Lebenszykluszustand | I |

Eindeutigkeit: `hardwarebindung.wert_hash` je Mandant, wenn gesetzt; `anzeige_name` je Mandant. Zwei Geräte mit identischer Hardwarebindung sind ein Fehler, kein zulässiger Zustand.

### Zuweisung

Die Attributtabelle der Zuweisung steht in 7.4, weil die Semantik ohne die Ableitungsregeln nicht vollständig beschreibbar ist.

### Dienst

Zweck: laufende Instanz eines Katalogeintrags bei einem Mandanten; die fachliche Einheit, die der Bediener sieht. Betrieb und Katalog in [Kapitel 15](15-dienste-software.md).

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `katalogeintrag` | ULID | ja | Verweis; unveränderlich | K |
| `katalog_version` | Text | ja | festgehaltene Version; Änderung nur über einen Aktualisierungsvorgang | S |
| `datensicherheitsstufe` | Aufzählung | ja | `lokal`, `gespiegelt`, `synchron_gespiegelt`; einzige Speicherentscheidung des Bedieners | S |
| `ressourcenbudget` | Objekt | ja | `{ "kerne": 0.1..64, "speicher_mb": 128..262144 }`, Vorbelegung aus dem Katalogeintrag | S |
| `platzierung` | ULID | nein | Verweis auf einen Knoten; abgeleitet, mit Begründungstext | A |
| `platzierungsbegruendung` | Text ≤ 256 | nein | genau ein anzeigbarer Satz, Pflicht sobald `platzierung` gesetzt ist | A |
| `ausschlusszonen` | Liste\<ULID\> | nein | Fehlerzonen, die der Bediener ausschließt | S |
| `rpo_anzeige` | Text | ja | aus `datensicherheitsstufe` und Knotenzahl abgeleitet, nach K-08 bis K-10 | A |
| `gesundheit` | Aufzählung | ja | `unbekannt`, `gesund`, `beeintraechtigt`, `gestoert` mit Beobachtungszeitpunkt | I |

Eindeutigkeit: `anzeige_name` je Mandant; `technischer_name` je Mandant, weil er in den internen DNS-Namen `<dienst>.<mandant>.<basisdomäne>` eingeht.

### Katalogeintrag

Zweck: signierte Beschreibung eines einsetzbaren Fremdprodukts und Vorlage für Dienste. Der Katalogeintrag gehört dem Plattform-Mandanten.

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `produkt_kennung` | Text ≤ 64 | ja | `[a-z][a-z0-9-]*`, global eindeutig zusammen mit `version` | K |
| `version` | Text ≤ 32 | ja | Version des Fremdprodukts, unverändert übernommen | K |
| `signatur` | Objekt | ja | Ed25519 nach RFC 8032 über die kanonische Form nach RFC 8785 | K |
| `abbildverweise` | Liste\<Objekt\> | ja | inhaltsadressierte Verweise mit Hashwert | S |
| `speicherbereichsdefinition` | Liste\<Objekt\> | ja | Name, Mindestgröße, Datenklasse | S |
| `benoetigte_konnektoren` | Liste\<Objekt\> | nein | Manifestkennung und Mindest-`vertrag_version` | S |
| `grenzdeklaration` | Objekt | ja | je Objektklasse `atrium` oder `fremdoberflaeche`; fehlt sie, wird der Eintrag abgelehnt (INV-30) | S |
| `kostenwirksame_aktionen` | Liste\<Text\> | ja, ggf. leer | Grundlage der Anzeige nach INV-29 | S |
| `migrationsschritte` | Liste\<Objekt\> | nein | Reihenfolge und Rücksprungfähigkeit je Schritt | S |
| `freigegeben_fuer` | Liste\<ULID\> | nein | Mandanten, für die der Eintrag wählbar ist | S |

### Knoten

Zweck: Maschine mit Atrium. Der Knoten gehört dem Plattform-Mandanten und wird ab M3 einem Mandanten exklusiv zugeordnet, ohne den Eigentümer zu wechseln. Kopplung und Rollen in [Kapitel 16](16-cluster.md).

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `rollen` | Liste\<Aufzählung\> | ja | `stimmknoten`, `mitleser`, `zeuge`, `diensttraeger`, `speichertraeger`, `eingangstraeger`; `zeuge` schließt `diensttraeger` und `speichertraeger` aus | S |
| `knotenklasse` | Text ≤ 32 | ja | frei gewählte Klasse, Grundlage der Platzierung | S |
| `fehlerzone` | Text ≤ 64 | ja | Stromkreis, Rack oder Standort; Grundlage jeder Antiaffinität | S |
| `exklusiv_fuer_mandant` | ULID | nur bei M3 | Verweis; setzt `isolationsstufe = m3` beim Zielmandanten voraus | S |
| `kapazitaet` | Objekt | ja | Kerne, Speicher, Plattenplatz; beobachtet, nicht eingegeben | I |
| `abbildversion_aktiv` / `_inaktiv` | Text | ja / nein | die beiden Hälften des A/B-Wurzeldateisystems | I |
| `leasestatus` | Objekt | ja | `{ "gueltig_bis": ..., "beobachtet_am": ... }`; Ablauf führt zur Selbstabschottung (INV-06) | I |
| `knotenzertifikat` | ULID | ja | Verweis; Sperrung beim Entkoppeln | A |

### Domäne

Zweck: Namensraum mit Sichtbarkeit und Geltungsbereich. Auflösung, Sichten und Signierung in [Kapitel 12](12-dns-netzwerk.md).

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `name` | Text ≤ 253 | ja | Labelregeln nach Identifikatorabschnitt, global eindeutig über alle Mandanten | K |
| `sichtbarkeit` | Aufzählung | ja | `intern`, `extern`, `beides`; `beides` erzeugt zwei Zoneninstanzen | S |
| `geltungsbereich` | Liste\<Objekt\> | nur bei `intern` oder `beides` | Verweise auf Gruppe, Person, Gerät oder Netzzone mit Zieltyp | S |
| `dnssec_zustand` | Aufzählung | ja | `unsigniert`, `signiert`, `wechsel_laeuft` | A |
| `serie` | Ganzzahl | ja | gleich `sollzustand_version` bei der letzten Zonenänderung | A |
| `delegierungsstatus` | Aufzählung | nur bei `extern` oder `beides` | `keine`, `ds_gesetzt`, `ds_geprueft`, `ds_fehlt` | A |

### DNS-Eintrag

Zweck: einzelner Name innerhalb einer Domäne mit ausdrücklicher Sichtzugehörigkeit.

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `domaene` | ULID | ja | Verweis; unveränderlich | K |
| `art` | Aufzählung | ja | `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `SRV`, `CAA`, `NS`, `DS`, `SVCB`, `HTTPS`, `PTR` | S |
| `name` | Text ≤ 253 | ja | relativ zur Domäne; `@` für die Zonenspitze | S |
| `wert` | Text ≤ 4096 | ja | artabhängig geprüft, Adressen syntaktisch validiert | S |
| `ttl` | Ganzzahl | ja | 60 bis 86400; Vorbelegung 300 intern, 3600 extern (K-17) | S |
| `sicht` | Aufzählung | ja | `intern`, `extern`, `beide`; ohne Vorbelegung laufen die Zoneninstanzen auseinander | S |
| `quelle` | Aufzählung | ja | `handeingegeben` oder `abgeleitet` | A |
| `quell_objekt` | ULID | nur bei `abgeleitet` | Verweis auf Veröffentlichung, Maildomäne oder Knoten (INV-09) | A |

Eindeutigkeit und Gültigkeit: das Tupel (`domaene`, `name`, `art`, `sicht`, `wert`) ist eindeutig; ein `CNAME` schließt jeden weiteren Eintrag desselben `name` in derselben `sicht` aus; an der Zonenspitze ist `CNAME` unzulässig; `DS` ist nur in der Elternzone zulässig. Ein handeingegebener Eintrag, der mit einem abgeleiteten kollidiert, wird beim Anlegen abgelehnt, mit Nennung des erzeugenden Objekts. Die maschinenprüfbare Fassung dieser Regeln steht in [Anhang A](A1-schemata.md), A1.2.9; dieses Kapitel ist die Quelle der fachlichen Festlegung, A1 die ihrer Prüfform.

```json
{
  "$id": "urn:atrium:schema:dnseintrag:1.0",
  "type": "object",
  "required": ["domaene", "art", "name", "wert", "ttl", "sicht", "quelle"],
  "properties": {
    "domaene": { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "art":     { "enum": ["A","AAAA","CNAME","MX","TXT","SRV","CAA",
                          "NS","DS","SVCB","HTTPS","PTR"] },
    "name":    { "type": "string", "maxLength": 253,
                 "pattern": "^(@|(\\*\\.)?([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)(\\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*)$" },
    "wert":    { "type": "string", "minLength": 1, "maxLength": 4096 },
    "ttl":     { "type": "integer", "minimum": 60, "maximum": 86400, "default": 300 },
    "sicht":   { "enum": ["intern", "extern", "beide"] },
    "quelle":  { "enum": ["handeingegeben", "abgeleitet"] },
    "quell_objekt": { "type": "string", "pattern": "^urn:atrium:[a-z]{3,32}:[0-7][0-9A-HJKMNP-TV-Z]{25}$" }
  },
  "allOf": [
    { "$ref": "urn:atrium:schema:objektrumpf:1.0" },
    { "if":   { "properties": { "quelle": { "const": "abgeleitet" } } },
      "then": { "required": ["quell_objekt"] } },
    { "if":   { "properties": { "art": { "const": "A" } } },
      "then": { "properties": { "wert": { "format": "ipv4" } } } },
    { "if":   { "properties": { "art": { "const": "AAAA" } } },
      "then": { "properties": { "wert": { "format": "ipv6" } } } },
    { "if":   { "properties": { "art": { "const": "CNAME" } } },
      "then": { "properties": { "name": { "not": { "const": "@" } } } } }
  ],
  "unevaluatedProperties": false
}
```

Regeln außerhalb des Schemas: Ein Eintrag mit `quelle = abgeleitet` wird von der API für Bedienerschreibvorgänge zurückgewiesen (INV-09); die Prüfung auf `CNAME`-Alleinstellung und auf Kollision mit abgeleiteten Einträgen erfordert einen Blick auf den Bestand derselben Sicht und findet in der Datenzugriffsschicht statt; die Vorbelegung von `ttl` stammt aus `sichtbarkeit` der Domäne und nicht aus dem Schema-Vorgabewert, damit die Quelle der Vorbelegung benennbar bleibt (INV-15).

### Zertifikat

Zweck: ausgestelltes Endzertifikat nach RFC 5280 für Dienst, Knoten, Gerät, Person oder Dienstkonto. CA-Hierarchie in [Kapitel 11](11-pki.md).

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `inhaber` | ULID | ja | polymorph, Zieltyp in `inhaber_typ`; Verweis ist Pflicht, damit kein Zertifikat ohne Quelle existiert | K |
| `verwendungszweck` | Aufzählung | ja | `dienst_server`, `knoten`, `geraet`, `person_client`, `dienstkonto` | K |
| `aussteller` | ULID | ja | Verweis auf die Mandanten-Zwischen-CA | K |
| `seriennummer` | Text | ja | je Aussteller eindeutig, mindestens 64 bit Zufall | K |
| `gueltig_von` / `gueltig_bis` | Zeitpunkt | ja | Laufzeit nach K-13: 90 d Dienst, 365 d Gerät | K |
| `erneuerungsschwelle` | Zeitpunkt | ja | Dienst ab Tag 60, Gerät ab 120 d Restlaufzeit | A |
| `schluesselablage` | Aufzählung | ja | `tpm`, `token`, `verschluesselter_speicher`; der private Schlüssel verlässt den erzeugenden Knoten nie (INV-20) | K |
| `sperrstatus` | Aufzählung | ja | `aktiv`, `gesperrt`, `abgelaufen`, `abgeloest` | A |
| `sperrgrund` | Aufzählung | nur bei `gesperrt` | Gründe nach RFC 5280 | A |
| `der_kodierung` | Bytes | ja | das ausgestellte Zertifikat selbst | A |

### Postfach

Zweck: Postfach unabhängig vom Ablageort. Mailfluss und Anbieterbindung in [Kapitel 14](14-mail.md).

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `art` | Aufzählung | ja | `persoenlich`, `geteilt` | S |
| `ablageort` | ULID | ja | Verweis auf eine Konnektorbindung; bestimmt, wo das Postfach liegt | S |
| `eigentuemer` | ULID | ja | Person bei `persoenlich`, Gruppe bei `geteilt`; jede andere Kombination wird abgelehnt | S |
| `berechtigte` | Liste\<Objekt\> | nein | `{ "subjekt": ULID, "recht": "lesen" \| "vollzugriff" }` | S |
| `sendeberechtigung` | Liste\<Objekt\> | nein | `{ "subjekt": ULID, "art": "senden_als" \| "senden_im_auftrag" }` | S |
| `kontingent_mb` | Ganzzahl | nein | nur gesetzt, wenn der Ablageort ein Kontingent kennt | S |
| `fremdkennung` | Text | nein | Kennung im Fremdsystem; nur bei Erstanlage gesetzt (INV-13) | A |

### Konnektorbindung

Zweck: Verbindung zu einer konkreten Instanz eines Fremdsystems für genau einen Mandanten. Vertrag und Sandkasten in [Kapitel 09](09-konnektoren.md).

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `manifest` | Text ≤ 64 | ja | Kennung des Konnektormanifests | K |
| `vertrag_version` | Text | ja | semantische Version; Kern unterstützt laufende und vorhergehende Hauptversion | S |
| `endpunkt` | Text ≤ 512 | ja | absolute URI mit erlaubtem Schema aus dem Manifest | S |
| `geheimnis_ref` | Text ≤ 128 | ja | Referenzname; niemals Wert, auch nicht im Export | G |
| `faehigkeiten` | Liste\<Text\> | ja | Ergebnis von `describe`, beobachtet, nicht eingegeben | I |
| `feldeigentum` | Abbildung Text→Aufzählung | ja | je Feld `atrium`, `fremd` oder `erstanlage`; unvollständige Angabe führt zur Ablehnung (INV-13) | S |
| `abgleichintervall_s` | Ganzzahl | ja | 60 bis 86400 | S |
| `ausgangs_positivliste` | Liste\<Text\> | ja | aus `endpunkt` erzeugt; kein allgemeiner Netzzugang (INV-21) | A |
| `letzte_erfolgreiche_beobachtung` | Zeitpunkt | ja | Grundlage der Datierung jeder Istanzeige (INV-28) | I |

Eindeutigkeit: (`mandant`, `manifest`, `endpunkt`) ist eindeutig; zwei Bindungen auf dasselbe Fremdsystem im selben Mandanten sind eine Vermischungsgefahr und werden abgelehnt.

### Richtlinie

Zweck: Vorgabe, die Vorbelegungen erzeugt und damit Entscheidungen einspart. Jede Vorbelegung in der Konsole nennt die Richtlinie, aus der sie stammt (INV-15).

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `geltung` | Aufzählung | ja | `plattform`, `mandant` | S |
| `gegenstand` | Text ≤ 128 | ja | punktgetrennter Schlüssel, z. B. `person.anmeldename.schema` | K |
| `wert` | beliebig | ja | Schema je `gegenstand` in einem Registrierungsverzeichnis hinterlegt | S |
| `erzwingung` | Aufzählung | ja | `hart` (Abweichung unmöglich) oder `weich` (Abweichung mit Begründung) | S |
| `begruendungspflicht` | Wahrheitswert | ja | bei `weich` immer `true` | S |
| `version` | Ganzzahl | ja | monoton; jede Änderung zeigt vorab die Menge betroffener Objekte | A |

Auflösungsreihenfolge bei Konflikt: Mandantenrichtlinie schlägt Plattformrichtlinie, außer die Plattformrichtlinie ist `hart`; zwei harte Richtlinien desselben Gegenstands auf derselben Geltungsebene sind ein Fehler und werden beim Schreiben abgelehnt, nicht zur Laufzeit aufgelöst. Die maschinenprüfbare Fassung steht in [Anhang A](A1-schemata.md), A1.2.13.

### Vorgang

Zweck: jede beabsichtigte Änderung als eine Einheit aus Plan, Freigabe, Ausführung und Rücknahme.

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `ausloeser` | Objekt | ja | `{ "art": "person" \| "dienstkonto" \| "regel", "kennung": ULID }` | K |
| `betroffene_objekte` | Liste\<ULID\> | ja | ≥ 1 | S |
| `wirkungsvorschau` | Liste\<Objekt\> | ja | je Eintrag Zielsystem, Aktion, Objekt, Umkehrbarkeit, Kostenwirkung | A |
| `vorschau_basis_version` | Ganzzahl | ja | Sollzustandsversion, auf der die Vorschau beruht; Abweichung entwertet die Vorschau | A |
| `freigabestatus` | Aufzählung | ja | `nicht_erforderlich`, `offen`, `zugestimmt`, `abgelehnt` | S |
| `teilzustand` | Liste\<Objekt\> | ja | je Zielsystem `{ "zielsystem": ULID, "zustand": ..., "grund": ..., "wiederholbar": bool }` (INV-12) | I |
| `idempotenzschluessel` | Text | ja | je Zielsystem stabil über Wiederholungen (INV-07) | A |
| `korrelationskennung` | ULID | ja | in jedem Auditereignis und jedem Konnektoraufruf mitgeführt | K |
| `ruecksprung_verweis` | ULID | nein | Verweis auf den Wiederherstellungspunkt oder den umkehrenden Vorgang | A |

### Auditereignis

Zweck: unveränderlicher Nachweis. Das Auditereignis ist das einzige Objekt, das die Löschung seines Bezugsobjekts überlebt, und liegt außerhalb des replizierten Kernzustands (INV-23).

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `strom_kennung` | ULID | ja | je Verwaltungsknoten ein Strom | K |
| `folgenummer` | Ganzzahl | ja | lückenlos aufsteigend je Strom | K |
| `zeitstempel` | Zeitpunkt | ja | aus der geprüften Zeitquelle; ohne Zeitgüte wird nicht geschrieben (INV-32) | K |
| `aktion` | Text ≤ 64 | ja | `<objekt>.<verb>` im Perfekt, etwa `person.angelegt` | K |
| `objektverweis` | URN | nein | schwacher Verweis; bleibt gültig, wenn das Objekt nur noch als Grabstein existiert | K |
| `vorher` / `nachher` | Objekt | nein | Feldwerte ohne Felder der Klasse G; für diese nur der Referenzname | K |
| `ergebnis` | Aufzählung | ja | `erfolg`, `teilweise`, `fehlschlag`, `abgelehnt` | K |
| `vorgaenger_hashwert` | Hashwert | ja | SHA-2 über die kanonische Form des Vorgängers nach RFC 8785 | K |
| `siegel` | Objekt | nein | periodische Signatur mit Zeitstempel nach RFC 3161 | K |

Eindeutigkeit: (`strom_kennung`, `folgenummer`). Eine Lücke in der Folgenummer oder ein nicht passender Vorgängerhashwert ist ein Kettenbruch und wird beim Start und bei jedem Export gemeldet, nicht repariert.

## 7.4 Die Zuweisung als Absichtsentität

Die Zuweisung verknüpft drei Dinge, die in gewachsenen Systemen üblicherweise verschmelzen: das Subjekt (wer), die Fähigkeit (in welcher Rolle) und das Ziel (wo). Aus dem Ziel folgen die Zielsysteme, weil ein Dienst seine Konnektorbindungen kennt; der Bediener wählt nie ein Zielsystem aus.

| Feld | Typ | Pflicht | Wertebereich / Regel | Kl. |
|---|---|---|---|---|
| `subjekt` | ULID | ja | Person, Gruppe oder Dienstkonto; Zieltyp in `subjekt_typ` | S |
| `ziel` | ULID | ja | Dienst, Netzzone, Maildomäne, Mandantenbereich oder Plattformbereich | S |
| `rolle` | ULID | ja | Verweis auf eine Rolle, deren Geltung zum Zieltyp passt | S |
| `wirkung` | Aufzählung | ja | `gewaehrung` oder `ausschluss`; `ausschluss` nur mit `subjekt_typ = person` | S |
| `gueltig_von` / `gueltig_bis` | Zeitpunkt | ja / bedingt | `gueltig_bis` Pflicht bei `wirkung = ausschluss` und bei Gastzugängen | S |
| `begruendung` | Text ≤ 512 | nur bei `ausschluss` | Pflichtfeld, erscheint im Vorgang und im Audit | S |
| `herkunft` | Aufzählung | ja | `direkt` oder `gruppe`; bei `gruppe` zusätzlich `herkunft_gruppe` | A |
| `versorgungszustand` | Liste\<Objekt\> | ja | je Zielsystem Zustand, Zeitpunkt, Grund, Wiederholbarkeit | I |

Eindeutigkeit: (`subjekt`, `ziel`, `rolle`, `wirkung`) ist je Mandant eindeutig; eine zweite identische Zuweisung ist keine Verstärkung, sondern ein Duplikat und wird abgelehnt.

### Warum die Zuweisung existiert

Ohne Absichtsobjekt ist der Wunsch nicht von seiner Umsetzung unterscheidbar. Wird ein Fremdkonto direkt an eine Person gehängt, gibt es drei Fragen ohne Antwort: Ist das Konto gewollt oder ein Rest? Wer hat es gewollt und wann? Was muss zurückgebaut werden, wenn der Wunsch entfällt? Die Zuweisung beantwortet alle drei, weil sie den Wunsch trägt und jedes Fremdkonto ein abgeleitetes Artefakt mit Rückverweis auf genau diese Zuweisung ist.

Der zweite Grund ist der Teilerfolg. Ein Wunsch wirkt auf mehrere Zielsysteme mit unterschiedlicher Erreichbarkeit und Ratenbegrenzung. Nur ein eigenes Objekt kann gleichzeitig "gewollt" und "in drei von vier Zielsystemen wirksam" sein; ein Fremdkonto kann das nicht, weil es entweder existiert oder nicht. Das ist die Modellgrundlage von INV-12 und die Ursache dafür, dass `versorgungszustand` eine Liste je Zielsystem ist und kein einzelner Statuswert.

### Abgeleitete Artefakte einer Zuweisung

| Artefaktart | Entsteht, wenn | Quelle des Rückbaus | Bemerkung |
|---|---|---|---|
| Fremdkonto | das Zielsystem Konten kennt | `apply` mit Aktion `entfernen` oder `deaktivieren` | manche Fremdsysteme können nur deaktivieren; der Rest wird benannt |
| Gruppenmitgliedschaft im Fremdsystem | die Rolle auf eine Fremdgruppe abbildet | Entfernen der Mitgliedschaft | Feldeigentum entscheidet, ob Atrium schreibt (INV-13) |
| Netzfreigabe | das Ziel eine Netzzone oder eine Veröffentlichung mit Zugriffskreis ist | Neuberechnung des Regelsatzes, atomarer Tausch | Fehlerzustand fällt auf Default-Deny (INV-10) |
| Mailadresse und Postfach | das Ziel eine Maildomäne ist | Postfach archivieren, Adresse in die Sperrfrist | Sperrfrist 12 Monate nach K-25 |
| Zertifikatsantrag | die Rolle eine Klient- oder Gerätekennung verlangt | Sperrung, Sperrliste sofort verteilt | Antrag ist automatisch, nie ein Dialog |
| Lizenzzuweisung | das Manifest die Aktion als kostenwirksam deklariert | Lizenz freigeben | Kostenfolge steht vor der Bestätigung (INV-29) |

### Beispiele

Der Schalter "Ticketsystem" an einer Person erzeugt eine Zuweisung mit `subjekt = Person`, `ziel = Dienst Znuny`, `rolle = agent`. Daraus entstehen ein Fremdkonto über die Konnektorbindung des Dienstes, eine Mitgliedschaft in der Fremdgruppe der Agenten und eine Netzfreigabe von der Netzzone der Geräte dieser Person zur Veröffentlichung des Dienstes. Nicht daraus entstehen Warteschlangen, Textbausteine oder Berechtigungsprofile innerhalb des Fremdprodukts: die Grenzdeklaration des Katalogeintrags weist sie der Fremdoberfläche zu (INV-30).

"Mail hinzufügen" erzeugt eine Zuweisung mit `ziel = Maildomäne` und `rolle = postfachinhaber`. Der Bediener entscheidet zwei Dinge, Domäne und lokalen Teil (K-03); Ablageort, Postfachart, die Namenseinträge und die Berechtigungen folgen aus Domäne, Person und Richtlinie. Liegt das Postfach bei einem Anbieter mit Lizenzpflicht, zeigt die Wirkungsvorschau die Kostenfolge vor der Bestätigung.

Eine Gruppenzuweisung auf eine Netzzone erzeugt je Gerät der Mitglieder einen Zertifikatsantrag und eine Zuordnung im Netzzugangsdienst. Eine Person, die ausnahmsweise keinen Netzzugang erhalten soll, bekommt eine direkte Zuweisung mit `wirkung = ausschluss`, Pflichtbegründung und Pflichtbefristung. Die Auflösungsregel lautet in einem Satz: Die Wirkung ist die Vereinigung aller gültigen Gewährungen, abzüglich aller gültigen direkten Ausschlüsse. Die verworfene Alternative, Ausnahmen durch Entfernen aus der Gruppe abzubilden, scheitert daran, dass dieselbe Gruppe weitere Zuweisungen trägt, die nicht mit entfallen sollen. Die Schwäche des gewählten Wegs ist benennbar: Ein ablaufender Ausschluss stellt die Gewährung wieder her. Deshalb ist der Ablauf eines Ausschlusses 14 Tage vorher eine sichtbare Aufgabe im Überblick und nicht nur ein Datum im Objekt.

### Nichtmaterialisierung von Gruppenzuweisungen

Eine Gruppenzuweisung wird nicht je Mitglied als eigenes Sollzustandsobjekt kopiert. Rechnung mit den Mengen aus 7.11: 2.000 Mitgliedschaften auf 60 Gruppen ergeben durchschnittlich 33,3 Mitglieder je Gruppe, und 180 Gruppenzuweisungen × 33,3 Mitglieder ergeben 6.000 wirksame Zuweisungen aus Gruppen. Als eigene Objekte à 1 KB wären das 6 MB zusätzlich, und jede Mitgliedschaftsänderung schriebe 33 Objekte durch das Replikationsprotokoll. Stattdessen entsteht die wirksame Zuweisung im Lesemodell, und nur der Versorgungszustand je Paar aus wirksamer Zuweisung und Zielsystem wird knotenlokal geführt: 6.000 wirksame Zuweisungen aus Gruppen und 2.000 direkte Zuweisungen ergeben 8.000 wirksame Zuweisungen, und 8.000 × 1,4 Zielsysteme = 11.200 Sätze à 200 B = 2,24 MB im Istzustand. Das ist ein Modell, keine Messung.

```json
{
  "$id": "urn:atrium:schema:zuweisung:1.0",
  "allOf": [{ "$ref": "urn:atrium:schema:objektrumpf:1.0" }],
  "type": "object",
  "required": ["subjekt", "subjekt_typ", "ziel", "ziel_typ", "rolle", "wirkung",
               "gueltig_von", "herkunft"],
  "properties": {
    "subjekt":     { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "subjekt_typ": { "enum": ["person", "gruppe", "dienstkonto"] },
    "ziel":        { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "ziel_typ":    { "enum": ["dienst", "netzzone", "maildomaene",
                              "mandantenbereich", "plattformbereich"] },
    "rolle":       { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "wirkung":     { "enum": ["gewaehrung", "ausschluss"] },
    "gueltig_von": { "type": "string", "format": "date-time" },
    "gueltig_bis": { "type": "string", "format": "date-time" },
    "begruendung": { "type": "string", "maxLength": 512 },
    "herkunft":    { "enum": ["direkt", "gruppe"] },
    "herkunft_gruppe": { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "versorgungszustand": {
      "type": "array", "maxItems": 64,
      "items": {
        "type": "object",
        "required": ["zielsystem", "zustand", "beobachtet_am"],
        "properties": {
          "zielsystem":   { "type": "string" },
          "zustand":      { "enum": ["offen", "in_arbeit", "wirksam",
                                     "fehlgeschlagen", "zurueckgebaut", "rest"] },
          "grund":        { "type": "string", "maxLength": 256 },
          "wiederholbar": { "type": "boolean" },
          "beobachtet_am":{ "type": "string", "format": "date-time" }
        },
        "additionalProperties": false
      }
    }
  },
  "if":   { "properties": { "wirkung": { "const": "ausschluss" } } },
  "then": { "required": ["gueltig_bis", "begruendung"],
            "properties": { "subjekt_typ": { "const": "person" },
                            "herkunft": { "const": "direkt" } } },
  "unevaluatedProperties": false
}
```

Zusätzliche Regeln außerhalb des Schemas: `rolle` muss eine Geltung haben, die zu `ziel_typ` passt; `subjekt` und `ziel` müssen demselben Mandanten angehören oder durch eine gültige Freigabeverknüpfung verbunden sein; `versorgungszustand` ist ein Beobachtungsfeld und wird von der API für Bedienerschreibvorgänge zurückgewiesen.

## 7.5 Beziehungen und referenzielle Integrität

Es existieren genau drei Verweisarten. Mehr Arten sind nicht nötig, und weniger führen dazu, dass Grabsteine und abgeleitete Artefakte gleich behandelt werden müssten.

| Verweisart | Bedeutung | Verhalten beim Löschversuch des Ziels |
|---|---|---|
| `verweis_pflicht` | Der Verweis ist Teil der Existenzbedingung des Quellobjekts | Löschversuch wird abgelehnt und erzeugt eine Auswirkungsliste |
| `verweis_schwach` | Der Verweis dokumentiert Geschichte | Ziel wird zum Grabstein; der Verweis bleibt auflösbar |
| `verweis_abgeleitet` | Das Quellobjekt ist ein Artefakt des Ziels | Artefakt verfällt mit dem Ziel, ohne eigene Entscheidung |

| Von | Nach | Kardinalität | Verweisart |
|---|---|---|---|
| jedes Objekt | Mandant | n:1 | `verweis_pflicht` |
| Mitgliedschaft | Gruppe, Mitglied | n:1 je Seite | `verweis_pflicht` |
| Zuweisung | Subjekt, Ziel, Rolle | n:1 je Seite | `verweis_pflicht` |
| Gerät | Eigentümer | n:1 | `verweis_pflicht` |
| Veröffentlichung | Dienst, Domäne | n:1 je Seite | `verweis_pflicht` |
| DNS-Eintrag (abgeleitet) | Veröffentlichung, Maildomäne, Knoten | n:1 | `verweis_abgeleitet` |
| Zertifikat | Inhaber | n:1 | `verweis_pflicht` |
| Mailadresse | Postfach, Maildomäne | n:1 je Seite | `verweis_pflicht` |
| Dienst | Katalogeintrag | n:1 | `verweis_pflicht` |
| Dienst | Knoten (Platzierung) | n:1 | `verweis_abgeleitet` |
| Vorgang | betroffene Objekte | n:m | `verweis_schwach` |
| Auditereignis | Objekt | n:1 | `verweis_schwach` |

### Warum es keine Kaskadenlöschung gibt

Eine Kaskade ist eine Entscheidung, die der Datenbankmotor zur Laufzeit trifft, ohne sie vorher zeigen zu können. INV-11 verlangt das Gegenteil: eine vollständige Auswirkungsliste vor der Bestätigung, keine nicht rücknehmbare Aktion ohne Kennzeichnung und keine Massenaktion ohne Einzelaufstellung. Deshalb kennt die API keine Kaskade, und der Fremdschlüssel hat nur das Verhalten "ablehnen".

Stattdessen läuft jede Löschung als Vorgang in vier Schritten ab. Erstens berechnet der Kern über den Verweisindex die transitive Hülle aller Objekte, die auf das Ziel verweisen, getrennt nach Verweisart. Zweitens zeigt die Wirkungsvorschau je Klasse eine Handlungsoption: mitlöschen mit Einzelaufstellung, auf ein anderes Objekt übertragen, oder das Zielobjekt nur stilllegen statt zu löschen. Drittens bestätigt der Bediener; nicht umkehrbare Anteile sind gekennzeichnet. Viertens wird ausgeführt, und jedes gelöschte Objekt hinterlässt einen Grabstein.

Kosten der Berechnung: bei 24.300 Objekten und durchschnittlich vier ausgehenden Verweisen umfasst der Verweisindex rund 97.200 Kanten. Eine Breitensuche im schlechtesten Fall über alle Kanten kostet bei angenommenen 1 µs je Kante 97 ms und bleibt damit innerhalb der Vorgabe von K-18 für die Wirkungsvorschau. Das ist eine Abschätzung aus der Kantenzahl, keine Messung.

## 7.6 Lebenszyklen als Zustandsautomaten

Jeder Übergang hat genau einen Auslöser, und jeder Auslöser ist entweder ein Vorgang oder eine Fristüberschreitung. Beobachtungen lösen keine Zustandsübergänge im Sollzustand aus; sie erzeugen Anzeigen und Aufgaben.

```
Person
  angelegt          --Anlagevorgang abgeschlossen-->            aktiv
  angelegt          --Ruecknahme des Anlagevorgangs-->          geloescht
  aktiv             --Sperrvorgang-->                           gesperrt
  gesperrt          --Entsperrvorgang-->                        aktiv
  aktiv | gesperrt  --gueltig_bis erreicht | Austrittsvorgang-->ausgeschieden
  ausgeschieden     --Wiedereintrittsvorgang (< Aufbewahrung)-->aktiv
  ausgeschieden     --Loeschvorgang (>= Aufbewahrung)-->        geloescht
  geloescht         --kein Rueckweg-->
Wirkung je Zustand: gesperrt = Anmeldung ueberall aus, Daten bleiben, Zuweisungen
bleiben bestehen. ausgeschieden = Zuweisungen laufen ab, Postfach wird delegiert.
```

```
Geraet
  erfasst           --Registrierungsnachweis + Zertifikat-->    registriert
  registriert       --erste Meldung mit gueltigem Zertifikat--> aktiv
  aktiv             --Verlustmeldung | Sperrvorgang-->          gesperrt
  gesperrt          --Entsperrvorgang, neues Zertifikat-->      registriert
  aktiv | gesperrt  --Ausmusterungsvorgang-->                   ausgemustert
  ausgemustert      --Loeschvorgang (>= Aufbewahrung)-->        geloescht
Beim Uebergang nach gesperrt wird jedes Zertifikat des Geraets gesperrt und die
Sperrliste sofort verteilt. "erreichbar" ist ein Beobachtungsfeld, kein Zustand.
```

```
Dienst
  ausgewaehlt       --Bereitstellungsvorgang freigegeben-->     wird_bereitgestellt
  wird_bereitgestellt --Gesundheitssignal gesund-->             laeuft
  wird_bereitgestellt --Fehler | Frist ueberschritten-->        ausgewaehlt
  laeuft            --Aktualisierungsvorgang-->                 wird_aktualisiert
  wird_aktualisiert --Gesundheitssignal gesund nach Frist-->    laeuft (neue Version)
  wird_aktualisiert --Gesundheitssignal bleibt aus-->           laeuft (Ruecksprung)
  laeuft            --Anhaltevorgang-->                         angehalten
  angehalten        --Startvorgang-->                           laeuft
  laeuft|angehalten --Entfernungsvorgang mit Datenentscheidung->entfernt
  entfernt          --Wiederherstellung < Aufbewahrungsfrist--> angehalten
  entfernt          --Aufbewahrungsfrist abgelaufen-->          vernichtet
```

```
Zertifikat
  beantragt         --Ausstellung durch die Zwischen-CA-->      ausgestellt
  beantragt         --Namenspruefung | CAA | Kontingent-->       verworfen
  ausgestellt       --Auslieferung bestaetigt-->                aktiv
  aktiv             --Restlaufzeit <= Erneuerungsschwelle-->    in_erneuerung
  in_erneuerung     --Nachfolger aktiv, Ueberlappung vorbei-->  abgeloest
  in_erneuerung     --Erneuerung scheitert bis gueltig_bis-->   abgelaufen
  aktiv|in_erneuerung --Sperrvorgang-->                         gesperrt
  gesperrt          --kein Rueckweg; Neuausstellung = neues Objekt-->
  abgelaufen|abgeloest --Aufbewahrungsfrist-->                  archiviert
Sperrlisteneintraege bleiben bis zum urspruenglichen gueltig_bis bestehen.
```

```
Domaene
  angelegt          --Schluesselerzeugung, Zonensignierung-->   signiert
  signiert          --Zoneninstanzen ausgeliefert und geprueft->aktiv
  aktiv             --DS beim Elternbetreiber gesetzt+geprueft->delegiert
  delegiert         --DS entfernt | Pruefung schlaegt fehl-->   aktiv
  aktiv | delegiert --Stilllegungsvorgang-->                    stillgelegt
  stillgelegt       --Reaktivierungsvorgang-->                  aktiv
  stillgelegt       --Entfernungsvorgang mit Auswirkungsplan--> entfernt
Im Zustand stillgelegt bleiben alle Eintraege erhalten und werden nicht
ausgeliefert; das unterscheidet Stilllegung von Loeschung.
```

```
Vorgang
  entworfen             --plan ueber alle Bindungen-->      vorschau_berechnet
  vorschau_berechnet    --Sollzustandsversion geaendert-->  entworfen
  vorschau_berechnet    --Freigabepflicht aus Rolle|Regel-->zur_freigabe_vorgelegt
  vorschau_berechnet    --keine Freigabepflicht-->          freigegeben
  zur_freigabe_vorgelegt--Zustimmung-->                     freigegeben
  zur_freigabe_vorgelegt--Ablehnung | Frist-->              verworfen
  freigegeben           --Ausfuehrungsstart-->              in_ausfuehrung
  in_ausfuehrung        --alle Zielsysteme bestaetigt-->    abgeschlossen
  in_ausfuehrung        --mindestens ein Zielsystem offen-->teilweise_fehlgeschlagen
  teilweise_fehlgeschlagen --Wiederholung, gleicher Schluessel--> in_ausfuehrung
  abgeschlossen|teilweise_fehlgeschlagen --Ruecknahmevorgang--> zurueckgenommen
```

Die Rücknahme ist selbst ein Vorgang mit eigener Kennung und eigener Wirkungsvorschau; der ursprüngliche Vorgang wechselt erst nach deren erfolgreichem Abschluss nach `zurueckgenommen`. Scheitert die Rücknahme teilweise, bleibt der ursprüngliche Vorgang in `teilweise_fehlgeschlagen` mit benannten Resten, statt einen aufgeräumten Zustand vorzutäuschen.

## 7.7 Weiche Löschung, Grabsteine, Aufbewahrung, Vernichtung

Es gibt drei Abstufungen, und die Konsole benennt sie unterschiedlich, weil sie unterschiedlich umkehrbar sind.

| Stufe | Wirkung | Umkehrbar | Nachweis |
|---|---|---|---|
| Stilllegung | Objekt bleibt vollständig, wirkt aber nicht mehr | ja, durch Zustandswechsel | Auditereignis `<objekt>.stillgelegt` |
| Weiche Löschung | Nutzfelder entfallen, Grabstein bleibt | innerhalb der Aufbewahrungsfrist, durch Wiederherstellungspunkt | Auditereignis `<objekt>.geloescht` |
| Vernichtung | Datenträger freigegeben, Schlüssel gelöscht | nein | Auditereignis `<objekt>.vernichtet` mit Belegliste |

Ein Grabstein trägt ausschließlich `kennung`, `typ`, `mandant`, `technischer_name`, `geloescht_am`, `geloescht_durch_vorgang`, `letzte_version_hash` und, wo eine Sperrfrist gilt, `gesperrt_bis`. Er trägt keine personenbezogenen Nutzfelder; damit ist die weiche Löschung eine echte Löschung der Inhalte und nicht nur ein Sichtbarkeitsschalter. Der Grabstein existiert aus zwei Gründen: schwache Verweise bleiben auflösbar, und Namen bleiben so lange gesperrt, wie eine Wiedervergabe schaden würde.

Fristen nach K-25: freigegebene Datenträger 30 Tage, entfernte Mailadressen 12 Monate Sperrfrist, Auditstrom 12 Monate vollständig. Wachstumsrechnung für Grabsteine unter den Annahmen dieses Kapitels: 5 % Personalwechsel ergeben 25 Personengrabsteine, 15 % Gerätetausch 120, 20 % Zuweisungsfluktuation 436, dazu rund 20 sonstige, zusammen rund 600 Grabsteine im Jahr à 128 B = 76,8 KB im Jahr. Über zehn Jahre sind das 768 KB. Ein Verdichtungsverfahren für Grabsteine ist daher nicht erforderlich; das ist eine Rechnung aus Annahmen, keine Messung.

```json
{
  "$id": "urn:atrium:schema:grabstein:1.0",
  "type": "object",
  "required": ["kennung", "typ", "mandant", "technischer_name",
               "geloescht_am", "geloescht_durch_vorgang", "letzte_version_hash"],
  "additionalProperties": false,
  "properties": {
    "kennung":          { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "typ":              { "type": "string", "pattern": "^[a-z]{3,32}$" },
    "mandant":          { "$ref": "#/properties/kennung" },
    "technischer_name": { "type": "string", "pattern": "^[a-z][a-z0-9-]{0,62}$" },
    "geloescht_am":     { "type": "string", "format": "date-time" },
    "geloescht_durch_vorgang": { "$ref": "#/properties/kennung" },
    "letzte_version_hash": { "type": "string", "pattern": "^[0-9A-HJKMNP-TV-Z]{52}$" },
    "gesperrt_bis":     { "type": "string", "format": "date-time" },
    "vernichtungsbeleg": {
      "type": "object",
      "required": ["vorgang", "standorte"],
      "properties": {
        "vorgang":   { "$ref": "#/properties/kennung" },
        "standorte": {
          "type": "array", "minItems": 1,
          "items": {
            "type": "object",
            "required": ["standort", "bestaetigt"],
            "properties": {
              "standort":      { "type": "string", "maxLength": 128 },
              "bestaetigt":    { "type": "boolean" },
              "bestaetigt_am": { "type": "string", "format": "date-time" }
            },
            "additionalProperties": false
          }
        }
      },
      "additionalProperties": false
    }
  }
}
```

Die Beschränkung `additionalProperties: false` ist hier keine Formalie, sondern die Durchsetzung der Aussage, dass ein Grabstein keine Nutzfelder trägt: Ein Feld, das nicht in dieser Liste steht, kann einen Grabstein nicht erreichen, auch nicht über `zusatz`. Solange ein Eintrag in `vernichtungsbeleg.standorte` den Wert `bestaetigt: false` trägt, gilt die Vernichtung als unvollständig und wird so angezeigt.

Vernichtung eines Speicherbereichs erfolgt als Schlüsselvernichtung, weil Replikate, Momentaufnahmen und ausgelagerte Sicherungen sich nicht einzeln überschreiben lassen. Der Beleg ist ein Auditereignis, das die Speicherbereichskennung, den bei der Anlage hinterlegten Hashwert des Schlüssels, den Vorgang und je Replikatstandort eine Bestätigung der Schlüssellöschung enthält. Solange ein Standort nicht bestätigt hat, zeigt die Konsole die Vernichtung als unvollständig an. Hier liegt eine Grenze des Entwurfs, die offen benannt gehört: Ein Replikat, das zum Zeitpunkt der Vernichtung nicht erreichbar war und später zurückkehrt, bringt eine noch entschlüsselbare Kopie mit, bis es die Löschung nachholt. Ebenso ist eine einzelne Speicherbereichsvernichtung innerhalb einer deduplizierenden Auslagerung erst mit dem Ablauf von deren Aufbewahrung endgültig, weil das Auslagerungsziel einen Schlüssel je Mandant führt und nicht je Speicherbereich. Beides ist nicht durch Softwaregestaltung auflösbar und wird deshalb angezeigt, statt zugesichert zu werden.

## 7.8 Identifikatoren

Die ULID-Form, die URN-Form und die Namenskonventionen stehen in KANON.md, Abschnitt 3. Drei Punkte ergänzen sie.

Vergabe und Kollision: Die Kennung wird vom antragstellenden Knoten erzeugt und vom führenden Knoten bei der Protokollanhängung gegen den bestehenden Bestand geprüft. Ohne diese Prüfung gilt für den Zufallsanteil von 80 bit und 2^80 = 1,2089 × 10^24 möglichen Werten bei angenommenen 1.000 gleichzeitigen Erzeugungen innerhalb derselben Millisekunde eine Kollisionswahrscheinlichkeit von rund n²/(2 · 2^80) = 10^6 / (2,418 × 10^24) = 4,1 × 10^-19. Die Prüfung ist trotzdem vorhanden, weil ein Duplikat im Schlüsselraum kein tolerierbarer Zustand ist und die Prüfung im ohnehin nötigen Protokollanhängeschritt nichts zusätzlich kostet.

Trennung von Schlüssel und Name: `kennung` ist unveränderlich, `anzeige_name` ist frei änderbar, `technischer_name` und `anmelde_name` sind abgeleitet und nach Erzeugung unveränderlich. Eine Umbenennung ändert daher weder Verweise noch DNS-Namen noch Fremdkontennamen. Die Schwäche dieser Entscheidung ist konkret: Nach einer Namensänderung bleibt der alte technische Name in internen DNS-Namen und Fremdkonten sichtbar. Die Alternative, den technischen Namen mitzuführen, wurde verworfen, weil sie jede Umbenennung zu einer Wanderung durch alle Fremdsysteme mit unbekanntem Teilerfolg macht. Die Konsole zeigt deshalb an jedem Objekt Anzeigename und technischen Namen nebeneinander an.

Kollisionsauflösung bei Namen: Der Ableitungsschritt erzeugt einen Kandidaten nach der Mandantenrichtlinie, prüft ihn gegen Bestand, Grabsteine und gesperrte Namen und hängt bei Belegung ein deterministisches Suffix an, beginnend bei `-2`. Zusätzlich wird der Kandidat gegen eine Verwechslungsprüfung geführt, die visuell ähnliche Zeichen zusammenfasst, damit `maier-2` und ein optisch gleicher Name nicht gleichzeitig existieren. Der Vorschlag ist im Formular sichtbar und als Vorbelegung mit Quelle gekennzeichnet (INV-15).

## 7.9 Schemaversionierung und Migration

Die Regeln für `MAJOR.MINOR`, das Kompatibilitätsfenster `MAJOR` und `MAJOR-1` und die Trennung vom Konnektorvertrag stehen in KANON.md, Abschnitt 3. Daraus folgen vier Verhaltensregeln für die Laufzeit.

Unbekannte Felder werden beim Lesen in `zusatz` aufbewahrt und beim Schreiben unverändert zurückgeschrieben. Ohne diese Regel verliert ein gemischt versionierter Verbund bei jedem Schreibvorgang durch den älteren Knoten stillschweigend Felder. Unbekannte Aufzählungswerte fallen auf den je Feld deklarierten Rückfallwert zurück, immer auf den restriktiveren; das Objekt wird dabei als nicht vollständig verstanden markiert, und der ältere Knoten darf es lesen, aber nicht schreiben. Die verworfene Alternative, unbekannte Werte als Fehler zu behandeln, macht jede additive Erweiterung zu einem brechenden Wechsel.

```
Migrationsvorgang (eigener Vorgang, nie zusammen mit einer Laufzeitaenderung, INV-24)
 1 Vorpruefung      Alle Verwaltungsknoten lesen MAJOR und MAJOR-1? Sonst Abbruch.
 2 Rueckhalt        Signierter Sollzustandsexport vor der Migration als Rueckwegpunkt.
 3 Ausweitung       Neue Felder und Entitaeten additiv anlegen; Doppelschreibung an.
 4 Umschreibung     Bestandsobjekte deterministisch ueberfuehren, Ergebnis je Objekt
                    protokolliert; abbrechbar und wiederaufnehmbar (Idempotenz, INV-07).
 5 Umschaltung      Lesepfad auf das neue Feld; Doppelschreibung bleibt aktiv.
 6 Beobachtung      Beobachtungsfenster; Abweichungszaehler muss null erreichen.
 7 Verengung        Altes Feld entfernen. Fruehestens im naechsten Freigabestand.
                    Dies ist der erste Schritt ohne Rueckweg und als solcher markiert.
Rueckrollen aus 3-6: Doppelschreibung aus, Lesepfad zurueck, Lesemodell neu bauen.
Rueckrollen aus 7:  nur ueber den Export aus Schritt 2.
```

Das Lesemodell wird bei jeder Schemaänderung vollständig aus dem Änderungsprotokoll neu gebaut, nicht migriert. Abschätzung für die Umgebung dieses Kapitels: 24.300 Objekte bei angenommenen 20.000 materialisierten Objekten je Sekunde ergeben 1,2 s, dazu der Aufbau von rund 97.200 Verweiskanten. Der Zielwert für den Neubau des Lesemodells liegt damit bei ≤ 5 s. Das ist eine Rechnung aus einer Annahme, keine Messung.

```json
{
  "$id": "urn:atrium:schema:sollzustandsexport:1.0",
  "type": "object",
  "required": ["schema_version", "erzeugt_am", "installation", "objekte",
               "auditkette_abschluss", "signatur"],
  "additionalProperties": false,
  "properties": {
    "schema_version": { "type": "string", "pattern": "^[0-9]+\\.[0-9]+$" },
    "erzeugt_am":     { "type": "string", "format": "date-time" },
    "installation":   { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "sollzustand_version": { "type": "integer", "minimum": 1 },
    "objekte": {
      "type": "array",
      "items": { "$ref": "urn:atrium:schema:objektrumpf:1.0" }
    },
    "grabsteine": { "type": "array", "items": { "type": "object" } },
    "auditkette_abschluss": {
      "type": "object",
      "required": ["strom_kennung", "folgenummer", "hashwert"],
      "properties": {
        "strom_kennung": { "type": "string" },
        "folgenummer":   { "type": "integer", "minimum": 0 },
        "hashwert":      { "type": "string", "pattern": "^[0-9A-HJKMNP-TV-Z]{52}$" }
      },
      "additionalProperties": false
    },
    "signatur": {
      "type": "object",
      "required": ["verfahren", "schluessel_kennung", "wert"],
      "properties": {
        "verfahren":         { "enum": ["ed25519"] },
        "schluessel_kennung":{ "type": "string" },
        "wert":              { "type": "string" }
      },
      "additionalProperties": false
    }
  }
}
```

Prüfregeln beim Import: Die Signatur wird über die kanonische Form nach RFC 8785 geprüft, bevor ein einziges Objekt gelesen wird. Ein Export mit `schema_version` außerhalb des Kompatibilitätsfensters wird abgelehnt und nicht stillschweigend migriert. Der Export enthält keine Nutzdaten und keine Geheimnisse, nur Geheimnisreferenzen; die Konsole benennt das beim Erzeugen und beim Einspielen.

## 7.10 Mandantenzugehörigkeit und Freigabeverknüpfung

`mandant` ist ein Pflichtfeld ohne Leerwert. Plattformobjekte verweisen auf den bei der Erstinstallation angelegten Plattform-Mandanten mit fester Kennung. Die verworfene Alternative, das Feld nullbar zu machen, hätte in jeder Abfrage eine Oder-Verknüpfung erzwungen, und genau diese Verknüpfung ist die Stelle, an der die Isolation ausläuft. Die Datenzugriffsschicht nimmt den Mandanten nicht als Parameter entgegen, sondern aus dem Sitzungskontext, und lehnt jede Abfrage ohne Mandantenprädikat ab (INV-19); Einzelheiten der Rechteprüfung in [Kapitel 19](19-mandanten-rechte-audit.md).

Mandantenübergreifende Bezüge existieren ausschließlich als Freigabeverknüpfung.

| Feld | Typ | Pflicht | Wertebereich / Regel |
|---|---|---|---|
| `quell_mandant` / `ziel_mandant` | ULID | ja | verschieden; die Richtung ist Teil der Identität |
| `gegenstand` | Objekt | ja | einzelnes Objekt oder Objektklasse mit Filter |
| `richtung` | Aufzählung | ja | `lesend` oder `verwendend` |
| `zweck` | Text ≤ 256 | ja | Freitext, erscheint im Audit und in der Konsole |
| `gueltig_bis` | Zeitpunkt | ja | Pflichtbefristung; Zielwert Obergrenze 365 Tage |
| `zugestimmt_von` | Liste\<ULID\> | ja | je ein Administrator beider Mandanten |

Ein Verweis über die Mandantengrenze, der nicht von einer gültigen Freigabeverknüpfung gedeckt ist, löst sich wie ein Grabstein auf und wird als "nicht sichtbar, kein Freigabeweg" angezeigt. Die Einrichtung, jede Änderung und der Ablauf erzeugen Auditereignisse. Die Auflösung im Lesepfad erzeugt je Vorgang genau ein Auditereignis, nicht je gelesener Zeile; die Begründung ist die Mengenrechnung: Ein Ereignis je Zeile würde bei 20 Bedienern und 50 Listenabrufen je Tag den Auditstrom um ein Vielfaches der 2.000 Ereignisse je Tag aus dem Schreibbetrieb aufblähen, ohne eine Frage zu beantworten, die nicht schon auf Vorgangsebene beantwortet ist.

## 7.11 Kardinalitäten, Zugriffsmuster, Indizes, Größe

Annahme für die folgende Rechnung: 500 Personen, 800 Geräte, 200 Dienstinstanzen, 5 Mandanten, 12 Knoten. Die abgeleiteten Mengen beruhen auf benannten Faktoren; sämtliche Zahlen sind ein Modell, keine Messung. K-12 rechnet mit 150 Diensten; die hier angesetzten 200 liegen darüber, und das Ergebnis bleibt trotzdem unter dem Zielwert.

| Entität | Anzahl | Faktor | Ø Bytes | Summe KB |
|---|---|---|---|---|
| Mandant | 5 | Annahme | 4.000 | 20 |
| Person | 500 | Annahme | 2.000 | 1.000 |
| Gruppe | 60 | 0,12 je Person | 3.000 | 180 |
| Mitgliedschaft | 2.000 | 4 je Person | 150 | 300 |
| Gerät | 800 | Annahme | 1.500 | 1.200 |
| Dienstkonto | 40 | 8 je Mandant | 1.000 | 40 |
| Rolle | 30 | 6 je Mandant | 2.000 | 60 |
| Zuweisung | 2.180 | 4 je Person, 3 je Gruppe | 1.000 | 2.180 |
| Dienst | 200 | Annahme | 4.000 | 800 |
| Veröffentlichung | 260 | 1,3 je Dienst | 1.500 | 390 |
| Speicherbereich | 300 | 1,5 je Dienst | 1.000 | 300 |
| Domäne | 20 | 4 je Mandant | 2.000 | 40 |
| DNS-Eintrag | 800 | 10 je Domäne handeingegeben, Rest abgeleitet | 300 | 240 |
| Maildomäne | 8 | Annahme | 2.000 | 16 |
| Postfach | 540 | 500 persönlich, 40 geteilt | 800 | 432 |
| Mailadresse | 864 | 1,6 je Postfach | 200 | 173 |
| Zertifikat | 2.660 | aktive und noch nicht archivierte | 1.500 | 3.990 |
| Konnektorbindung | 40 | 8 je Mandant | 3.000 | 120 |
| Richtlinie | 130 | 20 je Mandant, 30 Plattform | 1.000 | 130 |
| Knoten | 12 | Annahme | 3.000 | 36 |
| Netzzone | 15 | 3 je Mandant | 1.000 | 15 |
| Wiederherstellungspunkt | 9.000 | 30 je Speicherbereich | 300 | 2.700 |
| Geheimnisreferenz | 200 | Annahme | 300 | 60 |
| Vorgang, vollständig | 1.200 | 40 je Tag × 30 Tage | 3.000 | 3.600 |
| Vorgang, Kopfsatz | 2.400 | Tag 31 bis 90 | 400 | 960 |
| Katalogfreigabeliste | 5 | je Mandant | 24.000 | 120 |
| Freigabeverknüpfung | 20 | Annahme | 500 | 10 |
| **Summe** | **24.289** | | **787** | **19.112** |

Ergebnis: rund 24.300 Objekte und 19,1 MB serialisierter Sollzustand, im Mittel 787 B je Objekt. Der Zielwert aus K-12 liegt bei ≤ 50 MB; die Reserve beträgt 61 %. Deutung: Der Sollzustand einer Umgebung dieser Größe passt vollständig in den Hauptspeicher, was die Vorgabe aus K-18 für Listenanzeigen trägt.

Der Katalog ist bewusst nicht Teil dieser Summe. Rechnung: 600 Produkte mit je 3 gepflegten Versionen ergeben 1.800 Katalogeinträge à angenommen 8 KB = 14,4 MB, also 75 % des gesamten übrigen Sollzustands, für Daten, die in jeder Installation identisch sind. Entwurfsentscheidung: Der Katalog wird als signierter, inhaltsadressierter Katalogband je Knoten ausgeliefert; im replizierten Sollzustand steht nur der Hashwert des Bandes und je Mandant eine Freigabeliste von 600 Verweisen à 40 B = 24 KB. Verworfene Alternative: den Katalog vollständig zu replizieren, was jede Katalogaktualisierung zu einer Schreiblast auf dem Konsens macht und Momentaufnahmen um den Faktor 1,75 vergrößert.

Der Auditstrom wird getrennt gerechnet, weil er nicht im replizierten Zustand liegt. Annahmen: 40 Vorgänge je Tag mit je 12 Ereignissen = 480, Anmeldungen 500 Personen × 3 = 1.500, Zertifikatsereignisse rund 5 (260 veröffentlichte Namen / 90 Tage plus 800 Geräte / 365 Tage). Summe rund 2.000 Ereignisse je Tag à 1,2 KB = 2,4 MB je Tag = 876 MB im Jahr. Das ist der Wert, an dem die Aufbewahrung aus K-25 zu bemessen ist, nicht der Sollzustand.

| Zugriffsmuster | Häufigkeit | Erforderlicher Index (Schlüsselreihenfolge) |
|---|---|---|
| Liste je Typ, sortiert, seitenweise | sehr hoch | (`mandant`, `typ`, `anzeige_name_sortierform`, `kennung`) |
| Zuweisungen eines Subjekts | hoch | (`mandant`, `subjekt`, `gueltig_bis`) |
| Zuweisungen auf ein Ziel | hoch | (`mandant`, `ziel`, `rolle`) |
| Wer verweist auf X (Löschvorprüfung) | mittel, teuer | Verweisindex (`ziel_kennung`, `quell_typ`, `quell_kennung`) |
| Ablaufende Zertifikate | stündlich | (`mandant`, `gueltig_bis`) eingeschränkt auf `sperrstatus = aktiv` |
| Auflösung eines Namens | sehr hoch | (`domaene`, `sicht`, `name`, `art`) |
| Offene Freigaben und Vorgänge | hoch | (`mandant`, `freigabestatus`, `erzeugt_am`) |
| Geräte eines Eigentümers | mittel | (`mandant`, `eigentuemer`) |
| Eindeutigkeit einer Mailadresse | mittel | eindeutig (`maildomaene`, `lokaler_teil_normalisiert`) einschließlich Grabsteinen in Sperrfrist |
| Eindeutigkeit technischer Namen | mittel | eindeutig (`mandant`, `typ`, `technischer_name`) |
| Auditsuche je Vorgang | mittel | (`korrelationskennung`, `folgenummer`) im Auditspeicher |

Indexgröße: Der Sortierindex umfasst 24.300 Einträge à rund 64 B = 1,56 MB, der Verweisindex 97.200 Kanten à 48 B = 4,67 MB; zusammen mit den übrigen Indizes ergibt sich ein Lesemodell von rund 27 MB. Das bleibt deutlich unter dem Budget aus K-19 und ist der Grund, warum das Lesemodell vollständig neu gebaut statt migriert werden kann.

## Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| R-07-01 | Jedes persistierte Objekt trägt die Pflichtfelder des Objektrumpfs; ein Objekt ohne `mandant`, `schema_version` oder `erzeugt_durch_vorgang` wird von der API abgewiesen. | INV-19, INV-03 |
| R-07-02 | Jedes Feld jeder Entität ist genau einer Feldklasse zugeordnet; ein Schreibversuch eines Bedieners auf eine Klasse A oder I wird mit Fehlercode abgewiesen. | INV-09 |
| R-07-03 | Die Datenzugriffsschicht lehnt jede Abfrage ohne Mandantenprädikat ab; der Mandant stammt aus dem Sitzungskontext, nicht aus einem Aufrufparameter. | INV-19 |
| R-07-04 | Die API enthält keine Operation, die beim Löschen eines Objekts referenzierende Objekte implizit mitlöscht. | INV-11 |
| R-07-05 | Ein Löschvorgang zeigt vor der Bestätigung die vollständige Liste referenzierender Objekte, getrennt nach Verweisart, mit je Klasse genau einer Handlungsoption. | INV-11, K-18 |
| R-07-06 | Die Berechnung der Auswirkungsliste erfolgt über den Verweisindex und liegt bei 10^5 Kanten unter 2 s. | K-18 |
| R-07-07 | Jede Löschung hinterlässt einen Grabstein mit genau den definierten Feldern und ohne personenbezogene Nutzfelder. | INV-11, DSGVO |
| R-07-08 | Eine entfernte Mailadresse bleibt 12 Monate im Eindeutigkeitsindex gesperrt und ist in dieser Zeit nicht neu vergebbar. | K-25 |
| R-07-09 | Die Vernichtung eines Speicherbereichs erzeugt ein Auditereignis mit je Replikatstandort einer Bestätigung; fehlt eine Bestätigung, zeigt die Konsole "Vernichtung unvollständig". | INV-12, INV-23 |
| R-07-10 | Kennungen sind ULIDs, unveränderlich, und werden beim Anhängen an das Änderungsprotokoll gegen den Bestand geprüft. | KANON 3 |
| R-07-11 | Eine Umbenennung ändert weder `kennung` noch `technischer_name` noch `anmelde_name` und erzeugt keinen Vorgang in einem Fremdsystem. | INV-07 |
| R-07-12 | Die Ableitung technischer Namen ist deterministisch, prüft gegen Bestand, Grabsteine und Verwechslungsformen und zeigt das Ergebnis als Vorbelegung mit Quellenangabe. | INV-15 |
| R-07-13 | Die Zuweisung ist die einzige Entität, aus der Versorgungswirkungen entstehen; kein Fremdkonto existiert ohne Rückverweis auf eine Zuweisung. | KANON 4.10 |
| R-07-14 | `versorgungszustand` führt je Zielsystem Zustand, Zeitpunkt, Grund und Wiederholbarkeit; ein Vorgang gilt nicht als abgeschlossen, solange ein Eintrag nicht `wirksam` oder `zurueckgebaut` ist. | INV-12 |
| R-07-15 | Ein Ausschluss ist nur als direkte Zuweisung auf eine Person, nur befristet und nur mit Begründung zulässig; sein Ablauf erscheint 14 Tage vorher als Aufgabe im Überblick. | INV-18 |
| R-07-16 | Gruppenzuweisungen werden nicht je Mitglied als Sollzustandsobjekt materialisiert. | K-12 |
| R-07-17 | Jede Entität besitzt einen dokumentierten Zustandsautomaten; ein Übergang, der nicht in diesem Automaten steht, wird abgelehnt. | INV-03 |
| R-07-18 | Beobachtungsfelder lösen keine Zustandsübergänge im Sollzustand aus. | INV-02, INV-28 |
| R-07-19 | Unbekannte Felder werden in `zusatz` erhalten und unverändert zurückgeschrieben; unbekannte Aufzählungswerte fallen auf den deklarierten restriktiveren Rückfallwert zurück und sperren das Schreiben durch den älteren Knoten. | KANON 3 |
| R-07-20 | Eine Schemamigration ist ein eigener Vorgang, der keine Laufzeitänderung enthält, und ist bis einschließlich des Umschaltschritts rückrollbar. | INV-24 |
| R-07-21 | Der Verengungsschritt einer Migration erfolgt frühestens im nächsten Freigabestand und ist als nicht umkehrbar gekennzeichnet. | INV-24, INV-11 |
| R-07-22 | Das Lesemodell wird bei einer Schemaänderung vollständig neu gebaut; der Zielwert beträgt ≤ 5 s bei 25.000 Objekten. | KANON 4.1 |
| R-07-23 | Ein mandantenübergreifender Verweis ohne gültige Freigabeverknüpfung löst sich wie ein Grabstein auf und wird als "kein Freigabeweg" angezeigt. | INV-19 |
| R-07-24 | Jede Freigabeverknüpfung ist pflichtbefristet und trägt einen Pflichtzweck; Einrichtung, Änderung und Ablauf erzeugen Auditereignisse. | INV-19, INV-23 |
| R-07-25 | Kein Geheimniswert erscheint in einem Objekt, einem Auditereignis oder einem Export; es erscheint ausschließlich der Referenzname. | INV-20 |
| R-07-26 | Der Katalog liegt nicht im replizierten Sollzustand; repliziert werden nur der Hashwert des Katalogbands und die Freigabeliste je Mandant. | K-12 |
| R-07-27 | Jede Objektschreiboperation wird am API-Rand gegen das JSON-Schema der laufenden `schema_version` validiert; nicht schemakonforme Eingaben werden mit einer Fehlermeldung ohne Preisgabe interner Feldnamen oder Pfade abgewiesen. | INV-17 |
| R-07-28 | Abfragen an das Lesemodell werden ausschließlich parametrisiert erzeugt; eine Zeichenkettenverkettung von Eingabewerten in Abfragen existiert im Quelltext nicht. | INV-20 |

## Akzeptanzkriterien

| Kriterium | Gebunden an | Prüfverfahren |
|---|---|---|
| Ein Objekt ohne `mandant` lässt sich über keinen API-Pfad anlegen; der Test deckt alle Entitätstypen ab. | R-07-01, R-07-03 | Vollständigkeitstest über die Typliste im Bau |
| Eine Abfrage ohne Mandantenprädikat schlägt in der Datenzugriffsschicht fehl, auch bei Plattformrollen. | R-07-03 | Einheitentest mit absichtlich fehlendem Prädikat |
| In der OpenAPI-Beschreibung existiert keine Operation mit Kaskadensemantik; die Suche nach entsprechenden Parametern liefert null Treffer. | R-07-04 | statische Prüfung der API-Beschreibung |
| Das Löschen einer Person mit 4 Zuweisungen, 2 Geräten, 1 Postfach und 3 Zertifikaten zeigt genau 10 direkte und alle transitiven Bezüge vor der Bestätigung. | R-07-05 | Szenariotest mit festem Datenbestand |
| Nach dem Löschen ist der Grabstein vorhanden, enthält keines der Nutzfelder und ist über den alten schwachen Verweis auflösbar. | R-07-07 | Feldvergleichstest |
| Die Wiedervergabe einer innerhalb von 12 Monaten entfernten Mailadresse wird abgelehnt. | R-07-08 | Zeitgesteuerter Integrationstest |
| Ein Versorgungsvorgang mit einem absichtlich fehlerhaften Zielsystem meldet "teilweise fehlgeschlagen" und benennt das ausstehende Zielsystem. | R-07-14 | Fehlerinjektion in eine von mehreren Konnektorbindungen |
| Kein Fremdkonto im Testbestand existiert ohne auflösbaren Rückverweis auf eine Zuweisung. | R-07-13 | Bestandsprüfung nach dem Abgleichlauf |
| Ein Objekt mit einem in `zusatz` gehaltenen unbekannten Feld behält dieses Feld nach einem Schreibvorgang durch einen Knoten der Version MAJOR-1 unverändert. | R-07-19 | Mischversionstest mit zwei Knotenversionen |
| Ein Freigabestand, der Schema- und Laufzeitänderung zugleich enthält, wird von der Freigabeprüfung abgelehnt. | R-07-20 | Bauprüfung |
| Ein Rückrollen aus dem Umschaltschritt stellt den Ausgangszustand objektweise identisch wieder her; Abweichung null Objekte. | R-07-20 | Migrationsprobe mit anschließendem Objektvergleich |
| Der Neubau des Lesemodells mit 25.000 Objekten liegt unter 5 s. | R-07-22 | Zeitmessung im Bau gegen einen erzeugten Bestand |
| Ein mandantenübergreifender Verweis ohne Freigabeverknüpfung liefert in Konsole und API dieselbe Aussage "kein Freigabeweg" und keine Objektdaten. | R-07-23 | Isolationstest über zwei Mandanten |
| Eine Ausgabeprüfung über alle Objekte, Auditereignisse und Exporte findet null Treffer gegen die Geheimnismuster. | R-07-25 | Mustersuche im Bau |
| Der serialisierte Sollzustand eines erzeugten Bestands mit 500 Personen, 800 Geräten und 200 Diensten liegt unter dem Zielwert von 50 MB aus K-12. | R-07-26 | Erzeugung und Messung des Exports im Bau |
| Eine Suche im Quelltext nach Abfragen mit verketteten Eingabewerten liefert null Treffer. | R-07-28 | statische Quelltextprüfung |

## Offene Punkte

1. **Gültigkeitsbereich von Grabsteinen unter der Datenschutz-Grundverordnung.** Der Grabstein enthält `technischer_name` und `anmelde_name`, damit Namen gesperrt bleiben und schwache Verweise auflösbar sind. Beides ist bei einer natürlichen Person ein Personenbezug. Zu entscheiden ist, ob der Name im Grabstein nach Ablauf der Sperrfrist durch einen Hashwert mit Mandantenschlüssel ersetzt wird und welche Folge das für die Auflösbarkeit alter Auditverweise hat. Ohne Entscheidung steht R-07-07 im Widerspruch zum Löschanspruch.

2. **Verbindliche Obergrenze für die Zahl wirksamer Zuweisungen je Subjekt.** Das Modell setzt keine Grenze; die Wirkungsvorschau und der Versorgungszustand wachsen jedoch linear mit ihr. Zu entscheiden ist eine Obergrenze je Person und je Gruppe und das Verhalten beim Überschreiten: Ablehnung, Warnung oder gestufte Ausführung. Ohne Grenze ist der Zielwert aus K-15 für die Versorgungslatenz nicht haltbar.

3. **Umgang mit einem Fremdsystem, das die Kennung eines Objekts nicht aufnehmen kann.** Das Modell setzt voraus, dass jedes abgeleitete Fremdkonto einen Rückverweis trägt. Fremdsysteme ohne freies Zusatzfeld erzwingen eine externe Zuordnungstabelle, die selbst zu einer zweiten Wahrheitsquelle werden kann. Zu entscheiden ist, ob diese Tabelle Teil der Konnektorbindung wird und wie sie nach einem Wiederaufbau geprüft wird.

4. **Endgültigkeit der Vernichtung in deduplizierender Auslagerung.** Bei einem Schlüssel je Mandant ist die Vernichtung eines einzelnen Speicherbereichs erst mit dem Ablauf der Aufbewahrung der Auslagerung wirksam. Zu entscheiden ist, ob je Speicherbereich ein eigener Auslagerungsschlüssel geführt wird und welchen Preis das an Deduplizierungsrate kostet.

5. **Auflösungsregel bei mehreren gültigen Richtlinien gleicher Geltung.** Der Entwurf lehnt zwei harte Richtlinien desselben Gegenstands beim Schreiben ab. Unentschieden ist, wie sich eine Plattformrichtlinie verhält, die nachträglich hart gesetzt wird, während in Mandanten bereits abweichende Werte in Gebrauch sind: rückwirkende Erzwingung, Bestandsschutz mit Kennzeichnung oder erzwungener Vorgang je betroffenem Mandanten.

6. **Aufbewahrungsdauer vollständiger Vorgangsobjekte.** Der Entwurf hält Vorgänge 30 Tage vollständig und danach als Kopfsatz im replizierten Zustand. Ob 30 Tage für die Rücknahme ausreichen, ist nicht belegt; eine längere Frist erhöht den Sollzustand rechnerisch um 3,6 MB je weiteren 30 Tagen. Der Zusammenhang zwischen Rücknahmefrist und Zustandsgröße muss entschieden, nicht geschätzt werden.

7. **Eindeutigkeit des Anzeigenamens je Mandant.** Die Regel erzwingt bei zwei gleichnamigen Personen einen unterscheidenden Zusatz im Anzeigenamen, den ein Bediener eingeben muss. Das ist eine zusätzliche Entscheidung und steht damit in Spannung zu INV-14. Zu entscheiden ist, ob der Anzeigename seine Eindeutigkeitspflicht verliert und die Unterscheidung allein über eine angezeigte Zusatzinformation erfolgt.

8. **Verhalten des Verweisindex bei sehr großen Gruppen.** Die Kantenzahl wächst mit der Mitgliederzahl. Für eine Gruppe mit mehreren tausend Mitgliedern ist nicht entschieden, ob die Auswirkungsliste vollständig berechnet, auf Klassen verdichtet oder ab einer Schwelle nur gezählt wird. Eine Verdichtung widerspricht der Einzelaufstellung aus INV-11, eine vollständige Berechnung widerspricht der Zeitvorgabe aus K-18.
