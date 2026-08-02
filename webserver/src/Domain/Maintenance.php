<?php

declare(strict_types=1);

namespace Stimmwerk\Domain;

use Stimmwerk\Core\Clock;
use Stimmwerk\Core\Database;
use Stimmwerk\Core\RateLimiter;

/**
 * Idempotenter Wartungslauf. Läuft per Cron (bin/cron.php) UND – gedrosselt –
 * lazy bei Seitenaufrufen, damit der Prototyp auch ohne Cron korrekt bleibt.
 */
final class Maintenance
{
    private const THROTTLE_SECONDS = 30;

    public function __construct(
        private readonly Database $db,
        private readonly JuryService $jury,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /** Gedrosselter Lauf für Web-Requests. */
    public function tickThrottled(): void
    {
        $now = Clock::now()->getTimestamp();
        $last = (int) ($this->db->val("SELECT v FROM schema_info WHERE k = 'last_tick'") ?? 0);
        if (($now - $last) < self::THROTTLE_SECONDS) {
            return;
        }
        $this->db->run(
            "INSERT INTO schema_info (k, v) VALUES ('last_tick', ?)
             ON CONFLICT(k) DO UPDATE SET v = excluded.v",
            [(string) $now]
        );
        $this->tick();
    }

    /** Vollständiger Lauf (Cron, Tests). */
    public function tick(): void
    {
        $this->jury->processDue();
        $this->rateLimiter->gc();
    }
}
