<?php
declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database as DB;

/** Systembenachrichtigungen (intern immer, extern best-effort). */
final class Mailer
{
    /** Neuer Nutzer registriert → Ticket an den ersten Admin. */
    public static function notifyNewUser(int $newUid, string $ticketCode): void
    {
        $admin = Auth::firstAdmin();
        $new   = DB::one('SELECT * FROM users WHERE id=?', [$newUid]);
        if (!$admin || !$new) {
            return;
        }
        $body = "Neue Registrierung wartet auf Freischaltung.\n\n"
              . "Benutzer:  {$new['username']}\n"
              . "E-Mail:    {$new['email']}\n"
              . "Ticket:    {$ticketCode}\n\n"
              . "In der Verwaltung freischalten, ablehnen oder Quota anpassen.";

        // Intern: Ticket-Nachricht an den Admin (fester Betreff = Ticket-Code)
        InternalMail::deliver($newUid, (int) $admin['id'], $ticketCode, $body, $ticketCode, false);

        // Extern: best effort an die Ticket-Adresse des Admins
        $to = $admin['ticket_email'] ?: $admin['email'];
        if ($to) {
            self::sendExternalBestEffort((int) $admin['id'], $to, $ticketCode . ' – Neue Registrierung: ' . $new['username'], $body);
        }
    }

    /** Interne System-Nachricht an einen Nutzer (vom ersten Admin). */
    public static function system(int $uid, string $subject, string $body): void
    {
        $admin = Auth::firstAdmin();
        if (!$admin) {
            return;
        }
        InternalMail::deliver((int) $admin['id'], $uid, $subject, $body, '', false);
    }

    /** Externer Versand über das erste Mailkonto des Admins – schlägt still fehl. */
    private static function sendExternalBestEffort(int $adminId, string $to, string $subject, string $body): void
    {
        $acc = DB::one('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id LIMIT 1', [$adminId]);
        if (!$acc) {
            return;
        }
        try {
            Smtp::send($acc, $to, $subject, $body);
        } catch (\Throwable $e) {
            // bewusst ignorieren – interne Zustellung ist die verlässliche Quelle
        }
    }
}
