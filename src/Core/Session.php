<?php

declare(strict_types=1);

namespace Stimmwerk\Core;

/**
 * Sitzungen mit Blick auf öffentliche Terminals: HttpOnly, SameSite,
 * Secure bei HTTPS, ID-Rotation bei An-/Abmeldung, Inaktivitäts- und
 * absolutes Timeout.
 */
final class Session
{
    public function __construct(
        private readonly int $idleMinutes,
        private readonly int $maxHours,
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('sw_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $this->enforceTimeouts();
        $_SESSION['last_activity'] = time();
    }

    public function isHttps(): bool
    {
        // X-Forwarded-Proto: bei Hosting-Paketen mit vorgeschaltetem
        // TLS-Proxy üblich; führt höchstens dazu, dass das Sitzungs-Cookie
        // ZUSÄTZLICH als "secure" markiert wird (sichere Richtung).
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    private function enforceTimeouts(): void
    {
        $now = time();
        $idle = isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $this->idleMinutes * 60;
        $expired = isset($_SESSION['auth_time']) && ($now - (int) $_SESSION['auth_time']) > $this->maxHours * 3600;
        if (($idle || $expired) && isset($_SESSION['user_id'])) {
            $this->clearAuthenticated();
            $this->flash('info', 'flash.session_expired');
        }
    }

    public function authenticate(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_time'] = time();
    }

    public function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return is_int($id) ? $id : null;
    }

    public function clearAuthenticated(): void
    {
        unset($_SESSION['user_id'], $_SESSION['auth_time']);
        session_regenerate_id(true);
    }

    /** Einmal-Nachricht (Flash); $key ist ein Übersetzungsschlüssel. */
    public function flash(string $type, string $key, array $repl = []): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'key' => $key, 'repl' => $repl];
    }

    public function takeFlashes(): array
    {
        $flashes = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return is_array($flashes) ? $flashes : [];
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }
}
