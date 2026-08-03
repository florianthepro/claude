# Stimmwerk – Ein-Datei-Version

Digitale Bürgerbeteiligung mit dem Personalausweis. Konzept und Fachlogik:
[whitepaper.md](whitepaper.md). Dieser Zweig (v4) besteht aus **einer einzigen
Datei**: `index.php` ist die gesamte Anwendung.

> **Testbetrieb:** Im Auslieferungszustand zeigt jede Seite ein Banner
> „Testbetrieb …“. Gesteuert über `show_test_banner` in der Konfiguration
> am Anfang von `index.php` (Standard: `true`).

## Installation

1. `index.php` in das Webverzeichnis hochladen (Hauptverzeichnis oder
   Unterordner – der Basispfad wird automatisch erkannt).
2. PHP **8.0 oder neuer** (Erweiterungen `pdo_sqlite`, `mbstring`; für die
   Schlüssel-Simulation `sodium` – alle drei sind Standard).
3. Seite aufrufen – fertig. Beim ersten Aufruf erzeugt die Datei selbst:
   - `data/` (SQLite-Datenbank, Server-Geheimnis, Logs, Zugriffssperre),
   - `.htaccess` (Routing + Schutz interner Dateien),
   - `robots.txt`.

Erscheint „Fast geschafft“: Ordner der `index.php` per FTP beschreibbar
machen (Rechte 755/775), neu laden. Ohne `.htaccess`-Unterstützung (z. B.
nginx) funktioniert alles weiter über automatisch erzeugte
`/index.php/...`-Links.

## Ausweis, profil.yaml, Bestätigung

- **Saubere Adressen:** Die Seite bleibt immer bei `/…` – eine direkt
  aufgerufene `/index.php` wird dauerhaft auf den sauberen Pfad umgeleitet.
  (Voraussetzung ist die mitgelieferte `.htaccess`-Umschreibung; für nginx
  eine gleichwertige try_files-Regel.)
- **Start:** Beim Sitzungsbeginn ein klares Icon und die Sprachwahl
  Deutsch/English (Textknöpfe, **keine Flaggen**); die Sprache gilt für die
  Sitzung und wird nirgends gespeichert. Danach ist **ohne Anmeldung nichts
  sichtbar** außer Anmeldung und Rechtlichem.
- **Eine Seite:** Thema einbringen und Suche öffnen je ein eigenes Fenster
  (Dialog); darunter Favoriten-Chips, die Gruppe „kürzlich abgestimmt (noch
  änderbar)“ und die Themenliste. Oben rechts steht nur „Abmelden“. Der
  Geltungsbereich wird zweistufig gewählt (Ebene → Land → Kreis), nicht als
  lange Liste. Einen Konto-Löschen-Bereich gibt es nicht mehr.
- **Nur autorisierte Ausweise – kein Fake-Zugang:** Anmelden kann sich nur,
  wessen **öffentlicher Schlüssel in der Allowlist** (`data/authorized_keys.yaml`)
  steht UND wer den passenden privaten Schlüssel besitzt (Signatur-Challenge).
  Ein Knopfdruck ohne Ausweis oder mit fremdem Schlüssel wird **abgewiesen**
  (fail-closed). Die Identität ist der öffentliche Schlüssel selbst – kein
  abgeleitetes Pseudonym.
- **Ausweis-Apps (AusweisApp & Nect Wallet):** Die Anmeldeseite bietet beide
  als Anbieter an (`eid_providers` in der Konfiguration). Jeder Anbieter hat
  eine `start`-URL: AusweisApp = eID-Server (TR-03130, erzeugt die tcTokenURL
  und öffnet die AusweisApp), Nect = Start-URL des Nect-Ident-Flows. Nach der
  Prüfung ruft der Anbieter `/eid/callback` zurück. Ist ein Anbieter nicht
  eingerichtet, meldet er sauber „nicht eingerichtet“ – **niemand kommt ohne
  echte Prüfung hinein** (fail-closed).
- **Zwei Test-Schalter (nur Entwicklung/Vorführung):** In der Konfiguration
  oben in `index.php`: `show_test_banner` (Testbetrieb-Banner) und
  `test_login`. Ist `test_login = true`, erzeugt der **eine** Anmelde-Knopf
  eine **zufällige, als gültig behandelte Sitzung**, und **alle
  Ausweis-Aufforderungen entfallen** (weder beim Anmelden noch bei Änderungen,
  auch kein Ablauf des Zeitfensters) – praktisch zum Testen mit vielen
  Stimmen. Im Echtbetrieb beide auf `false`: dann gilt die **strenge**
  Prüfung – ohne vorliegenden, zum Konto passenden Ausweis mit gültigem
  Umschlag wird jede Änderung abgewiesen.
- **Anmelde-Knopf genau einmal:** Auf der Anmeldeseite steht der
  Ausweis-Knopf **einmal, mittig** (die Kopfzeile zeigt dort keinen zweiten).
  Mit Web-NFC (Android/Chrome) wird der Knopf ausgeblendet – dann genügt das
  **Auflegen des Ausweises**; ohne NFC bleibt der eine Knopf.
- **Icons:** Neben dem SVG liefert die Datei echte **PNG- und ICO-Icons**
  (`/favicon.ico`, `/favicon.png`, `/apple-touch-icon.png`) – ohne
  Bildbibliothek erzeugt, da Safari/iOS keine SVG-Favicons anzeigt.
- **Modi (`eid_mode`):** `demo` – autorisierte Test-Ausweise per
  `php index.php issue-card` (Schlüssel → Allowlist, Ausgabe-Link
  `/claim/<handle>` lädt ihn in die Sitzung). `eid` – ausschließlich über die
  Ausweis-Apps oben. **Wichtig/ehrlich:** In Deutschland gibt es **keine** staatliche Liste
  aller Ausweis-Schlüssel und keine API, die sie liefert – echte Prüfung läuft
  über die BSI-Zertifikatskette (TR-03110) im eID-Server, und ein Perso-Chip
  ist nur mit der AusweisApp + PIN lesbar (kein Browser kann das). `sync-keys`
  ist der Anschlusspunkt für eine eigene Trust-Liste, kein Griff in ein
  Behördenregister.
  Die **profil.yaml** (Stimmen, Themen, Favoriten, Jury-Status) wird bei
  **jedem Seitenaufruf frisch** vom Server angefordert und nur im Browser
  zwischengehalten. Sie wird **an den öffentlichen Ausweis-Schlüssel
  verschlüsselt** (nur mit dem Ausweis lesbar) und mit einem **Server-Schlüssel
  signiert** (Manipulation erkennbar; öffentlicher Prüfschlüssel unter
  `/server.pub`). „Abmelden“ löscht die zwischengehaltene Datei.
- **Zeitfenster (TOTP-artig):** Der Anmeldenachweis gilt nur kurz
  (5-Minuten-Fenster, höchstens zwei Fenster); danach ist erneutes
  Anhalten nötig. Zu anderer Zeit entsteht ein anderer Nachweis.
- **Stimmabgabe (unabhängiger Vorgang):** Jede Änderung läuft über einen
  eigenen **versiegelten, zeitgebundenen Umschlag**: Die Karte versiegelt die
  Aktion, der Server öffnet mit dem öffentlichen Schlüssel und trägt das
  Ergebnis ein. Alte Umschläge verfallen; jedes Formular-Token ist einmalig.
- **Stimmen anonym & unverkettbar:** Die Stimmen-Tabelle enthält **keinen
  Ausweis-Bezug** – nur einen HMAC aus Thema + öffentlichem Schlüssel mit
  Server-Geheimnis. Ohne dieses Geheimnis lässt sich nicht rückschließen,
  welcher Ausweis was gewählt hat; Doppelstimmen bleiben ausgeschlossen.
- **Eigene Stimme 24 h änderbar:** Danach ist sie fest. Themen enden nach
  **Datum** oder bei erreichter **Stimmenzahl/Prozent-Zustimmung**; danach
  ist keine Abstimmung mehr möglich. Verfasser können ihr Thema **bearbeiten
  und löschen**.
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
- **Testbetrieb = nur das Banner:** Abläufe, NFC-Auslösung und
  Kryptographie sind bereits die aktiven Produktabläufe – auch mit echtem
  Personalausweis als NFC-Auslöser; serverseitig simuliert ist allein die
  Chip-Signatur (das PIN-geschützte Auslesen des Chips kann kein Browser,
  das übernimmt im Echtbetrieb die AusweisApp).
  Echtbetrieb: Karten-Block gegen die eID-Server-Anbindung (BSI TR-03130)
  tauschen; die AusweisApp übernimmt dann NFC samt PIN (Whitepaper Kap. 5).

## CLI (optional)

```bash
php index.php selftest   # Fachregeln automatisiert prüfen (80 Prüfungen)
php index.php cron       # Wartungslauf (sonst lazy bei Seitenaufrufen)
php index.php seed 400   # Demo-Pseudonyme + Zufallsstimmen (Vorführungen)
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
- Stimmen ohne Ausweis-Bezug gespeichert (HMAC-Marker) – nicht rückverfolgbar
- Sessions: HttpOnly, SameSite, ID-Rotation, Idle-/Absolut-Timeout
- Kernregeln zusätzlich als DB-Constraints (1 Thema/Tag, 1 Stimme/Thema,
  1 offene Meldung/Thema, 1 Jury-Sitz/Meldung)
- Jury-Losverfahren mit CSPRNG; Rate-Limits ohne Klar-IP-Speicherung
- Keine Klaridentitäten, keine Passwörter, keine externen Abhängigkeiten
