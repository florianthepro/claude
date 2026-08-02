# Stimmwerk – Webanwendung (Prototyp)

Digitale Bürgerbeteiligung mit dem Personalausweis. Konzept und Fachlogik sind im
[Whitepaper](../whitepaper.md) beschrieben; dieses Verzeichnis enthält die
PHP-Anwendung.

> **Testbetrieb:** Im Auslieferungszustand zeigt jede Seite ein Banner
> „Testbetrieb / Entwicklungsversion …“. Gesteuert über `show_test_banner`
> in `config/config.php` (Standard: `true`). Erst für einen echten,
> abgenommenen Betrieb auf `false` setzen.

## Voraussetzungen

- PHP ≥ 8.2 mit `pdo_sqlite` und `mbstring` (Standard in den meisten Paketen)
- keine weiteren Abhängigkeiten: kein Composer, kein Framework, kein CDN

## Schnellstart (Entwicklung/Demo)

```bash
cd webserver
php -S 127.0.0.1:8080 -t public public/router.php
```

Aufruf: http://127.0.0.1:8080 – beim ersten Start werden Schema, Kategorien und
die neutralen Startthemen automatisch angelegt (`data/stimmwerk.sqlite`).

Anmeldung im Mock-Modus: „Ausweis anhalten“ → „Neue Testkarte erzeugen“. Die
angezeigte Test-Kennung ersetzt die physische Karte (gleiche Kennung = gleiches
Konto) und wird für die nächste Anmeldung benötigt.

### Demo-Daten für Vorführungen (optional)

```bash
php bin/seed_demo.php 400      # 400 simulierte Pseudonyme + zufällige Stimmen
php bin/simulate_jury.php      # lässt Demo-Pseudonyme in laufenden Jurys abstimmen
```

Beide Skripte laufen nur über die CLI und nur im Mock-Modus.

### Selbsttest

```bash
php bin/selftest.php
```

Prüft die Kernregeln gegen Wegwerf-Datenbanken: Tagesgrenze (00:00,
Europe/Berlin), Stimmen, Favoriten, Jury-Auslosung (1 %, Ausschlüsse,
3-Tage-Karenz), Fristen (Start um Mitternacht, 24 h), Quorum (0,5 %) und
Entscheidung inklusive Entfernung.

## Aufbau

```
webserver/
├── public/          # Einziges Webroot (index.php, Assets, .htaccess)
├── src/
│   ├── Core/        # Datenbank (PDO), Session, CSRF, Zeit, Rate-Limits, Logs, Geheimnisse
│   ├── Security/    # HTTP-Sicherheitsheader (CSP u. a.)
│   ├── Eid/         # Ausweis-Abstraktion: Mock + Anbindungspunkt TR-03130
│   ├── Auth/        # Pseudonym-Konten (HMAC, keine Klardaten)
│   ├── Domain/      # Fachlogik: Themen, Stimmen, Favoriten, Meldungen, Jury
│   ├── I18n/        # Deutsch (Standard) und Englisch
│   ├── Controllers/ # Dünne HTTP-Schicht
│   └── View/        # Templates (durchgängig escaped)
├── db/schema.sql    # Schema mit Invarianten als Constraints
├── bin/             # cron.php, seed_demo.php, simulate_jury.php, selftest.php
├── config/config.php
└── data/            # Laufzeitdaten (SQLite, secret.key, Logs) – nicht im Webroot, nicht im Git
```

## Betrieb (Kurzfassung)

- **Webroot:** ausschließlich `public/` (Apache: `.htaccess` liegt bei;
  nginx: alle Pfade auf `public/index.php` leiten, `data/` und `src/` sind
  außerhalb des Webroots).
- **Cron:** `* * * * * php /pfad/zu/webserver/bin/cron.php` – startet
  Jury-Abstimmungen um 00:00 und entscheidet fällige Meldungen. Ohne Cron
  übernimmt ein gedrosselter Lazy-Lauf bei Seitenaufrufen.
- **TLS:** Produktion ausschließlich über HTTPS (HSTS wird dann automatisch
  gesetzt).
- **Geheimnisse:** `data/secret.key` (HMAC-Pepper) wird beim ersten Start
  erzeugt (0600). Bei Verlust sind alle Pseudonym-Zuordnungen unbrauchbar –
  sichern; bei Kompromittierung rotieren (invalidiert alle Konten).
- **eID:** `eid_provider = 'mock'` ist nur für Entwicklung/Demos. Echtbetrieb
  erfordert die TR-03130-Anbindung (Berechtigungszertifikat + eID-Server),
  siehe `src/Eid/Tr03130Provider.php` und Whitepaper Kapitel 5.

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
- Fehlerbilder ohne interne Details; Sicherheitsereignisse werden ohne
  Personenbezug protokolliert

Details und Bedrohungsmodell: Whitepaper, Kapitel 8–9.
