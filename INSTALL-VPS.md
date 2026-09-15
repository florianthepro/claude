# Claude-Agent 24/7 auf einem Ubuntu-VPS

Ein dauerhaft laufender Claude-Code-Agent auf dem eigenen Server, gesteuert aus
`claude.ai/code` und der Claude-App. Ein einziges, selbstenthaltenes Skript.

---

## Setup

Das Repository ist **privat**, ein anonymer `curl` bekommt daher 404. Zwei Wege:

### A — mit GitHub-Token (Einzeiler)

Token mit `repo`-Leserecht auf https://github.com/settings/tokens erzeugen, dann
auf dem VPS als root:

```bash
export GH_TOKEN=ghp_dein_token

# Erst ansehen, was passieren würde:
curl -fsSL -H "Authorization: Bearer $GH_TOKEN" \
  https://raw.githubusercontent.com/florianthepro/claude/vps-install/install.sh \
  | bash -s -- --plan

# Dann ausführen:
curl -fsSL -H "Authorization: Bearer $GH_TOKEN" \
  https://raw.githubusercontent.com/florianthepro/claude/vps-install/install.sh \
  | bash -s -- --yes
```

Anschließend `unset GH_TOKEN` und ggf. `history -d` — der Token landet sonst in der
Shell-History.

### B — ohne Token (scp vom eigenen Rechner)

```bash
git clone -b vps-install https://github.com/florianthepro/claude.git
scp claude/install.sh root@DEIN_SERVER:/root/
ssh root@DEIN_SERVER 'bash /root/install.sh --plan'
ssh -t root@DEIN_SERVER 'bash /root/install.sh'
```

**`--plan` zuerst** ist ausdrücklich empfohlen: Es zeigt jeden Schritt mit allen
aufgelösten Werten und ändert nichts. `--plan` läuft auch ohne root.

---

## Nach dem Skript: drei Schritte von Hand

Der erste braucht einen Browser und lässt sich nicht automatisieren — Remote
Control verlangt ein vollwertiges Login und lehnt lange Tokens ausdrücklich ab
(*„Long-lived tokens … are limited to inference-only for security reasons"*).

```bash
# 1  Login — URL erscheint, im Browser öffnen, code#state zurück einfügen
sudo -u claude -H /home/claude/.local/bin/claude auth login --claudeai

# 2  Probelauf — fängt Erstlauf-Dialoge ab; bei claude.ai/code-URL: Ctrl+C
sudo -u claude -H /home/claude/.local/bin/claude remote-control --spawn same-dir

# 3  Dauerbetrieb
systemctl start claude-agent
```

Danach erscheint der Agent in `claude.ai/code` unter dem Hostnamen des Servers.

---

## Optionen

```
--plan                  Nur zeigen, was passieren würde
--yes, -y               Ohne Rückfrage ausführen (nötig bei curl | bash)
--user NAME             Dienstbenutzer (Default: claude)
--workspace PFAD        Arbeitsverzeichnis (Default: <home>/workspace)
--session-name NAME     Anzeigename in claude.ai/code (Default: Hostname)
--capacity N            Gleichzeitige Sessions (Default: 3)
--permission-mode MODE  default | acceptEdits | plan | dontAsk | bypassPermissions
--swap GRÖSSE           Swap-Datei, z.B. 4G (Default: 4G)
--skip-swap             Keinen Swap anlegen
--service-name NAME     Name der systemd-Unit (Default: claude-agent)
--harden-ssh            SSH absichern: ufw, fail2ban, Keepalives
--ssh-key "ssh-ed25519 AAAA..."   Public-Key hinterlegen
--disable-passwords     Passwort-Login abschalten (nur mit gültigem Schlüssel)
```

Beispiel:

```bash
bash install.sh --yes --session-name prod --capacity 5 \
                --ssh-key "ssh-ed25519 AAAA... ich@laptop" --disable-passwords
```

---

## Was das Skript tut

| # | Schritt |
|---|---|
| 0 | Preflight: root, systemd, Architektur, störende Env-Variablen, NTP, Erreichbarkeit aller nötigen Hosts |
| 1 | Basispakete (Pflicht bricht ab, optionale nicht) |
| 2 | Swap anlegen (schützt vor dem OOM-Killer) |
| 3 | TCP-Keepalives auf 120 s |
| 4 | Dienstbenutzer anlegen, Home aus `/etc/passwd` lesen |
| 5 | Claude Code installieren (`https://claude.ai/install.sh`) |
| 6 | Workspace-Trust setzen, Erstlauf-Dialoge unterdrücken |
| 7 | PTY-Supervisor ablegen |
| 8 | systemd-Unit schreiben |
| 9 | Timer: wöchentliches Update, Health-Check alle 15 min |
| 10 | Units aktivieren — der Dienst startet **nicht** automatisch |
| 11 | Optional: SSH absichern |

Das Skript ist **idempotent**. Ein erneuter Lauf aktualisiert die Konfiguration und
startet einen bereits laufenden Dienst neu; einen gestoppten lässt er gestoppt.

Nicht angefasst: Repository, Agenten-Start, vorhandene Firewall-Regeln.

---

## Warum es dieses Setup überhaupt braucht

Alles aus dem Claude-Code-Binary belegt, nicht vermutet.

**Remote Control gibt absichtlich auf.** Mehr als **3 Verbindungsabbrüche pro
rollender Stunde** (72 in 24 h) und die Bridge verbindet sich dauerhaft nicht mehr
neu; 10 störungsfreie Minuten setzen das Fenster zurück. Ohne Supervisor ist der
Agent danach endgültig weg.

**Das Budget ist schnell verbraucht.** Linux sendet TCP-Keepalives erst nach
**7200 s**, typische NAT-Idle-Timeouts liegen bei 5–30 min. Die langlebige
Poll-Verbindung fällt still aus der NAT-Tabelle. Deckt sich mit Anthropics eigener
Runner-Diagnose: *„ECONNRESET mid-poll | NAT/proxy idle-connection timeout"*.

**Daraus folgt: Persistenz allein löst das Problem nicht.** Ein reiner
systemd-Neustart ohne Keepalive-Korrektur verbraucht das 3-pro-Stunde-Budget
genauso schnell wie vorher. Das Skript adressiert beide Ebenen.

| Ursache | Gegenmaßnahme |
|---|---|
| NAT wirft langlebige Verbindungen weg | `tcp_keepalive_time = 120` statt 7200 |
| Remote Control gibt endgültig auf | `Restart=always` + `StartLimitIntervalSec=0` |
| SSH-Abbruch killt den Prozess | systemd statt SSH-Sitzung |
| Reboot | `WantedBy=multi-user.target` |
| OOM-Killer | Swap + `MemoryHigh=70%` |
| Zu alte Version wird abgewiesen | wöchentliches `claude update` |
| Abgelaufenes Login (stiller Killer) | Health-Timer alle 15 min |
| Refresh-Token läuft ab | Health-Check warnt 14 Tage vorher |
| Uhrzeit-Drift bricht Token-Erneuerung | NTP wird aktiviert |

### Egress: fünf Hosts, nicht einer

Aus dem Konfigurationsobjekt des CLI:

```
TOKEN_URL:     https://platform.claude.com/v1/oauth/token
AUTHORIZE_URL: https://claude.com/cai/oauth/authorize
API:           https://api.anthropic.com
```

Die **Token-Erneuerung läuft über `platform.claude.com`**, nicht über
`api.anthropic.com`. Eine Firewall, die „nur Anthropic" erlaubt, besteht jeden
Sofort-Test, läuft tagelang sauber und stirbt beim ersten Refresh. Dazu ist
`cdn.growthbook.io` eine harte Abhängigkeit — fehlt sie, verweigert Remote Control
den Start. Der Preflight prüft alle fünf.

### Warum ein eigener PTY-Supervisor

| Ansatz | Problem (gemessen) |
|---|---|
| `tmux new-session -d` unter systemd | Der tmux-Server überlebt den Tod des inneren Prozesses; systemd merkt den Absturz nie |
| `script -qfec CMD /dev/null` | Killt sein Kind bei SIGTERM hart; der Signal-Trap feuerte im Test nie, ein Prozess blieb zurück |
| eingebetteter Supervisor | PTY + sauberes SIGTERM + Exit-Status ans systemd |

Beim Stoppen greift eine gestaffelte Kette, damit die Bridge ihren eigenen Drain
fahren kann (Sessions einzeln beenden, Worktrees aufräumen, Resume-Zeiger schreiben):

```
1. systemd     --SIGTERM-->  Supervisor       KillMode=mixed: nur der Hauptprozess
2. Supervisor  --SIGTERM-->  claude bridge    nur das Kind, nicht die Gruppe
3. bridge      --SIGTERM-->  ihre Sessions    geordneter Drain
4. nach 25 s:  Supervisor --SIGKILL--> Prozessgruppe
5. nach 45 s:  systemd    --SIGKILL--> Rest der cgroup
```

---

## Betrieb

```bash
systemctl status claude-agent       # Status
journalctl -u claude-agent -f       # Live-Log
systemctl restart claude-agent      # Neustart
claude-agent-health                 # Health-Check von Hand
claude-harden-ssh --help            # SSH-Absicherung nachholen
```

### Verhalten, das man kennen sollte

- **Ein Neustart erzeugt eine neue Session-URL.** Der Agent erscheint weiter unter
  demselben Namen, die Session-ID wechselt. `--continue` würde die alte Session
  fortsetzen, funktioniert aber nur ~4 Stunden und bricht beim Kaltstart ab —
  deshalb bewusst nicht in der Unit.
- **Nur eine Instanz pro Verzeichnis.** Ein zweiter `claude remote-control` im
  selben Workspace beendet sich mit
  `Environment ... is already being served by another instance`.
  Also nicht zusätzlich von Hand in tmux starten.
- **Berechtigungen** stehen auf `default`: heikle Aktionen lösen eine Rückfrage
  aus, die in `claude.ai/code` erscheint.

---

## Fehlersuche

| Symptom | Ursache | Behebung |
|---|---|---|
| Dienst läuft, tut nichts | Erstlauf-Dialog wartet auf Eingabe | Schritt 2 von Hand |
| Startet und endet sofort mit 0 | Dialog las EOF → `process.exit(0)` | dito |
| Lief tagelang, dann tot | `platform.claude.com` blockiert → Refresh scheiterte | Egress öffnen, neu anmelden |
| `Couldn't verify Remote Control eligibility` | `cdn.growthbook.io` blockiert | Egress öffnen |
| Startet gar nicht | `ANTHROPIC_BASE_URL` / `ANTHROPIC_API_KEY` gesetzt | aus `/etc/environment` entfernen |
| `Workspace not trusted` | Workspace **ist** das Home-Verzeichnis | Unterverzeichnis nehmen |
| Viele Neustarts | Netz/NAT | `journalctl -u claude-agent --since '1 hour ago'` |
| `too old for Remote Control` | Version veraltet | `claude update`, dann Neustart |
| Alles grün, keine Reaktion | Login abgelaufen | `claude-agent-health` zeigt es |

Eingebauter Assistent: `sudo -u claude -H claude doctor`

---

## Sicherheit

- Der Agent läuft als **unprivilegierter Benutzer**, nicht als root, **ohne sudo**.
- systemd-Sandboxing: `NoNewPrivileges`, `PrivateTmp`, `ProtectSystem=full`,
  `ProtectKernelTunables`, `ProtectKernelModules`, `ProtectControlGroups`,
  `RestrictSUIDSGID`, `RestrictRealtime`, `LockPersonality`.
- **Kein eingehender Port** außer SSH. Die Steuerverbindung geht ausgehend — das
  ist der wesentliche Vorteil gegenüber einem exponierten Web-Terminal
  (ttyd/wetty/code-server).
- Berechtigungsmodus `default` statt `bypassPermissions`.
- Bewusst **kein** `OOMScoreAdjust`: der Wert wird an jeden Kindprozess vererbt
  und lenkt den OOM-Killer auf Systemdienste, im schlimmsten Fall `sshd`.

`--disable-passwords` ist mehrstufig abgesichert: Schlüssel werden einzeln mit
`ssh-keygen -l` **validiert** statt gezählt (ein umgebrochener Paste ergibt
mehrere Zeilen und hätte bei reiner Zählung ausgesperrt), ein fehlender
Zeilenumbruch in `authorized_keys` wird ergänzt, **root** wird mitgeprüft, das
Einbinden von `sshd_config.d` wird verifiziert, nach `sshd -t` folgt eine
Gegenprobe via `sshd -T`, und im Fehlerfall wird aus Sicherungen vollständig
zurückgerollt.

Wenn der Agent später auf ein privates Repository zugreifen soll: **Deploy-Key mit
Lesezugriff** unter `/home/claude/.ssh/`, kein persönlicher Account-Token.
