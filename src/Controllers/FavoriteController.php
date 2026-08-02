<?php

declare(strict_types=1);

namespace Stimmwerk\Controllers;

final class FavoriteController extends BaseController
{
    public function toggle(): never
    {
        $user = $this->requireUser();
        $kind = post_str('kind', 10);
        $ref = post_str('ref', 100);
        $back = $this->safeReturnPath();
        $this->rateLimitOrRedirect('favorite:' . (int) $user['id'], 60, 600, $back);
        try {
            $added = $this->app->favorites->toggle((int) $user['id'], $kind, $ref);
        } catch (\DomainException $e) {
            $this->app->session->flash('error', $e->getMessage());
            redirect($back);
        }
        $this->app->session->flash('success', $added ? 'flash.favorite_added' : 'flash.favorite_removed');
        redirect($back);
    }

    /** Rücksprungziel: nur interne Pfade aus einer engen Whitelist. */
    private function safeReturnPath(): string
    {
        $return = post_str('return', 200);
        if (preg_match('#^/(topics(\?[A-Za-z0-9=&%._\-]*)?|topic/\d{1,10}|me)$#', $return) === 1) {
            return $return;
        }
        return '/topics';
    }
}
