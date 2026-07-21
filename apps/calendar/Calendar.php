<?php
declare(strict_types=1);

namespace Nexus\Apps;

use Nexus\Core\Database as DB;
use Nexus\Core\Icons;
use Nexus\Core\Security;
use Nexus\Core\View;

final class Calendar
{
    public static function handle(array $u, string $action): void
    {
        if ($action === 'save') {
            Security::verifyPost();
            $id = param_int('id');
            $title = trim(param('title'));
            $day = param('day');
            if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                redirect(url('calendar'));
            }
            $data = [$title, $day, param('time'), param('end_time'), trim(param('description')), param('color', '#c25a5a')];
            if ($id) {
                DB::run('UPDATE events SET title=?,day=?,time=?,end_time=?,description=?,color=? WHERE id=? AND user_id=?',
                    array_merge($data, [$id, $u['id']]));
            } else {
                DB::run('INSERT INTO events (title,day,time,end_time,description,color,user_id) VALUES (?,?,?,?,?,?,?)',
                    array_merge($data, [$u['id']]));
            }
            flash('Termin gespeichert.');
            redirect(url('calendar', ['m' => substr($day, 0, 7)]));
        }
        if ($action === 'del') {
            Security::verifyPost();
            DB::run('DELETE FROM events WHERE id=? AND user_id=?', [param_int('id'), $u['id']]);
            flash('Termin gelöscht.');
            redirect(url('calendar'));
        }
    }

    public static function render(array $u): void
    {
        $ym = param('m', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $ym = date('Y-m');
        }
        [$Y, $M] = array_map('intval', explode('-', $ym));
        $first = new \DateTime(sprintf('%04d-%02d-01', $Y, $M));
        $daysIn = (int) $first->format('t');
        $startDow = ((int) $first->format('N')) - 1;
        $prev = (clone $first)->modify('-1 month')->format('Y-m');
        $next = (clone $first)->modify('+1 month')->format('Y-m');

        $rows = DB::all('SELECT * FROM events WHERE user_id=? AND day LIKE ? ORDER BY time', [$u['id'], sprintf('%04d-%02d-%%', $Y, $M)]);
        $byDay = [];
        foreach ($rows as $e) {
            $byDay[$e['day']][] = $e;
        }

        $months = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
        View::topbar('Kalender', 'Termine & Ereignisse',
            '<button class="btn" onclick="openDay(\'' . date('Y-m-d') . '\')">' . Icons::icon('plus') . ' Termin</button>');

        echo '<div class="cal-head">';
        echo '<a class="iconbtn" href="' . url('calendar', ['m' => $prev]) . '">' . Icons::icon('chevL') . '</a>';
        echo '<h2>' . $months[$M] . ' ' . $Y . '</h2>';
        echo '<a class="iconbtn" href="' . url('calendar', ['m' => $next]) . '">' . Icons::icon('chevR') . '</a>';
        echo '<a class="btn ghost sm" href="' . url('calendar') . '">Heute</a></div>';

        echo '<div class="cal">';
        foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $d) {
            echo '<div class="dow">' . $d . '</div>';
        }
        $prevDays = (int) (clone $first)->modify('-1 month')->format('t');
        for ($i = $startDow; $i > 0; $i--) {
            echo '<div class="cell out"><span class="num">' . ($prevDays - $i + 1) . '</span></div>';
        }
        $today = date('Y-m-d');
        for ($d = 1; $d <= $daysIn; $d++) {
            $day = sprintf('%04d-%02d-%02d', $Y, $M, $d);
            echo '<div class="cell' . ($day === $today ? ' today' : '') . '" onclick="openDay(\'' . $day . '\')">';
            echo '<span class="num">' . $d . '</span>';
            foreach (($byDay[$day] ?? []) as $e) {
                echo '<div class="ev" style="--ec:' . h($e['color']) . '" title="' . h($e['title']) . '" onclick="editEvent(this)"'
                    . ' data-id="' . $e['id'] . '" data-title="' . h($e['title']) . '" data-day="' . h($e['day']) . '"'
                    . ' data-time="' . h($e['time']) . '" data-end="' . h($e['end_time']) . '" data-desc="' . h($e['description']) . '" data-color="' . h($e['color']) . '">';
                if ($e['time']) {
                    echo '<small>' . h(substr($e['time'], 0, 5)) . '</small>';
                }
                echo h($e['title']) . '</div>';
            }
            echo '</div>';
        }
        $filled = $startDow + $daysIn;
        for ($i = 0; $i < (7 - $filled % 7) % 7; $i++) {
            echo '<div class="cell out"><span class="num">' . ($i + 1) . '</span></div>';
        }
        echo '</div>';

        echo '<div class="modal" id="evModal"><div class="box">';
        echo '<span class="modal-x" onclick="closeModal(\'evModal\')">&times;</span><h3 id="evModalTitle">Termin</h3>';
        echo '<form method="post" action="?app=calendar&action=save" id="evForm">' . Security::field();
        echo '<input type="hidden" name="id" value="">';
        echo '<div class="field"><label>Titel</label><input class="input" name="title" required></div>';
        echo '<div class="field"><label>Datum</label><input class="input" type="date" name="day" required></div>';
        echo '<div class="row"><div class="field"><label>Von</label><input class="input" type="time" name="time"></div>';
        echo '<div class="field"><label>Bis</label><input class="input" type="time" name="end_time"></div></div>';
        echo '<div class="field"><label>Beschreibung</label><textarea name="description" style="min-height:70px"></textarea></div>';
        echo '<div class="field"><label>Farbe</label>' . View::swatch('color', '#c25a5a') . '</div>';
        echo '<div class="row"><button class="btn" type="submit" style="justify-content:center">Speichern</button>';
        echo '<button class="btn danger" type="submit" formaction="?app=calendar&action=del" style="flex:0 0 auto" onclick="return this.form.id.value?confirm(\'Termin löschen?\'):false">' . Icons::icon('trash') . '</button></div>';
        echo '</form></div></div>';
    }
}
