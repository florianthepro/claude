# 20 Sicherheitsarchitektur und Bedrohungsmodell

## 20.1 Schadensklassen und Schutzgüter

Ein Schutzbedarf ist nur dann prüfbar, wenn die Stufen an einem beobachtbaren Ergebnis hängen. Atrium benutzt drei Klassen mit genau dieser Eigenschaft.

| Klasse | Beobachtbare Definition |
|---|---|
| normal | Der Schaden bleibt auf einen Mandanten und einen Dienst begrenzt und ist innerhalb der RTO der für diesen Dienst gewählten Datensicherheitsstufe (K-08 bis K-10) behoben. |
| hoch | Der Schaden betrifft einen vollständigen Mandanten oder erzwingt den Austausch von Schlüsselmaterial; die Behebung verlangt einen Wiederherstellungspunkt oder eine Neuausstellung. |
| sehr hoch | Der Schaden betrifft alle Mandanten gleichzeitig, ist durch Wiederherstellung nicht behebbar (jeder Vertraulichkeitsverlust ist irreversibel) oder entzieht dem Nachweis seine Grundlage. |

| Schutzgut | Ort | Vertraulichkeit | Integrität | Verfügbarkeit | Begründung des höchsten Wertes |
|---|---|---|---|---|---|
| Sollzustand | hashverkettetes Änderungsprotokoll in atrium-core, materialisiertes Lesemodell je Verwaltungsknoten | hoch | sehr hoch | hoch | Der Sollzustand erzeugt jedes abgeleitete Artefakt (INV-09). Eine gefälschte Zeile darin öffnet Firewall, Proxyroute, DNS-Eintrag und Fremdkonto gleichzeitig und mandantenübergreifend. Verfügbarkeit bleibt unter "sehr hoch", weil Dienste die Kontrollebene überleben (INV-25). |
| Schlüsselmaterial der Wurzel-CA | außerhalb des laufenden Systems, offline (INV-22) | sehr hoch | sehr hoch | normal | Der Wurzelschlüssel wird nur bei einem Nachfolgewechsel der Ausgabe-CA gebraucht; sein Verlust ist ein planbarer Wiederaufbau, seine Offenlegung ist die Kompromittierung jedes Vertrauensankers auf jedem verwalteten Gerät. |
| Schlüsselmaterial der Ausgabe-CA | TPM 2.0 des Verwaltungsknotens, nicht exportierbar | sehr hoch | sehr hoch | hoch | Verfügbarkeit ist "hoch" und nicht "normal", weil jede Erneuerung eines Dienstzertifikats daran hängt und das Erneuerungsfenster nach K-13 dreißig Tage beträgt. |
| Schlüsselmaterial der Mandanten-Zwischen-CA | TPM, je Mandant getrennt, mit Namensbeschränkungen | hoch | sehr hoch | hoch | Die Namensbeschränkung begrenzt den Schaden auf einen Mandanten; ohne sie wäre die Klasse "sehr hoch". |
| Konnektorgeheimnisse | Geheimnisspeicher, gegen die Plattformintegrität versiegelt, nur als kurzlebige Referenz herausgegeben (INV-20) | sehr hoch | hoch | hoch | Ein offengelegtes Konnektorgeheimnis verleiht Rechte in einem Fremdsystem, die Atrium nicht zurücknehmen kann: der Widerruf liegt beim Fremdsystem, nicht im Sollzustand. |
| Nutzerdaten in Speicherbereichen | ZFS-Datasets je Dienst, verschlüsselt mit mandantenbezogenem Schlüssel | sehr hoch | sehr hoch | hoch bis sehr hoch | Der Wert folgt der Datenklassenvorgabe des Katalogeintrags. Verfügbarkeit ist die einzige Eigenschaft, die der Bediener über die Datensicherheitsstufe selbst wählt. |
| Auditkette | anhängbarer, hashverketteter Strom außerhalb des replizierten Kernzustands, periodisch signiert (INV-23) | hoch | sehr hoch | hoch | Integrität ist der Zweck des Guts. Vertraulichkeit ist "hoch", weil jedes Ereignis Akteur, Mandant und Objektbezug trägt und damit personenbezogene Daten enthält. |
| Sicherungsdaten | Wiederherstellungspunkte lokal und ausgelagert, Sollzustandsexporte | sehr hoch | sehr hoch | hoch | Eine Sicherung ist eine vollständige Kopie ohne die Laufzeitschutzmaßnahmen des Systems. Sie ist der kürzeste Weg zu allen Nutzerdaten und wird deshalb wie das Original bewertet. |
| Aktualisierungskanal | Freigabeschlüssel, signierte Systemabbilder, Transparenzprotokoll, Stücklisten | hoch | sehr hoch | normal | Integritätsverlust bedeutet Codeausführung auf allen Knoten. Verfügbarkeit ist "normal", weil eine verzögerte Aktualisierung kein Sofortschaden ist, solange der Rückfallweg besteht. |
| Bedienersitzung | Browser des Bedieners, Sitzungsobjekt in atrium-core | hoch | hoch | normal | Eine übernommene Sitzung mit Plattformrolle wirkt wie ein Innentäter mit vollen Rechten; sie wirkt aber nicht rückwirkend, weil jede Handlung ein Vorgang mit Auditereignis ist. |

Sechs Schutzgüter tragen mindestens einmal die Klasse "sehr hoch". Genau für diese sechs gelten die drei Maßnahmen, die das System an keiner anderen Stelle einsetzt: Versiegelung gegen die Plattformintegrität (20.7), Nichtexportierbarkeit des privaten Schlüssels aus dem erzeugenden Knoten (INV-20) und Prüfung der Signatur vor jeder Verwendung ohne Übersteuerungsmöglichkeit (20.6).

## 20.2 Angreiferklassen

| Kennung | Ausgangsposition | Angenommene Fähigkeiten | Ziel | Wirksame Grenze | Ausdrücklich nicht abgedeckt |
|---|---|---|---|---|---|
| **A-1** Unbefugter im lokalen Netz | Anschluss an ein Netzsegment ohne gültiges Gerätezertifikat | Mitschnitt, Einspeisung, ARP- und DHCP-Manipulation, Portscan, Ausnutzung offener Dienste | Zugang zu Diensten, Erlangen eines Kopplungscodes, Namensauflösung umlenken | Default-Deny in jeder Netzzone (INV-10), 802.1X mit EAP-TLS gegen die interne PKI, TLS 1.3 (RFC 8446) an jeder Strecke, DNSSEC-Validierung im Resolver | Erschöpfungsangriffe auf Leitungsebene; ein Angreifer mit Zugriff auf den physischen Uplink kann jederzeit die Verfügbarkeit nehmen |
| **A-2** Übernommener Bedienerarbeitsplatz | Vollständige Kontrolle über den Browser und das Betriebssystem eines Bedieners | Auslesen von Sitzungsmaterial im Browser, Auslösen beliebiger Aktionen im Namen des Bedieners, Bildschirmmitschnitt | Ausführen privilegierter Vorgänge, Anlegen eines dauerhaften Zugangs | Bindung der Sitzung an den Passkey-Authentisierer, kurze Zugriffstokenlaufzeit, Freigabepflicht und Vier-Augen-Prinzip für die schwerwiegendsten Vorgangsklassen ([Kapitel 19](19-mandanten-rechte-audit.md)) | Handlungen innerhalb der Rechte des Bedieners, die keine Freigabe verlangen; diese sind erkennbar, aber nicht verhinderbar |
| **A-3** Innentäter mit Teilrechten | Gültige Anmeldung, Administratorrolle mit begrenztem Geltungsbereich | Kenntnis interner Abläufe, Zeit, legitime API-Zugriffe, Kombination erlaubter Einzelschritte | Ausweitung des Geltungsbereichs, verdeckter Datenabfluss, Spurenbeseitigung | Autorisierung an einer Stelle und standardmäßig verweigernd (E-06), Mandantenprädikat in jeder Abfrage (INV-19), unveränderliche Auditkette (INV-23), Befristung von Administratorrollen | Missbrauch der legitimen Rechte selbst; das Modell erkennt ihn, verhindert ihn nicht |
| **A-4** Übernommener Konnektorprozess | Codeausführung im Kontext eines Konnektorprozesses, etwa über eine Schwachstelle im Parser einer Fremdantwort | Beliebiger Code unter der Kennung `atrium-conn-<mandant-kurz>-<konnektor>`, Zugriff auf die eigene Auftragsreferenz | Ausbruch auf den Knoten, Zugriff auf fremde Mandanten, Erreichen der Kontrollebene | Eigener Systembenutzer je Mandant und Bindung, eigener Netznamensraum mit Ausgangs-Positivliste (INV-21), Systemaufruffilter, kein Netzweg zu Port 8400 ([Kapitel 09](09-konnektoren.md)) | Vollständiger Missbrauch des Fremdsystems, für das der Konnektor legitim berechtigt ist |
| **A-5** Kompromittiertes Fremdsystem | Kontrolle über den Endpunkt einer Konnektorbindung | Beliebige Antworten, auch bösartig geformte; Vorspiegelung falscher Istzustände; Verzögerung und Ratenbegrenzung | Angriff auf den Konnektorparser, Erzeugen falscher Abweichungsanzeigen, Erzwingen von Löschungen im Sollzustand | Schemavalidierung am Rand mit Ablehnung unbekannter Felder (E-05), Größen- und Zeitgrenzen (E-11), Feldeigentum (INV-13), Istzustand ist nie Entscheidungsgrundlage außer für die Abweichungsanzeige | Datenverlust im Fremdsystem selbst; Atrium kann dort nur nach Feldeigentum korrigieren |
| **A-6** Angreifer mit physischem Zugang | Zugang zum Knoten, zu Datenträgern und zur Konsole | Ausbau von Datenträgern, Anschluss eigener Geräte, Kaltstart, Manipulation der Firmware | Lesen der Nutzdaten, Erlangen von Schlüsselmaterial, dauerhafte Implantate | Datenträgerverschlüsselung mit TPM-Bindung, gemessener Start mit UEFI Secure Boot und dm-verity, Notzugang mit nicht unterdrückbarem Auditereignis ([Kapitel 10](10-identitaet.md)) | Der laufende Knoten. Wer an einer laufenden Maschine physisch arbeitet, erreicht Hauptspeicherinhalte; dagegen wirkt nur physische Zugangskontrolle außerhalb dieses Produkts |
| **A-7** Angreifer in der Lieferkette | Zugriff auf eine Abhängigkeit, ein Basispaket, einen Bauknoten oder den Freigabeschlüssel | Einbringen von Code in ein Artefakt, das jeder Knoten ausführt | Codeausführung auf allen Installationen gleichzeitig | Reproduzierbarer Bau, Festlegung der Abhängigkeitsversionen, Signatur nach RFC 8032, Transparenzprotokoll mit Zeitstempel nach RFC 3161, Prüfung beim Einspielen ohne Übersteuerung (20.6) | Eine Hintertür in einer Abhängigkeit, die reproduzierbar gebaut und korrekt signiert wird; dagegen wirkt nur Prüfung des Quelltextes, die das Abhängigkeitsbudget (E-12) begrenzt, aber nicht ersetzt |
| **A-8** Anbieter der Virtualisierungsumgebung | Kontrolle über Hypervisor, virtuelle Datenträger und Fernkonsole | Lesen und Verändern des Hauptspeichers, Mitschnitt der Konsole, Kopieren der Datenträger, Vorspiegelung eines TPM | Vollständiger Zugriff auf einen oder alle Knoten | Kennzeichnung des Knotens in der Konsole als "Fernkonsole anbieterseitig mitlesbar", Beschränkung des Notzugangs auf Knoten unter eigener physischer Kontrolle, Ausschluss des Kopplungscodeverfahrens über mitlesbare Konsolen (Aufnahmetoken als ausdrücklich schwächeres Verfahren) | Die Vertraulichkeit jedes Schutzguts auf einem gemieteten Knoten. Ein virtualisiertes TPM ist eine Aussage des Anbieters über sich selbst |

Zwei Klassen sind nicht abwehrbar und werden deshalb als Randbedingung behandelt statt als Bedrohung, gegen die Maßnahmen versprochen werden: A-8 vollständig hinsichtlich Vertraulichkeit und A-6 gegenüber einem laufenden Knoten. Die Konsole macht beides sichtbar, statt eine Zusage zu geben, die die Technik nicht einlöst.

## 20.3 Vertrauensgrenzen

```
   Außennetz                                                  Lieferant
      |                                                            |
      | VG-01 (443/tcp, 443/udp, 80/tcp)                           | VG-08
      v                                                            v
+-----------------+        +----------------------------+   +--------------+
| Envoy (Eingang) |        | Bedienergerät / Browser    |   | Abbild, SBOM |
+--------+--------+        +-------------+--------------+   | Signatur     |
         |                               | VG-03                +----+-----+
         | (intern)                      v                           |
         |                    +----------+-----------+               |
         +------------------->|  atrium-core (8400)  |<--------------+
                              |  Sollzustand, PKI    |
                              +--+----+-----+--------+
                       VG-04 8401 |    | 8402        | VG-05 (Unix-Socket)
                 +----------------+    v             v
                 |            +--------+------+  +---+--------------------+
        anderer Knoten        |  atrium-node  |  | atrium-connector-<name>|
                 |            +---+-----------+  +---+--------------------+
                 |  VG-04 (WireGuard) | VG-06                | VG-06'
                 v                    v                      v
          +------+------+     +-------+--------+     +-------+---------+
          | Speichernetz|     | Dienstprozess  |     |  Fremdsystem    |
          +------+------+     +----------------+     +-----------------+
                 | VG-07
                 v
       Auslagerungsziel, Auditziel
```

| Grenze | Innen | Außen | Durchgangspunkt | Authentisierung der Gegenseite |
|---|---|---|---|---|
| **VG-01** | Eingangsproxy und alle veröffentlichten Dienste | Unauthentisiertes Außennetz | 443/tcp, 443/udp, 80/tcp | keine vor der Anwendungsschicht; Zugriffskreis der Veröffentlichung entscheidet |
| **VG-02** | Knoten im Wartemodus | Lokales Netzsegment | 8403/tcp | SPAKE2 aus dem Kopplungscode (RFC 9382), gebunden an den TLS-Exporter |
| **VG-03** | Öffentliche API von atrium-core | Bedienergerät, Automatisierung | 443/tcp über den Eingang auf 8400/tcp | OIDC-Sitzung mit Passkey; Dienstkonten mit mTLS oder Token nach RFC 9068 |
| **VG-04** | Kontrollebene und Knotenagent | Anderer Knoten derselben Installation | 8401/tcp, 8402/tcp innerhalb WireGuard | gegenseitiges TLS mit Knotenzertifikat, TPM-gebunden |
| **VG-05** | atrium-core | Konnektorprozess | Unix-Socket, gRPC | Dateisystemrechte und Systembenutzer; kein Netzweg |
| **VG-06** | Konnektorprozess bzw. Dienstprozess | Fremdsystem bzw. Knotenkern | Ausgangs-Positivliste bzw. Systemaufrufschnittstelle | Konnektorgeheimnis gegen das Fremdsystem; Systemaufruffilter gegen den Kern |
| **VG-07** | Knoten | Auslagerungsziel, Auditziel | 22/tcp, 6514/tcp | Schlüsselpaar je Knoten mit Zweckbindung; gegenseitiges TLS |
| **VG-08** | Knoten | Aktualisierungskanal des Lieferanten | Bezug des Abbilds über beliebigen Weg | Ed25519-Signatur (RFC 8032) und Eintrag im Transparenzprotokoll; der Transportweg ist unvertrauenswürdig |

### STRIDE je Vertrauensgrenze

**VG-01 Außennetz zum Eingang**

| Kategorie | Bedrohung | Gegenmaßnahme | Restrisiko |
|---|---|---|---|
| Spoofing | Vorspiegelung eines veröffentlichten Namens gegenüber Clients | Zertifikat je Veröffentlichung aus der eigenen oder einer öffentlichen CA; CAA-Eintrag (RFC 8659) je externer Domäne | Ein Client, der Zertifikate nicht prüft, ist nicht schützbar |
| Tampering | Manipulation von Verkehr zwischen Client und Eingang | TLS 1.3 (RFC 8446) ohne Rückfall auf ältere Versionen | Schwachstellen in der TLS-Umsetzung des Eingangs |
| Repudiation | Bestreiten einer über den Eingang ausgelösten Änderung | Korrelationskennung je Anfrage bis in das Auditereignis; Zuordnung zur Sitzung | Anfragen ohne Anmeldung sind nur netzseitig zuordenbar |
| Information Disclosure | Rückschluss auf interne Struktur über Fehlermeldungen und Kopfzeilen | Stabile Fehlercodes nach außen, vollständige Information nur im Audit (E-14); Unterdrückung von Versions- und Serverkennungen | Zeitverhalten und Antwortgrößen bleiben als Seitenkanal |
| Denial of Service | Erschöpfung von Verbindungen, Anfragen, Zertifikatsausstellungen | Verbindungs- und Anfrageratenbegrenzung je Quelle und je Veröffentlichung im Eingang; Ausstellungsrate je Domäne begrenzt | Volumenangriffe oberhalb der Anbindungsbandbreite sind nicht abwehrbar |
| Elevation of Privilege | Ausbruch aus dem Eingangsprozess auf den Knoten | Eigener Benutzer, `ProtectSystem=strict`, Systemaufruffilter, leere Capability-Menge, kein Schreibzugriff außerhalb des Kratzverzeichnisses | Envoy ist in C++ geschrieben; die Speichersicherheitszusage aus E-01 gilt hier nicht (20.5) |

**VG-02 Lokales Netz zum Kopplungsendpunkt**

| Kategorie | Bedrohung | Gegenmaßnahme | Restrisiko |
|---|---|---|---|
| Spoofing | Angreifer gibt sich als Ankerknoten aus und nimmt den wartenden Knoten auf | SPAKE2 ist beidseitig: ohne Kenntnis des Codes entsteht kein gemeinsames Geheimnis | Ein Bediener, der den Code auf einem fremden Bildschirm abliest, koppelt an ein fremdes System |
| Tampering | Einfügen eines eigenen Knotenzertifikats in den Ablauf | Bindung des SPAKE2-Ergebnisses an den TLS-Exporter der Verbindung | Keines auf Protokollebene bekannt |
| Repudiation | Bestreiten einer Kopplung | Kopplungsvorgang und Ergebnis sind Auditereignisse, auch bei Fehlschlag | Keines |
| Information Disclosure | Mitschnitt des Kopplungslaufs zwecks Offline-Raten | SPAKE2 (RFC 9382) lässt kein Offline-Raten zu; jeder Versuch kostet einen Online-Lauf | Der Code selbst ist sichtbar, wo der Bildschirm sichtbar ist |
| Denial of Service | Erschöpfen der fünf Fehlversuche, um die Kopplung zu verhindern | Codeerneuerung ist eine Bedienhandlung ohne Kosten; Fehlversuche sind im Audit sichtbar | Ein dauerhafter Angreifer im Segment kann die Kopplung dauerhaft verzögern |
| Elevation of Privilege | Aufnahme eines Knotens unter Kontrolle des Angreifers in die Kontrollebene | Zweckwahl nach der Kopplung; Stimmrecht ist eine eigene, auditierte Mitgliedschaftsänderung (INV-05) | Ein aufgenommener Dienstträger sieht die auf ihm platzierten Dienste |

**VG-03 Bedienergerät zur Konsole und API**

| Kategorie | Bedrohung | Gegenmaßnahme | Restrisiko |
|---|---|---|---|
| Spoofing | Anmeldung mit gestohlenem Anmeldemittel | Passkey mit Ursprungsbindung; kein Kennwort als alleiniges Mittel für Administratorrollen | Ein Authentisierer im Zugriff des Angreifers bleibt gültig |
| Tampering | Fremdveranlasste Anfragen aus einer anderen Ursprungsseite | Token im Speicher der Anwendung statt in einem automatisch mitgesendeten Merkmal; Ursprungsprüfung; keine formularbasierte Zustandsänderung | Eine bösartige Erweiterung im Browser des Bedieners umgeht jede dieser Maßnahmen |
| Repudiation | Bestreiten eines Vorgangs | Jeder Vorgang trägt Auslöser, Sitzung, Zeitpunkt und Wirkungsvorschau (INV-23) | Handlungen unter Notzugang sind zuordenbar, aber nicht personengenau, wenn der Code geteilt wurde |
| Information Disclosure | Auslesen von Sitzungsmaterial durch eingeschleustes Skript | Strenge Inhaltsrichtlinie ohne `unsafe-inline`, keine dynamische Codeausführung (E-10), keine Ablage langlebiger Geheimnisse im Browser | Eine Schwachstelle in der Konsole selbst |
| Denial of Service | Sperren von Konten durch Fehlanmeldungen | Ratenbegrenzung je Quelle statt Kontosperre; Sperre nur mit sichtbarer Entsperrmöglichkeit | Gezielte Ratenerschöpfung verzögert legitime Anmeldungen |
| Elevation of Privilege | Zugriff auf Objekte fremder Mandanten über manipulierte Kennungen | Mandantenprädikat in jeder Abfrage (INV-19); Autorisierung an einer Stelle (E-06); Kennungen sind nicht erratbar, aber auch nicht geheim | Ein Fehler in der einen Autorisierungsstelle wirkt überall; deshalb ist genau diese Stelle vollständig testabgedeckt |

**VG-04 Knoten zu Knoten**

| Kategorie | Bedrohung | Gegenmaßnahme | Restrisiko |
|---|---|---|---|
| Spoofing | Fremder Knoten tritt der Konsensgruppe bei | Gegenseitiges TLS mit Knotenzertifikat aus der Ausgabe-CA; Mitgliedschaft ist ein auditierter Sollzustandseintrag | Ein gestohlenes, nicht TPM-gebundenes Knotenzertifikat auf einem virtualisierten Knoten |
| Tampering | Verändern replizierter Einträge | Hashverkettung des Änderungsprotokolls; Signatur der Sollzustandsauszüge an atrium-node (INV-03) | Ein kompromittierter Führungsknoten kann gültige Einträge erzeugen |
| Repudiation | Bestreiten einer Replikationsentscheidung | Jeder Eintrag trägt Ursprung und Korrelationskennung | Keines |
| Information Disclosure | Mitlesen im Unterlagerungsnetz | WireGuard-Vollvermaschung zwischen allen Knoten; kein unverschlüsselter Knotenverkehr | Verkehrsmuster und Paketgrößen bleiben sichtbar |
| Denial of Service | Partition erzwingen, um Schreibvorgänge zu blockieren | Eingefrorener Zustand statt Divergenz (INV-04); Dienste laufen weiter (INV-25) | Ohne Quorum sind Änderungen bis zur Wiederherstellung nicht möglich; das ist gewollt |
| Elevation of Privilege | Von einem Dienstträger auf die Kontrollebene | Stimmrecht ist eine getrennte Rolle; atrium-node akzeptiert nur signierte Auszüge und kennt keine Schreiboperation zur Kontrollebene | Ein kompromittierter Stimmknoten ist ein kompromittierter Teil der Kontrollebene |

**VG-05 Kern zum Konnektorprozess**

| Kategorie | Bedrohung | Gegenmaßnahme | Restrisiko |
|---|---|---|---|
| Spoofing | Fremder Prozess gibt sich als Konnektor aus | Socket je Bindung mit Dateisystemrechten des zugehörigen Systembenutzers | Ein Angreifer mit Rechten dieses Benutzers ist der Konnektor |
| Tampering | Manipulierte `observe`-Ergebnisse erzeugen falsche Abweichungen | Istzustand ist nie Entscheidungsgrundlage außer für die Anzeige; Schreibwirkung nur über einen Vorgang mit Wirkungsvorschau (INV-08) | Eine falsche Abweichungsanzeige kann einen Bediener zu einer falschen Freigabe verleiten |
| Repudiation | Bestreiten eines `apply`-Aufrufs | Idempotenzschlüssel und Korrelationskennung je Aufruf (INV-07) | Das Fremdsystem protokolliert eigenständig und möglicherweise abweichend |
| Information Disclosure | Konnektor liest Geheimnisse anderer Bindungen | Kurzlebige, auftragsgebundene Referenz statt Geheimniswert (INV-20); eigener Benutzer je Mandant und Bindung | Das Geheimnis ist während des Auftrags im Prozessspeicher |
| Denial of Service | Konnektor blockiert den Kern durch Nichtantwort | Zeitüberschreitung je Aufruf, Leerlaufabschaltung nach 10 min, Parallelitätsgrenze aus K-20 | Ein Fremdsystem mit Ratenbegrenzung verzögert die Versorgung bis zur Obergrenze aus K-15 |
| Elevation of Privilege | Konnektor erreicht die API der Kontrollebene | Kein Netzzugang zu 8400/tcp (INV-21); eigener Netznamensraum | Der Unix-Socket selbst ist der einzige Weg und trägt nur den Konnektorvertrag |

**VG-06 Konnektor zum Fremdsystem, Dienstprozess zum Knoten**

| Kategorie | Bedrohung | Gegenmaßnahme | Restrisiko |
|---|---|---|---|
| Spoofing | Umlenkung auf einen falschen Fremdendpunkt | Ausgangs-Positivliste aus der Bindung; Zertifikatsprüfung gegen den öffentlichen Vertrauensspeicher oder einen gepinnten Anker | Ein Fremdsystem, dessen Betreiber selbst umgeleitet wurde |
| Tampering | Bösartig geformte Antwort greift den Parser an | Schemavalidierung mit Ablehnung unbekannter Felder (E-05); Größengrenzen (E-11); Rust als Sprache (E-01) | Logikfehler in der Manifestauswertung sind speichersicher, aber nicht harmlos |
| Repudiation | Fremdsystem bestreitet eine Änderung | Vorher-Nachher-Zustand im Auditereignis, soweit das Fremdsystem ihn liefert | Fremdsysteme ohne Abfragemöglichkeit des Vorzustands |
| Information Disclosure | Abfluss von Personendaten an ein Fremdsystem | Feldeigentum (INV-13) begrenzt, was übertragen wird; Wirkungsvorschau zeigt es vorab | Jede legitime Versorgung überträgt Daten; das ist der Zweck |
| Denial of Service | Dienstprozess erschöpft Knotenressourcen | Ressourcenbudget je Dienst über die erzeugte systemd-Einheit; `MemoryHigh` vor `MemoryMax` | Ein Dienst innerhalb seines Budgets kann andere Dienste verdrängen, wenn das Budget zu großzügig gesetzt ist |
| Elevation of Privilege | Dienstprozess erlangt Knotenrechte | Rootless-Ausführung wo das Produkt es zulässt, eigener Benutzer, seccomp, `NoNewPrivileges`, eingeschränkter Kernmodus | Kernschwachstellen, die über die Positivliste erreichbar bleiben |

**VG-07 Knoten zu Auslagerungs- und Auditziel**

| Kategorie | Bedrohung | Gegenmaßnahme | Restrisiko |
|---|---|---|---|
| Spoofing | Untergeschobenes Auslagerungsziel nimmt Sicherungen entgegen | Zielbindung an einen gepinnten Wirtsschlüssel; Schlüsselpaar je Knoten ausschließlich für diesen Zweck berechtigt | Ein übernommenes legitimes Ziel |
| Tampering | Veränderung ausgelagerter Stände | Signatur und Prüfsumme je Wiederherstellungspunkt; Prüfung vor jeder Verwendung ([Kapitel 17](17-speicher-backup.md)) | Löschung statt Veränderung; dagegen wirkt nur ein zweites Ziel |
| Repudiation | Bestreiten einer Auslagerung | Auslagerung ist ein Vorgang mit Auditereignis | Keines |
| Information Disclosure | Lesen der ausgelagerten Daten am Ziel | Verschlüsselung vor der Übertragung mit mandantenbezogenem Schlüssel, der das Ziel nie erreicht | Metadaten wie Größe, Zeitpunkt und Anzahl der Stände |
| Denial of Service | Ziel nimmt nichts mehr an | Sichtbarer Zustand am Speicherbereich statt stiller Fehlschlag (INV-18) | Ohne erreichbares Ziel läuft der RPO der Stufe "Lokal" weiter |
| Elevation of Privilege | Vom Auslagerungsziel zurück auf den Knoten | Der Zweckschlüssel erlaubt nur Schreiben und Lesen im Zielverzeichnis, keine Anmeldung | Eine Schwachstelle im verwendeten Transportwerkzeug |

**VG-08 Lieferkette zum Knoten**

| Kategorie | Bedrohung | Gegenmaßnahme | Restrisiko |
|---|---|---|---|
| Spoofing | Untergeschobenes Abbild eines fremden Herausgebers | Signaturprüfung gegen den im aktiven Abbild verankerten Freigabeschlüssel | Kompromittierung des Freigabeschlüssels |
| Tampering | Nachträgliche Veränderung eines freigegebenen Abbilds | dm-verity über die gesamte Abbildhälfte; Wurzelhashwert ist Teil der Signatur | Keines gegen ein korrekt signiertes, bösartiges Abbild |
| Repudiation | Bestreiten einer Freigabe | Eintrag jeder Freigabe in ein anhängbares, hashverkettetes Transparenzprotokoll mit Zeitstempel nach RFC 3161 | Ein Angreifer mit Kontrolle über Schlüssel und Protokoll gleichzeitig |
| Information Disclosure | Rückschluss auf Kundenbestand aus Bezugsmustern | Bezug ohne Kundenkennung; Transparenzprotokoll enthält Freigaben, nicht Abnehmer | Netzseitige Beobachtung des Bezugs |
| Denial of Service | Blockieren des Aktualisierungsbezugs | Der Knoten bleibt lauffähig; Aktualisierung ist keine Betriebsvoraussetzung | Ungepatchte Schwachstellen bei dauerhafter Blockade |
| Elevation of Privilege | Gezielte Einzelauslieferung an einen Kunden | Prüfung des Transparenzprotokolleintrags beim Einspielen: ein Abbild ohne öffentlichen Eintrag wird abgelehnt | Ein Protokollbetreiber, der zurückdatierte Einträge erzeugt; dagegen wirkt nur Beobachtung durch Dritte |

## 20.4 Vertiefte Analysen

Jede Analyse folgt derselben Gliederung: Angriffspfad, Voraussetzungen des Angreifers, Wirkung, Erkennung, Gegenmaßnahme, Restrisiko.

### T-01 Kopplungsvorgang

| Feld | Inhalt |
|---|---|
| Angriffspfad | Der Angreifer erreicht den Kopplungsendpunkt 8403/tcp eines Knotens im Wartemodus und versucht, den zwölfstelligen Kopplungscode zu erraten, oder er stellt sich zwischen Ankerknoten und wartenden Knoten und versucht, den Lauf zu übernehmen. |
| Voraussetzungen | Netzzugang zum lokalen Segment des wartenden Knotens innerhalb der Gültigkeitsdauer von 15 min (INV-27). Kein Anmeldemittel erforderlich. |
| Wirkung bei Erfolg | Der Angreifer erhält ein Knotenzertifikat aus der Ausgabe-CA und damit die Stellung eines gekoppelten Knotens; je nach gewähltem Zweck trägt er Dienste oder wird Verwaltungsknoten. |
| Erkennung | Jeder Fehlversuch erzeugt ein Auditereignis; fünf Fehlversuche vernichten den Code und erzeugen eine Störungsmeldung im Überblick. Ein erfolgreicher Kopplungsvorgang ohne zugehörige Bedienhandlung ist am Fehlen der auslösenden Sitzung erkennbar. |
| Gegenmaßnahme | SPAKE2 (RFC 9382) statt Klartextvergleich; Bindung an den TLS-Exporter; Einmaligkeit, Kurzlebigkeit und Versuchszähler; der Endpunkt ist ausschließlich im Wartemodus offen und nur an das lokale Segment gebunden. |
| Restrisiko | Der Code wird visuell abgelesen. Wer den Bildschirm des wartenden Knotens sieht, koppelt. Auf gemieteten Servern mit anbieterseitig mitlesbarer Fernkonsole entfällt die Schutzwirkung, weshalb dort das schwächere Aufnahmetoken eingesetzt und als solches gekennzeichnet wird. |

Die Rechnung zu K-14 wird hier nicht wiederholt, sondern um den Fall des dauerhaft anwesenden Angreifers ergänzt. Annahme: Der Angreifer erzwingt durch Störung so viele Kopplungsversuche, wie ein Bediener in einem Arbeitstag durchführt, nämlich 20. Bei je 5 Fehlversuchen sind das 100 Rateversuche gegen einen Raum von 2^50 = 1,1259 × 10^15. Erfolgswahrscheinlichkeit: 100 / 1,1259 × 10^15 = 8,88 × 10^-14. Deutung: Der Angriffspfad ist rechnerisch geschlossen; das verbleibende Risiko liegt vollständig bei der visuellen Übertragung des Codes. Das ist ein Modell, keine Messung.

### T-02 Ausbruch aus der Konnektorsandbox

| Feld | Inhalt |
|---|---|
| Angriffspfad | Ein Fremdsystem liefert eine Antwort, die eine Schwachstelle im Konnektor auslöst. Der Angreifer erlangt Codeausführung im Konnektorprozess und versucht von dort, den Netznamensraum zu verlassen, fremde Prozesse zu lesen oder Rechte auszuweiten. |
| Voraussetzungen | Kontrolle über den Endpunkt einer aktiven Konnektorbindung (A-5) und eine ausnutzbare Schwachstelle im Konnektor oder in einer seiner Abhängigkeiten. |
| Wirkung bei Erfolg | Zugriff auf die laufende Auftragsreferenz und das dahinterstehende Geheimnis dieser einen Bindung; bei erfolgreichem Ausbruch Zugriff auf den Knoten und damit auf dort liegende Speicherbereiche. |
| Erkennung | Abbruch durch den Systemaufruffilter erzeugt einen Prozessabbruch mit Signal, der als Auditereignis und als Störung am Konnektor sichtbar wird. Ausgehende Verbindungsversuche außerhalb der Positivliste werden verworfen und gezählt. |
| Gegenmaßnahme | Rust für alle mitgelieferten Konnektoren und den generischen Treiber (E-01); eigener Systembenutzer je Mandant und Bindung; eigener Netznamensraum; Systemaufruffilter als Positivliste mit `KILL_PROCESS`; leere Capability-Menge; kein Zugriff auf den Geheimnisspeicher, sondern nur auf eine kurzlebige Referenz; Details in [Kapitel 09](09-konnektoren.md). |
| Restrisiko | Der Prozess bleibt für die Dauer seines Auftrags im Besitz des entschlüsselten Geheimnisses seiner eigenen Bindung. Ein Ausbruch über eine Kernschwachstelle innerhalb der zugelassenen Systemaufrufe ist nicht ausgeschlossen. Die Trennung je Mandant begrenzt den Schaden auf einen Mandanten, sie verhindert ihn nicht. |

Rechnung zur Schadensbegrenzung: Bei 20 Mandanten mit je 8 Bindungen existieren 160 mögliche Prozessidentitäten. Ein übernommener Prozess erreicht davon 1, also 0,625 % der Bindungen. Ohne Trennung je Mandant und Bindung wären es 100 %. Annahmen: gleichverteilte Bindungen, ein Prozess je Bindung, kein Ausbruch. Das ist ein Modell, keine Messung.

### T-03 Bedienersitzung und Sitzungsdiebstahl

| Feld | Inhalt |
|---|---|
| Angriffspfad | Der Angreifer erlangt das Sitzungsmaterial eines angemeldeten Bedieners, entweder durch Kontrolle über dessen Gerät (A-2) oder über eine Schwachstelle in der Konsole, und benutzt es gegen die API. |
| Voraussetzungen | Zugriff auf den Browserkontext des Bedieners oder auf eine Ausführungsmöglichkeit im Ursprung der Konsole. |
| Wirkung bei Erfolg | Alle Handlungen innerhalb der Rolle des Bedieners, für die keine zusätzliche Freigabe erforderlich ist. |
| Erkennung | Abweichung von Gerät, Netzzone und Zeitmuster; parallele Sitzungen desselben Kontos aus unterschiedlichen Netzzonen; Häufung von Vorgängen ohne zugehörige Wirkungsvorschau-Ansicht. Jede Handlung bleibt im Auditstrom zuordenbar. |
| Gegenmaßnahme | Zugriffstoken mit kurzer Laufzeit (Zielwert 15 min) und Erneuerung nur gegen einen an den Passkey-Authentisierer gebundenen Nachweis; Ursprungsbindung des Passkeys; Ablage des Zugriffstokens ausschließlich im Speicher der Anwendung; strenge Inhaltsrichtlinie ohne dynamische Codeausführung; Freigabepflicht und Vier-Augen-Prinzip für die schwerwiegendsten Vorgangsklassen; sofortige Sitzungsbeendigung bei Rollenentzug. |
| Restrisiko | Solange das Gerät des Bedieners unter fremder Kontrolle steht, ist jede Erneuerung erfolgreich, weil der Authentisierer erreichbar ist. Die Bindung an den Authentisierer verkürzt das Zeitfenster nach dem Ende der Gerätekompromittierung, sie verhindert den Missbrauch während der Kompromittierung nicht. |

Rechnung zum Zeitfenster: Bei einer Tokenlaufzeit von 15 min und einer Erneuerung, die einen Authentisierernachweis verlangt, endet die Nutzbarkeit eines exfiltrierten Tokens nach höchstens 900 s. Ohne Bindung an den Authentisierer wäre die Nutzbarkeit durch die Laufzeit des Erneuerungsmaterials begrenzt; bei einem Zielwert von 8 h sind das 28.800 s, also der Faktor 32. Annahmen: keine Vorratshaltung erneuerter Token durch den Angreifer, Uhrensynchronität nach K-30.

### T-04 Kompromittierung der CA

| Feld | Inhalt |
|---|---|
| Angriffspfad | Der Angreifer erlangt die Nutzungsmöglichkeit des Schlüssels der Ausgabe-CA oder einer Mandanten-Zwischen-CA, entweder durch Übernahme von atrium-core auf einem Verwaltungsknoten oder durch physischen Zugriff auf einen laufenden Knoten mit entsiegeltem TPM-Kontext. |
| Voraussetzungen | Codeausführung im Kontext von atrium-core auf einem Knoten, dessen TPM-Richtlinie erfüllt ist, oder Besitz des Wurzelschlüsselmaterials außerhalb des Systems. |
| Wirkung bei Erfolg | Ausstellung gültiger Zertifikate für beliebige interne Namen im Geltungsbereich der jeweiligen CA. Bei der Ausgabe-CA betrifft das alle Mandanten, bei einer Zwischen-CA wegen der Namensbeschränkungen nur deren Mandanten. Der Angreifer kann Knoten-, Geräte-, Dienst- und Personenzertifikate erzeugen und damit gegenseitig authentisiertes TLS, EAP-TLS und die Konsolenanmeldung mit Zertifikat unterlaufen. |
| Erkennung | Jede Ausstellung ist ein Ereignis im replizierten Protokoll (INV-22). Eine Ausstellung ohne zugehörigen Sollzustandseintrag ist eine Abweichung und wird angezeigt. Der Abgleich "ausgestellte Zertifikate gegen erwartete Inhaberobjekte" läuft periodisch und meldet jede Differenz. |
| Gegenmaßnahme | TPM-Bindung mit signierter Richtlinienaussage ([Kapitel 11](11-pki.md)); Nichtexportierbarkeit; getrennte Zwischen-CA je Mandant ab M0 mit Namensbeschränkungen als kritischer Erweiterung; Wurzel offline (INV-22); vollständige Rekonstruierbarkeit der Ausstellungshistorie. |
| Restrisiko | Der Angreifer, der atrium-core kontrolliert, kontrolliert auch die Instanz, die den Ausstellungsabgleich durchführt. Die Erkennung ist daher nur belastbar, wenn der Auditstrom bereits ausgelagert wurde. Die Auslagerungsfrequenz bestimmt das Erkennungsfenster. |

Rechnung zum Wiederaufbau nach einer Kompromittierung der Ausgabe-CA: Zu ersetzen sind die Zertifikate der mittleren Installation aus K-12, also 150 Dienste, 800 Geräte, 32 Knoten und die Zwischen-CA je Mandant. Annahme: eine Ausstellung über den internen ACME-Server dauert 10 s einschließlich Schlüsselerzeugung auf der Gegenseite, Parallelität 8. Dienste und Knoten: (150 + 32) × 10 s / 8 = 227,5 s ≈ 4 min. Geräte: 800 × 10 s / 8 = 1.000 s ≈ 17 min, jedoch nur für erreichbare Geräte; nicht erreichbare Geräte werden erst bei ihrer nächsten Meldung versorgt. Verteilung des neuen Vertrauensankers an Geräte: an das Erneuerungsfenster von 120 d gebunden (K-13). Deutung: Die technische Neuausstellung dauert unter 30 min, die vollständige Ablösung des alten Ankers auf allen Geräten dauert bis zu 120 Tage. Diese Lücke ist der eigentliche Schaden und nicht die Ausstellungsdauer. Annahmen offengelegt, keine Messung.

### T-05 DNS-Manipulation

| Feld | Inhalt |
|---|---|
| Angriffspfad | Drei Varianten: Fälschung von Antworten gegenüber Clients im internen Netz; Einspeisung falscher Daten in den rekursiven Resolver; Manipulation der extern delegierten Zone über den Registrar oder über einen übernommenen Verwaltungsknoten. |
| Voraussetzungen | Variante 1 verlangt Netzzugang zum Segment des Clients, Variante 2 die Fähigkeit, Antworten des Vorgelagerten zu fälschen, Variante 3 Zugriff auf das Registrarkonto oder auf einen Verwaltungsknoten. |
| Wirkung bei Erfolg | Umlenkung von Clients auf fremde Endpunkte, Ausstellung eines öffentlichen Zertifikats über einen gefälschten ACME-Nachweis, Umlenkung von Mail über einen manipulierten MX-Eintrag. |
| Erkennung | Abweichung zwischen erzeugter und ausgelieferter Zone; die Seriennummer entspricht der Sollzustandsversion und macht jede Abweichung sofort sichtbar. Fehlgeschlagene DNSSEC-Validierung im Resolver wird gezählt und angezeigt. Ein CAA-Eintrag (RFC 8659) begrenzt, welche öffentliche CA ausstellen darf. |
| Gegenmaßnahme | DNSSEC nach RFC 4033, RFC 4034 und RFC 4035 mit automatischer Signierung und automatischem Schlüsselwechsel; validierender rekursiver Resolver ohne offene Rekursion; erzwungene verschlüsselte Namensauflösung auf verwalteten Geräten über RFC 7858 oder RFC 8484; Sichtattribut je Eintrag statt getrennt gepflegter Zoneninstanzen; jeder Verwaltungsknoten erzeugt die Zone deterministisch aus demselben Sollzustand, sodass kein Zonentransfer als Angriffsfläche besteht. Einzelheiten in [Kapitel 12](12-dns-netzwerk.md). |
| Restrisiko | Auf nicht verwalteten Geräten ist die Namensauflösung nicht durchsetzbar; ein Browser mit eigenem verschlüsseltem Resolver umgeht den internen Resolver vollständig. Die Delegierung der externen Zone liegt beim Registrar und damit außerhalb des Systems. |

Rechnung zum Wirkungsfenster einer gefälschten Antwort: Die interne TTL beträgt 300 s (K-17), die externe 3.600 s. Eine erfolgreich untergeschobene Antwort wirkt höchstens für die Dauer der TTL, danach wird erneut aufgelöst und die Fälschung muss wiederholt werden. Bei DNSSEC-Validierung im Resolver scheitert die Einspeisung ohne Schlüsselkompromittierung; die TTL-Rechnung gilt daher nur für die Strecke zwischen Resolver und einem Client, der selbst nicht validiert.

### T-06 Aktualisierungskanal

| Feld | Inhalt |
|---|---|
| Angriffspfad | Der Angreifer bringt ein bösartiges Systemabbild in den Bezugsweg ein: durch Manipulation des Spiegels, durch Rückspielen einer älteren, verwundbaren Freigabe, durch gezielte Einzelauslieferung an einen Kunden oder durch Kompromittierung des Freigabeschlüssels. |
| Voraussetzungen | Für die ersten drei Varianten Kontrolle über den Übertragungsweg; für die vierte Zugriff auf den Freigabeschlüssel des Lieferanten. |
| Wirkung bei Erfolg | Codeausführung mit vollen Rechten auf jedem Knoten, der das Abbild einspielt, und damit Zugriff auf alle Schutzgüter des Knotens einschließlich des entsiegelten Schlüsselmaterials. |
| Erkennung | Prüfung der Signatur, des Wurzelhashwerts, der Versionsfolge und des Transparenzprotokolleintrags vor dem Schreiben in die inaktive Abbildhälfte. Eine gezielte Einzelauslieferung fällt auf, weil kein öffentlicher Protokolleintrag existiert. Eine Rückdatierung fällt auf, weil der Zeitstempel nach RFC 3161 mit der Kettenposition verglichen wird. |
| Gegenmaßnahme | Reproduzierbarer Bau, signierte Artefakte, Transparenzprotokoll, Versionsfolgeprüfung gegen Rückspielen, A/B-Wurzeldateisystem mit automatischem Rückfall (K-23), gestaffelte Ausrollung mit Verwaltungsknoten zuletzt. Ablauf und Regeln in 20.6 und [Kapitel 21](21-betrieb-updates.md). |
| Restrisiko | Ein Angreifer im Besitz des Freigabeschlüssels, der einen Protokolleintrag erzeugen kann, wird durch keine der Maßnahmen erkannt. Dagegen wirkt nur die Beobachtung des Transparenzprotokolls durch Dritte und die Aufteilung des Freigabeschlüssels auf mehrere Personen. |

Rechnung zur Ausrollungsstaffelung: Bei 32 Knoten, einem Beobachtungsfenster von 30 min nach dem ersten Knoten und anschließend Gruppen von je 4 Knoten mit je 10 min Fenster ergibt sich 30 min + ceil((32 − 1) / 4) × 10 min = 30 + 8 × 10 = 110 min für die vollständige Ausrollung. In dieser Zeit ist ein bösartiges Abbild auf höchstens 1 Knoten aktiv, bevor das erste Gesundheitssignal ausbleiben kann. Deutung: Die Staffelung begrenzt einen fehlerhaften Rollout, aber nicht ein bösartiges Abbild, das absichtlich ein gesundes Signal meldet. Das ist ein Modell, keine Messung.

### T-07 Sicherungsdaten

| Feld | Inhalt |
|---|---|
| Angriffspfad | Der Angreifer greift nicht das laufende System an, sondern die Kopie: Zugriff auf das Auslagerungsziel, auf einen entnommenen Datenträger oder auf ein versiegeltes Wiederherstellungspaket. |
| Voraussetzungen | Lesezugriff auf das Auslagerungsziel oder physischer Zugriff auf Datenträger oder Paket. |
| Wirkung bei Erfolg | Vollständiger Lesezugriff auf Nutzdaten und Sollzustand eines Mandanten, sofern der zugehörige Schlüssel erreichbar ist. Ohne Schlüssel bleibt der Angreifer bei Metadaten. |
| Erkennung | Zugriffe am Auslagerungsziel sind für Atrium nicht beobachtbar; das ist eine benannte Lücke. Erkennbar sind ausgebliebene oder fehlgeschlagene Auslagerungen, nicht fremde Lesezugriffe. |
| Gegenmaßnahme | Verschlüsselung vor der Übertragung mit einem Schlüssel je Mandant und Ziel, der das Ziel nie erreicht; Signatur und Prüfsumme je Wiederherstellungspunkt; Wurzelschlüssel niemals Teil einer Sicherung (INV-22); Geheimnisse im Sollzustandsexport nur als Referenz (INV-20); getrenntes, gegen die Plattformintegrität versiegeltes Wiederherstellungspaket für den Katastrophenfall ([Kapitel 17](17-speicher-backup.md)). |
| Restrisiko | Wer Paket und zugehörigen Entsiegelungsweg gleichzeitig besitzt, besitzt die Installation. Die Aufbewahrung des Pakets liegt beim Betreiber und ist technisch nicht erzwingbar. Löschung am Auslagerungsziel ist nicht verhinderbar und wird nur durch ein zweites Ziel abgefedert. |

### T-08 Notfallzugang

| Feld | Inhalt |
|---|---|
| Angriffspfad | Auslösung des Notzugangs durch einen Unbefugten an der physischen oder der Fernkonsole eines Knotens, oder Erlangen des Wiederherstellungscodes aus seiner Aufbewahrung. |
| Voraussetzungen | Physischer oder anbieterseitiger Konsolenzugang und Besitz des Wiederherstellungscodes beziehungsweise der erforderlichen Anteile. |
| Wirkung bei Erfolg | Zeitlich begrenzte Sitzung mit den höchsten Rechten, einschließlich erzwungener Neukonstituierung der Kontrollebene ohne Quorum. |
| Erkennung | Nicht unterdrückbares Auditereignis, auch ohne Quorum (INV-23); Zustellung an jedes hinterlegte Alarmziel; dauerhaftes Banner im Überblick bis zur abgeschlossenen Nachbereitung; Markierung jedes in der Sitzung erzeugten Vorgangs. |
| Gegenmaßnahme | Physische Bindung statt Fernweg; kein Herstellerzugang; erzwungene Wartezeit vor der Neukonstituierung; Pflichtwechsel des Codes nach jeder Auslösung; Vier-Augen-Pflicht für weitere Auslösungen nach überschrittener Nachbereitungsfrist ([Kapitel 10](10-identitaet.md)). |
| Restrisiko | Auf gemieteten Servern ist "physische Konsole" faktisch "Anbieterkonsole", womit A-8 den Notzugang erreicht. Die Konsole kennzeichnet solche Knoten; verhindern lässt sich der Weg nicht, ohne den Notzugang selbst unbrauchbar zu machen. Ein Betreiber, der den Code unsicher verwahrt, hebt die Maßnahme auf. |

## 20.5 Verbindliche Entwicklungsregeln

Jede Regel ist im Bau prüfbar. Eine Regel ohne automatisierte Prüfung ist eine Absichtserklärung und zählt nicht.

| Nr. | Regel | Begründung | Prüfmethode im Bau |
|---|---|---|---|
| **E-01** | Jede Komponente, die Daten aus dem Netz oder aus einem Fremdsystem entgegennimmt und strukturell auswertet, ist in einer speichersicheren Sprache geschrieben. Für Eigenentwicklung ist das Rust. | Speicherfehler an einer unauthentisiert erreichbaren Grenze sind die Fehlerklasse mit der höchsten Ausnutzungswahrscheinlichkeit. Die Sprachwahl entfernt sie, sie mildert sie nicht. | Bauregel: Übersetzungseinheiten außerhalb der Positivliste speichersicherer Ziele brechen den Bau. Fremdkomponenten mit unsicherer Sprache stehen namentlich in einer Ausnahmeliste mit Begründung und Sandkastenprofil. |
| **E-02** | `unsafe`-Blöcke sind je Vorkommen begründet, auf ein Modul begrenzt und von einer zweiten Person freigegeben. Zielwert: 0 Vorkommen außerhalb der Anbindung an Systemschnittstellen. | Speichersicherheit als Zusage endet an der ersten unbegründeten Ausnahme. | Zählung im Bau gegen eine Obergrenze; jede Fundstelle verlangt eine Begründungsmarkierung, sonst bricht der Bau. |
| **E-03** | Befehle und Abfragen entstehen nie durch Zeichenkettenverkettung. Prozessaufrufe erfolgen ausschließlich mit Argumentvektor, nie über eine Shell. | Jede Verkettung mischt Daten und Steueranweisung; genau diese Mischung ist die Einschleusungsklasse. Ein Argumentvektor kennt keine Wortzerlegung und keine Ersetzung. | Musterprüfung im Bau auf Shell-startende Aufrufformen; ein Treffer bricht den Bau. Die Konnektoren und atrium-node rufen ausschließlich über eine gemeinsame, geprüfte Aufrufhilfe. |
| **E-04** | Datenbankabfragen sind parametrisiert. Bezeichner, die nicht parametrisierbar sind, stammen aus einer geschlossenen Aufzählung, nie aus Eingaben. | Parametrisierung trennt Struktur und Wert auf Protokollebene und ist die einzige Maßnahme, die nicht von der Vollständigkeit einer Maskierungsfunktion abhängt. | Statische Prüfung: dynamisch zusammengesetzte Abfragetexte sind in der Datenzugriffsschicht syntaktisch nicht erzeugbar; zusätzlich Bauregel gegen Zeichenkettenformatierung in Abfragepfaden. |
| **E-05** | Jede Eingabe wird am Rand gegen ein Schema validiert. Unbekannte Felder führen zur Ablehnung, nicht zum Überlesen. Das gilt für API-Anfragen, Konnektorantworten, Katalogeinträge, Konnektormanifeste und importierte Sollzustandsexporte. | Überlesene Felder erzeugen stille Bedeutungsunterschiede zwischen Versionen und sind der übliche Weg, Prüfungen zu umgehen. Ablehnung macht Schemaabweichung sichtbar statt wirksam. | Vertragstests mit Zusatzfeldern in jeder Nachrichtenart; eine akzeptierte Nachricht mit unbekanntem Feld bricht den Bau. |
| **E-06** | Autorisierung findet an genau einer Stelle statt, ist standardmäßig verweigernd und benötigt für jede Operation eine ausdrückliche Zulassung. Jede Abfrage trägt ein Mandantenprädikat. | Verteilte Prüfungen divergieren; eine vergessene Prüfung ist bei Default-Allow eine offene Tür und bei Default-Deny ein sichtbarer Fehler. | Vollständigkeitstest: jede API-Operation ohne Eintrag in der Berechtigungstabelle wird abgelehnt, und der Bau bricht bei einer Operation ohne Eintrag. Datenzugriffsschicht lehnt Abfragen ohne Mandantenprädikat ab (INV-19). |
| **E-07** | Rechte werden als Fähigkeitstoken übergeben, die Subjekt, Objekt, Operation, Gültigkeitsdauer und Vorgangsbezug nennen. Kein Prozess besitzt Rechte allein deshalb, weil er läuft. | Implizite Umgebungsrechte sind nicht widerrufbar und nicht prüfbar. Ein Token ist beides und benennt zugleich, wofür es ausgestellt wurde. | Prüfung der Konnektoraufrufe: ein Aufruf ohne gültiges, auftragsgebundenes Token wird abgelehnt; Vertragstest mit abgelaufenem und mit fremdem Token. |
| **E-08** | Geheimnisse und Nachweise werden laufzeitkonstant verglichen. Das betrifft Kopplungscodes, Prüfsummen, Signaturvergleiche, Token und den Wiederherstellungscode. | Ein früh abbrechender Vergleich gibt über die Antwortzeit Zeichen für Zeichen Auskunft; die Messbarkeit hängt nicht vom Netzabstand ab, sondern von der Wiederholungszahl. | Musterprüfung gegen direkte Vergleichsoperatoren in Geheimnispfaden; Vergleich ausschließlich über eine zentrale Funktion, deren Aufrufstellen geprüft werden. |
| **E-09** | Geheimnisse erscheinen nicht in Protokollen, Fehlermeldungen, Wirkungsvorschauen, Sollzustandsexporten oder Wiederherstellungspunkten. Geheimnistypen tragen eine Markierung, die ihre Serialisierung und ihre Darstellung unterbindet. | Die häufigste Offenlegung entsteht nicht durch Angriff, sondern durch Diagnoseausgabe. Eine Typmarkierung wirkt an jeder Ausgabestelle gleichzeitig. | Ausgabeprüfung gegen Geheimnismuster in Protokollen und Exporten (INV-20); zusätzlich Typprüfung, die die Darstellung eines Geheimnistyps im Klartext zu einem Übersetzungsfehler macht. |
| **E-10** | Keine dynamische Codeausführung. Kein Nachladen von Code zur Laufzeit, keine Auswertung von Ausdrücken aus Daten, keine Vorlagensprache mit Nebenwirkungen. Konnektormanifeste sind Daten und beschreiben Abbildungen, keine Programme. | Ein Manifest, das Code ausführt, verlegt die Vertrauensgrenze aus dem Sandkasten in den Manifestinhalt und macht jede Signaturprüfung zur Alleinmaßnahme. | Bauregel gegen dynamische Ladefunktionen; `MemoryDenyWriteExecute` in jeder erzeugten Einheit; Manifestauswertung ohne Ausführungskonstrukte, geprüft durch einen Ablehnungstest gegen ein Manifest mit Ausdruckssyntax. |
| **E-11** | Jeder Eingang hat eine Zeitüberschreitung und eine Größenbegrenzung: Nachrichtengröße, Feldlänge, Verschachtelungstiefe, Elementanzahl, Gesamtdauer eines Aufrufs und Gesamtzahl gleichzeitiger Aufrufe. Werte stehen in der Schnittstellenbeschreibung, nicht im Quelltext. | Ohne obere Schranke wird jede Schnittstelle zum Erschöpfungsvektor, und ohne Tiefenbegrenzung ist jede rekursive Auswertung ein Angriff auf den Kellerspeicher. | Grenzwerttests je Schnittstelle mit Nachrichten an, auf und über der Grenze; eine angenommene Nachricht oberhalb der Grenze bricht den Bau. |
| **E-12** | Jede neue Abhängigkeit verlangt eine schriftliche Begründung, eine Angabe der ersetzten Eigenentwicklung und eine Bewertung ihrer transitiven Hülle. Die Zahl direkter Abhängigkeiten je Kernkomponente ist budgetiert. | Die Angriffsfläche der Lieferkette wächst mit der transitiven Hülle, nicht mit der Zahl direkter Einträge. Ein Budget zwingt zur Abwägung, statt sie dem Zufall zu überlassen. | Bauregel: Überschreitung des Budgets oder ein Eintrag ohne Begründungsdatei bricht den Bau. Zusätzlich täglicher Abgleich der transitiven Hülle gegen Schwachstellenmeldungen (20.10). |
| **E-13** | Abhängigkeitsversionen sind festgelegt und über eine Prüfsumme gebunden. Der Bau hat keinen Netzzugang; alle Quellen stammen aus einem vorgehaltenen Spiegel. | Ohne Festlegung ist der Bau nicht reproduzierbar, und ohne Prüfsumme ersetzt ein manipulierter Spiegel jede Festlegung. | Bau in einer Umgebung ohne Netz; ein Netzzugriffsversuch während des Baus bricht den Bau. Zweitbau auf einem unabhängigen Knoten muss bitgleiche Artefakte liefern. |
| **E-14** | Fehler nach außen tragen einen stabilen Fehlercode, eine handlungsbezogene Klartextmeldung und die Vorgangskennung, sonst nichts. Die vollständige Ursache mit Ausnahmeverlauf, Parametern und Zielsystemantwort steht im Auditstrom beziehungsweise im Betriebsprotokoll. | Eine Fehlermeldung ist eine Auskunft über den inneren Zustand. Sie muss dem Bediener handlungsfähig machen, ohne einem Angreifer den inneren Zustand zu beschreiben. | Musterprüfung aller Meldungstexte auf Pfade, Abfragetexte, Adressen und Ausnahmebezeichner; zusätzlich Prüfung, dass jede Meldung einen Weg in der Oberfläche nennt (INV-17). |

Zwei Einschränkungen gehören an diese Stelle und nicht in eine Fußnote. Erstens: E-01 gilt für Eigenentwicklung. Der Eingangsproxy ist in C++ geschrieben, der autoritative DNS-Dienst und der rekursive Resolver sind in C, und beide stehen an unauthentisiert erreichbaren Grenzen. Die Sprachzusage wird dort durch Sandkastenprofile, Systemaufruffilter und eine kurze Aktualisierungsfrist für die Außenkante (K-23) ersetzt, nicht erfüllt. Zweitens: E-12 begrenzt die Zahl der Abhängigkeiten, ersetzt aber keine Quelltextprüfung. Modellrechnung dazu: Annahme 150 direkte Abhängigkeiten je Kernkomponente, Aufweitungsfaktor 6 zur transitiven Hülle ergibt 900 Einheiten; Annahme 0,05 sicherheitsrelevante Meldungen je Einheit und Jahr ergibt 45 Meldungen jährlich; bei 0,1 Personentagen Sichtung je Meldung sind das 4,5 Personentage jährlich allein für die Sichtung. Das ist tragbar; eine Quelltextprüfung von 900 Einheiten ist es nicht. Die Konsequenz wird ausgesprochen: Atrium prüft Meldungen, nicht Quelltexte Dritter.

### Fehlerhülle und Geheimnisvergleich

```
# Ausgabe nach außen (API, Konsole, Konnektorfehler)
struct FehlerAussen {
    code:            String,   # stabil, dokumentiert, z. B. "veroeffentlichung.name_belegt"
    meldung:         String,   # handlungsbezogen, ohne Pfade, ohne Fremdtexte
    vorgang:         Ulid,     # Korrelationskennung zum Nachschlagen
    naechster_schritt: String  # benennt einen Weg in der Oberfläche (INV-17)
}

# Ausgabe nach innen (Auditstrom bzw. Betriebsprotokoll)
struct FehlerInnen {
    code, vorgang, akteur, mandant, objekt,
    ursache_kette:   Vec<String>,   # vollständig
    zielsystem_antwort: Option<Bytes>,  # roh, geheimnisgefiltert
    zeitpunkt, knoten, komponente
}

# Regel: FehlerInnen wird nie serialisiert an eine Außenschnittstelle uebergeben.
# Pruefung: Typsystem trennt beide; FehlerInnen implementiert keine Aussendarstellung.

fn geheimnis_gleich(a: &Geheim, b: &Geheim) -> bool {
    # laufzeitkonstant: keine fruehe Rueckkehr, keine Verzweigung ueber Inhalt
    if a.len() != b.len() { return false }   # Laenge ist nicht geheim
    let mut diff = 0u8
    for i in 0..a.len() { diff |= a[i] ^ b[i] }
    diff == 0
}
```

## 20.6 Lieferkette

| Baustein | Festlegung | Wirkung gegen A-7 |
|---|---|---|
| Reproduzierbarer Bau | Bau ohne Netzzugang aus festgelegten Ubuntu-LTS-Paketen und einem vorgehaltenen Quellenspiegel; feste Zeitstempel, feste Pfade, feste Sortierung; Zweitbau auf unabhängiger Infrastruktur muss bitgleiche Artefakte liefern | Ein eingeschleuster Unterschied zwischen Quelltext und Artefakt wird durch den Zweitbau sichtbar |
| Stücklisten | Je Abbild, je Katalogeintrag und je Konnektormanifest eine Stückliste sowohl als SPDX als auch als CycloneDX, erzeugt aus dem Bau und nicht nachträglich abgeleitet | Grundlage jedes Abgleichs gegen Schwachstellenmeldungen (20.10) |
| Signatur | Ed25519 (RFC 8032) über die kanonische Serialisierung nach RFC 8785; signiert werden Abbild, Stückliste, Herkunftsnachweis und Freigabemetadaten gemeinsam | Ein nachträglicher Austausch einzelner Bestandteile schlägt fehl |
| Transparenzprotokoll | Anhängbares, hashverkettetes Protokoll jeder Freigabe mit Zeitstempel nach RFC 3161; der Eintrag enthält Version, Artefakthashwerte und Stücklistenhashwert | Gezielte Einzelauslieferung und Rückdatierung werden erkennbar |
| Herkunftsnachweis | Signierte Aussage über Quellstand, Bauumgebung, Bauzeit, Werkzeugversionen und Eingangsartefakte | Verknüpft ein Artefakt mit einem prüfbaren Bauvorgang |
| Festlegung der Abhängigkeitsversionen | Vollständige Festschreibung mit Prüfsummen, gespiegelte Quellen, keine Auflösung zur Bauzeit | Ein manipulierter Spiegel oder eine nachträglich veränderte Veröffentlichung wirkt nicht |
| Prüfung beim Einspielen | Sieben Schritte in fester Reihenfolge, siehe unten | Kein ungeprüftes Artefakt erreicht die inaktive Abbildhälfte |
| Verhalten bei Fehlschlag | Abbruch ohne Übersteuerungsmöglichkeit; der Knoten bleibt auf der aktiven Hälfte; Störungsmeldung mit Fehlercode und Vorgangskennung | Eine Bestätigung des Bedieners kann eine fehlgeschlagene Prüfung nicht übergehen |

```
# Prüfung beim Einspielen, Reihenfolge verbindlich, Abbruch bei erstem Fehlschlag.
1  Signatur des Freigabepakets gegen den im aktiven Abbild verankerten Freigabeschluessel
   pruefen (Ed25519, laufzeitkonstant, RFC 8032).
2  Kanonische Serialisierung nachrechnen (RFC 8785) und gegen den signierten Hashwert
   vergleichen.
3  Transparenzprotokolleintrag abrufen, Kettenposition und Zeitstempel (RFC 3161) pruefen;
   fehlender oder widerspruechlicher Eintrag => Abbruch.
4  Versionsfolge pruefen: Zielversion > aktive Version, oder ausdruecklich als Rueckfall
   markiert; sonst Abbruch (Schutz gegen Rueckspielen).
5  Schema- und Laufzeitaenderung getrennt pruefen: enthaelt das Paket beides, => Abbruch
   (INV-24).
6  Stuecklisten (SPDX und CycloneDX) auf Vorhandensein, Signatur und Hashwertbindung an
   das Abbild pruefen.
7  Abbild in die inaktive Haelfte schreiben, dm-verity-Wurzelhashwert nachrechnen und
   gegen den signierten Wert vergleichen.

Bei Abbruch in einem beliebigen Schritt:
  - kein Schreibvorgang in die inaktive Haelfte bleibt bestehen,
  - Auditereignis "abbild.abgelehnt" mit Schrittnummer und Fehlercode,
  - Stoerungsmeldung im Ueberblick mit Verweis auf den Vorgang,
  - keine Bedienhandlung setzt die Pruefung ausser Kraft.
```

Die Abwesenheit einer Übersteuerung hat einen Preis, der hier benannt wird: Geht der Freigabeschlüssel des Lieferanten verloren oder wird er kompromittiert, kann kein neues Abbild mehr eingespielt werden, weil der Nachfolgeschlüssel selbst nur über ein signiertes Abbild verteilt würde. Der Entwurf löst das über zwei im aktiven Abbild verankerte Freigabeschlüssel unterschiedlicher Herkunft, von denen einer genügt, und über einen Neuaufbau vom Installationsmedium als letzten Weg. Der Neuaufbau ist keine Aktualisierung, sondern eine Neuinstallation mit anschließendem Import des Sollzustandsexports; er kostet die RTO aus K-11. Ein bequemerer Weg existiert nicht, ohne die Zusage aufzugeben.

## 20.7 Geheimnisverwaltung

```
                TPM 2.0 des Knotens (nicht exportierbar)
                        |
                        | versiegelt gegen signierte Richtlinienaussage
                        v
             Knotenversiegelungsschluessel  (je Knoten, nie repliziert)
                        |
            +-----------+-----------+
            |                       |
   Knotenhauptschluessel     Schluessel der Ausgabe-CA
   (lokaler Geheimnisspeicher)   (nur auf Verwaltungsknoten)
            |
            | HKDF (RFC 5869), Kontext = Mandantenkennung
            v
   Mandantenhauptschluessel  (je Mandant, repliziert nur verschluesselt)
            |
            | HKDF, Kontext = Zweck + Objektkennung
            +-------------------+-------------------+------------------+
            v                   v                   v                  v
   Speicherbereichs-    Auslagerungs-        DKIM-Signier-      Konnektor-
   schluessel           schluessel je Ziel   schluessel         geheimnisschluessel
   (ZFS-Verschluesselung) (restic)           (je Maildomaene)   (umhuellt Zugangsdaten)
```

| Eigenschaft | Festlegung | Begründung |
|---|---|---|
| Versiegelung | Der Knotenversiegelungsschlüssel ist im TPM gegen eine mit dem Freigabeschlüssel signierte Richtlinienaussage versiegelt, nicht gegen feste Registerwerte | Eine Bindung an feste Werte macht den Schlüssel nach jeder Abbildaktualisierung unbrauchbar, weil das A/B-Verfahren die Messwerte ändert ([Kapitel 11](11-pki.md)) |
| Mandantenbezug | Je Mandant ein eigener Hauptschlüssel, aus dem alle Zweckschlüssel abgeleitet werden; kein Zweckschlüssel wird zwischen Mandanten geteilt | Die Isolationsstufe M0 verspricht bereits einen eigenen Hauptschlüssel; ohne ihn wäre jede höhere Stufe eine reine Netzmaßnahme |
| Ableitung | HKDF (RFC 5869) mit Kontext aus Zweck und Objektkennung; identische Eingaben erzeugen denselben Schlüssel, unterschiedliche Zwecke nie | Ableitung statt Speicherung reduziert die Zahl der zu sichernden Geheimnisse auf die Hauptschlüssel |
| Zugriff | Prozesse erhalten keine Geheimnisse, sondern kurzlebige, auftragsgebundene Referenzen mit Gültigkeitsdauer, Vorgangsbezug und Einmaleinlösung | Eine Referenz ist widerrufbar und im Audit nachvollziehbar; ein Geheimniswert ist beides nicht (INV-20) |
| Sichtbarkeit | Geheimnisse sind schreibbar, nie lesbar; die Konsole zeigt Verweisname, Typ, Wechselfrist und letzte Verwendung | Der Bediener braucht die Verwaltbarkeit, nicht den Wert |
| Kein Austritt | Kein privater Schlüssel verlässt den Knoten, auf dem er erzeugt wurde | Sonst ist die Zahl der Orte, an denen er kompromittiert werden kann, nicht mehr bestimmbar |

| Schlüssel | Lebensdauer (Zielwert) | Auslöser eines Wechsels | Verfahren |
|---|---|---|---|
| Wurzel-CA | 15 a | Planmäßiger Nachfolger, Verdacht auf Kompromittierung | Offline-Zeremonie; Nachfolger wird parallel verteilt, bevor der Vorgänger nur noch prüfend ist |
| Ausgabe-CA | 5 a, Nachfolger ab 2 a Restlaufzeit | Zeitablauf, Knotentausch, Verdacht | Neuerzeugung im TPM des Nachfolgeknotens, Signatur durch die Wurzel |
| Mandanten-Zwischen-CA | folgt der Ausgabe-CA | Zeitablauf, Mandantenauflösung, Verdacht | Neuausstellung; alte Zertifikate laufen im Erneuerungsfenster aus |
| Knotenhauptschlüssel | Lebensdauer des Knotens | Entkopplung, Abbildwechsel mit geänderter Richtlinie, Verdacht | Neuversiegelung; alle abgeleiteten Schlüssel werden neu umhüllt |
| Mandantenhauptschlüssel | 2 a | Zeitablauf, Isolationsstufenwechsel, Verdacht | Neuableitung mit doppelter Umhüllung während der Umstellung; Speicherbereiche werden ohne Unterbrechung umgeschlüsselt |
| Konnektorgeheimnis | 180 d | Zeitablauf, Fremdsystemwechsel, Verdacht | Sichtbare Aufgabe in der Konsole; der Wechsel ist ein Vorgang mit Wirkungsvorschau |
| DKIM-Schlüssel | 180 d | Zeitablauf | Überlappender Wechsel mit zwei gültigen Auswahlkennungen im DNS ([Kapitel 14](14-mail.md)) |
| WireGuard-Schlüssel | folgt dem Knotenzertifikat | Zertifikatserneuerung, Entkopplung | Gemeinsamer Wechsel, damit keine zweite Rotationslogik entsteht |
| Auslagerungsschlüssel | 2 a, an den Mandantenhauptschlüssel gebunden | Zielwechsel, Verdacht | Alte Stände bleiben mit dem alten Schlüssel lesbar, bis ihre Aufbewahrungsfrist endet |

**Verlust der Versiegelung.** Der TPM-Zustand geht bei Mainboardtausch, TPM-Löschung, Firmwarewechsel mit geänderter Messkette oder Wechsel der Freigabeschlüsselverankerung verloren. Der Knoten erkennt das beim Start daran, dass die Entsiegelung fehlschlägt. Er startet dann nicht in den Produktivbetrieb, sondern meldet den Zustand "Versiegelung nicht lösbar", hält seine Dienste an und fordert die Wiederaufnahme an. Der Weg zurück ist die Neuaufnahme des Knotens: Kopplung, neues Knotenzertifikat, Neuversiegelung, Abgleich durch den Reconciler. Ist der Knoten der letzte Verwaltungsknoten, greift das versiegelte Wiederherstellungspaket aus [Kapitel 17](17-speicher-backup.md); dessen Entsiegelung hängt ausdrücklich nicht am TPM eines einzelnen Knotens, weil sonst der Katastrophenfall den einzigen Weg aus dem Katastrophenfall verschlösse. Die Schwäche dieser Konstruktion wird benannt: Das Paket ist damit ein Vertraulichkeitsgut der Klasse "sehr hoch", das außerhalb der technischen Schutzmaßnahmen des Systems aufbewahrt wird.

## 20.8 Härtung

| Komponente | Benutzer und Rechte | Dateisystem | Netz | Systemaufruffilter | Begründung der Abweichung vom strengsten Profil |
|---|---|---|---|---|---|
| **atrium-core** | eigener Systembenutzer, `NoNewPrivileges`, leere Capability-Menge, `LockPersonality`, `MemoryDenyWriteExecute` | `ProtectSystem=strict`, `ProtectHome=yes`, `PrivateTmp`, `ProtectProc=invisible`, schreibbar nur das eigene ZFS-Dataset und der Auditpfad | Ports 8400, 8401, 8404, 8405, 8406, 8407, 8408; `RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX` | Positivliste, Rückfall `KILL_PROCESS`; ausgeschlossen unter anderem `ptrace`, `mount`, `kexec_load`, `bpf`, `perf_event_open` | Braucht Zugriff auf das TPM-Gerät für die Ausgabe-CA; `PrivateDevices` ist deshalb nicht setzbar, stattdessen `DeviceAllow` für genau dieses Gerät |
| **atrium-node** | eigener Systembenutzer, `NoNewPrivileges`; `CAP_NET_ADMIN` nur im Regelanwendungsschritt über einen getrennten, kurzlebigen Hilfsprozess | `ProtectSystem=strict`, schreibbar die erzeugten Unit-Verzeichnisse und der Zustandspfad | nur 8402 ausgehend zum Kern; kein eingehender Port aus dem Außennetz | Positivliste; Netz- und Einhängeaufrufe nur im Hilfsprozess zugelassen | Der Agent muss nftables-Regelsätze tauschen und Dateisysteme einhängen; die Trennung in einen Hilfsprozess hält den Dauerprozess ohne diese Rechte |
| **Eingangsproxy (Envoy)** | eigener Systembenutzer, `NoNewPrivileges`, leere Capability-Menge, Bindung an 80 und 443 über Socketaktivierung durch systemd | `ProtectSystem=strict`, `PrivateTmp`, kein Zugriff auf Schlüsselmaterial im Dateisystem (Zertifikate kommen über SDS in den Speicher) | 80, 443 eingehend; 8404 ausgehend zum xDS-Server | Positivliste; `MemoryDenyWriteExecute` nicht setzbar | Die Laufzeitübersetzung in der Filterkette verlangt beschreibbaren ausführbaren Speicher; als Ausgleich engerer Systemaufruffilter, kürzere Aktualisierungsfrist (K-23) und eigenes Zugriffskontrollprofil |
| **Autoritativer DNS (Knot DNS)** | eigener Systembenutzer, Bindung an 53 über Socketaktivierung | `ProtectSystem=strict`, schreibbar nur Zonen- und Journalpfad | 53/udp, 53/tcp eingehend; Steuerschnittstelle nur über lokalen Socket | Positivliste | In C geschrieben; die Sprachzusage aus E-01 gilt nicht, deshalb strengere Netz- und Dateisystemgrenzen und keine Steuerschnittstelle über das Netz |
| **Rekursiver Resolver (Knot Resolver)** | eigener Systembenutzer, je Netzzone eine Instanz | `ProtectSystem=strict`, schreibbar nur der Zwischenspeicherpfad | 53, 853 eingehend nur aus der zugeordneten Netzzone; ausgehend 53, 853 | Positivliste | Braucht ausgehende Namensauflösung; die Beschränkung auf die eigene Netzzone verhindert die Nutzung als offener Auflöser |
| **Protokollkopf (Identität)** | eigener Systembenutzer, eigene Verwaltungsoberfläche abgeschaltet | `ProtectSystem=strict`, eigener Datenpfad | 636 nur intern; Schreibzugriff ausschließlich durch den Reconciler | Positivliste | Schreibt eigenen Zustand; die Datenhaltung bleibt daher beschreibbar, wird aber nie als Wahrheitsquelle gelesen ([Kapitel 10](10-identitaet.md)) |
| **RADIUS-Dienst** | eigener Systembenutzer, Konfiguration vollständig erzeugt | `ProtectSystem=strict`, nur lesender Zugriff auf Vertrauensanker und Sperrliste | 1812/udp, 1813/udp, 2083/tcp | Positivliste | Nur aktiv, wenn Netzzugang genutzt wird; sonst nicht gestartet |
| **Konnektorprozess** | eigener Systembenutzer je Mandant und Bindung | wie [Kapitel 09](09-konnektoren.md) festgelegt | eigener Netznamensraum, Ausgangs-Positivliste, kein Zugriff auf 8400 (INV-21) | Positivliste, `KILL_PROCESS` | Keine; dies ist das strengste Profil im System |
| **Dienstprozess (Katalogeintrag)** | eigene Benutzerkennung je Dienst, rootless wo das Produkt es zulässt | erzeugte Einheit mit `ProtectSystem=strict`, `PrivateTmp`, `PrivateDevices`, schreibbar nur der eigene Speicherbereich | eigene Netzzone; erreichbar nur über eine Veröffentlichung | Positivliste aus der Erzeugungsvorlage | Ausnahmen sind ausschließlich deklarierte Eigenschaften des Katalogeintrags und in der Konsole am Dienst sichtbar ([Kapitel 15](15-dienste-software.md)) |

| Maßnahme | Festlegung | Wirkung |
|---|---|---|
| Zugriffskontrollprofile | Je erzeugter systemd-Einheit ein AppArmor-Profil im erzwingenden Modus, aus derselben Vorlage erzeugt wie die Einheit; der beobachtende Modus existiert nur im Bau | Ein Profil, das im Betrieb nur beobachtet, ist keine Maßnahme, sondern eine Protokollquelle |
| Systemaufruffilter | Positivliste je Komponentenklasse statt Sperrliste; Rückfall `KILL_PROCESS` statt Fehlercode | Eine Sperrliste ist beim nächsten neuen Systemaufruf unvollständig; ein Fehlercode lässt den Angreifer weiterprobieren |
| Eingeschränkter Kernmodus | Kernmodus "Integrität": kein Laden unsignierter Module, kein `kexec`, kein Zugriff auf `/dev/mem`, kein Schreiben in Kernspeicher über Debugschnittstellen | Entfernt die übliche Verankerungsklasse nach einer Rechteausweitung |
| Nicht benötigte Dienste | Nicht installiert statt nur abgeschaltet: interaktive Anmeldedienste außer dem befristet freischaltbaren Supportzugang, Druck-, Anzeige- und Automatisierungsdienste, Paketverwaltung zur Laufzeit (das Wurzeldateisystem ist unveränderlich) | Ein nicht installierter Dienst hat keine Schwachstelle und keinen Aktualisierungsbedarf; er verkürzt zugleich die Stückliste und damit den Abgleichaufwand aus 20.10 |
| Supportzugang | SSH standardmäßig geschlossen, zertifikatsbasiert, befristet, nur nach auditiertem Vorgang freigeschaltet, Sitzungsende erzwungen | Ein dauerhaft offener Verwaltungszugang widerspricht der Aussage, dass der Normalbetrieb kein Terminal braucht (INV-26) |

## 20.9 Start- und Laufzeitintegrität

| Stufe | Prüfendes Element | Geprüftes Element | Messung |
|---|---|---|---|
| 1 | UEFI mit Secure Boot | Startlader | Firmware, Konfiguration und Startlader werden in die Register der Plattformmessung aufgenommen |
| 2 | Startlader | Kern und Startabbild der aktiven Hälfte | Kern, Befehlszeile und Startabbild werden gemessen |
| 3 | Kern | Wurzeldateisystem der aktiven Hälfte über dm-verity | Der Wurzelhashwert des Abbilds wird gemessen; jede Abweichung eines Blocks führt beim Lesen zum Fehler, nicht erst beim Start |
| 4 | atrium-node | eigene Binärdatei und die erzeugten Einheiten | Messung der Komponentenidentität; die erzeugten Einheiten folgen dem Sollzustand und werden gegen ihn geprüft (INV-02) |
| 5 | TPM-Richtlinie | Gesamtergebnis der Stufen 1 bis 3 | Entsiegelung des Knotenversiegelungsschlüssels gelingt nur, wenn die signierte Richtlinienaussage erfüllt ist |

**Register-Politik.** Die Versiegelung bindet an die Register, die Firmware, Startlader, Kern, Befehlszeile und den dm-verity-Wurzelhashwert tragen, nicht an Register, die Gerätekonfiguration oder Startreihenfolge abbilden. Begründung: Eine Bindung an Gerätekonfiguration macht jeden Hardwaretausch zu einem Entsiegelungsverlust und erzeugt damit Betriebsvorfälle ohne Sicherheitsgewinn. Die Bindung erfolgt nicht an feste Werte, sondern an eine mit dem Freigabeschlüssel signierte Richtlinienaussage; dadurch überlebt die Versiegelung eine A/B-Aktualisierung, während ein nicht freigegebenes Abbild sie nicht erfüllt.

| Abweichung | Erkennung | Auslösung |
|---|---|---|
| Signatur des Startladers oder des Kerns ungültig | UEFI Secure Boot | Der Knoten startet nicht; Rückfall in die andere Abbildhälfte |
| dm-verity-Blockfehler im laufenden Betrieb | Kern beim Lesen | Lesefehler statt manipulierter Daten; der Knoten meldet die Störung und wird geräumt |
| Entsiegelung schlägt fehl | atrium-node beim Start | Zustand "Versiegelung nicht lösbar", keine Dienstaufnahme, keine Zertifikatsausstellung, Meldung an die Kontrollebene |
| Erzeugte Einheit weicht vom Sollzustand ab | Reconciler beim Abgleich | Überschreibung und Auditereignis "Abweichung korrigiert" (INV-02) |
| Uhrenabweichung über der Zielgrenze | chrony und atrium-node | Keine Führung, keine Ausstellung, Selbstabschottung (INV-32) |

**Fernbezeugung und ihre Grenzen.** Ein Knoten kann der Kontrollebene eine signierte Bezeugung seiner Startmessungen vorlegen; die Kontrollebene vergleicht sie gegen die erwartete Abbildversion und zeigt eine Abweichung als Knotenstörung an. Drei Grenzen werden ausgesprochen. Erstens bezeugt die Messung den Zustand zum Startzeitpunkt, nicht den laufenden Zustand; ein nach dem Start übernommener Prozess ändert keine Messung. Zweitens besteht zwischen Bezeugung und Verwendung des Ergebnisses eine Zeitlücke, die ein Angreifer mit Kontrolle über den Knoten nutzen kann; die Bezeugung ist deshalb ein Betriebssignal und keine Zugangsvoraussetzung. Drittens ist eine Bezeugung aus einem virtualisierten TPM eine Aussage des Anbieters der Virtualisierungsumgebung über sich selbst und trägt gegenüber A-8 nichts bei; die Konsole kennzeichnet solche Knoten entsprechend.

## 20.10 Schwachstellenbehandlung

| Bestandteil | Festlegung |
|---|---|
| Meldeweg | Eine dauerhaft veröffentlichte Meldeadresse mit öffentlichem Schlüssel für verschlüsselte Zuschriften, maschinenlesbar an einem festen Pfad der Produktseite hinterlegt; Eingangsbestätigung als Zielwert innerhalb von 2 Arbeitstagen; ein benannter Bearbeitungsverantwortlicher je Meldung. Der konkrete Pfad wird hier nicht erfunden, sondern als Anforderung festgelegt. |
| Bewertungsschema | Zwei Eingangsgrößen: die Bewertung des Herstellers der betroffenen Komponente, sofern vorhanden, und die Erreichbarkeit im Auslieferungszustand von Atrium. Maßgeblich ist die Erreichbarkeit: eine Schwachstelle in einer Bibliothek, die im Auslieferungszustand von keinem aktiven Codepfad erreicht wird, ist höchstens S2. |
| Schweregrade | **S0** aktiv ausgenutzt oder unauthentisiert an der Außenkante ausnutzbar; **S1** unauthentisiert im internen Netz oder mandantenübergreifend ausnutzbar; **S2** nur mit gültigen Rechten oder nur lokal ausnutzbar; **S3** ohne praktischen Angriffspfad im Auslieferungszustand. |
| Fristen (Zielwerte) | S0: Freigabe verfügbar ≤ 72 h nach Bestätigung, deckungsgleich mit K-23 für die Außenkante; S1 ≤ 7 d; S2 ≤ 30 d; S3 mit der nächsten regulären Freigabe. Die Frist läuft ab Bestätigung, nicht ab Meldungseingang; der Zeitpunkt der Bestätigung steht in der Sicherheitsmeldung. |
| Abgleich der Stücklisten | Täglicher automatisierter Abgleich beider Stücklistenformate jedes im Feld befindlichen Abbilds gegen öffentliche Schwachstellenquellen; jeder Treffer erzeugt einen Vorgang mit Pflichtentscheidung "betroffen" oder "nicht betroffen mit Begründung"; eine unbeantwortete Pflichtentscheidung bleibt als offene Aufgabe sichtbar. |
| Veröffentlichungspraxis | Abgestimmte Offenlegung: Veröffentlichung mit der Freigabe, spätestens 90 d nach Bestätigung, auch ohne Behebung. Je Freigabe eine maschinenlesbare Sicherheitsmeldung, die Komponente, Schweregrad, betroffene Versionen, Behebungsversion und Umgehungsmaßnahme nennt. Keine stille Behebung: eine Behebung ohne Meldung gilt als Verstoß gegen diese Praxis. |
| Bezug zur Produktsicherheitsgesetzgebung | Der Cyber Resilience Act (Verordnung (EU) 2024/2847) verpflichtet den Hersteller zur Schwachstellenbehandlung über den Unterstützungszeitraum, zur Bereitstellung einer Stückliste und zur Meldung aktiv ausgenutzter Schwachstellen an die zuständigen Stellen. Der Entwurf richtet die Meldekette darauf aus, eine Erstmeldung binnen 24 h und eine Folgemeldung binnen 72 h nach Bestätigung absetzen zu können. Die genaue Fristen- und Adressatenlage ist am Rechtstext zu prüfen und in [Kapitel 22](22-compliance.md) zu führen; hier steht die organisatorische Auslegung, nicht die Rechtsauskunft. |

Modellrechnung zum Abgleichaufwand: Annahme 900 Einheiten in der transitiven Hülle je Abbild und 0,05 Meldungen je Einheit und Jahr ergibt 45 Treffer jährlich. Annahme: 70 % davon betreffen im Auslieferungszustand nicht erreichbare Codepfade, also 31,5 Vorgänge mit Begründung "nicht betroffen" zu je 0,1 Personentagen = 3,15 Personentage, und 13,5 Vorgänge mit Behebung zu je 0,5 Personentagen = 6,75 Personentage. Summe 9,9 Personentage jährlich. Deutung: Der Abgleich ist tragbar, solange die Stückliste klein bleibt; jede Vergrößerung der transitiven Hülle wirkt direkt und linear auf diese Zahl, was das Abhängigkeitsbudget aus E-12 begründet. Das ist ein Modell, keine Messung.

## 20.11 Reaktion auf Vorfälle

| Erkennungssignal | Quelle | Schwelle (Zielwert) | Erste Reaktion |
|---|---|---|---|
| Zertifikatsausstellung ohne zugehörigen Sollzustandseintrag | Ausstellungsabgleich in atrium-core | 1 Ereignis | Sperrung des Zertifikats, Störungsmeldung, Prüfung der CA-Nutzung |
| Fehlgeschlagene Entsiegelung an einem Knoten | atrium-node beim Start | 1 Ereignis | Knoten bleibt außer Betrieb; Prüfung auf Hardwaretausch gegen Manipulation |
| Abbruch durch den Systemaufruffilter | systemd-Journal des betroffenen Dienstes | 1 Ereignis je Konnektor, 3 je Dienstprozess in 24 h | Aussetzen der Konnektorbindung; Abbild- und Manifestprüfung |
| Ausgehende Verbindungsversuche außerhalb der Positivliste | nftables-Zähler je Netznamensraum | 1 Ereignis | Aussetzen der Bindung; Prüfung des Fremdsystems |
| Bruch der Auditkette | Kettenprüfung bei Start und Export (INV-23) | 1 Ereignis | Sicherung des Strommaterials; Vorfall gilt bis zur Klärung als bestätigt |
| Parallele Sitzungen eines Kontos aus verschiedenen Netzzonen | Sitzungsverwaltung | 1 Ereignis bei Plattformrollen | Beendigung aller Sitzungen des Kontos, erneute Anmeldung mit Authentisierer |
| Notzugangsauslösung | Notzugangspfad | 1 Ereignis | Alarmierung aller hinterlegten Ziele, Banner, Nachbereitungspflicht |
| Abgelehntes Abbild beim Einspielen | Aktualisierungspfad | 1 Ereignis | Kein Rollout; Prüfung von Bezugsweg und Transparenzprotokoll |

| Phase | Handlungen | Randbedingung |
|---|---|---|
| Eindämmung | Gestufte, jeweils vorhandene Vorgänge: Konnektorbindung aussetzen, Veröffentlichung zurückziehen, Dienst anhalten, Knoten räumen, Knoten entkoppeln mit Zertifikatssperrung, Mandant auf lesend sperren, Sollzustand einfrieren | Kein Sonderweg: jede Eindämmung ist eine Änderung am Sollzustand und erzeugt einen Vorgang (INV-03). Ein Notfallpfad am Reconciler vorbei existiert nicht, weil er dauerhaft als Angriffsweg bestünde |
| Beweissicherung | Reihenfolge nach Flüchtigkeit: laufende Sitzungen und Prozessliste, Netzverbindungen und Zählerstände, Journalauszug, Auditstromexport mit Kettennachweis und Zeitstempel nach RFC 3161, Momentaufnahme der betroffenen Speicherbereiche, Abbildversion und Messwerte | Der Auditstrom ist auch ohne Quorum schreib- und exportierbar; die Momentaufnahme ist vor der Bereinigung zu erzeugen, weil ein Wiederanlauf Spuren überschreibt |
| Wiederanlauf | Bedingungen, die alle erfüllt sein müssen: Ursache benannt, Behebung eingespielt und geprüft, betroffenes Schlüsselmaterial gewechselt, Wiederherstellungspunkt geprüft, Abweichungsliste leer, Nachweis der Kettenintegrität erbracht | Ein Wiederanlauf ohne benannte Ursache ist ein Wiederanlauf mit unbekannter Restkompromittierung und wird in der Konsole als solcher gekennzeichnet |
| Meldepflichten | Bei personenbezogenen Daten: Meldung an die Aufsichtsbehörde binnen 72 h ab Kenntnis nach der DSGVO (Verordnung (EU) 2016/679), Benachrichtigung Betroffener bei hohem Risiko. Bei Einrichtungen im Anwendungsbereich der NIS2-Richtlinie (Richtlinie (EU) 2022/2555): gestufte Meldung an die zuständige Stelle. Bei aktiv ausgenutzten Schwachstellen im Produkt: Herstellerpflichten nach dem Cyber Resilience Act | Atrium liefert die Nachweisgrundlage (Auditexport, Betroffenheitsabgrenzung je Mandant), nicht die rechtliche Bewertung. Die Zuordnung der Pflichten steht in [Kapitel 22](22-compliance.md) |

Die Betroffenheitsabgrenzung ist der Grund, weshalb INV-19 kein Ordnungsprinzip, sondern eine Sicherheitsmaßnahme ist: Ohne Mandantenprädikat in jeder Abfrage lässt sich nach einem Vorfall nicht belegen, welche Mandanten betroffen waren, und die Meldung muss vorsorglich alle umfassen.

## 20.12 Kryptoinventar und Migration

| Zweck | Verfahren | Schlüssellänge bzw. Parameter | Lebensdauer | Austauschbarkeit |
|---|---|---|---|---|
| Transportverschlüsselung innen und außen | TLS 1.3 (RFC 8446) | X25519 (RFC 7748) für den Schlüsselaustausch; ChaCha20-Poly1305 (RFC 8439) und AES-GCM (FIPS 197) als Verkehrsverfahren | Sitzungsdauer | hoch: Verfahrenskennung je Verwendungszweck; hybrider Austausch ist ohne Formatänderung ergänzbar |
| Knoten-, Geräte-, Dienst- und Personenzertifikate | Ed25519 (RFC 8032) in X.509 nach RFC 5280 | 256 bit | Dienst 90 d, Gerät 365 d (K-13) | hoch: kurze Laufzeiten lösen einen Verfahrenswechsel im Erneuerungsfenster auf |
| Ausgabe-CA und Mandanten-Zwischen-CA | Ed25519 | 256 bit | 5 a bzw. daran gebunden | mittel: Wechsel verlangt Nachfolgerverteilung vor Ablösung |
| Wurzel-CA | Ed25519, Zielbild zusätzlich ML-DSA (FIPS 204) oder SLH-DSA (FIPS 205) | 256 bit | 15 a | niedrig: der Anker liegt auf jedem verwalteten Gerät; ein Wechsel ist ein mehrjähriger Vorgang |
| Signatur der Lieferkette | Ed25519 über kanonische Serialisierung nach RFC 8785 | 256 bit | Schlüssel 3 a | mittel: Doppelsignatur ist additiv möglich, verlangt aber Prüferunterstützung im Feld |
| Auditkette und Abbildhashwerte | SHA-2 (FIPS 180-4) | 256 bit | dauerhaft gültig für erzeugte Ketten | mittel: ein Wechsel erzeugt einen Kettenbruch, der als Übergang markiert werden muss |
| Kennwortableitung | Argon2id (RFC 9106) | Parameter als Richtlinie, mindestens der jeweils empfohlene Satz | bis zur nächsten Anmeldung | hoch: Parameter sind je Eintrag gespeichert und beim Anmelden nachziehbar |
| Schlüsselableitung | HKDF (RFC 5869) mit SHA-2 | 256 bit | folgt dem abgeleiteten Zweck | hoch |
| Kopplungsvorgang | SPAKE2 (RFC 9382) | 50 bit Codeentropie, an TLS-Exporter gebunden | 15 min | mittel: ein Verfahrenswechsel betrifft beide Seiten gleichzeitig und damit die Aufnahme älterer Knoten |
| DNSSEC-Signierung | Verfahren nach RFC 4034 mit automatischem Schlüsselwechsel | je Zone, Rollierung automatisch | Signaturen kurzlebig, Schlüssel rollierend | mittel: an die Validierungsfähigkeit der Auflöser im Internet gebunden |
| DKIM (RFC 6376) | Signaturverfahren mit zwei parallelen Auswahlkennungen | 180 d | 180 d | hoch: überlappender Wechsel ist Teil des Regelbetriebs |
| Speicherbereichsverschlüsselung | AES-GCM über ZFS-Verschlüsselung | 256 bit | an den Mandantenhauptschlüssel gebunden, 2 a | niedrig bis mittel: ein Verfahrenswechsel erzwingt Umschlüsselung des Bestands |
| Ausgelagerte Sicherungen | Verfahren des Auslagerungswerkzeugs mit Schlüssel je Mandant und Ziel | 256 bit | 2 a | niedrig: alte Stände bleiben bis zum Ende ihrer Aufbewahrungsfrist im alten Verfahren |
| Overlay-Netz | WireGuard | 256 bit, an das Knotenzertifikat gebunden | folgt dem Zertifikat | niedrig: das Protokoll kennt keine Verfahrensaushandlung |
| Versiegelung | TPM 2.0 mit signierter Richtlinienaussage | Verfahren der Plattform | Knotenlebensdauer | niedrig: an die Fähigkeiten des verbauten TPM gebunden |

**Migrationspfad zu quantencomputerresistenten Verfahren.** Die Reihenfolge folgt der Nutzenrechnung und nicht der Verfügbarkeit der Verfahren. Maßgeblich ist, welches Schutzgut heute aufgezeichnet und später entschlüsselt werden kann und welches Schlüsselmaterial die längste Restlaufzeit hat.

| Reihenfolge | Schritt | Begründung |
|---|---|---|
| 1 | Verfahrenskennung an jeder Signatur- und Austauschstelle, ohne Verfahrenswechsel | Ohne Kennung ist jeder spätere Wechsel eine Neuentwicklung statt einer Migration; dieser Schritt ist Voraussetzung aller weiteren und kostet keine Kompatibilität |
| 2 | Hybrider Schlüsselaustausch X25519 + ML-KEM (FIPS 203) an den Außenkanten: Eingang, Mailübergabe, Protokollausleitung, Auslagerung | Vertraulichkeit ist das einzige Gut, das rückwirkend verloren geht. Aufgezeichneter Verkehr von heute ist morgen entschlüsselbar; Signaturen von heute sind morgen wertlos, aber nicht rückwirkend fälschbar |
| 3 | Umschlüsselung der ausgelagerten Sicherungen auf ein Verfahren mit hybridem Schlüsseltransport | Sicherungen sind langlebige Vertraulichkeitsgüter außerhalb der Laufzeitschutzmaßnahmen und damit das zweitgrößte Aufzeichnungsrisiko |
| 4 | Zusätzliche Signatur der Lieferkettenartefakte mit ML-DSA oder SLH-DSA, parallel zu Ed25519 | Der Aktualisierungskanal ist der Weg, über den jeder spätere Verfahrenswechsel im Feld ankommt; er muss vor den abhängigen Schritten abgesichert sein |
| 5 | Nachfolger der Wurzel-CA mit zusätzlicher quantencomputerresistenter Signatur, parallel verteilt | Eine Laufzeit von 15 a überschreitet jeden absehbaren Wechselzeitpunkt; die Verteilung des neuen Ankers braucht Jahre und muss deshalb früh beginnen, obwohl die Bedrohung später wirkt |
| 6 | Ausgabe-CA, Mandanten-Zwischen-CAs und Endzertifikate | Kurze Laufzeiten (K-13) lösen diesen Schritt im laufenden Erneuerungsbetrieb auf, sobald der Anker steht |
| 7 | Kopplungsvorgang, Overlay-Netz, Versiegelung | Diese Verfahren haben keine Verfahrensaushandlung und verlangen einen abgestimmten Wechsel beider Seiten; sie stehen zuletzt, weil ihre Kompromittierung lokalen Zugriff voraussetzt |

Rechnung zum Umfang von Schritt 3: Annahme 4 TB ausgelagerter Bestand je Installation, Umschlüsselung mit 200 MB/s durch Lesen, Entschlüsseln, Verschlüsseln und Schreiben. 4 × 10^6 MB / 200 MB/s = 20.000 s ≈ 5,6 h reine Verarbeitungszeit, zuzüglich der Übertragung zum und vom Auslagerungsziel. Deutung: Der Schritt ist ein Wartungsvorgang von Stunden, kein Projekt, solange er vor der Vergrößerung des Bestands erfolgt; bei 40 TB sind es 56 h und damit ein Wartungsfenster, das geplant werden muss. Annahmen offengelegt, keine Messung.

## 20.13 Bekannte Restrisiken

| Nr. | Restrisiko | Warum es bleibt |
|---|---|---|
| RR-01 | Auf einem gemieteten Knoten ist keine Vertraulichkeitszusage haltbar | Hypervisor, virtueller Datenträger und virtuelles TPM stehen unter fremder Kontrolle (A-8). Die Konsole kennzeichnet solche Knoten, mehr ist technisch nicht erreichbar |
| RR-02 | Der Eingangsproxy, der autoritative DNS-Dienst und der rekursive Resolver stehen in speicherunsicheren Sprachen an unauthentisiert erreichbaren Grenzen | Eigenentwicklungen wären eine dauerhaft schlechtere Angriffsfläche. Ersatzmaßnahmen sind Sandkasten und Aktualisierungsfrist, keine Beseitigung |
| RR-03 | Eine korrekt signierte, bösartige Freigabe wird von keiner Prüfung erkannt | Signatur, Transparenzprotokoll und reproduzierbarer Bau beweisen Herkunft und Unverändertheit, nicht Gutartigkeit |
| RR-04 | Ein Innentäter innerhalb seiner Rechte ist nicht verhinderbar | Rechte, die gebraucht werden, können missbraucht werden. Das Modell liefert Nachweis und Begrenzung, keine Verhinderung |
| RR-05 | Ein übernommener Bedienerarbeitsplatz wirkt bis zu seiner Bereinigung | Jede Bindung an den Authentisierer wirkt erst nach dem Ende der Gerätekompromittierung |
| RR-06 | Konnektorgeheimnisse sind im Fremdsystem nicht widerrufbar | Der Widerruf liegt beim Fremdsystem; Atrium kann nur wechseln, was es selbst setzen darf |
| RR-07 | Das versiegelte Wiederherstellungspaket ist ein Generalschlüssel außerhalb des Systems | Ein Katastrophenweg, der am TPM eines Knotens hängt, ist im Katastrophenfall nicht begehbar. Die Aufbewahrung liegt beim Betreiber |
| RR-08 | Löschung am Auslagerungsziel ist nicht verhinderbar | Ein Ziel unter fremder Kontrolle kann Daten entfernen; dagegen wirkt nur ein zweites Ziel mit anderer Zugriffskette |
| RR-09 | Erkennung setzt eine ausgelagerte Auditkette voraus | Wer die Kontrollebene kontrolliert, kontrolliert auch den Prüfer. Das Erkennungsfenster ist die Auslagerungsfrequenz |
| RR-10 | Namensauflösung ist auf nicht verwalteten Geräten nicht durchsetzbar | Ein Gerät ohne Richtlinie wählt seinen Auflöser selbst; die Konsole sagt das ausdrücklich statt eine Zusage zu geben |
| RR-11 | Ein kompromittierter Verwaltungsknoten kann gültiges Schlüsselmaterial nutzen, solange die Versiegelungsrichtlinie erfüllt ist | Die Richtlinie prüft den Code, nicht das Verhalten des Codes. Fernbezeugung ändert das nicht |
| RR-12 | Die Zusagen dieses Kapitels sind Entwurfszusagen ohne Messung | Kein Wert in diesem Kapitel ist gemessen; alle Zahlen sind Zielwerte oder Rechnungen aus offengelegten Annahmen |

## Anforderungen

| ID | Anforderung | Herkunft |
|---|---|---|
| R-20-01 | Jedes Schutzgut aus 20.1 trägt im Sollzustand eine Schutzbedarfsangabe für Vertraulichkeit, Integrität und Verfügbarkeit; ein Objekt ohne Angabe wird von der API abgelehnt. | INV-19 |
| R-20-02 | Für jede der acht Vertrauensgrenzen VG-01 bis VG-08 existiert mindestens ein automatisierter Test je STRIDE-Kategorie; eine Kategorie ohne Test bricht den Bau. | INV-10 |
| R-20-03 | Der Kopplungsendpunkt ist ausschließlich im Wartemodus offen, an das lokale Segment gebunden und nach 5 Fehlversuchen oder 15 min geschlossen. | INV-27, K-14 |
| R-20-04 | Ein Konnektorprozess erreicht weder Port 8400 noch eine Adresse außerhalb seiner aus der Bindung erzeugten Ausgangs-Positivliste; ein Versuch wird verworfen und gezählt. | INV-21 |
| R-20-05 | Ein Zugriffstoken der Konsole ist höchstens 15 min gültig; die Erneuerung verlangt einen Nachweis des Passkey-Authentisierers. | INV-01 |
| R-20-06 | Eine Zertifikatsausstellung ohne zugehörigen Sollzustandseintrag erzeugt innerhalb eines Abgleichlaufs eine Störungsmeldung am betroffenen Objekt. | INV-22 |
| R-20-07 | Jede Komponente, die Netz- oder Fremdsystemdaten strukturell auswertet und aus Eigenentwicklung stammt, ist in Rust geschrieben; Ausnahmen stehen namentlich in einer Ausnahmeliste mit Sandkastenprofil. | KANON 4 Sprachen |
| R-20-08 | Im gesamten Quelltext existiert kein Aufruf, der einen Unterprozess über eine Shell startet oder einen Befehl aus einer Zeichenkette zusammensetzt. | E-03 |
| R-20-09 | Jede Datenbankabfrage ist parametrisiert; die Datenzugriffsschicht bietet keine Schnittstelle zur Übergabe zusammengesetzter Abfragetexte an. | E-04 |
| R-20-10 | Jede Eingangsnachricht wird gegen ein Schema validiert; eine Nachricht mit unbekanntem Feld wird mit stabilem Fehlercode abgelehnt. | E-05 |
| R-20-11 | Jede API-Operation besitzt genau einen Eintrag in der Berechtigungstabelle; eine Operation ohne Eintrag wird zur Laufzeit abgelehnt und bricht im Bau. | INV-19, E-06 |
| R-20-12 | Konnektoraufrufe tragen ein auftragsgebundenes Fähigkeitstoken mit Subjekt, Objekt, Operation, Gültigkeitsdauer und Vorgangsbezug; ein Aufruf ohne gültiges Token wird abgelehnt. | INV-20, E-07 |
| R-20-13 | Der Vergleich von Kopplungscodes, Token, Prüfsummen und des Wiederherstellungscodes erfolgt laufzeitkonstant über genau eine Funktion. | E-08 |
| R-20-14 | Protokolle, Fehlermeldungen, Wirkungsvorschauen, Sollzustandsexporte und Wiederherstellungspunkte enthalten 0 Treffer gegen die Geheimnismuster. | INV-20 |
| R-20-15 | Kein Prozess lädt zur Laufzeit Code nach; jede erzeugte systemd-Einheit setzt `MemoryDenyWriteExecute`, soweit die Komponente keine dokumentierte Ausnahme trägt. | E-10 |
| R-20-16 | Jeder Eingang besitzt dokumentierte Grenzen für Nachrichtengröße, Feldlänge, Verschachtelungstiefe, Elementanzahl, Aufrufdauer und Parallelität; eine Nachricht oberhalb der Grenze wird abgelehnt. | E-11 |
| R-20-17 | Jede direkte Abhängigkeit einer Kernkomponente besitzt eine Begründungsdatei; die Zahl direkter Abhängigkeiten je Kernkomponente liegt unter dem festgelegten Budget. | E-12 |
| R-20-18 | Der Bau läuft ohne Netzzugang; ein Zweitbau auf unabhängiger Infrastruktur liefert bitgleiche Artefakte. | E-13 |
| R-20-19 | Eine Fehlerausgabe nach außen enthält ausschließlich Fehlercode, Klartextmeldung, Vorgangskennung und den nächsten Schritt; die vollständige Ursache steht im Auditstrom. | INV-17, E-14 |
| R-20-20 | Zu jedem Abbild existieren eine SPDX- und eine CycloneDX-Stückliste, beide aus dem Bau erzeugt und mit dem Abbild signiert. | KANON 4 Signaturverfahren |
| R-20-21 | Die Prüfung beim Einspielen durchläuft die sieben Schritte in fester Reihenfolge; ein Fehlschlag verhindert das Schreiben in die inaktive Abbildhälfte und ist durch keine Bedienhandlung übersteuerbar. | INV-24 |
| R-20-22 | Ein Abbild ohne Eintrag im Transparenzprotokoll oder mit einer Version kleiner als der aktiven wird abgelehnt. | KANON 4 Signaturverfahren |
| R-20-23 | Kein privater Schlüssel verlässt den Knoten, auf dem er erzeugt wurde; Zweckschlüssel werden aus dem Mandantenhauptschlüssel abgeleitet, nicht gespeichert. | INV-20 |
| R-20-24 | Schlägt die Entsiegelung fehl, nimmt der Knoten keine Dienste auf, stellt keine Zertifikate aus und meldet den Zustand "Versiegelung nicht lösbar". | INV-32 |
| R-20-25 | Jede Komponente aus der Härtungstabelle läuft unter eigenem Systembenutzer mit leerer effektiver Capability-Menge, erzwingendem Zugriffskontrollprofil und Systemaufruf-Positivliste; jede Abweichung ist in der Tabelle begründet. | KANON 4 Konnektor-Sandkasten |
| R-20-26 | Der Knoten läuft im eingeschränkten Kernmodus "Integrität"; das Laden unsignierter Module und `kexec` sind nicht möglich. | INV-02 |
| R-20-27 | Interaktive Anmeldedienste außer dem befristet freischaltbaren Supportzugang sind nicht installiert; das Wurzeldateisystem ist zur Laufzeit nicht beschreibbar. | INV-26 |
| R-20-28 | Der Stücklistenabgleich gegen Schwachstellenquellen läuft täglich; jeder Treffer erzeugt einen Vorgang mit Pflichtentscheidung, der bis zur Beantwortung als offene Aufgabe sichtbar bleibt. | K-23 |
| R-20-29 | Eine als S0 bestätigte Schwachstelle an der Außenkante führt innerhalb von 72 h zu einer verfügbaren Freigabe; der Zeitpunkt der Bestätigung steht in der Sicherheitsmeldung. | K-23 |
| R-20-30 | Jede Freigabe, die eine Schwachstelle behebt, enthält eine maschinenlesbare Sicherheitsmeldung mit Komponente, Schweregrad, betroffenen Versionen und Behebungsversion. | K-23 |
| R-20-31 | Jede Eindämmungshandlung ist ein Vorgang am Sollzustand; ein Pfad zur Eindämmung am Reconciler vorbei existiert nicht. | INV-03 |
| R-20-32 | Der Auditstrom ist exportierbar mit Kettennachweis und Zeitstempel, auch ohne Quorum. | INV-23 |
| R-20-33 | Nach einem Vorfall liefert das System eine Betroffenheitsabgrenzung je Mandant aus dem Auditstrom ohne mandantenübergreifende Abfrage. | INV-19 |
| R-20-34 | Jede Signatur- und Schlüsselaustauschstelle trägt eine Verfahrenskennung; ein Artefakt ohne Verfahrenskennung wird abgelehnt. | KANON 4 Post-Quanten-Vorsorge |
| R-20-35 | Ein Wiederanlauf nach einem Vorfall ohne benannte Ursache wird in der Konsole dauerhaft als solcher gekennzeichnet, bis die Ursache eingetragen ist. | INV-18 |
| R-20-36 | Ein Knoten mit anbieterseitig mitlesbarer Fernkonsole ist in der Konsole gekennzeichnet und vom Notzugang ausgeschlossen, solange ein anderer Knoten verfügbar ist. | INV-18 |

## Akzeptanzkriterien

| Kriterium | Anforderung | Nachweis |
|---|---|---|
| Ein Objekt ohne vollständige Schutzbedarfsangabe wird von der API mit stabilem Fehlercode abgelehnt | R-20-01 | Schemaablehnungstest |
| Für 8 Vertrauensgrenzen × 6 STRIDE-Kategorien existieren 48 zugeordnete Tests; eine fehlende Zuordnung bricht den Bau | R-20-02 | Abdeckungsprüfung im Bau |
| 6 Kopplungsversuche mit falschem Code lassen den Endpunkt geschlossen zurück; ein Versuch nach 15 min 1 s schlägt fehl | R-20-03 | Wiederverwendungs- und Ratentest |
| Ein Konnektorprozess erreicht in 100 Versuchen 0 nicht gelistete Adressen und 0-mal Port 8400 | R-20-04 | Netznamensraumtest |
| Ein exfiltriertes Zugriffstoken ist nach 900 s wertlos; eine Erneuerung ohne Authentisierernachweis schlägt fehl | R-20-05 | Sitzungstest mit abgelaufenem Token |
| Eine außerhalb des Sollzustands erzeugte Ausstellung erscheint als Störung am Objekt innerhalb eines Abgleichlaufs | R-20-06 | Einschleusungstest im Testaufbau |
| Der Bau meldet 0 Übersetzungseinheiten außerhalb der Positivliste speichersicherer Ziele, abzüglich der namentlichen Ausnahmeliste | R-20-07 | Bauregel |
| Musterprüfung findet 0 shellstartende Aufrufe und 0 zusammengesetzte Abfragetexte | R-20-08, R-20-09 | Statische Prüfung im Bau |
| Jede Nachrichtenart lehnt eine Nachricht mit einem unbekannten Zusatzfeld ab | R-20-10 | Vertragstest mit Zusatzfeldern |
| Eine API-Operation ohne Eintrag in der Berechtigungstabelle bricht den Bau; zur Laufzeit antwortet sie mit Verweigerung | R-20-11 | Vollständigkeitstest |
| Ein Konnektoraufruf mit abgelaufenem oder fremdem Fähigkeitstoken wird abgelehnt | R-20-12 | Vertragstest |
| Die Laufzeitmessung des Geheimnisvergleichs zeigt über 10.000 Durchläufe keine inhaltsabhängige Abhängigkeit oberhalb der Messstreuung | R-20-13 | Zeitmessreihe |
| Protokolle, Exporte und Wiederherstellungspunkte enthalten 0 Treffer gegen die Geheimnismuster | R-20-14 | Ausgabeprüfung; ein Treffer bricht den Bau |
| Jede erzeugte Einheit ohne dokumentierte Ausnahme trägt `MemoryDenyWriteExecute` | R-20-15 | Einheitenprüfung |
| Je Eingang wird eine Nachricht an der Grenze angenommen und eine oberhalb abgelehnt | R-20-16 | Grenzwerttests |
| Jede direkte Abhängigkeit besitzt eine Begründungsdatei; das Budget wird eingehalten | R-20-17 | Bauregel |
| Zwei unabhängige Bauläufe liefern identische Artefakthashwerte | R-20-18 | Zweitbau |
| Kein Meldungstext nach außen enthält Pfade, Abfragetexte, Adressen oder Ausnahmebezeichner; jeder nennt einen Weg in der Oberfläche | R-20-19 | Musterprüfung aller Meldungstexte (INV-17) |
| Je Abbild liegen 2 signierte Stücklisten in beiden Formaten vor, deren Hashwerte in der Freigabesignatur enthalten sind | R-20-20 | Freigabeprüfung |
| Ein manipuliertes, ein zurückdatiertes, ein älteres und ein nicht protokolliertes Abbild werden jeweils abgelehnt; die inaktive Hälfte bleibt unverändert | R-20-21, R-20-22 | Vier Einspieltests mit Prüfung der inaktiven Hälfte |
| Kein Sollzustandsexport und keine Replikation enthält einen privaten Schlüssel | R-20-23 | Inhaltsprüfung (INV-22) |
| Ein Knoten mit gelöschtem TPM nimmt keine Dienste auf und meldet den definierten Zustand | R-20-24 | Entsiegelungsfehlertest |
| Für jede Komponente der Härtungstabelle ist `CapEff` gleich 0, das Zugriffskontrollprofil erzwingend und der Systemaufruffilter aktiv | R-20-25 | Laufzeitprüfung je Einheit |
| `kexec` und das Laden eines unsignierten Moduls schlagen fehl | R-20-26 | Kernmodustest |
| Auf einem produktiven Knoten ist 0 interaktiver Anmeldedienst aktiv und das Wurzeldateisystem nur lesbar eingehängt | R-20-27 | Knotenprüfung |
| Ein eingeschleuster Treffer im Stücklistenabgleich erzeugt innerhalb von 24 h einen Vorgang mit Pflichtentscheidung | R-20-28 | Abgleichtest mit Testeintrag |
| Für eine als S0 bestätigte Schwachstelle liegt nach 72 h eine Freigabe mit Sicherheitsmeldung vor | R-20-29, R-20-30 | Übungsdurchlauf des Freigabewegs |
| Jede Eindämmungshandlung erzeugt genau 1 Vorgang und 1 Auditereignis; ein direkter Eingriff ist über die API nicht möglich | R-20-31 | API-Oberflächenprüfung |
| Ein Auditexport auf der Minderheitsseite einer Partition gelingt mit gültigem Kettennachweis | R-20-32 | Partitionstest |
| Die Betroffenheitsabgrenzung liefert je Mandant eine Ereignisliste ohne mandantenübergreifende Abfrage | R-20-33 | Abfrageprüfung der Datenzugriffsschicht |
| Ein Artefakt ohne Verfahrenskennung wird beim Import abgelehnt | R-20-34 | Importtest |
| Ein Wiederanlauf ohne Ursacheneintrag zeigt die Kennzeichnung dauerhaft im Überblick | R-20-35 | Zustandsmatrixtest |
| Ein Knoten mit gesetztem Merkmal "Fernkonsole mitlesbar" wird vom Notzugang ausgeschlossen, solange ein anderer Knoten verfügbar ist | R-20-36 | Notzugangstest mit zwei Knoten |

## Offene Punkte

1. **Prüfbarkeit der Erreichbarkeitsbewertung.** Die Einstufung einer Schwachstelle als S2 oder S3 hängt an der Aussage, dass ein Codepfad im Auslieferungszustand nicht erreichbar ist. Ein Verfahren, das diese Aussage automatisiert belegt statt sie durch Sichtung zu behaupten, ist nicht festgelegt. Ohne ein solches Verfahren ist die Einstufung eine Ermessensentscheidung, die im Zweifel zugunsten des Herstellers ausfällt. Entscheidungsbedarf: Erreichbarkeitsanalyse als Bauartefakt oder Verzicht auf die Abstufung und damit kürzere Fristen für alle Treffer.
2. **Zweiter Freigabeschlüssel.** Der Entwurf verankert zwei Freigabeschlüssel unterschiedlicher Herkunft, damit der Verlust eines Schlüssels den Aktualisierungskanal nicht schließt. Offen ist, wer den zweiten Schlüssel hält, unter welchen Bedingungen er benutzt wird und wie verhindert wird, dass beide Schlüssel demselben Angriff unterliegen. Eine Aufteilung auf mehrere Personen erhöht die Sicherheit und senkt die Reaktionsgeschwindigkeit bei einer S0-Schwachstelle; beides gleichzeitig ist nicht erreichbar.
3. **Beobachtung des Transparenzprotokolls durch Dritte.** Das Protokoll erkennt eine gezielte Einzelauslieferung nur, wenn jemand außerhalb des Herstellers es beobachtet. Wer das tut, mit welchem Anreiz und mit welchem Meldeweg, ist nicht geklärt. Ohne unabhängige Beobachtung ist das Protokoll ein Nachweis gegenüber dem Betreiber, aber kein Schutz gegen den Hersteller.
4. **Aufbewahrung des Wiederherstellungspakets.** Das Paket ist ein Generalschlüssel, dessen Aufbewahrung außerhalb der technischen Maßnahmen des Systems liegt. Offen ist, ob der Entwurf eine Aufteilung in mehrere Anteile mit Schwellenwert vorschreibt, welcher Schwellenwert angemessen ist und wie ein Betreiber ohne organisatorische Reife damit umgeht. Eine Aufteilung erhöht die Sicherheit und erhöht zugleich die Wahrscheinlichkeit, dass im Ernstfall nicht genügend Anteile auffindbar sind.
5. **Laufzeitintegrität nach dem Start.** Der gemessene Start belegt den Zustand zum Startzeitpunkt. Ein Verfahren, das eine Übernahme im laufenden Betrieb erkennt, ohne einen dauerhaft privilegierten Überwachungsprozess einzuführen, der selbst ein Angriffsziel wäre, ist nicht festgelegt. Entscheidungsbedarf: periodische Neubezeugung mit Neustart, Verzicht auf Laufzeiterkennung oder ein Überwachungsprozess mit eigener Fehlerdomäne.
6. **Grenzwerte der Eingangsbegrenzungen.** E-11 verlangt Grenzen je Eingang, nennt aber keine Zahlen, weil die zulässige Nachrichtengröße eines Konnektormanifests, die maximale Elementanzahl einer Fremdantwort und die Verschachtelungstiefe einer SCIM-Antwort ohne Messung an realen Fremdsystemen nicht sinnvoll festlegbar sind. Zu früh gesetzte Grenzen brechen produktive Bindungen, zu späte sind wirkungslos.
7. **Ausnahmeliste zu E-01.** Die Liste speicherunsicherer Fremdkomponenten an Außenkanten ist heute kurz, wächst aber mit jedem Katalogeintrag, der einen eigenen Netzdienst mitbringt. Offen ist, ob ein Katalogeintrag mit unauthentisiert erreichbarem, speicherunsicherem Dienst überhaupt freigegeben wird, und wenn ja, unter welchen zusätzlichen Auflagen. Eine generelle Ablehnung würde einen erheblichen Teil verbreiteter quelloffener Software ausschließen.
8. **Messung statt Zielwert.** Sämtliche Zahlen dieses Kapitels sind Zielwerte oder Rechnungen aus Annahmen. Offen ist, welche davon vor der ersten Freigabe gemessen werden müssen und welche als Zielwert bestehen bleiben dürfen. Ohne diese Festlegung besteht die Gefahr, dass Zielwerte im Zeitverlauf als Messwerte zitiert werden.
9. **Zuordnung der Meldepflichten.** Die Fristen aus DSGVO, NIS2-Richtlinie und Cyber Resilience Act treffen unterschiedliche Adressaten: teils den Betreiber, teils den Hersteller. Welche Meldung Atrium als Produkt vorbereitet, welche der Betreiber selbst absetzt und welche Angaben das System dafür maschinenlesbar bereitstellen muss, ist in [Kapitel 22](22-compliance.md) zu entscheiden und hier nur als Anforderung vermerkt.
