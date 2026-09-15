# 02 Problemstellung

## 2.1 Messgrößen und Referenzfall

Die folgende Analyse arbeitet mit sechs Begriffen, die hier so definiert werden, dass die Aussagen dieses Kapitels nachzählbar sind. Ohne diese Definitionen wäre jede Zahl über "Komplexität" eine Meinung.

| Begriff | Definition | Erhebung |
|---|---|---|
| **Konfigurationsfläche** | Ein abgegrenzter Ort, an dem ein Mensch eine Änderung schreibt und der ein eigenes Vokabular, ein eigenes Rechtemodell, ein eigenes Anwendungsverfahren und eine eigene Fehlerausgabe besitzt | Aufzählung je Referenzfall |
| **Tatsache** | Eine Aussage über die Welt des Betreibers, die an mehr als einer Stelle wirksam werden muss, etwa "Person P ist beschäftigt" oder "Dienst D ist unter Name N erreichbar" | Aufzählung je Referenzfall |
| **Konsistenzpaar** | Ein ungeordnetes Paar von Konfigurationsflächen, die dieselbe Tatsache halten und die auseinanderlaufen können | Rechnung aus der Flächenzahl |
| **Vorgangsschritt** | Eine Handlung eines Menschen, die einen Zustand ändert oder eine Eingabe festlegt; Lesen und Nachschlagen zählen nicht | Zählung im Ablauf |
| **Abstraktionsleckage** | Eine Frage an den Bediener, deren Antwort eine Aussage über die Implementierung ist und nicht über die Welt des Betreibers | Prüfung je Frage |
| **Rückkopplung** | Die Anzeige, ob und wann eine geäußerte Absicht am Wirkort eingetreten ist, mit Beobachtungszeitpunkt | Vorhandensein und Latenz |

**Referenzfall A (Annahme, kein Erhebungsergebnis):** ein Betrieb mit 500 Personen, 800 Geräten, extern gehostetem Postfachdienst, einem Ticketsystem, zwei weiteren Fachanwendungen, einer eigenen Domäne und einem Standort. Die Objektzahlen entsprechen der Annahme aus K-12, damit die Rechnungen dieses Kapitels und die Kennzahlen des Kanons auf demselben Fall stehen.

Im Referenzfall A sind folgende Konfigurationsflächen zu bedienen. Die Liste ist eine Aufzählung typischer Bestandteile, keine Erhebung an einer realen Installation.

| Nr. | Fläche | Eigenes Vokabular für dieselbe Sache |
|---|---|---|
| 1 | Betriebssystem-Benutzerverwaltung | Benutzer, Gruppe, Shell, Home |
| 2 | Verzeichnisdienst | Eintrag, Attribut, Organisationseinheit |
| 3 | Identitätsanbieter für Anmeldungen | Subjekt, Anspruch, Bereich, Anwendung |
| 4 | Postfachanbieter | Postfach, Alias, Verteilergruppe, Lizenz |
| 5 | Autoritativer DNS beim Registrar | Eintrag, Zone, Seriennummer |
| 6 | Interner Resolver | Weiterleitung, Sicht, Zwischenspeicher |
| 7 | Paketfilter | Kette, Regel, Richtung, Schnittstelle |
| 8 | Eingangsproxy | Server, Standort, Ziel, Kopfzeile |
| 9 | Zertifikatsbezug | Konto, Auftrag, Nachweis, Schlüsselpaar |
| 10 | Ticketsystem | Agent, Kunde, Warteschlange, Rolle |
| 11 | Datenbank | Rolle, Schema, Recht, Verbindung |
| 12 | Fachanwendung 2 | eigene Begriffe |
| 13 | Sicherungswerkzeug | Auftrag, Aufbewahrung, Ablage |
| 14 | Netzzugang und Switch | Port, VLAN, Authentisierung |
| 15 | Endgeräteverwaltung | Profil, Richtlinie, Registrierung |

Damit ist n = 15 die Zahl der Konfigurationsflächen im Referenzfall A. Diese Zahl ist der Ausgangspunkt der folgenden Rechnungen; sie ist eine Annahme und keine Messung.

## 2.2 Zerlegung der Komplexität in fünf Ursachen

| ID | Ursache | Beobachtbares Merkmal | Unmittelbare Wirkung |
|---|---|---|---|
| **U1** | Zahl unabhängiger Konfigurationsflächen | n > 1 Orte, an denen geschrieben wird, ohne gemeinsame Schreibregel | Lernaufwand, Rechtefragmentierung, keine gemeinsame Auswirkungsliste |
| **U2** | Fehlende gemeinsame Datenbasis | Dieselbe Tatsache ist in mehreren Flächen unabhängig schreibbar | Divergenz, Mehrfachpflege, kein vollständiger Rückbau |
| **U3** | Abstraktionsleckage | Fragen an den Bediener, die nur mit Implementierungswissen zu beantworten sind | Abbruch, Zufallsantwort, dauerhafte Fehlkonfiguration |
| **U4** | Werkzeugvielfalt | Je Fläche eigene Syntax, eigene Anwendungssemantik, eigene Protokollform | Sinkende Übungsfrequenz je Fläche, steigende Nachschlagezeit |
| **U5** | Fehlende Rückkopplung zwischen Absicht und Wirkung | Kein Ort nennt, ob die Absicht am Wirkort eingetreten ist, und wann das beobachtet wurde | Späte Fehlererkennung, Vertrauen in grüne Anzeigen ohne Aussagekraft |

Die fünf Ursachen sind nicht unabhängig, sondern verstärken sich: U1 erzeugt U2 zwangsläufig, sobald dieselbe Tatsache in zwei Flächen gebraucht wird, und U4 ist die Folge davon, dass die n Flächen aus n Projekten unterschiedlicher Herkunft stammen. U3 und U5 sind demgegenüber eigenständig und ließen sich auch bei n = 1 verletzen.

### 2.2.1 U2 quantifiziert: eine Tatsache in n Systemen

```
Modell M1 (Konsistenzpaare)
  Halten n Konfigurationsflächen dieselbe Tatsache und ist keine davon
  gegenüber den anderen autoritativ, dann ist die Zahl der Paare,
  zwischen denen Divergenz auftreten kann:
        K(n) = n * (n - 1) / 2
  Existiert genau eine Wahrheitsquelle und werden alle n Flächen aus ihr
  abgeleitet, dann ist die Zahl der zu wahrenden Beziehungen:
        K_stern(n) = n
  Verhältnis:  K(n) / K_stern(n) = (n - 1) / 2
```

| n | K(n) Konsistenzpaare | K_stern(n) | Verhältnis |
|---|---|---|---|
| 3 | 3 | 3 | 1,0 |
| 6 | 15 | 6 | 2,5 |
| 10 | 45 | 10 | 4,5 |
| 15 | 105 | 15 | 7,0 |

Das ist ein Modell, keine Messung. Es sagt nicht, wie oft Divergenz eintritt, sondern an wie vielen Stellen sie eintreten kann. Bei n = 3 ist der Gewinn rechnerisch null; der Unterschied ist dort qualitativ, weil die drei Beziehungen im Sternfall gerichtet und maschinell erzwingbar sind, im Vollgraph dagegen symmetrisch und nur durch Absprache gehalten. Ab n = 6 wird der Unterschied auch quantitativ deutlich, und n = 6 ist für die Tatsache "Person P ist beschäftigt" im Referenzfall A bereits die Untergrenze: Betriebssystem, Verzeichnisdienst, Identitätsanbieter, Postfachanbieter, Ticketsystem, Datenbank.

Die Paarzahl multipliziert sich mit der Zahl der Instanzen. Für 500 Personen und n = 6 ergeben sich 500 × 15 = 7.500 Konsistenzpaare allein für diese eine Tatsachenart, ohne Geräte, Namen, Zertifikate und Berechtigungen. Diese Zahl ist der Grund, warum die bewusste Doppelpflege bei drei Systemen noch trägt und bei sechs nicht mehr.

### 2.2.2 Fehlermodell für mehrschrittige Vorgänge

```
Modell M2 (fehlerfreier Durchlauf)
  Ein Vorgang besteht aus k Schritten. Jeder Schritt misslingt unabhängig
  mit Wahrscheinlichkeit p (Eingabefehler, Auslassung, Verwechslung).
        P(fehlerfrei) = (1 - p)^k
        P(mindestens ein Fehler) = 1 - (1 - p)^k
  Annahme: Unabhängigkeit der Schritte. Sie ist konservativ zugunsten des
  Ist-Zustands, denn Ermüdung und Routineunterbrechung korrelieren
  Fehler positiv und senken P(fehlerfrei) zusätzlich.
```

Rechenergebnisse für P(fehlerfrei) in Prozent:

| k \ p | 0,005 | 0,01 | 0,02 | 0,05 |
|---|---|---|---|---|
| 5 | 97,52 | 95,10 | 90,39 | 77,38 |
| 10 | 95,11 | 90,44 | 81,71 | 59,87 |
| 20 | 90,46 | 81,79 | 66,76 | 35,85 |
| 40 | 81,83 | 66,90 | 44,57 | 12,85 |
| 80 | 66,96 | 44,75 | 19,86 | 1,65 |

Der Wert von p ist eine Annahme; für menschliche Routineschritte unter Zeitdruck liegt kein Messwert vor, der hier zitiert werden dürfte. Die Aussage des Modells hängt nicht am genauen p, sondern an der Form: P(fehlerfrei) fällt exponentiell in k. Eine Halbierung von p bringt denselben Gewinn wie eine Halbierung von k nur näherungsweise und nur für kleine p, während k in der Praxis um Größenordnungen veränderlich ist und p nicht.

```
Modell M3 (Wirkung der Automatisierung)
  Ein Vorgang mit k_h menschlichen Schritten wird ersetzt durch einen
  Vorgang mit k_e Entscheidungen; die übrigen k_h - k_e Schritte werden
  abgeleitet.
        Fehlerrate vorher  = 1 - (1 - p)^k_h
        Fehlerrate nachher = 1 - (1 - p)^k_e
  Beispiel k_h = 40, k_e = 3, p = 0,01:
        vorher  = 1 - 0,6690 = 33,10 %
        nachher = 1 - 0,9703 =  2,97 %
        Verhältnis 11,1
```

Der strukturelle Teil dieser Aussage liegt nicht im Faktor 11,1, sondern in der Natur der verbleibenden Fehler. Ein menschlicher Schritt misslingt bei jedem Durchlauf neu und unabhängig; ein abgeleiteter Schritt misslingt entweder systematisch oder gar nicht. Ein systematischer Fehler ist einmal auffindbar, einmal reparierbar und danach für alle Durchläufe behoben, ein unabhängiger Fehler nicht. Automatisierung senkt deshalb nicht nur den Erwartungswert der Fehlerzahl, sondern verschiebt die Fehlerart von einer Zufallsgröße je Durchlauf zu einer prüfbaren Eigenschaft des Systems. Genau darauf zielen INV-07 (Idempotenz) und die Bauprüfungen aus K-27.

Über ein Jahr gerechnet, mit der Annahme m = 200 Durchläufen eines Standardvorgangs: 200 × 33,10 % = 66,2 fehlerhafte Durchläufe gegenüber 200 × 2,97 % = 5,9. Wird zusätzlich eine Entdeckungswahrscheinlichkeit d = 0,5 angenommen, bleiben im ersten Fall 33,1 unentdeckte fehlerhafte Durchläufe pro Jahr. Diese unentdeckten Reste sind der Bestand, aus dem die Fehlerklassen in Abschnitt 2.3 entstehen.

### 2.2.3 Drift als Folge von U2 und M2 zusammen

```
Modell M4 (Divergenz je Pflegerunde)
  Eine Tatsache liegt in n Flächen. Bei einer Änderung sind n - 1
  Kopierschritte nötig, jeder mit Fehlerwahrscheinlichkeit p.
        P(alle Kopien stimmen) = (1 - p)^(n - 1)
  Über r Pflegerunden ohne jede Korrektur:
        P(nach r Runden noch überall gleich) = (1 - p)^((n - 1) * r)
```

Rechenergebnisse bei p = 0,01: n = 6 ergibt 0,99⁵ = 95,10 %, also 4,90 % Divergenz je Runde; n = 10 ergibt 0,99⁹ = 91,35 %, also 8,65 %. Über r = 12 Runden bei n = 10 ergibt sich 0,99¹⁰⁸ = 33,77 %, also 66,2 % der Tatsachen mit mindestens einer Abweichung. Der letzte Wert ist eine Obergrenze und ausdrücklich unrealistisch scharf, weil er jede Korrektur ausschließt; in der Praxis wird ein Teil der Abweichungen bei Störungen gefunden und behoben. Die belastbare Aussage ist die Richtung: ohne Wahrheitsquelle wächst der Anteil divergenter Tatsachen monoton mit der Zeit, und die Korrektur ist ein ungeplanter, störungsgetriebener Nebenprozess statt einer Systemeigenschaft. Der Kanon beantwortet das mit INV-02 und INV-03.

### 2.2.4 U3: Abstraktionsleckage und ihr Abgrenzungskriterium

Eine Frage an den Bediener ist genau dann zulässig, wenn ihre Antwort eine Aussage über die Welt des Betreibers ist. Sie ist eine Leckage, wenn ihre Antwort eine Aussage über die gewählte Implementierung ist. Dieses Kriterium ist scharf genug für die überwiegende Zahl der Fälle und an Grenzfällen nicht scharf; die Schwäche wird in den Offenen Punkten benannt.

| Typische Frage | Dahinterliegendes Detail | Warum der Bediener sie nicht beantworten kann | Woraus ableitbar |
|---|---|---|---|
| "Welche TTL?" | Zwischenspeicherverhalten rekursiver Resolver | Die Antwort hängt von Änderungsfrequenz und Umschaltszenarien ab, die nirgends erfasst sind | Sichtzugehörigkeit des DNS-Eintrags, vgl. [Kapitel 12](12-dns-netzwerk.md) |
| "DNSSEC aktivieren?" | Signaturkette, Schlüsselwechsel, DS-Eintrag beim Registrar | Der Nutzen ist nicht sichtbar, das Ausfallrisiko bei Fehlbedienung ist total | Zustand der Domäne, automatisch |
| "Welches Replikationsverfahren?" | Blockreplikation gegen Dateisystemreplikation | Die Wahl folgt aus Knotenzahl und geforderter Wiederanlaufgüte, nicht aus einer Präferenz | Datensicherheitsstufe, vgl. Festlegung 3b des Kanons |
| "Bridge- oder Host-Netz?" | Netzmodell der Ausführungsumgebung | Der Begriff hat außerhalb der Ausführungsumgebung keine Bedeutung | Netzzone des Dienstes |
| "Welche Chiffrensuiten?" | Verhandlungsparameter der Transportverschlüsselung | Eine falsche Antwort ist weder sofort noch später sichtbar | Kryptographische Grundzusage der Plattform |
| "Reverse-Proxy-Regel eintragen?" | Routingtabelle der Datenebene | Die Regel ist eine Folge der Erreichbarkeitsabsicht, nicht deren Ausdruck | Veröffentlichung, INV-09 |
| "Firewall-Port freigeben?" | Paketfilterkette | Der Bediener will einen Dienst erreichbar machen, nicht ein Paket passieren lassen | Veröffentlichung, INV-10 |

Legitim bleiben dagegen Fragen wie "soll dieser Dienst aus dem Internet erreichbar sein" oder "wer darf darauf zugreifen": beide Antworten sind Aussagen über den Betrieb. Die Grenze ist an einer Stelle unscharf, nämlich dort, wo eine betriebliche Entscheidung nur in technischer Sprache formulierbar ist; der Entwurf löst das nicht allgemein, sondern je Fall durch Umformulierung in eine Absichtsfrage.

### 2.2.5 U4: Werkzeugvielfalt, gerechnet als Übungsfrequenz

```
Modell M5 (Lernposten)
  Je Konfigurationsfläche sind sechs Dimensionen zu lernen: Vokabular,
  Eingabeform, Anwendungssemantik, Rechtemodell, Fehlerausgabe,
  Sicherungsverfahren.
        L = n * 6
  Referenzfall A, n = 15:            L = 90
  Nach Zusammenfassung der zwölf
  infrastrukturellen Flächen auf eine
  und Belassung dreier Fachoberflächen
  (INV-30):        n' = 4,           L' = 24
        Verhältnis 3,75
```

Die Zerlegung in sechs Dimensionen ist gesetzt und nicht gemessen; der Faktor ändert sich mit der Zerlegung, die Richtung nicht. Die ehrliche Einschränkung steht in INV-30: die Fachoberflächen der Fremdprodukte bleiben bestehen. Ein Ticketsystem wird weiterhin in seiner eigenen Oberfläche fachlich konfiguriert; die Plattform legt nur fest, wo es läuft, wer es nutzt und unter welchem Namen es erreichbar ist. Wer eine Reduktion auf eine einzige Oberfläche für alles verspricht, verspricht etwas, das die Produktgrenze nicht hergibt.

```
Modell M6 (Übungsfrequenz je Fläche)
  Annahme: 150 Änderungsereignisse pro Jahr im Referenzfall A.
        f = 150 / n
  n = 15:  f = 10 je Fläche und Jahr   = ein Kontakt alle 36,5 Tage
  n = 1:   f = 150                     = ein Kontakt alle 2,4 Tage
```

Der Abstand von 36,5 Tagen je Fläche ist der eigentliche Mechanismus hinter U4: Handlungswissen, das alle fünf Wochen einmal gebraucht wird, wird bei jedem Gebrauch neu nachgeschlagen. Für die Schwelle, ab der Handlungswissen ohne Nachschlagen verfügbar bleibt, liegt kein zitierbarer Wert vor; die Anforderung lautet deshalb, die Frequenz zu erhöhen, nicht, eine Schwelle zu unterschreiten.

### 2.2.6 U5: fehlende Rückkopplung

Die Flächen 1 bis 15 melden je für sich, dass eine Änderung angenommen wurde. Keine meldet, ob die Absicht am Wirkort eingetreten ist: ein angenommener DNS-Eintrag ist nicht dasselbe wie ein auflösbarer Name, eine geladene Proxykonfiguration nicht dasselbe wie eine erreichbare Adresse, ein angelegtes Konto nicht dasselbe wie eine mögliche Anmeldung. Die Rückkopplung wird dadurch an den Nutzer ausgelagert, der sich meldet, wenn etwas nicht geht.

```
Modell M7 (Kosten der Rückkopplungslatenz)
  Änderungsrate λ, Entdeckungslatenz t.
  Zahl der Änderungen, die zwischen Ursache und Entdeckung ergangen sind
  und bei der Fehlersuche mitgeprüft werden müssen:
        C = λ * t
  Annahme λ = 2 Änderungen je Arbeitstag, t = 30 Kalendertage
  (rund 21 Arbeitstage):  C = 42
```

Der Aufwand der Fehlersuche wächst linear mit der Entdeckungslatenz, weil die Menge der Kandidaten linear wächst. Der Kanon setzt dem einen datierten Istzustand entgegen (INV-28) und eine Sichtbarkeitsfrist von 30 s (K-16); die Latenz sinkt damit von Tagen auf Sekunden, und C fällt in denselben Größenordnungen.

## 2.3 Fehlerklassen mit Ursache und Wirkung

| ID | Fehlerklasse | Ursachen | Warum sie im heutigen Aufbau fast zwangsläufig entsteht | Gegenmaßnahme im Kanon |
|---|---|---|---|---|
| **F1** | Abweichung zwischen Absicht und Zustand | U2, U5 | Die Absicht existiert nur im Kopf oder im Ticket, der Zustand nur in den Flächen; es gibt kein Objekt, gegen das verglichen werden könnte | INV-02, INV-28 |
| **F2** | Verwaistes Artefakt (Firewallregel, DNS-Eintrag, Konto, Zertifikat) | U1, U2 | Anlegen hat einen Auftraggeber, Entfernen hat keinen; die Asymmetrie wirkt in jeder Runde in dieselbe Richtung | INV-09, INV-11 |
| **F3** | Abgelaufenes Zertifikat | U4, U5 | Eine Frist ohne zuständigen Prozess ist eine Frist, die genau einmal auffällt, nämlich beim Ablauf | K-13, INV-18 |
| **F4** | Unvollständiges Ausscheiden | U1, U2 | Das Ausscheiden ist eine Tatsache, die in n Flächen nachvollzogen werden muss, ohne dass eine Fläche die anderen kennt | INV-11, INV-12 |
| **F5** | Stiller Teilfehler | U1, U5 | Ein Vorgang über mehrere Systeme hat keine gemeinsame Transaktion und keinen gemeinsamen Ergebnisbericht; der erste Erfolg wird zum Gesamtergebnis | INV-12 |
| **F6** | Nicht dokumentierte Handänderung | U1, U4 | Im Störfall ist die Handänderung der schnellste Weg, die Dokumentation ein späterer, unbelohnter, separater Akt | INV-02, INV-03, INV-23 |

**F2 quantifiziert.** Annahme: 150 artefakterzeugende Änderungen je Jahr, Rückbauquote q = 0,8 bei Wegfall des Anlasses. Verwaiste Artefakte je Jahr = 150 × (1 − 0,8) = 30; nach fünf Jahren 150. Die Zahl ist ein Modellergebnis; belastbar ist die Aussage, dass der Bestand ohne aktiven Rückbau linear wächst und nie von selbst schrumpft. Ein verwaister Zugang ist dabei nicht nur Unordnung, sondern eine Berechtigung ohne Eigentümer.

**F3 quantifiziert.** Annahme: 500 veröffentlichte Namen, handgeführte Erneuerung mit Laufzeit 365 d, Fehlschlagwahrscheinlichkeit je Erneuerung f = 0,02. Erwartete Abläufe = 500 × 0,02 = 10 je Jahr. Mit dem Verfahren aus K-13 (Laufzeit 90 d, Erneuerung ab Tag 60, stündlicher Versuch, also 720 Versuche im Fenster) setzt ein Ablauf eine zusammenhängende Störung des Ausstellungspfads von 30 Tagen voraus; der Zielwert für den Anteil Zertifikate mit weniger als 7 d Restlaufzeit ist 0. Der Unterschied ist nicht ein besserer Erinnerungsmechanismus, sondern die Verlagerung der Frist von einem Menschen in einen Regelkreis.

**F4 quantifiziert.** Annahme: eine ausscheidende Person hat Zugänge in s = 8 Systemen, Fehlerwahrscheinlichkeit je Rückbauschritt p = 0,02. P(vollständig) = 0,98⁸ = 85,08 %, also 14,92 % unvollständige Austritte. Bei angenommenen 40 Austritten je Jahr sind das 5,97 Austritte mit mindestens einem verbliebenen aktiven Zugang. Das ist die für die Zielgruppen relevanteste Zahl dieses Kapitels, weil sie eine Sicherheits- und eine Nachweispflicht zugleich verletzt.

**F5 quantifiziert.** Ein Vorgang über z Zielsysteme mit Ausfallwahrscheinlichkeit e je Zielsystem gelingt vollständig mit (1 − e)^z. Bei z = 5 und e = 0,02 sind das 0,98⁵ = 90,39 %, also 9,61 % Vorgänge mit mindestens einem ausstehenden Zielsystem. Ohne gemeinsamen Ergebnisbericht werden diese 9,61 % als Erfolg angezeigt, weil kein Beteiligter das Gesamtergebnis kennt. INV-12 verbietet genau diesen Zustand und verlangt den Vorgangsausgang "teilweise fehlgeschlagen" mit benannten Resten.

**F6.** Diese Klasse ist die einzige der sechs, die durch Anreize und nicht durch Wahrscheinlichkeiten erklärt wird. Im Moment der Störung ist die Handänderung dominant: sie wirkt sofort, sie erfordert keine Freigabe, und sie ist der einzige Weg, der ohne Werkzeug funktioniert. Jede Lösung, die auf Disziplin setzt, arbeitet gegen diesen Anreiz und verliert. Der Kanon dreht den Anreiz um, indem die Handänderung beim nächsten Abgleich überschrieben wird und ein Auditereignis "Abweichung korrigiert" erzeugt (INV-02); der schnellste dauerhafte Weg wird damit der Weg über den Sollzustand.

## 2.4 Bedienlast eines klassischen Einrichtungsvorgangs

Der Vorgang "neuer Mitarbeiter mit Postfach und Ticketsystemzugang" zerfällt im Referenzfall A in die folgenden Fragen und Schritte. Die Zuordnung nennt die Ursache aus Abschnitt 2.2, aus der die Frage entsteht.

| Nr. | Frage oder Schritt | Ursache | Woraus sie ableitbar wäre |
|---|---|---|---|
| 1 | Anmeldename nach welchem Muster? | U2 | Mandantenrichtlinie |
| 2 | In welchen Verzeichnisast? | U3 | entfällt im Objektmodell |
| 3 | Welche Betriebssystemgruppen? | U1 | Rolle |
| 4 | Passwort setzen oder Einladung? | U1 | Richtlinie |
| 5 | Zweiter Faktor jetzt oder später? | U1 | Richtlinie |
| 6 | Konto im Identitätsanbieter anlegen | U2 | abgeleitet aus Person |
| 7 | Welche Anwendungen zuordnen? | U1 | Zuweisung |
| 8 | Postfach: welche Lizenz? | U1 | Wirkungsvorschau, INV-29 |
| 9 | Postfach: lokaler Teil? | legitim | Entscheidung |
| 10 | Postfach: welche Domäne? | legitim | Entscheidung |
| 11 | Alias anlegen? | U1 | optional |
| 12 | In welche Verteiler? | U2 | Gruppenmitgliedschaft |
| 13 | Ticketsystem: Agent oder Kunde? | legitim | Rolle |
| 14 | Ticketsystem: welche Warteschlangen? | U1 | Gruppe |
| 15 | Ticketsystem: Konto manuell oder Verzeichnisanbindung? | U3 | Konnektorbindung |
| 16 | Datenbankrolle nötig? | U3 | entfällt |
| 17 | Dateifreigaben: welche Rechte? | U2 | Gruppe |
| 18 | Netzzugang: Gerät registrieren? | U1 | Gerät |
| 19 | Zertifikat für das Gerät ausstellen? | U3 | abgeleitet |
| 20 | VPN-Profil erzeugen und zustellen | U1 | abgeleitet |
| 21 | Endgeräteverwaltung: Profil zuordnen | U1 | Richtlinie |
| 22 | Sicherung: fällt neuer Datenbereich an? | U5 | Sicherungsplan |
| 23 | Dokumentation: wo eintragen? | U5 | Auditereignis |
| 24 | Prüfen, ob alles wirkt | U5 | Istzustand |

Von 24 Schritten sind drei legitime betriebliche Entscheidungen; 21 entstehen aus U1 bis U5. Mit Modell M2 und p = 0,01 ergibt sich P(fehlerfrei) = 0,99²⁴ = 78,57 %, also 21,43 % fehlerbehaftete Einstellungen. Bei angenommenen 40 Einstellungen je Jahr sind das 8,6 fehlerbehaftete Vorgänge. Nach K-03 kostet derselbe Vorgang in der Zielarchitektur zwei Entscheidungen für die Person, zwei für die Mailadresse und einen Schalter für das Ticketsystem, also rund fünf: 0,99⁵ = 95,10 %, 4,90 % Fehlerrate, 2,0 Vorgänge je Jahr. Das Verhältnis der Fehlerraten beträgt 4,4.

Die verbleibenden Fehler ändern zusätzlich ihre Art. Bei 24 Schritten sind Auslassungen die häufigste Fehlerform, und eine Auslassung ist unsichtbar. Bei fünf Entscheidungen bleiben nur Falschauswahlen, und eine Falschauswahl ist am Objekt sichtbar und rücknehmbar, weil sie als Vorgang protokolliert ist.

Dasselbe Muster erklärt den in der Aufgabenstellung genannten Zustand nach der Einrichtung eines einzigen Servers: die offenen Fragen sind nicht zu viele Fragen, sondern Fragen der falschen Art. Sie lauten "welches Dateisystem", "welcher Webserver", "welcher Mailtransport", "welche Zertifikatsquelle", "welches Sicherungsziel", "welche Firewallzone", "welcher Resolver" und nicht "wer soll was benutzen dürfen". Jede einzelne dieser Fragen ist nach dem Kriterium aus Abschnitt 2.2.4 eine Leckage.

## 2.5 Zielgruppen

| Zielgruppe | Größenordnung (Annahme) | Merkmale | Besondere Zwangslage |
|---|---|---|---|
| Kleine und mittlere Betriebe | 5 bis mehrere hundert Nutzer | Keine eigene Fachabteilung; ein bis zwei Personen mit IT als Nebenaufgabe | Abhängigkeit vom Betrieb ist total, Fachtiefe ist gering |
| Dienstleister mit vielen Kunden | 10 bis 200 Kundeninstallationen | Wiederholte Einrichtung derselben Struktur; Nachweispflicht gegenüber Kunden | Jede nicht mandantenfähige Lösung multipliziert den Pflegeaufwand mit der Kundenzahl |
| Bildungseinrichtungen | 100 bis mehrere tausend Konten | Hohe Fluktuation, saisonale Massenvorgänge, geteilte Geräte | Massenanlage und Massenaustritt sind der Normalfall, nicht die Ausnahme |
| Vereine | 20 bis 500 Mitglieder | Ehrenamtliche Verwaltung, Wechsel der verantwortlichen Person in kurzen Zyklen | Wissensweitergabe scheitert an der Wechselfrequenz |
| Kanzleien und Praxen | 5 bis 100 Nutzer | Berufsrechtliche Verschwiegenheit, Aufbewahrungspflichten | Auslagerung ist rechtlich eingeschränkt, Eigenbetrieb fachlich nicht leistbar |
| Handwerk | 5 bis 100 Nutzer | Mobile Geräte, wechselnde Standorte, schwache Netzanbindung | Geräte- und Netzzugangsverwaltung ist hier keine Kür |

Gemeinsam ist allen sechs Gruppen, dass keine eigene Fachabteilung existiert, die regulatorischen Pflichten aber nicht mit der verfügbaren Fachtiefe skalieren. Pflichten aus der Datenschutz-Grundverordnung (Verordnung (EU) 2016/679) knüpfen an die Verarbeitung an, nicht an die Betriebsgröße; die Anforderungen der Richtlinie (EU) 2022/2555 knüpfen an Sektor und Größe an, deren Anwendbarkeit im Einzelfall nicht Gegenstand dieses Dokuments ist; die Verordnung (EU) 2024/2847 trifft Hersteller und wirkt über die Produktauswahl auf Betreiber zurück; die Richtlinie (EU) 2019/882 und das Barrierefreiheitsstärkungsgesetz wirken auf alles, was Beschäftigte oder Kunden bedienen müssen. Die Nachweisführung dieser Pflichten ist in [Kapitel 22](22-compliance.md) behandelt.

Die strukturelle Folge ist ein Aufwandsmodell, in dem der Engpass nicht die Arbeitszeit ist, sondern die Wissensbreite. Nach Modell M5 sind im Referenzfall A 90 Lernposten zu halten; nach Modell M6 wird jede Fläche alle 36,5 Tage einmal berührt. Eine Person kann viel Arbeit leisten, aber nicht 15 Fachgebiete mit einer Kontaktfrequenz von fünf Wochen wach halten.

## 2.6 Heutige Auswege und ihre Kosten

| Dimension | Vollständige Auslagerung in Abonnementdienste | Eigenbau | Mischform (heute der Normalfall) |
|---|---|---|---|
| Anfangsaufwand | Niedrig | Hoch | Mittel |
| Laufende Kosten | Wiederkehrend, nutzerzahlabhängig, einseitig änderbar | Hardware und Arbeitszeit | Beides |
| Kontrolle über Daten | Beim Anbieter; Ort und Zugriff vertraglich, nicht technisch bestimmt | Beim Betreiber | Geteilt und meist nicht dokumentiert |
| Ausstiegskosten | Hoch; Formate, Identitäten und Verknüpfungen sind anbieterspezifisch | Niedrig, aber nur bei dokumentiertem Zustand | Hoch, weil an beiden Enden gebunden |
| Bus-Faktor | Nicht beim Betreiber, dafür Anbieterabhängigkeit | 1 | 1 |
| Pflegeverantwortung | Beim Anbieter | Beim Betreiber, meist ungeplant | Unklar verteilt; Lücken entstehen an der Naht |
| Nachweisfähigkeit | Abhängig von Anbieterberichten | Abhängig von eigener Dokumentation | Zwei unvollständige Quellen |
| Anpassbarkeit | Gering, endet an der Produktgrenze des Anbieters | Hoch | Mittel |

**Auslagerung, gerechnet.** Hängt ein Arbeitsplatz von z externen Diensten ab, die gleichzeitig verfügbar sein müssen, dann ist die Kettenverfügbarkeit A_ges = A^z. Annahme A = 0,999 je Anbieter, z = 5 (Postfach, Identität, Dateiablage, Ticketsystem, Sicherung): A_ges = 0,999⁵ = 0,995010, entsprechend 0,004990 × 8760 h = 43,7 h Ausfallzeit je Jahr gegenüber 8,8 h bei einem einzigen Anbieter. Das Modell setzt Unabhängigkeit und gleichzeitige Notwendigkeit voraus; beides ist nur näherungsweise erfüllt, weshalb 43,7 h eine Rechnung und keine Prognose ist. Die belastbare Aussage lautet: serielle Abhängigkeit multipliziert Unverfügbarkeit, und die Zahl der Abhängigkeiten ist beim Auslagern kein Nebenaspekt, sondern die Hauptgröße.

Hinzu kommen drei Kostenarten, die sich nicht in Verfügbarkeit ausdrücken: der Datenabfluss an Stellen, an denen der Betreiber weder Ort noch Zugriffsweg technisch bestimmt; der Kontrollverlust über den Änderungszeitpunkt, weil Funktionsänderungen des Anbieters weder ablehnbar noch verschiebbar sind; und die Bindung durch Formate, die den Ausstieg mit der Nutzungsdauer teurer macht.

**Eigenbau, gerechnet.** Der Bus-Faktor 1 ist keine Metapher, sondern eine Verfügbarkeitsaussage über die einzige Person, die den Zustand kennt. Annahme: Unverfügbarkeitsanteil u = 0,15 (Urlaub, Krankheit, andere Aufgaben), m = 12 Störungen je Jahr, die Fachwissen erfordern. Erwartete Störungen ohne die kundige Person = 12 × 0,15 = 1,8 je Jahr. Bei Ausscheiden der Person ist der gesamte nicht externalisierte Zustand neu zu erschließen, und zwar über die 15 Flächen aus Abschnitt 2.1.

Die zweite Kostenart des Eigenbaus ist die Pflegelücke. Annahme: 12 sicherheitsrelevante Meldungen je Fläche und Jahr, 15 Flächen, also 180 Bewertungsentscheidungen je Jahr oder 3,5 je Woche für eine Person ohne dafür vorgesehene Zeit. Auch diese Zahl ist eine Annahme; die Struktur ist es nicht: die Meldungsmenge skaliert mit n, die verfügbare Zeit nicht.

## 2.7 Präzise Problemdefinition

**Zu lösen ist:** die Bedienung eines vollständigen Serverbetriebs auf eine einzige Konfigurationsfläche zu reduzieren, in der jede Tatsache genau einmal erklärt wird, jede Frage an den Bediener eine Aussage über seinen Betrieb verlangt und nicht über die Implementierung, jede Absicht eine datierte, überprüfbare Wirkung hat und jede Wirkung vollständig zurückgenommen werden kann. Formal:

```
P1  n = 1 für alle infrastrukturellen Konfigurationsflächen;
    Fachoberflächen bleiben bestehen und sind je Katalogeintrag
    deklariert (INV-30).
P2  Jede Tatsache ist genau einmal schreibbar; alle weiteren Orte sind
    abgeleitet und nicht editierbar (INV-02, INV-09).
P3  Jede Standardaufgabe kostet höchstens drei Entscheidungen, und jede
    Entscheidung ist eine Aussage über den Betrieb (INV-14, INV-15).
P4  Jede Absicht hat einen datierten Istzustand und einen benannten
    Ausgang je Zielsystem (INV-12, INV-28).
P5  Jede Wirkung ist über ihre Quelle rückbaubar, und der Rückbau ist
    ein Vorgang mit Auswirkungsliste (INV-11).
P6  Der gesamte Sollzustand ist ohne die einrichtende Person lesbar,
    exportierbar und wiederherstellbar (K-11, K-24).
```

**Nicht zu lösen ist:**

| Nicht gelöst | Begründung |
|---|---|
| Ersatz für Fachwissen bei Sonderfällen | Eine Aufgabe außerhalb des Katalogs bleibt eine Fachaufgabe; die Plattform senkt die Häufigkeit solcher Fälle, nicht ihre Schwierigkeit |
| Hypervisor oder Virtualisierungsplattform | Die Plattform verwaltet Knoten und Dienste, nicht fremde Gastsysteme; virtuelle Maschinen sind Unterlage, nicht Gegenstand |
| Anwendungsentwicklung | Fachliche Funktionen entstehen in den Fremdprodukten; die Plattform integriert sie und ersetzt sie nicht |
| Sicherheitsgarantie für fremde Software | Ein Katalogeintrag wird abgegrenzt, mit geringsten Rechten ausgeführt und mit Stückliste geführt; eine Schwachstelle im Fremdprodukt bleibt eine Schwachstelle |
| Schutz vor berechtigter Fehlbedienung | Eine mit korrekten Rechten getroffene falsche Entscheidung wird sichtbar, auditiert und rücknehmbar gemacht, nicht verhindert |
| Rechtliche Bewertung der Datenhaltung | Die Plattform liefert Nachweise, nicht die Subsumtion; siehe [Kapitel 22](22-compliance.md) |
| Verwaltung fremder Netzhardware jenseits von Standardprotokollen | Zuordnung über Netzzugangsprotokolle ja, herstellerspezifische Verwaltung nein; siehe [Kapitel 12](12-dns-netzwerk.md) |
| Wiederherstellung von Daten ohne Sicherung | Ungesicherte Daten sind verloren; die Plattform macht den Zustand "ohne geprüfte Sicherung" sichtbar (INV-18) |
| Bestandsaufnahme gewachsener Fremdsysteme | Vor Einführung entstandene Konten und Einträge gehören keinem Objekt und werden nicht automatisch übernommen |

## 2.8 Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| **R-02-01** | Für jede Tatsachenart existiert in der öffentlichen API genau ein schreibbarer Ort; die Zahl schreibbarer Orte je Tatsachenart ist 1 | INV-02, INV-09 |
| **R-02-02** | Alle infrastrukturellen Konfigurationsflächen sind in der Atrium Console zusammengefasst; jede verbleibende Fremdoberfläche ist am Katalogeintrag deklariert und in der Konsole benannt | INV-30 |
| **R-02-03** | Jede Aufgabe des Aufgabenkatalogs ist mit höchstens drei Entscheidungen abschließbar | INV-14, K-03 |
| **R-02-04** | Kein Pflichtfeld und keine Auswahl im Aufgabenkatalog verlangt eine Aussage über die Implementierung; die Prüfung gegen die Leckageliste ergibt 0 Treffer | INV-14, INV-15 |
| **R-02-05** | Jede Absichtsänderung zeigt innerhalb von 30 s einen Istzustand mit Beobachtungszeitpunkt | INV-28, K-16 |
| **R-02-06** | Ein Vorgang über mehrere Zielsysteme endet nie im Zustand "abgeschlossen", solange ein Zielsystem aussteht; jeder Rest ist mit Zielsystem, Grund und Wiederholungsweg benannt | INV-12 |
| **R-02-07** | Nach Konvergenz ist die Zahl abgeleiteter Artefakte ohne lebende Quelle 0; jedes Artefakt trägt einen Quellverweis | INV-09, INV-11 |
| **R-02-08** | Das Ausscheiden einer Person ist ein einzelner Vorgang; nach seinem Abschluss ist die Zahl verbliebener aktiver Zugänge 0 oder jeder verbliebene Zugang ist einzeln benannt | INV-11, INV-12 |
| **R-02-09** | Der Anteil Zertifikate mit weniger als 7 Tagen Restlaufzeit ist 0 | K-13, INV-18 |
| **R-02-10** | Eine Handänderung an einer erzeugten Konfiguration wird beim nächsten Abgleich überschrieben und erzeugt ein Auditereignis vom Typ "Abweichung korrigiert" | INV-02, INV-23 |
| **R-02-11** | Jede Aufgabe des Aufgabenkatalogs ist ohne Terminal ausführbar | INV-26 |
| **R-02-12** | Keine Fehlermeldung nennt einen Konsolenbefehl oder einen Dateipfad als einzigen Lösungsweg | INV-17, K-27 |
| **R-02-13** | Degradierte Zustände (fehlende Redundanz, ungeprüfte Sicherung, ausstehende Konvergenz) sind dauerhaft am betroffenen Objekt sichtbar | INV-18 |
| **R-02-14** | Der vollständige Sollzustand ist als signierter, maschinen- und menschenlesbarer Export verfügbar; eine zweite Person stellt daraus ohne Beteiligung der einrichtenden Person die Installation wieder her | K-11, K-24 |
| **R-02-15** | Je Dienst ist sichtbar, von welchen externen Parteien seine Funktion abhängt, und je Konnektorbindung ist der Zeitpunkt der letzten erfolgreichen Beobachtung angegeben | INV-28 |
| **R-02-16** | Die Nichtziele aus Abschnitt 2.7 sind je Katalogeintrag als Produktgrenzdeklaration hinterlegt und an der Stelle sichtbar, an der ein Bediener die Funktion erwartet | INV-30 |
| **R-02-17** | Jede Standardaufgabe ist vom Überblick aus in höchstens drei Ebenen erreichbar; kein Navigationsbereich hat mehr als sieben Unterpunkte | K-26 |
| **R-02-18** | Eine automatisierte Wiederherstellungsübung läuft monatlich auf Ersatzhardware mit Erfolgsquote 100 % und 0 Objektabweichungen | K-24 |

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Für jede der in der Tatsachenliste geführten Arten liefert eine Abfrage der API-Beschreibung genau eine schreibbare Stelle | R-02-01 | Statische Auswertung der API-Beschreibung im Bau; mehr als eine schreibbare Stelle bricht den Bau |
| Jeder Katalogeintrag trägt eine Produktgrenzdeklaration, und jede darin benannte Fremdoberfläche ist in der Konsole verlinkt | R-02-02, R-02-16 | Freigabeprüfung je Katalogeintrag (INV-30) |
| Der automatisierte Durchlauf aller Aufgaben des Aufgabenkatalogs zählt je Aufgabe höchstens drei Entscheidungen | R-02-03 | Abgleich der Formulardefinitionen gegen die maschinenlesbaren Aufgabendefinitionen (K-03) |
| Die Prüfung aller Pflichtfelder gegen die Leckageliste ergibt 0 Treffer, und jedes Feld nennt die Quelle seiner Vorbelegung | R-02-04 | Musterprüfung im Bau (INV-15, K-27) |
| Nach jeder Absichtsänderung im Testlauf erscheint innerhalb von 30 s ein Istzustand mit Beobachtungszeitpunkt | R-02-05 | Zeitmessung über den Aufgabenkatalog (K-16) |
| Fehlerinjektion in einen von fünf Konnektoren erzeugt den Vorgangsausgang "teilweise fehlgeschlagen" mit benanntem Zielsystem | R-02-06 | Injektionstest je Konnektorbindung (INV-12) |
| Nach Löschung einer Quelle und einem vollständigen Abgleich ist die Zahl abgeleiteter Artefakte ohne Quelle 0 | R-02-07 | Inventarabgleich nach Konvergenz |
| Ein simuliertes Ausscheiden mit acht Zielsystemen endet mit 0 aktiven Zugängen oder einer vollständigen Restliste | R-02-08 | Austrittstest über alle gebundenen Zielsysteme |
| Über einen simulierten Jahreslauf mit 500 Namen erreicht kein Zertifikat eine Restlaufzeit unter 7 Tagen | R-02-09 | Zeitrafferlauf des Erneuerungsregelkreises (K-13) |
| Eine Handänderung an einer erzeugten Datei ist nach dem nächsten Abgleich rückgängig, und das Auditereignis existiert | R-02-10 | Abweichungstest im Bau (INV-02) |
| Der vollständige Aufgabenkatalog läuft ohne Terminalzugriff durch | R-02-11 | Automatisierter Konsolendurchlauf (INV-26) |
| Die Musterprüfung aller Meldungstexte findet 0 Befehls- und 0 Dateipfadangaben als einzigen Lösungsweg | R-02-12 | Textprüfung im Bau (K-27) |
| Die Zustandsmatrix über alle Degradationsarten zeigt je Art eine dauerhafte Anzeige am betroffenen Objekt | R-02-13 | Zustandsmatrixtest (INV-18) |
| Eine zweite Person stellt aus Export und Nutzdatensicherung die Installation in höchstens 30 min ohne Rückfrage wieder her | R-02-14 | Wiederherstellungsübung mit unbeteiligter Person (K-11) |
| Je Dienst listet die Konsole alle externen Abhängigkeiten mit dem Zeitpunkt der letzten erfolgreichen Beobachtung | R-02-15 | Darstellungstest je Dienst (INV-28) |
| Jede Standardaufgabe ist in höchstens drei Ebenen erreichbar, kein Bereich hat mehr als sieben Unterpunkte | R-02-17 | Auswertung des Navigationsbaums (K-26) |
| Die monatliche Wiederherstellungsübung meldet Erfolgsquote 100 % und 0 Objektabweichungen | R-02-18 | Automatisierte Übung auf Ersatzhardware (K-24) |

## Offene Punkte

1. **Der Wert von p ist nicht gemessen.** Sämtliche Rechnungen der Abschnitte 2.2.2, 2.2.3, 2.3 und 2.4 stehen auf einer angenommenen Fehlerwahrscheinlichkeit je Schritt. Die Form der Aussage (exponentieller Abfall in k) ist vom Wert unabhängig, die genannten Faktoren 4,4 und 11,1 sind es nicht. Zu entscheiden ist, ob p an instrumentierten Einrichtungsläufen erhoben wird oder ob das Kapitel auf die rein strukturelle Aussage zurückgenommen wird und alle Faktoren entfallen.
2. **Die Drei-Entscheidungs-Regel gegen fachlich vierteilige Aufgaben.** Für Aufgaben, die fachlich vier unabhängige Angaben benötigen, bleiben zwei Auswege: die Aufteilung in zwei Aufgaben, die die Entscheidungszahl nur verschiebt und die Zusage aushöhlt, oder eine begründungspflichtige Ausnahme, die in K-27 als Bauprüfung nicht mehr eindeutig ist. Zu entscheiden ist, welcher Weg gilt und wie er geprüft wird, ohne dass die Regel formal eingehalten und praktisch umgangen wird.
3. **Die Abgrenzung legitime Frage gegen Abstraktionsleckage ist an Grenzfällen unscharf.** "Soll dieser Dienst aus dem Internet erreichbar sein" ist eine betriebliche Frage, setzt aber ein Verständnis der Folgen voraus, das genau das Fachwissen ist, dessen Fehlen unterstellt wird. Zu entscheiden ist, ob solche Fragen mit einer verpflichtenden Folgenanzeige in der Wirkungsvorschau versehen werden und wie deren Text geprüft wird, ohne in erfundene Oberflächentexte zu verfallen.
4. **Zielgruppenbreite gegen Entscheidungsarmut.** Kanzlei, Handwerksbetrieb und Bildungseinrichtung brauchen unterschiedliche Vorbelegungen für Aufbewahrung, Geräteverwaltung und Freigabewege. Jedes zusätzliche Richtlinienprofil senkt die Entscheidungszahl im Einzelvorgang und hebt sie bei der Ersteinrichtung, weil die Profilwahl selbst zur Fachentscheidung wird. Zu entscheiden ist die Höchstzahl mitgelieferter Profile und ob die Profilwahl in die vier Entscheidungen der Ersteinrichtung nach K-01 hineinzählt.
5. **Messverfahren für die Erstklick-Trefferquote.** K-26 nennt einen Zielwert von 80 %, für den weder ein Messverfahren noch eine Erstnutzergruppe festgelegt ist. Ohne beides ist der Wert eine Absicht und keine prüfbare Zusage, und R-02-17 deckt nur den strukturellen Teil (Ebenentiefe, Unterpunktzahl) ab.
6. **Die wirtschaftliche Gegenüberstellung in Abschnitt 2.6 ist ohne Preise geführt.** Verfügbarkeitsketten und Bus-Faktor sind rechenbar, die Abwägung zwischen wiederkehrenden Abonnementkosten und einmaliger Hardware mit Arbeitszeit ist es ohne Preisannahmen nicht. Zu entscheiden ist, ob diese Rechnung in [Kapitel 23](23-oekonomie-roadmap.md) mit offengelegten Preisannahmen geführt wird oder ob das Whitepaper auf eine Kostenaussage verzichtet.
7. **Erstinventar gewachsener Fremdsysteme.** Der Entwurf baut nur zurück, was er angelegt hat und was ihm das Feldeigentum nach INV-13 zuweist. Bestehende Konten, Verteiler, DNS-Einträge und Firewallregeln aus der Zeit vor der Einführung gehören keinem Objekt, sind damit weder sichtbar noch rückbaubar und unterlaufen die Aussage aus R-02-07 in jeder Bestandsinstallation. Zu entscheiden ist, ob eine Inventarisierungsfunktion Fremdbestände als "vorgefunden, ohne Quelle" aufnimmt, wer sie einem Objekt zuordnet und wie [Kapitel 09](09-konnektoren.md) das im Konnektorvertrag abbildet.
8. **Die Zahl n = 15 des Referenzfalls ist gesetzt.** Sie bestimmt die Ergebnisse der Modelle M1, M5 und M6 unmittelbar. Zu entscheiden ist, ob der Referenzfall durch eine strukturierte Erhebung an Bestandsinstallationen belegt wird oder ob er dauerhaft als Annahme geführt bleibt und alle darauf gestützten Faktoren entsprechend gekennzeichnet werden.
