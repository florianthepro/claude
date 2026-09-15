# Anhang F: Glossar und Standardverzeichnis

## F.0 Geltung, Aufnahmekriterium und Spaltenbedeutung

Dieser Anhang ist eine Nachschlageliste, keine Quelle neuer Festlegungen. Weicht ein Eintrag von `KANON.md` ab, gilt `KANON.md`; Abweichungen dieses Anhangs sind Fehler dieses Anhangs.

Aufnahmekriterium für F.1: Ein Begriff steht hier, wenn er in mindestens einer Kapiteldatei 02 bis 23 oder in einem der Anhänge A bis D im Volltext vorkommt. Begriffe, die nur in `KANON.md` stehen, aber in keinem Kapitel benutzt werden, sind nicht aufgenommen; zwei solche Fälle sind unter Offene Punkte benannt. Aufnahmekriterium für F.2 und F.3: Der Standard oder Rechtsakt steht in Abschnitt 8 von `KANON.md` **und** wird in mindestens einem Kapitel zitiert. Fundstellen sind unverändert aus Abschnitt 8 übernommen; dieser Anhang vergibt keine Nummern und ergänzt keine.

Die Spalte "Erstverwendung" nennt die niedrigste Kapitelnummer, in der der Begriff vorkommt. Kapitel 01 (Zusammenfassung) ist ausgenommen, weil es als Verdichtung nahezu jeden Kernbegriff enthält und die Angabe damit ohne Informationswert wäre. Anhänge werden als `A` (Schemata), `B` (API-Referenz), `C` (Architekturentscheidungen) und `D` (Bedrohungsmodell) geführt. Die Spalte "Englisch" nennt die gebräuchliche englische Entsprechung; ein Strich bedeutet, dass keine etablierte Entsprechung existiert und eine wörtliche Übersetzung irreführend wäre.

## F.1 Glossar

| Begriff | Definition | Englisch | Erstverwendung |
|---|---|---|---|
| **Abgeleitetes Artefakt** | Objekt, das ausschließlich aus einer Quelle im Sollzustand berechnet wird: Firewallregel, Proxyroute, DNS-Eintrag für einen Dienst, Zertifikatsantrag oder Fremdkonto. Es trägt immer einen Verweis auf seine Quelle, kennt keine Schreiboperation in der API und verfällt mit der Quelle (INV-09). | derived artifact | 03 |
| **Abgleich** | Ein Lauf des Reconcilers: Lesen des Sollzustands, Beobachten des Istzustands, Berechnen der Differenz, Anwenden der Differenz. Der Abgleich ist idempotent (INV-07); sein Ergebnis heißt Konvergenz. | reconciliation | 02 |
| **Abgleichintervall** | Attribut einer Konnektorbindung, das angibt, in welchem Abstand der Konnektor den Istzustand des Fremdsystems beobachtet, unabhängig von ereignisgetriebenen Aufrufen. | sync interval | 04 |
| **A/B-Wurzeldateisystem** | Zwei vollständige, signierte, mit dm-verity geprüfte Systemabbilder je Knoten. Eine Aktualisierung schreibt in die inaktive Hälfte und schaltet beim Neustart um; bleibt das Gesundheitssignal aus, fällt der Knoten selbsttätig auf die vorherige Hälfte zurück. | A/B root filesystem | 05 |
| **Anbietermodus** | Betriebsart, in der eine Installation Mandanten für Dritte führt. Sie macht den Navigationsbereich "Mandanten & Rechte" sichtbar, auch wenn nur ein Mandant existiert. | service-provider mode | 22 |
| **Ankerknoten** | Der Knoten, auf dem die Kontrollebene zuerst konstituiert wurde. Er trägt die Ausgabe-CA und ist interne Zeitquelle; seine Sonderstellung ist eine Frage des Schlüsselmaterials, nicht des Konsenses. | — | 05 |
| **Anmeldename** | Aus dem Anzeigenamen nach Mandantenrichtlinie abgeleiteter, kollisionsauflösender technischer Name. Er ist nach der Erzeugung unveränderlich und wird nie eingegeben. | login name | 02 |
| **Antiaffinität** | Platzierungsregel, die Replikate oder Instanzen auf verschiedene Fehlerzonen zwingt. Sie ist die einzige Regel, aus der die Platzierung eine Ablehnung ableiten darf, ohne dass ein Ressourcenbudget erschöpft ist. | anti-affinity | 03 |
| **Anzeigename** | Frei wählbarer, Unicode-fähiger, nach NFC normalisierter Name eines Objekts, je Mandant und Entitätstyp eindeutig. Er ist nie Ziel eines Verweises; Umbenennungen sind folgenlos. | display name | 03 |
| **atrium-audit** | Die Komponente, die den hashverketteten Auditstrom außerhalb des replizierten Zustands führt, periodisch signiert und mit Zeitstempel versieht. Sie läuft unter eigenem Systembenutzer ohne Capability. | — | 05 |
| **atrium-connector-\<name\>** | Der Konnektorprozess. Je Mandant und Konnektorbindung läuft genau einer, unter eigenem Systembenutzer, in eigenem Netznamensraum, angesprochen über einen Unix-Socket. | — | 05 |
| **Atrium Console** | Die einzige reguläre Bedienoberfläche. Sie benutzt ausschließlich die öffentlich dokumentierte API (INV-01); ein privater Pfad existiert nicht. Nie "Web-UI", nie "Admin-Panel". | — | 02 |
| **atrium-core** | Die Kontrollebene als Dienst: Sollzustand, Konsens, Reconciler, Platzierung, Ausgabe-CA, ACME, OCSP, Sperrlisten, xDS, SCIM und OTLP-Empfang. Je Verwaltungsknoten läuft ein Prozess ohne Capability. | — | 04 |
| **atriumctl** | Das Kommandozeilenwerkzeug für Support und Automatisierung. Es benutzt dieselbe API wie die Konsole und ist für den Normalbetrieb nicht erforderlich (INV-26). | — | 03 |
| **atrium-eingang** | Der Systembenutzer und die systemd-Einheit des Eingangsproxys. Die einzige zugeteilte Capability ist `CAP_NET_BIND_SERVICE` für die Bindung an 80/tcp, 443/tcp und 443/udp. | — | 05 |
| **atrium-link** | Die Hilfseinheit, die WireGuard-Schnittstellen, Adressen und Routen über netlink setzt. Sie darf keine Filterregeln setzen und keine Schlüssel exportieren. | — | 05 |
| **atrium-netfilter** | Die Hilfseinheit, die den vollständigen nftables-Regelsatz in einer netlink-Transaktion atomar tauscht. Ein abgebrochener Tausch endet auf Default-Deny. | — | 05 |
| **atrium-node** | Der Knotenagent: materialisiert den signierten Sollzustandsauszug, erzeugt die Dienst-Units, ruft Konnektoren auf, meldet Istzustand und hält die Lease. Er akzeptiert keine direkten Schreibaufrufe (INV-03). | — | 05 |
| **Atrium Server OS** | Die Distribution auf Ubuntu-LTS-Basis, kurz Atrium. Nie "AtriumOS", nie "Atrium OS". | — | 03 |
| **atrium-speicher** | Die Hilfseinheit, die ZFS-Datasets anlegt und einhängt. Sie trägt `CAP_SYS_ADMIN` und ist damit die schwächste Stelle des Rechtemodells der Schicht S4. | — | 05 |
| **Auditereignis** | Unveränderlicher Nachweis einer schreibenden Operation, einer Anmeldung, einer Zertifikatsausstellung oder eines Notzugriffs, mit Zeitstempel, Akteur, Mandant, Vorher/Nachher und Vorgängerhashwert. Auditereignisse überleben die Löschung des Objekts, auf das sie verweisen (INV-23). | audit event | 02 |
| **Auditkette** | Die Hashverkettung aller Auditereignisse eines Stroms, periodisch signiert und mit einem Zeitstempel nach RFC 3161 versehen. Sie wird bei jedem Start und bei jedem Export geprüft. | audit chain | 03 |
| **Auditstrom** | Der anhängbare Speicher der Auditereignisse, getrennt vom Betriebsprotokoll und außerhalb des replizierten Kernzustands geführt. | audit trail | 03 |
| **Aufbewahrungsfrist** | Zeitraum zwischen der Löschentscheidung und der tatsächlichen Freigabe eines Datenträgers oder der Verdichtung eines Auditbestands. Sie macht jede Löschung um die Fristdauer rücknehmbar (INV-11). | retention period | 03 |
| **Aufnahmetoken** | Einmalig verwendbares, kurzlebiges Geheimnis mit Pinning des CA-Fingerabdrucks, das die Kopplung ohne anwesenden Menschen erlaubt. Es ist der ausdrücklich schwächere Weg gegenüber dem Kopplungscode und als solcher gekennzeichnet und auditiert. | enrollment token | 03 |
| **Ausgabe-CA** | Die zweite Stufe der internen PKI: die von der Wurzel-CA signierte, im laufenden System betriebene Zertifizierungsstelle mit TPM- oder Token-gebundenem Schlüssel. Sie signiert Knotenzertifikate und je Mandant eine Zwischen-CA. | issuing CA | 05 |
| **Ausgangs-Positivliste** | Die aus einer Konnektorbindung erzeugte Liste der Ziele, die ein Konnektorprozess in seinem Netznamensraum erreichen darf. Alles nicht Gelistete ist unerreichbar, einschließlich der API der Kontrollebene (INV-21). | egress allowlist | 03 |
| **Beobachtungszeitpunkt** | Der Zeitpunkt, zu dem ein Istzustand zuletzt beobachtet wurde. Jede Istansicht nennt ihn; eine Istansicht ohne Beobachtungszeitpunkt bricht den Bau (INV-28). | observation time | 02 |
| **Bootstrapzertifikat** | Selbstsigniertes Zertifikat mit Restlaufzeit von höchstens 60 Minuten, das atrium-node bei der Erstinstallation im TPM erzeugt, um den Kopplungsendpunkt auf Loopback abzusichern. Es wird nach Ausstellung des regulären Knotenzertifikats gesperrt. | bootstrap certificate | 05 |
| **CA** | Zertifizierungsstelle der internen zweistufigen PKI. Drei Typen existieren: Wurzel-CA (offline), Ausgabe-CA (im System) und Mandanten-Zwischen-CA (je Mandant eine, ab M0). | certificate authority | 03 |
| **Datenebene** | Die Ebene, die Nutzverkehr führt und Dienste ausführt, im Gegensatz zur Kontrollebene, die Absichten verwaltet. Dienste der Datenebene überleben den Ausfall der Kontrollebene (INV-25). | data plane | 02 |
| **Datenklasse** | Vorgabe eines Katalogeintrags, welche Art personenbezogener oder schutzbedürftiger Daten ein Dienst führt. Sie steuert Vorbelegungen für Speicherort, Sicherung und Auslagerung. | data class | 03 |
| **Datensicherheitsstufe** | Das einzige Speicherfeld, das ein Bediener je Dienst setzt: **Lokal**, **Gespiegelt** oder **Synchron gespiegelt**. Welche Technik die Stufe erfüllt, leitet der Kern aus der Knotenzahl ab; das daraus folgende RPO wird am Dienst angezeigt. | data durability tier | 02 |
| **Default-Deny** | Grundregel, nach der jede Erreichbarkeit aus einer Veröffentlichung folgt und nie aus einer eingetragenen Regel. Jeder Fehlerzustand eines abgeleiteten Regelsatzes fällt auf Default-Deny zurück (INV-10). | default deny | 03 |
| **Dienst** | Die fachliche Einheit, die der Bediener sieht: eine laufende Instanz eines Katalogeintrags bei einem Mandanten, mit Platzierung, Ressourcenbudget, Datensicherheitsstufe und Veröffentlichungen. | service | 02 |
| **Dienstkonto** | Nicht-menschliche Identität für Automatisierung und Konnektoren, mit Pflichtablauf und erlaubten Quelladressen. Es ist Subjekt von Zuweisungen, aber nie Mitglied einer Personengruppe. | service account | 07 |
| **Dienstträger** | Knotenrolle: der Knoten führt Dienste aus. Sie ist unabhängig von Stimmrecht und Speicherrolle. | — | 06 |
| **Dienstzertifikat** | Endzertifikat, dessen Inhaber ein Dienst ist. Es wird automatisch aus dem Sollzustand beantragt und über SDS in den Eingangsproxy geliefert, nie über eine Datei. | service certificate | 05 |
| **DNS-Eintrag** | Einzelner Name innerhalb einer Domäne mit Art, Wert, TTL und ausdrücklicher Sichtzugehörigkeit. Abgeleitete Einträge tragen einen Verweis auf Veröffentlichung, Maildomäne oder Knoten und sind nicht editierbar. | DNS record | 02 |
| **Domäne** | Namensraum mit Sichtbarkeit, Geltungsbereich, DNSSEC-Status und Serie. Eine Domäne erzeugt bis zu zwei Zoneninstanzen, eine interne und eine externe. | domain | 02 |
| **Drift** | Das Auseinanderlaufen von Sollzustand und Istzustand ohne auslösenden Vorgang, etwa durch Handänderung, Fremdänderung oder verlorene Ereignisse. Der periodische Volllauf ist der Pfad, der Drift erkennt und korrigiert. | drift | 02 |
| **Driftpfad** | Der periodische Volllauf über alle Objekte, ergänzend zum ereignisgetriebenen Abgleich, mit einem Zielwert von 60 Minuten (K-16). | — | 03 |
| **Eingang** | Der L7-Eingangsproxy (Envoy), ausschließlich dynamisch über xDS gesteuert. Er terminiert TLS, routet nach SNI und ist ausdrücklich kein Autorisierungspunkt. | ingress | 03 |
| **Eingangsträger** | Knotenrolle: der Knoten nimmt externen Verkehr an und führt den Eingangsproxy aus. Ab Isolationsstufe M1 existiert je Mandant ein eigener Listener. | — | 12 |
| **Eingefroren** | Zustand des Sollzustands ohne Quorum: keine Schreibvorgänge, keine Löschungen, keine Verlagerungen. Dienste laufen weiter und werden lokal neu gestartet (INV-04, INV-25). | frozen | 16 |
| **Entkoppeln** | Das Entfernen eines Knotens aus der Installation nach dem Räumen. Das Knotenzertifikat wird gesperrt und die Sperrliste sofort verteilt. | decommission | 07 |
| **Envoy** | Das fremdgepflegte L7-Proxyprodukt hinter dem Eingang. Es besitzt keine Konfigurationsdatei im Betrieb; jede Konfiguration ist ein versionierter Snapshot über xDS, Rückrollen ist das Ausliefern des vorherigen Snapshots. | — | 04 |
| **Fähigkeitsliste** | Das Ergebnis des `describe`-Aufrufs einer Konnektorbindung: welche Objektarten, Felder und Operationen das Fremdsystem in dieser Instanz tatsächlich unterstützt. Sie begrenzt, was die Wirkungsvorschau versprechen darf. | capability list | 09 |
| **Fehlerdomäne** | Die Menge von Objekten, die ein einzelner Ausfall gemeinsam trifft. Die Isolationsstufe eines Mandanten bestimmt, wie weit seine Fehlerdomäne von fremden Mandanten getrennt ist. | failure domain | 05 |
| **Fehlerzone** | Ausdrücklich deklarierte Ausfallabgrenzung: Stromkreis, Rack oder Standort. Sie ist die Grundlage jeder Antiaffinitätsregel und jeder Aussage über vertragene Knotenausfälle. | failure zone | 03 |
| **Feldeigentum** | Die je Feld deklarierte Festlegung, ob Atrium das Feld besitzt, das Fremdsystem es besitzt oder es nur bei Erstanlage gesetzt wird. Der Reconciler überschreibt ausschließlich Felder in eigenem Besitz; ein Manifest ohne vollständige Eigentumsangabe wird beim Import abgelehnt (INV-13). | field ownership | 02 |
| **Freigabe** | Die Zustimmung zu einem Vorgang vor seiner Ausführung, auf Basis der Wirkungsvorschau. Welche Änderung wessen Zustimmung braucht, legt der Freigabeweg fest. | approval | 02 |
| **Freigabeweg** | Regel, die einer Klasse von Vorgängen eine oder mehrere zustimmungsberechtigte Rollen zuordnet. Er ist ein Objekt im Sollzustand, keine Einstellung der Oberfläche. | approval workflow | 02 |
| **Geheimnis** | Zugangsdaten oder Schlüsselmaterial, im Sollzustand nur als Referenz geführt. Geheimnisse sind schreibbar, nie lesbar; Konnektorprozesse erhalten ausschließlich kurzlebige, auftragsgebundene Referenzen (INV-20). | secret | 03 |
| **Geltungsbereich** | Die Menge von Personen, Gruppen, Geräten oder Netzzonen, für die eine Domäne, eine Richtlinie oder eine Administratorrolle gilt. Bei Domänen wird der Geltungsbereich auf Netzzone und Gerätezertifikat abgebildet, nicht auf die Nutzersitzung. | scope | 04 |
| **Gerät** | Verwaltetes Endgerät, das kein Knoten ist: Träger von Zertifikaten, Netzzugangsrechten und Richtlinien, im Eigentum einer Person oder Gruppe. | device | 02 |
| **Gerätezertifikat** | Endzertifikat, dessen Inhaber ein Gerät ist. Es trägt den Netzzugang über EAP-TLS und entscheidet zusammen mit der Netzzone, welche DNS-Sicht das Gerät erhält. | device certificate | 04 |
| **Gesundheitssignal** | Die Rückmeldung eines Knotens nach einem Neustart in die zuvor inaktive Abbildhälfte. Bleibt es aus, schaltet der Knoten selbsttätig auf die vorherige Hälfte zurück. | health signal | 07 |
| **Grabstein** | Der Restzustand eines gelöschten Objekts: Kennung, Löschzeitpunkt und Verweise bleiben erhalten, die Nutzdaten nicht. Grabsteine machen Verweise auf gelöschte Objekte auflösbar und verhindern stille Wiederverwendung von Kennungen. | tombstone | 07 |
| **Gruppe** | Bündel aus Personen, Geräten und Gruppen für Rechte, Adressierung und Geltungsbereiche, azyklisch erzwungen. Sie ist der Ort der Massenwirkung: eine Zuweisung an eine Gruppe wirkt auf alle Mitglieder. | group | 02 |
| **Hilfseinheit** | Sammelbezeichnung für `atrium-netfilter`, `atrium-link` und `atrium-speicher`: kleine Einheiten mit je genau einer Capability, die die privilegierten Systemoperationen ausführen, die atrium-node und atrium-core selbst nicht ausführen dürfen. | — | 05 |
| **Idempotenz** | Eigenschaft, dass die zweite Anwendung derselben Operation weder Änderung noch Neustart noch ein Auditereignis vom Typ "geändert" erzeugt. Jeder Reconciler-Lauf und jeder Konnektoraufruf ist idempotent (INV-07). | idempotence | 02 |
| **Idempotenzschlüssel** | Die je Operation mitgeführte Kennung, an der ein Fremdsystem oder ein Konnektor eine Wiederholung als Wiederholung erkennt. Sie ist Pflichtbestandteil jedes `apply`-Aufrufs. | idempotency key | 03 |
| **Invariante** | Eine der 32 normativen Zusicherungen aus Abschnitt 2 von `KANON.md`, jede mit benannter maschineller Prüfung. Zitiert wird ausschließlich als `INV-nn`. | invariant | 03 |
| **Isolationsstufe** | Die je Mandant gewählte Trennungstiefe M0 bis M3, online steigerbar, Absenkung nur mit ausdrücklicher Bestätigung und Auditeintrag. Sie bestimmt die Fehlerdomäne des Mandanten. | isolation level | 03 |
| **Istzustand** | Das beobachtete Ergebnis mit Beobachtungszeitpunkt. Er ist nie Eingabe für Entscheidungen außer zur Abweichungsanzeige. Nie "Actual State". | actual state | 02 |
| **Kanidm** | Das fremdgepflegte Produkt hinter dem Protokollkopf. Seine eigene Verwaltungsoberfläche ist abgeschaltet; geschrieben wird ausschließlich durch den Reconciler. | — | 05 |
| **Katalogeintrag** | Signierte Beschreibung eines einsetzbaren Fremdprodukts mit Abbildverweisen, Speicherbereichsdefinition, benötigten Konnektoren, Standardveröffentlichung und Produktgrenzdeklaration. Er ist die Vorlage, aus der ein Dienst entsteht. | catalog entry | 02 |
| **Kennzahl** | Eine der nummerierten Zielgrößen aus Abschnitt 6 von `KANON.md`, zitiert als `K-nn`. Jede Kennzahl ist als Zielwert gekennzeichnet und aus einer Protokolleigenschaft oder einer benannten Annahme hergeleitet. | key figure | 02 |
| **Knot DNS** | Das fremdgepflegte autoritative DNS-Produkt, ausschließlich über seine Steuerschnittstelle transaktional bespielt. Jeder Verwaltungsknoten erzeugt dieselbe Zoneninstanz aus demselben Sollzustand; einen Zonentransfer gibt es nicht. | — | 05 |
| **Knoten** | Physische oder virtuelle Maschine mit Atrium, Träger von Kontrollebene, Diensten und Speicher. Ein Knoten trägt Rollen (Stimmknoten, Mitleser, Zeuge, Dienstträger, Speicherträger, Eingangsträger), eine Knotenklasse und eine Fehlerzone. | node | 02 |
| **Knotenagent** | Der Dienst auf jedem Knoten, der den Sollzustand lokal materialisiert; siehe atrium-node. | node agent | 05 |
| **Knotenklasse** | Hardwarebezogene Einstufung eines Knotens, die zulässige Platzierungen begrenzt. Ab Isolationsstufe M3 darf ein Mandant nur Knoten der ihm exklusiv zugeordneten Klasse belegen. | node class | 12 |
| **Knotenzertifikat** | Endzertifikat, dessen Inhaber ein Knoten ist, ausgestellt aus der Ausgabe-CA und wo vorhanden TPM-gebunden. Es authentisiert jede Verbindung zwischen Knoten und ist an das WireGuard-Schlüsselmaterial gebunden. | node certificate | 05 |
| **Knot Resolver** | Das fremdgepflegte validierende rekursive Resolver-Produkt, als getrennter Prozess auf getrennten, nur internen Adressen, eine Instanz je Netzzone mit eigener Sicht. | — | 05 |
| **Konnektorbindung** | Die Verbindung zu einer konkreten Instanz eines Fremdsystems für einen Mandanten, mit Endpunkt, Geheimnisverweis, Vertragsversion, Fähigkeitsliste und Feldeigentumsabbildung. Je Mandant und Bindung läuft ein eigener Prozess. | connector binding | 02 |
| **Konnektormanifest** | Die signierte Beschreibung eines Konnektors: unterstützte Objektarten, Feldeigentum je Feld, kostenwirksame Aktionen und Vertragsversion. Ohne vollständige Eigentumsangabe wird es beim Import abgelehnt. | connector manifest | 03 |
| **Konnektorvertrag** | Die eine Schnittstelle, die jeder Konnektor erfüllt: gRPC über einen Unix-Socket mit den Operationen `describe`, `observe`, `plan`, `apply` und `healthcheck`. Der Vertrag trägt eine eigene, semantisch versionierte Versionsreihe `vertrag_version`. | connector contract | 02 |
| **Kontrollebene** | Die Ebene, die Sollzustand, Konsens, Reconciler, PKI, SCIM und xDS erbringt; als Dienst atrium-core. Ihr Ausfall friert Änderungen ein, hält aber laufende Dienste nicht an. | control plane | 03 |
| **Konvergenz** | Das Ergebnis eines Abgleichs, bei dem Istzustand und Sollzustand übereinstimmen. Konvergenz ist ein Zustand, kein Ereignis; ihr Ausbleiben wird als benannte Abweichung angezeigt. | convergence | 02 |
| **Kopplungscode** | 12 Zeichen Crockford-Base32 (10 Zufallszeichen, 2 Prüfzeichen), dargestellt als `XXXX-XXXX-XXXX`. Er ist einmalig, höchstens 15 Minuten gültig und nach 5 Fehlversuchen vernichtet (INV-27). | pairing code | 03 |
| **Kopplungsvorgang** | Das kurzlebige Objekt, das die Aufnahme eines Knotens aus dem Wartemodus führt: Codehashwert, Erzeugungszeit, Gültigkeit, Fehlversuchszähler. Bei Erfolg entsteht genau ein Knoten; Auditereignisse entstehen in jedem Fall. | pairing operation | 04 |
| **Korrelationskennung** | Eine ULID je Vorgang, mitgeführt in jedem Auditereignis, jedem Konnektoraufruf und jeder Protokollzeile. Sie verbindet Bedienhandlung, Fremdsystemaufruf und Protokoll zu einer nachvollziehbaren Spur. | correlation ID | 03 |
| **Lease** | Zeitlich begrenzte Berechtigung eines Knotens, seine Arbeitslasten zu führen. Ohne gültige Lease beendet der Knoten sie selbst; die Übernahmefrist auf einem anderen Knoten ist stets größer als Leasefrist plus zugelassene Uhrenabweichung (INV-06). | lease | 03 |
| **Lesemodell** | Die deterministisch aus dem bestätigten Änderungsprotokoll materialisierte lokale Abfragesicht in einer eingebetteten SQL-Engine. Sie ist bei Schemaänderungen neu baubar und nie selbst Wahrheitsquelle. | read model | 05 |
| **Mailadresse** | Adresse, getrennt vom Postfach geführt, mit lokalem Teil, Maildomänenverweis, Kennzeichen primär oder zusätzlich und Sperrfristende. Eine entfernte Adresse ist erst nach Ablauf der Sperrfrist neu vergebbar. | mail address | 02 |
| **Maildomäne** | Verbindet eine Domäne mit einem Postfachanbieter und erzeugt die Mail-Namenseinträge: MX, SPF, DKIM, DMARC, MTA-STS und TLS-RPT. | mail domain | 07 |
| **Mandant** | Die oberste Eigentums-, Abrechnungs- und Isolationsgrenze. Jedes Objekt außer Plattform und Knoten gehört genau einem Mandanten; eine Abfrage ohne Mandantenprädikat wird abgelehnt (INV-19). Die englischen Begriffe "Tenant" und "Tenancy" sind verboten. | tenant | 02 |
| **Mandantenstufe** | Die Isolationsstufe eines Mandanten in ihrer Wirkung als Platzierungsbedingung: ab M3 nur Knoten der exklusiv zugeordneten Knotenklasse, ab M1 muss die Netzzone des Mandanten auf dem Knoten bestehen. | — | 15 |
| **Materialisierung** | Die deterministische Umsetzung eines bestätigten Sollzustands in etwas Ausführbares: das lokale Lesemodell in atrium-core, die Dienst-Units und Regelsätze in atrium-node. Gleicher Eingang erzeugt auf jedem Knoten gleiches Ergebnis. | materialization | 05 |
| **Mitleser** | Raft-Mitglied ohne Stimmrecht, das den Konsens mitliest und lesend bedienen kann. Konsolentext: "Verwaltungsknoten (mitlesend)". | learner | 08 |
| **Momentaufnahme** | Der konsistente Zustand eines Speicherbereichs zu einem Zeitpunkt, technisch als ZFS-Momentaufnahme. Der englische Begriff "Snapshot" ist in Konsolentexten verboten. | snapshot | 03 |
| **Netznamensraum** | Der eigene Netzkontext eines Konnektorprozesses oder Dienstes, in dem ausschließlich die Ziele der Ausgangs-Positivliste erreichbar sind. | network namespace | 03 |
| **Netzzone** | Abgegrenzter Netzbereich als Overlay-Segment, VLAN oder VRF. Sie ist die Grundeinheit aller Firewallableitungen und bestimmt, welche DNS-Sicht ein Gerät erhält. | network zone | 02 |
| **Notbetrieb** | Der ausdrücklich herbeigeführte Weiterbetrieb nach Quorumverlust durch erzwungene Neukonstituierung mit einem Knoten. Er ist mit dem Wiederherstellungscode geschützt, hat eine erzwungene Wartezeit und erzeugt ein nicht unterdrückbares Auditereignis. | — | 03 |
| **Notzugang** | Der physisch gebundene Weg zurück nach einer Aussperrung: Wiederherstellungscode plus Zugang an der physischen oder Fernkonsole eines Knotens mit Neustart in einen Wiederherstellungsmodus. Es gibt keinen Fernzugang und keine Hintertür. | break-glass access | 03 |
| **Objektgraph** | Die Gesamtheit der Objekte des Sollzustands mit ihren Beziehungen. Aus ihm werden alle abgeleiteten Artefakte berechnet; eine Regel, die nicht aus ihm folgt, existiert nicht. | object graph | 03 |
| **Person** | Die Identität eines Menschen und die alleinige Quelle aller abgeleiteten Fremdkonten. Eine gesperrte Person behält ihre Daten und verliert überall die Anmeldung. | person | 02 |
| **Platzierung** | Die Entscheidung, auf welchem Knoten ein Dienst läuft, berechnet aus Ressourcenbudget, Fehlerzonen, Knotenklasse und Isolationsstufe. Jede Platzierung trägt eine in einem Satz anzeigbare Begründung. | placement | 03 |
| **Podman** | Das daemonlose Container-Laufzeitprodukt, ausschließlich von atrium-node über erzeugte systemd-Einheiten angesprochen. Die Begriffe Container, Image und Pod sind in Konsolentexten verboten. | — | 04 |
| **Positivliste** | Eine abschließende Liste des Erlaubten, gegen die geprüft wird; das Gegenstück zur Ausschlussliste. Sie wird für Netzziele von Konnektoren und für zulässige Oberflächenbegriffe verwendet (INV-16, INV-21). | allowlist | 03 |
| **Postfach** | Postfach unabhängig vom Ablageort, persönlich oder geteilt, mit Eigentümer, Berechtigten, Sendeberechtigung und Kontingent. Die Löschung ist ausdrücklich als nicht rücknehmbar gekennzeichnet. | mailbox | 02 |
| **Produktgrenze** | Die je Katalogeintrag deklarierte und in der Konsole sichtbare Festlegung, welche Objekte Atrium besitzt und welche Einrichtung in der Fremdoberfläche verbleibt. Ein Katalogeintrag ohne Grenzdeklaration wird bei der Freigabe abgelehnt (INV-30). | product boundary | 02 |
| **Protokollkopf** | Die Komponente, die Identitäten des Objektgraphen nach außen über OIDC, OAuth 2.0, LDAPS und Passkey/MFA anbietet. Sie ist nie Wahrheitsquelle; der Kern gewinnt jede Abweichung. | protocol head | 03 |
| **Quorum** | Die Mehrheit der Stimmknoten, die für jede schreibende Änderung am Sollzustand erforderlich ist (INV-04). Der Begriff erscheint nie in der Konsole, nur in Support- und Fachtexten. | quorum | 03 |
| **Räumen** | Das geordnete Verlagern aller Lasten von einem Knoten als sichtbare eigene Handlung vor Wartung oder Entkopplung. Nie "Drain". | drain | 15 |
| **Reconciler** | Die Abgleichschleife, die Sollzustand und Istzustand zur Deckung bringt. Sie ist der einzige Schreibweg in Zielsysteme; auch jede Notfallbehebung geht über sie (INV-03). | reconciler | 03 |
| **Replikationsstand** | Der je Speicherbereich sichtbare Fortschritt der Replikation, aus dem das tatsächliche RPO folgt. Er ist Istzustand und trägt einen Beobachtungszeitpunkt. | replication lag | 07 |
| **Ressourcenbudget** | Die je Dienst festgelegte Obergrenze für Rechenzeit, Arbeitsspeicher und Speicherplatz. Sie ist Eingang der Platzierung und Grund einer Ablehnung, wenn kein Knoten sie erfüllt. | resource budget | 03 |
| **Richtlinie** | Mandanten- oder plattformweite Vorgabe, die Vorbelegungen erzeugt und damit Entscheidungen einspart, mit harter oder weicher Erzwingung. Jede Vorbelegung nennt ihre Richtlinie als Quelle (INV-15). | policy | 02 |
| **Rolle** | Rechtebündel mit Geltung auf Plattform, Mandant oder Dienst, versioniert und mit Freigabepflicht. Eine Rollenänderung ist ein Vorgang mit Wirkungsvorschau. | role | 02 |
| **RPO** | Zulässiger Datenverlust in Zeit, gemessen vom letzten wiederherstellbaren Stand bis zum Ausfallzeitpunkt. Es folgt aus der Datensicherheitsstufe und wird am Dienst angezeigt. | recovery point objective | 03 |
| **RTO** | Zulässige Zeit bis zur Wiederherstellung des Betriebs nach einem Ausfall. Es ist im Dokument durchgängig als Zielwert gekennzeichnet, nicht als Messwert. | recovery time objective | 08 |
| **Rücksprungpunkt** | Der vor einer Aktualisierung festgehaltene Zustand, auf den ein Vorgang zurückgenommen werden kann. Ohne Rücksprungpunkt ist eine Aktualisierung als nicht rücknehmbar gekennzeichnet. | rollback point | 03 |
| **Sandkasten** | Die Einschränkung eines Prozesses auf das für seine Aufgabe Nötige: eigener Systembenutzer, systemd-Härtung, seccomp, eigener Netznamensraum. Jeder Konnektorprozess läuft in einem eigenen Sandkasten. | sandbox | 07 |
| **Schemaversion** | Die als `MAJOR.MINOR` geführte Version des Sollzustandsschemas, genannt in jedem Export, jedem Wiederherstellungspunkt und jeder API-Antwort. Eine MAJOR-Änderung ist eine eigene, separat rückrollbare Migration (INV-24). | schema version | 07 |
| **Selbstabschottung** | Das selbsttätige Beenden der eigenen Arbeitslasten durch einen Knoten, der seine Lease oder seine Zeitgewissheit verloren hat. Sie ersetzt externes Fencing durch eine lokale, prüfbare Entscheidung (INV-06, INV-32). | self-fencing | 03 |
| **Sichtattribut** | Das Attribut je DNS-Eintrag, das seine Zugehörigkeit zur internen, zur externen oder zu beiden Sichten ausdrücklich festlegt. Es ersetzt die getrennte Pflege zweier Zonen. | view attribute | 04 |
| **Sollzustand** | Der deklarierte, quorumpflichtige, versionierte Zielzustand und die einzige Wahrheitsquelle (INV-02). Nie "Desired State". | desired state | 02 |
| **Sollzustandsexport** | Der signierte, versionierte, maschinen- und menschenlesbare vollständige Auszug des Sollzustands, abgelegt vor jeder Änderungstransaktion und periodisch. Er enthält keine Geheimnisse im Klartext (INV-20). | desired-state export | 03 |
| **Sollzustandsversion** | Die fortlaufende Version des Sollzustands, zugleich Seriennummer jeder erzeugten DNS-Zoneninstanz. Sie macht Zonenstände und Konfigurationsschnappschüsse vergleichbar. | — | 05 |
| **Speicherbereich** | Persistenter Datenbereich genau eines Dienstes mit Datensicherheitsstufe, Replikatstandorten, Verschlüsselungsschlüsselreferenz und Sicherungsplan. Nie "Volume". | storage area | 03 |
| **Speicherträger** | Knotenrolle: der Knoten hält Replikate von Speicherbereichen. Ab acht Knoten können dedizierte Speicherknoten diese Rolle ausschließlich tragen. | — | 06 |
| **Sperrliste** | Die von der Ausgabe-CA geführte Liste gesperrter Zertifikate, sofort verteilt bei Sperrung eines Geräts oder Knotens. Sie ist neben OCSP der zweite Prüfweg. | certificate revocation list | 05 |
| **Split-Horizon** | Die Auslieferung unterschiedlicher Antworten für denselben Namen je nach Sicht des Fragenden. Atrium bildet sie über das Sichtattribut je Eintrag und bis zu zwei Zoneninstanzen je Domäne ab, nicht über Views im autoritativen Dienst. | split-horizon DNS | 12 |
| **Stimmknoten** | Raft-Mitglied mit Stimmrecht. Ihre Zahl ist genau 1, 3 oder 5, niemals 2 oder 4 (INV-05). Konsolentext: "Verwaltungsknoten". | voting member | 05 |
| **Stückliste** | Das je Systemabbild und Katalogeintrag mitgelieferte Inhaltsverzeichnis aller enthaltenen Komponenten, sowohl im SPDX- als auch im CycloneDX-Format. Ein Eintrag ohne Stückliste ist nicht ladbar. | software bill of materials | 02 |
| **Teilerfolg** | Ergebnis eines Vorgangs, bei dem ein Zielsystem wirkt und ein anderes nicht. Er wird je Zielsystem mit Grund, Wiederholungsmöglichkeit und benannten Resten ausgewiesen; der Vorgang gilt nicht als fertig (INV-12). | partial failure | 03 |
| **Transparenzprotokoll** | Der anhängbare, hashverkettete Nachweis jeder Freigabe von Systemabbildern, Katalogeinträgen und Konnektormanifesten, mit Zeitstempel nach RFC 3161. | transparency log | 06 |
| **Übernahmefrist** | Die Zeit, die ein anderer Knoten wartet, bevor er eine Last übernimmt. Sie ist stets größer als Leasefrist plus zugelassene Uhrenabweichung, damit zwei Knoten dieselbe Last nie gleichzeitig führen. | takeover timeout | 03 |
| **ULID** | Das Kennungsformat aller Objekte: 26 Zeichen Crockford-Base32, 48 bit Zeitanteil, 80 bit Zufall, lexikographisch nach Erzeugungszeit sortierbar. Externe Form ist `urn:atrium:<entitätstyp>:<ulid>`. | ULID | 05 |
| **Veröffentlichung** | Verbindet Dienst, Hostname, Sichtbarkeit und Zugriffskreis und ist die einzige Quelle für Proxyroute, DNS-Eintrag, Zertifikat und Netzfreigabe. Nie "Ingress", nie "Route". | publication | 02 |
| **Vertragsversion** | Die semantisch versionierte Version des Konnektorvertrags, unabhängig von der Schemaversion. Der Kern unterstützt die laufende und die vorhergehende Hauptversion. | contract version | 08 |
| **Vertrauensanker** | Das Zertifikat, dessen Gültigkeit ein Prüfer ohne weiteren Nachweis voraussetzt. In Atrium ist das die Wurzel-CA; sie wird an Knoten und Geräte verteilt, ihr Schlüssel bleibt offline. | trust anchor | 03 |
| **Vertrauensgrenze** | Die Linie, an der Daten oder Aufrufe zwischen Bereichen unterschiedlicher Rechte wechseln und deshalb geprüft werden müssen. Die Vertrauensgrenzen sind in Kapitel 05 aufgezählt und in Anhang D je Grenze nach STRIDE analysiert. | trust boundary | 05 |
| **Verwaltungsknoten** | Konsolentext für einen Knoten, der die Kontrollebene trägt, unabhängig davon, ob er Stimmknoten, Mitleser oder Zeuge ist. | — | 05 |
| **Vier-Augen-Prinzip** | Freigaberegel, nach der Urheber und Freigebender eines Vorgangs verschiedene Personen sein müssen. Es wird je Freigabeweg gesetzt, nicht global. | four-eyes principle | 14 |
| **Volllauf** | Der periodische Abgleich über alle Objekte unabhängig von Ereignissen. Eine unveränderte Beobachtung im Volllauf erzeugt keinen Protokolleintrag, damit der Driftpfad den Konsens nicht flutet. | full sweep | 03 |
| **Vorgang** | Jede beabsichtigte Änderung als ein Objekt mit Plan, Freigabe, Fortschritt, Ergebnis und Rücknahme, verbunden über eine Korrelationskennung. Nie "Job", nie "Task". | operation | 02 |
| **Wartemodus** | Zustand eines installierten, aber nicht zugeordneten Knotens: er zeigt einen Kopplungscode und hat ausschließlich den Kopplungsendpunkt offen. | — | 04 |
| **Wiederherstellungscode** | Bei der Erstinstallation erzeugter Code mit 256 bit Entropie, zum Ausdrucken bestimmt und im System nur als Verifikationswert vorhanden. Er schützt Notbetrieb und Notzugang. | recovery code | 05 |
| **Wiederherstellungspunkt** | Prüfbarer Zustand zu einem Zeitpunkt, entweder Sollzustandsexport oder Speicherbereichsmomentaufnahme, mit Prüfsumme, Signatur und Prüfstatus. "Ungeprüft" ist ein sichtbarer Zustand, kein Standard. | restore point | 05 |
| **Wirkungsvorschau** | Die nebenwirkungsfreie Berechnung aller Wirkungen eines Vorgangs vor seiner Ausführung, einschließlich kaufmännischer Folgen (INV-08, INV-29). Nie "Dry Run". | plan, dry run | 02 |
| **Wurzel-CA** | Die oberste Zertifizierungsstelle der internen PKI. Ihr Schlüsselmaterial liegt außerhalb des laufenden Systems und ist nie Teil einer Sicherung des laufenden Systems (INV-22). | root CA | 03 |
| **Zertifikat** | Ausgestelltes Endzertifikat für Dienst, Knoten, Gerät oder Person, gebunden an genau ein Inhaberobjekt, mit Erneuerungsschwelle und Sperrstatus. Es wird automatisch aus dem Sollzustand beantragt. | certificate | 02 |
| **Zeuge** | Stimmberechtigtes Raft-Mitglied ohne Dienst- und Speicherlast, lauffähig auf kleinster Hardware. Er ist der Weg, im Zwei-Standort-Fall eine ungerade Stimmzahl herzustellen, und zählt zugleich als DRBD-Quorumszeuge. | witness | 05 |
| **Zoneninstanz** | Die aus einer Domäne für eine Sicht erzeugte, vollständige DNS-Zone. Je Domäne entstehen höchstens zwei; die Seriennummer ist die Sollzustandsversion. | zone instance | 04 |
| **Zugriffskreis** | Das Attribut einer Veröffentlichung, das festlegt, wer sie erreichen darf: eine Gruppe, eine Netzzone oder öffentlich. Aus ihm folgen die Firewall- und Proxyableitungen. | access scope | 03 |
| **Zuweisung** | Das Absichtsobjekt "dieses Subjekt soll diesen Dienst in dieser Rolle nutzen" und der einzige Auslöser aller Versorgungswirkungen. Es ist das Objekt hinter dem Schalter in der Konsole und trägt einen Zustand je Zielsystem. | assignment | 02 |
| **Zwischen-CA** | Die je Mandant ausgestellte Zertifizierungsstelle unterhalb der Ausgabe-CA, vorhanden bereits ab Isolationsstufe M0. Ein kompromittierter Mandant kann damit unter keiner Stufe für fremde Namen ausstellen. | intermediate CA | 04 |

Die Tabelle enthält 150 Einträge. Sie deckt alle 28 Entitäten aus Abschnitt 3 von `KANON.md` sowie alle Komponentennamen aus Abschnitt 1 und aus der Komponententabelle in Kapitel 05 ab.

## F.2 Verwendete Standards

Die Fundstellen sind unverändert aus Abschnitt 8 von `KANON.md` übernommen. Die Spalte "Kapitel" nennt die Kapitel 02 bis 23 und die Anhänge A bis D, in denen die Nummer oder der Name zitiert wird. Kapitel 01 bleibt aus demselben Grund ausgenommen wie bei der Erstverwendung in F.1; die Anhänge E (Anforderungsmatrix) und F (dieser Anhang) zitieren Nummern nur wiederholend und werden deshalb nicht geführt. Eine nichtleere Zelle ist damit der Nachweis, dass der Standard in mindestens einem Kapitel verwendet wird.

| Standard | Fundstelle | Verwendung im Dokument | Kapitel |
|---|---|---|---|
| TLS 1.3 | RFC 8446 | Einzige zugelassene Transportsicherung nach außen und innen, einschließlich aller knoteninternen gRPC-Verbindungen. | 05, 09, 10, 12, 20, B, D |
| X.509/PKIX | RFC 5280 | Format und Prüfpfad aller internen Zertifikate; Grundlage der Betreff-, Erweiterungs- und Sperrprofile in Kapitel 11. | 04, 07, 11, 16, 20, 22, A, B |
| OCSP | RFC 6960 | Zweiter Sperrprüfweg neben der Sperrliste, vom Kern erbracht. | 11, 22, A, C, D |
| ACME | RFC 8555 | Ausstellungsprotokoll für alle Zertifikate, die ACME abdecken kann, intern wie für öffentliche Namen. | 03, 04, 05, 09, 11, 12, 21, 22, C, D |
| EST | RFC 7030 | Ausstellungsweg dort, wo ein Gerät oder eine Plattform kein ACME beherrscht. | 11, 13 |
| CAA | RFC 8659 | Begrenzung, welche Zertifizierungsstelle für eine Domäne ausstellen darf; abgeleiteter Eintrag je Domäne. | 11, 12, 18, 20, 22, D |
| DNSSEC Kern | RFC 4033, RFC 4034, RFC 4035 | Signierung der Zoneninstanzen und Validierung im rekursiven Resolver. | 12, 20, C, D |
| DNSSEC Betriebspraxis | RFC 6781 | Vorgaben für den automatischen Schlüsselwechsel der Zonen. | 12 |
| DNS UPDATE | RFC 2136 | Verworfener Weg zur Zonenpflege; in Kapitel 12 als Alternative benannt und begründet abgelehnt. | 12 |
| TSIG | RFC 8945 | Absicherung der Steuerschnittstelle des autoritativen Dienstes, soweit sie benutzt wird. | 12 |
| DNS over TLS | RFC 7858 | Verschlüsselte Namensauflösung zwischen verwalteten Geräten und dem internen Resolver. | 12, 13, 20, C, D |
| DNS over HTTPS | RFC 8484 | Zweiter verschlüsselter Auflösungsweg; auf verwalteten Geräten per Richtlinie auf den internen Resolver gezwungen. | 12, 13, 20, C, D |
| SPF | RFC 7208 | Abgeleiteter Namenseintrag je Maildomäne. | 03, 12, 14, 21 |
| DKIM | RFC 6376 | Abgeleiteter Namenseintrag je Maildomäne; Schlüsselverweis ist Attribut der Maildomäne. | 03, 12, 14, 20, 21 |
| DMARC | RFC 7489 | Abgeleiteter Namenseintrag je Maildomäne, Stufe aus der Richtlinienstufe. | 03, 12, 14, 21 |
| MTA-STS | RFC 8461 | Abgeleiteter Namenseintrag und Richtliniendokument je Maildomäne. | 12, 14, D |
| TLS-RPT | RFC 8460 | Abgeleiteter Namenseintrag je Maildomäne für Transportfehlerberichte. | 12, 14 |
| DANE für SMTP | RFC 7672 | Bindung der Mailtransportsicherung an DNSSEC, alternativ zu MTA-STS. | 14, D |
| SMTP | RFC 5321 | Transportprotokoll der Mailzustellung, Bezugspunkt der Konnektorabgrenzung. | 14, D |
| Nachrichtenformat | RFC 5322 | Nachrichtenaufbau, Bezugspunkt für Adress- und Kopfzeilenfelder. | 14 |
| IMAP4rev1 | RFC 3501 | Zugriffsprotokoll auf Postfächer, soweit der Ablageort es anbietet. | 14, D |
| JMAP Kern | RFC 8620 | Alternatives Zugriffsprotokoll auf Postfächer. | 14 |
| JMAP Mail | RFC 8621 | Mailspezifische Erweiterung des vorgenannten Protokolls. | 14 |
| OAuth 2.0 | RFC 6749 | Autorisierungsrahmen des Protokollkopfs gegenüber Fremdprodukten. | 03, 04, 10, 14, C, D |
| Bearer Token | RFC 6750 | Übergabeform des Zugriffstokens an der API. | 05, 10, B, D |
| PKCE | RFC 7636 | Pflichterweiterung für alle öffentlichen Clients des Autorisierungscodeflusses. | 03, 10, 15, B |
| Device Authorization Grant | RFC 8628 | Anmeldeweg für Geräte ohne Browser. | 10, 14, B |
| Token Exchange | RFC 8693 | Umtausch von Token an Dienstgrenzen, statt Weiterreichung des Ursprungstokens. | 10, B |
| Dynamic Client Registration | RFC 7591 | Registrierung von Fremdprodukten am Protokollkopf ohne Handeintrag. | 10, 14, B |
| JWT | RFC 7519 | Tokenformat des Protokollkopfs. | 10, 14, B |
| JWT Profile für Access Tokens | RFC 9068 | Verbindliches Profil der ausgestellten Zugriffstoken, damit Prüfung und Gültigkeitsdauer eindeutig sind. | 05, 10, 14, 15, 20, B, D |
| OpenID Connect Core 1.0 | OpenID Foundation, keine RFC | Anmeldeprotokoll des Protokollkopfs gegenüber Fremdprodukten. | 03, 04, 09, 10, 15, B |
| SAML 2.0 | OASIS, keine RFC | Nur über einen optionalen Protokollvermittler OIDC nach SAML, mit benannter Kompatibilitätsgrenze. | 04, 10 |
| SCIM Schema | RFC 7643 | Objektschema der Identitätsversorgung in und aus Fremdsystemen. | 03, 04, 09, 10, 21, B, C, D |
| SCIM Protokoll | RFC 7644 | Übertragungsprotokoll der Identitätsversorgung; Server und Client werden von atrium-core erbracht. | 03, 04, 09, 10, 21, B, C, D |
| LDAPv3 | RFC 4511 | Lesezugriff auf Identitäten für Fremdprodukte ohne OIDC, über den Protokollkopf. | 03, 04, 09, 10, 14, 21, C, D |
| Kerberos v5 | RFC 4120 | Ausdrücklich nicht erbracht; in der Marktabgrenzung und in Kapitel 10 als entfallende Zusicherung benannt. | 04, 10, 14 |
| RADIUS | RFC 2865 | Netzzugangsentscheidung über einen erzeugt konfigurierten eigenen Dienst. | 10, 11, 13, C |
| EAP | RFC 3748 | Rahmen der Netzzugangsauthentisierung. | 10, 13 |
| EAP-TLS | RFC 5216 | Einziges zugelassenes EAP-Verfahren; prüft ausschließlich gegen die interne Ausgabe-CA und deren Sperrliste. | 03, 10, 11, 13, C, D |
| RadSec | RFC 6614 | Absicherung der RADIUS-Strecke über Standorte hinweg. | 10, 13, C, D |
| IEEE 802.1X | IEEE, keine RFC | Portbasierte Netzzugangskontrolle, die Gerät auf Netzzone abbildet. | 10, 12, 13, 20, C |
| HTTP Semantik | RFC 9110 | Verbindliche Semantik der öffentlichen API, einschließlich Bedingungsanfragen und Statuscodes. | 08, B |
| HTTP/2 | RFC 9113 | Transport der internen gRPC-Verbindungen und des Eingangs. | 05, 12, B, D |
| HTTP/3 | RFC 9114 | Zusätzlicher Transport am Eingang. | 05, 12, B, D |
| QUIC | RFC 9000 | Unterlage von HTTP/3, mit eigener Port- und Filterfolge. | 12, B, D |
| Argon2 | RFC 9106 | Kennwortableitung dort, wo Kennwörter ausnahmsweise zugelassen sind, in der Variante Argon2id. | 10, 16, 20 |
| JSON Canonicalization Scheme | RFC 8785 | Kanonische Serialisierung vor jeder Signatur und vor jeder Hashbildung im Änderungsprotokoll. | 06, 07, 08, 09, 15, 16, 17, 18, 19, 20, 21, A, B, C, D |
| Time-Stamp Protocol | RFC 3161 | Zeitstempel der periodischen Auditsignatur und der Einträge im Transparenzprotokoll. | 03, 06, 07, 08, 09, 11, 15, 17, 19, 20, 21, 22, C, D |
| Syslog | RFC 5424 | Format der Protokollausleitung an eine externe Senke über TLS. | 03, 05, 08, 19, 21, C, D |
| SPAKE2 | RFC 9382 | Kopplungsverfahren aus dem Kopplungscode, gebunden an den TLS-Exporter der Verbindung. | 04, 05, 16, 20, B, C, D |
| HKDF | RFC 5869 | Ableitung von Teilschlüsseln aus Haupt- und Sitzungsschlüsseln. | 16, 17, 20 |
| EdDSA/Ed25519 | RFC 8032 | Signaturverfahren für Systemabbilder, Katalogeinträge, Konnektormanifeste, Sollzustandsexporte und Wiederherstellungspunkte. | 06, 07, 09, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, A, B, C, D |
| X25519 | RFC 7748 | Schlüsselaustausch, auch als klassischer Anteil des angestrebten hybriden Verfahrens. | 11, 20 |
| ChaCha20-Poly1305 | RFC 8439 | Verkehrsverfahren neben AES-GCM, insbesondere ohne Hardwarebeschleunigung. | 16, 17, 20 |
| WebAuthn Level 3 | W3C, keine RFC | Vorgegebenes Anmeldemittel (Passkey) für Personen. | 04, 10, 11, D |
| CTAP2 | FIDO Alliance, keine RFC | Geräteschnittstelle des vorgenannten Anmeldemittels. | 10 |
| SPIFFE/SPIRE | CNCF, keine RFC | Formvorbild der Dienstidentitätskennungen; die Kennungen selbst folgen dem Identifikatorformat des Kanons. | 10 |
| OpenTelemetry | CNCF, keine RFC | Internes Format für Metriken, Spuren und strukturierte Protokolle, empfangen über OTLP. | 08, C |
| OCI Image Spec / Distribution Spec | Open Container Initiative, keine RFC | Verteilformat der Dienstabbilder und Bezugsform der Katalogeinträge. | 15, 21, C |
| SPDX | ISO/IEC 5962 | Eines der beiden Pflichtformate der Stückliste je Abbild und Katalogeintrag. | 04, 06, 15, 20, 21, 23, A, C, D |
| CycloneDX | OWASP/Ecma, Nummer nicht zitieren | Zweites Pflichtformat der Stückliste. | 04, 06, 15, 20, 21, C, D |
| WireGuard | Protokollspezifikation Donenfeld, keine RFC | Overlay-Netz als Vollvermaschung zwischen allen Knoten, Schlüssel an das Knotenzertifikat gebunden. | 04, 05, 12, 20, C, D |
| Raft | Ongaro/Ousterhout, keine RFC | Konsensverfahren des Sollzustands; bestimmt Stimmzahl, Quorum und Leaderwechsel. | 04, 05, 08, 10, 12, 16, 17, 21, C |
| AES | FIPS 197 | Verkehrs- und Ruheverschlüsselung in der Betriebsart GCM sowie in der Speicherverschlüsselung. | 17, 20 |
| SHA-2 | FIPS 180-4 | Hashverfahren der Auditkette, des Änderungsprotokolls und der Zertifikatssignaturen. | 08, 11, 15, 17, 19, 20, A |
| ML-KEM | FIPS 203 | Post-Quanten-Anteil des angestrebten hybriden Schlüsselaustauschs an Außenkanten. | 11, 20 |
| ML-DSA | FIPS 204 | Mögliches zusätzliches Signaturverfahren an Lieferkette und Wurzel-CA. | 11, 20 |
| SLH-DSA | FIPS 205 | Zustandsloses Alternativverfahren zum vorgenannten, mit anderem Vertrauensprofil. | 11, 20 |
| TPM 2.0 | Trusted Computing Group | Schlüsselablage für Ausgabe-CA, Knoten- und Gerätezertifikate; Entsiegelung an den Zustand des Systemabbilds gebunden. | 05, 06, 10, 11, 13, 18, 20, C, D |
| UEFI Secure Boot | UEFI Forum | Erste Stufe der Startkette vor dm-verity und Entsiegelung. | 05, 06, 11, 20, 23, C |
| WCAG 2.2 | W3C | Stufe AA ist Bedingung für die Atrium Console, nicht Merkmal (INV-31). | 03, 18, 22, C, D |
| ISO/IEC 27001:2022 | ISO/IEC 27001:2022 | Bezugsrahmen der Nachweisführung in Kapitel 22; die Trennung in automatisierbare und nicht automatisierbare Anforderungen wird dort ausgewiesen. | 11, 13, 22 |
| BSI IT-Grundschutz-Kompendium | BSI IT-Grundschutz-Kompendium | Nationales Grundschutzwerk; Atriums Beitrag ist je Baustein-Familie unterschiedlich und in Kapitel 22 aufgeschlüsselt. | 22 |

## F.3 Rechtsakte

| Rechtsakt | Fundstelle | Relevanz für das Produkt | Kapitel |
|---|---|---|---|
| DSGVO | Verordnung (EU) 2016/679 | Begründet Mandantenpflicht, Löschung mit Aufbewahrungsfrist, Auskunft aus dem Objektgraphen, Auftragsverarbeitung je Konnektorbindung und die Datenklasse je Katalogeintrag. | 02, 03, 07, 13, 14, 17, 20, 22, C |
| NIS2 | Richtlinie (EU) 2022/2555 | Begründet die Nachweispflichten zu Vorfallserkennung, Protokollierung und Lieferkette; der unveränderliche Auditstrom und das Transparenzprotokoll sind die tragenden Belege. | 02, 11, 20, 22, C |
| Cyber Resilience Act | Verordnung (EU) 2024/2847 | Begründet Stückliste, Schwachstellenbehandlung, Aktualisierungszusage über den Produktlebenszyklus und die Freigabeprüfliste je Version. | 02, 20, 22, C |
| European Accessibility Act | Richtlinie (EU) 2019/882 | Begründet zusammen mit INV-31 die Barrierefreiheit der Atrium Console als Bedingung. | 02, 03, 18, 22, C |
| Barrierefreiheitsstärkungsgesetz (BFSG) | Deutschland | Nationale Umsetzung des vorgenannten Rechtsakts; maßgeblich für den deutschen Markt. | 02, 03, 18, 22, C |
| eIDAS | Verordnung (EU) 910/2014, novelliert 2024 | Maßgeblich für die Frage, wann ein Zeitstempel qualifiziert ist; die Quelle ist eine Konnektorbindung, nicht der Kern. | 11, 22 |

## F.4 Abkürzungen

| Abkürzung | Bedeutung ausgeschrieben | Deutsche Entsprechung |
|---|---|---|
| ACME | Automatic Certificate Management Environment | Automatisches Zertifikatsverwaltungsprotokoll |
| ADR | Architecture Decision Record | Architekturentscheidung |
| API | Application Programming Interface | Programmierschnittstelle |
| BFSG | Barrierefreiheitsstärkungsgesetz | Eigenname, deutsch |
| BSI | Bundesamt für Sicherheit in der Informationstechnik | Eigenname, deutsch |
| CA | Certificate Authority | Zertifizierungsstelle |
| CAA | Certification Authority Authorization | Ausstellungsberechtigung je Domäne |
| CN | Common Name | Allgemeiner Name im Zertifikatsbetreff |
| CNCF | Cloud Native Computing Foundation | Eigenname |
| CRA | Cyber Resilience Act | Verordnung über Cyberresilienz |
| CRL | Certificate Revocation List | Sperrliste |
| CTAP2 | Client to Authenticator Protocol 2 | Geräteschnittstelle für Passkeys |
| DANE | DNS-based Authentication of Named Entities | Vertrauensbindung über DNSSEC |
| DKIM | DomainKeys Identified Mail | Signatur ausgehender Nachrichten |
| DMARC | Domain-based Message Authentication, Reporting and Conformance | Richtlinie und Berichtswesen für Absenderprüfung |
| DNS | Domain Name System | Namenssystem |
| DNSSEC | Domain Name System Security Extensions | Signierung von Namensdaten |
| DoH | DNS over HTTPS | Namensauflösung über HTTPS |
| DoT | DNS over TLS | Namensauflösung über TLS |
| DRBD | Distributed Replicated Block Device | Synchron repliziertes Blockgerät |
| DS | Delegation Signer | Delegierungseintrag der Elternzone |
| DSGVO | Datenschutz-Grundverordnung | Eigenname, deutsch |
| EAP | Extensible Authentication Protocol | Rahmen der Netzzugangsauthentisierung |
| EAP-TLS | EAP-Transport Layer Security | Zertifikatsbasierte Netzzugangsauthentisierung |
| ECDSA | Elliptic Curve Digital Signature Algorithm | Signaturverfahren über elliptischen Kurven |
| EST | Enrollment over Secure Transport | Zertifikatsanforderung über gesicherten Transport |
| FIPS | Federal Information Processing Standards | Normenreihe der US-Bundesverwaltung |
| gRPC | gRPC Remote Procedure Calls | Entfernter Prozeduraufruf, hier über HTTP/2 |
| HKDF | HMAC-based Key Derivation Function | Schlüsselableitungsfunktion |
| HTTP | Hypertext Transfer Protocol | Übertragungsprotokoll des Webs |
| IEEE | Institute of Electrical and Electronics Engineers | Eigenname |
| IMAP | Internet Message Access Protocol | Postfachzugriffsprotokoll |
| INV | Invariante | Invariante, zitiert als `INV-nn` |
| ISO/IEC | International Organization for Standardization / International Electrotechnical Commission | Eigenname |
| JMAP | JSON Meta Application Protocol | Postfachzugriffsprotokoll auf JSON-Basis |
| JSON | JavaScript Object Notation | Datenformat der API und der Protokolle |
| JWT | JSON Web Token | Tokenformat |
| LDAP | Lightweight Directory Access Protocol | Verzeichniszugriffsprotokoll |
| LDAPS | LDAP over TLS | Verzeichniszugriff über TLS |
| LTS | Long Term Support | Langzeitunterstützte Ausgabe |
| M0 bis M3 | — | Die vier Isolationsstufen eines Mandanten |
| MFA | Multi-Factor Authentication | Mehrfaktoranmeldung |
| ML-DSA | Module-Lattice-Based Digital Signature Algorithm | Gitterbasiertes Signaturverfahren |
| ML-KEM | Module-Lattice-Based Key-Encapsulation Mechanism | Gitterbasiertes Schlüsselkapselungsverfahren |
| MTA-STS | Mail Transfer Agent Strict Transport Security | Erzwungene Transportsicherung für Mail |
| mTLS | Mutual TLS | Gegenseitig authentisiertes TLS |
| MX | Mail Exchanger | Namenseintrag des Mailziels |
| NAT | Network Address Translation | Adressumsetzung |
| NFC | Normalization Form C | Unicode-Normalform der Anzeigenamen |
| NIS2 | Network and Information Security Directive 2 | Richtlinie über Netz- und Informationssicherheit |
| NTS | Network Time Security | Netzwerkgesicherte Zeitsynchronisation |
| OASIS | Organization for the Advancement of Structured Information Standards | Eigenname |
| OAuth | Open Authorization | Autorisierungsrahmen |
| OCI | Open Container Initiative | Eigenname; hier das Abbild- und Verteilformat |
| OCSP | Online Certificate Status Protocol | Sperrstatusabfrage |
| OIDC | OpenID Connect | Anmeldeprotokoll auf OAuth-Basis |
| OTLP | OpenTelemetry Protocol | Übertragungsprotokoll der Telemetrie |
| OWASP | Open Worldwide Application Security Project | Eigenname |
| PKCE | Proof Key for Code Exchange | Absicherung des Autorisierungscodeflusses |
| PKI | Public Key Infrastructure | Schlüsselinfrastruktur |
| QUIC | — | Transportprotokoll unter HTTP/3; kein Akronym |
| RADIUS | Remote Authentication Dial-In User Service | Netzzugangsdienst |
| RadSec | RADIUS over TLS | RADIUS über TLS |
| RFC | Request for Comments | Dokumentenreihe der IETF |
| RPO | Recovery Point Objective | Zulässiger Datenverlust in Zeit |
| RTO | Recovery Time Objective | Zulässige Wiederherstellungszeit |
| SAML | Security Assertion Markup Language | Anmeldeprotokoll auf XML-Basis |
| SAN | Subject Alternative Name | Alternativer Name im Zertifikat |
| SBOM | Software Bill of Materials | Stückliste |
| SCIM | System for Cross-domain Identity Management | Identitätsversorgungsprotokoll |
| SDS | Secret Discovery Service | Zertifikatszustellung an den Eingang |
| SLH-DSA | Stateless Hash-Based Digital Signature Algorithm | Zustandsloses hashbasiertes Signaturverfahren |
| SMTP | Simple Mail Transfer Protocol | Mailtransportprotokoll |
| SNI | Server Name Indication | Namensangabe im TLS-Aufbau |
| SPAKE2 | Simple Password-Authenticated Key Exchange 2 | Kennwortauthentisierter Schlüsselaustausch |
| SPDX | Software Package Data Exchange | Stücklistenformat |
| SPF | Sender Policy Framework | Absenderrichtlinie je Maildomäne |
| SPIFFE | Secure Production Identity Framework for Everyone | Dienstidentitätsrahmen |
| TLS | Transport Layer Security | Transportsicherung |
| TLS-RPT | TLS Reporting | Berichtswesen über Transportfehler bei Mail |
| TPM | Trusted Platform Module | Sicherheitsbaustein mit Schlüsselablage |
| TTL | Time to Live | Gültigkeitsdauer eines Namenseintrags |
| UEFI | Unified Extensible Firmware Interface | Firmwareschnittstelle |
| ULID | Universally Unique Lexicographically Sortable Identifier | Sortierbare eindeutige Kennung |
| URN | Uniform Resource Name | Namensform der externen Objektkennung |
| VLAN | Virtual Local Area Network | Virtuelles Netzsegment |
| VRF | Virtual Routing and Forwarding | Getrennte Weiterleitungstabelle |
| W3C | World Wide Web Consortium | Eigenname |
| WAL | Write-Ahead Logging | Schreibvorprotokollierung der eingebetteten SQL-Engine |
| WCAG | Web Content Accessibility Guidelines | Richtlinien für barrierefreie Webinhalte |
| xDS | — | Sammelname der Envoy-Konfigurationsdienste; in Konsolentexten verboten |
| ZFS | — | Dateisystem und Datenträgerverwaltung; kein Akronym mit gültiger Auflösung |

## F.5 Schreibweisen

Diese Liste ist die Kurzfassung von Abschnitt 1 aus `KANON.md` für Bearbeiter. Bei Abweichung gilt `KANON.md`.

1. **Produkt und Komponenten:** Atrium Server OS (kurz: Atrium), Atrium Console, atrium-core, atrium-node, `atrium-connector-<name>`, atriumctl. Die Prozessnamen stehen klein und mit Bindestrich, auch am Satzanfang; "AtriumOS", "Atrium OS", "Web-UI" und "Admin-Panel" sind falsch.
2. **Zustandspaar:** Sollzustand und Istzustand, nie "Desired State" oder "Actual State". Der Vorgang heißt Abgleich, das Ergebnis Konvergenz, die Schleife Reconciler.
3. **Mandantensprache:** Mandant, mandantenfähig, Mandantenbezug. "Tenant" und "Tenancy" sind in jedem Text verboten, auch in Fachabschnitten.
4. **Objektnamen im Singular und groß:** Zuweisung, Veröffentlichung, Vorgang, Katalogeintrag, Konnektorbindung, Speicherbereich, Wiederherstellungspunkt, Abgeleitetes Artefakt. "Job", "Task", "Ingress", "Route" und "Volume" sind falsch.
5. **Verbotene Oberflächenbegriffe:** maßgeblich und abschließend ist die Liste in Abschnitt 1 von `KANON.md`; sie wird dort gepflegt und hier nicht wiederholt, damit keine zweite Fassung entsteht. Die Begriffe erscheinen in keinem Konsolentext, keiner Meldung und keinem Bericht (INV-16); in Fach- und Supporttexten sind sie nach Abschnitt 10 von `KANON.md` zulässig. "Snapshot" wird auch dort als Momentaufnahme geschrieben, wenn ein Speicherstand gemeint ist.
6. **Knotenrollen:** Stimmknoten, Mitleser, Zeuge im Fachtext; "Verwaltungsknoten" und "Verwaltungsknoten (mitlesend)" im Konsolentext. Stimmzahl immer als 1, 3 oder 5 geschrieben.
7. **Stufen:** Isolationsstufen als M0, M1, M2, M3 ohne Leerzeichen. Datensicherheitsstufen als Lokal, Gespiegelt, Synchron gespiegelt, groß geschrieben, ohne Anführungszeichen.
8. **Vorgangssprache:** Wirkungsvorschau statt "Dry Run", Freigabe statt "Approval", Räumen statt "Drain", Kopplungsvorgang und Kopplungscode statt "Join" oder "Enrollment", Eingefroren für den Zustand ohne Quorum.
9. **Verweise:** ausschließlich `INV-nn`, `K-nn`, `R-<kk>-<nn>` und Kapitelnummern. Kein "siehe oben", keine Seitenzahlen, keine Überschriftenzitate.
10. **Standards:** nur mit den Fundstellen aus F.2 und F.3 zitieren, Schreibweise `RFC 8446` mit Leerzeichen und ohne Punkt. Alles andere wird ohne Nummer nur mit Namen genannt.
11. **Technische Namen:** Kleinbuchstaben, Ziffern, Bindestrich, erstes Zeichen ein Buchstabe, höchstens 63 Zeichen je Label. API-Felder `snake_case`, deutsch, ohne Umlaute; API-Pfade `/v1/<entitätstyp-plural>` mit deutschem Plural.
12. **Auditereignistypen:** `<objekt>.<verb>` im Perfekt, klein, mit Punkt getrennt, Beispiel `person.angelegt`.
13. **Kennungen:** ULID in Crockford-Base32, externe Form `urn:atrium:<entitätstyp>:<ulid>`. Kopplungscode immer als `XXXX-XXXX-XXXX` dargestellt.
14. **Zahlen:** jede Zahl als Zielwert, Annahme oder Rechenergebnis gekennzeichnet. Keine Versionsnummern von Fremdsoftware; statt Kernelversionen "GA-Kernel der LTS-Serie" oder "HWE-Kernel".

## Akzeptanzkriterien

1. Jeder Begriff in F.1 kommt in mindestens einer der Dateien 02 bis 23 oder A bis D im Volltext vor; die Spalte "Erstverwendung" nennt die niedrigste zutreffende Nummer. Prüfbar durch Volltextsuche je Zeile.
2. Alle 28 Entitäten aus Abschnitt 3 von `KANON.md` und alle Komponentennamen aus Abschnitt 1 und aus der Komponententabelle in Kapitel 05 haben einen Eintrag in F.1. Prüfbar durch Abgleich beider Listen.
3. Jede Fundstelle in F.2 und F.3 ist zeichengleich mit der Fundstelle in Abschnitt 8 von `KANON.md`. Prüfbar durch Zeichenkettenvergleich.
4. Kein Eintrag in F.2 oder F.3 nennt eine Nummer, die nicht in Abschnitt 8 von `KANON.md` steht. Prüfbar durch Mustersuche nach `RFC \d+`, `FIPS \d+` und `(EU) \d{4}/\d+` gegen die Kanonliste.
5. Die Schreibweisenliste F.5 enthält keine Regel, die Abschnitt 1 oder Abschnitt 10 von `KANON.md` widerspricht. Prüfbar durch Gegenüberstellung der beiden Listen.

## Offene Punkte

1. **Fünf Standards aus Abschnitt 8 sind nicht aufgenommen, weil kein Kapitel sie benutzt:** ARC (RFC 8617), TOTP (RFC 6238), HOTP (RFC 4226), SHA-3 (FIPS 202) und ISO 22301. Das ist kein Schreibfehler dieses Anhangs, sondern eine Lücke der Kapitel: Kapitel 14 behandelt Weiterleitungsketten ohne ARC, Kapitel 10 nennt Einmalkennwortverfahren als Rückfallweg ohne Verfahrensfestlegung, und Kapitel 22 stützt die Betriebskontinuität nicht auf ISO 22301. Entweder die Kapitel benutzen die Standards, oder Abschnitt 8 streicht sie.
2. **Zwei Attributnamen aus Abschnitt 3 fehlen im Sprachgebrauch der Kapitel:** "Gesundheitszustand" als Attribut des Dienstes und "Platzierungsbegründung" als Attribut der Platzierung kommen in keinem Kapitel vor. Die Kapitel umschreiben beides. Solange das so ist, sind es keine Fachbegriffe des Dokuments und stehen nicht in F.1.
3. **Die Spalte "Erstverwendung" ist eine Fundstelle, keine Definitionsstelle.** Sie nennt das erste Vorkommen, nicht das Kapitel, das den Begriff einführt. Für Begriffe, die in Kapitel 02 als Problembeschreibung auftauchen und erst später definiert werden, führt die Spalte deshalb in ein Kapitel ohne Definition.
4. **Englische Entsprechungen sind nicht normativ.** Sie dienen der Literaturrecherche. Wo kein etablierter Begriff existiert, steht ein Strich; eine Übersetzung wird nicht erfunden. Für eine englische Fassung des Dokuments wäre eine eigene, geprüfte Terminologieliste nötig, die es nicht gibt.
5. **F.4 enthält Abkürzungen, die im Dokument nur als Herausgeberangabe vorkommen** (CNCF, W3C, OWASP, OASIS). Sie stehen hier, weil sie in den Fundstellen in F.2 erscheinen, nicht weil ein Kapitel sie im Fließtext benutzt.
