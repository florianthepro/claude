# 09 Konnektoren: Integration fremder Systeme

## Der Konnektorvertrag

Der Vertrag aus KANON.md, Abschnitt 4.5 kennt genau fünf Operationen. Dieses Kapitel füllt sie mit Datenmodell, Fehlerverhalten, Fristen und Wiederholbarkeit aus und leitet daraus die Stufen, den Sandkasten, die Katalogführung und die Wartungslastrechnung ab.

| Fachliche Bezeichnung | Operation | Richtung | Nebenwirkung | Aufrufer | Ergebnisklasse |
|---|---|---|---|---|---|
| Fähigkeiten melden | `describe` | Kern → Konnektor | keine | atrium-node bei Einrichtung, Versionswechsel und Gesundheitsprobe | Fähigkeitsliste, Vertragsversion, Objekttypen |
| Beobachten | `observe` | Kern → Konnektor → Fremdsystem (lesend) | keine | Reconciler ereignisgetrieben und im Volllauf | datierte Beobachtung (INV-28) |
| Importieren | `observe` im Modus `bestand` | wie Beobachten, seitenweise über den gesamten Bestand | keine | Erstabgleich, Abweichungsprüfung | Seite plus Fortsetzungsmarke |
| Planen | `plan` | Kern → Konnektor → Fremdsystem (lesend) | keine (INV-08) | Wirkungsvorschau eines Vorgangs | Liste konkreter Wirkungen |
| Anwenden | `apply` | Kern → Konnektor → Fremdsystem (schreibend) | ja | Ausführung eines freigegebenen Vorgangs | Schrittergebnisse je Zielobjekt |
| Gesundheit | `healthcheck` | Kern → Konnektor | keine | periodisch und vor jedem Vorgang | erreichbar, Vertragsversion, Abweichungsbefund |

Importieren ist bewusst kein sechstes Verb. Ein zusätzliches Verb vergrößert die Vertragsfläche, die jeder Konnektor jeder Stufe implementieren und jeder Vertragstest abdecken muss, während der Bestandsabruf sich vollständig als Modus von `observe` beschreiben lässt: gleiche Leserechte, gleiche Fehlerklassen, gleiche Nebenwirkungsfreiheit, nur ein anderer Geltungsbereich und eine Fortsetzungsmarke. Die verworfene Alternative lautet, `import` als eigenes Verb zu führen; sie wird verworfen, weil sie KANON.md, Abschnitt 4.5 widerspricht und einen zweiten Lesepfad mit eigenem Rechtemodell erzeugt, der getrennt geprüft werden müsste.

### Datenmodell der Aufrufe

```
# Gemeinsamer Aufrufrumpf jeder der fünf Operationen.
# Serialisierung über gRPC auf einem Unix-Socket; kein Port, kein Netzzugang zum Kern.
Aufrufrumpf:
  korrelationskennung : ULID       # eine je Vorgang, in jedem Auditereignis und jeder Protokollzeile
  bindung             : ULID       # Konnektorbindung; bestimmt Endpunkt, Geheimnisreferenz, Positivliste
  mandant             : ULID       # Pflichtfeld (INV-19); der Prozess bedient genau diesen Mandanten
  vertrag_version     : Text       # "MAJOR.MINOR" des aufrufenden Kerns
  frist_ms            : Ganzzahl   # verbleibendes Zeitbudget; der Konnektor bricht selbst vorher ab
  beobachtungszeit    : Zeitpunkt  # UTC aus der geprüften Zeitquelle (INV-32)

observe:
  geltungsbereich : einzel | bestand
  objekte         : [ { typ, kennung, fremdkennung? } ]     # nur bei geltungsbereich = einzel
  fortsetzung     : Text?                                   # opake Marke der vorigen Seite
  seitengroesse   : Ganzzahl                                # aus dem Manifest, nicht vom Aufrufer frei

plan / apply:
  schritte : [ Schritt ]
  idempotenz_schluessel : ULID     # nur bei apply; abgeleitet aus (Vorgang, Bindung, Schritthash)

Schritt:
  objekt       : { typ, kennung, fremdkennung? }
  aktion       : anlegen | aendern | aktivieren | deaktivieren | entfernen
                 | verknuepfen | loesen | sicherung_vorbereiten | sicherung_abschliessen | sicherung_pruefen
  felder       : { Feldname -> Wert }   # ausschließlich Felder im Eigentum atrium oder erstanlage (INV-13)
  vorbedingung : { fremd_marke? , feld_hashwert? }   # optimistische Sperre gegen Fremdänderung

Ergebnis:
  gesamt            : erfolgreich | teilweise | fehlgeschlagen      # nie "fertig" bei Rest (INV-12)
  schritt_ergebnis  : [ { index, zustand, fremdkennung?, fehler? } ]
  zustand           : unveraendert | geaendert | angelegt | uebersprungen | unbekannt
  beobachtung       : { zeitpunkt, felder }                          # Istzustand nach der Wirkung
  naechster_versuch_nach_ms : Ganzzahl?                              # nur bei Klasse kontingent
  fehler            : { klasse, schluessel, fremdsignal, wiederholbar, kostenwirkung? }
```

Der Ergebniszustand `unveraendert` ist der Normalfall eines zweiten Anwendens und der einzige, der kein Auditereignis vom Typ "geändert" erzeugt (INV-07). Der Zustand `unbekannt` existiert, weil ein Abbruch zwischen dem Schreiben im Fremdsystem und dem Eintreffen der Antwort real ist; er ist kein Fehlerzustand, sondern eine Aussage, die eine nachfolgende Beobachtung erzwingt. Die Felder `fremdsignal` und `schluessel` sind getrennt: `schluessel` ist ein übersetzbarer Bezeichner für die Konsole, `fremdsignal` ist der Rohbefund und geht ausschließlich in das Betriebsprotokoll. Diese Trennung ist die Durchsetzung von INV-16 und INV-17 an der Außenkante: ein Fremdtext kann Feldnamen, Pfade, Kennungen oder Geheimnisfragmente enthalten und erreicht die Oberfläche deshalb nie.

### Fehlertaxonomie

| Klasse | Bedeutung | Wiederholung | Rückstaffelung | Zustand des Vorgangs | Handlung des Bedieners |
|---|---|---|---|---|---|
| `dauerhaft` | Das Fremdsystem wird diesen Aufruf nie annehmen: unbekannter Objekttyp, ungültige Endpunktadresse, Zertifikatskette nicht prüfbar | nein | keine | teilweise fehlgeschlagen mit benanntem Rest | Bindung oder Manifest korrigieren |
| `voruebergehend` | Zeitüberschreitung, Serverfehler, Verbindungsabbruch | ja | exponentiell, Basis 500 ms, Faktor 2, Höchstwert 60 s, Streuung 20 % | in Arbeit bis zur harten Obergrenze aus K-15 | keine |
| `rechte` | Die hinterlegte Kennung darf die Aktion nicht ausführen | nein, bis Geheimnis oder Fremdrolle geändert ist | keine | blockiert, mit Angabe des fehlenden Fremdrechts aus dem Manifest | Fremdrecht erteilen oder Geheimnis wechseln |
| `kontingent` | Ratengrenze, Lizenzgrenze oder Speicherkontingent erschöpft | ja, frühestens ab `naechster_versuch_nach_ms` | vom Fremdsystem vorgegeben, nie selbst gewählt | in Arbeit, bei Lizenzgrenze mit Kostenhinweis (INV-29) | gegebenenfalls Kontingent erhöhen |
| `schema` | Das Fremdsystem erwartet eine andere Struktur als das Manifest beschreibt | nein | keine | dauerhaft fehlgeschlagen | Manifestversion oder Vertragsversion wechseln |
| `konflikt` | Das Fremdobjekt wurde seit der letzten Beobachtung fremdseitig geändert; die Vorbedingung trifft nicht mehr zu | genau einmal nach erneutem `observe` | keine | zur Entscheidung vorgelegt | Feldeigentum bestätigen oder Fremdwert übernehmen |

Der Rückfall für ein nicht abgebildetes Fremdsignal ist `dauerhaft`, nicht `voruebergehend`. Die verworfene Alternative, unbekannte Signale als vorübergehend zu behandeln, erzeugt stille Endlosschleifen gegen eine Fremd-API, die nie antworten wird, und verbrennt Kontingent. Ein Konnektor, dessen Vertragstest ein bekanntes Fremdsignal ohne Abbildung stehen lässt, fällt durch; die Abbildungstabelle ist damit Pflichtinhalt des Manifests und nicht Kür.

### Zeitüberschreitungen

| Operation | Frist (Zielwert) | Herleitung |
|---|---|---|
| Kaltstart des Prozesses bis Bereitschaft | 300 ms | K-15: der Kaltstart ist im p50-Budget von 5 s enthalten |
| `describe` | 2.000 ms | Nur bei Einrichtung, Versionswechsel und Gesundheitsprobe; nicht im Vorgangspfad |
| `observe`, Einzelobjekt | 3.000 ms | K-15: je Zielsystem ≤ 3 s |
| `observe`, Bestandsseite | 10.000 ms | 200 Objekte je Seite; bei 5.000 Objekten 25 Seiten × 10 s = 250 s, unter der Import-Höchstdauer |
| `plan` | 1.500 ms | K-18 verlangt für die Wirkungsvorschau p95 ≤ 2 s bei bis zu 7 parallelen Bindungen; 1.500 ms lassen 500 ms für Sammlung und Darstellung |
| `apply`, je Schritt | 3.000 ms | wie Einzelbeobachtung |
| `apply`, Gesamtauftrag | 60.000 ms | Danach Rückgabe mit Fortsetzungsmarke statt Abbruch; die harte Obergrenze des Vorgangs bleibt 15 min (K-15) |
| `healthcheck` | 1.000 ms | Läuft alle 300 s über alle Bindungen; bei 3.000 Bindungen (K-12-Annahme) sind 3.000 s Arbeit auf verteilte Verwaltungsknoten zu legen |
| Leerlaufabschaltung | 600.000 ms | KANON.md, Abschnitt 4.5; ohne sie wäre K-20 nicht haltbar |

Der Wächter in atrium-node beendet einen Konnektorprozess beim 1,5-fachen der deklarierten Frist. Überschreitet ein `plan` sein Budget, zeigt die Wirkungsvorschau für diese Bindung ausdrücklich "Vorschau nicht rechtzeitig verfügbar" statt zu blockieren oder eine leere Liste zu zeigen; die Freigabe ist dann möglich, trägt aber den Vermerk, dass eine Bindung ungeprüft ist. Das ist eine bewusst sichtbare Schwäche: eine langsame Fremd-API kann die Vollständigkeit der Vorschau unterlaufen, und der Entwurf zieht daraus keine Blockade, sondern eine Kennzeichnung, weil eine Blockade den gesamten Vorgang von der langsamsten Fremd-API abhängig machen würde. Wird ein `apply` vom Wächter beendet, gilt der betroffene Schritt als `unbekannt`, und der Reconciler stellt sofort einen `observe`-Auftrag auf genau dieses Objekt in die Warteschlange.

### Wiederholbarkeit

| Konvergenzklasse | Bedeutung | Wiederholung | Beispiel |
|---|---|---|---|
| `konvergent` | Das Ergebnis hängt nur vom Zielzustand ab, nicht von der Zahl der Aufrufe | unbegrenzt, gefahrlos | Feld setzen, Mitgliedschaft setzen, Gültigkeit setzen |
| `konvergent_mit_pruefung` | Konvergent erst nach einer Eindeutigkeitsprüfung, weil das Fremdsystem Doppelanlagen zulässt | nur nach vorgeschaltetem `observe` mit dem im Manifest deklarierten Prädikat | Konto anlegen in einem System ohne eindeutigen Anmeldenamen |
| `nicht_konvergent` | Jeder Aufruf erzeugt eine zusätzliche Wirkung | nie automatisch; Wiederholung nur nach ausdrücklicher Bestätigung | Einladung versenden, Vorgang eröffnen |

Der Idempotenzschlüssel aus INV-07 ist kein Dedup-Vermerk im Konnektor. Der Prozess ist aktivierungsgesteuert und wird nach zehn Minuten Leerlauf beendet, kann also keinen Zustand über Aufrufe hinweg halten; ein Schlüsselverzeichnis im Konnektor wäre eine zweite Wahrheitsquelle außerhalb des Sollzustands und damit ein Verstoß gegen INV-02. Der Schlüssel wirkt an zwei Stellen: er wird an Fremdsysteme weitergereicht, die ein entsprechendes Anfragemerkmal anbieten, und er dient dem Kern zur Zuordnung eines verspäteten Ergebnisses zu einem bereits abgeschlossenen Schritt. Die eigentliche Wiederholbarkeit entsteht nicht aus dem Schlüssel, sondern aus der Semantik: `apply` ist als Lesen-Vergleichen-Angleichen spezifiziert, nicht als Befehlsfolge. Für `nicht_konvergent` deklarierte Aktionen trägt der Entwurf die Schwäche offen: sie sind bei unbekanntem Ausgang nicht automatisch heilbar und erzeugen eine Aufgabe für einen Menschen.

## Die drei Stufen

| Stufe | Träger | Produktwissen | Was eine Neuanlage erzeugt | Aufwand Neuanlage (Annahme) | Aufwand Bruchbehebung (Annahme) | Grundpflege je Jahr (Annahme) |
|---|---|---|---|---|---|---|
| **T0** | Generischer Treiber mit Standardprofil | keines | eine Konnektorbindung und ein dünnes Profilmanifest (Endpunkt, Authentisierung, Attributabbildung) | 0,25 Personentage | 0,25 Personentage | 0,02 Personentage |
| **T1** | Generischer Treiber mit vollständigem Manifest | vollständig als Daten | ein signiertes Manifest, kein Quelltext | 1,5 Personentage | 0,25 Personentage | 0,10 Personentage |
| **T2** | Eigener Prozess in Rust hinter demselben Vertrag | vollständig als Quelltext | ein Programm, ein reproduzierbarer Bau, eine Stückliste, ein Freigabeprozess | 10 Personentage | 1,5 Personentage | 0,30 Personentage |

T0 benutzt ausschließlich Standardprotokolle: SCIM nach RFC 7643 und RFC 7644 für Versorgung, OpenID Connect Core 1.0 für Anmeldung, LDAPv3 nach RFC 4511 für Verzeichnisabfrage und, wo das Fremdsystem schreibende Verzeichnisoperationen anbietet, für Versorgung. Der entscheidende Unterschied zu T1 ist nicht die Technik, sondern die Versionierungsverantwortung: bei T0 versioniert ein Standardisierungsgremium das Format, bei T1 versioniert der Hersteller des Fremdprodukts das Format. Daraus folgt die Annahme, dass eine brechende Produktveröffentlichung einen T0-Konnektor nur in einem Fünftel der Fälle trifft, nämlich dann, wenn das Produkt seine Konformität ändert und nicht nur seine Fassung.

### Einstufungskriterien

```
Einstufung eines neuen Fremdsystems (in dieser Reihenfolge, erster Treffer gewinnt):

1. Bietet das System SCIM 2.0 mit /Users, /Groups, Filterung und PATCH
   in einer Form, die Anlage, Änderung, Mitgliedschaft und Deaktivierung abdeckt?
   -> T0 (Profil scim2)

2. Braucht der Dienst überhaupt kein eigenes Kontoobjekt, weil er die Identität
   bei jeder Anmeldung aus dem Token nimmt und keine Rolle im Produkt vergibt?
   -> T0 (Profil oidc_ohne_versorgung)

3. Bietet das System schreibende LDAP-Operationen auf einem Schema, dessen
   Abbildung auf Person, Gruppe und Mitgliedschaft vollständig ist?
   -> T0 (Profil ldap_versorgung)

4. Ist die Schnittstelle HTTP mit JSON, ressourcenorientiert, mit stabilen
   Feldnamen, und lässt sich jede benötigte Wirkung als Folge von höchstens
   drei voneinander abhängigen Anfragen ausdrücken?
   -> T1

5. Alles Übrige: nicht-HTTP-Transport (SQL-Drahtprotokoll, produkteigene RPC,
   administrative Mailprotokolle), mehrstufige Zustandsprotokolle, produkteigene
   Signaturen, Bibliothekszwang, Seitenlauf ohne stabile Marke.
   -> T2

Zusatzregel: Eine Einstufung nach T2 ist nur mit schriftlicher Begründung gegen
diese Liste zulässig und wird im Katalog mit der Begründung veröffentlicht.
```

Die Reihenfolge ist keine Vorliebe, sondern eine Kostenordnung: c(T0) < c(T1) < c(T2) mit dem unten gerechneten Verhältnis von rund 1 : 5,6 : 32 je System und Jahr. Ein häufiger Grenzfall ist ein Produkt mit OIDC-Anmeldung, aber produkteigener Rollenvergabe; es ist T1 und nicht T0, weil die Rollenzuordnung Produktwissen verlangt. Dieser Grenzfall ist der Regelfall im quelloffenen Serverumfeld und bestimmt die unten angesetzte T0-Deckungsgradannahme.

## Manifestschema

```
# Konnektormanifest. Kanonisch serialisiert nach RFC 8785, signiert mit Ed25519 (RFC 8032).
# Am Rand gegen das JSON-Schema der Vertragsversion validiert; ein unbekanntes Feld führt zur
# Ablehnung, nicht zum Ignorieren: ein Manifest ist eine Sicherheitsgrenze, kein Konfigurationsdokument.
# Versionsangaben sind hier Platzhalter <N>, <M>; im Katalog stehen dort konkrete Werte.

identitaet:
  kennung:            "znuny"                 # ^[a-z][a-z0-9-]{0,62}$, im Katalog eindeutig
  produktname:        "Znuny"                 # Anzeigename, Unicode NFC
  herausgeber:        "urn:atrium:pflegestelle:<ulid>"   # Herausgeber des Manifests, nicht des Produkts
  manifest_version:   "<N>.<M>"               # eigene Reihe je Manifest
  stufe:              t1                      # t0 | t1 | t2
  zertifizierung:     A                       # A geprüft | B bestätigt | C beigetragen
  sicherheitskontakt: "<Postfach>"            # Pflicht ab Stufe B

vertrag_version:      "<N>.<M>"               # der Kern lädt nur <N> und <N-1>

faehigkeiten:                                  # Deklaration; muss mit describe zur Laufzeit übereinstimmen
  [ person_versorgen, gruppe_versorgen, mitgliedschaft_setzen,
    deaktivieren, bestand_lesen, anmeldung_oidc ]
nicht_unterstuetzt:                            # ausdrücklich, damit die Konsole es benennen kann
  loeschen:        "Das Produkt löscht keine Konten, weil Vorgänge dauerhaft auf sie verweisen."
  pseudonymisieren: "Keine dokumentierte Schnittstelle vorhanden."

endpunkte:
  erlaubte_schemata: [ https ]                 # kein http, kein file, kein unix
  basis:             { pflicht: true }         # wird aus der Konnektorbindung gefüllt
  gesundheit:        { pfad: "<lesender Pfad>", methode: GET, erwartet: 200 }
  ausgang:                                     # Vorlage der Positivliste (INV-21)
    hosts_aus:       [ basis ]                 # ausschließlich aus dem Bindungsendpunkt abgeleitet
    zusatz_hosts:    [ ]                       # jeder Eintrag erzwingt eine Begründung im Katalog
    ports:           [ 443 ]
    namensaufloesung: nur_ueber_kern           # der Prozess hat keinen eigenen Resolver

authentisierung:
  verfahren:         bearer_token              # bearer_token | mtls | basic_ueber_tls | oauth2_cc
  geheimnis_typ:     token
  vermittler:        pflicht                   # der Prozess sieht den Wert nie, siehe Sandkasten
  tls:               { mindestversion: "1.3", pruefung: vollstaendig }   # RFC 8446; schwächer nicht ausdrückbar
  benoetigte_fremdrechte:                      # kleinstmögliche Fremdrolle, in der Konsole sichtbar
    [ "Benutzerverwaltung lesen und schreiben", "Rollen- und Gruppenmitgliedschaft schreiben" ]
  ausgeschlossene_fremdrechte:
    [ "Vollzugriff auf Vorgangsdaten", "Systemkonfiguration" ]

ratengrenzen:
  anfragen_je_minute: 120                      # Annahme aus der Produktdokumentation, sonst konservativ
  gleichzeitig:       4
  rueckstaffelung:    { basis_ms: 500, faktor: 2, hoechstwert_ms: 60000, streuung: 0.2 }
  kontingentangabe:   "<Kopfzeilenname>"       # wenn das Fremdsystem Restkontingent meldet

fristen_ms:                                     # Obergrenzen; der Wächter beendet beim 1,5-fachen Wert
  { describe: 2000, observe_einzeln: 3000, observe_bestand_seite: 10000,
    plan: 1500, apply_schritt: 3000, apply_auftrag: 60000, healthcheck: 1000 }

objekte:
  agent:
    fremdtyp:  "<Fremdobjektname>"
    zuordnungsschluessel:                       # geordnet; Anzeigename ist als Schlüssel verboten
      [ rueckverweis, mail_primaer_gefaltet, anmeldename ]
    konvergenz: konvergent
    felder:
      # eigentum: atrium | fremd | erstanlage  -- Pflichtangabe je Feld (INV-13)
      login:        { quelle: person.anmelde_name,   eigentum: erstanlage,
                      muster: "^[a-z][a-z0-9._-]{0,63}$" }
      vorname:      { quelle: person.vorname,        eigentum: atrium }
      nachname:     { quelle: person.nachname,       eigentum: atrium }
      mail:         { quelle: person.mail_primaer,   eigentum: atrium }
      gueltigkeit:  { quelle: zuweisung.zustand,     eigentum: atrium,
                      abbildung: { wirksam: gueltig, entzogen: ungueltig } }
      sprache:      { quelle: person.sprache,        eigentum: erstanlage }
      signatur:     { eigentum: fremd }
      abwesenheit:  { eigentum: fremd }
      rueckverweis: { quelle: person.kennung,        eigentum: erstanlage, pflicht: true }
    aktionen:
      anlegen:      { kostenwirksam: false }
      aendern:      { kostenwirksam: false }
      deaktivieren: { kostenwirksam: false, ersetzt: loeschen }

kostenwirksame_aktionen:                        # INV-29; eine bekannte, nicht deklarierte Kostenwirkung
  - { aktion: "agent.anlegen", wirkung: keine } #      bricht den Vertragstest
  # Muster für ein lizenzpflichtiges Fremdsystem:
  # - { aktion: "postfach.anlegen", wirkung: lizenz, messgroesse: "1 Lizenz je Postfach",
  #     anzeige: "Erzeugt ein kostenpflichtiges Postfach beim Anbieter." }

produktgrenze:                                  # INV-30; ohne diesen Abschnitt keine Freigabe
  atrium_besitzt:   [ "Agent-, Kunden- und Rollenobjekte", "Mitgliedschaft und Gültigkeit",
                      "Erreichbarkeit, Zertifikat, Zugriffskreis", "Datenbank, Speicherbereich, Sicherung" ]
  fremd_behaelt:    [ "Warteschlangen und ihre Eigenschaften", "Textbausteine, Vorlagen, dynamische Felder",
                      "Servicezeiten, Kalender, Benachrichtigungsregeln" ]
  anzeige_nur_lesend: [ "Warteschlangen mit den ihnen zugeordneten Gruppen" ]

fehlerabbildung:                                # Fremdsignal -> Klasse; Rückfall dauerhaft, nie voruebergehend
  - { signal: "http:429",          klasse: kontingent, wartezeit_aus: kontingentangabe }
  - { signal: "http:401",          klasse: rechte }
  - { signal: "http:403",          klasse: rechte }
  - { signal: "http:409",          klasse: konflikt }
  - { signal: "http:422",          klasse: schema }
  - { signal: "http:5xx",          klasse: voruebergehend }
  - { signal: "transport:frist",   klasse: voruebergehend }
  - { signal: "transport:tls",     klasse: dauerhaft }
  rueckfall:       dauerhaft
  text_weitergabe: nein                         # Fremdtexte nur ins Betriebsprotokoll (INV-16, INV-17)

gesundheitsprobe:
  art:             lesend                       # schreibende Proben sind unzulässig
  pruefungen:      [ erreichbarkeit, zertifikatskette, vertragsversion_vergleich, faehigkeiten_vergleich ]
  intervall_s:     300
  abweichend_nach: 3                            # Fehlversuche in Folge bis Bindungszustand "abweichend"

import:
  seitengroesse:       200
  fortsetzungsmarke:   pflicht
  hoechstdauer_s:      300
  stabilitaetspruefung: doppelabruf             # Abweichung > 1 % zwischen zwei Abrufen bricht ab
```

Fünf Abschnitte sind Ablehnungsgründe beim Import und nicht optionale Angaben: eine unvollständige Eigentumsangabe verletzt INV-13, eine fehlende Deklaration einer bekannten Kostenwirkung verletzt INV-29, ein fehlender Abschnitt `produktgrenze` verletzt INV-30, ein `zusatz_hosts`-Eintrag ohne Begründung verletzt INV-21, und ein `tls`-Block mit abgeschwächter Prüfung ist im Schema nicht ausdrückbar. Die verworfene Alternative, fehlende Angaben mit Vorgaben zu füllen, erzeugt genau die stillen Vorbelegungen, die INV-15 verbietet.

## Sandkasten

| Mechanismus | Ausprägung | Verhinderte Wirkung | Prüfung |
|---|---|---|---|
| Systembenutzer | `atrium-conn-<mandant-kurz>-<konnektor>`, je Mandant und Bindung ein eigener | Zugriff auf Dateien, Sockets und Prozesse einer anderen Bindung | Prozesstabelle im Integrationstest: keine zwei Bindungen unter derselben Kennung |
| Netznamensraum | eigener Namensraum je Prozess, ohne Standardroute; Ausgangs-Positivliste aus der Konnektorbindung erzeugt (INV-21) | Zugriff auf ein anderes Fremdsystem, auf 8400 und auf das Knoten-Overlay | Zugriffsversuch auf eine nicht gelistete Adresse und auf 8400 scheitert (R-05-16) |
| Namensauflösung | kein eigener Resolver; Namen werden vom Kern vor dem Start aufgelöst und als Adresse in die Positivliste geschrieben | Umgehung der Positivliste über einen manipulierten DNS-Antwortsatz | Namensauflösung im Namensraum schlägt fehl |
| Dateisystemsicht | `ProtectSystem=strict`, `ProtectHome=yes`, `PrivateTmp`, `ProtectProc=invisible`, `PrivateDevices`; schreibbar sind ausschließlich ein Kratzverzeichnis und nichts sonst | Lesen von Schlüsselmaterial, Sollzustand, Auditstrom oder fremden Verzeichnissen | Dateisystemtest: Lesezugriff auf den Geheimnisspeicher scheitert |
| Socket | Aktivierung durch systemd, Socket wird als Dateideskriptor übergeben; der Prozess bindet keinen Pfad selbst | Erreichen einer fremden Bindung über deren Socketpfad | Pfadtest mit vertauschten Bindungen |
| Rechte | leere effektive Capability-Menge, `NoNewPrivileges`, `RestrictNamespaces`, `LockPersonality`, `MemoryDenyWriteExecute`, `SystemCallArchitectures=native` | Rechteausweitung, Einhängen, Nachladen ausführbaren Speichers | `CapEff` ist 0 (R-05-06) |
| seccomp | Positivliste, Rückfall `KILL_PROCESS`; ausgeschlossen bleiben unter anderem `ptrace`, `mount`, `pivot_root`, `kexec_load`, `bpf`, `userfaultfd`, `process_vm_readv/writev`, `perf_event_open`, `io_uring_setup` sowie die Gruppen Modul, Rohgerät, Neustart, Auslagerung und Uhr | Auslesen fremder Prozessspeicher, Manipulation der Knotenuhr (INV-32) | Syscall-Probe im Vertragstest |
| Speicher und Laufzeit | `MemoryHigh` 96 MB, `MemoryMax` 128 MB, `CPUQuota` 50 %, `TasksMax` 32, `RuntimeMaxSec` 900, `TimeoutStopSec` 10 | Verdrängung anderer Lasten, Dauerlauf entgegen K-20 | Lasttest mit 32 gleichzeitigen Prozessen gegen K-19 |
| Geheimnisse | nur kurzlebige, auftragsgebundene Referenz (INV-20); für HTTP-Bindungen injiziert ein Vermittler die Kopfzeile | Preisgabe des Dauergeheimnisses aus dem Prozessadressraum | Musterprüfung der Prozessausgabe und des Kernauszugs |

`MemoryDenyWriteExecute` ist ohne Verzicht anwendbar, weil alle mitgelieferten Konnektoren und der generische Treiber in Rust vorab übersetzt werden und keine Laufzeitübersetzung benötigen. Die Speichergrenze von 128 MB ist aus K-20 abgeleitet: 32 gleichzeitige Prozesse zu 128 MB sind rechnerisch 4 GB, was das Budget aus K-19 für einen Arbeitsknoten allein ausschöpfen würde; deshalb ist `MemoryHigh` mit 96 MB angesetzt, sodass der reguläre Arbeitspunkt bei 32 × 96 MB = 3,07 GB liegt und die harte Grenze nur einzelne Ausreißer abfängt. Das ist ein Modell, keine Messung; der tatsächliche Verbrauch eines Manifestlaufs mit 200 Objekten je Seite ist unbekannt und muss vor der Festlegung gemessen werden.

### Geheimnisse und der Vermittler

Der Konnektorprozess erhält eine Referenz, keinen Wert. Für Bindungen mit gegenseitigem TLS wird der private Schlüssel nie übergeben, sondern die Verbindung im Namensraum durch einen Vermittler aufgebaut, der den Schlüssel hält; der Schlüssel verlässt den Knoten nicht (INV-20). Für Bindungen mit Zeichenkettengeheimnis (Token, Kennwort) läuft der ausgehende Verkehr über einen bindungseigenen Vermittler im selben Namensraum, der die Berechtigungskopfzeile einsetzt; der Konnektor kennt das Geheimnis nicht und kann es folglich weder protokollieren noch ausleiten. Der Vermittler ist für alle T0- und T1-Bindungen über HTTP Pflicht, weil dort das Protokoll bekannt ist und die Einsetzstelle eindeutig.

Für T2-Konnektoren mit Nicht-HTTP-Protokollen ist dieser Weg nicht gangbar, weil die Einsetzstelle protokollspezifisch ist. Dort gilt der schwächere Weg: der Wert wird über einen getrennten Vermittlungssocket auftragsgebunden abgerufen, in gesperrtem Speicher gehalten, nach Gebrauch überschrieben, und jeder Abruf erzeugt ein Auditereignis. Der Entwurf benennt die verbleibende Lücke, statt sie zu schließen: sobald der Wert im Adressraum des Konnektors liegt, hat ein übernommener Prozess ihn. Die einzige wirksame Gegenmaßnahme ist die Kleinheit des Geheimnisses, also die im Manifest deklarierte kleinstmögliche Fremdrolle, und die im Bindungszustand sichtbare Wechselfrist.

### Was ein übernommener Konnektorprozess anrichten kann

| Wirkung | Möglich | Begründung |
|---|---|---|
| Daten im gebundenen Fremdsystem lesen und ändern | ja, im Umfang der hinterlegten Fremdrolle | Der Sandkasten begrenzt die Reichweite, nicht die Rechte des Geheimnisses |
| Falsche Beobachtungen melden | ja | Der Kern kann eine Beobachtung nicht gegen eine zweite Quelle prüfen |
| Ein zweites Fremdsystem erreichen | nein | Positivliste aus der Bindung, keine Standardroute, keine Namensauflösung (INV-21) |
| Die Kern-API über das Netz erreichen | nein | 8400 liegt außerhalb der Positivliste; der Vertragssocket ist eingehend, nicht ausgehend |
| Den Sollzustand ändern | nein | Kein Schreibweg am Reconciler vorbei (INV-03); der Konnektor ist Aufgerufener, nicht Aufrufer |
| Ein Geheimnis dauerhaft entwenden | bei HTTP-Bindungen nein, bei Nicht-HTTP-T2 ja | Vermittler gegen auftragsgebundenen Abruf |
| Rechte auf dem Knoten ausweiten | nein | Leere Capability-Menge, `NoNewPrivileges`, seccomp-Positivliste |
| Andere Lasten verdrängen | begrenzt | Speicher-, CPU- und Aufgabengrenzen je Prozess |

Die gefährlichste verbleibende Wirkung ist die falsche Beobachtung. Ein lügender Konnektor kann melden, dass hunderte Fremdkonten fehlen, und damit einen Angleich auslösen, der sie anlegt, oder melden, dass sie überzählig sind, und eine Deaktivierungswelle auslösen. Der Entwurf setzt dagegen eine Plausibilitätsschwelle: ein Abgleichlauf, der mehr als 5 % des zuletzt beobachteten Bestands einer Bindung deaktivieren oder entfernen würde, wird nicht automatisch ausgeführt, sondern als Vorgang zur Freigabe vorgelegt (Zielwert, nicht gemessen). Das verhindert die Welle, nicht die einzelne falsche Änderung, und der Entwurf behauptet nichts anderes.

## Versionierung, Vertragstests, Katalogstufen

| Änderung am Vertrag | Version | Folge für bestehende Konnektoren |
|---|---|---|
| neues optionales Feld im Aufruf oder Ergebnis | MINOR | keine; unbekannte Felder werden erhalten und unverändert zurückgegeben |
| neue Fähigkeit, neuer Aufzählungswert mit deklariertem Rückfall | MINOR | keine; der Rückfallwert ist der restriktivere |
| neue Pflichtangabe, geänderte Bedeutung, entferntes Feld, neue Operation | MAJOR | Konnektoren müssen bis zum Ende des Fensters nachziehen |
| Fenster | — | Der Kern lädt Vertragsversion N und N-1; ein Manifest mit N-2 wird abgewiesen und die Bindung geht in `ausgesetzt`, nicht in einen stillen Fehler |

### Vertragstests

| Test | Prüft | Invariante |
|---|---|---|
| Vorschauprobe | Bestandsabruf vor und nach `plan` ist feldgleich | INV-08 |
| Doppelanwendung | zweites `apply` mit demselben Schlüssel meldet `unveraendert` und erzeugt kein Änderungsereignis | INV-07 |
| Eigentumsprobe | ein fremdseitig geändertes Feld der Klasse `fremd` bleibt nach dem Abgleich unverändert | INV-13 |
| Fehlerabbildung | jedes im Testbestand erzeugte Fremdsignal landet in genau einer Klasse | INV-12 |
| Fristprobe | jede Operation hält ihre Frist; der Wächter greift beim 1,5-fachen Wert | K-15, K-18 |
| Geheimnisprobe | Ausgabe, Protokoll und Kernauszug enthalten kein Geheimnismuster | INV-20 |
| Ausgangsprobe | Zugriff auf eine nicht gelistete Adresse und auf 8400 scheitert | INV-21 |
| Kostenprobe | jede als kostenwirksam bekannte Aktion ist deklariert und erscheint in der Vorschau | INV-29 |
| Grenzprobe | die Produktgrenzdeklaration deckt jedes Objekt ab, das die Fremdoberfläche anbietet | INV-30 |
| Teilfehlerprobe | eine Fehlerinjektion in einen von mehreren Schritten ergibt `teilweise` mit benanntem Rest | INV-12 |

| Stufe | Voraussetzung | Zusage | Anzeige an jeder Bindung |
|---|---|---|---|
| **A geprüft** | Vertragstests grün gegen eine echte Instanz in jedem Freigabestand; benannte Pflegestelle; Sicherheitskontakt; erprobter Rückzugsweg | Sicherheitsmeldung beantwortet in ≤ 72 h (Zielwert, angelehnt an K-23) | "geprüft, gepflegt von <Stelle>" |
| **B bestätigt** | Vertragstests grün gegen eine Nachbildung; benannte Pflegestelle; Sicherheitskontakt | Sicherheitsmeldung beantwortet in ≤ 14 Tagen (Zielwert) | "bestätigt, ohne Laborinstanz" |
| **C beigetragen** | signiert, Vertragstests laufen; keine fortdauernde Pflegezusage | keine | "ohne zugesicherte Pflege" |

Nicht signierte Manifeste sind nicht ladbar; es gibt keine Stufe unterhalb von C. Jedes Manifest trägt eine eigene Signatur nach RFC 8032 über die kanonische Serialisierung nach RFC 8785, unabhängig von der Signatur des Katalogbands, und jede Freigabe wird in das anhängbare, hashverkettete Transparenzprotokoll mit Zeitstempel nach RFC 3161 eingetragen. Die Prüfung erfolgt beim Import in den Katalog und erneut bei jedem Start des generischen Treibers; die verworfene Alternative, nur beim Import zu prüfen, ließe eine nachträgliche Änderung der abgelegten Datei unbemerkt.

### Rückzug eines Konnektors

| Stufe | Auslöser | `observe` | `plan` | `apply` | Bestehende Fremdkonten |
|---|---|---|---|---|---|
| `abgekuendigt` | geplantes Ende der Pflege, Zielwert 180 Tage Vorlauf | ja | ja | ja | unberührt |
| `zurueckgezogen` | Pflege endet, keine Übernahme | ja | ja | nein | unberührt, Bindung `ausgesetzt` |
| `gesperrt` | Sicherheitsvorfall, Signatur widerrufen | nein | nein | nein | unberührt, Bindung sofort `ausgesetzt`, Grund in der Konsole |

Kein Rückzugsschritt löscht ein Fremdkonto und kein Rückzugsschritt deaktiviert eines. Der Grund ist INV-11 und die praktische Erwägung, dass ein Sicherheitsvorfall im Konnektor kein Anlass ist, den Betrieb des Kunden im Fremdsystem zu beenden. Im Zustand `zurueckgezogen` bleibt `observe` erlaubt, damit die Konsole weiter einen datierten Istzustand zeigen kann (INV-28) statt stumm zu werden; im Zustand `gesperrt` ist auch das untersagt, weil ein gesperrter Konnektor als möglicherweise bösartig gilt und seine Beobachtungen wertlos sind.

## Die Skalierungsrechnung

Die Behauptung "viele hundert eingebundene Lösungen" ist nur dann keine Werbeaussage, wenn die daraus folgende jährliche Pflegelast benannt und tragbar ist. K-22 gibt den Zielwert vor; hier wird er nach Stufen aufgelöst und daraus die erforderliche Verteilung abgeleitet.

### Formel und Annahmen

```
E  =  E_bruch + E_grund + E_neu                             [Personentage je Jahr]

E_bruch = Summe über i aus {T0,T1,T2} von ( N · x_i · F_i · h_i )
E_grund = Summe über i von ( N · x_i · g_i )
E_neu   = Summe über i von ( N_neu · x_i · a_i )

zusammengefasst je System und Jahr:   c_i = F_i · h_i + g_i + (N_neu / N) · a_i
                                      E   = N · Summe über i von ( x_i · c_i )
```

| Symbol | Bedeutung | Wert | Herkunft |
|---|---|---|---|
| N | integrierte Fremdsysteme | 1.000 | Annahme, Skalenziel |
| N_neu | Neuanlagen je Jahr | 200 | Annahme aus K-22 |
| v | API-relevante Veröffentlichungen je Fremdsystem und Jahr | 1,5 | Annahme aus K-22 |
| b | Anteil brechender Veröffentlichungen | 0,20 | Annahme aus K-22 |
| F_T1, F_T2 | Brüche je System und Jahr | v · b = 0,30 | Rechenergebnis |
| F_T0 | Brüche je T0-System und Jahr | 0,30 · 0,20 = 0,06 | Annahme: nur ein Fünftel der brechenden Veröffentlichungen betrifft die Standardkonformität, weil das Drahtformat fremdversioniert ist |
| h_T0, h_T1 | Bruchbehebung im Manifest | 0,25 Personentage | Annahme aus K-22 |
| h_T2 | Bruchbehebung im Quelltext | 1,5 Personentage | Annahme aus K-22 |
| g_T0, g_T1, g_T2 | Grundpflege je System und Jahr | 0,02 / 0,10 / 0,30 | Annahme; T2 trägt zusätzlich Abhängigkeitspflege, Bau und Verfolgung von Sicherheitsmeldungen |
| a_T0, a_T1, a_T2 | Neuanlage | 0,25 / 1,5 / 10 Personentage | Annahme |
| Arbeitstage je Person und Jahr | produktiv | 200 | Annahme aus K-22 |

### Einheitskosten je Stufe

```
c_T0 = 0,06 · 0,25 + 0,02 + 0,2 · 0,25 = 0,015 + 0,02 + 0,05 = 0,085  PT je System und Jahr
c_T1 = 0,30 · 0,25 + 0,10 + 0,2 · 1,5  = 0,075 + 0,10 + 0,30 = 0,475  PT je System und Jahr
c_T2 = 0,30 · 1,50 + 0,30 + 0,2 · 10   = 0,450 + 0,30 + 2,00 = 2,750  PT je System und Jahr

Verhältnis  c_T0 : c_T1 : c_T2  =  1 : 5,6 : 32,4
```

### Gegenrechnung: 1.000 handgeschriebene Konnektoren

```
E = 1.000 · 2,750 = 2.750 PT/a = 2.750 / 200 = 13,75 Vollzeitäquivalente
```

K-22 rechnet denselben Fall mit einer höheren Grundpflege von 0,5 Personentagen und kommt auf 2.950 PT/a, also 14,75 Vollzeitäquivalente. Die Abweichung von 200 PT/a liegt innerhalb der Unsicherheit der Grundpflegeannahme und ändert die Aussage nicht: eine Organisation, die 1.000 Fremdsysteme handgeschrieben pflegt, bindet dauerhaft rund vierzehn Personen allein für Nachpflege, ohne eine einzige Produkteigenschaft zu verbessern. Diese Zahl ist die eigentliche Begründung des Stufenmodells, nicht ein Eleganzargument.

### Wirkung einer naheliegenden Verteilung

```
Verteilung x = (0,25 / 0,65 / 0,10), also 250 T0, 650 T1, 100 T2:

E = 250 · 0,085 + 650 · 0,475 + 100 · 2,750
  =  21,25     +  308,75      +  275,00
  = 605,0 PT/a = 3,03 Vollzeitäquivalente
```

Diese Verteilung erfüllt die in K-22 genannte Schwelle "≥ 90 % manifestbasiert" (T0 und T1 zusammen sind 90 %), verfehlt aber den dort genannten Gesamtzielwert von 512,5 PT/a um 18 %. Das ist ein Befund des verfeinerten Modells und keine Korrektur von K-22: K-22 unterscheidet T0 nicht von T1 und setzt deshalb andere Einheitskosten an. Die Folgerung ist, dass die 90-Prozent-Schwelle als alleiniges Steuerungsmaß nicht ausreicht.

### Die erforderliche Verteilung

Gesucht ist der höchste zulässige T2-Anteil x₂ bei gegebenem T0-Deckungsgrad x₀ unter der Bedingung E ≤ 512,5 PT/a.

```
1.000 · ( 0,085·x₀ + 0,475·(1 − x₀ − x₂) + 2,750·x₂ )  ≤  512,5

Beispiel x₀ = 0,25:
  0,085·0,25 + 0,475·(0,75 − x₂) + 2,750·x₂  ≤  0,5125
  0,02125 + 0,35625 + 2,275·x₂               ≤  0,5125
  2,275·x₂                                    ≤  0,135
  x₂                                          ≤  0,0593   =  5,9 %
```

| T0-Deckungsgrad x₀ | zulässiger T2-Anteil x₂ | T2-Konnektoren bei N = 1.000 | verbleibender T1-Anteil |
|---|---|---|---|
| 0,15 | 4,2 % | 42 | 80,8 % |
| 0,20 | 5,1 % | 51 | 74,9 % |
| 0,25 | 5,9 % | 59 | 69,1 % |
| 0,30 | 6,8 % | 68 | 63,2 % |
| 0,35 | 7,6 % | 76 | 57,4 % |
| 0,40 | 8,5 % | 85 | 51,5 % |

Jeder zusätzliche Prozentpunkt T0-Deckung erlaubt rund 0,17 Prozentpunkte mehr T2, also etwa 1,7 zusätzliche handgeschriebene Konnektoren je 1.000 Systemen. Der dominierende Hebel ist nicht T0, sondern die Obergrenze für T2: ein einziger zusätzlicher T2-Konnektor kostet so viel wie 5,8 T1-Konnektoren. Das ist ein Modell, keine Messung.

### Empfindlichkeit

| Geänderte Annahme | Wirkung auf x₂ bei x₀ = 0,25 |
|---|---|
| h_T1 = 0,5 PT statt 0,25 PT (Manifestbruch doppelt so teuer) | c_T1 = 0,55; x₂ ≤ 3,6 %, also 36 statt 59 Konnektoren |
| a_T1 = 2,5 PT statt 1,5 PT (Manifesterstellung teurer) | c_T1 = 0,675; E = 527,5 PT/a bereits ohne jeden T2-Konnektor; der Zielwert ist mit keiner Verteilung erreichbar, erst bei x₀ = 0,30 bleiben 7 T2-Konnektoren |
| N_neu = 100 statt 200 je Jahr | E sinkt bei (0,25/0,65/0,10) auf 250 · 0,06 + 650 · 0,325 + 100 · 1,75 = 401,25 PT/a; der Zielwert wird ohne Umverteilung erreicht |

Das Ergebnis hängt an zwei ungemessenen Zahlen, h_T1 und a_T1. Solange beide nicht an den ersten fünfzig Manifesten gemessen sind, ist die Schwelle von 6 % eine Planungsgröße und keine belastbare Obergrenze.

### Deckungsgrad von SCIM, OIDC und LDAP: Annahme mit Begründung

**Annahme: 25 % der quelloffenen Serverprodukte sind ohne Produktwissen vollständig versorgbar, mit einer Bandbreite von 15 % bis 35 %.** Die Zusammensetzung dieser Annahme:

| Anteil (Annahme) | Gruppe | Begründung |
|---|---|---|
| rund 8 % | SCIM-fähig im Sinne von Anlage, Änderung, Mitgliedschaft und Deaktivierung | SCIM wird überwiegend durch die Anforderung kommerzieller Identitätsanbieter getrieben; diese Anforderung trifft Anbieter gehosteter Dienste, nicht Betreiber selbstgehosteter quelloffener Software. Für ein Produkt ohne diesen Marktdruck ist SCIM Aufwand ohne Nachfrage. |
| rund 12 % | schreibende Verzeichnisversorgung mit vollständiger Abbildung von Person, Gruppe und Mitgliedschaft | Verzeichnisanbindung ist verbreitet, aber ganz überwiegend lesend: das Produkt liest Nutzer und legt bei der ersten Anmeldung ein eigenes Schattenkonto an. Das deckt die Anmeldung ab, aber weder die Rollenvergabe noch die Deaktivierung, und ist deshalb kein T0. |
| rund 5 % | kein eigenes Kontoobjekt nötig | Dienste, die die Identität bei jeder Anmeldung aus dem Token nehmen und keine produkteigene Rolle vergeben. |
| Rest | OIDC-Anmeldung vorhanden, Rollenvergabe produkteigen | Das ist der Regelfall und die eigentliche Ursache der T1-Last: die Anmeldung ist gelöst, die Versorgung nicht. |

Diese Zahlen sind Annahmen und keine Erhebung. Die Begründung ist ein Anreizargument, kein Messergebnis, und sie ist überprüfbar: eine Auszählung der Schnittstellendokumentation der ersten zweihundert Katalogeinträge liefert einen belastbaren Wert und muss vor der Festlegung der Katalogstrategie durchgeführt werden.

### Folgerungen für die Katalogstrategie

1. Der T2-Anteil ist ein bewirtschaftetes Kontingent, kein Ergebnis. Der Katalog veröffentlicht die T2-Zahl, und jede Neuaufnahme verbraucht sichtbar davon.
2. Jede Investition in den generischen Treiber, die einen Konnektor von T2 nach T1 verschiebt, spart 2,275 Personentage je Jahr und System. Ab etwa neun verschobenen Konnektoren trägt sich eine Treibererweiterung von zwanzig Personentagen innerhalb eines Jahres.
3. Ein T2-Konnektor, der viele Katalogeinträge bedient, ist wirtschaftlich anders zu bewerten als einer für ein einzelnes Produkt. Der Datenbankkonnektor unten ist der Musterfall: eine Implementierung, die jeder PostgreSQL-gestützte Katalogeintrag mitbenutzt.
4. Ein Beitrag an ein quelloffenes Fremdprodukt, der es von T1 nach T0 hebt, spart 0,39 Personentage je Jahr. Das trägt einen Beitragsaufwand von mehreren Personentagen nur bei Produkten mit vielen Installationen über Kunden hinweg; als allgemeine Strategie ist es nicht finanzierbar, und der Entwurf behauptet das nicht.
5. Die Zahl 1.000 ist kein Produktziel. Ein veröffentlichter Zähler erzeugt den Anreiz, Konnektoren aufzunehmen, deren Pflege niemand trägt; gemessen wird deshalb der Anteil der Bindungen im Zustand `aktiv` und nicht die Katalogbreite.

## Katalog-Governance

| Aufnahmekriterium | Prüfung | Ablehnungsgrund bei Verstoß |
|---|---|---|
| Signatur und Herkunftsnachweis vorhanden, Transparenzprotokolleintrag erzeugt | automatisch | nicht ladbar |
| Vollständige Eigentumsangabe je Feld | Schemaprüfung | INV-13 |
| Produktgrenzdeklaration deckt alle Objekte der Fremdoberfläche ab | Prüfung gegen die Objektliste aus `describe` | INV-30 |
| Kostenwirksame Aktionen deklariert | Abgleich mit der Liste bekannter Kostenwirkungen | INV-29 |
| Ratengrenzen und Fristen angegeben | Schemaprüfung | K-15, K-18 |
| Gesundheitsprobe lesend und ohne Nebenwirkung | Vertragstest | INV-08 |
| Kleinstmögliche Fremdrolle benannt, ausgeschlossene Rechte benannt | Begutachtung | Sicherheitsvorgabe |
| Rückzugsverhalten beschrieben | Begutachtung | INV-11 |
| Rezept für eine Testinstanz beigelegt | Bau | Vertragstests nicht ausführbar |
| Einstufung gegen den Entscheidungsbaum begründet, bei T2 schriftlich | Begutachtung | Kontingentregel |

**Pflegeverantwortung.** Jeder Katalogeintrag nennt genau eine Pflegestelle mit Sicherheitskontakt. Die Zusage ist an die Zertifizierungsstufe gebunden und wird an jeder Bindung angezeigt, nicht in einem Bericht; eine Stufe ohne Zusage heißt in der Konsole "ohne zugesicherte Pflege" und nicht "Gemeinschaftskonnektor".

**Gemeinschaftsbeiträge.** Ein T1-Beitrag ist Daten und kein Quelltext. Der generische Treiber führt aus einem Manifest keinen Ausdruck aus, der etwas aufrufen kann: es gibt keinen Ausdrucksauswerter, keine Vorlagensprache mit Seiteneffekten, keine eingebettete Abfragesprache und keine Möglichkeit, eine Zeichenkette aus dem Manifest als Befehl an ein Fremdsystem zu senden, die nicht aus dem festen Operationskatalog stammt. Daraus folgt der Prüfaufwand: ein T1-Beitrag ist in Stunden begutachtbar, ein T2-Beitrag verlangt eine vollständige Quelltextprüfung und einen reproduzierbaren Bau und kostet Tage. Dieser Unterschied ist derselbe Hebel wie in der Kostenrechnung und ist der zweite, sicherheitsseitige Grund für das Verteilungsziel.

**Abkündigung.** Vorlauf Zielwert 180 Tage. Die Konsole zeigt das Enddatum an jeder betroffenen Bindung, neue Bindungen sind ab Ankündigung nicht mehr anlegbar, bestehende laufen bis zum Ende unverändert.

**Verwaisung.** Ein Konnektor gilt als verwaist, wenn die Pflegestelle auf einen fehlgeschlagenen Vertragstest innerhalb des ihrer Stufe zugeordneten Zeitfensters nicht antwortet oder die Pflege niederlegt.

| Schritt | Frist (Zielwert) | Wirkung |
|---|---|---|
| Kennzeichnung "ohne Pflege" an jeder Bindung | sofort | `apply` weiter möglich; Bediener sieht den Zustand |
| Übernahmeaufruf im Katalog | 90 Tage | keine |
| Zustand `zurueckgezogen` bei weiterhin fehlschlagendem Vertragstest | nach 90 Tagen | `apply` gesperrt, `observe` erlaubt, Fremdkonten unberührt |
| Entfernung aus dem Katalog | nach 12 Monaten | bestehende Bindungen bleiben `ausgesetzt` und müssen durch einen Vorgang entfernt oder ersetzt werden |

Ein Kunde kann ein verwaistes Manifest als eigenes Manifest weiterführen: er signiert es mit einem eigenen Schlüssel, und die Konsole kennzeichnet die Bindung dauerhaft als "eigene Pflege, ohne Zusicherung". Das ist eine bewusst in Kauf genommene Schwäche, weil damit ein zweiter Vertrauensanker neben dem Katalogschlüssel entsteht und die Güte des kundeneigenen Schlüsselumgangs unbekannt ist. Die verworfene Alternative wäre, den Weg zu versperren; sie wird verworfen, weil sie einen Kunden bei einer verwaisten Integration ohne Ausweg lässt und ihn zur Handpflege am System vorbei drängt, die INV-02 gerade verhindern soll.

## Durchgerechnetes Beispiel: Znuny

Einstufung: **T1**. Das Produkt bietet eine administrative Schnittstelle über HTTP, aber kein SCIM und keine schreibende Verzeichnisversorgung; die Rollen- und Gruppenzuordnung ist produkteigen. Der Konnektor ist damit der Musterfall der in der Rechnung dominierenden Gruppe.

### Objektabbildung

| Atrium-Objekt | Znuny-Objekt | Auslöser | Richtung |
|---|---|---|---|
| Person mit Zuweisung `ziel = Dienst Znuny`, `rolle = agent` | Agent | Zuweisung (der Schalter "Ticketsystem") | Atrium → Znuny |
| Person mit Zuweisung `rolle = kunde` | Kundenbenutzer | Zuweisung | Atrium → Znuny |
| Gruppe der Art Rechte | Znuny-Rolle | Gruppenzuweisung | Atrium → Znuny |
| Gruppe der Art Geltungsbereich (Abteilung) | Znuny-Gruppe | Richtlinie des Mandanten | Atrium → Znuny |
| Mandant oder Kundenbereich | Kunde (Firmenobjekt) | Mandantenanlage | Atrium → Znuny |
| Warteschlange | Warteschlange | keiner | bleibt in Znuny, in Atrium nur lesend angezeigt |

Die Warteschlange ist die Stelle, an der die Produktgrenze verläuft, und sie verläuft dort aus einem sachlichen Grund: eine Warteschlange trägt Eskalationszeiten, Signaturen, automatische Antworten und Zuständigkeitsregeln, für die Atrium kein Modell hat und auch keines bekommen soll, weil es sonst die Fachlogik eines Ticketsystems nachbaute. Atrium erzeugt die Znuny-Gruppe und die Znuny-Rolle und setzt die Mitgliedschaften; welche Warteschlange welcher Gruppe zugeordnet ist, entscheidet die Ticketsystemverwaltung in Znuny. Die Konsole zeigt diese Zuordnung nur lesend an, damit ein Bediener die Frage "auf welche Warteschlangen wirkt diese Gruppe" beantworten kann, ohne die Fremdoberfläche zu öffnen. Das Manifest kann `queue_bindung: verwaltet` deklarieren, aber nur, wenn die Produktgrenzdeklaration des Katalogeintrags das ausdrücklich zulässt; der Vorgabewert ist `beobachtend`.

### Feldeigentum je Feld

| Feld im Fremdsystem | Eigentum | Begründung |
|---|---|---|
| Anmeldename | `erstanlage` | Aus dem Anzeigenamen nach Mandantenrichtlinie abgeleitet, nach Erzeugung unveränderlich; eine spätere Änderung bräche Verweise in Vorgängen |
| Vorname, Nachname | `atrium` | Folgen dem Anzeigenamen der Person; eine Namensänderung soll durchschlagen |
| Mailadresse | `atrium` | Folgt der primären Mailadresse aus [Kapitel 14](14-mail.md) |
| Gültigkeit | `atrium` | Trägt Aktivierung und Deaktivierung; einziges Feld, über das der Austritt wirkt |
| Kennwort | wird nie gesetzt | Anmeldung über OIDC; kein Konnektor schreibt jemals ein Kennwort in ein Fremdsystem (INV-20) |
| Sprache | `erstanlage` | Vorbelegung aus der Person, danach eine Nutzereinstellung |
| Signatur, Abwesenheitsnotiz, Benachrichtigungseinstellungen | `fremd` | Persönliche Arbeitseinstellungen; ein Überschreiben wäre Datenverlust |
| Rollenmitgliedschaft | `atrium` | Folgt unmittelbar der Zuweisung |
| Gruppenmitgliedschaft mit Rechtebits | `atrium` | Folgt der Gruppenzuweisung; die Bits werden aus der Atrium-Rolle abgebildet, nicht einzeln gepflegt |
| Vorgangsdaten jeder Art | `fremd` | Außerhalb der Produktgrenze; kein Konnektoraufruf berührt sie |

Die Feldnamen folgen dem in dieser Produktlinie üblichen Schema. Verbindlich ist nicht der hier genannte Name, sondern dass für jedes Feld, das die Fremdoberfläche anbietet, genau eine Eigentumsangabe existiert (INV-13). Der tatsächliche Feldsatz wird bei der Manifesterstellung gegen die Schnittstelle der eingesetzten Fassung erhoben und nicht aus dieser Tabelle übernommen; der Entwurf erfindet keine Feldliste.

### Aktivierung statt Löschung

Das Produkt löscht keine Agenten, weil Vorgänge dauerhaft auf Ersteller, Besitzer und Bearbeiter verweisen. Das Manifest deklariert deshalb `loeschen` als nicht unterstützt, und die Bindung zeigt in der Konsole dauerhaft: "Beim Entzug wird das Konto deaktiviert, nicht gelöscht." Ein `apply` mit der Aktion `entfernen` wird vom generischen Treiber gar nicht erst erzeugt; er bildet den Entzug auf `deaktivieren` ab, weil das Manifest die Ersetzung ausdrücklich erklärt (`deaktivieren: { ersetzt: loeschen }`). Die verworfene Alternative, den Entzug als Fehler zu melden, erzeugt bei jedem Austritt eine Störung, die kein Bediener beheben kann.

### Austritt eines Mitarbeiters

```
Vorgang "Person ausgeschieden", Wirkungsvorschau je Bindung:

  Protokollkopf (OIDC)   : Sitzungen widerrufen, keine neuen Token für diese Person
  Znuny                  : Gültigkeit -> ungültig
                           Rollenmitgliedschaften entfernen
                           Gruppenmitgliedschaften entfernen
                           Vorgänge unverändert, Name bleibt in der Vorgangshistorie sichtbar
  Postfach               : je Richtlinie delegieren oder umleiten (Kapitel 14)
  Geräte                 : Zertifikate sperren, Sperrliste sofort verteilen (Kapitel 11)
  Gruppen                : Mitgliedschaften enden; abgeleitete Zuweisungen laufen ab

  Bestätigungstext: "Tickets bleiben bestehen. Die Person wird deaktiviert, nicht gelöscht.
  Der Name bleibt in der Vorgangshistorie sichtbar."
```

Die Mitgliedschaften werden entfernt, obwohl das Konto ohnehin ungültig ist. Die verworfene Alternative, sie stehen zu lassen, ist bequemer und historisch aussagekräftiger, hinterlässt aber ein Konto, dessen bloße Reaktivierung sämtliche Rechte zurückbringt; die Entfernung macht eine Reaktivierung zu einer ausdrücklichen Rechteentscheidung. Die historische Aussage geht nicht verloren, weil der Auditstrom festhält, welche Mitgliedschaften wann entfernt wurden (INV-23).

### Datenschutzfolgen

| Sachverhalt | Bewertung |
|---|---|
| Vorgänge bleiben bestehen und nennen die Person | Unvermeidbar; die Vorgangshistorie ist der Geschäftszweck des Produkts und liegt außerhalb der Produktgrenze |
| Deaktivierung ist keine Löschung | Wird vor der Bestätigung im Klartext benannt, statt als "entfernt" dargestellt zu werden |
| Löschanspruch nach der Datenschutz-Grundverordnung | Atrium kann ihn im Fremdsystem nicht erfüllen und behauptet das nicht; es dokumentiert nachweisbar, was getan wurde |
| Pseudonymisierung | Nur möglich, wenn das Produkt eine dokumentierte Schnittstelle dafür anbietet; das Manifest deklariert die Fähigkeit `pseudonymisieren` oder weist sie als fehlend aus. Der Entwurf erfindet keine solche Schnittstelle |
| Nachweis | Deaktivierung, Mitgliedschaftsentzug und Sitzungswiderruf sind je ein Auditereignis mit derselben Korrelationskennung; die Rechenschaftspflicht ist damit belegbar, der Löschanspruch nicht erfüllt |

Diese Tabelle ist die ehrliche Grenze des Entwurfs: die Integration macht den Umgang mit dem Austritt vollständig, nachvollziehbar und einheitlich über alle Zielsysteme, sie macht ihn nicht datenschutzrechtlich abschließend. Die Fortführung steht in [Kapitel 22](22-compliance.md).

### Anmeldung über OIDC

| Gegenstand | Herkunft | Wird eingegeben |
|---|---|---|
| Kennung des vertrauenden Dienstes | abgeleitet aus der Dienstkennung | nein |
| Rücksprungadresse | abgeleitet aus der Veröffentlichung | nein |
| Geheimnis des vertrauenden Dienstes | erzeugt, als Geheimnisreferenz in der Bindung abgelegt, nie angezeigt (INV-20) | nein |
| `sub` | Kennung der Person (ULID), stabil über Umbenennungen | nein |
| `preferred_username` | abgeleiteter Anmeldename | nein |
| `email` | primäre Mailadresse | nein |
| Gruppenangabe | ausschließlich die Gruppen, die für diesen Dienst eine Zuweisung tragen | nein |

Die Gruppenangabe ist gefiltert und nicht vollständig. Ein Token, das jedem Dienst den gesamten Gruppengraphen eines Mandanten mitteilt, ist eine Informationspreisgabe an jedes eingebundene Fremdprodukt und wäre zugleich eine Umgehung der Mandantenisolation über die Tokeninhalte. Die Ausstellung erbringt der Protokollkopf aus [Kapitel 10](10-identitaet.md); die Prüfung der Zuweisung geschieht bereits bei der Tokenausstellung: ohne wirksame Zuweisung wird für diesen Dienst kein Token ausgestellt, auch dann nicht, wenn der Konnektor ein Konto angelegt hat. Damit ist die Anmeldung auch dann gesperrt, wenn die Versorgung fehlerhaft war.

Für Produkte ohne brauchbare OIDC-Unterstützung deklariert der Katalogeintrag den Rückfallweg: Authentisierung am Eingang mit Weitergabe der geprüften Identität als Kopfzeile, wobei der Dienst ausschließlich Verbindungen vom Eingang annimmt und die Kopfzeile am Eingang aus jeder eingehenden Anfrage entfernt wird, bevor sie gesetzt wird. Dieser Weg ist schwächer als OIDC, weil jeder, der den Dienst unter Umgehung des Eingangs erreicht, die Identität behaupten kann; er wird deshalb in der Konsole als schwächeres Verfahren gekennzeichnet, die Verbindung zwischen Eingang und Dienst läuft über gegenseitig authentisiertes TLS, und der Dienst liegt in einer Netzzone ohne andere Zugangswege.

### Wo Atrium die Erreichbarkeit festlegt

| Abgeleitetes Artefakt | Quelle | Kapitel |
|---|---|---|
| DNS-Eintrag in der richtigen Sicht (intern, extern oder beide) | Veröffentlichung und Domäne | [Kapitel 12](12-dns-netzwerk.md) |
| Route am Eingang | Veröffentlichung | [Kapitel 05](05-systemarchitektur.md) |
| Zertifikat aus der Mandanten-Zwischen-CA oder über ACME nach RFC 8555 | Veröffentlichung | [Kapitel 11](11-pki.md) |
| Netzfreigabe vom Zugriffskreis zur Veröffentlichung | Veröffentlichung und Zuweisung | [Kapitel 12](12-dns-netzwerk.md) |
| Grundadresse innerhalb des Fremdprodukts | Veröffentlichung, über die Konnektorbindung geschrieben | dieses Kapitel |

Die letzte Zeile ist der Punkt, an dem der Konnektor die Erreichbarkeit berührt, ohne sie zu bestimmen. Znuny muss seine eigene Adresse kennen, weil sie in ausgehenden Benachrichtigungen als Verweis erscheint; das entsprechende Produktfeld ist deshalb ein Feld im Eigentum von Atrium und folgt der Veröffentlichung. Wird die Veröffentlichung geändert, ändert der nächste Abgleich die Grundadresse mit, und die Wirkungsvorschau benennt ausdrücklich, dass bereits versandte Verweise auf die alte Adresse zeigen. Existieren eine interne und eine externe Veröffentlichung für denselben Dienst, muss genau eine als kanonisch gekennzeichnet sein; die Konsole fragt das nur in diesem Fall und zählt die Frage gegen das Entscheidungsbudget aus INV-14 und K-03.

## Zweites Beispiel: Datenbankdienst PostgreSQL

Einstufung: **T2**. Die Schnittstelle ist ein binäres Drahtprotokoll und keine HTTP-Ressource, Bezeichner unterliegen einer eigenen Zitierregel, und die Operationen sind transaktionsgebunden. Diese Einstufung verbraucht einen Platz aus dem knappen T2-Kontingent und ist begründbar, weil eine Implementierung jeden PostgreSQL-gestützten Katalogeintrag bedient; die Kosten aus der Skalierungsrechnung fallen einmal an, der Nutzen vielfach.

### Objektabbildung

| Atrium-Objekt | PostgreSQL-Objekt | Eigentum | Anmerkung |
|---|---|---|---|
| Dienstkonto einer Anwendung | Rolle mit Anmelderecht | `atrium` | Name abgeleitet, nie eingegeben |
| Dienst | Datenbank | `erstanlage` für die Datenbank, `fremd` für ihren Inhalt | Atrium legt an, die Anwendung besitzt das Schema |
| Gruppe der Art Rechte | Rolle ohne Anmelderecht als Sammelrolle | `atrium` | Mitgliedschaft folgt der Gruppenzuweisung |
| Zuweisung mit Rolle `leser`, `schreiber`, `eigentuemer` | Rechte auf Schema plus Standardrechte für künftige Objekte | `atrium` | Ohne Standardrechte gelten neue Tabellen nicht als erfasst |
| Tabellen, Indizes, Migrationsstand | — | `fremd` | Außerhalb der Produktgrenze |
| Speicherbereich, Datensicherheitsstufe, Sicherungsplan | — | `atrium` | [Kapitel 17](17-speicher-backup.md) |

### Sichere Ausführung

| Regel | Umsetzung |
|---|---|
| Keine Zeichenkettenverkettung von Werten | Werte sind ausschließlich gebundene Parameter des Drahtprotokolls |
| Bezeichner sind keine Parameter | Bezeichner werden zweistufig behandelt: Prüfung gegen `^[a-z][a-z0-9_]{0,62}$` und anschließend serverseitige Zitierung; schlägt die Prüfung fehl, wird die Operation abgelehnt und nicht bereinigt |
| Kein freier Anweisungstext aus einem Manifest | Der Konnektor kennt einen festen, abgeschlossenen Operationskatalog: `rolle_anlegen`, `rolle_deaktivieren`, `mitgliedschaft_setzen`, `datenbank_anlegen`, `recht_erteilen`, `recht_entziehen`, `standardrecht_setzen`, `sicherung_vorbereiten`, `sicherung_abschliessen`, `sicherung_pruefen` |
| Keine Shell | Der Konnektor spricht das Drahtprotokoll; er ruft kein Kommandozeilenwerkzeug auf und interpoliert nichts in eine Kommandozeile |
| Transport | TLS 1.3 nach RFC 8446 mit vollständiger Prüfung der Serverkette gegen die Mandanten-Zwischen-CA; eine schwächere Prüfstufe ist im Bindungsschema nicht ausdrückbar |
| Fehlermeldungen | Fremdmeldungen gehen ausschließlich in das Betriebsprotokoll; die Konsole zeigt den übersetzten Schlüssel ohne Bezeichner, Pfade oder Abfragetext |

Die Regel "kein freier Anweisungstext aus einem Manifest" ist die Bedingung dafür, dass Datenbankintegration überhaupt teilweise deklarativ werden darf. Ein Manifest, das eine Anweisung als Zeichenkette mitbringt, verwandelt einen Gemeinschaftsbeitrag in beliebige Ausführung auf der Datenbank des Kunden; der Prüfaufwand eines solchen Beitrags entspräche dem eines T2-Beitrags, und der Vorteil der Stufe entfiele.

### Passwortlosigkeit über Zertifikate

```
Anwendungsdienst A benötigt Zugriff auf Datenbank D:

  1. Dienstkonto von A erhält ein Zertifikat aus der Mandanten-Zwischen-CA.
     Der Betreff trägt den abgeleiteten Rollennamen.                       (Kapitel 11)
  2. Der Konnektor legt in D eine Rolle mit Anmelderecht und ohne Kennwort an.
     Es existiert kein Kennwort, das entwendet werden könnte.
  3. Die erzeugte Zugangskonfiguration der Datenbank lässt für diese Rolle
     ausschließlich zertifikatsbasierte Authentisierung zu; es existiert keine
     Zeile, über die ein Kennwort akzeptiert würde.
  4. Die Abbildung Zertifikatsbetreff -> Rollenname wird aus dem Sollzustand
     erzeugt und bei jedem Abgleich neu geschrieben.                       (INV-02)
  5. Erneuerung: gewöhnliche Zertifikatserneuerung nach K-13.
     Kein Kennwortwechsel, kein Neustart, keine Änderung am Fremdsystem.

Der Konnektor selbst meldet sich ebenfalls mit einem eigenen Zertifikat an,
als Verwaltungsrolle mit genau den Rechten "Rollen anlegen" und "Datenbanken
anlegen" und ohne Oberrechte.
```

Der Gewinn ist nicht nur Bequemlichkeit: INV-20 wird an dieser Stelle trivial erfüllbar, weil es kein Geheimnis gibt, das ausgegeben, protokolliert oder exportiert werden könnte. Der Entwurf benennt die verbleibende Schwäche: das Recht, Rollen anzulegen, erlaubt in verbreiteten Rechtemodellen mehr Einfluss auf andere Rollen ohne Oberrechte, als der Name nahelegt. Wo das Rechtemodell der eingesetzten Fassung keine engere Verwaltungsrolle zulässt, wird das als Restrisiko an der Bindung angezeigt und nicht weggeschrieben.

### Sicherungshaken

| Aufruf | Zeitpunkt | Wirkung | Frist (Zielwert) |
|---|---|---|---|
| `sicherung_vorbereiten` | vor der Momentaufnahme des Speicherbereichs | Versetzt die Datenbank in den vom Produkt dokumentierten Zustand, in dem das Datenverzeichnis wiederherstellbar ist | Fensterbreite ≤ 5 s |
| `sicherung_abschliessen` | nach der Momentaufnahme | Beendet diesen Zustand; wird auch bei Abbruch der Momentaufnahme aufgerufen | ≤ 3 s |
| `sicherung_pruefen` | nach der Auslagerung | Spielt die Momentaufnahme in eine Nebeninstanz ein und führt eine Konsistenzprüfung aus | ≤ 60 min (K-24) |

Die drei Haken sind keine neuen Verben, sondern Aktionen innerhalb von `apply`, ausgelöst von der Speicherschicht statt von einer Zuweisung. Ein Wiederherstellungspunkt ohne erfolgreiches `sicherung_pruefen` trägt den Zustand "ungeprüft" und wird als solcher am Dienst angezeigt, nicht in einem Bericht verborgen (INV-18). Die Schwäche ist offensichtlich und wird nicht übergangen: die Prüfung braucht eine zweite Instanz und den Platz für den geprüften Bestand; auf einem Ein-Knoten-System mit großer Datenbank ist sie nicht kostenlos und möglicherweise nicht durchführbar. Die Konsole zeigt dann dauerhaft "ungeprüft" und behauptet keinen Wiederherstellungswert, den niemand nachgewiesen hat.

## Import bestehender Systeme

### Erstabgleich

Der Erstabgleich benutzt `observe` im Modus `bestand`, seitenweise mit Fortsetzungsmarke, und schreibt im Fremdsystem nichts. Zwei vollständige Bestandsabrufe hintereinander ergeben die Stabilitätsprüfung: weicht der zweite Abruf um mehr als 1 % vom ersten ab, ändert sich das Fremdsystem während des Laufs, und ein Abgleich auf diesem Stand wäre eine Momentaufnahme, die nie existiert hat.

### Konfliktbehandlung

| Befund | Bedeutung | Vorgeschlagene Handlung | Automatisch |
|---|---|---|---|
| eindeutig zugeordnet | genau ein Treffer über den Zuordnungsschlüssel | Rückverweis setzen, Zuweisung erzeugen | ja |
| mehrdeutig | mehr als ein Treffer | Entscheidung je Fall | nein, nie |
| nur im Fremdsystem | Fremdkonto ohne Entsprechung im Kern | vier Optionen: als Person übernehmen, als Dienstkonto übernehmen, als `fremdverwaltet` kennzeichnen (Atrium fasst es nie an), stilllegen | nein |
| nur im Kern | Atrium-Objekt ohne Fremdkonto | gewöhnliche Versorgung im zweiten Vorgang | ja |
| widersprüchlich | zugeordnet, aber Felder weichen ab | je Feld nach Eigentum: `atrium` — Kern gewinnt; `fremd` — Fremdsystem gewinnt; `erstanlage` — Fremdsystem gewinnt und der abweichende Kernwert wird als Abweichung angezeigt | ja, nach Eigentum |

Die Zuordnungsschlüssel stehen geordnet im Manifest, und der Anzeigename ist als Schlüssel verboten. Der Grund ist die Schwere des Fehlers: Anzeigenamen sind weder eindeutig noch stabil, und eine Falschzuordnung verschmilzt zwei Personen zu einer, was im Fremdsystem Rechte vermischt und im Kern eine Identität vernichtet. Ein `mehrdeutig`-Befund wird nie automatisch aufgelöst, auch nicht mit einer Heuristik.

### Probelauf und Übernahme ohne Datenverlust

```
Zwei getrennte Vorgänge, nicht einer:

  Vorgang 1  "Bestand erfassen"
    observe(bestand) über alle Seiten        -> kein Schreibzugriff im Fremdsystem
    Stabilitätsprüfung (Doppelabruf)
    Abgleichbericht mit fünf Befundklassen
    plan() für die geplanten Fremdwirkungen  -> nebenwirkungsfrei (INV-08)
    Schreibwirkung: ausschließlich in Atrium (Rückverweise, Zuweisungsentwürfe)
    Rücknahme: vollständig, weil kein Fremdobjekt berührt wurde

  --- Freigabe durch einen Menschen; bei mehr als 5 % geplanten Deaktivierungen
      zusätzlich Freigabe durch eine zweite Rolle (Kapitel 19) ---

  Vorgang 2  "Bestand übernehmen"
    apply() je Schritt, mit Idempotenzschlüssel
    Teilfehler je Zielobjekt benannt (INV-12)
    Fortsetzbar über die Fortsetzungsmarke
```

Die Trennung in zwei Vorgänge ist die konkrete Zusage "Übernahme ohne Datenverlust". Im ersten Vorgang kann nichts im Fremdsystem verloren gehen, weil nichts geschrieben wird; im zweiten wird nur geschrieben, was der Bediener in einem vollständigen Bericht gesehen hat. Die zweite Freigabe bei mehr als 5 % geplanten Deaktivierungen folgt dem Freigabeweg aus [Kapitel 19](19-mandanten-rechte-audit.md). Der Bericht eines Imports mit mehreren tausend Objekten überschreitet das Vorschaubudget aus K-18 von 2 s deutlich; der Import ist deshalb ausdrücklich als langlaufender Vorgang mit eigenem Fortschritt geführt und von K-18 ausgenommen. Die Konsole benennt das, statt eine Vorschau zu zeigen, die stillsteht.

### Abbruchkriterien

| Kriterium | Schwelle (Zielwert) | Wirkung |
|---|---|---|
| Anteil mehrdeutiger Zuordnungen | > 2 % der Fremdobjekte | Kein Übernahmeangebot, nur Bericht; der Zuordnungsschlüssel ist ungeeignet |
| Anteil Fremdobjekte ohne Entsprechung im Kern | > 20 % | Rückfrage; Vorbelegung wechselt von "übernehmen" auf "fremdverwaltet" |
| Fehlerklasse `rechte` oder `schema` im Probelauf | ein Vorkommen | Abbruch, kein Teilimport |
| Fehlerklasse `voruebergehend` | > 5 % der Seitenabrufe | Abbruch mit Wiederholungsangebot ab der letzten Fortsetzungsmarke |
| Abweichung zwischen den beiden Bestandsabrufen | > 1 % | Abbruch; das Fremdsystem ändert sich während des Laufs |
| Geplante Deaktivierungen | > 5 % des beobachteten Bestands | Freigabe durch eine zweite Rolle erforderlich |
| Überschreitung der Import-Höchstdauer | 300 s je Seitenlauf | Abbruch mit erhaltener Fortsetzungsmarke, kein Verlust des bisherigen Berichts |

Die Schwellen sind Zielwerte und keine Messergebnisse. Sie sind bewusst eher niedrig gewählt, weil ein abgebrochener Import folgenlos ist, ein durchgelaufener Fehlimport aber in einem produktiven Fremdsystem behoben werden muss, und zwar von Hand.

## Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| R-09-01 | Der Konnektorvertrag umfasst genau die fünf Operationen `describe`, `observe`, `plan`, `apply`, `healthcheck`; der Bestandsabruf ist ein Modus von `observe` und kein weiteres Verb. | KANON 4.5 |
| R-09-02 | `plan` und `healthcheck` verändern im Fremdsystem nichts; ein Bestandsabruf vor und nach dem Aufruf ist feldgleich. | INV-08 |
| R-09-03 | Ein zweites `apply` mit demselben Idempotenzschlüssel und demselben Sollzustand meldet für jeden Schritt `unveraendert` und erzeugt kein Auditereignis vom Typ "geändert". | INV-07 |
| R-09-04 | Jeder Fehler wird genau einer der sechs Klassen `dauerhaft`, `voruebergehend`, `rechte`, `kontingent`, `schema`, `konflikt` zugeordnet; der Rückfall für ein nicht abgebildetes Signal ist `dauerhaft`. | INV-12 |
| R-09-05 | Kein Fremdfehlertext erreicht Konsole, Bericht oder Auditereignis; die Konsole zeigt ausschließlich den übersetzten Schlüssel, der Rohtext steht allein im Betriebsprotokoll. | INV-16, INV-17 |
| R-09-06 | Jede Operation hält die im Manifest deklarierte Frist ein; der Wächter beendet den Prozess beim 1,5-fachen Wert, und ein so beendeter `apply`-Schritt erhält den Zustand `unbekannt` mit sofort eingeplanter Nachbeobachtung. | K-15, K-18 |
| R-09-07 | Ein Manifest ohne vollständige Eigentumsangabe für jedes abgebildete Feld wird beim Import abgelehnt; der Reconciler schreibt ausschließlich Felder im Eigentum `atrium` sowie `erstanlage`-Felder bei der Erstanlage. | INV-13 |
| R-09-08 | Jede kostenwirksame Aktion ist im Manifest mit Wirkung, Messgröße und Anzeigetext deklariert und erscheint in der Wirkungsvorschau vor der Bestätigung. | INV-29 |
| R-09-09 | Jeder Katalogeintrag trägt eine Produktgrenzdeklaration, die jedes von `describe` gemeldete Objekt einer Seite zuordnet; ein Eintrag ohne vollständige Zuordnung wird nicht freigegeben. | INV-30 |
| R-09-10 | Der generische Treiber hält die im Manifest deklarierten Ratengrenzen ein und wählt die Wartezeit nach einem Kontingentfehler nicht selbst, sondern aus der Angabe des Fremdsystems. | K-15 |
| R-09-11 | Die Gesundheitsprobe ist lesend, hält 1 s ein und vergleicht die von `describe` gemeldete Fähigkeitsliste mit der Manifestdeklaration; eine Abweichung setzt die Bindung auf `abweichend`. | INV-28 |
| R-09-12 | Jeder Konnektorprozess läuft unter eigenem Systembenutzer je Mandant und Bindung, in eigenem Netznamensraum mit aus der Bindung erzeugter Ausgangs-Positivliste, ohne eigene Namensauflösung, mit leerer effektiver Capability-Menge und seccomp-Positivliste. | INV-21, INV-19 |
| R-09-13 | Ein Konnektorprozess erhält kein Dauergeheimnis als Wert; für HTTP-Bindungen setzt ein Vermittler die Berechtigungskopfzeile, für übrige Bindungen wird der Wert auftragsgebunden abgerufen und jeder Abruf auditiert. | INV-20 |
| R-09-14 | Der generische Treiber führt aus einem Manifest keinen Ausdruck, keine Vorlage mit Seiteneffekt und keinen Anweisungstext aus; Fremdwirkungen entstehen ausschließlich aus einem festen Operationskatalog. | INV-20 |
| R-09-15 | Manifeste werden beim Import und Fremdantworten vor der Weitergabe an den Reconciler gegen das Schema der laufenden Vertragsversion validiert; ein unbekanntes Feld im Manifest führt zur Ablehnung, nicht zum Ignorieren. | INV-24 |
| R-09-16 | Der Kern lädt Manifeste der Vertragsversion N und N-1; ein Manifest mit N-2 setzt die Bindung auf `ausgesetzt` und erzeugt eine sichtbare Aufgabe. | KANON 3 |
| R-09-17 | Die zehn Vertragstests laufen im Bau gegen jeden Konnektor; ein fehlschlagender Test verhindert die Freigabe des Katalogbands. | K-27 |
| R-09-18 | Die Zertifizierungsstufe und die Pflegestelle sind an jeder Konnektorbindung sichtbar; eine Bindung ohne Pflegezusage trägt den Text "ohne zugesicherte Pflege". | INV-18 |
| R-09-19 | Jedes Manifest ist nach RFC 8032 über die kanonische Serialisierung nach RFC 8785 signiert, im Transparenzprotokoll mit Zeitstempel nach RFC 3161 eingetragen und wird bei jedem Start des Treibers erneut geprüft. | KANON 4 Lieferkette |
| R-09-20 | Kein Rückzugsschritt löscht, deaktiviert oder verändert ein Fremdobjekt; im Zustand `zurueckgezogen` bleibt `observe` erlaubt, im Zustand `gesperrt` ist jede Operation untersagt. | INV-11 |
| R-09-21 | Der Katalog weist den Anteil der T2-Konnektoren aus; der Zielwert beträgt ≤ 6 % bei einem T0-Deckungsgrad von 0,25, und jede T2-Aufnahme trägt eine veröffentlichte Begründung gegen den Entscheidungsbaum. | K-22 |
| R-09-22 | Ein verwaister Konnektor durchläuft die Schritte Kennzeichnung, Übernahmeaufruf über 90 Tage, `zurueckgezogen` und Entfernung nach 12 Monaten; Fremdkonten bleiben in jedem Schritt unberührt. | INV-11, INV-18 |
| R-09-23 | Deklariert ein Manifest `loeschen` als nicht unterstützt, erzeugt der Treiber keine Entfernungsaktion, bildet den Entzug auf `deaktivieren` ab und zeigt das vor der Bestätigung im Klartext an. | INV-11, INV-12 |
| R-09-24 | Kein Konnektor schreibt ein Kennwort in ein Fremdsystem; die Anmeldung läuft über OIDC oder über den als schwächer gekennzeichneten Eingangsweg mit gegenseitig authentisiertem TLS und Kopfzeilenbereinigung am Eingang. | INV-20 |
| R-09-25 | Die Erreichbarkeit eines Dienstes folgt ausschließlich aus einer Veröffentlichung; der Konnektor schreibt die Grundadresse in das Fremdprodukt, bestimmt sie aber nicht, und je Dienst ist höchstens eine Veröffentlichung kanonisch. | INV-09, INV-10 |
| R-09-26 | Der Datenbankkonnektor bindet Werte als Parameter, prüft Bezeichner gegen ein Muster und zitiert sie serverseitig, ruft kein Kommandozeilenwerkzeug auf und führt keinen Anweisungstext aus einem Manifest aus. | INV-20 |
| R-09-27 | Von Atrium verwaltete Datenbankrollen tragen kein Kennwort; die erzeugte Zugangskonfiguration lässt für sie ausschließlich zertifikatsbasierte Authentisierung zu, und das Bindungsschema kann keine Prüfstufe unterhalb der vollständigen Kettenprüfung ausdrücken. | INV-20, INV-02 |
| R-09-28 | Ein Speicherbereich eines Dienstes mit deklarierten Sicherungshaken wird nur zwischen `sicherung_vorbereiten` und `sicherung_abschliessen` abgebildet; ein Wiederherstellungspunkt ohne erfolgreiches `sicherung_pruefen` trägt sichtbar den Zustand "ungeprüft". | INV-18, K-24 |
| R-09-29 | Der Erstabgleich schreibt im Fremdsystem nicht; Fremdwirkungen entstehen erst in einem zweiten, getrennt freigegebenen Vorgang. | INV-08, INV-11 |
| R-09-30 | Zuordnungsschlüssel stammen geordnet aus dem Manifest; der Anzeigename ist als Schlüssel unzulässig, und ein mehrdeutiger Treffer wird nie automatisch aufgelöst. | INV-12 |
| R-09-31 | Die sieben Abbruchkriterien des Imports sind harte Schwellen; bei Überschreitung endet der Vorgang mit erhaltener Fortsetzungsmarke und vollständigem Bericht. | INV-12 |
| R-09-32 | Jede Aktion trägt im Manifest eine Konvergenzklasse; als `nicht_konvergent` deklarierte Aktionen werden nie automatisch wiederholt, sondern erzeugen eine Aufgabe mit benanntem Grund. | INV-07, INV-12 |

## Akzeptanzkriterien

| Kriterium | Anforderung | Nachweisverfahren |
|---|---|---|
| Die gRPC-Dienstbeschreibung des Vertrags enthält genau fünf Operationen; ein sechstes Verb bricht den Bau. | R-09-01 | Schnittstellenprüfung im Bau |
| Ein Bestandsabruf vor und nach `plan` liefert feldgleiche Ergebnisse über alle Testkonnektoren. | R-09-02 | Vorschauprobe des Vertragstests |
| Ein zweiter `apply`-Lauf erzeugt null Änderungsereignisse. | R-09-03 | Doppellauf im Bau |
| Jedes im Testbestand erzeugte Fremdsignal landet in genau einer der sechs Klassen; null nicht abgebildete Signale. | R-09-04 | Fehlerinjektion über die Signalliste des Manifests |
| Eine Mustersuche über alle Konsolentexte und Auditereignisse findet null Fremdfehlertexte. | R-09-05 | Musterprüfung im Bau |
| Ein künstlich verzögerter `apply` wird beim 1,5-fachen der Frist beendet, und der betroffene Schritt trägt `unbekannt` mit eingeplanter Nachbeobachtung. | R-09-06 | Verzögerungsinjektion |
| Ein Manifest mit einem Feld ohne Eigentumsangabe wird beim Import abgewiesen. | R-09-07 | Negativtest im Bau |
| Eine als kostenwirksam bekannte, nicht deklarierte Aktion bricht den Vertragstest. | R-09-08 | Abgleich gegen die Liste bekannter Kostenwirkungen |
| Ein Katalogeintrag ohne vollständige Produktgrenzdeklaration wird bei der Freigabe abgelehnt. | R-09-09 | Freigabeprüfung |
| Ein Konnektorprozess erreicht weder eine nicht gelistete Adresse noch 8400, und `CapEff` ist 0. | R-09-12 | Netznamensraumtest und Prozessprüfung |
| Eine Musterprüfung über Prozessausgabe, Betriebsprotokoll und Sollzustandsexport findet null Geheimnistreffer. | R-09-13 | Ausgabeprüfung im Bau |
| Eine Quelltextsuche im generischen Treiber findet keinen Ausdrucksauswerter und keine Ausführung manifestgelieferten Anweisungstextes. | R-09-14 | statische Quelltextprüfung |
| Ein Manifest mit einem unbekannten Feld wird abgewiesen; ein Manifest mit Vertragsversion N-2 setzt die Bindung auf `ausgesetzt`. | R-09-15, R-09-16 | Negativtests im Bau |
| Ein Manifest mit ungültiger oder fehlender Signatur ist nicht ladbar; eine nachträgliche Änderung der abgelegten Datei wird beim Start erkannt. | R-09-19 | Signaturprobe mit manipulierter Datei |
| Nach Versetzen eines Konnektors in `gesperrt` existiert im Fremdsystem null Änderung an Fremdobjekten. | R-09-20 | Bestandsvergleich vor und nach der Sperrung |
| Der Katalog weist den T2-Anteil aus; ein T2-Eintrag ohne veröffentlichte Begründung bricht die Katalogfreigabe. | R-09-21 | Katalogprüfung im Bau |
| Der Austrittsvorgang einer Person setzt die Gültigkeit im Ticketsystem auf ungültig, entfernt Mitgliedschaften, ändert null Vorgangsdatensätze und zeigt vorher den Klartexthinweis. | R-09-23 | Integrationstest gegen eine Laborinstanz |
| Über alle Konnektoren findet eine Quelltext- und Verkehrsprüfung null geschriebene Kennwortfelder. | R-09-24 | Vertragstest mit Verkehrsmitschnitt |
| Eine Änderung der Veröffentlichung ändert die Grundadresse im Fremdprodukt im nächsten Abgleich; zwei kanonische Veröffentlichungen an einem Dienst werden von der API abgelehnt. | R-09-25 | Integrationstest und Negativtest |
| Ein Bezeichner, der das Muster verletzt, führt zur Ablehnung der Operation und nicht zu einer bereinigten Ausführung. | R-09-26 | Negativtest mit Sonderzeichen im Bezeichner |
| Ein Anmeldeversuch mit Kennwort gegen eine von Atrium verwaltete Datenbankrolle scheitert. | R-09-27 | Integrationstest |
| Ein Wiederherstellungspunkt ohne erfolgreiche Prüfung wird am Dienst als "ungeprüft" angezeigt. | R-09-28 | Zustandsmatrixtest |
| Nach Vorgang 1 eines Imports ist der Fremdbestand byteweise unverändert. | R-09-29 | Bestandsvergleich vor und nach dem Probelauf |
| Ein Testbestand mit absichtlich doppelten Anzeigenamen erzeugt null automatische Zuordnungen. | R-09-30 | Importprobe mit präpariertem Bestand |
| Ein Import, der eine der sieben Schwellen überschreitet, endet mit erhaltener Fortsetzungsmarke und vollständigem Bericht. | R-09-31 | Schwellenprobe je Kriterium |
| Eine als `nicht_konvergent` deklarierte Aktion wird nach einem Abbruch nicht automatisch wiederholt. | R-09-32 | Abbruchinjektion |

## Offene Punkte

1. **Darstellung des Zustands `unbekannt` im Objektmodell.** [Kapitel 07](07-objektmodell.md) definiert den Versorgungszustand je Zielsystem mit Zustand, Zeitpunkt, Grund und Wiederholbarkeit, führt `unbekannt` aber nicht als Wert. Ohne diesen Wert kann ein zwischen Schreiben und Antwort abgebrochener `apply` weder als Erfolg noch als Fehler dargestellt werden, und INV-12 verlangt eine benannte Aussage. Zu entscheiden ist, ob `unbekannt` ein eigener Zustand mit Pflichtnachbeobachtung wird oder ob der Schritt als fehlgeschlagen mit dem Zusatz "Wirkung im Fremdsystem ungewiss" geführt wird; die zweite Variante ist einfacher und in der Konsole irreführend.

2. **Reichweite des Vermittlers für Geheimnisse.** Für HTTP-Bindungen ist der Vermittler Pflicht und wirksam. Für T2-Konnektoren mit Nicht-HTTP-Protokollen existiert kein allgemeiner Einsetzpunkt, und der Wert liegt im Adressraum des Konnektors. Zu entscheiden ist, ob je Protokollfamilie ein eigener Vermittler gebaut wird (Aufwand, der aus dem T2-Kontingent zu bezahlen ist) oder ob die Lücke bestehen bleibt und stattdessen die Wechselfrist der betroffenen Geheimnisse verkürzt wird.

3. **Belastbarkeit der Verteilungsschwelle von 6 %.** Die Obergrenze für T2 hängt an h_T1 = 0,25 Personentagen und a_T1 = 1,5 Personentagen. Beide sind Annahmen; bei a_T1 = 2,5 Personentagen ist der Zielwert aus K-22 mit keiner Verteilung erreichbar. Zu entscheiden ist, ob die Schwelle erst nach Messung an den ersten fünfzig Manifesten verbindlich gesetzt wird und welcher Zielwert bis dahin gilt.

4. **Grenze der Deklarativität des generischen Treibers.** Je mehr Fremdsysteme T1 abdecken soll, desto mehr Konstrukte braucht das Manifest: Schrittketten, bedingte Abbildungen, Seitenlaufvarianten, Wiederholungsregeln. Ab einem bestimmten Punkt ist die Manifestsprache berechnungsuniversal, und der Prüfaufwand eines Beitrags entspricht wieder dem von Quelltext, womit der Sicherheits- und Kostenvorteil der Stufe entfällt. Zu entscheiden ist eine ausdrückliche Ausdrucksgrenze und was mit Fremdsystemen geschieht, die knapp jenseits davon liegen.

5. **Kundeneigene Manifeste.** Der Weg der Selbstpflege verwaister Konnektoren führt einen zweiten Vertrauensanker neben dem Katalogschlüssel ein. Zu entscheiden ist, ob er überhaupt zugelassen wird, ob er auf Bindungen ohne kostenwirksame Aktionen beschränkt bleibt und welche Anforderungen an die Schlüsselablage des Kunden gestellt werden, damit die Signaturprüfung nicht zur Formalität wird.

6. **Budget des periodischen Volllaufs je Bindung oder global.** [Kapitel 05](05-systemarchitektur.md) benennt den Volllauf als bindende Skalengrenze und lässt offen, ob sie global oder je Konnektorbindung gilt. Die hier festgelegte Seitengröße von 200 Objekten und die Bestandsseitenfrist von 10 s bestimmen den Beitrag des Imports zu dieser Grenze mit. Zu entscheiden ist, ob Bestandsabrufe in das Volllaufbudget aus K-16 zählen oder ein eigenes Budget erhalten.

7. **Pflicht zur Pseudonymisierungsfähigkeit.** Ein Katalogeintrag, der personenbezogene Daten verarbeitet und keine Pseudonymisierungsschnittstelle anbietet, macht den Löschanspruch im Fremdsystem unerfüllbar. Zu entscheiden ist, ob die Zertifizierungsstufe A diese Fähigkeit voraussetzt, was einen erheblichen Teil verbreiteter quelloffener Produkte von der höchsten Stufe ausschlösse, oder ob die Stufe lediglich die ehrliche Ausweisung des Fehlens verlangt.

8. **Verwaltete Warteschlangenbindung.** Der Vorgabewert `beobachtend` hält die Produktgrenze sauber, lässt aber die Frage "welche Gruppe arbeitet auf welcher Warteschlange" außerhalb der Wirkungsvorschau. Zu entscheiden ist, ob ein `verwaltet`-Modus angeboten wird, welche Teilmenge der Warteschlangeneigenschaften er umfasst und wie verhindert wird, dass Atrium über diesen Modus schrittweise die Fachkonfiguration des Fremdprodukts übernimmt.

9. **Plausibilitätsschwelle gegen falsche Beobachtungen.** Die Schwelle von 5 % Deaktivierungen je Abgleichlauf ist gesetzt, nicht hergeleitet. Bei kleinen Bindungen mit zwanzig Konten löst ein einziges Objekt die Freigabepflicht aus, bei großen Bindungen bleiben mehrere hundert Deaktivierungen unterhalb der Schwelle. Zu entscheiden ist, ob die Schwelle absolut, relativ oder als Kombination aus beidem wirkt und wie sie mit dem geordneten Rückbau bei der Auflösung einer Gruppe zusammenspielt.
