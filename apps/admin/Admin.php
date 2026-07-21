<?php
declare(strict_types=1);

namespace Nexus\Apps;

use Nexus\Core\Database as DB;
use Nexus\Core\Icons;
use Nexus\Core\Security;
use Nexus\Core\View;
use Nexus\Services\Auth;
use Nexus\Services\Quota;

final class Admin
{
    public static function handle(array $u, string $action): void
    {
        Security::verifyPost();
        $uid = param_int('uid');
        if ($uid === (int) $u['id'] && in_array($action, ['suspend', 'demote'], true)) {
            flash('Diese Aktion auf das eigene Konto ist nicht möglich.', 'err');
            redirect(url('admin'));
        }
        switch ($action) {
            case 'approve':   Auth::approve($uid, (int) $u['id']); flash('Konto freigeschaltet.'); break;
            case 'reject':    Auth::reject($uid, (int) $u['id']); flash('Registrierung abgelehnt.'); break;
            case 'suspend':   Auth::suspend($uid); flash('Konto gesperrt.'); break;
            case 'unsuspend': Auth::unsuspend($uid); flash('Sperre aufgehoben.'); break;
            case 'promote':   Auth::promote($uid); flash('Nutzer ist jetzt Administrator.'); break;
            case 'demote':    Auth::demote($uid); flash('Administratorrechte entzogen.'); break;
            case 'quota':
                $gb = (float) str_replace(',', '.', param('gb', '1'));
                Auth::setQuota($uid, (int) round($gb * 1024 * 1024 * 1024));
                flash('Quota angepasst.');
                break;
        }
        redirect(url('admin'));
    }

    public static function render(array $u): void
    {
        $pending = DB::all("SELECT * FROM users WHERE status='pending' ORDER BY id");
        View::topbar('Verwaltung', 'Nutzer, Freischaltung & Quota');

        // Offene Freischaltungen
        if ($pending) {
            echo '<div class="section-h">' . Icons::icon('ticket') . ' Offene Freischaltungen (' . count($pending) . ')</div>';
            echo '<div class="wrap-scroll"><table class="table"><thead><tr><th>Benutzer</th><th>E-Mail</th><th>Ticket</th><th>Registriert</th><th></th></tr></thead><tbody>';
            foreach ($pending as $p) {
                $t = DB::one('SELECT code FROM tickets WHERE user_id=? ORDER BY id DESC LIMIT 1', [$p['id']]);
                echo '<tr><td><strong>' . h($p['username']) . '</strong></td><td>' . h($p['email']) . '</td>';
                echo '<td class="mono">' . h($t['code'] ?? '—') . '</td>';
                echo '<td class="mono">' . h(date('d.m.Y', strtotime($p['created_at']))) . '</td>';
                echo '<td><div class="actions">';
                echo self::btn('approve', $p['id'], '<span class="btn ok sm">' . Icons::icon('check', 14) . ' Freischalten</span>');
                echo self::btn('reject', $p['id'], '<span class="btn ghost sm">Ablehnen</span>', 'Registrierung ablehnen und Konto sperren?');
                echo '</div></td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Alle Nutzer
        $users = DB::all('SELECT * FROM users ORDER BY role DESC, id');
        echo '<div class="section-h">' . Icons::icon('users') . ' Alle Nutzer (' . count($users) . ')</div>';
        echo '<div class="wrap-scroll"><table class="table"><thead><tr><th>Benutzer</th><th>Rolle</th><th>Status</th><th>Speicher</th><th>Aktionen</th></tr></thead><tbody>';
        foreach ($users as $r) {
            $used = Quota::used((int) $r['id']);
            $max = (int) $r['quota_bytes'];
            $pct = $max > 0 ? min(100, (int) round($used / $max * 100)) : 0;
            $self = (int) $r['id'] === (int) $u['id'];
            echo '<tr>';
            echo '<td><strong>' . h($r['username']) . '</strong>' . ($self ? ' <span class="chip">du</span>' : '') . '<br><small style="color:var(--muted)">' . h($r['email']) . '</small></td>';
            echo '<td><span class="badge ' . ($r['role'] === 'admin' ? 'admin' : '') . '">' . ($r['role'] === 'admin' ? 'Admin' : 'Nutzer') . '</span></td>';
            echo '<td><span class="badge ' . h($r['status']) . '">' . self::statusLabel($r['status']) . '</span></td>';
            echo '<td><div class="mono" style="font-size:11.5px;margin-bottom:3px">' . human_size($used) . ' / ' . human_size($max) . '</div>'
                . '<div class="quota-bar" style="width:120px"><i style="width:' . $pct . '%;background:' . ($pct >= 90 ? 'var(--err)' : 'var(--accent)') . '"></i></div></td>';
            echo '<td><div class="actions">';
            if ($r['status'] === 'pending') {
                echo self::btn('approve', $r['id'], '<span class="btn ok sm">Freischalten</span>');
            }
            if ($r['status'] === 'suspended') {
                echo self::btn('unsuspend', $r['id'], '<span class="btn ghost sm">Entsperren</span>');
            } elseif (!$self && $r['role'] !== 'admin') {
                echo self::btn('suspend', $r['id'], '<span class="btn ghost sm">' . Icons::icon('ban', 14) . '</span>', 'Konto sperren?');
            }
            echo '<button class="btn ghost sm" onclick="quotaModal(' . $r['id'] . ',\'' . h(addslashes($r['username'])) . '\',' . round($max / 1073741824, 2) . ')">' . Icons::icon('db', 14) . '</button>';
            if ($r['role'] === 'admin') {
                if (!$self) {
                    echo self::btn('demote', $r['id'], '<span class="btn ghost sm">Admin entziehen</span>', 'Administratorrechte entziehen?');
                }
            } else {
                echo self::btn('promote', $r['id'], '<span class="btn ghost sm">' . Icons::icon('shield', 14) . '</span>', 'Zum Administrator machen?');
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';

        // Quota-Modal
        echo '<div class="modal" id="quotaModal"><div class="box">';
        echo '<span class="modal-x" onclick="closeModal(\'quotaModal\')">&times;</span><h3>Quota für <span id="quotaUser" class="mono"></span></h3>';
        echo '<form method="post" action="?app=admin&action=quota">' . Security::field();
        echo '<input type="hidden" name="uid" value="">';
        echo '<div class="field"><label>Speicher in GB</label><input class="input" name="gb" type="number" step="0.5" min="0" required></div>';
        echo '<button class="btn" style="width:100%;justify-content:center">Speichern</button>';
        echo '</form></div></div>';
    }

    private static function btn(string $action, int $uid, string $inner, string $confirm = ''): string
    {
        $onsubmit = $confirm ? ' onsubmit="return confirm(\'' . h($confirm) . '\')"' : '';
        return '<form method="post" action="?app=admin&action=' . $action . '" style="display:inline"' . $onsubmit . '>'
            . Security::field() . '<input type="hidden" name="uid" value="' . $uid . '">'
            . '<button type="submit" style="background:none;border:0;padding:0;cursor:pointer">' . $inner . '</button></form>';
    }

    private static function statusLabel(string $s): string
    {
        return ['pending' => 'Wartet', 'active' => 'Aktiv', 'suspended' => 'Gesperrt'][$s] ?? $s;
    }
}
