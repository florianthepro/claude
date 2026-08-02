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

## Ausweis & Bestätigung

- **Anmelden:** ein Knopf – „Ausweis anhalten“. Der (im Testbetrieb
  simulierte) Karten-Chip signiert eine Zufallsnachricht mit seinem privaten
  Schlüssel; der Server prüft die Signatur gegen den öffentlichen Schlüssel
  und kennt nur ein daraus abgeleitetes Pseudonym.
- **Jede Änderung** (Stimme, Thema, Meldung, Jury-Stimme, Favorit, Löschung)
  verlangt die Karte erneut – ohne gültigen Karten-Schlüssel wird die
  Änderung abgelehnt.
- Testbetrieb: Die simulierte Karte liegt in diesem Browser (Cookie,
  echtes Ed25519-Schlüsselpaar). „Neue Testkarte“ erzeugt eine weitere
  Identität, z. B. für Vorführungen mit mehreren Rollen.
- Echtbetrieb: Austausch des Karten-Blocks gegen die eID-Server-Anbindung
  (BSI TR-03130), siehe Whitepaper Kapitel 5.

## CLI (optional)

```bash
php index.php selftest   # Fachregeln automatisiert prüfen (38 Prüfungen)
php index.php cron       # Wartungslauf (sonst lazy bei Seitenaufrufen)
php index.php seed 400   # Demo-Pseudonyme + Zufallsstimmen (Vorführungen)
php index.php jurysim    # Demo-Jury stimmt in laufenden Prüfungen ab
php -S 127.0.0.1:8080 index.php   # lokale Demo ohne Webserver
```

## Sicherheitsmerkmale (Auszug)

- Ausschließlich Prepared Statements; durchgängiges Output-Escaping
- CSP `default-src 'none'` ohne `unsafe-inline` (CSS/JS liefert die Datei
  selbst als eigene Routen aus), restriktive Header, HSTS bei HTTPS
- Zentrale CSRF-Prüfung jeder POST-Anfrage; Signaturbestätigung jeder Änderung
- Sessions: HttpOnly, SameSite, ID-Rotation, Idle-/Absolut-Timeout
- Kernregeln zusätzlich als DB-Constraints (1 Thema/Tag, 1 Stimme/Thema,
  1 offene Meldung/Thema, 1 Jury-Sitz/Meldung)
- Jury-Losverfahren mit CSPRNG; Rate-Limits ohne Klar-IP-Speicherung
- Keine Klaridentitäten, keine Passwörter, keine externen Abhängigkeiten
