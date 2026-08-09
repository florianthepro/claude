# DIERCK ID – SSO-, TOTP- und MFA-Dienst in einer Datei

`index.php` ist die komplette Anwendung: Nutzerkonten, Passwörter, TOTP-MFA,
Wiederherstellungscodes, optionale SMS-Einmalcodes über Odoo, SSO für eigene
Anwendungen und eine signierte Maschinen-API. Keine Datenbank, keine
Composer-Abhängigkeiten.

## Installation

1. `index.php` ins Webroot legen und einmal im Browser aufrufen.
2. Beim ersten Start entstehen automatisch:
   - `totp/` – Datenverzeichnis (Rechte 0700), alle Datensätze
     XChaCha20-Poly1305-verschlüsselt
   - `totp/.htaccess` und `totp/web.config` – Zugriff komplett gesperrt
   - `.htaccess` im Webroot – Routing plus Basis-Header
3. Das **erste angelegte Konto wird Administrator**. Also sofort selbst
   registrieren, bevor die Seite öffentlich erreichbar ist.

Voraussetzungen: PHP ≥ 8.1 mit `sodium`, `json`, `hash`; `curl` empfohlen.

### Ohne Apache

Nginx (Rewrite und Sperre des Datenverzeichnisses):

```nginx
location ^~ /totp/ { deny all; return 404; }
location / { try_files $uri /index.php$is_args$args; }
```

Ohne jede Rewrite-Regel läuft die Anwendung auch über `PATH_INFO`,
also z. B. `https://example.com/index.php/usr/login`.

## Konfiguration (Umgebungsvariablen)

| Variable | Bedeutung |
|---|---|
| `SSO_MASTER_KEY` | Masterschlüssel (base64, ≥ 32 Byte). **Vor dem ersten Start setzen** – nachträglich sind bestehende Daten nicht mehr entschlüsselbar. Ohne die Variable liegt der Schlüssel in `totp/keys/master.key`. |
| `SSO_BASE_URL` | Öffentliche Basis-URL, z. B. `https://id.example.com`. Empfohlen, sonst wird der Host-Header ausgewertet. |
| `SSO_DATA_DIR` | Anderer Ort für das Datenverzeichnis (idealerweise außerhalb des Webroots). |
| `SSO_ISSUER_NAME` | Name in der Authenticator-App. |
| `SSO_TRUSTED_PROXIES` | Kommaliste (IP oder CIDR). Nur von diesen Adressen werden `X-Forwarded-For`/`-Proto` ausgewertet. |
| `SSO_ARGON_MEMORY` / `_TIME` / `_THREADS` | Argon2id-Parameter (Standard 128 MiB, t=4, p=2). |
| `SSO_ALLOW_INSECURE_PAIRING` | Nur für lokale Tests: erlaubt `http` und interne Adressen. In Produktion niemals setzen. |
| `SSO_DEBUG` | Fehlerausgabe im Browser. Nur zur Fehlersuche. |

## Anwendung anbinden

**Weg A – automatisch koppeln.** Im Konto unter *API-Anbindungen* eine Anbindung
anlegen, Connector-URL eintragen, die erzeugte `api.php` auf den eigenen Server
legen, „Initialisieren“ drücken. Beide Seiten handeln per X25519 Schlüssel aus
und bestätigen sie gegenseitig; das Bootstrap-Geheimnis wird danach verworfen.

```php
require __DIR__ . '/api.php';
$sso = new SsoClient();

if (!isset($_GET['code'])) {
    header('Location: ' . $sso->loginUrl('https://example.com/api.php'));
    exit;
}
$user = $sso->handleCallback('https://example.com/api.php');
// $user: sub, username, name, email, amr, auth_time …
```

**Weg B – selbst implementieren.** Anbindung im Modus *Manuelle Schlüssel*
anlegen und jeden Aufruf signieren:

```
X-Sso-Client / X-Sso-Timestamp / X-Sso-Nonce / X-Sso-Signature

sig = base64url(HMAC-SHA256(k_c2s, S))
S   = "SSO-HMAC-SHA256" LF "POST" LF <Pfad> LF <ts> LF <nonce> LF sha256_hex(<Body>)
k_c2s = HKDF-SHA256(secret, L=32, info="sso-manual|c2s|<client_id>", salt="")
```

Vollständige Beschreibung aller Endpunkte unter `/api` auf der laufenden
Installation.

## SMS über Odoo

Im Adminbereich werden Odoo-URL, Datenbank, Login und **API-Key** hinterlegt
(Odoo: Einstellungen → Nutzer → Kontosicherheit → Neuer API-Key).

Der Versand läuft standardmäßig transaktional über das Modell `sms.sms`. Der
Weg über die **SMS-Marketing-App** (`mailing.mailing`) ist ebenfalls
implementiert und umstellbar, für Einmalcodes aber nicht zu empfehlen:
Mailings laufen über Warteschlangen und Cron, respektieren Blacklists und
Opt-outs und werden dadurch unvorhersehbar spät zugestellt. Beide Wege setzen
das Modul `sms` und IAP-Guthaben (oder einen eigenen Gateway-Connector) voraus.

## Sicherheitsmerkmale

- Argon2id mit serverseitigem Pepper (HMAC vor dem Hashen)
- TOTP nach RFC 6238 mit Replay-Schutz über monoton steigenden Zähler
- Alle Datensätze at-rest AEAD-verschlüsselt, AAD an den Ablageort gebunden
- Ed25519-signierte SSO-Assertions, JWKS-Endpunkt, PKCE (S256) verpflichtend
- Authentifizierter Schlüsselaustausch beim Pairing inkl. Key-Confirmation
- HMAC-Request-Signatur mit Nonce-Cache und Zeitfenster gegen Replay
- SSRF-Schutz inklusive DNS-Rebinding-Pinning für alle ausgehenden Anfragen
- Rate-Limits, exponentielles Lockout, enumerationsresistente Antworten
- Hash-verkettetes Audit-Log, im Adminbereich prüfbar
- Strikte CSP mit Nonces, `__Host-`-Cookie, CSRF-Token, SameSite=Lax

## Betriebshinweise

- Hinter TLS betreiben. HSTS wird nur unter https gesendet.
- Datenverzeichnis in die Datensicherung aufnehmen; ohne Masterschlüssel ist
  ein Backup wertlos (und umgekehrt).
- Aufräumarbeiten laufen probabilistisch bei etwa jedem 50. Aufruf mit.
