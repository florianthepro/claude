# Kanonische Festlegungen

## 0. Status und Geltung

Diese Datei ist normativ und bindet alle Kapitel des Whitepapers, alle Anforderungs-IDs und alle Abbildungen. Jede Abweichung eines Kapitels von dieser Datei ist ein Fehler des Kapitels und nicht eine zulässige Variante; Änderungswünsche werden hier eingetragen, bevor ein Kapitel sie benutzt.

---

## 1. Namen und Schreibweisen

| Begriff | Verbindliche Schreibweise | Bedeutung |
|---|---|---|
| Produkt | **Atrium Server OS**, kurz **Atrium** | Die Distribution auf Ubuntu-LTS-Basis. Nie "AtriumOS", nie "Atrium OS". |
| Bedienoberfläche | **Atrium Console** | Die einzige reguläre Bedienoberfläche. Nie "Web-UI", nie "Admin-Panel". |
| Kontrollebene | **atrium-core** | Dienst, der Sollzustand, Konsens, Reconciler, PKI, SCIM und xDS erbringt. |
| Knotenagent | **atrium-node** | Dienst auf jedem Knoten, der den Sollzustand lokal materialisiert. |
| Konnektorprozess | **atrium-connector-\<name\>** | Ein Prozess je Konnektorbindung, `<name>` kleingeschrieben, Bindestrich-getrennt. |
| Kommandozeilenwerkzeug | **atriumctl** | Support- und Automatisierungswerkzeug. Für den Normalbetrieb nicht erforderlich. |
| Zustand eines neuen Knotens | **Wartemodus** | Installiert, nicht zugeordnet, zeigt Kopplungscode, hat nur den Kopplungsendpunkt offen. |
| Erster Kontrollebenenknoten | **Ankerknoten** | Knoten, auf dem die Kontrollebene zuerst konstituiert wurde. |
| Sollzustand | **Sollzustand** | Der deklarierte, quorumpflichtige, versionierte Zielzustand. Nie "Desired State". |
| Istzustand | **Istzustand** | Das beobachtete Ergebnis mit Beobachtungszeitpunkt. Nie "Actual State". |
| Abgleichschleife | **Reconciler** | Etabliert; deutsch nicht sinnvoll ersetzbar. Vorgang: "Abgleich", Ergebnis: "Konvergenz". |
| Konsensmehrheit | **Quorum** | Etabliert. In der Konsole nie sichtbar, nur in Support- und Fachtexten. |
| Mandantenfähigkeit | **Mandant**, **mandantenfähig** | Deutsch verbindlich. "Tenant", "Tenancy" sind verboten. |
| Konsensverfahren | **Raft** | Eigenname. Erscheint nie in der Konsole. |
| Stimmberechtigter Knoten | **Stimmknoten** | Raft-Mitglied mit Stimmrecht. Konsolentext: "Verwaltungsknoten". |
| Stimmloses Mitglied | **Mitleser** | Raft-Lerner ohne Stimmrecht. Konsolentext: "Verwaltungsknoten (mitlesend)". |
| Stimmberechtigter ohne Daten | **Zeuge** | Stimmknoten ohne Dienst- und Speicherlast, für den Zwei-Standort-Fall. |
| Absichtsobjekt | **Zuweisung** | Verbindet Person oder Gruppe mit Dienst und Rolle. Das Objekt hinter dem Schalter. |
| Erreichbarkeitsobjekt | **Veröffentlichung** | Verbindet Dienst, Name, Sichtbarkeit und Zugriffskreis. Nie "Ingress", nie "Route". |
| Abgeleitetes Objekt | **Abgeleitetes Artefakt** | Firewallregel, Proxyroute, DNS-Eintrag, Zertifikat, Fremdkonto. Nie direkt editierbar. |
| Änderungseinheit | **Vorgang** | Plan, Freigabe, Ausführung, Rücknahme als ein Objekt. Nie "Job", nie "Task". |
| Trockenlauf | **Wirkungsvorschau** | Nebenwirkungsfreie Berechnung der Wirkungen eines Vorgangs. Nie "Dry Run". |
| Isolationsstufe | **M0**, **M1**, **M2**, **M3** | Vier Stufen der Mandantenisolation, siehe Abschnitt 4. |
| Datensicherheitsstufe | **Lokal**, **Gespiegelt**, **Synchron gespiegelt** | Die einzige Speicherentscheidung, die ein Bediener trifft. |
| Netzsegment | **Netzzone** | Overlay-Segment, VLAN oder VRF. Grundeinheit aller Firewallableitungen. |
| Datenbereich eines Dienstes | **Speicherbereich** | Persistenter Datenbereich mit Datensicherheitsstufe. Nie "Volume". |
| Softwarebeschreibung | **Katalogeintrag** | Signierte Beschreibung eines einsetzbaren Fremdprodukts. |
| Fremdsystemverbindung | **Konnektorbindung** | Verbindung zu einer konkreten Instanz eines Fremdsystems für einen Mandanten. |
| Sicherungsobjekt | **Wiederherstellungspunkt** | Sollzustandsexport oder Speicherbereichsmomentaufnahme, signiert und geprüft. |
| Kopplung | **Kopplungsvorgang**, **Kopplungscode** | Aufnahme eines Knotens aus dem Wartemodus. Nie "Join", nie "Enrollment". |
| Ausfallabgrenzung | **Fehlerzone** | Stromkreis, Rack, Standort. Grundlage jeder Antiaffinitätsregel. |
| Knotenwartung | **Räumen** | Geordnetes Verlagern aller Lasten von einem Knoten. Nie "Drain". |
| Notzugang | **Notzugang**, **Wiederherstellungscode** | Physisch gebundener Weg zurück nach Aussperrung. |
| Sperrbetrieb | **Eingefroren** | Zustand ohne Quorum: keine Schreibvorgänge, Dienste laufen weiter. |

Verbotene Begriffe in allen Konsolentexten, Meldungen und Berichten: Container, Image, Pod, Namespace, Quadlet, Unit, xDS, Cluster (im Envoy-Sinn), Zone (im DNS-Dateisinn), Ingress, ACME, OIDC-Client, Realm, Raft, Quorum, VRF, OSD, Dataset, Snapshot (deutsch: Momentaufnahme).

---

## 2. Invarianten

**INV-01 — Eine API.** Die Atrium Console benutzt ausschließlich dieselbe öffentlich dokumentierte API wie `atriumctl` und externe Automatisierung. Es existiert kein privater Pfad und keine Funktion, die nur die Konsole auslösen kann. *Prüfung:* Die Konsole wird im Bau gegen eine API-Fassade getestet, die alle nicht öffentlich dokumentierten Endpunkte sperrt; ein Zugriff darauf bricht den Bau.

**INV-02 — Eine Wahrheitsquelle.** Der Sollzustand in atrium-core ist die einzige Wahrheitsquelle. Handbearbeitete Konfigurationsdateien auf Knoten und Handänderungen in Verwaltungsoberflächen von Kernkomponenten werden beim nächsten Abgleich überschrieben; die Überschreibung erzeugt ein Auditereignis vom Typ "Abweichung korrigiert". *Prüfung:* Abweichungstest im Bau, der jede erzeugte Datei nach Handänderung erneut abgleicht.

**INV-03 — Kein Schreibweg am Reconciler vorbei.** Jede Änderung, auch jede Notfallbehebung, ist eine Änderung am Sollzustand und erzeugt einen Vorgang. *Prüfung:* atrium-node akzeptiert nur signierte, versionierte Sollzustandsauszüge; direkte Schreibaufrufe existieren nicht.

**INV-04 — Quorum für Schreibvorgänge.** Schreibende Änderungen am Sollzustand erfordern Quorum. Ohne Quorum ist der Sollzustand eingefroren: Knoten halten und starten ihre Dienste weiter, löschen und verschieben aber nichts. *Prüfung:* Partitionstest, der auf der Minderheitsseite jede Lösch- und Verlagerungsoperation ablehnt.

**INV-05 — Stimmzahl 1, 3 oder 5.** Stimmberechtigte Mitglieder der Kontrollebene sind genau 1, 3 oder 5, niemals 2 oder 4; alle weiteren Verwaltungsknoten sind Mitleser. Ein Zeuge ist ein Stimmknoten ohne Dienst- und Speicherlast. *Prüfung:* Mitgliedschaftsänderungen auf eine gerade Stimmzahl werden von der API abgelehnt.

**INV-06 — Selbstabschottung per Lease.** Ein Knoten ohne gültige Lease beendet seine Arbeitslasten selbst. Die Übernahmefrist auf einem anderen Knoten ist stets größer als Leasefrist plus zugelassene Uhrenabweichung. *Prüfung:* Simulationstest mit injizierter Partition und Uhrensprung vor jeder Freigabe.

**INV-07 — Idempotenz.** Jeder Reconciler-Lauf und jeder Konnektoraufruf ist idempotent und trägt einen Idempotenzschlüssel. Zweimaliges Anwenden desselben Sollzustands erzeugt keine Änderung, keinen Neustart und kein Auditereignis vom Typ "geändert". *Prüfung:* Doppellauf im Bau; ein zweites Änderungsereignis bricht den Bau.

**INV-08 — Wirkungsvorschau ist nebenwirkungsfrei.** `plan` verändert nichts. Jeder Vorgang kann vor der Ausführung als Liste konkreter Wirkungen gezeigt und in einem Review-Bereich freigegeben werden. *Prüfung:* Konnektor-Vertragstest, der nach einem `plan`-Aufruf den Istzustand des Fremdsystems auf Unverändertheit prüft.

**INV-09 — Abgeleitete Artefakte sind nicht editierbar.** Firewallregeln, Proxyrouten, DNS-Einträge für Dienste, Zertifikate und Fremdkonten sind sichtbar, tragen immer einen Verweis auf ihre Quelle und sind nur über die Quelle änderbar. Es gibt keinen Dialog zum Anlegen einer Firewallregel. *Prüfung:* Die API kennt für abgeleitete Artefakte keine Schreiboperation.

**INV-10 — Default-Deny.** Ein Dienst ist erreichbar, weil eine Veröffentlichung existiert, nie weil jemand eine Regel eingetragen hat. Abgeleitete Regelsätze werden atomar getauscht; jeder Fehlerzustand fällt auf Default-Deny zurück. *Prüfung:* Erreichbarkeitstest nach abgebrochener Regelanwendung.

**INV-11 — Keine implizite Löschung.** Jede Löschung erzeugt einen Vorgang mit vollständiger Auswirkungsliste und Bestätigung; Datenträger unterliegen einer Aufbewahrungsfrist, bevor sie freigegeben werden. Nicht rücknehmbare Aktionen sind als solche gekennzeichnet und nie Teil einer Massenaktion ohne Einzelaufstellung. *Prüfung:* Kaskadenlöschung existiert in der API nicht.

**INV-12 — Kein halber, stiller Erfolg.** Teilerfolge werden je Zielsystem benannt, mit Grund, Wiederholungsmöglichkeit und der Angabe, was noch nicht wirkt. Ein Vorgang endet nie im Zustand "fertig", solange ein Zielsystem aussteht. *Prüfung:* Fehlerinjektion in einen von mehreren Konnektoren; der Vorgang muss "teilweise fehlgeschlagen" melden.

**INV-13 — Feldeigentum ist deklariert.** Jedes Konnektormanifest erklärt je Feld, ob Atrium es besitzt, ob das Fremdsystem es besitzt oder ob es nur bei Erstanlage gesetzt wird. Der Reconciler überschreibt ausschließlich Felder in eigenem Besitz. *Prüfung:* Ein Manifest ohne vollständige Eigentumsangabe wird beim Import abgelehnt.

**INV-14 — Höchstens drei Entscheidungen.** Jede Standardaufgabe kostet höchstens drei Entscheidungen. Eine Entscheidung ist ein Pflichtfeld oder eine Auswahl ohne mögliche Vorbelegung; Bestätigungen, optionale Felder und Freitextnotizen zählen nicht. *Prüfung:* Maschinenlesbare Aufgabendefinitionen werden im Bau gegen die tatsächlichen Formulare geprüft; ein zusätzliches Pflichtfeld bricht den Bau.

**INV-15 — Jede Vorbelegung hat eine benannte Quelle.** Richtlinie, Ableitung oder vorheriger Wert; die Quelle steht im Vorgangsprotokoll. Es gibt keine stillen Vorgaben ohne Herkunft. *Prüfung:* Formularfelder ohne Quellenangabe brechen den Bau.

**INV-16 — Kein Fremdvokabular in der Oberfläche.** Die in Abschnitt 1 verbotenen Begriffe erscheinen in keinem Konsolentext, keiner Fehlermeldung und keinem Bericht. *Prüfung:* Positivliste erlaubter Oberflächenbegriffe, geprüft gegen alle Oberflächentexte einschließlich der Übersetzungstabelle für Fremdfehler.

**INV-17 — Keine Fehlermeldung verweist als einzige Lösung auf die Kommandozeile.** Gibt es für einen Fehlerfall keinen Weg in der Oberfläche, gilt der Fehlerfall als nicht fertig entwickelt. *Prüfung:* Musterprüfung aller Meldungstexte auf Befehls- und Dateipfadangaben.

**INV-18 — Degradierte Zustände werden benannt.** Ein Ein-Knoten-System zeigt dauerhaft "Redundanz: keine"; ein Dienst ohne geprüfte Sicherung zeigt das am Dienst, nicht in einem Bericht; der Überblick zeigt dauerhaft, wie viele Knotenausfälle derzeit noch vertragen werden. *Prüfung:* Zustandsmatrixtest über alle Degradationsarten.

**INV-19 — Mandantenbezug ist Pflichtfeld.** Jedes Objekt außer der Plattform selbst gehört genau einem Mandanten. Es existiert keine Abfrage ohne Mandantenfilter; mandantenübergreifende Bezüge existieren nur als ausdrückliche, auditierte Freigabeverknüpfung. *Prüfung:* Datenzugriffsschicht lehnt Abfragen ohne Mandantenprädikat ab.

**INV-20 — Geheimnisse sind schreibbar, nie lesbar.** Kein Geheimnis wird im Klartext an die Oberfläche, in ein Protokoll oder in einen Sollzustandsexport ausgegeben; Konnektorprozesse erhalten nur kurzlebige, auftragsgebundene Referenzen. Kein privater Schlüssel verlässt den Knoten, auf dem er erzeugt wurde. *Prüfung:* Ausgabeprüfung gegen Geheimnismuster in Protokollen und Exporten.

**INV-21 — Konnektoren haben keinen allgemeinen Netzzugang.** Die Ausgangs-Positivliste jedes Konnektorprozesses wird aus seiner Konnektorbindung erzeugt; er erreicht nur das Fremdsystem, für das er eingerichtet ist, und nie die API der Kontrollebene über das Netz. *Prüfung:* Netznamensraumtest mit Zugriffsversuch auf eine nicht gelistete Adresse.

**INV-22 — Die Wurzel-CA ist offline.** Ihr Schlüsselmaterial liegt außerhalb des laufenden Systems und ist nie Teil einer Sicherung des laufenden Systems. Jede Zertifikatsausstellung und jede Sperrung ist ein Ereignis im replizierten Protokoll und vollständig rekonstruierbar. *Prüfung:* Sicherungsinhaltsprüfung; ein Wurzelschlüssel in einer Sicherung bricht den Bau.

**INV-23 — Audit ist unveränderlich und liegt außerhalb des replizierten Kernzustands.** Auditereignisse sind nur anhängbar, hashverkettet, periodisch signiert, werden ausgelagert und sind durch keine Rolle änderbar; sie überleben die Löschung des Objekts. *Prüfung:* Kettenprüfung bei jedem Start und bei jedem Export.

**INV-24 — Schema und Laufzeit ändern sich nie im selben Schritt.** Eine Schemamigration des Sollzustands ist ein eigener, separat rückrollbarer Vorgang. *Prüfung:* Freigabeprüfung, die ein Abbild mit gleichzeitiger Schema- und Laufzeitänderung ablehnt.

**INV-25 — Dienste überleben die Kontrollebene.** Ein Dienst, der einmal erfolgreich gestartet wurde, überlebt den Ausfall der gesamten Kontrollebene und wird lokal neu gestartet, ohne dass eine Verbindung zur Kontrollebene nötig ist. *Prüfung:* Abschalttest der gesamten Kontrollebene mit anschließendem Knotenneustart.

**INV-26 — Jede Standardaufgabe ist ohne Terminal ausführbar.** `atriumctl` benutzt dieselbe API wie die Konsole und ist für den Normalbetrieb nicht erforderlich. *Prüfung:* Aufgabenkatalog wird vollständig über die Konsole automatisiert durchlaufen.

**INV-27 — Kopplungscodes sind einmalig und kurzlebig.** Ein Kopplungscode ist einmalig, höchstens 15 Minuten gültig und nach 5 Fehlversuchen vernichtet; der Kopplungsendpunkt ist nur im Wartemodus offen. *Prüfung:* Wiederverwendungs- und Ratentest.

**INV-28 — Istzustand ist datiert.** Jede Ansicht eines Istzustands nennt den Zeitpunkt der letzten Beobachtung und ob und wie der Istzustand vom Sollzustand abweicht. *Prüfung:* Darstellungstest; eine Istansicht ohne Beobachtungszeitpunkt bricht den Bau.

**INV-29 — Kostenwirkungen sind sichtbar.** Eine Zuweisung, die in einem Fremdsystem eine kaufmännische Folge hat (Lizenzzuweisung, kostenpflichtiges Postfach), zeigt diese Folge in der Wirkungsvorschau vor der Bestätigung. *Prüfung:* Manifeste deklarieren kostenwirksame Aktionen; eine nicht deklarierte, als kostenwirksam bekannte Aktion bricht den Vertragstest.

**INV-30 — Die Produktgrenze ist je Katalogeintrag deklariert.** Für jeden Katalogeintrag steht fest und ist in der Konsole sichtbar, welche Objekte Atrium besitzt und welche Einrichtung in der Fremdoberfläche verbleibt. Fremdoberflächen bleiben Fremdoberflächen; Verwaltungsoberflächen von Kernkomponenten sind abgeschaltet. *Prüfung:* Katalogeintrag ohne Grenzdeklaration wird bei der Freigabe abgelehnt.

**INV-31 — Barrierefreiheit ist Bedingung, nicht Merkmal.** Die Atrium Console ist vollständig ohne Zeigegerät bedienbar, arbeitet mit Bildschirmlesern und erfüllt WCAG 2.2 Stufe AA. *Prüfung:* Automatisierte Regelprüfung plus Tastaturdurchlauf des Aufgabenkatalogs im Bau; ein Verstoß bricht den Bau.

**INV-32 — Zeit ist eine geprüfte Voraussetzung.** Ein Knoten mit unbekannter oder zu großer Uhrenabweichung übernimmt keine Führung, stellt keine Zertifikate aus und schreibt keine Auditereignisse; er meldet den Zustand und schottet sich ab. *Prüfung:* Uhrensprungtest mit injizierter Abweichung oberhalb der Zielgrenze.

---

## 3. Objektmodell (Kurzreferenz)

| Entität | Zweck | Schlüsselattribute | Beziehungen | Lebenszyklus-Zustände |
|---|---|---|---|---|
| **Mandant** | Oberste Eigentums-, Abrechnungs- und Isolationsgrenze; bestimmt die Fehlerdomäne | Kennung, Anzeigename, Isolationsstufe M0–M3, Zwischen-CA-Verweis, Hauptschlüsselverweis, Sicherungsziel, Standardrichtlinien | besitzt alle Objekte außer Plattform und Knoten; belegt Knoten (ab M3 exklusiv) | angelegt → aktiv → gesperrt (lesend) → stillgelegt → aufgelöst (Aufbewahrungsfrist, dann Datenträger freigegeben; Auditkette bleibt) |
| **Person** | Identität eines Menschen; alleinige Quelle aller abgeleiteten Fremdkonten | Kennung, Anzeigename, abgeleiteter Anmeldename, Mandant, Status, Authentisierungsmittel, Gültigkeitszeitraum, Sprache | Mitglied in Gruppen; Träger von Zuweisungen, Postfächern, Mailadressen, Geräten, Zertifikaten | angelegt → aktiv → gesperrt (Anmeldung überall aus, Daten bleiben) → ausgeschieden (Zuweisungen laufen ab, Postfach delegiert) → gelöscht |
| **Gruppe** | Bündelt Personen und Geräte für Rechte, Adressierung und Geltungsbereiche; Ort der Massenwirkung | Kennung, Name, Mandant, Art (Rechte / Verteiler / Geltungsbereich), Mitgliedschaftsregel (statisch oder abgeleitet) | enthält Personen, Geräte und Gruppen (azyklisch erzwungen); Träger von Zuweisungen; Geltungsbereich von Domänen; Berechtigte geteilter Postfächer | angelegt → aktiv → aufgelöst (abgeleitete Zuweisungen sichtbar zurückgebaut) |
| **Rolle** | Rechtebündel innerhalb der Plattform, eines Mandanten oder eines Dienstes | Kennung, Name, Geltung (Plattform / Mandant / Dienst), Rechteliste, Freigabepflicht, Version | referenziert von Zuweisungen; begrenzt, was ein Administrator sieht und tut | vordefiniert oder angelegt → aktiv → versioniert (Änderung ist ein Vorgang mit Wirkungsvorschau) → außer Kraft |
| **Zuweisung** | Trägt die Absicht "dieses Subjekt soll diesen Dienst in dieser Rolle nutzen"; einziger Auslöser aller Versorgungswirkungen; das Objekt hinter dem Schalter | Kennung, Subjekt (Person / Gruppe / Dienstkonto), Ziel (Dienst oder Plattform-/Mandantenbereich), Rolle, Gültigkeit von/bis, Herkunft (direkt oder über Gruppe), Zustand je Zielsystem | erzeugt abgeleitete Artefakte: Fremdkonto, Gruppenmitgliedschaft, Netzfreigabe, ggf. Mailadresse und Zertifikat | gesetzt → in Versorgung (Teilzustände je Zielsystem sichtbar) → wirksam → abgelaufen / entzogen → zurückgebaut oder mit benannten Resten |
| **Dienstkonto** | Nicht-menschliche Identität für Automatisierung und Konnektoren | Kennung, Mandant, Zweck, Anmeldemittel (Zertifikat oder Token), Pflichtablauf, erlaubte Quelladressen | Ziel von Zuweisungen; nie Mitglied in Personengruppen | angelegt mit Pflichtablauf → aktiv → erneuert → abgelaufen (nie stillschweigend verlängert) → widerrufen |
| **Knoten** | Physische oder virtuelle Maschine mit Atrium; trägt Kontrollebene, Dienste und Speicher | Kennung, Name, Zustand, Rollen (Stimmknoten / Mitleser / Zeuge / Dienstträger / Speicherträger / Eingangsträger), Knotenklasse, Fehlerzone, Kapazität, Adressen, Abbildversion (aktiv/inaktiv), Leasestatus, Zertifikat | trägt Dienste und Speicherbereiche; gehört zu Netzzonen; ab M3 exklusiv einem Mandanten zugeordnet | installiert → Wartemodus → gekoppelt → produktiv → geräumt → entkoppelt (Zertifikat gesperrt) |
| **Kopplungsvorgang** | Kurzlebiges Objekt für die Aufnahme eines Knotens aus dem Wartemodus | Kennung, Kopplungscode-Hashwert, Erzeugungszeit, Gültigkeit 15 min, Fehlversuchszähler (max. 5), vorgesehener Zweck | erzeugt bei Erfolg genau einen Knoten; erzeugt in jedem Fall Auditereignisse | erzeugt → verwendet / abgelaufen / gesperrt; nie wiederverwendbar |
| **Gerät** | Verwaltetes Endgerät (kein Knoten); Träger von Zertifikaten und Netzzugangsrechten | Kennung, Name, Art, Eigentümer (Person oder Gruppe), Mandant, Hardwarebindung, Zertifikatsverweise, Netzzone, letzte Meldung | gehört Person/Gruppe; Mitglied einer Netzzone; Ziel von Richtlinien und Domänen-Geltungsbereichen | erfasst → registriert (Zertifikat ausgerollt) → aktiv → verloren/gesperrt (Zertifikat gesperrt, Sperrliste sofort verteilt) → ausgemustert |
| **Netzzone** | Abgegrenzter Netzbereich; Grundeinheit aller Firewall- und Sichtableitungen | Kennung, Name, Mandant, Art (Overlay / VLAN / VRF), Adressbereich, Resolver-Sicht, Default-Deny-Vorgabe | enthält Knoten, Geräte und Dienste; Quelle und Ziel abgeleiteter Regeln; bestimmt die ausgelieferte DNS-Sicht | abgeleitet aus Mandant und Isolationsstufe oder angelegt → aktiv → aufgelöst |
| **Katalogeintrag** | Signierte Beschreibung eines einsetzbaren Fremdprodukts | Kennung, Produktname, Version, Herkunft und Signatur, Abbildverweise, Speicherbereichsdefinition, Datenklassenvorgabe, benötigte Konnektoren, Standardbudget, Standardveröffentlichung, Produktgrenzdeklaration, Migrationsschritte | Vorlage für Dienste; verweist auf Konnektormanifeste | eingereicht → geprüft → freigegeben → abgekündigt (Bestand läuft weiter, keine Neuanlage) → zurückgezogen |
| **Dienst** | Fachliche Einheit, die der Bediener sieht; laufende Instanz eines Katalogeintrags bei einem Mandanten | Kennung, Name, Katalogeintrag und Version, Mandant, Platzierung (abgeleitet, mit Begründung), Ressourcenbudget, Datensicherheitsstufe, Gesundheitszustand | liegt auf Knoten; nutzt Speicherbereiche; besitzt Veröffentlichungen; Ziel von Zuweisungen; bedient von Konnektorbindungen | ausgewählt → wird bereitgestellt → läuft → wird aktualisiert (mit Rücksprungpunkt) → angehalten → entfernt (mit ausdrücklicher Entscheidung über die Daten) |
| **Veröffentlichung** | Verbindet Dienst, Name, Sichtbarkeit und Zugriffskreis; einzige Quelle für Proxyroute, DNS-Eintrag, Zertifikat und Netzfreigabe | Kennung, Hostname, Domänenverweis, Dienstverweis, Sichtbarkeit (intern / extern), Zugriffskreis (Gruppe / Netzzone / öffentlich), Protokoll, Zertifikatsverweis | gehört genau einem Dienst; erzeugt vier Arten abgeleiteter Artefakte | angelegt → bereit (Zertifikat vorhanden, Route aktiv) → gestört → zurückgezogen (Artefakte verfallen, Zertifikat gesperrt) |
| **Domäne** | Namensraum mit Sichtbarkeit und Geltungsbereich | Kennung, Name, Sichtbarkeit (intern / extern / beides), Mandant, Geltungsbereich (Gruppe / Person / Gerät / Netzzone), DNSSEC-Status, Serie (= Sollzustandsversion), Delegierungsstatus | enthält DNS-Einträge; wird von Veröffentlichungen und Maildomänen referenziert; erzeugt bis zu zwei Zoneninstanzen | angelegt → signiert → aktiv → delegiert (extern, DS gesetzt) → stillgelegt → entfernt (mit Auswirkungsplan) |
| **DNS-Eintrag** | Einzelner Name innerhalb einer Domäne mit ausdrücklicher Sichtzugehörigkeit | Kennung, Domänenverweis, Art, Name, Wert, TTL, Sicht (intern / extern / beide), Quelle (handeingegeben oder abgeleitet mit Quellverweis) | gehört einer Domäne; abgeleitete Einträge verweisen auf Veröffentlichung, Maildomäne oder Knoten | erzeugt → aktiv → veraltet (Quelle entfallen) → entfernt |
| **Maildomäne** | Verbindet eine Domäne mit einem Postfachanbieter und erzeugt die Mail-Namenseinträge | Kennung, Domänenverweis, Anbieterbindung (Konnektorbindung), DKIM-Schlüsselverweis, Richtlinienstufe | verweist auf Domäne und Konnektorbindung; erzeugt MX-, SPF-, DKIM-, DMARC-, MTA-STS- und TLS-RPT-Einträge | eingerichtet → verifiziert → aktiv → gestört → entfernt |
| **Postfach** | Postfach unabhängig vom Ablageort (extern gehostet, vor Ort oder intern) | Kennung, Art (persönlich / geteilt), Ablageort (Konnektorbindung), Eigentümer, Berechtigte, Sendeberechtigung, Kontingent | gehört einer Person (persönlich) oder einer Gruppe (geteilt); trägt Mailadressen | angelegt → aktiv → weitergeleitet → archiviert → gelöscht (ausdrücklich als nicht rücknehmbar gekennzeichnet) |
| **Mailadresse** | Adresse, getrennt vom Postfach | Kennung, lokaler Teil, Maildomänenverweis, Postfachverweis, primär/zusätzlich, Sperrfristende | gehört genau einem Postfach und einer Maildomäne | hinzugefügt → aktiv → umgeleitet → entfernt → gesperrt (Sperrfrist, danach neu vergebbar) |
| **CA** | Vertrauensanker der internen zweistufigen PKI | Kennung, Typ (Wurzel offline / Ausgabe / Mandanten-Zwischen), Betreff, Gültigkeit, Schlüsselablage (TPM / Token / offline), Nachfolgerverweis, Sperrlistenverteilpunkt | Wurzel signiert Ausgabe-CA; Ausgabe-CA signiert je Mandant eine Zwischen-CA; Zwischen-CA stellt Endzertifikate aus | erzeugt → aktiv → im Wechsel (Nachfolger parallel verteilt) → nur noch prüfend → abgelaufen |
| **Zertifikat** | Ausgestelltes Endzertifikat für Dienst, Knoten, Gerät oder Person | Kennung, Inhaberverweis, Verwendungszweck, Aussteller, Gültigkeit, Erneuerungsschwelle, Sperrstatus, Schlüsselablage | gebunden an genau ein Inhaberobjekt; referenziert von Veröffentlichungen und Netzzugangsrichtlinien | beantragt (automatisch aus dem Sollzustand) → ausgestellt → aktiv → in Erneuerung → gesperrt / abgelaufen |
| **Konnektorbindung** | Verbindung zu einer konkreten Instanz eines Fremdsystems für einen Mandanten | Kennung, Konnektormanifest und Vertragsversion, Endpunkt, Geheimnisverweis, Mandant, Fähigkeitsliste aus `describe`, Feldeigentumsabbildung, Abgleichintervall, Zustand, letzte erfolgreiche Beobachtung | bedient Dienste, Postfächer und Identitäten; benutzt von Zuweisungen; läuft als eigener Prozess je Mandant und Bindung | eingerichtet → geprüft (`healthcheck`) → aktiv → abweichend (Fehler sichtbar) → ausgesetzt → entfernt (wahlweise Deaktivierung oder Belassen der Fremdkonten) |
| **Speicherbereich** | Persistenter Datenbereich eines Dienstes mit Datensicherheitsstufe | Kennung, Dienstverweis, Größe, Datensicherheitsstufe (Lokal / Gespiegelt / Synchron gespiegelt), Replikatstandorte, Verschlüsselungsschlüsselreferenz, Sicherungsplan, Replikationsstand | gehört genau einem Dienst; liegt auf Knoten; begrenzt zulässige Platzierungen | angelegt → eingebunden → repliziert → Stufe geändert (Vorgang mit Datenverschiebung) → freigegeben (Aufbewahrungsfrist) → vernichtet (Schlüssel gelöscht) |
| **Wiederherstellungspunkt** | Prüfbarer Zustand zu einem Zeitpunkt: Sollzustandsexport oder Speicherbereichsmomentaufnahme | Kennung, Typ, Zeitpunkt, Prüfsumme, Signatur, Ablageort, Aufbewahrungsende, Prüfstatus mit Datum der letzten erfolgreichen Prüfung | verweist auf Mandant und Speicherbereich oder auf die gesamte Installation | erzeugt → geprüft → verwendbar → abgelaufen; ungeprüft ist ein sichtbarer Zustand, kein Standard |
| **Richtlinie** | Mandanten- oder plattformweite Vorgabe, die Vorbelegungen erzeugt und Entscheidungen einspart | Kennung, Geltung, Gegenstand, Wert, Erzwingung (hart / weich), Begründungspflicht bei Abweichung, Version | wirkt auf Personen, Geräte, Dienste, Veröffentlichungen, Speicherbereiche; Quelle jeder Vorbelegung | gesetzt → aktiv → versioniert (Änderung zeigt vorab die Menge betroffener Objekte) → außer Kraft |
| **Vorgang** | Jede beabsichtigte Änderung als Einheit mit Plan, Freigabe, Fortschritt, Ergebnis und Rücknahme | Kennung, Auslöser (Person / Dienstkonto / Regel), Mandant, betroffene Objekte, Wirkungsvorschau, Freigabestatus, Teilzustände je Zielsystem, Ausführungszeit, Rücksprungverweis, Korrelationskennung | verweist auf alle geänderten Objekte, alle beteiligten Konnektorbindungen und das erzeugte Auditereignis | entworfen → Wirkungsvorschau berechnet → zur Freigabe vorgelegt → freigegeben → in Ausführung → abgeschlossen / teilweise fehlgeschlagen mit benannten Resten → zurückgenommen |
| **Auditereignis** | Unveränderlicher Nachweis jeder schreibenden Operation, jeder Anmeldung, jeder Zertifikatsausstellung und jedes Notzugriffs | Zeitstempel, Akteur, Mandant, Aktion, Objektverweis, Vorher/Nachher, Ergebnis, Korrelationskennung, Vorgängerhashwert | verweist auf Vorgang und Objekt; überlebt die Löschung des Objekts; liegt außerhalb des replizierten Kernzustands | geschrieben → versiegelt (periodische Signatur) → ausgelagert → nach Aufbewahrungsfrist verdichtet; nie änderbar |
| **Geheimnis** | Zugangsdaten und Schlüsselmaterial für Konnektorbindungen und Dienste; im Sollzustand nur als Referenz | Kennung, Verweisname, Mandant, Typ, Besitzer, Erstellungszeit, Wechselfrist, letzte Verwendung, Ablageort (TPM / Token / verschlüsselter Speicher) | referenziert von Konnektorbindungen, Diensten und Speicherbereichen | hinterlegt → in Benutzung → Wechsel fällig (sichtbare Aufgabe) → gewechselt → widerrufen |
| **Abgeleitetes Artefakt** | Gemeinsamer Typ für Firewallregel, Proxyroute, DNS-Eintrag, Zertifikatsantrag und Fremdkonto; trägt immer seine Quelle | Kennung, Art, Quellobjektverweis, erzeugter Inhalt (Hashwert), Anwendungszeitpunkt, Zustand | erzeugt aus genau einer Quelle; nie direkt editierbar; verfällt mit der Quelle | berechnet → atomar angewandt → aktiv → veraltet → zurückgenommen (bei Fehler Rücksprung auf den vorherigen Satz, Default-Deny bleibt) |

### Beziehungsdiagramm (Textform)

```
                                   +-----------+
                                   |  Mandant  |  Isolationsstufe M0..M3
                                   +-----+-----+
                                         | besitzt (Pflichtbezug jedes Objekts)
   +-------------+--------------+--------+--------+--------------+---------------+
   |             |              |                 |              |               |
+--v---+     +---v---+      +---v----+       +----v----+    +----v----+    +-----v-----+
|Person|<--->|Gruppe |      | Gerät  |       | Domäne  |    | Dienst  |    |Konnektor- |
+--+---+ Mit-+---+---+      +---+----+       +----+----+    +----+----+    | bindung   |
   |    glied    |              |                 |              |         +-----+-----+
   |             |              | Eigentümer      | enthält      |               |
   |             |              |                 |              |               |
   |  +----------+--------------+                 |              |               |
   |  |  Subjekt einer                      +-----v------+       |               |
   +--+----------+                          |DNS-Eintrag |       |               |
      |          |                          +-----^------+       |               |
  +---v------+   |                                | abgeleitet   |               |
  |Zuweisung |---+-- Rolle                        |              |               |
  +---+------+                               +----+-------+      |               |
      | erzeugt                              |Veröffent-  |<-----+ besitzt       |
      |                                      | lichung    |                      |
      |         +----------------------------+-----+------+                      |
      |         |              |                   |                             |
      v         v              v                   v                             |
  +---+---------+--------------+-------------------+-----+                       |
  |          Abgeleitetes Artefakt                       |<----------------------+
  | (Fremdkonto | Firewallregel | Proxyroute |           |   erzeugt/verwaltet
  |  DNS-Eintrag | Zertifikatsantrag)                    |
  +-------------------------+----------------------------+
                            | Zertifikat ausgestellt von
                     +------v------+
                     |     CA      |  Wurzel(offline) -> Ausgabe -> Mandanten-Zwischen
                     +------+------+
                            | stellt aus
                     +------v------+
                     | Zertifikat  |--> Inhaber: Dienst | Knoten | Gerät | Person
                     +-------------+

  +--------+ trägt  +---------+ nutzt  +----------------+ hat  +---------------------+
  | Knoten |------->| Dienst  |------->| Speicherbereich|----->| Wiederherstellungs- |
  +---+----+        +---------+        +----------------+      |       punkt         |
      |  Rollen: Stimmknoten|Mitleser|Zeuge|Dienst|Speicher|Eingang +-----------------+
      | Mitglied in
  +---v-------+
  | Netzzone  |<--- Gerät, Dienst, Knoten; bestimmt Resolver-Sicht und Firewallableitung
  +-----------+

  +-------------------+ erzeugt bei Erfolg +--------+
  | Kopplungsvorgang  |------------------->| Knoten |
  +-------------------+                    +--------+

  +-----------+ liefert Vorbelegung für    +-------------+ protokolliert in
  | Richtlinie|--------------------------->|   Vorgang   |--------------------+
  +-----------+                            +------+------+                    |
                                                  | ändert                    v
  +--------------+ referenziert von        +-------v--------+        +----------------+
  |  Geheimnis   |<------------------------| jedes Objekt   |        | Auditereignis  |
  +--------------+  Konnektorbindung,      +----------------+        | (hashverkettet,|
                    Dienst, Speicherbereich                          |  außerhalb Raft)|
                                                                     +----------------+

  Dienstkonto  --> Subjekt einer Zuweisung (nie Mitglied einer Personengruppe)
  Maildomäne   --> Domäne + Konnektorbindung; Postfach --> Person|Gruppe; Mailadresse --> Postfach
  Katalogeintrag --> Vorlage für Dienst; verweist auf Konnektormanifeste
```

### Identifikatorformat

| Regel | Festlegung |
|---|---|
| Primärschlüssel | Jedes Objekt trägt eine unveränderliche Kennung als ULID: 26 Zeichen Crockford-Base32, 48 bit Zeitanteil, 80 bit Zufall, lexikographisch nach Erzeugungszeit sortierbar. |
| Externe Form | `urn:atrium:<entitätstyp>:<ulid>`, Entitätstyp kleingeschrieben und einwortig (`person`, `dienst`, `veroeffentlichung`, `konnektorbindung`). |
| Verweise | Beziehungen verweisen ausschließlich auf Kennungen, niemals auf Namen. Umbenennungen sind folgenlos. |
| Mandantenbezug | Jede Kennung ist global eindeutig; der Mandant ist ein Pflichtfeld des Objekts, nicht Teil der Kennung. |
| Technische Namen | Kleinbuchstaben, Ziffern, Bindestrich; erstes Zeichen ein Buchstabe; höchstens 63 Zeichen je Label (DNS-tauglich). Werden abgeleitet, nicht eingegeben. |
| Anzeigenamen | Frei, Unicode, normalisiert nach NFC, je Mandant und Entitätstyp eindeutig. |
| Anmeldenamen | Abgeleitet aus dem Anzeigenamen nach Mandantenrichtlinie, kollisionsauflösend, nach Erzeugung unveränderlich. |
| Kopplungscode | 12 Zeichen Crockford-Base32, davon 10 Zufallszeichen und 2 Prüfzeichen, dargestellt als `XXXX-XXXX-XXXX`. |
| Korrelationskennung | Eine ULID je Vorgang, in jedem Auditereignis, jedem Konnektoraufruf und jeder Protokollzeile mitgeführt. |

### Namenskonventionen

| Gegenstand | Konvention |
|---|---|
| API-Felder | `snake_case`, deutsch, ohne Umlaute (`anzeige_name`, `datensicherheitsstufe`). |
| API-Pfade | `/v1/<entitätstyp-plural>/<ulid>`, Plural deutsch (`/v1/personen`, `/v1/veroeffentlichungen`). |
| Systembenutzer | `atrium-<rolle>` bzw. `atrium-conn-<mandant-kurz>-<konnektor>`. |
| Erzeugte systemd-Units | `atrium-dienst-<ulid-kurz>.service`, immer generiert, nie handgepflegt. |
| ZFS-Datasets | `<pool>/atrium/<mandant-kurz>/<dienst-kurz>/<speicherbereich-kurz>`. |
| Interne DNS-Namen | `<dienst>.<mandant>.<basisdomäne>`; Knotennamen `<knoten>.knoten.<basisdomäne>`. |
| Auditereignistypen | `<objekt>.<verb>` im Perfekt (`person.angelegt`, `zertifikat.gesperrt`). |

### Versionierung des Schemas

| Regel | Festlegung |
|---|---|
| Format | `schema_version` als `MAJOR.MINOR`. Keine Patch-Ebene. |
| MINOR | Rein additiv: neue optionale Felder, neue Entitäten, neue Aufzählungswerte mit definiertem Rückfallverhalten. Ältere Kontrollebenen lesen weiter. |
| MAJOR | Alles andere. Erfordert eine Schemamigration als eigenen, separat rückrollbaren Vorgang (INV-24). |
| Kompatibilitätsfenster | Die Kontrollebene liest `MAJOR` und `MAJOR-1`. Ein Sollzustandsexport mit älterem `MAJOR` wird über eine ausdrückliche Migration importiert, nie stillschweigend. |
| Kennzeichnung | Jeder Sollzustandsexport, jeder Wiederherstellungspunkt und jede API-Antwort nennt `schema_version`. |
| Konnektorvertrag | Eigene Versionsreihe `vertrag_version`, semantisch versioniert, unabhängig vom Schema; der Kern unterstützt die laufende und die vorhergehende Hauptversion. |

---

## 4. Technologiefestlegungen

| Bereich | Festlegung | Verworfene Alternative | Grund in einem Satz |
|---|---|---|---|
| **1 Zustandsspeicher** | Eingebetteter Raft-Speicher in atrium-core: hashverkettetes Änderungsprotokoll des Sollzustands als autoritative Quelle, daraus deterministisch materialisiertes lokales Lesemodell in einer eingebetteten SQL-Engine (SQLite-Klasse, WAL, eigenes ZFS-Dataset); Ein-Knoten-Betrieb ist eine Raft-Gruppe mit einem Stimmmitglied und kein Sondermodus. | PostgreSQL mit Replikation; etcd | Ein eingebetteter Speicher fügt keine Prozessgrenze, kein zweites Sicherungsverfahren und keine zirkuläre Abhängigkeit (Kontrollebene braucht Datenbank, deren Failover braucht ein Quorum) hinzu, und das Lesemodell lässt sich bei Schemaänderungen neu bauen statt migrieren. |
| **1a Stimmzahl** | 1, 3 oder 5 Stimmknoten, niemals 2 oder 4; Wachstum 1 → 3 → 5 durch Mitgliedschaftsänderung im laufenden Betrieb ohne Datenmigration; weitere Verwaltungsknoten sind Mitleser. | 2 oder 4 Stimmknoten | Vier Stimmknoten sind rechnerisch schlechter verfügbar als drei (0,99941 gegen 0,99970), weil die Mehrheitsschwelle steigt, ohne dass ein weiterer Ausfall toleriert wird. |
| **1b Zwei-Standort-Fall** | Rolle **Zeuge**: stimmberechtigtes Raft-Mitglied ohne Dienst- und Speicherlast, lauffähig auf kleinster Hardware oder an einem dritten Standort; zählt gleichzeitig als DRBD-Quorumszeuge. | Zwei Stimmknoten mit Handentscheidung; automatischer Weiterbetrieb der Minderheitsseite | Zwei Maschinen plus Zeuge sind der einzige Weg, bei zwei Standorten automatischen Ausfallschutz zu bekommen, ohne dem Bediener im Störfall eine Split-Brain-Entscheidung aufzubürden. |
| **2 Dienstausführung** | Podman mit Quadlet-Units unter systemd, ausschließlich von atrium-node aus dem Sollzustand erzeugt, plus eigener Platzierungsdienst in atrium-core; rootless wo das Produkt es zulässt, je Dienst eigene Benutzerkennung, eigene Netzzone, eigener Speicherbereich; OCI-Abbilder sind das Verteilformat. | Verstecktes k3s; systemd-nspawn | Kubernetes lässt sich nicht verstecken, sondern nur verdecken, und bringt ein zweites Quorum, eine zweite PKI, ein zweites Netzmodell und einen zweiten DNS-Dienst mit, während nspawn kein signiertes Abbildverteilformat für hunderte Fremdprodukte hat. |
| **2a Platzierung** | Deterministische, gierige Bewertung mit erklärbarem Gleichstandsbruch; jede Platzierung trägt eine in einem Satz anzeigbare Begründung; Antiaffinität entlang Fehlerzonen; Räumen ist eine sichtbare eigene Handlung. | Scheduler mit Vorbelegung und Verdrängung | Bei höchstens 32 Knoten und 500 Dienstinstanzen sind 16.000 Bewertungen vollständig durchrechenbar, sodass Erklärbarkeit ohne Leistungsverlust wichtiger ist als Optimalität. |
| **3 Speicherreplikation** | ZFS in jeder Ausbaustufe als lokales Dateisystem; gestaffelt: 1 Knoten lokale Momentaufnahmen plus externe Auslagerung, 2 Knoten oder 2+Zeuge asynchrones ZFS send/recv, ab 3 Knoten zusätzlich DRBD/LINSTOR für als synchron markierte Speicherbereiche (3 Replikate oder 2+Zeuge), ab 8 Knoten mit mindestens 3 dedizierten Speicherknoten optional Ceph (RBD) auf eigenem Speichernetz. | Ceph als Standard; nur DRBD; nur ZFS send/recv | Unterhalb von acht Knoten sättigt eine Ceph-Wiederherstellungswelle die verbleibenden Knoten, während DRBD ohne Prüfsummendateisystem und ZFS send/recv ohne synchrone Replikation je allein eine geforderte Eigenschaft nicht erbringt. |
| **3a Schwellenwert synchron** | Synchrone Replikation ist ab 3 Knoten (oder 2+Zeuge) verfügbar, nicht ab 4. | Erst ab 4 Knoten | DRBD-Quorum verlangt eine Mehrheit, und die entsteht genau bei drei Stimmen; jede höhere Schwelle ist willkürlich. |
| **3b Bedienung des Speichers** | Genau ein Feld je Dienst: Datensicherheitsstufe **Lokal / Gespiegelt / Synchron gespiegelt**; welche Technik das erfüllt, leitet der Kern aus der Knotenzahl ab und zeigt das daraus folgende RPO am Dienst an. | Auswahl der Replikationstechnik durch den Bediener; stille Ableitung aus dem Katalogeintrag | Eine Technikauswahl bricht die Drei-Entscheidungs-Regel, und eine stille Ableitung lässt die Oberfläche RPO 0 versprechen, wo asynchron repliziert wird. |
| **4 Kopplung** | SPAKE2 (RFC 9382) aus einem 12-stelligen Kopplungscode in Crockford-Base32 (10 Zufallszeichen = 50 bit, 2 Prüfzeichen), angezeigt als `XXXX-XXXX-XXXX`, gebunden an den TLS-Exporter der Verbindung; danach Knotenzertifikat aus der Ausgabe-CA, TPM-gebunden wo vorhanden, ab da ausschließlich gegenseitig authentisiertes TLS. | QR-Code mit öffentlichem Schlüssel als eigenständiges Verfahren; signiertes Aufnahmetoken als Primärverfahren | Nur ein passwortauthentisierter Schlüsselaustausch macht ein kurzes, vorlesbares Geheimnis sicher, weil ein Mitschnitt kein Offline-Raten erlaubt und jeder Versuch einen zählbaren Online-Lauf kostet. |
| **4a Kopplung, Sonderwege** | QR-Code ist reine Eingabeerleichterung für denselben Code, kein zweites Verfahren. Für unbeaufsichtigte Massenausrollung (PXE, cloud-init) und für gemietete Server mit anbieterseitig mitlesbarer Fernkonsole: einmalig verwendbares, kurzlebiges Aufnahmetoken mit CA-Fingerabdruck-Pinning, über einen vom Kunden als vertrauenswürdig erklärten Kanal ausgeliefert, in der Konsole ausdrücklich als schwächeres Verfahren gekennzeichnet und auditiert. | Kein Sonderweg | Ohne einen automatisierbaren Weg wird die Massenausrollung improvisiert, und der anbieterseitig mitlesbare Konsolenkanal entwertet SPAKE2 genau im praktisch häufigsten Mietserverfall. |
| **5 Konnektorvertrag** | Genau ein Vertrag: Konnektor = eigener Prozess, gRPC über einen Unix-Socket, kein Netzzugang zum Kern; Operationen `describe`, `observe`, `plan`, `apply` (mit Idempotenzschlüssel), `healthcheck`; der generische deklarative Treiber ist selbst einer dieser Prozesse und bedient über Manifeste beliebig viele Fremdsysteme. Aktivierungsgesteuert, nicht dauerlaufend; Leerlaufabschaltung nach 10 min. | WASM-Modul im Kern; zwei getrennte Vertragsstufen | Ein Modul im Kern teilt dessen Fehlerdomäne und Netzrechte und zwingt dazu, TLS, OAuth, LDAP und SQL als Wirtsfunktionen nachzubauen, während zwei Verträge zwei Lernkurven und zwei Testverfahren erzeugen, obwohl das Manifest nur eine Implementierung hinter dem einen Vertrag ist. |
| **5a Konnektor-Sandkasten** | Eigener Systembenutzer je Konnektorprozess, systemd-Härtung (`NoNewPrivileges`, `ProtectSystem=strict`, `PrivateTmp`, seccomp), eigener Netznamensraum mit aus der Bindung erzeugter Ausgangs-Positivliste, Geheimnisse nur als kurzlebige auftragsgebundene Referenz, je Mandant und Bindung ein eigener Prozess. | Gemeinsamer Konnektorprozess über Mandanten hinweg | Ein gemeinsamer Prozess ist eine Vermischungsgefahr, die sich weder prüfen noch nachweisen lässt. |
| **6 Mandantenisolation** | Vier Stufen, je Mandant eine Auswahl, online steigerbar, Absenkung nur mit ausdrücklicher Bestätigung und Auditeintrag. **M0 logisch:** eigener Namensraum, eigene Rechteprüfung, eigener Hauptschlüssel, eigene Zwischen-CA, geteilte Knoten und geteilter Eingang. **M1 netzgetrennt logisch:** zusätzlich eigenes Overlay-Segment, eigene Firewallzone, eigener Eingangs-Listener mit eigenem Zertifikat, eigene Resolver-Sicht, eigene Ausgangsadresse. **M2 netzgetrennt physisch:** zusätzlich VLAN/VRF bis zur Netzkarte, eigene Eingangs-IP, eigene autoritative Zoneninstanzen. **M3 dediziert:** zusätzlich exklusiv zugeordnete Knotenklasse, eigene Speicherpools, eigene Sicherungsziele. | Nur logische Trennung; Isolation als Aufsatz aus n Installationen; eigene Kontrollebene je Mandant als Stufe | Eine eigene Kontrollebene je Mandant hebt den zentralen Produktnutzen und die geforderte mandantenübergreifende Übersicht auf und ist deshalb keine Isolationsstufe, sondern eine getrennte Installation außerhalb dieses Modells. |
| **6a Mandanten-PKI** | Eigene Zwischen-CA je Mandant bereits ab M0. | Gemeinsame Ausgabe-CA bis M2 | Ein kompromittierter Mandant darf unter keiner Stufe für fremde Namen ausstellen können. |
| **7 Eingangsproxy** | Envoy als L7-Datenebene, ausschließlich dynamisch über einen in atrium-core eingebauten xDS-Server gesteuert; Zertifikate über SDS direkt in den Speicher; keine Konfigurationsdatei, kein Neuladen; jede Konfiguration ist ein versionierter Snapshot, Rückrollen ist das Ausliefern des vorherigen Snapshots; SNI-basierte Listener je Mandant ab M1 in Envoy selbst. | nginx; eigener Rust-Proxy; vorgeschalteter eigener L4/SNI-Verteiler | Nur eine dynamisch programmierte Datenebene hält die Invariante "keine Konfigurationsdateien" durch, und ein eigener L7-Proxy wäre eine dauerhafte, unauthentisiert erreichbare CVE-Fläche ohne Produktvorteil. |
| **7a L3/L4-Filter** | nftables, Regelsätze vollständig aus dem Objektgraphen erzeugt und atomar getauscht, Default-Deny in jedem Fehlerzustand. | Handgepflegte Regeln; ufw | Eine Regel, die nicht aus einem Objekt folgt, ist per Invariante nicht existent. |
| **8 Autoritativer DNS** | Knot DNS, ausschließlich über seine Steuerschnittstelle transaktional bespielt, mit automatischer DNSSEC-Signierung und automatischem Schlüsselwechsel; die Seriennummer ist die Sollzustandsversion; jeder Verwaltungsknoten erzeugt dieselbe Zone aus demselben Sollzustand und ist autoritativ, es gibt keinen Zonentransfer als Fehlerquelle. | BIND9; PowerDNS mit SQL-Backend; ein Prozess für autoritativ und rekursiv | Ein SQL-Backend wäre eine zweite Wahrheitsquelle neben dem Sollzustand, BIND9 hat eine deutlich größere Angriffs- und Konfigurationsfläche, und ein gemeinsamer Prozess vermischt Cache und autoritative Daten. |
| **8a Rekursiver Resolver** | Knot Resolver als validierender rekursiver Resolver, getrennter Prozess auf getrennten Adressen, nur an interne Adressen gebunden, keine offene Rekursion, eine Instanz je Netzzone mit eigener Sicht. | Unbound; dnsmasq | Gleiche Herkunft und Freigabekadenz wie der autoritative Dienst halbieren die Zahl der zu verfolgenden Sicherheitsmeldungen und Aktualisierungspfade bei gleichwertiger Validierungs- und Sichtfähigkeit. |
| **8b Split-Horizon** | Eine Domäne erzeugt bis zu zwei Zoneninstanzen (intern, extern); jeder DNS-Eintrag trägt seine Sichtzugehörigkeit ausdrücklich als Attribut; die Zuordnung "für wen gilt die Domäne" wird auf Netzzone und Gerätezertifikat abgebildet, nicht auf den angemeldeten Nutzer. | Views im autoritativen Dienst; Ableitung aus der Nutzersitzung | Namensauflösung findet am Gerät und im Netz statt, nicht in einer Sitzung, und ohne Sichtattribut je Eintrag laufen die beiden Instanzen still auseinander. |
| **8c Grenze der DNS-Zusage** | Die Konsole erklärt ausdrücklich, dass ein Geltungsbereich nur für verwaltete Geräte in kontrollierten Netzen durchsetzbar ist; verschlüsselte Namensauflösung im Browser wird auf verwalteten Geräten per Richtlinie auf den internen Resolver gezwungen. | Zusage ohne Einschränkung | Eine Zusicherung, die die Technik nicht einlöst, ist eine Falschaussage in der Oberfläche. |
| **9 Identitätsanbieter** | Wahrheitsquelle ist immer der Objektgraph in atrium-core. Kanidm eingebettet als Protokollkopf für OIDC/OAuth2, LDAP(S) und Passkey/MFA; eigene Verwaltungsoberfläche abgeschaltet, Schreibzugriff ausschließlich durch den Reconciler. | Eingebettetes Keycloak; vollständige Eigenentwicklung | Keycloak bringt eine zweite vollwertige Datenbank mit Schemamigration und ein eigenes Objektmodell mit, dessen Begriffe bei jedem Fehler durchschlagen, und ist selbst kein LDAP-Server; eine Eigenentwicklung des Protokollstapels ist mehrjährig und ohne Interoperabilitätsnachweis nicht belastbar. |
| **9a SCIM** | SCIM-Server und SCIM-Client werden direkt von atrium-core erbracht. | SCIM über den Protokollkopf | Der Kern ist ohnehin die Wahrheitsquelle, und SCIM ist ein schlankes REST/JSON-Protokoll ohne eigenen Zustand. |
| **9b RADIUS / EAP-TLS** | Eigener, generiert konfigurierter RADIUS-Dienst (FreeRADIUS), der ausschließlich gegen die interne Ausgabe-CA und deren Sperrliste prüft; RadSec für standortübergreifende Strecken; nur aktiv, wenn Netzzugang genutzt wird. | RADIUS im Identitätsanbieter | EAP-TLS braucht eine CA und eine Sperrprüfung, keinen Identitätsanbieter, und die direkte Kopplung an die PKI spart eine Abhängigkeit und eine Divergenzquelle. |
| **9c SAML** | Optionaler Protokollvermittler OIDC → SAML, nur bei Bedarf eingeschaltet, mit ausdrücklich dokumentierter Einschränkung bei eigenwilligen Signaturprofilen und Attributformaten. | SAML im Kern; SAML durch den Protokollkopf vorausgesetzt | Ein Vermittler hält SAML aus dem Kern heraus und macht die Kompatibilitätsgrenze sichtbar, statt sie zu verschweigen. |
| **9d Identitätsabgleich** | Ständiger Abgleich zwischen Identitätsobjekt im Kern und Konto im Protokollkopf mit sichtbarer Abweichungsanzeige; der Kern gewinnt jede Abweichung. | Einmalige Bereitstellung ohne Abgleich | Zwei Replikationssysteme (Raft und der Speicher des Protokollkopfs) können divergieren, und nur ein sichtbarer Abgleich macht das erkennbar. |
| **10 Objektmodell** | Strikte Trennung von Sollzustand (quorumpflichtig, versioniert, auditiert) und Istzustand (beobachtet, datiert, nie Eingabe für Entscheidungen außer zur Abweichungsanzeige); zentrale Absichtsentität ist die **Zuweisung**; alle Wirkungen sind abgeleitete Artefakte mit Quellverweis; keine Kaskadenlöschung. | Gemeinsames Modell für Soll und Ist; direkte Verknüpfung Person zu Fremdkonto; namensbasierte Verweise | Ohne Absichtsobjekt ist Wunsch nicht von Umsetzung unterscheidbar und ein vollständiger Rückbau unmöglich, und Beobachtungen im quorumpflichtigen Zustand würden das Replikationsprotokoll fluten. |
| **11 Konsolennavigation** | Genau acht Bereiche nach Absicht des Bedieners, dazu eine dauerhaft sichtbare Suche, die kein Navigationsbereich ist; kein Technikbereich für Firewall, PKI, Proxy oder Integrationen. | Technikorientierter Schnitt; Assistenten als oberste Ebene; nur Suche | Ein Technikschnitt zwingt den Bediener, die Lösung vor dem Problem zu kennen, und ein eigener Technikbereich lädt zu der Handpflege ein, die die Architektur verbietet. |
| **12 Kennzahlen** | Jede Kennzahl ist ausdrücklich als **Zielwert** gekennzeichnet und entweder aus einer Protokolleigenschaft hergeleitet oder aus einer benannten Annahme berechnet; keine Zahl im gesamten Whitepaper ist eine behauptete Messung; die Drei-Entscheidungs-Regel und die Begriffs-Positivliste werden im Bau geprüft. | Richtwerte ohne Prüfung; Klickzahlen statt Entscheidungen; Messwerte aus Vergleichsprodukten | Ungeprüfte Zusagen fallen beim ersten Zusatzfeld unbemerkt, und Klicks lassen sich durch Oberflächentricks senken, ohne dass der Nutzer weniger entscheidet. |
| **Telemetrie** | OpenTelemetry als internes Format für Metriken, Spuren und strukturierte Protokolle; Empfang über OTLP durch einen in atrium-core eingebauten Kollektor; Metriken in einem lokalen Zeitreihenspeicher je Verwaltungsknoten, niemals im replizierten Zustand; Ausleitung an ein externes Ziel als Konnektorbindung. | Prometheus-Server als Pflichtbestandteil; Metriken im Sollzustand | Metriken im Konsens fluten das Protokoll und sprengen Momentaufnahmen, und ein eigener Metrikdienst wäre ein weiterer Dauerprozess mit eigener Sicherung. |
| **Protokollierung** | Strukturierte Ereignisse als JSON mit Korrelationskennung; systemd-journald lokal; Export nach RFC 5424 (Syslog) über TLS; Auditstrom getrennt vom Betriebsprotokoll, hashverkettet und periodisch signiert mit Zeitstempel nach RFC 3161. | Gemeinsamer Strom für Betrieb und Audit; unstrukturierte Textprotokolle | Ein Auditnachweis muss unveränderlich und getrennt aufbewahrbar sein, ein Betriebsprotokoll muss verdichtbar und wegwerfbar sein. |
| **Datenbank für Anwendungsdaten** | PostgreSQL als Standard-Datenbankdienst für Katalogeinträge, betrieben als gewöhnlicher Dienst mit Speicherbereich der Stufe "Synchron gespiegelt", niemals als Bestandteil der Kontrollebene; SQLite für Katalogeinträge mit geringem Bedarf; MariaDB nur, wo ein Katalogeintrag es zwingend verlangt. | Eine gemeinsame Datenbankinstanz für Kontrollebene und Anwendungen; MySQL/MariaDB als Standard | Die Kontrollebene darf keine externe Datenbank haben (Entscheidung 1), und PostgreSQL ist die einzige quelloffene Engine, die die Anforderungen der überwiegenden Zahl der Katalogeinträge ohne Zusatzannahmen erfüllt. |
| **Overlay-Netz** | WireGuard als Vollvermaschung zwischen allen Knoten; Schlüssel je Knoten lokal erzeugt und an das Knotenzertifikat gebunden, Rotation zusammen mit dem Zertifikat; je Mandant ab M1 ein eigenes Segment mit eigenem Schlüsselmaterial; VXLAN nur, wo L2-Semantik nachweislich gebraucht wird. | IPsec; ein CNI-Plugin; unverschlüsseltes Unterlagerungsnetz | WireGuard ist klein genug, um vollständig geprüft zu werden, und benötigt keinen Zustandsdienst, während eine Vollvermaschung bei höchstens 32 Knoten ohne Routenverteiler auskommt. |
| **RADIUS** | Siehe 9b: eigener FreeRADIUS-Dienst, Konfiguration vollständig erzeugt, ausschließlich EAP-TLS gegen die interne PKI, RadSec für Weitverkehrsstrecken; 802.1X-Zuordnung von Gerät zu Netzzone folgt aus dem Objektgraphen. | Passwortbasierte EAP-Verfahren; RADIUS gegen den Identitätsanbieter | Gerätebezogener Netzzugang gehört an das Gerätezertifikat, nicht an ein Nutzerkennwort. |
| **Sicherungswerkzeug** | ZFS send/recv für Knoten-zu-Knoten-Replikation; restic für die externe, verschlüsselte, deduplizierende Auslagerung mit eigenem Schlüssel je Mandant; Sollzustandsexport als eigenständige signierte Datei unabhängig von beiden. | Abbildsicherung der Knoten; nur Raft-Momentaufnahme als Sicherung | Eine Abbildsicherung scheitert an geänderter Hardware und erlaubt keine selektive Wiederherstellung, und eine Raft-Momentaufnahme ist ein internes Format, dessen Lesbarkeit an die Version der Kontrollebene gebunden ist. |
| **Abbild- und Bauverfahren** | Unveränderliches A/B-Wurzeldateisystem je Knoten: zwei vollständige, signierte Systemabbilder mit dm-verity, Nutzdaten und Sollzustand getrennt davon; Aktualisierung schreibt in die inaktive Hälfte und schaltet beim Neustart um; bleibt das Gesundheitssignal aus, fällt der Knoten selbsttätig zurück; Ausrollung gestaffelt, Verwaltungsknoten zuletzt und nie gleichzeitig; Bau reproduzierbar aus Ubuntu-LTS-Paketen. | Paketbasiertes Update in place mit Momentaufnahme-Rücksprung; gleichzeitige Aktualisierung aller Knoten | Ein Rücksprung, der vom Dateisystemzustand und vom erfolgreichen Booten abhängt, ist genau dann unbrauchbar, wenn er gebraucht wird, und eine gleichzeitige Aktualisierung ist die gemeinsame Ausfallursache, die jede Verfügbarkeitsrechnung entwertet. |
| **Basis** | Ubuntu LTS; Zielbasis 26.04 LTS, Übergangsbasis 24.04 LTS; GA-Kernel der LTS-Serie als Standard, HWE-Kernel nur für Hardware, die er nachweislich benötigt; Treiber und Hardwareunterstützung kommen unverändert aus Ubuntu. | Eigener Kernel; rollende Basis | Der einzige belastbare Vorteil der Ubuntu-Basis ist die fremdgepflegte Treiber- und Sicherheitsversorgung, und jede eigene Kernelvariante gibt ihn auf. |
| **Sprachen** | atrium-core, atrium-node und atriumctl in Rust; der generische Konnektortreiber und die mitgelieferten Konnektoren in Rust; die Konsole als Web-Oberfläche gegen die öffentliche API. | C/C++ für Kernkomponenten; Go für den Kern | Speichersicherheit ist an der Kontrollebene und am Knotenagenten die Voraussetzung dafür, dass die verbleibende C++-Fläche (Envoy) überhaupt vertretbar ist. |
| **Signaturverfahren der Lieferkette** | Ed25519 (RFC 8032) für alle Signaturen an Systemabbildern, Katalogeinträgen, Konnektormanifesten, Sollzustandsexporten und Wiederherstellungspunkten; kanonische Serialisierung nach RFC 8785 vor der Signatur; Eintrag jeder Freigabe in ein anhängbares, hashverkettetes Transparenzprotokoll mit Zeitstempel nach RFC 3161; Stückliste je Abbild sowohl als SPDX als auch als CycloneDX; UEFI Secure Boot und Messung in das TPM 2.0 auf dem Knoten. | Unsignierte Abbilder; nur Prüfsummen; nur ein Stücklistenformat | Ohne Transparenzprotokoll ist eine zurückdatierte oder gezielt an einen einzelnen Kunden ausgelieferte Freigabe nicht erkennbar, und beide Stücklistenformate werden von unterschiedlichen Abnehmern verlangt. |
| **Kryptographische Grundzusagen** | TLS 1.3 nach außen und innen; X25519 für Schlüsselaustausch, ChaCha20-Poly1305 und AES-GCM als Verkehrsverfahren, SHA-2 für Hashketten, Argon2id für Kennwortableitung, HKDF für Schlüsselableitung; Algorithmen sind je Verwendungszweck versioniert und austauschbar. | Feste Algorithmenwahl ohne Versionierung | Ohne benannte Verfahrensversionen je Verwendungszweck ist ein späterer Wechsel eine Neuentwicklung statt einer Migration. |
| **Post-Quanten-Vorsorge** | Alle Signatur- und Schlüsselaustauschstellen tragen eine Verfahrenskennung; Zielbild ist hybrider Schlüsselaustausch (X25519 + ML-KEM) an Außenkanten und ML-DSA oder SLH-DSA als zusätzliche Signatur an Lieferkette und Wurzel-CA; Zeitpunkt der Aktivierung ist nicht festgelegt, die Austauschbarkeit ist es. | Keine Vorsorge; sofortige Umstellung | Eine Wurzel-CA mit 15 Jahren Laufzeit überlebt jeden heute absehbaren Verfahrenswechsel, und nur eine Verfahrenskennung an jeder Stelle macht den Wechsel später zu einer Migration. |
| **Zeitsynchronisation** | chrony mit netzwerkgesicherter Zeitsynchronisation (NTS) gegen mindestens zwei Quellen; der Ankerknoten ist interne Zeitquelle für alle Knoten und Geräte; ein Knoten mit unbekannter oder zu großer Abweichung führt nicht, stellt nicht aus und schottet sich ab (INV-32). | Ungesichertes NTP; Vertrauen auf die Hardwareuhr | Raft-Leases, Zertifikatsgültigkeit, Einmalkennwörter und die Auditkette hängen sämtlich an der Uhr, und eine ungesicherte Zeitquelle ist ein Angriff auf alle vier gleichzeitig. |
| **PKI-Ort** | Zweistufige PKI: Wurzel-CA offline außerhalb des Systems, Ausgabe-CA als Modul innerhalb von atrium-core mit TPM- oder Token-gebundenem Schlüssel, je Mandant eine Zwischen-CA; ACME-Server, Sperrliste und OCSP werden vom Kern erbracht; jede Ausstellung ist ein Ereignis im replizierten Protokoll; ACME wird für alles benutzt, was ACME kann, einschließlich öffentlicher Zertifikate für externe Namen. | Separater CA-Dienst mit eigener Datenhaltung; ausschließlich öffentliche Zertifikate | Ein separater CA-Dienst erzeugt eine zweite Sicherung und die Möglichkeit, dass Kern und CA unterschiedliche Meinungen über Gültigkeit haben, während rein öffentliche Zertifikate interne Namen und Gerätezertifikate nicht abdecken und bei Internetausfall gar nichts mehr ausstellen. |
| **Quorumverlust und Notbetrieb** | Ohne Quorum ist der Sollzustand eingefroren; Fencing per Lease; Notbetrieb mit einem Knoten (erzwungene Neukonstituierung) ist eine ausdrückliche, mit dem Wiederherstellungscode geschützte, auditierte Handlung mit erzwungener Wartezeit und Klartextwarnung. | Automatischer Weiterbetrieb der Minderheitsseite; Hardware-Fencing als Pflicht | Das Zusammenführen divergierender Sollzustände verwirft entweder Daten oder verlangt Bedienerentscheidungen im Störfall, und Hardware-Fencing ist genau bei Netzpartition nicht erreichbar. |
| **Notzugang** | Bei Erstinstallation erzeugter Wiederherstellungscode (256 bit Entropie, zum Ausdrucken, nirgends im System gespeichert außer als Verifikationswert); zusätzlich Notzugang an der physischen oder Fernkonsole eines Knotens mit Neustart in einen Wiederherstellungsmodus, zeitlich begrenzt, mit nicht unterdrückbarem Auditereignis; kein Fernzugang, keine Hintertür. | Kein Notzugang; Fernnotzugang durch den Hersteller | Die Aussperrung eines Kunden aus seinem eigenen System ist der teuerste Supportfall, ein Herstellerfernzugang ist ein dauerhaftes Generalschlüsselrisiko. |
| **Sicherung und Wiederherstellung** | Zwei getrennte Sicherungsobjekte: signierter, versionierter, maschinen- und menschenlesbarer Sollzustandsexport (vor jeder Änderungstransaktion und periodisch, lokal und extern abgelegt) sowie Nutzdatensicherung je Speicherbereich nach Sicherungsplan; Wiederherstellung einer Gesamtinstallation = neuen Ankerknoten installieren, Export einspielen, Reconciler konvergieren lassen, Nutzdaten je Speicherbereich zurückspielen; die Konsole benennt ausdrücklich, dass der Export keine Nutzdaten enthält und Geheimnisse nur als Referenz führt. | Ein gemeinsames Sicherungsobjekt | Konfiguration ist klein, häufig zu sichern und lange aufzubewahren, Nutzdaten sind groß, langsam und anders aufzubewahren; ein gemeinsames Objekt erzwingt den schlechtesten gemeinsamen Nenner. |
| **Wiederherstellung in Fremdsystemen** | Nach einem Wiederaufbau gleicht der Reconciler Fremdsysteme nach dem deklarierten Feldeigentum an (INV-13): Felder in Atriums Besitz werden gesetzt, fremdbesessene Felder bleiben, Erstanlagefelder bleiben; jede verbleibende Abweichung wird einzeln benannt statt stillschweigend verworfen oder überschrieben. | "Sollzustand gewinnt" ohne Feldeigentum | Ohne Eigentumsmodell ist jede Abgleichrunde nach einem Wiederaufbau ein möglicher Datenverlust in einem fremden System. |

---

## 5. Navigationsbereiche der Konsole

| Bereich | Inhalt | Was ausdrücklich NICHT hier ist |
|---|---|---|
| **Überblick** | Aktuelle Störungen mit direktem Verweis auf das verursachende Objekt; offene Freigaben; Kapazitäts- und Redundanzstatus im Klartext ("wie viele Knotenausfälle werden derzeit noch vertragen", "Redundanz: keine"); ablaufende Zertifikate und Geheimnisse; Datum des letzten erfolgreichen Wiederherstellungstests; vier bis sechs häufigste Aufgaben als direkte Einstiege. | Keine Einstellungen. Keine Kennzahlen ohne Handlungsbezug. Keine Störungsmeldung ohne verlinktes Zielobjekt. Kein Diagramm, das nicht zu einem Objekt oder Vorgang führt. |
| **Personen & Gruppen** | Nutzerliste; Anlegen (Name, Gruppen/Rollen); Gruppen, Rollen und deren Dienstbindungen; Dienstkonten; Gästezugänge. Am einzelnen Nutzer: Schalter je Dienst (die Zuweisungen), "Mail hinzufügen", zugeordnete Geräte, ausgestellte Zertifikate, Versorgungszustand je Zielsystem, Verlauf. | Kein eigener Mailbereich — Mail wird am Nutzer oder an der Gruppe hinzugefügt. Keine Postfachserverkonfiguration. Keine Kennwortrichtlinien (die stehen als Richtlinie unter Mandanten & Rechte). Keine Administratorrechte-Vergabe für die Plattform. |
| **Geräte** | Geräteinventar mit Eigentümer und Netzzone; Registrierung neuer Geräte; ausgerollte Zertifikate mit Restlaufzeit; Netzzugangsrechte; Sperrung eines verlorenen Geräts als einzelne Handlung mit sofortiger Sperrlistenwirkung. | Keine Knoten — Maschinen mit Atrium stehen unter Knoten & Speicher. Keine CA-Verwaltung. Keine 802.1X-Konfiguration. Keine Softwareverteilung an Endgeräte außerhalb des Vertrauensankers und des Zertifikats. |
| **Dienste** | Katalog einsetzbarer Software mit Suche und Produktgrenzdeklaration; laufende Dienste mit Gesundheitszustand, Ort und angezeigter Platzierungsbegründung; Bereitstellung (Produkt, Name, intern/extern); Veröffentlichungen; Datensicherheitsstufe und das daraus folgende RPO; Aktualisierung mit Rücksprungpunkt; Verschieben; Zugang zur produkteigenen Oberfläche. | Keine Fachkonfiguration der Fremdprodukte (Znuny bedient man in Znuny). Keine Abbild- oder Laufzeiteinstellungen. Keine Wahl der Replikationstechnik. Keine Firewallregeln. |
| **Netz & Namen** | Domänen intern und extern mit Einträgen, Sichtzugehörigkeit und Geltungsbereich; Veröffentlichungen (welcher Dienst unter welchem Namen für wen); Zertifikatsübersicht und eigene CA; Netzzonen; nur lesbare, abgeleitete Firewallsicht mit Quellverweis je Regel; Erklärwerkzeug "warum erreicht dieses Gerät diesen Namen nicht". | Kein Dialog zum Anlegen einer Firewallregel. Keine Proxykonfiguration. Keine Zonendateien. Keine Resolver-Einstellungen. Keine manuelle Zertifikatsausstellung außerhalb einer Veröffentlichung oder eines Geräts. |
| **Knoten & Speicher** | Maschinenliste mit Zustand, Rollen, Fehlerzone, Abbildversion und Leasestatus; Aufnahme eines wartenden Knotens (Code eingeben, Zweck wählen: Redundanz / weiterer Verwaltungsknoten / Dienste verteilen); Räumen und Entkoppeln; Kapazität; Speicherpools; Replikationsstand je Speicherbereich; Sicherungspläne. | Keine Partitionierung, keine Dateisystemwerkzeuge. Keine Netzwerkkartenkonfiguration jenseits der Zonenzuordnung. Keine Dienstplatzierung von Hand (nur Vorgaben und Ausschlüsse). Keine Updateverwaltung — die steht unter Verlauf & Nachweis. |
| **Mandanten & Rechte** | Administratorrollen mit Geltungsbereich und Befristung; Freigabewege (welche Änderung braucht wessen Zustimmung); Richtlinien; Kunden- und Review-Bereiche. Die Mandantenliste mit Isolationsstufe und der daraus folgenden Fehlerdomäne erscheint hier, sobald mehr als ein Mandant existiert oder der Anbietermodus aktiv ist. | Keine Nutzeranlage — die steht unter Personen & Gruppen. Keine Abrechnungsfunktion. Keine Isolationsstufenänderung ohne Vorgang mit Dauer und Auswirkungsliste. |
| **Verlauf & Nachweis** | Alle Vorgänge mit Urheber, Wirkungsvorschau, Ergebnis, benannten Resten und Rücknahmemöglichkeit; offene Freigaben; Auditstrom mit Filter und Export; Update-Ausrollung und Rücksprung; Wiederherstellungspunkte mit Prüfstatus; Wiederherstellung einzelner Objekte oder der Installation; Notzugriffsereignisse. | Keine Änderung bestehender Objekte — von hier wird nur zurückgerollt oder wiederhergestellt. Keine Löschung von Auditereignissen. Keine Betriebsprotokolle der Fremdprodukte (die stehen am Dienst). |

Zusätzlich, kein Navigationsbereich: eine dauerhaft sichtbare Suche, die jedes Objekt und jede Aufgabe direkt erreichbar macht.

---

## 6. Kennzahlen und Zielwerte

Alle Werte sind **Zielwerte**, keine Messungen. Verfügbarkeitszahlen sind Rechenergebnisse aus der benannten Annahme und setzen unabhängige Ausfälle voraus; gemeinsame Ausfallursachen (Stromkreis, Switch, Firmware, fehlerhaftes Abbild) sind nicht enthalten, weshalb die Zahlen Obergrenzen und keine Erwartungswerte sind.

| Kennzahl | Zielwert | Herleitung |
|---|---|---|
| **K-01 Zeit vom Einschalten bis zum ersten nutzbaren Dienst (Ankerknoten)** | Zielwert ≤ 20 min gesamt, davon ≤ 5 min Nutzerinteraktion und ≤ 4 Entscheidungen in der Ersteinrichtung | Annahmen und Summanden: unbeaufsichtigte Installation vom Abbild 6 min (SSD, Abbild lokal, keine Paketdownloads) + Ersteinrichtung (Sprache, Mandantenname, Administrator, Basisdomäne) 3 min + Erzeugung Wurzel- und Ausgabe-CA ≤ 30 s + Abbild-Download des ersten Dienstes 300 MB bei 100 Mbit/s = 2400 Mbit / 100 Mbit/s = 24 s + Dienststart 30 s + Zertifikat und Eingangsroute ≤ 10 s ≈ 11 min; der Zielwert 20 min enthält Reserve für langsamere Hardware und Netzanbindung. |
| **K-02 Zeit vom Wartemodus zum produktiven Knoten** | Zielwert ≤ 5 min, davon Kopplung ≤ 60 s | SPAKE2-Lauf und Zertifikatsausstellung ≤ 5 s; Übertragung des Sollzustandsauszugs: eine Momentaufnahme von ≤ 2 GB über 1 Gbit/s entspricht 16 Gbit / 1 Gbit/s = 16 s; Rest ist Start der Knotendienste, mit Reserve ≤ 5 min. |
| **K-03 Entscheidungen je Standardaufgabe** | Zielwert ≤ 3 je Aufgabe. Entwurfsstand: Nutzer anlegen 2, Mail hinzufügen 2, geteiltes Postfach anlegen 3, Dienst bereitstellen 3, Dienst veröffentlichen 3, Knoten aufnehmen 2, interne Domäne anlegen 3, Gerät registrieren 2, Mandant anlegen 2 | Definition: eine Entscheidung ist ein Pflichtfeld oder eine Auswahl, die der Nutzer treffen muss und die nicht aus dem Objektgraphen oder einer Richtlinie vorbelegt werden kann; Bestätigungen, optionale Felder und Freitextnotizen zählen nicht. Beispiel "Mail hinzufügen": Domäne und lokaler Teil sind Entscheidungen; Ablageort, Postfachart, MX/SPF/DKIM/DMARC/MTA-STS-Einträge und Berechtigungen folgen aus Domäne, Person und Richtlinie. Messung im Bau gegen maschinenlesbare Aufgabendefinitionen; ein zusätzliches Pflichtfeld bricht den Bau (INV-14). |
| **K-04 Verfügbarkeit der Kontrollebene nach Stimmknotenzahl** | Zielwert: 1 Knoten 0,99 (≈ 3,65 d/a); 3 Knoten 0,999702 (≈ 2,6 h/a); 4 Knoten 0,999408 (≈ 5,2 h/a, schlechter als 3); 5 Knoten 0,999990 (≈ 5,2 min/a) | Annahme: Einzelknotenverfügbarkeit A = 0,99 einschließlich Wartungsfenstern, Ausfälle unabhängig. Binomial mit Mehrheitsbedingung: 3 Knoten A³+3A²(1−A) = 0,970299+0,029403 = 0,999702; 4 Knoten A⁴+4A³(1−A) = 0,960596+0,038812 = 0,999408; 5 Knoten A⁵+5A⁴(1−A)+10A³(1−A)² = 0,950990+0,048030+0,000970 = 0,999990. Der Sprung von 3 auf 5 kauft 2,6 h/a; 3 Stimmknoten sind der Normalfall, 5 nur auf ausdrückliche Wahl. |
| **K-05 Schreibpause bei Ausfall des führenden Knotens** | Zielwert ≤ 5 s; lesende Zugriffe nicht betroffen | Heartbeat 250 ms, randomisierte Wahlfrist 1000–2000 ms, ein Wahlgang plus Bestätigung, mit Reserve für einen fehlgeschlagenen Wahlgang. |
| **K-06 Erkennung eines Knotenausfalls bis Wiederanlauf eines zustandslosen Dienstes** | Zielwert ≤ 90 s | 3 verpasste Heartbeats à 5 s = 15 s Verdacht + Leaseablauf 20 s (Selbstabschottung) + Übernahmefreigabe ab 30 s + Platzierungsentscheidung ≤ 50 ms + Abbildstart ≤ 60 s. Setzt voraus, dass Abbilder aller platzierbaren Dienste auf allen zulässigen Knoten vorgehalten werden. |
| **K-07 Fristen für Lease, Fencing und Übernahme** | Zielwert: Heartbeat 5 s, Lease 20 s, Selbstabschottung bei Leaseablauf, Übernahme frühestens nach 30 s; zugelassene Uhrenabweichung ≤ 500 ms | Die Reserve zwischen Leaseablauf (20 s) und Übernahmefreigabe (30 s) beträgt 10 s und liegt damit um den Faktor 20 über der zugelassenen Uhrenabweichung (INV-06). |
| **K-08 RPO/RTO Datensicherheitsstufe "Synchron gespiegelt"** | Zielwert RPO 0, RTO ≤ 5 min | Übernahmefreigabe 30 s + Umschalten des replizierten Speicherbereichs und Journalwiederherstellung ≤ 2 min + Dienststart und Aufwärmen ≤ 2,5 min. Setzt 3 Replikate oder 2 Replikate plus Zeuge voraus, also mindestens 3 Knoten. |
| **K-09 RPO/RTO Datensicherheitsstufe "Gespiegelt"** | Zielwert RPO ≤ 15 min, RTO ≤ 30 min | Sendeintervall 5 min, Sicherheitsfaktor 3 gegen ausgelassene Läufe ergibt RPO 15 min. Annahme: Änderungsrate eines typischen Speicherbereichs unter 1 GB je Intervall, Übertragung bei 1 Gbit/s unter 10 s. RTO = Entscheidung über Übernahme + Einbinden des letzten gemeinsamen Standes + Dienststart. |
| **K-10 RPO/RTO Datensicherheitsstufe "Lokal"** | Zielwert RPO ≤ 24 h (wählbar bis 1 h), RTO ≤ 4 h | RPO folgt dem Sicherungsintervall der externen Auslagerung; RTO folgt aus Rückholung und Rückspielen des ausgelagerten Standes. In der Konsole dauerhaft als "keine Redundanz" gekennzeichnet. |
| **K-11 RPO/RTO des Kontrollebenenzustands** | Zielwert: RPO 0 ab 3 Stimmknoten; bei 1 Knoten RPO ≤ 15 min; RTO Vollwiederherstellung ≤ 30 min | Ab 3 Stimmknoten gilt eine Schreiboperation erst als bestätigt, wenn die Mehrheit sie protokolliert hat, also RPO 0. Bei 1 Knoten bestimmt das Exportintervall den RPO (15 min, zusätzlich Export vor jeder Änderungstransaktion). RTO = Neuinstallation eines Ankerknotens ≤ 10 min + Import des signierten Exports ≤ 5 min + Konvergenz ≤ 15 min. |
| **K-12 Größe von Sollzustand und Export** | Zielwert: Sollzustand ≤ 50 MB serialisiert, Export mit Auditkette ≤ 100 MB, Erzeugung und Signatur ≤ 5 s, Momentaufnahme ≤ 2 GB, Momentaufnahmeerzeugung ≤ 30 s | Annahme einer mittleren Installation: 500 Personen, 800 Geräte, 150 Dienste, 20 Domänen, 5.000 DNS-Einträge, 2.000 Zuweisungen. Diese sechs Posten ergeben zusammen 500 + 800 + 150 + 20 + 5.000 + 2.000 = 8.470 Objekte und sind nicht der vollständige Bestand; nicht aufgeführt sind die abgeleiteten und betrieblichen Objektklassen (Gruppen, Mitgliedschaften, Veröffentlichungen, Speicherbereiche, Postfächer, Mailadressen, Zertifikate, Richtlinien, Knoten, Netzzonen, Wiederherstellungspunkte, Vorgänge). Annahme für diese zusammen rund 6.500, sodass die Rechengrundlage rund 8.470 + 6.500 ≈ 15.000 Objekte à durchschnittlich 2 KB = rund 30 MB beträgt; der Zielwert 50 MB enthält Reserve. Kapitel 07 rechnet denselben Referenzfall mit allen Objektklassen einzeln aus und kommt auf 24.289 Objekte bei im Mittel 787 B je Objekt, also 19,1 MB. Beide Rechnungen bleiben unter dem Zielwert; die hier angesetzten 2 KB je Objekt sind gegenüber Kapitel 07 die konservativere Annahme, und die abgeleiteten Rechnungen in den Kapiteln stützen sich auf die hier genannten 15.000 Objekte. Der Istzustand wird knotenlokal gehalten und nur verdichtet repliziert (≤ 1 KB je Objekt). |
| **K-13 Zertifikatslaufzeiten und Erneuerungsfenster** | Zielwert: Dienstzertifikate 90 d, Erneuerung ab Tag 60, Warnung ab ≤ 15 d Restlaufzeit, Eskalation ab ≤ 7 d Restlaufzeit; Gerätezertifikate 365 d, Erneuerung ab 120 d Restlaufzeit; Ausgabe-CA 5 a, Nachfolger ab 2 a Restlaufzeit parallel verteilt; Wurzel-CA offline 15 a; Anteil Zertifikate näher als 7 d am Ablauf = 0 | Erneuerung bei zwei Dritteln der Laufzeit ergibt bei 90 d ein Fenster von 30 d; bei stündlichem Erneuerungsversuch sind das 30 × 24 = 720 Versuche vor Ablauf, womit eine zusammenhängende Störung von bis zu 30 Tagen ohne Dienstausfall überstanden wird. Bei 500 veröffentlichten Namen fallen im Mittel 500/90 = 5,6 Erneuerungen pro Tag an. Gerätefenster 120 d, weil Geräte annahmegemäß zeitweise offline sind. |
| **K-14 Widerstand des Kopplungsvorgangs** | Zielwert: Erfolgswahrscheinlichkeit je Code 4,44 × 10⁻¹⁵; Erfolgswahrscheinlichkeit eines ununterbrochenen Angriffs über ein Jahr ≤ 2 × 10⁻¹⁰ | 10 Zufallszeichen Crockford-Base32 à log₂(32) = 5 bit = 50 bit, also 2⁵⁰ = 1,1259 × 10¹⁵ Möglichkeiten (2 Prüfzeichen tragen keine Entropie, sie fangen Tippfehler ab). Je Code 5 Fehlversuche: 5 / 2⁵⁰ = 4,44 × 10⁻¹⁵. Codegültigkeit 15 min ⇒ höchstens 4 × 24 × 365 = 35.040 Codes pro Jahr × 5 = 175.200 Versuche; 175.200 / 1,1259 × 10¹⁵ = 1,56 × 10⁻¹⁰. Hypothetisch ohne jede Begrenzung bei 1.000 vollständigen Protokollläufen je Sekunde: Erwartungswert 2⁴⁹ / 1.000 s = 5,63 × 10¹¹ s ≈ 17.800 Jahre. Offline-Wörterbuchangriff auf einen mitgeschnittenen Lauf ist nach RFC 9382 ausgeschlossen. |
| **K-15 Versorgungslatenz einer Zuweisung über alle Zielsysteme** | Zielwert p50 ≤ 5 s, p95 ≤ 60 s, harte Obergrenze 15 min mit je Zielsystem sichtbarem Fortschritt und benanntem Grund bei Teilfehlern | Ereignisgetriebener Pfad; Kaltstart eines aktivierten Konnektors ≤ 300 ms, je Zielsystem ≤ 3 s, bei ≤ 10 gleichzeitig gebundenen Systemen und Parallelität 4 rechnerisch ≤ 10 s; die p95-Grenze ist von der langsamsten Fremd-API mit Ratenbegrenzung bestimmt. Die harte Obergrenze existiert, damit die Oberfläche nie unbegrenzt "in Arbeit" zeigt. |
| **K-16 Konvergenzzeit des Reconcilers** | Zielwert: Änderung im Istzustand sichtbar ≤ 30 s; vollständiger Abgleich aller Objekte ≤ 60 min | Ereignisgetriebener Pfad für Änderungen einschließlich Konnektorlauf; zusätzlich ein periodischer Volllauf als Driftpfad: ≤ 5.000 abgleichpflichtige Objekte × ≤ 2 s Prüfzeit je Objekt bei Parallelität 4 ≈ 42 min, Zielwert 60 min mit Reserve. |
| **K-17 Wirksamkeit einer Veröffentlichung und einer DNS-Änderung** | Zielwert: Veröffentlichung bis erreichbare, TLS-gültige Adresse p95 ≤ 10 s; DNS-Eintrag bis Auflösbarkeit im Zielbereich p95 ≤ 5 s; Konfigurationswechsel am Eingang ohne Verbindungsabbruch | Dynamische Zonenänderung mit Signierung ohne Neustart; xDS-Verteilung plus Zertifikatsausstellung aus der eigenen CA. Interne TTL 300 s, externe TTL 3600 s. Nachweis über Dauerlasttest mit fortlaufenden Verbindungen während 1.000 aufeinanderfolgender Routenänderungen. |
| **K-18 Reaktionszeit der Oberfläche** | Zielwert: Erstanzeige jeder Liste p95 ≤ 300 ms bei 10.000 Objekten; Wirkungsvorschau eines Vorgangs p95 ≤ 2 s | Listen werden aus dem lokal materialisierten, indizierten Lesemodell bedient, nicht aus dem Replikationsprotokoll und nicht aus Fremdsystemen; bei ≤ 50 MB Gesamtzustand liegt der Arbeitssatz im Hauptspeicher. Die Vorschau erfordert einen `plan`-Aufruf je beteiligter Konnektorbindung; bei ≤ 7 Bindungen und Parallelausführung bestimmt die langsamste Bindung die Zeit. |
| **K-19 Ressourcenbudget der Grundinstallation auf einem Ankerknoten** | Zielwert: ≤ 4 GB RAM und ≤ 2 Kerne für Kontrollebene, Eingang, autoritativen DNS, Resolver und Protokollkopf zusammen; Eingang davon ≤ 512 MB RSS bei 500 Hostnamen und 2.000 Routen; zwei Systemabbilder je ≤ 8 GB Plattenplatz | Vorgabe, damit Ein-Knoten-Betrieb auf kleiner Hardware möglich bleibt; sie begrenzt zugleich die zulässige Zahl mitgelieferter Dauerprozesse. |
| **K-20 Dauerprozesse je Knoten** | Zielwert: ≤ 3 je Arbeitsknoten (atrium-node, optional Eingangsinstanz, optional Konnektorpool); ≤ 32 gleichzeitig aktive Konnektorprozesse je Knoten | Podman läuft ohne Daemon, Konnektoren sind aktivierungsbasiert mit Leerlaufabschaltung nach 10 min. Gegenrechnung: 1.000 dauerlaufende Konnektoren à 20 MB RSS wären 20 GB allein im Leerlauf und sind ausgeschlossen; 20 Mandanten × 8 aktive Bindungen = 160 Prozesse à 30 MB wären 4,8 GB und erzwingen die Obergrenze von 32. |
| **K-21 Platzierungsentscheidung** | Zielwert: einzelne Platzierungsentscheidung p95 ≤ 50 ms; vollständige Neuberechnung aller Zuordnungen ≤ 200 ms | Skalenbereich 32 Knoten × 500 Dienstinstanzen = 16.000 Bewertungspaare; eine gierige Bewertung mit deterministischem Gleichstandsbruch ist vollständig im Speicher durchrechenbar. Die Skalengrenze von 32 Knoten ist gesetzt, nicht gemessen. |
| **K-22 Konnektor-Wartungslast bei 1.000 Konnektoren** | Zielwert: Anteil manifestbasierter Konnektoren ≥ 90 %; Aufwand für einen neuen Manifestkonnektor ≤ 1,5 Personentage; Gesamtlast ≈ 2,6 Vollzeitäquivalente; Trockenlaufantwort je Konnektor ≤ 2 s | Annahmen: 1,5 API-relevante Veröffentlichungen je Fremdsystem und Jahr, davon 20 % brechend = 0,3 Brüche je Konnektor und Jahr = 300 Brüche. Behebung 0,25 PT (Manifest) bzw. 1,5 PT (Code): 270 × 0,25 + 30 × 1,5 = 112,5 PT/a. Grundpflege 0,1 PT je Konnektor = 100 PT/a. Neuanlage 200 × 1,5 = 300 PT/a. Summe 512,5 PT/a bei 200 produktiven Tagen je Person = 2,56 VZÄ. Gegenrechnung rein handgeschrieben: 300 × 1,5 + 0,5 × 1.000 + 200 × 10 = 2.950 PT/a = 14,75 VZÄ, Faktor 5,8. Sämtlich Annahmen, keine Messwerte. |
| **K-23 Update-Ausrollung und automatischer Rückfall** | Zielwert: Beobachtungsfenster nach dem ersten Knoten 30 min; automatischer Rückfall, wenn das Gesundheitssignal ≤ 10 min nach Neustart ausbleibt; Rückfallzeit ≤ 5 min; Sicherheitsupdate für die Außenkante (Eingang, DNS, Protokollkopf) verfügbar ≤ 72 h nach Veröffentlichung | A/B-Wurzeldateisystem: der Rückfall ist ein Neustart in die vorherige Hälfte und damit durch die Startzeit des Knotens begrenzt. Verwaltungsknoten werden nacheinander aktualisiert, sodass das Quorum nie unterschritten wird. Die 72-Stunden-Vorgabe ist bei gestaffelter Ausrollung und A/B-Test ambitioniert und wird als solche gekennzeichnet. |
| **K-24 Wiederherstellungsübung** | Zielwert: monatlich automatisiert auf Ersatzhardware, Erfolgsquote 100 %, Abweichung 0 Objekte, Dauer ≤ 60 min | Ohne regelmäßigen, automatisierten Test sind alle RPO- und RTO-Werte unbelegte Behauptungen. Die Abweichungsprüfung vergleicht den importierten Sollzustand objektweise mit dem Export. Der Test ist selbst ein erheblicher, eigenständig zu entwickelnder Bestandteil. |
| **K-25 Aufbewahrung und Nachweis** | Zielwert: Auditstrom 12 Monate vollständig, danach verdichtet; Sollzustandsexporte 90 Tage täglich plus 12 Monatsstände; Aufbewahrungsfrist für freigegebene Datenträger 30 Tage; Sperrfrist für entfernte Mailadressen 12 Monate | Vorgaben. Die Sperrfrist verhindert die Wiedervergabe einer Adresse an eine andere Person, die sonst fremde Post empfinge. Die 30-Tage-Frist macht jede Löschung innerhalb eines Monats umkehrbar. |
| **K-26 Auffindbarkeit und Klickpfadtiefe** | Zielwert: jede Standardaufgabe vom Überblick aus in ≤ 3 Ebenen erreichbar; kein Bereich mit mehr als 7 Unterpunkten; jede Störungsmeldung verlinkt auf das verursachende Objekt (Trefferquote 100 %); Erstklick-Trefferquote ≥ 80 % bei 20 Standardaufgaben | Folgt aus höchstens acht Navigationsbereichen und der Regel, dass Aufgaben am Objekt erledigt werden (Bereich → Objekt → Aktion = 3 Ebenen). Für die Erstklick-Trefferquote liegt kein Messwert vor; das Messverfahren (unbegleiteter Test mit Erstnutzern) ist festzulegen. |
| **K-27 Prüfungen im Bau** | Zielwert: 0 Verstöße gegen die Positivliste der Oberflächenbegriffe; 0 Standardaufgaben mit mehr als 3 Entscheidungen; 0 Fehlermeldungen, deren einziger Lösungsweg ein Konsolenbefehl ist; 0 Verstöße gegen WCAG 2.2 Stufe AA in der automatisierten Prüfung; 0 Formularfelder ohne Quellenangabe der Vorbelegung | Alle fünf Invarianten sind statisch prüfbar: Oberflächentexte und Fehlerübersetzungstabelle gegen eine Begriffsliste, Formulardefinitionen gegen die Aufgabendefinitionen, Meldungstexte gegen eine Musterprüfung auf Befehls- und Dateipfadangaben, Oberflächenbaum gegen eine Barrierefreiheitsregelprüfung plus Tastaturdurchlauf. Ein Verstoß bricht den Bau, damit die Zusagen nicht schleichend erodieren. |
| **K-28 Fehlerdomäne je Mandant** | Zielwert: Anzahl gleichzeitig betroffener Mandanten bei Ausfall der Kontrollebene = alle (M0–M3); bei Ausfall eines Knotens ab M3 = 1; die Zahl wird je Mandant in der Konsole angezeigt | Folgt unmittelbar aus dem Stufenmodell: M0 trennt Berechtigungen, M1 Erreichbarkeit, M2 Leitungsebene, M3 Ressourcen und Datenträger; die Kontrollebene bleibt in allen Stufen gemeinsam, und keine Konfiguration ändert das. |
| **K-29 Stufenwechsel der Mandantenisolation** | Zielwert: M0 → M1 ohne Dienstunterbrechung; M1 → M2 mit geplanter Umlagerung, ≤ 5 min Unterbrechung je zustandsbehaftetem Dienst; M2 → M3 als Umzug mit ausdrücklichem Wartungsfenster ≤ 4 h | Jede Stufe zieht genau eine zusätzliche technische Grenze; die Unterbrechung entsteht erst dort, wo Verkehr oder Daten physisch umgelagert werden. Der Stufenwechsel wird in der Konsole als Vorgang mit Dauer dargestellt, nicht als Einstellung. |
| **K-30 Zeitgüte** | Zielwert: Uhrenabweichung je Knoten ≤ 500 ms gegenüber der internen Zeitquelle; mindestens 2 gesicherte externe Zeitquellen; Anteil Knoten mit unbekannter Zeitgüte = 0 | Die Reserve zwischen Leaseablauf und Übernahme (10 s, K-07) liegt um den Faktor 20 darüber. Ein Knoten, der die Zeitgüte nicht belegen kann, führt nicht, stellt nicht aus und schottet sich ab (INV-32). |

---

## 7. Netz- und Portmatrix (Grundgerüst)

Eigene Dienste benutzen ausschließlich den hiermit reservierten Bereich **8400–8499/tcp**. Ports außerhalb dieses Bereichs sind nur die hier genannten Standardports. Alle Zonen sind Default-Deny; jede Zeile besteht nur, weil ein Objekt sie erzeugt.

| Dienst | Protokoll | Port | Richtung | Zone | TLS | Authentisierung |
|---|---|---|---|---|---|---|
| Atrium Console und öffentliche API (veröffentlicht) | HTTPS (HTTP/2, HTTP/3) | 443/tcp, 443/udp | eingehend | extern und intern | TLS 1.3 | OIDC-Sitzung mit Passkey/MFA; Dienstkonten per mTLS oder Token |
| ACME http-01 und Umleitung auf HTTPS | HTTP | 80/tcp | eingehend | extern | nein | keine (nur Umleitung und ACME-Nachweis) |
| atrium-core API (knotenlokal, hinter dem Eingang) | HTTP/2 | **8400/tcp** | eingehend | Knoten-Overlay, Loopback | TLS 1.3 | mTLS mit Knoten- oder Dienstkontozertifikat |
| atrium-core Raft-Peer | gRPC | **8401/tcp** | Knoten ↔ Knoten | Knoten-Overlay | TLS 1.3 | gegenseitiges TLS mit Knotenzertifikat |
| atrium-node Agentenkanal | gRPC | **8402/tcp** | Kern → Knoten | Knoten-Overlay | TLS 1.3 | gegenseitiges TLS mit Knotenzertifikat |
| Kopplungsendpunkt (nur im Wartemodus) | HTTPS + SPAKE2 | **8403/tcp** | eingehend | nur lokales Netzsegment | TLS 1.3 mit Exporter-Kanalbindung | SPAKE2 aus dem Kopplungscode (RFC 9382) |
| xDS-Server an Envoy | gRPC | **8404/tcp** | Kern → Eingang | Loopback bzw. Knoten-Overlay | TLS 1.3 | gegenseitiges TLS |
| SCIM-Server (durch den Eingang auf 443 veröffentlicht) | HTTPS | **8405/tcp** | eingehend | intern | TLS 1.3 | OAuth 2.0 Bearer Token mit Dienstkonto |
| ACME-Server (intern) | HTTPS | **8406/tcp** | eingehend | intern, Knoten-Overlay | TLS 1.3 | ACME-Konto, an Objektkennung gebunden |
| OCSP-Responder und Sperrlistenverteilung | HTTP | **8407/tcp** | eingehend | intern und extern | nein (signierte Antworten) | keine |
| Telemetrieempfang (OTLP) | gRPC | **8408/tcp** | Knoten → Kern | Knoten-Overlay | TLS 1.3 | gegenseitiges TLS mit Knotenzertifikat |
| Gesundheits- und Bereitschaftsendpunkt | HTTP | **8409/tcp** | lokal | Loopback | nein | keine (nur Loopback) |
| Konnektorvertrag | gRPC über Unix-Socket | kein Port | lokal | Dateisystem | entfällt | Dateisystemrechte, eigener Systembenutzer je Konnektor |
| Autoritativer DNS (Knot DNS) | DNS | 53/udp, 53/tcp | eingehend | extern und intern | nein | keine; Änderungen nur über die lokale Steuerschnittstelle |
| Rekursiver Resolver (Knot Resolver) | DNS | 53/udp, 53/tcp | eingehend | nur intern und Mandanten-Overlay | nein | Quelladressbindung an die Netzzone; keine offene Rekursion |
| Rekursiver Resolver, verschlüsselt | DNS over TLS | 853/tcp | eingehend | intern, verwaltete Geräte | TLS 1.3 | Quelladressbindung; optional Gerätezertifikat |
| Rekursiver Resolver, verschlüsselt | DNS over HTTPS | 443/tcp (eigener Name) | eingehend | intern, verwaltete Geräte | TLS 1.3 | Quelladressbindung; optional Gerätezertifikat |
| LDAP (Protokollkopf) | LDAPS | 636/tcp | eingehend | nur intern | TLS 1.3 | Simple Bind über TLS oder Clientzertifikat; 389/tcp bleibt geschlossen |
| RADIUS Authentisierung | RADIUS + EAP-TLS | 1812/udp | eingehend | Netzzugangszone | EAP-TLS innerhalb | Gerätezertifikat aus der internen PKI, geprüft gegen Sperrliste |
| RADIUS Abrechnung | RADIUS | 1813/udp | eingehend | Netzzugangszone | entfällt | gemeinsames Geheimnis je Netzgerät, aus dem Objektgraphen erzeugt |
| RADIUS über Weitverkehr | RadSec | 2083/tcp | eingehend | standortübergreifend | TLS 1.3 | gegenseitiges TLS |
| SMTP Mailübergabe | SMTP mit STARTTLS | 25/tcp | ein- und ausgehend | extern | TLS 1.3, DANE bzw. MTA-STS | keine (Serverübergabe); Absenderprüfung über SPF, DKIM, DMARC |
| SMTP Einlieferung durch Clients | SMTP Submission | 587/tcp | eingehend | intern und extern | TLS 1.3 (STARTTLS erzwungen) | OAuth 2.0 Bearer Token oder Passkey-gebundene Anmeldung |
| SMTP Einlieferung, implizites TLS | SMTPS | 465/tcp | eingehend | intern und extern | TLS 1.3 | wie 587/tcp |
| IMAP (nur bei internem Postfachdienst) | IMAPS | 993/tcp | eingehend | intern und extern | TLS 1.3 | OAuth 2.0 Bearer Token |
| Overlay-Netz zwischen Knoten | WireGuard | 51820/udp | Knoten ↔ Knoten | Unterlagerungsnetz | eigenes Verfahren | öffentliche Schlüssel, an Knotenzertifikate gebunden |
| Synchrone Blockreplikation | DRBD | 7789/tcp und aufsteigend, je Ressource ein Port | Knoten ↔ Knoten | Speichernetz oder Knoten-Overlay | über WireGuard gekapselt | Knotenzugehörigkeit über das Overlay |
| Asynchrone Replikation und Auslagerung | ZFS send/recv bzw. restic über SSH | 22/tcp | Knoten → Ziel | Speichernetz bzw. Auslagerungsziel | SSH-Transport | Schlüsselpaar je Knoten, nur für diesen Zweck berechtigt |
| Interaktiver Supportzugang | SSH | 22/tcp | eingehend | intern, standardmäßig geschlossen | SSH-Transport | Zertifikatsbasiert, befristet, nur nach auditiertem Vorgang freigeschaltet |
| Zeitsynchronisation nach außen | NTS/NTP | 123/udp, 4460/tcp (NTS-KE) | ausgehend | extern | NTS-Schlüsselaustausch | Zertifikatsprüfung der Zeitquelle |
| Zeitsynchronisation nach innen | NTP | 123/udp | eingehend | intern | entfällt | Quelladressbindung an die Netzzone |
| Protokollausleitung | Syslog über TLS | 6514/tcp | ausgehend | extern oder intern | TLS 1.3 | gegenseitiges TLS |

---

## 8. Geprüftes Standardverzeichnis

Kapitel dürfen ausschließlich aus dieser Liste Nummern zitieren. Alles andere wird ohne Nummer nur mit Namen genannt.

| Standard | Fundstelle |
|---|---|
| TLS 1.3 | RFC 8446 |
| X.509/PKIX | RFC 5280 |
| OCSP | RFC 6960 |
| ACME | RFC 8555 |
| EST | RFC 7030 |
| CAA | RFC 8659 |
| DNSSEC Kern | RFC 4033, RFC 4034, RFC 4035 |
| DNSSEC Betriebspraxis | RFC 6781 |
| DNS UPDATE | RFC 2136 |
| TSIG | RFC 8945 |
| DNS over TLS | RFC 7858 |
| DNS over HTTPS | RFC 8484 |
| SPF | RFC 7208 |
| DKIM | RFC 6376 |
| DMARC | RFC 7489 |
| MTA-STS | RFC 8461 |
| TLS-RPT | RFC 8460 |
| DANE für SMTP | RFC 7672 |
| ARC | RFC 8617 |
| SMTP | RFC 5321 |
| Nachrichtenformat | RFC 5322 |
| IMAP4rev1 | RFC 3501 |
| JMAP Kern | RFC 8620 |
| JMAP Mail | RFC 8621 |
| OAuth 2.0 | RFC 6749 |
| Bearer Token | RFC 6750 |
| PKCE | RFC 7636 |
| Device Authorization Grant | RFC 8628 |
| Token Exchange | RFC 8693 |
| Dynamic Client Registration | RFC 7591 |
| JWT | RFC 7519 |
| JWT Profile für Access Tokens | RFC 9068 |
| OpenID Connect Core 1.0 | OpenID Foundation, keine RFC |
| SAML 2.0 | OASIS, keine RFC |
| SCIM Schema | RFC 7643 |
| SCIM Protokoll | RFC 7644 |
| LDAPv3 | RFC 4511 |
| Kerberos v5 | RFC 4120 |
| RADIUS | RFC 2865 |
| EAP | RFC 3748 |
| EAP-TLS | RFC 5216 |
| RadSec | RFC 6614 |
| IEEE 802.1X | IEEE, keine RFC |
| HTTP Semantik | RFC 9110 |
| HTTP/2 | RFC 9113 |
| HTTP/3 | RFC 9114 |
| QUIC | RFC 9000 |
| Argon2 | RFC 9106 |
| TOTP | RFC 6238 |
| HOTP | RFC 4226 |
| JSON Canonicalization Scheme | RFC 8785 |
| Time-Stamp Protocol | RFC 3161 |
| Syslog | RFC 5424 |
| SPAKE2 | RFC 9382 |
| HKDF | RFC 5869 |
| EdDSA/Ed25519 | RFC 8032 |
| X25519 | RFC 7748 |
| ChaCha20-Poly1305 | RFC 8439 |
| WebAuthn Level 3 | W3C, keine RFC |
| CTAP2 | FIDO Alliance, keine RFC |
| SPIFFE/SPIRE | CNCF, keine RFC |
| OpenTelemetry | CNCF, keine RFC |
| OCI Image Spec / Distribution Spec | Open Container Initiative, keine RFC |
| SPDX | ISO/IEC 5962 |
| CycloneDX | OWASP/Ecma, Nummer nicht zitieren |
| WireGuard | Protokollspezifikation Donenfeld, keine RFC |
| Raft | Ongaro/Ousterhout, keine RFC |
| AES | FIPS 197 |
| SHA-2 | FIPS 180-4 |
| SHA-3 | FIPS 202 |
| ML-KEM | FIPS 203 |
| ML-DSA | FIPS 204 |
| SLH-DSA | FIPS 205 |
| TPM 2.0 | Trusted Computing Group |
| UEFI Secure Boot | UEFI Forum |
| WCAG 2.2 | W3C |
| ISO/IEC 27001:2022 | ISO/IEC 27001:2022 |
| ISO 22301 | ISO 22301 |
| BSI IT-Grundschutz-Kompendium | BSI IT-Grundschutz-Kompendium |
| DSGVO | Verordnung (EU) 2016/679 |
| NIS2 | Richtlinie (EU) 2022/2555 |
| Cyber Resilience Act | Verordnung (EU) 2024/2847 |
| European Accessibility Act | Richtlinie (EU) 2019/882 |
| Barrierefreiheitsstärkungsgesetz (BFSG) | Deutschland |
| eIDAS | Verordnung (EU) 910/2014, novelliert 2024 |

---

## 9. Kapitel- und Anforderungsnummern

| Nr. | Kapitel | Dateiname |
|---|---|---|
| 01 | Zusammenfassung | `01-zusammenfassung.md` |
| 02 | Problemstellung | `02-problemstellung.md` |
| 03 | Zielbild und Prinzipien | `03-zielbild-prinzipien.md` |
| 04 | Marktabgrenzung | `04-marktabgrenzung.md` |
| 05 | Systemarchitektur | `05-systemarchitektur.md` |
| 06 | Basis Ubuntu | `06-basis-ubuntu.md` |
| 07 | Objektmodell | `07-objektmodell.md` |
| 08 | Kontrollebene | `08-kontrollebene.md` |
| 09 | Konnektoren | `09-konnektoren.md` |
| 10 | Identität | `10-identitaet.md` |
| 11 | PKI | `11-pki.md` |
| 12 | DNS und Netzwerk | `12-dns-netzwerk.md` |
| 13 | Geräteverwaltung | `13-geraeteverwaltung.md` |
| 14 | Mail | `14-mail.md` |
| 15 | Dienste und Software | `15-dienste-software.md` |
| 16 | Cluster | `16-cluster.md` |
| 17 | Speicher und Backup | `17-speicher-backup.md` |
| 18 | Bedienkonzept | `18-bedienkonzept.md` |
| 19 | Mandanten, Rechte, Audit | `19-mandanten-rechte-audit.md` |
| 20 | Sicherheit | `20-sicherheit.md` |
| 21 | Betrieb und Updates | `21-betrieb-updates.md` |
| 22 | Compliance | `22-compliance.md` |
| 23 | Ökonomie und Roadmap | `23-oekonomie-roadmap.md` |

Die Anhänge tragen Buchstabenkennungen und werden von den Kapiteln mit derselben Verbindlichkeit zitiert wie Kapitel untereinander:

| Kennung | Anhang | Dateiname | Eigene Anforderungs-IDs |
|---|---|---|---|
| A1 | Schemata | `A1-schemata.md` | ja, `R-A1-nn` |
| A2 | API-Referenz | `A2-api-referenz.md` | ja, `R-A2-nn` |
| A3 | Architekturentscheidungen | `A3-adr.md` | ja, `R-A3-nn` |
| A4 | Bedrohungsmodell und Härtung | `A4-bedrohungsmodell-haertung.md` | ja, `R-A4-nn` |
| A5 | Anforderungsmatrix | `A5-anforderungsmatrix.md` | nein, verweist ausschließlich auf fremde IDs |
| A6 | Glossar und Standards | `A6-glossar-standards.md` | nein, verweist ausschließlich auf fremde IDs |

**Anforderungs-IDs:** `R-<Kapitelnummer>-<zweistellig>` für die Kapitel 02 bis 23, Beispiel `R-12-03`, und `R-<Anhangkennung>-<zweistellig>` für die Anhänge A1 bis A4, Beispiel `R-A2-22`; fortlaufend je Kapitel beziehungsweise Anhang, beginnend bei 01. Kapitel 01 sowie die Anhänge A5 und A6 vergeben keine eigenen Anforderungs-IDs. Eine einmal vergebene Nummer wird in keinem der beiden Kennungsräume neu vergeben; entfallene Anforderungen bleiben mit dem Vermerk "entfallen" stehen. Jede Anforderung nennt die Invariante oder Kennzahl, aus der sie folgt, oder begründet, warum sie aus keiner folgt. Verweise zwischen Kapiteln zitieren ausschließlich Anforderungs-IDs, Invariantennummern (`INV-nn`) und Kennzahlennummern (`K-nn`), nie Seitenzahlen oder Überschriften.

---

## 10. Stilregeln für alle Kapitel

1. Dokumentsprache ist Deutsch; deutsche Fachbegriffe haben Vorrang, englische nur bei fehlendem etabliertem Äquivalent (Reconciler, Quorum, Raft, Podman, Envoy).
2. Die Schreibweisen aus Abschnitt 1 sind verbindlich; die dort verbotenen Begriffe erscheinen nur in Support- und Fachabschnitten, nie als Bediensprache.
3. Jede Zahl ist als **Zielwert**, **Annahme** oder **Rechenergebnis** gekennzeichnet; Messwerte gibt es nicht, solange nicht gemessen wurde.
4. Jede Rechnung wird vollständig ausgeschrieben, damit sie nachrechenbar ist und sich bei geänderter Annahme nachvollziehbar ändert.
5. Keine Versionsnummern von Fremdsoftware, keine Kernelversionen; stattdessen "GA-Kernel der LTS-Serie" bzw. "HWE-Kernel".
6. Standards werden ausschließlich mit den Nummern aus Abschnitt 8 zitiert; alles andere nur mit Namen.
7. Jede Entscheidung nennt die verworfene Alternative und den Grund; kein "sowohl als auch".
8. Jedes Kapitel und jeder Anhang führt einen eigenen Abschnitt "Offene Punkte" und benennt dort, was der Entwurf nicht löst, einschließlich der bekannten Schwächen der eigenen Festlegungen.
9. Aktiv und im Indikativ; keine Werbesprache, keine Superlative, keine Zukunftsversprechen ohne Zielwert.
10. Tabellen vor Fließtext, Listen vor Absätzen; ein Absatz hat höchstens fünf Sätze.
11. Verweise nur als `INV-nn`, `K-nn`, `R-<kk>-<nn>` oder Kapitelnummer; nie "siehe oben", nie Seitenzahlen.
12. Anforderungen sind prüfbar formuliert: sie nennen ein beobachtbares Ergebnis, nicht eine Absicht.
13. Keine Screenshots und keine erfundenen Oberflächentexte; Oberflächenbeispiele werden als Entscheidungsfolge beschrieben.
14. Diagramme sind Textdiagramme im Codeblock, damit sie versionierbar und diffbar bleiben.
15. Keine Emojis, keine Ausrufezeichen, keine rhetorischen Fragen.
