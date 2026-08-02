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

- **Start:** Beim allerersten Aufruf erscheint nur die Sprachwahl über zwei
  Flaggen (Deutsch/English), danach die Seite.
- **Anmelden (Profil laden):** Auf der Anmeldeseite ist der NFC-Leser am
  Smartphone automatisch scharf (Web NFC): den Personalausweis anhalten
  genügt, die Anmeldung löst direkt aus. Der Knopf dient als Rückfall und
  für die einmalige Browser-Berechtigung; ohne NFC sendet er normal ab. Die
  statische Challenge ist der öffentliche Schlüssel selbst – **kein
  abgeleitetes Pseudonym**, die Identität ist der Schlüssel („on the go“).
  Das Anhalten lädt die **profil.yaml** (Stimmen, Themen, Favoriten,
  Jury-Status) in den Browser; der Abmelde-Knopf löscht sie dort wieder.
- **Zeitfenster (TOTP-artig):** Der Anmeldenachweis gilt nur kurz
  (5-Minuten-Fenster, höchstens zwei Fenster); danach ist erneutes
  Anhalten nötig. Zu anderer Zeit entsteht ein anderer Nachweis.
- **Stimmabgabe (unabhängiger Vorgang):** Jede Änderung (Stimme, Thema,
  Meldung, Jury-Stimme, Favorit, Löschung) läuft über einen eigenen
  **versiegelten, zeitgebundenen Umschlag**: Die Karte versiegelt die
  Aktion, der Server öffnet mit dem öffentlichen Schlüssel und trägt das
  Ergebnis für genau diesen Schlüssel ein. Alte Umschläge verfallen;
  zusätzlich ist jedes Formular-Token einmalig (kein Replay, deckt CSRF ab).
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
php index.php selftest   # Fachregeln automatisiert prüfen (49 Prüfungen)
php index.php cron       # Wartungslauf (sonst lazy bei Seitenaufrufen)
php index.php seed 400   # Demo-Pseudonyme + Zufallsstimmen (Vorführungen)
php index.php jurysim    # Demo-Jury stimmt in laufenden Prüfungen ab
php -S 127.0.0.1:8080 index.php   # lokale Demo ohne Webserver
```

## Sicherheitsmerkmale (Auszug)

- Ausschließlich Prepared Statements; durchgängiges Output-Escaping
- CSP `default-src 'none'` ohne `unsafe-inline` (CSS/JS liefert die Datei
  selbst als eigene Routen aus), restriktive Header, HSTS bei HTTPS
- Einmal-Token für jede POST-Anfrage (kein Replay, deckt CSRF ab);
  zeitgebundene versiegelte Umschläge für jede Änderung; im Browser nur
  Sitzungs-ID und die bewusst geladene profil.yaml
- Sessions: HttpOnly, SameSite, ID-Rotation, Idle-/Absolut-Timeout
- Kernregeln zusätzlich als DB-Constraints (1 Thema/Tag, 1 Stimme/Thema,
  1 offene Meldung/Thema, 1 Jury-Sitz/Meldung)
- Jury-Losverfahren mit CSPRNG; Rate-Limits ohne Klar-IP-Speicherung
- Keine Klaridentitäten, keine Passwörter, keine externen Abhängigkeiten
