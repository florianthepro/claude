<?php

declare(strict_types=1);

namespace Stimmwerk\Domain;

use Stimmwerk\Core\Clock;
use Stimmwerk\Core\Database;

/**
 * Themen: Erstellen (1 pro Tag und Pseudonym), Listen mit Filtern,
 * Detailansicht mit Stimmenständen.
 */
final class TopicService
{
    public const SCOPE_LEVELS = ['kommune', 'landkreis', 'bundesland', 'bund'];

    public const TITLE_MIN = 8;
    public const TITLE_MAX = 120;
    public const GOAL_MIN = 10;
    public const GOAL_MAX = 500;
    public const REASONING_MIN = 10;
    public const REASONING_MAX = 4000;
    public const SCOPE_NAME_MAX = 80;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<int,array> */
    public function categories(): array
    {
        return $this->db->all('SELECT * FROM categories ORDER BY sort_order, id');
    }

    public function categoryBySlug(string $slug): ?array
    {
        return $this->db->one('SELECT * FROM categories WHERE slug = ?', [$slug]);
    }

    /** true, wenn das Pseudonym heute (lokale Zeit) bereits ein Thema erstellt hat. */
    public function hasPostedToday(int $userId): bool
    {
        return null !== $this->db->one(
            'SELECT 1 FROM topics WHERE author_id = ? AND created_date = ?',
            [$userId, Clock::localDate()]
        );
    }

    /**
     * Erstellt ein Thema. Werte sind vorab validiert (Controller); die
     * Tagesgrenze wird hier UND per DB-Constraint durchgesetzt.
     *
     * @throws \DomainException mit Übersetzungsschlüssel
     */
    public function create(
        int $userId,
        string $title,
        string $goal,
        string $reasoning,
        int $categoryId,
        string $scopeLevel,
        ?string $scopeName,
    ): int {
        if ($this->hasPostedToday($userId)) {
            throw new \DomainException('flash.topic_daily_limit');
        }
        try {
            $this->db->run(
                'INSERT INTO topics (author_id, title, goal, reasoning, category_id,
                                     scope_level, scope_name, created_at, created_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $title, $goal, $reasoning, $categoryId,
                 $scopeLevel, $scopeName, Clock::nowStr(), Clock::localDate()]
            );
        } catch (\PDOException $e) {
            // UNIQUE(author_id, created_date) – Wettlauf zweier paralleler Anfragen
            throw new \DomainException('flash.topic_daily_limit');
        }
        return $this->db->lastInsertId();
    }

    private const LIST_SELECT = "
        SELECT t.*, c.slug AS category_slug, c.name_de, c.name_en,
               u.is_system AS author_is_system,
               (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'for')     AS votes_for,
               (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'against') AS votes_against
        FROM topics t
        JOIN categories c ON c.id = t.category_id
        JOIN users u      ON u.id = t.author_id";

    /**
     * @param array{category?:string,level?:string,scope?:string,sort?:string,q?:string} $filters
     * @return array{rows:array<int,array>,total:int}
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        $where = ["t.status = 'active'"];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = 'c.slug = :cat';
            $params[':cat'] = $filters['category'];
        }
        if (!empty($filters['level'])) {
            $where[] = 't.scope_level = :level';
            $params[':level'] = $filters['level'];
        }
        if (!empty($filters['scope'])) {
            $where[] = 't.scope_name = :scope';
            $params[':scope'] = $filters['scope'];
        }
        if (!empty($filters['q'])) {
            $where[] = "t.title LIKE :q ESCAPE '\\'";
            $params[':q'] = '%' . addcslashes($filters['q'], '%_\\') . '%';
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->val(
            'SELECT COUNT(*) FROM topics t JOIN categories c ON c.id = t.category_id' . $whereSql,
            $params
        );

        $order = ($filters['sort'] ?? 'new') === 'top'
            ? ' ORDER BY (votes_for + votes_against) DESC, t.created_at DESC'
            : ' ORDER BY t.created_at DESC';

        $params[':limit'] = $perPage;
        $params[':offset'] = max(0, ($page - 1) * $perPage);
        $rows = $this->db->all(
            self::LIST_SELECT . $whereSql . $order . ' LIMIT :limit OFFSET :offset',
            $params
        );
        return ['rows' => $rows, 'total' => $total];
    }

    public function find(int $id): ?array
    {
        return $this->db->one(self::LIST_SELECT . ' WHERE t.id = ?', [$id]);
    }

    public function userVote(int $topicId, int $userId): ?string
    {
        $v = $this->db->val('SELECT choice FROM votes WHERE topic_id = ? AND user_id = ?', [$topicId, $userId]);
        return $v === null ? null : (string) $v;
    }

    /** @return array<int,array> Themen eines Autors (neueste zuerst). */
    public function byAuthor(int $userId): array
    {
        return $this->db->all(
            self::LIST_SELECT . ' WHERE t.author_id = ? ORDER BY t.created_at DESC',
            [$userId]
        );
    }

    /** @return array<int,array> Themen, für die der Nutzer gestimmt hat, mit eigener Stimme. */
    public function votedByUser(int $userId): array
    {
        return $this->db->all(
            "SELECT t.id, t.title, t.status, c.slug AS category_slug, c.name_de, c.name_en,
                    v.choice AS my_choice, v.updated_at AS voted_at,
                    (SELECT COUNT(*) FROM votes x WHERE x.topic_id = t.id AND x.choice = 'for')     AS votes_for,
                    (SELECT COUNT(*) FROM votes x WHERE x.topic_id = t.id AND x.choice = 'against') AS votes_against
             FROM votes v
             JOIN topics t     ON t.id = v.topic_id
             JOIN categories c ON c.id = t.category_id
             WHERE v.user_id = ?
             ORDER BY v.updated_at DESC",
            [$userId]
        );
    }

    public function stats(): array
    {
        return [
            'topics' => (int) $this->db->val("SELECT COUNT(*) FROM topics WHERE status = 'active'"),
            'votes'  => (int) $this->db->val('SELECT COUNT(*) FROM votes'),
            'users'  => (int) $this->db->val('SELECT COUNT(*) FROM users WHERE is_system = 0'),
        ];
    }
}
