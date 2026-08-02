<?php

declare(strict_types=1);

namespace Stimmwerk\Controllers;

use Stimmwerk\Domain\TopicService;

final class TopicController extends BaseController
{
    public function index(): never
    {
        $filters = [
            'category' => query_str('category', 64),
            'level'    => in_array(query_str('level', 20), TopicService::SCOPE_LEVELS, true)
                ? query_str('level', 20) : '',
            'scope'    => query_str('scope', TopicService::SCOPE_NAME_MAX),
            'q'        => query_str('q', 80),
            'sort'     => query_str('sort', 10) === 'top' ? 'top' : 'new',
        ];
        $page = query_int('page', 1, 500, 1);
        $perPage = (int) $this->app->config['page_size'];
        $result = $this->app->topics->list($filters, $page, $perPage);
        $this->app->view->render('topics', [
            'title'      => $this->app->i18n->t('topics.title'),
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'page'       => $page,
            'pages'      => max(1, (int) ceil($result['total'] / $perPage)),
            'filters'    => $filters,
            'categories' => $this->app->topics->categories(),
        ]);
    }

    public function show(int $id): never
    {
        $topic = $this->app->topics->find($id);
        if ($topic === null) {
            (new PageController($this->app))->notFound();
        }
        $userId = $this->app->auth->userId();
        $scopeRef = $topic['scope_level'] === 'bund'
            ? 'bund'
            : $topic['scope_level'] . ':' . (string) $topic['scope_name'];
        $this->app->view->render('topic', [
            'title'      => (string) $topic['title'],
            'topic'      => $topic,
            'myVote'     => $userId === null ? null : $this->app->topics->userVote($id, $userId),
            'openReport' => $this->app->reports->openReportForTopic($id),
            'isCatFav'   => $userId !== null
                && $this->app->favorites->isFavorite($userId, 'category', (string) $topic['category_slug']),
            'scopeRef'   => $scopeRef,
            'isScopeFav' => $userId !== null
                && $this->app->favorites->isFavorite($userId, 'scope', $scopeRef),
        ]);
    }

    public function createForm(): never
    {
        $user = $this->requireUser();
        $this->renderForm($user, [], [
            'title' => '', 'goal' => '', 'reasoning' => '',
            'category_id' => 0, 'scope_level' => 'bund', 'scope_name' => '',
        ]);
    }

    public function create(): never
    {
        $user = $this->requireUser();
        $userId = (int) $user['id'];
        $this->rateLimitOrRedirect('topic-form:' . $userId, 10, 600, '/topics/new');

        $old = [
            'title'       => post_str('title', TopicService::TITLE_MAX),
            'goal'        => post_str('goal', TopicService::GOAL_MAX, true),
            'reasoning'   => post_str('reasoning', TopicService::REASONING_MAX, true),
            'category_id' => post_int('category_id') ?? 0,
            'scope_level' => post_str('scope_level', 20),
            'scope_name'  => post_str('scope_name', TopicService::SCOPE_NAME_MAX),
        ];

        $errors = [];
        if (mb_strlen($old['title']) < TopicService::TITLE_MIN) {
            $errors[] = 'topic.err_title';
        }
        if (mb_strlen($old['goal']) < TopicService::GOAL_MIN) {
            $errors[] = 'topic.err_goal';
        }
        if (mb_strlen($old['reasoning']) < TopicService::REASONING_MIN) {
            $errors[] = 'topic.err_reasoning';
        }
        $category = null;
        if ($old['category_id'] > 0) {
            $category = $this->app->db->one('SELECT id FROM categories WHERE id = ?', [$old['category_id']]);
        }
        if ($category === null) {
            $errors[] = 'topic.err_category';
        }
        if (!in_array($old['scope_level'], TopicService::SCOPE_LEVELS, true)) {
            $errors[] = 'topic.err_scope';
        } elseif ($old['scope_level'] === 'bund') {
            $old['scope_name'] = '';
        } elseif (
            mb_strlen($old['scope_name']) < 2
            || preg_match('/^[\p{L}0-9 .\-()]+$/u', $old['scope_name']) !== 1
        ) {
            $errors[] = 'topic.err_scope_name';
        }

        if ($errors !== []) {
            $this->renderForm($user, $errors, $old);
        }

        try {
            $topicId = $this->app->topics->create(
                $userId,
                $old['title'],
                $old['goal'],
                $old['reasoning'],
                (int) $old['category_id'],
                $old['scope_level'],
                $old['scope_level'] === 'bund' ? null : $old['scope_name'],
            );
        } catch (\DomainException $e) {
            $this->renderForm($user, [$e->getMessage()], $old);
        }
        $this->app->session->flash('success', 'flash.topic_created');
        redirect('/topic/' . $topicId);
    }

    /** @param list<string> $errors @param array<string,mixed> $old */
    private function renderForm(array $user, array $errors, array $old): never
    {
        $this->app->view->render('topic_new', [
            'title'       => $this->app->i18n->t('topic.new_title'),
            'categories'  => $this->app->topics->categories(),
            'errors'      => $errors,
            'old'         => $old,
            'postedToday' => $this->app->topics->hasPostedToday((int) $user['id']),
        ]);
    }
}
