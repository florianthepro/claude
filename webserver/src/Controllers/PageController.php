<?php

declare(strict_types=1);

namespace Stimmwerk\Controllers;

final class PageController extends BaseController
{
    public function home(): never
    {
        $stats = $this->app->topics->stats();
        $latest = $this->app->topics->list([], 1, 6)['rows'];
        $this->app->view->render('home', [
            'title'  => $this->app->i18n->t('app.tagline'),
            'stats'  => $stats,
            'latest' => $latest,
        ]);
    }

    public function imprint(): never
    {
        $this->app->view->render('static_imprint', [
            'title' => $this->app->i18n->t('footer.imprint'),
        ]);
    }

    public function privacy(): never
    {
        $this->app->view->render('static_privacy', [
            'title' => $this->app->i18n->t('footer.privacy'),
        ]);
    }

    public function notFound(): never
    {
        $this->app->view->render('error', [
            'title'   => $this->app->i18n->t('error.not_found_title'),
            'message' => $this->app->i18n->t('error.not_found'),
        ], 404);
    }

    public function methodNotAllowed(): never
    {
        $this->app->view->render('error', [
            'title'   => $this->app->i18n->t('error.generic_title'),
            'message' => $this->app->i18n->t('error.method'),
        ], 405);
    }

    public function serverError(): never
    {
        $this->app->view->render('error', [
            'title'   => $this->app->i18n->t('error.generic_title'),
            'message' => $this->app->i18n->t('error.generic'),
        ], 500);
    }
}
