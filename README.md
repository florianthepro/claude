# Stimmwerk – Ein-Datei-Version (vf)

Digitale Bürgerbeteiligung mit dem Personalausweis. Konzept und Fachlogik:
[whitepaper.md](whitepaper.md). Dieser Zweig (**vf – version final**) besteht
aus **einer einzigen Datei**: `index.php` ist die gesamte Anwendung.

**Oberfläche:** schlichtes App-Design in der Formensprache von iOS/Signal –
graue Fläche, weiße Karten mit weichen Ecken, Haarlinien, ein blauer Akzent,
Systemschrift; Hell/Dunkel folgt automatisch der Systemeinstellung, Fenster
öffnen als Sheet von unten. Die Kopfzeile trägt keine Wortmarke.

> **Testbetrieb:** Im Auslieferungszustand läuft die Seite im Testmodus und
> zeigt oben ein Banner „Testbetrieb …“. Der Testmodus ist **keine Variable
> mehr**, sondern ein Zustand in der Datenbank und wird **über die Oberfläche
> beendet** (siehe unten). Das Banner selbst bleibt über `show_test_banner`
> in der Konfiguration steuerbar.

## Installation

1. `index.php` in das Webverzeichnis hochladen (Hauptverzeichnis oder
   Unterordner – der Basispfad wird automatisch erkannt).
2. PHP **8.0 oder neuer** (Erweiterungen `pdo_sqlite`, `mbstring`; für die
   Schlüssel-Simulation `sodium` – alle drei sind Standard).
3. Seite aufrufen – fertig. Beim ersten Aufruf erzeugt die Datei selbst:
   - `data/` (SQLite-Datenbank, Server-Geheimnis, Logs, Zugriffssperre),
   - `.htaccess` (Routing + Schutz interner Dateien),
   - `robots.txt`.

Mehr ist nicht nötig: **Apache + diese Datei**. Die Ausweis-App wird direkt
angesprochen (siehe unten) – ohne zusätzliche Pakete, Dienste oder
Konfiguration.

Erscheint „Fast geschafft“: Ordner der `index.php` per FTP beschreibbar
machen (Rechte 755/775), neu laden. Ohne `.htaccess`-Unterstützung (z. B.
nginx) funktioniert alles weiter über automatisch erzeugte
`/index.php/...`-Links.

## Testmodus (Auslieferungszustand) und Echtbetrieb

- **Beim ersten Start ist der Testmodus aktiv.** Die Anmeldeseite zeigt dann
  **genau einen Knopf** („Test-Anmeldung starten“): Er erzeugt eine zufällige,
  als gültig behandelte Sitzung, damit sich die Seite ohne Ausweis vorführen
  lässt. Alle Ausweis-Aufforderungen bei Änderungen entfallen in diesem Modus.
- **Beenden über die Oberfläche:** Angemeldet steht oben rechts der Chip
  „Testmodus“. Er öffnet ein Fenster mit Erklärung und Bestätigungshaken;
  „Testmodus beenden“ löscht **alle bis dahin angelegten Themen, Stimmen,
  Favoriten, Meldungen, Jury-Sitze, Konten und Test-Ausweise** (inklusive
  `data/authorized_keys.yaml`) und schaltet dauerhaft in den Echtbetrieb.
  Kategorien und das System-Konto bleiben. Der Schritt ist nicht umkehrbar.
- **Danach gilt strikt:** Die Anmeldeseite bietet nur noch die Ausweis-Apps
  an. Es gibt **keinen Knopf mehr**, der ohne Ausweis anmeldet – ein POST auf
  die Anmelde-Route läuft ins Leere (fail-closed).

## Ausweis, profil.yaml, Bestätigung

- **Saubere Adressen:** Die Seite bleibt immer bei `/…` – eine direkt
  aufgerufene `/index.php` wird dauerhaft auf den sauberen Pfad umgeleitet.
  (Voraussetzung ist die mitgelieferte `.htaccess`-Umschreibung; für nginx
  eine gleichwertige try_files-Regel.)
- **Start:** Beim Sitzungsbeginn ein klares Icon und die Sprachwahl
  **Deutsch/English mit Flaggen**; die Sprache gilt für die Sitzung und wird
  nirgends gespeichert. Danach ist **ohne Anmeldung nichts sichtbar** außer
  Anmeldung und Rechtlichem.
- **Eine Seite:** Thema einbringen und Suche öffnen je ein eigenes Fenster
  (Dialog); darunter Favoriten-Chips, die Gruppe „kürzlich abgestimmt (noch
  änderbar)“ und die Themenliste. Oben rechts steht nur „Abmelden“ (im
  Testmodus zusätzlich der Chip „Testmodus“). Der Geltungsbereich wird
  zweistufig gewählt (Ebene → Land → Kreis), nicht als lange Liste.
- **AusweisApp direkt integriert (BSI TR-03124):** Der Knopf „Mit AusweisApp
  anmelden“ leitet den Browser auf die Aktivierungsadresse des eID-Clients
  (`http://127.0.0.1:24727/eID-Client?tcTokenURL=…`). Die AusweisApp – am PC
  wie am Smartphone – fängt diese Adresse ab und holt das **tcToken** unter
  `/eid/tctoken` ab. Dieser Teil braucht **keine Konfiguration**.
  Weil die App das Token als eigener HTTP-Client **ohne Browser-Cookie** holt,
  verbindet ein Einmal-Nonce in der tcTokenURL beide Seiten (10 Minuten
  gültig, Tabelle `eid_flows`).
- **Was danach kommt, braucht einen eID-Server:** Das Auslesen des Chips läuft
  nach TR-03130 über einen eID-Server, den nur ein Betreiber mit
  **Berechtigungszertifikat des BVA** führen darf. Ist `eid_server_url`
  gesetzt, fordert `/eid/tctoken` dort per `useID` eine Sitzung an und liefert
  ServerAddress/SessionIdentifier/RefreshAddress an die App. Ist er **nicht**
  gesetzt, liefert das tcToken bewusst nur eine `CommunicationErrorAddress`:
  Die AusweisApp bricht sauber ab, der Browser kommt zurück – **angemeldet
  wird niemand** (fail-closed).
- **Nect Wallet** bleibt als zweiter Anbieter über `eid_providers[nect].start`
  konfigurierbar; ohne Eintrag meldet er „nicht eingerichtet“.
- **Nur autorisierte Ausweise – kein Fake-Zugang:** Anmelden kann sich nur,
  wessen **öffentlicher Schlüssel in der Allowlist** (`data/authorized_keys.yaml`)
  steht UND wer den passenden privaten Schlüssel besitzt (Signatur-Challenge).
  Die Identität ist der öffentliche Schlüssel selbst – kein abgeleitetes
  Pseudonym.
- **Icons:** Neben dem SVG liefert die Datei echte **PNG- und ICO-Icons**
  (`/favicon.ico`, `/favicon.png`, `/apple-touch-icon.png`) – ohne
  Bildbibliothek erzeugt, da Safari/iOS keine SVG-Favicons anzeigt.
- **Modi (`eid_mode`):** `demo` – autorisierte Test-Ausweise per
  `php index.php issue-card` (Schlüssel → Allowlist, Ausgabe-Link
  `/claim/<handle>` lädt ihn in die Sitzung). `eid` – ausschließlich über die
  Ausweis-Apps oben. **Wichtig/ehrlich:** In Deutschland gibt es **keine**
  staatliche Liste aller Ausweis-Schlüssel und keine API, die sie liefert –
  echte Prüfung läuft über die BSI-Zertifikatskette (TR-03110) im eID-Server,
  und ein Perso-Chip ist nur mit der AusweisApp + PIN lesbar (kein Browser
  kann das). `sync-keys` ist der Anschlusspunkt für eine eigene Trust-Liste,
  kein Griff in ein Behördenregister.
- **profil.yaml:** Stimmen, Themen, Favoriten und Jury-Status werden bei
  **jedem Seitenaufruf frisch** vom Server angefordert und nur im Browser
  zwischengehalten. Die Datei wird **an den öffentlichen Ausweis-Schlüssel
  verschlüsselt** (nur mit dem Ausweis lesbar) und mit einem **Server-Schlüssel
  signiert** (Manipulation erkennbar; öffentlicher Prüfschlüssel unter
  `/server.pub`). „Abmelden“ löscht die zwischengehaltene Datei.
- **Zeitfenster (TOTP-artig):** Der Anmeldenachweis gilt nur kurz
  (5-Minuten-Fenster, höchstens zwei Fenster); danach ist erneutes
  Auflegen nötig. Zu anderer Zeit entsteht ein anderer Nachweis.
- **Stimmabgabe (unabhängiger Vorgang):** Jede Änderung läuft über einen
  eigenen **versiegelten, zeitgebundenen Umschlag**: Die Karte versiegelt die
  Aktion, der Server öffnet mit dem öffentlichen Schlüssel und trägt das
  Ergebnis ein. Alte Umschläge verfallen; jedes Formular-Token ist einmalig.
- **Stimmen anonym & unverkettbar:** Die Stimmen-Tabelle enthält **keinen
  Ausweis-Bezug** – nur einen HMAC aus Thema + öffentlichem Schlüssel mit
  Server-Geheimnis. Ohne dieses Geheimnis lässt sich nicht rückschließen,
  welcher Ausweis was gewählt hat; Doppelstimmen bleiben ausgeschlossen.
- **Eigene Stimme 24 h änderbar:** Danach ist sie fest. Verfasser können ihr
  Thema **bearbeiten und löschen**.
- **Ende der Abstimmung – Datum, Zielwert oder beides:** Im Themenformular
  sind zwei Haken ankreuzbar: „an einem Datum“ (Datumsfeld) und „bei
  erreichter Stimmenzahl“ (Zahl + Einheit *Stimmen* oder *% der Ausweise*).
  Beides gemeinsam ist erlaubt – dann endet die Abstimmung, **was zuerst
  eintritt**. Prozentangaben werden beim Anlegen in eine absolute Zahl
  umgerechnet (mindestens 10 Stimmen).
- **Melden nur bei Gesetzesverstoß:** Der einzige Meldegrund ist der
  Verstoß gegen ein Gesetz. Beim Melden führt ein Suchfeld (Schlagwort
  oder Paragraphennummer) zum eingebauten Gesetzesregister; der gewählte
  Paragraph wird **1:1 zitiert** und der Jury wortgleich vorgelegt.
  Die Gesetzestexte sind vor einem Echtbetrieb wortgleich gegen
  gesetze-im-internet.de abzugleichen.
- **Geltungsbereich ohne Freitext:** Themen und Filter nutzen eine
  hierarchische Auswahl Deutschland → Bundesland → Landkreis/kreisfreie
  Stadt (eingebaute Liste, 16 Länder, rund 400 Kreise) – vor Echtbetrieb
  gegen das amtliche Verzeichnis (ARS/Destatis) abgleichen.

## CLI (optional)

```bash
php index.php selftest   # Fachregeln automatisiert prüfen (97 Prüfungen)
php index.php cron       # Wartungslauf (sonst lazy bei Seitenaufrufen)
php index.php seed 400   # Demo-Stimmen (anonym wie im Echtbetrieb)
php index.php jurysim    # Demo-Jury stimmt in laufenden Prüfungen ab
php index.php issue-card 3  # 3 autorisierte Demo-Ausweise ausgeben (+ Claim-Links)
php index.php sync-keys      # Allowlist aus konfigurierter Trust-Liste aktualisieren
php -S 127.0.0.1:8080 index.php   # lokale Demo ohne Webserver
```

## Sicherheitsmerkmale (Auszug)

- Ausschließlich Prepared Statements; durchgängiges Output-Escaping
- CSP `default-src 'none'` ohne `unsafe-inline` (CSS/JS liefert die Datei
  selbst als eigene Routen aus), restriktive Header, HSTS bei HTTPS
- Einmal-Token für jede POST-Anfrage (kein Replay, deckt CSRF ab);
  zeitgebundene versiegelte Umschläge für jede Änderung; Profil an den
  öffentlichen Schlüssel verschlüsselt und servergegengezeichnet
- Ausweis-Vorgänge über Einmal-Nonce gebunden, 10 Minuten gültig, danach
  automatisch verworfen; Ratenbegrenzung auch auf `/eid/start`
- Stimmen ohne Ausweis-Bezug gespeichert (HMAC-Marker) – nicht rückverfolgbar
- Sessions: HttpOnly, SameSite, ID-Rotation, Idle-/Absolut-Timeout
- Kernregeln zusätzlich als DB-Constraints (1 Thema/Tag, 1 Stimme/Thema,
  1 offene Meldung/Thema, 1 Jury-Sitz/Meldung)
- Jury-Losverfahren mit CSPRNG; Rate-Limits ohne Klar-IP-Speicherung
- Keine Klaridentitäten, keine Passwörter, keine externen Abhängigkeiten
