#!/usr/bin/env bash
#
# install.sh — Claude-Agent 24/7 auf einem Ubuntu-VPS
#
# Richtet einen dauerhaft laufenden Claude-Code-Agenten ein, der aus claude.ai/code
# und der Claude-App gesteuert wird. Selbstenthalten: PTY-Supervisor und optionale
# SSH-Absicherung sind eingebettet, es wird keine weitere Datei nachgeladen.
#
# Idempotent - beliebig oft erneut ausfuehrbar.
#
#   bash install.sh --plan          zeigt nur, was passieren wuerde
#   bash install.sh                 fuehrt aus (mit Rueckfrage)
#   bash install.sh --yes           fuehrt ohne Rueckfrage aus
#   bash install.sh --help          alle Optionen
#
# Warum es dieses Setup braucht (alles aus dem Claude-Code-Binary belegt):
#   * Remote Control gibt bei mehr als 3 Verbindungsabbruechen pro rollender Stunde
#     (72 in 24 h) dauerhaft auf und beendet sich. 10 stoerungsfreie Minuten setzen
#     das Fenster zurueck.
#   * Linux sendet TCP-Keepalives erst nach 7200 s, typische NAT-Idle-Timeouts liegen
#     bei 5-30 min. Die langlebige Verbindung faellt still aus der NAT-Tabelle.
#     -> Persistenz allein genuegt nicht; beide Ebenen muessen adressiert werden.
#   * Die Token-Erneuerung laeuft ueber platform.claude.com, NICHT api.anthropic.com.
#   * Lange Tokens (setup-token / CLAUDE_CODE_OAUTH_TOKEN) werden von Remote Control
#     abgelehnt - ein interaktives Login ist zwingend und kann nicht skriptet werden.
#
set -euo pipefail

# Hinweis zu 'curl | bash': bash liest das Skript dann selbst von stdin. Jeder
# Unterbefehl, der stdin anfasst, wuerde Skript-Bytes verschlucken und den Lauf
# mittendrin abschneiden. Alle betroffenen Aufrufe bekommen deshalb </dev/null.
# Aus demselben Grund wird stdin NICHT global umgelenkt - das wuerde das noch
# ungelesene Skript abschneiden.

INSTALLER_VERSION="1.0"

# ---------------------------------------------------------------- Optionen
AGENT_USER="${AGENT_USER:-claude}"
WORKSPACE_OVERRIDE="${WORKSPACE:-}"
SERVICE_NAME="${SERVICE_NAME:-claude-agent}"
SESSION_NAME="${SESSION_NAME:-$(hostname -s)}"
CAPACITY="${CAPACITY:-3}"
# Berechtigungsmodus der ferngesteuerten Sessions. "default" fragt bei heiklen Aktionen
# nach (die Rueckfrage erscheint in claude.ai/code). Bewusst NICHT bypassPermissions.
PERMISSION_MODE="${PERMISSION_MODE:-default}"
SWAP_SIZE="${SWAP_SIZE:-4G}"
SKIP_SWAP=0
HARDEN_SSH=0
SSH_KEY=""
DISABLE_PASSWORDS=0
ASSUME_YES=0
PLAN_ONLY=0

# AGENT_HOME wird nach dem Anlegen des Benutzers aus /etc/passwd gelesen (Abschnitt 4).
# Ein bereits vorhandenes Konto kann ein abweichendes Home haben; jedes 'sudo -u ... -H'
# benutzt ohnehin das echte Home, waehrend geratene Pfade in den Units landen wuerden.
AGENT_HOME=""

usage() {
  cat <<'USAGE'
install.sh - Claude-Agent 24/7 auf einem Ubuntu-VPS

  --plan                  Nur den Plan zeigen, nichts aendern
  --yes, -y               Ohne Rueckfrage ausfuehren
  --user NAME             Dienstbenutzer (Default: claude)
  --workspace PFAD        Arbeitsverzeichnis (Default: <home>/workspace)
                          Darf nicht das Home-Verzeichnis selbst sein.
  --session-name NAME     Anzeigename in claude.ai/code (Default: Hostname)
  --capacity N            Gleichzeitige Sessions (Default: 3)
  --permission-mode MODE  default | acceptEdits | plan | dontAsk | bypassPermissions | auto
                          ('manual' wird als Anzeigename von 'default' akzeptiert)
                          (Default: default - Rueckfragen erscheinen in claude.ai/code)
  --swap GROESSE          Swap-Datei, z.B. 4G oder 2048M (Default: 4G)
  --skip-swap             Keinen Swap anlegen
  --service-name NAME     Name der systemd-Unit (Default: claude-agent)

  --harden-ssh            SSH absichern: ufw, fail2ban, Keepalives
  --ssh-key "ssh-ed25519 AAAA..."
                          Diesen Public-Key hinterlegen (impliziert --harden-ssh)
  --disable-passwords     Passwort-Login abschalten. Wird nur ausgefuehrt, wenn fuer
                          jedes betroffene Konto ein gueltiger Schluessel vorliegt.

  -h, --help              Diese Hilfe

Alle Optionen lassen sich auch als Umgebungsvariablen setzen
(AGENT_USER, WORKSPACE, SESSION_NAME, CAPACITY, PERMISSION_MODE, SWAP_SIZE).
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --plan)              PLAN_ONLY=1; shift ;;
    -y|--yes)            ASSUME_YES=1; shift ;;
    --user)              AGENT_USER="${2:?--user benoetigt einen Wert}"; shift 2 ;;
    --workspace)         WORKSPACE_OVERRIDE="${2:?--workspace benoetigt einen Wert}"; shift 2 ;;
    --session-name)      SESSION_NAME="${2:?--session-name benoetigt einen Wert}"; shift 2 ;;
    --capacity)          CAPACITY="${2:?--capacity benoetigt einen Wert}"; shift 2 ;;
    --permission-mode)   PERMISSION_MODE="${2:?--permission-mode benoetigt einen Wert}"; shift 2 ;;
    --swap)              SWAP_SIZE="${2:?--swap benoetigt einen Wert}"; shift 2 ;;
    --skip-swap)         SKIP_SWAP=1; shift ;;
    --service-name)      SERVICE_NAME="${2:?--service-name benoetigt einen Wert}"; shift 2 ;;
    --harden-ssh)        HARDEN_SSH=1; shift ;;
    --ssh-key)           SSH_KEY="${2:?--ssh-key benoetigt den Schluessel}"; HARDEN_SSH=1; shift 2 ;;
    --disable-passwords) DISABLE_PASSWORDS=1; HARDEN_SSH=1; shift ;;
    -h|--help)           usage; exit 0 ;;
    *)                   echo "Unbekannte Option: $1" >&2; echo; usage; exit 2 ;;
  esac
done

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

# Eingaben pruefen, bevor irgendetwas angefasst wird.
[[ "${CAPACITY}" =~ ^[0-9]+$ && "${CAPACITY}" -ge 1 ]] \
  || die "--capacity muss eine positive Zahl sein (war: ${CAPACITY})."
# 'manual' ist nur ein ANZEIGE-Alias fuer 'default'. Die Pruefung im CLI lautet
# PERMISSION_MODES.includes(wert), und dieses Array enthaelt 'default', nicht 'manual';
# lediglich die Fehlermeldung mappt default -> manual. Wer der Fehlermeldung folgt und
# 'manual' uebergibt, bekaeme "Invalid permission mode" und damit einen Dienst, den
# systemd endlos neu startet. Deshalb hier uebersetzen statt durchreichen.
if [[ "${PERMISSION_MODE}" == "manual" ]]; then
  log "--permission-mode manual ist der Anzeigename von 'default' - verwende 'default'"
  PERMISSION_MODE="default"
fi
case "${PERMISSION_MODE}" in
  default|acceptEdits|plan|dontAsk|bypassPermissions|auto) ;;
  *) die "--permission-mode unbekannt: ${PERMISSION_MODE}
     Gueltig: default, acceptEdits, plan, dontAsk, bypassPermissions, auto" ;;
esac
[[ "${SWAP_SIZE}" =~ ^[0-9]+[GgMm]$ ]] \
  || die "--swap braucht eine Groesse wie 4G oder 2048M (war: ${SWAP_SIZE})."
[[ "${AGENT_USER}" =~ ^[a-z_][a-z0-9_-]*$ ]] \
  || die "--user ist kein gueltiger Benutzername: ${AGENT_USER}"
[[ "${SERVICE_NAME}" =~ ^[A-Za-z0-9_.-]+$ ]] \
  || die "--service-name enthaelt unzulaessige Zeichen: ${SERVICE_NAME}"
if [[ ${DISABLE_PASSWORDS} -eq 1 && -z "${SSH_KEY}" ]]; then
  warn "--disable-passwords ohne --ssh-key: es wird nur abgeschaltet, wenn bereits"
  warn "ein gueltiger Schluessel hinterlegt ist. Sonst bricht die Absicherung ab."
fi
if [[ "${PERMISSION_MODE}" == "bypassPermissions" ]]; then
  warn "bypassPermissions: der Agent fuehrt ALLES ohne Rueckfrage aus."
  warn "Auf einer Maschine mit echten Zugangsdaten ist das riskant."
fi

# ---------------------------------------------------------------- Plan
PLANNED_WORKSPACE="${WORKSPACE_OVERRIDE:-/home/${AGENT_USER}/workspace}"
cat <<PLAN

================================================================
 Claude-Agent 24/7 - Installationsplan (v${INSTALLER_VERSION})
================================================================
  Dienstbenutzer   : ${AGENT_USER}  (nicht root, kein sudo)
  Workspace        : ${PLANNED_WORKSPACE}
  systemd-Unit     : ${SERVICE_NAME}.service
  Session-Name     : ${SESSION_NAME}   (so erscheint sie in claude.ai/code)
  Gleichzeitig     : ${CAPACITY} Sessions
  Berechtigungen   : ${PERMISSION_MODE}
  Swap             : $([[ ${SKIP_SWAP} -eq 1 ]] && echo 'uebersprungen' || echo "${SWAP_SIZE}")
  SSH-Absicherung  : $([[ ${HARDEN_SSH} -eq 1 ]] && echo 'ja' || echo 'nein (--harden-ssh)')

 Was ausgefuehrt wird:
   0  Preflight: root, systemd, Architektur, stoerende Env-Variablen, NTP,
      Erreichbarkeit von api.anthropic.com, platform.claude.com, claude.com,
      claude.ai und cdn.growthbook.io
   1  Basispakete (curl, git, jq, python3 ...; optionale ohne Abbruch)
   2  $([[ ${SKIP_SWAP} -eq 1 ]] && echo 'Swap uebersprungen' || echo "Swap ${SWAP_SIZE} anlegen (schuetzt vor dem OOM-Killer)")
   3  TCP-Keepalives auf 120 s (Kernfix gegen NAT-bedingte Abbrueche)
   4  Dienstbenutzer '${AGENT_USER}' anlegen, Home aus /etc/passwd lesen
   5  Claude Code installieren (https://claude.ai/install.sh)
   6  Workspace-Trust setzen und Erstlauf-Dialoge unterdruecken
   7  PTY-Supervisor nach /usr/local/lib/claude-agent/ schreiben
   8  systemd-Unit: Restart=always, StartLimitIntervalSec=0, MemoryHigh=70%
   9  Timer: woechentliches Update, Health-Check alle 15 Minuten
  10  Units aktivieren (der Dienst startet NICHT automatisch - siehe unten)
$([[ ${HARDEN_SSH} -eq 1 ]] && echo "  11  SSH absichern: ufw, fail2ban, Keepalives$([[ ${DISABLE_PASSWORDS} -eq 1 ]] && echo ', Passwort-Login abschalten')")

 Danach von Hand noetig (nicht automatisierbar):
   A  claude auth login --claudeai      Remote Control verlangt ein volles Login;
                                        lange Tokens werden abgelehnt.
   B  einmaliger Probelauf              faengt Erstlauf-Dialoge ab
   C  systemctl start ${SERVICE_NAME}

 Nicht angefasst: Repository, Agenten-Start, vorhandene Firewall-Regeln.
================================================================

PLAN

if [[ ${PLAN_ONLY} -eq 1 ]]; then
  echo "Nur Plan (--plan) - es wurde nichts geaendert."
  exit 0
fi

if [[ ${ASSUME_YES} -eq 0 ]]; then
  if [[ -t 0 ]]; then
    read -r -p "Fortfahren? [j/N] " _answer
    case "${_answer}" in
      j|J|y|Y|ja|Ja|yes|Yes) ;;
      *) echo "Abgebrochen."; exit 0 ;;
    esac
  else
    die "Keine interaktive Eingabe moeglich. Mit --yes bestaetigen, oder erst --plan pruefen.
     Beispiel:  curl ... | bash -s -- --yes"
  fi
fi
echo
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
apt-get update -qq </dev/null || warn "apt-get update meldete Fehler - versuche die Installation trotzdem"

# Pflicht: ohne diese Pakete funktioniert das Setup nicht.
apt-get install -y -qq --no-install-recommends \
  ca-certificates curl git jq python3 util-linux procps >/dev/null </dev/null \
  || die "Basispakete liessen sich nicht installieren."

# Kuer: nuetzlich, aber kein Grund zum Abbruch (build-essential ist gross und fehlt
# auf minimalen Images gelegentlich ganz).
for pkg in file ripgrep unzip tmux unattended-upgrades build-essential; do
  apt-get install -y -qq --no-install-recommends "${pkg}" >/dev/null 2>&1 </dev/null \
    || warn "  optionales Paket '${pkg}' nicht installiert - weiter"
done
log "  ok"

# ---------------------------------------------------------------- 2. Swap
# Swap ist eine Schutzmassnahme, keine Voraussetzung: schlaegt etwas fehl, wird gewarnt
# statt abgebrochen. Deshalb laeuft der ganze Block in einer Subshell ohne 'set -e'.
if [[ ${SKIP_SWAP} -eq 1 ]]; then
  log "Swap uebersprungen (--skip-swap)"
elif swapon --show --noheadings 2>/dev/null | grep -q .; then
  log "Swap bereits vorhanden - uebersprungen"
else
  log "Lege Swap an (${SWAP_SIZE}) - schuetzt den Agenten vor dem OOM-Killer"
  if ( set +e
       # Ein vorhandenes, aber unbrauchbares /swapfile nicht blind weiterverwenden.
       # Nur loeschen, wenn 'file' vorhanden ist UND sicher sagt, dass es kein Swap ist.
       # Ohne diese Bedingung wuerde ein fehlendes 'file' (minimale Cloud-Images) den
       # Test immer scheitern lassen und /swapfile bedingungslos loeschen.
       if [[ -f /swapfile ]] && command -v file >/dev/null 2>&1 \
          && ! file /swapfile 2>/dev/null | grep -qi 'swap file'; then
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
       if ! grep -q '^/swapfile ' /etc/fstab; then
         # Endet fstab nicht mit Zeilenumbruch, wuerde der neue Eintrag an die letzte
         # Zeile geklebt - im schlimmsten Fall an den Root-Eintrag. Erst absichern.
         [[ -s /etc/fstab && -n "$(tail -c 1 /etc/fstab)" ]] && printf '\n' >> /etc/fstab
         printf '%s\n' '/swapfile none swap sw 0 0' >> /etc/fstab
       fi
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
sysctl -q --system >/dev/null 2>&1 \
  || warn "sysctl --system meldete Fehler (auf VPS/Containern ueblich) - Keepalives werden einzeln gesetzt"
# Die fuer uns wesentlichen Werte notfalls direkt setzen.
for kv in net.ipv4.tcp_keepalive_time=120 net.ipv4.tcp_keepalive_intvl=30 \
          net.ipv4.tcp_keepalive_probes=8; do
  sysctl -qw "${kv}" 2>/dev/null || warn "  ${kv} nicht setzbar"
done
log "  tcp_keepalive_time=$(cat /proc/sys/net/ipv4/tcp_keepalive_time)s"

# ---------------------------------------------------------------- 4. Dienstbenutzer
if ! id -u "${AGENT_USER}" >/dev/null 2>&1; then
  log "Lege Dienstbenutzer '${AGENT_USER}' an (der Agent laeuft NICHT als root)"
  adduser --disabled-password --gecos "Claude Code Agent" "${AGENT_USER}" >/dev/null 2>&1 </dev/null \
    || useradd -m -s /bin/bash -c "Claude Code Agent" "${AGENT_USER}" </dev/null \
    || die "Dienstbenutzer '${AGENT_USER}' konnte nicht angelegt werden."
else
  log "Benutzer '${AGENT_USER}' existiert bereits"
fi
# Echtes Home aus passwd, nicht geraten.
AGENT_HOME="$(getent passwd "${AGENT_USER}" 2>/dev/null | cut -d: -f6 || true)"
[[ -n "${AGENT_HOME}" ]] || die "Home-Verzeichnis von '${AGENT_USER}' nicht ermittelbar."
[[ -d "${AGENT_HOME}" ]] || install -d -o "${AGENT_USER}" -g "${AGENT_USER}" -m 0750 "${AGENT_HOME}"
WORKSPACE="${WORKSPACE_OVERRIDE:-${AGENT_HOME}/workspace}"
log "  Home: ${AGENT_HOME}"

# Das Arbeitsverzeichnis darf NICHT das Home-Verzeichnis sein: fuer $HOME speichert
# Claude Code den Workspace-Trust grundsaetzlich nicht ("home-directory trust is never
# saved"). Der Dienst wuerde dann bei jedem Start am Trust-Fehler scheitern.
WORKSPACE="${WORKSPACE%/}"
if [[ "${WORKSPACE}" == "${AGENT_HOME%/}" ]]; then
  die "WORKSPACE darf nicht das Home-Verzeichnis sein (${AGENT_HOME}).
     Fuer Home-Verzeichnisse wird Workspace-Trust nie gespeichert; der Dienst
     wuerde bei jedem Start scheitern. Nimm ein Unterverzeichnis, z.B.
     WORKSPACE=${AGENT_HOME}/workspace"
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
  sudo -u "${AGENT_USER}" -H bash -lc 'command -v claude' 2>/dev/null </dev/null || true
}
CLAUDE_BIN="$(find_claude)"
if [[ -z "${CLAUDE_BIN}" ]]; then
  log "Installiere Claude Code (offizieller Installer https://claude.ai/install.sh)"
  sudo -u "${AGENT_USER}" -H bash -lc 'curl -fsSL https://claude.ai/install.sh | bash' </dev/null \
    || die "Installation von Claude Code fehlgeschlagen."
  CLAUDE_BIN="$(find_claude)"
  [[ -n "${CLAUDE_BIN}" ]] || die "Claude Code wurde installiert, aber die Binary wurde nicht gefunden."
else
  log "Claude Code bereits vorhanden: ${CLAUDE_BIN}"
fi
# Das Verzeichnis der tatsaechlich gefundenen Binary gehoert in den Unit-PATH.
# find_claude kann sie ausserhalb der Standardpfade liefern (z.B. unter nvm), und ein
# Dienst mit unpassendem PATH endet mit Status 127, ohne sich je zu erholen.
CLAUDE_DIR="$(dirname "${CLAUDE_BIN}")"
SERVICE_PATH="${CLAUDE_DIR}:${AGENT_HOME}/.local/bin:/usr/local/bin:/usr/bin:/bin"

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
    # Eine Eingabeaufforderung endet NICHT mit einem Zeilenumbruch ("Enable Remote
    # Control? (y/n) "). Wer nur vollstaendige Zeilen ausgibt, haelt genau die
    # Meldung zurueck, die einen haengenden Dienst verraten wuerde. Deshalb wird ein
    # nicht leerer Puffer nach kurzem Leerlauf trotzdem ausgegeben.
    PARTIAL_FLUSH_SEC = 2.0
    last_data = time.monotonic()

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
                last_data = time.monotonic()
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

        # Angefangene Zeile nach Leerlauf ausgeben - so landet auch eine wartende
        # Eingabeaufforderung im Journal und der Health-Check kann sie erkennen.
        if buf and (time.monotonic() - last_data) > PARTIAL_FLUSH_SEC:
            pending = ANSI_RE.sub(b"", buf) if not args.keep_ansi else buf
            pending = pending.rstrip()
            buf = b""
            last_data = time.monotonic()
            if pending:
                out.write(pending + b"\n")
                out.flush()

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
Environment=PATH=${SERVICE_PATH}

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

# Bewusst KEIN OOMScoreAdjust: der Wert wird an jeden Kindprozess vererbt. Ein Build
# oder Test, den der Agent startet, waere damit ebenfalls geschuetzt - und der
# OOM-Killer griffe sich stattdessen einen Systemdienst, im schlimmsten Fall sshd.
# Den Zugang zum Server zu verlieren ist schlimmer, als den Agenten neu zu starten;
# Restart=always holt ihn ohnehin sofort zurueck. Gegen Speicherdruck wirkt der Swap
# aus Schritt 2, und MemoryHigh bremst den Dienst, bevor es kritisch wird.
MemoryHigh=70%
MemoryAccounting=true
# OOMPolicy steht per Default auf 'stop' (DefaultOOMPolicy in system.conf). Damit wuerde
# ein kernelseitiger OOM-Kill eines KINDPROZESSES - ein Build oder Test, den der Agent
# startet - die gesamte Unit stoppen und die laufende Session mitreissen. 'continue'
# laesst den Dienst weiterlaufen und nur das Kind sterben.
OOMPolicy=continue

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
Environment=PATH=${SERVICE_PATH}
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

# Nur einen ABGESTUERZTEN Dienst neu starten, nicht jeden inaktiven.
# 'failed' unterscheidet sauber zwischen den drei Zustaenden:
#   failed   -> abgestuerzt, hier soll der Watchdog eingreifen
#   inactive -> bewusst gestoppt ODER noch nie gestartet (vor dem Erst-Login!)
# Ein bedingungsloses restart wuerde ein 'systemctl stop' stillschweigend aufheben
# und den Dienst vor dem dokumentierten interaktiven Erstlauf in eine Absturzschleife
# schicken.
# Hinweis zur Arbeitsteilung: Abstuerze faengt systemd selbst ab (Restart=always,
# StartLimitIntervalSec=0). Der Dienst landet dadurch praktisch nie im Zustand
# 'failed' - er pendelt zwischen active und auto-restart. Dieser Check ist deshalb
# vor allem MELDEND; er startet nur den Sonderfall 'failed' neu und laesst einen
# bewusst gestoppten Dienst in Ruhe.
if systemctl is-failed --quiet "${SERVICE}.service"; then
  echo "KRITISCH: ${SERVICE}.service steht auf 'failed' - starte neu"
  systemctl restart "${SERVICE}.service"
  fail=1
elif [[ "$(systemctl show -p SubState --value "${SERVICE}.service" 2>/dev/null)" == "auto-restart" ]]; then
  echo "WARNUNG: ${SERVICE}.service startet gerade neu (Absturzschleife?)."
  echo "  Pruefen: journalctl -u ${SERVICE}.service -n 50"
  fail=1
elif ! systemctl is-active --quiet "${SERVICE}.service"; then
  if [[ -z "$(systemctl show -p ExecMainStartTimestamp --value "${SERVICE}.service" 2>/dev/null)" ]]; then
    echo "HINWEIS: ${SERVICE}.service wurde noch nie gestartet."
    echo "  Erst anmelden und den Probelauf machen, dann: systemctl start ${SERVICE}.service"
  else
    echo "HINWEIS: ${SERVICE}.service ist gestoppt (nicht abgestuerzt) - kein automatischer Start."
  fi
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

# Bei einem erneuten Lauf wurden die Units neu geschrieben. Laeuft der Dienst bereits,
# muss er neu starten, damit geaenderte Einstellungen greifen - sonst laeuft er still
# mit der alten Konfiguration weiter. try-restart laesst einen gestoppten Dienst
# gestoppt, stoert den dokumentierten Erstlauf also nicht.
if systemctl is-active --quiet "${SERVICE_NAME}.service"; then
  log "Dienst laeuft bereits - Neustart, damit die neue Konfiguration greift"
  systemctl try-restart "${SERVICE_NAME}.service" || warn "  Neustart fehlgeschlagen"
fi

# ---------------------------------------------------------------- 11. SSH-Absicherung
# Das Hardening-Skript wird immer abgelegt, damit es spaeter jederzeit einzeln laufen
# kann - ausgefuehrt wird es nur auf ausdruecklichen Wunsch.
log "Lege SSH-Hardening-Skript ab (/usr/local/sbin/claude-harden-ssh)"
install -d -m 0755 /usr/local/sbin
cat > /usr/local/sbin/claude-harden-ssh <<'HARDEN_SH_EOF'
#!/usr/bin/env bash
#
# harden-ssh.sh
#
# Sichert den SSH-Zugang des VPS ab und stabilisiert langlebige SSH-Sitzungen.
#
# Aussperr-Schutz (mehrstufig):
#   * Passwort-Login wird nur abgeschaltet, wenn fuer JEDES betroffene Konto ein
#     nachweislich GUELTIGER Schluessel hinterlegt ist - geprueft mit ssh-keygen -l,
#     nicht durch Zeilenzaehlen. Ein umgebrochener Paste-Schluessel zaehlt nicht.
#   * Die Richtlinie gilt global, deshalb wird auch root geprueft, nicht nur --user.
#   * Vor dem Anhaengen an authorized_keys wird ein fehlender Zeilenumbruch ergaenzt,
#     sonst verschmilzt der neue mit dem letzten Schluessel und beide werden unbrauchbar.
#   * Jede Aenderung wird mit 'sshd -t' geprueft; im Fehlerfall wird aus Sicherungen
#     zurueckgerollt und nichts neu geladen.
#   * 'reload' statt 'restart': die laufende Sitzung bleibt bestehen.
#
# Aufruf (als root):
#   bash harden-ssh.sh                               # nur pruefen und Keepalives setzen
#   bash harden-ssh.sh --key "ssh-ed25519 AAAA..."   # Schluessel hinterlegen
#   bash harden-ssh.sh --disable-passwords           # Passwort-Login abschalten
#
set -euo pipefail

SSH_USER="${SSH_USER:-root}"
NEW_KEY=""
DISABLE_PASSWORDS=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --key)               NEW_KEY="${2:?--key benoetigt den Schluessel als Argument}"; shift 2 ;;
    --disable-passwords) DISABLE_PASSWORDS=1; shift ;;
    --user)              SSH_USER="${2:?--user benoetigt einen Benutzernamen}"; shift 2 ;;
    -h|--help)           sed -n '2,25p' "$0"; exit 0 ;;
    *)                   echo "Unbekannte Option: $1" >&2; exit 2 ;;
  esac
done

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Bitte als root ausfuehren."

# Die Schluesselpruefung stuetzt sich auf ssh-keygen. Fehlt es, zaehlt jede Datei als
# "0 gueltige Schluessel" - das ist zwar fail-safe (es wird nichts abgeschaltet), aber
# die Ursache waere nicht erkennbar. Deshalb hier explizit melden.
if ! command -v ssh-keygen >/dev/null 2>&1; then
  if [[ ${DISABLE_PASSWORDS} -eq 1 || -n "${NEW_KEY}" ]]; then
    die "ssh-keygen fehlt - Schluessel koennen nicht geprueft werden.
     Ohne diese Pruefung wird nichts abgeschaltet (Aussperr-Schutz).
     Beheben:  apt-get install -y openssh-client"
  fi
  warn "ssh-keygen fehlt - Schluesselpruefung uebersprungen."
fi

# getent liefert 2 fuer unbekannte Nutzer; unter 'set -e' wuerde die Zuweisung den
# Lauf beenden, bevor die verstaendliche Meldung erscheint. Deshalb abfangen.
HOME_DIR="$(getent passwd "${SSH_USER}" 2>/dev/null | cut -d: -f6 || true)"
[[ -n "${HOME_DIR}" ]] || die "Benutzer '${SSH_USER}' existiert nicht."
AUTH_KEYS="${HOME_DIR}/.ssh/authorized_keys"

# --------------------------------------------------------------- Hilfsfunktionen
# Zaehlt tatsaechlich VERWENDBARE Schluessel: jede Zeile wird einzeln von ssh-keygen
# geprueft. Zeilen zaehlen genuegt nicht - ein umgebrochener Paste besteht das nicht.
count_valid_keys() {
  local file="$1" line tmp n=0
  [[ -s "${file}" ]] || { echo 0; return 0; }
  tmp="$(mktemp)"
  while IFS= read -r line || [[ -n "${line}" ]]; do
    [[ -z "${line}" || "${line}" =~ ^[[:space:]]*# ]] && continue
    printf '%s\n' "${line}" > "${tmp}"
    ssh-keygen -l -f "${tmp}" >/dev/null 2>&1 && n=$((n + 1))
  done < "${file}"
  rm -f "${tmp}"
  echo "${n}"
}

# --------------------------------------------------------------- Schluessel hinterlegen
if [[ -n "${NEW_KEY}" ]]; then
  tmp_key="$(mktemp)"
  printf '%s\n' "${NEW_KEY}" > "${tmp_key}"
  if ! ssh-keygen -l -f "${tmp_key}" >/dev/null 2>&1; then
    rm -f "${tmp_key}"
    die "Der uebergebene Schluessel ist kein gueltiger SSH-Public-Key.
     Haeufigste Ursache: beim Kopieren umgebrochen. Der Schluessel muss EINE Zeile sein."
  fi
  rm -f "${tmp_key}"

  install -d -m 0700 -o "${SSH_USER}" -g "$(id -gn "${SSH_USER}")" "${HOME_DIR}/.ssh"
  touch "${AUTH_KEYS}"
  if grep -qxF "${NEW_KEY}" "${AUTH_KEYS}" 2>/dev/null; then
    log "Schluessel ist bereits hinterlegt."
  else
    # Fehlenden Zeilenumbruch ergaenzen: sonst wird der neue Schluessel an den letzten
    # geklebt und BEIDE werden unbrauchbar - der klassische Aussperrer.
    if [[ -s "${AUTH_KEYS}" && -n "$(tail -c 1 "${AUTH_KEYS}")" ]]; then
      printf '\n' >> "${AUTH_KEYS}"
    fi
    printf '%s\n' "${NEW_KEY}" >> "${AUTH_KEYS}"
    log "Schluessel hinterlegt."
  fi
  chmod 0600 "${AUTH_KEYS}"
  chown "${SSH_USER}:$(id -gn "${SSH_USER}")" "${AUTH_KEYS}"
fi

KEY_COUNT="$(count_valid_keys "${AUTH_KEYS}")"
log "Gueltige Schluessel fuer '${SSH_USER}': ${KEY_COUNT}"

# --------------------------------------------------------------- sshd-Konfiguration
DROPIN=/etc/ssh/sshd_config.d/99-claude-hardening.conf
mkdir -p /etc/ssh/sshd_config.d

# Ein Drop-in wirkt nur, wenn sshd_config das Verzeichnis einbindet. Ohne diese Zeile
# waere die ganze Datei wirkungslos - und die Erfolgsmeldung eine Luege.
if ! grep -qsE '^\s*Include\s+/etc/ssh/sshd_config\.d/\*\.conf' /etc/ssh/sshd_config; then
  warn "/etc/ssh/sshd_config bindet sshd_config.d nicht ein - das Drop-in waere wirkungslos."
  warn "Ergaenze die Include-Zeile am ANFANG von /etc/ssh/sshd_config:"
  warn "    Include /etc/ssh/sshd_config.d/*.conf"
  die  "Abbruch, damit keine falsche Sicherheit entsteht."
fi

# Bisherigen Zustand merken: ein spaeterer Lauf OHNE --disable-passwords darf den
# Passwort-Login nicht stillschweigend wieder einschalten.
PW_ALREADY_DISABLED=0
if grep -qsE '^\s*PasswordAuthentication\s+no' "${DROPIN}" 2>/dev/null; then
  PW_ALREADY_DISABLED=1
fi
if [[ ${PW_ALREADY_DISABLED} -eq 1 && ${DISABLE_PASSWORDS} -eq 0 ]]; then
  log "Passwort-Login war bereits abgeschaltet - Zustand wird beibehalten."
  DISABLE_PASSWORDS=1
fi

# Sicherungen aller Dateien, die wir anfassen koennten - fuer einen echten Rollback.
# Die Zuordnung Sicherung -> Originalpfad steht in einem Manifest. Den Pfad in den
# Dateinamen zu kodieren waere fehleranfaellig: '/' durch '_' zu ersetzen ist nicht
# umkehrbar, sobald der Pfad selbst Unterstriche enthaelt - und genau das tut
# 'sshd_config'. Ein naiver Rueckweg ergaebe '/etc/ssh/sshd/config'.
BACKUP_DIR="$(mktemp -d /root/.ssh-hardening-backup.XXXXXX)"
chmod 700 "${BACKUP_DIR}"
MANIFEST="${BACKUP_DIR}/manifest.tsv"
: > "${MANIFEST}"
DROPIN_EXISTED=0
[[ -f "${DROPIN}" ]] && DROPIN_EXISTED=1

backup_n=0
backup_file() {
  local f="$1"
  [[ -f "$f" ]] || return 0
  # Schon gesichert? Dann nicht ueberschreiben.
  cut -f2 "${MANIFEST}" | grep -qxF "$f" && return 0
  backup_n=$((backup_n + 1))
  cp -a "$f" "${BACKUP_DIR}/${backup_n}.bak"
  printf '%s\t%s\n' "${backup_n}.bak" "$f" >> "${MANIFEST}"
}

backup_file /etc/ssh/sshd_config
backup_file "${DROPIN}"
for f in /etc/ssh/sshd_config.d/*.conf; do backup_file "$f"; done

rollback() {
  local bak orig
  while IFS=$'\t' read -r bak orig; do
    [[ -n "${bak}" && -n "${orig}" && -f "${BACKUP_DIR}/${bak}" ]] || continue
    cp -a "${BACKUP_DIR}/${bak}" "${orig}" 2>/dev/null \
      || warn "  Rollback von ${orig} fehlgeschlagen"
  done < "${MANIFEST}"
  # Ein in DIESEM Lauf neu erzeugtes Drop-in gab es vorher nicht - entfernen.
  [[ ${DROPIN_EXISTED} -eq 1 ]] || rm -f "${DROPIN}"
}

# ClientAliveInterval haelt die SSH-Sitzung durch NAT-Timeouts am Leben - das SSH-Pendant
# zu den TCP-Keepalives aus dem Installer.
# MaxAuthTries bleibt beim OpenSSH-Standard (6): ein niedrigerer Wert kann die
# Schluesselangebote eines ssh-agent aufbrauchen, bevor der richtige an der Reihe ist.
{
  echo "# Von harden-ssh.sh verwaltet."
  echo "ClientAliveInterval 60"
  echo "ClientAliveCountMax 10"
  echo "TCPKeepAlive yes"
  echo "LoginGraceTime 30"
  echo "X11Forwarding no"
  echo "AllowAgentForwarding no"
} > "${DROPIN}"

if [[ ${DISABLE_PASSWORDS} -eq 1 ]]; then
  # Die Richtlinie wirkt GLOBAL. Es genuegt daher nicht, nur --user zu pruefen:
  # 'PermitRootLogin prohibit-password' sperrt root aus, wenn root keinen Schluessel hat.
  ROOT_HOME="$(getent passwd root 2>/dev/null | cut -d: -f6 || true)"
  ROOT_KEYS="${ROOT_HOME:-/root}/.ssh/authorized_keys"
  ROOT_KEY_COUNT="$(count_valid_keys "${ROOT_KEYS}")"
  log "Gueltige Schluessel fuer 'root': ${ROOT_KEY_COUNT}"

  MISSING=""
  [[ "${KEY_COUNT}"      -lt 1 ]] && MISSING="${MISSING} ${SSH_USER}"
  [[ "${ROOT_KEY_COUNT}" -lt 1 ]] && MISSING="${MISSING} root"
  # Doppelnennung vermeiden, wenn --user root ist.
  MISSING="$(echo ${MISSING} | tr ' ' '\n' | sort -u | tr '\n' ' ')"

  if [[ -n "${MISSING// /}" ]]; then
    rollback
    die "Abbruch: kein gueltiger SSH-Schluessel fuer:${MISSING}
     Passwort-Login abzuschalten wuerde dich aussperren - die Richtlinie gilt global.
     Erst:  bash harden-ssh.sh --user <konto> --key \"ssh-ed25519 AAAA...\"
     Dann:  bash harden-ssh.sh --disable-passwords"
  fi

  {
    echo "PasswordAuthentication no"
    echo "KbdInteractiveAuthentication no"
    echo "PermitRootLogin prohibit-password"
  } >> "${DROPIN}"
  log "Passwort-Login wird abgeschaltet (alle betroffenen Konten haben Schluessel)."

  # Bei sshd gewinnt der ERSTE Treffer. Ein frueher gesetztes 'yes' - Ubuntu-Cloud-Images
  # bringen so eines mit - wuerde unser 'no' aushebeln. Deshalb auskommentieren.
  while IFS= read -r conflict; do
    [[ "${conflict}" == "${DROPIN}" ]] && continue
    warn "Konkurrierende PasswordAuthentication-Zeile in ${conflict}"
    sed -i 's/^\s*PasswordAuthentication\s\+yes/#&/I' "${conflict}" \
      && log "  auskommentiert (Sicherung in ${BACKUP_DIR})"
  done < <(grep -rlsiE '^\s*PasswordAuthentication\s+yes' \
             /etc/ssh/sshd_config /etc/ssh/sshd_config.d/ 2>/dev/null || true)
else
  log "Passwort-Login bleibt aktiv. Zum Abschalten: --disable-passwords (Schluessel noetig)."
fi

# --------------------------------------------------------------- Validieren + neu laden
log "Pruefe sshd-Konfiguration"
if ! sshd -t 2>&1; then
  rollback
  die "sshd-Konfiguration fehlerhaft - ALLE Aenderungen zurueckgerollt, nichts neu geladen."
fi

# Gegenprobe am effektiven Ergebnis, nicht nur an der Syntax.
if [[ ${DISABLE_PASSWORDS} -eq 1 ]]; then
  EFFECTIVE="$(sshd -T 2>/dev/null | awk '/^passwordauthentication /{print $2}' || echo '')"
  if [[ "${EFFECTIVE}" == "yes" ]]; then
    rollback
    die "sshd meldet weiterhin passwordauthentication=yes - zurueckgerollt.
     Es gibt eine vorrangige Direktive (Match-Block oder frueheres Include)."
  fi
  log "  sshd bestaetigt: passwordauthentication=${EFFECTIVE:-unbekannt}"
fi

systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
log "sshd neu geladen. Sicherungen: ${BACKUP_DIR}"

# --------------------------------------------------------------- Firewall
# Den tatsaechlich genutzten SSH-Port ermitteln, statt 22 anzunehmen: laeuft sshd auf
# einem anderen Port, wuerde ein blindes 'ufw allow 22/tcp' den Zugang kappen.
SSH_PORTS="$(sshd -T 2>/dev/null | awk '/^port /{print $2}' | sort -u || true)"
if [[ -z "${SSH_PORTS}" ]]; then
  SSH_PORTS="$(ss -lntp 2>/dev/null | awk '/sshd/{split($4,a,":"); print a[length(a)]}' | sort -u || true)"
fi
[[ -z "${SSH_PORTS}" ]] && SSH_PORTS=22
log "Erkannte SSH-Ports: $(echo ${SSH_PORTS} | tr '\n' ' ')"

if command -v ufw >/dev/null 2>&1 || apt-get install -y -qq ufw >/dev/null 2>&1; then
  log "Konfiguriere Firewall (ausgehend offen, eingehend nur SSH)"
  # Bewusst KEIN 'ufw reset': das wuerde Regeln anderer Dienste stillschweigend loeschen.
  ufw default deny incoming  >/dev/null 2>&1 || true
  ufw default allow outgoing >/dev/null 2>&1 || true
  # Erst freigeben, DANN aktivieren - nie umgekehrt.
  for port in ${SSH_PORTS}; do
    [[ "${port}" =~ ^[0-9]+$ ]] || continue
    ufw allow "${port}/tcp" >/dev/null 2>&1 || warn "  ufw-Regel fuer Port ${port} fehlgeschlagen"
  done
  if ufw status 2>/dev/null | head -1 | grep -qi 'inactive'; then
    ufw --force enable >/dev/null 2>&1 || warn "  ufw liess sich nicht aktivieren"
  fi
  log "  ufw aktiv: eingehend nur $(echo ${SSH_PORTS} | tr '\n' ' ')(tcp)"
fi

# --------------------------------------------------------------- fail2ban
if apt-get install -y -qq fail2ban >/dev/null 2>&1; then
  cat > /etc/fail2ban/jail.d/sshd.local <<'EOF'
[sshd]
enabled = true
backend = systemd
maxretry = 5
findtime = 10m
bantime = 1h
EOF
  systemctl enable --now fail2ban >/dev/null 2>&1 || true
  log "fail2ban aktiv fuer sshd."
fi

echo
echo "================================================================"
echo " SSH-Absicherung abgeschlossen."
echo "================================================================"
echo "  Geprueftes Konto  : ${SSH_USER} (${KEY_COUNT} gueltige Schluessel)"
echo "  Passwort-Login    : $([[ ${DISABLE_PASSWORDS} -eq 1 ]] && echo 'abgeschaltet' || echo 'noch aktiv')"
echo "  Eingehende Ports  : $(echo ${SSH_PORTS} | tr '\n' ' ')(tcp, sonst nichts)"
echo "  Sicherungen       : ${BACKUP_DIR}"
echo
echo "  WICHTIG: Diese SSH-Sitzung offen lassen und in einem ZWEITEN Terminal"
echo "  testen, ob der Login weiterhin funktioniert. Erst dann schliessen."
echo
HARDEN_SH_EOF
chmod 0755 /usr/local/sbin/claude-harden-ssh
bash -n /usr/local/sbin/claude-harden-ssh \
  || die "Eingebettetes Hardening-Skript ist syntaktisch fehlerhaft."

if [[ ${HARDEN_SSH} -eq 1 ]]; then
  HARDEN_ARGS=()
  [[ -n "${SSH_KEY}" ]]              && HARDEN_ARGS+=(--key "${SSH_KEY}")
  [[ ${DISABLE_PASSWORDS} -eq 1 ]]   && HARDEN_ARGS+=(--disable-passwords)
  log "Starte SSH-Absicherung"
  # Ein Fehler hier darf das bereits fertige Agenten-Setup nicht entwerten.
  if bash /usr/local/sbin/claude-harden-ssh "${HARDEN_ARGS[@]}"; then
    SSH_HARDENED=1
  else
    SSH_HARDENED=0
    warn "SSH-Absicherung fehlgeschlagen - der Agent ist davon unberuehrt."
    warn "Einzeln nachholen:  claude-harden-ssh --key \"ssh-ed25519 ...\""
  fi
else
  SSH_HARDENED=0
fi

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
echo " Setup abgeschlossen."
echo "================================================================"
echo "  Benutzer      : ${AGENT_USER}  (nicht root, kein sudo)"
echo "  Workspace     : ${WORKSPACE}"
echo "  Claude Code   : ${CLAUDE_VERSION}"
echo "  Service       : ${SERVICE_NAME}.service  (aktiviert, noch nicht gestartet)"
echo "  Session-Name  : ${SESSION_NAME}   (so erscheint sie in claude.ai/code)"
echo "  SSH-Hardening : $([[ ${SSH_HARDENED} -eq 1 ]] && echo 'ausgefuehrt' || echo 'nicht ausgefuehrt')"
echo

if [[ ${AUTH_OK} -eq 1 ]]; then
  cat <<EOF
  Login ist bereits vorhanden. Es fehlen noch zwei Schritte:

  SCHRITT 1 - einmaliger Probelauf (faengt Erstlauf-Dialoge ab):

      sudo -u ${AGENT_USER} -H ${CLAUDE_BIN} remote-control --spawn same-dir

  Sobald eine claude.ai/code-URL erscheint, stimmt alles. Mit Ctrl+C beenden.

  SCHRITT 2 - Dauerbetrieb einschalten:

      systemctl start ${SERVICE_NAME}
EOF
else
  cat <<EOF
  NOCH ZU TUN - drei Schritte, der erste braucht einen Browser:

  SCHRITT 1 - Login (nicht automatisierbar)

  Remote Control verlangt ein vollwertiges Login. Lange Tokens aus
  'claude setup-token' bzw. CLAUDE_CODE_OAUTH_TOKEN werden abgelehnt
  ("Long-lived tokens ... are limited to inference-only for security reasons").

      sudo -u ${AGENT_USER} -H ${CLAUDE_BIN} auth login --claudeai

  Es erscheint eine URL. Am eigenen Rechner im Browser oeffnen, anmelden,
  die zurueckgegebene Zeichenkette (Format code#state) hier einfuegen.

  SCHRITT 2 - einmaliger Probelauf, im selben SSH-Fenster:

      sudo -u ${AGENT_USER} -H ${CLAUDE_BIN} remote-control --spawn same-dir

  Beantwortet eventuelle Erstlauf-Dialoge und beweist, dass Login, Trust
  und Netzwerk stimmen. Bei erscheinender claude.ai/code-URL: Ctrl+C.

  SCHRITT 3 - Dauerbetrieb einschalten:

      systemctl start ${SERVICE_NAME}
      systemctl status ${SERVICE_NAME}
EOF
fi

cat <<EOF

  Steuerung danach: claude.ai/code oder Claude-App -> Code-Tab.
  Die Session erscheint dort als "${SESSION_NAME}".

  Betrieb:
      systemctl status ${SERVICE_NAME}       Status
      journalctl -u ${SERVICE_NAME} -f       Live-Log
      systemctl restart ${SERVICE_NAME}      Neustart
      ${SERVICE_NAME}-health                 Health-Check von Hand
EOF

if [[ ${SSH_HARDENED} -eq 0 ]]; then
  cat <<EOF

  Empfohlen: SSH absichern (ufw, fail2ban, Keepalives)
      claude-harden-ssh --key "ssh-ed25519 AAAA... dein-key"
      claude-harden-ssh --disable-passwords
EOF
fi
echo
