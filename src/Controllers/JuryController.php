<?php

declare(strict_types=1);

namespace Stimmwerk\Controllers;

use Stimmwerk\Domain\ReportService;

final class JuryController extends BaseController
{
    public function show(): never
    {
        $user = $this->requireUser();
        $duty = $this->app->jury->pendingDutyFor((int) $user['id']);
        $upcoming = $duty === null ? $this->app->jury->upcomingDutyFor((int) $user['id']) : null;
        $this->app->view->render('jury', [
            'title'    => $this->app->i18n->t('jury.title'),
            'duty'     => $duty,
            'upcoming' => $upcoming,
            'tally'    => $duty === null ? null : $this->app->jury->tally((int) $duty['id']),
            'deadline' => $duty === null ? null : $this->app->jury->deadline($duty),
            'criteria' => $duty === null ? [] : ReportService::decodeCriteria((string) $duty['criteria']),
        ]);
    }

    public function vote(): never
    {
        $user = $this->requireUser();
        $reportId = post_int('report_id');
        $vote = post_str('vote', 10);
        if ($reportId === null) {
            redirect('/jury');
        }
        $this->rateLimitOrRedirect('jury:' . (int) $user['id'], 30, 600, '/jury');
        try {
            $this->app->jury->castVote($reportId, (int) $user['id'], $vote);
        } catch (\DomainException $e) {
            $this->app->session->flash('error', $e->getMessage());
            redirect('/jury');
        }
        $this->app->session->flash('success', 'flash.jury_voted');
        redirect('/jury');
    }
}
