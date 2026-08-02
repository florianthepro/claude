<?php

declare(strict_types=1);

namespace Stimmwerk\Controllers;

use Stimmwerk\Domain\ReportService;

final class ReportController extends BaseController
{
    public function form(int $topicId): never
    {
        $this->requireUser();
        $topic = $this->app->topics->find($topicId);
        if ($topic === null || $topic['status'] !== 'active') {
            (new PageController($this->app))->notFound();
        }
        if ($this->app->reports->openReportForTopic($topicId) !== null) {
            $this->app->session->flash('info', 'flash.report_already_open');
            redirect('/topic/' . $topicId);
        }
        $this->app->view->render('report_new', [
            'title'    => $this->app->i18n->t('report.title'),
            'topic'    => $topic,
            'criteria' => ReportService::CRITERIA,
        ]);
    }

    public function create(): never
    {
        $user = $this->requireUser();
        $topicId = post_int('topic_id');
        if ($topicId === null) {
            redirect('/topics');
        }
        $back = '/report/' . $topicId;
        $this->rateLimitOrRedirect('report-form:' . (int) $user['id'], 10, 600, $back);

        $criteria = post_str_list('criteria', ReportService::CRITERIA);
        if ($criteria === []) {
            $this->app->session->flash('error', 'flash.report_no_criteria');
            redirect($back);
        }
        $freetext = post_str('freetext', ReportService::FREETEXT_MAX, true);

        try {
            $this->app->reports->create(
                $topicId,
                (int) $user['id'],
                $criteria,
                $freetext === '' ? null : $freetext,
            );
        } catch (\DomainException $e) {
            $this->app->session->flash('error', $e->getMessage());
            redirect('/topic/' . $topicId);
        }
        $this->app->log->security('report_created', ['topic' => $topicId]);
        $this->app->session->flash('success', 'flash.report_created');
        redirect('/topic/' . $topicId);
    }
}
