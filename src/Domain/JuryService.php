<?php

declare(strict_types=1);

namespace Stimmwerk\Domain;

use Stimmwerk\Core\Clock;
use Stimmwerk\Core\Database;

/**
 * Bürger-Jury: Mitwirkungspflicht beim Sitzungsbeginn, Stimmabgabe,
 * Zustandsübergänge (pending -> voting -> decided_*) und Karenzzeiten.
 */
final class JuryService
{
    public const VOTES = ['confirm', 'reject', 'neutral'];

    public function __construct(
        private readonly Database $db,
        private readonly array $config,
    ) {
    }

    /**
     * Offene Jury-Aufgabe eines Nutzers in einer LAUFENDEN Abstimmung –
     * solange sie existiert, führt die Anwendung den Nutzer zuerst hierhin.
     */
    public function pendingDutyFor(int $userId): ?array
    {
        return $this->db->one(
            "SELECT r.*, t.title, t.goal, t.reasoning, t.status AS topic_status,
                    c.slug AS category_slug, c.name_de, c.name_en
             FROM report_jurors rj
             JOIN reports r    ON r.id = rj.report_id
             JOIN topics t     ON t.id = r.topic_id
             JOIN categories c ON c.id = t.category_id
             WHERE rj.user_id = ? AND rj.vote IS NULL AND r.status = 'voting'
             ORDER BY r.voting_starts_at
             LIMIT 1",
            [$userId]
        );
    }

    /** Zuteilung, deren Abstimmung noch nicht begonnen hat (Info, keine Sperre). */
    public function upcomingDutyFor(int $userId): ?array
    {
        return $this->db->one(
            "SELECT r.id, r.voting_starts_at
             FROM report_jurors rj JOIN reports r ON r.id = rj.report_id
             WHERE rj.user_id = ? AND rj.vote IS NULL AND r.status = 'pending'
             ORDER BY r.voting_starts_at LIMIT 1",
            [$userId]
        );
    }

    /** @throws \DomainException mit Übersetzungsschlüssel */
    public function castVote(int $reportId, int $userId, string $vote): void
    {
        if (!in_array($vote, self::VOTES, true)) {
            throw new \DomainException('flash.invalid_input');
        }
        $this->db->tx(function () use ($reportId, $userId, $vote): void {
            $report = $this->db->one('SELECT * FROM reports WHERE id = ?', [$reportId]);
            if ($report === null || $report['status'] !== 'voting') {
                throw new \DomainException('flash.jury_not_open');
            }
            $seat = $this->db->one(
                'SELECT vote FROM report_jurors WHERE report_id = ? AND user_id = ?',
                [$reportId, $userId]
            );
            if ($seat === null) {
                throw new \DomainException('flash.jury_not_member');
            }
            if ($seat['vote'] !== null) {
                throw new \DomainException('flash.jury_already_voted');
            }
            $this->db->run(
                'UPDATE report_jurors SET vote = ?, voted_at = ? WHERE report_id = ? AND user_id = ?',
                [$vote, Clock::nowStr(), $reportId, $userId]
            );
            $this->decideIfDue($report);
        });
    }

    /**
     * Idempotenter Wartungslauf: startet fällige Abstimmungen (00:00) und
     * entscheidet fällige Meldungen (Frist abgelaufen + Quorum erreicht).
     */
    public function processDue(): void
    {
        $now = Clock::nowStr();
        $this->db->run(
            "UPDATE reports SET status = 'voting' WHERE status = 'pending' AND voting_starts_at <= ?",
            [$now]
        );
        $due = $this->db->all("SELECT * FROM reports WHERE status = 'voting'");
        foreach ($due as $report) {
            $this->db->tx(fn () => $this->decideIfDue($report));
        }
    }

    /** Zählt Stimmen und entscheidet, wenn Frist und Quorum erfüllt sind. */
    private function decideIfDue(array $report): void
    {
        $deadline = Clock::addHoursStr(
            (string) $report['voting_starts_at'],
            (int) $this->config['report_vote_hours']
        );
        if (Clock::nowStr() < $deadline) {
            return;
        }
        $tally = $this->tally((int) $report['id']);
        // Falls Sitze durch Kontolöschungen entfallen sind, bleibt das Quorum erreichbar.
        $quorumEffective = min((int) $report['quorum'], $tally['seats']);
        if ($tally['cast'] < $quorumEffective) {
            return;
        }
        $removed = $tally['confirm'] > $tally['reject'];
        $now = Clock::nowStr();
        $this->db->run(
            'UPDATE reports SET status = ?, decided_at = ? WHERE id = ?',
            [$removed ? 'decided_removed' : 'decided_kept', $now, (int) $report['id']]
        );
        if ($removed) {
            $this->db->run(
                "UPDATE topics SET status = 'removed' WHERE id = ?",
                [(int) $report['topic_id']]
            );
        }
        // Karenz: Mitglieder dieser Jury sind erst nach N Tagen wieder losbar.
        $cooldownUntil = Clock::addDaysStr($now, (int) $this->config['jury_cooldown_days']);
        $this->db->run(
            'UPDATE users SET jury_cooldown_until = ?
             WHERE id IN (SELECT user_id FROM report_jurors WHERE report_id = ?)',
            [$cooldownUntil, (int) $report['id']]
        );
    }

    /** @return array{seats:int,cast:int,confirm:int,reject:int,neutral:int} */
    public function tally(int $reportId): array
    {
        $row = $this->db->one(
            "SELECT COUNT(*) AS seats,
                    COUNT(vote) AS cast,
                    SUM(CASE WHEN vote = 'confirm' THEN 1 ELSE 0 END) AS confirm,
                    SUM(CASE WHEN vote = 'reject'  THEN 1 ELSE 0 END) AS reject,
                    SUM(CASE WHEN vote = 'neutral' THEN 1 ELSE 0 END) AS neutral
             FROM report_jurors WHERE report_id = ?",
            [$reportId]
        );
        return [
            'seats'   => (int) ($row['seats'] ?? 0),
            'cast'    => (int) ($row['cast'] ?? 0),
            'confirm' => (int) ($row['confirm'] ?? 0),
            'reject'  => (int) ($row['reject'] ?? 0),
            'neutral' => (int) ($row['neutral'] ?? 0),
        ];
    }

    public function deadline(array $report): string
    {
        return Clock::addHoursStr(
            (string) $report['voting_starts_at'],
            (int) $this->config['report_vote_hours']
        );
    }
}
