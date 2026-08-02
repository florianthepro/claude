<?php

declare(strict_types=1);

/**
 * Stimmwerk – digitale Bürgerbeteiligung mit dem Personalausweis.
 * Ein-Datei-Version: Diese Datei ist die gesamte Anwendung. Beim ersten
 * Aufruf legt sie selbst an: data/ (SQLite, Server-Geheimnis, Logs,
 * Zugriffssperre), .htaccess (Routing + Schutz) und robots.txt.
 * CSS, JavaScript und Favicon liefert sie ebenfalls selbst aus.
 *
 * Identität: Der Ausweis(-Chip) hält einen privaten Schlüssel; beim
 * Anhalten signiert er eine Zufallsnachricht, der Server prüft die
 * Signatur gegen den öffentlichen Schlüssel und kennt nur ein daraus
 * abgeleitetes Pseudonym. Im Testbetrieb simuliert eine im Browser
 * hinterlegte Testkarte den Chip (echtes Signieren/Prüfen via libsodium).
 * Jede Änderung (Stimme, Thema, Meldung, Jury, Favorit, Löschung)
 * erfordert die Karte erneut.
 *
 * CLI: php index.php selftest | cron | seed [n] | jurysim
 */

if (PHP_VERSION_ID < 80000) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Benoetigt PHP 8.0+, gefunden: " . PHP_VERSION . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>PHP-Version zu alt</title></head>'
        . '<body style="font-family:sans-serif;max-width:40em;margin:3em auto;padding:0 1em">'
        . '<h1>PHP-Version zu alt</h1><p>Diese Anwendung ben&ouml;tigt PHP 8.0 oder neuer (gefunden: '
        . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8')
        . '). Bitte im Verwaltungsbereich des Hosters umstellen.</p></body></html>';
    exit;
}

/* ============================== Konfiguration ============================= */

const SW_CONFIG = [
    'app_name' => 'Stimmwerk',
    'domain'   => 'stimmwerk.de',

    // Testbetrieb-Banner: solange true, zeigt jede Seite den Hinweis, dass
    // dies keine offizielle Seite der Bundesregierung oder einer Behörde ist.
    'show_test_banner' => true,

    'timezone'     => 'Europe/Berlin',
    'default_lang' => 'de',
    'langs'        => ['de', 'en'],

    // Bürger-Jury
    'jury_share'         => 0.01,  // 1 % der Nutzerschaft je Meldung
    'jury_min'           => 5,
    'quorum_share'       => 0.005, // 0,5 % der Nutzerschaft
    'quorum_min'         => 3,
    'report_vote_hours'  => 24,
    'jury_cooldown_days' => 3,
    'reports_per_day'    => 3,

    // Sitzungen (öffentliche Terminals)
    'session_idle_minutes' => 30,
    'session_max_hours'    => 8,

    'page_size' => 20,
];

/** Zentrale, bewusst kleine Registry (eine Datei, ein Zustand). */
final class SW
{
    public static array $cfg = SW_CONFIG;
    public static ?Db $db = null;
    public static string $dataDir = '';
    public static string $pepper = '';
    public static string $lang = 'de';
    /** @var array<string,string> */
    public static array $tActive = [];
    public static ?array $user = null;
    public static string $base = '';
    public static bool $clean = false;
    public static string $path = '/';
}

/* ============================== Zeit ====================================== */

final class Clock
{
    public const FORMAT = 'Y-m-d H:i:s';
    private static ?DateTimeImmutable $testNow = null;
    private static string $tz = 'Europe/Berlin';

    public static function setTimezone(string $tz): void
    {
        self::$tz = $tz;
    }

    public static function setTestNow(?DateTimeImmutable $now): void
    {
        self::$testNow = $now === null ? null : $now->setTimezone(new DateTimeZone('UTC'));
    }

    public static function now(): DateTimeImmutable
    {
        return self::$testNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function nowStr(): string
    {
        return self::now()->format(self::FORMAT);
    }

    /** Heutiges Datum (YYYY-MM-DD) in der Bezugszeitzone. */
    public static function localDate(): string
    {
        return self::now()->setTimezone(new DateTimeZone(self::$tz))->format('Y-m-d');
    }

    /** Nächste Mitternacht (00:00) der Bezugszeitzone, als UTC-String. */
    public static function nextLocalMidnightUtcStr(): string
    {
        $local = self::now()->setTimezone(new DateTimeZone(self::$tz));
        return $local->modify('tomorrow')->setTime(0, 0, 0)
            ->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function addHoursStr(string $utc, int $hours): string
    {
        return self::fromStr($utc)->modify(sprintf('%+d hours', $hours))->format(self::FORMAT);
    }

    public static function addDaysStr(string $utc, int $days): string
    {
        return self::fromStr($utc)->modify(sprintf('%+d days', $days))->format(self::FORMAT);
    }

    public static function fromStr(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public static function displayLocal(string $utc, string $format): string
    {
        return self::fromStr($utc)->setTimezone(new DateTimeZone(self::$tz))->format($format);
    }
}

/* ============================== Datenbank ================================= */

const SW_SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS schema_info (
    k TEXT PRIMARY KEY,
    v TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS users (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    pseudonym_hash      TEXT    NOT NULL UNIQUE,
    lang                TEXT    NOT NULL DEFAULT 'de' CHECK (lang IN ('de','en')),
    is_system           INTEGER NOT NULL DEFAULT 0 CHECK (is_system IN (0,1)),
    is_seed             INTEGER NOT NULL DEFAULT 0 CHECK (is_seed IN (0,1)),
    jury_cooldown_until TEXT,
    created_at          TEXT    NOT NULL,
    last_login_at       TEXT
);
CREATE TABLE IF NOT EXISTS categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,
    name_de    TEXT    NOT NULL,
    name_en    TEXT    NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS topics (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL REFERENCES users(id),
    title        TEXT    NOT NULL,
    goal         TEXT    NOT NULL,
    reasoning    TEXT    NOT NULL,
    category_id  INTEGER NOT NULL REFERENCES categories(id),
    scope_level  TEXT    NOT NULL CHECK (scope_level IN ('kommune','landkreis','bundesland','bund')),
    scope_name   TEXT,
    status       TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','removed')),
    created_at   TEXT    NOT NULL,
    created_date TEXT    NOT NULL,
    UNIQUE (author_id, created_date)
);
CREATE INDEX IF NOT EXISTS ix_topics_status_created ON topics(status, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_topics_category       ON topics(category_id);
CREATE INDEX IF NOT EXISTS ix_topics_scope          ON topics(scope_level, scope_name);
CREATE TABLE IF NOT EXISTS votes (
    topic_id   INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id)  ON DELETE CASCADE,
    choice     TEXT    NOT NULL CHECK (choice IN ('for','against')),
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    PRIMARY KEY (topic_id, user_id)
);
CREATE INDEX IF NOT EXISTS ix_votes_user ON votes(user_id);
CREATE TABLE IF NOT EXISTS favorites (
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind       TEXT    NOT NULL CHECK (kind IN ('category','scope')),
    ref        TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    PRIMARY KEY (user_id, kind, ref)
);
CREATE TABLE IF NOT EXISTS reports (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_id         INTEGER NOT NULL REFERENCES topics(id),
    reporter_id      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    criteria         TEXT    NOT NULL,
    freetext         TEXT,
    status           TEXT    NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending','voting','decided_removed','decided_kept')),
    jury_size        INTEGER NOT NULL,
    quorum           INTEGER NOT NULL,
    created_at       TEXT    NOT NULL,
    voting_starts_at TEXT    NOT NULL,
    decided_at       TEXT,
    UNIQUE (topic_id, reporter_id)
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_reports_open
    ON reports(topic_id) WHERE status IN ('pending','voting');
CREATE INDEX IF NOT EXISTS ix_reports_status ON reports(status);
CREATE TABLE IF NOT EXISTS report_jurors (
    report_id INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
    user_id   INTEGER NOT NULL REFERENCES users(id)   ON DELETE CASCADE,
    vote      TEXT    CHECK (vote IN ('confirm','reject','neutral')),
    voted_at  TEXT,
    PRIMARY KEY (report_id, user_id)
);
CREATE INDEX IF NOT EXISTS ix_jurors_user ON report_jurors(user_id);
CREATE TABLE IF NOT EXISTS rate_limits (
    k            TEXT    PRIMARY KEY,
    window_start INTEGER NOT NULL,
    cnt          INTEGER NOT NULL
);
SQL;

/** Schmale PDO-Hülle: ausschließlich Prepared Statements. */
final class Db
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Datenverzeichnis nicht anlegbar.');
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function val(string $sql, array $params = [])
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function lastId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function tx(callable $fn)
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function migrate(): void
    {
        $exists = $this->val("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_info'");
        if ($exists === null) {
            $this->pdo->exec(SW_SCHEMA);
            $this->run("INSERT INTO schema_info (k, v) VALUES ('version', '1'), ('created_at', ?)", [Clock::nowStr()]);
        }
    }
}

/* ===================== Selbst-Einrichtung (erster Aufruf) ================= */

/** Sperr-.htaccess für interne Verzeichnisse (Apache 2.2/2.4, LiteSpeed). */
const SW_HTACCESS_DENY = <<<'TXT'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
TXT;

/** Root-.htaccess: Routing an index.php + Kennung für saubere Pfade. */
const SW_HTACCESS_ROOT = <<<'TXT'
# Stimmwerk (automatisch erzeugt) - bei Bedarf loeschen, wird neu angelegt.
Options -Indexes -MultiViews
DirectoryIndex index.php
<IfModule mod_rewrite.c>
    RewriteEngine On
    SetEnv SW_CLEAN_URLS 1
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
<FilesMatch "\.(md|sqlite|sqlite-wal|sqlite-shm|log|key|lock)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
<FilesMatch "^\.">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
TXT;

/** Legt data/, Schutzdateien, Geheimnis und Datenbank an (idempotent). */
function sw_setup(): void
{
    Clock::setTimezone((string) SW::$cfg['timezone']);
    date_default_timezone_set('UTC');
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    SW::$dataDir = __DIR__ . '/data';
    if (!is_dir(SW::$dataDir) && !mkdir(SW::$dataDir, 0750, true) && !is_dir(SW::$dataDir)) {
        throw new RuntimeException('Verzeichnis data/ nicht anlegbar.');
    }
    ini_set('error_log', SW::$dataDir . '/php-error.log');

    $dataHt = SW::$dataDir . '/.htaccess';
    if (!is_file($dataHt)) {
        @file_put_contents($dataHt, SW_HTACCESS_DENY . "\n", LOCK_EX);
    }
    // Root-Schutz/-Routing: nur erzeugen, wenn nicht vorhanden. Schlaegt das
    // Schreiben fehl, laeuft die Anwendung ueber /index.php/...-Links weiter.
    $rootHt = __DIR__ . '/.htaccess';
    if (!is_file($rootHt)) {
        @file_put_contents($rootHt, SW_HTACCESS_ROOT . "\n", LOCK_EX);
    }
    $robots = __DIR__ . '/robots.txt';
    if (!is_file($robots)) {
        @file_put_contents($robots, "User-agent: *\nDisallow: /\n", LOCK_EX);
    }

    $keyFile = SW::$dataDir . '/secret.key';
    if (!is_file($keyFile)) {
        if (file_put_contents($keyFile, bin2hex(random_bytes(32)), LOCK_EX) === false) {
            throw new RuntimeException('Geheimnis-Datei nicht schreibbar.');
        }
        @chmod($keyFile, 0600);
    }
    $pepper = trim((string) file_get_contents($keyFile));
    if (strlen($pepper) < 32) {
        throw new RuntimeException('Geheimnis-Datei beschädigt.');
    }
    SW::$pepper = $pepper;

    $dbPath = getenv('STIMMWERK_DB') ?: SW::$dataDir . '/stimmwerk.sqlite';
    SW::$db = new Db($dbPath);
    SW::$db->migrate();
    sw_seed_categories();
}

function sw_hmac(string $value): string
{
    return hash_hmac('sha256', $value, SW::$pepper);
}

/* ============================== Hilfsfunktionen =========================== */

/** HTML-Escaping für JEDE Ausgabe von Nutzerdaten. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Übersetzung mit {platzhalter}-Ersetzung. */
function t(string $key, array $repl = []): string
{
    $text = SW::$tActive[$key] ?? SW_DE[$key] ?? $key;
    foreach ($repl as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}

/** Zahlformat je Sprache (1.234 / 1,234). */
function num(int $n): string
{
    return SW::$lang === 'de' ? number_format($n, 0, ',', '.') : number_format($n);
}

/** URL-Präfix: sauber (/topics) nur bei nachweislich aktiven Rewrite-Regeln,
 *  sonst überall lauffähig als /index.php/topics (PATH_INFO). */
function base_path(): string
{
    return SW::$base . (SW::$clean ? '' : '/index.php');
}

function url(string $path): string
{
    $full = base_path() . $path;
    return $full === '' ? '/' : $full;
}

/** Interner Redirect – ausschließlich auf eigene, interne Pfade. */
function redirect(string $path): void
{
    if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0) {
        $path = '/';
    }
    header('Location: ' . url($path), true, 303);
    exit;
}

function post_str(string $key, int $maxLen, bool $multiline = false): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $pattern = $multiline ? '/[^\P{C}\n\t]/u' : '/\p{C}/u';
    $value = trim((string) preg_replace($pattern, '', $value));
    return mb_strlen($value) > $maxLen ? mb_substr($value, 0, $maxLen) : $value;
}

function post_int(string $key): ?int
{
    $value = $_POST[$key] ?? null;
    return (is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1) ? (int) $value : null;
}

function post_str_list(string $key, array $allowed): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }
    $out = [];
    foreach ($values as $value) {
        if (is_string($value) && in_array($value, $allowed, true)) {
            $out[] = $value;
        }
    }
    return array_values(array_unique($out));
}

function query_str(string $key, int $maxLen = 120): string
{
    $value = $_GET[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $value = trim((string) preg_replace('/\p{C}/u', '', $value));
    return mb_strlen($value) > $maxLen ? mb_substr($value, 0, $maxLen) : $value;
}

function query_int(string $key, int $min, int $max, int $default): int
{
    $value = $_GET[$key] ?? null;
    if (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1) {
        return max($min, min($max, (int) $value));
    }
    return $default;
}

function log_line(string $level, string $event, array $context = []): void
{
    $line = sprintf(
        "%s %s %s %s\n",
        Clock::nowStr(),
        $level,
        $event,
        $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    @file_put_contents(SW::$dataDir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

/* ============================== Sitzung & CSRF ============================ */

function sw_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('sw_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => sw_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $now = time();
    $idle = isset($_SESSION['last_activity'])
        && ($now - (int) $_SESSION['last_activity']) > (int) SW::$cfg['session_idle_minutes'] * 60;
    $expired = isset($_SESSION['auth_time'])
        && ($now - (int) $_SESSION['auth_time']) > (int) SW::$cfg['session_max_hours'] * 3600;
    if (($idle || $expired) && isset($_SESSION['user_id'])) {
        unset($_SESSION['user_id'], $_SESSION['auth_time']);
        session_regenerate_id(true);
        flash('info', 'flash.session_expired');
    }
    $_SESSION['last_activity'] = $now;
}

function flash(string $type, string $key, array $repl = []): void
{
    $_SESSION['flash'][] = ['type' => $type, 'key' => $key, 'repl' => $repl];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($flashes) ? $flashes : [];
}

/**
 * Einmal-Token: Jedes Formular trägt ein eigenes, einmalig gültiges Token
 * (serverseitig in der Sitzung geführt, beim Einlösen verbraucht).
 * Das deckt CSRF ab UND verhindert jede Wiederholung einer Aktion –
 * jede Aktion ist genau einmal gültig.
 */
function csrf_field(): string
{
    $token = bin2hex(random_bytes(16));
    $list = isset($_SESSION['ot']) && is_array($_SESSION['ot']) ? $_SESSION['ot'] : [];
    $list[$token] = time();
    if (count($list) > 40) {
        $list = array_slice($list, -40, null, true);
    }
    $_SESSION['ot'] = $list;
    return '<input type="hidden" name="_csrf" value="' . e($token) . '">';
}

function csrf_ok(): bool
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !isset($_SESSION['ot']) || !is_array($_SESSION['ot']) || !isset($_SESSION['ot'][$sent])) {
        return false;
    }
    unset($_SESSION['ot'][$sent]); // einmalig: verbraucht ist verbraucht
    return true;
}

/** Festfenster-Ratenbegrenzung je Schlüssel; keine Klar-IP-Speicherung. */
function rate_allow(string $key, int $max, int $windowSeconds): bool
{
    $now = Clock::now()->getTimestamp();
    return SW::$db->tx(function () use ($key, $max, $windowSeconds, $now): bool {
        $row = SW::$db->one('SELECT window_start, cnt FROM rate_limits WHERE k = ?', [$key]);
        if ($row === null || ($now - (int) $row['window_start']) >= $windowSeconds) {
            SW::$db->run(
                'INSERT INTO rate_limits (k, window_start, cnt) VALUES (?, ?, 1)
                 ON CONFLICT(k) DO UPDATE SET window_start = excluded.window_start, cnt = 1',
                [$key, $now]
            );
            return true;
        }
        if ((int) $row['cnt'] >= $max) {
            return false;
        }
        SW::$db->run('UPDATE rate_limits SET cnt = cnt + 1 WHERE k = ?', [$key]);
        return true;
    });
}

function rate_gc(): void
{
    SW::$db->run('DELETE FROM rate_limits WHERE window_start < ?', [Clock::now()->getTimestamp() - 86400]);
}

function ip_key(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return substr(sw_hmac('ip|' . Clock::localDate() . '|' . $ip), 0, 24);
}

/* ================= Ausweis-Simulation (Schlüssel & Signatur) ============== */
/* Der echte Ausweis-Chip hält einen privaten Schlüssel, der die Karte nie
   verlässt; der Server prüft Signaturen gegen den öffentlichen Schlüssel
   (Zertifikatskette des Staates, BSI TR-03110/-03130). Im Testbetrieb
   simuliert der Server den Chip mit einem echten Ed25519-Schlüsselpaar
   (libsodium), das ausschließlich serverseitig in der Sitzung liegt –
   im Browser wird NICHTS gespeichert (einziges Cookie: die Sitzungs-ID).
   Jede Anmeldung und jede Änderung wird durch Signatur + Prüfung gegen den
   öffentlichen Schlüssel bestätigt. Am Smartphone löst der NFC-Kontakt den
   Vorgang direkt aus (Web NFC, mit Rückfall auf Knopfdruck). Produktion:
   Austausch dieses Blocks gegen die eID-Server-Anbindung (TR-03130). */

function card_supports_sodium(): bool
{
    return function_exists('sodium_crypto_sign_keypair');
}

/** Liest die simulierte Karte der laufenden Sitzung. @return array{secret:string,pk:string}|null */
function card_load(): ?array
{
    $raw = $_SESSION['card'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $secret = base64_decode($raw, true);
    if ($secret === false) {
        return null;
    }
    if (card_supports_sodium()) {
        if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }
        return ['secret' => $secret, 'pk' => sodium_crypto_sign_publickey_from_secretkey($secret)];
    }
    if (strlen($secret) !== 32) {
        return null;
    }
    return ['secret' => $secret, 'pk' => hash('sha256', 'pk|' . $secret, true)];
}

/** Erzeugt eine neue simulierte Karte – nur serverseitig in der Sitzung. */
function card_create(): array
{
    if (card_supports_sodium()) {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $pk = sodium_crypto_sign_publickey($pair);
    } else {
        $secret = random_bytes(32);
        $pk = hash('sha256', 'pk|' . $secret, true);
    }
    $_SESSION['card'] = base64_encode($secret);
    return ['secret' => $secret, 'pk' => $pk];
}

function card_forget(): void
{
    unset($_SESSION['card']);
}

/** Identität „on the go“: der öffentliche Schlüssel selbst (hex) – es wird
 *  kein abgeleitetes Pseudonym erzeugt oder zugeordnet. */
function card_identity(array $card): string
{
    return bin2hex($card['pk']);
}

/* TOTP-artige Zeitbindung: Alle Nachweise gelten nur für ein kurzes
   Zeitfenster – dieselbe Aktion ergibt zu anderer Zeit einen anderen,
   nicht wiederverwendbaren Nachweis. */
const SW_SLOT_SECONDS = 300; // Fensterlänge (5 Minuten)
const SW_AUTH_SLOTS = 2;     // Anmeldung gilt für aktuelles + folgendes Fenster

function time_slot(): int
{
    return intdiv(Clock::now()->getTimestamp(), SW_SLOT_SECONDS);
}

/** Versiegelter Aktions-Umschlag: Die Karte signiert/versiegelt Aktion +
 *  Zeitfenster mit ihrem privaten Schlüssel (kombinierter Signaturmodus);
 *  der Server ÖFFNET den Umschlag mit dem öffentlichen Schlüssel. */
function card_seal(array $card, string $action): string
{
    $payload = json_encode(['a' => $action, 'slot' => time_slot(), 'n' => bin2hex(random_bytes(8))]);
    if (card_supports_sodium()) {
        return sodium_crypto_sign($payload, $card['secret']);
    }
    // Rückfall ohne sodium: an den öffentlichen Schlüssel gebundene Prüfsumme.
    return $payload . '.' . hash('sha256', 'seal|' . $card['pk'] . '|' . $payload);
}

/** Öffnet den Umschlag mit dem öffentlichen Schlüssel und prüft Aktion und
 *  Zeitfenster (aktuelles oder unmittelbar vorheriges). */
function card_open(string $pk, string $sealed, string $action): bool
{
    if (card_supports_sodium()) {
        $payload = sodium_crypto_sign_open($sealed, $pk);
        if ($payload === false) {
            return false;
        }
    } else {
        $dot = strrpos($sealed, '.');
        if ($dot === false) {
            return false;
        }
        $payload = substr($sealed, 0, $dot);
        $mac = substr($sealed, $dot + 1);
        if (!hash_equals(hash('sha256', 'seal|' . $pk . '|' . $payload), $mac)) {
            return false;
        }
    }
    $data = json_decode($payload, true);
    if (!is_array($data) || ($data['a'] ?? '') !== $action) {
        return false;
    }
    $slot = (int) ($data['slot'] ?? -1);
    $current = time_slot();
    return $slot === $current || $slot === $current - 1;
}

/* ============================== Konto & Anmeldung ========================= */

function auth_login(string $pseudonymHash): array
{
    $now = Clock::nowStr();
    $user = SW::$db->one('SELECT * FROM users WHERE pseudonym_hash = ?', [$pseudonymHash]);
    if ($user === null) {
        SW::$db->run(
            'INSERT INTO users (pseudonym_hash, lang, created_at, last_login_at) VALUES (?, ?, ?, ?)',
            [$pseudonymHash, (string) SW::$cfg['default_lang'], $now, $now]
        );
        $user = SW::$db->one('SELECT * FROM users WHERE pseudonym_hash = ?', [$pseudonymHash]);
    } else {
        SW::$db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [$now, (int) $user['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['auth_time'] = time();
    SW::$user = $user;
    return $user;
}

function auth_logout(): void
{
    unset($_SESSION['user_id'], $_SESSION['auth_time']);
    session_regenerate_id(true);
    SW::$user = null;
}

function auth_user(): ?array
{
    if (SW::$user !== null) {
        return SW::$user;
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id)) {
        return null;
    }
    // Zeitfenster abgelaufen -> Identitätsnachweis verfällt, erneut anhalten.
    $slot = $_SESSION['auth_slot'] ?? null;
    if (is_int($slot) && (time_slot() - $slot) >= SW_AUTH_SLOTS) {
        unset($_SESSION['user_id'], $_SESSION['auth_time'], $_SESSION['auth_slot']);
        card_forget();
        session_regenerate_id(true);
        flash('info', 'flash.auth_expired');
        return null;
    }
    SW::$user = SW::$db->one('SELECT * FROM users WHERE id = ? AND is_system = 0', [$id]);
    return SW::$user;
}

function require_user(): array
{
    $user = auth_user();
    if ($user === null) {
        flash('info', 'flash.login_required');
        redirect('/auth');
    }
    return $user;
}

/** Jede Änderung läuft unabhängig von der Profil-Anmeldung über einen
 *  eigenen, zeitgebundenen versiegelten Umschlag: Die Karte versiegelt die
 *  Aktion, der Server öffnet mit dem öffentlichen Schlüssel und trägt das
 *  Ergebnis für genau diesen Schlüssel ein. Ein alter Umschlag (anderes
 *  Zeitfenster) wird abgelehnt. */
function require_card(array $user): void
{
    $card = card_load();
    $action = 'confirm:' . SW::$path;
    if ($card === null
        || !hash_equals((string) $user['pseudonym_hash'], card_identity($card))
        || !card_open($card['pk'], card_seal($card, $action), $action)) {
        log_line('SECURITY', 'card_confirm_failed', []);
        flash('error', 'flash.card_required');
        redirect('/auth');
    }
}

function short_id(array $user): string
{
    return strtoupper(substr((string) $user['pseudonym_hash'], 0, 8));
}

/* ============================== Fachlogik: Themen ========================= */

const SW_TITLE_MIN = 8;
const SW_TITLE_MAX = 120;
const SW_GOAL_MIN = 10;
const SW_GOAL_MAX = 500;
const SW_REASONING_MIN = 10;
const SW_REASONING_MAX = 4000;

/** Amtliche Verwaltungsgliederung: 16 Länder mit ihren Landkreisen und
    kreisfreien Städten – Auswahl statt Freitext. Vor einem Echtbetrieb
    gegen das amtliche Gemeindeverzeichnis (Destatis, ARS) abgleichen;
    die Gemeindeebene folgt in der Ausbaustufe über dasselbe Verzeichnis. */
const SW_REGIONS = [
    'Baden-Württemberg' => ['Alb-Donau-Kreis', 'Baden-Baden (Stadt)', 'Bodenseekreis', 'Enzkreis', 'Freiburg im Breisgau (Stadt)', 'Heidelberg (Stadt)', 'Heilbronn (Stadt)', 'Hohenlohekreis', 'Karlsruhe (Stadt)', 'Landkreis Biberach', 'Landkreis Breisgau-Hochschwarzwald', 'Landkreis Böblingen', 'Landkreis Calw', 'Landkreis Emmendingen', 'Landkreis Esslingen', 'Landkreis Freudenstadt', 'Landkreis Göppingen', 'Landkreis Heidenheim', 'Landkreis Heilbronn', 'Landkreis Karlsruhe', 'Landkreis Konstanz', 'Landkreis Ludwigsburg', 'Landkreis Lörrach', 'Landkreis Rastatt', 'Landkreis Ravensburg', 'Landkreis Reutlingen', 'Landkreis Rottweil', 'Landkreis Schwäbisch Hall', 'Landkreis Sigmaringen', 'Landkreis Tuttlingen', 'Landkreis Tübingen', 'Landkreis Waldshut', 'Main-Tauber-Kreis', 'Mannheim (Stadt)', 'Neckar-Odenwald-Kreis', 'Ortenaukreis', 'Ostalbkreis', 'Pforzheim (Stadt)', 'Rems-Murr-Kreis', 'Rhein-Neckar-Kreis', 'Schwarzwald-Baar-Kreis', 'Stuttgart (Stadt)', 'Ulm (Stadt)', 'Zollernalbkreis'],
    'Bayern' => ['Amberg (Stadt)', 'Ansbach (Stadt)', 'Aschaffenburg (Stadt)', 'Augsburg (Stadt)', 'Bamberg (Stadt)', 'Bayreuth (Stadt)', 'Coburg (Stadt)', 'Erlangen (Stadt)', 'Fürth (Stadt)', 'Hof (Stadt)', 'Ingolstadt (Stadt)', 'Kaufbeuren (Stadt)', 'Kempten (Allgäu) (Stadt)', 'Landkreis Aichach-Friedberg', 'Landkreis Altötting', 'Landkreis Amberg-Sulzbach', 'Landkreis Ansbach', 'Landkreis Aschaffenburg', 'Landkreis Augsburg', 'Landkreis Bad Kissingen', 'Landkreis Bad Tölz-Wolfratshausen', 'Landkreis Bamberg', 'Landkreis Bayreuth', 'Landkreis Berchtesgadener Land', 'Landkreis Cham', 'Landkreis Coburg', 'Landkreis Dachau', 'Landkreis Deggendorf', 'Landkreis Dillingen a.d.Donau', 'Landkreis Dingolfing-Landau', 'Landkreis Donau-Ries', 'Landkreis Ebersberg', 'Landkreis Eichstätt', 'Landkreis Erding', 'Landkreis Erlangen-Höchstadt', 'Landkreis Forchheim', 'Landkreis Freising', 'Landkreis Freyung-Grafenau', 'Landkreis Fürstenfeldbruck', 'Landkreis Fürth', 'Landkreis Garmisch-Partenkirchen', 'Landkreis Günzburg', 'Landkreis Haßberge', 'Landkreis Hof', 'Landkreis Kelheim', 'Landkreis Kitzingen', 'Landkreis Kronach', 'Landkreis Kulmbach', 'Landkreis Landsberg am Lech', 'Landkreis Landshut', 'Landkreis Lichtenfels', 'Landkreis Lindau (Bodensee)', 'Landkreis Main-Spessart', 'Landkreis Miesbach', 'Landkreis Miltenberg', 'Landkreis Mühldorf a.Inn', 'Landkreis München', 'Landkreis Neu-Ulm', 'Landkreis Neuburg-Schrobenhausen', 'Landkreis Neumarkt i.d.OPf.', 'Landkreis Neustadt a.d.Aisch-Bad Windsheim', 'Landkreis Neustadt a.d.Waldnaab', 'Landkreis Nürnberger Land', 'Landkreis Oberallgäu', 'Landkreis Ostallgäu', 'Landkreis Passau', 'Landkreis Pfaffenhofen a.d.Ilm', 'Landkreis Regen', 'Landkreis Regensburg', 'Landkreis Rhön-Grabfeld', 'Landkreis Rosenheim', 'Landkreis Roth', 'Landkreis Rottal-Inn', 'Landkreis Schwandorf', 'Landkreis Schweinfurt', 'Landkreis Starnberg', 'Landkreis Straubing-Bogen', 'Landkreis Tirschenreuth', 'Landkreis Traunstein', 'Landkreis Unterallgäu', 'Landkreis Weilheim-Schongau', 'Landkreis Weißenburg-Gunzenhausen', 'Landkreis Wunsiedel i.Fichtelgebirge', 'Landkreis Würzburg', 'Landshut (Stadt)', 'Memmingen (Stadt)', 'München (Stadt)', 'Nürnberg (Stadt)', 'Passau (Stadt)', 'Regensburg (Stadt)', 'Rosenheim (Stadt)', 'Schwabach (Stadt)', 'Schweinfurt (Stadt)', 'Straubing (Stadt)', 'Weiden i.d.OPf. (Stadt)', 'Würzburg (Stadt)'],
    'Berlin' => [],
    'Brandenburg' => ['Brandenburg an der Havel (Stadt)', 'Cottbus (Stadt)', 'Frankfurt (Oder) (Stadt)', 'Landkreis Barnim', 'Landkreis Dahme-Spreewald', 'Landkreis Elbe-Elster', 'Landkreis Havelland', 'Landkreis Märkisch-Oderland', 'Landkreis Oberhavel', 'Landkreis Oberspreewald-Lausitz', 'Landkreis Oder-Spree', 'Landkreis Ostprignitz-Ruppin', 'Landkreis Potsdam-Mittelmark', 'Landkreis Prignitz', 'Landkreis Spree-Neiße', 'Landkreis Teltow-Fläming', 'Landkreis Uckermark', 'Potsdam (Stadt)'],
    'Bremen' => ['Bremen (Stadt)', 'Bremerhaven (Stadt)'],
    'Hamburg' => [],
    'Hessen' => ['Darmstadt (Stadt)', 'Frankfurt am Main (Stadt)', 'Hochtaunuskreis', 'Kassel (Stadt)', 'Lahn-Dill-Kreis', 'Landkreis Bergstraße', 'Landkreis Darmstadt-Dieburg', 'Landkreis Fulda', 'Landkreis Gießen', 'Landkreis Groß-Gerau', 'Landkreis Hersfeld-Rotenburg', 'Landkreis Kassel', 'Landkreis Limburg-Weilburg', 'Landkreis Marburg-Biedenkopf', 'Landkreis Offenbach', 'Landkreis Waldeck-Frankenberg', 'Main-Kinzig-Kreis', 'Main-Taunus-Kreis', 'Odenwaldkreis', 'Offenbach am Main (Stadt)', 'Rheingau-Taunus-Kreis', 'Schwalm-Eder-Kreis', 'Vogelsbergkreis', 'Werra-Meißner-Kreis', 'Wetteraukreis', 'Wiesbaden (Stadt)'],
    'Mecklenburg-Vorpommern' => ['Landkreis Ludwigslust-Parchim', 'Landkreis Mecklenburgische Seenplatte', 'Landkreis Nordwestmecklenburg', 'Landkreis Rostock', 'Landkreis Vorpommern-Greifswald', 'Landkreis Vorpommern-Rügen', 'Rostock (Stadt)', 'Schwerin (Stadt)'],
    'Niedersachsen' => ['Braunschweig (Stadt)', 'Delmenhorst (Stadt)', 'Emden (Stadt)', 'Heidekreis', 'Landkreis Ammerland', 'Landkreis Aurich', 'Landkreis Celle', 'Landkreis Cloppenburg', 'Landkreis Cuxhaven', 'Landkreis Diepholz', 'Landkreis Emsland', 'Landkreis Friesland', 'Landkreis Gifhorn', 'Landkreis Goslar', 'Landkreis Grafschaft Bentheim', 'Landkreis Göttingen', 'Landkreis Hameln-Pyrmont', 'Landkreis Harburg', 'Landkreis Helmstedt', 'Landkreis Hildesheim', 'Landkreis Holzminden', 'Landkreis Leer', 'Landkreis Lüchow-Dannenberg', 'Landkreis Lüneburg', 'Landkreis Nienburg/Weser', 'Landkreis Northeim', 'Landkreis Oldenburg', 'Landkreis Osnabrück', 'Landkreis Osterholz', 'Landkreis Peine', 'Landkreis Rotenburg (Wümme)', 'Landkreis Schaumburg', 'Landkreis Stade', 'Landkreis Uelzen', 'Landkreis Vechta', 'Landkreis Verden', 'Landkreis Wesermarsch', 'Landkreis Wittmund', 'Landkreis Wolfenbüttel', 'Oldenburg (Stadt)', 'Osnabrück (Stadt)', 'Region Hannover', 'Salzgitter (Stadt)', 'Wilhelmshaven (Stadt)', 'Wolfsburg (Stadt)'],
    'Nordrhein-Westfalen' => ['Bielefeld (Stadt)', 'Bochum (Stadt)', 'Bonn (Stadt)', 'Bottrop (Stadt)', 'Dortmund (Stadt)', 'Duisburg (Stadt)', 'Düsseldorf (Stadt)', 'Ennepe-Ruhr-Kreis', 'Essen (Stadt)', 'Gelsenkirchen (Stadt)', 'Hagen (Stadt)', 'Hamm (Stadt)', 'Herne (Stadt)', 'Hochsauerlandkreis', 'Krefeld (Stadt)', 'Köln (Stadt)', 'Landkreis Borken', 'Landkreis Coesfeld', 'Landkreis Düren', 'Landkreis Euskirchen', 'Landkreis Gütersloh', 'Landkreis Heinsberg', 'Landkreis Herford', 'Landkreis Höxter', 'Landkreis Kleve', 'Landkreis Lippe', 'Landkreis Mettmann', 'Landkreis Minden-Lübbecke', 'Landkreis Olpe', 'Landkreis Paderborn', 'Landkreis Recklinghausen', 'Landkreis Siegen-Wittgenstein', 'Landkreis Soest', 'Landkreis Steinfurt', 'Landkreis Städteregion Aachen', 'Landkreis Unna', 'Landkreis Viersen', 'Landkreis Warendorf', 'Landkreis Wesel', 'Leverkusen (Stadt)', 'Märkischer Kreis', 'Mönchengladbach (Stadt)', 'Mülheim an der Ruhr (Stadt)', 'Münster (Stadt)', 'Oberbergischer Kreis', 'Oberhausen (Stadt)', 'Remscheid (Stadt)', 'Rhein-Erft-Kreis', 'Rhein-Kreis Neuss', 'Rhein-Sieg-Kreis', 'Rheinisch-Bergischer Kreis', 'Solingen (Stadt)', 'Wuppertal (Stadt)'],
    'Rheinland-Pfalz' => ['Donnersbergkreis', 'Eifelkreis Bitburg-Prüm', 'Frankenthal (Pfalz) (Stadt)', 'Kaiserslautern (Stadt)', 'Koblenz (Stadt)', 'Landau in der Pfalz (Stadt)', 'Landkreis Ahrweiler', 'Landkreis Altenkirchen (Westerwald)', 'Landkreis Alzey-Worms', 'Landkreis Bad Dürkheim', 'Landkreis Bad Kreuznach', 'Landkreis Bernkastel-Wittlich', 'Landkreis Birkenfeld', 'Landkreis Cochem-Zell', 'Landkreis Germersheim', 'Landkreis Kaiserslautern', 'Landkreis Kusel', 'Landkreis Mainz-Bingen', 'Landkreis Mayen-Koblenz', 'Landkreis Neuwied', 'Landkreis Südliche Weinstraße', 'Landkreis Südwestpfalz', 'Landkreis Trier-Saarburg', 'Landkreis Vulkaneifel', 'Ludwigshafen am Rhein (Stadt)', 'Mainz (Stadt)', 'Neustadt an der Weinstraße (Stadt)', 'Pirmasens (Stadt)', 'Rhein-Hunsrück-Kreis', 'Rhein-Lahn-Kreis', 'Rhein-Pfalz-Kreis', 'Speyer (Stadt)', 'Trier (Stadt)', 'Westerwaldkreis', 'Worms (Stadt)', 'Zweibrücken (Stadt)'],
    'Saarland' => ['Landkreis Merzig-Wadern', 'Landkreis Neunkirchen', 'Landkreis Saarlouis', 'Landkreis St. Wendel', 'Regionalverband Saarbrücken', 'Saarpfalz-Kreis'],
    'Sachsen' => ['Chemnitz (Stadt)', 'Dresden (Stadt)', 'Erzgebirgskreis', 'Landkreis Bautzen', 'Landkreis Görlitz', 'Landkreis Leipzig', 'Landkreis Meißen', 'Landkreis Mittelsachsen', 'Landkreis Nordsachsen', 'Landkreis Sächsische Schweiz-Osterzgebirge', 'Landkreis Zwickau', 'Leipzig (Stadt)', 'Vogtlandkreis'],
    'Sachsen-Anhalt' => ['Altmarkkreis Salzwedel', 'Burgenlandkreis', 'Dessau-Roßlau (Stadt)', 'Halle (Saale) (Stadt)', 'Landkreis Anhalt-Bitterfeld', 'Landkreis Börde', 'Landkreis Harz', 'Landkreis Jerichower Land', 'Landkreis Mansfeld-Südharz', 'Landkreis Stendal', 'Landkreis Wittenberg', 'Magdeburg (Stadt)', 'Saalekreis', 'Salzlandkreis'],
    'Schleswig-Holstein' => ['Flensburg (Stadt)', 'Kiel (Stadt)', 'Landkreis Dithmarschen', 'Landkreis Herzogtum Lauenburg', 'Landkreis Nordfriesland', 'Landkreis Ostholstein', 'Landkreis Pinneberg', 'Landkreis Plön', 'Landkreis Rendsburg-Eckernförde', 'Landkreis Schleswig-Flensburg', 'Landkreis Segeberg', 'Landkreis Steinburg', 'Landkreis Stormarn', 'Lübeck (Stadt)', 'Neumünster (Stadt)'],
    'Thüringen' => ['Erfurt (Stadt)', 'Gera (Stadt)', 'Ilm-Kreis', 'Jena (Stadt)', 'Kyffhäuserkreis', 'Landkreis Altenburger Land', 'Landkreis Eichsfeld', 'Landkreis Gotha', 'Landkreis Greiz', 'Landkreis Hildburghausen', 'Landkreis Nordhausen', 'Landkreis Saalfeld-Rudolstadt', 'Landkreis Schmalkalden-Meiningen', 'Landkreis Sonneberg', 'Landkreis Sömmerda', 'Landkreis Weimarer Land', 'Saale-Holzland-Kreis', 'Saale-Orla-Kreis', 'Suhl (Stadt)', 'Unstrut-Hainich-Kreis', 'Wartburgkreis', 'Weimar (Stadt)'],
];

/** Kodierte Geltungsbereich-Werte: 'de' | 'bl:<Land>' | 'kr:<Land>:<Kreis>'.
 *  @return array{0:string,1:?string}|null [scope_level, scope_name] */
function scope_decode(string $value): ?array
{
    if ($value === 'de') {
        return ['bund', null];
    }
    if (strpos($value, 'bl:') === 0) {
        $land = substr($value, 3);
        return isset(SW_REGIONS[$land]) ? ['bundesland', $land] : null;
    }
    if (strpos($value, 'kr:') === 0) {
        $parts = explode(':', substr($value, 3), 2);
        if (count($parts) === 2 && isset(SW_REGIONS[$parts[0]])
            && in_array($parts[1], SW_REGIONS[$parts[0]], true)) {
            return ['landkreis', $parts[1]];
        }
        return null;
    }
    return null;
}

/** Hierarchisches Auswahlfeld (eine Liste, wie im Behördenfinder). */
function scope_select(string $name, string $selected, bool $withAll): string
{
    $html = '<select name="' . e($name) . '">';
    if ($withAll) {
        $html .= '<option value="">' . e(t('topics.filter_all')) . '</option>';
    }
    $html .= '<option value="de"' . ($selected === 'de' ? ' selected' : '') . '>' . e(t('scope.bund')) . '</option>';
    foreach (SW_REGIONS as $land => $kreise) {
        $html .= '<optgroup label="' . e($land) . '">';
        $value = 'bl:' . $land;
        $html .= '<option value="' . e($value) . '"' . ($selected === $value ? ' selected' : '') . '>'
            . e($land) . ' (' . e(t('scope.bundesland')) . ')</option>';
        foreach ($kreise as $kreis) {
            $value = 'kr:' . $land . ':' . $kreis;
            $html .= '<option value="' . e($value) . '"' . ($selected === $value ? ' selected' : '') . '>'
                . e($kreis) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html . '</select>';
}

const SW_CATEGORIES = [
    ['umwelt-klima', 'Umwelt & Klima', 'Environment & Climate'],
    ['energie', 'Energie', 'Energy'],
    ['wirtschaft', 'Wirtschaft & Mittelstand', 'Economy & Business'],
    ['arbeit-soziales', 'Arbeit & Soziales', 'Labour & Social Affairs'],
    ['rente', 'Rente & Alterssicherung', 'Pensions'],
    ['gesundheit-pflege', 'Gesundheit & Pflege', 'Health & Care'],
    ['bildung-forschung', 'Bildung & Forschung', 'Education & Research'],
    ['familie-jugend', 'Familie & Jugend', 'Family & Youth'],
    ['migration-integration', 'Migration & Integration', 'Migration & Integration'],
    ['innere-sicherheit', 'Innere Sicherheit', 'Domestic Security'],
    ['justiz-buergerrechte', 'Justiz & Bürgerrechte', 'Justice & Civil Rights'],
    ['digitales', 'Digitales & Verwaltung', 'Digital Affairs & Administration'],
    ['verkehr', 'Verkehr & Infrastruktur', 'Transport & Infrastructure'],
    ['wohnen', 'Wohnen & Mieten', 'Housing & Rents'],
    ['landwirtschaft', 'Landwirtschaft & Ernährung', 'Agriculture & Food'],
    ['finanzen-steuern', 'Finanzen & Steuern', 'Finance & Taxes'],
    ['europa-aussen', 'Europa & Außenpolitik', 'Europe & Foreign Policy'],
    ['verteidigung', 'Verteidigung', 'Defence'],
    ['kultur-medien-sport', 'Kultur, Medien & Sport', 'Culture, Media & Sports'],
    ['verbraucherschutz', 'Verbraucherschutz', 'Consumer Protection'],
    ['kommunales', 'Kommunales & Ehrenamt', 'Local Affairs & Volunteering'],
    ['demokratie', 'Demokratie & Beteiligung', 'Democracy & Participation'],
];

/** Grunddaten: nur Kategorien + System-Konto. Themen werden nie vorbefüllt. */
function sw_seed_categories(): void
{
    if ((int) SW::$db->val('SELECT COUNT(*) FROM categories') > 0) {
        return;
    }
    SW::$db->tx(function (): void {
        foreach (SW_CATEGORIES as $i => $row) {
            SW::$db->run(
                'INSERT INTO categories (slug, name_de, name_en, sort_order) VALUES (?, ?, ?, ?)',
                [$row[0], $row[1], $row[2], $i]
            );
        }
        SW::$db->run(
            'INSERT INTO users (pseudonym_hash, lang, is_system, created_at) VALUES (?, ?, 1, ?)',
            ['system', 'de', Clock::nowStr()]
        );
    });
}

function categories(): array
{
    return SW::$db->all('SELECT * FROM categories ORDER BY sort_order, id');
}

function cat_name(array $row): string
{
    return SW::$lang === 'de' ? (string) $row['name_de'] : (string) $row['name_en'];
}

function topic_has_posted_today(int $userId): bool
{
    return null !== SW::$db->one(
        'SELECT 1 FROM topics WHERE author_id = ? AND created_date = ?',
        [$userId, Clock::localDate()]
    );
}

/** @throws DomainException mit Übersetzungsschlüssel */
function topic_create(int $userId, string $title, string $goal, string $reasoning, int $categoryId, string $scopeLevel, ?string $scopeName): int
{
    if (topic_has_posted_today($userId)) {
        throw new DomainException('flash.topic_daily_limit');
    }
    try {
        SW::$db->run(
            'INSERT INTO topics (author_id, title, goal, reasoning, category_id,
                                 scope_level, scope_name, created_at, created_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $title, $goal, $reasoning, $categoryId,
             $scopeLevel, $scopeName, Clock::nowStr(), Clock::localDate()]
        );
    } catch (PDOException $e) {
        throw new DomainException('flash.topic_daily_limit');
    }
    return SW::$db->lastId();
}

const SW_TOPIC_SELECT = "
    SELECT t.*, c.slug AS category_slug, c.name_de, c.name_en,
           (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'for')     AS votes_for,
           (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'against') AS votes_against
    FROM topics t
    JOIN categories c ON c.id = t.category_id";

/** @return array{rows:array,total:int} */
function topics_list(array $filters, int $page, int $perPage, ?int $userId): array
{
    $where = ["t.status = 'active'"];
    $params = [];
    if (!empty($filters['category'])) {
        $where[] = 'c.slug = :cat';
        $params[':cat'] = $filters['category'];
    }
    if (!empty($filters['level'])) {
        $where[] = 't.scope_level = :level';
        $params[':level'] = $filters['level'];
    }
    if (!empty($filters['scope'])) {
        $where[] = 't.scope_name = :scope';
        $params[':scope'] = $filters['scope'];
    }
    if (!empty($filters['q'])) {
        $where[] = "t.title LIKE :q ESCAPE '\\'";
        $params[':q'] = '%' . addcslashes($filters['q'], '%_\\') . '%';
    }
    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $total = (int) SW::$db->val(
        'SELECT COUNT(*) FROM topics t JOIN categories c ON c.id = t.category_id' . $whereSql,
        $params
    );
    $order = ($filters['sort'] ?? 'new') === 'top'
        ? ' ORDER BY (votes_for + votes_against) DESC, t.created_at DESC'
        : ' ORDER BY t.created_at DESC';
    $cols = "t.*, c.slug AS category_slug, c.name_de, c.name_en,
        (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'for')     AS votes_for,
        (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'against') AS votes_against";
    if ($userId !== null) {
        $cols .= ', (SELECT choice FROM votes mv WHERE mv.topic_id = t.id AND mv.user_id = :uid) AS my_choice';
        $params[':uid'] = $userId;
    }
    $select = 'SELECT ' . $cols . ' FROM topics t JOIN categories c ON c.id = t.category_id';
    $params[':limit'] = $perPage;
    $params[':offset'] = max(0, ($page - 1) * $perPage);
    $rows = SW::$db->all($select . $whereSql . $order . ' LIMIT :limit OFFSET :offset', $params);
    return ['rows' => $rows, 'total' => $total];
}

function topic_find(int $id): ?array
{
    return SW::$db->one(SW_TOPIC_SELECT . ' WHERE t.id = ?', [$id]);
}

function topic_user_vote(int $topicId, int $userId): ?string
{
    $v = SW::$db->val('SELECT choice FROM votes WHERE topic_id = ? AND user_id = ?', [$topicId, $userId]);
    return $v === null ? null : (string) $v;
}

function topics_by_author(int $userId): array
{
    return SW::$db->all(SW_TOPIC_SELECT . ' WHERE t.author_id = ? ORDER BY t.created_at DESC', [$userId]);
}

function topics_voted_by(int $userId): array
{
    return SW::$db->all(
        "SELECT t.id, t.title, t.status, c.name_de, c.name_en,
                v.choice AS my_choice, v.updated_at AS voted_at,
                (SELECT COUNT(*) FROM votes x WHERE x.topic_id = t.id AND x.choice = 'for')     AS votes_for,
                (SELECT COUNT(*) FROM votes x WHERE x.topic_id = t.id AND x.choice = 'against') AS votes_against
         FROM votes v
         JOIN topics t     ON t.id = v.topic_id
         JOIN categories c ON c.id = t.category_id
         WHERE v.user_id = ?
         ORDER BY v.updated_at DESC",
        [$userId]
    );
}

function site_stats(): array
{
    return [
        'topics' => (int) SW::$db->val("SELECT COUNT(*) FROM topics WHERE status = 'active'"),
        'votes'  => (int) SW::$db->val('SELECT COUNT(*) FROM votes'),
        'users'  => (int) SW::$db->val('SELECT COUNT(*) FROM users WHERE is_system = 0'),
    ];
}

/* ============================== Stimmen & Favoriten ======================= */

/** @throws DomainException */
function vote_cast(int $userId, int $topicId, string $choice): void
{
    if (!in_array($choice, ['for', 'against', 'none'], true)) {
        throw new DomainException('flash.invalid_input');
    }
    $status = SW::$db->val('SELECT status FROM topics WHERE id = ?', [$topicId]);
    if ($status !== 'active') {
        throw new DomainException('flash.topic_not_votable');
    }
    if ($choice === 'none') {
        SW::$db->run('DELETE FROM votes WHERE topic_id = ? AND user_id = ?', [$topicId, $userId]);
        return;
    }
    $now = Clock::nowStr();
    SW::$db->run(
        'INSERT INTO votes (topic_id, user_id, choice, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
         ON CONFLICT(topic_id, user_id) DO UPDATE SET choice = excluded.choice, updated_at = excluded.updated_at',
        [$topicId, $userId, $choice, $now, $now]
    );
}

function fav_valid(string $kind, string $ref): bool
{
    if ($kind === 'category') {
        return null !== SW::$db->one('SELECT 1 FROM categories WHERE slug = ?', [$ref]);
    }
    if ($kind === 'scope') {
        if ($ref === 'bund') {
            return true;
        }
        $parts = explode(':', $ref, 2);
        if (count($parts) !== 2) {
            return false;
        }
        if ($parts[0] === 'bundesland') {
            return isset(SW_REGIONS[$parts[1]]);
        }
        if ($parts[0] === 'landkreis') {
            foreach (SW_REGIONS as $kreise) {
                if (in_array($parts[1], $kreise, true)) {
                    return true;
                }
            }
        }
        return false;
    }
    return false;
}

/** @throws DomainException */
function fav_toggle(int $userId, string $kind, string $ref): bool
{
    if (!fav_valid($kind, $ref)) {
        throw new DomainException('flash.invalid_input');
    }
    $exists = SW::$db->one('SELECT 1 FROM favorites WHERE user_id = ? AND kind = ? AND ref = ?', [$userId, $kind, $ref]);
    if ($exists !== null) {
        SW::$db->run('DELETE FROM favorites WHERE user_id = ? AND kind = ? AND ref = ?', [$userId, $kind, $ref]);
        return false;
    }
    SW::$db->run(
        'INSERT INTO favorites (user_id, kind, ref, created_at) VALUES (?, ?, ?, ?)',
        [$userId, $kind, $ref, Clock::nowStr()]
    );
    return true;
}

function fav_is(int $userId, string $kind, string $ref): bool
{
    return null !== SW::$db->one('SELECT 1 FROM favorites WHERE user_id = ? AND kind = ? AND ref = ?', [$userId, $kind, $ref]);
}

function fav_list(int $userId): array
{
    return SW::$db->all(
        'SELECT f.kind, f.ref, c.name_de, c.name_en
         FROM favorites f
         LEFT JOIN categories c ON f.kind = \'category\' AND c.slug = f.ref
         WHERE f.user_id = ?
         ORDER BY f.kind, f.ref',
        [$userId]
    );
}

/* ============================== Meldungen & Jury ========================== */

const SW_CRITERIA = ['volksverhetzung', 'kennzeichen', 'gewalt', 'terror', 'beleidigung', 'bedrohung', 'privatdaten', 'sonstiges'];
const SW_FREETEXT_MAX = 1000;

function report_open_for(int $topicId): ?array
{
    return SW::$db->one(
        "SELECT * FROM reports WHERE topic_id = ? AND status IN ('pending','voting')",
        [$topicId]
    );
}

function reports_by(int $userId): array
{
    return SW::$db->all(
        'SELECT r.id, r.status, r.created_at, r.decided_at, t.id AS topic_id, t.title
         FROM reports r JOIN topics t ON t.id = r.topic_id
         WHERE r.reporter_id = ?
         ORDER BY r.created_at DESC',
        [$userId]
    );
}

function reports_today_by(int $reporterId): int
{
    $dayEnd = Clock::nextLocalMidnightUtcStr();
    $dayStart = Clock::addDaysStr($dayEnd, -1);
    return (int) SW::$db->val(
        'SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND created_at >= ? AND created_at < ?',
        [$reporterId, $dayStart, $dayEnd]
    );
}

/** Jury-Auslosung: 1 % der Nutzerschaft (mind. jury_min), CSPRNG-Mischung.
 *  Ausgeschlossen: Melder, Autor, aktive Juroren offener Meldungen, Karenz. */
function jury_draw(int $reporterId, int $authorId, int $totalUsers): array
{
    $eligible = SW::$db->all(
        "SELECT u.id FROM users u
         WHERE u.is_system = 0
           AND u.id NOT IN (?, ?)
           AND (u.jury_cooldown_until IS NULL OR u.jury_cooldown_until <= ?)
           AND u.id NOT IN (
               SELECT rj.user_id FROM report_jurors rj
               JOIN reports r ON r.id = rj.report_id
               WHERE r.status IN ('pending','voting')
           )",
        [$reporterId, $authorId, Clock::nowStr()]
    );
    $ids = array_map(static function (array $row): int {
        return (int) $row['id'];
    }, $eligible);
    $target = max((int) SW::$cfg['jury_min'], (int) ceil($totalUsers * (float) SW::$cfg['jury_share']));
    $count = count($ids);
    if ($count <= $target) {
        return $ids;
    }
    for ($i = $count - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $ids[$i];
        $ids[$i] = $ids[$j];
        $ids[$j] = $tmp;
    }
    return array_slice($ids, 0, $target);
}

/** @param list<string> $criteria @throws DomainException */
function report_create(int $topicId, int $reporterId, array $criteria, ?string $freetext): int
{
    $criteria = array_values(array_unique($criteria));
    if ($criteria === [] || array_diff($criteria, SW_CRITERIA) !== []) {
        throw new DomainException('flash.invalid_input');
    }
    if ($freetext !== null && mb_strlen($freetext) > SW_FREETEXT_MAX) {
        throw new DomainException('flash.invalid_input');
    }
    $topic = SW::$db->one('SELECT id, author_id, status FROM topics WHERE id = ?', [$topicId]);
    if ($topic === null || $topic['status'] !== 'active') {
        throw new DomainException('flash.topic_not_reportable');
    }
    return SW::$db->tx(function () use ($topicId, $reporterId, $criteria, $freetext, $topic): int {
        if (report_open_for($topicId) !== null) {
            throw new DomainException('flash.report_already_open');
        }
        $mine = SW::$db->one('SELECT 1 FROM reports WHERE topic_id = ? AND reporter_id = ?', [$topicId, $reporterId]);
        if ($mine !== null) {
            throw new DomainException('flash.report_duplicate');
        }
        if (reports_today_by($reporterId) >= (int) SW::$cfg['reports_per_day']) {
            throw new DomainException('flash.report_daily_limit');
        }
        $totalUsers = (int) SW::$db->val('SELECT COUNT(*) FROM users WHERE is_system = 0');
        $jurors = jury_draw($reporterId, (int) $topic['author_id'], $totalUsers);
        if ($jurors === []) {
            throw new DomainException('flash.report_too_few_users');
        }
        $quorum = min(
            count($jurors),
            max((int) SW::$cfg['quorum_min'], (int) ceil($totalUsers * (float) SW::$cfg['quorum_share']))
        );
        SW::$db->run(
            'INSERT INTO reports (topic_id, reporter_id, criteria, freetext, status,
                                  jury_size, quorum, created_at, voting_starts_at)
             VALUES (?, ?, ?, ?, \'pending\', ?, ?, ?, ?)',
            [$topicId, $reporterId, json_encode($criteria, JSON_THROW_ON_ERROR), $freetext,
             count($jurors), $quorum, Clock::nowStr(), Clock::nextLocalMidnightUtcStr()]
        );
        $reportId = SW::$db->lastId();
        foreach ($jurors as $jurorId) {
            SW::$db->run('INSERT INTO report_jurors (report_id, user_id) VALUES (?, ?)', [$reportId, $jurorId]);
        }
        return $reportId;
    });
}

function jury_pending_for(int $userId): ?array
{
    return SW::$db->one(
        "SELECT r.*, t.title, t.goal, t.reasoning
         FROM report_jurors rj
         JOIN reports r ON r.id = rj.report_id
         JOIN topics t  ON t.id = r.topic_id
         WHERE rj.user_id = ? AND rj.vote IS NULL AND r.status = 'voting'
         ORDER BY r.voting_starts_at
         LIMIT 1",
        [$userId]
    );
}

function jury_upcoming_for(int $userId): ?array
{
    return SW::$db->one(
        "SELECT r.id, r.voting_starts_at
         FROM report_jurors rj JOIN reports r ON r.id = rj.report_id
         WHERE rj.user_id = ? AND rj.vote IS NULL AND r.status = 'pending'
         ORDER BY r.voting_starts_at LIMIT 1",
        [$userId]
    );
}

/** @return array{seats:int,cast:int,confirm:int,reject:int,neutral:int} */
function jury_tally(int $reportId): array
{
    $row = SW::$db->one(
        "SELECT COUNT(*) AS seats, COUNT(vote) AS cast,
                SUM(CASE WHEN vote = 'confirm' THEN 1 ELSE 0 END) AS confirm,
                SUM(CASE WHEN vote = 'reject'  THEN 1 ELSE 0 END) AS reject,
                SUM(CASE WHEN vote = 'neutral' THEN 1 ELSE 0 END) AS neutral
         FROM report_jurors WHERE report_id = ?",
        [$reportId]
    );
    return [
        'seats'   => (int) ($row['seats'] ?? 0),
        'cast'    => (int) ($row['cast'] ?? 0),
        'confirm' => (int) ($row['confirm'] ?? 0),
        'reject'  => (int) ($row['reject'] ?? 0),
        'neutral' => (int) ($row['neutral'] ?? 0),
    ];
}

function jury_deadline(array $report): string
{
    return Clock::addHoursStr((string) $report['voting_starts_at'], (int) SW::$cfg['report_vote_hours']);
}

/** Zählt aus und entscheidet, wenn Frist abgelaufen und Quorum erreicht. */
function jury_decide_if_due(array $report): void
{
    if (Clock::nowStr() < jury_deadline($report)) {
        return;
    }
    $tally = jury_tally((int) $report['id']);
    $quorumEffective = min((int) $report['quorum'], $tally['seats']);
    if ($tally['cast'] < $quorumEffective) {
        return;
    }
    $removed = $tally['confirm'] > $tally['reject'];
    $now = Clock::nowStr();
    SW::$db->run(
        'UPDATE reports SET status = ?, decided_at = ? WHERE id = ?',
        [$removed ? 'decided_removed' : 'decided_kept', $now, (int) $report['id']]
    );
    if ($removed) {
        SW::$db->run("UPDATE topics SET status = 'removed' WHERE id = ?", [(int) $report['topic_id']]);
    }
    $cooldownUntil = Clock::addDaysStr($now, (int) SW::$cfg['jury_cooldown_days']);
    SW::$db->run(
        'UPDATE users SET jury_cooldown_until = ?
         WHERE id IN (SELECT user_id FROM report_jurors WHERE report_id = ?)',
        [$cooldownUntil, (int) $report['id']]
    );
}

/** @throws DomainException */
function jury_cast(int $reportId, int $userId, string $vote): void
{
    if (!in_array($vote, ['confirm', 'reject', 'neutral'], true)) {
        throw new DomainException('flash.invalid_input');
    }
    SW::$db->tx(function () use ($reportId, $userId, $vote): void {
        $report = SW::$db->one('SELECT * FROM reports WHERE id = ?', [$reportId]);
        if ($report === null || $report['status'] !== 'voting') {
            throw new DomainException('flash.jury_not_open');
        }
        $seat = SW::$db->one('SELECT vote FROM report_jurors WHERE report_id = ? AND user_id = ?', [$reportId, $userId]);
        if ($seat === null) {
            throw new DomainException('flash.jury_not_member');
        }
        if ($seat['vote'] !== null) {
            throw new DomainException('flash.jury_already_voted');
        }
        SW::$db->run(
            'UPDATE report_jurors SET vote = ?, voted_at = ? WHERE report_id = ? AND user_id = ?',
            [$vote, Clock::nowStr(), $reportId, $userId]
        );
        jury_decide_if_due($report);
    });
}

/** Idempotenter Wartungslauf: 00:00-Starts, fällige Entscheidungen, Aufräumen. */
function maintenance_tick(): void
{
    SW::$db->run(
        "UPDATE reports SET status = 'voting' WHERE status = 'pending' AND voting_starts_at <= ?",
        [Clock::nowStr()]
    );
    foreach (SW::$db->all("SELECT * FROM reports WHERE status = 'voting'") as $report) {
        SW::$db->tx(function () use ($report): void {
            jury_decide_if_due($report);
        });
    }
    rate_gc();
}

function maintenance_tick_throttled(): void
{
    $now = Clock::now()->getTimestamp();
    $last = (int) (SW::$db->val("SELECT v FROM schema_info WHERE k = 'last_tick'") ?? 0);
    if (($now - $last) < 30) {
        return;
    }
    SW::$db->run(
        "INSERT INTO schema_info (k, v) VALUES ('last_tick', ?)
         ON CONFLICT(k) DO UPDATE SET v = excluded.v",
        [(string) $now]
    );
    maintenance_tick();
}

/** Kontolöschung (DSGVO): Stimmen/Favoriten/Jury-Sitze weg; Beiträge werden
 *  dauerhaft vom Pseudonym entkoppelt (System-Konto bzw. NULL). */
function account_delete(int $userId): void
{
    SW::$db->tx(function () use ($userId): void {
        $systemId = (int) SW::$db->val('SELECT id FROM users WHERE is_system = 1 LIMIT 1');
        SW::$db->run('UPDATE topics SET author_id = ? WHERE author_id = ?', [$systemId, $userId]);
        SW::$db->run('DELETE FROM users WHERE id = ?', [$userId]);
    });
}

/* ============================== Sprachen ================================= */

const SW_DE = [
    'app.tagline' => 'Digitale Bürgerbeteiligung',
    'banner.test' => 'Testbetrieb – keine offizielle Seite der Bundesregierung oder einer Behörde.',
    'a11y.skip' => 'Zum Inhalt springen',
    'nav.topics' => 'Themen',
    'nav.new' => 'Thema einbringen',
    'nav.overview' => 'Meine Übersicht',
    'nav.jury' => 'Jury',
    'auth.login' => 'Ausweis anhalten',
    'auth.logout' => 'Abmelden',
    'lang.switch' => 'Sprache',
    'common.date_format' => 'd.m.Y',
    'common.datetime_format' => 'd.m.Y, H:i',
    'common.back_home' => 'Zur Startseite',

    'home.title' => 'Digitale Bürgerbeteiligung',
    'home.intro' => 'Themen einbringen und abstimmen – mit dem Personalausweis, ohne Konto und ohne Klarnamen.',
    'home.locked' => 'Stimmen, Favoriten und Jury-Aufgaben werden nach dem Anhalten des Ausweises sichtbar.',
    'home.personal' => 'Mein Bereich',
    'home.p_votes' => 'Abgegebene Stimmen',
    'home.p_favorites' => 'Favoriten',
    'home.p_jury' => 'Meldeverfahren',
    'home.p_jury_open' => 'Jury-Stimme erforderlich',
    'home.p_jury_upcoming' => 'Ausgelost, Beginn {date}, 00:00 Uhr',
    'home.p_jury_none' => 'keine Aufgabe',
    'home.stat_topics' => 'Aktive Themen',
    'home.stat_votes' => 'Abgegebene Stimmen',
    'home.stat_users' => 'Registrierte Ausweise',
    'home.latest' => 'Neueste Themen',
    'home.all_topics' => 'Alle Themen',

    'topics.title' => 'Themen',
    'topics.count' => '{n} Themen',
    'topics.filter_category' => 'Kategorie',
        'topics.filter_all' => 'Alle',
    'topics.search' => 'Suche im Titel',
    'topics.sort' => 'Sortierung',
    'topics.sort_new' => 'Neueste',
    'topics.sort_top' => 'Meiste Stimmen',
    'topics.apply' => 'Filtern',
    'topics.none' => 'Keine Themen gefunden.',
    'topics.none_yet' => 'Noch keine Themen vorhanden.',
    'topics.prev' => 'Zurück',
    'topics.next' => 'Weiter',
    'topics.page_of' => 'Seite {p} von {n}',

    'scope.bund' => 'Deutschland',
    'scope.bundesland' => 'Bundesland',
    'scope.landkreis' => 'Landkreis',
    'scope.kommune' => 'Kommune',

    'topic.goal_label' => 'Ziel',
    'topic.reasoning_label' => 'Begründung',
    'topic.created' => 'Eingebracht am {date}',
    'topic.report_link' => 'Inhalt melden',
    'topic.report_open' => 'Gemeinschaftsprüfung läuft.',
    'topic.fav_add' => 'Kategorie favorisieren',
    'topic.fav_remove' => 'Kategorie-Favorit entfernen',
    'topic.fav_scope_add' => 'Gebiet favorisieren',
    'topic.fav_scope_remove' => 'Gebiets-Favorit entfernen',
    'topic.removed_title' => 'Inhalt entfernt',
    'topic.removed_text' => 'Dieser Beitrag wurde nach Prüfung durch eine ausgeloste Bürger-Jury entfernt.',
    'topic.your_vote' => 'Ihre Stimme: {choice}',

    'vote.for' => 'Dafür',
    'vote.against' => 'Dagegen',
    'vote.withdraw' => 'Stimme zurückziehen',
    'vote.total' => '{n} Stimmen abgegeben',
    'vote.none_yet' => 'Noch keine Stimmen.',
    'vote.login_hint' => 'Zum Abstimmen Ausweis anhalten',
    'vote.neutral_hint' => 'Enthaltung = keine Stimme abgeben.',
    'vote.bar_aria' => 'Abstimmungsergebnis',
    'vote.signed' => 'Jede Änderung wird mit dem Ausweis-Schlüssel bestätigt.',

    'topic.new_title' => 'Thema einbringen',
    'topic.new_intro' => 'Ein Thema pro Person und Tag. Nach Veröffentlichung nicht mehr änderbar.',
    'topic.posted_today' => 'Heute bereits ein Thema eingebracht. Das nächste ist ab 00:00 Uhr möglich.',
    'topic.next_in' => 'Nächstes Thema in',
    'topic.f_title' => 'Titel',
    'topic.f_goal' => 'Ziel',
    'topic.f_reasoning' => 'Begründung',
    'topic.f_category' => 'Kategorie',
    'topic.f_scope' => 'Geltungsbereich',
    'topic.f_choose' => 'Bitte wählen',
    'topic.submit' => 'Veröffentlichen',
    'topic.err_title' => 'Titel: mindestens 8 Zeichen.',
    'topic.err_goal' => 'Ziel: mindestens 10 Zeichen.',
    'topic.err_reasoning' => 'Begründung: mindestens 10 Zeichen.',
    'topic.err_category' => 'Bitte eine Kategorie wählen.',
    'topic.err_scope' => 'Bitte einen Geltungsbereich wählen.',

    'auth.title' => 'Ausweis anhalten',
    'auth.line' => 'Der Ausweis meldet sich mit seinem öffentlichen Schlüssel an – zeitlich begrenzt, ohne Namen. Das Anhalten lädt Ihre profil.yaml.',
    'auth.tap' => 'Ausweis anhalten',
    'auth.hold' => 'Ausweis an das Gerät halten …',
    'auth.other_card' => 'Anderen Ausweis verwenden',

    'me.title' => 'Meine Übersicht',
    'me.short_id' => 'Öffentlicher Schlüssel (Kurzform)',
    'me.since' => 'Dabei seit {date}',
    'me.sec_votes' => 'Meine Stimmen',
    'me.sec_topics' => 'Meine Themen',
    'me.sec_favorites' => 'Favoriten',
    'me.sec_reports' => 'Meine Meldungen',
    'me.sec_jury' => 'Jury',
    'me.none' => 'Keine Einträge.',
    'me.status_active' => 'aktiv',
    'me.status_removed' => 'entfernt',
    'me.report_pending' => 'wartet auf Start (00:00)',
    'me.report_voting' => 'Jury stimmt ab',
    'me.report_removed' => 'Inhalt entfernt',
    'me.report_kept' => 'Inhalt bleibt',
    'me.fav_category' => 'Kategorie',
    'me.fav_scope' => 'Gebiet',
    'me.unfav' => 'Entfernen',
    'me.jury_pending' => 'Offene Jury-Aufgabe.',
    'me.jury_upcoming' => 'Ausgelost; Abstimmung ab {date}, 00:00 Uhr.',
    'me.jury_none' => 'Keine Jury-Aufgabe.',
    'me.jury_go' => 'Zur Jury-Aufgabe',
    'me.cooldown' => 'Jury-Karenz bis {date}.',
    'me.profile' => 'Profil (profil.yaml)',
    'me.download' => 'profil.yaml herunterladen',
    'me.logout_note' => 'Abmelden löscht die profil.yaml aus dem Browser.',
    'me.delete_title' => 'Konto und Daten löschen',
    'me.delete_text' => 'Stimmen, Favoriten und offene Jury-Sitze werden gelöscht. Beiträge bleiben, werden aber dauerhaft vom Pseudonym entkoppelt.',
    'me.delete_confirm' => 'Ja, endgültig löschen',
    'me.delete_button' => 'Konto löschen',

    'jury.title' => 'Bürger-Jury',
    'jury.intro' => 'Sie wurden per Los ausgewählt. Bitte bewerten Sie den gemeldeten Inhalt anhand der Kriterien; Enthaltung ist zulässig.',
    'jury.blocked' => 'Bis zur Stimmabgabe sind die übrigen Funktionen gesperrt – oder abwarten, bis die Prüfung endet.',
    'jury.none' => 'Keine Jury-Aufgabe.',
    'jury.upcoming' => 'Ausgelost; Abstimmung ab {date}, 00:00 Uhr. Bis dahin ist nichts zu tun.',
    'jury.reported' => 'Gemeldeter Inhalt',
    'jury.criteria' => 'Angegebene Kriterien',
    'jury.freetext' => 'Ergänzung der meldenden Person',
    'jury.question' => 'Verstößt der Inhalt gegen die angegebenen Kriterien?',
    'jury.confirm' => 'Ja – entfernen',
    'jury.reject' => 'Nein – behalten',
    'jury.neutral' => 'Enthaltung',
    'jury.stats' => '{cast} von {seats} Stimmen abgegeben · Quorum: {quorum}',
    'jury.deadline' => 'Reguläres Ende: {date}; danach wird bei erreichtem Quorum entschieden.',

    'report.title' => 'Inhalt melden',
    'report.intro' => 'Nur mutmaßlich rechtswidrige Inhalte melden – politische Meinungen sind kein Meldegrund.',
    'report.criteria' => 'Kriterien (mindestens eines)',
    'report.freetext' => 'Ergänzung (optional)',
    'report.process' => 'Es entscheidet eine ausgeloste Bürger-Jury (1 %): Abstimmung ab 00:00 Uhr, 24 Stunden, Quorum 0,5 %.',
    'report.submit' => 'Meldung abschicken',
    'report.cancel' => 'Abbrechen',

    'criteria.volksverhetzung' => 'Volksverhetzung (§ 130 StGB)',
    'criteria.kennzeichen' => 'Verbotene Kennzeichen (§§ 86, 86a StGB)',
    'criteria.gewalt' => 'Aufruf zu Gewalt oder Straftaten (§§ 111, 126 StGB)',
    'criteria.terror' => 'Terror-Propaganda (§§ 86, 129a/b StGB)',
    'criteria.beleidigung' => 'Beleidigung, üble Nachrede, Verleumdung (§§ 185–187 StGB)',
    'criteria.bedrohung' => 'Bedrohung (§ 241 StGB)',
    'criteria.privatdaten' => 'Veröffentlichung privater Daten (Doxxing)',
    'criteria.sonstiges' => 'Sonstiger mutmaßlich strafbarer Inhalt',

    'flash.session_expired' => 'Sitzung beendet. Bitte Ausweis erneut anhalten.',
    'flash.auth_expired' => 'Anmeldung abgelaufen – bitte Ausweis erneut anhalten.',
    'flash.login_required' => 'Bitte zuerst den Ausweis anhalten.',
    'flash.card_required' => 'Bestätigung fehlgeschlagen. Bitte Ausweis erneut anhalten.',
    'flash.rate_limited' => 'Zu viele Anfragen. Bitte kurz warten.',
    'flash.csrf' => 'Anfrage konnte nicht zugeordnet werden. Bitte erneut versuchen.',
    'flash.invalid_input' => 'Ungültige Eingabe.',
    'flash.topic_daily_limit' => 'Heute bereits ein Thema eingebracht; das nächste ab 00:00 Uhr.',
    'flash.topic_created' => 'Thema veröffentlicht.',
    'flash.topic_not_votable' => 'Abstimmung nicht möglich.',
    'flash.topic_not_reportable' => 'Meldung nicht möglich.',
    'flash.vote_saved' => 'Stimme gespeichert.',
    'flash.vote_withdrawn' => 'Stimme zurückgezogen.',
    'flash.favorite_added' => 'Favorit hinzugefügt.',
    'flash.favorite_removed' => 'Favorit entfernt.',
    'flash.report_already_open' => 'Für dieses Thema läuft bereits eine Prüfung.',
    'flash.report_duplicate' => 'Dieses Thema wurde von Ihnen bereits gemeldet.',
    'flash.report_daily_limit' => 'Tageslimit für Meldungen erreicht.',
    'flash.report_too_few_users' => 'Für eine Jury sind derzeit zu wenige Teilnehmende registriert.',
    'flash.report_created' => 'Meldung aufgenommen. Die Jury ist ausgelost; Abstimmung ab 00:00 Uhr.',
    'flash.report_no_criteria' => 'Bitte mindestens ein Kriterium wählen.',
    'flash.jury_not_open' => 'Diese Abstimmung ist nicht (mehr) offen.',
    'flash.jury_not_member' => 'Keine Berechtigung für diese Jury.',
    'flash.jury_already_voted' => 'In dieser Prüfung wurde bereits abgestimmt.',
    'flash.jury_voted' => 'Jury-Stimme gezählt.',
    'flash.auth_failed' => 'Anmeldung fehlgeschlagen.',
    'flash.auth_ok' => 'Angemeldet.',
    'flash.card_new' => 'Bereit für einen anderen Ausweis. Zum Anmelden anhalten.',
    'flash.logged_out' => 'Abgemeldet.',
    'flash.delete_not_confirmed' => 'Bitte die Löschung bestätigen.',
    'flash.account_deleted' => 'Konto gelöscht.',

    'error.not_found_title' => 'Seite nicht gefunden',
    'error.not_found' => 'Die angeforderte Seite existiert nicht oder wurde entfernt.',
    'error.generic_title' => 'Fehler',
    'error.generic' => 'Es ist ein Fehler aufgetreten. Bitte später erneut versuchen.',
    'error.method' => 'Anfrageart nicht unterstützt.',

    'footer.imprint' => 'Impressum',
    'footer.privacy' => 'Datenschutz',
    'footer.note' => 'Konzept- und Demonstrationsprojekt',

    'imprint.h' => 'Impressum',
    'imprint.p1' => 'Musterangaben – vor Aufnahme eines echten Betriebs vollständig auszufüllen (Betreiber, Anschrift, Vertretungsberechtigte, Kontakt, Aufsicht).',
    'imprint.p2' => 'Dieses Projekt befindet sich im Testbetrieb und ist keine offizielle Seite der Bundesregierung oder einer Behörde.',

    'privacy.h' => 'Datenschutz',
    'privacy.p1' => 'Es werden weder Name noch Anschrift, Geburtsdatum oder E-Mail-Adresse verarbeitet.',
    'privacy.p2' => 'Beim Anhalten des Ausweises erhält die Seite nur einen öffentlichen Schlüssel und speichert davon ausschließlich ein Pseudonym (Hash mit serverseitigem Geheimnis).',
    'privacy.p3' => 'Genau ein technisch notwendiges Sitzungs-Cookie. Darüber hinaus wird nichts im Browser gespeichert – keine weiteren Cookies, kein localStorage, keine Tracker, keine Drittinhalte.',
    'privacy.p4' => 'Zur Missbrauchsabwehr werden kurzlebige, gehashte Kennungen für Ratenbegrenzungen verarbeitet und automatisch gelöscht.',
    'privacy.p5' => 'Das Konto kann jederzeit in „Meine Übersicht“ gelöscht werden.',
];

const SW_EN = [
    'app.tagline' => 'Digital citizen participation',
    'banner.test' => 'Test operation – not an official website of the German federal government or any public authority.',
    'a11y.skip' => 'Skip to content',
    'nav.topics' => 'Topics',
    'nav.new' => 'Raise a topic',
    'nav.overview' => 'My overview',
    'nav.jury' => 'Jury',
    'auth.login' => 'Tap your ID card',
    'auth.logout' => 'Sign out',
    'lang.switch' => 'Language',
    'common.date_format' => 'Y-m-d',
    'common.datetime_format' => 'Y-m-d, H:i',
    'common.back_home' => 'Back to start page',

    'home.title' => 'Digital citizen participation',
    'home.intro' => 'Raise topics and vote – with the German ID card, without an account and without real names.',
    'home.locked' => 'Votes, favourites and jury tasks become visible after tapping your ID card.',
    'home.personal' => 'My area',
    'home.p_votes' => 'Votes cast',
    'home.p_favorites' => 'Favourites',
    'home.p_jury' => 'Review procedure',
    'home.p_jury_open' => 'Jury vote required',
    'home.p_jury_upcoming' => 'Drawn, starts {date}, midnight',
    'home.p_jury_none' => 'no task',
    'home.stat_topics' => 'Active topics',
    'home.stat_votes' => 'Votes cast',
    'home.stat_users' => 'Registered ID cards',
    'home.latest' => 'Latest topics',
    'home.all_topics' => 'All topics',

    'topics.title' => 'Topics',
    'topics.count' => '{n} topics',
    'topics.filter_category' => 'Category',
        'topics.filter_all' => 'All',
    'topics.search' => 'Search titles',
    'topics.sort' => 'Sort',
    'topics.sort_new' => 'Newest',
    'topics.sort_top' => 'Most votes',
    'topics.apply' => 'Apply',
    'topics.none' => 'No topics found.',
    'topics.none_yet' => 'No topics yet.',
    'topics.prev' => 'Previous',
    'topics.next' => 'Next',
    'topics.page_of' => 'Page {p} of {n}',

    'scope.bund' => 'Germany',
    'scope.bundesland' => 'Federal state',
    'scope.landkreis' => 'District',
    'scope.kommune' => 'Municipality',

    'topic.goal_label' => 'Goal',
    'topic.reasoning_label' => 'Reasoning',
    'topic.created' => 'Raised on {date}',
    'topic.report_link' => 'Report content',
    'topic.report_open' => 'Community review in progress.',
    'topic.fav_add' => 'Add category to favourites',
    'topic.fav_remove' => 'Remove category favourite',
    'topic.fav_scope_add' => 'Add area to favourites',
    'topic.fav_scope_remove' => 'Remove area favourite',
    'topic.removed_title' => 'Content removed',
    'topic.removed_text' => 'This contribution was removed after review by a randomly drawn citizen jury.',
    'topic.your_vote' => 'Your vote: {choice}',

    'vote.for' => 'For',
    'vote.against' => 'Against',
    'vote.withdraw' => 'Withdraw vote',
    'vote.total' => '{n} votes cast',
    'vote.none_yet' => 'No votes yet.',
    'vote.login_hint' => 'Tap your ID card to vote',
    'vote.neutral_hint' => 'Abstaining = casting no vote.',
    'vote.bar_aria' => 'Voting result',
    'vote.signed' => 'Every change is confirmed with the ID card key.',

    'topic.new_title' => 'Raise a topic',
    'topic.new_intro' => 'One topic per person per day. Cannot be edited after publication.',
    'topic.posted_today' => 'You already raised a topic today. The next one is possible from midnight.',
    'topic.next_in' => 'Next topic in',
    'topic.f_title' => 'Title',
    'topic.f_goal' => 'Goal',
    'topic.f_reasoning' => 'Reasoning',
    'topic.f_category' => 'Category',
    'topic.f_scope' => 'Jurisdiction',
    'topic.f_choose' => 'Please choose',
    'topic.submit' => 'Publish',
    'topic.err_title' => 'Title: at least 8 characters.',
    'topic.err_goal' => 'Goal: at least 10 characters.',
    'topic.err_reasoning' => 'Reasoning: at least 10 characters.',
    'topic.err_category' => 'Please choose a category.',
    'topic.err_scope' => 'Please choose a jurisdiction.',

    'auth.title' => 'Tap your ID card',
    'auth.line' => 'The card signs in with its public key – time-limited, without a name. Tapping loads your profil.yaml.',
    'auth.tap' => 'Tap your ID card',
    'auth.hold' => 'Hold your ID card to the device …',
    'auth.other_card' => 'Use a different ID card',

    'me.title' => 'My overview',
    'me.short_id' => 'Public key (short form)',
    'me.since' => 'Member since {date}',
    'me.sec_votes' => 'My votes',
    'me.sec_topics' => 'My topics',
    'me.sec_favorites' => 'Favourites',
    'me.sec_reports' => 'My reports',
    'me.sec_jury' => 'Jury',
    'me.none' => 'No entries.',
    'me.status_active' => 'active',
    'me.status_removed' => 'removed',
    'me.report_pending' => 'awaiting start (midnight)',
    'me.report_voting' => 'jury is voting',
    'me.report_removed' => 'content removed',
    'me.report_kept' => 'content kept',
    'me.fav_category' => 'Category',
    'me.fav_scope' => 'Area',
    'me.unfav' => 'Remove',
    'me.jury_pending' => 'Open jury task.',
    'me.jury_upcoming' => 'Drawn; voting starts {date}, midnight.',
    'me.jury_none' => 'No jury task.',
    'me.jury_go' => 'Go to jury task',
    'me.cooldown' => 'Jury cooldown until {date}.',
    'me.profile' => 'Profile (profil.yaml)',
    'me.download' => 'Download profil.yaml',
    'me.logout_note' => 'Signing out deletes the profil.yaml from the browser.',
    'me.delete_title' => 'Delete account and data',
    'me.delete_text' => 'Votes, favourites and open jury seats are deleted. Contributions remain but are permanently unlinked from your pseudonym.',
    'me.delete_confirm' => 'Yes, delete permanently',
    'me.delete_button' => 'Delete account',

    'jury.title' => 'Citizen jury',
    'jury.intro' => 'You were drawn by lot. Please assess the reported content against the criteria; abstaining is allowed.',
    'jury.blocked' => 'Until you vote, the other functions are locked – or wait until the review ends.',
    'jury.none' => 'No jury task.',
    'jury.upcoming' => 'Drawn; voting starts {date}, midnight. Nothing to do until then.',
    'jury.reported' => 'Reported content',
    'jury.criteria' => 'Stated criteria',
    'jury.freetext' => 'Reporter’s note',
    'jury.question' => 'Does the content violate the stated criteria?',
    'jury.confirm' => 'Yes – remove',
    'jury.reject' => 'No – keep',
    'jury.neutral' => 'Abstain',
    'jury.stats' => '{cast} of {seats} votes cast · quorum: {quorum}',
    'jury.deadline' => 'Regular end: {date}; afterwards a decision is made once the quorum is reached.',

    'report.title' => 'Report content',
    'report.intro' => 'Report presumably illegal content only – political opinions are not a reason to report.',
    'report.criteria' => 'Criteria (at least one)',
    'report.freetext' => 'Note (optional)',
    'report.process' => 'A randomly drawn citizen jury (1%) decides: voting from midnight, 24 hours, 0.5% quorum.',
    'report.submit' => 'Submit report',
    'report.cancel' => 'Cancel',

    'criteria.volksverhetzung' => 'Incitement to hatred (§ 130 German Criminal Code)',
    'criteria.kennzeichen' => 'Banned symbols of unconstitutional organisations (§§ 86, 86a)',
    'criteria.gewalt' => 'Incitement to violence or crime (§§ 111, 126)',
    'criteria.terror' => 'Terrorist propaganda (§§ 86, 129a/b)',
    'criteria.beleidigung' => 'Insult or defamation (§§ 185–187)',
    'criteria.bedrohung' => 'Threats (§ 241)',
    'criteria.privatdaten' => 'Publication of private data (doxxing)',
    'criteria.sonstiges' => 'Other presumably criminal content',

    'flash.session_expired' => 'Session ended. Please tap your ID card again.',
    'flash.auth_expired' => 'Sign-in expired – please tap your ID card again.',
    'flash.login_required' => 'Please tap your ID card first.',
    'flash.card_required' => 'Confirmation failed. Please tap your ID card again.',
    'flash.rate_limited' => 'Too many requests. Please wait a moment.',
    'flash.csrf' => 'The request could not be verified. Please try again.',
    'flash.invalid_input' => 'Invalid input.',
    'flash.topic_daily_limit' => 'Topic already raised today; the next one from midnight.',
    'flash.topic_created' => 'Topic published.',
    'flash.topic_not_votable' => 'Voting not possible.',
    'flash.topic_not_reportable' => 'Reporting not possible.',
    'flash.vote_saved' => 'Vote saved.',
    'flash.vote_withdrawn' => 'Vote withdrawn.',
    'flash.favorite_added' => 'Favourite added.',
    'flash.favorite_removed' => 'Favourite removed.',
    'flash.report_already_open' => 'A review is already in progress for this topic.',
    'flash.report_duplicate' => 'You have already reported this topic.',
    'flash.report_daily_limit' => 'Daily report limit reached.',
    'flash.report_too_few_users' => 'Too few participants are registered for a jury at the moment.',
    'flash.report_created' => 'Report received. The jury has been drawn; voting starts at midnight.',
    'flash.report_no_criteria' => 'Please select at least one criterion.',
    'flash.jury_not_open' => 'This vote is not (or no longer) open.',
    'flash.jury_not_member' => 'No authorisation for this jury.',
    'flash.jury_already_voted' => 'Already voted in this review.',
    'flash.jury_voted' => 'Jury vote counted.',
    'flash.auth_failed' => 'Sign-in failed.',
    'flash.auth_ok' => 'Signed in.',
    'flash.card_new' => 'Ready for a different ID card. Tap to sign in.',
    'flash.logged_out' => 'Signed out.',
    'flash.delete_not_confirmed' => 'Please confirm the deletion.',
    'flash.account_deleted' => 'Account deleted.',

    'error.not_found_title' => 'Page not found',
    'error.not_found' => 'The requested page does not exist or has been removed.',
    'error.generic_title' => 'Error',
    'error.generic' => 'An error occurred. Please try again later.',
    'error.method' => 'Request method not supported.',

    'footer.imprint' => 'Legal notice',
    'footer.privacy' => 'Privacy',
    'footer.note' => 'Concept and demonstration project',

    'imprint.h' => 'Legal notice',
    'imprint.p1' => 'Placeholder details – to be completed before any real operation (operator, address, authorised representatives, contact, supervision).',
    'imprint.p2' => 'This project is in test operation and is not an official website of the German federal government or any public authority.',

    'privacy.h' => 'Privacy',
    'privacy.p1' => 'Neither name, address, date of birth nor e-mail address are processed.',
    'privacy.p2' => 'When tapping the ID card, the site only receives a public key and stores nothing but a pseudonym derived from it (hash with a server-side secret).',
    'privacy.p3' => 'Exactly one technically necessary session cookie. Beyond that, nothing is stored in the browser – no further cookies, no localStorage, no trackers, no third-party content.',
    'privacy.p4' => 'To prevent abuse, short-lived hashed identifiers are processed for rate limiting and deleted automatically.',
    'privacy.p5' => 'The account can be deleted at any time in “My overview”.',
];

/* ============================== Assets =================================== */

const SW_CSS = <<<'CSS'
/* Stimmwerk - monochrom, schlicht, amtlich. Schwarz-Rot-Gold als einzige
   Farblinie. Hell/Dunkel folgt ausschliesslich der Systemeinstellung -
   es wird nichts im Browser gespeichert. Dafuer/Dagegen wird ueber
   Helligkeit unterschieden und traegt immer Textbeschriftung -
   Bedeutung haengt nie an Farbe allein. */
:root {
  color-scheme: light dark;
  --page: #ffffff; --surface: #ffffff; --field: #fafafa;
  --ink: #111111; --muted: #5f646b; --border: #d9d9d9;
  --accent: #111111; --accent-hover: #3a3a3a; --accent-ink: #ffffff;
  --vote-for: #1a1a1a; --vote-against: #a0a4a8; --bar-track: #ececec;
  --danger: #8a1f1f;
  --banner-bg: #111111; --banner-ink: #ffffff;
  --flash-bg: #f4f4f4; --flash-ink: #333333; --flash-error-ink: #8a1f1f;
  --focus: #111111;
}
@media (prefers-color-scheme: dark) {
  :root {
    --page: #131416; --surface: #17191c; --field: #1d2023;
    --ink: #ededed; --muted: #9aa0a6; --border: #34373b;
    --accent: #ededed; --accent-hover: #ffffff; --accent-ink: #131416;
    --vote-for: #ededed; --vote-against: #6d7378; --bar-track: #26282b;
    --danger: #d98a87;
    --banner-bg: #ededed; --banner-ink: #131416;
    --flash-bg: #1f2226; --flash-ink: #c9cdd1; --flash-error-ink: #d98a87;
    --focus: #ededed;
  }
}
* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  font-size: 1rem; line-height: 1.5; color: var(--ink); background: var(--page);
  min-height: 100vh; display: flex; flex-direction: column; }
.shell { width: 100%; max-width: 62rem; margin: 0 auto; padding: 0 1rem; }
.site-main { flex: 1; padding-top: 1.25rem; padding-bottom: 3rem; }
h1 { font-size: 1.45rem; line-height: 1.25; margin: 0 0 0.6rem; font-weight: 650; }
h2 { font-size: 1.05rem; line-height: 1.3; margin: 1.6rem 0 0.6rem; font-weight: 650; }
h3 { font-size: 1rem; margin: 0 0 0.3rem; font-weight: 600; }
p { margin: 0.5rem 0; }
a { color: var(--ink); text-decoration: underline; text-underline-offset: 2px; }
a:hover { color: var(--accent-hover); }
:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.muted { color: var(--muted); }
.skip-link { position: absolute; left: -999px; top: 0; background: var(--surface); color: var(--ink); padding: 0.5rem 1rem; z-index: 100; }
.skip-link:focus { left: 0.5rem; top: 0.5rem; }
.test-banner { background: var(--banner-bg); color: var(--banner-ink); text-align: center; font-size: 0.82rem; font-weight: 600; padding: 0.4rem 1rem; }
/* Schwarz-Rot-Gold: die einzige Farblinie der Seite */
.flagline { display: flex; height: 4px; }
.flagline span { flex: 1; }
.flagline .f1 { background: #000000; }
.flagline .f2 { background: #dd0000; }
.flagline .f3 { background: #ffcc00; }
.site-header { background: var(--surface); border-bottom: 1px solid var(--border); }
.header-inner { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem 1.1rem; padding-top: 0.65rem; padding-bottom: 0.65rem; }
.brand { display: inline-flex; align-items: center; gap: 0.45rem; color: var(--ink); text-decoration: none; font-weight: 700; font-size: 1.05rem; }
.brand-mark { width: 1.2rem; height: 1.2rem; color: var(--ink); }
.main-nav { display: flex; flex-wrap: wrap; gap: 0.9rem; }
.main-nav a { color: var(--ink); text-decoration: none; font-size: 0.93rem; padding: 0.25rem 0; }
.main-nav a:hover { text-decoration: underline; text-underline-offset: 4px; }
.main-nav a[aria-current="page"] { font-weight: 650; text-decoration: underline; text-decoration-thickness: 2px; text-underline-offset: 4px; }
.nav-duty { position: relative; padding-right: 0.85rem; }
.duty-dot { position: absolute; top: 0.15rem; right: 0; width: 0.45rem; height: 0.45rem; border-radius: 50%; background: var(--ink); }
.header-controls { display: flex; align-items: center; gap: 0.45rem; margin-left: auto; flex-wrap: wrap; }
.lang-form { display: inline-flex; border: 1px solid var(--border); }
.lang-btn { border: 0; background: var(--surface); color: var(--muted); font: inherit; font-size: 0.8rem; font-weight: 600; padding: 0.28rem 0.5rem; cursor: pointer; }
.lang-btn.is-active { background: var(--accent); color: var(--accent-ink); }
/* Sprachwahl beim ersten Aufruf: nur Flaggen */
.start-gate { min-height: 80vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1.6rem; padding: 2rem 1rem; }
.start-brand { font-size: 1.3rem; font-weight: 700; letter-spacing: 0.02em; margin: 0; }
.start-flags { display: flex; gap: 1.2rem; flex-wrap: wrap; justify-content: center; }
.flag-btn { display: flex; flex-direction: column; align-items: center; gap: 0.55rem; background: var(--surface); border: 1px solid var(--border); border-radius: 3px; padding: 1.1rem 1.6rem; font: inherit; font-weight: 600; color: var(--ink); cursor: pointer; }
.flag-btn:hover { border-color: var(--ink); }
.flag { width: 5.4rem; height: auto; display: block; border: 1px solid var(--border); }
/* Symbolhafter Anmelde-Einstieg */
.tap-icon { width: 7.5rem; height: auto; color: var(--ink); margin: 0.4rem auto 0.2rem; display: block; }
.tap-status { font-weight: 650; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; border: 1px solid transparent; border-radius: 2px;
  font: inherit; font-weight: 600; font-size: 0.92rem; padding: 0.45rem 0.95rem; cursor: pointer; text-decoration: none;
  transition: background-color 120ms ease, color 120ms ease, border-color 120ms ease; }
.btn-sm { padding: 0.28rem 0.65rem; font-size: 0.84rem; }
.btn-primary { background: var(--accent); color: var(--accent-ink); }
.btn-primary:hover { background: var(--accent-hover); color: var(--accent-ink); }
.btn-big { padding: 0.7rem 1.6rem; font-size: 1.02rem; }
.btn-outline { background: transparent; color: var(--ink); border-color: var(--ink); }
.btn-outline:hover { background: var(--accent); color: var(--accent-ink); }
.btn-ghost { background: transparent; color: var(--muted); border-color: var(--border); }
.btn-ghost:hover { color: var(--ink); border-color: var(--muted); }
.btn-danger { background: transparent; color: var(--danger); border-color: var(--danger); }
.btn-danger:hover { background: var(--danger); color: #ffffff; }
.btn-row { display: flex; gap: 0.55rem; flex-wrap: wrap; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: 3px; padding: 1rem 1.1rem; margin: 0.8rem 0; }
.badge { display: inline-block; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
  padding: 0.1rem 0.4rem; border: 1px solid var(--border); border-radius: 2px; color: var(--muted); background: transparent; white-space: nowrap; }
.flash { border: 1px solid var(--border); border-left: 3px solid var(--ink); border-radius: 2px; background: var(--flash-bg); color: var(--flash-ink); padding: 0.6rem 0.9rem; margin: 0.8rem 0; font-size: 0.93rem; }
.flash-error { border-left-color: var(--danger); color: var(--flash-error-ink); }
.flash a { color: inherit; font-weight: 650; }
.plain-list { margin: 0; padding-left: 1.1rem; }
.intro { padding: 0.5rem 0 0; max-width: 44rem; }
.gate-card, .personal-card { border-left: 3px solid var(--ink); }
.personal-card h2 { margin: 0 0 0.5rem; }
.personal-list { list-style: none; margin: 0; padding: 0; }
.personal-list li { display: flex; justify-content: space-between; gap: 1rem; padding: 0.4rem 0; border-bottom: 1px solid var(--border); font-size: 0.95rem; }
.personal-list li:last-child { border-bottom: 0; }
.personal-list li > span:first-child { color: var(--muted); }
.duty-link { font-weight: 650; }
.stat-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.7rem; margin: 1.2rem 0; }
.stat-tile { background: var(--surface); border: 1px solid var(--border); border-radius: 3px; padding: 0.75rem 1rem; display: flex; flex-direction: column; }
.stat-value { font-size: 1.45rem; font-weight: 700; }
.stat-label { color: var(--muted); font-size: 0.82rem; }
.page-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap; }
.filter-bar { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: end; background: var(--surface); border: 1px solid var(--border); border-radius: 3px; padding: 0.7rem 0.9rem; margin: 0.7rem 0 1rem; }
.filter-bar label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.82rem; color: var(--muted); }
.topic-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.7rem; }
.topic-card { margin: 0; display: flex; flex-direction: column; gap: 0.4rem; }
.topic-card-meta { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.topic-card-title { margin: 0; font-size: 1rem; }
.topic-card-title a { text-decoration: none; }
.topic-card-title a:hover { text-decoration: underline; }
.topic-card-goal { color: var(--muted); font-size: 0.9rem; margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.topic-card-votes { font-size: 0.86rem; color: var(--muted); margin-top: auto; }
.vote-sep { margin: 0 0.3rem; }
.pagination { display: flex; align-items: center; gap: 0.8rem; justify-content: center; margin: 1.4rem 0 0; }
.topic-detail h1 { margin-top: 0.5rem; }
.field-label { font-size: 0.74rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; color: var(--muted); margin: 0.85rem 0 0.15rem; }
.card .field-label:first-child { margin-top: 0; }
.dot { display: inline-block; width: 0.55rem; height: 0.55rem; border-radius: 50%; margin-right: 0.28rem; }
.dot-for { background: var(--vote-for); }
.dot-against { background: var(--vote-against); }
.votebar { display: flex; gap: 2px; height: 0.7rem; border-radius: 2px; overflow: hidden; background: var(--bar-track); }
.votebar span { flex-basis: 0; min-width: 3px; }
.votebar-for { background: var(--vote-for); }
.votebar-against { background: var(--vote-against); }
.votebar-legend { display: flex; flex-wrap: wrap; gap: 0.35rem 1.1rem; font-size: 0.88rem; margin-top: 0.4rem; }
.vote-actions { display: flex; gap: 0.55rem; flex-wrap: wrap; margin-top: 0.8rem; }
.vote-btn { background: transparent; color: var(--ink); border-color: var(--ink); }
.vote-btn:hover, .vote-btn.is-active { background: var(--accent); color: var(--accent-ink); }
.topic-tools { display: flex; align-items: center; gap: 0.9rem; flex-wrap: wrap; margin-top: 0.6rem; }
.link-quiet { color: var(--muted); font-size: 0.88rem; }
.link-quiet:hover { color: var(--ink); }
.form-stack { display: flex; flex-direction: column; gap: 0.85rem; }
.form-stack > label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; font-size: 0.9rem; }
.form-stack small { font-weight: 400; }
.form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.85rem; }
.form-row label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; font-size: 0.9rem; }
input[type="text"], input[type="search"], textarea, select { font: inherit; color: var(--ink); background: var(--field); border: 1px solid var(--border); border-radius: 2px; padding: 0.45rem 0.6rem; width: 100%; }
textarea { resize: vertical; }
input:focus, textarea:focus, select:focus { border-color: var(--ink); outline: none; }
.check-label { display: flex; align-items: flex-start; gap: 0.5rem; font-weight: 400; font-size: 0.93rem; }
.check-label input { margin-top: 0.22rem; accent-color: var(--accent); }
.criteria-set { border: 1px solid var(--border); border-radius: 2px; padding: 0.75rem 0.95rem; display: flex; flex-direction: column; gap: 0.5rem; }
.criteria-set legend { font-weight: 650; padding: 0 0.3rem; font-size: 0.9rem; }
.auth-card { max-width: 30rem; margin: 2.2rem auto; text-align: center; }
.auth-card .btn { margin: 0.7rem 0 0.4rem; }
.id-card { display: flex; flex-wrap: wrap; gap: 0.35rem 2rem; align-items: baseline; }
.id-value { font-family: ui-monospace, "SF Mono", Consolas, monospace; font-size: 1.1rem; font-weight: 700; letter-spacing: 0.08em; display: block; }
.row-list { list-style: none; margin: 0; padding: 0; border: 1px solid var(--border); border-radius: 3px; background: var(--surface); }
.row-item { display: flex; justify-content: space-between; align-items: center; gap: 0.8rem; flex-wrap: wrap; padding: 0.55rem 0.9rem; border-bottom: 1px solid var(--border); }
.row-item:last-child { border-bottom: 0; }
.row-main { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; min-width: 0; }
.row-side { display: flex; align-items: center; gap: 0.4rem; font-size: 0.88rem; flex-wrap: wrap; }
.danger-zone { border-left: 3px solid var(--danger); }
.danger-zone h2 { margin-top: 0; }
.error-card { max-width: 34rem; margin: 3rem auto; text-align: center; }
.prose { max-width: 46rem; }
.countdown { font-variant-numeric: tabular-nums; font-weight: 650; margin-left: 0.4rem; }
.yaml-block { background: var(--field); border: 1px solid var(--border); border-radius: 2px; padding: 0.8rem 1rem; overflow-x: auto; font-size: 0.84rem; line-height: 1.45; }
.site-footer { border-top: 1px solid var(--border); background: var(--surface); font-size: 0.83rem; color: var(--muted); }
.footer-inner { display: flex; justify-content: space-between; gap: 0.5rem 1.5rem; flex-wrap: wrap; padding-top: 0.8rem; padding-bottom: 0.8rem; }
.footer-nav { display: flex; gap: 1rem; }
.footer-nav a { color: var(--muted); }
.footer-nav a:hover { color: var(--ink); }
@media (max-width: 860px) { .form-row { grid-template-columns: 1fr; } }
@media (max-width: 640px) {
  h1 { font-size: 1.3rem; }
  .topic-grid { grid-template-columns: 1fr; }
  .stat-row { grid-template-columns: 1fr; }
  .header-inner { gap: 0.45rem 0.9rem; }
  .header-controls { margin-left: 0; width: 100%; justify-content: flex-end; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
CSS;

const SW_JS = <<<'JS'
/* Progressive Verbesserungen - alles laeuft auch ohne JavaScript.
   Einzige bewusste Ablage im Browser: die profil.yaml (sessionStorage),
   die der Abmelde-Knopf wieder loescht. Sonst: Countdown und - am
   Smartphone - das direkte Ausloesen der Anmeldung per NFC (Web NFC). */
(function () {
  'use strict';
  var init = function () {
    /* Countdown (z. B. bis zum naechsten moeglichen Thema um 00:00) */
    var nodes = document.querySelectorAll('[data-countdown-to]');
    if (nodes.length > 0) {
      var pad = function (n) { return n < 10 ? '0' + n : String(n); };
      var update = function () {
        nodes.forEach(function (node) {
          var target = Date.parse(node.getAttribute('data-countdown-to').replace(' ', 'T') + 'Z');
          var diff = Math.max(0, Math.floor((target - Date.now()) / 1000));
          var text = pad(Math.floor(diff / 3600)) + ':' + pad(Math.floor((diff % 3600) / 60)) + ':' + pad(diff % 60);
          node.textContent = (node.getAttribute('data-label') || '') + ' ' + text;
        });
      };
      update();
      setInterval(update, 1000);
    }

    /* profil.yaml: beim Anhalten geladen, im Browser gehalten (sessionStorage),
       der Abmelde-Knopf loescht sie wieder. */
    var yaml = document.getElementById('profil-yaml');
    if (yaml) {
      try { sessionStorage.setItem('profil.yaml', yaml.textContent); } catch (e) {}
    }
    var logoutForms = document.querySelectorAll('form.js-logout');
    logoutForms.forEach(function (form) {
      form.addEventListener('submit', function () {
        try { sessionStorage.removeItem('profil.yaml'); } catch (e) {}
      });
    });

    /* NFC direkt vom Handy: Knopf startet den Leser; das Anhalten der Karte
       loest die Anmeldung aus. Der Personalausweis ist kein NDEF-Tag, daher
       zaehlt auch "readingerror" als Kontakt. Ohne Web NFC (iOS, Desktop)
       oder nach 15 s sendet der Knopf normal ab. */
    var tapForm = document.getElementById('tap-form');
    if (tapForm && 'NDEFReader' in window) {
      tapForm.addEventListener('submit', function (ev) {
        if (tapForm.getAttribute('data-armed') === '1') { return; }
        ev.preventDefault();
        tapForm.setAttribute('data-armed', '1');
        var status = document.getElementById('tap-status');
        if (status) { status.hidden = false; }
        var done = false;
        var go = function () {
          if (!done) { done = true; tapForm.submit(); }
        };
        try {
          var reader = new NDEFReader();
          reader.addEventListener('reading', go);
          reader.addEventListener('readingerror', go);
          reader.scan().catch(go);
          setTimeout(go, 15000);
        } catch (e) { go(); }
      });
    }
  };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
  else { init(); }
})();
JS;

const SW_ICON = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
  <rect x="0" y="0" width="24" height="24" rx="3" fill="#111111"/>
  <rect x="3" y="16.2" width="18" height="1.6" fill="#dd0000"/>
  <rect x="3" y="18.6" width="18" height="1.6" fill="#ffcc00"/>
  <path d="M6.5 10l3.4 3.2 7.6-7.4" fill="none" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
SVG;

function serve_asset(string $kind): void
{
    $map = [
        'css'  => ['text/css; charset=utf-8', SW_CSS],
        'js'   => ['text/javascript; charset=utf-8', SW_JS],
        'icon' => ['image/svg+xml', SW_ICON],
    ];
    if (!isset($map[$kind])) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $map[$kind][0]);
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $map[$kind][1];
    if ($kind === 'css') {
        // Balkenbreiten CSP-konform als Klassen (statt Inline-Styles).
        for ($i = 0; $i <= 100; $i++) {
            echo "\n.w-" . $i . ' { flex-grow: ' . $i . '; }';
        }
    }
    exit;
}

/* ============================== Ansichten ================================= */

function render(string $title, string $content, int $status = 200): void
{
    http_response_code($status);
    echo v_layout($title, $content);
    exit;
}

function v_layout(string $title, string $content): string
{
    $cfg = SW::$cfg;
    $user = auth_user();
    $duty = $user === null ? null : jury_pending_for((int) $user['id']);
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $returnValue = SW::$path . ($query !== '' ? '?' . $query : '');
    $b = base_path();
    $a = SW::$base; /* Assets laufen ebenfalls durch index.php */

    $html = '<!DOCTYPE html><html lang="' . e(SW::$lang) . '"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="referrer" content="no-referrer">'
        . '<title>' . e($title) . ' · ' . e((string) $cfg['app_name']) . '</title>'
        . '<link rel="stylesheet" href="' . e(url('/a/app.css')) . '">'
        . '<link rel="icon" type="image/svg+xml" href="' . e(url('/a/icon.svg')) . '">'
        . '<script src="' . e(url('/a/app.js')) . '" defer></script>'
        . '</head><body>';
    if (!empty($cfg['show_test_banner'])) {
        $html .= '<div class="test-banner" role="note">' . e(t('banner.test')) . '</div>';
    }
    $html .= '<a class="skip-link" href="#main">' . e(t('a11y.skip')) . '</a>'
        . '<header class="site-header"><div class="shell header-inner">'
        . '<a class="brand" href="' . e(url('/')) . '" aria-label="' . e((string) $cfg['app_name']) . '">'
        . '<svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<rect x="1.5" y="1.5" width="21" height="21" rx="3" fill="none" stroke="currentColor" stroke-width="2"/>'
        . '<path d="M6.5 12.5l3.6 3.6 7.4-8.2" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg><span>' . e((string) $cfg['app_name']) . '</span></a>'
        . '<nav class="main-nav" aria-label="Navigation">'
        . '<a href="' . e(url('/topics')) . '"' . (SW::$path === '/topics' ? ' aria-current="page"' : '') . '>' . e(t('nav.topics')) . '</a>'
        . '<a href="' . e(url('/topics/new')) . '"' . (SW::$path === '/topics/new' ? ' aria-current="page"' : '') . '>' . e(t('nav.new')) . '</a>';
    if ($user !== null) {
        $html .= '<a href="' . e(url('/me')) . '"' . (SW::$path === '/me' ? ' aria-current="page"' : '') . '>' . e(t('nav.overview')) . '</a>';
        if ($duty !== null) {
            $html .= '<a class="nav-duty" href="' . e(url('/jury')) . '">' . e(t('nav.jury')) . '<span class="duty-dot" aria-hidden="true"></span></a>';
        }
    }
    $html .= '</nav><div class="header-controls">'
        . '<form class="lang-form" method="post" action="' . e(url('/lang')) . '" aria-label="' . e(t('lang.switch')) . '">'
        . csrf_field()
        . '<input type="hidden" name="return" value="' . e($returnValue) . '">'
        . '<button type="submit" name="lang" value="de" class="lang-btn ' . (SW::$lang === 'de' ? 'is-active' : '') . '" aria-pressed="' . (SW::$lang === 'de' ? 'true' : 'false') . '">DE</button>'
        . '<button type="submit" name="lang" value="en" class="lang-btn ' . (SW::$lang === 'en' ? 'is-active' : '') . '" aria-pressed="' . (SW::$lang === 'en' ? 'true' : 'false') . '">EN</button>'
        . '</form>';
    if ($user === null) {
        $html .= '<a class="btn btn-primary btn-sm" href="' . e(url('/auth')) . '">' . e(t('auth.login')) . '</a>';
    } else {
        $html .= '<form method="post" action="' . e(url('/logout')) . '" class="js-logout">' . csrf_field()
            . '<button type="submit" class="btn btn-ghost btn-sm">' . e(t('auth.logout')) . '</button></form>';
    }
    $html .= '</div></div></header>'
        . '<div class="flagline" aria-hidden="true"><span class="f1"></span><span class="f2"></span><span class="f3"></span></div>';

    $flashes = take_flashes();
    if ($flashes !== []) {
        $html .= '<div class="shell">';
        foreach ($flashes as $f) {
            $html .= '<div class="flash flash-' . e((string) $f['type']) . '" role="status">'
                . e(t((string) $f['key'], (array) $f['repl'])) . '</div>';
        }
        $html .= '</div>';
    }
    $html .= '<main id="main" class="shell site-main">' . $content . '</main>'
        . '<footer class="site-footer"><div class="shell footer-inner">'
        . '<span>' . e((string) $cfg['app_name']) . ' · ' . e(t('footer.note')) . '</span>'
        . '<nav class="footer-nav" aria-label="Footer">'
        . '<a href="' . e(url('/imprint')) . '">' . e(t('footer.imprint')) . '</a>'
        . '<a href="' . e(url('/privacy')) . '">' . e(t('footer.privacy')) . '</a>'
        . '</nav></div></footer></body></html>';
    return $html;
}

function scope_text(array $row): string
{
    $text = t('scope.' . (string) $row['scope_level']);
    if ((string) ($row['scope_name'] ?? '') !== '') {
        $text .= ': ' . (string) $row['scope_name'];
    }
    return $text;
}

function p_topic_card(array $row): string
{
    $html = '<article class="card topic-card"><div class="topic-card-meta">'
        . '<span class="badge">' . e(cat_name($row)) . '</span>'
        . '<span class="badge">' . e(scope_text($row)) . '</span></div>'
        . '<h3 class="topic-card-title"><a href="' . e(url('/topic/' . (int) $row['id'])) . '">' . e((string) $row['title']) . '</a></h3>'
        . '<p class="topic-card-goal">' . e((string) $row['goal']) . '</p>'
        . '<div class="topic-card-votes">'
        . '<span class="dot dot-for" aria-hidden="true"></span>' . e(t('vote.for')) . ' ' . e(num((int) $row['votes_for']))
        . '<span class="vote-sep" aria-hidden="true">·</span>'
        . '<span class="dot dot-against" aria-hidden="true"></span>' . e(t('vote.against')) . ' ' . e(num((int) $row['votes_against']));
    if (!empty($row['my_choice'])) {
        $html .= '<span class="vote-sep" aria-hidden="true">·</span>'
            . e(t('topic.your_vote', ['choice' => t($row['my_choice'] === 'for' ? 'vote.for' : 'vote.against')]));
    }
    return $html . '</div></article>';
}

function p_votebar(int $for, int $against): string
{
    $total = $for + $against;
    if ($total === 0) {
        return '<p class="muted">' . e(t('vote.none_yet')) . '</p>';
    }
    $pctFor = (int) round($for * 100 / $total);
    $pctAgainst = 100 - $pctFor;
    $html = '<div class="votebar" role="img" aria-label="' . e(t('vote.bar_aria')) . ': '
        . e(t('vote.for')) . ' ' . e(num($for)) . ', ' . e(t('vote.against')) . ' ' . e(num($against)) . '">';
    if ($for > 0) {
        $html .= '<span class="votebar-for w-' . $pctFor . '"></span>';
    }
    if ($against > 0) {
        $html .= '<span class="votebar-against w-' . $pctAgainst . '"></span>';
    }
    $html .= '</div><div class="votebar-legend">'
        . '<span><span class="dot dot-for" aria-hidden="true"></span>' . e(t('vote.for')) . ' ' . $pctFor . ' % · ' . e(num($for)) . '</span>'
        . '<span><span class="dot dot-against" aria-hidden="true"></span>' . e(t('vote.against')) . ' ' . $pctAgainst . ' % · ' . e(num($against)) . '</span>'
        . '</div>';
    return $html;
}

function v_home(): void
{
    $user = auth_user();
    $stats = site_stats();
    $latest = topics_list([], 1, 6, $user === null ? null : (int) $user['id']);
    $html = '<section class="intro"><h1>' . e(t('home.title')) . '</h1>'
        . '<p class="muted">' . e(t('home.intro')) . '</p></section>';
    if ($user === null) {
        $html .= '<section class="card gate-card"><p>' . e(t('home.locked')) . '</p>'
            . '<p><a class="btn btn-primary" href="' . e(url('/auth')) . '">' . e(t('auth.login')) . '</a></p></section>';
    } else {
        $userId = (int) $user['id'];
        $myVotes = (int) SW::$db->val('SELECT COUNT(*) FROM votes WHERE user_id = ?', [$userId]);
        $myFavs = (int) SW::$db->val('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$userId]);
        $duty = jury_pending_for($userId);
        $upcoming = $duty === null ? jury_upcoming_for($userId) : null;
        if ($duty !== null) {
            $juryText = '<a class="duty-link" href="' . e(url('/jury')) . '">' . e(t('home.p_jury_open')) . '</a>';
        } elseif ($upcoming !== null) {
            $juryText = e(t('home.p_jury_upcoming', ['date' => Clock::displayLocal((string) $upcoming['voting_starts_at'], t('common.date_format'))]));
        } else {
            $juryText = e(t('home.p_jury_none'));
        }
        $html .= '<section class="card personal-card"><h2>' . e(t('home.personal')) . '</h2><ul class="personal-list">'
            . '<li><span>' . e(t('home.p_votes')) . '</span><span><a href="' . e(url('/me')) . '">' . e(num($myVotes)) . '</a></span></li>'
            . '<li><span>' . e(t('home.p_favorites')) . '</span><span><a href="' . e(url('/me')) . '">' . e(num($myFavs)) . '</a></span></li>'
            . '<li><span>' . e(t('home.p_jury')) . '</span><span>' . $juryText . '</span></li>'
            . '</ul></section>';
    }
    $html .= '<section class="stat-row" aria-label="Statistik">'
        . '<div class="stat-tile"><span class="stat-value">' . e(num($stats['topics'])) . '</span><span class="stat-label">' . e(t('home.stat_topics')) . '</span></div>'
        . '<div class="stat-tile"><span class="stat-value">' . e(num($stats['votes'])) . '</span><span class="stat-label">' . e(t('home.stat_votes')) . '</span></div>'
        . '<div class="stat-tile"><span class="stat-value">' . e(num($stats['users'])) . '</span><span class="stat-label">' . e(t('home.stat_users')) . '</span></div>'
        . '</section>'
        . '<section><div class="page-head"><h2>' . e(t('home.latest')) . '</h2>'
        . '<a class="link-quiet" href="' . e(url('/topics')) . '">' . e(t('home.all_topics')) . '</a></div>';
    if ($latest['rows'] === []) {
        $html .= '<p class="muted">' . e(t('topics.none_yet')) . ' <a href="' . e(url('/topics/new')) . '">' . e(t('nav.new')) . '</a></p>';
    } else {
        $html .= '<div class="topic-grid">';
        foreach ($latest['rows'] as $row) {
            $html .= p_topic_card($row);
        }
        $html .= '</div>';
    }
    $html .= '</section>';
    render(t('app.tagline'), $html);
}

function v_topics(): void
{
    $user = auth_user();
    $scopeValue = query_str('gebiet', 160);
    $scopeDecoded = $scopeValue === '' ? null : scope_decode($scopeValue);
    if ($scopeDecoded === null) {
        $scopeValue = '';
    }
    $filters = [
        'category' => query_str('category', 64),
        'level'    => $scopeDecoded === null ? '' : $scopeDecoded[0],
        'scope'    => $scopeDecoded === null || $scopeDecoded[1] === null ? '' : $scopeDecoded[1],
        'gebiet'   => $scopeValue,
        'q'        => query_str('q', 80),
        'sort'     => query_str('sort', 10) === 'top' ? 'top' : 'new',
    ];
    $page = query_int('page', 1, 500, 1);
    $perPage = (int) SW::$cfg['page_size'];
    $result = topics_list($filters, $page, $perPage, $user === null ? null : (int) $user['id']);
    $pages = max(1, (int) ceil($result['total'] / $perPage));

    $html = '<div class="page-head"><h1>' . e(t('topics.title')) . '</h1>'
        . '<span class="muted">' . e(t('topics.count', ['n' => num($result['total'])])) . '</span></div>'
        . '<form class="filter-bar" method="get" action="' . e(url('/topics')) . '">'
        . '<label><span>' . e(t('topics.filter_category')) . '</span><select name="category">'
        . '<option value="">' . e(t('topics.filter_all')) . '</option>';
    foreach (categories() as $category) {
        $sel = $filters['category'] === $category['slug'] ? ' selected' : '';
        $html .= '<option value="' . e((string) $category['slug']) . '"' . $sel . '>' . e(cat_name($category)) . '</option>';
    }
    $html .= '</select></label>'
        . '<label><span>' . e(t('topic.f_scope')) . '</span>'
        . scope_select('gebiet', $filters['gebiet'], true)
        . '</label>'
        . '<label><span>' . e(t('topics.search')) . '</span><input type="search" name="q" maxlength="80" value="' . e($filters['q']) . '"></label>'
        . '<label><span>' . e(t('topics.sort')) . '</span><select name="sort">'
        . '<option value="new"' . ($filters['sort'] === 'new' ? ' selected' : '') . '>' . e(t('topics.sort_new')) . '</option>'
        . '<option value="top"' . ($filters['sort'] === 'top' ? ' selected' : '') . '>' . e(t('topics.sort_top')) . '</option>'
        . '</select></label>'
        . '<button type="submit" class="btn btn-outline">' . e(t('topics.apply')) . '</button></form>';

    if ($result['rows'] === []) {
        $html .= '<p class="muted">' . e(t('topics.none')) . '</p>';
    } else {
        $html .= '<div class="topic-grid">';
        foreach ($result['rows'] as $row) {
            $html .= p_topic_card($row);
        }
        $html .= '</div>';
    }
    if ($pages > 1) {
        $mkQuery = static function (int $p) use ($filters): string {
            $keep = ['category' => $filters['category'], 'gebiet' => $filters['gebiet'],
                     'q' => $filters['q'], 'sort' => $filters['sort'], 'page' => $p];
            $params = array_filter($keep, static function ($v) {
                return $v !== '' && $v !== null;
            });
            return $params === [] ? '' : '?' . http_build_query($params);
        };
        $html .= '<nav class="pagination" aria-label="Pagination">';
        if ($page > 1) {
            $html .= '<a class="btn btn-ghost btn-sm" href="' . e(url('/topics') . $mkQuery($page - 1)) . '">&laquo; ' . e(t('topics.prev')) . '</a>';
        }
        $html .= '<span class="muted">' . e(t('topics.page_of', ['p' => $page, 'n' => $pages])) . '</span>';
        if ($page < $pages) {
            $html .= '<a class="btn btn-ghost btn-sm" href="' . e(url('/topics') . $mkQuery($page + 1)) . '">' . e(t('topics.next')) . ' &raquo;</a>';
        }
        $html .= '</nav>';
    }
    render(t('topics.title'), $html);
}

function v_topic(int $id): void
{
    $topic = topic_find($id);
    if ($topic === null) {
        v_error_404();
    }
    if ($topic['status'] === 'removed') {
        $html = '<article class="card"><h1>' . e(t('topic.removed_title')) . '</h1>'
            . '<p class="muted">' . e(t('topic.removed_text')) . '</p>'
            . '<p><a class="btn btn-outline btn-sm" href="' . e(url('/topics')) . '">' . e(t('nav.topics')) . '</a></p></article>';
        render(t('topic.removed_title'), $html);
    }
    $user = auth_user();
    $userId = $user === null ? null : (int) $user['id'];
    $myVote = $userId === null ? null : topic_user_vote($id, $userId);
    $openReport = report_open_for($id);
    $scopeRef = $topic['scope_level'] === 'bund' ? 'bund' : $topic['scope_level'] . ':' . (string) $topic['scope_name'];

    $html = '<article class="topic-detail"><div class="topic-card-meta">'
        . '<span class="badge">' . e(cat_name($topic)) . '</span>'
        . '<span class="badge">' . e(scope_text($topic)) . '</span></div>'
        . '<h1>' . e((string) $topic['title']) . '</h1>'
        . '<p class="muted">' . e(t('topic.created', ['date' => Clock::displayLocal((string) $topic['created_at'], t('common.date_format'))])) . '</p>'
        . '<section class="card"><h2 class="field-label">' . e(t('topic.goal_label')) . '</h2>'
        . '<p>' . nl2br(e((string) $topic['goal'])) . '</p>'
        . '<h2 class="field-label">' . e(t('topic.reasoning_label')) . '</h2>'
        . '<p>' . nl2br(e((string) $topic['reasoning'])) . '</p></section>';

    $barFor = (int) $topic['votes_for'];
    $barAgainst = (int) $topic['votes_against'];
    $html .= '<section class="card">' . p_votebar($barFor, $barAgainst);
    if (($barFor + $barAgainst) > 0) {
        $html .= '<p class="muted">' . e(t('vote.total', ['n' => num($barFor + $barAgainst)])) . '</p>';
    }
    if ($user === null) {
        $html .= '<p><a class="btn btn-primary" href="' . e(url('/auth')) . '">' . e(t('vote.login_hint')) . '</a></p>';
    } else {
        $html .= '<form class="vote-actions" method="post" action="' . e(url('/vote')) . '">' . csrf_field()
            . '<input type="hidden" name="topic_id" value="' . (int) $topic['id'] . '">'
            . '<button type="submit" name="choice" value="for" class="btn vote-btn' . ($myVote === 'for' ? ' is-active' : '') . '" aria-pressed="' . ($myVote === 'for' ? 'true' : 'false') . '">' . e(t('vote.for')) . '</button>'
            . '<button type="submit" name="choice" value="against" class="btn vote-btn' . ($myVote === 'against' ? ' is-active' : '') . '" aria-pressed="' . ($myVote === 'against' ? 'true' : 'false') . '">' . e(t('vote.against')) . '</button>';
        if ($myVote !== null) {
            $html .= '<button type="submit" name="choice" value="none" class="btn btn-ghost">' . e(t('vote.withdraw')) . '</button>';
        }
        $html .= '</form>';
        if ($myVote !== null) {
            $html .= '<p class="muted">' . e(t('topic.your_vote', ['choice' => t($myVote === 'for' ? 'vote.for' : 'vote.against')])) . '</p>';
        }
        $html .= '<p class="muted">' . e(t('vote.neutral_hint')) . ' ' . e(t('vote.signed')) . '</p>';
    }
    $html .= '</section><section class="topic-tools">';
    if ($user !== null) {
        $isCatFav = fav_is((int) $user['id'], 'category', (string) $topic['category_slug']);
        $isScopeFav = fav_is((int) $user['id'], 'scope', $scopeRef);
        $html .= '<form method="post" action="' . e(url('/favorite')) . '">' . csrf_field()
            . '<input type="hidden" name="kind" value="category">'
            . '<input type="hidden" name="ref" value="' . e((string) $topic['category_slug']) . '">'
            . '<input type="hidden" name="return" value="/topic/' . (int) $topic['id'] . '">'
            . '<button type="submit" class="btn btn-ghost btn-sm">' . e(t($isCatFav ? 'topic.fav_remove' : 'topic.fav_add')) . '</button></form>'
            . '<form method="post" action="' . e(url('/favorite')) . '">' . csrf_field()
            . '<input type="hidden" name="kind" value="scope">'
            . '<input type="hidden" name="ref" value="' . e($scopeRef) . '">'
            . '<input type="hidden" name="return" value="/topic/' . (int) $topic['id'] . '">'
            . '<button type="submit" class="btn btn-ghost btn-sm">' . e(t($isScopeFav ? 'topic.fav_scope_remove' : 'topic.fav_scope_add')) . '</button></form>';
    }
    if ($openReport !== null) {
        $html .= '<span class="muted">' . e(t('topic.report_open')) . '</span>';
    } elseif ($user !== null) {
        $html .= '<a class="link-quiet" href="' . e(url('/report/' . (int) $topic['id'])) . '">' . e(t('topic.report_link')) . '</a>';
    }
    $html .= '</section></article>';
    render((string) $topic['title'], $html);
}

/** @param list<string> $errors @param array<string,mixed> $old */
function v_topic_new(array $errors, array $old, bool $postedToday): void
{
    $html = '<h1>' . e(t('topic.new_title')) . '</h1>'
        . '<p class="muted">' . e(t('topic.new_intro')) . ' ' . e(t('vote.signed')) . '</p>';
    if ($postedToday) {
        $html .= '<div class="flash">' . e(t('topic.posted_today'))
            . '<span class="countdown" data-countdown-to="' . e(Clock::nextLocalMidnightUtcStr()) . '" data-label="' . e(t('topic.next_in')) . '"></span></div>';
        render(t('topic.new_title'), $html);
    }
    if ($errors !== []) {
        $html .= '<div class="flash flash-error" role="alert"><ul class="plain-list">';
        foreach ($errors as $error) {
            $html .= '<li>' . e(t($error)) . '</li>';
        }
        $html .= '</ul></div>';
    }
    $html .= '<form class="card form-stack" method="post" action="' . e(url('/topics')) . '">' . csrf_field()
        . '<label><span>' . e(t('topic.f_title')) . '</span>'
        . '<input type="text" name="title" required minlength="' . SW_TITLE_MIN . '" maxlength="' . SW_TITLE_MAX . '" value="' . e((string) $old['title']) . '"></label>'
        . '<label><span>' . e(t('topic.f_goal')) . '</span>'
        . '<textarea name="goal" rows="3" required minlength="' . SW_GOAL_MIN . '" maxlength="' . SW_GOAL_MAX . '">' . e((string) $old['goal']) . '</textarea></label>'
        . '<label><span>' . e(t('topic.f_reasoning')) . '</span>'
        . '<textarea name="reasoning" rows="6" required minlength="' . SW_REASONING_MIN . '" maxlength="' . SW_REASONING_MAX . '">' . e((string) $old['reasoning']) . '</textarea></label>'
        . '<div class="form-row"><label><span>' . e(t('topic.f_category')) . '</span><select name="category_id" required>'
        . '<option value="">' . e(t('topic.f_choose')) . '</option>';
    foreach (categories() as $category) {
        $sel = (int) $old['category_id'] === (int) $category['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $category['id'] . '"' . $sel . '>' . e(cat_name($category)) . '</option>';
    }
    $html .= '</select></label>'
        . '<label><span>' . e(t('topic.f_scope')) . '</span>'
        . scope_select('scope', (string) $old['scope'], false)
        . '</label></div>'
        . '<div><button type="submit" class="btn btn-primary">' . e(t('topic.submit')) . '</button></div></form>';
    render(t('topic.new_title'), $html);
}

function v_auth(): void
{
    if (auth_user() !== null) {
        redirect('/me');
    }
    // Symbolhafter Einstieg: Ausweis-Piktogramm mit NFC-Wellen, ein Satz,
    // ein Knopf. Am Smartphone startet der Knopf den NFC-Leser (Web NFC);
    // das Anhalten der Karte löst die Anmeldung direkt aus.
    $pictogram = '<svg class="tap-icon" viewBox="0 0 96 64" aria-hidden="true" focusable="false">'
        . '<rect x="4" y="10" width="56" height="38" rx="4" fill="none" stroke="currentColor" stroke-width="3"/>'
        . '<rect x="11" y="19" width="14" height="11" rx="2" fill="currentColor"/>'
        . '<line x1="11" y1="38" x2="46" y2="38" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>'
        . '<path d="M70 18a22 22 0 0 1 0 28" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>'
        . '<path d="M78 12a32 32 0 0 1 0 40" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>'
        . '<path d="M86 6a42 42 0 0 1 0 52" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>'
        . '</svg>';
    $html = '<section class="card auth-card">' . $pictogram
        . '<h1>' . e(t('auth.title')) . '</h1>'
        . '<p class="muted">' . e(t('auth.line')) . '</p>'
        . '<form id="tap-form" method="post" action="' . e(url('/tap')) . '">' . csrf_field()
        . '<button type="submit" class="btn btn-primary btn-big">' . e(t('auth.tap')) . '</button></form>'
        . '<p id="tap-status" class="tap-status" hidden aria-live="polite">' . e(t('auth.hold')) . '</p>'
        . '<form method="post" action="' . e(url('/card/new')) . '">' . csrf_field()
        . '<button type="submit" class="btn btn-ghost btn-sm">' . e(t('auth.other_card')) . '</button></form>'
        . '</section>';
    render(t('auth.title'), $html);
}

/** Sprachwahl beim allerersten Aufruf: zwei Flaggen, sonst nichts. */
function v_start(): void
{
    $flagDe = '<svg class="flag" viewBox="0 0 60 36" aria-hidden="true" focusable="false">'
        . '<rect width="60" height="12" y="0" fill="#000000"/>'
        . '<rect width="60" height="12" y="12" fill="#dd0000"/>'
        . '<rect width="60" height="12" y="24" fill="#ffcc00"/></svg>';
    $flagEn = '<svg class="flag" viewBox="0 0 60 36" aria-hidden="true" focusable="false">'
        . '<rect width="60" height="36" fill="#012169"/>'
        . '<path d="M0 0L60 36M60 0L0 36" stroke="#ffffff" stroke-width="7"/>'
        . '<path d="M0 0L60 36M60 0L0 36" stroke="#c8102e" stroke-width="3"/>'
        . '<path d="M30 0V36M0 18H60" stroke="#ffffff" stroke-width="12"/>'
        . '<path d="M30 0V36M0 18H60" stroke="#c8102e" stroke-width="7"/></svg>';
    http_response_code(200);
    $banner = empty(SW::$cfg['show_test_banner'])
        ? ''
        : '<div class="test-banner" role="note">' . e(SW_DE['banner.test']) . ' / ' . e(SW_EN['banner.test']) . '</div>';
    echo '<!DOCTYPE html><html lang="de"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="referrer" content="no-referrer">'
        . '<title>' . e((string) SW::$cfg['app_name']) . '</title>'
        . '<link rel="stylesheet" href="' . e(url('/a/app.css')) . '">'
        . '<link rel="icon" type="image/svg+xml" href="' . e(url('/a/icon.svg')) . '">'
        . '</head><body>' . $banner
        . '<main class="start-gate">'
        . '<p class="start-brand">' . e((string) SW::$cfg['app_name']) . '</p>'
        . '<div class="start-flags">'
        . '<form method="post" action="' . e(url('/lang')) . '">' . csrf_field()
        . '<input type="hidden" name="return" value="/">'
        . '<button type="submit" name="lang" value="de" class="flag-btn" lang="de">' . $flagDe . '<span>Deutsch</span></button></form>'
        . '<form method="post" action="' . e(url('/lang')) . '">' . csrf_field()
        . '<input type="hidden" name="return" value="/">'
        . '<button type="submit" name="lang" value="en" class="flag-btn" lang="en">' . $flagEn . '<span>English</span></button></form>'
        . '</div></main></body></html>';
    exit;
}

function v_error_404(): void
{
    $html = '<section class="card error-card"><h1>' . e(t('error.not_found_title')) . '</h1>'
        . '<p class="muted">' . e(t('error.not_found')) . '</p>'
        . '<p><a class="btn btn-outline btn-sm" href="' . e(url('/')) . '">' . e(t('common.back_home')) . '</a></p></section>';
    render(t('error.not_found_title'), $html, 404);
}

function v_error(int $status, string $messageKey): void
{
    $html = '<section class="card error-card"><h1>' . e(t('error.generic_title')) . '</h1>'
        . '<p class="muted">' . e(t($messageKey)) . '</p>'
        . '<p><a class="btn btn-outline btn-sm" href="' . e(url('/')) . '">' . e(t('common.back_home')) . '</a></p></section>';
    render(t('error.generic_title'), $html, $status);
}

function v_static(string $titleKey, array $paraKeys): void
{
    $html = '<section class="card prose"><h1>' . e(t($titleKey)) . '</h1>';
    foreach ($paraKeys as $key) {
        $html .= '<p>' . e(t($key)) . '</p>';
    }
    $html .= '</section>';
    render(t($titleKey), $html);
}

/** Einfache, sichere YAML-Ausgabe (Werte stets in Anführungszeichen). */
function yq(string $v): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

/** profil.yaml: die Gesamtansicht der Person – wird beim Anhalten geladen,
 *  im Browser gehalten und beim Abmelden dort gelöscht. */
function profile_yaml(array $user): string
{
    $userId = (int) $user['id'];
    $slot = is_int($_SESSION['auth_slot'] ?? null) ? (int) $_SESSION['auth_slot'] : time_slot();
    $validUntil = date('c', ($slot + SW_AUTH_SLOTS) * SW_SLOT_SECONDS);
    $y = "stimmwerk_profil:\n";
    $y .= "  oeffentlicher_schluessel: " . yq((string) $user['pseudonym_hash']) . "\n";
    $y .= "  stand: " . yq(date('c', Clock::now()->getTimestamp())) . "\n";
    $y .= "  anmeldung_gueltig_bis: " . yq($validUntil) . "\n";
    $y .= "  sprache: " . yq((string) $user['lang']) . "\n";
    $duty = jury_pending_for($userId);
    $upcoming = $duty === null ? jury_upcoming_for($userId) : null;
    $y .= "  jury_aufgabe: " . yq($duty !== null ? 'offen' : ($upcoming !== null ? 'ausgelost' : 'keine')) . "\n";
    $y .= "  stimmen:\n";
    $voted = topics_voted_by($userId);
    if ($voted === []) {
        $y = substr($y, 0, -1) . " []\n";
    }
    foreach ($voted as $row) {
        $y .= "    - thema: " . (int) $row['id'] . "\n";
        $y .= "      titel: " . yq((string) $row['title']) . "\n";
        $y .= "      stimme: " . yq($row['my_choice'] === 'for' ? 'dafuer' : 'dagegen') . "\n";
    }
    $y .= "  eigene_themen:\n";
    $authored = topics_by_author($userId);
    if ($authored === []) {
        $y = substr($y, 0, -1) . " []\n";
    }
    foreach ($authored as $row) {
        $y .= "    - thema: " . (int) $row['id'] . "\n";
        $y .= "      titel: " . yq((string) $row['title']) . "\n";
        $y .= "      status: " . yq((string) $row['status']) . "\n";
    }
    $y .= "  favoriten:\n";
    $favorites = fav_list($userId);
    if ($favorites === []) {
        $y = substr($y, 0, -1) . " []\n";
    }
    foreach ($favorites as $favorite) {
        $y .= "    - art: " . yq($favorite['kind'] === 'category' ? 'kategorie' : 'gebiet') . "\n";
        $y .= "      wert: " . yq((string) $favorite['ref']) . "\n";
    }
    $y .= "  meldungen:\n";
    $reports = reports_by($userId);
    if ($reports === []) {
        $y = substr($y, 0, -1) . " []\n";
    }
    foreach ($reports as $report) {
        $y .= "    - thema: " . (int) $report['topic_id'] . "\n";
        $y .= "      status: " . yq((string) $report['status']) . "\n";
    }
    return $y;
}

function v_me(): void
{
    $user = require_user();
    $userId = (int) $user['id'];
    $duty = jury_pending_for($userId);
    $upcoming = $duty === null ? jury_upcoming_for($userId) : null;
    $yaml = profile_yaml($user);

    $html = '<div class="page-head"><h1>' . e(t('me.title')) . '</h1></div>'
        . '<section class="card id-card"><div><span class="field-label">' . e(t('me.short_id')) . '</span>'
        . '<span class="id-value">' . e(short_id($user)) . '</span></div>'
        . '<span class="muted">' . e(t('me.since', ['date' => Clock::displayLocal((string) $user['created_at'], t('common.date_format'))])) . '</span>';
    if ($user['jury_cooldown_until'] !== null && (string) $user['jury_cooldown_until'] > Clock::nowStr()) {
        $html .= '<span class="muted">' . e(t('me.cooldown', ['date' => Clock::displayLocal((string) $user['jury_cooldown_until'], t('common.datetime_format'))])) . '</span>';
    }
    $html .= '</section>';

    if ($duty !== null) {
        $html .= '<div class="flash">' . e(t('me.jury_pending')) . ' <a href="' . e(url('/jury')) . '">' . e(t('me.jury_go')) . '</a></div>';
    } elseif ($upcoming !== null) {
        $html .= '<p class="muted">' . e(t('me.jury_upcoming', ['date' => Clock::displayLocal((string) $upcoming['voting_starts_at'], t('common.date_format'))])) . '</p>';
    }

    $html .= '<section><div class="page-head"><h2>' . e(t('me.profile')) . '</h2>'
        . '<a class="btn btn-outline btn-sm" href="' . e(url('/profil.yaml')) . '">' . e(t('me.download')) . '</a></div>'
        . '<pre id="profil-yaml" class="yaml-block">' . e($yaml) . '</pre>'
        . '<p class="muted">' . e(t('me.logout_note')) . '</p></section>';

    $html .= '<section class="card danger-zone"><h2>' . e(t('me.delete_title')) . '</h2>'
        . '<p class="muted">' . e(t('me.delete_text')) . '</p>'
        . '<form method="post" action="' . e(url('/account/delete')) . '" class="form-stack">' . csrf_field()
        . '<label class="check-label"><input type="checkbox" name="confirm" value="yes" required><span>' . e(t('me.delete_confirm')) . '</span></label>'
        . '<div><button type="submit" class="btn btn-danger">' . e(t('me.delete_button')) . '</button></div></form></section>';
    render(t('me.title'), $html);
}

function v_jury(): void
{
    $user = require_user();
    $userId = (int) $user['id'];
    $duty = jury_pending_for($userId);
    $upcoming = $duty === null ? jury_upcoming_for($userId) : null;

    $html = '<h1>' . e(t('jury.title')) . '</h1>';
    if ($duty === null && $upcoming === null) {
        $html .= '<p class="muted">' . e(t('jury.none')) . '</p>'
            . '<p><a class="btn btn-outline btn-sm" href="' . e(url('/topics')) . '">' . e(t('nav.topics')) . '</a></p>';
        render(t('jury.title'), $html);
    }
    if ($duty === null && $upcoming !== null) {
        $html .= '<div class="flash">' . e(t('jury.upcoming', ['date' => Clock::displayLocal((string) $upcoming['voting_starts_at'], t('common.date_format'))])) . '</div>'
            . '<p><a class="btn btn-outline btn-sm" href="' . e(url('/topics')) . '">' . e(t('nav.topics')) . '</a></p>';
        render(t('jury.title'), $html);
    }

    $criteria = json_decode((string) $duty['criteria'], true);
    $criteria = is_array($criteria) ? array_values(array_filter($criteria, 'is_string')) : [];
    $tally = jury_tally((int) $duty['id']);
    $deadline = jury_deadline($duty);

    $html .= '<p>' . e(t('jury.intro')) . '</p>'
        . '<div class="flash">' . e(t('jury.blocked')) . '</div>'
        . '<section class="card"><h2 class="field-label">' . e(t('jury.reported')) . '</h2>'
        . '<h3>' . e((string) $duty['title']) . '</h3>'
        . '<p class="field-label">' . e(t('topic.goal_label')) . '</p><p>' . nl2br(e((string) $duty['goal'])) . '</p>'
        . '<p class="field-label">' . e(t('topic.reasoning_label')) . '</p><p>' . nl2br(e((string) $duty['reasoning'])) . '</p></section>'
        . '<section class="card"><h2 class="field-label">' . e(t('jury.criteria')) . '</h2><ul>';
    foreach ($criteria as $criterion) {
        $html .= '<li>' . e(t('criteria.' . $criterion)) . '</li>';
    }
    $html .= '</ul>';
    if ((string) ($duty['freetext'] ?? '') !== '') {
        $html .= '<h2 class="field-label">' . e(t('jury.freetext')) . '</h2><p>' . nl2br(e((string) $duty['freetext'])) . '</p>';
    }
    $html .= '</section><section class="card"><h2>' . e(t('jury.question')) . '</h2>'
        . '<form class="vote-actions" method="post" action="' . e(url('/jury/vote')) . '">' . csrf_field()
        . '<input type="hidden" name="report_id" value="' . (int) $duty['id'] . '">'
        . '<button type="submit" name="vote" value="confirm" class="btn vote-btn">' . e(t('jury.confirm')) . '</button>'
        . '<button type="submit" name="vote" value="reject" class="btn vote-btn">' . e(t('jury.reject')) . '</button>'
        . '<button type="submit" name="vote" value="neutral" class="btn btn-ghost">' . e(t('jury.neutral')) . '</button>'
        . '</form>'
        . '<p class="muted">' . e(t('jury.stats', [
            'cast' => num($tally['cast']),
            'seats' => num($tally['seats']),
            'quorum' => num(min((int) $duty['quorum'], $tally['seats'])),
        ])) . '</p>'
        . '<p class="muted">' . e(t('jury.deadline', ['date' => Clock::displayLocal($deadline, t('common.datetime_format'))])) . '</p>'
        . '</section>';
    render(t('jury.title'), $html);
}

function v_report(int $topicId): void
{
    require_user();
    $topic = topic_find($topicId);
    if ($topic === null || $topic['status'] !== 'active') {
        v_error_404();
    }
    if (report_open_for($topicId) !== null) {
        flash('info', 'flash.report_already_open');
        redirect('/topic/' . $topicId);
    }
    $html = '<h1>' . e(t('report.title')) . '</h1>'
        . '<p>' . e(t('report.intro')) . '</p>'
        . '<section class="card"><p class="field-label">' . e(t('jury.reported')) . '</p>'
        . '<h2>' . e((string) $topic['title']) . '</h2>'
        . '<p class="muted">' . e((string) $topic['goal']) . '</p></section>'
        . '<form class="card form-stack" method="post" action="' . e(url('/report')) . '">' . csrf_field()
        . '<input type="hidden" name="topic_id" value="' . (int) $topic['id'] . '">'
        . '<fieldset class="criteria-set"><legend>' . e(t('report.criteria')) . '</legend>';
    foreach (SW_CRITERIA as $criterion) {
        $html .= '<label class="check-label"><input type="checkbox" name="criteria[]" value="' . e($criterion) . '">'
            . '<span>' . e(t('criteria.' . $criterion)) . '</span></label>';
    }
    $html .= '</fieldset>'
        . '<label><span>' . e(t('report.freetext')) . '</span>'
        . '<textarea name="freetext" rows="4" maxlength="' . SW_FREETEXT_MAX . '"></textarea></label>'
        . '<p class="muted">' . e(t('report.process')) . '</p>'
        . '<div class="btn-row"><button type="submit" class="btn btn-primary">' . e(t('report.submit')) . '</button>'
        . '<a class="btn btn-ghost" href="' . e(url('/topic/' . (int) $topic['id'])) . '">' . e(t('report.cancel')) . '</a></div>'
        . '</form>';
    render(t('report.title'), $html);
}

/* ============================== Schreib-Aktionen ========================== */

function safe_return(string $fallback): string
{
    $return = post_str('return', 200);
    if (preg_match('#^/(topics(\?[A-Za-z0-9=&%._\-]*)?|topic/\d{1,10}|topics/new|me|jury|auth|imprint|privacy)?$#', $return) === 1) {
        return $return === '' ? '/' : $return;
    }
    return $fallback;
}

function h_tap(): void
{
    if (!rate_allow('auth:' . ip_key(), 10, 600)) {
        flash('error', 'flash.rate_limited');
        redirect('/auth');
    }
    $card = card_load();
    if ($card === null) {
        $card = card_create();
    }
    // Statische Challenge = der öffentliche Schlüssel selbst, zeitgebunden
    // versiegelt; der Server öffnet mit dem öffentlichen Schlüssel.
    $identity = card_identity($card);
    $sealed = card_seal($card, 'login:' . $identity);
    if (!card_open($card['pk'], $sealed, 'login:' . $identity)) {
        log_line('SECURITY', 'card_verify_failed', []);
        flash('error', 'flash.auth_failed');
        redirect('/auth');
    }
    auth_login($identity);
    $_SESSION['auth_slot'] = time_slot();
    flash('success', 'flash.auth_ok');
    redirect('/me');
}

function h_card_new(): void
{
    auth_logout();
    card_forget();
    flash('info', 'flash.card_new');
    redirect('/auth');
}

function h_logout(): void
{
    auth_logout();
    flash('info', 'flash.logged_out');
    redirect('/');
}

function h_lang(): void
{
    $lang = post_str('lang', 5);
    if (!in_array($lang, (array) SW::$cfg['langs'], true)) {
        redirect('/');
    }
    $_SESSION['lang'] = $lang;
    $user = auth_user();
    if ($user !== null) {
        SW::$db->run('UPDATE users SET lang = ? WHERE id = ?', [$lang, (int) $user['id']]);
    }
    redirect(safe_return('/'));
}

function h_vote(): void
{
    $user = require_user();
    require_card($user);
    $topicId = post_int('topic_id');
    $choice = post_str('choice', 10);
    if ($topicId === null) {
        redirect('/topics');
    }
    $back = '/topic/' . $topicId;
    if (!rate_allow('vote:' . (int) $user['id'], 60, 600)) {
        flash('error', 'flash.rate_limited');
        redirect($back);
    }
    try {
        vote_cast((int) $user['id'], $topicId, $choice);
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
        redirect($back);
    }
    flash('success', $choice === 'none' ? 'flash.vote_withdrawn' : 'flash.vote_saved');
    redirect($back);
}

function h_topic_create(): void
{
    $user = require_user();
    require_card($user);
    $userId = (int) $user['id'];
    if (!rate_allow('topic-form:' . $userId, 10, 600)) {
        flash('error', 'flash.rate_limited');
        redirect('/topics/new');
    }
    $old = [
        'title'       => post_str('title', SW_TITLE_MAX),
        'goal'        => post_str('goal', SW_GOAL_MAX, true),
        'reasoning'   => post_str('reasoning', SW_REASONING_MAX, true),
        'category_id' => post_int('category_id') ?? 0,
        'scope'       => post_str('scope', 160),
    ];
    $errors = [];
    if (mb_strlen($old['title']) < SW_TITLE_MIN) {
        $errors[] = 'topic.err_title';
    }
    if (mb_strlen($old['goal']) < SW_GOAL_MIN) {
        $errors[] = 'topic.err_goal';
    }
    if (mb_strlen($old['reasoning']) < SW_REASONING_MIN) {
        $errors[] = 'topic.err_reasoning';
    }
    $category = $old['category_id'] > 0
        ? SW::$db->one('SELECT id FROM categories WHERE id = ?', [$old['category_id']])
        : null;
    if ($category === null) {
        $errors[] = 'topic.err_category';
    }
    $scope = scope_decode($old['scope']);
    if ($scope === null) {
        $errors[] = 'topic.err_scope';
    }
    if ($errors !== []) {
        v_topic_new($errors, $old, topic_has_posted_today($userId));
    }
    try {
        $topicId = topic_create(
            $userId,
            $old['title'],
            $old['goal'],
            $old['reasoning'],
            (int) $old['category_id'],
            $scope[0],
            $scope[1]
        );
    } catch (DomainException $e) {
        v_topic_new([$e->getMessage()], $old, topic_has_posted_today($userId));
        return;
    }
    flash('success', 'flash.topic_created');
    redirect('/topic/' . $topicId);
}

function h_favorite(): void
{
    $user = require_user();
    require_card($user);
    $kind = post_str('kind', 10);
    $ref = post_str('ref', 100);
    $back = safe_return('/topics');
    if (!rate_allow('favorite:' . (int) $user['id'], 60, 600)) {
        flash('error', 'flash.rate_limited');
        redirect($back);
    }
    try {
        $added = fav_toggle((int) $user['id'], $kind, $ref);
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
        redirect($back);
    }
    flash('success', $added ? 'flash.favorite_added' : 'flash.favorite_removed');
    redirect($back);
}

function h_report_create(): void
{
    $user = require_user();
    require_card($user);
    $topicId = post_int('topic_id');
    if ($topicId === null) {
        redirect('/topics');
    }
    if (!rate_allow('report-form:' . (int) $user['id'], 10, 600)) {
        flash('error', 'flash.rate_limited');
        redirect('/report/' . $topicId);
    }
    $criteria = post_str_list('criteria', SW_CRITERIA);
    if ($criteria === []) {
        flash('error', 'flash.report_no_criteria');
        redirect('/report/' . $topicId);
    }
    $freetext = post_str('freetext', SW_FREETEXT_MAX, true);
    try {
        report_create($topicId, (int) $user['id'], $criteria, $freetext === '' ? null : $freetext);
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
        redirect('/topic/' . $topicId);
    }
    log_line('SECURITY', 'report_created', ['topic' => $topicId]);
    flash('success', 'flash.report_created');
    redirect('/topic/' . $topicId);
}

function h_jury_vote(): void
{
    $user = require_user();
    require_card($user);
    $reportId = post_int('report_id');
    $vote = post_str('vote', 10);
    if ($reportId === null) {
        redirect('/jury');
    }
    if (!rate_allow('jury:' . (int) $user['id'], 30, 600)) {
        flash('error', 'flash.rate_limited');
        redirect('/jury');
    }
    try {
        jury_cast($reportId, (int) $user['id'], $vote);
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
        redirect('/jury');
    }
    flash('success', 'flash.jury_voted');
    redirect('/jury');
}

function h_account_delete(): void
{
    $user = require_user();
    require_card($user);
    if (post_str('confirm', 10) !== 'yes') {
        flash('error', 'flash.delete_not_confirmed');
        redirect('/me');
    }
    account_delete((int) $user['id']);
    auth_logout();
    flash('success', 'flash.account_deleted');
    redirect('/');
}

/* ============================== Web-Hauptlauf ============================= */

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; "
        . "img-src 'self'; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Robots-Tag: noindex');
    if (sw_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function web_main(): void
{
    try {
        sw_setup();
    } catch (Throwable $e) {
        error_log('stimmwerk setup: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        $writable = is_writable(__DIR__ . '/data') || (!is_dir(__DIR__ . '/data') && is_writable(__DIR__));
        $hint = $writable
            ? 'Bitte pr&uuml;fen Sie, ob die PHP-Erweiterungen <code>pdo_sqlite</code> und <code>mbstring</code> aktiv sind.'
            : 'Bitte machen Sie das Verzeichnis f&uuml;r PHP beschreibbar (per FTP: Rechte 755 oder 775 f&uuml;r den Ordner der index.php setzen) und laden Sie die Seite neu.';
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Einrichtung erforderlich</title></head><body style="font-family:sans-serif;max-width:40em;margin:3em auto;padding:0 1em">'
            . '<h1>Fast geschafft</h1><p>Die Anwendung konnte noch nicht starten.</p><p>' . $hint . '</p>'
            . '<p style="color:#666">Details stehen im Server-Fehlerprotokoll.</p></body></html>';
        exit;
    }

    // Basispfad + Link-Stil (siehe base_path()).
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $base = rtrim($scriptDir, '/');
    if ($base !== '' && preg_match('#^(/[A-Za-z0-9._~\-]+)+$#', $base) !== 1) {
        $base = '';
    }
    SW::$base = $base;
    SW::$clean = (($_SERVER['SW_CLEAN_URLS'] ?? $_SERVER['REDIRECT_SW_CLEAN_URLS'] ?? '') === '1');

    // Interner Pfad: PATH_INFO (/index.php/topics) oder REQUEST_URI ohne Basis.
    $pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($pathInfo !== '' && $pathInfo[0] === '/') {
        $path = $pathInfo;
    } else {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        if (SW::$base !== '' && strpos($path, SW::$base) === 0) {
            $path = substr($path, strlen(SW::$base));
        }
        if (strpos($path, '/index.php') === 0) {
            $path = substr($path, strlen('/index.php'));
        }
    }
    SW::$path = $path === '' ? '/' : $path;
    $path = SW::$path;

    // Statische Eigen-Assets: ohne Session, mit Cache.
    if (preg_match('#^/a/(app\.css|app\.js|icon\.svg)$#', $path, $m) === 1) {
        $kindMap = ['app.css' => 'css', 'app.js' => 'js', 'icon.svg' => 'icon'];
        serve_asset($kindMap[$m[1]]);
    }
    if ($path === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nDisallow: /\n";
        exit;
    }

    send_security_headers();
    session_boot();

    // Sprache: Nutzerkonto -> Sitzung -> Standard.
    $user = auth_user();
    $lang = is_string($_SESSION['lang'] ?? null) ? (string) $_SESSION['lang'] : '';
    if ($user !== null) {
        $lang = (string) $user['lang'];
    }
    if (!in_array($lang, (array) SW::$cfg['langs'], true)) {
        $lang = (string) SW::$cfg['default_lang'];
    }
    SW::$lang = $lang;
    SW::$tActive = $lang === 'en' ? SW_EN : SW_DE;

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    try {
        maintenance_tick_throttled();

        if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            v_error(405, 'error.method');
        }

        // Allererster Aufruf: Sprachwahl über Flaggen, danach die Seite.
        $langChosen = is_string($_SESSION['lang'] ?? null) || $user !== null;
        if (!$langChosen && ($method === 'GET' || $method === 'HEAD')
            && !in_array($path, ['/start', '/imprint', '/privacy'], true)) {
            redirect('/start');
        }
        if ($path === '/start' && ($method === 'GET' || $method === 'HEAD')) {
            if ($langChosen) {
                redirect('/');
            }
            v_start();
        }
        // Zentrale CSRF-Prüfung: ausnahmslos jede POST-Anfrage.
        if ($method === 'POST' && !csrf_ok()) {
            log_line('SECURITY', 'csrf_failed', ['path' => $path]);
            flash('error', 'flash.csrf');
            redirect('/');
        }
        // Jury-Gate: offene Jury-Aufgabe -> zuerst dorthin.
        if ($user !== null && jury_pending_for((int) $user['id']) !== null) {
            $gateAllowed = ['/jury', '/jury/vote', '/logout', '/lang', '/imprint', '/privacy'];
            if (!in_array($path, $gateAllowed, true)) {
                redirect('/jury');
            }
        }

        $isGet = $method === 'GET' || $method === 'HEAD';
        if ($path === '/' && $isGet) {
            v_home();
        }
        if ($path === '/topics' && $isGet) {
            v_topics();
        }
        if ($path === '/topics/new' && $isGet) {
            $u = require_user();
            v_topic_new([], [
                'title' => '', 'goal' => '', 'reasoning' => '',
                'category_id' => 0, 'scope' => 'de',
            ], topic_has_posted_today((int) $u['id']));
        }
        if ($path === '/topics' && $method === 'POST') {
            h_topic_create();
        }
        if (preg_match('#^/topic/(\d{1,10})$#', $path, $m) === 1 && $isGet) {
            v_topic((int) $m[1]);
        }
        if ($path === '/vote' && $method === 'POST') {
            h_vote();
        }
        if ($path === '/favorite' && $method === 'POST') {
            h_favorite();
        }
        if (preg_match('#^/report/(\d{1,10})$#', $path, $m) === 1 && $isGet) {
            v_report((int) $m[1]);
        }
        if ($path === '/report' && $method === 'POST') {
            h_report_create();
        }
        if ($path === '/jury' && $isGet) {
            v_jury();
        }
        if ($path === '/jury/vote' && $method === 'POST') {
            h_jury_vote();
        }
        if ($path === '/me' && $isGet) {
            v_me();
        }
        if ($path === '/profil.yaml' && $isGet) {
            $u = require_user();
            header('Content-Type: text/yaml; charset=utf-8');
            header('Content-Disposition: attachment; filename="profil.yaml"');
            echo profile_yaml($u);
            exit;
        }
        if ($path === '/lang' && $method === 'POST') {
            h_lang();
        }
        if ($path === '/account/delete' && $method === 'POST') {
            h_account_delete();
        }
        if ($path === '/auth' && $isGet) {
            v_auth();
        }
        if ($path === '/tap' && $method === 'POST') {
            h_tap();
        }
        if ($path === '/card/new' && $method === 'POST') {
            h_card_new();
        }
        if ($path === '/logout' && $method === 'POST') {
            h_logout();
        }
        if ($path === '/imprint' && $isGet) {
            v_static('imprint.h', ['imprint.p1', 'imprint.p2']);
        }
        if ($path === '/privacy' && $isGet) {
            v_static('privacy.h', ['privacy.p1', 'privacy.p2', 'privacy.p3', 'privacy.p4', 'privacy.p5']);
        }
        v_error_404();
    } catch (Throwable $e) {
        log_line('ERROR', 'unhandled', ['type' => get_class($e), 'msg' => $e->getMessage(), 'path' => $path]);
        v_error(500, 'error.generic');
    }
}

/* ============================== CLI (Wartung & Selbsttest) ================ */

function cli_switch_db(string $tmpDir, string $name): void
{
    putenv('STIMMWERK_DB=' . $tmpDir . '/' . $name . '.sqlite');
    SW::$db = new Db($tmpDir . '/' . $name . '.sqlite');
    SW::$db->migrate();
    sw_seed_categories();
}

function cli_add_users(int $count, string $prefix): array
{
    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
        SW::$db->run(
            'INSERT INTO users (pseudonym_hash, lang, created_at) VALUES (?, ?, ?)',
            [$prefix . '-' . $i, 'de', Clock::nowStr()]
        );
        $ids[] = SW::$db->lastId();
    }
    return $ids;
}

function cli_make_topic(int $authorId, string $title): int
{
    $categoryId = (int) SW::$db->val('SELECT id FROM categories ORDER BY id LIMIT 1');
    return topic_create($authorId, $title, 'Ein Ziel für den Selbsttest dieses Themas.', 'Eine Begründung für den Selbsttest dieses Themas.', $categoryId, 'bund', null);
}

function cli_selftest(): int
{
    $tmpDir = sys_get_temp_dir() . '/stimmwerk-selftest-' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0700, true);
    $pass = 0;
    $fail = 0;
    $check = static function (string $description, bool $ok) use (&$pass, &$fail): void {
        if ($ok) {
            $pass++;
            echo "  ok  {$description}\n";
        } else {
            $fail++;
            echo "FAIL  {$description}\n";
        }
    };
    $t0 = new DateTimeImmutable('2026-03-02 12:00:00', new DateTimeZone('Europe/Berlin'));
    Clock::setTestNow($t0);
    $warp = static function (string $modify) use (&$t0): void {
        $t0 = $t0->modify($modify);
        Clock::setTestNow($t0);
    };

    echo "== Grunddaten ==\n";
    cli_switch_db($tmpDir, 'base');
    $check('Kategorien angelegt', count(categories()) >= 20);
    $check('Keine vorbefüllten Themen', site_stats()['topics'] === 0);
    $check('System-Konto vorhanden', (int) SW::$db->val('SELECT COUNT(*) FROM users WHERE is_system = 1') === 1);

    echo "== Ausweis-Schlüssel & Zeitfenster ==\n";
    if (card_supports_sodium()) {
        $pair = sodium_crypto_sign_keypair();
        $card = ['secret' => sodium_crypto_sign_secretkey($pair), 'pk' => sodium_crypto_sign_publickey($pair)];
        $other = sodium_crypto_sign_keypair();
        $otherPk = sodium_crypto_sign_publickey($other);
    } else {
        $secret = random_bytes(32);
        $card = ['secret' => $secret, 'pk' => hash('sha256', 'pk|' . $secret, true)];
        $otherPk = hash('sha256', 'pk|' . random_bytes(32), true);
    }
    $sealed = card_seal($card, 'vote');
    $check('Umschlag öffnet mit richtigem öffentlichen Schlüssel', card_open($card['pk'], $sealed, 'vote') === true);
    $check('Fremder öffentlicher Schlüssel wird abgelehnt', card_open($otherPk, $sealed, 'vote') === false);
    $check('Falsche Aktion wird abgelehnt', card_open($card['pk'], $sealed, 'report') === false);
    $tSave = $t0;
    $warp('+11 minutes');
    $check('Alter Umschlag verfällt (TOTP-Zeitfenster)', card_open($card['pk'], $sealed, 'vote') === false);
    $check('Neuer Umschlag zu neuer Zeit ist anders und gültig',
        card_seal($card, 'vote') !== $sealed && card_open($card['pk'], card_seal($card, 'vote'), 'vote') === true);
    $t0 = $tSave;
    Clock::setTestNow($t0);
    $check('Identität = öffentlicher Schlüssel (kein Pseudonym)', card_identity($card) === bin2hex($card['pk']));

    echo "== Geltungsbereich (amtliche Auswahl) ==\n";
    $check('Deutschland', scope_decode('de') === ['bund', null]);
    $check('Bundesland', scope_decode('bl:Bayern') === ['bundesland', 'Bayern']);
    $check('Landkreis', scope_decode('kr:Bayern:Landkreis München') === ['landkreis', 'Landkreis München']);
    $check('Unbekanntes Gebiet abgelehnt', scope_decode('kr:Bayern:Atlantis') === null && scope_decode('bl:Atlantis') === null);
    $check('Gebietsliste vollständig geladen', count(SW_REGIONS) === 16 && array_sum(array_map('count', SW_REGIONS)) > 350);

    echo "== Themen: 1 pro Tag ==\n";
    $alice = cli_add_users(1, 'alice')[0];
    $topicId = cli_make_topic($alice, 'Testthema Nummer eins');
    $check('Erstes Thema angelegt', $topicId > 0);
    try {
        cli_make_topic($alice, 'Zweites Thema am selben Tag');
        $check('Zweites Thema am selben Tag abgelehnt', false);
    } catch (DomainException $e) {
        $check('Zweites Thema am selben Tag abgelehnt', $e->getMessage() === 'flash.topic_daily_limit');
    }
    $warp('+1 day');
    $check('Thema am Folgetag erlaubt', cli_make_topic($alice, 'Thema am nächsten Tag') > 0);

    echo "== Stimmen & Favoriten ==\n";
    $bob = cli_add_users(1, 'bob')[0];
    vote_cast($bob, $topicId, 'for');
    $check('Stimme dafür gespeichert', topic_user_vote($topicId, $bob) === 'for');
    vote_cast($bob, $topicId, 'against');
    $check('Stimme änderbar', topic_user_vote($topicId, $bob) === 'against');
    vote_cast($bob, $topicId, 'none');
    $check('Stimme zurückziehbar (neutral = keine Stimme)', topic_user_vote($topicId, $bob) === null);
    $slug = (string) SW::$db->val('SELECT slug FROM categories ORDER BY id LIMIT 1');
    $check('Favorit angelegt', fav_toggle($bob, 'category', $slug) === true);
    $check('Favorit entfernt', fav_toggle($bob, 'category', $slug) === false);
    $check('Gebiets-Favorit (Landkreis) gegen Liste geprüft', fav_toggle($bob, 'scope', 'landkreis:Ostalbkreis') === true);
    try {
        fav_toggle($bob, 'scope', 'landkreis:Entenhausen');
        $check('Erfundenes Gebiet abgelehnt', false);
    } catch (DomainException $e) {
        $check('Erfundenes Gebiet abgelehnt', true);
    }

    echo "== Jury-Größe (1 %-Regel) ==\n";
    cli_switch_db($tmpDir, 'big');
    $crowd = cli_add_users(600, 'crowd');
    $reporter600 = cli_add_users(1, 'rep')[0];
    $target = cli_make_topic($crowd[0], 'Zielthema für die große Jury');
    report_create($target, $reporter600, ['volksverhetzung'], null);
    $bigReport = SW::$db->one('SELECT * FROM reports ORDER BY id DESC LIMIT 1');
    $check('Jury = 1 % bei 601 Nutzenden (aufgerundet)', (int) $bigReport['jury_size'] === (int) ceil(601 * 0.01));
    $check('Quorum = 0,5 % (mind. 3)', (int) $bigReport['quorum'] === max(3, (int) ceil(601 * 0.005)));

    echo "== Meldung & Jury: Ausschlüsse, Fristen, Karenz ==\n";
    cli_switch_db($tmpDir, 'jury');
    $users = cli_add_users(12, 'u');
    $tX = cli_make_topic($users[8], 'Gemeldetes Thema X');
    $tY = cli_make_topic($users[9], 'Gemeldetes Thema Y');
    $jurorsOf = static function (int $reportId): array {
        return array_map(static function (array $r): int {
            return (int) $r['user_id'];
        }, SW::$db->all('SELECT user_id FROM report_jurors WHERE report_id = ?', [$reportId]));
    };

    $r1 = report_create($tX, $users[0], ['kennzeichen', 'gewalt'], 'Testmeldung.');
    $j1 = $jurorsOf($r1);
    $check('Jury 1: 5 Sitze (Mindestgröße)', count($j1) === 5);
    $check('Melder und Autor nicht in der Jury', !in_array($users[0], $j1, true) && !in_array($users[8], $j1, true));
    $r1Row = SW::$db->one('SELECT * FROM reports WHERE id = ?', [$r1]);
    $check('Meldung wartet bis Mitternacht', $r1Row['status'] === 'pending');
    $check('Start zur nächsten Mitternacht (00:00 lokal)', $r1Row['voting_starts_at'] === Clock::nextLocalMidnightUtcStr());
    try {
        report_create($tX, $users[1], ['beleidigung'], null);
        $check('Zweite Meldung zum selben Thema abgelehnt', false);
    } catch (DomainException $e) {
        $check('Zweite Meldung zum selben Thema abgelehnt', $e->getMessage() === 'flash.report_already_open');
    }
    $r2 = report_create($tY, $users[1], ['bedrohung'], null);
    $j2 = $jurorsOf($r2);
    $check('Jury 2 disjunkt zu laufender Jury 1', array_intersect($j1, $j2) === []);
    $check('Kein Jury-Gate vor Abstimmungsstart', jury_pending_for($j1[0]) === null);

    $warp('+1 day');
    maintenance_tick();
    $check('Abstimmung um 00:00 gestartet', SW::$db->val('SELECT status FROM reports WHERE id = ?', [$r1]) === 'voting');
    $check('Jury-Gate nach Start aktiv', jury_pending_for($j1[0]) !== null);
    jury_cast($r1, $j1[0], 'confirm');
    jury_cast($r1, $j1[1], 'confirm');
    jury_cast($r1, $j1[2], 'neutral');
    maintenance_tick();
    $check('Keine Entscheidung vor Ablauf der 24 h', SW::$db->val('SELECT status FROM reports WHERE id = ?', [$r1]) === 'voting');
    try {
        jury_cast($r1, $j1[0], 'reject');
        $check('Doppelte Jury-Stimme abgelehnt', false);
    } catch (DomainException $e) {
        $check('Doppelte Jury-Stimme abgelehnt', $e->getMessage() === 'flash.jury_already_voted');
    }
    $warp('+25 hours');
    maintenance_tick();
    $r1Row = SW::$db->one('SELECT * FROM reports WHERE id = ?', [$r1]);
    $check('Entscheidung nach Frist + Quorum (2:0 bestätigt)', $r1Row['status'] === 'decided_removed');
    $check('Thema entfernt', SW::$db->val('SELECT status FROM topics WHERE id = ?', [$tX]) === 'removed');
    $expectedCooldown = Clock::addDaysStr((string) $r1Row['decided_at'], 3);
    $cooldowns = SW::$db->all(
        'SELECT jury_cooldown_until FROM users WHERE id IN (' . implode(',', array_fill(0, count($j1), '?')) . ')',
        $j1
    );
    $check('Karenz (3 Tage) für alle Jury-Mitglieder gesetzt', array_unique(array_column($cooldowns, 'jury_cooldown_until')) === [$expectedCooldown]);

    $tZ = cli_make_topic($j1[0], 'Gemeldetes Thema Z');
    $r3 = report_create($tZ, $j2[0], ['privatdaten'], null);
    $j3 = $jurorsOf($r3);
    $expectedJ3 = array_values(array_diff(array_map('intval', $users), $j1, $j2));
    sort($j3);
    sort($expectedJ3);
    $check('Karenz + laufende Jurys schließen korrekt aus (Restmenge = 2)', $j3 === $expectedJ3 && count($j3) === 2);

    $warp('+1 day');
    maintenance_tick();
    jury_cast($r3, $j3[0], 'reject');
    $warp('+25 hours');
    maintenance_tick();
    $check('Meldung läuft weiter, bis das Quorum erreicht ist', SW::$db->val('SELECT status FROM reports WHERE id = ?', [$r3]) === 'voting');
    jury_cast($r3, $j3[1], 'reject');
    $check('Entscheidung sofort bei Quorum nach Fristablauf (behalten)', SW::$db->val('SELECT status FROM reports WHERE id = ?', [$r3]) === 'decided_kept');
    $check('Thema bleibt bei Ablehnung bestehen', SW::$db->val('SELECT status FROM topics WHERE id = ?', [$tZ]) === 'active');

    $warp('+2 days');
    $tW = cli_make_topic($j2[2], 'Gemeldetes Thema W');
    $r4 = report_create($tW, $j2[1], ['sonstiges'], null);
    $j4 = $jurorsOf($r4);
    sort($j1);
    sort($j4);
    $check('Nach 3 Tagen Karenz wieder losbar (Jury 4 = frühere Jury 1)', $j4 === $j1);

    echo "== Kontolöschung (DSGVO) ==\n";
    cli_switch_db($tmpDir, 'gdpr');
    $pairIds = cli_add_users(2, 'cd');
    $carol = $pairIds[0];
    $dave = $pairIds[1];
    $carolTopic = cli_make_topic($carol, 'Thema von Carol zum Löschen');
    $daveTopic = cli_make_topic($dave, 'Thema von Dave bleibt bestehen');
    vote_cast($carol, $daveTopic, 'for');
    fav_toggle($carol, 'scope', 'bundesland:Bayern');
    account_delete($carol);
    $check('Nutzer gelöscht', (int) SW::$db->val('SELECT COUNT(*) FROM users WHERE id = ?', [$carol]) === 0);
    $check('Stimmen gelöscht', (int) SW::$db->val('SELECT COUNT(*) FROM votes WHERE user_id = ?', [$carol]) === 0);
    $check('Favoriten gelöscht', (int) SW::$db->val('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$carol]) === 0);
    $systemId = (int) SW::$db->val('SELECT id FROM users WHERE is_system = 1');
    $check('Thema entkoppelt (System-Konto)', (int) SW::$db->val('SELECT author_id FROM topics WHERE id = ?', [$carolTopic]) === $systemId);

    Clock::setTestNow(null);
    putenv('STIMMWERK_DB');
    array_map('unlink', glob($tmpDir . '/*') ?: []);
    rmdir($tmpDir);
    printf("\nErgebnis: %d bestanden, %d fehlgeschlagen.\n", $pass, $fail);
    return $fail === 0 ? 0 : 1;
}

function cli_seed(int $count): void
{
    $created = 0;
    $votes = 0;
    SW::$db->tx(function () use ($count, &$created, &$votes): void {
        $now = Clock::nowStr();
        for ($i = 0; $i < $count; $i++) {
            SW::$db->run(
                'INSERT INTO users (pseudonym_hash, lang, is_seed, created_at) VALUES (?, ?, 1, ?)',
                ['seed-' . bin2hex(random_bytes(28)), 'de', $now]
            );
            $created++;
        }
        $seedIds = array_map(static function (array $r): int {
            return (int) $r['id'];
        }, SW::$db->all('SELECT id FROM users WHERE is_seed = 1'));
        foreach (SW::$db->all("SELECT id FROM topics WHERE status = 'active'") as $topic) {
            $turnout = random_int(15, 60);
            $forShare = random_int(25, 75);
            foreach ($seedIds as $userId) {
                if (random_int(1, 100) > $turnout) {
                    continue;
                }
                $choice = random_int(1, 100) <= $forShare ? 'for' : 'against';
                SW::$db->run(
                    'INSERT OR IGNORE INTO votes (topic_id, user_id, choice, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [(int) $topic['id'], $userId, $choice, $now, $now]
                );
                $votes++;
            }
        }
    });
    printf("Demo-Nutzer angelegt: %d, Stimmen erzeugt: %d\n", $created, $votes);
}

function cli_jurysim(): void
{
    maintenance_tick();
    $seats = SW::$db->all(
        "SELECT rj.report_id, rj.user_id
         FROM report_jurors rj
         JOIN reports r ON r.id = rj.report_id
         JOIN users u   ON u.id = rj.user_id
         WHERE r.status = 'voting' AND rj.vote IS NULL AND u.is_seed = 1"
    );
    $cast = 0;
    foreach ($seats as $seat) {
        if (random_int(1, 100) > 80) {
            continue;
        }
        $roll = random_int(1, 100);
        $vote = $roll <= 55 ? 'confirm' : ($roll <= 85 ? 'reject' : 'neutral');
        try {
            jury_cast((int) $seat['report_id'], (int) $seat['user_id'], $vote);
            $cast++;
        } catch (DomainException $e) {
            // Meldung zwischenzeitlich entschieden – unkritisch.
        }
    }
    printf("Simulierte Jury-Stimmen: %d\n", $cast);
}

function cli_main(array $argv): int
{
    $cmd = $argv[1] ?? 'help';
    if ($cmd === 'selftest') {
        Clock::setTimezone((string) SW::$cfg['timezone']);
        date_default_timezone_set('UTC');
        return cli_selftest();
    }
    sw_setup();
    if ($cmd === 'cron') {
        maintenance_tick();
        echo "ok\n";
        return 0;
    }
    if ($cmd === 'seed') {
        $n = isset($argv[2]) && preg_match('/^\d{1,5}$/', $argv[2]) === 1 ? (int) $argv[2] : 400;
        cli_seed($n);
        return 0;
    }
    if ($cmd === 'jurysim') {
        cli_jurysim();
        return 0;
    }
    echo "Aufrufe: php index.php selftest | cron | seed [n] | jurysim\n";
    return $cmd === 'help' ? 0 : 1;
}

/* ============================== Einstieg ================================== */

if (PHP_SAPI === 'cli') {
    exit(cli_main($argv));
}
web_main();
