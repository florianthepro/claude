#!/usr/bin/env bash
#
# harden-ssh.sh
#
# Sichert den SSH-Zugang des VPS ab und stabilisiert langlebige SSH-Sitzungen.
#
# Aussperr-Schutz: Passwort-Login wird NUR dann abgeschaltet, wenn nachweislich ein
# funktionsfaehiger SSH-Schluessel hinterlegt ist. Ohne Schluessel bricht das Skript ab,
# statt den Zugang zu verlieren. Jede Aenderung wird vor dem Neustart mit `sshd -t` geprueft.
#
# Aufruf (als root):
#   bash harden-ssh.sh                          # nur pruefen, nichts abschalten
#   bash harden-ssh.sh --key "ssh-ed25519 AAAA..."   # Schluessel hinterlegen
#   bash harden-ssh.sh --disable-passwords      # Passwort-Login abschalten (braucht Schluessel)
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
    -h|--help)           sed -n '2,20p' "$0"; exit 0 ;;
    *)                   echo "Unbekannte Option: $1" >&2; exit 2 ;;
  esac
done

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Bitte als root ausfuehren."

HOME_DIR="$(getent passwd "${SSH_USER}" | cut -d: -f6)"
[[ -n "${HOME_DIR}" ]] || die "Benutzer '${SSH_USER}' existiert nicht."
AUTH_KEYS="${HOME_DIR}/.ssh/authorized_keys"

# ---------------------------------------------------------------- Schluessel hinterlegen
if [[ -n "${NEW_KEY}" ]]; then
  # Schluessel vor dem Einspielen validieren - ein kaputter Schluessel waere ein Aussperrer.
  tmp_key="$(mktemp)"
  printf '%s\n' "${NEW_KEY}" > "${tmp_key}"
  if ! ssh-keygen -l -f "${tmp_key}" >/dev/null 2>&1; then
    rm -f "${tmp_key}"
    die "Der uebergebene Schluessel ist kein gueltiger SSH-Public-Key."
  fi
  rm -f "${tmp_key}"

  install -d -m 0700 -o "${SSH_USER}" -g "$(id -gn "${SSH_USER}")" "${HOME_DIR}/.ssh"
  touch "${AUTH_KEYS}"
  if grep -qxF "${NEW_KEY}" "${AUTH_KEYS}"; then
    log "Schluessel ist bereits hinterlegt."
  else
    printf '%s\n' "${NEW_KEY}" >> "${AUTH_KEYS}"
    log "Schluessel hinterlegt."
  fi
  chmod 0600 "${AUTH_KEYS}"
  chown "${SSH_USER}:$(id -gn "${SSH_USER}")" "${AUTH_KEYS}"
fi

# ---------------------------------------------------------------- Schluessel pruefen
KEY_COUNT=0
if [[ -s "${AUTH_KEYS}" ]]; then
  KEY_COUNT="$(grep -cvE '^\s*(#|$)' "${AUTH_KEYS}" || true)"
fi
log "Hinterlegte Schluessel fuer '${SSH_USER}': ${KEY_COUNT}"

# ---------------------------------------------------------------- sshd-Konfiguration
# Drop-in statt Aenderung an sshd_config: ueberlebt Paket-Upgrades sauber.
DROPIN=/etc/ssh/sshd_config.d/99-claude-hardening.conf
mkdir -p /etc/ssh/sshd_config.d

# ClientAliveInterval haelt die SSH-Sitzung durch NAT-Timeouts am Leben. Das ist der
# SSH-Gegenpart zu den TCP-Keepalives aus dem Installer.
{
  echo "# Von harden-ssh.sh verwaltet."
  echo "ClientAliveInterval 60"
  echo "ClientAliveCountMax 10"
  echo "TCPKeepAlive yes"
  echo "MaxAuthTries 4"
  echo "LoginGraceTime 30"
  echo "X11Forwarding no"
  echo "AllowAgentForwarding no"
} > "${DROPIN}"

if [[ ${DISABLE_PASSWORDS} -eq 1 ]]; then
  if [[ "${KEY_COUNT}" -lt 1 ]]; then
    rm -f "${DROPIN}"
    die "Abbruch: kein SSH-Schluessel fuer '${SSH_USER}' hinterlegt.
     Passwort-Login abzuschalten wuerde dich aussperren.
     Erst:  bash harden-ssh.sh --key \"ssh-ed25519 AAAA...\"
     Dann:  bash harden-ssh.sh --disable-passwords"
  fi
  {
    echo "PasswordAuthentication no"
    echo "KbdInteractiveAuthentication no"
    echo "PermitRootLogin prohibit-password"
  } >> "${DROPIN}"
  log "Passwort-Login wird abgeschaltet (${KEY_COUNT} Schluessel vorhanden)."
else
  log "Passwort-Login bleibt aktiv. Zum Abschalten: --disable-passwords (Schluessel noetig)."
fi

# Ubuntu-Cloud-Images setzen PasswordAuthentication oft in einem frueheren Drop-in.
# Bei sshd gewinnt der ERSTE Treffer, nicht der letzte - konkurrierende Eintraege deshalb melden.
if [[ ${DISABLE_PASSWORDS} -eq 1 ]]; then
  while IFS= read -r conflict; do
    [[ "${conflict}" == "${DROPIN}" ]] && continue
    warn "Konkurrierende PasswordAuthentication-Zeile in ${conflict} - sshd wertet den ersten Treffer aus."
    # Vor dem Editieren einer System-Konfigurationsdatei eine Sicherung anlegen.
    cp -n "${conflict}" "${conflict}.bak-claude" 2>/dev/null || true
    sed -i 's/^\s*PasswordAuthentication\s\+yes/#&/I' "${conflict}" && \
      log "  auskommentiert in ${conflict} (Sicherung: ${conflict}.bak-claude)"
  done < <(grep -rlsiE '^\s*PasswordAuthentication\s+yes' /etc/ssh/sshd_config /etc/ssh/sshd_config.d/ 2>/dev/null || true)
fi

# ---------------------------------------------------------------- Validieren + neu laden
log "Pruefe sshd-Konfiguration"
if ! sshd -t; then
  rm -f "${DROPIN}"
  die "sshd-Konfiguration fehlerhaft - Aenderung zurueckgenommen, nichts neu geladen."
fi
# 'reload' statt 'restart': bestehende Sitzungen bleiben bestehen. Falls die neue Konfiguration
# doch aussperrt, bleibt die aktuelle SSH-Sitzung zum Korrigieren offen.
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
log "sshd neu geladen."

# ---------------------------------------------------------------- Firewall
# Den tatsaechlich genutzten SSH-Port ermitteln, statt 22 anzunehmen. Laeuft sshd auf einem
# anderen Port, wuerde ein blindes "ufw allow 22/tcp" den Zugang beim Aktivieren kappen.
SSH_PORTS="$(sshd -T 2>/dev/null | awk '/^port /{print $2}' | sort -u || true)"
if [[ -z "${SSH_PORTS}" ]]; then
  # Fallback: was horcht gerade?
  SSH_PORTS="$(ss -lntp 2>/dev/null | awk '/sshd/{split($4,a,":"); print a[length(a)]}' | sort -u || true)"
fi
[[ -z "${SSH_PORTS}" ]] && SSH_PORTS=22
log "Erkannte SSH-Ports: $(echo ${SSH_PORTS} | tr '\n' ' ')"

if command -v ufw >/dev/null 2>&1 || apt-get install -y -qq ufw >/dev/null 2>&1; then
  log "Konfiguriere Firewall (ausgehend offen, eingehend nur SSH)"
  # Bewusst KEIN "ufw reset": das wuerde bestehende Regeln anderer Dienste stillschweigend
  # loeschen. Regeln sind idempotent, doppelte Aufrufe sind unschaedlich.
  ufw default deny incoming  >/dev/null 2>&1 || true
  ufw default allow outgoing >/dev/null 2>&1 || true
  # Erst die SSH-Ports freigeben, DANN aktivieren - nie umgekehrt.
  for port in ${SSH_PORTS}; do
    [[ "${port}" =~ ^[0-9]+$ ]] || continue
    ufw allow "${port}/tcp" >/dev/null 2>&1 || warn "  ufw-Regel fuer Port ${port} fehlgeschlagen"
  done
  if ufw status 2>/dev/null | head -1 | grep -qi 'inactive'; then
    ufw --force enable >/dev/null 2>&1 || warn "  ufw liess sich nicht aktivieren"
  fi
  log "  ufw aktiv: eingehend nur $(echo ${SSH_PORTS} | tr '\n' ' ')(tcp)"
fi

# ---------------------------------------------------------------- fail2ban
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
echo "  Benutzer          : ${SSH_USER}"
echo "  Schluessel        : ${KEY_COUNT}"
echo "  Passwort-Login    : $([[ ${DISABLE_PASSWORDS} -eq 1 ]] && echo 'abgeschaltet' || echo 'noch aktiv')"
echo "  Eingehende Ports  : $(echo ${SSH_PORTS} | tr '\n' ' ')(tcp, sonst nichts)"
echo
echo "  WICHTIG: Diese SSH-Sitzung offen lassen und in einem ZWEITEN Terminal"
echo "  testen, ob der Login weiterhin funktioniert. Erst dann schliessen."
echo
