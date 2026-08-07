<?php
/**
 * simple-apple-v2.php - iCloud-Postfach sortieren lassen.
 *
 * Eine Datei, sonst nichts. Kein Composer, keine ext/imap, kein require.
 * Kann neben index.php liegen, teilt sich aber nichts mit ihr.
 *
 * Einrichtung: Voraussetzungen pruefen, dann die KI verbinden. Danach steht
 * dauerhaft ein Formular bereit: iCloud-Adresse und app-spezifisches Passwort.
 * Das Postfach wird einmal aufgeraeumt und danach im festen Takt sortiert.
 *
 * Keine Domains, keine Nebenadressen, kein Private Relay.
 *
 * Ablage: simple-apple-data neben dieser Datei.
 * Cron alle fuenf Minuten:  php /pfad/simple-apple-v2.php cron
 */

declare(strict_types=1);

const VERSION = '2.0';
const SA_INTERVAL = 15;        // Minuten zwischen zwei Durchlaeufen
const SA_MAX_KONTEN = 50;
const SA_HOST = 'imap.mail.me.com';
const SA_PORT = 993;

const TOTP_WINDOW = 1;

const LOGIN_MAX = 5;

const LOGIN_LOCK = 900;

const MAX_BODY = 8388608;

const BATCH = 200;

const STEP = 5;                       // kleinste Zeiteinheit in Minuten

const RELAY_DOMAIN = 'privaterelay.appleid.com';

/** Voreinstellung. Der Admin kann die Liste aendern. */
const DEFAULT_FOLDERS = [
    'Sicherheit'      => 'Anmeldungen, Zwei-Faktor, Codes, Passwoerter, Kontowarnungen',
    'Finanzen'        => 'alles mit echtem Geld: Banken, Zahlungsdienste, Rechnungen, Depots',
    'Finanzen/Krypto' => 'nur Kryptoboersen und Wallets',
    'Bestellungen'    => 'Kaeufe, Bestaetigungen, Versand, Pakete, Marktplatz',
    'Werbung'         => 'Werbung, Rabatte, Gutscheine, Newsletter',
    'Apple'           => 'Apple-Dienste: Quittungen, Abos, Geraetemeldungen',
    'Dienste'         => 'Online-Konten, Entwicklung, Hosting, Social, Gaming',
    'Behoerden'       => 'Aemter, Krankenkasse, Aerzte, Telekommunikation, Vertraege',
    'Spam'            => 'unerwuenschte Massenmail, Dating',
    'Persoenlich'     => 'echte Menschen und alles nicht sicher Zuordenbare',
];

const SYSTEM_FOLDERS = ['inbox', 'sent', 'sent messages', 'drafts', 'deleted messages',
    'trash', 'junk', 'archive', 'notes', 'outbox', '[gmail]'];

function data_dir(): string
{
    $env = getenv('SA_DATA_DIR');
    return is_string($env) && $env !== '' ? rtrim($env, '/') : __DIR__ . '/simple-apple-data';
}

function bail(string $msg): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg . "\n");
}

function jread(string $path, array $default = []): array
{
    if (!is_file($path)) {
        return $default;
    }
    $d = json_decode((string) @file_get_contents($path), true);
    return is_array($d) ? $d : $default;
}

function jwrite(string $path, array $data): void
{
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        bail('Schreiben fehlgeschlagen: ' . $path);
    }
    @chmod($path, 0600);
}

function locked(string $path, callable $fn): array
{
    $lock = fopen($path . '.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $data = $fn(jread($path));
        jwrite($path, $data);
        return $data;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function config(): array
{
    return jread(data_dir() . '/config.json');
}

function config_set(callable $fn): array
{
    return locked(data_dir() . '/config.json', $fn);
}

/** Ordnerkatalog aus der Konfiguration, sonst die Voreinstellung. */

/** Ordnerkatalog aus der Konfiguration, sonst die Voreinstellung. */
function folders_catalogue(): array
{
    $custom = config()['folders'] ?? null;
    if (!is_array($custom) || $custom === []) {
        $custom = DEFAULT_FOLDERS;
    }
    $out = [];
    foreach ($custom as $name => $what) {
        if (is_string($name) && folder_shape_ok($name)) {
            $out[$name] = (string) $what;
        }
    }
    unset($out['Alias']);   // hier gibt es keine Nebenadressen
    return $out;
}

function user_path(string $id): string
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
        bail('Ungueltige Nutzer-ID');
    }
    return data_dir() . '/users/' . $id . '.json';
}

/** @return list<array> */

/** @return list<array> */
function users(): array
{
    $out = [];
    foreach (glob(data_dir() . '/users/*.json') ?: [] as $file) {
        $u = jread($file);
        if ($u !== []) {
            $out[] = $u;
        }
    }
    usort($out, static fn($a, $b) => strcmp((string) $a['address'], (string) $b['address']));
    return $out;
}

function user_load(string $id): ?array
{
    $u = jread(user_path($id));
    return $u === [] ? null : $u;
}

function user_set(string $id, callable $fn): array
{
    return locked(user_path($id), $fn);
}

function key_bytes(): string
{
    $path = data_dir() . '/key.bin';
    if (!is_file($path)) {
        $key = random_bytes(32);
        file_put_contents($path, $key, LOCK_EX);
        @chmod($path, 0600);
        return $key;
    }
    return (string) file_get_contents($path);
}

function enc(string $plain): string
{
    $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($n . sodium_crypto_secretbox($plain, $n, key_bytes()));
}

function dec(string $blob): string
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $p = sodium_crypto_secretbox_open(
        substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), key_bytes());
    return $p === false ? '' : $p;
}

function new_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

final class ImapError extends RuntimeException {}

final class Imap
{
    /**
     * Abschliessende Liste. Was hier fehlt, wird nie gesendet.
     * Es gibt bewusst kein Kommando, das Nachrichteninhalte liest, und keines,
     * das Nachrichten loescht. DELETE betrifft nur Ordner, und nur leere.
     */
    private const ALLOWED = [
        '/^CAPABILITY$/',
        '/^LOGIN /',
        '/^LOGOUT$/',
        '/^LIST "" "\*"$/',
        '/^CREATE /',
        '/^DELETE /',
        '/^EXAMINE /',
        '/^SELECT /',
        '/^UID SEARCH ALL$/',
        '/^UID FETCH [0-9,:]+ \(BODY\.PEEK\[HEADER\.FIELDS \(FROM TO CC BCC\)\]\)$/',
        '/^UID MOVE [0-9,:]+ /',
    ];
    private const FETCH = '(BODY.PEEK[HEADER.FIELDS (FROM TO CC BCC)])';

    /** @var resource */
    private $sock;
    private int $tag = 0;
    private array $caps = [];
    public string $uidvalidity = '';

    public function __construct(string $host, int $port, int $timeout = 20)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true,
            'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr,
            $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($sock === false) {
            throw new ImapError(net_hint($host, $port, $errno, trim($errstr)));
        }
        $this->sock = $sock;
        stream_set_timeout($this->sock, 60);
        $this->line();
    }

    public function __destruct()
    {
        if (is_resource($this->sock)) {
            @fwrite($this->sock, "zz LOGOUT\r\n");
            @fclose($this->sock);
        }
    }

    private function line(): array
    {
        $text = '';
        $lit = [];
        while (true) {
            $chunk = fgets($this->sock);
            if ($chunk === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new ImapError(!empty($meta['timed_out'])
                    ? 'Zeitueberschreitung beim Lesen.' : 'Verbindung abgebrochen.');
            }
            $chunk = rtrim($chunk, "\r\n");
            $text .= $chunk;
            if (preg_match('/\{(\d+)\}$/', $chunk, $m) !== 1) {
                break;
            }
            $need = (int) $m[1];
            $data = '';
            while (strlen($data) < $need) {
                $part = fread($this->sock, min(65536, $need - strlen($data)));
                if ($part === false || $part === '') {
                    throw new ImapError('Verbindung beim Lesen abgebrochen.');
                }
                $data .= $part;
            }
            $lit[] = $data;
            $text = substr($text, 0, -strlen($m[0])) . "\x01";
        }
        return ['text' => $text, 'lit' => $lit];
    }

    private function send(string $cmd): array
    {
        $ok = false;
        foreach (self::ALLOWED as $p) {
            if (preg_match($p, $cmd) === 1) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new ImapError('Nicht erlaubt: ' . $cmd);
        }
        $tag = sprintf('a%04d', ++$this->tag);
        fwrite($this->sock, "{$tag} {$cmd}\r\n");
        $untagged = [];
        while (true) {
            $line = $this->line();
            if (preg_match('/^' . $tag . ' (OK|NO|BAD)\b(.*)$/i', $line['text'], $m) === 1) {
                return [strtoupper($m[1]), trim($m[2]), $untagged];
            }
            $untagged[] = $line;
        }
    }

    public static function q(string $v): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }

    public function login(string $user, string $password): void
    {
        [$status, $info] = $this->send('LOGIN ' . self::q($user) . ' ' . self::q($password));
        if ($status !== 'OK') {
            throw new ImapError('Anmeldung abgelehnt. iCloud und Google verlangen ein '
                . 'Anwendungspasswort, nicht das Kontopasswort. Server: ' . $info);
        }
        [, , $untagged] = $this->send('CAPABILITY');
        foreach ($untagged as $l) {
            if (stripos($l['text'], 'CAPABILITY') !== false) {
                $this->caps = array_map('strtoupper', preg_split('/\s+/', $l['text']) ?: []);
            }
        }
        if (!in_array('MOVE', $this->caps, true)) {
            throw new ImapError('Der Server bietet MOVE nicht an. Es wird nichts verschoben.');
        }
    }

    /** @return list<array{name:string,system:bool}> */
    public function folders(): array
    {
        [$status, , $untagged] = $this->send('LIST "" "*"');
        if ($status !== 'OK') {
            throw new ImapError('Ordnerliste nicht lesbar.');
        }
        $out = [];
        foreach ($untagged as $line) {
            if (preg_match('/^\* LIST \(([^)]*)\) (?:"(?:[^"\\\\]|\\\\.)*"|NIL) (.+)$/i',
                    $line['text'], $m) !== 1) {
                continue;
            }
            $flags = strtolower($m[1]);
            if (str_contains($flags, '\noselect')) {
                continue;
            }
            $tok = trim($m[2]);
            if ($tok === "\x01") {
                $raw = $line['lit'][0] ?? '';
            } elseif (strlen($tok) > 1 && $tok[0] === '"') {
                $raw = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($tok, 1, -1));
            } else {
                $raw = $tok;
            }
            if ($raw === '') {
                continue;
            }
            $name = mutf7_decode($raw);
            $system = in_array(strtolower($name), SYSTEM_FOLDERS, true)
                || preg_match('/\\\\(Sent|Drafts|Trash|Junk|Archive|All|Flagged|Important)\b/i', $flags) === 1;
            $out[] = ['name' => $name, 'system' => $system];
        }
        usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    public function create(string $folder): bool
    {
        [$status, $info] = $this->send('CREATE ' . self::q(mutf7_encode($folder)));
        return $status === 'OK' || stripos($info, 'ALREADYEXISTS') !== false;
    }

    /** Nur fuer leere Ordner. Der Aufrufer raeumt vorher aus. */
    public function delete_folder(string $folder): bool
    {
        if (in_array(strtolower($folder), SYSTEM_FOLDERS, true)) {
            return false;
        }
        [$status] = $this->send('DELETE ' . self::q(mutf7_encode($folder)));
        return $status === 'OK';
    }

    /** @return list<string> UIDs */
    public function open(string $folder, bool $write = false): array
    {
        $cmd = ($write ? 'SELECT ' : 'EXAMINE ') . self::q(mutf7_encode($folder));
        [$status, , $untagged] = $this->send($cmd);
        if ($status !== 'OK') {
            throw new ImapError('Ordner nicht waehlbar: ' . $folder);
        }
        foreach ($untagged as $l) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $l['text'], $m) === 1
                && strtoupper($folder) === 'INBOX') {
                $this->uidvalidity = $m[1];
            }
        }
        [$status, , $untagged] = $this->send('UID SEARCH ALL');
        foreach ($untagged as $l) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $l['text'], $m) === 1) {
                return preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
        }
        return [];
    }

    /**
     * @param list<string> $uids
     * @return list<array{uid:string,from:?string,to:list<string>}>
     */
    public function headers(array $uids): array
    {
        $out = [];
        foreach (array_chunk($uids, BATCH) as $chunk) {
            [$status, , $untagged] = $this->send(
                'UID FETCH ' . implode(',', $chunk) . ' ' . self::FETCH);
            if ($status !== 'OK') {
                continue;
            }
            foreach ($untagged as $line) {
                if (!isset($line['lit'][0])
                    || preg_match('/UID (\d+)/', $line['text'], $m) !== 1) {
                    continue;
                }
                $h = parse_head($line['lit'][0]);
                $out[] = ['uid' => $m[1], 'from' => $h['from'], 'to' => $h['to']];
            }
        }
        return $out;
    }

    /** @param list<string> $uids */
    public function move(array $uids, string $target): int
    {
        foreach ($uids as $uid) {
            if (!ctype_digit($uid)) {
                throw new ImapError('UID muss eine Zahl sein.');
            }
        }
        $moved = 0;
        foreach (array_chunk($uids, BATCH) as $chunk) {
            [$status] = $this->send('UID MOVE ' . implode(',', $chunk)
                . ' ' . self::q(mutf7_encode($target)));
            if ($status !== 'OK') {
                throw new ImapError('Verschieben nach ' . $target . ' fehlgeschlagen.');
            }
            $moved += count($chunk);
        }
        return $moved;
    }
}

function net_hint(string $host, int $port, int $errno, string $errstr): string
{
    $hint = match ($errno) {
        111 => "Verbindung abgelehnt. Auf {$host} antwortet auf Port {$port} nichts.",
        110 => "Zeitueberschreitung. Pakete zu {$host}:{$port} werden verworfen - "
             . "meist eine Firewall. Ausgehend Port {$port} freigeben.",
        101, 113 => 'Netzwerk nicht erreichbar. Routing oder IPv6 pruefen.',
        0 => $errstr !== '' ? $errstr : 'Verbindung fehlgeschlagen.',
        default => $errstr !== '' ? $errstr : 'Verbindung fehlgeschlagen.',
    };
    return "{$host}:{$port} - {$hint}" . ($errno ? " (errno {$errno})" : '');
}

function mutf7_encode(string $name): string
{
    $out = '';
    $buf = '';
    $flush = static function (string &$buf, string &$out): void {
        if ($buf !== '') {
            $u = (string) mb_convert_encoding($buf, 'UTF-16BE', 'UTF-8');
            $out .= '&' . rtrim(strtr(base64_encode($u), '/', ','), '=') . '-';
            $buf = '';
        }
    };
    foreach (preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if ($ch === '&') {
            $flush($buf, $out);
            $out .= '&-';
        } elseif (strlen($ch) === 1 && ord($ch) >= 0x20 && ord($ch) <= 0x7E) {
            $flush($buf, $out);
            $out .= $ch;
        } else {
            $buf .= $ch;
        }
    }
    $flush($buf, $out);
    return $out;
}

function mutf7_decode(string $name): string
{
    if (!str_contains($name, '&')) {
        return $name;
    }
    $out = '';
    for ($i = 0, $len = strlen($name); $i < $len;) {
        if ($name[$i] !== '&') {
            $out .= $name[$i++];
            continue;
        }
        $end = strpos($name, '-', $i);
        if ($end === false) {
            $out .= substr($name, $i);
            break;
        }
        $chunk = substr($name, $i + 1, $end - $i - 1);
        if ($chunk === '') {
            $out .= '&';
        } else {
            $b64 = strtr($chunk, ',', '/');
            $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
            $raw = base64_decode($b64, true);
            $out .= $raw === false ? substr($name, $i, $end - $i + 1)
                : (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
        }
        $i = $end + 1;
    }
    return $out;
}

/** @return array{from:?string,to:list<string>} */

/** @return array{from:?string,to:list<string>} */
function parse_head(string $raw): array
{
    $raw = preg_replace('/\r?\n[ \t]+/', ' ', str_replace("\r\n", "\n", $raw)) ?? $raw;
    $from = null;
    $to = [];
    foreach (explode("\n", $raw) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $field = strtolower(trim(substr($line, 0, $pos)));
        if (!in_array($field, ['from', 'to', 'cc', 'bcc'], true)) {
            continue;
        }
        preg_match_all('/[^\s<>,;:"()]+@[^\s<>,;:"()]+/', substr($line, $pos + 1), $m);
        foreach ($m[0] as $addr) {
            $addr = strtolower(rtrim(trim($addr), '.'));
            if ($field === 'from') {
                $from ??= $addr;
            } elseif (!in_array($addr, $to, true)) {
                $to[] = $addr;
            }
        }
    }
    return ['from' => $from, 'to' => $to];
}

function folder_shape_ok(string $name): bool
{
    $parts = explode('/', $name);
    if (count($parts) < 1 || count($parts) > 3) {
        return false;
    }
    if (in_array(strtolower($parts[0]), SYSTEM_FOLDERS, true)) {
        return false;
    }
    foreach ($parts as $p) {
        if (!preg_match('/^[A-Za-z0-9@._+\- ]{1,60}$/', $p) || !preg_match('/[A-Za-z0-9]/', $p)) {
            return false;
        }
    }
    return true;
}

function alias_path(string $recipient, array $user): ?string
{
    $r = strtolower(trim($recipient));
    if ($r === '' || !str_contains($r, '@') || $r === strtolower((string) $user['address'])) {
        return null;
    }
    $domain = substr($r, strrpos($r, '@') + 1);
    if ($domain === RELAY_DOMAIN || str_ends_with($domain, '.' . RELAY_DOMAIN)) {
        return !empty($user['privateappleid']) ? 'Apple/privateappleid' : null;
    }
    foreach ($user['domains'] ?? [] as $own) {
        $own = strtolower(ltrim((string) $own, '@'));
        if ($own !== '' && ($domain === $own || str_ends_with($domain, '.' . $own))) {
            return 'Alias/Domains/@' . $own;
        }
    }
    foreach ($user['addresses'] ?? [] as $own) {
        if (strtolower((string) $own) === $r) {
            return 'Alias/Adressen/' . $r;
        }
    }
    return null;
}

function alias_of(array $recipients, array $user): ?string
{
    foreach ($recipients as $to) {
        $p = alias_path((string) $to, $user);
        if ($p !== null) {
            return $p;
        }
    }
    return null;
}

function resolve_answer(string $answer, ?string $aliasPath): ?string
{
    if (!array_key_exists($answer, folders_catalogue())) {
        return null;
    }
    return $answer === 'Alias' ? $aliasPath : $answer;
}

const DEFAULT_MODEL = 'claude-sonnet-5';

const DEFAULT_API_URL = 'https://api.anthropic.com/v1/messages';

/** Zwei Wege zur KI: die Messages-API oder eine Routine auf claude.ai. */

/** Zwei Wege zur KI: die Messages-API oder eine Routine auf claude.ai. */
const API_KINDS = [
    'messages' => 'Claude-API (api.anthropic.com)',
    'routine'  => 'Claude-Routine (claude.ai/code/routines)',
];

function api_url(): string
{
    $url = (string) (config()['api_url'] ?? '');
    return str_starts_with($url, 'https://') ? $url : DEFAULT_API_URL;
}

function api_kind(): string
{
    $kind = (string) (config()['api_kind'] ?? 'messages');
    return isset(API_KINDS[$kind]) ? $kind : 'messages';
}

/**
 * Baut Anfrage und Kopfzeilen fuer den gewaehlten Weg.
 * @return array{0:string,1:list<string>}
 */

/**
 * Baut Anfrage und Kopfzeilen fuer den gewaehlten Weg.
 * @return array{0:string,1:list<string>}
 */
function api_request(string $kind, string $prompt, int $maxTokens, string $secret): array
{
    if ($kind === 'routine') {
        return [json_encode(['prompt' => $prompt], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['content-type: application/json', 'accept: application/json',
             'authorization: Bearer ' . $secret]];
    }
    return [json_encode([
        'model' => (string) (config()['model'] ?? DEFAULT_MODEL),
        'max_tokens' => $maxTokens,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ['content-type: application/json', 'x-api-key: ' . $secret,
         'anthropic-version: 2023-06-01']];
}

function api_secret(): string
{
    return dec((string) (config()['api_key'] ?? ''));
}

/** Zieht den Antworttext heraus, egal in welcher Huelle er steckt. */

/** Zieht den Antworttext heraus, egal in welcher Huelle er steckt. */
function api_answer_text(string $raw): string
{
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        return $raw;
    }
    $text = '';
    foreach ((array) ($body['content'] ?? []) as $part) {
        $text .= is_array($part) ? (string) ($part['text'] ?? '') : (string) $part;
    }
    if ($text !== '') {
        return $text;
    }
    foreach (['antwort', 'answer', 'result', 'output', 'text', 'message'] as $field) {
        if (isset($body[$field]) && is_scalar($body[$field])) {
            return (string) $body[$field];
        }
    }
    return $raw;
}

/** Die Aliasregeln entfallen, wenn es den Ordner Alias gar nicht gibt. */

/** Die Aliasregeln entfallen, wenn es den Ordner Alias gar nicht gibt. */
function alias_rules(): string
{
    if (!array_key_exists('Alias', folders_catalogue())) {
        return '';
    }
    return "- Steht (Nebenadresse), antworte \"Alias\" - ausser es ist Sicherheit oder Finanzen.\n"
        . "  Diese beiden gehen immer vor.\n"
        . "- \"Alias\" ist nur bei (Nebenadresse) gueltig.\n";
}

/**
 * @param list<array{from:string,to:string,art:string}> $questions
 * @return array{0:array<string,string>,1:?string}
 */

/**
 * @param list<array{from:string,to:string,art:string}> $questions
 * @return array{0:array<string,string>,1:?string}
 */
function ask_claude(array $questions): array
{
    $config = config();
    $key = dec((string) ($config['api_key'] ?? ''));
    if ($key === '') {
        return [[], 'Kein API-Schluessel hinterlegt.'];
    }
    if ($questions === []) {
        return [[], null];
    }
    $catalogue = '';
    foreach (folders_catalogue() as $name => $what) {
        $catalogue .= "- {$name}: {$what}\n";
    }
    // Jede Zeile bekommt eine Nummer. Derselbe Absender kann an die Hauptadresse
    // und an eine Nebenadresse schreiben - das sind zwei getrennte Fragen.
    $lines = '';
    foreach ($questions as $nr => $q) {
        $lines .= sprintf("%d) %s -> %s (%s)\n", $nr, $q['from'], $q['to'], $q['art']);
    }
    $prompt = "Ordne jede Zeile genau einem Ordner zu.\n\n"
        . "Ordner (nur diese Werte sind gueltig):\n{$catalogue}\n"
        . "Regeln:\n" . alias_rules()
        . "- Finanzen nur, wo tatsaechlich Geld dahintersteht. Newsletter und Werbung eines\n"
        . "  Zahlungsanbieters gehoeren nach Werbung.\n"
        . "- Unsicher? Persoenlich.\n\n"
        . "Zeilen:\n{$lines}\n"
        . "Antworte ausschliesslich mit JSON, Zeilennummer auf Ordner: {\"0\": \"Ordner\"}\n";

    [$payload, $headers] = api_request(api_kind(), $prompt, 4096, $key);
    [$code, $raw, $error] = https_post(api_url(), $payload, $headers);
    if ($error !== null) {
        return [[], 'Claude nicht erreichbar: ' . $error];
    }
    if ($code < 200 || $code >= 300) {
        $body = json_decode((string) $raw, true);
        $message = (string) ($body['error']['message'] ?? ($body['message'] ?? ''));
        if ($code === 401 || $code === 403) {
            $message .= ' Das Token wurde abgelehnt - meist ein Tippfehler oder '
                . 'ein Leerzeichen. Das ist kein Netzwerkproblem.';
        }
        return [[], 'Claude antwortet mit HTTP ' . $code . '. ' . trim($message)];
    }
    $text = api_answer_text((string) $raw);
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false) {
        return [[], 'Claude hat kein JSON geliefert.'];
    }
    $map = json_decode(substr($text, $start, $end - $start + 1), true);
    if (!is_array($map)) {
        return [[], 'Antwort nicht lesbar.'];
    }
    $catalogueKeys = folders_catalogue();
    $out = [];
    foreach ($map as $nr => $folder) {
        $folder = trim((string) $folder);
        if (isset($questions[(int) $nr]) && array_key_exists($folder, $catalogueKeys)) {
            $out[(int) $nr] = $folder;
        }
    }
    return [$out, null];
}

/** @return array{0:int,1:?string,2:?string} [status, body, fehler] */

/** @return array{0:int,1:?string,2:?string} [status, body, fehler] */
function https_post(string $url, string $payload, array $headers): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return $raw === false ? [0, null, $err] : [$code, (string) $raw, null];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST',
        'header' => implode("\r\n", $headers), 'content' => $payload,
        'timeout' => 120, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $code = (int) $m[1];
        }
    }
    return $raw === false ? [0, null, 'Anfrage fehlgeschlagen'] : [$code, (string) $raw, null];
}

function schedule_due(array $user, int $now): bool
{
    $last = isset($user['last_run']) ? strtotime((string) $user['last_run']) : 0;
    $mode = (string) ($user['schedule']['mode'] ?? 'interval');
    if ($mode === 'times') {
        $slot = date('H:i', $now - ($now % (STEP * 60)));
        if (!in_array($slot, (array) ($user['schedule']['times'] ?? []), true)) {
            return false;
        }
        return $last < $now - ($now % (STEP * 60));   // in diesem Fenster noch nicht gelaufen
    }
    $interval = max(STEP, (int) ($user['schedule']['interval'] ?? 60));
    return $last === 0 || ($now - $last) >= $interval * 60;
}

/**
 * @return array{gelesen:int,verschoben:int,offen:int,fehler:?string}
 */
function run_cycle(string $id, bool $initial = false): array
{
    $result = ['gelesen' => 0, 'verschoben' => 0, 'offen' => 0, 'fehler' => null];
    $user = user_load($id);
    if ($user === null) {
        $result['fehler'] = 'Nutzer nicht gefunden.';
        return $result;
    }
    // Ohne Claude keine Initialisierung. Lieber gar nicht anfangen, als den
    // Posteingang leerzuraeumen und dann ohne Ziel dazustehen.
    if ($initial) {
        [, $probe] = ask_claude([['from' => 'probe@example.com',
            'to' => (string) $user['address'], 'art' => 'Hauptadresse']]);
        if ($probe !== null) {
            $result['fehler'] = 'Claude ist nicht erreichbar, deshalb keine Initialisierung. '
                . $probe;
            return $result;
        }
    }

    try {
        $imap = new Imap((string) $user['host'], (int) $user['port']);
        $imap->login((string) $user['address'], dec((string) $user['password']));
        $folders = $imap->folders();

        if ($initial) {
            // 1. alles in den Posteingang, 2. leere Ordner loeschen.
            // Tiefste Ebene zuerst, sonst haengt ein Elternordner an seinen Kindern.
            $order = $folders;
            usort($order, static fn(array $a, array $b) =>
                substr_count($b['name'], '/') <=> substr_count($a['name'], '/'));
            foreach ($order as $f) {
                if (strtoupper($f['name']) === 'INBOX') {
                    continue;
                }
                // Systemordner bleiben stehen. Nur das Archiv wird geleert - dort
                // liegt echte Post, die mitsortiert werden soll. Gesendet,
                // Entwuerfe, Werbung und Papierkorb bleiben unangetastet.
                if ($f['system'] && strcasecmp($f['name'], 'Archive') !== 0) {
                    continue;
                }
                $uids = $imap->open($f['name'], true);
                if ($uids !== []) {
                    $imap->move($uids, 'INBOX');
                }
                if (!$f['system']) {
                    $imap->delete_folder($f['name']);
                }
            }
            $folders = $imap->folders();
            user_set($id, static function (array $u): array {
                $u['known_folders'] = [];
                $u['last_uid'] = 0;
                $u['knowledge'] = [];
                return $u;
            });
            $user = user_load($id) ?? $user;
        } else {
            $have = array_column($folders, 'name');
            $missing = array_values(array_diff($user['known_folders'] ?? [], $have));
            if ($missing !== []) {
                user_set($id, static function (array $u) use ($missing): array {
                    $u['status'] = 'neu_initialisieren';
                    $u['halt'] = 'Diese Ordner fehlen: ' . implode(', ', array_slice($missing, 0, 6));
                    return $u;
                });
                $result['fehler'] = 'Ordner wurden umbenannt oder geloescht. Bitte neu initialisieren.';
                return $result;
            }
        }

        $uids = $imap->open('INBOX', true);
        $messages = $imap->headers($uids);
        $watermark = (!$initial && ($user['uidvalidity'] ?? '') === $imap->uidvalidity)
            ? (int) ($user['last_uid'] ?? 0) : 0;
        $fresh = array_values(array_filter($messages,
            static fn(array $m) => (int) $m['uid'] > $watermark));
        $result['gelesen'] = count($fresh);

        // Was schon bekannt ist, direkt zuordnen; der Rest geht an Claude.
        $open = [];
        foreach ($fresh as $m) {
            $from = (string) ($m['from'] ?? '');
            $path = alias_of($m['to'], $user);
            $kind = $path === null ? 'primary' : 'alias';
            if (isset($user['knowledge'][$kind][$from])) {
                continue;
            }
            $open[$kind . '|' . $from] ??= ['from' => $from,
                'to' => $path === null ? (string) $user['address'] : ($m['to'][0] ?? ''),
                'art' => $kind === 'alias' ? 'Nebenadresse' : 'Hauptadresse', 'kind' => $kind];
        }
        $reachable = true;
        foreach (array_chunk($open, 60, true) as $batch) {
            // Fortlaufende Nummern je Anfrage, damit die Antwort eindeutig passt.
            $keys = array_keys($batch);
            [$answers, $error] = ask_claude(array_values($batch));
            if ($error !== null) {
                $result['fehler'] = $error;
                $reachable = false;
                break;
            }
            foreach ($answers as $nr => $answer) {
                $q = $open[$keys[$nr]] ?? null;
                if ($q === null || ($answer === 'Alias' && $q['kind'] !== 'alias')) {
                    continue;
                }
                $user['knowledge'][$q['kind']][$q['from']] = $answer;
                unset($open[$keys[$nr]]);
            }
        }
        // Claude war da, hat aber einzelne Zeilen nicht oder unbrauchbar
        // beantwortet. Die kommen nach Persoenlich, sonst bliebe der Merker
        // stehen und keine spaetere Mail wuerde je sortiert.
        if ($reachable) {
            foreach ($open as $key => $q) {
                $user['knowledge'][$q['kind']][$q['from']] =
                    $q['kind'] === 'alias' ? 'Alias' : 'Persoenlich';
                unset($open[$key]);
            }
        }

        $moves = [];
        foreach ($fresh as $m) {
            $from = (string) ($m['from'] ?? '');
            $path = alias_of($m['to'], $user);
            $kind = $path === null ? 'primary' : 'alias';
            $known = $user['knowledge'][$kind][$from] ?? null;
            $folder = is_string($known) ? resolve_answer($known, $path) : null;
            if ($folder !== null && folder_shape_ok($folder)) {
                $moves[$folder][] = $m['uid'];
            }
        }

        $have = array_column($imap->folders(), 'name');
        $used = [];
        foreach ($moves as $folder => $list) {
            if (!in_array($folder, $have, true)) {
                $parts = explode('/', $folder);
                for ($i = 1; $i <= count($parts); $i++) {
                    $imap->create(implode('/', array_slice($parts, 0, $i)));
                }
            }
            $imap->open('INBOX', true);
            $result['verschoben'] += $imap->move($list, $folder);
            $used[] = $folder;
        }
        $result['offen'] = count($open);

        $high = 0;
        foreach ($messages as $m) {
            $high = max($high, (int) $m['uid']);
        }
        $knowledge = $user['knowledge'] ?? [];
        $advance = $open === [];
        $uidvalidity = $imap->uidvalidity;
        user_set($id, static function (array $u) use ($knowledge, $advance, $high,
                                                      $uidvalidity, $used, $initial): array {
            $u['knowledge'] = $knowledge;
            $u['uidvalidity'] = $uidvalidity;
            if ($advance) {
                $u['last_uid'] = max((int) ($u['last_uid'] ?? 0), $high);
            }
            $u['known_folders'] = array_values(array_unique(
                array_merge($u['known_folders'] ?? [], $used)));
            $u['last_run'] = gmdate('c');
            $u['last_error'] = null;
            if ($initial) {
                $u['status'] = 'bereit';
                $u['halt'] = null;
                $u['initialised'] = gmdate('c');
            }
            return $u;
        });
    } catch (ImapError $e) {
        $result['fehler'] = $e->getMessage();
        user_set($id, static function (array $u) use ($e): array {
            $u['last_error'] = $e->getMessage();
            $u['last_run'] = gmdate('c');
            return $u;
        });
    }
    return $result;
}

/** @return list<array{name:string,ok:bool,detail:string,required:bool}> */
function checks_system(): array
{
    $out = [];

    $php = PHP_VERSION;
    $out[] = ['name' => 'PHP-Version', 'ok' => PHP_VERSION_ID >= 80100,
        'detail' => PHP_VERSION_ID >= 80100 ? $php : $php . ' - noetig ist 8.1 oder neuer',
        'required' => true];

    foreach (['sodium' => 'php-sodium', 'mbstring' => 'php-mbstring',
              'curl' => 'php-curl'] as $ext => $pkg) {
        $have = extension_loaded($ext);
        $out[] = ['name' => 'Erweiterung ' . $ext, 'ok' => $have,
            'detail' => $have ? 'geladen' : 'apt install ' . $pkg, 'required' => true];
    }

    $limit = trim((string) ini_get('memory_limit'));
    $bytes = ini_bytes($limit);
    $out[] = ['name' => 'Speicherlimit', 'ok' => $bytes < 0 || $bytes >= 128 * 1024 * 1024,
        'detail' => $limit === '' ? 'unbekannt' : ($bytes < 0 ? 'ohne Begrenzung' : $limit),
        'required' => false];

    $dir = data_dir();
    $writable = (is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir);
    $out[] = ['name' => 'Datenverzeichnis', 'ok' => $writable,
        'detail' => $writable ? $dir . ' beschreibbar' : $dir . ' nicht beschreibbar',
        'required' => true];

    $free = @disk_free_space($dir) ?: 0;
    $out[] = ['name' => 'Freier Speicher', 'ok' => $free >= 100 * 1024 * 1024,
        'detail' => $free > 0 ? round($free / 1048576) . ' MB frei' : 'unbekannt',
        'required' => false];

    foreach (net_checks(DEFAULT_API_URL) as $row) {
        $out[] = $row;
    }
    return $out;
}

/** Netzwerk: DNS und die drei Ports, die der Server nach aussen braucht. */

/** Netzwerk: DNS und die drei Ports, die der Server nach aussen braucht. */
function net_checks(string $url): array
{
    $out = [];
    $apiHost = (string) (parse_url($url, PHP_URL_HOST) ?: 'api.anthropic.com');

    $reach = static function (string $host, int $port): array {
        if (gethostbyname($host) === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return [false, $host . ' laesst sich nicht aufloesen - DNS pruefen (Port 53)'];
        }
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 3);
        if ($sock === false) {
            return [false, net_hint($host, $port, $errno, trim($errstr))];
        }
        fclose($sock);
        return [true, $host . ':' . $port . ' erreichbar'];
    };

    [$ok, $detail] = $reach($apiHost, 443);
    $out[] = ['name' => 'Ausgehend 443 - Claude', 'ok' => $ok, 'detail' => $detail,
        'required' => true];

    // Von den IMAP-Servern reicht einer. Wer nur iCloud einsetzt, braucht
    // Google nicht - die einzelnen Zeilen sind deshalb nur Hinweise.
    $anyImap = false;
    foreach ([['imap.mail.me.com', 'iCloud'], ['imap.gmail.com', 'Google']] as [$host, $label]) {
        [$ok, $detail] = $reach($host, 993);
        $anyImap = $anyImap || $ok;
        $out[] = ['name' => $label . ' - imap:993', 'ok' => $ok, 'detail' => $detail,
            'required' => false];
    }
    $out[] = ['name' => 'Ausgehend 993 - IMAP', 'ok' => $anyImap,
        'detail' => $anyImap ? 'offen' : 'Kein IMAP-Server erreichbar. Port 993 freigeben.',
        'required' => true];
    return $out;
}

function ini_bytes(string $value): int
{
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $n = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g' => $n * 1073741824,
        'm' => $n * 1048576,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Eine einzelne Zeile: antwortet die Gegenstelle mit diesem Token? */

/** Eine einzelne Zeile: antwortet die Gegenstelle mit diesem Token? */
function check_key(string $url, string $key, ?string $kind = null): array
{
    $kind = $kind !== null && isset(API_KINDS[$kind]) ? $kind : api_kind();
    if (!str_starts_with($url, 'https://')) {
        return ['name' => 'Verbindung zur KI', 'ok' => false,
            'detail' => 'Die API-URL muss mit https:// beginnen.', 'required' => true];
    }
    if ($key === '') {
        return ['name' => 'Verbindung zur KI', 'ok' => false,
            'detail' => 'Noch kein Token eingetragen.', 'required' => true];
    }
    if (($cached = key_probe_cached($url . '|' . $kind, $key)) !== null) {
        return $cached;
    }
    foreach (net_checks($url) as $row) {
        if ($row['required'] && !$row['ok'] && str_contains($row['name'], '443')) {
            return ['name' => 'Verbindung zur KI', 'ok' => false,
                'detail' => $row['detail'], 'required' => true];
        }
    }
    // Der Test laeuft mit dem eingetippten Token, nicht mit dem gespeicherten.
    [$payload, $headers] = api_request($kind, 'Antworte nur mit: ok', 16, $key);
    [$code, $raw, $error] = https_post($url, $payload, $headers);
    $ok = $error === null && $code >= 200 && $code < 300;
    if ($error !== null) {
        $detail = $error;
    } elseif ($ok) {
        $detail = 'Die KI antwortet.';
    } elseif ($code === 401 || $code === 403) {
        $detail = 'Token abgelehnt (' . $code . '). Tippfehler oder Leerzeichen?';
    } else {
        $body = json_decode((string) $raw, true);
        $detail = 'HTTP ' . $code . ' ' . (string) ($body['error']['message'] ?? '');
    }
    $row = ['name' => 'Verbindung zur KI', 'ok' => $ok, 'detail' => trim($detail),
        'required' => true];
    key_probe_store($url . '|' . $kind, $key, $row);
    return $row;
}

/** Die Liste wird im Sekundentakt geholt - der Schluessel aber nicht jedes Mal geprueft. */

/** Die Liste wird im Sekundentakt geholt - der Schluessel aber nicht jedes Mal geprueft. */
const KEY_PROBE_TTL = 20;

function key_probe_id(string $url, string $key): string
{
    return hash('sha256', $url . "\0" . $key);
}

function key_probe_cached(string $url, string $key): ?array
{
    $entry = jread(data_dir() . '/keycheck.json');
    return ($entry['id'] ?? '') === key_probe_id($url, $key)
        && time() - (int) ($entry['t'] ?? 0) < KEY_PROBE_TTL
        ? (array) $entry['row'] : null;
}

function key_probe_store(string $url, string $key, array $row): void
{
    jwrite(data_dir() . '/keycheck.json',
        ['id' => key_probe_id($url, $key), 't' => time(), 'row' => $row]);
}

function session_begin(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('icm');
    session_set_cookie_params(['lifetime' => 0, 'httponly' => true,
        'samesite' => 'Strict', 'secure' => $https]);
    session_start();
}

function csrf(): string
{
    return $_SESSION['csrf'] ??= new_token();
}

function csrf_ok(): void
{
    // Ohne Token in der Sitzung darf nichts durchgehen - zwei leere Werte
    // sind sonst gleich und die Pruefung waere wirkungslos.
    $want = (string) ($_SESSION['csrf'] ?? '');
    if ($want === '' || !hash_equals($want, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('CSRF-Token stimmt nicht. Seite neu laden.');
    }
}

/** Weiterleitung auf diese Datei, notfalls mit Abfrage. Nie relativ. */

/** Weiterleitung auf diese Datei, notfalls mit Abfrage. Nie relativ. */
function go(string $query = ''): never
{
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    header('Location: ' . $path . ($query !== '' ? '?' . $query : ''));
    exit;
}

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Einfache Mengenbegrenzung je Absender und Zeitfenster. */

/** Einfache Mengenbegrenzung je Absender und Zeitfenster. */
function rate_ok(string $bucket, int $max, int $window): bool
{
    $now = time();
    $state = locked(data_dir() . '/rate.json',
        static function (array $d) use ($bucket, $now, $window): array {
            foreach ($d as $k => $v) {
                if ($now - (int) ($v['start'] ?? 0) >= $window) {
                    unset($d[$k]);
                }
            }
            $e = $d[$bucket] ?? ['start' => $now, 'n' => 0];
            $e['n'] = (int) $e['n'] + 1;
            $d[$bucket] = $e;
            return $d;
        });
    return (int) ($state[$bucket]['n'] ?? 0) <= $max;
}

function throttled(string $bucket): bool
{
    $entry = jread(data_dir() . '/logins.json')[$bucket] ?? null;
    return is_array($entry) && ($entry['n'] ?? 0) >= LOGIN_MAX
        && time() - ($entry['t'] ?? 0) < LOGIN_LOCK;
}

function note_try(string $bucket, bool $ok): void
{
    locked(data_dir() . '/logins.json', static function (array $d) use ($bucket, $ok): array {
        if ($ok) {
            unset($d[$bucket]);
        } else {
            $e = $d[$bucket] ?? ['n' => 0, 't' => 0];
            if (time() - $e['t'] >= LOGIN_LOCK) {
                $e = ['n' => 0, 't' => 0];
            }
            $d[$bucket] = ['n' => $e['n'] + 1, 't' => time()];
        }
        foreach ($d as $k => $v) {
            if (time() - ($v['t'] ?? 0) > LOGIN_LOCK * 4) {
                unset($d[$k]);
            }
        }
        return $d;
    });
}

function json_out(int $code, array $data): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function head(string $title, ?string $nonce = null): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; "
        . ($nonce !== null ? "script-src 'nonce-{$nonce}'; connect-src 'self'; " : '')
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    ?><!doctype html>
<html lang="de"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>
<style>
 :root{--bg:#f5f5f7;--card:#fff;--fg:#1c1c1e;--muted:#70707a;--line:#e2e2e7;
  --accent:#0a6cff;--accent-fg:#fff;--ok:#0f7a58;--okbg:#e3f4ed;--err:#c0392b;
  --errbg:#fce7e4;--warn:#8a6100;--warnbg:#fbf1d8;--mono:#f0f0f3}
 @media(prefers-color-scheme:dark){:root{--bg:#101013;--card:#1a1a1e;--fg:#ececee;
  --muted:#9797a0;--line:#2b2b31;--accent:#4d92ff;--accent-fg:#06121f;--ok:#45c79a;
  --okbg:#123027;--err:#ff7b6b;--errbg:#361b17;--warn:#e5be5c;--warnbg:#362c13;--mono:#212127}}
 *{box-sizing:border-box}
 body{margin:0;background:var(--bg);color:var(--fg);
  font:14.5px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
 header{background:var(--card);border-bottom:1px solid var(--line)}
 header .in{max-width:1080px;margin:0 auto;padding:0 26px;display:flex;align-items:center;
  gap:24px;height:50px}
 header b{font-size:15px}
 header a{color:var(--muted);text-decoration:none}
 header a.on{color:var(--fg)}
 header form{margin-left:auto}
 main{max-width:1080px;margin:0 auto;padding:24px 26px 60px}
 main.narrow{max-width:400px;padding-top:64px}
 h1{font-size:18px;margin:0 0 16px}
 h2{font-size:13.5px;margin:0 0 12px;color:var(--muted);text-transform:uppercase;
  letter-spacing:.05em}
 .card{background:var(--card);border:1px solid var(--line);border-radius:10px;
  padding:18px 20px;margin-bottom:14px}
 .cols{display:grid;grid-template-columns:1fr 320px;gap:14px;align-items:start}
 @media(max-width:880px){.cols{grid-template-columns:1fr}}
 table{width:100%;border-collapse:collapse}
 th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);
  text-align:left;padding:0 10px 8px;font-weight:600}
 td{padding:9px 10px;border-top:1px solid var(--line)}
 tr:first-child td{border-top:0}
 a{color:var(--accent)}
 label{display:block;font-size:12.5px;font-weight:600;margin:0 0 5px}
 input,select,textarea{width:100%;padding:8px 10px;font:inherit;font-size:14px;
  color:var(--fg);background:var(--bg);border:1px solid var(--line);border-radius:7px}
 textarea{min-height:60px;font-family:ui-monospace,Consolas,monospace;font-size:13px}
 input:focus,select:focus,textarea:focus{outline:2px solid var(--accent);outline-offset:-1px}
 .f{margin-bottom:12px}
 .two{display:grid;grid-template-columns:1fr 90px;gap:10px}
 .hint{font-size:12px;color:var(--muted);margin-top:4px}
 button{padding:8px 14px;font:inherit;font-size:14px;font-weight:600;color:var(--accent-fg);
  background:var(--accent);border:0;border-radius:7px;cursor:pointer;white-space:nowrap}
 button:disabled{opacity:.4;cursor:default}
 button.q{background:transparent;color:var(--accent);border:1px solid var(--line)}
 button.d{background:transparent;color:var(--err);border:1px solid var(--line)}
 .msg{border-radius:7px;padding:10px 12px;font-size:13.5px;margin:0 0 14px}
 .msg.ok{background:var(--okbg);color:var(--ok)}
 .msg.err{background:var(--errbg);color:var(--err)}
 .msg.warn{background:var(--warnbg);color:var(--warn)}
 .tag{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11.5px;font-weight:600}
 .tag.ok{background:var(--okbg);color:var(--ok)}
 .tag.warn{background:var(--warnbg);color:var(--warn)}
 .tag.err{background:var(--errbg);color:var(--err)}
 code{background:var(--mono);padding:1px 5px;border-radius:5px;font-size:12.5px;word-break:break-all}
 pre{background:var(--mono);border-radius:7px;padding:11px;overflow:auto;font-size:12.5px;
  white-space:pre-wrap;word-break:break-word;margin:0}
 .muted{color:var(--muted);font-size:12.5px}
 .inline{display:inline}
 details>summary{cursor:pointer;font-size:13.5px;color:var(--accent);list-style:none}
 details>summary::-webkit-details-marker{display:none}
 details[open]>summary{margin-bottom:14px}
 ul.chk{list-style:none;margin:0;padding:0}
 ul.chk li{display:flex;gap:10px;padding:7px 0;border-top:1px solid var(--line);font-size:13.5px}
 ul.chk li:first-child{border-top:0}
 ul.chk .st{width:16px;flex:none;font-weight:700}
 ul.chk .nm{width:210px;flex:none}
 ul.chk .dt{color:var(--muted);font-size:12.5px}
 li.good .st,li.good .nm{color:var(--ok)}
 li.bad .st,li.bad .nm{color:var(--err)}
 ol.steps{display:flex;gap:8px;list-style:none;margin:0 0 22px;padding:0;font-size:12.5px}
 ol.steps li{display:flex;align-items:center;gap:7px;color:var(--muted);flex:1}
 ol.steps .n{display:inline-flex;width:21px;height:21px;border-radius:50%;flex:none;
  align-items:center;justify-content:center;font-weight:700;font-size:11.5px;
  background:var(--mono);color:var(--muted)}
 ol.steps li.on{color:var(--fg);font-weight:600}
 ol.steps li.on .n{background:var(--accent);color:var(--accent-fg)}
 ol.steps li.done .n{background:var(--okbg);color:var(--ok)}
</style></head><body>
    <?php
}

function msgs(?string $notice, ?string $error): void
{
    if ($notice !== null) {
        echo '<div class="msg ok">' . e($notice) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="msg err">' . e($error) . '</div>';
    }
}

/**
 * Einrichtung in drei Schritten. Der Stand liegt in der Sitzung, nicht in
 * versteckten Feldern - so laesst sich kein Schritt ueberspringen.
 */

function stamp(?string $iso): string
{
    $t = $iso !== null && $iso !== '' ? strtotime($iso) : false;
    return $t === false ? '-' : date('d.m.Y H:i', $t);
}

/** Blendet Uhrzeiten oder Minuten aus, je nach gewaehltem Zeitplan. */

/**
 * Holt eine Pruefliste im Sekundentakt nach und schaltet den Knopf frei.
 * Immer nur eine Anfrage zugleich, sonst stauen sich die Zeitablaeufe.
 */
function checklist_script(string $nonce, string $endpoint, string $listId,
                          string $buttonId, string $hintId, bool $withKey = false): void
{
    ?>
<script nonce="<?= e($nonce) ?>">
(function () {
  var list = document.getElementById(<?= json_encode($listId) ?>);
  var go = document.getElementById(<?= json_encode($buttonId) ?>);
  var hint = document.getElementById(<?= json_encode($hintId) ?>);
  var withKey = <?= $withKey ? 'true' : 'false' ?>;
  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c];
    });
  }
  function again() { setTimeout(tick, 1000); }
  function tick() {
    var q = <?= json_encode($endpoint) ?>;
    if (withKey) {
      q += '&url=' + encodeURIComponent(document.getElementById('au').value)
         + '&key=' + encodeURIComponent(document.getElementById('ak').value);
    }
    fetch(q, {cache: 'no-store'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var all = true, html = '';
        (d.pruefungen || []).forEach(function (c) {
          if (c.required && !c.ok) { all = false; }
          html += '<li class="' + (c.ok ? 'good' : 'bad') + '"><span class="st">'
               + (c.ok ? '✓' : '✗') + '</span><span class="nm">' + esc(c.name)
               + '</span><span class="dt">' + esc(c.detail) + '</span></li>';
        });
        list.innerHTML = html;
        go.disabled = !all;
        hint.textContent = all ? 'Alles bereit.' : 'Wird jede Sekunde erneut geprueft.';
      })
      .catch(function () {})
      .then(again);
  }
  tick();
  var kd = document.getElementById('kd');
  if (kd) {
    kd.addEventListener('change', function () {
      var m = document.createElement('input');
      m.type = 'hidden'; m.name = 'wechsel'; m.value = '1';
      kd.form.appendChild(m);
      kd.form.submit();
    });
  }
  var copy = document.getElementById('copy');
  if (copy) {
    copy.addEventListener('click', function () {
      var t = document.getElementById('auftrag').textContent;
      if (navigator.clipboard) { navigator.clipboard.writeText(t); }
      copy.textContent = 'Kopiert';
      setTimeout(function () { copy.textContent = 'Text kopieren'; }, 1500);
    });
  }
})();
</script>
    <?php
}

/** Der Auftrag, den der Betreiber der KI vorlegt. Kurz und ohne Beiwerk. */
function routine_text(string $kind = 'messages', string $name = 'icm'): string
{
    $folders = '';
    foreach (folders_catalogue() as $folder => $what) {
        $folders .= "- {$folder}: {$what}\n";
    }
    $weg = $kind === 'routine' ? 'per HTTP an diese Routine' : 'ueber die Messages-API';
    return "Auftrag fuer {$name} (Postfach sortieren)\n\n"
        . "Ein Webserver schickt dir {$weg} Zeilen der Form\n"
        . "  0) absender@example.com -> empfaenger@example.com (Hauptadresse)\n"
        . "Du antwortest ausschliesslich mit JSON: {\"0\": \"Ordner\"}\n\n"
        . "Erlaubte Ordner:\n" . $folders . "\n"
        . "Regeln:\n"
        . (array_key_exists('Alias', folders_catalogue())
            ? "- (Nebenadresse) bedeutet Alias - ausser Sicherheit oder Finanzen, die gehen vor.\n"
            : '')
        . "- Finanzen nur, wo echtes Geld dahintersteht. Newsletter eines Zahlungsdienstes\n"
        . "  gehoeren nach Werbung.\n"
        . "- Unsicher? Persoenlich.\n\n"
        . "Der Server liest nur Absender und Empfaenger, nie den Inhalt einer Nachricht.\n"
        . "Gebraucht werden dafuer die API-URL und ein API-Token.\n";
}

/**
 * Holt eine Pruefliste im Sekundentakt nach und schaltet den Knopf frei.
 * Immer nur eine Anfrage zugleich, sonst stauen sich die Zeitablaeufe.
 */


// ===========================================================================
// Konten
// ===========================================================================

function sa_find(string $address): ?array
{
    foreach (users() as $u) {
        if (strcasecmp((string) $u['address'], $address) === 0) {
            return $u;
        }
    }
    return null;
}

/**
 * Legt ein Konto an, nachdem die Anmeldedaten am Server geprueft wurden.
 * @return array{0:?array,1:?string,2:?string} [Konto, Zugangscode, Fehler]
 */
function sa_add(string $address, string $password): array
{
    $address = strtolower(trim($address));
    if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
        return [null, null, 'Das ist keine gueltige E-Mail-Adresse.'];
    }
    if ($password === '') {
        return [null, null, 'Das app-spezifische Passwort fehlt.'];
    }
    if (sa_find($address) !== null) {
        return [null, null, 'Diese Adresse ist schon eingetragen.'];
    }
    if (count(users()) >= SA_MAX_KONTEN) {
        return [null, null, 'Es sind keine Plaetze mehr frei.'];
    }
    try {
        (new Imap(SA_HOST, SA_PORT))->login($address, $password);
    } catch (ImapError $e) {
        return [null, null, $e->getMessage()];
    }
    $code = new_token();
    $id = bin2hex(random_bytes(8));
    jwrite(user_path($id), [
        'id' => $id, 'address' => $address, 'password' => enc($password),
        'host' => SA_HOST, 'port' => SA_PORT,
        // Ohne Nebenadressen: leer, damit jede Mail als Hauptadresse gilt.
        'privateappleid' => false, 'domains' => [], 'addresses' => [],
        'knowledge' => [], 'known_folders' => [],
        'schedule' => ['mode' => 'interval', 'interval' => SA_INTERVAL, 'times' => []],
        'code_hash' => hash('sha256', $code),
        'status' => 'neu', 'created' => gmdate('c'),
    ]);
    sodium_memzero($password);
    return [user_load($id), $code, null];
}

/** Kontozugang ueber den einmal angezeigten Code. */
function sa_auth(string $address, string $code): ?array
{
    $u = sa_find(strtolower(trim($address)));
    if ($u === null) {
        return null;
    }
    return hash_equals((string) ($u['code_hash'] ?? ''), hash('sha256', trim($code))) ? $u : null;
}

// ===========================================================================
// Einrichtung
// ===========================================================================

function sa_setup(): never
{
    if (!is_array($_SESSION['setup'] ?? null)) {
        $_SESSION['setup'] = ['kind' => 'messages'];
    }
    $kind = (string) ($_SESSION['setup']['kind'] ?? 'messages');
    $error = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_ok();
        $kind = isset(API_KINDS[(string) ($_POST['api_kind'] ?? '')])
            ? (string) $_POST['api_kind'] : 'messages';
        $_SESSION['setup']['kind'] = $kind;
        if (($_POST['wechsel'] ?? '') !== '') {
            go();                        // nur die Zustellart gewechselt
        }
        $url = trim((string) ($_POST['api_url'] ?? '')) ?: DEFAULT_API_URL;
        $key = trim((string) ($_POST['api_key'] ?? ''));
        $zugang = trim((string) ($_POST['zugang'] ?? ''));
        $offen = array_filter(checks_system(), static fn(array $c) => $c['required'] && !$c['ok']);
        if ($offen !== []) {
            $error = 'Noch offen: ' . implode(', ', array_column($offen, 'name'));
        } elseif (!($row = check_key($url, $key, $kind))['ok']) {
            $error = $row['detail'];
        } else {
            config_set(static fn(array $c): array => [
                'setup_done' => true,
                'api_url' => $url, 'api_key' => enc($key), 'api_kind' => $kind,
                'model' => DEFAULT_MODEL,
                'zugang_hash' => $zugang === '' ? '' : password_hash($zugang, PASSWORD_DEFAULT),
                'created' => gmdate('c'),
            ]);
            unset($_SESSION['setup']);
            session_regenerate_id(true);
            go();
        }
    }

    $nonce = base64_encode(random_bytes(16));
    head('Postfach sortieren - einrichten', $nonce);
    $routine = $kind === 'routine';
    ?>
<main class="narrow" style="max-width:600px">
  <h1>Einrichten</h1>
  <?php msgs(null, $error); ?>
  <section class="card">
    <h2>Voraussetzungen</h2>
    <ul class="chk" id="chk"><li><span class="st">&hellip;</span><span class="nm">wird geprueft</span></li></ul>
  </section>
  <section class="card">
    <h2>Was der Server nach aussen braucht</h2>
    <table>
      <tr><td>443/TCP</td><td class="muted">zur KI</td></tr>
      <tr><td>993/TCP</td><td class="muted">zu <?= e(SA_HOST) ?>, IMAP ueber TLS</td></tr>
      <tr><td>53</td><td class="muted">DNS</td></tr>
      <tr><td>80 und 443</td><td class="muted">eingehend, fuer diese Seite</td></tr>
    </table>
  </section>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <section class="card">
      <h2>1. Woher die Antwort kommt</h2>
      <div class="f"><select id="kd" name="api_kind">
        <?php foreach (API_KINDS as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $k === $kind ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select></div>
    </section>
    <section class="card">
      <h2>2. Diesen Text in die KI geben</h2>
      <p class="muted" style="margin:0 0 10px"><?= $routine
          ? 'Auf claude.ai/code/routines eine Routine anlegen und den Text als Auftrag '
            . 'einsetzen. Danach gibt die Routine dir eine API-URL und ein Token.'
          : 'Der Text beschreibt, was die KI tun soll. Das Token kommt aus der '
            . 'Anthropic-Konsole.' ?></p>
      <pre id="auftrag"><?= e(routine_text($kind, 'simple-apple')) ?></pre>
      <div style="margin-top:10px"><button class="q" type="button" id="copy">Text kopieren</button></div>
    </section>
    <section class="card">
      <h2>3. Zugang eintragen</h2>
      <div class="f"><label for="au">API-URL</label>
        <input id="au" name="api_url" value="<?= e($routine ? '' : DEFAULT_API_URL) ?>"
               placeholder="<?= e($routine ? 'aus der Routine' : DEFAULT_API_URL) ?>"></div>
      <div class="f"><label for="ak">API-Token</label>
        <input id="ak" name="api_key" type="password" required>
        <div class="hint">Wird verschluesselt abgelegt und nie wieder angezeigt.</div></div>
    </section>
    <section class="card">
      <h2>4. Wer darf sich eintragen</h2>
      <div class="f"><label for="zg">Zugangscode</label>
        <input id="zg" name="zugang" type="text">
        <div class="hint">Ohne Code kann jeder, der die Seite kennt, ein Postfach
          eintragen - und dabei dein KI-Token verbrauchen. Leer lassen nur, wenn
          die Seite ohnehin nicht oeffentlich erreichbar ist.</div></div>
      <button id="go" type="submit" disabled>Einrichten</button>
      <div class="hint" id="gohint">Erst moeglich, wenn alles gruen ist.</div>
    </section>
  </form>
<?php checklist_script($nonce, '?check=all', 'chk', 'go', 'gohint', true); ?>
</main></body></html>
    <?php
    exit;
}

// ===========================================================================
// Oberflaeche
// ===========================================================================

function sa_head(?string $notice, ?string $error): void
{
    ?>
<header><div class="in"><b>Postfach sortieren</b>
  <a href="?">Eintragen</a><a href="?konto">Mein Postfach</a></div></header>
<main class="narrow" style="max-width:520px">
  <?php msgs($notice, $error);
}

function sa_page_neu(?string $notice, ?string $error): never
{
    $zugang = (string) (config()['zugang_hash'] ?? '') !== '';
    head('Postfach sortieren');
    sa_head($notice, $error);
    ?>
  <section class="card">
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="aktion" value="anlegen">
      <div class="f"><label for="ad">iCloud-Adresse</label>
        <input id="ad" name="address" type="email" required autofocus></div>
      <div class="f"><label for="pw">App-spezifisches Passwort</label>
        <input id="pw" name="password" type="password" required>
        <div class="hint">appleid.apple.com &rarr; Anmeldung und Sicherheit &rarr;
          App-spezifische Passwoerter. Nicht das Passwort deiner Apple ID.</div></div>
      <?php if ($zugang): ?>
        <div class="f"><label for="zg">Zugangscode</label>
          <input id="zg" name="zugang" type="password" required></div>
      <?php endif; ?>
      <button type="submit">Postfach eintragen</button>
    </form>
  </section>
  <section class="card">
    <h2>Was danach passiert</h2>
    <ol style="margin:0;padding-left:18px">
      <li>Alles aus dem Archiv und den eigenen Ordnern geht in den Posteingang.</li>
      <li>Die eigenen Ordner werden geloescht und neu angelegt.</li>
      <li>Jede Nachricht wird einsortiert.</li>
      <li>Danach alle <?= SA_INTERVAL ?> Minuten die neue Post.</li>
    </ol>
    <p class="muted" style="margin:12px 0 0">Gesendet, Entwuerfe, Werbung und
      Papierkorb bleiben unberuehrt. Gelesen werden nur Absender und Empfaenger,
      nie der Inhalt einer Nachricht. Geloescht wird keine Nachricht.</p>
  </section>
  <section class="card">
    <h2>Ordner</h2>
    <table>
      <?php foreach (folders_catalogue() as $name => $was): ?>
        <tr><td><?= e($name) ?></td><td class="muted"><?= e($was) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </section>
</main></body></html>
    <?php
    exit;
}

function sa_page_code(array $u, string $code): never
{
    head('Postfach sortieren');
    sa_head(null, null);
    ?>
  <section class="card">
    <div style="font-weight:600;margin:0 0 12px"><?= e($u['address']) ?> ist eingetragen</div>
    <div class="f"><label>Dein Zugangscode</label><pre><?= e($code) ?></pre>
      <div class="hint">Jetzt sichern. Er wird nur dieses eine Mal angezeigt und
        ist der einzige Weg zurueck zu diesem Postfach.</div></div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="aktion" value="start">
      <button type="submit">Jetzt aufraeumen</button>
      <div class="hint">Das dauert je nach Postfach ein bis zwei Minuten.</div>
    </form>
  </section>
</main></body></html>
    <?php
    exit;
}

function sa_page_konto(?array $u, ?string $notice, ?string $error): never
{
    head('Postfach sortieren');
    sa_head($notice, $error);
    if ($u === null) {
        ?>
  <section class="card">
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="aktion" value="anmelden">
      <div class="f"><label for="ad">iCloud-Adresse</label>
        <input id="ad" name="address" type="email" required autofocus></div>
      <div class="f"><label for="cd">Zugangscode</label>
        <input id="cd" name="code" type="password" required></div>
      <button type="submit">Anzeigen</button>
    </form>
  </section>
</main></body></html>
        <?php
        exit;
    }
    $st = (string) ($u['status'] ?? 'neu');
    ?>
  <section class="card">
    <div style="font-weight:600;margin:0 0 12px"><?= e($u['address']) ?></div>
    <table>
      <tr><td>Zustand</td><td class="muted"><?= e(match ($st) {
          'bereit' => 'sortiert alle ' . SA_INTERVAL . ' Minuten',
          'neu_initialisieren' => (string) ($u['halt'] ?? 'angehalten'),
          default => 'noch nicht aufgeraeumt',
      }) ?></td></tr>
      <tr><td>Letzter Lauf</td><td class="muted"><?= e(stamp($u['last_run'] ?? null)) ?></td></tr>
      <tr><td>Bekannte Absender</td><td class="muted"><?= array_sum(array_map('count',
          (array) ($u['knowledge'] ?? []))) ?></td></tr>
      <tr><td>Ordner</td><td class="muted"><?= count($u['known_folders'] ?? []) ?></td></tr>
    </table>
    <?php if (!empty($u['last_error'])): ?>
      <div class="msg err" style="margin:14px 0 0"><?= e((string) $u['last_error']) ?></div>
    <?php endif; ?>
    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="aktion" value="sortieren">
        <button <?= $st === 'bereit' ? '' : 'class="q"' ?> type="submit">Jetzt sortieren</button></form>
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="aktion" value="start">
        <button <?= $st === 'bereit' ? 'class="d"' : '' ?> type="submit">
          <?= $st === 'bereit' ? 'Neu aufraeumen' : 'Aufraeumen' ?></button></form>
      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="aktion" value="entfernen">
        <button class="d" type="submit">Postfach entfernen</button></form>
    </div>
    <?php if ($st === 'bereit'): ?>
      <div class="hint" style="margin-top:8px">Neu aufraeumen holt alles zurueck in
        den Posteingang und sortiert von vorne.</div>
    <?php endif; ?>
  </section>
  <section class="card">
    <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <input type="hidden" name="aktion" value="abmelden">
      <button class="q" type="submit">Abmelden</button></form>
  </section>
</main></body></html>
    <?php
    exit;
}

// ===========================================================================
// Ablauf
// ===========================================================================

function sa_web(): never
{
    session_begin();
    $setup = (config()['setup_done'] ?? false) === true;

    if (isset($_GET['check'])) {
        // Nur waehrend der Einrichtung offen, danach zu.
        if ($setup || !is_array($_SESSION['setup'] ?? null)) {
            json_out(403, ['error' => 'Nicht erlaubt']);
        }
        if (!rate_ok('check|' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 90, 60)) {
            json_out(429, ['error' => 'Zu viele Anfragen']);
        }
        $kind = isset(API_KINDS[(string) ($_SESSION['setup']['kind'] ?? '')])
            ? (string) $_SESSION['setup']['kind'] : 'messages';
        json_out(200, ['pruefungen' => array_merge(checks_system(), [check_key(
            trim((string) ($_GET['url'] ?? '')) ?: DEFAULT_API_URL,
            trim((string) ($_GET['key'] ?? '')), $kind)])]);
    }

    if (!$setup) {
        sa_setup();
    }

    $notice = null;
    $error = null;
    $konto = isset($_SESSION['konto']) ? user_load((string) $_SESSION['konto']) : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_ok();
        $aktion = (string) ($_POST['aktion'] ?? '');
        $bucket = 'sa|' . (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if ($aktion === 'anlegen') {
            if (throttled($bucket)) {
                $error = 'Zu viele Versuche. Spaeter erneut.';
            } else {
                $hash = (string) (config()['zugang_hash'] ?? '');
                if ($hash !== '' && !password_verify((string) ($_POST['zugang'] ?? ''), $hash)) {
                    note_try($bucket, false);
                    $error = 'Der Zugangscode stimmt nicht.';
                } else {
                    [$u, $code, $fehler] = sa_add((string) ($_POST['address'] ?? ''),
                        (string) ($_POST['password'] ?? ''));
                    if ($fehler !== null) {
                        note_try($bucket, false);
                        $error = $fehler;
                    } else {
                        note_try($bucket, true);
                        session_regenerate_id(true);
                        $_SESSION['konto'] = $u['id'];
                        $_SESSION['csrf'] = new_token();
                        sa_page_code($u, (string) $code);
                    }
                }
            }
        } elseif ($aktion === 'anmelden') {
            if (throttled($bucket)) {
                $error = 'Zu viele Versuche. Spaeter erneut.';
            } else {
                $u = sa_auth((string) ($_POST['address'] ?? ''), (string) ($_POST['code'] ?? ''));
                if ($u === null) {
                    note_try($bucket, false);
                    $error = 'Adresse oder Code stimmt nicht.';
                } else {
                    note_try($bucket, true);
                    session_regenerate_id(true);
                    $_SESSION['konto'] = $u['id'];
                    $_SESSION['csrf'] = new_token();
                    go('konto');
                }
            }
        } elseif ($aktion === 'abmelden') {
            $_SESSION = [];
            session_destroy();
            go();
        } elseif ($konto !== null) {
            $id = (string) $konto['id'];
            if ($aktion === 'start' || $aktion === 'sortieren') {
                $r = run_cycle($id, $aktion === 'start');
                $konto = user_load($id);
                if ($r['fehler'] !== null) {
                    $error = $r['fehler'];
                } else {
                    $notice = sprintf('%d gelesen, %d einsortiert.',
                        $r['gelesen'], $r['verschoben']);
                }
            } elseif ($aktion === 'entfernen') {
                @unlink(user_path($id));
                @unlink(user_path($id) . '.lock');
                $_SESSION = [];
                session_destroy();
                go();
            }
        }
    }

    if (isset($_GET['konto']) || $konto !== null) {
        sa_page_konto($konto, $notice, $error);
    }
    sa_page_neu($notice, $error);
}

function sa_cron(): never
{
    if ((config()['setup_done'] ?? false) !== true) {
        bail('Noch nicht eingerichtet.');
    }
    $now = time();
    foreach (users() as $u) {
        if ((string) ($u['status'] ?? '') !== 'bereit' || !schedule_due($u, $now)) {
            continue;
        }
        $r = run_cycle((string) $u['id']);
        printf("%s: %d gelesen, %d verschoben%s\n", $u['address'], $r['gelesen'],
            $r['verschoben'], $r['fehler'] !== null ? ' - ' . $r['fehler'] : '');
    }
    exit(0);
}

// ===========================================================================
// Start
// ===========================================================================

foreach (['sodium' => 'php-sodium', 'mbstring' => 'php-mbstring'] as $ext => $pkg) {
    if (!extension_loaded($ext)) {
        bail("PHP-Erweiterung {$ext} fehlt (apt install {$pkg}).");
    }
}
if (!is_dir(data_dir())) {
    @mkdir(data_dir(), 0700, true);
}
@mkdir(data_dir() . '/users', 0700, true);
if (!is_file(data_dir() . '/.htaccess')) {
    @file_put_contents(data_dir() . '/.htaccess', "Require all denied\n");
}

if (PHP_SAPI === 'cli') {
    ($argv[1] ?? '') === 'cron' ? sa_cron() : bail('Aufruf: php simple-apple-v2.php cron');
}

sa_web();
