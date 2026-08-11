# Citadel

Sichere Self-Hosted-Plattform. Ein Konto (Username + Passwort + TOTP), dahinter
integrierte Module (Messenger, Mail, …). Dunkel, schlank, für Admins.

Status: **Fundament** — gehärteter Server + Konten/2FA/Sessions. Module folgen
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

## Sicherheit (Kurzfassung)

- Argon2id-Passwörter, TOTP-Pflicht, Backup-Codes, Account-Lockout.
- Serverseitige Sessions, `__Host-`-Cookies, CSRF-Token + Origin-Check.
- Strikte CSP + Trusted Types, HSTS, COOP/COEP, restriktive Permissions-Policy.
- TOTP-Seeds AES-256-GCM-versiegelt; DB-Leak gibt keine Secrets preis.
- Keine `npm audit`-Findings; minimale Dependency-Fläche.

Details & Threat-Model: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).
