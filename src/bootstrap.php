<?php
/**
 * Bootstrap: Pfade definieren, Laufzeit-Verzeichnisse anlegen, /data härten,
 * Secret-Key erzeugen, Datenbank migrieren. Wird von setup.php geladen.
 */
declare(strict_types=1);

require __DIR__ . '/autoload.php';

use Nexus\Core\Database;
use Nexus\Core\Migrations;

/* ---- Basis-Pfade ------------------------------------------------- *
 * data/, src/ und apps/ werden per .htaccess vom Web gesperrt; nur
 * setup.php ist erreichbar (Front Controller + Asset-Auslieferung).  */
define('NX_ROOT',    dirname(__DIR__));
define('NX_SRC',     __DIR__);
define('NX_APPS',    NX_ROOT . '/apps');
define('NX_DATA',    NX_ROOT . '/data');
define('NX_SYS',     NX_DATA . '/sys');
define('NX_DB',      NX_SYS  . '/app.sqlite');
define('NX_SECRET',  NX_SYS  . '/secret.key');
define('NX_SESSIONS',NX_SYS  . '/sessions');
define('NX_VERSION', '2.1.0');
define('NX_NAME',    'Nexus');

/** Quota-Vorgaben (Bytes). */
define('NX_QUOTA_PENDING', 512 * 1024 * 1024);        // 0,5 GB vor Freischaltung
define('NX_QUOTA_ACTIVE',  1024 * 1024 * 1024);       // 1 GB nach Freischaltung

function nx_bootstrap(): void
{
    // Laufzeit-Verzeichnisse (inkl. je-App-Ordner für Nutzerdaten)
    $dirs = [NX_DATA, NX_SYS, NX_SESSIONS];
    foreach (array_keys(nx_apps()) as $appId) {
        if (in_array($appId, ['home', 'settings', 'admin'], true)) {
            continue;
        }
        $dirs[] = NX_DATA . '/' . $appId;
    }
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0770, true);
        }
    }
    if (!is_dir(NX_DATA)) {
        http_response_code(500);
        exit('Fehler: data/ konnte nicht angelegt werden. Schreibrechte prüfen.');
    }

    // /data zusätzlich per .htaccess sperren (falls Docroot doch = Repo-Root)
    $hta = NX_DATA . '/.htaccess';
    if (!is_file($hta)) {
        @file_put_contents($hta, implode("\n", [
            '# Automatisch von Nexus erstellt – data/ ist komplett gesperrt.',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '    Order allow,deny',
            '    Deny from all',
            '</IfModule>',
            'Options -Indexes',
            '',
        ]));
    }
    $guard = "<?php http_response_code(403); exit('Zugriff verweigert.');";
    foreach ([NX_DATA, NX_SYS] as $d) {
        if (!is_file($d . '/index.php')) {
            @file_put_contents($d . '/index.php', $guard);
        }
    }

    // Secret-Key für symmetrische Verschlüsselung
    if (!is_file(NX_SECRET)) {
        @file_put_contents(NX_SECRET, bin2hex(random_bytes(32)));
        @chmod(NX_SECRET, 0600);
    }

    // Session-Speicher innerhalb der gesperrten data/sys
    if (is_dir(NX_SESSIONS) && is_writable(NX_SESSIONS)) {
        session_save_path(NX_SESSIONS);
    }

    // Datenbank + Schema
    Migrations::run(Database::pdo());
}

/** Prüft zwingende Voraussetzungen; liefert Liste fehlender Punkte. */
function nx_requirements(): array
{
    $missing = [];
    if (version_compare(PHP_VERSION, '7.4.0', '<')) {
        $missing[] = 'PHP ≥ 7.4 erforderlich (aktuell ' . PHP_VERSION . ').';
    }
    if (!extension_loaded('pdo_sqlite')) {
        $missing[] = 'PHP-Erweiterung <code>pdo_sqlite</code> ist nicht aktiv.';
    }
    if (!is_writable(NX_ROOT) && !is_dir(NX_DATA)) {
        $missing[] = 'Verzeichnis nicht beschreibbar – <code>data/</code> kann nicht angelegt werden.';
    }
    return $missing;
}

/** Minimales Setup-/Fehlerseiten-Layout, falls Voraussetzungen fehlen. */
function nx_setup_page(array $missing): void
{
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Nexus – Setup</title>';
    echo '<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#0f1115;color:#cfd3d9;'
       . 'display:grid;place-items:center;min-height:100vh;margin:0}.c{max-width:440px;background:#171a1f;'
       . 'border:1px solid #2a2e35;border-radius:6px;padding:28px}h1{font-size:18px;margin:0 0 6px}'
       . 'p{color:#848a94;font-size:14px}li{margin:8px 0}code{font-family:ui-monospace,monospace;color:#c25a5a}'
       . '.ok{color:#4a9d6f}</style>';
    echo '<div class="c"><h1>Nexus – Setup</h1>';
    echo '<p>Bevor es losgeht, fehlen noch Voraussetzungen:</p><ul>';
    foreach ($missing as $m) {
        echo '<li>' . $m . '</li>';
    }
    echo '</ul><p>Nach dem Beheben Seite neu laden. Danach legt das erste Konto automatisch den Administrator an.</p></div>';
}
