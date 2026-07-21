<?php
declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database as DB;

/**
 * Speicher-Abrechnung pro Nutzer: interner Mail-Posteingang + Dateien.
 * Bewusst günstig gehalten (Roh-Bytes werden bei Mail vorab gespeichert,
 * Dateigrößen werden gecacht statt bei jedem Aufruf rekursiv gezählt).
 */
final class Quota
{
    /** Belegter Speicher in Bytes. */
    public static function used(int $uid): int
    {
        $mail = (int) DB::scalar('SELECT COALESCE(SUM(bytes),0) FROM imsg WHERE recipient_id=? AND del_recip=0', [$uid]);
        return $mail + self::filesBytes($uid);
    }

    public static function remaining(int $uid): int
    {
        $u = DB::one('SELECT quota_bytes FROM users WHERE id=?', [$uid]);
        return max(0, (int) ($u['quota_bytes'] ?? 0) - self::used($uid));
    }

    public static function canStore(int $uid, int $bytes): bool
    {
        return $bytes <= self::remaining($uid);
    }

    public static function unreadCount(int $uid): int
    {
        return (int) DB::scalar('SELECT COUNT(*) FROM imsg WHERE recipient_id=? AND seen=0 AND del_recip=0', [$uid]);
    }

    /** Größe des Datei-Sandbox-Ordners (rekursiv). */
    public static function filesBytes(int $uid): int
    {
        $root = NX_DATA . '/files/' . $uid;
        if (!is_dir($root)) {
            return 0;
        }
        $sum = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $sum += $f->getSize();
            }
        }
        return $sum;
    }
}
