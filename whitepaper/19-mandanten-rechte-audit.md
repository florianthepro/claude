# 19 Mandanten, Rechte, Freigaben und Audit

## 19.1 Was eine Isolationsstufe leistet und was sie kostet

Die vier Stufen M0 bis M3 sind in KANON Abschnitt 4, Eintrag 6 festgelegt. Dieses Kapitel ergänzt, was je Stufe tatsächlich getrennt ist, welche Angriffsklasse sie abwehrt, welches Restrisiko bleibt und welchen Ressourcenpreis sie verlangt. Die Stufe ist ein Attribut des Mandanten, keine Einstellung an einem Dienst; sie wird je Mandant gewählt, ist online steigerbar und nur mit ausdrücklicher Bestätigung und Auditeintrag absenkbar.

### Trennungsmatrix

| Trennungsgegenstand | M0 | M1 | M2 | M3 |
|---|---|---|---|---|
| Datenraum im Sollzustand (Mandantenprädikat, INV-19) | ja | ja | ja | ja |
| Rechteprüfung, eigener Regelsatz | ja | ja | ja | ja |
| Hauptschlüssel und Speicherbereichsverschlüsselung | ja | ja | ja | ja |
| Zwischen-CA (KANON 4.6a) | ja | ja | ja | ja |
| Auditsicht und Auditexport | ja | ja | ja | ja |
| Overlay-Segment mit eigenem Schlüsselmaterial | nein | ja | ja | ja |
| Firewallzone und eigene Ausgangsadresse | nein | ja | ja | ja |
| Eingangs-Listener mit eigenem Zertifikat | nein | ja | ja | ja |
| Resolver-Sicht | nein | ja | ja | ja |
| Leitungsebene (VLAN/VRF bis zur Netzkarte) | nein | nein | ja | ja |
| Eigene Eingangsadresse | nein | nein | ja | ja |
| Eigene autoritative Zoneninstanzen | nein | nein | ja | ja |
| Knoten exklusiv | nein | nein | nein | ja |
| Speicherpool exklusiv | nein | nein | nein | ja |
| Sicherungsziel exklusiv | nein | nein | nein | ja |
| Rechenzeit und Arbeitsspeicher | Budget je Dienst | Budget je Dienst | Budget je Dienst | physisch |
| Kontrollebene | geteilt | geteilt | geteilt | geteilt |

Die letzte Zeile ist die wichtigste. Keine Stufe trennt die Kontrollebene. Das ist eine Entwurfsentscheidung mit benanntem Preis: die mandantenübergreifende Übersicht, die den Produktnutzen trägt, setzt einen gemeinsamen Sollzustand voraus, und ein gemeinsamer Sollzustand bedeutet eine gemeinsame Fehlerdomäne für Fehler in der Kontrollebene selbst (K-28).

### Angriffsklassen, Abwehr und Restrisiko

| Angriff | Abgewehrt ab | Restrisiko nach der höchsten Stufe |
|---|---|---|
| A1 Fehlendes Mandantenprädikat in einer Abfrage | M0 (Datenzugriffsschicht, INV-19) | Ein Fehler in der Datenzugriffsschicht selbst wirkt in allen Stufen gleich |
| A2 Zertifikatsausstellung für fremde Namen | M0 (eigene Zwischen-CA mit Namensbeschränkung) | Kompromittierung der Ausgabe-CA wirkt auf alle Mandanten |
| A3 Kompromittierter Dienst erreicht fremden Dienst über das Netz | M1 (eigenes Segment, eigene Firewallzone) | Auf gemeinsamem Knoten erreicht der Angreifer den fremden Dienst über die Kernel- und Speicherschnittstelle statt über das Netz |
| A4 Ausspähen interner Namen über die Namensauflösung | M1 (eigene Resolver-Sicht) | Namen, die in öffentlichen Zertifikatsprotokollen erscheinen, bleiben sichtbar |
| A5 Mitlesen fremden Verkehrs auf der Leitung | M2 (VLAN/VRF) | Ein kompromittierter Knoten sieht den Verkehr aller auf ihm laufenden Mandanten vor der Verschlüsselung |
| A6 Ausbruch aus einem Dienst auf den Knoten | M3 (exklusive Knoten) | Der Angreifer besitzt die Knoten des betroffenen Mandanten vollständig |
| A7 Ressourcenerschöpfung durch einen Nachbarn | M3 (exklusive Knoten und Pools) | Bis M2 begrenzen Dienstbudgets die Rechenzeit, nicht die Speicherbandbreite und nicht die Warteschlangen des gemeinsamen Speicherpools |
| A8 Wiederverwendung oder Beschlagnahme eines Datenträgers | M0 (eigener Hauptschlüssel) verstärkt durch M3 (eigener Pool) | Der Hauptschlüssel liegt im TPM eines Knotens des Betreibers |
| A9 Innentäter mit Plattformrolle | keine Stufe | Nur erkennbar, nicht verhinderbar; die Gegenmaßnahme ist Funktionstrennung (19.5) und Audit (19.8) |
| A10 Fehler in der Kontrollebene | keine Stufe | Wirkt auf alle Mandanten; die einzige Abhilfe ist eine getrennte Installation, also ein anderes System |

A3 und A5 zeigen die Grenze der Netztrennung genau: sie schützt gegen einen kompromittierten **Dienst**, nicht gegen einen kompromittierten **Knoten**. Das Schlüsselmaterial aller Segmente, die auf einem Knoten enden, liegt auf diesem Knoten. Wer den Knoten besitzt, besitzt die Segmente, die dort terminieren. Diese Schwäche entsteht durch die Vollvermaschung des Overlays und wird von M1 und M2 nicht behoben, sondern erst von M3, und zwar nicht durch bessere Kryptographie, sondern dadurch, dass keine fremden Segmente mehr auf dem Knoten enden.

### Kostenrechnung je Stufe

Die folgenden Rechnungen sind ein Modell mit offengelegten Annahmen, keine Messung. Annahme für alle Stufenrechnungen: 20 Mandanten, je Mandant 8 Dienste und 3 Domänen, 3 Stimmknoten, Ressourcenbudget der Grundinstallation nach K-19 (≤ 4 GB Arbeitsspeicher für Kontrollebene, Eingang, autoritativen DNS, Resolver und Protokollkopf zusammen, davon ≤ 512 MB für den Eingang).

**M0.** Zusatzkosten gegenüber einem Einmandantenbetrieb sind eine Zwischen-CA und ein Hauptschlüssel je Mandant. Annahme 2 KB je Zwischen-CA-Objekt: 20 × 2 KB = 40 KB gegenüber ≤ 50 MB Sollzustand (K-12), also 0,08 %. Das Mandantenprädikat verlängert jeden Index um eine führende Spalte; die Selektivität steigt dadurch, die Kosten sind nicht messbar negativ. **Deutung:** M0 ist kostenlos und deshalb die Vorgabe.

**M1.** Je Mandant kommen ein Eingangs-Listener und eine Resolver-Sicht hinzu. Annahme 8 MB zusätzlicher Arbeitsspeicher je Listener (eigene Zertifikatskette, eigener Filterstapel) und 60 MB je Resolver-Instanz. Annahme 2,5 GB für Kontrollebene, autoritativen DNS und Protokollkopf zusammen. Verfügbarer Rest: 4.096 MB − 2.560 MB = 1.536 MB. Je Mandant 68 MB ergibt 1.536 / 68 = 22,6. **Ergebnis:** auf einem Ankerknoten mit dem Budget aus K-19 sind höchstens 22 Mandanten der Stufe M1 darstellbar; bei den angenommenen 20 Mandanten bleiben 1.536 − 1.360 = 176 MB Reserve, also 11 %. **Deutung:** M1 ist bis etwa zwei Dutzend Mandanten auf einem einzelnen Verwaltungsknoten tragbar; darüber sind dedizierte Verwaltungsknoten Voraussetzung und keine Wahl. Die Rechnung hängt vollständig an der Annahme von 60 MB je Resolver-Instanz; fällt dieser Wert in einer Messung höher aus, sinkt die Mandantenzahl proportional.

**M2.** Zu den Kosten von M1 kommen eine eigene Eingangsadresse und eigene autoritative Zoneninstanzen. 20 Mandanten × 3 Domänen × 2 Sichten = 120 Zoneninstanzen; die Signierlast steigt linear mit der Zahl der Einträge, nicht mit der Zahl der Instanzen. Der eigentliche Preis ist nicht Rechenleistung, sondern Adressraum und Fremdkonfiguration: 20 öffentliche Adressen je Standort entsprechen einem /27 bei IPv4, und die VLAN- oder VRF-Zuordnung entsteht auf Netzgeräten, die nicht zu Atrium gehören. **Das ist die erste Stufe, deren Voraussetzung außerhalb des Systems liegt.** Atrium kann prüfen, ob eine Kennung an der Netzkarte ankommt, und den Mandanten als "M2 nicht vollständig wirksam" kennzeichnen; es kann das VLAN nicht anlegen. Ein Versprechen, M2 sei eine reine Schalterstellung in der Konsole, wäre falsch, und die Konsole macht diese Voraussetzung sichtbar, statt sie zu verschweigen.

**M3.** Ein Mandant der Stufe M3 belegt Knoten exklusiv. Für die Datensicherheitsstufe "Synchron gespiegelt" sind mindestens 3 Knoten nötig (K-08). Mit 3 Stimmknoten der geteilten Kontrollebene und der Skalengrenze von 32 Knoten (K-21) gilt 3 + 3n ≤ 32, also n ≤ 9. **Ergebnis:** höchstens 9 Mandanten der Stufe M3 mit Redundanz je Installation. Gegenrechnung: dieselben 20 Mandanten in M0 belegen nach der Annahme von 8 Diensten je Mandant und einer Annahme von 25 Dienstinstanzen je Dienstträgerknoten 160 / 25 = 6,4, also 7 Dienstträgerknoten. 20 Mandanten in M3 verlangen 60 Knoten und überschreiten die Skalengrenze um den Faktor 1,9. **Deutung:** M3 ist eine Stufe für wenige große Mandanten, nicht für einen Dienstleister mit vielen kleinen Kunden. Diese Aussage ist Produktgrenze, nicht Einstellungssache.

### Eine getrennte Installation ist keine Isolationsstufe

Wer eine fünfte, stärkere Stufe erwartet, erwartet ein anderes Produkt. Eine zweite Installation hat einen eigenen Sollzustand, eine eigene Auditkette, eine eigene Wurzel-CA, einen eigenen Updatezyklus und keine gemeinsame Übersicht. Die Eigenschaften, die sie gewinnt, sind genau die, die das Produkt aufgibt.

| Merkmal | Stufen M0–M3 | Zwei getrennte Installationen |
|---|---|---|
| Übersicht über alle Mandanten | eine Ansicht | keine; zwei Anmeldungen, zwei Wahrheiten |
| Fehlerdomäne der Kontrollebene | gemeinsam | getrennt |
| Mindestknotenzahl für Redundanz | 3 gesamt | 3 je Installation |
| Auditkette | eine je Verwaltungsknoten, mandantengefiltert exportierbar | zwei unabhängige Ketten ohne gemeinsame Ordnung |
| Freigabeweg über die Grenze | Freigabeverknüpfung ([Kapitel 7](07-objektmodell.md)) | nicht vorhanden |
| Betriebsaufwand Updates | ein gestaffelter Durchlauf | n Durchläufe |

**Rechnung Knotenzahl.** 5 Kunden in einer Installation mit M1: 3 Stimmknoten + 3 Dienstträgerknoten = 6. Dieselben 5 Kunden in 5 Installationen: 5 × 3 = 15 Knoten, Faktor 2,5. Bei M3 kehrt sich der Vorteil um: 5 × 3 + 3 = 18 gegenüber 15, weil die geteilte Kontrollebene zusätzlich zu den exklusiven Knoten getragen wird. **Deutung:** unterhalb von M3 ist die gemeinsame Installation deutlich günstiger; bei M3 ist sie geringfügig teurer und rechtfertigt sich allein durch die Übersicht und den gemeinsamen Freigabe- und Nachweisweg.

**Anforderungen**

- **R-19-01** — Jeder Mandant trägt genau eine Isolationsstufe aus {M0, M1, M2, M3}; ein Mandant ohne Stufe existiert nicht. Prüfbar: Anlegeversuch ohne Stufenangabe wird abgelehnt (INV-19).
- **R-19-02** — Eine Absenkung der Isolationsstufe erzeugt einen Vorgang mit Wirkungsvorschau, ausdrücklicher Bestätigung und genau einem Auditereignis; eine Absenkung ohne Bestätigung erzeugt 0 Wirkungen. Prüfbar: Absenkversuch ohne Bestätigung (INV-08, K-29).
- **R-19-03** — Die Konsole zeigt je Mandant die Zahl der bei Ausfall eines Knotens mitbetroffenen Mandanten; für M3 ist dieser Wert 1. Prüfbar: Darstellungstest über je einen Mandanten der vier Stufen (K-28, INV-18).
- **R-19-04** — Ein Mandant der Stufe M2, dessen Leitungstrennung an der Netzkarte nicht nachweisbar ist, wird dauerhaft als "M2 nicht vollständig wirksam" gekennzeichnet und nicht als M2 gezählt. Prüfbar: Aufbau ohne VLAN-Kennung (INV-18).
- **R-19-05** — Der Versuch, mehr Mandanten der Stufe M3 mit Redundanz einzurichten, als die Knotenzahl zulässt, wird mit Nennung der fehlenden Knotenzahl abgelehnt und nicht teilweise ausgeführt. Prüfbar: Einrichtungsversuch bei erschöpfter Knotenmenge (K-21, INV-12).
- **R-19-06** — Die Konsole benennt an der Stufenauswahl, dass die Kontrollebene in allen Stufen gemeinsam ist. Prüfbar: Textprüfung der Stufenauswahl (K-28).

## 19.2 Betreiber, Mandant, Endkunde

### Drei Ebenen, zwei Grenzen

| Ebene | Wer das ist | Objekt in Atrium | Grenze nach unten |
|---|---|---|---|
| Betreiber | Wer die Maschinen besitzt und die Plattform betreibt | Plattform-Mandant mit fester Kennung | Sieht Mandanten als Objekte, nicht deren Inhalte |
| Mandant | Eine Organisation mit eigener Rechte-, Schlüssel- und Abrechnungsgrenze | Mandant | Sieht Kundenbereiche als Objekte |
| Endkunde | Kunde eines Mandanten, der nur ein Selbstbedienungsfenster erhält | Kundenbereich innerhalb eines Mandanten | keine |

Die erste Entwurfsentscheidung dieses Abschnitts betrifft den Dienstleisterfall und ist folgenreich: **ein betreuter Kunde eines Dienstleisters ist ein eigener Mandant, kein Kundenbereich.** Der Grund ist prüfbar, nicht geschmacklich. Die Mandantengrenze wird von der Datenzugriffsschicht erzwungen (INV-19); eine Abfrage ohne Mandantenprädikat existiert nicht. Eine Kundengrenze innerhalb eines Mandanten wird dagegen von Regelselektoren erzwungen, also von korrekt geschriebenen Regeln. Der Unterschied zwischen "kann strukturell nicht passieren" und "passiert nicht, solange niemand eine Regel falsch schreibt" ist genau der Unterschied, den ein Kunde kauft. Der Kundenbereich bleibt deshalb den Endkunden vorbehalten, die kein eigenes Rechtemodell brauchen, sondern ein Fenster (19.7). Die verworfene Alternative, betreute Kunden als Gruppen innerhalb eines Dienstleistermandanten zu führen, spart Mandantenobjekte und verlagert die Isolation in die Regelpflege; sie ist damit genau die Vermischung, die vermieden werden soll.

### Sichtbarkeit nach unten

Der Betreiber sieht je Mandant: Kennung und Anzeigename, Isolationsstufe, Zustand, Anzahl der Objekte je Typ, Gesundheits- und Kapazitätsdaten, Vorgangsköpfe, Auditereignisköpfe, offene Freigaben, Sicherungs- und Prüfstatus. Er sieht nicht: Anhänge von Auditereignissen fremder Mandanten, Personendaten über Kennung und Anzeigename hinaus, Nutzdaten in Diensten (die liegen ohnehin nie in Atrium) und Geheimnisse (INV-20 gilt ohne Ausnahme für jede Rolle).

Diese Trennung hat eine harte Grenze, die benannt gehört: der Betreiber besitzt die Maschinen. Wer physischen Zugriff auf einen Knoten hat, auf dem ein Mandant der Stufe M0 bis M2 läuft, kann an dessen Daten gelangen, und der Hauptschlüssel des Mandanten liegt im TPM eines Knotens, den der Betreiber besitzt. Die Sichtbarkeitsregel ist eine Regel der API, keine Aussage über physische Vertraulichkeit. Ein Mandant, der sicherstellen muss, dass sein Betreiber technisch nicht lesen kann, braucht Schlüsselverwahrung außerhalb der Installation; dafür existiert im Entwurf kein Weg, und das ist eine offene Frage, keine gelöste (Kapitel 19, Offene Punkte, Punkt 1).

Was der Entwurf leistet, ist etwas Schwächeres und trotzdem Wertvolles: **es gibt keinen stillen Zugriff.** Eine Plattformrolle enthält nicht das Recht, Mandanteninhalte zu lesen; sie enthält das Recht, sich dieses Recht zu geben. Genau dieser Schritt ist ein Vorgang, erzeugt ein Auditereignis im Strom des Zielmandanten und löst dort eine Benachrichtigung aus. Die Zusage lautet damit nicht "der Betreiber kann nicht", sondern "der Betreiber kann nicht unbemerkt".

### Sichtbarkeit nach oben

| Was ein Mandant über den Betreiber sieht | Begründung |
|---|---|
| Liste aller Plattformrollen und ihrer Träger, mit Befristung | Ohne diese Liste ist die Frage "wer könnte" unbeantwortbar |
| Jede Zuweisung einer Rolle in den eigenen Mandanten hinein, vor ihrer Wirksamkeit | Rechteerhöhung ist freigabepflichtig (19.6) |
| Jede Handlung eines fremden Akteurs in den eigenen Objekten, mit Zweck und Freigabeverknüpfung | Nachvollziehbarkeit des Dienstleisterhandelns |
| Jede Notzugangsauslösung in den eigenen Mandanten, in Echtzeit | 19.10 |
| Den Zustand des Schalters "Zugriff auf Anfrage" | Steuerbarkeit |
| Nicht: andere Mandanten, deren Namen, Zahlen oder Störungen | Mandantenprädikat gilt auch für Betriebsdaten |

Ein Mandant sieht die Knoten, die seine Dienste tragen, weil INV-18 verlangt, dass Degradation am Objekt sichtbar ist. Er sieht nicht, welche fremden Dienste auf demselben Knoten liegen. Die Zahl "wie viele Knotenausfälle werden derzeit noch vertragen" ist mandantenbezogen berechnet und enthält keine fremden Objekte.

### Wie ein Dienstleister mehrere Kunden verwaltet

Das Personal des Dienstleisters lebt im Betreibermandanten. Der Zugriff in einen Kundenmandanten entsteht über eine Freigabeverknüpfung nach [Kapitel 7](07-objektmodell.md) mit `richtung = verwendend`, Pflichtfeld `zweck`, Pflichtbefristung und Zustimmung je eines Administrators beider Mandanten. Auf dieser Verknüpfung liegt eine befristete Zuweisung einer Mandantenrolle.

```
Betreibermandant                       Kundenmandant A
+----------------------+               +--------------------------+
| Person: Technikerin  |               | Mandantenadministrator   |
| Gruppe: Betreuung A  |--Freigabe---->| (Rolle, Geltung Mandant) |
+----------------------+  verknuepfung +--------------------------+
        |                  zweck, bis      ^
        |                                  | Zuweisung, gueltig_bis
        +-- Mandantenuebersicht (nur Zaehl- und Zustandswerte, kein Inhalt)
```

Drei Eigenschaften folgen daraus und sind prüfbar. Erstens: fällt die Freigabeverknüpfung weg oder läuft sie ab, verfallen alle daraus abgeleiteten Zuweisungen, ohne dass jemand aufräumen muss. Zweitens: ein Wechsel im Personal des Dienstleisters ist eine Gruppenänderung im Betreibermandanten und wirkt in allen betreuten Mandanten gleichzeitig und sichtbar. Drittens: kein Objekt wechselt je den Mandanten. Es gibt keinen Kopiervorgang zwischen Kundenmandanten und damit keinen Weg, über den Kundendaten sich vermischen.

Die mandantenübergreifende Übersicht des Dienstleisters ist eine Aggregation über Zähl- und Zustandswerte: Mandant, Stufe, Zahl offener Störungen, Zahl offener Freigaben, ältester ungeprüfter Wiederherstellungspunkt, nächste ablaufende Zertifikate. Sie enthält keine Objektnamen aus den Kundenmandanten. Die Grenze ist bewusst scharf gezogen, weil eine Übersicht, die Objektnamen aggregiert, faktisch ein Leserecht über alle Mandanten ist und die Zusage aus 19.2 aufhebt.

### Wie ein Kunde nachvollzieht, was beim ihm getan wurde

Drei Nachweise, die unabhängig voneinander wirken.

1. **Auditsicht.** Jedes Ereignis, dessen Akteur aus einem fremden Mandanten stammt, trägt `akteur.herkunfts_mandant` ungleich `mandant` und ist in der Konsole des Kunden als Fremdzugriff gefiltert abrufbar, mit Person, Zweck, Freigabeverknüpfung und Vorgang.
2. **Tätigkeitsnachweis.** Ein periodischer, signierter Export je Mandant, der ausschließlich Fremdzugriffe des Zeitraums enthält, nach dem Verfahren aus 19.8 einzeln prüfbar und ohne Atrium verifizierbar.
3. **Zugriff auf Anfrage.** Ein Schalter am Mandanten, der stehende Zuweisungen aus fremden Mandanten unwirksam setzt, bis ein Antrag durch den Kunden freigegeben ist. Die Wirkung ist eine Bedingung in der Regelauswertung (19.3), keine separate Mechanik.

Die ehrliche Grenze: Punkt 3 gilt nicht für den Notzugang. Ein Dienstleister, der nachts eine Störung beheben muss, kommt über 19.10 hinein, auch wenn der Schalter gesetzt ist. Der Kunde erhält dafür Echtzeitalarmierung und eine Begründungspflicht, nicht ein Vetorecht. Ein Vetorecht wäre gleichbedeutend damit, dass ein nicht erreichbarer Kunde die Behebung seiner eigenen Störung blockiert.

**Anforderungen**

- **R-19-07** — Ein Zugriff eines Subjekts aus einem anderen Mandanten ist nur wirksam, wenn eine gültige, befristete Freigabeverknüpfung mit ausgefülltem Zweck existiert; ohne sie liefert jede Abfrage "kein Freigabeweg" und 0 Objektdaten. Prüfbar: Isolationstest über zwei Mandanten (INV-19).
- **R-19-08** — Jedes Auditereignis mit fremdem Herkunftsmandanten ist in der Auditsicht des Zielmandanten ohne Zusatzrecht filterbar und nennt Person, Zweck und Freigabeverknüpfung. Prüfbar: Darstellungstest nach einem Fremdzugriff (INV-23).
- **R-19-09** — Die mandantenübergreifende Übersicht enthält 0 Objektnamen aus fremden Mandanten. Prüfbar: Inhaltsprüfung der Aggregationsantwort gegen die Namensfelder der Mandanten (INV-19).
- **R-19-10** — Der Ablauf oder Entzug einer Freigabeverknüpfung macht alle daraus abgeleiteten Zuweisungen innerhalb von 30 s unwirksam. Prüfbar: Entzugstest mit anschließendem Zugriffsversuch (K-16).

## 19.3 Das Rechtemodell

### Form einer Regel

Eine Regel besteht aus vier Teilen und einer Wirkung: **Subjekt**, **Aktion**, **Ressourcenselektor**, **Bedingung**. Rollen sind keine eigene Mechanik, sondern benannte Bündel von Regeln; eine Zuweisung verbindet ein Subjekt mit einer Rolle und einem Ziel ([Kapitel 7](07-objektmodell.md)). [Kapitel 10](10-identitaet.md) beschreibt, wie aus Zuweisungen wirksame Rechte einer Person werden; dieser Abschnitt beschreibt, wie aus einer Regel eine Entscheidung über eine konkrete Anfrage wird.

```
regel <kennung> {
  wirkung:     gewaehrung | ausschluss
  subjekt:     rolle:<ulid> | gruppe:<ulid> | person:<ulid> | dienstkonto:<ulid>
  aktion:      <objekt>.<verb> | <objekt>.* | klasse:<aktionsklasse>
  selektor:    mandant = <ulid|$eigener>
               [ und <feld> <op> <wert> ]*        # op aus =, !=, in, praefix
  bedingung:   [ zeit_im_fenster(<von>,<bis>)
               | authentisierungsguete >= <stufe>
               | netzzone in (<ulid>, ...)
               | freigabe_vorhanden(<typ>)
               | antragsteller != subjekt
               | mandant_schalter(<name>) = <wert> ]*
  gueltig:     <zeitpunkt> .. <zeitpunkt>
  begruendung: "<text>"            # Pflicht bei wirkung = ausschluss
}
```

Acht Beispielregeln. Sie sind Entwurfsstand und beschreiben die Notation, nicht eine ausgelieferte Regelbasis.

```
regel R-MA-GRUND {
  wirkung: gewaehrung
  subjekt: rolle:mandantenadministrator
  aktion:  klasse:verwaltung_mandant
  selektor: mandant = $eigener
  bedingung: authentisierungsguete >= passkey_mfa
  gueltig: unbefristet
}

regel R-HD-AUTH {
  wirkung: gewaehrung
  subjekt: rolle:helpdesk
  aktion:  person.authentikator_zurueckgesetzt
  selektor: mandant = $eigener und person.hat_plattformrolle = false
  bedingung: netzzone in (verwaltungszone)
  gueltig: unbefristet
}

regel R-HD-SPERRE {
  wirkung: ausschluss
  subjekt: rolle:helpdesk
  aktion:  person.authentikator_zurueckgesetzt
  selektor: mandant = $eigener und person.hat_plattformrolle = true
  bedingung: -
  gueltig: unbefristet
  begruendung: "Zuruecksetzen an einem Administratorkonto ist eine Rechteerhoehung"
}

regel R-PR-LESEN {
  wirkung: gewaehrung
  subjekt: rolle:pruefer
  aktion:  klasse:lesen, klasse:audit_lesen, klasse:audit_export
  selektor: mandant = $eigener
  bedingung: -
  gueltig: unbefristet
}

regel R-PR-KEIN-SCHREIBEN {
  wirkung: ausschluss
  subjekt: rolle:pruefer
  aktion:  klasse:schreiben, klasse:freigabe_erteilen
  selektor: mandant = *
  bedingung: -
  gueltig: unbefristet
  begruendung: "Wer prueft, aendert nicht, was er prueft"
}

regel R-DA-WARTUNG {
  wirkung: gewaehrung
  subjekt: gruppe:wartung-crm
  aktion:  dienst.aktualisiert, dienst.angehalten, dienst.gestartet
  selektor: mandant = $eigener und dienst.kennung = 01J8ZQ...CRM
  bedingung: zeit_im_fenster(2026-10-03T20:00Z, 2026-10-04T02:00Z)
  gueltig: 2026-10-03T00:00Z .. 2026-10-04T06:00Z
}

regel R-BETREUUNG-A {
  wirkung: gewaehrung
  subjekt: gruppe:betreuung-a
  aktion:  klasse:verwaltung_mandant
  selektor: mandant = 01J8ZQ...KUNDE-A
  bedingung: freigabe_vorhanden(betreuungsverknuepfung)
             und mandant_schalter(zugriff_auf_anfrage) = aus
  gueltig: 2026-01-01T00:00Z .. 2026-12-31T23:59Z
}

regel R-FG-EIGENANTRAG {
  wirkung: ausschluss
  subjekt: rolle:freigeber
  aktion:  vorgang.freigegeben
  selektor: mandant = *
  bedingung: antragsteller = subjekt
  gueltig: unbefristet
  begruendung: "Vier-Augen-Prinzip"
}
```

### Auswertung

```
funktion entscheidung(anfrage A) -> erlaubt | verweigert(grund, quelle)
  1  wenn A.mandant fehlt: gib verweigert("kein Mandantenpraedikat", -)      # INV-19
  2  S := {A.subjekt} vereinigt transitive_huelle(gruppen_von(A.subjekt))    # Tiefe <= 8
  3  R := regeln mit subjekt in S
            und aktion deckt A.aktion
            und gueltig_von <= jetzt <= gueltig_bis
  4  R := { r aus R : selektor_trifft(r, A.ressource) }
  5  R := { r aus R : alle bedingungen(r) erfuellt fuer A }
  6  wenn ein r aus R mit wirkung = ausschluss existiert:
         gib verweigert(begruendung(r), r)                                   # Vorrang
  7  wenn ein r aus R mit wirkung = gewaehrung existiert:
         gib erlaubt mit quellenliste { r aus R : wirkung = gewaehrung }
  8  gib verweigert("keine Regel trifft", -)                                 # Default-Deny
```

Fünf Eigenschaften sind Entwurfsentscheidungen mit Begründung.

**Verweigerung gewinnt unbedingt** (Schritt 6 vor Schritt 7). Damit ist das Ergebnis reihenfolgeunabhängig: keine Prioritätszahlen, keine Spezifitätsheuristik, kein Ausgang, der von der Sortierung der Regelbasis abhängt. Der Preis ist bekannt und wird benannt: ein einziger weit gefasster Ausschluss sperrt große Gruppen aus, und ohne Werkzeug ist die Ursache schwer zu finden. Die Gegenmaßnahme ist die Quellenliste in Schritt 6, nicht der Verzicht auf den Vorrang.

**Default-Deny ist der letzte Schritt** (Schritt 8, INV-10). Eine Anfrage, für die keine Regel existiert, wird abgelehnt, nicht durchgelassen. Die Fehlermeldung nennt die fehlende Aktionsklasse und den Mandanten, nie den Namen eines Objekts, das der Anfragende nicht sehen darf; andernfalls wäre die Fehlermeldung ein Leseweg.

**Gruppenmitgliedschaft wirkt nur über Regeln.** Eine Mitgliedschaft erzeugt für sich kein Recht ([Kapitel 10](10-identitaet.md)). Schritt 2 setzt die Mitgliedschaft in die Subjektmenge ein; wirksam wird sie erst, wenn eine Regel dieses Subjekt nennt. Daraus folgt der vollständige Rückbau: entfällt die Regel oder die Zuweisung, verfallen alle daraus abgeleiteten Artefakte unabhängig vom Gruppenpfad.

**Bedingungen sind Laufzeiteigenschaften, Selektoren sind Objekteigenschaften.** Die Trennung ist nicht kosmetisch: Selektoren müssen in ein Abfrageprädikat übersetzbar sein, Bedingungen nicht. Die Begründung steht in der folgenden Rechnung.

**Zeitlich begrenzte Rechte sind der Normalfall, nicht die Ausnahme.** `gueltig_bis` ist Pflichtfeld für jede Regel, die eine Aktionsklasse der Verwaltung gewährt. Vorgabewerte (Zielwerte): Mandantenrolle ≤ 365 d, Plattformrolle ≤ 90 d, Betreuungszugriff ≤ 365 d, erhöhter Zugriff aus einem Wartungsfenster ≤ 24 h, Notzugangssitzung ≤ 60 min.

**Rechnung Expositionsfenster.** Annahme: ein Administratorkonto wird je Jahr mit Wahrscheinlichkeit q = 0,01 übernommen, Annahme: der Angreifer nutzt eine Übernahme nur, solange die Rechte aktiv sind. Stehende Rechte sind 8.760 h/a aktiv. Bedarfsgebundene Erhöhung mit angenommen 2 h je Woche ergibt 104 h/a. Verhältnis 8.760 / 104 = 84. **Deutung:** das Zeitfenster, in dem eine Übernahme unmittelbar Verwaltungsrechte trägt, sinkt um den Faktor 84. Die Rechnung gilt ausdrücklich nicht für einen Angreifer mit dauerhaftem Zugang zum Endgerät: der wartet auf die nächste Erhöhung, und für ihn beträgt der Gewinn Null. Befristung wirkt gegen Gelegenheitsangriffe und gegen vergessene Rechte, nicht gegen einen etablierten Angreifer.

### Selektoren müssen zu Abfrageprädikaten werden

Eine Rechteprüfung je Objekt ist für Einzelzugriffe billig und für Listen unbezahlbar.

**Rechnung.** Annahme: 500 Regeln je Mandant, Index über (Mandant, Aktionsklasse), 12 Aktionsklassen, also im Mittel 42 Kandidatenregeln je Anfrage. Annahme 2 µs je Selektorprüfung. Einzelzugriff: 42 × 2 µs = 84 µs, gegenüber dem Budget von 300 ms aus K-18 sind das 0,03 %. Liste mit 10.000 Objekten bei Prüfung je Objekt: 10.000 × 84 µs = 0,84 s, also 280 % des Budgets aus K-18. **Ergebnis:** die objektweise Prüfung verletzt die Zusage um den Faktor 2,8 und ist ausgeschlossen.

**Folge als Entwurfsentscheidung.** Die Selektorsprache ist auf Ausdrücke beschränkt, die sich in ein Abfrageprädikat übersetzen lassen: Gleichheit, Ungleichheit, Mengenzugehörigkeit und Präfixvergleich über indizierte Felder, verknüpft mit Und. Kein Oder über Felder verschiedener Tabellen, keine Unterabfragen, keine Funktionsaufrufe, keine rekursive Traversierung. Die Prüfung wird dadurch nicht auf Zeilen angewandt, sondern in die Abfrage hineingezogen; die Datenzugriffsschicht erhält aus der Regelauswertung ein zusätzliches Prädikat und lehnt jede Abfrage ohne dieses Prädikat ab. Die verworfene Alternative, eine mächtigere Ausdruckssprache mit nachträglicher Filterung, kostet die Listenzusage und erzeugt zusätzlich eine Leckstelle: die Gesamtzahl der Treffer vor der Filterung verrät die Existenz nicht sichtbarer Objekte.

Sichere Praxis an dieser Schnittstelle: das Prädikat wird als parametrisierte Abfrage gebunden, nie als Zeichenkette zusammengesetzt; Selektorwerte werden am Rand gegen das Schema validiert (Typ, Länge, erlaubte Operatoren) und niemals in eine Kommandozeile, eine Vorlage oder einen dynamisch ausgewerteten Ausdruck übernommen. Die Regelsprache ist ausdrücklich keine Programmiersprache: sie kennt keine Schleifen, keine Funktionsdefinition und keinen Aufruf externen Codes, weil ein Skriptinterpreter im Freigabeweg ein Rechteausweitungspfad in der Kontrollebene wäre ([Kapitel 5](05-systemarchitektur.md)). Dieselbe Vorgabe steht als Auflage an der Selektorsyntax in [Anhang A](A1-schemata.md), A1.5; dort ist sie an das Schema der Rechteregel gebunden, hier an die Begrenzung der Regelsprache.

### Regelkonflikte sichtbar machen

Ein Konflikt ist ein Paar aus einer Gewährung und einem Ausschluss, deren Subjektmengen, Aktionsmengen und Selektormengen sich schneiden. Weil die Selektorsprache beschränkt ist, ist der Schnitt entscheidbar: Gleichheiten und Mengen schneiden sich prüfbar, Präfixe ebenfalls.

**Rechnung Aufwand.** 500 Regeln ergeben 500 × 499 / 2 = 124.750 Paare. Annahme 20 µs je Schnittprüfung: 2,5 s. **Deutung:** die vollständige Konfliktprüfung gehört nicht in den Anfragepfad, sondern läuft als Hintergrundaufgabe nach jeder Regeländerung und zusätzlich täglich. Die Wirkungsvorschau einer einzelnen Regeländerung prüft nur die neue Regel gegen alle anderen: 499 Paare × 20 µs = 10 ms, also innerhalb des Vorschaubudgets von 2 s aus K-18 mit großem Abstand.

Die Konsole zeigt Konflikte an drei Orten: an der Regel, an der Rolle und an der betroffenen Person mit dem Ergebnis "verweigert" und der Gegenquelle. Ein Konflikt blockiert nichts. Er ist in gewachsenen Organisationen normal und wird ausgewiesen, nicht verhindert. Zusätzlich zeigt jede Rechteansicht auf Abruf die vollständige Quellenliste einschließlich des Gruppenpfads; ohne diese Liste ist ein Ausschlussvorrang unbedienbar.

**Anforderungen**

- **R-19-11** — Das Ergebnis von `entscheidung` ist unabhängig von der Reihenfolge der Regelmenge. Prüfbar: Permutationstest über 1.000 zufällige Sortierungen je Testfall.
- **R-19-12** — Eine Anfrage, auf die keine Regel zutrifft, wird abgelehnt; die Ablehnung nennt 0 Namen von Objekten, für die kein Leserecht besteht. Prüfbar: Musterprüfung der Ablehnungstexte gegen Objektnamen (INV-10, INV-17).
- **R-19-13** — Jede Listenabfrage trägt das aus der Regelauswertung erzeugte Prädikat; eine Abfrage ohne dieses Prädikat wird von der Datenzugriffsschicht abgelehnt. Prüfbar: Abfrageprotokollprüfung im Bau (INV-19).
- **R-19-14** — Eine Selektorangabe außerhalb der zulässigen Ausdrucksmenge wird beim Speichern der Regel abgelehnt und nennt den unzulässigen Teilausdruck. Prüfbar: Eingabetest mit Unterabfrage, Oder-Verknüpfung und Funktionsaufruf.
- **R-19-15** — Jede Regel mit `wirkung = ausschluss` trägt eine nichtleere Begründung; Speichern ohne Begründung wird abgelehnt. Prüfbar: Speicherversuch ohne Begründung.
- **R-19-16** — Jede Regel, die eine Aktionsklasse der Verwaltung gewährt, trägt ein Gültigkeitsende; die Vorbelegung nennt ihre Quelle. Prüfbar: Anlegeversuch ohne Ende und Formularprüfung (INV-15).
- **R-19-17** — Die Wirkungsvorschau einer Regeländerung nennt die entstehenden Konflikte und die Zahl der betroffenen Personen, p95 ≤ 2 s. Prüfbar: Vorschau auf eine Regel mit 500 betroffenen Personen mit Zeitmessung (INV-08, K-18).

## 19.4 Vordefinierte Rollen und ihre Rechte

Acht Rollen sind vorgegeben. Jede trägt eine Geltung (Plattform, Mandant, Dienst) und ist versioniert; eine Änderung an einer Rolle ist ein Vorgang mit Wirkungsvorschau.

| Rolle | Kurz | Geltung | Zweck in einem Satz |
|---|---|---|---|
| Plattformeigner | PE | Plattform | Besitzt die Installation, die Knoten und den Katalog; verwaltet Mandanten als Objekte |
| Mandantenadministrator | MA | Mandant | Verwaltet Personen, Dienste, Namen und Rechte eines Mandanten |
| Dienstadministrator | DA | Dienst | Betreibt einzelne Dienste, ohne Rechte an Personen und Namen |
| Helpdesk | HD | Mandant | Hilft Personen bei Anmeldung, Geräten und Postfächern, ohne Rechte zu erhöhen |
| Freigeber | FG | Mandant oder Plattform | Entscheidet über vorgelegte Vorgänge, führt selbst keine aus |
| Prüfer/Auditor | PR | Mandant oder Plattform | Liest alles Lesbare und den Auditstrom, ändert nichts |
| Kundenkontakt | KK | Kundenbereich | Sieht die eigenen Objekte des Endkunden und stellt Anträge |
| Nur-Lesen | NL | Mandant | Liest Betriebsdaten ohne Auditzugriff |

Zeichen der Matrix: **A** ausführen, **F** ausführen, aber freigabepflichtig, **B** nur beantragen (erzeugt einen Vorgang, den ein anderer freigibt), **L** nur lesen, **N** nur über Notzugang mit Alarmierung, **–** nicht sichtbar.

| Aktionsklasse | PE | MA | DA | HD | FG | PR | KK | NL |
|---|---|---|---|---|---|---|---|---|
| Mandant anlegen, Isolationsstufe erhöhen | A | – | – | – | – | L | – | – |
| Isolationsstufe absenken | F | B | – | – | – | L | – | – |
| Mandant sperren, stilllegen, auflösen | F | B | – | – | – | L | – | – |
| Knoten aufnehmen, räumen, entkoppeln | A | – | – | – | – | L | – | L |
| Katalogeintrag freigeben oder zurückziehen | F | – | – | – | – | L | – | – |
| Dienst bereitstellen, aktualisieren | L | A | A | – | – | L | – | L |
| Dienst entfernen einschließlich Daten | L | F | B | – | – | L | – | – |
| Veröffentlichung intern anlegen, ändern | L | A | A | – | – | L | – | L |
| Veröffentlichung extern anlegen, ändern | L | F | B | – | – | L | B | L |
| Domäne anlegen, DNS-Eintrag setzen | L | A | – | – | – | L | – | L |
| Person anlegen, ändern, sperren | – | A | – | A | – | L | – | L |
| Person löschen | – | F | – | B | – | L | – | – |
| Zuweisung setzen und entziehen (Dienstrolle) | – | A | – | B | – | L | B | L |
| Zuweisung setzen und entziehen (Verwaltungsrolle) | F | F | – | – | – | L | – | – |
| Rolle oder Regel ändern | F | F | – | – | – | L | – | – |
| Authentikator zurücksetzen (ohne Verwaltungsrolle) | – | A | – | A | – | L | – | – |
| Authentikator zurücksetzen (mit Verwaltungsrolle) | – | F | – | – | – | L | – | – |
| Gerät registrieren, sperren | – | A | – | A | – | L | B | L |
| Gerät vollständig löschen | – | F | – | B | – | L | – | – |
| Geheimnis hinterlegen, wechseln | A | A | A | – | – | – | – | – |
| Geheimnis im Klartext lesen | – | – | – | – | – | – | – | – |
| Konnektorbindung einrichten, ändern | L | A | – | – | – | L | – | L |
| Wiederherstellungspunkt einspielen | F | F | B | – | – | L | – | – |
| Freigabe erteilen | – | – | – | – | A | – | – | – |
| Freigabeweg festlegen | F | F | – | – | – | L | – | – |
| Freigabeverknüpfung über Mandantengrenze | F | F | – | – | – | L | – | – |
| Auditköpfe des eigenen Mandanten lesen | L | L | – | L | L | L | L | – |
| Auditanhänge lesen | N | L | – | – | – | L | – | – |
| Auditexport erzeugen und prüfen | L | A | – | – | – | A | – | – |
| Notzugang in einen Mandanten auslösen | F | – | – | – | – | – | – | – |
| Eigene Objekte im Kundenbereich lesen | – | L | – | – | – | L | L | – |
| Antrag im Kundenbereich stellen | – | – | – | – | – | – | A | – |

Drei Zeilen verdienen eine Begründung. **Geheimnis im Klartext lesen** ist für jede Rolle leer; die Zeile steht in der Matrix, damit sichtbar ist, dass die Leerstelle Absicht ist und nicht ein vergessener Eintrag (INV-20). **Auditanhänge lesen** trägt für den Plattformeigner ein N: der Betreiber kommt an personenbezogene Anhänge fremder Mandanten nur über den alarmierten Notzugang, nicht über seine Standardrolle. **Freigabe erteilen** trägt ausschließlich der Freigeber; kein Administrator kann seine eigene Vorlage freigeben, und die Rolle Freigeber trägt umgekehrt keine ausführende Klasse.

**Anforderungen**

- **R-19-18** — Keine vordefinierte Rolle enthält ein Recht, ein Geheimnis im Klartext zu lesen oder zu exportieren. Prüfbar: Rechteliste aller Rollen gegen die Aktionsklasse "Geheimnis lesen"; ein Treffer bricht den Bau (INV-20).
- **R-19-19** — Die Rolle Prüfer/Auditor enthält 0 schreibende Aktionsklassen und 0 Freigaberechte, aber vollen Auditzugriff im Geltungsbereich. Prüfbar: Rechtelistenprüfung und Schreibversuch in allen Aktionsklassen.
- **R-19-20** — Eine Änderung an einer vordefinierten Rolle erzeugt eine neue Rollenversion und einen Vorgang mit der Zahl der betroffenen Personen; die alte Version bleibt für die Auswertung historischer Auditereignisse abrufbar. Prüfbar: Änderung mit anschließender Auditabfrage auf ein älteres Ereignis (INV-23).

## 19.5 Funktionstrennung

### Unvereinbare Kombinationen

| Kombination | Warum unvereinbar | Durchsetzung |
|---|---|---|
| Prüfer/Auditor + jede schreibende Rolle | Wer ändert, prüft nicht seine eigene Änderung; sonst ist der Nachweis wertlos | statisch, hart |
| Freigeber + Antragsteller desselben Vorgangs | Vier-Augen-Prinzip; sonst ist die Freigabe eine Selbstbestätigung | dynamisch, hart (Regel R-FG-EIGENANTRAG) |
| Freigeber + Dienstadministrator desselben Dienstes | Der Betroffene entscheidet über den eigenen Antrag mit einem Umweg über einen zweiten Vorgang | statisch, weich mit Begründungspflicht |
| Helpdesk + Freigeber | Helpdesk erzeugt die meisten Anträge auf Rücksetzungen; die Kombination hebt die Prüfung dieser Anträge auf | statisch, hart |
| Mandantenadministrator + Prüfer/Auditor im selben Mandanten | Wer Rechte vergibt, prüft nicht die Vergabe von Rechten | statisch, hart |
| Plattformeigner + Freigeber für Plattformvorgänge | Sonst gibt der Betreiber seine eigenen Eingriffe in Kundenmandanten frei | statisch, hart |
| Kundenkontakt + jede Rolle des betreuenden Mandanten | Der Kundenkontakt handelt im Interesse des Endkunden; die Kombination vermischt die Auftraggeber | statisch, hart |
| Dienstkonto + Freigeber | Eine Freigabe ist eine menschliche Entscheidung; ein Automat, der freigibt, ist keine Freigabe | statisch, hart |

### Wie das System das durchsetzt

Drei Ebenen, die gemeinsam wirken.

1. **Beim Setzen der Zuweisung.** Die Wirkungsvorschau prüft die entstehende Rollenmenge gegen die Unvereinbarkeitstabelle. Eine harte Unvereinbarkeit wird abgelehnt und nennt beide Rollen sowie die Regel; eine weiche verlangt eine Begründung im Pflichtfeld und erzeugt ein Auditereignis mit dem Kennzeichen "Funktionstrennung bewusst durchbrochen".
2. **Bei der Auswertung.** Ausschlussregeln wirken auch dann, wenn eine Zuweisung auf anderem Weg entstanden ist, etwa über eine Gruppenverschachtelung oder eine Freigabeverknüpfung. Schritt 6 der Auswertung ist die letzte Verteidigungslinie; sie ist unabhängig davon, ob Ebene 1 den Fall gesehen hat.
3. **Als laufende Prüfung.** Eine tägliche Hintergrundprüfung meldet bestehende Verstöße, weil Rollen sich ändern und eine gestern zulässige Kombination heute unzulässig sein kann. Die Meldung erscheint als Aufgabe im Überblick, nicht als Bericht.

### Kleine Organisationen

Funktionstrennung ist ein Personalproblem, kein Technikproblem. In einer Organisation mit drei Personen gibt es keine vier Augen. Der Entwurf verschweigt das nicht und bietet drei Auswege, von denen keiner die Trennung ersetzt.

| Ausweg | Was er leistet | Was er nicht leistet |
|---|---|---|
| Externer Freigeber über Freigabeverknüpfung (Steuerberater, Dienstleister, zweite Geschäftsführung) | Echte zweite Person mit eigener Anmeldung | Fachliche Prüfung; der Externe sieht den Vorgang, nicht dessen betriebliche Begründung |
| Zeitverzögerung statt zweiter Person: der Vorgang wird nach einer Wartezeit (Zielwert 4 h) wirksam, Alarmierung läuft sofort | Ein Zeitfenster zum Widerspruch | Schutz gegen den einzigen Administrator, der in diesem Fenster wach ist |
| Dokumentierte Annahme des Risikos mit Befristung (Zielwert ≤ 365 d) und dauerhafter Anzeige | Ehrlichkeit über den Zustand | Irgendeine Schutzwirkung |

Der dritte Ausweg ist ausdrücklich kein Sicherheitsmerkmal, sondern die Weigerung, eine Schutzwirkung zu behaupten, die nicht existiert. Eine Installation im Zustand "Funktionstrennung nicht hergestellt" zeigt das dauerhaft im Überblick (INV-18).

**Anforderungen**

- **R-19-21** — Eine Zuweisung, die eine hart unvereinbare Rollenkombination erzeugt, wird abgelehnt; die Ablehnung nennt beide Rollen und die zugrunde liegende Regel. Prüfbar: Zuweisungsversuch für jede Zeile der Unvereinbarkeitstabelle.
- **R-19-22** — Eine weich unvereinbare Kombination ist nur mit nichtleerer Begründung setzbar und erzeugt ein Auditereignis mit dem Kennzeichen "Funktionstrennung bewusst durchbrochen". Prüfbar: Setzversuch ohne Begründung, anschließende Auditabfrage (INV-23).
- **R-19-23** — Eine bestehende unvereinbare Kombination erscheint spätestens 24 h nach ihrem Entstehen als Aufgabe im Überblick. Prüfbar: Injektion über eine Gruppenverschachtelung, Wartezeitprüfung (INV-18).

## 19.6 Freigabewesen

### Zustandsautomat

```
                        +-----------+
                        |  Entwurf  |  Wirkungsvorschau wird berechnet (INV-08)
                        +-----+-----+
                              | einreichen (Antragsteller)
                              v
   ablehnen  +-----------+    +-----------+   F1 ohne Entscheidung   +-----------+
  <-----------|  Pruefung |<---+ Pruefung  +------------------------->| Abgelaufen|
   Abgelehnt  +-----------+    +-----+-----+                          +-----------+
                                     | freigeben (Freigeber != Antragsteller,
                                     |            Vorschauhash unveraendert)
                                     v
                              +-------------+   F2 ohne Anwendung    +-----------+
                              | Freigegeben +----------------------->| Verfallen |
                              +------+------+                        +-----------+
                                     | anwenden
                                     v
                              +-------------+  Teilzustaende je Zielsystem (INV-12)
                              |  Anwendung  |
                              +------+------+
                                     |
                                     v
                              +--------------+  Istzustand beobachtet (INV-28)
                              | Verifikation |
                              +---+------+---+
                   bestaetigt     |      |     Abweichung
                                  v      v
                          +-----------+  +---------------------------+
                          |  Wirksam  |  | Teilweise fehlgeschlagen  |
                          +-----------+  |  mit benannten Resten     |
                                         +---------------------------+
```

Zwei Zustände dieses Automaten existieren in üblichen Freigabeverfahren nicht und sind hier notwendig.

**Verifikation** ist ein eigener Zustand, weil eine angewandte Änderung noch keine wirksame Änderung ist. Der Vorgang gilt erst als wirksam, wenn der beobachtete Istzustand die Wirkung bestätigt; bis dahin steht er sichtbar in Verifikation, mit Beobachtungszeitpunkt (INV-28). Ein Vorgang endet nie im Zustand "fertig", solange ein Zielsystem aussteht (INV-12).

**Verfallen** existiert, weil eine Freigabe an eine konkrete Wirkungsvorschau gebunden ist. Die Freigabe trägt die Sollzustandsversion und den Hashwert der Vorschau. Hat sich der Sollzustand zwischen Freigabe und Anwendung geändert, ist die Vorschau nicht mehr die Vorschau der jetzt anstehenden Wirkung; der Vorgang verfällt und die Vorschau wird neu berechnet. Ohne diese Bindung gibt ein Freigeber eine Wirkung frei und eine andere wird ausgeführt. Zielwert für F2 (Anwendungsfrist nach Freigabe): 24 h.

### Zwingend freigabepflichtige Aktionen

| Aktionsklasse | Warum | Vier-Augen | Frist F1 (Zielwert) |
|---|---|---|---|
| Löschung mit Datenvernichtung (Dienst mit Daten, Speicherbereich, Postfach, Person, Mandant) | Nicht rücknehmbar nach Ablauf der Aufbewahrungsfrist (INV-11) | ja | 72 h |
| Rechteerhöhung (Zuweisung einer Verwaltungsrolle, Rollen- und Regeländerung) | Erweitert die Menge der Handlungsfähigen | ja | 72 h |
| Externe Veröffentlichung (Sichtbarkeit "extern") | Macht einen Dienst aus dem offenen Netz erreichbar | ja | 24 h |
| Mandantenübergreifendes (Freigabeverknüpfung, Betreuungszugriff) | Durchbricht die einzige strukturell erzwungene Grenze (INV-19) | ja, je ein Administrator beider Mandanten | 72 h |
| Notzugang in einen Mandanten | Umgeht die reguläre Rechtevergabe | nachgelagert, Begründungspflicht binnen 72 h | – |
| Absenkung einer Isolationsstufe | Reduziert eine zugesicherte Trennung | ja | 72 h |
| Einspielen eines Wiederherstellungspunkts | Überschreibt aktuellen Zustand mit älterem | ja | 24 h |
| Aufnahmetoken für unbeaufsichtigte Kopplung | Inhabergeheimnis, das einen Knoten erzeugt | ja | 24 h |

Ebenso wichtig ist die Gegenliste. **Nicht freigabepflichtig sind Schutzhandlungen:** Gerät sperren, Person sperren, Zertifikat sperren, Veröffentlichung zurückziehen, Dienst anhalten, Konnektorbindung aussetzen. Die Asymmetrie ist Entwurf: Handlungen, die dem Angreifer schaden, laufen sofort; Handlungen, die den Daten schaden, laufen über eine Freigabe. Eine Vier-Augen-Pflicht auf der schnellsten Schutzhandlung verzögert genau den Fall, für den sie existiert.

### Vier-Augen, Vertretung, Fristen

Ein Freigabeweg besteht aus Aktionsklasse, Geltungsbereich, freigebender Rolle, Vier-Augen-Kennzeichen, F1 und Eskalationsfrist. Er wird in "Mandanten & Rechte" festgelegt und kostet drei Entscheidungen (K-03, Aufgabe 29).

**Rechnung Freigeberzahl.** Annahme: ein benannter Freigeber ist innerhalb der Eskalationsfrist mit Wahrscheinlichkeit p = 0,8 erreichbar, Erreichbarkeiten unabhängig. Bei Vier-Augen ist mindestens ein Freigeber ungleich dem Antragsteller nötig; ist der Antragsteller selbst Träger der Rolle, stehen n − 1 zur Verfügung.

```
P(mindestens 1 von n-1 erreichbar) = 1 - (1-p)^(n-1)
n = 2 :  1 - 0,2      = 0,800
n = 3 :  1 - 0,2^2    = 0,960
n = 4 :  1 - 0,2^3    = 0,992
n = 5 :  1 - 0,2^4    = 0,9984
```

**Deutung:** mit zwei benannten Freigebern bleibt jede fünfte Freigabe in der Frist liegen, mit dreien jede fünfundzwanzigste. Zielwert: mindestens 3 Träger der Freigeberrolle je Geltungsbereich; die Konsole warnt dauerhaft bei weniger. Das ist eine Modellrechnung; die Unabhängigkeitsannahme ist ihr schwächstes Glied, weil Urlaubszeiten und gemeinsame Abwesenheiten Erreichbarkeiten koppeln.

**Vertretungsregel.** Jede Freigeberrolle trägt mindestens eine benannte Vertretung. Die Vertretung wird nach Ablauf der Eskalationsfrist (Zielwert 24 h von 72 h) automatisch aktiv; die Aktivierung ist ein Auditereignis und am Vorgang sichtbar. Die Vertretung erbt ausschließlich das Freigaberecht, nicht die zugrunde liegenden Verwaltungsrechte, und ist denselben Unvereinbarkeiten unterworfen; insbesondere kann eine Vertretung den eigenen Antrag nicht freigeben.

**Leerer Freigeberkreis.** Der Freigabeweg wird beim Festlegen darauf geprüft, ob die Menge zulässiger Freigeber unter Berücksichtigung aller Ausschlüsse leer werden kann. Ein Weg, der leer werden kann, wird abgelehnt und nennt den Grund. Die Alternative, den Fall erst beim Einreichen zu bemerken, verlagert eine Konfigurationsfehlerentdeckung in den Störungsfall.

### Wenn niemand freigibt

Der Vorgang läuft ab. Es gibt keine automatische Freigabe nach Fristablauf, in keiner Aktionsklasse, unter keiner Bedingung; eine Frist, nach deren Ablauf zugestimmt gilt, ist keine Freigabe, sondern eine Verzögerung. Der Antragsteller erhält den Vorgang als Aufgabe zurück, einschließlich der Angabe, wer nicht entschieden hat und wann eskaliert wurde.

Daraus folgt eine Frage, die der Entwurf beantworten muss: kann eine ausbleibende Freigabe eine Störung verursachen? Die Antwort ist konstruktiv nein, und zwar durch eine Regel über die Menge der freigabepflichtigen Aktionen: **Erneuerungen eines bestehenden Zustands sind nie freigabepflichtig.** Zertifikatserneuerung, Schlüsselwechsel nach Plan, Wiederanlauf eines Dienstes, Neuausstellung eines Gerätezertifikats laufen ohne Freigabe (K-13). Freigabepflichtig sind ausschließlich Zustandsänderungen. Eine ausbleibende Freigabe kann damit einen gewünschten neuen Zustand verhindern, nicht einen bestehenden zerfallen lassen. Der einzige verbleibende Fall ist eine Störung, deren Behebung eine freigabepflichtige Änderung verlangt und für die niemand erreichbar ist; das ist der Anwendungsfall des Notzugangs (19.10), und er ist der Grund, weshalb der Notzugang existieren muss.

**Anforderungen**

- **R-19-24** — Ein Vorgang wechselt niemals ohne ausdrückliche Entscheidung eines zulässigen Freigebers aus "Prüfung" nach "Freigegeben". Prüfbar: Fristüberschreitungstest über alle freigabepflichtigen Aktionsklassen; 0 automatische Freigaben.
- **R-19-25** — Eine Freigabe ist an Sollzustandsversion und Hashwert der Wirkungsvorschau gebunden; ändert sich der Sollzustand vor der Anwendung, verfällt die Freigabe und die Vorschau wird neu berechnet. Prüfbar: Änderung zwischen Freigabe und Anwendung (INV-08).
- **R-19-26** — Kein Vorgang erreicht "Wirksam", bevor der Istzustand die Wirkung je Zielsystem bestätigt; ein ausstehendes Zielsystem hält den Vorgang in "Anwendung" oder "Teilweise fehlgeschlagen" mit benannten Resten. Prüfbar: Fehlerinjektion in einen von mehreren Konnektoren (INV-12).
- **R-19-27** — Die Aktionsklassen Löschung mit Datenvernichtung, Rechteerhöhung, externe Veröffentlichung, Mandantenübergreifendes, Stufenabsenkung und Einspielen eines Wiederherstellungspunkts sind ohne Freigabe nicht ausführbar. Prüfbar: Ausführungsversuch je Klasse ohne Freigabe; 0 Wirkungen (INV-11).
- **R-19-28** — Schutzhandlungen (Sperren von Gerät, Person, Zertifikat; Zurückziehen einer Veröffentlichung; Anhalten eines Dienstes) sind ohne Freigabe ausführbar und wirken innerhalb von 30 s. Prüfbar: Sperrtest mit Zeitmessung (K-16).
- **R-19-29** — Ein Freigabeweg, dessen zulässige Freigebermenge leer werden kann, wird beim Festlegen abgelehnt und nennt den Grund. Prüfbar: Anlegeversuch mit einem einzigen Freigeber, der zugleich einziger Antragsteller ist.
- **R-19-30** — Eine Vertretung wird nach Ablauf der Eskalationsfrist automatisch aktiv, erbt ausschließlich das Freigaberecht und kann den eigenen Antrag nicht freigeben. Prüfbar: Fristablauftest und Selbstfreigabeversuch der Vertretung.

## 19.7 Kundenbereich

Ein Kundenbereich ist ein Fenster in einen Mandanten, kein Teilmandant. Er trägt keine eigene Rechtebasis, keine eigenen Rollen und keine eigene Isolationsstufe; er trägt eine Menge von Objekten, die dem Endkunden zugeordnet sind, und die Rolle Kundenkontakt.

| Der Endkunde sieht | Der Endkunde sieht nicht |
|---|---|
| Die ihm zugeordneten Dienste mit Zustand und Störungen | Auf welchen Knoten sie laufen |
| Die ihm zugeordneten veröffentlichten Namen und deren Zertifikatsrestlaufzeit | Die Zertifikatsübersicht des Mandanten, die CA, die Sperrliste |
| Die Personen seiner eigenen Organisation | Personen des Mandanten oder anderer Kundenbereiche |
| Seine eigenen offenen und abgeschlossenen Anträge | Vorgänge des Mandanten |
| Einen Auszug des Auditstroms, beschränkt auf Ereignisse an seinen Objekten | Auditereignisse des Mandanten, Anhänge fremder Ereignisse |
| Den Redundanzzustand seiner Dienste im Klartext | Knotennamen, Fehlerzonen, Kapazitätszahlen der Installation |
| Seine Postfächer und Mailadressen | Maildomänen anderer Kundenbereiche |

Die Zeile zum Redundanzzustand ist ein Entwurfskonflikt mit benannter Auflösung. INV-18 verlangt, dass Degradation am Objekt sichtbar ist; Knotennamen sind zugleich Infrastrukturinformation, die einem Endkunden nicht zusteht. Die Auflösung: der Kundenbereich zeigt den Zustand ("Redundanz: keine" oder "verträgt derzeit 1 Knotenausfall"), nicht die Identität der Knoten. Die Zusage aus INV-18 bleibt erfüllt, die Infrastrukturinformation bleibt innen.

### Selbstbedienung und Beantragung

Der Grundsatz lautet: **beantragen statt ausführen.** Der Endkunde führt ausschließlich Handlungen aus, die nur ihn selbst betreffen und keine Ressourcen der Plattform binden.

| Selbstbedienung (ausführen) | Beantragung (erzeugt Vorgang) |
|---|---|
| Eigenen Authentikator hinzufügen und entfernen, solange einer verbleibt | Person in der eigenen Organisation anlegen oder entfernen |
| Eigenes Gerät als verloren melden | Gerät registrieren |
| Eigene Sitzungen einsehen und widerrufen | Zusätzlichen Dienst erhalten |
| Eigene Kontaktdaten und Sprache ändern | Externe Veröffentlichung eines Namens |
| Eigenen Auditauszug einsehen und exportieren | Mailadresse hinzufügen oder ändern |
| Ein Anliegen mit Freitext und Anlagen einreichen | Kontingent erhöhen |

Ein Antrag erzeugt einen regulären Vorgang im Mandanten, mit Wirkungsvorschau, Freigabeweg und Auditkette. Der Endkunde sieht den Zustand seines Antrags und dessen Ergebnis; er sieht nicht die Wirkungsvorschau, weil sie Objekte des Mandanten nennt. Das ist eine bewusste Einschränkung: der Antragsteller erfährt "abgelehnt" mit dem Ablehnungstext, nicht mit der technischen Begründung. Wo der Ablehnungstext leer bleibt, entsteht genau der Zustand, den das Produkt vermeiden will, nämlich eine unbeantwortete Frage; der Ablehnungstext ist deshalb Pflichtfeld.

Die Selbstbedienungsoberfläche des Kundenbereichs ist an eine Veröffentlichung gebunden und damit denselben Regeln unterworfen wie jeder andere Dienst: Erreichbarkeit entsteht aus einer Veröffentlichung, nie aus einer Regel (INV-10), und sie unterliegt einer Ratenbegrenzung je Quelladresse.

**Anforderungen**

- **R-19-31** — Der Kundenbereich liefert 0 Knotennamen, 0 Fehlerzonen und 0 Kapazitätszahlen der Installation, zeigt aber den Redundanzzustand der eigenen Dienste im Klartext. Prüfbar: Inhaltsprüfung aller Antworten des Kundenbereichs gegen Knoten- und Zonennamen (INV-18).
- **R-19-32** — Jede Handlung des Kundenkontakts außerhalb der Selbstbedienungsliste erzeugt einen Vorgang und wirkt nicht unmittelbar. Prüfbar: Ausführungsversuch je Zeile der Beantragungsliste; 0 unmittelbare Wirkungen.
- **R-19-33** — Eine Ablehnung eines Antrags trägt einen nichtleeren, für den Endkunden verständlichen Text und nennt 0 Objekte des Mandanten. Prüfbar: Ablehnung ohne Text wird abgelehnt; Musterprüfung der Texte gegen Objektnamen (INV-17).
- **R-19-34** — Der Auditauszug des Kundenbereichs enthält ausschließlich Ereignisse an Objekten des Endkunden und 0 Anhänge fremder Ereignisse. Prüfbar: Auszugsprüfung nach gemischter Ereignislage (INV-19, INV-23).

## 19.8 Audit

[Kapitel 8](08-kontrollebene.md) begründet, weshalb der Auditstrom außerhalb des replizierten Kernzustands liegt, ohne Quorum schreibbar ist und keine globale Totalordnung besitzt.

### Ereignisschema

```
ereignis {                            # Kern: hashverkettet, unveraenderlich, nie loeschbar
  strom_kennung          ULID         # je Verwaltungsknoten genau ein Strom
  folgenummer            uint64       # lueckenlos aufsteigend je Strom
  zeitstempel            Zeitpunkt    # WANN, aus geprüfter Zeitquelle (INV-32)
  mandant                ULID         # Pflichtfeld, kein Leerwert (INV-19)
  akteur {                            # WER
    art                  enum { person, dienstkonto, regel, knoten }
    kennung              ULID
    herkunfts_mandant    ULID         # != mandant bei Betreiber- oder Betreuungszugriff
    rollen               [ULID]       # zum Zeitpunkt wirksame Rollen, als Version
  }
  aktion                 text<=64     # WAS, <objekt>.<verb> im Perfekt
  objektverweis          URN | null   # WORAN, schwacher Verweis, ueberlebt das Objekt
  quelle {                            # WOHER
    sitzung              ULID
    kanal                enum { konsole, api, atriumctl, reconciler, konnektor,
                                konsole_physisch }
    netzzone             ULID
    geraet               ULID | null
    authentisierungsguete enum { passkey_mfa, passkey, token, mtls, wiederherstellung }
  }
  ergebnis               enum { erfolg, teilweise, fehlschlag, abgelehnt }
  ergebnis_schluessel    text<=64     # Schluesselwort, kein Freitext, keine Fremdtexte
  vorgang                ULID         # IN WELCHEM VORGANG (Korrelationskennung)
  freigabe               ULID | null  # welche Freigabe die Aktion getragen hat
  begruendung_status     enum { keine_pflicht, erbracht, ausstehend, anhang_geloescht }
  anhang_hashwert        Hashwert | null   # Bindung an den Anhang
  vorgaenger_hashwert    Hashwert     # SHA-2 ueber die kanonische Form (RFC 8785)
}

anhang {                              # separat adressiert, loeschbar, nicht verkettet
  hashwert               Hashwert     # identisch mit ereignis.anhang_hashwert
  vorher / nachher       Objekt       # Feldwerte; Geheimnisse nur als Referenzname
  begruendung            text<=512    # WARUM, Freitext des Akteurs
  herkunftsdetail        Objekt       # Quelladresse, Client-Kennung
  aufbewahrungsklasse    enum { betrieb_90d, nachweis_12m, gesetzlich }
}

siegel {                              # Abschnittssiegel, Zielwert alle 5 min oder 1.000 Ereignisse
  strom_kennung          ULID
  von_folgenummer / bis_folgenummer   uint64
  merkle_wurzel          Hashwert     # ueber die Kernereignisse des Abschnitts
  ketten_hashwert        Hashwert     # Kernhashwert des letzten Ereignisses
  signatur               Ed25519      # Siegelschluessel des Verwaltungsknotens (RFC 8032)
}

tagessiegel {                         # einmal je Tag und Strom
  merkle_wurzel_der_siegel Hashwert
  signatur                 Ed25519
  zeitstempel_extern       TSA-Token  # RFC 3161, Pflicht; bei Nichterreichbarkeit
                                      # Kennzeichen "ohne externen Zeitstempel" + Aufgabe
}
```

### Warum Kern und Anhang getrennt sind

Diese Trennung ist die zentrale Entwurfsentscheidung des Abschnitts, und sie löst einen Konflikt, der sonst unlösbar bleibt. Der Kern enthält ausschließlich Kennungen, Aufzählungswerte und Schlüsselwörter, also keine Klartextnamen, keine Freitexte, keine Adressen. Nur der Kern geht in die Hashkette ein. Der Anhang enthält alles, was personenbezogen sein kann, ist über seinen Hashwert an den Kern gebunden und nicht Teil der Kette.

Daraus folgt unmittelbar: ein Anhang kann gelöscht werden, ohne die Kette zu brechen. Der Kern bleibt, der Hashwert bleibt, und das Feld `begruendung_status` wechselt auf `anhang_geloescht`. Die Löschung erzeugt selbst ein Auditereignis. Nachweisbar bleibt, **dass** eine Handlung stattgefunden hat, von wem, woran, wann, mit welchem Ergebnis, in welchem Vorgang und ob eine Begründung geschuldet und erbracht war; nicht mehr nachweisbar ist, **welche** Werte sich geändert haben und wie die Begründung lautete.

Der Preis dieser Lösung wird hier benannt und nicht kleingeredet: nach einer Anhanglöschung ist die inhaltliche Rekonstruktion eines Vorgangs unvollständig. Ein Auditor sieht, dass eine Lücke entstanden ist, warum sie entstanden ist und wer sie veranlasst hat; er sieht den Inhalt nicht mehr. Das ist die einzige Form, in der Unveränderlichkeit und ein Löschanspruch gleichzeitig eingelöst werden können, ohne dass eine der beiden Zusagen zur Behauptung wird.

### Hashverkettung, Siegel, Zeitstempel

Die Kette verläuft je Strom: `vorgaenger_hashwert` ist SHA-2 nach FIPS 180-4 über die kanonische Form des Vorgängerkerns nach RFC 8785. Eine Lücke in der Folgenummer oder ein nicht passender Vorgängerhashwert ist ein Kettenbruch.

Zwei Siegelebenen mit unterschiedlichem Zweck. Das **Abschnittssiegel** entsteht lokal alle 5 Minuten oder nach 1.000 Ereignissen und bindet den Abschnitt an den Siegelschlüssel des Verwaltungsknotens; es macht nachträgliches Umschreiben eines Abschnitts erkennbar, auch wenn der Angreifer die Kette neu berechnet. Das **Tagessiegel** trägt einen externen Zeitstempel nach RFC 3161 und bindet den Tag an eine Zeit, die nicht vom System selbst stammt; es macht Rückdatierung erkennbar.

**Rechnung Siegellast.** 365 × 24 × 12 = 105.120 Abschnittssiegel je Jahr und Strom. Annahme 300 Byte je Siegel: 31,5 MB je Jahr, gegenüber 730 MB Auditvolumen ([Kapitel 8](08-kontrollebene.md): 2.000 Ereignisse je Tag à 1 KB) sind das 4,3 %. Externe Zeitstempel: 365 Anfragen je Jahr und Strom, also 1 je Tag. **Deutung:** die Siegelung ist billig; ein externer Zeitstempel je Abschnitt wäre mit 105.120 Anfragen je Jahr an eine fremde Stelle eine Abhängigkeit, die der Nachweis nicht braucht.

**Rechnung Kettenprüfung.** 730.000 Ereignisse je Jahr; Annahme 1 µs je Ereignis für kanonische Form und Hashwert: 0,73 s. 105.120 Signaturprüfungen; Annahme 50 µs je Ed25519-Prüfung: 5,3 s. Lesen von 730 MB bei angenommenen 500 MB/s: 1,5 s. Summe rund 7,5 s. **Zielwert:** vollständige Prüfung einer Jahreskette ≤ 30 s, mit Reserve für langsamere Datenträger. Damit ist die von INV-23 verlangte Prüfung bei jedem Start und jedem Export durchführbar, ohne den Start spürbar zu verzögern.

### Auslagerung

Zwei Wege mit verschiedenen Aufgaben, die einander nicht ersetzen.

| Weg | Inhalt | Zweck | Grenze |
|---|---|---|---|
| Signierte Abschnittsdateien an ein Auslagerungsziel, fortlaufend, Zielwert Verzögerung ≤ 60 s | Kerne, Siegel, Anhänge nach Aufbewahrungsklasse | Nachweis; überlebt den Verlust der Installation | Nur beweiskräftig, wenn das Ziel nur anhängendes Schreiben zulässt |
| Laufender Strom nach RFC 5424 über TLS an 6514 | Kerne als strukturierte Daten | Korrelation in einem Fremdsystem | Kein Nachweis, solange der Empfänger nicht die kanonische Form speichert |

Die zweite Zeile ist eine ehrliche Einschränkung: ein Empfangssystem, das Felder umformt, zerstört die Prüfbarkeit der Signatur. Der Export für Nachweiszwecke ist deshalb immer der Dateiweg, und die Konsole benennt das an der Stelle, an der ein Ausleitungsziel eingerichtet wird.

### Gefilterter Export und seine Prüfbarkeit

Ein Auditor eines Mandanten darf nur die Ereignisse dieses Mandanten erhalten. Ein nach Mandant gefilterter Auszug hat Lücken in der Folgenummer und lässt sich deshalb nicht gegen die lineare Kette prüfen. Ohne Gegenmaßnahme wäre der mandantengefilterte Export nicht verifizierbar, und genau das ist der Export, den ein Kunde bekommt.

Die Auflösung ist der Merkle-Baum je Abschnittssiegel. Der gefilterte Export enthält je Ereignis den Inklusionspfad zur signierten Wurzel; der Prüfer rechnet Kern → Blatt → Wurzel und prüft die Signatur. Fremde Ereignisse erscheinen dabei ausschließlich als Hashwerte, geben also weder Inhalt noch Mandant preis.

**Rechnung Mehraufwand.** Bei 1.000 Ereignissen je Abschnitt ist die Pfadlänge ⌈log₂ 1.000⌉ = 10 Hashwerte à 32 Byte = 320 Byte je Ereignis. Bei einem Kern von angenommen 400 Byte sind das 80 % Mehraufwand im gefilterten Export, absolut 320 Byte je Ereignis. **Deutung:** der Mehraufwand fällt nur im Export an, nicht im Speicher, und ist der Preis dafür, dass ein Kunde seinen Auszug prüfen kann, ohne fremde Ereignisse zu sehen.

Der Prüfer unterscheidet zwei Exportarten und behandelt Lücken verschieden: im **Vollexport** ist eine Lücke in der Folgenummer ein Kettenbruch, im **gefilterten Export** ist sie erwartet und muss durch den Inklusionspfad gedeckt sein. Ein gefilterter Export ohne Inklusionspfade wird vom Prüfwerkzeug als "nicht prüfbar" bewertet, nicht als "gültig".

### Aufbewahrung, Verdichtung, Fristen

| Gegenstand | Frist (Zielwert) | Danach |
|---|---|---|
| Kernereignisse online | 12 Monate vollständig (K-25) | Verdichtung, Originalabschnitte nur noch in der Auslagerung |
| Anhang Klasse `betrieb_90d` (Herkunftsdetail, technische Vorher/Nachher-Werte) | 90 Tage | automatische Löschung, Kern bleibt |
| Anhang Klasse `nachweis_12m` (Begründungen, Freigaben) | 12 Monate | Löschung, `begruendung_status` wechselt |
| Anhang Klasse `gesetzlich` | nach Richtlinie des Mandanten | keine automatische Löschung |
| Siegel und Tagessiegel | so lange wie der zugehörige Abschnitt in der Auslagerung | — |

Die Verdichtung erzeugt einen eigenen, signierten **Verdichtungssatz** je Zeitraum: Zählwerte je Aktion, Akteur und Ergebnis, dazu die Merkle-Wurzeln und Tagessiegel des Zeitraums. Damit bleibt die Aussage "in diesem Zeitraum sind genau diese Wurzeln gesiegelt worden" prüfbar, auch wenn die Einzelereignisse online nicht mehr vorliegen. Die Verdichtung löscht nicht aus der Auslagerung.

Die konkreten gesetzlichen Aufbewahrungsfristen werden hier **nicht** genannt, weil sie von Rechtsform, Gegenstand und Land abhängen und eine erfundene Zahl an dieser Stelle schädlicher wäre als keine. Der Entwurf verlangt stattdessen: je Mandant ist eine Aufbewahrungsrichtlinie zu setzen, die Klasse `gesetzlich` verweist auf sie, und ein Konflikt zwischen einem Löschanspruch und einer Aufbewahrungspflicht wird angezeigt und nicht technisch entschieden. Die Abwägung führt [Kapitel 22](22-compliance.md).

### Prüfwerkzeug für Auditoren

Ein Nachweis, der nur mit dem geprüften System prüfbar ist, ist kein Nachweis. Der Entwurf verlangt deshalb ein eigenständiges Prüfprogramm mit vier Eigenschaften: es läuft außerhalb der Installation, es liest ausschließlich die Exportdateien, es benötigt keine Verbindung zu Atrium, und sein Eingabeformat ist vollständig dokumentiert, sodass eine unabhängige Zweitimplementierung möglich ist. Es prüft Kette, Lückenfreiheit beziehungsweise Inklusionspfade, Abschnitts- und Tagessiegel gegen den veröffentlichten Siegelschlüssel sowie die externen Zeitstempel, und gibt je Abschnitt ein Urteil aus: geprüft, nicht prüfbar, gebrochen.

Dieselbe Prüfung ist in der Konsole als Handlung verfügbar und liefert dasselbe Urteil; ein Fehlerfall, dessen einziger Lösungsweg ein Konsolenbefehl wäre, gilt als nicht fertig entwickelt (INV-17, INV-26). Der Name des Programms ist Entwurfsstand und im Kanon nicht festgelegt; dieses Kapitel legt die Anforderung fest, nicht den Namen.

### Verhalten bei Kettenbruch

Ein Bruch wird nicht repariert. Eine Reparatur wäre eine Änderung an einem unveränderlichen Nachweis und damit genau der Vorgang, gegen den die Kette schützt.

```
1  Erkennung bei Start, bei jedem Export und in einer taeglichen Pruefung
2  Der betroffene Strom wird ab der Bruchstelle als "gebrochen" gekennzeichnet;
   alle Abschnitte bis zum letzten gueltigen Siegel bleiben gueltig und bleiben es
3  Es wird ein neuer Strom mit neuer Strom_Kennung eroeffnet; dessen erstes Ereignis
   nennt den alten Strom, die Bruchstelle und den letzten gueltigen Kettenhashwert
4  Vergleich mit der ausgelagerten Kopie:
     lokal gebrochen, Auslagerung gueltig  -> Hypothese Datentraeger oder Prozess lokal
     beide gebrochen an derselben Stelle   -> Hypothese Eingriff vor der Auslagerung
     Auslagerung fehlt                     -> keine Aussage moeglich, so gemeldet
5  Alarmierung an alle Traeger der Pruefer- und Plattformrollen, dauerhaftes Banner
6  Der Bruch erscheint dauerhaft im Nachweis; er wird nie stillschweigend geschlossen
```

Schritt 4 liefert ausdrücklich Hypothesen, keine Feststellung. Die Unterscheidung zwischen Datenträgerfehler und Eingriff ist aus den vorliegenden Daten nicht entscheidbar, und eine Oberfläche, die sich für eine der beiden Ursachen entscheidet, behauptet mehr, als sie weiß.

**Anforderungen**

- **R-19-35** — Jedes Auditereignis trägt Akteur, Aktion, Objektverweis, Zeitstempel, Quelle, Ergebnis, Vorgangskennung und Begründungsstatus; ein Ereignis mit fehlendem Pflichtfeld wird nicht geschrieben. Prüfbar: Schemaprüfung aller erzeugten Ereignistypen im Bau (INV-23).
- **R-19-36** — Der Kern eines Auditereignisses enthält 0 Freitexte, 0 Klartextnamen und 0 Adressen. Prüfbar: Musterprüfung aller Kerne gegen Namens- und Adressmuster; ein Treffer bricht den Bau (INV-20).
- **R-19-37** — Die Löschung eines Anhangs lässt die Hashkette gültig und setzt `begruendung_status` auf `anhang_geloescht`; sie erzeugt selbst ein Auditereignis. Prüfbar: Löschung mit anschließender vollständiger Kettenprüfung (INV-23).
- **R-19-38** — Die vollständige Prüfung einer Kette über 12 Monate dauert ≤ 30 s. Prüfbar: Zeitmessung über einen erzeugten Jahresbestand von 730.000 Ereignissen (K-25).
- **R-19-39** — Ein mandantengefilterter Export enthält je Ereignis einen Inklusionspfad zu einem signierten Siegel und gibt 0 Inhalte fremder Ereignisse preis. Prüfbar: Inhaltsprüfung eines gefilterten Exports und Prüflauf ohne Zugriff auf die Installation (INV-19).
- **R-19-40** — Ein Kettenbruch wird gemeldet, nicht repariert; ein neuer Strom nennt Bruchstelle und letzten gültigen Kettenhashwert. Prüfbar: Injektion eines veränderten Ereignisses mit anschließendem Neustart (INV-23).
- **R-19-41** — Das Prüfprogramm verifiziert einen Export ohne Netzverbindung zur Installation und ohne Zugriff auf den Sollzustand. Prüfbar: Prüflauf auf einem getrennten Rechner ohne Netzzugang.
- **R-19-42** — Ein Ausleitungsziel, das die kanonische Form nicht erhält, wird in der Konsole als "Korrelation, kein Nachweis" gekennzeichnet. Prüfbar: Darstellungstest bei Einrichtung eines Syslog-Ziels (INV-17).

## 19.9 Personenbezug im Auditstrom

Ein Auditstrom ist notwendigerweise eine Sammlung personenbezogener Daten: er verzeichnet, wer wann was getan hat. Zwischen der Unveränderlichkeit aus INV-23 und einem Löschanspruch besteht ein echter Zielkonflikt; er wird hier nicht wegdefiniert, sondern an drei Stellen zerlegt.

**Minimierung im Kern.** Der Kern führt Kennungen, keine Namen. Eine ULID ist für sich genommen ohne die Zuordnungstabelle im Sollzustand nicht auf eine Person zurückführbar. Wird die Person gelöscht, verschwindet die Zuordnung; der Kern verweist danach auf ein nicht mehr auflösbares Objekt und nennt weiterhin, dass eine Handlung stattfand. Das ist keine Anonymisierung — die Zuordnung kann aus anderen Quellen rekonstruierbar bleiben —, aber es ist die wirksamste verfügbare Minimierung, und die Bezeichnung "Pseudonymisierung" ist die korrekte.

**Trennung in den Anhang.** Alles, was über die Kennung hinausgeht, liegt im Anhang und ist löschbar (19.8). Die Klasse `betrieb_90d` enthält Herkunftsdetails wie Quelladressen und wird ohne Zutun nach 90 Tagen gelöscht; damit verschwindet die Bewegungsspur automatisch, während der Nachweis der Handlung bleibt.

**Pseudonymisierung im Export.** Für die Ausleitung an ein Fremdsystem wird die Akteurkennung durch HMAC mit einem Schlüssel des Mandanten über die Kennung ersetzt, gekürzt auf 128 bit. Das Fremdsystem kann korrelieren, ohne rückschließen zu können; die Rückauflösung verlangt den Schlüssel und damit die Mitwirkung des Mandanten. **Rechnung Kollision:** bei 500 Akteuren ist die Kollisionswahrscheinlichkeit nach dem Geburtstagsmodell 500² / (2 · 2¹²⁸) = 250.000 / 6,81 · 10³⁸ ≈ 3,7 · 10⁻³⁴, also ohne praktische Bedeutung. Der Schlüssel liegt beim Mandanten; wechselt er, brechen die Korrelationen über den Wechselzeitpunkt hinweg, und das ist gewollt.

**Auskunft.** Eine Person hat Anspruch darauf zu erfahren, welche Ereignisse sie betreffen. Der eigene Auditauszug ([Kapitel 10](10-identitaet.md)) deckt Ereignisse, in denen die Person Akteur ist. Ereignisse, in denen sie Gegenstand einer Untersuchung ist, sind darin nicht enthalten; diese Trennung ist eine Entwurfsentscheidung mit erkennbarer Spannung, weil eine Auskunft, die den Untersuchungsteil auslässt, unvollständig ist. Der Entwurf löst das nicht technisch: er kennzeichnet untersuchungsbezogene Ereignisse als solche und legt die Entscheidung über deren Herausgabe als Vorgang mit Freigabeweg vor, statt sie in der Oberfläche vorwegzunehmen.

**Zugriff auf Anhänge ist selbst ein Ereignis.** Das Lesen von Anhängen durch einen Prüfer erzeugt ein Auditereignis je Abfrage, nicht je Zeile. **Rechnung Volumen:** Annahme 20 Prüfer mit je 10 Abfragen je Tag = 200 Ereignisse je Tag, gegenüber 2.000 Ereignissen je Tag aus dem Schreibbetrieb also 10 %. Eine Protokollierung je gelesener Zeile hätte bei 10.000 Zeilen je Abfrage das Vielfache des gesamten Auditstroms erzeugt und keine Frage beantwortet, die auf Abfrageebene unbeantwortet bliebe.

**Anforderungen**

- **R-19-43** — Nach der Löschung einer Person enthält kein Auditkern deren Namen, Anmeldenamen oder Adresse; die Kennung bleibt. Prüfbar: Löschung mit anschließender Musterprüfung des gesamten Auditbestands (INV-23).
- **R-19-44** — Anhänge der Klasse `betrieb_90d` werden spätestens 91 Tage nach ihrer Entstehung ohne Bedienereingriff gelöscht. Prüfbar: Bestandsprüfung nach simuliertem Zeitablauf (K-25).
- **R-19-45** — Ein pseudonymisierter Export enthält 0 unveränderte Akteurkennungen. Prüfbar: Inhaltsprüfung des Exports gegen die Kennungen des Mandanten.
- **R-19-46** — Jede Abfrage auf Auditanhänge erzeugt genau 1 Auditereignis mit Akteur, Filter und Trefferzahl. Prüfbar: Abfragetest mit Zählung der erzeugten Ereignisse (INV-23).

## 19.10 Notzugang in einen Mandanten

[Kapitel 10](10-identitaet.md) behandelt den Notzugang zur **Plattform** nach vollständiger Aussperrung. Dieser Abschnitt behandelt den anderen Fall: ein Betreiber oder Dienstleister muss in einem Mandanten handeln, für den ihm gerade kein wirksames Recht zusteht, weil der Schalter "Zugriff auf Anfrage" gesetzt ist, die Befristung abgelaufen ist oder niemand beim Kunden erreichbar ist.

```
Verfahren
  1. Auslösung durch eine Person mit Plattformrolle, Vier-Augen-pflichtig
  2. Pflichtfelder vor der Auslösung: Zielmandant, Grund als Freitext,
     vermuteter Umfang, erwartete Dauer. Ohne Grund keine Auslösung.
  3. Alarmierung vor der Wirksamkeit, Zielwert Zustellung <= 60 s an:
     alle Mandantenadministratoren des Zielmandanten, das hinterlegte
     Alarmziel des Mandanten, alle Traeger der Prueferrolle
  4. Wirksamkeit: befristete Sitzung, Zielwert 60 min, einmalige Verlaengerung
     nur mit erneuter Vier-Augen-Entscheidung
  5. Jeder Vorgang dieser Sitzung traegt die Markierung "unter Notzugang"

Ausgeschlossen bleibt unter Notzugang
  - Lesen von Geheimnissen im Klartext (INV-20 kennt keine Ausnahme)
  - Jede Aenderung am Auditstrom (INV-23 kennt keine Ausnahme)
  - Nicht rueckholbare Loeschungen: Speicherbereich vernichten, Postfach loeschen,
    Mandant aufloesen. Begruendung: keine Stoerung wird dadurch behoben.
  - Aenderung des Alarmziels des Zielmandanten waehrend der Sitzung

Nachbereitung, erzwungen
  - Frist 72 h: schriftliche Begruendung je Vorgang der Sitzung
  - Bis dahin dauerhaftes Banner im Ueberblick beider Mandanten
  - Nach Fristablauf: jede weitere Ausloesung gegen denselben Mandanten
    verlangt zusaetzlich die Zustimmung eines Mandantenadministrators
  - Bericht an den Mandanten als signierter Auszug nach 19.8
```

Zwei Entwurfsentscheidungen verdienen Begründung.

**Alarmierung blockiert nicht.** Ist kein Alarmziel erreichbar, läuft der Zugang trotzdem an, und das Ereignis trägt das Kennzeichen "Alarmierung nicht bestätigt". Die verworfene Alternative, den Zugang von einer bestätigten Zustellung abhängig zu machen, macht den Zustellkanal zur Verfügbarkeitsvoraussetzung der Störungsbehebung: ein Angreifer, der den Kanal stört, verhindert die Behebung. Der Preis der gewählten Variante ist ein Zeitfenster, in dem gehandelt wird, ohne dass jemand es bemerkt hat; er wird durch das nicht unterdrückbare Auditereignis und das dauerhafte Banner begrenzt, nicht beseitigt.

**Die Ausschlussliste ist kurz und hart.** Ein Notzugang, der alles darf, ist eine Hintertür mit Formular. Die Grenze verläuft entlang der Frage, ob eine Handlung rückholbar ist: eine Veröffentlichung zurückziehen ist rückholbar und deshalb erlaubt, einen Speicherbereich vernichten nicht und deshalb verboten. Die bewusst in Kauf genommene Schwäche: ein Fall, in dem eine sofortige unwiderrufliche Vernichtung rechtlich geboten wäre, ist unter Notzugang nicht behandelbar und verlangt die reguläre Freigabe mit einem Mandantenadministrator.

**Anforderungen**

- **R-19-47** — Eine Notzugangsauslösung ohne ausgefülltes Begründungsfeld erzeugt 0 Wirkungen. Prüfbar: Auslösungsversuch ohne Grund.
- **R-19-48** — Eine Notzugangsauslösung erzeugt vor Wirksamkeit mindestens 1 Zustellversuch je hinterlegtem Alarmziel und genau 1 nicht unterdrückbares Auditereignis, auch ohne Quorum. Prüfbar: Auslösung auf der Minderheitsseite einer Partition (INV-23, INV-04).
- **R-19-49** — Unter Notzugang sind Lesen von Geheimnissen, Änderungen am Auditstrom und nicht rückholbare Löschungen nicht ausführbar. Prüfbar: Ausführungsversuch je Klasse innerhalb einer Notzugangssitzung; 0 Wirkungen (INV-20, INV-23, INV-11).
- **R-19-50** — Jeder in einer Notzugangssitzung erzeugte Vorgang trägt die Markierung "unter Notzugang" und ist im Verlauf beider Mandanten gefiltert abrufbar. Prüfbar: Filterprüfung nach einer Testsitzung.
- **R-19-51** — Nach 72 h ohne abgeschlossene Nachbereitung verlangt jede weitere Auslösung gegen denselben Mandanten die Zustimmung eines Mandantenadministrators, und das Banner bleibt sichtbar. Prüfbar: Fristüberschreitungstest (INV-18).

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Ein Mandant ohne Isolationsstufe lässt sich nicht anlegen; alle vier Stufen sind setzbar | R-19-01 | Anlegetest über alle Stufen und ohne Stufe |
| Eine Stufenabsenkung ohne Bestätigung erzeugt 0 Wirkungen und 1 abgelehntes Auditereignis | R-19-02 | Absenkversuch ohne Bestätigung |
| Je Mandant ist die Zahl mitbetroffener Mandanten bei Knotenausfall sichtbar; für M3 ist sie 1 | R-19-03 | Darstellungstest über vier Mandanten |
| Ein M2-Mandant ohne nachweisbare Leitungstrennung wird dauerhaft als nicht vollständig wirksam gekennzeichnet | R-19-04 | Aufbau ohne VLAN-Kennung |
| Der Einrichtungsversuch eines M3-Mandanten bei erschöpfter Knotenmenge nennt die fehlende Zahl und führt nichts teilweise aus | R-19-05 | Einrichtungsversuch mit 2 freien Knoten |
| Ein Zugriff über die Mandantengrenze ohne gültige Freigabeverknüpfung liefert "kein Freigabeweg" und 0 Objektdaten | R-19-07 | Isolationstest über zwei Mandanten |
| Nach einem Fremdzugriff ist dieser in der Auditsicht des Zielmandanten mit Person, Zweck und Verknüpfung filterbar | R-19-08 | Zugriff mit anschließender Filterabfrage |
| Die mandantenübergreifende Übersicht enthält 0 fremde Objektnamen | R-19-09 | Inhaltsprüfung der Aggregationsantwort |
| Entzug einer Freigabeverknüpfung macht abgeleitete Zuweisungen in ≤ 30 s unwirksam | R-19-10 | Entzug mit Zugriffsversuch und Zeitmessung |
| 1.000 zufällige Sortierungen derselben Regelmenge liefern dasselbe Entscheidungsergebnis | R-19-11 | Permutationstest |
| Kein Ablehnungstext enthält den Namen eines Objekts ohne Leserecht | R-19-12 | Musterprüfung aller Ablehnungstexte |
| Jede Listenabfrage trägt das Regelprädikat; eine Abfrage ohne Prädikat wird abgelehnt | R-19-13 | Abfrageprotokollprüfung im Bau |
| Eine Regel mit Unterabfrage, Oder-Verknüpfung oder Funktionsaufruf wird beim Speichern abgelehnt | R-19-14 | Eingabetest mit drei unzulässigen Ausdrücken |
| Eine Ausschlussregel ohne Begründung lässt sich nicht speichern | R-19-15 | Speicherversuch ohne Begründung |
| Die Wirkungsvorschau einer Regeländerung mit 500 betroffenen Personen nennt Konflikte in p95 ≤ 2 s | R-19-17 | Vorschau mit Zeitmessung |
| Keine vordefinierte Rolle enthält ein Recht auf Klartextlesen eines Geheimnisses | R-19-18 | Rechtelistenprüfung; Treffer bricht den Bau |
| Die Rolle Prüfer/Auditor scheitert an jedem Schreibversuch und liest den vollständigen Auditstrom des Geltungsbereichs | R-19-19 | Schreibversuch je Aktionsklasse plus Leseprobe |
| Jede hart unvereinbare Rollenkombination wird abgelehnt und nennt beide Rollen | R-19-21 | Zuweisungsversuch je Tabellenzeile |
| Eine über Gruppenverschachtelung entstandene Unvereinbarkeit erscheint ≤ 24 h als Aufgabe | R-19-23 | Injektionstest mit Wartezeit |
| Über alle freigabepflichtigen Klassen erfolgen 0 automatische Freigaben nach Fristablauf | R-19-24 | Fristüberschreitungstest je Klasse |
| Eine Änderung zwischen Freigabe und Anwendung lässt die Freigabe verfallen und die Vorschau neu berechnen | R-19-25 | Änderungstest im Freigabefenster |
| Ein ausstehendes Zielsystem hält den Vorgang aus "Wirksam" heraus und benennt die Reste | R-19-26 | Fehlerinjektion in einen Konnektor |
| Jede der sechs zwingend freigabepflichtigen Klassen erzeugt ohne Freigabe 0 Wirkungen | R-19-27 | Ausführungsversuch je Klasse |
| Sperren von Gerät, Person und Zertifikat wirkt ohne Freigabe in ≤ 30 s | R-19-28 | Sperrtest mit Zeitmessung |
| Ein Freigabeweg mit potentiell leerem Freigeberkreis wird beim Festlegen abgelehnt | R-19-29 | Anlegeversuch mit einem Freigeber |
| Eine Vertretung wird nach Eskalationsfrist aktiv und kann den eigenen Antrag nicht freigeben | R-19-30 | Fristablauf- und Selbstfreigabetest |
| Der Kundenbereich liefert 0 Knotennamen und zeigt trotzdem den Redundanzzustand | R-19-31 | Inhaltsprüfung aller Antworten |
| Jede Handlung außerhalb der Selbstbedienungsliste erzeugt einen Vorgang statt einer Wirkung | R-19-32 | Ausführungsversuch je Beantragungszeile |
| Eine Antragsablehnung ohne Text lässt sich nicht abschließen | R-19-33 | Ablehnungsversuch ohne Text |
| Ein Auditereignis mit fehlendem Pflichtfeld wird nicht geschrieben | R-19-35 | Schemaprüfung aller Ereignistypen |
| Kein Auditkern enthält Freitext, Klartextnamen oder Adressen | R-19-36 | Musterprüfung; Treffer bricht den Bau |
| Nach Löschung eines Anhangs ist die Kette weiterhin vollständig gültig | R-19-37 | Löschung mit vollständiger Kettenprüfung |
| Die Kettenprüfung über 730.000 Ereignisse dauert ≤ 30 s | R-19-38 | Zeitmessung auf erzeugtem Jahresbestand |
| Ein gefilterter Export ist ohne Zugriff auf die Installation prüfbar und gibt 0 fremde Inhalte preis | R-19-39, R-19-41 | Prüflauf auf getrenntem Rechner |
| Ein injizierter Kettenbruch wird gemeldet, nicht repariert; der Folgestrom nennt die Bruchstelle | R-19-40 | Injektion mit Neustart |
| Nach Löschung einer Person enthält kein Auditkern deren Namen | R-19-43 | Musterprüfung des Gesamtbestands |
| Anhänge der Klasse `betrieb_90d` sind nach 91 Tagen ohne Eingriff verschwunden | R-19-44 | Bestandsprüfung nach Zeitablauf |
| Jede Anhangsabfrage erzeugt genau 1 Auditereignis mit Filter und Trefferzahl | R-19-46 | Abfragetest mit Ereigniszählung |
| Eine Notzugangsauslösung ohne Grund erzeugt 0 Wirkungen | R-19-47 | Auslösungsversuch ohne Grund |
| Eine Notzugangsauslösung auf der Minderheitsseite erzeugt 1 Auditereignis und Zustellversuche | R-19-48 | Partitionstest |
| Unter Notzugang scheitern Geheimnislesen, Auditänderung und unwiderrufliche Löschung | R-19-49 | Ausführungsversuch je Klasse |
| Nach 72 h ohne Nachbereitung verlangt die nächste Auslösung Kundenzustimmung | R-19-51 | Fristüberschreitungstest |

## Offene Punkte

1. **Schlüsselverwahrung außerhalb der Betreibersphäre ist ungelöst.** Der Hauptschlüssel eines Mandanten liegt im TPM eines Knotens, den der Betreiber besitzt. Ein Mandant, der vertraglich oder rechtlich zusichern muss, dass sein Betreiber technisch nicht lesen kann, bekommt diese Zusicherung im gegenwärtigen Entwurf nicht. Denkbar wäre eine Schlüsselverwahrung in einem Modul unter Kundenkontrolle, mit der Folge, dass der Mandant bei Nichterreichbarkeit dieses Moduls nicht startet. Ob diese Abhängigkeit vertretbar ist und ob sie überhaupt mit der Zusage "Dienste überleben die Kontrollebene" (INV-25) vereinbar ist, ist nicht entschieden.

2. **Die Anzahl der Aktionsklassen ist nicht festgelegt.** Die Rechtematrix in 19.4 benutzt rund dreißig Zeilen, die Rechnung in 19.3 nimmt zwölf Aktionsklassen für die Indexselektivität an. Beide Zahlen sind Entwurfsstand und widersprechen einander tendenziell: je feiner die Klassen, desto präziser die Rollen und desto schlechter die Selektivität des Index. Ein verbindlicher Katalog der Aktionsklassen mit Zuordnung jeder API-Operation fehlt und muss vor der ersten Implementierung der Rechteprüfung entstehen.

3. **Der Umgang mit untersuchungsbezogenen Auditereignissen ist rechtlich und ethisch offen.** Der Entwurf kennzeichnet sie und legt die Herausgabe als Vorgang vor, entscheidet aber nicht, wer über die Herausgabe befindet und nach welchem Maßstab. In Organisationen mit Mitbestimmung ist die Frage zusätzlich verhandlungspflichtig. Eine technische Vorwegnahme wäre falsch; eine fehlende Voreinstellung führt dazu, dass jede Installation die Frage neu beantwortet, und das ist ebenfalls unbefriedigend.

4. **Die Wirksamkeit der Funktionstrennung in kleinen Organisationen ist nicht herstellbar.** Die drei Auswege in 19.5 sind ehrlich benannt, aber keiner ersetzt eine zweite Person. Ob das Produkt für Installationen unterhalb einer bestimmten Personenzahl die Freigabepflicht generell abschalten sollte — mit entsprechend deutlicher Kennzeichnung — oder ob die Zeitverzögerung als Ersatz verbindlich vorgeschrieben wird, ist eine Produktentscheidung und noch nicht getroffen.

5. **Die Obergrenze für Mandanten der Stufe M1 auf einem Verwaltungsknoten beruht auf einer unbelegten Annahme.** Die Rechnung in 19.1 ergibt 22 Mandanten und hängt vollständig am angenommenen Arbeitsspeicherbedarf einer Resolver-Instanz von 60 MB. Wird stattdessen eine Instanz mit mehreren Sichten betrieben, widerspricht das der Festlegung "eine Instanz je Netzzone" im Kanon; wird die Annahme durch eine Messung widerlegt, ändert sich die Produktaussage für Dienstleister erheblich. Die Messung steht aus, und bis dahin ist die Zahl eine Modellgröße.

6. **Der Verdichtungssatz nach 12 Monaten ist inhaltlich nicht festgelegt.** Klar ist, dass Merkle-Wurzeln und Tagessiegel erhalten bleiben und Zählwerte entstehen. Welche Aggregationen ein Auditor tatsächlich braucht, um eine Prüfung über einen zurückliegenden Zeitraum zu führen, ist ohne Rückmeldung aus echten Prüfungen nicht bestimmbar. Eine zu schmale Verdichtung macht ältere Zeiträume wertlos, eine zu breite hebt die Aufbewahrungsbegrenzung faktisch auf.

7. **Der gefilterte Export löst die Prüfbarkeit, nicht die Vollständigkeitsfrage.** Ein Kunde kann prüfen, dass die gelieferten Ereignisse echt sind. Er kann nicht prüfen, dass ihm alle ihn betreffenden Ereignisse geliefert wurden, denn dazu müsste er die Gesamtmenge sehen. Ein Ansatz wäre, je Abschnitt die Anzahl der Ereignisse je Mandant in das Siegel aufzunehmen; das verrät die Aktivität anderer Mandanten in Zählform. Die Abwägung zwischen dieser Preisgabe und der Vollständigkeitszusage ist nicht getroffen.

8. **Die Fristen des Freigabewesens sind gesetzt, nicht abgeleitet.** F1 mit 72 h, Eskalation nach 24 h und F2 mit 24 h sind Zielwerte ohne empirische Grundlage. Zu kurze Fristen erzeugen Ablaufrauschen und Gewöhnung an abgelaufene Vorgänge; zu lange Fristen halten Änderungen unnötig auf. Welche Werte in der Praxis tragen, lässt sich erst aus Betriebsdaten bestimmen, und die Werte müssen je Aktionsklasse unterschiedlich sein können, was die Zahl der Einstellungen erhöht und mit der Entscheidungsbegrenzung aus INV-14 in Spannung steht.
