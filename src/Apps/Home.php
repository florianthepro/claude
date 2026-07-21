<?php
declare(strict_types=1);

namespace Nexus\Apps;

use Nexus\Core\Database as DB;
use Nexus\Core\Icons;
use Nexus\Core\View;
use Nexus\Services\Auth;
use Nexus\Services\Tickets;

final class Home
{
    public static function render(array $u): void
    {
        View::topbar('Dashboard', 'Angemeldet als ' . ($u['display_name'] ?: $u['username']) . ' · ' . date('d.m.Y H:i'));

        // Freischaltung ausstehend?
        if ($u['status'] === 'pending') {
            $t = Tickets::forUser((int) $u['id']);
            echo '<div class="banner">' . Icons::icon('clock', 20) . '<div>';
            echo '<b>Konto wartet auf Freischaltung</b>';
            echo '<p>Ein Administrator prüft deine Registrierung. Vorläufig stehen dir ' . human_size((int) $u['quota_bytes'])
                . ' Speicher zur Verfügung, und du kannst über <a href="' . url('mail') . '" style="color:var(--accent)">Mail</a> '
                . 'ausschließlich den Administrator kontaktieren. Ticket: <span class="mono">' . h($t['code'] ?? '—') . '</span></p>';
            echo '</div></div>';
        }

        // Schnellzugriff (nur freigeschaltete Nutzer haben Lesezeichen)
        if (Auth::canAccess($u, 'bookmarks')) {
            $bm = DB::all('SELECT * FROM bookmarks WHERE user_id=? ORDER BY position,id', [$u['id']]);
            if ($bm) {
                echo '<div class="section-h">' . Icons::icon('link') . ' Schnellzugriff</div><div class="grid tiles">';
                foreach ($bm as $b) {
                    echo '<a class="tile" style="--tc:' . h($b['color']) . ';min-height:auto" href="' . h($b['url']) . '" target="_blank" rel="noopener">';
                    echo '<div class="tico" style="width:32px;height:32px">' . Icons::icon('link', 16) . '</div>';
                    echo '<div><h3 style="font-size:14px">' . h($b['title']) . '</h3><p style="font-size:11.5px;word-break:break-all;margin:0">'
                        . h(preg_replace('#^https?://#', '', $b['url'])) . '</p></div></a>';
                }
                echo '</div>';
            }
        }

        // App-Kacheln (nur erlaubte)
        echo '<div class="section-h">' . Icons::icon('grid') . ' Apps</div><div class="grid tiles">';
        foreach (nx_apps() as $id => $a) {
            if (empty($a['tile']) || !Auth::canAccess($u, $id)) {
                continue;
            }
            echo '<a class="tile" style="--tc:' . h($a['color']) . '" href="' . url($id) . '">';
            echo '<div class="tico">' . Icons::icon($a['icon'], 22) . '</div>';
            echo '<div><h3>' . h($a['name']) . '</h3><p>' . h($a['desc']) . '</p></div></a>';
        }
        echo '</div>';

        // Heutige Termine (nur freigeschaltet)
        if (Auth::canAccess($u, 'calendar')) {
            $ev = DB::all('SELECT * FROM events WHERE user_id=? AND day=? ORDER BY time', [$u['id'], date('Y-m-d')]);
            echo '<div class="section-h">' . Icons::icon('calendar') . ' Heute</div><div class="panel">';
            if ($ev) {
                foreach ($ev as $e) {
                    echo '<div style="display:flex;align-items:center;gap:14px;padding:10px 0;border-bottom:1px solid var(--line)">';
                    echo '<span style="width:9px;height:9px;border-radius:50%;background:' . h($e['color']) . ';flex:none"></span>';
                    echo '<strong class="mono" style="min-width:56px">' . h($e['time'] ?: '—') . '</strong>';
                    echo '<div style="flex:1">' . h($e['title']);
                    if ($e['description']) {
                        echo '<br><small style="color:var(--muted)">' . h($e['description']) . '</small>';
                    }
                    echo '</div></div>';
                }
            } else {
                echo '<div class="empty" style="padding:26px">' . Icons::icon('clock', 34) . '<p>Keine Termine für heute.</p></div>';
            }
            echo '</div>';
        }
    }
}
