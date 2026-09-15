# 22 Rechtliche Anforderungen und Nachweisführung

Dieses Kapitel ordnet Produktfunktionen technischen Pflichtenkreisen zu und ist keine Rechtsberatung; die Auslegung des jeweiligen Rechtstextes, die Feststellung der eigenen Betroffenheit und die Bewertung des Einzelfalls bleiben beim Betreiber und seinen Rechtsbeiständen.

## 22.1 Rollenabgrenzung als Voraussetzung jeder Zuordnung

Ohne festgelegte Rollen ist jede Pflichtenzuordnung beliebig. Atrium kennt drei Beteiligte mit getrennten Pflichtenkreisen, und die Isolationsstufe eines Mandanten ([Kapitel 19](19-mandanten-rechte-audit.md)) entscheidet nicht darüber, wer welche Rolle hat.

| Beteiligter | Wer das ist | Pflichtenkreis | Beitrag von Atrium |
|---|---|---|---|
| Hersteller | Das Projekt bzw. der Anbieter, der Abbilder, Katalogeinträge und Konnektormanifeste signiert und bereitstellt | Produktsicherheit, Schwachstellenbehandlung, Stückliste, Unterstützungszeitraum | Der Gegenstand der Pflicht ist das Produkt selbst (22.8) |
| Betreiber | Wer eine Installation betreibt | Informationssicherheitsmanagement, Datenschutzorganisation, Meldewesen, Aufbewahrung | Nachweise, Voreinstellungen, Vorgangsprotokoll |
| Mandant | Die fachlich verantwortliche Organisationseinheit oder der Endkunde im Anbietermodus | Zweckfestlegung, Rechtsgrundlage, Betroffenenrechte, eigene Aufbewahrungsfristen | Mandantenbezug als Pflichtfeld (INV-19), getrennte Nachweise je Mandant |

Drei Betriebsformen ergeben drei Zuordnungen. Im Eigenbetrieb ist der Betreiber zugleich Verantwortlicher im Sinne der DSGVO (Verordnung (EU) 2016/679) für alle Mandanten. Im Anbietermodus ist der Betreiber gegenüber jedem Mandanten Auftragsverarbeiter nach Artikel 28, und jede Konnektorbindung zu einem Fremdsystem ist ein möglicher Unterauftragsverarbeiter, über den der Mandant informiert werden muss. Der Hersteller ist in keiner dieser Formen Verarbeiter, solange kein Datenfluss zu ihm besteht.

Dieser letzte Punkt ist eine Entwurfsentscheidung mit Begründung und keine Selbstverständlichkeit: Atrium sendet im Auslieferungszustand keine Telemetrie an den Hersteller. Die Telemetrieausleitung existiert ausschließlich als Konnektorbindung an ein vom Betreiber benanntes Ziel. Die verworfene Alternative, eine standardmäßig aktive Rückmeldung von Nutzungs- und Fehlerdaten an den Hersteller, hätte den Hersteller in jeder Installation zum Empfänger personenbeziehbarer Daten gemacht und damit in jeden Auftragsverarbeitungsvertrag hineingezogen; der Erkenntnisgewinn rechtfertigt diese Vertragslast nicht.

**Anforderungen**

- **R-22-01** — Im Auslieferungszustand besteht 0 ausgehende Verbindung zu einer Adresse des Herstellers außer zum Bezug signierter Abbilder, Katalogeinträge und Sicherheitsmeldungen. Prüfbar: Netzmitschnitt einer Neuinstallation über 24 h gegen die Ausgangs-Positivliste (INV-21).
- **R-22-02** — Jede Konnektorbindung deklariert im Manifest die Kategorie der übermittelten Daten und den Sitz des Fremdsystems als Pflichtfeld; ein Manifest ohne diese Angaben wird beim Import abgelehnt. Prüfbar: Importtest mit unvollständigem Manifest (INV-13).
- **R-22-03** — Die Konsole zeigt je Mandant eine vollständige Liste aller aktiven Konnektorbindungen mit Zweck, Datenkategorie und Sitz, exportierbar als Anlage zu einem Auftragsverarbeitungsvertrag. Prüfbar: Export vergleichen mit der Menge der Bindungen des Mandanten; Abweichung 0 Einträge.

## 22.2 Datenschutz: Pflichten, Funktionen, Nachweise, Restaufgaben

| Pflicht | Fundstelle | Produktfunktion | Erzeugter Nachweis | Restaufgabe des Betreibers |
|---|---|---|---|---|
| Grundsätze, insbesondere Datenminimierung und Speicherbegrenzung | Art. 5 Abs. 1 | Auditkern ohne Klartextnamen; Aufbewahrungsklassen je Anhang; Sperrfrist statt Wiedervergabe von Mailadressen (K-25) | Aufbewahrungsklassen je Datenbestand, Löschprotokoll | Zweckbindung und Erforderlichkeit je Fachverfahren beurteilen |
| Rechenschaftspflicht | Art. 5 Abs. 2 | Vorgang als Änderungseinheit mit Urheber, Wirkungsvorschau, Freigabe und Ergebnis (INV-03) | Auditstrom mit Kettennachweis, exportierbar | Nachweise sichten, bewerten, aufbewahren |
| Datenschutz durch Technikgestaltung | Art. 25 Abs. 1 | Default-Deny (INV-10), Geheimnisse nie lesbar (INV-20), Mandantenprädikat erzwungen (INV-19), Verschlüsselung der Speicherbereiche | Architekturbelege, Prüfergebnisse aus dem Bau (K-27) | Verfahrensbezogene Risikobewertung |
| Datenschutzfreundliche Voreinstellungen | Art. 25 Abs. 2 | Neue Veröffentlichung standardmäßig intern; neuer Dienst ohne Zuweisung ohne Nutzer; Richtlinie als benannte Quelle jeder Vorbelegung (INV-15) | Richtlinienstand mit Version und Änderungsvorgang | Abweichungen von der Voreinstellung begründen |
| Auftragsverarbeitung | Art. 28 | Konnektorbindungen als deklarierte Empfängerliste; Weisungsbindung des Reconcilers über Feldeigentum (INV-13) | Bindungsliste je Mandant, Feldeigentumsabbildung | Verträge schließen, Unterauftragsverarbeiter genehmigen |
| Verzeichnis der Verarbeitungstätigkeiten | Art. 30 | Erzeugter Entwurf aus Diensten, Datenklassenvorgabe des Katalogeintrags, Speicherbereichen, Replikatstandorten und Konnektorbindungen | Maschinenlesbarer Verzeichnisentwurf je Mandant mit Erzeugungszeitpunkt | Zwecke, Rechtsgrundlagen, Betroffenenkategorien, Löschfristen fachlich ergänzen |
| Technische und organisatorische Maßnahmen | Art. 32 | Verschlüsselung, Pseudonymisierung im Export, Zugriffsrechte, Wiederherstellbarkeit (K-08 bis K-11), regelmäßige Wiederherstellungsübung (K-24) | Istzustandsbericht mit Beobachtungszeitpunkt (INV-28), Prüfstatus jedes Wiederherstellungspunkts | Organisatorische Maßnahmen, Schulung, Zutrittskontrolle |
| Meldung einer Verletzung an die Aufsichtsbehörde | Art. 33 | Betroffenheitsabgrenzung je Mandant aus dem Auditstrom; Auditexport mit Kettennachweis; Vorfallvorgang mit Zeitachse | Exportpaket mit Zeitstempel nach RFC 3161 | Bewertung, Fristbeginn festlegen, Meldung absetzen, Dokumentation nach Art. 33 Abs. 5 |
| Benachrichtigung betroffener Personen | Art. 34 | Ermittlung der betroffenen Personenmenge über Zuweisungen und Postfächer | Personenliste je betroffenem Dienst | Risikobewertung, Formulierung, Versand |
| Datenschutz-Folgenabschätzung | Art. 35 | Datenklassenvorgabe je Katalogeintrag als Eingangsgröße | Datenklassenübersicht je Dienst | Durchführung und Dokumentation |
| Drittlandübermittlung | Art. 44 ff. | Sitz des Fremdsystems als Pflichtfeld der Bindung (R-22-02) | Bindungsliste mit Sitzangabe | Rechtsgrundlage der Übermittlung feststellen |

Der Verzeichnisentwurf ist der größte automatisierbare Anteil und zugleich der am leichtesten überschätzte. **Rechnung Abdeckung:** Von sieben Pflichtangaben eines Verzeichniseintrags kann Atrium vier aus dem Objektgraphen belegen, nämlich Empfängerkategorien, Drittlandbezug, Löschfristen der technischen Ablage und die allgemeine Beschreibung der technischen Maßnahmen; drei bleiben offen, nämlich Zweck, Kategorien betroffener Personen und Datenkategorien jenseits der groben Datenklassenvorgabe. Das sind 4/7 ≈ 57 % der Felder, aber 0 % der Bewertungen. **Deutung:** Der Entwurf ersetzt die Inventarisierung, nicht die juristische Arbeit; wer ihn als fertiges Verzeichnis ausgibt, erzeugt ein vollständig aussehendes und inhaltlich leeres Dokument. Das ist ein Modell mit der Annahme, dass die Fachanwendung keine weiteren Verarbeitungen außerhalb ihres Speicherbereichs durchführt.

**Anforderungen**

- **R-22-04** — Der Verzeichnisentwurf kennzeichnet jedes Feld als "abgeleitet" mit Quellobjekt oder als "vom Betreiber zu ergänzen"; 0 Felder sind ohne diese Kennzeichnung. Prüfbar: Schemaprüfung des Exports.
- **R-22-05** — Ein Auditexport zu einem benannten Zeitraum und Mandanten wird in ≤ 10 min erzeugt, enthält den Kettennachweis und ist ohne Atrium prüfbar. Prüfbar: Export und Prüfung mit dem eigenständigen Prüfwerkzeug auf einem System ohne Atrium (INV-23).
- **R-22-06** — Die Ermittlung der von einem Vorfall betroffenen Personen eines Mandanten liefert in ≤ 2 h ein Ergebnis mit benannter Unsicherheit. Prüfbar: Übung mit vorgegebenem Vorfallmuster.
- **R-22-07** — Jede neue Veröffentlichung hat die Sichtbarkeit "intern" als Vorbelegung; eine externe Sichtbarkeit ist eine ausdrückliche Entscheidung mit Wirkungsvorschau. Prüfbar: Formularprüfung im Bau (INV-15, K-27).

## 22.3 Löschkonzept

Ein Löschkonzept ist keine Einstellung, sondern eine Zuordnung von Datenbeständen zu Fristen, Auslösern und Zuständigkeiten. Atrium liefert die Zuordnung technisch; die Fristen der fachlichen Klassen setzt der Mandant.

| Datenbestand | Ort | Frist | Auslöser | Wirkung | Nachweis |
|---|---|---|---|---|---|
| Personenobjekt und abgeleitete Attribute | Sollzustand | sofort mit dem Vorgang | Löschung der Person | Objekt entfernt, Kennung bleibt als schwacher Verweis | Vorgang mit Auswirkungsliste |
| Abgeleitete Fremdkonten | Fremdsystem über Konnektorbindung | mit dem Vorgang, je Zielsystem sichtbar | Wegfall der Zuweisung | Deaktivierung oder Löschung nach Feldeigentum | Teilzustand je Zielsystem (INV-12) |
| Postfachinhalt | Ablageort der Konnektorbindung | fachlich, vom Mandanten gesetzt | Löschung des Postfachs | Als nicht rücknehmbar gekennzeichnet | Vorgang mit ausdrücklicher Bestätigung (INV-11) |
| Speicherbereich eines Dienstes | Knoten | Aufbewahrungsfrist 30 d, dann Schlüsselvernichtung (K-25) | Entfernen des Dienstes | Datenträger freigegeben, Schlüssel gelöscht | Zustandswechsel des Speicherbereichs |
| Wiederherstellungspunkte | lokal und ausgelagert | Aufbewahrungsende je Punkt (K-25) | Zeitablauf | Punkt verfällt | Aufbewahrungsende je Punkt sichtbar |
| Auditkern | Auditstrom | 12 Monate vollständig, danach verdichtet | Zeitablauf | Verdichtung, keine Löschung (INV-23) | Kettennachweis über die Verdichtung hinweg |
| Auditanhang Klasse `betrieb_90d` | Auditstrom | 90 d | Zeitablauf ohne Bedienereingriff | Anhang gelöscht, Kern bleibt | Statuswechsel `anhang_geloescht` |
| Auditanhang Klasse `nachweis_12m` | Auditstrom | 12 Monate | Zeitablauf | wie vor | wie vor |
| Auditanhang Klasse `gesetzlich` | Auditstrom | vom Betreiber gesetzt | Ablauf der gesetzten Frist | wie vor | wie vor |
| Geheimnisse | TPM, Token, verschlüsselter Speicher | mit dem Widerruf | Widerruf oder Wechsel | Referenz ungültig, Wert vernichtet | Auditereignis, nie der Wert (INV-20) |

Die ehrliche Schwachstelle liegt bei den Wiederherstellungspunkten. Eine Löschung im laufenden System erreicht eine bereits erzeugte, signierte Sicherung nicht, ohne deren Signatur und damit ihren Zweck zu zerstören. Atrium löst das nicht durch nachträgliche Bearbeitung, sondern durch eine **Löschvormerkung**: die Löschung wird als Eintrag geführt, der bei jedem Rückspielen eines älteren Wiederherstellungspunkts erneut angewandt wird, bevor der Sollzustand aktiv wird. Damit bleibt die Restpräsenz in Sicherungen bestehen, sie wird aber nicht wieder wirksam.

**Rechnung Restpräsenz:** Nach K-25 bestehen tägliche Exporte 90 Tage und Monatsstände 12 Monate. Eine am Tag T gelöschte Person ist damit längstens bis T + 12 Monate in mindestens einem Wiederherstellungspunkt enthalten, im Mittel bei gleichverteiltem Löschzeitpunkt rund 6 Monate. **Deutung:** Die Aussage "gelöscht" ist ohne Zusatz falsch; die Konsole muss "gelöscht, in Sicherungen längstens bis <Datum> enthalten, beim Rückspielen erneut gelöscht" sagen. Eine kürzere Aufbewahrung senkt die Restpräsenz linear und die Wiederherstellbarkeit im selben Maß; dieser Zielkonflikt ist nicht auflösbar, sondern nur zu entscheiden.

**Rechnung Umfang eines Löschvorgangs:** Annahme je Person 4 Zuweisungen, je Zuweisung 1 Fremdkonto und 1 Gruppenmitgliedschaft, 1,6 Geräte mit je 1 Zertifikat, 1 Postfach mit 1,3 Mailadressen. Das ergibt 4 + 8 + 3,2 + 2,3 ≈ 17,5 abgeleitete Artefakte je Person. Bei 500 Personen und einer angenommenen Fluktuation von 15 % jährlich sind das 75 Löschvorgänge mit rund 1.310 Artefaktrückbauten je Jahr. **Deutung:** Ohne Kaskadenrückbau über die Zuweisung wäre das Handarbeit in einer Größenordnung, die verlässlich unvollständig bleibt; die Auswirkungsliste vor der Bestätigung ist deshalb keine Bequemlichkeit, sondern die Bedingung dafür, dass eine Löschung vollständig wird.

**Anforderungen**

- **R-22-08** — Jede Löschung einer Person zeigt vor der Bestätigung die vollständige Liste der betroffenen abgeleiteten Artefakte je Zielsystem; nach Abschluss ist die Zahl der nicht zurückgebauten Artefakte benannt und nicht 0 verschwiegen. Prüfbar: Löschtest mit injiziertem Konnektorfehler (INV-11, INV-12).
- **R-22-09** — Eine Löschvormerkung wird bei jedem Rückspielen eines Wiederherstellungspunkts vor der Aktivierung des Sollzustands angewandt. Prüfbar: Wiederherstellungsübung mit einer vor der Sicherung angelegten und nach der Sicherung gelöschten Person (K-24).
- **R-22-10** — Die Konsole nennt bei jeder Löschung das Datum, bis zu dem der Bestand in Wiederherstellungspunkten enthalten bleibt. Prüfbar: Darstellungstest gegen das späteste Aufbewahrungsende.
- **R-22-11** — Jeder Datenbestand im Löschkonzept trägt genau eine Aufbewahrungsklasse; 0 Bestände ohne Klasse. Prüfbar: Bestandsabgleich gegen die Klassentabelle im Bau.

## 22.4 Betroffenenrechte und der Konflikt mit der Auditkette

| Recht | Fundstelle | Was Atrium liefert | Was Atrium nicht liefert |
|---|---|---|---|
| Auskunft | Art. 15 | Auszug aller Objekte mit Bezug zur Person aus dem Sollzustand, Auditauszug mit der Person als Akteur, Liste der Zielsysteme | Inhalte in Fachanwendungen; Ereignisse, in denen die Person Gegenstand einer Untersuchung ist |
| Berichtigung | Art. 16 | Änderung am Personenobjekt als Vorgang, Durchreichen an alle Zielsysteme mit Feldeigentum von Atrium | Felder in Fremdbesitz; dort ist die Fremdoberfläche zuständig (INV-30) |
| Löschung | Art. 17 | Löschkonzept nach 22.3 | Löschung des Auditkerns |
| Einschränkung | Art. 18 | Sperrung der Person: Anmeldung überall aus, Daten bleiben | Einschränkung einzelner Felder |
| Datenübertragbarkeit | Art. 20 | Strukturierter Export der Identitäts-, Gruppen- und Zuweisungsdaten | Fachdaten aus Fremdsystemen |
| Fristen und Modalitäten | Art. 12 | Anfrage als Vorgang mit Frist, Fristablauf als sichtbare Aufgabe | Bewertung, ob die Anfrage berechtigt ist |

Die Grenze bei der Datenübertragbarkeit folgt aus dem Konnektorvertrag und wird hier offen benannt. Der Vertrag kennt `describe`, `observe`, `plan`, `apply` und `healthcheck`; er kennt keine Operation `export`. `observe` liefert ausschließlich Felder, die im Feldeigentum abgebildet sind, also den Versorgungszustand und nicht den Postfachinhalt oder die Vorgangshistorie eines Ticketsystems. Ein Export von Fachdaten müsste je Fremdsystem eigene, versionsabhängige Ausleitungen implementieren und wäre der Anfang genau der Kopplungstiefe, die [Kapitel 9](09-konnektoren.md) vermeidet. **Konsequenz:** Für Fachdaten verweist die Konsole ausdrücklich auf die Ausleitungsfunktion des Fremdprodukts, statt Vollständigkeit zu suggerieren.

Der Konflikt zwischen Löschanspruch und Unveränderlichkeit ist real und wird in 19.9 in seine drei Teile zerlegt. Hier ist die rechtliche Einordnung nachzutragen, und sie ist eine Einordnung und keine Auskunft: Der Fortbestand des Auditkerns nach einer Löschung stützt sich auf die Ausnahmetatbestände des Artikels 17, namentlich die Erfüllung einer rechtlichen Verpflichtung und die Geltendmachung oder Verteidigung von Rechtsansprüchen. Ob diese Tatbestände im konkreten Fall greifen, ist eine Bewertung des Verantwortlichen. Atrium stellt dafür zwei Dinge bereit und behauptet nichts darüber hinaus: die technische Trennung, die den personenbeziehbaren Teil löschbar macht, und die ausdrückliche Anzeige, welcher Restbestand nach einer Löschung im Kern verbleibt.

**Rechnung Auskunftsaufwand:** Annahme 500 Personen und 2 Auskunftsersuchen je 100 Personen und Jahr, also 10 Ersuchen jährlich. Annahme je Ersuchen 0,25 Personentage bei automatisch erzeugtem Auszug gegenüber 1,5 Personentagen bei händischer Zusammenstellung aus Fremdsystemen. Das ergibt 2,5 statt 15 Personentage jährlich, eine Ersparnis von 12,5 Personentagen. **Deutung:** Der Nutzen entsteht nicht durch das Dokument, sondern dadurch, dass die Menge der Zielsysteme aus den Zuweisungen bekannt ist; ohne Zuweisung als Absichtsobjekt ist schon die Frage "wo überall existiert diese Person" unbeantwortbar. Das ist ein Modell, keine Messung.

**Anforderungen**

- **R-22-12** — Ein Auskunftsauszug zu einer Person wird in ≤ 5 min erzeugt und nennt jedes Zielsystem, in dem ein abgeleitetes Konto besteht, einschließlich der Zielsysteme mit fehlgeschlagener Beobachtung. Prüfbar: Erzeugung bei einer Person mit 4 Zuweisungen und einer gestörten Konnektorbindung.
- **R-22-13** — Der Auszug kennzeichnet ausdrücklich, dass er keine Fachdaten aus Fremdsystemen enthält, und benennt diese Systeme namentlich. Prüfbar: Inhaltsprüfung des Auszugs.
- **R-22-14** — Nach einer Löschung zeigt die Konsole den im Auditkern verbleibenden Restbestand je Feldart an. Prüfbar: Löschung mit anschließendem Abgleich gegen das Kernschema (INV-23).
- **R-22-15** — Ein Übertragbarkeitsexport ist maschinenlesbar, schemaversioniert und ohne Atrium interpretierbar. Prüfbar: Schemaprüfung und Einlesen durch ein Fremdwerkzeug.

## 22.5 Informationssicherheitsmanagement

Der einschlägige internationale Standard (ISO/IEC 27001:2022) besteht aus zwei Teilen mit unterschiedlicher Automatisierbarkeit. Die Anforderungen an das Managementsystem selbst (Kontext, Führung, Planung, Unterstützung, Betrieb, Bewertung der Leistung, Verbesserung) sind Organisationsarbeit und durch ein Produkt nicht ersetzbar. Die Maßnahmen im Anhang gliedern sich in vier Themenbereiche, und nur einer davon wird von Atrium wesentlich getragen. Maßnahmennummern werden hier nicht zitiert, weil die Zuordnung je Fassung des Standards zu prüfen ist.

| Themenbereich | Beispielhafte Pflichtthemen | Produktfunktion | Erzeugter Nachweis | Restaufgabe |
|---|---|---|---|---|
| Organisatorisch | Zugriffssteuerung, Rollen und Verantwortlichkeiten, Lieferantenbeziehungen, Vorfallbehandlung, Kontinuität | Rollen mit Geltungsbereich und Befristung, Freigabewesen, Katalogeinträge mit Herkunft und Signatur, Vorfallpfad ([Kapitel 20](20-sicherheit.md)) | Rollenzuordnung mit Version, Freigabeprotokoll, Bindungsliste | Richtlinien beschließen, Lieferanten bewerten, Zuständigkeiten benennen |
| Personenbezogen | Eignung, Schulung, Disziplinarverfahren, Arbeiten aus der Ferne | keine | keine | vollständig organisatorisch |
| Physisch | Zutritt, Sicherungszonen, Entsorgung von Datenträgern, Verkabelung | Fehlerzone je Knoten als Modellgröße, Schlüsselvernichtung bei Freigabe eines Speicherbereichs, TPM-Bindung | Fehlerzonenzuordnung, Vernichtungsereignis | Zutrittskontrolle, Entsorgungsnachweis für Hardware |
| Technologisch | Endgerätesicherheit, Zugriffsrechte, Kryptografie, Protokollierung, Überwachung, sichere Entwicklung, Netztrennung, Sicherungskopien | Geräteverwaltung mit Zertifikaten ([Kapitel 13](13-geraeteverwaltung.md)), interne PKI ([Kapitel 11](11-pki.md)), Auditstrom, Netzzonen mit Default-Deny, Sicherungspläne mit Prüfstatus | Istzustandsbericht mit Beobachtungszeitpunkt, Zertifikatsübersicht, Prüfstatus je Wiederherstellungspunkt | Kryptorichtlinie beschließen, Schwellenwerte festlegen |

**Rechnung Nachweisautomatisierung:** Annahme, ein Zertifizierungsaudit fordert je Themenbereich 25 Einzelnachweise, also 100 insgesamt. Annahme der Abdeckung nach der Tabelle: organisatorisch 40 %, personenbezogen 0 %, physisch 20 %, technologisch 80 %. Das ergibt 10 + 0 + 5 + 20 = 35 automatisch erzeugbare Nachweise, also 35 %. **Deutung:** Ein Drittel der Nachweisarbeit entfällt, zwei Drittel bleiben; die Aussage "das Produkt macht zertifizierungsfähig" ist damit widerlegt, die Aussage "das Produkt senkt die Nachweislast messbar" bleibt. Beide Annahmen sind gesetzt und nicht gemessen; die Zahl ändert sich mit dem Geltungsbereich der Zertifizierung.

**Anforderungen**

- **R-22-16** — Jeder automatisch erzeugte Nachweis nennt Erzeugungszeitpunkt, Beobachtungszeitpunkt des zugrunde liegenden Istzustands und die Objekte, aus denen er abgeleitet ist. Prüfbar: Schemaprüfung aller Nachweisexporte (INV-28).
- **R-22-17** — Die Nachweisübersicht kennzeichnet je Themenbereich, welcher Anteil nicht aus dem Produkt stammt; 0 Themenbereiche ohne diese Angabe. Prüfbar: Darstellungstest.

## 22.6 Nationales Grundschutzwerk

Das BSI IT-Grundschutz-Kompendium ist nach Baustein-Familien gegliedert; Atrium trägt zu jeder Familie unterschiedlich viel bei. Bausteinkennungen werden bewusst nicht zitiert, weil das Kompendium fortgeschrieben wird und eine Zuordnung auf Kennungsebene je Edition erneut zu prüfen ist.

| Baustein-Familie | Beitrag von Atrium | Grenze |
|---|---|---|
| Sicherheitsmanagement und Organisation | Rollen, Funktionstrennung, Freigabewege, Notzugang mit Nachbereitungspflicht | Leitlinie, Rollenbesetzung, Sensibilisierung bleiben organisatorisch |
| Konzepte und Vorgehensweisen | Kryptokonzept aus dem Kryptoinventar, Löschkonzept nach 22.3, Sicherungskonzept aus Datensicherheitsstufe und Sicherungsplan | Schutzbedarfsfeststellung ist eine fachliche Bewertung |
| Betrieb | Abgleichschleife, Aktualisierung mit Rücksprungpunkt, Protokollierung, Schwachstellenabgleich der Stückliste ([Kapitel 21](21-betrieb-updates.md)) | Betriebshandbuch, Notfallübungen jenseits der Wiederherstellungsübung |
| Detektion und Reaktion | Erkennungssignale und Eindämmungsvorgänge (20.11), Auditexport zur Beweissicherung | Alarmierungsorganisation, Rufbereitschaft |
| Anwendungen | Katalogeintrag mit Produktgrenzdeklaration (INV-30), Veröffentlichung als einzige Erreichbarkeitsquelle | Fachkonfiguration der Fremdprodukte bleibt in deren Oberfläche |
| IT-Systeme | Knoten mit unveränderlichem A/B-Wurzeldateisystem, Messung in das TPM, Lease-basierte Selbstabschottung | Hardwarebeschaffung, Firmwarepflege der Geräte |
| Netze und Kommunikation | Netzzonen, Default-Deny, Overlay, DNS-Sichten ([Kapitel 12](12-dns-netzwerk.md)) | Netzgeräte außerhalb von Atrium |
| Infrastruktur | Fehlerzone als Modellgröße für Antiaffinität | Räume, Strom, Klima, Zutritt |

Ein konkreter, häufig unterschätzter Beitrag liegt am Anfang der Grundschutz-Vorgehensweise. Die Strukturanalyse und der Netzplan sind aus dem Objektgraphen ableitbar, weil Knoten, Dienste, Netzzonen, Geräte, Veröffentlichungen und Konnektorbindungen vollständig erfasst sind und jede Erreichbarkeit aus einem Objekt folgt (INV-09, INV-10). **Deutung:** Der Aufwand der Strukturanalyse entfällt nicht, aber er verschiebt sich von der Erhebung zur Prüfung der Erhebung, und das ist der Teil, der bei händischer Pflege regelmäßig veraltet.

**Anforderungen**

- **R-22-18** — Atrium exportiert eine Strukturübersicht mit allen Knoten, Diensten, Netzzonen, Geräten, Veröffentlichungen und Konnektorbindungen einschließlich der abgeleiteten Erreichbarkeiten, mit Beobachtungszeitpunkt. Prüfbar: Vergleich des Exports mit dem Sollzustand; Abweichung 0 Objekte.
- **R-22-19** — Der Export benennt je Eintrag die Quelle jeder Erreichbarkeit; 0 Erreichbarkeiten ohne Quellverweis. Prüfbar: Schemaprüfung (INV-09).

## 22.7 Netz- und Informationssicherheitsrichtlinie

Die Richtlinie (EU) 2022/2555 verpflichtet nicht das Produkt, sondern Einrichtungen bestimmter Sektoren ab bestimmten Größen, unterschieden in wesentliche und wichtige Einrichtungen. Die Sektorenlisten und Schwellenwerte stehen im Rechtsakt und in der nationalen Umsetzung; sie werden hier nicht wiedergegeben, weil eine verkürzte Wiedergabe zur Fehleinschätzung der eigenen Betroffenheit führt. Für den Entwurf ist nur relevant, dass ein Betreiber von Atrium betroffen sein kann und dass ein Anbieter, der Atrium als verwalteten Dienst betreibt, selbst in den Anwendungsbereich fallen kann.

| Pflichtenbereich | Was verlangt wird | Produktbeitrag | Bleibt organisatorisch |
|---|---|---|---|
| Risikomanagementmaßnahmen | Risikoanalyse, Vorfallbehandlung, Kontinuität und Sicherungen, Zugriffskontrolle, Kryptografie, Wirksamkeitsbewertung, Cyberhygiene | Sicherungen mit geprüftem Wiederherstellungspunkt (K-24), Zugriffskontrolle, Kryptoinventar, Vorfallpfad, Istzustandsbericht als Eingangsgröße der Wirksamkeitsbewertung | Risikoanalyse, Schulung, Bewertung der Wirksamkeit |
| Meldewege | Gestufte Meldung an die zuständige Stelle: Frühwarnung, Folgemeldung, Abschlussbericht | Vorfallvorgang mit Zeitachse, Betroffenheitsabgrenzung je Mandant, Exportpaket je Meldestufe | Meldeentscheidung, Kommunikation mit der Behörde |
| Lieferkettensicherheit | Sicherheit direkter Lieferanten und Dienstleister, Berücksichtigung in Beschaffung und Wartung | Signierte Katalogeinträge mit Herkunft, Stückliste je Abbild, Transparenzprotokoll der Freigaben, Bindungsliste als Lieferanteninventar | Lieferantenbewertung, Vertragsgestaltung |
| Leitungsverantwortung | Billigung und Überwachung der Maßnahmen durch die Leitung, Schulungspflicht, Verantwortlichkeit | Freigabewesen: eine Freigabeinstanz kann eine Leitungsrolle sein, jede Billigung ist ein auditiertes Objekt mit Urheber und Zeitpunkt | Besetzung der Rolle, Schulung, Haftungsfragen |

Die Meldefristen sind die einzige Stelle, an der der Entwurf eine Zahl aus dem Rechtstext benötigt, und er behandelt sie deshalb als Parameter statt als Konstante. **Entwurfsentscheidung:** Der Meldeassistent führt die Fristen je Meldestufe als konfigurierbare Werte mit Vorbelegung aus dem zum Freigabezeitpunkt geltenden Rechtstext und nennt die Quelle der Vorbelegung (INV-15); die verworfene Alternative, die Fristen fest zu verdrahten, hätte bei jeder Änderung des Rechtstextes oder bei abweichender nationaler Umsetzung eine Produktänderung erzwungen und in der Zwischenzeit einen falschen Wert angezeigt.

**Rechnung Fristbudget einer Meldung:** Annahme einer 72-Stunden-Frist ab Kenntnis. Aus den Zielwerten des Entwurfs ergibt sich: Kettenprüfung des Auditbestands ≤ 30 s, Auditexport ≤ 10 min (R-22-05), Betroffenheitsabgrenzung je Mandant ≤ 2 h (R-22-06), Zusammenstellung des Exportpakets ≤ 1 h. Summe der technischen Anteile rund 3,2 h, also 4,4 % der Frist. **Deutung:** Die Frist wird nicht durch Technik verbraucht, sondern durch Bewertung und Abstimmung; ein Produkt, das die technischen Anteile von Tagen auf Stunden senkt, verschiebt damit den Engpass vollständig in die Organisation. Das ist ein Modell aus den Zielwerten des Entwurfs, keine Messung.

**Anforderungen**

- **R-22-20** — Der Meldeassistent führt je Meldestufe eine konfigurierbare Frist mit benannter Quelle der Vorbelegung und zeigt die verbleibende Restzeit ab dem eingetragenen Kenntniszeitpunkt. Prüfbar: Formularprüfung und Zeitablauftest (INV-15).
- **R-22-21** — Ein Exportpaket je Meldestufe enthält Zeitachse, betroffene Mandanten, betroffene Dienste, Auditauszug mit Kettennachweis und die Liste der noch offenen Punkte. Prüfbar: Schemaprüfung des Pakets.
- **R-22-22** — Jede Freigabe durch eine Leitungsrolle ist ein Auditereignis mit Akteur, Rollenversion und Zeitpunkt und ist nachträglich nicht änderbar. Prüfbar: Freigabetest mit anschließendem Änderungsversuch (INV-23).

## 22.8 Produktsicherheitsverordnung für Produkte mit digitalen Elementen

Der Cyber Resilience Act (Verordnung (EU) 2024/2847) richtet sich an den Hersteller und damit an das Projekt selbst, nicht an den Betreiber. Das unterscheidet ihn von allen anderen Abschnitten dieses Kapitels und macht ihn zur einzigen Regulatorik, die den Entwicklungsprozess unmittelbar bindet.

| Herstellerpflicht | Konsequenz für Entwicklung | Konsequenz für Freigabe | Konsequenz für Betrieb |
|---|---|---|---|
| Sicherheitsanforderungen an das Produkt | Default-Deny (INV-10), keine Standardkennwörter, Geheimnisse nie lesbar (INV-20), minimale Angriffsfläche durch Portmatrix und Dauerprozessgrenze (K-20) | Nachweis der Umsetzung als Teil der technischen Dokumentation | Auslieferungszustand ist der bewertete Zustand; jede Abweichung ist eine Betreiberentscheidung mit Begründung |
| Keine bekannten ausnutzbaren Schwachstellen bei Bereitstellung | Abhängigkeitsbudget, Abgleich der Stückliste im Bau | Freigabe bricht bei offenem Treffer der Klasse S0 oder S1 | Sicherheitsmeldung je Freigabe |
| Schwachstellenbehandlung über den Unterstützungszeitraum | Meldeweg, Bewertungsschema, Fristen (20.10) | Unterstützungszeitraum je Hauptversion maschinenlesbar deklariert | Rückportierung in alle unterstützten Linien |
| Stückliste | Erzeugung im Bau, beide Formate | Stückliste als signiertes Beiwerk jedes Abbilds | Täglicher Abgleich gegen öffentliche Quellen |
| Bereitstellung von Sicherheitsaktualisierungen | Trennung von Sicherheits- und Funktionsänderung im Abbildbau | Getrennte Kennzeichnung je Freigabe | Sicherheitsaktualisierung ohne Funktionsänderung installierbar; Zielwert Außenkante ≤ 72 h (K-23) |
| Meldepflicht bei aktiv ausgenutzten Schwachstellen | Bereitschaft der Meldekette als Bestandteil des Prozesses | Benannter Verantwortlicher je Freigabe | Erstmeldung und Folgemeldung als Zielwerte nach 20.10 |

Die Frage, ab wann ein quelloffenes Projekt als Hersteller gilt, ist die schwierigste dieses Kapitels und wird hier nicht juristisch entschieden, sondern in ihrer Wirkung auf den Entwurf beschrieben. Die Verordnung unterscheidet zwischen der Bereitstellung auf dem Markt im Rahmen einer Geschäftstätigkeit, die Herstellerpflichten auslöst, und der nicht-kommerziellen Bereitstellung quelloffener Software, für die ein leichteres Regime mit der Rolle eines Verwalters quelloffener Software vorgesehen ist. Entgeltlicher Support, Subskriptionen, gehostete Angebote und die Steuerung eines Produkts durch eine kommerziell tätige Organisation verschieben ein Projekt in Richtung der Herstellerrolle.

**Entwurfsentscheidung:** Das Projekt richtet seinen Prozess von Anfang an auf die Herstellerrolle aus. Begründung: Ein tragfähiges Geschäftsmodell für Atrium beruht nach [Kapitel 23](23-oekonomie-roadmap.md) auf entgeltlichen Leistungen, und ein Prozess, der erst nachträglich auf Herstellerpflichten umgestellt wird, müsste Stückliste, Transparenzprotokoll und Unterstützungszeitraum rückwirkend für bereits ausgelieferte Freigaben herstellen. Die verworfene Alternative, sich auf das leichtere Regime zu stützen, wäre genau in dem Moment unbrauchbar, in dem das Projekt wirtschaftlich relevant wird.

**Rechnung Unterstützungszeitraum:** Annahme einer Hauptversion je Jahr und eines Unterstützungszeitraums von 5 Jahren ergibt 5 gleichzeitig unterstützte Linien. Aus dem Modell in 20.10 folgen 45 Schwachstellentreffer jährlich, davon 13,5 mit Behebungsbedarf. Annahme: Bewertung einmal je Treffer 0,07 Personentage (45 × 0,07 = 3,15 PT), Behebung in der aktuellen Linie 0,5 Personentage je Fall (13,5 × 0,5 = 6,75 PT), Rückportierung 0,3 Personentage je Fall und älterer Linie (13,5 × 4 × 0,3 = 16,2 PT). Summe 26,1 Personentage jährlich gegenüber 9,9 Personentagen bei nur einer unterstützten Linie. **Deutung:** Der Unterstützungszeitraum ist der teuerste einzelne Parameter der Produktpflege, und der Aufwand wächst nahezu linear mit der Zahl der Linien. Ein kurzer Zeitraum ist billig und für Betreiber unbrauchbar, ein langer Zeitraum ist eine dauerhafte Personalbindung; die Festlegung gehört deshalb in die Wirtschaftlichkeitsbetrachtung und nicht in die technische Dokumentation. Das ist ein Modell, keine Messung.

**Anforderungen**

- **R-22-23** — Jede Freigabe deklariert den Unterstützungszeitraum maschinenlesbar mit Enddatum; die Konsole zeigt am Knoten an, wie viele Tage der Unterstützungszeitraum der installierten Version noch läuft. Prüfbar: Darstellungstest und Schemaprüfung der Freigabemetadaten.
- **R-22-24** — Jede Freigabe trägt eine Stückliste in beiden Formaten, signiert und im Transparenzprotokoll eingetragen; eine Freigabe ohne beides wird vom Aktualisierungspfad abgelehnt. Prüfbar: Einspielversuch einer Freigabe ohne Stückliste.
- **R-22-25** — Eine Sicherheitsaktualisierung der Außenkante ist ohne Funktionsänderung installierbar und als solche gekennzeichnet. Prüfbar: Vergleich zweier aufeinanderfolgender Abbilder auf Funktionsänderungen (K-23).
- **R-22-26** — Zu jeder behobenen Schwachstelle existiert eine maschinenlesbare Sicherheitsmeldung mit Komponente, Schweregrad, betroffenen Versionen und Behebungsversion; die Zahl stiller Behebungen ist 0. Prüfbar: Abgleich der Freigabedifferenzen gegen die Menge der Meldungen.

## 22.9 Barrierefreiheit

Die europäische Anforderung ergibt sich aus der Richtlinie (EU) 2019/882, national umgesetzt durch das Barrierefreiheitsstärkungsgesetz. Ihr Geltungsbereich erfasst bestimmte Produkte und Dienstleistungen für Verbraucher; ein Serverbetriebssystem, dessen Bedienoberfläche sich an Administratoren richtet, fällt nicht ohne Weiteres darunter. Das entbindet den Entwurf nicht, und zwar aus drei Gründen: öffentliche Stellen unterliegen der unionsrechtlichen Vorgabe zur Barrierefreiheit von Websites und mobilen Anwendungen öffentlicher Stellen unabhängig davon; über Atrium veröffentlichte Dienste können selbst in den Geltungsbereich fallen; und INV-31 macht WCAG 2.2 Stufe AA ohnehin zur Bedingung.

| Ebene | Gegenstand | Verhältnis zueinander |
|---|---|---|
| Rechtsakt | Richtlinie (EU) 2019/882 und nationale Umsetzung | Legt fest, wer verpflichtet ist und welche Rechtsfolgen ein Verstoß hat |
| Harmonisierte europäische Norm für IKT-Barrierefreiheit | Technische Anforderungen an Web-Inhalte, Nicht-Web-Software, Dokumente und Support | Konformitätsvermutung bei Einhaltung; übernimmt die Web-Erfolgskriterien |
| Richtlinienfamilie für Webinhalte, WCAG 2.2 | Prüfbare Erfolgskriterien in drei Stufen | Die eigentliche Prüfgrundlage; Stufe AA ist der übliche Bezugspunkt |

Prüfung und Erklärung zerfallen in einen automatisierbaren und einen nicht automatisierbaren Teil. Die Regelprüfung im Bau (K-27) erkennt strukturelle Verstöße wie fehlende Beschriftungen, unzureichende Kontraste und fehlende Rollenauszeichnung; sie erkennt nicht, ob eine Fehlermeldung verständlich, eine Vorlesereihenfolge sinnvoll oder ein Tastaturpfad zumutbar ist. Deshalb tritt der Tastaturdurchlauf des Aufgabenkatalogs hinzu, und deshalb bricht ein Verstoß den Bau in beiden Verfahren.

**Rechnung Prüfaufwand je Freigabe:** Maßgeblich ist der vollständige Aufgabenkatalog aus [Abschnitt 18.2](18-bedienkonzept.md) mit 38 Standardaufgaben, denn R-18-33 verlangt den Tastaturdurchlauf aller 38 Aufgaben; die 20 Aufgaben aus K-26 sind die Stichprobe der Erstklickmessung und nicht der Prüfumfang der Barrierefreiheit. Je Aufgabe ein Tastaturdurchlauf und ein Bildschirmleserdurchlauf, Annahme 15 Minuten je Durchlauf. Das ergibt 38 × 2 × 0,25 h = 19 Stunden je Freigabe. Bei angenommen 6 Freigaben jährlich sind das 6 × 19 h = 114 Stunden oder 14,25 Personentage. **Deutung:** Der Aufwand ist tragbar, solange die Zahl der Standardaufgaben begrenzt bleibt; jede zusätzliche Standardaufgabe kostet dauerhaft 2 × 0,25 h = 0,5 Stunden je Freigabe, bei 6 Freigaben also 3 Stunden im Jahr, und ist damit ein Argument für die Begrenzung des Aufgabenkatalogs, nicht nur für die Bedienbarkeit.

Die Barrierefreiheitserklärung hat eine Grenze, die aus INV-30 folgt und offen benannt werden muss. Die Erklärung kann sich nur auf die Atrium Console beziehen. Die Oberflächen der über den Katalog bereitgestellten Fremdprodukte sind fremde Oberflächen, ihre Barrierefreiheit wird von ihren Herstellern verantwortet, und Atrium kann sie weder prüfen noch zusichern. **Konsequenz:** Der Katalogeintrag führt die Barrierefreiheitsangabe des Fremdprodukts als deklaratives Feld mit, kennzeichnet sie als Fremdangabe und lässt sie leer, wo keine vorliegt; eine leere Angabe wird als "unbekannt" angezeigt und nicht als "erfüllt" behandelt.

**Anforderungen**

- **R-22-27** — Die Atrium Console erfüllt WCAG 2.2 Stufe AA; die automatisierte Regelprüfung meldet 0 Verstöße, und der Tastaturdurchlauf aller 38 Standardaufgaben des Katalogs aus 18.2 ist ohne Zeigegerät vollständig durchführbar (R-18-33). Prüfbar: beide Verfahren im Bau; ein Verstoß bricht den Bau (INV-31, K-27).
- **R-22-28** — Die Barrierefreiheitserklärung nennt Stand, Prüfverfahren, nicht barrierefreie Bestandteile mit Begründung und einen Rückmeldeweg; sie bezieht sich ausdrücklich nur auf die Atrium Console. Prüfbar: Inhaltsprüfung gegen diese fünf Bestandteile.
- **R-22-29** — Jeder Katalogeintrag trägt ein Feld für die Barrierefreiheitsangabe des Fremdprodukts mit Quellenkennzeichnung; ein fehlender Wert wird als "unbekannt" dargestellt und nie als Erfüllung. Prüfbar: Darstellungstest mit einem Eintrag ohne Angabe (INV-30).

## 22.10 Vertrauensdienste

Die eIDAS-Verordnung (Verordnung (EU) 910/2014, novelliert 2024) regelt Vertrauensdienste und ihre Rechtswirkung. Die interne CA von Atrium ist kein Vertrauensdienst in diesem Sinne, und diese Abgrenzung ist für die Erwartungshaltung wichtiger als jede Funktionsbeschreibung.

| Gegenstand | Was die interne CA leistet | Was sie nicht leistet |
|---|---|---|
| Zertifikate | Authentisierung von Knoten, Diensten, Geräten und Personen innerhalb der Vertrauensdomäne des Betreibers; TLS-Serverzertifikate für interne Namen nach RFC 5280 | Qualifizierte Zertifikate mit gesetzlicher Rechtswirkung |
| Signaturen | Integritätsnachweis für Abbilder, Sollzustandsexporte, Wiederherstellungspunkte und Auditsiegel nach RFC 8032 | Qualifizierte elektronische Signatur oder qualifiziertes Siegel |
| Zeit | Zeitstempel nach RFC 3161 von einem konfigurierten Anbieter | Qualifizierter Zeitstempel, sofern der Anbieter kein qualifizierter Vertrauensdiensteanbieter ist |
| Sperrung | Sperrliste und OCSP nach RFC 6960 aus dem Kern | Aufnahme in eine nationale Vertrauensliste |
| Öffentliche Namen | Zertifikate von einer öffentlichen CA über ACME nach RFC 8555, gesteuert über CAA nach RFC 8659 | Vertrauen der internen Wurzel-CA in öffentlichen Wurzelspeichern; keine Konfiguration ändert das |

Zwei Punkte werden ausdrücklich nicht behauptet. Erstens: Die Signatur eines Auditsiegels ist kein Nachweis über eine Person, sondern über einen Verwaltungsknoten; der Siegelschlüssel gehört dem Knoten, nicht dem Bediener, und eine Zurechnung zu einer natürlichen Person ergibt sich daraus nicht. Zweitens: Ob ein konfigurierter Zeitstempeldienst qualifiziert ist, kann Atrium nicht selbst feststellen, weil das eine Eigenschaft des Anbieters und seiner Aufsicht ist und nicht des Protokolls. **Konsequenz:** Die Konsole zeigt den konfigurierten Zeitstempeldienst mit einem vom Betreiber gesetzten Kennzeichen "als qualifiziert erklärt" an und stellt daneben, dass diese Angabe eine Betreibererklärung und keine Prüfung ist.

**Anforderungen**

- **R-22-30** — Die Konsole verwendet an keiner Stelle die Begriffe "qualifiziert", "rechtssicher" oder "rechtsgültig" für Erzeugnisse der internen CA; die Trefferzahl in der Begriffsprüfung ist 0. Prüfbar: Musterprüfung aller Oberflächentexte (INV-16, K-27).
- **R-22-31** — Der konfigurierte Zeitstempeldienst wird mit Endpunkt, Betreibererklärung zur Qualifizierung und Datum der letzten erfolgreichen Anfrage angezeigt; fehlt der externe Zeitstempel, ist das Tagessiegel als "ohne externen Zeitstempel" gekennzeichnet und erzeugt eine offene Aufgabe. Prüfbar: Ausfalltest des Zeitstempeldienstes.

## 22.11 Revisionssicherheit und Aufbewahrung

| Aussage | Wodurch belegt | Grenze |
|---|---|---|
| Eine Handlung hat stattgefunden | Auditkern, hashverkettet (INV-23) | Nur Handlungen an der Plattform, nicht innerhalb einer Fachanwendung |
| Wer gehandelt hat | Akteurkennung, Rollenversion, Authentisierungsgüte | Belegt das verwendete Authentisierungsmittel, nicht die Identität der Person dahinter |
| Wann gehandelt wurde | Zeitstempel aus geprüfter Zeitquelle (INV-32), Tagessiegel mit externem Zeitstempel | Rückdatierung vor dem ersten Tagessiegel ist nur über die externe Zeitquelle ausgeschlossen |
| Woran gehandelt wurde | Objektverweis als schwacher Verweis | Überlebt die Löschung des Objekts, ohne dessen Inhalt zu erhalten |
| Was sich geändert hat | Anhang mit Vorher/Nachher | Nach Anhanglöschung nicht mehr rekonstruierbar (19.8) |
| Dass nichts entfernt wurde | Lückenlose Folgenummern, Abschnittssiegel | Ein Angreifer mit Knotenkontrolle kann den noch nicht gesiegelten Schwanz abschneiden |

Die letzte Zeile ist die präzise Restlücke, und sie wird hier beziffert statt umschrieben. Ein Abschnittssiegel entsteht alle 5 Minuten oder nach 1.000 Ereignissen. Wer einen Verwaltungsknoten vollständig kontrolliert, kann die seit dem letzten Siegel geschriebenen Ereignisse entfernen und die Folgenummern neu vergeben; da diese Ereignisse in kein Siegel eingegangen sind, entsteht keine erkennbare Lücke. **Rechnung Fenster:** Bei einer Auslagerungsverzögerung von ≤ 60 s nach 19.8 sinkt das praktisch ausnutzbare Fenster von 300 s auf 60 s, also um 80 %, sofern das Auslagerungsziel nur anhängendes Schreiben zulässt. **Deutung:** Die Lücke ist nicht schließbar, solange der schreibende Knoten die Ereignisse erzeugt; sie ist nur verkleinerbar, und ihre Größe ist eine Entscheidung über die Auslagerungsverzögerung.

Die Eigenschaft "nur anhängendes Schreiben" liegt beim Auslagerungsziel und damit außerhalb von Atrium. Atrium kann sie nicht erzwingen, aber prüfen: eine periodische Zielprüfung versucht, eine bereits geschriebene Abschnittsdatei zu überschreiben und zu löschen, und trägt das Ergebnis als Eigenschaft des Ziels ein. Gelingt der Versuch, ist das Ziel als "nicht schreibgeschützt" gekennzeichnet, und die Konsole benennt, dass der Nachweiswert des ausgelagerten Bestandes davon abhängt.

**Rechnung Aufbewahrungsvolumen:** Nach K-25 bleibt der Auditstrom 12 Monate vollständig. Aus 2.000 Ereignissen je Tag à 1 KB folgen 730 MB Kernvolumen jährlich. Annahme 2 KB Anhang je Ereignis ergibt 1,46 GB Anhangvolumen jährlich; Annahme, dass 5 % der Anhänge der Klasse `gesetzlich` zugeordnet werden, ergibt 73 MB jährlich und bei einer vom Betreiber gesetzten Frist von 10 Jahren 730 MB Gesamtbestand dieser Klasse. **Deutung:** Aufbewahrung ist im Umfang dieser Installation kein Kapazitätsproblem, sondern ein Klassifikationsproblem; der Aufwand liegt darin, dass ein Mensch entscheiden muss, welche Vorgänge der gesetzlichen Klasse zugeordnet werden, und diese Entscheidung ist nicht ableitbar.

Die handels- und steuerrechtlichen Aufbewahrungsvorschriften werden hier nicht zitiert, weil sie nicht im geprüften Standardverzeichnis stehen. Der Entwurf beschreibt die Anforderung statt der Fundstelle: Unveränderbarkeit ab Erfassung, Nachvollziehbarkeit der Verarbeitungsschritte, Vollständigkeit, Verfügbarkeit über die Frist und maschinelle Auswertbarkeit. Zu allen fünf leistet der Auditstrom einen Beitrag für Handlungen an der Plattform; für Geschäftsvorfälle innerhalb einer Fachanwendung ist die Fachanwendung zuständig, und Atrium hat davon keine Kenntnis.

**Anforderungen**

- **R-22-32** — Die Kettenprüfung des gesamten Auditbestands läuft bei jedem Start und bei jedem Export und ist für eine Jahreskette in ≤ 30 s abgeschlossen. Prüfbar: Prüflauf über einen erzeugten Jahresbestand (INV-23).
- **R-22-33** — Jedes Auslagerungsziel trägt das Ergebnis der letzten Zielprüfung auf Überschreibbarkeit mit Datum; ein Ziel ohne Prüfergebnis wird als "Nachweiswert unbestimmt" angezeigt. Prüfbar: Einrichtung eines überschreibbaren Ziels und Kontrolle der Anzeige.
- **R-22-34** — Die Verzögerung der Auslagerung eines versiegelten Abschnitts beträgt im Zielwert ≤ 60 s; die tatsächliche Verzögerung ist mit Beobachtungszeitpunkt sichtbar. Prüfbar: Messung über 24 h gegen den Zielwert (INV-28).
- **R-22-35** — Die Zuordnung eines Vorgangs zur Aufbewahrungsklasse `gesetzlich` ist eine ausdrückliche Handlung mit Auditereignis; eine automatische Zuordnung existiert nicht. Prüfbar: API-Prüfung auf das Fehlen einer Regel-basierten Zuordnung.

## 22.12 Was Automatik nicht leisten kann

Dieser Abschnitt begrenzt die Zusagen der vorangegangenen elf. Er steht nicht aus Bescheidenheit hier, sondern weil jede der folgenden Aussagen eine gegenteilige Erwartung widerlegt, die an ein Produkt mit diesem Funktionsumfang regelmäßig gerichtet wird.

| Erwartung | Was tatsächlich gilt |
|---|---|
| Das Produkt macht den Betrieb rechtskonform | Es erzeugt Nachweise und setzt Voreinstellungen; Rechtskonformität ist ein Zustand der Organisation, nicht der Software |
| Eine Zertifizierung des Produkts zertifiziert den Betrieb | Ein Managementsystem wird für einen Geltungsbereich des Betreibers zertifiziert; ein Produktnachweis ist darin ein Beleg unter vielen |
| Zwecke und Rechtsgrundlagen lassen sich ableiten | Beides sind Bewertungen; aus einem Objektgraphen folgt, welche Daten wohin fließen, nicht warum das zulässig ist |
| Fremdsysteme sind mit abgedeckt | Atrium dokumentiert die Bindung und den Versorgungszustand; die Verarbeitung im Fremdsystem bleibt dessen Verarbeitung (INV-30) |
| Voreinstellungen bleiben wirksam | Jede Abweichung ist zulässig, wenn sie begründet wird; die Begründungspflicht verschiebt Verantwortung, sie verhindert nichts |
| Eine Meldung lässt sich automatisieren | Das Produkt stellt die Fakten in der Frist bereit; die Meldeentscheidung ist eine Bewertung unter Unsicherheit |
| Vollständige Nachweise ergeben sich von selbst | Die Vollständigkeit hängt an der Aufbewahrungsklassifikation, und die setzt ein Mensch (R-22-35) |

Daraus folgt eine Produktregel, die härter ist als jede Einzelanforderung dieses Kapitels: Die Atrium Console behauptet an keiner Stelle Rechtskonformität. Sie zeigt, welche Nachweise vorliegen, wann sie erzeugt wurden, welche fehlen und welche Restaufgabe offen ist. Die verworfene Alternative, eine zusammenfassende Konformitätsanzeige etwa in Form eines Erfüllungsgrades, wäre die wirksamste Art, einen Betreiber in Sicherheit zu wiegen, die der Entwurf nicht herstellen kann.

**Anforderungen**

- **R-22-36** — Kein Oberflächentext, keine Fehlermeldung und kein Bericht enthält eine Zusicherung der Rechtskonformität oder einen aggregierten Erfüllungsgrad; die Trefferzahl in der Begriffsprüfung ist 0. Prüfbar: Musterprüfung aller Oberflächentexte gegen eine Ausschlussliste (INV-16, K-27).
- **R-22-37** — Jede Nachweisübersicht nennt neben den vorliegenden Nachweisen die offenen Restaufgaben des Betreibers aus den Tabellen dieses Kapitels; 0 Übersichten ohne Restaufgabenliste. Prüfbar: Darstellungstest jeder Nachweisübersicht.

## Akzeptanzkriterien

| Anforderung | Kriterium | Verfahren |
|---|---|---|
| R-22-01 | Netzmitschnitt einer Neuinstallation über 24 h enthält 0 Verbindungen zu Herstelleradressen außerhalb von Abbild-, Katalog- und Meldungsbezug | Mitschnitt im Prüfstand, Abgleich gegen die Ausgangs-Positivliste |
| R-22-02 | Import eines Manifests ohne Datenkategorie oder ohne Sitzangabe schlägt fehl | Importtest mit je einem unvollständigen Manifest |
| R-22-03 | Der Bindungsexport eines Mandanten enthält genau die aktiven Bindungen dieses Mandanten, Abweichung 0 | Vergleich Export gegen Sollzustandsabfrage mit Mandantenprädikat |
| R-22-04 | Jedes Feld des Verzeichnisentwurfs trägt "abgeleitet" mit Quellobjekt oder "zu ergänzen" | Schemaprüfung des Exports |
| R-22-05 | Auditexport eines Monats für einen Mandanten in ≤ 10 min erzeugt und ohne Atrium prüfbar | Erzeugung und Prüfung auf einem System ohne Atrium |
| R-22-06 | Betroffenheitsabgrenzung liefert in ≤ 2 h ein Ergebnis mit benannter Unsicherheit | Vorfallübung mit vorgegebenem Muster |
| R-22-07 | Neue Veröffentlichung hat Sichtbarkeit "intern" vorbelegt | Formularprüfung im Bau |
| R-22-08 | Löschung mit injiziertem Konnektorfehler meldet "teilweise" und nennt das ausstehende Zielsystem | Fehlerinjektionstest |
| R-22-09 | Rückspielen eines Wiederherstellungspunkts aktiviert keine gelöschte Person | Wiederherstellungsübung mit Löschvormerkung |
| R-22-10 | Jede Löschbestätigung nennt das Datum der letzten Restpräsenz in Sicherungen | Darstellungstest gegen das späteste Aufbewahrungsende |
| R-22-11 | 0 Datenbestände ohne Aufbewahrungsklasse | Bestandsabgleich im Bau |
| R-22-12 | Auskunftsauszug in ≤ 5 min, alle Zielsysteme genannt, gestörte Bindungen eingeschlossen | Erzeugung bei Testperson mit 4 Zuweisungen und 1 gestörten Bindung |
| R-22-13 | Auszug enthält den Hinweis auf fehlende Fachdaten mit namentlicher Nennung der Systeme | Inhaltsprüfung |
| R-22-14 | Nach Löschung ist der verbleibende Kernbestand je Feldart angezeigt | Abgleich Anzeige gegen Kernschema |
| R-22-15 | Übertragbarkeitsexport wird von einem Fremdwerkzeug ohne Atrium-Kenntnis eingelesen | Einlesetest mit Schemaprüfung |
| R-22-16 | Jeder Nachweisexport nennt Erzeugungs- und Beobachtungszeitpunkt und die Quellobjekte | Schemaprüfung aller Exporte |
| R-22-17 | Jeder Themenbereich der Nachweisübersicht trägt eine Angabe zum nicht produktseitigen Anteil | Darstellungstest |
| R-22-18 | Strukturübersicht stimmt objektweise mit dem Sollzustand überein, Abweichung 0 | Vergleichslauf |
| R-22-19 | 0 Erreichbarkeiten im Export ohne Quellverweis | Schemaprüfung |
| R-22-20 | Fristanzeige je Meldestufe mit Quelle der Vorbelegung und Restzeit vorhanden | Formular- und Zeitablauftest |
| R-22-21 | Exportpaket enthält alle fünf Bestandteile | Schemaprüfung des Pakets |
| R-22-22 | Änderungsversuch an einer erteilten Freigabe wird abgelehnt und erzeugt ein Auditereignis | Änderungsversuch nach Freigabe |
| R-22-23 | Knotenansicht zeigt die Restlaufzeit des Unterstützungszeitraums in Tagen | Darstellungstest gegen die Freigabemetadaten |
| R-22-24 | Einspielversuch einer Freigabe ohne Stückliste oder ohne Transparenzeintrag wird abgelehnt | Einspielversuch mit je einem Mangel |
| R-22-25 | Zwei aufeinanderfolgende Sicherheitsabbilder unterscheiden sich in 0 Funktionsänderungen | Differenzprüfung der Abbilder |
| R-22-26 | Jede Freigabedifferenz mit Sicherheitsbezug hat genau 1 zugehörige Sicherheitsmeldung | Abgleich Differenzen gegen Meldungen |
| R-22-27 | 0 Verstöße in der automatisierten Regelprüfung, alle 38 Standardaufgaben des Katalogs aus 18.2 ohne Zeigegerät durchführbar | Regelprüfung und Tastaturdurchlauf im Bau |
| R-22-28 | Barrierefreiheitserklärung enthält Stand, Verfahren, Ausnahmen, Rückmeldeweg und die Geltungsbegrenzung | Inhaltsprüfung gegen fünf Pflichtbestandteile |
| R-22-29 | Katalogeintrag ohne Barrierefreiheitsangabe wird als "unbekannt" dargestellt | Darstellungstest |
| R-22-30 | 0 Treffer der Begriffe "qualifiziert", "rechtssicher", "rechtsgültig" in Oberflächentexten zur internen CA | Musterprüfung aller Texte |
| R-22-31 | Ausfall des Zeitstempeldienstes erzeugt die Kennzeichnung und eine offene Aufgabe | Ausfalltest |
| R-22-32 | Kettenprüfung einer Jahreskette in ≤ 30 s abgeschlossen | Prüflauf über erzeugten Jahresbestand |
| R-22-33 | Überschreibbares Auslagerungsziel wird als "Nachweiswert unbestimmt" angezeigt | Einrichtung eines überschreibbaren Ziels |
| R-22-34 | Auslagerungsverzögerung über 24 h im Mittel ≤ 60 s, Anzeige mit Beobachtungszeitpunkt | Messung im Prüfstand |
| R-22-35 | Die API kennt keine regelbasierte Zuordnung zur Klasse `gesetzlich` | API-Prüfung |
| R-22-36 | 0 Treffer einer Konformitätsbehauptung oder eines Erfüllungsgrades in allen Oberflächentexten | Musterprüfung gegen Ausschlussliste |
| R-22-37 | Jede Nachweisübersicht enthält eine Restaufgabenliste | Darstellungstest |

## Offene Punkte

1. **Rolle des Projekts nach der Produktsicherheitsverordnung.** Ob und ab welchem Zeitpunkt das Projekt als Hersteller gilt, hängt von der Trägerstruktur, der Entgeltlichkeit der Leistungen und der Auslegung des Begriffs der Geschäftstätigkeit ab. Der Entwurf entscheidet sich vorsorglich für die Herstellerrolle (22.8); die Entscheidung über Trägerschaft und Geschäftsmodell steht aus und ist Voraussetzung für die Festlegung des Konformitätsbewertungsverfahrens.
2. **Länge des Unterstützungszeitraums.** Die Rechnung in 22.8 zeigt einen nahezu linearen Aufwandsanstieg mit der Zahl unterstützter Linien. Ein Wert ist nicht festgelegt, und er ist ohne Aussage über die Finanzierung nicht festlegbar. Zu entscheiden sind Länge, Zahl paralleler Linien und die Frage, ob ältere Linien nur noch Behebungen der Klassen S0 und S1 erhalten.
3. **Nachweisformat für Prüfer.** Die Exporte sind maschinenlesbar, aber es gibt kein etabliertes Austauschformat, das ein Prüfer ohne Werkzeugkenntnis liest. Zu entscheiden ist, ob Atrium ein eigenes, dokumentiertes Prüfpaket definiert, ob es sich an ein bestehendes Berichtsformat anlehnt oder ob nur die Rohdaten mit einem eigenständigen Prüfwerkzeug ausgeliefert werden.
4. **Aufbewahrungsklassifikation als Handarbeit.** R-22-35 verbietet die automatische Zuordnung zur gesetzlichen Aufbewahrungsklasse, weil sie eine Bewertung ist. Damit bleibt eine wiederkehrende Aufgabe ohne Vorbelegung, die der Drei-Entscheidungs-Regel entgegensteht. Zu entscheiden ist, ob eine Richtlinie je Dienst eine Vorbelegung mit Begründungspflicht liefern darf, ohne dass die Klassifikation dadurch faktisch wieder automatisch wird.
5. **Restlücke der Auditkette.** Das Fenster zwischen Ereignis und Siegel bleibt bei Knotenkontrolle ausnutzbar (22.11). Eine Verkürzung auf wenige Sekunden erhöht die Siegellast und die Abhängigkeit vom Auslagerungsziel. Zu entscheiden ist, ob ein zweiter, unabhängiger Schreiber auf einem anderen Verwaltungsknoten eingeführt wird und was das für den Ein-Knoten-Betrieb bedeutet, in dem ein solcher Schreiber nicht existiert.
6. **Datenübertragbarkeit für Fachdaten.** Der Konnektorvertrag kennt keine Exportoperation (22.4). Ob eine sechste Operation eingeführt wird, ist offen; sie würde die Vertragsfläche vergrößern, versionsabhängige Ausleitungen je Fremdsystem erzwingen und dem Grundsatz widersprechen, dass Fremddaten dem Fremdsystem gehören. Ohne sie bleibt eine Lücke bei einem Betroffenenrecht.
7. **Geltung der Barrierefreiheitsanforderung.** Ob die Bedienoberfläche eines Serverbetriebssystems in den Anwendungsbereich der Richtlinie fällt, ist eine Rechtsfrage und hängt von der Betriebsform ab. INV-31 macht die technische Anforderung unabhängig davon verbindlich; offen ist, ob eine förmliche Barrierefreiheitserklärung mit den Rechtsfolgen einer solchen Erklärung abgegeben wird oder eine Selbstauskunft ohne diese Rechtsfolgen.
8. **Zuständigkeit für Meldefristen im Anbietermodus.** Im Anbietermodus kann ein Vorfall gleichzeitig eine Meldepflicht des Betreibers und mehrere Meldepflichten der Mandanten auslösen, mit unterschiedlichen Fristen und Adressaten. Der Entwurf liefert die Betroffenheitsabgrenzung, aber kein Modell dafür, wer wen in welcher Reihenfolge informiert. Zu entscheiden ist, ob Atrium Benachrichtigungswege je Mandant führt und ob deren Auslösung automatisch oder nur als vorgeschlagener Vorgang erfolgt.
