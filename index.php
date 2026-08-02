<?php

declare(strict_types=1);

/**
 * Front-Controller (liegt bewusst im Webroot: Hochladen/Entpacken genügt).
 * Sicherheitsheader, Session, Sprachauflösung, zentrale CSRF-Prüfung für alle
 * POST-Anfragen, Jury-Mitwirkungs-Gate, Routing. Läuft auch in einem
 * Unterordner des Webspace (Basispfad wird automatisch erkannt).
 */

// Freundlicher Hinweis statt weißer Seite, falls der Hoster noch auf einer
// alten PHP-Version steht. (Nur hier: keine PHP-8-Syntax vor dieser Prüfung.)
if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
        . '<title>PHP-Version zu alt</title></head><body style="font-family:sans-serif;max-width:40em;margin:3em auto">'
        . '<h1>PHP-Version zu alt</h1><p>Diese Anwendung ben&ouml;tigt PHP 8.2 oder neuer '
        . '(gefunden: ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '). '
        . 'Bitte stellen Sie die PHP-Version im Verwaltungsbereich Ihres Hosters um.</p></body></html>';
    exit;
}

// Basispfad erkennen (Installation im Webroot ODER in einem Unterordner).
$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
$basePath = rtrim($scriptDir, '/');
if ($basePath !== '' && preg_match('#^(/[A-Za-z0-9._~\-]+)+$#', $basePath) !== 1) {
    $basePath = '';
}
define('STIMMWERK_BASE', $basePath);

// Saubere Pfade (/topics) nur verlinken, wenn die Rewrite-Regeln nachweislich
// aktiv sind (Kennung aus .htaccess bzw. router.php). Sonst werden Links im
// überall funktionierenden Stil /index.php/topics erzeugt – so gibt es auch
// auf Servern ohne mod_rewrite/.htaccess keine toten Links (404).
define('STIMMWERK_CLEAN_URLS', (($_SERVER['SW_CLEAN_URLS'] ?? $_SERVER['REDIRECT_SW_CLEAN_URLS'] ?? '') === '1'));

$factory = require __DIR__ . '/src/bootstrap.php';

try {
    $app = $factory();
} catch (Throwable $e) {
    // Häufigste Ursache nach dem Hochladen: data/ ist nicht beschreibbar.
    error_log('stimmwerk bootstrap: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $dataWritable = is_writable(__DIR__ . '/data') || (!is_dir(__DIR__ . '/data') && is_writable(__DIR__));
    $hint = $dataWritable
        ? 'Bitte pr&uuml;fen Sie, ob die PHP-Erweiterungen <code>pdo_sqlite</code> und <code>mbstring</code> aktiv sind.'
        : 'Bitte machen Sie das Verzeichnis <code>data/</code> f&uuml;r PHP beschreibbar (per FTP: Rechte 755 oder 775 setzen) und laden Sie die Seite neu.';
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Einrichtung erforderlich</title></head><body style="font-family:sans-serif;max-width:40em;margin:3em auto;padding:0 1em">'
        . '<h1>Fast geschafft</h1><p>Die Anwendung konnte noch nicht starten.</p><p>' . $hint . '</p>'
        . '<p style="color:#666">Details stehen im Server-Fehlerprotokoll; auf der Seite werden aus Sicherheitsgr&uuml;nden keine internen Angaben angezeigt.</p>'
        . '</body></html>';
    exit;
}

use Stimmwerk\Controllers\AccountController;
use Stimmwerk\Controllers\AuthController;
use Stimmwerk\Controllers\FavoriteController;
use Stimmwerk\Controllers\JuryController;
use Stimmwerk\Controllers\PageController;
use Stimmwerk\Controllers\ReportController;
use Stimmwerk\Controllers\TopicController;
use Stimmwerk\Controllers\VoteController;
use Stimmwerk\I18n\I18n;
use Stimmwerk\Security\Headers;

Headers::send($app->session->isHttps());
$app->session->start();

// Sprache: Nutzerkonto -> Sitzung -> Standard.
$user = $app->auth->user();
$lang = is_string($app->session->get('lang')) ? (string) $app->session->get('lang') : '';
if ($user !== null) {
    $lang = (string) $user['lang'];
}
if (!in_array($lang, (array) $app->config['langs'], true)) {
    $lang = (string) $app->config['default_lang'];
}
if ($lang !== $app->i18n->lang()) {
    $app->i18n = new I18n($lang);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
// Interner Pfad: bevorzugt PATH_INFO (/index.php/topics – läuft ohne Rewrite),
// sonst REQUEST_URI ohne Basispfad (/topics – läuft mit Rewrite).
$pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
if ($pathInfo !== '' && $pathInfo[0] === '/') {
    $path = $pathInfo;
} else {
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    if (STIMMWERK_BASE !== '' && str_starts_with($path, STIMMWERK_BASE)) {
        $path = substr($path, strlen(STIMMWERK_BASE));
    }
    if (str_starts_with($path, '/index.php')) {
        $path = substr($path, strlen('/index.php'));
    }
}
if ($path === '') {
    $path = '/';
}
define('STIMMWERK_PATH', $path);
$pages = new PageController($app);

try {
    $app->maintenance->tickThrottled();

    if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
        $pages->methodNotAllowed();
    }

    // Zentrale CSRF-Prüfung: ausnahmslos jede POST-Anfrage.
    if ($method === 'POST' && !$app->csrf->isValid()) {
        $app->log->security('csrf_failed', ['path' => $path]);
        $app->session->flash('error', 'flash.csrf');
        redirect('/');
    }

    // Jury-Gate: Wer eine offene Jury-Aufgabe in einer laufenden Abstimmung
    // hat, wird zuerst dorthin geführt (Abmelden/Sprache/Rechtliches bleiben
    // erreichbar).
    if ($user !== null && $app->jury->pendingDutyFor((int) $user['id']) !== null) {
        $gateAllowed = ['/jury', '/jury/vote', '/logout', '/lang', '/imprint', '/privacy'];
        if (!in_array($path, $gateAllowed, true)) {
            redirect('/jury');
        }
    }

    $get = static fn (): bool => $method === 'GET' || $method === 'HEAD';

    if ($path === '/' && $get()) {
        $pages->home();
    }
    if ($path === '/topics' && $get()) {
        (new TopicController($app))->index();
    }
    if ($path === '/topics/new' && $get()) {
        (new TopicController($app))->createForm();
    }
    if ($path === '/topics' && $method === 'POST') {
        (new TopicController($app))->create();
    }
    if (preg_match('#^/topic/(\d{1,10})$#', $path, $m) === 1 && $get()) {
        (new TopicController($app))->show((int) $m[1]);
    }
    if ($path === '/vote' && $method === 'POST') {
        (new VoteController($app))->cast();
    }
    if ($path === '/favorite' && $method === 'POST') {
        (new FavoriteController($app))->toggle();
    }
    if (preg_match('#^/report/(\d{1,10})$#', $path, $m) === 1 && $get()) {
        (new ReportController($app))->form((int) $m[1]);
    }
    if ($path === '/report' && $method === 'POST') {
        (new ReportController($app))->create();
    }
    if ($path === '/jury' && $get()) {
        (new JuryController($app))->show();
    }
    if ($path === '/jury/vote' && $method === 'POST') {
        (new JuryController($app))->vote();
    }
    if ($path === '/me' && $get()) {
        (new AccountController($app))->overview();
    }
    if ($path === '/lang' && $method === 'POST') {
        (new AccountController($app))->setLang();
    }
    if ($path === '/account/delete' && $method === 'POST') {
        (new AccountController($app))->deleteAccount();
    }
    if ($path === '/auth' && $get()) {
        (new AuthController($app))->show();
    }
    if ($path === '/auth/mock' && $method === 'POST') {
        (new AuthController($app))->mockLogin();
    }
    if ($path === '/logout' && $method === 'POST') {
        (new AuthController($app))->logout();
    }
    if ($path === '/imprint' && $get()) {
        $pages->imprint();
    }
    if ($path === '/privacy' && $get()) {
        $pages->privacy();
    }

    $pages->notFound();
} catch (Throwable $e) {
    $app->log->error('unhandled', ['type' => get_class($e), 'msg' => $e->getMessage(), 'path' => $path]);
    $pages->serverError();
}
