<?php

declare(strict_types=1);

namespace Stimmwerk\Domain;

use Stimmwerk\Core\Database;

/**
 * Kontolöschung (DSGVO): Stimmen, Favoriten und offene Jury-Sitze werden
 * vollständig gelöscht; eingebrachte Themen und gestellte Meldungen bleiben
 * als Beiträge erhalten, werden aber vom Pseudonym entkoppelt.
 */
final class AccountService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function deleteAccount(int $userId): void
    {
        $this->db->tx(function () use ($userId): void {
            $systemId = (int) $this->db->val('SELECT id FROM users WHERE is_system = 1 LIMIT 1');
            $this->db->run('UPDATE topics SET author_id = ? WHERE author_id = ?', [$systemId, $userId]);
            // Stimmen, Favoriten und Jury-Sitze fallen per ON DELETE CASCADE,
            // Melder-Referenzen werden per ON DELETE SET NULL entkoppelt.
            $this->db->run('DELETE FROM users WHERE id = ?', [$userId]);
        });
    }
}
