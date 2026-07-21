<div align="center">

# 🧩 Nexus Launcher

**Ein ausgereifter Single-File-Launcher — von der Startseite über Kalender bis Mail, alles auf einer Seite.**

Eine einzige `index.php`, die nur **Apache + PHP** braucht und beim ersten Aufruf alles Weitere selbst anlegt.

![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb4?logo=php&logoColor=white)
![Apache](https://img.shields.io/badge/Server-Apache-d22128?logo=apache&logoColor=white)
![SQLite](https://img.shields.io/badge/DB-SQLite-003b57?logo=sqlite&logoColor=white)
![Single File](https://img.shields.io/badge/Setup-1%20Datei-6366f1)
![License](https://img.shields.io/badge/License-MIT-10b981)

</div>

---

## ✨ Überblick

Nexus ist ein persönliches Dashboard mit integrierten Apps, die **wirklich auf der Seite laufen** – kein iframe, kein neuer Tab. Du lädst eine einzige Datei auf deinen Server, rufst sie auf, legst ein Konto an – fertig. Die App erstellt Ordnerstruktur, Datenbank, Sicherheits-Regeln und Verschlüsselungs-Schlüssel vollautomatisch.

> **Philosophie:** Bei Diensten wie Mail brauchst du nur deine Kontodaten einzugeben – der komplette Rest passiert direkt auf der Seite.

---

## 🚀 Features

| App | Beschreibung |
|-----|--------------|
| 🏠 **Startseite** | Dashboard mit App-Kacheln, Schnellzugriff-Links und der heutigen Agenda |
| 📝 **Notizen** | Anpinnen, Farben, Bearbeiten – gespeichert in SQLite |
| 📅 **Kalender** | Monatsraster mit Terminen, Uhrzeiten und Farben |
| ✉️ **Mail** | Postfach per **IMAP** lesen, per eingebautem **SMTP-Client** senden (SSL/STARTTLS) – nur Zugangsdaten nötig |
| 📁 **Dateien** | Persönlicher, sandboxed Speicher mit Drag-&-Drop-Upload und Ordnern |
| 🔖 **Lesezeichen** | Links, die als Kacheln auf der Startseite erscheinen |
| ⚙️ **Einstellungen** | Profil, Theme, Akzentfarbe, Passwort, Mail-Konten, Systeminfo |

**Außerdem:**
- 🎨 **Einheitliches Design** über alle Apps – Sidebar-Navigation, Dark- & Light-Theme, wählbare Akzentfarbe, responsive für Mobil
- 🔐 **Benutzerverwaltung** mit Passwort-Hashing, CSRF-Schutz und Session-Handling; der erste registrierte Nutzer wird automatisch Admin
- 🗄️ **Selbst-Bootstrap** – Datenbank, Ordner, `.htaccess` und Secret-Key werden beim ersten Start erzeugt

---

## 📦 Voraussetzungen

- **Apache** (für die `.htaccess`-basierte Sperre von `/data`)
- **PHP ≥ 7.4** mit den Erweiterungen:
  - `pdo_sqlite` *(erforderlich)*
  - `openssl` *(empfohlen – für die verschlüsselte Speicherung von Mail-Passwörtern)*
  - `imap` *(optional – nur zum **Lesen** von Postfächern; SMTP-Versand läuft auch ohne)*
- Schreibrechte im Verzeichnis, in dem `index.php` liegt (zum Anlegen von `/data`)

---

## ⚡ Installation

```bash
# 1. Datei ins Webroot (oder ein Unterverzeichnis) deines Apache-Servers legen
cp index.php /var/www/html/

# 2. Im Browser aufrufen
#    https://deine-domain.tld/index.php
```

Beim **ersten Aufruf** legt Nexus automatisch an:

```
/data/                     ← per .htaccess komplett gesperrt
├── .htaccess              ← "Require all denied" (Apache 2.4 + 2.2-Fallback)
├── index.php              ← 403-Guard als Sicherheitsnetz
├── sys/                   ← System-Verzeichnis
│   ├── app.sqlite         ← Datenbank (Nutzer, Notizen, Termine …)
│   ├── secret.key         ← Schlüssel für AES-256-GCM-Verschlüsselung
│   └── sessions/          ← Session-Speicher
├── notes/                 ← je App ein eigener Datenordner
├── calendar/
├── mail/
├── bookmarks/
└── files/<user-id>/       ← persönlicher, abgeschotteter Datei-Speicher
```

Danach erstellst du im Browser das erste Konto (= Admin) und kannst sofort loslegen.

### Lokal testen

```bash
php -S 127.0.0.1:8000 index.php
# Hinweis: Der PHP-Dev-Server wertet .htaccess NICHT aus – die /data-Sperre
# greift nur unter echtem Apache. Die index.php-Guards schützen aber auch hier.
```

---

## 🔒 Sicherheit

- **`/data` ist komplett gesperrt** – automatisch generierte `.htaccess` (`Require all denied`, Apache-2.2-Fallback, `Options -Indexes`) plus zusätzliche `index.php`-403-Guards als Sicherheitsnetz.
- **Passwörter** werden mit `password_hash()` (bcrypt) gespeichert.
- **Mail-Zugangsdaten** werden mit **AES-256-GCM** verschlüsselt (Schlüssel in der gesperrten `/data/sys`).
- **CSRF-Schutz** auf allen zustandsändernden Aktionen.
- **Datei-Sandbox** pro Nutzer – Path-Traversal (`../`) wird abgewiesen.
- **Mail-Rendering** in einem `sandbox`-iframe mit vorherigem HTML-Sanitizing.

> ⚠️ Die `/data`-Sperre setzt **Apache** voraus. Unter nginx muss der Zugriff auf `/data` stattdessen in der Server-Konfiguration unterbunden werden (die `index.php`-Guards fangen Direktaufrufe aber trotzdem ab).

---

## 🏗️ Architektur

Alles steckt in **einer** Datei, klar in Abschnitte gegliedert:

| Abschnitt | Inhalt |
|-----------|--------|
| **Bootstrap** | Legt Ordner, `.htaccess`, Secret-Key und Datenbank an |
| **DB-Layer** | SQLite via PDO mit Migrationen |
| **Sicherheit** | CSRF, Escaping, AES-Verschlüsselung |
| **Auth** | Registrierung, Login, Sessions |
| **Apps** | Home, Notizen, Kalender, Mail, Dateien, Lesezeichen, Einstellungen |
| **Assets** | CSS & JS werden cachefähig über `?asset=` ausgeliefert |
| **Router** | Front-Controller mit `?app=` / `?action=` |

Neue Apps lassen sich über die zentrale `app_registry()` ergänzen – jede bekommt automatisch einen eigenen Datenordner unter `/data/`.

---

## 📁 Projektstruktur

```
.
├── index.php     ← die komplette Anwendung (Single File)
├── .gitignore    ← schließt Laufzeitdaten (/data, *.sqlite) aus
├── wifi/         ← eigenständiges WLAN-Audit-Lerntool (siehe unten)
└── README.md
```

---

## 📡 Weitere Tools: `wifi/`

Ein **eigenständiges, lehrorientiertes WLAN-Audit-Tool** (unabhängig von Nexus). Es führt Schritt für Schritt vom frischen Kali-Linux bis „im Netzwerk" – gesteuert über ein schlichtes lokales Web-Interface unter **http://crack-wifi.local**: Netzwerke scannen, Crackbarkeit (0–100) einschätzen, ein Netz anklicken und live zusehen, wie das gefundene Passwort ermittelt wird. Dünner Wrapper um die Standard-Kali-Tools (aircrack-ng-Suite).

**Alles in einem Kommando** (auf Kali – installiert fehlende Abhängigkeiten selbst und öffnet den Browser):

```bash
git clone https://github.com/florianthepro/claude.git
cd claude/wifi
sudo ./crack.sh
```

Danach im Browser einfach ein Netzwerk anklicken → warten → Passwort ablesen.

**Erst gefahrlos ausprobieren** (Demo-Modus, läuft überall – keine Hardware/root nötig):

```bash
cd claude/wifi
./crack.sh --demo --no-dns
# dann die ausgegebene URL öffnen, z. B. http://127.0.0.1:8777
```

> ⚠️ **Nur für autorisierte Nutzung** – teste ausschließlich Netzwerke, die dir gehören oder für die du eine schriftliche Erlaubnis hast. Details, Kali-Schritt-für-Schritt-Anleitung und alle Optionen: [`wifi/README.md`](wifi/README.md).

---

## 📝 Lizenz

Veröffentlicht unter der [MIT-Lizenz](LICENSE) – frei nutzbar, anpassbar und weiterverbreitbar.
