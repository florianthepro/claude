"""Konfiguration und Secret-Handling.

Secrets kommen ausschliesslich aus Umgebungsvariablen. Optional werden sie aus
einer Datei geladen, die Modus 0600 haben muss. Tokens tauchen nie in Argumenten,
Logs oder Fehlermeldungen auf -- `redact()` ist die einzige erlaubte Ausgabeform.
"""

from __future__ import annotations

import os
import stat
from dataclasses import dataclass, field
from pathlib import Path

DEFAULT_ENV_FILE = Path.home() / ".config" / "domainfinder" / "secrets.env"

# --- Rate-Limits je Stufe (Anfragen pro Sekunde), per CLI ueberschreibbar -----
DEFAULT_RATES = {
    "dns": 40.0,      # DoH ist grosszuegig; 32 Worker sind laut Aufgabe unkritisch
    "rdap": 3.5,      # Verisign drosselt bei ~4 rps pro IP
    "registrar": 2.0,  # Cloudflare API, 20 Domains pro Request
}
DEFAULT_WORKERS = {"dns": 32, "rdap": 4, "registrar": 2}


class ConfigError(RuntimeError):
    """Fehlende oder unsichere Konfiguration."""


def redact(value: str | None) -> str:
    """Gibt ein Secret in einer Form zurueck, die geloggt werden darf."""
    if not value:
        return "<unset>"
    return f"<set:{len(value)} Zeichen, endet auf …{value[-3:]}>" if len(value) > 8 else "<set>"


def load_env_file(path: Path | None = None, *, required: bool = False) -> int:
    """Laedt KEY=VALUE-Zeilen aus einer 0600-Datei in os.environ.

    Bereits gesetzte Umgebungsvariablen gewinnen -- die Datei ist nur Fallback.
    Gibt die Anzahl neu gesetzter Variablen zurueck.
    """
    path = path or DEFAULT_ENV_FILE
    if not path.exists():
        if required:
            raise ConfigError(f"Secret-Datei fehlt: {path}")
        return 0

    mode = stat.S_IMODE(path.stat().st_mode)
    if mode & 0o077:
        raise ConfigError(
            f"Secret-Datei {path} hat Modus {mode:04o}, verlangt ist 0600. "
            f"Korrektur: chmod 600 {path}"
        )

    loaded = 0
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export "):
            line = line[len("export "):].strip()
        key, sep, value = line.partition("=")
        if not sep:
            continue
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key and key not in os.environ:
            os.environ[key] = value
            loaded += 1
    return loaded


@dataclass(frozen=True)
class CloudflareCreds:
    account_id: str
    token: str = field(repr=False)  # niemals in Tracebacks

    def __str__(self) -> str:  # pragma: no cover - reine Anzeige
        return f"CloudflareCreds(account_id={self.account_id!r}, token={redact(self.token)})"


def cloudflare_creds(*, required: bool = True) -> CloudflareCreds | None:
    """Liest Cloudflare-Zugangsdaten aus der Umgebung.

    Akzeptiert die ueblichen Variablennamen. Gibt None zurueck, wenn nichts
    gesetzt ist und `required` False ist -- dann laeuft die Pipeline bis Stufe 2.
    """
    account = os.environ.get("CLOUDFLARE_ACCOUNT_ID") or os.environ.get("CF_ACCOUNT_ID")
    token = os.environ.get("CLOUDFLARE_API_TOKEN") or os.environ.get("CF_API_TOKEN")
    if not account or not token:
        if required:
            missing = [
                name
                for name, val in (("CLOUDFLARE_ACCOUNT_ID", account), ("CLOUDFLARE_API_TOKEN", token))
                if not val
            ]
            raise ConfigError(
                "Stufe 3 braucht " + " und ".join(missing) + ". "
                f"Entweder exportieren oder in {DEFAULT_ENV_FILE} (Modus 0600) hinterlegen."
            )
        return None
    return CloudflareCreds(account_id=account.strip(), token=token.strip())


def secure_write_path(path: Path) -> Path:
    """Legt Verzeichnis an und stellt sicher, dass die Datei 0600 bekommt."""
    path.parent.mkdir(parents=True, exist_ok=True)
    path.touch(mode=0o600, exist_ok=True)
    os.chmod(path, 0o600)
    return path
