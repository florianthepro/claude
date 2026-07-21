<?php
declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database as DB;

/** Freischalt-Tickets für neue Nutzer. */
final class Tickets
{
    /** Öffnet ein Ticket und liefert den festen Ticket-Code (= Mail-Betreff). */
    public static function open(int $uid): string
    {
        // Kurzer, gut referenzierbarer Code: TCK-JJJJ-XXXXXX
        $code = 'TCK-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        DB::run('INSERT INTO tickets (code,user_id,status) VALUES (?,?,?)', [$code, $uid, 'open']);
        return $code;
    }

    public static function resolve(int $uid, string $status, int $byAdmin): void
    {
        DB::run("UPDATE tickets SET status=?, resolved_at=?, resolved_by=? WHERE user_id=? AND status='open'",
            [$status, date('Y-m-d H:i:s'), $byAdmin, $uid]);
    }

    public static function openCount(): int
    {
        return (int) DB::scalar("SELECT COUNT(*) FROM tickets WHERE status='open'");
    }

    public static function forUser(int $uid): ?array
    {
        return DB::one('SELECT * FROM tickets WHERE user_id=? ORDER BY id DESC LIMIT 1', [$uid]);
    }
}
