<?php
declare(strict_types=1);

namespace Nexus\Apps;

use Nexus\Core\Database as DB;
use Nexus\Core\Icons;
use Nexus\Core\Security;
use Nexus\Core\View;

final class Notes
{
    public static function handle(array $u, string $action): void
    {
        if ($action === 'save') {
            Security::verifyPost();
            $id = param_int('id');
            $title = trim(param('title'));
            $body = trim(param('body'));
            $color = param('color', '#b3893f');
            if ($body === '' && $title === '') {
                redirect(url('notes'));
            }
            if ($id) {
                DB::run("UPDATE notes SET title=?,body=?,color=?,updated_at=datetime('now') WHERE id=? AND user_id=?",
                    [$title, $body, $color, $id, $u['id']]);
            } else {
                DB::run('INSERT INTO notes (user_id,title,body,color) VALUES (?,?,?,?)', [$u['id'], $title, $body, $color]);
            }
            flash('Notiz gespeichert.');
            redirect(url('notes'));
        }
        if ($action === 'del') {
            Security::verifyGet();
            DB::run('DELETE FROM notes WHERE id=? AND user_id=?', [param_int('id'), $u['id']]);
            flash('Notiz gelöscht.');
            redirect(url('notes'));
        }
        if ($action === 'pin') {
            Security::verifyGet();
            DB::run('UPDATE notes SET pinned=1-pinned WHERE id=? AND user_id=?', [param_int('id'), $u['id']]);
            redirect(url('notes'));
        }
    }

    public static function render(array $u): void
    {
        $notes = DB::all('SELECT * FROM notes WHERE user_id=? ORDER BY pinned DESC, updated_at DESC', [$u['id']]);
        View::topbar('Notizen', count($notes) . ' gespeichert',
            '<button class="btn" onclick="newNote()">' . Icons::icon('plus') . ' Neue Notiz</button>');

        if ($notes) {
            echo '<div class="masonry">';
            foreach ($notes as $n) {
                echo '<div class="note" style="--nc:' . h($n['color']) . '">';
                if ($n['pinned']) {
                    echo '<div style="position:absolute;top:13px;right:13px;color:' . h($n['color']) . '">' . Icons::icon('pin', 15) . '</div>';
                }
                if ($n['title']) {
                    echo '<h4>' . h($n['title']) . '</h4>';
                }
                echo '<div class="body">' . nl2br(h($n['body'])) . '</div>';
                echo '<div class="meta">' . Icons::icon('clock', 13) . ' ' . h(date('d.m.Y H:i', strtotime($n['updated_at'])));
                echo '<div class="acts">';
                echo '<a href="?app=notes&action=pin&id=' . $n['id'] . '&_csrf=' . Security::token() . '" title="Anheften">' . Icons::icon('pin', 15) . '</a>';
                echo '<a href="#" onclick="editNote(this);return false" data-id="' . $n['id'] . '" data-title="' . h($n['title']) . '" data-body="' . h($n['body']) . '" data-color="' . h($n['color']) . '" title="Bearbeiten">' . Icons::icon('edit', 15) . '</a>';
                echo '<a href="?app=notes&action=del&id=' . $n['id'] . '&_csrf=' . Security::token() . '" onclick="return confirm(\'Notiz löschen?\')" title="Löschen">' . Icons::icon('trash', 15) . '</a>';
                echo '</div></div></div>';
            }
            echo '</div>';
        } else {
            echo '<div class="empty">' . Icons::icon('note', 40) . '<h3>Noch keine Notizen</h3><p>Erstelle deine erste Notiz.</p></div>';
        }

        echo '<div class="modal" id="noteModal"><div class="box">';
        echo '<span class="modal-x" onclick="closeModal(\'noteModal\')">&times;</span><h3 id="noteModalTitle">Neue Notiz</h3>';
        echo '<form method="post" action="?app=notes&action=save" id="noteForm">' . Security::field();
        echo '<input type="hidden" name="id" value="">';
        echo '<div class="field"><label>Titel</label><input class="input" name="title" placeholder="Titel (optional)"></div>';
        echo '<div class="field"><label>Inhalt</label><textarea name="body" placeholder="Schreib etwas…" required></textarea></div>';
        echo '<div class="field"><label>Farbe</label>' . View::swatch('color', '#b3893f') . '</div>';
        echo '<button class="btn" type="submit" style="width:100%;justify-content:center">Speichern</button>';
        echo '</form></div></div>';
    }
}
