<?php

declare(strict_types=1);

/**
 * Baut die Anwendung zusammen: Konfiguration, Autoloader, Fehlerbehandlung,
 * Datenbank (inkl. Erststart-Migration und Grunddaten), Dienste.
 * Gibt den fertigen App-Container zurück. Für Web-Requests ruft public/index.php
 * zusätzlich Session, Sicherheitsheader und Wartungslauf auf.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Stimmwerk\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Stimmwerk\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';

use Stimmwerk\App;
use Stimmwerk\Auth\Auth;
use Stimmwerk\Core\Clock;
use Stimmwerk\Core\Csrf;
use Stimmwerk\Core\Database;
use Stimmwerk\Core\Logger;
use Stimmwerk\Core\RateLimiter;
use Stimmwerk\Core\Secrets;
use Stimmwerk\Core\Session;
use Stimmwerk\Domain\AccountService;
use Stimmwerk\Domain\FavoriteService;
use Stimmwerk\Domain\JuryService;
use Stimmwerk\Domain\Maintenance;
use Stimmwerk\Domain\ReportService;
use Stimmwerk\Domain\Seeder;
use Stimmwerk\Domain\TopicService;
use Stimmwerk\Domain\VoteService;
use Stimmwerk\Eid\MockEidProvider;
use Stimmwerk\Eid\Tr03130Provider;
use Stimmwerk\I18n\I18n;
use Stimmwerk\View\View;

return static function (string $lang = ''): App {
    $config = require dirname(__DIR__) . '/config/config.php';
    Clock::setTimezone((string) $config['timezone']);

    $db = new Database((string) $config['db_path']);
    $db->migrate(dirname(__DIR__) . '/db/schema.sql');
    (new Seeder($db))->baselineIfEmpty();
    ini_set('error_log', $config['data_dir'] . '/php-error.log');

    $secrets = new Secrets((string) $config['data_dir']);
    $log = new Logger((string) $config['data_dir']);
    $session = new Session((int) $config['session_idle_minutes'], (int) $config['session_max_hours']);
    $rateLimiter = new RateLimiter($db);
    $auth = new Auth($db, $session, $secrets, (string) $config['default_lang']);

    $eid = match ((string) $config['eid_provider']) {
        'mock'    => new MockEidProvider(),
        'tr03130' => new Tr03130Provider(),
        default   => throw new RuntimeException('Unbekannter eid_provider.'),
    };

    if (!in_array($lang, (array) $config['langs'], true)) {
        $lang = (string) $config['default_lang'];
    }
    $i18n = new I18n($lang);

    $topics = new TopicService($db);
    $jury = new JuryService($db, $config);
    $view = new View();

    $app = new App(
        config: $config,
        db: $db,
        session: $session,
        csrf: new Csrf(),
        secrets: $secrets,
        log: $log,
        rateLimiter: $rateLimiter,
        eid: $eid,
        auth: $auth,
        i18n: $i18n,
        topics: $topics,
        votes: new VoteService($db),
        favorites: new FavoriteService($db),
        reports: new ReportService($db, $config),
        jury: $jury,
        maintenance: new Maintenance($db, $jury, $rateLimiter),
        account: new AccountService($db),
        view: $view,
    );
    $view->setApp($app);
    return $app;
};
