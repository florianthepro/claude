# Claude-Agent 24/7 auf dem Ubuntu-VPS

Dauerhaft laufender Claude-Code-Agent auf einem eigenen Server, steuerbar aus
`claude.ai/code` und der Claude-App. Ausgelegt auf den Fall, dass die Steuerverbindung
bisher ständig abriss.

---

## 1. Wichtig vorab: warum die Installation nicht von hier aus lief

Die Installation konnte aus dieser Claude-Session **nicht** per SSH ausgeführt werden.
Belegt, nicht vermutet:

| Prüfung | Ergebnis |
|---|---|
| `ssh` im Sandbox-Image | nicht vorhanden, `apt-get install openssh-client` → `403 Forbidden` (Egress-Policy) |
| TCP `169.58.41.105:22` | Timeout |
| TCP `github.com:22` (Gegenprobe) | ebenfalls Timeout → **Port 22 ist generell gesperrt**, nicht nur zum VPS |
| TCP `169.58.41.105:80/443` | `connect=0.0006 s`, HTTP 403 → lokaler Egress-Interceptor, nicht der VPS |
| `CONNECT 169.58.41.105:443` über den Proxy | `403` (Ziel nicht in der Egress-Allowlist) |

Die Sandbox erreicht ausschließlich freigegebene Hosts über einen HTTPS-Proxy. Ein
beliebiger VPS gehört nicht dazu.

**Unabhängig davon wäre eine vollautomatische Installation ohnehin nicht möglich:**
Remote Control verlangt ein vollwertiges interaktives Login. Lange Tokens aus
`claude setup-token` bzw. `CLAUDE_CODE_OAUTH_TOKEN` werden ausdrücklich abgelehnt —
Wortlaut aus dem Claude-Code-Binary:

> Remote Control requires a full-scope login token. Long-lived tokens (from
> `claude setup-token` or `CLAUDE_CODE_OAUTH_TOKEN`) are limited to inference-only
> for security reasons. Run `claude auth login` to use Remote Control.

Dieses eine Login muss ein Mensch durchführen. Alles andere erledigt das Skript.

---

## 2. Warum es bisher ständig disconnectete

Remote Control ist **kein 24/7-Primitiv**. Es gibt bei anhaltenden Störungen
absichtlich auf und beendet den Prozess. Die Meldungen stehen wörtlich im Binary:

```
could not reach the Remote Control server for about 30 minutes
the connection to the Remote Control server kept dropping after each reconnect
the connection to the Remote Control server dropped more than N times in 24 hours
Your version of Claude Code (...) is too old for Remote Control.
```

Ohne Supervisor ist der Agent nach so einem Abbruch **dauerhaft** weg — genau das
beobachtete Verhalten. Die eigentliche Ursache der Abbrüche benennt Anthropics eigene
Runner-Diagnose:

```
| ECONNRESET mid-poll | NAT / proxy idle-connection timeout dropping long-lived polls
                      | Raise NAT/proxy idle timeouts |
```

Das Budget ist knapp. Aus den Konstanten im Binary: **mehr als 3 Abbrüche in einer
rollenden Stunde** (bzw. 72 in 24 h) und die Bridge hört dauerhaft auf, sich neu zu
verbinden; eine störungsfreie Strecke von 10 Minuten setzt das Fenster zurück.

Linux setzt TCP-Keepalives standardmäßig erst nach **7200 s** (2 h) ab. Typische
NAT- und Firewall-Idle-Timeouts liegen bei 5–30 min. Die langlebige Poll-Verbindung
fliegt also still aus der NAT-Tabelle, lange bevor der Kernel das erste Keepalive sendet.

**Daraus folgt: Persistenz allein genügt nicht.** Ein reiner systemd-Neustart ohne
Keepalive-Korrektur verbraucht das 3-pro-Stunde-Budget genauso schnell wie vorher.
Deshalb adressiert das Setup beide Ebenen gleichzeitig.

### Der zweite, heimtückischere Kandidat: Token-Erneuerung

Aus dem Konfigurationsobjekt des Claude-Code-Binaries:

```
TOKEN_URL:     https://platform.claude.com/v1/oauth/token
AUTHORIZE_URL: https://claude.com/cai/oauth/authorize
API_KEY_URL:   https://api.anthropic.com/api/oauth/claude_cli/create_api_key
```

Die **Token-Erneuerung läuft über `platform.claude.com`, nicht über
`api.anthropic.com`.** Eine Firewall, die „nur Anthropic" erlaubt und dabei an
`api.anthropic.com` denkt, besteht jeden Sofort-Test, läuft stunden- bis tagelang
sauber — und stirbt beim ersten Refresh. Von außen nicht von einem Netzproblem zu
unterscheiden.

Benötigte ausgehende Hosts (alle 443/tcp):

| Host | Wofür |
|---|---|
| `api.anthropic.com` | Inferenz + Bridge-Polling |
| `platform.claude.com` | **Token-Erneuerung** |
| `claude.com` | Login (Authorize) |
| `claude.ai` | Session-Oberfläche |
| `cdn.growthbook.io` | Eligibility-Prüfung — ohne sie verweigert RC den Start |

Der Installer prüft alle fünf im Preflight.

**Das Setup adressiert beide Ebenen:**

| Ursache | Gegenmaßnahme |
|---|---|
| NAT wirft langlebige Verbindungen weg | `tcp_keepalive_time = 120` (statt 7200) |
| Remote Control gibt endgültig auf | systemd `Restart=always` + `StartLimitIntervalSec=0` |
| SSH-Abbruch killt den Prozess | Betrieb als systemd-Dienst statt in der SSH-Sitzung |
| Reboot | `WantedBy=multi-user.target` |
| OOM-Killer auf kleinem VPS | Swap + `MemoryHigh=70%` (bewusst **kein** `OOMScoreAdjust` — der wird an jeden Kindprozess vererbt und lenkt den OOM-Killer auf Systemdienste, im schlimmsten Fall `sshd`) |
| Zu alte Version wird abgewiesen | wöchentlicher `claude update` + Neustart |
| Abgelaufenes Login (stiller Killer) | Health-Timer alle 15 min, meldet es ins Journal |
| Refresh-Token läuft irgendwann ab | Health-Check warnt 14 Tage vorher |
| `platform.claude.com` blockiert | Preflight prüft den Host explizit |
| Uhrzeit-Drift bricht Token-Erneuerung | NTP wird aktiviert |

---

## 3. Architektur

```
   claude.ai/code  /  Claude-App                Anthropic Control-Plane
            │                                            │
            └────────────────────┬───────────────────────┘
                                 │  ausgehendes HTTPS-Long-Polling
                                 │  (kein eingehender Port nötig)
                    ┌────────────▼─────────────┐
                    │  VPS 169.58.41.105       │
                    │  systemd: claude-agent   │
                    │    └ PTY-Supervisor      │
                    │        └ claude          │
                    │          remote-control  │
                    │  Benutzer: claude        │
                    │  Workspace: ~/workspace  │
                    └──────────────────────────┘
```

`claude remote-control` ist ein **persistenter Server**, der mehrere gleichzeitige
Sessions bedient (`--capacity`). Die Verbindung geht **ausgehend** — es muss kein
einziger Port von außen geöffnet werden. Das ist der wesentliche Sicherheitsvorteil
gegenüber einem exponierten Web-Terminal (ttyd/wetty/code-server).

### Warum ein eigener PTY-Supervisor

Remote Control ist eine Terminal-Anwendung und braucht ein PTY. Die naheliegenden
Lösungen wurden getestet und verworfen:

| Ansatz | Problem (gemessen) |
|---|---|
| `tmux new-session -d` unter systemd | Der tmux-Server überlebt den Tod des inneren Prozesses; systemd merkt den Absturz nie und startet nicht neu |
| `script -qfec CMD /dev/null` | Liefert zwar ein PTY und reicht mit `-e` den Exit-Code durch, **killt sein Kind bei SIGTERM aber hart** (`Session terminated, killing shell... ...killed`) — der Signal-Trap des Kindes feuerte im Test nie, und ein Prozess blieb zurück |
| `claude-agent-run.py` (hier verwendet) | PTY + sauberes SIGTERM an die Prozessgruppe + Exit-Status ans systemd |

Beim Stoppen greift eine gestaffelte Kette, damit die Bridge ihren eigenen
geordneten Drain fahren kann (sie beendet ihre Sessions einzeln, räumt Worktrees
auf und schreibt einen Resume-Zeiger):

```
1. systemd     --SIGTERM-->  Supervisor       KillMode=mixed: nur der Hauptprozess
2. Supervisor  --SIGTERM-->  claude bridge    nur das Kind, nicht die Gruppe
3. bridge      --SIGTERM-->  ihre Sessions    geordneter Drain
4. nach 25 s:  Supervisor --SIGKILL--> Prozessgruppe
5. nach 45 s:  systemd    --SIGKILL--> Rest der cgroup
```

Ein Signal an die gesamte Prozessgruppe in Schritt 2 würde die Kindsessions
parallel treffen und Schritt 3 abschneiden — deshalb dort bewusst nur das Kind.

Messwerte des Supervisors:

```
PTY allokiert (/dev/pts/N), Fenstergröße 200x50   ✓
Exit-Code-Weitergabe:            42 → 42, 0 → 0, exec-Fehler → 127
Sauberer Stopp:                  Kind räumt auf, Supervisor endet mit 0
Hängendes Kind:                  SIGKILL nach Gnadenfrist, Exit 137, keine Waisen
Wartende Eingabeaufforderung:    erscheint nach 2 s im Journal
Normale Ausgabe:                 jede Zeile genau einmal, keine Duplikate
ANSI-Stripping:                  Journal bleibt lesbar
```

Der vorletzte Punkt ist nicht kosmetisch: Eine Eingabeaufforderung endet **nicht**
mit einem Zeilenumbruch. Ein Supervisor, der nur vollständige Zeilen ausgibt, hält
genau die Meldung zurück, die einen hängenden Dienst verrät — der Health-Check liefe
ins Leere. Deshalb wird ein angefangener Puffer nach 2 s Leerlauf trotzdem ausgegeben.

---

## 4. Installation

Der Installer trägt den Supervisor eingebettet — es genügen also zwei Dateien:

```bash
scp install-claude-agent.sh harden-ssh.sh root@169.58.41.105:/root/
ssh root@169.58.41.105
```

Das Repository ist **privat**, ein `curl | bash`-Einzeiler vom Server aus funktioniert
daher nicht. `scp` vom eigenen Rechner ist der Weg.

### Schritt 1 — Basis-Setup (automatisch)

```bash
bash /root/install-claude-agent.sh
```

Das Skript ist **idempotent** und erledigt: Pakete, Swap, TCP-Keepalives, NTP,
Dienstbenutzer `claude` (nicht root), Claude-Code-Installation, Workspace-Trust,
Unterdrückung der Erstlauf-Dialoge, systemd-Dienst, Update-Timer, Health-Timer.

Anpassbar über Umgebungsvariablen:

```bash
SESSION_NAME=vps-prod CAPACITY=5 bash install-claude-agent.sh
```

### Schritt 2 — Login (einmalig, interaktiv)

```bash
sudo -u claude -H /home/claude/.local/bin/claude auth login --claudeai
```

Ablauf: Das CLI meldet `Couldn't open your browser. Visit <URL>`. Diese URL am eigenen
Rechner im Browser öffnen, anmelden, und die zurückgegebene Zeichenkette (Format
`code#state`) bei `Paste code here if prompted >` einfügen.

Es gibt **keinen** Localhost-Callback zum Weiterleiten: der OAuth-Listener bindet auf
einem zufälligen Port, `ssh -L` funktioniert dafür nicht. Der Paste-Code-Weg ist der
vorgesehene.

Ergebnis ist `/home/claude/.claude/.credentials.json` (Modus 600, Klartext — auf Linux
gibt es keinen Keyring-Backend, das ist der normale unterstützte Fall). Nur dieser Weg
liefert den vollen Scope-Satz inklusive `user:profile`, den Remote Control verlangt,
und die automatische Hintergrund-Erneuerung.

### Schritt 3 — Probelauf, dann Dauerbetrieb

```bash
sudo -u claude -H /home/claude/.local/bin/claude remote-control --spawn same-dir
```

Fängt eventuelle Erstlauf-Dialoge ab und beweist, dass Login, Trust und Netzwerk
stimmen. Sobald eine `claude.ai/code`-URL erscheint: **Ctrl+C**, dann

```bash
systemctl start claude-agent
systemctl status claude-agent
```

Ab jetzt läuft der Agent dauerhaft und erscheint in `claude.ai/code` unter dem
Hostnamen des VPS.

### Schritt 4 — SSH absichern (empfohlen)

```bash
bash /root/harden-ssh.sh --key "ssh-ed25519 AAAA... dein-key"
bash /root/harden-ssh.sh --disable-passwords
```

Der Aussperr-Schutz ist mehrstufig:

- Schlüssel werden **einzeln mit `ssh-keygen -l` validiert**, nicht gezählt. Ein beim
  Kopieren umgebrochener Schlüssel ergibt mehrere Zeilen — eine Zeilenzählung hätte
  „3 Schlüssel vorhanden" gemeldet und den Passwort-Login abgeschaltet. Genau so
  sperrt man sich aus.
- Vor dem Anhängen an `authorized_keys` wird ein **fehlender Zeilenumbruch ergänzt**.
  Ohne das verschmilzt der neue mit dem letzten Schlüssel und **beide** werden unbrauchbar.
- Die Richtlinie wirkt global, deshalb wird auch **root** geprüft, nicht nur `--user`.
  `PermitRootLogin prohibit-password` sperrt root aus, wenn root keinen Schlüssel hat.
- Es wird geprüft, ob `sshd_config` das Verzeichnis `sshd_config.d` überhaupt
  **einbindet** — sonst wäre das Drop-in wirkungslos und die Erfolgsmeldung falsch.
- Nach `sshd -t` folgt eine **Gegenprobe am effektiven Ergebnis** (`sshd -T`), weil ein
  vorrangiger Match-Block die Einstellung aushebeln kann.
- Alle angefassten Dateien werden vorher gesichert; schlägt etwas fehl, wird
  **vollständig zurückgerollt** und nichts neu geladen.
- `reload` statt `restart`: die laufende Sitzung bleibt offen.
- Fehlt `ssh-keygen`, bricht das Skript ab, statt ungeprüft abzuschalten.

---

## 5. Betrieb

```bash
systemctl status claude-agent          # Status
journalctl -u claude-agent -f          # Live-Log
systemctl restart claude-agent         # Neustart
claude-agent-health                    # Health-Check von Hand
systemctl list-timers 'claude-agent*'  # Timer-Übersicht
```

### Verhalten, das man kennen sollte

- **Ein Neustart erzeugt eine neue Session-URL.** Der Agent erscheint weiterhin unter
  demselben Namen in `claude.ai/code`, die konkrete Session-ID wechselt aber.
  `--continue` würde die alte Session weiterführen, funktioniert jedoch nur innerhalb
  von ca. 4 Stunden und bricht bei einem Kaltstart mit Fehler ab — deshalb bewusst
  nicht im Dienst verwendet.
- **Nur eine Instanz pro Verzeichnis.** Ein zweiter `claude remote-control` im selben
  Workspace beendet sich mit
  `Environment ... is already being served by another instance (pid N)`.
  Also nicht zusätzlich von Hand in tmux starten.
- **Berechtigungen** stehen auf `default`: heikle Aktionen lösen eine Rückfrage aus,
  die in `claude.ai/code` erscheint. Bewusst **nicht** `bypassPermissions` — auf einer
  Maschine mit echten Zugangsdaten wäre das fahrlässig.

---

## 6. Fehlersuche

| Symptom | Ursache | Behebung |
|---|---|---|
| Dienst läuft, tut aber nichts | Erstlauf-Dialog wartet auf Eingabe | Schritt 3 von Hand ausführen |
| Dienst startet und endet sofort mit 0 | Dialog las EOF → `process.exit(0)` | dito |
| `Couldn't verify Remote Control eligibility` | `cdn.growthbook.io` blockiert | Egress für diesen Host öffnen |
| Startet gar nicht | `ANTHROPIC_BASE_URL` / `ANTHROPIC_API_KEY` / `ANTHROPIC_AUTH_TOKEN` gesetzt | aus `/etc/environment` und Shell-Profilen entfernen |
| `Workspace not trusted` | Trust fehlt oder Workspace **ist** das Home-Verzeichnis | Installer erneut ausführen; niemals `/home/claude` selbst nutzen |
| Viele Neustarts | Netz/NAT | `journalctl -u claude-agent --since '1 hour ago'`; Keepalives prüfen |
| `too old for Remote Control` | Version veraltet | `sudo -u claude -H claude update && systemctl restart claude-agent` |
| Alles grün, aber keine Reaktion | Login abgelaufen | `claude-agent-health` zeigt es; dann Schritt 2 wiederholen |
| Lief tagelang, dann tot | `platform.claude.com` blockiert → Refresh scheiterte | Egress für den Host öffnen, dann Schritt 2 |
| `CLAUDE_CODE_OAUTH_TOKEN` gesetzt | Überstimmt das echte Login und degradiert auf „inference-only" | Variable entfernen, Dienst neu starten |

Eingebauter Assistent:

```bash
sudo -u claude -H claude doctor
```

---

## 7. Sicherheit

**Sofort erledigen:** Das Root-Passwort dieses Servers wurde im Klartext über den Chat
übermittelt und steht damit dauerhaft in einem Gesprächsprotokoll. Es ist als
kompromittiert zu behandeln:

```bash
passwd root
```

Danach Schritt 4 (Schlüssel hinterlegen, Passwort-Login abschalten).

Weitere Maßnahmen im Setup:

- Der Agent läuft als **unprivilegierter Benutzer** `claude`, nicht als root, und hat
  **kein sudo**.
- systemd-Absicherung: `NoNewPrivileges`, `PrivateTmp`, `ProtectSystem=full`,
  `ProtectKernelTunables`, `ProtectKernelModules`, `ProtectControlGroups`,
  `RestrictSUIDSGID`, `RestrictRealtime`, `LockPersonality`.
- **Kein eingehender Port** außer SSH. `harden-ssh.sh` setzt ufw auf
  „eingehend alles zu außer 22/tcp" und aktiviert fail2ban.
- Berechtigungsmodus `default` statt `bypassPermissions`.

Wenn der Agent später auf ein privates GitHub-Repository zugreifen soll: einen
**Deploy-Key mit Lesezugriff** für `/home/claude/.ssh/` verwenden, keinen
persönlichen Account-Token. Zugangsdaten gehören nie ins Repository.

---

## 8. Alternative: Self-Hosted Runner

Es gibt einen zweiten, technisch saubereren Weg: `claude self-hosted-runner`. Dabei
bleibt die Control-Plane bei Anthropic, und Cloud-Sessions werden **auf dem eigenen
VPS** ausgeführt. Der Runner ist von Grund auf für Dauerbetrieb gebaut
(`/healthz`-Endpunkt, definierte Drain-Semantik, Lease-Handling) und braucht **kein**
interaktives Login — nur ein Environment-Secret.

**Voraussetzung:** Das Environment wird ausschließlich in der Admin-Oberfläche
angelegt (`claude.ai/admin-settings/cloud-environments` → *Allow self-hosted
environments* → *New*). Das setzt eine Organisation mit Admin-Einstellungen voraus
(Team/Enterprise) und steht bei einem persönlichen Abo nicht zur Verfügung.

Falls vorhanden, führt der eingebaute Assistent durch die Einrichtung:

```bash
claude self-hosted-runner setup
claude self-hosted-runner doctor   # bei Problemen
```

Für den Dauerbetrieb gelten dieselben Regeln: `Restart=always` (der Runner beendet
sich bei abgelaufenem Token bewusst mit 0 und heilt sich nicht selbst),
`--base-dir` immer explizit setzen (Default `/workspace` ist ein Container-Pfad), und
Flags mit Leerzeichen statt `=` schreiben.

---

## Dateien

| Datei | Zweck |
|---|---|
| `install-claude-agent.sh` | Vollständiges Setup, idempotent, Supervisor eingebettet |
| `claude-agent-run.py` | PTY-Supervisor (Einzeldatei zur Durchsicht; im Installer identisch eingebettet) |
| `harden-ssh.sh` | SSH-Absicherung mit Aussperr-Schutz, ufw, fail2ban |
