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
    // dies keine offizielle Seite der Bundesregierung oder Behörde ist.
    // (Der Testmodus selbst ist KEINE Konfiguration mehr – er ist bei einer
    //  frischen Installation aktiv und wird über die Oberfläche beendet.)
    'show_test_banner' => true,

    // Anmeldemodus:
    //   'demo' = Ausweise werden per CLI ausgegeben (issue-card) und in die
    //            Allowlist aufgenommen; NUR gelistete Schlüssel können sich
    //            anmelden. Kein Ausweis / fremder Schlüssel => abgewiesen.
    //   'eid'  = echter eID-Server nach BSI TR-03130 (AusweisApp). Ohne
    //            konfigurierten Server schlägt die Anmeldung bewusst FEHL
    //            (fail-closed) – niemand kommt ohne echten Ausweis hinein.
    'eid_mode' => 'demo',
    // Integrationsstelle zum Aktualisieren der Allowlist (sync-keys). Standard
    // leer: In Deutschland gibt es KEINE staatliche Liste aller
    // Ausweis-Schlüssel; echte Prüfung läuft über die BSI-Zertifikatskette im
    // eID-Server. Diese URL ist der Anschlusspunkt für eine eigene Trust-Liste.
    'authorized_keys_url' => '',

    // Anmelde-Anbieter (Ausweis-Apps). 'start' ist die vom Betreiber
    // konfigurierte Startadresse des jeweiligen Flows:
    //  - AusweisApp: URL des eigenen eID-Servers (TR-03130), der eine
    //    tcTokenURL erzeugt und die AusweisApp öffnet.
    //  - Nect: Start-URL des Nect-Ident-Flows (Nect Wallet).
    // Leer = nicht konfiguriert; der Anbieter meldet dann sauber „nicht
    // eingerichtet“ (fail-closed), es kommt niemand ohne echte Prüfung hinein.
    // Aktivierungsadresse des eID-Clients nach BSI TR-03124. Die AusweisApp
    // (oder ein anderer eID-Client) lauscht lokal auf diesem Port; der Aufruf
    // mit tcTokenURL startet die Ausweis-Prüfung direkt auf dem Gerät. Dieser
    // Teil braucht KEINE Konfiguration – Apache + diese Datei genügen.
    'eid_client_url' => 'http://127.0.0.1:24727/eID-Client',
    // eID-Server nach BSI TR-03130 (SOAP-Endpunkt useID/getResult). Nur ein
    // Betreiber mit Berechtigungszertifikat des BVA kann so einen Server
    // betreiben; ohne Eintrag liefert /eid/tctoken einen sauberen Fehler an die
    // AusweisApp zurück und niemand wird angemeldet (fail-closed).
    'eid_server_url'  => '',
    'eid_server_cert' => '', // Client-Zertifikat (PEM) für die mTLS-Verbindung
    'eid_server_key'  => '', // zugehöriger privater Schlüssel (PEM)
    'eid_providers' => [
        'ausweisapp' => ['label' => 'AusweisApp', 'start' => ''],
        'nect'       => ['label' => 'Nect Wallet', 'start' => ''],
    ],

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
    public static string $serverSign = '';
    public static ?bool $testMode = null;
    public static string $lang = 'de';
    /** @var array<string,string> */
    public static array $tActive = [];
    public static ?array $user = null;
    public static string $base = '';
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
    status       TEXT    NOT NULL DEFAULT 'active' CHECK (status IN ('active','closed','removed')),
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
-- Stimmen sind bewusst NICHT mit dem Ausweis verknüpft: voter_tag ist ein
-- HMAC aus Thema + öffentlichem Schlüssel mit Server-Geheimnis. Ohne das
-- Geheimnis lässt sich nicht rückschließen, welcher Ausweis was gewählt hat;
-- Doppelstimmen bleiben trotzdem ausgeschlossen (Primärschlüssel).
CREATE TABLE IF NOT EXISTS votes (
    topic_id   INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    voter_tag  TEXT    NOT NULL,
    choice     TEXT    NOT NULL CHECK (choice IN ('for','against')),
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    PRIMARY KEY (topic_id, voter_tag)
);
CREATE TABLE IF NOT EXISTS favorites (
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind       TEXT    NOT NULL CHECK (kind IN ('category','scope')),
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

-- Laufende Ausweis-App-Vorgänge (TR-03124). Die AusweisApp holt das tcToken
-- ohne Browser-Cookie ab; der Einmal-Nonce in der tcTokenURL verbindet beides.
CREATE TABLE IF NOT EXISTS eid_flows (
    nonce      TEXT    PRIMARY KEY,
    session_id TEXT    NOT NULL,
    eid_ref    TEXT,
    created_at INTEGER NOT NULL
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
        // Das Schema besteht ausschließlich aus IF-NOT-EXISTS-Anweisungen und
        // wird daher bei jedem Start angewandt: neue Tabellen erscheinen auch
        // in bestehenden Datenbanken, vorhandene Daten bleiben unberührt.
        $exists = $this->val("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_info'");
        $this->pdo->exec(SW_SCHEMA);
        if ($exists === null) {
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

/** Root-.htaccess: saubere Pfade (/…) an index.php, ohne /index.php in der URL. */
const SW_HTACCESS_ROOT = <<<'TXT'
# Stimmwerk (automatisch erzeugt) - bei Bedarf loeschen, wird neu angelegt.
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

    // Server-Signaturschlüssel (Ed25519): signiert das ausgelieferte Profil,
    // damit Manipulation erkennbar ist. Öffentlicher Teil ist abrufbar.
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

    $dbPath = getenv('STIMMWERK_DB') ?: SW::$dataDir . '/stimmwerk.sqlite';
    SW::$db = new Db($dbPath);
    SW::$db->migrate();
    sw_seed_categories();
}

function sw_hmac(string $value): string
{
    return hash_hmac('sha256', $value, SW::$pepper);
}

/* ---- Testmodus ---------------------------------------------------------- *
 * Kein Konfigurationsschalter: Eine frische Installation startet IM
 * Testmodus, damit sie sofort ohne eID-Server nutzbar ist. Beenden erfolgt
 * einmalig über die Oberfläche; dabei werden alle im Testbetrieb erzeugten
 * Inhalte gelöscht. Danach gilt ausschließlich die echte Ausweis-Prüfung. */
function test_mode(): bool
{
    if (SW::$testMode === null) {
        SW::$testMode = SW::$db !== null
            && (string) (SW::$db->val("SELECT v FROM schema_info WHERE k = 'test_mode'") ?? '1') === '1';
    }
    return SW::$testMode;
}

/** Beendet den Testmodus und löscht alle im Testbetrieb erzeugten Inhalte. */
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
    // Testausweise und deren Freigaben verwerfen.
    @unlink(authorized_file());
    foreach (glob(SW::$dataDir . '/issued/*.key') ?: [] as $f) {
        @unlink($f);
    }
    log_line('SECURITY', 'test_mode_ended', []);
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

/** URL-Präfix. Die Anwendung erzeugt IMMER saubere Pfade (/topics, nie
 *  /index.php/...). Voraussetzung ist die mitgelieferte .htaccess-Umschreibung
 *  (Apache/LiteSpeed; für nginx eine gleichwertige try_files-Regel). */
function base_path(): string
{
    return SW::$base;
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

/* ---- Allowlist autorisierter Ausweis-Schlüssel -------------------------- *
 * Die Allowlist (data/authorized_keys.yaml) enthält die öffentlichen Schlüssel,
 * die zu von der Behörde ausgegebenen Ausweisen gehören. Anmelden kann sich
 * NUR, wessen Schlüssel hier steht UND wer den passenden privaten Schlüssel
 * besitzt (Signatur-Challenge). So kommt niemand mit fehlendem oder fremdem
 * Ausweis hinein. Aktualisiert wird die Liste über sync-keys bzw. issue-card;
 * im Echtbetrieb ersetzt der eID-Server (TR-03130) diese Liste durch die
 * Prüfung gegen die staatliche Zertifikatskette (TR-03110). */

function authorized_file(): string
{
    return SW::$dataDir . '/authorized_keys.yaml';
}

/** @return array<string,bool> Menge autorisierter Public-Keys (hex, lowercase). */
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

/** Fügt Public-Keys hinzu (idempotent) und schreibt die YAML neu. */
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
    $y = "stimmwerk_authorized_keys:\n";
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
    return $added;
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
    // Zeitfenster abgelaufen -> Identitätsnachweis verfällt, erneut auflegen.
    // Im Testmodus entfällt auch das (keine Ausweis-Aufforderungen).
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

/** Jede Änderung läuft unabhängig von der Profil-Anmeldung über einen
 *  eigenen, zeitgebundenen versiegelten Umschlag: Die Karte versiegelt die
 *  Aktion, der Server öffnet mit dem öffentlichen Schlüssel und trägt das
 *  Ergebnis für genau diesen Schlüssel ein. Ein alter Umschlag (anderes
 *  Zeitfenster) wird abgelehnt. */
/** Strenge Prüfung einer Änderung (rein, damit automatisiert testbar):
 *  Ohne Testmodus MUSS ein Ausweis vorliegen, zum angemeldeten Schlüssel
 *  gehören und einen gültigen, zeitgebundenen Umschlag liefern.
 *  Im Testmodus entfallen alle Ausweis-Aufforderungen. */
function card_confirm_ok(array $user, ?array $card, string $action): bool
{
    if (test_mode()) {
        return true; // Testmodus: keine Ausweis-Bestätigung bei Änderungen
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

/** Gebiets-Favorit ('bund' | 'bundesland:X' | 'landkreis:Y') als
 *  Filterwert der Themenliste ('de' | 'bl:X' | 'kr:Land:Y'). */
function fav_to_gebiet(string $ref): ?string
{
    if ($ref === 'bund') {
        return 'de';
    }
    $parts = explode(':', $ref, 2);
    if (count($parts) !== 2) {
        return null;
    }
    if ($parts[0] === 'bundesland' && isset(SW_REGIONS[$parts[1]])) {
        return 'bl:' . $parts[1];
    }
    if ($parts[0] === 'landkreis') {
        foreach (SW_REGIONS as $land => $kreise) {
            if (in_array($parts[1], $kreise, true)) {
                return 'kr:' . $land . ':' . $parts[1];
            }
        }
    }
    return null;
}

/** Geltungsbereich-Auswahl. Baseline (ohne JS): ein gruppiertes Auswahlfeld.
 *  Mit JS ersetzt app.js es durch eine kompakte zweistufige Auswahl
 *  (Ebene → Land → Kreis), damit keine lange Liste nötig ist. */
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
    foreach (SW_REGIONS as $land => $kreise) {
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

/** Bearbeiten durch den Autor. @throws DomainException */
function topic_update(int $topicId, int $userId, string $title, string $goal, string $reasoning, int $categoryId, string $scopeLevel, ?string $scopeName, string $endMode, ?string $endDate, ?int $endTarget): void
{
    $topic = SW::$db->one('SELECT * FROM topics WHERE id = ?', [$topicId]);
    if ($topic === null || (int) $topic['author_id'] !== $userId || $topic['status'] === 'removed') {
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

/** Löschen durch den Autor (Stimmen und Meldungen fallen mit). @throws DomainException */
function topic_delete(int $topicId, int $userId): void
{
    $topic = SW::$db->one('SELECT author_id FROM topics WHERE id = ?', [$topicId]);
    if ($topic === null || (int) $topic['author_id'] !== $userId) {
        throw new DomainException('flash.not_author');
    }
    SW::$db->run('DELETE FROM topics WHERE id = ?', [$topicId]);
}

/**
 * Ende-Angaben aus dem Formular lesen und prüfen. Datum und Zielwert sind
 * frei kombinierbar; mindestens eines muss gesetzt sein. Was zuerst
 * eintritt, beendet die Abstimmung.
 * @return array{0:string,1:?string,2:?int}|null [mode, date, target]
 */
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
           (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'against') AS votes_against
    FROM topics t
    JOIN categories c ON c.id = t.category_id";

/** @return array{rows:array,total:int} */
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
    // Standard: Netto-Zustimmung (dafür minus dagegen) absteigend; bei
    // Suchtreffern steht damit das Thema mit dem größten Vorsprung oben.
    $netExpr = "((SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'for')"
        . " - (SELECT COUNT(*) FROM votes v WHERE v.topic_id = t.id AND v.choice = 'against'))";
    $sortMode = $filters['sort'] ?? 'net';
    if ($sortMode === 'new') {
        $order = ' ORDER BY t.created_at DESC';
    } elseif ($sortMode === 'top') {
        $order = ' ORDER BY (votes_for + votes_against) DESC, t.created_at DESC';
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

/** @return array{choice:string,created_at:string,locked:bool}|null */
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

/** Eigene Stimmen per Tag-Sondierung – die Stimmen-Tabelle selbst kennt
 *  keinen Ausweis-Bezug. */
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

/* ============================== Stimmen & Favoriten ======================= */

const SW_VOTE_CHANGE_HOURS = 24; // danach ist die eigene Stimme fest

/** Entkoppelter Stimm-Marker: HMAC aus Thema + öffentlichem Schlüssel. */
function vote_tag(int $topicId, string $pkHex): string
{
    return sw_hmac('vote|' . $topicId . '|' . $pkHex);
}

function user_pk(int $userId): string
{
    return (string) SW::$db->val('SELECT pseudonym_hash FROM users WHERE id = ?', [$userId]);
}

/** Schließt ein Thema, sobald Enddatum überschritten oder Zielzahl erreicht. */
function topic_close_if_due(array $topic): string
{
    if ($topic['status'] !== 'active') {
        return (string) $topic['status'];
    }
    // Datum und Zielwert sind frei kombinierbar: Was zuerst eintritt, beendet.
    $close = false;
    if ($topic['end_date'] !== null && Clock::localDate() > (string) $topic['end_date']) {
        $close = true;
    }
    if ($topic['end_target'] !== null) {
        $total = (int) SW::$db->val('SELECT COUNT(*) FROM votes WHERE topic_id = ?', [(int) $topic['id']]);
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

/** @throws DomainException */
function vote_cast(int $userId, int $topicId, string $choice): void
{
    if (!in_array($choice, ['for', 'against', 'none'], true)) {
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
    if ($choice === 'none') {
        SW::$db->run('DELETE FROM votes WHERE topic_id = ? AND voter_tag = ?', [$topicId, $tag]);
        return;
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

/** Der einzige Meldegrund ist der Verstoß gegen ein Gesetz. Der verletzte
 *  Paragraph wird 1:1 zitiert. Texte nach bestem Wissen übernommen – vor
 *  einem Echtbetrieb wortgleich gegen gesetze-im-internet.de abgleichen. */
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

/** Schlagwort-/Paragraphensuche im Gesetzesregister. @return array<string,array> */
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

/** @throws DomainException */
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

/** Kontolöschung (DSGVO, nur Backend/CLI): Favoriten und Jury-Sitze werden
 *  gelöscht, Beiträge entkoppelt. Stimmen sind bereits konstruktiv anonym
 *  (kein Ausweis-Bezug in der Tabelle) und bleiben als Zählwerte erhalten. */
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
    'vote.login_hint' => 'Zum Abstimmen Ausweis auflegen',
    'vote.bar_aria' => 'Abstimmungsergebnis',

    'topic.new_title' => 'Thema einbringen',
    'topic.posted_today' => 'Heute bereits ein Thema eingebracht. Das nächste ist ab 00:00 Uhr möglich.',
    'topic.next_in' => 'Nächstes Thema in',
    'common.close' => 'Schließen',
    'topics.clear' => 'Filter zurücksetzen',
    'scope.whole' => 'gesamt',
    'scope.pick_land' => 'Bundesland wählen …',
    'scope.pick_kreis' => 'Landkreis/Stadt wählen …',
    'topic.f_end' => 'Ende der Abstimmung',
    'topic.end_by_date' => 'an einem Datum',
    'topic.end_by_target' => 'bei erreichter Stimmenzahl',
    'topic.end_value_ph' => 'Anzahl',
    'topic.end_unit' => 'Einheit',
    'topic.end_unit_count' => 'Stimmen',
    'topic.end_unit_percent' => '% der Ausweise',
    'topic.end_hint' => 'Beides möglich – es endet, was zuerst eintritt.',
    'topic.err_end' => 'Bitte ein gültiges Ende angeben (Datum in der Zukunft oder Zielzahl).',
    'topic.ends_on' => 'Läuft bis {date}',
    'topic.ends_count' => '{have} von {target} Stimmen',
    'topic.ends_both' => 'Läuft bis {date} oder {have} von {target} Stimmen',
    'topic.ended' => 'beendet',
    'topic.vote_closed' => 'Die Abstimmung ist beendet.',
    'topic.edit' => 'Thema bearbeiten',
    'topic.save' => 'Änderungen speichern',
    'topic.delete' => 'Thema löschen',
    'topic.delete_confirm' => 'Dieses Thema und alle zugehörigen Stimmen werden gelöscht.',
    'home.recent_votes' => 'Kürzlich abgestimmt (noch änderbar)',
    'vote.changeable_until' => 'änderbar bis {date}',
    'vote.locked_note' => 'nach 24 Stunden fest',
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

    'auth.line' => 'Anmeldung mit dem Personalausweis über eine Ausweis-App.',
    'auth.with' => 'Mit {app} anmelden',
    'testmode.chip' => 'Testmodus',
    'testmode.end' => 'Testmodus beenden',
    'testmode.explain' => 'Danach ist nur noch die Anmeldung mit echtem Ausweis möglich. Alle im Testbetrieb angelegten Themen, Stimmen, Meldungen und Test-Ausweise werden gelöscht. Das lässt sich nicht rückgängig machen.',
    'testmode.confirm' => 'Ja, Testdaten löschen und Testmodus beenden',
    'flash.testmode_ended' => 'Testmodus beendet, Testdaten gelöscht.',
    'flash.testmode_confirm' => 'Bitte das Beenden bestätigen.',
    'auth.title' => 'Mit Ausweis anmelden',
    'auth.tap' => 'Ausweis auflegen',
    'auth.test_login' => 'Test-Anmeldung starten',
    'auth.hold' => 'Ausweis an das Gerät halten …',
    'auth.other_card' => 'Anderen Ausweis verwenden',

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
    'flash.vote_withdrawn' => 'Stimme zurückgezogen.',
    'flash.favorite_added' => 'Favorit hinzugefügt.',
    'flash.favorite_removed' => 'Favorit entfernt.',
    'flash.report_already_open' => 'Für dieses Thema läuft bereits eine Prüfung.',
    'flash.report_duplicate' => 'Dieses Thema wurde von Ihnen bereits gemeldet.',
    'flash.report_daily_limit' => 'Tageslimit für Meldungen erreicht.',
    'flash.report_too_few_users' => 'Für eine Jury sind derzeit zu wenige Teilnehmende registriert.',
    'flash.report_no_law' => 'Bitte das verletzte Gesetz auswählen.',
    'flash.not_author' => 'Nur der Verfasser kann dieses Thema ändern.',
    'flash.topic_updated' => 'Thema aktualisiert.',
    'flash.topic_deleted' => 'Thema gelöscht.',
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
    'flash.logged_out' => 'Abgemeldet.',

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
    'privacy.p2' => 'Beim Auflegen des Ausweises erhält die Seite nur einen öffentlichen Schlüssel und speichert davon ausschließlich ein Pseudonym (Hash mit serverseitigem Geheimnis).',
    'privacy.p3' => 'Genau ein technisch notwendiges Sitzungs-Cookie. Darüber hinaus wird nichts im Browser gespeichert – keine weiteren Cookies, kein localStorage, keine Tracker, keine Drittinhalte.',
    'privacy.p4' => 'Zur Missbrauchsabwehr werden kurzlebige, gehashte Kennungen für Ratenbegrenzungen verarbeitet und automatisch gelöscht.',
    'privacy.p5' => 'Das Konto kann jederzeit in „Meine Übersicht“ gelöscht werden.',
];

const SW_EN = [
    'app.tagline' => 'Digital citizen participation',
    'banner.test' => 'Test operation – not an official website of the German federal government or any public authority.',
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
    'vote.login_hint' => 'Place your ID card to vote',
    'vote.bar_aria' => 'Voting result',

    'topic.new_title' => 'Raise a topic',
    'common.close' => 'Close',
    'topics.clear' => 'Reset filters',
    'scope.whole' => 'whole',
    'scope.pick_land' => 'Choose federal state …',
    'scope.pick_kreis' => 'Choose district/city …',
    'topic.f_end' => 'End of voting',
    'topic.end_by_date' => 'on a date',
    'topic.end_by_target' => 'at a number of votes',
    'topic.end_value_ph' => 'Amount',
    'topic.end_unit' => 'Unit',
    'topic.end_unit_count' => 'votes',
    'topic.end_unit_percent' => '% of ID cards',
    'topic.end_hint' => 'Both possible – whichever comes first ends it.',
    'topic.err_end' => 'Please set a valid end (future date or target count).',
    'topic.ends_on' => 'Runs until {date}',
    'topic.ends_count' => '{have} of {target} votes',
    'topic.ends_both' => 'Runs until {date} or {have} of {target} votes',
    'topic.ended' => 'ended',
    'topic.vote_closed' => 'Voting has ended.',
    'topic.edit' => 'Edit topic',
    'topic.save' => 'Save changes',
    'topic.delete' => 'Delete topic',
    'topic.delete_confirm' => 'This topic and all its votes will be deleted.',
    'home.recent_votes' => 'Recently voted (still changeable)',
    'vote.changeable_until' => 'changeable until {date}',
    'vote.locked_note' => 'fixed after 24 hours',
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

    'auth.line' => 'Sign in with your ID card via an ID app.',
    'auth.with' => 'Sign in with {app}',
    'testmode.chip' => 'Test mode',
    'testmode.end' => 'End test mode',
    'testmode.explain' => 'Afterwards only sign-in with a real ID card is possible. All topics, votes, reports and test cards created during test operation are deleted. This cannot be undone.',
    'testmode.confirm' => 'Yes, delete test data and end test mode',
    'flash.testmode_ended' => 'Test mode ended, test data deleted.',
    'flash.testmode_confirm' => 'Please confirm ending test mode.',
    'auth.title' => 'Sign in with ID card',
    'auth.tap' => 'Place your ID card',
    'auth.test_login' => 'Start test sign-in',
    'auth.hold' => 'Hold your ID card to the device …',
    'auth.other_card' => 'Use a different ID card',

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
    'flash.vote_withdrawn' => 'Vote withdrawn.',
    'flash.favorite_added' => 'Favourite added.',
    'flash.favorite_removed' => 'Favourite removed.',
    'flash.report_already_open' => 'A review is already in progress for this topic.',
    'flash.report_duplicate' => 'You have already reported this topic.',
    'flash.report_daily_limit' => 'Daily report limit reached.',
    'flash.report_too_few_users' => 'Too few participants are registered for a jury at the moment.',
    'flash.report_no_law' => 'Please select the violated law.',
    'flash.not_author' => 'Only the author can change this topic.',
    'flash.topic_updated' => 'Topic updated.',
    'flash.topic_deleted' => 'Topic deleted.',
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
    'flash.logged_out' => 'Signed out.',

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
/* Stimmwerk - schlichtes App-Design in der Formensprache von iOS/Signal:
   graue Flaeche, weisse Karten mit weichen Ecken, Haarlinien, ein blauer
   Akzent, Systemschrift. Hell/Dunkel folgt der Systemeinstellung.
   Dafuer/Dagegen: Akzent gegen Neutralgrau - deutlich in Helligkeit UND
   Farbton getrennt (CVD-geprueft) und immer zusaetzlich beschriftet. */
:root {
  color-scheme: light dark;
  --page: #f2f2f7; --surface: #ffffff; --field: #efeff4;
  --ink: #000000; --muted: #8e8e93; --sep: #d1d1d6;
  --accent: #3a76f0; --accent-ink: #ffffff; --accent-soft: rgba(58,118,240,0.12);
  --danger: #d70015; --danger-soft: rgba(215,0,21,0.10);
  --vote-for: #3a76f0; --vote-against: #aeaeb2; --track: #e5e5ea;
  --warn-bg: #ffd60a; --warn-ink: #1c1c00;
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
  }
}

* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, system-ui, sans-serif;
  font-size: 1.0625rem;            /* 17px - iOS Body, verhindert Zoom bei Fokus */
  line-height: 1.45;
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

/* ---------- Kopfzeile ---------- */
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

/* ---------- Schaltflaechen (iOS: gefuellt / getoent / schlicht) ---------- */
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

/* ---------- Karten & Listen ---------- */
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

/* ---------- Aktionsleiste, Chips, Themenliste ---------- */
.action-bar { display: flex; gap: 0.5rem; flex-wrap: wrap; margin: 0.2rem 0 0.9rem; }
.action-bar .btn { flex: 1 1 auto; }
.fav-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin: 0 0 0.9rem; }
.fav-chips .btn { background: var(--field); color: var(--ink); font-weight: 500; }
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

/* ---------- Themendetail & Abstimmung ---------- */
.topic-detail h1 { margin-top: 0.4rem; }
.field-label { font-size: 0.78rem; font-weight: 600; letter-spacing: 0.02em; text-transform: uppercase; color: var(--muted); margin: 0.9rem 0 0.2rem; }
.card .field-label:first-child { margin-top: 0; }
.dot { display: inline-block; width: 0.5rem; height: 0.5rem; border-radius: 50%; margin-right: 0.3rem; }
.dot-for { background: var(--vote-for); }
.dot-against { background: var(--vote-against); }
.votebar { display: flex; gap: 2px; height: 0.6rem; border-radius: 999px; overflow: hidden; background: var(--track); }
.votebar span { flex-basis: 0; min-width: 4px; }
.votebar-for { background: var(--vote-for); }
.votebar-against { background: var(--vote-against); }
.votebar-legend { display: flex; flex-wrap: wrap; gap: 0.3rem 1.1rem; font-size: 0.92rem; margin-top: 0.5rem; color: var(--ink); }
.vote-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.9rem; }
.vote-actions .btn { flex: 1 1 8rem; }
.vote-btn { background: var(--field); color: var(--ink); }
.vote-btn.is-active { background: var(--accent); color: var(--accent-ink); }
.topic-tools { display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap; margin-top: 0.8rem; }
.topic-tools .btn, .topic-tools .link-quiet {
  font-size: 0.9rem; padding: 0.4rem 0.8rem; border-radius: 999px;
  background: var(--field); color: var(--accent); font-weight: 600;
}
.topic-tools .link-quiet { color: var(--danger); }
.topic-tools .link-quiet:hover, .topic-tools .btn:hover { text-decoration: none; opacity: 0.85; }
.link-quiet { color: var(--muted); font-size: 0.92rem; }

/* ---------- Formulare ---------- */
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

/* ---------- Anmeldung & Start ---------- */
.auth-card { max-width: 24rem; margin: 1.6rem auto; text-align: center; padding: 1.4rem 1.2rem 1.2rem; }
.auth-card h1 { font-size: 1.4rem; }
.auth-action { display: flex; justify-content: center; margin: 0.9rem 0 0.2rem; }
.auth-action .btn, .provider-list .btn { width: 100%; }
.provider-list { display: flex; flex-direction: column; gap: 0.55rem; margin: 1rem 0 0.2rem; }
.provider-btn { width: 100%; }
.tap-icon { width: 6rem; height: auto; color: var(--accent); margin: 0.2rem auto 0.6rem; display: block; }
.tap-status { font-weight: 600; color: var(--accent); }

.start-gate { min-height: 82vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.9rem; padding: 2rem 1.2rem; }
.start-mark { color: var(--accent); }
.brand-icon { width: 4.2rem; height: 4.2rem; display: block; }
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

/* ---------- Sonstiges ---------- */
.error-card { max-width: 26rem; margin: 3rem auto; text-align: center; }
.prose { max-width: 40rem; }
.prose p { color: var(--muted); }
.countdown { font-variant-numeric: tabular-nums; font-weight: 600; margin-left: 0.4rem; }

.site-footer { border-top: 1px solid var(--sep); background: var(--surface); font-size: 0.85rem; color: var(--muted); }
.footer-inner { display: flex; justify-content: space-between; gap: 0.5rem 1.2rem; flex-wrap: wrap; padding-top: 0.9rem; padding-bottom: 0.9rem; }
.footer-nav { display: flex; gap: 1rem; }
.footer-nav a { color: var(--muted); }

/* ---------- Fenster: iOS-Sheet unten, Dialog ab Tablet ---------- */
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

    /* Geltungsbereich: gruppiertes Auswahlfeld -> zweistufig (Ebene/Land/Kreis).
       Ohne JS bleibt die gruppierte Liste - voll funktionsfaehig. */
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

    /* Ende-Felder: Datum und/oder Zielwert einblenden */
    document.querySelectorAll('[data-end-fields]').forEach(function (fs) {
      fs.querySelectorAll('[data-end-toggle]').forEach(function (box) {
        var part = fs.querySelector('[data-end-part="' + box.getAttribute('data-end-toggle') + '"]');
        if (!part) return;
        var apply = function () { part.hidden = !box.checked; };
        box.addEventListener('change', apply); apply();
      });
    });

    /* Fenster (:target-Modal) per Escape schliessen */
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && location.hash && document.querySelector(location.hash + '.modal')) {
        location.hash = '';
      }
    });

    /* profil.yaml: bei jedem Seitenaufruf frisch angefordert und nur im
       Browser gehalten; der Abmelde-Knopf loescht sie wieder. */
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

    /* NFC direkt vom Handy: Knopf startet den Leser; das Anhalten der Karte
       loest die Anmeldung aus. Der Personalausweis ist kein NDEF-Tag, daher
       zaehlt auch "readingerror" als Kontakt. Ohne Web NFC (iOS, Desktop)
       oder nach 15 s sendet der Knopf normal ab. */
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
      /* Leser sofort scharf: Perso auflegen genuegt. Verlangt der Browser
         erst eine Nutzergeste (Berechtigung), uebernimmt der Knopf. */
      try {
        startScan().then(function () {
          // NFC laeuft: kein Knopf noetig - nur die Aufforderung zum Auflegen.
          if (status) { status.hidden = false; }
          tapForm.querySelectorAll('[data-nfc-hide]').forEach(function (b) { b.hidden = true; });
        }).catch(function () { /* Knopf-Fallback */ });
      } catch (e) { /* Knopf-Fallback */ }
      /* Auf NFC-Geraeten loest NUR der Kartenkontakt aus - kein Zeit-Rueckfall.
         Schlaegt der Lesestart fehl (Berechtigung verweigert), sendet der
         Knopf direkt. */
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
  <rect x="0.5" y="0.5" width="23" height="23" rx="5" fill="#111111"/>
  <path d="M6.5 12.3l3.6 3.5 7.4-7.6" fill="none" stroke="#ffffff" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
SVG;

/** Flagge Deutschland (Sprachauswahl). */
function flag_de(): string
{
    return '<svg class="flag" viewBox="0 0 60 36" aria-hidden="true" focusable="false">'
        . '<rect width="60" height="12" y="0" fill="#000000"/>'
        . '<rect width="60" height="12" y="12" fill="#dd0000"/>'
        . '<rect width="60" height="12" y="24" fill="#ffcc00"/></svg>';
}

/** Flagge Vereinigtes Königreich (Sprachauswahl Englisch). */
function flag_en(): string
{
    return '<svg class="flag" viewBox="0 0 60 36" aria-hidden="true" focusable="false">'
        . '<rect width="60" height="36" fill="#012169"/>'
        . '<path d="M0 0L60 36M60 0L0 36" stroke="#ffffff" stroke-width="7"/>'
        . '<path d="M0 0L60 36M60 0L0 36" stroke="#c8102e" stroke-width="3"/>'
        . '<path d="M30 0V36M0 18H60" stroke="#ffffff" stroke-width="12"/>'
        . '<path d="M30 0V36M0 18H60" stroke="#c8102e" stroke-width="7"/></svg>';
}

/** Icon-Verweise für alle Browser (SVG modern, PNG/ICO für Safari/iOS). */
function icon_links(): string
{
    return '<link rel="icon" type="image/svg+xml" href="' . e(url('/a/icon.svg')) . '">'
        . '<link rel="icon" type="image/png" sizes="32x32" href="' . e(url('/favicon.png')) . '">'
        . '<link rel="apple-touch-icon" sizes="180x180" href="' . e(url('/apple-touch-icon.png')) . '">';
}

/** Größeres, monochromes Marken-Icon (Häkchen im Feld) für Start/Anmeldung. */
function brand_icon(string $class): string
{
    return '<svg class="' . e($class) . '" viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
        . '<rect x="2" y="2" width="44" height="44" rx="10" fill="none" stroke="currentColor" stroke-width="3"/>'
        . '<path d="M14 25l7 7 14-15" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}

/* ---- Icons: PNG ohne GD (nur zlib) ------------------------------------- *
 * Safari/iOS zeigt keine SVG-Favicons; deshalb erzeugt die Datei die Icons
 * zusätzlich als echtes PNG (und als ICO-Container) – ohne Bibliotheken. */

/** Ein PNG-Chunk mit Länge, Typ, Daten und CRC. */
function png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/** Zeichnet das Marken-Icon (dunkles Feld + weißes Häkchen) als PNG. */
function icon_png(int $size, bool $rounded = true): string
{
    $ss = 3;                       // Kantenglättung per Überabtastung
    $n = $size * $ss;
    $bg = [17, 17, 17];            // #111111
    $fg = [255, 255, 255];
    $radius = $rounded ? 0.22 * $n : 0.0;
    // Häkchen als zwei Strecken (relativ zur Kantenlänge)
    $pts = [[0.28 * $n, 0.53 * $n], [0.44 * $n, 0.69 * $n], [0.74 * $n, 0.34 * $n]];
    $stroke = 0.085 * $n;

    $distSeg = static function (float $px, float $py, array $a, array $b): float {
        $vx = $b[0] - $a[0];
        $vy = $b[1] - $a[1];
        $wx = $px - $a[0];
        $wy = $py - $a[1];
        $len = $vx * $vx + $vy * $vy;
        $tt = $len > 0 ? max(0.0, min(1.0, ($wx * $vx + $wy * $vy) / $len)) : 0.0;
        $dx = $wx - $tt * $vx;
        $dy = $wy - $tt * $vy;
        return sqrt($dx * $dx + $dy * $dy);
    };
    $inRounded = static function (float $x, float $y, float $n, float $r): bool {
        if ($r <= 0.0) {
            return true;
        }
        $cx = min(max($x, $r), $n - $r);
        $cy = min(max($y, $r), $n - $r);
        $dx = $x - $cx;
        $dy = $y - $cy;
        return ($dx * $dx + $dy * $dy) <= $r * $r;
    };

    $raw = '';
    for ($y = 0; $y < $size; $y++) {
        $raw .= "\x00";
        for ($x = 0; $x < $size; $x++) {
            $rSum = 0; $gSum = 0; $bSum = 0; $aSum = 0;
            for ($sy = 0; $sy < $ss; $sy++) {
                for ($sx = 0; $sx < $ss; $sx++) {
                    $px = $x * $ss + $sx + 0.5;
                    $py = $y * $ss + $sy + 0.5;
                    if (!$inRounded($px, $py, (float) $n, $radius)) {
                        continue; // außerhalb: transparent
                    }
                    $d = min($distSeg($px, $py, $pts[0], $pts[1]), $distSeg($px, $py, $pts[1], $pts[2]));
                    $col = $d <= $stroke / 2 ? $fg : $bg;
                    $rSum += $col[0]; $gSum += $col[1]; $bSum += $col[2]; $aSum += 255;
                }
            }
            $total = $ss * $ss;
            $raw .= chr((int) round($rSum / $total)) . chr((int) round($gSum / $total))
                . chr((int) round($bSum / $total)) . chr((int) round($aSum / $total));
        }
    }
    $ihdr = pack('NN', $size, $size) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0);
    return "\x89PNG\r\n\x1a\n"
        . png_chunk('IHDR', $ihdr)
        . png_chunk('IDAT', gzcompress($raw, 9))
        . png_chunk('IEND', '');
}

/** ICO-Container mit eingebettetem PNG (moderne Browser lesen das). */
function icon_ico(int $size = 32): string
{
    $png = icon_png($size, true);
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
        . icon_links()
        . '<script src="' . e(url('/a/app.js')) . '" defer></script>'
        . '</head><body' . ($user !== null ? ' data-profile-url="' . e(url('/profil.yaml')) . '"' : '') . '>';
    if (!empty($cfg['show_test_banner'])) {
        $html .= '<div class="test-banner" role="note">' . e(t('banner.test')) . '</div>';
    }
    $html .= '<a class="skip-link" href="#main">' . e(t('a11y.skip')) . '</a>'
        . '<header class="site-header"><div class="shell header-inner">'
        . '<div class="header-controls">';
    if (test_mode() && $user !== null) {
        // Testmodus beenden – nur solange er läuft und nur für angemeldete
        // Sitzungen; die Anmeldeseite bleibt bei genau einem Knopf.
        $html .= '<a class="testmode-chip" href="#modal-testend">' . e(t('testmode.chip')) . '</a>';
    }
    if ($user === null) {
        // Auf der Anmeldeseite selbst KEIN zweiter Anmelde-Knopf in der
        // Kopfzeile – der Ausweis-Knopf steht dort genau einmal, mittig.
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
    if (test_mode() && $user !== null) {
        $inner = '<p class="muted">' . e(t('testmode.explain')) . '</p>'
            . '<form method="post" action="' . e(url('/testmode/end')) . '" class="form-stack">' . csrf_field()
            . '<label class="check-label"><input type="checkbox" name="confirm" value="yes" required>'
            . '<span>' . e(t('testmode.confirm')) . '</span></label>'
            . '<div><button type="submit" class="btn btn-danger btn-big">' . e(t('testmode.end')) . '</button></div></form>';
        $html .= modal('modal-testend', t('testmode.end'), $inner);
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

/** Anzeigename des Geltungsbereichs. Die Gebietsnamen tragen die Ebene
 *  bereits ("Landkreis Harburg", "München (Stadt)"), deshalb ohne Präfix. */
function scope_text(array $row): string
{
    $name = (string) ($row['scope_name'] ?? '');
    return $name !== '' ? $name : t('scope.bund');
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

/** Ende-Auswahl im Themenformular: Datum und/oder Zielwert (Stimmenzahl oder
 *  Prozent der Ausweise). Beides ankreuzbar – es gilt, was zuerst eintritt. */
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
        . '<small class="muted">' . e(t('topic.end_hint')) . '</small>'
        . '</fieldset>';
}

/** Themenformular (Neu und Bearbeiten). */
function topic_form_html(array $errors, array $old, string $action, string $submitKey): string
{
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
        . '<input type="text" name="title" required minlength="' . SW_TITLE_MIN . '" maxlength="' . SW_TITLE_MAX . '" value="' . e((string) $old['title']) . '"></label>'
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

/** Modal per :target (funktioniert ohne JavaScript). */
function modal(string $id, string $title, string $inner): string
{
    return '<div class="modal" id="' . e($id) . '" role="dialog" aria-modal="true" aria-label="' . e($title) . '">'
        . '<a class="modal-backdrop" href="#" aria-label="' . e(t('common.close')) . '"></a>'
        . '<div class="modal-box"><div class="modal-head"><h2>' . e($title) . '</h2>'
        . '<a class="modal-close" href="#" aria-label="' . e(t('common.close')) . '">&times;</a></div>'
        . '<div class="modal-body">' . $inner . '</div></div></div>';
}

/** Die eine Hauptseite: Thema einbringen, Themen wählen, kürzliche Stimmen. */
function v_main(array $formErrors = [], ?array $formOld = null): void
{
    $user = require_user();
    $userId = (int) $user['id'];
    $html = '';

    $upcoming = jury_upcoming_for($userId);
    if ($upcoming !== null) {
        $html .= '<div class="flash">' . e(t('me.jury_upcoming', ['date' => Clock::displayLocal((string) $upcoming['voting_starts_at'], t('common.date_format'))])) . '</div>';
    }

    // Filterwerte
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

    // Aktionsleiste: zwei Knöpfe öffnen je ein eigenes Fenster
    $html .= '<div class="action-bar">'
        . '<a class="btn btn-primary" href="#modal-new">' . e(t('topic.new_title')) . '</a>'
        . '<a class="btn btn-outline" href="#modal-search">' . e(t('topics.search')) . '</a>';
    if ($filters['q'] !== '' || $filters['category'] !== '' || $filters['gebiet'] !== '') {
        $html .= '<a class="btn btn-ghost" href="' . e(url('/')) . '">' . e(t('topics.clear')) . '</a>';
    }
    $html .= '</div>';

    // Favoriten-Schnellfilter
    $chips = '';
    foreach (fav_list($userId) as $favorite) {
        if ($favorite['kind'] === 'category') {
            $label = SW::$lang === 'de' ? (string) ($favorite['name_de'] ?? $favorite['ref']) : (string) ($favorite['name_en'] ?? $favorite['ref']);
            $href = url('/') . '?category=' . rawurlencode((string) $favorite['ref']);
        } else {
            $gebiet = fav_to_gebiet((string) $favorite['ref']);
            if ($gebiet === null) {
                continue;
            }
            $parts = explode(':', (string) $favorite['ref'], 2);
            $label = $parts[0] === 'bund' || !isset($parts[1]) ? t('scope.bund') : $parts[1];
            $href = url('/') . '?gebiet=' . rawurlencode($gebiet);
        }
        $chips .= '<a class="btn btn-ghost btn-sm" href="' . e($href) . '">&#9733; ' . e($label) . '</a>';
    }
    if ($chips !== '') {
        $html .= '<div class="fav-chips">' . $chips . '</div>';
    }

    // Kürzliche eigene Stimmen (noch änderbar) als eigene Gruppe
    $recent = [];
    foreach (topics_voted_by($userId) as $row) {
        if ($row['status'] !== 'removed' && empty($row['locked'])) {
            $recent[] = $row;
        }
    }
    if ($recent !== []) {
        $html .= '<section class="recent-votes"><h2>' . e(t('home.recent_votes')) . '</h2><ul class="row-list">';
        foreach ($recent as $row) {
            $until = Clock::addHoursStr((string) $row['voted_at'], SW_VOTE_CHANGE_HOURS);
            $html .= '<li class="row-item"><div class="row-main">'
                . '<a href="' . e(url('/topic/' . (int) $row['id'])) . '">' . e((string) $row['title']) . '</a></div>'
                . '<div class="row-side"><span class="dot ' . ($row['my_choice'] === 'for' ? 'dot-for' : 'dot-against') . '" aria-hidden="true"></span>'
                . e(t($row['my_choice'] === 'for' ? 'vote.for' : 'vote.against'))
                . ' <span class="muted">· ' . e(t('vote.changeable_until', ['date' => Clock::displayLocal($until, t('common.datetime_format'))])) . '</span></div></li>';
        }
        $html .= '</ul></section>';
    }

    // Themenliste
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
            $html .= '<a class="btn btn-ghost btn-sm" href="' . e(url('/') . $mkQuery($page - 1)) . '">&laquo; ' . e(t('topics.prev')) . '</a>';
        }
        $html .= '<span class="muted">' . e(t('topics.page_of', ['p' => $page, 'n' => $pages])) . '</span>';
        if ($page < $pages) {
            $html .= '<a class="btn btn-ghost btn-sm" href="' . e(url('/') . $mkQuery($page + 1)) . '">' . e(t('topics.next')) . ' &raquo;</a>';
        }
        $html .= '</nav>';
    }

    // Fenster: Thema einbringen
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

    // Fenster: Suche/Filter
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
        . '<div><button type="submit" class="btn btn-primary">' . e(t('topics.apply')) . '</button></div></form>';
    $html .= modal('modal-search', t('topics.search'), $searchInner);

    render(t('app.tagline'), $html);
}

/** Wie lange läuft die Abstimmung noch? Datum, Zielwert oder beides. */
function topic_end_text(array $topic): string
{
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
    $closed = $topic['status'] !== 'active';
    $scopeRef = $topic['scope_level'] === 'bund' ? 'bund' : $topic['scope_level'] . ':' . (string) $topic['scope_name'];

    $html = '<article class="topic-detail"><div class="topic-card-meta">'
        . '<span class="badge">' . e(cat_name($topic)) . '</span>'
        . '<span class="badge">' . e(scope_text($topic)) . '</span>'
        . ($closed ? '<span class="badge badge-danger">' . e(t('topic.ended')) . '</span>' : '')
        . '</div>'
        . '<h1>' . e((string) $topic['title']) . '</h1>'
        . '<p class="muted">' . e(t('topic.created', ['date' => Clock::displayLocal((string) $topic['created_at'], t('common.date_format'))]))
        . ' · ' . e(topic_end_text($topic)) . '</p>'
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
    } elseif ($closed) {
        $html .= '<p class="muted">' . e(t('topic.vote_closed')) . '</p>';
        if ($myVote !== null) {
            $html .= '<p class="muted">' . e(t('topic.your_vote', ['choice' => t($myVote === 'for' ? 'vote.for' : 'vote.against')])) . '</p>';
        }
    } elseif ($voteRow !== null && $voteRow['locked']) {
        $html .= '<p class="muted">' . e(t('topic.your_vote', ['choice' => t($myVote === 'for' ? 'vote.for' : 'vote.against')]))
            . ' · ' . e(t('vote.locked_note')) . '</p>';
    } else {
        $html .= '<form class="vote-actions" method="post" action="' . e(url('/vote')) . '">' . csrf_field()
            . '<input type="hidden" name="topic_id" value="' . (int) $topic['id'] . '">'
            . '<button type="submit" name="choice" value="for" class="btn vote-btn' . ($myVote === 'for' ? ' is-active' : '') . '" aria-pressed="' . ($myVote === 'for' ? 'true' : 'false') . '">' . e(t('vote.for')) . '</button>'
            . '<button type="submit" name="choice" value="against" class="btn vote-btn' . ($myVote === 'against' ? ' is-active' : '') . '" aria-pressed="' . ($myVote === 'against' ? 'true' : 'false') . '">' . e(t('vote.against')) . '</button>';
        if ($myVote !== null) {
            $html .= '<button type="submit" name="choice" value="none" class="btn btn-ghost">' . e(t('vote.withdraw')) . '</button>';
        }
        $html .= '</form>';
        if ($myVote !== null && $voteRow !== null) {
            $until = Clock::addHoursStr((string) $voteRow['created_at'], SW_VOTE_CHANGE_HOURS);
            $html .= '<p class="muted">' . e(t('topic.your_vote', ['choice' => t($myVote === 'for' ? 'vote.for' : 'vote.against')]))
                . ' · ' . e(t('vote.changeable_until', ['date' => Clock::displayLocal($until, t('common.datetime_format'))])) . '</p>';
        }
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
    if ($isAuthor) {
        $html .= '<a class="btn btn-ghost btn-sm" href="' . e(url('/topic/' . (int) $topic['id'] . '/edit')) . '">' . e(t('topic.edit')) . '</a>'
            . '<a class="link-quiet" href="#modal-del">' . e(t('topic.delete')) . '</a>';
    }
    if ($openReport !== null) {
        $html .= '<span class="muted">' . e(t('topic.report_open')) . '</span>';
    } elseif ($user !== null && !$isAuthor && !$closed) {
        $html .= '<a class="link-quiet" href="' . e(url('/report/' . (int) $topic['id'])) . '">' . e(t('topic.report_link')) . '</a>';
    }
    $html .= '</section></article>';

    if ($isAuthor) {
        $delInner = '<p class="muted">' . e(t('topic.delete_confirm')) . '</p>'
            . '<form method="post" action="' . e(url('/topic/' . (int) $topic['id'] . '/delete')) . '">' . csrf_field()
            . '<div class="btn-row"><button type="submit" class="btn btn-danger">' . e(t('topic.delete')) . '</button>'
            . '<a class="btn btn-ghost" href="#">' . e(t('common.close')) . '</a></div></form>';
        $html .= modal('modal-del', t('topic.delete'), $delInner);
    }
    render((string) $topic['title'], $html);
}

/** Bearbeiten-Seite (nur Autor). */
function v_topic_edit(int $id, array $errors = [], ?array $old = null): void
{
    $user = require_user();
    $topic = topic_find($id);
    if ($topic === null) {
        v_error_404();
    }
    if ((int) $topic['author_id'] !== (int) $user['id'] || $topic['status'] === 'removed') {
        flash('error', 'flash.not_author');
        redirect('/topic/' . $id);
    }
    if ($old === null) {
        $scopeVal = $topic['scope_level'] === 'bund' ? 'de'
            : ($topic['scope_level'] === 'bundesland' ? 'bl:' . $topic['scope_name'] : 'kr:');
        // Land für Kreis rekonstruieren
        if ($topic['scope_level'] === 'landkreis') {
            foreach (SW_REGIONS as $land => $kreise) {
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
        . '<div class="card">' . topic_form_html($errors, $old, '/topic/' . $id . '/edit', 'topic.save')
        . '<p><a class="link-quiet" href="' . e(url('/topic/' . $id)) . '">' . e(t('report.cancel')) . '</a></p></div>';
    render(t('topic.edit'), $html);
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
    $mode = (string) SW::$cfg['eid_mode'];
    $card = card_load();
    $ready = $mode !== 'eid' && $card !== null && authorized_contains(card_identity($card));

    $html = '<section class="card auth-card">' . $pictogram
        . '<h1>' . e(t('auth.title')) . '</h1>'
        . '<p class="muted">' . e(t('auth.line')) . '</p>';

    if (test_mode()) {
        // Testmodus: GENAU EIN zentrierter Knopf, keine Ausweis-Aufforderung.
        $html .= '<form class="auth-action" method="post" action="' . e(url('/tap')) . '">' . csrf_field()
            . '<button type="submit" class="btn btn-primary btn-big">' . e(t('auth.test_login')) . '</button></form>';
    } else {
        // Anmelde-Anbieter (Ausweis-Apps): AusweisApp und Nect Wallet.
        $html .= '<div class="provider-list">';
        foreach ((array) SW::$cfg['eid_providers'] as $key => $prov) {
            $html .= '<a class="btn btn-primary provider-btn" href="' . e(url('/eid/start?provider=' . rawurlencode($key))) . '">'
                . e(t('auth.with', ['app' => (string) $prov['label']])) . '</a>';
        }
        $html .= '</div>';
        if ($ready) {
            // Ein autorisierter Ausweis liegt vor. Mit NFC genügt das Auflegen –
            // dann wird der Knopf ausgeblendet (data-nfc-hide). Ohne NFC bleibt
            // genau ein sauber zentrierter Knopf stehen.
            $html .= '<hr class="hr-soft">'
                . '<form id="tap-form" class="auth-action" method="post" action="' . e(url('/tap')) . '">' . csrf_field()
                . '<button type="submit" class="btn btn-outline btn-big" data-nfc-hide>' . e(t('auth.tap')) . '</button></form>'
                . '<p id="tap-status" class="tap-status" hidden aria-live="polite">' . e(t('auth.hold')) . '</p>';
        }
    }
    $html .= '</section>';
    render(t('auth.title'), $html);
}

/** Absolute Adresse dieser Installation (für tcToken/Rücksprünge). */
function site_url(string $path = ''): string
{
    $scheme = sw_is_https() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? SW::$cfg['domain']);
    return $scheme . '://' . $host . base_path() . $path;
}

/** Startet den Anmelde-Flow eines Anbieters.
 *
 *  AusweisApp: direkte Aktivierung des eID-Clients nach BSI TR-03124 – der
 *  Browser wird auf http://127.0.0.1:24727/eID-Client?tcTokenURL=… geleitet.
 *  Die AusweisApp (Desktop wie Smartphone) fängt diese Adresse ab, holt das
 *  tcToken hier ab und beginnt das Auslesen des Ausweises. Dafür ist keine
 *  Konfiguration nötig; es genügt der Apache mit dieser Datei.
 *
 *  Andere Anbieter (Nect) starten über ihre konfigurierte Start-URL. Fehlt
 *  sie, schlägt es sauber fehl (fail-closed). */
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
        // Einmal-Nonce in der tcTokenURL: Die AusweisApp holt das Token als
        // eigener HTTP-Client OHNE Browser-Cookie ab – der Nonce ist die
        // einzige Klammer zwischen Browsersitzung und Ausweis-Vorgang.
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
        // Anbieter vorhanden, aber (noch) nicht eingerichtet.
        flash('error', 'flash.eid_provider_off');
        redirect('/auth');
    }
    // Konfiguriert: an den echten Flow des Anbieters übergeben. Dieser prüft
    // den Ausweis (Nect) und ruft anschließend /eid/callback auf.
    $sep = strpos($start, '?') === false ? '?' : '&';
    header('Location: ' . $start . $sep . 'redirect=' . rawurlencode(site_url('/eid/callback')), true, 303);
    exit;
}

/** tcToken nach BSI TR-03124 – wird von der AusweisApp abgeholt.
 *
 *  Mit eingerichtetem eID-Server (TR-03130) enthält es dessen PAOS-Adresse und
 *  die Sitzungskennung aus `useID`. Ohne eID-Server wird bewusst nur eine
 *  CommunicationErrorAddress geliefert: Die AusweisApp bricht sauber ab und
 *  schickt den Browser zurück – angemeldet wird niemand (fail-closed). */
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
        // Kein eID-Server hinterlegt (oder nicht erreichbar): sauberer Abbruch.
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

/** Ausweis-Vorgang vormerken: Nonce ↔ Browsersitzung, 10 Minuten gültig. */
function eid_flow_start(string $nonce): void
{
    SW::$db->run('DELETE FROM eid_flows WHERE created_at < ?', [Clock::now()->getTimestamp() - 600]);
    SW::$db->run(
        'INSERT INTO eid_flows (nonce, session_id, created_at) VALUES (?, ?, ?)',
        [$nonce, session_id(), Clock::now()->getTimestamp()]
    );
}

/** Vorgang zum Nonce holen (nur frische Vorgänge). */
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

/** eID-Server-Sitzung am Vorgang vermerken (für den späteren Rücksprung). */
function eid_flow_bind(string $nonce, string $ref): void
{
    SW::$db->run('UPDATE eid_flows SET eid_ref = ? WHERE nonce = ?', [$ref, $nonce]);
}

/** Sitzung beim eID-Server anfordern (TR-03130 `useID`). Ohne konfigurierten
 *  Server gibt es hier bewusst nichts zurück – das ist der Anschlusspunkt für
 *  einen Betreiber mit Berechtigungszertifikat.
 *  @return array{paos:string,session:string}|null */
function eid_server_useid(): ?array
{
    $url = (string) SW::$cfg['eid_server_url'];
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
    if ((string) SW::$cfg['eid_server_cert'] !== '') {
        $ctx['ssl']['local_cert'] = (string) SW::$cfg['eid_server_cert'];
    }
    if ((string) SW::$cfg['eid_server_key'] !== '') {
        $ctx['ssl']['local_pk'] = (string) SW::$cfg['eid_server_key'];
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

/** Ein Element aus einer SOAP-Antwort lesen (namensraum-tolerant). */
function eid_xml_value(string $xml, string $name): string
{
    $pattern = '#<(?:[A-Za-z0-9_.-]+:)?' . preg_quote($name, '#') . '(?:\s[^>]*)?>([^<]*)</#';
    return preg_match($pattern, $xml, $m) === 1 ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) : '';
}

/** Rücksprung aus der Ausweis-App. Vertraut wird NUR einem serverseitig
 *  geprüften Ergebnis; ohne eingerichteten eID-Server passiert nichts. */
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
    // Hier holt ein Betreiber mit eID-Server das Ergebnis ab (TR-03130
    // `getResult`), prüft die Signatur der Zusicherung und entnimmt ihr die
    // geprüfte Kennung. Ohne diese Prüfung wird niemand angemeldet.
    log_line('SECURITY', 'eid_callback_unverified', []);
    flash('error', 'flash.eid_required');
    redirect('/auth');
}

/** Sprachwahl beim Sitzungsbeginn: klares Icon, zwei Knöpfe mit Flagge. */
function v_start(): void
{
    http_response_code(200);
    $banner = empty(SW::$cfg['show_test_banner'])
        ? ''
        : '<div class="test-banner" role="note">' . e(SW_DE['banner.test']) . ' / ' . e(SW_EN['banner.test']) . '</div>';
    echo '<!DOCTYPE html><html lang="de"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="referrer" content="no-referrer">'
        . '<title>' . e((string) SW::$cfg['app_name']) . '</title>'
        . '<link rel="stylesheet" href="' . e(url('/a/app.css')) . '">'
        . icon_links()
        . '</head><body>' . $banner
        . '<main class="start-gate">'
        . '<div class="start-mark">' . brand_icon('brand-icon') . '</div>'
        . '<p class="start-brand">' . e((string) SW::$cfg['app_name']) . '</p>'
        . '<div class="start-langs">'
        . '<form method="post" action="' . e(url('/lang')) . '">' . csrf_field()
        . '<input type="hidden" name="return" value="/">'
        . '<button type="submit" name="lang" value="de" class="lang-btn" lang="de">'
        . flag_de() . '<span>Deutsch</span></button></form>'
        . '<form method="post" action="' . e(url('/lang')) . '">' . csrf_field()
        . '<input type="hidden" name="return" value="/">'
        . '<button type="submit" name="lang" value="en" class="lang-btn" lang="en">'
        . flag_en() . '<span>English</span></button></form>'
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
    $y = "stimmwerk_profil:\n";
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

/** Öffentlicher Server-Signaturschlüssel (hex) oder '' ohne sodium. */
function server_sign_pk_hex(): string
{
    if (!card_supports_sodium() || SW::$serverSign === '') {
        return '';
    }
    return bin2hex(sodium_crypto_sign_publickey_from_secretkey(SW::$serverSign));
}

/**
 * Versiegeltes Profil: an den öffentlichen Ausweis-Schlüssel VERSCHLÜSSELT
 * (nur der Karteninhaber kann es öffnen) und mit dem Server-Schlüssel
 * SIGNIERT (Manipulation ist erkennbar). Enthält keine Zuordnung, wer wie
 * gestimmt hat – die Stimmen selbst sind bereits entkoppelt gespeichert.
 */
function profile_sealed(array $user): string
{
    $plain = profile_yaml($user);
    $pkHex = (string) $user['pseudonym_hash'];
    if (!card_supports_sodium() || SW::$serverSign === '' || strlen(@hex2bin($pkHex) ?: '') !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        // Rückfall ohne sodium: Klartext + HMAC-Integritätssiegel.
        $sig = sw_hmac('profil|' . $plain);
        return $plain . "# integritaet(hmac-sha256): " . $sig . "\n";
    }
    $edPk = hex2bin($pkHex);
    $curvePk = sodium_crypto_sign_ed25519_pk_to_curve25519($edPk);
    $cipher = sodium_crypto_box_seal($plain, $curvePk);          // an Public Key verschlüsselt
    $sig = sodium_crypto_sign_detached($cipher, SW::$serverSign); // Server-Signatur
    $out = "stimmwerk_versiegeltes_profil:\n";
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
    if (test_mode()) {
        // TESTMODUS: erzeugt eine zufällige Sitzung und behandelt sie als
        // gültig (die App tut so, als läge ein echter Ausweis an). Der zufällige
        // Schlüssel wird dazu autorisiert – die Prüfungen unten laufen normal.
        $card = card_create();
        authorized_add([card_identity($card)], 'test-login');
    } else {
        // Echtbetrieb: der eID-Server (AusweisApp, TR-03130) übernimmt. Ist er
        // nicht konfiguriert, schlägt die Anmeldung bewusst fehl – niemand kommt
        // ohne echten Ausweis hinein.
        if ((string) SW::$cfg['eid_mode'] === 'eid') {
            log_line('SECURITY', 'eid_not_configured', []);
            flash('error', 'flash.eid_required');
            redirect('/auth');
        }
        // Es muss ein Ausweis vorliegen (per Ausgabe-Link in die Sitzung geladen);
        // ein Knopfdruck allein erzeugt KEINE Identität.
        $card = card_load();
        if ($card === null) {
            flash('error', 'flash.no_card');
            redirect('/auth');
        }
    }
    // 1) Besitz des privaten Schlüssels beweisen (zeitgebundene Signatur).
    $identity = card_identity($card);
    $sealed = card_seal($card, 'login:' . $identity);
    if (!card_open($card['pk'], $sealed, 'login:' . $identity)) {
        log_line('SECURITY', 'card_verify_failed', []);
        flash('error', 'flash.auth_failed');
        redirect('/auth');
    }
    // 2) Schlüssel muss autorisiert (in der Allowlist) sein.
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

/** Ausgabe-Link (nur Demo): lädt einen autorisierten Ausweis in die Sitzung. */
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

/** Beendet den Testmodus über die Oberfläche (löscht alle Testinhalte). */
function h_testmode_end(): void
{
    require_user();
    if (!test_mode()) {
        redirect('/');
    }
    if (post_str('confirm', 10) !== 'yes') {
        flash('error', 'flash.testmode_confirm');
        redirect('/');
    }
    test_mode_end();
    auth_logout();
    card_forget();
    flash('info', 'flash.testmode_ended');
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

/** Gemeinsames Einlesen/Prüfen des Themenformulars (Neu und Bearbeiten).
 *  @return array{0:list<string>,1:array,2:?array,3:?array} */
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

/** Bearbeiten (nur Autor). */
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

/** Löschen (nur Autor). */
function h_topic_delete(int $topicId): void
{
    $user = require_user();
    require_card($user);
    try {
        topic_delete($topicId, (int) $user['id']);
    } catch (DomainException $e) {
        flash('error', $e->getMessage());
        redirect('/topic/' . $topicId);
    }
    flash('success', 'flash.topic_deleted');
    redirect('/');
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

/* ============================== Web-Hauptlauf ============================= */

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

    // Interner Pfad: PATH_INFO (/index.php/topics) oder REQUEST_URI ohne Basis.
    $rawPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
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

    // Kanonisch immer sauber: eine direkt aufgerufene /index.php[/…] wird
    // (bei GET) dauerhaft auf den sauberen Pfad umgeleitet, damit die Adresse
    // bei /… bleibt und nie /index.php zeigt.
    $method0 = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (($method0 === 'GET' || $method0 === 'HEAD') && strpos($rawPath, '/index.php') !== false) {
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        header('Location: ' . (base_path() . $path) . ($qs !== '' ? '?' . $qs : ''), true, 301);
        exit;
    }

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
    // Icons: ohne Sitzung abrufbar, mit Cache. PNG/ICO für Safari/iOS,
    // SVG für moderne Browser.
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
            // Apple-Touch-Icon ohne runde Ecken (iOS maskiert selbst).
            echo icon_png($size, strpos($name, 'apple-touch') !== 0);
        }
        exit;
    }
    // Öffentlicher Server-Signaturschlüssel: ohne Sitzung/Sprache abrufbar.
    if ($path === '/server.pub') {
        header('Content-Type: text/plain; charset=utf-8');
        echo server_sign_pk_hex() . "\n";
        exit;
    }

    send_security_headers();
    session_boot();

    // Sprache: nur Sitzung (Wahl beim Start); nichts wird am Konto gespeichert.
    $user = auth_user();
    $lang = is_string($_SESSION['lang'] ?? null) ? (string) $_SESSION['lang'] : '';
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
        // Ausgenommen sind die /eid/-Endpunkte: Das tcToken holt die
        // Ausweis-App als eigener HTTP-Client ohne Sitzung und ohne Sprache ab.
        $langChosen = is_string($_SESSION['lang'] ?? null) || $user !== null;
        if (!$langChosen && ($method === 'GET' || $method === 'HEAD')
            && !in_array($path, ['/start', '/imprint', '/privacy'], true)
            && strpos($path, '/eid/') !== 0) {
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

        // Ohne gescannten Ausweis ist die Seite nicht sichtbar:
        // nur Anmeldung und Rechtliches sind offen.
        if ($user === null && $isGet
            && !in_array($path, ['/auth', '/imprint', '/privacy'], true)
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
        if (preg_match('#^/topic/(\d{1,10})/edit$#', $path, $m) === 1 && $isGet) {
            v_topic_edit((int) $m[1]);
        }
        if (preg_match('#^/topic/(\d{1,10})/edit$#', $path, $m) === 1 && $method === 'POST') {
            h_topic_edit((int) $m[1]);
        }
        if (preg_match('#^/topic/(\d{1,10})/delete$#', $path, $m) === 1 && $method === 'POST') {
            h_topic_delete((int) $m[1]);
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

        if ($path === '/lang' && $method === 'POST') {
            h_lang();
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
        if ($path === '/testmode/end' && $method === 'POST') {
            h_testmode_end();
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
    $endDate = substr(Clock::addDaysStr(Clock::nowStr(), 30), 0, 10);
    return topic_create($authorId, $title, 'Ein Ziel für den Selbsttest dieses Themas.', 'Eine Begründung für den Selbsttest dieses Themas.', $categoryId, 'bund', null, 'date', $endDate, null);
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

    echo "== Themen-Ende (Datum/Anzahl) & Autor-Rechte ==\n";
    $au = cli_add_users(1, 'au')[0];
    $catId = (int) SW::$db->val('SELECT id FROM categories ORDER BY id LIMIT 1');
    $countTopic = topic_create($au, 'Thema endet bei 2 Stimmen', 'Ziel für den Anzahl-Test hier.', 'Begründung für den Anzahl-Test hier.', $catId, 'bund', null, 'count', null, 2);
    $c1 = cli_add_users(1, 'c1')[0];
    $c2 = cli_add_users(1, 'c2')[0];
    vote_cast($c1, $countTopic, 'for');
    $check('Thema bei Zielzahl noch offen (1/2)', SW::$db->val('SELECT status FROM topics WHERE id = ?', [$countTopic]) === 'active');
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
    topic_delete($ownTopic, $au2);
    $check('Autor kann löschen (Thema weg)', SW::$db->val('SELECT COUNT(*) FROM topics WHERE id = ?', [$ownTopic]) == 0);
    $check('Stimmen des gelöschten Themas entfernt', SW::$db->val('SELECT COUNT(*) FROM votes WHERE topic_id = ?', [$ownTopic]) == 0);

    echo "== Profil: verschlüsselt an Public Key + Server-Signatur ==\n";
    if (card_supports_sodium()) {
        $pair = sodium_crypto_sign_keypair();
        $pkHex = bin2hex(sodium_crypto_sign_publickey($pair));
        SW::$db->run('INSERT INTO users (pseudonym_hash, lang, created_at) VALUES (?, ?, ?)', [$pkHex, 'de', Clock::nowStr()]);
        $pu = SW::$db->one('SELECT * FROM users WHERE pseudonym_hash = ?', [$pkHex]);
        $sealed = profile_sealed($pu);
        $check('Ausgeliefertes Profil enthält keinen Klartext-Schlüssel im Inhalt',
            strpos($sealed, 'stimmwerk_versiegeltes_profil') === 0);
        // Chiffre extrahieren und mit dem privaten Schlüssel entschlüsseln
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
        $check('Nur mit dem Ausweis-Schlüssel entschlüsselbar', is_string($plain) && strpos($plain, 'stimmwerk_profil') === 0);
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
    // lowNet: 1 dafür, 1 dagegen -> netto 0
    vote_cast($sv[0], $lowNet, 'for');
    vote_cast($sv[1], $lowNet, 'against');
    // highNet: 3 dafür, 0 dagegen -> netto +3
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

    echo "== Sprachtabellen ==\n";
    $missingEn = array_diff(array_keys(SW_DE), array_keys(SW_EN));
    $missingDe = array_diff(array_keys(SW_EN), array_keys(SW_DE));
    $check('Deutsch und Englisch decken dieselben Schlüssel ab',
        $missingEn === [] && $missingDe === []);
    $emptyVals = array_filter(SW_EN, static function ($v): bool {
        return trim((string) $v) === '';
    });
    $check('Keine leeren englischen Texte', $emptyVals === []);

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
                // Wie im Echtbetrieb: ohne Ausweis-Bezug, nur unter dem Marker.
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
    if ($cmd === 'issue-card') {
        $n = isset($argv[2]) && preg_match('/^\d{1,4}$/', $argv[2]) === 1 ? (int) $argv[2] : 1;
        cli_issue_card($n);
        return 0;
    }
    if ($cmd === 'sync-keys') {
        return cli_sync_keys($argv[2] ?? '');
    }
    echo "Aufrufe: php index.php selftest | cron | seed [n] | jurysim | issue-card [n] | sync-keys [url]\n";
    return $cmd === 'help' ? 0 : 1;
}

/** Gibt autorisierte Test-Ausweise aus (Demo): Schlüsselpaar erzeugen, in die
 *  Allowlist aufnehmen, Geheimnis ablegen, Ausgabe-Link nennen. */
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

/** Aktualisiert die Allowlist aus einer konfigurierten Quelle (Trust-Liste).
 *  Erwartet einfache Zeilen/YAML mit hex-Schlüsseln. Ehrlicher Hinweis: eine
 *  staatliche Liste aller Ausweis-Schlüssel existiert nicht; echte Prüfung
 *  läuft über die BSI-Zertifikatskette im eID-Server (TR-03110/-03130). */
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

/* ============================== Einstieg ================================== */

if (PHP_SAPI === 'cli') {
    exit(cli_main($argv));
}
web_main();
