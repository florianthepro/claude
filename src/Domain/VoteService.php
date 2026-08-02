<?php

declare(strict_types=1);

namespace Stimmwerk\Domain;

use Stimmwerk\Core\Clock;
use Stimmwerk\Core\Database;

/**
 * Stimmen: dafür/dagegen, Enthaltung = keine Stimme ('none' zieht die eigene
 * Stimme zurück). Eine Stimme je Thema und Pseudonym (DB-erzwungen).
 */
final class VoteService
{
    public const CHOICES = ['for', 'against', 'none'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @throws \DomainException mit Übersetzungsschlüssel */
    public function cast(int $userId, int $topicId, string $choice): void
    {
        if (!in_array($choice, self::CHOICES, true)) {
            throw new \DomainException('flash.invalid_input');
        }
        $status = $this->db->val('SELECT status FROM topics WHERE id = ?', [$topicId]);
        if ($status !== 'active') {
            throw new \DomainException('flash.topic_not_votable');
        }
        if ($choice === 'none') {
            $this->db->run('DELETE FROM votes WHERE topic_id = ? AND user_id = ?', [$topicId, $userId]);
            return;
        }
        $now = Clock::nowStr();
        $this->db->run(
            'INSERT INTO votes (topic_id, user_id, choice, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(topic_id, user_id)
             DO UPDATE SET choice = excluded.choice, updated_at = excluded.updated_at',
            [$topicId, $userId, $choice, $now, $now]
        );
    }
}
