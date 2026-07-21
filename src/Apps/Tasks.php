<?php
declare(strict_types=1);

namespace Nexus\Apps;

use Nexus\Core\Database as DB;
use Nexus\Core\Icons;
use Nexus\Core\Security;
use Nexus\Core\View;

final class Tasks
{
    public static function handle(array $u, string $action): void
    {
        if ($action === 'add') {
            Security::verifyPost();
            $title = trim(param('title'));
            if ($title !== '') {
                DB::run('INSERT INTO tasks (user_id,title,due,priority) VALUES (?,?,?,?)',
                    [$u['id'], $title, param('due'), max(0, min(2, param_int('priority', 1)))]);
            }
            redirect(url('tasks'));
        }
        if ($action === 'toggle') {
            Security::verifyGet();
            DB::run('UPDATE tasks SET done=1-done WHERE id=? AND user_id=?', [param_int('id'), $u['id']]);
            redirect(url('tasks'));
        }
        if ($action === 'del') {
            Security::verifyGet();
            DB::run('DELETE FROM tasks WHERE id=? AND user_id=?', [param_int('id'), $u['id']]);
            redirect(url('tasks'));
        }
    }

    public static function render(array $u): void
    {
        $open = DB::all('SELECT * FROM tasks WHERE user_id=? AND done=0 ORDER BY priority DESC, (due=""), due, id', [$u['id']]);
        $done = DB::all('SELECT * FROM tasks WHERE user_id=? AND done=1 ORDER BY id DESC LIMIT 30', [$u['id']]);
        View::topbar('Aufgaben', count($open) . ' offen');

        echo '<div class="panel" style="margin-bottom:16px"><form method="post" action="?app=tasks&action=add" class="row" style="align-items:flex-end">' . Security::field();
        echo '<div class="field" style="flex:3;margin:0"><label>Neue Aufgabe</label><input class="input" name="title" placeholder="Was ist zu tun?" required></div>';
        echo '<div class="field" style="flex:1;margin:0"><label>Fällig</label><input class="input" type="date" name="due"></div>';
        echo '<div class="field" style="flex:1;margin:0"><label>Priorität</label><select name="priority"><option value="1">Normal</option><option value="2">Hoch</option><option value="0">Niedrig</option></select></div>';
        echo '<button class="btn" style="flex:0 0 auto">' . Icons::icon('plus') . '</button>';
        echo '</form></div>';

        $prioColor = ['#7a828e', '#4d7ea8', '#c25a5a'];
        $today = date('Y-m-d');
        if ($open) {
            echo '<div class="tasklist">';
            foreach ($open as $t) {
                echo '<div class="task">';
                echo '<span class="prio" style="background:' . $prioColor[$t['priority']] . '"></span>';
                echo '<a class="tcheck" href="?app=tasks&action=toggle&id=' . $t['id'] . '&_csrf=' . Security::token() . '">' . Icons::icon('square', 18) . '</a>';
                echo '<span class="ttitle">' . h($t['title']) . '</span>';
                if ($t['due']) {
                    $over = $t['due'] < $today;
                    echo '<span class="tdue' . ($over ? ' over' : '') . '">' . h(date('d.m.', strtotime($t['due']))) . '</span>';
                }
                echo '<a class="tdel" href="?app=tasks&action=del&id=' . $t['id'] . '&_csrf=' . Security::token() . '" onclick="return confirm(\'Aufgabe löschen?\')">' . Icons::icon('trash', 15) . '</a>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<div class="empty">' . Icons::icon('check', 40) . '<h3>Keine offenen Aufgaben</h3><p>Alles erledigt.</p></div>';
        }

        if ($done) {
            echo '<div class="section-h">' . Icons::icon('check') . ' Erledigt</div><div class="tasklist">';
            foreach ($done as $t) {
                echo '<div class="task done">';
                echo '<span class="prio" style="background:transparent"></span>';
                echo '<a class="tcheck" href="?app=tasks&action=toggle&id=' . $t['id'] . '&_csrf=' . Security::token() . '">' . Icons::icon('checkbox', 18) . '</a>';
                echo '<span class="ttitle">' . h($t['title']) . '</span>';
                echo '<a class="tdel" href="?app=tasks&action=del&id=' . $t['id'] . '&_csrf=' . Security::token() . '">' . Icons::icon('trash', 15) . '</a>';
                echo '</div>';
            }
            echo '</div>';
        }
    }
}
