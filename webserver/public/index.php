<?php

declare(strict_types=1);

/**
 * Front-Controller: Sicherheitsheader, Session, Sprachauflösung, zentrale
 * CSRF-Prüfung für alle POST-Anfragen, Jury-Mitwirkungs-Gate, Routing.
 */

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

$factory = require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $app = $factory();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Service nicht verfuegbar.\n";
    error_log('bootstrap: ' . $e->getMessage());
    exit;
}

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
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
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
    $app->log->error('unhandled', ['type' => $e::class, 'msg' => $e->getMessage(), 'path' => $path]);
    $pages->serverError();
}
