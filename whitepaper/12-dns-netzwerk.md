# 12 Netz, DNS und selbstverwaltete Firewall

## 12.1 Adressplan und Zonenmodell

Das Netz von Atrium ist kein eigenständig gepflegter Gegenstand, sondern die Projektion des Objektgraphen auf Adressen, Zonen und Regeln. Die Netzzone ist dabei die Grundeinheit: sie trägt den Adressbereich, die Resolver-Sicht und die Default-Deny-Vorgabe und ist Quelle und Ziel jeder abgeleiteten Regel. Wer keine Netzzone hat, hat keine Adresse im Sinne von Atrium und wird in jeder Ableitung wie eine unbekannte Quelle behandelt.

### Adressfamilien

IPv6 ist die primäre Adressfamilie für alle von Atrium erzeugten Adressen. IPv4 wird erzeugt, wo ein Katalogeintrag, ein Altgerät oder eine Gegenstelle es verlangt, und nie als Vorgabe. Der Grund ist rechnerisch: der interne Adressbedarf einer Installation mit 32 Knoten, 800 Geräten, 150 Diensten und bis zu 256 Mandanten liegt im Bereich von wenigen Tausend Adressen, aber der Bedarf an *disjunkten Segmenten* liegt bei mehreren Hundert, und disjunkte Segmente sind in IPv4 nur durch Zerlegung eines privaten Bereichs zu bekommen, die mit jedem vorhandenen Kundennetz kollidieren kann.

| Familie | Bereich | Herkunft | Verwendung |
|---|---|---|---|
| IPv6 intern | ein `/48` aus `fd00::/8` mit 40 zufälligen Bits, bei der Erstinstallation einmalig erzeugt | lokal erzeugter Zufall, danach unveränderlich im Sollzustand | alle Netzzonen, Overlay, Dienste, Geräte |
| IPv4 Overlay | Vorgabe `100.64.0.0/16` aus dem für anbieterinternes NAT reservierten Bereich `100.64.0.0/10` | Vorgabe, bei erkannter Kollision durch einen anderen Block aus demselben `/10` ersetzt | nur Knoten-Overlay und Dienste, die kein IPv6 sprechen |
| IPv4 Unterlagerung | das vorhandene Kundennetz | fremd, wird gelesen, nie verändert | Erreichbarkeit der Knoten untereinander vor Aufbau des Overlays |
| IPv6 extern | das vom Anschluss delegierte Präfix | fremd | veröffentlichte Dienste, ausgehender Verkehr |

**Rechnung zur Kollisionswahrscheinlichkeit des `/48`.** Die 40 Zufallsbits ergeben 2⁴⁰ = 1,0995 × 10¹² mögliche Präfixe. Zwei unabhängig erzeugte Installationen kollidieren mit 1/2⁴⁰ = 9,1 × 10⁻¹³. Für einen Verbund aus 1.000 Installationen, die einmal zusammengeschaltet werden sollen, gilt die Geburtstagsschranke: 1.000 × 999 / 2 / 2⁴⁰ = 499.500 / 1,0995 × 10¹² = 4,5 × 10⁻⁷. Das ist ein Modell, keine Messung; es rechtfertigt den Verzicht auf eine zentrale Präfixvergabe, nicht die Behauptung, eine Kollision sei unmöglich.

**Aufteilung des `/48`.** Die 16 Bits zwischen `/48` und `/64` werden fest in zwei Felder zerlegt:

```
  fd??:????:????:  MM  ZZ  ::/64
                   |   |
                   |   +-- 8 bit Zonenindex innerhalb des Mandanten (0..255)
                   +------ 8 bit Mandantenindex (0 = Plattform, 1..255 = Mandanten)

  Beispiel:  fd7a:3c19:88e2:0000::/64   Plattform, Zone 0  = Knoten-Overlay
             fd7a:3c19:88e2:0003::/64   Plattform, Zone 3  = verwaltete Geraete
             fd7a:3c19:88e2:0701::/64   Mandant 7, Zone 1  = Mandanten-Overlay
```

Beide Indizes sind Zähler im Sollzustand, keine Hashwerte. **Entwurfsentscheidung mit verworfener Alternative:** Ein aus der Mandantenkennung abgeleiteter Hash über 8 Bit kollidiert bei 20 Mandanten mit 20 × 19 / 2 / 256 = 190 / 256 = 74 % und ist damit ausgeschlossen; ein Hash über die vollen 16 Bit kollidiert bei 20 Mandanten mit 190 / 65.536 = 0,29 %, was für eine stille Ableitung immer noch zu hoch ist. Ein Zähler kostet nichts, weil die Zonenzuordnung ohnehin eine quorumpflichtige Entscheidung der Kontrollebene ist, und er ist in einem Supportfall durch das Lesen eines Feldes prüfbar statt durch Nachrechnen. Ein einmal vergebener Index wird nicht wiederverwendet, solange das Mandanten- oder Zonenobjekt existiert oder in der Aufbewahrungsfrist steht (INV-11).

**Knotenadressen.** Innerhalb der Zone 0 erhält jeder Knoten die Adresse `<präfix>:0000::<knotenindex>`, wobei der Knotenindex ein Zähler im Knotenobjekt ist. Bei 32 Knoten (K-21) sind das die Werte 1 bis 32. Auch hier wäre ein 64-Bit-Hash der Knotenkennung kollisionsarm — 32 × 31 / 2 / 2⁶⁴ = 496 / 1,8447 × 10¹⁹ = 2,7 × 10⁻¹⁷ —, die Kollisionsfrage ist also nicht der Ablehnungsgrund; der Ablehnungsgrund ist die fehlende Lesbarkeit im Störfall.

### Zonenkatalog

| Zone | Mitglieder | Resolver-Sicht | Default-Deny | Bedeutung |
|---|---|---|---|---|
| **Loopback** | nur der Knoten selbst | keine | ja, außer Loopback | Gesundheitsendpunkt 8409/tcp, Konnektorsockets, lokale Steuerschnittstelle des autoritativen DNS |
| **Unterlagerung** | physische Schnittstellen der Knoten | keine | ja | trägt ausschließlich WireGuard auf 51820/udp, Zeitsynchronisation und, wo vorhanden, das Speichernetz |
| **Knoten-Overlay** | alle gekoppelten Knoten | intern, vollständig | ja | Kontrollebenenverkehr: 8400–8402, 8404, 8406, 8408/tcp; DRBD ab 7789/tcp. 8403/tcp gehört nicht hierher: der Kopplungsendpunkt liegt im lokalen Netzsegment und nur im Wartemodus (INV-27) |
| **Speichernetz** | Speicherträger | keine | ja | optional, nur ab 8 Knoten mit dedizierten Speicherknoten; sonst über das Overlay gekapselt |
| **Mandanten-Overlay** | Dienste eines Mandanten ab M1 | intern, auf den Mandanten beschränkt | ja | eigenes Schlüsselmaterial, eigener Eingangs-Listener, eigene Ausgangsadresse |
| **Verwaltete Geräte** | Geräte mit gültigem Gerätezertifikat | intern, nach Geltungsbereich der Domänen | ja | Zuordnung über EAP-TLS nach IEEE 802.1X oder über Overlay-Mitgliedschaft |
| **Gast** | Geräte ohne Zuordnung, ausdrücklich als Gast zugelassen | ausschließlich extern | ja | kein Zugriff auf interne Namen, kein Zugriff auf interne Dienste, nur Uplink |
| **Nicht verwaltet** | Geräte im vorhandenen Kundennetz ohne Atrium-Bezug | extern, ergänzt um ausdrücklich freigegebene interne Namen | ja | der Regelfall in einer Bestandsumgebung; Gerätegranularität ist hier nicht durchsetzbar |
| **Wartemodus** | Knoten im Wartemodus | keine | ja, außer 8403/tcp | einzige Zone, in der ein unauthentisierter Endpunkt erreichbar ist (INV-27) |
| **Extern** | alles jenseits des Uplinks | die externe Zoneninstanz | ja | Quelle jeder Veröffentlichung mit Sichtbarkeit extern |

Die Zonen **Nicht verwaltet** und **Gast** sind der Punkt, an dem der Entwurf die Zusage aus Technologiefestlegung 8c einlöst und zugleich eine Schwäche zugeben muss: in einem vorhandenen, flachen Kundennetz ohne 802.1X ist ein einzelnes Gerät nicht von einem anderen zu unterscheiden. Die Zone ist dann das ganze Segment, und jede Ableitung, die Gerätegranularität verlangt, ist in dieser Zone nicht durchsetzbar. Die Konsole zeigt das am Geltungsbereich an, statt eine Wirkung zu versprechen, die nur in einem Teil der Installationen eintritt.

### Verhältnis zu vorhandenen Netzen

Atrium nummeriert kein vorhandenes Netz um und übernimmt keine Adressvergabe, die es nicht selbst erzeugt hat. Das Overlay liegt additiv über der vorhandenen Unterlagerung und verbindet ausschließlich Knoten; Endgeräte erreichen veröffentlichte Dienste über die Unterlagerungsadresse des Eingangsträgers, nicht über Overlay-Adressen. Ein Endgerät wird nur dann Overlay-Mitglied, wenn es als Gerät registriert ist und einen Zugang erhält; das ist Gegenstand von [Kapitel 13](13-geraeteverwaltung.md).

**Kosten der Zweistapeligkeit.** Jede Ableitung erzeugt zwei Regelmengen und zwei Adressmengen, und jede DNS-Sicht kann für A und AAAA auseinanderlaufen. Das ist die häufigste stille Fehlerquelle in Split-Horizon-Umgebungen: der Name löst über IPv6 auf die interne, über IPv4 auf die externe Adresse auf, und ein Client mit Adressfamilienwettlauf wählt je nach Laufzeit unterschiedlich. Der Generator erzeugt deshalb beide Familien aus demselben Objekt, und ein Bautest prüft die Gleichheit der abgeleiteten Erreichbarkeitsmengen über beide Familien.

**Anforderungen**

- **R-12-01** — Das interne Präfix ist ein `/48` aus `fd00::/8` mit 40 bei der Erstinstallation zufällig erzeugten Bits; nach der Erstinstallation ist es über keine API-Operation änderbar. Prüfbar: Endpunktprüfung gegen die API-Fassade (INV-01) und Entropieprüfung über 1.000 Erstinstallationen im Bau.
- **R-12-02** — Mandanten- und Zonenindex sind Zähler im Sollzustand; ein innerhalb der Aufbewahrungsfrist freigegebener Index wird in 0 Fällen erneut vergeben. Prüfbar: 1.000 Anlege- und Löschzyklen mit Indexvergleich (INV-11).
- **R-12-03** — Jede Netzzone trägt Adressbereich, Resolver-Sicht und Default-Deny-Vorgabe als Pflichtfelder; eine Netzzone ohne eines dieser Felder ist über die API nicht anlegbar. Prüfbar: Anlegeversuch je fehlendem Feld.
- **R-12-04** — Für jeden veröffentlichten Namen ist die abgeleitete Erreichbarkeitsmenge über IPv4 und IPv6 identisch; eine Abweichung bricht den Bau. Prüfbar: Mengenvergleich über alle Veröffentlichungen im Bautest.

## 12.2 Overlay: Vollvermaschung, Schlüsselbindung, MTU

### Schlüsselbindung und Rotation

Das Schlüsselpaar für WireGuard wird auf dem Knoten erzeugt und verlässt ihn nie (INV-20). Der öffentliche Schlüssel wird im Zertifikatsantrag mitgeführt und vom ausstellenden Modul in das Knotenzertifikat aufgenommen. Damit ist die Mitgliedschaft im Overlay keine eigene Liste, sondern eine reine Funktion der PKI: die Peer-Menge eines Knotens ist die Menge der gültigen, nicht gesperrten Knotenzertifikate abzüglich des eigenen. Ein entkoppelter Knoten verliert die Overlay-Mitgliedschaft dadurch, dass sein Zertifikat gesperrt wird, und nicht dadurch, dass jemand eine Peer-Zeile löscht.

**Kanonlücke, ausdrücklich vermerkt.** K-13 nennt Laufzeiten für Dienstzertifikate (90 d), Gerätezertifikate (365 d) und die CA-Stufen, aber keine Laufzeit für Knotenzertifikate. Dieses Kapitel rechnet mit der **Annahme: Knotenzertifikat 90 d, Erneuerung ab Tag 60** und markiert die Festlegung als offen; sie gehört nach [Kapitel 11](11-pki.md).

Die Rotation läuft mit Überlappung, weil ein gleichzeitiger Schlüsselwechsel auf allen Knoten die Vermaschung zerreißen würde:

```
  t0   neues Schluesselpaar auf Knoten K erzeugt, CSR gestellt
  t1   Zertifikat ausgestellt, oeffentlicher Schluessel im Sollzustand
  t2   alle uebrigen Knoten tragen K mit ZWEI oeffentlichen Schluesseln
       (alt und neu) als zwei Peer-Eintraege mit derselben Endpunktadresse
  t3   K stellt seine eigene Schnittstelle auf den neuen privaten Schluessel um
  t4   nach Ablauf der Ueberlappungsfrist faellt der alte Peer-Eintrag weg
       und das alte Zertifikat wird gesperrt
```

**Zielwert Überlappungsfrist: 24 h.** Begründung: der längste erwartete Zustand, in dem ein Knoten die Sollzustandsänderung nicht empfängt, ist ein Neustart mit Abbildwechsel plus Rückfall (K-23: Beobachtungsfenster 30 min, Rückfallzeit ≤ 5 min). 24 h liegen um den Faktor 41 darüber. Kürzere Fristen sparen nichts, weil die Zahl der Rotationen gering ist (Rechnung unten).

### Verhalten hinter NAT

WireGuard erkennt einen Endpunktwechsel daran, dass ein gültig authentisiertes Paket von einer neuen Quelladresse eintrifft; die Endpunktaktualisierung ist damit selbst authentisiert und nicht fälschbar. Für die Richtung von außen nach innen gilt das nicht: ein Knoten hinter NAT ist nur erreichbar, solange eine Zuordnung im NAT-Gerät besteht. Der Entwurf setzt deshalb einen **Zielwert Keepalive-Intervall 25 s**, weil verbreitete NAT-Zuordnungen für UDP im Bereich von 30 s bis wenigen Minuten ablaufen; 25 s liegen unterhalb der kürzesten üblichen Frist.

Der Fall **beide Seiten hinter NAT** ist im Entwurf nicht gelöst, und das wird hier benannt statt umschrieben. Ein Durchstoßverfahren verlangt einen dritten, öffentlich erreichbaren Vermittler und eine Zustandsmaschine mit Zeitabgleich; beides wäre ein zusätzlicher Dauerdienst und eine Fremdabhängigkeit. Die Festlegung lautet: mindestens ein Knoten je Standortpaar muss eine erreichbare Adresse haben, andernfalls erreicht die Vermaschung dieses Paar nicht und die Konsole zeigt den betroffenen Pfad als gestört mit der Ursache "kein erreichbarer Endpunkt auf beiden Seiten". Der Ausweg über einen gemieteten Knoten mit öffentlicher Adresse steht in Abschnitt 12.9.

### MTU-Rechnung

```
  Aussenpaket (IPv6-Unterlagerung):
     IPv6-Kopf                40 Byte
     UDP-Kopf                  8 Byte
     WireGuard-Datenkopf      16 Byte   (Typ 4 + Empfaengerindex 4 + Zaehler 8)
     Poly1305-Pruefwert       16 Byte
     ------------------------------------
     Overhead                 80 Byte
     Nutzlast bei MTU 1500  1420 Byte

  Aussenpaket (IPv4-Unterlagerung):
     IPv4-Kopf                20 + UDP 8 + WG 16 + Tag 16 = 60 Byte
     Nutzlast bei MTU 1500  1440 Byte
```

**Festlegung: Overlay-MTU 1420 Byte, unabhängig von der Adressfamilie der Unterlagerung.** Verworfene Alternative: MTU je Pfad aus der tatsächlichen Unterlagerung ableiten. Grund der Ablehnung: bei zweistapeliger Unterlagerung entstünden Pfade mit unterschiedlicher MTU innerhalb derselben Vermaschung, und ein Dienst, der über zwei Pfade repliziert, verhielte sich je Pfad anders. Ein einheitlicher Wert kostet bei IPv4-Unterlagerung 20 Byte je Paket, das sind 20/1440 = 1,4 % Nutzlast.

**Nutzlasteffizienz.** 1420 / 1500 = 94,67 %; der Overhead beträgt 5,33 %. Für die Auslegung einer Replikationsstrecke heißt das: eine Bruttorate von 1 Gbit/s trägt 947 Mbit/s Nutzlast innerhalb des Overlays.

**TCP-MSS.** Die maximale Segmentgröße folgt aus der Overlay-MTU abzüglich der inneren Köpfe:

```
  inneres IPv6:  1420 - 40 (IPv6) - 20 (TCP ohne Optionen) = 1360 Byte
  inneres IPv4:  1420 - 20 (IPv4) - 20 (TCP ohne Optionen) = 1380 Byte
  PPPoE-Unterlagerung (MTU 1492): 1492 - 80 = 1412
                 -> inneres IPv6 1352, inneres IPv4 1372
```

Die MSS wird an der Overlay-Schnittstelle in beiden Richtungen auf den Pfadwert geklemmt. **Begründung, warum das nicht optional ist:** WireGuard fragmentiert innere Pakete nicht, sondern verwirft zu große Pakete und meldet das per ICMPv6 "Paket zu groß" beziehungsweise ICMP "Fragmentierung nötig". Wird diese Meldung von einem Gerät auf dem Pfad verworfen — ein in der Praxis häufiger Zustand —, entsteht das klassische Fehlerbild: Verbindungsaufbau gelingt, kleine Antworten kommen an, große Antworten bleiben aus. Die Firewallsynthese aus Abschnitt 12.7 lässt die betroffenen ICMP- und ICMPv6-Typen deshalb ausdrücklich zu, und die MSS-Klemmung ist die zweite, vom Pfadverhalten unabhängige Absicherung.

### Skalierung der Vermaschung

| Knoten n | Tunnel n(n−1)/2 | Peer-Einträge n(n−1) | Keepalive je Knoten | Handshakes installationsweit |
|---|---|---|---|---|
| 3 | 3 | 6 | 2 / 25 s = 0,08 /s | 3 / 120 s = 0,025 /s |
| 8 | 28 | 56 | 7 / 25 s = 0,28 /s | 28 / 120 s = 0,23 /s |
| 16 | 120 | 240 | 15 / 25 s = 0,60 /s | 120 / 120 s = 1,00 /s |
| 32 | 496 | 992 | 31 / 25 s = 1,24 /s | 496 / 120 s = 4,13 /s |

**Annahmen der Tabelle:** Keepalive-Intervall 25 s je Peer, Schlüsselerneuerung je aktivem Tunnelpaar alle 120 s, jeder Knoten ist mit jedem anderen verbunden.

**Verkehrslast durch Keepalives bei 32 Knoten.** Ein Keepalive ist ein Datenpaket ohne Nutzlast: 16 Byte Kopf + 16 Byte Prüfwert = 32 Byte, zuzüglich 48 Byte äußerer Köpfe bei IPv6 ergibt 80 Byte auf der Leitung. Installationsweit: 32 Knoten × 1,24 Pakete/s = 39,7 Pakete/s × 80 Byte = 3.176 Byte/s = 25,4 kbit/s. Das ist vernachlässigbar und nicht die Grenze.

**Konfigurationsumfang.** 992 Peer-Einträge zu je etwa 200 Byte ergeben installationsweit rund 198 KB, je Knoten 31 Einträge = 6,2 KB. Auch das ist nicht die Grenze.

**Rotationslast.** Bei Annahme 90 d Laufzeit und Erneuerung ab Tag 60 rotiert jeder Knoten alle 60 Tage. Installationsweit: 32 / 60 = 0,53 Rotationen je Tag; jede Rotation verändert auf 31 anderen Knoten je einen Peer-Eintrag, macht 16,5 Peer-Aktualisierungen je Tag. Auch das ist nicht die Grenze.

**Woraus die Grenze tatsächlich folgt.** Bindend ist das Abgleichbudget. K-16 setzt für einen vollständigen Abgleich aller Objekte 60 min bei Parallelität 4 an. Werden die gerichteten Peer-Einträge als abgleichpflichtige Objekte gezählt, gilt:

```
  n(n-1) * t_pruef / p  <=  3600 s
  mit t_pruef = 2 s (K-16, obere Schranke) und p = 4:
  n(n-1) <= 7200   ->   n <= 85
```

Die Vollvermaschung kollidiert also erst bei 85 Knoten mit dem Abgleichbudget; die gesetzte Skalengrenze von 32 Knoten (K-21) bindet deutlich früher. Zwei Ehrlichkeitsvorbehalte gehören dazu: erstens ist `t_pruef` = 2 s aus K-16 für einen Konnektoraufruf bemessen und für den lokalen Vergleich eines Peer-Eintrags um Größenordnungen zu hoch, weshalb 85 eine sehr konservative Schranke ist; zweitens ist die eigentliche Schwäche der Vollvermaschung nicht die Rechenlast, sondern die **Diagnosefläche**: bei 496 Tunneln gibt es 496 unabhängig ausfallbare Pfade, und ein teilweise gestörter Graph ist für einen Bediener ohne Werkzeug nicht erfassbar. Die Konsole begegnet dem mit einer Erreichbarkeitsmatrix, die den Graphen als Menge von Knotenpaaren mit Zustand und Beobachtungszeitpunkt (INV-28) zeigt, nicht mit einer Liste von 496 Zeilen.

**Anforderungen**

- **R-12-05** — Der private WireGuard-Schlüssel eines Knotens erscheint in 0 Sollzustandsexporten, 0 Wiederherstellungspunkten und 0 Protokollzeilen. Prüfbar: Musterprüfung aller Exporte und Protokolle (INV-20).
- **R-12-06** — Die Peer-Menge eines Knotens ist genau die Menge der gültigen, nicht gesperrten Knotenzertifikate abzüglich des eigenen; eine Peer-Zeile ohne zugehöriges gültiges Zertifikat existiert in 0 Fällen. Prüfbar: Vergleich der laufenden Schnittstellenkonfiguration gegen den Zertifikatsbestand nach jeder Sperrung (INV-09).
- **R-12-07** — Während der Überlappungsfrist von 24 h sind beide öffentlichen Schlüssel eines rotierenden Knotens auf allen übrigen Knoten eingetragen; die Vermaschung verliert während einer Rotation 0 Tunnel. Prüfbar: Rotationstest mit fortlaufender Erreichbarkeitsmessung über alle Paare.
- **R-12-08** — Die Overlay-MTU beträgt auf jedem Knoten und jeder Schnittstelle 1420 Byte; die TCP-MSS wird in beiden Richtungen auf 1360 (inneres IPv6) beziehungsweise 1380 (inneres IPv4) geklemmt. Prüfbar: Messung mit Paketen der Größe 1421 und 1420 über jeden Pfad sowie Auslesen der ausgehandelten MSS.
- **R-12-09** — Ein Pfad, bei dem beide Endpunkte hinter NAT ohne erreichbare Adresse liegen, wird in der Konsole als gestört mit der Ursache "kein erreichbarer Endpunkt auf beiden Seiten" angezeigt und nicht als betriebsbereit gemeldet. Prüfbar: Aufbau eines solchen Paares und Darstellungsprüfung (INV-18).
- **R-12-10** — Bei 32 Knoten bleibt die installationsweite Keepalive-Last unter 50 kbit/s und die Handshake-Rate unter 10/s. Prüfbar: Messung über 24 h im Lasttest gegen die gerechneten Werte 25,4 kbit/s und 4,13/s.

## 12.3 DNS-Architektur: autoritativer Dienst, Resolver, Zoneninstanzen

### Trennung der beiden Dienste

| Eigenschaft | Autoritativer Dienst (Knot DNS) | Rekursiver Resolver (Knot Resolver) |
|---|---|---|
| Antwortet für | ausschließlich eigene Domänen | alles, was ein Client fragt |
| Gebunden an | 53/udp, 53/tcp in den Zonen extern und intern | 53/udp, 53/tcp nur intern und Mandanten-Overlay; 853/tcp DNS over TLS (RFC 7858); 443/tcp DNS over HTTPS (RFC 8484) unter eigenem Namen |
| Zustandsquelle | erzeugte Zone aus dem Sollzustand | Zwischenspeicher, flüchtig |
| Schreibweg | ausschließlich lokale Steuerschnittstelle durch atrium-node | keiner |
| Instanzen | eine je Verwaltungsknoten, alle autoritativ, kein Zonentransfer | eine je Netzzone mit eigener Sicht |

Die Trennung in zwei Prozesse auf zwei Adressen ist keine Geschmacksfrage: ein gemeinsamer Prozess vermischt zwischengespeicherte Fremddaten mit autoritativen eigenen Daten und macht jede Aussage darüber, woher eine Antwort stammt, von der internen Zustandsführung des Dienstes abhängig. Beide Prozesse stammen aus derselben Quelle und folgen derselben Freigabekadenz (Technologiefestlegung 8a), was die Zahl der zu verfolgenden Sicherheitsmeldungen halbiert.

### Zoneninstanzen und Sichtattribut

Eine Domäne erzeugt bis zu zwei Zoneninstanzen: eine interne und eine externe. Es gibt keine getrennten Zonendateien und keine Views im autoritativen Dienst. Stattdessen trägt **jeder DNS-Eintrag sein Sichtattribut** (`intern`, `extern`, `beide`) als Pflichtfeld, und der Generator projiziert die Eintragsmenge auf zwei Instanzen:

```
  zone_instanz(D, sicht) :=
      { e in eintraege(D) : e.sicht = sicht oder e.sicht = beide }
      vereinigt mit
      { abgeleitete_eintraege(D, sicht) }
```

Der Vorteil gegenüber zwei unabhängig gepflegten Zonen ist prüfbar: ein Eintrag, der in genau einer Instanz fehlen soll, ist ein Feld, kein Vergessen. Der Preis ist ebenso konkret: die Sicht ist ein zusätzliches Pflichtfeld je Eintrag. Die Drei-Entscheidungs-Regel (INV-14) bleibt gewahrt, weil die Sicht aus der Sichtbarkeit der Domäne vorbelegt wird und die Vorbelegung ihre Quelle nennt (INV-15).

### Erzeugung der Zonen aus dem Objektgraphen

Die Zone ist ein abgeleitetes Artefakt (INV-09). Sie entsteht aus vier Quellen und aus keiner weiteren:

| Quelle | Erzeugt | Beispiel |
|---|---|---|
| Veröffentlichung | A, AAAA oder CNAME auf den Eingangsträger der zuständigen Zone; CAA an der Domänenwurzel | `ticket.kunde-a.example` → Adresse des Eingangs |
| Maildomäne | MX, SPF (RFC 7208), DKIM (RFC 6376), DMARC (RFC 7489), MTA-STS (RFC 8461), TLS-RPT (RFC 8460) | siehe [Kapitel 14](14-mail.md) |
| Knoten und Dienst | interne Namen `<dienst>.<mandant>.<basisdomäne>` und `<knoten>.knoten.<basisdomäne>` | nur Sicht intern |
| Handeintrag durch den Bediener | alle übrigen Arten | Delegierung an ein Fremdsystem, TXT-Nachweise |

Ein Handeintrag, der mit einem abgeleiteten Eintrag kollidiert, wird beim Schreiben abgelehnt, nicht überschrieben, und die Ablehnung nennt das Quellobjekt des abgeleiteten Eintrags. Verworfene Alternative: den Handeintrag gewinnen lassen. Grund der Ablehnung: dann wäre die Veröffentlichung nicht mehr die einzige Quelle der Erreichbarkeit, und die Aussage "der Dienst ist erreichbar, weil eine Veröffentlichung existiert" (INV-10) wäre nicht mehr wahr.

### Serienstände

Die Seriennummer der Zone ist die Sollzustandsversion (Technologiefestlegung 8). Zwei Randbedingungen kommen hinzu.

**Wertebereich.** Das Seriennummernfeld ist 32 Bit breit und wird nach Seriennummernarithmetik verglichen, das heißt Fortschritt ist nur für Differenzen unterhalb von 2³¹ definiert. **Rechnung:** 2³² = 4.294.967.296 Werte; bei der Annahme von 1.000 Sollzustandsversionen je Tag ist der Bereich nach 4.294.967.296 / 1.000 / 365 = 11.767 Jahren erschöpft. Ein einzelner Sprung überschreitet 2³¹ nur, wenn zwischen zwei Zonenerzeugungen mehr als 2,1 Milliarden Versionen liegen, was denselben Zeitraum voraussetzt. Der Wertebereich ist damit kein praktisches Problem.

**Rücksprung nach Wiederherstellung.** Wird eine Installation aus einem Sollzustandsexport wiederhergestellt, kann die Version hinter einem bereits veröffentlichten Serienstand liegen. Da es keinen Zonentransfer gibt (Technologiefestlegung 8), hat ein Rücksprung intern keine Wirkung; nach außen ist er im SOA-Datensatz sichtbar und irritiert jeden fremden Beobachter. Festlegung: der veröffentlichte Serienstand ist `max(sollzustandsversion, letzter_veröffentlichter_serienstand + 1)` und der letzte veröffentlichte Stand wird je Zone außerhalb des wiederherstellbaren Bereichs auf jedem Verwaltungsknoten fortgeschrieben. Die Abweichung zwischen Version und Serie wird in der Konsole am Domänenobjekt angezeigt, statt still zu bestehen.

### Benachrichtigung und Wirksamkeit

Innerhalb der Installation gibt es keinen Zonentransfer und damit auch keine Zonenbenachrichtigung zwischen Servern: jeder Verwaltungsknoten erhält die neue Sollzustandsversion über das Replikationsprotokoll und erzeugt dieselbe Zone. Die eigentliche Verzögerung entsteht nicht beim autoritativen Dienst, sondern im Zwischenspeicher der Resolver.

**Rechnung zur Wirksamkeit.** K-17 setzt für einen DNS-Eintrag bis zur Auflösbarkeit im Zielbereich p95 ≤ 5 s an, bei einer internen TTL von 300 s. Ohne Gegenmaßnahme wäre der schlechteste Fall 300 s, nicht 5 s, denn ein Resolver, der den alten Wert vor einer Sekunde geholt hat, liefert ihn 299 s weiter. Die Zusage ist also nur einlösbar, wenn die Kontrollebene beim Zonenwechsel jede Resolverinstanz anweist, die betroffenen Namen aus dem Zwischenspeicher zu entfernen. Das ist eine ausdrückliche Entwurfsentscheidung mit einer Kostenseite: die Kontrollebene muss wissen, welche Namen sich geändert haben, was der Generator ohnehin als Differenz zweier Zonenprojektionen berechnet.

Für **verneinende Antworten** gilt dasselbe in schärferer Form. Die Verweildauer einer verneinenden Antwort wird vom letzten Feld des SOA-Datensatzes bestimmt. Ein neu angelegter Name, der zuvor nicht existierte, ist für jeden fremden Resolver erst nach Ablauf dieses Wertes sichtbar, und fremde Resolver lassen sich nicht anweisen. **Festlegung: SOA-Verweildauer für verneinende Antworten 60 s intern, 300 s extern.** Damit ist der schlechteste Fall für einen neu angelegten externen Namen bei einem fremden Resolver 300 s, und die Konsole nennt diesen Wert beim Anlegen, statt Sofortwirkung zu suggerieren.

### Konsistenz bei Quorumsverlust

Ohne Quorum ist der Sollzustand eingefroren (INV-04). Für DNS ergibt sich daraus eine Aufteilung, die genau benannt werden muss, weil sie nicht selbstverständlich ist:

| Vorgang | Im eingefrorenen Zustand | Begründung |
|---|---|---|
| Zoneninhalt ändern | nein | ist eine Sollzustandsänderung |
| Vorhandene Zone weiter beantworten | ja | Dienste überleben die Kontrollebene (INV-25) |
| Signaturen erneuern | **ja** | die Signatur ist Bestandteil des abgeleiteten Artefakts, nicht des Sollzustands; der Zoneninhalt bleibt unverändert |
| Schlüsselwechsel durchführen | nein | erzeugt neues Schlüsselmaterial und ändert den Sollzustand |
| Neue Veröffentlichung wirksam machen | nein | erzeugt einen neuen Eintrag |

Die dritte Zeile ist der Kern. Wäre die Signaturerneuerung eine Sollzustandsänderung, würde eine Netzpartition, die länger als die verbleibende Signaturgültigkeit dauert, sämtliche Namen der Installation für jeden validierenden Resolver unauflösbar machen — die Störung wäre also nicht der Ausfall der Kontrollebene, sondern der vollständige Verlust der Namensauflösung. Weil die Signatur aus unverändertem Inhalt mit einem bereits vorhandenen Schlüssel erzeugt wird, ist sie idempotent im Inhalt (INV-07) und erfordert kein Quorum.

Die Schwäche bleibt beim Schlüsselwechsel: dauert der eingefrorene Zustand länger als die Restlaufzeit eines Schlüssels, ist der fällige Wechsel blockiert. **Rechnung mit den Werten aus Abschnitt 12.5:** ZSK-Laufzeit 90 d, Wechsel beginnt bei 60 d Restlaufzeit; ein eingefrorener Zustand von mehr als 60 Tagen wäre nötig, um in die Klemme zu geraten. Gegenüber einem RTO von ≤ 30 min für die Kontrollebene (K-11) ist das ein Faktor von 2.880, und die Konsole zeigt die verbleibende Frist an, sobald der Zustand länger als 24 h besteht.

Ein zweiter, unangenehmerer Punkt: ein Knoten auf der Minderheitsseite beantwortet weiterhin eine gültig signierte, aber veraltete Zone. Für den Client ist die Antwort nicht von einer aktuellen zu unterscheiden — Signaturen bescheinigen Echtheit, nicht Aktualität. Der Entwurf löst das nicht kryptographisch, sondern durch Sichtbarkeit: die Konsole zeigt je Verwaltungsknoten den beantworteten Serienstand und den Beobachtungszeitpunkt (INV-28) und markiert Abweichungen als Störung mit Verweis auf den betroffenen Knoten.

**Anforderungen**

- **R-12-11** — Autoritativer Dienst und Resolver laufen als getrennte Prozesse auf getrennten Adressen; der Resolver ist aus der Zone extern in 0 Versuchen erreichbar. Prüfbar: Portabtastung aus jeder Zone sowie Rekursionsversuch von außen.
- **R-12-12** — Jeder DNS-Eintrag trägt ein Sichtattribut als Pflichtfeld; ein Eintrag ohne Sicht ist über die API nicht anlegbar, und die Vorbelegung nennt ihre Quelle. Prüfbar: Anlegeversuch ohne Sicht und Formularprüfung im Bau (INV-15).
- **R-12-13** — Alle Verwaltungsknoten erzeugen aus derselben Sollzustandsversion byteweise identische Zoneninstanzen; ein Unterschied bricht den Bau. Prüfbar: Erzeugung auf drei Knoten und Hashvergleich über 1.000 Versionen (INV-07).
- **R-12-14** — Ein Handeintrag, der mit einem abgeleiteten Eintrag kollidiert, wird abgelehnt; die Ablehnung nennt das Quellobjekt. Prüfbar: Schreibversuch gegen einen aus einer Veröffentlichung erzeugten Namen (INV-09).
- **R-12-15** — Der veröffentlichte Serienstand einer Zone ist nach einer Wiederherstellung aus einem Export in 0 Fällen kleiner als der zuvor veröffentlichte Stand. Prüfbar: Wiederherstellung aus einem 30 Tage alten Export mit anschließender SOA-Abfrage.
- **R-12-16** — Im eingefrorenen Zustand werden Signaturen weiter erneuert und Zoneninhalte in 0 Fällen geändert; jeder Verwaltungsknoten zeigt seinen beantworteten Serienstand mit Beobachtungszeitpunkt. Prüfbar: Partitionstest über 14 Tage simulierter Zeit mit Signaturprüfung und Schreibversuch (INV-04, INV-28).

## 12.4 Bedienablauf und Ableitung von der Zielgruppe zur Antwortpolitik

### Der geforderte Ablauf

Der Bedienablauf liegt im Navigationsbereich **Netz & Namen** und besteht aus zwei Aufgaben.

| Aufgabe | Entscheidungen | Vorbelegt und daher keine Entscheidung |
|---|---|---|
| Domäne hinzufügen | 1. Name, 2. intern oder extern, 3. Geltungsbereich (Gruppe / Person / Gerät / Netzzone) | Mandant (aus dem Kontext), DNSSEC-Status (Richtlinie), TTL-Vorgaben (Richtlinie), Zoneninstanzen (folgen aus der Sichtbarkeit), Serie (Sollzustandsversion) |
| Eintrag anlegen | 1. Art, 2. Name, 3. Wert | Sicht (aus der Sichtbarkeit der Domäne), TTL (Richtlinie), Quelle (handeingegeben), Signierung (automatisch) |

Beide Aufgaben halten die Grenze von drei Entscheidungen (INV-14, K-03: "interne Domäne anlegen 3"). Der Geltungsbereich ist bewusst eine Entscheidung am **Domänenobjekt**, nicht am Eintrag: eine Domäne, deren Einträge für unterschiedliche Zielgruppen unterschiedlich gelten, ist in Wahrheit zwei Domänen, und die Oberfläche bildet das ab, statt eine Matrix aus Eintrag × Zielgruppe anzubieten.

### Ableitungsalgorithmus

Die Ableitung führt von der fachlichen Zielgruppe über eine Adressmenge zur Antwortpolitik einer Resolverinstanz. Sie ist eine reine Funktion des Objektgraphen und enthält keine Sitzungsinformation.

```
EINGABE  D  : Domaene mit D.sichtbarkeit in {intern, extern, beides}
              und D.geltungsbereich (Gruppe | Person | Geraet | Netzzone)
AUSGABE  P  : Abbildung Resolverinstanz -> Antwortpolitik

# --- Schritt 1: Zielgruppe zu Geraetemenge ---
funktion geraetemenge(g):
    falls g ist Geraet        : liefere { g }
    falls g ist Person        : liefere { d : d.eigentuemer = g }        # NUR Eigentum
    falls g ist Gruppe        : liefere vereinigung( geraetemenge(m)
                                        fuer m in mitglieder_transitiv(g) )
    falls g ist Netzzone      : liefere { d : d.netzzone = g }
    # mitglieder_transitiv ist azyklisch erzwungen (Objektmodell, Gruppe)

# --- Schritt 2: Geraetemenge zu Zonenmenge ---
funktion zonenmenge(G):
    Z := leere Menge
    fuer d in G:
        falls d.netzzone ist gesetzt und d.netzzone.durchsetzbar:
            Z := Z vereinigt { d.netzzone }
        sonst:
            vermerke_nicht_durchsetzbar(d)      # erscheint in der Konsole
    liefere Z

# durchsetzbar(z) := z.art = Overlay
#                 oder z.zuordnungsverfahren in { EAP-TLS, Gerätezertifikat }
# Eine Zone, deren Mitgliedschaft nur ueber die Quelladresse eines
# fremdverwalteten Segments bestimmt wird, ist NICHT durchsetzbar.

# --- Schritt 3: Zonenmenge zu Adressmenge ---
funktion adressmenge(Z):
    liefere vereinigung( z.adressbereich fuer z in Z )
    # Adressbereiche, nicht Einzeladressen: ein Geraet mit wechselnder
    # Adresse bleibt innerhalb seines Zonenpraefixes.

# --- Schritt 4: Antwortpolitik je Resolverinstanz ---
funktion antwortpolitik(D):
    Z := zonenmenge( geraetemenge(D.geltungsbereich) )
    P := leere Abbildung
    fuer jede Resolverinstanz r der Installation:
        falls r.netzzone in Z und D.sichtbarkeit in {intern, beides}:
            P[r] := AUTORITATIV_INTERN(D)     # interne Zoneninstanz
        sonst falls D.sichtbarkeit in {extern, beides}:
            P[r] := AUTORITATIV_EXTERN(D)     # externe Zoneninstanz
        sonst:
            P[r] := VERNEINEN(D)              # NXDOMAIN, signiert
    liefere P

# --- Schritt 5: Anwendung ---
#   P wird als abgeleitetes Artefakt berechnet, atomar auf alle
#   Resolverinstanzen angewandt und traegt den Quellverweis auf D.
#   Keine Instanz erhaelt eine Teilmenge: entweder alle oder keine (INV-10).
```

Die Politik `VERNEINEN` ist nicht dasselbe wie "nicht antworten". Eine interne Domäne, die für eine Zone nicht gilt, wird dort mit einer signierten verneinenden Antwort beschieden, damit ein Client sofort scheitert, statt in einen Zeitablauf zu laufen. Das gibt allerdings die Existenz der Zone preis, sobald die Domäne extern delegiert ist; für rein interne Domänen ist es folgenlos.

### Die vier geforderten Fälle

| Fall | Verhalten | Ehrliche Einordnung |
|---|---|---|
| **Gerät mit wechselnder Adresse** | Die Ableitung verwendet in Schritt 3 den Zonenpräfix, nicht die Einzeladresse. Solange das Gerät die Zone nicht wechselt, ist ein Adresswechsel folgenlos. | Wechselt das Gerät die Zone, wechselt die Sicht. Ein Notebook, das vom verwalteten Netz ins Gastnetz wechselt, verliert die interne Sicht — richtig, aber der Nutzer erlebt es als "der Name geht plötzlich nicht mehr". Die Konsole erklärt das im Werkzeug "warum erreicht dieses Gerät diesen Namen nicht". |
| **Nutzer an fremdem Gerät** | Die Sicht folgt dem Gerät, nicht der Person. Der Nutzer erhält die Sicht des Geräts, an dem er sitzt. | Das ist keine Einschränkung der Umsetzung, sondern eine Eigenschaft des Protokolls: eine DNS-Abfrage trägt keine Nutzeridentität. Ein Geltungsbereich "Person" wirkt ausschließlich über die Geräte im Eigentum dieser Person. Die Konsole formuliert den Geltungsbereich deshalb als "gilt für die Geräte von …" und nicht als "gilt für …". |
| **Gast** | Zone Gast, Sicht ausschließlich extern, keine interne Zoneninstanz, keine Weiterleitung an interne Namen. | Ein Gast, der eine interne Adresse kennt, erreicht den Dienst trotzdem, wenn die Firewall es zulässt. Die Sichttrennung hält ihn nicht auf; die Zonenregel tut es. |
| **Nicht verwaltetes Gerät** | Zone "Nicht verwaltet", externe Sicht plus ausdrücklich für diese Zone freigegebene interne Namen. | Gerätegranularität ist hier nicht durchsetzbar (Schritt 2 vermerkt das). Jeder Geltungsbereich Gerät oder Person, der Geräte in dieser Zone enthält, wird in der Konsole mit dem Hinweis "für diese Geräte nicht durchsetzbar" und der Liste der betroffenen Geräte angezeigt. |

### DNS-Antworten sind keine Zugriffskontrolle

Diese Feststellung gehört an diese Stelle und nicht in eine Fußnote. Eine DNS-Antwort ist eine Auskunft über einen Namen. Sie verhindert nichts.

**Wofür die Sichttrennung taugt:**

1. **Richtigkeit der Adresse.** Ein interner Client erhält die interne Adresse und erreicht den Dienst direkt, statt über den Uplink und zurück. Das spart die Schleifenführung über das NAT-Gerät, die in vielen Bestandsumgebungen gar nicht funktioniert.
2. **Verringerung der Aufklärungsfläche.** Interne Namen — Knotennamen, Dienstnamen, Speichernamen — erscheinen nicht in der öffentlichen Zone und damit nicht in öffentlichen Bestandsaufnahmen von Zertifikatsprotokollen oder Zonenabfragen.
3. **Vermeidung von Fehlbedienung.** Ein Name, der nur intern sinnvoll ist, verweist außen nicht auf eine Adresse, die einem Dritten gehört.

**Wofür sie nicht taugt:**

1. **Zugriffsschutz.** Wer die Adresse kennt, erreicht den Port, sofern die Firewall ihn öffnet. Die Zugriffsentscheidung trifft ausschließlich die aus der Veröffentlichung abgeleitete Regel (Abschnitt 12.7) und, auf Anwendungsebene, der Eingangsproxy (Abschnitt 12.8).
2. **Schutz gegen einen Innentäter.** Ein Gerät in der internen Zone sieht die interne Sicht vollständig. Eine Aufzählung aller internen Namen ist für ein Gerät mit interner Sicht trivial.
3. **Schutz gegen ein Gerät, das den Resolver wechselt.** Siehe Abschnitt 12.6.
4. **Geheimhaltung.** Ein Name, der in einem öffentlich einsehbaren Zertifikat steht, ist öffentlich, unabhängig von der Zone, in der er auflösbar ist.

**Anforderungen**

- **R-12-17** — "Domäne hinzufügen" verlangt genau 3 Pflichtentscheidungen, "Eintrag anlegen" genau 3; ein zusätzliches Pflichtfeld bricht den Bau. Prüfbar: Abgleich der Formulardefinition gegen die maschinenlesbare Aufgabendefinition (INV-14, K-03).
- **R-12-18** — Die Antwortpolitik ist eine reine Funktion aus Domäne, Geltungsbereich und Netzzonen; 1.000 Permutationen derselben Eingabemenge liefern dieselbe Politik. Prüfbar: Permutationstest über den Generator (INV-07).
- **R-12-19** — Ein Geltungsbereich, der Geräte in einer nicht durchsetzbaren Zone enthält, wird mit dem Vermerk "für diese Geräte nicht durchsetzbar" und der vollständigen Geräteliste angezeigt. Prüfbar: Anlegen eines Geltungsbereichs mit Geräten in einer fremdverwalteten Zone und Darstellungsprüfung (INV-18).
- **R-12-20** — Die Konsole beschreibt einen Geltungsbereich vom Typ Person ausschließlich als Geltung für deren Geräte; die Zeichenfolge, die eine Geltung für die Person unabhängig vom Gerät behauptet, kommt in 0 Oberflächentexten vor. Prüfbar: Textprüfung gegen die Positivliste (INV-16, K-27).
- **R-12-21** — Eine für eine Zone nicht geltende interne Domäne wird dort mit einer signierten verneinenden Antwort beschieden; ein Zeitablauf ohne Antwort tritt in 0 Fällen ein. Prüfbar: Abfrage aus jeder Zone mit Auswertung von Antwortcode und Signatur.
- **R-12-22** — Die Antwortpolitik wird auf alle Resolverinstanzen atomar angewandt; nach einer abgebrochenen Anwendung hat 0 Instanzen eine Teilmenge der neuen Politik. Prüfbar: Anwendung mit injiziertem Abbruch und anschließendem Vergleich aller Instanzen (INV-10).
- **R-12-23** — Das Werkzeug "warum erreicht dieses Gerät diesen Namen nicht" nennt für jede Kombination aus Gerät und Name genau eine der Ursachen Sicht, Zone, Firewallregel, fehlende Veröffentlichung oder fehlendes Zertifikat und verlinkt das verursachende Objekt. Prüfbar: Durchlauf über 20 konstruierte Fehlerfälle mit Trefferquote 100 % (K-26).

## 12.5 DNSSEC: Schlüssel, Rollover, Zeitrechnung, Übergabe

Die Signierung folgt RFC 4033, RFC 4034 und RFC 4035; die Betriebsführung folgt RFC 6781. Jede Zoneninstanz wird signiert, auch die interne, weil eine unsignierte interne Zone einen validierenden Resolver zwingt, für interne Namen eine Ausnahme zu führen, und Ausnahmen sind der Ort, an dem Validierung still abgeschaltet wird.

### Schlüsselarten und Laufzeiten

| Schlüssel | Aufgabe | Zielwert Laufzeit | Wechselverfahren | Ablage |
|---|---|---|---|---|
| Zonensignaturschlüssel (ZSK) | signiert alle Datensätze außer dem Schlüsseldatensatz | 90 d, Wechsel beginnt bei 60 d Restlaufzeit | Vorveröffentlichung | je Verwaltungsknoten ein eigener, im TPM des Knotens erzeugt |
| Schlüsselsignaturschlüssel (KSK) | signiert den Schlüsseldatensatz; von ihm wird die Delegationssignatur gebildet | 365 d, Nachfolger ab 90 d Restlaufzeit vorveröffentlicht | Doppelsignatur mit Übergabe an den Elternbereich | einmal je Zone, TPM-gebunden auf dem Knoten, der das ausgebende CA-Modul trägt |

**Verfahren: Ed25519 (RFC 8032).** Der Grund ist die Antwortgröße und damit die Fragmentierungsfreiheit.

**Rechnung zur Größe des Schlüsseldatensatzes.** Ein Schlüsseleintrag besteht aus 4 Byte festen Feldern und dem öffentlichen Schlüssel. Maßgeblich für ihre Zahl sind die autoritativ antwortenden Knoten und nicht die Stimmzahl: jeder Mitleser, der ebenfalls autoritativ antwortet, führt einen eigenen ZSK und vergrößert den Datensatz um einen Eintrag (Offener Punkt 3). Bei fünf Stimmknoten (also fünf ZSK, INV-05) und zwei KSK während eines Wechsels ergeben sich sieben Einträge:

```
  Ed25519:        7 * (4 + 32)  =  252 Byte Schluesselmaterial
  + 2 Signaturen: 2 * (18 + 64 + ~20 Signiername) = 204 Byte
  + Fragenteil und Kopf                            ~  60 Byte
  ------------------------------------------------------------
  Summe                                            ~ 516 Byte

  ECDSA ueber P-256: 7 * (4 + 64) = 476 + 2 * (18 + 72 + 20) + 60 = 756 Byte
  RSA mit 2048 bit:  7 * (4 + 259) = 1841 + 2 * (18 + 256 + 20) + 60 = 2489 Byte
```

**Festlegung: angekündigte EDNS-Puffergröße 1232 Byte.** Herleitung: 1280 Byte ist die kleinste MTU, die IPv6 garantiert; abzüglich 40 Byte IPv6-Kopf und 8 Byte UDP-Kopf bleiben 1232 Byte Nutzlast, die ohne Fragmentierung durch jeden IPv6-Pfad passen. Der Ed25519-Fall liegt mit 516 Byte um den Faktor 2,4 darunter, der ECDSA-Fall mit 756 Byte noch darunter, der RSA-Fall mit 2.489 Byte darüber und erzwänge damit bei jeder Schlüsselabfrage einen Wechsel auf TCP. RSA ist deshalb nicht vorgesehen. Ed25519 ist die Vorgabe; wo ein Elternbereich das Verfahren nicht annimmt — was eine Eigenschaft des jeweiligen Registers ist und sich ändert, weshalb hier keine Liste behauptet wird —, weicht die Domäne auf ECDSA über P-256 aus, und die Konsole zeigt das Verfahren je Domäne an.

**Warum je Verwaltungsknoten ein eigener ZSK.** Jeder Verwaltungsknoten ist autoritativ und erzeugt dieselbe Zone (Technologiefestlegung 8). Ein gemeinsamer ZSK verlangte, dass der private Schlüssel den erzeugenden Knoten verlässt, was INV-20 verbietet. Verworfene Alternative: ein einziger signierender Knoten verteilt die fertigen Signaturen über das Replikationsprotokoll. Grund der Ablehnung: dann ist die Signaturerneuerung eine Konsensoperation und im eingefrorenen Zustand blockiert, womit der Vorteil aus Abschnitt 12.3 entfiele. Der Preis der gewählten Lösung ist der wachsende Schlüsseldatensatz; die Rechnung oben zeigt, dass er bei fünf Stimmknoten (INV-05) und Ed25519 unkritisch bleibt.

### Signaturgültigkeit und Erneuerung

| Größe | Zielwert | Herleitung |
|---|---|---|
| Signaturgültigkeit | 14 d | lang genug, dass eine Störung von einer Woche folgenlos bleibt; kurz genug, dass eine Wiedereinspielung alter Daten begrenzt ist |
| Erneuerung ab | 7 d Restgültigkeit | Hälfte der Gültigkeit; bei stündlichem Versuch sind das 7 × 24 = 168 Versuche vor Ablauf |
| Erneuerungslauf | mindestens alle 3 d je Zone | Reserve gegen ausgelassene Läufe mit Faktor 2,3 gegenüber dem Erneuerungsfenster |
| Vordatierung des Beginns | 1 h in die Vergangenheit | die eigene Uhrenabweichung ist auf 500 ms begrenzt (K-30), die eines fremden validierenden Resolvers nicht |
| Warnung | 3 d Restgültigkeit | erscheint im Überblick am Domänenobjekt |
| Eskalation | 1 d Restgültigkeit | Störung höchster Stufe, weil danach jeder Name der Zone unauflösbar wird |

**Warum eine abgelaufene Signatur schlimmer ist als keine Signierung.** Eine unsignierte Zone wird von einem validierenden Resolver ausgeliefert. Eine signierte Zone mit abgelaufener Signatur wird verworfen: jeder Name der Zone ist für jeden validierenden Resolver gleichzeitig unauflösbar, und der Fehler betrifft alle Clients zugleich, ohne Teilausfall und ohne Vorwarnung für den Nutzer. Die Verhinderung ruht deshalb auf drei voneinander unabhängigen Mechanismen:

1. **Quorumunabhängigkeit.** Die Erneuerung ist kein Sollzustandsschreibvorgang (Abschnitt 12.3) und läuft im eingefrorenen Zustand weiter.
2. **Lokale Überwachung.** Jeder autoritative Knoten prüft minütlich die kleinste verbleibende Signaturgültigkeit über alle seine Zonen und meldet sie als Istzustand mit Beobachtungszeitpunkt (INV-28).
3. **Unabhängige Außenprüfung.** Ein Prüflauf fragt die Zone von außerhalb des eigenen Resolvers ab und validiert vollständig gegen den Vertrauensanker. Das erkennt den Fall, in dem die interne Erzeugung meldet, alles sei in Ordnung, die ausgelieferte Zone aber nicht validiert — etwa weil ein Schlüssel im Datensatz fehlt.

### Zeitrechnung der Rollover

Die Wartezeiten eines Rollovers folgen aus TTL und Verbreitungszeit, nicht aus Gewohnheit.

```
  Groessen:
    TTL_DNSKEY   = 3600 s   (extern; intern 300 s)
    TTL_max      = 3600 s   (groesste TTL eines signierten Datensatzes, extern)
    D_prop       =   60 s   (Zonenwechsel auf allen Verwaltungsknoten plus
                             Zwischenspeicherbereinigung der eigenen Resolver;
                             K-17 setzt p95 <= 5 s an, 60 s ist die Reserve)
    D_eltern     = Annahme, nicht kontrollierbar: TTL der Delegationssignatur
                   beim Elternbereich, angenommen 86400 s

  ZSK-Rollover, Vorveroeffentlichung:
    Phase 1 (neuer ZSK veroeffentlicht, signiert noch nicht):
        I_pub >= TTL_DNSKEY + D_prop = 3600 + 60 = 3660 s = 1,02 h
        Zielwert 24 h   -> Reservefaktor 23,6
    Phase 2 (neuer ZSK signiert, alter noch veroeffentlicht):
        I_ret >= TTL_max + D_prop = 3600 + 60 = 3660 s = 1,02 h
        Zielwert 24 h   -> Reservefaktor 23,6
    Gesamtdauer eines ZSK-Rollovers: 48 h
    Anteil an der Schluessellaufzeit: 48 h / 2160 h = 2,2 %

  KSK-Rollover mit Uebergabe an den Elternbereich:
    Phase 1 (neuer KSK veroeffentlicht, Datensatz doppelt signiert):
        >= TTL_DNSKEY + D_prop = 3660 s;  Zielwert 24 h
    Phase 2 (neue Delegationssignatur beim Elternbereich eingetragen,
             Bestaetigung durch direkte Abfrage der Elternserver):
        Wartezeit >= D_eltern + Verbreitung des Elternbereichs
        Zielwert 7 d   (Annahme 86400 s Eltern-TTL, Faktor 7 Reserve,
                        weil weder TTL noch Verbreitung des Elternbereichs
                        kontrollierbar oder zugesichert sind)
    Phase 3 (alter KSK entfernt): erst nach bestaetigter Phase 2
    Gesamtdauer: >= 8 d
```

Der Zielwert von 7 Tagen in Phase 2 ist eine Setzung, keine Ableitung aus einer bekannten Eigenschaft: die TTL der Delegationssignatur und die interne Verbreitungszeit eines Registers sind von außen nicht zugesichert. Der Entwurf ersetzt die fehlende Zusage durch Messung: Phase 3 beginnt nicht nach Ablauf einer Frist, sondern nachdem **jeder autoritative Server des Elternbereichs** die neue Delegationssignatur und **keiner** die alte ausschließlich geliefert hat. Die Frist ist nur die Obergrenze, nach der die Konsole den Vorgang als gestört meldet.

### Übergabe der Delegationssignatur

| Weg | Ablauf | Voraussetzung |
|---|---|---|
| **Anbieterschnittstelle** | Konnektorbindung zum Register oder DNS-Anbieter; `plan` zeigt die Änderung nebenwirkungsfrei (INV-08), `apply` trägt sie ein, `observe` liest den Ist-Eintrag zurück | Der Anbieter hat eine Schnittstelle, und ein Konnektormanifest existiert |
| **Anzeige und Prüfung** | Die Konsole zeigt die Delegationssignatur als kopierbaren Wert; der Bediener trägt sie beim Register ein; Atrium prüft anschließend durch direkte Abfrage der Elternserver und setzt den Delegierungsstatus | immer verfügbar; einziger Weg ohne Anbieterschnittstelle |

In beiden Fällen ist der Delegierungsstatus der Domäne ein **Istzustand**: er entsteht durch Beobachtung des Elternbereichs, trägt einen Beobachtungszeitpunkt (INV-28) und ist nie eine Eingabe des Bedieners. Ein Domänenobjekt im Zustand "signiert, aber nicht delegiert" ist ein sichtbarer Zustand und kein Fehler; ein Domänenobjekt, das behauptet delegiert zu sein, ohne dass die Abfrage es bestätigt, existiert nicht.

**Anforderungen**

- **R-12-24** — Jede Zoneninstanz, auch die interne, ist signiert; eine unsignierte Zoneninstanz existiert in 0 Fällen. Prüfbar: Abfrage aller Zoneninstanzen mit Signaturprüfung.
- **R-12-25** — Der Schlüsseldatensatz jeder Zone passt mit Signaturen in 1232 Byte; ein Verfahren, das diese Grenze überschreiten würde, wird bei der Auswahl abgelehnt. Prüfbar: Größenmessung der Antwort bei fünf Stimmknoten und laufendem KSK-Rollover.
- **R-12-26** — Kein privater Zonensignaturschlüssel verlässt den Knoten, auf dem er erzeugt wurde; die Zahl der ZSK im Schlüsseldatensatz entspricht der Zahl der Verwaltungsknoten. Prüfbar: Inhaltsprüfung aller Exporte und Vergleich Schlüsselzahl gegen Knotenzahl (INV-20).
- **R-12-27** — Die Signaturerneuerung läuft im eingefrorenen Zustand weiter; nach 14 Tagen ohne Quorum validiert jede Zone weiterhin. Prüfbar: Partitionstest über 14 Tage simulierter Zeit mit externer Validierung (INV-04, INV-25).
- **R-12-28** — Der Anteil der Zonen mit weniger als 24 h Restsignaturgültigkeit beträgt 0; das Unterschreiten von 3 d erzeugt eine Meldung im Überblick mit Verweis auf das Domänenobjekt. Prüfbar: Dauerüberwachung im Lasttest und Auslösung durch injizierten Erneuerungsfehler (INV-18).
- **R-12-29** — Der alte KSK wird erst entfernt, nachdem jeder autoritative Server des Elternbereichs die neue Delegationssignatur geliefert hat; ein Entfernen allein aufgrund einer abgelaufenen Frist findet in 0 Fällen statt. Prüfbar: Rollover-Test mit einem Elternserver, der die alte Signatur weiterliefert.

## 12.6 Resolverpolitik

### Auflösungsweg

**Festlegung: vollständige Rekursion ab der Wurzel ist die Vorgabe; Weiterleitung an einen Fremdresolver ist eine ausdrückliche Einstellung je Netzzone.** Die verworfene Alternative ist die Weiterleitung als Vorgabe. Grund der Ablehnung: ein weitergeleiteter Resolver macht die Namensauflösung der gesamten Installation von einem Dritten abhängig, der zugleich das vollständige Abfrageprofil sieht. Die Kostenseite wird benannt: bei kaltem Zwischenspeicher ist die Rekursion langsamer als eine Weiterleitung an einen großen, warmen Fremdresolver, und die eigene Adresse wird für jeden abgefragten autoritativen Server sichtbar. Wo ein Anschluss die Rekursion nicht zulässt, ist die Weiterleitung der einzige Weg; sie läuft dann über DNS over TLS (RFC 7858) mit Zertifikatsprüfung der Gegenstelle, nicht über unverschlüsseltes DNS.

Der Weg zu autoritativen Servern bei voller Rekursion ist unverschlüsselt. Das ist keine Nachlässigkeit des Entwurfs, sondern der Stand der Technik: ein flächendeckend nutzbarer verschlüsselter Transport zu beliebigen autoritativen Servern ist nicht vorhanden. Wer Vertraulichkeit der Abfragen gegenüber dem Netzpfad benötigt, muss weiterleiten und gewinnt Vertraulichkeit gegenüber dem Pfad genau im Tausch gegen die Preisgabe an den Weiterleitungsziel; die Konsole stellt diesen Tausch beim Setzen der Einstellung dar.

### Validierung

Jeder Resolver validiert (RFC 4033, RFC 4034, RFC 4035) gegen den Vertrauensanker der Wurzel und gegen die eigene interne Zone. Der Vertrauensanker der Wurzel wird über das genormte Verfahren zur automatisierten Vertrauensankererneuerung nachgeführt. In einer Installation ohne ausgehende Verbindung altert der Anker; nach einem Schlüsselwechsel der Wurzel wäre die Validierung fremder Namen dann unmöglich. Der Entwurf macht daraus einen sichtbaren Zustand: der Anker trägt sein Alter, und ein Anker, der älter als ein gesetzter Schwellenwert ist, erscheint als Störung. Der Fall selbst ist nicht lösbar, solange keine Verbindung besteht.

### Sperrlisten

Sperrlisten sind Richtlinienobjekte je Netzzone, nicht Resolvereinstellungen. Die Antwort auf einen gesperrten Namen ist der Punkt, an dem Sperrung und Validierung in Konflikt geraten:

| Antwortform | Wirkung beim Client | Konflikt mit Validierung |
|---|---|---|
| Verneinende Antwort (NXDOMAIN) | Client scheitert sofort, ohne Erklärung | Ist der gesperrte Name in einer signierten Zone, kann der Resolver die Verneinung nicht beweisen; ein validierender Auflöser auf dem Endgerät verwirft die Antwort |
| Umlenkung auf eine Erklärseite | Client sieht den Grund der Sperrung | Dieselbe Verletzung; zusätzlich erhält die Erklärseite die Anfrage samt Kontext |
| Ablehnung (REFUSED) | Client scheitert sofort und wechselt gegebenenfalls den Resolver | keine Signaturverletzung, aber die Umgehung wird begünstigt |

**Festlegung:** Für Namen ohne Signatur wird auf eine Erklärseite umgelenkt; für Namen in signierten Zonen wird abgelehnt, und die Konsole benennt am Richtlinienobjekt, dass ein Endgerät mit eigenem validierendem Auflöser in diesem Fall nur eine allgemeine Fehlermeldung sieht. Die verworfene Alternative — in allen Fällen umzulenken — wurde abgelehnt, weil sie auf validierenden Endgeräten einen nicht diagnostizierbaren Fehler erzeugt und zugleich die Validierung als unzuverlässig erscheinen lässt.

### Fallstricke der Sichttrennung und die jeweilige Gegenmaßnahme

| Fallstrick | Wirkung | Gegenmaßnahme | Restrisiko |
|---|---|---|---|
| **Zwischengespeicherte Antwort beim Zonenwechsel des Geräts** | Ein Notebook trägt die interne Adresse mit ins Gastnetz und erreicht den Namen nicht mehr | Interne TTL 300 s (K-17) begrenzt die Fehlzeit; der Name existiert auch extern und antwortet dort, statt zu verneinen | Bis zu 5 min nach dem Wechsel schlägt der Zugriff fehl; ein Gerät ohne Neustart des Auflösers kann länger halten |
| **Umgehung über einen Fremdresolver** | Das Gerät trägt von Hand einen öffentlichen Resolver ein und verliert die interne Sicht | In Zonen mit erzwungener Sicht wird ausgehender Verkehr auf 53/udp und 53/tcp zu anderen Zielen als dem Zonenresolver verworfen | Wirkt nicht gegen verschlüsselte Auflösung über 443/tcp |
| **Verschlüsselte Auflösung im Browser** | Der Browser löst über HTTPS bei einem Anbieter auf und umgeht den Zonenresolver vollständig | Auf verwalteten Geräten setzt eine Richtlinie den internen Resolver fest (Technologiefestlegung 8c); zusätzlich beantwortet der Zonenresolver eine herstellerdefinierte Prüfdomäne verneinend, was die automatische Aktivierung bei manchen Browsern unterdrückt | Die Prüfdomäne ist eine Herstellerkonvention ohne Norm und kann jederzeit entfallen; auf nicht verwalteten Geräten ist nichts durchsetzbar |
| **Anwendungseigene Auflösung** | Eine Anwendung bringt ihren eigenen Auflöser mit und ignoriert die Systemeinstellung | keine wirksame Gegenmaßnahme außer der Firewall auf der Zielverbindung | Nicht lösbar; die Sichttrennung ist an dieser Stelle wirkungslos |
| **Divergenz der beiden Zoneninstanzen** | Interne und externe Instanz enthalten unterschiedliche Werte für denselben Namen, ohne dass es auffällt | Sichtattribut je Eintrag statt zweier Zonen (Technologiefestlegung 8b); Bautest auf Gleichheit der Erreichbarkeitsmengen über beide Familien (R-12-04) | Bleibt für handeingetragene Werte bestehen, die absichtlich verschieden sind; die Konsole zeigt solche Namen mit beiden Werten nebeneinander |
| **Verneinende Antwort im Zwischenspeicher eines Fremdresolvers** | Ein neu angelegter externer Name ist bei Dritten bis zum Ablauf der SOA-Verweildauer unauflösbar | SOA-Verweildauer extern 300 s statt des sonst üblichen höheren Wertes; die Konsole nennt die Wartezeit beim Anlegen | 300 s, nicht beeinflussbar |

Die Zeile zur anwendungseigenen Auflösung ist die wichtigste dieser Tabelle, weil sie die Grenze des gesamten Ansatzes markiert: Namensauflösung ist eine Auskunft, die sich ein Gerät auch anderswo holen kann. Alles, was wirklich durchgesetzt werden muss, wird an der Verbindung durchgesetzt, nicht am Namen.

**Anforderungen**

- **R-12-30** — Der Resolver validiert jede Antwort; eine Antwort mit ungültiger Signatur wird in 0 Fällen an einen Client weitergegeben. Prüfbar: Abfrage gegen eine absichtlich fehlerhaft signierte Testzone.
- **R-12-31** — In einer Zone mit erzwungener Sicht erreicht ein Gerät 0 fremde Ziele auf 53/udp und 53/tcp; der Versuch erzeugt einen Eintrag im Betriebsprotokoll mit Quelladresse. Prüfbar: Abfrageversuch gegen eine fremde Adresse aus der Zone.
- **R-12-32** — Das Alter des Vertrauensankers der Wurzel wird als Istzustand mit Beobachtungszeitpunkt geführt; ein Überschreiten des Schwellenwerts erzeugt eine Störung im Überblick. Prüfbar: Uhrvorstellung über den Schwellenwert hinaus (INV-18, INV-28).
- **R-12-33** — Ein gesperrter Name in einer signierten Zone wird abgelehnt und nicht umgelenkt; das Richtlinienobjekt zeigt die Folge für validierende Endgeräte im Klartext. Prüfbar: Sperrung eines signierten Namens und Auswertung von Antwortcode und Oberflächentext.
- **R-12-34** — Die Einstellung "Weiterleitung" zeigt vor dem Speichern, an welches Ziel das vollständige Abfrageprofil der Zone übertragen wird; ohne diese Anzeige ist die Einstellung nicht speicherbar. Prüfbar: Formularprüfung im Bau (INV-15).

## 12.7 Firewallsynthese

### Eingangsgrößen und Abbildung

Der Regelsatz ist eine Funktion `R = f(G)` über dem Objektgraphen `G`. Es gibt genau vier Eingangsarten und keine fünfte:

| Eingang | Erzeugt | Beispiel |
|---|---|---|
| **Veröffentlichung** | eine Akzeptanzregel je Protokoll und Port, deren Quellmenge aus dem Zugriffskreis folgt | `ticket.kunde-a.example` intern für Gruppe "Vertrieb" → Akzeptanz auf 443/tcp aus der Adressmenge der Geräte dieser Gruppe |
| **Zuweisung** | Netzfreigaben, wo ein Dienst eine gerichtete Verbindung zu einem anderen Dienst benötigt | Ticketsystem → Datenbankdienst |
| **Netzzone** | Zonengrenzen, Zonenmarkierung, Default-Deny-Vorgabe | Gast erreicht nur den Uplink |
| **Konnektorbindung** | Ausgangs-Positivliste des Konnektorprozesses in seinem eigenen Netznamensraum (INV-21) | Konnektor zu einem Postfachanbieter erreicht genau dessen Endpunkt |

Die Plattformregeln — Overlay, Kontrollebenenports, Kopplungsendpunkt, Zeitsynchronisation — folgen aus den Rollen des Knotenobjekts und sind damit ebenfalls Ableitungen, keine Grundausstattung.

### Kettenaufbau und Reihenfolge

```
  Tabelle: table inet atrium          (eine Tabelle fuer IPv4 und IPv6)

  Reihenfolge in der Eingangskette, von oben:
   1. Verbindungsverfolgung: established,related -> accept
      (zuerst, weil jede weitere Pruefung fuer bestehende Verbindungen
       reine Rechenlast waere)
   2. Verbindungsverfolgung: invalid -> drop
   3. Loopback -> accept
   4. ICMPv6-Pflichttypen und ICMP-Pflichttypen mit Ratenbegrenzung
      -> accept  (ohne diese Zeile bricht die Pfad-MTU-Ermittlung
                  und die Nachbarschaftserkennung)
   5. Zonenmarkierung: Quelladresse -> Markierung
   6. Sprung in die Zonenkette gemaess Markierung (Verteilertabelle)
   7. Fallthrough -> Abweisungskette (ratenbegrenzte Protokollzeile, drop)

  Kettenpolitik: drop in Eingang, Weiterleitung und Ausgang.
```

Die Ausgangskette hat ebenfalls die Politik `drop`. Das ist unbequem und wird begründet: ohne sie erfüllt ein kompromittierter Dienst jede ausgehende Verbindung, und die Zusage aus INV-21 gälte nur für Konnektorprozesse, nicht für Dienste. Die Kostenseite ist real: jede vergessene ausgehende Verbindung eines Katalogeintrags erscheint als Störung statt als stiller Erfolg. Der Entwurf nimmt das in Kauf, weil eine vergessene Ausgangsregel eine sichtbare Fehlfunktion erzeugt, während eine fehlende Ausgangsbeschränkung unsichtbar bleibt, bis sie ausgenutzt wird.

### Mengen

Adressmengen sind benannte Mengen mit Intervallkennzeichen, keine ausgerollten Einzelregeln. Der Grund ist die Ableitungsgröße: eine Veröffentlichung für eine Gruppe mit 200 Geräten erzeugt als Einzelregeln 200 Zeilen je Protokoll und Adressfamilie, als Menge eine Zeile und 200 Elemente, die im Kern als Suchstruktur mit logarithmischer Nachschlagezeit gehalten werden. Bei Annahme 150 Diensten, 500 Veröffentlichungen und durchschnittlich 3 Quellbereichen je Veröffentlichung ergibt die Mengenform 500 Regelzeilen und 1.500 Elemente gegenüber 1.500 Regelzeilen in der ausgerollten Form.

### Atomarer Tausch

Der erzeugte Regelsatz wird als **eine Datei** an das Regelwerkzeug übergeben, und diese Datei ist eine Transaktion:

```
  table inet atrium                 # legt an, falls nicht vorhanden
  delete table inet atrium          # loescht den vorherigen Stand
  table inet atrium { ... }         # vollstaendiger neuer Stand
```

Entweder wird die gesamte Datei angewandt oder keine Zeile davon; ein teilweise angewandter Regelsatz kann nicht entstehen. Verworfene Alternative: `flush ruleset`. Grund der Ablehnung: das löscht auch Tabellen fremder Software und hinterließe einen Zustand, den Atrium nicht berechnet hat. Fremde Tabellen werden stattdessen erkannt und als Abweichung gemeldet (INV-02).

**Fehlerfälle und Rückfall:**

| Fehler | Verhalten | Ergebnis |
|---|---|---|
| Erzeugung schlägt fehl (Objektgraph unvollständig) | Es wird nichts übergeben | Vorheriger, vollständiger Stand bleibt; Störung mit Verweis auf das fehlende Objekt |
| Übergabe schlägt fehl (Syntaxfehler, Kernelfehler) | Transaktion wird verworfen | Vorheriger Stand bleibt; Störung, und der erzeugte Satz wird zur Diagnose als Artefakt am Vorgang abgelegt |
| Systemstart ohne gültigen Regelsatz | Alle Schnittstellen außer Loopback bleiben administrativ abgeschaltet, bis der Satz angewandt ist; atrium-node startet keine Dienste | Kein Zeitfenster ohne Filter |
| Abweichender Prüfwert des laufenden Satzes | atrium-node wendet den erzeugten Satz erneut an und schreibt ein Auditereignis "Abweichung korrigiert" | Konvergenz (INV-02) |

Die dritte Zeile ist die eigentlich gefährliche. Ohne sie wäre die Kettenpolitik `drop` wirkungslos, weil bei fehlender Tabelle die Voreinstellung des Kerns gilt und Pakete passieren. Die Antwort auf INV-10 ist deshalb nicht allein die Kettenpolitik, sondern die Kopplung an den Schnittstellenzustand.

### Warum keine verwaiste Regel entstehen kann

**Begründungsskizze.** Sei `G_t` der Objektgraph zum Zeitpunkt `t` und `f` die Erzeugungsfunktion. Behauptung: zu jedem Zeitpunkt gilt `R_t = f(G_t)`, und damit hat jede Regel in `R_t` ein Urbild in `G_t`.

1. `f` ist total und deterministisch: sie liest ausschließlich den Sollzustand, verwendet keine Uhrzeit, keinen Zufall und keine Iterationsreihenfolge einer ungeordneten Struktur. Prüfbar durch zweifache Erzeugung mit Byte-Vergleich (INV-07).
2. Jede Anwendung ersetzt den **vollständigen** Satz, nicht eine Differenz. Es gibt keine Operation "Regel hinzufügen" und keine Operation "Regel entfernen".
3. Die Anwendung ist atomar. Es gibt keinen Zwischenzustand aus alten und neuen Regeln.
4. Aus 1 bis 3 folgt: `R_t` liegt stets im Bild von `f`. Eine Regel ohne Urbild in `G_t` wäre ein Element von `R_t \ f(G_t)` und widerspräche der Gleichheit.

**Die Begründung hat drei benannte Lücken.** Erstens setzt sie voraus, dass `f` fehlerfrei ist; ein Fehler in `f` erzeugt eine falsche, aber nicht verwaiste Regel — die Invariante schützt gegen Reste, nicht gegen Denkfehler. Zweitens kann ein Prozess mit ausreichenden Rechten eine eigene Tabelle anlegen; da der Kern ein Paket akzeptiert, sobald irgendeine Kette im selben Einhängepunkt es akzeptiert, hebelt eine fremde Tabelle die Sperre aus. Atrium erkennt das und meldet es, kann es aber nicht verhindern. Drittens — und das ist die praktisch häufigste Lücke — überlebt der **Verbindungsverfolgungszustand** den Regeltausch: eine bestehende Verbindung zu einem Dienst, dessen Veröffentlichung zurückgezogen wurde, läuft über Zeile 1 der Eingangskette weiter. Gegenmaßnahme: beim Zurückziehen einer Veröffentlichung werden die Verfolgungseinträge, deren Ziel der betroffenen Adresse und dem betroffenen Port entsprechen, ausdrücklich gelöscht. Ohne diesen Schritt ist "zurückgezogen" in der Oberfläche wahr und im Netz falsch.

### Beispielhafter erzeugter Regelsatz

```
# erzeugt aus Sollzustandsversion 184213
# Pruefwert 9f2c4a1e...; nicht editierbar (INV-09)
table inet atrium
delete table inet atrium
table inet atrium {

  # --- Mengen: vollstaendig aus dem Objektgraphen ---------------------
  set z_knoten_v6 { type ipv6_addr; flags interval
    elements = { fd7a:3c19:88e2:0000::/64 } }            # Netzzone 01J9A...
  set z_geraet_v6 { type ipv6_addr; flags interval
    elements = { fd7a:3c19:88e2:0003::/64 } }            # Netzzone 01J9B...
  set z_gast_v6 { type ipv6_addr; flags interval
    elements = { fd7a:3c19:88e2:0004::/64 } }            # Netzzone 01J9C...
  set pub_01J9D_quellen_v6 { type ipv6_addr; flags interval
    elements = { fd7a:3c19:88e2:0003::/64 } }            # Veroeff. 01J9D...
  set icmp6_pflicht { type icmpv6_type
    elements = { destination-unreachable, packet-too-big, time-exceeded,
                 parameter-problem, echo-request, echo-reply,
                 nd-router-solicit, nd-router-advert,
                 nd-neighbor-solicit, nd-neighbor-advert } }
  set icmp4_pflicht { type icmp_type
    elements = { destination-unreachable, time-exceeded, echo-request,
                 echo-reply } }

  # --- Eingang --------------------------------------------------------
  chain eingang {
    type filter hook input priority filter; policy drop;
    ct state established,related accept
    ct state invalid drop
    iif lo accept
    meta l4proto ipv6-icmp icmpv6 type @icmp6_pflicht \
      limit rate 100/second burst 50 packets accept
    meta l4proto icmp icmp type @icmp4_pflicht \
      limit rate 100/second burst 50 packets accept
    jump zonenmarkierung
    meta mark vmap { 0x10 : jump z_knoten,
                     0x20 : jump z_geraet,
                     0x30 : jump z_gast,
                     0x40 : jump z_extern }
    jump abweisen
  }

  chain zonenmarkierung {
    ip6 saddr @z_knoten_v6 meta mark set 0x10 return
    ip6 saddr @z_geraet_v6 meta mark set 0x20 return
    ip6 saddr @z_gast_v6   meta mark set 0x30 return
    meta mark set 0x40 return                      # alles Uebrige: extern
  }

  # --- Zone Knoten-Overlay: Quelle = Knotenrollen ----------------------
  chain z_knoten {
    udp dport 51820 accept                         # Overlay
    tcp dport 8400 accept                          # Kern-API,      Rolle Stimmknoten
    tcp dport 8401 accept                          # Raft-Peer,     Rolle Stimmknoten
    tcp dport 8402 accept                          # Agentenkanal,  Rolle Dienstträger
    tcp dport 8404 accept                          # xDS,           Rolle Eingangsträger
    tcp dport 8406 accept                          # ACME intern
    tcp dport 8408 accept                          # Telemetrie
    tcp dport 7789-7820 accept                     # Blockreplikation, je Ressource
    udp dport 123 accept                           # Zeit nach innen
    return
  }

  # --- Zone verwaltete Geraete ----------------------------------------
  chain z_geraet {
    udp dport 53 accept                            # Resolver dieser Zone
    tcp dport 53 accept
    tcp dport 853 accept                           # DNS over TLS
    ip6 saddr @pub_01J9D_quellen_v6 tcp dport 443 accept   # Veroeff. 01J9D...
    udp dport 1812 accept                          # RADIUS, Netzzugangszone
    return
  }

  # --- Zone Gast: nur Uplink, kein interner Dienst ---------------------
  chain z_gast {
    udp dport 53 accept                            # Resolver, Sicht extern
    tcp dport 53 accept
    return
  }

  # --- Zone extern: nur veroeffentlichte Namen -------------------------
  chain z_extern {
    tcp dport 443 accept                           # Eingang, SNI-Auswahl
    udp dport 443 accept                           # HTTP/3
    tcp dport 80  accept                           # Umleitung und ACME
    udp dport 53  accept                           # autoritativer DNS
    tcp dport 53  accept
    tcp dport 8407 accept                          # Sperrliste und OCSP
    return
  }

  # --- Weiterleitung: nur zwischen Dienstzonen mit Zuweisung -----------
  chain weiterleitung {
    type filter hook forward priority filter; policy drop;
    ct state established,related accept
    ct state invalid drop
    # je Zuweisung genau eine gerichtete Zeile, hier eine beispielhaft:
    ip6 saddr fd7a:3c19:88e2:0701::/64 \
      ip6 daddr fd7a:3c19:88e2:0702::/64 \
      tcp dport 5432 accept                        # Zuweisung 01J9E...
    jump abweisen
  }

  # --- Ausgang --------------------------------------------------------
  chain ausgang {
    type filter hook output priority filter; policy drop;
    ct state established,related accept
    oif lo accept
    meta l4proto { icmp, ipv6-icmp } accept
    udp dport 53 accept                            # eigener Resolver
    tcp dport { 80, 443 } accept                   # ACME, Abbildbezug
    udp dport 123 accept                           # Zeit nach aussen
    tcp dport 4460 accept                          # Zeitschluesselaustausch
    tcp dport 6514 accept                          # Protokollausleitung
    udp dport 51820 accept                         # Overlay
    jump abweisen
  }

  chain abweisen {
    limit rate 10/minute burst 5 packets \
      log prefix "atrium-abgewiesen " level info
    drop
  }
}
```

Jede Zeile trägt im erzeugten Satz einen Kommentar mit der Kennung ihres Quellobjekts. Die nur lesbare Firewallsicht im Bereich **Netz & Namen** zeigt genau diese Zuordnung; einen Dialog zum Anlegen einer Regel gibt es nicht (INV-09).

**Anforderungen**

- **R-12-35** — Die API kennt für Firewallregeln 0 Schreiboperationen; jede angezeigte Regel nennt die Kennung ihres Quellobjekts. Prüfbar: Endpunktprüfung gegen die API-Fassade und Vollständigkeitsprüfung der Quellverweise über alle Regeln (INV-01, INV-09).
- **R-12-36** — Zweifache Erzeugung aus derselben Sollzustandsversion liefert byteweise identische Regelsätze; ein Unterschied bricht den Bau. Prüfbar: Doppelerzeugung über 1.000 Versionen mit Hashvergleich (INV-07).
- **R-12-37** — Die Anwendung erfolgt als eine Transaktion; nach einem injizierten Abbruch ist der vorherige vollständige Satz aktiv, und 0 Pakete passieren eine Kette ohne Politik. Prüfbar: Erreichbarkeitstest nach abgebrochener Anwendung (INV-10).
- **R-12-38** — Startet ein Knoten ohne gültigen Regelsatz, bleiben alle Schnittstellen außer Loopback abgeschaltet und es startet 0 Dienste. Prüfbar: Start mit beschädigtem Regelsatz und Portabtastung von außen.
- **R-12-39** — Beim Zurückziehen einer Veröffentlichung werden die zugehörigen Verbindungsverfolgungseinträge gelöscht; eine vor dem Zurückziehen aufgebaute Verbindung überträgt danach 0 Byte. Prüfbar: Dauerverbindung, Zurückziehen, Messung des weiteren Durchsatzes.
- **R-12-40** — Eine fremde nftables-Tabelle wird innerhalb eines Abgleichzyklus erkannt und als Abweichung mit Tabellennamen gemeldet; sie wird nicht stillschweigend entfernt. Prüfbar: Anlegen einer fremden Tabelle und Auswertung von Meldung und Tabellenbestand (INV-02).
- **R-12-41** — Die ICMP- und ICMPv6-Pflichttypen sind in jeder Zone zugelassen; eine Pfad-MTU-Ermittlung über jeden Overlay-Pfad gelingt. Prüfbar: Messreihe mit Paketen oberhalb der Pfad-MTU über alle Knotenpaare.

## 12.8 Eingangsproxy

### Konfiguration aus Objekten

Der Eingang ist Envoy als Datenebene, ausschließlich dynamisch über den in atrium-core eingebauten xDS-Server auf 8404/tcp gesteuert (Technologiefestlegung 7). Es existiert keine Konfigurationsdatei und kein Neuladen. Die Abbildung ist gerichtet und vollständig:

```
  Veroeffentlichung V
    V.hostname          -> Filterketten-Auswahl ueber den Namensanzeiger (SNI)
                           und virtueller Wirt in der Routenkonfiguration
    V.dienstverweis     -> Endpunktmenge aus der Platzierung des Dienstes
                           (aendert sich bei Verlagerung ohne Routenaenderung)
    V.zertifikatsverweis-> Verweis auf das Zertifikat, ueber den
                           Geheimnisdienst direkt in den Speicher geliefert
    V.zugriffskreis     -> Filter vor der Weiterleitung:
                           oeffentlich | Netzzone | Gruppe (Tokenpruefung)
    V.protokoll         -> HTTP/2 (RFC 9113), HTTP/3 (RFC 9114) ueber QUIC
                           (RFC 9000), oder reine Stromweiterleitung

  Mandant M ab Isolationsstufe M1
    M                   -> eigener Lauscher mit eigener Adresse und
                           eigenem Zertifikatssatz
```

Jede Konfiguration ist eine versionierte Momentaufnahme. Ein Rückrollen ist das erneute Ausliefern der vorherigen Momentaufnahme und keine Rückwärtsberechnung. Damit ist der Eingang in derselben Weise ein abgeleitetes Artefakt wie der Regelsatz: er ist vollständige Funktion des Objektgraphen, und es gibt keine Route, die ihre Veröffentlichung überlebt.

### TLS-Terminierung und Transportsicherheit

| Gegenstand | Festlegung | Begründung |
|---|---|---|
| Protokollversion | TLS 1.3 (RFC 8446), nach außen und nach innen | Technologiefestlegung Kryptographische Grundzusagen |
| Schlüsselablage | Zertifikat und privater Schlüssel werden über den Geheimnisdienst direkt in den Speicher des Eingangs geliefert, nie auf einen Datenträger geschrieben | INV-20; ein Schlüssel auf Platte überlebt den Prozess |
| Strikte Transportsicherheit | Kopfzeile mit **Zielwert 63.072.000 s (2 a)** für jede Veröffentlichung, deren Name auflösbar und deren Zertifikat gültig ist | die Kopfzeile wirkt nur bei einem ersten erfolgreichen Besuch; ein kürzerer Wert verschenkt Wirkung ohne Gegenwert |
| Voranmeldung in Browserlisten | nicht vorgesehen | Ein Eintrag in eine vorinstallierte Liste ist praktisch nicht zurücknehmbar und würde eine Domäne dauerhaft binden, auch nach Rückgabe an den Kunden |
| Untergeordnete Namen | die Ausdehnung auf untergeordnete Namen wird je Domäne entschieden, nicht je Veröffentlichung | eine Veröffentlichung, die untergeordnete Namen mitbindet, würde Namen außerhalb ihres Geltungsbereichs erfassen |

### Gegenseitige Authentisierung nach innen

Die Strecke vom Eingang zum Dienst läuft, wo der Dienst es kann, als gegenseitig authentisiertes TLS mit der Arbeitslastidentität des Dienstes (siehe [Kapitel 10](10-identitaet.md) und [Kapitel 11](11-pki.md)). Wo ein Katalogeintrag das nicht beherrscht — und das ist bei Fremdprodukten der Regelfall, nicht die Ausnahme —, gilt die Ersatzgrenze: der Dienst liegt in einer eigenen Netzzone, deren Firewallableitung ausschließlich den Eingangsträger als Quelle zulässt, und der Katalogeintrag deklariert in seiner Produktgrenzdeklaration (INV-30), dass die innere Strecke nicht gegenseitig authentisiert ist. Die Konsole zeigt diese Eigenschaft am Dienst an. Die ehrliche Einordnung: eine Netzzone ersetzt keine kryptographische Authentisierung; sie schützt gegen einen Angreifer im Netz, nicht gegen einen Angreifer, der bereits auf dem Knoten ist.

### Verhalten bei fehlendem Zertifikat

**Festlegung: ist für einen Namen kein gültiges Zertifikat vorhanden, wird für diesen Namen keine Filterkette programmiert.** Ein Verbindungsversuch scheitert damit im Aushandlungsschritt; es wird kein Zertifikat ausgeliefert, das nicht zum Namen passt, und kein selbstsigniertes Ersatzzertifikat. Die Veröffentlichung zeigt den Zustand "wird eingerichtet" oder "gestört" mit dem Grund aus dem Zertifikatsvorgang.

Verworfene Alternative: ein Platzhalterzertifikat ausliefern. Grund der Ablehnung: der Nutzer sieht dann eine Warnung, die er wegklicken kann, und das Wegklicken von Zertifikatswarnungen ist genau die Gewohnheit, die jeden Zertifikatsschutz entwertet.

Der Ablauffall wird gleich behandelt: läuft ein Zertifikat trotz der Erneuerungsfenster aus K-13 ab, wird die Filterkette entfernt, statt ein abgelaufenes Zertifikat auszuliefern. Das ist eine bewusst harte Entscheidung mit einer Gegenseite, die benannt gehört: ein abgelaufenes Zertifikat erzeugt beim Nutzer eine Warnseite, aus der er den Grund ablesen und den Betreiber informieren kann, während eine fehlende Filterkette einen technisch unspezifischen Verbindungsfehler erzeugt. Der Ausschlag zugunsten der harten Variante beruht darauf, dass die Erneuerung 720 Versuche vor Ablauf hat (K-13) und die Eskalation bereits ab 7 Tagen Restlaufzeit läuft; ein tatsächlicher Ablauf ist damit kein Betriebs-, sondern ein übersehener Störungsfall.

**Anforderungen**

- **R-12-42** — Der Eingang lädt 0 Konfigurationsdateien; die gesamte Konfiguration stammt aus xDS-Momentaufnahmen mit Versionskennung. Prüfbar: Start mit leerem Konfigurationsverzeichnis und anschließende Funktionsprüfung (INV-02).
- **R-12-43** — 1.000 aufeinanderfolgende Routenänderungen erzeugen 0 Verbindungsabbrüche bei laufenden Verbindungen. Prüfbar: Dauerlasttest nach K-17.
- **R-12-44** — Für einen Namen ohne gültiges Zertifikat existiert 0 Filterketten; der Verbindungsversuch liefert in 0 Fällen ein Zertifikat mit abweichendem Namen. Prüfbar: Veröffentlichung mit blockierter Zertifikatsausstellung und Aushandlungsversuch.
- **R-12-45** — Privater Schlüssel und Zertifikat des Eingangs erscheinen in 0 Dateien auf einem Datenträger. Prüfbar: Dateisystemprüfung gegen Schlüsselmuster während des Betriebs (INV-20).
- **R-12-46** — Ein Dienst ohne gegenseitige Authentisierung auf der inneren Strecke ist aus 0 Netzzonen außer der des Eingangsträgers erreichbar, und die Produktgrenzdeklaration weist die Eigenschaft aus. Prüfbar: Erreichbarkeitstest aus jeder Zone und Prüfung der Katalogeintragsdeklaration (INV-30).

## 12.9 Uplinks

### Mehrere Anschlüsse

Ein Knoten mit mehreren Uplinks führt je Uplink eine eigene Weiterleitungstabelle und eine Regel, die den Rückweg an den Eingangsweg bindet. Ohne diese Bindung wird eine auf Uplink B eingehende Verbindung über den Standardweg auf Uplink A beantwortet, die Antwort trägt die falsche Absenderadresse und wird verworfen oder von einer Zustandsprüfung auf dem Weg abgewiesen. Dieses Fehlerbild ist die häufigste Ursache dafür, dass ein zweiter Anschluss "nicht funktioniert", und es ist mit einer Regel je Anschluss vollständig behoben.

| Fall | Verhalten |
|---|---|
| Eingehende Verbindung auf Uplink B | Antwort verlässt den Knoten über Uplink B, Quelladresse ist die von B |
| Ausgehende Verbindung ohne Vorgabe | verlässt den Knoten über den Uplink mit der höchsten Vorrangzahl, die erreichbar ist |
| Ausfall eines Uplinks | bestehende Verbindungen über diesen Uplink brechen ab; neue Verbindungen nehmen den nächsten erreichbaren Uplink |
| Veröffentlichung extern | erzeugt Einträge für alle Uplinks mit öffentlicher Adresse, nicht nur für einen |

Ein Verbindungsübergang ohne Abbruch über zwei Anschlüsse ist **nicht** vorgesehen. Er verlangte eine gemeinsame, von beiden Anschlüssen erreichbare Adresse und damit eine Vereinbarung mit dem Anschlussanbieter, die Atrium nicht herstellen kann.

### Wechselnde öffentliche Adresse

| Fall | Ermittlung der Adresse |
|---|---|
| Anschluss liefert die öffentliche Adresse auf die Schnittstelle | direkt aus der Schnittstelle, kein Dritter beteiligt |
| Knoten liegt hinter NAT | Abfrage eines konfigurierten Rückmeldedienstes; das ist eine benannte Fremdabhängigkeit, die in der Konsole am Uplink sichtbar ist |
| Weder noch | keine externe Veröffentlichung möglich; siehe unten |

**Rechnung zur Ausfallzeit nach einem Adresswechsel.** Annahmen: Prüfintervall 60 s, Aktualisierung des externen Eintrags ≤ 2 s, TTL für dynamische Namen 60 s.

```
  schlechtester Fall = Erkennung 60 s + Aktualisierung 2 s + TTL 60 s = 122 s
  Zielwert <= 180 s  (Reserve 58 s fuer Verbreitung beim Elternbereich)
```

Der Wert 60 s für die TTL weicht von der externen Vorgabe 3600 s aus K-17 ab. Die Abweichung ist auf Namen beschränkt, die auf eine wechselnde Adresse zeigen, und sie ist begründet: mit 3600 s betrüge der schlechteste Fall 60 + 2 + 3600 = 3.662 s, also gut eine Stunde Nichterreichbarkeit nach jedem Adresswechsel.

**Gegenrechnung zur Abfragelast.** Ein Name mit TTL 3600 s wird von einem Resolver 86.400/3600 = 24-mal je Tag abgefragt, mit TTL 60 s 1.440-mal. Bei Annahme 200 verschiedener fremder Resolver und 5 dynamischen Namen: 200 × 1.440 × 5 = 1.440.000 Abfragen je Tag = 16,7 Abfragen je Sekunde. Das ist für den autoritativen Dienst unerheblich und rechtfertigt die Ausnahme.

### Portweiterleitung

Liegt der Knoten hinter einem NAT-Gerät, müssen die Ports aus der Portmatrix dorthin weitergeleitet werden. **Atrium konfiguriert das NAT-Gerät nicht.** Verworfene Alternative: ein automatisches Zuordnungsprotokoll zum Randgerät. Grund der Ablehnung: solche Protokolle sind unauthentisierte Schreibwege in ein Gerät, das die Außengrenze bildet; ein Dienst auf einem beliebigen internen Rechner kann damit dieselbe Weiterleitung anfordern.

Stattdessen erzeugt die Konsole aus den Veröffentlichungen eine Liste der benötigten Weiterleitungen und **prüft jede einzeln** durch einen Erreichbarkeitsversuch von außen. Auch das ist eine benannte Fremdabhängigkeit: der Versuch erfordert einen Punkt außerhalb des eigenen Anschlusses. Die Prüfung ist damit keine Selbstauskunft, sondern eine Messung mit benannter Quelle und Beobachtungszeitpunkt (INV-28).

### Anschluss ohne öffentliche Adresse

| Weg | Wirkung | Kosten und Grenzen |
|---|---|---|
| **Relaisknoten** mit öffentlicher Adresse, als Knoten gekoppelt und mit der Rolle Eingangsträger | Externer Verkehr trifft am Relais ein und läuft durch das Overlay zum Standort | Alle Verkehrsmengen laufen zweimal über die Anschlussleitung des Standorts; Latenz erhöht sich um eine Strecke; das Relais ist ein zusätzlicher Ausfallpunkt |
| **Nur IPv6**, falls der Anschluss ein geroutetes Präfix liefert | Eingehende Verbindungen über IPv6 funktionieren, über IPv4 nicht | Clients ohne IPv6 erreichen den Dienst nicht; das ist in der Konsole je Veröffentlichung auszuweisen |
| **Keine externe Veröffentlichung** | Interne Nutzung bleibt vollständig | Die Konsole lehnt eine externe Veröffentlichung mit benanntem Grund ab, statt sie anzulegen und scheitern zu lassen |

**Festlegung zum Relaisknoten: auf dem Relais wird TLS nicht terminiert.** Der Eingang läuft dort als Stromweiterleitung mit Auswahl über den Namensanzeiger; der private Schlüssel bleibt am Standort (INV-20). Ein eigener Verteiler auf Schicht 4 ist nach Technologiefestlegung 7 ausgeschlossen, deshalb ist auch das Relais dieselbe, über xDS programmierte Datenebene.

**Rechnung zur Relaisbandbreite.** Annahme: mittlerer externer Verkehr 50 Mbit/s je Richtung. Das Relais überträgt diesen Verkehr durch das Overlay; mit dem Overhead von 5,33 % aus Abschnitt 12.2 ergibt sich 50 × 1,0533 = 52,7 Mbit/s je Richtung auf der Relaisleitung und derselbe Wert auf der Standortleitung. Ein Standortanschluss mit 50 Mbit/s im Aufwärtszweig ist damit bereits ausgelastet; die Konsole zeigt die Relaisstrecke als Kapazitätsgrenze an.

Die ursprüngliche Quelladresse des Clients wird dem Standort vom Relais im Verbindungsstrom vorangestellt. Das hat eine Konsequenz, die benannt gehört: die Auswertung des Zugriffskreises am Standort vertraut dann der Angabe des Relais. Ein übernommenes Relais kann beliebige Quelladressen behaupten. Gegenmaßnahme: die Angabe wird nur von Strecken akzeptiert, die über das Overlay mit gültigem Knotenzertifikat eintreffen, und ein Zugriffskreis vom Typ Netzzone wird für über ein Relais eingehende Verbindungen nicht angewandt, sondern als nicht durchsetzbar angezeigt.

### Abgrenzung zu einem vollwertigen Router

Atrium sichert die eigene Kante und ersetzt kein Randgerät. Ausdrücklich **nicht** erbracht:

1. Adressumsetzung für fremde Geräte im Kundennetz.
2. Adressvergabe für das Standortnetz als Standardaufgabe; wo Atrium eine Zone selbst trägt, vergibt es Adressen für diese Zone und für keine andere.
3. Dynamische Weiterleitungsverfahren zwischen Standorten und zu Anbietern.
4. Verkehrsformung und Priorisierung nach Anwendungsklassen.
5. Einwahl beliebiger Clients; Netzzugang besteht für **registrierte Geräte** und läuft über das Overlay, siehe [Kapitel 13](13-geraeteverwaltung.md).
6. Inhaltsprüfung des durchlaufenden Verkehrs.

Diese Liste steht hier, weil das Gegenteil eine naheliegende Erwartung ist: ein System, das Firewall, DNS und Zertifikate selbst verwaltet, wird für einen Router gehalten. Es ist keiner, und die Konsole schreibt das bei der Einrichtung des Uplinks hin.

**Anforderungen**

- **R-12-47** — Eine auf Uplink B eingehende Verbindung wird über Uplink B mit dessen Quelladresse beantwortet; der Anteil über den falschen Uplink beantworteter Verbindungen beträgt 0. Prüfbar: gleichzeitiger Verbindungsaufbau über alle Uplinks mit Auswertung der Antwortquelladresse.
- **R-12-48** — Nach einem Wechsel der öffentlichen Adresse ist der zugehörige externe Name p95 ≤ 180 s wieder korrekt auflösbar. Prüfbar: 50 erzwungene Adresswechsel mit Messung bis zur korrekten Auflösung bei einem externen Resolver.
- **R-12-49** — Jede benötigte Portweiterleitung wird durch einen Erreichbarkeitsversuch von außen geprüft; das Ergebnis trägt Quelle und Beobachtungszeitpunkt. Prüfbar: Darstellungsprüfung und Abschaltung einer Weiterleitung mit erwarteter Statusänderung (INV-28).
- **R-12-50** — Auf einem Relaisknoten liegt 0 privates Schlüsselmaterial veröffentlichter Namen; ein Zugriffskreis vom Typ Netzzone wird für über ein Relais eingehende Verbindungen als nicht durchsetzbar angezeigt und nicht angewandt. Prüfbar: Dateisystem- und Speicherprüfung auf dem Relais sowie Zugriffsversuch mit behaupteter interner Quelladresse.

## 12.10 Externe DNS-Anbieter

### Zwei Wege, eine Bevorzugung

| Weg | Verfahren | Wann |
|---|---|---|
| **Atrium ist selbst autoritativ** | Die Domäne wird beim Register auf die Namensserver von Atrium delegiert; Einträge entstehen ausschließlich aus dem Objektgraphen | Vorzugsweg; es werden keinerlei Anbieterzugangsdaten benötigt, nur die Delegierung und die Delegationssignatur |
| **Fremder Anbieter bleibt autoritativ** | Dynamische Aktualisierung nach RFC 2136 mit Transaktionssignatur nach RFC 8945, oder Anbieterschnittstelle über eine Konnektorbindung | Wenn die Domäne aus organisatorischen Gründen beim Anbieter bleibt |

### Dynamische Aktualisierung mit Transaktionssignatur

Die Aktualisierung nach RFC 2136 wird mit einer Transaktionssignatur nach RFC 8945 authentisiert. Sichere Praxis an dieser Schnittstelle:

- Der Signaturschlüssel ist ein Geheimnis im Sinne des Objektmodells: schreibbar, nie lesbar (INV-20), mit Wechselfrist, je Domäne ein eigener.
- Die Prüfung des Nachrichtenprüfwerts erfolgt laufzeitkonstant; ein früh abbrechender Vergleich erlaubt das schrittweise Erraten des Prüfwerts.
- Das Zeitfeld der Signatur bindet die Nachricht an ein Zeitfenster und schützt gegen Wiedereinspielung; das setzt synchrone Uhren voraus (INV-32, K-30). Ein Knoten mit unbekannter Zeitgüte sendet keine Aktualisierung.
- Die Aktualisierung wird mit Vorbedingungen gesendet, sodass sie fehlschlägt, wenn der Zielzustand bereits abweichend verändert wurde, statt blind zu überschreiben.
- Namen und Werte werden vor dem Senden gegen ein Schema geprüft: zulässige Zeichen je Namensbestandteil, Längengrenzen je Bestandteil (63 Zeichen) und je Name (255 Zeichen), zulässige Eintragsarten. Eine Zusammensetzung von Nachrichten aus ungeprüften Zeichenketten findet nicht statt.

Wo der Anbieter keine dynamische Aktualisierung anbietet, läuft der Zugriff über eine Konnektorbindung mit dem einen Vertrag aus Technologiefestlegung 5: `describe`, `observe`, `plan`, `apply`, `healthcheck`. `plan` ist nebenwirkungsfrei (INV-08), `apply` trägt einen Idempotenzschlüssel (INV-07), der Prozess läuft unter eigenem Systembenutzer mit einer Ausgangs-Positivliste, die genau den Endpunkt dieses Anbieters enthält (INV-21).

### Zertifikatsvalidierung über DNS

Der Ablauf für eine Bestellung nach RFC 8555 mit Nachweis über DNS:

```
  1. Vorpruefung: CAA-Eintraege der Domaene (RFC 8659) abfragen.
     Erlaubt kein Eintrag die vorgesehene Ausgabestelle, bricht die
     Bestellung ab und die Konsole nennt den fehlenden Eintrag
     als konkreten Wert. Kein stiller Fehlversuch.
  2. Bestellung anlegen, Aufgabe vom Typ DNS entgegennehmen.
  3. Nachweiswert berechnen und als TXT-Eintrag unter
     _acme-challenge.<name> setzen (eigener autoritativer Dienst
     oder Fremdanbieter, je nach Weg oben).
  4. Sichtbarkeitspruefung: den Wert bei JEDEM autoritativen Server
     der Zone direkt abfragen, nicht ueber den eigenen Resolver.
     Erst wenn alle Server den Wert liefern, Schritt 5.
     Zielwert Obergrenze 10 min; danach Abbruch mit benanntem Grund.
  5. Ausgabestelle zur Pruefung auffordern, Ergebnis abholen.
  6. Nachweiswert entfernen, auch im Fehlerfall.
```

Schritt 4 ist der Schritt, der ohne ausdrückliche Prüfung regelmäßig fehlschlägt: eine Ausgabestelle fragt mehrere autoritative Server, und wer nur den eigenen Resolver befragt, hält den Wert bereits für verbreitet, während ein anderer Server ihn noch nicht hat. Die Prüfung gegen jeden Server der Zone ist deshalb Bestandteil des Ablaufs und keine Optimierung.

### Rechteumfang der Anbieterzugangsdaten

Das ist die unangenehmste Stelle dieses Abschnitts und wird nicht beschönigt: die Zugangsdaten vieler DNS-Anbieter sind nicht auf einzelne Namen oder Eintragsarten beschränkbar. Ein Zugang, der `_acme-challenge` schreiben darf, darf dann in der Regel auch den MX-Eintrag und den Adresseintrag der Domäne ändern — also Mail und Web derselben Domäne übernehmen.

| Maßnahme | Wirkung | Voraussetzung |
|---|---|---|
| **Delegierung des Nachweisnamens.** `_acme-challenge.<name>` wird per CNAME auf eine Zone verwiesen, für die Atrium selbst autoritativ ist. | Für die laufende Zertifikatsausstellung werden **überhaupt keine** Anbieterzugangsdaten mehr benötigt. Der Anbieterzugang wird einmalig zum Setzen des CNAME gebraucht und danach entfernt. | Der Anbieter erlaubt einen CNAME unterhalb eines mit Unterstrich beginnenden Namens |
| **Engster verfügbarer Rechteumfang** | Begrenzt den Schaden auf das, was der Anbieter zu begrenzen erlaubt | Der Anbieter bietet Rechteumfänge an |
| **Eigener Zugang je Domäne und Mandant** | Ein kompromittierter Zugang betrifft eine Domäne, nicht alle | immer möglich |
| **Wechselfrist und sichtbare Aufgabe** | Ein abgeflossener Zugang ist zeitlich begrenzt wirksam | Der Anbieter erlaubt programmatischen oder wenigstens manuellen Wechsel |
| **Klartextangabe in der Konsole** | Der Bediener weiß, was der hinterlegte Zugang im Missbrauchsfall vermag | immer möglich |

Die erste Zeile ist die einzige, die das Problem tatsächlich beseitigt statt es zu verkleinern, und sie ist deshalb der voreingestellte Weg. Wo der Anbieter sie nicht zulässt, bleibt ein dauerhaft hinterlegter Zugang mit weitreichenden Rechten bestehen; dieser Zustand wird am Domänenobjekt als Risiko ausgewiesen, statt ihn als gelöst darzustellen.

**Anforderungen**

- **R-12-51** — Der Vergleich des Nachrichtenprüfwerts einer Transaktionssignatur läuft laufzeitkonstant; die Laufzeitdifferenz zwischen richtigem und falschem Prüfwert liegt unter der Messauflösung. Prüfbar: Zeitmessreihe über je 10.000 Prüfungen.
- **R-12-52** — Eine dynamische Aktualisierung wird nur gesendet, wenn die Zeitgüte des Knotens belegt ist; ein Knoten mit unbekannter Abweichung sendet 0 Aktualisierungen. Prüfbar: Uhrensprungtest mit anschließendem Aktualisierungsversuch (INV-32).
- **R-12-53** — Vor jeder Zertifikatsbestellung werden die CAA-Einträge geprüft; fehlt die Erlaubnis, wird die Bestellung nicht gesendet und die Konsole nennt den fehlenden Eintrag als vollständigen Wert. Prüfbar: Bestellung gegen eine Domäne mit ausschließendem CAA-Eintrag (INV-17).
- **R-12-54** — Der Nachweiswert wird bei jedem autoritativen Server der Zone geprüft, bevor die Ausgabestelle aufgefordert wird; eine Aufforderung nach Prüfung nur des eigenen Resolvers findet in 0 Fällen statt. Prüfbar: Test mit einem absichtlich verzögerten autoritativen Server.
- **R-12-55** — Der Nachweiswert wird nach Abschluss der Bestellung entfernt, auch im Fehlerfall; die Zahl verbliebener Nachweiseinträge nach 100 Bestellungen mit 50 injizierten Fehlern beträgt 0. Prüfbar: Zonenabfrage nach dem Testlauf (INV-11).

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Das interne `/48` ist nach der Erstinstallation über 0 API-Operationen änderbar; über 1.000 Erstinstallationen tritt 0-mal dasselbe Präfix auf | R-12-01 | Endpunktprüfung gegen die Fassade plus Entropiereihe |
| Über 1.000 Anlege- und Löschzyklen wird 0-mal ein Index innerhalb der Aufbewahrungsfrist erneut vergeben | R-12-02 | Indexvergleich über den Zyklus |
| Eine Netzzone ohne Adressbereich, Resolver-Sicht oder Default-Deny-Vorgabe lässt sich nicht anlegen | R-12-03 | Anlegeversuch je fehlendem Feld |
| Über alle Veröffentlichungen sind die Erreichbarkeitsmengen für IPv4 und IPv6 identisch | R-12-04 | Mengenvergleich im Bautest; eine Abweichung bricht den Bau |
| Exporte, Wiederherstellungspunkte und Protokolle enthalten 0 Treffer gegen das Muster eines privaten Overlay-Schlüssels | R-12-05 | Musterprüfung aller Ausgaben |
| Nach jeder Sperrung eines Knotenzertifikats existiert 0 Peer-Zeile ohne gültiges Zertifikat | R-12-06 | Vergleich Schnittstellenkonfiguration gegen Zertifikatsbestand |
| Während einer Schlüsselrotation verliert die Vermaschung 0 Tunnel | R-12-07 | Rotationstest mit Erreichbarkeitsmessung über alle Paare |
| Ein Paket von 1421 Byte innerer Nutzlast wird verworfen, eines von 1420 Byte zugestellt; die ausgehandelte MSS beträgt 1360 beziehungsweise 1380 | R-12-08 | Messung über jeden Pfad |
| Ein Paar ohne erreichbaren Endpunkt auf beiden Seiten wird als gestört mit benannter Ursache angezeigt und nie als betriebsbereit | R-12-09 | Aufbau eines solchen Paares und Darstellungsprüfung |
| Bei 32 Knoten bleibt die Keepalive-Last unter 50 kbit/s und die Handshake-Rate unter 10/s | R-12-10 | Messung über 24 h gegen 25,4 kbit/s und 4,13/s |
| Der Resolver ist aus der Zone extern in 0 Versuchen erreichbar; offene Rekursion von außen gelingt 0-mal | R-12-11 | Portabtastung und Rekursionsversuch |
| Ein DNS-Eintrag ohne Sichtattribut lässt sich nicht anlegen; jede Vorbelegung nennt ihre Quelle | R-12-12 | Anlegeversuch und Formularprüfung im Bau |
| Drei Verwaltungsknoten erzeugen über 1.000 Versionen byteweise identische Zoneninstanzen | R-12-13 | Hashvergleich; ein Unterschied bricht den Bau |
| Ein Handeintrag gegen einen abgeleiteten Namen wird abgelehnt und die Ablehnung nennt das Quellobjekt | R-12-14 | Schreibversuch gegen einen aus einer Veröffentlichung erzeugten Namen |
| Nach Wiederherstellung aus einem 30 Tage alten Export ist der veröffentlichte Serienstand nicht kleiner als zuvor | R-12-15 | SOA-Abfrage nach der Wiederherstellung |
| Nach 14 Tagen ohne Quorum validiert jede Zone weiterhin, und der Zoneninhalt hat sich 0-mal geändert | R-12-16 | Partitionstest mit externer Validierung |
| "Domäne hinzufügen" und "Eintrag anlegen" verlangen je genau 3 Pflichtentscheidungen | R-12-17 | Abgleich Formulardefinition gegen Aufgabendefinition; ein Zusatzfeld bricht den Bau |
| 1.000 Permutationen derselben Eingabemenge liefern dieselbe Antwortpolitik | R-12-18 | Permutationstest über den Generator |
| Ein Geltungsbereich mit Geräten in einer nicht durchsetzbaren Zone zeigt den Vermerk und die vollständige Geräteliste | R-12-19 | Anlegen und Darstellungsprüfung |
| Oberflächentexte behaupten in 0 Fällen eine Geltung für eine Person unabhängig vom Gerät | R-12-20 | Textprüfung gegen die Positivliste |
| Eine für die Zone nicht geltende interne Domäne liefert eine signierte verneinende Antwort; 0 Abfragen laufen in einen Zeitablauf | R-12-21 | Abfrage aus jeder Zone mit Auswertung von Code und Signatur |
| Nach abgebrochener Anwendung trägt 0 Resolverinstanz eine Teilmenge der neuen Politik | R-12-22 | Anwendung mit injiziertem Abbruch und Instanzvergleich |
| Das Erklärwerkzeug nennt in 20 konstruierten Fehlerfällen 20-mal die richtige Ursache und verlinkt das verursachende Objekt | R-12-23 | Durchlauf über den Fehlerfallkatalog |
| 0 Zoneninstanzen sind unsigniert, auch keine interne | R-12-24 | Abfrage aller Instanzen mit Signaturprüfung |
| Der Schlüsseldatensatz bleibt bei fünf Stimmknoten und laufendem KSK-Rollover unter 1232 Byte | R-12-25 | Größenmessung der Antwort |
| Die Zahl der ZSK im Schlüsseldatensatz entspricht der Knotenzahl; 0 private Zonenschlüssel erscheinen in Exporten | R-12-26 | Zählvergleich und Inhaltsprüfung |
| Nach 14 Tagen ohne Quorum ist die kleinste Restsignaturgültigkeit größer als 0 | R-12-27 | Partitionstest mit Signaturmessung |
| Der Anteil der Zonen mit unter 24 h Restsignaturgültigkeit beträgt 0; das Unterschreiten von 3 d erzeugt eine verlinkte Meldung | R-12-28 | Dauerüberwachung und injizierter Erneuerungsfehler |
| Bei einem Elternserver, der die alte Delegationssignatur weiterliefert, wird der alte KSK 0-mal entfernt | R-12-29 | Rollover-Test mit verzögertem Elternserver |
| Eine ungültig signierte Antwort wird 0-mal an einen Client weitergegeben | R-12-30 | Abfrage gegen eine fehlerhaft signierte Testzone |
| Aus einer Zone mit erzwungener Sicht erreicht ein Gerät 0 fremde Ziele auf 53/udp und 53/tcp | R-12-31 | Abfrageversuch gegen eine fremde Adresse |
| Das Alter des Vertrauensankers wird mit Beobachtungszeitpunkt geführt; die Überschreitung erzeugt eine Störung | R-12-32 | Uhrvorstellung über den Schwellenwert |
| Ein gesperrter Name in einer signierten Zone wird abgelehnt, nicht umgelenkt; die Folge steht im Klartext am Richtlinienobjekt | R-12-33 | Sperrung eines signierten Namens |
| Die Einstellung "Weiterleitung" ist ohne Anzeige des Empfängers des Abfrageprofils nicht speicherbar | R-12-34 | Formularprüfung im Bau |
| Die API kennt 0 Schreiboperationen für Firewallregeln; 100 % der Regeln tragen einen Quellverweis | R-12-35 | Endpunktprüfung und Vollständigkeitsprüfung |
| Zweifache Erzeugung liefert über 1.000 Versionen byteweise identische Regelsätze | R-12-36 | Doppelerzeugung mit Hashvergleich |
| Nach injiziertem Abbruch ist der vorherige vollständige Satz aktiv; 0 Pakete passieren eine Kette ohne Politik | R-12-37 | Erreichbarkeitstest nach abgebrochener Anwendung |
| Ein Start ohne gültigen Regelsatz lässt 0 Schnittstellen außer Loopback aktiv und startet 0 Dienste | R-12-38 | Start mit beschädigtem Satz und Portabtastung |
| Eine vor dem Zurückziehen aufgebaute Verbindung überträgt danach 0 Byte | R-12-39 | Dauerverbindung, Zurückziehen, Durchsatzmessung |
| Eine fremde nftables-Tabelle wird innerhalb eines Abgleichzyklus gemeldet und 0-mal stillschweigend entfernt | R-12-40 | Anlegen einer fremden Tabelle |
| Die Pfad-MTU-Ermittlung gelingt über 100 % der Knotenpaare | R-12-41 | Messreihe mit übergroßen Paketen |
| Der Eingang startet mit leerem Konfigurationsverzeichnis und ist danach voll funktionsfähig | R-12-42 | Start ohne Datei und Funktionsprüfung |
| 1.000 aufeinanderfolgende Routenänderungen erzeugen 0 Verbindungsabbrüche | R-12-43 | Dauerlasttest nach K-17 |
| Für einen Namen ohne gültiges Zertifikat existiert 0 Filterkette; 0 Aushandlungen liefern ein fremdes Zertifikat | R-12-44 | Veröffentlichung mit blockierter Ausstellung |
| Während des Betriebs enthalten 0 Dateien auf Datenträgern den privaten Schlüssel des Eingangs | R-12-45 | Dateisystemprüfung gegen Schlüsselmuster |
| Ein Dienst ohne innere gegenseitige Authentisierung ist aus 0 fremden Netzzonen erreichbar und in der Produktgrenzdeklaration ausgewiesen | R-12-46 | Erreichbarkeitstest je Zone und Katalogprüfung |
| 0 Verbindungen werden über den falschen Uplink beantwortet | R-12-47 | Gleichzeitiger Aufbau über alle Uplinks |
| Nach 50 erzwungenen Adresswechseln liegt die Zeit bis zur korrekten Auflösung p95 ≤ 180 s | R-12-48 | Messreihe bei einem externen Resolver |
| Jede Portweiterleitung trägt ein von außen gemessenes Ergebnis mit Quelle und Beobachtungszeitpunkt | R-12-49 | Darstellungsprüfung und Abschalttest |
| Auf einem Relaisknoten liegt 0 privates Schlüsselmaterial; ein Zugriffskreis vom Typ Netzzone wird dort 0-mal angewandt | R-12-50 | Speicher- und Dateiprüfung plus Zugriffsversuch |
| Die Laufzeitdifferenz bei der Prüfwertkontrolle liegt unter der Messauflösung | R-12-51 | Zeitmessreihe über je 10.000 Prüfungen |
| Ein Knoten mit unbekannter Zeitgüte sendet 0 dynamische Aktualisierungen | R-12-52 | Uhrensprungtest |
| Eine Bestellung gegen eine Domäne mit ausschließendem CAA-Eintrag wird 0-mal gesendet; die Meldung nennt den vollständigen fehlenden Wert | R-12-53 | Bestellversuch gegen eine solche Domäne |
| Bei einem verzögerten autoritativen Server wird die Ausgabestelle 0-mal vorzeitig aufgefordert | R-12-54 | Test mit verzögertem Server |
| Nach 100 Bestellungen mit 50 injizierten Fehlern verbleiben 0 Nachweiseinträge in der Zone | R-12-55 | Zonenabfrage nach dem Testlauf |

## Offene Punkte

1. **Laufzeit des Knotenzertifikats ist im Kanon nicht geführt.** K-13 kennt Dienstzertifikate mit 90 Tagen und Gerätezertifikate mit 365 Tagen, aber keine Knotenklasse. Abschnitt 12.2 rechnet mit der Annahme 90 Tage und leitet daraus die Rotationslast (16,5 Peer-Aktualisierungen je Tag) und die Überlappungsfrist von 24 h ab. Da der Overlay-Schlüssel an dieses Zertifikat gebunden ist, verschiebt jede andere Festlegung beide Werte. Die Entscheidung gehört nach [Kapitel 11](11-pki.md); bis dahin ist R-12-07 an eine gesetzte, nicht an eine kanonische Größe gebunden.

2. **Durchsetzbarkeit des Geltungsbereichs in Bestandsnetzen.** Der Ableitungsalgorithmus aus Abschnitt 12.4 liefert für Geräte in einer fremdverwalteten Zone kein durchsetzbares Ergebnis und zeigt das an. Offen ist, wie die Konsole damit umgeht, wenn das für die überwiegende Zahl der Geräte einer Installation gilt — was in einer Bestandsumgebung ohne 802.1X der Normalfall ist. Drei Wege sind denkbar: den Geltungsbereich Gerät und Person in solchen Installationen gar nicht anbieten (ehrlich, aber die Oberfläche unterscheidet sich dann je Installation), ihn anbieten und flächendeckend als nicht durchsetzbar markieren (die Markierung wird zur Tapete), oder die Registrierung eines Geräts an die Aufnahme in eine durchsetzbare Zone koppeln (setzt Eingriffe in die Netzinfrastruktur des Kunden voraus, die Atrium nicht vornehmen darf). Keiner der drei Wege ist entschieden.

3. **Zahl der Zonensignaturschlüssel gegenüber der Knotenzahl.** Die Lösung "ein ZSK je Verwaltungsknoten" hält INV-20 ein und macht die Signaturerneuerung quorumunabhängig, koppelt aber die Größe des Schlüsseldatensatzes an die Zahl der Verwaltungsknoten. Bei fünf Stimmknoten und Ed25519 ist das unkritisch (516 Byte gegenüber 1232 Byte Grenze). Nicht entschieden ist, was gilt, wenn zusätzlich Mitleser autoritativ antworten sollen: bei zwölf Verwaltungsknoten und laufendem KSK-Rollover ergibt die Rechnung aus Abschnitt 12.5 14 Schlüssel zu je 36 Byte = 504 Byte plus 2 × (18 + 64 + 20) = 204 Byte Signaturen und rund 60 Byte Fragenteil und Kopf, also rund 768 Byte — noch unterhalb der Grenze. Mit ECDSA über P-256 sind es 14 × (4 + 64) = 952 Byte Schlüsselmaterial, 2 × (18 + 72 + 20) = 220 Byte Signaturen und rund 60 Byte Fragenteil und Kopf, zusammen 1.232 Byte: genau die angekündigte EDNS-Puffergröße. Der Wert erreicht die Grenze also nicht annähernd, sondern exakt, und lässt keine Reserve für einen weiteren Schlüsseleintrag oder eine zusätzliche Kopfoption; jedes weitere Oktett erzwingt den Wechsel auf TCP. Entweder wird die Zahl autoritativ antwortender Knoten begrenzt, oder der Schlüsseldatensatz wird von der Knotenzahl entkoppelt, was ein anderes Verfahren für die Signaturerneuerung erfordert.

4. **Reaktion auf einen abgelaufenen Vertrauensanker ohne Verbindung.** Abschnitt 12.6 macht das Alter des Wurzelankers sichtbar, löst den Fall aber nicht. Eine Installation ohne ausgehende Verbindung kann nach einem Schlüsselwechsel der Wurzel fremde Namen nicht mehr validieren. Offen ist, ob die Validierung dann für fremde Namen automatisch abgeschaltet wird (eine stille Sicherheitsminderung, die genau das tut, was der Entwurf sonst verbietet), ob fremde Namen unauflösbar werden (korrekt, aber für den Nutzer ein Totalausfall der Internetnutzung), oder ob der Anker als signiertes Artefakt mit dem Systemabbild verteilt wird und damit an die Ausrollkadenz aus K-23 gebunden ist.

5. **Abweichung der TTL für dynamische Namen von K-17.** Abschnitt 12.9 setzt für Namen auf wechselnden Adressen 60 s statt der in K-17 genannten externen TTL von 3600 s und begründet das mit der Ausfallzeit nach einem Adresswechsel. Der Kanon kennt diese Ausnahme nicht. Offen ist, ob K-17 um eine zweite TTL-Klasse ergänzt wird, ob die Ausnahme auf eine Höchstzahl dynamischer Namen je Installation begrenzt wird, oder ob externe Veröffentlichungen auf Anschlüssen mit wechselnder Adresse grundsätzlich als eingeschränkt gekennzeichnet werden.

6. **Vertrauen in die vom Relais gemeldete Quelladresse.** Abschnitt 12.9 lässt die Quelladressangabe nur von Strecken mit gültigem Knotenzertifikat zu und wendet Zugriffskreise vom Typ Netzzone über ein Relais nicht an. Das ist konservativ und macht ein Relais für einen erheblichen Teil der Zugriffskreise unbrauchbar. Offen ist, ob es eine Zwischenstufe gibt — etwa ein Zugriffskreis, der nur die Unterscheidung "aus dem Internet" gegen "aus einer internen Zone" verlangt und über ein Relais sinnvoll bleibt —, und wer die Vertrauenswürdigkeit eines gemieteten Relais beurteilt, dessen Betreiber die Maschine kontrolliert.

7. **Wirksamkeit der Ausgangsrichtlinie gegenüber Katalogeinträgen.** Die Kettenpolitik `drop` im Ausgang setzt voraus, dass jeder Katalogeintrag seine ausgehenden Ziele vollständig deklariert. Für hunderte Fremdprodukte ist das eine Deklarationspflicht, die im Zweifel unvollständig erfüllt wird, und die Folge ist eine Störung beim Kunden statt eines stillen Erfolgs. Offen ist, ob es einen Lernmodus gibt, der ausgehende Verbindungen eine definierte Zeit lang protokolliert statt verwirft und daraus einen Vorschlag erzeugt — was ein Zeitfenster mit abgeschwächter Regel schafft und damit der Default-Deny-Zusage (INV-10) widerspricht —, oder ob unvollständige Deklarationen als Katalogfehler behandelt werden und der Dienst bis zur Korrektur gestört bleibt.

8. **Verhalten bei abgelaufenem Zertifikat am Eingang.** Abschnitt 12.8 entfernt die Filterkette, statt ein abgelaufenes Zertifikat auszuliefern. Die Gegenseite ist benannt: der Nutzer erhält einen unspezifischen Verbindungsfehler statt einer lesbaren Warnseite und kann den Betreiber nicht gezielt informieren. Offen ist, ob stattdessen eine Fehlerseite über eine unverschlüsselte Verbindung auf 80/tcp ausgeliefert wird, die den Grund benennt — was eine unverschlüsselte Auskunft über den Zustand eines internen Objekts wäre —, oder ob es bei dem harten Verhalten bleibt.

9. **Fremde nftables-Tabellen sind erkennbar, aber nicht verhinderbar.** Die Begründungsskizze in Abschnitt 12.7 hat hier ihre größte Lücke: der Kern akzeptiert ein Paket, sobald irgendeine Kette im selben Einhängepunkt es akzeptiert, und eine fremde Tabelle hebelt damit jede Ableitung aus. Atrium meldet das als Abweichung, entfernt es aber nicht, weil ein automatisches Entfernen fremder Tabellen ein Eingriff in Software wäre, die der Betreiber bewusst installiert hat. Offen ist, ob es eine Betriebsart gibt, in der fremde Tabellen entfernt werden, wer sie einschaltet, und ob der Knoten bei einer nicht entfernten fremden Tabelle Dienste weiter betreibt oder sich abschottet.

10. **Erkennung des Zonenwechsels eines Geräts.** Der Fall "Notebook wechselt vom verwalteten Netz ins Gastnetz" wird in Abschnitt 12.6 über die TTL begrenzt, aber nicht erkannt. Das Gerät selbst weiß nichts von Zonen, und Atrium sieht den Wechsel erst, wenn das Gerät sich in der neuen Zone meldet. Offen ist, ob ein registriertes Gerät den Wechsel aktiv meldet (setzt eine Komponente auf dem Gerät voraus und verschiebt die Produktgrenze, siehe [Kapitel 13](13-geraeteverwaltung.md)), ob der Fall ausschließlich über kurze TTL und eine verständliche Fehlermeldung behandelt wird, oder ob Geräte mit Overlay-Zugang die interne Sicht unabhängig von ihrem Standort behalten und damit das Zonenmodell für diese Geräteklasse entfällt.
