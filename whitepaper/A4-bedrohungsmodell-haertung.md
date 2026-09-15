# Anhang D: Bedrohungsmodell und Härtungsvorgaben

## A4.1 Geltung, Notation und Verhältnis zu Kapitel 20

Dieser Anhang führt das Tabellenwerk, auf das sich [Kapitel 20](20-sicherheit.md) argumentativ stützt. Er fügt dem dortigen Modell keine Bedrohung und keine Maßnahme hinzu, sondern die vier Spalten, die dort fehlen und ohne die eine Maßnahme nicht prüfbar ist: den konkreten Angriffspfad, das Erkennungssignal, den Verweis auf die abdeckende Invariante oder Anforderung und den benannten Eigentümer eines Schutzguts. Die Schutzbedarfsklassen `normal`, `hoch`, `sehr hoch` sind in 20.1 beobachtbar definiert und werden hier benutzt, nicht neu bestimmt.

| Notationselement | Bedeutung |
|---|---|
| `VG-01` … `VG-08` | Vertrauensgrenzen aus 20.3. Dieser Anhang führt keine weiteren ein; die Frage nach zwei zusätzlichen Grenzen steht unter "Offene Punkte". |
| `A-1` … `A-8` | Angreiferklassen aus 20.2. |
| `E-01` … `E-14` | Verbindliche Entwicklungsregeln aus 20.5. |
| `MF-01` … `MF-12` | Missbrauchsfälle dieses Anhangs. |
| Ereignisnamen | Auditereignistypen im Schema `<objekt>.<verb>` im Perfekt nach KANON.md, Abschnitt 3. Die hier genannten Namen sind Entwurfsstand; verbindlich ist das Schema, nicht die einzelne Zeichenkette. |
| Zähler | Laufzeitzähler des Betriebsprotokolls, nicht des Auditstroms. Ein Zähler ist kein Nachweis, sondern ein Signal. |

Rollenkürzel für die Eigentümerspalte stammen aus [Kapitel 19](19-mandanten-rechte-audit.md): PE Plattformeigner, MA Mandantenadministrator, DA Dienstadministrator, FG Freigeber, PR Prüfer. Eine Rolle ist Eigentümer eines Schutzguts, wenn sie den Schutzbedarf festlegt und die Folgen einer Absenkung trägt; sie ist nicht dadurch Eigentümer, dass sie Lesezugriff hat.

## A4.2 Wertetabelle

| Schutzgut | Ort | Vertraulichkeit | Integrität | Verfügbarkeit | Eigentümer | Pflicht des Eigentümers |
|---|---|---|---|---|---|---|
| Sollzustand | Änderungsprotokoll in atrium-core, Lesemodell je Verwaltungsknoten | hoch | sehr hoch | hoch | PE | Freigabewege und Stimmzahl festlegen; Exportintervall und Aufbewahrung nach K-25 bestätigen |
| Schlüsselmaterial der Wurzel-CA | offline, außerhalb des laufenden Systems (INV-22) | sehr hoch | sehr hoch | normal | PE | Aufbewahrungsort, Zwei-Personen-Regel und Nachfolgeplanung nach K-13 |
| Schlüsselmaterial der Ausgabe-CA | TPM 2.0 des Verwaltungsknotens, nicht exportierbar | sehr hoch | sehr hoch | hoch | PE | Nachfolger ab 2 a Restlaufzeit parallel verteilen (K-13) |
| Schlüsselmaterial der Mandanten-Zwischen-CA | TPM, je Mandant getrennt, mit Namensbeschränkung | hoch | sehr hoch | hoch | MA | Namensbeschränkung prüfen; Sperrung bei Mandantenstilllegung auslösen |
| Konnektorgeheimnisse | Geheimnisspeicher, gegen die Plattformintegrität versiegelt (INV-20) | sehr hoch | hoch | hoch | MA | Wechselfrist je Bindung setzen und die fällige Aufgabe abarbeiten |
| Nutzerdaten in Speicherbereichen | ZFS-Datasets je Dienst, mandantenbezogen verschlüsselt | sehr hoch | sehr hoch | hoch bis sehr hoch | DA | Datensicherheitsstufe wählen und das daraus folgende RPO (K-08 bis K-10) verantworten |
| Auditkette | anhängbarer, hashverketteter Strom außerhalb des replizierten Kernzustands (INV-23) | hoch | sehr hoch | hoch | PR | Auslagerungsziel und Kettenprüfung bestätigen; Aufbewahrung nach K-25 |
| Sicherungsdaten | Wiederherstellungspunkte lokal und ausgelagert, Sollzustandsexporte | sehr hoch | sehr hoch | hoch | PE | Wiederherstellungsübung nach K-24 abnehmen; ungeprüfte Punkte abarbeiten |
| Aktualisierungskanal | Freigabeschlüssel, signierte Abbilder, Transparenzprotokoll, Stücklisten | hoch | sehr hoch | normal | Hersteller | Freigabeprüfliste A4.9 vollständig durchlaufen; Restrisikoliste veröffentlichen |
| Bedienersitzung | Browser des Bedieners, Sitzungsobjekt in atrium-core | hoch | hoch | normal | MA | Authentisierungsmittel und Sitzungsdauer je Rolle festlegen |
| Netzzonenzuordnung der Geräte | Netzzone, Gerätezertifikat, RADIUS-Entscheidung | normal | sehr hoch | hoch | MA | Sperrung verlorener Geräte als Einzelhandlung auslösen |
| Namensraum einer Domäne | autoritative Zoneninstanzen, DNSSEC-Schlüssel | normal | sehr hoch | sehr hoch | MA | Delegierungsstatus und DS-Eintrag bestätigen |

Der Eigentümer des Aktualisierungskanals ist keine Rolle des Rechtemodells, sondern der Hersteller. Das ist eine Lücke des Rollenmodells und keine Nachlässigkeit dieser Tabelle: Für ein Schutzgut, das außerhalb der Installation entsteht, existiert im Objektgraphen kein Subjekt. Die Konsequenz steht in A4.9 und in den offenen Punkten.

Auszählung: 12 Schutzgüter × 3 Schutzziele = 36 Wertfelder, davon 16 mit dem Wert `sehr hoch`, also 44,4 Prozent; ein weiteres Feld trägt `hoch bis sehr hoch` und ergäbe mitgezählt 17 Felder oder 47,2 Prozent. Die drei Maßnahmen, die nur für Güter mit mindestens einer Klasse `sehr hoch` gelten (Versiegelung gegen die Plattformintegrität, Nichtexportierbarkeit privater Schlüssel, Signaturprüfung ohne Übersteuerung), betreffen damit 11 der 12 Güter; allein die Bedienersitzung trägt in keinem Schutzziel die Klasse `sehr hoch`. Das ist eine Auszählung dieser Tabelle, keine Messung.

## A4.3 Angreiferklassen mit Einstiegspunkt

| Klasse | Zugang | Fähigkeiten | Ziel | Typischer Einstiegspunkt |
|---|---|---|---|---|
| **A-1** Unbefugter im lokalen Netz | Anschluss an ein Segment ohne gültiges Gerätezertifikat | Mitschnitt, Einspeisung, ARP- und DHCP-Manipulation, Portscan | Dienstzugang, Kopplungscode, Umlenkung der Namensauflösung | 53/udp des rekursiven Resolvers, 8403/tcp eines Knotens im Wartemodus, 1812/udp des RADIUS-Dienstes |
| **A-2** Übernommener Bedienerarbeitsplatz | Vollständige Kontrolle über Browser und Betriebssystem eines Bedieners | Auslesen von Sitzungsmaterial, Auslösen von Aktionen im Namen des Bedieners | Privilegierte Vorgänge, dauerhafter Zugang | 443/tcp, Sitzung der Atrium Console |
| **A-3** Innentäter mit Teilrechten | Gültige Anmeldung, Rolle mit begrenztem Geltungsbereich | Kenntnis der Abläufe, Zeit, legitime API-Zugriffe | Ausweitung des Geltungsbereichs, verdeckter Datenabfluss | 443/tcp, öffentliche API mit gültigem Token |
| **A-4** Übernommener Konnektorprozess | Codeausführung im Kontext eines Konnektorprozesses | Beliebiger Code unter `atrium-conn-<mandant-kurz>-<konnektor>` | Ausbruch auf den Knoten, Zugriff auf fremde Mandanten | Unix-Socket des Konnektorvertrags; Antwortparser einer Fremd-API |
| **A-5** Kompromittiertes Fremdsystem | Kontrolle über den Endpunkt einer Konnektorbindung | Beliebig geformte Antworten, Vorspiegelung falscher Istzustände | Angriff auf den Parser, falsche Abweichungsanzeige | Antwortkanal der Konnektorbindung (ausgehende Positivliste) |
| **A-6** Angreifer mit physischem Zugang | Zugang zu Knoten, Datenträgern und Konsole | Ausbau von Datenträgern, Kaltstart, Firmwaremanipulation | Nutzdaten, Schlüsselmaterial, dauerhafte Implantate | Datenträgerschnittstelle, serielle oder grafische Konsole, UEFI-Einstellungen |
| **A-7** Angreifer in der Lieferkette | Zugriff auf Abhängigkeit, Basispaket, Bauknoten oder Freigabeschlüssel | Einbringen von Code in ein Artefakt, das jeder Knoten ausführt | Codeausführung auf allen Installationen | Quellenspiegel des Baus, Freigabeschlüssel, Transparenzprotokoll |
| **A-8** Anbieter der Virtualisierungsumgebung | Kontrolle über Hypervisor, virtuelle Datenträger, Fernkonsole | Lesen und Verändern des Hauptspeichers, Mitschnitt der Konsole, virtualisiertes TPM | Vollständiger Zugriff auf einen oder alle Knoten | Fernkonsole des Anbieters, Datenträgerabbild, TPM-Emulation |

Die Spalte "typischer Einstiegspunkt" ist die Verbindung zwischen diesem Anhang und A4.8: Jeder genannte Einstiegspunkt muss dort als Zeile mit Quellzone, Zielzone und Außenerreichbarkeit auftauchen. Ein Einstiegspunkt ohne Zeile in der Portmatrix wäre ein nicht modellierter Zugang.

## A4.4 Vertrauensgrenzen

| Grenze | Trennt | Übertragungsweg | Authentisierung | Autorisierung | Verschlüsselung |
|---|---|---|---|---|---|
| **VG-01** | Unauthentisiertes Außennetz ↔ Eingangsproxy und veröffentlichte Dienste | 443/tcp, 443/udp, 80/tcp | keine vor der Anwendungsschicht; serverseitig Zertifikat je Veröffentlichung | Zugriffskreis der Veröffentlichung (Gruppe, Netzzone, öffentlich) | TLS 1.3 (RFC 8446); 80/tcp unverschlüsselt und ausschließlich für Umleitung und ACME (RFC 8555) |
| **VG-02** | Lokales Netzsegment ↔ Knoten im Wartemodus | 8403/tcp, nur im Wartemodus offen | SPAKE2 (RFC 9382) aus dem Kopplungscode, an den TLS-Exporter gebunden | keine weitere; der Endpunkt trägt genau eine Operation | TLS 1.3 mit Exporter-Kanalbindung |
| **VG-03** | Bedienergerät und Automatisierung ↔ öffentliche API | 443/tcp über den Eingang auf 8400/tcp | OIDC-Sitzung mit Passkey nach WebAuthn; Dienstkonten mit mTLS oder Token nach RFC 9068 | eine Autorisierungsstelle, standardmäßig verweigernd, Mandantenprädikat je Abfrage (E-06, INV-19) | TLS 1.3 außen; TLS 1.3 mit gegenseitiger Authentisierung zwischen Eingang und Kern |
| **VG-04** | Knoten ↔ Knoten derselben Installation | 8401/tcp, 8402/tcp innerhalb WireGuard (51820/udp) | gegenseitiges TLS mit Knotenzertifikat, TPM-gebunden wo vorhanden | Knotenrolle aus dem Sollzustand (Stimmknoten, Mitleser, Zeuge, Dienstträger) | zwei Lagen: WireGuard als Träger, TLS 1.3 darin |
| **VG-05** | atrium-core ↔ Konnektorprozess | gRPC über Unix-Socket, ein Socket je Bindung | Dateisystemrechte und Systembenutzer; Prüfung der Gegenstellenkennung am Socket | auftragsgebundenes Fähigkeitstoken mit Subjekt, Objekt, Operation, Gültigkeit, Vorgangsbezug (E-07) | entfällt; Begründung: lokaler Socket ohne Netzweg, Schutz durch Dateisystemrechte und getrennte Systembenutzer |
| **VG-06** | Konnektorprozess ↔ Fremdsystem; Dienstprozess ↔ Knotenkern | ausgehende Positivliste des Netznamensraums; Systemaufrufschnittstelle | Konnektorgeheimnis oder Token nach RFC 6749 und RFC 6750 gegen das Fremdsystem; Kernkennung des Prozesses gegen den Knoten | im Fremdsystem, nicht in Atrium; gegenüber dem Knoten Systemaufruffilter und Zugriffskontrollprofil | TLS 1.3 zum Fremdsystem, soweit dieses es anbietet; entfällt an der Systemaufrufschnittstelle |
| **VG-07** | Knoten ↔ Auslagerungsziel und Auditziel | 22/tcp, 6514/tcp | Schlüsselpaar je Knoten mit Zweckbindung, gepinnter Wirtsschlüssel; gegenseitiges TLS für die Protokollstrecke | Zielverzeichnis, ausschließlich Lesen und Schreiben, keine interaktive Anmeldung | SSH-Transport beziehungsweise TLS 1.3; Nutzdaten zusätzlich vor der Übertragung mit mandantenbezogenem Schlüssel verschlüsselt |
| **VG-08** | Aktualisierungskanal des Lieferanten ↔ Knoten | Bezug über beliebigen, unvertrauenswürdigen Weg | Ed25519-Signatur (RFC 8032) über die kanonische Serialisierung nach RFC 8785; Eintrag im Transparenzprotokoll mit Zeitstempel nach RFC 3161 | Freigabeschlüssel im aktiven Abbild verankert; Version muss mindestens der aktiven entsprechen | für die Zusage unerheblich; der Transportweg wird als mitlesbar und manipulierbar angenommen |

Zwei Zeilen tragen ein "entfällt", und beide sind eine bewusste Entscheidung mit sichtbarer Folge. Bei VG-05 ersetzt die Prozess- und Dateisystemtrennung die Transportverschlüsselung; die Folge ist, dass ein Angreifer mit Rechten des Systembenutzers der Konnektor ist und kein Kryptoverfahren das noch auffängt. Bei VG-06 liegt die Autorisierung gegenüber dem Fremdsystem vollständig dort; Atrium kann ein einmal vergebenes Recht im Fremdsystem nicht zurücknehmen, sondern nur nach Feldeigentum (INV-13) korrigieren.

## A4.5 STRIDE je Vertrauensgrenze

Umfang: 8 Grenzen × 6 Kategorien = 48 Zeilen. Diese Zahl ist zugleich die Zielgröße der Testabdeckung aus R-20-02; eine Zeile ohne zugeordneten Test bricht den Bau.

### A4.5.1 VG-01 Außennetz zum Eingang

| Bedrohung | Konkreter Angriffspfad | Gegenmaßnahme | Erkennungssignal | Restrisiko | Abdeckung |
|---|---|---|---|---|---|
| Spoofing | Angreifer erlangt ein Zertifikat für einen Namen der externen Domäne bei einer dritten CA und lenkt Clients auf einen eigenen Endpunkt | CAA-Eintrag (RFC 8659) je externer Domäne, DNSSEC (RFC 4033, RFC 4034, RFC 4035), Zertifikat ausschließlich aus einer Veröffentlichung | `zertifikat.ausgestellt` ohne zugehörige Veröffentlichung; Abgleich beobachteter öffentlicher Ausstellungen gegen die eigene Veröffentlichungsliste | Ein Client, der Zertifikate nicht prüft, ist nicht schützbar | INV-09, R-20-06 |
| Tampering | Zwischenstelle erzwingt Abstufung auf eine ältere Protokollversion und verändert Antworten | TLS 1.3 (RFC 8446) ohne Rückfall; HTTP/2 (RFC 9113) und HTTP/3 (RFC 9114) über QUIC (RFC 9000) | Zähler abgelehnter Handshakes mit gewünschter Version unterhalb TLS 1.3 | Fehler in der TLS-Umsetzung des Eingangs | KANON 4 Kryptographische Grundzusagen |
| Repudiation | Eine über die API ausgelöste Änderung wird bestritten, weil Anfrage und Auditeintrag nicht verknüpft sind | Korrelationskennung ab dem Eingang bis in das Auditereignis | Anteil Auditereignisse ohne Korrelationskennung; Zielwert 0 | Anfragen ohne Anmeldung sind nur netzseitig zuordenbar | INV-23 |
| Information Disclosure | Angreifer erzeugt gezielt Fehler und liest Pfade, Versionen und Fremdtexte aus der Antwort | Stabile Fehlercodes nach außen, vollständige Ursache nur nach innen (E-14); Unterdrückung von Versions- und Serverkennungen | Musterprüfung aller Meldungstexte im Bau; Zielwert 0 Treffer | Zeitverhalten und Antwortgrößen bleiben als Seitenkanal | R-20-19 |
| Denial of Service | Massenhaft halboffene Verbindungen und Anfragen unter neuen Namen, die je eine Zertifikatsausstellung auslösen | Verbindungs- und Anfrageratenbegrenzung je Quelle und Veröffentlichung; Ausstellungsrate je Domäne begrenzt | Zähler `veroeffentlichung.ratenbegrenzt`; Auslastungsanzeige am Eingang | Volumen oberhalb der Anbindungsbandbreite ist nicht abwehrbar | INV-10 |
| Elevation of Privilege | Speicherfehler im Eingangsprozess führt zu Codeausführung unter dessen Kennung | Eigener Systembenutzer, leere Capability-Menge, Bindung an 80 und 443 über Socketaktivierung, Systemaufruf-Positivliste, erzwingendes Zugriffskontrollprofil | Tötungsereignis des Systemaufruffilters mit Aufrufnummer; Ablehnungen des Zugriffskontrollprofils | Der Eingang ist in C++ geschrieben; `MemoryDenyWriteExecute` ist nicht setzbar | R-20-25, E-01 |

### A4.5.2 VG-02 Lokales Netz zum Kopplungsendpunkt

| Bedrohung | Konkreter Angriffspfad | Gegenmaßnahme | Erkennungssignal | Restrisiko | Abdeckung |
|---|---|---|---|---|---|
| Spoofing | Angreifer betreibt im selben Segment einen Endpunkt auf 8403/tcp und nimmt den wartenden Knoten in ein fremdes System auf | SPAKE2 ist beidseitig; ohne Kenntnis des Codes entsteht kein gemeinsames Geheimnis | `kopplung.fehlgeschlagen` am echten Knoten; unveränderter Wartemodus trotz Bedienhandlung | Ein Bediener, der den Code von einem fremden Bildschirm abliest, koppelt an ein fremdes System | INV-27, K-14 |
| Tampering | Einschleusen eines eigenen Knotenzertifikats zwischen Schlüsselableitung und Ausstellung | Bindung des SPAKE2-Ergebnisses an den TLS-Exporter der Verbindung | Abbruch mit stabilem Fehlercode; Zähler `kopplung.kanalbindung_verletzt` | Auf Protokollebene keines bekannt | KANON 4 Kopplung |
| Repudiation | Eine Kopplung wird bestritten | `kopplung.erzeugt`, `kopplung.verwendet`, `kopplung.fehlgeschlagen` als Auditereignisse, auch im Fehlerfall | Vollständigkeit der drei Ereignistypen je Kopplungsvorgang | Zuordnung zur Person nur über die Sitzung, in der der Code eingegeben wurde | INV-23 |
| Information Disclosure | Mitschnitt des Kopplungslaufs zwecks Offline-Raten des Codes | SPAKE2 (RFC 9382) lässt kein Offline-Raten zu; jeder Versuch kostet einen Online-Lauf | keines erforderlich; die Eigenschaft ist protokollseitig | Der Code ist sichtbar, wo der Bildschirm sichtbar ist; auf Knoten mit anbieterseitig mitlesbarer Fernkonsole entfällt der Schutz | K-14, R-20-36 |
| Denial of Service | Angreifer verbraucht die fünf Fehlversuche jedes erzeugten Codes | Codeerneuerung ist eine Bedienhandlung ohne Kosten; Fehlversuche sind im Audit sichtbar | Häufung von `kopplung.fehlgeschlagen` ohne Bedienhandlung | Ein dauerhafter Angreifer im Segment verzögert die Kopplung dauerhaft | INV-27 |
| Elevation of Privilege | Ein vom Angreifer kontrollierter Knoten wird aufgenommen und anschließend zum Stimmknoten gemacht | Zweckwahl nach der Kopplung; Stimmrecht ist eine eigene, auditierte Mitgliedschaftsänderung | `knoten.gekoppelt` mit Zweck; `knoten.mitgliedschaft_geaendert` | Ein aufgenommener Dienstträger sieht die auf ihm platzierten Dienste und deren Speicherbereiche | INV-05 |

### A4.5.3 VG-03 Bedienergerät zur Konsole und API

| Bedrohung | Konkreter Angriffspfad | Gegenmaßnahme | Erkennungssignal | Restrisiko | Abdeckung |
|---|---|---|---|---|---|
| Spoofing | Gestohlenes Zugriffstoken wird von einer anderen Quelle weiterverwendet | Passkey mit Ursprungsbindung; Zugriffstoken höchstens 15 min gültig; Erneuerung nur mit Nachweis des Authentisierers | `anmeldung.erfolgt` mit neuer Quelladresse innerhalb einer laufenden Sitzung | Ein Authentisierer im Zugriff des Angreifers bleibt gültig | R-20-05 |
| Tampering | Fremdveranlasste Zustandsänderung aus einer anderen Ursprungsseite | Token im Anwendungsspeicher statt in einem automatisch mitgesendeten Merkmal; Ursprungsprüfung; keine formularbasierte Zustandsänderung | Zähler abgelehnter Anfragen mit fremdem Ursprung | Eine bösartige Browsererweiterung umgeht jede dieser Maßnahmen | E-06 |
| Repudiation | Ein ausgeführter Vorgang wird bestritten | Vorgang trägt Auslöser, Sitzung, Zeitpunkt, Wirkungsvorschau und Freigeber | Vorgangsprotokoll mit Freigabekette | Notzugang mit geteiltem Wiederherstellungscode ist nicht personengenau | INV-23 |
| Information Disclosure | Eingeschleustes Skript liest Sitzungsmaterial aus der Konsole | Strenge Inhaltsrichtlinie ohne Inline-Ausführung; keine dynamische Codeausführung (E-10); keine langlebigen Geheimnisse im Browser | Verstoßmeldungen der Inhaltsrichtlinie an einen Sammelpunkt | Eine Schwachstelle in der Konsole selbst | R-20-15 |
| Denial of Service | Fehlanmeldungen sperren gezielt Konten von Administratoren | Ratenbegrenzung je Quelle statt Kontosperre; Sperre nur mit sichtbarer Entsperrmöglichkeit | Häufung von `anmeldung.fehlgeschlagen` je Quelladresse | Gezielte Ratenerschöpfung verzögert legitime Anmeldungen | INV-17 |
| Elevation of Privilege | Zugriff auf Objekte eines fremden Mandanten über eine erratene oder abgeschriebene Kennung | Mandantenprädikat in jeder Abfrage; Autorisierung an genau einer, vollständig testabgedeckten Stelle | `zugriff.verweigert` mit Kennzeichen "Mandantenwechsel versucht" | Ein Fehler in dieser einen Stelle wirkt überall gleichzeitig | INV-19, R-20-11 |

### A4.5.4 VG-04 Knoten zu Knoten

| Bedrohung | Konkreter Angriffspfad | Gegenmaßnahme | Erkennungssignal | Restrisiko | Abdeckung |
|---|---|---|---|---|---|
| Spoofing | Ein fremder Knoten meldet sich mit kopiertem Knotenzertifikat an der Konsensgruppe an | Gegenseitiges TLS mit Knotenzertifikat aus der Ausgabe-CA; Mitgliedschaft ist ein auditierter Sollzustandseintrag | Zähler abgelehnter TLS-Verbindungen auf 8401/tcp; `knoten.mitgliedschaft_geaendert` ohne Vorgang | Ein gestohlenes, nicht TPM-gebundenes Zertifikat auf einem virtualisierten Knoten | INV-05, A-8 |
| Tampering | Nachträgliches Verändern replizierter Einträge auf einem Knoten | Hashverkettung des Änderungsprotokolls; signierte, versionierte Sollzustandsauszüge an atrium-node | Kettenprüfung bei jedem Start und bei jedem Export; Abweichung bricht den Start | Ein kompromittierter Führungsknoten erzeugt gültige Einträge | INV-03, INV-23 |
| Repudiation | Eine Replikationsentscheidung wird bestritten | Ursprung und Korrelationskennung je Eintrag | Vollständigkeit der Ursprungsangabe; Zielwert 100 Prozent | Keines | INV-23 |
| Information Disclosure | Mitlesen des Knotenverkehrs im Unterlagerungsnetz | WireGuard-Vollvermaschung zwischen allen Knoten; kein unverschlüsselter Knotenverkehr | Verworfene Pakete auf 8401/tcp und 8402/tcp außerhalb des Overlays, gezählt je Knoten | Verkehrsmuster und Paketgrößen bleiben sichtbar | KANON 4 Overlay-Netz |
| Denial of Service | Erzwungene Netzpartition blockiert alle Schreibvorgänge | Eingefrorener Sollzustand statt Divergenz; Dienste laufen weiter und starten lokal neu | Konsole zeigt "Eingefroren" und die Zahl derzeit tolerierter Knotenausfälle | Ohne Quorum sind Änderungen bis zur Wiederherstellung nicht möglich; das ist gewollt | INV-04, INV-18, INV-25 |
| Elevation of Privilege | Ein kompromittierter Dienstträger versucht, auf die Kontrollebene zu schreiben | Stimmrecht ist eine getrennte Rolle; atrium-node kennt keine Schreiboperation zur Kontrollebene | `zugriff.verweigert` auf 8400/tcp mit Quellzone einer Dienstzone | Ein kompromittierter Stimmknoten ist ein kompromittierter Teil der Kontrollebene | INV-03 |

### A4.5.5 VG-05 Kern zum Konnektorprozess

| Bedrohung | Konkreter Angriffspfad | Gegenmaßnahme | Erkennungssignal | Restrisiko | Abdeckung |
|---|---|---|---|---|---|
| Spoofing | Ein anderer lokaler Prozess verbindet sich mit dem Socket und gibt sich als Konnektor aus | Ein Socket je Bindung mit Dateisystemrechten des zugehörigen Systembenutzers; Prüfung der Gegenstellenkennung | `konnektor.verbindung_abgelehnt` mit abweichender Benutzerkennung | Ein Angreifer mit Rechten dieses Benutzers ist der Konnektor | KANON 4 Konnektor-Sandkasten |
| Tampering | Manipulierte `observe`-Ergebnisse erzeugen eine Abweichung, die den Bediener zu einer Korrektur verleitet | Istzustand ist außerhalb der Abweichungsanzeige nie Entscheidungsgrundlage; Schreibwirkung nur über einen Vorgang mit Wirkungsvorschau | Sprunghafte Änderung der Abweichungszahl je Bindung; Beobachtungszeitpunkt an jeder Istansicht | Eine falsche Anzeige kann zu einer falschen Freigabe verleiten | INV-08, INV-28 |
| Repudiation | Ein `apply`-Aufruf wird bestritten | Idempotenzschlüssel und Korrelationskennung je Aufruf | Zuordnung Aufruf zu Vorgang; Zielwert 100 Prozent | Das Fremdsystem protokolliert eigenständig und möglicherweise abweichend | INV-07 |
| Information Disclosure | Konnektorprozess fordert eine Geheimnisreferenz einer fremden Bindung an | Kurzlebige, auftragsgebundene Referenz statt Wert; eigener Systembenutzer je Mandant und Bindung | `geheimnis.zugriff_verweigert` mit Auftrags- und Bindungskennung | Das Geheimnis liegt während des Auftrags im Prozessspeicher | INV-20, R-20-12 |
| Denial of Service | Konnektor antwortet nicht und bindet Aufrufslots des Kerns | Zeitüberschreitung je Aufruf, Leerlaufabschaltung nach 10 min, Parallelitätsgrenze aus K-20 | Zähler Zeitüberschreitungen je Bindung; Bindungszustand "abweichend" | Ein Fremdsystem mit Ratenbegrenzung verzögert die Versorgung bis zur Obergrenze aus K-15 | K-15, K-20 |
| Elevation of Privilege | Konnektorprozess versucht, die API der Kontrollebene über das Netz zu erreichen | Eigener Netznamensraum, ausgehende Positivliste aus der Bindung, kein Netzweg zu 8400/tcp | Zähler `konnektor.ausgang_verworfen` größer 0 | Der Unix-Socket bleibt der einzige Weg und trägt nur den Konnektorvertrag | INV-21, R-20-04 |

### A4.5.6 VG-06 Konnektor zum Fremdsystem, Dienstprozess zum Knoten

| Bedrohung | Konkreter Angriffspfad | Gegenmaßnahme | Erkennungssignal | Restrisiko | Abdeckung |
|---|---|---|---|---|---|
| Spoofing | Manipulation der Namensauflösung lenkt den Konnektor auf einen Endpunkt des Angreifers | Ausgehende Positivliste aus der Bindung; Zertifikatsprüfung gegen den öffentlichen Vertrauensspeicher oder einen gepinnten Anker | `konnektorbindung.zertifikat_abgewichen`; Abbruch vor der ersten Nutzlast | Ein Fremdsystem, dessen Betreiber selbst umgeleitet wurde | INV-21 |
| Tampering | Bösartig geformte Antwort greift den Parser oder die Manifestauswertung an | Schemavalidierung am Rand mit Ablehnung unbekannter Felder; Größen-, Längen- und Tiefengrenzen; Rust für Eigenentwicklung | `konnektor.antwort_abgelehnt` mit Fehlercode und Bindungskennung | Logikfehler in der Manifestauswertung sind speichersicher, aber nicht harmlos | E-05, E-11, R-20-10 |
| Repudiation | Das Fremdsystem bestreitet eine von Atrium ausgelöste Änderung | Vorher-Nachher-Zustand im Auditereignis, soweit das Fremdsystem ihn liefert | Anteil `apply`-Ereignisse ohne Vorzustand, je Bindung ausgewiesen | Fremdsysteme ohne Abfragemöglichkeit des Vorzustands | INV-23 |
| Information Disclosure | Eine Zuweisung überträgt mehr Personenfelder als beabsichtigt | Feldeigentum je Feld deklariert; Wirkungsvorschau zeigt die zu übertragenden Felder vorab | Abweichung zwischen der angezeigten Vorschau und der tatsächlichen `apply`-Nutzlast; Zielwert 0 | Jede legitime Versorgung überträgt Daten; das ist ihr Zweck | INV-13, INV-08 |
| Denial of Service | Ein Dienstprozess verbraucht Hauptspeicher und Ein-/Ausgabe des Knotens | Ressourcenbudget je Dienst über die erzeugte Einheit; weiche Speichergrenze vor harter Grenze | Drosselungszähler der Ressourcengruppe je Dienst | Ein Dienst innerhalb eines zu großzügigen Budgets verdrängt andere Dienste | KANON 4 Dienstausführung |
| Elevation of Privilege | Dienstprozess ruft einen nicht benötigten Systemaufruf auf und erlangt Knotenrechte | Ausführung ohne Rootrechte wo das Produkt es zulässt, eigener Benutzer, Systemaufruf-Positivliste mit Rückfall `KILL_PROCESS`, keine Rechteerhöhung, eingeschränkter Kernmodus | Tötungsereignis mit Aufrufnummer und Dienstkennung | Kernschwachstellen, die über die Positivliste erreichbar bleiben | R-20-25, R-20-26 |

### A4.5.7 VG-07 Knoten zu Auslagerungs- und Auditziel

| Bedrohung | Konkreter Angriffspfad | Gegenmaßnahme | Erkennungssignal | Restrisiko | Abdeckung |
|---|---|---|---|---|---|
| Spoofing | Ein untergeschobenes Ziel nimmt Sicherungen entgegen und sammelt Daten | Zielbindung an einen gepinnten Wirtsschlüssel; Zweckschlüssel je Knoten ausschließlich für diesen Zweck berechtigt | `auslagerung.zielschluessel_abgewichen`; Abbruch vor der Übertragung | Ein übernommenes, legitimes Ziel | KANON 4 Sicherungswerkzeug |
| Tampering | Veränderung ausgelagerter Stände am Ziel | Signatur und Prüfsumme je Wiederherstellungspunkt; Prüfung vor jeder Verwendung | Prüfstatus am Wiederherstellungspunkt; "ungeprüft" ist ein sichtbarer Zustand | Löschung statt Veränderung; dagegen wirkt nur ein zweites Ziel | INV-18, K-24 |
| Repudiation | Eine Auslagerung wird bestritten | Auslagerung ist ein Vorgang mit Auditereignis | Zuordnung Vorgang zu Wiederherstellungspunkt; Zielwert 100 Prozent | Keines | INV-23 |
| Information Disclosure | Der Betreiber des Ziels liest die abgelegten Daten | Verschlüsselung vor der Übertragung mit mandantenbezogenem Schlüssel, der das Ziel nie erreicht | Kein Signal auf der Atrium-Seite möglich; das wird hier ausdrücklich festgehalten | Metadaten wie Größe, Zeitpunkt und Anzahl der Stände bleiben lesbar | INV-20 |
| Denial of Service | Das Ziel nimmt nichts mehr an, die Sicherung fällt still aus | Sichtbarer Zustand am Speicherbereich statt stiller Fehlschlag | `auslagerung.fehlgeschlagen`; RPO-Anzeige am Dienst läuft sichtbar weiter | Ohne erreichbares Ziel läuft der RPO der Stufe "Lokal" weiter | INV-18, K-10 |
| Elevation of Privilege | Vom Ziel aus wird über den Rückkanal eine Anmeldung auf dem Knoten versucht | Der Zweckschlüssel erlaubt nur Lesen und Schreiben im Zielverzeichnis, keine interaktive Anmeldung; die Protokollstrecke ist einseitig | Anmeldeversuch wird am Ziel protokolliert, nicht in Atrium; das ist eine Lücke und keine Maßnahme | Eine Schwachstelle im verwendeten Transportwerkzeug | R-20-27 |

### A4.5.8 VG-08 Lieferkette zum Knoten

| Bedrohung | Konkreter Angriffspfad | Gegenmaßnahme | Erkennungssignal | Restrisiko | Abdeckung |
|---|---|---|---|---|---|
| Spoofing | Ein Abbild eines fremden Herausgebers wird als Aktualisierung angeboten | Signaturprüfung gegen den im aktiven Abbild verankerten Freigabeschlüssel | `abbild.signatur_ungueltig`; Abbruch ohne Übersteuerungsmöglichkeit | Kompromittierung des Freigabeschlüssels | R-20-21 |
| Tampering | Nachträgliche Veränderung einzelner Blöcke eines freigegebenen Abbilds | dm-verity über die gesamte Abbildhälfte; der Wurzelhashwert ist Teil der Signatur | Lesefehler statt manipulierter Daten; der Knoten meldet die Störung und wird geräumt | Keines gegen ein korrekt signiertes, bösartiges Abbild | KANON 4 Abbild- und Bauverfahren |
| Repudiation | Eine Freigabe wird bestritten oder zurückdatiert | Anhängbares, hashverkettetes Transparenzprotokoll mit Zeitstempel nach RFC 3161 | Vergleich des lokal geprüften Eintrags mit einem unabhängig beobachteten Protokollstand | Ein Angreifer mit Kontrolle über Schlüssel und Protokoll gleichzeitig | R-20-22 |
| Information Disclosure | Rückschluss auf den Kundenbestand aus Bezugsmustern | Bezug ohne Kundenkennung; das Transparenzprotokoll enthält Freigaben, nicht Abnehmer | keines auf Herstellerseite; netzseitige Beobachtung ist nicht erkennbar | Netzseitige Beobachtung des Bezugs | KANON 4 Signaturverfahren |
| Denial of Service | Der Aktualisierungsbezug wird dauerhaft blockiert | Der Knoten bleibt lauffähig; Aktualisierung ist keine Betriebsvoraussetzung | Alter der aktiven Abbildversion im Überblick, Warnung ab einer festgelegten Schwelle | Ungepatchte Schwachstellen bei dauerhafter Blockade | INV-18, K-23 |
| Elevation of Privilege | Gezielte Einzelauslieferung eines bösartigen Abbilds an einen einzelnen Kunden | Prüfung des Transparenzprotokolleintrags beim Einspielen; ein Abbild ohne öffentlichen Eintrag oder mit einer Version unterhalb der aktiven wird abgelehnt | Abweichung zwischen lokal geprüftem Eintrag und öffentlich beobachtbarem Protokollstand | Ein Protokollbetreiber, der zurückdatierte Einträge erzeugt | R-20-22 |

## A4.6 Missbrauchsfälle

- **MF-01** Ein Angreifer mit Anschluss an das Bürosegment (A-1) versucht, einen wartenden Knoten zu übernehmen, indem er dessen Kopplungsendpunkt auf 8403/tcp mit geratenen Codes belegt; das System verhindert das durch fünf Fehlversuche je Code, 15 min Gültigkeit und die Vernichtung des Codes nach dem fünften Versuch (INV-27); es bleibt das Restrisiko, dass er die Kopplung durch fortgesetzte Fehlversuche dauerhaft verzögert.
- **MF-02** Ein Angreifer mit Kontrolle über einen Bedienerarbeitsplatz (A-2) versucht, sich einen dauerhaften Zugang zu verschaffen, indem er ein Dienstkonto mit Plattformrechten anlegt; das System erkennt das, weil Rechteerhöhung eine freigabepflichtige Vorgangsklasse ist und ein zweiter Mensch zustimmen muss ([Kapitel 19](19-mandanten-rechte-audit.md)); es bleibt das Restrisiko, dass der Angreifer innerhalb der nicht freigabepflichtigen Rechte des Bedieners handelt.
- **MF-03** Ein Innentäter mit Helpdesk-Rolle (A-3) versucht, Postfachinhalte einer fremden Führungskraft zu lesen, indem er sich selbst als Berechtigten eines geteilten Postfachs einträgt; das System erkennt das, weil die Änderung ein Vorgang mit Wirkungsvorschau und Auditereignis ist und die Rechtematrix Helpdesk für diese Aktionsklasse auf "beantragen" begrenzt; es bleibt das Restrisiko, dass ein Freigeber den Antrag ohne Prüfung bestätigt.
- **MF-04** Ein Angreifer mit Kontrolle über ein Fremdsystem (A-5) versucht, in Atrium eine Massenlöschung auszulösen, indem er in der `observe`-Antwort alle Konten als nicht vorhanden meldet; das System verhindert das, weil der Istzustand außerhalb der Abweichungsanzeige keine Entscheidungsgrundlage ist und jede Löschung einen eigenen Vorgang mit vollständiger Auswirkungsliste erfordert (INV-11); es bleibt das Restrisiko, dass eine falsche Abweichungsanzeige einen Bediener zu einer unnötigen Korrektur verleitet.
- **MF-05** Ein Angreifer mit Codeausführung in einem Konnektorprozess (A-4) versucht, Geheimnisse anderer Mandanten zu erlangen, indem er Referenzen fremder Bindungen anfordert; das System verhindert das durch einen eigenen Systembenutzer je Mandant und Bindung und durch auftragsgebundene, kurzlebige Referenzen (INV-20) und erkennt es an `geheimnis.zugriff_verweigert`; es bleibt das Restrisiko, dass das eigene Geheimnis der Bindung während des laufenden Auftrags im Prozessspeicher liegt.
- **MF-06** Ein Angreifer mit Codeausführung in einem Dienstprozess (A-4) versucht, aus dem Dienst auf den Knoten auszubrechen, indem er Einhänge- und Netzwerkaufrufe absetzt; das System verhindert das durch eine Systemaufruf-Positivliste mit Rückfall `KILL_PROCESS` und ein erzwingendes Zugriffskontrollprofil und erkennt es am Tötungsereignis mit Aufrufnummer; es bleibt das Restrisiko einer Kernschwachstelle, die über einen zugelassenen Aufruf erreichbar ist.
- **MF-07** Ein Angreifer mit physischem Zugang (A-6) versucht, Nutzdaten zu lesen, indem er einen Datenträger ausbaut und an einer eigenen Maschine einhängt; das System verhindert das durch Datenträgerverschlüsselung mit TPM-Bindung und eine Versiegelung, die an Firmware, Startlader, Kern und dm-verity-Wurzelhashwert gebunden ist; es bleibt das Restrisiko des laufenden Knotens, an dem Hauptspeicherinhalte erreichbar sind.
- **MF-08** Ein Angreifer mit Zugriff auf eine Abhängigkeit (A-7) versucht, Code auf allen Installationen auszuführen, indem er eine bereits veröffentlichte Bibliotheksversion nachträglich austauscht; das System verhindert das durch festgeschriebene Abhängigkeitsversionen mit Prüfsummen, einen Bau ohne Netzzugang und einen gespiegelten Quellenbestand (E-13); es bleibt das Restrisiko einer Hintertür, die von Anfang an enthalten war und reproduzierbar mitgebaut wird.
- **MF-09** Ein Angreifer mit Kontrolle über die Fernkonsole eines gemieteten Knotens (A-8) versucht, die Kopplung mitzulesen, indem er den angezeigten Kopplungscode abgreift; das System verhindert den Codeweg auf solchen Knoten und verlangt stattdessen das ausdrücklich als schwächer gekennzeichnete Aufnahmetoken mit CA-Fingerabdruck-Pinning; es bleibt das Restrisiko, dass der Anbieter jeden Hauptspeicherinhalt des Knotens ohnehin lesen kann und damit jede Vertraulichkeitszusage auf diesem Knoten entfällt.
- **MF-10** Ein Angreifer im lokalen Netz (A-1) versucht, Nutzer auf eine gefälschte Anmeldeseite zu lenken, indem er Antworten des rekursiven Resolvers fälscht; das System verhindert das durch DNSSEC-Validierung im Resolver (RFC 4033, RFC 4034, RFC 4035), die Bindung des Resolvers an interne Adressen ohne offene Rekursion und die erzwungene Nutzung des internen Resolvers auf verwalteten Geräten; es bleibt das Restrisiko nicht verwalteter Geräte, die eine eigene verschlüsselte Namensauflösung benutzen.
- **MF-11** Ein Innentäter mit Prüferrolle (A-3) versucht, Spuren zu beseitigen, indem er Auditereignisse löscht; das System verhindert das, weil der Auditstrom nur anhängbar, hashverkettet, periodisch signiert und durch keine Rolle änderbar ist (INV-23), und erkennt eine Manipulation bei der Kettenprüfung zum Start und zum Export; es bleibt das Restrisiko, dass ein Angreifer die Auslagerung unterbindet und damit die Aufbewahrungsdauer verkürzt.
- **MF-12** Ein Angreifer mit gestohlenem Zugriffstoken (A-2) versucht, Objekte eines fremden Mandanten zu lesen, indem er fremde Kennungen in API-Pfade einsetzt; das System verhindert das durch das Mandantenprädikat in jeder Abfrage und die Autorisierung an genau einer, standardmäßig verweigernden Stelle (INV-19, E-06) und erkennt es an `zugriff.verweigert` mit dem Kennzeichen "Mandantenwechsel versucht"; es bleibt das Restrisiko, dass ein Fehler in dieser einen Stelle gleichzeitig überall wirkt, weshalb sie vollständig testabgedeckt ist.

## A4.7 Härtungsvorgaben je Systemkomponente

Die Spalte "Systemaufruffilter" nennt die Filterklasse und die ausdrücklich ausgeschlossenen Aufrufgruppen. Die vollständigen Positivlisten werden hier nicht erfunden; sie entstehen aus einer Messung des tatsächlichen Aufrufbedarfs jeder Komponente und sind als Anforderung R-A4-13 festgelegt.

| Komponente | Sandbox-Direktiven | Zugriffskontrollprofil | Systemaufruffilter | Begründung |
|---|---|---|---|---|
| **atrium-core** | `NoNewPrivileges`, leere Capability-Menge, `LockPersonality`, `MemoryDenyWriteExecute`, `ProtectSystem=strict`, `ProtectHome=yes`, `PrivateTmp`, `ProtectProc=invisible`, `RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX`, `DeviceAllow` nur für das TPM-Gerät | erzwingend, eigenes Profil; schreibbar nur das eigene Dataset und der Auditpfad | Positivliste, Rückfall `KILL_PROCESS`; ausgeschlossen Ablaufverfolgung, Einhängen, Kernmodulladen, Kernneuladen, Programmladen in den Kern, Leistungszähler | Braucht das TPM-Gerät für die Ausgabe-CA, daher kein vollständiger Geräteentzug; der Ersatz ist die Einzelfreigabe genau dieses Geräts |
| **atrium-node** | `NoNewPrivileges`, `ProtectSystem=strict`, schreibbar nur Zustandspfad und erzeugte Einheitenverzeichnisse; keine Netzfähigkeit im Dauerprozess | erzwingend; Schreibpfade auf den Zustandspfad begrenzt | Positivliste; Netz- und Einhängeaufrufe im Dauerprozess ausgeschlossen | Der Agent muss Regelsätze tauschen und Dateisysteme einhängen; das geschieht im Hilfsprozess, damit der Dauerprozess ohne diese Rechte läuft |
| **Regelanwendungs-Hilfsprozess** | kurzlebig, `NoNewPrivileges`, genau eine Netzverwaltungsfähigkeit, kein Netzzugang, Laufzeitgrenze je Aufruf | erzwingend, eigenes, sehr enges Profil | eigene Positivliste, ausschließlich Netz- und Einhängeaufrufe zusätzlich | Ein Dauerprozess mit Netzverwaltungsrechten wäre dauerhaft angreifbar; die Trennung begrenzt das Zeitfenster auf die Regelanwendung |
| **Eingangsproxy (Envoy)** | `NoNewPrivileges`, leere Capability-Menge, Bindung an 80 und 443 über Socketaktivierung, `ProtectSystem=strict`, `PrivateTmp`, kein Schlüsselmaterial im Dateisystem | erzwingend; kein Lesezugriff außerhalb des eigenen Kratzverzeichnisses | Positivliste, enger als der Standard; `MemoryDenyWriteExecute` nicht setzbar | Die Laufzeitübersetzung der Filterkette verlangt beschreibbaren ausführbaren Speicher; Ausgleich sind engerer Filter, Socketaktivierung statt Portbindungsrecht und die kurze Aktualisierungsfrist nach K-23 |
| **Autoritativer DNS (Knot DNS)** | eigener Systembenutzer, Bindung an 53 über Socketaktivierung, `ProtectSystem=strict`, schreibbar nur Zonen- und Journalpfad | erzwingend; Steuerschnittstelle nur über lokalen Socket | Positivliste | In C geschrieben, unauthentisiert erreichbar; die Sprachzusage aus E-01 gilt nicht und wird durch engere Netz- und Dateisystemgrenzen ersetzt, nicht erfüllt |
| **Rekursiver Resolver (Knot Resolver)** | eigener Systembenutzer je Netzzone, `ProtectSystem=strict`, schreibbar nur der Zwischenspeicherpfad | erzwingend, je Instanz getrennt | Positivliste | Braucht ausgehende Namensauflösung; die Bindung an genau eine Netzzone verhindert die Nutzung als offener Auflöser |
| **Protokollkopf (Identität)** | eigener Systembenutzer, eigene Verwaltungsoberfläche abgeschaltet, `ProtectSystem=strict`, eigener Datenpfad | erzwingend; Schreibzugriff auf den Datenpfad, kein Zugriff auf Kernpfade | Positivliste | Schreibt eigenen Zustand und bleibt daher beschreibbar; er wird nie als Wahrheitsquelle gelesen ([Kapitel 10](10-identitaet.md)) |
| **RADIUS-Dienst** | eigener Systembenutzer, erzeugte Konfiguration, `ProtectSystem=strict`, nur lesender Zugriff auf Vertrauensanker und Sperrliste | erzwingend | Positivliste | Nur aktiv, wenn Netzzugang genutzt wird; im Auslieferungszustand nicht gestartet |
| **Zeitdienst (chrony)** | eigener Systembenutzer, Recht zum Setzen der Systemzeit, sonst leere Capability-Menge | erzwingend | Positivliste, zusätzlich der Zeitsetzaufruf | Das Zeitsetzrecht ist unverzichtbar und zugleich ein Angriff auf Leases, Zertifikate und die Auditkette; deshalb gesicherte Zeitquellen und Selbstabschottung bei zu großer Abweichung (INV-32) |
| **Konnektorprozess** | eigener Systembenutzer je Mandant und Bindung, eigener Netznamensraum, ausgehende Positivliste, `NoNewPrivileges`, `ProtectSystem=strict`, `PrivateTmp`, Leerlaufabschaltung nach 10 min | erzwingend, je Bindung erzeugt | Positivliste, Rückfall `KILL_PROCESS` | Keine Abweichung; dies ist das strengste Profil im System und die Bezugsgröße für alle anderen Zeilen |
| **Dienstprozess (Katalogeintrag)** | eigene Benutzerkennung je Dienst, Ausführung ohne Rootrechte wo möglich, `ProtectSystem=strict`, `PrivateTmp`, `PrivateDevices`, schreibbar nur der eigene Speicherbereich, Ressourcenbudget je Dienst | erzwingend, aus der Erzeugungsvorlage | Positivliste aus der Erzeugungsvorlage | Abweichungen sind ausschließlich deklarierte Eigenschaften des Katalogeintrags und in der Konsole am Dienst sichtbar (INV-30) |
| **Supportzugang (SSH)** | nicht dauerhaft gestartet, Socketaktivierung nach auditiertem Vorgang, Befristung mit erzwungenem Sitzungsende | erzwingend | Positivliste | Ein dauerhaft offener Verwaltungszugang widerspricht der Aussage, dass der Normalbetrieb kein Terminal braucht (INV-26) |

Modellrechnung zur Filtergröße: Auf der Zielarchitektur steht eine Aufrufmenge in der Größenordnung von rund 350 Systemaufrufen zur Verfügung (Annahme). Zielwert für den Dauerprozess atrium-core sind höchstens 90 zugelassene Aufrufe, für einen Konnektorprozess höchstens 60. Daraus folgt eine Reduktion der erreichbaren Aufrufmenge um 74 beziehungsweise 83 Prozent. Das ist ein Modell und keine Messung; die tatsächlichen Listen entstehen aus einer Aufzeichnung des Aufrufbedarfs unter Last und sind Gegenstand von R-A4-13. Eine zu eng gesetzte Liste tötet Prozesse im Betrieb, weshalb die Liste eine Freigabeprüfung mit Lastlauf durchläuft und nicht aus einer Schätzung stammt.

## A4.8 Netz- und Portmatrix

Aufbauend auf KANON.md, Abschnitt 7. Ergänzt um Quellzone, Zielzone, Zweck, Erzeugungsquelle der Regel und die Angabe, ob der Port aus dem Außennetz erreichbar sein darf. Jede Regel wird aus einem Objekt erzeugt; einen Dialog zum Anlegen einer Firewallregel gibt es nicht (INV-09).

| Port | Quellzone | Zielzone | Zweck | Regel erzeugt aus | Von außen erreichbar |
|---|---|---|---|---|---|
| 443/tcp, 443/udp | Außennetz, intern | Eingangszone | Atrium Console, öffentliche API, veröffentlichte Dienste | Veröffentlichung | ja |
| 80/tcp | Außennetz | Eingangszone | Umleitung auf HTTPS und ACME-Nachweis (RFC 8555) | Veröffentlichung mit externer Sichtbarkeit | ja |
| 8400/tcp | Loopback, Knoten-Overlay | Kontrollebene | API von atrium-core hinter dem Eingang | Knoten mit Kontrollebenenrolle | nein |
| 8401/tcp | Knoten-Overlay | Kontrollebene | Konsensverkehr zwischen Stimmknoten und Mitlesern | Knotenmitgliedschaft im Sollzustand | nein |
| 8402/tcp | Knoten-Overlay | Knotenagent | Sollzustandsauszüge und Istzustandsmeldungen | Knoten im Zustand produktiv | nein |
| 8403/tcp | lokales Netzsegment | Knoten im Wartemodus | Kopplungsendpunkt, nur im Wartemodus | Kopplungsvorgang | nein |
| 8404/tcp | Loopback, Knoten-Overlay | Eingangszone | Dynamische Programmierung des Eingangs | Veröffentlichungsmenge | nein |
| 8405/tcp | intern | Kontrollebene | SCIM-Server (RFC 7643, RFC 7644), außen über 443 veröffentlicht | Konnektorbindung mit SCIM-Fähigkeit | nein |
| 8406/tcp | intern, Knoten-Overlay | Kontrollebene | Interner ACME-Server für Dienst-, Knoten- und Gerätezertifikate | Veröffentlichung, Knoten, Gerät | nein |
| 8407/tcp | intern, Außennetz | Kontrollebene | Sperrlistenverteilung und OCSP (RFC 6960) | CA-Objekt | ja |
| 8408/tcp | Knoten-Overlay | Kontrollebene | Telemetrieempfang von Knoten | Knoten im Zustand produktiv | nein |
| 8409/tcp | Loopback | eigener Knoten | Gesundheits- und Bereitschaftsabfrage | erzeugte Einheit | nein |
| kein Port | Dateisystem | Konnektorprozess | Konnektorvertrag über Unix-Socket | Konnektorbindung | nein |
| 53/udp, 53/tcp | Außennetz, intern | autoritativer DNS | Auflösung externer und interner Domänen | Domäne mit Sichtbarkeit extern oder intern | ja für externe Domänen, nein für ausschließlich interne |
| 53/udp, 53/tcp | Netzzone eines Mandanten | rekursiver Resolver | Auflösung für Geräte und Dienste der Zone | Netzzone | nein |
| 853/tcp | intern, verwaltete Geräte | rekursiver Resolver | Verschlüsselte Auflösung (RFC 7858) | Netzzone, Gerät | nein |
| 443/tcp unter eigenem Namen | intern, verwaltete Geräte | rekursiver Resolver | Verschlüsselte Auflösung (RFC 8484) | Netzzone, Gerät | nein |
| 636/tcp | intern | Protokollkopf | Verzeichniszugriff (RFC 4511) für Altanwendungen | Zuweisung mit LDAP-Bedarf | nein; 389/tcp bleibt geschlossen |
| 1812/udp | Netzzugangszone | RADIUS-Dienst | Netzzugang über EAP-TLS (RFC 5216) gegen die interne PKI | Gerät mit Netzzugangsrecht | nein |
| 1813/udp | Netzzugangszone | RADIUS-Dienst | Abrechnungsdaten der Netzgeräte | Gerät mit Netzzugangsrecht | nein |
| 2083/tcp | Standortverbund | RADIUS-Dienst | RadSec (RFC 6614) über Weitverkehrsstrecken | Netzzone mit standortübergreifendem Zugang | bedingt; nur wenn keine Overlay-Strecke besteht, dann gegenseitiges TLS |
| 25/tcp | Außennetz | Mailübergabe | Serverübergabe (RFC 5321) mit DANE (RFC 7672) oder MTA-STS (RFC 8461) | Maildomäne mit internem Postfachdienst | ja |
| 587/tcp | Außennetz, intern | Mailübergabe | Einlieferung durch Clients | Maildomäne mit internem Postfachdienst | ja |
| 465/tcp | Außennetz, intern | Mailübergabe | Einlieferung mit implizitem TLS | Maildomäne mit internem Postfachdienst | ja |
| 993/tcp | Außennetz, intern | Postfachdienst | Postfachzugriff (RFC 3501) | Postfach mit internem Ablageort | ja |
| 51820/udp | Unterlagerungsnetz | Unterlagerungsnetz | Overlay zwischen allen Knoten | Knoten im Zustand gekoppelt | nein |
| 7789/tcp aufsteigend | Speichernetz, Knoten-Overlay | Speicherträger | Synchrone Blockreplikation je Speicherbereich | Speicherbereich der Stufe "Synchron gespiegelt" | nein |
| 22/tcp | Knoten | Auslagerungsziel | Asynchrone Replikation und externe Auslagerung | Sicherungsplan des Speicherbereichs | nein, ausgehend |
| 22/tcp | intern | Knoten | Befristeter Supportzugang | Vorgang "Supportzugang freigeben" | nein |
| 123/udp, 4460/tcp | Knoten | Außennetz | Gesicherte Zeitsynchronisation gegen externe Quellen | Knoten, Zeitrichtlinie | nein, ausgehend |
| 123/udp | intern | Ankerknoten | Zeitquelle für Knoten und Geräte | Netzzone | nein |
| 6514/tcp | Knoten | Auditziel, Protokollziel | Protokollausleitung (RFC 5424) über TLS | Konnektorbindung des Protokollziels | nein, ausgehend |

Auszählung der Außenfläche: Die Matrix hat 32 Zeilen. Im Auslieferungszustand ohne internen Postfachdienst, ohne Weitverkehrs-Netzzugang und ohne Altanwendungen sind aus dem Außennetz genau vier Portnummern erreichbar: 443, 80, 53 und 8407. Dahinter stehen drei Prozesse: der Eingangsproxy, der autoritative DNS-Dienst und atrium-core mit Sperrlistenverteilung. Die 53 und der autoritative Dienst stehen darin, weil das Mindestprofil mindestens eine Domäne mit Sichtbarkeit extern führt; bei ausschließlich internen Domänen entfallen beide, und es bleiben drei Portnummern und zwei Prozesse. Mit internem Postfachdienst kommen 25, 587, 465 und 993 hinzu, also acht Portnummern und vier Prozesse. Die Zahl der Ports ist dabei der schwächere Maßstab; entscheidend ist die Zahl der Prozesse, die ohne vorherige Authentisierung Daten strukturell auswerten. Diese Zahl ist im Mindestprofil drei und steigt mit dem Postfachdienst auf vier. Zwei dieser Prozesse sind nicht in einer speichersicheren Sprache geschrieben, was in 20.5 ausgesprochen und hier in Zahlen sichtbar gemacht wird.

## A4.9 Prüfliste für die Freigabe einer Version

Jede Prüfung ist blockierend. "Blockierend" heißt hier nicht, dass eine Freigabe untersagt ist, sondern dass das Freigabewerkzeug ohne bestandene Prüfung keinen Transparenzprotokolleintrag erzeugt und ohne diesen Eintrag kein Knoten das Abbild annimmt (R-20-22).

| Nr. | Prüfung | Bestehenskriterium | Bindung |
|---|---|---|---|
| F-01 | Reproduzierbarer Zweitbau auf unabhängiger Infrastruktur | bitgleiche Artefakte | R-20-18, E-13 |
| F-02 | Bau ohne Netzzugang | 0 Netzzugriffsversuche während des Baus | E-13 |
| F-03 | Stücklisten erzeugt | je Abbild eine SPDX- und eine CycloneDX-Stückliste, mitsigniert | R-20-20 |
| F-04 | Stücklistenabgleich gegen Schwachstellenquellen | 0 offene Treffer der Stufe S0 und S1 ohne Entscheidung | R-20-28 |
| F-05 | Positivliste der Oberflächenbegriffe | 0 Verstöße in Konsolentexten, Meldungen, Berichten und der Fremdfehlerübersetzung | INV-16, K-27 |
| F-06 | Entscheidungszahl je Standardaufgabe | 0 Aufgaben mit mehr als drei Entscheidungen | INV-14, K-03 |
| F-07 | Meldungstexte | 0 Meldungen, deren einziger Lösungsweg ein Konsolenbefehl ist; 0 Meldungen mit Pfaden, Abfragetexten oder Ausnahmebezeichnern | INV-17, R-20-19 |
| F-08 | Barrierefreiheit | 0 Verstöße in der automatisierten Prüfung nach WCAG 2.2 Stufe AA; Tastaturdurchlauf des Aufgabenkatalogs bestanden | INV-31 |
| F-09 | Vorbelegungen | 0 Formularfelder ohne benannte Quelle | INV-15 |
| F-10 | STRIDE-Abdeckung | 48 von 48 Zeilen aus A4.5 mit zugeordnetem Test, alle bestanden | R-20-02, R-A4-02 |
| F-11 | Missbrauchsfälle | 12 von 12 Fällen aus A4.6 mit bestandenem Test | R-A4-09 |
| F-12 | Erreichbarkeitstest | 0 erfolgreiche Verbindungen aus einer externen Zone auf jede Zeile mit "von außen erreichbar: nein" | R-A4-14, INV-10 |
| F-13 | Default-Deny bei Abbruch | Erreichbarkeitstest nach abgebrochener Regelanwendung ergibt 0 offene Wege | INV-10 |
| F-14 | Idempotenz | Doppellauf des Reconcilers erzeugt 0 Änderungsereignisse | INV-07 |
| F-15 | Nebenwirkungsfreiheit der Wirkungsvorschau | Konnektor-Vertragstest: Istzustand des Fremdsystems nach `plan` unverändert | INV-08 |
| F-16 | Partitionstest | Minderheitsseite lehnt jede Lösch- und Verlagerungsoperation ab | INV-04 |
| F-17 | Uhrensprungtest | Knoten mit injizierter Abweichung oberhalb der Zielgrenze führt nicht, stellt nicht aus, schottet sich ab | INV-32, K-30 |
| F-18 | Geheimnisausgabe | 0 Treffer gegen Geheimnismuster in Protokollen, Exporten, Wirkungsvorschauen und Wiederherstellungspunkten | R-20-14, INV-20 |
| F-19 | Sicherungsinhalt | 0 Wurzelschlüssel in einer Sicherung des laufenden Systems | INV-22 |
| F-20 | Härtungsprofile | jede Komponente aus A4.7 mit erzwingendem Zugriffskontrollprofil, Positivliste und, bei Abweichung, begründetem Ausgleich | R-A4-06, R-A4-07, R-A4-08 |
| F-21 | Systemaufruflisten | Größe jeder Positivliste unterhalb der Obergrenze; Lastlauf ohne Tötungsereignis | R-A4-13 |
| F-22 | Schema- und Laufzeittrennung | das Abbild enthält keine gleichzeitige Schema- und Laufzeitänderung | INV-24 |
| F-23 | Rückfallprobe | Aktualisierung ohne Gesundheitssignal führt innerhalb der Frist zum selbsttätigen Rückfall in die vorherige Abbildhälfte | K-23 |
| F-24 | Restrisikoliste | jede Zeile aus A4.5 und A4.6 mit nicht leerem Restrisiko ist in der maschinenlesbaren Restrisikoliste der Freigabe enthalten | R-A4-12 |

Zeitmodell für den Freigabelauf: Annahme sind 24 Prüfungen mit einer Summe der Einzellaufzeiten von 210 min, davon eine nicht parallelisierbare Kette aus Zweitbau (40 min) und Abbildprüfung (20 min), zusammen 60 min. Bei Parallelität 4 ergibt sich max(210 / 4, 60) = max(52,5, 60) = 60 min. Der Zielwert liegt bei 90 min mit Reserve. Nicht enthalten ist die Wiederherstellungsübung nach K-24, die monatlich und nicht je Freigabe läuft; das ist eine bewusste Entscheidung, weil eine 60-minütige Übung je Freigabe die Freigabefrequenz bestimmen würde statt der Inhalt. Die Folge wird ausgesprochen: Zwischen zwei Übungen kann eine Freigabe die Wiederherstellbarkeit brechen, ohne dass es auffällt.

## A4.10 Anforderungen

| ID | Anforderung | Herkunft |
|---|---|---|
| R-A4-01 | Jedes Schutzgut aus A4.2 trägt genau eine Eigentümerrolle; ein Schutzgut ohne Eigentümerrolle bricht die Freigabe. | INV-19, R-20-01 |
| R-A4-02 | Jede der 48 Zeilen aus A4.5 nennt genau ein Erkennungssignal als Auditereignistyp oder als benannten Zähler; eine Zeile ohne Signal bricht den Bau. | R-20-02 |
| R-A4-03 | Jedes Erkennungssignal aus A4.5 ist in der Atrium Console ohne Terminal abfragbar und an das verursachende Objekt verlinkt. | INV-17, INV-26, K-26 |
| R-A4-04 | Jede Zeile der Portmatrix A4.8 nennt genau ein Quellobjekt, aus dem die Regel erzeugt wird; eine Regel ohne Quellobjekt existiert in keinem erzeugten Regelsatz. | INV-09, INV-10 |
| R-A4-05 | Im Auslieferungszustand ohne internen Postfachdienst und ohne Weitverkehrs-Netzzugang sind aus dem Außennetz höchstens 4 Portnummern und höchstens 3 Prozesse erreichbar. | INV-10 |
| R-A4-06 | Jede Komponente aus A4.7 läuft mit einem Zugriffskontrollprofil im erzwingenden Modus; ein Profil im beobachtenden Modus im Auslieferungszustand bricht die Freigabe. | R-20-25 |
| R-A4-07 | Jeder Systemaufruffilter aus A4.7 ist eine Positivliste mit Rückfall `KILL_PROCESS`; eine Sperrliste oder ein Rückfall auf einen Fehlercode bricht die Freigabe. | R-20-25 |
| R-A4-08 | Jede Abweichung vom Profil des Konnektorprozesses trägt in A4.7 eine Begründung und einen benannten Ausgleich; eine Abweichung ohne beides bricht die Freigabe. | R-20-25 |
| R-A4-09 | Zu jedem Missbrauchsfall aus A4.6 existiert ein automatisierter Test, der die genannte Verhinderung oder Erkennung nachweist; Zielwert 12 von 12. | R-20-02 |
| R-A4-10 | Eine Freigabe ohne vollständig bestandene Prüfliste A4.9 erzeugt keinen Transparenzprotokolleintrag; die Blockade ist technisch und nicht organisatorisch. | R-20-21, R-20-22 |
| R-A4-11 | Ein Knoten mit anbieterseitig mitlesbarer Fernkonsole lehnt den Kopplungscodeweg ab und verlangt das gekennzeichnete Aufnahmetoken. | R-20-36 |
| R-A4-12 | Jedes nicht leere Restrisiko aus A4.5 und A4.6 ist Eintrag einer maschinenlesbaren Restrisikoliste, die mit jeder Freigabe veröffentlicht wird. | INV-18 |
| R-A4-13 | Die Zahl zugelassener Systemaufrufe je Komponentenprofil wird im Bau gezählt und gegen eine je Komponente festgelegte Obergrenze geprüft; eine Überschreitung bricht den Bau, ein Tötungsereignis im Lastlauf bricht die Freigabe. | R-20-25 |
| R-A4-14 | Für jede Zeile aus A4.8 mit "von außen erreichbar: nein" weist ein Erreichbarkeitstest aus einer externen Zone 0 erfolgreiche Verbindungen nach. | INV-10 |
| R-A4-15 | Jede Vertrauensgrenze aus A4.4 nennt Authentisierung, Autorisierung und Verschlüsselung; steht dort "entfällt", enthält dieselbe Zeile die Begründung. | INV-01 |

## Akzeptanzkriterien

| Kriterium | Anforderung | Nachweis |
|---|---|---|
| 12 von 12 Schutzgütern tragen eine Eigentümerrolle; ein Schutzgut ohne Rolle bricht die Freigabeprüfung F-24 | R-A4-01 | Vollständigkeitsprüfung der Wertetabelle im Bau |
| 48 von 48 STRIDE-Zeilen tragen ein benanntes Erkennungssignal und einen zugeordneten Test | R-A4-02 | Abdeckungsprüfung F-10 |
| Jedes Erkennungssignal ist in einem Durchlauf der Konsole ohne Terminal auffindbar und führt auf das verursachende Objekt | R-A4-03 | Tastaturdurchlauf mit Zielobjektprüfung |
| Der erzeugte Regelsatz enthält 0 Regeln ohne Quellobjektverweis | R-A4-04 | Abgleich erzeugter Regelsatz gegen Objektgraph |
| Ein Portscan aus einer externen Zone gegen das Mindestprofil findet genau 4 offene Portnummern | R-A4-05 | Erreichbarkeitstest F-12 |
| 0 Komponenten mit Zugriffskontrollprofil im beobachtenden Modus im Auslieferungsabbild | R-A4-06 | Profilinventur F-20 |
| 0 Komponenten mit Sperrlistenfilter oder Rückfall auf Fehlercode | R-A4-07 | Profilinventur F-20 |
| Jede Abweichung in A4.7 nennt Begründung und Ausgleich; 0 Zeilen ohne beides | R-A4-08 | Tabellenprüfung F-20 |
| 12 von 12 Missbrauchsfällen bestehen ihren Test | R-A4-09 | Testlauf F-11 |
| Ein Freigabeversuch mit einer nicht bestandenen Prüfung erzeugt 0 Transparenzprotokolleinträge | R-A4-10 | Negativtest des Freigabewerkzeugs |
| Ein Knoten mit gekennzeichneter Fernkonsole lehnt 100 Prozent der Kopplungscodeversuche ab | R-A4-11 | Kopplungstest mit gesetzter Kennzeichnung |
| Die Restrisikoliste der Freigabe enthält jede Zeile mit nicht leerem Restrisiko; Differenz 0 | R-A4-12 | Abgleich Restrisikoliste gegen A4.5 und A4.6 |
| Jede Positivliste liegt unter ihrer Obergrenze; der Lastlauf erzeugt 0 Tötungsereignisse | R-A4-13 | Zählung und Lastlauf F-21 |
| 0 erfolgreiche Verbindungen auf als nicht extern erreichbar markierte Zeilen | R-A4-14 | Erreichbarkeitstest F-12 |
| 0 Zeilen in A4.4 mit "entfällt" ohne Begründung in derselben Zeile | R-A4-15 | Tabellenprüfung im Bau |

## Offene Punkte

1. **Zwei Kandidaten für zusätzliche Vertrauensgrenzen sind unentschieden.** Die Grenze zwischen zwei Mandanten innerhalb der gemeinsamen Kontrollebene und die Grenze des Netzzugangs (Gerät gegen RADIUS und Netzzone) sind derzeit in VG-03, VG-04 und VG-06 mitbehandelt. Beide haben eigene Angriffspfade, eigene Erkennungssignale und eigene Restrisiken. Eine Aufnahme als VG-09 und VG-10 erhöht die Zahl der Pflichttests von 48 auf 60 und ändert damit R-20-02 und die Prüfung F-10; die Entscheidung gehört in KANON.md und in [Kapitel 20](20-sicherheit.md), nicht in diesen Anhang.
2. **Erkennungssignale haben keinen definierten Empfänger und keine Schwellenwerte.** Die Spalte "Erkennungssignal" nennt Ereignistypen und Zähler, aber weder die Schwelle, ab der ein Zähler zu einer Störungsmeldung wird, noch die Rolle, die sie erhält, noch die Frist, innerhalb derer sie beantwortet sein muss. Ohne diese drei Angaben ist ein Signal eine Protokollzeile und keine Maßnahme. Ein Katalog mit Schwelle, Empfängerrolle und Frist je Signal fehlt und ist vor der ersten Implementierung der Störungsanzeige zu erstellen.
3. **Die Obergrenzen der Systemaufruf-Positivlisten sind geschätzt.** Die Werte 90 und 60 aus A4.7 stammen aus einer Modellannahme, nicht aus einer Aufzeichnung. Zu eng gesetzte Listen erzeugen Prozessabbrüche im Betrieb, zu weite Listen sind keine Maßnahme. Offen ist, ob die Obergrenze je Komponente fest ist oder als Nichtverschlechterung gegenüber der Vorversion geführt wird, und wie eine Erhöhung freigegeben wird.
4. **VG-07 hat bei Information Disclosure kein Erkennungssignal.** Ein Lesezugriff am Auslagerungsziel ist auf der Atrium-Seite prinzipiell nicht beobachtbar. Die Verschlüsselung vor der Übertragung begrenzt den Schaden, ersetzt aber keine Erkennung. Offen ist die Entscheidung, ob ein zweites Auslagerungsziel mit unabhängigem Betreiber verpflichtend wird und wer die Mehrkosten trägt.
5. **Der Eigentümer des Aktualisierungskanals ist keine Rolle des Rechtemodells.** Die Wertetabelle führt dort "Hersteller". Damit hat genau ein Schutzgut mit Integritätsbedarf "sehr hoch" keinen Eigentümer innerhalb der Installation, und die Prüfliste A4.9 ist die einzige Kontrolle. Offen ist, ob der Plattformeigner eine Gegenkontrolle erhält, etwa eine unabhängige Beobachtung des Transparenzprotokolls, und wie diese ohne Internetzugang funktioniert.
6. **Die Veröffentlichung der Restrisikoliste ist zweischneidig.** R-A4-12 verlangt eine maschinenlesbare Liste je Freigabe. Dieselbe Liste ist eine geordnete Beschreibung der Stellen, an denen das System nachweislich nicht schützt. Offen ist die Granularität: eine Liste, die nur Kategorien nennt, ist für Betreiber wertlos; eine Liste, die Pfade nennt, ist eine Vorlage. Eine Entscheidung über zwei Fassungen mit unterschiedlichem Empfängerkreis ist zu treffen und würde der Zusage widersprechen, dass es nur eine Wahrheit gibt.
7. **Die obere Portgrenze der synchronen Blockreplikation ist nicht festgelegt.** A4.8 führt "7789/tcp aufsteigend, je Speicherbereich ein Port". Ohne Obergrenze ist die Zahl der Regeln unbestimmt und der reservierte Bereich nicht abgegrenzt. Offen ist, ob ein zweiter Bereich reserviert wird oder ob die Zahl synchron replizierter Speicherbereiche je Knoten begrenzt wird; die zweite Antwort ist eine Kapazitätsentscheidung und gehört zu [Kapitel 17](17-speicher-backup.md).
