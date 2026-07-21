<?php
/**
 * ============================================================================
 *  NEXUS LAUNCHER  —  Single-File PHP Application Launcher
 * ----------------------------------------------------------------------------
 *  Benötigt ausschließlich Apache + PHP (>= 7.4). Diese eine Datei legt beim
 *  ersten Aufruf selbstständig alles Weitere an:
 *
 *    /data/                 -> per .htaccess komplett gesperrt
 *    /data/.htaccess        -> "Require all denied"
 *    /data/sys/             -> System (SQLite-DB, Secret-Key, Sessions)
 *    /data/sys/app.sqlite   -> Benutzer, Notizen, Termine, Bookmarks, Mail
 *    /data/<app>/           -> je On-Site-App ein eigener Unterordner
 *
 *  Alle Apps laufen direkt auf dieser Seite (kein iframe, kein neuer Tab)
 *  und teilen sich ein einheitliches Design.
 * ============================================================================
 */

declare(strict_types=1);
session_name('NEXUSSID');

/* ------------------------------------------------------------------ *
 *  0. PFADE / KONSTANTEN
 * ------------------------------------------------------------------ */
define('APP_NAME',   'Nexus');
define('ROOT_DIR',   __DIR__);
define('DATA_DIR',   ROOT_DIR . '/data');
define('SYS_DIR',    DATA_DIR . '/sys');
define('DB_FILE',    SYS_DIR  . '/app.sqlite');
define('SECRET_FILE',SYS_DIR  . '/secret.key');
define('SESSION_DIR',SYS_DIR  . '/sessions');
define('APP_VERSION','1.0.0');

/* ------------------------------------------------------------------ *
 *  1. BOOTSTRAP — legt Ordnerstruktur, .htaccess, Secret & DB an
 * ------------------------------------------------------------------ */

/** Registry aller On-Site-Apps. Jede bekommt einen Ordner unter /data/. */
function app_registry(): array {
    return [
        'home' => [
            'name'  => 'Startseite',
            'desc'  => 'Übersicht & Schnellzugriffe',
            'icon'  => 'grid',
            'color' => '#6366f1',
            'tile'  => false,
        ],
        'notes' => [
            'name'  => 'Notizen',
            'desc'  => 'Gedanken, Listen & Snippets',
            'icon'  => 'note',
            'color' => '#f59e0b',
            'tile'  => true,
        ],
        'calendar' => [
            'name'  => 'Kalender',
            'desc'  => 'Termine & Ereignisse',
            'icon'  => 'calendar',
            'color' => '#ef4444',
            'tile'  => true,
        ],
        'mail' => [
            'name'  => 'Mail',
            'desc'  => 'IMAP/SMTP Postfach',
            'icon'  => 'mail',
            'color' => '#0ea5e9',
            'tile'  => true,
        ],
        'files' => [
            'name'  => 'Dateien',
            'desc'  => 'Persönlicher Speicher',
            'icon'  => 'folder',
            'color' => '#10b981',
            'tile'  => true,
        ],
        'bookmarks' => [
            'name'  => 'Lesezeichen',
            'desc'  => 'Links & Kacheln verwalten',
            'icon'  => 'link',
            'color' => '#8b5cf6',
            'tile'  => true,
        ],
        'settings' => [
            'name'  => 'Einstellungen',
            'desc'  => 'Profil, Konten & Aussehen',
            'icon'  => 'cog',
            'color' => '#64748b',
            'tile'  => true,
        ],
    ];
}

function bootstrap(): void {
    $dirs = [DATA_DIR, SYS_DIR, SESSION_DIR];
    // je App ein eigener Datenordner unter /data/
    foreach (array_keys(app_registry()) as $appId) {
        if ($appId === 'home' || $appId === 'settings') continue;
        $dirs[] = DATA_DIR . '/' . $appId;
    }

    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0770, true);
        }
    }
    if (!is_dir(DATA_DIR)) {
        http_response_code(500);
        exit('Fehler: /data konnte nicht erstellt werden. Schreibrechte prüfen.');
    }

    // --- /data komplett per .htaccess sperren -----------------------
    $htaccess = DATA_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        $rules = <<<HTA
        # Automatisch von Nexus erstellt – /data ist komplett gesperrt.
        # Apache 2.4
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        # Apache 2.2 (Fallback)
        <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
        </IfModule>
        # Directory-Listing zusätzlich unterbinden
        Options -Indexes
        HTA;
        @file_put_contents($htaccess, preg_replace('/^        /m', '', $rules));
    }

    // Sicherheitsnetz: index in /data, falls .htaccess mal ignoriert wird
    $guard = "<?php http_response_code(403); exit('Zugriff verweigert.');";
    foreach ([DATA_DIR, SYS_DIR] as $d) {
        $idx = $d . '/index.php';
        if (!file_exists($idx)) @file_put_contents($idx, $guard);
    }

    // --- Secret-Key für Verschlüsselung -----------------------------
    if (!file_exists(SECRET_FILE)) {
        $key = bin2hex(random_bytes(32));
        @file_put_contents(SECRET_FILE, $key);
        @chmod(SECRET_FILE, 0600);
    }

    // --- Session-Speicher in /data/sys/sessions ---------------------
    if (is_dir(SESSION_DIR) && is_writable(SESSION_DIR)) {
        session_save_path(SESSION_DIR);
    }

    // --- Datenbank & Migrationen ------------------------------------
    db_migrate();
}

/* ------------------------------------------------------------------ *
 *  2. DATENBANK-LAYER (SQLite via PDO)
 * ------------------------------------------------------------------ */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
    }
    return $pdo;
}

function db_migrate(): void {
    $sql = [
        "CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT UNIQUE NOT NULL,
            email         TEXT,
            pass_hash     TEXT NOT NULL,
            display_name  TEXT,
            theme         TEXT DEFAULT 'dark',
            accent        TEXT DEFAULT '#6366f1',
            is_admin      INTEGER DEFAULT 0,
            created_at    TEXT DEFAULT (datetime('now'))
        )",
        "CREATE TABLE IF NOT EXISTS notes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            title      TEXT DEFAULT '',
            body       TEXT DEFAULT '',
            color      TEXT DEFAULT '#f59e0b',
            pinned     INTEGER DEFAULT 0,
            updated_at TEXT DEFAULT (datetime('now')),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            title       TEXT NOT NULL,
            day         TEXT NOT NULL,        -- YYYY-MM-DD
            time        TEXT DEFAULT '',      -- HH:MM
            end_time    TEXT DEFAULT '',
            description TEXT DEFAULT '',
            color       TEXT DEFAULT '#ef4444',
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS bookmarks (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id  INTEGER NOT NULL,
            title    TEXT NOT NULL,
            url      TEXT NOT NULL,
            color    TEXT DEFAULT '#8b5cf6',
            position INTEGER DEFAULT 0,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS mail_accounts (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL,
            label       TEXT NOT NULL,
            email       TEXT NOT NULL,
            imap_host   TEXT NOT NULL,
            imap_port   INTEGER DEFAULT 993,
            imap_enc    TEXT DEFAULT 'ssl',
            smtp_host   TEXT NOT NULL,
            smtp_port   INTEGER DEFAULT 465,
            smtp_enc    TEXT DEFAULT 'ssl',
            username    TEXT NOT NULL,
            enc_pass    TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
    ];
    foreach ($sql as $stmt) db()->exec($stmt);
}

/* ------------------------------------------------------------------ *
 *  3. SICHERHEIT (CSRF, Escaping, Verschlüsselung)
 * ------------------------------------------------------------------ */
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = $_POST['_csrf'] ?? '';
        if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
            http_response_code(419);
            exit('Sitzung abgelaufen oder ungültiges Token. Bitte Seite neu laden.');
        }
    }
}

function secret_key(): string {
    return (string)@file_get_contents(SECRET_FILE);
}

/** Symmetrische Verschlüsselung für Mail-Passwörter (AES-256-GCM). */
function enc(string $plain): string {
    $key = hash('sha256', secret_key(), true);
    if (function_exists('openssl_encrypt')) {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }
    // Fallback (nur wenn openssl fehlt)
    return 'x1:' . base64_encode($plain ^ str_pad('', strlen($plain), $key));
}

function dec(string $blob): string {
    $key = hash('sha256', secret_key(), true);
    if (str_starts_with($blob, 'v1:') && function_exists('openssl_decrypt')) {
        $raw = base64_decode(substr($blob, 3));
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? '' : $pt;
    }
    if (str_starts_with($blob, 'x1:')) {
        $ct = base64_decode(substr($blob, 3));
        return $ct ^ str_pad('', strlen($ct), $key);
    }
    return '';
}

/* ------------------------------------------------------------------ *
 *  4. AUTHENTIFIZIERUNG
 * ------------------------------------------------------------------ */
function current_user(): ?array {
    static $cached = false, $user = null;
    if ($cached) return $user;
    $cached = true;
    if (empty($_SESSION['uid'])) return $user = null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $user = ($st->fetch() ?: null);
}

function require_login(): array {
    $u = current_user();
    if (!$u) { redirect('?view=login'); }
    return $u;
}

function user_count(): int {
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

function do_register(string $user, string $email, string $pass, string $pass2): array {
    $user = trim($user);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $user))
        return ['err' => 'Benutzername: 3–32 Zeichen (A–Z, 0–9, _.-).'];
    if (strlen($pass) < 6)
        return ['err' => 'Passwort muss mindestens 6 Zeichen haben.'];
    if ($pass !== $pass2)
        return ['err' => 'Passwörter stimmen nicht überein.'];
    $st = db()->prepare('SELECT 1 FROM users WHERE username = ?');
    $st->execute([$user]);
    if ($st->fetch()) return ['err' => 'Benutzername bereits vergeben.'];

    $isAdmin = user_count() === 0 ? 1 : 0; // erster Benutzer = Admin
    $st = db()->prepare('INSERT INTO users (username,email,pass_hash,display_name,is_admin)
                         VALUES (?,?,?,?,?)');
    $st->execute([$user, trim($email), password_hash($pass, PASSWORD_DEFAULT), $user, $isAdmin]);
    $_SESSION['uid'] = (int)db()->lastInsertId();
    session_regenerate_id(true);
    return ['ok' => true];
}

function do_login(string $user, string $pass): array {
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([trim($user)]);
    $row = $st->fetch();
    if (!$row || !password_verify($pass, $row['pass_hash'])) {
        return ['err' => 'Benutzername oder Passwort falsch.'];
    }
    $_SESSION['uid'] = (int)$row['id'];
    session_regenerate_id(true);
    return ['ok' => true];
}

/* ------------------------------------------------------------------ *
 *  5. HELFER
 * ------------------------------------------------------------------ */
function redirect(string $to): void { header('Location: ' . $to); exit; }

function param(string $k, $def = ''): string {
    $v = $_GET[$k] ?? $_POST[$k] ?? $def;
    return is_string($v) ? $v : (string)$def;
}

function flash(?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function current_app(): string {
    $a = param('app', 'home');
    return array_key_exists($a, app_registry()) ? $a : 'home';
}

function url(string $app, array $q = []): string {
    return '?app=' . urlencode($app) . ($q ? '&' . http_build_query($q) : '');
}

/* ------------------------------------------------------------------ *
 *  6. ICONS (inline SVG)
 * ------------------------------------------------------------------ */
function icon(string $name, int $size = 20): string {
    $p = [
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'note'     => '<path d="M4 4h16v12l-4 4H4z"/><path d="M16 20v-4h4"/><path d="M8 9h8M8 13h5"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'folder'   => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
        'cog'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'trash'    => '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/>',
        'pin'      => '<path d="M12 17v5M9 3h6l-1 6 3 3H7l3-3z"/>',
        'chevL'    => '<path d="M15 18l-6-6 6-6"/>',
        'chevR'    => '<path d="M9 18l6-6-6-6"/>',
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'send'     => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/>',
        'inbox'    => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5 5h14l3 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
        'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        'search'   => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4-4"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>',
        'moon'     => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'back'     => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'reply'    => '<path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 0 1 5 5v2"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    $body = $p[$name] ?? '<circle cx="12" cy="12" r="9"/>';
    return '<svg class="ic" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
         . $body . '</svg>';
}

/* ================================================================== *
 *  7. ASSETS (CSS / JS über ?asset= ausgeliefert -> cachefähig)
 * ================================================================== */
function serve_asset(string $which): void {
    if ($which === 'css') {
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo asset_css();
    } elseif ($which === 'js') {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo asset_js();
    }
    exit;
}

function asset_css(): string {
    return <<<CSS
:root{
  --accent:#6366f1;
  --bg:#0b0f1a; --bg2:#121829; --panel:#161d31; --panel2:#1c2440;
  --line:#26304d; --txt:#e7ecf5; --muted:#8b95ad; --muted2:#5f6a85;
  --ok:#10b981; --warn:#f59e0b; --err:#ef4444;
  --radius:16px; --radius-sm:10px;
  --shadow:0 10px 30px rgba(0,0,0,.35);
  --sidebar:260px;
}
[data-theme="light"]{
  --bg:#eef1f7; --bg2:#e3e8f2; --panel:#ffffff; --panel2:#f4f6fb;
  --line:#dbe1ee; --txt:#1a2138; --muted:#5c6784; --muted2:#8a93ad;
  --shadow:0 10px 30px rgba(30,41,80,.12);
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
  font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
  background:
    radial-gradient(1200px 600px at 80% -10%, color-mix(in srgb,var(--accent) 18%, transparent), transparent),
    radial-gradient(900px 500px at -10% 110%, color-mix(in srgb,var(--accent) 12%, transparent), transparent),
    var(--bg);
  color:var(--txt); line-height:1.5; -webkit-font-smoothing:antialiased;
}
a{color:inherit;text-decoration:none}
.ic{display:block;flex:none}
button,input,select,textarea{font:inherit;color:inherit}

/* ---------- App-Shell ---------- */
.shell{display:grid;grid-template-columns:var(--sidebar) 1fr;min-height:100vh}
.sidebar{
  background:color-mix(in srgb,var(--panel) 88%, transparent);
  border-right:1px solid var(--line);
  padding:22px 16px;display:flex;flex-direction:column;gap:6px;
  position:sticky;top:0;height:100vh;backdrop-filter:blur(12px);
}
.brand{display:flex;align-items:center;gap:12px;padding:6px 10px 18px;font-weight:700;font-size:20px}
.brand .logo{
  width:38px;height:38px;border-radius:11px;display:grid;place-items:center;color:#fff;
  background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 55%, #000));
  box-shadow:0 6px 18px color-mix(in srgb,var(--accent) 45%, transparent);
}
.nav{display:flex;flex-direction:column;gap:4px;margin-top:4px}
.nav a{
  display:flex;align-items:center;gap:13px;padding:11px 13px;border-radius:12px;
  color:var(--muted);font-weight:500;font-size:14.5px;transition:.15s;
}
.nav a:hover{background:var(--panel2);color:var(--txt)}
.nav a.active{background:color-mix(in srgb,var(--accent) 16%, transparent);color:var(--txt)}
.nav a.active .ic{color:var(--accent)}
.nav .dot{width:8px;height:8px;border-radius:50%;margin-left:auto}
.side-foot{margin-top:auto;display:flex;flex-direction:column;gap:4px;padding-top:12px;border-top:1px solid var(--line)}
.side-user{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:12px}
.avatar{width:34px;height:34px;border-radius:10px;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:700}
.side-user small{color:var(--muted);display:block;font-size:12px}

/* ---------- Main ---------- */
.main{padding:26px 34px 60px;max-width:1300px;width:100%}
.topbar{display:flex;align-items:center;gap:16px;margin-bottom:26px}
.topbar h1{font-size:24px;font-weight:700;letter-spacing:-.3px}
.topbar .sub{color:var(--muted);font-size:14px;margin-top:2px}
.spacer{flex:1}
.iconbtn{
  width:40px;height:40px;border-radius:11px;border:1px solid var(--line);
  background:var(--panel);display:grid;place-items:center;cursor:pointer;color:var(--txt);transition:.15s;
}
.iconbtn:hover{background:var(--panel2);border-color:var(--accent)}

/* ---------- Buttons / Forms ---------- */
.btn{
  display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:11px;
  background:var(--accent);color:#fff;border:none;cursor:pointer;font-weight:600;font-size:14px;transition:.15s;
}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px)}
.btn.ghost{background:var(--panel);color:var(--txt);border:1px solid var(--line)}
.btn.ghost:hover{background:var(--panel2)}
.btn.danger{background:var(--err)}
.btn.sm{padding:7px 12px;font-size:13px}
.field{margin-bottom:15px}
.field label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px;font-weight:500}
.input,textarea,select{
  width:100%;padding:11px 13px;border-radius:11px;border:1px solid var(--line);
  background:var(--bg2);color:var(--txt);transition:.15s;outline:none;
}
.input:focus,textarea:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 20%,transparent)}
textarea{resize:vertical;min-height:110px;font-family:inherit}
.row{display:flex;gap:12px;flex-wrap:wrap}
.row>*{flex:1;min-width:0}

/* ---------- Cards / Panels ---------- */
.panel{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:22px;box-shadow:var(--shadow)}
.grid{display:grid;gap:18px}
.tiles{grid-template-columns:repeat(auto-fill,minmax(210px,1fr))}
.tile{
  position:relative;overflow:hidden;background:var(--panel);border:1px solid var(--line);
  border-radius:var(--radius);padding:22px;cursor:pointer;transition:.18s;display:flex;flex-direction:column;gap:14px;min-height:150px;
}
.tile:hover{transform:translateY(-4px);border-color:color-mix(in srgb,var(--tc) 60%,var(--line));box-shadow:0 16px 40px color-mix(in srgb,var(--tc) 22%,transparent)}
.tile .tico{width:48px;height:48px;border-radius:13px;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,var(--tc),color-mix(in srgb,var(--tc) 55%,#000))}
.tile h3{font-size:17px;font-weight:650}
.tile p{color:var(--muted);font-size:13.5px;margin-top:-6px}
.tile .glow{position:absolute;right:-40px;top:-40px;width:140px;height:140px;border-radius:50%;background:var(--tc);opacity:.08;filter:blur(10px)}
.section-h{display:flex;align-items:center;gap:10px;margin:30px 0 16px;font-size:16px;font-weight:650;color:var(--muted)}
.section-h::after{content:"";flex:1;height:1px;background:var(--line)}

/* ---------- Notes ---------- */
.masonry{columns:280px;column-gap:18px}
.note{
  break-inside:avoid;margin-bottom:18px;border-radius:var(--radius);padding:18px;
  border:1px solid var(--line);background:var(--panel);border-left:4px solid var(--nc,var(--accent));position:relative;
}
.note h4{margin-bottom:8px;font-size:16px}
.note .body{color:var(--muted);white-space:pre-wrap;font-size:14px;max-height:320px;overflow:auto}
.note .meta{margin-top:12px;display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted2)}
.note .acts{margin-left:auto;display:flex;gap:4px}
.note .acts a{padding:5px;border-radius:8px;color:var(--muted)}
.note .acts a:hover{background:var(--panel2);color:var(--txt)}

/* ---------- Calendar ---------- */
.cal-head{display:flex;align-items:center;gap:14px;margin-bottom:18px}
.cal-head h2{font-size:20px;min-width:210px;text-align:center}
.cal{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
.cal .dow{text-align:center;color:var(--muted);font-size:12px;font-weight:600;padding:4px 0;text-transform:uppercase;letter-spacing:.5px}
.cal .cell{
  background:var(--panel);border:1px solid var(--line);border-radius:12px;min-height:104px;padding:8px;
  display:flex;flex-direction:column;gap:4px;transition:.15s;cursor:pointer;
}
.cal .cell:hover{border-color:var(--accent)}
.cal .cell.out{opacity:.4}
.cal .cell.today{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent) inset}
.cal .cell .num{font-size:13px;font-weight:600;color:var(--muted)}
.cal .cell.today .num{color:var(--accent)}
.ev{font-size:11.5px;padding:3px 6px;border-radius:6px;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:var(--ec,var(--accent))}
.ev small{opacity:.85;font-weight:600;margin-right:3px}

/* ---------- Mail ---------- */
.mail-list{display:flex;flex-direction:column;gap:2px}
.mail-item{display:flex;gap:14px;padding:13px 16px;border-radius:12px;border:1px solid transparent;cursor:pointer;transition:.12s;align-items:center}
.mail-item:hover{background:var(--panel2);border-color:var(--line)}
.mail-item.unseen{font-weight:600}
.mail-item .from{width:200px;flex:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mail-item .subj{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)}
.mail-item.unseen .subj{color:var(--txt)}
.mail-item .date{color:var(--muted2);font-size:12px;flex:none}
.mail-body{white-space:pre-wrap;line-height:1.7;padding:14px 2px}
.mail-body iframe{width:100%;border:0;background:#fff;border-radius:10px}

/* ---------- Files ---------- */
.file-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px}
.file-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px;text-align:center;position:relative;transition:.15s}
.file-card:hover{border-color:var(--accent);transform:translateY(-2px)}
.file-card .fi{color:var(--accent);display:grid;place-items:center;margin-bottom:8px}
.file-card .fn{font-size:13px;word-break:break-word}
.file-card .fs{font-size:11px;color:var(--muted2);margin-top:3px}
.file-card .fdel{position:absolute;top:6px;right:6px;opacity:0;transition:.15s;padding:4px;border-radius:7px;color:var(--muted)}
.file-card:hover .fdel{opacity:1}
.file-card .fdel:hover{background:var(--err);color:#fff}
.crumb{display:flex;gap:6px;align-items:center;color:var(--muted);margin-bottom:16px;font-size:14px}
.dropzone{border:2px dashed var(--line);border-radius:14px;padding:26px;text-align:center;color:var(--muted);margin-bottom:20px;transition:.15s}
.dropzone.drag{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 8%,transparent)}

/* ---------- Auth ---------- */
.auth-wrap{min-height:100vh;display:grid;place-items:center;padding:20px}
.auth-card{width:100%;max-width:400px;background:var(--panel);border:1px solid var(--line);border-radius:22px;padding:36px;box-shadow:var(--shadow)}
.auth-card .logo{width:56px;height:56px;border-radius:16px;margin:0 auto 18px;display:grid;place-items:center;color:#fff;
  background:linear-gradient(135deg,var(--accent),color-mix(in srgb,var(--accent) 55%,#000));box-shadow:0 8px 24px color-mix(in srgb,var(--accent) 45%,transparent)}
.auth-card h1{text-align:center;font-size:23px;margin-bottom:4px}
.auth-card .tag{text-align:center;color:var(--muted);margin-bottom:26px;font-size:14px}
.auth-switch{text-align:center;margin-top:18px;color:var(--muted);font-size:14px}
.auth-switch a{color:var(--accent);font-weight:600}

/* ---------- Alerts / Chips ---------- */
.alert{padding:12px 15px;border-radius:12px;margin-bottom:16px;font-size:14px;border:1px solid}
.alert.err{background:color-mix(in srgb,var(--err) 12%,transparent);border-color:color-mix(in srgb,var(--err) 40%,transparent);color:var(--err)}
.alert.ok{background:color-mix(in srgb,var(--ok) 12%,transparent);border-color:color-mix(in srgb,var(--ok) 40%,transparent);color:var(--ok)}
.chip{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;background:var(--panel2);border:1px solid var(--line);font-size:12.5px;color:var(--muted)}
.empty{text-align:center;padding:60px 20px;color:var(--muted)}
.empty .ic{margin:0 auto 14px;color:var(--muted2);width:48px;height:48px}
.swatch{display:flex;gap:8px;flex-wrap:wrap}
.swatch label{width:30px;height:30px;border-radius:8px;cursor:pointer;position:relative;border:2px solid transparent}
.swatch input{position:absolute;opacity:0}
.swatch input:checked+span{outline:2px solid var(--txt);outline-offset:2px}
.swatch span{position:absolute;inset:0;border-radius:6px}

/* ---------- Modal ---------- */
.modal{position:fixed;inset:0;background:rgba(4,7,16,.66);backdrop-filter:blur(4px);display:none;place-items:center;z-index:50;padding:20px}
.modal.open{display:grid}
.modal .box{background:var(--panel);border:1px solid var(--line);border-radius:20px;padding:26px;width:100%;max-width:520px;box-shadow:var(--shadow);max-height:90vh;overflow:auto}
.modal h3{margin-bottom:18px;font-size:19px}
.modal-x{position:absolute;top:16px;right:18px;cursor:pointer;color:var(--muted);font-size:22px;line-height:1}

/* ---------- Responsive ---------- */
.menu-toggle{display:none}
@media(max-width:880px){
  .shell{grid-template-columns:1fr}
  .sidebar{position:fixed;left:-300px;z-index:40;width:270px;transition:.25s;box-shadow:var(--shadow)}
  .sidebar.open{left:0}
  .menu-toggle{display:grid}
  .main{padding:18px 16px 60px}
  .mail-item .from{width:120px}
  .backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:39;display:none}
  .backdrop.open{display:block}
}
CSS;
}

function asset_js(): string {
    return <<<'JS'
// Theme
function applyTheme(t){document.documentElement.setAttribute('data-theme',t);localStorage.setItem('nx_theme',t);}
(function(){const t=localStorage.getItem('nx_theme');if(t)applyTheme(t);})();
function toggleTheme(){const c=document.documentElement.getAttribute('data-theme')||'dark';applyTheme(c==='dark'?'light':'dark');
  fetch('?action=save_theme&theme='+(c==='dark'?'light':'dark'));}
// Sidebar (mobile)
function toggleMenu(){document.querySelector('.sidebar')?.classList.toggle('open');document.querySelector('.backdrop')?.classList.toggle('open');}
// Modals
function openModal(id){document.getElementById(id)?.classList.add('open');}
function closeModal(id){if(id){document.getElementById(id)?.classList.remove('open');}else{document.querySelectorAll('.modal.open').forEach(m=>m.classList.remove('open'));}}
document.addEventListener('click',e=>{if(e.target.classList.contains('modal'))e.target.classList.remove('open');});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});
// Prefill edit modal for notes
function editNote(el){
  const d=el.dataset;openModal('noteModal');
  const f=document.getElementById('noteForm');
  f.id.value=d.id;f.title.value=d.title;f.body.value=d.body;
  f.querySelector('[name=color][value="'+d.color+'"]')?.click();
  document.getElementById('noteModalTitle').textContent='Notiz bearbeiten';
}
function newNote(){const f=document.getElementById('noteForm');f.reset();f.id.value='';
  document.getElementById('noteModalTitle').textContent='Neue Notiz';openModal('noteModal');}
// Calendar day modal
function openDay(day){openModal('evModal');const f=document.getElementById('evForm');f.reset();f.id.value='';f.day.value=day;
  document.getElementById('evModalTitle').textContent='Termin am '+day;}
function editEvent(el){const d=el.dataset;event.stopPropagation();openModal('evModal');const f=document.getElementById('evForm');
  f.id.value=d.id;f.title.value=d.title;f.day.value=d.day;f.time.value=d.time;f.end_time.value=d.end||'';f.description.value=d.desc;
  f.querySelector('[name=color][value="'+d.color+'"]')?.click();document.getElementById('evModalTitle').textContent='Termin bearbeiten';}
// Files drag&drop
function initDrop(){const dz=document.getElementById('dropzone');if(!dz)return;const inp=document.getElementById('fileInput');
  ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.add('drag');}));
  ['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.remove('drag');}));
  dz.addEventListener('drop',e=>{inp.files=e.dataTransfer.files;document.getElementById('uploadForm').submit();});
  dz.addEventListener('click',()=>inp.click());
  inp.addEventListener('change',()=>document.getElementById('uploadForm').submit());}
document.addEventListener('DOMContentLoaded',initDrop);
JS;
}

/* ================================================================== *
 *  8. LAYOUT (Kopf, Sidebar, Footer)  — einheitliches Design
 * ================================================================== */
function layout_head(array $user, string $activeApp): void {
    $reg   = app_registry();
    $theme = $user['theme'] ?? 'dark';
    $accent= $user['accent'] ?? '#6366f1';
    $meta  = $reg[$activeApp];
    echo '<!doctype html><html lang="de" data-theme="'.h($theme).'"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.h($meta['name']).' · '.APP_NAME.'</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="?asset=css&v='.APP_VERSION.'">';
    echo '<style>:root{--accent:'.h($accent).'}</style>';
    echo '<link rel="icon" href="data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24" rx="6" fill="'.$accent.'"/><text x="12" y="17" font-size="14" fill="white" text-anchor="middle" font-family="Arial" font-weight="bold">N</text></svg>').'">';
    echo '</head><body>';

    echo '<div class="backdrop" onclick="toggleMenu()"></div>';
    echo '<div class="shell">';

    // -- Sidebar -----------------------------------------------------
    echo '<aside class="sidebar">';
    echo '<div class="brand"><div class="logo">'.icon('grid',22).'</div>'.APP_NAME.'</div>';
    echo '<nav class="nav">';
    foreach ($reg as $id => $a) {
        $active = $id === $activeApp ? ' active' : '';
        echo '<a class="'.$active.'" href="'.url($id).'">'.icon($a['icon']).'<span>'.h($a['name']).'</span>';
        echo '<span class="dot" style="background:'.h($a['color']).'"></span></a>';
    }
    echo '</nav>';
    echo '<div class="side-foot">';
    echo '<a class="side-user" href="'.url('settings').'">';
    echo '<div class="avatar">'.h(strtoupper(substr($user['display_name'] ?: $user['username'],0,1))).'</div>';
    echo '<div><strong>'.h($user['display_name'] ?: $user['username']).'</strong><small>'.($user['is_admin']?'Administrator':'Benutzer').'</small></div></a>';
    echo '<a class="nav" style="display:flex;align-items:center;gap:13px;padding:11px 13px;border-radius:12px;color:var(--muted)" href="?action=logout">'.icon('logout').'<span>Abmelden</span></a>';
    echo '</div>';
    echo '</aside>';

    // -- Main-Container-Start ----------------------------------------
    echo '<main class="main">';
}

function layout_topbar(string $title, string $sub = '', string $actions = ''): void {
    echo '<div class="topbar">';
    echo '<button class="iconbtn menu-toggle" onclick="toggleMenu()">'.icon('grid').'</button>';
    echo '<div><h1>'.h($title).'</h1>';
    if ($sub) echo '<div class="sub">'.h($sub).'</div>';
    echo '</div><div class="spacer"></div>';
    echo $actions;
    $isDark = (current_user()['theme'] ?? 'dark') === 'dark';
    echo '<button class="iconbtn" title="Theme wechseln" onclick="toggleTheme()">'.icon($isDark?'sun':'moon').'</button>';
    echo '</div>';
    if ($f = flash()) echo '<div class="alert ok">'.h($f).'</div>';
}

function layout_foot(): void {
    echo '</main></div>';
    echo '<script src="?asset=js&v='.APP_VERSION.'"></script>';
    echo '</body></html>';
}

/* ================================================================== *
 *  9. AUTH-VIEW (Login / Registrierung)
 * ================================================================== */
function view_auth(string $mode, ?string $err = null): void {
    $accent = '#6366f1';
    echo '<!doctype html><html lang="de" data-theme="dark"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>'.($mode==='register'?'Registrieren':'Anmelden').' · '.APP_NAME.'</title>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="?asset=css">';
    echo '<script>const t=localStorage.getItem("nx_theme");if(t)document.documentElement.setAttribute("data-theme",t);</script>';
    echo '</head><body><div class="auth-wrap"><div class="auth-card">';
    echo '<div class="logo">'.icon('grid',28).'</div>';

    $first = user_count() === 0;
    if ($mode === 'register') {
        echo '<h1>'.($first?'Willkommen bei '.APP_NAME:'Konto erstellen').'</h1>';
        echo '<p class="tag">'.($first?'Lege das erste (Admin-)Konto an.':'Registriere dich, um loszulegen.').'</p>';
    } else {
        echo '<h1>Willkommen zurück</h1><p class="tag">Melde dich bei '.APP_NAME.' an.</p>';
    }
    if ($err) echo '<div class="alert err">'.h($err).'</div>';

    echo '<form method="post" action="?action='.($mode==='register'?'register':'login').'">';
    echo csrf_field();
    echo '<div class="field"><label>Benutzername</label><input class="input" name="username" autofocus autocomplete="username" required value="'.h(param('username')).'"></div>';
    if ($mode === 'register') {
        echo '<div class="field"><label>E-Mail (optional)</label><input class="input" type="email" name="email" autocomplete="email" value="'.h(param('email')).'"></div>';
    }
    echo '<div class="field"><label>Passwort</label><input class="input" type="password" name="password" autocomplete="'.($mode==='register'?'new-password':'current-password').'" required></div>';
    if ($mode === 'register') {
        echo '<div class="field"><label>Passwort bestätigen</label><input class="input" type="password" name="password2" autocomplete="new-password" required></div>';
    }
    echo '<button class="btn" style="width:100%;justify-content:center" type="submit">'.($mode==='register'?'Konto erstellen':'Anmelden').'</button>';
    echo '</form>';

    if ($mode === 'register') {
        echo '<div class="auth-switch">Bereits registriert? <a href="?view=login">Anmelden</a></div>';
    } else {
        echo '<div class="auth-switch">Noch kein Konto? <a href="?view=register">Registrieren</a></div>';
    }
    echo '</div></div><script src="?asset=js"></script></body></html>';
}

/* ================================================================== *
 *  10. FARB-SWATCH-HELFER
 * ================================================================== */
function color_swatch(string $field, string $current, array $colors = []): string {
    if (!$colors) $colors = ['#6366f1','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#64748b'];
    $out = '<div class="swatch">';
    foreach ($colors as $c) {
        $chk = strcasecmp($c,$current)===0 ? 'checked' : '';
        $out .= '<label><input type="radio" name="'.$field.'" value="'.$c.'" '.$chk.'><span style="background:'.$c.'"></span></label>';
    }
    $out .= '</div>';
    return $out;
}

/* ================================================================== *
 *  11. APP: HOME / DASHBOARD
 * ================================================================== */
function app_home(array $u): void {
    $reg = app_registry();
    $hour = (int)date('G');
    $greet = $hour < 5 ? 'Gute Nacht' : ($hour < 11 ? 'Guten Morgen' : ($hour < 18 ? 'Guten Tag' : 'Guten Abend'));

    // Statistiken
    $notesN = q1('SELECT COUNT(*) FROM notes WHERE user_id=?', [$u['id']]);
    $todayEv = db()->prepare('SELECT * FROM events WHERE user_id=? AND day=? ORDER BY time');
    $todayEv->execute([$u['id'], date('Y-m-d')]);
    $todayEv = $todayEv->fetchAll();
    $marksN = q1('SELECT COUNT(*) FROM bookmarks WHERE user_id=?', [$u['id']]);

    layout_topbar($greet.', '.h($u['display_name'] ?: $u['username']).'!', date('l, j. F Y', strtotime('today')));

    // Schnellzugriff-Bookmarks
    $bm = db()->prepare('SELECT * FROM bookmarks WHERE user_id=? ORDER BY position,id');
    $bm->execute([$u['id']]);
    $bm = $bm->fetchAll();
    if ($bm) {
        echo '<div class="section-h">'.icon('link').' Schnellzugriff</div>';
        echo '<div class="grid tiles">';
        foreach ($bm as $b) {
            $c = $b['color'];
            echo '<a class="tile" style="--tc:'.h($c).';min-height:auto" href="'.h($b['url']).'" target="_blank" rel="noopener">';
            echo '<div class="glow"></div><div class="tico" style="width:40px;height:40px">'.icon('link').'</div>';
            echo '<div><h3 style="font-size:15px">'.h($b['title']).'</h3><p style="font-size:12px;word-break:break-all">'.h(preg_replace('#^https?://#','',$b['url'])).'</p></div></a>';
        }
        echo '</div>';
    }

    // Apps
    echo '<div class="section-h">'.icon('grid').' Apps</div>';
    echo '<div class="grid tiles">';
    foreach ($reg as $id => $a) {
        if (empty($a['tile'])) continue;
        $badge = '';
        if ($id === 'notes'    && $notesN)          $badge = $notesN.' Notizen';
        if ($id === 'calendar' && count($todayEv))  $badge = count($todayEv).' heute';
        if ($id === 'bookmarks'&& $marksN)          $badge = $marksN.' Links';
        echo '<a class="tile" style="--tc:'.h($a['color']).'" href="'.url($id).'">';
        echo '<div class="glow"></div><div class="tico">'.icon($a['icon'],24).'</div>';
        echo '<div><h3>'.h($a['name']).'</h3><p>'.h($a['desc']).'</p></div>';
        if ($badge) echo '<div class="chip" style="align-self:flex-start">'.h($badge).'</div>';
        echo '</a>';
    }
    echo '</div>';

    // Heutige Termine
    echo '<div class="section-h">'.icon('calendar').' Heute</div>';
    echo '<div class="panel">';
    if ($todayEv) {
        foreach ($todayEv as $e) {
            echo '<div style="display:flex;align-items:center;gap:14px;padding:11px 0;border-bottom:1px solid var(--line)">';
            echo '<span style="width:10px;height:10px;border-radius:50%;background:'.h($e['color']).';flex:none"></span>';
            echo '<strong style="min-width:64px">'.h($e['time'] ?: '–').'</strong>';
            echo '<div style="flex:1"><div>'.h($e['title']).'</div>';
            if ($e['description']) echo '<small style="color:var(--muted)">'.h($e['description']).'</small>';
            echo '</div></div>';
        }
    } else {
        echo '<div class="empty" style="padding:30px">'.icon('clock',40).'<p>Keine Termine für heute. Genieß den Tag! 🎉</p></div>';
    }
    echo '</div>';
}

/* ================================================================== *
 *  12. APP: NOTIZEN
 * ================================================================== */
function app_notes(array $u): void {
    $st = db()->prepare('SELECT * FROM notes WHERE user_id=? ORDER BY pinned DESC, updated_at DESC');
    $st->execute([$u['id']]);
    $notes = $st->fetchAll();

    $actions = '<button class="btn" onclick="newNote()">'.icon('plus').' Neue Notiz</button>';
    layout_topbar('Notizen', count($notes).' gespeichert', $actions);

    if ($notes) {
        echo '<div class="masonry">';
        foreach ($notes as $n) {
            echo '<div class="note" style="--nc:'.h($n['color']).'">';
            if ($n['pinned']) echo '<div style="position:absolute;top:14px;right:14px;color:'.h($n['color']).'">'.icon('pin',16).'</div>';
            if ($n['title']) echo '<h4>'.h($n['title']).'</h4>';
            echo '<div class="body">'.nl2br(h($n['body'])).'</div>';
            echo '<div class="meta">'.icon('clock',13).' '.h(date('d.m.Y H:i', strtotime($n['updated_at'])));
            echo '<div class="acts">';
            echo '<a href="?app=notes&action=note_pin&id='.$n['id'].'&_csrf='.csrf_token().'" title="Anheften">'.icon('pin',16).'</a>';
            echo '<a href="#" onclick="editNote(this);return false" data-id="'.$n['id'].'" data-title="'.h($n['title']).'" data-body="'.h($n['body']).'" data-color="'.h($n['color']).'" title="Bearbeiten">'.icon('edit',16).'</a>';
            echo '<a href="?app=notes&action=note_del&id='.$n['id'].'&_csrf='.csrf_token().'" onclick="return confirm(\'Notiz löschen?\')" title="Löschen">'.icon('trash',16).'</a>';
            echo '</div></div></div>';
        }
        echo '</div>';
    } else {
        echo '<div class="empty">'.icon('note',48).'<h3>Noch keine Notizen</h3><p>Erstelle deine erste Notiz.</p></div>';
    }

    // Modal
    echo '<div class="modal" id="noteModal"><div class="box" style="position:relative">';
    echo '<span class="modal-x" onclick="closeModal(\'noteModal\')">&times;</span>';
    echo '<h3 id="noteModalTitle">Neue Notiz</h3>';
    echo '<form method="post" action="?app=notes&action=note_save" id="noteForm">'.csrf_field();
    echo '<input type="hidden" name="id" value="">';
    echo '<div class="field"><label>Titel</label><input class="input" name="title" placeholder="Titel (optional)"></div>';
    echo '<div class="field"><label>Inhalt</label><textarea name="body" placeholder="Schreib etwas…" required></textarea></div>';
    echo '<div class="field"><label>Farbe</label>'.color_swatch('color','#f59e0b').'</div>';
    echo '<button class="btn" type="submit" style="width:100%;justify-content:center">Speichern</button>';
    echo '</form></div></div>';
}

function handle_notes(array $u, string $action): void {
    if ($action === 'note_save') {
        csrf_check();
        $id = (int)param('id');
        $title = trim(param('title'));
        $body  = trim(param('body'));
        $color = param('color','#f59e0b');
        if ($body === '' && $title === '') redirect(url('notes'));
        if ($id) {
            $st = db()->prepare('UPDATE notes SET title=?,body=?,color=?,updated_at=datetime(\'now\') WHERE id=? AND user_id=?');
            $st->execute([$title,$body,$color,$id,$u['id']]);
        } else {
            $st = db()->prepare('INSERT INTO notes (user_id,title,body,color) VALUES (?,?,?,?)');
            $st->execute([$u['id'],$title,$body,$color]);
        }
        flash('Notiz gespeichert.');
        redirect(url('notes'));
    }
    if ($action === 'note_del') {
        csrf_check_get();
        db()->prepare('DELETE FROM notes WHERE id=? AND user_id=?')->execute([(int)param('id'),$u['id']]);
        flash('Notiz gelöscht.');
        redirect(url('notes'));
    }
    if ($action === 'note_pin') {
        csrf_check_get();
        db()->prepare('UPDATE notes SET pinned = 1 - pinned WHERE id=? AND user_id=?')->execute([(int)param('id'),$u['id']]);
        redirect(url('notes'));
    }
}

/* ================================================================== *
 *  13. APP: KALENDER
 * ================================================================== */
function app_calendar(array $u): void {
    $ym = param('m', date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    [$Y,$M] = array_map('intval', explode('-', $ym));
    $first = new DateTime(sprintf('%04d-%02d-01', $Y, $M));
    $daysIn = (int)$first->format('t');
    $startDow = ((int)$first->format('N')) - 1; // Mo=0
    $prev = (clone $first)->modify('-1 month')->format('Y-m');
    $next = (clone $first)->modify('+1 month')->format('Y-m');

    // Events dieses Monats
    $st = db()->prepare('SELECT * FROM events WHERE user_id=? AND day LIKE ? ORDER BY time');
    $st->execute([$u['id'], sprintf('%04d-%02d-%%', $Y, $M)]);
    $byDay = [];
    foreach ($st->fetchAll() as $e) $byDay[$e['day']][] = $e;

    $months = ['','Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    layout_topbar('Kalender', 'Termine & Ereignisse verwalten',
        '<button class="btn" onclick="openDay(\''.date('Y-m-d').'\')">'.icon('plus').' Termin</button>');

    echo '<div class="cal-head">';
    echo '<a class="iconbtn" href="'.url('calendar',['m'=>$prev]).'">'.icon('chevL').'</a>';
    echo '<h2>'.$months[$M].' '.$Y.'</h2>';
    echo '<a class="iconbtn" href="'.url('calendar',['m'=>$next]).'">'.icon('chevR').'</a>';
    echo '<a class="btn ghost sm" href="'.url('calendar').'">Heute</a>';
    echo '</div>';

    echo '<div class="cal">';
    foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $d) echo '<div class="dow">'.$d.'</div>';

    // Vormonat-Füller
    $prevDays = (int)(clone $first)->modify('-1 month')->format('t');
    for ($i=$startDow; $i>0; $i--) {
        echo '<div class="cell out"><span class="num">'.($prevDays-$i+1).'</span></div>';
    }
    $today = date('Y-m-d');
    for ($d=1; $d<=$daysIn; $d++) {
        $day = sprintf('%04d-%02d-%02d', $Y, $M, $d);
        $isToday = $day === $today ? ' today' : '';
        echo '<div class="cell'.$isToday.'" onclick="openDay(\''.$day.'\')">';
        echo '<span class="num">'.$d.'</span>';
        foreach (($byDay[$day] ?? []) as $e) {
            echo '<div class="ev" style="--ec:'.h($e['color']).'" title="'.h($e['title']).'" '
               . 'onclick="editEvent(this)" data-id="'.$e['id'].'" data-title="'.h($e['title']).'" data-day="'.h($e['day']).'" '
               . 'data-time="'.h($e['time']).'" data-end="'.h($e['end_time']).'" data-desc="'.h($e['description']).'" data-color="'.h($e['color']).'">';
            if ($e['time']) echo '<small>'.h(substr($e['time'],0,5)).'</small>';
            echo h($e['title']).'</div>';
        }
        echo '</div>';
    }
    // Nachmonat-Füller
    $filled = $startDow + $daysIn;
    for ($i=0; $i < (7 - $filled % 7) % 7; $i++) {
        echo '<div class="cell out"><span class="num">'.($i+1).'</span></div>';
    }
    echo '</div>';

    // Modal
    echo '<div class="modal" id="evModal"><div class="box" style="position:relative">';
    echo '<span class="modal-x" onclick="closeModal(\'evModal\')">&times;</span>';
    echo '<h3 id="evModalTitle">Termin</h3>';
    echo '<form method="post" action="?app=calendar&action=ev_save" id="evForm">'.csrf_field();
    echo '<input type="hidden" name="id" value="">';
    echo '<div class="field"><label>Titel</label><input class="input" name="title" required></div>';
    echo '<div class="field"><label>Datum</label><input class="input" type="date" name="day" required></div>';
    echo '<div class="row"><div class="field"><label>Von</label><input class="input" type="time" name="time"></div>';
    echo '<div class="field"><label>Bis</label><input class="input" type="time" name="end_time"></div></div>';
    echo '<div class="field"><label>Beschreibung</label><textarea name="description" style="min-height:70px"></textarea></div>';
    echo '<div class="field"><label>Farbe</label>'.color_swatch('color','#ef4444').'</div>';
    echo '<div class="row"><button class="btn" type="submit" style="justify-content:center">Speichern</button>';
    echo '<button class="btn danger" type="submit" formaction="?app=calendar&action=ev_del" style="flex:0 0 auto" onclick="return this.form.id.value?confirm(\'Termin löschen?\'):false">'.icon('trash').'</button></div>';
    echo '</form></div></div>';
}

function handle_calendar(array $u, string $action): void {
    if ($action === 'ev_save') {
        csrf_check();
        $id = (int)param('id');
        $title = trim(param('title'));
        $day = param('day');
        if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$day)) redirect(url('calendar'));
        $data = [$title,$day,param('time'),param('end_time'),trim(param('description')),param('color','#ef4444')];
        if ($id) {
            $st = db()->prepare('UPDATE events SET title=?,day=?,time=?,end_time=?,description=?,color=? WHERE id=? AND user_id=?');
            $st->execute(array_merge($data,[$id,$u['id']]));
        } else {
            $st = db()->prepare('INSERT INTO events (title,day,time,end_time,description,color,user_id) VALUES (?,?,?,?,?,?,?)');
            $st->execute(array_merge($data,[$u['id']]));
        }
        flash('Termin gespeichert.');
        redirect(url('calendar',['m'=>substr($day,0,7)]));
    }
    if ($action === 'ev_del') {
        csrf_check();
        db()->prepare('DELETE FROM events WHERE id=? AND user_id=?')->execute([(int)param('id'),$u['id']]);
        flash('Termin gelöscht.');
        redirect(url('calendar'));
    }
}

/* ================================================================== *
 *  14. APP: LESEZEICHEN
 * ================================================================== */
function app_bookmarks(array $u): void {
    $st = db()->prepare('SELECT * FROM bookmarks WHERE user_id=? ORDER BY position,id');
    $st->execute([$u['id']]);
    $bm = $st->fetchAll();

    layout_topbar('Lesezeichen', count($bm).' Links · erscheinen auf der Startseite',
        '<button class="btn" onclick="openModal(\'bmModal\')">'.icon('plus').' Link hinzufügen</button>');

    if ($bm) {
        echo '<div class="grid tiles">';
        foreach ($bm as $b) {
            echo '<div class="tile" style="--tc:'.h($b['color']).';min-height:auto">';
            echo '<div class="glow"></div>';
            echo '<a href="'.h($b['url']).'" target="_blank" rel="noopener" style="display:flex;gap:14px;align-items:center">';
            echo '<div class="tico" style="width:40px;height:40px">'.icon('link').'</div>';
            echo '<div style="min-width:0"><h3 style="font-size:15px">'.h($b['title']).'</h3>';
            echo '<p style="font-size:12px;word-break:break-all;margin:0">'.h(preg_replace('#^https?://#','',$b['url'])).'</p></div></a>';
            echo '<div style="display:flex;gap:6px;margin-top:auto">';
            echo '<a class="btn ghost sm" href="?app=bookmarks&action=bm_del&id='.$b['id'].'&_csrf='.csrf_token().'" onclick="return confirm(\'Löschen?\')">'.icon('trash',15).'</a>';
            echo '</div></div>';
        }
        echo '</div>';
    } else {
        echo '<div class="empty">'.icon('link',48).'<h3>Keine Lesezeichen</h3><p>Füge Links hinzu — sie erscheinen als Kacheln auf der Startseite.</p></div>';
    }

    echo '<div class="modal" id="bmModal"><div class="box" style="position:relative">';
    echo '<span class="modal-x" onclick="closeModal(\'bmModal\')">&times;</span>';
    echo '<h3>Lesezeichen hinzufügen</h3>';
    echo '<form method="post" action="?app=bookmarks&action=bm_save">'.csrf_field();
    echo '<div class="field"><label>Titel</label><input class="input" name="title" required></div>';
    echo '<div class="field"><label>URL</label><input class="input" type="url" name="url" placeholder="https://…" required></div>';
    echo '<div class="field"><label>Farbe</label>'.color_swatch('color','#8b5cf6').'</div>';
    echo '<button class="btn" type="submit" style="width:100%;justify-content:center">Hinzufügen</button>';
    echo '</form></div></div>';
}

function handle_bookmarks(array $u, string $action): void {
    if ($action === 'bm_save') {
        csrf_check();
        $title = trim(param('title'));
        $urlv  = trim(param('url'));
        if (!preg_match('#^https?://#i',$urlv)) $urlv = 'https://'.$urlv;
        if ($title && $urlv) {
            $st = db()->prepare('INSERT INTO bookmarks (user_id,title,url,color) VALUES (?,?,?,?)');
            $st->execute([$u['id'],$title,$urlv,param('color','#8b5cf6')]);
            flash('Lesezeichen hinzugefügt.');
        }
        redirect(url('bookmarks'));
    }
    if ($action === 'bm_del') {
        csrf_check_get();
        db()->prepare('DELETE FROM bookmarks WHERE id=? AND user_id=?')->execute([(int)param('id'),$u['id']]);
        flash('Lesezeichen gelöscht.');
        redirect(url('bookmarks'));
    }
}

/* ================================================================== *
 *  15. APP: DATEIEN (Sandbox: /data/files/<user_id>/)
 * ================================================================== */
function files_root(array $u): string {
    $root = DATA_DIR . '/files/' . $u['id'];
    if (!is_dir($root)) @mkdir($root, 0770, true);
    return realpath($root) ?: $root;
}

/** Sicherer Pfad innerhalb der Sandbox (verhindert ../ Traversal). */
function files_resolve(array $u, string $rel): ?string {
    $root = files_root($u);
    $rel  = str_replace('\\','/',$rel);
    $rel  = ltrim($rel,'/');
    $full = $root . ($rel === '' ? '' : '/' . $rel);
    $real = realpath($full);
    if ($real === false) {
        // Zielordner muss existieren -> Elternteil prüfen
        $real = realpath(dirname($full));
        if ($real === false || strncmp($real, $root, strlen($root)) !== 0) return null;
        return $full;
    }
    if (strncmp($real, $root, strlen($root)) !== 0) return null;
    return $real;
}

function human_size(int $b): string {
    $u = ['B','KB','MB','GB','TB']; $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, $b < 10 && $i ? 1 : 0) . ' ' . $u[$i];
}

function app_files(array $u): void {
    $rel = trim(param('p',''), '/');
    $dir = files_resolve($u, $rel);
    if ($dir === null || !is_dir($dir)) { $rel = ''; $dir = files_root($u); }

    layout_topbar('Dateien', 'Persönlicher Speicher in /data/files/',
        '<form method="post" action="?app=files&action=mkdir" style="display:flex;gap:8px">'.csrf_field().
        '<input type="hidden" name="p" value="'.h($rel).'">'.
        '<input class="input" name="name" placeholder="Neuer Ordner" style="width:150px;padding:8px 12px">'.
        '<button class="btn ghost sm">'.icon('plus').'</button></form>');

    // Breadcrumb
    echo '<div class="crumb"><a href="'.url('files').'">'.icon('folder',16).' home</a>';
    $acc = '';
    foreach (array_filter(explode('/', $rel)) as $part) {
        $acc .= ($acc ? '/' : '') . $part;
        echo ' / <a href="'.url('files',['p'=>$acc]).'">'.h($part).'</a>';
    }
    echo '</div>';

    // Upload-Dropzone
    echo '<form method="post" action="?app=files&action=upload" enctype="multipart/form-data" id="uploadForm">'.csrf_field();
    echo '<input type="hidden" name="p" value="'.h($rel).'">';
    echo '<input type="file" name="files[]" id="fileInput" multiple hidden>';
    echo '<div class="dropzone" id="dropzone">'.icon('upload',32).'<div style="margin-top:8px">Dateien hierher ziehen oder klicken zum Hochladen</div></div>';
    echo '</form>';

    // Inhalt
    $entries = @scandir($dir) ?: [];
    $dirs = $filesArr = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        $full = $dir . '/' . $e;
        if (is_dir($full)) $dirs[] = $e; else $filesArr[] = $e;
    }
    sort($dirs); sort($filesArr);

    if (!$dirs && !$filesArr) {
        echo '<div class="empty">'.icon('folder',48).'<h3>Leerer Ordner</h3><p>Lade Dateien hoch oder erstelle einen Unterordner.</p></div>';
        return;
    }

    echo '<div class="file-grid">';
    foreach ($dirs as $d) {
        $child = ($rel ? $rel.'/' : '').$d;
        echo '<a class="file-card" href="'.url('files',['p'=>$child]).'">';
        echo '<a class="fdel" href="?app=files&action=rm&p='.urlencode($child).'&_csrf='.csrf_token().'" onclick="event.stopPropagation();return confirm(\'Ordner (inkl. Inhalt) löschen?\')">'.icon('trash',15).'</a>';
        echo '<div class="fi">'.icon('folder',34).'</div><div class="fn">'.h($d).'</div><div class="fs">Ordner</div></a>';
    }
    foreach ($filesArr as $f) {
        $child = ($rel ? $rel.'/' : '').$f;
        $size = human_size((int)@filesize($dir.'/'.$f));
        echo '<div class="file-card">';
        echo '<a class="fdel" href="?app=files&action=rm&p='.urlencode($child).'&_csrf='.csrf_token().'" onclick="return confirm(\'Datei löschen?\')">'.icon('trash',15).'</a>';
        echo '<a href="?app=files&action=dl&p='.urlencode($child).'" title="Herunterladen">';
        echo '<div class="fi">'.icon('file',34).'</div><div class="fn">'.h($f).'</div><div class="fs">'.$size.'</div></a>';
        echo '</div>';
    }
    echo '</div>';
}

function handle_files(array $u, string $action): void {
    $rel = trim(param('p',''), '/');
    if ($action === 'upload') {
        csrf_check();
        $dir = files_resolve($u, $rel);
        if ($dir && is_dir($dir) && !empty($_FILES['files'])) {
            $n = 0;
            foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
                if (!is_uploaded_file($tmp)) continue;
                $name = basename($_FILES['files']['name'][$i]);
                $name = preg_replace('/[^\p{L}\p{N}._ -]+/u','_',$name);
                if ($name === '' || $name[0] === '.') $name = 'datei_'.$i;
                @move_uploaded_file($tmp, $dir.'/'.$name);
                $n++;
            }
            flash($n.' Datei(en) hochgeladen.');
        }
        redirect(url('files',['p'=>$rel]));
    }
    if ($action === 'mkdir') {
        csrf_check();
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u','_', trim(param('name')));
        if ($name !== '' && $name[0] !== '.') {
            $parent = files_resolve($u, $rel);
            if ($parent && is_dir($parent)) @mkdir($parent.'/'.$name, 0770);
            flash('Ordner erstellt.');
        }
        redirect(url('files',['p'=>$rel]));
    }
    if ($action === 'rm') {
        csrf_check_get();
        $target = files_resolve($u, $rel);
        $root   = files_root($u);
        if ($target && $target !== $root && strncmp($target,$root,strlen($root))===0) {
            if (is_dir($target)) rrmdir($target); else @unlink($target);
            flash('Gelöscht.');
        }
        redirect(url('files',['p'=>dirname($rel)==='.'?'':dirname($rel)]));
    }
    if ($action === 'dl') {
        $target = files_resolve($u, $rel);
        $root   = files_root($u);
        if ($target && is_file($target) && strncmp($target,$root,strlen($root))===0) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($target).'"');
            header('Content-Length: '.filesize($target));
            readfile($target);
            exit;
        }
        http_response_code(404); exit('Nicht gefunden.');
    }
}

function rrmdir(string $dir): void {
    foreach (array_diff(scandir($dir) ?: [], ['.','..']) as $f) {
        $p = $dir.'/'.$f;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/* ================================================================== *
 *  16. APP: MAIL (IMAP lesen + SMTP senden)
 * ================================================================== */
function mail_account(array $u, ?int $id = null): ?array {
    if ($id) {
        $st = db()->prepare('SELECT * FROM mail_accounts WHERE id=? AND user_id=?');
        $st->execute([$id, $u['id']]);
    } else {
        $st = db()->prepare('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id LIMIT 1');
        $st->execute([$u['id']]);
    }
    return $st->fetch() ?: null;
}

function app_mail(array $u): void {
    $accId  = (int)param('acc') ?: null;
    $acc    = mail_account($u, $accId);
    $accts  = db()->prepare('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id');
    $accts->execute([$u['id']]);
    $accts  = $accts->fetchAll();

    // Kein Konto -> Einrichtung
    if (!$acc) {
        layout_topbar('Mail', 'Postfach einrichten');
        echo '<div class="panel" style="max-width:600px;margin:0 auto">';
        echo '<div style="text-align:center;margin-bottom:20px">'.icon('mail',44).'<h3 style="margin-top:10px">Mail-Konto verbinden</h3>';
        echo '<p style="color:var(--muted)">Nur Zugangsdaten eingeben – Nexus verbindet sich per IMAP/SMTP direkt auf dieser Seite.</p></div>';
        mail_account_form($u);
        echo '</div>';
        return;
    }

    if (!function_exists('imap_open')) {
        layout_topbar('Mail', $acc['email']);
        echo '<div class="alert err">Die PHP-Erweiterung <b>imap</b> ist auf diesem Server nicht aktiviert. '
           . 'Bitte <code>extension=imap</code> in der php.ini aktivieren, um Postfächer zu lesen. '
           . 'Das Senden per SMTP funktioniert dennoch.</div>';
        echo '<a class="btn" href="?app=mail&action=compose&acc='.$acc['id'].'">'.icon('send').' Neue Mail schreiben</a>';
        return;
    }

    // Konto-Auswahl + Aktionen
    $accSel = '';
    if (count($accts) > 1) {
        $accSel = '<select class="input" style="width:auto" onchange="location=\'?app=mail&acc=\'+this.value">';
        foreach ($accts as $a) {
            $s = $a['id']==$acc['id']?'selected':'';
            $accSel .= '<option value="'.$a['id'].'" '.$s.'>'.h($a['email']).'</option>';
        }
        $accSel .= '</select>';
    }
    $actions = $accSel.'<a class="btn" href="?app=mail&action=compose&acc='.$acc['id'].'">'.icon('send').' Schreiben</a>';

    $uid = (int)param('uid');
    if ($uid) { mail_show($u, $acc, $uid); return; }

    layout_topbar('Posteingang', $acc['email'], $actions);
    mail_inbox($u, $acc);
}

function mail_account_form(array $u): void {
    echo '<form method="post" action="?app=mail&action=acc_save">'.csrf_field();
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
    echo '<p style="color:var(--muted2);font-size:12.5px;margin-bottom:14px">Das Passwort wird verschlüsselt (AES-256-GCM) in der gesperrten /data/sys gespeichert.</p>';
    echo '<button class="btn" type="submit" style="width:100%;justify-content:center">Konto speichern</button>';
    echo '</form>';
}

function imap_mailbox_str(array $acc, string $folder = 'INBOX'): string {
    $enc = $acc['imap_enc'] === 'ssl' ? '/ssl' : ($acc['imap_enc'] === 'tls' ? '/tls' : '/notls');
    $flags = $enc . '/novalidate-cert';
    return '{' . $acc['imap_host'] . ':' . (int)$acc['imap_port'] . '/imap' . $flags . '}' . $folder;
}

function mail_inbox(array $u, array $acc): void {
    $mbox = @imap_open(imap_mailbox_str($acc), $acc['username'], dec($acc['enc_pass']), 0, 1);
    if (!$mbox) {
        echo '<div class="alert err">Verbindung fehlgeschlagen: '.h(imap_last_error() ?: 'unbekannter Fehler').'</div>';
        echo '<a class="btn ghost" href="?app=settings">Konto-Einstellungen</a>';
        return;
    }
    $total = imap_num_msg($mbox);
    if ($total === 0) {
        echo '<div class="empty">'.icon('inbox',48).'<h3>Posteingang leer</h3></div>';
        imap_close($mbox);
        return;
    }
    $from = max(1, $total - 39); // letzte 40
    $seq  = $from . ':' . $total;
    $overview = imap_fetch_overview($mbox, $seq, 0);
    usort($overview, fn($a,$b) => ($b->uid ?? 0) <=> ($a->uid ?? 0));

    echo '<div class="mail-list">';
    foreach ($overview as $ov) {
        $seen = !empty($ov->seen);
        $subj = $ov->subject ?? '(kein Betreff)';
        $subj = imap_mime_decode_str($subj);
        $fromN= imap_mime_decode_str($ov->from ?? '');
        $date = isset($ov->udate) ? mail_date($ov->udate) : '';
        echo '<a class="mail-item '.($seen?'':'unseen').'" href="?app=mail&acc='.$acc['id'].'&uid='.$ov->uid.'">';
        echo '<span style="width:9px;height:9px;border-radius:50%;flex:none;background:'.($seen?'transparent':'var(--accent)').'"></span>';
        echo '<span class="from">'.h($fromN).'</span>';
        echo '<span class="subj">'.h($subj).'</span>';
        echo '<span class="date">'.h($date).'</span></a>';
    }
    echo '</div>';
    imap_close($mbox);
}

function mail_show(array $u, array $acc, int $uid): void {
    $mbox = @imap_open(imap_mailbox_str($acc), $acc['username'], dec($acc['enc_pass']));
    if (!$mbox) { layout_topbar('Mail'); echo '<div class="alert err">Verbindung fehlgeschlagen.</div>'; return; }

    $head = imap_headerinfo($mbox, imap_msgno($mbox, $uid));
    $subj = imap_mime_decode_str($head->subject ?? '(kein Betreff)');
    $fromN= imap_mime_decode_str($head->fromaddress ?? '');
    $date = isset($head->udate) ? mail_date($head->udate) : '';

    layout_topbar($subj, $fromN,
        '<a class="btn ghost" href="?app=mail&acc='.$acc['id'].'">'.icon('back').' Zurück</a>'.
        '<a class="btn" href="?app=mail&action=compose&acc='.$acc['id'].'&reply='.$uid.'">'.icon('reply').' Antworten</a>');

    $body = imap_get_body_pref($mbox, $uid);
    echo '<div class="panel">';
    echo '<div style="display:flex;align-items:center;gap:12px;padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid var(--line)">';
    echo '<div class="avatar" style="width:42px;height:42px">'.h(strtoupper(substr($fromN,0,1))).'</div>';
    echo '<div style="flex:1"><strong>'.h($fromN).'</strong><br><small style="color:var(--muted)">'.h($date).'</small></div></div>';

    if ($body['html'] !== '') {
        $safe = mail_sanitize_html($body['html']);
        echo '<iframe sandbox="" style="min-height:520px" srcdoc="'.h($safe).'"></iframe>';
    } else {
        echo '<div class="mail-body">'.mail_linkify(h($body['text'])).'</div>';
    }
    echo '</div>';
    imap_setflag_full($mbox, (string)$uid, "\\Seen", ST_UID);
    imap_close($mbox);
}

function mail_compose(array $u, array $acc): void {
    $to = $subject = $body = '';
    if ($ruid = (int)param('reply')) {
        if (function_exists('imap_open') && ($mbox = @imap_open(imap_mailbox_str($acc), $acc['username'], dec($acc['enc_pass'])))) {
            $h = imap_headerinfo($mbox, imap_msgno($mbox, $ruid));
            $from = $h->from[0] ?? null;
            if ($from) $to = $from->mailbox.'@'.$from->host;
            $subject = 'Re: '.imap_mime_decode_str($h->subject ?? '');
            $orig = imap_get_body_pref($mbox, $ruid)['text'];
            $body = "\n\n--- Ursprüngliche Nachricht ---\n" . preg_replace('/^/m','> ', trim($orig));
            imap_close($mbox);
        }
    }
    layout_topbar('Neue Nachricht', 'von '.$acc['email'],
        '<a class="btn ghost" href="?app=mail&acc='.$acc['id'].'">'.icon('back').' Abbrechen</a>');
    echo '<div class="panel" style="max-width:760px">';
    echo '<form method="post" action="?app=mail&action=send">'.csrf_field();
    echo '<input type="hidden" name="acc" value="'.$acc['id'].'">';
    echo '<div class="field"><label>An</label><input class="input" type="email" name="to" value="'.h($to).'" required></div>';
    echo '<div class="field"><label>Betreff</label><input class="input" name="subject" value="'.h($subject).'"></div>';
    echo '<div class="field"><label>Nachricht</label><textarea name="body" style="min-height:280px" required>'.h($body).'</textarea></div>';
    echo '<button class="btn" type="submit">'.icon('send').' Senden</button>';
    echo '</form></div>';
}

function handle_mail(array $u, string $action): void {
    if ($action === 'acc_save') {
        csrf_check();
        $st = db()->prepare('INSERT INTO mail_accounts
            (user_id,label,email,imap_host,imap_port,imap_enc,smtp_host,smtp_port,smtp_enc,username,enc_pass)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $u['id'], trim(param('label')), trim(param('email')),
            trim(param('imap_host')), (int)param('imap_port') ?: 993, param('imap_enc','ssl'),
            trim(param('smtp_host')), (int)param('smtp_port') ?: 465, param('smtp_enc','ssl'),
            trim(param('username')), enc(param('password')),
        ]);
        flash('Mail-Konto verbunden.');
        redirect(url('mail'));
    }
    if ($action === 'acc_del') {
        csrf_check_get();
        db()->prepare('DELETE FROM mail_accounts WHERE id=? AND user_id=?')->execute([(int)param('id'),$u['id']]);
        flash('Konto entfernt.');
        redirect(url('settings'));
    }
    if ($action === 'compose') {
        $acc = mail_account($u, (int)param('acc') ?: null);
        if (!$acc) redirect(url('mail'));
        layout_head($u, 'mail');
        mail_compose($u, $acc);
        layout_foot();
        exit;
    }
    if ($action === 'send') {
        csrf_check();
        $acc = mail_account($u, (int)param('acc') ?: null);
        if (!$acc) redirect(url('mail'));
        $res = smtp_send($acc, trim(param('to')), param('subject'), param('body'));
        flash($res === true ? 'Nachricht gesendet.' : 'Fehler beim Senden: '.$res);
        redirect(url('mail',['acc'=>$acc['id']]));
    }
}

/* --- Mail-Hilfsfunktionen --------------------------------------- */
function mail_date(int $ts): string {
    $diff = time() - $ts;
    if (date('Y-m-d',$ts) === date('Y-m-d')) return date('H:i', $ts);
    if ($diff < 6*86400) return date('D, H:i', $ts);
    return date('d.m.Y', $ts);
}

function imap_mime_decode_str(string $s): string {
    if (!function_exists('imap_mime_header_decode')) return $s;
    $out = '';
    foreach (imap_mime_header_decode($s) as $part) {
        $cs = strtoupper($part->charset);
        $txt = $part->text;
        if ($cs !== 'DEFAULT' && $cs !== 'UTF-8' && function_exists('mb_convert_encoding')) {
            $txt = @mb_convert_encoding($txt, 'UTF-8', $cs) ?: $txt;
        }
        $out .= $txt;
    }
    return $out;
}

/** Liefert ['text'=>..., 'html'=>...] der bevorzugten Body-Teile. */
function imap_get_body_pref($mbox, int $uid): array {
    $struct = imap_fetchstructure($mbox, $uid, FT_UID);
    $res = ['text' => '', 'html' => ''];
    if (empty($struct->parts)) {
        $data = imap_body($mbox, $uid, FT_UID);
        $data = imap_decode_part($data, $struct->encoding ?? 0);
        if (($struct->subtype ?? '') === 'HTML') $res['html'] = $data; else $res['text'] = $data;
        return $res;
    }
    imap_walk_parts($mbox, $uid, $struct->parts, '', $res);
    return $res;
}

function imap_walk_parts($mbox, int $uid, array $parts, string $prefix, array &$res): void {
    foreach ($parts as $i => $part) {
        $section = $prefix === '' ? (string)($i+1) : $prefix.'.'.($i+1);
        $type = strtoupper($part->subtype ?? '');
        $isText = ($part->type ?? 0) === 0; // TEXT
        $disp = '';
        foreach (($part->dparameters ?? []) as $p) if (strtoupper($p->attribute)==='FILENAME') $disp='attach';
        if ($isText && $disp !== 'attach') {
            $data = imap_fetchbody($mbox, $uid, $section, FT_UID);
            $data = imap_decode_part($data, $part->encoding ?? 0);
            $cs = '';
            foreach (($part->parameters ?? []) as $p) if (strtoupper($p->attribute)==='CHARSET') $cs=$p->value;
            if ($cs && strtoupper($cs)!=='UTF-8' && function_exists('mb_convert_encoding'))
                $data = @mb_convert_encoding($data,'UTF-8',$cs) ?: $data;
            if ($type === 'HTML') $res['html'] .= $data; else $res['text'] .= $data;
        }
        if (!empty($part->parts)) imap_walk_parts($mbox, $uid, $part->parts, $section, $res);
    }
}

function imap_decode_part(string $data, int $enc): string {
    switch ($enc) {
        case 3: return base64_decode($data);          // BASE64
        case 4: return quoted_printable_decode($data); // QUOTED-PRINTABLE
        default: return $data;
    }
}

function mail_sanitize_html(string $html): string {
    // Skripte/Events entfernen (wird zusätzlich in sandbox-iframe gerendert)
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $html);
    $html = preg_replace("#\son\w+\s*=\s*'[^']*'#i", '', $html);
    $html = preg_replace('#javascript:#i', '', $html);
    return $html;
}

function mail_linkify(string $text): string {
    return preg_replace('#(https?://[^\s<]+)#', '<a href="$1" target="_blank" rel="noopener" style="color:var(--accent)">$1</a>', $text);
}

/** Minimaler SMTP-Client (SSL oder STARTTLS). true bei Erfolg, sonst Fehlertext. */
function smtp_send(array $acc, string $to, string $subject, string $body) {
    $host = $acc['smtp_host'];
    $port = (int)$acc['smtp_port'];
    $enc  = $acc['smtp_enc'];
    $pass = dec($acc['enc_pass']);

    $transport = $enc === 'ssl' ? 'ssl://' : 'tcp://';
    $ctx = stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $fp = @stream_socket_client($transport.$host.':'.$port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return "Verbindung fehlgeschlagen ($errstr)";

    $read = function() use ($fp) {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function($c) use ($fp, $read) { fwrite($fp, $c."\r\n"); return $read(); };

    $read(); // Begrüßung
    $ehlo = $cmd('EHLO '.($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if ($enc === 'tls') {
        $cmd('STARTTLS');
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT))
            return 'STARTTLS fehlgeschlagen';
        $cmd('EHLO '.($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }
    $r = $cmd('AUTH LOGIN');
    if (strpos($r,'334') !== 0) return 'AUTH nicht unterstützt: '.trim($r);
    $r = $cmd(base64_encode($acc['username']));
    $r = $cmd(base64_encode($pass));
    if (strpos($r,'235') !== 0) return 'Anmeldung abgelehnt: '.trim($r);

    $from = $acc['email'];
    $r = $cmd('MAIL FROM:<'.$from.'>');
    if ($r[0] !== '2') return 'MAIL FROM abgelehnt: '.trim($r);
    $r = $cmd('RCPT TO:<'.$to.'>');
    if ($r[0] !== '2') return 'Empfänger abgelehnt: '.trim($r);
    $cmd('DATA');

    $subjectEnc = '=?UTF-8?B?'.base64_encode($subject).'?=';
    $headers  = 'From: '.$from."\r\n";
    $headers .= 'To: '.$to."\r\n";
    $headers .= 'Subject: '.$subjectEnc."\r\n";
    $headers .= 'Date: '.date('r')."\r\n";
    $headers .= 'MIME-Version: 1.0'."\r\n";
    $headers .= 'Content-Type: text/plain; charset=UTF-8'."\r\n";
    $headers .= 'Content-Transfer-Encoding: base64'."\r\n";
    $data = $headers."\r\n".chunk_split(base64_encode($body));
    // Punkt-Stuffing
    $data = preg_replace('/^\./m', '..', $data);
    fwrite($fp, $data."\r\n.\r\n");
    $r = $read();
    $cmd('QUIT');
    fclose($fp);
    return $r[0] === '2' ? true : 'Server-Antwort: '.trim($r);
}

/* ================================================================== *
 *  17. APP: EINSTELLUNGEN
 * ================================================================== */
function app_settings(array $u): void {
    layout_topbar('Einstellungen', 'Profil, Aussehen & Konten');

    // Profil
    echo '<div class="section-h">'.icon('user').' Profil</div>';
    echo '<div class="panel" style="max-width:640px">';
    echo '<form method="post" action="?app=settings&action=profile">'.csrf_field();
    echo '<div class="field"><label>Anzeigename</label><input class="input" name="display_name" value="'.h($u['display_name']).'"></div>';
    echo '<div class="field"><label>E-Mail</label><input class="input" type="email" name="email" value="'.h($u['email']).'"></div>';
    echo '<div class="field"><label>Benutzername</label><input class="input" value="'.h($u['username']).'" disabled></div>';
    echo '<button class="btn">Speichern</button>';
    echo '</form></div>';

    // Aussehen
    echo '<div class="section-h">'.icon('sun').' Aussehen</div>';
    echo '<div class="panel" style="max-width:640px">';
    echo '<form method="post" action="?app=settings&action=appearance">'.csrf_field();
    echo '<div class="field"><label>Theme</label><select name="theme">';
    foreach (['dark'=>'Dunkel','light'=>'Hell'] as $k=>$v)
        echo '<option value="'.$k.'" '.($u['theme']===$k?'selected':'').'>'.$v.'</option>';
    echo '</select></div>';
    echo '<div class="field"><label>Akzentfarbe</label>'.color_swatch('accent',$u['accent']).'</div>';
    echo '<button class="btn">Übernehmen</button>';
    echo '</form></div>';

    // Passwort
    echo '<div class="section-h">'.icon('cog').' Passwort ändern</div>';
    echo '<div class="panel" style="max-width:640px">';
    echo '<form method="post" action="?app=settings&action=password">'.csrf_field();
    echo '<div class="field"><label>Aktuelles Passwort</label><input class="input" type="password" name="current" required></div>';
    echo '<div class="row"><div class="field"><label>Neues Passwort</label><input class="input" type="password" name="new" required></div>';
    echo '<div class="field"><label>Wiederholen</label><input class="input" type="password" name="new2" required></div></div>';
    echo '<button class="btn">Passwort ändern</button>';
    echo '</form></div>';

    // Mail-Konten
    $accts = db()->prepare('SELECT * FROM mail_accounts WHERE user_id=? ORDER BY id');
    $accts->execute([$u['id']]);
    $accts = $accts->fetchAll();
    echo '<div class="section-h">'.icon('mail').' Mail-Konten</div>';
    echo '<div class="panel" style="max-width:640px">';
    if ($accts) {
        foreach ($accts as $a) {
            echo '<div style="display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--line)">';
            echo '<div class="tico" style="width:38px;height:38px;background:linear-gradient(135deg,#0ea5e9,#0369a1)">'.icon('mail').'</div>';
            echo '<div style="flex:1"><strong>'.h($a['label']).'</strong><br><small style="color:var(--muted)">'.h($a['email']).' · '.h($a['imap_host']).'</small></div>';
            echo '<a class="btn ghost sm danger" href="?app=mail&action=acc_del&id='.$a['id'].'&_csrf='.csrf_token().'" onclick="return confirm(\'Konto entfernen?\')">'.icon('trash',15).'</a>';
            echo '</div>';
        }
    } else {
        echo '<p style="color:var(--muted);margin-bottom:14px">Noch kein Mail-Konto verbunden.</p>';
    }
    echo '<a class="btn ghost" href="'.url('mail').'" style="margin-top:14px">'.icon('plus').' Konto hinzufügen</a>';
    echo '</div>';

    // Systeminfo
    echo '<div class="section-h">'.icon('grid').' System</div>';
    echo '<div class="panel" style="max-width:640px">';
    $imap = function_exists('imap_open') ? '✓ aktiv' : '✗ nicht verfügbar';
    $ossl = function_exists('openssl_encrypt') ? '✓ aktiv' : '✗ Fallback';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:14px">';
    echo '<div><span style="color:var(--muted)">Version</span><br>'.APP_NAME.' '.APP_VERSION.'</div>';
    echo '<div><span style="color:var(--muted)">PHP</span><br>'.h(PHP_VERSION).'</div>';
    echo '<div><span style="color:var(--muted)">IMAP-Erweiterung</span><br>'.$imap.'</div>';
    echo '<div><span style="color:var(--muted)">Verschlüsselung</span><br>'.$ossl.'</div>';
    echo '<div><span style="color:var(--muted)">Datenverzeichnis</span><br><code>/data</code> (gesperrt)</div>';
    echo '<div><span style="color:var(--muted)">Datenbank</span><br>SQLite</div>';
    echo '</div></div>';
}

function handle_settings(array $u, string $action): void {
    if ($action === 'profile') {
        csrf_check();
        db()->prepare('UPDATE users SET display_name=?, email=? WHERE id=?')
            ->execute([trim(param('display_name')) ?: $u['username'], trim(param('email')), $u['id']]);
        flash('Profil aktualisiert.');
        redirect(url('settings'));
    }
    if ($action === 'appearance') {
        csrf_check();
        $theme = param('theme') === 'light' ? 'light' : 'dark';
        $accent= preg_match('/^#[0-9a-f]{6}$/i', param('accent')) ? param('accent') : '#6366f1';
        db()->prepare('UPDATE users SET theme=?, accent=? WHERE id=?')->execute([$theme,$accent,$u['id']]);
        flash('Aussehen übernommen.');
        redirect(url('settings'));
    }
    if ($action === 'password') {
        csrf_check();
        if (!password_verify(param('current'), $u['pass_hash'])) {
            flash('Aktuelles Passwort falsch.'); redirect(url('settings'));
        }
        if (strlen(param('new')) < 6 || param('new') !== param('new2')) {
            flash('Neues Passwort ungültig oder stimmt nicht überein.'); redirect(url('settings'));
        }
        db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')
            ->execute([password_hash(param('new'), PASSWORD_DEFAULT), $u['id']]);
        flash('Passwort geändert.');
        redirect(url('settings'));
    }
}

/* ------------------------------------------------------------------ *
 *  Kleine DB-/CSRF-Helfer
 * ------------------------------------------------------------------ */
function q1(string $sql, array $args = []) {
    $st = db()->prepare($sql); $st->execute($args); return $st->fetchColumn();
}
function csrf_check_get(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', param('_csrf'))) {
        http_response_code(419); exit('Ungültiges Token.');
    }
}

/* ================================================================== *
 *  18. FRONT CONTROLLER / ROUTER
 * ================================================================== */

// Assets zuerst (kein Session/DB nötig) — spart Overhead & funktioniert immer
if (isset($_GET['asset'])) {
    serve_asset($_GET['asset'] === 'js' ? 'js' : 'css');
}

bootstrap();

session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax']);
session_start();

$action = param('action');

// Globale, auth-freie Aktionen
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect('?view=login');
}
if ($action === 'save_theme') { // JS-Toggle: Theme persistent speichern
    if ($u = current_user()) {
        $t = param('theme') === 'light' ? 'light' : 'dark';
        db()->prepare('UPDATE users SET theme=? WHERE id=?')->execute([$t,$u['id']]);
    }
    header('Content-Type: application/json'); echo '{"ok":true}'; exit;
}

// Registrierung / Login (nicht eingeloggt)
$view = param('view');
if (!current_user()) {
    if ($action === 'register') {
        csrf_check();
        $r = do_register(param('username'), param('email'), param('password'), param('password2'));
        if (!empty($r['ok'])) redirect(url('home'));
        view_auth('register', $r['err']); exit;
    }
    if ($action === 'login') {
        csrf_check();
        $r = do_login(param('username'), param('password'));
        if (!empty($r['ok'])) redirect(url('home'));
        view_auth('login', $r['err']); exit;
    }
    // Standard: erster Benutzer -> Registrierung, sonst Login
    $mode = ($view === 'register' || user_count() === 0) ? 'register' : 'login';
    view_auth($mode); exit;
}

// --- Ab hier: eingeloggt -----------------------------------------
$user = current_user();
$app  = current_app();

// POST/GET-Aktionen der Apps abhandeln (führen i. d. R. redirect aus)
if ($action !== '') {
    switch ($app) {
        case 'notes':     handle_notes($user, $action);     break;
        case 'calendar':  handle_calendar($user, $action);  break;
        case 'bookmarks': handle_bookmarks($user, $action); break;
        case 'files':     handle_files($user, $action);     break;
        case 'mail':      handle_mail($user, $action);      break;
        case 'settings':  handle_settings($user, $action);  break;
    }
}

// --- Seite rendern ------------------------------------------------
layout_head($user, $app);
switch ($app) {
    case 'notes':     app_notes($user);     break;
    case 'calendar':  app_calendar($user);  break;
    case 'bookmarks': app_bookmarks($user); break;
    case 'files':     app_files($user);     break;
    case 'mail':      app_mail($user);      break;
    case 'settings':  app_settings($user);  break;
    default:          app_home($user);      break;
}
layout_foot();
