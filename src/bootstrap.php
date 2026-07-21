<?php
/**
 * Bootstrap: Pfade definieren, Laufzeit-Verzeichnisse anlegen, /data härten,
 * Secret-Key erzeugen, Datenbank migrieren. Wird von public/index.php geladen.
 */
declare(strict_types=1);

require __DIR__ . '/autoload.php';

use Nexus\Core\Database;
use Nexus\Core\Migrations;

/* ---- Basis-Pfade ------------------------------------------------- *
 * data/ liegt AUSSERHALB von public/ und ist damit nicht per Web
 * erreichbar (zusätzlich zur .htaccess-Sperre = Defense in Depth).   */
define('NX_ROOT',    dirname(__DIR__));
define('NX_SRC',     __DIR__);
define('NX_DATA',    NX_ROOT . '/data');
define('NX_SYS',     NX_DATA . '/sys');
define('NX_DB',      NX_SYS  . '/app.sqlite');
define('NX_SECRET',  NX_SYS  . '/secret.key');
define('NX_SESSIONS',NX_SYS  . '/sessions');
define('NX_VERSION', '2.0.0');
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
