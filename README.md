# Stimmwerk – Installation durch Hochladen

Digitale Bürgerbeteiligung mit dem Personalausweis. Konzept und Fachlogik:
[whitepaper.md](whitepaper.md). Dieser Zweig (v2) ist so aufgebaut, dass
**Hochladen genügt** – kein Terminal, kein Installer, keine Datenbank-Einrichtung.

> **Testbetrieb:** Im Auslieferungszustand zeigt jede Seite ein Banner
> „Testbetrieb / Entwicklungsversion …“. Gesteuert über `show_test_banner`
> in `config/config.php` (Standard: `true`).

## Installation (Webhosting mit PHP)

1. Diesen Zweig als ZIP herunterladen und entpacken.
2. Den **Inhalt** des entpackten Ordners in das Webverzeichnis hochladen
   (z. B. `htdocs/`, `public_html/` oder `www/`) – entweder direkt ins
   Hauptverzeichnis oder in einen Unterordner (beides funktioniert, der
   Basispfad wird automatisch erkannt).
3. Im Hosting-Verwaltungsbereich **PHP 8.2 oder neuer** einstellen
   (Erweiterungen `pdo_sqlite` und `mbstring` sind praktisch überall Standard).
4. Seite im Browser aufrufen – fertig. Beim ersten Aufruf legt die Anwendung
   Datenbank, Kategorien und die neutralen Startthemen selbst an.

Falls stattdessen eine Hinweisseite „Fast geschafft“ erscheint: dem dortigen
Hinweis folgen (meist: Ordner `data/` per FTP beschreibbar machen, Rechte
755/775), dann neu laden.

**Voraussetzungen an den Server:** Apache oder LiteSpeed mit `.htaccess`-
Unterstützung (bei PHP-Webhosting der Normalfall). Für nginx müssen alle
nicht existierenden Pfade auf `index.php` geleitet und die Verzeichnisse
`src/`, `config/`, `db/`, `data/`, `bin/` gesperrt werden.

## Anmeldung im Testbetrieb

„Ausweis anhalten“ → „Neue Testkarte erzeugen und anmelden“. Die angezeigte
Test-Kennung ersetzt die physische Karte (gleiche Kennung = gleiches Konto)
und wird für die nächste Anmeldung benötigt. Der echte eID-Ablauf
(AusweisApp/NFC, BSI TR-03130) ist im Whitepaper, Kapitel 5, beschrieben und
über `src/Eid/` als Anbindungspunkt vorbereitet.

## Optional

- **Cron:** nicht erforderlich (Zustandswechsel laufen gedrosselt bei
  Seitenaufrufen mit). Wer möchte: minütlich `php bin/cron.php`.
- **Demo-Daten** für Vorführungen (nur CLI, nur Mock-Modus):
  `php bin/seed_demo.php 400` und `php bin/simulate_jury.php`.
- **Selbsttest** der Fachregeln: `php bin/selftest.php`.
- **Lokale Demo ohne Webserver:** `php -S 127.0.0.1:8080 router.php`.

## Aufbau

```
index.php        # Front-Controller (einziger öffentlicher Einstieg)
.htaccess        # Routing + Zugriffssperren
assets/          # CSS, JS, Favicon (einzige weitere öffentliche Dateien)
src/             # Anwendung (per .htaccess gesperrt)
config/          # Konfiguration (gesperrt)
db/schema.sql    # Schema (gesperrt)
data/            # Laufzeitdaten: SQLite, secret.key, Logs (gesperrt)
bin/             # CLI-Werkzeuge (gesperrt)
```

## Sicherheitsmerkmale (Auszug)

- Ausschließlich Prepared Statements (PDO), keinerlei String-SQL mit Nutzerdaten
- Durchgängiges Output-Escaping; CSP `default-src 'none'` ohne `unsafe-inline`,
  keine Inline-Skripte/-Styles, keine externen Ressourcen
- Zentrale CSRF-Prüfung für jede POST-Anfrage (`hash_equals`)
- Sessions: HttpOnly, SameSite, ID-Rotation, Idle-/Absolut-Timeout (Terminals)
- Kernregeln zusätzlich als DB-Constraints (1 Thema/Tag, 1 Stimme/Thema,
  1 offene Meldung/Thema, 1 Jury-Sitz/Meldung)
- Jury-Losverfahren mit CSPRNG (`random_int`, Fisher-Yates)
- Rate-Limits je Pseudonym bzw. gehashter Tages-IP; keine Klar-IPs, keine
  Klaridentitäten, keine Passwörter in der Datenbank
- Interne Verzeichnisse doppelt gesperrt (Sperr-`.htaccess` je Verzeichnis
  plus Dateiendungs-Sperren im Root); Geheimnisse mit Rechten 0600
- Fehlerbilder ohne interne Details; Sicherheitsereignisse ohne Personenbezug

Details und Bedrohungsmodell: Whitepaper, Kapitel 8–9.
