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
BACKUP_DIR="$(mktemp -d /root/.ssh-hardening-backup.XXXXXX)"
for f in /etc/ssh/sshd_config "${DROPIN}"; do
  [[ -f "$f" ]] && cp -a "$f" "${BACKUP_DIR}/$(echo "$f" | tr / _)"
done
for f in /etc/ssh/sshd_config.d/*.conf; do
  [[ -f "$f" ]] && cp -a "$f" "${BACKUP_DIR}/$(echo "$f" | tr / _)"
done

rollback() {
  for b in "${BACKUP_DIR}"/*; do
    [[ -f "$b" ]] || continue
    orig="$(basename "$b" | tr _ /)"
    cp -a "$b" "${orig}"
  done
  # Ein in diesem Lauf NEU erzeugtes Drop-in gab es vorher nicht - entfernen.
  [[ -f "${BACKUP_DIR}/$(echo "${DROPIN}" | tr / _)" ]] || rm -f "${DROPIN}"
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
