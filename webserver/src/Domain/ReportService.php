<?php

declare(strict_types=1);

namespace Stimmwerk\Domain;

use Stimmwerk\Core\Clock;
use Stimmwerk\Core\Database;

/**
 * Meldungen mutmaßlich rechtswidriger Inhalte.
 *
 * Ablauf: Meldung -> sofortige Auslosung der Bürger-Jury (1 % der
 * Nutzerschaft, kryptographisch sicherer Zufall) -> Abstimmung startet zur
 * nächsten Mitternacht (lokale Zeit) und läuft 24 Stunden -> Entscheidung,
 * sobald die Frist abgelaufen UND das Quorum (0,5 %) erreicht ist; sonst
 * läuft die Meldung weiter, bis das Quorum erreicht wird.
 */
final class ReportService
{
    public const CRITERIA = [
        'volksverhetzung', // § 130 StGB
        'kennzeichen',     // §§ 86, 86a StGB
        'gewalt',          // §§ 111, 126 StGB
        'terror',          // §§ 86, 129a/b StGB
        'beleidigung',     // §§ 185–187 StGB
        'bedrohung',       // § 241 StGB
        'privatdaten',     // Doxxing
        'sonstiges',
    ];

    public const FREETEXT_MAX = 1000;

    public function __construct(
        private readonly Database $db,
        private readonly array $config,
    ) {
    }

    /**
     * Erstellt eine Meldung samt Jury-Auslosung.
     *
     * @param list<string> $criteria
     * @throws \DomainException mit Übersetzungsschlüssel
     */
    public function create(int $topicId, int $reporterId, array $criteria, ?string $freetext): int
    {
        $criteria = array_values(array_unique($criteria));
        if ($criteria === [] || array_diff($criteria, self::CRITERIA) !== []) {
            throw new \DomainException('flash.invalid_input');
        }
        if ($freetext !== null && mb_strlen($freetext) > self::FREETEXT_MAX) {
            throw new \DomainException('flash.invalid_input');
        }

        $topic = $this->db->one('SELECT id, author_id, status FROM topics WHERE id = ?', [$topicId]);
        if ($topic === null || $topic['status'] !== 'active') {
            throw new \DomainException('flash.topic_not_reportable');
        }

        return $this->db->tx(function () use ($topicId, $reporterId, $criteria, $freetext, $topic): int {
            $open = $this->db->one(
                "SELECT 1 FROM reports WHERE topic_id = ? AND status IN ('pending','voting')",
                [$topicId]
            );
            if ($open !== null) {
                throw new \DomainException('flash.report_already_open');
            }
            $mine = $this->db->one(
                'SELECT 1 FROM reports WHERE topic_id = ? AND reporter_id = ?',
                [$topicId, $reporterId]
            );
            if ($mine !== null) {
                throw new \DomainException('flash.report_duplicate');
            }
            if ($this->reportsTodayBy($reporterId) >= (int) $this->config['reports_per_day']) {
                throw new \DomainException('flash.report_daily_limit');
            }

            $totalUsers = (int) $this->db->val('SELECT COUNT(*) FROM users WHERE is_system = 0');
            $jurors = $this->drawJury($reporterId, (int) $topic['author_id'], $totalUsers);
            if ($jurors === []) {
                throw new \DomainException('flash.report_too_few_users');
            }
            $quorum = min(
                count($jurors),
                max((int) $this->config['quorum_min'], (int) ceil($totalUsers * (float) $this->config['quorum_share']))
            );

            $this->db->run(
                'INSERT INTO reports (topic_id, reporter_id, criteria, freetext, status,
                                      jury_size, quorum, created_at, voting_starts_at)
                 VALUES (?, ?, ?, ?, \'pending\', ?, ?, ?, ?)',
                [
                    $topicId,
                    $reporterId,
                    json_encode($criteria, JSON_THROW_ON_ERROR),
                    $freetext,
                    count($jurors),
                    $quorum,
                    Clock::nowStr(),
                    Clock::nextLocalMidnightUtcStr(),
                ]
            );
            $reportId = $this->db->lastInsertId();
            foreach ($jurors as $jurorId) {
                $this->db->run(
                    'INSERT INTO report_jurors (report_id, user_id) VALUES (?, ?)',
                    [$reportId, $jurorId]
                );
            }
            return $reportId;
        });
    }

    /**
     * Lost die Jury aus: 1 % der Nutzerschaft (mindestens jury_min).
     * Ausgeschlossen: Melder, Themen-Autor, aktive Juroren offener Meldungen,
     * Pseudonyme in der 3-Tage-Karenz. Auswahl per Fisher-Yates mit
     * random_int (CSPRNG) – bewusst nicht mit SQL-RANDOM().
     *
     * @return list<int>
     */
    private function drawJury(int $reporterId, int $authorId, int $totalUsers): array
    {
        $eligible = $this->db->all(
            "SELECT u.id FROM users u
             WHERE u.is_system = 0
               AND u.id NOT IN (?, ?)
               AND (u.jury_cooldown_until IS NULL OR u.jury_cooldown_until <= ?)
               AND u.id NOT IN (
                   SELECT rj.user_id FROM report_jurors rj
                   JOIN reports r ON r.id = rj.report_id
                   WHERE r.status IN ('pending','voting')
               )",
            [$reporterId, $authorId, Clock::nowStr()]
        );
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $eligible);
        $target = max((int) $this->config['jury_min'], (int) ceil($totalUsers * (float) $this->config['jury_share']));
        $count = count($ids);
        if ($count <= $target) {
            return $ids;
        }
        for ($i = $count - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        }
        return array_slice($ids, 0, $target);
    }

    private function reportsTodayBy(int $reporterId): int
    {
        $dayEnd = Clock::nextLocalMidnightUtcStr();
        $dayStart = Clock::addDaysStr($dayEnd, -1);
        return (int) $this->db->val(
            'SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND created_at >= ? AND created_at < ?',
            [$reporterId, $dayStart, $dayEnd]
        );
    }

    public function openReportForTopic(int $topicId): ?array
    {
        return $this->db->one(
            "SELECT * FROM reports WHERE topic_id = ? AND status IN ('pending','voting')",
            [$topicId]
        );
    }

    /** @return array<int,array> Meldungen eines Melders (für „Meine Übersicht“). */
    public function byReporter(int $userId): array
    {
        return $this->db->all(
            'SELECT r.id, r.status, r.created_at, r.decided_at, t.id AS topic_id, t.title
             FROM reports r JOIN topics t ON t.id = r.topic_id
             WHERE r.reporter_id = ?
             ORDER BY r.created_at DESC',
            [$userId]
        );
    }

    /** @return list<string> */
    public static function decodeCriteria(string $json): array
    {
        $list = json_decode($json, true);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
