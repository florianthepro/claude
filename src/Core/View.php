<?php
declare(strict_types=1);

namespace Nexus\Core;

use Nexus\Services\Auth;
use Nexus\Services\Quota;

/** Rendert das einheitliche Layout (Kopf, Sidebar, Topbar, Footer). */
final class View
{
    public static function head(array $user, string $activeApp): void
    {
        Security::headers();
        $theme  = $user['theme'] ?: 'dark';
        $accent = $user['accent'] ?: '#4d7ea8';
        $apps   = nx_apps();
        $meta   = $apps[$activeApp] ?? $apps['home'];

        echo '<!doctype html><html lang="de" data-theme="' . h($theme) . '"><head>';
        echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . h($meta['name']) . ' · ' . NX_NAME . '</title>';
        echo '<link rel="stylesheet" href="assets/app.css?v=' . NX_VERSION . '">';
        echo '<style>:root{--accent:' . h($accent) . '}</style>';
        echo '<link rel="icon" href="data:image/svg+xml,' . rawurlencode(Icons::logoFavicon($accent)) . '">';
        echo '</head><body>';
        echo '<div class="backdrop" onclick="toggleMenu()"></div><div class="shell">';

        // Sidebar
        echo '<aside class="sidebar">';
        echo '<div class="brand"><div class="logo">' . Icons::logoMark(20) . '</div>' . NX_NAME . '</div>';
        echo '<nav class="nav">';
        foreach ($apps as $id => $a) {
            if (!Auth::canAccess($user, $id)) {
                continue;
            }
            $active = $id === $activeApp ? ' active' : '';
            $badge  = '';
            if ($id === 'mail') {
                $unseen = Quota::unreadCount((int) $user['id']);
                if ($unseen > 0) {
                    $badge = '<span class="nav-badge">' . $unseen . '</span>';
                }
            }
            if ($id === 'admin') {
                $open = (int) Database::scalar("SELECT COUNT(*) FROM tickets WHERE status='open'");
                if ($open > 0) {
                    $badge = '<span class="nav-badge warn">' . $open . '</span>';
                }
            }
            echo '<a class="' . $active . '" href="' . url($id) . '">' . Icons::icon($a['icon'])
                . '<span>' . h($a['name']) . '</span>' . $badge
                . '<span class="dot" style="background:' . h($a['color']) . '"></span></a>';
        }
        echo '</nav>';

        // Quota-Anzeige
        $used = Quota::used((int) $user['id']);
        $max  = (int) $user['quota_bytes'];
        $pct  = $max > 0 ? min(100, (int) round($used / $max * 100)) : 0;
        $barc = $pct >= 90 ? 'var(--err)' : ($pct >= 70 ? 'var(--warn)' : 'var(--accent)');
        echo '<div class="quota">';
        echo '<div class="quota-top"><span>Speicher</span><span class="mono">' . human_size($used) . ' / ' . human_size($max) . '</span></div>';
        echo '<div class="quota-bar"><i style="width:' . $pct . '%;background:' . $barc . '"></i></div>';
        echo '</div>';

        // Footer / Nutzer
        echo '<div class="side-foot">';
        echo '<a class="side-user" href="' . url('settings') . '">';
        echo '<div class="avatar">' . h(strtoupper(substr($user['display_name'] ?: $user['username'], 0, 1))) . '</div>';
        $roleLbl = $user['role'] === 'admin' ? 'Administrator'
            : ($user['status'] === 'pending' ? 'Wartet auf Freischaltung'
            : ($user['status'] === 'suspended' ? 'Gesperrt' : 'Benutzer'));
        echo '<div><strong>' . h($user['display_name'] ?: $user['username']) . '</strong><small>' . h($roleLbl) . '</small></div></a>';
        echo '<a class="side-user" style="color:var(--muted)" href="?action=logout">' . Icons::icon('logout', 20) . '<span>Abmelden</span></a>';
        echo '</div></aside><main class="main">';
    }

    public static function topbar(string $title, string $sub = '', string $actions = ''): void
    {
        $user = Auth::user();
        echo '<div class="topbar">';
        echo '<button class="iconbtn menu-toggle" onclick="toggleMenu()">' . Icons::icon('grid') . '</button>';
        echo '<div><h1>' . h($title) . '</h1>';
        if ($sub !== '') {
            echo '<div class="sub">' . h($sub) . '</div>';
        }
        echo '</div><div class="spacer"></div>' . $actions;
        $isDark = ($user['theme'] ?? 'dark') === 'dark';
        echo '<button class="iconbtn" title="Theme wechseln" onclick="toggleTheme()">' . Icons::icon($isDark ? 'sun' : 'moon') . '</button>';
        echo '</div>';
        if ($f = flash()) {
            echo '<div class="alert ' . h($f['type']) . '">' . h($f['msg']) . '</div>';
        }
    }

    public static function foot(): void
    {
        echo '</main></div><script src="assets/app.js?v=' . NX_VERSION . '"></script></body></html>';
    }

    public static function swatch(string $field, string $current, array $colors = []): string
    {
        if (!$colors) {
            $colors = ['#4d7ea8', '#4a9d6f', '#b3893f', '#c25a5a', '#8a7fb0', '#4a8ca0', '#7a828e', '#546072'];
        }
        $out = '<div class="swatch">';
        foreach ($colors as $c) {
            $chk = strcasecmp($c, $current) === 0 ? 'checked' : '';
            $out .= '<label><input type="radio" name="' . $field . '" value="' . $c . '" ' . $chk . '><span style="background:' . $c . '"></span></label>';
        }
        return $out . '</div>';
    }
}
