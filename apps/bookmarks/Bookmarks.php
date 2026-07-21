<?php
declare(strict_types=1);

namespace Nexus\Apps;

use Nexus\Core\Database as DB;
use Nexus\Core\Icons;
use Nexus\Core\Security;
use Nexus\Core\View;

final class Bookmarks
{
    public static function handle(array $u, string $action): void
    {
        if ($action === 'save') {
            Security::verifyPost();
            $title = trim(param('title'));
            $urlv = trim(param('url'));
            if (!preg_match('#^https?://#i', $urlv)) {
                $urlv = 'https://' . $urlv;
            }
            if ($title !== '' && filter_var($urlv, FILTER_VALIDATE_URL)) {
                DB::run('INSERT INTO bookmarks (user_id,title,url,color) VALUES (?,?,?,?)',
                    [$u['id'], $title, $urlv, param('color', '#4d7ea8')]);
                flash('Lesezeichen hinzugefügt.');
            }
            redirect(url('bookmarks'));
        }
        if ($action === 'del') {
            Security::verifyGet();
            DB::run('DELETE FROM bookmarks WHERE id=? AND user_id=?', [param_int('id'), $u['id']]);
            flash('Lesezeichen gelöscht.');
            redirect(url('bookmarks'));
        }
    }

    public static function render(array $u): void
    {
        $bm = DB::all('SELECT * FROM bookmarks WHERE user_id=? ORDER BY position,id', [$u['id']]);
        View::topbar('Lesezeichen', count($bm) . ' Links · erscheinen auf der Startseite',
            '<button class="btn" onclick="openModal(\'bmModal\')">' . Icons::icon('plus') . ' Link hinzufügen</button>');

        if ($bm) {
            echo '<div class="grid tiles">';
            foreach ($bm as $b) {
                echo '<div class="tile" style="--tc:' . h($b['color']) . ';min-height:auto">';
                echo '<a href="' . h($b['url']) . '" target="_blank" rel="noopener" style="display:flex;gap:11px;align-items:center">';
                echo '<div class="tico" style="width:32px;height:32px">' . Icons::icon('link', 16) . '</div>';
                echo '<div style="min-width:0"><h3 style="font-size:14px">' . h($b['title']) . '</h3>';
                echo '<p style="font-size:11.5px;word-break:break-all;margin:0">' . h(preg_replace('#^https?://#', '', $b['url'])) . '</p></div></a>';
                echo '<div style="display:flex;gap:6px;margin-top:auto"><a class="btn ghost sm" href="?app=bookmarks&action=del&id=' . $b['id'] . '&_csrf=' . Security::token() . '" onclick="return confirm(\'Löschen?\')">' . Icons::icon('trash', 14) . '</a></div>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<div class="empty">' . Icons::icon('link', 40) . '<h3>Keine Lesezeichen</h3><p>Links erscheinen als Kacheln auf der Startseite.</p></div>';
        }

        echo '<div class="modal" id="bmModal"><div class="box">';
        echo '<span class="modal-x" onclick="closeModal(\'bmModal\')">&times;</span><h3>Lesezeichen hinzufügen</h3>';
        echo '<form method="post" action="?app=bookmarks&action=save">' . Security::field();
        echo '<div class="field"><label>Titel</label><input class="input" name="title" required></div>';
        echo '<div class="field"><label>URL</label><input class="input" type="text" name="url" placeholder="https://…" required></div>';
        echo '<div class="field"><label>Farbe</label>' . View::swatch('color', '#4d7ea8') . '</div>';
        echo '<button class="btn" type="submit" style="width:100%;justify-content:center">Hinzufügen</button>';
        echo '</form></div></div>';
    }
}
