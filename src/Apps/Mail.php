<?php
declare(strict_types=1);

namespace Nexus\Apps;

use Nexus\Core\Database as DB;
use Nexus\Core\Icons;
use Nexus\Core\Security;
use Nexus\Core\View;
use Nexus\Services\Auth;
use Nexus\Services\Imap;
use Nexus\Services\InternalMail;
use Nexus\Services\Smtp;
use Nexus\Services\Tickets;

final class Mail
{
    /* ============================ HANDLE ============================ */
    public static function handle(array $u, string $action): void
    {
        if ($action === 'send_internal') {
            Security::verifyPost();
            self::sendInternal($u);
        }
        if ($action === 'del_internal') {
            Security::verifyGet();
            InternalMail::delete(param_int('id'), (int) $u['id']);
            flash('Nachricht gelöscht.');
            redirect(url('mail', ['box' => param('box', 'inbox')]));
        }
        if ($action === 'acc_save') {
            Security::verifyPost();
            DB::run('INSERT INTO mail_accounts (user_id,label,email,imap_host,imap_port,imap_enc,smtp_host,smtp_port,smtp_enc,username,enc_pass,validate_cert)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
                $u['id'], trim(param('label')), trim(param('email')),
                trim(param('imap_host')), param_int('imap_port', 993), param('imap_enc', 'ssl'),
                trim(param('smtp_host')), param_int('smtp_port', 465), param('smtp_enc', 'ssl'),
                trim(param('username')), Security::encrypt(param('password')),
                param('validate_cert') ? 1 : 0,
            ]);
            flash('Mail-Konto verbunden.');
            redirect(url('mail', ['ext' => DB::lastId()]));
        }
        if ($action === 'send_ext') {
            Security::verifyPost();
            $acc = self::account($u, param_int('acc'));
            if ($acc) {
                $res = Smtp::send($acc, trim(param('to')), param('subject'), param('body'));
                flash($res === true ? 'Nachricht gesendet.' : 'Fehler: ' . $res, $res === true ? 'ok' : 'err');
                redirect(url('mail', ['ext' => $acc['id']]));
            }
            redirect(url('mail'));
        }
    }

    private static function sendInternal(array $u): void
    {
        $body = trim(param('body'));
        if ($u['status'] === 'pending') {
            // Nur an den Admin, fester Betreff = Ticket-Code
            $admin = Auth::firstAdmin();
            $ticket = Tickets::forUser((int) $u['id']);
            if ($admin) {
                InternalMail::deliver((int) $u['id'], (int) $admin['id'],
                    $ticket['code'] ?? 'Anfrage', $body, $ticket['code'] ?? '', false);
                flash('Nachricht an den Administrator gesendet.');
            }
            redirect(url('mail', ['box' => 'sent']));
        }
        $to = param_int('to');
        $recip = DB::one("SELECT id FROM users WHERE id=? AND status!='suspended'", [$to]);
        if (!$recip) {
            flash('Empfänger nicht gefunden.', 'err');
            redirect(url('mail', ['compose' => 1]));
        }
        $res = InternalMail::deliver((int) $u['id'], $to, trim(param('subject')), $body);
        flash($res === true ? 'Nachricht gesendet.' : $res, $res === true ? 'ok' : 'err');
        redirect(url('mail', ['box' => 'sent']));
    }

    private static function account(array $u, int $id): ?array
    {
        return DB::one('SELECT * FROM mail_accounts WHERE id=? AND user_id=?', [$id, $u['id']]);
    }

    /* ============================ RENDER ============================ */
    public static function render(array $u): void
    {
        $ext = param('ext');
        if ($ext === 'new') {
            self::setupExternal($u);
            return;
        }
        if ($ext !== '' && ctype_digit($ext)) {
            self::renderExternal($u, (int) $ext);
            return;
        }
        if (param('compose')) {
            self::composeInternal($u);
            return;
        }
        $msg = param_int('msg');
        if ($msg) {
            self::readInternal($u, $msg);
            return;
        }
        self::renderInternal($u);
    }

    private static function tabs(array $u, string $active): string
    {
        $t = '<div class="mail-tabs">';
        $t .= '<a class="' . ($active === 'inbox' ? 'active' : '') . '" href="' . url('mail', ['box' => 'inbox']) . '">' . Icons::icon('inbox', 16) . ' Posteingang</a>';
        $t .= '<a class="' . ($active === 'sent' ? 'active' : '') . '" href="' . url('mail', ['box' => 'sent']) . '">' . Icons::icon('send', 16) . ' Gesendet</a>';
        // Externe Konten
        foreach (DB::all('SELECT id,label FROM mail_accounts WHERE user_id=? ORDER BY id', [$u['id']]) as $a) {
            $t .= '<a href="' . url('mail', ['ext' => $a['id']]) . '">' . Icons::icon('mail', 16) . ' ' . h($a['label']) . '</a>';
        }
        return $t . '</div>';
    }

    private static function renderInternal(array $u): void
    {
        $box = param('box', 'inbox') === 'sent' ? 'sent' : 'inbox';
        $actions = '<button class="btn" onclick="location=\'' . url('mail', ['compose' => 1]) . '\'">' . Icons::icon('send') . ' Schreiben</button>';
        View::topbar('Mail', 'Interne Nachrichten', $actions);
        echo self::tabs($u, $box);

        $rows = InternalMail::inbox((int) $u['id'], $box);
        if (!$rows) {
            echo '<div class="empty">' . Icons::icon('inbox', 40) . '<h3>' . ($box === 'sent' ? 'Nichts gesendet' : 'Posteingang leer') . '</h3></div>';
            return;
        }
        echo '<div class="mail-list">';
        foreach ($rows as $m) {
            $seen = $box === 'sent' ? true : (bool) $m['seen'];
            echo '<a class="mail-item ' . ($seen ? '' : 'unseen') . '" href="' . url('mail', ['msg' => $m['id'], 'box' => $box]) . '">';
            echo '<span style="width:9px;height:9px;border-radius:50%;flex:none;background:' . ($seen ? 'transparent' : 'var(--accent)') . '"></span>';
            echo '<span class="from">' . ($box === 'sent' ? '&rarr; ' : '') . h($m['other']) . '</span>';
            echo '<span class="subj">' . h($m['subject'] ?: '(kein Betreff)') . '</span>';
            if ($m['ticket_code']) {
                echo '<span class="tag-tic">' . h($m['ticket_code']) . '</span>';
            }
            echo '<span class="date">' . h(self::shortDate($m['created_at'])) . '</span></a>';
        }
        echo '</div>';
    }

    private static function readInternal(array $u, int $id): void
    {
        $m = InternalMail::get($id, (int) $u['id']);
        if (!$m) {
            redirect(url('mail'));
        }
        if ((int) $m['recipient_id'] === (int) $u['id']) {
            InternalMail::markSeen($id, (int) $u['id']);
        }
        $otherId = (int) $m['sender_id'] === (int) $u['id'] ? (int) $m['recipient_id'] : (int) $m['sender_id'];
        $other = DB::one('SELECT username FROM users WHERE id=?', [$otherId]);
        $box = param('box', 'inbox');

        View::topbar($m['subject'] ?: '(kein Betreff)', 'von ' . ($other['username'] ?? '—'),
            '<a class="btn ghost" href="' . url('mail', ['box' => $box]) . '">' . Icons::icon('back') . ' Zurück</a>'
            . '<a class="btn ghost danger" href="?app=mail&action=del_internal&id=' . $id . '&box=' . h($box) . '&_csrf=' . Security::token() . '" onclick="return confirm(\'Löschen?\')">' . Icons::icon('trash') . '</a>');

        echo '<div class="panel">';
        echo '<div style="display:flex;align-items:center;gap:12px;padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid var(--line)">';
        echo '<div class="avatar" style="width:38px;height:38px">' . h(strtoupper(substr($other['username'] ?? '?', 0, 1))) . '</div>';
        echo '<div style="flex:1"><strong>' . h($other['username'] ?? '—') . '</strong>';
        if ($m['ticket_code']) {
            echo ' <span class="tag-tic">' . h($m['ticket_code']) . '</span>';
        }
        echo '<br><small style="color:var(--muted)" class="mono">' . h(date('d.m.Y H:i', strtotime($m['created_at']))) . '</small></div></div>';
        echo '<div class="mail-body">' . self::linkify(h($m['body'])) . '</div></div>';
    }

    private static function composeInternal(array $u): void
    {
        $pending = $u['status'] === 'pending';
        View::topbar('Neue Nachricht', $pending ? 'An den Administrator' : 'Intern',
            '<a class="btn ghost" href="' . url('mail') . '">' . Icons::icon('back') . ' Abbrechen</a>');

        echo '<div class="panel" style="max-width:680px"><form method="post" action="?app=mail&action=send_internal">' . Security::field();
        if ($pending) {
            $ticket = Tickets::forUser((int) $u['id']);
            $admin = Auth::firstAdmin();
            echo '<div class="banner"><div>' . Icons::icon('ticket', 18) . '</div><div><b>Freischaltung ausstehend</b>'
                . '<p>Bis zur Freischaltung kannst du nur den Administrator kontaktieren. Betreff ist fest an dein Ticket gebunden.</p></div></div>';
            echo '<div class="field"><label>An</label><input class="input" value="' . h($admin['username'] ?? 'Administrator') . '" disabled></div>';
            echo '<div class="field"><label>Betreff (Ticket)</label><input class="input mono" value="' . h($ticket['code'] ?? '') . '" disabled></div>';
        } else {
            $users = DB::all("SELECT id,username FROM users WHERE id!=? AND status!='suspended' ORDER BY username", [$u['id']]);
            echo '<div class="field"><label>An</label><select name="to" required>';
            foreach ($users as $r) {
                echo '<option value="' . $r['id'] . '">' . h($r['username']) . '</option>';
            }
            echo '</select></div>';
            echo '<div class="field"><label>Betreff</label><input class="input" name="subject"></div>';
        }
        echo '<div class="field"><label>Nachricht</label><textarea name="body" style="min-height:200px" required></textarea></div>';
        echo '<button class="btn" type="submit">' . Icons::icon('send') . ' Senden</button>';
        echo '</form></div>';
    }

    /* ---------------------- Externe Konten (IMAP/SMTP) ---------------------- */
    private static function renderExternal(array $u, int $accId): void
    {
        $acc = self::account($u, $accId);
        if (!$acc) {
            self::setupExternal($u);
            return;
        }
        if (param('compose')) {
            self::composeExternal($u, $acc);
            return;
        }
        $uid = param_int('uid');
        View::topbar($acc['label'], $acc['email'],
            '<a class="btn ghost" href="' . url('mail') . '">' . Icons::icon('back') . '</a>'
            . '<a class="btn" href="' . url('mail', ['ext' => $acc['id'], 'compose' => 1]) . '">' . Icons::icon('send') . ' Schreiben</a>');
        echo self::tabs($u, '');

        if (!Imap::available()) {
            echo '<div class="alert err">Die PHP-Erweiterung <b>imap</b> ist nicht aktiv. Postfach-Lesen benötigt <code>extension=imap</code>. Senden per SMTP funktioniert dennoch.</div>';
            return;
        }
        if ($uid) {
            self::readExternal($acc, $uid);
            return;
        }
        $mbox = Imap::open($acc);
        if (!$mbox) {
            echo '<div class="alert err">Verbindung fehlgeschlagen: ' . h(Imap::lastError()) . '</div>';
            return;
        }
        $ov = Imap::recent($mbox, 40);
        if (!$ov) {
            echo '<div class="empty">' . Icons::icon('inbox', 40) . '<h3>Posteingang leer</h3></div>';
            imap_close($mbox);
            return;
        }
        echo '<div class="mail-list">';
        foreach ($ov as $o) {
            $seen = !empty($o->seen);
            echo '<a class="mail-item ' . ($seen ? '' : 'unseen') . '" href="' . url('mail', ['ext' => $acc['id'], 'uid' => $o->uid]) . '">';
            echo '<span style="width:9px;height:9px;border-radius:50%;flex:none;background:' . ($seen ? 'transparent' : 'var(--accent)') . '"></span>';
            echo '<span class="from">' . h(Imap::decodeHeader($o->from ?? '')) . '</span>';
            echo '<span class="subj">' . h(Imap::decodeHeader($o->subject ?? '(kein Betreff)')) . '</span>';
            echo '<span class="date">' . h(isset($o->udate) ? self::mailDate($o->udate) : '') . '</span></a>';
        }
        echo '</div>';
        imap_close($mbox);
    }

    private static function readExternal(array $acc, int $uid): void
    {
        $mbox = Imap::open($acc, false);
        if (!$mbox) {
            echo '<div class="alert err">Verbindung fehlgeschlagen.</div>';
            return;
        }
        $head = imap_headerinfo($mbox, imap_msgno($mbox, $uid));
        $from = Imap::decodeHeader($head->fromaddress ?? '');
        $body = Imap::bodyPref($mbox, $uid);
        echo '<div class="panel">';
        echo '<div style="display:flex;align-items:center;gap:12px;padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid var(--line)">';
        echo '<div class="avatar" style="width:38px;height:38px">' . h(strtoupper(substr($from, 0, 1))) . '</div>';
        echo '<div style="flex:1"><strong>' . h($from) . '</strong><br><small class="mono" style="color:var(--muted)">'
            . h(isset($head->udate) ? date('d.m.Y H:i', $head->udate) : '') . '</small></div>';
        echo '<a class="btn ghost sm" href="' . url('mail', ['ext' => $acc['id']]) . '">' . Icons::icon('back') . '</a></div>';
        if ($body['text'] !== '') {
            echo '<div class="mail-body">' . self::linkify(h($body['text'])) . '</div>';
        } elseif ($body['html'] !== '') {
            // Isoliert + eigene CSP: keine Skripte, keine Remote-Requests (kein Tracking)
            $csp = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'; font-src data:">';
            $safe = $csp . '<base target="_blank">' . self::sanitize($body['html']);
            echo '<iframe sandbox="" style="min-height:480px" srcdoc="' . h($safe) . '"></iframe>';
        } else {
            echo '<div class="empty">Kein darstellbarer Inhalt.</div>';
        }
        echo '</div>';
        Imap::markSeen($mbox, $uid);
        imap_close($mbox);
    }

    private static function composeExternal(array $u, array $acc): void
    {
        View::topbar('Neue Nachricht', 'von ' . $acc['email'],
            '<a class="btn ghost" href="' . url('mail', ['ext' => $acc['id']]) . '">' . Icons::icon('back') . ' Abbrechen</a>');
        echo '<div class="panel" style="max-width:680px"><form method="post" action="?app=mail&action=send_ext">' . Security::field();
        echo '<input type="hidden" name="acc" value="' . $acc['id'] . '">';
        echo '<div class="field"><label>An</label><input class="input" type="email" name="to" value="' . h(param('to')) . '" required></div>';
        echo '<div class="field"><label>Betreff</label><input class="input" name="subject"></div>';
        echo '<div class="field"><label>Nachricht</label><textarea name="body" style="min-height:240px" required></textarea></div>';
        echo '<button class="btn" type="submit">' . Icons::icon('send') . ' Senden</button>';
        echo '</form></div>';
    }

    private static function setupExternal(array $u): void
    {
        View::topbar('Mail-Konto verbinden', 'IMAP/SMTP');
        echo '<div class="panel" style="max-width:620px;margin:0 auto">';
        echo '<div style="text-align:center;margin-bottom:16px">' . Icons::icon('mail', 40) . '<h3 style="margin-top:8px">Externes Konto</h3>';
        echo '<p style="color:var(--muted)">Nur Zugangsdaten eingeben – Nexus verbindet sich direkt per IMAP/SMTP.</p></div>';
        echo '<form method="post" action="?app=mail&action=acc_save">' . Security::field();
        echo '<div class="field"><label>Bezeichnung</label><input class="input" name="label" placeholder="z. B. Privat" required></div>';
        echo '<div class="field"><label>E-Mail-Adresse</label><input class="input" type="email" name="email" required></div>';
        echo '<div class="row"><div class="field" style="flex:2"><label>IMAP-Server</label><input class="input" name="imap_host" placeholder="imap.example.com" required></div>';
        echo '<div class="field"><label>Port</label><input class="input" name="imap_port" value="993"></div>';
        echo '<div class="field"><label>Verschl.</label><select name="imap_enc"><option value="ssl">SSL</option><option value="tls">TLS</option><option value="notls">Keine</option></select></div></div>';
        echo '<div class="row"><div class="field" style="flex:2"><label>SMTP-Server</label><input class="input" name="smtp_host" placeholder="smtp.example.com" required></div>';
        echo '<div class="field"><label>Port</label><input class="input" name="smtp_port" value="465"></div>';
        echo '<div class="field"><label>Verschl.</label><select name="smtp_enc"><option value="ssl">SSL</option><option value="tls">STARTTLS</option></select></div></div>';
        echo '<div class="field"><label>Benutzername</label><input class="input" name="username" placeholder="oft die E-Mail-Adresse" required></div>';
        echo '<div class="field"><label>Passwort</label><input class="input" type="password" name="password" required></div>';
        echo '<label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--muted);margin-bottom:14px"><input type="checkbox" name="validate_cert" value="1" style="width:auto"> TLS-Zertifikat streng prüfen (empfohlen bei öffentlichen Servern)</label>';
        echo '<button class="btn" type="submit" style="width:100%;justify-content:center">Konto speichern</button>';
        echo '</form></div>';
    }

    /* ---------------------- Helfer ---------------------- */
    private static function shortDate(string $sql): string
    {
        $ts = strtotime($sql);
        return date('Y-m-d', $ts) === date('Y-m-d') ? date('H:i', $ts) : date('d.m.Y', $ts);
    }

    private static function mailDate(int $ts): string
    {
        if (date('Y-m-d', $ts) === date('Y-m-d')) {
            return date('H:i', $ts);
        }
        return (time() - $ts < 6 * 86400) ? date('D, H:i', $ts) : date('d.m.Y', $ts);
    }

    private static function linkify(string $text): string
    {
        return preg_replace('#(https?://[^\s<]+)#', '<a href="$1" target="_blank" rel="noopener" style="color:var(--accent)">$1</a>', $text);
    }

    private static function sanitize(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $html);
        $html = preg_replace("#\son\w+\s*=\s*'[^']*'#i", '', $html);
        return preg_replace('#javascript:#i', '', $html);
    }
}
