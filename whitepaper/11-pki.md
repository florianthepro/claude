# 11 Interne PKI und Zertifikatsverwaltung

## 11.1 Aufbau der Hierarchie

Atrium betreibt eine dreistufige Zertifikatskette mit zwei betrieblich getrennten Stufen: die Wurzel-CA liegt außerhalb des laufenden Systems (INV-22), Ausgabe-CA und Mandanten-Zwischen-CAs liegen im laufenden System. Der Kanon nennt das eine zweistufige PKI, weil genau zwei Stufen online existieren; die Wurzel ist keine Betriebsstufe, sondern ein Vertrauensanker.

| Ebene | Betreff | Laufzeit | Signaturverfahren | Hashverfahren | Schlüsselablage | pathLenConstraint |
|---|---|---|---|---|---|---|
| Wurzel-CA | `CN=<Installationsname> Wurzel, O=<Installations-ULID>` | 15 a (K-13) | ECDSA über P-384 | SHA-384 (FIPS 180-4) | keine; existiert nur in Shamir-Anteilen | 2 |
| Ausgabe-CA | `CN=<Installationsname> Ausgabe <knoten-kurz>, O=<Installations-ULID>` | 5 a (K-13) | ECDSA über P-256 | SHA-256 (FIPS 180-4) | TPM 2.0 des Ausstellungsknotens, nicht exportierbar | 1 |
| Mandanten-Zwischen-CA | `CN=<Mandantenname> Zwischen, O=<Mandanten-ULID>` | 2 a, Nachfolger ab 8 Monaten Restlaufzeit | ECDSA über P-256 | SHA-256 | TPM 2.0 desselben Knotens | 0 |

Die Wahl von ECDSA statt Ed25519 für die X.509-Kette ist eine Entscheidung gegen die naheliegende Vereinheitlichung. Der Kanon legt Ed25519 (RFC 8032) für Lieferkettensignaturen fest, die ausschließlich Atrium selbst prüft. Die X.509-Kette prüfen dagegen Fremdprogramme: Browser, Mailclients, Gerätebetriebssysteme, RADIUS-Bittsteller und die im Katalog geführten Fremdprodukte. Deren Unterstützung für Ed25519 in Zertifikatsketten ist uneinheitlich, deren Unterstützung für ECDSA über P-256 ist es nicht. Der Preis ist ein zweites Signaturverfahren im System; der Ertrag ist, dass die Vertrauensprüfung nicht an der Bibliothek eines Fremdprodukts scheitert. P-384 an der Wurzel kostet nichts, weil die Wurzel über ihre gesamte Laufzeit annahmegemäß weniger als 20 Zertifikate signiert, und deckt den 15-Jahre-Horizont besser ab als P-256.

Die Laufzeit der Zwischen-CA von 2 a ist gesetzt, nicht aus dem Kanon abgeleitet. Sie folgt aus zwei Bedingungen: sie muss größer sein als die längste Endzertifikatslaufzeit plus deren Erneuerungsfenster (365 d + 120 d = 485 d ≈ 1,33 a), damit ein Gerätezertifikat nie länger gültig ist als sein Aussteller, und sie muss kleiner sein als die Laufzeit der Ausgabe-CA, damit der Mandantenschlüssel häufiger wechselt als der Installationsschlüssel. Der Nachfolger wird ab 8 Monaten Restlaufzeit erzeugt und parallel verteilt; 8 Monate sind mehr als die 120 d des größten Erneuerungsfensters und lassen damit Raum für einen vollständigen Umlauf aller Endzertifikate auf die neue Zwischen-CA.

## 11.2 Namensbeschränkungen als Erzwingung der Mandantentrennung

Jede Mandanten-Zwischen-CA trägt die Erweiterung Name Constraints nach RFC 5280, als kritisch markiert. Ohne sie wäre die Zwischen-CA je Mandant nur eine Buchhaltungsgrenze: ein kompromittierter Mandantenschlüssel könnte für jeden Namen der Installation ausstellen, und die Isolationsstufen M0 bis M3 wären kryptographisch bedeutungslos.

```
NameConstraints (kritisch) der Zwischen-CA von Mandant <M>:

permittedSubtrees:
  dNSName                   ".<mandant-kurz>.<basisdomaene>"
  dNSName                   je zugeordneter externer Domaene ein Eintrag
  rfc822Name                je Maildomaene des Mandanten ein Eintrag
  directoryName             "O=<Mandanten-ULID>"

excludedSubtrees:
  iPAddress                 0.0.0.0/0
  iPAddress                 ::/0
  uniformResourceIdentifier ""        // leerer Wirtsanteil trifft jeden URI
```

Die tragende Beschränkung ist `directoryName`. Jedes Endzertifikat führt die Mandanten-ULID als `organizationName` im Betreff; die Beschränkung auf diesen Teilbaum bewirkt, dass eine Zwischen-CA kein Zertifikat ausstellen kann, das einem anderen Mandanten zugeordnet ist, unabhängig davon, welche Namensform im Subject Alternative Name steht. Die Beschränkungen auf `dNSName` und `rfc822Name` decken zusätzlich die Namensformen ab, aus denen Erreichbarkeit und Mailidentität folgen.

Drei Schwächen entstehen an dieser Stelle und werden nicht verschwiegen. Erstens kennt RFC 5280 keine Beschränkungsform für `otherName`; ein Zertifikat mit einem `otherName`-Eintrag ist durch die Erweiterung nicht begrenzbar. Die Ausstellung lehnt deshalb jeden Antrag ab, der `otherName` enthält, und setzt Erweiterungen ausschließlich selbst — das ist eine Durchsetzung im Code, nicht in X.509, und sie schützt nur, solange der Code die einzige Ausstellungsstelle ist. Zweitens gilt die URI-Beschränkung nach RFC 5280 dem Wirtsanteil; ein URN ohne Wirtsanteil ist nicht beschränkbar, weshalb Objektverweise nicht über URI-SAN, sondern über den Betreff getragen werden. Drittens setzt die Wirkung voraus, dass der prüfende Client Name Constraints umsetzt. Die Erweiterung ist als kritisch markiert, sodass ein regelkonformer Client, der sie nicht versteht, die Kette ablehnen muss; ein nicht regelkonformer Client akzeptiert ein mandantenfremdes Zertifikat. Für Prüfstellen, die Atrium selbst betreibt (atrium-node, Eingang, RADIUS, siehe [Kapitel 12](12-dns-netzwerk.md)), ist die Umsetzung zugesichert und geprüft; für fremde Clients ist sie eine zusätzliche Verteidigungsschicht, keine Garantie.

Die Ausgabe-CA trägt keine Namensbeschränkung, weil sie Knotenzertifikate über alle Mandanten hinweg ausstellt. Ihre Beschränkung ist `pathLenConstraint = 1`: sie kann Zwischen-CAs signieren, aber keine Kette darunter verlängern.

## 11.3 Schlüsselzeremonie bei der Erstinstallation

Die Zeremonie läuft innerhalb der Erstinstallation und fügt der Ersteinrichtung nach K-01 keine Entscheidung hinzu: k und n sind durch eine Plattformrichtlinie vorbelegt, deren Quelle im Vorgangsprotokoll steht (INV-15). Der Bediener bestätigt Anteile, er wählt keine Parameter.

```
Ablauf (jeder Schritt erzeugt ein Auditereignis, alle Schritte gehoeren zu einem Vorgang):

 1. Netzschnittstellen aus, Auslagerungsspeicher aus, TPM 2.0 vorhanden geprueft
 2. Wurzelschluessel im Arbeitsspeicher erzeugen, Wurzelzertifikat selbst signieren
 3. Ausgabe-CA-Schluessel IM TPM erzeugen (nicht exportierbar), Antrag mit Wurzel signieren
 4. Zwischen-CA-Schluessel des ersten Mandanten im TPM erzeugen, mit Ausgabe-CA signieren
 5. Sperrliste der Wurzel mit langer Gueltigkeit signieren (die Wurzel ist danach offline)
 6. Wurzelschluessel nach Shamir in n Anteile teilen, je Anteil einen Pruefwert bilden
 7. Anteile einzeln ausgeben; je Anteil Bestaetigung durch Eingabe des gedruckten Pruefwerts
 8. Wurzelschluessel und alle Zwischenwerte im Arbeitsspeicher ueberschreiben
 9. Pruefprotokoll erzeugen, mit der Ausgabe-CA signieren, ausgeben
10. Netzschnittstellen an, Dienste starten
```

Schritt 7 ist der Punkt, an dem vergleichbare Verfahren scheitern. Eine Schaltfläche "Anteil gesichert" ist eine Behauptung des Bedieners. Stattdessen trägt jeder Anteilsträger einen Prüfwert, der aus dem Anteil und einem im System verbleibenden Schlüssel berechnet wird und selbst keine Information über den Anteil preisgibt. Der Bediener liest den Prüfwert vom Träger ab und gibt ihn ein; der Vergleich läuft laufzeitkonstant. Damit ist bestätigt, dass der Träger existiert und lesbar ist, nicht nur, dass jemand geklickt hat. Der Prüfwert ist auch das Mittel der späteren wiederkehrenden Prüfung (Abschnitt 11.11).

Der Träger eines Anteils ist ein bedruckter Bogen mit Anteilsnummer, Nutzlast in Crockford-Base32, Prüfzeichen, maschinenlesbarer Wiederholung derselben Zeichenfolge, Prüfwert und dem Fingerabdruck des Wurzelzertifikats. Steht kein Drucker zur Verfügung, schreibt die Zeremonie je Anteil auf einen getrennten Wechseldatenträger; die Anzeige eines Anteils auf dem Bildschirm zum Abfotografieren ist zugelassen und in der Konsole ausdrücklich als schwächeres Verfahren gekennzeichnet. Ein optionales Feld je Anteil nimmt den Aufbewahrungsort auf; zwei gleiche Ortsangaben erzeugen eine Warnung. Das Feld ist optional und zählt deshalb nicht gegen INV-14. Die Schwäche bleibt: ob fünf Bögen tatsächlich an fünf Orten liegen, ist technisch nicht prüfbar.

### Wahl von k und n

Zielwert: k = 3, n = 5. Die Begründung ist eine Rechnung mit zwei offengelegten Annahmen. Annahme: die Wahrscheinlichkeit, dass ein einzelner Anteil innerhalb eines Jahres verloren geht (Verlust, Vernichtung, unleserlich), beträgt q = 0,05. Annahme: die Wahrscheinlichkeit, dass ein einzelner Anteil innerhalb eines Jahres in fremde Hand gerät, beträgt p = 0,02. Beide Annahmen sind gesetzt, nicht gemessen; die Ordnung der Ergebnisse ist gegenüber ihrer genauen Höhe unempfindlich.

Der Schlüssel ist unwiederbringlich, sobald mehr als n − k Anteile verloren sind. Für 3-von-5 sind das drei oder mehr Verluste:

```
P_verlust(3-von-5) = C(5,3)·q³·(1-q)² + C(5,4)·q⁴·(1-q) + q⁵
                   = 10·1,25e-4·0,9025 + 5·6,25e-6·0,95 + 3,125e-7
                   = 1,12813e-3 + 2,96875e-5 + 3,125e-7
                   = 1,158e-3  je Jahr

P_verlust(2-von-3) = C(3,2)·q²·(1-q) + q³
                   = 3·2,5e-3·0,95 + 1,25e-4 = 7,25e-3  je Jahr
```

Der Schlüssel ist kompromittiert, sobald k Anteile in fremder Hand sind:

```
P_komp(3-von-5) = C(5,3)·p³·(1-p)² + C(5,4)·p⁴·(1-p) + p⁵
                = 10·8e-6·0,9604 + 5·1,6e-7·0,98 + 3,2e-9 = 7,76e-5  je Jahr

P_komp(2-von-3) = C(3,2)·p²·(1-p) + p³ = 3·4e-4·0,98 + 8e-6 = 1,184e-3  je Jahr
```

3-von-5 ist gegenüber 2-von-3 um den Faktor 7,25e-3 / 1,158e-3 = 6,3 verlustsicherer und um den Faktor 1,184e-3 / 7,76e-5 = 15,3 kompromisssicherer. Der Vergleich mit 4-von-7 fällt rechnerisch noch günstiger aus (P_verlust = 1,94e-4, P_komp = 5,34e-6), scheitert aber an der Organisation: sieben unabhängige, erreichbare und vertrauenswürdige Aufbewahrungsorte existieren in Installationen der Zielgröße annahmegemäß nicht. 3-von-5 ist der Standard, 4-von-7 ist per Richtlinie einstellbar, 2-von-3 ist zugelassen und wird dauerhaft als verminderte Sicherung angezeigt (INV-18). Das ist ein Modell, keine Messung; es ordnet die Alternativen, es sagt keine Ausfallrate vorher.

### TPM-Bindung der Ausgabe-CA und ihre Kosten

Der Schlüssel der Ausgabe-CA entsteht im TPM 2.0 und verlässt es nie (INV-20). Die Nutzung ist an eine Richtlinie gebunden, die den Zustand des Systemabbilds einschließt: Secure Boot, der Wurzelhashwert des dm-verity-Abbilds und die Messung von atrium-core. Ein naiv an feste Messwerte gesiegelter Schlüssel wäre nach jeder Abbildaktualisierung unbenutzbar, weil das A/B-Verfahren nach [Kapitel 21](21-betrieb-updates.md) die Messwerte verändert. Die Richtlinie ist deshalb nicht an Werte, sondern an eine mit dem Freigabeschlüssel signierte Richtlinienaussage gebunden: ein Abbild, das ohnehin signiert ausgeliefert wurde, erfüllt sie. Damit entsteht kein neues Vertrauen — der Freigabeschlüssel kontrolliert bereits, welcher Code läuft, und dieser Code betreibt die CA.

Zwei Folgen sind hart. Erstens ist ein Knoten ohne TPM 2.0 nicht als Ausstellungsstelle geeignet; da der Ankerknoten die Ausgabe-CA trägt, ist TPM 2.0 eine Installationsvoraussetzung für ihn und nicht eine Empfehlung. Zweitens ist der Schlüssel nicht sicherbar: der Verlust des Knotens ist der Verlust des Schlüssels. Der Kanon verbietet die Replikation privater Schlüssel (INV-20), also ist die Verfügbarkeit der Ausstellung nicht durch Replikation herstellbar. Sie wird stattdessen durch Zeit hergestellt; das ist der Gegenstand von Abschnitt 11.6.

### Prüfprotokoll

Das Prüfprotokoll ist ein signiertes Objekt, das kein Schlüsselmaterial enthält und deshalb gesichert werden darf, ohne INV-22 zu verletzen. Es enthält Zeitpunkt und Dauer der Zeremonie, den Fingerabdruck des Wurzelzertifikats, den Fingerabdruck der Ausgabe-CA, den Hashwert des Installationsabbilds, die Verfahrenskennungen, die Anteilsnummern mit ihren Prüfwerten und die handelnde Person. Es ist gegen die verteilte Kette nachprüfbar: der aus dem ausgerollten Wurzelzertifikat neu berechnete Fingerabdruck muss dem Wert im Protokoll entsprechen. Damit ist die Zeremonie ein Nachweis und nicht eine Erinnerung.

## 11.4 Ausstellungsprofile

| Zwecktyp | Namen im Zertifikat | Schlüsselverwendung | Erweiterte Schlüsselverwendung | Laufzeit | Erneuerung ab | Ausstellungsweg | Schlüsselablage |
|---|---|---|---|---|---|---|---|
| Dienstzertifikat | SAN `dNSName` aus der Veröffentlichung; Betreff `O=<Mandanten-ULID>` | `digitalSignature` | `serverAuth` | 90 d | 30 d Restlaufzeit | ACME intern (RFC 8555), dns-01 | Dateisystem des Dienstes, Schlüssel lokal erzeugt |
| Knotenzertifikat | SAN `dNSName` `<knoten>.knoten.<basisdomäne>`; Betreff `O=<Installations-ULID>` | `digitalSignature` | `serverAuth`, `clientAuth` | 90 d | 30 d Restlaufzeit | direkt durch die Kontrollebene im Kopplungskanal | TPM 2.0 des Knotens |
| Gerätezertifikat | SAN `dNSName` `<gerät>.geraete.<basisdomäne>`; Betreff `CN=<Geräte-ULID>, O=<Mandanten-ULID>` | `digitalSignature` | `clientAuth` | 365 d | 120 d Restlaufzeit | EST (RFC 7030) | TPM oder Sicherheitselement des Geräts, sonst Betriebssystemspeicher |
| Nutzerzertifikat | SAN `rfc822Name` der primären Mailadresse; Betreff `CN=<Personen-ULID>, O=<Mandanten-ULID>` | `digitalSignature` | `clientAuth`, `emailProtection` | 180 d | 60 d Restlaufzeit | EST auf dem verwalteten Gerät | Schlüsselspeicher des verwalteten Geräts |
| Konnektor- und Dienstkontozertifikat | Betreff `CN=<Bindungs-ULID>, O=<Mandanten-ULID>` | `digitalSignature` | `clientAuth` | 7 d | bei jeder Aktivierung, wenn Restlaufzeit < 2/3 | direkt durch die Kontrollebene über den lokalen Socket | Arbeitsspeicher des Konnektorprozesses |

Aus einem Zertifikatsantrag übernimmt die CA ausschließlich den öffentlichen Schlüssel und den Besitznachweis. Jeder Name, jede Erweiterung, jede Gültigkeit und jede Verwendungsangabe stammt aus dem Objektgraphen und dem Profil. Ein Antrag, der Erweiterungen enthält, wird nicht bereinigt, sondern abgelehnt; ein Antragsteller, der Namen wählen darf, hebt die Namensbeschränkung aus Abschnitt 11.2 faktisch auf. Diese Regel ist die wichtigste einzelne Sicherheitsregel der Ausstellung.

Das Nutzerzertifikat ist gerätegebunden und existiert nur auf verwalteten Geräten. Ein Export als PKCS#12 wird nicht angeboten, weil er einen privaten Schlüssel über einen Bedienpfad transportieren würde (INV-20). Die Folge ist eine benannte Lücke: auf einem nicht verwalteten Gerät gibt es kein Nutzerzertifikat und damit keine zertifikatsgebundene Anmeldung; dort trägt die Anmeldung nach [Kapitel 10](10-identitaet.md) über Passkey. Für S/MIME gilt zusätzlich, dass ein intern ausgestelltes Zertifikat außerhalb der Installation von keinem Empfänger geprüft werden kann; es ist innerhalb des Mandanten nützlich und außerhalb wertlos (siehe [Kapitel 14](14-mail.md)).

## 11.5 Ausstellungswege

| Weg | Gegenstand | Authentisierung des Antragstellers | Warum dieser Weg |
|---|---|---|---|
| ACME intern, Port 8406 | Dienstzertifikate | ACME-Konto, an die Objektkennung der Veröffentlichung gebunden | Unveränderte Clientimplementierungen; derselbe Codepfad wie für öffentliche Zertifikate |
| ACME gegen eine öffentliche CA | externe Namen | Kontoschlüssel der Installation, dns-01 gegen die eigene autoritative Zone | Atrium ist selbst DNS-Autorität; kein Zugangsdatum zu einem fremden DNS-Anbieter nötig |
| EST, über den Eingang veröffentlicht | Geräte- und Nutzerzertifikate | Erstausstellung mit einmaligem Registriergeheimnis am Geräteobjekt; Erneuerung mit dem vorhandenen Zertifikat | Geräteseitige Bibliotheken sprechen EST; Erneuerung ohne Bedienereingriff |
| direkt durch die Kontrollebene | Knoten-, Konnektor- und Dienstkontozertifikate | bereits authentisierter Kanal (SPAKE2-Kopplung bzw. lokaler Socket) | Kein Protokoll nötig, wo der Kanal schon authentisiert ist |

Intern wird ausschließlich die dns-01-Prüfung benutzt. http-01 verlangte Port 80 in internen Zonen und widerspräche der Default-Deny-Vorgabe (INV-10); tls-alpn-01 verlangte, dass der Eingang den TLS-Handschlag für einen Namen abgibt, den er bereits bedient. dns-01 ist im internen Fall streng genommen ein Umweg, weil die Kontrollebene den Namen ohnehin aus dem Sollzustand kennt und den TXT-Eintrag selbst setzt. Der Umweg wird bewusst in Kauf genommen: er hält den internen und den öffentlichen Ausstellungspfad identisch, sodass ein Fehler im Pfad intern auffällt, bevor er extern zuschlägt.

Die in RFC 7030, Abschnitt 4.4 optional vorgesehene serverseitige Schlüsselerzeugung (`/serverkeygen`) wird von Atrium nicht erbracht; entsprechende Aufrufe werden abgelehnt. Der Grund ist INV-20: ein privater Schlüssel entsteht dort, wo er verwendet wird, und wird nicht über eine Schnittstelle übertragen. Das Registriergeheimnis der Erstausstellung ist einmalig, höchstens 24 h gültig und nach 5 Fehlversuchen vernichtet; es ist an genau ein Geräteobjekt gebunden. Die Erneuerung authentisiert sich mit dem vorhandenen Zertifikat, sodass ein Gerät nach der Erstregistrierung ohne jedes Geheimnis auskommt. Einzelheiten der Geräteregistrierung stehen in [Kapitel 13](13-geraeteverwaltung.md).

Ein manueller Weg existiert nicht: es gibt keinen Dialog zum Hochladen eines Zertifikats, keinen zum Erzeugen eines Antrags von Hand und keinen zum Signieren eines fremden Antrags. Der Grund ist nicht Bequemlichkeit, sondern INV-09 und INV-02. Ein von Hand eingespieltes Zertifikat hätte kein Quellobjekt; daraus folgt: keine Erneuerung, kein Sperrauslöser beim Löschen des Inhabers, kein Eintrag in der Ablaufübersicht des Überblicks. Der abgelaufene, von Hand installierte Schlüssel ist die häufigste vermeidbare Störungsursache im Serverbetrieb, und ein Produkt, das ihn ermöglicht, hat die Ursache nicht beseitigt, sondern nur die Oberfläche darüber gelegt. Die Grenze dieser Strenge ist ebenso klar: ein Fremdprodukt, das ein Zertifikat in einem Format oder an einem Ort erwartet, den die Kontrollebene nicht schreiben kann, ist nicht integrierbar; sein Katalogeintrag wird nicht freigegeben, statt eine Ausnahme zu eröffnen (INV-30, siehe [Kapitel 15](15-dienste-software.md)).

## 11.6 Erneuerungsmathematik

Die Regel ist einheitlich: Erneuerung beginnt, wenn zwei Drittel der Laufzeit verstrichen sind. Daraus folgt ein Erneuerungsfenster F = L/3.

```
Dienst- und Knotenzertifikat
  L = 90 d, F = L/3 = 30 d = 720 h
  Versuchsintervall 1 h, versetzt um einen aus der Seriennummer abgeleiteten Offset
  Versuche vor Ablauf N = 720 / 1 = 720

Geraetezertifikat
  L = 365 d, zwei Drittel = 243,3 d verstrichen, Rest 121,7 d
  K-13 rundet auf F = 120 d
  Annahme: Geraet eingeschaltet 8 h an 5 von 7 Tagen
    Anteil a = (8/24)·(5/7) = 0,2381
  Eingeschaltete Stunden im Fenster: 120·24·0,2381 = 685,7 h
  Versuch je 6 h eingeschalteter Zeit plus je Start: N = 685,7 / 6 = 114
```

Das Ausfallbudget ergibt sich nicht aus der Versuchszahl, sondern aus dem Fenster. Im eingeschwungenen Betrieb erneuert jedes Dienstzertifikat innerhalb einer Stunde, nachdem es das Fenster erreicht hat; folglich hat zu jedem Zeitpunkt kein Dienstzertifikat weniger als 30 d − 1 h Restlaufzeit. Fällt die Ausstellung zu einem beliebigen Zeitpunkt vollständig aus, läuft das erste Zertifikat also nach 30 d − 1 h ab. Zielwert für die zulässige Ausstellungsunterbrechung: 29 d. Die Warnschwellen aus K-13 liegen genau in diesem Budget: die Warnung bei 15 d Restlaufzeit erscheint nach 15 Tagen Ausfall, die Eskalation bei 7 d nach 23 Tagen. Der Bediener hat damit zwei Wochen Vorlauf, bevor der erste Dienst unerreichbar wird.

Die Versuchszahl von 720 ist gegen einen zusammenhängenden Ausfall wertlos, weil die Versuche vollständig korreliert sind: fällt die CA aus, scheitern alle 720. Sie ist gegen sporadische Fehler wirksam. Annahme: ein einzelner Versuch scheitert unabhängig mit 10 %. Dann scheitern alle 720 mit 0,1^720, einer Zahl ohne praktische Bedeutung. Die richtige Lesart ist deshalb: das Fenster deckt Ausfälle, die Versuchszahl deckt Störungen.

Für Geräte ist das Budget die Offline-Zeit: ein Gerät, das 120 d am Stück ausgeschaltet oder außerhalb des Netzes ist, verliert sein Zertifikat und muss neu registriert werden. Annahme: Abwesenheiten von Geräten überschreiten 60 d selten; das Fenster deckt diesen Fall mit Faktor 2. Der Fall darüber wird nicht wegdefiniert: die Geräteliste zeigt "seit N Tagen nicht gemeldet" und hebt ab 60 d hervor, und die Neuregistrierung ist ein einzelner Vorgang mit einem neuen Registriergeheimnis.

### Ausstellungsvolumen und Nachlauf

Grundlage ist die mittlere Installation aus K-12 und K-13: 500 veröffentlichte Namen, 800 Geräte, 32 Knoten, 160 Konnektorbindungen (20 Mandanten × 8 Bindungen nach K-20).

```
Dienstzertifikate        500 · 365/90  = 2.028 /a
Knotenzertifikate         32 · 365/90  =   130 /a
Geraetezertifikate       800 · 365/365 =   800 /a
Nutzerzertifikate        800 · 365/180 = 1.622 /a
Konnektorzertifikate     160 · 365/7   = 8.343 /a
                                        --------
Summe                                   12.923 /a = 35,4 /d = 1,47 /h im Mittel
```

Die kurzlebigen Konnektorzertifikate dominieren das Volumen. Sie erzeugen trotzdem keine Objektflut, weil je Bindung höchstens ein gültiges Zertifikat existiert: die Neuausstellung ersetzt das Objekt, sie fügt keines hinzu. Die Zahl gleichzeitig gültiger Zertifikatsobjekte beträgt 500 + 32 + 800 + 800 + 160 = 2.292; bei durchschnittlich 2 KB je Objekt (Annahme aus K-12) sind das 4,6 MB, also rund 9 % des Zielwerts von 50 MB für den gesamten Sollzustand. Zertifikate sind damit nach den DNS-Einträgen die zweitgrößte Klasse abgeleiteter Artefakte. Jede Ausstellung ist zusätzlich ein Eintrag im replizierten Protokoll (INV-22) und ein Auditereignis (INV-23); bei Annahme 300 B je Eintrag sind das 12.923 · 300 B = 3,9 MB im Jahr, im Protokoll durch Momentaufnahmen beschnitten, im Auditstrom über 12 Monate aufbewahrt (K-25).

Nach einer Unterbrechung von 29 d stehen 35,4 /d · 29 = 1.027 Erneuerungen gleichzeitig an. Jede Ausstellung ist nach INV-22 ein Eintrag im replizierten Protokoll, also ein Konsensschreibvorgang; maßgeblich ist damit nicht die Bedienrate des Lesepfads, sondern μ_schreib = 50 bestätigte Änderungen je Sekunde aus [Kapitel 08](08-kontrollebene.md). Der Nachlauf dauert 1.027 / 50 = 20,5 s und bleibt damit im Zielwert von 60 s aus dem Akzeptanzkriterium zu R-11-17. Der Rückstau ist also kein Problem der CA, sondern eines der Gleichzeitigkeit: damit nicht alle Clients zur selben Minute anfragen, leitet jeder Erneuerungsversuch seinen Zeitversatz innerhalb der Stunde deterministisch aus der Seriennummer ab.

## 11.7 Sperrung

Atrium betreibt eine Sperrliste nach RFC 5280 und einen Statusdienst nach RFC 6960 auf Port 8407. Beide richten sich an fremde Prüfstellen. Für die eigenen Prüfstellen gilt ein anderes, strengeres Verfahren.

**Entscheidung: Positivliste statt Sperrliste an eigenen Prüfstellen.** Weil Atrium sowohl die CA als auch die Prüfstellen betreibt, verteilt die Kontrollebene die Menge der gültig ausgestellten Seriennummern als Teil des Sollzustands. Eine Prüfstelle akzeptiert ein Zertifikat, wenn seine Seriennummer in dieser Menge steht, und nicht, wenn sie nicht in einer Sperrliste steht. Umfang: 2.292 Seriennummern à 16 B = 36,7 KB, also verteilbar in jedem Abgleich. Der Gewinn ist doppelt: ein Zertifikat, das die CA signiert hat, ohne dass die Ausstellung im replizierten Protokoll steht, wird abgelehnt — ein stiller Missbrauch des CA-Schlüssels wird damit an der Prüfstelle wirkungslos. Und der Sperrvorgang wirkt in der Zeit, die ein Sollzustandsabgleich braucht, nicht in der Zeit, die ein Sperrlistenintervall braucht.

| Prüfstelle | Verfahren | Zielwert bis Wirksamkeit |
|---|---|---|
| Eingang, atrium-node, RADIUS, interne Dienste | Positivliste aus dem Sollzustand, Hard-Fail bei fehlender Seriennummer | p95 ≤ 10 s |
| Fremdsoftware innerhalb der Installation | Sperrliste, `nextUpdate` 6 h, Neuveröffentlichung stündlich | ≤ 6 h |
| Fremdsoftware mit Statusabfrage | OCSP-Antwort 1 h gültig, Heftung durch den Eingang, Auffrischung ab 30 min Restgültigkeit | ≤ 1 h |

Größe der Sperrliste: im pathologischen Fall aller gleichzeitig gesperrten Zertifikate 2.292 Einträge à 40 B = 92 KB. Im erwarteten Fall — Annahme 2 % Sperrquote im Jahr, mittlere Verweildauer eines Eintrags 60 d — sind es 12.923 · 0,02 · 60/365 = 42 Einträge = 1,7 KB. Abgelaufene Zertifikate werden aus der Liste entfernt, sodass sie nicht wächst.

**Hard-Fail gegen Soft-Fail.** Die übliche Begründung für Soft-Fail ist, dass eine Statusabfrage über das Netz fehlschlagen kann und dann jeder Verbindungsaufbau scheitert. Diese Begründung greift hier nicht, weil die eigenen Prüfstellen nichts abfragen: die Liste liegt lokal. Deshalb gilt Hard-Fail gegen den Listeninhalt. Für die Aktualität der Liste gilt dagegen bewusst nicht Hard-Fail: ein Knoten ohne Verbindung zur Kontrollebene behält seine letzte Liste und lehnt damit nicht plötzlich gültige Zertifikate ab, weil das INV-25 verletzen würde — Dienste überleben die Kontrollebene. Er zeigt stattdessen den benannten Zustand "Sperrliste veraltet seit <Zeit>" (INV-18). Das ist ein bewusst in Kauf genommenes Fenster: ein während der Abschottung gesperrtes Gerät wird von diesem Knoten weiter akzeptiert. Die Alternative — Abschottung des Knotens nach Ablauf der Listenfrist — würde einen Netzfehler in einen Dienstausfall übersetzen und wird verworfen.

Kurze Laufzeiten ersetzen die Sperrung weitgehend, aber nicht vollständig. Bei 7 d Laufzeit eines Konnektorzertifikats ist die Sperrung praktisch bedeutungslos; bei 365 d Laufzeit eines Gerätezertifikats ist sie der einzige wirksame Hebel, und genau dort ist der prüfende Punkt (RADIUS, Eingang) einer, den Atrium selbst betreibt. Die Kombination ist tragfähig: lange Laufzeiten nur dort, wo die Positivliste greift.

## 11.8 Vertrauensverteilung

Das Wurzelzertifikat gelangt über die Geräteverwaltung in den Vertrauensspeicher verwalteter Geräte; die Mechanik je Betriebssystem steht in [Kapitel 13](13-geraeteverwaltung.md). Wo die Plattform eine Einschränkung des Vertrauensankers auf einen Namensraum zulässt, wird sie gesetzt; wo nicht, bleibt die Namensbeschränkung in der Zwischen-CA die einzige Grenze.

Nicht verwaltete Geräte bekommen das Wurzelzertifikat nicht. Die Konsole bietet keinen Download mit Installationsanleitung an. Der Grund ist, dass eine Anleitung zum Einbau eines fremden Vertrauensankers genau die Handlung einübt, die Angreifer benötigen, und dass ein so eingebauter Anker auf einem privaten Gerät nach dem Ende des Arbeitsverhältnisses bestehen bleibt. Die Folge ist eine Entwurfsregel: ein Dienst, der für nicht verwaltete Geräte erreichbar sein soll, wird extern veröffentlicht und trägt ein Zertifikat einer öffentlichen CA. Eine Veröffentlichung trägt genau ein Zertifikat; wer denselben Dienst beiden Kreisen anbieten will, legt zwei Veröffentlichungen an, eine interne und eine externe. Das ist zusätzlicher Aufwand und wird als solcher benannt.

Die interne Wurzel gehört aus drei Gründen nie in öffentliche Vertrauensspeicher. Erstens würde sie damit für jeden Namen weltweit gültig, und die Namensbeschränkung wird nicht von jedem Client durchgesetzt. Zweitens setzt öffentliche Vertrauenswürdigkeit ein Prüf- und Aufsichtsregime voraus, das eine je Kunde erzeugte Wurzel nicht durchläuft. Drittens müsste jede Ausstellung in Certificate-Transparency-Protokolle eingetragen werden, womit jeder interne Name öffentlich würde.

Der Wechsel des Vertrauensankers nach 15 a wird vorbereitet, nicht improvisiert: der Nachfolger wird in einer Zeremonie 3 a vor Ablauf erzeugt, von der alten Wurzel zusätzlich quersigniert und ab diesem Zeitpunkt parallel an verwaltete Geräte verteilt. Ein Gerät, das in diesen 3 a nie erreicht wird, verliert das Vertrauen; das betrifft genau die Geräte, die ohnehin außerhalb der Verwaltung stehen.

## 11.9 Öffentliche Zertifikate

Externe Namen erhalten Zertifikate einer öffentlichen CA über ACME (RFC 8555) mit dns-01. Weil Atrium selbst DNS-Autorität ist ([Kapitel 12](12-dns-netzwerk.md)), wird dafür kein Zugangsdatum zu einem fremden DNS-Anbieter hinterlegt — ein Geheimnis weniger im System. Die CAA-Einträge nach RFC 8659 sind abgeleitete Artefakte der externen Domäne (INV-09) und begrenzen die Ausstellung auf die gewählte CA sowie über die Kontobindung auf das Konto dieser Installation. Ein Ausstellungsversuch über eine andere CA scheitert dadurch an der CA selbst, nicht erst an einer Entdeckung im Nachhinein.

Ratengrenzen öffentlicher CAs sind anbieterabhängig und veränderlich; konkrete Werte werden hier nicht genannt, weil jede genannte Zahl eine Erfindung wäre. Festgelegt wird das Verhalten: die Installation führt je Registrierdomäne einen Zähler ausgestellter Zertifikate über ein gleitendes Zeitfenster; das Budget wird bei der Einrichtung aus den vom Anbieter veröffentlichten Werten gesetzt und nicht im Code fest verdrahtet; eine Ablehnung wegen Ratengrenze wird als geplante Wiederholung mit der vom Anbieter genannten Wartezeit behandelt und nie als Fehlerschleife; ein erschöpftes Budget ist ein benannter Zustand am betroffenen Objekt (INV-12), keine stille Verzögerung.

Eine Bündelung vieler Namen in ein Zertifikat würde die Zahl der Anfragen senken — 500 externe Namen zu je 100 Namen ergäben 5 Zertifikate und 5 · 365/90 = 20,3 Ausstellungen im Jahr statt 2.028. Sie wird verworfen. Ein gebündeltes Zertifikat koppelt unabhängige Veröffentlichungen: ein nicht auflösbarer Name verhindert die Erneuerung für alle anderen, und ein Teilfehler ist keinem Objekt mehr zuzuordnen (INV-12). Der Preis der Entscheidung ist ein um den Faktor 100 höheres Ausstellungsvolumen gegenüber der Bündelung, und ob es in die Grenzen eines bestimmten Anbieters passt, ist bei der Einrichtung zu prüfen und nicht zuzusichern.

Jedes öffentliche Zertifikat erscheint in Certificate-Transparency-Protokollen. Daraus folgt eine Regel ohne Ausnahme: eine Veröffentlichung mit Sichtbarkeit "intern" erhält immer ein internes Zertifikat, eine mit Sichtbarkeit "extern" immer ein öffentliches. Interne Namen erscheinen deshalb nie in einem öffentlichen Protokoll. Wildcard-Zertifikate würden die Einzelnamen verbergen, verlangen aber, dass sich mehrere Dienste einen privaten Schlüssel teilen; das verstößt gegen INV-20 und wird verworfen. Der verbleibende Preis ist real: jeder extern veröffentlichte Hostname ist öffentlich bekannt, und bei der Namensform `<dienst>.<mandant>.<basisdomäne>` wird damit auch die Kundenbeziehung öffentlich. Für Mandanten ab M1 ist deshalb die externe Veröffentlichung unter einer eigenen Domäne des Mandanten der Regelfall.

## 11.10 Abgrenzung gegenüber qualifizierten Vertrauensdiensten

Die interne CA ist kein qualifizierter Vertrauensdiensteanbieter im Sinne von eIDAS (Verordnung (EU) 910/2014, novelliert 2024). Sie durchläuft keine Konformitätsbewertung, unterliegt keiner Aufsichtsstelle und steht in keiner Vertrauensliste. Daraus folgt konkret: ein mit einem internen Nutzerzertifikat erzeugtes Dokument trägt keine qualifizierte elektronische Signatur und genießt deren Rechtswirkung nicht; ein internes Zertifikat ist keine qualifizierte Website-Authentifizierung; formgebundene Erklärungen lassen sich damit nicht erfüllen. Ob die technischen Eigenschaften einer fortgeschrittenen Signatur vorliegen, ist eine rechtliche Bewertung im Einzelfall und keine Aussage, die das Produkt über sich trifft. Die Konsole verwendet deshalb an keiner Stelle Begriffe, die Rechtswirkung nahelegen; das Zertifikatsobjekt trägt einen technischen Verwendungszweck und keine rechtliche Einordnung.

Die Zeitstempel der Auditkette nach RFC 3161 sind qualifizierte Zeitstempel nur dann, wenn die Quelle ein qualifizierter Anbieter ist. Die Quelle ist eine Konnektorbindung ([Kapitel 09](09-konnektoren.md)); die Konsole zeigt, welche Quelle in Benutzung ist. Die Angabe, ob diese Quelle qualifiziert ist, ist eine Erklärung des Betreibers und keine vom System prüfbare Eigenschaft — das wird in der Oberfläche so gekennzeichnet. Der Nachweiswert der Schlüsselzeremonie für Prüfungen nach ISO/IEC 27001:2022 oder NIS2 (Richtlinie (EU) 2022/2555) liegt im Prüfprotokoll und in der wiederkehrenden Anteilsprüfung; die Einordnung erfolgt in [Kapitel 22](22-compliance.md).

## 11.11 Kryptoagilität

Jedes CA-Objekt, jedes Zertifikatsobjekt und jede Signatur trägt eine Verfahrenskennung und eine Profilversion: `sig_verfahren`, `hash_verfahren`, `schluessel_verfahren`, `profil_version`. Ein Zertifikat ohne vollständige Kennung wird nicht ausgestellt. Der Zweck ist nicht Dokumentation, sondern Migrierbarkeit: ohne die Kennung ist der Bestand nach Verfahren nicht filterbar, und ohne Filterbarkeit ist ein Wechsel keine Migration, sondern eine Neuinstallation.

Zwei Migrationen sind zu unterscheiden und laufen unabhängig. Der hybride Schlüsselaustausch (X25519 nach RFC 7748 zusammen mit ML-KEM nach FIPS 203) ist eine Eigenschaft der TLS-Aushandlung und berührt die Zertifikatskette nicht; er kann eingeschaltet werden, ohne ein einziges Zertifikat neu auszustellen. Die Signaturmigration betrifft die Kette und ist der teure Teil.

Die Signaturmigration läuft als Parallelkette, nie als Nachsignatur. Eine neue Wurzel mit dem neuen Verfahren entsteht in einer Zeremonie, wird zusätzlich zur alten an verwaltete Geräte verteilt, und Endzertifikate werden aus beiden Ketten parallel ausgestellt; der Eingang hält je Name zwei Zertifikate und wählt anhand der vom Client angebotenen Signaturverfahren. Die alte Kette entfällt, wenn kein Client mehr ausschließlich klassische Verfahren anbietet. Die Kosten sind benennbar: verdoppeltes Ausstellungsvolumen und verdoppelte Positivliste während der Übergangszeit.

Für Endzertifikate ist ML-DSA (FIPS 204) das Ziel, nicht SLH-DSA (FIPS 205). Der Grund ist die Signaturgröße: SLH-DSA-Signaturen sind um Größenordnungen größer als ECDSA- oder Ed25519-Signaturen; die genaue Größe hängt vom Parametersatz ab und wird hier nicht beziffert. Bei EAP-TLS (RFC 5216) über RADIUS (RFC 2865) wird die Zertifikatskette in EAP-Fragmente zerlegt, und jede zusätzliche Fragmentrunde verlängert die Anmeldung am Netz spürbar. SLH-DSA bleibt deshalb der Lieferkette und der Wurzel vorbehalten, wo Größe keine Rolle spielt und die konservativere Sicherheitsannahme zählt.

Die Wurzellaufzeit von 15 a überlebt einen Verfahrenswechsel, weil die Wurzel ein Vertrauensanker und keine Zusage über die Haltbarkeit eines Verfahrens ist. Wird ein Verfahren schwach, entsteht eine neue Wurzel; die alte läuft aus, ohne dass etwas nachsigniert wird. Die Kosten des Wechsels bestimmt nicht die Restlaufzeit der alten Wurzel, sondern die Reichweite der Vertrauensverteilung — und die ist bei einer 5 Jahre alten Wurzel dieselbe wie bei einer 15 Jahre alten. Deshalb ist die Laufzeitwahl von der Verfahrensfrage entkoppelt.

## 11.12 Wiederherstellung

| Fall | Erkennung | Sofortmaßnahme | Verfahren | Zeitbudget | Bleibender Schaden |
|---|---|---|---|---|---|
| Verlust des Ausstellungsknotens (TPM zerstört) | Ausstellung schlägt fehl; Überblick zeigt "Ausstellung nicht möglich" | keine Sperrung, da kein Kompromittierungsbeleg | Zeremonie mit k Anteilen; neuer Ausgabe-CA-Schlüssel im TPM des Ersatzknotens; neues Ausgabe-CA-Zertifikat von der Wurzel | Zielwert ≤ 7 d, hart begrenzt durch 29 d (Abschnitt 11.6) | keiner; alte und neue Kette sind beide unter derselben Wurzel gültig |
| Kompromittierung der Ausgabe-CA | Auditabgleich: signiertes Zertifikat ohne Protokolleintrag | Positivliste einfrieren; betroffene Seriennummern entfernen (p95 ≤ 10 s an eigenen Prüfstellen) | Notzeremonie: Sperrung des Ausgabe-CA-Zertifikats in der Wurzelsperrliste, neue Ausgabe-CA, Neuausstellung aller 2.292 Zertifikate | Signaturen in Sekunden; Geräte nach Erreichbarkeit, Zielwert ≥ 95 % in 7 d | Geräte, die 7 d nicht erscheinen, verlieren den Netzzugang bis zur Neuregistrierung |
| Kompromittierung einer Zwischen-CA | wie oben, mandantenbezogen | Sperrung durch die Ausgabe-CA, online und sofort | neue Zwischen-CA für diesen Mandanten, Neuausstellung ausschließlich seiner Zertifikate | Zielwert ≤ 24 h | genau ein Mandant betroffen — das ist der Ertrag der Namensbeschränkung |
| Verlust einzelner Anteile, aber ≥ k vorhanden | Anteilsprüfung | Zustand "Anteil fehlt" am Überblick | Zeremonie: Rekonstruktion, Neuteilung in n frische Anteile, Vernichtungsnachweis für alle alten | Zielwert ≤ 30 d | alte Anteile bleiben rekonstruktionsfähig, solange sie nicht vernichtet sind |
| Verlust von mehr als n − k Anteilen | Anteilsprüfung | Zustand "Wurzel nicht mehr verfügbar" dauerhaft sichtbar | neue Wurzel, parallele Verteilung, neue Ausgabe- und Zwischen-CAs, Umstellung aller Endzertifikate | begrenzt durch eine Gerätezertifikatslaufzeit (365 d) | die alte Wurzel kann die neue nicht quersignieren; nicht verwaltete Geräte brechen beim Wechsel |
| Kompromittierung der Wurzel | externer Hinweis oder Zeremonieabweichung | vollständiger Neuaufbau der Kette | wie Verlust, zusätzlich Sperrung aller Ketten unter der alten Wurzel | dieselbe Grenze | jedes nicht verwaltete Gerät muss von Hand angefasst werden |

Der Verlust der Anteile ist in einer Hinsicht schlimmer als die Kompromittierung: bei Kompromittierung existiert ein Schlüssel, mit dem die Ablösung quersigniert werden kann, bei Verlust nicht. Deshalb ist die Verfügbarkeit der Anteile eine laufend geprüfte Eigenschaft und kein Einrichtungsschritt. Zielwert: vierteljährliche Anteilsprüfung, Anteil unbestätigter Anteile = 0. Die Prüfung verlangt vom Träger die Eingabe des auf dem Träger gedruckten Prüfwerts; der Vergleich läuft laufzeitkonstant gegen den gespeicherten Wert. Der Prüfwert ist aus dem Anteil und einem im System verbleibenden Schlüssel abgeleitet und gibt über den Anteil nichts preis, sodass wiederholte Prüfungen keine Information ansammeln. Ein Anteil, der länger als 100 d unbestätigt ist, erzeugt einen benannten Zustand im Überblick (INV-18) und keine Zeile in einem Bericht, den niemand liest.

## Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| R-11-01 | Die Kette besteht aus genau drei Ebenen: Wurzel offline, Ausgabe-CA im TPM, je Mandant ab M0 eine Zwischen-CA. Eine vierte Ebene ist nicht ausstellbar. | INV-22, KANON 4.6a |
| R-11-02 | Die `pathLenConstraint`-Werte betragen 2 (Wurzel), 1 (Ausgabe), 0 (Zwischen); ein Antrag auf ein CA-Zertifikat an eine Zwischen-CA wird abgelehnt. | RFC 5280 |
| R-11-03 | Jede Zwischen-CA trägt Name Constraints als kritische Erweiterung mit `permittedSubtrees` für `dNSName`, `rfc822Name` und `directoryName` sowie `excludedSubtrees` für alle IP-Namensformen. | INV-19 |
| R-11-04 | Die Ausstellung übernimmt aus einem Antrag ausschließlich den öffentlichen Schlüssel und den Besitznachweis; alle Namen und Erweiterungen stammen aus dem Objektgraphen. Ein Antrag mit Erweiterungen wird abgelehnt, nicht bereinigt. | INV-02 |
| R-11-05 | Der Wurzelschlüssel liegt zu keinem Zeitpunkt auf einem dauerhaften Datenträger eines laufenden Knotens und in keinem Wiederherstellungspunkt. | INV-22 |
| R-11-06 | Die Zeremonie läuft ohne aktive Netzschnittstelle und ohne Auslagerungsspeicher; jeder Schritt erzeugt ein Auditereignis; das Prüfprotokoll enthält kein Schlüsselmaterial. | INV-23 |
| R-11-07 | Die Werte k und n stammen aus einer Richtlinie mit benannter Quelle; Standard ist k = 3, n = 5; 2-von-3 wird dauerhaft als verminderte Sicherung angezeigt. | INV-15, INV-18 |
| R-11-08 | Die Bestätigung eines Anteils erfordert die Eingabe des auf dem Träger gedruckten Prüfwerts; der Vergleich läuft laufzeitkonstant. Eine Bestätigung ohne Eingabe existiert nicht. | INV-20 |
| R-11-09 | Die Anteilsprüfung läuft vierteljährlich; ein länger als 100 d unbestätigter Anteil erzeugt einen benannten Zustand im Überblick. | INV-18 |
| R-11-10 | Der Schlüssel der Ausgabe-CA entsteht im TPM 2.0 und ist nicht exportierbar; ein Knoten ohne TPM 2.0 kann die Ausstellungsrolle nicht übernehmen. | INV-20 |
| R-11-11 | Die TPM-Richtlinie akzeptiert jeden mit dem Freigabeschlüssel signierten Abbildzustand, sodass eine A/B-Aktualisierung die Ausstellung nicht unterbricht. | K-23 |
| R-11-12 | Es existieren genau fünf Ausstellungsprofile mit den in Abschnitt 11.4 festgelegten Werten für Schlüsselverwendung, erweiterte Schlüsselverwendung, Laufzeit und Erneuerungsschwelle. | K-13 |
| R-11-13 | Es existieren genau drei Ausstellungswege (ACME, EST, direkte Ausstellung). Die API kennt keine Operation zum Hochladen, Erzeugen oder Signieren eines Zertifikats von Hand. | INV-09, INV-01 |
| R-11-14 | Intern wird ausschließlich die dns-01-Prüfung verwendet; das ACME-Konto ist an die Objektkennung der Veröffentlichung gebunden. | RFC 8555 |
| R-11-15 | Der in RFC 7030, Abschnitt 4.4 optionale Endpunkt `/serverkeygen` wird nicht angeboten; ein Aufruf darauf wird abgelehnt. Das Registriergeheimnis ist einmalig, ≤ 24 h gültig und nach 5 Fehlversuchen vernichtet. | INV-20 |
| R-11-16 | Die Erneuerung beginnt bei zwei Dritteln der Laufzeit, versucht stündlich mit einem aus der Seriennummer abgeleiteten Zeitversatz; der Anteil Zertifikate mit weniger als 7 d Restlaufzeit ist 0. | K-13 |
| R-11-17 | Eine vollständige Ausstellungsunterbrechung von 29 d führt zu 0 abgelaufenen Dienst- und Knotenzertifikaten. | K-13 |
| R-11-18 | Eigene Prüfstellen prüfen gegen eine aus dem Sollzustand verteilte Positivliste gültiger Seriennummern und lehnen jedes nicht enthaltene Zertifikat ab. | INV-22 |
| R-11-19 | Eine veraltete Positivliste führt nicht zur Ablehnung enthaltener Zertifikate, sondern zum benannten Zustand "Sperrliste veraltet seit <Zeit>". | INV-25, INV-18 |
| R-11-20 | Die Sperrung eines Geräts wirkt an eigenen Prüfstellen p95 ≤ 10 s; die Sperrliste für fremde Prüfstellen trägt `nextUpdate` 6 h und wird stündlich neu veröffentlicht. | K-17 |
| R-11-21 | Die Konsole bietet keinen Download des Wurzelzertifikats mit Einbauanleitung für nicht verwaltete Geräte an. | INV-16 |
| R-11-22 | Eine Veröffentlichung mit Sichtbarkeit "intern" erhält ausschließlich ein internes Zertifikat, eine mit Sichtbarkeit "extern" ausschließlich ein öffentliches; Wildcard-Zertifikate werden nicht ausgestellt. | INV-20 |
| R-11-23 | CAA-Einträge sind abgeleitete Artefakte der externen Domäne und begrenzen Ausstellung auf die gewählte CA und das Konto der Installation. | INV-09, RFC 8659 |
| R-11-24 | Eine Ablehnung wegen Ratengrenze erzeugt eine geplante Wiederholung mit der vom Anbieter genannten Wartezeit und einen benannten Zustand am Objekt; eine unbegrenzte Wiederholungsschleife existiert nicht. | INV-12 |
| R-11-25 | Weder Konsole noch Bericht noch Zertifikatsobjekt treffen eine Aussage über Rechtswirkung; das Zertifikat trägt ausschließlich einen technischen Verwendungszweck. | eIDAS |
| R-11-26 | Jede CA, jedes Zertifikat und jede Signatur trägt `sig_verfahren`, `hash_verfahren`, `schluessel_verfahren` und `profil_version`; ohne vollständige Kennung erfolgt keine Ausstellung. | KANON 4 Post-Quanten-Vorsorge |
| R-11-27 | Ein Verfahrenswechsel läuft als Parallelkette; während der Migration führt jede betroffene Veröffentlichung zwei Zertifikate, und die Auswahl folgt den vom Client angebotenen Signaturverfahren. | INV-24 |
| R-11-28 | Der Verlust des Ausstellungsknotens führt zu keiner Sperrung, solange kein Kompromittierungsbeleg vorliegt; die Wiederherstellung erfolgt innerhalb des Erneuerungsfensters. | K-13 |
| R-11-29 | Die Kompromittierung einer Zwischen-CA erzwingt die Neuausstellung ausschließlich der Zertifikate des betroffenen Mandanten; die Zahl betroffener Fremdmandanten ist 0. | INV-19 |
| R-11-30 | Der aus dem verteilten Wurzelzertifikat neu berechnete Fingerabdruck stimmt mit dem Wert im Prüfprotokoll überein. | INV-23 |

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Ein Antrag auf ein Zertifikat mit vier Kettenebenen wird von der Ausgabe-CA abgelehnt | R-11-01 | Kettenaufbautest gegen die Ausstellungsschnittstelle |
| Ein von der Zwischen-CA des Mandanten A signierter Antrag mit `O=<Mandant B>` schlägt fehl; eine dennoch erzeugte Kette wird von jeder eigenen Prüfstelle abgelehnt | R-11-03, R-11-29 | Kreuzausstellungstest mit manipuliertem Betreff |
| Ein Antrag mit `otherName` oder mit angeforderten Erweiterungen erzeugt 0 Zertifikate und 1 Ablehnungsereignis | R-11-04 | Antragsmutationstest über alle fünf Profile |
| Eine Inhaltsprüfung aller Wiederherstellungspunkte und Sollzustandsexporte findet 0 Treffer gegen das Muster eines privaten Wurzelschlüssels | R-11-05 | Sicherungsinhaltsprüfung im Bau (INV-22) |
| Die Zeremonie bricht ab, wenn eine Netzschnittstelle aktiv oder Auslagerungsspeicher eingebunden ist | R-11-06 | Umgebungsinjektion vor Schritt 2 |
| Eine Anteilsbestätigung ohne korrekten Prüfwert schließt die Zeremonie nicht ab; 100 Fehlversuche erzeugen 100 Auditereignisse und 0 Fortschritte | R-11-08 | Zeremoniedurchlauf mit falschen Prüfwerten |
| Ein Anteil ohne Bestätigung über 100 d erzeugt genau einen Zustandseintrag im Überblick | R-11-09 | Zeitrafferlauf über die Anteilsprüfung |
| Der Ausgabe-CA-Schlüssel ist über jede Schnittstelle nicht auslesbar; ein Exportversuch erzeugt 0 Bytes Schlüsselmaterial und 1 Auditereignis | R-11-10 | Exportversuchstest gegen TPM und API |
| Nach einer vollständigen A/B-Abbildaktualisierung stellt derselbe Knoten ohne Zeremonie weiter aus | R-11-11 | Aktualisierungstest mit anschließender Ausstellung |
| Die Endpunktliste enthält 0 Operationen zum Hochladen oder Handsignieren eines Zertifikats | R-11-13 | Fassadenbau gegen die öffentliche API (INV-01) |
| Ein interner ACME-Lauf über http-01 oder tls-alpn-01 wird abgelehnt | R-11-14 | Protokolltest mit allen drei Prüfarten |
| Ein EST-Aufruf auf `/serverkeygen` wird abgelehnt und erzeugt 0 Bytes Schlüsselmaterial; ein zweites Mal verwendetes Registriergeheimnis erzeugt 0 Zertifikate | R-11-15 | Wiederverwendungs- und Ratentest |
| Über 90 simulierte Tage liegt der Anteil der Zertifikate mit weniger als 7 d Restlaufzeit bei 0; die Erneuerungszeitpunkte sind über die Stunde gleichverteilt | R-11-16 | Zeitrafferlauf mit 2.292 Zertifikaten |
| Eine 29 d dauernde Abschaltung der Ausgabe-CA führt zu 0 abgelaufenen Dienst- und Knotenzertifikaten; der Nachlauf von 1.027 Erneuerungen dauert ≤ 60 s | R-11-17 | Ausfallinjektion mit anschließender Zeitmessung |
| Ein außerhalb des replizierten Protokolls mit dem CA-Schlüssel erzeugtes Zertifikat wird von jeder eigenen Prüfstelle abgelehnt | R-11-18 | Injektionstest mit direkt signiertem Zertifikat |
| Ein von der Kontrollebene getrennter Knoten lehnt nach Ablauf der Listenfrist 0 gültige Zertifikate ab und zeigt genau einen Veraltungszustand | R-11-19 | Partitionstest über 72 h |
| Die Sperrung eines Geräts ist an Eingang und RADIUS p95 ≤ 10 s wirksam | R-11-20 | Sperrvorgang mit paralleler Verbindungsmessung |
| Die Konsolentexte enthalten 0 Treffer für einen Download des Wurzelzertifikats mit Einbauanleitung | R-11-21 | Musterprüfung aller Oberflächentexte |
| Eine interne Veröffentlichung erhält 0 öffentliche Zertifikate; kein interner Name erscheint in einem Certificate-Transparency-Protokoll | R-11-22 | Ausstellungspfadtest plus Abgleich der ausgestellten Namen |
| Ein Ausstellungsversuch bei einer nicht in CAA erlaubten CA schlägt fehl | R-11-23 | Ausstellungstest gegen eine zweite öffentliche CA |
| Eine Ratengrenzenablehnung erzeugt 1 geplante Wiederholung und 1 benannten Zustand, 0 Sofortwiederholungen | R-11-24 | Fehlerinjektion an der ACME-Attrappe |
| Konsolentexte und Berichte enthalten 0 Treffer gegen die Liste rechtswirkungsbezogener Begriffe | R-11-25 | Musterprüfung im Bau (INV-16) |
| Eine Ausstellung ohne vollständige Verfahrenskennung erzeugt 0 Zertifikate | R-11-26 | Feldmutationstest an der Ausstellung |
| Während der simulierten Verfahrensmigration wählt ein Client mit klassischen und ein Client mit neuen Verfahren je die passende Kette; 0 fehlgeschlagene Handschläge | R-11-27 | Migrationstest mit zwei Clientprofilen |
| Nach dem Verlust des Ausstellungsknotens ist die Ausstellung nach Zeremonie wieder möglich; 0 Sperrungen bestehender Zertifikate | R-11-28 | Wiederherstellungsübung auf Ersatzhardware (K-24) |
| Der aus dem ausgerollten Wurzelzertifikat berechnete Fingerabdruck stimmt mit dem Prüfprotokoll überein | R-11-30 | Nachrechnung im Wiederherstellungstest |

## Offene Punkte

1. **Ausstellungsredundanz gegen Zeremonieaufwand.** Weil INV-20 die Replikation privater Schlüssel verbietet, hat die Installation genau eine Ausstellungsstelle. Der Ausbau von einem auf drei Verwaltungsknoten macht die zweite Ausgabe-CA wünschenswert, deren Wurzelsignatur aber eine Zeremonie mit k Anteilen erfordert. Die Alternative, die erste Ausgabe-CA die zweite signieren zu lassen, verlängert die Kette auf vier Ebenen und macht den Erstknoten dauerhaft übergeordnet. Welche der beiden Varianten Standard wird, ist nicht entschieden.
2. **Widerruf einer TPM-Richtlinienautorisierung.** Die Richtlinie akzeptiert jeden mit dem Freigabeschlüssel signierten Abbildzustand. Damit kann ein altes, verwundbares Abbild den CA-Schlüssel weiterhin benutzen. Ein Widerrufsmechanismus für einzelne Abbildversionen innerhalb der TPM-Richtlinie ist nicht ausgearbeitet; ein Zähler im TPM wäre ein Weg, kollidiert aber mit dem Rückfall auf die inaktive Hälfte nach K-23.
3. **Anteilsträger in Ein-Personen-Installationen.** Fünf unabhängige Aufbewahrungsorte sind in kleinen Installationen unrealistisch, und die Gleichortigkeit von Anteilen ist technisch nicht feststellbar. Die Ortsangabe je Anteil ist ein optionales Freitextfeld und damit eine Behauptung. Ob ein Verfahren mit einem zusätzlichen, an ein zweites Gerät gebundenen Anteil sinnvoll ist, ist offen.
4. **Zertifikate auf nicht verwalteten Geräten.** Ohne Schlüsselexport gibt es dort kein Nutzerzertifikat. Für Fälle, in denen ein externer Partner oder ein privates Gerät eine zertifikatsgebundene Anmeldung benötigt, existiert kein Weg. Ob eine eng begrenzte Ausnahme mit gerätegebundenem Schlüssel über WebAuthn diese Lücke schließt, ist nicht untersucht.
5. **S/MIME außerhalb der Installation.** Intern ausgestellte Nutzerzertifikate sind für externe Empfänger wertlos. Ob eine Konnektorbindung an eine öffentliche S/MIME-CA vorgesehen wird — mit den Folgen für Kosten, Identitätsprüfung und Schlüsselverwahrung — ist nicht entschieden.
6. **Nicht beschränkbare Namensformen.** `otherName` ist durch Name Constraints nicht begrenzbar, und die URI-Beschränkung greift nur am Wirtsanteil. Die Durchsetzung liegt im Ausstellungscode. Sollte je ein zweiter Ausstellungspfad entstehen, ist die Mandantentrennung an dieser Stelle nicht mehr kryptographisch erzwungen.
7. **Positivliste gegen Partitionstoleranz.** Ein von der Kontrollebene getrennter Knoten kennt keine nach der Trennung ausgestellten Seriennummern und lehnt sie ab. Ein neu bereitgestellter Dienst ist von einem solchen Knoten aus damit nicht erreichbar. Ob die Prüfstelle in diesem Fall auf eine reine Kettenprüfung zurückfallen darf und ab welcher Listenalterung, ist nicht festgelegt.
8. **Ratengrenzen öffentlicher CAs.** Das Ausstellungsvolumen von 2.028 externen Zertifikaten im Jahr bei 500 externen Namen ist eine Rechengröße ohne Abgleich mit einem konkreten Anbieter. Welches Verhalten gilt, wenn eine neue externe Veröffentlichung wegen einer erschöpften Grenze kein Zertifikat erhält — Ablehnung des Vorgangs oder Veröffentlichung im Zustand "ohne Zertifikat" —, ist offen.
9. **Preisgabe von Mandantennamen über Certificate Transparency.** Externe Veröffentlichungen unter der Basisdomäne machen die Kundenbeziehung öffentlich. Die Empfehlung, für Mandanten ab M1 eigene Domänen zu verwenden, ist keine Durchsetzung. Ob die Konsole eine externe Veröffentlichung unter der Basisdomäne für einen Mandanten verweigert, ist nicht entschieden.
10. **Qualifizierte Zeitstempel.** Ob eine Zeitstempelquelle qualifiziert ist, ist eine Erklärung des Betreibers und maschinell nicht prüfbar. Damit steht im Auditnachweis eine Eigenschaft, deren Richtigkeit das System nicht belegen kann. Eine Prüfung gegen eine Vertrauensliste ist denkbar, aber weder im Objektmodell noch als Konnektor vorgesehen.
11. **Wurzelwechsel und nicht verwaltete Geräte.** Nach 15 a oder nach einem Verfahrenswechsel erreicht die neue Wurzel nur verwaltete Geräte. Für alle anderen bricht das Vertrauen zu einem bekannten Zeitpunkt. Ein Verfahren, das diesen Zeitpunkt in der Konsole rechtzeitig mit der Zahl betroffener Geräte anzeigt, ist beschrieben, aber nicht mit der Geräteverwaltung in [Kapitel 13](13-geraeteverwaltung.md) abgestimmt.
