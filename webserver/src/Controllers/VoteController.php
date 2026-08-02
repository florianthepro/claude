<?php

declare(strict_types=1);

namespace Stimmwerk\Controllers;

final class VoteController extends BaseController
{
    public function cast(): never
    {
        $user = $this->requireUser();
        $topicId = post_int('topic_id');
        $choice = post_str('choice', 10);
        if ($topicId === null) {
            redirect('/topics');
        }
        $back = '/topic/' . $topicId;
        $this->rateLimitOrRedirect('vote:' . (int) $user['id'], 60, 600, $back);
        try {
            $this->app->votes->cast((int) $user['id'], $topicId, $choice);
        } catch (\DomainException $e) {
            $this->app->session->flash('error', $e->getMessage());
            redirect($back);
        }
        $this->app->session->flash('success', $choice === 'none' ? 'flash.vote_withdrawn' : 'flash.vote_saved');
        redirect($back);
    }
}
