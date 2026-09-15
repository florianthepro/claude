# 05 Systemarchitektur

## 5.1 Schichten und ihre Grenzen

Die Architektur besteht aus sechs Schichten. Jede Schicht darf nur die unmittelbar darunterliegende aufrufen; das Überspringen einer Schicht ist kein Optimierungsspielraum, sondern ein Fehler, weil es die Rechte- und Fehlerabgrenzung aufhebt, aus der sich die Invarianten ergeben.

```
 Bediener: Browser, Tastatur, Bildschirmleser        Fremdautomatisierung
        |                                                    |
        |  HTTPS 443/tcp, 443/udp, TLS 1.3                    |  dieselbe API (INV-01)
        v                                                    v
 +======================================================================+
 | S6  ATRIUM CONSOLE                                                   |
 |     Browseranwendung. Kein Serverprozess, kein serverseitiger        |
 |     Sitzungsspeicher, kein privilegierter Pfad. atriumctl ist ein    |
 |     zweiter Aufrufer derselben Endpunkte, kein zweiter Weg.          |
 +======================================================================+
        |  einziger Eintritt: Eingang (Envoy), TLS-Terminierung
        v
 +======================================================================+
 | S4  KONTROLLEBENE  (atrium-core, je Verwaltungsknoten ein Prozess)   |
 |  +----------------+ +-------------+ +-----------+ +---------------+  |
 |  | API + Schema-  | | Raft-Log    | | Lesemodell| | Reconciler    |  |
 |  | validierung    | | (Sollzust., | | (SQL,     | | + Platzierung |  |
 |  |                | |  hashverk.) | |  WAL)     | |               |  |
 |  +----------------+ +-------------+ +-----------+ +---------------+  |
 |  +----------------+ +-------------+ +-----------+ +---------------+  |
 |  | Ausgabe-CA +   | | xDS-Server  | | SCIM      | | OTLP-Kollektor|  |
 |  | ACME/OCSP/CRL  | | (an Eingang)| | Srv+Client| |               |  |
 |  +----------------+ +-------------+ +-----------+ +---------------+  |
 +======================================================================+
      |  8402/tcp Agentenkanal      |  8404/tcp xDS + SDS
      |  (gRPC, gegenseitiges TLS)  v
      |                     +-------------------------------------+
      |                     | S2b DATENEBENE (fremdgepflegt)      |
      |                     | Envoy | Knot DNS | Knot Resolver |   |
      |                     | Kanidm (Protokollkopf) | FreeRADIUS |
      |                     +-------------------------------------+
      v                                    ^
 +======================================================================+
 | S3  ATRIUM-NODE (auf jedem Knoten, auch auf Verwaltungsknoten)       |
 |     Materialisiert den signierten Sollzustandsauszug lokal.          |
 |     Ruft Konnektoren auf. Meldet Istzustand. Hält die Lease.         |
 |                                                                      |
 |     ----> S5 KONNEKTOREN (Seitenfläche, nicht Stapelschicht)         |
 |           atrium-connector-<name>, ein Prozess je Mandant und        |
 |           Bindung, gRPC über Unix-Socket, eigener Netznamensraum,    |
 |           Ausgangs-Positivliste aus der Konnektorbindung (INV-21).   |
 +======================================================================+
      |  lokale, schemagebundene Aufrufe an benannte Hilfseinheiten
      v
 +======================================================================+
 | S2  BASISSYSTEM (Ubuntu LTS, unverändert)                            |
 |     systemd | Podman (daemonlos) | nftables | WireGuard | ZFS |      |
 |     chrony (NTS) | journald | dm-verity, A/B-Wurzeldateisystem       |
 +======================================================================+
      v
 +======================================================================+
 | S1  HARDWARE / VIRTUALISIERUNG                                       |
 |     UEFI Secure Boot | TPM 2.0 | Netzkarten | Datenträger            |
 +======================================================================+
```

Die Schichtnummern sind nicht fortlaufend nach Zeichenposition vergeben, sondern nach Vertrauensrichtung: S1 und S2 werden fremdgepflegt und von Atrium nur konfiguriert, S3 und S4 sind Eigenentwicklung, S5 ist gekapselter Fremdcode mit Netzkontakt, S6 hat keinen eigenen Prozess. Die Konnektoren stehen bewusst nicht zwischen S3 und S4: sie sind die einzige Komponentenart mit Verbindung nach außen und haben deshalb keine Position im Stapel, sondern eine eigene, seitlich angebundene Fläche mit eigener Rechteumgebung.

Die Datenebene S2b ist fremdgepflegte Software, die Atrium ausschließlich programmatisch steuert und niemals über Konfigurationsdateien. Ihre Verwaltungsoberflächen sind abgeschaltet (INV-30), ihre Zustände sind aus dem Sollzustand rekonstruierbar, und sie überleben den Ausfall der Kontrollebene mit ihrem letzten gültigen Stand (INV-25). Die Zuordnung der Technologien folgt Abschnitt 4 von `KANON.md` und wird in [Kapitel 12](12-dns-netzwerk.md) und [Kapitel 10](10-identitaet.md) vertieft.

**Anforderungen**

- **R-05-01** — Kein Prozess der Schicht S4 ruft Podman, systemd, netlink oder ZFS direkt auf. Prüfbar: das gebaute Binärartefakt von atrium-core enthält keine Symbolbindung an Container-, netlink-, D-Bus- oder ZFS-Bibliotheken; ein Verstoß bricht den Bau. Folgt aus der Schichtregel und stützt INV-03.
- **R-05-02** — Jede Schnittstelle zwischen zwei Schichten ist im maschinenlesbaren Schnittstellenkatalog mit Protokoll, Transport, Authentisierung, Autorisierung und deklariertem Fehlerverhalten eingetragen. Prüfbar: eine im Betrieb beobachtete Verbindung zwischen zwei Atrium-Prozessen, die nicht im Katalog steht, bricht den Integrationstest.

## 5.2 Komponenteninventar

| Komponente | Aufgabe | Sprache | Laufzeitrechte | Netzflächen | Gehaltener Zustand | Verhalten bei Ausfall | Neustartkosten |
|---|---|---|---|---|---|---|---|
| **atrium-core** | Sollzustand, Konsens, Reconciler, Platzierung, Ausgabe-CA, ACME/OCSP/CRL, xDS, SCIM, OTLP-Empfang | Rust | Benutzer `atrium-core`, keine Capability, Zugriff auf `/dev/tpmrm0` über Gruppenmitgliedschaft, Schreibrecht nur im eigenen ZFS-Dataset | 8400 API, 8401 Raft, 8402 Agentenkanal, 8404 xDS, 8405 SCIM, 8406 ACME, 8407 OCSP/CRL, 8408 OTLP, 8409 Gesundheit (Loopback) | Raft-Log (autoritativ), materialisiertes Lesemodell, Zeitreihenspeicher (lokal, nicht repliziert) | War der Prozess Führer, wählt die Gruppe neu; Schreibvorgänge pausieren, Lesevorgänge auf anderen Verwaltungsknoten laufen weiter; bei Verlust der Mehrheit ist der Sollzustand eingefroren (INV-04) | Schreibpause Zielwert ≤ 5 s (K-05); kein Verbindungsabbruch an veröffentlichten Diensten; Lesemodell wird aus dem Log wiederhergestellt, nicht migriert |
| **atrium-node** | Materialisierung des Sollzustandsauszugs, Erzeugung der Dienst-Units, Konnektoraufruf, Istzustandsmeldung, Lease | Rust | Benutzer `atrium-node`, keine Capability; privilegierte Wirkungen nur über Hilfseinheiten; polkit-Regel auf Unit-Namensmuster `atrium-dienst-*.service` | ausgehend 8402 zum Kern, 8408 Telemetrie; lokal: Unix-Sockets zu Konnektoren und Hilfseinheiten | Letzter empfangener, signierter Sollzustandsauszug; lokaler Istzustand mit Beobachtungszeitpunkt | Laufende Dienste laufen weiter, weil Podman daemonlos ist und systemd die Units hält; ohne gültige Lease beendet der Knoten seine Arbeitslasten selbst (INV-06) | Kein Dienstneustart, keine Verbindungsunterbrechung; Wiederaufnahme durch erneuten Abgleich, Zielwert ≤ 30 s bis zur ersten Istmeldung |
| **Eingang (Envoy)** | L7-Terminierung, Routen, SNI-Listener je Mandant ab M1 | C++ (fremdgepflegt) | Benutzer `atrium-eingang`, ausschließlich `CAP_NET_BIND_SERVICE` als Ambient-Capability | 80, 443/tcp, 443/udp eingehend; 8404 ausgehend zum Kern | Nur der zuletzt empfangene, versionierte Konfigurationsschnappschuss im Speicher; keine Datei | Behält beim Verlust der xDS-Verbindung den letzten Stand bei (fail-static); ein Fehler beim Anwenden führt zum Verwerfen des neuen Schnappschusses, nicht zum Leerzustand | Neustart bricht bestehende Verbindungen; deshalb nur bei Abbildwechsel mit Verbindungsentleerung, nie zur Konfigurationsänderung |
| **Autoritativer DNS (Knot DNS)** | Autoritative Beantwortung, DNSSEC-Signierung | C (fremdgepflegt) | Benutzer `atrium-dns-autoritativ`, `CAP_NET_BIND_SERVICE` | 53/udp, 53/tcp eingehend; lokale Steuerschnittstelle als Unix-Socket | Zoneninstanzen, deterministisch aus dem Sollzustand erzeugt; Seriennummer = Sollzustandsversion | Antwortet mit dem zuletzt geladenen Stand weiter; ein zweiter Verwaltungsknoten erzeugt dieselbe Zone unabhängig, es gibt keinen Zonentransfer als Fehlerquelle | Kurze Auflösungslücke auf diesem Knoten; interne TTL 300 s deckt sie ab, sofern ein zweiter autoritativer Knoten existiert |
| **Rekursiver Resolver (Knot Resolver)** | Validierende Auflösung je Netzzone mit eigener Sicht | C (fremdgepflegt) | Benutzer `atrium-dns-resolver`, `CAP_NET_BIND_SERVICE` | 53/udp, 53/tcp, 853/tcp; nur interne Adressen | Cache (flüchtig), Sichtzuordnung aus dem Sollzustand | Clients fallen auf den Resolver einer anderen Instanz zurück, sofern die Netzzone zwei kennt; sonst Auflösungsausfall in dieser Zone | Cache kalt, erhöhte Latenz für die Dauer der Wiederbefüllung; keine Zustandsverlustfolge |
| **Protokollkopf (Kanidm)** | OIDC/OAuth2, LDAPS, Passkey/MFA gegenüber Fremdprodukten | Rust (fremdgepflegt) | Benutzer `atrium-identitaet`, `CAP_NET_BIND_SERVICE` für 636/tcp | 636/tcp eingehend, OIDC hinter dem Eingang; Schreibzugriff nur lokal durch den Reconciler | Eigener Kontospeicher als abgeleitete Kopie; der Kern gewinnt jede Abweichung | Anmeldungen an Fremdprodukten schlagen fehl; bestehende Sitzungen laufen bis zum Tokenablauf weiter | Neustart ohne Datenverlust; Abweichungen werden beim nächsten Abgleich korrigiert und als Auditereignis "Abweichung korrigiert" gemeldet (INV-02) |
| **atrium-connector-\<name\>** | Fremdsystemzugriff über `describe`, `observe`, `plan`, `apply`, `healthcheck` | Rust | Eigener Benutzer `atrium-conn-<mandant-kurz>-<konnektor>`, keine Capability, `NoNewPrivileges`, `ProtectSystem=strict`, `PrivateTmp`, seccomp, eigener Netznamensraum | Kein eingehender Port; ausgehend ausschließlich die aus der Bindung erzeugte Positivliste; kein Zugriff auf 8400 (INV-21) | Keiner über die Dauer eines Auftrags hinaus; Geheimnisse nur als kurzlebige, auftragsgebundene Referenz | Der Vorgang meldet "teilweise fehlgeschlagen" mit benanntem Zielsystem und Grund (INV-12); andere Zielsysteme sind nicht betroffen | Aktivierungsgesteuert, Leerlaufabschaltung nach 10 min; Kaltstart Zielwert ≤ 300 ms, daher ist ein Neustart der Regelfall und keine Störung |
| **Hilfseinheiten `atrium-netfilter`, `atrium-link`, `atrium-speicher`** | Atomarer Regelsatztausch, WireGuard-Schnittstellen, ZFS-Datasets und Einhängungen | Rust | Je Einheit genau eine Capability (siehe 5.3); kein Freitext, festes Operationsschema | Keine Netzfläche; Aktivierung über je einen Unix-Socket mit Dateisystemrechten | Keiner; jede Operation ist vollständig durch ihre Parameter bestimmt | Der zuletzt angewandte Regelsatz bleibt aktiv; ein abgebrochener Tausch fällt auf Default-Deny zurück (INV-10) | Aktivierungsgesteuert, kein Dauerprozess; Neustart wirkungsfrei |
| **`atrium-audit`** | Anhängbarer, hashverketteter Auditstrom außerhalb des replizierten Zustands, periodische Signatur und Zeitstempel | Rust | Benutzer `atrium-audit`, keine Capability; Schreibrecht ausschließlich auf sein eigenes, nur anhängbares Dataset; kein anderer Atrium-Benutzer hat dort Schreibrecht | Ausgehend 6514/tcp Syslog über TLS | Auditkette mit Vorgängerhashwert je Ereignis | Der Kern verweigert schreibende Operationen, solange kein Auditereignis geschrieben werden kann; ein nicht nachweisbarer Schreibvorgang findet nicht statt | Kettenprüfung bei jedem Start (INV-23); Zielwert ≤ 5 s für 12 Monate verdichteter Kette |
| **Atrium Console** | Bedienung | Web-Oberfläche gegen die öffentliche API | Kein Serverprozess, kein Systembenutzer | Keine; sie wird als statische Artefaktmenge vom Eingang ausgeliefert | Nur die Browsersitzung | Kein Ausfallmodus über die Nichtverfügbarkeit des Eingangs hinaus | Neuladen im Browser |
| **atriumctl** | Support und Automatisierung | Rust | Rechte des aufrufenden Benutzers; keine Sonderrechte, kein lokaler Sonderpfad | Ausgehend 443/tcp zur veröffentlichten API | Keiner außer dem Sitzungstoken im Schlüsselbund des Benutzers | Entfällt; Ausfall ist Nichtverfügbarkeit der API | Entfällt |

Die Spalte "Neustartkosten" ist der Grund für die Trennung von Kontroll- und Datenebene: nur der Eingang hat Neustartkosten, die ein Nutzer bemerkt, und genau deshalb wird er niemals zur Konfigurationsänderung neu gestartet. Alle übrigen Komponenten sind so geschnitten, dass ihr Neustart entweder wirkungsfrei ist oder eine benannte, in [Kapitel 16](16-cluster.md) quantifizierte Pause erzeugt.

**Anforderungen**

- **R-05-03** — Ein Neustart einer beliebigen einzelnen Atrium-Komponente außer dem Eingang unterbricht keine bestehende Verbindung zu einem veröffentlichten Dienst. Prüfbar: Dauerlasttest mit fortlaufenden Verbindungen während je eines Neustarts jeder Komponente; ein Verbindungsabbruch bricht den Test.
- **R-05-04** — Der Eingang wird zur Änderung von Routen, Zertifikaten oder Listenern nicht neu gestartet und liest keine Konfigurationsdatei. Prüfbar: 1.000 aufeinanderfolgende Routenänderungen ohne Prozessneustart und ohne Verbindungsabbruch (K-17).

## 5.3 Prozess- und Rechtemodell

Kein Atrium-Prozess läuft als Benutzer 0. Privilegierte Wirkungen sind auf drei Hilfseinheiten mit je genau einer Capability und einem festen Operationsschema eingeschränkt; sie nehmen keine Zeichenketten entgegen, die zu einem Befehl werden könnten, sondern ausschließlich typisierte Nachrichten, deren Felder gegen das Schema und gegen die Namenskonventionen aus `KANON.md` validiert werden.

| Einheit | Systembenutzer | Capability | Warum genau diese | Was sie ausdrücklich nicht darf |
|---|---|---|---|---|
| `atrium-netfilter.service` | `atrium-netfilter` | `CAP_NET_ADMIN` | Der atomare Tausch eines vollständigen nftables-Regelsatzes ist eine netlink-Transaktion und ohne diese Capability nicht durchführbar | Keine Einzelregel entgegennehmen, keine Regel anhängen, keine Schnittstelle konfigurieren; ein Regelsatz ohne gültige Quellzuordnung wird abgelehnt |
| `atrium-link.service` | `atrium-link` | `CAP_NET_ADMIN` | WireGuard-Schnittstellen, Adressen und Routen werden über netlink gesetzt | Keine Filterregeln setzen, keine Schlüssel exportieren; private Schlüssel werden in der Einheit erzeugt und verlassen den Knoten nicht (INV-20) |
| `atrium-speicher.service` | `atrium-speicher` | `CAP_SYS_ADMIN` | Das Einhängen eines Dateisystems verlangt unter Linux `CAP_SYS_ADMIN`; eine feinere Capability existiert nicht | Keine Netzoperation, keine Prozesserzeugung, kein Zugriff außerhalb des Pfadpräfixes `<pool>/atrium/`; Datasetnamen werden gegen die Namenskonvention geprüft, bevor sie an die Bibliothek gehen |
| `atrium-eingang.service` | `atrium-eingang` | `CAP_NET_BIND_SERVICE` | Bindung an 80/tcp und 443/tcp und 443/udp | Nichts darüber hinaus; die Capability ist ambient gesetzt und nach der Bindung nicht mehr wirksam |
| `atrium-dns-autoritativ.service`, `atrium-dns-resolver.service`, `atrium-identitaet.service` | eigene | `CAP_NET_BIND_SERVICE` | Bindung an 53, 853 bzw. 636/tcp | Keine weiteren Rechte; kein Schreibzugriff außerhalb des jeweils eigenen Datasets |

`CAP_SYS_ADMIN` in `atrium-speicher` ist die schwächste Stelle dieses Modells und wird hier benannt statt umschrieben: diese Capability ist im Linux-Rechtemodell nahezu gleichbedeutend mit voller Privilegierung. Die Gegenmaßnahmen sind eine sehr kleine Angriffsfläche (ein Socket, ein Nachrichtenschema, kein Netz, keine Prozesserzeugung), ein eigener Namensraum ohne Zugriff auf fremde Einhängepunkte und eine Auditpflicht für jede Operation. Ob die vollständige Vermeidung über ZFS-Delegation möglich ist, ist ungeklärt und steht unter "Offene Punkte".

Ausdrücklich nicht privilegiert laufen: **atrium-core** (keine Capability, kein netlink, kein Container, kein Zugriff auf fremde Dateisystempfade), **atrium-node** (keine Capability; es beauftragt die Hilfseinheiten und startet nur Units, deren Name dem generierten Muster entspricht) und **jeder Konnektorprozess** (keine Capability, eigener Netznamensraum, Ausgangs-Positivliste). Der Kern hält den Schlüssel der Ausgabe-CA im TPM 2.0 und erhält dafür Gruppenzugriff auf das Geräte-Sonderdatei, nicht eine Capability; der Unterschied ist wesentlich, weil Gruppenzugriff auf genau ein Gerät beschränkt ist und eine Capability auf eine Klasse von Operationen wirkt.

Jede systemd-Einheit für Dienste trägt den Namen `atrium-dienst-<ulid-kurz>.service` und wird vollständig erzeugt. Handänderungen an erzeugten Einheiten werden beim nächsten Abgleich überschrieben und erzeugen das Auditereignis "Abweichung korrigiert" (INV-02). Die Härtungsdirektiven (`NoNewPrivileges`, `ProtectSystem=strict`, `PrivateTmp`, `PrivateDevices`, seccomp-Profil, `RestrictAddressFamilies`) sind Teil der Erzeugungsvorlage und nicht je Dienst wählbar; Ausnahmen existieren nur als deklarierte Eigenschaft des Katalogeintrags und sind in der Konsole am Dienst sichtbar, siehe [Kapitel 15](15-dienste-software.md).

**Anforderungen**

- **R-05-05** — Kein Atrium-Prozess läuft mit der Benutzerkennung 0. Prüfbar: Prozesstabellenprüfung im Integrationstest über alle Knotenrollen; ein Treffer bricht den Test.
- **R-05-06** — atrium-core und jeder Konnektorprozess haben eine leere effektive Capability-Menge. Prüfbar: `CapEff` in `/proc/<pid>/status` ist 0; ein von 0 verschiedener Wert bricht den Test.
- **R-05-07** — Jede privilegierte Hilfseinheit lehnt jede Nachricht ab, die nicht ihrem deklarierten Operationsschema entspricht, und gibt dabei keinen Pfad, keinen Parameterinhalt und keinen Ausnahmeverlauf zurück. Prüfbar: schemaverletzender Zufallsdatentest über mindestens 10^6 Nachrichten ohne erfolgreiche Operation und ohne Informationspreisgabe in der Antwort.
- **R-05-08** — In atrium-core, atrium-node und den Hilfseinheiten existiert keine dynamische Codeausführung und keine Prozesserzeugung über eine Shell. Prüfbar: Bauprüfung auf Interpreterbindungen und auf Prozesserzeugung mit Shell-Argument; ein Treffer bricht den Bau.

## 5.4 Schnittstellen zwischen den Komponenten

| Paar | Protokoll | Transport | Authentisierung | Autorisierung | Fehlerverhalten |
|---|---|---|---|---|---|
| Browser bzw. atriumctl → Eingang | HTTP/2, HTTP/3 (RFC 9113, RFC 9114) | 443/tcp, 443/udp, TLS 1.3 (RFC 8446) | OIDC-Sitzung mit Passkey/MFA; Dienstkonten per mTLS oder Bearer Token (RFC 6750, RFC 9068) | Keine; der Eingang entscheidet nicht | fail-closed: ohne gültige Route Default-Deny; Antwort ohne Angabe interner Namen |
| Eingang → atrium-core API | HTTP/2 | 8400/tcp, Loopback bzw. Knoten-Overlay, TLS 1.3 | Gegenseitiges TLS mit Dienstzertifikat des Eingangs | Der Kern prüft das weitergereichte Sitzungstoken selbst und ignoriert jeden eingehenden Autorisierungs-Kopfzeileneintrag, den er nicht selbst gesetzt hat | fail-closed: 503 ohne Detail; der Eingang wiederholt nicht selbsttätig schreibende Aufrufe |
| atrium-core ↔ atrium-core | gRPC (Raft) | 8401/tcp, Knoten-Overlay, TLS 1.3 | Gegenseitiges TLS mit Knotenzertifikat | Mitgliedschaft in der aktuellen Konsenskonfiguration; ein nicht eingetragener Knoten wird abgewiesen | fail-closed: ohne Mehrheit ist der Sollzustand eingefroren (INV-04); Lesevorgänge laufen weiter |
| atrium-core → atrium-node | gRPC | 8402/tcp, Knoten-Overlay, TLS 1.3 | Gegenseitiges TLS mit Knotenzertifikat | Der Knoten erhält ausschließlich den für ihn bestimmten, signierten und versionierten Sollzustandsauszug (INV-03) | fail-static am Knoten: der letzte Auszug bleibt wirksam; ohne gültige Lease Selbstabschottung (INV-06) |
| atrium-node → atrium-core | gRPC, gegenläufig auf demselben Kanal | 8402/tcp | wie oben | Der Knoten darf ausschließlich Istzustand zu Objekten melden, die ihm zugewiesen sind | Verdichtete Meldung; verlorene Meldungen führen zu einem veralteten Beobachtungszeitpunkt, nie zu einer Sollzustandsänderung (INV-28) |
| atrium-core → Eingang | xDS und SDS über gRPC | 8404/tcp, TLS 1.3 | Gegenseitiges TLS | Der Eingang übernimmt vollständige, versionierte Schnappschüsse; Teilaktualisierungen existieren nicht | fail-static: bei Verbindungsverlust bleibt der letzte Schnappschuss aktiv; ein abgelehnter Schnappschuss wird verworfen, nicht teilweise angewandt |
| atrium-node → Konnektorprozess | gRPC, Vertrag `describe`/`observe`/`plan`/`apply`/`healthcheck` | Unix-Socket, kein Port | Dateisystemrechte; eigener Systembenutzer je Bindung | Der Socketpfad ist an genau eine Konnektorbindung gebunden; ein Prozess kann keine fremde Bindung bedienen | fail-closed: Teilerfolg wird je Zielsystem benannt (INV-12); `apply` trägt einen Idempotenzschlüssel (INV-07) |
| Konnektorprozess → Fremdsystem | produktabhängig, überwiegend HTTPS, LDAPS, SMTP | TLS 1.3, Netznamensraum mit Positivliste | Geheimnis aus der Bindung, nur als kurzlebige, auftragsgebundene Referenz | Positivliste aus der Konnektorbindung; jede andere Zieladresse ist nicht erreichbar (INV-21) | fail-closed mit benanntem Grund, Wiederholung nach Rückstaffelung; niemals stiller Abbruch |
| atrium-node → Hilfseinheit | typisierte Nachrichten über gRPC | Unix-Socket | Dateisystemrechte | Festes Operationsschema je Einheit | fail-closed auf Default-Deny; abgebrochener Regelsatztausch fällt auf den vorherigen Satz zurück (INV-10) |
| atrium-core → Protokollkopf | HTTPS, lokal | Loopback bzw. Unix-Socket, TLS 1.3 | Gegenseitiges TLS | Nur der Reconciler schreibt; die produkteigene Verwaltungsoberfläche ist abgeschaltet (INV-30) | Abweichung wird sichtbar gemeldet; der Kern gewinnt jede Abweichung |
| atrium-core → autoritativer DNS | Steuerschnittstelle, transaktional | Unix-Socket | Dateisystemrechte | Vollständige Zoneninstanz je Transaktion; die Seriennummer ist die Sollzustandsversion | fail-static: bei fehlgeschlagener Transaktion bleibt die vorherige Zoneninstanz aktiv |
| Knoten im Wartemodus → Ankerknoten | HTTPS mit SPAKE2 (RFC 9382) | 8403/tcp, nur lokales Netzsegment, TLS 1.3 mit Exporter-Kanalbindung | Kopplungscode, nicht Zertifikat | Einmalig, ≤ 15 min, ≤ 5 Fehlversuche (INV-27) | fail-closed: nach dem fünften Fehlversuch ist der Code vernichtet und der Vorgang auditiert |
| atrium-node → atrium-core (Telemetrie) | OTLP über gRPC | 8408/tcp, TLS 1.3 | Gegenseitiges TLS mit Knotenzertifikat | Nur eigene Metriken und Spuren | Verwerfend: Telemetrieverlust hat keine Rückwirkung auf Soll- oder Istzustand |
| `atrium-audit` → externe Senke | Syslog (RFC 5424) | 6514/tcp, TLS 1.3 | Gegenseitiges TLS | Nur anhängen | Lokale Pufferung; bei anhaltendem Ausfall der lokalen Auditsenke verweigert der Kern schreibende Operationen |

Zwei Fehlermodelle reichen aus und sind je Schnittstelle deklariert: **fail-closed** für alles, was eine Berechtigung, eine Erreichbarkeit oder eine Änderung herstellt, und **fail-static** für alles, was einen bereits erreichten, geprüften Zustand aufrechterhält. Eine dritte Möglichkeit, etwa das stille Weiterarbeiten mit einem teilweise angewandten Stand, existiert nicht und ist der eigentliche Gehalt von INV-10 und INV-25. Die Unterscheidung erklärt auch, warum der Eingang seinen Schnappschuss behält, während der Kern ohne Mehrheit nichts mehr schreibt: Erreichbarkeit ist erhaltender Zustand, Sollzustandsänderung ist herstellender Zustand.

**Anforderungen**

- **R-05-09** — Der Eingang ist kein Autorisierungspunkt. Prüfbar: ein Aufruf, der eine vom Eingang hinzugefügte Rollenangabe mitführt, wird von atrium-core ignoriert; der Test setzt eine gefälschte Angabe und erwartet dieselbe Entscheidung wie ohne sie.
- **R-05-10** — Auf 8401, 8402, 8404 und 8408 scheitert jeder Verbindungsversuch ohne gültiges Clientzertifikat aus der Ausgabe-CA. Prüfbar: Verbindungstest ohne Zertifikat und mit fremdem Zertifikat; ein erfolgreicher Handschlag bricht den Test.
- **R-05-11** — Jede Schnittstelle ist im Schnittstellenkatalog als fail-closed oder fail-static deklariert, und das Verhalten wird durch Fehlerinjektion nachgewiesen. Prüfbar: je Katalogzeile ein Injektionstest; ein abweichendes Verhalten bricht den Test.

## 5.5 Bootstrap des Ankerknotens

Die zirkuläre Abhängigkeit lautet: die API braucht TLS, TLS braucht ein Zertifikat, das Zertifikat braucht die Ausgabe-CA, jede Ausstellung ist ein Ereignis im replizierten Protokoll, das Protokoll braucht eine konstituierte Konsensgruppe, und die Konsensgruppe braucht eine authentisierte Knotenidentität, die wiederum ein Zertifikat ist. Der Kreis wird nicht innerhalb des Systems geschlossen, sondern von außen aufgebrochen: der erste Vertrauensanker ist die Herstellersignatur des Systemabbilds, geprüft durch UEFI Secure Boot und dm-verity und in das TPM 2.0 gemessen. Dieser Anker liegt außerhalb des laufenden Systems und ist deshalb nicht Teil des Kreises.

Der zweite Aufbruchpunkt ist die erste Bedienerverbindung. Sie wird **nicht durch das Serverzertifikat** authentisiert, sondern durch den Kopplungscode über SPAKE2 (RFC 9382), gebunden an den TLS-Exporter der Verbindung. Damit ist das selbstsignierte Bootstrapzertifikat des frisch installierten Knotens sicherheitstechnisch belanglos: ein Angreifer, der die Verbindung übernimmt, besteht die SPAKE2-Bestätigung nicht, weil er den Code nicht kennt, und jeder Rateversuch kostet einen zählbaren Online-Lauf (K-14).

| Schritt | Zeitpunkt | Vorgang | Ergebnis | Zielwert |
|---|---|---|---|---|
| 1 | t=0 | Einschalten, UEFI Secure Boot prüft den Startlader, dm-verity prüft die aktive Abbildhälfte, Messungen in das TPM | Ausgeführter Code entspricht dem signierten Abbild | — |
| 2 | t+6 min | Unbeaufsichtigte Installation vom Abbild, Anlage der ZFS-Pools, Zeitgüteprüfung über NTS gegen mindestens zwei Quellen | Knoten lauffähig, Uhrenabweichung ≤ 500 ms belegt (INV-32) | K-01 |
| 3 | — | atrium-node erzeugt ein Schlüsselpaar im TPM und ein selbstsigniertes Bootstrapzertifikat mit Restlaufzeit ≤ 60 min, gültig nur für Loopback und 8403 | Transportsicherung für den Kopplungsendpunkt | — |
| 4 | — | Wartemodus: nur 8403 offen, Kopplungscode auf der physischen oder Fernkonsole angezeigt, Gültigkeit 15 min | Ein Zustand, zwei mögliche Ausgänge: Aufnahme durch einen bestehenden Ankerknoten oder Konstituierung als Ankerknoten | INV-27 |
| 5 | — | Der Bediener öffnet die lokale Adresse, gibt den Code ein, SPAKE2 läuft gegen den TLS-Exporter | Authentisierter Kanal ohne vertrauenswürdige PKI | K-14 |
| 6 | — | atrium-core legt sein Dataset an, öffnet ein leeres hashverkettetes Änderungsprotokoll und konstituiert eine Konsensgruppe mit genau einem Stimmmitglied | Schreibvorgänge sind möglich, Quorum = 1 (INV-05) | — |
| 7 | t+9 min | Ersteinrichtung: Sprache, Mandantenname, Administrator, Basisdomäne | Höchstens vier Entscheidungen | K-01 |
| 8 | — | Wurzel-CA wird erzeugt, signiert die Ausgabe-CA, der Wurzelschlüssel wird auf ein externes Medium exportiert und aus dem laufenden System entfernt; die Einrichtung schließt nicht ab, bevor der Export bestätigt ist | Zweistufige PKI, Wurzel offline (INV-22) | ≤ 30 s |
| 9 | — | Ausgabe-CA-Schlüssel in das TPM gebunden; je Mandant eine Zwischen-CA; jede Ausstellung wird als Ereignis protokolliert | PKI betriebsbereit | — |
| 10 | — | atrium-node beantragt ein reguläres Knotenzertifikat; das Bootstrapzertifikat wird gesperrt und seine Seriennummer auf die Sperrliste gesetzt | Ab hier ausschließlich gegenseitig authentisiertes TLS | — |
| 11 | — | Dienstzertifikat für den Konsolennamen; Zustellung an den Eingang über SDS, Listener und Route über xDS | Die API hat reguläres TLS; 8400 bleibt knotenlokal | K-17 |
| 12 | — | Knot DNS erhält die interne Zoneninstanz der Basisdomäne, signiert; der Resolver erhält die interne Sicht | Der Konsolenname ist intern auflösbar | K-17 |
| 13 | — | Protokollkopf erhält Zertifikat und das erste Konto: den Administrator aus Schritt 7, versorgt durch den Reconciler | Anmeldung möglich | — |
| 14 | — | Wiederherstellungscode mit 256 bit Entropie wird erzeugt, angezeigt und ausschließlich als Verifikationswert gespeichert | Notzugang ohne Hintertür | — |
| 15 | t+11 min | Umleitung vom Bootstrapkanal auf den regulären Namen, erste Anmeldung mit Passkey | Ankerknoten produktiv, "Redundanz: keine" dauerhaft sichtbar (INV-18) | K-01 |

Zwei Stellen dieses Ablaufs sind Kompromisse und werden als solche benannt. Erstens existiert der private Wurzelschlüssel in Schritt 8 für die Dauer der Signatur im Arbeitsspeicher eines laufenden Systems; er wird in gesperrten Speicherseiten gehalten, nie in einen Auslagerungsbereich geschrieben und in einer eigenen, kurzlebigen Einheit erzeugt, aber die theoretisch saubere Variante ist die Erzeugung auf einer getrennten, niemals vernetzten Maschine mit Import ausschließlich des Ausgabe-CA-Zertifikats. Diese Variante kostet die Zusage aus K-01 und wird deshalb als dokumentierte Empfehlung für regulierte Umgebungen und für die Isolationsstufen M2 und M3 geführt, nicht als Vorgabe.

Zweitens kennt der Browser eines unverwalteten Geräts die interne Wurzel-CA nicht. Es gibt dafür keine Lösung, die ohne eine der drei Möglichkeiten auskommt: Installation des Vertrauensankers, ein öffentlich vertrauenswürdiges Zertifikat über ACME (RFC 8555) für eine extern delegierte Basisdomäne, oder ein einmalig akzeptierter Zertifikatshinweis. Der Entwurf wählt ACME, wo die Basisdomäne extern delegiert und erreichbar ist, und andernfalls die ausdrückliche, in der Konsole erklärte Installation des Vertrauensankers; die dritte Möglichkeit wird nicht angeboten, weil sie den Bediener trainiert, Zertifikatswarnungen wegzuklicken. Details zur PKI stehen in [Kapitel 11](11-pki.md), zur Kopplung weiterer Knoten in [Kapitel 16](16-cluster.md).

**Anforderungen**

- **R-05-12** — Die erste Bedienerverbindung eines frisch installierten Knotens wird durch den Kopplungscode authentisiert, nicht durch das Serverzertifikat. Prüfbar: ein Zwischenangriff mit eigenem, gültig aussehendem Zertifikat scheitert an der SPAKE2-Bestätigung.
- **R-05-13** — Jedes im Bootstrap erzeugte Zertifikat trägt eine Restlaufzeit ≤ 60 min und ist nach Abschluss der Ersteinrichtung gesperrt. Prüfbar: die Sperrliste enthält die Seriennummer des Bootstrapzertifikats unmittelbar nach Schritt 10.
- **R-05-14** — Nach Abschluss der Ersteinrichtung existiert kein privater Wurzelschlüssel im laufenden System und in keiner Sicherung. Prüfbar: Inhaltsprüfung aller Datasets und aller Wiederherstellungspunkte gegen Schlüsselmuster; ein Treffer bricht den Bau (INV-22).
- **R-05-15** — Die Ersteinrichtung verlangt höchstens vier Entscheidungen und endet mit einer angemeldeten Sitzung. Prüfbar: automatisierter Durchlauf gegen die maschinenlesbare Aufgabendefinition; ein zusätzliches Pflichtfeld bricht den Bau (INV-14, K-01).

## 5.6 Datenfluss des Vorgangs "Nutzer anlegen"

```
 Bediener                                                     Fremdsystem
    |  2 Entscheidungen: Anzeigename, Gruppe/Rollen (K-03)     (z. B. Znuny)
    v                                                                ^
 [S6 Console] --HTTPS 443--> [Eingang] --8400 mTLS--> [atrium-core]   |
                                                          |          |
   (1) Schemavalidierung am Rand: unbekanntes Feld -> 400 |          |
   (2) Autorisierung + Mandantenpraedikat (INV-19)        |          |
   (3) Vorgang wird angelegt  ------------------> RAFT-APPEND  [Q]   |
   (4) Wirkungsvorschau: plan() je Bindung, nebenwirkungsfrei (INV-08)
       Ergebnis in das lokale Lesemodell, NICHT repliziert (Istzustand)
   (5) Freigabe, falls die Rolle Freigabepflicht traegt --> RAFT [Q]  |
   (6) Sollzustandsaenderung: Person + Zuweisung --------> RAFT [Q]   |
            |                                                        |
            +--> commit --> deterministische Materialisierung        |
                            in das Lesemodell je Verwaltungsknoten   |
                            (kein Quorum, jeder Knoten rechnet gleich)
            |                                                        |
   (7) Reconciler leitet ab: Fremdkonto, Gruppenmitgliedschaft,      |
       ggf. Mailadresse, ggf. Zertifikat  (Abgeleitete Artefakte)    |
            |                                                        |
   (8) Arbeitsauftrag mit Idempotenzschluessel                       |
       = H(Vorgang-ULID | Zielsystem | Objekt-ULID)   (INV-07)       |
            |                                                        |
            v  8402/tcp, mTLS, signierter Auszug                     |
        [atrium-node]                                                |
            |  Unix-Socket, eigener Systembenutzer                   |
            v                                                        |
        [atrium-connector-znuny]  --Positivliste, TLS 1.3------------+
            |
   (9) apply() -> Ergebnis je Zielsystem
            |
            v  8402 zurueck
        [atrium-core] --> RAFT-APPEND des Zielsystemzustands  [Q]
            |
  (10) Vorgang: "wirksam" oder "teilweise fehlgeschlagen" mit
       benanntem Rest je Zielsystem (INV-12)
            |
            +--> [atrium-audit]  person.angelegt, zuweisung.gesetzt,
                 hashverkettet, ausserhalb des replizierten Zustands (INV-23)
            |
            v
 Bediener sieht je Zielsystem: versorgt / in Arbeit / Grund
 Zielwert p50 <= 5 s, p95 <= 60 s, harte Obergrenze 15 min (K-15)

 [Q] = Mehrheit erforderlich
```

Der Bediener trifft zwei Entscheidungen; alles Weitere folgt aus Richtlinie und Objektgraph, und jede Vorbelegung trägt ihre benannte Quelle (INV-15). Der Schalter "Ticketsystem" am Nutzer erzeugt keine Sonderlogik, sondern genau eine Zuweisung; die Versorgung in Znuny ist ihre abgeleitete Wirkung, während die fachliche Bedienung von Znuny in Znuny bleibt (INV-30). Das Verfahren ist für jedes Zielsystem identisch, weil der Konnektorvertrag identisch ist; siehe [Kapitel 09](09-konnektoren.md).

## 5.7 Vertrauensgrenzen

```
 +---------------------------------------------------------------------+
 | T0  AUSSENWELT: Internet, unverwaltete Geraete, Fremdsysteme        |
 +---------------------------------------------------------------------+
        |  443/tcp+udp, 80/tcp, 53, 25/tcp   ---- keine Vertrauensannahme
        v
 ##### Grenze A: Terminierung. Ueberquert: TLS 1.3, OIDC-Sitzung.      #
 #####            Traegt: nichts ausser dem Sitzungstoken.             #
        v
 +---------------------------------------------------------------------+
 | T1  AUSSENKANTE: Eingang, autoritativer DNS, ACME/OCSP-Endpunkte    |
 |     Fremdcode mit Netzkontakt. Kein Zustand ausser dem Schnappschuss.|
 |     Annahme: kompromittierbar. Deshalb kein Datenbankzugriff, keine  |
 |     Autorisierungsentscheidung, kein Schluesselmaterial ausser den   |
 |     ueber SDS zugestellten Dienstzertifikaten.                       |
 +---------------------------------------------------------------------+
        |  8400/tcp mTLS
        v
 ##### Grenze B: Entscheidung. Ueberquert: authentisierter Aufruf.     #
 #####            Hier und nur hier faellt jede Autorisierung.         #
        v
 +---------------------------------------------------------------------+
 | T2  KONTROLLEBENE: atrium-core auf 1, 3 oder 5 Stimmknoten          |
 |     Haelt Sollzustand, Ausgabe-CA (Schluessel im TPM), SCIM, xDS.    |
 |     Keine Capability. Kein ausgehender Internetzugang.               |
 +---------------------------------------------------------------------+
        |  8401 zwischen Stimmknoten          |  8402 zu Knoten
        |  (Grenze C: Konsens. Ueberquert:    |  (Grenze D: Weisung.
        |   Protokolleintraege. Mitgliedschaft|   Ueberquert: signierter,
        |   ist die Autorisierung.)           |   versionierter Auszug.)
        v                                     v
 +--------------------------+   +--------------------------------------+
 | T2' WEITERE STIMMKNOTEN  |   | T3  KNOTEN: atrium-node, Dienste     |
 | gleiches Vertrauensmass  |   |     Vertraut dem Kern, nicht umgekehrt|
 +--------------------------+   +--------------------------------------+
                                        |  Unix-Socket, Dateisystemrechte
                                        v
 ##### Grenze E: Auslagerung. Ueberquert: Auftrag + kurzlebige         #
 #####            Geheimnisreferenz. Nie: Sollzustand, nie Schluessel. #
                                        v
                               +--------------------------------------+
                               | T4  KONNEKTORSANDKASTEN              |
                               |     Eigener Benutzer, eigener Netz-  |
                               |     namensraum, Ausgangs-Positivliste|
                               |     Annahme: kompromittierbar durch  |
                               |     das Fremdsystem, das er bedient. |
                               +--------------------------------------+
                                        |  nur gelistete Zieladressen
                                        v
                                     zurueck nach T0 (Fremdsystem)

 +---------------------------------------------------------------------+
 | T5  AUSSERHALB DES SYSTEMS: Wurzel-CA-Schluessel, Wiederherstellungs-|
 |     code, Abbildsignaturschluessel des Herstellers.                  |
 |     Wird nie gesichert, nie repliziert, nie ueber das Netz bewegt.   |
 |     Genau hier bricht die Bootstrap-Zirkularitaet auf.               |
 +---------------------------------------------------------------------+
```

T1 und T4 sind unter der Annahme entworfen, dass sie fallen. Der Eingang hält kein Geheimnis außer den ihm zugestellten Dienstzertifikaten und trifft keine Autorisierungsentscheidung; ein übernommener Eingang kann Verkehr sehen und stören, aber keinen Sollzustand ändern und keine Rolle erweitern. Ein übernommener Konnektorprozess kann das eine Fremdsystem erreichen, für das er eingerichtet ist, und sonst nichts, weil die Ausgangs-Positivliste aus seiner Bindung erzeugt wird und er die Kern-API über das Netz nicht erreicht (INV-21).

Die Richtung des Vertrauens über Grenze D ist einseitig und wird oft falsch entworfen: ein Knoten vertraut dem Kern, der Kern vertraut keinem Knoten. Deshalb ist jede Istmeldung eine Beobachtung mit Zeitpunkt und niemals Eingabe für eine Entscheidung außer für die Abweichungsanzeige, und deshalb erhält ein Knoten nur den für ihn bestimmten Auszug statt des Gesamtzustands.

**Anforderungen**

- **R-05-16** — Ein Konnektorprozess kann keine Adresse außerhalb der aus seiner Bindung erzeugten Positivliste erreichen und die Kern-API nicht über das Netz ansprechen. Prüfbar: Netznamensraumtest mit Zugriffsversuch auf eine nicht gelistete Adresse und auf 8400; beide Versuche scheitern.
- **R-05-17** — Der Eingang besitzt kein Geheimnis, mit dem sich eine Sollzustandsänderung auslösen ließe. Prüfbar: Durchsuchung des Prozessspeichers und aller ihm zugänglichen Pfade nach Schlüsselmaterial außerhalb der über SDS zugestellten Dienstzertifikate.
- **R-05-18** — Istzustandsmeldungen eines Knotens verändern den Sollzustand nicht. Prüfbar: Injektion einer manipulierten Istmeldung; der Sollzustand bleibt unverändert, und es entsteht ein Abweichungsereignis.

## 5.8 Schreibpfad und Lesepfad

Der Schreibpfad ist konsenspflichtig, der Lesepfad nicht. Diese Trennung ist der Grund, weshalb ein System ohne Mehrheit weiter bedienbar bleibt, obwohl es nichts mehr ändert.

| Schritt des Schreibpfads | Ort | Mehrheit nötig | Begründung |
|---|---|---|---|
| Schemavalidierung, Ablehnung unbekannter Felder | Kern, annehmender Knoten | nein | Reine Eingangsprüfung, kein Zustand |
| Autorisierung und Mandantenprädikat | Kern | nein | Entscheidung aus dem lokalen Lesemodell |
| Anlage des Vorgangs | Raft-Log | **ja** | Der Vorgang trägt den Freigabestatus; eine Freigabe, die bei einem Führungswechsel verloren gehen kann, ist wertlos |
| Wirkungsvorschau (`plan` je Bindung) | Konnektoren über atrium-node | nein | Nebenwirkungsfrei (INV-08); das Ergebnis ist eine datierte Beobachtung und gehört in das Lesemodell, nicht in den Konsens |
| Freigabe | Raft-Log | **ja** | Wie Vorgangsanlage |
| Sollzustandsänderung | Raft-Log | **ja** | INV-04 |
| Materialisierung in das Lesemodell | jeder Verwaltungsknoten | nein | Deterministische Funktion des bestätigten Protokolls; jeder Knoten rechnet dasselbe Ergebnis |
| Ableitung der abgeleiteten Artefakte | Reconciler auf dem Führer | mittelbar | Nur der Führer besitzt die Lease; die Lease setzt Mehrheit voraus |
| Zustellung des Auszugs an den Knoten | 8402 | nein | Der Auszug ist signiert und versioniert; der Knoten prüft die Signatur |
| Konnektoraufruf `apply` | Knoten, Unix-Socket | nein | Idempotenzschlüssel macht Wiederholung wirkungsfrei (INV-07) |
| Rückschreiben des Zielsystemzustands | Raft-Log | **ja** | Der Zustand je Zielsystem ist Attribut der Zuweisung und damit Sollzustandsbestandteil |
| Unveränderte Beobachtung im Volllauf | lokaler Istspeicher | nein | Ohne Änderung entsteht kein Protokolleintrag; andernfalls flutet der Driftpfad den Konsens |
| Auditereignis | `atrium-audit` | nein | Audit liegt außerhalb des replizierten Kernzustands (INV-23) |

Der Lesepfad läuft ausschließlich gegen das lokal materialisierte, indizierte Lesemodell des Knotens, der die Anfrage terminiert; es gibt keine Konsensrunde je Leseanfrage. Daraus folgt die Zusage aus K-18 (Erstanzeige p95 ≤ 300 ms bei 10.000 Objekten) und zugleich das Risiko eines veralteten Standes. Der Entwurf schließt dieses Risiko nicht durch stärkere Konsistenz, sondern durch eine Sitzungsgarantie: jede Antwort trägt den angewandten Protokollindex, jede Folgeanfrage derselben Sitzung führt den zuletzt gesehenen Index mit, und ein zurückliegender Knoten wartet bis zum Erreichen dieses Index oder antwortet mit einem benannten Fehler.

- **Zielwert Wartezeit:** ≤ 500 ms, danach benannter Fehler mit Wiederholungsangebot in der Oberfläche.
- **Rechnung:** Die Verzögerung zwischen Bestätigung und lokaler Materialisierung ist bei gesundem Netz durch eine Protokollrunde und die Materialisierungszeit begrenzt. Annahme: Heartbeat 250 ms für die Konsensgruppe, Materialisierung eines Einzelobjekts ≤ 5 ms. Zielwert Verzögerung ≤ 250 ms, Wartebudget 500 ms entspricht Faktor 2. Das ist ein Modell, keine Messung.
- **Wirkung:** Der Fall "Nutzer angelegt, erscheint nicht in der Liste" ist ausgeschlossen, ohne dass Listenabfragen Konsens kosten.

Lesevorgänge, Istansichten und Auditabfragen funktionieren im eingefrorenen Zustand vollständig. Die Konsole zeigt dann dauerhaft an, dass keine Änderungen möglich sind, und benennt die Ursache am Objekt statt in einem Bericht (INV-18).

**Anforderungen**

- **R-05-19** — Im eingefrorenen Zustand beantwortet die API jede lesende Anfrage und lehnt jede schreibende Anfrage mit benannter Ursache ab. Prüfbar: Partitionstest auf der Minderheitsseite über den vollständigen Aufgabenkatalog.
- **R-05-20** — Eine Leseanfrage derselben Sitzung liefert niemals stillschweigend einen Stand vor der eigenen bestätigten Änderung. Prüfbar: Schreib-Lese-Test gegen einen künstlich verzögerten Verwaltungsknoten; entweder korrekter Stand oder benannter Fehler, kein dritter Ausgang.
- **R-05-21** — Ein Reconciler-Volllauf ohne festgestellte Abweichung erzeugt keinen Protokolleintrag und kein Auditereignis vom Typ "geändert". Prüfbar: Doppellauf im Bau; ein zweites Änderungsereignis bricht den Bau (INV-07).

## 5.9 Skalengrenzen

Alle Werte sind Zielwerte und Rechenergebnisse aus benannten Annahmen, keine Messungen.

| Größe | Grenze | Herleitung | Bindende Ursache |
|---|---|---|---|
| Knoten je Installation | 32 | Vollvermaschung WireGuard: 32 · 31 / 2 = 496 Tunnel, je Knoten 31 Gegenstellen; Protokollverteilung des Führers an 31 Empfänger bei angenommenen 200 Änderungen je Tag à 2 KB ergibt 200 · 2 KB · 31 = 12,4 MB/Tag = 0,14 kB/s und ist damit nicht bindend | Nicht das Protokoll, sondern die Platzierungsrechnung (K-21) und die Überschaubarkeit für den Bediener; die Grenze ist gesetzt, nicht gemessen |
| Stimmknoten | 1, 3 oder 5 | Verfügbarkeitsrechnung in K-04: 4 Stimmknoten sind mit 0,999408 schlechter als 3 mit 0,999702 | INV-05 |
| Dienstinstanzen | 500 | 32 · 500 = 16.000 Bewertungspaare je Neuberechnung; Zielwert vollständige Neuberechnung ≤ 200 ms entspricht 200 ms / 16.000 = 12,5 µs je Paar | K-21; erfordert eine Bewertungsfunktion ohne Speicheranforderung je Paar, was eine Entwurfsauflage und kein Nebenprodukt ist |
| Objekte im Sollzustand | 15.000 typisch, ≤ 50 MB serialisiert | 15.000 · 2 KB = 30 MB; Lesemodell mit Indizes bei Faktor 3 ergibt 90 MB und damit 2,25 % des 4-GB-Budgets aus K-19 | Nicht der Speicher |
| Abgleichpflichtige Objekte | 7.200 | N_max = T_ziel · P / t_obj = 3.600 s · 4 / 2 s = 7.200 bei Volllaufziel 60 min, Parallelität 4 und angenommenen 2 s Prüfzeit je Objekt | **Bindend.** Der Volllauf als Driftpfad, nicht die Speichergröße (K-16) |
| Konnektoraufrufe je Minute und Verwaltungsknoten | Zielwert 600 | 32 gleichzeitige Konnektorprozesse (K-20) · (60 s / 3 s je Zielsystemaufruf, K-15) = 640; Zielwert 600 mit Reserve | In der Praxis eher die Ratenbegrenzung der Fremd-API als die eigene Kapazität |
| Bestätigte Sollzustandsänderungen je Sekunde | Zielwert ≥ 50 | Bedarf: 600 Aufrufe/min = 10/s, jeder mit einem Rückschreibvorgang, zuzüglich ≤ 1/s Bedieneränderungen ergibt ≤ 11/s; Zielwert 50/s entspricht Faktor 4,5 | Schreibverstärkung durch Rückschreiben des Zielsystemzustands |
| Dauerprozesse je Arbeitsknoten | ≤ 3 | atrium-node, optional Eingangsinstanz, optional Konnektorpool | K-20 |

Die bindende Grenze der Architektur ist nicht der Speicher und nicht der Konsens, sondern der periodische Volllauf des Reconcilers. Bei 7.200 abgleichpflichtigen Objekten ist das Volllaufziel von 60 Minuten erreicht; darüber hinaus gibt es genau drei Auswege, und jeder kostet etwas: Parallelität erhöhen (zusätzliche gleichzeitige Konnektorprozesse, begrenzt durch K-20 und durch Arbeitsspeicher), Volllaufintervall verlängern (schwächere Drifterkennung, längere Zeit bis zur Korrektur einer Handänderung) oder die Prüfzeit je Objekt senken (setzt voraus, dass Fremdsysteme Sammelabfragen anbieten, was nicht durchgängig zutrifft). Die Entscheidung ist nicht getroffen.

Die Architektur setzt die Knotengrenze durch und die Objektgrenze nicht. Die Aufnahme eines dreiunddreißigsten Knotens wird abgelehnt, weil eine Überschreitung die Platzierungszusage und die Vermaschung betrifft und weil eine Ablehnung dem Bediener eine klare Aussage gibt. Eine Ablehnung des 7.201. Objekts wäre dagegen eine willkürliche Betriebsstörung; stattdessen berechnet die Konsole die daraus folgende Volllaufdauer und zeigt sie als degradierten Zustand an (INV-18).

**Anforderungen**

- **R-05-22** — Die Aufnahme eines Knotens über die deklarierte Höchstzahl hinaus wird mit benannter Begründung abgelehnt. Prüfbar: Kopplungsversuch bei erreichter Höchstzahl; der Vorgang endet mit Ablehnung und Auditereignis, nicht mit einem Teilzustand.
- **R-05-23** — Überschreitet die Zahl abgleichpflichtiger Objekte die für das Volllaufziel berechnete Grenze, zeigt die Konsole die neu berechnete Volllaufdauer als degradierten Zustand an und lehnt keine Objektanlage ab. Prüfbar: Lasttest mit Objektzahl über der Grenze; die angezeigte Dauer entspricht N · t_obj / P.
- **R-05-24** — Kontrollebene, Eingang, autoritativer DNS, Resolver und Protokollkopf zusammen belegen im Nennbetrieb ≤ 4 GB Hauptspeicher und ≤ 2 Kerne. Prüfbar: Lastmessung auf einem Ankerknoten mit 500 Personen, 150 Diensten und 500 veröffentlichten Namen (K-19).

## 5.10 Was bewusst nicht Teil der Architektur ist

| Ausgeschlossen | Begründung |
|---|---|
| Ein Container-Orchestrierer als verdeckte Unterschicht | Er bringt ein zweites Quorum, eine zweite PKI, ein zweites Netzmodell und einen zweiten DNS-Dienst mit; seine Begriffe schlagen bei jedem Fehler in die Oberfläche durch und brechen INV-16 |
| Eine externe Datenbank für die Kontrollebene | Die Kontrollebene wäre von einem Dienst abhängig, dessen eigener Ausfallschutz wiederum ein Quorum braucht; das ist genau die Zirkularität, die der eingebettete Konsensspeicher vermeidet |
| Ein Nachrichtenvermittler zwischen den Komponenten | Das Änderungsprotokoll ist bereits eine geordnete, dauerhafte Warteschlange; ein zweiter dauerhafter Puffer wäre eine zweite Wahrheitsquelle mit eigener Sicherung und eigenem Wiederanlaufverfahren |
| Ein Erweiterungsmechanismus im Kern (Module, Skripte, dynamische Auswertung) | Ein Modul im Kernprozess teilt dessen Fehlerdomäne, dessen Netzrechte und dessen Zugriff auf Schlüsselmaterial; der Konnektorvertrag als eigener Prozess leistet dasselbe mit einer Prozessgrenze dazwischen |
| Eine Vorlagen- oder Konfigurationsdateischicht | Eine zweite Autorenfläche neben dem Sollzustand widerspricht INV-02 und INV-03 unmittelbar; erzeugte Dateien sind Ausgabe, nie Eingabe |
| Eine Ablaufsteuerung mit eigener Skriptsprache für Freigaben | Freigaben sind ein Zustandsautomat am Vorgang; eine Skriptsprache im Freigabeweg wäre ausführbarer Code in der Kontrollebene und damit ein Rechteausweitungspfad |
| Ein eigener L7-Proxy oder ein eigener TLS-Stapel | Eine unauthentisiert erreichbare Eigenentwicklung an der Außenkante ist eine dauerhafte Verwundbarkeitsfläche ohne Produktvorteil |
| Eigene Kernelmodule und eigene Treiber | Der einzige belastbare Vorteil der Ubuntu-Basis ist die fremdgepflegte Treiber- und Sicherheitsversorgung; jede eigene Kernelvariante gibt ihn auf, siehe [Kapitel 06](06-basis-ubuntu.md) |
| Eine über Weitverkehrsstrecken gedehnte Konsensgruppe als unterstützte Aufstellung | Lease 20 s, Übernahme ab 30 s und zugelassene Uhrenabweichung ≤ 500 ms (K-07) sowie die Schreibpause ≤ 5 s (K-05) setzen lokale Laufzeiten voraus; eine gedehnte Gruppe verletzt diese Zusagen still. Für zwei Standorte gibt es den Zeugen, für mehrere Regionen getrennte Installationen |
| Mandantenübergreifende Ressourcenteilung ohne ausdrückliche Verknüpfung | Jede Abfrage trägt ein Mandantenprädikat (INV-19); eine implizite Teilung wäre eine nicht nachweisbare Vermischung |
| Ein Herstellerfernzugang | Ein dauerhafter Generalschlüssel ist ein Risiko, das kein Supportvorteil aufwiegt; der Notzugang ist physisch gebunden und auditiert |
| Eine zweite Bedienoberfläche neben der Konsole | INV-01; ein zweiter Weg erzeugt zwangsläufig Funktionen, die nur über einen der beiden Wege erreichbar sind, und damit genau die Unübersichtlichkeit, die das Produkt vermeiden soll |

Der letzte Punkt verdient eine Ergänzung, weil er häufig als Einschränkung gelesen wird: `atriumctl` ist kein zweiter Weg, sondern ein zweiter Aufrufer derselben Endpunkte. Es existiert kein Befehl, der eine Wirkung erzielt, die die Konsole nicht ebenfalls erzielen kann, und die Bauprüfung gegen eine Fassade, die nicht dokumentierte Endpunkte sperrt, macht das nachweisbar statt behauptet.

**Anforderungen**

- **R-05-25** — Die Atrium Console verwendet keinen Endpunkt, der nicht öffentlich dokumentiert ist. Prüfbar: Bau gegen eine API-Fassade, die alle nicht dokumentierten Endpunkte sperrt; ein Zugriff bricht den Bau (INV-01).
- **R-05-26** — Es existiert kein Prozess der Kontrollebene mit ausgehendem Internetzugang außer über eine Konnektorbindung oder den ACME-Ausgangspfad. Prüfbar: Netznamensraumtest mit Zugriffsversuch auf eine externe Adresse aus dem Kernprozess.

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Das gebaute atrium-core-Artefakt enthält keine Bindung an Container-, netlink-, D-Bus- oder ZFS-Bibliotheken | R-05-01 | Symbolprüfung im Bau; ein Treffer bricht den Bau |
| Jede im Integrationstest beobachtete Verbindung zwischen Atrium-Prozessen ist im Schnittstellenkatalog eingetragen | R-05-02 | Verbindungsmitschnitt gegen Katalogabgleich |
| 1.000 aufeinanderfolgende Routenänderungen laufen ohne Prozessneustart des Eingangs und ohne Verbindungsabbruch | R-05-03, R-05-04 | Dauerlasttest mit fortlaufenden Verbindungen (K-17) |
| Kein Prozess mit Benutzerkennung 0 auf Ankerknoten, Verwaltungsknoten und Arbeitsknoten | R-05-05 | Prozesstabellenprüfung je Knotenrolle |
| `CapEff` von atrium-core und jedem Konnektorprozess ist 0 | R-05-06 | Auslesen von `/proc/<pid>/status` im Testlauf |
| 10^6 schemaverletzende Nachrichten an jede Hilfseinheit führen zu 0 erfolgreichen Operationen und 0 Antworten mit Pfad-, Parameter- oder Ausnahmeinhalt | R-05-07 | Zufallsdatentest je Hilfseinheit |
| Kein Interpreter und keine Shell-gestützte Prozesserzeugung in Kern, Knotenagent und Hilfseinheiten | R-05-08 | Bauprüfung auf Bindungen und Aufrufmuster |
| Ein Aufruf mit gefälschter, vom Eingang gesetzter Rollenangabe führt zur identischen Autorisierungsentscheidung wie ohne sie | R-05-09 | Manipulationstest am Eingang |
| Verbindungsversuche ohne Clientzertifikat und mit fremdem Zertifikat scheitern auf 8401, 8402, 8404 und 8408 | R-05-10 | Handschlagtest je Port |
| Jede Katalogzeile ist als fail-closed oder fail-static deklariert, und die Fehlerinjektion erzeugt genau das deklarierte Verhalten | R-05-11 | Injektionstest je Schnittstelle |
| Ein Zwischenangriff auf die erste Bedienerverbindung scheitert an der SPAKE2-Bestätigung | R-05-12 | Angriffstest mit eigenem Zertifikat (RFC 9382, K-14) |
| Die Sperrliste enthält die Seriennummer des Bootstrapzertifikats nach Abschluss der Ersteinrichtung; dessen Restlaufzeit lag nie über 60 min | R-05-13 | Sperrlistenprüfung und Zertifikatsauswertung |
| Keine Datei und kein Wiederherstellungspunkt enthält ein Wurzelschlüsselmuster | R-05-14 | Inhaltsprüfung aller Datasets und Sicherungen (INV-22) |
| Die Ersteinrichtung verlangt höchstens vier Entscheidungen und endet mit einer angemeldeten Sitzung | R-05-15 | Automatisierter Durchlauf gegen die Aufgabendefinition (K-01, INV-14) |
| Ein Konnektorprozess erreicht weder eine nicht gelistete Adresse noch 8400 | R-05-16 | Netznamensraumtest (INV-21) |
| Im Prozessspeicher des Eingangs findet sich kein Schlüsselmaterial außerhalb der über SDS zugestellten Dienstzertifikate | R-05-17 | Speicherabbildprüfung nach Lastlauf |
| Eine manipulierte Istmeldung ändert keinen Sollzustandswert und erzeugt ein Abweichungsereignis | R-05-18 | Injektionstest am Agentenkanal (INV-28) |
| Auf der Minderheitsseite einer Partition sind alle lesenden Aufgaben des Katalogs ausführbar und alle schreibenden mit benannter Ursache abgelehnt | R-05-19 | Partitionstest über den Aufgabenkatalog (INV-04) |
| Nach einer bestätigten Änderung liefert dieselbe Sitzung entweder den neuen Stand oder einen benannten Fehler, nie den alten Stand | R-05-20 | Schreib-Lese-Test gegen einen verzögerten Verwaltungsknoten |
| Ein zweiter Reconciler-Volllauf ohne Abweichung erzeugt 0 Protokolleinträge und 0 Änderungsereignisse | R-05-21 | Doppellauf im Bau (INV-07) |
| Der Kopplungsversuch bei erreichter Knotenhöchstzahl endet abgelehnt und auditiert, ohne Teilzustand | R-05-22 | Kopplungstest an der Grenze |
| Bei Objektzahl über der Volllaufgrenze zeigt die Konsole die Dauer N · t_obj / P an und lehnt keine Objektanlage ab | R-05-23 | Lasttest mit Objektzahl über der Grenze (K-16) |
| Kontrollebene, Eingang, DNS, Resolver und Protokollkopf zusammen bleiben unter 4 GB Hauptspeicher und 2 Kernen | R-05-24 | Lastmessung auf einem Ankerknoten (K-19) |
| Der Bau gegen die API-Fassade meldet 0 Zugriffe der Konsole auf nicht dokumentierte Endpunkte | R-05-25 | Fassadenbau (INV-01) |
| Ein Zugriffsversuch aus dem Kernprozess auf eine externe Adresse außerhalb des ACME-Pfads scheitert | R-05-26 | Netznamensraumtest |

## Offene Punkte

1. **Erzeugungsort der Wurzel-CA.** Die Erzeugung im Arbeitsspeicher des laufenden Ankerknotens hält K-01 ein, setzt den privaten Wurzelschlüssel aber für die Dauer der Signatur einem laufenden System aus. Die Erzeugung auf einer getrennten, nie vernetzten Maschine vermeidet das vollständig, kostet aber die Zusage der Ersteinrichtung in einem Zug. Zu entscheiden ist, ob die Offline-Variante für M2 und M3 verpflichtend wird und wie die Konsole den Unterschied darstellt, ohne den Bediener zu überfordern.
2. **Grenze des Volllaufs.** Die Rechnung N_max = T_ziel · P / t_obj ergibt 7.200 abgleichpflichtige Objekte und hängt vollständig an der angenommenen Prüfzeit von 2 s je Objekt, für die kein Messwert vorliegt. Zu entscheiden ist, welcher der drei Auswege gewählt wird (höhere Parallelität gegen K-20 und Arbeitsspeicher, längeres Intervall gegen Drifterkennung, Sammelabfragen gegen Konnektorkomplexität) und ob die Grenze je Konnektorbindung statt global gilt.
3. **Ausführungsort der Konnektorprozesse.** Ab Isolationsstufe M1 hat ein Mandant eine eigene Ausgangsadresse, weshalb der Konnektor auf einem Knoten laufen muss, der diese Adresse trägt; ist das kein Verwaltungsknoten, kostet jeder Aufruf einen zusätzlichen Weg über den Agentenkanal. Ungeklärt ist die Semantik eines Knotenausfalls zwischen `apply` und Ergebnismeldung: der Idempotenzschlüssel macht die Wiederholung gefahrlos, aber der Zwischenzustand "unbekannt, ob das Fremdsystem geändert wurde" ist im Objektmodell bisher nicht darstellbar, und INV-12 verlangt eine benannte Aussage.
4. **Erstzugang von unverwalteten Geräten.** Ohne öffentlich vertrauenswürdiges Zertifikat und ohne installierten Vertrauensanker gibt es keinen Weg zur ersten Anmeldung, der nicht entweder von externer Erreichbarkeit der Basisdomäne oder von einer Handlung am Gerät abhängt. Zu entscheiden ist, ob eine Installation ohne externe Domäne den Vertrauensanker verpflichtend ausrollt, bevor die Einrichtung als abgeschlossen gilt, und wie das in [Kapitel 13](13-geraeteverwaltung.md) mit der Geräteregistrierung zusammengeführt wird.
5. **Vorgänge im eingefrorenen Zustand.** Der Entwurf legt Vorgänge in den konsenspflichtigen Zustand, damit eine Freigabe einen Führungswechsel überlebt. Die Folge ist, dass ohne Mehrheit nicht einmal eine Änderung vorbereitet werden kann, also gerade in der Störung, in der ein Bediener handeln will. Zu entscheiden ist, ob ein nicht replizierter Entwurfszustand mit ausdrücklicher Kennzeichnung "geht bei Knotenwechsel verloren" zulässig ist oder ob die strengere Variante bleibt.
6. **`CAP_SYS_ADMIN` in der Speicherhilfseinheit.** Das Einhängen eines Dateisystems verlangt diese Capability, die im Linux-Rechtemodell nahezu vollständiger Privilegierung entspricht. Ob ZFS-Delegation alle benötigten Operationen einschließlich Einhängen ohne diese Capability abdeckt, ist ungeklärt und muss auf der Zielbasis geprüft werden; fällt die Prüfung negativ aus, bleibt die Einheit die privilegierteste Stelle des Systems und muss in [Kapitel 20](20-sicherheit.md) gesondert behandelt werden.
7. **Schreibverstärkung durch Zielsystemzustände.** Jeder Konnektorerfolg erzeugt einen Konsenseintrag, weil der Zustand je Zielsystem Attribut der Zuweisung ist. Bei Massenvorgängen (Erstversorgung eines Mandanten mit mehreren hundert Personen und mehreren Zielsystemen) entstehen daraus Lastspitzen, für die kein Messwert vorliegt. Zu entscheiden ist, ob Zielsystemzustände gebündelt geschrieben werden und wie sich das mit der Anzeigepflicht je Zielsystem aus INV-12 verträgt.
