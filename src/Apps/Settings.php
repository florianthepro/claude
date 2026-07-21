<?php
declare(strict_types=1);

namespace Nexus\Apps;

use Nexus\Core\Database as DB;
use Nexus\Core\Icons;
use Nexus\Core\Security;
use Nexus\Core\View;
use Nexus\Services\Imap;

final class Settings
{
    public static function handle(array $u, string $action): void
    {
        Security::verifyPost();
        switch ($action) {
            case 'profile':
                DB::run('UPDATE users SET display_name=?, email=? WHERE id=?',
                    [trim(param('display_name')) ?: $u['username'], trim(param('email')), $u['id']]);
                flash('Profil aktualisiert.');
                break;
            case 'appearance':
                $theme = param('theme') === 'light' ? 'light' : 'dark';
                $accent = preg_match('/^#[0-9a-f]{6}$/i', param('accent')) ? param('accent') : '#4d7ea8';
                DB::run('UPDATE users SET theme=?, accent=? WHERE id=?', [$theme, $accent, $u['id']]);
                flash('Aussehen übernommen.');
                break;
            case 'password':
                if (!password_verify(param('current'), $u['pass_hash'])) {
                    flash('Aktuelles Passwort falsch.', 'err');
                    break;
                }
                if (strlen(param('new')) < 8 || param('new') !== param('new2')) {
                    flash('Neues Passwort ungültig (min. 8 Zeichen) oder stimmt nicht überein.', 'err');
                    break;
                }
                DB::run('UPDATE users SET pass_hash=? WHERE id=?', [password_hash(param('new'), PASSWORD_DEFAULT), $u['id']]);
                flash('Passwort geändert.');
                break;
            case 'ticket_email':
                if ($u['role'] === 'admin') {
                    DB::run('UPDATE users SET ticket_email=? WHERE id=?', [trim(param('ticket_email')), $u['id']]);
                    flash('Ticket-Adresse gespeichert.');
                }
                break;
            case 'acc_del':
                DB::run('DELETE FROM mail_accounts WHERE id=? AND user_id=?', [param_int('id'), $u['id']]);
                flash('Mail-Konto entfernt.');
                break;
        }
        redirect(url('settings'));
    }

    public static function render(array $u): void
    {
        View::topbar('Einstellungen', 'Profil, Konten & Aussehen');

        echo '<div class="section-h">' . Icons::icon('user') . ' Profil</div><div class="panel" style="max-width:620px">';
        echo '<form method="post" action="?app=settings&action=profile">' . Security::field();
        echo '<div class="field"><label>Anzeigename</label><input class="input" name="display_name" value="' . h($u['display_name']) . '"></div>';
        echo '<div class="field"><label>E-Mail</label><input class="input" type="email" name="email" value="' . h($u['email']) . '"></div>';
        echo '<div class="field"><label>Benutzername</label><input class="input" value="' . h($u['username']) . '" disabled></div>';
        echo '<button class="btn">Speichern</button></form></div>';

        echo '<div class="section-h">' . Icons::icon('sun') . ' Aussehen</div><div class="panel" style="max-width:620px">';
        echo '<form method="post" action="?app=settings&action=appearance">' . Security::field();
        echo '<div class="field"><label>Theme</label><select name="theme">';
        foreach (['dark' => 'Dunkel', 'light' => 'Hell'] as $k => $v) {
            echo '<option value="' . $k . '" ' . ($u['theme'] === $k ? 'selected' : '') . '>' . $v . '</option>';
        }
        echo '</select></div>';
        echo '<div class="field"><label>Akzentfarbe</label>' . View::swatch('accent', $u['accent']) . '</div>';
        echo '<button class="btn">Übernehmen</button></form></div>';

        echo '<div class="section-h">' . Icons::icon('cog') . ' Passwort ändern</div><div class="panel" style="max-width:620px">';
        echo '<form method="post" action="?app=settings&action=password">' . Security::field();
        echo '<div class="field"><label>Aktuelles Passwort</label><input class="input" type="password" name="current" required></div>';
        echo '<div class="row"><div class="field"><label>Neues Passwort</label><input class="input" type="password" name="new" required></div>';
        echo '<div class="field"><label>Wiederholen</label><input class="input" type="password" name="new2" required></div></div>';
        echo '<button class="btn">Passwort ändern</button></form></div>';

        // Admin: Ticket-Adresse
        if ($u['role'] === 'admin') {
            echo '<div class="section-h">' . Icons::icon('ticket') . ' Ticket-Benachrichtigung</div><div class="panel" style="max-width:620px">';
            echo '<form method="post" action="?app=settings&action=ticket_email">' . Security::field();
            echo '<div class="field"><label>Externe Ticket-Adresse (optional)</label><input class="input" type="email" name="ticket_email" value="' . h($u['ticket_email']) . '" placeholder="admin@example.com"></div>';
            echo '<p style="color:var(--muted2);font-size:12.5px;margin-bottom:12px">Neue Registrierungen erzeugen intern immer ein Ticket. Ist hier eine Adresse hinterlegt und ein Mailkonto verbunden, wird zusätzlich eine E-Mail (Betreff = Ticket-Code) versendet.</p>';
            echo '<button class="btn">Speichern</button></form></div>';
        }

        // Mailkonten
        $accts = DB::all('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id', [$u['id']]);
        echo '<div class="section-h">' . Icons::icon('mail') . ' Externe Mail-Konten</div><div class="panel" style="max-width:620px">';
        if ($accts) {
            foreach ($accts as $a) {
                echo '<div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)">';
                echo '<div class="tico" style="width:34px;height:34px">' . Icons::icon('mail') . '</div>';
                echo '<div style="flex:1"><strong>' . h($a['label']) . '</strong><br><small style="color:var(--muted)">' . h($a['email']) . ' · ' . h($a['imap_host']) . ($a['validate_cert'] ? ' · cert✓' : '') . '</small></div>';
                echo '<form method="post" action="?app=settings&action=acc_del" onsubmit="return confirm(\'Konto entfernen?\')">' . Security::field() . '<input type="hidden" name="id" value="' . $a['id'] . '"><button class="btn ghost sm danger">' . Icons::icon('trash', 14) . '</button></form>';
                echo '</div>';
            }
        } else {
            echo '<p style="color:var(--muted);margin-bottom:12px">Noch kein externes Mail-Konto verbunden.</p>';
        }
        echo '<a class="btn ghost" href="' . url('mail', ['ext' => 'new']) . '" style="margin-top:12px">' . Icons::icon('plus') . ' Konto hinzufügen</a></div>';

        // System
        echo '<div class="section-h">' . Icons::icon('db') . ' System</div><div class="panel" style="max-width:620px">';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px">';
        $imap = Imap::available() ? '✓ aktiv' : '✗ nicht verfügbar';
        $ossl = function_exists('openssl_encrypt') ? '✓ aktiv' : '✗ Fallback';
        $gz = function_exists('gzdeflate') ? '✓ aktiv' : '✗ unkomprimiert';
        foreach ([
            'Version' => NX_NAME . ' ' . NX_VERSION, 'PHP' => PHP_VERSION,
            'IMAP-Erweiterung' => $imap, 'Verschlüsselung' => $ossl,
            'Mail-Kompression' => $gz, 'Datenbank' => 'SQLite',
        ] as $k => $v) {
            echo '<div><span style="color:var(--muted)">' . h($k) . '</span><br>' . h($v) . '</div>';
        }
        echo '</div></div>';
    }
}
