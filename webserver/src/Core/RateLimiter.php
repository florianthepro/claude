<?php

declare(strict_types=1);

namespace Stimmwerk\Core;

/**
 * Einfache, serverseitige Festfenster-Begrenzung je Schlüssel
 * (z. B. "vote:<userid>" oder "auth:<ip-hash>"). Abgelaufene Fenster werden
 * beim Zugriff aufgeräumt; es werden keine Klar-IP-Adressen gespeichert.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /** true = erlaubt (und gezählt), false = Limit erreicht. */
    public function allow(string $key, int $max, int $windowSeconds): bool
    {
        $now = Clock::now()->getTimestamp();
        return $this->db->tx(function () use ($key, $max, $windowSeconds, $now): bool {
            $row = $this->db->one('SELECT window_start, cnt FROM rate_limits WHERE k = ?', [$key]);
            if ($row === null || ($now - (int) $row['window_start']) >= $windowSeconds) {
                $this->db->run(
                    'INSERT INTO rate_limits (k, window_start, cnt) VALUES (?, ?, 1)
                     ON CONFLICT(k) DO UPDATE SET window_start = excluded.window_start, cnt = 1',
                    [$key, $now]
                );
                return true;
            }
            if ((int) $row['cnt'] >= $max) {
                return false;
            }
            $this->db->run('UPDATE rate_limits SET cnt = cnt + 1 WHERE k = ?', [$key]);
            return true;
        });
    }

    /** Entfernt Fenster, die älter als $olderThanSeconds sind. */
    public function gc(int $olderThanSeconds = 86400): void
    {
        $cutoff = Clock::now()->getTimestamp() - $olderThanSeconds;
        $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [$cutoff]);
    }
}
