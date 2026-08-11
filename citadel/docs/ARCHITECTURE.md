# Citadel — Architektur & Sicherheit

Sichere Self-Hosted-Plattform: ein Konto (Username + Passwort + TOTP), dahinter
integrierte Module (Messenger, Mail, …). Ziel: maximale Sicherheit bei schlankem
Betrieb — von jedem Browser aus nutzbar, ohne dass der Client vertrauenswürdig
sein muss.

## 1. Schutzziele

- **Vertraulichkeit** der Nutzerdaten, insbesondere Nachrichten → Ende-zu-Ende
  (der Server sieht Klartext nie).
- **Integrität & Authentizität** der Kommunikation → aktive MITM (auch ein
  Angreifer *auf dem Pfad*, z. B. kompromittierter Switch/Proxy) darf keine
  Nachricht fälschen oder unbemerkt mitlesen.
- **Konto-Sicherheit** → Phishing-/Credential-Stuffing-resistente Anmeldung,
  Pflicht-2FA.
- **Kompromittierungs-Robustheit** → Diebstahl der Datenbank gibt keine Passwörter,
  keine TOTP-Seeds, keine Nachrichteninhalte preis.

## 2. Bedrohungsmodell

| Angreifer | Fähigkeit | Gegenmaßnahme |
|---|---|---|
| Netzwerk-MITM (Switch, WLAN, Proxy) | Verkehr lesen/ändern | TLS 1.2/1.3, HSTS; für Nachrichten zusätzlich E2E + Safety-Numbers |
| Passiver Server-Betreiber / DB-Leak | Ruhende Daten lesen | Argon2id-Passwörter, AES-256-GCM-versiegelte TOTP-Seeds, E2E-Inhalte |
| Web-Angreifer (CSRF/XSS/Clickjacking) | Fremd-Requests, Skript-Injection | strikte CSP + Trusted Types, CSRF-Token, SameSite=Strict, `frame-ancestors 'none'` |
| Brute-Force / Enumeration | Rate/Timing-Angriffe | Rate-Limits, Account-Lockout, konstante Fehlermeldungen, Timing-Angleich |
| Gestohlenes Session-Cookie | Session-Hijack | `__Host-` Cookie, HttpOnly, Idle-/Absolut-Timeout, Rotation, serverseitige Sessions |

**Außerhalb des Modells (aktuell):** vollständig kompromittiertes Endgerät
(Keylogger/Malware im Browser), physischer Zugriff auf den entsperrten Server,
Angriffe unterhalb der TLS/OS-Ebene.

## 3. Aktuelle Kontrollen (implementiert)

### Anmeldung
- Passwörter: **Argon2id** (m=64 MiB, t=3, p=1), nie geloggt, Mindestlänge 12,
  Sperrliste gängiger Passwörter.
- Zweiter Faktor: **TOTP (RFC 6238)** verpflichtend beim Registrieren; 10 einmalige
  **Backup-Codes** (nur als Argon2id-Hash gespeichert).
- Lockout nach 5 Fehlversuchen (15 min), aktive Sessions werden dabei invalidiert.
- Nutzer-Enumeration verhindert: generische Fehler + Timing-Angleich (Dummy-Hash
  für unbekannte Konten).

### Sessions
- Serverseitig in SQLite; Cookie trägt nur ein 256-bit-Zufallstoken, gespeichert
  wird dessen SHA-256 → DB-Leak enthält keine gültigen Cookies.
- `__Host-sid`: `Secure`, `HttpOnly`, `SameSite=Strict`, `Path=/`.
- Idle-Timeout 30 min, Absolut-Timeout 12 h, Rotation bei Privilegienwechsel
  (Enroll → Voll).

### Transport & Browser
- CSP: `default-src 'none'`, nur `'self'`-Skripte/Styles, `require-trusted-types-for
  'script'`, `frame-ancestors 'none'`, `object-src 'none'`, `base-uri 'none'`.
- HSTS (2 Jahre, includeSubDomains, preload), COOP/COEP/CORP, `Referrer-Policy:
  no-referrer`, restriktive `Permissions-Policy`.
- Kein Inline-JS/CSS; das Frontend baut DOM ausschließlich über `textContent`/
  `createElement` (Trusted-Types-kompatibel, keine `innerHTML`-Sinks).

### CSRF
- Doppelter Schutz: pro-Session-CSRF-Token im `X-CSRF-Token`-Header **und**
  strikte Origin/Referer-Prüfung für alle zustandsändernden Requests.

### Statische Auslieferung
- Explizite Allowlist (`/`, `/styles.css`, `/app.js`) — kein Pfad wird aus
  Request-Input abgeleitet, Directory-Traversal ist strukturell ausgeschlossen.
  (Deshalb bewusst **kein** generischer Static-Server als Dependency.)

## 4. Messenger — E2E-Design (geplant, MITM-/„Switch"-resistent)

Der Server ist ausschließlich **blinder Relay + Verzeichnis**. Er kennt nie
private Schlüssel oder Klartext.

**Schlüsseltausch: X3DH.** Jedes Gerät erzeugt lokal ein Langzeit-Identity-Key-Paar,
ein signiertes Prekey und Einmal-Prekeys (öffentliche Teile liegen serverseitig).
Ein Sender leitet aus mehreren Diffie-Hellman-Operationen ein gemeinsames Geheimnis
ab, ohne dass der Empfänger online sein muss.

**Nachrichten: Double Ratchet.** Pro Nachricht neuer Schlüssel (DH-Ratchet +
symmetrischer KDF-Ratchet) → **Forward Secrecy** und **Post-Compromise Security**:
ein einzelner geleakter Schlüssel entschlüsselt weder Vergangenheit noch Zukunft.

**Warum das einen Switch/Proxy-MITM aushält:** TLS schützt den Transport, aber der
eigentliche Schutz sitzt eine Ebene höher — Inhalte sind schon vor dem Verlassen des
Geräts verschlüsselt und authentifiziert (AEAD). Ein Angreifer auf dem Pfad sieht nur
Chiffrat. Der einzige verbleibende Angriff wäre ein **Identity-Key-Austausch** durch
einen bösartigen Server. Dagegen:

- **Safety Numbers**: aus beiden Identity-Keys wird ein Fingerprint abgeleitet, den
  zwei Nutzer out-of-band (QR/Vorlesen) vergleichen. Stimmt er, ist ausgeschlossen,
  dass ein Dritter dazwischen sitzt.
- **Trust On First Use + Change-Warnung**: ändert sich ein Identity-Key, wird die
  Konversation blockiert, bis der Nutzer neu verifiziert.

**Konto-Automatik (wie gewünscht):** Beim ersten Öffnen des Messengers erzeugt der
Client zufällig einen Handle und die Schlüssel; Registrierung erfolgt automatisch
gegen das interne Verzeichnis — ohne Telefonnummer. (Eine optionale Bridge zu
externen Netzen wäre ein separates, später zu bewertendes Modul, da echte
Fremd-Netze verifizierte Rufnummern verlangen und sich nicht sauber automatisieren
lassen.)

## 5. Ruhende Daten

- SQLite mit WAL; Datei gehört dem unprivilegierten `node`-User.
- TOTP-Seeds: AES-256-GCM unter einem KEK aus der Umgebung (nie im Repo/DB).
- Passwörter & Backup-Codes: nur Argon2id-Hashes.
- Später: E2E-Inhalte serverseitig ausschließlich als Chiffrat; private Schlüssel
  verlassen das Endgerät nie.

## 6. Deployment-Härtung

- Container: rootless (`USER node`), `read_only`-Rootfs, `cap_drop: ALL`,
  `no-new-privileges`, `tmpfs` für `/tmp`, App nur im internen Netz.
- TLS-Terminierung durch Caddy (automatische Zertifikate, moderne Cipher).
- Reproduzierbarer Multi-Stage-Build, nur Prod-Dependencies im Runtime-Image.

## 7. Implementierungsstand Messenger

Der komplette Krypto-Kern (`web/crypto/`) und das Server-Backend sind gebaut und
verifiziert — **isomorpher Code**, der identisch im Browser und (für Tests) in Node läuft:

- `primitives.js` — WebCrypto-Wrapper (X25519, Ed25519, HKDF, HMAC, AES-256-GCM, PBKDF2).
- `x3dh.js` — Identitäts-/Prekey-Erzeugung, Bundle, X3DH-Initiator/Responder.
- `doubleratchet.js` — Double Ratchet inkl. Skipped-Keys (Out-of-Order/Verlust).
- `session.js` — Wire-Format, Safety-Numbers, Zustands-Serialisierung.
- `backup.js` — client-verschlüsseltes Schlüssel-Backup (PBKDF2 → AES-GCM).
- Server: Directory (`/api/keys*`), atomarer OPK-Pop, Relay (`/api/messages*`),
  Backup-Speicher (`/api/backup`) — alles auth-/CSRF-geschützt, **Server sieht nur Chiffrat**.

Verifiziert durch zwei Suites: `npm run crypto-test` (22 Checks: Ratchet, Out-of-Order,
Manipulations-/Identitätsbindung, Safety-Numbers, Serialisierung, Backup) und
`npm run messenger-e2e` (11 Checks: kompletter Austausch zwei Clients durch den echten
Server, Nachweis der Server-Blindheit, OPK-Verbrauch, Safety-Number-Gleichheit).

**Krypto-Hinweis (ehrlich):** Die Protokolle laufen auf audited Primitiven (WebCrypto),
die Protokoll-*Logik* (X3DH/Ratchet) ist hier selbst implementiert und getestet, aber
nicht extern auditiert. Die Modulgrenze ist bewusst schmal gehalten, damit eine
produktive Installation sie gegen auditiertes **libsignal** tauschen kann.

## 8. Roadmap

1. **Fundament (fertig):** gehärteter Server, Konten (Argon2id + TOTP + Backup),
   Sessions, CSP/CSRF, Deployment, End-to-End-Smoke-Test.
2. **Messenger-Kern + Backend (fertig, verifiziert):** X3DH + Double Ratchet,
   Relay/Directory, Safety-Numbers, client-verschlüsseltes Backup.
3. **Messenger-UI (nächster Schritt):** Chat-Oberfläche, Safety-Number-Verifikation,
   Recovery-Passphrase-Flow, lokale Persistenz (IndexedDB), OPK-Nachfüllung.
4. **Mail:** Anbindung, minimal & nativ integriert.
5. **Betrieb:** WebAuthn/Passkeys als TOTP-Alternative, Admin-Audit-UI,
   automatisierte Security-Tests in CI, Auslagerung auf auditiertes libsignal.
