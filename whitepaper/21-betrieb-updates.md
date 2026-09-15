# 21 Betrieb, Beobachtbarkeit und Aktualisierung

## 21.1 Beobachtbarkeit

### Vier getrennte Signalarten

Atrium erhebt vier Signalarten mit getrennten Lebenszyklen, getrennten Ablageorten und getrennten Sichtbarkeitsregeln. Die Trennung folgt aus der kanonischen Festlegung, dass der Auditstrom außerhalb des replizierten Kernzustands liegt (INV-23) und Metriken niemals im Sollzustand stehen.

| Signalart | Inhalt | Erzeugung | Ablageort | Replikation |
|---|---|---|---|---|
| **Metriken** | Zähler, Verteilungen und Zustandswerte je Knoten, Dienst, Veröffentlichung, Konnektorbindung | atrium-node und atrium-core, OTLP über 8408/tcp | Lokaler Zeitreihenspeicher je Verwaltungsknoten | keine; jeder Verwaltungsknoten hält seinen eigenen Stand |
| **Betriebsprotokoll** | Strukturierte JSON-Ereignisse mit Korrelationskennung, Objektkennung, Fassung und Ergebnis | Jeder Atrium-Prozess und jeder Dienstprozess | systemd-journald lokal, optional Ausleitung nach RFC 5424 über 6514/tcp | keine |
| **Ablaufspuren** | Spannen je Vorgang, je API-Aufruf, je Konnektoraufruf, je Reconciler-Lauf | atrium-core, atrium-node, Konnektorprozesse | Lokaler Spurenspeicher je Verwaltungsknoten | keine |
| **Auditstrom** | Jede schreibende Operation, jede Anmeldung, jede Zertifikatsausstellung, jeder Notzugriff | atrium-core | Anhängbarer, hashverketteter Strom außerhalb des Raft-Zustands, periodisch signiert mit Zeitstempel nach RFC 3161 | eigener Auslagerungspfad, nicht über Raft |

Das Betriebsprotokoll ist verdichtbar und wegwerfbar, der Auditstrom ist es nicht. Ein Ereignis, das beide Eigenschaften braucht, wird zweimal geschrieben: als Auditereignis mit Vorher/Nachher und als Protokollzeile mit technischem Kontext, beide mit derselben Korrelationskennung. Die Verdopplung ist der Preis dafür, dass der Auditstrom nicht mit Betriebsrauschen gefüllt wird und dadurch seine Beweiskraft verliert.

- **R-21-01** — Jede Protokollzeile, jede Spanne und jedes Auditereignis, die zu demselben Vorgang gehören, tragen dieselbe Korrelationskennung. Prüfbar: ein Testvorgang über drei Konnektorbindungen erzeugt Einträge in allen drei Strömen, die sich über genau eine Kennung vollständig zusammenführen lassen; eine Zeile ohne Kennung bricht den Bau. Folgt aus dem Identifikatorformat des Kanons.
- **R-21-02** — Der replizierte Sollzustand enthält keine Metrik- und keine Protokolldaten. Prüfbar: Größenprüfung des Sollzustandsexports gegen K-12 nach einem Dauerlastlauf von 72 h; ein Wachstum über die Objektzahl hinaus bricht den Bau.

### Auflösung und Aufbewahrung der Metriken

Die Kardinalität ist die Größe, die einen Metrikspeicher zum Ausfallen bringt, nicht die Abtastrate. Sie wird deshalb begrenzt, nicht beobachtet.

Kardinalitätsrechnung, Obergrenze der Skalengrenze aus K-21 (32 Knoten, 500 Dienstinstanzen):

```
Reihen(Knoten)   = 32 Knoten   x 120 Reihen je Knoten   =   3.840
Reihen(Dienste)  = 500 Dienste x  40 Reihen je Dienst   =  20.000
Reihen(Kern)     = Kontrollebene, Eingang, DNS, PKI     =     300
                                                          -------
Summe                                                     24.140
Zielwert Obergrenze                                       25.000 Zeitreihen
```

Annahme für den Speicherbedarf: 2 Byte je Datenpunkt nach Differenz- und Bitmusterkodierung. Das ist eine Annahme aus der üblichen Kodierung monotoner Zeitreihen, keine Messung.

| Stufe | Raster | Zeitraum | Punkte je Reihe | Reihen | Werte je Punkt | Bedarf |
|---|---|---|---|---|---|---|
| Roh | 10 s | 48 h | 48 · 3600 / 10 = 17.280 | 25.000 | 1 | 25.000 · 17.280 · 2 B = 0,86 GB |
| Verdichtet | 60 s | 30 d | 30 · 86400 / 60 = 43.200 | 25.000 | 4 (Minimum, Maximum, Summe, Anzahl) | 25.000 · 43.200 · 4 · 2 B = 8,64 GB |
| Nachweis | 300 s | 13 Monate | 395 · 86400 / 300 = 113.760 | 200 | 4 | 200 · 113.760 · 4 · 2 B = 0,18 GB |
| **Summe** | | | | | | **9,68 GB** |

Die dritte Stufe gilt ausdrücklich nicht für alle Reihen. Die vollständige Beibehaltung aller 25.000 Reihen über 13 Monate ergäbe 25.000 · 113.760 · 4 · 2 B = 22,75 GB und damit zusammen 32,25 GB je Verwaltungsknoten; das widerspricht dem Ressourcenbudget eines kleinen Ankerknotens aus K-19. Entwurfsentscheidung: eine benannte **Nachweisliste** von höchstens 200 Reihen — die Dienstgüteindikatoren aus 21.2, die Kapazitätsreihen und die Zertifikatsrestlaufzeiten — überlebt 13 Monate, alles andere endet nach 30 Tagen. Die verworfene Alternative, alles 13 Monate zu halten, kostet das Dreifache an Plattenplatz für Daten, die nach vier Wochen niemand mehr abfragt. Die Schwäche dieser Wahl ist benannt: eine Störungsanalyse, die auf eine nicht gelistete Reihe von vor sechs Monaten angewiesen ist, ist nicht durchführbar.

- **R-21-03** — Der Metrikspeicher eines Verwaltungsknotens überschreitet 12 GB nicht und lehnt neue Zeitreihen oberhalb von 25.000 mit benanntem Grund ab, statt zu wachsen. Prüfbar: Lasttest mit erzwungener Kardinalitätsexplosion; der Knoten bleibt unter der Grenze und meldet den Zustand (INV-18).
- **R-21-04** — Die Nachweisliste enthält höchstens 200 Zeitreihen und ist in der Konsole als Liste sichtbar. Prüfbar: Abgleich der Listenlänge im Bau.

### Umfang des Betriebsprotokolls und der Ablaufspuren

```
Betriebsprotokoll je Knoten:
  Annahme: 5 Ereignisse/s im Mittel, 600 B je Ereignis
  5 /s x 600 B x 86.400 s/d = 259.200.000 B/d = 259 MB/d roh
  Annahme Kompressionsfaktor 8 im Journal          =  32 MB/d
  Aufbewahrung 30 d                                = 972 MB
  Zielwert Obergrenze je Knoten                    = 1,5 GB, danach aelteste Woche verfaellt

Ablaufspuren:
  Vorgaenge:      Annahme 200/d x 40 Spannen x 400 B  =  3,2 MB/d   (Abtastung 100 %)
  Leseanfragen:   Annahme 20/s = 1.728.000/d, Abtastung 1 von 1.000
                  1.728 Spuren x 12 Spannen x 400 B   =  8,3 MB/d
  Fehleranfragen: 100 % abgetastet, im Rauschen enthalten
  Summe                                              = 11,5 MB/d
  Aufbewahrung 7 d                                   = 81 MB
```

Vorgänge werden vollständig abgetastet, weil sie selten und einzeln bedeutsam sind; Leseanfragen werden ratenbegrenzt abgetastet, weil sie häufig und einzeln bedeutungslos sind; fehlgeschlagene und ungewöhnlich langsame Anfragen werden vollständig abgetastet, weil genau sie gebraucht werden. Die verworfene Alternative einer festen Abtastrate über alle Anfragen verliert entweder die seltenen Vorgänge oder erzeugt die 415 MB je Tag, die eine vollständige Abtastung der Leseanfragen kostet.

### Wer welches Signal sieht

| Rolle | Metriken | Betriebsprotokoll | Ablaufspuren | Auditstrom |
|---|---|---|---|---|
| Mandantenadministrator | nur Reihen eigener Dienste, Veröffentlichungen und Konnektorbindungen | nur Zeilen mit eigenem Mandantenbezug | nur Spuren eigener Vorgänge | nur eigene Ereignisse |
| Plattformadministrator | alle, einschließlich Knoten- und Kernreihen | alle | alle | alle |
| Prüferrolle | Nachweisliste | keine | keine | alle, nur lesend, mit Export |
| Fernunterstützung | wie die befristet zugewiesene Rolle, nie darüber hinaus | dito | dito | dito |
| Bediener ohne Administratorrolle | keine | keine | keine | eigene Anmeldeereignisse |

Jede Abfrage aller vier Ströme trägt ein Mandantenprädikat (INV-19). Eine Protokollzeile ohne Mandantenbezug — etwa eine Startmeldung eines Knotendienstes — gehört der Plattform und ist für Mandantenadministratoren unsichtbar, auch dann, wenn der Knoten ausschließlich ihre Dienste trägt.

- **R-21-05** — Eine Metrik-, Protokoll- oder Spurenabfrage ohne Mandantenprädikat wird von der Datenzugriffsschicht abgelehnt. Prüfbar: Abfragetest über alle vier Ströme mit einer Rolle ohne Plattformgeltung; 0 fremde Datensätze im Ergebnis (INV-19).

### Keine Übermittlung an den Hersteller

Voreinstellung ist Stufe 0: die Ausgangs-Positivliste von atrium-core enthält keine Adresse des Herstellers. Es gibt keinen Pfad, der ohne ein Objekt im Sollzustand entsteht, weil jede ausgehende Verbindung ein abgeleitetes Artefakt einer Konnektorbindung ist (INV-09, INV-21).

| Stufe | Was übermittelt würde | Auslösung | Widerruf |
|---|---|---|---|
| **0 (Voreinstellung)** | nichts | entfällt | entfällt |
| 1 Absturzberichte | Fassung, Komponentenname, Fehlerklasse, Aufrufkette ohne Argumentwerte, Knotenklasse, Anzahl der Knoten; keine Kennungen, keine Namen, keine Adressen | ausdrückliche Einwilligung, je Stufe ein eigener Vorgang | jederzeit, wirkt sofort durch Entzug der Ausgangsfreigabe |
| 2 Nutzungskennzahlen | Zahl der Mandanten, Personen, Geräte, Dienste je Katalogeintrag, Knotenzahl, Fassungsstand, Ausrollungsergebnisse, Dauer der Standardaufgaben; sämtlich als Zahlen, keine Namen, keine Kennungen | wie Stufe 1 | wie Stufe 1 |
| 3 Diagnosepaket | der Inhalt aus 21.8, geschwärzt und pseudonymisiert | je Paket ein eigener Vorgang mit eigener Bestätigung; eine Einwilligung gilt nie für künftige Pakete | entfällt, weil jede Übermittlung einzeln entschieden wird |

Der Widerruf ist ein Vorgang, nicht eine Einstellung: er erzeugt ein Auditereignis, entfernt die Konnektorbindung und damit die Ausgangsfreigabe und ist in [Kapitel 19](19-mandanten-rechte-audit.md) nachweisbar. Die Konsole fragt eine abgelehnte Einwilligung nicht erneut ab; ein wiederholter Hinweis nach einer Ablehnung ist ein Entwurfsfehler, weil er die Ablehnung zu einer Frage der Ausdauer macht.

- **R-21-06** — Ohne aktive Einwilligung erreicht kein Atrium-Prozess eine Adresse des Herstellers. Prüfbar: Netznamensraumtest mit Zugriffsversuch aus atrium-core, atrium-node und jedem Konnektorprozess auf die Herstelleradresse; 0 erfolgreiche Verbindungen (INV-21).
- **R-21-07** — Der Widerruf einer Einwilligung beendet jede laufende Übermittlung innerhalb von 60 s und erzeugt genau 1 Auditereignis. Prüfbar: Widerruf während eines laufenden Uploads; die Verbindung endet, das Ereignis existiert, ein erneuter Versuch scheitert an der Ausgangsliste.
- **R-21-08** — Die Konsole zeigt zu jeder Einwilligungsstufe die vollständige Liste der übermittelten Felder vor der Zustimmung. Prüfbar: Abgleich der angezeigten Feldliste gegen das tatsächlich gesendete Schema im Vertragstest; eine Abweichung bricht den Bau.

## 21.2 Dienstgüteziele und Fehlerbudget

### Indikatoren

Ein Dienstgüteindikator ist ein Anteil erfolgreicher Ereignisse an allen Ereignissen, gemessen an der Stelle, an der der Nutzer das Ergebnis sieht — am Eingang, nicht im Dienst. Ein am Dienst gemessener Indikator zeigt 100 %, während der Eingang keine Route hat.

| Gegenstand | Indikator | Messpunkt | Zielwert | Fenster |
|---|---|---|---|---|
| Atrium Console und API | Anteil Anfragen mit Antwortklasse unter 500 und ohne Zeitüberschreitung | Eingang | 99,5 % | 30 d gleitend |
| Atrium Console, Latenz | Anteil Listenanfragen mit Antwortzeit ≤ 300 ms | Eingang | 95 % | 30 d gleitend |
| Schreibpfad der Kontrollebene | Anteil bestätigter Schreibvorgänge mit Bestätigung ≤ 5 s (K-05) | atrium-core | 99,0 % | 30 d gleitend |
| Veröffentlichter Dienst | Anteil Anfragen mit Antwortklasse unter 500 | Eingang, je Veröffentlichung | 99,0 %, je Dienst überschreibbar | 30 d gleitend |
| Namensauflösung | Anteil Antworten ≤ 100 ms im Zielbereich | Resolver je Netzzone | 99,9 % | 30 d gleitend |
| Versorgung einer Zuweisung | Anteil Zuweisungen mit Wirksamkeit ≤ 60 s über alle Zielsysteme (K-15) | atrium-core | 95 % | 30 d gleitend |
| Zertifikatsversorgung | Anteil Zertifikate, deren Restlaufzeit nie unter 7 d fällt (K-13) | PKI-Modul | 100 % | fortlaufend |

Die letzte Zeile hat kein Fehlerbudget. Ein abgelaufenes Zertifikat ist kein Anteilsproblem, sondern ein Ausfall einer Veröffentlichung; ein Budget von einem Prozent wäre bei 500 Namen die Erlaubnis für fünf Ausfälle. Die Ehrlichkeit verlangt die Feststellung, dass ein Ziel von exakt 100 % kein Dienstgüteziel im eigentlichen Sinn ist, sondern eine Invariante mit Alarm; es steht hier, damit die Liste vollständig ist, und nicht, weil das Budgetverfahren darauf anwendbar wäre.

### Berechnung des Fehlerbudgets

```
Fenster        T   = 30 d = 43.200 min = 2.592.000 s
Ziel           Z   = 0,995
Fehlerbudget   B_t = (1 - Z) x T = 0,005 x 43.200 min = 216 min = 3 h 36 min

Ereignisbezogen, Annahme 20 Anfragen/s:
  N   = 20 /s x 2.592.000 s = 51.840.000 Anfragen im Fenster
  B_n = 0,005 x 51.840.000  =    259.200 zulaessige Fehlanfragen
```

Zwei Beispielstörungen, gerechnet gegen dasselbe Budget:

```
Stoerung A: vollstaendiger Ausfall 18 min
  Verbrauch = 18 min / 216 min = 8,33 % des Budgets

Stoerung B: 5 % Fehleranteil ueber 12 h
  Budgetaequivalent = 720 min x 0,05 = 36 min
  Verbrauch = 36 min / 216 min = 16,67 % des Budgets
```

Die Deutung ist der eigentliche Zweck der Rechnung: die zwölfstündige Teilstörung, die niemandem auffällt, verbraucht doppelt so viel Budget wie der vollständige Ausfall, über den alle sprechen. Das ist ein Modell, keine Messung; es setzt gleichmäßige Last voraus und bewertet eine Störung zur Hauptlastzeit genauso wie eine nachts.

### Verbrauchsrate als Alarmgrundlage

Die Verbrauchsrate b ist das Verhältnis des tatsächlichen Fehleranteils zum zulässigen Fehleranteil. Bei b = 1 ist das Budget genau am Fensterende aufgebraucht.

| Fenster t | Verbrauchsrate b | Verbrauchter Budgetanteil b · t / 30 d | Zugehöriger Fehleranteil b · 0,005 | Folge |
|---|---|---|---|---|
| 1 h | 14,4 | 2 % | 7,2 % | Zustellung an das Alarmziel |
| 6 h | 6 | 5 % | 3,0 % | Zustellung an das Alarmziel |
| 24 h | 3 | 10 % | 1,5 % | Aufgabe im Überblick |
| 72 h | 1 | 10 % | 0,5 % | Hinweis am Dienst |

Die kurzen Fenster fangen den schnellen Verbrauch, die langen den schleichenden. Jede Zeile wird gegen ein zweites, kürzeres Fenster bestätigt, bevor sie zustellt; ohne diese Bestätigung erzeugt jede Lastspitze von fünf Minuten eine Zustellung.

### Folge eines aufgebrauchten Budgets

| Zustand | Wirkung | Erzwingung |
|---|---|---|
| Budget > 25 % verbleibend | keine | — |
| Budget ≤ 25 % verbleibend | Hinweis am Dienst; Funktionsaktualisierungen dieses Dienstes werden in der Wirkungsvorschau als budgetrelevant gekennzeichnet | weich |
| Budget aufgebraucht | Funktionsaktualisierungen und nicht sicherheitsrelevante Änderungen an diesem Dienst werden freigabepflichtig; Sicherheitsaktualisierungen und Störungsbehebungen bleiben frei; laufende gestaffelte Ausrollungen für diesen Dienst werden angehalten | Richtlinie, voreingestellt weich |

Die Erzwingung ist voreingestellt weich, und das ist eine bewusste Abweichung von der üblichen Lehre. Eine harte Änderungssperre setzt eine Organisation voraus, in der jemand anderes die Sperre aufheben kann; in einer Installation mit einem einzigen Administrator sperrt sie genau die Person aus, die die Störung beheben soll. Die Richtlinie ist auf hart stellbar und dann mit Begründungspflicht bei Abweichung verbunden.

- **R-21-09** — Jeder Dienst mit mindestens einer Veröffentlichung hat einen Dienstgüteindikator mit Zielwert, Fenster und sichtbarem Restbudget. Prüfbar: Abgleich der Dienstliste gegen die Indikatorliste; ein Dienst ohne Indikator bricht den Bau.
- **R-21-10** — Ein aufgebrauchtes Fehlerbudget hält eine laufende gestaffelte Ausrollung für den betroffenen Dienst an und lässt Sicherheitsaktualisierungen zu. Prüfbar: Injektion eines Fehleranteils über dem Budget während einer Ausrollung; die Ausrollung stoppt, eine Sicherheitsaktualisierung desselben Dienstes läuft durch.

## 21.3 Alarmierung

### Regel: Symptom statt Ursache

Ein Alarm ist zulässig, wenn alle drei Bedingungen erfüllt sind: das Signal ist für einen Nutzer oder für die Nachweisführung sichtbar, ein Mensch muss jetzt handeln, und in der Konsole existiert ein Weg zur Behebung (sinngemäß INV-17). Fehlt eine der drei Bedingungen, entsteht eine Aufgabe im Überblick oder ein Hinweis am Objekt, kein Alarm.

| Alarm | Gemessenes Symptom | Bedingung | Dringlichkeit | Nicht alarmiert wird die Ursache |
|---|---|---|---|---|
| Dienst nicht erreichbar | Fehleranteil einer Veröffentlichung am Eingang | ≥ 50 % über 5 min bei ≥ 20 Anfragen | sofort | Neustartzahl, Speicherauslastung, Prozessabsturz |
| Budgetverbrauch zu schnell | Verbrauchsrate nach 21.2 | b ≥ 14,4 über 1 h, bestätigt über 5 min | sofort | einzelne langsame Antwort |
| Namensauflösung gestört | Anteil ausbleibender Antworten je Netzzone | ≥ 10 % über 5 min | sofort | Resolver-Neustart, Zwischenspeicherstand |
| Keine Redundanz mehr | Zahl der noch vertragenen Knotenausfälle | Übergang auf 0 | sofort | Ausfall eines einzelnen Knotens bei bestehender Redundanz |
| Sollzustand eingefroren | Quorumzustand | kein Quorum über 60 s | sofort | Führungswechsel, einzelne verpasste Heartbeats |
| Zertifikat läuft ab | kleinste Restlaufzeit über alle aktiven Zertifikate | ≤ 7 d (K-13) | sofort | einzelner fehlgeschlagener Erneuerungsversuch |
| Sicherung nicht nachweisbar | Alter der letzten erfolgreichen Prüfung eines Wiederherstellungspunkts | > 2 Prüfintervalle | am nächsten Werktag | einzelner fehlgeschlagener Sicherungslauf |
| Auditkette gebrochen | Kettenprüfung beim Start und beim Export | jede Abweichung | sofort, nicht unterdrückbar | — |
| Notzugang ausgelöst | Ereignis aus [Kapitel 19](19-mandanten-rechte-audit.md) | jede Auslösung | sofort, nicht unterdrückbar | — |
| Ausrollung abgebrochen | Abbruchkriterium aus 21.5 | jeder Abbruch | sofort | einzelner Knotenneustart innerhalb der Ausrollung |
| Zeitgüte unbekannt | Anteil Knoten ohne belegte Zeitgüte | > 0 (K-30, INV-32) | sofort | einzelne Korrektur innerhalb der Grenze |
| Ausrollung unvollständig | Dauer des Mischbetriebs | > 7 d | am nächsten Werktag | gemischte Fassungen innerhalb des Fensters |

### Was niemals alarmiert

Auslastungswerte ohne Wirkung auf einen Indikator; ein einzelner fehlgeschlagener Reconciler-Lauf, der im nächsten Lauf gelingt; eine Abweichung zwischen Ist- und Sollzustand, die der Reconciler innerhalb der Konvergenzzeit K-16 korrigiert; die Leerlaufabschaltung eines Konnektorprozesses; ein geplanter Knotenneustart innerhalb einer laufenden Ausrollung; ein einzelner verworfener Telemetriedatensatz; das Erreichen einer Plattenfüllschwelle unterhalb der Kontingentgrenze; jede reine Zustandsänderung ohne Handlungsbedarf. Diese Liste ist Teil der Spezifikation und nicht eine Betriebsempfehlung: eine Regel, die auf einen dieser Gegenstände zustellt, wird im Bau abgelehnt.

### Alarmmüdigkeit, quantitativ

Die zulässige Zahl von Regeln folgt aus einer geforderten Präzision, nicht aus Geschmack.

```
Zielwert Praezision P     = 0,8   (4 von 5 Zustellungen fuehren zu einer Handlung)
Annahme echte Stoerungen  = 1 je Monat = 0,23 je Woche
Zulaessige Fehlalarme F:
  P = echt / (echt + F)  =>  F = echt x (1 - P) / P = 0,23 x 0,25 = 0,0575 je Woche

Bei R Regeln und Fehlalarmrate f je Regel und Tag:
  F = R x f x 7
  R =  40  =>  f = 0,0575 / (40 x 7) = 2,05e-4 /d  = 1 Fehlalarm je Regel in 13 Jahren
  R =  12  =>  f = 0,0575 / (12 x 7) = 6,85e-4 /d  = 1 Fehlalarm je Regel in  4,0 Jahren
  R =   8  =>  f = 0,0575 / ( 8 x 7) = 1,03e-3 /d  = 1 Fehlalarm je Regel in  2,7 Jahren
```

Deutung: eine Regelmenge von vierzig zustellenden Regeln verlangt je Regel eine Fehlalarmfreiheit von dreizehn Jahren und ist damit nicht erreichbar. Daraus folgt der Zielwert von höchstens zwölf zustellenden Regeln je Installation, und daraus folgt, dass jede weitere Beobachtung eine Aufgabe erzeugt und keinen Alarm. Das ist ein Modell mit zwei Annahmen (Störungshäufigkeit, Unabhängigkeit der Regeln) und keine Messung; es ändert sich sofort, wenn eine Installation häufiger gestört ist.

- **R-21-11** — Die Zahl der Regeln, die außerhalb der Konsole zustellen, beträgt höchstens 12 je Installation. Prüfbar: Abzählung der Regelmenge im Bau; die dreizehnte Regel bricht den Bau.
- **R-21-12** — Kein Alarm nennt eine Ursache, für die es kein Symptom in der Indikatorliste gibt. Prüfbar: Abgleich jeder Regel gegen die Indikatorliste aus 21.2 und die Symptomspalte in 21.3.

### Zusammenfassung, Abhängigkeitsunterdrückung, Eskalation

Die Unterdrückung leitet sich aus dem Objektgraphen ab, nicht aus einer eigenen Abhängigkeitskonfiguration. Fällt ein Knoten aus, sind die Veröffentlichungen der auf ihm platzierten Dienste per Konstruktion betroffen; ihre Alarme werden zu Positionen im Knotenalarm, nicht zu eigenen Zustellungen. Eine zweite Konfigurationsquelle für Abhängigkeiten wäre eine zweite Wahrheitsquelle und verstieße gegen INV-02.

```
Ausfall eines Arbeitsknotens mit 14 Diensten und 22 Veroeffentlichungen
  ohne Unterdrueckung: 1 + 14 + 22 = 37 Zustellungen
  mit  Unterdrueckung: 1 Zustellung "Knoten ausgefallen",
                       Position 1: 14 Dienste, Position 2: 22 Veroeffentlichungen,
                       Position 3: Redundanz danach: <Zahl>
```

| Stufe | Auslösung | Ziel | Frist |
|---|---|---|---|
| 1 | Alarm entsteht | Banner im Überblick, Aufgabe am verursachenden Objekt | sofort |
| 2 | Alarm besteht fort | Alarmziel des betroffenen Mandanten | ≤ 60 s |
| 3 | keine Quittierung | Zweitziel des Mandanten | 15 min nach Stufe 2 |
| 4 | keine Quittierung | Plattform-Alarmziel | 30 min nach Stufe 3 |

Stille Zeiten sind an ein Wartungsfenster oder an einen laufenden Vorgang gebunden und nie an eine Uhrzeit allein; ein Fenster ohne zugehörigen Vorgang läuft nach spätestens 8 h aus. Niemals unterdrückbar sind: Notzugangsauslösung, Bruch der Auditkette, Verlust des Quorums, Fehler in der Sperrlistenverteilung, Ausfall der Alarmzustellung selbst.

**Benannte Schwäche.** Betreibt die Installation ihren eigenen Mailfluss, ist die Alarmzustellung per Mail von genau dem System abhängig, dessen Störung sie melden soll. Der Entwurf löst das nicht technisch, sondern verlangt mindestens ein Alarmziel, das als außerhalb dieser Installation erklärt ist; existiert keines, zeigt der Überblick dauerhaft "Alarmzustellung nicht unabhängig" (INV-18). Eine Erklärung durch den Bediener ist keine Prüfung — das System kann nicht verifizieren, dass das genannte Ziel wirklich unabhängig ist.

- **R-21-13** — Ein Knotenausfall erzeugt genau 1 Zustellung, unabhängig von der Zahl der betroffenen Dienste und Veröffentlichungen. Prüfbar: Ausfalltest mit ≥ 10 Diensten; Abzählung der Zustellungen.
- **R-21-14** — Eine stille Zeit endet spätestens 8 h nach ihrem Beginn und unterdrückt keinen Alarm aus der Liste der nicht unterdrückbaren Ereignisse. Prüfbar: Auslösung jedes nicht unterdrückbaren Ereignisses während einer stillen Zeit.

## 21.4 Aktualisierung des Basissystems

### Ablauf

```
Ausgangslage: Platz A aktiv (Fassung n), Platz B inaktiv (Fassung n-1)

 1  Abbild n+1 abrufen (Depot oder Datentraeger)
 2  Pruefen, Reihenfolge verbindlich, Abbruch beim ersten Fehlschlag:
      a Ed25519-Signatur (RFC 8032) ueber die kanonische Form (RFC 8785)
      b Eintrag im Transparenzprotokoll mit Zeitstempel (RFC 3161), Inklusionsnachweis
      c Fassungsfolge: n+1 > aktive Fassung  (Rueckspielsperre)
      d dm-verity-Wurzelhashwert stimmt mit der signierten Fassungsangabe ueberein
      e Fassung steht nicht auf der knotenbezogenen Sperrliste (siehe Rueckfallbegrenzung)
 3  In Platz B schreiben, Platz B verifizieren (vollstaendiger Hashvergleich)
 4  Startzaehler fuer Platz B auf 3 setzen, Platz B als Startziel markieren
 5  Dienste geordnet anhalten, Knoten neu starten
 6  Startlader dekrementiert den Startzaehler vor dem Start des Nutzerbereichs
 7  Gesundheitspruefung (Liste unten), Frist 10 min (K-23)
 8a erfolgreich: Startzaehler auf "bestaetigt", Platz A wird der inaktive Platz
 8b Frist verstrichen oder Pruefung fehlgeschlagen: Neustart; bei Startzaehler 0
    waehlt der Startlader Platz A; Fassung n+1 auf die Sperrliste dieses Knotens
```

Nutzdaten, Speicherbereiche, Sollzustand, Auditstrom, Metriken und Geheimnisspeicher liegen außerhalb beider Abbildhälften. Ein Rückfall verliert deshalb keine Daten — mit genau einer Ausnahme, die 21.4 unten behandelt.

### Gesundheitsprüfung nach dem Start

| Nr. | Prüfung | Bezug |
|---|---|---|
| 1 | dm-verity-Wurzelhashwert entspricht der signierten Fassungsangabe | Lieferkette, [Kapitel 20](20-sicherheit.md) |
| 2 | Zeitgüte belegt, Abweichung ≤ 500 ms | INV-32, K-30 |
| 3 | TPM-Entsiegelung erfolgreich, Knotenhauptschlüssel verfügbar | [Kapitel 11](11-pki.md) |
| 4 | Knotenzertifikat gültig und nicht gesperrt | [Kapitel 11](11-pki.md) |
| 5 | Overlay-Verbindung zu mindestens einem Verwaltungsknoten steht | [Kapitel 16](16-cluster.md) |
| 6 | atrium-node hat den Sollzustandsauszug empfangen und eine Istmeldung gesendet | INV-28 |
| 7 | nur Verwaltungsknoten: Raft-Mitgliedschaft wiederhergestellt, Log-Rückstand 0, Zustandshashwert des Lesemodells gleich dem der Mehrheit | [Kapitel 08](08-kontrollebene.md) |
| 8 | alle vor dem Neustart laufenden Dienste laufen wieder; Sollmenge aus dem letzten Auszug | INV-25 |
| 9 | jede Veröffentlichung dieses Knotens antwortet am Eingang mit gültigem Zertifikat | K-17 |
| 10 | keine im Referenzfenster unbekannte Fehlerklasse im Betriebsprotokoll | 21.1 |

Auf einem alleinstehenden Ankerknoten entarten die Prüfungen 5 und 7 zu Selbstprüfungen; das ist die ehrliche Feststellung, dass ein Ein-Knoten-System die Korrektheit seiner neuen Fassung nur gegen sich selbst prüfen kann und die Ausrollungsstaffelung aus 21.5 dort nicht wirkt.

### Wachhund und Begrenzung der Rückfallversuche

| Ebene | Mechanismus | Frist | Wirkung bei Ablauf |
|---|---|---|---|
| Startlader | Startzähler je Abbildplatz, vor dem Nutzerbereich dekrementiert | 3 Versuche | Umschaltung auf den anderen Platz |
| Prozess | systemd-Wachhund je Kernprozess | 30 s ohne Lebenszeichen | Prozessneustart, nach 3 Neustarts in 10 min Meldung als Fehlklasse in Prüfung 10 |
| Knoten | Gesundheitsfrist | 10 min (K-23) | Neustart, Zähler entscheidet über den Platz |
| Verbund | Lease | 20 s (K-07) | Selbstabschottung des Knotens (INV-06), Übernahme ab 30 s |

Nach einem Rückfall trägt der Knoten die Fassung in eine knotenbezogene Sperrliste im Sollzustand ein. Die Ausrollung weist ihm dieselbe Fassung nicht erneut zu; ohne diese Sperre entstünde eine Endlosschleife aus Aktualisierung, Fehlschlag und erneuter Zuweisung, die den Knoten dauerhaft im Neustart hält. Der Eintrag wird ausschließlich durch einen ausdrücklichen Vorgang entfernt. Rückfallzeit: Zielwert ≤ 5 min (K-23), begrenzt durch die Startzeit des Knotens, weil der Rückfall nichts anderes ist als ein Start in den anderen Platz.

Scheitern beide Plätze, startet der Knoten in einen Wartungsmodus mit einem lokal erreichbaren Bedienendpunkt analog zum Kopplungsendpunkt (8403/tcp). Dieser Fall verlangt physischen oder Fernkonsolenzugang; der Entwurf löst ihn nicht aus der Ferne, weil jeder Fernweg in diesen Zustand genau die Hintertür wäre, die der Kanon ausschließt.

- **R-21-15** — Bleibt das Gesundheitssignal 10 min nach dem Start aus, startet der Knoten selbsttätig in den vorherigen Abbildplatz, ohne dass eine Verbindung zur Kontrollebene besteht. Prüfbar: Aktualisierung mit absichtlich fehlerhaftem Abbild bei abgeschalteter Kontrollebene (K-23, INV-25).
- **R-21-16** — Eine Fassung, die auf einem Knoten zurückgefallen ist, wird diesem Knoten ohne ausdrücklichen Vorgang nicht erneut zugewiesen. Prüfbar: Wiederholte Ausrollung derselben Fassung nach einem Rückfall; 0 erneute Zuweisungen.
- **R-21-17** — Ein Abbild mit niedrigerer Fassungsnummer als der aktiven wird abgelehnt. Prüfbar: Rückspielversuch mit gültig signiertem älterem Abbild; Ablehnung mit benanntem Grund.

### Trennung von Schema und Laufzeit (INV-24)

| Schritt | Gegenstand | Eigener Vorgang | Rücknahme durch |
|---|---|---|---|
| 1 | Sollzustandsexport unmittelbar vor der Umstellung | ja | entfällt |
| 2 | Abbildwechsel auf eine Fassung, die `MAJOR` und `MAJOR−1` liest | ja | A/B-Rückfall |
| 3 | Schemamigration `MAJOR−1` → `MAJOR`, nur auf Verwaltungsknoten, kein Abbildwechsel | ja | Migrationsrücknahme, sonst Import des Exports aus Schritt 1 |
| 4 | Abbildwechsel auf eine Fassung ohne `MAJOR−1`-Lesefähigkeit | ja, frühestens eine Linie später | A/B-Rückfall nur, solange Schritt 3 nicht angewandt wurde |

Die Ausnahme vom Satz "ein Rückfall verliert keine Daten" liegt genau zwischen Schritt 3 und Schritt 4: ist die Schemamigration angewandt und wird die Laufzeit zurückgerollt, liest die alte Fassung den neuen Zustand nicht. Der Ausweg ist der Import des Exports aus Schritt 1, und dieser Import verwirft alle Änderungen seit der Migration. Der Entwurf verkleinert das Fenster, indem der Export unmittelbar vor der Migration erzwungen wird und die Migration der letzte Schritt einer Ausrollung ist; er beseitigt es nicht. Eine Freigabeprüfung lehnt jedes Abbild ab, das Schema- und Laufzeitänderung in einem Schritt trägt.

- **R-21-18** — Ein Abbild, das eine Schemamigration und eine Laufzeitänderung in einem Schritt enthält, wird bei der Freigabe abgelehnt. Prüfbar: Freigabeversuch mit einem so gebauten Abbild (INV-24).
- **R-21-19** — Vor jeder Schemamigration existiert ein signierter, geprüfter Sollzustandsexport mit dem Schemastand vor der Migration. Prüfbar: Migration ohne vorhandenen Export wird abgelehnt.

## 21.5 Gestaffelte Ausrollung

### Modell und Rechnung

Ein Fehler tritt auf einem aktualisierten Knoten mit der Wahrscheinlichkeit p innerhalb des Beobachtungsfensters auf; die Knoten werden als unabhängig angenommen. Die Wahrscheinlichkeit, dass mindestens ein Kanarienknoten den Fehler zeigt, ist die Gegenwahrscheinlichkeit dazu, dass keiner ihn zeigt:

```
D(p, k) = 1 - (1 - p)^k
```

| p | k = 1 | k = 2 | k = 3 | k = 5 | k = 8 |
|---|---|---|---|---|---|
| 0,01 | 0,0100 | 0,0199 | 0,0297 | 0,0490 | 0,0773 |
| 0,05 | 0,0500 | 0,0975 | 0,1426 | 0,2262 | 0,3366 |
| 0,10 | 0,1000 | 0,1900 | 0,2710 | 0,4095 | 0,5695 |
| 0,25 | 0,2500 | 0,4375 | 0,5781 | 0,7627 | 0,8999 |
| 0,50 | 0,5000 | 0,7500 | 0,8750 | 0,9688 | 0,9961 |
| 1,00 | 1,0000 | 1,0000 | 1,0000 | 1,0000 | 1,0000 |

Die Mindestzahl an Kanarienknoten für eine geforderte Erkennungswahrscheinlichkeit D folgt durch Umstellen:

```
k_min = ceil( ln(1 - D) / ln(1 - p) ),  hier D = 0,95, ln(0,05) = -2,995732

 p = 0,01 :  ln(0,99) = -0,0100503  ->  k >= 298,07  ->  299
 p = 0,05 :  ln(0,95) = -0,0512933  ->  k >=  58,40  ->   59
 p = 0,10 :  ln(0,90) = -0,1053605  ->  k >=  28,43  ->   29
 p = 0,25 :  ln(0,75) = -0,2876821  ->  k >=  10,41  ->   11
 p = 0,50 :  ln(0,50) = -0,6931472  ->  k >=   4,32  ->    5
 p = 0,80 :  ln(0,20) = -1,6094379  ->  k >=   1,86  ->    2
```

**Ableitung und benannte Schwäche.** Die Skalengrenze des Systems liegt bei 32 Knoten (K-21), und die überwiegende Zahl der Installationen hat ein bis fünf Knoten. Innerhalb einer Installation ist damit nur ein Fehler mit p ≥ 0,5 mit 95 % Wahrscheinlichkeit durch Kanarienknoten erkennbar; ein Fehler mit p = 0,05 — der also nur bei jeder zwanzigsten Hardware-, Last- oder Konfigurationslage auftritt — verlangt 59 Kanarienknoten und ist in einer einzelnen Installation grundsätzlich nicht durch Staffelung erkennbar. Die Staffelung innerhalb einer Installation schützt gegen den offensichtlichen Fehler und gegen den gleichzeitigen Ausfall aller Knoten; sie ist kein Qualitätsverfahren.

Die Erkennung seltener Fehler kann nur aus der Grundgesamtheit über viele Installationen kommen:

```
Erkennung ueber N Installationen bei Auftretenswahrscheinlichkeit q je Installation:
  D = 1 - (1 - q)^N
  q = 0,02, N = 200 :  0,98^200 = e^(200 x ln 0,98) = e^(-4,04054) = 0,01762
                       D = 0,9824
  N_min fuer D = 0,95 bei q = 0,02 :
      ln(0,05) / ln(0,98) = -2,995732 / -0,0202027 = 148,3  ->  149
```

Daraus folgt der Zielwert einer Frühnehmergruppe von mindestens 150 Installationen. Und daraus folgt der unangenehme Teil: die Rückmeldung aus dieser Gruppe setzt Übermittlung an den Hersteller voraus, die nach 21.1 einwilligungspflichtig und voreingestellt abgeschaltet ist. Der Entwurf kauft Datensparsamkeit mit Erkennungsvermögen. Er löst den Zielkonflikt nicht auf, sondern legt ihn offen: die Frühnehmergruppe besteht aus Installationen, die Stufe 1 oder 2 ausdrücklich eingeschaltet haben, und die Freigabemeldung nennt die tatsächliche Zahl der rückmeldenden Installationen, damit ein Betreiber weiß, wie belastbar die Vorprüfung war.

### Ringe und Kanarienwahl

| Ring | Grundgesamtheit | Mindestverweildauer | Freigabe in den nächsten Ring |
|---|---|---|---|
| 0 | Installationen des Herstellers | 7 d | keine Abbruchkriterien ausgelöst |
| 1 | Frühnehmer mit ausdrücklicher Einwilligung, Zielwert ≥ 150 | 7 d | Rückmeldungen ohne Abbruchkriterium, Zahl der rückmeldenden Installationen in der Freigabemeldung |
| 2 | alle Installationen | — | — |

Innerhalb einer Installation: k = max(1, ⌈n/8⌉), begrenzt auf 4, wobei n die Zahl der Arbeitsknoten ist. Kanarienknoten sind nie Verwaltungsknoten und nie der einzige Knoten einer Fehlerzone, wenn es mehr als eine gibt. Beobachtungsfenster 30 min (K-23).

### Abbruchkriterien

| Kriterium | Schwelle | Wirkung |
|---|---|---|
| Gesundheitssignal ausgeblieben | ≥ 1 Kanarienknoten | Abbruch |
| Fehleranteil am Eingang für Dienste auf Kanarienknoten | ≥ Faktor 2 gegenüber nicht aktualisierten Knoten bei ≥ 100 Anfragen | Abbruch |
| Zustandshashwert des Lesemodells weicht zwischen Verwaltungsknoten ab | jede Abweichung | sofortiger Abbruch |
| Neustartzahl eines Dienstes auf einem Kanarienknoten | > 2 im Fenster | Abbruch |
| p95 der API-Antwortzeit | > 150 % des 7-Tage-Medians | Abbruch |
| Konvergenzzeit des Reconcilers | über der Grenze aus K-16 | Abbruch |
| Fehlerbudget eines betroffenen Dienstes | aufgebraucht (21.2) | Ausrollung angehalten, kein Rückfall |

Abbruch bedeutet: keine weiteren Knoten, Rückfall der bereits aktualisierten Knoten auf den vorherigen Abbildplatz, Eintrag der Fassung in die Sperrliste dieser Installation, Erzeugung eines Diagnosepakets nach 21.8 — erzeugt, nicht versandt. Der Zustand heißt in der Konsole "Ausrollung abgebrochen" und ist ein eigener, benannter Zustand, kein Fehlerzustand ohne Namen.

- **R-21-20** — Jede Ausrollung beginnt mit k = max(1, ⌈n/8⌉) Kanarienknoten, höchstens 4, und keiner davon ist ein Verwaltungsknoten. Prüfbar: Ausrollungssimulation für n ∈ {1, 4, 8, 27}; Abzählung und Rollenprüfung.
- **R-21-21** — Jedes der sieben Abbruchkriterien hält eine laufende Ausrollung innerhalb von 60 s an. Prüfbar: Injektion je Kriterium; Messung bis zum Stillstand der Ausrollung.
- **R-21-22** — Die Freigabemeldung einer Fassung nennt die Zahl der Installationen, die in Ring 1 zurückgemeldet haben. Prüfbar: Feldprüfung der Freigabemeldung; eine Meldung ohne diese Zahl wird bei der Freigabe abgelehnt.

## 21.6 Rollende Aktualisierung des Verbunds

### Reihenfolge

```
1  Zeuge (traegt keine Dienste und keine Speicherlast)
2  Kanarienknoten (Arbeitsknoten), danach 30 min Beobachtungsfenster
3  uebrige Arbeitsknoten in Losen, antiaffin zu Fehlerzonen,
   Losgroesse hoechstens 25 % der Arbeitsknoten je Fehlerzone
4  Mitleser, einzeln
5  Stimmknoten, einzeln, der Fuehrende zuletzt,
   vor dessen Neustart geordnete Fuehrungsuebergabe
```

Vorbedingung vor jedem einzelnen Stimmknoten: Quorum gesund, kein anderer Knoten in Aktualisierung, Log-Rückstand des vorherigen Knotens 0, Lease gültig, Zeitgüte belegt (INV-32).

### Quorumerhalt, gerechnet

```
Annahmen je Knoten:
  Neustart bis Dienstbereitschaft 90 s
  Gesundheitspruefung              60 s
  Einholen des Replikationsprotokolls 30 s
  Wartefrist vor dem naechsten Knoten 120 s
  Summe                           300 s = 5 min

3 Stimmknoten, Mehrheit 2, hoechstens 1 Ausfall vertraeglich
  => strikt einzeln, 3 x 5 min = 15 min

5 Stimmknoten, Mehrheit 3, hoechstens 2 Ausfaelle vertraeglich
  sequenziell: 5 x 5 min = 25 min
  zwei parallel: ceil(5/2) x 5 min = 15 min
  Ersparnis 10 min, Preis: waehrend der Aktualisierung vertraegt der Verbund
  keinen zusaetzlichen unabhaengigen Ausfall mehr
```

Entwurfsentscheidung: sequenziell auch bei fünf Stimmknoten. Die verworfene Alternative — zwei parallel — spart zehn Minuten und gibt dafür die Toleranz gegen einen unabhängigen Ausfall während des Fensters auf; ein fehlerhaftes Abbild ist zudem eine gemeinsame Ausfallursache, deren Wirkung mit der Zahl gleichzeitig aktualisierter Knoten wächst. Zehn Minuten sind kein Gegenwert.

Gesamtdauer und damit Mischbetriebsdauer:

```
8 Knoten (3 Stimm-, 5 Arbeitsknoten), k = 1:
   5 (Kanarie) + 30 (Fenster) + 2 Lose x 5 (4 Arbeitsknoten) + 3 x 5 (Stimmknoten)
   = 5 + 30 + 10 + 15 = 60 min

32 Knoten (5 Stimm-, 27 Arbeitsknoten), k = 4 parallel:
   5 + 30 + 6 Lose x 5 (23 Arbeitsknoten, Lose zu 4) + 5 x 5
   = 5 + 30 + 30 + 25 = 90 min
```

Beide Werte liegen unter dem Zielwert von 4 h Mischbetrieb. Das sind Rechnungen aus den vier genannten Annahmen, keine Messungen; eine langsam startende Hardware verschiebt sie linear.

### Gemischte Fassungen

| Schnittstelle | Kompatibilitätsfenster | Quelle |
|---|---|---|
| Raft-Peer-Protokoll (8401/tcp) | Zielwert: 2 aufeinanderfolgende Fassungen | Entwurfsfestlegung |
| `schema_version` des Sollzustands | `MAJOR` und `MAJOR−1` | Kanon, Abschnitt Schemaversionierung |
| Agentenkanal (8402/tcp) | atrium-node verträgt einen Kern je eine Fassung älter und neuer | Entwurfsfestlegung |
| Konnektorvertrag | laufende und vorhergehende Hauptversion von `vertrag_version` | Kanon |
| xDS an den Eingang (8404/tcp) | Zielwert: 2 aufeinanderfolgende Fassungen | Entwurfsfestlegung |

Während des Mischbetriebs gilt die Regel, dass der Verbund auf dem kleinsten gemeinsamen Funktionsumfang arbeitet: eine Funktion der neuen Fassung wird erst freigeschaltet, wenn alle Knoten sie tragen. Die Freischaltung ist ein eigener, auditierter Vorgang und kein Nebeneffekt des letzten Knotenneustarts. Ohne diese Trennung wäre das Verhalten des Systems davon abhängig, welcher Knoten eine Anfrage bearbeitet.

- **R-21-23** — Während einer rollenden Aktualisierung unterschreitet der Verbund zu keinem Zeitpunkt das Quorum. Prüfbar: Ausrollung über 3 und 5 Stimmknoten mit fortlaufenden Schreibvorgängen; 0 Schreibfehler außerhalb der Fristen aus K-05 (INV-04).
- **R-21-24** — Eine Funktion der neuen Fassung wird erst nach einem ausdrücklichen Freischaltvorgang wirksam, nicht mit dem letzten Knotenneustart. Prüfbar: Verhaltenstest im Mischbetrieb gegen beide Fassungen; identische Antwort unabhängig vom bearbeitenden Knoten.
- **R-21-25** — Ein Mischbetrieb über 7 d erzeugt einen Alarm "Ausrollung unvollständig" mit der Zahl der Knoten je Fassung. Prüfbar: Zeitsimulation mit angehaltener Ausrollung.

### Abbruch mitten in der Ausrollung

Der Abbruch führt in einen benannten Zustand, nicht in einen undefinierten. Der Überblick zeigt die Knotenzahl je Fassung, die Ursache des Abbruchs und genau zwei Wege: fortsetzen oder vollständig zurückrollen. Das Zurückrollen bereits aktualisierter Knoten ist der A/B-Rückfall aus 21.4 und dauert je Knoten die Startzeit. Wurde in derselben Ausrollung bereits eine Schemamigration angewandt, ist das vollständige Zurückrollen nur über den Import des Exports aus Schritt 1 der Tabelle in 21.4 möglich, mit dem dort benannten Verlust der Änderungen seit der Migration; die Konsole nennt in diesem Fall die Zahl der betroffenen Vorgänge, bevor sie die Bestätigung annimmt (INV-11).

## 21.7 Wartungsfenster, Verschiebung, Pflicht

| Klasse | Definition | Verschiebbar | Höchstaufschub | Nach Ablauf |
|---|---|---|---|---|
| Funktionsfassung | neue Funktionen, keine Sicherheitswirkung | beliebig | keiner; begrenzt durch den Unterstützungszeitraum | nichts |
| Fehlerbehebung | behebt einen Fehler ohne Sicherheitswirkung | ja | 90 d | Hinweis am betroffenen Objekt |
| Sicherheitsaktualisierung, nicht ausgenutzt | Schwachstelle ohne bekannte Ausnutzung | ja | 30 d | Einspielung im nächsten regulären Wartungsfenster, Fenster wird gesetzt |
| Sicherheitsaktualisierung der Außenkante, ausgenutzt | Eingang, autoritativer DNS, Resolver, Protokollkopf, Mailübergabe; bekannte Ausnutzung; extern erreichbar | ja, höchstens 24 h | 72 h nach Verfügbarkeit (K-23) | Wahl des Betreibers: einspielen oder betroffene Veröffentlichung zurückziehen; nach weiteren 24 h ohne Wahl zieht die Richtlinie die externe Veröffentlichung zurück |

Wartungsfenster sind je Installation für Abbildwechsel und je Dienst für Dienstaktualisierungen definiert; die Vorbelegung stammt aus einer Richtlinie und trägt deren Quellenangabe (INV-15). Jede Verschiebung ist ein Vorgang mit Grund und erscheint im Verlauf.

### Zwang gegen Kontrolle des Betreibers

Atrium ist quelloffen, wird auf fremder Hardware betrieben und hat keinen Herstellerfernzugang (Kanon: kein Fernzugang, keine Hintertür). Damit ist eine erzwungene Aktualisierung technisch nicht möglich, ohne genau den Fernwirkpfad zu bauen, den die Architektur ausschließt. Die Entwurfsentscheidung lautet deshalb: das System aktualisiert sich niemals gegen den Willen des Betreibers, aber es verkleinert nach Fristablauf selbsttätig die Angriffsfläche, die es selbst erzeugt hat.

| Verworfene Alternative | Grund der Verwerfung |
|---|---|
| Erzwungene Aktualisierung nach Frist | Verlangt einen Wirkpfad des Herstellers in fremde Systeme; widerspricht dem Notzugangsmodell und der Produktzusage |
| Keine Reaktion, nur Hinweis | Ein Hinweis auf eine ausgenutzte Schwachstelle an einer extern erreichbaren Komponente ist bei unbeaufsichtigtem Betrieb wirkungslos |
| Abschaltung des betroffenen Dienstes | Trifft auch Nutzer im internen Netz, für die die Schwachstelle nicht erreichbar ist; unverhältnismäßig |
| **Gewählt: Rückzug der externen Veröffentlichung** | Entfernt die Erreichbarkeit von außen, lässt den internen Betrieb bestehen, ist über die Veröffentlichung ein bestehendes Objekt und damit rücknehmbar |

Die Schwäche ist zu benennen: der Betreiber kann diese Richtlinie auf weich stellen, und dann schützt ihn nichts mehr. Der Entwurf verlangt für diese Umstellung eine Begründung, ein Auditereignis und ein dauerhaftes Banner im Überblick; er verhindert sie nicht, weil eine nicht abschaltbare Richtlinie in einem selbst betriebenen System eine Fremdsteuerung wäre.

- **R-21-26** — Eine Sicherheitsaktualisierung der Außenkante mit bekannter Ausnutzung erzeugt spätestens 72 h nach Verfügbarkeit entweder eine eingespielte Fassung oder eine zurückgezogene externe Veröffentlichung. Prüfbar: Zeitsimulation ohne Bedienerhandlung; Erreichbarkeitsprüfung von außen (K-23).
- **R-21-27** — Das Umstellen der Rückzugsrichtlinie auf weich erzeugt 1 Auditereignis und ein dauerhaftes Banner im Überblick. Prüfbar: Richtlinienänderung und Anzeigetest (INV-18).

## 21.8 Diagnosepaket

| Enthalten | Form |
|---|---|
| Fassungen aller Komponenten, Abbildhashwerte, Stücklistenverweise (SPDX und CycloneDX) | Klartext |
| Knotenliste mit Zustand, Rollen, Fehlerzone, Abbildversion, Leasestatus, Zeitgüte | pseudonymisiert |
| Istzustand aller Objekte mit Beobachtungszeitpunkt (INV-28), ohne Werte von Nutzfeldern | pseudonymisiert |
| Strukturauszug des Sollzustands: Objekttypen, Zahlen, Beziehungen, Richtlinienwerte | pseudonymisiert, ohne Anzeigenamen |
| Vorgangsprotokoll der letzten 7 d mit Ergebnis und benannten Resten | pseudonymisiert |
| Betriebsprotokoll der letzten 24 h, Fehler- und Warnstufe | geschwärzt |
| Metrikauszug der Nachweisliste, 48 h, 60-s-Raster | Zahlen |
| Ablaufspuren fehlgeschlagener Vorgänge der letzten 7 d | pseudonymisiert |
| Konnektorzustände mit Manifestkennung, Vertragsversion und Endpunktrumpf | Endpunkt ohne Pfad und ohne Zugangsdaten |
| Kopf der Auditkette: Hashwerte und Signaturzeitpunkte, keine Ereignisinhalte | Klartext |
| Ergebnis der letzten Wiederherstellungsprobe (K-24) | Klartext |

| Ausdrücklich nicht enthalten | Grund |
|---|---|
| Geheimnisse jeder Art, auch verschlüsselt | INV-20; ein Paket ist kein Ort für Schlüsselmaterial |
| Private Schlüssel, Wurzel- und Ausgabe-CA-Material | INV-22; verlässt den Knoten nie |
| Nutzdaten aus Speicherbereichen, Postfachinhalte, Dateiinhalte | Produktgrenze; Diagnose braucht sie nicht |
| Auditereignisinhalte | INV-23; der Auditstrom hat einen eigenen, gesonderten Exportweg |
| Anzeigenamen, Mailadressen, Anmeldenamen im Klartext | Datenminimierung; ersetzt durch Pseudonyme |
| Kopplungscodes, Wiederherstellungscode und dessen Verifikationswert | Ein Paket darf niemals einen Weg ins System enthalten |
| Vollständige externe Gegenstellenadressen | gekürzt auf Netzanteil |

**Schwärzung.** Jede Kennung wird durch ein Pseudonym ersetzt, das aus einem je Paket neu erzeugten Zufallsschlüssel und der Objektkennung abgeleitet wird. Der Schlüssel bleibt im System und wird nicht Teil des Pakets; die Konsole führt die Zuordnungstabelle, sodass der Bediener ein Pseudonym aus einem Supportbericht selbst auflösen kann, der Empfänger des Pakets aber nicht. Gleiche Objekte tragen innerhalb eines Pakets dasselbe Pseudonym und über Pakete hinweg unterschiedliche; die zweite Eigenschaft verhindert die Zusammenführung mehrerer Pakete, kostet aber die Vergleichbarkeit zweier Pakete derselben Installation. Der Bediener kann die Vergleichbarkeit durch die Wahl eines vorhandenen Paketschlüssels ausdrücklich herstellen; die Voreinstellung ist der neue Schlüssel.

**Erzeugung.** Ein Vorgang in "Verlauf & Nachweis". Vor der Erzeugung zeigt die Wirkungsvorschau die Inhaltsliste mit Datensatzzahlen je Kategorie und der geschätzten Größe (INV-08). Erzeugung ist ein Auditereignis.

```
Groessenabschaetzung (Annahmen, Installation mit 8 Knoten):
  Strukturauszug Sollzustand, etwa 10 % von K-12                 5,0 MB
  Betriebsprotokoll 24 h, 2 % Fehler-/Warnanteil, Faktor 8 komprimiert
      8 Knoten x 259 MB/d x 0,02 / 8                             5,2 MB
  Metrikauszug: 200 Reihen x 2.880 Punkte x 2 B                  1,15 MB
  Spuren fehlgeschlagener Vorgaenge, 7 d, 1 % von 11,5 MB/d      0,8 MB
  Rest (Listen, Hashwerte, Stuecklistenverweise)                 0,1 MB
  Summe                                                         12,25 MB
  Zielwert Obergrenze 50 MB, Erzeugungsdauer Zielwert <= 120 s
```

**Weitergabe.** Das System versendet das Paket nicht. Der Bediener lädt es herunter und entscheidet über den Empfänger. Existiert eine Konnektorbindung für Herstellerunterstützung mit aktiver Einwilligung der Stufe 3, ist der Versand eine ausdrückliche Handlung je Paket mit eigener Bestätigung; eine Einwilligung gilt nie für künftige Pakete.

- **R-21-28** — Ein erzeugtes Diagnosepaket enthält kein Geheimnismuster, keinen privaten Schlüssel und keine Mailadresse im Klartext. Prüfbar: Musterprüfung über das erzeugte Paket in der Bauprüfung; ein Treffer bricht den Bau (INV-20).
- **R-21-29** — Die Inhaltsliste der Wirkungsvorschau stimmt mit dem erzeugten Paket überein. Prüfbar: Abgleich der Kategorien und Datensatzzahlen zwischen Vorschau und Paket; eine Abweichung bricht den Bau.
- **R-21-30** — Kein Diagnosepaket verlässt die Installation ohne eine Bestätigung, die genau dieses Paket benennt. Prüfbar: Versuch eines automatischen Versands nach Erzeugung; 0 ausgehende Übertragungen.

## 21.9 Fernunterstützung

| Eigenschaft | Festlegung |
|---|---|
| Auslösung | ausschließlich durch den Betreiber als Vorgang; es existiert kein eingehender Pfad, den der Hersteller öffnen kann |
| Zugangsweg | die reguläre, veröffentlichte API auf 443/tcp; kein zusätzlicher Port, kein rückwärts aufgebauter Kanal, kein SSH |
| Anmeldemittel | Dienstkonto mit einem Zertifikat, das für diese Sitzung ausgestellt wird; Laufzeit = Sitzungsdauer + 5 min |
| Rechte | eine ausdrücklich zugewiesene Rolle mit Rechteliste und Mandantengeltung; nie die Plattformrolle durch Vererbung |
| Zeitbegrenzung | Voreinstellung 60 min, Obergrenze 4 h; keine Verlängerung, nur ein neuer Vorgang |
| Sichtbarkeit | dauerhaftes Banner während der Sitzung, Liste der Handlungen in Echtzeit, Markierung jedes in der Sitzung erzeugten Vorgangs |
| Auditierung | Sitzungsbeginn, Sitzungsende, jede API-Anfrage mit Pfad, Objektbezug und Ergebnis, alle mit derselben Korrelationskennung (INV-23) |
| Abbruch | eine Handlung in der Konsole; Wirkung ≤ 5 s: Token entwertet, Zertifikat gesperrt, Sperrliste verteilt, Verbindung geschlossen |
| Nach der Sitzung | Sperrung des Sitzungszertifikats, Bericht mit vollständiger Handlungsliste im Verlauf, kein Restzustand |

Es gibt keinen dauerhaften Zugang, keinen Herstellerschlüssel im System und keinen Unterstützungsmodus, der einen Neustart überlebt. Ein Unterstützungszugang, der nach einem Neustart weiterbesteht, wäre nicht mehr von einer Hintertür zu unterscheiden.

**Benannte Grenze.** Der Weg über die veröffentlichte API setzt voraus, dass die Installation von außen erreichbar ist. Für eine Installation ohne externe Erreichbarkeit — und das ist der Regelfall in den Umgebungen aus 21.10 — ist Fernunterstützung nicht möglich. Der verbleibende Weg ist das Diagnosepaket aus 21.8 zusammen mit einer Fernkonsole, die der Betreiber selbst bedient. Der Entwurf baut dafür ausdrücklich keinen ausgehenden Unterstützungstunnel, weil ein ausgehend aufgebauter Kanal die Firewall des Betreibers umgeht und damit die Zusage aus INV-10 aushöhlt.

- **R-21-31** — Ohne einen freigegebenen Unterstützungsvorgang scheitert jede Anmeldung mit einem Unterstützungsdienstkonto. Prüfbar: Anmeldeversuch vor und nach Ablauf der Sitzung; 0 Erfolge.
- **R-21-32** — Der Abbruch einer Unterstützungssitzung beendet jede laufende Anfrage dieser Sitzung innerhalb von 5 s. Prüfbar: Abbruch während einer laufenden Anfrage; Messung bis zum Verbindungsende und Prüfung des Sperrlisteneintrags.
- **R-21-33** — Jede in einer Unterstützungssitzung erzeugte Änderung trägt im Verlauf die Kennzeichnung der Sitzung. Prüfbar: Stichprobenabgleich aller Vorgänge einer Testsitzung; 100 % gekennzeichnet.

## 21.10 Betrieb ohne Internetanbindung

### Depot, Aktualisierung, Nachweisbarkeit

Ein Spiegel wird auf einer verbundenen Maschine erzeugt und als signiertes Bündel auf einem Datenträger übertragen. Das Bündel enthält die Abbilder, die Katalogeinträge, die Konnektormanifeste und — entscheidend — den signierten Kopf des Transparenzprotokolls mit Zeitstempel nach RFC 3161 sowie die Inklusionsnachweise für jedes enthaltene Artefakt. Ohne den Protokollkopf kann eine abgetrennte Installation eine gezielt für sie gebaute Fassung nicht von einer allgemein veröffentlichten unterscheiden; mit ihm prüft sie die Inklusion lokal.

Die Grenze ist zu benennen: die Konsistenz des Transparenzprotokolls über die Zeit — der Nachweis, dass kein Eintrag nachträglich entfernt wurde — verlangt einen frischeren Protokollkopf als den letzten eingelesenen. Eine abgetrennte Installation kann diesen Nachweis nicht selbst führen. Die Konsole zeigt deshalb dauerhaft "Stand des Transparenzprotokolls: <Datum>" und weist ab 30 Tagen Alter darauf hin.

Der Einspielweg ist der Vorgang "Datenträger einlesen": Signaturprüfung, Inklusionsprüfung, Fassungsfolgeprüfung gegen Rückspielen, dann der reguläre A/B-Pfad aus 21.4. Ein Datenträger ist kein Sonderweg an der Prüfkette vorbei.

### Zeitquelle

```
Annahme: freilaufender Oszillator, 10 ppm
  10e-6 s/s x 86.400 s/d = 0,864 s/d absolute Abweichung
  Grenze aus K-30: 500 ms
  500 ms / 0,864 s/d = 0,58 d = rund 14 h bis zum Ueberschreiten
```

Die Rechnung gilt für die **absolute** Abweichung gegenüber der wahren Zeit. Die **relative** Abweichung zwischen den Knoten bleibt klein, weil alle Knoten sich gegen den Ankerknoten als interne Zeitquelle disziplinieren; Leases, Raft-Fristen und die Selbstabschottung (K-07, INV-06) hängen an der relativen Abweichung und sind deshalb im abgetrennten Betrieb nicht gefährdet. Gefährdet sind die absolut bewerteten Größen: Zertifikatsgültigkeitsfenster, Zeitstempel im Auditstrom und die Übereinstimmung mit externen Gegenstellen bei einer späteren Wiederanbindung.

Daraus folgt eine konkrete Betriebsanforderung, keine Empfehlung: eine abgetrennte Installation braucht eine lokale Referenz — einen GNSS-Empfänger oder eine Funkuhr am Ankerknoten — oder sie akzeptiert eine erklärte, nicht geprüfte Zeit. Die Konsole unterscheidet die drei Zustände "geprüft" (NTS gegen ≥ 2 Quellen), "lokal referenziert" und "erklärt, nicht geprüft" und zeigt den dritten dauerhaft an (INV-18). Blockiert wird im dritten Zustand nichts, weil eine Installation, die keine geprüfte Zeit haben kann, sonst überhaupt nicht betreibbar wäre.

### Was entfällt

| Funktion | Zustand ohne Internetanbindung | Ersatz |
|---|---|---|
| Öffentlich vertrauenswürdige Zertifikate (ACME, RFC 8555) | entfällt | interne CA; externe Veröffentlichungen werden mit benanntem Grund abgelehnt |
| Externe DNS-Delegierung und DS-Setzung | entfällt | nur interne Sicht je Domäne |
| Mailzustellung nach außen | entfällt | interne Zustellung; Maildomänen bleiben unverifiziert |
| Konnektorbindungen zu fremdgehosteten Systemen | entfällt | nur Bindungen zu Systemen im selben Netz |
| Zeitsynchronisation nach NTS | entfällt | lokale Referenz oder erklärte Zeit |
| Abruf neuer Fassungen und Katalogeinträge | entfällt | Datenträgerweg |
| Ausleitung von Protokollen nach außen | entfällt | lokale Aufbewahrung nach 21.1 |
| Fernunterstützung | entfällt | Diagnosepaket nach 21.8 |
| Sperrlistenabruf externer CAs | entfällt | betrifft nur extern ausgestellte Zertifikate, die es hier nicht gibt |

- **R-21-34** — Ein über Datenträger eingelesenes Abbild durchläuft dieselbe Prüfkette wie ein aus dem Depot bezogenes, einschließlich Inklusionsnachweis. Prüfbar: Einlesen eines signierten, aber nicht im Transparenzprotokoll enthaltenen Abbilds; Ablehnung mit benanntem Grund.
- **R-21-35** — Die Konsole zeigt den Zustand der Zeitquelle als einen von drei benannten Werten und die Installation verweigert bei "erklärt, nicht geprüft" keine Funktion. Prüfbar: Zustandsmatrixtest über alle drei Werte (INV-18, INV-32).

## 21.11 Übernahme bestehender Umgebungen

### Reihenfolge und ihre Begründung

```
1  DNS       zuerst, weil alles andere ueber Namen adressiert wird und
             DNS parallel laufen kann, ohne dass ein Umschaltpunkt noetig ist
2  Verzeichnisdienst  danach, weil Personen und Gruppen die Voraussetzung
             jeder Zuweisung sind
3  Dateidienste       danach, weil ihre Rechte aus dem Verzeichnisdienst stammen
4  Mail      zuletzt, weil der Umschaltpunkt eine MX-Aenderung ist und
             verlorene Post nicht wiederholbar ist
```

| Bereich | Probelauf | Parallelbetrieb | Umschaltpunkt | Rückfall |
|---|---|---|---|---|
| DNS | Zone einlesen, erzeugte Zone eintragsweise gegen die bestehende vergleichen, Abweichungsbericht | Atrium autoritativ zunächst nur für die interne Sicht | NS- und DS-Änderung beim Registrar | NS zurück; Wirkung begrenzt durch die TTL |
| Verzeichnisdienst | Import nur lesend, Abgleichbericht mit Treffern, Kollisionen und nicht abbildbaren Attributen | Atrium schreibt als SCIM-Client (RFC 7644) in den bestehenden Dienst, oder der bestehende Dienst bleibt Anmeldequelle | Anwendungen zeigen mit LDAP (RFC 4511) oder OIDC auf Atrium | Anwendungen zurückzeigen; der alte Dienst wurde nie gelöscht |
| Dateidienste | Kopie mit Prüfsummenvergleich, Bericht über nicht übersetzbare Rechteeinträge | neue Seite nur lesend | Freigabename bzw. Pfad umgelenkt | zurücklenken; Schreibvorgänge der neuen Seite müssen zurückgeführt werden |
| Mail | Maildomäne anlegen und verifizieren, ohne MX zu ändern | Doppelzustellung oder Weiterleitung | MX-Änderung | MX zurück; Wirkung begrenzt durch die TTL |

### TTL-Rechnung für jeden Umschaltpunkt mit DNS-Wirkung

```
Alte TTL 86.400 s. Ein Rueckfall wirkt erst, wenn die alte Antwort ueberall
abgelaufen ist.
  Schritt 1: TTL auf 300 s senken
  Schritt 2: mindestens die alte TTL abwarten: 86.400 s = 24 h
  Schritt 3: umschalten
  Rueckfallwirkung danach: <= 300 s statt <= 24 h
```

Ohne Schritt 1 und 2 ist der Rückfallplan nominell vorhanden und praktisch unbrauchbar, weil er einen Tag braucht. Das ist die häufigste Stolperstelle jeder Namensumstellung und deshalb ein Pflichtschritt des Vorgangs, kein Ratschlag.

### Typische Stolperstellen, je Bereich

| Bereich | Stolperstelle | Umgang im Entwurf |
|---|---|---|
| Verzeichnisdienst | Kennworthashwerte sind nicht übertragbar | Der bestehende Dienst bleibt während der Übergangszeit Anmeldequelle, oder die Personen richten ihre Anmeldemittel neu ein; die Konsole nennt die Zahl der betroffenen Personen vor dem Umschalten. Der Entwurf löst das nicht, weil es nicht lösbar ist |
| Verzeichnisdienst | Kollidierende Anmeldenamen, verschachtelte Gruppen mit Zyklen, Kontakte ohne Anmeldung, deaktivierte aber lizenzierte Konten | Abgleichbericht listet jede Kollision einzeln; der Import bricht nicht ab, sondern lässt die betroffenen Objekte aus und zählt sie |
| DNS | Handgepflegte Einträge ohne Quelle im Objektgraphen | Sie werden als handeingegebene Einträge übernommen und als solche gekennzeichnet; abgeleitete Einträge entstehen erst mit der jeweiligen Veröffentlichung (INV-09) |
| DNS | DNSSEC-Schlüsselwechsel während der Umstellung | Delegierung erst nach abgeschlossenem Schlüsselwechsel; die Konsole sperrt den DS-Schritt, solange ein Wechsel läuft |
| Dateidienste | Übersetzung von Rechteeinträgen ist verlustbehaftet | Der Bericht nennt die Zahl nicht übersetzbarer Einträge je Freigabe; die Umstellung verlangt je Freigabe eine Entscheidung, statt stillschweigend zu verwerfen |
| Dateidienste | Offene Dateihandhaben, Pfadlängen, Groß-/Kleinschreibung, Zeitstempel | Prüfliste im Probelauf; jede Verletzung erscheint als Position mit Pfad |
| Mail | SPF muss während der Parallelphase beide Systeme führen (RFC 7208) | Die Maildomäne erzeugt den kombinierten Eintrag automatisch und entfernt das alte System erst mit dem Abschluss des Vorgangs |
| Mail | DKIM-Auswahlkennungen beider Systeme müssen gleichzeitig gültig sein (RFC 6376) | Überlappender Betrieb zweier Auswahlkennungen, wie in [Kapitel 14](14-mail.md) für den Schlüsselwechsel festgelegt |
| Mail | DMARC-Richtlinie darf während der Umstellung nicht verschärft werden (RFC 7489) | Der Vorgang sperrt eine Verschärfung, solange die Parallelphase läuft |
| Mail | Postfachinhalte | Nicht Gegenstand von Atrium: Atrium verwaltet Adressen, Postfachobjekte und Zuweisungen, nicht Postfachinhalte (INV-30). Die Inhaltsmigration ist eine getrennte Aufgabe mit den Werkzeugen des Postfachanbieters |

Allgemein gilt: Atrium löscht in keiner Übernahme etwas im Altsystem (INV-11). Jede Umstellung ist ein Vorgang mit Wirkungsvorschau, jeder Umschaltpunkt hat einen benannten Rückfallpunkt, und der Punkt ohne Rückweg wird je Bereich ausdrücklich benannt, bevor er erreicht wird.

- **R-21-36** — Jeder Umschaltpunkt mit DNS-Wirkung erzwingt die TTL-Senkung und die Wartezeit in Höhe der alten TTL als Schritte des Vorgangs. Prüfbar: Versuch des Umschaltens ohne abgeschlossene Wartezeit; Ablehnung mit benanntem Grund.
- **R-21-37** — Ein Verzeichnisimport bricht bei Kollisionen nicht ab, sondern lässt die betroffenen Objekte aus und nennt ihre Zahl und Art. Prüfbar: Import eines Bestands mit Namenskollision, Gruppenzyklus und nicht abbildbarem Attribut (INV-12).
- **R-21-38** — Kein Übernahmevorgang löscht oder verändert ein Objekt im Altsystem. Prüfbar: Istzustandsvergleich des Altsystems vor und nach dem Probelauf und nach dem Parallelbetrieb; 0 Änderungen.

## 21.12 Ausstieg

| Gegenstand | Format | Vollständigkeit | Anmerkung |
|---|---|---|---|
| Sollzustand | JSON in kanonischer Form (RFC 8785), signiert (RFC 8032), mit `schema_version` | vollständig | Semantik ist Atriums Semantik; lesbar, aber nicht von anderen Systemen unmittelbar verwertbar |
| Auditstrom | JSON Lines mit Hashkette und periodischen Signaturen samt Zeitstempeln (RFC 3161) | vollständig für die Aufbewahrungsfrist (K-25) | prüfbar ohne Atrium |
| Personen, Gruppen, Zuweisungen | SCIM-Schema (RFC 7643) | vollständig; Zuweisungen mit Rolle und Zielsystem | die Wirkungsableitung der Zuweisung ist Atrium-spezifisch |
| DNS | Zonendatenformat je Domäne und Sicht, DNSSEC-Schlüssel als öffentliche Anteile | vollständig | private Signierschlüssel nicht enthalten, Neusignierung erforderlich |
| Zertifikate und Vertrauensanker | PEM, Kette vollständig | öffentliche Anteile vollständig | private Schlüssel nicht enthalten |
| Nutzdaten je Speicherbereich | Dateibaum im Klartext; Datenbankdienste zusätzlich als eigener Ausgabestand der Engine | vollständig | Größe bestimmt die Dauer |
| Katalog- und Lieferkettenangaben | Liste der Katalogeinträge mit Fassung, Abbildverweis und Stücklistenverweisen (SPDX, CycloneDX) | vollständig | die Abbilder selbst sind OCI-Artefakte und separat beziehbar |
| Metriken der Nachweisliste | Textformat für Zeitreihen | 13 Monate | alles außerhalb der Liste ist nach 30 d nicht mehr vorhanden |
| Konnektormanifeste und Feldeigentumsabbildungen | JSON | vollständig | dokumentiert, welches Feld Atrium in welchem Fremdsystem besessen hat |

| Nicht exportierbar | Grund | Weg für den Betreiber |
|---|---|---|
| Private Schlüssel aus TPM oder Token | verlassen den Knoten nie (INV-20, Kanon) | Neuausstellung im Nachfolgesystem |
| Wurzel-CA-Schlüssel | liegt außerhalb des Systems (INV-22) | er ist bereits beim Betreiber |
| Geheimnisse für Konnektorbindungen | schreibbar, nie lesbar (INV-20) | der Export enthält die Liste der neu zu hinterlegenden Geheimnisse mit Verweisname, Typ und Zielsystem |
| Zustand fremdbesessener Felder in Fremdsystemen | gehört dem Fremdsystem (INV-13) | Export aus dem Fremdsystem |
| Istzustand | Beobachtung, kein Eigentum, datiert (INV-28) | entfällt |

Der Ausstieg ist ein Vorgang "Installation stilllegen": vollständiger Exportsatz, Prüfung des Exports durch Rückimport in eine Probeinstallation nach dem Verfahren aus K-24, Anzeige der Liste des nicht Exportierbaren mit Zahlen, dann erst die Freigabe der Datenträger mit der Aufbewahrungsfrist von 30 Tagen (K-25, INV-11). Zielwert: der Exportsatz ohne Nutzdaten einer mittleren Installation nach den Annahmen aus K-12 ist in ≤ 60 min erzeugt und geprüft; die Nutzdaten folgen ihrem Volumen.

**Ehrliche Abgrenzung des Bindungsgrads.** Unmittelbar in ein anderes System übernehmbar sind Personen und Gruppen, DNS-Daten, Dateien, Datenbankstände, Zertifikatsketten und der Auditnachweis. Nicht übernehmbar ist die Semantik, die den Produktnutzen ausmacht: die Zuweisung als Absichtsobjekt, die Richtlinien, die Veröffentlichungen und sämtliche abgeleiteten Artefakte. Sie sind lesbar dokumentiert, aber kein anderes System kennt sie. Wer Atrium verlässt, verliert keine Daten und behält die Nachweise, muss die Absichtsebene aber neu aufbauen. Diese Aussage steht hier, weil das Gegenteil zu behaupten unredlich wäre.

## 21.13 Unterstützungszeitraum, Versionslinien, Abkündigung

| Gegenstand | Festlegung |
|---|---|
| Linie | An die Ubuntu-LTS-Basis gebunden; eine Linie je Basis. Zielbasis und Übergangsbasis stehen im Kanon |
| Fassungsbezeichnung | `<Linie>.<Folge>`, ganzzahlig aufsteigend, keine Namen, keine Sprünge zur Kennzeichnung von Bedeutung |
| Unterstützung | Zielwert: Sicherheitsaktualisierungen bis 12 Monate nach der Produktionsreife der Nachfolgelinie |
| Überlappung | Zielwert: ≥ 12 Monate, in denen beide Linien Sicherheitsaktualisierungen erhalten |
| Funktionsfassungen | ausschließlich auf der jeweils aktuellen Linie |
| Linienwechsel | Abbildwechsel über denselben A/B-Pfad, mit vorausgehender Schemamigration als eigenem Vorgang (INV-24), mit Kanarienknoten und rollender Reihenfolge wie jede andere Ausrollung |

| Abkündigungsgegenstand | Vorlauf bis zur Entfernung | Bestandsschutz |
|---|---|---|
| API-Feld oder Endpunkt | 12 Monate; Entfernung nur mit `MAJOR` | bis zur Entfernung unverändert nutzbar |
| Konnektorvertragsversion | 12 Monate nach dem Hauptversionssprung | laufende und vorhergehende Hauptversion werden bedient |
| Katalogeintrag, Sicherheitsgrund | 6 Monate zwischen "abgekündigt" und "zurückgezogen" | Bestand läuft weiter, keine Neuanlage |
| Katalogeintrag, sonstiger Grund | 12 Monate | Bestand läuft weiter, keine Neuanlage |
| Linie | 12 Monate nach Produktionsreife der Nachfolgelinie | Sicherheitsaktualisierungen bis zum Ende |

Ein Abkündigungshinweis, der nur den Gegenstand und ein Datum nennt, ist unbrauchbar. Ein Hinweis enthält verbindlich: den Gegenstand, das Datum der Abkündigung, das Datum der Entfernung, die Zahl und Liste der **in dieser Installation** betroffenen Objekte, den Ersatzweg und den Vorgang, der die Umstellung ausführt. Fehlt die Liste der betroffenen Objekte, muss der Bediener selbst suchen, ob ihn die Abkündigung angeht — und genau das ist die Arbeit, die das Produkt abnehmen soll.

- **R-21-39** — Jeder Abkündigungshinweis nennt die Zahl und die Liste der in dieser Installation betroffenen Objekte. Prüfbar: Abkündigungstest mit betroffenen und nicht betroffenen Beständen; ein Hinweis ohne Objektliste bricht den Bau.
- **R-21-40** — Ein abgekündigter Katalogeintrag lässt sich nicht neu einsetzen, und bestehende Dienste daraus laufen unverändert weiter. Prüfbar: Neuanlageversuch und Betriebsprüfung eines Bestandsdienstes.
- **R-21-41** — Ein Linienwechsel verwendet denselben A/B-Pfad, dieselben Kanarienregeln und dieselbe rollende Reihenfolge wie jede andere Ausrollung. Prüfbar: Linienwechseltest gegen die Ausrollungsdefinition; ein Sonderpfad bricht den Bau.

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Ein Testvorgang über 3 Konnektorbindungen lässt sich über genau 1 Korrelationskennung vollständig aus Protokoll, Spuren und Auditstrom zusammenführen | R-21-01 | Zusammenführungstest; eine Zeile ohne Kennung bricht den Bau |
| Der Sollzustandsexport wächst nach 72 h Dauerlast nicht über die aus der Objektzahl folgende Größe hinaus | R-21-02 | Größenprüfung gegen K-12 |
| Der Metrikspeicher bleibt unter 12 GB und lehnt die 25.001-te Zeitreihe mit benanntem Grund ab | R-21-03 | Lasttest mit erzwungener Kardinalitätsexplosion |
| Die Nachweisliste enthält höchstens 200 Zeitreihen | R-21-04 | Abzählung im Bau |
| Eine Abfrage über alle vier Ströme mit mandantengebundener Rolle liefert 0 fremde Datensätze | R-21-05 | Abfragetest je Strom (INV-19) |
| Aus atrium-core, atrium-node und jedem Konnektorprozess sind 0 Verbindungen zu einer Herstelleradresse möglich, solange keine Einwilligung besteht | R-21-06 | Netznamensraumtest (INV-21) |
| Ein Widerruf beendet eine laufende Übermittlung ≤ 60 s und erzeugt genau 1 Auditereignis | R-21-07 | Widerruf während eines Uploads |
| Die angezeigte Feldliste je Einwilligungsstufe stimmt mit dem gesendeten Schema überein | R-21-08 | Vertragstest; Abweichung bricht den Bau |
| Jeder Dienst mit Veröffentlichung hat Indikator, Zielwert, Fenster und sichtbares Restbudget | R-21-09 | Abgleich Dienstliste gegen Indikatorliste |
| Ein aufgebrauchtes Budget hält die Ausrollung des Dienstes an und lässt eine Sicherheitsaktualisierung durch | R-21-10 | Fehleranteilsinjektion während einer Ausrollung |
| Die Zahl zustellender Alarmregeln beträgt höchstens 12 | R-21-11 | Abzählung im Bau |
| Jede Alarmregel bezieht sich auf ein Symptom der Indikator- oder Symptomliste | R-21-12 | Regelabgleich im Bau |
| Ein Knotenausfall mit ≥ 10 Diensten erzeugt genau 1 Zustellung | R-21-13 | Ausfalltest mit Zustellungszählung |
| Eine stille Zeit endet ≤ 8 h und unterdrückt 0 nicht unterdrückbare Ereignisse | R-21-14 | Auslösung jedes nicht unterdrückbaren Ereignisses während einer stillen Zeit |
| Ein Knoten mit fehlerhaftem Abbild startet ohne Kontrollebene selbsttätig in den vorherigen Platz zurück, Rückfallzeit ≤ 5 min | R-21-15 | Aktualisierung mit fehlerhaftem Abbild bei abgeschalteter Kontrollebene (K-23) |
| Nach einem Rückfall erfolgen 0 erneute Zuweisungen derselben Fassung an denselben Knoten | R-21-16 | Wiederholte Ausrollung |
| Ein gültig signiertes älteres Abbild wird abgelehnt | R-21-17 | Rückspielversuch |
| Ein Abbild mit Schema- und Laufzeitänderung in einem Schritt wird bei der Freigabe abgelehnt | R-21-18 | Freigabeversuch (INV-24) |
| Eine Schemamigration ohne vorhandenen, geprüften Export wird abgelehnt | R-21-19 | Migrationsversuch ohne Export |
| Für n ∈ {1, 4, 8, 27} beträgt die Kanarienzahl 1, 1, 1, 4 und enthält 0 Verwaltungsknoten | R-21-20 | Ausrollungssimulation |
| Jedes der 7 Abbruchkriterien hält die Ausrollung ≤ 60 s an | R-21-21 | Injektion je Kriterium |
| Jede Freigabemeldung nennt die Zahl der rückmeldenden Installationen aus Ring 1 | R-21-22 | Feldprüfung der Freigabemeldung |
| Bei fortlaufenden Schreibvorgängen während einer Ausrollung über 3 und 5 Stimmknoten treten 0 Schreibfehler außerhalb von K-05 auf | R-21-23 | Rollende Ausrollung unter Last (INV-04) |
| Im Mischbetrieb liefert dieselbe Anfrage unabhängig vom bearbeitenden Knoten dieselbe Antwort | R-21-24 | Verhaltenstest gegen beide Fassungen |
| Ein Mischbetrieb über 7 d erzeugt 1 Alarm mit Knotenzahl je Fassung | R-21-25 | Zeitsimulation |
| 72 h nach Verfügbarkeit einer ausgenutzten Außenkantenschwachstelle ist die Fassung eingespielt oder die externe Veröffentlichung nicht mehr erreichbar | R-21-26 | Zeitsimulation ohne Bedienerhandlung, Erreichbarkeitsprüfung von außen (K-23) |
| Das Weichstellen der Rückzugsrichtlinie erzeugt 1 Auditereignis und ein dauerhaftes Banner | R-21-27 | Richtlinienänderung und Anzeigetest (INV-18) |
| Ein Diagnosepaket enthält 0 Geheimnismuster, 0 private Schlüssel, 0 Mailadressen im Klartext | R-21-28 | Musterprüfung über das erzeugte Paket (INV-20) |
| Kategorien und Datensatzzahlen aus Vorschau und Paket stimmen überein | R-21-29 | Abgleich Vorschau gegen Paket (INV-08) |
| Nach der Erzeugung eines Pakets erfolgen 0 ausgehende Übertragungen ohne paketbezogene Bestätigung | R-21-30 | Versandtest |
| Anmeldeversuche mit einem Unterstützungsdienstkonto vor und nach der Sitzung ergeben 0 Erfolge | R-21-31 | Anmeldetest |
| Der Sitzungsabbruch beendet laufende Anfragen ≤ 5 s und trägt das Zertifikat in die Sperrliste ein | R-21-32 | Abbruch während laufender Anfrage |
| 100 % der in einer Unterstützungssitzung erzeugten Vorgänge tragen die Sitzungskennzeichnung | R-21-33 | Stichprobenabgleich |
| Ein signiertes, aber nicht im Transparenzprotokoll enthaltenes Abbild vom Datenträger wird abgelehnt | R-21-34 | Einleseversuch |
| Die Zeitquelle zeigt genau einen von drei benannten Werten; bei "erklärt, nicht geprüft" wird 0 Funktion verweigert | R-21-35 | Zustandsmatrixtest (INV-32) |
| Ein Umschalten ohne abgeschlossene TTL-Wartezeit wird abgelehnt | R-21-36 | Umschaltversuch im Vorgang |
| Ein Import mit Namenskollision, Gruppenzyklus und nicht abbildbarem Attribut bricht nicht ab und nennt Zahl und Art der ausgelassenen Objekte | R-21-37 | Importtest (INV-12) |
| Der Istzustand des Altsystems ist nach Probelauf und Parallelbetrieb unverändert | R-21-38 | Vorher-Nachher-Vergleich (INV-11) |
| Ein Abkündigungshinweis ohne Liste betroffener Objekte bricht den Bau | R-21-39 | Abkündigungstest mit und ohne Bestand |
| Ein abgekündigter Katalogeintrag erlaubt 0 Neuanlagen; Bestandsdienste laufen unverändert | R-21-40 | Neuanlage- und Betriebsprüfung |
| Ein Linienwechsel verwendet 0 Sonderpfade gegenüber der allgemeinen Ausrollungsdefinition | R-21-41 | Abgleich gegen die Ausrollungsdefinition |

## Offene Punkte

1. **Erkennungsvermögen gegen Datensparsamkeit.** Die Rechnung in 21.5 zeigt, dass Fehler mit einer Auftretenswahrscheinlichkeit von fünf Prozent innerhalb einer einzelnen Installation nicht durch Staffelung erkennbar sind und dass eine Frühnehmergruppe von mindestens 150 rückmeldenden Installationen nötig wäre. Die Rückmeldung setzt eine Einwilligung voraus, die voreingestellt abgeschaltet ist. Zu entscheiden ist, ob Ring 1 durch eine Gegenleistung gebildet wird (längerer Unterstützungszeitraum, frühere Fassungen), ob die Freigabe bei zu kleiner Rückmeldebasis verzögert wird, oder ob der Entwurf hinnimmt, dass die Erkennung überwiegend bei den Installationen des Herstellers stattfindet. Alle drei Wege haben Kosten, und keiner ist bisher gewählt.

2. **Fehlerbudget bei kleiner Ereigniszahl.** Die Budgetrechnung in 21.2 nimmt 20 Anfragen je Sekunde an. Eine Installation mit fünfzehn Personen und drei Diensten hat Größenordnungen weniger Ereignisse; dort ist der Anteil erfolgreicher Anfragen statistisch so verrauscht, dass die Verbrauchsratenschwellen aus 21.2 entweder ständig auslösen oder nie. Zu entscheiden ist, ob unterhalb einer Mindestereigniszahl je Fenster auf ein zeitbasiertes Verfügbarkeitsmaß umgeschaltet wird, ob die Fenster verlängert werden, oder ob Dienstgüteziele unterhalb einer Installationsgröße gar nicht aktiviert werden. Ohne eine Entscheidung ist das Verfahren für den häufigsten Installationstyp unbrauchbar.

3. **Kompatibilitätsfenster des Raft-Peer-Protokolls.** Das Fenster von zwei aufeinanderfolgenden Fassungen ist eine Entwurfsfestlegung ohne Herleitung. Ein zu enges Fenster macht jede unterbrochene Ausrollung zu einem Zwang, sofort weiterzumachen; ein zu weites Fenster zwingt die Kontrollebene, jede Protokolländerung über mehrere Linien hinweg mitzuführen, und das ist die Stelle, an der Konsensimplementierungen erfahrungsgemäß Fehler bekommen. Zu entscheiden ist die Fensterbreite, und zu klären ist, wie eine Installation behandelt wird, die zwei Fassungen übersprungen hat, weil sie ein Jahr abgetrennt betrieben wurde.

4. **Rückfall nach angewandter Schemamigration.** Der Entwurf kennt für den Fall "Migration angewandt, Laufzeit muss zurück" nur den Import des Exports mit Verlust aller Änderungen seit der Migration. Eine rückwärts anwendbare Migration je Schemaänderung wäre die saubere Lösung, verdoppelt aber den Entwicklungs- und Testaufwand jeder Schemaänderung und ist für Änderungen, die Information verdichten, nicht vollständig möglich. Zu entscheiden ist, ob rückwärts anwendbare Migrationen für eine benannte Teilmenge von Schemaänderungen verpflichtend werden und wie die Konsole den Unterschied zwischen "rückrollbar" und "nur über Import rückholbar" darstellt, bevor der Bediener die Migration auslöst.

5. **Unabhängigkeit der Alarmzustellung.** Der Entwurf verlangt ein Alarmziel außerhalb der Installation, kann dessen Unabhängigkeit aber nicht prüfen; eine Mailadresse bei demselben Anbieter, dessen Ausfall die Störung auslöst, erfüllt die Anforderung formal und nicht sachlich. Zu entscheiden ist, ob ein zweiter Zustellweg mit anderer Technik (Kurznachricht, eigenständiger Melder) verpflichtend wird, wie er ohne eine neue Abhängigkeit zu einem Fremddienst umgesetzt wird und wie sich das mit der Zusage verträgt, dass ohne Einwilligung nichts das System verlässt.

6. **Zeitgüte im abgetrennten Betrieb.** Der Zustand "erklärt, nicht geprüft" blockiert nach dem Entwurf keine Funktion, obwohl INV-32 eine belegte Zeitgüte für Führung, Zertifikatsausstellung und Auditschreiben verlangt. Die Rechnung in 21.10 zeigt, dass die absolute Abweichung nach etwa vierzehn Stunden die Grenze aus K-30 überschreitet. Zu entscheiden ist, ob eine lokale Referenz für den abgetrennten Betrieb verpflichtend wird, ob die Zertifikatslaufzeiten in diesem Zustand verkürzt oder verlängert werden, und wie ein Auditzeitstempel bewertet wird, der aus einer erklärten Zeit stammt — das betrifft unmittelbar die Beweiskraft gegenüber [Kapitel 22](22-compliance.md).

7. **Pseudonymschlüssel je Diagnosepaket.** Ein neuer Schlüssel je Paket verhindert die Zusammenführung mehrerer Pakete, verhindert aber auch den Vergleich zweier Pakete derselben Installation vor und nach einer Änderung — und dieser Vergleich ist in der Störungsanalyse der Normalfall. Die angebotene Wahl des vorhandenen Schlüssels verschiebt die Entscheidung auf den Bediener, der die Folgen nicht überblickt. Zu entscheiden ist, ob ein installationsgebundener, aber empfängergebunden abgeleiteter Schlüssel der bessere Entwurf ist und wie er ohne eine dauerhafte Schlüsselverwaltung für Empfänger umgesetzt wird.

8. **Umfang der Ausstiegszusage.** Der Entwurf sichert einen vollständigen Export aller Daten zu, nicht aber die Übertragbarkeit der Absichtsebene. Ob das ausreicht, ist eine Produkt- und keine technische Frage, und sie berührt die Argumentation in [Kapitel 23](23-oekonomie-roadmap.md). Zu entscheiden ist, ob zusätzlich ein dokumentiertes, stabiles Austauschformat für Zuweisungen, Richtlinien und Veröffentlichungen gepflegt wird, das andere Systeme lesen könnten, und wer die Last trägt, dieses Format über Schemahauptversionen hinweg stabil zu halten.

9. **Prüfbarkeit der Fehlalarmrate.** Die Ableitung der höchstens zwölf zustellenden Regeln in 21.3 stützt sich auf eine angenommene Störungshäufigkeit von einer je Monat. Für diese Annahme liegt kein Messwert vor, und sie unterscheidet sich zwischen einer Installation mit drei Knoten und einer mit zweiunddreißig erheblich. Zu entscheiden ist, ob die Regelzahl an die Installationsgröße gekoppelt wird und wie die Präzision im Betrieb überhaupt gemessen werden soll, ohne dass der Bediener jede Zustellung nachträglich als nützlich oder unnütz einstuft — eine Rückmeldung, die selbst wieder Bedienaufwand ist und damit der Grundzusage des Produkts widerspricht.
