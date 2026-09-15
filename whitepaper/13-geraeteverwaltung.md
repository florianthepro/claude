# 13 Geräteverwaltung und Geräteidentität

## 13.1 Das Gerät als eigenes Subjekt

Das Objektmodell führt **Gerät** gleichrangig neben **Person** (KANON 3). Beide sind Subjekte, beide tragen Zertifikate, beide haben einen Lebenszyklus, und keines leitet sich aus dem anderen ab. Die Verbindung entsteht über das Feld `eigentuemer` am Geräteobjekt und nicht über eine gemeinsame Kennung.

Diese Trennung ist eine Entwurfsentscheidung mit drei prüfbaren Folgen. Erstens überlebt ein Gerät das Ausscheiden seines Eigentümers: das Geräteobjekt wechselt den Eigentümer, es wird nicht gelöscht, und sein Zertifikat behält seine Gültigkeit, bis der Vorgang es ausdrücklich sperrt. Zweitens überlebt eine Person den Verlust jedes ihrer Geräte, weil ihre Authentisierungsmittel nach [Kapitel 10](10-identitaet.md) mehrfach vorliegen. Drittens sind Zugriffsentscheidungen konjunktiv formulierbar: der Eingang verlangt für einen Dienst ein gültiges Nutzertoken **und** ein gültiges Gerätezertifikat, und beide werden unabhängig voneinander gesperrt.

Die verworfene Alternative ist die in klassischen Verzeichnisdiensten übliche Modellierung des Rechners als Konto innerhalb derselben Kontenklasse wie der Nutzer. Sie spart eine Entität, koppelt aber Sperrung, Ablauf und Namensraum von Mensch und Maschine aneinander und macht die Frage, ob eine Anmeldung von einem verwalteten Gerät stammt, zu einer Auswertung von Namenskonventionen.

**Ein Gerät ist kein Knoten.** Eine Maschine, auf der Atrium läuft, ist ein Knoten und steht unter *Knoten & Speicher*; sie wird über einen Kopplungsvorgang mit Kopplungscode aufgenommen (INV-27). Ein Gerät ist alles andere und steht unter *Geräte*; es wird über einen **Aufnahmevorgang** mit **Aufnahmecode** aufgenommen. Die beiden Verfahren teilen das Muster, nicht die Implementierung, und ihre Codes sind absichtlich unterschiedlich dargestellt (13.3).

**Ein Gerät ist kein Subjekt einer Zuweisung.** Eine Zuweisung trägt die Absicht "dieses Subjekt soll diesen Dienst in dieser Rolle nutzen" und kennt nach KANON 3 als Subjekt Person, Gruppe oder Dienstkonto. Daraus folgt eine Regel, die der Kanon nicht ausformuliert und die dieses Kapitel festlegt: **die Gruppenart bestimmt die zulässige Mitgliederart.** Rechtegruppen enthalten Personen und Dienstkonten, Geltungsbereichsgruppen enthalten Geräte und Netzzonen, Verteilergruppen enthalten Personen und Postfächer. Nur eine Rechtegruppe kann Subjekt einer Zuweisung sein. Ohne diese Regel entstünde die Frage, was eine Zuweisung "Dienst X, Rolle Y" für ein Gerät in der Gruppe bedeutet, und jede Antwort darauf wäre entweder eine stille Nichtwirkung oder ein zweites, halbes Zuweisungsmodell.

## 13.2 Geräteklassen und Verwaltungstiefe

Die Klasse eines Geräts ist keine Beschriftung, sondern bestimmt Aufnahmeweg, Zertifikatsprofil, zulässige Netzzonen und die Menge erhebbarer Konformitätsmerkmale.

| Klasse | Verwaltbar | Nicht verwaltbar | Hardwarebindung erwartbar |
|---|---|---|---|
| Arbeitsplatzrechner Linux | Gerätezertifikat, Nutzerzertifikat, Vertrauensanker, Netzzugang, Zeitquelle, Resolverzwang, Konformitätsmeldung, Richtlinienabholung | Anwendungsverteilung, Betriebssystemaktualisierung, Datenträgerverschlüsselung einrichten, Benutzerprofile | TPM 2.0, wo vorhanden; sonst Betriebssystemspeicher |
| Arbeitsplatzrechner Windows | wie Linux, über den plattformüblichen Verwaltungsweg statt über einen eigenen Agenten | wie Linux; zusätzlich keine Domänenaufnahme (Kerberos ist nicht abgedeckt, siehe [Kapitel 10](10-identitaet.md)) | TPM 2.0 |
| Arbeitsplatzrechner macOS | wie Linux, über den plattformüblichen Verwaltungsweg | wie Linux | Sicherheitsbaustein des Geräts |
| Mobilgerät, dienstlich | Gerätezertifikat, Vertrauensanker, Netzprofil, Resolverzwang, Sperrbildschirmvorgabe, Fernlöschung des Geräts | Anwendungsverteilung, Anwendungskonfiguration | Sicherheitsbaustein des Geräts |
| Mobilgerät, privat mit Arbeitsbereich | Zertifikat und Vertrauensanker ausschließlich im Arbeitsbereich, Fernlöschung ausschließlich des Arbeitsbereichs | alles außerhalb des Arbeitsbereichs, einschließlich Konformität des Gesamtgeräts | Sicherheitsbaustein, soweit der Arbeitsbereich ihn freigibt |
| Server ohne Atrium | Gerätezertifikat, Vertrauensanker, Netzzone, DNS-Name, Firewallableitung, Zeitquelle | Betriebssystem, Dienste, Aktualisierungen, Datenhaltung | TPM 2.0, wo vorhanden |
| Netzgerät (Schalter, Funkzugangspunkt, Firewall) | RADIUS-Zuordnung, gemeinsames Geheimnis, Zonenabbildung, Zertifikat für die Verwaltungsschnittstelle, soweit das Gerät es annimmt | Firmware, Portkonfiguration jenseits der Zonenabbildung, herstellereigene Funktionen | selten; in der Regel keine |
| Drucker und Multifunktionsgerät | Netzzone, DNS-Name, Firewallableitung, Zertifikat und Vertrauensanker, soweit das Gerät sie annimmt | Warteschlangen, Treiber, Nutzerabrechnung, Firmware | keine |
| Eingebettetes Gerät (Sensor, Kamera, Steuerung) | Netzzone, Firewallableitung, Zulassung anhand der Hardwareadresse | in der Regel alles Übrige | keine |

Zwei Grenzen sind produktbestimmend und werden nicht relativiert.

**Atrium verteilt keine Software an Endgeräte.** Der Navigationsbereich *Geräte* schließt Softwareverteilung jenseits des Vertrauensankers und des Zertifikats ausdrücklich aus (KANON 5). Der Grund ist nicht Aufwand, sondern Fehlerdomäne: eine Paketverteilung an achthundert heterogene Endgeräte ist ein eigenes Produkt mit eigenem Teststand, eigener Rückrollmechanik und eigener Ausfallwirkung, und sie würde die Zusage aus INV-14 für jedes Verteilungsziel neu verhandeln. Die Folge ist unbequem und wird benannt: wer Anwendungspakete, Betriebssystemaktualisierungen oder Konfigurationsprofile jenseits der hier genannten Gegenstände ausrollen will, betreibt dafür ein eigenes Werkzeug. Atrium prüft den Zustand und zieht daraus Netzfolgen; es stellt ihn nicht her.

**Atrium ist kein Ersatz für eine Windows-Domäne.** Für Windows-Arbeitsplätze deckt Atrium Gerätezertifikat, Netzzugang, Vertrauensanker und Konformitätsmeldung ab. Einmaliges Anmelden an klassischen Dateifreigaben über Kerberos, Gruppenrichtlinienobjekte und Anmeldeskripte sind nicht abgedeckt.

## 13.3 Aufnahme

### Bedienweg

Der Bedienweg ist für alle Klassen identisch und kostet zwei Entscheidungen (K-03).

```
Bereich "Geraete" -> "Geraet aufnehmen"
  Entscheidung 1: Eigentuemer   (Person oder Gruppe; Suchfeld)
  Entscheidung 2: Geraeteklasse (Liste aus 13.2)
  [optional: Anzeigename; vorbelegt aus Eigentuemer + Klasse + laufender Nummer]
  -> Konsole zeigt: Aufnahmecode, Gueltigkeit, CA-Fingerabdruck, Aufnahmelink
  -> Geraet: Code eingeben bzw. Link oeffnen
  -> Konsole: Zustandswechsel "erfasst" -> "registriert"
```

Alles Übrige ist abgeleitet und trägt nach INV-15 eine benannte Quelle: der Mandant folgt aus dem Eigentümer, die Netzzone aus der Richtlinie des Mandanten für diese Geräteklasse, das Zertifikatsprofil aus der Klasse ([Kapitel 11](11-pki.md), Abschnitt 11.4), der technische Name aus der Kennung, die Konformitätsvorgaben aus der Richtlinie. Die Wirkungsvorschau nennt vor der Bestätigung, in welche Netzzone das Gerät fällt, welche Domänen dadurch für es auflösbar werden und welche Konformitätsmerkmale es erfüllen muss.

### Was im Hintergrund geschieht

```
1. Vorgang "geraet.aufgenommen" entworfen, Wirkungsvorschau berechnet (INV-08)
2. Geraeteobjekt im Sollzustand angelegt, Zustand "erfasst", Mandant gesetzt (INV-19)
3. Aufnahmegeheimnis erzeugt: 50 bit Zufall, Hashwert im Sollzustand,
   Klartext nur einmal in der Antwort an die Konsole (INV-20)
4. Geraet erzeugt Schluesselpaar lokal, im Sicherheitsbaustein wo vorhanden
5. Geraet sendet Zertifikatsantrag ueber EST (RFC 7030) an den Eingang,
   authentisiert mit dem Aufnahmegeheimnis
6. Ausstellung: CA uebernimmt aus dem Antrag ausschliesslich oeffentlichen
   Schluessel und Besitznachweis; alle Namen aus dem Objektgraphen (R-11-04)
7. Ausstellungsereignis ins replizierte Protokoll; Seriennummer in die
   Positivliste (R-11-18); Auditereignis "zertifikat.ausgestellt" (INV-23)
8. Vertrauensanker an das Geraet ausgeliefert, Fingerabdruck gegen den
   bei Schritt 4 gepinnten Wert geprueft
9. Netzzonenzuordnung wirksam: abgeleitete Firewallregeln und Resolversicht
   werden atomar getauscht (INV-09, INV-10)
10. Aufnahmegeheimnis vernichtet; Geraetezustand "registriert"
```

Die Reihenfolge ist nicht beliebig. Schritt 8 steht nach Schritt 5, weil das Gerät den Vertrauensanker zum Zeitpunkt des ersten Zertifikatsantrags noch nicht besitzt und die Verbindung deshalb nicht über die interne Kette prüfen kann. Gelöst wird das wie bei der Knotenkopplung (KANON 4.4a) über **Fingerabdruck-Pinning**: der Aufnahmecode wird zusammen mit dem Fingerabdruck der Ausgabe-CA angezeigt, der Aufnahmelink trägt ihn, und das Aufnahmeprogramm prüft die Kette gegen diesen Wert statt gegen einen Speicher. Ohne diesen Schritt wäre die Erstaufnahme gegen einen dazwischengeschalteten Angreifer ungeschützt, weil eine unvalidierte TLS-Verbindung das Aufnahmegeheimnis preisgäbe.

### Aufnahmewege je Klasse

| Klasse | Weg | Was das Gerät ausführt | Unsicherheit |
|---|---|---|---|
| Linux | signiertes Agentenpaket aus der Installation, Signatur nach RFC 8032 gegen einen im Paket nicht enthaltenen Freigabeschlüssel geprüft | Schlüsselerzeugung, EST-Antrag, Vertrauensankerpflege, Konformitätsmeldung, Richtlinienabholung | keine; der Agent ist Eigenentwicklung und vollständig spezifizierbar |
| Windows | plattformüblicher Verwaltungsweg des Betriebssystems, ausgelöst durch den Aufnahmelink | Registrierung gegen den Verwaltungsendpunkt, Zertifikatsantrag, Profilabholung | Die genaue Protokollausprägung und der Umfang der abfragbaren Merkmale sind herstellerabhängig. Festgelegt wird hier die Anforderung (R-13-06), nicht die Implementierung. |
| macOS, iOS-Klasse | plattformüblicher Verwaltungsweg des Betriebssystems über ein Verwaltungsprofil | wie Windows | wie Windows |
| Android-Klasse | plattformüblicher Verwaltungsweg, dienstlich als Vollverwaltung, privat als Arbeitsbereich | wie Windows | wie Windows; zusätzlich ist die Trennschärfe des Arbeitsbereichs eine Zusage der Plattform, die Atrium nicht prüfen kann |
| Server ohne Atrium | derselbe Agent wie Linux, ohne Konformitätsmeldung, oder reiner EST-Client | Schlüsselerzeugung, EST-Antrag, Vertrauensankerpflege | keine |
| Netzgerät | Konfigurationszugriff über die Verwaltungsschnittstelle des Geräts mit einem Zugangsdatum, das als Geheimnis hinterlegt ist | nichts; Atrium schreibt RADIUS-Adresse, gemeinsames Geheimnis und Zonenabbildung | Der Konfigurationszugriff ist herstellerspezifisch und wird über eine Konnektorbindung nach [Kapitel 09](09-konnektoren.md) erbracht, nicht über einen eigenen Codepfad. |
| Drucker, eingebettet | Erfassung ohne Agent: Hardwareadresse und Netzzone werden eingetragen, ein Zertifikat wird nur ausgestellt, wenn das Gerät einen Antrag stellen kann | nichts oder ein EST-Antrag | Ob ein konkretes Gerät EST spricht, ist geräteabhängig und wird bei der Erfassung geprüft, nicht angenommen. |

Der Agent für Linux ist die einzige Klasse, für die Atrium Code auf dem Endgerät ausliefert. Für ihn gelten dieselben Regeln wie für Kernkomponenten: Rust, kein dynamisch nachgeladener Code, kein Schalenaufruf mit interpolierten Werten, Schemavalidierung jeder empfangenen Nachricht am Rand, laufzeitkonstanter Vergleich des Aufnahmegeheimnisses, Fehlermeldungen ohne Preisgabe von Pfaden oder Zuständen, die der Aufrufer nicht ohnehin kennt. Er läuft mit den geringsten Rechten, die seine Aufgaben zulassen: Schreibrecht auf den Vertrauensankerspeicher und den Zertifikatsspeicher, Leserecht auf die Konformitätsmerkmale, kein Recht, Pakete zu installieren.

### Widerstand des Aufnahmecodes

Der Aufnahmecode ist ein kurzlebiges Geheimnis an einem Endpunkt, der über den Eingang erreichbar ist. Er ist damit exponierter als der Kopplungscode eines Knotens, dessen Endpunkt nur im Wartemodus und nur im lokalen Segment offen ist (KANON 7).

```
Einzelaufnahme
  10 Zufallszeichen Crockford-Base32 = 10 · log2(32) = 50 bit
  Raum            R = 2^50 = 1,1259 · 10^15
  Darstellung     XXXXX-XXXXX-XX  (2 Pruefzeichen ohne Entropie)
                  bewusst anders gruppiert als der Kopplungscode XXXX-XXXX-XXXX
  Gueltigkeit     24 h; 5 Fehlversuche je Code vernichten ihn (R-11-15)

  Erfolg je Code                5 / R = 4,44 · 10^-15

  Blindraten gegen alle offenen Codes gleichzeitig:
  V = Zahl gleichzeitig gueltiger Codes, A = globale Fehlversuche je Jahr
  Zielwert: global 20 Fehlversuche/h, je Quelladresse 10/h
  A = 20 · 24 · 365 = 175.200
  Obergrenze V = 10 (Konsole verweigert den 11. offenen Kurzcode je Mandant)
  Erfolg ueber ein Jahr = V · A / R = 10 · 175.200 / 1,1259·10^15
                        = 1,56 · 10^-9

Massenaufnahme (V > 10)
  16 Zufallszeichen = 80 bit, R = 1,2089 · 10^24
  nicht abtippbar; nur als Aufnahmelink oder maschinenlesbar ausgeliefert
  Erfolg ueber ein Jahr bei V = 1.000: 1.000 · 175.200 / 1,2089·10^24
                        = 1,45 · 10^-16
```

Das ist ein Modell, keine Messung. Zwei Einsichten daraus sind für den Entwurf bestimmend. Erstens skaliert das Blindraten mit der Zahl gleichzeitig offener Codes; die Fünf-Fehlversuche-Grenze je Code schützt den einzelnen Code, nicht den Bestand. Zweitens liegt der Wert 1,56 · 10⁻⁹ um den Faktor 7,8 über dem Wert des Kopplungscodes aus K-14 (2 · 10⁻¹⁰), was unmittelbar aus der längeren Gültigkeit (24 h statt 15 min) und der größeren Zahl gleichzeitig offener Codes folgt.

Die längere Gültigkeit wird beibehalten, weil ein Aufnahmecode einer Person zugestellt wird, die das Gerät möglicherweise erst am Folgetag in der Hand hält; ein 15-Minuten-Fenster erzwänge, dass eine Administratorin neben jedem Gerät steht. Der Preis ist die genannte Zahl, und sie steht hier, statt sie durch eine schmeichelhafte Rechnung zu ersetzen.

Der **Aufnahmelink** ist keine zweite Sicherheitsstufe, sondern eine Eingabeerleichterung: er trägt dasselbe Geheimnis und denselben Fingerabdruck. Wird er über einen Kanal zugestellt, den Atrium nicht kontrolliert, ist seine Sicherheit die Sicherheit dieses Kanals. Die Konsole kennzeichnet deshalb jede über einen Link abgeschlossene Aufnahme im Auditereignis, und die Richtlinie eines Mandanten kann den Linkweg abschalten.

## 13.4 Geräteidentität und Schlüsselmaterial

### Schlüsselerzeugung und Hardwarebindung

Der private Schlüssel entsteht auf dem Gerät und verlässt es nicht (INV-20). EST bietet serverseitige Schlüsselerzeugung nicht an (R-11-15), ein Export als PKCS#12 existiert nicht, und es gibt keinen Bedienpfad, über den ein privater Schlüssel in die Konsole, in ein Protokoll oder in einen Sollzustandsexport gelangen könnte.

Die Hardwarebindung ist der Nachweis, dass dieser Schlüssel in einem Sicherheitsbaustein erzeugt wurde und ihn nicht verlassen kann. Wo die Plattform einen solchen Nachweis mitliefert, wird er bei der Ausstellung geprüft und das Ergebnis als Attribut `hardwarebindung` am Geräteobjekt geführt. Wo nicht, steht dort dauerhaft "keine" (INV-18).

| Wert | Bedeutung | Prüfbar durch |
|---|---|---|
| `bescheinigt` | Der Schlüssel liegt nachweislich in einem Sicherheitsbaustein und ist nicht exportierbar | Bescheinigung der Plattform, gegen die Herstellerkette geprüft |
| `behauptet` | Das Gerät meldet einen Sicherheitsbaustein, liefert aber keine prüfbare Bescheinigung | nichts; Angabe des Geräts |
| `keine` | Der Schlüssel liegt im Betriebssystemspeicher | entfällt |

Der Unterschied zwischen `bescheinigt` und `behauptet` ist der zwischen einem Beleg und einer Aussage, und die Konsole benennt ihn so. Eine Richtlinie kann eine Netzzone auf Geräte mit `bescheinigt` beschränken; das ist die einzige Stelle, an der die Unterscheidung eine technische Folge hat. Ohne diese Folge wäre sie Zierrat.

Die ehrliche Grenze: eine Bescheinigung belegt die Herkunft des Schlüssels, nicht die Integrität des Geräts. Ein Gerät mit kompromittiertem Betriebssystem und bescheinigtem Schlüssel authentisiert sich korrekt und handelt im Sinne des Angreifers. Die Hardwarebindung verhindert das Kopieren der Identität auf eine zweite Maschine, mehr nicht.

### Erneuerung

Gerätezertifikate laufen 365 Tage und erneuern ab 120 Tagen Restlaufzeit (K-13). Die Erneuerung authentisiert sich mit dem vorhandenen Zertifikat, sodass nach der Erstaufnahme kein Geheimnis mehr im Spiel ist (R-11-15). Der Versuchsfahrplan und das daraus folgende Ausfallbudget sind in [Kapitel 11](11-pki.md) gerechnet: bei einer Annahme von 8 Betriebsstunden an 5 von 7 Tagen ergeben sich 114 Versuche im Fenster.

Daraus folgt eine Zustandsanzeige statt einer stillen Frist. Die Geräteliste führt "seit N Tagen nicht gemeldet" und hebt ab 60 Tagen hervor. Ein Gerät, das 120 Tage am Stück ausgeschaltet oder außerhalb des Netzes ist, verliert sein Zertifikat; das ist kein Fehler, sondern das beabsichtigte Verhalten, weil ein Gerät, das vier Monate nicht erreichbar war, kein verwaltetes Gerät mehr ist. Die Wiederaufnahme ist ein einzelner Vorgang mit einem neuen Aufnahmecode und derselben Gerätekennung; das Objekt und seine Historie bleiben erhalten.

### Sperrung bei Verlust

Die Meldung eines verlorenen Geräts ist im Navigationsbereich *Geräte* eine einzelne Handlung (KANON 5) und zusätzlich eine Selbstbedienungshandlung der Person über ihre eigene Geräteliste ([Kapitel 10](10-identitaet.md)). Sie erzeugt einen Vorgang mit folgender Wirkung:

```
geraet.verloren_gemeldet
  -> Zustand "verloren/gesperrt"
  -> alle Zertifikate des Geraets gesperrt
  -> Seriennummern aus der Positivliste entfernt; Verteilung p95 <= 10 s (R-11-20)
  -> Sperrliste fuer fremde Pruefstellen neu veroeffentlicht (<= 6 h)
  -> RADIUS: laufende Sitzungen werden abgebrochen (13.5)
  -> auf dem Geraet registrierte Authentikatoren der Person entwertet
  -> Netzzonenmitgliedschaft endet; abgeleitete Regeln zurueckgenommen
  -> Auditereignisse: geraet.gesperrt, zertifikat.gesperrt (je Zertifikat)
```

Die Sperrung ist absichtlich nicht freigabepflichtig. Eine Vier-Augen-Pflicht auf der schnellsten Schutzhandlung des Systems würde genau den Fall verzögern, für den sie existiert. Die Asymmetrie ist Entwurf: Handlungen, die dem Angreifer schaden, laufen sofort; Handlungen, die den Daten schaden, laufen über eine Freigabe (13.9).

### Verhältnis zur Nutzeridentität

Gerät und Person sind getrennte Subjekte; die **Zuordnung** ist das Feld `eigentuemer`. Sie ist kein eigenes Objekt, weil sie keine eigenen Attribute trägt und ihre Änderung ein gewöhnlicher Vorgang am Geräteobjekt ist.

| Frage | Antwort |
|---|---|
| Wie viele Geräte kann eine Person besitzen | unbegrenzt; die Richtlinie des Mandanten kann eine Obergrenze setzen |
| Wie viele Eigentümer hat ein Gerät | genau einen: eine Person oder eine Gruppe |
| Was bedeutet eine Gruppe als Eigentümer | ein geteiltes Gerät (Besprechungsraum, Werkstatt, Schalter); jedes Mitglied der Gruppe darf es melden und sperren |
| Was passiert beim Ausscheiden des Eigentümers | Der Ausscheidevorgang der Person listet jedes Gerät einzeln auf und verlangt je Gerät eine Entscheidung: Eigentümer wechseln oder Gerät ausmustern. Eine Kaskadenlöschung existiert nicht (INV-11). |
| Ist eine Anmeldung ohne Gerätezertifikat möglich | ja, wo die Richtlinie es zulässt; Dienste mit erhöhtem Schutzbedarf verlangen beides |

Bei einer Gruppe als Eigentümer ist die Bindung eines Nutzertokens an die Gerätekennung ([Kapitel 10](10-identitaet.md), 10.6) schwächer, weil das Gerät nicht auf eine Person zurückführt. Die Konsole zeigt das am Gerät an; eine Richtlinie kann geteilte Geräte aus Zonen mit erhöhtem Schutzbedarf ausschließen.

## 13.5 Netzzugang

### 802.1X mit EAP-TLS

Der Netzzugang verwendet IEEE 802.1X mit EAP (RFC 3748) und EAP-TLS (RFC 5216) gegen einen eigenen RADIUS-Dienst (RFC 2865) auf 1812/udp, Abrechnung auf 1813/udp, standortübergreifend RadSec (RFC 6614) auf 2083/tcp (KANON 7). Kennwortbasierte EAP-Verfahren sind nicht vorgesehen: der Netzzugang gehört an das Gerätezertifikat, nicht an ein Nutzerkennwort (KANON 4, Festlegung 9b).

Der RADIUS-Dienst ist ein eigener Dienst und kein Bestandteil des Identitätsanbieters. Er prüft ausschließlich gegen die interne Ausgabe-CA und die aus dem Sollzustand verteilte Positivliste gültiger Seriennummern (R-11-18), mit Hard-Fail gegen den Listeninhalt. Seine Konfiguration ist vollständig erzeugt; es gibt keine handgepflegte Datei und keinen Dialog zur 802.1X-Einstellung im Navigationsbereich *Geräte* (KANON 5). Das gemeinsame Geheimnis je Netzgerät ist ein abgeleitetes Artefakt (INV-09) und wird bei der Erfassung des Netzgeräts erzeugt, nie eingegeben.

### Zuordnung zu Netzsegmenten

Die Zonenzuordnung folgt aus dem Objektgraphen und wird nicht am Schalter gepflegt:

```
Geraet --eigentuemer--> Person --Mitglied--> Rechtegruppe
Geraet --Mitglied----> Geltungsbereichsgruppe
Geraet --klasse------> Richtlinie des Mandanten
                            |
                            v
                     Netzzone (genau eine zur Zeit)
                            |
              +-------------+-------------+
              v             v             v
      Firewallregeln   Resolversicht   VLAN/Overlay-Zuordnung
      (abgeleitet)     (abgeleitet)    (RADIUS-Antwortattribut)
```

Der RADIUS-Dienst antwortet auf eine erfolgreiche Authentisierung mit den Attributen, die das Netzgerät in das zugeordnete Segment schalten. Welche Attribute ein konkretes Netzgerät versteht, ist herstellerabhängig; festgelegt wird die Anforderung (R-13-13), nicht die Attributliste.

Die Auswertungsreihenfolge ist deterministisch und wird angezeigt: Gerätezustand vor Richtlinie, spezifischere Geltungsbereichsgruppe vor allgemeinerer, Konformitätsfolge zuletzt. Bei Gleichstand gewinnt die restriktivere Zone, und die Konsole nennt die Regel, die entschieden hat.

**Ein Gerät ist zu einem Zeitpunkt in genau einer Netzzone.** Das folgt aus dem Objektmodell (KANON 3) und hat eine Folge, die in 13.11 gerechnet wird: die Auflösungsgranularität für DNS-Sichten ist die Zone, nicht das Gerät.

### Geräte ohne 802.1X und deren Risiko

Drucker, eingebettete Geräte und ältere Netzgeräte sprechen häufig kein 802.1X oder können keinen privaten Vertrauensanker prüfen. Der Entwurf löst das nicht, er begrenzt es.

| Ausnahme | Verfahren | Risiko | Begrenzung |
|---|---|---|---|
| Zulassung anhand der Hardwareadresse | Der Schalter fragt RADIUS mit der Hardwareadresse als Kennung; Atrium antwortet, wenn ein Geräteobjekt mit dieser Adresse im Zustand "aktiv" existiert | Hardwareadressen sind fälschbar; die Ausnahme ist kein Authentisierungsverfahren, sondern eine Inventarabfrage | Ausschließlich in Zonen, die in der abgeleiteten Regelmenge nur den Zweck des Geräts erreichen; nie in einer Zone mit Zugriff auf Verwaltung, Speicher oder Personendaten |
| Statischer Port | Der Port trägt fest eine Zone, ohne Authentisierung | Wer physischen Zugang zum Port hat, ist in der Zone | Nur für Geräte ohne Netzstapel für Authentisierung; die Konsole führt jeden statischen Port dauerhaft als benannte Ausnahme mit Begründungspflicht (INV-18) |

Beide Ausnahmen sind im Objektmodell sichtbar, nicht im Schalter versteckt: jede erzeugt ein Ausnahmeobjekt mit Begründung, Antragsteller und Befristung, und der Überblick zeigt ihre Zahl. Die Zusage lautet damit nicht "jedes Gerät ist authentisiert", sondern "jede Ausnahme ist gezählt, begründet, befristet und auf eine Zone ohne Querzugriff beschränkt". Das ist weniger, und es ist überprüfbar.

### Gastnetz

Das Gastnetz ist eine Netzzone ohne Geräteobjekte. Es kennt keine Zertifikate, keine Konformität und keine Zuordnung zu einer Person. Seine abgeleitete Regelmenge erlaubt genau: Auflösung über den Resolver dieser Zone, Zugang zum Weitverkehrsnetz, Zugang zu ausdrücklich für "öffentlich" veröffentlichten Diensten. Alles Übrige ist Default-Deny (INV-10), einschließlich des Verkehrs zwischen Gastgeräten untereinander.

Die interne Resolversicht wird im Gastnetz nicht ausgeliefert; ein interner Name ist dort nicht auflösbar. Das ist keine Sicherheitsmaßnahme, sondern Hygiene, weil die Firewallableitung ohnehin sperrt; die Konsole sagt das auch so, statt Namensverbergung als Schutz auszugeben.

### Sitzungsabbruch bei Sperrung

Eine Sperrung wirkt an der Prüfstelle in p95 ≤ 10 s (R-11-20). Eine bereits bestehende 802.1X-Sitzung ist davon nicht betroffen, weil sie nach der Authentisierung nicht erneut geprüft wird. Zwei Mechanismen schließen das Fenster, und beide haben eine Grenze:

```
Fall A: Netzgeraet beherrscht Sitzungsruecksetzung (Change of Authorization)
  Atrium sendet den Abbruch an das Netzgeraet
  Zielwert Wirksamkeit: p95 <= 10 s

Fall B: Netzgeraet beherrscht sie nicht
  Die Sitzung endet erst bei der naechsten Reauthentisierung
  Fenster = Reauthentisierungsintervall T
  T = 8 h  -> Erwartungswert 4 h, schlimmster Fall 8 h
  T = 1 h  -> Erwartungswert 30 min, schlimmster Fall 1 h

Last bei T = 1 h, Annahme 800 Geraete, davon 500 gleichzeitig verbunden:
  500 / 3600 s = 0,139 Handschlaege/s = 12.000 je Tag
Last bei T = 8 h:
  500 / 28800 s = 0,017 Handschlaege/s = 1.500 je Tag
```

Festlegung: T = 1 h in Zonen mit erhöhtem Schutzbedarf, T = 8 h sonst. Die Konsole zeigt je Netzgerät, ob Fall A oder Fall B gilt, und leitet daraus am Gerät das tatsächliche Sperrfenster ab, statt überall 10 Sekunden zu behaupten.

## 13.6 Vertrauensspeicher

Das Wurzelzertifikat gelangt ausschließlich über die Geräteverwaltung in Vertrauensspeicher. Es gibt keinen Download mit Einbauanleitung (R-11-21), weil eine solche Anleitung die Handlung einübt, die ein Angreifer braucht.

| Klasse | Speicher, den Atrium pflegt | Speicher, den Atrium nicht erreicht |
|---|---|---|
| Linux | Systemvertrauensspeicher; zusätzlich die dem Agenten bekannten Anwendungsspeicher | Speicher, die eine Anwendung mitbringt: mitgelieferte Zertifikatsbündel in Laufzeitumgebungen, Anwendungen mit eingebautem Bündel |
| Windows, macOS, Mobilgeräte | der über den plattformüblichen Verwaltungsweg beschreibbare Speicher | dieselbe Klasse von Anwendungsspeichern |
| Server ohne Atrium | Systemvertrauensspeicher über den Agenten | wie Linux |
| Drucker, eingebettet, Netzgeräte | soweit das Gerät einen Import annimmt | in der Regel alles |

**Die Lücke wird benannt, nicht überspielt.** Ein Vertrauensanker im Systemspeicher erreicht nicht jede Anwendung. Der Agent führt eine Liste der von ihm gepflegten Speicher, meldet sie als Teil des Istzustands und nennt in der Konsole die Zahl der Speicher, die er auf diesem Gerät gefunden, aber nicht geschrieben hat. Damit ist die Aussage am Gerät nicht "vertraut", sondern "vertraut in n von m gefundenen Speichern" — eine Angabe, die einem Bediener bei einem TLS-Fehler in genau einer Anwendung tatsächlich hilft.

Wo die Plattform eine Einschränkung des Vertrauensankers auf einen Namensraum zulässt, wird sie gesetzt; wo nicht, bleibt die Namensbeschränkung in der Mandanten-Zwischen-CA die einzige Grenze (R-11-03).

**Entfernen beim Ausscheiden.** Der Vertrauensanker wird im Ausmusterungsvorgang entfernt, wenn das Gerät erreichbar ist. Ist es das nicht — Diebstahl, Defekt, Wechsel ohne Rückgabe —, bleibt der Anker auf dem Gerät. Das ist nicht reparierbar, und der Ausmusterungsnachweis führt es als "nicht entfernt" auf (INV-12). Die Schadensbegrenzung liegt nicht im Entfernen, sondern im Umfang des Ankers: die Zwischen-CA des Mandanten kann nur für dessen Namensraum ausstellen, und ein gestohlenes Gerät mit gültigem Anker kann damit ausschließlich Namen dieses Mandanten vorgetäuscht bekommen — und das auch nur von jemandem, der zusätzlich einen privaten CA-Schlüssel besitzt.

## 13.7 Konformität

### Geprüfte Eigenschaften und ihre Erhebung

| Eigenschaft | Erhebung | Beleg oder Aussage |
|---|---|---|
| Datenträgerverschlüsselung aktiv | Abfrage der Plattform durch Agent oder Verwaltungsweg | Aussage des Geräts |
| Aktualisierungsstand (Tage seit letzter Sicherheitsaktualisierung) | Abfrage der Plattform | Aussage des Geräts |
| Schutzsoftware aktiv, soweit die Plattform eine führt | Abfrage der Plattform | Aussage des Geräts |
| Sperrbildschirm mit Zeitgrenze | Abfrage der Plattform | Aussage des Geräts |
| Gesicherter Start aktiv | Abfrage der Plattform; mit Bescheinigung, wo die Plattform sie liefert | Beleg, wo bescheinigt; sonst Aussage |
| Hardwarebindung des Gerätezertifikats | Ergebnis der Ausstellungsprüfung (13.4) | Beleg |
| Zertifikat gültig und nicht gesperrt | Prüfung in der Kontrollebene | Beleg |

Die ersten vier Zeilen sind Aussagen, nicht Belege. Nur die letzten drei sind belegbar, und zwei davon prüft Atrium selbst.

**Konformität ist Istzustand, nicht Sollzustand.** Meldungen laufen nicht in den quorumpflichtigen Zustand, sondern in den knotenlokalen Istzustand und werden nur verdichtet repliziert (K-12). Jede Anzeige nennt den Beobachtungszeitpunkt (INV-28).

```
Annahme: 800 Geraete, Meldeintervall 4 h, 2 KB je Meldung
  Meldungen je Tag   = 800 · 24/4 = 4.800
  Rate               = 4.800 / 86.400 s = 0,056 /s
  Volumen je Tag     = 4.800 · 2 KB = 9,6 MB (knotenlokal)
  Replizierter Anteil = 800 · 1 KB = 0,8 MB (verdichtet, K-12)
```

0,8 MB sind 1,6 % des Zielwerts von 50 MB für den gesamten Sollzustand — vertretbar. Die ungekürzten 9,6 MB je Tag im replizierten Protokoll wären es nicht: über ein Jahr 3,5 GB gegen einen Zielwert von 2 GB je Momentaufnahme. Das ist die Rechnung, die die Trennung von Soll- und Istzustand an dieser Stelle begründet.

Ein Gerät, das nicht meldet, ist nicht "konform" und nicht "nicht konform", sondern **unbekannt**. Unbekannt erfüllt keine Richtlinie, die Konformität verlangt. Die Folge tritt aber erst ein, wenn das Gerät sich wieder verbindet, weil ein ausgeschaltetes Gerät in keiner Zone ist und es nichts zu verschieben gibt.

### Folge der Nichterfüllung

Die Folge ist ein **Segmentwechsel**, keine Sperre.

```
Erste Feststellung der Nichterfuellung
  -> Zustand am Geraet: "Nachbesserung noetig: <Eigenschaft>"
  -> Meldung an den Eigentuemer im Klartext, mit der konkreten Eigenschaft
  -> Frist: Zielwert 7 d
Nach Fristablauf
  -> Netzzone wechselt in die Nachbesserungszone des Mandanten
  -> abgeleitete Regeln werden atomar getauscht (INV-09, INV-10)
Nach Behebung, beim naechsten Meldelauf (<= 4 h)
  -> Rueckwechsel in die urspruengliche Zone, ohne Bedienereingriff
```

Die Nachbesserungszone ist Default-Deny mit genau vier Ausnahmen: Aktualisierungsquellen, Namensauflösung, Zeitquelle, die Nachbesserungsseite der Konsole. Sie ist keine Strafzone, sondern die einzige Zone, in der das Gerät den Mangel beheben kann. Ein vollständiger Netzausschluss wird verworfen, weil ein ausgeschlossenes Gerät weder aktualisiert noch verschlüsselt noch neu registriert werden kann und der Bediener den Ausschluss dann von Hand aufheben muss — womit die Maßnahme regelmäßig abgeschaltet wird.

Der Regelaufwand wächst mit der Zonenzahl, nicht mit der Gerätezahl:

```
Zonenbasiert:   Regeln = c · |Zonen|,  c = Annahme 20 Regeln je Zone
                800 Geraete in 6 Zonen: 20 · 6 = 120 Regeln
Geraetebasiert: Regeln = c · |Geraete| = 20 · 800 = 16.000 Regeln
Faktor 133
```

### Grenze der Aussage

Konformitätsangaben eines Geräts sind nur so vertrauenswürdig wie das Gerät selbst. Ein Gerät, dessen Betriebssystem ein Angreifer kontrolliert, meldet sich als konform. Eine Bescheinigung des gesicherten Starts verschiebt die Schwelle auf die Startkette und sagt nichts über den Zustand nach dem Start. Es gibt kein Verfahren, mit dem eine Gegenstelle den Laufzeitzustand eines Endgeräts beweist, und dieses Kapitel behauptet keines.

Daraus folgt die richtige Einordnung: Konformitätsprüfung ist ein Hygieneinstrument gegen Nachlässigkeit — ein vergessenes Update, eine abgeschaltete Verschlüsselung, ein fehlender Sperrbildschirm —, und sie ist wirkungslos gegen einen Angreifer, der das Gerät bereits kontrolliert. Die Konsole formuliert das Ergebnis deshalb als "gemeldeter Zustand", nicht als "geprüfter Zustand", und die Berichte tun es ebenso. Eine Zugriffsentscheidung, die allein auf einer Konformitätsmeldung beruht, ist eine Entscheidung auf Zuruf des Bewerteten.

## 13.8 Inventar und Datenschutzgrenze

| Erfasst | Quelle | Aktualität |
|---|---|---|
| Kennung, Anzeigename, Art, Klasse, Mandant | Sollzustand | sofort |
| Eigentümer | Sollzustand | sofort |
| Zertifikatsverweise mit Restlaufzeit | Sollzustand | sofort |
| Netzzone | abgeleitet | sofort |
| Hardwarebindung | Ausstellungsprüfung | bei Ausstellung |
| Hardwareadressen | Meldung oder Erfassung | je Meldelauf |
| Betriebssystemfamilie und Aktualisierungsstand | Meldung | je Meldelauf (Zielwert 4 h) |
| Konformitätsmerkmale aus 13.7 | Meldung | je Meldelauf |
| Zeitpunkt der letzten Meldung | Beobachtung | laufend |
| Modell- und Seriennummer | Meldung, nur bei dienstlichen Geräten | je Meldelauf |

**Nicht erfasst, in keiner Klasse:** Standortdaten, besuchte Namen oder Adressen, Dateinamen, Dateiinhalte, Bildschirminhalte, Tastatureingaben, Inhalt oder Empfänger von Nachrichten. Diese Aufzählung ist eine Festlegung, keine Voreinstellung: die entsprechenden Felder existieren im Datenmodell nicht, die API kennt sie nicht, und der Agent erhebt sie nicht. Eine Fähigkeit, die nicht existiert, kann nicht durch eine Richtlinienänderung eingeschaltet werden — das ist der Unterschied zwischen einer Zusage und einer Einstellung.

Der Grund ist zugleich rechtlich und praktisch. Die DSGVO (Verordnung (EU) 2016/679) verlangt Datenminimierung und eine Zweckbindung; für die Entscheidung, ob ein Gerät in eine Netzzone darf, ist keines der genannten Merkmale erforderlich. Praktisch gilt: jedes erhobene Merkmal wird irgendwann angefragt, ausgewertet und in einem Konflikt verwendet, und ein Produkt, das eine radikal einfache Bedienung verspricht, darf nicht nebenbei ein Überwachungswerkzeug sein.

| Profil | Erfasster Umfang | Fernlöschung |
|---|---|---|
| Dienstlich | vollständige Tabelle oben | ganzes Gerät oder Arbeitsbereich |
| Privat mit Arbeitsbereich | Zertifikatsgültigkeit, Hardwarebindung, Verschlüsselung und Sperrbildschirm **des Arbeitsbereichs**, Aktualisierungsstand des Betriebssystems, letzte Meldung. Keine Seriennummer, keine Hardwareadresse außerhalb der Arbeitsverbindung, kein Merkmal des Privatbereichs | ausschließlich Arbeitsbereich |

Das private Profil ist in der Konsole am Gerät sichtbar und in der Konformitätsansicht als solches gekennzeichnet, damit ein Bediener nicht das Fehlen von Merkmalen für einen Störungszustand hält. Die ehrliche Einschränkung: die Trennschärfe des Arbeitsbereichs ist eine Eigenschaft der Plattform, nicht von Atrium. Atrium kann nicht prüfen, ob der Arbeitsbereich tatsächlich getrennt ist; es kann nur die Merkmale erfragen, die die Plattform für ihn ausweist, und darauf vertrauen, dass sie die Grenze einhält.

## 13.9 Fernaktionen

| Aktion | Wirkung | Umkehrbar | Freigabe | Bedingung |
|---|---|---|---|---|
| Sperren | Zertifikate gesperrt, Netzzugang entzogen, Sitzungen abgebrochen | durch Wiederaufnahme, nicht durch Entsperren desselben Zertifikats | keine | keine; wirkt auch offline (an der Prüfstelle) |
| Richtlinie erneuern | erneute Zustellung des geltenden Richtliniensatzes und des Vertrauensankers | entfällt (idempotent, INV-07) | keine | Gerät online |
| Neustart | Neustart des Geräts nach Ankündigung und Wartefrist | entfällt | keine | Gerät online |
| Arbeitsbereich löschen | Daten des Arbeitsbereichs vernichtet | **nein** | Vier-Augen | Gerät online |
| Gerät vollständig löschen | Datenträger des Geräts vernichtet | **nein** | Vier-Augen | Gerät online **und** Zustand "verloren/gesperrt" |

Die Freigabepflicht folgt aus INV-11: eine nicht rücknehmbare Aktion ist als solche gekennzeichnet, trägt eine vollständige Auswirkungsliste und ist nie Teil einer Massenaktion ohne Einzelaufstellung. Für Löschaktionen bedeutet das konkret: eine Auswahl von zwölf Geräten erzeugt zwölf einzeln aufgeführte Wirkungen mit je einem Eigentümer, und die Freigabe bestätigt die Liste, nicht die Zahl.

Die Vorbedingung "Zustand verloren/gesperrt" für die vollständige Löschung ist kein zusätzlicher Klick, sondern eine Reihenfolgeregel: wer ein Gerät löschen will, hat es zuvor als verloren gemeldet oder ausgemustert, und beide Vorgänge sind billiger rückgängig zu machen als eine Löschung.

**Die Fernlöschung ist die schwächste Maßnahme dieses Kapitels, und der Entwurf sagt das.** Sie wirkt nur, wenn das Gerät online geht. Ein ausgeschaltetes, in einem Faradaykäfig liegendes oder mit entnommenem Datenträger betriebenes Gerät wird nie gelöscht. Die tatsächliche Schutzwirkung bei Verlust liegt in zwei anderen Dingen: der Datenträgerverschlüsselung, die vor der Wegnahme aktiv war, und der Zertifikatssperrung, die sofort und ohne Zutun des Geräts wirkt. Die Konsole stellt die Fernlöschung deshalb als "wird beim nächsten Kontakt ausgeführt" dar und zeigt am Geräteobjekt dauerhaft den Zustand "Löschung ausstehend seit <Zeit>", bis sie bestätigt ist (INV-12, INV-18). Sie meldet nie "gelöscht", solange keine Bestätigung des Geräts vorliegt.

## 13.10 Ausscheiden eines Geräts

Die Reihenfolge unterscheidet sich zwischen einer geordneten Ausmusterung und einem Verlust, und der Unterschied ist nicht kosmetisch: die Löschung braucht Netzzugang, die Sperrung nimmt ihn.

| Schritt | Geordnete Ausmusterung | Verlust oder Diebstahl |
|---|---|---|
| 1 | Vorgang anlegen, Wirkungsvorschau berechnen (INV-08) | Vorgang anlegen |
| 2 | Arbeitsbereich oder Gerät löschen, Bestätigung des Geräts abwarten | Zertifikate sperren, Positivliste aktualisieren, Sitzungen abbrechen |
| 3 | Vertrauensanker entfernen, Bestätigung abwarten | Netzzonenmitgliedschaft beenden |
| 4 | Zertifikate sperren, Positivliste aktualisieren | Fernlöschung beauftragen, Zustand "Löschung ausstehend" setzen |
| 5 | Netzzonenmitgliedschaft beenden, abgeleitete Regeln zurücknehmen | Auswirkungen auf den Eigentümer prüfen (Authentikatorverlust, [Kapitel 10](10-identitaet.md)) |
| 6 | Zustand "ausgemustert"; Aufbewahrungsfrist 30 d (K-25) | Zustand bleibt "verloren/gesperrt", bis eine Entscheidung fällt |
| 7 | Ausmusterungsnachweis erzeugen und signieren | Nachweis erzeugen, mit benannten Resten |

Die Wirkungsvorschau in Schritt 1 nennt vollständig: welche Zertifikate gesperrt werden, welche Domänen einen Geltungsbereichseintrag verlieren, welche Netzzone ein Mitglied verliert, ob das Gerät der letzte plattformgebundene Authentikator seines Eigentümers ist und ob dadurch der Wiederherstellungsweg W2 aus [Kapitel 10](10-identitaet.md) entfällt. Der letzte Punkt ist der, den ein Bediener am häufigsten übersieht: die Ausmusterung des Dienstrechners kann eine Person aus ihrem eigenen Konto aussperren.

**Nachweis.** Der Ausmusterungsnachweis ist ein signierter Auszug aus dem Auditstrom mit Vorgangskennung, Zeitpunkten je Schritt, Ergebnis je Schritt und einer ausdrücklichen Liste dessen, was **nicht** ausgeführt wurde. Er dokumentiert ausgeführte Schritte; er belegt keine Datenabwesenheit. Ein Nachweis, der "Daten gelöscht" behauptet, während die Löschbestätigung des Geräts fehlt, wäre eine Falschaussage in einem Dokument, das für Prüfungen verwendet wird; deshalb trägt der Nachweis in diesem Fall den Eintrag "Löschung nicht bestätigt" und gilt als unvollständig. Die Auditereignisse überleben die Löschung des Geräteobjekts (INV-23).

Für Datenträger, die physisch ausscheiden, endet die technische Zuständigkeit von Atrium an der Grenze des Geräts. Die Konsole bietet ein Feld zur Erfassung einer Vernichtungsbestätigung mit benannter Person und Datum; dieses Feld ist ausdrücklich eine menschliche Erklärung und wird im Nachweis als solche gekennzeichnet, nicht als Systemtatsache.

## 13.11 Zusammenspiel mit DNS-Sichten, Netzzonen und Zuweisungen

Eine Domäne trägt einen Geltungsbereich (Gruppe, Person, Gerät oder Netzzone), und jeder DNS-Eintrag trägt seine Sichtzugehörigkeit als Attribut (KANON 4, Festlegung 8b). Die Abbildung auf Geräte läuft über die Netzzone, nicht über die Sitzung des angemeldeten Nutzers, weil Namensauflösung am Gerät und im Netz stattfindet. Die Ableitungskette ist in [Kapitel 12](12-dns-netzwerk.md) beschrieben; hier steht ihre Genauigkeitsgrenze.

```
Domaene D, Geltungsbereich = Geltungsbereichsgruppe G
G enthaelt 12 Geraete
Diese 12 Geraete liegen in 3 Netzzonen
Diese 3 Zonen enthalten insgesamt 212 Geraete

Auflösung ueber 53/udp (Resolver je Netzzone):
  D ist fuer alle 212 Geraete auflösbar
  Ueberreichweite = 212 / 12 = 17,7

Auflösung ueber DNS over TLS (RFC 7858) bzw. DNS over HTTPS (RFC 8484)
mit Geraetezertifikat als Clientmerkmal (KANON 7):
  D ist fuer genau 12 Geraete auflösbar
  Ueberreichweite = 1,0
```

Das ist die Schwäche an der Stelle, an der sie entsteht: **ein Geltungsbereich auf Geräteebene ist über unverschlüsselte Namensauflösung nicht durchsetzbar, sondern nur auf Zonenebene.** Der Faktor 17,7 ist ein Rechenbeispiel aus der genannten Annahme und kein Messwert; die Aussage dahinter ist unabhängig von den Zahlen, weil der Resolver auf 53/udp nur die Quelladresse kennt.

Daraus folgt die Festlegung: ein Geltungsbereich, der feiner ist als eine Netzzone, wird nur wirksam, wenn die betroffenen Geräte den internen Resolver über DNS over TLS oder DNS over HTTPS mit Gerätezertifikat benutzen. Die Konsole verlangt das nicht, sie zeigt es an: bei einer Domäne mit Geräte- oder Gruppengeltung nennt sie, für wie viele Geräte der Bereich genau durchsetzbar ist und für wie viele er über die Zone hinaus sichtbar wird. Das ist die Anwendung der Zusagengrenze aus KANON 4, Festlegung 8c auf den Einzelfall. Verschlüsselte Namensauflösung im Browser wird auf verwalteten Geräten per Richtlinie auf den internen Resolver gezwungen; auf nicht verwalteten Geräten ist das nicht durchsetzbar, und die Konsole behauptet es nicht.

Die Firewallableitung hat diese Schwäche nicht. Sie arbeitet auf Quelladresse und Zone, und die Zone ist genau die Granularität, die das Netzgerät auch durchsetzt. Namensauflösung ist Information, Paketfilterung ist Durchsetzung, und ein Name, den ein Gerät auflösen kann, ohne den Dienst zu erreichen, ist ein Informationsleck und keine Zugriffslücke. Die Konsole formuliert Geltungsbereiche deshalb als "wer diesen Namen auflöst" und nicht als "wer diesen Dienst erreicht"; Letzteres steht an der Veröffentlichung.

Das Erklärwerkzeug "warum erreicht dieses Gerät diesen Namen nicht" aus dem Navigationsbereich *Netz & Namen* (KANON 5) wertet die Kette in dieser Reihenfolge aus und nennt die erste Stufe, die verneint: Gerätezustand, Zertifikatsgültigkeit, Netzzone, Sichtzugehörigkeit des Eintrags, Geltungsbereich der Domäne, Zugriffskreis der Veröffentlichung, abgeleitete Firewallregel. Jede Stufe verlinkt auf das Objekt, das sie erzeugt hat (INV-09).

Zur Zuweisung: sie wirkt auf Personen und Dienstkonten, nie auf Geräte (13.1). Ein Gerät erhält Wirkungen über drei Wege, und nur über diese drei: Mitgliedschaft in einer Geltungsbereichsgruppe, Zugehörigkeit zu einer Netzzone und Eigentum einer Person oder Gruppe. Die Trennung hat einen praktischen Nutzen im Rückbau: das Entfernen einer Person aus einer Rechtegruppe zieht Fremdkonten und Postfachrechte zurück, das Entfernen eines Geräts aus einer Geltungsbereichsgruppe zieht DNS-Sichten und Firewallregeln zurück, und keiner der beiden Vorgänge erzeugt unbeabsichtigte Wirkungen im anderen Bereich.

## Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| R-13-01 | Gerät und Person sind getrennte Objekte; die Verbindung ist ausschließlich das Feld `eigentuemer` am Geräteobjekt. Die Löschung einer Person löscht 0 Geräteobjekte. | INV-11, KANON 3 |
| R-13-02 | Ein Gerät ist niemals Subjekt einer Zuweisung. Eine Zuweisung mit einer Gruppe als Subjekt akzeptiert ausschließlich Gruppen der Art "Rechte"; Geltungsbereichsgruppen werden von der API abgelehnt. | KANON 3 |
| R-13-03 | Die Gruppenart bestimmt die zulässige Mitgliederart: Rechtegruppen enthalten Personen und Dienstkonten, Geltungsbereichsgruppen Geräte und Netzzonen, Verteilergruppen Personen und Postfächer. Ein abweichender Mitgliedschaftsantrag wird abgelehnt. | INV-19 |
| R-13-04 | Die Aufnahme eines Geräts kostet genau zwei Entscheidungen: Eigentümer und Geräteklasse. Mandant, Netzzone, Zertifikatsprofil, technischer Name und Konformitätsvorgaben sind abgeleitet und tragen je eine benannte Quelle. | INV-14, INV-15, K-03 |
| R-13-05 | Der private Schlüssel eines Geräts entsteht auf dem Gerät. Es existiert keine API-Operation, die einen privaten Geräteschlüssel entgegennimmt, ausgibt oder erzeugt. | INV-20, R-11-15 |
| R-13-06 | Für Windows, macOS und Mobilgeräte benutzt Atrium den plattformüblichen Verwaltungsweg des Betriebssystems und liefert dort keinen eigenen Agenten aus. Die tatsächlich abfragbaren Konformitätsmerkmale je Plattform sind je Klasse in der Konsole aufgeführt; ein nicht abfragbares Merkmal wird als "nicht erhebbar" geführt, nie als erfüllt. | INV-18 |
| R-13-07 | Der Aufnahmecode besteht aus 10 Zufallszeichen Crockford-Base32 (50 bit), ist ≤ 24 h gültig, nach 5 Fehlversuchen vernichtet und in der Darstellung `XXXXX-XXXXX-XX` vom Kopplungscode unterscheidbar. Je Mandant sind höchstens 10 Kurzcodes gleichzeitig gültig. | R-11-15, K-14 |
| R-13-08 | Der Aufnahmeendpunkt lässt global höchstens 20 und je Quelladresse höchstens 10 fehlgeschlagene Versuche je Stunde zu. Der Vergleich des Aufnahmegeheimnisses läuft laufzeitkonstant. | INV-20 |
| R-13-09 | Massenaufnahme (mehr als 10 gleichzeitig offene Aufnahmen) verwendet ausschließlich 16-stellige Codes (80 bit), die nicht zur Eingabe von Hand angezeigt werden. | K-14 |
| R-13-10 | Aufnahmecode und Aufnahmelink tragen den Fingerabdruck der Ausgabe-CA; das aufnehmende Programm prüft die Kette gegen diesen Wert, bevor es das Aufnahmegeheimnis sendet. | KANON 4.4a |
| R-13-11 | Jedes Geräteobjekt führt `hardwarebindung` mit genau einem der Werte `bescheinigt`, `behauptet`, `keine`. Der Wert `bescheinigt` wird nur bei erfolgreich geprüfter Bescheinigung gesetzt. | INV-18 |
| R-13-12 | Der RADIUS-Dienst prüft ausschließlich Gerätezertifikate gegen die interne Ausgabe-CA und die verteilte Positivliste, mit Hard-Fail gegen den Listeninhalt. Kennwortbasierte EAP-Verfahren sind nicht konfigurierbar. | KANON 4.9b, R-11-18 |
| R-13-13 | Die Netzzone eines Geräts folgt deterministisch aus Gerätezustand, Richtlinie und Geltungsbereichsgruppen; bei Gleichstand gewinnt die restriktivere Zone, und die entscheidende Regel wird angezeigt. Eine Zonenzuordnung von Hand am Netzgerät existiert nicht. | INV-02, INV-09 |
| R-13-14 | Jede Ausnahme vom 802.1X-Zugang (Zulassung anhand der Hardwareadresse, statischer Port) ist ein eigenes Objekt mit Begründung, Antragsteller und Befristung, wird im Überblick gezählt und ist auf Zonen ohne Zugriff auf Verwaltung, Speicher und Personendaten beschränkt. | INV-18, INV-10 |
| R-13-15 | Das Gastnetz enthält 0 Geräteobjekte, liefert 0 interne Resolversichten aus und erlaubt 0 Verbindungen zwischen Gastgeräten. | INV-10 |
| R-13-16 | Die Konsole zeigt je Netzgerät, ob es Sitzungsrücksetzung beherrscht, und leitet daraus am Geräteobjekt das tatsächliche Sperrfenster ab. Das Reauthentisierungsintervall beträgt 1 h in Zonen mit erhöhtem Schutzbedarf, sonst 8 h. | INV-18, R-11-20 |
| R-13-17 | Die Konsole bietet keinen Download des Wurzelzertifikats mit Einbauanleitung an; der Vertrauensanker gelangt ausschließlich über die Geräteverwaltung in einen Speicher. | R-11-21 |
| R-13-18 | Der Agent meldet je Gerät die Zahl gefundener und die Zahl gepflegter Vertrauensspeicher; die Konsole zeigt beide Zahlen statt eines binären Zustands. | INV-12, INV-18 |
| R-13-19 | Konformitätsmeldungen liegen ausschließlich im Istzustand, werden nur verdichtet repliziert (≤ 1 KB je Gerät) und tragen je Anzeige einen Beobachtungszeitpunkt. | INV-28, K-12 |
| R-13-20 | Ein Gerät ohne Meldung führt den Zustand "unbekannt"; "unbekannt" erfüllt 0 Richtlinien, die Konformität verlangen. | INV-18 |
| R-13-21 | Nichterfüllung führt nach einer Frist von 7 d zum Wechsel in die Nachbesserungszone, nie zum vollständigen Netzausschluss. Der Rückwechsel nach Behebung erfolgt beim nächsten Meldelauf ohne Bedienereingriff. | INV-10, INV-12 |
| R-13-22 | Die Nachbesserungszone erlaubt genau vier Ziele: Aktualisierungsquellen, Namensauflösung, Zeitquelle, Nachbesserungsseite der Konsole. Alles Übrige ist Default-Deny. | INV-10 |
| R-13-23 | Konsole und Berichte bezeichnen Konformitätsergebnisse als "gemeldeten Zustand", nicht als "geprüften Zustand". | INV-16 |
| R-13-24 | Das Datenmodell und die API kennen für Geräte keine Felder für Standort, aufgerufene Namen, Dateinamen, Dateiinhalte, Bildschirminhalte oder Tastatureingaben. Kein Richtlinienwert schaltet eine solche Erhebung ein. | DSGVO |
| R-13-25 | Für Geräte im Profil "privat mit Arbeitsbereich" erfasst das Inventar ausschließlich Merkmale des Arbeitsbereichs und den Aktualisierungsstand des Betriebssystems; Fernlöschung wirkt ausschließlich auf den Arbeitsbereich. | DSGVO |
| R-13-26 | Sperren und Richtlinie erneuern sind freigabefrei; Arbeitsbereich löschen und Gerät vollständig löschen erfordern eine Vier-Augen-Freigabe mit Einzelaufstellung je betroffenem Gerät. Vollständige Löschung setzt den Zustand "verloren/gesperrt" voraus. | INV-11 |
| R-13-27 | Eine beauftragte Fernlöschung wird bis zur Bestätigung des Geräts als "Löschung ausstehend seit <Zeit>" geführt; der Vorgang erreicht 0-mal den Zustand "abgeschlossen" ohne Bestätigung. | INV-12, INV-18 |
| R-13-28 | Die Wirkungsvorschau einer Ausmusterung nennt, ob das Gerät der letzte plattformgebundene Authentikator seines Eigentümers ist. | INV-08 |
| R-13-29 | Der Ausmusterungsnachweis ist ein signierter Auszug aus dem Auditstrom und führt nicht ausgeführte Schritte einzeln auf. Eine Vernichtungsbestätigung wird als menschliche Erklärung mit Person und Datum gekennzeichnet, nie als Systemtatsache. | INV-23, INV-12 |
| R-13-30 | Bei einer Domäne mit Geräte- oder Gruppengeltung nennt die Konsole die Zahl der Geräte, für die der Geltungsbereich genau durchsetzbar ist, und die Zahl derer, für die er über die Netzzone hinaus sichtbar wird. | KANON 4.8c |
| R-13-31 | Das Erklärwerkzeug für Namensauflösung wertet Gerätezustand, Zertifikatsgültigkeit, Netzzone, Sichtzugehörigkeit, Domänengeltungsbereich, Zugriffskreis und Firewallregel in dieser Reihenfolge aus und verlinkt die erste verneinende Stufe auf ihr Quellobjekt. | INV-09, K-26 |
| R-13-32 | Der Agent für Linux führt keinen dynamisch nachgeladenen Code aus, ruft keine Schale mit interpolierten Werten auf, validiert jede empfangene Nachricht gegen ein Schema am Rand und hat kein Recht, Pakete zu installieren. | KANON 4 Sprachen |

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Die Löschung einer Person mit 3 Geräten erzeugt 0 gelöschte Geräteobjekte und 3 Einzelentscheidungen im Vorgang | R-13-01 | Ausscheidevorgang mit bestücktem Gerätebestand |
| Eine Zuweisung mit einer Geltungsbereichsgruppe als Subjekt wird von der API abgelehnt; ein Gerät in einer Rechtegruppe wird beim Mitgliedschaftsantrag abgelehnt | R-13-02, R-13-03 | Mutationstest über alle drei Gruppenarten |
| Das Formular "Gerät aufnehmen" enthält genau 2 Pflichtfelder; jedes vorbelegte Feld nennt seine Quelle | R-13-04 | Abgleich der Formulardefinition gegen die Aufgabendefinition im Bau (INV-14) |
| Die Endpunktliste enthält 0 Operationen, die einen privaten Geräteschlüssel entgegennehmen oder ausgeben | R-13-05 | Fassadenbau gegen die öffentliche API (INV-01) |
| Für jede Geräteklasse ist jedes Konformitätsmerkmal entweder erhebbar oder als "nicht erhebbar" geführt; die Zahl stillschweigend als erfüllt gewerteter Merkmale ist 0 | R-13-06, R-13-20 | Matrixprüfung Klasse × Merkmal im Bau |
| Ein zum zweiten Mal verwendeter Aufnahmecode erzeugt 0 Zertifikate; der 11. gleichzeitig offene Kurzcode je Mandant wird abgelehnt | R-13-07 | Wiederverwendungs- und Mengentest |
| 200 fehlgeschlagene Aufnahmeversuche in einer Stunde führen zu 20 gezählten Versuchen und 180 Ablehnungen ohne Prüfung; die Antwortzeit ist unabhängig vom Grad der Übereinstimmung | R-13-08 | Ratentest mit Zeitmessung über 10.000 Läufe |
| Ein Aufnahmeversuch über eine Verbindung mit abweichendem CA-Fingerabdruck sendet 0 Bytes des Aufnahmegeheimnisses | R-13-10 | Angriffssimulation mit vorgeschalteter Gegenstelle |
| Ein Gerät ohne prüfbare Bescheinigung erhält `behauptet` und erfüllt 0 Richtlinien, die `bescheinigt` verlangen | R-13-11 | Aufnahme mit und ohne Bescheinigung |
| Ein Authentisierungsversuch mit einem kennwortbasierten EAP-Verfahren wird abgelehnt; ein Zertifikat außerhalb der Positivliste wird abgelehnt | R-13-12 | Protokolltest gegen den RADIUS-Dienst |
| Zwei konkurrierende Zonenregeln führen zur restriktiveren Zone; die Konsole nennt die entscheidende Regel in 100 % der Fälle | R-13-13 | Gleichstandstest über alle Regelarten |
| Jede Zugangsausnahme erscheint im Überblick; eine Ausnahme ohne Begründung oder Befristung wird beim Anlegen abgelehnt | R-13-14 | Anlegetest plus Überblicksprüfung |
| Aus dem Gastnetz sind 0 interne Namen auflösbar und 0 Gastgeräte untereinander erreichbar | R-13-15 | Erreichbarkeitstest aus der Gastzone |
| Nach einer Sperrung endet die Sitzung an einem Netzgerät mit Sitzungsrücksetzung p95 ≤ 10 s; an einem ohne spätestens nach dem angezeigten Intervall | R-13-16 | Sperrtest an beiden Netzgeräteklassen mit Verkehrsmessung |
| Die Konsolentexte enthalten 0 Treffer für einen Download des Wurzelzertifikats mit Einbauanleitung | R-13-17 | Musterprüfung aller Oberflächentexte |
| Auf einem Gerät mit einem nicht beschreibbaren Anwendungsspeicher zeigt die Konsole "n von m" mit n < m statt eines Erfolgszustands | R-13-18 | Aufnahme auf einem Testgerät mit zwei Speichern |
| Der replizierte Anteil der Konformitätsdaten bleibt bei 800 Geräten unter 1 MB; jede Konformitätsansicht nennt einen Beobachtungszeitpunkt | R-13-19 | Lasttest mit 800 meldenden Geräten und Darstellungsprüfung |
| Ein Gerät, das eine Eigenschaft verletzt, wechselt nach 7 d in die Nachbesserungszone, erreicht dort genau die vier erlaubten Ziele und kehrt nach Behebung ohne Bedienereingriff zurück | R-13-21, R-13-22 | Zeitrafferlauf mit injizierter Nichterfüllung |
| Konsolentexte und Berichte enthalten 0 Treffer für "geprüfter Zustand" im Konformitätskontext | R-13-23 | Musterprüfung im Bau (INV-16) |
| Das API-Schema enthält 0 Felder aus der Ausschlussliste; kein Richtlinienwert erzeugt eine solche Erhebung | R-13-24 | Schemaprüfung plus Richtlinienraumdurchlauf |
| Eine Fernlöschung auf einem Gerät im privaten Profil erreicht 0 Objekte außerhalb des Arbeitsbereichs | R-13-25 | Löschtest auf einem Testgerät mit getrenntem Arbeitsbereich |
| Eine Löschung ohne Vier-Augen-Freigabe erzeugt 0 Wirkungen; eine Massenauswahl von 12 Geräten erzeugt 12 einzeln aufgeführte Wirkungen | R-13-26 | Freigabetest und Massenaktionstest |
| Eine beauftragte Fernlöschung auf einem dauerhaft offline gehaltenen Gerät erreicht über 30 d 0-mal den Zustand "abgeschlossen" und zeigt durchgehend die Ausstandsdauer | R-13-27 | Offline-Injektionstest über 30 d |
| Die Wirkungsvorschau der Ausmusterung eines Einzelgeräts nennt den Authentikatorverlust des Eigentümers | R-13-28 | Vorschautest mit einer Person mit genau einem verwalteten Gerät |
| Ein Ausmusterungsnachweis ohne Löschbestätigung enthält den Eintrag "Löschung nicht bestätigt" und gilt als unvollständig | R-13-29 | Nachweiserzeugung nach abgebrochener Löschung |
| Bei einer Domäne mit einer Gerätegruppe von 12 Geräten in 3 Zonen nennt die Konsole 12 und 212 | R-13-30 | Aufbau der Beispielkonstellation aus 13.11 |
| Für 20 konstruierte Fehlerfälle nennt das Erklärwerkzeug in 20 Fällen die erste verneinende Stufe und verlinkt ihr Quellobjekt | R-13-31 | Fehlerfallkatalog gegen das Erklärwerkzeug |
| Der Agent enthält 0 Aufrufe einer Schale und 0 dynamische Codeladung; eine schemawidrige Nachricht erzeugt 0 Zustandsänderungen | R-13-32 | Statische Prüfung plus Fuzzing des Agentenkanals |

## Offene Punkte

1. **Verwaltungstiefe auf fremden Plattformen ist nicht verhandelbar und nicht abschätzbar.** Welche Merkmale der plattformübliche Verwaltungsweg von Windows, macOS und den Mobilplattformen tatsächlich liefert, bestimmt der jeweilige Hersteller und ändert es ohne Zutun von Atrium. R-13-06 verlangt deshalb eine Matrix "Klasse × Merkmal", aber der Inhalt dieser Matrix ist zum Entwurfszeitpunkt nicht bekannt und nicht erfindbar. Ob die Konformitätszusagen des Kapitels auf allen vier Plattformfamilien gleichwertig einlösbar sind, ist offen und erst nach einer Erhebung an realer Hardware entscheidbar.
2. **Dienste auf Servern ohne Atrium sind nicht veröffentlichbar.** Eine Veröffentlichung verweist nach KANON 3 auf einen Dienst, und ein Dienst ist eine laufende Instanz eines Katalogeintrags. Ein Webdienst auf einem Fremdserver, den das Geräteobjekt abbildet, hat kein solches Dienstobjekt und kann damit weder einen Namen noch ein Zertifikat noch eine Proxyroute erhalten. Das ist eine häufige praktische Anforderung. Ob das Objektmodell einen "externen Dienst" als Veröffentlichungsziel erhält — mit der Folge, dass der Eingang auf ein System zeigt, dessen Zustand Atrium nicht kennt — ist in [Kapitel 15](15-dienste-software.md) zu entscheiden und hier bewusst nicht vorweggenommen.
3. **Die Zonengranularität der DNS-Sicht bleibt ein halbes Versprechen.** Der Rechenweg in 13.11 zeigt eine Überreichweite, die erst mit verschlüsselter Namensauflösung und Gerätezertifikat verschwindet. Ob die Konsole einen Geltungsbereich feiner als die Netzzone überhaupt anbieten soll, solange nicht alle betroffenen Geräte den verschlüsselten Weg benutzen, oder ob sie ihn mit dauerhafter Kennzeichnung anbietet, ist nicht entschieden. Eine Verweigerung wäre ehrlicher, eine Kennzeichnung nützlicher.
4. **Ein Gerät kann nur in einer Netzzone sein.** Das Objektmodell erlaubt genau eine Zone je Gerät. Geräte mit mehreren Netzschnittstellen — Arbeitsplatzrechner mit Kabel und Funk, Server mit getrenntem Speichernetz — sind damit nicht abbildbar, ohne für jede Schnittstelle ein eigenes Geräteobjekt anzulegen, was das Inventar verdoppelt und die Eigentümerzuordnung verwässert. Ob das Modell eine Schnittstellenebene unterhalb des Geräts erhält, ist offen; sie würde die Zahl der Objekte und die Zahl der abgeleiteten Regeln erhöhen.
5. **Die Vertrauenswürdigkeit der Konformitätsmeldung ist strukturell ungelöst.** 13.7 stellt fest, dass ein kompromittiertes Gerät sich als konform meldet, und der Entwurf akzeptiert das. Ob eine Bescheinigung der Startkette als zusätzliches Merkmal für einzelne Zonen verpflichtend wird — mit der Folge, dass Geräte ohne entsprechende Hardware dauerhaft ausgeschlossen sind —, ist eine Richtlinienentscheidung, für die das Kapitel keine Vorgabe trifft. Ohne sie ist R-13-21 eine Maßnahme gegen Nachlässigkeit und gegen nichts sonst.
6. **Der Widerstand des Aufnahmecodes liegt um den Faktor 7,8 über dem des Kopplungscodes.** Die Rechnung in 13.3 legt das offen. Ob die 24-Stunden-Gültigkeit auf 4 h gesenkt wird (Faktor 6 Gewinn, aber eine deutlich unbequemere Aufnahme), ob das globale Versuchsbudget auf 5 je Stunde sinkt (Faktor 4 Gewinn, aber Ausfallgefahr bei Masseneinführung) oder ob der Kurzcode ganz entfällt und jede Aufnahme über einen 80-Bit-Link läuft, ist nicht entschieden. Die dritte Variante ist technisch die beste und bedienseitig die schlechteste, weil sie einen zustellbaren Kanal zur Voraussetzung jeder Geräteaufnahme macht.
7. **Netzgerätezugriff ist herstellerspezifisch und damit die schwächste Stelle der Ableitungskette.** Atrium schreibt RADIUS-Adresse, gemeinsames Geheimnis und Zonenabbildung über eine Konnektorbindung in Schalter und Funkzugangspunkte. Welche Geräteklassen dafür ein maschinell ansprechbares, versioniertes Konfigurationsmodell bieten und welche nur eine Bedienoberfläche, ist nicht erhoben. Für Letztere gibt es keinen Weg, der INV-02 einhält, und die Folge wäre eine handgepflegte Konfiguration außerhalb des Sollzustands — genau das, was die Architektur ausschließt. Ob solche Geräte als "nicht unterstützt" geführt werden, ist zu entscheiden.
8. **Die Vernichtungsbestätigung ist eine Behauptung im Nachweis.** 13.10 kennzeichnet sie als menschliche Erklärung. Für Prüfungen nach ISO/IEC 27001:2022 oder für Nachweise gegenüber einem Auftraggeber kann das zu wenig sein. Ob eine Anbindung an einen Vernichtungsdienstleister mit eigenem Nachweis vorgesehen wird — als Konnektorbindung mit allen Folgen für Feldeigentum und Kostenwirkung — ist nicht untersucht.
9. **Der Wurzelwechsel nach 15 Jahren ist mit der Geräteverwaltung nicht abgestimmt.** [Kapitel 11](11-pki.md) verteilt den Nachfolger 3 Jahre vor Ablauf parallel an verwaltete Geräte. Dieses Kapitel legt nicht fest, wie ein Gerät behandelt wird, das den neuen Anker nicht innerhalb der 3 Jahre erhält, obwohl es im Inventar als aktiv geführt wird: ob es in die Nachbesserungszone wechselt, ob es gesperrt wird oder ob die Konsole lediglich eine Zahl anzeigt. Ohne Festlegung bricht das Vertrauen zu einem bekannten Zeitpunkt ohne benannte Folge.
10. **Geteilte Geräte schwächen die Tokenbindung.** Ein Gerät mit einer Gruppe als Eigentümer trägt ein Gerätezertifikat, das keine Person identifiziert. Die in [Kapitel 10](10-identitaet.md) beschriebene Bindung eines Zugriffstokens an die Gerätekennung leistet dort weniger. Ob geteilte Geräte generell aus Zonen mit erhöhtem Schutzbedarf ausgeschlossen werden — was Schalterarbeitsplätze und Werkstattrechner trifft — oder ob ein zusätzliches, personengebundenes Merkmal verlangt wird, ist offen.
