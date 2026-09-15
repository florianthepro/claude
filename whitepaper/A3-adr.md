# Anhang C: Architekturentscheidungen

## A3.1 Format, Status und Rückabwicklungsklassen

Dieser Anhang führt die Entscheidungen, die KANON.md, Abschnitt 4 tabellarisch festlegt, als einzeln versionierbare Einträge mit Begründungslast, Folgenbilanz und bezifferter Rückabwicklung. Er fügt dem Kanon keine Festlegung hinzu; wo eine Entscheidung über den Kanon hinausgeht, ist sie als Vorbehalt gekennzeichnet und nennt die Bedingung, unter der sie zu bestätigen oder zu verwerfen ist.

Jeder Eintrag führt acht Felder in fester Reihenfolge: Status, Kontext, Entscheidung, Begründung, Konsequenz positiv, Konsequenz negativ, Verworfen, Rückabwicklung. Ein Eintrag ohne eines dieser Felder ist unvollständig und wird von der Dokumentprüfung abgelehnt (R-A3-02).

| Status | Bedeutung |
|---|---|
| Angenommen | Bindend, deckungsgleich mit KANON.md, Abschnitt 4. |
| Angenommen mit Vorbehalt | Bindend, aber an eine benannte, noch nicht erbrachte Bedingung geknüpft. |
| Zurückgestellt | Richtung festgelegt, Aktivierung bewusst offen; die Austauschbarkeit ist die eigentliche Entscheidung. |
| Ersetzt durch ADR-nn | Historisch. Der Eintrag bleibt stehen, die Nummer wird nie neu vergeben. |

Die Rückabwicklungsklassen beziffern, was es kostet, eine Entscheidung nach der ersten Freigabe zurückzunehmen. Die Bänder sind Festlegungen, keine Messungen; ein Personenmonat ist mit 20 produktiven Personentagen angesetzt (Annahme).

| Klasse | Eingriffstiefe | Aufwandsband (Annahme) | Wirkung auf Bestandsinstallationen |
|---|---|---|---|
| **R0** | Schalter, Mitgliedschaft oder Richtlinie im laufenden Betrieb | ≤ 1 Personentag | keine; Umschaltung ist ein Vorgang |
| **R1** | Vorgang mit Wartungsfenster, ggf. Datenverschiebung | ≤ 20 Personentage | geplante Unterbrechung je Dienst |
| **R2** | Austausch eines Bausteins samt Migration und Vertragstests | 1 bis 12 Personenmonate | Migrationsvorgang je Installation |
| **R3** | Architekturbruch mit Wirkung auf Objektmodell oder Invarianten | > 12 Personenmonate | Bestandsinstallationen migrationspflichtig oder nicht übernehmbar |

Von den 32 Einträgen dieses Anhangs tragen 4 die Klasse R0, 8 die Klasse R1, 11 die Klasse R2 und 9 die Klasse R3. Der R3-Anteil beträgt 9 / 32 = 28,1 %. Das ist die Menge der Entscheidungen, deren Fehleinschätzung sich nicht durch Nacharbeit, sondern nur durch einen Neubau korrigieren lässt; sie sind deshalb diejenigen, für die vor der ersten Freigabe ein Beleg vorliegen muss (R-A3-06).

## A3.2 Zuordnung zu den Technologiefestlegungen

| Nr. | Titel (Kurzform) | Status | Kanonbezug (Abschnitt 4) | Klasse | Kapitel |
|---|---|---|---|---|---|
| 01 | Distributionsbasis statt Eigenbau | Angenommen | Basis | R3 | [06](06-basis-ubuntu.md) |
| 02 | Unveränderliches Wurzeldateisystem, zwei Abbildplätze | Angenommen | Abbild- und Bauverfahren | R2 | [21](21-betrieb-updates.md) |
| 03 | Rust für Kontrollebene, Knotenagent, Konnektoren | Angenommen | Sprachen | R3 | [08](08-kontrollebene.md) |
| 04 | Signierte Lieferkette mit Transparenzprotokoll | Angenommen | Signaturverfahren der Lieferkette | R1 | [20](20-sicherheit.md) |
| 05 | Gesicherte Zeit als Vorbedingung | Angenommen | Zeitsynchronisation | R1 | [20](20-sicherheit.md) |
| 06 | Eingebetteter Raft-Speicher, Änderungsprotokoll autoritativ | Angenommen | 1 Zustandsspeicher | R3 | [08](08-kontrollebene.md) |
| 07 | Stimmzahl 1, 3 oder 5 | Angenommen | 1a Stimmzahl | R0 | [16](16-cluster.md) |
| 08 | Zeugenrolle für zwei Standorte | Angenommen | 1b Zwei-Standort-Fall | R0 | [16](16-cluster.md) |
| 09 | Einfrieren statt Weiterbetrieb der Minderheitsseite | Angenommen | Quorumverlust und Notbetrieb | R3 | [16](16-cluster.md) |
| 10 | Notzugang ohne Herstellerfernzugang | Angenommen | Notzugang | R1 | [20](20-sicherheit.md) |
| 11 | Container mit erzeugten systemd-Einheiten | Angenommen | 2 Dienstausführung | R3 | [15](15-dienste-software.md) |
| 12 | Eigener Platzierungsdienst | Angenommen | 2a Platzierung | R2 | [16](16-cluster.md) |
| 13 | Drei Speicherklassen als einzige Speicherentscheidung | Angenommen | 3b Bedienung des Speichers | R1 | [17](17-speicher-backup.md) |
| 14 | Gestaffelte Replikationsverfahren nach Knotenzahl | Angenommen mit Vorbehalt | 3, 3a Speicherreplikation | R2 | [17](17-speicher-backup.md) |
| 15 | Zwei getrennte Sicherungsobjekte | Angenommen | Sicherung und Wiederherstellung | R1 | [17](17-speicher-backup.md) |
| 16 | Passwortauthentisierter Schlüsselaustausch zur Kopplung | Angenommen | 4 Kopplung | R2 | [16](16-cluster.md) |
| 17 | Signiertes Aufnahmetoken als Sonderweg | Angenommen mit Vorbehalt | 4a Kopplung, Sonderwege | R0 | [16](16-cluster.md) |
| 18 | Ein Konnektorvertrag mit generischem Manifesttreiber | Angenommen | 5 Konnektorvertrag | R3 | [09](09-konnektoren.md) |
| 19 | Prozessgrenze als Sandkasten statt Modul im Kern | Angenommen | 5a Konnektor-Sandkasten | R2 | [09](09-konnektoren.md) |
| 20 | Eingebetteter Identitätsanbieter als Protokollkopf | Angenommen mit Vorbehalt | 9 Identitätsanbieter | R2 | [10](10-identitaet.md) |
| 21 | SCIM-Server und -Client in der Kontrollebene | Angenommen | 9a SCIM | R1 | [10](10-identitaet.md) |
| 22 | Getrennter RADIUS-Dienst gegen die interne PKI | Angenommen | 9b RADIUS / EAP-TLS | R1 | [13](13-geraeteverwaltung.md) |
| 23 | Zweistufige PKI, Mandanten-Zwischen-CA ab M0 | Angenommen | PKI-Ort, 6a Mandanten-PKI | R2 | [11](11-pki.md) |
| 24 | Autoritativer Dienst und Resolver aus einer Hand | Angenommen | 8, 8a DNS | R2 | [12](12-dns-netzwerk.md) |
| 25 | Sichtattribut je Eintrag statt getrennter Zonenpflege | Angenommen | 8b Split-Horizon | R2 | [12](12-dns-netzwerk.md) |
| 26 | Firewall vollständig erzeugt, nicht editierbar | Angenommen | 7a L3/L4-Filter | R3 | [12](12-dns-netzwerk.md) |
| 27 | Eingangsproxy ausschließlich dynamisch konfiguriert | Angenommen | 7 Eingangsproxy | R2 | [12](12-dns-netzwerk.md) |
| 28 | WireGuard-Vollvermaschung als Knotennetz | Angenommen | Overlay-Netz | R2 | [16](16-cluster.md) |
| 29 | Audit außerhalb des replizierten Kernzustands | Angenommen | Protokollierung | R3 | [19](19-mandanten-rechte-audit.md) |
| 30 | Anwendungsdatenbank als gewöhnlicher Dienst | Angenommen | Datenbank für Anwendungsdaten | R1 | [15](15-dienste-software.md) |
| 31 | Keine Telemetrie an den Hersteller ohne Einwilligung | Angenommen | Telemetrie | R0 | [22](22-compliance.md) |
| 32 | Barrierefreiheit als Baubedingung | Angenommen | 12 Kennzahlen, INV-31 | R3 | [18](18-bedienkonzept.md) |

## A3.3 Grundlage und Knotenabbild

### ADR-01 — Distributionsbasis statt Eigenbau

**Status:** Angenommen.
**Kontext:** Ein Server-Betriebssystem, das Hardware ohne Nacharbeit unterstützen soll, braucht eine Treiber- und Sicherheitsversorgung, die fortlaufend fremdgepflegt wird. Eine eigene Paketbasis verlagert diese Last dauerhaft in das Produktteam.
**Entscheidung:** Ubuntu LTS als Basis in der im Kanon benannten Ziel- und Übergangsfassung; GA-Kernel der LTS-Serie als Standard, HWE-Kernel nur für Hardware, die ihn nachweislich benötigt; Treiber und Hardwareunterstützung werden unverändert übernommen.
**Begründung:** Der einzige belastbare Vorteil der Fremdbasis ist die fremdgepflegte Versorgung. Jede eigene Kernelvariante gibt ihn auf und erzeugt eine zweite Sicherheitsmeldungskette, die dieselbe Mannschaft verfolgen müsste, die die Kontrollebene baut.
**Konsequenz positiv:** Hardwarezertifizierungen, Firmwarepakete und Sicherheitsaktualisierungen der Basis gelten ohne eigene Arbeit; die Prüffläche des Produkts beschränkt sich auf die eigenen Komponenten.
**Konsequenz negativ:** Der Freigaberhythmus der Basis ist fremdbestimmt. Ein Wechsel der LTS-Serie ist ein erzwungener Umbau mit eigenem Zeitplan, und Voreinstellungsänderungen der Basis können Invarianten berühren, ohne dass das Produktteam sie beeinflusst.
**Verworfen:** Eigener Kernel — gibt die fremdgepflegte Treiberversorgung auf. Rollende Basis — macht die Zusage einer stabilen, reproduzierbaren Abbildkette unmöglich, weil sich die Grundlage zwischen zwei Abbildern ändert.
**Rückabwicklung:** Klasse R3. Ein Basiswechsel berührt Abbildbau, Paketauswahl, Kernelmerkmale und jeden Vertragstest gegen Systemdienste; Bestandsinstallationen wären nur über einen Neuaufbau nach ADR-15 migrierbar.

### ADR-02 — Unveränderliches Wurzeldateisystem mit zwei Abbildplätzen

**Status:** Angenommen.
**Kontext:** Ein Rücksprung nach fehlgeschlagener Aktualisierung muss genau dann funktionieren, wenn das System gestört ist. Verfahren, die vom Zustand des Dateisystems oder vom erfolgreichen Start des neuen Standes abhängen, sind in diesem Moment nicht verfügbar.
**Entscheidung:** Zwei vollständige, signierte Systemabbilder je Knoten mit dm-verity; Nutzdaten und Sollzustand liegen getrennt davon. Die Aktualisierung schreibt in die inaktive Hälfte und schaltet beim Neustart um; bleibt das Gesundheitssignal aus, fällt der Knoten selbsttätig zurück.
**Begründung:** Der Rückfall ist damit ein Neustart in eine unveränderte, integritätsgeprüfte Hälfte und durch die Startzeit des Knotens begrenzt (K-23: Rückfallzeit ≤ 5 min, Beobachtungsfenster 30 min).
**Konsequenz positiv:** Handänderungen am Wurzeldateisystem sind technisch unmöglich statt nur verboten; INV-02 wird dadurch nicht überwacht, sondern erzwungen.
**Konsequenz negativ:** Der doppelte Plattenbedarf ist fest: 2 × 8 GB = 16 GB je Knoten (K-19), bei 32 Knoten 512 GB reservierter Platz, davon die Hälfte dauerhaft ungenutzt. Jede Änderung an der Systemschicht erfordert einen vollständigen Abbildbau und einen Neustart, auch für eine Einzeldatei.
**Verworfen:** Paketbasiertes Update in place mit Momentaufnahme-Rücksprung — der Rücksprung hängt vom Dateisystem- und Startzustand ab und ist genau im Störfall unzuverlässig. Gleichzeitige Aktualisierung aller Knoten — gemeinsame Ausfallursache, die jede Verfügbarkeitsrechnung nach K-04 entwertet.
**Rückabwicklung:** Klasse R2. Der Wechsel auf ein schreibbares Wurzeldateisystem erfordert einen neuen Aktualisierungspfad, neue Rückfalllogik und die Neubewertung von INV-02; die Knoten selbst lassen sich über eine Neuinstallation überführen.

### ADR-03 — Rust für Kontrollebene, Knotenagent und Konnektoren

**Status:** Angenommen.
**Kontext:** Die Kontrollebene verarbeitet unauthentisiert erreichbare Eingaben am Kopplungsendpunkt, signierte Fremdmanifeste und Antworten fremder APIs. Der Eingangsproxy bleibt nach ADR-27 eine C++-Fläche.
**Entscheidung:** atrium-core, atrium-node, atriumctl, der generische Konnektortreiber und die mitgelieferten Konnektoren in Rust; die Atrium Console als Web-Oberfläche gegen die öffentliche API (INV-01).
**Begründung:** Speichersicherheit an Kontrollebene und Knotenagent ist die Voraussetzung dafür, dass die verbleibende, nicht speichersichere Fläche am Eingang überhaupt vertretbar ist. Sie ersetzt keine der übrigen Praktiken: Schemavalidierung am Rand, parametrisierte Abfragen im Lesemodell, keine Shell-Interpolation beim Erzeugen von Einheiten, laufzeitkonstanter Vergleich von Kopplungscodes und Token bleiben verpflichtend.
**Konsequenz positiv:** Die Klasse der Speicherfehler entfällt in den Komponenten, die Quorum, Schlüsselmaterial und Ausstellung halten; unsichere Teilbereiche sind im Quelltext markiert und einzeln prüfbar.
**Konsequenz negativ:** Der Bewerbermarkt ist enger als für verbreitete Serversprachen, und Anbindungen an C-Bibliotheken erzeugen genau die Übergangsstellen, die die Zusage lokal wieder aufheben.
**Verworfen:** C/C++ für Kernkomponenten — verlagert die Hauptfehlerklasse in die Komponente mit dem höchsten Schaden. Go für den Kern — speichersicher, aber ohne Kontrolle über Anhaltezeiten und Speicherbelegung an einer Stelle, an der Lease-Fristen (K-07: Lease 20 s) und ein Budget von 4 GB (K-19) einzuhalten sind.
**Rückabwicklung:** Klasse R3. Modellrechnung mit offengelegten Annahmen: atrium-core und atrium-node zusammen 120.000 Zeilen (Annahme), Neuschreiben bei 25 geprüften und getesteten Zeilen je Personentag (Annahme) ergibt 120.000 / 25 = 4.800 Personentage, bei 200 produktiven Tagen je Person 24 Personenjahre. Das ist ein Modell, keine Messung.

### ADR-04 — Signierte Lieferkette mit Transparenzprotokoll

**Status:** Angenommen.
**Kontext:** Ein Angreifer, der eine einzelne Kundeninstallation mit einem gezielt gebauten Abbild versorgt, wird durch Signaturprüfung allein nicht entdeckt, weil die Signatur gültig ist.
**Entscheidung:** Ed25519 (RFC 8032) für Signaturen an Systemabbildern, Katalogeinträgen, Konnektormanifesten, Sollzustandsexporten und Wiederherstellungspunkten; kanonische Serialisierung nach RFC 8785 vor der Signatur; Eintrag jeder Freigabe in ein anhängbares, hashverkettetes Transparenzprotokoll mit Zeitstempel nach RFC 3161; Stückliste je Abbild als SPDX und als CycloneDX; UEFI Secure Boot und Messung in das TPM 2.0.
**Begründung:** Erst das Transparenzprotokoll macht eine zurückdatierte oder gezielt ausgelieferte Freigabe erkennbar, weil eine gültige Signatur ohne Protokolleintrag auffällt. Die kanonische Serialisierung verhindert, dass zwei Darstellungen desselben Objekts unterschiedliche Signaturen tragen.
**Konsequenz positiv:** Jede ausgelieferte Einheit ist gegen eine öffentlich nachvollziehbare Freigabeliste prüfbar; beide Stücklistenformate bedienen unterschiedliche Abnehmerpflichten, unter anderem aus Verordnung (EU) 2024/2847.
**Konsequenz negativ:** Das Transparenzprotokoll ist ein zu betreibender, hochverfügbarer Dienst des Herstellers und eine neue Abhängigkeit im Ausrollpfad. Bricht er weg, ist entweder die Prüfung abgeschaltet oder die Ausrollung blockiert.
**Verworfen:** Unsignierte Abbilder und reine Prüfsummen — belegen Unverfälschtheit gegenüber einer Quelle, nicht die Rechtmäßigkeit der Quelle. Nur ein Stücklistenformat — deckt einen Teil der Abnehmer nicht ab.
**Rückabwicklung:** Klasse R1. Verfahren und Formate sind je Verwendungszweck versioniert; ein Wechsel ist ein Migrationsvorgang mit Parallelbetrieb beider Verfahrenskennungen, kein Umbau.

### ADR-05 — Gesicherte Zeit als Vorbedingung

**Status:** Angenommen.
**Kontext:** Raft-Leases, Zertifikatsgültigkeit, Einmalgeheimnisse und die Auditkette hängen sämtlich an der Uhr. Eine manipulierte Zeitquelle greift alle vier gleichzeitig an.
**Entscheidung:** chrony mit netzwerkgesicherter Zeitsynchronisation gegen mindestens zwei Quellen; der Ankerknoten ist interne Zeitquelle für Knoten und Geräte; ein Knoten mit unbekannter oder zu großer Abweichung führt nicht, stellt keine Zertifikate aus, schreibt keine Auditereignisse und schottet sich ab (INV-32).
**Begründung:** Die Reserve zwischen Leaseablauf (20 s) und Übernahmefreigabe (30 s) beträgt 10 s und liegt um den Faktor 10 s / 500 ms = 20 über der zugelassenen Uhrenabweichung (K-07, K-30). Diese Reserve ist nur belastbar, wenn die Abweichung belegt ist statt angenommen.
**Konsequenz positiv:** Die Selbstabschottung nach INV-06 wird nachweisbar statt wahrscheinlich; ein Knoten ohne Zeitgüte entfernt sich selbst aus der Menge der handelnden Knoten.
**Konsequenz negativ:** Ein Ausfall aller gesicherten Zeitquellen führt schrittweise zur Handlungsunfähigkeit der Kontrollebene, obwohl Rechner, Netz und Dienste funktionieren. Das ist ein bewusst in Kauf genommener Verfügbarkeitsverlust zugunsten der Nachweisbarkeit.
**Verworfen:** Ungesichertes NTP — die Zeitquelle ist dann selbst ein Angriffspunkt ohne Authentisierung. Vertrauen auf die Hardwareuhr — deckt den Drift nicht und erkennt einen gesetzten Sprung nicht.
**Rückabwicklung:** Klasse R1. Die Fristen sind Parameter; eine Lockerung ist ein Richtlinienwechsel mit Neubewertung von K-07 und INV-06.

## A3.4 Kontrollebene und Konsens

### ADR-06 — Eingebetteter Raft-Speicher mit Änderungsprotokoll als Wahrheitsquelle

**Status:** Angenommen.
**Kontext:** Eine Kontrollebene mit externer Datenbank braucht für deren Ausfallschutz selbst ein Quorum und schafft damit eine zirkuläre Abhängigkeit: die Kontrollebene braucht die Datenbank, deren Umschaltung ein Quorum braucht, das wiederum verwaltet werden muss.
**Entscheidung:** Ein hashverkettetes Änderungsprotokoll des Sollzustands in atrium-core ist autoritativ; daraus wird deterministisch ein lokales Lesemodell in einer eingebetteten SQL-Engine materialisiert. Ein-Knoten-Betrieb ist eine Raft-Gruppe mit einem Stimmmitglied und kein Sondermodus.
**Begründung:** Das Protokoll trägt die Ordnung, das Lesemodell trägt die Abfragen. Bei einer Schemaänderung wird das Lesemodell aus dem Protokoll neu gebaut statt migriert, was den Migrationspfad auf den Protokollinhalt reduziert und INV-24 erfüllbar macht.
**Konsequenz positiv:** Keine zweite Prozessgrenze, kein zweites Sicherungsverfahren, keine zweite Zugangsverwaltung; Listen werden aus dem indizierten Lesemodell bedient und halten K-18 (p95 ≤ 300 ms bei 10.000 Objekten).
**Konsequenz negativ:** Der Zustand muss klein bleiben. K-12 setzt 50 MB serialisiert und 2 GB je Momentaufnahme; alles, was diese Größe sprengt, ist aus dem Konsens herauszuhalten, was die Entscheidungen ADR-29 und die Telemetrieablage erzwingt. Auswertungen über große Datenmengen sind in dieser Ablage nicht möglich.
**Verworfen:** PostgreSQL mit Replikation — erzeugt die zirkuläre Abhängigkeit und ein zweites Sicherungsobjekt. etcd — ein weiterer Dauerprozess mit eigener Mitgliedschaftsverwaltung und eigener PKI, ohne dass die Ordnungszusage über das hinausginge, was ein eingebettetes Protokoll leistet.
**Rückabwicklung:** Klasse R3. Das Änderungsprotokoll ist die Wahrheitsquelle nach INV-02; ein Wechsel berührt Sollzustandsexport, Wiederherstellung, Schemamigration und jede Invariante, die auf Quorumpflicht verweist.

### ADR-07 — Stimmzahl 1, 3 oder 5

**Status:** Angenommen.
**Kontext:** Bediener erwarten, dass mehr Maschinen mehr Sicherheit bedeuten. Bei Mehrheitsverfahren gilt das für gerade Stimmzahlen nicht.
**Entscheidung:** Genau 1, 3 oder 5 Stimmknoten, niemals 2 oder 4. Wachstum 1 → 3 → 5 als Mitgliedschaftsänderung im laufenden Betrieb ohne Datenmigration; weitere Verwaltungsknoten sind Mitleser. Die API lehnt eine Änderung auf eine gerade Stimmzahl ab (INV-05).
**Begründung:** Vier Stimmknoten erreichen 0,999408 gegen 0,999702 bei drei (K-04), weil die Mehrheitsschwelle von 2 auf 3 steigt, ohne dass ein weiterer Ausfall toleriert wird. Die Ablehnung in der API ist der einzige Ort, an dem sich diese Eigenschaft ohne Bedienerwissen durchsetzen lässt.
**Konsequenz positiv:** Der Bediener kann keine Konfiguration erzeugen, die schlechter ist als die kleinere; die Konsole muss den Zusammenhang nicht erklären, sondern nur die Zweckwahl anbieten.
**Konsequenz negativ:** Ein vierter Verwaltungsknoten trägt Last und liest mit, stimmt aber nicht ab. Das ist erklärungsbedürftig, sobald ein Bediener die Knotenliste betrachtet, und die Konsole muss dafür den Begriff "Verwaltungsknoten (mitlesend)" tragen.
**Verworfen:** 2 oder 4 Stimmknoten — rechnerisch schlechter verfügbar bei höherem Ressourcenverbrauch.
**Rückabwicklung:** Klasse R0. Die Stimmzahl ist eine Mitgliedschaftsänderung im laufenden Betrieb; Mitleser werden zu Stimmknoten befördert und umgekehrt, ohne Datenverschiebung.

### ADR-08 — Zeugenrolle für den Zwei-Standort-Fall

**Status:** Angenommen.
**Kontext:** Zwei Standorte sind der häufigste Ausbau jenseits einer Maschine. Mit zwei Stimmen gibt es bei Leitungsausfall keine Mehrheit, und jede automatische Entscheidung wäre ein Split-Brain-Risiko.
**Entscheidung:** Rolle Zeuge: stimmberechtigtes Mitglied ohne Dienst- und Speicherlast, lauffähig auf kleinster Hardware oder an einem dritten Ort; derselbe Zeuge zählt als Quorumszeuge der synchronen Blockreplikation.
**Begründung:** Zwei Maschinen plus Zeuge ist der einzige Aufbau, der bei zwei Standorten automatischen Ausfallschutz liefert, ohne dem Bediener im Störfall eine Entscheidung aufzubürden, für die er im Moment der Störung keine Informationsgrundlage hat.
**Konsequenz positiv:** Synchrone Replikation wird bereits ab 2 Knoten plus Zeuge verfügbar, nicht erst ab 3 vollwertigen Knoten; der dritte Standort kostet nur eine kleine Maschine.
**Konsequenz negativ:** Der Zeuge ist verfügbarkeitswirksam, obwohl er nichts trägt. Rechnung mit Annahme A = 0,99 für die beiden Dienstknoten und A_z = 0,98 für den Zeugen auf kleiner Hardware: alle drei verfügbar 0,99 × 0,99 × 0,98 = 0,960498; genau zwei verfügbar 0,9801 × 0,02 + 0,99 × 0,01 × 0,98 + 0,01 × 0,99 × 0,98 = 0,019602 + 0,009702 + 0,009702 = 0,039006; Summe 0,999504. Gegenüber drei gleichwertigen Knoten (0,999702, K-04) sind das 0,000198 × 8.760 h = 1,73 h zusätzlicher Ausfallzeit je Jahr. Das ist ein Rechenergebnis aus benannten Annahmen, keine Messung.
**Verworfen:** Zwei Stimmknoten mit Handentscheidung — verlagert die Split-Brain-Entscheidung auf den Bediener im ungünstigsten Moment. Automatischer Weiterbetrieb der Minderheitsseite — erzeugt divergierende Sollzustände.
**Rückabwicklung:** Klasse R0. Der Zeuge wird zum vollwertigen Stimmknoten befördert oder durch einen solchen ersetzt; beides ist eine Mitgliedschaftsänderung.

### ADR-09 — Einfrieren statt Weiterbetrieb der Minderheitsseite

**Status:** Angenommen.
**Kontext:** Bei Netzpartition ist die Frage nicht, ob Dienste weiterlaufen, sondern ob zwei Seiten gleichzeitig den Sollzustand ändern dürfen.
**Entscheidung:** Ohne Quorum ist der Sollzustand eingefroren: Knoten halten und starten ihre Dienste weiter, löschen und verschieben aber nichts (INV-04, INV-25). Fencing erfolgt per Lease (INV-06). Der Notbetrieb mit einem Knoten ist eine ausdrückliche, mit dem Wiederherstellungscode geschützte, auditierte Handlung mit erzwungener Wartezeit und Klartextwarnung.
**Begründung:** Das Zusammenführen divergierender Sollzustände verwirft entweder Daten oder verlangt Bedienerentscheidungen im Störfall. Hardware-Fencing ist bei Netzpartition definitionsgemäß nicht erreichbar und damit keine Alternative zum Lease.
**Konsequenz positiv:** Ein Datenverlust durch doppelte Führung ist ausgeschlossen; laufende Dienste bleiben erreichbar, weil die Datenebene nicht am Quorum hängt.
**Konsequenz negativ:** Auf der Minderheitsseite ist keine Änderung möglich, auch keine dringende. Ein Kunde mit ausgefallener Leitung kann kein Konto sperren, obwohl der Anlass die Sperrung gerade dringend macht. Das ist ein bewusst hingenommener Fall, der in der Konsole benannt wird und über den Notbetrieb nur unter hoher Hürde umgangen werden kann.
**Verworfen:** Automatischer Weiterbetrieb der Minderheitsseite — erzeugt zwei Wahrheiten. Hardware-Fencing als Pflicht — nicht erreichbar in genau dem Fall, für den es gedacht ist.
**Rückabwicklung:** Klasse R3. Ein Weiterbetrieb der Minderheitsseite verlangt Konfliktauflösung im Objektmodell und bricht INV-02 und INV-04.

### ADR-10 — Notzugang ohne Herstellerfernzugang

**Status:** Angenommen.
**Kontext:** Die Aussperrung eines Kunden aus seinem eigenen System ist der teuerste Supportfall. Der naheliegende Ausweg, ein Herstellerzugang, ist ein dauerhafter Generalschlüssel.
**Entscheidung:** Ein bei der Erstinstallation erzeugter Wiederherstellungscode mit 256 bit Entropie zum Ausdrucken, im System nur als Verifikationswert gespeichert; zusätzlich Notzugang an der physischen oder Fernkonsole eines Knotens mit Neustart in einen Wiederherstellungsmodus, zeitlich begrenzt und mit nicht unterdrückbarem Auditereignis. Kein Fernzugang des Herstellers, keine Hintertür.
**Begründung:** Der physisch gebundene Weg begrenzt den Angriff auf jemanden, der Zugang zur Maschine oder zur Fernkonsole hat, und macht ihn in jedem Fall sichtbar. Der Verifikationswert wird laufzeitkonstant verglichen, und die Fehlermeldung unterscheidet nicht zwischen falschem Code und fehlender Berechtigung.
**Konsequenz positiv:** Es existiert kein Generalschlüssel, der bei einem Einbruch beim Hersteller alle Kunden betrifft.
**Konsequenz negativ:** Ein verlorener Wiederherstellungscode in Verbindung mit fehlendem physischem Zugang ist endgültig. Der Hersteller kann nicht helfen, und das ist die Zusage, nicht ein Mangel.
**Verworfen:** Kein Notzugang — macht jeden Bedienfehler zum Totalverlust. Fernnotzugang durch den Hersteller — dauerhaftes Generalschlüsselrisiko und ein Ziel, dessen Wert mit der Kundenzahl wächst.
**Rückabwicklung:** Klasse R1. Zusätzliche Wege lassen sich ergänzen; das Fehlen eines Herstellerzugangs ist jedoch eine Zusage, deren Rücknahme ein Vertrauensbruch und kein technischer Vorgang ist.

## A3.5 Dienstausführung, Platzierung und Speicher

### ADR-11 — Container mit erzeugten systemd-Einheiten statt Orchestrierungsplattform

**Status:** Angenommen.
**Kontext:** Hunderte Fremdprodukte brauchen ein signiertes, verbreitetes Verteilformat. Eine vollständige Orchestrierungsplattform liefert das mit, bringt aber ein zweites Quorum, eine zweite PKI, ein zweites Netzmodell und einen zweiten Namensdienst mit.
**Entscheidung:** Podman mit Quadlet-Einheiten unter systemd, ausschließlich von atrium-node aus dem Sollzustand erzeugt, dazu ein eigener Platzierungsdienst in atrium-core. Rootless, wo das Produkt es zulässt; je Dienst eigene Benutzerkennung, eigene Netzzone, eigener Speicherbereich. OCI-Abbilder sind das Verteilformat.
**Begründung:** Eine Orchestrierungsplattform lässt sich nicht verstecken, sondern nur verdecken; ihre Begriffe schlagen bei jedem Fehler in die Oberfläche durch und verletzen INV-16. Modellrechnung zum Ressourcenanspruch: eine mitgelieferte Plattform-Kontrollebene mit vier Dauerprozessen zu je 375 MB (Annahme) belegt 1,5 GB und damit 37,5 % des Budgets von 4 GB nach K-19, bevor ein Dienst läuft. Das ist ein Modell, keine Messung.
**Konsequenz positiv:** Dienste überleben den Ausfall der Kontrollebene und starten lokal neu (INV-25), weil systemd sie hält; Dauerprozesse je Arbeitsknoten bleiben bei ≤ 3 (K-20). Die erzeugten Einheiten werden ohne Shell-Interpolation aus typisierten Feldern geschrieben.
**Konsequenz negativ:** Alles, was eine Plattform mitbringt, ist selbst zu bauen: Platzierung, Räumen, Antiaffinität, Aktualisierungsreihenfolge, Gesundheitsbewertung. Fremdprodukte, die ausschließlich als Plattformpaket ausgeliefert werden, sind nicht ohne Übersetzungsarbeit aufnehmbar.
**Verworfen:** Verstecktes k3s — zweites Quorum, zweite PKI, zweiter Namensdienst und durchschlagendes Fremdvokabular. systemd-nspawn — kein signiertes Abbildverteilformat für hunderte Fremdprodukte.
**Rückabwicklung:** Klasse R3. Die Entscheidung prägt Objektmodell, Platzierung, Netzzonen und den Katalogeintrag; ein Wechsel ist ein Neubau der gesamten Ausführungsschicht.

### ADR-12 — Eigener Platzierungsdienst mit erklärbarem Ergebnis

**Status:** Angenommen.
**Kontext:** Ein Bediener, der fragt, warum ein Dienst auf einer bestimmten Maschine liegt, braucht eine Antwort in einem Satz. Bewertungsverfahren mit Vorbelegung und Verdrängung geben diese Antwort nicht.
**Entscheidung:** Deterministische, gierige Bewertung mit erklärbarem Gleichstandsbruch; jede Platzierung trägt eine anzeigbare Begründung; Antiaffinität entlang Fehlerzonen; Räumen ist eine sichtbare eigene Handlung.
**Begründung:** Der Skalenbereich ist klein genug, dass Optimalität kein Ziel sein muss. Rechnung: 32 Knoten × 500 Dienstinstanzen = 16.000 Bewertungspaare; bei 8 Kriterien je Paar sind das 128.000 Kriterienauswertungen, bei angenommenen 100 ns je Auswertung 12,8 ms und damit innerhalb des Zielwerts von 50 ms (K-21). Die Annahme von 100 ns ist nicht gemessen.
**Konsequenz positiv:** Jede Platzierung ist reproduzierbar und begründbar; gleiche Eingaben erzeugen dieselbe Zuordnung, was die Wirkungsvorschau nach INV-08 erst belastbar macht.
**Konsequenz negativ:** Das Ergebnis ist nicht optimal. Bei hoher Auslastung kann eine gierige Zuordnung eine Platzierung ablehnen, die ein umordnendes Verfahren gefunden hätte; der Bediener sieht dann eine Ablehnung statt einer Verdrängung.
**Verworfen:** Scheduler mit Vorbelegung und Verdrängung — verdrängt Dienste ohne Bedienerabsicht und macht die Begründung mehrstufig.
**Rückabwicklung:** Klasse R2. Ein anderes Verfahren ist austauschbar, solange es die Begründungspflicht erfüllt; die Schnittstelle zum Reconciler bleibt gleich.

### ADR-13 — Drei Speicherklassen als einzige Speicherentscheidung

**Status:** Angenommen.
**Kontext:** Replikationstechnik ist die klassische Stelle, an der Serverprodukte Fachwissen verlangen. Die Drei-Entscheidungs-Regel (INV-14) lässt dafür keinen Platz.
**Entscheidung:** Genau ein Feld je Dienst mit den Werten Lokal, Gespiegelt, Synchron gespiegelt. Welche Technik das erfüllt, leitet der Kern aus der Knotenzahl ab; das daraus folgende RPO wird am Dienst angezeigt (K-08 bis K-10).
**Begründung:** Eine Technikauswahl bricht INV-14. Eine stille Ableitung ohne Anzeige des RPO lässt die Oberfläche RPO 0 versprechen, wo asynchron repliziert wird, und wäre eine Falschaussage.
**Konsequenz positiv:** Der Bediener entscheidet über die Eigenschaft, die er beurteilen kann, und sieht die Folge als Zahl; ein Ausbau von 1 auf 3 Knoten ändert die erreichbare Klasse, ohne dass er umlernt.
**Konsequenz negativ:** Wer eine bestimmte Technik aus Betriebsgründen braucht, kann sie nicht wählen. Für diesen Fall existiert nur der Umweg über Knotenklassen und Ausschlüsse, was die Zusage der Einfachheit an dieser Stelle einschränkt.
**Verworfen:** Auswahl der Replikationstechnik durch den Bediener — bricht INV-14. Stille Ableitung aus dem Katalogeintrag ohne Anzeige — erzeugt eine unzutreffende RPO-Zusage.
**Rückabwicklung:** Klasse R1. Eine zusätzliche Klasse ist additiv; die Änderung der Bedeutung einer bestehenden Klasse ist ein Vorgang mit Datenverschiebung je betroffenem Speicherbereich.

### ADR-14 — Gestaffelte Replikationsverfahren nach Knotenzahl

**Status:** Angenommen mit Vorbehalt. Der Vorbehalt betrifft die Ceph-Stufe und entfällt, sobald das Wiederherstellungsverhalten auf der Zielhardware gemessen und in der Wiederherstellungsübung nach K-24 gegen K-08 geprüft ist.
**Kontext:** Kein einzelnes Verfahren erfüllt gleichzeitig Prüfsummen auf Dateisystemebene, synchrone Replikation und Betrieb auf einer Maschine.
**Entscheidung:** ZFS in jeder Ausbaustufe als lokales Dateisystem; 1 Knoten lokale Momentaufnahmen plus externe Auslagerung; 2 Knoten oder 2 plus Zeuge asynchrones ZFS send/recv; ab 3 Knoten zusätzlich DRBD/LINSTOR für als synchron markierte Speicherbereiche (3 Replikate oder 2 plus Zeuge); ab 8 Knoten mit mindestens 3 dedizierten Speicherknoten optional Ceph auf eigenem Speichernetz.
**Begründung:** Unterhalb von acht Knoten sättigt eine Wiederherstellungswelle eines verteilten Objektspeichers die verbleibenden Knoten, die gleichzeitig die Dienste tragen. DRBD ohne Prüfsummendateisystem und ZFS send/recv ohne synchrone Replikation erbringen je allein eine geforderte Eigenschaft nicht. Die Schwelle 3 folgt aus dem Mehrheitsbedarf des DRBD-Quorums und ist nicht willkürlich gesetzt.
**Konsequenz positiv:** Jede Ausbaustufe hat ein Verfahren, das zur Knotenzahl passt; der Übergang ist ein Vorgang mit Datenverschiebung, kein Produktwechsel.
**Konsequenz negativ:** Drei Replikationsverfahren bedeuten drei Fehlerbilder, drei Wiederherstellungspfade und drei Sätze von Vertragstests. Das ist die größte Wartungslast im Speicherbereich und die Stelle, an der die Zusage "radikal einfach" intern am teuersten erkauft ist.
**Verworfen:** Ceph als Standard — sättigt kleine Installationen im Wiederherstellungsfall. Nur DRBD — keine Prüfsummen und keine Momentaufnahmen auf Dateisystemebene. Nur ZFS send/recv — kein RPO 0.
**Rückabwicklung:** Klasse R2. Der Wegfall einer Stufe ist eine Migration je Speicherbereich; die Bedienoberfläche bleibt unverändert, weil sie nur die drei Klassen nach ADR-13 kennt.

### ADR-15 — Zwei getrennte Sicherungsobjekte

**Status:** Angenommen.
**Kontext:** Konfiguration ist klein, häufig zu sichern und lange aufzubewahren. Nutzdaten sind groß, langsam und anders aufzubewahren.
**Entscheidung:** Ein signierter, versionierter, maschinen- und menschenlesbarer Sollzustandsexport vor jeder Änderungstransaktion und periodisch, lokal und extern abgelegt; getrennt davon eine Nutzdatensicherung je Speicherbereich nach Sicherungsplan. Die Wiederherstellung einer Gesamtinstallation ist: Ankerknoten installieren, Export einspielen, Reconciler konvergieren lassen, Nutzdaten je Speicherbereich zurückspielen.
**Begründung:** Ein gemeinsames Objekt erzwingt den schlechtesten gemeinsamen Nenner aus beiden Anforderungsprofilen. Die Trennung macht zudem sichtbar, dass der Export keine Nutzdaten enthält und Geheimnisse nur als Referenz führt (INV-20); die Konsole benennt das ausdrücklich.
**Konsequenz positiv:** Der Export ist klein genug für häufige Erzeugung (K-12: ≤ 50 MB, Erzeugung und Signatur ≤ 5 s) und unabhängig vom internen Format der Kontrollebene lesbar.
**Konsequenz negativ:** Eine Wiederherstellung besteht aus zwei Vorgängen mit zwei Zeitpunkten. Fällt der Export zeitlich hinter die Nutzdaten zurück, verweist der Sollzustand auf Objekte in einem Zustand, den die Nutzdaten nicht abbilden; die benannten Reste nach INV-12 machen das sichtbar, lösen es aber nicht.
**Verworfen:** Ein gemeinsames Sicherungsobjekt — vereint die ungünstigsten Eigenschaften beider Datenarten. Abbildsicherung der Knoten — scheitert an geänderter Hardware und erlaubt keine selektive Wiederherstellung.
**Rückabwicklung:** Klasse R1. Beide Objekte bleiben getrennt erzeugbar; eine Zusammenlegung wäre ein neues Format, kein Umbau der Ablage.

## A3.6 Kopplung und Konnektoren

### ADR-16 — Passwortauthentisierter Schlüsselaustausch für die Kopplung

**Status:** Angenommen.
**Kontext:** Ein Kopplungscode muss kurz genug sein, um vorgelesen und abgetippt zu werden, und darf trotzdem einem Mitschnitt standhalten.
**Entscheidung:** SPAKE2 (RFC 9382) aus einem 12-stelligen Code in Crockford-Base32, davon 10 Zufallszeichen und 2 Prüfzeichen, gebunden an den TLS-Exporter der Verbindung; danach Knotenzertifikat aus der Ausgabe-CA, TPM-gebunden wo vorhanden, ab da ausschließlich gegenseitig authentisiertes TLS. Der Kopplungsendpunkt ist nur im Wartemodus offen (Port 8403/tcp).
**Begründung:** Nur ein passwortauthentisiertes Verfahren macht ein kurzes Geheimnis sicher, weil ein Mitschnitt kein Offline-Raten erlaubt und jeder Versuch einen zählbaren Online-Lauf kostet. K-14 beziffert die Erfolgswahrscheinlichkeit je Code mit 4,44 × 10⁻¹⁵ und über ein Jahr mit ≤ 2 × 10⁻¹⁰.
**Konsequenz positiv:** Die Kopplung braucht keine vorverteilten Schlüssel, keine Zertifikatsvorbereitung und keinen zweiten Kanal; sie besteht aus zwei Entscheidungen (K-03: Knoten aufnehmen 2).
**Konsequenz negativ:** Das Verfahren setzt voraus, dass der Code auf einem Weg zum Bediener kommt, den der Angreifer nicht sieht. Genau das ist bei einer anbieterseitig mitlesbaren Fernkonsole nicht gegeben, was ADR-17 erzwingt.
**Verworfen:** QR-Code mit öffentlichem Schlüssel als eigenständiges Verfahren — ein zweites Verfahren mit eigener Angriffsfläche, obwohl der QR-Code als reine Eingabeerleichterung für denselben Code genügt. Signiertes Aufnahmetoken als Primärverfahren — verlagert die Sicherheit vollständig auf die Geheimhaltung des Auslieferungskanals.
**Rückabwicklung:** Klasse R2. Ein Verfahrenswechsel berührt Kopplungsendpunkt, Codeformat, Konsolenablauf und die Rechnung K-14, nicht aber das Objektmodell.

### ADR-17 — Signiertes Aufnahmetoken als gekennzeichneter Sonderweg

**Status:** Angenommen mit Vorbehalt. Der Vorbehalt entfällt, sobald ein Kriterium festgelegt ist, ab welchem Anteil von Tokenkopplungen an allen Kopplungen die Zusage aus ADR-16 praktisch nicht mehr trägt; geprüft wird der Anteil je Mandant im Auditbericht.
**Kontext:** Unbeaufsichtigte Massenausrollung über Netzstart oder Startkonfiguration und gemietete Server mit mitlesbarer Fernkonsole lassen sich mit einem vorzulesenden Code nicht bedienen.
**Entscheidung:** Ein einmalig verwendbares, kurzlebiges Aufnahmetoken mit CA-Fingerabdruck-Pinning, ausgeliefert über einen vom Kunden ausdrücklich als vertrauenswürdig erklärten Kanal, in der Konsole als schwächeres Verfahren gekennzeichnet und auditiert. Der QR-Code bleibt reine Eingabeerleichterung für denselben Code und ist kein zweites Verfahren.
**Begründung:** Ohne automatisierbaren Weg wird die Massenausrollung improvisiert, und improvisierte Verfahren sind weder auditiert noch begrenzt. Die Kennzeichnung ist der Preis dafür, dass die Schwäche sichtbar bleibt statt in der Zusage unterzugehen.
**Konsequenz positiv:** Ein Bediener kann 50 Knoten ohne 50 Konsolenblicke aufnehmen; jede Tokenkopplung erzeugt ein unterscheidbares Auditereignis.
**Konsequenz negativ:** Die Sicherheitszusage sinkt genau im praktisch häufigsten Mietserverfall auf die Güte des Auslieferungskanals. Das ist eine bewusste Absenkung, keine gleichwertige Alternative, und dieser Anhang benennt sie als solche.
**Verworfen:** Kein Sonderweg — erzeugt improvisierte Verfahren außerhalb jeder Kontrolle.
**Rückabwicklung:** Klasse R0. Der Sonderweg ist je Mandant abschaltbar; die Abschaltung ist eine Richtlinie mit sofortiger Wirkung.

### ADR-18 — Ein Konnektorvertrag mit generischem Manifesttreiber

**Status:** Angenommen.
**Kontext:** Mehrere hundert Fremdsysteme sind anzubinden. Handgeschriebener Code je System skaliert nicht, ein rein deklarativer Ansatz deckt eigenwillige Systeme nicht ab.
**Entscheidung:** Genau ein Vertrag: ein Konnektor ist ein eigener Prozess, spricht gRPC über einen Unix-Socket und hat keinen Netzzugang zum Kern. Operationen sind `describe`, `observe`, `plan`, `apply` mit Idempotenzschlüssel und `healthcheck`. Der generische deklarative Treiber ist selbst einer dieser Prozesse und bedient über Manifeste beliebig viele Fremdsysteme. Konnektoren sind aktivierungsgesteuert mit Leerlaufabschaltung nach 10 Minuten.
**Begründung:** Zwei Vertragsstufen erzeugen zwei Lernkurven und zwei Testverfahren, obwohl das Manifest nur eine Implementierung hinter demselben Vertrag ist. Modellrechnung der Zusatzlast eines zweiten Vertrags auf Basis von K-22: bei 1.000 Konnektoren und angenommenen 0,05 Personentagen zusätzlicher Pflege je Konnektor und Jahr sind das 50 Personentage, also 0,25 Vollzeitäquivalente oder 0,25 / 2,56 = 9,8 % Mehrlast gegenüber der Grundlast von 2,56 Vollzeitäquivalenten. Das ist ein Modell, keine Messung.
**Konsequenz positiv:** Ein Vertragstest gilt für alle Konnektoren; die Wirkungsvorschau nach INV-08 und die Feldeigentumsprüfung nach INV-13 sind einmal zu bauen. K-22 setzt den Manifestanteil auf ≥ 90 %.
**Konsequenz negativ:** Der eine Vertrag muss auch für das ungünstigste Fremdsystem ausreichen. Systeme ohne Beobachtungsmöglichkeit erzwingen entweder eine Vertragserweiterung für alle oder eine Notlösung im Konnektor, die im Vertrag nicht sichtbar ist.
**Verworfen:** WASM-Modul im Kern — teilt dessen Fehlerdomäne und Netzrechte und zwingt dazu, TLS, OAuth, LDAP und SQL als Wirtsfunktionen nachzubauen. Zwei getrennte Vertragsstufen — doppelte Lern- und Testlast ohne zusätzliche Fähigkeit.
**Rückabwicklung:** Klasse R3. Der Vertrag ist die Schnittstelle, an der jeder Konnektor und jede Konnektorbindung hängt; eine Änderung der Operationsmenge ist eine Hauptversion mit Migration jedes Konnektors.

### ADR-19 — Prozessgrenze als Sandkasten statt Modul im Kern

**Status:** Angenommen.
**Kontext:** Konnektorcode verarbeitet Antworten fremder Systeme und hält deren Zugangsdaten. Er ist damit der Teil des Systems mit der höchsten Wahrscheinlichkeit für fremdverursachte Fehler.
**Entscheidung:** Je Mandant und Bindung ein eigener Prozess mit eigenem Systembenutzer, systemd-Härtung (`NoNewPrivileges`, `ProtectSystem=strict`, `PrivateTmp`, seccomp), eigenem Netznamensraum und einer aus der Bindung erzeugten Ausgangs-Positivliste; Geheimnisse nur als kurzlebige, auftragsgebundene Referenz (INV-20, INV-21).
**Begründung:** Ein gemeinsamer Prozess über Mandanten hinweg ist eine Vermischungsgefahr, die sich weder prüfen noch nachweisen lässt. Die Positivliste macht INV-21 zur Eigenschaft des Namensraums statt zu einer Zusage im Code.
**Konsequenz positiv:** Ein übernommener Konnektor erreicht weder ein anderes Fremdsystem noch die API der Kontrollebene über das Netz; sein Schaden ist auf eine Bindung eines Mandanten begrenzt.
**Konsequenz negativ:** Die Prozesszahl steigt mit Mandanten und Bindungen. Rechnung nach K-20: 20 Mandanten × 8 aktive Bindungen = 160 Prozesse zu je 30 MB wären 4,8 GB, weshalb die Obergrenze von 32 gleichzeitig aktiven Konnektorprozessen je Knoten und die Leerlaufabschaltung nicht optional, sondern tragend sind. Bei Lastspitzen werden Aufrufe dadurch eingereiht statt parallel ausgeführt, was K-15 belastet.
**Verworfen:** Gemeinsamer Konnektorprozess über Mandanten hinweg — nicht nachweisbar trennbar. Modul im Kern — teilt Fehlerdomäne und Netzrechte des Kerns.
**Rückabwicklung:** Klasse R2. Die Prozessgrenze ist Teil der Sicherheitszusage gegenüber Mandanten; ihre Aufhebung erzwingt eine neue Isolationsargumentation für M0 bis M3.

## A3.7 Identität und PKI

### ADR-20 — Eingebetteter Identitätsanbieter als Protokollkopf

**Status:** Angenommen mit Vorbehalt. Der Vorbehalt entfällt, sobald die LDAP-Schemaabdeckung gegen die Menge der Katalogeinträge belegt ist, die LDAP zwingend verlangen; geprüft wird das im Vertragstest je betroffenem Katalogeintrag vor dessen Freigabe.
**Kontext:** Fremdprodukte sprechen OIDC, OAuth 2.0 (RFC 6749) und LDAPv3 (RFC 4511). Ein eigener Protokollstapel ist mehrjährig und ohne Interoperabilitätsnachweis nicht belastbar.
**Entscheidung:** Wahrheitsquelle bleibt der Objektgraph in atrium-core. Kanidm wird als Protokollkopf für OIDC/OAuth2, LDAP über TLS und Passkey/MFA eingebettet; die eigene Verwaltungsoberfläche ist abgeschaltet, Schreibzugriff erfolgt ausschließlich durch den Reconciler. Ein ständiger Abgleich zeigt Abweichungen an, und der Kern gewinnt jede Abweichung.
**Begründung:** Der Protokollkopf erbringt nur Protokolle, keine Wahrheit. Zwei Replikationssysteme können divergieren, und nur ein sichtbarer Abgleich macht das erkennbar (INV-02).
**Konsequenz positiv:** Interoperabilität entsteht aus einer gepflegten Implementierung statt aus Eigenbau; die Verwaltungsoberfläche des Fremdprodukts erscheint nie und kann keine zweite Bedienwelt eröffnen (INV-30).
**Konsequenz negativ:** Der Protokollkopf hält einen eigenen Speicher, der mit dem Kern auseinanderlaufen kann, und seine Fähigkeitsgrenzen sind fremdbestimmt. Ob seine LDAP-Schemaunterstützung für die Fremdprodukte des Katalogs ausreicht, ist an dieser Stelle nicht belegt; der Anhang beschreibt deshalb die Anforderung und nennt keine Implementierung, die sie erfüllt.
**Verworfen:** Eingebettetes Keycloak — bringt eine zweite vollwertige Datenbank mit Schemamigration und ein eigenes Objektmodell mit, dessen Begriffe bei jedem Fehler durchschlagen, und ist selbst kein LDAP-Server. Vollständige Eigenentwicklung — mehrjährig ohne Interoperabilitätsnachweis.
**Rückabwicklung:** Klasse R2. Da der Kern die Wahrheitsquelle bleibt, ist ein Austausch des Kopfes ein Neuaufbau der abgeleiteten Konten aus dem Sollzustand; Anmeldedaten von Passkeys sind dabei nicht übertragbar und müssen neu registriert werden.

### ADR-21 — SCIM-Server und -Client in der Kontrollebene

**Status:** Angenommen.
**Kontext:** Fremdsysteme erwarten SCIM (RFC 7643, RFC 7644) zur Nutzerversorgung. Die Frage ist, ob der Protokollkopf oder der Kern es erbringt.
**Entscheidung:** atrium-core erbringt SCIM-Server und SCIM-Client direkt; der Server wird über den Eingang auf 443 veröffentlicht und hört knotenlokal auf 8405/tcp mit OAuth-2.0-Bearer-Token eines Dienstkontos.
**Begründung:** Der Kern ist ohnehin die Wahrheitsquelle, und SCIM ist ein REST/JSON-Protokoll ohne eigenen Zustand. Es über den Protokollkopf zu führen, hieße, Schreibvorgänge an der Wahrheitsquelle vorbeizuleiten und damit INV-03 zu brechen.
**Konsequenz positiv:** Ein eingehender SCIM-Schreibvorgang wird zu einem Vorgang mit Wirkungsvorschau und Auditereignis wie jede andere Änderung; eingehende Objekte werden am Rand gegen das Schema aus [Anhang A](A1-schemata.md) validiert.
**Konsequenz negativ:** Der Kern trägt damit eine eingehende, öffentlich erreichbare Protokolloberfläche mehr. Deren Eingaben stammen von Fremdsystemen, deren Fehlerverhalten nicht kontrollierbar ist, und jede Abweichung im SCIM-Verständnis eines Partners landet direkt an der Wahrheitsquelle.
**Verworfen:** SCIM über den Protokollkopf — erzeugt einen Schreibweg am Reconciler vorbei.
**Rückabwicklung:** Klasse R1. Die Protokolloberfläche ist von der Objektlogik getrennt und kann verlagert werden, ohne das Objektmodell zu berühren.

### ADR-22 — Getrennter RADIUS-Dienst gegen die interne PKI

**Status:** Angenommen.
**Kontext:** Netzzugang nach IEEE 802.1X braucht RADIUS (RFC 2865) mit EAP-TLS (RFC 5216). EAP-TLS prüft Zertifikate, nicht Kennwörter.
**Entscheidung:** Ein eigener, vollständig erzeugt konfigurierter RADIUS-Dienst, der ausschließlich gegen die interne Ausgabe-CA und deren Sperrliste prüft; RadSec (RFC 6614) für standortübergreifende Strecken; der Dienst läuft nur, wenn Netzzugang genutzt wird. Die 802.1X-Zuordnung von Gerät zu Netzzone folgt aus dem Objektgraphen.
**Begründung:** EAP-TLS braucht eine CA und eine Sperrprüfung, keinen Identitätsanbieter. Die direkte Kopplung an die PKI spart eine Abhängigkeit und eine Divergenzquelle zwischen Zertifikatsstatus und Anmeldeentscheidung.
**Konsequenz positiv:** Die Sperrung eines verlorenen Geräts wirkt über die Sperrliste sofort auf den Netzzugang, ohne dass eine zweite Stelle nachgeführt werden muss.
**Konsequenz negativ:** Ein weiterer Dienst mit erzeugter Konfiguration und eigenem Fehlerbild. Fällt die Sperrlistenverteilung aus, entscheidet der Dienst auf veralteter Grundlage; die zulässige Verzögerung ist damit eine Sicherheitseigenschaft, die überwacht werden muss.
**Verworfen:** RADIUS im Identitätsanbieter — koppelt Gerätezugang an eine Nutzeridentität, die dort nicht gebraucht wird. Passwortbasierte EAP-Verfahren — binden Netzzugang an ein Nutzerkennwort statt an das Gerätezertifikat.
**Rückabwicklung:** Klasse R1. Der Dienst ist zuschaltbar und ersetzbar; seine Konfiguration wird ohnehin vollständig erzeugt.

### ADR-23 — Zweistufige PKI mit Mandanten-Zwischen-CA ab M0

**Status:** Angenommen.
**Kontext:** Ein Mandant, der eigene Dienste veröffentlicht, braucht Zertifikate. Stellt eine gemeinsame CA sie aus, kann ein kompromittierter Mandant für fremde Namen ausstellen.
**Entscheidung:** Wurzel-CA offline außerhalb des Systems (INV-22); Ausgabe-CA als Modul innerhalb von atrium-core mit TPM- oder Token-gebundenem Schlüssel; je Mandant eine Zwischen-CA bereits ab Isolationsstufe M0. ACME-Server (RFC 8555), Sperrliste und OCSP (RFC 6960) erbringt der Kern; jede Ausstellung ist ein Ereignis im replizierten Protokoll. ACME wird für alles benutzt, was ACME kann, einschließlich öffentlicher Zertifikate für externe Namen.
**Begründung:** Ein kompromittierter Mandant darf unter keiner Stufe für fremde Namen ausstellen können; die Namensbeschränkung der Zwischen-CA ist die einzige Stelle, an der das technisch und nicht organisatorisch durchsetzbar ist. Rechnung zur Schadensbegrenzung: aus den Annahmen von K-12 folgen rund 1.450 Endzertifikate je Installation (800 Geräte + 500 Personen + 150 Dienste); ohne Zwischen-CA betrifft eine Kompromittierung alle 1.450, mit Zwischen-CA je Mandant bei 20 Mandanten nach K-20 im Mittel 1.450 / 20 = 72,5.
**Konsequenz positiv:** Sperrung und Neuausstellung sind mandantenweise möglich; der Wechsel einer Zwischen-CA berührt keine anderen Mandanten.
**Konsequenz negativ:** Die Kettenlänge steigt, und jeder Prüfer muss zwei Zwischenstufen verarbeiten. Der Wechsel der Ausgabe-CA erzwingt den parallelen Betrieb aller Zwischen-CAs beider Generationen; K-13 setzt dafür 5 Jahre Laufzeit und ab 2 Jahren Restlaufzeit einen parallel verteilten Nachfolger.
**Verworfen:** Separater CA-Dienst mit eigener Datenhaltung — erzeugt eine zweite Sicherung und die Möglichkeit, dass Kern und CA unterschiedliche Meinungen über Gültigkeit haben. Gemeinsame Ausgabe-CA bis M2 — lässt genau den Angriff zu, den die Isolation verhindern soll. Ausschließlich öffentliche Zertifikate — decken interne Namen und Gerätezertifikate nicht ab und stellen bei Internetausfall gar nichts mehr aus.
**Rückabwicklung:** Klasse R2. Eine Vereinfachung der Kette erfordert die Neuausstellung aller Endzertifikate und die Neuverteilung der Vertrauensanker auf allen Geräten.

## A3.8 Netz und Namen

### ADR-24 — Autoritativer Dienst und Resolver aus einer Hand

**Status:** Angenommen.
**Kontext:** Das System ist DNS-Autorität und zugleich Resolver für interne Netze. Beide Rollen in einem Prozess vermischen Cache und autoritative Daten.
**Entscheidung:** Knot DNS als autoritativer Dienst, ausschließlich über seine Steuerschnittstelle transaktional bespielt, mit automatischer DNSSEC-Signierung (RFC 4033, RFC 4034, RFC 4035) und automatischem Schlüsselwechsel; die Seriennummer ist die Sollzustandsversion. Knot Resolver als getrennter, validierender rekursiver Prozess auf getrennten Adressen, nur an interne Adressen gebunden, je Netzzone eine Instanz mit eigener Sicht. Jeder Verwaltungsknoten erzeugt dieselbe Zoneninstanz aus demselben Sollzustand und ist autoritativ; einen Zonentransfer gibt es nicht.
**Begründung:** Gleiche Herkunft und Freigabekadenz beider Dienste halbieren die Zahl der zu verfolgenden Sicherheitsmeldungen und Aktualisierungspfade. Dass jeder Knoten dieselbe Instanz deterministisch erzeugt, entfernt den Zonentransfer als eigene Fehlerquelle mit eigenem Schlüsselmaterial.
**Konsequenz positiv:** Eine Änderung wirkt auf allen Knoten aus derselben Quelle; K-17 setzt für einen DNS-Eintrag bis zur Auflösbarkeit p95 ≤ 5 s. Verschlüsselte Auflösung nach RFC 7858 und RFC 8484 steht für verwaltete Geräte bereit.
**Konsequenz negativ:** Zwei Dienste desselben Herstellers sind eine gemeinsame Ausfallursache bei einer Schwachstelle in geteiltem Code. Diese Abhängigkeit wird hier bewusst gegen den Gewinn an Pflegeaufwand eingetauscht und ist im Risikoregister zu führen.
**Verworfen:** BIND9 — deutlich größere Angriffs- und Konfigurationsfläche. PowerDNS mit SQL-Backend — eine zweite Wahrheitsquelle neben dem Sollzustand. Ein Prozess für autoritativ und rekursiv — vermischt Cache und autoritative Daten.
**Rückabwicklung:** Klasse R2. Die Erzeugung der Zoneninstanz ist von der Ablage getrennt; ein Austausch betrifft den Erzeugungspfad und die Steuerschnittstelle, nicht das Objektmodell der Domäne.

### ADR-25 — Sichtattribut je Eintrag statt getrennter Zonenpflege

**Status:** Angenommen.
**Kontext:** Interne und externe Auflösung desselben Namens unterscheiden sich häufig. Der übliche Weg sind zwei getrennt gepflegte Zonen.
**Entscheidung:** Eine Domäne erzeugt bis zu zwei Zoneninstanzen (intern, extern); jeder DNS-Eintrag trägt seine Sichtzugehörigkeit ausdrücklich als Attribut mit den Werten intern, extern oder beide. Die Zuordnung "für wen gilt die Domäne" wird auf Netzzone und Gerätezertifikat abgebildet, nicht auf die angemeldete Person.
**Begründung:** Namensauflösung findet am Gerät und im Netz statt, nicht in einer Sitzung. Ohne Sichtattribut je Eintrag laufen zwei Instanzen still auseinander. Modellrechnung mit den Annahmen aus K-12: bei 5.000 Einträgen und angenommen 40 % in beiden Sichten sind 2.000 Einträge doppelt zu führen; bei angenommenen 20 Pflegevorgängen je Woche und einer Fehlerquote von 0,5 % je Vorgang entstehen 20 × 0,005 × 52 = 5,2 stille Divergenzen je Jahr. Das ist ein Modell, keine Messung.
**Konsequenz positiv:** Ein Eintrag existiert genau einmal; die beiden Instanzen sind Projektionen und können nicht auseinanderlaufen. Die Erklärfrage "warum erreicht dieses Gerät diesen Namen nicht" ist aus Netzzone, Gerätezertifikat und Sichtattribut beantwortbar.
**Konsequenz negativ:** Die Zusage gilt nur für verwaltete Geräte in kontrollierten Netzen. Ein unverwaltetes Gerät mit eigener verschlüsselter Namensauflösung umgeht die Sicht; die Konsole muss diese Grenze ausdrücklich benennen, statt eine Durchsetzung zu behaupten, die die Technik nicht einlöst.
**Verworfen:** Views im autoritativen Dienst — verlagert die Sicht in die Ablage und macht sie im Objektmodell unsichtbar. Ableitung aus der Nutzersitzung — die Auflösung findet vor und außerhalb jeder Sitzung statt.
**Rückabwicklung:** Klasse R2. Das Attribut ist Teil des Eintragsschemas in [Anhang A](A1-schemata.md); seine Entfernung ist eine Schemamigration nach INV-24 mit Aufteilung aller Einträge auf zwei Bestände.

### ADR-26 — Firewall vollständig erzeugt, nicht editierbar

**Status:** Angenommen.
**Kontext:** Handgepflegte Regelsätze sind die häufigste Quelle stiller Abweichungen zwischen dokumentiertem und tatsächlichem Zugriff.
**Entscheidung:** nftables mit Regelsätzen, die vollständig aus dem Objektgraphen erzeugt und atomar getauscht werden; Default-Deny in jedem Fehlerzustand (INV-10). Es gibt keinen Dialog zum Anlegen einer Regel; die Konsole zeigt die abgeleitete Sicht mit Quellverweis je Regel (INV-09).
**Begründung:** Eine Regel, die nicht aus einem Objekt folgt, ist per Invariante nicht existent. Modellrechnung des Umfangs, zuerst am durchgerechneten Referenzfall aus [Kapitel 07](07-objektmodell.md): 260 Veröffentlichungen ergeben 260 Eingangsregeln, 15 Netzzonen je eine Grundregel, 40 Konnektorbindungen mit je 3 Zieladressen 120 Ausgangsregeln, zusammen 260 + 15 + 120 = 395 Regeln. Am oberen Rand der Kanongrößen — 500 Hostnamen nach K-19, 160 Konnektorbindungen nach K-20 (20 Mandanten × 8 Bindungen), Annahme 40 Netzzonen — sind es 500 + 40 + 480 = 1.020 Regeln. K-12 trägt diese Rechnung nicht; es nennt weder Veröffentlichungen noch Netzzonen oder Bindungen. Bei angenommen 20 Änderungen je Woche, 3 betroffenen Regeln je Änderung und 4 Minuten Handarbeit je Regel wären das 20 × 3 × 4 = 240 Minuten je Woche, also 208 Stunden oder rund 26 Personentage je Jahr allein für Regelpflege. Das ist ein Modell, keine Messung.
**Konsequenz positiv:** Die Erreichbarkeit folgt aus der Veröffentlichung; ein Rückbau entfernt die Regel zwangsläufig mit, weil das Artefakt mit seiner Quelle verfällt.
**Konsequenz negativ:** Ein Sonderfall, den das Objektmodell nicht kennt, ist nicht abbildbar. Bis das Modell ihn kennt, existiert kein Weg, ihn zu erlauben, und der Bediener steht ohne Ausweg da. Das ist die härteste Einschränkung dieses Entwurfs und der Preis für INV-09.
**Verworfen:** Handgepflegte Regeln — erzeugen genau die Abweichung, die INV-02 ausschließt. ufw — eine Bedienoberfläche für Handregeln und damit dieselbe Klasse.
**Rückabwicklung:** Klasse R3. Eine editierbare Regel bricht INV-09 und INV-10 und entwertet die Zusage, dass jede Erreichbarkeit einen benannten Ursprung hat.

### ADR-27 — Eingangsproxy ausschließlich dynamisch konfiguriert

**Status:** Angenommen.
**Kontext:** Die Invariante "keine Konfigurationsdateien" gilt auch für den Eingang. Jede dateibasierte Konfiguration erzeugt einen Neuladevorgang und damit ein Zeitfenster mit unklarem Zustand.
**Entscheidung:** Envoy als L7-Datenebene, gesteuert ausschließlich über einen in atrium-core eingebauten xDS-Server (8404/tcp); Zertifikate über den Geheimnisdienst direkt in den Speicher; keine Konfigurationsdatei, kein Neuladen. Jede Konfiguration ist eine versionierte Momentaufnahme, ein Rückrollen ist das Ausliefern der vorherigen Momentaufnahme. Ab Isolationsstufe M1 eigene SNI-basierte Listener je Mandant in derselben Instanz.
**Begründung:** Nur eine dynamisch programmierte Datenebene hält INV-02 am Eingang durch. Modellrechnung zur Vermeidung des Neuladens: bei angenommen 20 Änderungen je Tag und 2 s je Neuladen entstünden 40 s täglich mit Verbindungsabbrüchen; K-17 verlangt stattdessen einen Konfigurationswechsel ohne Abbruch über 1.000 aufeinanderfolgende Routenänderungen.
**Konsequenz positiv:** Ein Rückrollen ist ein Datenwechsel statt eines Dateitauschs; der Zustand des Eingangs ist jederzeit als Version benennbar und mit dem Sollzustand vergleichbar.
**Konsequenz negativ:** Envoy ist die größte nicht speichersichere Fläche im System und steht am unauthentisierten Rand. Dieses Risiko wird durch ADR-03 begrenzt, aber nicht beseitigt, und es begründet die 72-Stunden-Vorgabe für Sicherheitsaktualisierungen der Außenkante (K-23).
**Verworfen:** nginx — dateibasiert und damit unvereinbar mit INV-02. Eigener Rust-Proxy — eine dauerhafte, unauthentisiert erreichbare Angriffsfläche ohne Produktvorteil. Vorgeschalteter eigener L4/SNI-Verteiler — ein weiterer Dauerprozess für eine Aufgabe, die die L7-Ebene ohnehin erbringt.
**Rückabwicklung:** Klasse R2. Die Schnittstelle ist der xDS-Server; ein Austausch der Datenebene erfordert einen neuen Erzeugungspfad, lässt aber Veröffentlichung und Objektmodell unberührt.

### ADR-28 — WireGuard-Vollvermaschung als Knotennetz

**Status:** Angenommen.
**Kontext:** Knoten tauschen Konsensverkehr, Agentenverkehr, Replikationsverkehr und Telemetrie aus. Dieser Verkehr darf im Unterlagerungsnetz nicht lesbar sein.
**Entscheidung:** WireGuard als Vollvermaschung zwischen allen Knoten (51820/udp); Schlüssel je Knoten lokal erzeugt und an das Knotenzertifikat gebunden, Rotation gemeinsam mit dem Zertifikat; je Mandant ab M1 ein eigenes Segment mit eigenem Schlüsselmaterial; VXLAN nur, wo L2-Semantik nachweislich gebraucht wird.
**Begründung:** WireGuard ist klein genug für eine vollständige Prüfung und braucht keinen Zustandsdienst. Eine Vollvermaschung kommt bei höchstens 32 Knoten ohne Routenverteiler aus; die Zahl der Verbindungen beträgt 32 × 31 / 2 = 496 und ist damit vollständig aus dem Sollzustand erzeugbar.
**Konsequenz positiv:** Kein privater Schlüssel verlässt seinen Knoten (INV-20); die synchrone Blockreplikation kann ohne eigenes Transportsicherungsverfahren über das Overlay laufen.
**Konsequenz negativ:** Die Vollvermaschung wächst quadratisch. Oberhalb der gesetzten Grenze von 32 Knoten ist sie nicht mehr sinnvoll, und die Grenze ist eine Festlegung, keine gemessene Obergrenze.
**Verworfen:** IPsec — größerer Konfigurationsraum und mehr Zustand für dieselbe Zusage. Ein Netz-Plugin einer Orchestrierungsplattform — setzt ADR-11 voraus, die verworfen wurde. Unverschlüsseltes Unterlagerungsnetz — verlagert die Vertraulichkeit auf eine Annahme über fremde Netze.
**Rückabwicklung:** Klasse R2. Das Overlay ist unterhalb der Netzzone angesiedelt; ein Austausch berührt die Erzeugung der Segmente, nicht deren Modell.

## A3.9 Nachweis, Anwendungsdaten und Bedienung

### ADR-29 — Audit außerhalb des replizierten Kernzustands

**Status:** Angenommen.
**Kontext:** Der Nachweis muss unveränderlich, vollständig und langlebig sein. Der Sollzustand muss klein, quorumpflichtig und schnell exportierbar bleiben.
**Entscheidung:** Auditereignisse sind nur anhängbar, hashverkettet, periodisch signiert mit Zeitstempel nach RFC 3161, werden ausgelagert und liegen außerhalb des replizierten Kernzustands; sie sind durch keine Rolle änderbar und überleben die Löschung des Objekts (INV-23). Der Auditstrom ist vom Betriebsprotokoll getrennt, das nach RFC 5424 über TLS ausgeleitet wird.
**Begründung:** Modellrechnung des Mengenkonflikts mit benannten Annahmen: 200 schreibende Vorgänge je Tag mit je 12 Auditereignissen zu je 1,5 kB ergeben 200 × 12 × 1,5 kB = 3.600 kB, also 3,6 MB je Tag und 1,31 GB je Jahr. Im replizierten Zustand wäre der Zielwert von 50 MB (K-12) nach 50 / 3,6 = 13,9 Tagen überschritten, und bei drei Stimmknoten entstünde eine Schreiblast von 3 × 3,6 = 10,8 MB je Tag allein für den Nachweis. Das ist ein Modell, keine Messung.
**Konsequenz positiv:** Der Sollzustand bleibt klein genug für häufigen Export und schnelle Momentaufnahmen; der Nachweis bleibt erhalten, auch wenn das Objekt gelöscht wurde, was für Verordnung (EU) 2016/679 und Richtlinie (EU) 2022/2555 getrennt bewertbar ist.
**Konsequenz negativ:** Der Auditstrom ist nicht quorumgesichert. Je Verwaltungsknoten entsteht eine eigene Kette, und die Ordnung zweier Ereignisse aus verschiedenen Ketten ist bei einer zugelassenen Uhrenabweichung von 500 ms (K-30) nicht sicher bestimmbar. Der Verlust eines Knotens vor der Auslagerung kann Ereignisse dieser Kette verlieren; die Kettenprüfung zeigt die Lücke, füllt sie aber nicht.
**Verworfen:** Gemeinsamer Strom für Betrieb und Audit — ein Betriebsprotokoll muss verdichtbar und wegwerfbar sein, ein Nachweis darf es nicht sein. Audit im replizierten Zustand — sprengt Zustandsgröße und Momentaufnahme.
**Rückabwicklung:** Klasse R3. Die Unveränderlichkeit und die Trennung sind Gegenstand von INV-23 und der gesamten Nachweisführung in [Kapitel 19](19-mandanten-rechte-audit.md).

### ADR-30 — Anwendungsdatenbank als gewöhnlicher Dienst

**Status:** Angenommen.
**Kontext:** Viele Katalogeinträge brauchen eine relationale Datenbank. Die naheliegende Ersparnis wäre, dieselbe Instanz für die Kontrollebene zu nutzen.
**Entscheidung:** PostgreSQL als Standard-Datenbankdienst für Katalogeinträge, betrieben als gewöhnlicher Dienst mit Speicherbereich der Stufe "Synchron gespiegelt", niemals als Bestandteil der Kontrollebene; SQLite für Katalogeinträge mit geringem Bedarf; MariaDB nur, wo ein Katalogeintrag es zwingend verlangt.
**Begründung:** Die Kontrollebene darf nach ADR-06 keine externe Datenbank haben. Als gewöhnlicher Dienst unterliegt die Datenbank derselben Platzierung, denselben Speicherklassen und demselben Sicherungsplan wie jeder andere Dienst, ohne Sonderpfad.
**Konsequenz positiv:** Für die Datenbank gelten Wiederherstellungspunkt, Datensicherheitsstufe und Aktualisierung mit Rücksprungpunkt unverändert; es existiert kein zweites Betriebsverfahren.
**Konsequenz negativ:** Mehrere Datenbankinstanzen je Installation kosten Hauptspeicher und Platten, weil jede ihren eigenen Puffer hält. Eine gemeinsame Instanz je Mandant wäre sparsamer, verletzt aber die Fehlerdomänentrennung zwischen Diensten; diese Abwägung ist hier zugunsten der Trennung entschieden.
**Verworfen:** Eine gemeinsame Instanz für Kontrollebene und Anwendungen — erzeugt die zirkuläre Abhängigkeit aus ADR-06. MySQL/MariaDB als Standard — deckt die Anforderungen der überwiegenden Zahl der Katalogeinträge nicht ohne Zusatzannahmen ab.
**Rückabwicklung:** Klasse R1. Ein zusätzlicher Datenbanktyp ist ein weiterer Katalogeintrag mit eigenem Speicherbereich; die Kontrollebene ist davon nicht betroffen.

### ADR-31 — Keine Telemetrie an den Hersteller ohne Einwilligung

**Status:** Angenommen.
**Kontext:** Betriebsdaten sind für die Produktentwicklung wertvoll und für den Kunden ein Vertraulichkeitsrisiko. Eine Voreinstellung entscheidet diesen Konflikt faktisch.
**Entscheidung:** OpenTelemetry als internes Format; Empfang über OTLP durch einen in atrium-core eingebauten Kollektor (8408/tcp); Metriken in einem lokalen Zeitreihenspeicher je Verwaltungsknoten, niemals im replizierten Zustand. Eine Ausleitung an ein externes Ziel ist eine Konnektorbindung wie jede andere und existiert nur, wenn der Kunde sie einrichtet. Ohne eingerichtete Bindung verlässt kein Betriebsdatum das System.
**Begründung:** Eine Ausleitung ohne Bindung wäre ein Schreibweg ohne Objekt und damit ohne Wirkungsvorschau und ohne Auditereignis. Die Einrichtung als Konnektorbindung macht Ziel, Umfang und Ausgangs-Positivliste sichtbar und auditierbar (INV-21).
**Konsequenz positiv:** Die Aussage "keine Datenübermittlung an den Hersteller" ist prüfbar statt zugesichert: die Ausgangsregeln des Knotens enthalten kein Herstellerziel, solange keine Bindung besteht.
**Konsequenz negativ:** Der Hersteller hat keine Felddaten. Fehlerbilder aus dem Betrieb erreichen die Entwicklung nur über Supportvorgänge und beigefügte Ausleitungen, was Diagnose und Priorisierung verlangsamt. Das ist eine bewusst getragene Entwicklungslast.
**Verworfen:** Ausleitung als Voreinstellung mit Abwahlmöglichkeit — verschiebt die Entscheidung auf jemanden, der sie nicht trifft. Prometheus-Server als Pflichtbestandteil — ein weiterer Dauerprozess mit eigener Sicherung, entgegen K-20.
**Rückabwicklung:** Klasse R0 technisch, weil die Bindung ein Objekt mit Lebenszyklus ist. Die Zusage selbst ist nicht rückabwickelbar: ihre Rücknahme ist ein Vertrauensbruch und kein Vorgang.

### ADR-32 — Barrierefreiheit als Baubedingung

**Status:** Angenommen.
**Kontext:** Barrierefreiheit, die als Merkmal geführt wird, wird bei Terminkonflikten verschoben. Rechtlich ist sie durch Richtlinie (EU) 2019/882 und das Barrierefreiheitsstärkungsgesetz gefordert.
**Entscheidung:** Die Atrium Console ist vollständig ohne Zeigegerät bedienbar, arbeitet mit Bildschirmlesern und erfüllt WCAG 2.2 Stufe AA (INV-31). Geprüft wird im Bau durch eine automatisierte Regelprüfung und einen Tastaturdurchlauf des gesamten Aufgabenkatalogs; ein Verstoß bricht den Bau (K-27).
**Begründung:** Nur eine Bauprüfung hält die Eigenschaft über die Zeit. Eine Prüfung am Ende eines Entwicklungsabschnitts findet Verstöße, wenn ihre Behebung teuer ist; eine Prüfung im Bau verhindert ihre Entstehung.
**Konsequenz positiv:** Die Tastaturbedienbarkeit fällt mit der Anforderung zusammen, dass jede Standardaufgabe ohne Terminal ausführbar ist (INV-26), und beide Prüfungen laufen über denselben Aufgabenkatalog.
**Konsequenz negativ:** Jede Oberflächenarbeit trägt die Prüfung mit. Ein gebrochener Bau wegen eines Kontrastwerts blockiert eine unverwandte Änderung, und die automatisierte Regelprüfung deckt nur einen Teil der Stufe AA ab; der verbleibende manuelle Anteil ist in diesem Entwurf nicht beziffert. Ein belastbarer Faktor für die Mehrkosten einer nachträglichen Herstellung liegt nicht vor, und dieser Anhang erfindet keinen.
**Verworfen:** Barrierefreiheit als späterer Ausbau — erzeugt eine Nachrüstung an einer Oberfläche, deren Struktur bereits festliegt. Prüfung nur vor Freigaben — findet Verstöße zu spät und macht sie zu Terminrisiken.
**Rückabwicklung:** Klasse R3. Die Zusage ist Teil der Produktdefinition und rechtlich gebunden; eine Rücknahme berührt die Verkehrsfähigkeit des Produkts im Geltungsbereich der genannten Rechtsakte.

## A3.10 Anforderungen

| ID | Anforderung |
|---|---|
| R-A3-01 | Jeder Bereich aus KANON.md, Abschnitt 4 ist in der Tabelle A3.2 genau einer Entscheidungsnummer zugeordnet; die Zahl der nicht zugeordneten Bereiche ist 0. |
| R-A3-02 | Jede Entscheidung führt die acht Felder Status, Kontext, Entscheidung, Begründung, Konsequenz positiv, Konsequenz negativ, Verworfen, Rückabwicklung in dieser Reihenfolge; ein fehlendes Feld führt zur Ablehnung durch die Dokumentprüfung. |
| R-A3-03 | Jede Entscheidung trägt genau eine Rückabwicklungsklasse aus {R0, R1, R2, R3}, und die Klassenverteilung in A3.1 stimmt mit der Auszählung aus A3.2 überein. |
| R-A3-04 | Jeder Status ist ein Wert aus {Angenommen, Angenommen mit Vorbehalt, Zurückgestellt, Ersetzt durch ADR-nn}; eine ersetzte Entscheidung bleibt mit Verweis auf die ersetzende Nummer erhalten, und keine Nummer wird neu vergeben. |
| R-A3-05 | Jede Entscheidung mit Status "Angenommen mit Vorbehalt" nennt die Bedingung, unter der der Vorbehalt entfällt, und die Stelle, an der die Bedingung geprüft wird. |
| R-A3-06 | Jede Entscheidung der Klasse R3 nennt mindestens ein vor der ersten Freigabe zu erbringendes Kriterium oder die Invariante, aus der ihre Unumkehrbarkeit folgt. |
| R-A3-07 | Jede Entscheidung nennt mindestens eine verworfene Alternative mit Grund; die Zahl der Entscheidungen ohne verworfene Alternative ist 0. |
| R-A3-08 | Jede Zahl in diesem Anhang ist als Zielwert, Annahme oder Rechenergebnis gekennzeichnet; jede Rechnung nennt Formel, Annahmen und Ergebnis und ist ohne weitere Angaben nachrechenbar. |
| R-A3-09 | Jede Entscheidung, die eine Invariante stützt oder einschränkt, nennt deren Nummer im Format INV-nn; jede Entscheidung, die einen Zielwert einhält, nennt dessen Nummer im Format K-nn. |
| R-A3-10 | Eine Änderung an KANON.md, Abschnitt 4 erzeugt vor ihrer Verwendung in einem Kapitel entweder eine neue Entscheidung oder eine ersetzende Entscheidung in diesem Anhang. |
| R-A3-11 | Kein Eintrag dieses Anhangs nennt eine Versionsnummer von Fremdsoftware oder eine Kernelversion. |
| R-A3-12 | Jede Entscheidung verweist auf mindestens ein Kapitel des Whitepapers, in dem sie ausgeführt ist; die Zahl der Entscheidungen ohne Kapitelverweis ist 0. |
| R-A3-13 | Die Ausleitung von Betriebsdaten an ein herstellerseitiges Ziel existiert ausschließlich als Konnektorbindung; ohne eingerichtete Bindung enthält die Ausgangs-Positivliste eines Knotens 0 Herstellerziele. |
| R-A3-14 | Die automatisierte Regelprüfung auf WCAG 2.2 Stufe AA und der Tastaturdurchlauf des Aufgabenkatalogs laufen in jedem Bau; ein Verstoß bricht den Bau. |

## Akzeptanzkriterien

| Kriterium | Anforderung | Nachweisverfahren |
|---|---|---|
| Die Zuordnungstabelle A3.2 deckt alle Bereiche aus KANON.md, Abschnitt 4 ab; 0 offene Bereiche | R-A3-01 | Abgleichlauf im Bau zwischen Kanontabelle und A3.2 |
| 32 von 32 Entscheidungen führen alle acht Felder; 0 unvollständige Einträge | R-A3-02 | Strukturprüfung des Dokuments |
| Die Auszählung ergibt 4 × R0, 8 × R1, 11 × R2, 9 × R3 und in Summe 32 | R-A3-03 | Auszählung gegen A3.2 |
| 0 Entscheidungen mit einem Status außerhalb der vier zugelassenen Werte; 0 neu vergebene Nummern | R-A3-04 | Musterprüfung über alle Statusfelder und Nummernvergleich gegen die Vorfassung |
| Alle drei Einträge mit Vorbehalt (14, 17, 20) nennen Bedingung und Prüfstelle | R-A3-05 | Sichtprüfung der Vorbehaltsfelder |
| Alle 9 R3-Entscheidungen nennen ein Freigabekriterium oder die tragende Invariante | R-A3-06 | Sichtprüfung der Rückabwicklungsfelder der R3-Einträge |
| 0 Entscheidungen ohne verworfene Alternative mit Grund | R-A3-07 | Strukturprüfung des Feldes "Verworfen" |
| 0 Zahlen ohne Kennzeichnung; jede Modellrechnung ist mit den angegebenen Annahmen reproduzierbar | R-A3-08 | Nachrechnen der Rechnungen in ADR-03, ADR-08, ADR-11, ADR-12, ADR-18, ADR-19, ADR-23, ADR-25, ADR-26, ADR-27, ADR-28 und ADR-29 |
| 0 Entscheidungen ohne Invarianten- oder Kennzahlenbezug, soweit ein solcher besteht | R-A3-09 | Musterprüfung auf `INV-` und `K-` je Eintrag |
| Eine Kanonänderung ohne zugehörige neue oder ersetzende Entscheidung bricht die Dokumentprüfung | R-A3-10 | Abgleichlauf gegen die Vorfassung von KANON.md |
| 0 Treffer der Musterprüfung auf Versions- und Kernelangaben | R-A3-11 | Musterprüfung über den Dokumenttext |
| 32 von 32 Entscheidungen tragen mindestens einen Kapitelverweis als relativer Link | R-A3-12 | Linkprüfung im Bau |
| Ohne eingerichtete Bindung erreicht ein Knoten im Netznamensraumtest 0 Herstelleradressen | R-A3-13 | Netznamensraumtest mit Zugriffsversuch |
| 0 Verstöße in der automatisierten Regelprüfung; der Tastaturdurchlauf erreicht 100 % des Aufgabenkatalogs | R-A3-14 | Bauprüfung nach K-27 |

## Offene Punkte

1. **Kein Beleg für das Ressourcenbudget mit vollständigem Stapel.** K-19 setzt 4 GB und 2 Kerne für Kontrollebene, Eingang, autoritativen Dienst, Resolver und Protokollkopf zusammen. Die Entscheidungen 11, 20, 24 und 27 hängen sämtlich an diesem Wert, und es existiert keine Messung mit allen fünf Komponenten gleichzeitig unter Last. Überschreitet der Stapel das Budget, fällt entweder der Ein-Knoten-Betrieb auf kleiner Hardware oder eine der vier Entscheidungen; welche zuerst, ist nicht festgelegt.

2. **Fähigkeitsabdeckung des Protokollkopfes.** ADR-20 setzt voraus, dass die LDAP-Schemaunterstützung des eingebetteten Kopfes für die Fremdprodukte des Katalogs ausreicht. Dieser Nachweis liegt nicht vor, und der Anhang beschreibt die Anforderung bewusst, statt eine Abdeckung zu behaupten. Ein Rückfall auf einen zweiten LDAP-Dienst wäre Klasse R2 und würde eine zweite Kontenablage erzeugen, die mit INV-02 nur über einen weiteren Abgleichpfad vereinbar ist.

3. **Die dritte Replikationsstufe ohne vierte Bedienoption.** ADR-14 sieht ab acht Knoten einen verteilten Objektspeicher vor, ADR-13 lässt dem Bediener aber nur drei Klassen. Ob die neue Stufe eine bestehende Klasse anders erfüllt oder eine vierte Klasse erzwingt, ist nicht entschieden; im zweiten Fall bricht die Zusage aus INV-14 an dieser Stelle, im ersten Fall ändert sich das RPO einer Klasse durch einen Ausbau, ohne dass der Bediener es veranlasst hat.

4. **Rückabwicklungskosten sind Modelle ohne Messung.** Die Bänder in A3.1 und jede bezifferte Rückabwicklung beruhen auf Annahmen, insbesondere auf 25 geprüften Zeilen je Personentag und 20 produktiven Tagen je Personenmonat. Weder die Produktivitätsannahme noch der Umfang von 120.000 Zeilen in ADR-03 ist belegt. Solange kein Bezugspunkt aus dem eigenen Bau vorliegt, ordnen die Klassen Entscheidungen relativ zueinander ein und taugen nicht zur Budgetplanung.

5. **Kein Abbruchkriterium für den Kopplungssonderweg.** ADR-17 senkt die Sicherheitszusage aus ADR-16 genau für den häufigsten Mietserverfall. Es gibt keinen festgelegten Anteil, ab dem der Sonderweg zum Regelweg geworden ist und die Zusage aus K-14 praktisch nicht mehr trägt. Ohne ein solches Kriterium ist die Kennzeichnung in der Konsole der einzige Schutz, und Kennzeichnungen nutzen sich ab.

6. **Ordnung und Verfügbarkeit des Auditstroms.** ADR-29 nimmt in Kauf, dass je Verwaltungsknoten eine eigene Kette entsteht und der Verlust eines Knotens vor der Auslagerung Ereignisse dieser Kette verliert. Ob ein knotenübergreifender Anker eingeführt wird und welche zusätzliche Schreiblast er erzeugt, ist offen; [Anhang A](A1-schemata.md) führt denselben Punkt aus der Schemasicht. Für einen Nachweis, der die Abfolge zweier Handlungen auf verschiedenen Knoten belegen soll, reicht der Zeitstempel bei 500 ms zugelassener Abweichung (K-30) nicht.

7. **Manueller Anteil der Barrierefreiheitsprüfung.** ADR-32 bindet die automatisierte Regelprüfung an den Bau. Der Anteil der Stufe AA, der nur manuell prüfbar ist, ist weder benannt noch budgetiert, und ohne diese Zahl ist die Zusage in K-27 nur für den automatisierbaren Teil belastbar.

8. **Kein Rückfallpfad bei Änderungen der Distributionsbasis.** ADR-01 übernimmt Voreinstellungen und Kernelmerkmale der Basis unverändert. Fällt ein Merkmal weg, auf dem eine Invariante ruht — etwa die Integritätsprüfung des Wurzeldateisystems aus ADR-02 —, existiert kein beschriebener Weg außer einem eigenen Paketzweig, der die Begründung von ADR-01 aufhebt. Dieser Konflikt ist erkannt und nicht gelöst.
