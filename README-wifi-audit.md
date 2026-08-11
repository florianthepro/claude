# wifi-audit.sh

Autonomer WPA/WPA2-Handshake-Audit auf Basis der aircrack-ng-Suite.
Neufassung meiner `cli.sh` / `enhanced-cli.sh` — auf den Kern reduziert,
maximal selbstständig, minimale Interaktion.

> ⚠️ **Nur autorisierter Einsatz.** Ausschließlich Netzwerke testen, die dir
> gehören oder für die du eine schriftliche Erlaubnis hast.

## Prinzip

```
starten → (Interface nur falls mehrere) → Netz wählen
        → Angriff läuft autonom → warten → Ergebnis
```

Nach der Zielwahl **keine weiteren Fragen**. Der Angriff eskaliert den Deauth
selbst und wartet in Runden, bis der Handshake sitzt — dann wird sofort
gecrackt.

- **Interface**: automatisch erkannt; Nachfrage nur bei mehreren Adaptern.
- **Ziel**: aus der Scan-Liste, `Enter` wählt das stärkste Netz.
- **Deauth**: gezielt gegen alle erkannten Clients des Ziels **plus** Broadcast,
  mit automatisch steigender Stärke (5 → 30) über die Runden.
- **Handshake**: wird laufend geprüft; sobald erfasst, endet die Wartephase.
- **Cracken**: startet automatisch; Ergebnis wird in `./wifi-audit-results.txt`
  protokolliert.

## Nutzung

```bash
sudo ./wifi-audit.sh                       # geführt: nur Ziel wählen, dann warten
sudo ./wifi-audit.sh -b AA:BB:CC:11:22:33  # ohne jede Frage (Kanal wird gesucht)
sudo ./wifi-audit.sh -w /pfad/liste.txt    # eigene Wortliste
sudo ./wifi-audit.sh -C                    # bei Erfolg automatisch verbinden
```

| Option | Bedeutung |
|--------|-----------|
| `-i, --iface`     | WLAN-Interface (sonst automatisch) |
| `-w, --wordlist`  | Wortliste (Standard: `rockyou.txt`, auto-entpackt) |
| `-b, --bssid`     | Ziel-BSSID → Scan/Auswahl entfällt, Kanal wird gesucht |
| `-c, --channel`   | Ziel-Kanal (optional zu `-b`) |
| `-e, --essid`     | Ziel-Name (optional zu `-b`) |
| `-s, --scan-time` | Scan-Dauer (Standard 15 s) |
| `-T, --cap-time`  | Handshake-Budget (Standard 180 s) |
| `-C, --connect`   | Bei Erfolg automatisch per `nmcli` verbinden |
| `-y, --yes`       | Ohne Autorisierungs-Nachfrage starten |

## Robustheit / behobene Fehler

- **Scan hängt nicht.** Hintergrundprozess + sauberes `SIGINT` statt
  `timeout airodump-ng …` (airodump fängt SIGTERM ab und blockiert);
  `--write-interval 1` für geflushte CSV.
- **Robuster CSV-Parser.** Nur AP-Sektion (stoppt bei `Station MAC`),
  ESSIDs mit Kommas, versteckte SSIDs, Carriage-Returns; nach Signal sortiert.
- **Gezielter Deauth.** Erkennt verbundene Clients des Ziels aus der
  Capture-CSV (case-insensitiv, fremde APs ignoriert) und deauthed sie direkt —
  deutlich schneller als reiner Broadcast.
- **Zuverlässige Monitor-Erkennung** über `iw dev … type monitor` (Name bleibt
  gleich *oder* wird zu `<if>mon`).
- **Auto-sudo** ohne Doppel-Nachfrage · **sauberes Cleanup** bei jedem
  Exit/Ctrl-C (Monitor aus, NetworkManager zurück, Temp weg).
- **Kein `set -e`-Frühabbruch** durch Tools mit legitimem Exit-Code ≠ 0.
- Geprüft mit `bash -n` und `shellcheck` (keine warnings/errors).

## Voraussetzungen

aircrack-ng-Suite (`airmon-ng`, `airodump-ng`, `aireplay-ng`, `aircrack-ng`)
und `iw`; fehlen sie, versucht das Script `apt-get install` automatisch.
Für `-C` (verbinden) optional `nmcli`.
