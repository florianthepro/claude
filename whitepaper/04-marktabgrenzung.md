# 04 Abgrenzung zu bestehenden Lösungen

## 4.1 Geltungsbereich, Erhebungsgrundlage und Bewertungsregel

Die folgende Einordnung beruht auf öffentlich bekannten Funktionsumfängen der genannten Systeme. Sie ist keine Erhebung an Testinstallationen, kein Ergebnis einer Messung und keine Aussage über Qualität, Reife, Stabilität oder Eignung für andere Aufgaben als die hier gestellte. Produkte entwickeln sich weiter; jede Zelle der Vergleichstabelle kann durch eine Produktänderung ungültig werden, ohne dass dieses Dokument das bemerkt. Die Bewertung bezieht sich ausschließlich auf den Aufgabenkatalog, der in [Kapitel 02](02-problemstellung.md) hergeleitet und in [Kapitel 03](03-zielbild-prinzipien.md) als Zielbild festgelegt ist.

Aus dieser Grundlage folgen vier Regeln für die Lesart:

| Regel | Festlegung |
|---|---|
| **Stichtag** | Die Einordnung trägt den Kenntnisstand der Erstellung. Ohne Stichtag ist eine Produktbewertung nicht interpretierbar; das Dokument führt den Stichtag im Änderungsverzeichnis. |
| **Keine Versionsangaben** | Es werden keine Versionsnummern von Fremdsoftware genannt. Eine Bewertung, die an einer Version hängt, wäre nach der nächsten Freigabe falsch, ohne erkennbar falsch zu sein. |
| **Bewertung gegen einen festen Katalog** | Bewertet wird die Passung gegen den hier gestellten Aufgabenkatalog. Ein System, das seine eigene Aufgabe vollständig löst, kann in dieser Tabelle mehrere Felder ohne Eintrag haben; das ist eine Aussage über die Aufgabenstellung, nicht über das System. |
| **Offengelegte Unsicherheit** | Wo der Kenntnisstand für eine Bewertung nicht ausreicht, steht die Kennzeichnung "unsicher" statt einer Bewertung. Eine geratene Zelle wäre schädlicher als eine leere. |

Die Bewertung ist dreistufig. Die Stufen sind so definiert, dass jede Zuordnung an einem beobachtbaren Merkmal entschieden werden kann:

| Stufe | Zeichen | Definition |
|---|---|---|
| Vorhanden als Systemfunktion | **S** | Die Fähigkeit ist ohne Zusatzprodukt vorhanden, wird aus dem zentralen Datenmodell des Systems abgeleitet und ist in der Hauptoberfläche des Systems bedienbar. |
| Vorhanden als Zusatz oder eingeschränkt | **Z** | Die Fähigkeit ist erreichbar, aber über ein Zusatzmodul, ein Fremdpaket, eine eigene Konfigurationsfläche, nur für Teilfälle oder ohne Ableitung aus dem zentralen Datenmodell. |
| Nicht vorhanden | **—** | Das System erbringt die Fähigkeit nicht; ihre Nutzung erfordert ein weiteres, eigenständig zu betreibendes Produkt. |

Zusätzlich existiert das Zeichen **?**. Es ist keine vierte Bewertungsstufe, sondern die Aussage, dass der Kenntnisstand für eine Zuordnung zu S, Z oder — nicht ausreicht. Jedes mit **?** gekennzeichnete Feld ist in Abschnitt 4.5 einzeln benannt.

Die Dimensionen werden nicht gewichtet und nicht zu einer Summe verrechnet. Eine Summe würde unterstellen, dass die Dimensionen gegeneinander aufrechenbar sind; das sind sie nicht, weil ein fehlendes autoritatives DNS durch eine breite Anwendungsintegration nicht ersetzt wird.

## 4.2 Bewertungsraster

| ID | Dimension | Definition in einem Satz | Beobachtbares Entscheidungsmerkmal |
|---|---|---|---|
| **D01** | Identität als Systemkern | Personen und Gruppen sind das zentrale Datenmodell des Systems, aus dem Zugänge in den betriebenen Anwendungen abgeleitet werden, und nicht eine Nebenfunktion für die Anmeldung an der Verwaltungsoberfläche. | Erzeugt das Anlegen einer Person ohne weiteren Schritt einen Zugang in einer betriebenen Anwendung? |
| **D02** | Eigene Zertifizierungsstelle | Das System betreibt eine eigene Ausgabestelle für X.509-Zertifikate nach RFC 5280 und stellt Endzertifikate für seine Dienste, Knoten, Geräte oder Personen aus. | Existiert ein eigener Vertrauensanker, aus dem Endzertifikate ohne externen Anbieter ausgestellt werden? |
| **D03** | Autoritatives DNS als Systemfunktion | Das System ist selbst autoritativ für Domänen und erzeugt deren Einträge aus seinem Datenmodell, statt Einträge nur vorzuschlagen oder in einem Fremdsystem zu programmieren. | Antwortet das System selbst autoritativ, und stammt der Eintragsinhalt aus einem Objekt? |
| **D04** | Geräteverwaltung einschließlich Zertifikatsausrollung | Das System führt Endgeräte als eigene Objekte mit Eigentümer und rollt auf sie Zertifikate aus, die es bei Verlust sperren kann. | Existiert ein Geräteobjekt mit Eigentümerbezug, und führt dessen Sperrung zur sofortigen Sperrung seiner Zertifikate? |
| **D05** | Anbindung fremder Postfachsysteme | Das System legt Postfächer und Adressen in einem fremden, nicht von ihm betriebenen Postfachsystem an und entzieht sie dort wieder. | Kann eine Adresse in einem extern gehosteten oder vor Ort betriebenen Fremdsystem aus dem System heraus angelegt werden? |
| **D06** | Selbstorganisation mehrerer Knoten ohne Fachwissen | Eine weitere Maschine wird ohne Kenntnis von Rollen, Netzadressen, Zertifikaten oder Replikationsverfahren aufgenommen; die Rollenfrage wird als Zweckfrage gestellt. | Genügt ein auf der neuen Maschine angezeigter Code und eine Zweckangabe, um sie produktiv zu machen? |
| **D07** | Mehrmandantenfähigkeit mit Rechtetrennung | Das System kennt eine oberste Eigentums- und Isolationsgrenze, der jedes Objekt zugeordnet ist, und trennt Administratorrechte entlang dieser Grenze. | Gibt es eine Abfrage im System, die Objekte mehrerer Mandanten ohne ausdrückliche Freigabe zurückgibt? |
| **D08** | Breite der Anwendungsintegration | Das System versorgt eine große Zahl fremder Anwendungen und Datenbanken über deren Schnittstellen mit Identitäten, Rechten und Erreichbarkeit. | Wie viele Fremdsysteme werden nicht nur installiert, sondern aus dem Datenmodell heraus versorgt? |
| **D09** | Automatisch erzeugte Firewall und Veröffentlichung | Erreichbarkeit entsteht aus einem Absichtsobjekt, aus dem Paketfilterregel, Proxyroute, Namenseintrag und Zertifikat gemeinsam abgeleitet werden; einzelne Regeln sind nicht von Hand anlegbar. | Existiert ein Dialog zum Anlegen einer einzelnen Firewallregel? Wenn ja, ist die Dimension nicht erfüllt. |
| **D10** | Bedienbarkeit ohne Terminal | Jede Standardaufgabe des Aufgabenkatalogs ist in der Hauptoberfläche vollständig ausführbar; Kommandozeile, Konfigurationsdateien und Skripte sind für den Normalbetrieb nicht erforderlich. | Lässt sich der vollständige Aufgabenkatalog ohne Terminalzugriff durchlaufen? |
| **D11** | Lizenz | Alle zur Erfüllung des Aufgabenkatalogs nötigen Bestandteile stehen unter einer quelloffenen Lizenz, und keine Katalogfunktion ist einer nicht quelloffenen Ausgabe vorbehalten. | Existiert eine Funktion des Aufgabenkatalogs, die nur in einer kostenpflichtigen oder proprietären Ausgabe verfügbar ist? |

D09 ist die einzige Dimension, deren Entscheidungsmerkmal negativ formuliert ist. Das ist beabsichtigt: Die Anwesenheit eines Regelanlagedialogs ist leichter und eindeutiger festzustellen als die Abwesenheit eines zweiten Schreibwegs, und sie ist nach INV-09 hinreichend für die Nichterfüllung.

## 4.3 Bewertete Systeme

### 4.3.1 Univention Corporate Server

**Was es tut.** Eine Serverdistribution der Debian-Familie, deren Mittelpunkt ein Verzeichnisdienst ist. Personen, Gruppen und Rechnerobjekte liegen im Verzeichnis; eine Weboberfläche bedient sie; ein Anwendungskatalog installiert Fremdprodukte auf denselben oder weiteren Servern; ein Benachrichtigungsmechanismus versorgt installierte Anwendungen aus dem Verzeichnis. Das Produkt betreibt eine eigene Zertifizierungsstelle für die interne Kommunikation, führt einen an das Verzeichnis gekoppelten DNS-Dienst und kann als mit klassischen Arbeitsplatzdomänen verträglicher Domänencontroller auftreten oder einer solchen Domäne beitreten.

**Stärke bezogen auf die Aufgabe.** Identität ist hier tatsächlich der Systemkern und nicht die Anmeldeverwaltung einer Oberfläche; das Anlegen einer Person wirkt in installierte Anwendungen hinein. Damit ist D01 als Systemfunktion erfüllt, und der Anwendungskatalog löst den Teil der Aufgabe, der Fremdsoftware installierbar machen soll.

**Grenze bezogen auf die Aufgabe.** Erreichbarkeit ist kein Objekt: Paketfilter, Reverse Proxy und Veröffentlichung nach außen werden als eigene Konfigurationsflächen gepflegt, nicht aus einer Absicht abgeleitet, womit D09 nicht erfüllt ist. Die Aufnahme eines weiteren Servers ist ein Domänenbeitritt mit Rollenwahl und Anmeldedaten und damit eine Fachfrage, nicht eine Zweckfrage. Ein Mandantenbegriff mit Isolationsstufen und Rechtetrennung entlang der Mandantengrenze ist nach derzeitigem Kenntnisstand nicht Teil des Modells; diese Bewertung ist mit Unsicherheit behaftet.

### 4.3.2 Zentyal

**Was es tut.** Eine Serverdistribution auf Ubuntu-Basis mit Weboberfläche und Modulschaltung für Verzeichnisdienst, Datei- und Druckdienste, Mail, DNS, DHCP, Paketfilter und VPN. Der Schwerpunkt liegt auf Verträglichkeit mit klassischen Arbeitsplatzdomänen.

**Stärke bezogen auf die Aufgabe.** Für den Modulumfang ist die Bedienung geschlossen: DNS, Paketfilter und Verzeichnis werden in derselben Oberfläche gepflegt, was D10 für diesen Umfang weitgehend erfüllt und zeigt, dass ein zusammenhängender Bedienweg für Infrastrukturdienste möglich ist.

**Grenze bezogen auf die Aufgabe.** Der Funktionsumfang ist eine feste Modulliste, keine Integrationsbreite über hunderte Fremdsysteme; D08 ist damit auf den Modulsatz begrenzt. Der Paketfilter wird als Regelwerk bedient, nicht abgeleitet. Mehrknotenbetrieb beschränkt sich auf Verzeichnisreplikation und ist kein allgemeines Verlagern von Diensten; Mandantenfähigkeit im Sinne von D07 ist nicht Teil des Modells.

### 4.3.3 NethServer

**Was es tut.** Eine Serverdistribution mit Weboberfläche und Modulkatalog für Mail, Dateidienste, Paketfilter, Verzeichnisdienst und Zertifikatsbezug über ACME nach RFC 8555. Die neuere Generation stellt die Module auf abbildbasierte Ausführung um und führt eine Verwaltung über mehrere Knoten ein.

**Stärke bezogen auf die Aufgabe.** Der Bruch mit der paketbasierten Modulinstallation zugunsten abbildbasierter Module und knotenübergreifender Verwaltung geht in dieselbe Richtung wie die hier getroffene Festlegung, Dienste als verlagerbare Einheiten zu führen.

**Grenze bezogen auf die Aufgabe.** Der Reifegrad und die Bedienlast der knotenübergreifenden Verwaltung sind aus öffentlich zugänglicher Kenntnis nicht belastbar zu beurteilen; D06 wird deshalb als unsicher geführt. Geräteverwaltung mit Zertifikatsausrollung und Mandantenfähigkeit gehören nicht zum Funktionsumfang.

### 4.3.4 YunoHost

**Was es tut.** Eine Debian-basierte Distribution für den Eigenbetrieb, die einen Anwendungskatalog, ein eigenes Verzeichnis mit Anmeldeportal, einen eigenen Postfachdienst, automatische Reverse-Proxy-Einträge und automatischen Zertifikatsbezug für die verwalteten Namen zusammenführt.

**Stärke bezogen auf die Aufgabe.** Für den Umfang einer Maschine ist die Ableitung konsequent: Wer eine Anwendung installiert und ihr einen Namen gibt, erhält Proxyeintrag und Zertifikat ohne eigenes Zutun; das ist der Kern dessen, was D09 verlangt.

**Grenze bezogen auf die Aufgabe.** Das System ist auf eine Maschine ausgelegt; D06 entfällt. Mail wird selbst betrieben statt in fremden Postfachsystemen angelegt, womit D05 nicht erfüllt ist. Es gibt keine Geräteobjekte, keine Ausgabestelle für Gerätezertifikate und keine Mandantengrenze; der Katalog besteht aus gemeinschaftlich gepflegten Paketen und deckt einen anderen Bedarf ab als die in der Aufgabe geforderte Breite an Geschäftsanwendungen und Datenbanken.

### 4.3.5 Cloudron

**Was es tut.** Eine Plattform, die auf einem Server Webanwendungen aus einem Katalog installiert, betreibt, sichert und aktualisiert, mit eigenem Benutzerverzeichnis und Einmalanmeldung, automatischem Reverse Proxy, automatischem Zertifikatsbezug und einer Anbindung an die Schnittstellen von DNS-Anbietern, über die die benötigten Namenseinträge selbsttätig gesetzt werden.

**Stärke bezogen auf die Aufgabe.** Die Kombination aus Benutzerverzeichnis, Katalog, Proxy, Zertifikat und automatisch gesetzten Namenseinträgen ist die vollständigste Umsetzung des Gedankens "Erreichbarkeit folgt aus der Installation", die in dieser Aufstellung vorkommt. Der Weg über die Anbieterschnittstelle löst die Namensfrage, ohne selbst autoritativ zu sein.

**Grenze bezogen auf die Aufgabe.** Genau das ist auch die Grenze: Die Autorität über den Namen bleibt fremd, es gibt keine getrennte interne Sicht und keinen Geltungsbereich je Gruppe, Person oder Gerät. Es gibt keine Ausgabestelle für Geräte- und Personenzertifikate, keine Geräteobjekte, keinen Mehrknotenbetrieb. Die Lizenzlage wird hier als unsicher geführt, weil quelloffener Quelltext und kommerzielle Nutzungsbedingungen nebeneinander stehen und die Zuordnung zu D11 vom genauen Wortlaut abhängt, der hier nicht belastbar bekannt ist.

### 4.3.6 Proxmox VE

**Was es tut.** Eine Debian-basierte Virtualisierungsplattform mit Weboberfläche, Knotenverbund über ein eigenes Kommunikationsverfahren, Hochverfügbarkeit, ZFS- und verteiltem Blockspeicher, Sicherung und einem Rollen- und Bereichsmodell für Administratoren.

**Stärke bezogen auf die Aufgabe.** Der Beitritt eines weiteren Knotens über eine kopierbare Beitrittsinformation ist die praktisch nächste Entsprechung zum hier beschriebenen Kopplungsvorgang und der Beleg dafür, dass ein Verbund ohne Handkonfiguration von Adressen und Zertifikaten aufgebaut werden kann. Speicher und Verfügbarkeit werden ohne Terminal bedient.

**Grenze bezogen auf die Aufgabe.** Die Plattform verwaltet Maschinen, nicht Personen: Benutzer sind Administratoren der Plattform, nicht Beschäftigte, die in Fachanwendungen versorgt werden; D01 ist damit nicht erfüllt. Es gibt keinen Katalog von Geschäftsanwendungen mit Versorgung, kein autoritatives DNS, keine Geräteverwaltung. Ein Paketfilter existiert, seine Regeln werden jedoch eingetragen und nicht abgeleitet, weshalb D09 trotz vorhandener Funktion nicht erfüllt ist.

### 4.3.7 TrueNAS SCALE

**Was es tut.** Ein Speicherbetriebssystem auf Debian-Basis mit ZFS, Weboberfläche, Dateifreigaben über die üblichen Protokolle, Momentaufnahmen und Replikation sowie einem Anwendungskatalog für zusätzliche Dienste. Identitäten werden lokal geführt oder aus einem Fremdverzeichnis bezogen.

**Stärke bezogen auf die Aufgabe.** Die Bedienung von ZFS ohne Terminal ist gelöst, einschließlich Momentaufnahmen, Replikation und Verschlüsselung; das ist genau der Teil des Speicherproblems, den [Kapitel 17](17-speicher-backup.md) für Atrium beschreibt.

**Grenze bezogen auf die Aufgabe.** Identität ist hier Verbrauch, nicht Quelle: Das System ist Teilnehmer eines Verzeichnisses, nicht dessen Führung. Autoritatives DNS, Geräteverwaltung, Postfachanbindung und Mandantengrenze fehlen. Der Stand der knotenübergreifenden Verwaltung außerhalb dedizierter Hardwarepaare ist aus öffentlich zugänglicher Kenntnis nicht belastbar zu beurteilen und wird deshalb als unsicher geführt.

### 4.3.8 Synology DSM

**Was es tut.** Ein an die Hardware des Herstellers gebundenes Betriebssystem für Speichergeräte mit einer Weboberfläche, die Speicher, Freigaben, Benutzer, Zertifikate, Reverse Proxy, Paketfilter, Sicherung und einen Paketkatalog abdeckt; Verzeichnisdienst und Mailserver sind eigene Pakete.

**Stärke bezogen auf die Aufgabe.** Dieses System ist der Beleg dafür, dass ein sehr breiter Funktionsumfang vollständig ohne Terminal bedienbar sein kann, und zwar für Bediener ohne einschlägige Ausbildung. D10 ist hier der Maßstab, an dem sich die Atrium Console messen lassen muss.

**Grenze bezogen auf die Aufgabe.** Reverse Proxy und Paketfilter werden als Regellisten von Hand gepflegt; die Oberfläche macht die Regelpflege bequem, sie schafft sie nicht ab. Genau dieser Unterschied ist der Gegenstand von D09: Bedienbarkeit einer Regelpflege ist nicht dasselbe wie Abwesenheit einer Regelpflege. Es gibt keinen Mehrknotenbetrieb im hier geforderten Sinn, keine Geräteverwaltung für Endgeräte mit Zertifikatsausrollung und keine Mandantengrenze; die Lizenz ist proprietär und an Hardware des Herstellers gebunden.

### 4.3.9 Kommerzielles Serverbetriebssystem mit Verzeichnisdienst und Geräteverwaltung

**Was es tut.** Diese Klasse führt einen Verzeichnisdienst als Systemkern, an den ein autoritativer DNS-Dienst, eine Zertifizierungsstelle, ein Richtlinienmechanismus für beigetretene Arbeitsplätze und eine Anmeldeinfrastruktur unmittelbar gekoppelt sind. Geräte werden über den Domänenbeitritt zu Objekten des Verzeichnisses; Richtlinien wirken auf sie; ergänzende Verwaltungsprodukte desselben Herstellers decken mobile Geräte und Softwareverteilung ab. Bedient wird über mehrere rollenbezogene Verwaltungskonsolen, ergänzt durch eine Skriptumgebung.

**Stärke bezogen auf die Aufgabe.** Die Dimensionen D01, D02, D03 und D04 sind hier als zusammenhängende Systemfunktionen gelöst, und zwar seit langem und in großem Maßstab. Dass Verzeichnis, Namensdienst und Zertifizierungsstelle voneinander wissen, ist in dieser Klasse kein Entwurfsziel, sondern Voraussetzung; das ist der sachliche Kern, an dem sich die Aufgabe dieses Dokuments orientiert.

**Grenze bezogen auf die Aufgabe.** Das Bedienmodell setzt Fachwissen voraus: Es ist rollen- und konsolenorientiert, verlangt die Kenntnis der zuständigen Konsole vor der Aufgabe und weist Automatisierung an eine Skriptumgebung, womit D10 nach der hier gewählten Definition nicht erfüllt ist. Erreichbarkeit nach außen, Reverse Proxy und Paketfilter sind getrennte Produkte mit eigener Regelpflege. Die Anbindung an Postfachsysteme ist für den eigenen Anbieter gelöst und für fremde nur über Zusatzprodukte. Mandantenfähigkeit ist kein natives Konzept; sie wird über getrennte Verzeichnisstrukturen erreicht, deren Betriebskosten hoch sind. Die Lizenz ist proprietär und in der Regel platz- oder gerätebezogen.

### 4.3.10 Kubernetes-Distributionen mit Verwaltungsoberfläche

**Was es tut.** Diese Klasse orchestriert Abbilder über mehrere Knoten anhand eines deklarierten Sollzustands, den Regelkreise laufend gegen den beobachteten Zustand abgleichen. Erweiterungen liefern Eingangsobjekte, automatischen Zertifikatsbezug, das Setzen von Namenseinträgen in fremden DNS-Systemen und die Anbindung an einen Identitätsanbieter. Namensräume und ein Rechtemodell trennen Bereiche.

**Stärke bezogen auf die Aufgabe.** Das Muster "deklarierter Sollzustand, Regelkreis, abgeleitete Objekte, Idempotenz" stammt aus dieser Klasse. Es ist der unmittelbare Vorläufer dessen, was [Kapitel 08](08-kontrollebene.md) für atrium-core beschreibt, und es wird hier übernommen, nicht erfunden.

**Grenze bezogen auf die Aufgabe.** Die Abstraktion ist die Arbeitslast, nicht der Mensch: Identität, Postfächer, Geräte und autoritative Namensführung sind Zusammenbauten aus Erweiterungen, die ein Fachkundiger auswählt, verbindet und versioniert. Das Vokabular ist nicht verbergbar, sondern nur verdeckbar; sobald ein Fehler auftritt, steht es in der Meldung. Die Bedienlast ist damit das Gegenteil der hier gestellten Aufgabe. Diese Einschätzung deckt sich mit der Technologiefestlegung in KANON.md, Kubernetes nicht als verdeckte Ausführungsschicht zu verwenden.

### 4.3.11 Einfache Heimserver-Oberflächen

**Was es tut.** Diese Klasse umfasst Weboberflächen, die auf einer Maschine eine Auswahl selbst betriebener Dienste installieren, starten, aktualisieren und teilweise hinter einem Reverse Proxy veröffentlichen. Der Umfang reicht von reinen Startseiten bis zu Oberflächen mit eigenem Katalog und automatischem Zertifikatsbezug.

**Stärke bezogen auf die Aufgabe.** Die Einstiegshürde ist die niedrigste aller hier betrachteten Klassen, und der Weg vom Einschalten bis zum ersten laufenden Dienst ist kurz. Das ist die Erfahrung, die K-01 als Zielwert festhält.

**Grenze bezogen auf die Aufgabe.** Jede Anwendung bringt ihre eigene Benutzerverwaltung mit; es gibt keinen Identitätskern, keine Zertifizierungsstelle, keine Geräte, keine Mandanten, keinen Nachweis und keine Rücknahme. Häufig erzeugt die Oberfläche Konfigurationsdateien, die anschließend von Hand nachbearbeitet werden, womit der zweite Schreibweg wieder entsteht, den INV-02 ausschließt. Die Klasse ist so heterogen, dass die Dimensionen D09 und D10 für sie nicht einheitlich bewertbar sind.

### 4.3.12 Sandstorm

**Was es tut.** Eine Plattform, auf der jede Anwendungsinstanz in einer eigenen Abschottung läuft und Zugriff nicht konfiguriert, sondern als Freigabe erteilt wird; die Plattform führt die Identitäten und reicht sie an die Anwendungen durch, sodass Anwendungen keine eigene Benutzerverwaltung benötigen.

**Stärke bezogen auf die Aufgabe.** Der Grundsatz "Zugriff entsteht aus einer Freigabe, nicht aus einer Konfiguration in der Anwendung" ist dieselbe Denkfigur, die hier als Zuweisung geführt wird, und in Sandstorm konsequenter umgesetzt als in jedem anderen betrachteten System. Die Abschottung je Instanz ist stärker als das, was dieses Dokument verlangt.

**Grenze bezogen auf die Aufgabe.** Die Anwendungen müssen für die Plattform paketiert sein; damit fällt der weit überwiegende Teil gewöhnlicher Serversoftware und praktisch der gesamte Datenbankbereich aus dem Umfang, und D08 ist nicht erfüllt. Infrastrukturfunktionen wie Namensführung, Zertifizierungsstelle für Geräte, Paketfilterableitung und Mehrknotenbetrieb sind nicht Gegenstand des Entwurfs. Die Entwicklungsaktivität ist nach öffentlich zugänglicher Kenntnis seit längerem gering; das ist für die Eignung als Grundlage erheblich und wird hier als Sachverhalt genannt, nicht als Bewertung des Entwurfs.

### 4.3.13 FreeIPA

**Was es tut.** Ein integriertes Identitätsverwaltungssystem für Linux, das Verzeichnisdienst, Kerberos-Schlüsselverteilung, eine eigene Zertifizierungsstelle, einen an das Verzeichnis gekoppelten autoritativen DNS-Dienst und Rechnerobjekte mit Aufnahmeverfahren zusammenführt. Die Aufnahme eines Rechners erzeugt dessen Zertifikat und Kerberos-Schlüssel; mehrere Server bilden eine Replikationstopologie; Zugriffs- und Rechteregeln werden zentral geführt.

**Stärke bezogen auf die Aufgabe.** Dies ist der einzige quelloffene Vertreter in dieser Aufstellung, der D01, D02, D03 und D04 gemeinsam als Systemfunktionen erbringt, und damit der Beleg dafür, dass die Kopplung von Identität, eigener Zertifizierungsstelle, autoritativer Namensführung und Geräteobjekten mit Zertifikatsausrollung technisch gelöst ist. Für den infrastrukturellen Kern der hier gestellten Aufgabe ist FreeIPA der nächste Verwandte.

**Grenze bezogen auf die Aufgabe.** Es ist eine Komponente, kein Serverbetriebssystem: Es gibt keinen Anwendungskatalog, keine Dienstplatzierung, keine Veröffentlichung, keine Postfachanbindung, keine Mandantengrenze. Der kanonische Bedienweg ist die Kommandozeile; die Weboberfläche deckt einen großen Teil ab, die Aufnahme eines Rechners jedoch setzt eine Anmeldung auf diesem Rechner voraus. Die verwalteten Geräte sind Arbeitsplätze und Server mit passendem Betriebssystem, nicht beliebige Endgeräte.

### 4.3.14 Eigenständige Identitätsanbieter

**Was es tut.** Diese Klasse erbringt Anmeldung und Tokenausgabe nach OAuth 2.0 (RFC 6749), OpenID Connect und teils SAML 2.0, verwaltet Benutzer entweder selbst oder föderiert sie, bietet Mehrfaktorverfahren einschließlich WebAuthn, und stellt teilweise SCIM nach RFC 7643 und RFC 7644 für die Versorgung angebundener Systeme bereit.

**Stärke bezogen auf die Aufgabe.** Die Protokollabdeckung und die Nachweisbarkeit der Standardkonformität sind hoch, und die Anmeldefrage ist damit gelöst. Genau deshalb sieht die Technologiefestlegung in KANON.md einen Vertreter dieser Klasse als eingebetteten Protokollkopf vor, dessen eigene Verwaltungsoberfläche abgeschaltet ist.

**Grenze bezogen auf die Aufgabe.** Anmeldung ist nicht Versorgung: Ein Identitätsanbieter sagt, wer jemand ist, und nicht, dass in einem Ticketsystem ein Agent mit einer Warteschlangenzuordnung existieren soll. Wo die Zielanwendung kein SCIM spricht, und das ist bei selbst betriebener Software der Normalfall, endet die Wirkung an der Anmeldemaske. Signaturschlüssel für Token sind keine Zertifizierungsstelle im Sinne von D02; Geräte, Namensführung, Erreichbarkeit und Mehrknotenbetrieb liegen außerhalb des Gegenstands.

### 4.3.15 Konfigurationsverwaltungswerkzeuge

**Was es tut.** Diese Klasse beschreibt den Zielzustand von Maschinen und Fremdsystemen in versionierbarem Quelltext und wendet ihn über eine Ausführungsschicht an, in der Regel idempotent und mit einer vorgeschalteten Berechnung der bevorstehenden Änderungen. Über Module und Anbieterbindungen erreicht sie eine sehr große Zahl von Zielsystemen, einschließlich extern gehosteter Postfach- und Verzeichnisdienste.

**Stärke bezogen auf die Aufgabe.** Das ist die vorhandene fachliche Antwort auf das Problem der vielen Konfigurationsflächen, und sie löst drei Teile davon tatsächlich: eine Quelle, Reproduzierbarkeit und Idempotenz. Die Trennung zwischen Berechnung und Anwendung ist der unmittelbare Vorläufer der Wirkungsvorschau nach INV-08. In D08 ist diese Klasse allen anderen überlegen.

**Grenze bezogen auf die Aufgabe.** Die Wahrheitsquelle ist Quelltext, das Publikum sind Entwickler, und das Vokabular ist die Implementierung selbst; damit ist die Abstraktionsleckage maximal, nicht minimal. Es gibt kein laufendes Objektmodell, keine Wirkungsvorschau in fachlichen Begriffen, keinen Teilzustand je Zielsystem, der als Ergebnis eines Vorgangs sichtbar bleibt, und keine an Geschäftsobjekte gebundene Nachweiskette. Ein Lauf endet mit einem Ergebnis, nicht mit einem nachvollziehbaren Vorgang, der zurückgenommen werden kann.

## 4.4 Vergleichstabelle

Zeichenbedeutung nach Abschnitt 4.1: **S** vorhanden als Systemfunktion, **Z** vorhanden als Zusatz oder eingeschränkt, **—** nicht vorhanden, **?** Kenntnisstand nicht ausreichend.

| System | D01 | D02 | D03 | D04 | D05 | D06 | D07 | D08 | D09 | D10 | D11 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Univention Corporate Server | S | S | S | Z | Z | Z | ? | Z | — | Z | S |
| Zentyal | S | Z | S | — | Z | Z | — | Z | — | Z | S |
| NethServer | S | Z | Z | — | Z | ? | — | Z | — | Z | S |
| YunoHost | S | Z | Z | — | — | — | — | Z | Z | S | S |
| Cloudron | S | Z | Z | — | Z | — | Z | Z | Z | S | ? |
| Proxmox VE | — | Z | — | — | — | S | Z | — | — | S | S |
| TrueNAS SCALE | Z | Z | — | — | — | ? | — | Z | — | S | S |
| Synology DSM | Z | Z | Z | — | Z | — | — | Z | — | S | — |
| Kommerzielles Serverbetriebssystem | S | S | S | S | Z | Z | — | Z | — | Z | — |
| Kubernetes-Distributionen mit Oberfläche | Z | Z | Z | — | — | Z | Z | Z | Z | — | S |
| Einfache Heimserver-Oberflächen | — | — | — | — | — | — | — | Z | ? | ? | S |
| Sandstorm | S | — | — | — | — | — | Z | — | Z | S | S |
| FreeIPA | S | S | S | S | — | Z | — | — | — | Z | S |
| Eigenständige Identitätsanbieter | S | — | — | — | — | Z | Z | Z | — | S | S |
| Konfigurationsverwaltungswerkzeuge | — | — | — | — | Z | Z | — | S | Z | — | S |
| *Aufgabe dieses Dokuments (Sollzustand)* | *S* | *S* | *S* | *S* | *S* | *S* | *S* | *S* | *S* | *S* | *S* |

Die letzte Zeile ist keine gleichrangige Tabellenzeile. Sie beschreibt eine Spezifikation, alle übrigen Zeilen beschreiben ausgelieferte Produkte. Eine Spezifikation erfüllt ihre eigenen Dimensionen definitionsgemäß, weil die Dimensionen aus ihr abgeleitet sind; darin liegt keine Aussage über Erreichbarkeit, Aufwand oder Risiko. Die Zeile steht nur deshalb in der Tabelle, weil ihr Fehlen die Frage offen ließe, wogegen verglichen wird; sie ist bis zur ersten Freigabe als Zielzustand und nicht als Befund zu lesen.

## 4.5 Offengelegte Unsicherheiten

| System | Dimension | Grund der Unsicherheit |
|---|---|---|
| Univention Corporate Server | D07 | Ob ein Mandantenbegriff mit durchgängiger Rechtetrennung über alle Objektarten existiert, ist aus öffentlich zugänglicher Kenntnis nicht zu entscheiden. Eine Organisationseinheit im Verzeichnis ist keine Mandantengrenze im Sinne von INV-19. |
| NethServer | D06 | Der Reifegrad und die Bedienlast der knotenübergreifenden Verwaltung sind nicht beurteilbar; die Bewertung hinge an einer Produktgeneration, die hier nicht benannt werden darf. |
| Cloudron | D11 | Quelloffener Quelltext und kommerzielle Nutzungsbedingungen stehen nebeneinander; die Zuordnung zu D11 hängt am genauen Wortlaut, der hier nicht belastbar bekannt ist. |
| TrueNAS SCALE | D06 | Der Stand der knotenübergreifenden Verwaltung außerhalb dedizierter Hardwarepaare ist nicht belastbar bekannt. |
| Einfache Heimserver-Oberflächen | D09, D10 | Die Klasse ist heterogen. Eine Klassenbewertung ist nur zulässig, wo die Klasse in der Dimension einheitlich ist; hier ist sie es nicht. |

Jede der sechs Zellen in diesen fünf Zeilen ist ein offener Klärungsauftrag und keine Bewertung. Nach R-04-14 ist eine unsichere Zelle ohne benannten Klärungsauftrag ein Mangel des Dokuments.

## 4.6 Vertiefter Vergleich mit dem nächsten Verwandten

Der nächste Verwandte über alle Dimensionen ist Univention Corporate Server: Serverbetriebssystem der Debian-Familie, Identität als Systemkern, eigene Zertifizierungsstelle, an das Verzeichnis gekoppelter Namensdienst, Anwendungskatalog mit Versorgung, Weboberfläche als vorgesehener Bedienweg. Auf der infrastrukturellen Achse ist FreeIPA näher, auf der Katalog- und Erreichbarkeitsachse sind YunoHost und Cloudron näher, und auf der Verbundachse ist Proxmox VE näher. Kein bestehendes System ist auf allen Achsen zugleich das nächste; diese Feststellung ist selbst ein Ergebnis der Einordnung.

### 4.6.1 Worin die Aufgabe hinausgeht

| Gegenstand | Nächster Verwandter | Aufgabe dieses Dokuments | Prüfbarer Unterschied |
|---|---|---|---|
| Schreibwege | Verzeichnis ist zentral, Paketfilter, Proxy und Fremdoberflächen bleiben eigene Schreibflächen | Genau ein Schreibweg in den Sollzustand; Verwaltungsoberflächen der Kernkomponenten abgeschaltet | Zahl der Orte, an denen dieselbe Tatsache schreibbar ist: > 1 gegen 1 (INV-02, INV-30) |
| Erreichbarkeit | Konfiguriert je Fläche | Ein Objekt Veröffentlichung erzeugt Proxyroute, Namenseintrag, Zertifikat und Netzfreigabe gemeinsam | Existenz eines Dialogs zum Anlegen einer einzelnen Firewallregel (INV-09, INV-10) |
| Namensführung | DNS am Verzeichnis, eine Sicht | Sichtattribut je Eintrag, bis zu zwei Zoneninstanzen je Domäne, Geltungsbereich auf Gruppe, Person oder Gerät, abgebildet auf Netzzone und Gerätezertifikat | Auflösbarkeit desselben Namens mit unterschiedlichem Ergebnis je Netzzone, siehe [Kapitel 12](12-dns-netzwerk.md) |
| Aufnahme einer Maschine | Domänenbeitritt mit Rollenwahl und Anmeldedaten | Code aus dem Wartemodus, passwortauthentisierter Schlüsselaustausch nach RFC 9382, danach Zweckfrage | Zahl der Entscheidungen bis zum produktiven Knoten: Zielwert 2 nach K-03, siehe [Kapitel 16](16-cluster.md) |
| Fremdsysteme | Versorgung installierter Anwendungen | Konnektorvertrag mit `describe`, `observe`, `plan`, `apply`, `healthcheck`, Feldeigentum je Feld als Pflichtangabe | Ablehnung eines Manifests ohne vollständige Eigentumsangabe (INV-13), siehe [Kapitel 09](09-konnektoren.md) |
| Änderung als Objekt | Änderungen wirken sofort | Vorgang mit Wirkungsvorschau, Freigabe, Teilzuständen je Zielsystem und Rücknahme | Ausgang "teilweise fehlgeschlagen" mit benanntem Rest bei Fehlerinjektion (INV-08, INV-12) |
| Mandanten | Nach derzeitigem Kenntnisstand kein Stufenmodell | Vier online steigerbare Isolationsstufen, eigene Zwischen-CA ab der untersten Stufe | Ablehnung jeder Abfrage ohne Mandantenprädikat (INV-19), siehe [Kapitel 19](19-mandanten-rechte-audit.md) |
| Bedienlast | Nicht zugesichert | Höchstens drei Entscheidungen je Standardaufgabe, im Bau geprüft | Ein zusätzliches Pflichtfeld bricht den Bau (INV-14, K-03) |
| Postfächer | Anbindung an fremde Postfachsysteme als Zusatzmodul | Postfach unabhängig vom Ablageort als Objekt, Adresse getrennt vom Postfach, geteilte Postfächer gleichrangig | Anlegen einer Adresse in einem Fremdsystem mit zwei Entscheidungen, siehe [Kapitel 14](14-mail.md) |

### 4.6.2 Worin die Aufgabe nicht hinausgeht

| Gegenstand | Feststellung |
|---|---|
| Verzeichnis als Systemkern | Übernommen. Der Gedanke, dass Personen das zentrale Datenmodell sind und Anwendungen daraus versorgt werden, ist in dieser Produktklasse etabliert. |
| Anwendungskatalog | Übernommen. Ein signierter Katalog installierbarer Fremdprodukte ist keine neue Erfindung. |
| Eigene Zertifizierungsstelle mit interner Ausstellung | Übernommen, aus FreeIPA und aus der kommerziellen Verzeichnisklasse. |
| Verträglichkeit mit klassischen Arbeitsplatzdomänen | Nicht erreicht. Der nächste Verwandte ist hier weiter: Er kann als Domänencontroller für klassische Arbeitsplätze auftreten. Der Entwurf dieses Dokuments führt OIDC, LDAP nach RFC 4511 und Passkey-Verfahren, aber Kerberos nach RFC 4120 nicht als Kernfunktion. Für Dateidienste mit Einmalanmeldung und für den klassischen Domänenbeitritt ist das eine Lücke und keine Vereinfachung. |
| Treiber und Hardwareunterstützung | Nicht verändert. Sie kommen unverändert aus der Ubuntu-Basis, siehe [Kapitel 06](06-basis-ubuntu.md). |
| Fremdoberflächen | Nicht ersetzt. Die Fachkonfiguration eines Ticketsystems bleibt im Ticketsystem; Atrium legt nur fest, wo es läuft und wer versorgt wird (INV-30). |
| Reife | Nicht vergleichbar. Der nächste Verwandte ist ausgeliefert und im Einsatz; dieser Entwurf ist es nicht. Jeder Vergleich, der das unterschlägt, ist unvollständig. |

## 4.7 Was hier neu ist

Jeder Punkt ist als überprüfbare Aussage formuliert und steht unter dem Vorbehalt des derzeitigen Kenntnisstands; jeder Punkt fällt, sobald ein Gegenbeispiel benannt wird.

1. Nach derzeitigem Kenntnisstand existiert kein quelloffenes Produkt, das Identität, eigene Zertifizierungsstelle, autoritative Namensführung, Geräteobjekte mit Zertifikatsausrollung, Anbindung fremder Postfachsysteme, Mehrknotenbetrieb und einen Anwendungskatalog aus einem gemeinsamen, quorumpflichtigen Sollzustand ableitet. Gegenprobe: ein System benennen, das in Abschnitt 4.4 in D01 bis D06 und D08 durchgehend **S** trägt.
2. Nach derzeitigem Kenntnisstand bietet jedes betrachtete System mit Paketfilter- oder Proxyfunktion einen Dialog zum Anlegen einer einzelnen Regel. Die Festlegung, dass ein solcher Dialog nicht existiert und abgeleitete Artefakte nur über ihre Quelle änderbar sind, ist in dieser Strenge nicht bekannt. Gegenprobe: ein System benennen, dessen Schnittstelle für Paketfilterregeln keine Schreiboperation kennt.
3. Nach derzeitigem Kenntnisstand wird Bedienaufwand nirgends als brechendes Baukriterium geprüft. Die Prüfung maschinenlesbarer Aufgabendefinitionen gegen die tatsächlichen Formulare mit Abbruch bei mehr als drei Entscheidungen ist neu. Gegenprobe: ein Projekt benennen, dessen Bau an einem zusätzlichen Pflichtfeld scheitert.
4. Nach derzeitigem Kenntnisstand existiert keine im Bau geprüfte Positivliste erlaubter Oberflächenbegriffe, die auch die Übersetzung von Fremdfehlermeldungen einschließt. Gegenprobe: ein Projekt benennen, das Fremdfehler vor der Anzeige in eigenes Vokabular übersetzt und diese Übersetzung erzwingt.
5. Nach derzeitigem Kenntnisstand verbindet kein System ein Sichtattribut je einzelnem Namenseintrag mit einem Geltungsbereich, der auf Gruppe, Person oder Gerät lautet und auf Netzzone und Gerätezertifikat abgebildet wird. Getrennte Sichten sind verbreitet; die Geltung je Subjekt ist es nicht.
6. Nach derzeitigem Kenntnisstand kennt kein vergleichbares System eine online steigerbare, vierstufige Mandantenisolation mit einer eigenen Zwischen-CA bereits ab der untersten Stufe. Gegenprobe: ein System benennen, in dem ein Mandant auf logischer Isolationsstufe eine eigene Zwischen-CA besitzt.
7. Nach derzeitigem Kenntnisstand verlangt kein Konnektor- oder Integrationsrahmen eine vollständige Eigentumserklärung je Feld als Annahmebedingung des Manifests. Gegenprobe: einen Integrationsrahmen benennen, der ein Manifest ohne Feldeigentum ablehnt.
8. Nach derzeitigem Kenntnisstand zeigt kein vergleichbares System kaufmännische Folgen einer Rechtevergabe in der Vorschau vor der Bestätigung (INV-29). Gegenprobe: ein System benennen, das vor dem Setzen eines Schalters die dadurch ausgelöste Lizenzzuweisung im Fremdsystem nennt.

Diese Liste behauptet keine neue Technik. Sie behauptet eine Kombination und eine Strenge. Das ist die schwächere Neuheitsbehauptung, und sie ist hier so gemeint.

## 4.8 Was hier nicht neu ist, sondern nur konsequenter umgesetzt wird

| Gedanke | Herkunft | Verschärfung in diesem Entwurf |
|---|---|---|
| Deklarierter Sollzustand mit Regelkreis und Idempotenz | Kubernetes-Klasse, Konfigurationsverwaltung | Gilt ausnahmslos, auch für Notfallbehebungen; es gibt keinen Schreibweg am Regelkreis vorbei (INV-03) |
| Berechnung vor Anwendung | Konfigurationsverwaltung | Nebenwirkungsfreiheit ist Vertragsbestandteil jedes Konnektors und wird im Vertragstest geprüft (INV-08) |
| Verzeichnis als Systemkern mit Versorgung | Univention Corporate Server, kommerzielle Verzeichnisklasse | Versorgung ist ein eigenes Objekt mit Zustand je Zielsystem statt eines Nebeneffekts |
| Eigene Zertifizierungsstelle mit automatischer Ausstellung | FreeIPA, kommerzielle Verzeichnisklasse | Wurzel offline, Ausstellung als Ereignis im replizierten Protokoll, Zwischen-CA je Mandant (INV-22) |
| Katalog mit automatischem Proxy und Zertifikat | YunoHost, Cloudron | Der Proxyeintrag ist nicht nur automatisch, sondern nicht editierbar, und er trägt seine Quelle (INV-09) |
| Beitritt über eine kopierbare Beitrittsinformation | Proxmox VE | Passwortauthentisierter Schlüsselaustausch aus einem vorlesbaren Code, Einmaligkeit und Kurzlebigkeit erzwungen (INV-27) |
| Quorumbasierte Kontrollebene mit ungerader Mitgliedszahl | Raft-basierte Systeme allgemein | Gerade Stimmzahlen werden von der Schnittstelle abgelehnt, nicht nur abgeraten (INV-05) |
| Terminalfreie Bedienung breiten Funktionsumfangs | Synology DSM | Keine Fehlermeldung darf als einzige Lösung auf die Kommandozeile verweisen (INV-17) |
| Bereichstrennung über Namensräume und Rechte | Kubernetes-Klasse | Mandantenbezug ist Pflichtfeld, und die Datenzugriffsschicht lehnt Abfragen ohne Mandantenprädikat ab (INV-19) |
| Zugriff als Freigabe statt als Konfiguration | Sandstorm | Die Zuweisung ist der einzige Auslöser jeder Versorgungswirkung |
| ZFS, WireGuard, Envoy, Knot, Podman, FreeRADIUS, Ubuntu-Treiber | Jeweils eigene Projekte | Unverändert übernommen; der Beitrag liegt in der Ableitung ihrer Konfiguration, nicht in ihrem Ersatz |

## 4.9 Warum eine Kombination bestehender Werkzeuge die Aufgabe nicht löst

Die Begründung wird nicht über Funktionslücken geführt. Für jede einzelne Dimension existiert ein Werkzeug, das sie erbringt; eine hinreichend fachkundige Person kann sie zusammensetzen. Die Begründung führt über drei Kosten, die bei jeder Zusammensetzung entstehen und die mit dem Hinzufügen weiterer Werkzeuge wachsen: Integrationskosten, fehlende gemeinsame Datenbasis und Bedienlast.

### 4.9.1 Integrationskosten

```
Modell M-04-1 (Kopplungen)
  n  = Zahl der Konfigurationsflächen
  Ohne gemeinsame Wahrheitsquelle, paarweise Abgleichung:
        K(n) = n * (n - 1) / 2
  Mit genau einer Wahrheitsquelle, sternförmig:
        S(n) = n
  Annahme: n = 15 (Referenzfall A, siehe Kapitel 02)
        K(15) = 15 * 14 / 2 = 105
        S(15) = 15
        Verhältnis 105 / 15 = 7
```

Die Zahl 105 wird in der Praxis nie gebaut. Ein Betreiber baut die wenigen Kopplungen, die sich lohnen, und pflegt die übrigen Tatsachen doppelt. Damit verschwinden die Integrationskosten nicht, sondern wechseln die Form: Aus 105 minus der gebauten Kopplungen wird Handarbeit. Das ist der eigentliche Befund, und er ist unabhängig davon, wie viele Kopplungen tatsächlich entstehen.

```
Modell M-04-2 (Bruchlast an Kopplungen)
  Annahme (übernommen aus der Herleitung zu K-22):
        1,5 schnittstellenrelevante Veröffentlichungen je Fremdsystem und Jahr
        davon 20 % brechend
        =>  0,3 brechende Änderungen je Kopplung und Jahr
  Annahme: 8 gebundene Fremdsysteme
        Brüche je Jahr = 8 * 0,3 = 2,4
  Annahme: 1 Personentag je Bruchbehebung
        Aufwand = 2,4 Personentage je Jahr
  Deutung: Die Zahl der Brüche ist in beiden Architekturen gleich.
           Verschoben werden Trägerschaft und Entdeckungszeit:
           Im Selbstbau trägt der Betreiber den Bruch, und er entdeckt ihn,
           wenn etwas nicht funktioniert. Im Konnektormodell trägt ihn der
           Anbieter des Manifests, und healthcheck entdeckt ihn.
```

Das ist ein Modell, keine Messung. Es rechtfertigt keine eigene Distribution: 2,4 Personentage je Jahr sind kein Argument. Das Argument liegt in der Entdeckungszeit: Ohne vertraglich zugesicherten Gesundheitstest bleibt eine gebrochene Kopplung unbemerkt, bis eine Person einen Zugang vermisst, und der Zeitraum dazwischen ist der Schaden. Zugleich entsteht damit eine Abhängigkeit vom Pfleger der Katalogeinträge und Konnektormanifeste, die im Selbstbau nicht besteht; diese Abhängigkeit ist eine Kostenverlagerung und keine Kostenbeseitigung, und [Kapitel 23](23-oekonomie-roadmap.md) muss sie tragen.

### 4.9.2 Fehlende gemeinsame Datenbasis

```
Modell M-04-3 (Mehrfachpflege und Fehlerwirkung)
  Annahmen:
        500 Personen, jährliche Fluktuation 15 %
        => 75 Eintritte + 75 Austritte = 150 Personenereignisse je Jahr
        eine Person ist in 5 Flächen zu führen
        4 min Bearbeitungszeit je Pflegevorgang
        Fehlerwahrscheinlichkeit p = 0,02 je Pflegevorgang

  Ohne gemeinsame Datenbasis:
        Pflegevorgänge = 150 * 5 = 750 je Jahr
        Zeit           = 750 * 4 min = 3.000 min = 50 h je Jahr
        Fehler         = 750 * 0,02 = 15 je Jahr, je Fehler 1 betroffenes System

  Mit einer Wahrheitsquelle:
        Pflegevorgänge = 150 je Jahr, die übrigen 600 werden abgeleitet
        Zeit           = 150 * 4 min = 600 min = 10 h je Jahr
        Fehler         = 150 * 0,02 = 3 je Jahr, je Fehler 5 betroffene Systeme
        falsch versorgte Zielsysteme = 3 * 5 = 15 je Jahr
```

Die erwartete Zahl falsch versorgter Zielsysteme ist in beiden Architekturen gleich: 15 je Jahr. Das ist kein Rechenfehler, sondern die ehrliche Auskunft des Modells. Eine einzige Quelle beseitigt den Eingabefehler nicht; sie verbreitert seine Wirkung, weil ein falscher Wert in fünf Systeme statt in eines läuft. Der Unterschied liegt in drei anderen Größen: Die Korrektur kostet einen Vorgang statt fünf, die Wirkungsvorschau nennt vor der Bestätigung die fünf betroffenen Systeme, und der Regelkreis hält den korrigierten Wert anschließend. Das ist eine benennbare Schwäche der Einquellenarchitektur, und sie gehört an diese Stelle und nicht in eine Fußnote.

```
Modell M-04-4 (Vollständigkeit des Rückbaus beim Ausscheiden)
  Annahmen: k = 5 Zielsysteme, q = 0,05 Wahrscheinlichkeit, dass ein
            einzelnes Zielsystem übersehen oder unvollständig bereinigt wird
        P(vollständig) = (1 - q)^k = 0,95^5 = 0,7738
        => 22,6 % der Austritte hinterlassen mindestens einen aktiven Zugang
```

Auch hier verspricht der Entwurf keine Null. Er senkt q nicht auf 0, sondern ersetzt den Zustand "unbekannt" durch den Zustand "benannter Rest": Ein Vorgang endet nicht als "fertig", solange ein Zielsystem aussteht (INV-12). Der Gewinn ist Sichtbarkeit, nicht Vollständigkeit. Wer daraus eine Vollständigkeitszusage macht, hat die Zusage bereits gebrochen.

Die dritte Folge der fehlenden gemeinsamen Datenbasis ist nicht rechenbar und trotzdem die schwerste: Es gibt keinen Ort, an dem die Frage "was passiert, wenn ich diesen Schalter umlege" beantwortet werden kann, weil keine der Flächen die Wirkungen der anderen kennt. Eine Wirkungsvorschau über n Systeme setzt ein gemeinsames Objektmodell voraus; ohne dieses ist sie nicht nachrüstbar, sondern nur simulierbar, und eine Simulation ohne Modell ist eine Vermutung.

### 4.9.3 Bedienlast

Aufgabe: eine Person anlegen, ihr eine Mailadresse in einem extern gehosteten Postfachsystem geben und ihr Zugang zum Ticketsystem verschaffen. Gezählt werden Entscheidungen im Sinne von INV-14, also Pflichtfelder und Auswahlen ohne mögliche Vorbelegung; Bestätigungen und optionale Felder zählen nicht.

| Fläche | Pflichtangaben | Entscheidungen |
|---|---|---|
| Verzeichnisdienst | Anzeigename, Anmeldename, Organisationseinheit, Erstkennwort | 4 |
| Identitätsanbieter | Gruppenzuordnung, Anwendungszuweisung | 2 |
| Postfachanbieter | Domäne, lokaler Teil, Lizenzplan | 3 |
| Ticketsystem | Rolle, Warteschlangenzuordnung | 2 |
| **Summe Kombination** | **4 Oberflächen** | **11** |
| Aufgabe dieses Dokuments | Nutzer anlegen 2, Mail hinzufügen 2 | **4** |

```
Modell M-04-5 (Fehlerfreie Erledigung)
  Annahme: p = 0,02 Fehlerwahrscheinlichkeit je Entscheidung
        P(fehlerfrei) = (1 - p)^k
        Kombination:  0,98^11 = 0,8007  =>  Fehlerrate 19,93 %
        Ein Bedienweg: 0,98^4  = 0,9224  =>  Fehlerrate  7,76 %
        Verhältnis der Fehlerraten = 19,93 / 7,76 = 2,57
```

Die Zeitersparnis ist gering, das Verhältnis der Fehlerraten ist es nicht. Entscheidend ist aber eine Größe, die in keiner dieser Rechnungen vorkommt: das Vorwissen, welche der vier Oberflächen welche Tatsache hält. Dieses Wissen ist der eigentliche Zugangsschutz einer Kombinationsarchitektur, es ist nicht dokumentierbar ohne zu veralten, und es lässt sich durch kein weiteres Werkzeug verringern, sondern nur durch das Entfernen von Flächen. Genau das ist die Aussage von [Kapitel 02](02-problemstellung.md), und sie ist der Grund, warum die Lösung an der Zahl der Flächen ansetzt und nicht an ihrer Bedienbarkeit.

### 4.9.4 Warum auch ein Aufsatz auf eine bestehende Kombination die Aufgabe nicht löst

Der naheliegende Ausweg ist eine gemeinsame Oberfläche über vorhandenen Systemen. Er scheitert an einer einzigen Bedingung: Solange die Verwaltungsoberflächen der unterlagerten Systeme erreichbar bleiben, existieren zwei Schreibwege, und INV-02 ist verletzt. Ein Aufsatz, der diese Oberflächen nicht abschalten kann, muss entweder Handänderungen dulden, womit er seine eigene Datenbasis aufgibt, oder sie überschreiben, ohne die Ausgangslage zu kennen, womit er Daten vernichtet. Deshalb sieht dieser Entwurf vor, dass Verwaltungsoberflächen der Kernkomponenten abgeschaltet sind, während Fremdoberflächen von Katalogeinträgen ausdrücklich Fremdoberflächen bleiben (INV-30); der Unterschied ist die Grenzziehung je Katalogeintrag und nicht eine allgemeine Haltung.

### 4.9.5 Sicherheitsfolgen der Kombinationsarchitektur

Jede zusätzliche Kopplung ist ein Geheimnis mehr, ein Netzpfad mehr, ein Datenformat mehr und eine Fehlerausgabe mehr. Die Zahl der Geheimnisse wächst mit der Zahl der Kopplungen, und im Selbstbau liegen sie typischerweise in Skripten, Umgebungsvariablen und Ablaufplänen ohne Wechselfrist. Der Entwurf dieses Dokuments beantwortet dieselbe Fläche mit festen Praktiken, die in [Kapitel 09](09-konnektoren.md) und [Kapitel 20](20-sicherheit.md) ausgeführt sind und hier nur benannt werden:

| Gegenstand | Festlegung |
|---|---|
| Netzrechte eines Konnektors | Ausgangs-Positivliste aus der Konnektorbindung erzeugt, eigener Netznamensraum, kein Zugang zur Schnittstelle der Kontrollebene über das Netz (INV-21) |
| Geheimnisse | Nur schreibbar, nie lesbar; Ausgabe an Konnektorprozesse als kurzlebige, auftragsgebundene Referenz; kein privater Schlüssel verlässt seinen Knoten (INV-20) |
| Vergleich von Geheimnissen und Kopplungscodes | Laufzeitkonstanter Vergleich, Einmaligkeit, Gültigkeit 15 min, Vernichtung nach 5 Fehlversuchen (INV-27) |
| Aufrufe an Fremdsysteme | Parametrisierte Abfragen und typisierte Schnittstellenaufrufe; keine Zusammensetzung von Befehlszeilen aus Eingabewerten; keine dynamische Codeausführung in Manifesten |
| Eingaben am Rand | Schemavalidierung jeder eingehenden Nachricht an der Systemgrenze; Ablehnung vor jeder Verarbeitung |
| Vorgabezustand des Netzes | Default-Deny in jedem Fehlerzustand; abgeleitete Regelsätze werden atomar getauscht (INV-10) |
| Fehlerausgabe | Meldungen ohne Preisgabe von Endpunkten, Anmeldedaten, internen Pfaden oder Fremdsystemantworten im Rohtext |

Diese Praktiken sind in einer Kombinationsarchitektur nicht unmöglich; sie sind dort nur nicht erzwingbar, weil es keine Stelle gibt, die sie für alle Kopplungen durchsetzt.

## 4.10 Anforderungen

| ID | Anforderung | Quelle |
|---|---|---|
| **R-04-01** | Die Einordnung wird als versionierte Datei mit Stichtag geführt; jede Bewertungszelle trägt entweder eine Herkunftsangabe oder die Kennzeichnung "unsicher". Die Zahl der Zellen ohne beides ist 0. | Dokumentationsanforderung dieses Kapitels; folgt aus keiner Invariante |
| **R-04-02** | Kein Konsolentext, kein Katalogeintrag und keine Fehlermeldung enthält eine wertende Aussage über ein benanntes Fremdprodukt. Die Musterprüfung der Oberflächentexte auf Produktnamen in wertendem Kontext ergibt 0 Treffer. | INV-16, K-27 |
| **R-04-03** | Für jede der elf Dimensionen D01 bis D11 existiert mindestens ein Prüffall im Aufgabenkatalog, der die Dimension an der eigenen Installation beobachtbar macht. Die Abdeckung beträgt 11 von 11. | K-03, K-27 |
| **R-04-04** | Ein bestehendes Fremdverzeichnis ist als Konnektorbindung führbar, deren Manifest je Feld das Eigentum deklariert; ein Abgleichlauf gegen ein Testverzeichnis ändert 0 fremdbesessene Felder. | INV-13 |
| **R-04-05** | Für die Klassen "LDAP-Verzeichnis", "externer Postfachanbieter" und "bestehende autoritative Domäne" existiert je ein Übernahmevorgang, der vor dem ersten Schreibzugriff die vollständige Liste der zu übernehmenden Objekte mit Objektzahl zeigt. | INV-08, INV-12 |
| **R-04-06** | Keine Einrichtung verlangt die Abschaltung eines Fremdsystems als Voraussetzung. Die Einrichtung jeder mitgelieferten Konnektorbindung gelingt bei laufendem Fremdsystem, und der Parallelbetrieb wird je Bindung als Zustand angezeigt. | INV-30 |
| **R-04-07** | Verwaltungsoberflächen der Kernkomponenten sind nicht erreichbar; Fremdoberflächen von Katalogeinträgen sind erreichbar und in der Konsole verlinkt. Der Erreichbarkeitstest auf die Verwaltungsendpunkte der Kernkomponenten ergibt 0 erreichbare Oberflächen. | INV-02, INV-30 |
| **R-04-08** | Alle zur Erfüllung des Aufgabenkatalogs nötigen Bestandteile stehen unter einer quelloffenen Lizenz. Der Abgleich des Aufgabenkatalogs gegen das Lizenzinventar ergibt 0 Funktionen, die einer nicht quelloffenen Ausgabe vorbehalten sind. | Dimension D11; folgt aus keiner Invariante |
| **R-04-09** | Die Stückliste je Systemabbild weist für jeden Fremdbestandteil Lizenz und Herkunft aus, sowohl im SPDX- als auch im CycloneDX-Format; ein Bestandteil ohne Lizenzangabe bricht den Bau. | KANON.md, Signaturverfahren der Lieferkette |
| **R-04-10** | Eine Domäne ist als "extern geführt" markierbar. Eine Veröffentlichung unter einer so markierten Domäne endet im Zustand "teilweise fehlgeschlagen" mit benanntem Rest und zeigt den vollständigen einzutragenden Eintragstext, statt still zu scheitern. | INV-12; [Kapitel 12](12-dns-netzwerk.md) |
| **R-04-11** | Der Sollzustandsexport enthält alle Objekte in einem dokumentierten, ohne Atrium lesbaren Format; die Formatbeschreibung liegt demselben Ausgabestand bei. Ein Prüfprogramm außerhalb von Atrium liest den Export vollständig und zählt dieselbe Objektzahl. | INV-02, K-12 |
| **R-04-12** | Das Entfernen einer Konnektorbindung verlangt eine ausdrückliche Entscheidung zwischen "Fremdkonten deaktivieren" und "Fremdkonten belassen"; die Schnittstelle lehnt das Entfernen ohne diese Angabe ab. | INV-11; Objektmodell Konnektorbindung |
| **R-04-13** | Jede Konnektorbindung beantwortet `healthcheck` und zeigt den Zeitpunkt der letzten erfolgreichen Beobachtung. Eine Bindung ohne Beobachtung innerhalb des doppelten Abgleichintervalls wird als gestört angezeigt. | INV-28, K-16 |
| **R-04-14** | Für jedes in Abschnitt 4.4 mit "unsicher" bewertete Feld existiert ein benannter Klärungsauftrag mit Verantwortlichem und Frist. Die Zahl unsicherer Felder ohne Klärungsauftrag ist 0. | Dokumentationsanforderung dieses Kapitels; folgt aus keiner Invariante |

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Die Datei trägt einen Stichtag, und die Auszählung der Bewertungszellen ergibt 0 Zellen ohne Herkunftsangabe und ohne Unsicherheitskennzeichnung | R-04-01 | Maschinelle Auszählung der Tabelle in Abschnitt 4.4 gegen die Liste in Abschnitt 4.5 |
| Die Musterprüfung aller Oberflächentexte, Katalogtexte und Meldungstexte auf die in Abschnitt 4.3 genannten Produktnamen ergibt 0 Treffer in wertendem Kontext | R-04-02 | Textprüfung im Bau gegen eine Namensliste und eine Liste wertender Muster |
| Der Aufgabenkatalog enthält je Dimension mindestens einen Prüffall; die Abdeckungsmatrix ist vollständig | R-04-03 | Abdeckungsprüfung Dimension gegen Prüffall im Bau; eine Lücke bricht den Bau |
| Ein Abgleichlauf gegen ein Testverzeichnis mit fremdbesessenen Feldern ändert 0 dieser Felder und meldet jede Abweichung einzeln | R-04-04 | Konnektor-Vertragstest mit vorbelegtem Testverzeichnis |
| Jeder der drei Übernahmevorgänge zeigt vor dem ersten Schreibzugriff eine Objektliste mit Zahl; ein Schreibzugriff vor der Freigabe bricht den Test | R-04-05 | Vertragstest mit Beobachtung des Fremdsystems auf Unverändertheit nach `plan` |
| Alle mitgelieferten Konnektorbindungen lassen sich bei laufendem Fremdsystem einrichten; der Zustand "Parallelbetrieb" ist je Bindung sichtbar | R-04-06 | Einrichtungsdurchlauf gegen Testinstanzen aller mitgelieferten Bindungen |
| Ein Portscan und ein Abruf der bekannten Verwaltungspfade der Kernkomponenten liefern 0 erreichbare Verwaltungsoberflächen; jede in einer Produktgrenzdeklaration benannte Fremdoberfläche ist verlinkt | R-04-07 | Erreichbarkeitstest plus Abgleich der Produktgrenzdeklarationen (INV-30) |
| Der Abgleich jeder Katalogfunktion gegen das Lizenzinventar ergibt 0 Funktionen mit nicht quelloffenem Bestandteil | R-04-08 | Lizenzinventar aus der Stückliste, Abgleich im Bau |
| Jede Stückliste liegt in beiden Formaten vor und enthält für jeden Bestandteil Lizenz und Herkunft | R-04-09 | Vollständigkeitsprüfung der Stückliste im Bau |
| Eine Veröffentlichung unter einer als extern geführt markierten Domäne endet mit benanntem Rest und zeigt den vollständigen Eintragstext | R-04-10 | Vorgangstest mit extern geführter Testdomäne |
| Ein Prüfprogramm außerhalb von Atrium liest einen Export vollständig und zählt dieselbe Objektzahl wie die Schnittstelle | R-04-11 | Unabhängiger Leser gegen einen Export im Bau |
| Der Aufruf zum Entfernen einer Konnektorbindung ohne Angabe der Fremdkontenbehandlung wird abgelehnt | R-04-12 | Schnittstellentest mit fehlendem Pflichtfeld |
| Die Abschaltung eines Testfremdsystems führt innerhalb des doppelten Abgleichintervalls zur Anzeige "gestört" mit Beobachtungszeitpunkt | R-04-13 | Ausfallinjektion je Bindung mit Zeitmessung |
| Jedes unsichere Feld aus Abschnitt 4.5 ist einem Klärungsauftrag mit Verantwortlichem und Frist zugeordnet | R-04-14 | Abgleich der Unsicherheitsliste gegen die Vorgangsliste des Projekts |

## Offene Punkte

1. **Die Bewertung der Fremdsysteme ist nicht nachgeprüft.** Es existiert keine Testinstallation eines einzigen der fünfzehn bewerteten Systeme. Jede Zelle beruht auf öffentlich zugänglicher Kenntnis, und der Unterschied zwischen "die Dokumentation beschreibt es" und "es verhält sich so" ist genau der Unterschied, den dieses Dokument anderswo als entscheidend behandelt. Zu entscheiden ist, ob eine strukturierte Nachprüfung an Testinstallationen durchgeführt wird oder ob die Einordnung dauerhaft als Kenntnisstand mit Stichtag geführt bleibt.
2. **Die Asymmetrie zwischen Spezifikation und Produkt bleibt ungelöst.** Die letzte Zeile der Vergleichstabelle beschreibt einen Sollzustand, alle übrigen beschreiben ausgelieferte Produkte. Die Kennzeichnung in Abschnitt 4.4 mildert das, beseitigt es aber nicht, weil eine Tabelle stärker wirkt als der Text darunter. Zu entscheiden ist, ob die Zeile bis zur ersten Freigabe entfällt und der Vergleich rein verbal geführt wird.
3. **Kerberos fehlt und die Folgen sind nicht abgeschätzt.** Der Entwurf führt OIDC, LDAP nach RFC 4511 und Passkey-Verfahren, aber Kerberos nach RFC 4120 nicht als Kernfunktion. Für klassischen Domänenbeitritt von Arbeitsplätzen und für Dateidienste mit Einmalanmeldung ist das eine Lücke gegenüber FreeIPA und gegenüber der kommerziellen Verzeichnisklasse. Zu entscheiden ist zwischen Kerberos als Kernfunktion, Kerberos als Katalogeintrag und einer ausdrücklich erklärten Nichtunterstützung mit benannten Folgen.
4. **Fremdgeführte Identität steht in Spannung zu INV-02.** R-04-04 erlaubt ein Fremdverzeichnis als Eigentümer bestimmter Felder; INV-02 erklärt den Sollzustand zur einzigen Wahrheitsquelle. Die Auflösung über Feldeigentum ist formal sauber, aber nicht belegt, dass sie für die häufigsten Fälle trägt, insbesondere wenn das Fremdverzeichnis Gruppenmitgliedschaften führt, aus denen Zuweisungen folgen sollen. Zu entscheiden ist, welche Felder überhaupt fremdbesessen sein dürfen.
5. **Migrationspfade aus den bewerteten Systemen sind nicht entworfen.** Ohne Übernahmepfad ist diese Einordnung für Bestandsbetreiber folgenlos, und genau Bestandsbetreiber sind die Zielgruppe, für die der Vergleich geführt wird. R-04-05 fordert drei Klassen von Übernahmevorgängen, ohne deren Machbarkeit zu zeigen.
6. **Die Dimension D08 ist nicht vergleichbar messbar.** Eine Zahl von Katalogeinträgen sagt nichts über Integrationstiefe; ein Katalog mit hunderten Einträgen ohne Versorgung ist schlechter als zwanzig vollständig versorgte. Zu entscheiden ist, ob ein Tiefenmaß an die Stelle des Breitenmaßes tritt, etwa die Zahl der je Eintrag aus dem Objektmodell versorgten Objektarten.
7. **Vier Zeilen bewerten Produktklassen, keine Produkte.** Eine Klassenbewertung ist nur zulässig, wo die Klasse in der jeweiligen Dimension einheitlich ist, und bei den Heimserver-Oberflächen ist sie es nachweislich nicht. Zu entscheiden ist, ob die Klassen in benannte Vertreter aufgelöst werden; das macht die Einordnung angreifbarer und zugleich prüfbar.
8. **R-04-08 fordert Quelloffenheit ohne Lizenzwahl.** Die Wahl bestimmt, welche Fremdbestandteile kombinierbar bleiben, ob ein Anbieter eine abweichende Ausgabe erstellen darf und ob das Tragmodell aus [Kapitel 23](23-oekonomie-roadmap.md) überhaupt möglich ist. Solange sie offen ist, ist R-04-08 prüfbar formuliert, aber nicht erfüllbar, weil das Lizenzinventar keinen Sollwert hat.
9. **Die Einordnung veraltet planmäßig.** Jedes bewertete Produkt kann jede Dimension nachrüsten, und mehrere haben in der Vergangenheit ihre Architektur grundlegend geändert. Der Vergleich beschreibt deshalb keinen Vorsprung, sondern eine Momentaufnahme. Zu entscheiden ist, in welchem Rhythmus er neu erhoben wird und wer dafür verantwortlich ist.
