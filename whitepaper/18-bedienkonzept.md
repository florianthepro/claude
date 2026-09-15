# 18 Bedienkonzept: Oberfläche ohne Rückfragen

## 18.1 Informationsarchitektur

### Die acht Bereiche und ihre Leitfrage

Die Navigationsbereiche sind in KANON 5 abschließend festgelegt. Sie werden hier nicht wiederholt, sondern um die Leitfrage ergänzt, aus der ihr Zuschnitt folgt, und um die Tiefengrenze, die in ihnen gilt.

| Bereich | Leitfrage des Bedieners | Oberste Objektart | Tiefe bis zur Aktion |
|---|---|---|---|
| Überblick | Was ist gerade zu tun | Störung, Freigabe, Frist | 1 (Verweis auf das verursachende Objekt) |
| Personen & Gruppen | Wer darf was benutzen | Person, Gruppe, Rolle, Dienstkonto | 2 (Liste → Person → Schalter) |
| Geräte | Welches Gerät gehört wem und darf wohin | Gerät | 2 |
| Dienste | Welche Software läuft für wen | Katalogeintrag, Dienst, Veröffentlichung | 2 |
| Netz & Namen | Unter welchem Namen ist etwas für wen erreichbar | Domäne, Veröffentlichung, Netzzone, Zertifikat | 2 |
| Knoten & Speicher | Welche Maschinen gibt es und wie sicher liegen die Daten | Knoten, Speicherbereich, Sicherungsplan | 2 |
| Mandanten & Rechte | Wer verwaltet was und wer gibt frei | Mandant, Rolle, Richtlinie, Freigabeweg | 2 |
| Verlauf & Nachweis | Was ist passiert und wie komme ich zurück | Vorgang, Auditereignis, Wiederherstellungspunkt | 2 |

### Die Negativregel als Entwurfswerkzeug

Die Spalte "Was ausdrücklich NICHT hier ist" in KANON 5 ist kein erläuternder Zusatz, sondern die eigentliche Festlegung. Ein Bereich wächst, weil jede neue Funktion irgendwo hin muss und der thematisch nächstliegende Bereich immer plausibel wirkt. Die Negativregel dreht die Beweislast um: eine Funktion, die in der Negativliste eines Bereichs steht, wird dort nicht eingebaut, sondern es wird der Bereich bestimmt, in dessen Leitfrage sie fällt. Findet sich keiner, ist die Funktion falsch geschnitten und wird nicht gebaut.

Drei Anwendungen dieser Regel mit ihren Folgen:

| Vermutete Ablage | Tatsächliche Ablage | Grund |
|---|---|---|
| "Mail" als eigener Bereich | Am Nutzer und an der Gruppe unter Personen & Gruppen, Maildomäne unter Netz & Namen | Ein Mailbereich verlangt vom Bediener die Kenntnis, dass Postfach, Adresse und Maildomäne drei Objekte sind. Am Nutzer ist die Handlung "Mail hinzufügen" eine Eigenschaft der Person. |
| "Firewall" als eigener Bereich | Nur lesbare, abgeleitete Sicht unter Netz & Namen, hergeleitet in [Kapitel 12](12-dns-netzwerk.md) | INV-09 kennt keine Schreiboperation für abgeleitete Artefakte. Ein Bereich mit ausschließlich lesbarem Inhalt und ohne eigene Leitfrage ist kein Bereich, sondern eine Ansicht. |
| "Integrationen" als eigener Bereich | Konnektorbindung als Eigenschaft des Mandanten unter Mandanten & Rechte, Zustand am nutzenden Dienst und an der betroffenen Person | Eine Bindung ist nie Selbstzweck. Wer sie sucht, sucht in Wahrheit die Ursache eines Versorgungsfehlers, und der steht am betroffenen Objekt. |

### Suche als gleichwertiger Einstieg

Die Suche ist kein Navigationsbereich (KANON 4, Entscheidung 11) und keine Notlösung für misslungene Navigation, sondern der zweite vollwertige Einstieg. Sie liefert zwei Trefferarten in einer Liste: Objekte und Aufgaben.

```
Eingabe: "schmidt"
  Objekte:   Person "Anna Schmidt"            -> Personenansicht
             Gerät  "schmidt-notebook"        -> Geräteansicht
             Postfach "a.schmidt@..."         -> am Postfach
  Aufgaben:  Nutzer anlegen                   -> Formular, Anzeigename vorbelegt "schmidt"
             Mail hinzufügen (Anna Schmidt)   -> Formular, Person vorbelegt

Eingabe: "mail hinzufügen"
  Aufgaben:  Mail hinzufügen                  -> Formular, Person unbesetzt
  Objekte:   Maildomänen (3 Treffer)
```

Festlegungen der Suche: kein Abfragesprachenrest in der Eingabe, keine Platzhalterzeichen, keine Feldpräfixe. Die Eingabe ist eine Zeichenkette; die Zerlegung geschieht serverseitig gegen einen Index über Anzeigenamen, technische Namen, Mailadressen, Hostnamen und Aufgabentitel. Die Abfrage gegen das Lesemodell ist parametrisiert; die Eingabe wird nie in eine Abfrage interpoliert. Jede Trefferzeile trägt Objektart und Mandant, weil derselbe Anzeigename in zwei Mandanten zulässig ist; der Mandantenfilter aus INV-19 gilt auch für die Suche, sodass ein Treffer aus einem fremden Mandanten nicht entsteht, statt gefiltert dargestellt zu werden.

### Tiefenbegrenzung

Aus K-26 folgen drei harte Grenzen: höchstens drei Ebenen vom Überblick bis zur Aktion, höchstens sieben Unterpunkte je Bereich, Erstklick-Trefferquote als Zielwert ≥ 80 % bei 20 Standardaufgaben.

Rechnung zur Tragfähigkeit der Struktur. Annahme: 8 Bereiche mit je höchstens 7 Unterpunkten ergeben höchstens 8 × 7 = 56 Menüeinträge. Der Aufgabenkatalog in 18.2 umfasst 38 Standardaufgaben. Würden Aufgaben als Menüeinträge geführt, entstünden 56 + 38 = 94 Einträge, also eine Vergrößerung um 68 %. Deutung: die Regel "Aufgaben werden am Objekt erledigt, nicht im Menü gestartet" ist keine Stilfrage, sondern die Bedingung dafür, dass die Menübreite mit dem Funktionsumfang nicht mitwächst. Der Preis ist, dass ein Bediener, der eine Aufgabe ohne Objektbezug sucht, sie nur über die Suche findet; das ist der Grund, warum die Suche gleichwertiger Einstieg und nicht Beiwerk ist.

## 18.2 Aufgabenkatalog und der Nachweis für INV-14

Eine Entscheidung ist nach INV-14 ein Pflichtfeld oder eine Auswahl ohne mögliche Vorbelegung. Bestätigungen, optionale Felder und Freitextnotizen zählen nicht. Die Spalte "Quelle der Vorbelegung" erfüllt zugleich INV-15: jede Vorbelegung hat eine benannte Quelle, die im Vorgangsprotokoll steht.

| # | Aufgabe | Bereich | Entsch. | Entscheidungsfelder | Vorbelegte Felder | Quelle der Vorbelegung |
|---|---|---|---|---|---|---|
| 1 | Nutzer anlegen | Personen & Gruppen | 2 | Anzeigename, Gruppen/Rollen | Anmeldename, Mandant, Sprache, Authentisierungsmittel, Gültigkeit, Netzzone | Ableitung (Anmeldenamensregel, Sitzungsmandant), Richtlinie (Authentisierung, Sprache) |
| 2 | Nutzer sperren | Personen & Gruppen | 1 | Person | Wirkungszeitpunkt (sofort), Datenbehandlung (bleibt) | Richtlinie |
| 3 | Nutzer ausscheiden lassen | Personen & Gruppen | 2 | Ausscheidedatum, Übernehmer des Postfachs | Ablauf aller Zuweisungen, Gerätesperrung, Adresssperrfrist 12 Monate | Ableitung (Zuweisungsgraph), Richtlinie, K-25 |
| 4 | Gruppe anlegen | Personen & Gruppen | 2 | Name, Art (Rechte/Verteiler/Geltungsbereich) | Mandant, Mitgliedschaftsregel (statisch) | Ableitung, Richtlinie |
| 5 | Mitglied hinzufügen | Personen & Gruppen | 1 | Person oder Gerät | Wirkungszeitpunkt, Herkunft (direkt) | Ableitung |
| 6 | Administratorrolle vergeben | Mandanten & Rechte | 3 | Person, Rolle, Befristungsende | Geltungsbereich, Freigabepflicht, Auditstufe | Ableitung (Rollendefinition), Richtlinie |
| 7 | Dienstkonto anlegen | Personen & Gruppen | 2 | Zweck, Anmeldemittel (Zertifikat/Token) | Ablaufdatum, erlaubte Quelladressen, Mandant | Richtlinie (Pflichtablauf), Ableitung (Netzzone des Zwecks) |
| 8 | Gästezugang anlegen | Personen & Gruppen | 3 | Anzeigename, Zieldienst, Gültigkeitsende | Rolle, Mandant, Authentisierungsmittel, Netzzone | Richtlinie (Gästerichtlinie), Ableitung |
| 9 | Gerät aufnehmen (siehe [Kapitel 13](13-geraeteverwaltung.md)) | Geräte | 2 | Eigentümer, Geräteart | Netzzone, Zertifikatsprofil, Laufzeit 365 d, Hardwarebindung | Ableitung (Eigentümer → Gruppe → Netzzone), K-13 |
| 10 | Gerät sperren (verloren) | Geräte | 1 | Gerät | Zertifikatssperrung, Sperrlistenverteilung sofort, Netzzugang entzogen | Ableitung |
| 11 | Gerät ausmustern | Geräte | 1 | Gerät | Sperrung, Aufbewahrungsfrist, Eigentümerbezug gelöst | Richtlinie, K-25 |
| 12 | Dienst hinzufügen | Dienste | 3 | Produkt, Name, Veröffentlichungsart | Platzierung, Ressourcenbudget, Datensicherheitsstufe, Speicherbereich, Abhängigkeiten | Ableitung (Platzierung), Katalogeintrag (Budget), Richtlinie (Datensicherheitsstufe) |
| 13 | Dienst veröffentlichen | Dienste / Netz & Namen | 3 | Hostname, Sichtbarkeit, Zugriffskreis | Domäne, Protokoll, Zertifikat, Proxyroute, DNS-Eintrag, Netzfreigabe | Ableitung (Objektgraph), Richtlinie (Vorgabedomäne) |
| 14 | Dienst verschieben | Dienste | 1 | Zielknoten oder "System entscheiden" | Vorgehensweise, Datenverlagerung, Unterbrechungsdauer | Ableitung (Platzierung, Speicherbereich) |
| 15 | Dienst aktualisieren | Dienste | 1 | Zeitpunkt (sofort/Fenster) | Zielfassung, Rücksprungpunkt, Reihenfolge, Vorabprüfung | Ableitung (Kanal, Katalogeintrag), K-23 |
| 16 | Dienst entfernen | Dienste | 2 | Dienst, Datenbehandlung (aufbewahren/vernichten) | Rückbau der Veröffentlichungen, Fremdkontenbehandlung, Aufbewahrungsfrist 30 d | Ableitung, K-25 |
| 17 | Datensicherheitsstufe ändern | Dienste | 1 | Zielstufe | Technik, Replikatorte, resultierendes RPO, Umlagerungsplan | Ableitung (Knotenzahl), K-08 bis K-10 |
| 18 | Domäne anlegen | Netz & Namen | 3 | Name, Sichtbarkeit, Geltungsbereich | DNSSEC, Serie, Resolver-Sicht, Standard-TTL, Mandant | Ableitung, Richtlinie |
| 19 | DNS-Eintrag anlegen | Netz & Namen | 3 | Art, Name, Wert | Sicht, TTL, Domäne, Quelle (handeingegeben) | Ableitung (Domänenkontext), Richtlinie (TTL) |
| 20 | Maildomäne einrichten | Netz & Namen | 2 | Domäne, Anbieterbindung | DKIM-Schlüssel, MX-, SPF-, DMARC-, MTA-STS-, TLS-RPT-Einträge, Richtlinienstufe | Ableitung (Konnektorbindung), Richtlinie |
| 21 | Mail hinzufügen | Personen & Gruppen | 2 | Maildomäne, lokaler Teil | Postfachart, Ablageort, Primärkennzeichen, Berechtigte, Kontingent | Ableitung (Subjektart, Maildomäne → Bindung), Richtlinie |
| 22 | Geteiltes Postfach anlegen | Personen & Gruppen | 3 | Anzeigename, Maildomäne + lokaler Teil, berechtigte Gruppe | Ablageort, Sendeberechtigung, Kontingent, Postfachart (geteilt) | Ableitung, Richtlinie |
| 23 | Netzzone anlegen | Netz & Namen | 2 | Name, Art (Overlay/VLAN) | Adressbereich, Resolver-Sicht, Default-Deny, Mandant | Ableitung (Adressplan), INV-10 |
| 24 | Knoten koppeln | Knoten & Speicher | 2 | Kopplungscode, Zweck (Redundanz / weiterer Verwaltungsknoten / Dienste verteilen) | Knotenname, Rollen, Fehlerzone, Abbildversion, Netzzone, Zertifikat | Ableitung (Zweck → Rollen; Netztopologie → Fehlerzonenvorschlag) |
| 25 | Knoten räumen | Knoten & Speicher | 1 | Knoten | Zielknoten je Dienst, Reihenfolge, erwartete Unterbrechungen | Ableitung (Platzierung) |
| 26 | Knoten entkoppeln | Knoten & Speicher | 1 | Knoten | Zertifikatssperrung, Rollenneuverteilung, Datenbehandlung | Ableitung |
| 27 | Mandant anlegen | Mandanten & Rechte | 2 | Anzeigename, Isolationsstufe M0–M3 | Zwischen-CA, Hauptschlüssel, Sicherungsziel, Netzzone, Standardrichtlinien | Ableitung (Stufe → Netz- und Speichervorgaben), Richtlinie |
| 28 | Kundenzugang anlegen | Mandanten & Rechte | 2 | Person oder Gruppe, Umfang (Dienstauswahl) | Rolle (lesend), Geltungsbereich, Befristung, Auditstufe | Richtlinie (Kundenrichtlinie), Ableitung |
| 29 | Freigabeweg festlegen | Mandanten & Rechte | 3 | Änderungsart, freigebende Rolle, Vieraugenpflicht ja/nein | Geltung, Eskalationsfrist, Protokollumfang | Richtlinie, Ableitung |
| 30 | Freigabe erteilen | Verlauf & Nachweis | 1 | Zustimmung/Ablehnung | Vorgang, Wirkungsvorschau, Freigebender, Zeitstempel | Ableitung (Vorgangsobjekt) |
| 31 | Zertifikat prüfen | Netz & Namen | 0 | — | Inhaber, Aussteller, Restlaufzeit, Sperrstatus, Erneuerungsfenster | Ableitung; reine Ansicht ohne Entscheidung |
| 32 | Objekt wiederherstellen | Verlauf & Nachweis | 2 | Wiederherstellungspunkt, Umfang (Objekt/Speicherbereich) | Ziel, Prüfstatus, abgeleitete Artefakte werden neu berechnet | Ableitung, INV-09 |
| 33 | Installation wiederherstellen | Verlauf & Nachweis | 2 | Wiederherstellungspunkt, Zielhardware | Reihenfolge, Konvergenzplan, Nutzdatenrückspielung je Speicherbereich | Ableitung, K-11 |
| 34 | Plattformaktualisierung einspielen | Verlauf & Nachweis | 2 | Zielfassung, Zeitfenster | Reihenfolge der Knoten, Beobachtungsfenster 30 min, Rückfallregel (siehe [Kapitel 21](21-betrieb-updates.md)) | K-23, Ableitung |
| 35 | Audit exportieren | Verlauf & Nachweis | 2 | Zeitraum, Filter (Objekt/Akteur/Aktion) | Format, Signatur, Kettennachweis, Mandantenfilter | Richtlinie, INV-19, INV-23 |
| 36 | Konnektorbindung einrichten | Mandanten & Rechte | 3 | Fremdsystem, Endpunkt, Geheimnis | Fähigkeitsliste aus `describe`, Feldeigentum, Abgleichintervall, Ausgangs-Positivliste | Ableitung (Manifest), INV-13, INV-21 |
| 37 | Geheimnis wechseln | Mandanten & Rechte | 1 | neues Geheimnis | betroffene Bindungen, Wechselzeitpunkt, Prüfung vor Umschaltung | Ableitung (Referenzgraph) |
| 38 | Richtlinie ändern | Mandanten & Rechte | 2 | Gegenstand, neuer Wert | Geltung, betroffene Objektmenge, Erzwingungsart, Version | Ableitung, INV-08 |

Auswertung des Katalogs. Summe der Entscheidungen 72 bei 38 Aufgaben, arithmetisches Mittel 72 / 38 = 1,89 Entscheidungen je Aufgabe. Maximum 3, erreicht bei 9 Aufgaben (Nummern 6, 8, 12, 13, 18, 19, 22, 29, 36), Minimum 0 bei einer reinen Ansicht. Damit ist INV-14 über den gesamten Katalog eingehalten und K-03 mit Reserve unterschritten. Die Zahlen sind Entwurfsfestlegungen, keine Messung; ihre Einhaltung wird im Bau gegen die maschinenlesbaren Aufgabendefinitionen geprüft, und ein zusätzliches Pflichtfeld bricht den Bau.

Bekannte Schwäche des Katalogs: die Aufgaben 12, 13, 18, 19, 22, 29 und 36 liegen am Maximum. Jede spätere Erweiterung dieser Formulare um ein Pflichtfeld verletzt INV-14. Die Erweiterung ist dann nicht abzulehnen, sondern zwingt zur Neuaufteilung der Aufgabe oder zur Einführung einer Richtlinie, aus der das Feld vorbelegt wird. Dass dieser Druck besteht, ist beabsichtigt; dass er an sieben Stellen sofort besteht, ist eine Enge, die im Betrieb spürbar wird.

## 18.3 Die Methode der Fragenbeseitigung

### Vier Behandlungsarten

| Kürzel | Behandlung | Wirkung auf die Frage | Preis |
|---|---|---|---|
| B1 | Vorgabe aus Richtlinie | Feld ist vorbelegt, abweichbar mit Begründungspflicht | Eine Richtlinie muss existieren und gepflegt werden; fehlt sie, ist die Aufgabe gesperrt, nicht um ein Feld erweitert |
| B2 | Ableitung aus dem Objektgraphen | Feld ist vorbelegt und als abgeleitet gekennzeichnet; Änderung nur über die Quelle | Der Graph muss die Information tragen; eine falsche Ableitung ist schwerer zu erkennen als eine falsche Eingabe |
| B3 | Verschiebung auf den Zeitpunkt des Zwangs | Frage entfällt jetzt und erscheint dort, wo die Antwort wirksam wird | Die Aufgabe wird zerlegt; der Bediener sieht nicht mehr alles an einer Stelle |
| B4 | Zusammenlegung zu einer Entscheidung höherer Ordnung | Mehrere Fragen werden durch eine fachliche Absicht ersetzt | Der Wirkungsraum wird kleiner; Sonderfälle jenseits der zusammengelegten Absicht sind nicht mehr einstellbar |

### Entscheidungsbaum

```
Frage F in einer Standardaufgabe
 |
 +-- 1. Ist die Antwort aus bereits vorhandenen Objekten eindeutig berechenbar?
 |        ja  -> B2 Ableitung.  Feld vorbelegt, Quelle = Objektpfad.
 |        nein
 +-- 2. Existiert oder lohnt eine Richtlinie mit Geltung fuer dieses Objekt?
 |        ja  -> B1 Vorgabe.    Feld vorbelegt, Quelle = Richtlinienkennung.
 |        nein
 +-- 3. Wird die Antwort erst zu einem spaeteren, ohnehin eintretenden
 |      Zeitpunkt wirksam, an dem sie erzwungen werden kann?
 |        ja  -> B3 Verschiebung. Frage entfaellt hier, Pflichtfeld dort.
 |        nein
 +-- 4. Bestimmt eine andere, bereits gestellte Frage die Antwort mit,
 |      sodass beide dieselbe fachliche Absicht ausdruecken?
 |        ja  -> B4 Zusammenlegung. Eine Entscheidung ersetzt beide.
 |        nein
 +-- 5. Frage bleibt. Sie zaehlt gegen INV-14.
          Bleiben nach allen vier Behandlungen mehr als drei Fragen,
          ist die Aufgabe falsch geschnitten: sie wird geteilt
          oder die Funktion wird nicht gebaut.
```

Die Reihenfolge ist nicht beliebig. B2 steht vorn, weil eine Ableitung einen beweisbar richtigen Wert liefert, während eine Richtlinie nur einen vertretbaren liefert. B3 und B4 stehen hinten, weil sie die Interaktionsstruktur ändern und damit teurer sind als eine Vorbelegung. Schritt 5 ist die einzige zulässige Antwort auf eine Frage, die keine der vier Behandlungen verträgt; ein fünftes Feld "Erweitert" gibt es nicht.

### Durchgerechnete Beispiele

**B1 an Aufgabe 12 "Dienst hinzufügen".** Die Datensicherheitsstufe ist eine Auswahl aus drei Werten mit unmittelbarer Kostenwirkung. Ohne Behandlung hat das Formular vier Pflichtfelder: Produkt, Name, Veröffentlichungsart, Datensicherheitsstufe. Das verletzt INV-14. Aus dem Objektgraphen ist die Stufe nicht berechenbar, weil sie eine Risikoaussage des Bedieners ist und nicht aus dem Katalogeintrag folgt; B2 scheidet aus. Eine Mandantenrichtlinie "Standarddatensicherheitsstufe" ist möglich und sinnvoll, weil die Antwort innerhalb eines Mandanten fast immer gleich lautet. Ergebnis: 4 − 1 = 3 Pflichtfelder. Der Preis steht in R-15-09 ([Kapitel 15](15-dienste-software.md)): fehlt die Richtlinie, wird kein viertes Feld eingeblendet, sondern die Handlung ist gesperrt und die fehlende Richtlinie wird benannt. Das ist unbequem und die einzige Bauweise, die INV-14 nicht schleichend aushöhlt.

**B2 an Aufgabe 21 "Mail hinzufügen".** Die naive Form verlangt sechs Angaben: Person, Postfachart, Ablageort, Maildomäne, lokaler Teil, Berechtigte. Die Ableitungskette nach dem Objektmodell aus [Kapitel 7](07-objektmodell.md):

```
Person (aus dem Aufrufkontext)          -> Feld "Person"      abgeleitet
Subjektart = Person                      -> Postfachart        = persoenlich
Maildomaene (Entscheidung)               -> Anbieterbindung    -> Ablageort
Postfachart = persoenlich                -> Berechtigte        = Eigentuemer
Richtlinie "Kontingent je Person"        -> Kontingent
```

Es bleiben zwei Entscheidungen: Maildomäne und lokaler Teil. 6 − 4 = 2, also eine Reduktion um 67 %. Deutung: der Ablageort, das wichtigste technische Merkmal des Postfachs, ist keine Frage mehr, weil er eine Eigenschaft der gewählten Maildomäne ist. Genau das ist gemeint, wenn das Zielbild sagt, es sei gleichgültig, wo das Postfach liegt. Gegenprobe der Grenze: existieren für eine Domäne zwei Anbieterbindungen, ist die Kette nicht eindeutig, die Ableitung scheitert und die Bindung wird zur dritten Entscheidung. Der Entwurf verbietet deshalb zwei aktive Anbieterbindungen je Maildomäne (siehe [Kapitel 14](14-mail.md)).

**B3 an Aufgabe 24 "Knoten koppeln".** Die Frage "wozu dient dieser Knoten" ist bei der Installation nicht beantwortbar, weil zum Installationszeitpunkt noch keine Kontrollebene existiert, der der Knoten zugeordnet wäre. Klassisch wird sie trotzdem dort gestellt, als Rollenauswahl im Installationsprogramm. Atrium verschiebt sie auf den Kopplungsvorgang, an dem sie unvermeidbar wird, weil die Rollenzuteilung ohne sie nicht berechenbar ist. Rechnung: Installation 0 Entscheidungen (unbeaufsichtigt, Ergebnis ist der Wartemodus mit Kopplungscode), Kopplung 2 Entscheidungen (Code, Zweck). Summe 2 statt der klassischen Aufteilung von mindestens 2 bei der Installation plus 2 bei der Aufnahme. Der Preis ist, dass zwischen Installation und Kopplung ein Knoten in einem Zustand steht, in dem er nichts tut; dieser Zustand ist mit Wartemodus benannt und in KANON 1 festgelegt, statt verschwiegen zu werden.

**B4 an Aufgabe 13 "Dienst veröffentlichen".** Klassisch getrennt zu beantworten sind: Proxyroute, TLS-Zertifikat und dessen Erneuerung, Firewallfreigabe mit Quellbereich, interner DNS-Eintrag, externer DNS-Eintrag. Das sind fünf Fragen mit je eigener Fehlermöglichkeit und ohne gemeinsame Klammer. Die Zusammenlegung ersetzt sie durch eine Entscheidung höherer Ordnung: Sichtbarkeit (intern/extern) und Zugriffskreis (Gruppe/Netzzone/öffentlich). Zusammen mit dem Hostnamen sind das drei Entscheidungen statt fünf plus Hostname plus Sichtbarkeit, also 3 statt 7, eine Reduktion um 57 %. Der Preis ist der Wirkungsraum: eine Veröffentlichung, die extern unter anderem Namen erreichbar sein soll als intern, ist keine Veröffentlichung, sondern zwei. Wer eine Proxyroute ohne DNS-Eintrag will, bekommt sie nicht. Diese Einschränkung ist bewusst und in INV-09 verankert.

### Gegenprobe an einem klassischen Einrichtungsablauf

Aufgabe: einen Server aufsetzen, auf dem ein Ticketsystem intern und extern erreichbar ist, Nutzer sich mit ihren Unternehmenskonten anmelden, der Dienst Mail versendet und die Daten gesichert werden. Zerlegung in die Fragen, die ein klassisches Server-Betriebssystem in Installation und Diensteinrichtung stellt, mit zugeordneter Behandlung.

| # | Frage im klassischen Ablauf | Behandlung | Begründung der Zuordnung |
|---|---|---|---|
| 1 | Sprache und Tastaturbelegung | bleibt | Trägt Information, die im System nicht existiert; Teil der Ersteinrichtung (K-01) |
| 2 | Zeitzone | B2 | Folgt aus der Sprach- und Standortwahl, korrigierbar in der Richtlinie |
| 3 | Partitionsschema | B2 | Folgt aus dem A/B-Abbildverfahren und der Trennung von System und Nutzdaten |
| 4 | Dateisystem | B2 | Festgelegt (ZFS für Nutzdaten, verifiziertes Abbild für das System) |
| 5 | Verschlüsselung ja/nein | B1 | Richtlinie; Vorgabe "ja", Schlüssel an TPM 2.0 gebunden |
| 6 | Rechnername | B2 | Abgeleiteter technischer Name nach KANON 3, DNS-tauglich, kollisionsauflösend |
| 7 | Netzkonfiguration statisch/dynamisch | B4 | Teil der Entscheidung "Netzzone", nicht eigenständig |
| 8 | Adresse, Maske, Gateway | B4 | Wie 7 |
| 9 | Resolveradressen | B2 | Folgt aus der Netzzone und der Resolver-Sicht |
| 10 | Serverrollen und Paketauswahl | entfällt | Es gibt keine Rollenauswahl; Funktion entsteht durch Dienste, nicht durch Installationsoptionen |
| 11 | Administratorkonto und Kennwort | bleibt | Identität eines Menschen, nicht ableitbar; Teil der Ersteinrichtung |
| 12 | Fernzugangsdienst aktivieren | B1 | Richtlinie; Vorgabe "geschlossen", Freischaltung nur als auditierter Vorgang |
| 13 | Automatische Sicherheitsaktualisierung | B1 | Richtlinie; Vorgabe "an", Ausrollung gestaffelt nach K-23 |
| 14 | Beitritt zu einem Verzeichnisdienst | B3 | Frage entsteht erst, wenn eine Konnektorbindung eingerichtet wird |
| 15 | Bezugsquelle der Dienstsoftware | B2 | Folgt aus dem signierten Katalogeintrag |
| 16 | Datenbankart und Ablageort | B2 | Folgt aus der Abhängigkeitsdeklaration des Katalogeintrags |
| 17 | Datenbankname, Benutzer, Kennwort | B2 | Abgeleitete technische Namen; das Geheimnis wird erzeugt, nie eingegeben (INV-20) |
| 18 | Webserverwahl | entfällt | Der Eingang ist Teil der Plattform, nicht eine Wahl je Dienst |
| 19 | Virtueller Host und Dokumentenwurzel | B4 | Teil der Veröffentlichung |
| 20 | Portwahl | B2 | Folgt aus dem Katalogeintrag und der Portmatrix in KANON 7 |
| 21 | Zertifikatsherkunft | B2 | Folgt aus der Sichtbarkeit: intern aus der eigenen CA, extern über den öffentlichen Weg |
| 22 | Erneuerungsverfahren und Zeitplan | B2 | Folgt aus K-13; Erneuerung ab zwei Dritteln der Laufzeit |
| 23 | Reverse-Proxy und seine Konfiguration | B4 | Teil der Veröffentlichung |
| 24 | Firewallfreigaben | B4 | Teil der Veröffentlichung; INV-09 verbietet die eigenständige Frage |
| 25 | Interner DNS-Eintrag | B4 | Teil der Veröffentlichung |
| 26 | Externer DNS-Eintrag und Delegierung | B3 | Entsteht beim Anlegen der externen Domäne, nicht bei der Dienstinstallation |
| 27 | Mailrelais des Dienstes | B2 | Folgt aus der Maildomäne des Mandanten |
| 28 | Absenderadresse des Dienstes | B2 | Abgeleitet aus Dienstname und Maildomäne |
| 29 | SPF-, DKIM-, DMARC-Einträge | B2 | Abgeleitete Artefakte der Maildomäne |
| 30 | Anbindung der Nutzerkonten, Filter, Attributabbildung | B2 | Folgt aus der Produktgrenzdeklaration des Katalogeintrags und dem Objektgraphen |
| 31 | Abbildung Gruppe auf Dienstrolle | B4 | Teil der Zuweisung; der Schalter am Nutzer ist die Entscheidung |
| 32 | Sicherungswerkzeug, Ziel, Zeitplan, Aufbewahrung | B1 | Richtlinie und Sicherungsplan des Mandanten |
| 33 | Wiederherstellungstest | B1 | Richtlinie; monatlich automatisiert nach K-24 |
| 34 | Überwachung und Empfänger | B2 | Gesundheitsproben folgen aus dem Katalogeintrag; Empfänger folgt aus der Rolle |
| 35 | Protokollrotation und Aufbewahrung | B1 | Richtlinie; Fristen aus K-25 |

Auswertung. Von 35 Fragen bleiben 2 als Ersteinrichtungsentscheidungen (1, 11), 2 entfallen ersatzlos (10, 18), 18 werden abgeleitet (B2), 6 durch Richtlinie vorbelegt (B1), 2 verschoben (B3) und 7 zusammengelegt (B4). Zusammen mit den beiden übrigen Ersteinrichtungsentscheidungen aus K-01 (Mandantenname, Basisdomäne) und den Entscheidungen der beiden beteiligten Standardaufgaben (Dienst hinzufügen 3, Dienst veröffentlichen 3) ergeben sich 4 + 6 = 10 Entscheidungen gegenüber 35 Fragen, also eine Reduktion um 1 − 10/35 = 71,4 %.

Deutung und ehrliche Einschränkung. Die Zahl 35 ist eine Zerlegung, keine Messung an einem konkreten Produkt; ein anderer Ablauf ergibt eine andere Zahl. Belastbar ist nicht der Prozentwert, sondern die Verteilung: 18 der 35 Fragen sind reine Ableitungen, die nur deshalb gestellt werden, weil das klassische System kein Objektmodell hat, in dem die Antwort schon steht. Die zwei bleibenden Fragen sind genau die, die Information tragen, welche nirgends im System existiert: die Sprache des Menschen und seine Identität. Das ist die Grenze der Methode: sie beseitigt keine Frage, deren Antwort außerhalb des Systems liegt.

## 18.4 Auswahlzeit und Zielgrößen

### Hick-Hyman als Modell

Das Hick-Hyman-Modell beschreibt die Entscheidungszeit bei einer Auswahl aus n gleichwahrscheinlichen Alternativen als

```
RT = a + b * log2(n + 1)
```

mit a als auswahlunabhängigem Anteil (Wahrnehmung, Bewegungsvorbereitung) und b als Steigung. Das ist ein Modell, keine Messung. Für Atrium liegen keine Werte für a und b vor, und es werden hier keine erfunden. Nutzbar ist das Modell trotzdem, weil sich zwei Gestaltungsregeln allein aus seiner Form ableiten lassen, ohne a und b zu kennen.

**Regel 1: Vorbelegung schlägt Aufteilung.** Vergleich einer flachen Auswahl aus 16 Alternativen mit einer zweistufigen Auswahl aus 4 mal 4:

```
flach:      RT1 = a + b*log2(17)  = a + 4,087b
zweistufig: RT2 = 2a + 2b*log2(5) = 2a + 4,644b
Differenz:  RT2 - RT1 = a + 0,557b
```

Für jedes a > 0 und b > 0 ist die Differenz positiv, die Aufteilung also langsamer. Deutung: ein Untermenü, das eine Menge nur zerteilt, ohne sie fachlich zu verkleinern, verschlechtert die Auswahlzeit unabhängig von den konkreten Parameterwerten. Untermenüs sind deshalb nur dort zulässig, wo die erste Stufe die relevante Menge tatsächlich verkleinert, also filtert. Das ist die Begründung für die Grenze von sieben Unterpunkten je Bereich in K-26 und für die flache Trefferliste der Suche.

**Regel 2: Eine entfallene Entscheidung ist mehr wert als eine beschleunigte.** Eine Aufgabe mit drei Entscheidungen zu je fünf Alternativen kostet

```
3 * (a + b*log2(6)) = 3a + 7,755b
```

Entfällt eine Entscheidung durch Vorbelegung, kostet die Aufgabe 2a + 5,170b. Die Ersparnis beträgt a + 2,585b, also bei drei Entscheidungen ein Drittel des Gesamtaufwands, wieder unabhängig von den Parameterwerten. Deutung: die Drei-Entscheidungs-Regel aus INV-14 wirkt auf die Auswahlzeit stärker als jede Verkürzung einzelner Auswahllisten. Deshalb ist der Aufgabenkatalog die zentrale Kennzahl der Oberfläche und nicht die Klickzahl.

Grenze des Modells, offen benannt: Hick-Hyman setzt gleichwahrscheinliche, dem Bediener bekannte Alternativen voraus. In einer Verwaltungsoberfläche sind die Alternativen ungleich wahrscheinlich und teilweise unbekannt; die Suchzeit in einer unbekannten Liste folgt eher einem linearen als einem logarithmischen Verlauf. Das Modell taugt daher zur Begründung der Richtung, nicht zur Vorhersage von Zeiten. Jede Zahl, die aus ihm eine Sekundenangabe machen würde, wäre erfunden.

### Zielgrößen von Bedienelementen

Das Fitts-Modell beschreibt die Zeigezeit als MT = a + b · log₂(2D/W + 1) mit Distanz D und Zielbreite W. Auch hier sind a und b unbekannt; die relative Aussage ist es nicht. Bei einer angenommenen Distanz D = 400 Bildpunkten ergibt sich

```
W = 24 px: log2(2*400/24 + 1) = log2(34,33) = 5,102
W = 44 px: log2(2*400/44 + 1) = log2(19,18) = 4,262
Differenz: 0,840, relativ 0,840/5,102 = 16,5 %
```

Deutung: die Vergrößerung eines Ziels von 24 auf 44 Bildpunkte senkt den Schwierigkeitsindex um 16,5 %, unabhängig von den Parameterwerten. WCAG 2.2 Stufe AA verlangt als Mindestzielgröße 24 mal 24 CSS-Bildpunkte; INV-31 bindet Atrium an diese Stufe. Atrium setzt darüber hinaus als Zielwert 44 mal 44 CSS-Bildpunkte für jede Hauptaktion einer Ansicht und für jedes Element in einer Zeilenliste, weil dort Distanz und Zielhäufigkeit am größten sind. Der Zielwert ist eine Festlegung, keine Ableitung aus einer Messung; die 16,5 % sind ein Modellergebnis und keine Zeitersparnis in Sekunden.

## 18.5 Die Übersicht "wo läuft was"

### Vier Ebenen

```
Mandant  (Isolationsstufe, Zahl der Fehlerdomaenen, Redundanzangabe)
  +- Knoten     (Zustand, Rollen, Fehlerzone, Abbildversion, Leasestatus)
  |    +- Dienst      (Gesundheit, Platzierungsbegruendung, Datensicherheitsstufe, RPO)
  |         +- Abhaengigkeit  (Dienst -> Dienst, Dienst -> Konnektorbindung,
  |                            Dienst -> Speicherbereich, Dienst -> Veroeffentlichung)
  +- Speicherbereich (Stufe, Replikatorte, Replikationsstand, letzter geprueft. Punkt)
```

Jede Ebene ist in beiden Richtungen navigierbar: vom Knoten zu den Diensten, die er trägt, und vom Dienst zu dem Knoten, auf dem er liegt. Die Abhängigkeitsebene ist die einzige, die Kanten statt Knoten darstellt; sie beantwortet die Frage, was ausfällt, wenn ein bestimmtes Objekt ausfällt, und wird deshalb auch rückwärts gelesen ("wovon hängt dieser Dienst ab" und "was hängt von diesem Dienst ab").

### Zustandsdarstellung

| Zustand | Bedeutung | Darstellung neben dem Text |
|---|---|---|
| läuft | Istzustand entspricht Sollzustand, letzte Beobachtung innerhalb der Frist | Form und Beschriftung, nie nur Farbe |
| wird versorgt | Vorgang läuft, Teilzustände je Zielsystem sichtbar (INV-12) | Fortschritt mit benanntem Endzustand |
| abweichend | Istzustand weicht vom Sollzustand ab, Abgleich läuft oder scheiterte | Abweichung mit Quellobjekt und Grund |
| gestört | Dienst oder Knoten meldet Fehler | Verweis auf den verursachenden Vorgang oder das verursachende Objekt |
| angehalten | Sollzustand sieht keinen Lauf vor | Urheber und Zeitpunkt der Anhaltung |
| unbekannt | Beobachtung älter als die Veraltungsschwelle | Zeitpunkt der letzten Beobachtung im Klartext |

Kein Zustand wird allein durch Farbe getragen; jeder Zustand trägt eine Beschriftung und eine unterscheidbare Form. Das folgt aus INV-31 und gilt gleichermaßen für Ausdrucke und Exporte.

### Dauerhafte Degradationsanzeige und vertragene Knotenausfälle

INV-18 verlangt, dass die Übersicht dauerhaft zeigt, wie viele Knotenausfälle derzeit noch vertragen werden. Die Zahl ist das Minimum aus zwei Achsen:

```
f_stimm = floor((n_stimm - 1) / 2)       n_stimm aus {1, 3, 5} (INV-05)
f_daten = (Zahl der Replikate in getrennten Fehlerzonen) - Mehrheitsbedarf
f       = min(f_stimm, f_daten)
```

| Aufbau | n_stimm | f_stimm | Datensicherheitsstufe | f_daten | f | Anzeige |
|---|---|---|---|---|---|---|
| Ein Knoten | 1 | 0 | Lokal | 0 | 0 | "Redundanz: keine" |
| Zwei Knoten plus Zeuge | 3 | 1 | Gespiegelt (asynchron) | 0 verlustfrei | 0 | "0 Ausfälle verlustfrei; 1 Ausfall mit Datenverlust bis 15 min" |
| Zwei Knoten plus Zeuge | 3 | 1 | Synchron gespiegelt | 1 | 1 | "1 Knotenausfall" |
| Drei Knoten | 3 | 1 | Synchron gespiegelt | 1 | 1 | "1 Knotenausfall" |
| Fünf Knoten | 5 | 2 | Synchron gespiegelt (3 Replikate) | 1 | 1 | "1 Knotenausfall (begrenzt durch die Datenachse)" |

Der letzte Fall ist der lehrreiche: fünf Stimmknoten vertragen zwei Ausfälle der Kontrollebene, drei Replikate aber nur einen Ausfall der Daten. Die angezeigte Zahl ist 1, und die Anzeige nennt die begrenzende Achse. Eine Anzeige, die nur die Stimmachse nennt, wäre eine Falschaussage; genau das ist der Fehler, den INV-18 verhindern soll. Die Gesamtanzeige der Installation ist das Minimum über alle Dienste, und der begrenzende Dienst wird namentlich genannt und verlinkt. Die Herleitung der Datenachse steht in [Kapitel 16](16-cluster.md) und [Kapitel 17](17-speicher-backup.md).

Weitere dauerhaft angezeigte Degradationen: ein Dienst ohne geprüften Wiederherstellungspunkt trägt das am Dienst, nicht in einem Bericht; ein Wiederherstellungspunkt ohne bestandene Prüfung trägt den Zustand "ungeprüft"; eine Konnektorbindung im Zustand "abweichend" trägt ihn an jedem Objekt, das von ihr versorgt wird, nicht nur an sich selbst.

### Datierung des Istzustands

INV-28 verlangt an jeder Istansicht den Zeitpunkt der letzten Beobachtung und die Angabe, ob und wie der Istzustand vom Sollzustand abweicht. Umsetzung: jede Istangabe trägt den Beobachtungszeitpunkt als absolute Zeit mit Zeitzonenangabe und zusätzlich als Alter. Aus K-16 folgt, dass eine Änderung im Istzustand binnen 30 s sichtbar sein soll; die Veraltungsschwelle ist als Zielwert das Dreifache, also 90 s. Überschreitet das Alter diese Schwelle, wechselt die Angabe in den Zustand "unbekannt", und die Ansicht zeigt statt eines veralteten Werts den Zeitpunkt, zu dem der Wert zuletzt galt. Eine Istansicht ohne Beobachtungszeitpunkt bricht den Bau.

## 18.6 Interaktionsmuster

### Wirkungsvorschau vor jeder Änderung

Jeder Vorgang zeigt vor der Ausführung eine Liste konkreter Wirkungen (INV-08). Die Vorschau ist nebenwirkungsfrei; `plan` verändert weder Atrium noch ein Fremdsystem. Ihr Aufbau ist fest:

| Abschnitt | Inhalt | Invariante |
|---|---|---|
| Was entsteht | Neue Objekte mit Art und Name | INV-08 |
| Was sich ändert | Objekt, Feld, Wert vorher, Wert nachher | INV-08 |
| Was verschwindet | Objekte und abgeleitete Artefakte, die verfallen | INV-11 |
| Je Zielsystem | Fremdsystem, geplante Aktion, Feldeigentum | INV-12, INV-13 |
| Kostenwirkung | Kostenwirksame Aktionen je Zielsystem, ausdrücklich als solche benannt | INV-29 |
| Nicht rücknehmbar | Aktionen ohne Rücknahme, einzeln aufgeführt | INV-11 |
| Was sich nicht ändert | Ausdrücklich genannte Nichtwirkungen, wo eine Wirkung vermutet wird | — |

Der letzte Abschnitt ist ungewöhnlich und notwendig. Die häufigste Rückfrage nach einer Änderung lautet, ob etwas anderes betroffen ist; sie lässt sich nur beseitigen, indem die Vorschau die naheliegenden Nichtwirkungen benennt, etwa dass eine Sperrung einer Person deren Daten nicht löscht. Die Menge der genannten Nichtwirkungen ist je Vorgangsart fest deklariert und nicht berechnet; das ist eine Schwäche, weil eine unvollständige Deklaration wie eine Zusicherung wirkt.

Die Vorschau hat ein Zeitbudget von p95 ≤ 2 s (K-18). Sie erfordert je beteiligter Konnektorbindung einen `plan`-Aufruf; bei mehr als sieben Bindungen ist das Budget nicht haltbar, und die Vorschau wird abschnittsweise nachgeladen, wobei der noch fehlende Teil benannt wird. Eine Vorschau, die still unvollständig ist, wäre schlimmer als eine langsame.

### Teilerfolge

Ein Vorgang, der mehrere Zielsysteme berührt, endet nie im Zustand "fertig", solange eines aussteht (INV-12). Die Darstellung ist eine Zeile je Zielsystem mit Zustand, Grund im Fehlerfall, Zeitpunkt des letzten Versuchs und der Angabe, was deshalb noch nicht wirkt. Der Gesamtzustand ist die Verdichtung: "abgeschlossen" nur bei ausnahmslos erfolgreichen Zeilen, sonst "teilweise fehlgeschlagen mit benannten Resten". Die Wiederholung ist je Zeile möglich und trägt denselben Idempotenzschlüssel (INV-07), sodass eine Wiederholung nach einem Teilfehler die bereits erfolgreichen Zielsysteme nicht erneut ändert.

### Rücknahme

Jeder Vorgang trägt einen Rücksprungverweis. Drei Klassen werden unterschieden und in der Vorschau benannt:

| Klasse | Bedeutung | Beispiel aus dem Katalog |
|---|---|---|
| rücknehmbar | Rücknahme stellt den Ausgangszustand vollständig her | Zuweisung setzen, Veröffentlichung anlegen, Richtlinie ändern |
| rücknehmbar mit Rest | Rücknahme stellt den Sollzustand her, eine Fremdwirkung bleibt | Fremdkonto wurde angelegt und wird deaktiviert, nicht gelöscht |
| nicht rücknehmbar | Keine Rücknahme möglich | Postfach gelöscht, Datenschlüssel vernichtet, Mailadresse in Sperrfrist |

Nicht rücknehmbare Aktionen sind nie Teil einer Massenaktion ohne Einzelaufstellung (INV-11).

### Massenaktionen

Eine Massenaktion zeigt vor der Ausführung die vollständige Einzelaufstellung der betroffenen Objekte, nicht deren Anzahl. Übersteigt die Menge die darstellbare Länge, wird sie als Datei zum Herunterladen angeboten und die Aktion bleibt gesperrt, bis die Aufstellung abgerufen oder ausdrücklich übersprungen wurde; der Übersprung ist ein Auditereignis. Die Ausführung ist je Objekt ein eigener Teilvorgang mit eigenem Zustand, sodass ein Fehler an Objekt 47 die Objekte 1 bis 46 nicht zurücknimmt und die Objekte 48 bis n nicht still überspringt.

### Bestätigung nur, wo sie etwas bedeutet

Eine Bestätigung wird genau dann verlangt, wenn mindestens eine der drei Bedingungen zutrifft: die Aktion ist nicht rücknehmbar, sie hat eine Kostenwirkung in einem Fremdsystem (INV-29), oder sie betrifft mehr als eine Schwellenzahl von Objekten. Zielwert der Schwelle: 10 Objekte oder jede Aktion, die eine Mandantengrenze berührt.

Rechnung zur Wirkung der Regel. Annahme: eine mittlere Installation führt 100 Vorgänge im Monat aus; Annahme: 8 % davon sind nicht rücknehmbar, kostenwirksam oder überschreiten die Schwelle. Ohne Regel entstehen 100 Bestätigungsdialoge im Monat, mit Regel 8. Deutung: nur im zweiten Fall trägt ein Bestätigungsdialog Information. Bestätigt ein Bediener 100-mal im Monat folgenlos, ist die 101. Bestätigung eine Handbewegung und keine Entscheidung; die Regel schützt nicht vor Klicks, sondern vor Gewöhnung. Die Zahlen sind Annahmen zur Verdeutlichung der Größenordnung, keine Messung.

## 18.7 Fehlerbehandlung

### Aufbau jeder Meldung

Jede Meldung nennt drei Dinge in dieser Reihenfolge: Ursache, Wirkung, nächste Handlung. Dazu tritt ein Verweis auf den Vorgang, in dessen Protokoll der vollständige Hergang einschließlich des Originaltexts eines Fremdfehlers steht. Fehlt eines der drei Stücke, ist der Fehlerfall nicht fertig entwickelt.

Sicherheitsregeln für Meldungstexte: keine Pfadangaben, keine internen Kennungen außer der Vorgangs- und Objektkennung, kein Fremdsystem-Rohtext in der Oberfläche, keine Unterscheidung zwischen "Konto existiert nicht" und "Kennwort falsch", keine Geheimnisfragmente. Der Originaltext im Vorgangsprotokoll unterliegt derselben Geheimnismaskierung wie jede andere Ausgabe (INV-20).

### Übersetzung von Fremdfehlern

Ein Konnektormanifest deklariert je Fehlerkennung des Fremdsystems einen Textschlüssel, eine Wirkungsklasse und eine Handlungsklasse. Die Oberfläche zeigt den übersetzten Text; der Originaltext geht unverändert in das Vorgangsprotokoll. Der übersetzte Text unterliegt der Positivliste erlaubter Oberflächenbegriffe (INV-16), die Übersetzungstabelle wird im Bau mitgeprüft.

Fehlt für eine Fehlerkennung ein Eintrag, greift eine Rückfallmeldung, die Zielsystem, versuchte Aktion, Wirkung auf den Vorgang und als nächste Handlung die Wiederholung oder das Aussetzen der Bindung nennt. Diese Rückfallmeldung ist ehrlich schwächer als eine übersetzte: sie nennt keine Ursache, sondern nur die Tatsache des Scheiterns. Die Abdeckungsquote der Übersetzungstabelle je Konnektor ist deshalb eine Kennzahl der Konnektorreife (siehe [Kapitel 9](09-konnektoren.md)), und ein Konnektor mit niedriger Abdeckung erzeugt Meldungen, die INV-17 formal einhalten und dem Bediener trotzdem wenig helfen.

### Verbot des Kommandozeilenverweises

INV-17 verbietet, dass eine Meldung als einzige Lösung auf die Kommandozeile verweist. Die Prüfung ist eine Musterprüfung aller Meldungstexte und aller Textschlüssel der Übersetzungstabelle auf Befehlsnamen, Dateipfade und Konsolenaufforderungen. Ein Treffer bricht den Bau. Der Verweis auf `atriumctl` als zusätzliche, nicht als einzige Möglichkeit ist zulässig, aber in den Standardaufgaben nicht vorgesehen.

### Drei Meldungen in schlechter und guter Form

Die folgenden Beispiele sind Inhaltsschemata, keine Endtexte; die sprachliche Ausformulierung entsteht in der Oberflächentextpflege und unterliegt der Positivliste.

```
Fall 1  Geheimnis der Postfachbindung abgelaufen

schlecht: "Fehler 0x80070005 beim Anwenden der Richtlinie."
          -> Fehlernummer ohne Text, keine Ursache, keine Wirkung,
             keine Handlung. Verstoesst gegen INV-12 und INV-17.

gut:      Ursache          Die Verbindung zum Postfachanbieter hat die
                           Anmeldung abgelehnt; das hinterlegte Geheimnis
                           ist seit <Zeitpunkt> abgelaufen.
          Wirkung          Fuer 3 von 5 Personen wurde kein Postfach
                           angelegt. Der Vorgang steht auf "teilweise
                           fehlgeschlagen". Anmeldung und alle uebrigen
                           Dienste sind nicht betroffen.
          Naechste Handlung  Geheimnis erneuern [Verweis auf die Bindung],
                           danach "Vorgang wiederholen" [Verweis].
          Nachweis         Originaltext im Vorgangsprotokoll [Verweis].
```

```
Fall 2  Oeffentliches Zertifikat nicht ausstellbar

schlecht: "Zertifikat konnte nicht ausgestellt werden.
           Bitte pruefen Sie die Konfiguration."
          -> keine Ursache, keine Wirkung, Handlung ohne Ziel.

gut:      Ursache          Fuer <Name> schliesst ein CAA-Eintrag der
                           Domaene die gewaehlte Ausgabestelle aus
                           (RFC 8659).
          Wirkung          Die Veroeffentlichung bleibt auf "wird
                           eingerichtet". Der Dienst ist intern
                           erreichbar, extern nicht.
          Naechste Handlung  CAA-Eintrag an der Domaene aendern [Verweis]
                           oder Sichtbarkeit auf "intern" setzen [Verweis].
          Nachweis         Vorgang <Kennung> [Verweis].
```

```
Fall 3  Platzierung nicht moeglich

schlecht: "Operation fehlgeschlagen. Details siehe Systemprotokoll
           auf dem Knoten."
          -> verweist als einzige Loesung auf einen Weg ausserhalb
             der Konsole. Verstoesst gegen INV-17.

gut:      Ursache          Kein zugelassener Knoten hat freien Speicher
                           fuer den angeforderten Speicherbereich
                           (benoetigt <X>, groesster freier Bereich <Y>
                           auf <Knoten>).
          Wirkung          Es wurde kein Dienst angelegt und keine
                           Aenderung wirksam.
          Naechste Handlung  Speicherbereich verkleinern [Verweis],
                           weiteren Knoten zulassen [Verweis]
                           oder Knoten aufnehmen [Verweis].
          Nachweis         Vorgang <Kennung> mit Ausschlussliste
                           je Knoten [Verweis].
```

## 18.8 Benachrichtigungen

### Aufgabenliste statt Warnungsflut

Es gibt keine Warnungsliste. Es gibt eine Aufgabenliste im Überblick, deren Einträge Handlungen sind. Ein Ereignis wird nur dann zu einem Eintrag, wenn alle vier Bedingungen gelten:

1. Es gibt einen benannten Empfänger, der das Recht hat, zu handeln.
2. Es gibt eine Handlung in der Atrium Console, die den Zustand beendet.
3. Ohne Handlung tritt bis zu einer benennbaren Frist ein benennbarer Schaden ein.
4. Der Zustand ist nicht bereits am betroffenen Objekt sichtbar und wird dort ohnehin gesehen.

Alles andere ist Zustand am Objekt, nicht Benachrichtigung. Ein Dienst, der läuft, erzeugt keine Meldung. Eine erfolgreiche Zertifikatserneuerung erzeugt keine Meldung, sondern ein Auditereignis; die Aufbewahrung und Auswertung des Auditstroms steht in [Kapitel 19](19-mandanten-rechte-audit.md).

### Priorisierungsregel

Die Aufgabenliste ist total geordnet, ohne erfundene Gewichte:

```
1. Frist bis zum Schadenseintritt, aufsteigend
2. bei gleicher Frist: Zahl betroffener Personen, absteigend
3. bei Gleichstand: Erzeugungszeitpunkt, aufsteigend
```

Die Ordnung ist deterministisch und erklärbar; jede Zeile nennt ihre Frist im Klartext. Ein Punktesystem mit gewichteter Summe wird verworfen, weil die Gewichte nicht herleitbar wären und die Reihenfolge damit unerklärbar würde.

### Zusammenfassung gleichartiger Ereignisse

Der Verdichtungsschlüssel ist das Tripel aus Ereignistyp, Ursachenobjekt und Vorgang. Ereignisse mit gleichem Schlüssel erzeugen einen Eintrag mit Anzahl, Zeitraum und Verweis auf die Einzelaufstellung. Beispiel: 40 fehlgeschlagene Versorgungen wegen derselben abgelaufenen Bindung sind ein Eintrag mit der Zahl 40, nicht 40 Einträge.

Rechnung zur Größenordnung. Aus K-13 folgen bei 500 veröffentlichten Namen im Mittel 500 / 90 = 5,56 Zertifikatserneuerungen je Tag, also 5,56 × 365 = 2.029 Erneuerungen je Jahr. Würde jede Erneuerung gemeldet, entstünden 2.029 Einträge im Jahr. Nach Bedingung 2 und 3 rechtfertigt eine erfolgreiche Erneuerung keinen Eintrag, weil keine Handlung nötig ist. Annahme einer Fehlerquote von 1 % ergibt 20 Einträge im Jahr. Verhältnis 2.029 zu 20, also Faktor 100. Deutung: die Bedingungen für einen Eintrag sind der einzige wirksame Hebel gegen die Flut; jede Verfeinerung der Darstellung wäre gegenüber diesem Faktor unerheblich. Die Fehlerquote ist eine Annahme, keine Messung.

## 18.9 Formularregeln

### Vorbelegungsquellen

Jede Vorbelegung nennt ihre Quelle (INV-15). Zulässig sind genau vier Quellen:

| Quelle | Beispiel | Darstellung | Änderbarkeit |
|---|---|---|---|
| Richtlinie | Datensicherheitsstufe, Kontingent, Gültigkeitsdauer | Richtlinienname und Verweis | Abweichung möglich, Begründungspflicht bei harter Erzwingung |
| Ableitung aus dem Objektgraphen | Ablageort eines Postfachs, Netzzone eines Geräts | Objektpfad der Ableitung | Nur über die Quelle änderbar (INV-09) |
| Vorheriger Wert desselben Bedieners | zuletzt gewählte Maildomäne | Hinweis auf den letzten Vorgang | Frei änderbar |
| Katalogeintrag | Ressourcenbudget, Speicherbereichsgröße, Abhängigkeiten | Katalogeintrag und Fassung | Nur innerhalb der deklarierten Grenzen |

Die Quellenangabe ist nicht nur Anzeige, sondern Teil des Vorgangsprotokolls. Ein Formularfeld ohne Quellenangabe bricht den Bau.

### Validierung am Rand

Die Eingabevalidierung geschieht gegen dasselbe Schema, das die API am Rand erzwingt. Das Schema ist die einzige Quelle beider Prüfungen; eine abweichende Prüfung in der Oberfläche existiert nicht. Regeln:

- Strikte Schemaprüfung mit Ablehnung unbekannter Felder, nicht nur Prüfung der bekannten.
- Keine Zeichenketteninterpolation in Abfragen, Aufrufe oder Schalen; alle Abfragen gegen das Lesemodell sind parametrisiert.
- Keine dynamische Auswertung von Eingaben; kein Ausdruck aus einem Formularfeld, einem Katalogeintrag oder einem Manifest wird ausgeführt.
- Längen-, Zeichen- und Formatgrenzen stehen im Schema, nicht im Oberflächencode.
- Vergleiche von Geheimnissen und Kopplungscodes laufen laufzeitkonstant.

### Sofortige Rückmeldung

Eine Prüfung, die lokal entscheidbar ist (Format, Länge, Zeichenvorrat, Pflichtfeld), meldet sich bei Verlassen des Feldes, nicht erst beim Absenden. Eine Prüfung, die eine Abfrage erfordert (Namenskollision, Verfügbarkeit eines lokalen Teils, Erreichbarkeit einer Domäne), zeigt einen ausdrücklichen Zwischenzustand "wird geprüft" mit Endzustand; sie blockiert die Eingabe nicht.

Regel: keine Fehlermeldung nach dem Absenden für etwas, das vorher prüfbar war. Ehrliche Ausnahme, die der Entwurf nicht auflöst: Eindeutigkeit ist erst im Schreibzeitpunkt garantiert. Zwei Bediener, die gleichzeitig denselben lokalen Teil vergeben, können die Vorabprüfung beide bestehen. Der Konflikt wird deshalb als benannter Sonderfall behandelt: die Eingaben bleiben erhalten, das kollidierende Feld wird markiert, ein Vorschlag wird angeboten, und der Vorgang wird nicht verworfen. Das ist eine Milderung, keine Lösung; ohne Sperre über die gesamte Eingabedauer ist der Fall nicht ausschließbar, und eine solche Sperre wäre der schlechtere Handel.

## 18.10 Barrierefreiheit

INV-31 macht Barrierefreiheit zur Bedingung: vollständige Bedienbarkeit ohne Zeigegerät, Zusammenarbeit mit Bildschirmlesern, WCAG 2.2 Stufe AA. Rechtlicher Rahmen sind der European Accessibility Act und das Barrierefreiheitsstärkungsgesetz; die Einordnung steht in [Kapitel 22](22-compliance.md).

| Gegenstand | Festlegung | Prüfung im Bau |
|---|---|---|
| Tastaturbedienung | Jede Aufgabe des Katalogs vollständig ohne Zeigegerät; keine Tastaturfalle; Sprungmarke zum Hauptinhalt; Tastenkürzel nur mit Zusatztaste oder abschaltbar | Tastaturdurchlauf aller 38 Aufgaben als Bauprüfung |
| Fokusführung | Fokusreihenfolge entspricht der Leserichtung; Fokus sichtbar mit Kontrast ≥ 3:1; nach dem Öffnen eines Dialogs im Dialog, nach dem Schließen am auslösenden Element; Fokus wird nie durch eine Aktualisierung verschoben | Automatische Regelprüfung plus Durchlauf |
| Bildschirmleser | Jedes Bedienelement trägt Rolle, Name und Zustand; Tabellen tragen Kopfzeilenbezüge; Vorgangsfortschritt in einem Live-Bereich mit höflicher Ansage, Fehler mit bestimmter Ansage | Automatische Regelprüfung, Stichprobe mit mindestens zwei Bildschirmlesern |
| Kontrast | Text ≥ 4,5:1, großer Text ≥ 3:1, Bedienelemente und Zustandsformen ≥ 3:1 | Automatische Kontrastprüfung über alle Farbpaare des Gestaltungssystems |
| Zustand ohne Farbe | Jeder Zustand trägt Beschriftung und Form; Farbe ist redundant | Darstellungstest mit ausgeschalteter Farbe |
| Bewegungsreduktion | Bei gesetzter Systemvorgabe keine Bewegung außer Fortschrittsanzeigen; keine selbsttätige Aktualisierung, die den Fokus verschiebt | Regelprüfung |
| Zielgröße | ≥ 24 × 24 CSS-Bildpunkte überall, Zielwert 44 × 44 für Hauptaktionen und Zeilenlisten | Geometrieprüfung im Bau |
| Beschriftungen | Jedes Feld hat eine dauerhaft sichtbare Beschriftung; ein Platzhalter ersetzt keine Beschriftung; Pflichtfelder sind als solche ausgezeichnet, nicht nur durch ein Zeichen | Regelprüfung |
| Fehlerbezug | Jede Fehlermeldung ist programmatisch mit ihrem Feld verknüpft; eine Fehlerübersicht am Kopf verweist auf die Felder | Regelprüfung plus Bildschirmleserstichprobe |

Grenze des Prüfverfahrens, ehrlich benannt: die automatisierte Regelprüfung findet strukturelle Verstöße, nicht Sinnfehler. Sie erkennt eine fehlende Beschriftung, nicht eine nichtssagende. Sie erkennt eine fehlende Fokusreihenfolge, nicht eine unlogische. Der Tastaturdurchlauf des Aufgabenkatalogs schließt die Lücke teilweise, weil er eine Aufgabe erst als erfüllt wertet, wenn der Zielzustand erreicht ist. Eine vollständige Abdeckung leistet nur eine Prüfung mit Menschen, die auf Hilfsmittel angewiesen sind; sie ist als wiederkehrende Maßnahme vorzusehen und hier nicht als erledigt darstellbar.

## 18.11 Sprachen, Zeit und Formate

| Gegenstand | Festlegung | Begründung |
|---|---|---|
| Textablage | Alle Oberflächentexte als Schlüssel-Wert-Paare, keine Satzbildung aus Fragmenten, Pluralregeln je Sprache, Platzhalter benannt statt positionsabhängig | Zusammengesetzte Sätze sind in flektierenden Sprachen nicht korrekt übersetzbar |
| Sprachwahl | Attribut "Sprache" an der Person (KANON 3), wirkt sofort, Rückfallkette Personensprache → Mandantensprache → Systemsprache | Sprache ist eine Eigenschaft des Menschen, keine Browsereinstellung |
| Fremdfehlerübersetzung | Manifest liefert Textschlüssel, nicht Text; die Tabelle ist damit selbst übersetzbar und unterliegt der Positivliste (INV-16) | Ein festverdrahteter Fremdfehlertext wäre einsprachig und unprüfbar |
| Zeitablage | Speicherung in UTC, Anzeige in der Anzeigezeitzone der Person | Vorgänge, Auditereignisse und Zertifikatsfristen müssen über Standorte vergleichbar bleiben |
| Zeitanzeige | Absolute Zeit mit Zeitzonenkürzel immer; relative Angabe ("vor 3 Minuten") nur zusätzlich, nie allein | Eine relative Angabe ist nach dem Kopieren oder Ausdrucken wertlos |
| Beobachtungszeitpunkte | Immer absolut und relativ, nach INV-28 pflichtig | Der Istzustand ist ohne Zeitpunkt keine Aussage |
| Datums- und Zahlenformat | Aus der Sprach- und Regionswahl; Dezimaltrennzeichen und Zifferngruppierung entsprechend | Fehlinterpretation von Datumsangaben ist eine reale Fehlerquelle in gemischten Teams |
| Nicht lokalisiert | Kennungen, technische Namen, Hostnamen, Kopplungscodes, Prüfsummen, Fassungsangaben | Sie werden kopiert und verglichen, nicht gelesen |
| Schreibrichtung | Der Entwurf legt Layoutspiegelung für rechtsläufige Sprachen nicht fest | Ehrliche Lücke; siehe Offene Punkte |

## 18.12 Leistungsbudget

| Größe | Zielwert | Herkunft |
|---|---|---|
| Erstanzeige einer Liste | p95 ≤ 300 ms bei 10.000 Objekten | K-18 |
| Wirkungsvorschau eines Vorgangs | p95 ≤ 2 s | K-18 |
| Sichtbare Reaktion auf eine Eingabe (Tastendruck, Auswahl) | Zielwert ≤ 100 ms | Festlegung dieses Kapitels |
| Ansichtswechsel innerhalb eines Bereichs | Zielwert ≤ 300 ms | Festlegung dieses Kapitels |
| Rundläufe je Standardaufgabe | Zielwert ≤ 3 | Festlegung dieses Kapitels |

Rechnung zur Listendarstellung. Annahme: eine Listenzeile in der Übertragungsform umfasst 200 Byte. 10.000 Objekte ergeben 10.000 × 200 B = 2.000.000 B = 16 Mbit. Bei einer Anbindung von 10 Mbit/s dauert allein die Übertragung 16 Mbit / 10 Mbit/s = 1,6 s und überschreitet das Budget um mehr als das Fünffache. Folgerung: die Liste wird seitenweise übertragen. Bei 50 Zeilen sind es 50 × 200 B = 10.000 B = 80 kbit, also 80 kbit / 10 Mbit/s = 8 ms Übertragungszeit; zuzüglich einer angenommenen Umlaufzeit von 200 ms ergeben sich 208 ms und damit ein eingehaltenes Budget. Deutung: die Seitenaufteilung folgt nicht aus Bequemlichkeit, sondern aus dem Budget; Sortierung und Filterung müssen deshalb serverseitig auf dem Lesemodell laufen, nicht im Browser.

Messverfahren. Erstens eine synthetische Messung im Bau gegen einen festen Vergleichsbestand in der Größenordnung von K-12 (15.000 Objekte), ausgeführt bei jedem Bau, mit Budgetbruch als Baufehler. Zweitens eine Messung der tatsächlichen Anzeigezeiten im Browser, die ausschließlich lokal ausgewertet und als Kennzahl ohne Objektbezug an den Kern gemeldet wird; personenbezogene Bedienpfade werden nicht übertragen. Beide Verfahren liefern Zielwertprüfungen, keine in diesem Dokument behaupteten Messwerte.

Langsame Verbindung. Zielwert: jede Standardaufgabe bleibt bei 1 Mbit/s und 200 ms Umlaufzeit durchführbar. Bei drei Rundläufen ergibt sich ein Grundbedarf von 3 × 200 ms = 600 ms je Aufgabe zuzüglich Übertragung. Daraus folgt die Regel, dass ein Formular seine Vorbelegungen in einem Aufruf lädt und nicht je Feld nachlädt.

Optimistische Darstellung und ihre Grenze. Zulässig ist die sofortige Anzeige eines geänderten Zustands nur dort, wo die Änderung lokal entscheidbar ist und eine Rücknahme unsichtbar bliebe, etwa bei Sortierung, Filterung oder dem Aufklappen eines Abschnitts. Unzulässig ist sie für jede Änderung am Sollzustand: diese erfordert Mehrheitszustimmung und erzeugt einen Vorgang, dessen Ausgang nicht vorhergesagt werden darf. Eine optimistisch angezeigte Zuweisung, die später scheitert, wäre genau der stille halbe Erfolg, den INV-12 verbietet. Die Oberfläche zeigt deshalb nach dem Absenden den Vorgang mit seinen Teilzuständen, nicht das erhoffte Ergebnis.

## 18.13 Prüfverfahren für die Bedienbarkeit

| Größe | Erhebung | Zielwert |
|---|---|---|
| Aufgabenerfolgsquote | Anteil der Teilnehmer, die den Zielzustand ohne fremde Hilfe erreichen, je Aufgabe | Zielwert ≥ 90 % je Aufgabe des Katalogs |
| Zeit je Aufgabe | Zeit von der Aufgabenstellung bis zum erreichten Zielzustand, je Teilnehmer | Erhebung als Spanne (Minimum, Median, Maximum), kein Mittelwert bei kleiner Stichprobe |
| Abbruchgründe | Freie Angabe des Teilnehmers, kodiert in feste Klassen: Objekt nicht gefunden, Begriff nicht verstanden, Wirkung nicht absehbar, Fehlermeldung ohne Handlung, Aufgabe für unmöglich gehalten | Erhebung; kein Zielwert, weil die Klassenverteilung die Diagnose ist |
| Erstklick-Trefferquote | Anteil der Teilnehmer, deren erster Klick auf dem Weg zur Aufgabe liegt | Zielwert ≥ 80 % bei 20 Aufgaben (K-26) |
| Standardisierter Fragebogen | Ein etablierter Fragebogen zur wahrgenommenen Bedienbarkeit, unverändert eingesetzt | Zielwert ≥ 80 Punkte; ausdrücklich ein Zielwert, kein erhobener Wert |

### Was eine kleine Stichprobe aussagt

Zwei Rechnungen, beide auf der Annahme unabhängiger Teilnehmer.

**Entdeckungswahrscheinlichkeit eines Problems.** Tritt ein Problem bei einem Anteil p der Teilnehmer auf, beträgt die Wahrscheinlichkeit, es bei n Teilnehmern mindestens einmal zu sehen, 1 − (1 − p)ⁿ.

| p | n = 5 | n = 10 | n = 20 |
|---|---|---|---|
| 0,05 | 0,226 | 0,401 | 0,642 |
| 0,15 | 0,556 | 0,803 | 0,961 |
| 0,30 | 0,832 | 0,972 | 0,999 |

**Aussagekraft für eine Quote.** Beobachtet ein Test n Versuche ohne Fehlschlag, so liegt die obere 95-%-Schranke der Fehlschlagsrate bei u = 1 − 0,05^(1/n).

| n | obere 95-%-Schranke der Fehlschlagsrate |
|---|---|
| 5 | 45,1 % |
| 10 | 25,9 % |
| 20 | 13,9 % |
| 50 | 5,8 % |

Deutung, ohne Beschönigung. Fünf Teilnehmer finden häufige Probleme zuverlässig (83 % Entdeckungswahrscheinlichkeit bei p = 0,30) und seltene fast nicht (23 % bei p = 0,05). Fünf Teilnehmer ohne Fehlschlag belegen eine Erfolgsquote von 90 % nicht: die obere Schranke der Fehlschlagsrate liegt bei 45 %. Eine Aussage über die Einhaltung des Zielwerts von 90 % verlangt mindestens die Größenordnung von 50 Teilnehmern je Aufgabe. Folgerung für das Vorgehen: kleine Stichproben dienen dem Auffinden von Problemen, nicht der Bestätigung von Quoten; die Quotenzielwerte in K-26 und in diesem Abschnitt sind erst mit einer entsprechend großen Erhebung belegbar und gelten bis dahin als unbelegte Zielwerte.

## 18.14 Der Expertenausgang

| Bestandteil | Festlegung | Bedingung |
|---|---|---|
| Deklarativer Export | Vollständiger Sollzustand, maschinen- und menschenlesbar, kanonisch serialisiert nach RFC 8785, signiert nach RFC 8032, mit `schema_version`; Geheimnisse nur als Referenz (INV-20) | Enthält keine Nutzdaten; die Konsole benennt das ausdrücklich |
| Import | Nur als Vorgang mit Wirkungsvorschau und Unterschiedsliste gegen den aktuellen Sollzustand; ältere Hauptfassung nur über ausdrückliche Migration (INV-24) | Nie stillschweigend |
| Dieselbe API | Konsole, `atriumctl` und externe Automatisierung benutzen dieselbe öffentlich dokumentierte Schnittstelle (INV-01) | Kein privater Pfad; die Konsole wird im Bau gegen eine Fassade getestet, die nicht dokumentierte Endpunkte sperrt |
| `atriumctl` | Support- und Automatisierungswerkzeug | Für den Normalbetrieb nicht erforderlich (INV-26) |

Die Bedingung aus INV-26 ist prüfbar und wird zweifach geprüft: erstens durchläuft der vollständige Aufgabenkatalog automatisiert die Konsole, zweitens prüft die Musterprüfung aller Meldungstexte, dass kein Fehlerfall auf ein Kommandozeilenwerkzeug als einzige Lösung verweist (INV-17).

Schwäche, die der Entwurf nicht auflöst: Export und Import laden zu genau der Handbearbeitung ein, die INV-02 verbietet. Ein Bediener kann den Export bearbeiten und zurückspielen. Der Import läuft zwar durch dieselbe Schemaprüfung, dieselbe Rechteprüfung und dieselbe Wirkungsvorschau wie jede andere Änderung, sodass kein Zustand entsteht, der über die API nicht erreichbar wäre. Er kann aber eine große, unüberschaubare Änderungsmenge in einem Vorgang erzeugen. Die Milderung ist eine Obergrenze für die Zahl der Änderungen je Import, oberhalb derer der Import in Teilvorgänge zerlegt und einzeln freigegeben wird; die Obergrenze ist eine offene Festlegung.

## 18.15 Muster, die nicht vorkommen

| Muster | Warum es entsteht | Was stattdessen gilt | Prüfung |
|---|---|---|---|
| Assistent mit mehr als drei Schritten | Jede neue Einstellung bekommt einen eigenen Schritt, weil das billiger ist als eine Ableitung | Höchstens drei Entscheidungen je Aufgabe; mehr bedeutet falscher Schnitt (INV-14) | Abgleich der Formulardefinition gegen die Aufgabendefinition im Bau |
| Einstellungsseite ohne Wirkungsangabe | Ein Schalter ist schnell gebaut, seine Wirkungsberechnung nicht | Jede Änderung ist ein Vorgang mit Wirkungsvorschau (INV-08); eine Richtlinienänderung nennt vorab die Menge betroffener Objekte | Musterprüfung: jedes schreibende Formular besitzt einen `plan`-Pfad |
| Freitextfeld für strukturierte Daten | Ein Textfeld nimmt alles an und verschiebt die Prüfung in den Betrieb | Strukturierte Daten haben ein Schema und eine Auswahl aus Objekten; Freitext existiert nur für Anzeigenamen und Notizen | Schemaprüfung: Felder mit Objektbezug sind Verweise, keine Zeichenketten |
| Fehlermeldung mit Nummer ohne Text | Die Nummer stammt aus einem Fremdsystem und wird durchgereicht | Jede Meldung nennt Ursache, Wirkung, nächste Handlung; der Originaltext steht im Vorgangsprotokoll (INV-12, INV-17) | Musterprüfung aller Meldungstexte und Textschlüssel |
| Versteckte Abhängigkeit zwischen Schaltern | Ein Schalter wirkt nur, wenn ein anderer gesetzt ist, ohne dass das sichtbar wäre | Abhängige Felder sind entweder abgeleitet und als solche gekennzeichnet oder nicht vorhanden; ein wirkungsloser Schalter wird nicht angezeigt | Zustandsmatrixtest über alle Feldkombinationen eines Formulars |
| Abschnitt "Erweitert" als Ablage | Alles, was nicht in die drei Entscheidungen passt, wandert dorthin | Es gibt keinen solchen Abschnitt; was nicht vorbelegbar ist, ist eine Entscheidung oder existiert nicht | Formularprüfung gegen die Aufgabendefinition |
| Bestätigungsdialog ohne Wirkungsliste | Bestätigung ist billiger als Wirkungsberechnung | Bestätigung nur bei Nichtrücknehmbarkeit, Kostenwirkung oder Schwellenüberschreitung, stets mit Wirkungsliste | Prüfung: jeder Bestätigungsdialog referenziert eine Wirkungsvorschau |
| Fortschrittsanzeige ohne Endzustand | Der Endzustand ist unbekannt, die Anzeige läuft weiter | Jeder Vorgang hat eine harte Obergrenze (K-15: 15 min) und endet in einem benannten Zustand | Zeitschranktest je Vorgangsart |
| Kurzhinweis als einziger Ort einer Pflichtinformation | Der Platz im Formular ist knapp | Pflichtinformationen stehen im dauerhaften Text; Kurzhinweise sind redundant | Bildschirmleserprüfung, die alle Kurzhinweise ausblendet |
| Verwaltungsoberfläche einer Kernkomponente | Die Fremdkomponente bringt sie mit | Verwaltungsoberflächen von Kernkomponenten sind abgeschaltet; Fremdoberflächen von Fachprodukten bleiben Fremdoberflächen (INV-30) | Erreichbarkeitstest der abgeschalteten Oberflächen |

## Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| R-18-01 | Die Atrium Console führt genau 8 Navigationsbereiche mit den in KANON 5 festgelegten Namen und Inhalten. Ein neunter Bereich existiert nicht. | KANON 4 Entscheidung 11, KANON 5 |
| R-18-02 | Kein Bereich enthält mehr als 7 Unterpunkte. Jede Standardaufgabe ist vom Überblick aus in höchstens 3 Ebenen erreichbar. | K-26 |
| R-18-03 | Eine Funktion, die in der Negativliste eines Bereichs steht, ist in diesem Bereich nicht erreichbar; der Versuch, sie dort einzubauen, bricht den Bau. | KANON 5 |
| R-18-04 | Die Suche liefert Objekt- und Aufgabentreffer in einer Liste, kennt keine Abfragesprache und keine Platzhalterzeichen und erzeugt 0 Treffer aus fremden Mandanten. | INV-19, KANON 4 Entscheidung 11 |
| R-18-05 | Jede Suchanfrage wird parametrisiert gegen das Lesemodell ausgeführt; die Eingabe wird in 0 Abfragen interpoliert. | Sicherheitsvorgabe |
| R-18-06 | Jede der 38 Aufgaben des Katalogs in 18.2 verlangt höchstens 3 Entscheidungen. Ein zusätzliches Pflichtfeld bricht den Bau. | INV-14, K-03 |
| R-18-07 | Jedes vorbelegte Formularfeld nennt genau eine der vier zulässigen Quellen: Richtlinie, Ableitung, vorheriger Wert, Katalogeintrag. Die Quelle steht im Vorgangsprotokoll. | INV-15 |
| R-18-08 | Fehlt eine Richtlinie, aus der ein Feld vorbelegt würde, entsteht 0 zusätzliches Pflichtfeld; die Aufgabe ist gesperrt und die fehlende Richtlinie wird benannt. | INV-14, INV-15 |
| R-18-09 | Eine Aufgabe, die nach Anwendung aller vier Behandlungsarten mehr als 3 Entscheidungen behält, wird geteilt oder nicht gebaut. Ein Abschnitt "Erweitert" existiert nicht. | INV-14 |
| R-18-10 | Ein Untermenü ist nur zulässig, wenn seine erste Stufe die Alternativenmenge fachlich verkleinert. Eine reine Aufteilung einer Liste in Untermenüs ist unzulässig. | K-26 |
| R-18-11 | Jedes Bedienelement misst mindestens 24 × 24 CSS-Bildpunkte; Hauptaktionen und Elemente in Zeilenlisten erreichen den Zielwert 44 × 44. | INV-31 |
| R-18-12 | Die Übersicht stellt die vier Ebenen Mandant, Knoten, Dienst, Abhängigkeit dar und ist zwischen allen Ebenen in beiden Richtungen navigierbar. | INV-18 |
| R-18-13 | Die Übersicht zeigt dauerhaft die Zahl der derzeit vertragenen Knotenausfälle als Minimum aus Stimm- und Datenachse und nennt die begrenzende Achse und den begrenzenden Dienst. | INV-18, K-04 |
| R-18-14 | Ein Ein-Knoten-System zeigt dauerhaft "Redundanz: keine". Ein Dienst ohne geprüften Wiederherstellungspunkt zeigt das am Dienst. | INV-18 |
| R-18-15 | Jede Istangabe nennt den Beobachtungszeitpunkt absolut mit Zeitzone und als Alter. Bei einem Alter über 90 s wechselt die Angabe in den Zustand "unbekannt". | INV-28, K-16 |
| R-18-16 | Kein Zustand wird allein durch Farbe dargestellt; jeder Zustand trägt Beschriftung und Form. | INV-31 |
| R-18-17 | Jeder schreibende Vorgang besitzt eine Wirkungsvorschau mit den sieben festgelegten Abschnitten. Ein schreibendes Formular ohne `plan`-Pfad existiert nicht. | INV-08 |
| R-18-18 | `plan` verändert 0 Objekte in Atrium und 0 Objekte in Fremdsystemen. | INV-08 |
| R-18-19 | Eine kostenwirksame Aktion in einem Fremdsystem erscheint in der Wirkungsvorschau vor der Bestätigung. | INV-29 |
| R-18-20 | Ein Vorgang mit mindestens einem ausstehenden Zielsystem erreicht den Zustand "abgeschlossen" nicht; er zeigt je Zielsystem Zustand, Grund und Wiederholungsmöglichkeit. | INV-12 |
| R-18-21 | Eine Massenaktion zeigt die Einzelaufstellung der betroffenen Objekte, nicht nur ihre Anzahl, und führt jedes Objekt als eigenen Teilvorgang aus. Nicht rücknehmbare Aktionen sind nie Teil einer Massenaktion ohne Einzelaufstellung. | INV-11 |
| R-18-22 | Eine Bestätigung wird genau dann verlangt, wenn die Aktion nicht rücknehmbar ist, eine Kostenwirkung hat oder mehr als 10 Objekte oder eine Mandantengrenze betrifft. Jeder Bestätigungsdialog referenziert eine Wirkungsvorschau. | INV-11, INV-29 |
| R-18-23 | Jede Fehlermeldung nennt Ursache, Wirkung und nächste Handlung und verweist auf den Vorgang. Eine Meldung ohne eines der drei Stücke bricht den Bau. | INV-12, INV-17 |
| R-18-24 | Keine Meldung nennt einen Konsolenbefehl oder Dateipfad als einzigen Lösungsweg. Die Musterprüfung erfasst Meldungstexte und die Übersetzungstabelle der Fremdfehler. | INV-17 |
| R-18-25 | Fremdfehler erscheinen ausschließlich übersetzt; der Originaltext steht im Vorgangsprotokoll und unterliegt der Geheimnismaskierung. Übersetzte Texte verletzen die Positivliste in 0 Fällen. | INV-16, INV-20 |
| R-18-26 | Eine Meldung enthält 0 internen Pfade, 0 Rohtexte eines Fremdsystems, 0 Geheimnisfragmente und unterscheidet bei Anmeldefehlern nicht zwischen unbekanntem Konto und falschem Geheimnis. | INV-20, Sicherheitsvorgabe |
| R-18-27 | Ein Ereignis wird nur dann zu einem Eintrag in der Aufgabenliste, wenn Empfänger, Handlung in der Konsole, Frist und Schaden benennbar sind und der Zustand nicht bereits am Objekt sichtbar ist. | INV-18 |
| R-18-28 | Die Aufgabenliste ist total geordnet nach Frist, dann Zahl betroffener Personen, dann Erzeugungszeit. Ein gewichtetes Punktesystem existiert nicht. | INV-18 |
| R-18-29 | Gleichartige Ereignisse mit gleichem Tripel aus Ereignistyp, Ursachenobjekt und Vorgang erzeugen genau 1 Eintrag mit Anzahl und Verweis auf die Einzelaufstellung. | INV-18 |
| R-18-30 | Oberfläche und API prüfen gegen dasselbe Schema; unbekannte Felder werden abgelehnt. Eine abweichende Prüfung in der Oberfläche existiert nicht. | INV-01, Sicherheitsvorgabe |
| R-18-31 | Aus Formulareingaben wird 0 Ausdruck ausgewertet und 0 Zeichenkette in eine Abfrage, einen Aufruf oder eine Schale interpoliert. Vergleiche von Geheimnissen und Kopplungscodes laufen laufzeitkonstant. | INV-20, INV-27 |
| R-18-32 | Lokal entscheidbare Prüfungen melden sich beim Verlassen des Feldes, nicht beim Absenden. Ein Eindeutigkeitskonflikt nach dem Absenden erhält die Eingaben, markiert das kollidierende Feld und bietet einen Vorschlag an. | Festlegung 18.9 |
| R-18-33 | Jede Aufgabe des Katalogs ist vollständig ohne Zeigegerät ausführbar; der Tastaturdurchlauf aller 38 Aufgaben ist Bestandteil des Baus. Ein Verstoß bricht den Bau. | INV-31 |
| R-18-34 | Jedes Bedienelement trägt Rolle, Name und Zustand; jede Fehlermeldung ist programmatisch mit ihrem Feld verknüpft; jedes Feld trägt eine dauerhaft sichtbare Beschriftung. | INV-31 |
| R-18-35 | Text erreicht einen Kontrast von mindestens 4,5:1, großer Text und Bedienelemente mindestens 3:1. Bei gesetzter Bewegungsreduktion findet außer Fortschrittsanzeigen 0 Bewegung statt. | INV-31 |
| R-18-36 | Oberflächentexte werden nicht aus Fragmenten zusammengesetzt; Platzhalter sind benannt; die Fremdfehlerübersetzung liefert Textschlüssel statt Text. | INV-16 |
| R-18-37 | Zeitpunkte werden in UTC gespeichert und in der Anzeigezeitzone der Person mit Zeitzonenkürzel angezeigt. Eine relative Zeitangabe steht nie allein. | INV-28 |
| R-18-38 | Kennungen, technische Namen, Hostnamen, Kopplungscodes und Prüfsummen werden nicht lokalisiert und nicht gruppiert dargestellt. | KANON 3 Identifikatorformat |
| R-18-39 | Die Erstanzeige jeder Liste hält p95 ≤ 300 ms bei 10.000 Objekten ein; Listen werden seitenweise übertragen, Sortierung und Filterung laufen serverseitig. | K-18 |
| R-18-40 | Jede Standardaufgabe kommt mit höchstens 3 Rundläufen aus; ein Formular lädt seine Vorbelegungen in einem Aufruf. | K-18 |
| R-18-41 | Optimistische Darstellung ist auf lokal entscheidbare Zustände beschränkt. Eine Änderung am Sollzustand wird nie als erfolgt angezeigt, bevor der Vorgang sie meldet. | INV-12, INV-04 |
| R-18-42 | Die Bedienbarkeitsprüfung erhebt Erfolgsquote, Zeitspanne, Abbruchgrundklasse und Erstklick-Trefferquote je Aufgabe und weist bei Stichproben unter 50 Teilnehmern keine Quotenaussage aus. | K-26 |
| R-18-43 | Der Sollzustandsexport ist vollständig, kanonisch serialisiert nach RFC 8785, signiert nach RFC 8032, nennt `schema_version` und enthält 0 Geheimnisse im Klartext. | INV-20, INV-24 |
| R-18-44 | Ein Import erzeugt einen Vorgang mit Wirkungsvorschau und Unterschiedsliste. Ein stillschweigender Import existiert nicht. | INV-08, INV-24 |
| R-18-45 | Der vollständige Aufgabenkatalog wird im Bau automatisiert über die Konsole durchlaufen; `atriumctl` wird dabei in 0 Aufgaben benötigt. | INV-26, INV-01 |
| R-18-46 | Die Konsole benutzt 0 Endpunkte außerhalb der öffentlich dokumentierten API; der Bau testet sie gegen eine Fassade, die nicht dokumentierte Endpunkte sperrt. | INV-01 |
| R-18-47 | Ein Formularfeld, dessen Wirkung von der Stellung eines anderen Feldes abhängt, ist entweder als abgeleitet gekennzeichnet oder nicht vorhanden. Ein wirkungsloser Schalter wird nicht angezeigt. | INV-15 |
| R-18-48 | Jeder Vorgang endet innerhalb der harten Obergrenze von 15 min in einem benannten Zustand. Eine Fortschrittsanzeige ohne Endzustand existiert nicht. | K-15, INV-12 |

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Die Konsole führt 8 Bereiche; kein Bereich hat mehr als 7 Unterpunkte; jede der 38 Aufgaben ist in höchstens 3 Ebenen erreichbar | R-18-01, R-18-02 | Strukturdurchlauf des Oberflächenbaums im Bau |
| Eine Funktion aus der Negativliste eines Bereichs ist in diesem Bereich in 0 Fällen erreichbar | R-18-03 | Abgleich der Oberflächenkarte gegen KANON 5 |
| Eine Suche nach einem Namen, der in zwei Mandanten existiert, liefert für einen auf einen Mandanten beschränkten Bediener genau die Treffer seines Mandanten | R-18-04 | Mandantentrennungstest der Suche |
| Eine Sucheingabe mit Abfrage-Sonderzeichen erzeugt 0 veränderte Abfragen und 0 Fehler mit Informationspreisgabe | R-18-05 | Einschleusungstest über das Suchfeld |
| Alle 38 Aufgabendefinitionen und die zugehörigen Formulare stimmen in der Zahl der Pflichtfelder überein, Maximum 3 | R-18-06, R-18-09 | Abgleich Aufgabendefinition gegen Formulardefinition im Bau |
| Jedes vorbelegte Feld aller Formulare nennt eine der vier Quellen; 0 Felder ohne Quelle | R-18-07 | Formularprüfung im Bau |
| Bei entfernter Richtlinie ist die betroffene Aufgabe gesperrt und das Formular unverändert | R-18-08 | Richtlinienraumdurchlauf mit und ohne gesetzte Richtlinie |
| Alle Bedienelemente erreichen 24 × 24 CSS-Bildpunkte; Hauptaktionen und Zeilenlistenelemente erreichen 44 × 44 | R-18-11 | Geometrieprüfung über alle Ansichten |
| Ein Aufbau mit 5 Stimmknoten und 3 Replikaten zeigt "1 Knotenausfall" und nennt die Datenachse als Begrenzung | R-18-13 | Zustandsmatrixtest über Stimmzahl × Datensicherheitsstufe |
| Ein Ein-Knoten-System zeigt in jeder Ansicht der Übersicht "Redundanz: keine" | R-18-14 | Darstellungstest im Ein-Knoten-Aufbau |
| Eine Istangabe ohne Beobachtungszeitpunkt bricht den Bau; eine 120 s alte Beobachtung erscheint als "unbekannt" | R-18-15 | Darstellungstest mit angehaltener Beobachtung |
| Bei ausgeschalteter Farbdarstellung sind alle sechs Zustände unterscheidbar | R-18-16 | Darstellungstest ohne Farbe |
| Jedes schreibende Formular besitzt einen `plan`-Pfad; `plan` erzeugt 0 Änderungen im Bestand jedes Fremdsystems | R-18-17, R-18-18 | Vorschauprobe: Bestandsabruf vor und nach `plan` ist feldgleich |
| Eine Zuweisung mit kostenwirksamer Lizenzzuweisung zeigt die Kostenwirkung vor der Bestätigung | R-18-19 | Vertragstest gegen die Kostendeklaration des Manifests |
| Ein Vorgang über 3 Zielsysteme mit einem injizierten Fehler meldet "teilweise fehlgeschlagen" und 0-mal "abgeschlossen" | R-18-20 | Fehlerinjektion in einen von drei Konnektoren |
| Eine Massenaktion über 200 Objekte zeigt 200 Einzelzeilen oder verweigert die Ausführung bis zum Abruf der Aufstellung; ein Fehler an Objekt 47 nimmt 0 der Objekte 1 bis 46 zurück | R-18-21 | Massenaktionstest mit injiziertem Fehler |
| Über 100 Testvorgänge erscheinen Bestätigungsdialoge ausschließlich bei nicht rücknehmbaren, kostenwirksamen oder schwellenüberschreitenden Vorgängen | R-18-22 | Zählung über den Vorgangsdurchlauf |
| Alle Meldungstexte enthalten die drei Stücke Ursache, Wirkung, nächste Handlung; 0 Texte enthalten Befehls- oder Pfadangaben als einzigen Weg | R-18-23, R-18-24 | Musterprüfung aller Texte und Textschlüssel |
| Ein Fremdfehler ohne Eintrag in der Übersetzungstabelle erzeugt die Rückfallmeldung mit Zielsystem, Aktion, Wirkung und Handlung; der Originaltext erscheint nur im Vorgangsprotokoll | R-18-25 | Fehlerinjektion mit unbekannter Fehlerkennung |
| Ein Anmeldefehler mit unbekanntem Konto und ein Anmeldefehler mit falschem Geheimnis erzeugen denselben Text und dieselbe Antwortzeitklasse | R-18-26 | Vergleichstest der Antworten |
| 40 gleichartige Versorgungsfehler derselben Bindung erzeugen 1 Eintrag mit der Zahl 40 | R-18-29 | Verdichtungstest |
| Ein Formular mit einem unbekannten Zusatzfeld wird von der API abgelehnt; die Oberflächenprüfung liefert dasselbe Ergebnis wie die API-Prüfung | R-18-30 | Schemaprüfung mit manipulierten Eingaben |
| Formulareingaben mit Ausdrucks- und Schalensyntax erzeugen 0 Auswertungen | R-18-31 | Einschleusungstest über alle Formularfelder |
| Zwei gleichzeitige Vergaben desselben lokalen Teils erhalten beide Eingaben; genau eine wird wirksam, die andere erhält Markierung und Vorschlag | R-18-32 | Wettlauftest mit zwei Sitzungen |
| Alle 38 Aufgaben werden ohne Zeigegerät bis zum Zielzustand durchlaufen | R-18-33 | Tastaturdurchlauf im Bau |
| Die automatisierte Regelprüfung meldet 0 Verstöße gegen WCAG 2.2 Stufe AA; alle Farbpaare erfüllen die Kontrastwerte | R-18-34, R-18-35 | Regel- und Kontrastprüfung im Bau |
| 0 Oberflächentexte werden aus Fragmenten gebildet; alle Platzhalter sind benannt | R-18-36 | Textprüfung im Bau |
| Jede Zeitangabe in Vorgängen, Audit und Istansichten trägt Zeitzonenkürzel; 0 Angaben sind ausschließlich relativ | R-18-37 | Darstellungstest über alle Ansichten |
| Die Erstanzeige einer Liste mit 10.000 Objekten hält p95 ≤ 300 ms; die Übertragung je Seite bleibt unter 50 Zeilen | R-18-39 | Lastmessung gegen den Vergleichsbestand im Bau |
| Jede der 38 Aufgaben kommt mit höchstens 3 Rundläufen aus | R-18-40 | Zählung der Aufrufe je Aufgabendurchlauf |
| Eine Zuweisung, deren Versorgung scheitert, wurde zu 0 Zeitpunkten als wirksam angezeigt | R-18-41 | Zeitreihentest der Anzeige bei injiziertem Konnektorfehler |
| Ein Export enthält 0 Geheimnisse im Klartext und ist gegen die Signatur prüfbar; ein manipulierter Export wird beim Import abgelehnt | R-18-43 | Ausgabeprüfung und Importtest mit manipulierter Datei |
| Der Aufgabenkatalog läuft vollständig über die Konsole; `atriumctl` wird in 0 Aufgaben aufgerufen | R-18-45 | Automatisierter Katalogdurchlauf |
| Die Konsole erreicht gegen die API-Fassade 0 nicht dokumentierte Endpunkte | R-18-46 | Fassadentest im Bau |
| In keiner Formularkombination existiert ein Schalter ohne Wirkung | R-18-47 | Zustandsmatrixtest über alle Feldkombinationen |
| Jeder Testvorgang endet innerhalb von 15 min in einem benannten Zustand | R-18-48 | Zeitschranktest je Vorgangsart |

## Offene Punkte

1. **Sieben Aufgaben liegen am Maximum von drei Entscheidungen, ohne dass eine Reserve besteht.** Die Aufgaben 12, 13, 18, 19, 22, 29 und 36 des Katalogs schöpfen INV-14 vollständig aus. Jede fachlich begründete Erweiterung — etwa eine zweite Anbieterbindung je Maildomäne, ein zweiter Hostname je Veröffentlichung oder ein Pflichtfeld aus einer neuen Rechtsanforderung — erzwingt entweder eine neue Richtlinie, aus der vorbelegt wird, oder die Teilung der Aufgabe in zwei. Beides ist teuer und beides ist ungeplant. Offen ist, ob der Entwurf eine Reserve einführt, indem er für diese sieben Aufgaben ausdrücklich zwei Entscheidungen als Zielwert setzt, oder ob die Enge als Steuerungsmittel bewusst bestehen bleibt.

2. **Die Menge der genannten Nichtwirkungen in der Wirkungsvorschau ist deklariert, nicht berechnet.** Der Abschnitt "Was sich nicht ändert" wirkt auf den Bediener wie eine Zusicherung, beruht aber auf einer je Vorgangsart handgepflegten Liste. Eine unvollständige Liste erzeugt Vertrauen, das der Entwurf nicht einlöst. Eine berechnete Liste wäre die Menge aller Objekte im Wirkungskegel, die der Vorgang nicht berührt, und damit praktisch unbegrenzt. Offen ist, ob die Deklaration an eine Prüfung gebunden wird, die für jede genannte Nichtwirkung nachweist, dass der Vorgang das genannte Objekt tatsächlich nicht verändert, und ob eine nicht genannte, aber vom Bediener erwartete Nichtwirkung als Fehler gilt.

3. **Die Abdeckungsquote der Fremdfehlerübersetzung ist nicht festgelegt und bestimmt trotzdem die Qualität der Fehlerbehandlung.** INV-17 ist formal auch mit einer Rückfallmeldung eingehalten, die keine Ursache nennt. Ein Konnektor mit geringer Abdeckung erzeugt damit Meldungen, die die Prüfung bestehen und dem Bediener nicht helfen. Offen ist, ob eine Mindestabdeckung als Freigabebedingung für einen Konnektor gilt, wie sie gemessen wird — die Menge der möglichen Fehlerkennungen eines Fremdsystems ist selten dokumentiert — und was mit bereits freigegebenen Konnektoren geschieht, deren Fremdsystem neue Fehlerkennungen einführt.

4. **Die Zielwerte für Aufgabenerfolgsquote, Erstklick-Trefferquote und den standardisierten Fragebogen sind unbelegt und mit vertretbarem Aufwand schwer zu belegen.** Aus der Rechnung in 18.13 folgt, dass eine Aussage über eine Erfolgsquote von 90 % eine Stichprobe in der Größenordnung von 50 Teilnehmern je Aufgabe verlangt. Bei 38 Aufgaben wären das 1.900 Aufgabendurchläufe. Offen ist, ob die Prüfung auf eine begründete Teilmenge der Aufgaben beschränkt wird — mit der Folge, dass die Zusage nur für diese Teilmenge gilt — oder ob die Quotenzielwerte durch eine im Betrieb erhobene Größe ersetzt werden, etwa die Abbruchquote realer Vorgänge, die ohne Teilnehmerbefragung auskommt, aber die Ursache nicht liefert.

5. **Layoutspiegelung für rechtsläufige Schreibrichtungen ist nicht festgelegt.** Die Textablage ist übersetzbar, die Anordnung ist es nicht. Eine nachträgliche Spiegelung betrifft Navigationsbaum, Tabellen, Fortschrittsdarstellungen, die Abhängigkeitsansicht in 18.5 und sämtliche Textdiagramme. Offen ist, ob Atrium rechtsläufige Sprachen zusagt und die Spiegelung von Beginn an in das Gestaltungssystem aufnimmt, oder ob die Sprachliste ausdrücklich begrenzt wird. Eine spätere Nachrüstung ist erfahrungsgemäß teurer als die anfängliche Berücksichtigung; eine Zusage ohne Umsetzung wäre eine Falschaussage.

6. **Die Obergrenze für die Änderungsmenge je Import ist nicht festgelegt.** Der Expertenausgang lässt einen bearbeiteten Sollzustandsexport zurückspielen. Ohne Obergrenze entsteht ein Vorgang mit möglicherweise tausenden Einzeländerungen, dessen Wirkungsvorschau niemand liest, womit die Freigabe zur Formalie wird. Mit Obergrenze zerfällt ein legitimer Großimport — etwa eine Wiederherstellung nach Totalverlust — in viele Teilvorgänge mit eigener Freigabe, was die RTO-Zielwerte aus K-11 gefährdet. Offen ist, ob die Grenze von der Art des Vorgangs abhängt (Wiederherstellung anders als Bearbeitung) und woran der Kern beide unterscheidet.

7. **Der Wechsel zwischen Atrium Console und Fremdoberfläche ist als Grenze deklariert, aber nicht als Bedienerlebnis gelöst.** INV-30 hält Fremdoberflächen bewusst außerhalb. Der Bediener wechselt damit mitten in einer fachlichen Aufgabe in eine Oberfläche mit anderem Vokabular, anderer Navigationstiefe und anderer Barrierefreiheitsgüte. Atrium kann die Anmeldung durchreichen und die Produktgrenze anzeigen, aber es kann die Brüche nicht beseitigen. Offen ist, ob die Konsole beim Wechsel einen Hinweis auf die geltende Grenze erzwingt, ob sie die Rückkehr in den auslösenden Vorgang sicherstellt und wie sie mit Fremdprodukten umgeht, die WCAG 2.2 Stufe AA nicht erfüllen, ohne die eigene Zusage aus INV-31 zu entwerten.

8. **Die Veraltungsschwelle von 90 s für Istangaben ist aus K-16 abgeleitet und für langsame Konnektorpfade zu knapp.** Ein Istwert, der über eine Fremd-API mit Ratenbegrenzung beobachtet wird, kann regelmäßig älter als 90 s sein, ohne dass eine Störung vorliegt. Die Ansicht würde dann dauerhaft "unbekannt" zeigen und damit die Anzeige entwerten. Offen ist, ob die Schwelle je Beobachtungsquelle aus dem deklarierten Abgleichintervall der Konnektorbindung abgeleitet wird — was eine weitere Pflichtangabe im Manifest bedeutet — oder ob eine zweite, längere Schwelle für fremdbeobachtete Werte eingeführt wird, mit dem Preis, dass zwei verschiedene Veraltungsbegriffe nebeneinander bestehen.
