<div align="center">

# Nexus

**Schlanker, selbst-gehosteter App-Launcher mit integrierten Apps — Mail, Kalender, Notizen, Aufgaben, Dateien und mehr.**

Nur **Apache + PHP**. Kein Composer, keine externen Dienste, keine ausgehenden Requests. Gebaut für den Betrieb in abgeschotteten, sicherheitskritischen Umgebungen.

![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb4)
![Apache](https://img.shields.io/badge/Server-Apache-d22128)
![SQLite](https://img.shields.io/badge/DB-SQLite-003b57)
![Ohne Composer](https://img.shields.io/badge/Dependencies-0-4a9d6f)
![License](https://img.shields.io/badge/License-MIT-4d7ea8)

</div>

---

## Inhalt

- [Überblick](#überblick)
- [Architektur](#architektur)
- [Installation](#installation)
- [Nutzer-Lebenszyklus & Quota](#nutzer-lebenszyklus--quota)
- [Mail](#mail)
- [Sicherheit](#sicherheit)
- [Neue App hinzufügen](#neue-app-hinzufügen)
- [Archiv](#archiv)

---

## Überblick

Nexus ist ein persönliches/kleines-Team-Dashboard mit Apps, die **direkt auf der Seite laufen** (kein iframe, kein neues Tab). Neue Nutzer registrieren sich selbst, werden aber erst nach **Freischaltung durch einen Administrator** vollwertig aktiv. Der Speicher jedes Nutzers ist per **Quota** begrenzt.

| App | Zweck | Ab Status |
|-----|-------|-----------|
| **Startseite** | Übersicht, Kacheln, Schnellzugriffe, heutige Termine | jeder |
| **Mail** | Interne Nachrichten/Tickets **+** externe IMAP/SMTP-Konten | jeder¹ |
| **Notizen** | Anpinnen, Farben | freigeschaltet |
| **Aufgaben** | To-dos mit Fälligkeit & Priorität | freigeschaltet |
| **Kalender** | Monatsansicht, Termine | freigeschaltet |
| **Kontakte** | Adressbuch | freigeschaltet |
| **Dateien** | Sandbox-Speicher (Quota-begrenzt) | freigeschaltet |
| **Lesezeichen** | Links als Startseiten-Kacheln | freigeschaltet |
| **Verwaltung** | Freischaltung, Quota, Sperren, Admins | nur Admin |
| **Einstellungen** | Profil, Theme, Passwort, Mailkonten | jeder |

¹ Vor der Freischaltung ist Mail auf den Administrator beschränkt (siehe unten).

---

## Architektur

Bewusst modular und **ohne Framework/Composer** – ein kleiner PSR-4-Autoloader genügt.

```
.
├── public/                  ← Webroot (Docroot hierauf zeigen)
│   ├── index.php            ← Front Controller (Bootstrap + Kernel)
│   ├── .htaccess            ← Verzeichnis-/Header-Härtung
│   └── assets/              ← app.css, app.js (statisch, cachefähig)
├── src/
│   ├── autoload.php         ← PSR-4 Autoloader (Namespace „Nexus\")
│   ├── bootstrap.php        ← Pfade, data/-Anlage & -Härtung, Migrationen
│   ├── helpers.php          ← globale Helfer (h, url, param, …)
│   ├── registry.php         ← zentrale App-Registry
│   ├── Core/                ← Database, Migrations, Security, Kernel,
│   │                          Router/View, Icons, AuthView
│   ├── Services/            ← Auth, Quota, Tickets, InternalMail,
│   │                          Mailer, Imap, Smtp
│   └── Apps/                ← Home, Mail, Notes, Tasks, Calendar,
│                              Contacts, Files, Bookmarks, Admin, Settings
├── data/                    ← Laufzeit (gitignored, s. u.) – NICHT im Webroot
│   └── sys/                 ← SQLite-DB, secret.key, sessions
├── old/                     ← archivierte Single-File-Version
└── .htaccess                ← Fallback, falls Docroot = Repo-Root
```

**Sicherheitsrelevant:** `data/` liegt **außerhalb** von `public/` und ist damit per Web gar nicht erreichbar – zusätzlich zur automatisch erzeugten `.htaccess`-Sperre (Defense in Depth).

Datenfluss: `public/index.php` → `nx_bootstrap()` → `Core\Kernel::handle()` (Session, Auth-Gating, Routing) → App-Klasse `render()`/`handle()` → `Core\View` rendert das einheitliche Layout.

---

## Installation

**Voraussetzungen:** Apache, PHP ≥ 7.4 mit `pdo_sqlite`. Empfohlen: `openssl` (verschlüsselte Mail-Passwörter), `zlib` (Mail-Kompression). Optional: `imap` (externe Postfächer lesen).

```apache
<VirtualHost *:80>
    ServerName nexus.example.com
    DocumentRoot /var/www/nexus/public
    <Directory /var/www/nexus/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

1. Repo nach `/var/www/nexus` klonen.
2. Docroot auf `/var/www/nexus/public` zeigen lassen (Vhost oben). *Alternativ* Repo-Root als Docroot – die mitgelieferte Root-`.htaccess` leitet dann nach `public/` und sperrt `src/`, `data/`, `old/`.
3. Schreibrechte für das Anlegen von `data/` sicherstellen (`chown www-data`).
4. Im Browser öffnen → **das erste angelegte Konto wird automatisch Administrator** (aktiv, 1 GB).

Beim ersten Aufruf legt Nexus `data/`, die SQLite-DB, den Secret-Key und alle App-Ordner selbst an.

### Lokal testen

```bash
php -S 127.0.0.1:8000 -t public public/index.php
```

---

## Nutzer-Lebenszyklus & Quota

```
Registrierung (E-Mail Pflicht)
        │
        ▼
   ┌─────────┐   Admin: Freischalten    ┌────────┐
   │ pending │ ───────────────────────▶ │ active │
   │ 0,5 GB  │                          │  1 GB  │
   └─────────┘   Admin: Ablehnen/Sperren └────────┘
        │                                    │
        └──────────────┐      ┌──────────────┘
                       ▼      ▼
                   ┌───────────┐
                   │ suspended │  (Login gesperrt)
                   └───────────┘
```

- **Registrierung** erfordert eine **E-Mail-Adresse**. Es entsteht sofort ein **Freischalt-Ticket** mit fester ID (`TCK-JJJJ-XXXXXX`).
- **Benachrichtigung des Admins:** Der erste (dienstälteste) Admin erhält bei jeder Registrierung automatisch eine **interne Ticket-Nachricht** (Betreff = Ticket-ID). Ist in den Einstellungen eine **Ticket-E-Mail** hinterlegt *und* ein Mailkonto verbunden, wird zusätzlich eine echte E-Mail versendet (best effort).
- **Vor der Freischaltung** (`pending`, 0,5 GB): Der Nutzer kann sich einloggen und **Mail nutzen, aber ausschließlich an den Administrator** schreiben – der Betreff ist fest an das Ticket gebunden. Andere Apps sind gesperrt.
- **Nach der Freischaltung** (`active`, 1 GB): voller Funktionsumfang.
- **Admin-Rechte** (App „Verwaltung"): Konten **freischalten/ablehnen**, **Quota +/−** setzen, **sperren/entsperren**, **weitere Admins ernennen** (oder degradieren). Der letzte verbleibende Admin kann nicht degradiert werden; das eigene Konto kann man nicht sperren.
- **Quota** = interner Mail-Posteingang + Dateien. Uploads über dem Limit werden abgewiesen; die belegte Menge ist in der Sidebar und in der Verwaltung sichtbar.

Die Standardwerte (0,5 GB / 1 GB) sind in `src/bootstrap.php` als `NX_QUOTA_PENDING` / `NX_QUOTA_ACTIVE` definiert.

---

## Mail

Nexus trennt zwei Welten, beide auf **wenig Speicher und wenig Bandbreite** ausgelegt:

**Intern** (Nachrichten/Tickets zwischen Nutzern)
- Bodies werden **komprimiert** (`gzdeflate`) in SQLite abgelegt → minimaler Speicher.
- Keine externen Ressourcen → **null Bandbreite**, kein Tracking.
- Zählt gegen die Quota des Empfängers.

**Extern** (eigene IMAP/SMTP-Konten – „nur Zugangsdaten, Rest läuft auf der Seite")
- Listen laden **nur die Overview** (Betreff/Absender/Datum), keine Bodies.
- Der Nachrichtentext wird **erst beim Öffnen** und mit **`FT_PEEK`** geladen, **`text/plain` bevorzugt** (kleiner als HTML); **Anhänge werden nicht vorab geladen** → deutlich weniger Bandbreite.
- HTML-Mails werden in einem **`sandbox`-iframe mit eigener CSP** dargestellt: keine Skripte, **keine Remote-Requests** (Tracking-Pixel laden nicht).
- Passwörter werden **AES-256-GCM-verschlüsselt** in der gesperrten `data/sys` gespeichert. TLS-Zertifikatsprüfung ist pro Konto aktivierbar.
- Versand über einen eingebauten, abhängigkeitsfreien **SMTP-Client** (SSL/STARTTLS).

---

## Sicherheit

- `data/` **außerhalb** des Webroots + `.htaccess`-Sperre + 403-Guards.
- **Strikte Sicherheits-Header** auf jeder Antwort: CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy`.
- **CSRF-Schutz** auf allen zustandsändernden Aktionen (POST-Token + Token bei GET-Aktionen).
- Passwörter mit `password_hash()` (bcrypt), min. 8 Zeichen.
- **Login-Drossel**: Sperre nach zu vielen Fehlversuchen pro IP.
- **Datei-Sandbox** pro Nutzer, Path-Traversal (`../`) wird abgewiesen.
- **Keine ausgehenden Requests** im Normalbetrieb (keine Font-CDNs o. ä.).
- Rollen-/Status-basierte Zugriffskontrolle je App.

---

## Neue App hinzufügen

1. Klasse unter `src/Apps/Meine.php` mit `Nexus\Apps\Meine`:
   ```php
   namespace Nexus\Apps;
   use Nexus\Core\View;
   final class Meine {
       public static function render(array $u): void { View::topbar('Meine App'); /* … */ }
       public static function handle(array $u, string $action): void { /* optional */ }
   }
   ```
2. Eintrag in `src/registry.php` ergänzen (Name, Icon, Farbe, `min`-Status, `class`).
3. Fertig – Navigation, Kachel, Routing und je-App-Datenordner unter `data/` entstehen automatisch.

---

## Archiv

Die ursprüngliche, komplett eigenständige **Single-File-Version** liegt unter [`old/`](old/) als Referenz.

---

## Lizenz

[MIT](LICENSE).
