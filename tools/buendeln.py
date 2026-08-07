#!/usr/bin/env python3
"""Baut aus den Modulen drei Einzeldateien.

    python3 tools/buendeln.py [zielverzeichnis]

Wer mitmachen will, soll nicht erst ein Projekt auschecken muessen. Heraus
kommen drei Dateien, die jeweils fuer sich allein lauffaehig sind:

    nyx.py          Knoten und Client fuer die Kommandozeile, nur Python
    nyx-knoten.php  Knoten fuer jeden Webspace mit PHP und sodium
    nyx.html        Client fuer den Browser, eine Datei, kein Bauschritt

Die Buendel sind erzeugt, nicht von Hand gepflegt. Geaendert wird immer
unter impl/, danach dieses Werkzeug laufen lassen.
"""

from __future__ import annotations

import ast
import re
import subprocess
import sys
from pathlib import Path

WURZEL = Path(__file__).resolve().parent.parent
PY = WURZEL / "impl" / "python" / "nyx"
PHP = WURZEL / "impl" / "php"
WEB = WURZEL / "impl" / "web"

# Reihenfolge nach Abhaengigkeiten — von den Primitiven nach oben.
PY_MODULE = [
    "primitives/kdf.py",
    "primitives/aead.py",
    "primitives/curve25519.py",
    "identity.py",
    "directory.py",
    "x3dh.py",
    "ratchet.py",
    "gf256.py",
    "dispersal.py",
    "puncturable.py",
    "store.py",
    "drops.py",
    "mixnet.py",
    "client.py",
    "services/chat.py",
    "services/files.py",
    "services/mail.py",
    "services/calls.py",
    "node.py",
    "cli.py",
]

PHP_DATEI = ["src/Canonical.php", "src/Puncturable.php", "src/Store.php",
             "src/Directory.php"]

KOPF_PY = '''#!/usr/bin/env python3
"""Nyx — anonymer, dezentraler Messenger. Alles in einer Datei.

    python3 nyx.py demo                      Vorfuehrung, ohne Netz
    python3 nyx.py knoten --port 8443        eigenen Knoten betreiben
    python3 nyx.py init                      Identitaet anlegen
    python3 nyx.py senden <adresse> "Text"
    python3 nyx.py holen

Keine Installation, keine Fremdpakete, Python 3.11 genuegt.

Diese Datei ist erzeugt aus impl/python/nyx. Der ausfuehrliche Quelltext mit
Tests und Dokumentation liegt dort; hier steht dasselbe am Stueck, damit man
mitmachen kann, ohne ein Projekt auszuchecken.

WICHTIG: Die kryptographischen Primitive sind reines Python und nicht
laufzeitkonstant. Fuer den ernsthaften Betrieb sind sie durch libsodium zu
ersetzen. Was sonst noch zu tun waere, steht in docs/sicherheitshinweis.md.
"""

'''

KOPF_PHP = '''<?php
declare(strict_types=1);

/**
 * Nyx — Knoten und Verzeichnis. Alles in einer Datei.
 *
 * Aufsetzen:
 *   1. Diese Datei auf einen Webspace mit PHP 8 und der Erweiterung sodium
 *      legen, zum Beispiel als index.php.
 *   2. Ein beschreibbares Datenverzeichnis angeben (NYX_DATA), das von
 *      aussen nicht erreichbar ist.
 *   3. Fertig. Keine Datenbank, kein Composer, keine Anmeldung.
 *
 * Zum Ausprobieren genuegt:
 *   NYX_DATA=/tmp/nyx php -S 0.0.0.0:8080 nyx-knoten.php
 *
 * Der Knoten fuehrt kein Zugriffsprotokoll. Das ist keine Nachlaessigkeit:
 * wer wann welchen Tag abgefragt hat, waere genau die Metadatenspur, die
 * das uebrige System vermeidet.
 *
 * Erzeugt aus impl/php.
 */

'''


def lies(pfad: Path) -> str:
    return pfad.read_text(encoding="utf-8")


# -- Python -----------------------------------------------------------------

def buendle_python() -> str:
    """Haengt die Module aneinander und loest die relativen Importe auf.

    Die Analyse laeuft ueber den Syntaxbaum, nicht ueber Zeilenmuster: nur
    so werden mehrzeilige Importe zuverlaessig als Ganzes erkannt.
    """
    stdlib: set[str] = set()
    teile: list[str] = []

    for name in PY_MODULE:
        text = lies(PY / name)
        zeilen = text.splitlines()
        streichen: set[int] = set()          # 0-basiert

        baum = ast.parse(text)
        oberste = {id(k) for k in baum.body}

        # Relative Importe entfallen in jeder Tiefe — auch die in
        # Funktionsruempfen, die sonst zur Laufzeit scheitern wuerden.
        for knoten in ast.walk(baum):
            if not isinstance(knoten, (ast.Import, ast.ImportFrom)):
                continue
            relativ = isinstance(knoten, ast.ImportFrom) and knoten.level > 0
            if not relativ and id(knoten) not in oberste:
                continue                      # verschachtelter Standardimport bleibt

            if relativ and any(a.asname or a.name[0].islower() and knoten.level
                               and not knoten.module for a in knoten.names):
                # 'from . import modul' liesse einen Modulnamen zurueck, den es
                # in der Einzeldatei nicht gibt. Lieber hier abbrechen als eine
                # Datei ausliefern, die erst zur Laufzeit scheitert.
                if knoten.module is None:
                    raise SystemExit(
                        f"{name}:{knoten.lineno}: 'from . import <modul>' laesst sich "
                        f"nicht buendeln — die Namen direkt importieren.")

            streichen.update(range(knoten.lineno - 1,
                                   (knoten.end_lineno or knoten.lineno)))
            if not relativ:
                quelle = ast.get_source_segment(text, knoten) or ""
                if not quelle.startswith("from __future__"):
                    stdlib.add(" ".join(quelle.split()))

        # Der Modulkopf wird zum Kommentar — als Zeichenkette mitten in der
        # Datei waere er nur Ballast.
        kopf = ast.get_docstring(baum, clean=False)
        if kopf is not None and isinstance(baum.body[0], ast.Expr):
            erste = baum.body[0]
            streichen.update(range(erste.lineno - 1, (erste.end_lineno or erste.lineno)))

        rumpf = "\n".join(z for i, z in enumerate(zeilen) if i not in streichen)
        titel = "\n".join(f"# {z}".rstrip() for z in (kopf or name).strip().splitlines())

        teile.append(f"# {'=' * 74}\n# {name}\n# {'=' * 74}\n#\n{titel}\n\n"
                     f"{rumpf.strip(chr(10))}\n")

    importe = "\n".join(sorted(stdlib, key=lambda z: (not z.startswith("import "), z)))
    return (KOPF_PY + "from __future__ import annotations\n\n" + importe
            + "\n\n\n" + "\n\n".join(teile)
            + '\n\nif __name__ == "__main__":\n    raise SystemExit(main())\n')


# -- PHP --------------------------------------------------------------------

def buendle_php() -> str:
    teile = []
    for name in PHP_DATEI:
        text = lies(PHP / name)
        text = re.sub(r"^<\?php\s*", "", text)
        text = re.sub(r"^declare\(strict_types=1\);\s*", "", text, flags=re.M)
        text = re.sub(r"^namespace Nyx;\s*", "", text, flags=re.M)
        teile.append(text.strip())

    router = lies(PHP / "public" / "index.php")
    router = re.sub(r"^<\?php\s*", "", router)
    router = re.sub(r"^declare\(strict_types=1\);\s*", "", router, flags=re.M)
    router = re.sub(r"^require .*$\n?", "", router, flags=re.M)
    router = re.sub(r"^use Nyx\\\w+;\s*$\n?", "", router, flags=re.M)
    # Der Kopfkommentar des Routers wird durch den Kopf dieser Datei ersetzt.
    router = re.sub(r"^/\*\*.*?\*/\s*", "", router, flags=re.S)

    return (KOPF_PHP + "namespace Nyx;\n\n" + "\n\n".join(teile)
            + "\n\n// " + "=" * 74 + "\n// Schnittstelle\n// " + "=" * 74
            + "\n\n" + router.strip() + "\n")


# -- Web --------------------------------------------------------------------

def buendle_web() -> str:
    """Legt Stil und die drei Module in eine einzige HTML-Datei."""
    html = lies(WEB / "index.html")
    css = lies(WEB / "style.css")

    module = []
    for name in ("nyx-crypto.js", "nyx-protocol.js", "app.js"):
        text = lies(WEB / name)
        # Importe entfallen, Exporte werden zu gewoehnlichen Deklarationen.
        text = re.sub(r"^import\s*\{[^}]*\}\s*from\s*'[^']*';\s*$\n?", "", text, flags=re.M)
        text = re.sub(r"^import\s+[^;]*;\s*$\n?", "", text, flags=re.M)
        text = re.sub(r"^export\s*\{[^}]*\};\s*$\n?", "", text, flags=re.M)
        text = re.sub(r"^export\s+(?=(async\s+function|function|class|const|let|var))",
                      "", text, flags=re.M)
        module.append(f"// {'=' * 70}\n// {name}\n// {'=' * 70}\n\n{text.strip()}")

    html = html.replace('<link rel="stylesheet" href="style.css">',
                        f"<style>\n{css.strip()}\n</style>")
    html = html.replace('<script type="module" src="app.js"></script>',
                        '<script type="module">\n'
                        + "\n\n".join(module) + "\n</script>")

    hinweis = ("<!--\n  Nyx — Client fuer den Browser. Alles in einer Datei.\n\n"
               "  Oeffnen ueber einen Webserver (nicht per file://, das erlauben\n"
               "  ES-Module nicht). Zum Ausprobieren genuegt im selben Ordner:\n\n"
               "      python3 -m http.server 8080\n\n"
               "  Erzeugt aus impl/web.\n-->\n")
    return html.replace("<!DOCTYPE html>", "<!DOCTYPE html>\n" + hinweis, 1)


# -- Pruefen ----------------------------------------------------------------

def pruefe(pfad: Path) -> str:
    """Jedes Buendel wird nach dem Erzeugen geprueft, nicht nur geschrieben."""
    if pfad.suffix == ".py":
        r = subprocess.run([sys.executable, "-m", "py_compile", str(pfad)],
                           capture_output=True, text=True)
    elif pfad.suffix == ".php":
        r = subprocess.run(["php", "-l", str(pfad)], capture_output=True, text=True)
    else:
        return "nicht geprueft"
    return "in Ordnung" if r.returncode == 0 else f"FEHLER: {(r.stderr or r.stdout).strip()}"


def main(argv: list[str]) -> int:
    ziel = Path(argv[1]) if len(argv) > 1 else WURZEL / "dist"
    ziel.mkdir(parents=True, exist_ok=True)

    erzeugt = [
        (ziel / "nyx.py", buendle_python()),
        (ziel / "nyx-knoten.php", buendle_php()),
        (ziel / "nyx.html", buendle_web()),
    ]

    for pfad, inhalt in erzeugt:
        pfad.write_text(inhalt, encoding="utf-8")
        if pfad.suffix == ".py":
            pfad.chmod(0o755)
        zeilen = inhalt.count("\n")
        print(f"  {pfad.name:<16} {len(inhalt) // 1024:>4} KiB  "
              f"{zeilen:>5} Zeilen  {pruefe(pfad)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
