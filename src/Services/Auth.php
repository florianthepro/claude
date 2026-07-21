<?php
declare(strict_types=1);

namespace Nexus\Services;

use Nexus\Core\Database as DB;

/** Authentifizierung, Registrierung mit Freischalt-Workflow, Admin-Aktionen. */
final class Auth
{
    private static bool $loaded = false;
    private static ?array $user = null;

    /* ---------------- Sitzung ---------------- */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        if (empty($_SESSION['uid'])) {
            return self::$user = null;
        }
        return self::$user = DB::one('SELECT * FROM users WHERE id=?', [$_SESSION['uid']]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function count(): int
    {
        return (int) DB::scalar('SELECT COUNT(*) FROM users');
    }

    /** Der erste (dienstälteste) Administrator. */
    public static function firstAdmin(): ?array
    {
        return DB::one("SELECT * FROM users WHERE role='admin' ORDER BY id LIMIT 1");
    }

    /* ---------------- Zugriffskontrolle ---------------- */
    public static function canAccess(array $user, string $appId): bool
    {
        $apps = nx_apps();
        if (!isset($apps[$appId])) {
            return false;
        }
        if ($user['status'] === 'suspended') {
            return false;
        }
        $min = $apps[$appId]['min'];
        if ($min === 'admin') {
            return $user['role'] === 'admin';
        }
        if ($min === 'active') {
            return $user['status'] === 'active' || $user['role'] === 'admin';
        }
        return true; // pending: jeder eingeloggte Nutzer
    }

    /* ---------------- Registrierung ---------------- */
    public static function register(string $user, string $email, string $pass, string $pass2): array
    {
        $user  = trim($user);
        $email = trim($email);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $user)) {
            return ['err' => 'Benutzername: 3–32 Zeichen (A–Z, 0–9, _.-).'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['err' => 'Bitte eine gültige E-Mail-Adresse angeben.'];
        }
        if (strlen($pass) < 8) {
            return ['err' => 'Passwort muss mindestens 8 Zeichen haben.'];
        }
        if ($pass !== $pass2) {
            return ['err' => 'Passwörter stimmen nicht überein.'];
        }
        if (DB::one('SELECT 1 FROM users WHERE username=?', [$user])) {
            return ['err' => 'Benutzername bereits vergeben.'];
        }

        $first = self::count() === 0;
        $role   = $first ? 'admin'  : 'user';
        $status = $first ? 'active' : 'pending';
        $quota  = $first ? NX_QUOTA_ACTIVE : NX_QUOTA_PENDING;

        DB::run(
            'INSERT INTO users (username,email,pass_hash,display_name,role,status,quota_bytes,approved_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$user, $email, password_hash($pass, PASSWORD_DEFAULT), $user, $role, $status, $quota,
             $first ? date('Y-m-d H:i:s') : null]
        );
        $uid = DB::lastId();
        $_SESSION['uid'] = $uid;
        session_regenerate_id(true);

        if (!$first) {
            // Freischalt-Ticket + Benachrichtigung des Admins
            $ticket = Tickets::open($uid);
            Mailer::notifyNewUser($uid, $ticket);
        }
        return ['ok' => true, 'first' => $first];
    }

    /* ---------------- Login (mit Drossel) ---------------- */
    public static function login(string $user, string $pass): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $window = time() - 900; // 15 min
        DB::run('DELETE FROM login_attempts WHERE ts < ?', [time() - 86400]);
        $fails = (int) DB::scalar('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND ts>?', [$ip, $window]);
        if ($fails >= 10) {
            return ['err' => 'Zu viele Fehlversuche. Bitte später erneut versuchen.'];
        }

        $row = DB::one('SELECT * FROM users WHERE username=?', [trim($user)]);
        if (!$row || !password_verify($pass, $row['pass_hash'])) {
            DB::run('INSERT INTO login_attempts (ip,username,ts) VALUES (?,?,?)', [$ip, trim($user), time()]);
            return ['err' => 'Benutzername oder Passwort falsch.'];
        }
        if ($row['status'] === 'suspended') {
            return ['err' => 'Dieses Konto ist gesperrt. Bitte an den Administrator wenden.'];
        }
        DB::run('DELETE FROM login_attempts WHERE ip=?', [$ip]);
        $_SESSION['uid'] = (int) $row['id'];
        session_regenerate_id(true);
        return ['ok' => true];
    }

    /* ---------------- Admin-Aktionen ---------------- */
    public static function approve(int $uid, int $byAdmin): void
    {
        DB::run("UPDATE users SET status='active', quota_bytes=MAX(quota_bytes,?), approved_at=?, approved_by=?
                 WHERE id=? AND status!='suspended'",
            [NX_QUOTA_ACTIVE, date('Y-m-d H:i:s'), $byAdmin, $uid]);
        Tickets::resolve($uid, 'approved', $byAdmin);
        Mailer::system($uid, 'Konto freigeschaltet',
            "Dein Konto wurde freigeschaltet. Dir stehen jetzt " . human_size(NX_QUOTA_ACTIVE) . " zur Verfügung.");
    }

    public static function reject(int $uid, int $byAdmin): void
    {
        Tickets::resolve($uid, 'rejected', $byAdmin);
        DB::run("UPDATE users SET status='suspended' WHERE id=? AND role!='admin'", [$uid]);
    }

    public static function suspend(int $uid): void
    {
        DB::run("UPDATE users SET status='suspended' WHERE id=? AND role!='admin'", [$uid]);
    }

    public static function unsuspend(int $uid): void
    {
        DB::run("UPDATE users SET status='active' WHERE id=?", [$uid]);
    }

    public static function setQuota(int $uid, int $bytes): void
    {
        $bytes = max(0, $bytes);
        DB::run('UPDATE users SET quota_bytes=? WHERE id=?', [$bytes, $uid]);
    }

    public static function promote(int $uid): void
    {
        DB::run("UPDATE users SET role='admin', status='active' WHERE id=?", [$uid]);
    }

    public static function demote(int $uid): void
    {
        // Den letzten verbleibenden Admin nicht degradieren
        $admins = (int) DB::scalar("SELECT COUNT(*) FROM users WHERE role='admin'");
        if ($admins > 1) {
            DB::run("UPDATE users SET role='user' WHERE id=?", [$uid]);
        }
    }
}
