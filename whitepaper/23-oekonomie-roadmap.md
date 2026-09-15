# 23 Lizenz, Geschäftsmodell, Aufwand und Roadmap

## 23.1 Lizenzwahl des Kerns

### Was als Kern lizenziert wird

Die Lizenzentscheidung betrifft nicht ein Repositorium, sondern eine benannte Menge von Bestandteilen. Die Menge folgt der Technologiefestlegung aus KANON.md, Abschnitt 4, Zeile "Sprachen": atrium-core, atrium-node, atriumctl, die Atrium Console und der generische Konnektortreiber. Alles, was über den Konnektorvertrag angebunden ist, steht außerhalb dieser Menge, weil es hinter einer Prozessgrenze läuft.

| Bestandteil | Zugehörigkeit | Begründung der Zuordnung |
|---|---|---|
| atrium-core einschließlich Ausgabe-CA-Modul, xDS-Server, SCIM-Server | Kern | Enthält den Sollzustand, das Konsensverfahren und die PKI; jede Abspaltung, die hier ändert, ändert das Produkt |
| atrium-node | Kern | Materialisiert den Sollzustand; eine geänderte Fassung kann INV-02 und INV-03 unbemerkt aufheben |
| Atrium Console | Kern | Benutzt ausschließlich die öffentliche API (INV-01) und ist damit austauschbar, trägt aber die Bediengrundzusagen (INV-14 bis INV-18) |
| atriumctl | Kern | Gleicher API-Zugang wie die Konsole; eine abweichende Fassung ist ein zweiter Bedienweg |
| Generischer Konnektortreiber | Kern | Führt Manifeste aus und entscheidet über Feldeigentum (INV-13); eine geänderte Fassung kann in Fremdsystemen schreiben, was der Sollzustand nicht deckt |
| Konnektormanifeste, Konnektorprozesse Dritter | nicht Kern | Eigener Prozess, eigener Systembenutzer, gRPC über Unix-Socket, kein Netzzugang zum Kern (INV-21) |
| Schemadateien, API-Beschreibung, Clientbibliotheken, Vertragstestwerkzeug | nicht Kern | Ihr Zweck ist die Anbindung Dritter; jede Copyleft-Wirkung an dieser Stelle wirkt gegen den Zweck |
| Katalogeinträge, Richtliniendateien, Beispieldaten | nicht Kern | Daten, kein Programm |

### Die beiden Seiten

| Gesichtspunkt | Netzwerk-Copyleft (AGPL-Familie) | Permissiv (Apache-Familie) |
|---|---|---|
| Verhalten eines Anbieters, der Atrium gehostet anbietet | Muss seine Änderungen an den Nutzern des gehosteten Dienstes offenlegen | Muss nichts offenlegen; kann eine geschlossene Variante betreiben |
| Verhalten eines Systemhauses, das Atrium beim Kunden installiert | Weitergabe unverändert löst nur das Quellenangebot aus, das ohnehin öffentlich ist | Gleich, ohne Auflage |
| Verhalten eines Herstellers, der Atrium in ein Gerät einbaut | Muss den Quelltext seiner Änderungen mitliefern | Muss nichts mitliefern |
| Wirkung auf Beiträge | Änderungen kommen mit höherer Wahrscheinlichkeit zurück, weil das Offenlegen ohnehin fällig ist | Änderungen bleiben mit höherer Wahrscheinlichkeit außerhalb |
| Wirkung auf Verbreitung | Rechtsabteilungen großer Betreiber prüfen Netzwerk-Copyleft gesondert und lehnen es teils pauschal ab | Geringste Hürde |
| Wirkung auf Konnektoren Dritter | Ohne ausdrückliche Abgrenzung entsteht Unsicherheit, ob ein Konnektorprozess ein abgeleitetes Werk ist | Frage stellt sich nicht |
| Wirkung auf die Finanzierung | Schützt das Abonnementmodell gegen einen Anbieter, der dieselbe Software ohne Rückfluss vermarktet | Schützt nicht |
| Durchsetzbarkeit | Setzt einen Rechteinhaber voraus, der klagen kann und will | Kaum Durchsetzungsbedarf |

### Entscheidung

**Der Kern steht unter einer Lizenz mit Netzwerk-Copyleft (SPDX-Kennung `AGPL-3.0-or-later`). Alles außerhalb des Kerns steht unter einer permissiven Lizenz (SPDX-Kennung `Apache-2.0`).**

Begründung: Das Geschäftsmodell aus 23.3 verzichtet auf jede Funktionssperre und lebt vollständig von Wartung, geprüftem Depot, Unterstützung und Zertifizierung. Genau diese Leistungen kann ein Dritter unter einer permissiven Lizenz übernehmen, ohne etwas zurückzugeben, und zwar mit Kostenvorteil, weil er die Entwicklungslast nicht trägt. Netzwerk-Copyleft schließt den einen Fall, der dieses Modell aushebelt, nämlich den geschlossen betriebenen, gehosteten Dienst mit eigenen Änderungen. Die verworfene Alternative, eine permissive Lizenz für den Kern, wurde verworfen, weil sie die Finanzierungsschwelle aus 23.3 einseitig zulasten des Projekts verschiebt und dafür nur eine Verbreitungshürde beseitigt, die für die Zielgruppe — Betreiber eigener Server, nicht Wiederverkäufer von Software — gering ist.

Die Abgrenzung zu den Konnektoren wird nicht der Auslegung überlassen. Der Konnektorvertrag ist eine Prozessgrenze mit eigenem Systembenutzer, eigenem Netznamensraum und einer schmalen, versionierten gRPC-Schnittstelle über einen Unix-Socket. Das Projekt veröffentlicht zusätzlich zur Lizenz eine **Ausnahmeerklärung**, die festhält, dass ein Programm, das ausschließlich über diesen Vertrag mit dem Kern spricht, kein abgeleitetes Werk des Kerns ist. Ohne diese Erklärung bliebe eine Unsicherheit bestehen, die jeden Hersteller eines geschlossenen Fremdsystems davon abhält, einen eigenen Konnektor beizusteuern.

**Schwäche an dieser Stelle:** Die Ausnahmeerklärung ist eine Zusicherung des Rechteinhabers, keine gerichtlich geklärte Auslegung. Sie wirkt, solange der Rechteinhaber sie nicht widerruft, und sie wirkt nicht gegenüber Beitragenden, die ihr nicht zugestimmt haben. Deshalb muss sie Bestandteil der Beitragsbedingungen sein und nicht ein nachgereichtes Dokument.

### Beiträge: Herkunftsnachweis statt Rechteübertragung

| Verfahren | Wirkung | Kosten |
|---|---|---|
| Herkunftsnachweis (DCO) | Beitragende bestätigen, dass sie die Rechte haben; die Rechte bleiben bei ihnen | Keine Hürde; keine Unterschrift, keine Registrierung |
| Rechteübertragung (CLA) | Das Projekt kann später die Lizenz einseitig ändern und Verstöße allein verfolgen | Hürde für Gelegenheitsbeiträge; Konzernbeitragende brauchen eine Rechtsprüfung |

**Entscheidung: Herkunftsnachweis.** Begründung: Die Zahl der Beiträge ist nach dem Risikoregister in 23.8 der Engpass, nicht die Zahl der Rechtsstreitigkeiten. Die verworfene Alternative, eine Rechteübertragung, hätte einen konkreten Vorteil — die Möglichkeit, das Lizenzmodell später zu ändern —, und genau dieser Vorteil ist unerwünscht: eine Übertragung erzeugt bei jedem Beitragenden die Frage, ob das Projekt den Kern später schließen wird.

**Folge, die ausdrücklich benannt wird:** Mit dem Herkunftsnachweis ist ein späterer Lizenzwechsel praktisch ausgeschlossen, weil er die Zustimmung aller Beitragenden erfordert. Das ist eine Selbstbindung, keine Nebenwirkung. Wer die Lizenzwahl für falsch hält, muss sie vor dem ersten fremden Beitrag korrigieren; danach ist sie unumkehrbar.

### Marke und kommerzielle Nutzung durch Dritte

Die Schreibweisen "Atrium Server OS", "Atrium", "Atrium Console" sind nach KANON.md, Abschnitt 1 verbindlich und werden getrennt vom Quelltext lizenziert. Der Code darf beliebig geändert werden; der Name darf es nicht.

| Fall | Marke zulässig | Bedingung |
|---|---|---|
| Unveränderter Bau aus den Projektquellen | ja | Signatur und Transparenzprotokolleintrag des Projekts |
| Bau mit Änderungen an Kernkomponenten | nein | Der Bauprozess erzeugt aus geänderten Quellen selbsttätig eine unmarkierte Fassung mit ersetztem Namen und ersetztem Zeichen |
| Dienstleister betreibt Atrium für Kunden | ja, beschreibend | "Betrieb von Atrium Server OS", nicht "Atrium von <Firma>" |
| Gehosteter Dienst auf Basis von Atrium mit Änderungen | nein für den Produktnamen | Netzwerk-Copyleft gilt zusätzlich und unabhängig |
| Schulung, Buch, Konferenzbeitrag | ja, beschreibend | Keine Verwechslungsgefahr mit einer Projektzertifizierung |

Kommerzielle Nutzung durch Dritte ist ausdrücklich erlaubt und erwünscht: Verkauf von Hardware mit vorinstalliertem Atrium, Betrieb für Kunden, Entwicklung eigener Konnektoren, Weiterverkauf von Dienstleistungen. Untersagt sind zwei Dinge: die Marke an einer geänderten Fassung und die Behauptung einer Zertifizierung, die das Projekt nicht erteilt hat. Der zweite Punkt ist nicht kosmetisch — die Zertifizierungsstufe ist nach R-09-18 an jeder Konnektorbindung sichtbar und trägt eine Pflegezusage, die ein Dritter nicht einseitig behaupten darf.

## 23.2 Verhältnis zur Ubuntu-Basis

### Lizenzpflichten bei der Weitergabe

Das Systemabbild ist nach KANON.md, Abschnitt 4 ein unveränderliches A/B-Wurzeldateisystem, reproduzierbar gebaut aus Paketen der Ubuntu-LTS-Basis. Damit gibt das Projekt eine Zusammenstellung weiter, die Bestandteile unter Copyleft-Lizenzen enthält, insbesondere den Kernel und die Kernbibliotheken. Daraus folgt eine Quelltextpflicht, die unabhängig von der eigenen Lizenzwahl besteht.

| Pflicht | Umsetzung im Entwurf | Prüfbarkeit |
|---|---|---|
| Vollständiges, entsprechendes Quellenangebot je weitergegebenem Abbild | Je Freigabe ein Quellenbestand, adressiert über den Eintrag im Transparenzprotokoll; die Stückliste nach [Kapitel 22](22-compliance.md) (R-22-24) liefert die Liste der Bestandteile | Stichprobe: zu einem zufälligen Abbild wird der Quellenbestand vollständig aufgelöst |
| Verfügbarkeit über die Dauer der Weitergabe | Aufbewahrung über den gesamten deklarierten Unterstützungszeitraum (R-22-23) | Abruf des ältesten noch unterstützten Abbilds |
| Lizenztexte und Urhebervermerke im Abbild | Eigene Ablage im Abbild, nicht nur im Quellenbestand | Dateiprüfung im Bau |
| Weitergabe durch Dritte | Die Pflicht trifft den Weitergebenden; das Projekt stellt den Quellenbestand so bereit, dass ein Dritter ihn mitliefern kann | Handreichung für Wiederverkäufer |

Die Kosten dieser Pflicht sind nicht die Rechtsprüfung, sondern die Aufbewahrung. Rechnung mit offengelegten Annahmen: **Annahme** ein Quellenbestand je Freigabe von 20 GB, **Annahme** sechs Freigaben je Jahr und **Annahme** ein Unterstützungszeitraum von fünf Jahren ergibt 20 GB × 6 × 5 = 600 GB dauerhaft vorzuhaltenden, unveränderlichen Speicher, wachsend um 120 GB je Jahr. Deutung: Der Betrag ist tragbar und wächst linear mit der Zahl der unterstützten Linien; er ist damit ein weiterer Posten, der an derselben Stellschraube hängt wie die Aufwandsrechnung in [Kapitel 22](22-compliance.md), Abschnitt 22.8. Das ist eine Modellrechnung, keine Messung.

### Markenrichtlinie für abgeleitete Systeme

Die Basisdistribution schützt ihren Namen und ihr Zeichen mit einer eigenen Richtlinie, die von der Softwarelizenz getrennt ist. Der Wortlaut dieser Richtlinie wird hier nicht wiedergegeben und nicht aus dem Gedächtnis rekonstruiert; der Entwurf behandelt stattdessen den ungünstigsten Fall als bindend, bis eine rechtliche Prüfung vorliegt.

| Verwendung | Behandlung im Entwurf | Grund |
|---|---|---|
| Basisname im Produktnamen | ausgeschlossen | Der Produktname ist nach KANON.md, Abschnitt 1 ohnehin "Atrium Server OS" |
| Basisname oder Zeichen im Konsolenkopf, in Navigationsbereichen, in Formularen, in Fehlermeldungen | ausgeschlossen, Vorkommen = 0 | Gleiche Prüfung wie INV-16; ein fremder Produktname ist Fremdvokabular |
| Basisname im Installationsprogramm und im Startbildschirm | ausgeschlossen | Der Bediener installiert Atrium, nicht die Basis |
| Basisname in Domänennamen, Paketnamen und Systembenutzern des Projekts | ausgeschlossen | Namenskonvention ist `atrium-<rolle>` nach KANON.md, Abschnitt 3 |
| Sachliche Herkunftsangabe in der Fassungsansicht und in der Dokumentation | zulässig | Eine Tatsachenangabe über die Herkunft der Pakete ist keine Markenbenutzung im geschäftlichen Verkehr im Sinne einer Ursprungsbezeichnung |
| Behauptung, die Basis leiste Unterstützung für Atrium | ausgeschlossen | Wäre unzutreffend und beschädigt beide Seiten |

Die Änderung, die das Abbild an der Basis vornimmt, ist erheblich: unveränderliches Wurzeldateisystem mit dm-verity, A/B-Umschaltung, abgeschaltete Verwaltungsoberflächen von Kernkomponenten, erzeugte statt gepflegter Konfiguration. Ein System mit dieser Eingriffstiefe ist ein abgeleitetes System und kein Derivat mit Zusatzpaketen; die Zurückhaltung beim Namen ist deshalb nicht Vorsicht, sondern sachlich richtig.

**Was bleibt:** Der einzige belastbare Vorteil der Basis ist nach KANON.md, Abschnitt 4 die fremdgepflegte Treiber- und Sicherheitsversorgung. Dieser Vorteil hängt daran, dass das Projekt die Paketquellen der Basis unverändert benutzt und Sicherheitsaktualisierungen aus ihnen übernimmt. Eine eigene Paketpflege würde ihn aufheben; das ist in [Kapitel 6](06-basis-ubuntu.md) festgelegt und wird hier nur in seiner wirtschaftlichen Wirkung benannt: ohne diesen Vorteil steigt der Aufwandsposten "Basispflege" von null auf eine Größenordnung, die die Gesamtschätzung aus 23.5 um mehrere Personenjahre je Jahr erhöht.

## 23.3 Geschäftsmodell

### Was dauerhaft kostenlos bleibt

| Gegenstand | Frei | Begründung |
|---|---|---|
| Vollständiger Funktionsumfang, alle Navigationsbereiche | ja | Eine Funktionssperre erzeugt einen zweiten Codepfad, den niemand testet, und widerspricht INV-01 |
| Zahl der Knoten, Personen, Geräte, Mandanten, Konnektorbindungen | unbegrenzt | Eine Zählgrenze im Kern ist eine Lizenzprüfung im Kern und damit ein Angriffsziel und eine Fehlerquelle |
| Mandantenisolation bis Isolationsstufe M3 | ja | Isolation ist eine Sicherheitseigenschaft; Sicherheitseigenschaften hinter einer Zahlschranke sind unvertretbar |
| Sicherheitsaktualisierungen der laufenden Hauptlinie | ja | Folgt bereits aus der Herstellerpflicht nach [Kapitel 22](22-compliance.md), Abschnitt 22.8 |
| Freies Katalogdepot mit vollständigem Eintragsbestand | ja | Ein gekürzter freier Katalog macht die freie Fassung unbrauchbar und die Kernzusage unglaubwürdig |
| Sollzustandsexport und vollständiger Datenexport | ja | Ausstiegsfähigkeit ist Voraussetzung dafür, dass ein Betreiber sich überhaupt bindet |

### Wofür ein Abonnement sinnvoll ist

| Leistung | Inhalt | Warum entgeltfähig | Bedingung, unter der es trägt |
|---|---|---|---|
| **Verlängerte Wartung** | Sicherheitsaktualisierungen für Hauptlinien jenseits der laufenden | Der Aufwand wächst nahezu linear mit der Zahl der Linien (26,1 statt 9,9 Personentage jährlich bei fünf statt einer Linie, Rechnung in [Kapitel 22](22-compliance.md)) | Es gibt Betreiber, die eine Hauptlinie länger halten, als das Projekt sie frei pflegt; das ist bei Serversystemen der Regelfall |
| **Geprüftes Katalogdepot** | Gleicher Eintragsbestand, aber zusätzlich: Prüfung jeder Fassung gegen den Vertragstest, gestaffelte Ausrollung, benannte Rücksprungfassung, Pflegezusage je Eintrag | Die Prüfung ist Arbeit, die je Eintrag und Fassung anfällt und nicht durch Automatisierung entfällt | Der Unterschied ist Prüftiefe und Zusage, nie Bestand; sonst bricht die Zusage aus der freien Spalte |
| **Unterstützung** | Erreichbarkeit, Reaktionszeiten, Eskalationsweg, Störungsbegleitung | Nur diese Leistung skaliert mit Personen und nicht mit Kopien | Die Supportlast je Installation muss sinken, während die Zahl der Installationen steigt; sonst ist das Modell ein Dienstleistungsgeschäft mit Softwareanhang |
| **Zertifizierung von Konnektoren** | Prüfung eines fremden Manifests oder Prozesses gegen die zehn Vertragstests (R-09-17), Signatur, Eintrag mit Pflegestelle | Der Prüfaufwand ist je Konnektor und Vertragshauptversion bestimmbar | Es muss einen Zahler geben; siehe die Schwäche unten |
| **Partnermodell für Dienstleister** | Stufen "registriert", "geprüft", "ausgezeichnet"; Schulung, frühe Fassungen, Eskalationsweg, Eintrag in ein Verzeichnis | Ein Dienstleister verkauft Betrieb und braucht Verlässlichkeit gegenüber seinem Kunden | Keine Stufe darf einen Funktionsvorsprung gewähren; sonst entsteht eine verdeckte Funktionssperre |

**Schwäche der Zertifizierung:** Ein Hersteller eines kommerziellen Fremdsystems hat ein Interesse daran, dass sein Produkt in Atrium sauber angebunden ist, und kann die Zertifizierung bezahlen. Ein quelloffenes Fremdprodukt ohne Firma dahinter hat dieses Interesse ebenfalls, aber keinen Zahler. Genau diese Produkte machen nach [Kapitel 9](09-konnektoren.md) den überwiegenden Teil des Katalogs aus. Die Zertifizierung finanziert deshalb strukturell den kleineren Teil des Katalogs, und der größere Teil bleibt beim Projekt. Das ist kein Detail, sondern die Ursache der Bedingung in 23.6.

### Tragfähigkeitsbedingung als Ungleichung

```
Feste jährliche Last:
   L  =  L_konnektor + L_linien + L_kern + L_support       [Vollzeitäquivalente]

Einnahmen:
   U  =  N_inst · q · p_abo  +  N_zert · p_zert            [Geld je Jahr]

Tragfähigkeit:
   N_inst · q · p_abo  +  N_zert · p_zert   ≥   L · c_VZÄ

Nach der Zahl der Installationen aufgelöst:
   N_inst   ≥   ( L · c_VZÄ  −  N_zert · p_zert )  /  ( q · p_abo )
```

| Symbol | Bedeutung | Herkunft |
|---|---|---|
| L_konnektor | Vollzeitäquivalente für Konnektor- und Katalogpflege | Rechnung in 23.6, gestützt auf K-22 und [Kapitel 9](09-konnektoren.md) |
| L_linien | Vollzeitäquivalente für gleichzeitig unterstützte Hauptlinien | 26,1 Personentage jährlich bei fünf Linien nach [Kapitel 22](22-compliance.md) = 0,13 VZÄ |
| L_kern | Kernentwicklung und Sicherheitsnachpflege | Aufwandsrechnung 23.5, nach Meilenstein M5 als Dauerlast |
| L_support | Unterstützung, wächst mit N_inst | Nicht konstant; deshalb keine reine Fixkostenrechnung |
| q | Anteil zahlender Installationen | Annahme, nicht messbar vor Meilenstein M5 |
| p_abo, p_zert, c_VZÄ | Abonnementpreis, Zertifizierungsentgelt, Kosten je Vollzeitäquivalent | Bewusst nicht beziffert |

Deutung ohne Geldbeträge: Die Ungleichung ist in `N_inst` linear und in `q` hyperbolisch. Halbiert sich die Abonnementquote, verdoppelt sich die erforderliche Installationsbasis. Da `L_support` mit `N_inst` mitwächst, kippt die Ungleichung nicht beliebig zugunsten großer Zahlen: ab dem Punkt, an dem die Supportlast je zusätzlicher Installation mehr kostet, als deren erwarteter Beitrag `q · p_abo` einbringt, verschlechtert jede weitere Installation die Lage. Die daraus folgende Steuerungsgröße ist nicht der Umsatz, sondern die **Supportlast je Installation**, und diese wird durch INV-17 und INV-26 unmittelbar bestimmt: jede Fehlermeldung, die als einzige Lösung auf die Kommandozeile verweist, erzeugt einen Supportfall. Der Bedienanspruch ist damit kein Produktmerkmal neben dem Geschäftsmodell, sondern dessen Voraussetzung.

**Was ausdrücklich kein Geschäftsmodell ist:** Funktionssperren im Kern, Knoten- oder Nutzerzahlgrenzen, verpflichtende Telemetrie, ein Herstellerfernzugang als Supportweg (nach KANON.md, Abschnitt 4 ausgeschlossen) und ein gekürzter freier Katalog. Jede dieser vier Optionen erhöht kurzfristig die Einnahmen und verletzt eine Invariante oder eine Grundzusage.

## 23.4 Gesamtkostenmodell

### Formel

```
Betrachtungszeitraum T in Monaten.

K(T)  =  C_a · (T / T_ab)          Anschaffung, linear abgeschrieben
      +  T · h · s                 Betriebsaufwand
      +  T · n · l                 Lizenz je Nutzer und Monat
      +  T · f · d · s_A           erwartete Ausfallkosten
      +  C_m                       Migration, einmalig

Monatliche Rate:
   k  =  C_a / T_ab  +  h · s  +  n · l  +  f · d · s_A
```

| Symbol | Bedeutung | Einheit |
|---|---|---|
| C_a | Anschaffung: Hardware, Erstinstallation, Einrichtung | Geld |
| T_ab | Abschreibungsdauer | Monate |
| h | Betriebsstunden je Monat: Wartung, Änderungen, Störungsbehebung | Stunden je Monat |
| s | Stundensatz der betreibenden Person | Geld je Stunde |
| n | Zahl der Nutzer | Stück |
| l | Lizenzkosten je Nutzer und Monat | Geld je Nutzer und Monat |
| f | Ausfallhäufigkeit | Ereignisse je Monat |
| d | mittlere Ausfalldauer | Stunden je Ereignis |
| s_A | Ausfallkosten | Geld je Stunde |
| C_m | Migrationskosten, einmalig | Geld |

### Drei Vergleichsumgebungen

| Variante | C_a | h | n · l | f · d | C_m |
|---|---|---|---|---|---|
| **A** kommerzielles Serverbetriebssystem mit Abonnementdiensten | Hardware plus Betriebssystemlizenz | mittel; viel Bedienung über Oberflächen, wenig Eigenbau | wächst **linear in n** | gering bei redundanter Auslegung, sonst mittel | entfällt beim Bestand, fällt beim Wechsel an |
| **B** selbstgebauter Linux-Betrieb | Hardware | hoch und personenabhängig; Konfiguration, Aktualisierungen, Eigenautomatisierung | 0 | stark streuend, abhängig von der Sorgfalt der betreibenden Person | gering, weil oft gewachsen |
| **C** Atrium | Hardware | Zielwert niedrig (INV-14, INV-26, K-01 bis K-03) | **konstant in n**: das Abonnement ist je Installation, nicht je Nutzer; in der freien Fassung 0 | gering ab drei Stimmknoten (K-04, K-08) | fällt vollständig an |

Die entscheidende Strukturaussage steht in der dritten Spalte: Bei A wächst der Lizenzposten mit der Zahl der Nutzer, bei C nicht. Daraus folgt der gesamte Verlauf des Vergleichs.

### Schwelle gegenüber Variante A

```
Amortisationszeit T*  =  ΔC_m  /  Δk

Δk = (C_a,A − C_a,C)/T_ab  +  (h_A − h_C)·s  +  (n · l_A − a_abo)  +  (f_A·d_A − f_C·d_C)·s_A

Atrium ist ab T > T* günstiger, sofern Δk > 0.
```

Der Lizenzterm `n · l_A − a_abo` wechselt bei

```
n_krit  =  a_abo / l_A
```

das Vorzeichen. Deutung: Unterhalb von `n_krit` Nutzern ist das Atrium-Abonnement teurer als die nutzerbezogene Lizenz der kommerziellen Variante, und der Vergleich muss vollständig von den Betriebsstunden und den Ausfallkosten getragen werden. Oberhalb von `n_krit` wächst der Vorteil linear mit jeder weiteren Person, und `T*` fällt wie `1/n`. Das ist der wirtschaftliche Grund, warum eine kleine Installation die freie Fassung wählen sollte und eine große das Abonnement: in der freien Fassung ist `a_abo = 0` und der Lizenzterm für jede Installationsgröße positiv.

### Schwelle gegenüber Variante B

Gegen einen selbstgebauten Linux-Betrieb entfällt der Lizenzhebel vollständig, weil `l_A = 0`. Übrig bleibt:

```
Δk  =  (h_B − h_C)·s  +  (f_B·d_B − f_C·d_C)·s_A  −  a_abo

Bedingung für einen Vorteil überhaupt:
   (h_B − h_C)·s  +  (f_B·d_B − f_C·d_C)·s_A   >   a_abo
```

**Ehrliche Einordnung:** Das Vorzeichen von `h_B − h_C` ist im ersten Jahr nicht sicher positiv. Eine erfahrene Person, die ihre eigene Automatisierung kennt, arbeitet in einer bekannten Umgebung schneller als in einer fremden, und Atrium verlangt zusätzlich, dass jede Änderung über den Sollzustand läuft (INV-03) — das kostet Zeit, bevor es Zeit spart. Der Vorteil entsteht dort, wo `h_B` hoch ist, weil die Umgebung von einer Person gehalten wird, die andere Aufgaben hat, oder wo `f_B · d_B` hoch ist, weil Sicherung, Aktualisierung und Zertifikatserneuerung nicht zuverlässig laufen. Gegen einen sauber betriebenen, gut automatisierten Eigenbau mit kompetentem Personal ist der wirtschaftliche Fall für Atrium schwach, und der Entwurf behauptet nichts anderes.

### Welche Variable den Vergleich kippt

| Variable | Wirkungsrichtung | Schwelle | Deutung |
|---|---|---|---|
| n | groß begünstigt C gegenüber A | `n > a_abo / l_A` | Einziger Posten, der in A mit der Organisation wächst und in C nicht |
| h_C − h_B | Vorzeichen entscheidet gegen B | `(h_B − h_C)·s > a_abo` bei gleichem f·d | Der gesamte Fall gegen B hängt hieran |
| s | verstärkt jeden Betriebsstundenunterschied | linear | Hoher Stundensatz vergrößert den Abstand in beide Richtungen |
| f · d | groß begünstigt die redundante Auslegung | `(f_B·d_B − f_C·d_C)·s_A > a_abo` | Nur belastbar, wenn die Redundanz tatsächlich geprüft ist (K-24) |
| s_A | groß begünstigt C ab drei Stimmknoten | linear | Bei s_A = 0 verschwindet der Verfügbarkeitsvorteil aus der Rechnung vollständig |
| C_m | verschiebt T* linear | `T* ∝ C_m` | Die einzige Variable, die ausschließlich gegen C wirkt |
| T_ab | wirkt nur über den Unterschied in C_a | `ΔC_a / T_ab` | Kippt nichts, solange beide Varianten dieselbe Hardware benutzen |

**Nicht bezifferbare Posten**, die in keiner der drei Spalten stehen und trotzdem wirken: die Ausstiegskosten aus Atrium, die nach [Kapitel 21](21-betrieb-updates.md) mangels stabilem Austauschformat für die Absichtsebene nicht bestimmt sind, und das Risiko der Abhängigkeit von einem jungen Projekt. Ein Gesamtkostenmodell, das diese beiden Posten weglässt, ist unvollständig; sie werden hier benannt und nicht beziffert, weil jede Bezifferung erfunden wäre.

## 23.5 Aufwandsschätzung von unten nach oben

### Annahmen der Schätzung

**Annahme:** Ein Personenmonat entspricht 18 produktiven Tagen. **Annahme:** In jedem Baustein sind Entwurf, Umsetzung, Prüfungen und die Entwicklerdokumentation dieses Bausteins enthalten. **Nicht enthalten:** Hardwarezertifizierung, Übersetzung über Deutsch und Englisch hinaus, mobile Anwendungen, Vertrieb, Rechtsberatung und der Betrieb der Projektinfrastruktur. Alle Werte sind Schätzungen mit Bandbreite und keine Messungen.

| Baustein | Umfang, auf den sich die Schätzung stützt | PM min | PM max | Dominanter Treiber |
|---|---|---|---|---|
| Kontrollebene | Sollzustandsmodell, Änderungsprotokoll, Lesemodell, Reconciler, Vorgangsmodell, Wirkungsvorschau, Platzierung, öffentliche API, Schemamigration | 30 | 48 | Idempotenz und Wirkungsvorschau über alle Objekttypen (INV-07, INV-08) |
| Knotenagent | Materialisierung, erzeugte Units, Leasebehandlung, Selbstabschottung, Abweichungskorrektur | 14 | 22 | Selbstabschottung mit Uhrenabweichung (INV-06, INV-32) |
| Konsole | Acht Navigationsbereiche, Objektansichten, Aufgabenformulare, Wirkungsvorschau, Suche, Fehlerdarstellung | 26 | 42 | Zahl der Objekttypen, nicht Zahl der Seiten |
| Identität | Einbettung des Protokollkopfs, SCIM-Server und -Client, ständiger Abgleich, Gruppen und Rollen | 16 | 26 | Abgleich zweier Replikationssysteme mit sichtbarer Abweichung |
| PKI | Zweistufige CA, Mandanten-Zwischen-CA, ACME-Server, OCSP, Sperrlisten, TPM-Bindung, Schlüsselwechsel | 12 | 20 | Wechselverfahren und Sperrverteilung, nicht die Ausstellung |
| DNS und Netz | Ansteuerung des autoritativen Dienstes, DNSSEC, Split-Horizon, Resolver-Sichten, nftables-Ableitung, Overlay, Eingangsproxy über xDS | 22 | 34 | Ableitung aus dem Objektgraphen mit atomarem Tausch (INV-09, INV-10) |
| Konnektorrahmen | Vertrag, Sandkasten, generischer Treiber, Manifestschema, Feldeigentum, zehn Vertragstests | 20 | 32 | Fehlerklassen und Teilerfolge über heterogene Fremdsysteme (INV-12) |
| Katalog | Eintragsformat, Signatur, Depot, Ausrollungsstaffelung, Governance-Werkzeuge, erste 50 Einträge | 14 | 24 | Governance und Produktgrenzdeklaration je Eintrag (INV-30) |
| Speicher und Sicherung | ZFS-Anbindung, gestaffelte Replikation, Sicherungsobjekte, Wiederherstellung, automatisierte Wiederherstellungsübung | 22 | 34 | Die Übung (K-24) ist ein eigenständiges Produkt |
| Aktualisierung | A/B-Abbild, dm-verity, Secure Boot, reproduzierbarer Bau, Staffelung, selbsttätiger Rückfall | 18 | 28 | Reproduzierbarkeit und Rückfall ohne Bedienereingriff |
| Mandanten und Rechte | Vier Isolationsstufen, Stufenwechsel als Vorgang, Rollenmodell, Freigabewege, Kunden- und Reviewbereiche | 14 | 22 | Isolationsstufe M2 und M3 mit Umlagerung (K-29) |
| Audit | Hashkette, periodische Signatur, Zeitstempel, Auslagerung, Export, Prüfwerkzeug ohne Atrium | 8 | 14 | Prüfbarkeit des Exports auf einem fremden System |
| Bedienoberflächenarbeit | Interaktionsentwurf, Aufgabendefinitionen, Texte, Fehlerübersetzungstabelle, Barrierefreiheit | 18 | 30 | Fehlerübersetzung fremder Systeme ohne Fremdvokabular (INV-16, INV-17) |
| Prüfung und Absicherung | Prüfstand, Fehlerinjektion, Partitions- und Uhrensprungtests, Bauprüfungen nach K-27, externe Sicherheitsprüfung | 26 | 42 | Partitions- und Zeittests brauchen eigene Infrastruktur |
| Dokumentation | Betreiber-, Entwickler- und Konnektorautorendokumentation, Unterlagen nach [Kapitel 22](22-compliance.md) | 12 | 20 | Die regulatorischen Unterlagen sind nicht auslagerbar |
| **Summe** | | **272** | **438** | Mittelwert 355 PM ≈ 29,6 Personenjahre |

### Teamzusammensetzung

| Rolle | Anteil am Team | Begründung |
|---|---|---|
| Systemnahe Entwicklung (Kontrollebene, Knotenagent, Konnektorrahmen) | 5–7 | Größter und am stärksten verketteter Anteil |
| Netz, DNS, PKI | 2–3 | Eigenes Fachgebiet mit eigener Prüfumgebung |
| Oberfläche: Umsetzung, Interaktionsentwurf, Texte | 3–4 | Die Bedienzusagen sind messbare Anforderungen, keine Zuarbeit |
| Katalog und Konnektoren | 2–3 | Wächst nach Meilenstein M3 weiter, siehe 23.6 |
| Prüfung, Absicherung, Bau- und Freigabekette | 2–3 | Die Bauprüfungen nach K-27 sind selbst ein Produkt |
| Technische Redaktion | 1 | Ohne feste Zuständigkeit entfällt sie in jedem Projekt zuerst |
| Architektur und Produktverantwortung | 1 | Trägt die Invarianten gegen den Funktionsdruck |
| **Summe** | **16–22** | |

### Dauer bei verschiedener Teamgröße

Aufwand und Dauer hängen nicht linear zusammen. Das folgende Modell macht das rechenbar. **Annahme:** Die Wirksamkeit je Person sinkt linear mit der Teamgröße, `e(n) = 1 − k · (n − 1)` mit `k = 0,02`. **Annahme:** Der Aufwand beträgt 355 PM (Mittelwert der Bandbreite).

```
Effektive Leistung  P(n) = n · e(n) = n · (1 − 0,02 · (n − 1)) = 1,02·n − 0,02·n²
Dauer               D(n) = 355 / P(n)

Maximum:  dP/dn = 1,02 − 0,04·n = 0   ⇒   n* = 25,5
          P(n*) = 1,02·25,5 − 0,02·650,25 = 26,01 − 13,01 = 13,0 PM je Kalendermonat
          D(n*) = 355 / 13,0 = 27,3 Monate
```

| Teamgröße n | e(n) | P(n) in PM je Monat | Dauer bei 355 PM | Dauer bei 272 PM | Dauer bei 438 PM |
|---|---|---|---|---|---|
| 6 | 0,90 | 5,4 | 65,7 | 50,4 | 81,1 |
| 10 | 0,82 | 8,2 | 43,3 | 33,2 | 53,4 |
| 15 | 0,72 | 10,8 | 32,9 | 25,2 | 40,6 |
| 20 | 0,62 | 12,4 | 28,6 | 21,9 | 35,3 |
| 26 | 0,50 | 13,0 | 27,3 | 20,9 | 33,7 |
| 30 | 0,42 | 12,6 | 28,2 | 21,6 | 34,8 |
| 40 | 0,22 | 8,8 | 40,3 | 30,9 | 49,8 |

Deutung: Die Dauer lässt sich durch Personal nicht unter rund 27 Monate drücken, und jenseits von etwa 26 Personen wird sie wieder länger. Eine Verdopplung des Teams von 15 auf 30 verkürzt die Dauer von 32,9 auf 28,2 Monate, also um 14 Prozent bei 100 Prozent mehr Personalkosten.

**Grenzen des Modells, ausdrücklich benannt:** Die lineare Wirksamkeitsabnahme ist eine Annahme ohne Herleitung und wird bei `n = 51` negativ, was unsinnig ist; das Modell gilt nur im gezeigten Bereich. Unabhängig davon setzt der kritische Pfad eine eigene Untergrenze: Sollzustandsmodell, Änderungsprotokoll und Reconciler müssen vor Knotenagent, Konsole und Konnektorrahmen stehen, und diese Kette ist nicht durch Personal teilbar. Beide Grenzen zeigen in dieselbe Richtung, was die Aussage stützt, aber nicht beweist. Das ist ein Modell, keine Messung.

## 23.6 Die Konnektorfrage wirtschaftlich

[Kapitel 9](09-konnektoren.md) rechnet die jährliche Pflegelast als `E = N · Σ x_i · c_i` mit den Einheitskosten `c_T0 = 0,085`, `c_T1 = 0,475` und `c_T2 = 2,750` Personentagen je System und Jahr. Hier wird die Umkehrfrage gestellt: Welche Katalogbreite `N` trägt ein gegebenes Personalbudget?

```
N_max  =  VZÄ · 200  /  c̄        mit  c̄ = Σ x_i · c_i   und  200 produktiven Tagen je Person und Jahr
```

| Verteilung (T0 / T1 / T2) | c̄ [PT je System und Jahr] | N_max bei 1 VZÄ | bei 2 VZÄ | bei 3 VZÄ | bei 5 VZÄ |
|---|---|---|---|---|---|
| 0,25 / 0,65 / 0,10 | 0,605 | 330 | 661 | 992 | 1.653 |
| 0,25 / 0,69 / 0,06 | 0,514 | 389 | 778 | 1.167 | 1.946 |
| 0,30 / 0,66 / 0,04 | 0,449 | 445 | 891 | 1.336 | 2.227 |
| 0,15 / 0,70 / 0,15 | 0,758 | 264 | 528 | 792 | 1.319 |

Deutung: Eine **dreistellige** Katalogbreite ist bei jeder der gezeigten Verteilungen mit ein bis zwei Vollzeitäquivalenten tragbar. Eine **vierstellige** Breite verlangt mindestens drei Vollzeitäquivalente und einen T2-Anteil von höchstens sechs Prozent. Bei einem T2-Anteil von fünfzehn Prozent ist sie mit fünf Vollzeitäquivalenten gerade erreichbar und damit als Dauerlast einer Projektorganisation unrealistisch.

### Der eigentliche Treiber ist das Wachstum, nicht die Pflege

Die Zerlegung von `E` bei `N = 1.000`, Verteilung (0,25 / 0,65 / 0,10) und `N_neu / N = 0,2`:

```
E_neu   = 1.000 · (0,25·0,05 + 0,65·0,30 + 0,10·2,00)  = 1.000 · 0,4075 = 407,5 PT/a
E_rest  = 605,0 − 407,5                                                  = 197,5 PT/a
Anteil Neuanlage an der Gesamtlast: 407,5 / 605,0 = 67,4 %
```

Zwei Drittel der Last entstehen durch Zuwachs, nicht durch Pflege des Bestands. Dasselbe Modell im Beharrungszustand mit `N_neu / N = 0,05`:

```
c_T0 = 0,015 + 0,02 + 0,05·0,25 = 0,0475
c_T1 = 0,075 + 0,10 + 0,05·1,50 = 0,2500
c_T2 = 0,450 + 0,30 + 0,05·10,0 = 1,2500
c̄    = 0,25·0,0475 + 0,65·0,25 + 0,10·1,25 = 0,0119 + 0,1625 + 0,1250 = 0,2994

E = 1.000 · 0,2994 = 299,4 PT/a = 1,50 Vollzeitäquivalente
```

**Deutung:** Eine vierstellige Katalogbreite kostet im Aufbau rund 3,0 und im Beharrungszustand rund 1,5 Vollzeitäquivalente. Der Katalog ist teuer, solange er wächst, und günstig, sobald er steht. Die wirtschaftliche Bedingung lautet damit nicht "viele Konnektoren sind tragbar", sondern: **Die Zuwachsrate muss fallen, bevor die absolute Zahl steigt.** Ein Katalog, der dauerhaft zwanzig Prozent seines Bestands jährlich neu aufnimmt, ist bei keiner Breite tragbar.

### Wann es nicht trägt

| Bedingung | Wirkung | Quelle |
|---|---|---|
| T2-Anteil über 10 Prozent | `c̄` steigt überproportional; ein T2-Konnektor kostet so viel wie 5,8 T1-Konnektoren | [Kapitel 9](09-konnektoren.md), R-09-21 |
| `a_T1` liegt bei 2,5 statt 1,5 Personentagen | Der Zielwert aus K-22 ist mit keiner Verteilung erreichbar | Empfindlichkeitsrechnung in [Kapitel 9](09-konnektoren.md) |
| Aufnahme von Konnektoren ohne benannte Pflegestelle | Die Last fällt beim Projekt an, ohne dass sie geplant wurde | R-09-18, R-09-22 |
| Katalogbreite wird als Erfolgszahl veröffentlicht | Erzeugt den Anreiz, ungepflegte Einträge aufzunehmen | [Kapitel 9](09-konnektoren.md), Folgerung 5 |
| Zertifizierungsentgelte werden als Finanzierung eingeplant | Zahler existieren überwiegend nur bei kommerziellen Fremdsystemen; der Katalog besteht überwiegend aus quelloffenen | 23.3 |

Die Aufgabenstellung fordert wörtlich, dass mindestens 1.000 quelloffene Softwarelösungen eingebunden sind (F-09 in [Anhang E](A5-anforderungsmatrix.md)). Die Abweichung gehört benannt statt abgeschwächt: Eine dreistellige Zahl eingebundener Lösungen ist unter diesen Bedingungen erreichbar. Die geforderten 1.000 sind es nur im Beharrungszustand, mit einem T2-Anteil unter sechs Prozent und mit externen Pflegestellen für einen erheblichen Teil des Bestands — und auch dann liefert der Entwurf das Verfahren, mit dem 1.000 Einträge tragbar werden, nicht die 1.000 Einträge selbst.

## 23.7 Roadmap

**Bezeichnungskonflikt:** KANON.md, Abschnitt 1 belegt `M0` bis `M3` mit den vier Isolationsstufen. In diesem Kapitel trägt jede Meilensteinangabe deshalb das Wort "Meilenstein" und jede Isolationsangabe das Wort "Isolationsstufe". Das ist eine Notlösung; die dauerhafte Umbenennung der Meilensteine steht in den offenen Punkten.

| Meilenstein | Ziel | Lieferergebnis | Abbruchkriterium | Voraussetzung |
|---|---|---|---|---|
| **Meilenstein M0** — Machbarkeitsnachweis der Kernschleife | Sollzustand → Reconciler → Istzustand → Audit läuft für einen Dienst, einen Knoten, einen Konnektor | Lauffähiger Prototyp; Doppellauf ohne Änderungsereignis; `plan` ohne Nebenwirkung im Fremdsystem; datierter Istzustand | Idempotenz ist ohne fremdsystemspezifische Sonderfälle in mehr als einem Viertel der Abgleichpfade nicht erreichbar, oder die Wirkungsvorschau ist ohne schreibenden Aufruf nicht berechenbar | Objektmodell aus [Kapitel 7](07-objektmodell.md) als Schema, Vertragsentwurf aus [Kapitel 9](09-konnektoren.md) |
| **Meilenstein M1** — Einzelknoten nutzbar | Ein Ankerknoten leistet Nutzer, Geräte, Dienste, Veröffentlichungen, DNS, PKI, Mail-Anbindung und Sicherung ohne Terminal | Installierbares Abbild; acht Navigationsbereiche; die neun Standardaufgaben aus K-03; 20 Katalogeinträge; geprüfter Wiederherstellungspunkt | Eine der neun Standardaufgaben ist ohne Funktionsverlust nicht unter vier Entscheidungen zu bringen, oder K-19 wird um mehr als die Hälfte überschritten | Meilenstein M0; Interaktionsentwurf aus [Kapitel 18](18-bedienkonzept.md) |
| **Meilenstein M2** — Verbund | Mehrere Maschinen, Kopplung, Quorum, Platzierung, Replikation, Aktualisierung | Kopplungsvorgang nach K-02; 3 und 5 Stimmknoten; Zeuge; Räumen; synchron gespiegelte Speicherbereiche; A/B-Aktualisierung mit Staffelung und Rückfall | Die Fristen aus K-06 und K-07 sind mit Selbstabschottung nicht einzuhalten, oder der Partitionstest erzeugt divergierende Sollzustände | Meilenstein M1; Prüfstand mit injizierbarer Partition und Uhrensprung |
| **Meilenstein M3** — Konnektorbreite | Der Katalog trägt sich ohne Sonderfälle je Produkt | 200 Katalogeinträge, davon mindestens 90 Prozent manifestbasiert; Zertifizierungsverfahren; gemessene Werte für `a_T1` und `h_T1` an mindestens 50 Manifesten | Das gemessene `a_T1` liegt über 2,5 Personentagen; damit ist die Katalogzusage bei jeder Verteilung unfinanzierbar | Meilenstein M1; generischer Treiber aus Meilenstein M0 in Vollausbau |
| **Meilenstein M4** — Mandanten und Dienstleisterbetrieb | Ein Dienstleister betreibt mehrere Kunden auf einer Installation | Isolationsstufen M0 bis M2 mit Stufenwechsel als Vorgang; Freigabewege; Kunden- und Reviewbereiche; Auditexport je Mandant; Partnermodell Stufe 1 | Der Nachweis der Mandantentrennung scheitert, oder ein Stufenwechsel ist nur mit Neuinstallation möglich | Meilenstein M2; Rechte- und Auditmodell aus [Kapitel 19](19-mandanten-rechte-audit.md) |
| **Meilenstein M5** — allgemeine Verfügbarkeit mit Unterstützungszusage | Das Produkt ist mit zugesagter Wartung einsetzbar | Maschinenlesbar deklarierter Unterstützungszeitraum; Meldekette und Fristen nach [Kapitel 22](22-compliance.md); bestandene externe Sicherheitsprüfung; bestandene Barrierefreiheitsprüfung; monatliche automatisierte Wiederherstellungsübung | Die Frist für die Außenkante aus K-23 wird über zwei aufeinanderfolgende Quartale verfehlt, oder die externe Sicherheitsprüfung findet einen Befund in der Kernschleife, dessen Behebung die Architektur ändert | Meilensteine M2, M3, M4; Finanzierung der Dauerlast nach 23.3 |

### Voraussetzungen als Textdiagramm

```
                 Meilenstein M0  (Kernschleife)
                        |
                 Meilenstein M1  (Einzelknoten)
                    /          \
     Meilenstein M2 (Verbund)   Meilenstein M3 (Konnektorbreite)
                    |                     |
     Meilenstein M4 (Mandanten)           |
                    \                    /
                     Meilenstein M5 (allgemeine Verfügbarkeit)
```

Meilenstein M2 und Meilenstein M3 sind voneinander unabhängig und die einzige echte Parallelisierungsmöglichkeit der Roadmap.

### Aufwand und Dauer je Meilenstein

**Annahme:** Das Team wächst mit dem Projekt; die Wirksamkeitsfunktion aus 23.5 gilt unverändert.

| Meilenstein | PM min | PM max | Teamgröße | P(n) | Dauer min [Monate] | Dauer max [Monate] |
|---|---|---|---|---|---|---|
| Meilenstein M0 | 25 | 40 | 6 | 5,4 | 4,6 | 7,4 |
| Meilenstein M1 | 90 | 140 | 14 | 10,4 | 8,7 | 13,5 |
| Meilenstein M2 | 60 | 95 | 20 | 12,4 | 4,8 | 7,7 |
| Meilenstein M3 | 40 | 65 | 20 | 12,4 | 3,2 | 5,2 |
| Meilenstein M4 | 30 | 50 | 18 | 11,9 | 2,5 | 4,2 |
| Meilenstein M5 | 27 | 48 | 18 | 11,9 | 2,3 | 4,0 |
| **Summe** | **272** | **438** | | | **26,1** | **42,0** |

Deutung: Mit Personalaufwuchs liegt die Gesamtdauer bei 26 bis 42 Monaten gegenüber 22 bis 35 Monaten bei durchgehend zwanzig Personen. Der Unterschied von rund vier Monaten ist der Preis dafür, dass zu Beginn nicht zwanzig Personen sinnvoll beschäftigt werden können, weil der kritische Pfad durch die Kontrollebene läuft. Die parallele Bearbeitung von Meilenstein M2 und Meilenstein M3 verkürzt die Summe um bis zu 5,2 Monate, verlangt aber zwei getrennte Teilteams und ist in der Tabelle nicht eingerechnet.

## 23.8 Risikoregister

| Nr. | Risiko | Ursache | Wirkung | Wahrscheinlichkeit | Gegenmaßnahme | Frühindikator |
|---|---|---|---|---|---|---|
| RS-01 | Konnektorpflege übersteigt das Budget | `a_T1` und `h_T1` sind ungemessen; Fremd-APIs brechen häufiger als angenommen | Katalogzusage wird zurückgenommen; Vertrauensverlust | hoch | T2-Anteil als bewirtschaftetes Kontingent (R-09-21); Messung an den ersten 50 Manifesten vor Meilenstein M3 | Mittelwert der Bruchbehebungszeit über die letzten 20 Fälle steigt über 0,4 Personentage |
| RS-02 | Bedienbarkeitsanspruch bricht am Funktionsumfang | Jede neue Funktion drängt auf ein weiteres Pflichtfeld | INV-14 wird aufgeweicht; das Alleinstellungsmerkmal entfällt | hoch | Bauprüfung gegen maschinenlesbare Aufgabendefinitionen (K-27); Architekturrolle mit Vetorecht | Zahl der Ausnahmeanträge gegen die Drei-Entscheidungs-Regel je Quartal > 0 |
| RS-03 | Abhängigkeit von einer Entscheidung der Basisdistribution | Änderung der Paketpolitik, der Markenrichtlinie oder der Freigabekadenz | Basiswechsel mit mehreren Personenjahren Aufwand | mittel | Reproduzierbarer Bau ohne distributionsspezifische Werkzeuge; Abhängigkeitsbudget nach [Kapitel 20](20-sicherheit.md) | Zahl der Bauschritte, die sich nicht auf eine zweite Basis abbilden lassen, steigt |
| RS-04 | Kompromittierung der Ausgabe-CA | Angriff auf einen Verwaltungsknoten; fehlerhafte TPM-Bindung | Vertrauensanker aller Installationen dieses Kunden fällt; Ausstellung für fremde Namen | gering, Schaden sehr hoch | Wurzel-CA offline (INV-22); Zwischen-CA je Mandant ab Isolationsstufe M0; jede Ausstellung im replizierten Protokoll | Zahl der Ausstellungen ohne zugehörigen Vorgang > 0 |
| RS-05 | Finanzierung erreicht die Schwelle nicht | Abonnementquote `q` unter der Annahme; Supportlast je Installation zu hoch | Dauerlast aus 23.3 ist ungedeckt; Unterstützungszeitraum muss verkürzt werden | hoch | Supportlast je Installation als gesteuerte Größe; Unterstützungszeitraum erst mit nachgewiesener Finanzierung verlängern | Supportfälle je 100 Installationen und Monat steigen über zwei aufeinanderfolgende Quartale |
| RS-06 | Beitragsgemeinschaft bildet sich nicht | Hohe Einstiegshürde in eine Rust-Kontrollebene mit Konsensverfahren; Netzwerk-Copyleft schreckt Firmen ab | Projekt bleibt an einer Organisation hängen; Wegfall dieser Organisation beendet es | hoch | Konnektoren und Manifeste permissiv lizenziert und ohne Kernwissen beitragbar; Herkunftsnachweis statt Rechteübertragung | Anteil übernommener Beiträge von außerhalb des Kernteams bleibt unter zehn Prozent |
| RS-07 | Außenkante in nicht speichersicheren Sprachen | Eingangsproxy und DNS-Dienste sind fremde Bestandteile an unauthentisiert erreichbaren Grenzen | Ausnutzbare Schwachstelle mit direkter Netzerreichbarkeit | mittel | Sandkastenprofile, Systemaufruffilter, Frist für die Außenkante nach K-23 | Anteil der Freigaben, die die 72-Stunden-Frist einhalten, sinkt unter 90 Prozent |
| RS-08 | Ein großer Fremdanbieter bricht seine Schnittstelle | Änderung der Authentisierung oder Abschaltung eines Endpunkts bei einem Postfachanbieter | Mailversorgung vieler Installationen gleichzeitig gestört | mittel | Fehlerklasse `schema` mit sichtbarer Abweichung; Vertragsversionierung; Bindung im Zustand `ausgesetzt` statt stillem Fehlverhalten | Zahl der Bindungen im Zustand `abweichend` je Konnektortyp steigt sprunghaft |
| RS-09 | Herstellerrolle bindet mehr Aufwand als geplant | Regulatorische Pflichten treffen das Projekt vollständig, sobald entgeltliche Leistungen angeboten werden | Entwicklungsleistung verschiebt sich in Nachweisarbeit | mittel | Prozess von Anfang an auf die Herstellerrolle ausgelegt ([Kapitel 22](22-compliance.md)); Stückliste und Transparenzprotokoll im Bau | Anteil der Personentage für Nachweis- und Meldearbeit an der Gesamtleistung steigt über zehn Prozent |
| RS-10 | Markenkonflikt mit der Basisdistribution | Unklare Abgrenzung zwischen Herkunftsangabe und Markenbenutzung | Umbenennungs- und Rückrufaufwand nach Auslieferung | gering | Basisname in Produktname und Oberfläche ausgeschlossen; Rechtsprüfung vor Meilenstein M1 | Prüfung ist zum Zeitpunkt des Meilensteins M1 nicht abgeschlossen |
| RS-11 | Schlüsselpersonenabhängigkeit im Kern | Konsensverfahren, Reconciler und Wirkungsvorschau sind von wenigen Personen entworfen | Ausfall einer Person verzögert den kritischen Pfad um Monate | hoch | Entwurfsdokumentation als Lieferergebnis je Baustein; mindestens zwei Personen je Kernbereich ab Meilenstein M1 | Zahl der Kernbereiche mit nur einer sachkundigen Person > 0 |
| RS-12 | Abspaltung oder Ablehnung wegen der Lizenzwahl | Netzwerk-Copyleft wird von Integratoren pauschal abgelehnt | Verbreitung bleibt hinter der Finanzierungsschwelle zurück | mittel | Ausnahmeerklärung für die Prozessgrenze; permissive Lizenz für alles außerhalb des Kerns | Zahl der Anfragen nach einer Ausnahmelizenz je Quartal steigt |
| RS-13 | Datenverlust in einem Fremdsystem nach Wiederherstellung | Feldeigentum ist in einem Manifest unvollständig oder falsch deklariert | Überschreiben fremdbesessener Felder in Produktivsystemen | mittel, Schaden hoch | Vollständige Eigentumsangabe als Importbedingung (INV-13, R-09-07); Erstabgleich schreibt nicht (R-09-29) | Zahl der Manifeste mit nachträglich korrigierter Eigentumsangabe > 0 |
| RS-14 | Skalengrenze trifft einen frühen Großkunden | Platzierung und Overlay sind auf höchstens 32 Knoten ausgelegt | Zusage "alles, was ein großer Server kann" ist an der Grenze nicht einlösbar | mittel | Grenze in der Konsole und in der Dokumentation ausdrücklich nennen statt sie zu verschweigen | Zahl der Installationen über 24 Knoten > 0 |
| RS-15 | Unterstützungszeitraum nicht einhaltbar | Fünf gleichzeitige Linien binden 26,1 Personentage jährlich für Bewertung, Behebung und Rückportierung, davon 16,2 Personentage allein für die Rückportierung | Zusage muss gebrochen werden; das ist der teuerste aller Vertrauensschäden | mittel | Zeitraum erst mit nachgewiesener Finanzierung festlegen; Zahl der Linien in der Konsole sichtbar | Rückportierungsrückstand über zwei Freigabezyklen |
| RS-16 | Barrierefreiheitszusage blockiert Freigaben | Die Prüfung bricht den Bau bei jedem Verstoß (INV-31, K-27) | Freigaben verzögern sich; Druck auf Ausnahmen entsteht | mittel | Barrierefreiheit als Bestandteil jeder Oberflächenarbeit statt als Abnahmeschritt | Zahl der Bauabbrüche aus der Barrierefreiheitsprüfung je Monat steigt |

## 23.9 Erfolgskennzahlen mit Messverfahren

Die Telemetrie ist nach [Kapitel 21](21-betrieb-updates.md) und [Kapitel 22](22-compliance.md) einwilligungspflichtig und voreingestellt abgeschaltet. Jede Kennzahl nennt deshalb ausdrücklich, ob sie ohne Rückmeldung aus Installationen messbar ist.

| Nr. | Kennzahl | Messverfahren | Datenquelle | Flächig messbar |
|---|---|---|---|---|
| E-01 | Entscheidungen je Standardaufgabe | Abgleich der Formulardefinitionen gegen die maschinenlesbaren Aufgabendefinitionen im Bau (K-03, K-27) | Bauprozess | ja |
| E-02 | Zahl der Fehlermeldungen, deren einziger Lösungsweg ein Konsolenbefehl ist | Musterprüfung aller Meldungstexte auf Befehls- und Dateipfadangaben (INV-17) | Bauprozess | ja |
| E-03 | Erstklick-Trefferquote bei 20 Standardaufgaben | Unbegleiteter Test mit Erstnutzern; Verfahren ist nach K-26 noch festzulegen | Nutzerstudie | nein, nur Stichprobe |
| E-04 | Zeit vom Einschalten bis zum ersten nutzbaren Dienst | Messung auf einer definierten Prüfstandkonfiguration je Freigabe (K-01) | Prüfstand | ja, auf Prüfstandhardware |
| E-05 | Anteil der Konnektorbindungen im Zustand `aktiv` | Auszählung in der Installation; flächig nur mit Einwilligung, sonst über Partner- und Supportinstallationen | Installation | nein |
| E-06 | Katalogbreite | wird bewusst **nicht** als Erfolgskennzahl veröffentlicht | entfällt | entfällt |
| E-07 | Anteil der Freigaben, die die Frist für die Außenkante einhalten | Vergleich von Veröffentlichungszeitpunkt der Meldung und Bereitstellungszeitpunkt der Freigabe (K-23) | Freigabekette | ja |
| E-08 | Anteil der Installationen mit bestandener Wiederherstellungsübung | Prüfstatus des jüngsten Wiederherstellungspunkts (K-24); ohne Einwilligung nur über Support- und Partnerinstallationen | Installation | nein |
| E-09 | Supportfälle je 100 Installationen und Monat, aufgeschlüsselt nach "Oberfläche reichte nicht aus" | Auszählung der Supportvorgänge mit Pflichtfeld für die Ursachenklasse | Supportsystem | ja, für zahlende Installationen |
| E-10 | Anteil übernommener Beiträge von außerhalb des Kernteams | Auszählung im Quelltextverwaltungssystem | Projektinfrastruktur | ja |
| E-11 | Abonnementquote `q` | Zahl zahlender Installationen gegen geschätzte Gesamtzahl; die Gesamtzahl ist ohne Telemetrie nur über Abrufzahlen des Depots abschätzbar und damit unsicher | Abrechnung und Depot | eingeschränkt |
| E-12 | Anteil zertifizierter Konnektoren mit benannter Pflegestelle | Auszählung im Katalog (R-09-18) | Katalog | ja |
| E-13 | Anteil manifestbasierter Konnektoren | Auszählung im Katalog gegen K-22 | Katalog | ja |
| E-14 | Verstöße gegen die Positivliste der Oberflächenbegriffe | Prüfung aller Oberflächentexte einschließlich Fehlerübersetzungstabelle (INV-16) | Bauprozess | ja |

Die Aufstellung zeigt eine unbequeme Verteilung: Alles, was den Bauprozess betrifft, ist flächig und exakt messbar. Alles, was die tatsächliche Nutzung betrifft — E-03, E-05, E-08, E-11 —, ist es nicht, weil die Datenquelle einwilligungspflichtig ist. Die zentrale Zusage des Produkts, dass Bedienung ohne Terminal genügt, ist damit im Feld nicht flächig belegbar, sondern nur an Support- und Partnerinstallationen. Diese Einschränkung ist eine Folge der Datensparsamkeitsentscheidung und wird nicht durch eine Umdeutung der Kennzahlen beseitigt.

## 23.10 Machbarkeitsbewertung

### Realistisch

Die Kernschleife aus Sollzustand, Reconciler, Istzustand und Audit ist Stand der Technik und in 25 bis 40 Personenmonaten nachweisbar. Ein Einzelknoten, der Personen, Geräte, Dienste, Veröffentlichungen, DNS, PKI und Sicherung ohne Terminal bedienbar macht, ist mit der gewählten Technologiemenge erreichbar; die neun Standardaufgaben aus K-03 sind nach dem Entwurfsstand mit höchstens drei Entscheidungen darstellbar. Der Verbund mit 3 oder 5 Stimmknoten, Selbstabschottung per Lease und A/B-Aktualisierung ist ein gelöstes Problem, dessen Schwierigkeit in der Prüfung und nicht im Entwurf liegt. Die Isolationsstufen M0 und M1 sind mit dem beschriebenen Modell erreichbar. Eine dreistellige Zahl manifestbasierter Konnektoren ist mit ein bis zwei Vollzeitäquivalenten tragbar.

### Ambitioniert

Die Frist von 72 Stunden für Sicherheitsaktualisierungen der Außenkante steht bereits in K-23 als ambitioniert gekennzeichnet und kollidiert mit der gestaffelten Ausrollung. Der Zielwert von 20 Minuten vom Einschalten bis zum ersten Dienst gilt für definierte Hardware, nicht für beliebige. Die monatliche automatisierte Wiederherstellungsübung auf Ersatzhardware ist nach K-24 ein eigenständig zu entwickelndes Produkt und wird in Projekten regelmäßig zuerst gestrichen. Die Isolationsstufe M3 mit exklusiven Knoten, eigenen Speicherpools und eigenen Sicherungszielen verdoppelt den Prüfaufwand der Mandantenfunktionen. Ein Unterstützungszeitraum von fünf Jahren über fünf Linien ist ohne gesicherte Finanzierung eine Zusage auf Vorrat. Die von der Aufgabenstellung geforderten mindestens 1.000 eingebundenen Lösungen mit Pflegezusage für jeden Eintrag sind nur im Beharrungszustand und nur mit externen Pflegestellen erreichbar; der Entwurf spezifiziert dafür das Verfahren und nicht den Bestand.

### Unter den gegebenen Annahmen nicht erreichbar

| Anspruch | Warum nicht | Was stattdessen gilt |
|---|---|---|
| "Alles, was ein kommerzielles Serverbetriebssystem kann" | Die Protokollkopf-Entscheidung liefert OIDC, LDAP, SCIM und EAP-TLS, aber keinen Domänenbeitritt von Arbeitsplatzrechnern mit dem zugehörigen Verwaltungsmodell. Das ist keine Aufwandsfrage, sondern eine Festlegung der Architektur | Atrium verwaltet Identität, Zertifikat und Netzzugang eines Geräts; die Arbeitsplatzverwaltung bleibt außerhalb |
| "Eine Serverarchitektur, die ein Kind bedienen kann" | Die Drei-Entscheidungs-Regel entfernt die Syntax, nicht die Sachfrage. Ob eine Domäne extern delegiert wird, welche Datensicherheitsstufe angemessen ist und ob eine Wiederherstellung den Bestand oder den Wiederherstellungspunkt gewinnen lässt, sind fachliche Entscheidungen mit Folgen | Die Zahl der Entscheidungen ist begrenzt und jede Entscheidung ist erklärt; die Verantwortung für die Entscheidung bleibt beim Bediener |
| "Firewall, TLS, Reverse Proxy und DNS verwalten sich selbst" — ohne Einschränkung | Die Selbstverwaltung endet an der Objektgrenze. Registrar, externe Delegierung, vorgelagerte Anbieterfirewall und die Namensauflösung auf unverwalteten Geräten liegen außerhalb | Innerhalb der Objektgrenze gilt die Zusage vollständig; außerhalb benennt die Konsole die Grenze ausdrücklich |
| Betrieb über 32 Knoten hinaus | Platzierung, Overlay-Vollvermaschung und Verfügbarkeitsrechnung sind auf diese Grenze ausgelegt; sie ist gesetzt, nicht gemessen | Die Grenze wird genannt, nicht überschritten; darüber hinaus ist eine getrennte Installation der vorgesehene Weg |
| Wirtschaftlicher Vorteil gegenüber einem gut betriebenen Eigenbau | Gegen Variante B fehlt der Lizenzhebel vollständig; der Vorteil hängt allein an `h_B − h_C` und an den Ausfallkosten, und beide Größen sind bei kompetentem Personal klein | Der Fall trägt gegen Variante A und gegen einen unzureichend betreuten Eigenbau, nicht gegen jeden Eigenbau |
| Flächiger Nachweis der Bedienzusage im Feld | Die Datenquelle ist einwilligungspflichtig und voreingestellt abgeschaltet | Nachweis über Bauprüfungen, Prüfstand und Stichproben; die Lücke bleibt benannt |

## Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| R-23-01 | Der Kern steht unter einer Lizenz mit Netzwerk-Copyleft; jedes Quelltextverzeichnis des Kerns trägt eine maschinenlesbare Lizenzangabe, und ein Bau mit fehlender oder abweichender Angabe bricht ab | Entscheidung 23.1 |
| R-23-02 | Konnektorprozesse, Manifeste, Schemadateien, die API-Beschreibung, Clientbibliotheken und das Vertragstestwerkzeug stehen unter einer permissiven Lizenz; die Lizenzgrenze ist in einer veröffentlichten Ausnahmeerklärung an die Prozessgrenze des Konnektorvertrags gebunden | KANON 4.5, INV-21 |
| R-23-03 | Jeder Beitrag trägt einen Herkunftsnachweis; die Zahl übernommener Beiträge ohne Nachweis ist 0 | Entscheidung 23.1 |
| R-23-04 | Der Bauprozess erzeugt aus geänderten Kernquellen selbsttätig eine unmarkierte Fassung mit ersetztem Produktnamen und ersetztem Zeichen | Markenrichtlinie 23.1 |
| R-23-05 | Jede Freigabe stellt das vollständige Quellenangebot für alle Copyleft-Bestandteile des Abbilds bereit, adressiert über den Transparenzprotokolleintrag, und hält es über den gesamten deklarierten Unterstützungszeitraum verfügbar | R-22-23, R-22-24 |
| R-23-06 | Der Name und das Zeichen der Basisdistribution erscheinen in keinem Navigationsbereich, keinem Formular, keiner Fehlermeldung, keinem Paketnamen und keinem Systembenutzer; zulässig ist ausschließlich die Herkunftsangabe in der Fassungsansicht; Vorkommen außerhalb dieser Ansicht = 0 | INV-16 |
| R-23-07 | Kein Funktionsumfang ist an ein Abonnement gebunden; die Zahl der Knoten, Personen, Geräte, Mandanten und Konnektorbindungen ist unbegrenzt, und der Kern enthält keine Lizenzprüfung | INV-01, Entscheidung 23.3 |
| R-23-08 | Das entgeltliche Katalogdepot unterscheidet sich vom freien Depot ausschließlich in Prüftiefe, Ausrollungsstaffelung und Pflegezusage; die Differenzmenge der Katalogeinträge zwischen beiden Depots ist 0 | INV-30, Entscheidung 23.3 |
| R-23-09 | Keine Partnerstufe gewährt Zugang zu einem API-Endpunkt oder einer Konsolenfunktion, die außerhalb des Programms nicht verfügbar ist; die Menge partnerexklusiver Funktionen ist leer | INV-01 |
| R-23-10 | Das Gesamtkostenmodell wird als Rechenblatt mit offengelegten Variablen und ohne vorbelegte Geldbeträge veröffentlicht; jede Kostenaussage des Projekts nennt die Variablen und die Schwelle als Ungleichung | Stilregel KANON 10.3 |
| R-23-11 | Jeder Baustein der Aufwandsschätzung trägt eine Bandbreite und einen benannten dominanten Treiber; nach jedem Meilenstein wird der Istaufwand je Baustein gegen die Bandbreite gestellt und veröffentlicht | Modellcharakter 23.5 |
| R-23-12 | Jeder Meilenstein trägt ein vor seiner Eröffnung veröffentlichtes Abbruchkriterium mit Messverfahren; ein Meilenstein ohne Abbruchkriterium wird nicht eröffnet | Roadmap 23.7 |
| R-23-13 | Meilenstein M1 gilt als erreicht, wenn die neun Standardaufgaben aus K-03 vollständig über die Konsole ohne Terminal mit höchstens drei Entscheidungen durchlaufen werden | INV-14, INV-26, K-03 |
| R-23-14 | Meilenstein M2 gilt als erreicht, wenn Partitions- und Uhrensprungtests keine divergierenden Sollzustände erzeugen und die Fristen aus K-06 und K-07 eingehalten werden | INV-04, INV-06, K-06, K-07 |
| R-23-15 | Meilenstein M3 gilt als erreicht, wenn mindestens 90 Prozent der Katalogeinträge manifestbasiert versorgt werden und `a_T1` sowie `h_T1` an mindestens 50 Manifesten gemessen sind | K-22, R-09-21 |
| R-23-16 | Meilenstein M5 setzt einen maschinenlesbar deklarierten Unterstützungszeitraum, eine bestandene externe Sicherheitsprüfung und eine bestandene Barrierefreiheitsprüfung voraus | R-22-23, INV-31 |
| R-23-17 | Der Unterstützungszeitraum je Hauptversion wird vor Meilenstein M5 festgelegt und aus der Rechnung in 23.3 hergeleitet; eine Verlängerung ohne nachgewiesene Finanzierung der zusätzlichen Linien findet nicht statt | Kapitel 22, Abschnitt 22.8 |
| R-23-18 | Das Risikoregister wird quartalsweise fortgeschrieben; jedes Risiko trägt einen Frühindikator mit benannter Datenquelle und Schwelle; die Zahl der Risiken ohne messbaren Frühindikator ist 0 | 23.8 |
| R-23-19 | Jede Erfolgskennzahl nennt ihre Datenquelle; Kennzahlen ohne einwilligungsfreie Quelle sind als "nicht flächig messbar" gekennzeichnet und werden nicht als Gesamtaussage veröffentlicht | Kapitel 22, Datensparsamkeit |
| R-23-20 | Die Katalogbreite wird nicht als Erfolgskennzahl veröffentlicht; veröffentlicht wird der Anteil der Konnektorbindungen im Zustand `aktiv` | Kapitel 9, Folgerung 5 |
| R-23-21 | Der T2-Anteil des Katalogs wird als Kontingent geführt und veröffentlicht; jede Neuaufnahme verbraucht sichtbar davon | R-09-21 |
| R-23-22 | Die Skalengrenze von 32 Knoten ist in Konsole und Dokumentation ausdrücklich genannt; eine Kopplung, die sie überschreitet, wird abgelehnt und nennt den Grund | K-21, INV-18 |
| R-23-23 | Die Machbarkeitsbewertung aus 23.10 wird zu jedem Meilenstein fortgeschrieben; Punkte, die von "ambitioniert" nach "nicht erreichbar" wandern, werden benannt und nicht entfernt | Ehrlichkeitsregel KANON 10.8 |
| R-23-24 | Die rechtliche Prüfung der Markenrichtlinie der Basisdistribution liegt vor der Freigabe des Meilensteins M1 vor; bis dahin gilt der ungünstigste Fall als bindend | RS-10 |

## Akzeptanzkriterien

| Kriterium | Anforderung | Nachweisverfahren |
|---|---|---|
| Ein Bau mit einer Quelltextdatei des Kerns ohne maschinenlesbare Lizenzangabe bricht ab | R-23-01 | Negativtest im Bau mit einer Datei ohne Angabe |
| Die Ausnahmeerklärung zur Prozessgrenze ist veröffentlicht und Bestandteil der Beitragsbedingungen | R-23-02 | Dokumentenprüfung vor dem ersten fremden Beitrag |
| Eine Zusammenführung ohne Herkunftsnachweis wird abgelehnt; Zahl übernommener Beiträge ohne Nachweis = 0 | R-23-03 | Auszählung im Quelltextverwaltungssystem je Quartal |
| Ein Bau aus geänderten Kernquellen erzeugt eine Fassung, in der Produktname und Zeichen ersetzt sind | R-23-04 | Bau mit künstlicher Änderung einer Kerndatei, anschließende Prüfung der erzeugten Oberflächentexte |
| Zu einem zufällig gewählten Abbild der ältesten noch unterstützten Linie wird der vollständige Quellenbestand aufgelöst | R-23-05 | Stichprobe je Quartal gegen die Stückliste |
| Eine Mustersuche über alle Konsolentexte findet den Basisnamen ausschließlich in der Fassungsansicht | R-23-06 | Musterprüfung im Bau |
| Der Kern enthält keinen Codepfad, der eine Abonnementkennung, eine Knotenzahl oder eine Nutzerzahl prüft | R-23-07 | Quelltextprüfung im Bau gegen eine Musterliste |
| Der Eintragsbestand beider Katalogdepots ist identisch; Differenzmenge = 0 | R-23-08 | Täglicher Abgleich beider Depotverzeichnisse |
| Die Menge partnerexklusiver API-Endpunkte und Konsolenfunktionen ist leer | R-23-09 | Abgleich der Rechtematrix gegen die API-Beschreibung |
| Jede veröffentlichte Kostenaussage enthält Variablen und Schwelle; Zahl der Aussagen ohne Annahme = 0 | R-23-10 | Prüfung aller Projektveröffentlichungen vor Freigabe |
| Nach jedem Meilenstein liegt je Baustein ein Soll-Ist-Vergleich des Aufwands vor | R-23-11 | Dokumentenprüfung am Meilensteinende |
| Kein Meilenstein wird ohne veröffentlichtes Abbruchkriterium eröffnet | R-23-12 | Prüfung der Meilensteineröffnung |
| Die neun Standardaufgaben aus K-03 werden ohne Terminal mit höchstens drei Entscheidungen durchlaufen | R-23-13 | Automatisierter Konsolendurchlauf des Aufgabenkatalogs |
| Der Partitionstest erzeugt null divergierende Sollzustände; die Fristen aus K-06 und K-07 werden eingehalten | R-23-14 | Partitions- und Uhrensprungtest im Prüfstand |
| Der Anteil manifestbasierter Katalogeinträge ist ≥ 90 Prozent; `a_T1` und `h_T1` sind an ≥ 50 Manifesten gemessen | R-23-15 | Auszählung im Katalog und Auswertung der Erfassungszeiten |
| Meilenstein M5 wird nicht freigegeben, solange eine der drei Voraussetzungen fehlt | R-23-16 | Freigabeprüfung mit Vorlagepflicht der drei Nachweise |
| Der Unterstützungszeitraum ist vor Meilenstein M5 festgelegt und hergeleitet | R-23-17 | Dokumentenprüfung |
| Jedes Risiko des Registers trägt einen Frühindikator mit Datenquelle und Schwelle; Zahl ohne = 0 | R-23-18 | Quartalsweise Registerprüfung |
| Jede veröffentlichte Kennzahl nennt ihre Datenquelle und ihre Messbarkeitseinschränkung | R-23-19 | Prüfung der Kennzahlenveröffentlichung |
| Die Katalogbreite erscheint in keiner Erfolgsveröffentlichung | R-23-20 | Musterprüfung der Veröffentlichungen |
| Der T2-Anteil ist im Katalog sichtbar und sinkt bei jeder Neuaufnahme sichtbar als Kontingent | R-23-21 | Darstellungsprüfung des Katalogs |
| Ein Kopplungsversuch, der 32 Knoten überschreiten würde, wird mit genanntem Grund abgelehnt | R-23-22 | Negativtest im Prüfstand |
| Die Machbarkeitsbewertung liegt zu jedem Meilenstein in fortgeschriebener Fassung vor | R-23-23 | Dokumentenprüfung am Meilensteinende |
| Die Markenprüfung liegt vor der Freigabe des Meilensteins M1 vor | R-23-24 | Freigabeprüfung |

## Offene Punkte

1. **Benennung der Meilensteine.** `M0` bis `M3` sind nach KANON.md, Abschnitt 1 die Isolationsstufen. Die Roadmap benutzt dieselben Zeichen für Meilensteine und löst die Mehrdeutigkeit derzeit nur durch ein vorangestelltes Wort. Das hält in Fließtext, nicht in Tabellenköpfen, Fehlermeldungen und Projektplänen. Zu entscheiden ist, ob die Meilensteine dauerhaft umbenannt werden, und wenn ja, ob in eine Reihe ohne Kollisionspotenzial; die Änderung betrifft alle Kapitel, die auf die Roadmap verweisen, und muss vor dem ersten externen Projektplan getroffen werden.

2. **Finanzierung des quelloffenen Katalogteils.** Die Zertifizierung finanziert Konnektoren zu kommerziellen Fremdsystemen, weil dort ein Hersteller ein Interesse und ein Budget hat. Der überwiegende Teil des Katalogs besteht aus quelloffenen Produkten ohne Zahler, und genau dieser Teil trägt die Produktzusage. Zu entscheiden ist, ob dieser Teil aus dem Abonnement quersubventioniert wird, ob eine Beitragspflicht für Partner eingeführt wird, die den Katalog geschäftlich nutzen, oder ob die Pflegezusage für einen benannten Teil des Katalogs entfällt und die Einträge sichtbar als "ohne zugesicherte Pflege" geführt werden. Die dritte Möglichkeit ist die ehrlichste und die wirtschaftlich unbequemste.

3. **Höhe und Struktur des Abonnements gegenüber kleinen Installationen.** Die Rechnung in 23.4 zeigt eine Schwelle `n_krit = a_abo / l_A`, unterhalb derer ein installationsbezogenes Abonnement teurer ist als eine nutzerbezogene Lizenz der Vergleichsvariante. Eine Installation mit fünfzehn Personen liegt mit hoher Wahrscheinlichkeit unterhalb dieser Schwelle. Zu entscheiden ist, ob es eine nach Installationsgröße gestaffelte Struktur gibt — was dem Grundsatz "keine Zählgrenzen" nahe kommt, ohne ihn technisch zu verletzen —, ob kleine Installationen dauerhaft auf die freie Fassung verwiesen werden, oder ob die Supportlast kleiner Installationen als nicht finanzierbar hingenommen wird.

4. **Unumkehrbarkeit der Lizenzentscheidung.** Mit dem Herkunftsnachweis statt der Rechteübertragung ist ein späterer Lizenzwechsel praktisch ausgeschlossen. Sollte sich die Annahme aus RS-12 bestätigen, dass Netzwerk-Copyleft Integratoren pauschal abschreckt, gibt es keinen Rückweg. Zu entscheiden ist, ob vor dem ersten fremden Beitrag ein befristetes Fenster geöffnet wird, in dem die Entscheidung anhand der Rückmeldungen aus Meilenstein M0 und Meilenstein M1 noch einmal geprüft wird, und wer diese Prüfung mit welchem Kriterium durchführt.

5. **Zahl gleichzeitig unterstützter Linien.** Die Rechnung in [Kapitel 22](22-compliance.md) zeigt einen nahezu linearen Zusammenhang zwischen der Zahl der Linien und der Rückportierungslast. Ein kurzer Unterstützungszeitraum ist billig und für Betreiber unbrauchbar, ein langer bindet dauerhaft Personal und Speicher für Quellenbestände. Zu entscheiden sind die Zahl der Linien, die Kadenz der Hauptversionen und die Frage, ob eine verlängerte Wartung nur für Linien angeboten wird, für die eine Mindestzahl zahlender Installationen besteht — was bedeutet, dass ein Betreiber im Voraus nicht wissen kann, wie lange seine Linie unterstützt wird.

6. **Messbarkeit der zentralen Produktzusage.** E-03, E-05, E-08 und E-11 sind ohne einwilligungspflichtige Rückmeldung nicht flächig messbar. Damit ist die Aussage "die Oberfläche genügt im Normalbetrieb" nur an Support- und Partnerinstallationen belegbar, also an einer Stichprobe, die systematisch von Installationen mit Problemen und Installationen mit Budget gebildet wird. Zu entscheiden ist, ob eine ausdrücklich beschränkte, inhaltlich benannte und jederzeit abschaltbare Rückmeldung angeboten wird, welche Gegenleistung sie rechtfertigt, und wie verhindert wird, dass aus einem Angebot faktisch eine Voraussetzung für guten Support wird.

7. **Abgrenzung des Partnerprogramms gegen die Gleichbehandlungszusage.** R-23-09 verbietet einen Funktionsvorsprung, erlaubt aber frühe Fassungen und einen Eskalationsweg. Der Zugang zu einer Fassung vor der allgemeinen Freigabe ist in der Praxis ein Vorsprung, und der Eskalationsweg ist eine Supportleistung, deren Fehlen sich für Nichtpartner wie eine schlechtere Produktqualität anfühlt. Zu entscheiden ist, wo die Linie zwischen zulässigem Dienstleistungsvorteil und unzulässigem Funktionsvorsprung verläuft und wie sie überprüfbar formuliert wird, damit R-23-09 nicht zu einer nicht prüfbaren Absichtserklärung wird.

8. **Belastbarkeit der Aufwandsbandbreiten.** Die Werte in 23.5 stützen sich auf den Umfang der Bausteine, nicht auf vergleichbare Vorhaben, und sie streuen mit einem Faktor von 1,6 zwischen unterer und oberer Grenze. Eine Streuung dieser Größe entscheidet über die Finanzierbarkeit des gesamten Vorhabens. Zu entscheiden ist, ob nach Meilenstein M0 eine Neuschätzung mit den dann gemessenen Werten für die Kontrollebene verbindlich vorgenommen wird, welcher Abweichungsbetrag eine Neuplanung der Roadmap auslöst, und ob der Meilenstein M0 zu diesem Zweck bewusst so geschnitten wird, dass er die Schätzung der schwierigsten Bausteine kalibriert.

9. **Ausstiegsformat als Teil des Geschäftsmodells.** [Kapitel 21](21-betrieb-updates.md) hält offen, ob ein stabiles Austauschformat für Zuweisungen, Richtlinien und Veröffentlichungen gepflegt wird. Wirtschaftlich ist das kein Randthema: ohne ein solches Format sind die Ausstiegskosten im Gesamtkostenmodell unbestimmbar, und ein Betreiber, der das erkennt, bewertet die Abhängigkeit höher, als die Rechnung in 23.4 ausweist. Zu entscheiden ist, ob das Format zum Lieferumfang des Meilensteins M5 gehört, wer seine Stabilität über Schemahauptversionen hinweg trägt, und ob das Projekt die Pflege eines Formats finanziert, dessen einziger Zweck der Wechsel zu einem anderen System ist.
