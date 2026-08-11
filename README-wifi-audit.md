# wifi-audit.sh

Schlanker, selbstständiger WPA/WPA2-Handshake-Audit auf Basis der
aircrack-ng-Suite. Neufassung meiner `cli.sh` / `enhanced-cli.sh` — auf den
Kern reduziert, minimale Nachfragen, sauberes visuelles Feedback und ohne den
Scan-Hänger.

> ⚠️ **Nur autorisierter Einsatz.** Ausschließlich Netzwerke testen, die dir
> gehören oder für die du eine schriftliche Erlaubnis hast.

## Ablauf (vollautomatisch)

```
root → Interface → Monitor-Mode → Scan → Ziel wählen
     → Handshake (+ Deauth) → Cracken → optional verbinden
```

Einzige Nachfrage im Standardlauf: **welches Netz** angegriffen wird.
Mit `-b`/`-c`/`-e` läuft alles ohne Rückfrage.

## Nutzung

```bash
sudo ./wifi-audit.sh                       # geführt, minimal
sudo ./wifi-audit.sh -w /pfad/liste.txt    # eigene Wortliste
sudo ./wifi-audit.sh -b AA:BB:CC:11:22:33 -c 6 -e "Netz"   # vollautomatisch
sudo ./wifi-audit.sh -y                    # ohne Autorisierungs-Nachfrage
```

| Option | Bedeutung |
|--------|-----------|
| `-i, --iface`    | WLAN-Interface (sonst automatisch erkannt) |
| `-w, --wordlist` | Wortliste (Standard: `rockyou.txt`) |
| `-b, --bssid`    | Ziel-BSSID → überspringt Scan/Auswahl |
| `-c, --channel`  | Ziel-Kanal |
| `-e, --essid`    | Ziel-Name |
| `-s, --scan-time`| Scan-Dauer (Standard 15 s) |
| `-T, --cap-time` | Handshake-Timeout (Standard 90 s) |
| `-y, --yes`      | Autorisierungs-Hinweis ohne Nachfrage |

## Was gegenüber den alten Scripts behoben ist

- **Scan hängt nicht mehr.** Statt `timeout airodump-ng …` (airodump fängt
  SIGTERM ab und blockiert) läuft der Scan im Hintergrund und wird sauber per
  `SIGINT` beendet; `--write-interval 1` sorgt für eine geflushte CSV.
- **Robuster CSV-Parser.** Wertet nur die AP-Sektion aus (stoppt bei
  `Station MAC`), verträgt ESSIDs mit Kommas, versteckte SSIDs und
  Carriage-Returns; sortiert nach Signalstärke.
- **Monitor-Interface wird zuverlässig erkannt** (Name bleibt gleich *oder*
  wird zu `<if>mon`) über `iw dev … type monitor`.
- **Automatische Elevation** per `sudo` ohne Doppel-Nachfrage.
- **Sauberes Aufräumen** bei jedem Exit/Ctrl-C: Monitor-Mode aus,
  NetworkManager zurück, Temp-Dateien weg.
- **Kein `set -e`-Frühabbruch** — Tools mit legitimem Exit-Code ≠ 0
  killen das Script nicht mehr.

## Voraussetzungen

`aircrack-ng`-Suite (`airmon-ng`, `airodump-ng`, `aireplay-ng`, `aircrack-ng`)
und `iw`. Fehlen sie, versucht das Script `apt-get install` automatisch.
Für „verbinden" optional `nmcli`.
