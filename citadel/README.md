# Citadel

Sichere Self-Hosted-Plattform. Ein Konto (Username + Passwort + TOTP), dahinter
integrierte Module (Messenger, Mail, …). Dunkel, schlank, für Admins.

Status: gehärteter Server + Konten/2FA/Sessions **und** verifizierter E2E-Messenger-Kern
(X3DH + Double Ratchet, Server ist blinder Relay). Als Nächstes die Messenger-UI
(siehe `docs/ARCHITECTURE.md`).

## Schnellstart (lokal)

```bash
npm install
cp .env.example .env
echo "KEK_BASE64=$(npm run -s keygen)" >> .env   # 32-Byte-Schlüssel erzeugen
# für lokales HTTP: in .env COOKIE_SECURE=false und PUBLIC_ORIGIN=http://localhost:8787
npm run dev
```

Aufruf: `http://localhost:8787` → Registrieren → TOTP scannen → Backup-Codes
sichern → Anmelden.

## Betrieb (Docker + TLS)

```bash
cp .env.example .env      # KEK_BASE64 setzen (npm run keygen), PUBLIC_ORIGIN=https://deine-domain
# deploy/Caddyfile: Domain + E-Mail eintragen
docker compose -f deploy/docker-compose.yml up -d --build
```

Caddy holt/erneuert Zertifikate automatisch; die App läuft rootless, read-only
und nur im internen Netz.

## Skripte

| Befehl | Zweck |
|---|---|
| `npm run dev` | Entwicklungsserver (Hot-Reload) |
| `npm run build` / `npm start` | Build + Produktionsstart |
| `npm run typecheck` | Typprüfung |
| `npm run keygen` | 32-Byte-KEK (base64) erzeugen |
| `npm run smoke` | End-to-End-Test der Auth-Kette gegen einen laufenden Server |
| `npm run sec-test` | Security-Regressionen: TOTP-Replay, Lockout, Origin/CSRF |
| `npm run crypto-test` | E2E-Krypto-Kern verifizieren (headless, 22 Checks) |
| `npm run messenger-e2e` | Messenger-Austausch durch den laufenden Server (11 Checks) |
| `npm run ui-e2e` | Zwei-Nutzer-Browser-Test (Playwright): Registrierung → Chat → Safety-Number |

`messenger-e2e` und `ui-e2e` erwarten einen laufenden Server mit `COOKIE_SECURE=false`
(TOTP/Chat gegen `http://127.0.0.1`). `ui-e2e` braucht Chromium — in dieser Umgebung
vorinstalliert, sonst `npx playwright install chromium`.

## Sicherheit (Kurzfassung)

- Argon2id-Passwörter, TOTP-Pflicht (einmalig, Replay-Schutz), Backup-Codes,
  Account-Lockout (ohne bestehende Sessions zu zerstören).
- Serverseitige Sessions, `__Host-`-Cookies, CSRF-Token + Origin-Check.
- Strikte CSP + Trusted Types, HSTS, COOP/COEP, restriktive Permissions-Policy.
- TOTP-Seeds AES-256-GCM-versiegelt; DB-Leak gibt keine Secrets preis.
- Keine `npm audit`-Findings; minimale Dependency-Fläche.

Details & Threat-Model: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
