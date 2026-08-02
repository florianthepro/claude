<?php

declare(strict_types=1);

namespace Stimmwerk;

use Stimmwerk\Auth\Auth;
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
use Stimmwerk\Domain\TopicService;
use Stimmwerk\Domain\VoteService;
use Stimmwerk\Eid\EidProvider;
use Stimmwerk\I18n\I18n;
use Stimmwerk\View\View;

/** Zentraler Anwendungscontainer – alle Abhängigkeiten sind explizit. */
final class App
{
    public function __construct(
        public readonly array $config,
        public readonly Database $db,
        public readonly Session $session,
        public readonly Csrf $csrf,
        public readonly Secrets $secrets,
        public readonly Logger $log,
        public readonly RateLimiter $rateLimiter,
        public readonly EidProvider $eid,
        public readonly Auth $auth,
        // Bewusst nicht readonly: die Sprache wird erst nach Sessionstart
        // aufgelöst (Nutzerkonto -> Session -> Standard).
        public I18n $i18n,
        public readonly TopicService $topics,
        public readonly VoteService $votes,
        public readonly FavoriteService $favorites,
        public readonly ReportService $reports,
        public readonly JuryService $jury,
        public readonly Maintenance $maintenance,
        public readonly AccountService $account,
        public readonly View $view,
    ) {
    }
}
