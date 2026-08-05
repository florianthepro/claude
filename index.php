<?php

declare(strict_types=1);

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

const SW_CONFIG = [
    'app_name' => 'Bürgerabstimmung', // Name in Titel und Fußzeile
    'domain'   => '', // leer = Host der Anfrage; im Echtbetrieb hier die feste Domain eintragen

    'show_official_banner' => true, // true/false: gelbes Band mit dem Hinweis, dass die Seite nicht von einer Behörde stammt

    'testmode_end' => 'edit', // gui = Umschalten unter /setup, edit = Umschalten sobald die Werte hier stimmen (Fehler stehen im Protokoll)

    'eid_mode' => 'demo', // demo = Anmeldung über die Trust-Liste, eid = nur über eigenen eID-Server

    'authorized_keys_url' => '', // https-Adresse der Trust-Liste mit freigegebenen Ausweis-Schlüsseln, leer = keine

    // Abgleich der Listen: nur https://raw.githubusercontent.com/..., leer = kein Abgleich.
    // Es werden ausschließlich neue Werte ergänzt, nie welche geändert oder gelöscht.
    'categories_url' => '', // z. B. https://raw.githubusercontent.com/KONTO/REPO/main/categories.json
    'regions_url'    => '', // z. B. https://raw.githubusercontent.com/KONTO/REPO/main/regions.json

    'eid_client_url' => 'http://127.0.0.1:24727/eID-Client', // Ausweis-App auf dem Gerät (BSI TR-03124)

    'eid_server_url'  => '', // https-SOAP-Adresse des eigenen eID-Servers (BSI TR-03130)
    'eid_server_cert' => '', // Pfad zum Client-Zertifikat für den eID-Server
    'eid_server_key'  => '', // Pfad zum privaten Schlüssel dazu
    'eid_providers' => [ // Anmelde-Knöpfe auf /auth; start = https-Startadresse, leer = Knopf meldet „nicht eingerichtet“
        'ausweisapp' => ['label' => 'AusweisApp', 'start' => ''],
        'nect'       => ['label' => 'Nect Wallet', 'start' => ''],
    ],

    'timezone'     => 'Europe/Berlin', // Zeitzone aller angezeigten Daten
    'default_lang' => 'de', // Sprache vor der Auswahl: de oder en
    'langs'        => ['de', 'en'], // wählbare Sprachen

    'jury_share'         => 0.01, // Anteil der Nutzer, der je Meldung ausgelost wird
    'jury_min'           => 5, // angestrebte Mindestgröße der Jury
    'quorum_share'       => 0.005, // Anteil der Nutzer, der für eine gültige Entscheidung stimmen muss
    'quorum_min'         => 3, // angestrebte Mindestzahl abgegebener Jurystimmen
    'report_vote_hours'  => 24, // Stunden, die eine Jury zum Entscheiden hat
    'jury_cooldown_days' => 3, // Tage, die jemand nach einem Dienst nicht erneut gelost wird
    'reports_per_day'    => 3, // Meldungen, die eine Person pro Tag abgeben darf

    'session_idle_minutes' => 30, // Minuten ohne Aufruf bis zur Abmeldung
    'session_max_hours'    => 8, // Stunden bis zur Abmeldung, auch bei Betrieb

    'page_size' => 20, // Themen je Seite
];

final class SW
{
    public static array $cfg = SW_CONFIG;
    public static ?Db $db = null;
    public static string $dataDir = '';
    public static string $pepper = '';
    public static string $serverSign = '';
    public static ?bool $testMode = null;
    public static string $lang = 'de';
    public static string $headerExtra = '';
    public static array $lists = [];

    public static array $tActive = [];
    public static ?array $user = null;
    public static string $base = '';
    public static string $path = '/';
}

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

    public static function localDate(): string
    {
        return self::now()->setTimezone(new DateTimeZone(self::$tz))->format('Y-m-d');
    }

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
    status       TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','closed','removed','archived')),
    end_mode     TEXT    NOT NULL DEFAULT 'date' CHECK (end_mode IN ('date','count','both')),
    end_date     TEXT,
    end_target   INTEGER,
    created_at   TEXT    NOT NULL,
    created_date TEXT    NOT NULL,
    UNIQUE (author_id, created_date)
);
CREATE INDEX IF NOT EXISTS ix_topics_status_created ON topics(status, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_topics_category       ON topics(category_id);
CREATE INDEX IF NOT EXISTS ix_topics_scope          ON topics(scope_level, scope_name);

CREATE TABLE IF NOT EXISTS votes (
    topic_id   INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    voter_tag  TEXT    NOT NULL,
    choice     TEXT    NOT NULL CHECK (choice IN ('for','against','neutral')),
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    PRIMARY KEY (topic_id, voter_tag)
);
CREATE TABLE IF NOT EXISTS favorites (
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind       TEXT    NOT NULL CHECK (kind IN ('category','scope','topic')),
    ref        TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    PRIMARY KEY (user_id, kind, ref)
);
CREATE TABLE IF NOT EXISTS reports (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_id         INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
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

CREATE TABLE IF NOT EXISTS eid_flows (
    nonce      TEXT    PRIMARY KEY,
    session_id TEXT    NOT NULL,
    eid_ref    TEXT,
    created_at INTEGER NOT NULL
);
SQL;

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
        $this->pdo->exec(SW_SCHEMA);
        if ($exists === null) {
            $this->run("INSERT INTO schema_info (k, v) VALUES ('version', '1'), ('created_at', ?)", [Clock::nowStr()]);
        }
        $voteSql = (string) ($this->val("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'votes'") ?? '');
        if ($voteSql !== '' && strpos($voteSql, "'neutral'") === false) {
            $this->pdo->exec(
                "CREATE TABLE votes_new (
                    topic_id   INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
                    voter_tag  TEXT    NOT NULL,
                    choice     TEXT    NOT NULL CHECK (choice IN ('for','against','neutral')),
                    created_at TEXT    NOT NULL,
                    updated_at TEXT    NOT NULL,
                    PRIMARY KEY (topic_id, voter_tag)
                );
                INSERT INTO votes_new SELECT topic_id, voter_tag, choice, created_at, updated_at FROM votes;
                DROP TABLE votes;
                ALTER TABLE votes_new RENAME TO votes;"
            );
        }
        $favSql = (string) ($this->val("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'favorites'") ?? '');
        if ($favSql !== '' && strpos($favSql, "'topic'") === false) {
            $this->pdo->exec(
                "CREATE TABLE favorites_new (
                    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    kind       TEXT    NOT NULL CHECK (kind IN ('category','scope','topic')),
                    ref        TEXT    NOT NULL,
                    created_at TEXT    NOT NULL,
                    PRIMARY KEY (user_id, kind, ref)
                );
                INSERT INTO favorites_new SELECT user_id, kind, ref, created_at FROM favorites;
                DROP TABLE favorites;
                ALTER TABLE favorites_new RENAME TO favorites;"
            );
        }
    }
}

const SW_HTACCESS_DENY = <<<'TXT'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
TXT;

const SW_HTACCESS_ROOT = <<<'TXT'

Options -Indexes -MultiViews
DirectoryIndex index.php
<IfModule mod_rewrite.c>
    RewriteEngine On
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

    if (card_supports_sodium()) {
        $srvFile = SW::$dataDir . '/server_sign.key';
        if (!is_file($srvFile)) {
            $pair = sodium_crypto_sign_keypair();
            if (file_put_contents($srvFile, base64_encode(sodium_crypto_sign_secretkey($pair)), LOCK_EX) === false) {
                throw new RuntimeException('Server-Signaturschlüssel nicht schreibbar.');
            }
            @chmod($srvFile, 0600);
        }
        SW::$serverSign = base64_decode(trim((string) file_get_contents($srvFile)), true) ?: '';
    }

    setup_apply(setup_load());

    $dbPath = getenv('BUERGERABSTIMMUNG_DB') ?: SW::$dataDir . '/buergerabstimmung.sqlite';
    SW::$db = new Db($dbPath);
    SW::$db->migrate();
    sw_seed_categories();

    testmode_edit_tick();
}

const SW_SETUP_KEYS = [
    'eid_mode', 'eid_server_url', 'eid_server_cert', 'eid_server_key',
    'eid_client_url', 'authorized_keys_url', 'nect_start',
];

function setup_file(): string
{
    return SW::$dataDir . '/config.yaml';
}

function setup_token_file(): string
{
    return SW::$dataDir . '/setup.token';
}

function setup_token(): string
{
    $file = setup_token_file();
    if (!is_file($file)) {
        @file_put_contents($file, bin2hex(random_bytes(16)) . "\n", LOCK_EX);
        @chmod($file, 0600);
    }
    return trim((string) @file_get_contents($file));
}

function setup_load(): array
{
    $file = setup_file();
    if (!is_file($file)) {
        return [];
    }
    $out = [];
    foreach (explode("\n", (string) file_get_contents($file)) as $line) {
        if (preg_match('/^([a-z_]+):\s*"(.*)"\s*$/', trim($line), $m) !== 1) {
            continue;
        }
        if (in_array($m[1], SW_SETUP_KEYS, true)) {
            $out[$m[1]] = str_replace(['\\"', '\\\\'], ['"', '\\'], $m[2]);
        }
    }
    return $out;
}

function setup_save(array $values): bool
{
    $lines = [];
    foreach (SW_SETUP_KEYS as $key) {
        if (!isset($values[$key])) {
            continue;
        }
        $value = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $values[$key]);
        $lines[] = $key . ': "' . $value . '"';
    }
    $ok = @file_put_contents(setup_file(), implode("\n", $lines) . "\n", LOCK_EX) !== false;
    if ($ok) {
        @chmod(setup_file(), 0600);
    }
    return $ok;
}

function setup_apply(array $values): void
{
    foreach ($values as $key => $value) {
        if ($key === 'nect_start') {
            $providers = SW::$cfg['eid_providers'];
            $providers['nect']['start'] = (string) $value;
            SW::$cfg['eid_providers'] = $providers;
            continue;
        }
        if (in_array($key, SW_SETUP_KEYS, true)) {
            SW::$cfg[$key] = $value;
        }
    }
}

function setup_read_post(): array
{
    return [
        'eid_mode'            => post_str('eid_mode', 10) === 'eid' ? 'eid' : 'demo',
        'eid_server_url'      => post_str('eid_server_url', 300),
        'eid_server_cert'     => post_str('eid_server_cert', 300),
        'eid_server_key'      => post_str('eid_server_key', 300),
        'eid_client_url'      => post_str('eid_client_url', 200),
        'authorized_keys_url' => post_str('authorized_keys_url', 300),
        'nect_start'          => post_str('nect_start', 300),
    ];
}

function setup_validate(array $in): array
{
    $errors = [];
    if ($in['eid_client_url'] === '') {
        $in['eid_client_url'] = (string) SW_CONFIG['eid_client_url'];
    }
    if (preg_match('#^https?://[^\s"\']+$#', $in['eid_client_url']) !== 1) {
        $errors[] = 'setup.err_client';
    }
    foreach (['eid_server_url', 'authorized_keys_url', 'nect_start'] as $key) {
        if ($in[$key] !== '' && preg_match('#^https://[^\s"\']+$#', $in[$key]) !== 1) {
            $errors[] = 'setup.err_https';
        }
    }
    foreach (['eid_server_cert', 'eid_server_key'] as $key) {
        if ($in[$key] !== '' && !is_readable($in[$key])) {
            $errors[] = 'setup.err_file';
        }
    }
    if ($in['eid_mode'] === 'eid' && $in['eid_server_url'] === '') {
        $errors[] = 'setup.err_server_missing';
    }
    if ($in['eid_mode'] === 'demo' && $in['authorized_keys_url'] === '' && authorized_count_stable() === 0) {
        $errors[] = 'setup.err_list_empty';
    }
    return [array_values(array_unique($errors)), $in];
}

function setup_probe(array $cfg): bool
{
    return eid_server_useid($cfg) !== null;
}

function setup_probe_list(string $url): ?int
{
    if ($url === '') {
        return authorized_count_stable();
    }
    if (preg_match('#^https://#', $url) !== 1) {
        return null;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 8],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    $found = preg_match_all('/[0-9a-fA-F]{64,128}/', $body, $mm);
    return $found === false ? null : $found;
}

function setup_check_stamp(array $values): string
{
    return sw_hmac('setup-check|' . implode('|', [
        (string) ($values['eid_mode'] ?? ''),
        (string) ($values['eid_server_url'] ?? ''),
        (string) ($values['eid_server_cert'] ?? ''),
        (string) ($values['eid_server_key'] ?? ''),
        (string) ($values['authorized_keys_url'] ?? ''),
    ]));
}

function setup_checked(array $values): bool
{
    return hash_equals((string) ($_SESSION['setup_check'] ?? ''), setup_check_stamp($values));
}

function setup_ready(): array
{
    $htaccess = is_file(__DIR__ . '/.htaccess');
    return [
        ['setup.check_data', is_writable(SW::$dataDir)],
        ['setup.check_https', sw_is_https()],
        ['setup.check_sodium', card_supports_sodium()],
        ['setup.check_htaccess', $htaccess],
    ];
}

function sw_hmac(string $value): string
{
    return hash_hmac('sha256', $value, SW::$pepper);
}

function testmode_config(): array
{
    return [
        'eid_mode'            => (string) SW::$cfg['eid_mode'],
        'eid_server_url'      => (string) SW::$cfg['eid_server_url'],
        'eid_server_cert'     => (string) SW::$cfg['eid_server_cert'],
        'eid_server_key'      => (string) SW::$cfg['eid_server_key'],
        'eid_client_url'      => (string) SW::$cfg['eid_client_url'],
        'authorized_keys_url' => (string) SW::$cfg['authorized_keys_url'],
        'nect_start'          => (string) (SW::$cfg['eid_providers']['nect']['start'] ?? ''),
    ];
}

function testmode_edit_tick(): void
{
    if ((string) SW::$cfg['testmode_end'] !== 'edit' || !test_mode()) {
        return;
    }
    $last = (int) (SW::$db->val("SELECT v FROM schema_info WHERE k = 'edit_check'") ?? 0);
    $now = time();
    if ($now - $last < 300) {
        return;
    }
    SW::$db->run(
        "INSERT INTO schema_info (k, v) VALUES ('edit_check', ?) ON CONFLICT(k) DO UPDATE SET v = ?",
        [(string) $now, (string) $now]
    );

    $values = testmode_config();
    [$errors] = setup_validate($values);
    if ($errors === [] && $values['eid_mode'] === 'eid' && !setup_probe($values)) {
        $errors[] = 'flash.setup_failed';
    }
    if ($errors === [] && $values['eid_mode'] === 'demo') {
        $count = setup_probe_list($values['authorized_keys_url']);
        if ($count === null || $count === 0) {
            $errors[] = 'setup.err_list_empty';
        }
    }
    if ($errors !== []) {
        log_line('SETUP', 'testmode_edit_invalid', ['errors' => $errors]);
        return;
    }
    test_mode_end();
    log_line('SETUP', 'testmode_ended_by_edit', []);
}

function test_mode(): bool
{
    if (SW::$testMode === null) {
        SW::$testMode = SW::$db !== null
            && (string) (SW::$db->val("SELECT v FROM schema_info WHERE k = 'test_mode'") ?? '1') === '1';
    }
    return SW::$testMode;
}

function test_mode_end(): void
{
    SW::$db->tx(function (): void {
        SW::$db->run('DELETE FROM report_jurors');
        SW::$db->run('DELETE FROM reports');
        SW::$db->run('DELETE FROM votes');
        SW::$db->run('DELETE FROM favorites');
        SW::$db->run('DELETE FROM topics');
        SW::$db->run('DELETE FROM users WHERE is_system = 0');
        SW::$db->run('DELETE FROM rate_limits');
        SW::$db->run('DELETE FROM eid_flows');
        SW::$db->run(
            "INSERT INTO schema_info (k, v) VALUES ('test_mode', '0')
             ON CONFLICT(k) DO UPDATE SET v = '0'"
        );
    });
    SW::$testMode = false;

    authorized_remove(test_keys_load());
    @unlink(test_keys_file());
    log_line('SECURITY', 'test_mode_ended', []);
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function t(string $key, array $repl = []): string
{
    $text = SW::$tActive[$key] ?? SW_DE[$key] ?? $key;
    foreach ($repl as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}

function num(int $n): string
{
    return SW::$lang === 'de' ? number_format($n, 0, ',', '.') : number_format($n);
}

function base_path(): string
{
    return SW::$base;
}

function url(string $path): string
{
    $full = base_path() . $path;
    if ($full === '') {
        $full = '/';
    }
    $carry = lang_stored() === '' ? lang_wanted() : '';
    if ($carry !== '' && strpos($path, 'lang=') === false
        && preg_match('#^/(a/|favicon|apple-touch-icon|icon-192|server\.pub|robots\.txt)#', $path) !== 1) {
        $full .= (strpos($full, '?') === false ? '?' : '&') . 'lang=' . rawurlencode($carry);
    }
    return $full;
}

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

function sw_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function consent_given(): bool
{
    return (string) ($_COOKIE['sw_consent'] ?? '') === '1';
}

function cookie_kill(string $name): void
{
    setcookie($name, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => sw_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function session_forget(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        cookie_kill(session_name());
        session_destroy();
    }
    cookie_kill('sw_consent');
    cookie_kill('sw_lang');
}

function consent_set(): void
{
    setcookie('sw_consent', '1', [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => sw_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function consent_return(): string
{
    $to = query_str('to', 120);
    return preg_match('#^/(topic/\d{1,10}|auth|about|imprint|privacy)?$#', $to) === 1 && $to !== '' ? $to : '/';
}

function lang_from_browser(): string
{
    $raw = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $first = strtolower(substr(trim(explode(',', $raw)[0]), 0, 2));
    return in_array($first, (array) SW::$cfg['langs'], true) ? $first : (string) SW::$cfg['default_lang'];
}

function lang_valid(string $code): bool
{
    return in_array($code, (array) SW::$cfg['langs'], true);
}

function lang_stored(): string
{
    $value = (string) ($_COOKIE['sw_lang'] ?? '');
    return lang_valid($value) ? $value : '';
}

function lang_wanted(): string
{
    $value = query_str('lang', 5);
    return lang_valid($value) ? $value : '';
}

function lang_store(string $code): void
{
    if (!lang_valid($code)) {
        return;
    }
    setcookie('sw_lang', $code, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => sw_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function lang_active(): string
{
    $lang = lang_stored();
    if ($lang === '') {
        $lang = lang_wanted();
    }
    return $lang === '' ? lang_from_browser() : $lang;
}

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (!consent_given()) {
        $GLOBALS['_SESSION'] = [];
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
    unset($_SESSION['ot'][$sent]);
    return true;
}

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

function card_supports_sodium(): bool
{
    return function_exists('sodium_crypto_sign_keypair');
}

function authorized_file(): string
{
    return SW::$dataDir . '/authorized_keys.yaml';
}

function authorized_load(): array
{
    $file = authorized_file();
    if (!is_file($file)) {
        return [];
    }
    $set = [];
    foreach (preg_split('/\r?\n/', (string) file_get_contents($file)) as $line) {
        if (preg_match('/["\x27]?([0-9a-fA-F]{64,128})["\x27]?\s*$/', trim($line), $m) === 1
            && strpos(trim($line), '-') === 0) {
            $set[strtolower($m[1])] = true;
        }
    }
    return $set;
}

function authorized_contains(string $pkHex): bool
{
    return isset(authorized_load()[strtolower($pkHex)]);
}

function authorized_count(): int
{
    return count(authorized_load());
}

function test_keys_file(): string
{
    return SW::$dataDir . '/test_keys.list';
}

function test_keys_load(): array
{
    $file = test_keys_file();
    if (!is_file($file)) {
        return [];
    }
    $out = [];
    foreach (preg_split('/\r?\n/', (string) file_get_contents($file)) as $line) {
        $hex = strtolower(trim($line));
        if (preg_match('/^[0-9a-f]{64,128}$/', $hex) === 1) {
            $out[] = $hex;
        }
    }
    return $out;
}

function test_key_add(string $pkHex): void
{
    @file_put_contents(test_keys_file(), strtolower($pkHex) . "\n", FILE_APPEND | LOCK_EX);
    @chmod(test_keys_file(), 0600);
}

function authorized_count_stable(): int
{
    $set = authorized_load();
    foreach (test_keys_load() as $hex) {
        unset($set[$hex]);
    }
    return count($set);
}

function authorized_remove(array $pkHexList): int
{
    $set = authorized_load();
    $removed = 0;
    foreach ($pkHexList as $hex) {
        $hex = strtolower(trim($hex));
        if (isset($set[$hex])) {
            unset($set[$hex]);
            $removed++;
        }
    }
    if ($removed > 0) {
        authorized_write($set, 'cleanup');
    }
    return $removed;
}

function authorized_add(array $pkHexList, string $source): int
{
    $set = authorized_load();
    $added = 0;
    foreach ($pkHexList as $hex) {
        $hex = strtolower(trim($hex));
        if (preg_match('/^[0-9a-f]{64,128}$/', $hex) === 1 && !isset($set[$hex])) {
            $set[$hex] = true;
            $added++;
        }
    }
    authorized_write($set, $source);
    return $added;
}

function authorized_write(array $set, string $source): void
{
    $y = "buergerabstimmung_authorized_keys:\n";
    $y .= "  hinweis: \"Oeffentliche Schluessel autorisierter Ausweise. Nur diese koennen sich anmelden.\"\n";
    $y .= "  aktualisiert: \"" . Clock::nowStr() . "\"\n";
    $y .= "  quelle: \"" . str_replace('"', '', $source) . "\"\n";
    $y .= "  schluessel:\n";
    if ($set === []) {
        $y = substr($y, 0, -1) . " []\n";
    }
    foreach (array_keys($set) as $hex) {
        $y .= "    - \"" . $hex . "\"\n";
    }
    @file_put_contents(authorized_file(), $y, LOCK_EX);
    @chmod(authorized_file(), 0640);
}

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

function card_identity(array $card): string
{
    return bin2hex($card['pk']);
}

const SW_SLOT_SECONDS = 300;
const SW_AUTH_SLOTS = 2;

function time_slot(): int
{
    return intdiv(Clock::now()->getTimestamp(), SW_SLOT_SECONDS);
}

function card_seal(array $card, string $action): string
{
    $payload = json_encode(['a' => $action, 'slot' => time_slot(), 'n' => bin2hex(random_bytes(8))]);
    if (card_supports_sodium()) {
        return sodium_crypto_sign($payload, $card['secret']);
    }

    return $payload . '.' . hash('sha256', 'seal|' . $card['pk'] . '|' . $payload);
}

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

    $slot = $_SESSION['auth_slot'] ?? null;
    if (!test_mode() && is_int($slot) && (time_slot() - $slot) >= SW_AUTH_SLOTS) {
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

function card_confirm_ok(array $user, ?array $card, string $action): bool
{
    if (test_mode()) {
        return true;
    }
    if ($card === null) {
        return false;
    }
    if (!hash_equals((string) $user['pseudonym_hash'], card_identity($card))) {
        return false;
    }
    return card_open($card['pk'], card_seal($card, $action), $action);
}

function require_card(array $user): void
{
    $action = 'confirm:' . SW::$path;
    if (!card_confirm_ok($user, card_load(), $action)) {
        log_line('SECURITY', 'card_confirm_failed', []);
        flash('error', 'flash.card_required');
        redirect('/auth');
    }
}

function short_id(array $user): string
{
    return strtoupper(substr((string) $user['pseudonym_hash'], 0, 8));
}

const SW_TITLE_MIN = 8;
const SW_TITLE_MAX = 120;
const SW_GOAL_MIN = 10;
const SW_GOAL_MAX = 500;
const SW_REASONING_MIN = 10;
const SW_REASONING_MAX = 4000;

const SW_CATEGORIES_FILE = 'categories.json';
const SW_REGIONS_FILE = 'regions.json';
const SW_LIST_MAX_BYTES = 262144;   // Deckel für Datei und Abruf
const SW_MAX_CATEGORIES = 60;
const SW_MAX_LAENDER = 24;
const SW_MAX_KREISE = 600;
const SW_SYNC_MAX_NEW = 20;         // höchstens so viele neue Werte je Abgleich
const SW_SYNC_EVERY = 21600;        // frühestens alle 6 Stunden

function sw_list_text($value, int $maxChars): ?string
{
    if (!is_string($value) || strlen($value) > 400) {
        return null;
    }
    if (!mb_check_encoding($value, 'UTF-8')) {
        return null;
    }
    $text = trim((string) preg_replace('/\s+/u', ' ', $value));
    $len = mb_strlen($text);
    if ($len < 2 || $len > $maxChars) {
        return null;
    }
    // Nur lateinische Schrift: verhindert nachgeahmte Namen aus fremden Alphabeten.
    return preg_match('/^[\p{Latin}\p{N} .,&()\/\'\-]+$/u', $text) === 1 ? $text : null;
}

function sw_categories_clean(array $raw): array
{
    $out = [];
    $seen = [];
    foreach ($raw as $row) {
        if (!is_array($row) || count($out) >= SW_MAX_CATEGORIES) {
            continue;
        }
        $slug = isset($row['slug']) && is_string($row['slug']) ? trim($row['slug']) : '';
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/', $slug) !== 1 || isset($seen[$slug])) {
            continue;
        }
        $de = sw_list_text($row['de'] ?? null, 60);
        $en = sw_list_text($row['en'] ?? null, 60);
        if ($de === null || $en === null) {
            continue;
        }
        $seen[$slug] = true;
        $out[] = [$slug, $de, $en];
    }
    return $out;
}

function sw_regions_clean(array $raw): array
{
    $out = [];
    $kreise = 0;
    foreach ($raw as $land => $list) {
        $name = sw_list_text(is_string($land) ? $land : null, 60);
        if ($name === null || !is_array($list) || isset($out[$name]) || count($out) >= SW_MAX_LAENDER) {
            continue;
        }
        $clean = [];
        $seen = [];
        foreach ($list as $entry) {
            if ($kreise >= SW_MAX_KREISE) {
                break;
            }
            $kreis = sw_list_text($entry, 80);
            if ($kreis === null || isset($seen[$kreis])) {
                continue;
            }
            $seen[$kreis] = true;
            $clean[] = $kreis;
            $kreise++;
        }
        $out[$name] = $clean;
    }
    return $out;
}

function sw_list_path(string $file): string
{
    return __DIR__ . '/' . $file;
}

function sw_list_load(string $file, string $cleaner, array $fallback): array
{
    $raw = @file_get_contents(sw_list_path($file), false, null, 0, SW_LIST_MAX_BYTES + 1);
    if ($raw === false || $raw === '') {
        return $fallback;
    }
    if (strlen($raw) > SW_LIST_MAX_BYTES) {
        log_line('DATA', 'list_too_big', ['file' => $file]);
        return $fallback;
    }
    $data = json_decode($raw, true, 6);
    if (!is_array($data)) {
        log_line('DATA', 'list_unreadable', ['file' => $file]);
        return $fallback;
    }
    $clean = $cleaner($data);
    if ($clean === []) {
        log_line('DATA', 'list_empty', ['file' => $file]);
        return $fallback;
    }
    return $clean;
}

function sw_categories(): array
{
    if (!isset(SW::$lists['categories'])) {
        SW::$lists['categories'] = sw_list_load(SW_CATEGORIES_FILE, 'sw_categories_clean', SW_CATEGORIES);
    }
    return SW::$lists['categories'];
}

function sw_regions(): array
{
    if (!isset(SW::$lists['regions'])) {
        SW::$lists['regions'] = sw_list_load(SW_REGIONS_FILE, 'sw_regions_clean', SW_REGIONS);
    }
    return SW::$lists['regions'];
}

function sw_list_write(string $file, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > SW_LIST_MAX_BYTES) {
        return false;
    }
    $path = sw_list_path($file);
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        log_line('DATA', 'list_write_failed', ['file' => $file]);
        return false;
    }
    return true;
}

function sw_list_fetch(string $url): ?array
{
    if (preg_match('#^https://raw\.githubusercontent\.com/[A-Za-z0-9._~/-]{1,200}$#', $url) !== 1) {
        log_line('DATA', 'sync_url_rejected', []);
        return null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 8,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'ignore_errors'   => true,
            'header'          => "Accept: application/json\r\nUser-Agent: buergerabstimmung\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx, 0, SW_LIST_MAX_BYTES + 1);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/[\d.]+\s+(\d{3})#', $line, $m) === 1) {
            $status = (int) $m[1];
        }
    }
    if ($body === false || $status !== 200 || strlen($body) > SW_LIST_MAX_BYTES) {
        log_line('DATA', 'sync_fetch_failed', ['status' => $status]);
        return null;
    }
    $data = json_decode($body, true, 6);
    return is_array($data) ? $data : null;
}

function sw_categories_new(array $local, array $wanted): array
{
    $have = [];
    foreach ($local as $row) {
        $have[$row[0]] = true;
    }
    $added = [];
    foreach ($wanted as $row) {
        if (isset($have[$row[0]]) || count($added) >= SW_SYNC_MAX_NEW
            || count($local) + count($added) >= SW_MAX_CATEGORIES) {
            continue;
        }
        $have[$row[0]] = true;
        $added[] = $row;
    }
    return $added;
}

function sw_regions_merge(array $local, array $wanted): array
{
    $kreise = array_sum(array_map('count', $local));
    $added = [];
    foreach ($wanted as $land => $list) {
        if (count($added) >= SW_SYNC_MAX_NEW) {
            break;
        }
        if (!isset($local[$land])) {
            if (count($local) >= SW_MAX_LAENDER) {
                continue;
            }
            $local[$land] = [];
            $added[] = ['land' => $land];
        }
        foreach ($list as $kreis) {
            if (count($added) >= SW_SYNC_MAX_NEW || $kreise >= SW_MAX_KREISE) {
                break;
            }
            if (in_array($kreis, $local[$land], true)) {
                continue;
            }
            $local[$land][] = $kreis;
            $kreise++;
            $added[] = ['land' => $land, 'kreis' => $kreis];
        }
    }
    return ['list' => $local, 'added' => $added];
}

function sw_sync_categories(string $url): int
{
    $remote = sw_list_fetch($url);
    if ($remote === null) {
        return 0;
    }
    $local = [];
    foreach (categories() as $row) {
        $local[] = [(string) $row['slug'], (string) $row['name_de'], (string) $row['name_en']];
    }
    if ($local === []) {
        $local = sw_categories();
    }
    $added = sw_categories_new($local, sw_categories_clean($remote));
    if ($added === []) {
        return 0;
    }
    $merged = array_merge($local, $added);
    $file = [];
    foreach ($merged as $row) {
        $file[] = ['slug' => $row[0], 'de' => $row[1], 'en' => $row[2]];
    }
    if (!sw_list_write(SW_CATEGORIES_FILE, $file)) {
        return 0;
    }
    SW::$lists['categories'] = $merged;
    SW::$db->tx(function () use ($added): void {
        $next = (int) SW::$db->val('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM categories');
        foreach ($added as $i => $row) {
            SW::$db->run(
                'INSERT OR IGNORE INTO categories (slug, name_de, name_en, sort_order) VALUES (?, ?, ?, ?)',
                [$row[0], $row[1], $row[2], $next + $i]
            );
        }
    });
    foreach ($added as $row) {
        log_line('DATA', 'category_added', ['slug' => $row[0], 'name' => $row[1]]);
    }
    return count($added);
}

function sw_sync_regions(string $url): int
{
    $remote = sw_list_fetch($url);
    if ($remote === null) {
        return 0;
    }
    $merge = sw_regions_merge(sw_regions(), sw_regions_clean($remote));
    if ($merge['added'] === [] || !sw_list_write(SW_REGIONS_FILE, $merge['list'])) {
        return 0;
    }
    SW::$lists['regions'] = $merge['list'];
    foreach ($merge['added'] as $entry) {
        log_line('DATA', 'region_added', $entry);
    }
    return count($merge['added']);
}

function sw_sync_lists(bool $force = false): int
{
    if (!$force) {
        $now = Clock::now()->getTimestamp();
        $last = (int) (SW::$db->val("SELECT v FROM schema_info WHERE k = 'last_list_sync'") ?? 0);
        if (($now - $last) < SW_SYNC_EVERY) {
            return 0;
        }
        SW::$db->run(
            "INSERT INTO schema_info (k, v) VALUES ('last_list_sync', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v",
            [(string) $now]
        );
    }
    $added = 0;
    $catUrl = trim((string) SW::$cfg['categories_url']);
    $regUrl = trim((string) SW::$cfg['regions_url']);
    if ($catUrl !== '') {
        $added += sw_sync_categories($catUrl);
    }
    if ($regUrl !== '') {
        $added += sw_sync_regions($regUrl);
    }
    return $added;
}

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

function scope_decode(string $value): ?array
{
    if ($value === 'de') {
        return ['bund', null];
    }
    if (strpos($value, 'bl:') === 0) {
        $land = substr($value, 3);
        return isset(sw_regions()[$land]) ? ['bundesland', $land] : null;
    }
    if (strpos($value, 'kr:') === 0) {
        $parts = explode(':', substr($value, 3), 2);
        if (count($parts) === 2 && isset(sw_regions()[$parts[0]])
            && in_array($parts[1], sw_regions()[$parts[0]], true)) {
            return ['landkreis', $parts[1]];
        }
        return null;
    }
    return null;
}

function fav_to_gebiet(string $ref): ?string
{
    if ($ref === 'bund') {
        return 'de';
    }
    $parts = explode(':', $ref, 2);
    if (count($parts) !== 2) {
        return null;
    }
    if ($parts[0] === 'bundesland' && isset(sw_regions()[$parts[1]])) {
        return 'bl:' . $parts[1];
    }
    if ($parts[0] === 'landkreis') {
        foreach (sw_regions() as $land => $kreise) {
            if (in_array($parts[1], $kreise, true)) {
                return 'kr:' . $land . ':' . $parts[1];
            }
        }
    }
    return null;
}

function scope_picker(string $name, string $selected, bool $withAll): string
{
    $html = '<select name="' . e($name) . '" data-scope-native'
        . ' data-l-de="' . e(t('scope.bund')) . '"'
        . ' data-l-bl="' . e(t('scope.bundesland')) . '"'
        . ' data-l-kr="' . e(t('scope.landkreis')) . '"'
        . ' data-l-pick="' . e(t('topic.f_choose')) . '"'
        . ' data-l-all="' . e(t('topics.filter_all')) . '">';
    if ($withAll) {
        $html .= '<option value="">' . e(t('topics.filter_all')) . '</option>';
    }
    $html .= '<option value="de"' . ($selected === 'de' ? ' selected' : '') . '>' . e(t('scope.bund')) . '</option>';
    foreach (sw_regions() as $land => $kreise) {
        $value = 'bl:' . $land;
        $html .= '<optgroup label="' . e($land) . '">'
            . '<option value="' . e($value) . '"' . ($selected === $value ? ' selected' : '') . '>'
            . e($land) . ' — ' . e(t('scope.whole')) . '</option>';
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

function sw_seed_categories(): void
{
    if ((int) SW::$db->val('SELECT COUNT(*) FROM categories') > 0) {
        return;
    }
    SW::$db->tx(function (): void {
        foreach (sw_categories() as $i => $row) {
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

function topic_create(int $userId, string $title, string $goal, string $reasoning, int $categoryId, string $scopeLevel, ?string $scopeName, string $endMode, ?string $endDate, ?int $endTarget): int
{
    if (topic_has_posted_today($userId)) {
        throw new DomainException('flash.topic_daily_limit');
    }
    try {
        SW::$db->run(
            'INSERT INTO topics (author_id, title, goal, reasoning, category_id,
                                 scope_level, scope_name, end_mode, end_date, end_target,
                                 created_at, created_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $title, $goal, $reasoning, $categoryId,
             $scopeLevel, $scopeName, $endMode, $endDate, $endTarget,
             Clock::nowStr(), Clock::localDate()]
        );
    } catch (PDOException $e) {
        throw new DomainException('flash.topic_daily_limit');
    }
    return SW::$db->lastId();
}

function topic_update(int $topicId, int $userId, string $title, string $goal, string $reasoning, int $categoryId, string $scopeLevel, ?string $scopeName, string $endMode, ?string $endDate, ?int $endTarget): void
{
    $topic = SW::$db->one('SELECT * FROM topics WHERE id = ?', [$topicId]);
    if ($topic === null || (int) $topic['author_id'] !== $userId
        || in_array((string) $topic['status'], ['removed', 'archived'], true)) {
        throw new DomainException('flash.not_author');
    }
    SW::$db->run(
        'UPDATE topics SET title = ?, goal = ?, reasoning = ?, category_id = ?,
                           scope_level = ?, scope_name = ?, end_mode = ?, end_date = ?, end_target = ?
         WHERE id = ?',
        [$title, $goal, $reasoning, $categoryId, $scopeLevel, $scopeName,
         $endMode, $endDate, $endTarget, $topicId]
    );
}

function topic_archive(int $topicId, int $userId): void
{
    $topic = SW::$db->one('SELECT author_id, status FROM topics WHERE id = ?', [$topicId]);
    if ($topic === null || (int) $topic['author_id'] !== $userId
        || in_array((string) $topic['status'], ['removed', 'archived'], true)) {
        throw new DomainException('flash.not_author');
    }
    if (topic_has_votes($topicId)) {
        throw new DomainException('flash.topic_locked');
    }
    SW::$db->run("UPDATE topics SET status = 'archived' WHERE id = ?", [$topicId]);
    log_line('INFO', 'topic_archived', ['topic' => $topicId]);
}

function topic_has_votes(int $topicId): bool
{
    return (int) SW::$db->val('SELECT COUNT(*) FROM votes WHERE topic_id = ?', [$topicId]) > 0;
}

function topic_title_words(string $title): array
{
    $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title));
    $words = [];
    foreach (preg_split('/\s+/', (string) $clean) as $word) {
        if (mb_strlen($word) >= 4) {
            $words[$word] = true;
        }
    }
    return array_slice(array_keys($words), 0, 8);
}

function topics_similar(string $title, ?int $excludeId = null, int $limit = 5): array
{
    $words = topic_title_words($title);
    if ($words === []) {
        return [];
    }
    $where = [];
    $args = [];
    foreach ($words as $word) {
        $where[] = 'lower(t.title) LIKE ?';
        $args[] = '%' . $word . '%';
    }
    $sql = 'SELECT t.id, t.title FROM topics t WHERE t.status IN (\'active\', \'closed\') AND (' . implode(' OR ', $where) . ')';
    if ($excludeId !== null) {
        $sql .= ' AND t.id != ?';
        $args[] = $excludeId;
    }
    $sql .= ' ORDER BY t.created_at DESC LIMIT 60';
    $scored = [];
    foreach (SW::$db->all($sql, $args) as $row) {
        $other = topic_title_words((string) $row['title']);
        $shared = count(array_intersect($words, $other));
        if ($shared === 0) {
            continue;
        }
        $row['score'] = $shared / max(1, min(count($words), count($other)));
        $scored[] = $row;
    }
    usort($scored, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });
    return array_slice(array_filter($scored, static function (array $r): bool {
        return $r['score'] >= 0.5;
    }), 0, $limit);
}

function parse_topic_end(): ?array
{
    $useDate = isset($_POST['end_by_date']);
    $useTarget = isset($_POST['end_by_target']);
    if (!$useDate && !$useTarget) {
        return null;
    }
    $date = null;
    $target = null;

    if ($useDate) {
        $raw = post_str('end_date', 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        $max = substr(Clock::addDaysStr(Clock::nowStr(), 365), 0, 10);
        if ($raw <= Clock::localDate() || $raw > $max) {
            return null;
        }
        $date = $raw;
    }
    if ($useTarget) {
        $value = post_int('end_value');
        $unit = post_str('end_unit', 10);
        if ($value === null || $value < 1 || !in_array($unit, ['count', 'percent'], true)) {
            return null;
        }
        if ($unit === 'percent') {
            if ($value > 100) {
                return null;
            }
            $users = (int) SW::$db->val('SELECT COUNT(*) FROM users WHERE is_system = 0');
            $value = (int) ceil($users * $value / 100);
        }
        if ($value > 100000000) {
            return null;
        }
        $target = max(10, $value);
    }
    $mode = $date !== null && $target !== null ? 'both' : ($date !== null ? 'date' : 'count');
    return [$mode, $date, $target];
}

const SW_TOPIC_SELECT = "
    SELECT t.*, c.slug AS category_slug, c.name_de, c.name_en,
           (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'for')     AS votes_for,
           (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'against') AS votes_against,
           (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'neutral')  AS votes_neutral
    FROM topics t
    JOIN categories c ON c.id = t.category_id";

function topics_list(array $filters, int $page, int $perPage, ?int $userId): array
{
    $where = ["t.status IN ('active','closed')"];
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

    $netExpr = "((SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'for')"
        . " - (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'against'))";
    $sortMode = $filters['sort'] ?? 'net';
    if ($sortMode === 'new') {
        $order = ' ORDER BY t.created_at DESC';
    } elseif ($sortMode === 'top') {
        $order = ' ORDER BY (votes_for + votes_against) DESC, t.created_at DESC';
    } elseif (!empty($filters['q'])) {
        $params[':qp'] = addcslashes((string) $filters['q'], '%_\\') . '%';
        $order = " ORDER BY CASE WHEN t.title LIKE :qp ESCAPE '\\' THEN 0 ELSE 1 END, " . $netExpr . ' DESC, t.created_at DESC';
    } else {
        $order = ' ORDER BY ' . $netExpr . ' DESC, t.created_at DESC';
    }
    $cols = "t.*, c.slug AS category_slug, c.name_de, c.name_en,
        (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'for')     AS votes_for,
        (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'against') AS votes_against";
    $select = 'SELECT ' . $cols . ' FROM topics t JOIN categories c ON c.id = t.category_id';
    $params[':limit'] = $perPage;
    $params[':offset'] = max(0, ($page - 1) * $perPage);
    $rows = SW::$db->all($select . $whereSql . $order . ' LIMIT :limit OFFSET :offset', $params);
    if ($userId !== null) {
        $pk = user_pk($userId);
        foreach ($rows as $i => $row) {
            $tag = vote_tag((int) $row['id'], $pk);
            $rows[$i]['my_choice'] = SW::$db->val('SELECT choice FROM votes WHERE topic_id = ? AND voter_tag = ?', [(int) $row['id'], $tag]);
        }
    }
    return ['rows' => $rows, 'total' => $total];
}

function topic_find(int $id): ?array
{
    return SW::$db->one(SW_TOPIC_SELECT . ' WHERE t.id = ?', [$id]);
}

function topic_user_vote(int $topicId, int $userId): ?string
{
    $row = topic_user_vote_row($topicId, $userId);
    return $row === null ? null : (string) $row['choice'];
}

function topic_user_vote_row(int $topicId, int $userId): ?array
{
    $tag = vote_tag($topicId, user_pk($userId));
    $row = SW::$db->one('SELECT choice, created_at FROM votes WHERE topic_id = ? AND voter_tag = ?', [$topicId, $tag]);
    if ($row === null) {
        return null;
    }
    $row['locked'] = Clock::nowStr() >= Clock::addHoursStr((string) $row['created_at'], SW_VOTE_CHANGE_HOURS);
    return $row;
}

function topics_by_author(int $userId): array
{
    return SW::$db->all(SW_TOPIC_SELECT . ' WHERE t.author_id = ? ORDER BY t.created_at DESC', [$userId]);
}

function topics_voted_by(int $userId): array
{
    $pk = user_pk($userId);
    $out = [];
    foreach (SW::$db->all('SELECT id, title, status FROM topics ORDER BY id DESC') as $topic) {
        $tag = vote_tag((int) $topic['id'], $pk);
        $vote = SW::$db->one('SELECT choice, created_at FROM votes WHERE topic_id = ? AND voter_tag = ?', [(int) $topic['id'], $tag]);
        if ($vote !== null) {
            $topic['my_choice'] = (string) $vote['choice'];
            $topic['voted_at'] = (string) $vote['created_at'];
            $topic['locked'] = Clock::nowStr() >= Clock::addHoursStr((string) $vote['created_at'], SW_VOTE_CHANGE_HOURS);
            $out[] = $topic;
        }
    }
    return $out;
}

function site_stats(): array
{
    return [
        'topics' => (int) SW::$db->val("SELECT COUNT(*) FROM topics WHERE status = 'active'"),
        'votes'  => (int) SW::$db->val('SELECT COUNT(*) FROM votes'),
        'users'  => (int) SW::$db->val('SELECT COUNT(*) FROM users WHERE is_system = 0'),
    ];
}

const SW_VOTE_CHANGE_HOURS = 24;

function vote_tag(int $topicId, string $pkHex): string
{
    return sw_hmac('vote|' . $topicId . '|' . $pkHex);
}

function user_pk(int $userId): string
{
    return (string) SW::$db->val('SELECT pseudonym_hash FROM users WHERE id = ?', [$userId]);
}

function topic_close_if_due(array $topic): string
{
    if ($topic['status'] !== 'active') {
        return (string) $topic['status'];
    }

    $close = false;
    if ($topic['end_date'] !== null && Clock::localDate() > (string) $topic['end_date']) {
        $close = true;
    }
    if ($topic['end_target'] !== null) {
        $total = (int) SW::$db->val("SELECT COUNT(*) FROM votes WHERE topic_id = ? AND choice IN ('for','against')", [(int) $topic['id']]);
        if ($total >= (int) $topic['end_target']) {
            $close = true;
        }
    }
    if ($close) {
        SW::$db->run("UPDATE topics SET status = 'closed' WHERE id = ? AND status = 'active'", [(int) $topic['id']]);
        return 'closed';
    }
    return 'active';
}

function vote_cast(int $userId, int $topicId, string $choice): void
{
    if (!in_array($choice, ['for', 'against', 'neutral'], true)) {
        throw new DomainException('flash.invalid_input');
    }
    $topic = SW::$db->one('SELECT * FROM topics WHERE id = ?', [$topicId]);
    if ($topic === null || topic_close_if_due($topic) !== 'active') {
        throw new DomainException('flash.topic_not_votable');
    }
    $tag = vote_tag($topicId, user_pk($userId));
    $existing = SW::$db->one('SELECT choice, created_at FROM votes WHERE topic_id = ? AND voter_tag = ?', [$topicId, $tag]);
    if ($existing !== null && Clock::nowStr() >= Clock::addHoursStr((string) $existing['created_at'], SW_VOTE_CHANGE_HOURS)) {
        throw new DomainException('flash.vote_locked');
    }
    $now = Clock::nowStr();
    SW::$db->run(
        'INSERT INTO votes (topic_id, voter_tag, choice, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
         ON CONFLICT(topic_id, voter_tag) DO UPDATE SET choice = excluded.choice, updated_at = excluded.updated_at',
        [$topicId, $tag, $choice, $now, $now]
    );
    if ($existing === null) {
        topic_close_if_due(array_merge($topic, ['status' => 'active']));
    }
}

function fav_valid(string $kind, string $ref): bool
{
    if ($kind === 'topic') {
        return preg_match('/^\d{1,10}$/', $ref) === 1
            && null !== SW::$db->one('SELECT 1 FROM topics WHERE id = ?', [(int) $ref]);
    }
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
            return isset(sw_regions()[$parts[1]]);
        }
        if ($parts[0] === 'landkreis') {
            foreach (sw_regions() as $kreise) {
                if (in_array($parts[1], $kreise, true)) {
                    return true;
                }
            }
        }
        return false;
    }
    return false;
}

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
        'SELECT f.kind, f.ref, c.name_de, c.name_en, t.title AS topic_title, t.status AS topic_status
         FROM favorites f
         LEFT JOIN categories c ON f.kind = \'category\' AND c.slug = f.ref
         LEFT JOIN topics t ON f.kind = \'topic\' AND t.id = CAST(f.ref AS INTEGER)
         WHERE f.user_id = ?
         ORDER BY f.kind, f.ref',
        [$userId]
    );
}

const SW_LAWS = [
    'stgb-130-1' => [
        'norm' => '§ 130 Abs. 1 StGB', 'titel' => 'Volksverhetzung',
        'schlagworte' => 'volksverhetzung hass hetze aufstacheln menschenwürde gruppe bevölkerung',
        'text' => 'Wer in einer Weise, die geeignet ist, den öffentlichen Frieden zu stören, 1. gegen eine nationale, rassische, religiöse oder durch ihre ethnische Herkunft bestimmte Gruppe, gegen Teile der Bevölkerung oder gegen einen Einzelnen wegen dessen Zugehörigkeit zu einer vorbezeichneten Gruppe oder zu einem Teil der Bevölkerung zum Hass aufstachelt, zu Gewalt- oder Willkürmaßnahmen auffordert oder 2. die Menschenwürde anderer dadurch angreift, dass er eine vorbezeichnete Gruppe, Teile der Bevölkerung oder einen Einzelnen wegen dessen Zugehörigkeit zu einer vorbezeichneten Gruppe oder zu einem Teil der Bevölkerung beschimpft, böswillig verächtlich macht oder verleumdet, wird mit Freiheitsstrafe von drei Monaten bis zu fünf Jahren bestraft.',
    ],
    'stgb-86a-1' => [
        'norm' => '§ 86a Abs. 1 StGB', 'titel' => 'Verwenden von Kennzeichen verfassungswidriger und terroristischer Organisationen',
        'schlagworte' => 'kennzeichen symbole verfassungswidrig hakenkreuz parole organisation',
        'text' => 'Mit Freiheitsstrafe bis zu drei Jahren oder mit Geldstrafe wird bestraft, wer 1. im Inland Kennzeichen einer der in § 86 Abs. 1 Nr. 1, 2 und 4 oder Absatz 2 bezeichneten Parteien oder Vereinigungen verbreitet oder öffentlich, in einer Versammlung oder in einem von ihm verbreiteten Inhalt (§ 11 Absatz 3) verwendet oder 2. einen Inhalt (§ 11 Absatz 3), der ein derartiges Kennzeichen darstellt oder enthält, zur Verbreitung oder Verwendung im Inland oder Ausland in der in Nummer 1 bezeichneten Art und Weise herstellt, vorrätig hält, einführt oder ausführt.',
    ],
    'stgb-111-1' => [
        'norm' => '§ 111 Abs. 1 StGB', 'titel' => 'Öffentliche Aufforderung zu Straftaten',
        'schlagworte' => 'aufforderung straftat aufruf gewalt anstiftung',
        'text' => 'Wer öffentlich, in einer Versammlung oder durch Verbreiten eines Inhalts (§ 11 Absatz 3) zu einer rechtswidrigen Tat auffordert, wird wie ein Anstifter (§ 26) bestraft.',
    ],
    'stgb-185' => [
        'norm' => '§ 185 StGB', 'titel' => 'Beleidigung',
        'schlagworte' => 'beleidigung ehre schmähung',
        'text' => 'Die Beleidigung wird mit Freiheitsstrafe bis zu einem Jahr oder mit Geldstrafe und, wenn die Beleidigung öffentlich, in einer Versammlung, durch Verbreiten eines Inhalts (§ 11 Absatz 3) oder mittels einer Tätlichkeit begangen wird, mit Freiheitsstrafe bis zu zwei Jahren oder mit Geldstrafe bestraft.',
    ],
    'stgb-186' => [
        'norm' => '§ 186 StGB', 'titel' => 'Üble Nachrede',
        'schlagworte' => 'üble nachrede tatsache herabwürdigen rufschädigung',
        'text' => 'Wer in Beziehung auf einen anderen eine Tatsache behauptet oder verbreitet, welche denselben verächtlich zu machen oder in der öffentlichen Meinung herabzuwürdigen geeignet ist, wird, wenn nicht diese Tatsache erweislich wahr ist, mit Freiheitsstrafe bis zu einem Jahr oder mit Geldstrafe und, wenn die Tat öffentlich, in einer Versammlung oder durch Verbreiten eines Inhalts (§ 11 Absatz 3) begangen ist, mit Freiheitsstrafe bis zu zwei Jahren oder mit Geldstrafe bestraft.',
    ],
    'stgb-187' => [
        'norm' => '§ 187 StGB', 'titel' => 'Verleumdung',
        'schlagworte' => 'verleumdung unwahre tatsache lüge kredit',
        'text' => 'Wer wider besseres Wissen in Beziehung auf einen anderen eine unwahre Tatsache behauptet oder verbreitet, welche denselben verächtlich zu machen oder in der öffentlichen Meinung herabzuwürdigen oder dessen Kredit zu gefährden geeignet ist, wird mit Freiheitsstrafe bis zu zwei Jahren oder mit Geldstrafe und, wenn die Tat öffentlich, in einer Versammlung oder durch Verbreiten eines Inhalts (§ 11 Absatz 3) begangen ist, mit Freiheitsstrafe bis zu fünf Jahren oder mit Geldstrafe bestraft.',
    ],
    'stgb-240-1' => [
        'norm' => '§ 240 Abs. 1 StGB', 'titel' => 'Nötigung',
        'schlagworte' => 'nötigung zwang drohung übel',
        'text' => 'Wer einen Menschen rechtswidrig mit Gewalt oder durch Drohung mit einem empfindlichen Übel zu einer Handlung, Duldung oder Unterlassung nötigt, wird mit Freiheitsstrafe bis zu drei Jahren oder mit Geldstrafe bestraft.',
    ],
    'stgb-241-1' => [
        'norm' => '§ 241 Abs. 1 StGB', 'titel' => 'Bedrohung',
        'schlagworte' => 'bedrohung drohung verbrechen gewalt',
        'text' => 'Wer einen Menschen mit der Begehung einer gegen ihn oder eine ihm nahestehende Person gerichteten rechtswidrigen Tat gegen die sexuelle Selbstbestimmung, die körperliche Unversehrtheit, die persönliche Freiheit oder gegen eine Sache von bedeutendem Wert bedroht, wird mit Freiheitsstrafe bis zu einem Jahr oder mit Geldstrafe bestraft.',
    ],
    'stgb-126a-1' => [
        'norm' => '§ 126a Abs. 1 StGB', 'titel' => 'Gefährdendes Verbreiten personenbezogener Daten',
        'schlagworte' => 'doxxing personenbezogene daten adresse veröffentlichen gefährdung',
        'text' => 'Wer personenbezogene Daten einer anderen Person in einer Art und Weise, die geeignet ist, diese Person oder eine ihr nahestehende Person der Gefahr 1. eines gegen sie gerichteten Verbrechens oder 2. einer sonstigen gegen sie gerichteten rechtswidrigen Tat gegen die körperliche Unversehrtheit, die persönliche Freiheit oder gegen eine Sache von bedeutendem Wert auszusetzen, öffentlich zugänglich macht, wird mit Freiheitsstrafe bis zu zwei Jahren oder mit Geldstrafe bestraft.',
    ],
];

function law_search(string $q): array
{
    $q = mb_strtolower(trim($q));
    if ($q === '') {
        return [];
    }
    $hits = [];
    foreach (SW_LAWS as $id => $law) {
        $haystack = mb_strtolower($law['norm'] . ' ' . $law['titel'] . ' ' . $law['schlagworte'] . ' ' . $law['text']);
        if (mb_strpos($haystack, $q) !== false || mb_strpos(str_replace(['§', ' '], '', $haystack), str_replace(['§', ' '], '', $q)) !== false) {
            $hits[$id] = $law;
        }
    }
    return $hits;
}

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

function report_create(int $topicId, int $reporterId, string $lawId): int
{
    if (!isset(SW_LAWS[$lawId])) {
        throw new DomainException('flash.report_no_law');
    }
    $topic = SW::$db->one('SELECT id, author_id, status FROM topics WHERE id = ?', [$topicId]);
    if ($topic === null || $topic['status'] !== 'active') {
        throw new DomainException('flash.topic_not_reportable');
    }
    return SW::$db->tx(function () use ($topicId, $reporterId, $lawId, $topic): int {
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
            [$topicId, $reporterId, $lawId, null,
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

function maintenance_tick(): void
{
    SW::$db->run(
        "UPDATE topics SET status = 'closed'
         WHERE status = 'active' AND end_date IS NOT NULL AND end_date < ?",
        [Clock::localDate()]
    );
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

function account_delete(int $userId): void
{
    SW::$db->tx(function () use ($userId): void {
        $systemId = (int) SW::$db->val('SELECT id FROM users WHERE is_system = 1 LIMIT 1');
        SW::$db->run('UPDATE topics SET author_id = ? WHERE author_id = ?', [$systemId, $userId]);
        SW::$db->run('DELETE FROM users WHERE id = ?', [$userId]);
    });
}

const SW_DE = [
    'app.tagline' => 'Digitale Bürgerbeteiligung',
    'banner.official' => 'Keine offizielle Seite der Bundesregierung oder einer Behörde.',
    'a11y.skip' => 'Zum Inhalt springen',
    'nav.topics' => 'Themen',
    'nav.jury' => 'Jury',
    'auth.login' => 'Ausweis auflegen',
    'auth.logout' => 'Abmelden',
    'common.date_format' => 'd.m.Y',
    'common.datetime_format' => 'd.m.Y, H:i',
    'common.back_home' => 'Zur Startseite',

    'topics.filter_category' => 'Kategorie',
        'topics.filter_all' => 'Alle',
    'topics.search' => 'Suche',
    'topics.sort_net' => 'Größte Zustimmung',
    'topics.sort' => 'Sortierung',
    'topics.sort_new' => 'Neueste',
    'topics.sort_top' => 'Meiste Stimmen',
    'topics.apply' => 'Filtern',
    'topics.none' => 'Keine Themen gefunden.',
    'topics.prev' => 'Zurück',
    'topics.next' => 'Weiter',
    'topics.page_of' => 'Seite {p} von {n}',

    'scope.bund' => 'Deutschland',
    'scope.bundesland' => 'Bundesland',
    'scope.landkreis' => 'Landkreis',

    'topic.goal_label' => 'Ziel',
    'topic.reasoning_label' => 'Begründung',
    'topic.report_link' => 'Inhalt melden',
    'topic.report_open' => 'Gemeinschaftsprüfung läuft.',
    'topic.remember' => 'Merken',
    'topic.this' => 'Dieses Thema',
    'topic.removed_title' => 'Inhalt entfernt',
    'topic.removed_text' => 'Dieser Beitrag wurde nach Prüfung durch eine ausgeloste Bürger-Jury entfernt.',

    'vote.for' => 'Dafür',
    'vote.against' => 'Dagegen',
    'vote.login_hint' => 'Zum Abstimmen Ausweis auflegen',
    'vote.bar_aria' => 'Abstimmungsergebnis',

    'topic.new_title' => 'Thema einbringen',
    'topic.posted_today' => 'Heute bereits ein Thema eingebracht. Das nächste ist ab 00:00 Uhr möglich.',
    'topic.next_in' => 'Nächstes Thema in',
    'common.close' => 'Schließen',
    'topics.clear' => 'Filter zurücksetzen',
    'scope.whole' => 'gesamt',
    'topic.f_end' => 'Ende der Abstimmung',
    'topic.end_by_date' => 'an einem Datum',
    'topic.end_by_target' => 'bei erreichter Stimmenzahl',
    'topic.end_value_ph' => 'Anzahl',
    'topic.end_unit' => 'Einheit',
    'topic.end_unit_count' => 'X Stimmen',
    'topic.end_unit_percent' => '% Stimmen',
    'topic.err_end' => 'Bitte ein gültiges Ende angeben (Datum in der Zukunft oder Zielzahl).',
    'topic.ends_on' => 'Läuft bis {date}',
    'topic.ends_count' => '{have} von {target} Stimmen',
    'topic.ends_both' => 'Läuft bis {date} oder {have} von {target} Stimmen',
    'topic.ended' => 'beendet',
    'topic.edit' => 'Thema bearbeiten',
    'topic.save' => 'Änderungen speichern',
    'topic.archive' => 'Thema archivieren',
    'topic.archive_confirm' => 'Das Thema verschwindet aus den Listen und ist nicht mehr wählbar. Gelöscht wird es nicht.',
    'topic.archived_badge' => 'Archiviert',
    'topic.archived_note' => 'Dieses Thema wurde vom Verfasser archiviert.',
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

    'auth.with' => 'Mit {app} anmelden',
    'testmode.chip' => 'Testmodus',
    'flash.testmode_ended' => 'Echtbetrieb eingerichtet, Testdaten gelöscht.',
    'flash.testmode_confirm' => 'Bitte das Beenden bestätigen.',
    'setup.title' => 'Echtbetrieb einrichten',
    'setup.missing' => 'Fehlt noch',
    'setup.check_data' => 'Datenverzeichnis beschreibbar',
    'setup.check_https' => 'HTTPS aktiv',
    'setup.check_sodium' => 'Kryptographie (sodium) verfügbar',
    'setup.check_htaccess' => 'Zugriffsschutz (.htaccess) vorhanden',
    'setup.check_keys' => 'Noch keine freigegebenen Ausweis-Schlüssel.',
    'setup.path' => 'Zugang',
    'setup.path_eid' => 'Eigener eID-Server',
    'setup.path_list' => 'Eigene Trust-Liste',
    'setup.f_server' => 'eID-Server',
    'setup.f_cert' => 'Client-Zertifikat',
    'setup.f_key' => 'Privater Schlüssel',
    'setup.f_client' => 'Ausweis-App',
    'setup.f_sync' => 'Adresse der Trust-Liste',
    'setup.f_nect' => 'Nect Wallet',
    'setup.token' => 'Einrichtungsschlüssel',
    'setup.token_hint' => 'data/setup.token',
    'setup.check_btn' => 'Verbindung prüfen',
    'setup.finish_btn' => 'Umschalten',
    'setup.confirm' => 'Testdaten löschen',
    'setup.end_locked' => 'Umschalten nur durch Bearbeiten der Datei.',
    'setup.err_client' => 'Adresse der Ausweis-App ist ungültig.',
    'setup.err_https' => 'Adressen müssen mit https:// beginnen.',
    'setup.err_file' => 'Zertifikat oder Schlüssel ist nicht lesbar.',
    'setup.err_server_missing' => 'Für den eID-Server wird dessen SOAP-Adresse benötigt.',
    'setup.err_list_empty' => 'Die Freigabeliste ist leer: Abgleich-Adresse angeben oder zuerst Ausweise ausgeben.',
    'setup.err_token' => 'Einrichtungsschlüssel stimmt nicht.',
    'setup.err_check_first' => 'Bitte zuerst die Verbindung prüfen.',
    'setup.err_save' => 'Einstellungen nicht speicherbar – Rechte des Ordners data/ prüfen.',
    'flash.setup_ok' => 'eID-Server antwortet.',
    'flash.setup_failed' => 'eID-Server antwortet nicht oder liefert keine Sitzung.',
    'flash.setup_no_server' => 'Keine eID-Server-Adresse angegeben.',
    'flash.setup_list_ok' => 'Trust-Liste erreichbar: {n} Schlüssel.',
    'flash.setup_list_failed' => 'Trust-Liste nicht erreichbar.',
    'auth.title' => 'Mit Ausweis anmelden',
    'auth.tap' => 'Ausweis auflegen',
    'auth.test_login' => 'Test-Anmeldung starten',
    'auth.hold' => 'Ausweis an das Gerät halten …',

    'me.jury_upcoming' => 'Ausgelost; Abstimmung ab {date}, 00:00 Uhr.',

    'jury.title' => 'Bürger-Jury',
    'jury.intro' => 'Per Los ausgewählt. Bitte anhand der Kriterien bewerten; Enthaltung zulässig.',
    'jury.blocked' => 'Bis zur Stimmabgabe sind die übrigen Funktionen gesperrt.',
    'jury.none' => 'Keine Jury-Aufgabe.',
    'jury.upcoming' => 'Ausgelost; Abstimmung ab {date}, 00:00 Uhr. Bis dahin ist nichts zu tun.',
    'jury.reported' => 'Gemeldeter Inhalt',
    'jury.law' => 'Gemeldeter Gesetzesverstoß',
    'jury.question' => 'Verstößt der Inhalt gegen das zitierte Gesetz?',
    'jury.confirm' => 'Ja – entfernen',
    'jury.reject' => 'Nein – behalten',
    'jury.neutral' => 'Enthaltung',
    'jury.stats' => '{cast} von {seats} Stimmen abgegeben · Quorum: {quorum}',
    'jury.deadline' => 'Reguläres Ende: {date}; danach wird bei erreichtem Quorum entschieden.',

    'report.title' => 'Inhalt melden',
    'report.intro' => 'Einziger Meldegrund: Verstoß gegen ein Gesetz. Der verletzte Paragraph wird 1:1 zitiert.',
    'report.process' => 'Es entscheidet eine ausgeloste Bürger-Jury (1 %; ab 00:00 Uhr, 24 h, Quorum 0,5 %).',
    'report.search' => 'Gesetz finden (Schlagwort oder Paragraph)',
    'report.pick' => 'Verletztes Gesetz (wird 1:1 zitiert)',
    'report.none_found' => 'Kein Treffer. Anderes Schlagwort oder Paragraphennummer versuchen.',
    'report.submit' => 'Meldung abschicken',
    'report.cancel' => 'Abbrechen',

    'flash.session_expired' => 'Sitzung beendet. Bitte Ausweis erneut auflegen.',
    'flash.auth_expired' => 'Anmeldung abgelaufen – bitte Ausweis erneut auflegen.',
    'flash.login_required' => 'Bitte zuerst den Ausweis auflegen.',
    'flash.card_required' => 'Bestätigung fehlgeschlagen. Bitte Ausweis erneut auflegen.',
    'flash.rate_limited' => 'Zu viele Anfragen. Bitte kurz warten.',
    'flash.csrf' => 'Anfrage konnte nicht zugeordnet werden. Bitte erneut versuchen.',
    'flash.invalid_input' => 'Ungültige Eingabe.',
    'flash.topic_daily_limit' => 'Heute bereits ein Thema eingebracht; das nächste ab 00:00 Uhr.',
    'flash.topic_created' => 'Thema veröffentlicht.',
    'flash.topic_not_votable' => 'Abstimmung nicht möglich.',
    'flash.topic_not_reportable' => 'Meldung nicht möglich.',
    'flash.vote_saved' => 'Stimme gespeichert.',
    'vote.neutral' => 'Enthalten',
    'flash.favorite_added' => 'Favorit hinzugefügt.',
    'flash.favorite_removed' => 'Favorit entfernt.',
    'flash.report_already_open' => 'Für dieses Thema läuft bereits eine Prüfung.',
    'flash.report_duplicate' => 'Dieses Thema wurde von Ihnen bereits gemeldet.',
    'flash.report_daily_limit' => 'Tageslimit für Meldungen erreicht.',
    'flash.report_too_few_users' => 'Für eine Jury sind derzeit zu wenige Teilnehmende registriert.',
    'flash.report_no_law' => 'Bitte das verletzte Gesetz auswählen.',
    'flash.not_author' => 'Nur der Verfasser kann dieses Thema ändern.',
    'flash.topic_locked' => 'Sobald abgestimmt wurde, bleibt das Thema dauerhaft bestehen.',
    'topic.similar' => 'Ähnliche Themen',
    'topic.similar_hint' => 'Zu diesem Titel gibt es bereits ähnliche Themen. Einbringen ist trotzdem möglich.',
    'flash.topic_updated' => 'Thema aktualisiert.',
    'flash.topic_archived' => 'Thema archiviert.',
    'flash.vote_locked' => 'Diese Stimme ist nach 24 Stunden nicht mehr änderbar.',
    'flash.report_created' => 'Meldung aufgenommen. Die Jury ist ausgelost; Abstimmung ab 00:00 Uhr.',
    'flash.jury_not_open' => 'Diese Abstimmung ist nicht (mehr) offen.',
    'flash.jury_not_member' => 'Keine Berechtigung für diese Jury.',
    'flash.jury_already_voted' => 'In dieser Prüfung wurde bereits abgestimmt.',
    'flash.jury_voted' => 'Jury-Stimme gezählt.',
    'flash.auth_failed' => 'Anmeldung fehlgeschlagen.',
    'flash.eid_required' => 'Anmeldung nur mit echtem Ausweis über eine Ausweis-App.',
    'flash.eid_provider_off' => 'Dieser Anbieter ist in dieser Installation noch nicht eingerichtet.',
    'flash.no_card' => 'Kein Ausweis vorhanden. Bitte Ausweis bereitstellen.',
    'flash.card_not_authorized' => 'Dieser Ausweis ist nicht autorisiert.',
    'flash.card_ready' => 'Ausweis bereit. Zum Anmelden auflegen.',
    'flash.card_new' => 'Bereit für einen anderen Ausweis. Zum Anmelden auflegen.',

    'error.not_found_title' => 'Seite nicht gefunden',
    'error.not_found' => 'Die angeforderte Seite existiert nicht oder wurde entfernt.',
    'error.generic_title' => 'Fehler',
    'error.generic' => 'Es ist ein Fehler aufgetreten. Bitte später erneut versuchen.',
    'error.method' => 'Anfrageart nicht unterstützt.',

    'footer.home' => 'Startseite',
    'footer.about' => 'Über uns',
    'footer.imprint' => 'Impressum',
    'footer.privacy' => 'Datenschutz',

    'about.h' => 'Über diese Seite',
    'about.p1' => 'Bürgerabstimmung ist eine offene Seite. Jeder kann ein politisches Thema zur Abstimmung stellen und über die Themen anderer abstimmen.',
    'about.p2' => 'Gefragt ist die eigene Meinung. Jede Person hat pro Thema genau eine Stimme. Dafür wird der Ausweis geprüft, damit niemand mit mehreren Konten abstimmt.',
    'about.p3' => 'Vom Ausweis kommt nur eine Kennung, die allein für diese Seite gilt. Name, Anschrift und Geburtsdatum werden nicht gelesen. Am Konto steht nicht, wie jemand abgestimmt hat.',
    'about.p4' => 'Die Ergebnisse kann jeder lesen, auch ohne Anmeldung. Für eigene Themen und Stimmen ist eine Anmeldung nötig.',
    'about.p5' => 'Die Seite wird privat betrieben. Sie gehört nicht zur Regierung und ist keine Wahl. Das Ergebnis ist ein Meinungsbild.',

    'imprint.h' => 'Impressum',
    'imprint.p1' => "Florian Kutzer\nc/o POSTFLEX PFX-730-065\nEmsdettener Straße 10\n48268 Greven",
    'imprint.p2' => 'E-Mail: impressum@buergerabstimmung.org',
    'imprint.p3' => "Verantwortlich für den Inhalt:\nFlorian Kutzer\nc/o POSTFLEX PFX-730-065\nEmsdettener Straße 10\n48268 Greven",

    'privacy.h' => 'Datenschutzerklärung',
    'privacy.p1' => 'Diese Anwendung verarbeitet keine Klaridentitäten wie Name, Anschrift, Geburtsdatum oder E-Mail-Adresse.',
    'privacy.p2' => 'Zur Nutzung der Seite wird ein technisches Profil erstellt. Dieses Profil sowie die auf der Seite abgegebenen Eingaben, Stimmen, Themen, Meldungen und Entscheidungen werden dauerhaft gespeichert. Die dauerhafte Speicherung dient der Eindeutigkeit, der Nachvollziehbarkeit, der Verhinderung mehrfacher oder nachträglich manipulierter Vorgänge und der Sicherheit der Anwendung.',
    'privacy.p3' => 'Zur Wiedererkennung verwendet die Anwendung pseudonyme technische Kennungen, zum Beispiel Hash- oder HMAC-Werte mit serverseitigem Geheimnis. Diese Kennungen dienen der Anmeldung, Sitzungsführung, Missbrauchsabwehr und Verhinderung von Doppelstimmen.',
    'privacy.p4' => 'Die Seite verwendet ein technisch notwendiges Sitzungs-Cookie für Anmeldung und Sicherheit. Weitere Cookies, Tracking, Werbung und externe Drittinhalte werden nicht eingesetzt.',
    'privacy.p5' => 'Zur Missbrauchsabwehr und Fehleranalyse können technische Protokolldaten verarbeitet werden. Diese werden nur für Sicherheit und Betrieb verwendet.',
    'privacy.p6' => 'Rechtsgrundlage ist, soweit erforderlich, Art. 6 Abs. 1 lit. f DSGVO. Das berechtigte Interesse liegt im sicheren Betrieb der Anwendung, der Eindeutigkeit der Abstimmungen und dem Schutz vor Manipulation.',
    'privacy.p7' => 'Betroffene Personen haben nach Maßgabe der DSGVO Rechte auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Widerspruch und Beschwerde bei einer Datenschutzaufsichtsbehörde.',
    'privacy.p8' => 'Löschung und Einschränkung der Verarbeitung können abgelehnt oder nur eingeschränkt umgesetzt werden, soweit die weitere Speicherung zur Integrität, Nachvollziehbarkeit, Missbrauchsabwehr, Verhinderung von Doppelstimmen oder Verhinderung nachträglicher Manipulation erforderlich ist.',
    'privacy.p9' => 'Nutzer sind für die von ihnen eingestellten Inhalte selbst verantwortlich. Der Betreiber macht sich diese Inhalte nicht zu eigen. Rechtswidrige Inhalte können nach Kenntnisnahme geprüft, eingeschränkt oder entfernt werden.',
    'privacy.p10' => "Verantwortlich:\nFlorian Kutzer\nc/o POSTFLEX PFX-730-065\nEmsdettener Straße 10\n48268 Greven\nE-Mail: datenschutz@buergerabstimmung.org",
    'consent.text' => 'Diese Seite verwendet ein technisch notwendiges Sitzungs-Cookie für Anmeldung und Sicherheit. Details entnehmen Sie der Datenschutzerklärung.',
    'consent.title' => 'Cookies',
    'consent.test' => 'Testbetrieb: Die Anmeldung erzeugt zurzeit eine zufällige Kennung, es wird noch kein Ausweis gelesen. Angelegte Themen und Stimmen werden beim Wechsel in den Echtbetrieb gelöscht.',
    'consent.accept' => 'Akzeptieren',
    'error.consent' => 'Dafür wird das Sitzungs-Cookie benötigt. Bitte auf der Anmeldeseite zustimmen.',
];

const SW_EN = [
    'app.tagline' => 'Digital citizen participation',
    'banner.official' => 'Not an official website of the German federal government or any public authority.',
    'a11y.skip' => 'Skip to content',
    'nav.topics' => 'Topics',
    'nav.jury' => 'Jury',
    'auth.login' => 'Place your ID card',
    'auth.logout' => 'Sign out',
    'common.date_format' => 'Y-m-d',
    'common.datetime_format' => 'Y-m-d, H:i',
    'common.back_home' => 'Back to start page',

    'topics.filter_category' => 'Category',
        'topics.filter_all' => 'All',
    'topics.search' => 'Search',
    'topics.sort_net' => 'Highest approval',
    'topics.sort' => 'Sort',
    'topics.sort_new' => 'Newest',
    'topics.sort_top' => 'Most votes',
    'topics.apply' => 'Apply',
    'topics.none' => 'No topics found.',
    'topics.prev' => 'Previous',
    'topics.next' => 'Next',
    'topics.page_of' => 'Page {p} of {n}',

    'scope.bund' => 'Germany',
    'scope.bundesland' => 'Federal state',
    'scope.landkreis' => 'District',

    'topic.goal_label' => 'Goal',
    'topic.reasoning_label' => 'Reasoning',
    'topic.report_link' => 'Report content',
    'topic.report_open' => 'Community review in progress.',
    'topic.remember' => 'Save',
    'topic.this' => 'This topic',
    'topic.removed_title' => 'Content removed',
    'topic.removed_text' => 'This contribution was removed after review by a randomly drawn citizen jury.',

    'vote.for' => 'For',
    'vote.against' => 'Against',
    'vote.login_hint' => 'Place your ID card to vote',
    'vote.bar_aria' => 'Voting result',

    'topic.new_title' => 'Raise a topic',
    'common.close' => 'Close',
    'topics.clear' => 'Reset filters',
    'scope.whole' => 'whole',
    'topic.f_end' => 'End of voting',
    'topic.end_by_date' => 'on a date',
    'topic.end_by_target' => 'at a number of votes',
    'topic.end_value_ph' => 'Amount',
    'topic.end_unit' => 'Unit',
    'topic.end_unit_count' => 'X votes',
    'topic.end_unit_percent' => '% votes',
    'topic.err_end' => 'Please set a valid end (future date or target count).',
    'topic.ends_on' => 'Runs until {date}',
    'topic.ends_count' => '{have} of {target} votes',
    'topic.ends_both' => 'Runs until {date} or {have} of {target} votes',
    'topic.ended' => 'ended',
    'topic.edit' => 'Edit topic',
    'topic.save' => 'Save changes',
    'topic.archive' => 'Archive topic',
    'topic.archive_confirm' => 'The topic disappears from the lists and can no longer be voted on. It is not deleted.',
    'topic.archived_badge' => 'Archived',
    'topic.archived_note' => 'This topic was archived by its author.',
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

    'auth.with' => 'Sign in with {app}',
    'testmode.chip' => 'Test mode',
    'flash.testmode_ended' => 'Live operation configured, test data deleted.',
    'flash.testmode_confirm' => 'Please confirm ending test mode.',
    'setup.title' => 'Set up live operation',
    'setup.missing' => 'Still missing',
    'setup.check_data' => 'Data directory writable',
    'setup.check_https' => 'HTTPS active',
    'setup.check_sodium' => 'Cryptography (sodium) available',
    'setup.check_htaccess' => 'Access protection (.htaccess) present',
    'setup.check_keys' => 'No authorised ID keys yet.',
    'setup.path' => 'Access',
    'setup.path_eid' => 'Own eID server',
    'setup.path_list' => 'Own trust list',
    'setup.f_server' => 'eID server',
    'setup.f_cert' => 'Client certificate',
    'setup.f_key' => 'Private key',
    'setup.f_client' => 'ID app',
    'setup.f_sync' => 'Trust list address',
    'setup.f_nect' => 'Nect Wallet',
    'setup.token' => 'Setup key',
    'setup.token_hint' => 'data/setup.token',
    'setup.check_btn' => 'Test connection',
    'setup.finish_btn' => 'Switch over',
    'setup.confirm' => 'Delete test data',
    'setup.end_locked' => 'Switching only by editing the file.',
    'setup.err_client' => 'The ID app address is invalid.',
    'setup.err_https' => 'Addresses must start with https://.',
    'setup.err_file' => 'Certificate or key is not readable.',
    'setup.err_server_missing' => 'The eID server needs its SOAP address.',
    'setup.err_list_empty' => 'The allowlist is empty: give a sync address or issue ID cards first.',
    'setup.err_token' => 'Setup key does not match.',
    'setup.err_check_first' => 'Please test the connection first.',
    'setup.err_save' => 'Settings could not be saved – check the permissions of the data/ folder.',
    'flash.setup_ok' => 'eID server responds.',
    'flash.setup_failed' => 'eID server does not respond or returns no session.',
    'flash.setup_no_server' => 'No eID server address given.',
    'flash.setup_list_ok' => 'Trust list reachable: {n} keys.',
    'flash.setup_list_failed' => 'Trust list not reachable.',
    'auth.title' => 'Sign in with ID card',
    'auth.tap' => 'Place your ID card',
    'auth.test_login' => 'Start test sign-in',
    'auth.hold' => 'Hold your ID card to the device …',

    'me.jury_upcoming' => 'Drawn; voting starts {date}, midnight.',

    'jury.title' => 'Citizen jury',
    'jury.intro' => 'Drawn by lot. Please assess against the criteria; abstaining is allowed.',
    'jury.blocked' => 'Until you vote, the other functions are locked.',
    'jury.none' => 'No jury task.',
    'jury.upcoming' => 'Drawn; voting starts {date}, midnight. Nothing to do until then.',
    'jury.reported' => 'Reported content',
    'jury.law' => 'Reported violation of law',
    'jury.question' => 'Does the content violate the quoted law?',
    'jury.confirm' => 'Yes – remove',
    'jury.reject' => 'No – keep',
    'jury.neutral' => 'Abstain',
    'jury.stats' => '{cast} of {seats} votes cast · quorum: {quorum}',
    'jury.deadline' => 'Regular end: {date}; afterwards a decision is made once the quorum is reached.',

    'report.title' => 'Report content',
    'report.intro' => 'The only reason to report: violation of a law. The violated section is quoted verbatim.',
    'report.process' => 'A drawn citizen jury decides (1%; from midnight, 24 h, 0.5% quorum).',
    'report.search' => 'Find the law (keyword or section number)',
    'report.pick' => 'Violated law (quoted verbatim)',
    'report.none_found' => 'No match. Try another keyword or section number.',
    'report.submit' => 'Submit report',
    'report.cancel' => 'Cancel',

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
    'vote.neutral' => 'Abstain',
    'flash.favorite_added' => 'Favourite added.',
    'flash.favorite_removed' => 'Favourite removed.',
    'flash.report_already_open' => 'A review is already in progress for this topic.',
    'flash.report_duplicate' => 'You have already reported this topic.',
    'flash.report_daily_limit' => 'Daily report limit reached.',
    'flash.report_too_few_users' => 'Too few participants are registered for a jury at the moment.',
    'flash.report_no_law' => 'Please select the violated law.',
    'flash.not_author' => 'Only the author can change this topic.',
    'flash.topic_locked' => 'Once votes are cast the topic stays permanently.',
    'topic.similar' => 'Similar topics',
    'topic.similar_hint' => 'Similar topics already exist for this title. You can still publish it.',
    'flash.topic_updated' => 'Topic updated.',
    'flash.topic_archived' => 'Topic archived.',
    'flash.vote_locked' => 'This vote can no longer be changed after 24 hours.',
    'flash.report_created' => 'Report received. The jury has been drawn; voting starts at midnight.',
    'flash.jury_not_open' => 'This vote is not (or no longer) open.',
    'flash.jury_not_member' => 'No authorisation for this jury.',
    'flash.jury_already_voted' => 'Already voted in this review.',
    'flash.jury_voted' => 'Jury vote counted.',
    'flash.auth_failed' => 'Sign-in failed.',
    'flash.eid_required' => 'Sign-in only with a real ID card via an ID app.',
    'flash.eid_provider_off' => 'This provider is not set up in this installation yet.',
    'flash.no_card' => 'No ID card present. Please provide an ID card.',
    'flash.card_not_authorized' => 'This ID card is not authorised.',
    'flash.card_ready' => 'ID card ready. Tap to sign in.',
    'flash.card_new' => 'Ready for a different ID card. Tap to sign in.',

    'error.not_found_title' => 'Page not found',
    'error.not_found' => 'The requested page does not exist or has been removed.',
    'error.generic_title' => 'Error',
    'error.generic' => 'An error occurred. Please try again later.',
    'error.method' => 'Request method not supported.',

    'footer.home' => 'Home',
    'footer.about' => 'About',
    'footer.imprint' => 'Legal notice',
    'footer.privacy' => 'Privacy',

    'about.h' => 'About this site',
    'about.p1' => 'Bürgerabstimmung is an open site. Anyone can put a political topic up for a vote and vote on topics raised by others.',
    'about.p2' => 'What counts is your own opinion. Each person has exactly one vote per topic. The ID card is checked so that nobody votes with several accounts.',
    'about.p3' => 'Only one identifier comes from the ID card, and it is valid for this site alone. Name, address and date of birth are not read. The account does not record how someone voted.',
    'about.p4' => 'Anyone can read the results, without signing in. Signing in is needed for your own topics and votes.',
    'about.p5' => 'The site is run privately. It does not belong to the government and it is not an election. The result is a snapshot of opinion.',

    'imprint.h' => 'Legal notice',
    'imprint.p1' => "Florian Kutzer\nc/o POSTFLEX PFX-730-065\nEmsdettener Straße 10\n48268 Greven",
    'imprint.p2' => 'E-mail: impressum@buergerabstimmung.org',
    'imprint.p3' => "Responsible for the content:\nFlorian Kutzer\nc/o POSTFLEX PFX-730-065\nEmsdettener Straße 10\n48268 Greven",

    'privacy.h' => 'Privacy policy',
    'privacy.p1' => 'This application does not process clear identities such as name, address, date of birth or e-mail address.',
    'privacy.p2' => 'A technical profile is created in order to use the site. This profile and the entries, votes, topics, reports and decisions submitted on the site are stored permanently. Permanent storage serves uniqueness, traceability, the prevention of duplicate or retroactively manipulated operations, and the security of the application.',
    'privacy.p3' => 'For recognition the application uses pseudonymous technical identifiers, for example hash or HMAC values with a server-side secret. These identifiers serve sign-in, session handling, abuse prevention and the prevention of duplicate votes.',
    'privacy.p4' => 'The site uses one technically necessary session cookie for sign-in and security. No further cookies, tracking, advertising or external third-party content are used.',
    'privacy.p5' => 'Technical log data may be processed for abuse prevention and error analysis. It is used for security and operation only.',
    'privacy.p6' => 'The legal basis, where required, is Art. 6(1)(f) GDPR. The legitimate interest lies in the secure operation of the application, the uniqueness of the votes and protection against manipulation.',
    'privacy.p7' => 'Data subjects have the rights to information, rectification, erasure, restriction of processing, objection and to lodge a complaint with a supervisory authority, as provided by the GDPR.',
    'privacy.p8' => 'Erasure and restriction of processing may be refused or carried out only in part where continued storage is necessary for integrity, traceability, abuse prevention, prevention of duplicate votes or prevention of retroactive manipulation.',
    'privacy.p9' => 'Users are responsible for the content they submit. The operator does not adopt this content as its own. Unlawful content may be reviewed, restricted or removed once it becomes known.',
    'privacy.p10' => "Responsible:\nFlorian Kutzer\nc/o POSTFLEX PFX-730-065\nEmsdettener Straße 10\n48268 Greven\nE-mail: datenschutz@buergerabstimmung.org",
    'consent.text' => 'This site uses one technically necessary session cookie for sign-in and security. See the privacy policy for details.',
    'consent.title' => 'Cookies',
    'consent.test' => 'Test operation: sign-in currently creates a random identifier, no ID card is read yet. Topics and votes created now are deleted when switching to live operation.',
    'consent.accept' => 'Accept',
    'error.consent' => 'This needs the session cookie. Please accept on the sign-in page.',
];

const SW_CSS = <<<'CSS'

:root {
  color-scheme: light dark;
  --page: #f2f2f7; --surface: #ffffff; --field: #efeff4;
  --ink: #000000; --muted: #8e8e93; --sep: #d1d1d6;
  --accent: #3a76f0; --accent-ink: #ffffff; --accent-soft: rgba(58,118,240,0.12);
  --danger: #d70015; --danger-soft: rgba(215,0,21,0.10);
  --vote-for: #3a76f0; --vote-against: #aeaeb2; --track: #e5e5ea;
  --warn-bg: #ffd60a; --warn-ink: #1c1c00;
  --btn-dim: #dedee4; --btn-dim-ink: #5b5b60;
  --btn-locked: #e7effd; --btn-locked-ink: #2c60d0;
  --radius: 14px; --radius-sm: 10px;
}
@media (prefers-color-scheme: dark) {
  :root {
    --page: #000000; --surface: #1c1c1e; --field: #2c2c2e;
    --ink: #ffffff; --muted: #98989d; --sep: #38383a;
    --accent: #4b86f7; --accent-ink: #ffffff; --accent-soft: rgba(75,134,247,0.22);
    --danger: #ff453a; --danger-soft: rgba(255,69,58,0.18);
    --vote-for: #4b86f7; --vote-against: #8e8e93; --track: #2c2c2e;
    --warn-bg: #ffd60a; --warn-ink: #1c1c00;
    --btn-dim: #0f0f11; --btn-dim-ink: #98989d;
    --btn-locked: #26334c; --btn-locked-ink: #9dbdfc;
  }
}

* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, system-ui, sans-serif;
  font-size: 1.0625rem;
  line-height: 1.55;
  color: var(--ink);
  background: var(--page);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  -webkit-font-smoothing: antialiased;
}
.shell { width: 100%; max-width: 44rem; margin: 0 auto; padding: 0 1rem; }
.site-main { flex: 1; padding-top: 1rem; padding-bottom: 3rem; }

h1 { font-size: 1.6rem; line-height: 1.2; margin: 0.2rem 0 0.7rem; font-weight: 700; letter-spacing: -0.02em; }
h2 { font-size: 1.15rem; line-height: 1.25; margin: 1.5rem 0 0.5rem; font-weight: 650; letter-spacing: -0.01em; }
h3 { font-size: 1.0625rem; margin: 0 0 0.2rem; font-weight: 600; }
p { margin: 0.45rem 0; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 6px; }
.muted { color: var(--muted); font-size: 0.95rem; }

.skip-link { position: absolute; left: -999px; top: 0; background: var(--surface); color: var(--ink); padding: 0.6rem 1rem; border-radius: var(--radius-sm); z-index: 100; }
.skip-link:focus { left: 0.5rem; top: 0.5rem; }

.test-banner {
  background: var(--warn-bg); color: var(--warn-ink);
  text-align: center; font-size: 0.72rem; font-weight: 600; line-height: 1.25;
  padding: 0.3rem 0.9rem; letter-spacing: -0.01em;
}

.site-header { background: var(--surface); border-bottom: 1px solid var(--sep); position: sticky; top: 0; z-index: 50; }
.header-inner { display: flex; align-items: center; gap: 0.6rem; min-height: 3rem; padding-top: 0.4rem; padding-bottom: 0.4rem; }
.brand { display: inline-flex; align-items: center; gap: 0.45rem; color: var(--ink); font-weight: 650; font-size: 1.05rem; letter-spacing: -0.01em; }
.brand:hover { text-decoration: none; }
.brand-mark { width: 1.4rem; height: 1.4rem; color: var(--accent); }
.header-controls { display: flex; align-items: center; gap: 0.5rem; margin-left: auto; }
.testmode-chip { padding: 0.3rem 0.7rem; border-radius: 999px; background: var(--warn-bg); color: var(--warn-ink); font-size: 0.85rem; font-weight: 650; }
.testmode-chip:hover { text-decoration: none; opacity: 0.9; }
.header-inner { justify-content: flex-end; }
.nav-duty { position: relative; padding: 0.3rem 0.7rem; border-radius: 999px; background: var(--accent-soft); color: var(--accent); font-size: 0.9rem; font-weight: 600; }
.nav-duty:hover { text-decoration: none; }
.duty-dot { display: inline-block; width: 0.4rem; height: 0.4rem; border-radius: 50%; background: var(--danger); margin-left: 0.35rem; vertical-align: middle; }

.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
  border: 0; border-radius: 12px; font: inherit; font-weight: 600; font-size: 1rem;
  padding: 0.62rem 1.05rem; cursor: pointer; text-decoration: none;
  transition: opacity 120ms ease, background-color 120ms ease;
  -webkit-tap-highlight-color: transparent;
}
.btn:hover { text-decoration: none; opacity: 0.88; }
.btn:active { opacity: 0.7; }
.btn-sm { padding: 0.4rem 0.8rem; font-size: 0.92rem; border-radius: 10px; }
.btn-big { width: 100%; padding: 0.9rem 1.2rem; font-size: 1.0625rem; border-radius: var(--radius); }
.btn-primary { background: var(--accent); color: var(--accent-ink); }
.btn-outline { background: var(--accent-soft); color: var(--accent); }
.btn-ghost { background: transparent; color: var(--accent); padding-left: 0.6rem; padding-right: 0.6rem; }
.btn-danger { background: var(--danger-soft); color: var(--danger); }
.btn-row { display: flex; gap: 0.6rem; flex-wrap: wrap; }

.card { background: var(--surface); border-radius: var(--radius); padding: 0.95rem 1.05rem; margin: 0.7rem 0; }
.badge {
  display: inline-block; font-size: 0.78rem; font-weight: 600; letter-spacing: -0.01em;
  padding: 0.15rem 0.55rem; border-radius: 999px;
  background: var(--field); color: var(--muted); white-space: nowrap;
}
.badge-danger { background: var(--danger-soft); color: var(--danger); }

.flash {
  background: var(--surface); color: var(--ink);
  border-left: 3px solid var(--accent); border-radius: var(--radius-sm);
  padding: 0.7rem 0.9rem; margin: 0.7rem 0; font-size: 0.95rem;
}
.flash-error { border-left-color: var(--danger); }
.flash a { font-weight: 600; }
.plain-list { margin: 0; padding-left: 1.1rem; }

.row-list { list-style: none; margin: 0; padding: 0; background: var(--surface); border-radius: var(--radius); overflow: hidden; }
.row-item { position: relative; display: flex; justify-content: space-between; align-items: center; gap: 0.7rem; flex-wrap: wrap; padding: 0.75rem 1rem; min-height: 2.75rem; }
.row-item:not(:last-child)::after { content: ""; position: absolute; left: 1rem; right: 0; bottom: 0; height: 1px; background: var(--sep); }
.row-main { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; min-width: 0; }
.row-side { display: flex; align-items: center; gap: 0.35rem; font-size: 0.92rem; flex-wrap: wrap; color: var(--ink); }

.action-bar { display: flex; gap: 0.5rem; flex-wrap: wrap; margin: 0.2rem 0 0.9rem; }
.action-bar .btn { flex: 1 1 auto; }
.fav-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin: 0 0 0.9rem; }
.fav-chips .btn { background: var(--accent-soft); color: var(--accent); font-weight: 500; }
.fav-chip { display: inline-flex; align-items: center; gap: 0.4rem; }
.fav-chip .ico { width: 0.95rem; height: 0.95rem; color: var(--accent); }
.recent-votes { margin-bottom: 1.1rem; }
.recent-votes h2 { margin-top: 0.6rem; }

.topic-grid { display: grid; grid-template-columns: 1fr; gap: 0.6rem; }
.topic-card { margin: 0; display: flex; flex-direction: column; gap: 0.4rem; }
.topic-card-meta { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.topic-card-title { margin: 0; font-size: 1.05rem; font-weight: 600; letter-spacing: -0.01em; }
.topic-card-title a { color: var(--ink); }
.topic-card-title a:hover { color: var(--accent); text-decoration: none; }
.topic-card-goal { color: var(--muted); font-size: 0.95rem; margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.topic-card-votes { font-size: 0.9rem; color: var(--muted); margin-top: auto; display: flex; align-items: center; flex-wrap: wrap; gap: 0.15rem; }
.vote-sep { margin: 0 0.35rem; }
.pagination { display: flex; align-items: center; gap: 0.8rem; justify-content: center; margin: 1.3rem 0 0; }

.topic-detail h1 { margin-top: 0.4rem; }
.field-label { font-size: 0.78rem; font-weight: 600; letter-spacing: 0.02em; text-transform: uppercase; color: var(--muted); margin: 0.9rem 0 0.2rem; }
.card .field-label:first-child { margin-top: 0; }
.dot { display: inline-block; width: 0.5rem; height: 0.5rem; border-radius: 50%; margin-right: 0.3rem; }
.dot-for { background: var(--vote-for); }
.dot-against { background: var(--vote-against); }
.dot-neutral { background: var(--muted); }
.votebar { display: flex; gap: 2px; height: 0.6rem; border-radius: 999px; overflow: hidden; background: var(--track); }
.votebar span { flex-basis: 0; min-width: 4px; }
.votebar-for { background: var(--vote-for); }
.votebar-against { background: var(--vote-against); }
.votebar-legend { display: flex; flex-wrap: wrap; gap: 0.3rem 1.1rem; font-size: 0.92rem; margin-top: 0.5rem; color: var(--ink); }
.votebar-for.w-0, .votebar-against.w-0 { display: none; }
.votebar-legend b { font-weight: 600; }
.votebar-legend .pct { color: var(--muted); margin-left: 0.3rem; white-space: nowrap; }
.votefig-slim { margin-top: 0.6rem; }
.votefig-slim .votebar { height: 4px; }
.votefig-slim .votebar-legend { font-size: 0.88rem; color: var(--muted); margin-top: 0.4rem; }
.votefig-slim .votebar-legend b { color: var(--ink); }
.similar-hint { background: var(--field); border-radius: var(--radius-sm); padding: 0.6rem 0.75rem; font-size: 0.9rem; }
.similar-hint p { margin: 0 0 0.35rem; }
.similar-hint .plain-list { display: flex; flex-direction: column; gap: 0.3rem; }
.similar { margin-top: 1rem; }
.similar .plain-list { display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.95rem; }
.vote-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.9rem; }
.vote-actions .btn { flex: 1 1 8rem; }
.vote-btn { background: var(--field); color: var(--ink); }
.vote-btn.is-active { background: var(--accent); color: var(--accent-ink); }
.vote-btn.is-locked { background: var(--btn-locked); color: var(--btn-locked-ink); }
.vote-btn.is-dim { background: var(--btn-dim); color: var(--btn-dim-ink); }
.vote-btn[disabled] { cursor: default; opacity: 1; }
.topic-tools { display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap; margin-top: 0.8rem; }
.topic-tools .btn, .topic-tools .link-quiet {
  font-size: 0.9rem; padding: 0.4rem 0.8rem; border-radius: 999px;
  background: var(--field); color: var(--accent); font-weight: 600;
}
.topic-tools .link-quiet { color: var(--danger); }
.topic-tools .link-quiet:hover, .topic-tools .btn:hover { text-decoration: none; opacity: 0.85; }
.link-quiet { color: var(--muted); font-size: 0.92rem; }
.ico { width: 1.15rem; height: 1.15rem; display: block; }
.card-h { font-size: 0.78rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); margin: 0 0 0.5rem; }
.check-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.4rem; }
.check-list li { display: flex; align-items: baseline; gap: 0.6rem; font-size: 0.95rem; }
.check-list li > span { flex: none; width: 1.1rem; text-align: center; font-weight: 700; }
.check-list .is-ok > span { color: var(--vote-for); }
.check-list .is-warn { color: var(--muted); }
.check-list .is-warn > span { color: var(--danger); }
.fav-menu { position: relative; }
.fav-menu > summary {
  list-style: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
  width: 2.15rem; height: 2.15rem; border-radius: 999px; background: var(--field); color: var(--accent);
}
.fav-menu > summary::-webkit-details-marker { display: none; }
.fav-menu > summary::marker { content: ''; }
.fav-menu[open] > summary { background: var(--accent-soft); }
.fav-pop {
  position: absolute; z-index: 60; bottom: calc(100% + 0.4rem); left: 0; min-width: 12rem;
  background: var(--surface); border-radius: var(--radius-sm); padding: 0.3rem;
  box-shadow: 0 10px 34px rgba(0,0,0,0.20); display: flex; flex-direction: column;
}
.fav-item {
  display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; width: 100%;
  background: none; border: 0; font: inherit; font-size: 1rem; color: var(--ink); cursor: pointer;
  padding: 0.55rem 0.7rem; border-radius: 8px; text-align: left;
}
.fav-item:hover { background: var(--field); }
.fav-item.is-on { color: var(--accent); font-weight: 600; }

.form-stack { display: flex; flex-direction: column; gap: 0.85rem; }
.form-stack > label { display: flex; flex-direction: column; gap: 0.3rem; font-weight: 600; font-size: 0.92rem; }
.form-stack small { font-weight: 400; }
.form-row { display: grid; grid-template-columns: 1fr; gap: 0.85rem; }
.form-row label { display: flex; flex-direction: column; gap: 0.3rem; font-weight: 600; font-size: 0.92rem; }
input[type="text"], input[type="search"], input[type="date"], input[type="number"], textarea, select {
  font: inherit; font-size: 1.0625rem; color: var(--ink); background: var(--field);
  border: 0; border-radius: var(--radius-sm); padding: 0.7rem 0.8rem; width: 100%;
  -webkit-appearance: none; appearance: none;
}
select { background-image: none; }
textarea { resize: vertical; }
input:focus, textarea:focus, select:focus { outline: 2px solid var(--accent); outline-offset: 0; }
.check-label { display: flex; align-items: flex-start; gap: 0.6rem; font-weight: 400; font-size: 1rem; }
.check-label input { margin-top: 0.28rem; accent-color: var(--accent); width: 1.1rem; height: 1.1rem; }
.criteria-set, .end-fields { border: 0; background: var(--field); border-radius: var(--radius-sm); padding: 0.8rem 0.9rem; display: flex; flex-direction: column; gap: 0.6rem; margin: 0; }
.criteria-set legend, .end-fields legend { font-weight: 600; padding: 0; font-size: 0.92rem; float: left; width: 100%; margin-bottom: 0.2rem; }
.criteria-set input[type="radio"] { accent-color: var(--accent); width: 1.1rem; height: 1.1rem; margin-top: 0.25rem; }
.end-fields input, .end-fields select { background: var(--surface); }
.end-fields .check { display: flex; align-items: center; gap: 0.6rem; font-size: 1rem; font-weight: 400; }
.end-fields .check input { accent-color: var(--accent); width: 1.15rem; height: 1.15rem; flex: none; }
.end-row { display: flex; gap: 0.5rem; margin: -0.2rem 0 0.2rem 1.75rem; }
.end-row input, .end-row select { flex: 1 1 0; min-width: 0; width: auto; }
.end-row input[type="number"] { flex: 0 1 7.5rem; }
.end-row[hidden] { display: none; }
.scope-picker { display: flex; flex-direction: column; gap: 0.4rem; }
.law-quote { font-size: 0.92rem; color: var(--muted); display: block; margin-top: 0.25rem; }
.hr-soft { border: 0; border-top: 1px solid var(--sep); margin: 1rem 0 0.6rem; }

.auth-card { max-width: 24rem; margin: 1.6rem auto; text-align: center; padding: 1.4rem 1.2rem 1.2rem; }
.auth-card h1 { font-size: 1.4rem; }
.auth-action { display: flex; justify-content: center; margin: 0.9rem 0 0.2rem; }
.auth-action .btn, .provider-list .btn { width: 100%; }
.provider-list { display: flex; flex-direction: column; gap: 0.55rem; margin: 1rem 0 0.2rem; }
.provider-btn { width: 100%; }
.tap-icon { width: 6rem; height: auto; color: var(--accent); margin: 0.2rem auto 0.6rem; display: block; }
.tap-status { font-weight: 600; color: var(--accent); }

.start-gate { min-height: 82vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.9rem; padding: 2rem 1.2rem; }
.start-brand { font-size: 1.5rem; font-weight: 700; letter-spacing: -0.02em; margin: 0; }
.start-langs { display: flex; gap: 0.8rem; margin-top: 1rem; flex-wrap: wrap; justify-content: center; }
.start-langs form { display: flex; }
.lang-btn {
  display: flex; flex-direction: column; align-items: center; gap: 0.5rem;
  background: var(--surface); border: 0; border-radius: var(--radius);
  padding: 0.9rem 1.2rem; font: inherit; font-weight: 600; color: var(--ink); cursor: pointer;
}
.lang-btn:active { opacity: 0.7; }
.flag { width: 4.2rem; height: auto; display: block; border-radius: 4px; }

.error-card { max-width: 26rem; margin: 3rem auto; text-align: center; }
.prose { max-width: 40rem; }
.prose p { color: var(--muted); }
.countdown { font-variant-numeric: tabular-nums; font-weight: 600; margin-left: 0.4rem; }

.site-footer { border-top: 1px solid var(--sep); background: var(--surface); font-size: 0.85rem; color: var(--muted); }
.footer-inner { display: flex; gap: 0.5rem 1.2rem; flex-wrap: wrap; padding-top: 0.9rem; padding-bottom: 0.9rem; }
.footer-nav { display: flex; gap: 1rem; }
.footer-nav a { color: var(--muted); }

.modal { position: fixed; inset: 0; z-index: 200; display: none; }
.modal:target { display: flex; align-items: flex-end; justify-content: center; }
.modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.4); }
.modal-box {
  position: relative; z-index: 1; background: var(--page);
  border-radius: 16px 16px 0 0; width: 100%; max-height: 90vh; overflow-y: auto;
  padding: 0.5rem 1rem 1.6rem;
}
.modal-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.6rem 0 0.7rem; position: sticky; top: 0; background: var(--page); }
.modal-head h2 { margin: 0; font-size: 1.15rem; }
.modal-close { font-size: 1.6rem; line-height: 1; color: var(--muted); padding: 0 0.3rem; }
.modal-close:hover { color: var(--ink); text-decoration: none; }
.modal-body > .form-stack, .modal-body > form { background: var(--surface); border-radius: var(--radius); padding: 0.95rem 1rem; }

@media (min-width: 40rem) {
  .modal:target { align-items: center; }
  .modal-box { width: min(34rem, 92vw); border-radius: var(--radius); max-height: 86vh; }
  .form-row { grid-template-columns: 1fr 1fr; }
  .topic-grid { grid-template-columns: 1fr 1fr; }
  .auth-action .btn, .provider-list .btn { width: auto; min-width: 16rem; }
  .provider-list { align-items: center; }
  .auth-action { justify-content: center; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
.path-set input[type="radio"] { float: left; margin: .25rem .5rem 0 0; }
.path-label { display: block; overflow: hidden; margin: 0 0 .4rem; }
.only-eid, .only-list { display: none; clear: both; }
#path-eid:checked ~ .only-eid, #path-list:checked ~ .only-list { display: flex; flex-direction: column; gap: .6rem; }
.form-stack > .check-label { display: flex; flex-direction: row; align-items: center; gap: .5rem; }
.check-list li { display: flex; gap: .5rem; }
.btn:disabled { opacity: .5; }
.consent-actions { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: center; }

.btn-icon { flex: 0 0 auto; width: 2.75rem; height: 2.75rem; padding: 0; border-radius: 999px; }
.btn-icon .ico { width: 1.4rem; height: 1.4rem; }
.action-bar .btn-icon { flex: 0 0 auto; }
.fold summary { cursor: pointer; list-style: none; display: flex; align-items: center; gap: 0.4rem; font-size: 0.78rem; font-weight: 600; letter-spacing: 0.02em; text-transform: uppercase; color: var(--muted); }
.fold summary::-webkit-details-marker { display: none; }
.fold summary::after { content: "\203A"; font-size: 0.95rem; transition: transform 120ms ease; }
.fold[open] summary::after { transform: rotate(90deg); }
.fold[open] summary { margin-bottom: 0.3rem; }
.header-controls .btn-icon { width: 2.15rem; height: 2.15rem; }
.home-link { margin-right: auto; flex: 0 0 auto; width: 2.15rem; height: 2.15rem; background: var(--field); }
.home-link .ico { width: 1.15rem; height: 1.15rem; }
.header-controls .btn-icon.btn-ghost { background: var(--field); }
.header-controls .btn-icon .ico { width: 1.15rem; height: 1.15rem; }
.header-controls .fav-pop { bottom: auto; top: calc(100% + 0.4rem); left: auto; right: 0; }
.card .topic-card-meta { margin: 0 0 0.7rem; }
.card .field-label { margin-top: 0.9rem; }
.card .topic-card-meta + .field-label { margin-top: 0; }
.topic-detail .vote-actions { margin-top: 0.9rem; }
.topic-detail .votefig { margin-top: 0.7rem; }
.vote-actions .vote-btn { flex: 1 1 5.5rem; }
CSS;

const SW_JS = <<<'JS'

(function () {
  'use strict';
  var init = function () {

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

    document.querySelectorAll('select[data-scope-native]').forEach(function (native) {
      var current = native.value;
      var level = 'de', land = '', kreis = '';
      if (current.indexOf('bl:') === 0) { level = 'bundesland'; land = current.slice(3); }
      else if (current.indexOf('kr:') === 0) { level = 'landkreis'; var r = current.slice(3).split(':'); land = r[0]; kreis = r.slice(1).join(':'); }
      else if (current === '') { level = native.querySelector('option[value=""]') ? 'all' : 'de'; }
      var lands = {}, order = [];
      native.querySelectorAll('optgroup').forEach(function (g) {
        var name = g.label; order.push(name); lands[name] = [];
        g.querySelectorAll('option').forEach(function (o) {
          if (o.value.indexOf('kr:') === 0) { lands[name].push(o.textContent); }
        });
      });
      var L = {
        de: native.getAttribute('data-l-de') || 'DE',
        bl: native.getAttribute('data-l-bl') || 'Bundesland',
        kr: native.getAttribute('data-l-kr') || 'Landkreis',
        pick: native.getAttribute('data-l-pick') || '—',
        all: native.getAttribute('data-l-all') || '—'
      };
      var wrap = document.createElement('div');
      wrap.className = 'scope-picker';
      var hasAll = !!native.querySelector('option[value=""]');
      var selLevel = document.createElement('select');
      if (hasAll) { selLevel.add(new Option(L.all, 'all')); }
      selLevel.add(new Option(L.de, 'de'));
      selLevel.add(new Option(L.bl, 'bundesland'));
      selLevel.add(new Option(L.kr, 'landkreis'));
      selLevel.value = level;
      var selLand = document.createElement('select');
      selLand.add(new Option(L.pick, ''));
      order.forEach(function (n) { selLand.add(new Option(n, n)); });
      if (land) { selLand.value = land; }
      var selKreis = document.createElement('select');
      var fillKreis = function () {
        selKreis.innerHTML = '';
        selKreis.add(new Option(L.pick, ''));
        (lands[selLand.value] || []).forEach(function (k) { selKreis.add(new Option(k, k)); });
        if (kreis) { selKreis.value = kreis; }
      };
      fillKreis();
      var sync = function () {
        var v = 'de';
        if (selLevel.value === 'all') { v = ''; }
        else if (selLevel.value === 'de') { v = 'de'; }
        else if (selLevel.value === 'bundesland') { v = selLand.value ? 'bl:' + selLand.value : ''; }
        else if (selLevel.value === 'landkreis') { v = (selLand.value && selKreis.value) ? 'kr:' + selLand.value + ':' + selKreis.value : ''; }
        native.value = v;
        selLand.hidden = !(selLevel.value === 'bundesland' || selLevel.value === 'landkreis');
        selKreis.hidden = selLevel.value !== 'landkreis';
      };
      selLevel.addEventListener('change', sync);
      selLand.addEventListener('change', function () { kreis = ''; fillKreis(); sync(); });
      selKreis.addEventListener('change', sync);
      native.style.display = 'none';
      native.parentNode.insertBefore(wrap, native);
      wrap.appendChild(selLevel); wrap.appendChild(selLand); wrap.appendChild(selKreis);
      sync();
    });

    document.querySelectorAll('[data-end-fields]').forEach(function (fs) {
      fs.querySelectorAll('[data-end-toggle]').forEach(function (box) {
        var part = fs.querySelector('[data-end-part="' + box.getAttribute('data-end-toggle') + '"]');
        if (!part) return;
        var apply = function () { part.hidden = !box.checked; };
        box.addEventListener('change', apply); apply();
      });
    });

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && location.hash && document.querySelector(location.hash + '.modal')) {
        location.hash = '';
      }
    });

    var base = document.body.getAttribute('data-base') || '';
    var figures = document.querySelectorAll('[data-topic]');
    if (figures.length > 0) {
      var ids = [];
      figures.forEach(function (el) { ids.push(el.getAttribute('data-topic')); });
      var groupSep = document.body.getAttribute('data-group-sep') || '';
      var groupNum = function (n) {
        return groupSep === '' ? String(n) : String(n).replace(/\B(?=(\d{3})+(?!\d))/g, groupSep);
      };
      var setNum = function (el, sel, value) {
        var node = el.querySelector(sel);
        if (node && node.textContent !== value) { node.textContent = value; }
      };
      var refresh = function () {
        fetch(base + '/api/topics?ids=' + encodeURIComponent(ids.join(',')), { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (data) {
            if (!data) { return; }
            figures.forEach(function (el) {
              var row = data[el.getAttribute('data-topic')];
              if (!row) { return; }
              var total = row.f + row.a;
              var pf = total > 0 ? Math.round(row.f * 100 / total) : 0;
              var pa = total > 0 ? 100 - pf : 0;
              var bf = el.querySelector('[data-bar="for"]');
              var ba = el.querySelector('[data-bar="against"]');
              if (bf) { bf.className = 'votebar-for w-' + pf; }
              if (ba) { ba.className = 'votebar-against w-' + pa; }
              setNum(el, '[data-num="for"]', groupNum(row.f));
              setNum(el, '[data-num="against"]', groupNum(row.a));
              if (row.n !== undefined) { setNum(el, '[data-num="neutral"]', groupNum(row.n)); }
              setNum(el, '[data-pct="for"]', '/ ' + pf + ' %');
              setNum(el, '[data-pct="against"]', '/ ' + pa + ' %');
            });
          })
          .catch(function () {});
      };
      var timer = setInterval(refresh, 12000);
      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { refresh(); }
      });
      window.addEventListener('pagehide', function () { clearInterval(timer); });
    }

    document.querySelectorAll('[data-similar-for]').forEach(function (box) {
      var input = document.querySelector(box.getAttribute('data-similar-for'));
      if (!input) { return; }
      var exclude = box.getAttribute('data-similar-not') || '';
      var wait = null;
      var lookup = function () {
        var q = input.value.trim();
        if (q.length < 6) { box.hidden = true; box.innerHTML = ''; return; }
        fetch(base + '/api/similar?q=' + encodeURIComponent(q) + (exclude ? '&not=' + encodeURIComponent(exclude) : ''),
              { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (rows) {
            if (!rows || rows.length === 0) { box.hidden = true; box.innerHTML = ''; return; }
            var list = document.createElement('ul');
            list.className = 'plain-list';
            rows.forEach(function (row) {
              var li = document.createElement('li');
              var a = document.createElement('a');
              a.href = base + '/topic/' + row.id;
              a.textContent = row.title;
              li.appendChild(a);
              list.appendChild(li);
            });
            var head = document.createElement('p');
            head.className = 'muted';
            head.textContent = box.getAttribute('data-similar-label') || '';
            box.innerHTML = '';
            box.appendChild(head);
            box.appendChild(list);
            box.hidden = false;
          })
          .catch(function () {});
      };
      input.addEventListener('input', function () {
        clearTimeout(wait);
        wait = setTimeout(lookup, 400);
      });
    });

    var profileUrl = document.body.getAttribute('data-profile-url');
    if (profileUrl) {
      fetch(profileUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.text() : null; })
        .then(function (text) {
          try {
            if (text) { sessionStorage.setItem('profil.yaml', text); }
          } catch (e) {}
        })
        .catch(function () {});
    }
    var logoutForms = document.querySelectorAll('form.js-logout');
    logoutForms.forEach(function (form) {
      form.addEventListener('submit', function () {
        try { sessionStorage.removeItem('profil.yaml'); } catch (e) {}
      });
    });

    var tapForm = document.getElementById('tap-form');
    if (tapForm && 'NDEFReader' in window) {
      var status = document.getElementById('tap-status');
      var done = false;
      var go = function () {
        if (!done) { done = true; tapForm.submit(); }
      };
      var startScan = function () {
        var reader = new NDEFReader();
        reader.addEventListener('reading', go);
        reader.addEventListener('readingerror', go);
        return reader.scan();
      };

      try {
        startScan().then(function () {

          if (status) { status.hidden = false; }
          tapForm.querySelectorAll('[data-nfc-hide]').forEach(function (b) { b.hidden = true; });
        }).catch(function () {  });
      } catch (e) {  }

      tapForm.addEventListener('submit', function (ev) {
        if (tapForm.getAttribute('data-armed') === '1') { return; }
        ev.preventDefault();
        tapForm.setAttribute('data-armed', '1');
        if (status) { status.hidden = false; }
        try {
          startScan().catch(go);
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
  <style>.t{fill:#111111}.m{stroke:#ffffff}@media(prefers-color-scheme:dark){.t{fill:#ffffff}.m{stroke:#111111}}</style>
  <rect class="t" width="24" height="24"/>
  <path class="m" d="M6.4 12.3l3.9 3.9 7.3-8.1" fill="none" stroke-width="2.8" stroke-linecap="square" stroke-linejoin="miter"/>
</svg>
SVG;

function flag_de(): string
{
    return '<svg class="flag" viewBox="0 0 60 36" aria-hidden="true" focusable="false">'
        . '<rect width="60" height="12" y="0" fill="#000000"/>'
        . '<rect width="60" height="12" y="12" fill="#dd0000"/>'
        . '<rect width="60" height="12" y="24" fill="#ffcc00"/></svg>';
}

function flag_en(): string
{
    return '<svg class="flag" viewBox="0 0 60 36" aria-hidden="true" focusable="false">'
        . '<rect width="60" height="36" fill="#012169"/>'
        . '<path d="M0 0L60 36M60 0L0 36" stroke="#ffffff" stroke-width="7"/>'
        . '<path d="M0 0L60 36M60 0L0 36" stroke="#c8102e" stroke-width="3"/>'
        . '<path d="M30 0V36M0 18H60" stroke="#ffffff" stroke-width="12"/>'
        . '<path d="M30 0V36M0 18H60" stroke="#c8102e" stroke-width="7"/></svg>';
}

function icon_links(): string
{
    return '<link rel="icon" type="image/svg+xml" href="' . e(url('/a/icon.svg')) . '">'
        . '<link rel="icon" type="image/png" sizes="32x32" href="' . e(url('/favicon.png')) . '">'
        . '<link rel="apple-touch-icon" sizes="180x180" href="' . e(url('/apple-touch-icon.png')) . '">';
}

function png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function icon_png(int $size): string
{
    $ss = 3;
    $n = $size * $ss;
    $bg = [17, 17, 17];
    $fg = [255, 255, 255];
    $a1 = [0.265 * $n, 0.515 * $n];
    $b1 = [0.430 * $n, 0.680 * $n];
    $a2 = [0.430 * $n, 0.680 * $n];
    $b2 = [0.735 * $n, 0.340 * $n];
    $half = 0.058 * $n;

    $inBar = static function (float $px, float $py, array $a, array $b, float $half): bool {
        $vx = $b[0] - $a[0];
        $vy = $b[1] - $a[1];
        $len = sqrt($vx * $vx + $vy * $vy);
        if ($len <= 0.0) {
            return false;
        }
        $ux = $vx / $len;
        $uy = $vy / $len;
        $wx = $px - $a[0];
        $wy = $py - $a[1];
        $along = $wx * $ux + $wy * $uy;
        $perp = abs($wx * -$uy + $wy * $ux);
        return $perp <= $half && $along >= -$half && $along <= $len + $half;
    };

    $raw = '';
    for ($y = 0; $y < $size; $y++) {
        $raw .= "\x00";
        for ($x = 0; $x < $size; $x++) {
            $rSum = 0; $gSum = 0; $bSum = 0;
            for ($sy = 0; $sy < $ss; $sy++) {
                for ($sx = 0; $sx < $ss; $sx++) {
                    $px = $x * $ss + $sx + 0.5;
                    $py = $y * $ss + $sy + 0.5;
                    $on = $inBar($px, $py, $a1, $b1, $half) || $inBar($px, $py, $a2, $b2, $half);
                    $col = $on ? $fg : $bg;
                    $rSum += $col[0]; $gSum += $col[1]; $bSum += $col[2];
                }
            }
            $total = $ss * $ss;
            $raw .= chr((int) round($rSum / $total)) . chr((int) round($gSum / $total))
                . chr((int) round($bSum / $total)) . chr(255);
        }
    }
    $ihdr = pack('NN', $size, $size) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0);
    return "\x89PNG\r\n\x1a\n"
        . png_chunk('IHDR', $ihdr)
        . png_chunk('IDAT', gzcompress($raw, 9))
        . png_chunk('IEND', '');
}

function icon_ico(int $size = 32): string
{
    $png = icon_png($size);
    $dim = $size >= 256 ? 0 : $size;
    return pack('vvv', 0, 1, 1)
        . chr($dim) . chr($dim) . chr(0) . chr(0)
        . pack('vv', 1, 32)
        . pack('VV', strlen($png), 22)
        . $png;
}

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

        for ($i = 0; $i <= 100; $i++) {
            echo "\n.w-" . $i . ' { flex-grow: ' . $i . '; }';
        }
    }
    exit;
}

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
    $b = base_path();
    $a = SW::$base;

    $html = '<!DOCTYPE html><html lang="' . e(SW::$lang) . '"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="referrer" content="no-referrer">'
        . '<title>' . e($title) . ' · ' . e((string) $cfg['app_name']) . '</title>'
        . '<link rel="stylesheet" href="' . e(url('/a/app.css')) . '">'
        . icon_links()
        . '<script src="' . e(url('/a/app.js')) . '" defer></script>'
        . '</head><body data-base="' . e(base_path()) . '" data-group-sep="' . e(num(1000) === '1.000' ? '.' : ',') . '"'
        . ($user !== null ? ' data-profile-url="' . e(url('/profil.yaml')) . '"' : '') . '>';
    if (!empty($cfg['show_official_banner'])) {
        $html .= '<div class="test-banner" role="note">' . e(t('banner.official')) . '</div>';
    }
    $html .= '<a class="skip-link" href="#main">' . e(t('a11y.skip')) . '</a>'
        . '<header class="site-header"><div class="shell header-inner">'
        . '<a class="btn btn-ghost btn-sm btn-icon home-link" href="' . e(url('/')) . '" title="' . e(t('footer.home')) . '" aria-label="' . e(t('footer.home')) . '">' . icon_home() . '</a>'
        . '<div class="header-controls">';
    $html .= SW::$headerExtra;
    if (test_mode() && $user !== null && (string) $cfg['testmode_end'] === 'gui') {
        $html .= '<a class="testmode-chip" href="' . e(url('/setup')) . '">' . e(t('testmode.chip')) . '</a>';
    }
    if ($user === null) {
        if (SW::$path !== '/auth') {
            $html .= '<a class="btn btn-primary btn-sm" href="' . e(url('/auth')) . '">' . e(t('auth.login')) . '</a>';
        }
    } else {
        $html .= '<a class="nav-duty" href="' . e(url('/jury')) . '"' . ($duty !== null ? '' : ' hidden') . '>' . e(t('nav.jury')) . ($duty !== null ? '<span class="duty-dot" aria-hidden="true"></span>' : '') . '</a>'
            . '<form method="post" action="' . e(url('/logout')) . '" class="js-logout">' . csrf_field()
            . '<button type="submit" class="btn btn-ghost btn-sm">' . e(t('auth.logout')) . '</button></form>';
    }
    $html .= '</div></div></header>'
        ;

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
        . '<nav class="footer-nav" aria-label="Footer">'
        . '<a href="' . e(url('/')) . '">' . e(t('footer.home')) . '</a>'
        . '<a href="' . e(url('/about')) . '">' . e(t('footer.about')) . '</a>'
        . '<a href="' . e(url('/imprint')) . '">' . e(t('footer.imprint')) . '</a>'
        . '<a href="' . e(url('/privacy')) . '">' . e(t('footer.privacy')) . '</a>'
        . '</nav></div></footer></body></html>';
    return $html;
}

function scope_text(array $row): string
{
    $name = (string) ($row['scope_name'] ?? '');
    return $name !== '' ? $name : t('scope.bund');
}

function p_topic_card(array $row): string
{
    $html = '<article class="card topic-card" data-topic="' . (int) $row['id'] . '"><div class="topic-card-meta">'
        . '<span class="badge">' . e(cat_name($row)) . '</span>'
        . '<span class="badge">' . e(scope_text($row)) . '</span></div>'
        . '<h3 class="topic-card-title"><a href="' . e(url('/topic/' . (int) $row['id'])) . '">' . e((string) $row['title']) . '</a></h3>'
        . '<p class="topic-card-goal">' . e((string) $row['goal']) . '</p>'
        . p_votebar((int) $row['votes_for'], (int) $row['votes_against'], true);
    return $html . '</article>';
}

function vote_label_key(string $choice): string
{
    return $choice === 'for' ? 'vote.for' : ($choice === 'against' ? 'vote.against' : 'vote.neutral');
}

function p_vote_button(string $choice, ?string $myVote): string
{
    $mine = $myVote === $choice;
    return '<button type="submit" name="choice" value="' . e($choice) . '"'
        . ' class="btn vote-btn' . ($mine ? ' is-active' : '') . '"'
        . ' aria-pressed="' . ($mine ? 'true' : 'false') . '">'
        . e(t(vote_label_key($choice))) . '</button>';
}

function p_vote_locked(?string $myVote): string
{
    $html = '<div class="vote-actions">';
    foreach (['for', 'neutral', 'against'] as $choice) {
        $mine = $myVote === $choice;
        $state = $myVote === null ? ' is-dim' : ($mine ? ' is-locked' : ' is-dim');
        $html .= '<button type="button" class="btn vote-btn' . $state . '" disabled'
            . ' aria-pressed="' . ($mine ? 'true' : 'false') . '">'
            . e(t(vote_label_key($choice))) . '</button>';
    }
    return $html . '</div>';
}

function p_votebar(int $for, int $against, bool $slim = false): string
{
    $total = $for + $against;
    $pctFor = $total > 0 ? (int) round($for * 100 / $total) : 0;
    $pctAgainst = $total > 0 ? 100 - $pctFor : 0;
    $cls = $slim ? 'votefig votefig-slim' : 'votefig';
    return '<div class="' . $cls . '" data-fig>'
        . '<div class="votebar" role="img" aria-label="' . e(t('vote.bar_aria')) . '">'
        . '<span class="votebar-for w-' . $pctFor . '" data-bar="for"></span>'
        . '<span class="votebar-against w-' . $pctAgainst . '" data-bar="against"></span>'
        . '</div></div>';
}

function p_vote_values(int $for, int $neutral, int $against): string
{
    $total = $for + $against;
    $pctFor = $total > 0 ? (int) round($for * 100 / $total) : 0;
    $pctAgainst = $total > 0 ? 100 - $pctFor : 0;
    return '<div class="votefig" data-fig>'
        . '<div class="votebar-legend">'
        . '<span><span class="dot dot-for" aria-hidden="true"></span>' . e(t('vote.for'))
        . ' <b data-num="for">' . e(num($for)) . '</b>'
        . '<span class="pct" data-pct="for">/ ' . $pctFor . ' %</span></span>'
        . '<span><span class="dot dot-neutral" aria-hidden="true"></span>' . e(t('vote.neutral'))
        . ' <b data-num="neutral">' . e(num($neutral)) . '</b></span>'
        . '<span><span class="dot dot-against" aria-hidden="true"></span>' . e(t('vote.against'))
        . ' <b data-num="against">' . e(num($against)) . '</b>'
        . '<span class="pct" data-pct="against">/ ' . $pctAgainst . ' %</span></span>'
        . '</div></div>';
}

function topic_end_fields(array $old): string
{
    $byDate = (bool) ($old['end_by_date'] ?? true);
    $byTarget = (bool) ($old['end_by_target'] ?? false);
    if (!$byDate && !$byTarget) {
        $byDate = true;
    }
    $defaultDate = substr(Clock::addDaysStr(Clock::nowStr(), 30), 0, 10);
    $date = ($old['end_date'] ?? '') !== '' ? (string) $old['end_date'] : $defaultDate;
    $value = ($old['end_value'] ?? '') !== '' ? (string) $old['end_value'] : '';
    $unit = ($old['end_unit'] ?? '') === 'percent' ? 'percent' : 'count';
    $minDate = substr(Clock::addDaysStr(Clock::nowStr(), 1), 0, 10);
    $maxDate = substr(Clock::addDaysStr(Clock::nowStr(), 365), 0, 10);
    return '<fieldset class="end-fields" data-end-fields><legend>' . e(t('topic.f_end')) . '</legend>'
        . '<label class="check"><input type="checkbox" name="end_by_date" value="1" data-end-toggle="date"'
        . ($byDate ? ' checked' : '') . '><span>' . e(t('topic.end_by_date')) . '</span></label>'
        . '<div class="end-row" data-end-part="date"' . ($byDate ? '' : ' hidden') . '>'
        . '<input type="date" name="end_date" value="' . e($date) . '" min="' . e($minDate) . '" max="' . e($maxDate) . '" aria-label="' . e(t('topic.end_by_date')) . '">'
        . '</div>'
        . '<label class="check"><input type="checkbox" name="end_by_target" value="1" data-end-toggle="target"'
        . ($byTarget ? ' checked' : '') . '><span>' . e(t('topic.end_by_target')) . '</span></label>'
        . '<div class="end-row" data-end-part="target"' . ($byTarget ? '' : ' hidden') . '>'
        . '<input type="number" name="end_value" min="1" max="100000000" value="' . e($value) . '" inputmode="numeric" placeholder="' . e(t('topic.end_value_ph')) . '" aria-label="' . e(t('topic.end_by_target')) . '">'
        . '<select name="end_unit" aria-label="' . e(t('topic.end_unit')) . '">'
        . '<option value="count"' . ($unit === 'count' ? ' selected' : '') . '>' . e(t('topic.end_unit_count')) . '</option>'
        . '<option value="percent"' . ($unit === 'percent' ? ' selected' : '') . '>' . e(t('topic.end_unit_percent')) . '</option>'
        . '</select></div>'
        . '</fieldset>';
}

function topic_form_html(array $errors, array $old, string $action, string $submitKey, ?int $selfId = null): string
{
    $formId = $selfId === null ? 'new' : ('e' . $selfId);
    $html = '';
    if ($errors !== []) {
        $html .= '<div class="flash flash-error" role="alert"><ul class="plain-list">';
        foreach ($errors as $error) {
            $html .= '<li>' . e(t($error)) . '</li>';
        }
        $html .= '</ul></div>';
    }
    $html .= '<form class="form-stack" method="post" action="' . e(url($action)) . '">' . csrf_field()
        . '<label><span>' . e(t('topic.f_title')) . '</span>'
        . '<input type="text" id="title-' . e($formId) . '" name="title" required minlength="' . SW_TITLE_MIN . '" maxlength="' . SW_TITLE_MAX . '" value="' . e((string) $old['title']) . '"></label>'
        . '<div class="similar-hint" hidden data-similar-for="#title-' . e($formId) . '"'
        . ($selfId !== null ? ' data-similar-not="' . (int) $selfId . '"' : '')
        . ' data-similar-label="' . e(t('topic.similar_hint')) . '"></div>'
        . '<label><span>' . e(t('topic.f_goal')) . '</span>'
        . '<textarea name="goal" rows="3" required minlength="' . SW_GOAL_MIN . '" maxlength="' . SW_GOAL_MAX . '">' . e((string) $old['goal']) . '</textarea></label>'
        . '<label><span>' . e(t('topic.f_reasoning')) . '</span>'
        . '<textarea name="reasoning" rows="5" required minlength="' . SW_REASONING_MIN . '" maxlength="' . SW_REASONING_MAX . '">' . e((string) $old['reasoning']) . '</textarea></label>'
        . '<div class="form-row"><label><span>' . e(t('topic.f_category')) . '</span><select name="category_id" required>'
        . '<option value="">' . e(t('topic.f_choose')) . '</option>';
    foreach (categories() as $category) {
        $sel = (int) $old['category_id'] === (int) $category['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $category['id'] . '"' . $sel . '>' . e(cat_name($category)) . '</option>';
    }
    $html .= '</select></label>'
        . '<label><span>' . e(t('topic.f_scope')) . '</span>'
        . scope_picker('scope', ($old['scope'] ?? 'de') !== '' ? (string) $old['scope'] : 'de', false)
        . '</label></div>'
        . topic_end_fields($old)
        . '<div><button type="submit" class="btn btn-primary">' . e(t($submitKey)) . '</button></div></form>';
    return $html;
}

function modal(string $id, string $title, string $inner): string
{
    return '<div class="modal" id="' . e($id) . '" role="dialog" aria-modal="true" aria-label="' . e($title) . '">'
        . '<a class="modal-backdrop" href="#" aria-label="' . e(t('common.close')) . '"></a>'
        . '<div class="modal-box"><div class="modal-head"><h2>' . e($title) . '</h2>'
        . '<a class="modal-close" href="#" aria-label="' . e(t('common.close')) . '">&times;</a></div>'
        . '<div class="modal-body">' . $inner . '</div></div></div>';
}

function v_main(array $formErrors = [], ?array $formOld = null): void
{
    $user = auth_user();
    $userId = $user === null ? null : (int) $user['id'];
    $html = '';

    $upcoming = $userId === null ? null : jury_upcoming_for($userId);
    if ($upcoming !== null) {
        $html .= '<div class="flash">' . e(t('me.jury_upcoming', ['date' => Clock::displayLocal((string) $upcoming['voting_starts_at'], t('common.date_format'))])) . '</div>';
    }

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
        'sort'     => in_array(query_str('sort', 10), ['new', 'top'], true) ? query_str('sort', 10) : 'net',
    ];
    $page = query_int('page', 1, 500, 1);
    $perPage = (int) SW::$cfg['page_size'];
    $result = topics_list($filters, $page, $perPage, $userId);
    $pages = max(1, (int) ceil($result['total'] / $perPage));

    $head = '';
    if ($userId !== null) {
        $head .= '<a class="btn btn-primary btn-icon" href="#modal-new" title="' . e(t('topic.new_title')) . '" aria-label="' . e(t('topic.new_title')) . '">' . icon_plus() . '</a>';
    }
    $head .= '<a class="btn btn-outline btn-icon" href="#modal-search" title="' . e(t('topics.search')) . '" aria-label="' . e(t('topics.search')) . '">' . icon_search() . '</a>';
    SW::$headerExtra = $head;
    $html .= '<div class="action-bar">';
    if ($filters['q'] !== '' || $filters['category'] !== '' || $filters['gebiet'] !== '') {
        $html .= '<a class="btn btn-ghost" href="' . e(url('/')) . '">' . e(t('topics.clear')) . '</a>';
    }
    $html .= '</div>';

    $chips = '';
    foreach ($userId === null ? [] : fav_list($userId) as $favorite) {
        if ($favorite['kind'] === 'topic') {
            if (($favorite['topic_title'] ?? null) === null) {
                continue;
            }
            $label = (string) $favorite['topic_title'];
            if (mb_strlen($label) > 34) {
                $label = mb_substr($label, 0, 33) . '…';
            }
            $href = url('/topic/' . (int) $favorite['ref']);
        } elseif ($favorite['kind'] === 'category') {
            $label = SW::$lang === 'de' ? (string) ($favorite['name_de'] ?? $favorite['ref']) : (string) ($favorite['name_en'] ?? $favorite['ref']);
            $href = url('/?category=' . rawurlencode((string) $favorite['ref']));
        } else {
            $gebiet = fav_to_gebiet((string) $favorite['ref']);
            if ($gebiet === null) {
                continue;
            }
            $parts = explode(':', (string) $favorite['ref'], 2);
            $label = $parts[0] === 'bund' || !isset($parts[1]) ? t('scope.bund') : $parts[1];
            $href = url('/?gebiet=' . rawurlencode($gebiet));
        }
        $chips .= '<a class="btn btn-ghost btn-sm fav-chip" href="' . e($href) . '">' . icon_bookmark(true) . e($label) . '</a>';
    }
    if ($chips !== '') {
        $html .= '<div class="fav-chips">' . $chips . '</div>';
    }

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
            $html .= '<a class="btn btn-ghost btn-sm" href="' . e(url('/' . $mkQuery($page - 1))) . '">&laquo; ' . e(t('topics.prev')) . '</a>';
        }
        $html .= '<span class="muted">' . e(t('topics.page_of', ['p' => $page, 'n' => $pages])) . '</span>';
        if ($page < $pages) {
            $html .= '<a class="btn btn-ghost btn-sm" href="' . e(url('/' . $mkQuery($page + 1))) . '">' . e(t('topics.next')) . ' &raquo;</a>';
        }
        $html .= '</nav>';
    }

    if ($userId !== null) {
        $old = $formOld ?? ['title' => '', 'goal' => '', 'reasoning' => '', 'category_id' => 0, 'scope' => 'de',
                            'end_by_date' => true, 'end_date' => '', 'end_by_target' => false,
                            'end_value' => '', 'end_unit' => 'count'];
        if (topic_has_posted_today($userId)) {
            $newInner = '<p class="muted">' . e(t('topic.posted_today'))
                . '<span class="countdown" data-countdown-to="' . e(Clock::nextLocalMidnightUtcStr()) . '" data-label="' . e(t('topic.next_in')) . '"></span></p>';
        } else {
            $newInner = topic_form_html($formErrors, $old, '/topics', 'topic.submit');
        }
        $html .= modal('modal-new', t('topic.new_title'), $newInner);
    }

    $searchInner = '<form class="form-stack" method="get" action="' . e(url('/')) . '">'
        . '<label><span>' . e(t('topics.search')) . '</span><input type="search" name="q" maxlength="80" value="' . e($filters['q']) . '"></label>'
        . '<label><span>' . e(t('topics.filter_category')) . '</span><select name="category">'
        . '<option value="">' . e(t('topics.filter_all')) . '</option>';
    foreach (categories() as $category) {
        $sel = $filters['category'] === $category['slug'] ? ' selected' : '';
        $searchInner .= '<option value="' . e((string) $category['slug']) . '"' . $sel . '>' . e(cat_name($category)) . '</option>';
    }
    $searchInner .= '</select></label>'
        . '<label><span>' . e(t('topic.f_scope')) . '</span>'
        . scope_picker('gebiet', $filters['gebiet'], true)
        . '</label>'
        . '<label><span>' . e(t('topics.sort')) . '</span><select name="sort">'
        . '<option value="net"' . ($filters['sort'] === 'net' ? ' selected' : '') . '>' . e(t('topics.sort_net')) . '</option>'
        . '<option value="new"' . ($filters['sort'] === 'new' ? ' selected' : '') . '>' . e(t('topics.sort_new')) . '</option>'
        . '<option value="top"' . ($filters['sort'] === 'top' ? ' selected' : '') . '>' . e(t('topics.sort_top')) . '</option>'
        . '</select></label>'
        . '<div><button type="submit" class="btn btn-primary">' . e(t('topics.apply')) . '</button></div>'
        . (lang_stored() === '' && lang_wanted() !== '' ? '<input type="hidden" name="lang" value="' . e(SW::$lang) . '">' : '')
        . '</form>';
    $html .= modal('modal-search', t('topics.search'), $searchInner);

    render(t('app.tagline'), $html);
}

function topic_end_text(array $topic): string
{
    if ($topic['status'] === 'archived') {
        return t('topic.archived_badge');
    }
    if ($topic['status'] === 'closed') {
        return t('topic.ended');
    }
    $date = $topic['end_date'] !== null
        ? Clock::displayLocal((string) $topic['end_date'] . ' 00:00:00', t('common.date_format'))
        : null;
    $target = $topic['end_target'] !== null ? (int) $topic['end_target'] : null;
    $have = num((int) $topic['votes_for'] + (int) $topic['votes_against']);
    if ($date !== null && $target !== null) {
        return t('topic.ends_both', ['date' => $date, 'have' => $have, 'target' => num($target)]);
    }
    if ($date !== null) {
        return t('topic.ends_on', ['date' => $date]);
    }
    if ($target !== null) {
        return t('topic.ends_count', ['have' => $have, 'target' => num($target)]);
    }
    return '';
}

function fav_menu(int $userId, int $topicId, string $catRef, string $catLabel, string $scopeRef, string $scopeLabel): string
{
    $back = '/topic/' . $topicId;
    $items = [
        ['topic', (string) $topicId, t('topic.this'), fav_is($userId, 'topic', (string) $topicId)],
        ['category', $catRef, $catLabel, fav_is($userId, 'category', $catRef)],
        ['scope', $scopeRef, $scopeLabel, fav_is($userId, 'scope', $scopeRef)],
    ];
    $any = false;
    foreach ($items as $item) {
        $any = $any || $item[3];
    }
    $html = '<details class="fav-menu"><summary class="fav-toggle' . ($any ? ' is-on' : '')
        . '" title="' . e(t('topic.remember')) . '" aria-label="' . e(t('topic.remember')) . '" role="button">'
        . icon_bookmark($any) . '</summary><div class="fav-pop">';
    foreach ($items as [$kind, $ref, $label, $on]) {
        $html .= '<form method="post" action="' . e(url('/favorite')) . '">' . csrf_field()
            . '<input type="hidden" name="kind" value="' . e($kind) . '">'
            . '<input type="hidden" name="ref" value="' . e($ref) . '">'
            . '<input type="hidden" name="return" value="' . e($back) . '">'
            . '<button type="submit" class="fav-item' . ($on ? ' is-on' : '') . '">'
            . '<span>' . e($label) . '</span>' . ($on ? icon_bookmark(true) : '')
            . '</button></form>';
    }
    return $html . '</div></details>';
}

function icon_cookie(): string
{
    return '<svg class="tap-icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
        . '<path d="M24 5a19 19 0 1 0 19 19 8 8 0 0 1-9.5-9.5A8 8 0 0 1 24 5Z" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>'
        . '<circle cx="18" cy="19" r="2.4" fill="currentColor"/>'
        . '<circle cx="16" cy="30" r="2.4" fill="currentColor"/>'
        . '<circle cx="27" cy="33" r="2.4" fill="currentColor"/>'
        . '<circle cx="29" cy="24" r="2" fill="currentColor"/>'
        . '</svg>';
}

function icon_bookmark(bool $filled): string
{
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<path d="M6.5 3h11a1 1 0 0 1 1 1v17l-6.5-4.4L5.5 21V4a1 1 0 0 1 1-1z"'
        . ' fill="' . ($filled ? 'currentColor' : 'none') . '" stroke="currentColor"'
        . ' stroke-width="1.9" stroke-linejoin="round"/></svg>';
}

function icon_plus(): string
{
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<path d="M12 5v14M5 12h14" fill="none" stroke="currentColor"'
        . ' stroke-width="1.9" stroke-linecap="round"/></svg>';
}

function icon_search(): string
{
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.9"/>'
        . '<path d="M19.5 19.5 15.7 15.7" fill="none" stroke="currentColor"'
        . ' stroke-width="1.9" stroke-linecap="round"/></svg>';
}

function icon_flag(): string
{
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<path d="M6 21V4m0 .5h11.5l-3 4 3 4H6" fill="none" stroke="currentColor"'
        . ' stroke-width="1.9" stroke-linejoin="round" stroke-linecap="round"/></svg>';
}

function icon_home(): string
{
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<path d="M4 11.5 12 4.5l8 7M6.5 10.2V19.5h11v-9.3" fill="none" stroke="currentColor"'
        . ' stroke-width="1.9" stroke-linejoin="round" stroke-linecap="round"/></svg>';
}

function icon_archive(): string
{
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<path d="M4.5 5h15v4h-15zM6 9v10.5h12V9M10 12.5h4" fill="none" stroke="currentColor"'
        . ' stroke-width="1.9" stroke-linejoin="round" stroke-linecap="round"/></svg>';
}

function v_topic(int $id): void
{
    $topic = topic_find($id);
    if ($topic === null) {
        v_error_404();
    }
    topic_close_if_due($topic);
    $topic = topic_find($id);
    if ($topic['status'] === 'removed') {
        $html = '<article class="card"><h1>' . e(t('topic.removed_title')) . '</h1>'
            . '<p class="muted">' . e(t('topic.removed_text')) . '</p>'
            . '<p><a class="btn btn-outline btn-sm" href="' . e(url('/')) . '">' . e(t('common.back_home')) . '</a></p></article>';
        render(t('topic.removed_title'), $html);
    }
    $user = auth_user();
    $userId = $user === null ? null : (int) $user['id'];
    $voteRow = $userId === null ? null : topic_user_vote_row($id, $userId);
    $myVote = $voteRow === null ? null : (string) $voteRow['choice'];
    $isAuthor = $userId !== null && (int) $topic['author_id'] === $userId;
    $openReport = report_open_for($id);
    $archived = $topic['status'] === 'archived';
    $closed = $topic['status'] !== 'active';
    $scopeRef = $topic['scope_level'] === 'bund' ? 'bund' : $topic['scope_level'] . ':' . (string) $topic['scope_name'];

    $barFor = (int) $topic['votes_for'];
    $barNeutral = (int) $topic['votes_neutral'];
    $barAgainst = (int) $topic['votes_against'];
    $html = '<article class="topic-detail">'
        . '<h1>' . e((string) $topic['title']) . '</h1>'
        . '<section class="card" data-topic="' . (int) $topic['id'] . '">'
        . '<div class="topic-card-meta">'
        . '<span class="badge">' . e(cat_name($topic)) . '</span>'
        . '<span class="badge">' . e(scope_text($topic)) . '</span>'
        . '<span class="badge">' . e(topic_end_text($topic)) . '</span>'
        . ($archived ? '<span class="badge badge-danger">' . e(t('topic.archived_badge')) . '</span>'
            : ($closed ? '<span class="badge badge-danger">' . e(t('topic.ended')) . '</span>' : ''))
        . '</div>'
        . '<p>' . nl2br(e((string) $topic['goal'])) . '</p>'
        . '<details class="fold"><summary>' . e(t('topic.reasoning_label')) . '</summary>'
        . '<p>' . nl2br(e((string) $topic['reasoning'])) . '</p></details>';
    if ($archived) {
        $html .= '<p class="muted">' . e(t('topic.archived_note')) . '</p>';
    } elseif ($user === null) {
        $html .= '<p><a class="btn btn-primary" href="' . e(url('/auth')) . '">' . e(t('vote.login_hint')) . '</a></p>';
    } elseif ($closed || ($voteRow !== null && $voteRow['locked'])) {
        $html .= p_vote_locked($myVote);
    } else {
        $html .= '<form class="vote-actions" method="post" action="' . e(url('/vote')) . '">' . csrf_field()
            . '<input type="hidden" name="topic_id" value="' . (int) $topic['id'] . '">'
            . p_vote_button('for', $myVote)
            . p_vote_button('neutral', $myVote)
            . p_vote_button('against', $myVote)
            . '</form>';
    }
    $html .= p_vote_values($barFor, $barNeutral, $barAgainst) . '</section>';
    $head = '';
    if ($user !== null) {
        $head .= fav_menu(
            (int) $user['id'],
            (int) $topic['id'],
            (string) $topic['category_slug'],
            cat_name($topic),
            $scopeRef,
            scope_text($topic)
        );
    }
    $locked = topic_has_votes((int) $topic['id']);
    if ($isAuthor && !$archived && !$locked) {
        $head .= '<a class="btn btn-ghost btn-sm btn-icon" href="#modal-del" title="' . e(t('topic.archive')) . '" aria-label="' . e(t('topic.archive')) . '">' . icon_archive() . '</a>';
    }
    if ($openReport !== null) {
        $head .= '<span class="btn btn-ghost btn-sm btn-icon" role="img" title="' . e(t('topic.report_open')) . '" aria-label="' . e(t('topic.report_open')) . '">' . icon_flag() . '</span>';
    } elseif ($user !== null && !$isAuthor && !$closed) {
        $head .= '<a class="btn btn-ghost btn-sm btn-icon" href="' . e(url('/report/' . (int) $topic['id'])) . '" title="' . e(t('topic.report_link')) . '" aria-label="' . e(t('topic.report_link')) . '">' . icon_flag() . '</a>';
    }
    SW::$headerExtra = $head;
    $similar = topics_similar((string) $topic['title'], (int) $topic['id'], 4);
    if ($similar !== []) {
        $html .= '<section class="similar"><h2 class="card-h">' . e(t('topic.similar')) . '</h2><ul class="plain-list">';
        foreach ($similar as $row) {
            $html .= '<li><a href="' . e(url('/topic/' . (int) $row['id'])) . '">' . e((string) $row['title']) . '</a></li>';
        }
        $html .= '</ul></section>';
    }
    $html .= '</article>';

    if ($isAuthor && !$locked && !$archived) {
        $delInner = '<p class="muted">' . e(t('topic.archive_confirm')) . '</p>'
            . '<form method="post" action="' . e(url('/topic/' . (int) $topic['id'] . '/archive')) . '">' . csrf_field()
            . '<div class="btn-row"><button type="submit" class="btn btn-danger">' . e(t('topic.archive')) . '</button>'
            . '<a class="btn btn-ghost" href="#">' . e(t('common.close')) . '</a></div></form>';
        $html .= modal('modal-del', t('topic.archive'), $delInner);
    }
    render((string) $topic['title'], $html);
}

function v_topic_edit(int $id, array $errors = [], ?array $old = null): void
{
    $user = require_user();
    $topic = topic_find($id);
    if ($topic === null) {
        v_error_404();
    }
    if ((int) $topic['author_id'] !== (int) $user['id']
        || in_array((string) $topic['status'], ['removed', 'archived'], true)) {
        flash('error', 'flash.not_author');
        redirect('/topic/' . $id);
    }
    if ($old === null) {
        $scopeVal = $topic['scope_level'] === 'bund' ? 'de'
            : ($topic['scope_level'] === 'bundesland' ? 'bl:' . $topic['scope_name'] : 'kr:');

        if ($topic['scope_level'] === 'landkreis') {
            foreach (sw_regions() as $land => $kreise) {
                if (in_array((string) $topic['scope_name'], $kreise, true)) {
                    $scopeVal = 'kr:' . $land . ':' . $topic['scope_name'];
                    break;
                }
            }
        }
        $old = [
            'title' => (string) $topic['title'],
            'goal' => (string) $topic['goal'],
            'reasoning' => (string) $topic['reasoning'],
            'category_id' => (int) $topic['category_id'],
            'scope' => $scopeVal,
            'end_by_date' => $topic['end_date'] !== null,
            'end_date' => (string) ($topic['end_date'] ?? ''),
            'end_by_target' => $topic['end_target'] !== null,
            'end_value' => (string) ($topic['end_target'] ?? ''),
            'end_unit' => 'count',
        ];
    }
    $html = '<h1>' . e(t('topic.edit')) . '</h1>'
        . '<div class="card">' . topic_form_html($errors, $old, '/topic/' . $id . '/edit', 'topic.save', $id)
        . '<p><a class="link-quiet" href="' . e(url('/topic/' . $id)) . '">' . e(t('report.cancel')) . '</a></p></div>';
    render(t('topic.edit'), $html);
}

function h_api_topics(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $ids = [];
    foreach (explode(',', query_str('ids', 400)) as $raw) {
        if (preg_match('/^\d{1,10}$/', trim($raw)) === 1) {
            $ids[] = (int) $raw;
        }
    }
    $ids = array_slice(array_unique($ids), 0, 50);
    if ($ids === []) {
        echo '{}';
        exit;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows = SW::$db->all(
        "SELECT t.id, t.status,
                (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'for') AS votes_for,
                (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'against') AS votes_against,
                (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'neutral') AS votes_neutral
         FROM topics t WHERE t.id IN ($marks)",
        $ids
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['id']] = [
            'f' => (int) $row['votes_for'],
            'a' => (int) $row['votes_against'],
            'n' => (int) $row['votes_neutral'],
            's' => (string) $row['status'],
        ];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function h_api_similar(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (auth_user() === null) {
        http_response_code(401);
        echo '[]';
        exit;
    }
    $title = query_str('q', SW_TITLE_MAX);
    $exclude = query_str('not', 10);
    $out = [];
    foreach (topics_similar($title, $exclude === '' ? null : (int) $exclude, 4) as $row) {
        $out[] = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function v_setup(array $errors = [], ?array $old = null): void
{
    require_user();
    if (!test_mode()) {
        redirect('/');
    }
    $old = $old ?? [
        'eid_mode' => (string) SW::$cfg['eid_mode'],
        'eid_server_url' => (string) SW::$cfg['eid_server_url'],
        'eid_server_cert' => (string) SW::$cfg['eid_server_cert'],
        'eid_server_key' => (string) SW::$cfg['eid_server_key'],
        'eid_client_url' => (string) SW::$cfg['eid_client_url'],
        'authorized_keys_url' => (string) SW::$cfg['authorized_keys_url'],
        'nect_start' => (string) (SW::$cfg['eid_providers']['nect']['start'] ?? ''),
    ];
    $html = '<h1>' . e(t('setup.title')) . '</h1>'
        ;
    $missing = array_values(array_filter(setup_ready(), static function (array $row): bool {
        return !$row[1];
    }));
    if ($missing !== []) {
        $html .= '<section class="card"><h2 class="card-h">' . e(t('setup.missing')) . '</h2><ul class="check-list">';
        foreach ($missing as [$key, $ok]) {
            $html .= '<li class="is-warn"><span aria-hidden="true">!</span>' . e(t($key)) . '</li>';
        }
        $html .= '</ul></section>';
    }
    $stable = authorized_count_stable();

    if ($errors !== []) {
        $html .= '<div class="flash flash-error" role="alert"><ul class="plain-list">';
        foreach ($errors as $error) {
            $html .= '<li>' . e(t($error)) . '</li>';
        }
        $html .= '</ul></div>';
    }

    $eid = $old['eid_mode'] === 'eid';
    $html .= '<form class="card form-stack" method="post" action="' . e(url('/setup/finish')) . '">' . csrf_field()
        . '<div class="path-set" role="radiogroup" aria-label="' . e(t('setup.path')) . '">'
        . '<p class="field-label">' . e(t('setup.path')) . '</p>'
        . '<input type="radio" id="path-eid" name="eid_mode" value="eid"' . ($eid ? ' checked' : '') . '>'
        . '<label class="path-label" for="path-eid">' . e(t('setup.path_eid')) . '</label>'
        . '<input type="radio" id="path-list" name="eid_mode" value="demo"' . ($eid ? '' : ' checked') . '>'
        . '<label class="path-label" for="path-list">' . e(t('setup.path_list')) . '</label>'
        . '<div class="form-stack only-eid">'
        . '<label><span>' . e(t('setup.f_server')) . '</span>'
        . '<input type="text" name="eid_server_url" inputmode="url" placeholder="https://…" value="' . e($old['eid_server_url']) . '"></label>'
        . '<label><span>' . e(t('setup.f_cert')) . '</span>'
        . '<input type="text" name="eid_server_cert" value="' . e($old['eid_server_cert']) . '"></label>'
        . '<label><span>' . e(t('setup.f_key')) . '</span>'
        . '<input type="text" name="eid_server_key" value="' . e($old['eid_server_key']) . '"></label>'
        . '<label><span>' . e(t('setup.f_client')) . '</span>'
        . '<input type="text" name="eid_client_url" value="' . e($old['eid_client_url']) . '"></label>'
        . '<label><span>' . e(t('setup.f_nect')) . '</span>'
        . '<input type="text" name="nect_start" inputmode="url" placeholder="https://…" value="' . e($old['nect_start']) . '"></label>'
        . '</div>'
        . '<div class="form-stack only-list">'
        . ($stable > 0 ? '' : '<p class="muted">' . e(t('setup.check_keys')) . '</p>')
        . '<label><span>' . e(t('setup.f_sync')) . '</span>'
        . '<input type="text" name="authorized_keys_url" inputmode="url" placeholder="https://…" value="' . e($old['authorized_keys_url']) . '"></label>'
        . '</div>'
        . '</div>'
        . '<div><button type="submit" class="btn btn-outline" formaction="' . e(url('/setup/check')) . '">'
        . e(t('setup.check_btn')) . '</button></div>';
    if ((string) SW::$cfg['testmode_end'] === 'gui') {
        $html .= '<hr class="hr-soft">'
            . '<label><span>' . e(t('setup.token')) . '</span>'
            . '<input type="text" name="token" autocomplete="off" spellcheck="false"></label>'
            . '<small class="muted">' . e(t('setup.token_hint')) . '</small>'
            . '<label class="check-label"><input type="checkbox" name="confirm" value="yes" required>'
            . '<span>' . e(t('setup.confirm')) . '</span></label>'
            . '<div><button type="submit" class="btn btn-danger btn-big"' . (setup_checked($old) ? '' : ' disabled') . '>'
            . e(t('setup.finish_btn')) . '</button></div>';
    } else {
        $html .= '<hr class="hr-soft"><p class="muted">' . e(t('setup.end_locked')) . '</p>';
    }
    $html .= '</form>';
    render(t('setup.title'), $html);
}

function h_setup_check(): void
{
    require_user();
    if (!test_mode()) {
        redirect('/');
    }
    if (!rate_allow('setup:' . ip_key(), 20, 600)) {
        flash('error', 'flash.rate_limited');
        redirect('/setup');
    }
    [$errors, $values] = setup_validate(setup_read_post());
    $errors = array_values(array_filter($errors, static function (string $key): bool {
        return $key !== 'setup.err_list_empty';
    }));
    if ($errors !== []) {
        v_setup($errors, $values);
    }
    unset($_SESSION['setup_check']);
    if ($values['eid_mode'] === 'eid') {
        if ($values['eid_server_url'] === '') {
            flash('info', 'flash.setup_no_server');
            v_setup([], $values);
        }
        $ok = setup_probe($values);
        flash($ok ? 'success' : 'error', $ok ? 'flash.setup_ok' : 'flash.setup_failed');
    } else {
        $count = setup_probe_list((string) $values['authorized_keys_url']);
        $ok = $count !== null && $count > 0;
        if ($count === null) {
            flash('error', 'flash.setup_list_failed');
        } elseif ($count === 0) {
            flash('error', 'setup.err_list_empty');
        } else {
            flash('success', 'flash.setup_list_ok', ['n' => num($count)]);
        }
    }
    if ($ok) {
        $_SESSION['setup_check'] = setup_check_stamp($values);
    }
    v_setup([], $values);
}

function h_setup_finish(): void
{
    require_user();
    if (!test_mode()) {
        redirect('/');
    }
    if ((string) SW::$cfg['testmode_end'] !== 'gui') {
        redirect('/setup');
    }
    if (!rate_allow('setup:' . ip_key(), 20, 600)) {
        flash('error', 'flash.rate_limited');
        redirect('/setup');
    }
    [$errors, $values] = setup_validate(setup_read_post());
    if (post_str('confirm', 10) !== 'yes') {
        $errors[] = 'flash.testmode_confirm';
    }
    $token = setup_token();
    if ($token === '' || !hash_equals($token, post_str('token', 80))) {
        log_line('SECURITY', 'setup_token_bad', []);
        $errors[] = 'setup.err_token';
    }
    if (!setup_checked($values)) {
        $errors[] = 'setup.err_check_first';
    }
    if ($errors !== []) {
        v_setup(array_values(array_unique($errors)), $values);
    }
    if (!setup_save($values)) {
        v_setup(['setup.err_save'], $values);
    }
    setup_apply($values);
    test_mode_end();
    unset($_SESSION['setup_check']);
    @unlink(setup_token_file());
    auth_logout();
    card_forget();
    flash('info', 'flash.testmode_ended');
    redirect('/auth');
}

function v_auth(): void
{
    if (auth_user() !== null) {
        redirect('/me');
    }

    $pictogram = '<svg class="tap-icon" viewBox="0 0 96 64" aria-hidden="true" focusable="false">'
        . '<rect x="4" y="10" width="56" height="38" rx="4" fill="none" stroke="currentColor" stroke-width="3"/>'
        . '<rect x="11" y="19" width="14" height="11" rx="2" fill="currentColor"/>'
        . '<line x1="11" y1="38" x2="46" y2="38" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>'
        . '<path d="M70 18a22 22 0 0 1 0 28" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>'
        . '<path d="M78 12a32 32 0 0 1 0 40" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>'
        . '<path d="M86 6a42 42 0 0 1 0 52" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>'
        . '</svg>';
    $mode = (string) SW::$cfg['eid_mode'];
    $card = card_load();
    $ready = $mode !== 'eid' && $card !== null && authorized_contains(card_identity($card));

    if (!consent_given()) {
        render(t('consent.title'), '<section class="card auth-card">' . icon_cookie()
            . '<h1>' . e(t('consent.title')) . '</h1>'
            . '<p class="muted">' . e(t('consent.text')) . '</p>'
            . (test_mode() ? '<p class="muted">' . e(t('consent.test')) . '</p>' : '')
            . '<div class="consent-actions">'
            . '<a class="btn btn-primary btn-big" href="' . e(url('/consent?to=/auth')) . '">'
            . e(t('consent.accept')) . '</a></div></section>');
    }

    $html = '<section class="card auth-card">' . $pictogram
        . '<h1>' . e(t('auth.title')) . '</h1>';


    if (test_mode()) {

        $html .= '<form class="auth-action" method="post" action="' . e(url('/tap')) . '">' . csrf_field()
            . '<button type="submit" class="btn btn-primary btn-big">' . e(t('auth.test_login')) . '</button></form>';
    } else {

        $html .= '<div class="provider-list">';
        foreach ((array) SW::$cfg['eid_providers'] as $key => $prov) {
            $html .= '<a class="btn btn-primary provider-btn" href="' . e(url('/eid/start?provider=' . rawurlencode($key))) . '">'
                . e(t('auth.with', ['app' => (string) $prov['label']])) . '</a>';
        }
        $html .= '</div>';
        if ($ready) {

            $html .= '<hr class="hr-soft">'
                . '<form id="tap-form" class="auth-action" method="post" action="' . e(url('/tap')) . '">' . csrf_field()
                . '<button type="submit" class="btn btn-outline btn-big" data-nfc-hide>' . e(t('auth.tap')) . '</button></form>'
                . '<p id="tap-status" class="tap-status" hidden aria-live="polite">' . e(t('auth.hold')) . '</p>';
        }
    }
    $html .= '</section>';
    render(t('auth.title'), $html);
}

function site_url(string $path = ''): string
{
    $scheme = sw_is_https() ? 'https' : 'http';
    $host = trim((string) SW::$cfg['domain']);
    if ($host === '') {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    }
    if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?(:[0-9]{1,5})?$/', $host) !== 1) {
        $host = 'localhost';
    }
    return $scheme . '://' . $host . base_path() . $path;
}

function h_eid_start(): void
{
    $key = query_str('provider', 30);
    $providers = (array) SW::$cfg['eid_providers'];
    if (!isset($providers[$key])) {
        flash('error', 'flash.eid_required');
        redirect('/auth');
    }
    if (!rate_allow('eid:' . ip_key(), 20, 600)) {
        flash('error', 'flash.rate_limited');
        redirect('/auth');
    }
    if ($key === 'ausweisapp') {

        $nonce = bin2hex(random_bytes(16));
        eid_flow_start($nonce);
        $tcToken = site_url('/eid/tctoken?s=' . $nonce);
        $client = (string) SW::$cfg['eid_client_url'];
        log_line('SECURITY', 'eid_client_activation', ['provider' => 'ausweisapp']);
        header('Location: ' . $client . '?tcTokenURL=' . rawurlencode($tcToken), true, 303);
        exit;
    }
    $start = (string) ($providers[$key]['start'] ?? '');
    if ($start === '' || preg_match('#^https://#', $start) !== 1) {

        flash('error', 'flash.eid_provider_off');
        redirect('/auth');
    }

    $sep = strpos($start, '?') === false ? '?' : '&';
    header('Location: ' . $start . $sep . 'redirect=' . rawurlencode(site_url('/eid/callback')), true, 303);
    exit;
}

function h_eid_tctoken(): void
{
    header('Content-Type: text/xml; charset=utf-8');
    header('Cache-Control: no-store');
    $nonce = query_str('s', 40);
    $errorUrl = site_url('/eid/callback?e=1');
    $flow = eid_flow_find($nonce);
    if ($flow === null) {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<TCTokenType><CommunicationErrorAddress>' . e($errorUrl)
            . '</CommunicationErrorAddress></TCTokenType>';
        exit;
    }
    $session = eid_server_useid();
    if ($session === null) {

        log_line('SECURITY', 'eid_tctoken_unconfigured', []);
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<TCTokenType><CommunicationErrorAddress>' . e($errorUrl)
            . '</CommunicationErrorAddress></TCTokenType>';
        exit;
    }
    eid_flow_bind($nonce, $session['session']);
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<TCTokenType>'
        . '<ServerAddress>' . e($session['paos']) . '</ServerAddress>'
        . '<SessionIdentifier>' . e($session['session']) . '</SessionIdentifier>'
        . '<RefreshAddress>' . e(site_url('/eid/callback')) . '</RefreshAddress>'
        . '<CommunicationErrorAddress>' . e($errorUrl) . '</CommunicationErrorAddress>'
        . '<Binding>urn:liberty:paos:2006-08</Binding>'
        . '</TCTokenType>';
    exit;
}

function eid_flow_start(string $nonce): void
{
    SW::$db->run('DELETE FROM eid_flows WHERE created_at < ?', [Clock::now()->getTimestamp() - 600]);
    SW::$db->run(
        'INSERT INTO eid_flows (nonce, session_id, created_at) VALUES (?, ?, ?)',
        [$nonce, session_id(), Clock::now()->getTimestamp()]
    );
}

function eid_flow_find(string $nonce): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
        return null;
    }
    $row = SW::$db->one('SELECT * FROM eid_flows WHERE nonce = ?', [$nonce]);
    if ($row === null || (int) $row['created_at'] < Clock::now()->getTimestamp() - 600) {
        return null;
    }
    return $row;
}

function eid_flow_bind(string $nonce, string $ref): void
{
    SW::$db->run('UPDATE eid_flows SET eid_ref = ? WHERE nonce = ?', [$ref, $nonce]);
}

function eid_server_useid(?array $over = null): ?array
{
    $cfg = $over ?? SW::$cfg;
    $url = (string) ($cfg['eid_server_url'] ?? '');
    if ($url === '' || preg_match('#^https://#', $url) !== 1) {
        return null;
    }
    $soap = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
        . ' xmlns:eid="http://bsi.bund.de/eID/"><soap:Body><eid:useIDRequest>'
        . '<eid:UseOperations><eid:RestrictedIdentification eid:required="REQUIRED"/></eid:UseOperations>'
        . '</eid:useIDRequest></soap:Body></soap:Envelope>';
    $ctx = ['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: text/xml; charset=utf-8\r\nSOAPAction: \"\"\r\n",
        'content' => $soap,
        'timeout' => 8,
        'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]];
    if ((string) ($cfg['eid_server_cert'] ?? '') !== '') {
        $ctx['ssl']['local_cert'] = (string) $cfg['eid_server_cert'];
    }
    if ((string) ($cfg['eid_server_key'] ?? '') !== '') {
        $ctx['ssl']['local_pk'] = (string) $cfg['eid_server_key'];
    }
    $xml = @file_get_contents($url, false, stream_context_create($ctx));
    if (!is_string($xml) || $xml === '') {
        log_line('SECURITY', 'eid_server_unreachable', []);
        return null;
    }
    $paos = eid_xml_value($xml, 'eCardServerAddress');
    $session = eid_xml_value($xml, 'Session');
    if ($session === '') {
        $session = eid_xml_value($xml, 'ID');
    }
    if ($paos === '' || $session === '') {
        log_line('SECURITY', 'eid_server_bad_response', []);
        return null;
    }
    return ['paos' => $paos, 'session' => $session];
}

function eid_xml_value(string $xml, string $name): string
{
    $pattern = '#<(?:[A-Za-z0-9_.-]+:)?' . preg_quote($name, '#') . '(?:\s[^>]*)?>([^<]*)</#';
    return preg_match($pattern, $xml, $m) === 1 ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) : '';
}

function h_eid_callback(): void
{
    $flow = SW::$db->one(
        'SELECT * FROM eid_flows WHERE session_id = ? ORDER BY created_at DESC LIMIT 1',
        [session_id()]
    );
    SW::$db->run('DELETE FROM eid_flows WHERE session_id = ?', [session_id()]);
    if ($flow === null || ($flow['eid_ref'] ?? '') === '' || query_str('e', 4) !== '') {
        flash('error', 'flash.eid_required');
        redirect('/auth');
    }

    log_line('SECURITY', 'eid_callback_unverified', []);
    flash('error', 'flash.eid_required');
    redirect('/auth');
}

function v_start(): void
{
    http_response_code(200);
    $to = consent_return();
    $banner = empty(SW::$cfg['show_official_banner'])
        ? ''
        : '<div class="test-banner" role="note">' . e(SW_DE['banner.official']) . ' / ' . e(SW_EN['banner.official']) . '</div>';
    echo '<!DOCTYPE html><html lang="de"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="referrer" content="no-referrer">'
        . '<title>' . e((string) SW::$cfg['app_name']) . '</title>'
        . '<link rel="stylesheet" href="' . e(url('/a/app.css')) . '">'
        . icon_links()
        . '</head><body>' . $banner
        . '<main class="start-gate">'
        . '<p class="start-brand">' . e((string) SW::$cfg['app_name']) . '</p>'
        . '<div class="start-langs">'
        . '<a class="lang-btn" lang="de" href="' . e(base_path() . '/lang?set=de&to=' . rawurlencode($to)) . '">'
        . flag_de() . '<span>Deutsch</span></a>'
        . '<a class="lang-btn" lang="en" href="' . e(base_path() . '/lang?set=en&to=' . rawurlencode($to)) . '">'
        . flag_en() . '<span>English</span></a>'
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
        $html .= '<p>' . nl2br(e(t($key)), false) . '</p>';
    }
    $html .= '</section>';
    render(t($titleKey), $html);
}

function yq(string $v): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

function profile_yaml(array $user): string
{
    $userId = (int) $user['id'];
    $y = "buergerabstimmung_profil:\n";
    $y .= "  oeffentlicher_schluessel: " . yq((string) $user['pseudonym_hash']) . "\n";
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
        $art = ['category' => 'kategorie', 'topic' => 'thema'][$favorite['kind']] ?? 'gebiet';
        $y .= "    - art: " . yq($art) . "\n";
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

function server_sign_pk_hex(): string
{
    if (!card_supports_sodium() || SW::$serverSign === '') {
        return '';
    }
    return bin2hex(sodium_crypto_sign_publickey_from_secretkey(SW::$serverSign));
}

function profile_sealed(array $user): string
{
    $plain = profile_yaml($user);
    $pkHex = (string) $user['pseudonym_hash'];
    if (!card_supports_sodium() || SW::$serverSign === '' || strlen(@hex2bin($pkHex) ?: '') !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {

        $sig = sw_hmac('profil|' . $plain);
        return $plain . "# integritaet(hmac-sha256): " . $sig . "\n";
    }
    $edPk = hex2bin($pkHex);
    $curvePk = sodium_crypto_sign_ed25519_pk_to_curve25519($edPk);
    $cipher = sodium_crypto_box_seal($plain, $curvePk);
    $sig = sodium_crypto_sign_detached($cipher, SW::$serverSign);
    $out = "buergerabstimmung_versiegeltes_profil:\n";
    $out .= "  hinweis: " . yq('An oeffentlichen Ausweis-Schluessel verschluesselt; nur mit dem Ausweis lesbar.') . "\n";
    $out .= "  verschluesselt_fuer: " . yq($pkHex) . "\n";
    $out .= "  server_schluessel: " . yq(server_sign_pk_hex()) . "\n";
    $out .= "  chiffre_b64: " . yq(base64_encode($cipher)) . "\n";
    $out .= "  server_signatur_b64: " . yq(base64_encode($sig)) . "\n";
    return $out;
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

    $law = SW_LAWS[(string) $duty['criteria']] ?? null;
    $tally = jury_tally((int) $duty['id']);
    $deadline = jury_deadline($duty);

    $html .= '<p>' . e(t('jury.intro')) . '</p>'
        . '<div class="flash">' . e(t('jury.blocked')) . '</div>'
        . '<section class="card"><h2 class="field-label">' . e(t('jury.reported')) . '</h2>'
        . '<h3>' . e((string) $duty['title']) . '</h3>'
        . '<p class="field-label">' . e(t('topic.goal_label')) . '</p><p>' . nl2br(e((string) $duty['goal'])) . '</p>'
        . '<p class="field-label">' . e(t('topic.reasoning_label')) . '</p><p>' . nl2br(e((string) $duty['reasoning'])) . '</p></section>'
        . '<section class="card"><h2 class="field-label">' . e(t('jury.law')) . '</h2>';
    if ($law !== null) {
        $html .= '<p><strong>' . e($law['norm']) . ' – ' . e($law['titel']) . '</strong></p>'
            . '<p class="law-quote">„' . e($law['text']) . '“</p>';
    } else {
        $html .= '<p class="muted">' . e((string) $duty['criteria']) . '</p>';
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
    $q = query_str('q', 80);
    $hits = law_search($q);
    $html = '<h1>' . e(t('report.title')) . '</h1>'
        . '<p class="muted">' . e(t('report.intro')) . '</p>'
        . '<section class="card"><p class="field-label">' . e(t('jury.reported')) . '</p>'
        . '<h2>' . e((string) $topic['title']) . '</h2></section>'
        . '<form class="card form-stack" method="get" action="' . e(url('/report/' . (int) $topic['id'])) . '">'
        . '<label><span>' . e(t('report.search')) . '</span>'
        . '<input type="search" name="q" maxlength="80" value="' . e($q) . '"></label>'
        . '<div><button type="submit" class="btn btn-outline">' . e(t('topics.apply')) . '</button></div>'
        . '</form>';
    if ($q !== '' && $hits === []) {
        $html .= '<p class="muted">' . e(t('report.none_found')) . '</p>';
    }
    if ($hits !== []) {
        $html .= '<form class="card form-stack" method="post" action="' . e(url('/report')) . '">' . csrf_field()
            . '<input type="hidden" name="topic_id" value="' . (int) $topic['id'] . '">'
            . '<fieldset class="criteria-set"><legend>' . e(t('report.pick')) . '</legend>';
        foreach ($hits as $id => $law) {
            $html .= '<label class="check-label"><input type="radio" name="law" value="' . e($id) . '" required>'
                . '<span><strong>' . e($law['norm']) . ' – ' . e($law['titel']) . '</strong><br>'
                . '<span class="law-quote">„' . e($law['text']) . '“</span></span></label>';
        }
        $html .= '</fieldset>'
            . '<p class="muted">' . e(t('report.process')) . '</p>'
            . '<div class="btn-row"><button type="submit" class="btn btn-primary">' . e(t('report.submit')) . '</button>'
            . '<a class="btn btn-ghost" href="' . e(url('/topic/' . (int) $topic['id'])) . '">' . e(t('report.cancel')) . '</a></div>'
            . '</form>';
    }
    render(t('report.title'), $html);
}

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
    if (test_mode()) {

        $card = card_create();
        authorized_add([card_identity($card)], 'test-login');
        test_key_add(card_identity($card));
    } else {

        if ((string) SW::$cfg['eid_mode'] === 'eid') {
            log_line('SECURITY', 'eid_not_configured', []);
            flash('error', 'flash.eid_required');
            redirect('/auth');
        }

        $card = card_load();
        if ($card === null) {
            flash('error', 'flash.no_card');
            redirect('/auth');
        }
    }

    $identity = card_identity($card);
    $sealed = card_seal($card, 'login:' . $identity);
    if (!card_open($card['pk'], $sealed, 'login:' . $identity)) {
        log_line('SECURITY', 'card_verify_failed', []);
        flash('error', 'flash.auth_failed');
        redirect('/auth');
    }

    if (!authorized_contains($identity)) {
        log_line('SECURITY', 'card_not_authorized', []);
        card_forget();
        flash('error', 'flash.card_not_authorized');
        redirect('/auth');
    }
    auth_login($identity);
    $_SESSION['auth_slot'] = time_slot();
    redirect('/');
}

function h_claim(string $handle): void
{
    if ((string) SW::$cfg['eid_mode'] === 'eid') {
        redirect('/auth');
    }
    if (preg_match('/^[a-f0-9]{16,64}$/', $handle) !== 1) {
        flash('error', 'flash.no_card');
        redirect('/auth');
    }
    $file = SW::$dataDir . '/issued/' . $handle . '.key';
    if (!is_file($file)) {
        flash('error', 'flash.no_card');
        redirect('/auth');
    }
    $secret = base64_decode(trim((string) file_get_contents($file)), true);
    if ($secret === false) {
        flash('error', 'flash.no_card');
        redirect('/auth');
    }
    $_SESSION['card'] = base64_encode($secret);
    flash('info', 'flash.card_ready');
    redirect('/auth');
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
    SW::$user = null;
    session_forget();
    redirect('/');
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
    flash('success', 'flash.vote_saved');
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
    [$errors, $old, $scope, $end] = topic_form_read();
    if ($errors !== []) {
        v_main($errors, $old);
    }
    try {
        $topicId = topic_create(
            $userId,
            $old['title'],
            $old['goal'],
            $old['reasoning'],
            (int) $old['category_id'],
            $scope[0],
            $scope[1],
            $end[0],
            $end[1],
            $end[2]
        );
    } catch (DomainException $e) {
        v_main([$e->getMessage()], $old);
        return;
    }
    flash('success', 'flash.topic_created');
    redirect('/topic/' . $topicId);
}

function topic_form_read(): array
{
    $old = [
        'title'       => post_str('title', SW_TITLE_MAX),
        'goal'        => post_str('goal', SW_GOAL_MAX, true),
        'reasoning'   => post_str('reasoning', SW_REASONING_MAX, true),
        'category_id' => post_int('category_id') ?? 0,
        'scope'         => post_str('scope', 160),
        'end_by_date'   => isset($_POST['end_by_date']),
        'end_date'      => post_str('end_date', 10),
        'end_by_target' => isset($_POST['end_by_target']),
        'end_value'     => post_str('end_value', 10),
        'end_unit'      => post_str('end_unit', 10),
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
    $end = parse_topic_end();
    if ($end === null) {
        $errors[] = 'topic.err_end';
    }
    return [$errors, $old, $scope, $end];
}

function h_topic_edit(int $topicId): void
{
    $user = require_user();
    require_card($user);
    [$errors, $old, $scope, $end] = topic_form_read();
    if ($errors !== []) {
        v_topic_edit($topicId, $errors, $old);
    }
    try {
        topic_update(
            $topicId,
            (int) $user['id'],
            $old['title'],
            $old['goal'],
            $old['reasoning'],
            (int) $old['category_id'],
            $scope[0],
            $scope[1],
            $end[0],
            $end[1],
            $end[2]
        );
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
        redirect('/topic/' . $topicId);
    }
    flash('success', 'flash.topic_updated');
    redirect('/topic/' . $topicId);
}

function h_topic_archive(int $topicId): void
{
    $user = require_user();
    require_card($user);
    try {
        topic_archive($topicId, (int) $user['id']);
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
        redirect('/topic/' . $topicId);
    }
    flash('success', 'flash.topic_archived');
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
    $lawId = post_str('law', 40);
    if (!isset(SW_LAWS[$lawId])) {
        flash('error', 'flash.report_no_law');
        redirect('/report/' . $topicId);
    }
    try {
        report_create($topicId, (int) $user['id'], $lawId);
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

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; "
        . "img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
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
        error_log('buergerabstimmung setup: ' . $e->getMessage());
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

    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $base = rtrim($scriptDir, '/');
    if ($base !== '' && preg_match('#^(/[A-Za-z0-9._~\-]+)+$#', $base) !== 1) {
        $base = '';
    }
    SW::$base = $base;

    $rawPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    if (preg_match('#(^|/)data(/|$)#', $rawPath) === 1) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo "404\n";
        exit;
    }
    $pathInfo = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($pathInfo !== '' && $pathInfo[0] === '/') {
        $path = $pathInfo;
    } else {
        $path = $rawPath;
        if (SW::$base !== '' && strpos($path, SW::$base) === 0) {
            $path = substr($path, strlen(SW::$base));
        }
        if (strpos($path, '/index.php') === 0) {
            $path = substr($path, strlen('/index.php'));
        }
    }
    SW::$path = $path === '' ? '/' : $path;
    $path = SW::$path;

    $method0 = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (($method0 === 'GET' || $method0 === 'HEAD') && strpos($rawPath, '/index.php') !== false) {
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        header('Location: ' . (base_path() . $path) . ($qs !== '' ? '?' . $qs : ''), true, 301);
        exit;
    }

    if (preg_match('#^/a/(app\.css|app\.js|icon\.svg)$#', $path, $m) === 1) {
        $kindMap = ['app.css' => 'css', 'app.js' => 'js', 'icon.svg' => 'icon'];
        serve_asset($kindMap[$m[1]]);
    }
    if ($path === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nDisallow: /\n";
        exit;
    }

    if (preg_match('#^/(favicon\.ico|favicon\.png|apple-touch-icon(?:-precomposed)?\.png|icon-192\.png)$#', $path, $mIcon) === 1) {
        $name = $mIcon[1];
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        if ($name === 'favicon.ico') {
            header('Content-Type: image/x-icon');
            echo icon_ico(32);
        } else {
            $size = $name === 'favicon.png' ? 32 : ($name === 'icon-192.png' ? 192 : 180);
            header('Content-Type: image/png');
            echo icon_png($size);
        }
        exit;
    }

    if ($path === '/server.pub') {
        header('Content-Type: text/plain; charset=utf-8');
        echo server_sign_pk_hex() . "\n";
        exit;
    }

    send_security_headers();
    session_boot();

    $user = auth_user();
    $lang = lang_active();
    if (!lang_valid($lang)) {
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

        $reading = $method === 'GET' || $method === 'HEAD';
        if ($path === '/consent' && $reading) {
            consent_set();
            redirect(consent_return());
        }
        if ($path === '/lang' && $reading) {
            $wish = query_str('set', 5);
            if (consent_given()) {
                lang_store($wish);
                redirect(consent_return());
            }
            redirect(consent_return() . (lang_valid($wish) ? '?lang=' . rawurlencode($wish) : ''));
        }

        $public = $path === '/' || preg_match('#^/topic/\d{1,10}$#', $path) === 1
            || in_array($path, ['/start', '/auth', '/about', '/imprint', '/privacy', '/api/topics'], true);
        if (!consent_given() && !($reading && $public)) {
            v_error(403, 'error.consent');
        }

        $langChosen = lang_stored() !== '' || lang_wanted() !== '';
        if ($path === '/start' && $reading) {
            if ($langChosen) {
                redirect('/');
            }
            v_start();
        }

        if ($method === 'POST' && !csrf_ok()) {
            log_line('SECURITY', 'csrf_failed', ['path' => $path]);
            flash('error', 'flash.csrf');
            redirect('/');
        }

        if ($user !== null && jury_pending_for((int) $user['id']) !== null) {
            $gateAllowed = ['/jury', '/jury/vote', '/logout', '/lang', '/about', '/imprint', '/privacy'];
            if (!in_array($path, $gateAllowed, true)) {
                redirect('/jury');
            }
        }

        $isGet = $method === 'GET' || $method === 'HEAD';

        if ($user === null && $isGet
            && !in_array($path, ['/', '/auth', '/about', '/imprint', '/privacy', '/api/topics', '/api/similar'], true)
            && preg_match('#^/topic/\d{1,10}$#', $path) !== 1
            && strpos($path, '/claim/') !== 0
            && strpos($path, '/eid/') !== 0) {
            redirect('/auth');
        }

        if ($path === '/' && $isGet) {
            v_main();
        }
        if (($path === '/topics' || $path === '/topics/new' || $path === '/me') && $isGet) {
            redirect('/');
        }
        if ($path === '/topics' && $method === 'POST') {
            h_topic_create();
        }
        if (preg_match('#^/topic/(\d{1,10})$#', $path, $m) === 1 && $isGet) {
            v_topic((int) $m[1]);
        }
        if (preg_match('#^/topic/(\d{1,10})/archive$#', $path, $m) === 1 && $method === 'POST') {
            h_topic_archive((int) $m[1]);
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
        if ($path === '/profil.yaml' && $isGet) {
            $u = require_user();
            header('Content-Type: text/yaml; charset=utf-8');
            header('Content-Disposition: attachment; filename="profil.yaml"');
            echo profile_sealed($u);
            exit;
        }

        if ($path === '/auth' && $isGet) {
            v_auth();
        }
        if (preg_match('#^/claim/([a-f0-9]{16,64})$#', $path, $m) === 1 && $isGet) {
            h_claim($m[1]);
        }
        if ($path === '/eid/start' && $isGet) {
            h_eid_start();
        }
        if ($path === '/eid/tctoken' && $isGet) {
            h_eid_tctoken();
        }
        if ($path === '/eid/callback' && $isGet) {
            h_eid_callback();
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
        if ($path === '/api/topics' && $isGet) {
            h_api_topics();
        }
        if ($path === '/api/similar' && $isGet) {
            h_api_similar();
        }
        if ($path === '/setup' && $isGet) {
            v_setup();
        }
        if ($path === '/setup/check' && $method === 'POST') {
            h_setup_check();
        }
        if ($path === '/setup/finish' && $method === 'POST') {
            h_setup_finish();
        }
        if ($path === '/about' && $isGet) {
            v_static('about.h', ['about.p1', 'about.p2', 'about.p3', 'about.p4', 'about.p5']);
        }
        if ($path === '/imprint' && $isGet) {
            v_static('imprint.h', ['imprint.p1', 'imprint.p2', 'imprint.p3']);
        }
        if ($path === '/privacy' && $isGet) {
            v_static('privacy.h', ['privacy.p1', 'privacy.p2', 'privacy.p3', 'privacy.p4', 'privacy.p5',
                'privacy.p6', 'privacy.p7', 'privacy.p8', 'privacy.p9', 'privacy.p10']);
        }
        v_error_404();
    } catch (Throwable $e) {
        log_line('ERROR', 'unhandled', ['type' => get_class($e), 'msg' => $e->getMessage(), 'path' => $path]);
        v_error(500, 'error.generic');
    }
}

function cli_switch_db(string $tmpDir, string $name): void
{
    putenv('BUERGERABSTIMMUNG_DB=' . $tmpDir . '/' . $name . '.sqlite');
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
    $endDate = substr(Clock::addDaysStr(Clock::nowStr(), 30), 0, 10);
    return topic_create($authorId, $title, 'Ein Ziel für den Selbsttest dieses Themas.', 'Eine Begründung für den Selbsttest dieses Themas.', $categoryId, 'bund', null, 'date', $endDate, null);
}

function cli_selftest(): int
{
    $tmpDir = sys_get_temp_dir() . '/buergerabstimmung-selftest-' . bin2hex(random_bytes(4));
    mkdir($tmpDir, 0700, true);
    $pass = 0;
    $fail = 0;
    $check = static function (string $description, bool $ok, string $note = '') use (&$pass, &$fail): void {
        if ($ok) {
            $pass++;
            echo "  ok  {$description}\n";
        } else {
            $fail++;
            echo "FAIL  {$description}" . ($note !== '' ? ': ' . $note : '') . "\n";
        }
    };
    SW::$pepper = bin2hex(random_bytes(32));
    SW::$dataDir = $tmpDir;
    if (card_supports_sodium()) {
        SW::$serverSign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    }
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

    echo "== Stimmen entkoppelt & 24h-Sperre ==\n";
    $vt1 = cli_add_users(1, 'vt')[0];
    $vtTopic = cli_make_topic($vt1, 'Thema für Stimmtests');
    vote_cast($vt1, $vtTopic, 'for');
    $check('Stimmen-Tabelle ohne Ausweis-Bezug (kein user_id)',
        SW::$db->val("SELECT COUNT(*) FROM pragma_table_info('votes') WHERE name = 'user_id'") == 0);
    $tag = vote_tag($vtTopic, user_pk($vt1));
    $check('Stimme unter HMAC-Marker gespeichert (nicht Klar-ID)',
        SW::$db->val('SELECT COUNT(*) FROM votes WHERE topic_id = ? AND voter_tag = ?', [$vtTopic, $tag]) == 1);
    vote_cast($vt1, $vtTopic, 'against');
    $check('Änderung innerhalb 24 h möglich', topic_user_vote($vtTopic, $vt1) === 'against');
    $warp('+25 hours');
    try {
        vote_cast($vt1, $vtTopic, 'for');
        $check('Änderung nach 24 h gesperrt', false);
    } catch (DomainException $e) {
        $check('Änderung nach 24 h gesperrt', $e->getMessage() === 'flash.vote_locked');
    }
    $warp('-25 hours');
    $vtN = cli_add_users(1, 'vn')[0];
    vote_cast($vtN, $vtTopic, 'neutral');
    $check('Enthalten wird als neutrale Stimme gespeichert', topic_user_vote($vtTopic, $vtN) === 'neutral');
    $check('Enthalten zählt nicht in Dafür/Dagegen',
        SW::$db->val("SELECT COUNT(*) FROM votes WHERE topic_id = ? AND choice IN ('for','against')", [$vtTopic]) == 1);
    try {
        vote_cast($vtN, $vtTopic, 'none');
        $check('Stimme löschen ist nicht möglich', false);
    } catch (DomainException $e) {
        $check('Stimme löschen ist nicht möglich', $e->getMessage() === 'flash.invalid_input');
    }

    echo "== Themen-Ende (Datum/Anzahl) & Autor-Rechte ==\n";
    $au = cli_add_users(1, 'au')[0];
    $catId = (int) SW::$db->val('SELECT id FROM categories ORDER BY id LIMIT 1');
    $countTopic = topic_create($au, 'Thema endet bei 2 Stimmen', 'Ziel für den Anzahl-Test hier.', 'Begründung für den Anzahl-Test hier.', $catId, 'bund', null, 'count', null, 2);
    $c1 = cli_add_users(1, 'c1')[0];
    $c2 = cli_add_users(1, 'c2')[0];
    vote_cast($c1, $countTopic, 'for');
    $check('Thema bei Zielzahl noch offen (1/2)', SW::$db->val('SELECT status FROM topics WHERE id = ?', [$countTopic]) === 'active');
    $cN = cli_add_users(1, 'cn')[0];
    vote_cast($cN, $countTopic, 'neutral');
    $check('Enthaltungen zählen nicht zum Zielwert', SW::$db->val('SELECT status FROM topics WHERE id = ?', [$countTopic]) === 'active');
    vote_cast($c2, $countTopic, 'against');
    $check('Thema schließt bei Erreichen der Zielzahl (2/2)', SW::$db->val('SELECT status FROM topics WHERE id = ?', [$countTopic]) === 'closed');
    try {
        vote_cast($au, $countTopic, 'for');
        $check('Keine Stimme nach Schließung', false);
    } catch (DomainException $e) {
        $check('Keine Stimme nach Schließung', $e->getMessage() === 'flash.topic_not_votable');
    }
    $warp('+1 day');
    $dateTopic = topic_create($au, 'Thema endet gestern', 'Ziel für den Datum-Test hier.', 'Begründung für den Datum-Test hier.', $catId, 'bund', null, 'date', substr(Clock::nowStr(), 0, 10), null);
    $warp('+2 days');
    maintenance_tick();
    $check('Datumsende schließt Thema', SW::$db->val('SELECT status FROM topics WHERE id = ?', [$dateTopic]) === 'closed');
    $warp('-3 days');
    $au2 = cli_add_users(1, 'au2')[0];
    $ownTopic = cli_make_topic($au2, 'Thema zum Bearbeiten und Löschen');
    topic_update($ownTopic, $au2, 'Neuer Titel nach Bearbeitung', 'Neues Ziel nach Bearbeitung hier.', 'Neue Begründung nach Bearbeitung hier.', $catId, 'bund', null, 'date', substr(Clock::addDaysStr(Clock::nowStr(), 10), 0, 10), null);
    $check('Autor kann bearbeiten', SW::$db->val('SELECT title FROM topics WHERE id = ?', [$ownTopic]) === 'Neuer Titel nach Bearbeitung');
    try {
        topic_update($ownTopic, $c1, 'Fremd', 'Fremdziel hier bitte.', 'Fremdbegründung hier bitte.', $catId, 'bund', null, 'date', substr(Clock::addDaysStr(Clock::nowStr(), 10), 0, 10), null);
        $check('Nicht-Autor kann nicht bearbeiten', false);
    } catch (DomainException $e) {
        $check('Nicht-Autor kann nicht bearbeiten', $e->getMessage() === 'flash.not_author');
    }
    vote_cast($c1, $ownTopic, 'for');
    try {
        topic_archive($ownTopic, $au2);
        $check('Abgestimmtes Thema bleibt dauerhaft bestehen', false);
    } catch (DomainException $e) {
        $check('Abgestimmtes Thema bleibt dauerhaft bestehen', $e->getMessage() === 'flash.topic_locked');
    }
    $check('Abgestimmtes Thema weiterhin aktiv',
        SW::$db->val('SELECT status FROM topics WHERE id = ?', [$ownTopic]) === 'active');
    $warp('+1 day');
    $freshTopic = cli_make_topic($au2, 'Thema ohne Stimmen zum Archivieren');
    topic_archive($freshTopic, $au2);
    $check('Thema ohne Stimmen wird archiviert, nicht gelöscht',
        SW::$db->val('SELECT status FROM topics WHERE id = ?', [$freshTopic]) === 'archived'
        && SW::$db->val('SELECT COUNT(*) FROM topics WHERE id = ?', [$freshTopic]) == 1);
    $check('Archiviertes Thema erscheint nicht in der Liste',
        !in_array($freshTopic, array_map(static function (array $r): int {
            return (int) $r['id'];
        }, topics_list([], 1, 50, null)['rows']), true));
    try {
        vote_cast($c1, $freshTopic, 'for');
        $check('Archiviertes Thema ist nicht wählbar', false);
    } catch (DomainException $e) {
        $check('Archiviertes Thema ist nicht wählbar', $e->getMessage() === 'flash.topic_not_votable');
    }
    try {
        topic_update($freshTopic, $au2, 'Neuer Titel im Archiv', 'Ziel im Archiv hier.', 'Begründung im Archiv hier.', $catId, 'bund', null, 'date', substr(Clock::addDaysStr(Clock::nowStr(), 10), 0, 10), null);
        $check('Archiviertes Thema ist nicht mehr bearbeitbar', false);
    } catch (DomainException $e) {
        $check('Archiviertes Thema ist nicht mehr bearbeitbar', $e->getMessage() === 'flash.not_author');
    }
    $warp('-1 day');
    $similar = topics_similar('Neuer Titel nach Bearbeitung', null, 5);
    $check('Ähnliches Thema wird gefunden', $similar !== [] && (int) $similar[0]['id'] === $ownTopic);
    $check('Unähnlicher Titel liefert nichts',
        topics_similar('Vollkommen anderes Anliegen ohne Bezug', null, 5) === []);
    $check('Eigenes Thema wird bei der Suche ausgeblendet',
        topics_similar('Neuer Titel nach Bearbeitung', $ownTopic, 5) === []);

    echo "== Profil: verschlüsselt an Public Key + Server-Signatur ==\n";
    if (card_supports_sodium()) {
        $pair = sodium_crypto_sign_keypair();
        $pkHex = bin2hex(sodium_crypto_sign_publickey($pair));
        SW::$db->run('INSERT INTO users (pseudonym_hash, lang, created_at) VALUES (?, ?, ?)', [$pkHex, 'de', Clock::nowStr()]);
        $pu = SW::$db->one('SELECT * FROM users WHERE pseudonym_hash = ?', [$pkHex]);
        $sealed = profile_sealed($pu);
        $check('Ausgeliefertes Profil enthält keinen Klartext-Schlüssel im Inhalt',
            strpos($sealed, 'buergerabstimmung_versiegeltes_profil') === 0);

        preg_match('/chiffre_b64: "([^"]+)"/', $sealed, $cm);
        preg_match('/server_signatur_b64: "([^"]+)"/', $sealed, $sm);
        $cipher = base64_decode($cm[1]);
        $sig = base64_decode($sm[1]);
        $serverPk = hex2bin(server_sign_pk_hex());
        $check('Server-Signatur bestätigt (keine Manipulation)',
            sodium_crypto_sign_verify_detached($sig, $cipher, $serverPk) === true);
        $check('Manipulierte Chiffre fällt bei Signaturprüfung durch',
            sodium_crypto_sign_verify_detached($sig, $cipher . 'x', $serverPk) === false);
        $curveSk = sodium_crypto_sign_ed25519_sk_to_curve25519(sodium_crypto_sign_secretkey($pair));
        $curvePk = sodium_crypto_sign_ed25519_pk_to_curve25519(sodium_crypto_sign_publickey($pair));
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($curveSk, $curvePk);
        $plain = sodium_crypto_box_seal_open($cipher, $keypair);
        $check('Nur mit dem Ausweis-Schlüssel entschlüsselbar', is_string($plain) && strpos($plain, 'buergerabstimmung_profil') === 0);
    } else {
        $check('Profil-Versiegelung übersprungen (kein sodium)', true);
        $check('Profil-Versiegelung übersprungen (kein sodium)', true);
        $check('Profil-Versiegelung übersprungen (kein sodium)', true);
        $check('Profil-Versiegelung übersprungen (kein sodium)', true);
    }

    echo "== Meldegrund: Gesetzesverstoß ==\n";
    $check('Suche nach Schlagwort findet Volksverhetzung', isset(law_search('hetze')['stgb-130-1']));
    $check('Suche nach Paragraph findet § 185', isset(law_search('185')['stgb-185']));
    $check('Leere Suche liefert nichts', law_search('') === []);
    try {
        report_create(999999, 1, 'kein-gesetz');
        $check('Unbekanntes Gesetz abgelehnt', false);
    } catch (DomainException $e) {
        $check('Unbekanntes Gesetz abgelehnt', $e->getMessage() === 'flash.report_no_law');
    }

    echo "== Geltungsbereich (amtliche Auswahl) ==\n";
    $check('Deutschland', scope_decode('de') === ['bund', null]);
    $check('Bundesland', scope_decode('bl:Bayern') === ['bundesland', 'Bayern']);
    $check('Landkreis', scope_decode('kr:Bayern:Landkreis München') === ['landkreis', 'Landkreis München']);
    $check('Unbekanntes Gebiet abgelehnt', scope_decode('kr:Bayern:Atlantis') === null && scope_decode('bl:Atlantis') === null);
    $check('Gebietsliste vollständig geladen', count(sw_regions()) === 16 && array_sum(array_map('count', sw_regions())) > 350);

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
    vote_cast($bob, $topicId, 'neutral');
    $check('Enthalten als dritte Stimmart speicherbar', topic_user_vote($topicId, $bob) === 'neutral');
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
    report_create($target, $reporter600, 'stgb-130-1');
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

    $r1 = report_create($tX, $users[0], 'stgb-86a-1');
    $j1 = $jurorsOf($r1);
    $check('Jury 1: 5 Sitze (Mindestgröße)', count($j1) === 5);
    $check('Melder und Autor nicht in der Jury', !in_array($users[0], $j1, true) && !in_array($users[8], $j1, true));
    $r1Row = SW::$db->one('SELECT * FROM reports WHERE id = ?', [$r1]);
    $check('Meldung wartet bis Mitternacht', $r1Row['status'] === 'pending');
    $check('Start zur nächsten Mitternacht (00:00 lokal)', $r1Row['voting_starts_at'] === Clock::nextLocalMidnightUtcStr());
    try {
        report_create($tX, $users[1], 'stgb-185');
        $check('Zweite Meldung zum selben Thema abgelehnt', false);
    } catch (DomainException $e) {
        $check('Zweite Meldung zum selben Thema abgelehnt', $e->getMessage() === 'flash.report_already_open');
    }
    $r2 = report_create($tY, $users[1], 'stgb-241-1');
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
    $r3 = report_create($tZ, $j2[0], 'stgb-126a-1');
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
    $r4 = report_create($tW, $j2[1], 'stgb-240-1');
    $j4 = $jurorsOf($r4);
    sort($j1);
    sort($j4);
    $check('Nach 3 Tagen Karenz wieder losbar (Jury 4 = frühere Jury 1)', $j4 === $j1);

    echo "== Strenge im Normalmodus / Skip im Testmodus ==\n";
    if (card_supports_sodium()) {
        $pairA = sodium_crypto_sign_keypair();
        $cardA = ['secret' => sodium_crypto_sign_secretkey($pairA), 'pk' => sodium_crypto_sign_publickey($pairA)];
        $pairB = sodium_crypto_sign_keypair();
        $cardB = ['secret' => sodium_crypto_sign_secretkey($pairB), 'pk' => sodium_crypto_sign_publickey($pairB)];
        $userA = ['pseudonym_hash' => card_identity($cardA)];
        SW::$testMode = false;
        $check('Normalmodus: ohne Ausweis abgelehnt', card_confirm_ok($userA, null, 'confirm:/vote') === false);
        $check('Normalmodus: fremder Ausweis abgelehnt', card_confirm_ok($userA, $cardB, 'confirm:/vote') === false);
        $check('Normalmodus: eigener Ausweis bestätigt', card_confirm_ok($userA, $cardA, 'confirm:/vote') === true);
        SW::$testMode = true;
        $check('Testmodus: Ausweis-Aufforderung entfällt', card_confirm_ok($userA, null, 'confirm:/vote') === true);
        SW::$testMode = false;
    } else {
        for ($i = 0; $i < 5; $i++) {
            $check('Strenge-Prüfung übersprungen (kein sodium)', true);
        }
    }

    echo "== Allowlist autorisierter Ausweis-Schlüssel ==\n";
    @unlink(authorized_file());
    $check('Leere Allowlist weist alles ab', authorized_contains(str_repeat('a', 64)) === false);
    $goodPk = str_repeat('b', 64);
    authorized_add([$goodPk], 'test');
    $check('Autorisierter Schlüssel erkannt', authorized_contains($goodPk) === true);
    $check('Nicht autorisierter Schlüssel abgewiesen', authorized_contains(str_repeat('c', 64)) === false);
    $check('Doppeltes Hinzufügen ohne Duplikat', authorized_add([$goodPk], 'test') === 0);
    $check('Ungültiges Format wird ignoriert', authorized_add(['xyz'], 'test') === 0 && !authorized_contains('xyz'));

    echo "== Sortierung: größte Netto-Zustimmung oben ==\n";
    cli_switch_db($tmpDir, 'sortdb');
    $sa = cli_add_users(1, 'sa')[0];
    $catS = (int) SW::$db->val('SELECT id FROM categories ORDER BY id LIMIT 1');
    $lowNet = topic_create($sa, 'Radweg-Vorschlag mit knapper Mehrheit', 'Ziel des ersten Sortier-Themas hier.', 'Begründung des ersten Sortier-Themas hier.', $catS, 'bund', null, 'date', substr(Clock::addDaysStr(Clock::nowStr(), 30), 0, 10), null);
    $sb = cli_add_users(1, 'sb')[0];
    $highNet = topic_create($sb, 'Radweg-Vorschlag mit klarer Mehrheit', 'Ziel des zweiten Sortier-Themas hier.', 'Begründung des zweiten Sortier-Themas hier.', $catS, 'bund', null, 'date', substr(Clock::addDaysStr(Clock::nowStr(), 30), 0, 10), null);
    $sv = cli_add_users(6, 'sv');

    vote_cast($sv[0], $lowNet, 'for');
    vote_cast($sv[1], $lowNet, 'against');

    vote_cast($sv[2], $highNet, 'for');
    vote_cast($sv[3], $highNet, 'for');
    vote_cast($sv[4], $highNet, 'for');
    $listed = topics_list(['q' => 'Radweg-Vorschlag'], 1, 10, null);
    $check('Suchtreffer nach Netto-Zustimmung: höchste zuerst',
        $listed['rows'] !== [] && (int) $listed['rows'][0]['id'] === $highNet);

    echo "== Kontolöschung (DSGVO) ==\n";
    cli_switch_db($tmpDir, 'gdpr');
    $pairIds = cli_add_users(2, 'cd');
    $carol = $pairIds[0];
    $dave = $pairIds[1];
    $carolTopic = cli_make_topic($carol, 'Thema von Carol zum Löschen');
    $daveTopic = cli_make_topic($dave, 'Thema von Dave bleibt bestehen');
    vote_cast($carol, $daveTopic, 'for');
    fav_toggle($carol, 'scope', 'bundesland:Bayern');
    $carolTag = vote_tag($daveTopic, user_pk($carol));
    account_delete($carol);
    $check('Nutzer gelöscht', (int) SW::$db->val('SELECT COUNT(*) FROM users WHERE id = ?', [$carol]) === 0);
    $check('Stimme bleibt anonym erhalten (nicht mehr zuordenbar)',
        (int) SW::$db->val('SELECT COUNT(*) FROM votes WHERE topic_id = ? AND voter_tag = ?', [$daveTopic, $carolTag]) === 1);
    $check('Favoriten gelöscht', (int) SW::$db->val('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$carol]) === 0);
    $systemId = (int) SW::$db->val('SELECT id FROM users WHERE is_system = 1');
    $check('Thema entkoppelt (System-Konto)', (int) SW::$db->val('SELECT author_id FROM topics WHERE id = ?', [$carolTopic]) === $systemId);

    echo "== Themen-Ende: Datum, Zielwert, Kombination ==\n";
    cli_switch_db($tmpDir, 'endform');
    $readEnd = static function (array $post): ?array {
        $_POST = $post;
        $result = parse_topic_end();
        $_POST = [];
        return $result;
    };
    $soon = substr(Clock::addDaysStr(Clock::nowStr(), 5), 0, 10);
    $check('Nur Datum ergibt Modus "date"', $readEnd(['end_by_date' => '1', 'end_date' => $soon]) === ['date', $soon, null]);
    $check('Nur Stimmenzahl ergibt Modus "count"', $readEnd(['end_by_target' => '1', 'end_value' => '250', 'end_unit' => 'count']) === ['count', null, 250]);
    $check('Datum + Zielwert ergibt Modus "both"',
        $readEnd(['end_by_date' => '1', 'end_date' => $soon, 'end_by_target' => '1', 'end_value' => '80', 'end_unit' => 'count']) === ['both', $soon, 80]);
    $check('Ohne Auswahl kein gültiges Ende', $readEnd([]) === null);
    $check('Datum in der Vergangenheit abgelehnt', $readEnd(['end_by_date' => '1', 'end_date' => '2000-01-01']) === null);
    $check('Prozent über 100 abgelehnt', $readEnd(['end_by_target' => '1', 'end_value' => '120', 'end_unit' => 'percent']) === null);
    cli_add_users(200, 'pc');
    $pct = $readEnd(['end_by_target' => '1', 'end_value' => '10', 'end_unit' => 'percent']);
    $check('Prozent wird in Stimmenzahl umgerechnet', $pct !== null && $pct[2] === 20);
    $bothUser = cli_add_users(1, 'both')[0];
    $bothTopic = topic_create($bothUser, 'Thema mit Datum und Zielzahl', 'Ziel des Kombi-Tests hier.', 'Begründung des Kombi-Tests hier.', $catId, 'bund', null, 'both', $soon, 2);
    $bv = cli_add_users(2, 'bv');
    vote_cast($bv[0], $bothTopic, 'for');
    vote_cast($bv[1], $bothTopic, 'for');
    $check('Kombination: Zielzahl schließt vor dem Datum',
        SW::$db->val('SELECT status FROM topics WHERE id = ?', [$bothTopic]) === 'closed');
    $dateFirst = topic_create($bv[0], 'Thema schließt am Datum', 'Ziel des Datum-Kombi-Tests.', 'Begründung des Datum-Kombi-Tests.', $catId, 'bund', null, 'both', substr(Clock::nowStr(), 0, 10), 1000000);
    $warp('+2 days');
    maintenance_tick();
    $check('Kombination: Datum schließt vor der Zielzahl',
        SW::$db->val('SELECT status FROM topics WHERE id = ?', [$dateFirst]) === 'closed');
    $warp('-2 days');

    echo "== Testbetrieb: Zustand in der Datenbank, Ende löscht alles ==\n";
    cli_switch_db($tmpDir, 'testmode');
    SW::$testMode = null;
    $check('Testbetrieb ist im Auslieferungszustand aktiv', test_mode() === true);
    $tmUser = cli_add_users(1, 'tm')[0];
    $tmTopic = cli_make_topic($tmUser, 'Thema aus dem Testbetrieb');
    vote_cast(cli_add_users(1, 'tv')[0], $tmTopic, 'for');
    fav_toggle($tmUser, 'scope', 'bundesland:Bayern');
    test_mode_end();
    $check('Testbetrieb beendet', test_mode() === false);
    $check('Themen gelöscht', (int) SW::$db->val('SELECT COUNT(*) FROM topics') === 0);
    $check('Stimmen gelöscht', (int) SW::$db->val('SELECT COUNT(*) FROM votes') === 0);
    $check('Favoriten gelöscht', (int) SW::$db->val('SELECT COUNT(*) FROM favorites') === 0);
    $check('Konten gelöscht (System-Konto bleibt)',
        (int) SW::$db->val('SELECT COUNT(*) FROM users WHERE is_system = 0') === 0
        && (int) SW::$db->val('SELECT COUNT(*) FROM users WHERE is_system = 1') === 1);
    $check('Kategorien bleiben erhalten', count(categories()) >= 20);
    SW::$testMode = true;

    echo "== Einrichtung des Echtbetriebs ==\n";
    cli_switch_db($tmpDir, 'setup');
    SW::$testMode = null;
    @unlink(authorized_file());
    @unlink(test_keys_file());
    $base = ['eid_mode' => 'demo', 'eid_server_url' => '', 'eid_server_cert' => '', 'eid_server_key' => '',
             'eid_client_url' => 'http://127.0.0.1:24727/eID-Client', 'authorized_keys_url' => '', 'nect_start' => ''];
    $validate = static function (array $over) use ($base): array {
        [$errors] = setup_validate(array_merge($base, $over));
        return $errors;
    };
    $check('Leere Freigabeliste ohne Abgleich-Adresse abgelehnt',
        in_array('setup.err_list_empty', $validate([]), true));
    authorized_add([str_repeat('ab', 32)], 'selftest');
    $check('Freigabeliste mit Eintrag genügt', $validate([]) === []);
    $check('eID-Modus ohne Serveradresse abgelehnt',
        in_array('setup.err_server_missing', $validate(['eid_mode' => 'eid']), true));
    $check('Serveradresse ohne https abgelehnt',
        in_array('setup.err_https', $validate(['eid_server_url' => 'http://eid.example']), true));
    $check('Nicht lesbares Zertifikat abgelehnt',
        in_array('setup.err_file', $validate(['eid_server_cert' => '/kein/pfad/cert.pem']), true));
    $check('Gültige eID-Angaben angenommen',
        $validate(['eid_mode' => 'eid', 'eid_server_url' => 'https://eid.example/soap']) === []);
    $saved = array_merge($base, ['eid_mode' => 'eid', 'eid_server_url' => 'https://eid.example/soap',
                                 'nect_start' => 'https://nect.example/start']);
    $check('Einstellungen gespeichert', setup_save($saved));
    $loaded = setup_load();
    $check('Einstellungen unverändert gelesen',
        $loaded['eid_server_url'] === 'https://eid.example/soap' && $loaded['eid_mode'] === 'eid');
    setup_apply($loaded);
    $check('Einstellungen überschreiben die Vorgabe',
        (string) SW::$cfg['eid_server_url'] === 'https://eid.example/soap'
        && (string) SW::$cfg['eid_providers']['nect']['start'] === 'https://nect.example/start');
    $check('Nicht vorgesehene Schlüssel werden nicht übernommen',
        !array_key_exists('session_idle_minutes', setup_load()));
    $check('Unerreichbarer eID-Server meldet Fehlschlag',
        setup_probe(array_merge($base, ['eid_server_url' => 'https://127.0.0.1:1/soap'])) === false);
    $token = setup_token();
    $check('Einrichtungsschlüssel erzeugt und stabil', strlen($token) === 32 && $token === setup_token());
    @unlink(authorized_file());
    @unlink(test_keys_file());
    $realKey = str_repeat('cd', 32);
    $testKey = str_repeat('ef', 32);
    authorized_add([$realKey], 'issue-card');
    authorized_add([$testKey], 'test-login');
    test_key_add($testKey);
    $check('Testschlüssel zählen nicht als freigegebene Ausweise', authorized_count_stable() === 1);
    test_mode_end();
    $check('Testschlüssel beim Umschalten entfernt', !authorized_contains($testKey));
    $check('Echter Ausweis-Schlüssel bleibt erhalten', authorized_contains($realKey));
    SW::$cfg = SW_CONFIG;
    @unlink(setup_file());
    @unlink(setup_token_file());
    SW::$testMode = true;

    echo "== Listen aus Dateien ==\n";
    $check('Kategorien-Datei wird gelesen oder faellt sauber zurueck', count(sw_categories()) >= 20);
    $check('Gebiete-Datei wird gelesen oder faellt sauber zurueck', count(sw_regions()) === 16);
    $check('Nur https://raw.githubusercontent.com wird abgerufen',
        sw_list_fetch('http://raw.githubusercontent.com/a/b/c.json') === null
        && sw_list_fetch('https://example.org/liste.json') === null
        && sw_list_fetch('https://raw.githubusercontent.com.angreifer.example/a.json') === null);
    $check('Kategorie mit unzulaessigem Kennzeichen wird verworfen',
        sw_categories_clean([['slug' => '../../etc', 'de' => 'Test', 'en' => 'Test']]) === []);
    $check('Steuerzeichen und Markup werden verworfen',
        sw_list_text("Te\x00st", 60) === null
        && sw_list_text('<script>alert(1)</script>', 60) === null
        && sw_list_text('Umwelt & Klima', 60) === 'Umwelt & Klima');
    $check('Zu lange Werte werden verworfen', sw_list_text(str_repeat('a', 300), 80) === null);
    $check('Anzahl der Gebiete ist gedeckelt',
        count(sw_regions_clean(array_fill_keys(array_map(static function (int $i): string {
            return 'Land ' . $i;
        }, range(1, 50)), ['Kreis Eins'])) ) <= SW_MAX_LAENDER);
    $check('Doppelte Werte werden zusammengefasst',
        sw_regions_clean(['Land Eins' => ['Kreis A', 'Kreis A', 'Kreis B']]) === ['Land Eins' => ['Kreis A', 'Kreis B']]);
    $catLocal = [['alt', 'Alt', 'Old']];
    $check('Nur neue Kategorien werden uebernommen',
        sw_categories_new($catLocal, [['alt', 'Anders', 'Other'], ['neu', 'Neu', 'New']]) === [['neu', 'Neu', 'New']]);
    $check('Bestehende Kategorien bleiben unveraendert',
        sw_categories_new($catLocal, [['alt', 'Boese', 'Evil']]) === []);
    $many = [];
    for ($i = 0; $i < 40; $i++) {
        $many[] = ['neu' . $i, 'Neu ' . $i, 'New ' . $i];
    }
    $check('Hoechstzahl neuer Werte je Abgleich greift',
        count(sw_categories_new($catLocal, $many)) === SW_SYNC_MAX_NEW);
    $regLocal = ['Land Eins' => ['Kreis A']];
    $merge = sw_regions_merge($regLocal, ['Land Eins' => ['Kreis A', 'Kreis B'], 'Land Zwei' => ['Kreis C']]);
    $check('Neue Gebiete werden ergaenzt, alte behalten',
        $merge['list'] === ['Land Eins' => ['Kreis A', 'Kreis B'], 'Land Zwei' => ['Kreis C']]
        && count($merge['added']) === 3);
    $check('Fehlende Werte in der Ferndatei loeschen nichts',
        sw_regions_merge($regLocal, [])['list'] === $regLocal);
    $check('Abweichende Leerzeichen erzeugen keinen Doppeleintrag',
        sw_regions_merge(['Land Eins' => ['Kreis A']], sw_regions_clean(['Land Eins' => [' Kreis  A ']]))['added'] === []);
    $check('Namen aus fremden Alphabeten werden verworfen',
        sw_list_text('Вayern', 60) === null && sw_list_text('Bayern', 60) === 'Bayern');

    echo "== Sprachtabellen ==\n";
    $dupes = static function (string $const): array {
        $src = file_get_contents(__FILE__);
        $start = strpos($src, 'const ' . $const . ' = [');
        $end = strpos($src, "\n];", $start);
        $body = substr($src, $start, $end - $start);
        preg_match_all("/^ {4}'([a-z0-9_.]+)' =>/m", $body, $m);
        $seen = [];
        $twice = [];
        foreach ($m[1] as $key) {
            if (isset($seen[$key])) {
                $twice[] = $key;
            }
            $seen[$key] = true;
        }
        return $twice;
    };
    $check('Keine doppelten Schlüssel in SW_DE', $dupes('SW_DE') === []);
    $check('Keine doppelten Schlüssel in SW_EN', $dupes('SW_EN') === []);
    $outside = static function (): string {
        $src = file_get_contents(__FILE__);
        $out = '';
        $cursor = 0;
        foreach (['SW_DE', 'SW_EN'] as $const) {
            $start = strpos($src, 'const ' . $const . ' = [');
            $end = strpos($src, "\n];", $start) + 3;
            $out .= substr($src, $cursor, $start - $cursor);
            $cursor = $end;
        }
        return $out . substr($src, $cursor);
    };
    $body = $outside();
    $orphans = array_values(array_filter(array_keys(SW_DE), static function (string $key) use ($body): bool {
        return strpos($body, "'" . $key . "'") === false;
    }));
    $check('Keine verwaisten Textschlüssel', $orphans === [], implode(', ', $orphans));
    $missingEn = array_diff(array_keys(SW_DE), array_keys(SW_EN));
    $missingDe = array_diff(array_keys(SW_EN), array_keys(SW_DE));
    $check('Deutsch und Englisch decken dieselben Schlüssel ab',
        $missingEn === [] && $missingDe === []);
    $emptyVals = array_filter(SW_EN, static function ($v): bool {
        return trim((string) $v) === '';
    });
    $check('Keine leeren englischen Texte', $emptyVals === []);

    Clock::setTestNow(null);
    putenv('BUERGERABSTIMMUNG_DB');
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
                    'INSERT OR IGNORE INTO votes (topic_id, voter_tag, choice, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [(int) $topic['id'], vote_tag((int) $topic['id'], user_pk($userId)), $choice, $now, $now]
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
        $added = sw_sync_lists();
        echo $added > 0 ? "ok, neue Listeneinträge: $added\n" : "ok\n";
        return 0;
    }
    if ($cmd === 'sync-lists') {
        echo 'neue Einträge: ' . sw_sync_lists(true) . "\n";
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
    if ($cmd === 'issue-card') {
        $n = isset($argv[2]) && preg_match('/^\d{1,4}$/', $argv[2]) === 1 ? (int) $argv[2] : 1;
        cli_issue_card($n);
        return 0;
    }
    if ($cmd === 'sync-keys') {
        return cli_sync_keys($argv[2] ?? '');
    }
    if ($cmd === 'lists') {
        printf("Kategorien: %d (Datei: %s)\n", count(sw_categories()), is_file(sw_list_path(SW_CATEGORIES_FILE)) ? 'ja' : 'nein — eingebaute Liste');
        printf("Gebiete:    %d Länder, %d Kreise (Datei: %s)\n", count(sw_regions()),
            array_sum(array_map('count', sw_regions())), is_file(sw_list_path(SW_REGIONS_FILE)) ? 'ja' : 'nein — eingebaute Liste');
        return 0;
    }
    if ($cmd === 'setup-token') {
        if (!test_mode()) {
            echo "Testbetrieb ist bereits beendet; ein Einrichtungsschluessel wird nicht mehr gebraucht.\n";
            return 0;
        }
        echo setup_token() . "\n";
        return 0;
    }
    if ($cmd === 'config') {
        $stored = setup_load();
        echo $stored === [] ? "Keine Einstellungen in data/config.yaml.\n" : '';
        foreach (SW_SETUP_KEYS as $key) {
            printf("%-20s %s\n", $key, (string) ($stored[$key] ?? '-'));
        }
        return 0;
    }
    echo "Aufrufe: php index.php selftest | cron | sync-lists | lists | seed [n] | jurysim | issue-card [n] | sync-keys [url] | setup-token | config\n";
    return $cmd === 'help' ? 0 : 1;
}

function cli_issue_card(int $count): void
{
    if (!card_supports_sodium()) {
        fwrite(STDERR, "Abbruch: PHP-sodium erforderlich.\n");
        return;
    }
    $dir = SW::$dataDir . '/issued';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        fwrite(STDERR, "Abbruch: issued/ nicht anlegbar.\n");
        return;
    }
    for ($i = 0; $i < $count; $i++) {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $pkHex = bin2hex(sodium_crypto_sign_publickey($pair));
        $handle = bin2hex(random_bytes(16));
        authorized_add([$pkHex], 'issue-card');
        $file = $dir . '/' . $handle . '.key';
        file_put_contents($file, base64_encode($secret), LOCK_EX);
        @chmod($file, 0600);
        echo "Autorisierter Ausweis ausgegeben.\n";
        echo "  Schluessel: " . substr($pkHex, 0, 16) . "…\n";
        echo "  Ausgabe-Link (im Browser oeffnen): /claim/" . $handle . "\n";
    }
    echo "Hinweis: Nur diese Schluessel koennen sich anmelden. Link der URL der Seite voranstellen.\n";
}

function cli_sync_keys(string $urlArg): int
{
    $url = $urlArg !== '' ? $urlArg : (string) SW::$cfg['authorized_keys_url'];
    if ($url === '') {
        echo "Keine Quelle konfiguriert (authorized_keys_url leer).\n";
        echo "In Deutschland gibt es keine staatliche Liste aller Ausweis-Schluessel;\n";
        echo "echte Pruefung laeuft ueber die BSI-Zertifikatskette im eID-Server.\n";
        echo "Diese Funktion ist der Anschlusspunkt fuer eine eigene Trust-Liste.\n";
        return 0;
    }
    if (preg_match('#^https://#', $url) !== 1) {
        fwrite(STDERR, "Abbruch: nur https-Quellen erlaubt.\n");
        return 1;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        fwrite(STDERR, "Abbruch: Quelle nicht erreichbar.\n");
        return 1;
    }
    preg_match_all('/[0-9a-fA-F]{64,128}/', $body, $mm);
    $added = authorized_add($mm[0], $url);
    printf("Allowlist aktualisiert: %d neue Schluessel aus %s\n", $added, $url);
    return 0;
}

if (PHP_SAPI === 'cli') {
    exit(cli_main($argv));
}
web_main();
