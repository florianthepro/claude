#!/usr/bin/env bash
#
# install-claude-agent.sh
#
# Richtet auf einem frischen Ubuntu-Server einen dauerhaft laufenden Claude-Code-Agenten ein,
# der ueber Remote Control aus claude.ai/code bzw. der Claude-App gesteuert wird.
#
# Kernproblem, das dieses Skript loest: Remote Control beendet sich absichtlich, wenn die
# Verbindung dauerhaft gestoert ist (belegt durch die Meldungen im Claude-Code-Binary:
#   "could not reach the Remote Control server for about 30 minutes"
#   "the connection to the Remote Control server kept dropping after each reconnect"
#   "the connection to the Remote Control server dropped more than N times in 24 hours").
# Ohne Supervisor ist der Agent danach dauerhaft weg. Gegenmassnahmen hier:
#   1. systemd mit Restart=always und StartLimitIntervalSec=0  -> gibt das Neustarten nie auf
#   2. TCP-Keepalives deutlich unter den ueblichen NAT-Idle-Timeouts
#      (offizielle Runner-Diagnose: "ECONNRESET mid-poll | NAT / proxy idle-connection timeout
#       dropping long-lived polls | Raise NAT/proxy idle timeouts")
#   3. Swap + OOMScoreAdjust, damit der Node-Prozess nicht vom OOM-Killer geholt wird
#   4. woechentliches "claude update", weil Remote Control alte Versionen abweist
#      ("Your version of Claude Code (...) is too old for Remote Control.")
#   5. Betrieb unter systemd statt in einer SSH-Sitzung -> ueberlebt SSH-Abbruch und Reboot
#
# Idempotent: kann beliebig oft erneut ausgefuehrt werden.
#
# Aufruf (als root):
#   bash install-claude-agent.sh
#
set -euo pipefail

# ---------------------------------------------------------------- Konfiguration
AGENT_USER="${AGENT_USER:-claude}"
AGENT_HOME="/home/${AGENT_USER}"
WORKSPACE="${WORKSPACE:-${AGENT_HOME}/workspace}"
SERVICE_NAME="${SERVICE_NAME:-claude-agent}"
SESSION_NAME="${SESSION_NAME:-$(hostname -s)}"
CAPACITY="${CAPACITY:-3}"
# Berechtigungsmodus der ferngesteuerten Sessions. "default" fragt bei heiklen Aktionen nach
# (die Rueckfrage erscheint in claude.ai/code). Bewusst NICHT bypassPermissions.
PERMISSION_MODE="${PERMISSION_MODE:-default}"
SWAP_SIZE="${SWAP_SIZE:-4G}"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- 0. Preflight
[[ $EUID -eq 0 ]] || die "Bitte als root ausfuehren."
[[ -d /run/systemd/system ]] || die "systemd nicht aktiv - dieses Setup benoetigt systemd."

. /etc/os-release
log "System: ${PRETTY_NAME:-unbekannt} ($(uname -m))"
[[ "${ID:-}" == "ubuntu" ]] || warn "Nicht Ubuntu (ID=${ID:-?}) - Skript ist auf Ubuntu ausgelegt, laeuft aber i.d.R. auf jedem systemd-Debian-Derivat."
case "$(uname -m)" in
  x86_64|aarch64) ;;
  *) die "Nicht unterstuetzte Architektur $(uname -m). Claude Code liefert Builds fuer x86_64 und aarch64." ;;
esac

# Remote Control verweigert den Dienst, wenn eine dieser Variablen gesetzt ist - es besteht
# ausdruecklich auf einem First-Party-Endpunkt (api.anthropic.com).
log "Pruefe stoerende Umgebungsvariablen"
BLOCKERS=""
for v in ANTHROPIC_BASE_URL ANTHROPIC_API_KEY ANTHROPIC_AUTH_TOKEN ANTHROPIC_UNIX_SOCKET \
         CLAUDE_CODE_USE_BEDROCK CLAUDE_CODE_USE_VERTEX; do
  if [[ -n "${!v:-}" ]] || grep -qsE "^\s*(export\s+)?${v}=" /etc/environment 2>/dev/null; then
    BLOCKERS="${BLOCKERS} ${v}"
  fi
done
if [[ -n "${BLOCKERS}" ]]; then
  warn "Gesetzt:${BLOCKERS}"
  warn "Remote Control startet damit NICHT. Aus /etc/environment und den Shell-Profilen entfernen."
  warn "Der systemd-Dienst erbt sie nicht - ein interaktives 'claude auth login' aber schon."
fi

# Zeitsynchronisation: Uhrzeit-Drift laesst die Token-Erneuerung fehlschlagen und wirkt
# spaeter wie ein zufaelliger Verbindungsabbruch.
if command -v timedatectl >/dev/null 2>&1; then
  if ! timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -q yes; then
    log "Aktiviere Zeitsynchronisation (Uhrzeit-Drift bricht die Token-Erneuerung)"
    timedatectl set-ntp true 2>/dev/null || true
  fi
fi

log "Pruefe Erreichbarkeit der benoetigten Endpunkte"
# Alle Hosts werden wirklich gebraucht - aus dem Konfigurationsobjekt des CLI:
#   TOKEN_URL:     https://platform.claude.com/v1/oauth/token  <- Token-Erneuerung
#   AUTHORIZE_URL: https://claude.com/cai/oauth/authorize      <- Login
#   API:           https://api.anthropic.com                   <- Inferenz + Bridge
#   cdn.growthbook.io                                          <- Eligibility-Pruefung
# platform.claude.com ist der heimtueckische Fall: eine Egress-Regel, die nur
# api.anthropic.com erlaubt, besteht jeden Sofort-Test und stirbt Stunden spaeter bei der
# ersten Token-Erneuerung - das sieht dann exakt aus wie ein zufaelliger Abbruch.
for host in api.anthropic.com platform.claude.com claude.com claude.ai cdn.growthbook.io; do
  if curl -fsS --max-time 15 -o /dev/null "https://${host}/" 2>/dev/null; then
    log "  ${host}: erreichbar"
  else
    # Ein 4xx ist hier voellig in Ordnung - es beweist, dass TLS steht.
    code=$(curl -s --max-time 15 -o /dev/null -w '%{http_code}' "https://${host}/" 2>/dev/null || echo 000)
    [[ "$code" != "000" ]] && log "  ${host}: erreichbar (HTTP ${code})" \
                           || die "${host} ist nicht erreichbar. Egress auf 443 pruefen, bevor es weitergeht."
  fi
done

# ---------------------------------------------------------------- 1. Basispakete
log "Installiere Basispakete"
export DEBIAN_FRONTEND=noninteractive
# Ein voruebergehend nicht erreichbarer Spiegel darf den Lauf nicht abbrechen.
apt-get update -qq || warn "apt-get update meldete Fehler - versuche die Installation trotzdem"

# Pflicht: ohne diese Pakete funktioniert das Setup nicht.
apt-get install -y -qq --no-install-recommends \
  ca-certificates curl git jq python3 util-linux procps >/dev/null \
  || die "Basispakete liessen sich nicht installieren."

# Kuer: nuetzlich, aber kein Grund zum Abbruch (build-essential ist gross und fehlt
# auf minimalen Images gelegentlich ganz).
for pkg in ripgrep unzip tmux unattended-upgrades build-essential; do
  apt-get install -y -qq --no-install-recommends "${pkg}" >/dev/null 2>&1 \
    || warn "  optionales Paket '${pkg}' nicht installiert - weiter"
done
log "  ok"

# ---------------------------------------------------------------- 2. Swap
# Swap ist eine Schutzmassnahme, keine Voraussetzung: schlaegt etwas fehl, wird gewarnt
# statt abgebrochen. Deshalb laeuft der ganze Block in einer Subshell ohne 'set -e'.
if swapon --show --noheadings 2>/dev/null | grep -q .; then
  log "Swap bereits vorhanden - uebersprungen"
else
  log "Lege Swap an (${SWAP_SIZE}) - schuetzt den Agenten vor dem OOM-Killer"
  if ( set +e
       # Ein vorhandenes, aber unbrauchbares /swapfile nicht blind weiterverwenden.
       if [[ -f /swapfile ]] && ! file /swapfile 2>/dev/null | grep -qi 'swap file'; then
         rm -f /swapfile
       fi
       if [[ ! -f /swapfile ]]; then
         # Auf btrfs muss Copy-on-Write fuer Swapdateien aus sein.
         if [[ "$(stat -f -c %T / 2>/dev/null)" == "btrfs" ]]; then
           truncate -s 0 /swapfile && chattr +C /swapfile 2>/dev/null
         fi
         fallocate -l "${SWAP_SIZE}" /swapfile 2>/dev/null \
           || dd if=/dev/zero of=/swapfile bs=1M count="$(numfmt --from=iec "${SWAP_SIZE}" 2>/dev/null | awk '{print int($1/1048576)}')" status=none
         [[ -s /swapfile ]] || exit 1
         chmod 600 /swapfile
         mkswap -q /swapfile >/dev/null 2>&1 || exit 1
       fi
       chmod 600 /swapfile
       swapon /swapfile 2>/dev/null || exit 1
       grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
     ); then
    log "  Swap aktiv"
  else
    warn "  Swap konnte nicht eingerichtet werden - weiter ohne. Bei wenig RAM den OOM-Killer im Auge behalten."
  fi
fi
sysctl -qw vm.swappiness=10
printf 'vm.swappiness=10\n' > /etc/sysctl.d/98-claude-swappiness.conf

# ---------------------------------------------------------------- 3. TCP-Keepalives
# Linux-Default ist tcp_keepalive_time=7200 (2 h). Typische NAT-/Firewall-Idle-Timeouts liegen
# bei 5-30 min. Die langlebige Remote-Control-Verbindung wird deshalb stillschweigend aus der
# NAT-Tabelle geworfen -> ECONNRESET -> Reconnect-Schleife -> Remote Control gibt auf.
# 120 s Keepalive liegt unter jedem gaengigen NAT-Timeout und haelt den Eintrag warm.
log "Setze TCP-Keepalives (Kernfix gegen NAT-bedingte Verbindungsabbrueche)"
cat > /etc/sysctl.d/99-claude-keepalive.conf <<'EOF'
# Haelt langlebige Verbindungen (Claude Remote Control) in NAT-/Firewall-Tabellen warm.
net.ipv4.tcp_keepalive_time = 120
net.ipv4.tcp_keepalive_intvl = 30
net.ipv4.tcp_keepalive_probes = 8
# Abgebrochene Verbindungen schneller erkennen statt minutenlang haengen.
net.ipv4.tcp_retries2 = 8
EOF
sysctl -q --system >/dev/null
log "  tcp_keepalive_time=$(cat /proc/sys/net/ipv4/tcp_keepalive_time)s"

# ---------------------------------------------------------------- 4. Dienstbenutzer
if ! id -u "${AGENT_USER}" >/dev/null 2>&1; then
  log "Lege Dienstbenutzer '${AGENT_USER}' an (der Agent laeuft NICHT als root)"
  adduser --disabled-password --gecos "Claude Code Agent" "${AGENT_USER}" >/dev/null 2>&1 \
    || useradd -m -s /bin/bash -c "Claude Code Agent" "${AGENT_USER}" \
    || die "Dienstbenutzer '${AGENT_USER}' konnte nicht angelegt werden."
else
  log "Benutzer '${AGENT_USER}' existiert bereits"
fi
install -d -o "${AGENT_USER}" -g "${AGENT_USER}" -m 0755 "${WORKSPACE}"
# Lingering: erlaubt Benutzerprozesse ohne aktive Login-Sitzung (relevant fuer tmux-Debugging).
loginctl enable-linger "${AGENT_USER}" >/dev/null 2>&1 || true

# ---------------------------------------------------------------- 5. Claude Code installieren
CLAUDE_BIN=""
find_claude() {
  for p in "${AGENT_HOME}/.local/bin/claude" /usr/local/bin/claude /opt/claude-code/bin/claude; do
    [[ -x "$p" ]] && { echo "$p"; return 0; }
  done
  sudo -u "${AGENT_USER}" -H bash -lc 'command -v claude' 2>/dev/null || true
}
CLAUDE_BIN="$(find_claude)"
if [[ -z "${CLAUDE_BIN}" ]]; then
  log "Installiere Claude Code (offizieller Installer https://claude.ai/install.sh)"
  sudo -u "${AGENT_USER}" -H bash -lc 'curl -fsSL https://claude.ai/install.sh | bash' \
    || die "Installation von Claude Code fehlgeschlagen."
  CLAUDE_BIN="$(find_claude)"
  [[ -n "${CLAUDE_BIN}" ]] || die "Claude Code wurde installiert, aber die Binary wurde nicht gefunden."
else
  log "Claude Code bereits vorhanden: ${CLAUDE_BIN}"
fi
CLAUDE_VERSION="$(sudo -u "${AGENT_USER}" -H "${CLAUDE_BIN}" --version 2>/dev/null | head -1 || echo 'unbekannt')"
log "  Version: ${CLAUDE_VERSION}"
# Bequemer Aufruf als 'claude' fuer root. Wichtig: NICHT verlinken, wenn die Binary
# bereits selbst unter /usr/local/bin/claude liegt - ein 'ln -sf X X' wuerde die echte
# Datei durch einen Selbstverweis ersetzen und die Installation zerstoeren.
if [[ "${CLAUDE_BIN}" != "/usr/local/bin/claude" ]] \
   && [[ "$(readlink -f "${CLAUDE_BIN}")" != "$(readlink -f /usr/local/bin/claude 2>/dev/null || echo '')" ]]; then
  ln -sfn "${CLAUDE_BIN}" /usr/local/bin/claude
fi

# ---------------------------------------------------------------- 6. Workspace-Trust vorsetzen
# Remote Control verweigert den Start in einem nicht vertrauten Verzeichnis. Der interaktive
# Trust-Dialog laesst sich nicht skripten, aber das Binary nennt selbst den Ersatzweg:
#   "Run Claude Code interactively here once and accept the trust dialog, or set
#    projects[<pfad>].hasTrustDialogAccepted: true in ~/.claude.json"
# Wichtig: Das Arbeitsverzeichnis darf NICHT das Home-Verzeichnis sein - fuer $HOME wird
# Trust grundsaetzlich nicht gespeichert. Deshalb der Unterordner ${WORKSPACE}.
# Zusaetzlich zwei globale Schalter vorsetzen:
#   remoteDialogSeen       -> unterdrueckt den blockierenden Erstlauf-Dialog
#                             "Enable Remote Control? (y/n)"
#   remoteControlSpawnMode -> unterdrueckt die Rueckfrage "Choose [1/2]" in Git-Verzeichnissen
# Beide Dialoge lesen von stdin. Mit PTY wuerde der Dienst dort ewig haengen, ohne PTY liest
# er EOF und beendet sich sofort mit Status 0 - beides sieht nach "Agent laeuft nicht" aus,
# ohne dass eine Fehlermeldung erscheint.
log "Setze Workspace-Trust fuer ${WORKSPACE} und unterdruecke Erstlauf-Dialoge"
sudo -u "${AGENT_USER}" -H WORKSPACE="${WORKSPACE}" python3 - <<'PYEOF'
import json, os, pathlib, tempfile

cfg_path = pathlib.Path(os.path.expanduser("~/.claude.json"))
workspace = os.environ["WORKSPACE"]

try:
    cfg = json.loads(cfg_path.read_text()) if cfg_path.exists() else {}
    if not isinstance(cfg, dict):
        cfg = {}
except (json.JSONDecodeError, OSError):
    cfg = {}

projects = cfg.setdefault("projects", {})
if not isinstance(projects, dict):
    projects = {}
    cfg["projects"] = projects

entry = projects.get(workspace)
if not isinstance(entry, dict):
    entry = {}

# Form entspricht dem Default-Projektobjekt des CLI.
entry.setdefault("allowedTools", [])
entry.setdefault("mcpContextUris", [])
entry.setdefault("mcpServers", {})
entry.setdefault("enabledMcpjsonServers", [])
entry.setdefault("disabledMcpjsonServers", [])
entry["hasTrustDialogAccepted"] = True
entry.setdefault("hasClaudeMdExternalIncludesApproved", False)
entry.setdefault("hasClaudeMdExternalIncludesWarningShown", False)
projects[workspace] = entry

# Globale Schalter - gleiche Ebene wie "theme" / "autoUpdates".
cfg["remoteDialogSeen"] = True
cfg.setdefault("remoteControlSpawnMode", "same-dir")

# Atomar schreiben, damit ein parallel laufendes CLI keine halbe Datei sieht.
fd, tmp = tempfile.mkstemp(dir=str(cfg_path.parent), prefix=".claude.json.")
try:
    with os.fdopen(fd, "w") as fh:
        json.dump(cfg, fh, indent=2)
    os.chmod(tmp, 0o600)
    os.replace(tmp, cfg_path)
except BaseException:
    os.unlink(tmp)
    raise

print(f"    Trust gesetzt: {workspace}")
print("    Erstlauf-Dialoge unterdrueckt (remoteDialogSeen, remoteControlSpawnMode)")
PYEOF

# ---------------------------------------------------------------- 7a. PTY-Supervisor
# Remote Control ist eine Terminal-Anwendung und braucht ein PTY. Der naheliegende
# Wrapper `script -qfec` liefert zwar eines, killt sein Kind bei SIGTERM aber hart und
# laesst dabei nachweislich Prozesse zurueck - kein geordnetes Herunterfahren.
# Dieser Supervisor legt ein PTY an, reicht SIGTERM sauber an die Prozessgruppe weiter
# und gibt den Exit-Status des Kindes an systemd zurueck.
log "Installiere PTY-Supervisor"
install -d -m 0755 /usr/local/lib/claude-agent
cat > /usr/local/lib/claude-agent/claude-agent-run.py <<'PY_SUPERVISOR_EOF'
#!/usr/bin/env python3
"""
PTY-Supervisor fuer den Claude-Code-Agenten unter systemd.

Warum das noetig ist:
  Claude Code Remote Control ist eine Terminal-Anwendung und erwartet ein PTY.
  Der naheliegende Wrapper `script -qfec CMD /dev/null` liefert zwar ein PTY und
  reicht mit -e den Exit-Code durch, killt sein Kind bei SIGTERM aber hart
  ("Session terminated, killing shell... ...killed") - ein sauberes Herunterfahren
  ist damit nicht moeglich, und in-flight-Arbeit geht verloren.

Dieser Supervisor macht beides richtig:
  * legt ein echtes PTY an (pty.fork setzt setsid + TIOCSCTTY) und setzt eine
    definierte Fenstergroesse, damit die TUI nicht in einen 0x0-Zustand laeuft,
  * leitet SIGTERM/SIGINT saeuberlich an das Kind weiter und raeumt erst nach
    einer Gnadenfrist mit SIGKILL auf,
  * beendet sich mit dem Exit-Status des Kindes, damit systemd echte Abstuerze
    von einem geordneten Stopp unterscheiden kann,
  * schreibt die Ausgabe zeilenweise nach stdout (journald), optional ohne
    ANSI-Steuerzeichen, damit `journalctl` lesbar bleibt.

Aufruf:
    claude-agent-run.py [--grace SEKUNDEN] [--keep-ansi] -- BEFEHL [ARGS...]
"""

import argparse
import errno
import fcntl
import os
import pty
import re
import select
import signal
import struct
import sys
import termios
import time

ANSI_RE = re.compile(rb"\x1b\[[0-9;?]*[ -/]*[@-~]|\x1b[@-Z\\-_]|\r")
COLS, ROWS = 200, 50


def set_winsize(fd: int, rows: int, cols: int) -> None:
    """Definierte Fenstergroesse setzen - eine TUI ohne Groesse rendert unbrauchbar."""
    try:
        fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack("HHHH", rows, cols, 0, 0))
    except OSError:
        pass


def main() -> int:
    ap = argparse.ArgumentParser(add_help=True)
    ap.add_argument("--grace", type=float, default=25.0,
                    help="Sekunden, die dem Kind nach SIGTERM bleiben (Default: 25)")
    ap.add_argument("--keep-ansi", action="store_true",
                    help="ANSI-Steuerzeichen im Log behalten")
    ap.add_argument("cmd", nargs=argparse.REMAINDER,
                    help="-- gefolgt vom auszufuehrenden Befehl")
    args = ap.parse_args()

    cmd = args.cmd[1:] if args.cmd and args.cmd[0] == "--" else args.cmd
    if not cmd:
        print("claude-agent-run: kein Befehl angegeben", file=sys.stderr)
        return 2

    pid, master_fd = pty.fork()
    if pid == 0:
        # Kindprozess: hat jetzt ein eigenes PTY als kontrollierendes Terminal.
        try:
            os.execvp(cmd[0], cmd)
        except OSError as exc:
            # Direkt auf fd 2 schreiben - print() koennte hier schon gepuffert sein.
            os.write(2, f"claude-agent-run: exec fehlgeschlagen: {exc}\n".encode())
            os._exit(127)

    set_winsize(master_fd, ROWS, COLS)

    stopping = False
    deadline = None

    def request_stop(signum, _frame):
        """SIGTERM/SIGINT an das Kind weiterreichen statt es hart zu killen."""
        nonlocal stopping, deadline
        if stopping:
            return
        stopping = True
        deadline = time.monotonic() + args.grace
        sys.stdout.write(
            f"[supervisor] Signal {signum} empfangen, "
            f"leite SIGTERM an pid {pid} weiter (Gnadenfrist {args.grace:.0f}s)\n")
        sys.stdout.flush()
        # SIGTERM bewusst NUR an das Kind, nicht an die ganze Gruppe: die Bridge fährt
        # beim Beenden einen geordneten Drain (SIGTERM an jede Session, Aufraeumen von
        # Worktrees, Schreiben des Resume-Zeigers). Ein Signal an die gesamte Gruppe
        # wuerde die Kindsessions parallel treffen und diesen Ablauf abschneiden.
        # Fuer den harten SIGKILL nach Ablauf der Gnadenfrist gilt das nicht - dort wird
        # die ganze Gruppe beendet, damit keine Waisen zurueckbleiben.
        try:
            os.kill(pid, signal.SIGTERM)
        except ProcessLookupError:
            pass

    signal.signal(signal.SIGTERM, request_stop)
    signal.signal(signal.SIGINT, request_stop)
    signal.signal(signal.SIGHUP, signal.SIG_IGN)

    out = sys.stdout.buffer
    buf = b""
    status = None

    while True:
        # Kind einsammeln, sobald es weg ist.
        if status is None:
            try:
                wpid, wstatus = os.waitpid(pid, os.WNOHANG)
                if wpid == pid:
                    status = wstatus
            except ChildProcessError:
                status = 0

        try:
            ready, _, _ = select.select([master_fd], [], [], 0.25)
        except InterruptedError:
            continue
        except OSError as exc:
            if exc.errno == errno.EBADF:
                break
            raise

        if ready:
            try:
                chunk = os.read(master_fd, 65536)
            except OSError:
                chunk = b""      # PTY zu: das Kind hat sich verabschiedet
            if chunk:
                buf += chunk
                # Zeilenweise ausgeben, damit journald saubere Eintraege bekommt.
                while b"\n" in buf:
                    line, buf = buf.split(b"\n", 1)
                    if not args.keep_ansi:
                        line = ANSI_RE.sub(b"", line)
                    line = line.rstrip()
                    if line:
                        out.write(line + b"\n")
                out.flush()
            elif status is not None:
                break

        if status is not None and not ready:
            break

        # Gnadenfrist abgelaufen: jetzt hart beenden.
        if stopping and deadline and time.monotonic() > deadline and status is None:
            sys.stdout.write("[supervisor] Gnadenfrist abgelaufen, sende SIGKILL\n")
            sys.stdout.flush()
            try:
                os.killpg(pid, signal.SIGKILL)
            except (ProcessLookupError, PermissionError):
                try:
                    os.kill(pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
            deadline = None

    # Rest des Puffers ausgeben.
    if buf:
        if not args.keep_ansi:
            buf = ANSI_RE.sub(b"", buf)
        buf = buf.rstrip()
        if buf:
            out.write(buf + b"\n")
    out.flush()

    try:
        os.close(master_fd)
    except OSError:
        pass

    if status is None:
        try:
            _, status = os.waitpid(pid, 0)
        except ChildProcessError:
            status = 0

    # Exit-Status so zurueckgeben, dass systemd Absturz und Stopp unterscheiden kann.
    if os.WIFSIGNALED(status):
        return 128 + os.WTERMSIG(status)
    return os.WEXITSTATUS(status)


if __name__ == "__main__":
    sys.exit(main())
PY_SUPERVISOR_EOF
chmod 0755 /usr/local/lib/claude-agent/claude-agent-run.py
python3 -c "import ast,sys; ast.parse(open('/usr/local/lib/claude-agent/claude-agent-run.py').read())" \
  || die "PTY-Supervisor ist syntaktisch fehlerhaft - Abbruch vor der Service-Einrichtung."
SUPERVISOR=/usr/local/lib/claude-agent/claude-agent-run.py
log "  ok"

# ---------------------------------------------------------------- 7. systemd-Service
# Remote Control ist laut Binary eine "(interactive terminal) session" und braucht ein PTY.
# `script -qfec CMD /dev/null` stellt ein echtes PTY bereit, laeuft im Vordergrund und gibt
# mit -e den Exit-Code des Kindes zurueck. Damit sieht systemd echte Abstuerze und kann
# zuverlaessig neu starten - anders als bei `tmux new-session -d`, wo der tmux-Server
# weiterlebt und systemd den Tod des inneren Prozesses nie bemerkt.
log "Schreibe systemd-Unit ${SERVICE_NAME}.service"
cat > "/etc/systemd/system/${SERVICE_NAME}.service" <<EOF
[Unit]
Description=Claude Code Agent (Remote Control)
Documentation=https://claude.ai/code
After=network-online.target
Wants=network-online.target
# Niemals aufgeben: ohne Start-Limit schaltet systemd den Dienst nie dauerhaft ab.
StartLimitIntervalSec=0

[Service]
Type=simple
User=${AGENT_USER}
Group=${AGENT_USER}
WorkingDirectory=${WORKSPACE}

Environment=HOME=${AGENT_HOME}
Environment=TERM=xterm-256color
Environment=LANG=C.UTF-8
Environment=PATH=${AGENT_HOME}/.local/bin:/usr/local/bin:/usr/bin:/bin

ExecStart=/usr/bin/python3 ${SUPERVISOR} --grace 25 -- \
    ${CLAUDE_BIN} remote-control \
    --name ${SESSION_NAME} \
    --capacity ${CAPACITY} \
    --spawn same-dir \
    --permission-mode ${PERMISSION_MODE}

Restart=always
RestartSec=5s
# TimeoutStopSec > Gnadenfrist des Supervisors (25s), sonst greift systemd zu frueh durch.
TimeoutStopSec=45s
# mixed: SIGTERM geht nur an den Supervisor; der reicht es geordnet an die Sitzung weiter.
KillMode=mixed
KillSignal=SIGTERM

# OOM: lieber etwas anderes opfern als den Agenten.
OOMScoreAdjust=-500

# Moderate Absicherung. Bewusst nicht strenger: der Agent soll normale Entwicklungsarbeit
# im Workspace erledigen koennen.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=false
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictSUIDSGID=true
RestrictRealtime=true
LockPersonality=true

StandardOutput=journal
StandardError=journal
SyslogIdentifier=${SERVICE_NAME}

[Install]
WantedBy=multi-user.target
EOF

# ---------------------------------------------------------------- 8. Update-Timer
# Remote Control weist zu alte Clients ab. Ein woechentliches Update haelt den Agenten
# anschlussfaehig; der Neustart danach ist kurz und faellt in ein Wartungsfenster.
log "Schreibe woechentlichen Update-Timer"
cat > "/etc/systemd/system/${SERVICE_NAME}-update.service" <<EOF
[Unit]
Description=Claude Code aktualisieren und Agent neu starten

[Service]
Type=oneshot
User=${AGENT_USER}
Environment=HOME=${AGENT_HOME}
Environment=PATH=${AGENT_HOME}/.local/bin:/usr/local/bin:/usr/bin:/bin
ExecStart=${CLAUDE_BIN} update
# Neustart als root, deshalb das "+" (privilegiert ausgefuehrt).
# --no-block ist wichtig: ohne das wartet diese Unit auf den Neustart-Job, was aus einer
# laufenden Unit heraus im ungluecklichen Fall in einen Transaktions-Deadlock laeuft.
ExecStartPost=+/bin/systemctl try-restart --no-block ${SERVICE_NAME}.service
# Ein fehlgeschlagenes Update darf den Agenten nicht beeintraechtigen.
SuccessExitStatus=0 1
EOF

cat > "/etc/systemd/system/${SERVICE_NAME}-update.timer" <<EOF
[Unit]
Description=Woechentliches Claude-Code-Update

[Timer]
OnCalendar=Sun 04:30
RandomizedDelaySec=30m
Persistent=true

[Install]
WantedBy=timers.target
EOF

# ---------------------------------------------------------------- 9. Health-Watchdog
# systemd faengt Abstuerze ab. Was es nicht sieht: ein abgelaufenes Login. Genau das ist der
# stille Killer eines unbeaufsichtigten Agenten - der Prozess laeuft, kann sich aber nicht
# mehr anmelden. Der Check protokolliert das deutlich ins Journal.
log "Schreibe Health-Watchdog"
cat > "/usr/local/bin/${SERVICE_NAME}-health" <<EOF
#!/usr/bin/env bash
set -uo pipefail
SERVICE="${SERVICE_NAME}"
CLAUDE_BIN="${CLAUDE_BIN}"
AGENT_USER="${AGENT_USER}"
AGENT_HOME="${AGENT_HOME}"
EOF
cat >> "/usr/local/bin/${SERVICE_NAME}-health" <<'EOF'

fail=0

if ! systemctl is-active --quiet "${SERVICE}.service"; then
  echo "KRITISCH: ${SERVICE}.service ist nicht aktiv - starte neu"
  systemctl restart "${SERVICE}.service"
  fail=1
fi

# Auth-Status pruefen. Laeuft als Dienstbenutzer, damit dieselben Credentials gelesen werden.
# Die drei Felder aus 'auth status --json' entscheiden, ob Remote Control ueberhaupt starten
# kann: loggedIn, apiProvider (muss firstParty sein) und authMethod.
auth_json="$(sudo -u "${AGENT_USER}" -H env HOME="${AGENT_HOME}" \
              "${CLAUDE_BIN}" auth status --json 2>/dev/null || true)"
if [[ -z "${auth_json}" ]]; then
  echo "WARNUNG: 'claude auth status' lieferte keine Ausgabe."
  fail=1
else
  logged_in="$(echo "${auth_json}" | jq -r '.loggedIn // false' 2>/dev/null || echo unknown)"
  provider="$(echo "${auth_json}"  | jq -r '.apiProvider // ""' 2>/dev/null || echo '')"
  method="$(echo "${auth_json}"    | jq -r '.authMethod // ""' 2>/dev/null || echo '')"

  if [[ "${logged_in}" != "true" ]]; then
    echo "KRITISCH: Claude-Login ist abgelaufen oder fehlt."
    echo "  Beheben:  sudo -u ${AGENT_USER} -H ${CLAUDE_BIN} auth login --claudeai"
    echo "  danach:   systemctl restart ${SERVICE}.service"
    fail=1
  elif [[ -n "${provider}" && "${provider}" != "firstParty" ]]; then
    # Remote Control laeuft ausschliesslich gegen api.anthropic.com.
    echo "KRITISCH: apiProvider ist '${provider}', Remote Control verlangt 'firstParty'."
    echo "  Bedrock/Vertex/Gateway/ANTHROPIC_BASE_URL aus der Umgebung entfernen."
    fail=1
  elif [[ "${method}" == "oauth_token" ]]; then
    # Ein Token aus CLAUDE_CODE_OAUTH_TOKEN ist auf 'inference-only' beschraenkt und
    # ueberstimmt dabei das echte Login - Remote Control lehnt es ab.
    echo "KRITISCH: Angemeldet ueber ein langlebiges Token (authMethod=oauth_token)."
    echo "  Remote Control akzeptiert das nicht ('limited to inference-only')."
    echo "  CLAUDE_CODE_OAUTH_TOKEN entfernen und neu anmelden:"
    echo "            sudo -u ${AGENT_USER} -H ${CLAUDE_BIN} auth login --claudeai"
    fail=1
  fi
fi

# Das Refresh-Token hat ein eigenes Ablaufdatum. Das CLI warnt nur in der interaktiven
# Oberflaeche - die sieht auf einem Server niemand. Auch ein perfekt eingerichteter Agent
# braucht deshalb irgendwann wieder ein menschliches Login.
CRED="${AGENT_HOME}/.claude/.credentials.json"
if [[ -r "${CRED}" ]] && command -v jq >/dev/null 2>&1; then
  # Feld kann verschachtelt liegen, deshalb rekursiv suchen. Wert in Millisekunden.
  exp_ms="$(jq -r '[.. | objects | .refreshTokenExpiresAt? // empty] | first // empty' "${CRED}" 2>/dev/null)"
  if [[ "${exp_ms}" =~ ^[0-9]+$ ]]; then
    days=$(( (exp_ms / 1000 - $(date +%s)) / 86400 ))
    if (( days < 0 )); then
      echo "KRITISCH: Das Refresh-Token ist seit $(( -days )) Tagen abgelaufen - neues Login noetig."
      echo "  sudo -u ${AGENT_USER} -H ${CLAUDE_BIN} auth login --claudeai"
      fail=1
    elif (( days < 14 )); then
      echo "WARNUNG: Das Refresh-Token laeuft in ${days} Tagen ab. Login rechtzeitig erneuern."
      fail=1
    fi
  fi
  perms="$(stat -c '%a' "${CRED}" 2>/dev/null || echo '')"
  if [[ -n "${perms}" && "${perms}" != "600" ]]; then
    echo "WARNUNG: ${CRED} hat Rechte ${perms}, erwartet 600."
    fail=1
  fi
fi

# Blockierender Erstlauf-Dialog: der Dienst laeuft, wartet aber auf eine Eingabe,
# die unter systemd nie kommt. Von aussen sieht das aus wie "haengt einfach".
if journalctl -u "${SERVICE}.service" --since '10 min ago' --no-pager 2>/dev/null \
   | grep -qiE 'Enable Remote Control\? \(y/n\)|Choose \[1/2\]'; then
  echo "KRITISCH: Remote Control wartet auf eine Erstlauf-Eingabe und kommt nicht weiter."
  echo "  Beheben:  einmalig interaktiv starten:"
  echo "            sudo -u ${AGENT_USER} -H ${CLAUDE_BIN} remote-control --spawn same-dir"
  echo "            Dialog beantworten, mit Ctrl+C beenden, dann:"
  echo "            systemctl restart ${SERVICE}.service"
  fail=1
fi

# Remote Control gibt nach anhaltenden Stoerungen absichtlich auf und beendet sich.
# systemd startet dann neu - haeufen sich diese Neustarts, liegt es am Netz, nicht am Dienst.
if journalctl -u "${SERVICE}.service" --since '1 hour ago' --no-pager 2>/dev/null \
   | grep -qiE 'too old for Remote Control|kept dropping after each reconnect|could not reach the Remote Control server|giving up'; then
  echo "WARNUNG: Remote Control meldet anhaltende Verbindungs- oder Versionsprobleme."
  echo "  Pruefen: journalctl -u ${SERVICE}.service --since '1 hour ago'"
  fail=1
fi

# Haeufige Neustarts deuten auf ein Netz- oder Konfigurationsproblem hin.
restarts="$(systemctl show -p NRestarts --value "${SERVICE}.service" 2>/dev/null || echo 0)"
if [[ "${restarts:-0}" =~ ^[0-9]+$ ]] && (( restarts > 20 )); then
  echo "WARNUNG: ${restarts} Neustarts seit dem letzten Boot - Verbindung oder Konfiguration pruefen."
  fail=1
fi

[[ ${fail} -eq 0 ]] && echo "OK: Agent laeuft, Login gueltig."
exit 0
EOF
chmod 0755 "/usr/local/bin/${SERVICE_NAME}-health"

cat > "/etc/systemd/system/${SERVICE_NAME}-health.service" <<EOF
[Unit]
Description=Health-Check des Claude-Agenten

[Service]
Type=oneshot
ExecStart=/usr/local/bin/${SERVICE_NAME}-health
SyslogIdentifier=${SERVICE_NAME}-health
EOF

cat > "/etc/systemd/system/${SERVICE_NAME}-health.timer" <<EOF
[Unit]
Description=Health-Check des Claude-Agenten (alle 15 Minuten)

[Timer]
OnBootSec=5min
OnUnitActiveSec=15min

[Install]
WantedBy=timers.target
EOF

# ---------------------------------------------------------------- 10. Aktivieren
log "Aktiviere Units"
systemctl daemon-reload
systemctl enable --quiet "${SERVICE_NAME}.service"
systemctl enable --quiet --now "${SERVICE_NAME}-update.timer"
systemctl enable --quiet --now "${SERVICE_NAME}-health.timer"
systemctl enable --quiet --now unattended-upgrades.service 2>/dev/null || true

# ---------------------------------------------------------------- Abschluss
AUTH_OK=0
AUTH_JSON="$(sudo -u "${AGENT_USER}" -H env HOME="${AGENT_HOME}" "${CLAUDE_BIN}" auth status --json 2>/dev/null || true)"
if [[ -n "${AUTH_JSON}" ]] \
   && [[ "$(echo "${AUTH_JSON}" | jq -r '.loggedIn // false' 2>/dev/null)" == "true" ]] \
   && [[ "$(echo "${AUTH_JSON}" | jq -r '.authMethod // ""' 2>/dev/null)" != "oauth_token" ]]; then
  AUTH_OK=1
fi

echo
echo "================================================================"
echo " Basis-Setup abgeschlossen."
echo "================================================================"
echo "  Benutzer      : ${AGENT_USER}  (nicht root)"
echo "  Workspace     : ${WORKSPACE}"
echo "  Claude Code   : ${CLAUDE_VERSION}"
echo "  Service       : ${SERVICE_NAME}.service"
echo "  Session-Name  : ${SESSION_NAME}   (so erscheint sie in claude.ai/code)"
echo

if [[ ${AUTH_OK} -eq 1 ]]; then
  echo "  Login: bereits vorhanden."
  echo
  echo "  SCHRITT 1 - einmaliger Probelauf (faengt Erstlauf-Dialoge ab):"
  echo "      sudo -u ${AGENT_USER} -H ${CLAUDE_BIN} remote-control --spawn same-dir"
  echo "      Sobald eine claude.ai/code-URL erscheint, laeuft alles. Mit Ctrl+C beenden."
  echo
  echo "  SCHRITT 2 - Dauerbetrieb einschalten:"
  echo "      systemctl start ${SERVICE_NAME}"
else
  cat <<EOF
  NOCH ZU TUN - einmaliger Login (laesst sich nicht automatisieren):

  Remote Control verlangt ein vollwertiges Login. Lange Tokens aus
  'claude setup-token' bzw. CLAUDE_CODE_OAUTH_TOKEN reichen NICHT - das
  CLI lehnt sie fuer Remote Control ausdruecklich ab ("Long-lived tokens
  ... are limited to inference-only for security reasons").

      sudo -u ${AGENT_USER} -H ${CLAUDE_BIN} auth login --claudeai

  Es erscheint eine URL. Diese am eigenen Rechner im Browser oeffnen,
  anmelden, den Code zurueck in die SSH-Sitzung kopieren.

  SCHRITT 2 - einmaliger Probelauf, im selben SSH-Fenster:

      sudo -u ${AGENT_USER} -H ${CLAUDE_BIN} remote-control --spawn same-dir

  Dieser Lauf beantwortet eventuelle Erstlauf-Dialoge und beweist, dass
  Login, Trust und Netzwerk stimmen. Sobald eine claude.ai/code-URL
  erscheint, mit Ctrl+C beenden.

  SCHRITT 3 - Dauerbetrieb einschalten:

      systemctl start ${SERVICE_NAME}
      systemctl status ${SERVICE_NAME}
EOF
fi

cat <<EOF

  Steuerung danach: claude.ai/code oder Claude-App -> Code-Tab.
  Die Session erscheint dort als "${SESSION_NAME}".

  Betrieb:
      systemctl status ${SERVICE_NAME}          Status
      journalctl -u ${SERVICE_NAME} -f          Live-Log
      systemctl restart ${SERVICE_NAME}         Neustart
      ${SERVICE_NAME}-health                    Health-Check von Hand

  Empfohlen als naechster Schritt:
      bash harden-ssh.sh                        SSH absichern (siehe README)

EOF
