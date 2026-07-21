<?php
declare(strict_types=1);

namespace Nexus\Core;

use Nexus\Services\Auth;

/** Login-/Registrierungs- und Sperr-Seiten. */
final class AuthView
{
    public static function form(string $mode, ?string $err = null): void
    {
        Security::headers();
        $first = Auth::count() === 0;
        echo '<!doctype html><html lang="de" data-theme="dark"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . ($mode === 'register' ? 'Registrieren' : 'Anmelden') . ' · ' . NX_NAME . '</title>';
        echo '<link rel="stylesheet" href="assets/app.css?v=' . NX_VERSION . '">';
        echo '<script>const t=localStorage.getItem("nx_theme");if(t)document.documentElement.setAttribute("data-theme",t);</script>';
        echo '</head><body><div class="auth-wrap"><div class="auth-card">';
        echo '<div class="logo">' . Icons::logoMark(26) . '</div>';

        if ($mode === 'register') {
            echo '<h1>' . ($first ? 'Willkommen bei ' . NX_NAME : 'Konto anlegen') . '</h1>';
            echo '<p class="tag">' . ($first
                ? 'Lege das erste Konto an – es wird automatisch Administrator.'
                : 'Nach der Registrierung schaltet ein Administrator dein Konto frei.') . '</p>';
        } else {
            echo '<h1>Anmelden</h1><p class="tag">Willkommen zurück bei ' . NX_NAME . '.</p>';
        }
        if ($err) {
            echo '<div class="alert err">' . h($err) . '</div>';
        }

        echo '<form method="post" action="?action=' . ($mode === 'register' ? 'register' : 'login') . '">';
        echo Security::field();
        echo '<div class="field"><label>Benutzername</label><input class="input" name="username" autofocus autocomplete="username" required value="' . h(param('username')) . '"></div>';
        if ($mode === 'register') {
            echo '<div class="field"><label>E-Mail (Pflicht)</label><input class="input" type="email" name="email" autocomplete="email" required value="' . h(param('email')) . '"></div>';
        }
        echo '<div class="field"><label>Passwort</label><input class="input" type="password" name="password" autocomplete="' . ($mode === 'register' ? 'new-password' : 'current-password') . '" required></div>';
        if ($mode === 'register') {
            echo '<div class="field"><label>Passwort bestätigen</label><input class="input" type="password" name="password2" autocomplete="new-password" required></div>';
        }
        echo '<button class="btn" style="width:100%;justify-content:center" type="submit">' . ($mode === 'register' ? 'Konto anlegen' : 'Anmelden') . '</button>';
        echo '</form>';

        echo '<div class="auth-switch">' . ($mode === 'register'
            ? 'Bereits registriert? <a href="?view=login">Anmelden</a>'
            : 'Noch kein Konto? <a href="?view=register">Registrieren</a>') . '</div>';
        echo '</div></div><script src="assets/app.js?v=' . NX_VERSION . '"></script></body></html>';
    }

    public static function locked(): void
    {
        Security::headers();
        echo '<!doctype html><html lang="de" data-theme="dark"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Gesperrt · ' . NX_NAME . '</title><link rel="stylesheet" href="assets/app.css?v=' . NX_VERSION . '">';
        echo '</head><body><div class="auth-wrap"><div class="auth-card" style="text-align:center">';
        echo '<div class="logo" style="color:var(--err)">' . Icons::icon('ban', 26) . '</div>';
        echo '<h1>Konto gesperrt</h1>';
        echo '<p class="tag">Dieses Konto wurde durch einen Administrator gesperrt.</p>';
        echo '<a class="btn ghost" style="width:100%;justify-content:center" href="?action=logout">Abmelden</a>';
        echo '</div></div></body></html>';
    }
}
