<?php

declare(strict_types=1);

namespace Stimmwerk\Controllers;

use Stimmwerk\App;

abstract class BaseController
{
    public function __construct(protected readonly App $app)
    {
    }

    /** Liefert den angemeldeten Nutzer oder leitet zur Anmeldung um. */
    protected function requireUser(): array
    {
        $user = $this->app->auth->user();
        if ($user === null) {
            $this->app->session->flash('info', 'flash.login_required');
            redirect('/auth');
        }
        return $user;
    }

    /** Wendet ein Rate-Limit an; bei Überschreitung Flash + Redirect. */
    protected function rateLimitOrRedirect(string $key, int $max, int $windowSeconds, string $backTo): void
    {
        if (!$this->app->rateLimiter->allow($key, $max, $windowSeconds)) {
            $this->app->log->security('rate_limit', ['key' => $key]);
            $this->app->session->flash('error', 'flash.rate_limited');
            redirect($backTo);
        }
    }
}
