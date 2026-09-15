# Anhang E: Anforderungsmatrix

## E.1 Verfahren

Dieser Anhang führt die vierzig atomaren Forderungen des Auftraggebers (F-01 bis F-40) gegen die Stellen, an denen sie im Dokument behandelt werden. Die Zuordnung ist nicht aus der Erinnerung erstellt, sondern durch Volltextsuche über alle 30 Dateien des Bestandes (`KANON.md`, 23 Kapitel, 6 Anhänge) und durch Lesen der Fundstellen. Jede in Spalte "Anforderungs-IDs" genannte Kennung wurde im zitierten Kapitel nachgeschlagen; Kennungen ohne Fundstelle stehen nicht in der Tabelle.

Als Beleg zählt ausschließlich eine Anforderungs-ID nach KANON 9, also eine prüfbar formulierte Anforderung mit benanntem beobachtbarem Ergebnis. Eine erzählende Erwähnung im Fließtext ist kein Beleg. Wo eine Forderung nur im Fließtext oder nur in einer Kennzahl behandelt wird, steht das in der Begründungsspalte und senkt die Bewertung.

| Stufe | Bedeutung | Bedingung |
|---|---|---|
| **abgedeckt** | Die Forderung ist spezifiziert und mit mindestens einer Anforderungs-ID belegt. | Mindestens eine Anforderungs-ID trifft die Forderung unmittelbar; das Dokument benennt für diese Forderung keine Einschränkung ihrer Geltung. |
| **eingeschränkt abgedeckt** | Die Forderung ist spezifiziert, das Dokument benennt aber ausdrücklich eine Einschränkung. | Anforderungs-IDs vorhanden; zusätzlich existiert im Dokument eine ausdrückliche Aussage darüber, was die Lösung nicht leistet. Jede solche Forderung erhält in E.3 einen eigenen Abschnitt. |
| **nicht abgedeckt** | Die Forderung ist im Dokument nicht gelöst. | Das Dokument erklärt die Forderung in der gestellten Form für nicht erreichbar oder liefert keine Spezifikation. Auch diese Forderung erhält in E.3 einen eigenen Abschnitt. |

Eine Einschränkung ist keine Abwertung des Entwurfs, sondern eine Aussage über seine Grenze. Die Stufe "eingeschränkt abgedeckt" wird vergeben, sobald das Dokument selbst eine Grenze benennt — auch dann, wenn die Forderung im Kernbereich vollständig eingelöst ist. Der umgekehrte Weg, eine benannte Grenze als Sonderfall zu behandeln und die Forderung als glatt abgedeckt zu führen, würde die Matrix wertlos machen.

Die Tabelle "Unter den gegebenen Annahmen nicht erreichbar" in 23.10 führt sechs Ansprüche und entscheidet die Bewertung nur dort, wo sie die Forderung in der gestellten Form verneint. Das trifft auf F-40 zu: 23.10 verneint "Eine Serverarchitektur, die ein Kind bedienen kann" ohne jede Einschränkung, und F-40 ("Bedienbar wie von einem Kind") verlangt der Sache nach dasselbe. Bei F-02, F-10, F-11, F-13, F-16 und F-31 verneint 23.10 dagegen die unbeschränkte Lesart — "Alles, was ein kommerzielles Serverbetriebssystem kann", "Firewall, TLS, Reverse Proxy und DNS verwalten sich selbst" ausdrücklich "ohne Einschränkung", "Betrieb über 32 Knoten hinaus" — und setzt in derselben Zeile unter "Was stattdessen gilt" die eingelöste Teilzusage dagegen. Diese sechs Forderungen tragen deshalb "eingeschränkt abgedeckt", und die betreffende Zeile aus 23.10 ist im zugehörigen Abschnitt von E.3 als Grenze zitiert.

Verweise auf Abschnitte ohne Anforderungs-ID sind als Kapitel- und Abschnittsnummer angegeben, damit die Fundstelle prüfbar bleibt.

## E.2 Matrix

| Forderung | Bewertung | Kapitel | Anforderungs-IDs | Einschränkung oder Begründung |
|---|---|---|---|---|
| **F-01** Quelloffenes Server-Betriebssystem auf Ubuntu-Basis | abgedeckt | 06, 23 | R-06-01, R-06-02, R-06-10, R-06-13, R-06-25 | Abbilder werden ausschließlich aus der festgelegten LTS-Basis und dem Projektarchiv gebaut, ohne abweichenden Kern. Jede Abweichung vom Basisstand trägt einen Eintrag im Abhängigkeitsbudget mit Ablaufdatum. Der Unterstützungszeitraum endet nicht später als der Wartungszeitraum der Basisserie. |
| **F-02** Leistungsumfang eines vollwertigen Servers | eingeschränkt abgedeckt | 04, 13, 23 | R-04-03, R-04-08, R-13-06, R-23-07 | Elf Vergleichsdimensionen sind je mit einem Prüffall belegt, kein Funktionsumfang ist an ein Abonnement gebunden. Domänenbeitritt, Kerberos, Gruppenrichtlinien und Softwareverteilung an Endgeräte fehlen. E.3.1 |
| **F-03** Radikal einfache Bedienung, kein Terminal | abgedeckt | 02, 03, 18 | R-02-11, R-02-12, R-03-10, R-18-24, R-18-45 | INV-26; der vollständige Aufgabenkatalog wird im Bau automatisiert über die Konsole durchlaufen, `atriumctl` wird dabei in 0 Aufgaben benötigt. Keine Meldung nennt einen Konsolenbefehl als einzigen Lösungsweg. |
| **F-04** Aufgeräumte, klare Oberfläche | abgedeckt | 18, 02 | R-18-01, R-18-02, R-18-03, R-18-09, R-18-10 | Genau 8 Navigationsbereiche, höchstens 7 Unterpunkte je Bereich, jede Aufgabe in höchstens 3 Ebenen erreichbar (K-26). Ein Abschnitt "Erweitert" existiert nicht; eine Funktion aus der Negativliste eines Bereichs bricht dort den Bau. |
| **F-05** Nutzer anlegen ist ein einziger Vorgang | abgedeckt | 07, 18, 02 | R-02-03, R-07-13, R-18-06, R-18-07, R-18-08 | Aufgabe 1 des Katalogs kostet 2 Entscheidungen (Anzeigename, Gruppen/Rollen). Alle weiteren Felder sind vorbelegt und nennen genau eine von vier zulässigen Quellen. Fehlt eine Richtlinie, entsteht kein zusätzliches Pflichtfeld. |
| **F-06** Ticketsystem ebenso einfach; Schalter am Nutzer legt in Znuny an | abgedeckt | 07, 09, 15 | R-07-13, R-09-09, R-15-08, R-15-09, R-15-31 | "Dienst hinzufügen" kostet 3 Entscheidungen; die Versorgung einer Person kostet 0 zusätzliche Felder, weil der Schalter selbst die Handlung ist (15.10). Fehlt die Standardrolle, ist der Schalter gesperrt statt um ein Feld erweitert. |
| **F-07** Verwaltung des Ticketsystems bleibt in dessen Oberfläche | abgedeckt | 02, 04, 09, 15 | R-02-02, R-04-07, R-09-09, R-15-02, R-15-31 | INV-30. Atrium schreibt in Fremdprodukten ausschließlich Zugangs- und Rollenobjekte; für produktinterne Feinrechte existiert keine Schreiboperation. Warteschlangen bleiben in Znuny und werden in der Konsole nur lesend gezeigt (R-09-07, R-09-09). |
| **F-08** Festlegbar, wo Znuny läuft: Adresse, interne oder externe Domäne | abgedeckt | 09, 11, 12, 15 | R-09-25, R-11-22, R-12-17, R-15-08 | Die Veröffentlichungsart ist eines der drei Pflichtfelder von "Dienst hinzufügen"; Sichtbarkeit intern oder extern bestimmt Zertifikatsherkunft und Sicht. Der Konnektor schreibt die Grundadresse in das Fremdprodukt, bestimmt sie aber nicht. |
| **F-09** Mindestens 1.000 quelloffene Softwarelösungen eingebunden | eingeschränkt abgedeckt | 03, 09, 23 | R-03-07, R-09-21, R-23-15, R-23-20 | K-22 rechnet die Pflegelast für 1.000 Systeme; der Manifestanteil ist auf ≥ 90 % und der T2-Anteil auf ≤ 6 % festgelegt. Die Zahl 1.000 ist ausdrücklich kein Produktziel und wird nicht als Erfolgskennzahl veröffentlicht. E.3.2 |
| **F-10** Firewall verwaltet sich selbst und bleibt sauber | eingeschränkt abgedeckt | 12 | R-12-35, R-12-36, R-12-37, R-12-38, R-12-40 | Für Firewallregeln existieren 0 Schreiboperationen; jede Regel nennt ihr Quellobjekt, die Erzeugung ist deterministisch und die Anwendung eine Transaktion. Die Selbstverwaltung endet an der Objektgrenze. E.3.3 |
| **F-11** TLS- und Zertifikatsverwaltung automatisch | eingeschränkt abgedeckt | 11, 12 | R-11-13, R-11-16, R-11-17, R-12-53, R-12-55 | Genau drei Ausstellungswege, keine Operation zum Hochladen oder Signieren von Hand; Erneuerung ab zwei Dritteln der Laufzeit übersteht 29 Tage Ausstellungsstillstand ohne Ablauf. Unverwaltete Geräte bleiben außerhalb. E.3.4 |
| **F-12** Softwareverwaltung automatisch | eingeschränkt abgedeckt | 06, 15, 21 | R-06-12, R-15-03, R-15-26, R-21-15, R-21-20 | Nicht abschaltbare Selbstaktualisierung verhindert die Freigabe eines Katalogeintrags; Plattformaktualisierung läuft mit Kanarienknoten und selbsttätigem Rückfall. Fassungswechsel mit Datenmigration bleibt eine Bedienerentscheidung. E.3.5 |
| **F-13** Proxyverwaltung automatisch | eingeschränkt abgedeckt | 12 | R-12-42, R-12-43, R-12-44, R-12-45 | Der Eingang lädt 0 Konfigurationsdateien; die gesamte Konfiguration stammt aus xDS-Momentaufnahmen. 1.000 aufeinanderfolgende Routenänderungen erzeugen 0 Verbindungsabbrüche. Relaisbetrieb ist die benannte Grenze. E.3.6 |
| **F-14** Außerhalb der Oberfläche möglichst nichts anfassen | eingeschränkt abgedeckt | 03, 11, 14, 18 | R-03-10, R-11-06, R-14-15, R-18-45, R-18-46 | Innerhalb der Objektgrenze vollständig eingelöst. Benannte Ausnahmen: Fachkonfiguration in Fremdoberflächen, Zustimmungsablauf im Anbieterportal, Registereintrag bei extern delegierter Domäne, Wurzelschlüsselzeremonie. E.3.7 |
| **F-15** Das System kann selbst Zertifizierungsstelle sein | abgedeckt | 11 | R-11-01, R-11-02, R-11-05, R-11-10, R-11-13 | Dreistufige Kette: Wurzel offline, Ausgabe-CA im TPM 2.0 nicht exportierbar, je Mandant eine Zwischen-CA mit Name Constraints. Eine vierte Ebene ist nicht ausstellbar, der Wurzelschlüssel liegt in keinem Wiederherstellungspunkt. |
| **F-16** Das System kann selbst DNS-Server sein | eingeschränkt abgedeckt | 12 | R-12-11, R-12-13, R-12-24, R-12-29 | Autoritativer Dienst und Resolver laufen als getrennte Prozesse; alle Verwaltungsknoten erzeugen byteweise identische Zoneninstanzen, jede ist signiert. Delegierung und Register liegen außerhalb. E.3.8 |
| **F-17** Nicht nur Nutzer, auch Geräte werden verwaltet | eingeschränkt abgedeckt | 13 | R-13-01, R-13-04, R-13-11, R-13-19, R-13-24 | Gerät ist ein eigenes Objekt mit Eigentümer, Netzzone, Hardwarebindung und Konformitätsmeldung im Istzustand. Softwareverteilung und Arbeitsplatzverwaltung sind ausdrücklich ausgeschlossen. E.3.9 |
| **F-18** Zertifikate werden auf Geräte ausgerollt | eingeschränkt abgedeckt | 11, 13 | R-11-15, R-13-05, R-13-10, R-13-17, R-13-18 | Der private Schlüssel entsteht auf dem Gerät; EST bietet keine serverseitige Schlüsselerzeugung. Der Vertrauensanker gelangt ausschließlich über die Geräteverwaltung in einen Speicher. Verwaltungstiefe fremder Plattformen offen. E.3.10 |
| **F-19** Es existiert internes DNS | abgedeckt | 12 | R-12-01, R-12-11, R-12-12, R-12-21, R-12-24 | Internes `/48` aus `fd00::/8`, nach der Erstinstallation über keine API-Operation änderbar. Jeder Eintrag trägt ein Sichtattribut als Pflichtfeld; eine nicht geltende interne Domäne wird mit signierter verneinender Antwort beschieden. |
| **F-20** DNS-Bedienung: intern oder extern wählen, Domäne und Einträge anlegen | abgedeckt | 12, 18 | R-12-12, R-12-14, R-12-17, R-12-34 | "Domäne hinzufügen" verlangt genau 3 Pflichtentscheidungen (Name, Sichtbarkeit, Geltungsbereich), "Eintrag anlegen" genau 3 (Art, Name, Wert). Ein Handeintrag, der mit einem abgeleiteten kollidiert, wird unter Nennung des Quellobjekts abgelehnt. |
| **F-21** Festlegen, für wen eine Domäne gilt (Gruppe, Nutzer, Gerät) | eingeschränkt abgedeckt | 12, 13 | R-12-18, R-12-19, R-12-20, R-13-30 | Die Antwortpolitik ist eine reine Funktion aus Domäne, Geltungsbereich und Netzzonen und damit reihenfolgeunabhängig. Durchsetzbar ist der Geltungsbereich nur bis zur Netzzone. E.3.11 |
| **F-22** Die Anbindung von Geräten ist einfach | abgedeckt | 13 | R-13-04, R-13-07, R-13-08, R-13-10 | Zwei Entscheidungen (Eigentümer, Geräteklasse). Der Aufnahmecode trägt 50 bit, ist ≤ 24 h gültig und nach 5 Fehlversuchen vernichtet; das aufnehmende Programm prüft die Kette gegen den CA-Fingerabdruck, bevor es das Geheimnis sendet. |
| **F-23** Mail einfach, unabhängig vom Ort des Postfachs | eingeschränkt abgedeckt | 14 | R-14-02, R-14-11, R-14-15, R-14-32 | Fünf Ablageortklassen mit je vollständiger Feldeigentumsdeklaration; jedes Postfach trägt genau einen Ablageort als Verweis auf eine Konnektorbindung. Die Adresse allein genügt bei Anbietern nicht. E.3.12 |
| **F-24** Mail hinzufügen: Domäne wählen, Benutzernamen eingeben, fertig | eingeschränkt abgedeckt | 14 | R-14-04, R-14-05, R-14-06, R-14-16 | Genau 2 Entscheidungen, bei genau einer aktiven Maildomäne 1. MX, SPF, DKIM, DMARC, MTA-STS und TLS-RPT sind abgeleitete Artefakte ohne Schreiboperation. Der Zustimmungsablauf beim Anbieter bleibt. E.3.13 |
| **F-25** Geteilte Postfächer genauso einfach | eingeschränkt abgedeckt | 14 | R-14-12, R-14-13, R-14-14 | 3 Entscheidungen (Anzeigename, Maildomäne, lokaler Teil); die besitzende Gruppe wird abgeleitet. Die Abbildungsgüte je Berechtigungsmerkmal ist deklarationspflichtig, aber nicht erhoben. E.3.14 |
| **F-26** Es gibt Mandanten | abgedeckt | 19 | R-19-01, R-19-02, R-19-03, R-19-05, R-19-06 | Vier Isolationsstufen M0 bis M3, jede mit genau einer zusätzlichen technischen Grenze. Die Konsole zeigt je Mandant die Zahl der bei einem Knotenausfall mitbetroffenen Mandanten; für M3 ist sie 1. Die Kontrollebene bleibt in allen Stufen gemeinsam und wird an der Stufenauswahl benannt (K-28). |
| **F-27** Es gibt Kundenbereiche | abgedeckt | 19 | R-19-31, R-19-32, R-19-33, R-19-34 | Der Kundenbereich liefert 0 Knotennamen, 0 Fehlerzonen und 0 Kapazitätszahlen, zeigt aber den Redundanzzustand der eigenen Dienste. Jede Handlung außerhalb der Selbstbedienungsliste wird ein Vorgang; Aufgabe 28 kostet 2 Entscheidungen. |
| **F-28** Es gibt Freigabe- und Prüfbereiche | abgedeckt | 08, 19 | R-08-16, R-19-24, R-19-25, R-19-27, R-19-29 | Sechs freigabepflichtige Aktionsklassen; kein Vorgang wechselt ohne Entscheidung eines zulässigen Freigebers nach "Freigegeben". Die Freigabe ist an Sollzustandsversion und Vorschauhashwert gebunden und verfällt bei zwischenzeitlicher Änderung. |
| **F-29** Es gibt Audit | abgedeckt | 19, 22 | R-19-35, R-19-38, R-19-40, R-19-41, R-22-05 | Hashverkettete Ereignisse mit acht Pflichtfeldern; ein Kettenbruch wird gemeldet und nicht repariert. Das Prüfprogramm verifiziert einen Export ohne Netzverbindung zur Installation; eine Jahreskette wird in ≤ 30 s geprüft. |
| **F-30** Unterschiedliche Administratoren mit unterschiedlichen Rechten | abgedeckt | 10, 19 | R-10-06, R-19-16, R-19-18, R-19-19, R-19-21 | Rollen mit Geltungsbereich und Pflichtbefristung; keine vordefinierte Rolle darf ein Geheimnis im Klartext lesen. Die Prüferrolle enthält 0 schreibende Aktionsklassen. Hart unvereinbare Kombinationen werden unter Nennung beider Rollen abgelehnt. |
| **F-31** Das System wird auf vielen Geräten ausgerollt | eingeschränkt abgedeckt | 05, 06, 16 | R-05-22, R-06-21, R-16-15, R-16-17 | Unbeaufsichtigte Installation mit schemageprüfter Vorgabedatei; Massenaufnahme über ein Einmaltoken je Knoten mit Quellpräfixbindung. Die Knotenzahl je Installation ist auf 32 begrenzt. E.3.15 |
| **F-32** Auf einem Server wird festgelegt, dass er der verwaltende ist | abgedeckt | 05, 16 | R-05-12, R-05-15, R-16-04 | Der Wartemodus hat zwei Ausgänge: Aufnahme durch eine bestehende Kontrollebene oder Konstituierung als Ankerknoten durch die Ersteinrichtung (5.5, Schritt 4 und 6). Der Ankerknoten trägt ab 3 Stimmknoten kein Sonderrecht mehr. |
| **F-33** Auf einem anderen "auf Verbindung warten"; Schnittstelle und Schlüssel | abgedeckt | 05, 06, 16 | R-05-13, R-06-17, R-16-06, R-16-07 | Bewusste Abweichung von der Forderungsformulierung: der Wartemodus wird nicht gewählt, sondern ist nach R-16-06 der automatische Zustand nach der Installation; die geforderte Auswahl entfällt ersatzlos, ihr Ergebnis nicht. 8403/tcp antwortet ausschließlich dort und ausschließlich im lokalen Segment; der Code wird gleichzeitig über Grafikschnittstelle, serielle Schnittstelle und Fernkonsole ausgegeben. |
| **F-34** Der Schlüssel wird auf dem verwaltenden Server eingegeben | abgedeckt | 16 | R-16-11, R-16-13, R-16-14, R-16-19 | Code und Zweck sind die beiden Entscheidungen der Aufnahme. Die Antwortzeit des Kopplungsendpunkts ist unabhängig vom Grad der Übereinstimmung; vor Abschluss zeigt die Konsole Maschinenkennung und Fingerabdruck der Gegenstelle. Eine Adresseingabe entfällt, solange sich der wartende Knoten im selben Segment ankündigt (16.3.2). |
| **F-35** Frage nach dem Zweck: Redundanz, Verwaltungsserver, Dienstverteilung | abgedeckt | 16 | R-16-19, R-16-20, R-16-21, R-16-22 | Der Rollendialog stellt genau eine Frage mit genau diesen drei Antworten; alles Weitere wird abgeleitet und nennt seine Quelle. Der Vergleich mit einem RAID wird nicht übernommen: die vertragene Ausfallzahl ist das Minimum aus Stimm- und Datenachse und damit ein Rechenergebnis, kein Schalter. |
| **F-36** Übersicht, wo was läuft, mit Konfigurationsmöglichkeit | abgedeckt | 15, 16, 18 | R-15-10, R-16-35, R-16-36, R-16-37, R-18-12 | Die Übersicht wird ausschließlich aus Soll- und Istzustand berechnet und stellt vier Ebenen dar. Eine genannte Platzierung wirkt als harte Nebenbedingung; ein selbsttätiges Ausbalancieren des Bestandes findet nicht statt (R-15-16). |
| **F-37** Oberfläche nie so unübersichtlich wie bei kommerziellen Systemen | eingeschränkt abgedeckt | 02, 18 | R-02-17, R-18-01, R-18-02, R-18-09, R-18-42 | Im durchgerechneten Vergleichsfall sinken 35 Fragen auf 10 Entscheidungen, eine Reduktion um 71,4 % (18.3). Sieben Aufgaben liegen ohne Reserve am Maximum, und für die Erstklick-Trefferquote liegt kein Messwert vor. E.3.16 |
| **F-38** Treiberversorgung sauber aus der Distributionsbasis | eingeschränkt abgedeckt | 06 | R-06-02, R-06-14, R-06-16, R-06-18 | Das Abbild enthält keinen vom Basisstand abweichenden Kern; die Kernvariante folgt der Hardwareerkennung und hat kein Bedienerfeld. Hardware-RAID wird abgelehnt statt stillschweigend überlagert. E.3.17 |
| **F-39** Schnittstellen quelloffener Projekte und Datenbanken eingebunden | abgedeckt | 03, 09 | R-03-07, R-09-01, R-09-07, R-09-26, R-09-27 | Genau ein Konnektorvertrag mit fünf Operationen für alle Bindungen. Jedes Manifest nennt das benutzte Standardprotokoll mit Fundstelle oder begründet den Sonderweg. Der Datenbankkonnektor bindet Werte als Parameter und führt keinen Anweisungstext aus. |
| **F-40** Bedienbar wie von einem Kind | nicht abgedeckt | 18, 23 | R-03-01, R-18-06 | Der Entwurf erklärt den Anspruch unter den gegebenen Annahmen für nicht erreichbar (23.10). An seine Stelle tritt die Begrenzung auf höchstens drei Entscheidungen je Aufgabe mit benannter Quelle jeder Vorbelegung; die Verantwortung für die Entscheidung bleibt beim Bediener. E.3.18 |

## E.3 Forderungen mit Einschränkung

### E.3.1 F-02 — Leistungsumfang eines vollwertigen Servers

**Gefordert.** Das Anspruchsniveau kommerzieller Serverbetriebssysteme und großer Linux-Serverinstallationen.

**Geliefert.** Identität mit OIDC, LDAPv3 (RFC 4511) und SCIM (RFC 7643, RFC 7644), eigene zweistufige PKI, autoritativer DNS mit DNSSEC, Geräteobjekte mit Zertifikatsausrollung und Netzzugang über EAP-TLS (RFC 5216), Anbindung fremder Postfachsysteme, Dienstkatalog mit Platzierung, Mehrknotenbetrieb mit Konsens, Speicher mit drei Datensicherheitsstufen, Mandanten, Rechte, Audit. R-04-03 verlangt für jede der elf Vergleichsdimensionen mindestens einen Prüffall im Aufgabenkatalog, R-04-08 bindet alle zur Erfüllung des Katalogs nötigen Bestandteile an quelloffene Lizenzen, R-23-07 verbietet die Bindung von Funktionsumfang an ein Abonnement.

**Lücke.** Vier Gegenstände fehlen und werden im Dokument namentlich benannt: der Domänenbeitritt von Arbeitsplatzrechnern, Kerberos v5 (RFC 4120) als Kernfunktion, Gruppenrichtlinienobjekte und Anmeldeskripte, sowie die Verteilung von Software an Endgeräte jenseits von Vertrauensanker und Zertifikat (4.6, 13.2, 23.10). Für Dateidienste mit Einmalanmeldung ist das eine Lücke und keine Vereinfachung; das Dokument sagt das wörtlich.

**Grund.** Die Entscheidung für einen Protokollkopf, der OIDC, LDAP, SCIM und EAP-TLS erbringt, ist eine Architekturfestlegung und keine Aufwandsfrage. Ein Domänencontroller-Modell verlangt ein zweites Identitätsprotokoll mit eigener Zeitabhängigkeit, eigener Replikationssemantik und eigener Rechteverwaltung auf dem Endgerät. Dieses zweite Modell wäre in der Konsole nicht ohne Bruch der Drei-Entscheidungs-Regel abbildbar, weil jede Richtlinienklasse eines Arbeitsplatzes eine eigene Entscheidungsfläche mitbringt. Die Softwareverteilung ist aus einem anderen Grund ausgeschlossen: sie ist eine eigene Fehlerdomäne mit eigenem Teststand und eigener Rückrollmechanik (13.2).

### E.3.2 F-09 — Mindestens 1.000 eingebundene quelloffene Lösungen

**Gefordert.** Mindestens 1.000 quelloffene Softwarelösungen sind eingebunden.

**Geliefert.** Ein einziger Konnektorvertrag mit fünf Operationen (R-09-01), ein dreistufiges Modell der Einbindungstiefe und eine vollständig ausgeschriebene Pflegelastrechnung. Die Rechnung setzt Einheitskosten je System und Jahr an: `c_T0 = 0,085`, `c_T1 = 0,475` und `c_T2 = 2,750` Personentage, im Verhältnis 1 : 5,6 : 32,4. Daraus folgt R-09-21 mit dem Zielwert T2 ≤ 6 % bei einem T0-Deckungsgrad von 0,25, R-03-07 mit dem Manifestanteil ≥ 90 % und R-23-15 mit der Messauflage an mindestens 50 Manifesten.

**Was durch Standardprotokolle abgedeckt ist.** Die Stufe T0 umfasst Systeme, die ohne Produktwissen vollständig versorgbar sind, also über SCIM, schreibende Verzeichnisversorgung oder ohne eigenes Kontoobjekt. Das Dokument setzt diesen Anteil als Annahme mit 25 % an, zusammengesetzt aus rund 8 % SCIM-fähigen, rund 12 % schreibend verzeichnisversorgbaren und rund 5 % kontolosen Produkten, mit einer Bandbreite von 15 % bis 35 %. Der Rest — der Regelfall — hat OIDC-Anmeldung, aber produkteigene Rollenvergabe und ist damit T1: ein Manifest je Produkt, kein Quelltext.

**Was eine Katalogaufgabe über Jahre bleibt.** Die 1.000 Einträge selbst. Bei der Verteilung (0,25 / 0,65 / 0,10) ergibt die Rechnung `E = 250 · 0,085 + 650 · 0,475 + 100 · 2,750 = 605,0` Personentage je Jahr, also 3,03 Vollzeitäquivalente, und verfehlt den Zielwert aus K-22 um 18 %. Kapitel 23 rechnet die Aufbaulast getrennt: eine vierstellige Katalogbreite kostet im Aufbau rund 3,0 und im Beharrungszustand rund 1,5 Vollzeitäquivalente. Kapitel 23 fasst die wirtschaftliche Bedingung dort nicht als "viele Konnektoren sind tragbar", sondern als Forderung, dass die Zuwachsrate fallen muss, bevor die absolute Zahl steigt (R-23-15, R-23-20).

**Lücke.** Der Entwurf spezifiziert das Verfahren, mit dem 1.000 Einträge tragbar werden, und liefert keine 1.000 Einträge. Er lehnt zusätzlich ab, die Zahl als Erfolgsmaß zu führen: R-23-20 verbietet die Veröffentlichung der Katalogbreite als Erfolgskennzahl, veröffentlicht wird stattdessen der Anteil der Bindungen im Zustand `aktiv`.

**Grund.** Kapitel 09 begründet den Verzicht auf den Zähler mit dem Anreiz, Konnektoren aufzunehmen, deren Pflege niemand trägt; R-23-20 setzt diese Folgerung um. Zwei Eingangsgrößen der Rechnung sind ungemessen: die Bruchbehebung im Manifest `h_T1` und die Manifesterstellung `a_T1`. Liegt `a_T1` bei 2,5 statt 1,5 Personentagen, ist der Zielwert aus K-22 mit keiner Verteilung erreichbar. Kapitel 09 zieht daraus den Schluss, dass die Schwelle von 6 % bis zur Messung an den ersten fünfzig Manifesten eine Planungsgröße und keine belastbare Obergrenze ist (R-09-21, R-23-15). Auch der Deckungsgrad von 25 % ist eine Annahme mit Anreizbegründung, keine Erhebung; das Dokument verlangt eine Auszählung der Schnittstellendokumentation der ersten zweihundert Katalogeinträge, bevor die Katalogstrategie festgelegt wird.

### E.3.3 F-10 — Die Firewall verwaltet sich selbst

**Gefordert.** Die Firewall verwaltet sich selbst und bleibt sauber.

**Geliefert.** R-12-35 setzt die Zahl der Schreiboperationen für Firewallregeln auf 0 und verlangt an jeder angezeigten Regel die Kennung ihres Quellobjekts. R-12-36 verlangt byteweise identische Regelsätze bei zweifacher Erzeugung aus derselben Sollzustandsversion. R-12-37 macht die Anwendung zu einer Transaktion, nach deren Abbruch der vorherige vollständige Satz aktiv ist und 0 Pakete eine Kette ohne Politik passieren. R-12-38 hält bei fehlendem gültigen Regelsatz alle Schnittstellen außer Loopback abgeschaltet. R-12-40 meldet eine fremde nftables-Tabelle als Abweichung mit Tabellennamen, statt sie stillschweigend zu entfernen. KANON 5 schließt einen Dialog zum Anlegen einer einzelnen Regel aus.

**Lücke.** Die Selbstverwaltung endet an der Objektgrenze. Eine dem System vorgelagerte Anbieter- oder Standortfirewall wird nicht verwaltet; die notwendige Portweiterleitung wird lediglich durch einen Erreichbarkeitsversuch von außen geprüft und mit Quelle und Beobachtungszeitpunkt angezeigt (R-12-49). Auf einem Relaisknoten ist ein Zugriffskreis vom Typ Netzzone für eingehende Verbindungen nicht durchsetzbar und wird als solcher gekennzeichnet (R-12-50).

**Grund.** Ein Gerät außerhalb der eigenen Objektmenge hat kein Objekt im Sollzustand, aus dem eine Regel abgeleitet werden könnte, und keine Schnittstelle, die dem Konnektorvertrag unterliegt. Die Alternative — Atrium bedient die Oberfläche der vorgelagerten Firewall automatisiert — ist dieselbe, die Kapitel 14 für Anbieterportale ablehnt: sie erzeugt ein dauerhaft gespeichertes Geheimnis mit maximalen Rechten und bricht bei jeder Oberflächenänderung. Das Dokument benennt die Grenze und behauptet innerhalb ihrer die vollständige Zusage (23.10).

### E.3.4 F-11 — TLS- und Zertifikatsverwaltung automatisch

**Gefordert.** TLS- und Zertifikatsverwaltung geschieht automatisch.

**Geliefert.** R-11-13 legt genau drei Ausstellungswege fest (ACME nach RFC 8555, EST nach RFC 7030, direkte Ausstellung) und schließt jede Operation zum Hochladen, Erzeugen oder Signieren eines Zertifikats von Hand aus. R-11-16 beginnt die Erneuerung bei zwei Dritteln der Laufzeit mit stündlichem Versuch und setzt den Anteil Zertifikate mit weniger als 7 d Restlaufzeit auf 0. R-11-17 verlangt, dass eine vollständige Ausstellungsunterbrechung von 29 Tagen zu 0 abgelaufenen Dienst- und Knotenzertifikaten führt. R-12-53 prüft CAA-Einträge vor jeder Bestellung, R-12-55 entfernt den Nachweiswert auch im Fehlerfall.

**Lücke.** Der Browser eines unverwalteten Geräts kennt die interne Wurzel-CA nicht. R-11-21 und R-13-17 verbieten ausdrücklich den bequemen Ausweg: die Konsole bietet keinen Download des Wurzelzertifikats mit Einbauanleitung an, der Vertrauensanker gelangt ausschließlich über die Geräteverwaltung in einen Speicher. Für extern sichtbare Namen bleibt die Abhängigkeit von einer öffentlichen Ausgabestelle und deren Ratengrenzen bestehen (R-11-24). 23.10 führt denselben Anspruch in der unbeschränkten Lesart unter den nicht erreichbaren Ansprüchen und stellt ihm die innerhalb der Objektgrenze vollständige Zusage gegenüber.

**Grund.** Es gibt für das unverwaltete Gerät nur drei Möglichkeiten: Installation des Vertrauensankers, ein öffentlich vertrauenswürdiges Zertifikat über ACME für eine extern delegierte Basisdomäne, oder ein einmalig akzeptierter Zertifikatshinweis. Der Entwurf wählt die ersten beiden und lehnt die dritte ab, weil sie den Bediener trainiert, Zertifikatswarnungen wegzuklicken (5.5). Ein Downloadknopf für die Wurzel-CA wäre genau diese dritte Möglichkeit in anderer Verkleidung: er verlagert die Prüfung der Echtheit auf einen Menschen, der sie nicht durchführen kann.

### E.3.5 F-12 — Softwareverwaltung automatisch

**Gefordert.** Softwareverwaltung geschieht automatisch.

**Geliefert.** R-15-03 verweigert die Freigabe eines Katalogeintrags, dessen Produkt eine nicht abschaltbare Selbstaktualisierung besitzt. R-06-12 stellt sicher, dass auf einem produktiven Knoten kein Pfad existiert, der ein Paket der Basis austauscht; R-06-07 entfernt die Paketverwaltungswerkzeuge aus dem Abbild. R-21-20 beginnt jede Ausrollung mit `k = max(1, ⌈n/8⌉)` Kanarienknoten, höchstens 4, keiner davon ein Verwaltungsknoten. R-21-15 startet den Knoten selbsttätig in den vorherigen Abbildplatz zurück, wenn das Gesundheitssignal 10 min nach dem Start ausbleibt, und zwar ohne Verbindung zur Kontrollebene. R-15-26 verhindert den Beginn einer Aktualisierung ohne geprüften Wiederherstellungspunkt jünger als 24 h.

**Lücke.** Drei Stellen bleiben Bedienerentscheidungen. Erstens: ein Fassungswechsel, bei dem `laeuft_beim_start: ja` gilt, verbindet Fassungswechsel und Datenmigration untrennbar; R-15-29 verlangt, dass die Wirkungsvorschau das im Klartext nennt, und R-15-28 setzt `migration.rueckrollbar` auf `nein`, sofern keine Rückmigration gegen eine Laborinstanz durchlaufen wurde. Zweitens: R-15-27 lehnt eine Aktualisierung über mehr als eine Hauptfassung ab und bietet sie als Folge von Einzelschritten an. Drittens: R-15-20 hält einen Dienst nach 10 Fehlstarts innerhalb von 10 Minuten an und startet ihn ohne Bedienerhandlung nicht erneut.

**Grund.** Eine Datenmigration ist nicht idempotent und nicht ohne Datenverlust umkehrbar. Ein automatischer Rückfall nach gelaufener Migration wäre eine Wiederherstellung mit Datenverlust; R-15-28 verlangt deshalb, dass die Konsole die Handlung genau so benennt und die Zeitspanne nennt. Die Alternative, den Fassungswechsel automatisch durchzuführen und den Rückfall zu verschweigen, verletzt INV-08 und INV-18. Die Fehlstartgrenze existiert, weil ein endloser Neustartkreis Ressourcen verbraucht und die Ursache verdeckt.

### E.3.6 F-13 — Proxyverwaltung automatisch

**Gefordert.** Proxyverwaltung geschieht automatisch.

**Geliefert.** R-12-42 setzt die Zahl der vom Eingang geladenen Konfigurationsdateien auf 0; die gesamte Konfiguration stammt aus xDS-Momentaufnahmen mit Versionskennung. R-12-43 verlangt 0 Verbindungsabbrüche bei 1.000 aufeinanderfolgenden Routenänderungen. R-12-44 setzt die Zahl der Filterketten für einen Namen ohne gültiges Zertifikat auf 0. R-12-45 hält privaten Schlüssel und Zertifikat des Eingangs aus jeder Datei auf einem Datenträger heraus. R-15-19 setzt Route und Namenseintrag erst nach bestandener Bereitschaftsprobe. KANON 5 schließt eine Proxykonfiguration als Bedienfläche aus.

**Lücke.** Bei Betrieb über ein Relais liegt kein privates Schlüsselmaterial veröffentlichter Namen auf dem Relaisknoten, und ein Zugriffskreis vom Typ Netzzone ist für über das Relais eingehende Verbindungen nicht durchsetzbar; R-12-50 verlangt, dass die Konsole das als solches ausweist. Dienste auf Servern ohne Atrium sind nicht veröffentlichbar, weil eine Veröffentlichung einen Dienstverweis als Pflichtfeld trägt (13, Offener Punkt 2). 23.10 führt denselben Anspruch in der unbeschränkten Lesart unter den nicht erreichbaren Ansprüchen und stellt ihm die innerhalb der Objektgrenze vollständige Zusage gegenüber.

**Grund.** Die Quelladresse einer über ein Relais eingehenden Verbindung ist die des Relais und nicht die des Ursprungs; ein Zugriffskreis, der auf Netzzonen beruht, hat damit kein auswertbares Merkmal. Die Alternative, weitergereichte Adressangaben des Relais zu vertrauen, wäre eine Zugriffsentscheidung aufgrund einer vom Angreifer setzbaren Kopfzeile. Die Nichtveröffentlichbarkeit fremder Dienste folgt aus dem Objektmodell: Veröffentlichung, Dienst und Katalogeintrag sind drei getrennte Objekte mit Pflichtverweisen, und ein Fremdserver liefert keinen Katalogeintrag.

### E.3.7 F-14 — Außerhalb der Oberfläche muss nichts angefasst werden

**Gefordert.** Außerhalb der Oberfläche muss möglichst nichts angefasst werden.

**Geliefert.** R-18-45 verlangt den automatisierten Durchlauf des vollständigen Aufgabenkatalogs über die Konsole mit `atriumctl` in 0 Aufgaben. R-18-46 und R-05-25 binden die Konsole an die öffentlich dokumentierte API und testen sie gegen eine Fassade, die nicht dokumentierte Endpunkte sperrt. R-03-09 und R-18-24 verbieten Meldungen, deren einziger Lösungsweg ein Konsolenbefehl oder ein Dateipfad ist. R-04-07 schaltet die Verwaltungsoberflächen der Kernkomponenten ab.

**Lücke.** Fünf Handlungen bleiben außerhalb der Konsole, jede davon benannt:

| Handlung | Ort | Fundstelle |
|---|---|---|
| Fachkonfiguration eines Katalogprodukts (Warteschlangen, Eskalationszeiten) | Fremdoberfläche | INV-30, R-15-31, R-02-02 |
| Zustimmungsablauf je Mandant und Anbieter | Anbieterportal | R-14-15 |
| Nachweiseintrag bei extern delegierter Domäne | Fremd-DNS oder Register | R-14-16, R-04-10 |
| Zeremonie zur Erzeugung des Wurzelschlüssels und Verwahrung der Anteile | getrennter Raum, physische Träger | R-11-06, R-11-07, R-11-08 |
| Ablesen des Kopplungscodes vor der ersten Verbindung | physische Konsole, serielle Schnittstelle oder Fernkonsole | R-06-17 |

**Grund.** Die erste Handlung folgt aus INV-30: würde Atrium Warteschlangen und Eskalationszeiten modellieren, baute es die Fachlogik eines Ticketsystems nach. Die zweite ist eine Willenserklärung im fremden System und per Konstruktion nicht durch einen Aufruf ersetzbar, den Atrium allein ausführen könnte (14.3). Die dritte belegt Kontrolle über einen Namensraum, den Atrium nicht führt; kein Zugangsdatum kann diesen Nachweis ersetzen. Die vierte ist die Bedingung dafür, dass der Wurzelschlüssel nach Abschluss der Ersteinrichtung in keinem laufenden System und in keiner Sicherung existiert (R-05-14). Die fünfte bricht den Vertrauenskreis von außen auf: vor der ersten Kopplung gibt es keine vertrauenswürdige PKI, über die der Code zugestellt werden könnte.

### E.3.8 F-16 — Das System kann selbst DNS-Server sein

**Gefordert.** Das System kann selbst DNS-Server sein.

**Geliefert.** R-12-11 trennt autoritativen Dienst und Resolver in Prozesse auf getrennten Adressen und setzt die externe Erreichbarkeit des Resolvers auf 0. R-12-13 verlangt byteweise identische Zoneninstanzen aus derselben Sollzustandsversion auf allen Verwaltungsknoten. R-12-24 setzt die Zahl unsignierter Zoneninstanzen auf 0, auch intern. R-12-26 verhindert, dass ein privater Zonensignaturschlüssel den erzeugenden Knoten verlässt. R-12-27 hält die Signaturerneuerung im eingefrorenen Zustand aufrecht, sodass nach 14 Tagen ohne Quorum jede Zone weiterhin validiert.

**Lücke.** Die Delegierung selbst liegt außerhalb. R-12-29 entfernt den alten KSK erst, nachdem jeder autoritative Server des Elternbereichs die neue Delegationssignatur geliefert hat — das Setzen dieser Signatur ist eine Handlung beim Register. Bleibt ein fremder Anbieter autoritativ, läuft die Pflege über dynamische Aktualisierung nach RFC 2136 mit Transaktionssignatur nach RFC 8945 oder über eine Konnektorbindung, und R-12-34 verlangt vor dem Speichern einer Weiterleitung die Anzeige, an welches Ziel das vollständige Abfrageprofil der Zone übertragen wird. 23.10 führt denselben Anspruch in der unbeschränkten Lesart unter den nicht erreichbaren Ansprüchen und stellt ihm die innerhalb der Objektgrenze vollständige Zusage gegenüber.

**Grund.** Die Delegierung ist ein Eintrag im Elternbereich. Wer den Elternbereich nicht führt, kann ihn nicht schreiben; das ist eine Eigenschaft des Namenssystems und keine Entwurfsschwäche. Das Dokument zieht daraus die Konsequenz, den Weg "Atrium ist selbst autoritativ" als Vorzugsweg zu führen, weil er ohne Anbieterzugangsdaten auskommt und nur Delegierung und Delegationssignatur benötigt (12.10). Der zweite Weg bleibt bestehen, weil eine Organisation ihre Domäne aus nichttechnischen Gründen beim Anbieter halten kann.

### E.3.9 F-17 — Nicht nur Nutzer, auch Geräte werden verwaltet

**Gefordert.** Nicht nur Nutzer, auch Geräte werden verwaltet.

**Geliefert.** Das Gerät ist ein eigenständiges Objekt: R-13-01 trennt es von der Person, die Verbindung ist das Feld `eigentuemer`, und die Löschung einer Person löscht 0 Geräteobjekte. R-13-04 macht die Aufnahme zu zwei Entscheidungen. R-13-11 führt die Hardwarebindung mit genau drei Werten. R-13-13 leitet die Netzzone deterministisch aus Gerätezustand, Richtlinie und Geltungsbereichsgruppen ab und lässt bei Gleichstand die restriktivere Zone gewinnen. R-13-19 hält Konformitätsmeldungen im Istzustand mit Beobachtungszeitpunkt, R-13-20 lässt "unbekannt" 0 Richtlinien erfüllen.

**Lücke.** Der Umfang der Verwaltung ist auf vier Gegenstände begrenzt: Gerätezertifikat, Netzzugang, Vertrauensanker und Konformitätsmeldung. Softwareverteilung an Endgeräte jenseits dieser Gegenstände ist ausgeschlossen (KANON 5, 13.2). R-13-24 verbietet zusätzlich Felder für Standort, aufgerufene Namen, Dateinamen, Dateiinhalte, Bildschirminhalte und Tastatureingaben, und kein Richtlinienwert schaltet eine solche Erhebung frei. R-13-23 verlangt, dass Konformitätsergebnisse als "gemeldeter Zustand" und nicht als "geprüfter Zustand" bezeichnet werden.

**Grund.** Die Softwareverteilung ist ausgeschlossen, weil sie eine eigene Fehlerdomäne mit eigener Rückrollmechanik ist und die Drei-Entscheidungs-Zusage für jedes Verteilungsziel neu verhandeln würde (13.2). Die Erhebungsgrenze in R-13-24 ist eine bewusste Beschränkung der Verwaltungstiefe zugunsten der Datensparsamkeit. Die Bezeichnung "gemeldeter Zustand" folgt aus einer strukturellen Einsicht, die das Dokument nicht auflöst: ein kompromittiertes Gerät meldet sich als konform (13.7).

### E.3.10 F-18 — Zertifikate werden auf Geräte ausgerollt

**Gefordert.** Zertifikate werden auf Geräte ausgerollt.

**Geliefert.** R-13-05 lässt den privaten Schlüssel auf dem Gerät entstehen und schließt jede API-Operation aus, die einen privaten Geräteschlüssel entgegennimmt, ausgibt oder erzeugt. R-11-15 verbietet EST die serverseitige Schlüsselerzeugung und begrenzt das Registriergeheimnis auf einmalig, ≤ 24 h, 5 Fehlversuche. R-13-10 legt den Fingerabdruck der Ausgabe-CA in Aufnahmecode und Aufnahmelink und verlangt die Kettenprüfung, bevor das Aufnahmegeheimnis gesendet wird. R-11-20 setzt die Wirksamkeit einer Gerätesperrung an eigenen Prüfstellen auf p95 ≤ 10 s. K-13 setzt die Gerätezertifikatslaufzeit auf 365 Tage mit Erneuerung ab 120 Tagen Restlaufzeit, weil Geräte annahmegemäß zeitweise offline sind.

**Lücke.** Für Windows, macOS und Mobilgeräte benutzt Atrium den plattformüblichen Verwaltungsweg des Betriebssystems und liefert dort keinen eigenen Agenten aus (R-13-06). Welche Merkmale dieser Weg tatsächlich liefert, bestimmt der jeweilige Hersteller; der Inhalt der geforderten Matrix "Klasse × Merkmal" ist zum Entwurfszeitpunkt nicht bekannt (13, Offener Punkt 1). Für den Vertrauensanker meldet der Agent je Gerät die Zahl gefundener und die Zahl gepflegter Vertrauensspeicher, und die Konsole zeigt beide Zahlen statt eines binären Zustands (R-13-18).

**Grund.** Ein eigener Agent auf Windows, macOS und Mobilplattformen wäre ein zweites Produkt mit eigener Signaturkette, eigener Aktualisierungsmechanik und eigener Angriffsfläche auf jedem Endgerät. Die Entscheidung gegen ihn ist die Entscheidung für eine Verwaltungstiefe, die der Plattformhersteller festlegt und einseitig ändern kann. Die zweiteilige Anzeige bei Vertrauensspeichern ist die ehrliche Form derselben Einsicht: Anwendungen mit eigenem Speicher — verbreitet bei Browsern und Laufzeitumgebungen — werden vom plattformüblichen Weg nicht erreicht, und eine Meldung "Vertrauensanker installiert" wäre in diesem Fall falsch.

### E.3.11 F-21 — Festlegen, für wen eine Domäne gilt

**Gefordert.** Einfach festlegen, für wen eine Domäne gilt (Gruppe, Nutzer, Gerät); der Rest geschieht von selbst.

**Geliefert.** R-12-18 macht die Antwortpolitik zu einer reinen Funktion aus Domäne, Geltungsbereich und Netzzonen; 1.000 Permutationen derselben Eingabemenge liefern dieselbe Politik. R-12-22 wendet die Politik atomar auf alle Resolverinstanzen an. R-12-21 beschiedet eine für eine Zone nicht geltende interne Domäne mit einer signierten verneinenden Antwort statt mit einem Zeitablauf. R-12-23 liefert für jede Kombination aus Gerät und Name genau eine benannte Ursache, wenn die Auflösung scheitert.

**Lücke.** Durchsetzbar ist der Geltungsbereich nur bis zur Netzzone. R-12-19 verlangt für einen Geltungsbereich, der Geräte in einer nicht durchsetzbaren Zone enthält, die Anzeige "für diese Geräte nicht durchsetzbar" mit vollständiger Geräteliste. R-12-20 verbietet der Konsole, eine Geltung für eine Person unabhängig vom Gerät zu behaupten: ein Geltungsbereich vom Typ Person wird ausschließlich als Geltung für deren Geräte beschrieben. R-13-30 verlangt bei Geräte- oder Gruppengeltung die Nennung zweier Zahlen — für wie viele Geräte der Bereich genau durchsetzbar ist und für wie viele er über die Netzzone hinaus sichtbar bleibt.

**Grund.** Ein Resolver entscheidet anhand der Quelladresse der Anfrage. Die Quelladresse identifiziert eine Netzzone, nicht eine Person und nicht zuverlässig ein einzelnes Gerät. Eine feinere Durchsetzung setzt verschlüsselte Namensauflösung mit Gerätezertifikat auf allen betroffenen Geräten voraus (13.11); solange ein einziges Gerät in der Zone diese Voraussetzung nicht erfüllt, entsteht eine Überreichweite. Der Entwurf zieht daraus nicht den Schluss, die Funktion zu streichen, sondern den, die Überreichweite zu beziffern und anzuzeigen. Ob die Konsole einen Geltungsbereich feiner als die Netzzone überhaupt anbieten soll, ist als offener Punkt geführt (13, Offener Punkt 3).

### E.3.12 F-23 — Mail einfach, unabhängig vom Ort des Postfachs

**Gefordert.** Mail ist einfach, unabhängig davon, wo das Postfach liegt (Exchange, Microsoft 365 und dessen Verwaltungsportal, anderes); Anbindung über die Adresse.

**Geliefert.** Kapitel 14 führt fünf Ablageortklassen mit je eigener Authentisierung, eigenem Feldeigentum und eigener Beobachtungsfähigkeit. R-14-02 verlangt für jedes Postfach genau einen Ablageort als Verweis auf eine Konnektorbindung; ein Postfach ohne Ablageort ist nicht anlegbar. R-14-11 lehnt jedes Mailkonnektormanifest ohne vollständige Eigentumsdeklaration je Feld ab. R-14-32 verbietet für fremdgehostete Postfächer die Anzeige berechneter Zustellraten, Warteschlangenwerte und Reputationspunktwerte und lässt nur drei herleitbare Größen je Maildomäne zu, jeweils mit Beobachtungszeitpunkt.

**Lücke — die Adresse genügt nicht.** Abschnitt 14.3 rechnet das Vorhaben in zehn Einzelfälle auf und stellt fest, dass die Trennlinie nicht zwischen eigenem Netz und Internet verläuft, sondern zwischen Zugriff auf Daten und Änderung der Verwaltungswirklichkeit eines fremden Mandanten. Zustellung an eine fremde Domäne, Verzeichnislesen im eigenen Netz, Postfachanlage auf einem Exchange im eigenen Netz und der Empfang von DMARC- und TLS-Berichten gelingen mit Adresse und Zugangsdaten. Postfachanlage bei Microsoft 365 oder Google Workspace, das Hinzufügen einer Domäne beim Anbieter und die Zuweisung einer Lizenz gelingen damit nicht. Die Folge ist ein zusätzlicher Einrichtungsschritt, der genau einmal je Mandant und Anbieter anfällt: **Verbindung freischalten** (R-14-15).

**Grund.** Kapitel 14 nennt drei Gründe, jeder für sich hinreichend. Erstens ist die Zustimmung eines Mandanten dort als Willenserklärung im fremden System und nicht als technischer Handschlag eingeordnet; sie ist per Konstruktion nicht durch einen Aufruf ersetzbar, den Atrium allein ausführen könnte (R-14-15). Zweitens hat die naheliegende Umgehung — ein hinterlegtes Administratorkennwort und automatisierte Bedienung der Fremdoberfläche — drei disqualifizierende Eigenschaften: sie erzeugt ein dauerhaft gespeichertes Geheimnis mit maximalen Rechten statt eines zweckbegrenzten Tokens, sie bricht bei jeder Oberflächenänderung des Anbieters, und sie ist keine vom Anbieter vorgesehene Schnittstelle. R-14-15 verbietet sie ausdrücklich. Drittens ändert eine mandantenfähige Anwendungsregistrierung auf Herstellerseite nur die Gestalt des Schritts, nicht seine Existenz. Der Nachweis der Namenskontrolle über einen DNS-Eintrag belegt Kontrolle über den Namensraum und nicht Kenntnis eines Geheimnisses; kein Zugangsdatum kann ihn ersetzen.

**Was der Entwurf stattdessen tut.** Er beziffert den Restaufwand und senkt den größeren Posten. Rechnung: 20 Mandanten zu je einem Anbieter und 5 min Zustimmungsablauf ergeben einmalig 100 min. Bei 3 Domänen je Mandant und einem Nachweiseintrag je Domäne fallen bei fremder Delegierung 20 × 3 × 5 min = 300 min an; ist Atrium für die Domäne autoritativ, erzeugt es den Nachweiseintrag selbst und wiederholt die Anbieterprüfung selbsttätig, und der Bedienaufwand beträgt 0 min (R-14-16). Die Wartezeit auf die Anbieterprüfung bleibt der unkontrollierbare Anteil.

### E.3.13 F-24 — Mail hinzufügen in einem Zug

**Gefordert.** Bei einem Nutzer klicken, Mail hinzufügen, Domäne suchen und wählen, Benutzernamen eingeben, hinzufügen — und es funktioniert.

**Geliefert.** R-14-04 setzt den Ablauf auf genau 2 Entscheidungen (Maildomäne, lokaler Teil) und auf 1 Entscheidung, wenn genau eine aktive Maildomäne des Mandanten existiert; jedes weitere Feld ist vorbelegt und nennt seine Quelle. R-14-03 schließt einen Navigationsbereich für Mail aus: Adressen werden ausschließlich an einer Person oder an einer Gruppe hinzugefügt. R-14-17 macht MX, SPF, DKIM, DMARC, MTA-STS, TLS-RPT und TLSA zu abgeleiteten Artefakten, für die die API keine Schreiboperation kennt. R-14-07 hält `POST /v1/mailadressen:pruefen` frei von Wirkungen in Atrium und in Fremdsystemen.

**Lücke.** Zwei Bedingungen stehen vor dem "und es funktioniert". Erstens setzt der Ablauf die freigeschaltete Verbindung aus E.3.12 voraus; ohne sie existiert keine Konnektorbindung, auf die der Ablageort verweisen könnte. Zweitens sperrt R-14-05 die Handlung an einer Maildomäne, für die keine Richtlinie `lizenzprofil_mail` existiert — und zwar ohne das Formular um ein Pflichtfeld zu erweitern. Wo eine Lizenzzuweisung kostenwirksam ist, nennt R-14-06 sie in der Wirkungsvorschau mit dem Namen des Lizenzprofils und der Angabe der Kostenwirkung, aber ohne Preis.

**Grund.** Die Sperre statt des zusätzlichen Feldes ist eine bewusste Entscheidung gegen die übliche Auflösung. Ein zusätzliches Pflichtfeld hätte INV-14 verletzt und die Entscheidung über ein Lizenzprofil an die falsche Stelle verlegt: sie gehört zur Maildomäne und nicht zur einzelnen Person. Der Preis wird nicht genannt, weil Atrium keine Vertragsbeziehung zum Anbieter hat und ein angezeigter Preis eine Behauptung über einen fremden Vertrag wäre.

### E.3.14 F-25 — Geteilte Postfächer genauso einfach

**Gefordert.** Geteilte Postfächer sind genauso einfach.

**Geliefert.** R-14-14 setzt "Geteiltes Postfach anlegen" auf genau 3 Entscheidungen (Anzeigename, Maildomäne, lokaler Teil) und leitet die besitzende Gruppe ab; die Pflege der Berechtigten ist eine eigene Handlung und erscheint nach Abschluss als offene Aufgabe. R-14-12 verlangt je Berechtigungsmerkmal — `lesen`, `senden_als`, `senden_im_auftrag`, Ordnerrechte, geteiltes Postfach, Verteiler — einen der drei Werte `vollstaendig`, `verlustbehaftet`, `nicht_abbildbar`. R-14-13 lässt eine verlustbehaftete Abbildung als eigene Zeile mit Klartextfolge in der Wirkungsvorschau erscheinen und lehnt eine nicht abbildbare Berechtigung ab, statt sie auszuführen.

**Lücke.** "Genauso einfach" ist nicht eingelöst: 3 Entscheidungen statt 2, und die Berechtigtenpflege ist ein zweiter Vorgang. Schwerer wiegt, dass die tatsächliche Semantik geteilter Postfächer je Anbieter nicht erhoben ist. Die Abbildungstabelle legt fest, wie eine Abweichung zu deklarieren und anzuzeigen ist, nicht welche Abweichungen bestehen. Insbesondere ist offen, ob `senden_im_auftrag` bei Google Workspace und bei Exchange vor Ort dieselbe Kopfzeilenwirkung hat; wenn nicht, sendet dieselbe Zuweisung je nach Ablageort unterschiedlich sichtbare Nachrichten (14, Offener Punkt 2).

**Grund.** Die drei Entscheidungen sind nicht weiter reduzierbar: der Anzeigename eines geteilten Postfachs trägt Information, die im System nicht existiert, und Domäne und lokaler Teil sind dieselben beiden Entscheidungen wie bei einer persönlichen Adresse. Die Trennung der Berechtigtenpflege folgt aus INV-14: eine Berechtigtenliste ist eine Menge und keine Entscheidung; sie in dasselbe Formular zu ziehen, hätte die Aufgabe über das Budget gehoben. Die fehlende Erhebung ist eine Aussage über den Entwurfsstand: sie betrifft beobachtbares Verhalten und kann nicht aus der Dokumentation der Anbieter allein erfolgen, sondern verlangt Messung an Testmandanten.

### E.3.15 F-31 — Ausrollung auf viele Geräte

**Gefordert.** Das System wird auf vielen Geräten ausgerollt.

**Geliefert.** R-06-21 prüft die Vorgabedatei der unbeaufsichtigten Installation gegen ein Schema und bricht bei einem unbekannten Feld mit benanntem Grund ab, statt es zu ignorieren — die Begründung nennt ausdrücklich den Massenfall, in dem eine ignorierte Vorgabe unbemerkt hunderte Knoten falsch konfiguriert. R-06-22 lässt das Medium seine eigene Signatur prüfen, bevor es den Zieldatenträger beschreibt. R-16-15 macht das Aufnahmetoken einmalig verwendbar, bindet es an mindestens ein Quellpräfix und lässt es die Fingerabdrücke von Wurzel- und Ausgabe-CA tragen. R-16-17 verweigert einem über Token aufgenommenen Knoten das Stimmrecht ohne eigene, protokollierte Entscheidung eines Menschen.

**Lücke.** Die Knotenzahl je Installation ist begrenzt. R-05-22 lehnt die Aufnahme über die deklarierte Höchstzahl hinaus mit benannter Begründung ab. K-21 setzt den Skalenbereich auf 32 Knoten × 500 Dienstinstanzen und benennt die Grenze ausdrücklich als gesetzt und nicht gemessen. Kapitel 23 führt "Betrieb über 32 Knoten hinaus" unter den nicht erreichbaren Ansprüchen; der vorgesehene Weg darüber hinaus ist eine getrennte Installation.

**Grund.** Drei Größen hängen an der Grenze. Erstens die Platzierung: 32 × 500 = 16.000 Bewertungspaare sind vollständig im Speicher durchrechenbar und halten den Zielwert p95 ≤ 50 ms ein. Zweitens das Overlay: die Vollvermaschung wächst quadratisch, und R-12-10 hält die installationsweite Keepalive-Last bei 32 Knoten unter 50 kbit/s. Drittens die Verfügbarkeitsrechnung in K-04, die auf 1, 3 oder 5 Stimmknoten mit Mitlesern beruht. Eine höhere Grenze verlangt eine andere Overlay-Topologie und eine andere Platzierungsstrategie; der Entwurf setzt die Grenze, statt sie zu überschreiten und die Zusagen unbelegt zu lassen. Wenn F-31 dagegen die Ausrollung von Zertifikaten und Konformitätsvorgaben auf Endgeräte meint, gilt F-17 und F-18 mit ihren dort benannten Grenzen.

### E.3.16 F-37 — Die Oberfläche wird nie unübersichtlich

**Gefordert.** Die Oberfläche wird nie so unübersichtlich wie bei kommerziellen Serverbetriebssystemen; man bleibt nicht mit dreißig offenen Fragen zurück, auch nicht bei einem einzigen Server.

**Geliefert.** Abschnitt 18.3 rechnet den Vergleichsfall durch: von 35 Fragen bleiben 2 als Ersteinrichtungsentscheidungen, 2 entfallen ersatzlos, 18 werden abgeleitet, 6 durch Richtlinie vorbelegt, 2 verschoben und 7 zusammengelegt. Zusammen mit den beiden übrigen Ersteinrichtungsentscheidungen aus K-01 und den Entscheidungen der beiden beteiligten Standardaufgaben ergeben sich 4 + 6 = 10 Entscheidungen gegenüber 35 Fragen, also eine Reduktion um 1 − 10/35 = 71,4 %. R-18-01 und R-18-02 begrenzen die Struktur, R-18-09 verbietet den Ausweichabschnitt "Erweitert", R-02-17 bindet die Klickpfadtiefe. Der Aufgabenkatalog in 18.2 weist über 38 Aufgaben 72 Entscheidungen aus, im Mittel 1,89 je Aufgabe.

**Lücke.** Drei Punkte. Erstens liegen sieben Aufgaben (12, 13, 18, 19, 22, 29, 36) ohne jede Reserve am Maximum von drei Entscheidungen; jede fachlich begründete Erweiterung erzwingt eine Neuaufteilung der Aufgabe oder eine neue Richtlinie (18, Offener Punkt 1). Zweitens ist der Wechsel zwischen Atrium Console und Fremdoberfläche als Grenze deklariert, aber nicht als Bedienerlebnis gelöst: der Bediener wechselt mitten in einer fachlichen Aufgabe in eine Oberfläche mit anderem Vokabular, anderer Navigationstiefe und anderer Barrierefreiheitsgüte (18, Offener Punkt 7). Drittens liegt für die Erstklick-Trefferquote aus K-26 kein Messwert vor; R-18-42 verbietet deshalb eine Quotenaussage bei Stichproben unter 50 Teilnehmern, und bei 38 Aufgaben wären das 1.900 Aufgabendurchläufe.

**Grund.** Die Enge an sieben Stellen ist gewollt und wird trotzdem als Schwäche geführt: der Druck, eine wachsende Aufgabe neu zu schneiden statt ein Feld anzuhängen, ist der Wirkmechanismus von INV-14 — dass er an sieben Stellen sofort besteht, ist eine Enge, die im Betrieb spürbar wird. Der Oberflächenwechsel folgt aus INV-30 und ist der Preis dafür, die Fachlogik von Fremdprodukten nicht nachzubauen. Der fehlende Messwert folgt aus der Datensparsamkeitsentscheidung: die Datenquelle für eine flächige Erhebung der Bedienzusage ist einwilligungspflichtig und voreingestellt abgeschaltet (R-21-06, R-21-08), weshalb die zentrale Zusage des Produkts im Feld nicht flächig belegbar ist, sondern nur an Support- und Partnerinstallationen (23.9).

### E.3.17 F-38 — Treiberversorgung aus der Distributionsbasis

**Gefordert.** Treiberversorgung ist sauber, weil sie von der Distributionsbasis kommt.

**Geliefert.** R-06-02 setzt die Zahl der vom Basisstand abweichenden Kerne auf 0 und prüft das bei jeder Freigabe durch Vergleich der Kernpaketprüfsumme. R-06-25 setzt die Zahl abweichender Pakete auf 0 oder verlangt je Abweichung einen Eintrag im Abhängigkeitsbudget mit Ablaufdatum und Rückführungsweg. R-06-14 leitet die Kernvariante aus der Hardwareerkennung ab, zeigt die laufende Variante mit Grund an und verbietet ein Bedienerfeld dafür. R-06-18 führt je Knoten eine Hardwarestufe und stellt die Zeitzusage aus K-01 nur für die Stufe "geprüft" dar.

**Lücke.** Zwei Hardwareklassen werden abgelehnt statt unterstützt. R-06-16 bricht die Installation bei fehlendem Speichertreiber und bei einem Controller im RAID-Modus mit benanntem Grund ab. R-06-09 verweigert einem Knoten ohne TPM 2.0 und ohne Hardwaretoken die Rolle des Ausgabe-CA-Trägers und kennzeichnet ihn dauerhaft mit den entfallenen Zusicherungen. Das schließt einen erheblichen Teil vorhandener Serverhardware aus, deren Controller keinen reinen Durchreichemodus kennt (6, Offener Punkt 6).

**Grund.** ZFS braucht direkten Blockzugriff für Prüfsummen und Selbstheilung; ein Hardware-RAID-Controller verdeckt ihn. Ein Betrieb darauf wäre möglich, aber ohne die Prüfsummen- und Selbstheilungszusage, auf der die Speicherkapitel aufbauen. Der Entwurf zieht die Ablehnung dem stillschweigenden Verlust einer Zusage vor und führt als offenen Punkt, ob ein gekennzeichneter Betrieb ohne diese Zusage angeboten wird. Der Abbruch bei fehlendem Speichertreiber ist keine Entscheidung, sondern eine Folge: ohne Treiber gibt es kein Wurzeldateisystem, und der Abbruch fällt vor jede Möglichkeit, eine Meldung anzuzeigen — deshalb prüft das Medium vor dem ersten Schreibvorgang.

### E.3.18 F-40 — Bedienbar wie von einem Kind

**Gefordert.** Die Architektur ist so einfach bedienbar, dass sie ein Kind bedienen könnte.

**Geliefert.** Die Drei-Entscheidungs-Regel (INV-14, K-03) mit maschinenlesbaren Aufgabendefinitionen und Bauprüfung; R-03-01 zählt im Bau höchstens drei Pflichtfelder ohne mögliche Vorbelegung je Aufgabe, R-18-06 bindet alle 38 Aufgaben des Katalogs an diese Grenze und bricht bei einem zusätzlichen Pflichtfeld den Bau. R-18-07 verlangt für jedes vorbelegte Feld genau eine von vier zulässigen Quellen im Vorgangsprotokoll. R-18-23 verlangt von jeder Fehlermeldung Ursache, Wirkung und nächste Handlung. Über den gesamten Katalog liegt das Mittel bei 1,89 Entscheidungen je Aufgabe.

**Lücke.** Der Anspruch in der gestellten Form ist im Dokument nicht gelöst. Kapitel 23 führt ihn unter "Unter den gegebenen Annahmen nicht erreichbar" mit der Begründung: die Drei-Entscheidungs-Regel entfernt die Syntax, nicht die Sachfrage.

**Welche Verantwortung bleibt.** Das Dokument benennt drei Beispiele fachlicher Entscheidungen mit Folgen, die keine Vereinfachung auflöst: ob eine Domäne extern delegiert wird, welche Datensicherheitsstufe angemessen ist, und ob eine Wiederherstellung den Bestand oder den Wiederherstellungspunkt gewinnen lässt. Dazu treten die Entscheidungen, die das System ausdrücklich nicht selbst trifft: die Wahl der Isolationsstufe eines Mandanten (R-19-01), die Behandlung der Fremdkonten bei einer Deinstallation, für die kein Vorgabewert existiert (R-15-33), die Auswahl über Datenbehandlung beim Entfernen eines Dienstes, und die Freigabe eines Vorgangs aus einer der sechs freigabepflichtigen Aktionsklassen (R-19-27). Auch die Fehlerzonenvorbelegung ist ausdrücklich als Vermutung gekennzeichnet, deren Prüfung beim Bediener liegt (R-16-22).

**Grund.** Eine Entscheidung mit Folgen lässt sich verlegen, vorbelegen oder erklären, aber nicht abschaffen. Der Entwurf wählt den einzigen Weg, der ohne Täuschung auskommt: er begrenzt die Zahl der Entscheidungen, er verlangt für jede Vorbelegung eine benannte Quelle, er zeigt vor jeder Bestätigung eine Wirkungsvorschau — und er lässt die Verantwortung für die Entscheidung beim Bediener. Die Alternative, eine Sachfrage durch eine Vorgabe zu ersetzen, die niemand sieht, verletzt INV-15 und INV-18 und erzeugt genau das, was das Dokument als "Zusage, die die Technik nicht einlöst" ablehnt. Was erreichbar ist und erreicht wird, ist ein anderes: kein Bediener muss wissen, wie eine Zonendatei, eine nftables-Kette oder ein Envoy-Listener aussieht, um einen Dienst zu veröffentlichen.

## E.4 Nicht gestellte, aber notwendige Anforderungen

Die folgenden Gegenstände hat der Auftraggeber nicht gefordert. Ohne sie funktioniert die geforderte Lösung nicht, oder sie funktioniert und lässt sich nicht nachweisen. Jeder Eintrag nennt die Anforderungs-IDs und einen Satz Begründung.

| Gegenstand | Anforderungs-IDs | Warum ohne ihn nichts funktioniert |
|---|---|---|
| **Zeitsynchronisation und Zeitgüte** | R-12-52, R-16-27, R-20-24, R-21-35 | Lease, Selbstabschottung, Zertifikatsgültigkeit, Ordnung der Auditereignisse und die Bewertung, ob eine Beobachtung aktuell ist, hängen sämtlich an der Uhr; K-30 setzt die zugelassene Abweichung auf 500 ms, und INV-32 lässt einen Knoten mit unbekannter Zeitgüte weder führen noch ausstellen noch Auditereignisse schreiben. |
| **Feldeigentum je Konnektorfeld** | R-08-24, R-09-07, R-14-11, R-15-30 | Ohne eine vollständige Deklaration, welches Feld Atrium besitzt und welches das Fremdprodukt, kann der Reconciler nicht zwischen einer zu korrigierenden Abweichung und einer legitimen Fremdänderung unterscheiden und überschreibt entweder fremde Arbeit oder gibt die eigene Zusage auf. |
| **Barrierefreiheit als Baubedingung** | R-03-23, R-18-11, R-18-33, R-18-34, R-18-35, R-22-27, R-22-28 | Eine Oberfläche, die nur mit Zeigegerät und nur mit Farbunterscheidung bedienbar ist, löst die Forderung nach einfacher Bedienung für einen Teil der Bediener gar nicht ein; WCAG 2.2 Stufe AA und der Tastaturdurchlauf aller Aufgaben sind deshalb Bauprüfungen und keine Merkmale. |
| **Wiederherstellung der Kontrollebene** | R-02-14, R-08-31, R-17-24, R-17-25, R-17-27, R-17-28, R-17-29 | Ein System, dessen gesamter Sollzustand in einer Konsensgruppe liegt, ist ohne ein versiegeltes Wiederherstellungspaket mit k-von-n-Anteilen bei Verlust aller Verwaltungsknoten verloren; das Paket enthält keinen privaten CA-Schlüssel, weshalb Ausgabe-CA und Zwischen-CAs nach der Wiederherstellung neu ausgestellt werden. |
| **Geprüfte Wiederherstellung statt behaupteter** | R-17-20, R-17-21, R-17-22, R-02-18 | Ohne monatliche automatisierte Übung auf Ersatzhardware sind alle RPO- und RTO-Werte unbelegte Behauptungen (K-24); ein Wiederherstellungspunkt ohne erfolgreiche Probe trägt deshalb sichtbar den Zustand "ungeprüft". |
| **Löschsemantik ohne Kaskade** | R-07-04, R-07-05, R-07-07, R-10-33 | Eine implizite Mitlöschung referenzierender Objekte macht jede Löschung zu einem unvorhersehbaren Eingriff; stattdessen zeigt der Vorgang vor der Bestätigung die vollständige Liste der Bezüge und hinterlässt einen Grabstein ohne personenbezogene Nutzfelder. |
| **Wirkungsvorschau vor jedem Schreibvorgang** | R-08-13, R-15-11, R-18-17, R-18-18, R-18-19 | Eine Bedienoberfläche mit wenigen Entscheidungen verlagert Wirkung in Ableitungen; ohne eine nebenwirkungsfreie Vorschau, die auch kostenwirksame Fremdaktionen nennt, bestätigt der Bediener etwas, das er nicht sehen kann. |
| **Idempotenz jeder Fremdwirkung** | R-08-09, R-08-10, R-09-03 | Jede Wiederholung nach Zeitüberschreitung, Führungswechsel oder Netzfehler würde sonst ein zweites Konto, eine zweite Adresse oder eine zweite kostenwirksame Zuweisung erzeugen; der Idempotenzschlüssel ist deshalb über beliebig viele Versuche stabil und knotenunabhängig. |
| **Istzustand mit Beobachtungszeitpunkt** | R-08-27, R-16-36, R-18-15 | Ein angezeigter Zustand ohne Alter ist eine Behauptung; ab einem Alter über 90 s wechselt die Angabe in "unbekannt", und ein Dienst ohne frische Beobachtung wird nicht als laufend dargestellt. |
| **Lieferkette, Signatur und Stückliste** | R-06-19, R-15-01, R-20-18, R-20-20, R-22-24 | Ein Katalog von tausend Fremdprodukten ist ohne Signatur, Transparenzprotokolleintrag, Zeitstempel und zwei Stücklistenformate keine Vereinfachung, sondern eine Verteilung unbekannter Herkunft an alle Installationen. |
| **Schemaversionierung und Migration** | R-07-19, R-07-20, R-07-21, R-21-18 | Ein System, das Jahre läuft und dabei Felder gewinnt, braucht eine Regel für unbekannte Felder und unbekannte Aufzählungswerte sowie die Trennung von Schemamigration und Laufzeitänderung, sonst ist keine Aktualisierung mehr rückrollbar. |
| **Notzugang mit Nachweis statt Hintertür** | R-10-11, R-10-18, R-10-19, R-19-47, R-19-48, R-19-49 | Jedes System mit starker Authentisierung braucht einen Weg zurück, wenn alle Authentikatoren verloren sind; der Entwurf löst das mit Wartezeit, Vier-Augen-Prinzip, Zustellung an alle Alarmziele und einem nicht unterdrückbaren Auditereignis auch ohne Quorum, statt mit einem stillen Zugang. |
| **Datensparsamkeit und Einwilligung** | R-21-06, R-21-07, R-21-08, R-22-01 | Ohne aktive Einwilligung erreicht kein Atrium-Prozess eine Adresse des Herstellers; das ist die Voraussetzung dafür, dass die Installation eines Kunden auch dann betreibbar bleibt, wenn der Hersteller nicht erreichbar ist oder nicht mehr existiert. |
| **Sperrfristen für wiedervergebbare Bezeichner** | R-07-08, R-14-08, R-12-02 | Eine wiedervergebene Mailadresse lässt eine andere Person fremde Post empfangen, ein wiedervergebener Zonenindex verweist auf ein anderes Netz; beides ist ohne Sperrfrist ein stiller Fehler und kein sichtbarer. |
| **Überlastverhalten der Kontrollebene** | R-08-32, R-08-33, R-08-34 | Eine Kontrollebene, die unter Last still Anfragen verwirft, macht jede Zeitzusage unbrauchbar; stattdessen wird oberhalb der Annahmeschwelle mit Wartezeitangabe abgelehnt, und Lese- und Vorschaupfad haben getrennte Kontingente. |
| **Ausstiegsfähigkeit** | R-02-14, R-03-19, R-04-11, R-23-01 | Ein Betreiber, der den vollständigen Sollzustand nicht ohne das Produkt lesen kann, ist an das Produkt gebunden; der jährliche Austrittstest verlangt die Rekonstruktion von Personen, Mailadressen, DNS-Einträgen und Veröffentlichungen durch eine unbeteiligte Person ohne laufendes Atrium. |

## E.5 Statistik

### Bewertung der vierzig Forderungen

| Bewertungsstufe | Anzahl | Anteil | Forderungen |
|---|---|---|---|
| abgedeckt | 22 | 55,0 % | F-01, F-03, F-04, F-05, F-06, F-07, F-08, F-15, F-19, F-20, F-22, F-26, F-27, F-28, F-29, F-30, F-32, F-33, F-34, F-35, F-36, F-39 |
| eingeschränkt abgedeckt | 17 | 42,5 % | F-02, F-09, F-10, F-11, F-12, F-13, F-14, F-16, F-17, F-18, F-21, F-23, F-24, F-25, F-31, F-37, F-38 |
| nicht abgedeckt | 1 | 2,5 % | F-40 |
| **Summe** | **40** | **100 %** | — |

Deutung: 39 der 40 Forderungen sind spezifiziert und mit Anforderungs-IDs belegt. Bei 17 davon benennt das Dokument eine Grenze der Geltung. Die Grenzen häufen sich an drei Stellen und nicht gleichmäßig: an der Grenze zu fremden Anbietern (F-23, F-24, F-25), an der Grenze zu fremden Plattformen und fremder Hardware (F-17, F-18, F-38) und an der Objektgrenze der Selbstverwaltung (F-10, F-11, F-13, F-16, F-14). Das ist kein Zufall, sondern die Wiederkehr derselben Ursache: Atrium leitet aus einem eigenen Sollzustand ab und hat außerhalb dieses Sollzustands kein Objekt, aus dem es ableiten könnte.

### Anforderungs-IDs im Dokument

Ausgezählt wurden alle Zeilen, die eine Anforderungs-ID nach KANON 9 einführen, also Listenpunkte der Form `- **R-kk-nn**` und Tabellenzeilen, deren erste Zelle die Kennung trägt. Wiederholte Nennungen in Akzeptanzkriterien und Querverweisen sind nicht mitgezählt.

| Kapitel | IDs | Kapitel | IDs | Kapitel | IDs |
|---|---|---|---|---|---|
| 02 Problemstellung | 18 | 10 Identität | 49 | 18 Bedienkonzept | 49 |
| 03 Zielbild und Prinzipien | 26 | 11 PKI | 30 | 19 Mandanten, Rechte, Audit | 51 |
| 04 Marktabgrenzung | 14 | 12 DNS und Netzwerk | 55 | 20 Sicherheit | 36 |
| 05 Systemarchitektur | 26 | 13 Geräteverwaltung | 32 | 21 Betrieb und Updates | 41 |
| 06 Basis Ubuntu | 25 | 14 Mail | 33 | 22 Compliance | 37 |
| 07 Objektmodell | 28 | 15 Dienste und Software | 36 | 23 Ökonomie und Roadmap | 24 |
| 08 Kontrollebene | 38 | 16 Cluster | 38 | | |
| 09 Konnektoren | 32 | 17 Speicher und Backup | 38 | **Summe** | **756** |

```
Anforderungs-IDs der Kapitel                            756
Kapitel mit Anforderungs-IDs                             22   (02 bis 23)
Mittelwert je Kapitel                       756 / 22 = 34,4
Groesstes Kapitel                            12 DNS und Netzwerk mit 55
Kleinstes Kapitel                            04 Marktabgrenzung mit 14
Spannweite                                   55 - 14 = 41
Anhaenge mit Anforderungs-IDs (A1 bis A4)                71   (A1 16, A2 26,
                                                              A3 14, A4 15)
Anhaenge ohne Anforderungs-IDs                            2   (A5, A6)
Gesamtzahl der Anforderungs-IDs           756 + 71 = 827
Nummernluecken je Kapitel                                 0   (jede Reihe
                                                              laeuft von 01
                                                              bis zum Maximum
                                                              ohne Auslassung)
```

Die Zählung der Lücken ist ein Prüfergebnis und keine Zusage: KANON 9 lässt entfallene Anforderungen mit dem Vermerk "entfallen" stehen, damit eine Nummer nie neu vergeben wird. Im derzeitigen Stand existiert keine entfallene Anforderung.

KANON 9 legt zwei Nummernräume fest: `R-<Kapitelnummer>-<nn>` für die Kapitel 02 bis 23 und `R-A<k>-<nn>` für die Anhänge A1 bis A4; Kapitel 01 sowie A5 und A6 vergeben keine eigenen Anforderungs-IDs. Die Matrix in E.2 belegt ausschließlich mit Kapitel-IDs; Anhang-IDs werden von ihr weder zitiert noch auf Lücken geprüft, und die Summe 756 bezieht sich deshalb durchgehend auf die Kapitel.

### Bestand insgesamt

| Gegenstand | Anzahl | Fundstelle |
|---|---|---|
| Kapitel | 23 | Kapitel 01 bis 23; anforderungstragend sind nach KANON 9 die Kapitel 02 bis 23 |
| Anhänge | 6 | A1 Schemata, A2 API-Referenz, A3 ADR, A4 Bedrohungsmodell und Härtung, A5 Anforderungsmatrix, A6 Glossar und Standardverzeichnis |
| Normative Kurzreferenz | 1 | `KANON.md` |
| Dateien des Bestandes | 30 | `KANON.md` + 23 Kapitel + 6 Anhänge |
| Invarianten | 32 | KANON 2, INV-01 bis INV-32 |
| Kennzahlen | 30 | KANON 6, K-01 bis K-30 |
| Prinzipien | 17 | Kapitel 03, P-01 bis P-17 |
| Anforderungs-IDs der Kapitel | 756 | Kapitel 02 bis 23 |
| Anforderungs-IDs der Anhänge | 71 | A1 16, A2 26, A3 14, A4 15 |
| Navigationsbereiche der Konsole | 8 | KANON 5 |
| Aufgaben im Aufgabenkatalog | 38 | 18.2 |
| Entscheidungen über den Aufgabenkatalog | 72 | 18.2, Mittel 72 / 38 = 1,89 |

### Abdeckungsdichte

```
Nennungen von Anforderungs-IDs in E.2                       181
Davon verschiedene IDs (entdoppelt)                         157
Anteil am Bestand der Kapitel-IDs           157 / 756 = 20,8 %
Belegte Forderungen                                 39 von 40
Mittlere Zahl zitierter IDs je Forderung      181 / 40 = 4,5
                                              (Nennungen, nicht entdoppelt)
```

Deutung: Rund vier Fünftel der Anforderungs-IDs des Dokuments belegen keine der vierzig Forderungen unmittelbar. Das ist kein Überhang, sondern die erwartete Verteilung: die Forderungen beschreiben, was der Auftraggeber sehen will, und ein erheblicher Teil der Spezifikation beschreibt, was erfüllt sein muss, damit das Sichtbare trägt. Die Gegenstände aus E.4 gehören vollständig in diesen Anteil.

## Akzeptanzkriterien

1. Jede in E.2 zitierte Anforderungs-ID existiert im genannten Kapitel und trägt dort eine prüfbar formulierte Anforderung. Prüfbar durch Abgleich jeder Kennung gegen die Anforderungstabelle des Kapitels; eine Kennung ohne Fundstelle bricht die Prüfung.
2. Jede Forderung mit der Bewertung "eingeschränkt abgedeckt" oder "nicht abgedeckt" hat einen eigenen Abschnitt in E.3, und jeder Abschnitt in E.3 gehört zu genau einer solchen Forderung. Prüfbar durch Gegenüberstellung der Bewertungsspalte in E.2 mit der Abschnittsliste von E.3.
3. Die Zahlen in E.5 sind aus dem Bestand nachgerechnet: Summe der Kapitelspalten 756, Anhang-IDs 71, Nennungen in E.2 181, verschiedene IDs 157. Prüfbar durch Auszählung der Zeilen, die eine Kennung einführen, und der Kennungen in der Spalte "Anforderungs-IDs".
4. Kein Verweis in diesem Anhang enthält einen Platzhalter der Form `<Kapitel>.x`. Prüfbar durch Mustersuche nach `\d+\.x`.
5. Jede Forderung, die der Entwurf in der gestellten Form für nicht erreichbar erklärt, trägt die Bewertung "nicht abgedeckt" und zitiert die Zeile aus 23.10. Prüfbar durch Gegenüberstellung der Tabelle "Unter den gegebenen Annahmen nicht erreichbar" mit der Bewertungsspalte in E.2.

## Offene Punkte

1. **Die Bewertungsstufen sind eine Lesart und kein Prüfergebnis.** Die Zuordnung einer Forderung zu "abgedeckt" oder "eingeschränkt abgedeckt" beruht auf dem Urteil, ob die vom Dokument benannte Grenze die Forderung in ihrer gestellten Form trifft. Dieses Urteil ist nicht maschinell prüfbar und von keiner zweiten Stelle gegengelesen. Solange das so ist, ist die Kopfzahl 22 / 17 / 1 eine begründete Einschätzung und keine Messung.
2. **Die Abgrenzung zwischen gestellter Form und unbeschränkter Lesart ist nicht formalisiert.** E.1 unterscheidet beides, aber die vierzig Forderungen liegen nur in ihrer Kurzform vor, nicht in einer Fassung, die den Geltungsumfang ausdrücklich festlegt. Bei F-02, F-10, F-11, F-13, F-16 und F-31 entscheidet diese Unterscheidung die Bewertungsstufe. Eine abgestimmte Langfassung der Forderungen würde die Unterscheidung belegen statt sie zu behaupten.
3. **Die Belegtiefe in E.4 ist ungleichmäßig.** Die sechzehn Gegenstände nennen je zwischen drei und sieben Anforderungs-IDs, ohne dass ein Kriterium für die Auswahl angegeben ist. Ob eine genannte Kennung den Gegenstand trägt oder ihn nur berührt, ist aus der Tabelle nicht erkennbar. Eine Trennung in tragende und ergänzende Kennungen steht aus.
4. **Die Auszählung hat keinen Stichtag.** Die Zahlen in E.5 gelten für den Stand des Bestandes zum Zeitpunkt der Auszählung; jede spätere Anforderung verschiebt sie, ohne dass dieser Anhang das bemerkt. Weder ist der Stand über eine Fassungsangabe gebunden, noch läuft die Auszählung im Bau mit. Ohne eines von beidem veraltet E.5 still.
5. **Das Nummernschema der Anhänge steht nicht im Kanon.** KANON 9 legt allein `R-<Kapitelnummer>-<nn>` fest; die 71 Kennungen der Form `R-A<k>-<nn>` in A1 bis A4 sind ohne kanonische Grundlage vergeben. Entweder nimmt KANON 9 das Anhangschema auf, oder die Anhang-IDs entfallen. Bis dahin sind sie in dieser Matrix ausgewiesen, aber nicht auf Lücken geprüft.
6. **Kapitel 01 ist in keiner Zählung eindeutig eingeordnet.** Die Kapiteltabelle in KANON 9 läuft von 02 bis 23, README zählt 23 Kapitel, und Kapitel 01 trägt keine Anforderungs-IDs. Dieser Anhang führt es als Kapitel und weist es zugleich als nicht anforderungstragend aus. Ob KANON 9 es aufnimmt oder ausdrücklich ausnimmt, ist nicht entschieden.
