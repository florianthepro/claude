# 06 Basissystem: Ubuntu als Unterbau

## 6.1 Bauen oder aufsetzen: die Pflegelast, gerechnet

`KANON.md`, Abschnitt 4 legt Ubuntu LTS als Basis fest und nennt als einzigen belastbaren Vorteil die fremdgepflegte Treiber- und Sicherheitsversorgung. Dieser Abschnitt rechnet nach, was die Alternative kostet, und leitet daraus die Randbedingungen ab, unter denen der Vorteil erhalten bleibt.

### Modell der Sicherheitspflege eines selbstgebauten Systems

**Annahme A1 (Paketumfang).** Ein minimales Serversystem ohne grafische Arbeitsumgebung umfasst 1.000 selbst zu pflegende Quellpakete. Begründung in zwei Teilen: Die Stückliste eines Atrium-Abbilds führt nach [Kapitel 20](20-sicherheit.md), Abschnitt 20.10 rund 900 Einheiten in der transitiven Hülle; davon entfallen die Eigenentwicklung in Rust und die Fremdkomponenten der Datenebene auf rund ein Drittel, sodass rund 600 Einheiten aus der Basis stammen. Ein selbstgebautes System muss zusätzlich die Bauwerkzeugkette selbst pflegen — Übersetzer, Binärwerkzeuge, Bausysteme, Bootstrap —, für die hier 400 weitere Quellpakete angesetzt werden.

**Annahme A2 (Meldungsrate).** Die Rate ist nicht gleichverteilt, sondern von einer kleinen Gruppe dominiert. Das Modell schichtet deshalb:

| Klasse | Gegenstand | Pakete | Meldungen je Paket und Jahr | Meldungen je Jahr |
|---|---|---|---|---|
| H | Kern, C-Bibliothek, TLS-Bibliothek, Dienstverwaltung, Skriptsprachenlaufzeiten, Containerlaufzeit, Netzdienste | 40 | 8,0 | 320 |
| M | Bibliotheken mit Netz- oder Dateiformatberührung, Werkzeuge mit Fremdeingaben | 260 | 0,8 | 208 |
| L | Rest ohne Fremdeingabe im Auslieferungszustand | 700 | 0,05 | 35 |
| **Summe** | | **1.000** | 0,563 (gewichtetes Mittel) | **563** |

**Annahme A3 (Bearbeitungsaufwand).** Bewertung jeder Meldung 0,1 Personentage; Anteil mit Behebungsbedarf 30 Prozent; Behebung im Mittel 1,45 Personentage, hergeleitet aus je zur Hälfte einem Versionssprung (0,4 PT) und einer Rückportierung in einen eingefrorenen Stand (2,5 PT); Bau, Regressionsprüfung und Freigabe zusätzlich 0,25 Personentage je Behebung.

```
Bewertung        563       x 0,10 PT  =  56,3 PT/a
Behebungsfaelle  563 x 0,30           = 168,9 Faelle
Behebung         168,9     x 1,45 PT  = 244,9 PT/a
Bau und Pruefung 168,9     x 0,25 PT  =  42,2 PT/a
-------------------------------------------------
Summe                                 = 343,4 PT/a
bei 200 produktiven Tagen je Person   =   1,72 Personenjahre je Jahr
```

Der Wert gilt für **eine** unterstützte Linie. [Kapitel 22](22-compliance.md), Abschnitt 22.8 rechnet mit fünf gleichzeitig unterstützten Linien und 0,3 Personentagen je Rückportierung und älterer Linie; das ergibt zusätzlich 168,9 × 4 × 0,3 = 202,7 PT/a und eine Gesamtlast von 546,1 PT/a oder 2,73 Personenjahren je Jahr.

### Empfindlichkeit

| Variierte Größe | Wert | Meldungen je Jahr | Aufwand [PT/a] | Personenjahre je Jahr |
|---|---|---|---|---|
| Rate der Klasse H | 4,0 | 403 | 245,8 | 1,23 |
| Rate der Klasse H | 8,0 (Basisfall) | 563 | 343,4 | 1,72 |
| Rate der Klasse H | 16,0 | 883 | 538,6 | 2,69 |
| Behebungsanteil | 20 % | 563 | 247,8 | 1,24 |
| Behebungsanteil | 40 % | 563 | 439,1 | 2,20 |

Die Spanne reicht von 1,2 bis 2,7 Personenjahren je Jahr und enthält keinen Wert, bei dem die Sicherheitspflege einer selbstgebauten Basis nebenbei erledigbar wäre. Das ist ein Modell, keine Messung.

### Die Untergrenze ist die Erreichbarkeit, nicht das Volumen

K-23 verlangt für die Außenkante eine Freigabe innerhalb von 72 Stunden nach Veröffentlichung. Eine solche Zusage über 365 Tage verlangt eine Rufbereitschaft mit Kenntnis der Basispakete. **Annahme:** Eine Person trägt höchstens eine Woche je Monat Rufbereitschaft, ohne dauerhaft überlastet zu sein; daraus folgen mindestens vier Personen. Die Volumenrechnung von 1,72 Personenjahren unterschreitet diese Besetzungsuntergrenze um mehr als die Hälfte. Der teure Teil einer eigenen Basis ist folglich nicht die Arbeitsmenge, sondern die dauerhaft vorzuhaltende Reaktionsfähigkeit.

### Gegenrechnung für das abgeleitete System

Im abgeleiteten System entfällt die Behebung, weil sie aus der Basis kommt; es bleibt die Bewertung, ob die eigene Zusammenstellung betroffen ist. [Kapitel 20](20-sicherheit.md) beziffert diesen Rest mit 9,9 Personentagen jährlich. Das Verhältnis 343,4 / 9,9 ergibt Faktor 34,7. Die beiden Modelle benutzen unterschiedliche Meldungsraten (0,563 gegen 0,05 im Mittel) und sind deshalb nicht unmittelbar vergleichbar; die Zusammenführung beider Modelle steht aus und ist unter "Offene Punkte" vermerkt. Unabhängig davon ändert sich nicht der Umfang, sondern die Art der Aufgabe: aus Behebung wird Bewertung.

### Nicht quantifizierbare Gründe

| Grund | Wirkung bei Ableitung | Wirkung bei Eigenbau |
|---|---|---|
| Hardwareunterstützung | Treiber für Speichercontroller, Netzkarten und Sicherheitsbausteine kommen mit dem Kern der Basis | Jede Treiberrückportierung und jede Regression liegt beim Projekt |
| Firmware | Gerätefirmware ist paketiert, lizenzgeprüft und mit dem Kern abgestimmt | Eigene Sammlung mit eigener Lizenzprüfung je Gerät |
| Zertifizierung durch Hardwarehersteller | Serverhersteller zertifizieren Modelle gegen benannte Distributionen; ein abgeleitetes System erbt die Prüfung faktisch | Kein Bezugspunkt gegenüber dem Hardwarehersteller im Supportfall |
| Langzeitunterstützung | Eine LTS-Serie mit zugesagtem Wartungszeitraum ist Voraussetzung dafür, dass Atrium überhaupt einen Unterstützungszeitraum zusagen kann (R-22-23) | Die Zusage ist vollständig selbst zu decken |

Die Zertifizierung ist eine geerbte Tatsache, kein übertragbares Recht: Sie gilt der Basis, nicht Atrium, und wird nicht als eigene Zertifizierung dargestellt. [Kapitel 23](23-oekonomie-roadmap.md), Risiko RS-03 benennt die Kehrseite: Atrium hängt an Entscheidungen eines Dritten über Paketpolitik, Markenrichtlinie und Freigabekadenz, und ein Basiswechsel kostet mehrere Personenjahre.

### Entscheidung

Atrium wird aus einer LTS-Serie abgeleitet. Verworfen: der Eigenbau, wegen der Rechnung und der Besetzungsuntergrenze; eine rollende Basis, weil sie mit einem zugesagten Unterstützungszeitraum und mit einem reproduzierbaren Bau gegen einen eingefrorenen Stand unvereinbar ist.

- **R-06-01** — Jedes Abbild wird ausschließlich aus Paketen der im Kanon festgelegten Basis und aus dem Projektarchiv gebaut. Prüfbar: Bauprüfung gegen die Festschreibungsdatei; eine Quelle außerhalb dieser beiden bricht den Bau.
- **R-06-02** — Das Abbild enthält keinen vom Basisstand abweichenden Kern. Prüfbar: Vergleich der Kernpaketprüfsumme gegen den Basisstand bei jeder Freigabe.

## 6.2 Aufbau des Abbilds

```
GPT-Datentraegerbelegung eines Knotens

 p1  Startpartition        FAT, signierter Startlader                 512 MiB
 p2  Abbildplatz A         unveraenderlich, dm-verity                  8 GiB
 p3  Hashbaum A            Hashbaum + signierter Wurzelhashwert     64,5 MiB
 p4  Abbildplatz B         wie A                                       8 GiB
 p5  Hashbaum B            wie p3                                   64,5 MiB
 p6  Systemzustand         ZFS: Knotenidentitaet, Schluesselmaterial,
                           Sollzustandsauszug, Auditstrom, Schreib-
                           schicht der Konfiguration                   20 GiB
 p7  Dienstdaten           ZFS: <pool>/atrium/<mandant>/<dienst>/...   Rest
```

**Rechnung Hashbaum.** 8 GiB bei 4 KiB Blockgröße ergeben 2.097.152 Blöcke; mit SHA-2 zu 32 Byte je Block ist Ebene 1 64 MiB groß, Ebene 2 512 KiB, Ebene 3 4 KiB, darüber der Wurzelhashwert. Summe 64,5 MiB, also 0,79 Prozent des Abbilds.

**Rechnung Mindestdatenträger.** 0,5 GiB + 2 × 8 GiB + 2 × 0,063 GiB + 20 GiB = 36,6 GiB vor jeder Dienstdatenzuweisung. **Zielwert:** kleinster unterstützter Systemdatenträger 64 GB. Der Auditstrom passt in den Systemzustand: **Annahme** 200 schreibende Vorgänge je Tag zu je 5 Ereignissen à 1,5 KB ergeben 1,5 MB je Tag und 547,5 MB in zwölf Monaten (K-25).

### Getrennte Schreibbereiche

| Bereich | Inhalt | Verhalten bei Abbildwechsel | Verhalten bei Rückfall |
|---|---|---|---|
| Abbildplatz | Betriebssystem, alle Programme, unveränderliche Grundeinstellungen | wird ersetzt (inaktive Hälfte) | Umschalten auf die vorherige Hälfte |
| Systemzustand | Knotenidentität, TPM-gebundenes Schlüsselmaterial, Sollzustandsauszug, Auditstrom, Maschinenkennung, Wirtsschlüssel | unverändert | unverändert |
| Dienstdaten | Speicherbereiche der Dienste nach [Kapitel 17](17-speicher-backup.md) | unverändert | unverändert |

Die Trennung ist die Voraussetzung dafür, dass ein Rückfall in die vorherige Abbildhälfte keine Daten verwirft. Sie hat eine Kehrseite, die benannt wird: Eine Schemaänderung des Sollzustands, die der Systemzustand bereits mitvollzogen hat, überlebt den Rückfall der Laufzeit. Genau deshalb trennt INV-24 beide Schritte; der Rückfall der Laufzeit ist ohne den Rückfall des Schemas nur zulässig, wenn die ältere Laufzeit das Schema noch liest.

### Konfigurationsdateien, die klassisch handgepflegt werden

Das Wurzeldateisystem ist unveränderlich, also liegt der Konfigurationsbaum als Schreibschicht über dem Abbild, mit der beschreibbaren Hälfte im Systemzustand. Jede Datei dieser Schicht trägt einen deklarierten Eigentümer, analog zum Feldeigentum aus INV-13:

| Eigentümer | Beispiele | Verhalten |
|---|---|---|
| Abbild | Grundeinstellungen fremdgepflegter Komponenten | liegen im unveränderlichen Teil; Änderung nur durch eine neue Freigabe |
| Reconciler | erzeugte Diensteinheiten, Regelsätze, Sichtzuordnungen des Resolvers | werden bei jedem Abgleich neu erzeugt; eine Handänderung wird überschrieben und erzeugt das Auditereignis "Abweichung korrigiert" (INV-02) |
| Knotenidentität | Maschinenkennung, Wirtsschlüssel, Zufallssaat | einmalig beim ersten Start erzeugt, danach unveränderlich, im Systemzustand und nicht im Abbild |

Die Schreibschicht wird bei jedem Start aus dem Sollzustandsauszug neu aufgebaut und nicht über einen Abbildwechsel mitgeschleppt. Grund: Eine mitgeschleppte Schicht würde nach einem Rückfall eine Konfiguration über eine Laufzeit legen, die sie nicht erzeugt hat. Dateien ohne deklarierten Eigentümer existieren nicht; ein Fund beim Startinventar wird entfernt und auditiert.

- **R-06-03** — Nach der Erstinstallation sind beide Abbildplätze mit derselben Fassung beschrieben. Prüfbar: Partitionsprüfung unmittelbar nach der Installation.
- **R-06-04** — Das Wurzeldateisystem der aktiven Hälfte ist im Betrieb nicht beschreibbar und blockweise gegen den signierten Wurzelhashwert geprüft. Prüfbar: Schreibversuch schlägt fehl; ein manipulierter Block erzeugt einen Lesefehler statt veränderter Daten.
- **R-06-05** — Ein Abbildwechsel und ein Rückfall verändern weder den Systemzustand noch die Dienstdaten. Prüfbar: Prüfsummenvergleich beider Bereiche vor und nach Wechsel und Rückfall.
- **R-06-06** — Jede Datei der beschreibbaren Konfigurationsschicht trägt einen deklarierten Eigentümer; eine nicht deklarierte Datei wird beim Startinventar entfernt und auditiert. Prüfbar: Ablage einer Fremddatei vor dem Start (INV-02).
- **R-06-07** — Das Abbild enthält keine Paketverwaltungswerkzeuge. Prüfbar: Dateiinventar des Abbilds gegen eine Sperrliste von Werkzeugnamen.

## 6.3 Startkette

| Stufe | Prüfendes Element | Geprüftes Element | Ergebnis bei Abweichung |
|---|---|---|---|
| 1 | Firmware mit UEFI Secure Boot | Startlader | Der Knoten startet nicht; Rückfall in die andere Hälfte |
| 2 | Startlader | Kern, Befehlszeile, Startabbild | wie Stufe 1 |
| 3 | Kern | Wurzeldateisystem über dm-verity | Lesefehler statt veränderter Daten; Störungsmeldung, Knoten wird geräumt |
| 4 | TPM 2.0 | Gesamtergebnis der Stufen 1 bis 3 | Entsiegelung schlägt fehl: keine Dienstaufnahme, keine Zertifikatsausstellung, Meldung an die Kontrollebene |

Die Registerpolitik und die Entsiegelung gegen eine mit dem Freigabeschlüssel signierte Richtlinienaussage sind in [Kapitel 20](20-sicherheit.md), Abschnitt 20.9 festgelegt und werden hier nicht wiederholt. Für das Basissystem folgt daraus eine Bauvorgabe: Kern, Befehlszeile und Wurzelhashwert gehören in ein gemeinsam signiertes Startabbild, weil eine getrennt übergebene Befehlszeile den Wurzelhashwert austauschbar machte und Stufe 3 damit entwertete.

### Hardware ohne gesicherten Start und ohne Sicherheitsbaustein

| Zusicherung | Secure Boot und TPM | ohne Secure Boot | ohne TPM | ohne beides |
|---|---|---|---|---|
| Ausgeführter Code entspricht beim Start dem signierten Abbild | ja | nein: die Kette beginnt bei unauthentisiertem Code | ja | nein |
| Unbemerkte Veränderung des Abbilds im Betrieb ausgeschlossen | ja | teilweise: dm-verity wirkt, aber der Wurzelhashwert ist nicht mehr vertrauenswürdig verankert | ja | teilweise |
| Schlüsselmaterial an den Knotenzustand gebunden | ja | ja | nein: Ersatz ist eine Passphrase bei jedem Start oder Ablage im Systemzustand | nein |
| Privater Knotenschlüssel nicht exportierbar | ja | ja | nein: INV-20 bleibt organisatorisch, nicht technisch durchgesetzt | nein |
| Fernbezeugung des Startzustands | ja | nein | nein | nein |

**Entwurfsentscheidung.** Atrium läuft auf solcher Hardware und verweigert den Betrieb nicht, weil ein Produkt, das nur auf aktueller Serverhardware startet, den Einstieg unmöglich macht. Der Knoten wird dauerhaft mit den entfallenen Zusicherungen gekennzeichnet (INV-18) und kommt als Träger der Ausgabe-CA nicht in Betracht, deren Schlüssel nach `KANON.md`, Abschnitt 4 TPM- oder tokengebunden ist. Er kann Dienstträger und Speicherträger sein. Ein virtualisiertes TPM ist nach [Kapitel 20](20-sicherheit.md) eine Aussage des Virtualisierungsanbieters über sich selbst und wird in der Konsole als solche gekennzeichnet, nicht als vorhandener Sicherheitsbaustein gezählt.

- **R-06-08** — Auf Hardware mit Secure Boot und TPM 2.0 gelingt die Entsiegelung nur bei erfüllter, mit dem Freigabeschlüssel signierter Richtlinienaussage. Prüfbar: Start mit ausgetauschtem Kern; die Entsiegelung schlägt fehl und der Knoten nimmt keinen Dienst auf.
- **R-06-09** — Ein Knoten ohne TPM 2.0 und ohne Hardwaretoken erhält die Rolle Ausgabe-CA-Träger nicht und ist dauerhaft mit den entfallenen Zusicherungen gekennzeichnet. Prüfbar: Die API lehnt die Rollenzuweisung ab; Zustandsmatrixtest über alle vier Hardwarefälle (INV-18).

## 6.4 Paketpolitik

| Quelle | Verwendung | Prüfung beim Einzug | Festlegung |
|---|---|---|---|
| Basis, wartungsgestützte Komponente | Standardfall | Archivsignatur der Basis | exakte Version mit Quell- und Binärprüfsumme |
| Basis, gemeinschaftlich gepflegte Komponente | nur mit Ausnahmeeintrag im Abhängigkeitsbudget | Archivsignatur der Basis | wie oben, zusätzlich Ablaufdatum des Eintrags |
| Ursprungsprojekt außerhalb der Basis | nur für Komponenten, die die Basis nicht führt | Signatur des Ursprungsprojekts und eigener Bau aus dem Quelltext | Quellstand mit Prüfsumme |
| Projektarchiv | Eigenentwicklung | Ed25519 (RFC 8032) | Quellstand mit Prüfsumme |

Das Projekt betreibt einen vollständigen Spiegel aller verwendeten Quell- und Binärpakete. Der Spiegel bedient drei Zwecke gleichzeitig: den Bau ohne Netzzugang ([Kapitel 20](20-sicherheit.md), Abschnitt 20.6), das Quelltextangebot ([Kapitel 23](23-oekonomie-roadmap.md)) und den Betrieb ohne Internetanbindung ([Kapitel 21](21-betrieb-updates.md), Abschnitt 21.10). Die Prüfung erfolgt zweimal und unabhängig: beim Einzug in den Spiegel gegen die Archivsignatur der Basis, im Bau gegen die Prüfsumme der Festschreibungsdatei. Sichere Praxis an dieser Stelle: laufzeitkonstanter Vergleich aller Prüfsummen und Signaturen, Schemavalidierung der Festschreibungsdatei am Rand mit Abweisung unbekannter Felder, keine Interpolation von Paketnamen oder Pfaden in eine Kommandozeile, keine Auflösung von Abhängigkeiten zur Bauzeit.

### Verhältnis zu den Sicherheitsaktualisierungen der Basis

Knoten führen keine Paketaktualisierung durch. Es gibt im Betrieb keinen Pfad, der ein Paket der Basis austauscht; das Wurzeldateisystem ist unveränderlich, und die Werkzeuge dafür sind nicht Teil des Abbilds. Eine Sicherheitsaktualisierung der Basis wird zu einem neuen Abbild und läuft über den A/B-Pfad aus [Kapitel 21](21-betrieb-updates.md).

```
Latenzrechnung fuer den Aussenkantenfall (Zielwerte, keine Messung)

 Spiegelabgleich (stuendlich)                            <= 1,0 h
 Bewertung "betroffen / nicht betroffen"                 <= 1,0 h
 Abbildbau aus dem Spiegel                               <= 0,75 h
 automatisierte Pruefung des Abbilds                     <= 4,0 h
 Kanarienfenster nach dem ersten Knoten (K-23)           <= 0,5 h
 ---------------------------------------------------------------
 Summe bis zur Freigabe                                  <= 7,25 h
 Frist aus K-23                                             72 h
```

Deutung: Die Frist ist rechnerisch mit großer Reserve einhaltbar. Sie wird nicht vom Bau bestimmt, sondern von der Erkennung und der Besetzung — dasselbe Ergebnis wie in 6.1, nur aus der anderen Richtung. **Annahme** sechs reguläre Freigaben je Jahr und 13,5 Behebungsfälle jährlich aus [Kapitel 20](20-sicherheit.md) ergeben im Mittel 2,25 Behebungen je regulärer Freigabe; bei einem angenommenen S0-Anteil von 5 Prozent fällt weniger als eine außerplanmäßige Freigabe je Jahr an. Der Bereitschaftsapparat wird selten gebraucht und muss dauerhaft besetzt sein.

### Erweiterte Wartung und Unterstützungszeitraum

Die Basis führt eine reguläre, im Umfang der LTS-Serie zugesagte Wartung und eine entgeltliche erweiterte Wartung, die den Zeitraum verlängert und den Umfang auf weitere Komponenten ausdehnt. Fristen, Umfänge und Vertragsbedingungen werden hier nicht genannt, weil sie vertraglich und zeitabhängig sind; das Whitepaper beschreibt die Abhängigkeit, nicht den Vertrag.

| Weg | Folge für den Unterstützungszeitraum | Kosten und Risiko |
|---|---|---|
| (a) Reguläre Wartung der Basis | kürzer, entgeltfrei | Betreiber muss häufiger eine Hauptversion mitgehen |
| (b) Erweiterte Wartung der Basis | länger | Eine entgeltliche Fremdleistung wird zur Voraussetzung; die Weitergabe einer Berechtigung an Installationen ist ungeklärt |
| (c) Basiswechsel vor Ablauf | unabhängig von (b) | Setzt eine risikoarme Hauptversionsmigration von Atrium voraus |

**Entwurfsentscheidung:** (c) als Regelweg, (b) als Option für Installationen, die eine Hauptversion nicht mitgehen können. Begründung: (b) als Regelweg macht ein quelloffenes Produkt von einer entgeltlichen Fremdleistung abhängig. Die Schwäche des gewählten Wegs wird benannt: Er verlagert Aufwand auf den Betreiber und verlangt, dass die Hauptversionsmigration tatsächlich risikoarm ist; beziffert ist dieser Aufwand bisher nicht.

- **R-06-10** — Jedes im Bau verwendete Paket ist im Projektspiegel mit Quell- und Binärprüfsumme festgeschrieben. Prüfbar: vollständiger Bau ohne Netzzugang.
- **R-06-11** — Prüfsummen- und Signaturvergleiche im Einzugs- und Bauweg sind laufzeitkonstant; Paketnamen und Pfade werden nicht in eine Kommandozeile interpoliert. Prüfbar: Quelltextprüfung im Bau gegen beide Muster.
- **R-06-12** — Auf einem produktiven Knoten existiert kein Pfad, der ein Paket der Basis austauscht. Prüfbar: Versuch über alle bekannten Werkzeugnamen schlägt fehl (INV-03).
- **R-06-13** — Der deklarierte Unterstützungszeitraum einer Freigabe endet nicht später als der Wartungszeitraum der zugrundeliegenden Basisserie in der genutzten Wartungsstufe; die Freigabemetadaten nennen Serie und Stufe maschinenlesbar. Prüfbar: Schemaprüfung der Freigabemetadaten gegen R-22-23.

## 6.5 Kern und Treiber

Die Treiberfrage ist mit der Basis weitgehend gelöst, weil der Kern der LTS-Serie unverändert übernommen wird und mit ihm die gesamte Gerätedatenbank. Der Preis dieser Lösung ist, dass sie nur so lange gilt, wie das Projekt keinen eigenen Kern baut; das ist der Grund für R-06-02.

| Geräteklasse | Warum sie Aufmerksamkeit braucht | Behandlung |
|---|---|---|
| Speichercontroller | Ohne Treiber gibt es kein Wurzeldateisystem, und der Abbruch fällt vor jede Möglichkeit, eine Meldung anzuzeigen. Hardware-RAID verdeckt zudem den direkten Blockzugriff, den ZFS für Prüfsummen und Selbstheilung braucht | Das Installationsmedium erkennt vor dem Schreiben und bricht mit benanntem Grund ab; ein Controller im RAID-Modus wird abgelehnt statt stillschweigend überlagert |
| Netzkarten | Ohne Treiber gibt es keinen Kopplungsendpunkt und damit keinen Weg in die Oberfläche | Der Kopplungscode wird lokal angezeigt; für den Fall ohne Netz existiert kein Weg in der Konsole. Das ist die einzige bekannte Ausnahme von INV-17 und wird als solche geführt |
| Sicherheitsbaustein | Zugriffspfad und Registerbelegung sind herstellerabhängig; Firmware-TPM und diskretes TPM unterscheiden sich in den Zusicherungen | Erkennung und Klartextanzeige "vorhanden", "nicht vorhanden" oder "virtuell", mit den Folgen aus 6.3 |
| Grafikausgabe für die Erstinstallation | Gebraucht wird nur eine Textausgabe für den Kopplungscode, nicht beschleunigte Grafik | Ausgabe über die Firmware-Grafikschnittstelle, über die serielle Schnittstelle und über die Fernkonsole des Herstellers |

### Firmware

Gerätefirmware kommt als Paket der Basis und wird nicht eigenständig gesammelt. Eine Plattform-Firmwareaktualisierung — Systemfirmware, Datenträger, Netzkarte — ist dagegen ein Vorgang mit Wirkungsvorschau und läuft nie automatisch. Grund: Sie verändert eine Messung, die von keiner Freigabe des Projekts signiert ist, und bricht damit die Entsiegelung aus 6.3. Die Wirkungsvorschau benennt vorab, dass der Knoten nach dem Neustart sein versiegeltes Material neu binden muss. Die Neubindung erfolgt im Verbund gegen die Kontrollebene nach erfolgreicher Bezeugung; im Ein-Knoten-Fall bleibt nur der ausgedruckte Wiederherstellungscode, was eine Bedienerhandlung mit physischem Beleg erzwingt und unter "Offene Punkte" steht.

### Kernvarianten

`KANON.md`, Abschnitt 4 legt den GA-Kernel der LTS-Serie als Standard fest und lässt den HWE-Kernel nur für Hardware zu, die ihn nachweislich benötigt. Die Folge ist eine verdoppelte Baumatrix: **Annahme** sechs Freigaben je Jahr und zwei Kernvarianten ergeben zwölf Abbildbauten statt sechs und bei vier Stunden automatisierter Prüfung je Abbild 48 statt 24 Stunden Rechenzeit. Das ist tragbar, weil es Rechenzeit und nicht Personalzeit kostet. Teuer ist die Hardwareprüfmatrix: Jede geprüfte Maschine ist gegen beide Varianten zu prüfen.

| Hardwarestufe | Bedeutung | Zusage |
|---|---|---|
| geprüft | automatisiert auf definierten Maschinen geprüft | K-01 gilt |
| erwartet lauffähig | vom Hardwarehersteller gegen die Basis zertifiziert, vom Projekt nicht geprüft | keine Zeitzusage |
| unbekannt | weder noch | Installation zulässig, dauerhafte Kennzeichnung |

- **R-06-14** — Die Kernvariante folgt aus der Hardwareerkennung; die Konsole zeigt die laufende Variante und den Grund, und es existiert kein Bedienerfeld dafür. Prüfbar: Formularprüfung im Bau (INV-14, INV-15).
- **R-06-15** — Eine Plattform-Firmwareaktualisierung ist ein Vorgang mit Wirkungsvorschau, die die notwendige Neubindung der Versiegelung benennt, und hat keinen automatischen Auslöser. Prüfbar: Suche nach Auslösern im Sollzustandsmodell; ein Treffer bricht den Bau (INV-03, INV-08).
- **R-06-16** — Das Installationsmedium bricht bei fehlendem Speichertreiber oder bei einem Controller im RAID-Modus mit benanntem Grund ab. Prüfbar: Installationsversuch auf beiden Konfigurationen.
- **R-06-17** — Der Kopplungscode wird gleichzeitig über die Firmware-Grafikschnittstelle, die serielle Schnittstelle und die Fernkonsole ausgegeben. Prüfbar: Abgriff auf allen drei Wegen im Wartemodus.
- **R-06-18** — Die Konsole zeigt je Knoten die Hardwarestufe; die Zeitzusage aus K-01 wird nur für die Stufe "geprüft" dargestellt. Prüfbar: Darstellungstest über alle drei Stufen (INV-18).

## 6.6 Bauprozess

```
Quellstand (Projektarchiv, signierte Marke)
  |
  +-- Festschreibungsdatei: je Paket Name, Version, Quell- und Binaerpruefsumme
  |
  v
Spiegel (vollstaendig, netzgetrennt) --- Signaturpruefung des Basisarchivs
  |
  +---> Bau 1 (Infrastruktur A) ---+
  |                                |--> bitweiser Artefaktvergleich
  +---> Bau 2 (Infrastruktur B) ---+     ungleich => keine Freigabe
  |
  v
Abbild + Hashbaum + Wurzelhashwert
  +--> Stueckliste SPDX       (aus dem Bau erzeugt, nicht nachtraeglich abgeleitet)
  +--> Stueckliste CycloneDX  (ebenso)
  +--> Herkunftsnachweis: Quellstand, Bauumgebung, Bauzeit, Werkzeugstaende,
       Eingangsartefakte
  |
  v
Signatur Ed25519 (RFC 8032) ueber die kanonische Serialisierung (RFC 8785)
  von Abbild, Hashbaum, beiden Stuecklisten, Herkunftsnachweis und Freigabedaten
  |
  v
Transparenzprotokolleintrag mit Zeitstempel (RFC 3161)
  |
  v
Veroeffentlichung im Depot; Quellenbestand derselben Freigabe wird archiviert
```

Die Signatur-, Stücklisten- und Transparenzfestlegungen stehen in [Kapitel 20](20-sicherheit.md), Abschnitt 20.6. Basisspezifisch sind die Quellen der Nichtdeterminismus, die ein aus Distributionspaketen zusammengesetztes Abbild mitbringt: Pflegeskripte, die beim Einspielen Zeitstempel, Zufallswerte, Maschinenkennungen, Wirtsschlüssel oder neu berechnete Zertifikatsspeicher erzeugen. Behandlung: Diese Artefakte entstehen nicht im Abbild, sondern beim ersten Start im Systemzustand; das Abbild führt an ihrer Stelle einen definierten Leerwert. Ein Zweitbau, der sich nur in einer solchen Datei unterscheidet, gilt als fehlgeschlagen und nicht als tolerierbar, weil eine geduldete Ausnahme die Aussage des Zweitbaus aufhebt.

**Rechnung Bauzeit.** **Annahme** ein vollständiger Abbildbau aus dem Spiegel dauert 45 Minuten auf 16 Kernen; zwei unabhängige Bauläufe parallel ausgeführt kosten dieselbe Wanduhrzeit und doppelte Rechenzeit. Deutung: Der reproduzierbare Bau kostet Rechenzeit, nicht Personalzeit, und ist genau deshalb als Pflicht je Freigabe tragbar. Das ist ein Zielwert, keine Messung.

### Nachvollziehbarkeit vom Abbild zur Quelle

| Schritt | Von | Nach | Bindung |
|---|---|---|---|
| 1 | laufender Knoten | Wurzelhashwert der aktiven Hälfte | Istzustandsmeldung mit Beobachtungszeitpunkt (INV-28) |
| 2 | Wurzelhashwert | Freigabekennung | Transparenzprotokolleintrag |
| 3 | Freigabekennung | beide Stücklisten | gemeinsame Signatur |
| 4 | Stückliste | Quellpaketname, Version, Quellprüfsumme je Einheit | Stücklisteninhalt |
| 5 | Quellprüfsumme | Datei im archivierten Quellenbestand | Quelltextangebot nach [Kapitel 23](23-oekonomie-roadmap.md) |

- **R-06-19** — Zwei unabhängige Bauläufe desselben Quellstandes erzeugen bitgleiche Artefakte; ein Unterschied verhindert die Freigabe ohne Übersteuerungsmöglichkeit. Prüfbar: Zweitbau je Freigabe.
- **R-06-20** — Aus dem Wurzelhashwert eines laufenden Knotens ist ohne Mitwirkung des Herstellers die Quellprüfsumme jeder enthaltenen Einheit bestimmbar. Prüfbar: Durchlauf der fünf Schritte gegen ausschließlich veröffentlichte Artefakte.

## 6.7 Erstinstallation

Das Installationsmedium ist zugleich Quelle der ersten Abbildhälfte; während der Installation werden keine Pakete nachgeladen. Das Medium prüft seine eigene Signatur vor dem ersten Schreibvorgang.

```
Zeitrechnung der Installation (Annahmen, keine Messung)

 Firmwarestart der Serverhardware                  30 s bis 180 s
 Schreiben von 2 x 8 GiB bei 500 MB/s             2 x 17,2 s = 34,4 s
 Einmaliger Lesedurchlauf zur Hashbaumpruefung             17,2 s
 Anlage der ZFS-Pools                                   <= 10,0 s
 ----------------------------------------------------------------
 Summe                                        rund 1,5 min bis 4 min
 Ansatz in K-01                                              6 min
```

Der Ansatz aus K-01 enthält damit Reserve für langsamere Datenträger und für die Firmwareinitialisierung großer Maschinen, die mehrere Minuten dauern kann. Beide Abbildplätze werden bei der Installation beschrieben, damit ein Rückfall vom ersten Tag an möglich ist; das ist der Grund für die verdoppelte Schreibzeit.

### Unbeaufsichtigte Installation

Eine Vorgabedatei auf dem Medium oder auf einem zweiten Datenträger steuert die Installation. Sie wird am Rand gegen ein Schema geprüft; ein unbekanntes Feld führt zum Abbruch mit benanntem Grund und nicht zum stillschweigenden Ignorieren, weil eine ignorierte Vorgabe in einer Massenausrollung unbemerkt hunderte Knoten falsch konfiguriert. Sie enthält die Auswahlregel für den Zieldatenträger (nicht dessen Gerätenamen), die Netzkonfiguration, die Tastaturbelegung und wahlweise das Aufnahmetoken nach `KANON.md`, Abschnitt 4a.

### Was der Bediener tatsächlich eingibt

| Phase | Eingabe | Pflicht | Vorbelegung und ihre Quelle (INV-15) |
|---|---|---|---|
| Installation | Zieldatenträger | nur bei mehr als einem geeigneten Datenträger | der einzige geeignete Datenträger |
| Installation | Tastaturbelegung | nein | Spracheinstellung der Firmware |
| Wartemodus | keine | — | der Kopplungscode wird angezeigt, nicht eingegeben |
| Kopplung | Kopplungscode | ja | keine; Ablesen vom Bildschirm des Knotens |
| Ersteinrichtung | Sprache | nein | Tastaturbelegung |
| Ersteinrichtung | Mandantenname | ja | keine |
| Ersteinrichtung | Administrator mit Passkey-Registrierung | ja | Anmeldename wird abgeleitet |
| Ersteinrichtung | Basisdomäne | ja | keine |

**Zählkonvention, offengelegt.** K-01 nennt höchstens vier Entscheidungen in der Ersteinrichtung; gezählt werden Sprache, Mandantenname, Administrator und Basisdomäne. Zieldatenträger und Kopplungscode gehören zur Installation und zum Kopplungsvorgang und werden nicht mitgezählt. Die Konvention wird hier benannt, weil sie sonst als Beschönigung wirkt: Der Bediener trifft auf dem Weg bis zur ersten Anmeldung tatsächlich bis zu sechs Eingaben, von denen zwei außerhalb der Ersteinrichtungszählung liegen. Der weitere Ablauf bis zur ersten angemeldeten Sitzung steht in [Kapitel 5](05-systemarchitektur.md), Abschnitt 5.5, die Aufnahme weiterer Knoten in [Kapitel 16](16-cluster.md).

- **R-06-21** — Die Vorgabedatei der unbeaufsichtigten Installation wird gegen ein Schema geprüft; ein unbekanntes Feld führt zum Abbruch mit benanntem Grund. Prüfbar: Installation mit einer Datei, die ein zusätzliches Feld enthält.
- **R-06-22** — Das Installationsmedium prüft seine eigene Signatur vor dem ersten Schreibvorgang auf den Zieldatenträger. Prüfbar: verändertes Medium; die Installation bricht ab, bevor der Zieldatenträger beschrieben wird.

## 6.8 Rechtliches zur Basis

Dieser Abschnitt beschreibt die Behandlung im Entwurf und ersetzt keine rechtliche Prüfung.

| Gegenstand | Behandlung im Entwurf | Begründung |
|---|---|---|
| Weitergabe einer Zusammenstellung mit Copyleft-Bestandteilen | Quelltextangebot für alle Bestandteile, erzeugt aus der Festschreibungsdatei und nicht aus einer gepflegten Liste | Eine Handliste weicht von der tatsächlichen Zusammenstellung ab, sobald ein Paket hinzukommt |
| Verweis auf das Archiv der Basis anstelle eines eigenen Bestands | ausgeschlossen | Ein fremdes Archiv kann ältere Stände entfernen; die Pflicht besteht über den gesamten Unterstützungszeitraum (R-23-05) |
| Name und Zeichen der Basis im Produktnamen, in der Konsole, im Installationsprogramm, im Startbildschirm, in Paketnamen und Systembenutzern | ausgeschlossen, Vorkommen 0 | Markenrichtlinie der Basis; zugleich Fremdvokabular im Sinne von INV-16 |
| Sachliche Herkunftsangabe in der Fassungsansicht und in der Dokumentation | zulässig | Tatsachenangabe über die Herkunft der Pakete |
| Behauptung, die Basis leiste Unterstützung für Atrium | ausgeschlossen | Unzutreffend und für beide Seiten schädlich |

Die praktische Folge ist eine Prüfliste im Bau, die dieselbe Musterprüfung wie INV-16 benutzt und zusätzlich Paketnamen, Systembenutzer, Startbildschirm und Installationsprogramm abdeckt. Die Eingriffstiefe des Abbilds — unveränderliches Wurzeldateisystem, A/B-Umschaltung, abgeschaltete Verwaltungsoberflächen, erzeugte statt gepflegter Konfiguration — macht Atrium zu einem abgeleiteten System und nicht zu einer Basis mit Zusatzpaketen; die Zurückhaltung beim Namen ist deshalb sachlich richtig und nicht bloß vorsichtig. Die wirtschaftliche Seite steht in [Kapitel 23](23-oekonomie-roadmap.md), die regulatorische in [Kapitel 22](22-compliance.md).

- **R-06-23** — Vorkommen des Basisnamens und der Basiszeichen in Oberflächentexten, Installationsprogramm, Startbildschirm, Paketnamen und Systembenutzern sind 0, ausgenommen die Herkunftsangabe in Fassungsansicht und Dokumentation. Prüfbar: Musterprüfung im Bau; ein Treffer bricht den Bau (INV-16).
- **R-06-24** — Das Quelltextangebot wird aus der Festschreibungsdatei der jeweiligen Freigabe erzeugt. Prüfbar: Abgleich des Angebots gegen die Stückliste derselben Freigabe; eine Differenz bricht die Freigabe.

## 6.9 Abgrenzung: was unverändert bleibt

| Bestandteil | Behandlung | Grund |
|---|---|---|
| Kern und Gerätetreiber | unverändert übernommen | Jeder eigene Patch verschiebt die Sicherheitspflege nach 6.1 auf das Projekt |
| C-Bibliothek und Kernbibliotheken | unverändert | wie oben |
| Dienstverwaltung | unverändert, ausschließlich über erzeugte Einheiten benutzt | Die Einheiten sind abgeleitete Artefakte (INV-09) |
| Paketverwaltung | nicht Teil des Abbilds, nur in der Bauumgebung | Ein Werkzeug, dessen Schreiboperationen wirkungslos sind, führt den Bediener in die Irre und widerspricht INV-02 |
| Erstkonfigurations- und Netzkonfigurationsschichten der Basis | abgeschaltet, nur im Installationspfad wirksam | Sie wären eine zweite Wahrheitsquelle (INV-02) |
| Unbeaufsichtigte Aktualisierungsmechanismen der Basis | abgeschaltet | Sie umgehen den A/B-Pfad und erzeugen Meldungen ohne zugehörigen Vorgang |
| Verwaltungsoberflächen fremdgepflegter Kernkomponenten | abgeschaltet | INV-30 |
| Fernzugangsdienst | vorhanden, standardmäßig geschlossen, zertifikatsbasiert, nur nach auditiertem Vorgang | Portmatrix in `KANON.md`, Abschnitt 7 |

### Was eine Abweichung kostet

```
Ein einziges vom Basisstand abweichendes Paket (Annahmen):

  einmalige Uebernahme und Anwendung der Aenderung        0,5 PT
  Nachfuehrung bei 2 Sicherheitsaktualisierungen je Jahr  2 x 0,4 = 0,8 PT/a
  zusaetzliche Regressionspruefung                        0,2 PT/a
  --------------------------------------------------------------
  laufend je Linie und Jahr                               1,0 PT/a
  bei 5 gleichzeitig unterstuetzten Linien                5,0 PT/a
  zehn abweichende Pakete bei 5 Linien                   50,0 PT/a = 0,25 VZAE
```

Deutung: Zehn abweichende Pakete binden ein Viertel Vollzeitäquivalent dauerhaft, ohne eine Produkteigenschaft zu erzeugen, und der Betrag wächst linear mit der Zahl der Linien. **Zielwert:** 0 abweichende Basispakete. Jede Abweichung ist ein Eintrag im Abhängigkeitsbudget nach [Kapitel 20](20-sicherheit.md) mit Ablaufdatum und benanntem Weg zur Rückführung durch Einreichung stromaufwärts; ein Eintrag ohne Ablaufdatum wird nicht angelegt.

- **R-06-25** — Die Zahl der vom Basisstand abweichenden Pakete ist 0, oder jede Abweichung trägt einen Eintrag im Abhängigkeitsbudget mit Ablaufdatum und Rückführungsweg. Prüfbar: Abgleich der Festschreibungsdatei gegen den Basisstand bei jeder Freigabe; eine Abweichung ohne Eintrag bricht den Bau.

## Akzeptanzkriterien

| Kriterium | Anforderung | Nachweisverfahren |
|---|---|---|
| Der Bau schlägt fehl, sobald eine Paketquelle außerhalb von Basis und Projektarchiv benutzt wird | R-06-01 | Bauprüfung mit eingeschleuster Fremdquelle |
| Die Kernpaketprüfsumme des Abbilds stimmt mit dem Basisstand überein | R-06-02 | Prüfsummenvergleich je Freigabe |
| Nach der Erstinstallation tragen beide Abbildplätze dieselbe Fassung | R-06-03 | Partitionsprüfung nach unbeaufsichtigter Installation |
| Ein Schreibversuch auf das aktive Wurzeldateisystem schlägt fehl; ein manipulierter Block erzeugt einen Lesefehler | R-06-04 | Schreibversuch und Blockmanipulation im Prüfstand |
| Systemzustand und Dienstdaten sind vor und nach Wechsel und Rückfall prüfsummengleich | R-06-05 | A/B-Wechsel mit anschließendem Rückfall |
| Eine vor dem Start abgelegte Fremddatei in der Konfigurationsschicht wird entfernt und auditiert | R-06-06 | Startinventarprüfung |
| Das Dateiinventar des Abbilds enthält kein Paketverwaltungswerkzeug | R-06-07 | Inventarabgleich gegen eine Werkzeugnamensliste |
| Ein Start mit ausgetauschtem Kern führt zu fehlgeschlagener Entsiegelung und ausbleibender Dienstaufnahme | R-06-08 | Manipulationstest auf Hardware mit TPM 2.0 |
| Die Rollenzuweisung "Ausgabe-CA-Träger" an einen Knoten ohne TPM und ohne Token wird von der API abgelehnt | R-06-09 | API-Test plus Zustandsmatrixtest über vier Hardwarefälle |
| Ein vollständiger Abbildbau gelingt ohne Netzzugang | R-06-10 | Bau in einer netzgetrennten Umgebung |
| Prüfsummen- und Signaturvergleiche sind laufzeitkonstant, Paketnamen erreichen keine Kommandozeile | R-06-11 | Quelltextprüfung im Bau gegen beide Muster |
| Kein Werkzeugaufruf auf einem produktiven Knoten tauscht ein Basispaket aus | R-06-12 | Versuchsreihe über bekannte Werkzeugnamen |
| Die Freigabemetadaten nennen Basisserie und Wartungsstufe, und der Unterstützungszeitraum endet nicht später als deren Wartungszeitraum | R-06-13 | Schemaprüfung gegen R-22-23 |
| Kein Formular der Konsole bietet die Wahl der Kernvariante an; die laufende Variante und ihr Grund sind am Knoten sichtbar | R-06-14 | Formular- und Darstellungstest |
| Es existiert kein automatischer Auslöser für eine Plattform-Firmwareaktualisierung, und die Wirkungsvorschau nennt die Neubindung | R-06-15 | Suche im Sollzustandsmodell plus Vorschautest |
| Die Installation bricht bei fehlendem Speichertreiber und bei Controller im RAID-Modus mit benanntem Grund ab | R-06-16 | Installationsversuche auf beiden Konfigurationen |
| Der Kopplungscode ist im Wartemodus auf allen drei Ausgabewegen abgreifbar | R-06-17 | Abgriffstest je Weg |
| Die Zeitzusage aus K-01 erscheint nur bei Knoten der Hardwarestufe "geprüft" | R-06-18 | Darstellungstest über alle drei Stufen |
| Zwei unabhängige Bauläufe erzeugen bitgleiche Artefakte, und ein Unterschied verhindert die Freigabe ohne Übersteuerung | R-06-19 | Zweitbau je Freigabe |
| Die Kette Wurzelhashwert → Freigabe → Stückliste → Quellprüfsumme → Quelldatei ist ohne Herstellermitwirkung durchlaufbar | R-06-20 | Durchlauf ausschließlich über veröffentlichte Artefakte |
| Eine Vorgabedatei mit unbekanntem Feld führt zum Abbruch mit benanntem Grund | R-06-21 | Installationsversuch mit erweiterter Datei |
| Ein verändertes Installationsmedium bricht vor dem ersten Schreibvorgang ab | R-06-22 | Manipulationstest am Medium |
| Basisname und Basiszeichen kommen außerhalb der Herkunftsangabe nirgends vor | R-06-23 | Musterprüfung im Bau über alle geprüften Flächen |
| Quelltextangebot und Stückliste derselben Freigabe stimmen überein | R-06-24 | Abgleich je Freigabe |
| Jede Abweichung vom Basisstand trägt Ablaufdatum und Rückführungsweg | R-06-25 | Abgleich der Festschreibungsdatei gegen den Basisstand |

## Offene Punkte

1. **Zwei unvereinbare Meldungsratenmodelle.** [Kapitel 20](20-sicherheit.md) rechnet mit 0,05 Meldungen je Einheit und Jahr gleichverteilt, dieser Abschnitt mit einem geschichteten Mittel von 0,563. Die Differenz von Faktor elf wirkt unmittelbar auf die Aufwandsrechnungen in [Kapitel 22](22-compliance.md) und [Kapitel 23](23-oekonomie-roadmap.md). Zu entscheiden ist, welches Modell für alle Kapitel gilt und ob das Schichtenmodell auf die Stückliste des Abbilds angewandt wird, was die dortigen Aufwandszahlen deutlich erhöhte.
2. **Wartungsstufe der Basis und Weitergabe einer Berechtigung.** Weg (b) aus 6.4 setzt eine entgeltliche Fremdleistung voraus. Ob und unter welchen Bedingungen eine Berechtigung an eine Installation weitergegeben werden darf, ist eine vertragliche Frage, die weder beantwortet noch aus dem Gedächtnis rekonstruiert wird. Ohne Antwort ist der Unterstützungszeitraum nicht festlegbar.
3. **Prozessorarchitekturen.** Der Entwurf beschreibt eine Architektur. Eine zweite verdoppelt Baumatrix, Prüfmatrix, Spiegelgröße und Hardwareprüfstand und verändert die Rechnung in 6.5 und die Speicherrechnung in [Kapitel 23](23-oekonomie-roadmap.md). Die Entscheidung ist nicht getroffen und gehört vor den ersten Meilenstein mit Unterstützungszusage.
4. **Umfang und Finanzierung der geprüften Hardwareliste.** K-01 gilt nach 6.5 nur für die Stufe "geprüft". Ohne eine benannte Liste ist die Zusage ohne Geltungsbereich, und mit einer Liste entsteht ein dauerhaft zu betreibender Hardwareprüfstand. Zu entscheiden sind Umfang, Beschaffungsweg und wer die Prüfhardware bezahlt.
5. **Firmware-Neubindung im Ein-Knoten-Fall.** Der Entwurf verlangt dort den ausgedruckten Wiederherstellungscode. Das ist eine physische Bedienerhandlung für eine Routineaufgabe. Die Alternative, eine zweite vorgehaltene Versiegelungsrichtlinie, schwächt die Bindung an die Plattformmessung. Beide Wege haben Kosten, und keiner ist gewählt.
6. **Hardware-RAID.** Die entworfene Antwort ist Ablehnung, weil ZFS direkten Blockzugriff braucht. Das schließt einen erheblichen Teil vorhandener Serverhardware aus, deren Controller keinen reinen Durchreichemodus kennt. Zu entscheiden ist, ob ein gekennzeichneter Betrieb ohne Prüfsummen- und Selbstheilungszusage angeboten wird oder ob die Ablehnung bestehen bleibt.
7. **Katalogeinträge mit Kernanforderungen.** Ein Fremdprodukt kann ein Kernmodul verlangen, das nicht im Abbild liegt. Ein Nachladen widerspricht dem unveränderlichen Wurzeldateisystem, eine Aufnahme in das Abbild vergrößert die Stückliste und damit die Last aus 6.1. Zu entscheiden ist, ob solche Katalogeinträge abgelehnt werden oder ob eine begrenzte, im Abbild enthaltene Modulpositivliste geführt wird. Die Folgen berührt [Kapitel 15](15-dienste-software.md).
8. **Kosten der Hauptversionsmigration.** Weg (c) aus 6.4 ist als Regelweg gewählt, setzt aber eine risikoarme Migration zwischen Basisserien voraus. Deren Aufwand ist in [Kapitel 21](21-betrieb-updates.md) nicht beziffert. Solange er unbeziffert ist, ist die Entscheidung für (c) eine Annahme und keine Rechnung.
9. **Aufnahmetoken auf dem Installationsmedium.** Die Vorgabedatei darf nach 6.7 ein Aufnahmetoken tragen. Ein Medium, das Installationsabbild und Aufnahmegeheimnis zugleich führt, macht den Verlust des Mediums zu einem Kopplungsrisiko. Zu entscheiden ist, ob das Token auf einem getrennten Träger verlangt wird, was die unbeaufsichtigte Massenausrollung umständlicher macht.
