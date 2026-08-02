<?php

declare(strict_types=1);

namespace Stimmwerk\Controllers;

use Stimmwerk\Eid\MockEidProvider;

final class AuthController extends BaseController
{
    public function show(): never
    {
        if ($this->app->auth->user() !== null) {
            redirect('/me');
        }
        $this->app->view->render('auth', [
            'title'  => $this->app->i18n->t('auth.title'),
            'isMock' => $this->app->eid->isMock(),
        ]);
    }

    /** Simulierte Ausweisprüfung (nur Mock-Modus). */
    public function mockLogin(): never
    {
        if (!$this->app->eid->isMock()) {
            $this->app->session->flash('error', 'flash.eid_not_configured');
            redirect('/auth');
        }
        $this->rateLimitOrRedirect('auth:' . $this->app->auth->ipKey(), 10, 600, '/auth');

        $generated = null;
        if (($_POST['action'] ?? '') === 'generate') {
            $generated = MockEidProvider::generateSecret();
            $secret = $generated;
        } else {
            $secret = post_str('card_secret', 128);
        }

        $pseudonym = $this->app->eid->completeAuth(['card_secret' => $secret]);
        if ($pseudonym === null) {
            $this->app->log->security('auth_failed', ['ip' => $this->app->auth->ipKey()]);
            $this->app->session->flash('error', 'flash.auth_failed');
            redirect('/auth');
        }

        $this->app->auth->loginWithPseudonym($pseudonym);
        if ($generated !== null) {
            $this->app->session->flash('success', 'flash.auth_generated', ['secret' => $generated]);
        } else {
            $this->app->session->flash('success', 'flash.auth_ok');
        }
        redirect('/me');
    }

    public function logout(): never
    {
        $this->app->auth->logout();
        $this->app->session->flash('info', 'flash.logged_out');
        redirect('/');
    }
}
