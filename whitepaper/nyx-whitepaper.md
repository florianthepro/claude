# Nyx: Ein anonymes, dezentrales Nachrichtensystem mit ephemeren Transportschlüsseln

**Version 1.0**

---

## Abstract

Eine rein Peer-to-Peer basierte Form der Nachrichtenübermittlung würde es erlauben, Nachrichten direkt von einem Teilnehmer zum anderen zu senden, ohne den Umweg über einen Diensteanbieter. Digitale Signaturen und Ende-zu-Ende-Verschlüsselung lösen einen Teil des Problems, doch der wesentliche Vorteil geht verloren, wenn weiterhin ein vertrauenswürdiger Dritter benötigt wird, um Identitäten zu verwalten, Schlüssel zu verteilen und Nachrichten zwischenzuspeichern. Dieser Dritte sieht, wer mit wem kommuniziert, wann und wie oft — Informationen, die den Inhalt der Nachrichten oft entbehrlich machen. Wir schlagen eine Lösung für dieses Metadaten-Problem vor, die auf drei Bausteinen beruht: Identität ist ausschließlich ein Schlüsselpaar, ohne Telefonnummer und ohne Registrierung; die Verteilung und der Widerruf öffentlicher Schlüssel erfolgt über eine öffentliche, nur anhängbare Kette von Blöcken, die durch Rechenaufwand gesichert ist; und der Transport erfolgt über ein Mixnetz mit Schichtverschlüsselung, in dem kein einzelner Knoten Sender und Empfänger zugleich kennt. Jede einzelne Nachricht wird unter einem eigenen, temporären Schlüssel übertragen, der aus einer fortschreitenden Schlüsselkette abgeleitet und unmittelbar nach der Entschlüsselung auf dem Endgerät gelöscht wird. Das System ist sicher, solange ein Angreifer weniger als einen kritischen Anteil der Weiterleitungsknoten kontrolliert und die Endgeräte selbst nicht kompromittiert sind.

---

## 1. Einleitung

Nachrichtenübermittlung im Internet beruht heute fast ausschließlich auf Diensteanbietern als vertrauenswürdigen Dritten. Das Modell funktioniert für die meisten Anwendungsfälle hinreichend gut, leidet aber an den Schwächen jedes vertrauensbasierten Modells. Ende-zu-Ende-Verschlüsselung, wie sie inzwischen weit verbreitet ist, entzieht dem Anbieter den Inhalt der Nachrichten — aber nicht das soziale Diagramm. Der Anbieter weiß weiterhin, welche Kennung wann mit welcher anderen Kennung gesprochen hat, wie lange, wie oft, von welcher IP-Adresse und mit welchem Gerät. Diese Metadaten sind maschinenlesbar, langfristig speicherbar und in vielen Fällen aussagekräftiger als der Text selbst.

Hinzu kommt, dass der Anbieter in aller Regel auch das Schlüsselverzeichnis führt. Wer das Verzeichnis kontrolliert, kann bei einem Schlüsselaustausch einen eigenen Schlüssel unterschieben; die Ende-zu-Ende-Verschlüsselung schützt dann korrekt — nur eben gegen den Falschen. Der Nutzer hat keine Möglichkeit, dies zu bemerken, weil das Verzeichnis nicht öffentlich prüfbar ist. Die üblichen Gegenmittel, etwa der manuelle Abgleich von Sicherheitsnummern, werden praktisch nie angewendet.

Schließlich verlangt fast jedes verbreitete System eine dauerhafte, staatlich oder kommerziell rückverfolgbare Kennung — meist eine Telefonnummer. Damit ist die Anonymität bereits vor der ersten Nachricht aufgehoben, unabhängig von der Qualität der eingesetzten Kryptographie.

Was benötigt wird, ist ein System, in dem Identität nicht vergeben, sondern erzeugt wird; in dem das Schlüsselverzeichnis öffentlich prüfbar und nicht rückwirkend fälschbar ist; in dem der Transportweg keinem Beteiligten das Kommunikationspaar offenbart; und in dem der Schlüssel, unter dem eine Nachricht übertragen wurde, nach der Zustellung nirgends mehr existiert. Wir schlagen ein solches System vor.

---

## 2. Bedrohungsmodell

Wir definieren, wogegen das System schützt, und ebenso deutlich, wogegen nicht.

**Der Angreifer kann:**

- beliebig viele eigene Knoten in das Netz einbringen, solange die Kosten pro Knoten aufgebracht werden;
- den Verkehr an einer großen Zahl von Netzübergängen beobachten, aufzeichnen und langfristig auswerten;
- Nachrichten verzögern, verwerfen, duplizieren oder wiedereinspielen;
- das öffentliche Schlüsselverzeichnis vollständig lesen und selbst Einträge darin vornehmen;
- Speicherknoten beschlagnahmen und deren Datenträger auswerten;
- ein Endgerät zu einem späteren Zeitpunkt beschlagnahmen und dessen Schlüsselmaterial auslesen.

**Der Angreifer kann nicht:**

- die eingesetzten kryptographischen Primitive brechen;
- den gesamten Weltverkehr gleichzeitig mit vollständiger Zeitauflösung beobachten (ein globaler passiver Beobachter besiegt jedes praktikable Mixnetz mit geringer Latenz; siehe Abschnitt 11);
- ein Endgerät zum Zeitpunkt der Kommunikation kompromittieren, ohne dass dies die Vertraulichkeit der zu diesem Zeitpunkt laufenden Sitzung aufhebt.

**Schutzziele in absteigender Priorität:**

1. *Vertraulichkeit des Inhalts* — nur die Gesprächspartner lesen mit.
2. *Vorwärtsgeheimhaltung* — die spätere Beschlagnahme eines Geräts gibt frühere Nachrichten nicht preis.
3. *Unverkettbarkeit der Metadaten* — kein Beteiligter kann Sender und Empfänger einander zuordnen.
4. *Nichtzuordenbarkeit der Identität* — eine Adresse lässt sich ohne Zusatzwissen keiner Person zuordnen.
5. *Prüfbarkeit des Schlüsselverzeichnisses* — ein untergeschobener Schlüssel ist nachweisbar und öffentlich sichtbar.

---

## 3. Identität

Eine Identität ist ein Schlüsselpaar, nichts weiter. Der Teilnehmer erzeugt lokal einen Ed25519-Signaturschlüssel `(IK_priv, IK_pub)`. Seine Adresse ist

```
addr = base32( H(IK_pub) [0..19] || prüfsumme )
```

wobei `H` eine kryptographische Hashfunktion (BLAKE3) bezeichnet. Die Adresse ist damit rund 32 Zeichen lang, selbstzertifizierend und ohne Rückfrage bei irgendeiner Instanz überprüfbar: wer eine gültige Signatur unter der Adresse vorlegt, ist der Inhaber.

Es gibt keine Registrierung, keine Telefonnummer, keine E-Mail-Adresse, keinen Benutzernamen. Eine Identität entsteht in dem Moment, in dem der Zufallsgenerator sie erzeugt, und hört auf zu existieren, wenn der private Schlüssel gelöscht wird. Ein Teilnehmer kann beliebig viele Identitäten führen; nichts im Protokoll verknüpft sie miteinander. Für die meisten Anwendungsfälle empfehlen wir ausdrücklich, pro Kontext eine eigene Identität zu verwenden — die Kosten dafür sind praktisch null.

Der Identitätsschlüssel signiert ausschließlich Schlüsselankündigungen (Abschnitt 4). Er wird niemals zur Verschlüsselung von Nachrichten verwendet und verlässt niemals das Gerät.

---

## 4. Das Verzeichnis

### 4.1 Das Problem

Um einem Teilnehmer die erste Nachricht zu senden, muss man seinen aktuellen öffentlichen Schlüssel kennen. Fragt man einen Server danach, ist man wieder bei einem vertrauenswürdigen Dritten, der einen falschen Schlüssel liefern kann. Verteilt man Schlüssel ausschließlich außerhalb des Systems, ist es unbenutzbar.

Der entscheidende Punkt ist nicht, den Angriff unmöglich zu machen, sondern ihn *öffentlich und dauerhaft sichtbar* zu machen. Ein Angreifer, der einen Schlüssel unterschiebt, muss dafür einen Eintrag hinterlassen, den jeder Beobachter sehen und niemand nachträglich entfernen kann.

### 4.2 Aufbau

Wir verwenden eine nur anhängbare Kette von Blöcken. In die Kette gehen **keine Nachrichten** ein — nur Schlüsselereignisse. Es gibt genau vier Eintragsarten:

| Typ | Inhalt |
|---|---|
| `BIND` | Erstmalige Bindung: `addr`, `IK_pub`, Zeitstempel, Selbstsignatur |
| `PREKEY` | Signiertes Prekey-Bündel: `SPK_pub`, Signatur, Menge von `OPK_pub`, Gültigkeitsende |
| `REVOKE` | Widerruf eines Schlüssels, signiert mit `IK_priv` |
| `RECOVER` | Nachfolge-Identität, signiert mit `IK_priv` und dem Wiederherstellungsschlüssel |

Jeder Block enthält den Hash des Vorgängerblocks, eine Merkle-Wurzel über alle enthaltenen Einträge und einen Arbeitsnachweis. Die Kette wird von denselben Knoten geführt, die auch weiterleiten und speichern (Abschnitt 8). Die längste gültige Kette gilt, wie üblich, als die maßgebliche.

### 4.3 Warum eine Blockchain und nicht nur ein Transparenzprotokoll

Ein signiertes Transparenzprotokoll allein löst das Problem nicht, weil es einen Betreiber voraussetzt, der ein zweites, abweichendes Protokoll führen und dieses gezielt einzelnen Nutzern zeigen kann (*split view*). Die Erkennung erfordert dann einen Gossip-Mechanismus zwischen den Nutzern, der wiederum eigene Metadaten erzeugt.

Die Kette mit Arbeitsnachweis löst dies dadurch, dass eine abweichende Sicht nicht nur erzeugt, sondern gegen die gesamte übrige Rechenleistung des Netzes *dauerhaft fortgeschrieben* werden muss. Ein Angreifer, der einem Opfer eine gefälschte Prekey-Ankündigung zeigen will, muss ihm für die gesamte Dauer des Angriffs eine eigene, längere Kette vorspielen. Die Kosten sind damit nicht mehr die eines Datenbankschreibvorgangs, sondern die eines fortlaufenden Mehrheitsangriffs.

### 4.4 Privatsphäre des Verzeichnisses

Das Verzeichnis ist öffentlich. Es enthält Adressen — also Hashes von Schlüsseln — und Prekeys. Es enthält keine Namen, keine Kontaktlisten, keine Nachrichten und keine Angabe darüber, wer wen abgefragt hat. Aus dem Verzeichnis lässt sich ablesen, dass eine Identität *existiert*, und ungefähr, wann sie ihre Prekeys erneuert hat. Es lässt sich daraus **nicht** ablesen, mit wem sie kommuniziert.

Abfragen an das Verzeichnis erfolgen grundsätzlich über das Mixnetz (Abschnitt 6), damit kein Knoten das Interessenprofil eines Nutzers erstellen kann. Clients, die nicht die vollständige Kette vorhalten, verifizieren Einträge über Merkle-Pfade gegen die Blockköpfe; das genügt, um die Echtheit eines Prekey-Bündels zu prüfen, ohne die Menge aller Einträge abzurufen.

### 4.5 Kosten pro Eintrag

Um das Verzeichnis nicht mit erzeugten Identitäten fluten zu lassen, kostet jeder Eintrag einen kleinen clientseitigen Arbeitsnachweis oder eine Gebühr in der Netzwährung (Abschnitt 8). Der Betrag ist so bemessen, dass eine Handvoll Identitäten pro Nutzer kostenlos bleibt, Millionen jedoch teuer werden.

---

## 5. Ephemere Schlüssel

Dies ist der Kern des Systems. Kein Schlüssel, der eine Nachricht schützt, überlebt ihre Zustellung.

### 5.1 Sitzungsaufbau

Der Sender lädt das signierte Prekey-Bündel des Empfängers aus dem Verzeichnis und prüft die Signatur gegen dessen `IK_pub`. Er erzeugt ein ephemeres Schlüsselpaar `EK` und berechnet vier Diffie-Hellman-Werte über X25519:

```
DH1 = DH(IK_S , SPK_E)
DH2 = DH(EK_S , IK_E)
DH3 = DH(EK_S , SPK_E)
DH4 = DH(EK_S , OPK_E)        (falls ein Einmal-Prekey verfügbar war)

SK  = KDF( DH1 || DH2 || DH3 || DH4 )
```

Anschließend werden `DH1..DH4` und `EK_priv` sofort mit Null überschrieben. Der Empfänger rekonstruiert `SK` aus seinen privaten Schlüsseln und löscht den verbrauchten `OPK_priv` unwiderruflich; parallel dazu wird der zugehörige öffentliche Einmal-Prekey im Verzeichnis als verbraucht markiert und in der nächsten Ankündigung nicht mehr geführt.

### 5.2 Die Doppelratsche

Aus `SK` wird ein Wurzelschlüssel abgeleitet, der zwei Ketten speist:

- eine **symmetrische Kette**, die pro Nachricht einen neuen Nachrichtenschlüssel `MK_i` erzeugt: `CK_{i+1}, MK_i = KDF(CK_i)`;
- eine **Diffie-Hellman-Ratsche**, die bei jedem Sprecherwechsel ein neues ephemeres Schlüsselpaar einbringt und den Wurzelschlüssel erneuert.

Die symmetrische Kette ist eine Einwegfunktion. Aus `CK_{i+1}` lässt sich `CK_i` nicht zurückrechnen; wer den heutigen Zustand kennt, kann die Nachricht von gestern nicht entschlüsseln. Die DH-Ratsche sorgt dafür, dass ein Angreifer, der einmalig den Zustand ausliest, nach dem nächsten Sprecherwechsel wieder ausgesperrt ist. Das erste Merkmal ist Vorwärtsgeheimhaltung, das zweite Wiederherstellung nach Kompromittierung.

### 5.3 Löschung auf dem Endgerät

Wir spezifizieren die Löschung ausdrücklich als Teil des Protokolls und nicht als Implementierungsdetail:

1. Der Nachrichtenschlüssel `MK_i` wird **unmittelbar nach erfolgreicher Entschlüsselung und Authentifizierung** mit Null überschrieben. Er wird zu keinem Zeitpunkt persistiert. Zwischen Entschlüsselung und Löschung liegt keine Ein-/Ausgabeoperation.
2. Der alte Kettenschlüssel `CK_i` wird beim Vorwärtsschreiten der Kette überschrieben.
3. Private ephemere DH-Schlüssel werden nach Berechnung des gemeinsamen Geheimnisses überschrieben.
4. Verbrauchte Einmal-Prekeys werden gelöscht, nicht archiviert.
5. Der Klartext wird — sofern der Nutzer keine lokale Historie eingeschaltet hat — nur im Arbeitsspeicher gehalten und beim Schließen der Unterhaltung verworfen.
6. Schlüssel für Nachrichten, die außer der Reihe eintreffen (*skipped keys*), werden zwischengespeichert, aber unter einem harten Doppellimit: höchstens `N_max = 1000` Schlüssel je Sitzung und höchstens `T_max = 7 Tage` Alter. Wird eines der beiden überschritten, wird der Schlüssel gelöscht und die betreffende Nachricht ist dauerhaft unlesbar. Dies ist beabsichtigt: ein unbegrenzter Zwischenspeicher hebt die Vorwärtsgeheimhaltung faktisch auf.
7. Speicher für Schlüsselmaterial wird in Seiten alloziert, die vom Auslagern ausgenommen sind (`mlock`), und beim Freigeben überschrieben.

Die praktische Konsequenz: wird ein Gerät zwei Wochen nach einer Unterhaltung beschlagnahmt und vollständig ausgelesen, existiert der Schlüssel, mit dem diese Unterhaltung verschlüsselt war, weder auf dem Gerät noch beim Gegenüber noch bei einem Knoten des Netzes. Das Chiffrat, das der Angreifer gegebenenfalls aufgezeichnet hat, bleibt Rauschen.

### 5.4 Nachrichtenformat

```
Kopf (authentifiziert, nicht verschlüsselt):
  DH_pub          32 B      aktueller Ratschenschlüssel
  PN, N            8 B      Kettenzähler
  drop_tag        32 B      Zustelladresse, siehe 7.2

Rumpf:
  AEAD( MK_i ; kopf ; klartext || füllung )
```

Alle Pakete haben nach der Fragmentierung dieselbe Länge (Abschnitt 6.2). Der Kopf enthält keine Absender- und keine Empfängeradresse — nur einen pro Nachricht neu abgeleiteten Zustell-Tag.

---

## 6. Transport

### 6.1 Schichtverschlüsselung

Der Vorteil, den Darknets gegenüber dem offenen Netz bieten, ist nicht Verschlüsselung — die ist überall verfügbar — sondern die Trennung von Wissen. Wir übernehmen dieses Prinzip.

Der Sender wählt einen Pfad aus drei Knoten `n1 → n2 → n3` und verpackt die Nachricht in drei Verschlüsselungsschichten, jeweils an den öffentlichen Schlüssel des betreffenden Knotens. Jeder Knoten entfernt genau eine Schicht und erfährt dabei ausschließlich seinen Vorgänger und seinen Nachfolger:

| Knoten | weiß | weiß nicht |
|---|---|---|
| `n1` | die IP des Senders | Empfänger, Inhalt, Pfadlänge |
| `n2` | `n1` und `n3` | Sender, Empfänger, Inhalt |
| `n3` | den Zustellknoten | Sender, Inhalt |

Kein einzelner Knoten kennt Sender und Empfänger gleichzeitig. Um das Paar zu rekonstruieren, muss ein Angreifer den ersten und den letzten Knoten desselben Pfades kontrollieren.

Wir verwenden dafür ein Sphinx-artiges Paketformat: die Größe des Pakets bleibt über alle Schichten hinweg konstant, sodass ein Beobachter Pakete nicht anhand ihrer schrumpfenden Länge über die Hops hinweg verketten kann.

### 6.2 Gegen Verkehrsanalyse

Schichtverschlüsselung allein genügt nicht. Wer Ein- und Ausgang eines Knotens beobachtet, kann Pakete anhand von Größe und Zeitpunkt einander zuordnen. Dagegen:

- **Einheitliche Paketgröße.** Jedes Paket ist exakt 2 KiB groß. Kürzere Nachrichten werden aufgefüllt, längere fragmentiert. Die Größe einer Nachricht verrät nichts.
- **Mischen statt Weiterleiten.** Jeder Knoten hält eintreffende Pakete für eine exponentialverteilte Zeitspanne zurück und gibt sie in zufälliger Reihenfolge aus. Damit ist die Ausgangsreihenfolge unabhängig von der Eingangsreihenfolge.
- **Deckverkehr.** Jeder Client sendet in konstanter Rate Pakete, unabhängig davon, ob er etwas zu sagen hat; ist nichts zu senden, wird ein Füllpaket erzeugt, das an einem zufälligen Knoten verworfen wird. Ob ein Nutzer gerade kommuniziert, ist von außen nicht erkennbar. Dies kostet Bandbreite und ist der Preis für Metadatenschutz.
- **Eingangswächter.** Der erste Knoten eines Pfades wird nicht für jede Nachricht neu gewählt, sondern über einen längeren Zeitraum beibehalten. Die Begründung liefert die Rechnung in Abschnitt 10.

### 6.3 Latenz

Mischen erzeugt Verzögerung. Wir bieten zwei Betriebsarten: einen interaktiven Modus mit geringer Mischverzögerung (Sekundenbereich, schwächerer Schutz gegen Zeitkorrelation) und einen Depeschenmodus mit Verzögerungen im Minutenbereich und deutlich stärkerem Schutz. Die Wahl trifft der Nutzer je Unterhaltung. Wir halten es für ehrlicher, diesen Kompromiss sichtbar zu machen, als ihn zu verschweigen.

---

## 7. Zustellung an Abwesende

### 7.1 Das Problem

Endgeräte sind meistens offline. Ein rein synchrones P2P-System wäre unbenutzbar. Es braucht also Knoten, die Nachrichten zwischenspeichern — und genau diese Knoten sind im klassischen Modell der Ort, an dem alle Metadaten anfallen.

### 7.2 Blinde Ablagen

Nachrichten werden nicht an eine Identität adressiert, sondern an einen pro Nachricht neu abgeleiteten Tag:

```
drop_tag_i = HMAC( SK_tag , i )
```

wobei `SK_tag` ein aus der Sitzung abgeleitetes, nur den beiden Partnern bekanntes Geheimnis ist und `i` der Nachrichtenzähler. Zwei Tags derselben Unterhaltung sind für jeden Dritten nicht als zusammengehörig erkennbar — sie sehen aus wie unabhängige Zufallswerte.

Der Speicherknoten sieht: einen Zufallswert und 2 KiB Rauschen. Er kennt weder Sender noch Empfänger noch Zugehörigkeit zu einer Unterhaltung. Wird er beschlagnahmt, gibt sein Datenträger nichts preis als eine Menge nicht zuordenbarer Chiffrate.

### 7.3 Abholung

Der Empfänger berechnet die zu erwartenden Tags selbst — er kennt `SK_tag` und den Zähler — und fragt sie über das Mixnetz ab. Die Abfrage erfolgt in Sammelanfragen über einen Bereich von Tags, gemischt mit Fülltags, sodass der Speicherknoten nicht lernt, welcher Tag den Anfragenden tatsächlich interessiert.

Jede Ablage hat eine Verfallszeit (Standard: 7 Tage). Danach wird sie gelöscht, auch wenn sie nie abgeholt wurde. Nach bestätigter Abholung wird sie sofort gelöscht. Es gibt keine Sicherungskopie, kein Archiv und keine serverseitige Historie — an keiner Stelle des Systems.

---

## 8. Knoten und Anreize

Damit genügend Knoten existieren, müssen sie einen Grund haben zu existieren. Freiwilligkeit allein hat sich in bestehenden Netzen als tragfähig, aber knapp erwiesen.

Knoten erbringen zwei messbare Leistungen: Weiterleitung und Speicherung. Beide werden vergütet. Speicherung wird über regelmäßige Aufbewahrungsnachweise belegt: der Knoten muss auf eine Zufallsabfrage hin einen Merkle-Beweis über einen zufälligen Ausschnitt der bei ihm liegenden Daten liefern; kann er das nicht, verfällt sein Pfand.

Die Bezahlung selbst darf keine Metadaten erzeugen — eine nachvollziehbare Zahlung vom Nutzer an den Knoten würde genau das offenlegen, was das Mixnetz verbirgt. Wir verwenden daher blind signierte Gutscheine: der Nutzer erwirbt Gutscheine gegen die Netzwährung, lässt sie blind signieren und reicht sie beim Knoten ein. Der Aussteller kann die Einlösung nicht dem Erwerb zuordnen.

Der Beitritt eines Knotens erfordert ein Pfand. Das ist der Sybil-Schutz des Transportnetzes: er macht nicht unmöglich, viele Knoten zu betreiben, aber er macht es proportional teuer — und Abschnitt 10 zeigt, welcher Anteil an Knoten überhaupt gefährlich wird.

---

## 9. Gruppen

Gruppen werden nicht als Server-Objekt, sondern als kryptographischer Zustand aller Mitglieder geführt. Jedes Mitglied hält einen Baum von Schlüsseln; der Wurzelknoten ist der gemeinsame Gruppenschlüssel. Tritt jemand bei oder aus, wird der Pfad dieses Mitglieds im Baum neu geschlüsselt, was mit logarithmischem Aufwand einen neuen Wurzelschlüssel erzeugt.

Daraus folgt unmittelbar: wer eine Gruppe verlässt, kann spätere Nachrichten nicht mehr lesen; wer beitritt, kann frühere nicht lesen. Nachrichten an die Gruppe werden einmal unter dem Gruppenschlüssel verschlüsselt und über getrennte blinde Ablagen an jedes Mitglied zugestellt — getrennt deshalb, weil eine gemeinsame Ablage die Gruppenmitgliedschaft für den Speicherknoten sichtbar machen würde. Die Mitgliederliste existiert an keiner zentralen Stelle.

---

## 10. Berechnung

Wir betrachten den maßgeblichen Angriff auf die Anonymität: ein Angreifer kontrolliert einen Anteil `q` aller Weiterleitungsknoten und versucht, Sender und Empfänger einer Unterhaltung zu verknüpfen. Dazu muss er den ersten *und* den letzten Knoten desselben Pfades kontrollieren. Bei zufälliger Pfadwahl aus einer großen Knotenmenge:

```
P(ein Pfad vollständig beobachtbar) = q²
P(bei drei Hops mit Endpunktkorrelation) ≈ q²
```

Der mittlere Knoten schadet dem Angreifer nicht, hilft ihm aber auch nicht — entscheidend sind die Endpunkte. Für eine *einzelne* Nachricht ergibt das kleine Werte. Das eigentliche Problem entsteht über die Zeit: wählt der Client für jede der `n` Nachrichten einen neuen Pfad, gilt

```
P(mindestens einmal erfasst) = 1 − (1 − q²)^n
```

| `q` | n = 100 | n = 1 000 | n = 10 000 |
|---|---|---|---|
| 0,01 | 0,996 % | 9,5 % | 63,2 % |
| 0,05 | 22,2 % | 91,8 % | > 99,99 % |
| 0,10 | 63,4 % | 99,99 % | ≈ 1 |
| 0,20 | 98,3 % | ≈ 1 | ≈ 1 |

Das Ergebnis ist ernüchternd und für zufällige Pfadwahl grundlegend: bei ausreichend vielen Nachrichten wird *jeder* Nutzer irgendwann erfasst. Die Wahrscheinlichkeit konvergiert mit wachsendem `n` gegen Eins, unabhängig davon, wie klein `q` ist.

Die Gegenmaßnahme ist der Eingangswächter aus Abschnitt 6.2. Wird der erste Knoten nicht pro Nachricht, sondern einmal je Zeitraum gewählt und beibehalten, ändert sich die Struktur des Risikos grundlegend:

```
P(erfasst) = q · (1 − (1 − q)^n) ≤ q
```

Ist der Wächter ehrlich — was mit Wahrscheinlichkeit `1 − q` der Fall ist — wird der Nutzer während der gesamten Standzeit des Wächters *überhaupt nicht* erfasst, gleichgültig wie viele Nachrichten er sendet. Ist der Wächter feindlich, ist er es von Anfang an. Aus einer Gewissheit über die Zeit wird ein einmaliger Münzwurf:

| `q` | zufällige Pfade, n = 10 000 | mit Eingangswächter |
|---|---|---|
| 0,01 | 63,2 % | ≤ 1,0 % |
| 0,05 | > 99,99 % | ≤ 5,0 % |
| 0,10 | ≈ 1 | ≤ 10,0 % |
| 0,20 | ≈ 1 | ≤ 20,0 % |

Dies ist der wichtigste quantitative Befund dieses Entwurfs: die Sicherheit eines anonymen Netzes hängt weniger an der Stärke seiner Kryptographie als an der Frage, wie oft es dem Angreifer eine neue Gelegenheit gibt.

**Zum Verzeichnis** gilt eine analoge Überlegung. Ein untergeschobener Prekey muss in der Kette veröffentlicht werden. Spiegeln `k` unabhängige Beobachter die Kette und vergleichen ihre Blockköpfe, wird eine abweichende Sicht mit Wahrscheinlichkeit `1 − (1 − p)^k` entdeckt, wobei `p` die Wahrscheinlichkeit ist, dass ein einzelner Beobachter die betreffende Sicht zu sehen bekommt. Schon eine kleine Zahl unabhängiger Beobachter macht einen gezielten Schlüsselaustausch zu einem Angriff mit hoher Entdeckungswahrscheinlichkeit — und, anders als beim Verkehrsangriff, mit dauerhaftem Beweis.

---

## 11. Grenzen

Wir halten es für notwendig, die Grenzen ebenso deutlich zu benennen wie die Eigenschaften.

**Der globale passive Beobachter.** Wer den gesamten Netzverkehr an allen Ein- und Austrittspunkten gleichzeitig mit feiner Zeitauflösung beobachtet, kann auch bei konstanter Paketgröße und Mischverzögerung Korrelationen finden — insbesondere im interaktiven Modus. Kein Mixnetz mit geringer Latenz löst dieses Problem. Der Depeschenmodus mit hoher Verzögerung und durchgehendem Deckverkehr verschiebt die Grenze erheblich, hebt sie aber nicht auf.

**Das Endgerät.** Alles, was hier beschrieben wird, schützt Daten auf dem Weg. Ein kompromittiertes Endgerät liest mit, bevor verschlüsselt und nachdem entschlüsselt wurde. Die Löschung ephemerer Schlüssel begrenzt den Schaden auf den Zeitraum der Kompromittierung — das ist wertvoll, aber es ist kein Schutz gegen einen aktiven Angreifer auf dem Gerät selbst.

**Anonymität braucht Gesellschaft.** Die Anonymitätsmenge eines Nutzers ist die Menge der Nutzer, die er hätte sein können. In einem Netz mit hundert Teilnehmern ist sie unabhängig von der Kryptographie klein. Das System wird mit jedem Teilnehmer sicherer — und ist zu Beginn am schwächsten.

**Der Nutzer.** Wer über einen anonymen Kanal seinen Klarnamen nennt, hebt jede Eigenschaft dieses Entwurfs auf. Ebenso Schreibstil, Zeitmuster und wiederverwendete Identitäten über getrennte Kontexte hinweg. Das Protokoll kann Verkettung durch Metadaten verhindern, nicht Verkettung durch Inhalt.

**Verlorene Schlüssel.** Ohne zentrale Instanz gibt es keine Wiederherstellung im üblichen Sinn. Der Verlust des privaten Schlüssels bedeutet den Verlust der Identität. Der `RECOVER`-Eintrag erlaubt eine vorbereitete Nachfolge über einen getrennt aufbewahrten Wiederherstellungsschlüssel; wer das nicht vorbereitet hat, beginnt neu. Dies ist eine Eigenschaft des Modells, kein Fehler darin.

**Missbrauch.** Ein System ohne Betreiber hat keine Moderationsinstanz. Was es bietet, ist Vertraulichkeit der Kommunikation zwischen Menschen, die einander bereits kennen; was es nicht bietet, ist Reichweite gegenüber Fremden. Die Abwesenheit von Verzeichnissuche, öffentlichen Kanälen und Weiterleitung an Unbekannte ist insofern kein Mangel des Entwurfs, sondern eine bewusste Entscheidung.

---

## 12. Schlussfolgerung

Wir haben ein Nachrichtensystem vorgeschlagen, das ohne vertrauenswürdige Dritte auskommt. Identität ist ein selbst erzeugtes Schlüsselpaar ohne Bezug zu einer realen Person. Das Schlüsselverzeichnis ist eine öffentliche, durch Rechenaufwand gesicherte Kette, in der ein untergeschobener Schlüssel nicht heimlich, sondern nur öffentlich und dauerhaft nachweisbar erfolgen kann. Der Transport erfolgt über ein Mixnetz mit Schichtverschlüsselung, einheitlicher Paketgröße und Deckverkehr, in dem kein Knoten Sender und Empfänger zugleich kennt. Zwischengespeicherte Nachrichten liegen unter pro Nachricht neu abgeleiteten Tags, die für den Speicherknoten nicht als zusammengehörig erkennbar sind.

Der Kern ist die Behandlung der Schlüssel. Jede Nachricht wird unter einem eigenen, aus einer Einwegkette abgeleiteten Schlüssel übertragen, der auf dem Endgerät unmittelbar nach der Entschlüsselung überschrieben wird und danach nirgends mehr existiert — nicht beim Sender, nicht beim Empfänger, nicht im Netz. Die spätere Beschlagnahme eines Geräts oder eines Speicherknotens gibt aufgezeichnete Kommunikation nicht preis.

Die Sicherheit des Systems beruht nicht auf der Redlichkeit eines Betreibers, sondern darauf, dass ein Angreifer weniger als einen kritischen Anteil der Knoten kontrolliert — und, wie Abschnitt 10 zeigt, vor allem darauf, dem Angreifer möglichst wenige Gelegenheiten zu geben.

---

## Referenzen

1. S. Nakamoto, *Bitcoin: A Peer-to-Peer Electronic Cash System*, 2008.
2. M. Marlinspike, T. Perrin, *The X3DH Key Agreement Protocol*, Open Whisper Systems, 2016.
3. T. Perrin, M. Marlinspike, *The Double Ratchet Algorithm*, Open Whisper Systems, 2016.
4. R. Dingledine, N. Mathewson, P. Syverson, *Tor: The Second-Generation Onion Router*, USENIX Security, 2004.
5. D. Chaum, *Untraceable Electronic Mail, Return Addresses, and Digital Pseudonyms*, CACM 24(2), 1981.
6. G. Danezis, I. Goldberg, *Sphinx: A Compact and Provably Secure Mix Format*, IEEE S&P, 2009.
7. B. Laurie, A. Langley, E. Kasper, *Certificate Transparency*, RFC 6962, 2013.
8. R. Barnes et al., *The Messaging Layer Security (MLS) Protocol*, RFC 9420, 2023.
9. D. Chaum, *Blind Signatures for Untraceable Payments*, CRYPTO '82.
10. N. Borisov, G. Danezis, I. Goldberg, *DP5: A Private Presence Service*, PoPETs, 2015.
11. A. Juels, B. Kaliski, *PORs: Proofs of Retrievability for Large Files*, ACM CCS, 2007.
12. R. Dingledine, N. Mathewson, *Anonymity Loves Company: Usability and the Network Effect*, WEIS, 2006.
