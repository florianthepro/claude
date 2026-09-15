# 03 Zielbild und Entwurfsprinzipien

## 3.1 Zielbild

Atrium Server OS ist eine Serverdistribution auf Ubuntu-LTS-Basis, deren gesamter infrastruktureller Betrieb über genau eine Bedienoberfläche und genau eine öffentlich dokumentierte API geführt wird: Der Betreiber erklärt in Objekten, was gelten soll — welche Person welchen Dienst in welcher Rolle nutzt, unter welchem Namen ein Dienst für welchen Kreis erreichbar ist, wie viele Knotenausfälle ein Speicherbereich überstehen muss, welchem Mandanten ein Gerät gehört —, und das System stellt diesen Zustand auf allen beteiligten Knoten, Fremdsystemen und abgeleiteten Artefakten her, hält ihn gegen Abweichung, zeigt mit Beobachtungszeitpunkt an, was davon eingetreten ist und was nicht, und nimmt jede Wirkung über ihre Quelle wieder zurück, während die Fachoberflächen der eingebundenen Fremdprodukte bestehen bleiben und je Katalogeintrag als Grenze deklariert sind und Terminal, Konfigurationsdateien und `atriumctl` Support- und Automatisierungswerkzeuge bleiben statt Betriebsvoraussetzung zu sein.

Dieses Zielbild ist in sieben prüfbare Aussagen zerlegt. Jede ist an eine Invariante oder eine Kennzahl des Kanons gebunden und damit falsifizierbar.

```
Z1  Die Zahl infrastruktureller Konfigurationsflächen ist 1.
    Fachoberflächen bleiben bestehen und sind deklariert.    (INV-30)
Z2  Jede Tatsache ist genau einmal schreibbar; jede weitere
    Darstellung ist abgeleitet und nicht editierbar.         (INV-02, INV-09)
Z3  Jede Aufgabe des Aufgabenkatalogs kostet höchstens drei
    Entscheidungen, und jede Entscheidung ist eine Aussage
    über den Betrieb, nicht über die Technik.                (INV-14, K-03)
Z4  Jede Absicht hat einen datierten Istzustand mit benanntem
    Ausgang je Zielsystem; Teilerfolg heißt Teilerfolg.      (INV-12, INV-28)
Z5  Jede Wirkung ist über ihre Quelle rückbaubar; was nicht
    rückbaubar ist, wird vorher als solches benannt.         (INV-11)
Z6  Der vollständige Sollzustand ist ohne die einrichtende
    Person lesbar, exportierbar und wiederherstellbar.       (K-11, K-24)
Z7  Alle inneren Funktionen arbeiten ohne Verbindung nach
    außen; extern abhängige Funktionen sind benannt.         (INV-25)
```

Gegenüber dem Referenzfall A aus [Kapitel 02](02-problemstellung.md) mit fünfzehn Konfigurationsflächen ist Z1 die einzige Aussage, die eine Größenordnung ändert; Z2 bis Z7 sind die Bedingungen, unter denen Z1 nicht in eine einzige, dafür unübersichtliche Fläche mündet.

## 3.2 Prinzipien

Die folgenden siebzehn Prinzipien sind die Regeln, aus denen die Entscheidungen der Kapitel 05 bis 22 folgen. Jedes Prinzip ist an Invarianten des Kanons verankert; die Invarianten sagen, was gilt, die Prinzipien sagen, warum es gilt und was daraus für den Entwurf folgt.

| ID | Kurzname | Verankerung |
|---|---|---|
| **P-01** | Entscheidungsarmut | INV-14, INV-15, K-03 |
| **P-02** | Eine Wahrheitsquelle | INV-02, INV-03, INV-09 |
| **P-03** | Deklarativ und konvergent | INV-07, K-16 |
| **P-04** | Standardprotokolle vor Sonderadaptern | INV-13, K-22 |
| **P-05** | Sicher in der Voreinstellung | INV-10, INV-20, INV-21 |
| **P-06** | Keine Sackgasse | INV-17, INV-26, INV-01 |
| **P-07** | Umkehrbarkeit | INV-11, INV-24, K-25 |
| **P-08** | Nachvollziehbarkeit jeder Änderung | INV-23, INV-32 |
| **P-09** | Ehrliche Zustandsanzeige | INV-12, INV-18, INV-28 |
| **P-10** | Klare Produktgrenze | INV-13, INV-30 |
| **P-11** | Kein Fremdvokabular | INV-16, K-27 |
| **P-12** | Datensparsamkeit | INV-20, K-12, K-25 |
| **P-13** | Betrieb ohne Internetanbindung | INV-25, INV-22 |
| **P-14** | Austrittsfreiheit | K-11, K-24 |
| **P-15** | Lokale Autonomie | INV-04, INV-06, INV-25 |
| **P-16** | Wirkung vor Bestätigung | INV-08, INV-29, K-18 |
| **P-17** | Barrierefreiheit als Bedingung | INV-31, K-27 |

### P-01 Entscheidungsarmut

**Aussage.** Jede Aufgabe des Aufgabenkatalogs verlangt höchstens drei Angaben, die der Bediener selbst treffen muss; jede weitere Angabe ist vorbelegt, abgeleitet, verschoben oder zusammengelegt.

**Begründung.** Die Zahl der Pflichtangaben ist die einzige Größe der Bedienlast, die statisch prüfbar ist. Klickzahlen und Bildschirmzeiten lassen sich durch Layout senken, ohne dass der Bediener weniger entscheidet; ein Pflichtfeld weniger ist dagegen im Formular abzählbar.

**Konsequenz für die Architektur.** Der Aufgabenkatalog wird ein maschinenlesbares Artefakt, gegen das die Formulardefinitionen im Bau geprüft werden. Jedes Pflichtfeld braucht eine der vier Behandlungen aus Abschnitt 3.3 oder einen Eintrag im versionierten Restfragenkatalog. Die Vorbelegungslogik wandert damit in die Kontrollebene, nicht in die Oberfläche: Eine Oberfläche, die selbst vorbelegt, ist ein zweiter Ort mit eigener Wahrheit und verletzt P-02.

**Akzeptanzkriterium.** Der Bau zählt für jede im Aufgabenkatalog geführte Aufgabe die Pflichtfelder ohne mögliche Vorbelegung; der Zählwert ist für jede Aufgabe höchstens drei, und ein zusätzliches Pflichtfeld bricht den Bau.

**Gegenbeispiel.** Ein Bereitstellungsdialog, der neben Produkt, Name und Sichtbarkeit zusätzlich nach Zielknoten, Speicherpool und Replikationsverfahren fragt und die Antworten mit "empfohlen" beschriftet, ohne dass eine Richtlinie oder ein Objekt sie bestimmt.

### P-02 Eine Wahrheitsquelle

**Aussage.** Jede Tatsache wird genau einmal geschrieben, und zwar im Sollzustand; jede andere Darstellung derselben Tatsache ist ein abgeleitetes Artefakt mit Quellverweis und ohne Schreibweg.

**Begründung.** Halten n Orte dieselbe Tatsache ohne autoritative Quelle, wächst die Zahl der Paare, zwischen denen Divergenz auftreten kann, mit n(n−1)/2, während sie im Sternfall mit n wächst ([Kapitel 02](02-problemstellung.md), Modell M1). Der Gewinn ist nicht nur rechnerisch: Im Sternfall sind die Beziehungen gerichtet und maschinell erzwingbar, im Vollgraph symmetrisch und nur durch Absprache gehalten.

**Konsequenz für die Architektur.** Die API kennt für abgeleitete Artefakte keine Schreiboperation, es gibt keinen Dialog zum Anlegen einer Firewallregel, und die Verwaltungsoberflächen der Kernkomponenten sind abgeschaltet statt nur unverlinkt. Eine Handänderung an einer erzeugten Datei ist kein verbotener, sondern ein nicht dauerhafter Vorgang: Der nächste Abgleich stellt den Sollzustand wieder her und schreibt ein Auditereignis vom Typ "Abweichung korrigiert".

**Akzeptanzkriterium.** Die statische Auswertung der API-Beschreibung ergibt 0 Schreiboperationen auf abgeleitete Artefakte, und der Abweichungstest stellt nach einer Handänderung an jeder erzeugten Datei den vorherigen Inhalt mit zugehörigem Auditereignis wieder her.

**Gegenbeispiel.** Ein Dialog "Firewallregel anlegen", auch dann, wenn er nur Fachrollen sichtbar ist, eine Warnung zeigt und die Regel als "manuell" markiert — die Markierung schafft genau den zweiten Schreibort, dessen Fehlen die Aussage trägt.

### P-03 Deklarativ und konvergent statt imperativ

**Aussage.** Der Bediener beschreibt den Zielzustand, nicht die Schritte dorthin, und der Abgleich wird so lange wiederholt, bis Istzustand und Sollzustand übereinstimmen.

**Begründung.** Ein imperativer Ablauf, der beim zweiten Durchlauf ein anderes Ergebnis liefert, macht jede Wiederholung nach einem Teilfehler zum Risiko. Genau die Wiederholung nach Teilfehler ist aber der häufige Fall, weil Fremdsysteme Ratenbegrenzungen, Zeitüberschreitungen und Wartungsfenster haben.

**Konsequenz für die Architektur.** Jeder Konnektoraufruf trägt einen Idempotenzschlüssel, `plan` und `apply` sind getrennt, und neben dem ereignisgetriebenen Pfad existiert ein periodischer Volllauf als Driftpfad mit einem Zielwert von 60 min für alle Objekte (K-16). Ein Abbruch mitten in einem Vorgang ist damit kein Sonderfall mit eigenem Aufräumpfad, sondern der Normalfall des nächsten Laufs; Aufräumcode, der nur im Fehlerfall läuft und deshalb selten getestet wird, entfällt.

**Akzeptanzkriterium.** Das zweimalige Anwenden desselben Sollzustands erzeugt 0 Änderungsereignisse, 0 Dienstneustarts und 0 Auditereignisse vom Typ "geändert".

**Gegenbeispiel.** Ein Einrichtungsablauf, der beim zweiten Durchlauf ein zweites Fremdkonto anlegt oder mit der Meldung "existiert bereits" abbricht und den Bediener zum Aufräumen von Hand auffordert.

### P-04 Standardprotokolle vor Sonderadaptern

**Aussage.** Ein Fremdsystem wird über das allgemein spezifizierte Protokoll angebunden, das es unterstützt; ein produktspezifischer Adapter ist die begründungspflichtige Ausnahme.

**Begründung.** Die Pflegelast wächst mit der Zahl eigener Adapter, nicht mit der Zahl angebundener Systeme. Ein Protokoll mit vielen unabhängigen Implementierungen bricht seltener, ist gegen mehr als eine Gegenstelle testbar, und seine Semantik ist schriftlich fixiert statt aus dem Verhalten einer Version erschlossen.

**Konsequenz für die Architektur.** Erste Wahl sind SCIM (RFC 7643, RFC 7644) für Identitäten, LDAPv3 (RFC 4511) für Verzeichniszugriff, OAuth 2.0 (RFC 6749) mit PKCE (RFC 7636) und OpenID Connect Core 1.0 für Anmeldung, ACME (RFC 8555) für Zertifikate, EAP-TLS (RFC 5216) für Netzzugang, Syslog (RFC 5424) für Protokollausleitung sowie SPF (RFC 7208), DKIM (RFC 6376) und DMARC (RFC 7489) für Mailrichtlinien. Alle Konnektoren, auch der generische deklarative Treiber, bedienen denselben einen Vertrag mit den Operationen `describe`, `observe`, `plan`, `apply` und `healthcheck`; der Zielwert für den Anteil manifestbasierter Konnektoren liegt bei mindestens 90 % (K-22).

**Akzeptanzkriterium.** Jedes Konnektormanifest nennt entweder das benutzte Protokoll mit Fundstelle oder eine schriftliche Begründung für den Sonderweg; der Anteil manifestbasierter Konnektorbindungen an allen ausgelieferten Bindungen beträgt mindestens 90 %, und jeder Protokollkonnektor besteht seine Vertragstests gegen mindestens zwei unabhängige Gegenstellen.

**Gegenbeispiel.** Ein eigener Adapter für ein Fremdsystem, das SCIM spricht, geschrieben, weil der Adapter zwei Attribute bequemer abbildet und beim Anlegen eine Ratenbegrenzung umgeht.

### P-05 Sicher in der Voreinstellung

**Aussage.** Ein nicht getroffener Entschluss führt zum verschlossenen Zustand; Erreichbarkeit, Rechte und Netzwege entstehen ausschließlich, weil ein Objekt sie erzeugt.

**Begründung.** Eine öffnende Vorbelegung wirkt bei jedem Bediener, der die Frage überspringt, und ihre Folge ist unsichtbar. Eine schließende Vorbelegung erzeugt im gleichen Fall eine sichtbare Störung, die zum verursachenden Objekt führt. Der Unterschied ist nicht die Fehlerhäufigkeit, sondern die Entdeckungswahrscheinlichkeit.

**Konsequenz für die Architektur.** Die L3/L4-Regelsätze werden vollständig aus dem Objektgraphen erzeugt und atomar getauscht, und jeder Fehlerzustand beim Tausch fällt auf Default-Deny zurück statt auf den vorherigen offenen Satz. Konnektorprozesse laufen in eigenen Netznamensräumen mit einer aus der Bindung erzeugten Ausgangs-Positivliste und erreichen die API der Kontrollebene nicht über das Netz. Geheimnisse sind schreibbar und nie lesbar, weshalb auch der Sollzustandsexport sie nur als Referenz führt.

**Akzeptanzkriterium.** Der Erreichbarkeitstest nach einer abgebrochenen Regelanwendung findet 0 erreichbare Dienste ohne deckende Veröffentlichung, und der Netznamensraumtest weist für jeden Konnektorprozess den Zugriff auf eine nicht gelistete Adresse ab.

**Gegenbeispiel.** Ein neu bereitgestellter Dienst, der bereits auf 443/tcp aus dem externen Netz antwortet, bevor eine Veröffentlichung angelegt wurde, weil der Eingang unbekannte Namen an den zuletzt gestarteten Dienst weiterreicht.

### P-06 Keine Sackgasse

**Aussage.** Für jeden Zustand, in den das System geraten kann, existiert ein in der Oberfläche beschriebener Ausgang, und der Fachausgang existiert zusätzlich, ohne dass ein Normalweg von ihm abhängt.

**Begründung.** Eine Fehlermeldung, deren einzige Lösung ein Befehl ist, verlegt die Bedienung genau an der Stelle ins Terminal, an der der Bediener am wenigsten Übung hat und unter Zeitdruck steht. Der Fachausgang bleibt trotzdem nötig, weil ein System ohne Weg für Fachleute im Sonderfall entweder unrettbar ist oder eine Herstellerhintertür braucht.

**Konsequenz für die Architektur.** `atriumctl` benutzt dieselbe öffentliche API wie die Konsole, sodass ein Fachausgang keine zusätzliche Funktionsfläche erzeugt (INV-01). Der Meldungskatalog ordnet jeder Meldung genau einen Weg in der Oberfläche zu; eine Meldung ohne solchen Weg gilt als nicht fertig entwickelte Funktion und nicht als Dokumentationslücke. Der Notzugang ist physisch gebunden, zeitlich begrenzt und auditiert, und es gibt keinen Herstellerfernzugang.

**Akzeptanzkriterium.** Die Musterprüfung aller Meldungstexte findet 0 Meldungen mit einem Befehl oder Dateipfad als einzigem Lösungsweg, und der vollständige Aufgabenkatalog läuft ohne Terminalzugriff durch.

**Gegenbeispiel.** Die Meldung "Zertifikatsausstellung fehlgeschlagen, Einzelheiten im Protokoll auf dem Knoten" ohne Verweis auf die betroffene Veröffentlichung und ohne Wiederholungsmöglichkeit in der Konsole.

### P-07 Umkehrbarkeit

**Aussage.** Jede Wirkung ist über ihre Quelle rücknehmbar, und was nicht rücknehmbar ist, wird vor der Ausführung als solches benannt und nie in einer Massenaktion ohne Einzelaufstellung ausgeführt.

**Begründung.** Die Kosten einer falschen Entscheidung bestehen aus Eintrittswahrscheinlichkeit und Schadenshöhe. Eine Plattform kann die Wahrscheinlichkeit nur begrenzt senken, weil eine mit korrekten Rechten getroffene falsche Entscheidung berechtigt ist; die Schadenshöhe senkt sie dagegen um den Faktor, den die Rücknahme erlaubt.

**Konsequenz für die Architektur.** Der Vorgang ist die Änderungseinheit und trägt einen Rücksprungverweis, freigegebene Datenträger unterliegen einer Aufbewahrungsfrist von 30 Tagen und entfernte Mailadressen einer Sperrfrist von 12 Monaten (K-25). Kaskadenlöschung existiert in der API nicht, das Wurzeldateisystem ist A/B-geteilt, sodass die Rücknahme einer Systemänderung ein Neustart ist, und eine Schemamigration ist ein eigener, separat rückrollbarer Vorgang (INV-24).

**Akzeptanzkriterium.** Für jede Aktion des Aufgabenkatalogs ist im Test entweder die Rücknahme ausgeführt worden oder die Aktion trägt die Kennzeichnung "nicht rücknehmbar" mit benanntem Grund; der Anteil ungekennzeichneter nicht rücknehmbarer Aktionen beträgt 0.

**Gegenbeispiel.** Die Auflösung eines Mandanten, die Datenträger sofort freigibt und die zugehörigen Mailadressen sofort zur Neuvergabe freischaltet, sodass die nächste gleichnamige Person fremde Post empfängt.

### P-08 Nachvollziehbarkeit jeder Änderung

**Aussage.** Jede schreibende Handlung hinterlässt einen unveränderlichen, hashverketteten Nachweis mit Urheber, Vorher-Nachher-Stand und Korrelationskennung, der das geänderte Objekt überlebt.

**Begründung.** Ohne einen vom Objekt unabhängigen Nachweis ist die Frage "wer hat das geändert" nur beantwortbar, solange das Objekt existiert. Gerade Löschungen sind aber die Ereignisse, deren Nachweis am ehesten gebraucht wird.

**Konsequenz für die Architektur.** Der Auditstrom liegt außerhalb des replizierten Kernzustands, ist nur anhängbar, wird periodisch signiert und mit einem Zeitstempel nach RFC 3161 versehen und über Syslog (RFC 5424) über TLS ausgeleitet. Keine Rolle besitzt ein Änderungs- oder Löschrecht darauf. Die Kette hängt an der Uhr, weshalb ein Knoten mit unbekannter Zeitgüte keine Auditereignisse schreibt, sondern sich abschottet (INV-32).

**Akzeptanzkriterium.** Die Kettenprüfung bei jedem Start und bei jedem Export ergibt 0 Lücken, die Ereignisse eines gelöschten Objekts sind weiterhin abrufbar, und die API-Beschreibung enthält 0 Operationen, die ein Auditereignis ändern oder löschen.

**Gegenbeispiel.** Ein Auditbericht, der mit dem Mandanten gelöscht wird, oder eine Rolle "Auditverwalter", die Einträge zur Bereinigung entfernen darf.

### P-09 Ehrliche Zustandsanzeige

**Aussage.** Der angezeigte Zustand nennt Beobachtungszeitpunkt, Abweichung und Rest, statt einen Teilerfolg als Erfolg oder eine fehlende Beobachtung als Gesundheit darzustellen.

**Begründung.** Eine grüne Anzeige ohne Beobachtungszeitpunkt ist eine Aussage über die Oberfläche und nicht über das System. Wer ihr vertraut, entdeckt den Ausfall einer Beobachtungsstrecke erst dann, wenn ein Nutzer ihn meldet.

**Konsequenz für die Architektur.** Jede Istansicht führt den Zeitpunkt der letzten Beobachtung mit, ein Vorgang endet nie im Zustand "fertig", solange ein Zielsystem aussteht, und die Versorgung einer Zuweisung hat eine harte Obergrenze von 15 min, damit die Oberfläche nie unbegrenzt "in Arbeit" zeigt (K-15). Degradierte Zustände sind dauerhaft sichtbar: Ein Ein-Knoten-System zeigt "Redundanz: keine", ein Dienst ohne geprüfte Sicherung zeigt das am Dienst, und der Überblick nennt fortlaufend, wie viele Knotenausfälle derzeit noch vertragen werden.

**Akzeptanzkriterium.** Der Darstellungstest findet 0 Istansichten ohne Beobachtungszeitpunkt, und die Fehlerinjektion in einen von mehreren Konnektoren führt zum Vorgangsausgang "teilweise fehlgeschlagen" mit benanntem Zielsystem und benanntem Rest.

**Gegenbeispiel.** Eine Übersicht mit der Aussage "Alle Systeme betriebsbereit", während die letzte erfolgreiche Beobachtung einer Konnektorbindung zwei Tage zurückliegt und der Zustand aus dem Zwischenspeicher stammt.

### P-10 Klare Produktgrenze gegenüber Fremdoberflächen

**Aussage.** Atrium bestimmt, wo ein Fremdprodukt läuft, unter welchem Namen es erreichbar ist, wer es in welcher Rolle nutzen darf und wie seine Daten gesichert werden; die fachliche Einrichtung bleibt in der Oberfläche des Fremdprodukts und ist je Katalogeintrag deklariert.

**Begründung.** Eine nachgebaute Fremdoberfläche muss jeder Änderung des Fremdprodukts folgen und ist ab der nächsten Version unvollständig. Die Grenze existiert ohnehin; die Wahl besteht nur darin, ob sie deklariert oder unklar ist.

**Konsequenz für die Architektur.** Ein Katalogeintrag ohne Grenzdeklaration wird bei der Freigabe abgelehnt, und jedes Konnektormanifest erklärt je Feld, ob Atrium es besitzt, ob das Fremdsystem es besitzt oder ob es nur bei Erstanlage gesetzt wird; der Reconciler überschreibt ausschließlich Felder in eigenem Besitz. Die Verwaltungsoberflächen der Kernkomponenten sind abgeschaltet, während die Fachoberflächen der Fremdprodukte erreichbar bleiben und am Dienst verlinkt sind.

**Akzeptanzkriterium.** 100 % der freigegebenen Katalogeinträge tragen eine Grenzdeklaration, jede darin benannte Fremdoberfläche ist am Dienst verlinkt, und der Reconciler schreibt in der Vertragsprüfung 0 Felder außerhalb des erklärten Eigentums.

**Gegenbeispiel.** Ein Atrium-Dialog zum Anlegen von Warteschlangen im Ticketsystem, der dessen eigene Warteschlangenverwaltung spiegelt und bei jeder Produktänderung nachgezogen werden muss.

### P-11 Kein Fremdvokabular in der Oberfläche

**Aussage.** Die Konsole benennt Gegenstände der Betreiberwelt; Begriffe der Implementierung erscheinen weder in Bedientexten noch in Fehlermeldungen noch in Berichten.

**Begründung.** Ein Begriff, den der Bediener nicht kennt, ist eine Frage, die er nicht beantworten kann, und damit nach der Definition aus [Kapitel 02](02-problemstellung.md) eine Abstraktionsleckage. Sie ist besonders schädlich in Fehlermeldungen, weil dort keine Zeit zum Nachschlagen bleibt.

**Konsequenz für die Architektur.** Es existiert eine Positivliste erlaubter Oberflächenbegriffe, gegen die alle Oberflächentexte geprüft werden, und eine Übersetzungstabelle, die Fremdfehler in Aussagen über Objekte überführt, statt sie durchzureichen. Die Prüfung schließt die Übersetzungstabelle ein, weil ein durchgereichter Fremdtext sonst die Regel an genau der Stelle unterläuft, an der sie zählt.

**Akzeptanzkriterium.** Die Prüfung aller Oberflächentexte, Fehlermeldungen, Berichte und Übersetzungseinträge gegen die Begriffsliste ergibt 0 Treffer.

**Gegenbeispiel.** Die Meldung "Pod konnte nicht geplant werden" oder "ACME-Order fehlgeschlagen" anstelle einer Aussage darüber, welcher Dienst unter welchem Namen derzeit kein gültiges Zertifikat hat und wann der nächste Versuch läuft.

Dieses Prinzip hat eine Schwäche, die an dieser Stelle entsteht: Ein unbekannter Fremdfehler aus einem neu angebundenen System ist nicht übersetzbar, weil die Übersetzung erst nach seinem ersten Auftreten geschrieben werden kann. Der Rückfall auf eine gekennzeichnete, unübersetzte Fremdmeldung erfüllt den Buchstaben der Regel nicht und ihren Zweck nur teilweise; Abschnitt "Offene Punkte" führt das als Entscheidungsbedarf.

### P-12 Datensparsamkeit

**Aussage.** Das System erhebt, repliziert, protokolliert und exportiert nur, was eine benannte Funktion benötigt.

**Begründung.** Jedes gespeicherte Feld ist zugleich ein Auskunfts-, Berichtigungs- und Löschfall nach der Verordnung (EU) 2016/679 und eine Angriffsfläche. Zusätzlich ist jedes replizierte Feld eine Last für Konsens, Momentaufnahme und Wiederherstellung.

**Konsequenz für die Architektur.** Der Istzustand wird knotenlokal gehalten und nur verdichtet repliziert, Metriken liegen in einem lokalen Zeitreihenspeicher und niemals im replizierten Zustand, und der Zielwert für den serialisierten Sollzustand bleibt dadurch bei 50 MB für rund 15.000 Objekte (K-12). Geheimnisse erscheinen im Sollzustand nur als Referenz, Konnektorprozesse erhalten ausschließlich kurzlebige, auftragsgebundene Referenzen, und eine Ausleitung von Telemetrie an ein externes Ziel existiert nur als ausdrücklich eingerichtete Konnektorbindung.

**Akzeptanzkriterium.** Die Ausgabeprüfung gegen Geheimnismuster in Protokollen und Exporten ergibt 0 Treffer, der Sollzustandsexport enthält 0 Nutzdaten, und im Trennungstest besteht 0 ausgehende Verbindung, die nicht auf ein Objekt zurückführbar ist.

**Gegenbeispiel.** Eine Nutzungsstatistik, die bei jeder Installation eingeschaltet ist, Objektnamen und Mandantenkennungen an ein Herstellerziel überträgt und sich nur in einer Einstellung abschalten lässt, die keine Entsprechung im Objektgraphen hat.

### P-13 Betriebsfähigkeit ohne Internetanbindung

**Aussage.** Anmeldung, Namensauflösung, Zertifikatsausstellung, Dienststart und Abgleich arbeiten ohne Verbindung nach außen; von außen abhängig sind ausschließlich Funktionen, die per Definition außen liegen.

**Begründung.** Eine Plattform, deren Anmeldung oder Zertifikatserneuerung an einer Internetverbindung hängt, besitzt eine Ausfallursache, die der Betreiber weder kontrolliert noch prüfen kann. Für abgeschottete Netze ist sie zusätzlich gar nicht einsetzbar.

**Konsequenz für die Architektur.** Die zweistufige interne PKI mit einem vom Kern erbrachten ACME-Server (RFC 8555), der eigene autoritative Namensdienst, der validierende Resolver und der eingebettete Protokollkopf für Anmeldungen sind Bestandteile des Systems und keine Fremdbezüge. Abbilder aller platzierbaren Dienste werden auf den zulässigen Knoten vorgehalten, weil sonst der Wiederanlauf nach K-06 an einem Nachladen hinge. Extern abhängig bleiben öffentliche Zertifikate für externe Namen, Mailübergabe, Aktualisierungsbezug, gesicherte Zeitquellen und jedes gebundene Fremdsystem; diese Abhängigkeiten sind je Dienst mit dem Zeitpunkt der letzten erfolgreichen Beobachtung sichtbar.

**Akzeptanzkriterium.** Im Trennungstest laufen nach Trennung vom Internet Anmeldung, interne Namensauflösung, interne Zertifikatserneuerung, Dienststart und vollständiger Abgleich weiter, und die Konsole benennt jede extern abhängige Funktion einzeln.

**Gegenbeispiel.** Eine Anmeldung, die eine Prüfung gegen einen Herstellerdienst voraussetzt, oder ein Dienstneustart, der ein Abbild aus einer externen Ablage nachlädt, weil auf dem Knoten nur ein Verweis liegt.

An dieser Stelle entsteht ein ungelöster Widerspruch: K-30 verlangt mindestens zwei gesicherte externe Zeitquellen, und ohne Internetanbindung gibt es sie nicht. Entweder tritt eine lokale Hardwarezeitquelle an ihre Stelle, oder die Zusage aus K-30 gilt im abgeschotteten Betrieb nicht; der Kanon entscheidet das derzeit nicht.

### P-14 Austrittsfreiheit

**Aussage.** Ein Betreiber kann das Produkt verlassen, ohne Daten oder Zugänge zu verlieren, und der Austritt wird geprobt statt behauptet.

**Begründung.** Eine Plattform, die alle infrastrukturellen Konfigurationsflächen bündelt, ist andernfalls die stärkste Bindung, die ein Betreiber eingehen kann: Der Wert der Bündelung und die Höhe der Austrittskosten haben dieselbe Ursache.

**Konsequenz für die Architektur.** Der Sollzustandsexport ist signiert, versioniert sowie maschinen- und menschenlesbar und damit unabhängig von der laufenden Kontrollebene auswertbar. Nutzdaten liegen in Speicherbereichen auf einem verbreiteten Dateisystem und nicht in einem produkteigenen Ablageformat. Das Entfernen einer Konnektorbindung bietet ausdrücklich die Wahl zwischen Deaktivierung und Belassen der Fremdkonten, sodass der Austritt nicht die Zugänge der Nutzer vernichtet.

**Akzeptanzkriterium.** Im jährlichen Austrittstest rekonstruiert eine unbeteiligte Person allein aus Export und Nutzdatensicherung, ohne laufendes Atrium, die Personenliste, die Mailadressen, die DNS-Einträge und die Veröffentlichungen; nach dem Entfernen einer Bindung mit der Wahl "belassen" sind 100 % der betroffenen Fremdkonten weiter nutzbar.

**Gegenbeispiel.** Ein Speicherbereich, dessen Inhalt nur über ein Atrium-eigenes Format lesbar ist, oder eine Konnektorbindung, deren Entfernen die Fremdkonten zwingend deaktiviert.

Der Export beschreibt das Objektmodell von Atrium. Ein Nachfolgesystem muss es abbilden, und dieser Abbildungsaufwand bleibt bestehen; die Zusage lautet Lesbarkeit und Vollständigkeit, nicht Aufwandsfreiheit.

### P-15 Lokale Autonomie vor zentraler Steuerung

**Aussage.** Ein Knoten hält seine laufenden Dienste ohne erreichbare Kontrollebene aufrecht und startet sie nach einem Neustart erneut; ohne Quorum wird nicht geschrieben, aber auch nichts abgeräumt.

**Begründung.** Die Kopplung "Steuerung aus, Dienst aus" macht die Kontrollebene zur gemeinsamen Ausfallursache sämtlicher Dienste und entwertet jede Verfügbarkeitsrechnung, die unabhängige Ausfälle voraussetzt.

**Konsequenz für die Architektur.** Die erzeugten Startbeschreibungen liegen lokal auf dem Knoten und sind ohne den Kern lauffähig, der Sollzustand friert bei fehlendem Quorum ein statt Lasten zu verschieben, und die Selbstabschottung per Lease greift für Arbeitslasten mit Exklusivitätsanspruch, damit eine Übernahme auf einem anderen Knoten nie mit einem weiterlaufenden Original zusammentrifft. Die Übernahmefrist liegt stets über der Leasefrist zuzüglich zugelassener Uhrenabweichung, im Entwurfsstand 30 s gegen 20 s bei höchstens 500 ms Abweichung (K-07).

**Akzeptanzkriterium.** Nach vollständiger Abschaltung der Kontrollebene und anschließendem Knotenneustart laufen die zuvor erfolgreich gestarteten Dienste wieder, und im Partitionstest führt die Minderheitsseite 0 Lösch- und 0 Verlagerungsoperationen aus.

**Gegenbeispiel.** Ein Knoten, der nach Ablauf seiner Lease sämtliche Arbeitslasten beendet, auch diejenigen ohne Exklusivitätsanspruch, und damit bei einem Ausfall der Kontrollebene alle Dienste einer Installation stillsetzt.

Zwischen INV-06 und INV-25 besteht in der wörtlichen Lesart ein Widerspruch: Fällt die gesamte Kontrollebene aus, besitzt kein Knoten mehr eine gültige Lease. Der Entwurf folgt der Lesart, dass die Lease nur übernahmefähige, exklusiv gebundene Arbeitslasten abschottet; diese Lesart ist im Kanon nicht festgeschrieben und wird als Entscheidungsbedarf geführt.

### P-16 Wirkung vor Bestätigung

**Aussage.** Vor jeder Bestätigung zeigt das System die Liste der konkreten Wirkungen einschließlich kaufmännischer Folgen, und die Berechnung dieser Liste verändert nichts.

**Begründung.** Eine Entscheidung ohne Kenntnis ihrer Folgen ist keine Entscheidung, sondern eine Zustimmung. Eine Vorschau, die selbst bereits wirkt, ist keine Vorschau, sondern eine unbeschriftete Ausführung.

**Konsequenz für die Architektur.** Der Konnektorvertrag trennt `plan` von `apply`, und der Vertragstest prüft nach jedem `plan`-Aufruf den Istzustand des Fremdsystems auf Unverändertheit. Kostenwirksame Aktionen sind im Manifest deklariert, und eine bekanntermaßen kostenwirksame, nicht deklarierte Aktion bricht den Vertragstest. Der Zielwert von 2 s für die Vorschau (K-18) begrenzt zugleich, wie viele Bindungen synchron befragt werden dürfen; im Entwurfsstand sind das höchstens sieben.

**Akzeptanzkriterium.** Nach jedem `plan`-Aufruf ist der Istzustand des Fremdsystems unverändert, jede als kostenwirksam deklarierte Aktion erscheint vor der Bestätigung in der Wirkungsvorschau, und die Vorschau erreicht p95 höchstens 2 s.

**Gegenbeispiel.** Eine Vorschau, die zur Ermittlung der Lizenzfolge bereits eine Lizenz im Fremdsystem zuweist und sie anschließend zurücknimmt, weil die Fremd-API keine Abfrage ohne Zuweisung anbietet.

### P-17 Barrierefreiheit als Bedingung

**Aussage.** Die Konsole ist vollständig ohne Zeigegerät bedienbar, arbeitet mit Bildschirmlesern und erfüllt WCAG 2.2 Stufe AA, und zwar für jede Aufgabe des Aufgabenkatalogs.

**Begründung.** Barrierefreiheit ist für die Richtlinie (EU) 2019/882 und das Barrierefreiheitsstärkungsgesetz eine Rechtsfrage und für den Entwurf eine Strukturfrage: Eine Funktion, die nur über Ziehen, Überfahren oder Farbe erreichbar ist, besitzt keine benennbare Darstellung und ist damit auch nicht automatisiert prüfbar.

**Konsequenz für die Architektur.** Jede Aktion hat eine Textentsprechung und eine Tastaturfolge, Zustände werden nicht allein über Farbe kodiert, und der Bau führt neben der Regelprüfung einen Tastaturdurchlauf des Aufgabenkatalogs aus. Diese Bedingung begrenzt die zulässigen Darstellungsformen: Eine Kapazitätsansicht, die sich nur zeichnerisch erschließt, braucht eine gleichwertige Tabelle, sonst ist sie keine zulässige Darstellung.

**Akzeptanzkriterium.** Die automatisierte Regelprüfung meldet 0 Verstöße gegen WCAG 2.2 Stufe AA, und der Tastaturdurchlauf erreicht 100 % der Aufgaben des Aufgabenkatalogs ohne Zeigegerät.

**Gegenbeispiel.** Eine Platzierungsansicht, in der ein Dienst nur durch Ziehen auf einen anderen Knoten verlagert werden kann, ohne gleichwertige Aktion in der Objektansicht.

### Tragende Kapitel je Prinzip

| Prinzip | Ausgeführt in |
|---|---|
| P-01, P-11, P-17 | [Kapitel 18](18-bedienkonzept.md) |
| P-02, P-03, P-15 | [Kapitel 08](08-kontrollebene.md), [Kapitel 16](16-cluster.md) |
| P-04, P-10, P-16 | [Kapitel 09](09-konnektoren.md), [Kapitel 15](15-dienste-software.md) |
| P-05 | [Kapitel 12](12-dns-netzwerk.md), [Kapitel 20](20-sicherheit.md) |
| P-06 | [Kapitel 18](18-bedienkonzept.md), [Kapitel 21](21-betrieb-updates.md) |
| P-07, P-14 | [Kapitel 17](17-speicher-backup.md), [Kapitel 21](21-betrieb-updates.md) |
| P-08, P-12 | [Kapitel 19](19-mandanten-rechte-audit.md), [Kapitel 22](22-compliance.md) |
| P-09 | [Kapitel 07](07-objektmodell.md), [Kapitel 18](18-bedienkonzept.md) |
| P-13 | [Kapitel 11](11-pki.md), [Kapitel 12](12-dns-netzwerk.md), [Kapitel 10](10-identitaet.md) |

## 3.3 Methode zur Beseitigung von Rückfragen

Die Drei-Entscheidungs-Regel ist keine Gestaltungsempfehlung, sondern eine Rechenvorgabe: Von den Fragen, die eine Aufgabe fachlich aufwirft, muss der überwiegende Teil vor dem Formular verschwinden. Zulässig sind dafür genau vier Behandlungen. Eine Frage, die keiner davon zugeordnet ist und trotzdem nicht gestellt wird, ist eine stille Vorgabe und verletzt INV-15.

### 3.3.1 Die vier Behandlungen

| ID | Behandlung | Zulässig, wenn | Was der Bediener stattdessen sieht | Wo die Entscheidung tatsächlich fällt | Eingeführtes Risiko |
|---|---|---|---|---|---|
| **B1** | Vorgabe aus Richtlinie | Eine Richtlinie mit benannter Geltung, Version und Erzwingungsart bestimmt den Wert | Den vorbelegten Wert mit Verweis auf die Richtlinie | Einmal je Mandant oder Plattform, durch eine Rolle mit Richtlinienrecht | Ein falscher Richtlinienwert wirkt sofort auf alle betroffenen Objekte |
| **B2** | Ableitung aus dem Objektgraphen | Der Wert ist aus bereits erklärten Objekten eindeutig bestimmt | Den abgeleiteten Wert mit Verweis auf das bestimmende Objekt | Bereits getroffen, beim Anlegen des bestimmenden Objekts | Eine unausgesprochene Ableitungsregel wird zur verborgenen Semantik |
| **B3** | Verschiebung auf den erzwingenden Zeitpunkt | Die zur Antwort nötige Tatsache existiert zum Zeitpunkt der Aufgabe noch nicht | Nichts; später eine sichtbare offene Aufgabe am betroffenen Objekt | Später, ausgelöst durch ein benanntes Ereignis | Verschiebung ohne Auslöser wird zum Vergessen |
| **B4** | Zusammenlegung zu einer Entscheidung höherer Ordnung | Eine gröbere Entscheidung bestimmt zwei oder mehr feine Werte deterministisch | Die gröbere Wahl und die daraus folgenden Werte | Gleichzeitig, aber in der Sprache des Betreibers | Nicht ausdrückbare Sonderfälle verschwinden aus dem Produkt |

Das Musterbeispiel für B4 ist die Datensicherheitsstufe: Der Bediener wählt **Lokal**, **Gespiegelt** oder **Synchron gespiegelt**, und daraus folgen Replikationstechnik, Replikatzahl, zulässige Platzierungen und das angezeigte RPO. Die Alternative — eine Auswahl der Replikationstechnik — wäre eine Frage über die Implementierung und damit eine Abstraktionsleckage; die stille Ableitung aus dem Katalogeintrag wäre die dritte, schlechteste Möglichkeit, weil die Oberfläche dann RPO 0 anzeigen könnte, wo asynchron repliziert wird.

### 3.3.2 Entscheidungsregel

```
Eingabe: eine Frage f, die eine Standardaufgabe stellen könnte

1  Ist der Wert von f aus bereits erklärten Objekten eindeutig bestimmt?
     ja  -> B2. Pflicht: Wert anzeigen, bestimmendes Objekt benennen
             (INV-15); Korrektur ausschließlich an der Quelle, nie im
             Formular.
2  Existiert eine zuständige Richtlinie, oder lohnt ihre Einrichtung
   nach Modell M3-01?
     ja  -> B1. Pflicht: Richtlinienverweis am Feld; Erzwingung hart
             oder weich deklariert; Abweichung begründungspflichtig;
             Aktivierung zeigt vorab die Menge betroffener Objekte.
3  Bestimmt eine gröbere, in der Sprache des Betreibers formulierbare
   Entscheidung f zusammen mit mindestens einer weiteren Frage
   deterministisch?
     ja  -> B4. Pflicht: Abbildung grobe Wahl -> feine Werte ist
             offengelegt und in der Wirkungsvorschau sichtbar.
4  Existiert die zur Antwort nötige Tatsache jetzt noch nicht?
     ja  -> B3. Pflicht: benanntes auslösendes Ereignis, sichtbare
             offene Aufgabe am Objekt, keine stille Vorbelegung.
5  sonst -> Restfrage. Sie zählt gegen INV-14 und wird im
             Restfragenkatalog mit Begründung geführt.
```

Die Reihenfolge ist nicht beliebig. B2 steht vorn, weil eine Ableitung kein neues zu pflegendes Objekt erzeugt und der erklärten Absicht nicht widersprechen kann. B1 folgt, weil eine Richtlinie ein eigenes Objekt mit Version, Geltung und Pflegeaufwand ist. B4 steht vor B3, weil es als einzige Behandlung die Gesamtzahl der Entscheidungen über die Lebensdauer wirklich senkt, während B3 sie nur verschiebt; B3 ist deshalb der letzte zulässige Ausweg und nur mit benanntem Auslöser gültig.

**Amortisation einer Richtlinie.** B1 lohnt sich nicht immer. Eine Richtlinie ist selbst eine Entscheidung, sie ist zu formulieren, freizugeben und zu pflegen.

```
Modell M3-01 (Amortisation)
  N             Zahl der Vorgänge, in denen die Frage sonst gestellt würde
  c_frage       Bedienaufwand der Frage je Vorgang
  c_anzeige     Aufwand, den vorbelegten Wert zu lesen und zu bestätigen
  c_richtlinie  einmaliger Aufwand für Entscheidung, Formulierung,
                Freigabe und Pflege der Richtlinie
  Bedingung     N * (c_frage - c_anzeige) > c_richtlinie
  Schwelle      N* = c_richtlinie / (c_frage - c_anzeige)

  Annahmen (gesetzt, nicht gemessen):
    c_frage = 20 s, c_anzeige = 2 s, c_richtlinie = 600 s
  Ergebnis
    N* = 600 / (20 - 2) = 600 / 18 = 33,3  ->  ab 34 Vorgängen lohnend
```

Deutung: Für eine Frage innerhalb von "Nutzer anlegen" ist im Referenzfall A mit 500 Personen N = 500 und damit weit über der Schwelle; für eine Frage, die einmal je Mandant auftritt, ist N = 1 und B1 ausgeschlossen — dort greift B2, B4 oder die Restfrage. Das ist ein Modell und keine Messung; es blendet die Risikokosten aus und berücksichtigt nicht, dass jede Richtlinie, die bei der Ersteinrichtung entschieden werden muss, gegen das Budget von vier Entscheidungen aus K-01 zählt.

**Risikoseite von B1.** Eine Vorgabe senkt nicht automatisch die Fehlerzahl, sondern verlagert sie.

```
Modell M3-02 (Fehlerkonzentration)
  Einzelfrage:  erwartete Zahl falsch gesetzter Objekte = N * p_frage
  Richtlinie:   erwartete Zahl falsch gesetzter Objekte = N * p_richtlinie
  Vorteil der Richtlinie genau dann, wenn p_richtlinie < p_frage
```

Die Richtlinie ist nicht deshalb besser, weil sie automatisch wirkt, sondern nur dann, wenn die eine Entscheidung sorgfältiger getroffen wird als N einzelne. Plausibel wird das durch die Eigenschaften des Objekts: Die Richtlinie ist einzeln prüfbar, versioniert, auditiert und zeigt vor der Aktivierung die Menge der betroffenen Objekte. Fällt p_richtlinie dennoch nicht unter p_frage, ist B1 ein Verlust, und keine Bauprüfung kann das feststellen.

### 3.3.3 Wann eine Frage bestehen bleiben darf

```
Eine Frage f bleibt genau dann als Restfrage bestehen, wenn alle vier
Bedingungen gelten:

  R1  Nach Ausschluss aller Optionen, die eine Invariante verletzen,
      bleiben mindestens zwei Optionen übrig.
  R2  Jede verbliebene Option ist für sich sicher: keine öffnet einen
      Zugang, erzeugt eine unbemerkte Kostenfolge oder verlässt die
      Vorgabe des Default-Deny.
  R3  Die Optionen unterscheiden sich in einer für den Betreiber
      wesentlichen Wirkung: Erreichbarkeit, Kostenfolge, Datenort,
      Aufbewahrung oder Rechtewirkung.
  R4  Die Wahl ist nicht ableitbar: weder aus dem Objektgraphen (B2),
      noch aus einer Richtlinie (B1), noch durch Zusammenlegung (B4),
      noch durch Verschiebung auf einen späteren Zeitpunkt (B3).

Fällt eine der vier Bedingungen weg, ist die Frage durch genau eine
der vier Behandlungen zu beseitigen.
```

R2 ist der Grund, warum "intern oder extern erreichbar" eine zulässige Restfrage ist: Beide Antworten sind sicher, denn die externe Erreichbarkeit schaltet keine Rechte frei, sondern erzeugt eine Veröffentlichung mit eigenem Zugriffskreis, eigenem Zertifikat und eigenem abgeleiteten Regelsatz. R3 ist der Grund, warum "welcher Knoten trägt den Dienst" keine zulässige Restfrage ist: Der Unterschied ist für den Betreiber nicht wesentlich, solange Fehlerzone, Kapazität und Antiaffinität gewahrt bleiben, und genau das leistet die Ableitung.

### 3.3.4 Durchgerechnetes Beispiel: Dienst bereitstellen

Die Aufgabe wirft im Entwurfsstand achtzehn Fragen auf. K-03 nennt für sie drei Entscheidungen; die folgende Zuordnung zeigt, wodurch die übrigen fünfzehn verschwinden.

| Nr. | Potenzielle Frage | Behandlung | Bestimmende Quelle |
|---|---|---|---|
| 1 | Welches Produkt | Restfrage 1 | R1–R4 erfüllt |
| 2 | Welcher Anzeigename | Restfrage 2 | R1–R4 erfüllt |
| 3 | Intern oder extern erreichbar | Restfrage 3 | R1–R4 erfüllt |
| 4 | Auf welchem Knoten | B2 | Platzierung aus Kapazität, Fehlerzone, Antiaffinität und Speicherbindung, mit anzeigbarer Begründung |
| 5 | Welches Ressourcenbudget | B2 | Standardbudget des Katalogeintrags |
| 6 | Welcher Speicherbereich, welche Größe | B2 | Speicherbereichsdefinition des Katalogeintrags |
| 7 | Welche Datensicherheitsstufe | B1 | Richtlinie des Mandanten; das angezeigte RPO folgt aus ihr |
| 8 | Welche Replikationstechnik | B4 | in der Datensicherheitsstufe zusammengelegt |
| 9 | Welcher Hostname | B2 | aus technischem Namen und Basisdomäne nach Namenskonvention |
| 10 | Welche Domäne | B1 | Standarddomäne des Mandanten |
| 11 | Welcher Zertifikatsaussteller | B2 | aus der Sichtbarkeit der Veröffentlichung |
| 12 | Welche Firewallregeln | B2 | aus Veröffentlichung und Netzzone, nicht editierbar |
| 13 | Welche DNS-Einträge mit welcher Sicht | B2 | aus Veröffentlichung und deren Sichtbarkeit |
| 14 | Welche Netzzone | B2 | aus Mandant und Isolationsstufe |
| 15 | Welche Datenbank, welche Zugangsdaten | B2 | aus dem im Katalogeintrag benannten Konnektor; Geheimnis erzeugt, nie angezeigt |
| 16 | Wer darf den Dienst nutzen | B3 | die Zuweisung ist eine eigene Aufgabe an Person oder Gruppe |
| 17 | Welcher Sicherungsplan | B1 | Richtlinie des Mandanten, abgestimmt auf die Datensicherheitsstufe |
| 18 | Wann wird aktualisiert | B3 | die Ausrollung ist ein eigener Vorgang mit Rücksprungpunkt |

Verteilung: B2 neunmal, B1 dreimal, B4 einmal, B3 zweimal, Restfragen dreimal. Die Aufgabe erfüllt damit INV-14 mit genau ausgeschöpftem Budget; ein weiteres Pflichtfeld müsste eine der drei Restfragen verdrängen.

**Wirkung auf die Fehlerwahrscheinlichkeit.** [Kapitel 02](02-problemstellung.md) modelliert die Wahrscheinlichkeit eines fehlerfreien Durchlaufs als (1−p) hoch k über k Schritte. Überträgt man das Modell auf die Entscheidungszahl, ergibt sich:

```
Modell M3-03 (Restfehlerwahrscheinlichkeit)
  P_ok(k) = (1 - p)^k
  Verhältnis bei Senkung von k1 = 18 auf k2 = 3:
    P_ok(3) / P_ok(18) = (1 - p)^(3 - 18) = (1 - p)^-15
```

| p (Annahme) | Faktor (1−p)^−15 |
|---|---|
| 0,02 | 1,35 |
| 0,05 | 2,16 |
| 0,10 | 4,86 |

Das ist ein Modell, keine Messung, und p ist in keinem Kapitel dieses Whitepapers erhoben. Zwei Einschränkungen gelten unabhängig vom Wert von p. Erstens tragen die fünfzehn beseitigten Fragen weiterhin Fehlerrisiko, nur an anderer Stelle: B1 verschiebt es in die Richtlinie, B2 in die Ableitungsregel, B4 in die Abbildungstabelle, B3 in die spätere Aufgabe. Zweitens ist der Fehler in einer Richtlinie oder einer Ableitungsregel nach Modell M3-02 gleichzeitig in allen N Objekten wirksam, während der Fehler in einer Einzelantwort auf ein Objekt beschränkt bleibt.

### 3.3.5 Was die Methode nicht leistet

Die vier Behandlungen lassen keine Entscheidung verschwinden, sie ordnen sie einem anderen Ort, einem anderen Zeitpunkt oder einer anderen Person zu. Der Gewinn entsteht durch Amortisation und durch die Prüfbarkeit des neuen Ortes, nicht durch Einsparung. Für Aufgaben, die fachlich mehr als drei nicht ableitbare, wesentlich unterschiedliche und je für sich sichere Optionen verlangen, hat die Methode keine Lösung; solche Aufgaben sind entweder aufzuteilen, was die Zählung verschiebt, ohne die Last zu senken, oder aus dem Aufgabenkatalog herauszunehmen und als Fachaufgabe zu kennzeichnen. Abschnitt 3.4.1 behandelt diesen Ausweg und seinen Preis.

## 3.4 Spannungsfelder

Die Prinzipien widersprechen einander an fünf Stellen. Jede Auflösung ist eine Entscheidung mit einem Preis, und der Preis wird hier genannt und nicht abgemildert.

### 3.4.1 Einfachheit gegen Funktionsumfang

**Konflikt.** INV-14 begrenzt jede Standardaufgabe auf drei Entscheidungen, während einzelne Aufgaben fachlich mehr unabhängige Angaben verlangen — etwa ein geteiltes Postfach mit Berechtigtenkreis, Sendeberechtigung, Aufbewahrung und mandantenübergreifender Freigabe.

**Abwägung.** Die Regel bindet den Aufgabenkatalog, nicht den Funktionsumfang des Produkts. Eine Funktion, die nach Anwendung aller vier Behandlungen über drei Restfragen bleibt, wird entweder in eine Richtlinie gehoben, zusammengelegt oder ausdrücklich aus dem Aufgabenkatalog genommen und als Fachaufgabe mit benanntem Verfahren geführt. Der Funktionsumfang wird dadurch nicht beschnitten; die Zusage der Entscheidungsarmut gilt aber nur für den Katalog.

**Rechnung zum Spielraum.** Der Entwurfsstand aus K-03 führt neun Aufgaben mit 2, 2, 3, 3, 3, 2, 3, 2 und 2 Entscheidungen.

```
Summe der Entscheidungen    = 2+2+3+3+3+2+3+2+2 = 22
Budget bei 9 Aufgaben       = 9 * 3 = 27
Ausschöpfung               = 22 / 27 = 0,815 = 81,5 %
Verbleibender Spielraum     = 5 Entscheidungen über 9 Aufgaben
```

Deutung: Vier der neun Aufgaben sind bereits ausgeschöpft. Jedes zusätzliche Pflichtfeld in einer dieser Aufgaben muss ein bestehendes verdrängen; ein Wachstum des Katalogs ohne wachsende Ableitungs- und Richtlinienabdeckung ist rechnerisch ausgeschlossen. Die Zahlen sind Entwurfsstand aus dem Kanon, keine Messung an einer gebauten Oberfläche.

**Preis.** Die Katalogzugehörigkeit wird ein steuerndes Objekt: Wer eine Aufgabe aus dem Katalog nimmt, erfüllt die Regel formal und verschlechtert die Bedienbarkeit tatsächlich. Keine Bauprüfung kann eine berechtigte Fachaufgabe von einer entfernten Standardaufgabe unterscheiden, weil beide gleich aussehen. Wirksam bleibt nur eine zweite, nicht automatisierbare Kennzahl: der Anteil der tatsächlich anfallenden Betriebsvorgänge, den der Katalog abdeckt. Für sie existiert kein Messverfahren.

### 3.4.2 Automatik gegen Kontrolle

**Konflikt.** Der Reconciler nimmt Handänderungen zurück (INV-02), während im Störfall die schnellste Abhilfe häufig genau eine Handänderung im Fremdsystem oder auf dem Knoten ist.

**Abwägung.** Die Handänderung ist nicht verboten, sie ist nicht dauerhaft. Wer eine Wirkung dauerhaft will, ändert den Sollzustand; das ist auch im Notfall der einzige Schreibweg (INV-03), allenfalls mit verkürztem Freigabeweg. Dort, wo der Reconciler grundsätzlich nicht hinreicht, entscheidet das deklarierte Feldeigentum (INV-13) und nicht eine Vereinbarung.

**Rechnung zur Lebensdauer einer Handänderung.** Der ereignisgetriebene Pfad macht eine Änderung binnen 30 s sichtbar, der periodische Volllauf erfasst alle abgleichpflichtigen Objekte binnen 60 min (K-16). Eine Handänderung an einem ereignisgedeckten Objekt hat damit eine erwartete Lebensdauer unter einer Minute, an jedem anderen Objekt höchstens eine Stunde. Sie ist als Sofortmaßnahme unbrauchbar, sobald sie länger als diese Frist wirken soll.

**Preis.** Drei Kosten fallen an. Erstens dauert eine Notmaßnahme länger, weil sie ein Vorgang ist. Zweitens kann der Abgleich eine richtige Handänderung durch einen falschen Sollzustand ersetzen; das Ereignis ist auditiert, aber nicht verhindert. Drittens bleibt für den Fall, dass die Kontrollebene selbst der Störungsgegenstand ist, nur der Notzugang mit erzwungener Wartezeit — bewusst langsamer als jeder Handgriff.

### 3.4.3 Vorgabe gegen Anpassbarkeit

**Konflikt.** Jede Richtlinie senkt die Entscheidungszahl im Einzelvorgang und hebt sie bei der Ersteinrichtung; ein mitgeliefertes Richtlinienprofil je Branche verschiebt das Problem nur, weil die Profilwahl selbst zur Fachentscheidung wird.

**Abwägung.** Richtlinien sind Objekte mit Geltung, Version, Erzwingungsart und Begründungspflicht bei Abweichung. Harte Erzwingung gilt dort, wo eine Abweichung eine Invariante brechen würde; weiche Erzwingung dort, wo Abweichung legitim und selten ist — dann bleibt die Frage sichtbar, aber vorbelegt. Die Zahl der bei der Ersteinrichtung zu entscheidenden Richtlinien ist durch K-01 auf das Budget von vier Entscheidungen begrenzt, weshalb alles Weitere entweder abgeleitet oder auf den ersten Vorgang verschoben wird, der es erzwingt.

**Preis.** Ein Profil, das mehrere Richtlinien zusammenfasst, ist eine Anwendung von B4 und entfernt damit Ausdrucksmöglichkeiten aus dem Produkt. Die Höchstzahl mitgelieferter Profile ist im Kanon nicht festgelegt; ohne diese Festlegung ist offen, ob die Profilwahl in das Budget aus K-01 hineinzählt. Ohne mitgelieferte Profile wiederum ist die erste Zuweisung jedes Mandanten eine Folge von Einzelentscheidungen, die die Drei-Entscheidungs-Regel im Einzelvorgang einhält und in der Summe unterläuft.

### 3.4.4 Integrationsbreite gegen Pflegeaufwand

**Konflikt.** Eine Anbindung vieler hundert Fremdsysteme ist ein dauerhaftes Pflegeversprechen, dessen Größe von einer Annahme abhängt und nicht von einer Messung.

**Abwägung.** Es existiert genau ein Konnektorvertrag, und der Zielwert für den Anteil manifestbasierter Konnektoren liegt bei mindestens 90 % (K-22). Ein Codekonnektor ist begründungspflichtig. Als Ventil dient der Zustand "abgekündigt": Bestand läuft weiter, Neuanlage ist gesperrt.

**Empfindlichkeitsrechnung.** K-22 rechnet mit 1.000 Konnektoren, 300 Brüchen im Jahr, Behebung mit 0,25 PT je Manifest und 1,5 PT je Codekonnektor, Grundpflege 0,1 PT je Konnektor und 200 Neuanlagen zu 1,5 PT, insgesamt 512,5 PT/a oder 2,56 Vollzeitäquivalente bei 200 produktiven Tagen. Fällt der Manifestanteil von 90 % auf 70 %, ergibt sich:

```
Behebung   210 * 0,25 + 90 * 1,5   = 52,5 + 135 = 187,5 PT/a
Grundpflege 1.000 * 0,1                        = 100,0 PT/a

Variante A (Neuanlage wie in K-22 durchgehend 1,5 PT):
  Neuanlage 200 * 1,5                          = 300,0 PT/a
  Summe 187,5 + 100 + 300 = 587,5 PT/a = 2,94 VZAE   (Faktor 1,15)

Variante B (Neuanlage im selben Mischungsverhältnis, Codekonnektor
            10 PT wie in der Gegenrechnung von K-22):
  Neuanlage 140 * 1,5 + 60 * 10 = 210 + 600    = 810,0 PT/a
  Summe 187,5 + 100 + 810 = 1.097,5 PT/a = 5,49 VZAE (Faktor 2,14)
```

Deutung: Der Manifestanteil wirkt über die Neuanlage weit stärker als über die Fehlerbehebung. Die Gesamtlast ist damit nicht primär eine Frage der Wartung bestehender Konnektoren, sondern der Frage, ob neue Systeme ohne Code angebunden werden können. Sämtliche Werte sind Annahmen aus dem Kanon beziehungsweise Fortschreibungen davon; gemessen ist keiner.

**Preis.** Der Zielwert von 90 % ist für Fremdsysteme ohne allgemeines Protokoll nicht erreichbar, und jedes solche System zählt gegen ihn. Eine Abkündigung ist zugleich ein gebrochenes Versprechen gegenüber jedem Kunden, der den betroffenen Katalogeintrag produktiv betreibt; die Regel "Bestand läuft weiter" mildert das, beendet es aber nur aufgeschoben.

### 3.4.5 Sicherheit gegen Bedienbarkeit

**Konflikt.** Default-Deny, einmalige Kopplungscodes mit 15 min Gültigkeit, nicht lesbare Geheimnisse, erzwungene Wartezeit im Notbetrieb und eine offline gehaltene Wurzel-CA verteuern jeweils einen legitimen Vorgang.

**Abwägung.** Sicherheitsvorgaben sind dort unverhandelbar, wo eine falsche Voreinstellung still und dauerhaft wirkt: Erreichbarkeit, Rechte, Geheimnispreisgabe, Vertrauensanker. Sie werden dort gelockert, wo der Preis ein wiederholbarer, sichtbarer und risikoarmer Schritt ist — etwa die Neuerzeugung eines abgelaufenen Kopplungscodes.

**Rechnung zur Massenausrollung.** Ein Kopplungscode ist einmalig und höchstens 15 min gültig (INV-27), und die Widerstandsrechnung aus K-14 mit 4,44 × 10⁻¹⁵ Erfolgswahrscheinlichkeit je Code beruht genau darauf. Für die Skalengrenze von 32 Knoten folgt daraus:

```
Annahme: je Maschine 45 s Handhabung (Code erzeugen, ablesen, eingeben)
         und 120 s Weg zwischen Konsole und Maschine
32 Maschinen * (45 s + 120 s) = 32 * 165 s = 5.280 s = 88 min
```

Deutung: Die unbeaufsichtigte Ausrollung ist keine Bequemlichkeit, sondern rechnerisch notwendig, sobald mehr als eine Handvoll Maschinen aufgenommen wird. Deshalb existiert das einmalig verwendbare, kurzlebige Aufnahmetoken mit Pinning des CA-Fingerabdrucks als ausdrücklich schwächeres, gekennzeichnetes und auditiertes Verfahren. Die Annahmen 45 s und 120 s sind gesetzt, nicht gemessen.

**Preis.** Es existiert ein bewusst eingebautes, benanntes schwächeres Verfahren. Sein Sicherheitsniveau hängt an der Vertrauenswürdigkeit des Auslieferungskanals, die Atrium nicht prüfen kann, sondern nur vom Kunden erklären lässt. Zusätzlich erzeugt die Sicherheitsvorgabe regelmäßig Fehlermeldungen, die keine Störung sind — ein abgelaufener Code ist der häufigste Fall —, und jede solche Meldung ist eine Gelegenheit, die Regel als Schikane zu erleben.

## 3.5 Was das Zielbild ausdrücklich nicht ist

| Abgrenzung | Was stattdessen gilt | Beobachtbare Grenze |
|---|---|---|
| **Kein Baukasten für beliebige Architekturen** | Die Bauteile sind abgezählt: Stimmzahl 1, 3 oder 5; drei Datensicherheitsstufen; vier Isolationsstufen; eine feste Menge von Knotenrollen. Wer eine andere Architektur will, baut sie nicht in Atrium, sondern neben Atrium | Die API kennt keine Operation, die eine neue Knotenrolle, eine neue Replikationstechnik oder eine fünfte Isolationsstufe anlegt |
| **Keine Entwicklungsplattform** | Eigene Software tritt als Katalogeintrag ein: signiert, mit Speicherbereichsdefinition, Datenklassenvorgabe, Produktgrenzdeklaration und Migrationsschritten. Bauen, Prüfen und Veröffentlichen von Software findet außerhalb statt | Es existiert keine Operation, die ein unsigniertes Abbild in Betrieb nimmt |
| **Kein Hypervisor-Ersatz** | Virtuelle Maschinen sind Unterlage, nicht Gegenstand. Atrium verwaltet Knoten, Dienste, Speicherbereiche und Netzzonen, keine Gastsysteme fremder Virtualisierung | Das Objektmodell enthält keine Entität für ein Gastsystem; die kleinste verwaltete Maschine ist der Knoten |
| **Kein Ersatz für Fachwissen bei Sonderfällen** | Eine Aufgabe außerhalb des Katalogs bleibt eine Fachaufgabe. Die Plattform senkt die Häufigkeit solcher Fälle, nicht ihre Schwierigkeit | Der Aufgabenkatalog ist endlich, versioniert und benennt, was er nicht enthält |
| **Kein Nachbau fremder Fachfunktionen** | Die fachliche Einrichtung eines Fremdprodukts bleibt in dessen Oberfläche und ist je Katalogeintrag deklariert (P-10) | Jeder Katalogeintrag trägt eine Grenzdeklaration; ohne sie wird er nicht freigegeben |

Die weiteren Nichtziele — Sicherheitsgarantie für fremde Software, Schutz vor berechtigter Fehlbedienung, rechtliche Bewertung der Datenhaltung, Wiederherstellung ohne Sicherung — sind in [Kapitel 02](02-problemstellung.md) begründet und gelten unverändert.

## 3.6 Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| **R-03-01** | Der Aufgabenkatalog liegt maschinenlesbar vor; für jede geführte Aufgabe zählt der Bau höchstens drei Pflichtfelder ohne mögliche Vorbelegung | P-01, INV-14, K-03 |
| **R-03-02** | Jedes vorbelegte oder nicht gestellte Feld einer Katalogaufgabe trägt genau eine der Behandlungen B1 bis B4 mit Quellenangabe; Felder ohne Zuordnung brechen den Bau | P-01, INV-15 |
| **R-03-03** | Jede Restfrage ist im versionierten Restfragenkatalog mit der Begründung nach R1 bis R4 geführt; eine Restfrage ohne vollständige Begründung bricht den Bau | P-01 |
| **R-03-04** | Die API-Beschreibung enthält 0 Schreiboperationen auf abgeleitete Artefakte, und jedes abgeleitete Artefakt trägt einen Verweis auf genau eine Quelle | P-02, INV-09 |
| **R-03-05** | Eine Handänderung an einer erzeugten Datei ist nach dem nächsten Abgleich zurückgenommen und erzeugt ein Auditereignis vom Typ "Abweichung korrigiert" | P-02, INV-02 |
| **R-03-06** | Zweimaliges Anwenden desselben Sollzustands erzeugt 0 Änderungsereignisse, 0 Neustarts und 0 Auditereignisse vom Typ "geändert" | P-03, INV-07 |
| **R-03-07** | Jedes Konnektormanifest nennt das benutzte Standardprotokoll mit Fundstelle oder begründet den Sonderweg schriftlich; der Anteil manifestbasierter Bindungen beträgt mindestens 90 % | P-04, K-22 |
| **R-03-08** | Nach abgebrochener Regelanwendung ist die Zahl erreichbarer Dienste ohne deckende Veröffentlichung 0; jeder Konnektorprozess scheitert am Zugriff auf eine nicht gelistete Adresse | P-05, INV-10, INV-21 |
| **R-03-09** | Kein Meldungstext nennt einen Befehl oder Dateipfad als einzigen Lösungsweg; jede Meldung verweist auf genau einen Weg in der Oberfläche und auf das verursachende Objekt | P-06, INV-17 |
| **R-03-10** | Der vollständige Aufgabenkatalog läuft ohne Terminalzugriff durch, und `atriumctl` benutzt ausschließlich die öffentlich dokumentierte API | P-06, INV-26, INV-01 |
| **R-03-11** | Jede Aktion des Aufgabenkatalogs ist im Test entweder zurückgenommen worden oder trägt die Kennzeichnung "nicht rücknehmbar" mit benanntem Grund; Massenaktionen mit nicht rücknehmbaren Anteilen führen eine Einzelaufstellung | P-07, INV-11 |
| **R-03-12** | Die Auditkette wird bei jedem Start und bei jedem Export geprüft und weist 0 Lücken auf; Ereignisse gelöschter Objekte bleiben abrufbar; 0 API-Operationen ändern oder löschen Auditereignisse | P-08, INV-23 |
| **R-03-13** | Jede Ansicht eines Istzustands nennt den Zeitpunkt der letzten Beobachtung und die Abweichung zum Sollzustand; ein Vorgang mit ausstehendem Zielsystem erreicht nie den Ausgang "abgeschlossen" | P-09, INV-28, INV-12 |
| **R-03-14** | 100 % der freigegebenen Katalogeinträge tragen eine Produktgrenzdeklaration; jede darin benannte Fremdoberfläche ist am Dienst verlinkt; der Reconciler schreibt 0 Felder außerhalb des deklarierten Eigentums | P-10, INV-30, INV-13 |
| **R-03-15** | Die Prüfung aller Oberflächentexte, Fehlermeldungen, Berichte und Einträge der Fremdfehlerübersetzung gegen die Begriffs-Positivliste ergibt 0 Treffer | P-11, INV-16 |
| **R-03-16** | Der Sollzustandsexport enthält 0 Nutzdaten und 0 Geheimnisse im Klartext; die Ausgabeprüfung gegen Geheimnismuster in Protokollen und Exporten ergibt 0 Treffer | P-12, INV-20 |
| **R-03-17** | Es besteht 0 ausgehende Verbindung, die nicht auf eine Konnektorbindung oder ein anderes Objekt zurückführbar ist; Telemetrieausleitung existiert nur als eingerichtete Bindung | P-12, INV-21 |
| **R-03-18** | Nach Trennung vom Internet arbeiten Anmeldung, interne Namensauflösung, interne Zertifikatserneuerung, Dienststart und vollständiger Abgleich weiter; jede extern abhängige Funktion ist in der Konsole einzeln benannt | P-13, INV-25 |
| **R-03-19** | Im jährlichen Austrittstest rekonstruiert eine unbeteiligte Person aus Export und Nutzdatensicherung ohne laufendes Atrium Personen, Mailadressen, DNS-Einträge und Veröffentlichungen vollständig | P-14, K-24 |
| **R-03-20** | Das Entfernen einer Konnektorbindung bietet die Wahl zwischen Deaktivierung und Belassen der Fremdkonten; bei der Wahl "belassen" bleiben 100 % der betroffenen Fremdkonten nutzbar | P-14 |
| **R-03-21** | Nach vollständiger Abschaltung der Kontrollebene und anschließendem Knotenneustart laufen zuvor erfolgreich gestartete Dienste wieder; auf der Minderheitsseite einer Partition finden 0 Lösch- und 0 Verlagerungsoperationen statt | P-15, INV-25, INV-04 |
| **R-03-22** | Ein `plan`-Aufruf lässt den Istzustand des Fremdsystems unverändert; jede deklariert kostenwirksame Aktion erscheint vor der Bestätigung in der Wirkungsvorschau; die Vorschau erreicht p95 höchstens 2 s | P-16, INV-08, INV-29, K-18 |
| **R-03-23** | Die automatisierte Barrierefreiheitsprüfung meldet 0 Verstöße gegen WCAG 2.2 Stufe AA, und der Tastaturdurchlauf erreicht 100 % der Aufgaben des Aufgabenkatalogs ohne Zeigegerät | P-17, INV-31 |
| **R-03-24** | Jede Richtlinie zeigt vor Aktivierung und vor jeder Versionsänderung die Menge der betroffenen Objekte; die Aktivierung ohne diese Anzeige ist in der API nicht möglich | P-16, INV-08 |
| **R-03-25** | Jede Vorbelegung ist ausschließlich an ihrer Quelle korrigierbar; es existiert 0 Formularfeld, das einen abgeleiteten Wert überschreibt, ohne das bestimmende Objekt zu ändern | P-02, INV-15 |
| **R-03-26** | Jede Freigabe weist die Summe der Entscheidungen über den gesamten Aufgabenkatalog aus; ein Anstieg gegenüber der Vorgängerfreigabe ist schriftlich zu begründen | P-01, K-03 |

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Der Abgleich der Formulardefinitionen gegen die Aufgabendefinitionen zählt je Aufgabe höchstens drei Pflichtfelder ohne mögliche Vorbelegung | R-03-01 | Statische Prüfung im Bau; ein zusätzliches Pflichtfeld bricht den Bau (K-03) |
| Für 100 % der vorbelegten und nicht gestellten Felder liegt eine Behandlungsangabe B1 bis B4 mit benannter Quelle vor | R-03-02 | Auswertung der Formulardefinitionen gegen den Behandlungsindex (INV-15) |
| Der Restfragenkatalog enthält für jede Restfrage die Prüfung der Bedingungen R1 bis R4 | R-03-03 | Freigabeprüfung des Katalogs; unvollständige Begründung bricht den Bau |
| Die statische Auswertung der API-Beschreibung ergibt 0 Schreiboperationen auf abgeleitete Artefakte | R-03-04, R-03-25 | API-Fassadentest im Bau (INV-09) |
| Nach Handänderung an jeder erzeugten Datei stellt der nächste Abgleich den vorherigen Inhalt her und schreibt das zugehörige Auditereignis | R-03-05 | Abweichungstest über alle erzeugten Dateiarten (INV-02) |
| Der Doppellauf desselben Sollzustands erzeugt 0 Änderungsereignisse und 0 Neustarts | R-03-06 | Idempotenztest im Bau (INV-07) |
| Jedes ausgelieferte Konnektormanifest trägt eine Protokollangabe mit Fundstelle oder eine Sonderwegbegründung; der Manifestanteil liegt bei mindestens 90 % | R-03-07 | Manifestprüfung bei der Freigabe; Auszählung über den Auslieferungsstand (K-22) |
| Der Erreichbarkeitstest nach abgebrochener Regelanwendung findet 0 ungedeckte erreichbare Dienste; der Netznamensraumtest weist jeden Zugriff auf eine nicht gelistete Adresse ab | R-03-08 | Fehlerinjektion beim Regeltausch; Netznamensraumtest je Konnektorprozess (INV-10, INV-21) |
| Die Musterprüfung aller Meldungstexte findet 0 Befehls- und 0 Dateipfadangaben als einzigen Lösungsweg, und 100 % der Meldungen verlinken das verursachende Objekt | R-03-09 | Textprüfung im Bau (K-27, K-26) |
| Der automatisierte Konsolendurchlauf absolviert den vollständigen Aufgabenkatalog ohne Terminalzugriff | R-03-10 | Aufgabenkatalogdurchlauf gegen die öffentliche API (INV-26) |
| Für 100 % der Katalogaktionen liegt eine ausgeführte Rücknahme oder eine Kennzeichnung "nicht rücknehmbar" mit Grund vor | R-03-11 | Rücknahmetest je Aktion (INV-11) |
| Die Kettenprüfung bei Start und Export ergibt 0 Lücken, und die Ereignisse eines gelöschten Objekts sind weiterhin abrufbar | R-03-12 | Kettenprüfung und Löschtest (INV-23) |
| Der Darstellungstest findet 0 Istansichten ohne Beobachtungszeitpunkt; die Fehlerinjektion in einen von mehreren Konnektoren ergibt den Ausgang "teilweise fehlgeschlagen" mit benanntem Rest | R-03-13 | Darstellungstest und Konnektorfehlerinjektion (INV-28, INV-12) |
| 100 % der freigegebenen Katalogeinträge tragen eine Grenzdeklaration, und der Reconciler schreibt im Vertragstest 0 fremdbesessene Felder | R-03-14 | Freigabeprüfung je Katalogeintrag; Vertragstest je Konnektor (INV-30, INV-13) |
| Die Begriffsprüfung über Oberflächentexte, Meldungen, Berichte und Übersetzungstabelle ergibt 0 Treffer | R-03-15 | Positivlistenprüfung im Bau (K-27) |
| Der Sollzustandsexport enthält 0 Nutzdatenobjekte, und die Geheimnismusterprüfung über Protokolle und Exporte ergibt 0 Treffer | R-03-16 | Ausgabeprüfung im Bau (INV-20, K-12) |
| Im Trennungstest besteht 0 ausgehende Verbindung ohne Objektbezug | R-03-17 | Verbindungsmitschnitt im Trennungstest (INV-21) |
| Nach Trennung vom Internet laufen Anmeldung, interne Namensauflösung, interne Zertifikatserneuerung, Dienststart und vollständiger Abgleich weiter | R-03-18 | Trennungstest über mindestens einen vollständigen Abgleichzyklus (INV-25) |
| Eine unbeteiligte Person rekonstruiert im Austrittstest Personen, Mailadressen, DNS-Einträge und Veröffentlichungen vollständig ohne laufendes Atrium | R-03-19 | Jährlicher Austrittstest mit Abweichungszählung gegen den Sollzustand (K-24) |
| Nach dem Entfernen einer Bindung mit der Wahl "belassen" sind 100 % der betroffenen Fremdkonten weiter nutzbar | R-03-20 | Anmeldetest je Zielsystem nach Entfernen der Bindung |
| Nach Abschaltung der gesamten Kontrollebene und Knotenneustart laufen die Dienste wieder; die Minderheitsseite führt 0 Löschungen aus | R-03-21 | Abschalttest und Partitionstest (INV-25, INV-04) |
| Der Istzustand des Fremdsystems ist nach jedem `plan`-Aufruf unverändert, und jede deklariert kostenwirksame Aktion erscheint in der Vorschau | R-03-22, R-03-24 | Konnektor-Vertragstest mit Zustandsvergleich (INV-08, INV-29) |
| Die Barrierefreiheitsregelprüfung meldet 0 Verstöße, und der Tastaturdurchlauf erreicht 100 % der Katalogaufgaben | R-03-23 | Automatisierte Prüfung plus Tastaturdurchlauf im Bau (INV-31, K-27) |
| Jede Freigabe weist die Entscheidungssumme des Aufgabenkatalogs aus, und jeder Anstieg trägt eine schriftliche Begründung | R-03-26 | Freigabeprotokoll gegen den Vorgängerstand (K-03) |

## Offene Punkte

1. **Der Widerspruch zwischen INV-06 und INV-25 ist nicht kanonisch aufgelöst.** Fällt die gesamte Kontrollebene aus, besitzt kein Knoten eine gültige Lease; die wörtliche Anwendung von INV-06 beendet dann alle Arbeitslasten und widerspricht INV-25. P-15 folgt der Lesart, dass die Lease nur übernahmefähige, exklusiv gebundene Arbeitslasten abschottet. Zu entscheiden ist, ob diese Einschränkung in den Kanon aufgenommen wird, welche Arbeitslastarten als exklusiv gebunden gelten und wie ein Knoten diese Unterscheidung ohne Kontrollebene selbst trifft.
2. **Für die Restfragenbedingung fehlt ein Schiedsverfahren und ein Maß für "wesentlich".** R3 verlangt eine für den Betreiber wesentliche Wirkungsdifferenz, ohne sie zu operationalisieren, und über die Erfüllung von R1 bis R4 entscheidet derzeit dieselbe Rolle, die das Formular entwirft. Ohne unabhängige Prüfung ist die Regel selbstbestätigend. Zu entscheiden ist, wer den Restfragenkatalog freigibt und welche Wirkungsklassen (Erreichbarkeit, Kosten, Datenort, Aufbewahrung, Rechtewirkung) abschließend als wesentlich gelten.
3. **Die Drei-Entscheidungs-Regel lässt sich durch Schrumpfen des Aufgabenkatalogs formal erfüllen.** R-03-01 und R-03-26 prüfen nur den Katalog, nicht seine Abdeckung. Zu entscheiden ist, ob eine zweite Kennzahl eingeführt wird, die den Anteil der tatsächlich anfallenden Betriebsvorgänge misst, die der Katalog abdeckt, und mit welchem Erhebungsverfahren — ohne eine solche Kennzahl bleibt die Zusage der Entscheidungsarmut manipulierbar.
4. **Zeitquellen im abgeschotteten Betrieb.** K-30 verlangt mindestens zwei gesicherte externe Zeitquellen, P-13 verlangt vollständige Funktion ohne Internetanbindung, und INV-32 macht die Zeitgüte zur Bedingung für Führung, Zertifikatsausstellung und Auditschreiben. Zu entscheiden ist, ob eine lokale Hardwarezeitquelle vorgeschrieben wird, ob im abgeschotteten Betrieb eine abgeschwächte Zeitzusage gilt und wie die Konsole diesen Unterschied benennt.
5. **Höchstzahl und Zuschnitt mitgelieferter Richtlinienprofile.** Modell M3-01 begründet, wann eine Richtlinie lohnt, nicht wie viele Profile mitgeliefert werden dürfen; K-01 begrenzt die Ersteinrichtung auf vier Entscheidungen, ohne zu klären, ob die Profilwahl dazuzählt. Zu entscheiden sind beide Punkte gemeinsam, da jedes zusätzliche Profil eine Anwendung von B4 ist und Ausdrucksmöglichkeiten entfernt.
6. **Unübersetzbare Fremdfehler.** Ein Fehler aus einem neu angebundenen System kann erst nach seinem ersten Auftreten übersetzt werden; der Rückfall auf eine gekennzeichnete Fremdmeldung erfüllt INV-16 nicht. Zu entscheiden ist, ob die Bauprüfung diesen Rückfall als zulässigen Zustand akzeptiert, welche Ersatzaussage die Konsole stattdessen trifft und binnen welcher Frist eine Übersetzung nachzuliefern ist.
7. **Der Austrittstest ist ohne definiertes Zielformat nicht falsifizierbar.** R-03-19 verlangt eine Rekonstruktion, ohne je Objektklasse zu benennen, in welcher Form das Ergebnis vorliegen muss. Zu entscheiden ist, für welche Objektklassen ein allgemein lesbares Ausgabeformat verbindlich ist und welche Klassen ausdrücklich nur im Atrium-eigenen Modell exportiert werden — andernfalls ist die Austrittszusage nicht prüfbar.
8. **Datensparsamkeit gegen Nachweispflicht.** P-12 verlangt Minimierung, P-08 verlangt einen unveränderlichen Nachweis, der das Objekt überlebt, und K-25 setzt zwölf Monate vollständige Aufbewahrung des Auditstroms. Ein Löschverlangen nach der Verordnung (EU) 2016/679 trifft damit auf eine Kette, die nicht änderbar ist. Zu entscheiden ist, welche personenbezogenen Felder überhaupt in Auditereignisse gelangen, ob eine Pseudonymisierung mit getrennt löschbarer Zuordnungstabelle eingeführt wird und wie [Kapitel 22](22-compliance.md) das gegenüber der Unveränderlichkeitszusage aus INV-23 vertritt.
