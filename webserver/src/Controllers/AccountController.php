<?php

declare(strict_types=1);

namespace Stimmwerk\Controllers;

use Stimmwerk\Auth\Auth;

final class AccountController extends BaseController
{
    public function overview(): never
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $this->app->view->render('me', [
            'title'     => $this->app->i18n->t('me.title'),
            'user'      => $user,
            'shortId'   => Auth::shortId($user),
            'voted'     => $this->app->topics->votedByUser($userId),
            'authored'  => $this->app->topics->byAuthor($userId),
            'favorites' => $this->app->favorites->listFor($userId),
            'myReports' => $this->app->reports->byReporter($userId),
            'duty'      => $this->app->jury->pendingDutyFor($userId),
            'upcoming'  => $this->app->jury->upcomingDutyFor($userId),
        ]);
    }

    /** Sprachwahl – wird in der Sitzung und (falls angemeldet) am Pseudonym gespeichert. */
    public function setLang(): never
    {
        $lang = post_str('lang', 5);
        if (!in_array($lang, (array) $this->app->config['langs'], true)) {
            redirect('/');
        }
        $this->app->session->set('lang', $lang);
        $userId = $this->app->auth->userId();
        if ($userId !== null) {
            $this->app->auth->setLang($userId, $lang);
        }
        redirect($this->safeReturnPath());
    }

    public function deleteAccount(): never
    {
        $user = $this->requireUser();
        if (post_str('confirm', 10) !== 'yes') {
            $this->app->session->flash('error', 'flash.delete_not_confirmed');
            redirect('/me');
        }
        $this->app->account->deleteAccount((int) $user['id']);
        $this->app->auth->logout();
        $this->app->session->flash('success', 'flash.account_deleted');
        redirect('/');
    }

    private function safeReturnPath(): string
    {
        $return = post_str('return', 200);
        if (preg_match('#^/(topics(\?[A-Za-z0-9=&%._\-]*)?|topic/\d{1,10}|topics/new|me|jury|auth|imprint|privacy)?$#', $return) === 1) {
            return $return === '' ? '/' : $return;
        }
        return '/';
    }
}
