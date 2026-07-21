<?php
declare(strict_types=1);

namespace Nexus\Apps;

use Nexus\Core\Database as DB;
use Nexus\Core\Icons;
use Nexus\Core\Security;
use Nexus\Core\View;

final class Contacts
{
    public static function handle(array $u, string $action): void
    {
        if ($action === 'save') {
            Security::verifyPost();
            $id = param_int('id');
            $name = trim(param('name'));
            if ($name === '') {
                redirect(url('contacts'));
            }
            $data = [$name, trim(param('email')), trim(param('phone')), trim(param('note'))];
            if ($id) {
                DB::run('UPDATE contacts SET name=?,email=?,phone=?,note=? WHERE id=? AND user_id=?',
                    array_merge($data, [$id, $u['id']]));
            } else {
                DB::run('INSERT INTO contacts (name,email,phone,note,user_id) VALUES (?,?,?,?,?)',
                    array_merge($data, [$u['id']]));
            }
            flash('Kontakt gespeichert.');
            redirect(url('contacts'));
        }
        if ($action === 'del') {
            Security::verifyGet();
            DB::run('DELETE FROM contacts WHERE id=? AND user_id=?', [param_int('id'), $u['id']]);
            flash('Kontakt gelöscht.');
            redirect(url('contacts'));
        }
    }

    public static function render(array $u): void
    {
        $rows = DB::all('SELECT * FROM contacts WHERE user_id=? ORDER BY name COLLATE NOCASE', [$u['id']]);
        View::topbar('Kontakte', count($rows) . ' Einträge',
            '<button class="btn" onclick="newContact()">' . Icons::icon('plus') . ' Kontakt</button>');

        if ($rows) {
            echo '<div class="wrap-scroll"><table class="table"><thead><tr><th>Name</th><th>E-Mail</th><th>Telefon</th><th>Notiz</th><th></th></tr></thead><tbody>';
            foreach ($rows as $c) {
                echo '<tr><td><strong>' . h($c['name']) . '</strong></td>';
                echo '<td>' . ($c['email'] ? h($c['email']) : '<span style="color:var(--muted2)">—</span>') . '</td>';
                echo '<td class="mono">' . (h($c['phone']) ?: '—') . '</td>';
                echo '<td style="color:var(--muted)">' . h($c['note']) . '</td>';
                echo '<td><div class="actions">';
                echo '<a class="btn ghost sm" href="#" onclick="editContact(this);return false" data-id="' . $c['id'] . '" data-name="' . h($c['name']) . '" data-email="' . h($c['email']) . '" data-phone="' . h($c['phone']) . '" data-note="' . h($c['note']) . '">' . Icons::icon('edit', 14) . '</a>';
                echo '<a class="btn ghost sm" href="?app=contacts&action=del&id=' . $c['id'] . '&_csrf=' . Security::token() . '" onclick="return confirm(\'Kontakt löschen?\')">' . Icons::icon('trash', 14) . '</a>';
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="empty">' . Icons::icon('user', 40) . '<h3>Keine Kontakte</h3><p>Lege deinen ersten Kontakt an.</p></div>';
        }

        echo '<div class="modal" id="contactModal"><div class="box">';
        echo '<span class="modal-x" onclick="closeModal(\'contactModal\')">&times;</span><h3 id="contactModalTitle">Neuer Kontakt</h3>';
        echo '<form method="post" action="?app=contacts&action=save" id="contactForm">' . Security::field();
        echo '<input type="hidden" name="id" value="">';
        echo '<div class="field"><label>Name</label><input class="input" name="name" required></div>';
        echo '<div class="field"><label>E-Mail</label><input class="input" type="email" name="email"></div>';
        echo '<div class="field"><label>Telefon</label><input class="input" name="phone"></div>';
        echo '<div class="field"><label>Notiz</label><textarea name="note" style="min-height:70px"></textarea></div>';
        echo '<button class="btn" type="submit" style="width:100%;justify-content:center">Speichern</button>';
        echo '</form></div></div>';
    }
}
