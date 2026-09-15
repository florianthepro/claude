# 17 Speicher, Replikation, Sicherung und Wiederherstellung

## 17.1 Lokaler Speicher

### Drei getrennte Bereiche auf jedem Knoten

Ein Knoten trägt drei Datenarten mit unterschiedlicher Herkunft, unterschiedlicher Wiederbeschaffbarkeit und deshalb unterschiedlicher Sicherungspflicht. Die Trennung ist die Voraussetzung dafür, dass eine Sicherung klein bleibt und eine Wiederherstellung nicht das Falsche zurückschreibt.

| Bereich | Träger | Wiederbeschaffbar aus | Wird gesichert | Begründung |
|---|---|---|---|---|
| Basisabbild | zwei Partitionen A/B, unveränderlich, dm-verity, signiert, je ≤ 8 GB (K-19) | dem reproduzierbaren Bau; identifiziert durch Version und Hashwert | nein, nur die Versionsangabe und der Hashwert | Ein Abbild, das aus seinem Bau reproduzierbar ist, in eine Sicherung zu kopieren, verdoppelt Datenmenge und Vertrauensanker ohne Ertrag. |
| Systemzustand | `<pool>/atrium/kern/…` und `<pool>/atrium/knoten/…` | Sollzustand: aus dem signierten Export. Istzustand: aus erneuter Beobachtung. Geheimnisse: gar nicht, sie sind TPM-gebunden (INV-20) | nur der Sollzustandsexport und der Auditstrom | Der Istzustand ist eine Beobachtung mit Beobachtungszeitpunkt (INV-28) und damit als Sicherungsgegenstand wertlos; der Sollzustand ist die einzige Wahrheitsquelle (INV-02). |
| Dienstdaten | `<pool>/atrium/<mandant-kurz>/<dienst-kurz>/<speicherbereich-kurz>` | nichts | ja, vollständig | Dies ist der einzige Bestand, der nirgendwo sonst existiert. |

Die Namensregel für Dienstdaten steht in KANON 3. Die beiden anderen Teilbäume folgen demselben Präfix, damit ein Pool genau einen von Atrium verwalteten Unterbaum hat und alles außerhalb davon nicht Atrium gehört.

### Eigenschaften je Teilbaum

| Teilbaum | Prüfsumme | Kompression | Verschlüsselung | Momentaufnahmen | Kontingent |
|---|---|---|---|---|---|
| `kern/protokoll` | kryptographisch | ja, schnell | ja, Installationsschlüssel | vor jeder Schemamigration (INV-24) | fest, klein (K-12: ≤ 50 MB Sollzustand) |
| `kern/lesemodell` | nicht kryptographisch | ja, schnell | ja | keine; das Lesemodell wird deterministisch neu gebaut | fest |
| `kern/audit` | kryptographisch | ja, stark | ja | täglich bis zur Auslagerung | fest, mit Auslagerungsschwelle |
| `knoten/abbilder` | nicht kryptographisch | aus, Abbildschichten sind bereits komprimiert | nein | keine | fest, Verdrängung nach Alter |
| `knoten/telemetrie` | nicht kryptographisch | ja, stark | nein | keine | fest, Ringverhalten |
| `<mandant>/<dienst>/<bereich>` | kryptographisch | ja, schnell | ja, Datenschlüssel je Speicherbereich (R-15-23) | nach Leiter aus 17.4 | `refquota` und `quota`, siehe unten |

Die Unterscheidung zwischen kryptographischer und nicht kryptographischer Prüfsumme ist keine Kosmetik. Eine schnelle, nicht kryptographische Prüfsumme erkennt Übertragungs-, Speicher- und Firmwarefehler zuverlässig und ist für Daten ausreichend, deren Hashwert nirgends als Identität benutzt wird. Überall dort, wo ein Hashwert als Identität dient — Auditkette, Wiederherstellungspunkt, Abgleich zweier Kopien über eine Strecke —, muss die Prüfsumme kollisionsresistent gegen einen Angreifer sein, weil sonst zwei verschiedene Inhalte denselben Nachweis tragen können. Für diese Fälle gilt SHA-2 nach FIPS 180-4 oder ein Verfahren derselben Klasse.

Die Prüfsumme wird bei jedem Lesevorgang geprüft, nicht nur beim Prüflauf. Existiert im Pool eine Redundanz, wird ein als fehlerhaft erkannter Block aus der Redundanz repariert; existiert keine, meldet das Dateisystem den betroffenen Pfad als beschädigt. Der wesentliche Unterschied zu einer Anordnung ohne Blockprüfsummen ist nicht, dass weniger Fehler auftreten, sondern dass ein Fehler benannt und lokalisiert statt stillschweigend ausgeliefert wird.

Kompression läuft vor der Verschlüsselung. Das ist die einzig mögliche Reihenfolge, weil verschlüsselte Daten nicht komprimierbar sind, und sie hat eine Nebenwirkung, die genannt gehört: Das Kompressionsverhältnis eines verschlüsselten Datenbestandes ist von außen beobachtbar und verrät grob die Art der Daten. Diese Preisgabe wird in Kauf genommen, weil das Kompressionsverhältnis gleichzeitig das wirksamste Erkennungssignal gegen einen Verschlüsselungsangriff ist (17.8).

### Kontingente

Ein Speicherbereich trägt zwei Grenzen. `refquota` begrenzt die Nutzdaten und ist die Größe, die der Katalogeintrag deklariert und die der Dienst sieht. `quota` begrenzt Nutzdaten und Momentaufnahmen zusammen und ist die Größe, die der Pool belegt. Würde nur eine Grenze gesetzt, hätte das eine von zwei Folgen: Bei nur `quota` verliert der Dienst ohne eigenes Zutun Schreibfähigkeit, sobald die Momentaufnahmen wachsen; bei nur `refquota` kann eine einzelne volatile Anwendung den Pool über ihre Momentaufnahmen füllen.

Der Aufschlag wird gerechnet, nicht geraten. Mit dem Belegungsmodell aus 17.4 gilt für einen Speicherbereich der Nettogröße S, einer täglichen Änderungsrate c und einem lokalen Aufbewahrungshorizont T:

```
quota = refquota · ( 1 + (1 − (1 − c)^T) )
```

Beispiel mit den Annahmen S = 200 GB, c = 2 % je Tag, T = 56 Tage:

```
(1 − 0,02)^56 = e^(56 · ln 0,98) = e^(−1,13135) = 0,32262
Aufschlagsanteil = 1 − 0,32262 = 0,67738
quota = 200 GB · 1,67738 = 335,5 GB
```

Deutung: Die lokale Aufbewahrungsleiter kostet bei diesen Annahmen zwei Drittel der Nutzdatenmenge zusätzlich. Der Wert wird täglich aus dem beobachteten c neu berechnet; die verworfene Alternative ist ein fester Faktor, der bei statischen Daten Kapazität verschenkt, bei volatilen Daten nicht reicht und in beiden Fällen den Zusammenhang zwischen Aufbewahrungswunsch und Kapazitätsbedarf vor dem Bediener verbirgt.

### Kapazitätsausbeute eines Datenträgers

Drei Faktoren wirken zusammen. Der Pool-Füllgrad bleibt bei ≤ 80 %, weil der Allokator oberhalb davon keine zusammenhängenden freien Bereiche mehr findet, die Fragmentierung steigt und die Schreiblatenz mit ihr; das ist eine Festlegung, keine Messung. Die Kompression liefert bei gemischten Dienstdaten einen angenommenen Faktor 1,6. Der Momentaufnahmenaufschlag beträgt nach der Rechnung oben 1,67738.

```
Nettodaten je TB Rohkapazitaet
  = 1.000 GB · 0,80 · 1,6 / 1,67738
  = 1.280 GB / 1,67738
  = 763 GB
```

Deutung: Ein Rohterabyte trägt unter diesen Annahmen rund 763 GB Nutzdaten. Das ist ein Modell, keine Messung; jede der drei Annahmen verschiebt das Ergebnis linear. Die Konsole zeigt den Wert nicht als Formel, sondern als verbleibende Kapazität mit der Angabe, welcher Anteil auf Aufbewahrung entfällt.

### Prüflauf

Ein vollständiger Prüflauf über einen Pool liest alle belegten Blöcke und prüft sie gegen ihre Prüfsummen. Seine Dauer folgt derselben Formel wie der Wiederaufbau in 17.9 und ist damit keine eigene Annahme. Zielwert: ein vollständiger Prüflauf je Pool und Monat, mit Vorrang hinter der Produktionslast, abgebrochen und fortgesetzt statt verworfen. Ein Pool ohne abgeschlossenen Prüflauf innerhalb von 45 Tagen ist ein benannter degradierter Zustand (INV-18).

## 17.2 Speicherklassen

### Die drei Klassen im Vergleich

Der Bediener wählt ausschließlich die Datensicherheitsstufe (KANON 4, Entscheidung 3b). Die folgende Tabelle ist die vollständige Ableitung dahinter.

| Eigenschaft | Lokal | Gespiegelt | Synchron gespiegelt |
|---|---|---|---|
| Verfahren | Momentaufnahmen im lokalen Pool plus verschlüsselte externe Auslagerung | asynchrones ZFS send/recv auf einen zweiten Knoten, zusätzlich externe Auslagerung | DRBD/LINSTOR über das Overlay, 3 Replikate oder 2 Replikate plus Zeuge, zusätzlich Momentaufnahmen und externe Auslagerung |
| Verfügbar ab | 1 Knoten | 2 Knoten | 3 Stimmen (3 Knoten oder 2 Knoten plus Zeuge), KANON 4 Entscheidung 3a |
| RPO | ≤ 24 h, wählbar bis 1 h (K-10) | ≤ 15 min (K-09) | 0 (K-08) |
| RTO | ≤ 4 h (K-10) | ≤ 30 min (K-09), Einschränkung siehe unten | ≤ 5 min (K-08) |
| Leistungskosten im Schreibpfad | keine; die Momentaufnahme ist eine Metadatenoperation | keine im Schreibpfad; je Intervall eine Lese- und Netzlast | jede bestätigte Schreiboperation wartet auf ein Replikat, Rechnung unten |
| Kapazitätskosten | 1 · S zuzüglich Aufbewahrungsaufschlag | 2 · S zuzüglich Aufschlag je Kopie | 3 · S bzw. 2 · S bei 2 Replikaten plus Zeuge, jeweils zuzüglich Aufschlag |
| Ausfallverhalten Knoten | Dienst steht bis zur Wiederherstellung; Verlust bis zum letzten ausgelagerten Stand | Übernahme möglich, Verlust bis zum letzten übertragenen Stand | Übernahme ohne Datenverlust, sofern die Mehrheit erhalten bleibt |
| Ausfallverhalten Mehrheitsverlust | unverändert, die Klasse kennt keine Mehrheit | Übertragung pausiert, lokale Momentaufnahmen laufen weiter | Speicherbereich wird schreibgesperrt, Dienste lesen weiter (INV-04) |

### Leistungskosten der synchronen Replikation

Eine synchrone Replikation bestätigt eine Schreiboperation erst, wenn mindestens ein weiteres Replikat sie hält. Die Bestätigungszeit ist deshalb nicht die lokale Schreibzeit, sondern die Zeit über das Netz und zurück.

Annahmen: lokale Bestätigungszeit eines kleinen Schreibvorgangs 100 µs; Umlaufzeit im Standortnetz 200 µs; Schreibzeit beim Replikat 100 µs. Die lokale Schreiboperation läuft parallel zur Übertragung.

```
t_sync = max( t_lokal , RTT/2 + t_replikat + RTT/2 )
       = max( 100 µs , 100 + 100 + 100 µs )
       = 300 µs

Serialisierte Schreibvorgaenge je Sekunde und Strang:
  lokal      1 / 100 µs  = 10.000
  synchron   1 / 300 µs  =  3.333      Rueckgang 67 %
```

Dieselbe Rechnung über eine Weitverkehrsstrecke mit 10 ms Umlaufzeit:

```
t_sync = 5.000 + 100 + 5.000 = 10.100 µs = 10,1 ms
Serialisierte Schreibvorgaenge je Sekunde und Strang: 99     Rueckgang 99 %
```

Deutung: Der Aufschlag trifft ausschließlich serialisierte Schreibketten, also Bestätigungspunkte von Datenbanken und Protokolldateien; parallele Lasten verlieren Durchsatz nur, wenn die Strecke selbst sättigt, weil Latenz und Durchsatz verschiedene Größen sind. Der Faktor zwischen Standortnetz und Weitverkehr beträgt 10,1 ms / 0,3 ms = 34. Daraus folgt eine harte Grenze statt einer Empfehlung: Die Klasse "Synchron gespiegelt" wird nur zugelassen, wenn die gemessene Umlaufzeit zwischen allen Replikatträgern ≤ 1 ms beträgt; darüber nennt die Konsole die berechnete Bestätigungszeit und die Klasse ist nicht wählbar. Das ist ein Modell mit angenommenen Eingangswerten, keine Messung; die Umlaufzeit selbst wird gemessen, die drei Zeitanteile sind Annahmen.

### Warum synchrone Replikation eine Mehrheit braucht

Der Grund liegt nicht in der Replikation, sondern in der Nichtvereinigbarkeit divergenter Blockgeschichten. Ein Blockgerät hat keine Verschmelzungssemantik: Zwei Replikate, die denselben Block unterschiedlich beschrieben haben, lassen sich nicht zusammenführen, weil kein Kriterium existiert, das den richtigen Inhalt bestimmt. Eine Datei kann dreiseitig verschmolzen werden, ein Zähler kann konfliktfrei zusammengeführt werden, ein Block nicht.

Daraus folgt die Anforderung: Nach einer Netztrennung darf höchstens eine Seite weiterschreiben. Beide Seiten haben dieselbe Information, nämlich dass die Gegenseite nicht antwortet, und können deshalb aus eigener Kraft nicht unterscheiden, ob sie die überlebende oder die abgeschnittene Seite sind. Ein Kriterium, das lokal auswertbar ist und trotzdem auf höchstens einer Seite zutrifft, ist die Mehrheit über einer festen, im Voraus bekannten Stimmenmenge.

```
Stimmen n   Mehrheit   toleriert Ausfaelle   bei Trennung schreibfaehig
    1           1              0              die eine Seite
    2           2              0              keine Seite
    3           2              1              genau eine Seite
    4           3              1              genau eine Seite
    5           3              2              genau eine Seite
```

Die Stimmenmenge selbst, ihre Änderung im laufenden Betrieb und die Rolle des Zeugen sind in [Kapitel 16](16-cluster.md) beschrieben und werden hier vorausgesetzt.

Zwei Stimmen sind der einzige Fall, der die Anforderung zwar erfüllt, aber ohne jeden Verfügbarkeitsgewinn: Jeder Einzelausfall sperrt die Schreibvorgänge. Vier Stimmen tolerieren nicht mehr Ausfälle als drei und sind rechnerisch schlechter verfügbar (K-04). Deshalb gilt INV-05 und deshalb beginnt die Klasse "Synchron gespiegelt" bei drei Stimmen, nicht bei vier.

Für die Knotenzahl bedeutet das konkret: Eine Installation, die synchrone Replikation anbieten will, braucht drei Stimmen. Der billigste Weg dorthin sind zwei datentragende Knoten und ein Zeuge, der weder Dienst- noch Speicherlast trägt und zugleich als Raft-Stimme und als Replikationszeuge zählt (KANON 4, Entscheidung 1b). Die Kapazitätsfolge unterscheidet die beiden Ausbauten deutlich: Ein Speicherbereich von 200 GB belegt bei drei Replikaten 600 GB Rohkapazität vor Kompression, bei zwei Replikaten plus Zeuge 400 GB. Der Zeuge spart damit ein Drittel der Kapazität und toleriert denselben Einzelausfall; er toleriert jedoch nicht den Ausfall eines datentragenden Knotens während eines Wiederaufbaus, weil dann keine zweite vollständige Kopie mehr existiert.

### Die Einschränkung der Klasse "Gespiegelt" bei zwei Knoten

Eine Installation mit zwei Knoten hat nach INV-05 genau eine Stimme. Fällt der Stimmknoten aus, ist der Sollzustand eingefroren (INV-04): Dienste auf dem verbleibenden Knoten laufen weiter und starten lokal neu (INV-25), aber eine Übernahme der Dienste des ausgefallenen Knotens findet nicht statt, weil eine Übernahme eine Änderung am Sollzustand ist und damit ein Schreibvorgang.

Daraus folgt eine Aussage, die die Konsole treffen muss und die leicht verschwiegen würde: Zwei Knoten ohne Zeugen erzeugen eine Datenkopie, keinen Ausfallschutz. Der RPO von ≤ 15 min nach K-09 gilt unverändert, der RTO von ≤ 30 min gilt nur, wenn der ausgefallene Knoten nicht der Stimmknoten war. War er es, bestimmt die Wiederherstellung der Kontrollebene den RTO (K-11, ≤ 30 min für den Zustand) zuzüglich der Datenrückholung nach 17.5. Die Konsole zeigt an einem Dienst der Klasse "Gespiegelt" in einer Zwei-Knoten-Installation deshalb dauerhaft den Zusatz, dass die Übernahme eine Bedienerhandlung ist.

### Entscheidungsmatrix

| Ausgangslage | Verfügbare Klassen | Vorbelegung | Begründung der Vorbelegung |
|---|---|---|---|
| 1 Knoten | Lokal | Lokal | Es gibt kein zweites Ziel; die Konsole zeigt dauerhaft "Redundanz: keine" (INV-18). |
| 2 Knoten, kein Zeuge | Lokal, Gespiegelt | Gespiegelt | Eine zweite Kopie kostet nur Kapazität und keinen Schreibpfad; die Übernahme bleibt eine Handlung. |
| 2 Knoten plus Zeuge | Lokal, Gespiegelt, Synchron gespiegelt | Gespiegelt | Synchron bleibt die ausdrückliche Wahl, weil sie Schreiblatenz kostet, die kein Dienst pauschal braucht. |
| ≥ 3 Knoten | alle drei | Gespiegelt | wie oben |
| ≥ 3 Knoten, Katalogeintrag deklariert Datenklasse "transaktional" | alle drei | Synchron gespiegelt | Der Katalogeintrag deklariert die Vorgabe; die Quelle der Vorbelegung ist benannt (INV-15). |
| Umlaufzeit zwischen Replikatträgern > 1 ms | Lokal, Gespiegelt | Gespiegelt | Synchron ist nicht wählbar; die Konsole nennt die gemessene Umlaufzeit und die berechnete Bestätigungszeit. |

Ist eine gewählte Klasse mit der aktuellen Knotenzahl nicht erfüllbar, wird sie nicht still abgesenkt, sondern die Platzierung ist unlösbar mit benannter Ursache; die Regel steht in [Kapitel 15](15-dienste-software.md) und gilt hier unverändert. Die verworfene Alternative — stille Absenkung auf die nächstniedrigere Klasse — würde die Oberfläche einen RPO zusagen lassen, den das System nicht einhält, und ist deshalb ausgeschlossen.

## 17.3 Sicherungsarchitektur

### Drei getrennte Artefaktarten

Atrium erzeugt drei Arten von Wiederherstellungspunkten mit getrennten Verfahren, getrennten Formaten und getrennten Ablageorten. Die Trennung ist eine Entscheidung gegen ein einziges vereinheitlichtes Sicherungsverfahren; sie kostet drei Prüfpfade und erbringt, dass kein einzelner Formatfehler und kein einzelner Werkzeugfehler alle drei entwertet.

Die Erzeugung des Exports und seine Beziehung zum Änderungsprotokoll sind in [Kapitel 8](08-kontrollebene.md) beschrieben; dieses Kapitel behandelt nur seine Ablage, Prüfung und Rückführung.

| Artefakt | Inhalt | Werkzeug | Format lesbar ohne Atrium | Ablage |
|---|---|---|---|---|
| Sollzustandsexport | Objektgraph, `schema_version`, Auditkettenabschluss | eigene Serialisierung, kanonisiert nach RFC 8785, signiert nach RFC 8032, Zeitstempel nach RFC 3161 | ja, selbstbeschreibendes Textformat | Auslagerungsziel und, als Kopie, jeder Verwaltungsknoten |
| Speicherbereichsmomentaufnahme, knotennah | Blockstand eines Speicherbereichs | ZFS send/recv, roh übertragen | nein, Dateisystemformat | zweiter Knoten, andere Fehlerzone |
| Ausgelagerter Stand | Inhalt eines Speicherbereichs | restic, dedupliziert, verschlüsselt | ja, mit dem Werkzeug und dem Schlüssel | Auslagerungsziel außerhalb jeder Fehlerzone |

### Ablauffolge je Speicherbereich

```
 1  Haken "vorbereiten"      Frist 10 s   Dienst leert Puffer, setzt Schreibbarriere
 2  Momentaufnahme           <= 1 s       atomar, im lokalen Pool
 3  Haken "freigeben"        Frist  5 s   Dienst nimmt Schreibvorgaenge wieder auf
 4  Uebertragung knotennah                ZFS send roh, nur Zuwachs seit letztem Stand
 5  Uebertragung ausgelagert              restic, dedupliziert, nur neue Bloecke
 6  Pruefsumme und Signatur               Wiederherstellungspunkt entsteht, Zustand "erzeugt"
 7  Eintrag im Sollzustand                Kennung, Zeitpunkt, Pruefsumme, Ablageort, Aufbewahrungsende
```

Die Haken in den Schritten 1 und 3 sind in [Kapitel 15](15-dienste-software.md) spezifiziert und werden hier nicht wiederholt. Für dieses Kapitel ist nur die Folge wesentlich: Fehlt der Haken oder antwortet er nicht, entsteht trotzdem ein Wiederherstellungspunkt, er trägt aber dauerhaft die Kennzeichnung "absturzkonsistent", und diese Kennzeichnung wandert unverändert in jede Kopie und in jeden Prüfbericht. Ein absturzkonsistenter Stand ist nicht wertlos, aber seine Wiederherstellbarkeit hängt vom Anlaufverhalten des Fremdprodukts ab und ist damit nicht zugesichert.

Schritt 4 überträgt roh, also verschlüsselt. Das Zielsystem empfängt und speichert den Stand, ohne den Datenschlüssel zu besitzen. Das ist der Grund, warum ein Replikatziel in einer anderen Fehlerzone — oder ab M3 ein Knoten eines anderen Mandanten — den Bestand halten kann, ohne ihn lesen zu können. Der Preis ist, dass auf dem Zielsystem keine Prüfung des Klartextes und keine Wiederherstellung einzelner Dateien ohne Schlüssel möglich ist.

### Schlüsselführung

```
Mandantenhauptschluessel        je Mandant, TPM-umschlossen auf jedem berechtigten Knoten
   |
   +-- Datenschluessel          je Speicherbereich, umschlossen (R-15-23)
   |       -> verschluesselt den lokalen Bestand und jede rohe Uebertragung
   |
   +-- Auslagerungsschluessel   je Mandant und Auslagerungsziel
           abgeleitet mit HKDF (RFC 5869) aus dem Hauptschluessel,
           Kontextzeichenkette: Mandantenkennung, Zielkennung, Zweck
           -> verschluesselt den ausgelagerten Bestand
```

Der Schlüssel je Mandant und nicht je Installation ist die Bedingung dafür, dass die Auslagerung eines Mandanten ohne Zugriff auf fremde Mandanten wiederherstellbar ist und dass die Vernichtung eines Mandanten durch Schlüssellöschung vollständig ist (INV-19). Der Schlüssel je Speicherbereich ist die Bedingung dafür, dass die Vernichtung eines einzelnen Dienstes möglich ist, ohne den Mandanten zu berühren.

Die konkrete Konstruktion der authentisierten Verschlüsselung im Auslagerungsformat wird von dem dafür festgelegten Werkzeug bestimmt und hier nicht neu definiert. Die Anforderung an sie wird benannt: authentisierte Verschlüsselung mit einem Schlüssel je Mandant und Ziel, Verfahren aus der Klasse AES nach FIPS 197 oder ChaCha20-Poly1305 nach RFC 8439, Integritätsprüfung vor jeder Entschlüsselung, Schlüsselableitung nach RFC 5869. Ein Vergleich von Geheimniswerten im Prüfpfad läuft laufzeitkonstant.

### Warum die Sicherung nie mit denselben Zugangsdaten löschbar sein darf

Der Angreifer, gegen den eine Sicherung schützt, hat in dem Moment, in dem sie gebraucht wird, bereits Zugriff auf den produktiven Knoten. Er hat damit Zugriff auf alles, was dieser Knoten besitzt, einschließlich der Zugangsdaten zum Auslagerungsziel. Sind das dieselben Zugangsdaten, mit denen gelöscht oder überschrieben werden kann, dann vernichtet ein einzelner Befehl auf dem befallenen Knoten Produktivbestand und Sicherung gleichzeitig, und die gesamte Sicherungsarchitektur hat keinen Wert.

Daraus folgt eine Trennung nach Prinzipalen, nicht nach Rollen innerhalb eines Prinzipals:

| Prinzipal | Berechtigung am Auslagerungsziel | Ort des Geheimnisses | Läuft auf |
|---|---|---|---|
| Schreiber | anhängen; Aufbewahrungsdatum verlängern, nie verkürzen | TPM des schreibenden Knotens | produktivem Knoten |
| Prüfer | lesen | eigene Kennung | Prüfumgebung, getrennt vom produktiven Knoten |
| Aufräumer | löschen, ausschließlich für Objekte mit abgelaufenem Aufbewahrungsdatum | eigene Kennung, nie im Sollzustand, nie auf einem produktiven Knoten erreichbar | getrenntem Ausführungsort |

Drei Punkte an dieser Tabelle sind nicht selbstverständlich. Erstens ist Überschreiben eine Löschung: Ein Schreibrecht, das bestehende Objekte ersetzen darf, ist ein Vernichtungsrecht. Deshalb lautet die Anforderung nicht "kein Löschrecht", sondern Schreiben nur einmal je Objektname. Zweitens darf der Schreiber das Aufbewahrungsdatum verlängern, weil genau das die automatische Reaktion auf einen Verdachtsfall ist (17.8); eine Verkürzung ist ihm verwehrt, weil sie eine vorgezogene Löschung wäre. Drittens ist der Aufräumer kein Hintergrundprozess des Systems, sondern ein eigener Prinzipal; wäre er ein Dienst auf einem produktiven Knoten, wäre die Trennung wieder aufgehoben.

Die Unveränderlichkeit selbst wird vom Ablageziel durchgesetzt, nicht vom schreibenden Knoten. Ob ein Ziel sie wirklich durchsetzt, ist eine Zusage dieses Ziels und für Atrium nicht aus dem Vertrag ablesbar. Prüfbar ist sie dagegen durch einen Versuch: Die monatliche Probe legt ein eigens dafür erzeugtes Prüfobjekt mit kurzer, aber noch laufender Aufbewahrungsfrist ab und versucht anschließend, es mit den Zugangsdaten des Schreibers zu löschen und zu überschreiben. Beide Versuche müssen scheitern. Gelingt einer, setzt das Ziel die Unveränderlichkeit nicht durch, und der Schutzstatus jedes Dienstes, dessen Stände dort liegen, sinkt sichtbar. Der Versuch läuft nie gegen einen echten Wiederherstellungspunkt.

### Kopien und die Fehler, die sie überleben

| Kopie | Ort | Überlebt |
|---|---|---|
| Produktivbestand | Knoten, Speicherbereich | nichts; er ist der Gegenstand |
| lokale Momentaufnahme | derselbe Pool | Bedienfehler, fehlerhafte Aktualisierung, Anwendungsfehler |
| knotennahes Replikat | anderer Knoten, andere Fehlerzone | Ausfall eines Knotens, eines Racks, eines Stromkreises |
| ausgelagerter Stand | außerhalb jeder Fehlerzone, eigene Administrationsdomäne | Verlust des Standortes, Kompromittierung der Installation |
| Offline-Stand | trennbares Medium ohne Netzpfad aus der Produktion | Kompromittierung jedes netzerreichbaren Zieles |

"Zweiter Ort" ist keine Ortsangabe, sondern eine Eigenschaftsangabe: eigene Fehlerzone, eigene Stromversorgung, eigene Administrationsdomäne. Die dritte Eigenschaft ist die, die am häufigsten fehlt. Ein zweiter Standort, der mit denselben Zugangsdaten und aus derselben Verwaltungsoberfläche bedient wird, ist gegen einen Angreifer kein zweiter Ort, sondern ein zweites Ziel desselben Angriffs.

## 17.4 Aufbewahrung

### Generationenschema

| Stufe | Abstand | Anzahl | Abgedeckter Zeitraum | Gehalten |
|---|---|---|---|---|
| Kurzstufe | 15 min | 16 | 4 h | lokal |
| Stundenstufe | 1 h | 48 | 2 d | lokal |
| Tagesstufe | 1 d | 14 | 14 d | lokal, Replikat, Auslagerung |
| Wochenstufe | 7 d | 8 | Tag 21 bis 70 | lokal, Replikat, Auslagerung |
| Monatsstufe | 30 d | 12 | Tag 100 bis 430 | Auslagerung, davon 12 zusätzlich offline |
| Jahresstufe | 365 d | 0 bis 10, Vorgabe 0 | nach Vorgabe | Auslagerung, offline |

Der lokale Horizont beträgt damit 70 Tage, der ausgelagerte 430 Tage. Die Stufen sind für die Kapazitätsrechnung überschneidungsfrei definiert; überschneiden sich zwei Stufen, zählt der Stand nur einmal, was die Rechnung unterhalb des berechneten Wertes hält.

Die Leiter ist eine Richtlinie mit benannter Quelle (INV-15), keine Einstellung je Dienst. Der Bediener wählt je Mandant eine von wenigen benannten Vorgaben; jede Änderung der Vorgabe ist ein Vorgang, dessen Wirkungsvorschau den nach der Formel unten berechneten Kapazitätsunterschied nennt, bevor er bestätigt wird (INV-08).

### Formel für den Kapazitätsbedarf

Ein ausgelagertes Ablageziel dedupliziert; es speichert jeden Block einmal, unabhängig davon, in wie vielen Ständen er vorkommt. Der Bedarf ist deshalb die Mächtigkeit der Vereinigung aller Blöcke, die zu irgendeinem aufbewahrten Zeitpunkt lebten, nicht die Summe der Stände.

Annahme für die Modellbildung: Die je Tag geänderten Blöcke sind gleichverteilt über den Bestand gewählt. Dann ist der Anteil der Blöcke, die über Δ Tage mindestens einmal geändert wurden, gleich 1 − (1 − c)^Δ.

```
R  =  S · [ 1 + SUMME ueber alle Abstaende Delta_i der Leiter von
                ( 1 − (1 − c)^Delta_i ) ]

S        Nettodatenbestand
c        taegliche Aenderungsrate als Anteil von S
Delta_i  Abstand zwischen zwei aufeinanderfolgenden aufbewahrten Staenden
R        Bedarf vor Kompression
```

Die Annahme der Gleichverteilung ist in beide Richtungen falsch, und das gehört benannt. Reale Lasten ändern dieselben Blöcke wiederholt; dadurch wächst die Vereinigung langsamer und der echte Bedarf liegt unter R. Anhängende Lasten — Protokolle, Mailarchive, Belegablagen — wachsen, statt zu ändern; dort ist S nicht konstant und der echte Bedarf liegt über R. Die Konsole rechnet deshalb nicht mit der Formel, sondern schreibt die beobachtete Reihe der Zuwächse je Speicherbereich fort; die Formel dient der Auslegung vor der ersten Messung.

### Beispielrechnung

Annahmen: Gesamtnettodatenbestand aller Speicherbereiche der Installation S = 2.000 GB; tägliche Änderungsrate c = 2 %; Kompressionsfaktor 1,6; Leiter wie oben, also 13 Abstände zu 1 d, 8 Abstände zu 7 d und 12 Abstände zu 30 d.

```
Abstand  1 d :  1 − 0,98^1  = 0,02000
Abstand  7 d :  1 − 0,98^7  = 1 − 0,868128 = 0,131872
Abstand 30 d :  1 − 0,98^30 = 1 − 0,545505 = 0,454495

R / S = 1 + 13 · 0,02000 + 8 · 0,131872 + 12 · 0,454495
      = 1 + 0,26000    + 1,054976     + 5,453940
      = 7,768916

R           = 2.000 GB · 7,768916 = 15.537,8 GB
R nach Kompression = 15.537,8 / 1,6 = 9.711,1 GB
```

Deutung: Das Auslagerungsziel braucht bei diesen Annahmen rund das Fünffache des Produktivbestandes. Der Bedarf ist fast vollständig von der Monatsstufe bestimmt: Sie trägt 5,454 von 7,769, also 70,2 %, die Tagesstufe dagegen 0,260, also 3,3 %. Der Grund ist der Abstand, nicht die Anzahl: Bei c = 2 % teilen zwei Stände im Abstand von 30 Tagen nur noch 54,6 % ihrer Blöcke.

Daraus folgt eine belastbare Stellschraube. Eine Verkürzung der Monatsstufe von 12 auf 3 Stände spart 9 · 0,454495 · 2.000 GB = 8.180,9 GB, also 52,7 % des Gesamtbedarfs, und verkürzt den ausgelagerten Horizont von 430 auf 160 Tage. Eine Verlängerung der Wochenstufe zulasten der Monatsstufe wirkt umgekehrt: Ersetzt man drei Monatsstände durch zwölf Wochenstände bei gleichem abgedecktem Zeitraum, steigt der Bedarf um (12 · 0,131872 − 3 · 0,454495) · 2.000 GB = (1,582464 − 1,363485) · 2.000 GB = 437,96 GB, also um 2,8 %, und der Datenverlust nach einer spät erkannten Beschädigung sinkt in diesem Zeitraum von bis zu 30 Tagen auf bis zu 7 Tage. Diese zweite Rechnung ist die Grundlage der Aufbewahrungsentscheidung in 17.8.

Für den lokalen Pool gilt dieselbe Formel mit dem lokalen Horizont. Mit 13 Abständen zu 1 d, 8 zu 7 d und den 64 Abständen der Kurz- und Stundenstufe zu 15 min und 1 h ist der Zuwachs der beiden feinen Stufen vernachlässigbar: 64 Abstände zu durchschnittlich 0,66 h ergeben zusammen weniger als 0,04 · S. Der lokale Bedarf wird deshalb hinreichend genau durch den Aufschlag aus 17.1 beschrieben.

## 17.5 Wiederherstellungszeit

### Formel

```
RTO = D / v + t_ruest

D        zurueckzuholende Datenmenge
v        Nettodurchsatz der Rueckholstrecke
t_ruest  Ruestzeit: Entscheidung, Bereitstellung des Ziels, Schluesselbereitstellung,
         Pruefung von Signatur und Pruefsumme, Dienststart, Bereitschaftsprobe
```

Die Rüstzeit ist von D unabhängig und dominiert bei kleinen Beständen; der Übertragungsterm dominiert bei großen. Die Trennung ist nützlich, weil nur der zweite Term durch Bandbreite und nur der erste durch Automatisierung sinkt.

### Beispiel 1: ein einzelner Dienst

Annahmen: Speicherbereich D = 200 GB netto; Rüstzeit t_rüst = 10 min, zusammengesetzt aus Auswahl und Entscheidung 5 min, Dienst anhalten 1 min, Schlüsselbereitstellung 0,5 min, Prüfung von Signatur und Prüfsumme 0,5 min, Dienststart und Bereitschaftsprobe 3 min. Entschlüsselung und Prüfsummenprüfung begrenzen den Durchsatz annahmegemäß nicht.

| Quelle | v (netto) | D / v | RTO |
|---|---|---|---|
| lokale Momentaufnahme | entfällt, Rücksprung ist eine Metadatenoperation | ≤ 10 s | **≈ 10 min** |
| Replikat über 10 Gbit/s | 1.000 MB/s | 200.000 MB / 1.000 = 200 s = 3,3 min | **13,3 min** |
| Auslagerung über 1 Gbit/s | 100 MB/s | 200.000 MB / 100 = 2.000 s = 33,3 min | **43,3 min** |
| Auslagerung über 100 Mbit/s | 10 MB/s | 200.000 MB / 10 = 20.000 s = 5,56 h | **5,72 h** |

Die letzte Zeile verletzt den RTO-Zielwert von ≤ 4 h aus K-10. Das ist kein Rechenfehler, sondern die Bedingung, unter der K-10 gilt. Umgestellt ergibt sich der Mindestdurchsatz und die Höchstgröße:

```
v_min  = D / (RTO_ziel − t_ruest)
       = 200.000 MB / (14.400 s − 600 s)
       = 200.000 / 13.800 = 14,5 MB/s = 116 Mbit/s netto

D_max  = v · (RTO_ziel − t_ruest)
bei v = 10 MB/s:  10 · 13.800 = 138.000 MB = 138 GB
```

Daraus folgt eine Festlegung statt einer Warnung: Die Konsole berechnet D_max je Auslagerungsziel aus dem bei der letzten erfolgreichen Wiederherstellungsprobe gemessenen v und kennzeichnet jeden Speicherbereich, dessen Größe D_max überschreitet, mit dem berechneten tatsächlichen RTO. Der Wert v ist damit keine Annahme, sondern ein Messergebnis aus 17.6, und die RTO-Zusage am Dienst ist gerechnet und nicht behauptet.

### Beispiel 2: ein vollständiger Knoten

Annahmen: 2.000 GB Dienstdaten auf dem Knoten; 150 Dienste mit je 300 MB Abbild (Annahme aus K-01 und K-12); Startparallelität 8, Startzeit je Dienst 30 s.

| Schritt | Rechnung | Aus dem Replikat | Aus der Auslagerung |
|---|---|---|---|
| Unbeaufsichtigte Installation vom Abbild | K-01 | 6,0 min | 6,0 min |
| Kopplung und Knotenzertifikat | K-02 | 5,0 min | 5,0 min |
| Sollzustandsauszug ≤ 2 GB über 1 Gbit/s | 16 Gbit / 1 Gbit/s = 16 s | 0,3 min | 0,3 min |
| Dienstdaten | 2.000.000 MB / 1.000 MB/s bzw. / 100 MB/s | 33,3 min | 333,3 min |
| Dienstabbilder 150 · 300 MB = 45.000 MB | / 100 MB/s = 450 s | 7,5 min | 7,5 min |
| Dienststart 150 / 8 · 30 s = 562,5 s | | 9,4 min | 9,4 min |
| **Summe** | | **61,5 min** | **361,5 min = 6,0 h** |

Zielwerte, die daraus folgen und die K-11 nicht abdeckt, weil K-11 nur den Zustand der Kontrollebene betrifft und nicht die Dienstdaten:

| Zielwert | Wert | Herleitung |
|---|---|---|
| Wiederherstellung eines Knotens aus dem Replikat | ≤ 90 min | Rechenergebnis 61,5 min zuzüglich Reserve für langsamere Hardware |
| Wiederherstellung eines Knotens aus der Auslagerung | ≤ 8 h | Rechenergebnis 6,0 h zuzüglich Reserve |
| Wiederherstellung eines Dienstes aus lokaler Momentaufnahme | ≤ 15 min | Rechenergebnis ≈ 10 min zuzüglich Reserve |
| Wiederherstellung eines Dienstes aus der Auslagerung | D / v + 10 min, je Dienst angezeigt | Formel oben mit gemessenem v |

Die Reihenfolge der Rückholung ist nicht beliebig. Sie folgt der Abhängigkeitsordnung der Dienste aus dem Sollzustand, damit ein Dienst nicht startet, bevor die Datenbank steht, auf die er zeigt; innerhalb einer Ordnungsstufe gewinnt der Dienst mit der kleineren Datenmenge, weil das die Zahl der bereits nutzbaren Dienste zu jedem Zeitpunkt maximiert. Die verworfene Alternative ist eine vom Bediener gepflegte Wichtigkeitsliste; sie wurde verworfen, weil sie ein Pflichtfeld je Dienst wäre und damit gegen INV-14 verstieße, und weil eine Abhängigkeitsordnung ohnehin vorliegt.

## 17.6 Automatische Wiederherstellungsproben

### Was geprüft wird

| Probe | Gegenstand | Kadenz | Erfolgsmaß |
|---|---|---|---|
| P1 Integritätsprobe | Prüfsumme und Signatur der Wiederherstellungspunkte am Ablageort | täglich als Stichprobe, monatlich vollständig | 0 Abweichungen |
| P2 Objektprobe | Sollzustandsexport in eine Laborinstanz importieren, objektweise gegen den Export vergleichen | täglich | 0 abweichende Objekte (K-24) |
| P3 Datenprobe | einen Speicherbereich vollständig aus der Auslagerung zurückspielen, Dienst starten, deklarierte Bereitschaftsprobe bestehen | rotierend, Fenster 3 Monate je Speicherbereich | Bereitschaftsprobe bestanden |
| P4 Vollprobe | Knoten aus Abbild, Sollzustand und Daten auf Ersatzhardware | monatlich (K-24) | ≤ 60 min, 0 Abweichungen |
| P5 Durchsatzprobe | Nettodurchsatz v der Rückholstrecke | bei jeder Datenprobe | Messwert für die RTO-Projektion nach 17.5 |
| P6 Unveränderlichkeitsprobe | Lösch- und Überschreibversuch auf ein Prüfobjekt mit den Zugangsdaten des Schreibers | monatlich | beide Versuche scheitern |

P3 läuft ausdrücklich gegen die Auslagerung und nicht gegen das Replikat, weil das Replikat im Normalbetrieb ohnehin beschrieben wird und die Auslagerung sonst nie gelesen würde. Ein Pfad, der nur im Katastrophenfall benutzt wird, ist genau der Pfad, der im Katastrophenfall zum ersten Mal versagt.

### Dimensionierung der Rotation

Annahmen: 40 datentragende Speicherbereiche; Zielfenster, innerhalb dessen jeder Speicherbereich einmal geprüft ist, W = 3 Monate; mittlere Größe 50 GB; v = 100 MB/s; Rüstzeit je Probe 10 min.

```
Proben je Monat = 40 / 3 = 13,3 -> 14
Dauer je Probe  = 50.000 MB / 100 MB/s + 600 s = 500 s + 600 s = 1.100 s = 18,3 min
Probenzeit je Monat = 14 · 18,3 min = 256 min = 4,3 h
```

Deutung: Die vollständige Abdeckung aller Speicherbereiche in einem Quartal kostet gut vier Stunden Prüfzeit im Monat auf Ersatzhardware. Eine Probe je Monat statt vierzehn wäre billiger und wertlos: Bei 40 Speicherbereichen wäre jeder einzelne erst nach 40 Monaten an der Reihe.

### Warum eine ungeprüfte Sicherung als nicht vorhanden gilt

Der Wert einer Sicherung ist vollständig die Wahrscheinlichkeit, dass die Wiederherstellung gelingt. Ohne Probe ist diese Wahrscheinlichkeit unbekannt, und die Fehlerarten sind bauartbedingt lautlos: eine fehlerhaft umschlossene Schlüsselreferenz, eine abgebrochene Übertragung mit vollständig wirkendem Objekt, ein beschädigter Index am Ablageziel, ein fehlender Haken mit absturzkonsistentem Stand, ein Stand mit einer `schema_version`, die die laufende Kontrollebene nicht mehr importiert. Keiner dieser Fälle erzeugt beim Schreiben einen Fehler.

Die zweite, stärkere Begründung ist kombinatorisch. Annahme: Die Wahrscheinlichkeit, dass die Wiederherstellung eines einzelnen Speicherbereichs unbemerkt misslingt, beträgt p = 5 %.

```
P(alle 40 Speicherbereiche wiederherstellbar) = (1 − 0,05)^40
                                              = e^(40 · ln 0,95)
                                              = e^(−2,05173)
                                              = 0,1285
```

Deutung: Mit einer Einzelfehlerwahrscheinlichkeit von 5 % gelingt eine vollständige Wiederherstellung in nur 12,9 % der Fälle. Eine einzige Gesamtprobe "die Sicherung funktioniert" ist deshalb kein Nachweis; der Nachweis muss je Speicherbereich geführt werden. Das ist ein Modell mit angenommenem p, keine Messung; die Aussage ist unabhängig vom konkreten Wert von p, weil (1 − p)^40 für jedes nennenswerte p klein wird.

Aus INV-18 folgt daraus unmittelbar die Anzeige: Ein Wiederherstellungspunkt ohne erfolgreiche Prüfung trägt den Zustand "ungeprüft", und dieser Zustand erscheint am Dienst, nicht in einem Bericht. Das Objektmodell hält dafür bereits das Feld "Prüfstatus mit Datum der letzten erfolgreichen Prüfung" vor.

### Nachweis

Jede Probe ist ein Vorgang und erzeugt Auditereignisse (INV-23). Das Ergebnis besteht aus vier Teilen: der Kennung des geprüften Wiederherstellungspunkts, dem gemessenen v, dem Vergleichsergebnis (bei P2 die Zahl abweichender Objekte, bei P3 das Ergebnis der deklarierten Bereitschaftsprobe), und einem Prüfbericht, der mit der Ausgabe-CA signiert und mit einem Zeitstempel nach RFC 3161 versehen wird. Der Prüfbericht liegt im Auditstrom und überlebt damit die Löschung des geprüften Objekts.

Die Grenze der Probe gehört zum Nachweis. Eine Probe zeigt, dass der Bestand lesbar ist, dass das Fremdprodukt ihn annimmt und dass die deklarierte Bereitschaftsprobe besteht. Sie zeigt nicht, dass die fachlichen Daten inhaltlich richtig sind; eine Anwendung, die seit Wochen falsche Werte schreibt, besteht jede Bereitschaftsprobe. Diese Grenze wird in der Konsole benannt und nicht durch eine Formulierung wie "Sicherung geprüft" verdeckt.

## 17.7 Katastrophenfall der Kontrollebene

### Die Lage

Alle Knoten sind verloren. Damit sind verloren: der Sollzustand im laufenden System ([Kapitel 8](08-kontrollebene.md)), die Ausgabe-CA und alle Mandanten-Zwischen-CAs (ihre Schlüssel sind TPM-gebunden und nicht exportierbar, siehe [Kapitel 11](11-pki.md)), alle Geheimnisse und — entscheidend — die TPM-umschlossenen Mandantenhauptschlüssel. Die ausgelagerten Stände existieren weiter, sind aber mit Schlüsseln verschlüsselt, die es nicht mehr gibt. Ohne ein vorbereitetes Artefakt ist die Sicherung in diesem Moment unbrauchbar, und zwar nicht durch einen Fehler, sondern durch die korrekte Anwendung von INV-20.

Das versiegelte Wiederherstellungspaket löst genau diese Zirkularität und nichts anderes.

### Inhalt

| Bestandteil | Zweck | Lage im Paket |
|---|---|---|
| Installationskennung, Basisdomäne, `schema_version`, Erzeugungszeitpunkt, Paketkennung | Zuordnung eines gefundenen Pakets ohne Öffnen | Kopfdaten, unverschlüsselt |
| Fingerabdruck des Paketinhalts und Signatur der zum Erzeugungszeitpunkt gültigen Ausgabe-CA | Echtheits- und Vollständigkeitsprüfung vor dem Öffnen | Kopfdaten, unverschlüsselt |
| Adressen der Auslagerungsziele und ausschließlich lesende Zugangsdaten | Erreichen der Stände | versiegelte Nutzlast |
| Mandantenhauptschlüssel je Mandant | Entschlüsselung der Speicherbereiche | versiegelte Nutzlast |
| Ableitungsmaterial der Auslagerungsschlüssel je Mandant und Ziel | Entschlüsselung der ausgelagerten Stände | versiegelte Nutzlast |
| Öffentlicher Teil und Fingerabdruck der Wurzel-CA sowie die Kette der Ausgabe-CA | Prüfung der Signaturen an den Ständen | versiegelte Nutzlast |
| Verzeichnis der Wiederherstellungspunkte: Kennung, Zeitpunkt, Prüfsumme, Ablageort, Aufbewahrungsende | Wissen, was überhaupt existiert | versiegelte Nutzlast |
| Letzter bekannter Kettenhashwert des ausgelagerten Auditstroms | Fortsetzungsprüfung der Auditkette | versiegelte Nutzlast |
| Verfahrenshinweis in Klartext | Bedienbarkeit ohne laufendes Atrium und ohne Konsole | versiegelte Nutzlast |

Der Verfahrenshinweis ist kein Beiwerk. Im Katastrophenfall existiert keine Atrium Console, die durch den Ablauf führt; INV-17 verlangt, dass kein Fehlerfall ausschließlich auf die Kommandozeile verweist, kann hier aber nicht greifen, weil keine Oberfläche existiert. Das Paket schließt diese Lücke durch eine Anleitung, die ohne das Produkt lesbar ist, und das ist die einzige Stelle des Entwurfs, an der eine Kommandozeilenanleitung der vorgesehene Weg ist.

### Was ausdrücklich nicht enthalten sein darf

| Ausgeschlossen | Begründung |
|---|---|
| Privater Schlüssel der Wurzel-CA | INV-22 verlangt ihn außerhalb des laufenden Systems; das Paket wäre sonst eine zweite, schwächer geschützte Kopie des höchsten Vertrauensankers. Die Wurzel hat ein eigenes Verfahren mit eigenen Anteilen ([Kapitel 11](11-pki.md)). |
| Private Schlüssel der Ausgabe-CA und der Mandanten-Zwischen-CAs | Sie sind TPM-gebunden und nicht exportierbar. Ihr Verlust wird durch Neuausstellung geheilt, nicht durch Wiederherstellung. |
| Schreib- oder Löschzugangsdaten zu einem Auslagerungsziel | Das Paket wäre sonst genau der Weg, die Sicherung zu vernichten, den 17.3 ausschließt. Ein Wiederherstellungsweg braucht nur Lesezugriff. |
| Zugangsdaten zu Fremdsystemen aus Konnektorbindungen | Ihr Verlust kostet Arbeit, ihre Preisgabe kostet fremde Systeme. Die Asymmetrie entscheidet gegen die Aufnahme. |
| Nutzdaten der Dienste | Das Paket ist ein Schlüsselbund, kein Datenträger. Es bleibt klein genug, um es auf Papier oder auf einem Hardwaretoken zu halten. |
| Auditereignisse | Sie liegen ausgelagert. Das Paket enthält nur den letzten Kettenhashwert. |
| Anmeldemittel von Personen, Passkeys, Kennwörter | Sie sind nicht Teil des Sollzustands und für die Wiederherstellung nicht erforderlich. |
| Notzugang und Wiederherstellungscode des laufenden Betriebs | Anderer Zweck, anderes Bedrohungsmodell, andere Aufbewahrung. Eine Vermischung macht den Notzugang so schwer zugänglich wie das Katastrophenpaket oder das Paket so leicht zugänglich wie den Notzugang. |

Der jüngste Sollzustandsexport ist bewusst nicht im Standardpaket. Er veraltet ab der Erzeugung, und er würde das Paket von einem Schlüsselbund zu einer vollständigen Kopie des Objektgraphen machen. Der Export wird stattdessen mit den Lesezugangsdaten des Pakets vom Auslagerungsziel geholt. Die Schwäche dieser Entscheidung wird benannt: Sind Paket und Auslagerungsziel gleichzeitig verloren, ist die Installation nicht wiederherstellbar. Für diesen Fall ist ein zusätzlicher Datenträger mit dem jüngsten Export vorgesehen, der ausdrücklich gewählt wird und der wie das Paket aufbewahrt wird, aber nicht Teil von ihm ist.

### Erzeugung, Schutz, Aufbewahrung

Erzeugung: bei der Erstinstallation unmittelbar nach der Schlüsselzeremonie; bei jedem Ereignis, das den Inhalt ändert (neuer Mandant, neues Auslagerungsziel, Schlüsselwechsel); turnusmäßig mit dem Zielwert 90 Tage, damit ein abgelaufenes Lesezugangsmittel das Paket nicht unbemerkt entwertet. Jede Erzeugung ist ein Vorgang mit Wirkungsvorschau und Auditereignis.

Schutz: Die Nutzlast wird mit einem eigenen Paketschlüssel verschlüsselt, der nach Shamir in n Anteile geteilt wird; k Anteile öffnen das Paket. Die Vorbelegung von k und n stammt aus einer Plattformrichtlinie mit benannter Quelle (INV-15), der Bediener bestätigt Anteile und wählt keine Parameter. Der Paketschlüssel ist nie derselbe wie der Wurzelschlüssel und wird nie in denselben Anteilen geführt; andernfalls öffnete ein Anteilssatz sowohl den Vertrauensanker als auch alle Mandantendaten, und die Trennung der beiden Verfahren wäre nur eine Benennung.

Aufbewahrung: Anteile an getrennten Orten, mindestens einer außerhalb jeder Fehlerzone der Installation. Kein Anteil auf einem Knoten, kein Anteil im Auslagerungsziel, und kein Anteil in einem Geheimnisspeicher, der selbst ein Dienst dieser Installation ist. Die letzte Regel ist die, gegen die in der Praxis verstoßen wird: Ein Kennwortspeicher, der auf Atrium läuft, ist im Katastrophenfall genau so verloren wie alles andere. Abgelöste Pakete werden 90 Tage überlappend aufbewahrt, damit ein fehlerhaft verteiltes neues Paket den Rückgriff auf das vorherige nicht ausschließt; ihre Vernichtung ist ein Vorgang mit Nachweis.

### Verwendung

```
 1  Ersatzhardware, Installation vom Abbild, Wartemodus
 2  "Wiederherstellung aus Paket" statt "Ankerknoten einrichten"   (erste Entscheidung)
 3  k Anteile einzeln eingeben, je Anteil den gedruckten Pruefwert bestaetigen
 4  Kopfdaten gegen die eingegebene Installationskennung pruefen, Nutzlast entsiegeln
 5  Lesezugang zum Auslagerungsziel herstellen, Verzeichnis gegen den Ablageort abgleichen
 6  Juengsten Sollzustandsexport holen, Signatur, Pruefsumme und schema_version pruefen
 7  Wurzel wiedereinbringen (eigene Anteile) ODER neue Wurzelzeremonie;
    Ausgabe-CA und Mandanten-Zwischen-CAs in jedem Fall NEU ausstellen
 8  Sollzustand importieren; alle Endzertifikate als neu auszustellen markieren
 9  Mandantenhauptschluessel einbringen; Speicherbereiche werden entschluesselbar
10  Daten in Abhaengigkeitsordnung zurueckholen (17.5)
11  Dienste starten; abgeleitete Artefakte NEU berechnen, nie zurueckschreiben
12  Genesisereignis der neuen Auditkette mit dem alten Kettenhashwert als Vorgaenger
13  Pruefbericht erzeugen, signieren, Zeitstempel nach RFC 3161
```

Schritt 7 trägt die teuerste Folge des gesamten Kapitels. Sind die Anteile der Wurzel-CA erhalten, überlebt der Vertrauensanker; verwaltete Geräte behalten ihn und akzeptieren die neu ausgestellte Kette ohne Zutun. Sind die Anteile der Wurzel verloren, muss eine neue Wurzel erzeugt werden, und jedes verwaltete Gerät muss den neuen Anker erhalten. Die Kosten lassen sich beziffern:

```
Annahmen: 800 Geraete (K-12), Aufwand je Geraet 5 min
800 · 5 min = 4.000 min = 66,7 h = 8,3 Personentage
```

Deutung: Der Verlust der Wurzelanteile kostet rund acht Personentage Feldarbeit zusätzlich zur eigentlichen Wiederherstellung und macht bis zu deren Abschluss jeden zertifikatsgebundenen Netzzugang unbrauchbar. Das ist die Begründung dafür, dass die Wurzelanteile ein eigenes Verfahren mit eigener Aufbewahrung haben und nicht im Katastrophenpaket liegen: Ihre Aufbewahrung ist strenger, weil ihr Verlust anders und ihr Diebstahl viel schlimmer wiegt.

Schritt 11 ist derselbe Grundsatz wie bei der Wiederherstellung eines einzelnen Dienstes ([Kapitel 15](15-dienste-software.md)): Eine Wiederherstellung schreibt Daten zurück, nicht Konfiguration. Firewallregeln, Proxyrouten, DNS-Einträge, Zertifikate und Fremdkonten werden aus dem importierten Sollzustand neu abgeleitet (INV-09).

### Wer es benutzen darf, und die Auditfolge

Die Verwendung ist kein Recht einer Rolle. Im Katastrophenfall existiert kein laufendes System, das ein Recht prüfen könnte; jede Berechtigungsprüfung wäre eine Behauptung. Die Zugangskontrolle ist ausschließlich physisch und besteht im Besitz von k Anteilen. Daraus folgt: Die Verteilung der Anteile ist die Rechtevergabe. Sie ist ein auditierter Vorgang im laufenden System, sie nennt je Anteil den Träger und das Datum der letzten Bestätigung, und dieser Nachweis liegt im ausgelagerten Auditstrom und überlebt damit die Installation.

Zielwert: k ≥ 3, und die Konsole verweigert eine Verteilung, die zwei Anteile demselben Träger zuordnet. Die Schwäche dieser Zusage ist dieselbe wie bei der Wurzelzeremonie: Ob zwei Anteile tatsächlich an verschiedenen Orten liegen, ist technisch nicht feststellbar; die Ortsangabe je Anteil ist ein Freitextfeld und damit eine Behauptung des Bedieners.

Die Auditfolge über den Katastrophenfall hinweg ist nachvollziehbar, aber nicht lückenlos, und wird auch nicht als lückenlos dargestellt. Die neue Kette beginnt mit einem Genesisereignis, das die alte Installationskennung und den letzten bekannten Kettenhashwert nennt. Die Ereignisse zwischen der letzten Auslagerung des Auditstroms und dem Ausfall sind verloren; das Genesisereignis nennt diesen Zeitraum ausdrücklich als Lücke mit Anfangs- und Endzeitpunkt.

## 17.8 Widerstand gegen Verschlüsselungsangriffe

### Bedrohungsmodell

Der Angreifer hat administrativen Zugriff auf mindestens einen Knoten und möglicherweise Administratorrechte in der Konsole. Er verschlüsselt oder löscht Dienstdaten und versucht anschließend, die Sicherung unbrauchbar zu machen, weil sonst seine Forderung wirkungslos bleibt. Er agiert nicht sofort, sondern verweilt, bis er genug Rechte und genug Übersicht hat.

### Die fünf Eigenschaften und ihre Grenzen

| Eigenschaft | Umsetzung | Verhindert | Verhindert nicht |
|---|---|---|---|
| Unveränderlichkeit | Aufbewahrungsdatum je Objekt, vom Ablageziel durchgesetzt, vom Schreiber nur verlängerbar | Überschreiben bestehender Stände | Verschlüsselung der Produktivdaten |
| Nur-Anhängen | Schreibzugang ohne Lösch- und Ersetzrecht, ein Objektname nur einmal beschreibbar | Vernichtung der Sicherung mit den Zugangsdaten des befallenen Knotens | Einbringen unbrauchbarer neuer Stände |
| Getrennte Berechtigungen | Drei-Prinzipal-Modell aus 17.3 | Vernichtung durch einen einzelnen kompromittierten Prinzipal | Kompromittierung mehrerer Prinzipale |
| Offline-Kopie | ein Monatsstand auf ein trennbares Medium ohne Netzpfad aus der Produktion | Angriff über jeden netzerreichbaren Pfad | Datenverlust seit dem letzten Offline-Stand |
| Aufbewahrungsdauer | Leiter aus 17.4 | Verlust aller unbefallenen Stände bei später Erkennung | nichts, wenn die Verweildauer den Horizont übersteigt |

Keine dieser Eigenschaften schützt die Produktivdaten. Sie schützen ausschließlich die Möglichkeit, einen unbefallenen Stand zurückzuholen. Das ist der Zweck, und eine Darstellung, die mehr verspricht, wäre falsch.

### Erkennungssignale

| Signal | Berechnung | Falschalarmquelle |
|---|---|---|
| Sprung der Änderungsrate | c_beobachtet > 5 · c_gleitend, gleitender Mittelwert über 14 d je Speicherbereich | geplante Massenimporte, Erstbefüllung, Migrationen |
| Zusammenbruch des Kompressionsverhältnisses | Abfall um mehr als 30 % gegenüber dem 14-Tage-Mittel desselben Speicherbereichs | Wechsel des Datentyps, etwa Beginn einer Medienablage |
| Zusammenbruch der Deduplizierungsquote | Anteil neuer Blöcke eines Standes deutlich über dem aus 1 − (1 − c)^Δ erwarteten Wert | wie oben |
| Massenlöschung oder Massenumbenennung | Rate der Entfernungen je Minute gegen den Tagesmittelwert | Aufräumläufe von Anwendungen |
| Fehlgeschlagener Löschversuch am Auslagerungsziel | Zähler; der legitime Schreiber versucht nie zu löschen | praktisch keine |
| Zugriff auf viele Speicherbereiche durch dieselbe Identität in kurzer Zeit | Anzahl berührter Speicherbereiche je Identität und Stunde | Sicherungs- und Prüfläufe, als Prinzipale bekannt und ausgenommen |

Das fünfte Signal ist das schärfste, weil es keine legitime Ursache hat: Der Schreiber besitzt kein Löschrecht und versucht deshalb im Normalbetrieb nie zu löschen. Ein einziger fehlgeschlagener Löschversuch mit den Zugangsdaten des Schreibers ist ein Angriffsnachweis, kein Verdacht.

Ein Entropiemaß über eine Stichprobe neu geschriebener Blöcke wird nicht als eigenständiges Signal geführt. Verschlüsselte und bereits komprimierte Nutzdaten — Medien, Archive, verschlüsselte Bestände anderer Dienste — sind ebenfalls hochentrop, sodass das Maß nur gegen die eigene Grundlinie des jeweiligen Speicherbereichs aussagekräftig ist; genau diese Grundlinie liefert bereits das Kompressionsverhältnis, das ohnehin gemessen wird.

Jedes ausgelöste Signal ist eine Störung im Überblick mit Verweis auf das verursachende Objekt (KANON 5).

### Reaktion

Die automatische Reaktion löscht nichts (INV-11) und hält die Auslagerung nicht an. Das Anhalten der Auslagerung wäre der naheliegende und falsche Reflex: Die Leiter ist additiv, neue Stände verdrängen alte nicht, und ein angehaltener Schreiber verhindert nur, dass ein noch unbefallener Teil gesichert wird. Angehalten wird stattdessen der Aufräumer, und die Aufbewahrungsdaten aller bestehenden Stände werden verlängert — eine Operation, die dem Schreiber erlaubt ist, weil sie nur verlängert (17.3). Zusätzlich wird eine Momentaufnahme außer der Reihe erzeugt, damit der Zustand unmittelbar vor der Bestätigung des Verdachts festgehalten ist.

### Vom Zeitfenster zur Aufbewahrungsdauer

Die Verweildauer t_verweil ist die Zeit zwischen dem ersten schreibenden Zugriff des Angreifers und der Erkennung. Sie ist vom System nicht im Voraus messbar und wird als Richtlinienannahme des Betreibers geführt; eine Zahl aus der Literatur wird hier nicht zitiert, weil sie nicht überprüfbar wäre. Die Bedingung an die Leiter lautet:

```
T_aufbewahrung  >=  t_verweil + t_reaktion + t_wiederherstellung
```

Mit der Annahme t_verweil = 90 d, t_reaktion = 7 d und t_wiederherstellung = 1 d ergibt sich T ≥ 98 d. Der ausgelagerte Horizont der Leiter aus 17.4 beträgt 430 d und erfüllt die Bedingung mit großem Abstand.

Die Länge ist jedoch nur die halbe Antwort. Die zweite Hälfte ist die Dichte, denn sie bestimmt, wie alt der jüngste unbefallene Stand ist und damit den Datenverlust nach einem erfolgreichen Angriff:

| Verweildauer | greifende Stufe | Abstand | Datenverlust nach Rückgriff |
|---|---|---|---|
| ≤ 14 d | Tagesstufe | 1 d | bis zu 1 d |
| 14 d bis 70 d | Wochenstufe | 7 d | bis zu 7 d |
| 70 d bis 430 d | Monatsstufe | 30 d | bis zu 30 d |
| > 430 d | keine | — | vollständig |

Bei der angenommenen Verweildauer von 90 d greift die Monatsstufe, und der Datenverlust beträgt bis zu 30 Tage. Das ist die eigentliche Folge der Verweildauer, und sie wird selten so benannt: Die Verweildauer bestimmt nicht nur, ob überhaupt ein unbefallener Stand existiert, sondern auch dessen Alter. Die Gegenmaßnahme ist die Verdichtung der Leiter im betroffenen Zeitraum, deren Preis in 17.4 ausgerechnet ist: Zwölf Wochenstände statt drei Monatsständen über denselben Zeitraum kosten 437,96 GB, also 2,8 % des Gesamtbedarfs, und senken den Datenverlust in diesem Bereich von 30 auf 7 Tage. Diese Rechnung wird der Aufbewahrungsentscheidung in der Wirkungsvorschau beigelegt.

Der Offline-Stand folgt derselben Logik mit gröberer Dichte. Ein monatlich fortgeschriebenes trennbares Medium mit der vollen Leiter belegt nach 17.4 rund 9.711 GB; zwölf rotierende Medien mit je einem vollständigen Stand belegen 12 · 2.000 / 1,6 = 15.000 GB. Die Leiter auf einem Medium ist billiger, die Rotation schützt zusätzlich gegen den Ausfall des Mediums selbst. Welche Variante gilt, ist eine Richtlinie je Mandant.

## 17.9 Datenträgerausfall

### Wiederaufbauzeit

```
t_wieder = C_belegt / v_wieder

C_belegt   belegte Kapazitaet des ausgefallenen Traegers
v_wieder   Nettodurchsatz des Wiederaufbaus, begrenzt vom langsamsten der drei Werte:
           Lesedurchsatz der Quellen, Schreibdurchsatz des Ersatztraegers,
           der dem Wiederaufbau ueberlassene Anteil der Ein-/Ausgabeleistung
```

Ein Wiederaufbau in einem prüfsummenbasierten Dateisystem kopiert nur belegte Blöcke, nicht den ganzen Träger. Der Füllgrad geht deshalb direkt in die Dauer ein, und der Verzicht auf die 80-Prozent-Grenze aus 17.1 verlängert nicht nur die Schreiblatenz, sondern auch jedes Ausfallfenster.

Annahmen: Träger 8 TB, Füllgrad 70 %, also C_belegt = 5,6 TB = 5.600.000 MB; dem Wiederaufbau überlassener Anteil 50 %.

| Trägerart | Schreibdurchsatz | v_wieder | t_wieder |
|---|---|---|---|
| rotierend | 180 MB/s | 90 MB/s | 5.600.000 / 90 = 62.222 s = **17,3 h** |
| Festkörper | 1.500 MB/s | 750 MB/s | 5.600.000 / 750 = 7.467 s = **2,07 h** |

### Zweiter Ausfall während des Wiederaufbaus

Modell: konstante Ausfallrate, exponentialverteilte Lebensdauer, unabhängige Ausfälle. Annahme: jährliche Ausfallrate eines Trägers 2 %.

```
lambda = 0,02 / 8.760 h = 2,2831 · 10^-6 je Stunde
m      = Zahl der verbleibenden Traeger der Gruppe = 5
P(mindestens ein weiterer Ausfall in t) = 1 − e^(−m · lambda · t)

rotierend, t = 17,3 h : 5 · 2,2831e-6 · 17,3 = 1,9727e-4
                        P = 1,97 · 10^-4 = 0,0197 %   (1 zu 5.069)
Festkoerper, t = 2,07 h: 5 · 2,2831e-6 · 2,07 = 2,3676e-5
                        P = 2,37 · 10^-5 = 0,0024 %   (1 zu 42.235)
```

Deutung: Das Zeitfenster schrumpft um den Faktor 17,3 / 2,07 = 8,35, und die Wahrscheinlichkeit sinkt im selben Verhältnis, weil sie in diesem Bereich linear in t ist.

### Der Fehler, der wahrscheinlicher ist

Die größere Gefahr ist kein zweiter Vollausfall, sondern ein nicht korrigierbarer Lesefehler auf einem verbleibenden Träger genau während des Wiederaufbaus, denn der Wiederaufbau liest die Quelle vollständig. Annahme: nicht korrigierbare Lesefehlerrate 1 je 10^15 gelesenen Bits.

```
Gelesene Bits = 5,6 · 10^12 Byte · 8 = 4,48 · 10^13 bit
P(mindestens ein Lesefehler) ~= 4,48e13 · 1e-15 = 0,0448 = 4,48 %

Bei einer Rate von 1 je 10^14:  4,48e13 · 1e-14 = 0,448 = 44,8 %
```

Deutung: Bei der günstigeren angenommenen Rate ist ein nicht korrigierbarer Lesefehler während des Wiederaufbaus 0,0448 / 0,000197 = 227-mal wahrscheinlicher als ein zweiter Vollausfall. Bei der ungünstigeren Rate trifft er fast jeden zweiten Wiederaufbau. Ein Zwei-Wege-Spiegel hat in diesem Moment keine dritte Quelle: Die Prüfsumme erkennt den Fehler, aber es gibt nichts, woraus sie ihn reparieren könnte. Der betroffene Pfad wird als beschädigt gemeldet — benannt und lokalisiert, nicht stillschweigend falsch ausgeliefert, was gegenüber einer Anordnung ohne Blockprüfsummen der entscheidende Unterschied bleibt, den Datenverlust aber nicht aufhebt.

### Folgerung für die Redundanzwahl

| Festlegung | Begründung |
|---|---|
| Ein Speicherpool mit Trägern ab 4 TB wird nicht als Zwei-Wege-Spiegel ausgelegt | Die Lesefehlerwahrscheinlichkeit während des Wiederaufbaus liegt im Prozentbereich und ist ohne dritte Quelle nicht reparabel. |
| Zulässig sind Drei-Wege-Spiegel oder eine Paritätsanordnung mit zwei Paritätseinheiten | Beide behalten während eines Wiederaufbaus eine korrigierende Quelle. |
| Zielwert t_wieder ≤ 12 h; daraus folgt die Höchstbelegung je Träger | C_max = v_wieder · 12 h; bei 90 MB/s: 90 · 43.200 s = 3.888.000 MB = 3,89 TB, also 48,6 % eines 8-TB-Trägers. Alternativ wird v_wieder erhöht. |
| Trägerredundanz ersetzt keine Knotenredundanz | Ein Drei-Wege-Spiegel überlebt keinen Knotenausfall, eine Speicherklasse "Gespiegelt" überlebt keinen Doppelfehler innerhalb eines Trägers ohne Wiederaufbau. Beide Ebenen sind getrennt zu wählen. |

Zwei Grenzen dieser Rechnungen gehören benannt. Erstens sind die Ausfälle als unabhängig angenommen; Träger aus derselben Fertigungscharge, mit derselben Betriebsdauer, in derselben Temperatur- und Vibrationsumgebung fallen korreliert aus. Ein Korrelationszuschlag lässt sich aus einer Ausfallrate nicht seriös ableiten; die Gegenmaßnahme ist organisatorisch, nämlich gemischte Chargen und gestaffelte Einbauzeitpunkte, und sie ist vom System nicht erzwingbar. Zweitens wird die Wahrscheinlichkeit zweier Lesefehler auf demselben Streifen einer Paritätsanordnung hier nicht ausgerechnet, weil das ein Streifenmodell mit Annahmen über Blockgrößen und Fehlerhäufung erforderte, die nicht belegbar wären; festgehalten wird nur die Richtung, dass eine zweite Paritätseinheit den Einzelfehler während des Wiederaufbaus korrigierbar hält.

Ein struktureller Befund folgt aus der Formel unabhängig von allen Annahmen: v_wieder wächst über Hardwaregenerationen langsamer als C, weshalb t_wieder und damit jedes Ausfallfenster wächst. Die Antwort darauf ist eine Obergrenze der Belegung je Träger in der Knotenklasse, nicht die Hinnahme längerer Fenster.

## 17.10 Was der Bediener davon sieht

### Genau ein Feld je Dienst

Der Dienst trägt ein einziges Speicherfeld: die Datensicherheitsstufe mit den drei Werten aus KANON 1. Alles andere ist abgeleitet und nicht editierbar (INV-09): Technik, Zahl der Replikate, Aufbewahrungsleiter, Ablageorte, Schlüssel, Kontingentaufschlag, Sicherungsplan. Am Feld steht der daraus folgende RPO, und bei nicht erfüllbarer Stufe steht keine stille Absenkung, sondern die benannte Ursache.

### Dauerhafter Schutzstatus

Der Schutzstatus ist ein berechneter Zustand je Dienst, dauerhaft am Dienst sichtbar und nicht in einem Bericht (INV-18). Die Zustände sind geordnet; angezeigt wird der ungünstigste zutreffende.

| Zustand | Bedingung |
|---|---|
| Nicht geschützt | kein Wiederherstellungspunkt vorhanden |
| Nur lokal | kein Stand außerhalb der Fehlerzone des Dienstes |
| Rückständig | jüngster Stand älter als das Intervall der Stufe |
| Ungeprüft | Stand vorhanden, aber ohne erfolgreiche Prüfung innerhalb des Probenfensters |
| Geschützt | Stand innerhalb des Intervalls, geprüft innerhalb des Probenfensters, mindestens eine Kopie außerhalb der Fehlerzone |

Der Zustand "Geschützt" nennt zusätzlich den Zeitpunkt des letzten geprüften Standes und den nach 17.5 berechneten RTO für diesen Dienst. Damit steht am Dienst eine gerechnete Zusage und keine Zusicherung ohne Deckung.

### Wiederherstellung in wenigen Schritten

| Aufgabe | Entscheidungen | Was vorbelegt ist und woher |
|---|---|---|
| Dienst auf einen früheren Stand zurücksetzen | 1: welcher Stand | Umfang folgt aus dem Zustand des Dienstes; jüngster geprüfter Stand ist vorausgewählt; Zielknoten aus der Platzierung |
| Einzelne Objekte eines Dienstes zurückholen | 2: welcher Stand, welche Objekte | Ablageort, Schlüssel und Zielpfad sind abgeleitet |
| Ausgefallenen Knoten ersetzen | 2: welcher Knoten wird ersetzt, welche Ersatzmaschine | Stand, Reihenfolge und Umfang folgen aus dem Sollzustand |
| Installation aus dem Paket wiederherstellen | 1: Wiederherstellung statt Ersteinrichtung | alles Weitere folgt aus dem Paket; die Anteilseingabe ist eine Bestätigung, keine Entscheidung |

Zielwert: höchstens 2 Entscheidungen je Wiederherstellungsaufgabe, Entwurfsstand wie in der Tabelle. Die Zählweise ist die aus K-03: Ein Pflichtfeld oder eine Auswahl ohne mögliche Vorbelegung ist eine Entscheidung, Bestätigungen und optionale Felder sind keine. Vor der Ausführung steht die Wirkungsvorschau mit der berechneten Unterbrechungsdauer, dem Alter des gewählten Standes und den Mengen, die der anschließende Abgleich neu anlegt und deaktiviert (INV-08).

Die Aufbewahrungsleiter erscheint nirgends als Feld je Dienst. Sie ist eine Richtlinie je Mandant mit wenigen benannten Vorgaben; eine Änderung ist ein Vorgang, dessen Wirkungsvorschau den nach der Formel aus 17.4 berechneten Kapazitätsunterschied und die Veränderung des Datenverlusts aus der Tabelle in 17.8 nennt, bevor sie bestätigt wird.

## Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| R-17-01 | Basisabbild, Systemzustand und Dienstdaten liegen auf getrennten Trägern beziehungsweise getrennten Teilbäumen. Ein Wiederherstellungspunkt enthält kein Basisabbild, sondern dessen Version und Hashwert. | INV-02, K-19 |
| R-17-02 | Jeder Speicherbereich, jeder Kernteilbaum und der Auditteilbaum tragen eine Blockprüfsumme. Wo ein Hashwert als Identität dient, ist das Verfahren kollisionsresistent nach FIPS 180-4 oder gleichwertig. | INV-23 |
| R-17-03 | Jeder Speicherbereich trägt `refquota` für Nutzdaten und `quota` für Nutzdaten und Momentaufnahmen. `quota` wird täglich aus der beobachteten Änderungsrate und dem lokalen Aufbewahrungshorizont neu berechnet; ein fester Faktor wird nicht verwendet. | INV-15 |
| R-17-04 | Der Füllgrad eines Speicherpools überschreitet 80 % nicht. Eine Platzierung, die diese Grenze verletzen würde, wird nicht vorgenommen. | KANON 4 Entscheidung 3 |
| R-17-05 | Jeder Speicherpool schließt innerhalb von 45 Tagen einen vollständigen Prüflauf ab. Überschreitet ein Pool die Frist, zeigt der Knoten einen benannten degradierten Zustand. | INV-18 |
| R-17-06 | Die Datensicherheitsstufe ist das einzige vom Bediener gesetzte Speicherfeld eines Dienstes. Replikationstechnik, Replikatzahl, Ablageorte und Aufbewahrungsleiter sind abgeleitet und über die API nicht schreibbar. | INV-09, KANON 4 Entscheidung 3b |
| R-17-07 | Die Stufe "Synchron gespiegelt" ist nur wählbar, wenn mindestens 3 Stimmen bestehen und die gemessene Umlaufzeit zwischen allen vorgesehenen Replikatträgern ≤ 1 ms beträgt. Andernfalls nennt die Konsole den gemessenen Wert und die berechnete Bestätigungszeit. | INV-05, KANON 4 Entscheidung 3a |
| R-17-08 | Eine nicht erfüllbare Datensicherheitsstufe wird nicht abgesenkt. Die Platzierung endet als unlösbar mit benannter Ursache und mindestens einer benannten kleinsten Änderung, die sie lösbar macht. | INV-12, INV-17 |
| R-17-09 | Bei einer Installation mit genau einer Stimme zeigt jeder Dienst der Stufe "Gespiegelt" dauerhaft, dass die Übernahme eine Bedienerhandlung ist und dass der RTO nach K-09 nur beim Ausfall eines Nicht-Stimmknotens gilt. | INV-18 |
| R-17-10 | Verliert ein synchron gespiegelter Speicherbereich die Mehrheit, wird er schreibgesperrt; lesende Zugriffe und laufende Dienste bleiben unberührt. Ein Weiterschreiben auf der Minderheitsseite findet nicht statt. | INV-04 |
| R-17-11 | Die knotennahe Übertragung eines Speicherbereichs erfolgt roh; der Zielknoten hält den Bestand ohne den Datenschlüssel. | INV-20 |
| R-17-12 | Der Auslagerungsschlüssel wird je Mandant und Ziel nach RFC 5869 aus dem Mandantenhauptschlüssel abgeleitet. Ein installationsweiter Auslagerungsschlüssel existiert nicht. | INV-19, INV-20 |
| R-17-13 | Die Zugangsdaten, mit denen ein Knoten Sicherungsobjekte schreibt, besitzen kein Lösch- und kein Ersetzrecht. Sie dürfen ein Aufbewahrungsdatum verlängern, nie verkürzen. | INV-11 |
| R-17-14 | Löschung abgelaufener Sicherungsobjekte erfolgt durch einen eigenen Prinzipal, dessen Geheimnis nicht im Sollzustand steht und von keinem produktiven Knoten erreichbar ist. | INV-20, INV-21 |
| R-17-15 | Die monatliche Unveränderlichkeitsprobe versucht mit den Zugangsdaten des Schreibers, ein eigens erzeugtes Prüfobjekt mit laufender Aufbewahrungsfrist zu löschen und zu überschreiben. Beide Versuche scheitern. Gelingt einer, sinkt der Schutzstatus aller Dienste an diesem Ziel sichtbar. Die Probe läuft nie gegen einen echten Wiederherstellungspunkt. | INV-18 |
| R-17-16 | Die Aufbewahrungsleiter ist eine Richtlinie je Mandant, kein Feld je Dienst. Eine Änderung ist ein Vorgang, dessen Wirkungsvorschau den berechneten Kapazitätsunterschied und die Veränderung des maximalen Datenverlusts je Zeitraum nennt. | INV-08, INV-15 |
| R-17-17 | Die Konsole berechnet den Kapazitätsbedarf eines Auslagerungsziels aus der beobachteten Zuwachsreihe je Speicherbereich, nicht aus einem festen Vielfachen des Bestandes. | INV-15 |
| R-17-18 | Jeder Dienst nennt den nach `RTO = D / v + t_rüst` berechneten Wert, wobei v der bei der letzten erfolgreichen Wiederherstellungsprobe gemessene Nettodurchsatz ist. Überschreitet der berechnete Wert den Zielwert der Stufe, ist das am Dienst sichtbar. | INV-18, K-08 bis K-10 |
| R-17-19 | Die Rückholreihenfolge folgt der Abhängigkeitsordnung der Dienste aus dem Sollzustand; innerhalb einer Ordnungsstufe geht der Dienst mit der kleineren Datenmenge voran. Eine vom Bediener gepflegte Wichtigkeitsliste existiert nicht. | INV-14 |
| R-17-20 | Jeder Speicherbereich wird innerhalb eines Fensters von 3 Monaten mindestens einmal vollständig aus der Auslagerung zurückgespielt, gestartet und gegen die im Katalogeintrag deklarierte Bereitschaftsprobe geprüft. | K-24 |
| R-17-21 | Ein Wiederherstellungspunkt ohne erfolgreiche Prüfung innerhalb des Probenfensters trägt den Zustand "ungeprüft", und dieser Zustand erscheint am Dienst. | INV-18 |
| R-17-22 | Jede Probe erzeugt einen mit der Ausgabe-CA signierten Prüfbericht mit Zeitstempel nach RFC 3161, der die Kennung des geprüften Punktes, das gemessene v und das Vergleichsergebnis nennt und im Auditstrom liegt. | INV-23 |
| R-17-23 | Die Konsole stellt das Ergebnis einer Probe nicht als Zusicherung fachlicher Richtigkeit dar; die Grenze der Probe wird im Ergebnistext benannt. | INV-16, INV-18 |
| R-17-24 | Ein versiegeltes Wiederherstellungspaket wird bei der Erstinstallation, bei jeder inhaltsändernden Änderung und spätestens alle 90 Tage neu erzeugt. Jede Erzeugung ist ein Vorgang mit Auditereignis. | INV-03, K-13 |
| R-17-25 | Das Paket enthält keinen privaten Schlüssel einer CA, keine Schreib- oder Löschzugangsdaten zu einem Auslagerungsziel, keine Fremdsystemzugangsdaten, keine Nutzdaten und keine Anmeldemittel von Personen. Eine Inhaltsprüfung im Bau bricht bei einem Treffer. | INV-20, INV-22 |
| R-17-26 | Der Paketschlüssel wird in k von n Anteilen geführt, mit k ≥ 3, und ist niemals identisch mit dem Wurzelschlüssel oder in denselben Anteilen enthalten. Die Konsole verweigert eine Verteilung, die zwei Anteile demselben Träger zuordnet. | INV-22 |
| R-17-27 | Die Verwendung des Pakets setzt keine Rolle im laufenden System voraus. Die Verteilung der Anteile ist ein auditierter Vorgang, der je Anteil Träger und Datum der letzten Bestätigung nennt. | INV-23 |
| R-17-28 | Nach einer Wiederherstellung aus dem Paket werden Ausgabe-CA und alle Mandanten-Zwischen-CAs neu ausgestellt; kein privater CA-Schlüssel wird wiederhergestellt. Die Wirkungsvorschau nennt die Zahl der Geräte, die einen neuen Vertrauensanker benötigen, falls die Wurzel nicht erhalten ist. | INV-22, INV-08 |
| R-17-29 | Die Auditkette der wiederhergestellten Installation beginnt mit einem Genesisereignis, das die alte Installationskennung, den letzten bekannten Kettenhashwert und den Zeitraum der entstandenen Lücke mit Anfangs- und Endzeitpunkt nennt. | INV-23 |
| R-17-30 | Eine Wiederherstellung schreibt ausschließlich Daten zurück. Abgeleitete Artefakte werden aus dem aktuellen beziehungsweise importierten Sollzustand neu berechnet. | INV-09 |
| R-17-31 | Ein ausgelöstes Erkennungssignal hält den Aufräumer an, verlängert das Aufbewahrungsdatum aller bestehenden Stände, erzeugt eine Momentaufnahme außer der Reihe und hält die Auslagerung nicht an. Es löscht nichts. | INV-11 |
| R-17-32 | Ein fehlgeschlagener Löschversuch am Auslagerungsziel mit den Zugangsdaten eines Knotens erzeugt sofort eine Störung im Überblick mit Verweis auf den Knoten. | INV-18, KANON 5 |
| R-17-33 | Mindestens ein Generationsstand je Monat liegt auf einem Ziel ohne Netzpfad aus der Produktion. Fehlt er, ist das am Mandanten sichtbar. | INV-18 |
| R-17-34 | Ein Speicherpool mit Trägern ab 4 TB wird nicht als Zwei-Wege-Spiegel ausgelegt. Zulässig sind Drei-Wege-Spiegel oder eine Paritätsanordnung mit zwei Paritätseinheiten. | KANON 4 Entscheidung 3 |
| R-17-35 | Die Belegung je Träger wird so begrenzt, dass der berechnete Wiederaufbau 12 h nicht überschreitet. Überschreitet ein bestehender Pool den Wert, ist das am Knoten sichtbar. | INV-18 |
| R-17-36 | Eine Wiederherstellungsaufgabe kostet höchstens 2 Entscheidungen. Jede Vorbelegung nennt ihre Quelle. | INV-14, INV-15, K-03 |
| R-17-37 | Der Schutzstatus eines Dienstes wird aus den fünf geordneten Zuständen berechnet und dauerhaft am Dienst angezeigt; der ungünstigste zutreffende Zustand gewinnt. Eine Darstellung ausschließlich in einem Bericht ist ausgeschlossen. | INV-18 |
| R-17-38 | Eingaben aus Sicherungs- und Wiederherstellungspfaden werden am Rand gegen ein Schema validiert und nie in eine Abfrage, einen Aufruf oder eine Schale interpoliert. Der Vergleich von Prüfwerten und Geheimnissen läuft laufzeitkonstant. Fehlermeldungen nennen weder Pfade noch Schlüsselreferenzen. | INV-20, Sicherheitsvorgabe |

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Eine Inhaltsprüfung aller Wiederherstellungspunkte findet 0 Basisabbilder und 0 private Schlüssel | R-17-01, R-17-25 | Sicherungsinhaltsprüfung im Bau (INV-22) |
| Ein im Speicher manipulierter Block wird beim Lesen erkannt; bei vorhandener Redundanz beträgt die Zahl fehlerhaft ausgelieferter Blöcke 0 | R-17-02 | Bitfehlerinjektion mit anschließendem Lesetest |
| Nach 56 simulierten Tagen bei 2 % täglicher Änderungsrate liegt die berechnete `quota` innerhalb von 5 % des tatsächlichen Verbrauchs | R-17-03 | Zeitrafferlauf mit erzeugter Schreiblast |
| Eine Platzierung, die den Pool über 80 % füllen würde, findet nicht statt | R-17-04 | Platzierungstest an einem vorbefüllten Pool |
| Die API kennt 0 Schreiboperationen für Replikationstechnik, Replikatzahl, Ablageort und Aufbewahrungsleiter | R-17-06 | Fassadenbau gegen die öffentliche API (INV-01) |
| Bei 2 Stimmen oder bei einer Umlaufzeit von 2 ms ist "Synchron gespiegelt" nicht wählbar; die Meldung nennt den gemessenen Wert | R-17-07 | Formulartest mit injizierter Latenz und Mitgliedschaftsänderung |
| Eine Anforderung von "Synchron gespiegelt" bei 1 Knoten erzeugt 0 Platzierungen und genau 1 benannte Ursache | R-17-08 | Unlösbarkeitstest |
| Nach injizierter Netztrennung schreibt die Minderheitsseite 0 Blöcke und liest weiter | R-17-10 | Partitionstest über einen synchron gespiegelten Speicherbereich |
| Auf dem Replikatziel ist der empfangene Bestand ohne Schlüssel nicht lesbar; ein Leseversuch liefert 0 Byte Klartext | R-17-11 | Leseversuch auf dem Zielknoten |
| Ein Löschversuch und ein Ersetzversuch mit den Schreibzugangsdaten scheitern beide; eine Verlängerung des Aufbewahrungsdatums gelingt, eine Verkürzung scheitert | R-17-13, R-17-15 | Unveränderlichkeitsprobe gegen das Ablageziel |
| Das Geheimnis des Aufräumers ist aus dem Netznamensraum jedes produktiven Knotens nicht erreichbar | R-17-14 | Netznamensraumtest mit Zugriffsversuch |
| Die Wirkungsvorschau einer Leiteränderung von 12 auf 3 Monatsstände nennt eine Kapazitätsänderung, die mit der Formel aus 17.4 auf 1 % übereinstimmt | R-17-16 | Nachrechnung gegen die Vorschau |
| Bei einem Auslagerungsziel mit 10 MB/s gemessenem Durchsatz zeigt jeder Speicherbereich über 138 GB einen RTO oberhalb des Zielwerts der Stufe | R-17-18 | Durchsatzinjektion mit anschließender Anzeigeprüfung |
| Bei 40 Speicherbereichen liegt nach 3 Monaten die Zahl der nie zurückgespielten Speicherbereiche bei 0 | R-17-20 | Zeitrafferlauf über die Rotation |
| Ein neu erzeugter Wiederherstellungspunkt zeigt am Dienst den Zustand "ungeprüft", bis die Probe bestanden ist | R-17-21 | Zustandsmatrixtest über alle fünf Schutzzustände |
| Jede Probe erzeugt genau 1 signierten Prüfbericht mit Zeitstempel; eine Kettenprüfung des Auditstroms besteht | R-17-22 | Kettenprüfung beim Export |
| Ein Paket, dem ein privater CA-Schlüssel, ein Schreibzugangsmittel oder ein Nutzdatenblock beigemischt wurde, wird beim Bau abgelehnt | R-17-25 | Inhaltsmutationstest gegen die Paketerzeugung |
| Eine Anteilsverteilung, die zwei Anteile demselben Träger zuordnet, wird abgelehnt; k = 2 wird abgelehnt | R-17-26 | Verteilungstest mit kollidierenden Trägern |
| Nach einer vollständigen Wiederherstellung auf Ersatzhardware beträgt die Zahl wiederhergestellter privater CA-Schlüssel 0, die Zahl abweichender Objekte 0 und die Dauer ≤ 60 min | R-17-28, K-24 | Vollprobe nach K-24 |
| Die neue Auditkette beginnt mit genau 1 Genesisereignis, das den alten Kettenhashwert und den Lückenzeitraum nennt | R-17-29 | Kettenprüfung nach der Wiederherstellung |
| Nach einer Wiederherstellung stimmen Firewallregeln, Proxyrouten, DNS-Einträge und Zertifikate mit dem aktuellen Sollzustand überein, nicht mit dem Stand des Wiederherstellungspunkts | R-17-30 | Vergleichslauf über die abgeleiteten Artefakte |
| Eine injizierte Verschlüsselungslast erzeugt innerhalb von 24 h mindestens 2 Signale; der Aufräumer läuft danach 0-mal, die Auslagerung läuft weiter, 0 Stände werden gelöscht | R-17-31 | Angriffssimulation auf einem Laborspeicherbereich |
| Ein einzelner fehlgeschlagener Löschversuch erzeugt genau 1 Störung mit Verweis auf den auslösenden Knoten | R-17-32 | Ereignisinjektion am Ablageziel |
| Eine Auslegung als Zwei-Wege-Spiegel mit 8-TB-Trägern wird abgelehnt | R-17-34 | Poolauslegungstest |
| Über 20 Wiederherstellungsaufgaben liegt die Zahl der Pflichtfelder ohne mögliche Vorbelegung bei höchstens 2 je Aufgabe | R-17-36 | Prüfung der Aufgabendefinitionen im Bau (INV-14) |
| Bei fehlendem Offline-Stand, fehlender Prüfung und veraltetem Stand gleichzeitig zeigt der Dienst den ungünstigsten Zustand, nicht drei Meldungen | R-17-37 | Zustandsmatrixtest |
| Ein Feldwert mit Trennzeichen erzeugt 0 veränderte Aufrufe; Fehlermeldungen enthalten 0 Pfadangaben und 0 Schlüsselreferenzen | R-17-38 | Eingabemutationstest und Musterprüfung der Meldungstexte |

## Offene Punkte

1. **Prüfsummenkette über die synchrone Replikation.** Die Blockprüfsumme des Dateisystems wirkt lokal unter jedem Replikat; die synchrone Replikation arbeitet darunter auf Blockebene und vergleicht keine Prüfsummen zwischen den Replikaten. Ein Vergleichslauf zwischen den Replikaten ist erforderlich, seine Kadenz, seine Dauer bei laufender Last und sein Verhalten bei einer festgestellten Abweichung — welches Replikat gewinnt — sind nicht entschieden. Solange das offen ist, ist die Aussage "Prüfsummen überall" für die Stufe "Synchron gespiegelt" nicht in voller Strenge erfüllt.
2. **Verweildauer als Richtlinienannahme.** Die gesamte Auslegung der Aufbewahrungsdauer hängt an einer Zahl, die das System nicht messen kann und die hier bewusst nicht aus der Literatur übernommen wird. Ein Betreiber, der 90 Tage annimmt und tatsächlich 200 Tage erlebt, hat eine Leiter, die formal greift, aber nur einen Monatsstand mit bis zu 30 Tagen Datenverlust liefert. Ob die Konsole eine Voreinstellung vorschlagen darf, ohne damit eine nicht belegbare Behauptung zu treffen, ist offen.
3. **Nachweis der Unveränderlichkeit bei fremden Ablagezielen.** Die Löschprobe zeigt, dass die Zugangsdaten des Schreibers nicht löschen können. Sie zeigt nicht, dass ein Betreiber des Ablageziels oder ein Angreifer mit dessen Rechten nicht löschen kann. Für ein Ziel, das der Kunde nicht selbst betreibt, bleibt die Unveränderlichkeit eine vertragliche Zusage. Ob Atrium diesen Unterschied im Schutzstatus abbilden soll — und wie, ohne eine nicht prüfbare Eigenschaft zu behaupten — ist nicht entschieden.
4. **Aufbewahrungspflichten gegen Löschpflichten.** Eine nur anhängende, unveränderliche Ablage mit 430 Tagen Horizont steht im Widerspruch zu einem Löschverlangen nach der DSGVO, das sich auf personenbezogene Daten in einem Dienst bezieht. Die Löschung eines einzelnen Datensatzes aus 34 aufbewahrten Ständen ist technisch nicht vorgesehen, und die Schlüsselvernichtung wirkt nur je Speicherbereich, nicht je Person. Welcher der beiden Wege gilt — Fristenablauf abwarten oder Speicherbereich neu aufbauen —, ist eine Entscheidung mit rechtlicher und technischer Seite und hier nicht getroffen; [Kapitel 22](22-compliance.md) muss sie aufgreifen.
5. **Das Auslagerungsziel als Einzelpunkt.** Der Entwurf setzt ein Auslagerungsziel je Mandant voraus. Fällt es aus oder verweigert es den Dienst, bleiben die Klassen "Lokal" und "Gespiegelt" ohne externe Kopie. Ob ein zweites, unabhängiges Ziel Pflicht wird — mit doppelten Kosten und doppelter Kapazität nach der Rechnung aus 17.4 — oder ob der Ausfall nur als degradierter Zustand angezeigt wird, ist nicht entschieden.
6. **Schutz der lesenden Zugangsdaten im Paket.** Ein geöffnetes Paket erlaubt das Lesen aller ausgelagerten Stände aller Mandanten. Der Besitz von k Anteilen ist damit gleichbedeutend mit dem Vollzugriff auf sämtliche Daten der Installation. Ob eine Trennung nach Mandanten sinnvoll ist — ein Paket je Mandant mit eigenen Anteilen, dafür n-fache Aufbewahrungslast und ein zusätzliches Paket für den installationsweiten Sollzustand — ist nicht untersucht.
7. **Erkennung gegen Falschalarm.** Die Schwellen (Faktor 5 bei der Änderungsrate, 30 % beim Kompressionsverhältnis) sind gesetzt, nicht aus Daten gewonnen. Ein zu empfindlicher Schwellenwert hält den Aufräumer dauerhaft an und lässt die Kapazität des Auslagerungsziels unbegrenzt wachsen; ein zu unempfindlicher erkennt nichts. Ein Verfahren, das die Schwelle je Speicherbereich aus dessen eigener Reihe lernt, ist naheliegend, aber weder spezifiziert noch gegen Manipulation durch einen langsam arbeitenden Angreifer abgesichert.
8. **Wiederherstellung über eine Schemagrenze.** Ein Sollzustandsexport mit älterer Hauptversion wird nach KANON 3 über eine ausdrückliche Migration importiert. Ein 430 Tage alter Stand kann zwei Hauptversionen zurückliegen, das Kompatibilitätsfenster umfasst aber nur eine. Ob alte Exporte beim Versionswechsel migriert und neu signiert werden — was ihre ursprüngliche Signatur entwertet — oder ob eine Wiederherstellungsumgebung mit der alten Kontrollebenenversion vorgehalten wird, ist offen.
9. **Kapazitätsplanung bei anhängenden Lasten.** Das Belegungsmodell nimmt einen konstanten Bestand an. Für Mail-, Protokoll- und Belegablagen wächst S, und die Formel unterschätzt den Bedarf. Ein Wachstumsterm ist einfach ergänzbar, aber die Wachstumsrate ist je Dienst verschieden und zum Zeitpunkt der Auslegung unbekannt. Wie die Konsole zwischen Modellwert vor der ersten Messung und fortgeschriebener Reihe danach umschaltet, ohne den Bediener mit zwei widersprüchlichen Zahlen zu konfrontieren, ist nicht ausgearbeitet.
10. **Zeitbedarf der Vollprobe gegen K-24.** Die Vollprobe soll nach K-24 höchstens 60 min dauern. Die Rechnung in 17.5 ergibt für einen Knoten mit 2.000 GB Dienstdaten aus dem Replikat 61,5 min und aus der Auslagerung 6,0 h. Die 60-Minuten-Vorgabe ist damit nur für kleine Knoten oder für eine Teilmenge der Daten haltbar. Ob K-24 als Probe des Kontrollebenenzustands zu lesen ist, deren Datenanteil die rotierende Datenprobe abdeckt, oder ob der Zielwert angehoben werden muss, ist im Kanon zu klären.
