<?php
declare(strict_types=1);

namespace Nexus\Core;

use Nexus\Services\Auth;

/** Request-Lebenszyklus: Session, Auth-Gating, Routing, Rendering. */
final class Kernel
{
    public static function handle(): void
    {
        self::startSession();

        $action = param('action');

        // Auth-freie Aktionen
        if ($action === 'logout') {
            Auth::logout();
            redirect('?view=login');
        }
        if ($action === 'save_theme') {
            if ($u = Auth::user()) {
                $t = param('theme') === 'light' ? 'light' : 'dark';
                Database::run('UPDATE users SET theme=? WHERE id=?', [$t, $u['id']]);
            }
            header('Content-Type: application/json');
            echo '{"ok":true}';
            exit;
        }

        $user = Auth::user();

        // Nicht eingeloggt → Login/Registrierung
        if (!$user) {
            self::guest($action);
            exit;
        }

        // Gesperrt → alles blockiert außer Abmelden
        if ($user['status'] === 'suspended') {
            AuthView::locked();
            exit;
        }

        $app = self::currentApp();

        // Zugriffskontrolle
        if (!Auth::canAccess($user, $app)) {
            flash('Diese Funktion ist erst nach der Freischaltung durch einen Administrator verfügbar.', 'err');
            redirect(url('home'));
        }

        $class = nx_apps()[$app]['class'];

        // Aktion abarbeiten (führt i. d. R. zu einem Redirect)
        if ($action !== '' && method_exists($class, 'handle')) {
            $class::handle($user, $action);
        }

        // Seite rendern
        View::head($user, $app);
        $class::render($user);
        View::foot();
    }

    private static function startSession(): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
        session_start();
    }

    private static function currentApp(): string
    {
        $a = param('app', 'home');
        return array_key_exists($a, nx_apps()) ? $a : 'home';
    }

    private static function guest(string $action): void
    {
        if ($action === 'register') {
            Security::verifyPost();
            $r = Auth::register(param('username'), param('email'), param('password'), param('password2'));
            if (!empty($r['ok'])) {
                redirect(url('home'));
            }
            AuthView::form('register', $r['err']);
            return;
        }
        if ($action === 'login') {
            Security::verifyPost();
            $r = Auth::login(param('username'), param('password'));
            if (!empty($r['ok'])) {
                redirect(url('home'));
            }
            AuthView::form('login', $r['err']);
            return;
        }
        $mode = (param('view') === 'register' || Auth::count() === 0) ? 'register' : 'login';
        AuthView::form($mode);
    }
}
