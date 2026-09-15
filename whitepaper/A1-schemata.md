# Anhang A: Schemata

## A1.1 Geltung, Notation und Ablage

Dieser Anhang führt die formalen Schemata, gegen die die öffentliche API, der Importpfad für Manifeste und Katalogeinträge und der Importpfad für Sollzustandsexporte am Rand validieren. Die Feldnamen, Typen und Wertebereiche stammen aus KANON.md, Abschnitt 3 und aus [Kapitel 07](07-objektmodell.md); dieser Anhang fügt keine Felder hinzu, sondern macht die dort tabellarisch beschriebenen Regeln maschinenprüfbar und benennt die Stellen, an denen das nicht gelingt.

| Festlegung | Wert |
|---|---|
| Notation | JSON-Schema. Die verwendete Entwurfsfassung wird im Bauverzeichnis festgelegt und hier nicht genannt; verlangt werden die Schlüsselwörter `$defs`, `$ref`, `if`/`then`, `propertyNames`, `dependentRequired` und `unevaluatedProperties`. |
| Bezeichner | `$id` als `urn:atrium:schema:<entitätstyp>:<MAJOR.MINOR>`. Die Hauptversion folgt `schema_version` aus KANON.md, Abschnitt 3. |
| Kanonisierung vor Signatur | RFC 8785 |
| Signaturverfahren | Ed25519 nach RFC 8032 |
| Hashwerte | SHA-2 nach FIPS 180-4, dargestellt als `sha2-256:<64 Hexzeichen>` |
| Zusätzliche Eigenschaften | Verboten. Auf der äußersten Ebene jedes Entitätsschemas steht `unevaluatedProperties: false`, in jedem eingebetteten Objekt ohne `allOf` steht `additionalProperties: false`. |
| Validierungsort | Am Rand: API-Fassade, Manifestimport, Katalogimport, Exportimport. Eine Validierung erst in der Datenzugriffsschicht ist unzulässig. |

Die Kombination `allOf: [{"$ref": objektrumpf}]` mit `additionalProperties: false`, wie sie [Kapitel 07](07-objektmodell.md) in zwei Schemata verwendet, ist fehlerhaft und wird hier korrigiert. `additionalProperties` bewertet ausschließlich die Eigenschaften desselben Schemaobjekts und kennt die über `$ref` eingebundenen Rumpfeigenschaften nicht; ein solches Schema lehnt jedes gültige Objekt ab, weil `kennung`, `mandant` und alle weiteren Rumpffelder als zusätzliche Eigenschaften gelten. Der Anhang verwendet deshalb durchgehend `unevaluatedProperties: false`, das die Ergebnisse der eingebundenen Teilschemata berücksichtigt. Das zweite Schema in [Kapitel 07](07-objektmodell.md), der DNS-Eintrag, trägt zusätzlich zwei Mitglieder mit dem Namen `allOf` in demselben Objekt und ist damit nicht eindeutig lesbares JSON; A1.2.9 führt die zusammengeführte Fassung.

Sechs Formatbezeichner werden als Zusicherung und nicht als Annotation ausgewertet. Ein Validator, der Formate nur annotiert, erfüllt diese Spezifikation nicht.

```
date-time   ipv4   ipv6   idn-hostname   uri   duration
```

### Basisdefinitionen

Alle Entitätsschemata binden dieselben Basistypen ein. Sie stehen einmal und werden nicht je Entität wiederholt.

```json
{
  "$id": "urn:atrium:schema:basis:1.0",
  "$defs": {
    "ulid":    { "type": "string", "pattern": "^[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "urn":     { "type": "string", "maxLength": 64,
                 "pattern": "^urn:atrium:[a-z]{3,32}:[0-7][0-9A-HJKMNP-TV-Z]{25}$" },
    "zeitpunkt": { "type": "string", "format": "date-time" },
    "technischer_name": { "type": "string", "pattern": "^[a-z][a-z0-9-]{0,62}$" },
    "kurzname": { "type": "string", "pattern": "^[a-z][a-z0-9-]{0,15}$" },
    "hashwert": { "type": "string", "pattern": "^sha2-256:[0-9a-f]{64}$" },
    "geheimnis_ref": { "type": "string", "maxLength": 128,
                       "pattern": "^[a-z][a-z0-9._-]{0,127}$" },
    "schema_version": { "type": "string", "pattern": "^[0-9]+\\.[0-9]+$" },
    "vertrag_version": { "type": "string", "pattern": "^[0-9]+\\.[0-9]+$" },
    "entitaetstyp": { "type": "string", "pattern": "^[a-z]{3,32}$" },
    "dnsname": { "type": "string", "maxLength": 253, "format": "idn-hostname" },
    "beobachtung": {
      "type": "object",
      "required": ["beobachtet_am"],
      "properties": { "beobachtet_am": { "$ref": "#/$defs/zeitpunkt" } },
      "additionalProperties": false
    }
  }
}
```

`geheimnis_ref` ist ein Verweisname und nie ein Wert (INV-20). Das Schema kann diese Zusage nicht erzwingen, weil eine Zeichenkette mit einem Geheimnis identisch aussehen kann; die Durchsetzung liegt in der Ausgabeprüfung und in der Tatsache, dass die API für Felder dieser Klasse keine Leseoperation kennt.

### Schemaverzeichnis

| `$id` (ohne Präfix `urn:atrium:schema:`) | Gegenstand | Quelle der Felder |
|---|---|---|
| `basis:1.0` | gemeinsame Typen | dieser Anhang |
| `objektrumpf:1.0` | Rumpf jedes Objekts | [Kapitel 07](07-objektmodell.md) |
| `mandant:1.0` … `vorgang:1.0` | die vierzehn Sollzustandsentitäten | KANON.md 3, [Kapitel 07](07-objektmodell.md) |
| `auditereignis:1.0` | Nachweisschicht | A1.6 |
| `konnektormanifest:1.0` | Konnektormanifest | [Kapitel 09](09-konnektoren.md) |
| `katalogeintrag:1.0` | Katalogeintrag | [Kapitel 15](15-dienste-software.md) |
| `rechteregel:1.0` | Rechteregel mit Selektor | [Kapitel 19](19-mandanten-rechte-audit.md) |
| `gesamtexport:1.0` | deklarativer Gesamtexport | A1.7 |

## A1.2 Kernentitäten

Jedes Entitätsschema bindet `objektrumpf:1.0` ein, verengt `zustand` auf die Zustandsmenge der Entität und verbietet unbewertete Eigenschaften. Die Rumpffelder werden nicht wiederholt; sie stehen in [Kapitel 07](07-objektmodell.md).

### A1.2.1 Mandant

```json
{
  "$id": "urn:atrium:schema:mandant:1.0",
  "allOf": [{ "$ref": "urn:atrium:schema:objektrumpf:1.0" }],
  "type": "object",
  "required": ["isolationsstufe", "kurzname", "zwischen_ca", "hauptschluessel_ref",
               "sicherungsziel", "standardrichtlinien", "fehlerdomaene_anzeige"],
  "properties": {
    "typ":     { "const": "mandant" },
    "zustand": { "enum": ["angelegt","aktiv","gesperrt","stillgelegt","aufgeloest"] },
    "isolationsstufe":     { "enum": ["m0","m1","m2","m3"] },
    "kurzname":            { "$ref": "urn:atrium:schema:basis:1.0#/$defs/kurzname" },
    "zwischen_ca":         { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "hauptschluessel_ref": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/geheimnis_ref" },
    "sicherungsziel":      { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "standardrichtlinien": {
      "type": "array", "minItems": 1, "maxItems": 256, "uniqueItems": true,
      "items": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" }
    },
    "exklusive_knotenklasse": { "type": "string", "maxLength": 32,
                                "pattern": "^[a-z][a-z0-9-]{0,31}$" },
    "fehlerdomaene_anzeige":  { "type": "string", "minLength": 1, "maxLength": 256 }
  },
  "if":   { "properties": { "isolationsstufe": { "const": "m3" } },
            "required": ["isolationsstufe"] },
  "then": { "required": ["exklusive_knotenklasse"] },
  "else": { "not": { "required": ["exklusive_knotenklasse"] } },
  "unevaluatedProperties": false
}
```

Außerhalb des Schemas geprüft: `kurzname` ist global eindeutig und nach der Anlage unveränderlich, was eine Bestandsabfrage und einen Vergleich mit dem Vorzustand erfordert; `zwischen_ca` verweist auf eine CA, deren Feld `mandant` auf genau dieses Objekt zurückverweist, und beide Richtungen werden erst nach dem Import aller Blöcke geprüft (A1.7); der Plattform-Mandant trägt eine feste Kennung und ist gegen Löschung, Umbenennung und Sperrung gesperrt, was eine Regel der Datenzugriffsschicht und keine Schemaeigenschaft ist. Die Absenkung der `isolationsstufe` ist schematisch von einer Erhöhung nicht unterscheidbar; sie wird im Vorgang erkannt, weil dort der Vorzustand vorliegt.

### A1.2.2 Person

Die Konsole nennt diese Entität "Nutzer"; im Modell und in jeder API-Antwort heißt sie `person`.

```json
{
  "$id": "urn:atrium:schema:person:1.0",
  "allOf": [{ "$ref": "urn:atrium:schema:objektrumpf:1.0" }],
  "type": "object",
  "required": ["anmelde_name", "nachname", "authentisierungsmittel",
               "gueltig_von", "sprache"],
  "properties": {
    "typ":     { "const": "person" },
    "zustand": { "enum": ["angelegt","aktiv","gesperrt","ausgeschieden","geloescht"] },
    "anmelde_name": { "type": "string", "maxLength": 64,
                      "pattern": "^[a-z][a-z0-9._-]{0,63}$" },
    "nachname": { "type": "string", "minLength": 1, "maxLength": 64 },
    "vorname":  { "type": "string", "minLength": 1, "maxLength": 64 },
    "authentisierungsmittel": {
      "type": "array", "minItems": 1, "maxItems": 16,
      "items": {
        "type": "object",
        "required": ["art", "kennung", "registriert_am"],
        "properties": {
          "art": { "enum": ["passkey","totp","zertifikat","kennwort"] },
          "kennung": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
          "registriert_am": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
          "letzter_erfolg": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" }
        },
        "additionalProperties": false
      }
    },
    "gueltig_von": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "gueltig_bis": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "sprache":     { "type": "string", "pattern": "^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$" },
    "personal_kennzeichen": { "type": "string", "maxLength": 64 },
    "letzte_anmeldung":     { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" }
  },
  "unevaluatedProperties": false
}
```

Außerhalb des Schemas geprüft: `gueltig_bis` ist größer als `gueltig_von`, was JSON-Schema für zwei Werte desselben Objekts nicht ausdrückt; die Zulässigkeit von `art: kennwort` folgt aus der Mandantenrichtlinie `person.authentisierung.kennwort_erlaubt` und wird bei der Aufnahme des Mittels und erneut bei jeder Richtlinienänderung geprüft; `anmelde_name` ist je Mandant eindeutig und nach der Erzeugung unveränderlich; `personal_kennzeichen` ist je Mandant eindeutig, wenn gesetzt. `letzte_anmeldung` ist ein Beobachtungsfeld und wird von der API für Bedienerschreibvorgänge zurückgewiesen; das Schema kennt die Feldklasse nicht, die Fassade kennt sie.

### A1.2.3 Gruppe und Gruppenmitgliedschaft

```json
{
  "$id": "urn:atrium:schema:gruppe:1.0",
  "allOf": [{ "$ref": "urn:atrium:schema:objektrumpf:1.0" }],
  "type": "object",
  "required": ["art", "mitgliedschaftsregel", "tiefe", "wirksame_mitglieder_zahl"],
  "properties": {
    "typ":     { "const": "gruppe" },
    "zustand": { "enum": ["angelegt","aktiv","aufgeloest"] },
    "art":     { "enum": ["rechte","verteiler","geltungsbereich"] },
    "mitgliedschaftsregel": {
      "type": "object",
      "required": ["modus"],
      "properties": {
        "modus": { "enum": ["statisch","abgeleitet"] },
        "ausdruck": { "type": "string", "maxLength": 1024 }
      },
      "dependentRequired": { "ausdruck": ["modus"] },
      "additionalProperties": false,
      "if":   { "properties": { "modus": { "const": "abgeleitet" } },
                "required": ["modus"] },
      "then": { "required": ["ausdruck"] },
      "else": { "not": { "required": ["ausdruck"] } }
    },
    "tiefe": { "type": "integer", "minimum": 0, "maximum": 8 },
    "wirksame_mitglieder_zahl": { "type": "integer", "minimum": 0 }
  },
  "unevaluatedProperties": false
}
```

```json
{
  "$id": "urn:atrium:schema:gruppenmitgliedschaft:1.0",
  "type": "object",
  "required": ["gruppe", "mitglied", "mitglied_typ", "seit", "herkunft", "mandant"],
  "properties": {
    "gruppe":      { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "mitglied":    { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "mitglied_typ":{ "enum": ["person","geraet","dienstkonto","gruppe"] },
    "mandant":     { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "seit":        { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "bis":         { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "herkunft":    { "enum": ["direkt","regel"] }
  },
  "additionalProperties": false
}
```

Die Mitgliedschaft ist ein eigener Satz und kein Feld der Gruppe, damit eine einzelne Mitgliedschaft befristet werden kann, ohne die Gruppe zu versionieren. Außerhalb des Schemas geprüft: der Mitgliedschaftsgraph ist azyklisch, und `tiefe` ist sein berechnetes Maximum, was eine Traversierung über den Bestand erfordert und im Schema grundsätzlich nicht ausdrückbar ist; ein Schreibversuch, der einen Zyklus erzeugen würde, wird mit Nennung der schließenden Kante abgelehnt. `wirksame_mitglieder_zahl` ist ein Ableitungsfeld und dient der Anzeige der Massenwirkung vor einer Änderung; ein von außen gesetzter Wert wird verworfen.

### A1.2.4 Gerät

```json
{
  "$id": "urn:atrium:schema:geraet:1.0",
  "allOf": [{ "$ref": "urn:atrium:schema:objektrumpf:1.0" }],
  "type": "object",
  "required": ["art", "eigentuemer", "eigentuemer_typ", "netzzone"],
  "properties": {
    "typ":     { "const": "geraet" },
    "zustand": { "enum": ["erfasst","registriert","aktiv","gesperrt","ausgemustert"] },
    "art": { "enum": ["arbeitsplatz","mobilgeraet","drucker","netzgeraet","sonstiges"] },
    "eigentuemer":     { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "eigentuemer_typ": { "enum": ["person","gruppe"] },
    "hardwarebindung": {
      "type": "object",
      "required": ["art","wert_hash"],
      "properties": {
        "art": { "enum": ["tpm_ek","seriennummer"] },
        "wert_hash": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/hashwert" }
      },
      "additionalProperties": false
    },
    "netzzone":    { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "zertifikate": { "type": "array", "maxItems": 32, "uniqueItems": true,
                     "items": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" } },
    "letzte_meldung": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "erreichbar": { "type": "boolean" }
  },
  "unevaluatedProperties": false
}
```

KANON.md nennt den Zustand "verloren/gesperrt". Das Schema führt ihn als `gesperrt`, weil "verloren" ein Grund und kein Zustand ist; der Grund steht im auslösenden Vorgang und im Auditereignis. Außerhalb des Schemas geprüft: `hardwarebindung.wert_hash` ist je Mandant eindeutig, wenn gesetzt, und zwei Geräte mit identischer Bindung sind ein Fehler; `netzzone` gehört demselben Mandanten; der Übergang nach `gesperrt` verteilt die Sperrliste sofort und ist damit keine reine Zustandsänderung, sondern ein Vorgang mit Wirkung auf abgeleitete Artefakte. Das Feld `wert_hash` enthält ausschließlich einen Hashwert; ein Rohwert im Sollzustand wäre ein dauerhaft gespeichertes Gerätemerkmal ohne Zweck.

### A1.2.5 Zuweisung

Das Schema in [Kapitel 07](07-objektmodell.md) ist inhaltlich vollständig. Hier steht die normalisierte Fassung mit korrigierter Abschlussregel und Basisverweisen; die Feldbedeutungen wiederholt dieser Anhang nicht.

```json
{
  "$id": "urn:atrium:schema:zuweisung:1.0",
  "type": "object",
  "required": ["subjekt","subjekt_typ","ziel","ziel_typ","rolle","wirkung",
               "gueltig_von","herkunft"],
  "properties": {
    "typ":     { "const": "zuweisung" },
    "zustand": { "enum": ["gesetzt","in_versorgung","wirksam","abgelaufen",
                          "entzogen","zurueckgebaut","mit_resten"] },
    "subjekt":     { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "subjekt_typ": { "enum": ["person","gruppe","dienstkonto"] },
    "ziel":        { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "ziel_typ":    { "enum": ["dienst","netzzone","maildomaene",
                              "mandantenbereich","plattformbereich"] },
    "rolle":       { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "wirkung":     { "enum": ["gewaehrung","ausschluss"] },
    "gueltig_von": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "gueltig_bis": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "begruendung": { "type": "string", "minLength": 8, "maxLength": 512 },
    "herkunft":    { "enum": ["direkt","gruppe"] },
    "herkunft_gruppe": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "versorgungszustand": {
      "type": "array", "maxItems": 64,
      "items": {
        "type": "object",
        "required": ["zielsystem","zustand","beobachtet_am"],
        "properties": {
          "zielsystem": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
          "zustand": { "enum": ["offen","in_arbeit","wirksam",
                                "fehlgeschlagen","zurueckgebaut","rest"] },
          "grund": { "type": "string", "maxLength": 256 },
          "wiederholbar": { "type": "boolean" },
          "beobachtet_am": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" }
        },
        "additionalProperties": false
      }
    }
  },
  "allOf": [
    { "$ref": "urn:atrium:schema:objektrumpf:1.0" },
    { "if":   { "properties": { "wirkung": { "const": "ausschluss" } },
                "required": ["wirkung"] },
      "then": { "required": ["gueltig_bis","begruendung"],
                "properties": { "subjekt_typ": { "const": "person" },
                                "herkunft":    { "const": "direkt" } } } },
    { "if":   { "properties": { "herkunft": { "const": "gruppe" } },
                "required": ["herkunft"] },
      "then": { "required": ["herkunft_gruppe"] } }
  ],
  "unevaluatedProperties": false
}
```

Der Unterschied zur Fassung in [Kapitel 07](07-objektmodell.md) besteht in drei Punkten. Die bedingten Regeln liegen in einem einzigen `allOf`, weil zwei Mitglieder gleichen Namens in einem JSON-Objekt nicht eindeutig sind. Jede `if`-Klausel führt `required` für das geprüfte Feld mit, weil eine `if`-Klausel ohne diese Angabe auch dann zutrifft, wenn das Feld fehlt, und dann die `then`-Regeln unbeabsichtigt erzwingt. `herkunft_gruppe` ist an `herkunft` gekoppelt, was vorher nur im Text stand. Außerhalb des Schemas geprüft: `rolle` hat eine Geltung, die zu `ziel_typ` passt; `subjekt` und `ziel` gehören demselben Mandanten oder sind durch eine gültige Freigabeverknüpfung verbunden; das Tupel (`subjekt`, `ziel`, `rolle`, `wirkung`) ist je Mandant eindeutig.

### A1.2.6 Dienst

KANON.md nennt die laufende Instanz eines Katalogeintrags **Dienst**; "Dienstinstanz" ist kein Objektname des Modells.

```json
{
  "$id": "urn:atrium:schema:dienst:1.0",
  "allOf": [{ "$ref": "urn:atrium:schema:objektrumpf:1.0" }],
  "type": "object",
  "required": ["katalogeintrag","katalog_version","datensicherheitsstufe",
               "ressourcenbudget","rpo_anzeige","gesundheit"],
  "properties": {
    "typ":     { "const": "dienst" },
    "zustand": { "enum": ["ausgewaehlt","wird_bereitgestellt","laeuft",
                          "wird_aktualisiert","angehalten","entfernt"] },
    "katalogeintrag":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "katalog_version": { "type": "string", "maxLength": 32, "minLength": 1 },
    "datensicherheitsstufe": { "enum": ["lokal","gespiegelt","synchron_gespiegelt"] },
    "ressourcenbudget": {
      "type": "object",
      "required": ["kerne","speicher_mb"],
      "properties": {
        "kerne":       { "type": "number", "minimum": 0.1, "maximum": 64 },
        "speicher_mb": { "type": "integer", "minimum": 128, "maximum": 262144 }
      },
      "additionalProperties": false
    },
    "platzierung": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "platzierungsbegruendung": { "type": "string", "minLength": 1, "maxLength": 256 },
    "ausschlusszonen": { "type": "array", "maxItems": 64, "uniqueItems": true,
                         "items": { "type": "string", "maxLength": 64 } },
    "rpo_anzeige": { "type": "string", "minLength": 1, "maxLength": 128 },
    "gesundheit": {
      "type": "object",
      "required": ["stufe","beobachtet_am"],
      "properties": {
        "stufe": { "enum": ["unbekannt","gesund","beeintraechtigt","gestoert"] },
        "beobachtet_am": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" }
      },
      "additionalProperties": false
    }
  },
  "dependentRequired": { "platzierung": ["platzierungsbegruendung"] },
  "unevaluatedProperties": false
}
```

`gesundheit` ist hier ein Objekt und keine reine Aufzählung. [Kapitel 07](07-objektmodell.md) beschreibt den Beobachtungszeitpunkt im Fließtext der Wertebereichsspalte; INV-28 verlangt ihn an jeder Istanzeige, und ein Schema, das ihn nicht erzwingt, verlagert die Invariante in die Oberfläche. Außerhalb des Schemas geprüft: `rpo_anzeige` folgt aus `datensicherheitsstufe` und der aktuellen Knotenzahl nach K-08 bis K-10 und ist deshalb ein Ableitungsfeld; `ausschlusszonen` enthält Bezeichner existierender Fehlerzonen; `katalog_version` wird nur über einen Aktualisierungsvorgang geändert, was einen Vergleich mit dem Vorzustand erfordert.

### A1.2.7 Knoten

```json
{
  "$id": "urn:atrium:schema:knoten:1.0",
  "type": "object",
  "required": ["rollen","knotenklasse","fehlerzone","kapazitaet",
               "abbildversion_aktiv","leasestatus","knotenzertifikat"],
  "properties": {
    "typ":     { "const": "knoten" },
    "zustand": { "enum": ["installiert","wartemodus","gekoppelt",
                          "produktiv","geraeumt","entkoppelt"] },
    "rollen": {
      "type": "array", "minItems": 1, "maxItems": 6, "uniqueItems": true,
      "items": { "enum": ["stimmknoten","mitleser","zeuge",
                          "diensttraeger","speichertraeger","eingangstraeger"] }
    },
    "knotenklasse": { "type": "string", "maxLength": 32,
                      "pattern": "^[a-z][a-z0-9-]{0,31}$" },
    "fehlerzone":   { "type": "string", "maxLength": 64, "minLength": 1 },
    "exklusiv_fuer_mandant": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "kapazitaet": {
      "type": "object",
      "required": ["kerne","speicher_mb","plattenplatz_gb","beobachtet_am"],
      "properties": {
        "kerne":           { "type": "integer", "minimum": 1 },
        "speicher_mb":     { "type": "integer", "minimum": 1024 },
        "plattenplatz_gb": { "type": "integer", "minimum": 16 },
        "beobachtet_am":   { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" }
      },
      "additionalProperties": false
    },
    "abbildversion_aktiv":   { "type": "string", "maxLength": 64 },
    "abbildversion_inaktiv": { "type": "string", "maxLength": 64 },
    "leasestatus": {
      "type": "object",
      "required": ["gueltig_bis","beobachtet_am"],
      "properties": {
        "gueltig_bis":   { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
        "beobachtet_am": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" }
      },
      "additionalProperties": false
    },
    "knotenzertifikat": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" }
  },
  "allOf": [
    { "$ref": "urn:atrium:schema:objektrumpf:1.0" },
    { "if":   { "properties": { "rollen": { "contains": { "const": "zeuge" } } },
                "required": ["rollen"] },
      "then": { "properties": { "rollen": {
                  "not": { "contains": { "enum": ["diensttraeger","speichertraeger"] } } } } } },
    { "if":   { "properties": { "rollen": { "contains": { "const": "stimmknoten" } } },
                "required": ["rollen"] },
      "then": { "properties": { "rollen": {
                  "not": { "contains": { "const": "mitleser" } } } } } }
  ],
  "unevaluatedProperties": false
}
```

Die wichtigste Regel dieses Objekts steht nicht im Schema: die Zahl der Knoten mit der Rolle `stimmknoten` ist 1, 3 oder 5 und niemals 2 oder 4 (INV-05). Das ist eine Aussage über den Bestand und nicht über ein Objekt; sie wird bei jeder Mitgliedschaftsänderung in atrium-core geprüft, und die API lehnt eine Änderung auf eine gerade Stimmzahl ab. Ebenfalls außerhalb des Schemas: `exklusiv_fuer_mandant` ist genau dann zulässig, wenn der Zielmandant `isolationsstufe = m3` trägt; `abbildversion_inaktiv` fehlt genau nach der Erstinstallation und vor der ersten Aktualisierung; `leasestatus` ist ein Beobachtungsfeld, dessen Ablauf zur Selbstabschottung führt (INV-06) und das kein Bediener setzen kann.

### A1.2.8 Domäne

```json
{
  "$id": "urn:atrium:schema:domaene:1.0",
  "type": "object",
  "required": ["name","sichtbarkeit","dnssec_zustand","serie"],
  "properties": {
    "typ":     { "const": "domaene" },
    "zustand": { "enum": ["angelegt","signiert","aktiv","delegiert",
                          "stillgelegt","entfernt"] },
    "name":         { "$ref": "urn:atrium:schema:basis:1.0#/$defs/dnsname" },
    "sichtbarkeit": { "enum": ["intern","extern","beides"] },
    "geltungsbereich": {
      "type": "array", "maxItems": 256,
      "items": {
        "type": "object",
        "required": ["ziel","ziel_typ"],
        "properties": {
          "ziel":     { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
          "ziel_typ": { "enum": ["gruppe","person","geraet","netzzone"] }
        },
        "additionalProperties": false
      }
    },
    "dnssec_zustand":      { "enum": ["unsigniert","signiert","wechsel_laeuft"] },
    "serie":               { "type": "integer", "minimum": 1 },
    "delegierungsstatus":  { "enum": ["keine","ds_gesetzt","ds_geprueft","ds_fehlt"] }
  },
  "allOf": [
    { "$ref": "urn:atrium:schema:objektrumpf:1.0" },
    { "if":   { "properties": { "sichtbarkeit": { "enum": ["intern","beides"] } },
                "required": ["sichtbarkeit"] },
      "then": { "required": ["geltungsbereich"] } },
    { "if":   { "properties": { "sichtbarkeit": { "enum": ["extern","beides"] } },
                "required": ["sichtbarkeit"] },
      "then": { "required": ["delegierungsstatus"] } }
  ],
  "unevaluatedProperties": false
}
```

Außerhalb des Schemas geprüft: `name` ist global über alle Mandanten eindeutig, und eine Domäne darf keine echte Unterdomäne einer Domäne eines fremden Mandanten sein, weil sonst ein Mandant Namen unterhalb eines fremden Namensraums vergäbe; diese Prüfung ist ein Präfixvergleich über den Bestand aller Domänen und im Schema nicht ausdrückbar. `serie` ist gleich `sollzustand_version` bei der letzten Zonenänderung; ein von außen gesetzter Wert wird verworfen. Der Geltungsbereich ist nur für verwaltete Geräte in kontrollierten Netzen durchsetzbar, was die Konsole ausdrücklich benennt; das Schema kann diese Grenze nicht abbilden.

### A1.2.9 DNS-Eintrag

Zusammengeführte Fassung des Schemas aus [Kapitel 07](07-objektmodell.md); die beiden dortigen `allOf`-Mitglieder liegen hier in einer Liste.

```json
{
  "$id": "urn:atrium:schema:dnseintrag:1.0",
  "type": "object",
  "required": ["domaene","art","name","wert","ttl","sicht","quelle"],
  "properties": {
    "typ":     { "const": "dnseintrag" },
    "zustand": { "enum": ["erzeugt","aktiv","veraltet","entfernt"] },
    "domaene": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "art":  { "enum": ["A","AAAA","CNAME","MX","TXT","SRV","CAA",
                       "NS","DS","SVCB","HTTPS","PTR"] },
    "name": { "type": "string", "maxLength": 253,
              "pattern": "^(@|(\\*\\.)?([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)(\\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*)$" },
    "wert": { "type": "string", "minLength": 1, "maxLength": 4096 },
    "ttl":   { "type": "integer", "minimum": 60, "maximum": 86400 },
    "sicht": { "enum": ["intern","extern","beide"] },
    "quelle":{ "enum": ["handeingegeben","abgeleitet"] },
    "quell_objekt": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/urn" }
  },
  "allOf": [
    { "$ref": "urn:atrium:schema:objektrumpf:1.0" },
    { "if":   { "properties": { "quelle": { "const": "abgeleitet" } },
                "required": ["quelle"] },
      "then": { "required": ["quell_objekt"] },
      "else": { "not": { "required": ["quell_objekt"] } } },
    { "if":   { "properties": { "art": { "const": "A" } }, "required": ["art"] },
      "then": { "properties": { "wert": { "format": "ipv4" } } } },
    { "if":   { "properties": { "art": { "const": "AAAA" } }, "required": ["art"] },
      "then": { "properties": { "wert": { "format": "ipv6" } } } },
    { "if":   { "properties": { "art": { "const": "CNAME" } }, "required": ["art"] },
      "then": { "properties": { "name": { "not": { "const": "@" } } } } },
    { "if":   { "properties": { "art": { "const": "MX" } }, "required": ["art"] },
      "then": { "properties": { "wert": {
                  "pattern": "^(0|[1-9][0-9]{0,4}) [a-z0-9.-]{1,253}\\.$" } } } },
    { "if":   { "properties": { "art": { "const": "CAA" } }, "required": ["art"] },
      "then": { "properties": { "wert": {
                  "pattern": "^(0|128) (issue|issuewild|iodef) \"[^\"]{1,255}\"$" } } } }
  ],
  "unevaluatedProperties": false
}
```

Die Vorbelegung von `ttl` steht bewusst nicht als `default` im Schema. Sie stammt aus der `sichtbarkeit` der Domäne (300 s intern, 3600 s extern nach K-17), und eine Vorbelegung ohne benennbare Quelle verletzt INV-15; ein Schema-Vorgabewert hätte keine anzeigbare Herkunft. Außerhalb des Schemas geprüft: das Tupel (`domaene`, `name`, `art`, `sicht`, `wert`) ist eindeutig; ein `CNAME` schließt jeden weiteren Eintrag desselben `name` in derselben `sicht` aus; `DS` ist nur in der Elternzone zulässig; ein handeingegebener Eintrag, der mit einem abgeleiteten kollidiert, wird unter Nennung des erzeugenden Objekts abgelehnt; ein Eintrag mit `quelle: abgeleitet` wird für Bedienerschreibvorgänge zurückgewiesen (INV-09). Die Wertmuster für `MX` und `CAA` sind Syntaxprüfungen und keine Existenzprüfungen des Ziels.

### A1.2.10 Zertifikat

```json
{
  "$id": "urn:atrium:schema:zertifikat:1.0",
  "allOf": [
    { "$ref": "urn:atrium:schema:objektrumpf:1.0" },
    { "if":   { "properties": { "sperrstatus": { "const": "gesperrt" } },
                "required": ["sperrstatus"] },
      "then": { "required": ["sperrgrund"],
                "properties": { "zustand": { "const": "gesperrt" } } } }
  ],
  "type": "object",
  "required": ["inhaber","inhaber_typ","verwendungszweck","aussteller","seriennummer",
               "gueltig_von","gueltig_bis","erneuerungsschwelle","schluesselablage",
               "sperrstatus","der_kodierung"],
  "properties": {
    "typ":     { "const": "zertifikat" },
    "zustand": { "enum": ["beantragt","ausgestellt","aktiv","in_erneuerung",
                          "gesperrt","abgelaufen"] },
    "inhaber":     { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "inhaber_typ": { "enum": ["dienst","knoten","geraet","person","dienstkonto"] },
    "verwendungszweck": { "enum": ["dienst_server","knoten","geraet",
                                   "person_client","dienstkonto"] },
    "aussteller":   { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "seriennummer": { "type": "string", "pattern": "^[0-9a-f]{16,40}$" },
    "gueltig_von":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "gueltig_bis":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "erneuerungsschwelle": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "schluesselablage": { "enum": ["tpm","token","verschluesselter_speicher"] },
    "sperrstatus": { "enum": ["aktiv","gesperrt","abgelaufen","abgeloest"] },
    "sperrgrund":  { "enum": ["unspecified","keyCompromise","cACompromise",
                              "affiliationChanged","superseded","cessationOfOperation",
                              "certificateHold","privilegeWithdrawn","aACompromise"] },
    "der_kodierung": { "type": "string", "contentEncoding": "base64",
                       "maxLength": 16384 }
  },
  "unevaluatedProperties": false
}
```

Die Sperrgründe sind die Gründe nach RFC 5280 und werden als deren englische Bezeichner geführt, weil sie unverändert in die Sperrliste und in eine Antwort nach RFC 6960 eingehen; eine deutsche Übersetzung entstünde erst in der Oberfläche. `seriennummer` trägt mindestens 64 bit Zufall, was das Muster mit 16 Hexzeichen als Untergrenze abbildet, die Zufälligkeit aber nicht prüft. Außerhalb des Schemas geprüft: `gueltig_bis` liegt nach `gueltig_von`, `erneuerungsschwelle` liegt zwischen beiden; die Laufzeiten folgen K-13 und werden gegen den Verwendungszweck geprüft; `aussteller` verweist auf die Zwischen-CA desselben Mandanten; der private Schlüssel ist nie Teil des Objekts, weshalb das Schema kein entsprechendes Feld kennt (INV-20). Die Obergrenze von `der_kodierung` ist eine Festlegung dieses Anhangs und begrenzt den Beitrag der Zertifikate zur Exportgröße nach K-12.

### A1.2.11 Postfach

```json
{
  "$id": "urn:atrium:schema:postfach:1.0",
  "allOf": [
    { "$ref": "urn:atrium:schema:objektrumpf:1.0" },
    { "if":   { "properties": { "art": { "const": "persoenlich" } },
                "required": ["art"] },
      "then": { "properties": { "eigentuemer_typ": { "const": "person" } } },
      "else": { "properties": { "eigentuemer_typ": { "const": "gruppe" } } } }
  ],
  "type": "object",
  "required": ["art","ablageort","eigentuemer","eigentuemer_typ"],
  "properties": {
    "typ":     { "const": "postfach" },
    "zustand": { "enum": ["angelegt","aktiv","weitergeleitet",
                          "archiviert","geloescht"] },
    "art":       { "enum": ["persoenlich","geteilt"] },
    "ablageort": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "eigentuemer":     { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "eigentuemer_typ": { "enum": ["person","gruppe"] },
    "berechtigte": {
      "type": "array", "maxItems": 256,
      "items": { "type": "object", "required": ["subjekt","recht"],
        "properties": {
          "subjekt": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
          "recht":   { "enum": ["lesen","vollzugriff"] } },
        "additionalProperties": false }
    },
    "sendeberechtigung": {
      "type": "array", "maxItems": 256,
      "items": { "type": "object", "required": ["subjekt","art"],
        "properties": {
          "subjekt": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
          "art":     { "enum": ["senden_als","senden_im_auftrag"] } },
        "additionalProperties": false }
    },
    "kontingent_mb": { "type": "integer", "minimum": 1, "maximum": 10485760 },
    "fremdkennung":  { "type": "string", "maxLength": 256 }
  },
  "unevaluatedProperties": false
}
```

Außerhalb des Schemas geprüft: `kontingent_mb` ist nur gesetzt, wenn der Ablageort ein Kontingent kennt, was aus den Fähigkeiten der Konnektorbindung folgt und erst zur Laufzeit bekannt ist; `fremdkennung` ist ein Erstanlagefeld und wird nach INV-13 nie überschrieben; die Löschung eines Postfachs ist ausdrücklich als nicht rücknehmbar gekennzeichnet und nie Teil einer Massenaktion ohne Einzelaufstellung (INV-11).

### A1.2.12 Konnektorbindung

```json
{
  "$id": "urn:atrium:schema:konnektorbindung:1.0",
  "allOf": [{ "$ref": "urn:atrium:schema:objektrumpf:1.0" }],
  "type": "object",
  "required": ["manifest","vertrag_version","endpunkt","geheimnis_ref",
               "feldeigentum","abgleichintervall_s","ausgangs_positivliste"],
  "properties": {
    "typ":     { "const": "konnektorbindung" },
    "zustand": { "enum": ["eingerichtet","geprueft","aktiv","abweichend",
                          "ausgesetzt","entfernt"] },
    "manifest": { "type": "string", "maxLength": 64,
                  "pattern": "^[a-z][a-z0-9-]{0,62}$" },
    "vertrag_version": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/vertrag_version" },
    "endpunkt": { "type": "string", "maxLength": 512, "format": "uri",
                  "pattern": "^https://" },
    "geheimnis_ref": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/geheimnis_ref" },
    "faehigkeiten": { "type": "array", "maxItems": 64, "uniqueItems": true,
                      "items": { "type": "string",
                                 "pattern": "^[a-z][a-z0-9_]{0,63}$" } },
    "feldeigentum": {
      "type": "object", "minProperties": 1, "maxProperties": 512,
      "propertyNames": { "pattern": "^[a-z][a-z0-9_]{0,31}(\\.[a-z][a-z0-9_]{0,31}){1,3}$" },
      "additionalProperties": { "enum": ["atrium","fremd","erstanlage"] }
    },
    "abgleichintervall_s": { "type": "integer", "minimum": 60, "maximum": 86400 },
    "ausgangs_positivliste": {
      "type": "array", "minItems": 1, "maxItems": 32,
      "items": { "type": "object", "required": ["host","port"],
        "properties": {
          "host": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/dnsname" },
          "port": { "type": "integer", "minimum": 1, "maximum": 65535 } },
        "additionalProperties": false }
    },
    "letzte_erfolgreiche_beobachtung": {
      "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" }
  },
  "unevaluatedProperties": false
}
```

Das Schema erzwingt, dass `feldeigentum` nicht leer ist, nicht aber, dass es vollständig ist. Vollständigkeit heißt: für jedes Feld, das das Manifest unter `objekte.*.felder` führt, existiert genau ein Eintrag. Diese Prüfung ist ein Abgleich zweier Dokumente und erfolgt beim Einrichten der Bindung gegen das geladene Manifest; eine unvollständige Angabe führt zur Ablehnung (INV-13). Ebenfalls außerhalb des Schemas: `ausgangs_positivliste` wird aus `endpunkt` und den im Manifest begründeten Zusatzhosts erzeugt und ist ein Ableitungsfeld, das kein Bediener setzt (INV-21); das Tupel (`mandant`, `manifest`, `endpunkt`) ist eindeutig; `faehigkeiten` stammt aus `describe` und wird bei Abweichung zur Manifestdeklaration als Befund gemeldet, nicht stillschweigend übernommen.

### A1.2.13 Richtlinie

```json
{
  "$id": "urn:atrium:schema:richtlinie:1.0",
  "allOf": [
    { "$ref": "urn:atrium:schema:objektrumpf:1.0" },
    { "if":   { "properties": { "erzwingung": { "const": "weich" } },
                "required": ["erzwingung"] },
      "then": { "properties": { "begruendungspflicht": { "const": true } } } }
  ],
  "type": "object",
  "required": ["geltung","gegenstand","wert","erzwingung",
               "begruendungspflicht","version"],
  "properties": {
    "typ":     { "const": "richtlinie" },
    "zustand": { "enum": ["gesetzt","aktiv","versioniert","ausser_kraft"] },
    "geltung":    { "enum": ["plattform","mandant"] },
    "gegenstand": { "type": "string", "maxLength": 128,
                    "pattern": "^[a-z][a-z0-9_]{0,31}(\\.[a-z][a-z0-9_]{0,31}){1,4}$" },
    "wert": { "$comment": "Typ und Wertebereich je gegenstand im Registrierungsverzeichnis" },
    "erzwingung": { "enum": ["hart","weich"] },
    "begruendungspflicht": { "type": "boolean" },
    "version": { "type": "integer", "minimum": 1 }
  },
  "unevaluatedProperties": false
}
```

`wert` ist im Schema absichtlich untypisiert. Der zulässige Typ hängt von `gegenstand` ab, und eine Aufzählung aller Gegenstände im Entitätsschema würde bei jeder neuen Richtlinienart eine Schemaänderung erzwingen, obwohl die Erweiterung rein additiv ist. Die Typprüfung erfolgt in einem zweiten Schritt gegen das Registrierungsverzeichnis: dieses bildet `gegenstand` auf ein eigenes Teilschema ab, und die API weist eine Richtlinie mit unbekanntem `gegenstand` ab, statt sie ungeprüft zu speichern. Die Schwäche dieser Konstruktion ist benennbar und wird nicht verschwiegen: die Vollständigkeit der Prüfung hängt an der Pflege des Verzeichnisses, und ein dort fehlender Eintrag macht aus einer Ablehnung eine Sperre der betroffenen Handlung. Außerhalb des Schemas geprüft: zwei harte Richtlinien desselben Gegenstands auf derselben Geltungsebene sind ein Fehler und werden beim Schreiben abgelehnt, nicht zur Laufzeit aufgelöst.

### A1.2.14 Vorgang

```json
{
  "$id": "urn:atrium:schema:vorgang:1.0",
  "allOf": [{ "$ref": "urn:atrium:schema:objektrumpf:1.0" }],
  "type": "object",
  "required": ["ausloeser","betroffene_objekte","wirkungsvorschau",
               "vorschau_basis_version","freigabestatus","teilzustand",
               "idempotenzschluessel","korrelationskennung"],
  "properties": {
    "typ":     { "const": "vorgang" },
    "zustand": { "enum": ["entworfen","vorschau_berechnet","zur_freigabe_vorgelegt",
                          "freigegeben","in_ausfuehrung","abgeschlossen",
                          "teilweise_fehlgeschlagen","zurueckgenommen"] },
    "ausloeser": {
      "type": "object", "required": ["art","kennung"],
      "properties": {
        "art":     { "enum": ["person","dienstkonto","regel"] },
        "kennung": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" } },
      "additionalProperties": false
    },
    "betroffene_objekte": { "type": "array", "minItems": 1, "maxItems": 10000,
      "uniqueItems": true,
      "items": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/urn" } },
    "wirkungsvorschau": {
      "type": "array", "maxItems": 10000,
      "items": { "type": "object",
        "required": ["zielsystem","aktion","objekt","umkehrbar","kostenwirkung"],
        "properties": {
          "zielsystem": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
          "aktion":     { "type": "string", "maxLength": 64,
                          "pattern": "^[a-z][a-z0-9_]{0,31}\\.[a-z][a-z0-9_]{0,31}$" },
          "objekt":     { "$ref": "urn:atrium:schema:basis:1.0#/$defs/urn" },
          "umkehrbar":  { "type": "boolean" },
          "kostenwirkung": {
            "type": "object", "required": ["wirkung"],
            "properties": {
              "wirkung":    { "enum": ["keine","lizenz","speicher","unbekannt"] },
              "messgroesse":{ "type": "string", "maxLength": 128 },
              "anzeige":    { "type": "string", "maxLength": 256 } },
            "additionalProperties": false },
          "vorschau_unvollstaendig": { "type": "boolean" } },
        "additionalProperties": false }
    },
    "vorschau_basis_version": { "type": "integer", "minimum": 1 },
    "freigabestatus": { "enum": ["nicht_erforderlich","offen",
                                 "zugestimmt","abgelehnt"] },
    "teilzustand": {
      "type": "array", "maxItems": 256,
      "items": { "type": "object",
        "required": ["zielsystem","zustand","wiederholbar","beobachtet_am"],
        "properties": {
          "zielsystem":   { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
          "zustand":      { "enum": ["offen","in_arbeit","erfolgreich",
                                     "fehlgeschlagen","unbekannt","rest"] },
          "grund":        { "type": "string", "maxLength": 256 },
          "wiederholbar": { "type": "boolean" },
          "beobachtet_am":{ "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" } },
        "additionalProperties": false }
    },
    "idempotenzschluessel": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/hashwert" },
    "korrelationskennung":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "ruecksprung_verweis":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/urn" }
  },
  "unevaluatedProperties": false
}
```

Das Feld `vorschau_unvollstaendig` je Vorschaueintrag bildet den Fall ab, dass ein `plan`-Aufruf seine Frist überschreitet: die Freigabe bleibt möglich, trägt aber den Vermerk, dass eine Bindung ungeprüft ist ([Kapitel 09](09-konnektoren.md)). Drei Regeln stehen außerhalb des Schemas. Der Zustand `abgeschlossen` ist unzulässig, solange ein Eintrag in `teilzustand` nicht `erfolgreich` ist; das ist die Schemaseite von INV-12 und lässt sich nur als Bedingung über zwei Felder formulieren, die JSON-Schema für Listeninhalte in dieser Form nicht trägt, weshalb sie in der Zustandsübergangsprüfung liegt. Eine Wirkungsvorschau, deren `vorschau_basis_version` kleiner als die aktuelle Sollzustandsversion ist, ist entwertet und wird vor der Ausführung neu berechnet. `idempotenzschluessel` wird aus Vorgang, Bindung und Schritthashwert abgeleitet und ist über Wiederholungen stabil (INV-07).

## A1.3 Konnektormanifest

Das Manifest ist eine Sicherheitsgrenze und kein Konfigurationsdokument: ein unbekanntes Feld führt zur Ablehnung, nicht zum Ignorieren. [Kapitel 09](09-konnektoren.md) zeigt ein ausgefülltes Beispiel; hier steht das Schema, gegen das jedes Manifest beim Import validiert.

```json
{
  "$id": "urn:atrium:schema:konnektormanifest:1.0",
  "type": "object",
  "required": ["identitaet","vertrag_version","faehigkeiten","endpunkte",
               "authentisierung","ratengrenzen","fristen_ms","objekte",
               "kostenwirksame_aktionen","produktgrenze","fehlerabbildung",
               "gesundheitsprobe","import","signatur"],
  "additionalProperties": false,
  "properties": {
    "identitaet": {
      "type": "object", "additionalProperties": false,
      "required": ["kennung","produktname","herausgeber","manifest_version",
                   "stufe","zertifizierung"],
      "properties": {
        "kennung":      { "type": "string", "pattern": "^[a-z][a-z0-9-]{0,62}$" },
        "produktname":  { "type": "string", "minLength": 1, "maxLength": 128 },
        "herausgeber":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/urn" },
        "manifest_version": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/schema_version" },
        "stufe":        { "enum": ["t0","t1","t2"] },
        "zertifizierung": { "enum": ["A","B","C"] },
        "sicherheitskontakt": { "type": "string", "maxLength": 256 }
      },
      "if":   { "properties": { "zertifizierung": { "enum": ["A","B"] } },
                "required": ["zertifizierung"] },
      "then": { "required": ["sicherheitskontakt"] }
    },
    "vertrag_version": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/vertrag_version" },
    "faehigkeiten": { "type": "array", "minItems": 1, "maxItems": 64,
      "uniqueItems": true,
      "items": { "type": "string", "pattern": "^[a-z][a-z0-9_]{0,63}$" } },
    "nicht_unterstuetzt": {
      "type": "object", "maxProperties": 64,
      "propertyNames": { "pattern": "^[a-z][a-z0-9_]{0,63}$" },
      "additionalProperties": { "type": "string", "minLength": 8, "maxLength": 256 } },
    "endpunkte": {
      "type": "object", "additionalProperties": false,
      "required": ["erlaubte_schemata","basis","gesundheit","ausgang"],
      "properties": {
        "erlaubte_schemata": { "type": "array", "minItems": 1, "maxItems": 1,
                               "items": { "const": "https" } },
        "basis": { "type": "object", "additionalProperties": false,
                   "required": ["pflicht"],
                   "properties": { "pflicht": { "const": true } } },
        "gesundheit": { "type": "object", "additionalProperties": false,
          "required": ["pfad","methode","erwartet"],
          "properties": {
            "pfad":    { "type": "string", "pattern": "^/[A-Za-z0-9._~/-]{0,255}$" },
            "methode": { "enum": ["GET","HEAD"] },
            "erwartet":{ "type": "integer", "minimum": 200, "maximum": 299 } } },
        "ausgang": { "type": "object", "additionalProperties": false,
          "required": ["hosts_aus","zusatz_hosts","ports","namensaufloesung"],
          "properties": {
            "hosts_aus": { "type": "array", "minItems": 1, "maxItems": 1,
                           "items": { "const": "basis" } },
            "zusatz_hosts": { "type": "array", "maxItems": 8,
              "items": { "type": "object", "additionalProperties": false,
                "required": ["host","begruendung"],
                "properties": {
                  "host": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/dnsname" },
                  "begruendung": { "type": "string", "minLength": 16,
                                   "maxLength": 512 } } } },
            "ports": { "type": "array", "minItems": 1, "maxItems": 4,
                       "items": { "type": "integer", "minimum": 1,
                                  "maximum": 65535 } },
            "namensaufloesung": { "const": "nur_ueber_kern" } } }
      }
    },
    "authentisierung": {
      "type": "object", "additionalProperties": false,
      "required": ["verfahren","geheimnis_typ","vermittler","tls",
                   "benoetigte_fremdrechte","ausgeschlossene_fremdrechte"],
      "properties": {
        "verfahren": { "enum": ["bearer_token","mtls","basic_ueber_tls","oauth2_cc"] },
        "geheimnis_typ": { "enum": ["token","zertifikat","kennwort","client_credentials"] },
        "vermittler": { "const": "pflicht" },
        "tls": { "type": "object", "additionalProperties": false,
                 "required": ["mindestversion","pruefung"],
                 "properties": { "mindestversion": { "const": "1.3" },
                                 "pruefung": { "const": "vollstaendig" } } },
        "benoetigte_fremdrechte": { "type": "array", "minItems": 1, "maxItems": 32,
          "items": { "type": "string", "minLength": 4, "maxLength": 256 } },
        "ausgeschlossene_fremdrechte": { "type": "array", "maxItems": 32,
          "items": { "type": "string", "minLength": 4, "maxLength": 256 } }
      }
    },
    "ratengrenzen": {
      "type": "object", "additionalProperties": false,
      "required": ["anfragen_je_minute","gleichzeitig","rueckstaffelung"],
      "properties": {
        "anfragen_je_minute": { "type": "integer", "minimum": 1, "maximum": 100000 },
        "gleichzeitig":       { "type": "integer", "minimum": 1, "maximum": 32 },
        "rueckstaffelung": { "type": "object", "additionalProperties": false,
          "required": ["basis_ms","faktor","hoechstwert_ms","streuung"],
          "properties": {
            "basis_ms":      { "type": "integer", "minimum": 100, "maximum": 10000 },
            "faktor":        { "type": "number",  "minimum": 1.1, "maximum": 4 },
            "hoechstwert_ms":{ "type": "integer", "minimum": 1000, "maximum": 600000 },
            "streuung":      { "type": "number",  "minimum": 0, "maximum": 1 } } },
        "kontingentangabe": { "type": "string", "maxLength": 64,
                              "pattern": "^[A-Za-z][A-Za-z0-9-]{0,63}$" }
      }
    },
    "fristen_ms": {
      "type": "object", "additionalProperties": false,
      "required": ["describe","observe_einzeln","observe_bestand_seite",
                   "plan","apply_schritt","apply_auftrag","healthcheck"],
      "properties": {
        "describe":              { "type": "integer", "minimum": 100, "maximum": 2000 },
        "observe_einzeln":       { "type": "integer", "minimum": 100, "maximum": 3000 },
        "observe_bestand_seite": { "type": "integer", "minimum": 100, "maximum": 10000 },
        "plan":                  { "type": "integer", "minimum": 100, "maximum": 1500 },
        "apply_schritt":         { "type": "integer", "minimum": 100, "maximum": 3000 },
        "apply_auftrag":         { "type": "integer", "minimum": 1000, "maximum": 60000 },
        "healthcheck":           { "type": "integer", "minimum": 50,  "maximum": 1000 }
      }
    },
    "objekte": {
      "type": "object", "minProperties": 1, "maxProperties": 64,
      "propertyNames": { "pattern": "^[a-z][a-z0-9_]{0,31}$" },
      "additionalProperties": {
        "type": "object", "additionalProperties": false,
        "required": ["fremdtyp","zuordnungsschluessel","konvergenz","felder","aktionen"],
        "properties": {
          "fremdtyp": { "type": "string", "minLength": 1, "maxLength": 128 },
          "zuordnungsschluessel": { "type": "array", "minItems": 1, "maxItems": 4,
            "items": { "type": "string", "pattern": "^[a-z][a-z0-9_]{0,63}$" } },
          "konvergenz": { "enum": ["konvergent","konvergent_mit_pruefung",
                                   "nicht_konvergent"] },
          "eindeutigkeitspraedikat": { "type": "string", "maxLength": 256 },
          "felder": {
            "type": "object", "minProperties": 1, "maxProperties": 256,
            "propertyNames": { "pattern": "^[a-z][a-z0-9_]{0,63}$" },
            "additionalProperties": {
              "type": "object", "additionalProperties": false,
              "required": ["eigentum"],
              "properties": {
                "eigentum": { "enum": ["atrium","fremd","erstanlage"] },
                "quelle":   { "type": "string", "maxLength": 128,
                              "pattern": "^[a-z][a-z0-9_]{0,31}(\\.[a-z][a-z0-9_]{0,31}){1,3}$" },
                "muster":   { "type": "string", "maxLength": 256 },
                "abbildung":{ "type": "object", "maxProperties": 32,
                              "additionalProperties": { "type": "string",
                                                        "maxLength": 128 } },
                "pflicht":  { "type": "boolean" } },
              "if":   { "properties": { "eigentum": { "enum": ["atrium",
                                                               "erstanlage"] } },
                        "required": ["eigentum"] },
              "then": { "required": ["quelle"] },
              "else": { "not": { "required": ["quelle"] } } }
          },
          "aktionen": {
            "type": "object", "minProperties": 1, "maxProperties": 32,
            "propertyNames": { "enum": ["anlegen","aendern","aktivieren",
                                        "deaktivieren","entfernen","verknuepfen",
                                        "loesen","sicherung_vorbereiten",
                                        "sicherung_abschliessen","sicherung_pruefen"] },
            "additionalProperties": {
              "type": "object", "additionalProperties": false,
              "required": ["kostenwirksam"],
              "properties": { "kostenwirksam": { "type": "boolean" },
                              "ersetzt": { "type": "string", "maxLength": 32 } } }
          }
        },
        "if":   { "properties": { "konvergenz": { "const": "konvergent_mit_pruefung" } },
                  "required": ["konvergenz"] },
        "then": { "required": ["eindeutigkeitspraedikat"] }
      }
    },
    "kostenwirksame_aktionen": {
      "type": "array", "maxItems": 128,
      "items": { "type": "object", "additionalProperties": false,
        "required": ["aktion","wirkung"],
        "properties": {
          "aktion":  { "type": "string",
                       "pattern": "^[a-z][a-z0-9_]{0,31}\\.[a-z][a-z0-9_]{0,31}$" },
          "wirkung": { "enum": ["keine","lizenz","speicher","unbekannt"] },
          "messgroesse": { "type": "string", "maxLength": 128 },
          "anzeige":     { "type": "string", "minLength": 8, "maxLength": 256 } },
        "if":   { "properties": { "wirkung": { "enum": ["lizenz","speicher",
                                                        "unbekannt"] } },
                  "required": ["wirkung"] },
        "then": { "required": ["messgroesse","anzeige"] } }
    },
    "produktgrenze": {
      "type": "object", "additionalProperties": false,
      "required": ["atrium_besitzt","fremd_behaelt"],
      "properties": {
        "atrium_besitzt": { "type": "array", "minItems": 1, "maxItems": 64,
                            "items": { "type": "string", "minLength": 4,
                                       "maxLength": 256 } },
        "fremd_behaelt":  { "type": "array", "minItems": 1, "maxItems": 64,
                            "items": { "type": "string", "minLength": 4,
                                       "maxLength": 256 } },
        "anzeige_nur_lesend": { "type": "array", "maxItems": 64,
                                "items": { "type": "string", "maxLength": 256 } }
      }
    },
    "fehlerabbildung": {
      "type": "object", "additionalProperties": false,
      "required": ["regeln","rueckfall","text_weitergabe"],
      "properties": {
        "regeln": { "type": "array", "minItems": 1, "maxItems": 128,
          "items": { "type": "object", "additionalProperties": false,
            "required": ["signal","klasse"],
            "properties": {
              "signal": { "type": "string", "maxLength": 64,
                          "pattern": "^(http:[0-9]{3}|http:[0-9]xx|transport:[a-z]{3,16})$" },
              "klasse": { "enum": ["dauerhaft","voruebergehend","rechte",
                                   "kontingent","schema","konflikt"] },
              "wartezeit_aus": { "const": "kontingentangabe" } } } },
        "rueckfall": { "const": "dauerhaft" },
        "text_weitergabe": { "const": "nein" }
      }
    },
    "gesundheitsprobe": {
      "type": "object", "additionalProperties": false,
      "required": ["art","pruefungen","intervall_s","abweichend_nach"],
      "properties": {
        "art": { "const": "lesend" },
        "pruefungen": { "type": "array", "minItems": 1, "maxItems": 8,
          "uniqueItems": true,
          "items": { "enum": ["erreichbarkeit","zertifikatskette",
                              "vertragsversion_vergleich","faehigkeiten_vergleich"] } },
        "intervall_s":     { "type": "integer", "minimum": 60, "maximum": 3600 },
        "abweichend_nach": { "type": "integer", "minimum": 1, "maximum": 10 }
      }
    },
    "import": {
      "type": "object", "additionalProperties": false,
      "required": ["seitengroesse","fortsetzungsmarke","hoechstdauer_s",
                   "stabilitaetspruefung"],
      "properties": {
        "seitengroesse":     { "type": "integer", "minimum": 10, "maximum": 1000 },
        "fortsetzungsmarke": { "const": "pflicht" },
        "hoechstdauer_s":    { "type": "integer", "minimum": 30, "maximum": 3600 },
        "stabilitaetspruefung": { "enum": ["doppelabruf","keine"] }
      }
    },
    "signatur": {
      "type": "object", "additionalProperties": false,
      "required": ["verfahren","schluessel_kennung","wert",
                   "transparenzprotokoll_index","zeitstempel"],
      "properties": {
        "verfahren": { "const": "ed25519" },
        "schluessel_kennung": { "type": "string", "maxLength": 128 },
        "wert": { "type": "string", "contentEncoding": "base64",
                  "minLength": 86, "maxLength": 128 },
        "transparenzprotokoll_index": { "type": "integer", "minimum": 0 },
        "zeitstempel": { "type": "string", "contentEncoding": "base64",
                         "maxLength": 16384 }
      }
    }
  }
}
```

Die harten Festwerte sind Absicht. `tls.mindestversion` ist `1.3` und `tls.pruefung` ist `vollstaendig`, weil eine schwächere Angabe im Schema gar nicht erst ausdrückbar sein soll; `erlaubte_schemata` enthält ausschließlich `https`; `namensaufloesung` ist `nur_ueber_kern`, weil ein eigener Resolver im Konnektorprozess die Ausgangs-Positivliste umginge; `text_weitergabe` ist `nein`, weil ein Fremdtext Feldnamen, Pfade oder Geheimnisfragmente enthalten kann und die Oberfläche deshalb nie erreicht (INV-16, INV-17); `rueckfall` ist `dauerhaft`, weil ein unbekanntes Fremdsignal als vorübergehend behandelt eine stille Endlosschleife gegen eine Fremd-API erzeugt.

Vier Prüfungen bleiben außerhalb des Schemas und werden beim Import und im Vertragstest ausgeführt. Erstens die Vollständigkeit der Eigentumsangabe gegenüber dem tatsächlichen Feldsatz der eingesetzten Fremdfassung: das Schema erzwingt eine Angabe je deklariertem Feld, nicht die Deklaration jedes existierenden Feldes, und diese Lücke ist mit Daten allein nicht schließbar (INV-13). Zweitens die Deckung bekannter Kostenwirkungen: der Vertragstest führt eine Liste als kostenwirksam bekannter Aktionen je Fremdsystem, und eine dort verzeichnete, im Manifest nicht deklarierte Aktion bricht den Test (INV-29). Drittens die Übereinstimmung von `faehigkeiten` mit der Antwort von `describe` zur Laufzeit; eine Abweichung ist ein Befund an der Bindung. Viertens die Prüfung, ob `zuordnungsschluessel` einen Anzeigenamen enthält, was unzulässig ist, weil Anzeigenamen nicht stabil sind; die Prüfung erfordert Kenntnis der Feldsemantik und ist nicht mustergestützt.

## A1.4 Katalogeintrag

Das Schema folgt der Struktur aus [Kapitel 15](15-dienste-software.md). Dieser Anhang führt die dort in Textform gezeigten Felder als validierbares Schema und ergänzt die Wertebereiche.

```json
{
  "$id": "urn:atrium:schema:katalogeintrag:1.0",
  "type": "object",
  "additionalProperties": false,
  "required": ["kennung","schema_version","produkt_name","produkt_reihe","fassung",
               "herkunft","signatur","stueckliste","vertrauensstufe","abbilder",
               "laufzeit","standardbudget","speicherbereiche","gesundheitsproben",
               "standard_veroeffentlichung","produktgrenze","migration"],
  "properties": {
    "kennung":        { "$ref": "urn:atrium:schema:basis:1.0#/$defs/urn" },
    "schema_version": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/schema_version" },
    "produkt_name":   { "type": "string", "minLength": 1, "maxLength": 128 },
    "produkt_reihe":  { "type": "string", "pattern": "^[a-z][a-z0-9-]{0,31}$" },
    "fassung": {
      "type": "object", "additionalProperties": false,
      "required": ["bezeichner","kanal"],
      "properties": {
        "bezeichner": { "type": "string", "minLength": 1, "maxLength": 64 },
        "kanal":      { "enum": ["stabil","vorab"] },
        "vorgaenger": { "oneOf": [
            { "$ref": "urn:atrium:schema:basis:1.0#/$defs/urn" },
            { "type": "null" } ] } }
    },
    "herkunft": {
      "type": "object", "additionalProperties": false,
      "required": ["quelltext_ursprung","lizenz_spdx","bauverfahren",
                   "bauprotokoll_hash"],
      "properties": {
        "quelltext_ursprung": { "type": "string", "format": "uri", "maxLength": 512 },
        "lizenz_spdx":        { "type": "string", "maxLength": 128 },
        "bauverfahren":       { "enum": ["reproduzierbar","nicht_reproduzierbar"] },
        "bauprotokoll_hash":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/hashwert" } }
    },
    "signatur": { "$ref": "urn:atrium:schema:konnektormanifest:1.0#/properties/signatur" },
    "stueckliste": {
      "type": "object", "additionalProperties": false,
      "required": ["spdx","cyclonedx"],
      "properties": { "spdx":      { "type": "string", "maxLength": 512 },
                      "cyclonedx": { "type": "string", "maxLength": 512 } }
    },
    "vertrauensstufe": { "enum": ["A","B","C"] },
    "abbilder": {
      "type": "array", "minItems": 1, "maxItems": 4,
      "items": { "type": "object", "additionalProperties": false,
        "required": ["architektur","verweis","digest"],
        "properties": {
          "architektur": { "enum": ["amd64","arm64"] },
          "verweis":     { "type": "string", "maxLength": 512 },
          "digest":      { "$ref": "urn:atrium:schema:basis:1.0#/$defs/hashwert" } } }
    },
    "laufzeit": {
      "type": "object", "additionalProperties": false,
      "required": ["benutzerkennung","wurzelrechte","schreibbare_pfade",
                   "selbstaktualisierung"],
      "properties": {
        "benutzerkennung": { "const": "eigen" },
        "wurzelrechte": { "oneOf": [
            { "const": "nein" },
            { "type": "object", "additionalProperties": false,
              "required": ["benoetigt"],
              "properties": { "benoetigt": { "type": "string", "minLength": 16,
                                             "maxLength": 512 } } } ] },
        "schreibbare_pfade": { "type": "array", "maxItems": 32,
          "items": { "type": "string", "pattern": "^/[A-Za-z0-9._/-]{1,255}$" } },
        "selbstaktualisierung": { "const": "aus" } }
    },
    "standardbudget": {
      "type": "object", "additionalProperties": false,
      "required": ["arbeitsspeicher_weich_mb","arbeitsspeicher_hart_mb",
                   "rechenanteil_millikerne","prozesse_max"],
      "properties": {
        "arbeitsspeicher_weich_mb": { "type": "integer", "minimum": 128,
                                      "maximum": 262144 },
        "arbeitsspeicher_hart_mb":  { "type": "integer", "minimum": 128,
                                      "maximum": 262144 },
        "rechenanteil_millikerne":  { "type": "integer", "minimum": 100,
                                      "maximum": 64000 },
        "prozesse_max":             { "type": "integer", "minimum": 1,
                                      "maximum": 4096 } }
    },
    "speicherbereiche": {
      "type": "array", "maxItems": 16,
      "items": { "type": "object", "additionalProperties": false,
        "required": ["name","groesse_vorgabe_gb","datenklassenvorgabe",
                     "pfad_im_dienst","sicherungshaken"],
        "properties": {
          "name": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/technischer_name" },
          "groesse_vorgabe_gb": { "type": "integer", "minimum": 1,
                                  "maximum": 1048576 },
          "datenklassenvorgabe": { "enum": ["lokal","gespiegelt",
                                            "synchron_gespiegelt"] },
          "pfad_im_dienst": { "type": "string",
                              "pattern": "^/[A-Za-z0-9._/-]{1,255}$" },
          "sicherungshaken": {
            "type": "object", "additionalProperties": false,
            "required": ["ohne_haken"],
            "properties": {
              "vorbereiten": { "type": "object", "additionalProperties": false,
                "required": ["aufruf","frist_s"],
                "properties": { "aufruf": { "type": "string", "maxLength": 256 },
                                "frist_s": { "type": "integer", "minimum": 1,
                                             "maximum": 60 } } },
              "freigeben":   { "type": "object", "additionalProperties": false,
                "required": ["aufruf","frist_s"],
                "properties": { "aufruf": { "type": "string", "maxLength": 256 },
                                "frist_s": { "type": "integer", "minimum": 1,
                                             "maximum": 60 } } },
              "ohne_haken": { "const": "absturzkonsistent" } } } } }
    },
    "abhaengigkeiten": {
      "type": "array", "maxItems": 16,
      "items": { "type": "object", "additionalProperties": false,
        "required": ["katalogeintrag","art","bereitschaft_abwarten"],
        "properties": {
          "katalogeintrag": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/urn" },
          "art": { "enum": ["benoetigt","empfiehlt"] },
          "bereitschaft_abwarten": { "type": "boolean" } } }
    },
    "gesundheitsproben": {
      "type": "object", "additionalProperties": false,
      "required": ["start","bereitschaft","leben"],
      "properties": {
        "start":       { "type": "object", "additionalProperties": false,
          "required": ["pfad","intervall_s","versuche_max"],
          "properties": { "pfad": { "type": "string", "maxLength": 256 },
                          "intervall_s": { "type": "integer", "minimum": 1,
                                           "maximum": 60 },
                          "versuche_max": { "type": "integer", "minimum": 1,
                                            "maximum": 600 } } },
        "bereitschaft":{ "type": "object", "additionalProperties": false,
          "required": ["pfad","intervall_s","erfolge_noetig"],
          "properties": { "pfad": { "type": "string", "maxLength": 256 },
                          "intervall_s": { "type": "integer", "minimum": 1,
                                           "maximum": 60 },
                          "erfolge_noetig": { "type": "integer", "minimum": 1,
                                              "maximum": 10 } } },
        "leben":       { "type": "object", "additionalProperties": false,
          "required": ["pfad","intervall_s","fehlschlaege_max"],
          "properties": { "pfad": { "type": "string", "maxLength": 256 },
                          "intervall_s": { "type": "integer", "minimum": 1,
                                           "maximum": 300 },
                          "fehlschlaege_max": { "type": "integer", "minimum": 1,
                                                "maximum": 10 } } } }
    },
    "standard_veroeffentlichung": {
      "type": "object", "additionalProperties": false,
      "required": ["protokoll","sichtbarkeit_vorgabe","anmeldung"],
      "properties": {
        "protokoll": { "const": "https" },
        "sichtbarkeit_vorgabe": { "enum": ["nur_intern","intern_und_extern"] },
        "anmeldung": { "enum": ["oidc","kopfzeile_am_eingang","keine"] } }
    },
    "benoetigte_konnektoren": {
      "type": "array", "maxItems": 16,
      "items": { "type": "object", "additionalProperties": false,
        "required": ["manifest","vertrag_version","pflicht"],
        "properties": {
          "manifest": { "type": "string", "pattern": "^[a-z][a-z0-9-]{0,62}$" },
          "vertrag_version": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/vertrag_version" },
          "pflicht": { "type": "boolean" } } }
    },
    "produktgrenze": {
      "type": "object", "additionalProperties": false,
      "required": ["atrium_besitzt","fremd_besitzt","nur_erstanlage"],
      "properties": {
        "atrium_besitzt": { "type": "array", "minItems": 1, "maxItems": 64,
                            "items": { "type": "string", "maxLength": 256 } },
        "fremd_besitzt":  { "type": "array", "minItems": 1, "maxItems": 64,
                            "items": { "type": "string", "maxLength": 256 } },
        "nur_erstanlage": { "type": "array", "maxItems": 64,
                            "items": { "type": "string", "maxLength": 256 } } }
    },
    "migration": {
      "type": "object", "additionalProperties": false,
      "required": ["laeuft_beim_start","rueckrollbar","dauer_annahme_min",
                   "uebersprungene_hauptfassungen"],
      "properties": {
        "von_fassung": { "type": "string", "maxLength": 64 },
        "laeuft_beim_start": { "type": "boolean" },
        "rueckrollbar": { "const": false },
        "dauer_annahme_min": { "type": "integer", "minimum": 0, "maximum": 1440 },
        "uebersprungene_hauptfassungen": { "type": "boolean" } }
    },
    "abkuendigung": {
      "type": "object", "additionalProperties": false,
      "properties": {
        "ab":   { "oneOf": [{ "type": "string", "format": "date" },
                            { "type": "null" }] },
        "ende": { "oneOf": [{ "type": "string", "format": "date" },
                            { "type": "null" }] },
        "nachfolger": { "oneOf": [
            { "$ref": "urn:atrium:schema:basis:1.0#/$defs/urn" },
            { "type": "null" }] } }
    }
  }
}
```

`migration.rueckrollbar` trägt den Festwert `false`. Ein Katalogeintrag, der einen Rückweg behauptet, wird nur mit einer gegen eine Laborinstanz durchlaufenen Rückmigration aufgenommen, und diese Ausnahme ist ein Freigabevorgang und keine Schemaeigenschaft. Außerhalb des Schemas geprüft: `arbeitsspeicher_hart_mb` ist nicht kleiner als `arbeitsspeicher_weich_mb`; der Abhängigkeitsgraph über `abhaengigkeiten` ist azyklisch, was eine Traversierung über den Katalog erfordert und beim Import mit Nennung der schließenden Kante abgelehnt wird; die Signatur wird nicht nur beim Import, sondern bei jedem Start von atrium-node erneut geprüft, weil ein Import ein einmaliges Ereignis ist und die Datei danach Jahre auf dem Knoten liegt; `lizenz_spdx` wird gegen die Liste gültiger SPDX-Kennungen geprüft, die als Daten mitgeliefert und nicht im Schema aufgezählt wird.

## A1.5 Rechteregel und Selektorsyntax

Die Rechteregel ist ein eigenes Objekt und nicht identisch mit der Richtlinie aus A1.2.13: die Richtlinie erzeugt Vorbelegungen, die Rechteregel entscheidet über Zugriffe. Die Grammatik des Selektors ist absichtlich klein, weil jeder Selektor in ein Abfrageprädikat übersetzbar sein muss ([Kapitel 19](19-mandanten-rechte-audit.md)).

```
selektor        = mandantenterm *( "und" feldterm )
mandantenterm   = "mandant" "=" ( ulid / "$eigener" / "*" )
feldterm        = feldpfad operator wert
feldpfad        = entitaetstyp "." feldname *( "." feldname )
entitaetstyp    = 3*32LOWER
feldname        = LOWER *31( LOWER / DIGIT / "_" )
operator        = "=" / "!=" / "in" / "praefix"
wert            = ulid / zeichenkette / wahrheitswert / zahl / wertmenge
wertmenge       = "(" wert *( "," wert ) ")"            ; nur mit Operator "in"
zeichenkette    = DQUOTE 1*255(%x20-21 / %x23-7E) DQUOTE ; ohne Anfuehrungszeichen
```

Drei Beschränkungen sind Entwurfsentscheidungen mit Begründung. Es gibt kein Oder über Felder verschiedener Tabellen, keine Unterabfrage, keinen Funktionsaufruf und keine rekursive Traversierung, weil ein solcher Ausdruck nicht in ein Abfrageprädikat übersetzbar wäre und die objektweise Nachfilterung die Listenzusage aus K-18 verfehlt. Der Mandantenterm steht an erster Stelle und ist Pflicht, weil die Datenzugriffsschicht jede Abfrage ohne Mandantenprädikat ablehnt (INV-19). Die Zeichenkette schließt das Anführungszeichen aus, damit die Zerlegung ohne Maskierungsregeln auskommt; eine Maskierungsregel ist der übliche Ort, an dem Einschleusungen entstehen.

```json
{
  "$id": "urn:atrium:schema:rechteregel:1.0",
  "allOf": [
    { "$ref": "urn:atrium:schema:objektrumpf:1.0" },
    { "if":   { "properties": { "wirkung": { "const": "ausschluss" } },
                "required": ["wirkung"] },
      "then": { "required": ["begruendung"] } }
  ],
  "type": "object",
  "required": ["wirkung","subjekt","aktionen","selektor","gueltig_von"],
  "properties": {
    "typ":     { "const": "rechteregel" },
    "zustand": { "enum": ["gesetzt","aktiv","ausser_kraft"] },
    "wirkung": { "enum": ["gewaehrung","ausschluss"] },
    "subjekt": {
      "type": "object", "additionalProperties": false,
      "required": ["art","kennung"],
      "properties": {
        "art":     { "enum": ["rolle","gruppe","person","dienstkonto"] },
        "kennung": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" } }
    },
    "aktionen": {
      "type": "array", "minItems": 1, "maxItems": 32, "uniqueItems": true,
      "items": { "type": "string", "maxLength": 80,
                 "pattern": "^(klasse:[a-z][a-z0-9_]{0,63}|[a-z][a-z0-9_]{0,31}\\.([a-z][a-z0-9_]{0,31}|\\*))$" }
    },
    "selektor": {
      "type": "object", "additionalProperties": false,
      "required": ["mandant","terme"],
      "properties": {
        "mandant": { "oneOf": [
            { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
            { "enum": ["$eigener","*"] } ] },
        "terme": {
          "type": "array", "maxItems": 8,
          "items": { "type": "object", "additionalProperties": false,
            "required": ["feld","operator","wert"],
            "properties": {
              "feld": { "type": "string", "maxLength": 96,
                        "pattern": "^[a-z]{3,32}(\\.[a-z][a-z0-9_]{0,31}){1,2}$" },
              "operator": { "enum": ["=","!=","in","praefix"] },
              "wert": { "oneOf": [
                  { "type": "string", "maxLength": 255 },
                  { "type": "boolean" },
                  { "type": "integer" },
                  { "type": "array", "minItems": 1, "maxItems": 64,
                    "items": { "type": "string", "maxLength": 255 } } ] } },
            "if":   { "properties": { "operator": { "const": "in" } },
                      "required": ["operator"] },
            "then": { "properties": { "wert": { "type": "array" } } },
            "else": { "properties": { "wert": { "not": { "type": "array" } } } } }
        }
      }
    },
    "bedingungen": {
      "type": "array", "maxItems": 8,
      "items": { "type": "object", "additionalProperties": false,
        "required": ["art"],
        "properties": {
          "art": { "enum": ["zeit_im_fenster","authentisierungsguete",
                            "netzzone_in","freigabe_vorhanden",
                            "antragsteller_ungleich_subjekt","mandant_schalter"] },
          "von": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
          "bis": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
          "stufe": { "enum": ["kennwort","totp","passkey","passkey_mfa"] },
          "netzzonen": { "type": "array", "maxItems": 32,
            "items": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" } },
          "freigabetyp": { "type": "string", "maxLength": 64 },
          "schalter": { "type": "string", "maxLength": 64 },
          "schalterwert": { "type": "string", "maxLength": 64 } },
        "allOf": [
          { "if":   { "properties": { "art": { "const": "zeit_im_fenster" } },
                      "required": ["art"] },
            "then": { "required": ["von","bis"] } },
          { "if":   { "properties": { "art": { "const": "authentisierungsguete" } },
                      "required": ["art"] },
            "then": { "required": ["stufe"] } },
          { "if":   { "properties": { "art": { "const": "netzzone_in" } },
                      "required": ["art"] },
            "then": { "required": ["netzzonen"] } },
          { "if":   { "properties": { "art": { "const": "mandant_schalter" } },
                      "required": ["art"] },
            "then": { "required": ["schalter","schalterwert"] } } ] }
    },
    "gueltig_von": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "gueltig_bis": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "begruendung": { "type": "string", "minLength": 8, "maxLength": 512 }
  },
  "unevaluatedProperties": false
}
```

Vier Regeln sind nicht schematisch prüfbar. `selektor.terme[].feld` muss auf ein indiziertes Feld verweisen, sonst ist die Übersetzung in ein Prädikat zwar möglich, aber die Listenzusage aus K-18 verfehlt; das Feldverzeichnis liegt als Daten vor und wird beim Speichern der Regel geprüft, wobei ein unzulässiger Teilausdruck benannt wird. `gueltig_bis` ist Pflichtfeld für jede Regel, die eine Aktionsklasse der Verwaltung gewährt, was die Kenntnis der Aktionsklassen voraussetzt. Der Konflikt zwischen einer Gewährung und einem Ausschluss ist entscheidbar, weil die Selektorsprache beschränkt ist, aber die Entscheidung ist ein Mengenschnitt über zwei Regeln und kein Feldtest. Die Regelauswertung selbst erzeugt bei der Verweigerung eine Quellenliste; das Schema kann nicht erzwingen, dass eine Fehlermeldung keinen Objektnamen preisgibt, den der Anfragende nicht sehen darf, und diese Prüfung liegt in der Musterprüfung der Meldungstexte.

Sichere Praxis an dieser Schnittstelle: das erzeugte Prädikat wird als parametrisierte Abfrage gebunden und nie als Zeichenkette zusammengesetzt; Selektorwerte werden am Rand gegen dieses Schema validiert und nie in eine Kommandozeile, eine Vorlage oder einen dynamisch ausgewerteten Ausdruck übernommen; die Regelsprache kennt keine Schleife, keine Funktionsdefinition und keinen Aufruf externen Codes.

## A1.6 Auditereignis und Hashverkettung

Das Auditereignis liegt außerhalb des replizierten Kernzustands, ist nur anhängbar und durch keine Rolle änderbar (INV-23). Das Schema bildet die Kettenfelder ab; die Kette selbst ist eine Eigenschaft der Folge und nicht des einzelnen Satzes.

```json
{
  "$id": "urn:atrium:schema:auditereignis:1.0",
  "type": "object",
  "additionalProperties": false,
  "required": ["strom_kennung","folgenummer","zeitstempel","akteur","mandant",
               "aktion","ergebnis","korrelationskennung","vorgaenger_hashwert",
               "eigen_hashwert","schema_version"],
  "properties": {
    "strom_kennung":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "folgenummer":    { "type": "integer", "minimum": 0 },
    "zeitstempel":    { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
    "zeitguete_ms":   { "type": "integer", "minimum": 0, "maximum": 500 },
    "akteur": {
      "type": "object", "additionalProperties": false,
      "required": ["art","kennung"],
      "properties": {
        "art":     { "enum": ["person","dienstkonto","regel","system","notzugang"] },
        "kennung": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" } }
    },
    "mandant":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "aktion":   { "type": "string", "maxLength": 64,
                  "pattern": "^[a-z][a-z0-9_]{0,31}\\.[a-z][a-z0-9_]{0,31}$" },
    "objektverweis": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/urn" },
    "vorgang":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "vorher":   { "type": "object" },
    "nachher":  { "type": "object" },
    "ergebnis": { "enum": ["erfolg","teilweise","fehlschlag","abgelehnt"] },
    "korrelationskennung": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
    "vorgaenger_hashwert": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/hashwert" },
    "eigen_hashwert":      { "$ref": "urn:atrium:schema:basis:1.0#/$defs/hashwert" },
    "siegel": {
      "type": "object", "additionalProperties": false,
      "required": ["bis_folgenummer","verfahren","wert","zeitstempel_token"],
      "properties": {
        "bis_folgenummer":  { "type": "integer", "minimum": 0 },
        "verfahren":        { "const": "ed25519" },
        "schluessel_kennung": { "type": "string", "maxLength": 128 },
        "wert":             { "type": "string", "contentEncoding": "base64",
                              "minLength": 86, "maxLength": 128 },
        "zeitstempel_token":{ "type": "string", "contentEncoding": "base64",
                              "maxLength": 16384 } }
    },
    "schema_version": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/schema_version" }
  }
}
```

Die Kettenbildung ist eine Rechenvorschrift und kein Feldtest.

```
kanon(e)   = Serialisierung von e ohne das Feld "eigen_hashwert" nach RFC 8785
h(e)       = "sha2-256:" || hex( SHA-256( kanon(e) ) )              # FIPS 180-4

Genesis eines Stroms:
  e0.folgenummer         = 0
  e0.vorgaenger_hashwert = "sha2-256:" || hex(0^256)                # 64 Nullen
  e0.eigen_hashwert      = h(e0)

Fortschreibung:
  e(n).folgenummer         = e(n-1).folgenummer + 1
  e(n).vorgaenger_hashwert = e(n-1).eigen_hashwert
  e(n).eigen_hashwert      = h(e(n))

Pruefung eines Stroms (bei jedem Start und bei jedem Export):
  fuer n = 1 .. N:
    pruefe e(n).folgenummer == e(n-1).folgenummer + 1
    pruefe e(n).vorgaenger_hashwert == e(n-1).eigen_hashwert
    pruefe e(n).eigen_hashwert == h(e(n))
  Abweichung => Kettenbruch melden, nicht reparieren
```

Vier Eigenschaften stehen nicht im Schema. Die Lückenlosigkeit von `folgenummer` ist eine Aussage über die Folge; sie wird bei jedem Start und bei jedem Export geprüft, und ein Bruch wird gemeldet und nicht repariert, weil eine Reparatur eine Änderung an einem unveränderlichen Nachweis wäre. `vorher` und `nachher` sind untypisiert, dürfen aber keine Werte von Feldern der Klasse Geheimnisreferenz enthalten; die Durchsetzung ist eine Ausgabeprüfung gegen Geheimnismuster und nicht schematisch möglich, weil jedes Feld ein Geheimnis enthalten könnte. `zeitguete_ms` bildet K-30 ab: ein Knoten ohne belegbare Zeitgüte schreibt kein Auditereignis (INV-32), und das Fehlen des Feldes ist damit kein gültiger Zustand, sondern ein Hinweis auf einen Satz aus einer älteren Schemaminderversion. Die Reihenfolge zweier Ereignisse aus verschiedenen Strömen ist nicht definiert; `strom_kennung` trennt je Verwaltungsknoten eine eigene Kette, und nur `korrelationskennung` verbindet sie fachlich.

## A1.7 Deklarativer Gesamtexport

Der Sollzustandsexport ist ein selbstbeschreibendes Textformat, das ohne Atrium lesbar ist. Er enthält keine Nutzdaten und keine Geheimniswerte, sondern Geheimnisse ausschließlich als Referenz (INV-20).

```json
{
  "$id": "urn:atrium:schema:gesamtexport:1.0",
  "type": "object",
  "additionalProperties": false,
  "required": ["kopf","typreihenfolge","objekte","nicht_enthalten",
               "geheimnisse_neu_zu_hinterlegen","auditabschluss","signatur"],
  "properties": {
    "kopf": {
      "type": "object", "additionalProperties": false,
      "required": ["format_kennung","format_version","schema_version",
                   "installation_kennung","erzeugt_am","erzeugender_knoten",
                   "sollzustand_version","umfang","objektzahl_je_typ"],
      "properties": {
        "format_kennung":  { "const": "atrium-sollzustandsexport" },
        "format_version":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/schema_version" },
        "schema_version":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/schema_version" },
        "installation_kennung": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
        "erzeugt_am":      { "$ref": "urn:atrium:schema:basis:1.0#/$defs/zeitpunkt" },
        "erzeugt_durch_vorgang": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
        "erzeugender_knoten": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
        "sollzustand_version": { "type": "integer", "minimum": 1 },
        "umfang": {
          "type": "object", "additionalProperties": false,
          "required": ["art"],
          "properties": {
            "art": { "enum": ["vollstaendig","mandantenauszug"] },
            "mandanten": { "type": "array", "minItems": 1, "maxItems": 1024,
              "items": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" } } },
          "if":   { "properties": { "art": { "const": "mandantenauszug" } },
                    "required": ["art"] },
          "then": { "required": ["mandanten"] } },
        "objektzahl_je_typ": {
          "type": "object", "maxProperties": 64,
          "propertyNames": { "pattern": "^[a-z]{3,32}$" },
          "additionalProperties": { "type": "integer", "minimum": 0 } }
      }
    },
    "typreihenfolge": {
      "type": "array", "minItems": 1, "maxItems": 64, "uniqueItems": true,
      "items": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/entitaetstyp" }
    },
    "objekte": {
      "type": "array", "minItems": 1, "maxItems": 64,
      "items": {
        "type": "object", "additionalProperties": false,
        "required": ["typ","schema","eintraege"],
        "properties": {
          "typ":    { "$ref": "urn:atrium:schema:basis:1.0#/$defs/entitaetstyp" },
          "schema": { "type": "string", "maxLength": 128,
                      "pattern": "^urn:atrium:schema:[a-z]{3,32}:[0-9]+\\.[0-9]+$" },
          "eintraege": { "type": "array", "items": { "type": "object" } } }
      }
    },
    "nicht_enthalten": {
      "type": "array", "minItems": 1, "maxItems": 32,
      "items": { "type": "object", "additionalProperties": false,
        "required": ["gegenstand","grund","anzahl"],
        "properties": {
          "gegenstand": { "enum": ["nutzdaten","geheimniswerte","private_schluessel",
                                   "istzustand","metriken","betriebsprotokolle",
                                   "abgeleitete_artefakte_der_fremdsysteme"] },
          "grund":  { "type": "string", "minLength": 8, "maxLength": 256 },
          "anzahl": { "type": "integer", "minimum": 0 } } }
    },
    "geheimnisse_neu_zu_hinterlegen": {
      "type": "array", "maxItems": 4096,
      "items": { "type": "object", "additionalProperties": false,
        "required": ["verweisname","typ","zielsystem","mandant"],
        "properties": {
          "verweisname": { "$ref": "urn:atrium:schema:basis:1.0#/$defs/geheimnis_ref" },
          "typ":         { "enum": ["token","zertifikat","kennwort",
                                    "client_credentials","hauptschluessel"] },
          "zielsystem":  { "type": "string", "maxLength": 256 },
          "mandant":     { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" } } }
    },
    "auditabschluss": {
      "type": "array", "minItems": 1, "maxItems": 64,
      "items": { "type": "object", "additionalProperties": false,
        "required": ["strom_kennung","letzte_folgenummer","kettenhashwert"],
        "properties": {
          "strom_kennung":      { "$ref": "urn:atrium:schema:basis:1.0#/$defs/ulid" },
          "letzte_folgenummer": { "type": "integer", "minimum": 0 },
          "kettenhashwert":     { "$ref": "urn:atrium:schema:basis:1.0#/$defs/hashwert" },
          "siegel_vorhanden":   { "type": "boolean" } } }
    },
    "signatur": {
      "type": "object", "additionalProperties": false,
      "required": ["verfahren","schluessel_kennung","wert","zeitstempel",
                   "kanonisierung","nutzlast_hashwert"],
      "properties": {
        "verfahren":          { "const": "ed25519" },
        "schluessel_kennung": { "type": "string", "maxLength": 128 },
        "wert":               { "type": "string", "contentEncoding": "base64",
                                "minLength": 86, "maxLength": 128 },
        "zeitstempel":        { "type": "string", "contentEncoding": "base64",
                                "maxLength": 16384 },
        "kanonisierung":      { "const": "rfc8785" },
        "nutzlast_hashwert":  { "$ref": "urn:atrium:schema:basis:1.0#/$defs/hashwert" }
      }
    }
  }
}
```

`objekte` ist eine Liste von Blöcken und keine Abbildung von Typ auf Liste. Ein JSON-Objekt ist nach seiner Definition ungeordnet, und eine Reihenfolgenzusage über Objektschlüssel wäre nicht durchsetzbar; nur eine Liste trägt die Reihenfolge im Format selbst. `typreihenfolge` steht zusätzlich im Kopf, damit ein Leser die beabsichtigte Reihenfolge prüfen kann, ohne sie zu kennen; weicht die Reihenfolge der Blöcke von `typreihenfolge` ab, ist der Export fehlerhaft und wird abgelehnt.

Die Importreihenfolge ist so gewählt, dass ein Verweis in der Regel auf ein bereits gelesenes Objekt zeigt. Sie ist nicht zyklenfrei, und das wird nicht verschwiegen.

```
 1 mandant            9 dienstkonto        17 maildomaene
 2 ca                10 geraet             18 veroeffentlichung
 3 richtlinie        11 katalogeintrag     19 dnseintrag
 4 rolle             12 konnektorbindung   20 postfach
 5 netzzone          13 dienst             21 mailadresse
 6 knoten            14 speicherbereich    22 zuweisung
 7 person            15 geheimnis (Ref.)   23 rechteregel
 8 gruppe            16 domaene            24 wiederherstellungspunkt
   + gruppenmitgliedschaft nach gruppe
```

Zwei Zyklen bestehen unvermeidbar: `mandant.zwischen_ca` verweist auf eine CA, deren Feld `mandant` zurückverweist, und `knoten.exklusiv_fuer_mandant` verweist auf einen Mandanten, dessen Objekte auf Knoten liegen. Der Import löst das in zwei Durchgängen: im ersten werden alle Objekte ohne Prüfung der Fremdverweise gelesen und abgelegt, im zweiten wird die referenzielle Integrität über den gesamten Bestand geprüft. Ein hängender Verweis bricht den Import und wird mit Typ, Kennung und verweisendem Feld benannt; ein stillschweigendes Nullsetzen wäre ein unbemerkter Datenverlust.

Abgeleitete Artefakte sind im Export enthalten, werden beim Import aber verworfen und neu berechnet. Der Grund steht in [Kapitel 17](17-speicher-backup.md): ein zurückgeschriebenes abgeleitetes Artefakt wäre eine zweite Wahrheitsquelle neben seiner Quelle (INV-02, INV-09). Sie bleiben trotzdem im Export, weil sie den Zustand zum Exportzeitpunkt nachweisen und ein Vergleich nach dem Wiederaufbau die Abweichungen sichtbar macht.

Vier Regeln gelten außerhalb des Schemas. Die Summen in `objektzahl_je_typ` müssen mit den tatsächlichen Blocklängen übereinstimmen; eine Abweichung ist ein Formatfehler und keine tolerierbare Ungenauigkeit. Jeder Block validiert zusätzlich gegen das in `schema` genannte Entitätsschema, was eine zweite Validierungsstufe ist und nicht im Exportschema selbst steht. Der Export mit `schema_version` einer älteren Hauptversion wird über eine ausdrückliche Migration importiert, nie stillschweigend; das Kompatibilitätsfenster umfasst `MAJOR` und `MAJOR-1`. Die Signatur deckt die kanonische Form des gesamten Dokuments ohne das Feld `signatur.wert` ab, und der Vergleich des Signaturwerts erfolgt laufzeitkonstant, damit die Prüfdauer keinen Rückschluss auf die Übereinstimmungslänge erlaubt.

## A1.8 Prüfstufen

Die Schemata dieses Anhangs prüfen Struktur. Alles, was Bestand, Reihenfolge oder Vorzustand einbezieht, prüft eine spätere Stufe. Die Zuordnung ist Teil der Spezifikation und nicht der Implementierung überlassen.

| Stufe | Ort | Prüft | Beispiel |
|---|---|---|---|
| 1 Struktur | API-Fassade, Import | Typ, Pflichtfeld, Muster, Wertebereich, keine zusätzlichen Eigenschaften | `ttl` zwischen 60 und 86400 |
| 2 Feldklasse | API-Fassade | Schreibversuch auf Ableitungs- oder Beobachtungsfeld | `versorgungszustand` von außen gesetzt |
| 3 Bestand | Datenzugriffsschicht | Eindeutigkeit, referenzielle Integrität, Azyklizität, Mandantenprädikat | `kurzname` global eindeutig |
| 4 Übergang | Vorgangsprüfung | Zustandsübergang, Vergleich mit dem Vorzustand, Unveränderlichkeit | `anmelde_name` nach Erzeugung unveränderlich |
| 5 Bestandsinvariante | atrium-core | Aussagen über Mengen | Stimmzahl 1, 3 oder 5 (INV-05) |
| 6 Laufzeitabgleich | Reconciler, Vertragstest | Übereinstimmung mit dem Fremdsystem | `faehigkeiten` gegen `describe` |

Eine Prüfung, die auf einer höheren Stufe liegt als nötig, ist ein Fehler: sie läuft später, meldet unspezifischer und erreicht den Bediener erst, wenn ein Vorgang bereits begonnen hat.

## Anforderungen

- **R-A1-01** — Jedes Entitätsschema trägt `unevaluatedProperties: false` auf der äußersten Ebene und jedes eingebettete Objekt ohne `allOf` trägt `additionalProperties: false`. Prüfbar: statische Prüfung aller Schemadateien; ein Schema ohne diese Angabe bricht den Bau. Folgt aus INV-01.
- **R-A1-02** — Ein Objekt mit einer Eigenschaft, die in keinem Teilschema bewertet wird, wird von der API-Fassade mit Nennung des Eigenschaftsnamens abgelehnt. Prüfbar: Sendeversuch mit einer zusätzlichen Eigenschaft je Entitätstyp.
- **R-A1-03** — Jede `if`-Klausel in einem Schema dieses Anhangs führt `required` für die von ihr geprüften Felder. Prüfbar: statische Prüfung; eine `if`-Klausel ohne `required` bricht den Bau. Folgt aus der Eigenschaft, dass eine `if`-Klausel ohne diese Angabe bei fehlendem Feld zutrifft.
- **R-A1-04** — Die sechs in A1.1 genannten Formatbezeichner werden als Zusicherung ausgewertet. Prüfbar: Sendeversuch mit `art: A` und einem `wert`, der keine IPv4-Adresse ist; die Ablehnung erfolgt in Stufe 1.
- **R-A1-05** — Ein Konnektormanifest ohne vollständige Eigentumsangabe je deklariertem Feld wird beim Import abgelehnt. Prüfbar: Importversuch mit einem Feld ohne `eigentum`. Folgt aus INV-13.
- **R-A1-06** — Ein Konnektormanifest ohne Abschnitt `produktgrenze` mit je mindestens einem Eintrag in `atrium_besitzt` und `fremd_behaelt` wird abgelehnt. Prüfbar: Importversuch mit leerem Abschnitt. Folgt aus INV-30.
- **R-A1-07** — Ein Manifesteintrag unter `kostenwirksame_aktionen` mit `wirkung` ungleich `keine` und ohne `messgroesse` und `anzeige` wird abgelehnt. Prüfbar: Importversuch. Folgt aus INV-29.
- **R-A1-08** — Ein `zusatz_hosts`-Eintrag ohne Begründung von mindestens 16 Zeichen wird abgelehnt. Prüfbar: Importversuch mit leerer Begründung. Folgt aus INV-21.
- **R-A1-09** — Ein Katalogeintrag mit `laufzeit.selbstaktualisierung` ungleich `aus` oder `migration.rueckrollbar` ungleich `false` wird abgelehnt. Prüfbar: Importversuch je Feld. Folgt aus INV-02.
- **R-A1-10** — Eine Rechteregel mit einem Selektorterm außerhalb der Grammatik aus A1.5 wird beim Speichern abgelehnt und nennt den unzulässigen Teilausdruck. Prüfbar: Eingabetest mit Oder-Verknüpfung, Unterabfrage und Funktionsaufruf. Folgt aus K-18.
- **R-A1-11** — Jedes Auditereignis trägt `vorgaenger_hashwert` und `eigen_hashwert`; die Kettenprüfung nach A1.6 läuft bei jedem Start und bei jedem Export und meldet einen Bruch, ohne ihn zu reparieren. Prüfbar: Injektion eines veränderten Ereignisses. Folgt aus INV-23.
- **R-A1-12** — Ein Sollzustandsexport enthält 0 Geheimniswerte und 0 private Schlüssel und führt jedes nicht enthaltene Gut in `nicht_enthalten` mit Anzahl auf. Prüfbar: Ausgabeprüfung des Exports gegen Geheimnismuster und Vollständigkeitsprüfung der Liste. Folgt aus INV-20.
- **R-A1-13** — Ein Export, dessen Blockreihenfolge von `typreihenfolge` abweicht oder dessen `objektzahl_je_typ` nicht den Blocklängen entspricht, wird abgelehnt. Prüfbar: Importversuch mit vertauschten Blöcken und mit falscher Zahl.
- **R-A1-14** — Ein hängender Verweis im Export bricht den Import und wird mit Typ, Kennung und verweisendem Feld benannt; kein Verweis wird stillschweigend auf null gesetzt. Prüfbar: Importversuch mit entferntem Zielobjekt. Folgt aus INV-11.
- **R-A1-15** — Abgeleitete Artefakte aus einem Export werden beim Import verworfen und neu berechnet. Prüfbar: Import eines Exports mit verfälschtem abgeleitetem DNS-Eintrag; der Bestand nach Konvergenz enthält den berechneten und nicht den importierten Wert. Folgt aus INV-02 und INV-09.
- **R-A1-16** — Der Vergleich des Signaturwerts eines Exports, eines Manifests und eines Katalogeintrags erfolgt laufzeitkonstant. Prüfbar: Quelltextprüfung auf den verwendeten Vergleichsaufruf; ein früh abbrechender Vergleich bricht den Bau.

## Akzeptanzkriterien

| Kriterium | Anforderung | Nachweisverfahren |
|---|---|---|
| Alle Schemadateien tragen die geforderten Abschlussangaben; 0 Ausnahmen | R-A1-01 | statische Prüfung im Bau |
| Je Entitätstyp wird ein Objekt mit einer zusätzlichen Eigenschaft abgelehnt, und die Meldung nennt den Eigenschaftsnamen | R-A1-02 | erzeugter Testfallsatz über alle Entitätstypen |
| 0 `if`-Klauseln ohne zugehöriges `required` | R-A1-03 | statische Prüfung im Bau |
| `art: A` mit nicht-IPv4-Wert wird in Stufe 1 abgelehnt; `art: AAAA` mit IPv4-Wert ebenso | R-A1-04 | Eingabetest gegen die API-Fassade |
| Manifest mit einem Feld ohne `eigentum` wird abgelehnt; die Meldung nennt Objekt und Feld | R-A1-05 | Importtest |
| Manifest mit leerem `produktgrenze`-Abschnitt wird abgelehnt | R-A1-06 | Importtest |
| Manifest mit `wirkung: lizenz` ohne `messgroesse` wird abgelehnt | R-A1-07 | Importtest |
| Manifest mit `zusatz_hosts`-Eintrag ohne Begründung wird abgelehnt | R-A1-08 | Importtest |
| Katalogeintrag mit `selbstaktualisierung: ein` wird abgelehnt; Katalogeintrag mit `rueckrollbar: true` wird abgelehnt | R-A1-09 | Importtest je Feld |
| Regel mit `oder`-Verknüpfung wird abgelehnt und nennt den Teilausdruck | R-A1-10 | Eingabetest |
| Ein verändertes Auditereignis führt bei Start und Export zu einer Kettenbruchmeldung; 0 automatische Reparaturen | R-A1-11 | Injektionstest |
| Der Export enthält 0 Treffer gegen die Geheimnismusterliste; `nicht_enthalten` führt alle sieben Gegenstände mit Anzahl | R-A1-12 | Ausgabeprüfung nach jedem Export |
| Export mit vertauschten Blöcken wird abgelehnt; Export mit falscher Objektzahl wird abgelehnt | R-A1-13 | Importtest |
| Import mit entferntem Zielobjekt bricht ab und nennt Typ, Kennung und Feld | R-A1-14 | Importtest |
| Nach Import eines Exports mit verfälschtem abgeleitetem Eintrag entspricht der Bestand dem berechneten Wert | R-A1-15 | Wiederherstellungsübung nach K-24 |
| Der Signaturvergleich verwendet den laufzeitkonstanten Aufruf an allen drei Stellen | R-A1-16 | Quelltextprüfung im Bau |

## Offene Punkte

1. **Entwurfsfassung von JSON-Schema und Validatorwahl.** Dieser Anhang setzt `unevaluatedProperties`, `dependentRequired` und Formatzusicherung voraus. Welche Entwurfsfassung verbindlich ist und welcher Validator sie in Rust vollständig und mit ausreichender Geschwindigkeit für die Fassadenprüfung erbringt, ist nicht entschieden. Die Entscheidung wirkt auf jedes Schema dieses Anhangs zurück, weil ein Validator ohne `unevaluatedProperties` die Rumpfeinbindung nicht trägt und dann jedes Entitätsschema den Rumpf ausschreiben müsste.

2. **Widerspruch zwischen additiver Schemaerweiterung und verbotenen Zusatzfeldern.** KANON.md, Abschnitt 3 erklärt eine MINOR-Erhöhung für rein additiv und lässt ältere Kontrollebenen weiterlesen; gleichzeitig verbietet dieser Anhang unbewertete Eigenschaften, und [Kapitel 07](07-objektmodell.md) führt das Feld `zusatz` für Felder einer neueren MINOR-Version. Ungeklärt ist, wer neue Felder in `zusatz` verschiebt: ein neuerer Erzeuger schreibt sie auf oberster Ebene, und ein älterer Leser lehnt das Objekt dann ab, statt es zu lesen. Entweder erzeugt die neuere Fassung beim Schreiben an ältere Empfänger eine abgesenkte Darstellung, oder die Abschlussregel wird für Objekte aus einer bekannten höheren MINOR-Version gelockert; beides hat Kosten, und keines ist entschieden.

3. **Registrierungsverzeichnis der Richtliniengegenstände.** `wert` ist untypisiert, und die Typprüfung hängt an einem Verzeichnis, das `gegenstand` auf ein Teilschema abbildet. Offen sind Eigentümerschaft, Versionierung und Auslieferung dieses Verzeichnisses sowie die Frage, ob ein Mandant eigene Gegenstände eintragen darf. Ohne Antwort ist entweder die Erweiterbarkeit oder die Prüftiefe eingeschränkt, und die Sperre einer Handlung wegen eines fehlenden Verzeichniseintrags trifft den Bediener an einer Stelle, an der er die Ursache nicht sieht.

4. **Abdeckung der Eigentumsangabe gegenüber dem realen Feldsatz.** Das Manifestschema erzwingt eine Eigentumsangabe je deklariertem Feld, nicht die Deklaration jedes im Fremdsystem existierenden Feldes. Ein Feld, das das Manifest nicht kennt, hat damit kein deklariertes Eigentum, und INV-13 ist nur für den deklarierten Ausschnitt erfüllt. Offen ist, ob der Vertragstest eine Vollständigkeitsprüfung gegen eine erhobene Feldliste der eingesetzten Fremdfassung erzwingt, wer diese Liste je Fassung pflegt und wie mit einem Fremdsystem umgegangen wird, das Felder zur Laufzeit hinzufügen lässt.

5. **Größenbeitrag eingebetteter Binärdaten zum Export.** `der_kodierung` und die Zeitstempeltoken sind base64-kodierte Binärdaten. Rechnung mit den Annahmen aus K-12: 500 Personen, 800 Geräte und 150 Dienste ergeben in der Größenordnung 1.450 Endzertifikate; bei angenommen 1,2 kB je Zertifikat und Faktor 1,34 durch base64 sind das 1.450 × 1,2 kB × 1,34 = 2,33 MB, also rund 5 % des Zielwerts von 50 MB. Das ist tragbar, aber die Rechnung setzt eine Obergrenze je Zertifikat voraus, die dieser Anhang mit 16 kB großzügiger zieht; im ungünstigsten Fall wären es 1.450 × 16 kB = 23,2 MB und damit 46 % des Zielwerts. Offen ist, ob die Kodierung im Export durch einen Verweis auf einen getrennten Zertifikatsblock ersetzt wird. Das ist ein Modell, keine Messung.

6. **Ordnung über Auditströme hinweg.** `strom_kennung` trennt je Verwaltungsknoten eine eigene Kette, und die Reihenfolge zweier Ereignisse aus verschiedenen Strömen ist nicht definiert. Für einen Nachweis, der die Abfolge zweier Handlungen auf verschiedenen Knoten belegen soll, reicht der Zeitstempel bei einer zugelassenen Uhrenabweichung von 500 ms (K-30) nicht aus. Offen ist, ob ein knotenübergreifender Anker eingeführt wird — etwa ein periodisches Siegel über alle Ströme mit gemeinsamem Zeitstempel — und welche zusätzliche Schreiblast das erzeugt.

7. **Prüfung der Exportgröße gegen die Blockstruktur.** Der Import validiert jeden Eintrag gegen sein Entitätsschema, was bei 15.000 Objekten (K-12) 15.000 Validierungsläufe bedeutet. Der Zielwert für den Import aus [Kapitel 08](08-kontrollebene.md) liegt bei 5 Minuten, also 300 s, was 20 ms je Objekt erlaubt; ob ein Schemavalidator mit dieser Schemaverschachtelung diesen Wert hält, ist nicht belegt und muss gemessen werden, bevor der Wert als Zusage geführt wird.
