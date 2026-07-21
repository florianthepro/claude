<?php
declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database as DB;

/**
 * Interner Nachrichtenspeicher. Bodies werden komprimiert (gzdeflate) in
 * SQLite abgelegt → wenig Speicher; es werden keine externen Ressourcen
 * geladen → keine Bandbreite. Der Posteingang zählt gegen die Quota.
 */
final class InternalMail
{
    /**
     * Zustellen. Gibt bei Erfolg true zurück, sonst eine Fehlermeldung.
     * $enforceQuota=false für System-/Ticket-Nachrichten.
     */
    public static function deliver(int $senderId, int $recipientId, string $subject,
                                   string $body, string $ticketCode = '', bool $enforceQuota = true)
    {
        $body    = trim($body);
        $subject = trim($subject);
        $bytes   = strlen($body) + strlen($subject);

        if ($enforceQuota && !Quota::canStore($recipientId, $bytes)) {
            return 'Empfänger-Postfach ist voll (Quota überschritten).';
        }
        $gz = function_exists('gzdeflate') ? gzdeflate($body, 6) : $body;
        DB::run('INSERT INTO imsg (sender_id,recipient_id,subject,body_gz,bytes,ticket_code)
                 VALUES (?,?,?,?,?,?)',
            [$senderId, $recipientId, $subject, $gz, $bytes, $ticketCode]);
        return true;
    }

    public static function inbox(int $uid, string $folder = 'inbox', int $limit = 50): array
    {
        if ($folder === 'sent') {
            return DB::all(
                "SELECT m.*, u.username AS other FROM imsg m
                 JOIN users u ON u.id=m.recipient_id
                 WHERE m.sender_id=? AND m.del_sender=0 ORDER BY m.id DESC LIMIT ?",
                [$uid, $limit]
            );
        }
        return DB::all(
            "SELECT m.*, u.username AS other FROM imsg m
             JOIN users u ON u.id=m.sender_id
             WHERE m.recipient_id=? AND m.del_recip=0 ORDER BY m.id DESC LIMIT ?",
            [$uid, $limit]
        );
    }

    /** Eine Nachricht, sofern der Nutzer Sender oder Empfänger ist. */
    public static function get(int $id, int $uid): ?array
    {
        $m = DB::one('SELECT * FROM imsg WHERE id=? AND (recipient_id=? OR sender_id=?)', [$id, $uid, $uid]);
        if (!$m) {
            return null;
        }
        $m['body'] = self::body($m);
        return $m;
    }

    public static function body(array $m): string
    {
        $raw = $m['body_gz'];
        if ($raw === null || $raw === '') {
            return '';
        }
        if (function_exists('gzinflate')) {
            $out = @gzinflate($raw);
            if ($out !== false) {
                return $out;
            }
        }
        return (string) $raw;
    }

    public static function markSeen(int $id, int $uid): void
    {
        DB::run('UPDATE imsg SET seen=1 WHERE id=? AND recipient_id=?', [$id, $uid]);
    }

    public static function delete(int $id, int $uid): void
    {
        // Nur die eigene Sicht löschen; Zeile erst entfernen, wenn beide gelöscht haben.
        DB::run('UPDATE imsg SET del_recip=1 WHERE id=? AND recipient_id=?', [$id, $uid]);
        DB::run('UPDATE imsg SET del_sender=1 WHERE id=? AND sender_id=?', [$id, $uid]);
        DB::run('DELETE FROM imsg WHERE id=? AND del_recip=1 AND del_sender=1', [$id]);
    }
}
