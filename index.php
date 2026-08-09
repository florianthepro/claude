<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  SSO / TOTP IDENTITY SERVICE  --  Single-File PHP Anwendung
 * ============================================================================
 *
 *  Eine einzige Datei (index.php) stellt bereit:
 *
 *    /              Landingpage - Nutzerkonto im Vordergrund (Login/Register),
 *                   darunter kompakte Doku "API nutzen".
 *    /usr/...       Nutzerbereich: Registrierung, Login, TOTP-MFA, Recovery-
 *                   Codes, Telefon/SMS-OTP (Odoo), Sitzungen, API-Anbindungen.
 *    /api/          Menschenlesbare API-Dokumentation.
 *    /api/v1/...    Maschinen-API (HMAC-signierte Requests, SSO-Assertions).
 *    /totp/         Datenverzeichnis. Wird beim ersten Start angelegt und per
 *                   .htaccess komplett gesperrt; alle Datensätze liegen
 *                   zusätzlich AEAD-verschlüsselt auf der Platte.
 *
 *  Sicherheits-Grundlagen (Details siehe SECURITY-Abschnitt im Code):
 *    - Argon2id + serverseitiger Pepper (HMAC vor dem Hashen)
 *    - TOTP nach RFC 6238, Replay-Schutz über monoton steigenden Counter
 *    - Alle Geheimnisse at-rest mit XChaCha20-Poly1305 (AAD = Ablageort)
 *    - Ed25519-signierte SSO-Assertions, JWKS-Endpunkt, Key-Rotation möglich
 *    - Authenticated Key Exchange (X25519 + HKDF + Key-Confirmation) für das
 *      automatische Pairing mit dem externen Server ("Initialisieren")
 *    - HMAC-SHA256 Request-Signatur mit Nonce-Cache + Zeitfenster (Replay)
 *    - SSRF-Schutz inkl. DNS-Rebinding-Pinning für alle ausgehenden Requests
 *    - Rate-Limits + exponentielles Account-Lockout, enumerationsresistent
 *    - Hash-verkettetes Audit-Log (manipulationssicher prüfbar)
 *    - Strikte CSP mit Nonces, __Host-Cookies, CSRF-Token, SameSite=Strict
 *
 *  Anforderungen: PHP >= 8.1 mit ext-sodium, ext-json, ext-hash.
 *                 Optional: ext-curl (empfohlen für ausgehende Requests).
 *
 *  Installation: Datei in das Webroot legen, einmal aufrufen. Alles Weitere
 *  (Datenverzeichnis, .htaccess, Schlüssel) wird automatisch erzeugt.
 *
 *  Der erste registrierte Account wird automatisch Administrator.
 * ============================================================================
 */

// ---------------------------------------------------------------------------
// 0. KONFIGURATION
// ---------------------------------------------------------------------------
// Alle Werte lassen sich per Umgebungsvariable überschreiben (SetEnv in der
// .htaccess, fastcgi_param, docker -e ...). Beispiel: SSO_MASTER_KEY.

/** Liest eine Konfiguration aus der Umgebung. */
function cfg(string $key, string|int|bool|null $default = null): string|int|bool|null
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $raw = getenv($key);
    if ($raw === false && isset($_SERVER[$key])) {
        $raw = (string) $_SERVER[$key];
    }
    if ($raw === false || $raw === '') {
        return $cache[$key] = $default;
    }
    if (is_bool($default)) {
        $val = in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    } elseif (is_int($default)) {
        $val = (int) $raw;
    } else {
        $val = $raw;
    }
    return $cache[$key] = $val;
}

const APP_NAME      = 'DIERCK ID';
const APP_VERSION   = '1.0.0';
const API_VERSION   = 'v1';

// -- Verzeichnisse ----------------------------------------------------------
define('DATA_DIR', (string) cfg('SSO_DATA_DIR', __DIR__ . '/totp'));

// -- Sicherheitsparameter ---------------------------------------------------
const PW_MIN_LENGTH        = 12;      // Mindestlänge Passwort
const PW_MAX_LENGTH        = 4096;    // DoS-Schutz beim Hashen
const TOTP_DIGITS          = 6;
const TOTP_PERIOD          = 30;      // Sekunden
const TOTP_ALGO            = 'sha1';  // Kompatibilität mit allen Authenticator-Apps
const TOTP_WINDOW          = 1;       // +/- 1 Zeitschritt Drift
const TOTP_SECRET_BYTES    = 20;      // 160 Bit (RFC 4226 Empfehlung)
const RECOVERY_CODE_COUNT  = 10;
const SESSION_IDLE_TTL     = 1800;    // 30 Minuten Inaktivität
const SESSION_ABS_TTL      = 43200;   // 12 Stunden absolut
const MFA_CHALLENGE_TTL    = 300;     // 5 Minuten für den zweiten Faktor
const SSO_CODE_TTL         = 90;      // Authorization-Code Lebensdauer
const ASSERTION_TTL        = 300;     // SSO-Assertion Lebensdauer
const API_CLOCK_SKEW       = 60;      // erlaubte Zeitabweichung API-Signatur
const PAIR_TOKEN_TTL       = 900;     // Bootstrap-Secret gültig (15 Min)
const SMS_OTP_TTL          = 300;
const SMS_OTP_DIGITS       = 6;
const LOCK_BASE_SECONDS    = 5;       // exponentielles Lockout: 5,10,20,40...
const LOCK_MAX_SECONDS     = 3600;
const LOGIN_FAIL_THRESHOLD = 3;       // ab hier greift das Lockout
const HTTP_TIMEOUT         = 8;
const HTTP_MAX_BYTES       = 262144;

// ---------------------------------------------------------------------------
// 1. BOOTSTRAP
// ---------------------------------------------------------------------------

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('PHP 8.1 oder neuer wird benötigt.');
}
foreach (['sodium', 'json', 'hash'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        exit('Fehlende PHP-Erweiterung: ' . $ext);
    }
}

mb_internal_encoding('UTF-8');
ini_set('display_errors', cfg('SSO_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');
error_reporting(E_ALL);
date_default_timezone_set((string) cfg('SSO_TIMEZONE', 'UTC'));
// Eigene Sessionverwaltung - PHPs Standardsession wird bewusst nicht benutzt.
ini_set('session.use_cookies', '0');

set_exception_handler(static function (Throwable $e): void {
    error_log('[' . APP_NAME . '] ' . $e::class . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (Router::isApiRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'internal_error'], JSON_UNESCAPED_SLASHES);
    } else {
        echo '<!doctype html><meta charset="utf-8"><title>Fehler</title>'
            . '<p style="font:16px system-ui;padding:2rem">Interner Fehler. '
            . 'Details stehen im Server-Log.</p>';
    }
    exit;
});

// ---------------------------------------------------------------------------
// 2. UTILITIES
// ---------------------------------------------------------------------------

final class Util
{
    /** Kryptografisch sichere Zufallsbytes als hex. */
    public static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** URL-sicheres Base64 ohne Padding. */
    public static function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $in): string
    {
        $in = strtr($in, '-_', '+/');
        $pad = strlen($in) % 4;
        if ($pad) {
            $in .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($in, true);
        return $out === false ? '' : $out;
    }

    /** Zeitkonstanter Vergleich, auch bei unterschiedlicher Länge sicher. */
    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /** RFC 4648 Base32 (Alphabet A-Z2-7), ohne Padding. */
    public static function base32Encode(string $raw): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
            $buffer = ($buffer << 8) | ord($raw[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= $alphabet[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $out .= $alphabet[($buffer << (5 - $bits)) & 31];
        }
        return $out;
    }

    public static function base32Decode(string $in): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $in = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $in) ?? '');
        $out = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0, $n = strlen($in); $i < $n; $i++) {
            $pos = strpos($alphabet, $in[$i]);
            if ($pos === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $out;
    }

    /** JSON mit strikten Flags; wirft bei Fehlern. */
    public static function jsonEncode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    public static function jsonDecode(string $json): mixed
    {
        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }

    /** HTML-Escaping für Textknoten und Attribute. */
    public static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Nutzername normalisieren (Vergleich immer klein geschrieben). */
    public static function normalizeUsername(string $u): string
    {
        return mb_strtolower(trim($u));
    }

    public static function isValidUsername(string $u): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9._-]{1,62}[a-z0-9])$/', $u);
    }

    public static function isValidEmail(string $e): bool
    {
        return $e !== '' && strlen($e) <= 254 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** E.164 Telefonnummer, wie sie Odoo erwartet. */
    public static function normalizePhone(string $p): ?string
    {
        $p = preg_replace('/[^\d+]/', '', $p) ?? '';
        if (str_starts_with($p, '00')) {
            $p = '+' . substr($p, 2);
        }
        if (!preg_match('/^\+[1-9]\d{6,14}$/', $p)) {
            return null;
        }
        return $p;
    }

    /** Client-IP; Proxy-Header nur wenn ausdrücklich vertraut. */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (string) cfg('SSO_TRUSTED_PROXIES', '');
        if ($trusted !== '' && self::ipInList($remote, array_map('trim', explode(',', $trusted)))) {
            $fwd = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($fwd !== '') {
                $parts = array_map('trim', explode(',', $fwd));
                $candidate = $parts[0];
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }
        return $remote;
    }

    private static function ipInList(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if (Net::ipInCidr($ip, $entry)) {
                    return true;
                }
            } elseif ($entry === $ip) {
                return true;
            }
        }
        return false;
    }

    /** Läuft die Anfrage über TLS? */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $trusted = (string) cfg('SSO_TRUSTED_PROXIES', '');
        if ($trusted !== ''
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return false;
    }

    /** Öffentlich erreichbare Basis-URL dieser Installation. */
    public static function baseUrl(): string
    {
        $configured = (string) cfg('SSO_BASE_URL', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = self::isHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        // Host-Header niemals ungeprüft übernehmen.
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
            $host = 'localhost';
        }
        return $scheme . '://' . $host . Router::basePath();
    }

    /** Menschenlesbare Zeitangabe. */
    public static function ts(?int $t): string
    {
        return $t ? date('d.m.Y H:i:s', $t) : '-';
    }

    /** Wandelt einen 64-Bit-Integer in 8 Bytes Big-Endian. */
    public static function packCounter(int $counter): string
    {
        return pack('J', $counter);
    }

    /** Gruppiert einen String in Blöcke (Anzeige von Secrets/Codes). */
    public static function group(string $s, int $size = 4, string $sep = ' '): string
    {
        return trim(chunk_split($s, $size, $sep), $sep);
    }
}

// ---------------------------------------------------------------------------
// 3. NETZWERK - ausgehende Requests mit SSRF-Schutz
// ---------------------------------------------------------------------------
//
// SECURITY: Der Nutzer gibt die Domain seines externen Servers selbst an.
// Ohne Schutz wäre das eine klassische SSRF-Lücke (Cloud-Metadaten,
// interne Admin-Panels, localhost). Deshalb:
//   1. Nur https (http nur mit SSO_ALLOW_INSECURE_PAIRING=1 für Tests).
//   2. Hostname wird aufgelöst, JEDE Adresse muss öffentlich routbar sein.
//   3. Die geprüften IPs werden per CURLOPT_RESOLVE gepinnt -> kein
//      DNS-Rebinding zwischen Prüfung und Verbindung (TOCTOU).
//   4. Keine Redirects, harte Timeouts, begrenzte Antwortgröße.

final class Net
{
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $maskLen] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }
        $bits = $maskLen === null ? strlen($ipBin) * 8 : (int) $maskLen;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        if ($bytes > 0 && strncmp($ipBin, $subBin, $bytes) !== 0) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        return (($ipBin[$bytes] & $mask) === ($subBin[$bytes] & $mask));
    }

    /** Ist die Adresse öffentlich routbar? */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // IPv4-mapped IPv6 auf IPv4 zurückführen.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }
        $blocked = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
            '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
            '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
            '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            '255.255.255.255/32',
            '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8',
            '2001:db8::/32', '64:ff9b::/96',
        ];
        foreach ($blocked as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }

    /** Löst einen Hostnamen auf und liefert nur öffentliche Adressen. */
    public static function resolvePublic(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? [$host] : [];
        }
        $ips = [];
        $a = @dns_get_record($host, DNS_A);
        foreach ($a ?: [] as $rec) {
            if (!empty($rec['ip'])) {
                $ips[] = $rec['ip'];
            }
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach ($aaaa ?: [] as $rec) {
            if (!empty($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
        if (!$ips) {
            $single = @gethostbyname($host);
            if ($single !== $host && filter_var($single, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $single;
            }
        }
        if (!$ips) {
            return [];
        }
        // Eine einzige nicht-öffentliche Adresse macht das Ziel unbrauchbar.
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return [];
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * POST mit JSON-Body an eine geprüften URL.
     *
     * @return array{ok:bool,status:int,body:string,error:string}
     */
    public static function postJson(
        string $url,
        array $payload,
        array $headers = [],
        bool $allowPrivate = false
    ): array {
        $allowInsecure = (bool) cfg('SSO_ALLOW_INSECURE_PAIRING', false);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return self::fail('ungueltige_url');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $allowInsecure)) {
            return self::fail('nur_https_erlaubt');
        }
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        // allow_private nur für vom Administrator hinterlegte Ziele (z. B. eine
        // selbst gehostete Odoo-Instanz im internen Netz) - niemals für
        // Adressen, die ein beliebiger Nutzer eintragen kann.
        $ips = $allowPrivate ? [] : self::resolvePublic($host);
        if (!$ips && !$allowPrivate && !$allowInsecure) {
            return self::fail('ziel_nicht_oeffentlich_erreichbar');
        }

        $body = Util::jsonEncode($payload);
        $hdr = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . APP_NAME . '/' . APP_VERSION,
            'Content-Length: ' . strlen($body),
        ], $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $hdr,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS      => 0,
                CURLOPT_CONNECTTIMEOUT => HTTP_TIMEOUT,
                CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
                CURLOPT_PROTOCOLS_STR  => $allowInsecure ? 'http,https' : 'https',
            ];
            if ($scheme === 'https') {
                $opts[CURLOPT_SSL_VERIFYPEER] = true;
                $opts[CURLOPT_SSL_VERIFYHOST] = 2;
            }
            if ($ips) {
                // DNS-Rebinding-Schutz: nur die geprüften IPs verwenden.
                $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . implode(',', $ips)];
            }
            curl_setopt_array($ch, $opts);
            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                return self::fail($err !== '' ? $err : 'verbindung_fehlgeschlagen');
            }
            return [
                'ok'     => $status >= 200 && $status < 300,
                'status' => $status,
                'body'   => substr((string) $resp, 0, HTTP_MAX_BYTES),
                'error'  => '',
            ];
        }

        // Fallback ohne cURL (kein IP-Pinning möglich -> nur mit TLS-Verify).
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $hdr),
                'content'       => $body,
                'timeout'       => HTTP_TIMEOUT,
                'ignore_errors' => true,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx, 0, HTTP_MAX_BYTES);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($resp === false) {
            return self::fail('verbindung_fehlgeschlagen');
        }
        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => (string) $resp,
            'error'  => '',
        ];
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $error];
    }
}

// ---------------------------------------------------------------------------
// 4. VAULT - Schlüsselverwaltung und Verschlüsselung at rest
// ---------------------------------------------------------------------------
//
// Ein einziger Masterkey (32 Byte) ist die Wurzel. Daraus werden per HKDF
// getrennte Teilschlüssel abgeleitet - ein kompromittierter Teilschlüssel
// gefährdet die anderen Zwecke nicht.
//
// Der Masterkey kommt bevorzugt aus der Umgebung (SSO_MASTER_KEY, base64).
// Ist er nicht gesetzt, wird er in /totp/keys/master.key mit 0600 angelegt.
// Für den Produktivbetrieb: Key per Umgebungsvariable setzen, dann liegt er
// nicht im Dateisystem neben den Daten.
//
// ACHTUNG: Die Entscheidung fällt VOR dem ersten Start. Wird SSO_MASTER_KEY
// nachträglich gesetzt (oder entfernt), sind alle bereits gespeicherten
// Datensätze nicht mehr entschlüsselbar - Nutzer, Schlüssel und Anbindungen
// wären verloren. Zum Wechseln: Datenverzeichnis leeren und neu aufsetzen.

final class Vault
{
    private static ?string $master = null;

    public static function master(): string
    {
        if (self::$master !== null) {
            return self::$master;
        }
        $env = (string) cfg('SSO_MASTER_KEY', '');
        if ($env !== '') {
            $raw = base64_decode($env, true);
            if ($raw === false || strlen($raw) < 32) {
                throw new RuntimeException('SSO_MASTER_KEY muss >= 32 Byte base64 sein.');
            }
            return self::$master = substr(hash('sha256', $raw, true), 0, 32);
        }
        $path = DATA_DIR . '/keys/master.key';
        if (is_file($path)) {
            $raw = (string) file_get_contents($path);
            if (strlen($raw) !== 32) {
                throw new RuntimeException('Masterkey beschädigt: ' . $path);
            }
            return self::$master = $raw;
        }
        $raw = random_bytes(32);
        Storage::atomicWrite($path, $raw, 0600);
        return self::$master = $raw;
    }

    /** Zweckgebundener Teilschlüssel (HKDF-SHA256). */
    public static function subkey(string $purpose, int $len = 32): string
    {
        return hash_hkdf('sha256', self::master(), $len, 'sso:' . $purpose, '');
    }

    /**
     * AEAD-Verschlüsselung. Die AAD bindet den Ciphertext an seinen Ablageort,
     * damit ein Angreifer mit Schreibrechten keine Datensätze vertauschen kann.
     */
    public static function encrypt(string $plain, string $aad = ''): string
    {
        $key = self::subkey('storage');
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $aad, $nonce, $key);
        sodium_memzero($key);
        return "SSO1" . $nonce . $ct;
    }

    public static function decrypt(string $blob, string $aad = ''): ?string
    {
        if (strlen($blob) < 4 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || substr($blob, 0, 4) !== 'SSO1') {
            return null;
        }
        $nonce = substr($blob, 4, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ct = substr($blob, 4 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = self::subkey('storage');
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, $aad, $nonce, $key);
        sodium_memzero($key);
        return $plain === false ? null : $plain;
    }

    /** Passwort-Pepper: Passwörter werden vor Argon2id geHMACt. */
    public static function pepper(string $password): string
    {
        return base64_encode(hash_hmac('sha256', $password, self::subkey('pepper'), true));
    }

    /** Deterministischer, nicht umkehrbarer Bezeichner (z. B. Dateinamen). */
    public static function tag(string $purpose, string $value, int $chars = 40): string
    {
        return substr(hash_hmac('sha256', $value, self::subkey('tag:' . $purpose)), 0, $chars);
    }

    /** Ed25519-Schlüsselpaar zum Signieren von SSO-Assertions. */
    public static function signingKeypair(): array
    {
        $rec = Storage::get('keys', 'assertion-signing');
        if ($rec !== null && isset($rec['sk'], $rec['pk'])) {
            return [
                'sk'  => Util::b64uDecode($rec['sk']),
                'pk'  => Util::b64uDecode($rec['pk']),
                'kid' => (string) $rec['kid'],
            ];
        }
        $pair = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($pair);
        $pk = sodium_crypto_sign_publickey($pair);
        $kid = substr(Util::b64u(hash('sha256', $pk, true)), 0, 16);
        Storage::put('keys', 'assertion-signing', [
            'sk'      => Util::b64u($sk),
            'pk'      => Util::b64u($pk),
            'kid'     => $kid,
            'created' => time(),
        ]);
        return ['sk' => $sk, 'pk' => $pk, 'kid' => $kid];
    }
}

// ---------------------------------------------------------------------------
// 5. STORAGE - verschlüsselte Dateiablage unter /totp/
// ---------------------------------------------------------------------------
//
// Bewusst dateibasiert: keine DB-Abhängigkeit, alles bleibt "single file".
// Schreibvorgänge sind atomar (tmp + rename), Lese-Ändere-Schreib-Zyklen
// laufen unter einer exklusiven Lockdatei.

final class Storage
{
    private const COLLECTIONS = [
        'users', 'sessions', 'clients', 'challenges', 'codes',
        'nonces', 'rate', 'keys', 'meta', 'locks',
    ];

    private static bool $ready = false;

    /** Legt Verzeichnisstruktur und Schutzdateien an (idempotent). */
    public static function bootstrap(): void
    {
        if (self::$ready) {
            return;
        }
        if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0700, true) && !is_dir(DATA_DIR)) {
            throw new RuntimeException('Datenverzeichnis nicht anlegbar: ' . DATA_DIR);
        }
        @chmod(DATA_DIR, 0700);
        foreach (self::COLLECTIONS as $c) {
            $dir = DATA_DIR . '/' . $c;
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        }
        self::writeIfMissing(DATA_DIR . '/.htaccess', self::dataHtaccess());
        self::writeIfMissing(DATA_DIR . '/index.html', '');
        self::writeIfMissing(DATA_DIR . '/web.config', self::dataWebConfig());
        self::writeIfMissing(DATA_DIR . '/README.txt',
            "Dieses Verzeichnis enthält Nutzer- und Schlüsseldaten.\n"
            . "Es darf NIEMALS über das Web erreichbar sein.\n"
            . "Schutz: .htaccess (Apache), web.config (IIS), Dateirechte 0700,\n"
            . "zusätzlich sind alle Datensätze XChaCha20-Poly1305-verschlüsselt.\n");
        self::writeIfMissing(dirname(DATA_DIR) . '/.htaccess', self::rootHtaccess());
        self::$ready = true;
    }

    /** .htaccess für /totp/ - Zugriff komplett verbieten. */
    public static function dataHtaccess(): string
    {
        return <<<'HTACCESS'
# Datenverzeichnis - kein Webzugriff, keine Ausführung.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
    Satisfy All
</IfModule>

# Falls die Zugriffsregel durch eine übergeordnete Konfiguration ausgehebelt
# wird: Skripte hier dürfen unter keinen Umständen ausgeführt werden.
Options -Indexes -ExecCGI -FollowSymLinks
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py
RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^ - [F,L]
</IfModule>
HTACCESS;
    }

    /** IIS-Äquivalent. */
    public static function dataWebConfig(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <hiddenSegments>
          <add segment="." />
        </hiddenSegments>
      </requestFiltering>
    </security>
    <handlers accessPolicy="None" />
  </system.webServer>
</configuration>
XML;
    }

    /** .htaccess für das Webroot - Routing + Basissicherheit. */
    public static function rootHtaccess(): string
    {
        $dir = basename(DATA_DIR);
        return <<<HTACCESS
# {$dir} niemals ausliefern (doppelter Boden zur .htaccess im Verzeichnis).
RedirectMatch 404 ^/(?:.*/)?{$dir}(?:/|\$)

Options -Indexes -MultiViews
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^{$dir}(/|\$) - [F,L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>

<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "no-referrer"
</IfModule>

# Versteckte Dateien und Backups nicht ausliefern.
<FilesMatch "(^\.|~\$|\.(bak|old|orig|save|swp|dist|log|key|dat)\$)">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
HTACCESS;
    }

    private static function writeIfMissing(string $path, string $content): void
    {
        if (!is_file($path)) {
            self::atomicWrite($path, $content, 0600);
        }
    }

    /** Atomares Schreiben: erst in eine Temp-Datei, dann umbenennen. */
    public static function atomicWrite(string $path, string $data, int $mode = 0600): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Verzeichnis nicht anlegbar: ' . $dir);
        }
        $tmp = $dir . '/.tmp.' . bin2hex(random_bytes(8));
        if (@file_put_contents($tmp, $data, LOCK_EX) === false) {
            throw new RuntimeException('Schreiben fehlgeschlagen: ' . $path);
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Umbenennen fehlgeschlagen: ' . $path);
        }
    }

    private static function path(string $collection, string $id): string
    {
        if (!in_array($collection, self::COLLECTIONS, true)) {
            throw new InvalidArgumentException('Unbekannte Collection: ' . $collection);
        }
        // Pfadtraversal ausgeschlossen: nicht triviale IDs werden gehasht.
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            $id = 'h_' . hash('sha256', $id);
        }
        return DATA_DIR . '/' . $collection . '/' . $id . '.rec';
    }

    public static function get(string $collection, string $id): ?array
    {
        self::bootstrap();
        $file = self::path($collection, $id);
        if (!is_file($file)) {
            return null;
        }
        $blob = @file_get_contents($file);
        if ($blob === false || $blob === '') {
            return null;
        }
        $plain = Vault::decrypt($blob, $collection . '/' . basename($file));
        if ($plain === null) {
            error_log('[' . APP_NAME . '] Datensatz nicht entschlüsselbar: ' . $file);
            return null;
        }
        try {
            $data = Util::jsonDecode($plain);
        } catch (JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    public static function put(string $collection, string $id, array $data): void
    {
        self::bootstrap();
        $file = self::path($collection, $id);
        $blob = Vault::encrypt(Util::jsonEncode($data), $collection . '/' . basename($file));
        self::atomicWrite($file, $blob, 0600);
    }

    public static function delete(string $collection, string $id): void
    {
        self::bootstrap();
        $file = self::path($collection, $id);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function exists(string $collection, string $id): bool
    {
        self::bootstrap();
        return is_file(self::path($collection, $id));
    }

    /** Alle Datensätze einer Collection (für Admin-Listen). */
    public static function all(string $collection): array
    {
        self::bootstrap();
        $out = [];
        foreach (glob(DATA_DIR . '/' . $collection . '/*.rec') ?: [] as $file) {
            $blob = @file_get_contents($file);
            if ($blob === false) {
                continue;
            }
            $plain = Vault::decrypt($blob, $collection . '/' . basename($file));
            if ($plain === null) {
                continue;
            }
            try {
                $data = Util::jsonDecode($plain);
            } catch (JsonException) {
                continue;
            }
            if (is_array($data)) {
                $out[basename($file, '.rec')] = $data;
            }
        }
        return $out;
    }

    /**
     * Read-Modify-Write unter exklusivem Lock. Der Callback bekommt den
     * aktuellen Datensatz und liefert den neuen zurück (null = löschen).
     */
    public static function mutate(string $collection, string $id, callable $fn): mixed
    {
        self::bootstrap();
        $lockFile = DATA_DIR . '/locks/' . hash('sha256', $collection . '|' . $id) . '.lock';
        $fh = @fopen($lockFile, 'c');
        if ($fh === false) {
            throw new RuntimeException('Lock nicht möglich: ' . $lockFile);
        }
        @chmod($lockFile, 0600);
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            throw new RuntimeException('Lock nicht erhalten.');
        }
        try {
            $current = self::get($collection, $id);
            $result = $fn($current);
            if (is_array($result)) {
                self::put($collection, $id, $result);
            } elseif ($result === false) {
                self::delete($collection, $id);
            }
            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Entfernt abgelaufene Datensätze (best effort, 2 % der Requests). */
    public static function gc(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        $now = time();
        $maxAge = [
            'sessions'   => SESSION_ABS_TTL + 3600,
            'challenges' => 3600,
            'nonces'     => 3600,
            'codes'      => 3600,
            'rate'       => 86400,
        ];
        foreach ($maxAge as $c => $age) {
            foreach (glob(DATA_DIR . '/' . $c . '/*.rec') ?: [] as $file) {
                $mtime = @filemtime($file);
                if ($mtime !== false && $now - $mtime > $age) {
                    @unlink($file);
                }
            }
        }
        foreach (glob(DATA_DIR . '/locks/*.lock') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $now - $mtime > 86400) {
                @unlink($file);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 6. AUDIT-LOG - hash-verkettet und damit manipulationssicher prüfbar
// ---------------------------------------------------------------------------
//
// Jede Zeile enthält den MAC der Vorgängerzeile. Wer nachträglich einen
// Eintrag ändert oder löscht, bricht die Kette - Audit::verify() findet das.

final class Audit
{
    public static function log(string $event, array $meta = [], ?string $actor = null): void
    {
        Storage::bootstrap();
        $dir = DATA_DIR . '/meta';
        $file = $dir . '/audit-' . date('Y-m') . '.log';
        $headFile = $dir . '/audit-head.dat';

        $fh = @fopen($file, 'a');
        if ($fh === false) {
            return;
        }
        @chmod($file, 0600);
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return;
        }
        try {
            $prev = is_file($headFile) ? trim((string) file_get_contents($headFile)) : str_repeat('0', 64);
            $entry = [
                'ts'    => time(),
                'ev'    => $event,
                'actor' => $actor,
                'ip'    => hash('sha256', Util::clientIp() . '|' . Vault::subkey('iphash')),
                'meta'  => $meta,
                'prev'  => $prev,
            ];
            $payload = Util::jsonEncode($entry);
            $mac = hash_hmac('sha256', $prev . '|' . $payload, Vault::subkey('audit'));
            $entry['mac'] = $mac;
            fwrite($fh, Util::jsonEncode($entry) . "\n");
            fflush($fh);
            @file_put_contents($headFile, $mac, LOCK_EX);
            @chmod($headFile, 0600);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Prüft die Kette einer Logdatei. */
    public static function verify(string $month): array
    {
        $file = DATA_DIR . '/meta/audit-' . $month . '.log';
        if (!is_file($file)) {
            return ['ok' => false, 'lines' => 0, 'error' => 'Datei nicht gefunden'];
        }
        $fh = fopen($file, 'r');
        if ($fh === false) {
            return ['ok' => false, 'lines' => 0, 'error' => 'nicht lesbar'];
        }
        $n = 0;
        $expectPrev = null;
        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $n++;
                try {
                    $entry = Util::jsonDecode($line);
                } catch (JsonException) {
                    return ['ok' => false, 'lines' => $n, 'error' => "Zeile {$n}: kein JSON"];
                }
                $mac = (string) ($entry['mac'] ?? '');
                unset($entry['mac']);
                $calc = hash_hmac('sha256', ((string) $entry['prev']) . '|' . Util::jsonEncode($entry),
                    Vault::subkey('audit'));
                if (!Util::equals($calc, $mac)) {
                    return ['ok' => false, 'lines' => $n, 'error' => "Zeile {$n}: MAC ungültig"];
                }
                if ($expectPrev !== null && $entry['prev'] !== $expectPrev) {
                    return ['ok' => false, 'lines' => $n, 'error' => "Zeile {$n}: Kette unterbrochen"];
                }
                $expectPrev = $mac;
            }
        } finally {
            fclose($fh);
        }
        return ['ok' => true, 'lines' => $n, 'error' => ''];
    }

    /** Letzte N Einträge (neueste zuerst). */
    public static function tail(int $limit = 100): array
    {
        $files = glob(DATA_DIR . '/meta/audit-*.log') ?: [];
        rsort($files);
        $out = [];
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
                try {
                    $out[] = Util::jsonDecode($lines[$i]);
                } catch (JsonException) {
                    // defekte Zeile überspringen
                }
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// 7. RATE-LIMIT und ACCOUNT-LOCKOUT
// ---------------------------------------------------------------------------

final class RateLimit
{
    /**
     * Sliding-Window-Zähler. Gibt false zurück, wenn das Limit erreicht ist.
     */
    public static function hit(string $key, int $limit, int $window): bool
    {
        $id = Vault::tag('rate', $key);
        $now = time();
        $allowed = true;
        Storage::mutate('rate', $id, static function (?array $rec) use ($now, $limit, $window, &$allowed): array {
            $rec ??= ['count' => 0, 'reset' => $now + $window];
            if ((int) $rec['reset'] <= $now) {
                $rec = ['count' => 0, 'reset' => $now + $window];
            }
            $rec['count'] = (int) $rec['count'] + 1;
            $allowed = $rec['count'] <= $limit;
            return $rec;
        });
        return $allowed;
    }

    /** Restliche Sperrzeit in Sekunden (0 = frei). */
    public static function retryAfter(string $key): int
    {
        $rec = Storage::get('rate', Vault::tag('rate', $key));
        if ($rec === null) {
            return 0;
        }
        return max(0, (int) $rec['reset'] - time());
    }

    public static function reset(string $key): void
    {
        Storage::delete('rate', Vault::tag('rate', $key));
    }

    /**
     * Exponentielles Lockout für Anmeldeversuche.
     * Liefert die verbleibende Sperrzeit in Sekunden.
     */
    public static function lockRemaining(string $subject): int
    {
        $rec = Storage::get('rate', Vault::tag('lock', $subject));
        if ($rec === null) {
            return 0;
        }
        return max(0, (int) ($rec['until'] ?? 0) - time());
    }

    public static function registerFailure(string $subject): int
    {
        $until = 0;
        Storage::mutate('rate', Vault::tag('lock', $subject), static function (?array $rec) use (&$until): array {
            $now = time();
            $rec ??= ['fails' => 0, 'until' => 0];
            // Zähler nach einer ruhigen Stunde zurücksetzen.
            if ($now - (int) ($rec['last'] ?? 0) > 3600) {
                $rec['fails'] = 0;
            }
            $rec['fails'] = (int) $rec['fails'] + 1;
            $rec['last'] = $now;
            if ($rec['fails'] >= LOGIN_FAIL_THRESHOLD) {
                $exp = min($rec['fails'] - LOGIN_FAIL_THRESHOLD, 12);
                $delay = min(LOCK_BASE_SECONDS * (2 ** $exp), LOCK_MAX_SECONDS);
                $rec['until'] = $now + $delay;
                $until = $delay;
            }
            return $rec;
        });
        return $until;
    }

    public static function clearFailures(string $subject): void
    {
        Storage::delete('rate', Vault::tag('lock', $subject));
    }
}

// ---------------------------------------------------------------------------
// 8. SESSIONS - eigene Implementierung, serverseitig unter /totp/sessions
// ---------------------------------------------------------------------------
//
// Warum nicht die PHP-Standardsession? Weil deren Dateien im systemweiten
// /tmp landen (auf Shared Hosting für andere Accounts lesbar), die IDs nicht
// an den Client gebunden sind und Cookie-Attribute je nach ini variieren.
// Hier: 32 Byte Zufalls-ID, serverseitig nur der Hash gespeichert, Bindung an
// User-Agent, Idle- und Absolut-Timeout, Rotation bei Rechteänderung.

final class Session
{
    private static ?array $data = null;
    private static ?string $sid = null;

    /**
     * Sitzungen entstehen erst, wenn wirklich etwas gespeichert wird.
     * Ein Aufruf der API oder eine 404-Seite legt damit keine Datei an -
     * sonst könnte man den Server mit anonymen Anfragen zumüllen.
     */
    private static bool $stored = false;

    public static function cookieName(): string
    {
        // __Host- erzwingt Secure + Path=/ + keine Domain: stärkste Variante.
        return (Util::isHttps() && Router::basePath() === '') ? '__Host-sso' : 'sso_session';
    }

    public static function start(): void
    {
        if (self::$data !== null) {
            return;
        }
        $cookie = (string) ($_COOKIE[self::cookieName()] ?? '');
        if ($cookie !== '' && preg_match('/^[A-Za-z0-9_-]{43}$/', $cookie)) {
            $rec = Storage::get('sessions', Vault::tag('session', $cookie));
            if ($rec !== null && self::isValid($rec)) {
                self::$sid = $cookie;
                self::$stored = true;
                $rec['last'] = time();
                self::$data = $rec;
                Storage::put('sessions', Vault::tag('session', $cookie), $rec);
                return;
            }
            if ($rec !== null) {
                Storage::delete('sessions', Vault::tag('session', $cookie));
            }
        }
        self::create();
    }

    private static function isValid(array $rec): bool
    {
        $now = time();
        if ($now - (int) ($rec['created'] ?? 0) > SESSION_ABS_TTL) {
            return false;
        }
        if ($now - (int) ($rec['last'] ?? 0) > SESSION_IDLE_TTL) {
            return false;
        }
        // Bindung an den User-Agent: gestohlene Cookies werden dadurch nicht
        // wertlos, aber deutlich unbequemer. Die IP wird bewusst NICHT
        // gebunden (Mobilfunk wechselt ständig die Adresse).
        $ua = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (isset($rec['ua']) && !Util::equals((string) $rec['ua'], $ua)) {
            return false;
        }
        return true;
    }

    /** Legt die Sitzung nur im Speicher an - geschrieben wird erst bei Bedarf. */
    private static function create(array $seed = []): void
    {
        $now = time();
        self::$data = array_merge([
            'created' => $now,
            'last'    => $now,
            'uid'     => null,
            'mfa'     => false,
            'ua'      => hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'ip'      => Util::clientIp(),
            'csrf'    => null,
            'flash'   => [],
        ], $seed);
        self::$sid = null;
        self::$stored = false;
    }

    private static function sendCookie(string $sid): void
    {
        if (headers_sent()) {
            return;
        }
        $name = self::cookieName();
        $path = $name === '__Host-sso' ? '/' : (Router::basePath() ?: '/');
        setcookie($name, $sid, [
            'expires'  => 0,
            'path'     => $path,
            'secure'   => Util::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Neue Session-ID bei Rechteänderung (Session-Fixation-Schutz). */
    public static function regenerate(): void
    {
        self::start();
        $old = self::$sid;
        $data = self::$data ?? [];
        $data['created'] = time();
        $data['last'] = time();
        // Auch das CSRF-Token wird erneuert - ein vor der Anmeldung
        // untergeschobenes Token gilt danach nicht mehr.
        $data['csrf'] = Util::b64u(random_bytes(32));
        if ($old !== null) {
            Storage::delete('sessions', Vault::tag('session', $old));
        }
        self::$data = null;
        self::create($data);
        self::persist();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return self::$data[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        self::$data[$key] = $value;
        self::persist();
    }

    public static function forget(string $key): void
    {
        self::start();
        unset(self::$data[$key]);
        self::persist();
    }

    /** Schreibt die Sitzung; beim ersten Mal entstehen ID und Cookie. */
    private static function persist(): void
    {
        if (self::$data === null) {
            return;
        }
        if (self::$sid === null) {
            self::$sid = Util::b64u(random_bytes(32));
            self::sendCookie(self::$sid);
        }
        self::$stored = true;
        Storage::put('sessions', Vault::tag('session', self::$sid), self::$data);
    }

    public static function destroy(): void
    {
        self::start();
        if (self::$sid !== null && self::$stored) {
            Storage::delete('sessions', Vault::tag('session', self::$sid));
        }
        if (!headers_sent()) {
            $name = self::cookieName();
            setcookie($name, '', [
                'expires'  => time() - 3600,
                'path'     => $name === '__Host-sso' ? '/' : (Router::basePath() ?: '/'),
                'secure'   => Util::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        self::$data = null;
        self::$sid = null;
    }

    public static function id(): ?string
    {
        self::start();
        return self::$sid;
    }

    /** Eingeloggter Nutzer mit bestandener MFA - sonst null. */
    public static function user(): ?array
    {
        self::start();
        $uid = self::get('uid');
        if (!is_string($uid) || $uid === '' || !self::get('mfa', false)) {
            return null;
        }
        return Users::byId($uid);
    }

    public static function login(array $user, bool $mfaDone): void
    {
        self::regenerate();
        self::$data['uid'] = $user['id'];
        self::$data['mfa'] = $mfaDone;
        self::$data['login_at'] = time();
        self::persist();
    }

    /** Einmalige Statusmeldung für die nächste Seite. */
    public static function flash(string $type, string $message): void
    {
        self::start();
        $flash = self::get('flash', []);
        $flash[] = ['type' => $type, 'msg' => $message];
        self::set('flash', array_slice($flash, -5));
    }

    public static function takeFlash(): array
    {
        self::start();
        $flash = self::get('flash', []);
        if ($flash) {
            self::set('flash', []);
        }
        return is_array($flash) ? $flash : [];
    }
}

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('csrf');
        if (!is_string($t) || $t === '') {
            $t = Util::b64u(random_bytes(32));
            Session::set('csrf', $t);
        }
        return $t;
    }

    /** Verstecktes Formularfeld. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . Util::h(self::token()) . '">';
    }

    public static function check(): bool
    {
        $sent = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return $sent !== '' && Util::equals(self::token(), $sent);
    }

    /** Bricht die Anfrage bei fehlendem/falschem Token ab. */
    public static function require(): void
    {
        if (!self::check()) {
            Audit::log('csrf.blocked', ['path' => Router::path()]);
            Response::error(403, 'CSRF-Token ungültig. Bitte Formular neu laden.');
        }
    }
}

// ---------------------------------------------------------------------------
// 9. TOTP nach RFC 6238 (auf Basis von HOTP, RFC 4226)
// ---------------------------------------------------------------------------

final class Totp
{
    /** Neues Geheimnis (Rohbytes). */
    public static function generateSecret(): string
    {
        return random_bytes(TOTP_SECRET_BYTES);
    }

    public static function counter(?int $time = null): int
    {
        return intdiv($time ?? time(), TOTP_PERIOD);
    }

    /** HOTP-Wert für einen Zählerstand. */
    public static function code(
        string $secret,
        int $counter,
        int $digits = TOTP_DIGITS,
        string $algo = TOTP_ALGO
    ): string {
        $hash = hash_hmac($algo, Util::packCounter($counter), $secret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Prüft eine Eingabe im Drift-Fenster.
     *
     * SECURITY: $minCounter setzt den Replay-Schutz um. Ein bereits benutzter
     * Code (und alle älteren) wird abgelehnt, auch wenn er zeitlich noch
     * gültig wäre - sonst könnte ein abgefangener Code erneut verwendet
     * werden, solange das 30-Sekunden-Fenster läuft.
     *
     * @return int|false Der akzeptierte Zählerstand oder false.
     */
    public static function verify(
        string $secret,
        string $input,
        int $minCounter = 0,
        int $window = TOTP_WINDOW,
        ?int $time = null
    ): int|false {
        $input = preg_replace('/\D/', '', $input) ?? '';
        if (strlen($input) !== TOTP_DIGITS) {
            return false;
        }
        $current = self::counter($time);
        $match = false;
        // Immer alle Kandidaten durchlaufen (kein Early-Exit) - das hält die
        // Laufzeit unabhängig davon, welcher Schritt getroffen hat.
        for ($i = -$window; $i <= $window; $i++) {
            $counter = $current + $i;
            if ($counter < 0 || $counter <= $minCounter) {
                continue;
            }
            if (Util::equals(self::code($secret, $counter), $input) && $match === false) {
                $match = $counter;
            }
        }
        return $match;
    }

    /** otpauth-URI für Authenticator-Apps. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $params = http_build_query([
            'secret'    => Util::base32Encode($secret),
            'issuer'    => $issuer,
            'algorithm' => strtoupper(TOTP_ALGO),
            'digits'    => TOTP_DIGITS,
            'period'    => TOTP_PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $label . '?' . $params;
    }
}

// ---------------------------------------------------------------------------
// 10. QR-CODE - vollständiger Encoder in reinem PHP (keine Abhängigkeiten)
// ---------------------------------------------------------------------------
//
// Byte-Modus, Fehlerkorrektur-Level M, Versionen 1-20. Ausgabe als Inline-SVG,
// damit die strikte CSP ohne externe Ressourcen und ohne data:-Skripte
// auskommt. Implementiert nach ISO/IEC 18004.

final class QrCode
{
    /** Fehlerkorrektur-Codewörter pro Block, Level M, Version 1..20. */
    private const ECC_PER_BLOCK = [
        10, 16, 26, 18, 24, 16, 18, 22, 22, 26,
        30, 22, 22, 24, 24, 28, 28, 26, 26, 26,
    ];

    /** Anzahl Fehlerkorrektur-Blöcke, Level M, Version 1..20. */
    private const NUM_BLOCKS = [
        1, 1, 1, 2, 2, 4, 4, 4, 5, 5,
        5, 8, 9, 9, 10, 10, 11, 13, 14, 16,
    ];

    private const ECC_FORMAT_BITS_M = 0; // L=1, M=0, Q=3, H=2

    /** Gesamtzahl der Datenmodule (ohne Funktionsmuster) einer Version. */
    private static function rawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($version >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function rawCodewords(int $version): int
    {
        return intdiv(self::rawDataModules($version), 8);
    }

    private static function dataCodewords(int $version): int
    {
        return self::rawCodewords($version)
            - self::ECC_PER_BLOCK[$version - 1] * self::NUM_BLOCKS[$version - 1];
    }

    private static function alignmentPositions(int $version): array
    {
        if ($version === 1) {
            return [];
        }
        $numAlign = intdiv($version, 7) + 2;
        $size = $version * 4 + 17;
        $step = intdiv($version * 4 + $numAlign * 2 + 1, $numAlign * 2 - 2) * 2;
        $result = [];
        for ($pos = $size - 7; count($result) < $numAlign - 1; $pos -= $step) {
            array_unshift($result, $pos);
        }
        array_unshift($result, 6);
        return $result;
    }

    /** Kleinste Version, in die $data passt. */
    private static function chooseVersion(int $length): int
    {
        for ($v = 1; $v <= 20; $v++) {
            $countBits = $v <= 9 ? 8 : 16;
            $capacity = intdiv(self::dataCodewords($v) * 8 - 4 - $countBits, 8);
            if ($length <= $capacity) {
                return $v;
            }
        }
        throw new RuntimeException('Daten zu lang für QR-Code (max. Version 20).');
    }

    // -- Galois-Feld GF(256) mit Primitivpolynom 0x11D -----------------------

    private static function gfMul(int $a, int $b): int
    {
        $result = 0;
        for ($i = 7; $i >= 0; $i--) {
            $result = (($result << 1) ^ ((($result >> 7) & 1) * 0x11D)) & 0xFF;
            $result ^= (($b >> $i) & 1) * $a;
            $result &= 0xFF;
        }
        return $result;
    }

    /** Generatorpolynom für $degree Fehlerkorrektur-Codewörter. */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $result;
    }

    /** Reed-Solomon-Rest für einen Datenblock. */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $degree = count($divisor);
        $result = array_fill(0, $degree, 0);
        foreach ($data as $b) {
            $factor = ($b ^ $result[0]) & 0xFF;
            array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $result[$i] ^= self::gfMul($divisor[$i], $factor);
            }
        }
        return $result;
    }

    /**
     * Erzeugt die Modulmatrix (Werte 0/1) für beliebige Nutzdaten.
     * $forceMask erzwingt ein bestimmtes Maskenmuster (nur für Tests).
     */
    public static function matrix(string $data, ?int $forceMask = null): array
    {
        $len = strlen($data);
        $version = self::chooseVersion($len);
        $size = $version * 4 + 17;
        $countBits = $version <= 9 ? 8 : 16;
        $dataCw = self::dataCodewords($version);

        // --- 1. Bitstrom aufbauen (Byte-Modus) ---
        $bits = '0100' . str_pad(decbin($len), $countBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $capacityBits = $dataCw * 8;
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $padBytes = ['11101100', '00010001'];
        for ($i = 0; strlen($bits) < $capacityBits; $i++) {
            $bits .= $padBytes[$i % 2];
        }
        $codewords = [];
        for ($i = 0; $i < $capacityBits; $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }

        // --- 2. Blöcke bilden, Fehlerkorrektur berechnen, verschachteln ---
        $numBlocks = self::NUM_BLOCKS[$version - 1];
        $eccLen = self::ECC_PER_BLOCK[$version - 1];
        $rawCw = self::rawCodewords($version);
        $numShort = $numBlocks - $rawCw % $numBlocks;
        $shortLen = intdiv($rawCw, $numBlocks);
        $divisor = self::rsDivisor($eccLen);

        $blocks = [];
        $offset = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $dataLen = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $block = array_slice($codewords, $offset, $dataLen);
            $offset += $dataLen;
            $blocks[] = ['data' => $block, 'ecc' => self::rsRemainder($block, $divisor)];
        }
        $final = [];
        $maxData = $shortLen - $eccLen + 1;
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $b) {
                if ($i < count($b['data'])) {
                    $final[] = $b['data'][$i];
                }
            }
        }
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($blocks as $b) {
                $final[] = $b['ecc'][$i];
            }
        }

        // --- 3. Funktionsmuster setzen ---
        $m = array_fill(0, $size, array_fill(0, $size, 0));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $setFn = static function (int $r, int $c, int $v) use (&$m, &$reserved, $size): void {
            if ($r < 0 || $c < 0 || $r >= $size || $c >= $size) {
                return;
            }
            $m[$r][$c] = $v;
            $reserved[$r][$c] = true;
        };

        // Suchmuster inkl. Trennlinien
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$fr, $fc]) {
            for ($dr = -1; $dr <= 7; $dr++) {
                for ($dc = -1; $dc <= 7; $dc++) {
                    $r = $fr + $dr;
                    $c = $fc + $dc;
                    if ($r < 0 || $c < 0 || $r >= $size || $c >= $size) {
                        continue;
                    }
                    $inner = max(abs($dr - 3), abs($dc - 3));
                    $setFn($r, $c, ($inner === 2 || $inner > 3) ? 0 : 1);
                }
            }
        }
        // Taktmuster
        for ($i = 8; $i < $size - 8; $i++) {
            $setFn(6, $i, $i % 2 === 0 ? 1 : 0);
            $setFn($i, 6, $i % 2 === 0 ? 1 : 0);
        }
        // Ausrichtungsmuster
        $align = self::alignmentPositions($version);
        $n = count($align);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                // Ecken sind von den Suchmustern belegt.
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1)
                    || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $setFn($align[$i] + $dr, $align[$j] + $dc,
                            max(abs($dr), abs($dc)) === 1 ? 0 : 1);
                    }
                }
            }
        }
        // Formatbereiche reservieren. Index 6 bleibt ausgespart - dort liegen
        // die Taktmodule (6,8) und (8,6), die nicht überschrieben werden dürfen.
        for ($i = 0; $i <= 8; $i++) {
            if ($i === 6) {
                continue;
            }
            $setFn($i, 8, 0);
            $setFn(8, $i, 0);
        }
        // Zweite Kopie: acht Module in Zeile 8 rechts, acht in Spalte 8 unten
        // (davon eines das immer dunkle Modul).
        for ($i = 0; $i < 8; $i++) {
            $setFn(8, $size - 1 - $i, 0);
            $setFn($size - 1 - $i, 8, 0);
        }
        $setFn($size - 8, 8, 1); // immer dunkel
        // Versionsbereiche (ab Version 7)
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
            }
            $vbits = ($version << 12) | ($rem & 0xFFF);
            for ($i = 0; $i < 18; $i++) {
                $bit = ($vbits >> $i) & 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $setFn($b, $a, $bit);
                $setFn($a, $b, $bit);
            }
        }

        // --- 4. Daten im Zickzack einfügen ---
        $bitIndex = 0;
        $totalBits = count($final) * 8;
        $upward = true;
        for ($col = $size - 1; $col >= 1; $col -= 2) {
            if ($col === 6) {
                $col = 5; // Taktspalte überspringen
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? $size - 1 - $i : $i;
                for ($k = 0; $k < 2; $k++) {
                    $c = $col - $k;
                    if ($reserved[$row][$c]) {
                        continue;
                    }
                    $bit = 0;
                    if ($bitIndex < $totalBits) {
                        $bit = ($final[$bitIndex >> 3] >> (7 - ($bitIndex & 7))) & 1;
                        $bitIndex++;
                    }
                    $m[$row][$c] = $bit;
                }
            }
            $upward = !$upward;
        }

        // --- 5. Beste Maske wählen ---
        $best = null;
        $bestPenalty = PHP_INT_MAX;
        if ($forceMask !== null) {
            $cand = self::applyMask($m, $reserved, $forceMask, $size);
            self::drawFormat($cand, $forceMask, $size);
            return $cand;
        }
        for ($mask = 0; $mask < 8; $mask++) {
            $cand = self::applyMask($m, $reserved, $mask, $size);
            self::drawFormat($cand, $mask, $size);
            $penalty = self::penalty($cand, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $cand;
            }
        }
        return $best ?? $m;
    }

    private static function applyMask(array $m, array $reserved, int $mask, int $size): array
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($r + $c) % 2 === 0,
                    1 => $r % 2 === 0,
                    2 => $c % 3 === 0,
                    3 => ($r + $c) % 3 === 0,
                    4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                    5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
                    6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
                    default => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
                };
                if ($invert) {
                    $m[$r][$c] ^= 1;
                }
            }
        }
        return $m;
    }

    private static function drawFormat(array &$m, int $mask, int $size): void
    {
        $data = (self::ECC_FORMAT_BITS_M << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $m[$i][8] = ($bits >> $i) & 1;
        }
        $m[7][8] = ($bits >> 6) & 1;
        $m[8][8] = ($bits >> 7) & 1;
        $m[8][7] = ($bits >> 8) & 1;
        for ($i = 9; $i < 15; $i++) {
            $m[8][14 - $i] = ($bits >> $i) & 1;
        }
        for ($i = 0; $i < 8; $i++) {
            $m[8][$size - 1 - $i] = ($bits >> $i) & 1;
        }
        for ($i = 8; $i < 15; $i++) {
            $m[$size - 15 + $i][8] = ($bits >> $i) & 1;
        }
        $m[$size - 8][8] = 1;
    }

    /** Bewertung nach ISO/IEC 18004, Abschnitt 8.8.2. */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;
        // Regel 1: Laufende Folgen gleicher Farbe
        for ($r = 0; $r < $size; $r++) {
            $runColor = -1;
            $runLen = 0;
            for ($c = 0; $c < $size; $c++) {
                if ($m[$r][$c] === $runColor) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) {
                        $score += 3 + ($runLen - 5);
                    }
                    $runColor = $m[$r][$c];
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) {
                $score += 3 + ($runLen - 5);
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $runColor = -1;
            $runLen = 0;
            for ($r = 0; $r < $size; $r++) {
                if ($m[$r][$c] === $runColor) {
                    $runLen++;
                } else {
                    if ($runLen >= 5) {
                        $score += 3 + ($runLen - 5);
                    }
                    $runColor = $m[$r][$c];
                    $runLen = 1;
                }
            }
            if ($runLen >= 5) {
                $score += 3 + ($runLen - 5);
            }
        }
        // Regel 2: gleichfarbige 2x2-Blöcke
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }
        // Regel 3: suchmusterähnliche Sequenzen
        $p1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $p2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $hit1 = true;
                $hit2 = true;
                for ($k = 0; $k < 11; $k++) {
                    $hit1 = $hit1 && $m[$r][$c + $k] === $p1[$k];
                    $hit2 = $hit2 && $m[$r][$c + $k] === $p2[$k];
                }
                $score += ($hit1 ? 40 : 0) + ($hit2 ? 40 : 0);
            }
        }
        for ($c = 0; $c < $size; $c++) {
            for ($r = 0; $r <= $size - 11; $r++) {
                $hit1 = true;
                $hit2 = true;
                for ($k = 0; $k < 11; $k++) {
                    $hit1 = $hit1 && $m[$r + $k][$c] === $p1[$k];
                    $hit2 = $hit2 && $m[$r + $k][$c] === $p2[$k];
                }
                $score += ($hit1 ? 40 : 0) + ($hit2 ? 40 : 0);
            }
        }
        // Regel 4: Abweichung vom 50-Prozent-Schwarzanteil
        $dark = 0;
        for ($r = 0; $r < $size; $r++) {
            $dark += array_sum($m[$r]);
        }
        $total = $size * $size;
        // floor(|Dunkelanteil in Prozent - 50| / 5) * 10, ganzzahlig gerechnet.
        $score += intdiv(abs($dark * 100 - 50 * $total), 5 * $total) * 10;
        return $score;
    }

    /** Inline-SVG (CSP-freundlich, skaliert verlustfrei). */
    public static function svg(string $data, int $scale = 6, int $quiet = 4, string $label = ''): string
    {
        $m = self::matrix($data);
        $size = count($m);
        $dim = ($size + 2 * $quiet) * $scale;
        $path = '';
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($m[$r][$c] === 1) {
                    $x = ($c + $quiet) * $scale;
                    $y = ($r + $quiet) * $scale;
                    $path .= "M{$x} {$y}h{$scale}v{$scale}h-{$scale}z";
                }
            }
        }
        $aria = $label !== '' ? ' role="img" aria-label="' . Util::h($label) . '"' : ' aria-hidden="true"';
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim
            . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges"' . $aria . '>'
            . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
            . '<path d="' . $path . '" fill="#000000"/></svg>';
    }
}

// ---------------------------------------------------------------------------
// 11. NUTZER
// ---------------------------------------------------------------------------

final class Users
{
    /** Argon2id-Parameter; bewusst höher als der PHP-Standard. */
    private static function hashOptions(): array
    {
        return [
            'memory_cost' => (int) cfg('SSO_ARGON_MEMORY', 131072), // 128 MiB
            'time_cost'   => (int) cfg('SSO_ARGON_TIME', 4),
            'threads'     => (int) cfg('SSO_ARGON_THREADS', 2),
        ];
    }

    private static function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** Deterministische, nicht ratbare Nutzer-ID aus dem Nutzernamen. */
    public static function idFor(string $username): string
    {
        return Vault::tag('uid', Util::normalizeUsername($username), 32);
    }

    public static function byId(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }
        return Storage::get('users', $id);
    }

    public static function byUsername(string $username): ?array
    {
        $username = Util::normalizeUsername($username);
        if ($username === '') {
            return null;
        }
        return Storage::get('users', self::idFor($username));
    }

    public static function save(array $user): void
    {
        Storage::put('users', (string) $user['id'], $user);
    }

    public static function count(): int
    {
        return count(glob(DATA_DIR . '/users/*.rec') ?: []);
    }

    /**
     * Passwortrichtlinie. Liefert eine Liste von Fehlern (leer = ok).
     */
    public static function passwordProblems(string $pw, string $username = '', string $email = ''): array
    {
        $problems = [];
        $len = mb_strlen($pw);
        if ($len < PW_MIN_LENGTH) {
            $problems[] = 'Mindestens ' . PW_MIN_LENGTH . ' Zeichen.';
        }
        if (strlen($pw) > PW_MAX_LENGTH) {
            $problems[] = 'Höchstens ' . PW_MAX_LENGTH . ' Bytes.';
        }
        $classes = 0;
        foreach (['/[a-z]/u', '/[A-Z]/u', '/\d/', '/[^\p{L}\p{N}]/u'] as $re) {
            $classes += preg_match($re, $pw) ? 1 : 0;
        }
        // Kurze Passwörter brauchen Zeichenvielfalt, lange Passphrasen nicht.
        if ($len < 16 && $classes < 3) {
            $problems[] = 'Unter 16 Zeichen sind mindestens drei Zeichenarten nötig '
                . '(Klein-, Großbuchstaben, Ziffern, Sonderzeichen).';
        }
        $lower = mb_strtolower($pw);
        if ($username !== '' && str_contains($lower, mb_strtolower($username))) {
            $problems[] = 'Das Passwort darf den Nutzernamen nicht enthalten.';
        }
        if ($email !== '') {
            $local = strtok(mb_strtolower($email), '@');
            if (is_string($local) && strlen($local) >= 4 && str_contains($lower, $local)) {
                $problems[] = 'Das Passwort darf die E-Mail-Adresse nicht enthalten.';
            }
        }
        if (preg_match('/^(.)\1+$/u', $pw)) {
            $problems[] = 'Nur ein wiederholtes Zeichen ist kein Passwort.';
        }
        $common = [
            'passwort', 'password', 'qwertz', 'qwerty', 'asdfgh', '123456',
            'letmein', 'welcome', 'admin', 'iloveyou', 'dragon', 'monkey',
            'sunshine', 'princess', 'football', 'baseball', 'trustno1',
            'defcon', 'hacktheplanet', 'changeme', 'geheim',
        ];
        foreach ($common as $needle) {
            if (str_contains($lower, $needle)) {
                $problems[] = 'Enthält ein sehr häufiges Muster ("' . $needle . '").';
                break;
            }
        }
        return $problems;
    }

    /**
     * Legt einen Nutzer an.
     *
     * @return array{ok:bool,user:?array,errors:array}
     */
    public static function create(string $username, string $email, string $password, string $display = ''): array
    {
        $username = Util::normalizeUsername($username);
        $errors = [];
        if (!Util::isValidUsername($username)) {
            $errors[] = 'Nutzername: 3-64 Zeichen, a-z 0-9 . _ -, Anfang und Ende alphanumerisch.';
        }
        if (!Util::isValidEmail($email)) {
            $errors[] = 'E-Mail-Adresse ist ungültig.';
        }
        $errors = array_merge($errors, self::passwordProblems($password, $username, $email));
        if ($errors) {
            return ['ok' => false, 'user' => null, 'errors' => $errors];
        }
        $id = self::idFor($username);
        $now = time();
        $user = [
            'id'            => $id,
            'username'      => $username,
            'display'       => $display !== '' ? mb_substr($display, 0, 64) : $username,
            'email'         => $email,
            'pw'            => password_hash(Vault::pepper($password), self::algo(), self::hashOptions()),
            'pw_changed'    => $now,
            'created'       => $now,
            'admin'         => self::count() === 0, // erster Account wird Admin
            'disabled'      => false,
            'totp_secret'   => null,
            'totp_enabled'  => false,
            'totp_counter'  => 0,
            'recovery'      => [],
            'phone'         => null,
            'phone_ok'      => false,
            'sms_enabled'   => false,
            'last_login'    => null,
        ];
        // Unter Lock anlegen: zwei gleichzeitige Registrierungen desselben
        // Namens dürfen sich nicht gegenseitig überschreiben.
        $taken = false;
        Storage::mutate('users', $id, static function (?array $rec) use ($user, &$taken): array {
            if ($rec !== null) {
                $taken = true;
                return $rec;
            }
            return $user;
        });
        if ($taken) {
            return ['ok' => false, 'user' => null, 'errors' => ['Nutzername ist bereits vergeben.']];
        }
        Audit::log('user.created', ['username' => $username, 'admin' => $user['admin']], $id);
        return ['ok' => true, 'user' => $user, 'errors' => []];
    }

    /** Passwortprüfung inkl. transparentem Rehash bei neuen Parametern. */
    public static function verifyPassword(array $user, string $password): bool
    {
        if (strlen($password) > PW_MAX_LENGTH) {
            return false;
        }
        $peppered = Vault::pepper($password);
        if (!password_verify($peppered, (string) $user['pw'])) {
            return false;
        }
        if (password_needs_rehash((string) $user['pw'], self::algo(), self::hashOptions())) {
            $user['pw'] = password_hash($peppered, self::algo(), self::hashOptions());
            self::save($user);
        }
        return true;
    }

    /**
     * Dummy-Verifikation für nicht existierende Nutzer. Ohne das wäre die
     * Antwortzeit ein zuverlässiger Indikator dafür, ob ein Konto existiert.
     */
    public static function dummyVerify(string $password): void
    {
        static $dummy = null;
        $dummy ??= password_hash(Vault::pepper('nicht-vorhanden'), self::algo(), self::hashOptions());
        password_verify(Vault::pepper($password), $dummy);
    }

    public static function setPassword(array $user, string $password): array
    {
        $problems = self::passwordProblems($password, (string) $user['username'], (string) $user['email']);
        if ($problems) {
            return $problems;
        }
        $user['pw'] = password_hash(Vault::pepper($password), self::algo(), self::hashOptions());
        $user['pw_changed'] = time();
        self::save($user);
        Audit::log('user.password_changed', [], (string) $user['id']);
        return [];
    }

    /**
     * TOTP-Prüfung mit Replay-Schutz.
     * Der akzeptierte Zählerstand wird gespeichert; ältere oder gleiche
     * Zähler werden danach abgelehnt.
     */
    public static function verifyTotp(array $user, string $code): bool
    {
        if (empty($user['totp_enabled']) || empty($user['totp_secret'])) {
            return false;
        }
        $secret = Util::b64uDecode((string) $user['totp_secret']);
        $counter = Totp::verify($secret, $code, (int) ($user['totp_counter'] ?? 0));
        if ($counter === false) {
            return false;
        }
        // Zählerstand atomar fortschreiben (parallele Requests können den
        // gleichen Code sonst zweimal einlösen).
        $accepted = false;
        Storage::mutate('users', (string) $user['id'],
            static function (?array $rec) use ($counter, &$accepted): ?array {
                if ($rec === null) {
                    return null;
                }
                if ((int) ($rec['totp_counter'] ?? 0) >= $counter) {
                    return $rec; // schon verwendet
                }
                $rec['totp_counter'] = $counter;
                $accepted = true;
                return $rec;
            });
        return $accepted;
    }

    /** Erzeugt neue Wiederherstellungscodes und liefert sie im Klartext. */
    public static function newRecoveryCodes(array $user): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne I,O,0,1
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < RECOVERY_CODE_COUNT; $i++) {
            $code = '';
            for ($j = 0; $j < 12; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plain[] = implode('-', str_split($code, 4));
            $hashes[] = self::recoveryHash($code);
        }
        $user['recovery'] = $hashes;
        self::save($user);
        Audit::log('user.recovery_codes_created', ['count' => RECOVERY_CODE_COUNT], (string) $user['id']);
        return $plain;
    }

    private static function recoveryHash(string $code): string
    {
        // Codes haben ~60 Bit Entropie - HMAC genügt, Argon2 wäre hier nur
        // langsam (es müssten zehn Hashes geprüft werden).
        return hash_hmac('sha256', strtoupper($code), Vault::subkey('recovery'));
    }

    /** Löst einen Wiederherstellungscode ein (Einmalverwendung). */
    public static function useRecoveryCode(array $user, string $input): bool
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
        if (strlen($clean) !== 12) {
            return false;
        }
        $target = self::recoveryHash($clean);
        $used = false;
        Storage::mutate('users', (string) $user['id'],
            static function (?array $rec) use ($target, &$used): ?array {
                if ($rec === null) {
                    return null;
                }
                $remaining = [];
                foreach ((array) ($rec['recovery'] ?? []) as $h) {
                    if (!$used && Util::equals((string) $h, $target)) {
                        $used = true;
                        continue; // verbrauchen
                    }
                    $remaining[] = $h;
                }
                $rec['recovery'] = $remaining;
                return $rec;
            });
        if ($used) {
            Audit::log('user.recovery_code_used', [], (string) $user['id']);
        }
        return $used;
    }
}

// ---------------------------------------------------------------------------
// 12. ODOO-SMS - zweiter Faktor per SMS über die Odoo-Instanz
// ---------------------------------------------------------------------------
//
// ZUR FRAGE "SMS-Marketing-App nutzen":
// Odoos External API (JSON-RPC) erreicht jedes Modell, also auch die der
// SMS-Marketing-App. Für Einmalpasswörter ist der transaktionale Weg über
// das Modell sms.sms aber der richtige: Mailings sind für Kampagnen gebaut,
// laufen über Warteschlangen/Cron, respektieren Blacklist- und Opt-out-Logik
// und liefern dadurch unvorhersehbar spät - für einen 5-Minuten-Code
// unbrauchbar. Beide Wege sind hier implementiert; Standard ist sms.sms.
// Voraussetzung in beiden Fällen: das Modul "sms" ist installiert und die
// Instanz hat IAP-Guthaben (oder einen eigenen SMS-Gateway-Connector).

final class OdooSms
{
    public static function config(): array
    {
        $rec = Storage::get('meta', 'odoo') ?? [];
        return array_merge([
            'enabled'       => false,
            'url'           => '',       // https://meinefirma.odoo.com
            'db'            => '',
            'user'          => '',       // Login (E-Mail)
            'api_key'       => '',       // Odoo API-Key statt Passwort
            'mode'          => 'sms',    // 'sms' = sms.sms, 'mailing' = SMS-Marketing
            'mailing_list'  => 'SSO Einmalcodes',
            'sender_note'   => '',
            'allow_private' => false,    // selbst gehostete Instanz im LAN
        ], $rec);
    }

    public static function saveConfig(array $cfg): void
    {
        $current = self::config();
        $merged = array_merge($current, $cfg);
        Storage::put('meta', 'odoo', $merged);
        Audit::log('odoo.config_saved', ['url' => $merged['url'], 'mode' => $merged['mode']]);
    }

    public static function isReady(): bool
    {
        $c = self::config();
        return $c['enabled'] && $c['url'] !== '' && $c['db'] !== ''
            && $c['user'] !== '' && $c['api_key'] !== '';
    }

    /** Roher JSON-RPC-Aufruf gegen /jsonrpc. */
    private static function rpc(string $service, string $method, array $args): mixed
    {
        $c = self::config();
        $url = rtrim((string) $c['url'], '/') . '/jsonrpc';
        $resp = Net::postJson($url, [
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => ['service' => $service, 'method' => $method, 'args' => $args],
            'id'      => random_int(1, PHP_INT_MAX),
        ], [], (bool) $c['allow_private']);
        if (!$resp['ok']) {
            throw new RuntimeException('Odoo nicht erreichbar: '
                . ($resp['error'] !== '' ? $resp['error'] : 'HTTP ' . $resp['status']));
        }
        try {
            $data = Util::jsonDecode($resp['body']);
        } catch (JsonException) {
            throw new RuntimeException('Odoo lieferte kein JSON zurück.');
        }
        if (isset($data['error'])) {
            $msg = $data['error']['data']['message'] ?? $data['error']['message'] ?? 'unbekannt';
            throw new RuntimeException('Odoo-Fehler: ' . (string) $msg);
        }
        return $data['result'] ?? null;
    }

    /** Anmeldung; liefert die Odoo-Nutzer-ID. */
    public static function authenticate(): int
    {
        $c = self::config();
        $uid = self::rpc('common', 'authenticate', [
            $c['db'], $c['user'], $c['api_key'], new stdClass(),
        ]);
        if (!is_int($uid) || $uid <= 0) {
            throw new RuntimeException('Odoo-Anmeldung fehlgeschlagen (DB, Login oder API-Key falsch).');
        }
        return $uid;
    }

    /** execute_kw-Aufruf auf einem Modell. */
    private static function call(int $uid, string $model, string $method, array $args, array $kwargs = []): mixed
    {
        $c = self::config();
        return self::rpc('object', 'execute_kw', [
            $c['db'], $uid, $c['api_key'], $model, $method, $args,
            $kwargs === [] ? new stdClass() : $kwargs,
        ]);
    }

    /**
     * Verschickt eine SMS.
     *
     * @return array{ok:bool,error:string,ref:string}
     */
    public static function send(string $number, string $body): array
    {
        if (!self::isReady()) {
            return ['ok' => false, 'error' => 'Odoo-SMS ist nicht konfiguriert.', 'ref' => ''];
        }
        $number = Util::normalizePhone($number) ?? '';
        if ($number === '') {
            return ['ok' => false, 'error' => 'Ungültige Rufnummer.', 'ref' => ''];
        }
        try {
            $uid = self::authenticate();
            $c = self::config();
            if (($c['mode'] ?? 'sms') === 'mailing') {
                $ref = self::sendViaMailing($uid, $number, $body);
            } else {
                $ref = self::sendTransactional($uid, $number, $body);
            }
            Audit::log('sms.sent', ['mode' => $c['mode'], 'ref' => $ref]);
            return ['ok' => true, 'error' => '', 'ref' => $ref];
        } catch (Throwable $e) {
            Audit::log('sms.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage(), 'ref' => ''];
        }
    }

    /** Empfohlener Weg: Datensatz in sms.sms anlegen und sofort senden. */
    private static function sendTransactional(int $uid, string $number, string $body): string
    {
        $id = self::call($uid, 'sms.sms', 'create', [[
            'number' => $number,
            'body'   => $body,
        ]]);
        if (!is_int($id) && !is_array($id)) {
            throw new RuntimeException('sms.sms konnte nicht angelegt werden.');
        }
        $ids = is_array($id) ? $id : [$id];
        self::call($uid, 'sms.sms', 'send', [$ids]);
        return 'sms.sms#' . implode(',', array_map('strval', $ids));
    }

    /**
     * Weg über die SMS-Marketing-App (mass_mailing_sms).
     * Funktioniert, ist aber für Einmalcodes nicht zu empfehlen - siehe
     * Kommentar oben. Nur aktiv, wenn mode = 'mailing' gesetzt ist.
     */
    private static function sendViaMailing(int $uid, string $number, string $body): string
    {
        $c = self::config();
        $listName = (string) ($c['mailing_list'] ?: 'SSO Einmalcodes');

        $listIds = self::call($uid, 'mailing.list', 'search', [[['name', '=', $listName]]], ['limit' => 1]);
        $listId = is_array($listIds) && $listIds ? (int) $listIds[0]
            : (int) self::call($uid, 'mailing.list', 'create', [['name' => $listName]]);

        $contactIds = self::call($uid, 'mailing.contact', 'search',
            [[['mobile', '=', $number]]], ['limit' => 1]);
        if (is_array($contactIds) && $contactIds) {
            $contactId = (int) $contactIds[0];
            self::call($uid, 'mailing.contact', 'write',
                [[$contactId], ['list_ids' => [[4, $listId]]]]);
        } else {
            $contactId = (int) self::call($uid, 'mailing.contact', 'create', [[
                'name'     => 'OTP ' . substr($number, -4),
                'mobile'   => $number,
                'list_ids' => [[4, $listId]],
            ]]);
        }

        $modelIds = self::call($uid, 'ir.model', 'search',
            [[['model', '=', 'mailing.contact']]], ['limit' => 1]);
        if (!is_array($modelIds) || !$modelIds) {
            throw new RuntimeException('Modell mailing.contact nicht gefunden.');
        }

        $mailingId = (int) self::call($uid, 'mailing.mailing', 'create', [[
            'subject'          => 'Einmalcode',
            'mailing_type'     => 'sms',
            'body_plaintext'   => $body,
            'mailing_model_id' => (int) $modelIds[0],
            'contact_list_ids' => [[6, 0, [$listId]]],
            'mailing_domain'   => "[('id', '=', {$contactId})]",
        ]]);
        self::call($uid, 'mailing.mailing', 'action_send_mail', [[$mailingId]]);
        return 'mailing.mailing#' . $mailingId;
    }

    /** Verbindungstest für die Admin-Oberfläche. */
    public static function test(): array
    {
        try {
            $uid = self::authenticate();
            $version = self::rpc('common', 'version', []);
            $hasSms = self::call($uid, 'ir.model', 'search_count', [[['model', '=', 'sms.sms']]]);
            return [
                'ok'      => true,
                'message' => 'Verbunden als UID ' . $uid
                    . ' (Odoo ' . (string) ($version['server_serie'] ?? '?') . '). '
                    . ((int) $hasSms > 0 ? 'Modul "sms" ist vorhanden.' : 'ACHTUNG: Modell sms.sms fehlt.'),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

/** Einmalcodes per SMS (zweiter Faktor bzw. Rufnummernbestätigung). */
final class SmsOtp
{
    private static function key(string $uid, string $purpose): string
    {
        return Vault::tag('smsotp', $uid . '|' . $purpose);
    }

    /** @return array{ok:bool,error:string} */
    public static function issue(array $user, string $purpose, ?string $numberOverride = null): array
    {
        $number = $numberOverride ?? (string) ($user['phone'] ?? '');
        if ($number === '') {
            return ['ok' => false, 'error' => 'Keine Rufnummer hinterlegt.'];
        }
        if (!RateLimit::hit('sms:' . $user['id'], 5, 900)) {
            return ['ok' => false, 'error' => 'Zu viele SMS-Anforderungen. Bitte später erneut versuchen.'];
        }
        $code = str_pad((string) random_int(0, 10 ** SMS_OTP_DIGITS - 1), SMS_OTP_DIGITS, '0', STR_PAD_LEFT);
        Storage::put('codes', self::key((string) $user['id'], $purpose), [
            'hash'  => hash_hmac('sha256', $code, Vault::subkey('smsotp')),
            'exp'   => time() + SMS_OTP_TTL,
            'tries' => 0,
        ]);
        $text = APP_NAME . ': Ihr Einmalcode lautet ' . $code . '. Gültig '
            . intdiv(SMS_OTP_TTL, 60) . ' Minuten. Niemals weitergeben.';
        $res = OdooSms::send($number, $text);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error']];
        }
        return ['ok' => true, 'error' => ''];
    }

    public static function verify(array $user, string $purpose, string $code): bool
    {
        $id = self::key((string) $user['id'], $purpose);
        $ok = false;
        Storage::mutate('codes', $id, static function (?array $rec) use ($code, &$ok): array|false {
            if ($rec === null || (int) $rec['exp'] < time()) {
                return false;
            }
            if ((int) $rec['tries'] >= 5) {
                return false; // verbrannt
            }
            $rec['tries'] = (int) $rec['tries'] + 1;
            $clean = preg_replace('/\D/', '', $code) ?? '';
            if (Util::equals((string) $rec['hash'], hash_hmac('sha256', $clean, Vault::subkey('smsotp')))) {
                $ok = true;
                return false; // Einmalverwendung: Datensatz löschen
            }
            return $rec;
        });
        return $ok;
    }
}

// ---------------------------------------------------------------------------
// 13. API-CLIENTS (Anbindungen externer Server)
// ---------------------------------------------------------------------------
//
// Zwei Betriebsarten:
//
//  A) "paired"  - Der Nutzer lädt eine personalisierte api.php herunter, legt
//     sie auf seinen Server, trägt hier die Domain ein und drückt
//     "Initialisieren". Danach führen beide Skripte einen authentifizierten
//     Diffie-Hellman durch (X25519 + HKDF + Key-Confirmation). Es wird nie ein
//     Langzeitschlüssel übertragen, das Bootstrap-Geheimnis wird nach dem
//     Pairing verbrannt (Forward Secrecy für den Schlüsselaustausch).
//
//  B) "manual"  - Der Nutzer kopiert Client-ID und Secret und implementiert die
//     Signatur selbst. Gleiche Endpunkte, gleiches Signaturverfahren.

final class Clients
{
    public static function get(string $clientId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $clientId)) {
            return null;
        }
        return Storage::get('clients', $clientId);
    }

    public static function save(array $client): void
    {
        Storage::put('clients', (string) $client['id'], $client);
    }

    public static function delete(string $clientId): void
    {
        Storage::delete('clients', $clientId);
    }

    /** Alle Anbindungen eines Nutzers. */
    public static function forOwner(string $uid): array
    {
        $out = [];
        foreach (Storage::all('clients') as $c) {
            if (($c['owner'] ?? '') === $uid) {
                $out[(string) $c['id']] = $c;
            }
        }
        uasort($out, static fn(array $a, array $b): int => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
        return $out;
    }

    public static function create(array $owner, string $name, string $mode, string $connectorUrl = ''): array
    {
        $id = Util::randomHex(16);
        $now = time();
        $client = [
            'id'            => $id,
            'owner'         => (string) $owner['id'],
            'name'          => mb_substr(trim($name), 0, 64) ?: 'Anbindung',
            'mode'          => $mode === 'manual' ? 'manual' : 'paired',
            'status'        => $mode === 'manual' ? 'active' : 'pending',
            'connector_url' => $connectorUrl,
            'redirect_uris' => [],
            'created'       => $now,
            'paired_at'     => null,
            'last_used'     => null,
            'k_c2s'         => null,
            'k_s2c'         => null,
            'secret'        => null,
            'bootstrap'     => null,
            'allow_password_api' => false,
            'ed25519_peer'  => null,
        ];
        if ($client['mode'] === 'manual') {
            // Ein Secret, aus dem beide Richtungsschlüssel abgeleitet werden.
            $secret = random_bytes(32);
            $client['secret'] = Util::b64u($secret);
            $client['k_c2s'] = Util::b64u(hash_hkdf('sha256', $secret, 32, 'sso-manual|c2s|' . $id, ''));
            $client['k_s2c'] = Util::b64u(hash_hkdf('sha256', $secret, 32, 'sso-manual|s2c|' . $id, ''));
            $client['paired_at'] = $now;
        } else {
            $client['bootstrap'] = [
                'secret' => Util::b64u(random_bytes(32)),
                'exp'    => $now + PAIR_TOKEN_TTL,
            ];
        }
        self::save($client);
        Audit::log('client.created', ['client' => $id, 'mode' => $client['mode']], (string) $owner['id']);
        return $client;
    }

    /** Erneuert das Bootstrap-Geheimnis (neue api.php nötig). */
    public static function refreshBootstrap(array $client): array
    {
        $client['bootstrap'] = [
            'secret' => Util::b64u(random_bytes(32)),
            'exp'    => time() + PAIR_TOKEN_TTL,
        ];
        $client['status'] = 'pending';
        $client['k_c2s'] = null;
        $client['k_s2c'] = null;
        self::save($client);
        return $client;
    }

    /** Schlüssel für eingehende Signaturen (Connector -> hier). */
    public static function inboundKey(array $client): ?string
    {
        return isset($client['k_c2s']) ? Util::b64uDecode((string) $client['k_c2s']) : null;
    }

    /** Schlüssel für ausgehende Signaturen (hier -> Connector). */
    public static function outboundKey(array $client): ?string
    {
        return isset($client['k_s2c']) ? Util::b64uDecode((string) $client['k_s2c']) : null;
    }

    public static function touch(array $client): void
    {
        $client['last_used'] = time();
        self::save($client);
    }
}

// ---------------------------------------------------------------------------
// 14. SIGNATUREN
// ---------------------------------------------------------------------------

final class Sig
{
    /** Deterministische Serialisierung: rekursiv sortierte Schlüssel. */
    public static function canonical(array $payload): string
    {
        unset($payload['mac'], $payload['sig']);
        $sort = static function (array $a) use (&$sort): array {
            ksort($a, SORT_STRING);
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $a[$k] = $sort($v);
                }
            }
            return $a;
        };
        return Util::jsonEncode($sort($payload));
    }

    public static function mac(string $key, array $payload): string
    {
        return Util::b64u(hash_hmac('sha256', self::canonical($payload), $key, true));
    }

    public static function checkMac(string $key, array $payload, string $given): bool
    {
        return Util::equals(self::mac($key, $payload), $given);
    }

    /**
     * Kanonische Darstellung eines HTTP-Requests für die API-Signatur.
     * Methode, Pfad, Zeitstempel, Nonce und Body-Hash sind gebunden - der
     * Body kann also nicht ausgetauscht und der Request nicht auf einen
     * anderen Endpunkt umgebogen werden.
     */
    public static function requestString(string $method, string $path, int $ts, string $nonce, string $body): string
    {
        return implode("\n", [
            'SSO-HMAC-SHA256',
            strtoupper($method),
            $path,
            (string) $ts,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    public static function signRequest(string $key, string $method, string $path, int $ts, string $nonce, string $body): string
    {
        return Util::b64u(hash_hmac('sha256', self::requestString($method, $path, $ts, $nonce, $body), $key, true));
    }

    /** Merkt eine Nonce vor; false, wenn sie schon benutzt wurde. */
    public static function consumeNonce(string $clientId, string $nonce): bool
    {
        $fresh = false;
        Storage::mutate('nonces', Vault::tag('nonce', $clientId . '|' . $nonce),
            static function (?array $rec) use (&$fresh): array {
                if ($rec !== null && (int) ($rec['exp'] ?? 0) > time()) {
                    return $rec; // bereits vergeben
                }
                $fresh = true;
                return ['exp' => time() + API_CLOCK_SKEW * 4];
            });
        return $fresh;
    }
}

// ---------------------------------------------------------------------------
// 15. PAIRING - authentifizierter Schlüsselaustausch mit dem externen Server
// ---------------------------------------------------------------------------
//
// Ablauf (beide Nachrichten gehen von hier zum Connector):
//
//   1. pair.hello   ->  Server-X25519-Public, Nonce, Zeitstempel
//                       signiert mit Ed25519 (Connector hat den Public-Key
//                       fest eingebaut) UND mit dem Bootstrap-Geheimnis geMACt
//   2. Antwort      <-  Client-X25519-Public + Key-Confirmation
//   3. pair.finish  ->  Key-Confirmation der Gegenrichtung
//
// Erst wenn beide Bestätigungen stimmen, gilt die Anbindung als aktiv.

final class Pairing
{
    private const PROTO = 'sso-pair-v1';

    /** @return array{ok:bool,error:string} */
    public static function run(array $client): array
    {
        if (($client['mode'] ?? '') !== 'paired') {
            return ['ok' => false, 'error' => 'Diese Anbindung nutzt manuelle Schlüssel.'];
        }
        $url = (string) ($client['connector_url'] ?? '');
        if ($url === '') {
            return ['ok' => false, 'error' => 'Keine Connector-URL hinterlegt.'];
        }
        $bootstrap = $client['bootstrap'] ?? null;
        if (!is_array($bootstrap) || (int) $bootstrap['exp'] < time()) {
            return ['ok' => false, 'error' => 'Bootstrap-Geheimnis abgelaufen. Bitte api.php neu erzeugen.'];
        }
        $bsKey = Util::b64uDecode((string) $bootstrap['secret']);

        // Ephemeres X25519-Schlüsselpaar (nur für dieses Pairing).
        $kp = sodium_crypto_box_keypair();
        $sk = sodium_crypto_box_secretkey($kp);
        $pk = sodium_crypto_box_publickey($kp);
        $signing = Vault::signingKeypair();

        $nonce = Util::randomHex(16);
        $hello = [
            'v'          => 1,
            'op'         => 'pair.hello',
            'proto'      => self::PROTO,
            'client_id'  => (string) $client['id'],
            'issuer'     => Util::baseUrl(),
            'ts'         => time(),
            'nonce'      => $nonce,
            'server_pub' => Util::b64u($pk),
            'sign_pub'   => Util::b64u($signing['pk']),
            'kid'        => $signing['kid'],
        ];
        $hello['sig'] = Util::b64u(
            sodium_crypto_sign_detached(Sig::canonical($hello), $signing['sk'])
        );
        $hello['mac'] = Sig::mac($bsKey, $hello);

        $resp = Net::postJson($url, $hello);
        if (!$resp['ok']) {
            return ['ok' => false, 'error' => 'Connector nicht erreichbar: '
                . ($resp['error'] !== '' ? $resp['error'] : 'HTTP ' . $resp['status'])];
        }
        try {
            $answer = Util::jsonDecode($resp['body']);
        } catch (JsonException) {
            return ['ok' => false, 'error' => 'Connector lieferte kein JSON (falsche URL?).'];
        }
        if (!is_array($answer) || ($answer['op'] ?? '') !== 'pair.hello.ok') {
            return ['ok' => false, 'error' => 'Unerwartete Antwort: '
                . mb_substr((string) ($answer['error'] ?? $resp['body']), 0, 200)];
        }
        if (!Sig::checkMac($bsKey, $answer, (string) ($answer['mac'] ?? ''))) {
            return ['ok' => false, 'error' => 'Antwort-MAC falsch - Bootstrap-Geheimnis passt nicht.'];
        }
        if (!hash_equals($nonce, (string) ($answer['nonce'] ?? ''))
            || !hash_equals((string) $client['id'], (string) ($answer['client_id'] ?? ''))) {
            return ['ok' => false, 'error' => 'Antwort gehört nicht zu dieser Anfrage.'];
        }

        $clientPub = Util::b64uDecode((string) ($answer['client_pub'] ?? ''));
        if (strlen($clientPub) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return ['ok' => false, 'error' => 'Ungültiger Public-Key vom Connector.'];
        }

        $shared = sodium_crypto_scalarmult($sk, $clientPub);
        // Alles, was den Austausch identifiziert, geht in die Ableitung ein.
        $transcript = implode('|', [
            self::PROTO, (string) $client['id'], $nonce,
            Util::b64u($pk), Util::b64u($clientPub),
        ]);
        $kC2S = hash_hkdf('sha256', $shared, 32, $transcript . '|c2s', $nonce);
        $kS2C = hash_hkdf('sha256', $shared, 32, $transcript . '|s2c', $nonce);
        sodium_memzero($shared);
        sodium_memzero($sk);

        $expected = Util::b64u(hash_hmac('sha256', 'confirm-client|' . $transcript, $kC2S, true));
        if (!Util::equals($expected, (string) ($answer['confirm'] ?? ''))) {
            return ['ok' => false, 'error' => 'Key-Confirmation fehlgeschlagen.'];
        }

        $finish = [
            'v'         => 1,
            'op'        => 'pair.finish',
            'proto'     => self::PROTO,
            'client_id' => (string) $client['id'],
            'ts'        => time(),
            'nonce'     => $nonce,
            'confirm'   => Util::b64u(hash_hmac('sha256', 'confirm-server|' . $transcript, $kS2C, true)),
        ];
        $finish['mac'] = Sig::mac($bsKey, $finish);
        $resp2 = Net::postJson($url, $finish);
        if (!$resp2['ok']) {
            return ['ok' => false, 'error' => 'Abschluss fehlgeschlagen: HTTP ' . $resp2['status']];
        }
        try {
            $final = Util::jsonDecode($resp2['body']);
        } catch (JsonException) {
            return ['ok' => false, 'error' => 'Abschlussantwort unlesbar.'];
        }
        if (($final['op'] ?? '') !== 'pair.finish.ok'
            || !Sig::checkMac($bsKey, $final, (string) ($final['mac'] ?? ''))) {
            return ['ok' => false, 'error' => 'Connector hat den Abschluss nicht bestätigt.'];
        }

        $client['k_c2s'] = Util::b64u($kC2S);
        $client['k_s2c'] = Util::b64u($kS2C);
        $client['status'] = 'active';
        $client['paired_at'] = time();
        $client['bootstrap'] = null; // Einmalgeheimnis verbrannt
        Clients::save($client);
        Audit::log('client.paired', ['client' => $client['id'], 'url' => $url], (string) $client['owner']);
        return ['ok' => true, 'error' => ''];
    }
}

// ---------------------------------------------------------------------------
// 16. SSO-ASSERTIONS (Ed25519, kompaktes JWT mit alg=EdDSA)
// ---------------------------------------------------------------------------

final class Assertion
{
    public static function issue(array $user, array $client, array $extra = []): string
    {
        $keys = Vault::signingKeypair();
        $now = time();
        $header = ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $keys['kid']];
        $claims = array_merge([
            'iss'       => Util::baseUrl(),
            'sub'       => (string) $user['id'],
            'aud'       => (string) $client['id'],
            'iat'       => $now,
            'nbf'       => $now - 5,
            'exp'       => $now + ASSERTION_TTL,
            'jti'       => Util::randomHex(16),
            'username'  => (string) $user['username'],
            'name'      => (string) $user['display'],
            'email'     => (string) $user['email'],
            'mfa'       => true,
        ], $extra);
        $signingInput = Util::b64u(Util::jsonEncode($header)) . '.' . Util::b64u(Util::jsonEncode($claims));
        $sig = sodium_crypto_sign_detached($signingInput, $keys['sk']);
        return $signingInput . '.' . Util::b64u($sig);
    }

    /** @return array{ok:bool,claims:array,error:string} */
    public static function verify(string $token, ?string $audience = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return ['ok' => false, 'claims' => [], 'error' => 'Formatfehler'];
        }
        [$h, $p, $s] = $parts;
        try {
            $header = Util::jsonDecode(Util::b64uDecode($h));
            $claims = Util::jsonDecode(Util::b64uDecode($p));
        } catch (JsonException) {
            return ['ok' => false, 'claims' => [], 'error' => 'JSON fehlerhaft'];
        }
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'EdDSA') {
            return ['ok' => false, 'claims' => [], 'error' => 'Algorithmus nicht erlaubt'];
        }
        $keys = Vault::signingKeypair();
        if (($header['kid'] ?? '') !== $keys['kid']) {
            return ['ok' => false, 'claims' => [], 'error' => 'Unbekannte Schlüssel-ID'];
        }
        $sig = Util::b64uDecode($s);
        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($sig, $h . '.' . $p, $keys['pk'])) {
            return ['ok' => false, 'claims' => [], 'error' => 'Signatur ungültig'];
        }
        $now = time();
        if ((int) ($claims['exp'] ?? 0) < $now) {
            return ['ok' => false, 'claims' => [], 'error' => 'abgelaufen'];
        }
        if ((int) ($claims['nbf'] ?? 0) > $now + API_CLOCK_SKEW) {
            return ['ok' => false, 'claims' => [], 'error' => 'noch nicht gültig'];
        }
        if ($audience !== null && !hash_equals($audience, (string) ($claims['aud'] ?? ''))) {
            return ['ok' => false, 'claims' => [], 'error' => 'falsche Zielgruppe'];
        }
        return ['ok' => true, 'claims' => $claims, 'error' => ''];
    }

    /** Öffentlicher Schlüssel im JWKS-Format. */
    public static function jwks(): array
    {
        $keys = Vault::signingKeypair();
        return ['keys' => [[
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'use' => 'sig',
            'alg' => 'EdDSA',
            'kid' => $keys['kid'],
            'x'   => Util::b64u($keys['pk']),
        ]]];
    }
}

// ---------------------------------------------------------------------------
// 17. CHALLENGES (MFA-Zwischenschritt und SSO-Autorisierungscodes)
// ---------------------------------------------------------------------------

final class Challenges
{
    /** Legt eine Challenge an und liefert das Handle im Klartext. */
    public static function create(string $type, array $data, int $ttl): string
    {
        $handle = Util::b64u(random_bytes(32));
        Storage::put('challenges', Vault::tag('chal', $handle), array_merge($data, [
            'type' => $type,
            'exp'  => time() + $ttl,
        ]));
        return $handle;
    }

    public static function peek(string $handle, string $type): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $handle)) {
            return null;
        }
        $rec = Storage::get('challenges', Vault::tag('chal', $handle));
        if ($rec === null || ($rec['type'] ?? '') !== $type || (int) $rec['exp'] < time()) {
            return null;
        }
        return $rec;
    }

    public static function update(string $handle, array $data): void
    {
        $id = Vault::tag('chal', $handle);
        $rec = Storage::get('challenges', $id);
        if ($rec !== null) {
            Storage::put('challenges', $id, array_merge($rec, $data));
        }
    }

    /** Holt und löscht in einem Schritt (Einmalverwendung). */
    public static function consume(string $handle, string $type): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $handle)) {
            return null;
        }
        $id = Vault::tag('chal', $handle);
        $found = null;
        Storage::mutate('challenges', $id, static function (?array $rec) use ($type, &$found): array|false {
            if ($rec === null || ($rec['type'] ?? '') !== $type || (int) $rec['exp'] < time()) {
                return false;
            }
            $found = $rec;
            return false; // löschen
        });
        return $found;
    }

    public static function drop(string $handle): void
    {
        Storage::delete('challenges', Vault::tag('chal', $handle));
    }
}

// ---------------------------------------------------------------------------
// 18. HTTP-ANTWORTEN
// ---------------------------------------------------------------------------

final class Response
{
    private static ?string $nonce = null;

    /** Pro Request ein CSP-Nonce für erlaubte Inline-Blöcke. */
    public static function nonce(): string
    {
        return self::$nonce ??= Util::b64u(random_bytes(16));
    }

    public static function securityHeaders(bool $html = true): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        if (Util::isHttps()) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
        }
        if ($html) {
            $n = self::nonce();
            header(
                "Content-Security-Policy: default-src 'none'; "
                . "base-uri 'none'; "
                . "form-action 'self'; "
                . "frame-ancestors 'none'; "
                . "img-src 'self' data:; "
                . "style-src 'nonce-{$n}'; "
                . "script-src 'nonce-{$n}'; "
                . "connect-src 'self'; "
                . "object-src 'none'"
                // Nur unter TLS sinnvoll; auf http://localhost würde die
                // Direktive jede Anfrage auf https umbiegen.
                . (Util::isHttps() ? '; upgrade-insecure-requests' : '')
            );
        }
    }

    public static function json(array $data, int $status = 200, array $headers = []): never
    {
        self::securityHeaders(false);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $h) {
            header($h);
        }
        echo Util::jsonEncode($data);
        exit;
    }

    public static function apiError(int $status, string $code, string $message = ''): never
    {
        self::json(['error' => $code, 'message' => $message], $status);
    }

    public static function html(string $body, int $status = 200): never
    {
        self::securityHeaders(true);
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $body;
        exit;
    }

    public static function text(string $body, int $status = 200, array $headers = []): never
    {
        self::securityHeaders(false);
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        foreach ($headers as $h) {
            header($h);
        }
        echo $body;
        exit;
    }

    public static function redirect(string $url, int $status = 303): never
    {
        self::securityHeaders(false);
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    /** Fehlerseite bzw. Fehler-JSON, je nach Anfrageart. */
    public static function error(int $status, string $message): never
    {
        if (Router::isApiRequest()) {
            self::apiError($status, 'request_rejected', $message);
        }
        self::html(View::page('Fehler ' . $status,
            '<div class="card"><h1>Fehler ' . $status . '</h1><p>' . Util::h($message) . '</p>'
            . '<p><a class="btn" href="' . Util::h(Router::url('/')) . '">Zur Startseite</a></p></div>'
        ), $status);
    }
}

// ---------------------------------------------------------------------------
// 19. CONNECTOR - die api.php für den externen Server
// ---------------------------------------------------------------------------
//
// Wird pro Anbindung individuell erzeugt: Client-ID, Bootstrap-Geheimnis und
// der Ed25519-Public-Key dieser Installation sind fest eingebaut (Pinning).
// Die Datei enthält KEINE Langzeitschlüssel - die entstehen erst beim
// Pairing und landen in einer separaten Zustandsdatei.

final class ConnectorSource
{
    public static function fileName(array $client): string
    {
        return 'api.php';
    }

    public static function build(array $client): string
    {
        $keys = Vault::signingKeypair();
        $bootstrap = (string) ($client['bootstrap']['secret'] ?? '');
        $stateFile = 'sso_state_' . substr(hash('sha256', (string) $client['id']), 0, 12) . '.php';

        return strtr(self::TEMPLATE, [
            '{{CLIENT_ID}}'  => (string) $client['id'],
            '{{BOOTSTRAP}}'  => $bootstrap,
            '{{ISSUER}}'     => Util::baseUrl(),
            '{{SIGN_PUB}}'   => Util::b64u($keys['pk']),
            '{{KID}}'        => $keys['kid'],
            '{{STATE_FILE}}' => $stateFile,
            '{{GENERATED}}'  => date('c'),
            '{{APP}}'        => APP_NAME,
        ]);
    }

    private const TEMPLATE = <<<'CONNECTOR'
<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  {{APP}} - SSO-Connector für den externen Server
 * ============================================================================
 *
 *  Erzeugt am {{GENERATED}} für Client {{CLIENT_ID}}.
 *
 *  INSTALLATION
 *    1. Diese Datei auf den eigenen Server legen, z. B. als /api.php.
 *       Der Pfad muss exakt der URL entsprechen, die im Konto unter
 *       "Connector-URL" eingetragen ist.
 *    2. Das Verzeichnis muss für PHP schreibbar sein - daneben entsteht die
 *       Zustandsdatei {{STATE_FILE}} mit den ausgehandelten Schlüsseln.
 *    3. Im Konto auf "Initialisieren" drücken. Beide Seiten führen dann
 *       einen authentifizierten Schlüsselaustausch durch.
 *    4. Danach im eigenen Code verwenden:
 *
 *         require __DIR__ . '/api.php';
 *         $sso = new SsoClient();
 *
 *         // A) Weiterleitungs-Login (empfohlen - das Passwort bleibt beim IdP)
 *         if (!isset($_GET['code'])) {
 *             header('Location: ' . $sso->loginUrl('https://example.com/api.php'));
 *             exit;
 *         }
 *         $user = $sso->handleCallback('https://example.com/api.php');
 *         // $user enthält sub, username, name, email, amr, ...
 *
 *         // B) Beliebiger API-Aufruf mit signiertem Request
 *         $info = $sso->call('user/get', ['username' => 'alice']);
 *
 *  SICHERHEIT
 *    - Das Bootstrap-Geheimnis unten ist ein Einmalwert. Nach erfolgreichem
 *      Pairing ist es wertlos; die eigentlichen Schlüssel entstehen aus einem
 *      Diffie-Hellman und verlassen den Server nie.
 *    - Der Ed25519-Public-Key ist fest eingebaut. Selbst wer DNS oder TLS
 *      kontrolliert, kann sich damit nicht als Identity-Provider ausgeben.
 *    - {{STATE_FILE}} ist eine PHP-Datei, die nur ein Array zurückgibt. Wird
 *      sie versehentlich direkt aufgerufen, gibt sie nichts aus.
 * ============================================================================
 */

// --- Feste Parameter dieser Anbindung ---------------------------------------
const SSO_CLIENT_ID  = '{{CLIENT_ID}}';
const SSO_BOOTSTRAP  = '{{BOOTSTRAP}}';
const SSO_ISSUER     = '{{ISSUER}}';
const SSO_SIGN_PUB   = '{{SIGN_PUB}}';
const SSO_KID        = '{{KID}}';
const SSO_STATE_FILE = __DIR__ . '/{{STATE_FILE}}';
const SSO_SKEW       = 60;
const SSO_TIMEOUT    = 8;

final class SsoState
{
    public static function load(): array
    {
        if (!is_file(SSO_STATE_FILE)) {
            return [];
        }
        $data = @include SSO_STATE_FILE;
        return is_array($data) ? $data : [];
    }

    public static function save(array $state): void
    {
        $php = "<?php\n// {{APP}} Connector-Zustand. Nicht bearbeiten, nicht weitergeben.\nreturn "
            . var_export($state, true) . ";\n";
        $tmp = SSO_STATE_FILE . '.tmp' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new RuntimeException('Zustandsdatei nicht schreibbar: ' . SSO_STATE_FILE);
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, SSO_STATE_FILE)) {
            @unlink($tmp);
            throw new RuntimeException('Zustandsdatei nicht ersetzbar.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(SSO_STATE_FILE, true);
        }
    }

    public static function isPaired(): bool
    {
        $s = self::load();
        return ($s['status'] ?? '') === 'active' && !empty($s['k_c2s']);
    }
}

final class SsoCrypto
{
    public static function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $in): string
    {
        $in = strtr($in, '-_', '+/');
        $pad = strlen($in) % 4;
        if ($pad) {
            $in .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($in, true);
        return $out === false ? '' : $out;
    }

    /** Muss byteweise identisch zur Gegenseite sein. */
    public static function canonical(array $payload): string
    {
        unset($payload['mac'], $payload['sig']);
        $sort = static function (array $a) use (&$sort): array {
            ksort($a, SORT_STRING);
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $a[$k] = $sort($v);
                }
            }
            return $a;
        };
        return json_encode($sort($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function mac(string $key, array $payload): string
    {
        return self::b64u(hash_hmac('sha256', self::canonical($payload), $key, true));
    }

    public static function requestString(string $method, string $path, int $ts, string $nonce, string $body): string
    {
        return implode("\n", [
            'SSO-HMAC-SHA256', strtoupper($method), $path,
            (string) $ts, $nonce, hash('sha256', $body),
        ]);
    }
}

/** Verarbeitet die beiden Pairing-Nachrichten des Identity-Providers. */
final class SsoPairing
{
    private const PROTO = 'sso-pair-v1';

    public static function handle(array $msg): array
    {
        $bs = SsoCrypto::b64uDecode(SSO_BOOTSTRAP);
        if ($bs === '') {
            return ['op' => 'error', 'error' => 'Kein Bootstrap-Geheimnis eingebaut.'];
        }
        if (($msg['client_id'] ?? '') !== SSO_CLIENT_ID || ($msg['proto'] ?? '') !== self::PROTO) {
            return ['op' => 'error', 'error' => 'Nachricht gehört nicht zu diesem Connector.'];
        }
        if (abs(time() - (int) ($msg['ts'] ?? 0)) > SSO_SKEW * 5) {
            return ['op' => 'error', 'error' => 'Zeitstempel außerhalb des Fensters.'];
        }
        if (!hash_equals(SsoCrypto::mac($bs, $msg), (string) ($msg['mac'] ?? ''))) {
            return ['op' => 'error', 'error' => 'MAC ungültig.'];
        }
        return match ($msg['op'] ?? '') {
            'pair.hello'  => self::hello($msg, $bs),
            'pair.finish' => self::finish($msg, $bs),
            default       => ['op' => 'error', 'error' => 'Unbekannte Operation.'],
        };
    }

    private static function hello(array $msg, string $bs): array
    {
        // Identität des IdP prüfen - der Public-Key steckt fest in dieser Datei.
        $sig = SsoCrypto::b64uDecode((string) ($msg['sig'] ?? ''));
        $pub = SsoCrypto::b64uDecode(SSO_SIGN_PUB);
        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($sig, SsoCrypto::canonical($msg), $pub)) {
            return ['op' => 'error', 'error' => 'Ed25519-Signatur des IdP ungültig.'];
        }
        if (SsoState::isPaired()) {
            return ['op' => 'error', 'error' => 'Bereits gekoppelt. Zustandsdatei löschen, um neu zu koppeln.'];
        }
        $serverPub = SsoCrypto::b64uDecode((string) ($msg['server_pub'] ?? ''));
        if (strlen($serverPub) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return ['op' => 'error', 'error' => 'Ungültiger Public-Key.'];
        }
        $nonce = (string) $msg['nonce'];

        $kp = sodium_crypto_box_keypair();
        $sk = sodium_crypto_box_secretkey($kp);
        $pk = sodium_crypto_box_publickey($kp);
        $shared = sodium_crypto_scalarmult($sk, $serverPub);

        $transcript = implode('|', [
            self::PROTO, SSO_CLIENT_ID, $nonce,
            SsoCrypto::b64u($serverPub), SsoCrypto::b64u($pk),
        ]);
        $kC2S = hash_hkdf('sha256', $shared, 32, $transcript . '|c2s', $nonce);
        $kS2C = hash_hkdf('sha256', $shared, 32, $transcript . '|s2c', $nonce);
        sodium_memzero($shared);
        sodium_memzero($sk);

        SsoState::save([
            'status'     => 'pending',
            'k_c2s'      => SsoCrypto::b64u($kC2S),
            'k_s2c'      => SsoCrypto::b64u($kS2C),
            'transcript' => $transcript,
            'nonce'      => $nonce,
            'issuer'     => (string) ($msg['issuer'] ?? SSO_ISSUER),
            'started'    => time(),
        ]);

        $out = [
            'v'          => 1,
            'op'         => 'pair.hello.ok',
            'proto'      => self::PROTO,
            'client_id'  => SSO_CLIENT_ID,
            'nonce'      => $nonce,
            'ts'         => time(),
            'client_pub' => SsoCrypto::b64u($pk),
            'confirm'    => SsoCrypto::b64u(hash_hmac('sha256', 'confirm-client|' . $transcript, $kC2S, true)),
        ];
        $out['mac'] = SsoCrypto::mac($bs, $out);
        return $out;
    }

    private static function finish(array $msg, string $bs): array
    {
        $state = SsoState::load();
        if (($state['status'] ?? '') !== 'pending' || empty($state['transcript'])) {
            return ['op' => 'error', 'error' => 'Kein laufendes Pairing.'];
        }
        if (!hash_equals((string) $state['nonce'], (string) ($msg['nonce'] ?? ''))) {
            return ['op' => 'error', 'error' => 'Nonce passt nicht.'];
        }
        $kS2C = SsoCrypto::b64uDecode((string) $state['k_s2c']);
        $expected = SsoCrypto::b64u(
            hash_hmac('sha256', 'confirm-server|' . $state['transcript'], $kS2C, true)
        );
        if (!hash_equals($expected, (string) ($msg['confirm'] ?? ''))) {
            return ['op' => 'error', 'error' => 'Key-Confirmation fehlgeschlagen.'];
        }
        $state['status'] = 'active';
        $state['paired_at'] = time();
        unset($state['transcript'], $state['nonce']);
        SsoState::save($state);

        $out = [
            'v'         => 1,
            'op'        => 'pair.finish.ok',
            'proto'     => self::PROTO,
            'client_id' => SSO_CLIENT_ID,
            'ts'        => time(),
        ];
        $out['mac'] = SsoCrypto::mac($bs, $out);
        return $out;
    }
}

/** Schnittstelle für die eigene Anwendung. */
final class SsoClient
{
    private array $state;

    public function __construct()
    {
        $this->state = SsoState::load();
    }

    public function isPaired(): bool
    {
        return SsoState::isPaired();
    }

    public function issuer(): string
    {
        return rtrim((string) ($this->state['issuer'] ?? SSO_ISSUER), '/');
    }

    /**
     * Baut die Login-URL (Weiterleitungsverfahren mit PKCE).
     * $redirectUri muss im Konto als erlaubte Rückleit-URL eingetragen sein.
     */
    public function loginUrl(string $redirectUri, array $scopes = ['profile']): string
    {
        $verifier = SsoCrypto::b64u(random_bytes(48));
        $state = SsoCrypto::b64u(random_bytes(24));
        $this->rememberFlow($state, $verifier, $redirectUri);
        $query = http_build_query([
            'client_id'             => SSO_CLIENT_ID,
            'redirect_uri'          => $redirectUri,
            'state'                 => $state,
            'scope'                 => implode(' ', $scopes),
            'code_challenge'        => SsoCrypto::b64u(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        return $this->issuer() . '/usr/authorize?' . $query;
    }

    /**
     * Wertet die Rückleitung aus und tauscht den Code gegen eine Assertion.
     *
     * @return array Die geprüften Claims des angemeldeten Nutzers.
     */
    public function handleCallback(string $redirectUri): array
    {
        $code = (string) ($_GET['code'] ?? '');
        $state = (string) ($_GET['state'] ?? '');
        if ($code === '' || $state === '') {
            throw new RuntimeException('Rückleitung ohne code/state.');
        }
        $flow = $this->takeFlow($state);
        if ($flow === null) {
            throw new RuntimeException('Unbekannter oder abgelaufener state-Wert (CSRF-Schutz).');
        }
        if (!hash_equals((string) $flow['redirect_uri'], $redirectUri)) {
            throw new RuntimeException('redirect_uri stimmt nicht mit dem Start überein.');
        }
        $res = $this->call('token', [
            'code'          => $code,
            'code_verifier' => $flow['verifier'],
            'redirect_uri'  => $redirectUri,
        ]);
        $assertion = (string) ($res['assertion'] ?? '');
        return $this->verifyAssertion($assertion);
    }

    /** Prüft eine Assertion lokal gegen den eingebauten Ed25519-Key. */
    public function verifyAssertion(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Assertion hat ein falsches Format.');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(SsoCrypto::b64uDecode($h), true);
        $claims = json_decode(SsoCrypto::b64uDecode($p), true);
        if (!is_array($header) || !is_array($claims)) {
            throw new RuntimeException('Assertion unlesbar.');
        }
        if (($header['alg'] ?? '') !== 'EdDSA') {
            throw new RuntimeException('Unerlaubter Signaturalgorithmus.');
        }
        if (!hash_equals(SSO_KID, (string) ($header['kid'] ?? ''))) {
            throw new RuntimeException('Unbekannte Schlüssel-ID - IdP hat rotiert?');
        }
        $sig = SsoCrypto::b64uDecode($s);
        $pub = SsoCrypto::b64uDecode(SSO_SIGN_PUB);
        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($sig, $h . '.' . $p, $pub)) {
            throw new RuntimeException('Signatur der Assertion ist ungültig.');
        }
        $now = time();
        if ((int) ($claims['exp'] ?? 0) < $now) {
            throw new RuntimeException('Assertion ist abgelaufen.');
        }
        if ((int) ($claims['nbf'] ?? $now) > $now + SSO_SKEW) {
            throw new RuntimeException('Assertion ist noch nicht gültig.');
        }
        if (!hash_equals(SSO_CLIENT_ID, (string) ($claims['aud'] ?? ''))) {
            throw new RuntimeException('Assertion war für einen anderen Client bestimmt.');
        }
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $this->issuer()) {
            throw new RuntimeException('Assertion stammt von einem anderen Aussteller.');
        }
        return $claims;
    }

    /**
     * Signierter API-Aufruf. $endpoint ist der Teil hinter /api/v1/,
     * also z. B. 'ping', 'user/get', 'auth/start'.
     */
    public function call(string $endpoint, array $payload = []): array
    {
        if (!$this->isPaired()) {
            throw new RuntimeException('Connector ist noch nicht gekoppelt.');
        }
        $key = SsoCrypto::b64uDecode((string) $this->state['k_c2s']);
        $issuer = $this->issuer();
        $path = parse_url($issuer, PHP_URL_PATH) ?: '';
        $path = rtrim((string) $path, '/') . '/api/v1/' . ltrim($endpoint, '/');
        $url = $issuer . '/api/v1/' . ltrim($endpoint, '/');
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = time();
        $nonce = bin2hex(random_bytes(16));
        $sig = SsoCrypto::b64u(
            hash_hmac('sha256', SsoCrypto::requestString('POST', $path, $ts, $nonce, $body), $key, true)
        );
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Sso-Client: ' . SSO_CLIENT_ID,
            'X-Sso-Timestamp: ' . $ts,
            'X-Sso-Nonce: ' . $nonce,
            'X-Sso-Signature: ' . $sig,
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => SSO_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => SSO_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                throw new RuntimeException('Verbindung zum IdP fehlgeschlagen: ' . $err);
            }
        } else {
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => SSO_TIMEOUT,
                'ignore_errors' => true,
            ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
            $raw = @file_get_contents($url, false, $ctx);
            $status = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                }
            }
            if ($raw === false) {
                throw new RuntimeException('Verbindung zum IdP fehlgeschlagen.');
            }
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Antwort war kein JSON (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('API-Fehler ' . $status . ': '
                . (string) ($data['error'] ?? '?') . ' ' . (string) ($data['message'] ?? ''));
        }
        return $data;
    }

    // -- Ablage des PKCE-Zustands zwischen Weiterleitung und Rückkehr ------
    private function rememberFlow(string $state, string $verifier, string $redirectUri): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['sso_flows'] ??= [];
        $_SESSION['sso_flows'][$state] = [
            'verifier'     => $verifier,
            'redirect_uri' => $redirectUri,
            'exp'          => time() + 600,
        ];
    }

    private function takeFlow(string $state): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flows = $_SESSION['sso_flows'] ?? [];
        $flow = $flows[$state] ?? null;
        unset($flows[$state]);
        foreach ($flows as $k => $f) {
            if ((int) ($f['exp'] ?? 0) < time()) {
                unset($flows[$k]);
            }
        }
        $_SESSION['sso_flows'] = $flows;
        if (!is_array($flow) || (int) $flow['exp'] < time()) {
            return null;
        }
        return $flow;
    }
}

// ---------------------------------------------------------------------------
// Direktaufruf: Pairing-Nachrichten beantworten, sonst Statusseite zeigen.
// Wird die Datei per require eingebunden, passiert hier nichts.
// ---------------------------------------------------------------------------
if (!defined('SSO_CONNECTOR_LIBRARY_ONLY')
    && isset($_SERVER['REQUEST_METHOD'])
    && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = (string) file_get_contents('php://input');
        $msg = json_decode($raw, true);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if (!is_array($msg)) {
            http_response_code(400);
            echo json_encode(['op' => 'error', 'error' => 'Kein JSON-Body.']);
            exit;
        }
        try {
            $out = SsoPairing::handle($msg);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['op' => 'error', 'error' => $e->getMessage()]);
            exit;
        }
        http_response_code(($out['op'] ?? '') === 'error' ? 400 : 200);
        echo json_encode($out, JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    $paired = SsoState::isPaired();
    $writable = is_writable(__DIR__);
    echo '<!doctype html><meta charset="utf-8"><title>SSO-Connector</title>'
        . '<div style="font:15px/1.6 system-ui;max-width:44rem;margin:3rem auto;padding:0 1rem">'
        . '<h1 style="font-size:1.3rem">SSO-Connector</h1>'
        . '<p>Client-ID: <code>' . htmlspecialchars(SSO_CLIENT_ID, ENT_QUOTES) . '</code></p>'
        . '<p>Status: <strong>' . ($paired ? 'gekoppelt' : 'noch nicht gekoppelt') . '</strong></p>'
        . '<p>Verzeichnis beschreibbar: <strong>' . ($writable ? 'ja' : 'nein - Pairing wird scheitern')
        . '</strong></p>'
        . ($paired ? '' : '<p>Jetzt im Konto beim Identity-Provider auf &bdquo;Initialisieren&ldquo; '
            . 'drücken.</p>')
        . '</div>';
    exit;
}
CONNECTOR;
}

// ---------------------------------------------------------------------------
// 20. OBERFLAECHE
// ---------------------------------------------------------------------------

final class View
{
    private const CSS = <<<'CSS'
*,*::before,*::after{box-sizing:border-box}
:root{
  --bg:#0b0f14;--panel:#131a23;--panel2:#182231;--line:#243244;--fg:#e6edf5;
  --muted:#93a4b8;--accent:#4da3ff;--accent2:#7ee2b8;--warn:#ffb454;--err:#ff6b6b;
  --radius:14px;
}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:linear-gradient(180deg,#0b0f14 0%,#0d131b 100%);
  color:var(--fg);font:16px/1.65 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  min-height:100vh}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
.wrap{max-width:64rem;margin:0 auto;padding:0 1.1rem 4rem}
header.top{border-bottom:1px solid var(--line);background:rgba(11,15,20,.85);
  position:sticky;top:0;z-index:10;backdrop-filter:blur(8px)}
header.top .wrap{display:flex;align-items:center;gap:1rem;padding-top:.85rem;padding-bottom:.85rem}
.brand{font-weight:700;letter-spacing:.02em;color:var(--fg);display:flex;align-items:center;gap:.55rem}
.brand .dot{width:.7rem;height:.7rem;border-radius:50%;
  background:linear-gradient(135deg,var(--accent),var(--accent2));box-shadow:0 0 12px rgba(77,163,255,.6)}
nav.main{margin-left:auto;display:flex;gap:1.15rem;align-items:center;flex-wrap:wrap;font-size:.94rem}
nav.main a{color:var(--muted)}
nav.main a.active,nav.main a:hover{color:var(--fg)}
h1{font-size:1.6rem;line-height:1.25;margin:0 0 .6rem}
h2{font-size:1.18rem;margin:2rem 0 .7rem}
h3{font-size:1rem;margin:1.4rem 0 .5rem;color:var(--muted);text-transform:uppercase;
  letter-spacing:.08em;font-weight:600}
p{margin:.55rem 0}
.lead{color:var(--muted);font-size:1.03rem}
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);
  padding:1.4rem;margin:1.1rem 0}
.card.tight{padding:1rem 1.1rem}
.grid{display:grid;gap:1.1rem}
@media(min-width:52rem){.grid.two{grid-template-columns:1.05fr .95fr}
  .grid.three{grid-template-columns:repeat(3,1fr)}}
.hero{padding:2.2rem 0 .4rem}
label{display:block;font-size:.87rem;color:var(--muted);margin:.9rem 0 .3rem;font-weight:500}
input[type=text],input[type=password],input[type=email],input[type=url],input[type=tel],select,textarea{
  width:100%;padding:.68rem .8rem;background:#0c1118;color:var(--fg);
  border:1px solid var(--line);border-radius:10px;font:inherit;font-size:.97rem}
input:focus,select:focus,textarea:focus{outline:2px solid var(--accent);outline-offset:1px;border-color:transparent}
input[type=checkbox]{width:1.05rem;height:1.05rem;vertical-align:-2px;accent-color:var(--accent)}
.otp{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:1.5rem;letter-spacing:.5rem;
  text-align:center}
.btn{display:inline-block;border:0;cursor:pointer;font:inherit;font-weight:600;font-size:.95rem;
  padding:.66rem 1.15rem;border-radius:10px;background:var(--accent);color:#04121f;
  text-decoration:none;margin-top:1rem}
.btn:hover{filter:brightness(1.08);text-decoration:none}
.btn.sec{background:transparent;color:var(--fg);border:1px solid var(--line)}
.btn.danger{background:transparent;color:var(--err);border:1px solid #4a2530}
.btn.sm{padding:.38rem .7rem;font-size:.86rem;margin-top:0}
.row{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}
.muted{color:var(--muted)}
.small{font-size:.86rem}
code,kbd{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.88em;
  background:#0c1118;border:1px solid var(--line);border-radius:6px;padding:.1rem .35rem}
pre{background:#0a0e14;border:1px solid var(--line);border-radius:12px;padding:1rem;
  overflow-x:auto;font-size:.84rem;line-height:1.55}
pre code{background:none;border:0;padding:0}
.alert{border-radius:12px;padding:.8rem 1rem;margin:.9rem 0;border:1px solid;font-size:.94rem}
.alert.ok{border-color:#1e5b45;background:#0f2b22;color:var(--accent2)}
.alert.err{border-color:#5b2530;background:#2b1216;color:#ffb3b3}
.alert.warn{border-color:#5b4620;background:#2b2010;color:var(--warn)}
.alert.info{border-color:#1e3f5b;background:#0f1f2b;color:#9fd0ff}
table{width:100%;border-collapse:collapse;font-size:.92rem}
th,td{text-align:left;padding:.55rem .6rem;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--muted);font-weight:600;font-size:.83rem;text-transform:uppercase;letter-spacing:.05em}
.tag{display:inline-block;font-size:.74rem;padding:.16rem .5rem;border-radius:999px;
  border:1px solid var(--line);color:var(--muted);letter-spacing:.03em}
.tag.ok{color:var(--accent2);border-color:#1e5b45}
.tag.pend{color:var(--warn);border-color:#5b4620}
.tag.off{color:var(--err);border-color:#5b2530}
.qr{background:#fff;padding:.9rem;border-radius:12px;display:inline-block;line-height:0}
.codes{display:grid;grid-template-columns:repeat(2,1fr);gap:.45rem;font-family:ui-monospace,monospace;
  font-size:.95rem}
.codes div{background:#0c1118;border:1px solid var(--line);border-radius:8px;padding:.45rem .6rem;
  text-align:center;letter-spacing:.06em}
.steps{counter-reset:s;list-style:none;padding:0;margin:1rem 0}
.steps li{counter-increment:s;position:relative;padding-left:2.4rem;margin:.9rem 0}
.steps li::before{content:counter(s);position:absolute;left:0;top:.05rem;width:1.7rem;height:1.7rem;
  border-radius:50%;background:var(--panel2);border:1px solid var(--line);color:var(--accent);
  display:grid;place-items:center;font-size:.83rem;font-weight:700}
footer.site{border-top:1px solid var(--line);margin-top:3rem;padding:1.4rem 0;color:var(--muted);
  font-size:.85rem}
.split{display:flex;justify-content:space-between;gap:1rem;align-items:baseline;flex-wrap:wrap}
.break{word-break:break-all}
.hidden{display:none}
CSS;

    public static function page(string $title, string $body, ?array $user = null, string $active = ''): string
    {
        $n = Response::nonce();
        $base = Router::basePath();
        $nav = self::nav($user, $active);
        $alerts = self::alerts();
        $t = Util::h($title . ' – ' . APP_NAME);
        $css = self::CSS;
        $app = Util::h(APP_NAME);
        $ver = Util::h(APP_VERSION);
        return <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{$t}</title>
<style nonce="{$n}">{$css}</style>
</head>
<body>
<header class="top"><div class="wrap">
  <a class="brand" href="{$base}/"><span class="dot"></span>{$app}</a>
  {$nav}
</div></header>
<main class="wrap">
{$alerts}
{$body}
</main>
<footer class="site"><div class="wrap">
  {$app} {$ver} · Identitäts- und MFA-Dienst · <a href="{$base}/api">API-Dokumentation</a>
</div></footer>
</body>
</html>
HTML;
    }

    private static function nav(?array $user, string $active): string
    {
        $base = Router::basePath();
        $a = static fn(string $key): string => $active === $key ? ' class="active"' : '';
        if ($user !== null) {
            $admin = !empty($user['admin'])
                ? '<a href="' . $base . '/usr/admin"' . $a('admin') . '>Administration</a>' : '';
            return '<nav class="main">'
                . '<a href="' . $base . '/usr/"' . $a('home') . '>Konto</a>'
                . '<a href="' . $base . '/usr/security"' . $a('security') . '>Sicherheit</a>'
                . '<a href="' . $base . '/usr/api"' . $a('api') . '>API-Anbindungen</a>'
                . '<a href="' . $base . '/api"' . $a('docs') . '>Doku</a>'
                . $admin
                . '<a href="' . $base . '/usr/logout">Abmelden</a>'
                . '</nav>';
        }
        return '<nav class="main">'
            . '<a href="' . $base . '/usr/login"' . $a('login') . '>Anmelden</a>'
            . '<a href="' . $base . '/usr/register"' . $a('register') . '>Konto anlegen</a>'
            . '<a href="' . $base . '/api"' . $a('docs') . '>API</a>'
            . '</nav>';
    }

    private static function alerts(): string
    {
        $out = '';
        foreach (Session::takeFlash() as $f) {
            $type = in_array($f['type'], ['ok', 'err', 'warn', 'info'], true) ? $f['type'] : 'info';
            $out .= '<div class="alert ' . $type . '">' . Util::h((string) $f['msg']) . '</div>';
        }
        return $out;
    }

    /** Fehlerliste eines Formulars. */
    public static function errors(array $errors): string
    {
        if (!$errors) {
            return '';
        }
        $items = '';
        foreach ($errors as $e) {
            $items .= '<li>' . Util::h((string) $e) . '</li>';
        }
        return '<div class="alert err"><ul style="margin:.2rem 0 .2rem 1rem;padding:0">'
            . $items . '</ul></div>';
    }
}

// ---------------------------------------------------------------------------
// 21. ROUTER
// ---------------------------------------------------------------------------

final class Router
{
    private static ?string $path = null;
    private static ?string $base = null;

    /** Unterverzeichnis, in dem die Anwendung liegt ('' im Webroot). */
    public static function basePath(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $self = '/' . basename(__FILE__);
        // SCRIPT_NAME zeigt je nach Server auf diese Datei oder auf die
        // aufgelöste Zieldatei. Nur der erste Fall darf ein Basisverzeichnis
        // ergeben - sonst würde z. B. /totp/index.html das Prefix "/totp"
        // liefern und die Sperre des Datenverzeichnisses aushebeln.
        $dir = str_ends_with($script, $self)
            ? rtrim(substr($script, 0, -strlen($self)), '/')
            : '';
        return self::$base = ($dir === '.' || $dir === '/' ? '' : $dir);
    }

    /** Angefragter Pfad ohne Basisverzeichnis, immer mit führendem Slash. */
    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $p = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $p = rawurldecode($p);
        $base = self::basePath();
        if ($base !== '' && str_starts_with($p, $base)) {
            $p = substr($p, strlen($base));
        }
        // Aufruf ohne Rewrite: /index.php/usr/login
        if (str_starts_with($p, '/index.php')) {
            $p = substr($p, strlen('/index.php'));
        }
        $p = '/' . ltrim($p, '/');
        // Steuerzeichen entfernen (Header-Injection über %0d%0a im Pfad),
        // dann mehrfache Slashes und Traversal-Versuche.
        $p = preg_replace('/[\x00-\x1F\x7F]/', '', $p) ?? '/';
        $p = preg_replace('#/+#', '/', $p) ?? '/';
        if (str_contains($p, '..')) {
            $p = '/';
        }
        return self::$path = ($p === '' ? '/' : $p);
    }

    public static function url(string $path = '/'): string
    {
        return self::basePath() . '/' . ltrim($path, '/');
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function isApiRequest(): bool
    {
        return str_starts_with(self::path(), '/api/v1');
    }

    public static function dispatch(): void
    {
        Storage::bootstrap();
        Storage::gc();
        $p = rtrim(self::path(), '/');
        $p = $p === '' ? '/' : $p;

        // Das Datenverzeichnis ist über die Anwendung nie erreichbar. Der
        // Vergleich läuft auf dem ersten Pfadsegment - Endpunkte wie
        // /api/v1/auth/totp bleiben davon unberührt.
        if (str_starts_with($p . '/', '/' . basename(DATA_DIR) . '/')) {
            Response::error(404, 'Nicht gefunden.');
        }
        if (str_starts_with($p, '/api/v1')) {
            Api::dispatch(substr($p, strlen('/api/v1')));
        }
        if ($p === '/api') {
            Web::apiDocs();
        }
        if ($p === '/') {
            Web::landing();
        }
        if (str_starts_with($p, '/usr')) {
            Web::dispatch(substr($p, strlen('/usr')) ?: '/');
        }
        if ($p === '/.well-known/jwks.json') {
            Response::json(Assertion::jwks());
        }
        Response::error(404, 'Diese Seite gibt es nicht.');
    }
}

// ---------------------------------------------------------------------------
// 22. NUTZERBEREICH (Web)
// ---------------------------------------------------------------------------

final class Web
{
    // -- Hilfen ------------------------------------------------------------

    /**
     * Verlangt einen vollständig angemeldeten Nutzer.
     * Solange kein TOTP eingerichtet ist, wird auf die Einrichtung umgeleitet -
     * MFA ist auf dieser Installation nicht optional.
     */
    private static function requireUser(bool $allowSetup = false): array
    {
        $user = Session::user();
        if ($user === null) {
            Session::set('after_login', Router::path()
                . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
            Response::redirect(Router::url('/usr/login'));
        }
        if (!empty($user['disabled'])) {
            Session::destroy();
            Response::error(403, 'Dieses Konto ist gesperrt.');
        }
        if (empty($user['totp_enabled']) && !$allowSetup) {
            Session::flash('warn', 'Bitte richten Sie zuerst die Zwei-Faktor-Authentisierung ein.');
            Response::redirect(Router::url('/usr/mfa/setup'));
        }
        return $user;
    }

    private static function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    private static function query(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public static function dispatch(string $sub): void
    {
        $sub = rtrim($sub, '/');
        $sub = $sub === '' ? '/' : $sub;
        match (true) {
            $sub === '/'            => self::dashboard(),
            $sub === '/login'       => self::login(),
            $sub === '/register'    => self::register(),
            $sub === '/logout'      => self::logout(),
            $sub === '/mfa'         => self::mfa(),
            $sub === '/mfa/setup'   => self::mfaSetup(),
            $sub === '/mfa/recovery' => self::recoveryCodes(),
            $sub === '/security'    => self::security(),
            $sub === '/api'         => self::apiClients(),
            $sub === '/authorize'   => self::authorize(),
            $sub === '/admin'       => self::admin(),
            (bool) preg_match('#^/api/([a-f0-9]{32})$#', $sub, $m) => self::apiClient($m[1]),
            (bool) preg_match('#^/api/([a-f0-9]{32})/connector$#', $sub, $m) => self::connectorDownload($m[1]),
            default => Response::error(404, 'Diese Seite gibt es nicht.'),
        };
    }

    // -- Landingpage -------------------------------------------------------

    public static function landing(): void
    {
        if (Session::user() !== null) {
            Response::redirect(Router::url('/usr/'));
        }
        $base = Router::basePath();
        $csrf = Csrf::field();
        $count = Users::count();
        $firstHint = $count === 0
            ? '<div class="alert info">Diese Installation ist noch leer. Das <strong>erste Konto</strong> '
                . 'erhält automatisch Administratorrechte.</div>'
            : '';

        $body = <<<HTML
<section class="hero">
  <h1>Ein Konto. Passwort und zweiter Faktor an einem Ort.</h1>
  <p class="lead">Dieser Dienst verwaltet Ihre Anmeldedaten und prüft bei jeder Anmeldung
  einen zeitbasierten Einmalcode (TOTP). Angebundene Anwendungen erfahren nie Ihr Passwort -
  sie bekommen nur eine signierte Bestätigung, dass Sie es sind.</p>
</section>
{$firstHint}
<div class="grid two">
  <div class="card">
    <h2 style="margin-top:0">Anmelden</h2>
    <form method="post" action="{$base}/usr/login" autocomplete="on">
      {$csrf}
      <label for="u">Nutzername</label>
      <input id="u" name="username" type="text" autocomplete="username" required
             autocapitalize="none" spellcheck="false" autofocus>
      <label for="p">Passwort</label>
      <input id="p" name="password" type="password" autocomplete="current-password" required>
      <button class="btn" type="submit">Weiter zum zweiten Faktor</button>
    </form>
    <p class="small muted" style="margin-top:1rem">Noch kein Konto?
      <a href="{$base}/usr/register">Jetzt anlegen</a> – dauert eine Minute,
      der zweite Faktor wird direkt mit eingerichtet.</p>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Was Sie hier verwalten</h2>
    <ul style="margin:.4rem 0 0 1.05rem;padding:0;line-height:1.9">
      <li>Passwort (Argon2id, serverseitig zusätzlich geHMACt)</li>
      <li>TOTP-Geheimnis für Ihre Authenticator-App</li>
      <li>Zehn Wiederherstellungscodes zur Einmalverwendung</li>
      <li>Optional: Rufnummer für SMS-Einmalcodes</li>
      <li>Angebundene Anwendungen und deren Schlüssel</li>
      <li>Aktive Sitzungen</li>
    </ul>
  </div>
</div>

<h2>Für Entwickler: eigene Anwendung anbinden</h2>
<p class="lead">Anbindungen legen Sie <strong>in Ihrem Konto</strong> an – ohne Anmeldung
gibt es hier keine Schlüssel. Zwei Wege stehen zur Wahl.</p>
<div class="grid two">
  <div class="card tight">
    <h3 style="margin-top:0">Weg A – automatisch koppeln</h3>
    <ol class="steps">
      <li>Im Konto unter <em>API-Anbindungen</em> eine Anbindung anlegen und die
          Domain des eigenen Servers eintragen.</li>
      <li>Die erzeugte <code>api.php</code> herunterladen und dort ablegen.</li>
      <li>Auf <em>Initialisieren</em> drücken. Beide Skripte handeln per X25519
          Sitzungsschlüssel aus und bestätigen sie gegenseitig.</li>
      <li>Fertig – ab jetzt signiert Ihr Server jeden Aufruf automatisch.</li>
    </ol>
  </div>
  <div class="card tight">
    <h3 style="margin-top:0">Weg B – selbst implementieren</h3>
    <p>Anbindung im Modus <em>Manuelle Schlüssel</em> anlegen, Client-ID und Secret
    kopieren und die Signatur selbst bauen:</p>
<pre><code>sig = HMAC-SHA256(secret,
      "SSO-HMAC-SHA256\\nPOST\\n/api/v1/ping\\n" +
      timestamp + "\\n" + nonce + "\\n" +
      sha256(body))</code></pre>
    <p class="small muted">Vollständige Beschreibung inklusive aller Endpunkte in der
    <a href="{$base}/api">API-Dokumentation</a>.</p>
  </div>
</div>
HTML;
        Response::html(View::page('Start', $body, null, 'home'));
    }

    // -- Registrierung -----------------------------------------------------

    public static function register(): void
    {
        if (Session::user() !== null) {
            Response::redirect(Router::url('/usr/'));
        }
        $errors = [];
        $values = ['username' => '', 'email' => '', 'display' => ''];
        if (Router::isPost()) {
            Csrf::require();
            if (!RateLimit::hit('register:' . Util::clientIp(), 5, 3600)) {
                Response::error(429, 'Zu viele Registrierungen von dieser Adresse.');
            }
            $values['username'] = self::post('username');
            $values['email'] = self::post('email');
            $values['display'] = self::post('display');
            $pw = (string) ($_POST['password'] ?? '');
            $pw2 = (string) ($_POST['password2'] ?? '');
            if ($pw !== $pw2) {
                $errors[] = 'Die beiden Passwörter stimmen nicht überein.';
            } else {
                $res = Users::create($values['username'], $values['email'], $pw, $values['display']);
                if ($res['ok']) {
                    Session::login($res['user'], true);
                    Session::flash('ok', 'Konto angelegt. Jetzt noch den zweiten Faktor einrichten.');
                    Response::redirect(Router::url('/usr/mfa/setup'));
                }
                $errors = $res['errors'];
            }
        }

        $base = Router::basePath();
        $csrf = Csrf::field();
        $err = View::errors($errors);
        $u = Util::h($values['username']);
        $e = Util::h($values['email']);
        $d = Util::h($values['display']);
        $min = PW_MIN_LENGTH;
        $body = <<<HTML
<h1>Konto anlegen</h1>
<p class="lead">Nach dem Anlegen richten Sie direkt Ihre Authenticator-App ein.
Ohne zweiten Faktor kommen Sie nicht weiter.</p>
{$err}
<div class="card" style="max-width:32rem">
<form method="post">
  {$csrf}
  <label for="u">Nutzername</label>
  <input id="u" name="username" type="text" value="{$u}" required autocapitalize="none"
         spellcheck="false" autocomplete="username" autofocus>
  <label for="d">Anzeigename (optional)</label>
  <input id="d" name="display" type="text" value="{$d}" autocomplete="name">
  <label for="e">E-Mail</label>
  <input id="e" name="email" type="email" value="{$e}" required autocomplete="email">
  <label for="p">Passwort (mindestens {$min} Zeichen)</label>
  <input id="p" name="password" type="password" required autocomplete="new-password">
  <label for="p2">Passwort wiederholen</label>
  <input id="p2" name="password2" type="password" required autocomplete="new-password">
  <button class="btn" type="submit">Konto anlegen</button>
</form>
</div>
<p class="small muted">Schon registriert? <a href="{$base}/usr/login">Zur Anmeldung</a></p>
HTML;
        Response::html(View::page('Konto anlegen', $body, null, 'register'));
    }

    // -- Anmeldung: Schritt 1 (Passwort) -----------------------------------

    public static function login(): void
    {
        if (Session::user() !== null) {
            Response::redirect(Router::url('/usr/'));
        }
        $errors = [];
        $username = '';
        if (Router::isPost()) {
            Csrf::require();
            $username = Util::normalizeUsername(self::post('username'));
            $password = (string) ($_POST['password'] ?? '');
            $ip = Util::clientIp();

            if (!RateLimit::hit('login:ip:' . $ip, 40, 600)) {
                Audit::log('login.ratelimited', ['ip' => $ip]);
                Response::error(429, 'Zu viele Anmeldeversuche. Bitte später erneut.');
            }
            $lock = RateLimit::lockRemaining('login:' . $username);
            if ($lock > 0) {
                $errors[] = 'Zu viele Fehlversuche. Bitte ' . $lock . ' Sekunden warten.';
            } else {
                $user = Users::byUsername($username);
                // Immer hashen, auch wenn es den Nutzer nicht gibt: sonst
                // verrät die Antwortzeit, welche Konten existieren.
                $ok = $user !== null && empty($user['disabled'])
                    && Users::verifyPassword($user, $password);
                if (!$ok) {
                    Users::dummyVerify($password);
                    RateLimit::registerFailure('login:' . $username);
                    Audit::log('login.failed', ['username' => $username]);
                    $errors[] = 'Nutzername oder Passwort ist falsch.';
                } else {
                    RateLimit::clearFailures('login:' . $username);
                    if (!empty($user['totp_enabled'])) {
                        // Zweiter Faktor: Zwischenzustand, noch keine Anmeldung.
                        Session::regenerate();
                        Session::set('pending_uid', $user['id']);
                        Session::set('pending_exp', time() + MFA_CHALLENGE_TTL);
                        Audit::log('login.password_ok', [], (string) $user['id']);
                        Response::redirect(Router::url('/usr/mfa'));
                    }
                    Session::login($user, true);
                    Audit::log('login.success', ['mfa' => false], (string) $user['id']);
                    Response::redirect(Router::url('/usr/mfa/setup'));
                }
            }
        }

        $base = Router::basePath();
        $csrf = Csrf::field();
        $err = View::errors($errors);
        $u = Util::h($username);
        $body = <<<HTML
<h1>Anmelden</h1>
{$err}
<div class="card" style="max-width:28rem">
<form method="post">
  {$csrf}
  <label for="u">Nutzername</label>
  <input id="u" name="username" type="text" value="{$u}" required autocapitalize="none"
         spellcheck="false" autocomplete="username" autofocus>
  <label for="p">Passwort</label>
  <input id="p" name="password" type="password" required autocomplete="current-password">
  <button class="btn" type="submit">Anmelden</button>
</form>
</div>
<p class="small muted"><a href="{$base}/usr/register">Konto anlegen</a></p>
HTML;
        Response::html(View::page('Anmelden', $body, null, 'login'));
    }

    // -- Anmeldung: Schritt 2 (zweiter Faktor) ------------------------------

    public static function mfa(): void
    {
        $uid = Session::get('pending_uid');
        $exp = (int) Session::get('pending_exp', 0);
        if (!is_string($uid) || $exp < time()) {
            Session::forget('pending_uid');
            Response::redirect(Router::url('/usr/login'));
        }
        $user = Users::byId($uid);
        if ($user === null) {
            Session::destroy();
            Response::redirect(Router::url('/usr/login'));
        }

        $errors = [];
        $smsPossible = !empty($user['sms_enabled']) && !empty($user['phone_ok']) && OdooSms::isReady();

        if (Router::isPost()) {
            Csrf::require();
            $action = self::post('action', 'verify');
            if ($action === 'sms' && $smsPossible) {
                $res = SmsOtp::issue($user, 'login');
                Session::flash($res['ok'] ? 'ok' : 'err',
                    $res['ok'] ? 'Einmalcode wurde per SMS verschickt.' : $res['error']);
                Response::redirect(Router::url('/usr/mfa'));
            }
            if (!RateLimit::hit('mfa:' . $uid, 10, 300)) {
                Audit::log('mfa.ratelimited', [], $uid);
                Response::error(429, 'Zu viele Versuche. Bitte später erneut.');
            }
            $code = self::post('code');
            $method = null;
            if (Users::verifyTotp($user, $code)) {
                $method = 'otp';
            } elseif ($smsPossible && SmsOtp::verify($user, 'login', $code)) {
                $method = 'sms';
            } elseif (Users::useRecoveryCode($user, $code)) {
                $method = 'recovery';
            }
            if ($method === null) {
                RateLimit::registerFailure('mfa:' . $uid);
                Audit::log('mfa.failed', [], $uid);
                $errors[] = 'Der Code stimmt nicht. Achten Sie auf die Uhrzeit Ihres Geräts.';
            } else {
                RateLimit::clearFailures('mfa:' . $uid);
                $user = Users::byId($uid) ?? $user;
                $user['last_login'] = time();
                Users::save($user);
                Session::forget('pending_uid');
                Session::forget('pending_exp');
                Session::login($user, true);
                Session::set('amr', ['pwd', $method]);
                Audit::log('login.success', ['method' => $method], $uid);
                if ($method === 'recovery') {
                    Session::flash('warn', 'Ein Wiederherstellungscode wurde verbraucht. '
                        . 'Bitte prüfen Sie Ihren Vorrat.');
                }
                $target = Session::get('after_login');
                Session::forget('after_login');
                $safe = is_string($target) && str_starts_with($target, '/usr/')
                    ? Router::url($target) : Router::url('/usr/');
                Response::redirect($safe);
            }
        }

        $csrf = Csrf::field();
        $err = View::errors($errors);
        $left = count((array) ($user['recovery'] ?? []));
        $smsBtn = $smsPossible
            ? '<form method="post" style="display:inline">' . $csrf
              . '<input type="hidden" name="action" value="sms">'
              . '<button class="btn sec sm" type="submit">Code per SMS senden</button></form>'
            : '';
        $body = <<<HTML
<h1>Zweiter Faktor</h1>
<p class="lead">Bitte den aktuellen sechsstelligen Code aus Ihrer Authenticator-App eingeben.
Alternativ funktioniert ein Wiederherstellungscode.</p>
{$err}
<div class="card" style="max-width:26rem">
<form method="post">
  {$csrf}
  <input type="hidden" name="action" value="verify">
  <label for="c">Code</label>
  <input id="c" name="code" class="otp" type="text" inputmode="numeric" autocomplete="one-time-code"
         pattern="[0-9A-Za-z\- ]{6,20}" required autofocus>
  <button class="btn" type="submit">Bestätigen</button>
</form>
<p class="small muted" style="margin-top:1rem">Verbleibende Wiederherstellungscodes: {$left}</p>
{$smsBtn}
</div>
HTML;
        Response::html(View::page('Zweiter Faktor', $body));
    }

    public static function logout(): void
    {
        $user = Session::user();
        if ($user !== null) {
            Audit::log('logout', [], (string) $user['id']);
        }
        Session::destroy();
        Session::flash('ok', 'Sie sind abgemeldet.');
        Response::redirect(Router::url('/'));
    }

    // -- Übersicht --------------------------------------------------------

    public static function dashboard(): void
    {
        $user = self::requireUser();
        $base = Router::basePath();
        $clients = Clients::forOwner((string) $user['id']);
        $active = count(array_filter($clients, static fn(array $c): bool => ($c['status'] ?? '') === 'active'));
        $left = count((array) ($user['recovery'] ?? []));
        $recTag = $left <= 2 ? 'off' : ($left <= 5 ? 'pend' : 'ok');
        $phone = !empty($user['phone_ok'])
            ? Util::h(substr((string) $user['phone'], 0, 4) . '…' . substr((string) $user['phone'], -3))
            : 'nicht hinterlegt';
        $last = Util::ts($user['last_login'] ?? null);
        $name = Util::h((string) $user['display']);
        $admin = !empty($user['admin'])
            ? '<p class="small"><span class="tag ok">Administrator</span></p>' : '';
        $total = count($clients);

        $body = <<<HTML
<h1>Hallo {$name}</h1>
<p class="lead">Letzte Anmeldung: {$last}</p>
{$admin}
<div class="grid three">
  <div class="card tight">
    <h3 style="margin-top:0">Zwei-Faktor</h3>
    <p><span class="tag ok">TOTP aktiv</span></p>
    <p class="small muted">Zeitbasierte Codes, Replay-Schutz aktiv.</p>
    <a class="btn sec sm" href="{$base}/usr/security">Verwalten</a>
  </div>
  <div class="card tight">
    <h3 style="margin-top:0">Wiederherstellung</h3>
    <p><span class="tag {$recTag}">{$left} Codes übrig</span></p>
    <p class="small muted">Einmalcodes für den Fall, dass das Telefon fehlt.</p>
    <a class="btn sec sm" href="{$base}/usr/mfa/recovery">Neu erzeugen</a>
  </div>
  <div class="card tight">
    <h3 style="margin-top:0">SMS-Faktor</h3>
    <p class="small">Rufnummer: {$phone}</p>
    <p class="small muted">Optionaler Ersatzfaktor über Odoo.</p>
    <a class="btn sec sm" href="{$base}/usr/security">Einrichten</a>
  </div>
</div>
<div class="card">
  <div class="split">
    <h2 style="margin:0">API-Anbindungen</h2>
    <a class="btn sec sm" href="{$base}/usr/api">Alle ansehen</a>
  </div>
  <p class="lead" style="margin-top:.6rem">{$total} Anbindung(en), davon {$active} aktiv.
  Hier verbinden Sie eigene Server mit diesem Anmeldedienst.</p>
</div>
HTML;
        Response::html(View::page('Konto', $body, $user, 'home'));
    }

    // -- TOTP einrichten ---------------------------------------------------

    public static function mfaSetup(): void
    {
        $user = self::requireUser(true);
        $errors = [];

        if (Router::isPost()) {
            Csrf::require();
            $pending = Session::get('totp_pending');
            if (!is_string($pending) || $pending === '') {
                $errors[] = 'Die Einrichtung ist abgelaufen. Bitte Seite neu laden.';
            } else {
                $secret = Util::b64uDecode($pending);
                if (!RateLimit::hit('totpsetup:' . $user['id'], 10, 600)) {
                    Response::error(429, 'Zu viele Versuche.');
                }
                $counter = Totp::verify($secret, self::post('code'), 0);
                if ($counter === false) {
                    $errors[] = 'Code stimmt nicht. Uhrzeit des Geräts prüfen und erneut versuchen.';
                } else {
                    $user['totp_secret'] = $pending;
                    $user['totp_enabled'] = true;
                    $user['totp_counter'] = $counter;
                    Users::save($user);
                    Session::forget('totp_pending');
                    Session::set('mfa', true);
                    Audit::log('mfa.enabled', [], (string) $user['id']);
                    $codes = Users::newRecoveryCodes($user);
                    Session::set('show_recovery', $codes);
                    Session::flash('ok', 'Zwei-Faktor-Authentisierung ist aktiv.');
                    Response::redirect(Router::url('/usr/mfa/recovery'));
                }
            }
        }

        if (!empty($user['totp_enabled'])) {
            Session::flash('info', 'TOTP ist bereits aktiv.');
            Response::redirect(Router::url('/usr/security'));
        }

        $pending = Session::get('totp_pending');
        if (!is_string($pending) || $pending === '') {
            $pending = Util::b64u(Totp::generateSecret());
            Session::set('totp_pending', $pending);
        }
        $secret = Util::b64uDecode($pending);
        $uri = Totp::uri($secret, (string) $user['username'], (string) cfg('SSO_ISSUER_NAME', APP_NAME));
        $qr = QrCode::svg($uri, 5, 3, 'QR-Code zur Einrichtung');
        $b32 = Util::group(Util::base32Encode($secret), 4);
        $csrf = Csrf::field();
        $err = View::errors($errors);
        $digits = TOTP_DIGITS;
        $period = TOTP_PERIOD;
        $algo = strtoupper(TOTP_ALGO);

        $body = <<<HTML
<h1>Zwei-Faktor einrichten</h1>
<p class="lead">Ohne zweiten Faktor ist dieses Konto nicht nutzbar. Der QR-Code funktioniert
mit jeder gängigen Authenticator-App.</p>
{$err}
<div class="grid two">
  <div class="card">
    <div class="qr">{$qr}</div>
    <p class="small muted" style="margin-top:.8rem">Kein Scanner zur Hand? Geheimnis manuell eingeben:</p>
    <p><code class="break">{$b32}</code></p>
    <p class="small muted">Typ TOTP · {$algo} · {$digits} Stellen · {$period} Sekunden</p>
  </div>
  <div class="card">
    <h2 style="margin-top:0">Bestätigen</h2>
    <p>Geben Sie den aktuell angezeigten Code ein, um die Einrichtung abzuschließen.</p>
    <form method="post">
      {$csrf}
      <label for="c">Code aus der App</label>
      <input id="c" name="code" class="otp" type="text" inputmode="numeric"
             autocomplete="one-time-code" pattern="[0-9]{6}" required autofocus>
      <button class="btn" type="submit">Aktivieren</button>
    </form>
  </div>
</div>
HTML;
        Response::html(View::page('Zwei-Faktor einrichten', $body, $user, 'security'));
    }

    // -- Wiederherstellungscodes -------------------------------------------

    public static function recoveryCodes(): void
    {
        $user = self::requireUser(true);
        $codes = Session::get('show_recovery');
        if (Router::isPost()) {
            Csrf::require();
            $codes = Users::newRecoveryCodes($user);
            Session::set('show_recovery', $codes);
            Response::redirect(Router::url('/usr/mfa/recovery'));
        }
        Session::forget('show_recovery');

        $csrf = Csrf::field();
        $base = Router::basePath();
        if (is_array($codes) && $codes) {
            $items = '';
            foreach ($codes as $c) {
                $items .= '<div>' . Util::h((string) $c) . '</div>';
            }
            $list = '<div class="codes">' . $items . '</div>';
            $note = '<div class="alert warn">Diese Codes werden <strong>nur jetzt</strong> angezeigt. '
                . 'Serverseitig liegt nur ein HMAC davon. Bitte ausdrucken oder in einem '
                . 'Passwortmanager ablegen.</div>';
        } else {
            $left = count((array) ($user['recovery'] ?? []));
            $list = '<p class="lead">Sie haben noch <strong>' . $left . '</strong> unbenutzte Codes.</p>';
            $note = '';
        }
        $body = <<<HTML
<h1>Wiederherstellungscodes</h1>
{$note}
<div class="card">
{$list}
<form method="post" style="margin-top:1.2rem">
  {$csrf}
  <button class="btn sec" type="submit">Neuen Satz erzeugen (alte werden ungültig)</button>
</form>
</div>
<p><a href="{$base}/usr/">Zurück zur Übersicht</a></p>
HTML;
        Response::html(View::page('Wiederherstellungscodes', $body, $user, 'security'));
    }

    // -- Sicherheitseinstellungen ------------------------------------------

    public static function security(): void
    {
        $user = self::requireUser();
        $errors = [];

        if (Router::isPost()) {
            Csrf::require();
            $action = self::post('action');

            if ($action === 'password') {
                $cur = (string) ($_POST['current'] ?? '');
                $new = (string) ($_POST['new'] ?? '');
                $new2 = (string) ($_POST['new2'] ?? '');
                if (!Users::verifyPassword($user, $cur)) {
                    RateLimit::registerFailure('pwchange:' . $user['id']);
                    $errors[] = 'Das aktuelle Passwort ist falsch.';
                } elseif ($new !== $new2) {
                    $errors[] = 'Die neuen Passwörter stimmen nicht überein.';
                } else {
                    $problems = Users::setPassword($user, $new);
                    if ($problems) {
                        $errors = array_merge($errors, $problems);
                    } else {
                        self::revokeOtherSessions((string) $user['id']);
                        Session::flash('ok', 'Passwort geändert. Andere Sitzungen wurden beendet.');
                        Response::redirect(Router::url('/usr/security'));
                    }
                }
            } elseif ($action === 'phone') {
                $phone = Util::normalizePhone(self::post('phone'));
                if ($phone === null) {
                    $errors[] = 'Bitte die Rufnummer im Format +49170… angeben.';
                } elseif (!OdooSms::isReady()) {
                    $errors[] = 'SMS ist auf dieser Installation nicht konfiguriert.';
                } else {
                    $user['phone'] = $phone;
                    $user['phone_ok'] = false;
                    $user['sms_enabled'] = false;
                    Users::save($user);
                    $res = SmsOtp::issue($user, 'verify_phone', $phone);
                    Session::flash($res['ok'] ? 'ok' : 'err',
                        $res['ok'] ? 'Bestätigungscode verschickt.' : $res['error']);
                    Response::redirect(Router::url('/usr/security'));
                }
            } elseif ($action === 'phone_verify') {
                if (SmsOtp::verify($user, 'verify_phone', self::post('code'))) {
                    $user['phone_ok'] = true;
                    $user['sms_enabled'] = true;
                    Users::save($user);
                    Audit::log('user.phone_verified', [], (string) $user['id']);
                    Session::flash('ok', 'Rufnummer bestätigt. SMS steht als Ersatzfaktor bereit.');
                    Response::redirect(Router::url('/usr/security'));
                }
                $errors[] = 'Der SMS-Code stimmt nicht oder ist abgelaufen.';
            } elseif ($action === 'sms_toggle') {
                $user['sms_enabled'] = !empty($user['phone_ok']) && self::post('enabled') === '1';
                Users::save($user);
                Session::flash('ok', 'Einstellung gespeichert.');
                Response::redirect(Router::url('/usr/security'));
            } elseif ($action === 'totp_reset') {
                // Neues Geheimnis nur gegen Passwort UND aktuellen Code.
                if (!Users::verifyPassword($user, (string) ($_POST['current'] ?? ''))) {
                    $errors[] = 'Das Passwort ist falsch.';
                } elseif (!Users::verifyTotp($user, self::post('code'))) {
                    $errors[] = 'Der aktuelle Code stimmt nicht.';
                } else {
                    $user['totp_enabled'] = false;
                    $user['totp_secret'] = null;
                    $user['totp_counter'] = 0;
                    Users::save($user);
                    Session::forget('totp_pending');
                    Audit::log('mfa.reset', [], (string) $user['id']);
                    Session::flash('warn', 'TOTP wurde zurückgesetzt. Bitte neu einrichten.');
                    Response::redirect(Router::url('/usr/mfa/setup'));
                }
            } elseif ($action === 'sessions') {
                self::revokeOtherSessions((string) $user['id']);
                Session::flash('ok', 'Alle anderen Sitzungen wurden beendet.');
                Response::redirect(Router::url('/usr/security'));
            }
        }

        $csrf = Csrf::field();
        $err = View::errors($errors);
        $base = Router::basePath();
        $smsReady = OdooSms::isReady();
        $phoneVal = Util::h((string) ($user['phone'] ?? ''));
        $phoneState = !empty($user['phone_ok'])
            ? '<span class="tag ok">bestätigt</span>'
            : (($user['phone'] ?? '') !== '' ? '<span class="tag pend">unbestätigt</span>'
                : '<span class="tag off">nicht gesetzt</span>');
        $smsChecked = !empty($user['sms_enabled']) ? ' checked' : '';
        $smsBlock = $smsReady ? <<<HTML
<form method="post">
  {$csrf}
  <input type="hidden" name="action" value="phone">
  <label for="ph">Rufnummer (international, z. B. +4917012345678)</label>
  <input id="ph" name="phone" type="tel" value="{$phoneVal}" autocomplete="tel">
  <button class="btn sec" type="submit">Speichern und Code senden</button>
</form>
<form method="post" style="margin-top:1rem">
  {$csrf}
  <input type="hidden" name="action" value="phone_verify">
  <label for="pc">Bestätigungscode aus der SMS</label>
  <input id="pc" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}">
  <button class="btn sec" type="submit">Rufnummer bestätigen</button>
</form>
<form method="post" style="margin-top:1rem">
  {$csrf}
  <input type="hidden" name="action" value="sms_toggle">
  <label style="display:flex;gap:.5rem;align-items:center;color:var(--fg)">
    <input type="checkbox" name="enabled" value="1"{$smsChecked}> SMS als Ersatzfaktor zulassen
  </label>
  <button class="btn sec sm" type="submit" style="margin-top:.8rem">Übernehmen</button>
</form>
HTML
            : '<p class="muted">Der Administrator hat noch keine Odoo-Verbindung hinterlegt, '
              . 'daher steht SMS derzeit nicht zur Verfügung.</p>';

        $sessions = self::sessionRows((string) $user['id']);

        $body = <<<HTML
<h1>Sicherheit</h1>
{$err}
<div class="grid two">
  <div class="card">
    <h2 style="margin-top:0">Passwort ändern</h2>
    <form method="post">
      {$csrf}
      <input type="hidden" name="action" value="password">
      <label for="cur">Aktuelles Passwort</label>
      <input id="cur" name="current" type="password" required autocomplete="current-password">
      <label for="n1">Neues Passwort</label>
      <input id="n1" name="new" type="password" required autocomplete="new-password">
      <label for="n2">Wiederholen</label>
      <input id="n2" name="new2" type="password" required autocomplete="new-password">
      <button class="btn" type="submit">Ändern</button>
    </form>
  </div>
  <div class="card">
    <h2 style="margin-top:0">TOTP zurücksetzen</h2>
    <p class="small muted">Nur nötig, wenn Sie das Gerät wechseln und die alte App nicht
    mehr übertragen können. Danach richten Sie den Faktor neu ein.</p>
    <form method="post">
      {$csrf}
      <input type="hidden" name="action" value="totp_reset">
      <label for="tp">Passwort</label>
      <input id="tp" name="current" type="password" required autocomplete="current-password">
      <label for="tc">Aktueller Code</label>
      <input id="tc" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" required>
      <button class="btn danger" type="submit">Zurücksetzen</button>
    </form>
    <p class="small" style="margin-top:1rem"><a href="{$base}/usr/mfa/recovery">Wiederherstellungscodes verwalten</a></p>
  </div>
</div>

<div class="card">
  <div class="split"><h2 style="margin:0">SMS-Ersatzfaktor</h2><div>{$phoneState}</div></div>
  <p class="small muted">Die Rufnummer wird verschlüsselt gespeichert und nur für Einmalcodes
  verwendet. Der Versand läuft über die Odoo-Instanz dieser Installation.</p>
  {$smsBlock}
</div>

<div class="card">
  <div class="split">
    <h2 style="margin:0">Aktive Sitzungen</h2>
    <form method="post">{$csrf}<input type="hidden" name="action" value="sessions">
      <button class="btn sec sm" type="submit">Andere beenden</button></form>
  </div>
  <table>
    <tr><th>Gestartet</th><th>Zuletzt aktiv</th><th>IP</th><th></th></tr>
    {$sessions}
  </table>
</div>
HTML;
        Response::html(View::page('Sicherheit', $body, $user, 'security'));
    }

    private static function sessionRows(string $uid): string
    {
        $current = Session::id();
        $currentTag = $current !== null ? Vault::tag('session', $current) : '';
        $rows = '';
        foreach (Storage::all('sessions') as $key => $rec) {
            if (($rec['uid'] ?? '') !== $uid) {
                continue;
            }
            $mine = $key === $currentTag ? '<span class="tag ok">diese Sitzung</span>' : '';
            $rows .= '<tr><td>' . Util::h(Util::ts($rec['created'] ?? null)) . '</td><td>'
                . Util::h(Util::ts($rec['last'] ?? null)) . '</td><td>'
                . Util::h((string) ($rec['ip'] ?? '?')) . '</td><td>' . $mine . '</td></tr>';
        }
        return $rows !== '' ? $rows : '<tr><td colspan="4" class="muted">Keine Einträge.</td></tr>';
    }

    private static function revokeOtherSessions(string $uid): void
    {
        $current = Session::id();
        $keep = $current !== null ? Vault::tag('session', $current) : '';
        foreach (Storage::all('sessions') as $key => $rec) {
            if (($rec['uid'] ?? '') === $uid && $key !== $keep) {
                Storage::delete('sessions', $key);
            }
        }
        Audit::log('sessions.revoked', [], $uid);
    }

    // -- API-Anbindungen ---------------------------------------------------

    public static function apiClients(): void
    {
        $user = self::requireUser();
        $errors = [];

        if (Router::isPost()) {
            Csrf::require();
            $name = self::post('name');
            $mode = self::post('mode') === 'manual' ? 'manual' : 'paired';
            $url = self::post('connector_url');
            if ($mode === 'paired') {
                $problem = self::validateConnectorUrl($url);
                if ($problem !== null) {
                    $errors[] = $problem;
                }
            } else {
                $url = '';
            }
            if (count(Clients::forOwner((string) $user['id'])) >= 25) {
                $errors[] = 'Maximal 25 Anbindungen pro Konto.';
            }
            if (!$errors) {
                $client = Clients::create($user, $name, $mode, $url);
                Session::flash('ok', 'Anbindung angelegt.');
                Response::redirect(Router::url('/usr/api/' . $client['id']));
            }
        }

        $base = Router::basePath();
        $csrf = Csrf::field();
        $err = View::errors($errors);
        $rows = '';
        foreach (Clients::forOwner((string) $user['id']) as $c) {
            $tag = match ($c['status'] ?? '') {
                'active'   => '<span class="tag ok">aktiv</span>',
                'pending'  => '<span class="tag pend">wartet auf Pairing</span>',
                default    => '<span class="tag off">deaktiviert</span>',
            };
            $rows .= '<tr><td><a href="' . $base . '/usr/api/' . Util::h((string) $c['id']) . '">'
                . Util::h((string) $c['name']) . '</a><br><code class="small break">'
                . Util::h((string) $c['id']) . '</code></td>'
                . '<td>' . ($c['mode'] === 'manual' ? 'manuelle Schlüssel' : 'gekoppelt') . '</td>'
                . '<td>' . $tag . '</td>'
                . '<td class="small muted">' . Util::h(Util::ts($c['last_used'] ?? null)) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">Noch keine Anbindung angelegt.</td></tr>';
        }

        $body = <<<HTML
<h1>API-Anbindungen</h1>
<p class="lead">Jede Anbindung steht für einen externen Server, der Anmeldungen über
diesen Dienst prüfen darf. Schlüssel gibt es nur hier im Konto.</p>
{$err}
<div class="card">
  <table>
    <tr><th>Name</th><th>Art</th><th>Status</th><th>Zuletzt benutzt</th></tr>
    {$rows}
  </table>
</div>
<div class="card">
  <h2 style="margin-top:0">Neue Anbindung</h2>
  <form method="post">
    {$csrf}
    <label for="n">Name</label>
    <input id="n" name="name" type="text" placeholder="Shop, Forum, DEFCON-Badge …" required>
    <label for="m">Art der Anbindung</label>
    <select id="m" name="mode">
      <option value="paired">Automatisch koppeln (api.php auf dem eigenen Server)</option>
      <option value="manual">Manuelle Schlüssel (Signatur selbst implementieren)</option>
    </select>
    <label for="cu">Connector-URL (nur beim automatischen Koppeln)</label>
    <input id="cu" name="connector_url" type="url" placeholder="https://example.com/api.php">
    <button class="btn" type="submit">Anlegen</button>
  </form>
</div>
HTML;
        Response::html(View::page('API-Anbindungen', $body, $user, 'api'));
    }

    /** Prüft eine vom Nutzer eingetragene Connector-URL. */
    private static function validateConnectorUrl(string $url): ?string
    {
        if ($url === '') {
            return 'Bitte die URL der api.php auf Ihrem Server eintragen.';
        }
        if (strlen($url) > 512) {
            return 'URL ist zu lang.';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return 'URL ist unvollständig.';
        }
        $insecureOk = (bool) cfg('SSO_ALLOW_INSECURE_PAIRING', false);
        if (strtolower($parts['scheme']) !== 'https' && !$insecureOk) {
            return 'Nur https ist erlaubt.';
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return 'Zugangsdaten in der URL sind nicht erlaubt.';
        }
        if (!$insecureOk && Net::resolvePublic((string) $parts['host']) === []) {
            return 'Der Host ist nicht öffentlich erreichbar (interne Adressen sind gesperrt).';
        }
        return null;
    }

    public static function apiClient(string $id): void
    {
        $user = self::requireUser();
        $client = Clients::get($id);
        if ($client === null || ($client['owner'] ?? '') !== $user['id']) {
            Response::error(404, 'Anbindung nicht gefunden.');
        }
        $errors = [];
        $reveal = null;

        if (Router::isPost()) {
            Csrf::require();
            $action = self::post('action');
            if ($action === 'delete') {
                Clients::delete($id);
                Audit::log('client.deleted', ['client' => $id], (string) $user['id']);
                Session::flash('ok', 'Anbindung gelöscht.');
                Response::redirect(Router::url('/usr/api'));
            } elseif ($action === 'save') {
                $url = self::post('connector_url');
                if ($client['mode'] === 'paired' && $url !== (string) $client['connector_url']) {
                    $problem = self::validateConnectorUrl($url);
                    if ($problem !== null) {
                        $errors[] = $problem;
                    } else {
                        $client['connector_url'] = $url;
                        $client['status'] = 'pending';
                        $client['k_c2s'] = null;
                        $client['k_s2c'] = null;
                    }
                }
                $uris = [];
                foreach (preg_split('/\R/', (string) ($_POST['redirect_uris'] ?? '')) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $p = parse_url($line);
                    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])
                        || str_contains($line, '#')) {
                        $errors[] = 'Ungültige Rückleit-URL: ' . mb_substr($line, 0, 80);
                        continue;
                    }
                    if (strtolower($p['scheme']) !== 'https'
                        && !(bool) cfg('SSO_ALLOW_INSECURE_PAIRING', false)) {
                        $errors[] = 'Rückleit-URLs müssen https verwenden: ' . mb_substr($line, 0, 80);
                        continue;
                    }
                    $uris[] = $line;
                }
                $client['redirect_uris'] = array_values(array_unique(array_slice($uris, 0, 10)));
                $client['allow_password_api'] = self::post('allow_password_api') === '1';
                $client['name'] = mb_substr(self::post('name') ?: (string) $client['name'], 0, 64);
                if (!$errors) {
                    Clients::save($client);
                    Session::flash('ok', 'Gespeichert.');
                    Response::redirect(Router::url('/usr/api/' . $id));
                }
            } elseif ($action === 'pair') {
                if (!RateLimit::hit('pair:' . $id, 10, 600)) {
                    Response::error(429, 'Zu viele Pairing-Versuche.');
                }
                $res = Pairing::run($client);
                Session::flash($res['ok'] ? 'ok' : 'err',
                    $res['ok'] ? 'Pairing erfolgreich. Die Anbindung ist aktiv.'
                               : 'Pairing fehlgeschlagen: ' . $res['error']);
                Response::redirect(Router::url('/usr/api/' . $id));
            } elseif ($action === 'rebootstrap') {
                $client = Clients::refreshBootstrap($client);
                Audit::log('client.rebootstrap', ['client' => $id], (string) $user['id']);
                Session::flash('warn', 'Neues Bootstrap-Geheimnis erzeugt. Bitte api.php erneut '
                    . 'herunterladen und auf dem Server ersetzen.');
                Response::redirect(Router::url('/usr/api/' . $id));
            } elseif ($action === 'reveal' && $client['mode'] === 'manual') {
                if (!Users::verifyPassword($user, (string) ($_POST['current'] ?? ''))) {
                    $errors[] = 'Passwort falsch.';
                } else {
                    $reveal = (string) $client['secret'];
                    Audit::log('client.secret_revealed', ['client' => $id], (string) $user['id']);
                }
            }
        }

        $base = Router::basePath();
        $csrf = Csrf::field();
        $err = View::errors($errors);
        $name = Util::h((string) $client['name']);
        $cid = Util::h((string) $client['id']);
        $curl = Util::h((string) $client['connector_url']);
        $uris = Util::h(implode("\n", (array) $client['redirect_uris']));
        $statusTag = match ($client['status'] ?? '') {
            'active'  => '<span class="tag ok">aktiv</span>',
            'pending' => '<span class="tag pend">wartet auf Pairing</span>',
            default   => '<span class="tag off">deaktiviert</span>',
        };
        $pwChecked = !empty($client['allow_password_api']) ? ' checked' : '';
        $issuer = Util::h(Util::baseUrl());

        if ($client['mode'] === 'paired') {
            $hasBootstrap = is_array($client['bootstrap'] ?? null)
                && (int) $client['bootstrap']['exp'] > time();
            $bootstrapNote = $hasBootstrap
                ? '<p class="small muted">Bootstrap-Geheimnis gültig bis '
                    . Util::h(Util::ts((int) $client['bootstrap']['exp'])) . '.</p>'
                : '<p class="small muted">Kein gültiges Bootstrap-Geheimnis. Für ein (erneutes) '
                    . 'Pairing zuerst ein neues erzeugen.</p>';
            $dl = $hasBootstrap
                ? '<a class="btn" href="' . $base . '/usr/api/' . $cid . '/connector">api.php herunterladen</a>'
                : '';
            $pairBtn = $hasBootstrap && $curl !== ''
                ? '<form method="post" style="display:inline">' . $csrf
                    . '<input type="hidden" name="action" value="pair">'
                    . '<button class="btn" type="submit">Initialisieren</button></form>'
                : '';
            $keyBlock = <<<HTML
<div class="card">
  <h2 style="margin-top:0">Kopplung</h2>
  {$bootstrapNote}
  <ol class="steps">
    <li><strong>api.php herunterladen</strong> und im Webroot Ihres Servers genau unter der
        oben eingetragenen Connector-URL ablegen. Das Verzeichnis muss schreibbar sein.</li>
    <li><strong>Initialisieren</strong> drücken. Diese Seite ruft Ihre api.php auf, beide
        Seiten tauschen X25519-Public-Keys aus und bestätigen die abgeleiteten Schlüssel.</li>
    <li>Danach steht auf Ihrem Server <code>new SsoClient()</code> zur Verfügung.</li>
  </ol>
  <div class="row">{$dl}{$pairBtn}
    <form method="post" style="display:inline">{$csrf}
      <input type="hidden" name="action" value="rebootstrap">
      <button class="btn sec" type="submit">Neues Bootstrap-Geheimnis</button></form>
  </div>
</div>
HTML;
        } else {
            $secretBox = $reveal !== null
                ? '<div class="alert warn">Client-Secret (wird nur jetzt angezeigt):<br>'
                    . '<code class="break">' . Util::h($reveal) . '</code></div>'
                : '';
            $keyBlock = <<<HTML
<div class="card">
  <h2 style="margin-top:0">Manuelle Schlüssel</h2>
  <p>Client-ID: <code class="break">{$cid}</code></p>
  {$secretBox}
  <form method="post">
    {$csrf}
    <input type="hidden" name="action" value="reveal">
    <label for="rp">Zum Anzeigen des Secrets Ihr Passwort eingeben</label>
    <input id="rp" name="current" type="password" autocomplete="current-password" required>
    <button class="btn sec" type="submit">Secret anzeigen</button>
  </form>
  <p class="small muted" style="margin-top:1rem">Signaturschlüssel für Anfragen an diesen
  Dienst: <code>HKDF-SHA256(secret, info="sso-manual|c2s|CLIENT_ID")</code>.
  Details in der <a href="{$base}/api">API-Dokumentation</a>.</p>
</div>
HTML;
        }

        $body = <<<HTML
<div class="split"><h1>{$name}</h1><div>{$statusTag}</div></div>
<p class="small muted">Client-ID <code class="break">{$cid}</code> · Aussteller <code>{$issuer}</code></p>
{$err}
{$keyBlock}
<div class="card">
  <h2 style="margin-top:0">Einstellungen</h2>
  <form method="post">
    {$csrf}
    <input type="hidden" name="action" value="save">
    <label for="nm">Name</label>
    <input id="nm" name="name" type="text" value="{$name}">
    <label for="cu">Connector-URL</label>
    <input id="cu" name="connector_url" type="url" value="{$curl}"
           placeholder="https://example.com/api.php">
    <label for="ru">Erlaubte Rückleit-URLs (eine pro Zeile, exakter Vergleich)</label>
    <textarea id="ru" name="redirect_uris" rows="4"
              placeholder="https://example.com/login/callback">{$uris}</textarea>
    <label style="display:flex;gap:.55rem;align-items:flex-start;color:var(--fg);margin-top:1rem">
      <input type="checkbox" name="allow_password_api" value="1"{$pwChecked}>
      <span>Passwortprüfung über die API erlauben (<code>auth/start</code>).
      <span class="muted small">Nur aktivieren, wenn die eigene Anwendung ein eigenes
      Anmeldeformular zeigt. Sicherer ist die Weiterleitung, bei der das Passwort diesen
      Server nie verlässt.</span></span>
    </label>
    <button class="btn" type="submit">Speichern</button>
  </form>
</div>
<div class="card">
  <h2 style="margin-top:0">Löschen</h2>
  <p class="small muted">Entfernt die Anbindung und alle Schlüssel. Anfragen dieses Clients
  werden danach abgelehnt.</p>
  <form method="post">{$csrf}<input type="hidden" name="action" value="delete">
    <button class="btn danger" type="submit">Anbindung löschen</button></form>
</div>
<p><a href="{$base}/usr/api">Zurück zur Übersicht</a></p>
HTML;
        Response::html(View::page($client['name'] . ' – Anbindung', $body, $user, 'api'));
    }

    public static function connectorDownload(string $id): void
    {
        $user = self::requireUser();
        $client = Clients::get($id);
        if ($client === null || ($client['owner'] ?? '') !== $user['id']) {
            Response::error(404, 'Anbindung nicht gefunden.');
        }
        if (($client['mode'] ?? '') !== 'paired') {
            Response::error(400, 'Diese Anbindung nutzt manuelle Schlüssel.');
        }
        if (!is_array($client['bootstrap'] ?? null) || (int) $client['bootstrap']['exp'] < time()) {
            Response::error(400, 'Kein gültiges Bootstrap-Geheimnis. Bitte neu erzeugen.');
        }
        Audit::log('client.connector_downloaded', ['client' => $id], (string) $user['id']);
        Response::text(ConnectorSource::build($client), 200, [
            'Content-Type: application/octet-stream',
            'Content-Disposition: attachment; filename="' . ConnectorSource::fileName($client) . '"',
        ]);
    }

    // -- SSO: Autorisierung im Browser -------------------------------------

    public static function authorize(): void
    {
        $clientId = self::query('client_id');
        $redirect = self::query('redirect_uri');
        $state = self::query('state');
        $challenge = self::query('code_challenge');
        $method = self::query('code_challenge_method', 'S256');
        $scope = self::query('scope', 'profile');

        $client = Clients::get($clientId);
        if ($client === null || ($client['status'] ?? '') !== 'active') {
            Response::error(400, 'Unbekannte oder inaktive Anwendung.');
        }
        // Die Rückleit-URL muss exakt eingetragen sein - sonst wird nie
        // weitergeleitet (offener Redirect wäre hier ein Kontodiebstahl).
        if (!in_array($redirect, (array) $client['redirect_uris'], true)) {
            Response::error(400, 'Diese Rückleit-URL ist für die Anwendung nicht hinterlegt.');
        }
        if ($method !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            Response::error(400, 'PKCE mit S256 ist Pflicht (code_challenge fehlt oder ist falsch).');
        }
        if (strlen($state) > 512) {
            Response::error(400, 'state ist zu lang.');
        }

        $user = self::requireUser();

        if (Router::isPost()) {
            Csrf::require();
            if (self::post('decision') !== 'allow') {
                Response::redirect($redirect . (str_contains($redirect, '?') ? '&' : '?')
                    . http_build_query(['error' => 'access_denied', 'state' => $state]));
            }
            if (self::post('remember') === '1') {
                $approved = (array) ($user['approved'] ?? []);
                $approved[$clientId] = time();
                $user['approved'] = $approved;
                Users::save($user);
            }
            $code = Challenges::create('sso_code', [
                'client'    => $clientId,
                'uid'       => (string) $user['id'],
                'redirect'  => $redirect,
                'challenge' => $challenge,
                'scope'     => mb_substr($scope, 0, 128),
                'amr'       => (array) Session::get('amr', ['pwd', 'otp']),
                'auth_time' => (int) Session::get('login_at', time()),
            ], SSO_CODE_TTL);
            Audit::log('sso.code_issued', ['client' => $clientId], (string) $user['id']);
            Response::redirect($redirect . (str_contains($redirect, '?') ? '&' : '?')
                . http_build_query(['code' => $code, 'state' => $state]));
        }

        // Bereits erteilte Zustimmung -> ohne Rückfrage weiter.
        if (isset(((array) ($user['approved'] ?? []))[$clientId])) {
            $code = Challenges::create('sso_code', [
                'client'    => $clientId,
                'uid'       => (string) $user['id'],
                'redirect'  => $redirect,
                'challenge' => $challenge,
                'scope'     => mb_substr($scope, 0, 128),
                'amr'       => (array) Session::get('amr', ['pwd', 'otp']),
                'auth_time' => (int) Session::get('login_at', time()),
            ], SSO_CODE_TTL);
            Audit::log('sso.code_issued', ['client' => $clientId, 'silent' => true], (string) $user['id']);
            Response::redirect($redirect . (str_contains($redirect, '?') ? '&' : '?')
                . http_build_query(['code' => $code, 'state' => $state]));
        }

        $csrf = Csrf::field();
        $appName = Util::h((string) $client['name']);
        $host = Util::h((string) (parse_url($redirect, PHP_URL_HOST) ?: '?'));
        $uname = Util::h((string) $user['username']);
        $body = <<<HTML
<h1>Anmeldung bei „{$appName}“</h1>
<div class="card" style="max-width:34rem">
  <p class="lead">Sie sind als <strong>{$uname}</strong> angemeldet. Die Anwendung auf
  <code>{$host}</code> möchte folgende Angaben erhalten:</p>
  <ul style="margin:.4rem 0 1rem 1.05rem;line-height:1.9">
    <li>Ihre Kennung und Ihren Nutzernamen</li>
    <li>Anzeigename und E-Mail-Adresse</li>
    <li>Die Bestätigung, dass ein zweiter Faktor geprüft wurde</li>
  </ul>
  <p class="small muted">Ihr Passwort und Ihr TOTP-Geheimnis werden <strong>nicht</strong>
  weitergegeben.</p>
  <form method="post">
    {$csrf}
    <label style="display:flex;gap:.5rem;align-items:center;color:var(--fg)">
      <input type="checkbox" name="remember" value="1"> Künftig nicht mehr fragen
    </label>
    <div class="row">
      <button class="btn" name="decision" value="allow" type="submit">Erlauben</button>
      <button class="btn sec" name="decision" value="deny" type="submit">Ablehnen</button>
    </div>
  </form>
</div>
HTML;
        Response::html(View::page('Anmeldung erlauben', $body, $user));
    }

    // -- Administration ----------------------------------------------------

    public static function admin(): void
    {
        $user = self::requireUser();
        if (empty($user['admin'])) {
            Response::error(403, 'Dieser Bereich ist Administratoren vorbehalten.');
        }
        $errors = [];
        $testResult = null;

        if (Router::isPost()) {
            Csrf::require();
            $action = self::post('action');
            if ($action === 'odoo') {
                OdooSms::saveConfig([
                    'enabled'       => self::post('enabled') === '1',
                    'url'           => rtrim(self::post('url'), '/'),
                    'db'            => self::post('db'),
                    'user'          => self::post('user'),
                    'api_key'       => self::post('api_key') !== ''
                        ? self::post('api_key') : (string) OdooSms::config()['api_key'],
                    'mode'          => self::post('mode') === 'mailing' ? 'mailing' : 'sms',
                    'mailing_list'  => self::post('mailing_list'),
                    'allow_private' => self::post('allow_private') === '1',
                ]);
                Session::flash('ok', 'Odoo-Einstellungen gespeichert.');
                Response::redirect(Router::url('/usr/admin'));
            } elseif ($action === 'odoo_test') {
                $testResult = OdooSms::test();
            } elseif ($action === 'audit_verify') {
                $res = Audit::verify(date('Y-m'));
                Session::flash($res['ok'] ? 'ok' : 'err', $res['ok']
                    ? 'Audit-Kette ist unversehrt (' . $res['lines'] . ' Einträge).'
                    : 'Audit-Kette fehlerhaft: ' . $res['error']);
                Response::redirect(Router::url('/usr/admin'));
            }
        }

        $c = OdooSms::config();
        $csrf = Csrf::field();
        $err = View::errors($errors);
        $enabled = $c['enabled'] ? ' checked' : '';
        $priv = $c['allow_private'] ? ' checked' : '';
        $modeSms = $c['mode'] === 'sms' ? ' selected' : '';
        $modeMail = $c['mode'] === 'mailing' ? ' selected' : '';
        $keySet = $c['api_key'] !== '' ? 'gesetzt (leer lassen zum Beibehalten)' : 'noch nicht gesetzt';
        $test = $testResult !== null
            ? '<div class="alert ' . ($testResult['ok'] ? 'ok' : 'err') . '">'
              . Util::h((string) $testResult['message']) . '</div>'
            : '';

        $userRows = '';
        foreach (Storage::all('users') as $u) {
            $userRows .= '<tr><td>' . Util::h((string) $u['username'])
                . (!empty($u['admin']) ? ' <span class="tag ok">admin</span>' : '') . '</td>'
                . '<td>' . (!empty($u['totp_enabled']) ? 'ja' : '<span class="tag off">nein</span>') . '</td>'
                . '<td>' . (!empty($u['sms_enabled']) ? 'ja' : '–') . '</td>'
                . '<td class="small muted">' . Util::h(Util::ts($u['created'] ?? null)) . '</td>'
                . '<td class="small muted">' . Util::h(Util::ts($u['last_login'] ?? null)) . '</td></tr>';
        }

        $auditRows = '';
        foreach (Audit::tail(60) as $e) {
            $auditRows .= '<tr><td class="small">' . Util::h(Util::ts($e['ts'] ?? null)) . '</td>'
                . '<td><code>' . Util::h((string) ($e['ev'] ?? '')) . '</code></td>'
                . '<td class="small break muted">' . Util::h(substr((string) ($e['actor'] ?? '–'), 0, 12))
                . '</td>'
                . '<td class="small muted break">'
                . Util::h(mb_substr(json_encode($e['meta'] ?? [], JSON_UNESCAPED_SLASHES) ?: '', 0, 90))
                . '</td></tr>';
        }

        $urlV = Util::h((string) $c['url']);
        $dbV = Util::h((string) $c['db']);
        $userV = Util::h((string) $c['user']);
        $listV = Util::h((string) $c['mailing_list']);

        $body = <<<HTML
<h1>Administration</h1>
{$err}
{$test}
<div class="card">
  <h2 style="margin-top:0">Odoo-Verbindung für SMS</h2>
  <p class="small muted">Empfohlen wird ein eigener Odoo-Nutzer mit API-Key
  (Einstellungen &rarr; Nutzer &rarr; Kontosicherheit &rarr; Neuer API-Key). Der transaktionale
  Weg über <code>sms.sms</code> ist für Einmalcodes der richtige; die SMS-Marketing-App
  arbeitet mit Warteschlangen und Blacklists und ist für zeitkritische Codes ungeeignet.</p>
  <form method="post">
    {$csrf}
    <input type="hidden" name="action" value="odoo">
    <label style="display:flex;gap:.5rem;align-items:center;color:var(--fg)">
      <input type="checkbox" name="enabled" value="1"{$enabled}> SMS-Faktor aktivieren
    </label>
    <label for="ou">Odoo-URL</label>
    <input id="ou" name="url" type="url" value="{$urlV}" placeholder="https://firma.odoo.com">
    <label for="od">Datenbank</label>
    <input id="od" name="db" type="text" value="{$dbV}">
    <label for="ol">Login (E-Mail)</label>
    <input id="ol" name="user" type="text" value="{$userV}">
    <label for="ok">API-Key ({$keySet})</label>
    <input id="ok" name="api_key" type="password" autocomplete="new-password">
    <label for="om">Versandweg</label>
    <select id="om" name="mode">
      <option value="sms"{$modeSms}>Transaktional über sms.sms (empfohlen)</option>
      <option value="mailing"{$modeMail}>SMS-Marketing-App (mailing.mailing)</option>
    </select>
    <label for="oml">Name der Mailingliste (nur im Marketing-Modus)</label>
    <input id="oml" name="mailing_list" type="text" value="{$listV}">
    <label style="display:flex;gap:.5rem;align-items:center;color:var(--fg);margin-top:1rem">
      <input type="checkbox" name="allow_private" value="1"{$priv}>
      Odoo läuft im internen Netz (SSRF-Schutz für dieses Ziel aussetzen)
    </label>
    <div class="row">
      <button class="btn" type="submit">Speichern</button>
    </div>
  </form>
  <form method="post" style="margin-top:.6rem">
    {$csrf}<input type="hidden" name="action" value="odoo_test">
    <button class="btn sec sm" type="submit">Verbindung testen</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Nutzer</h2>
  <table>
    <tr><th>Nutzer</th><th>TOTP</th><th>SMS</th><th>Angelegt</th><th>Letzte Anmeldung</th></tr>
    {$userRows}
  </table>
</div>

<div class="card">
  <div class="split">
    <h2 style="margin:0">Audit-Log</h2>
    <form method="post">{$csrf}<input type="hidden" name="action" value="audit_verify">
      <button class="btn sec sm" type="submit">Kette prüfen</button></form>
  </div>
  <p class="small muted">Jeder Eintrag enthält den MAC seines Vorgängers. Nachträgliche
  Änderungen brechen die Kette und werden hier erkannt.</p>
  <table>
    <tr><th>Zeit</th><th>Ereignis</th><th>Akteur</th><th>Details</th></tr>
    {$auditRows}
  </table>
</div>
HTML;
        Response::html(View::page('Administration', $body, $user, 'admin'));
    }

    // -- API-Dokumentation -------------------------------------------------

    public static function apiDocs(): void
    {
        $user = Session::user();
        $base = Router::basePath();
        $issuer = Util::h(Util::baseUrl());
        $skew = API_CLOCK_SKEW;
        $ttl = ASSERTION_TTL;
        $body = <<<HTML
<h1>API-Dokumentation</h1>
<p class="lead">Alle Endpunkte liegen unter <code>{$issuer}/api/v1/</code> und erwarten
<code>POST</code> mit JSON-Body. Ausnahme: <code>GET /api/v1/jwks</code> ist öffentlich.</p>

<div class="alert info">Schlüssel gibt es ausschließlich im eingeloggten Konto unter
<a href="{$base}/usr/api">API-Anbindungen</a>. Ohne angelegte Anbindung ist die API nicht nutzbar.</div>

<h2>1. Anfragen signieren</h2>
<p>Jede Anfrage trägt vier Kopfzeilen. Der Signaturstring bindet Methode, Pfad, Zeit,
Nonce und den Hash des Bodys – ein abgefangener Request lässt sich damit weder
wiederholen noch auf einen anderen Endpunkt umlenken.</p>
<pre><code>X-Sso-Client:    &lt;client_id&gt;
X-Sso-Timestamp: &lt;Unixzeit, max. {$skew}s Abweichung&gt;
X-Sso-Nonce:     &lt;32 Hex-Zeichen, pro Client einmalig&gt;
X-Sso-Signature: base64url(HMAC-SHA256(k_c2s, S))

S = "SSO-HMAC-SHA256" LF
    "POST"            LF
    "/api/v1/ping"    LF     (Pfad inkl. eventuellem Unterverzeichnis)
    timestamp         LF
    nonce             LF
    sha256_hex(body)</code></pre>
<p class="small muted">Bei manuellen Anbindungen ist
<code>k_c2s = HKDF-SHA256(secret, L=32, info="sso-manual|c2s|&lt;client_id&gt;", salt="")</code>.
Beim automatischen Pairing entsteht der Schlüssel aus dem X25519-Austausch – die
mitgelieferte <code>api.php</code> erledigt das komplett.</p>

<h2>2. Endpunkte</h2>
<table>
  <tr><th>Endpunkt</th><th>Zweck</th><th>Eingabe</th></tr>
  <tr><td><code>ping</code></td><td>Verbindungstest</td><td>–</td></tr>
  <tr><td><code>token</code></td><td>Autorisierungscode gegen Assertion tauschen</td>
      <td>code, code_verifier, redirect_uri</td></tr>
  <tr><td><code>assertion/verify</code></td><td>Assertion serverseitig prüfen</td><td>assertion</td></tr>
  <tr><td><code>user/get</code></td><td>Profil abfragen</td><td>username oder sub</td></tr>
  <tr><td><code>auth/start</code></td><td>Passwortprüfung (nur wenn erlaubt)</td>
      <td>username, password</td></tr>
  <tr><td><code>auth/totp</code></td><td>Zweiter Faktor zur Challenge</td><td>challenge, code</td></tr>
  <tr><td><code>auth/sms/send</code></td><td>SMS-Code zur Challenge senden</td><td>challenge</td></tr>
  <tr><td><code>auth/sms/verify</code></td><td>SMS-Code prüfen</td><td>challenge, code</td></tr>
  <tr><td><code>jwks</code> (GET)</td><td>Ed25519-Public-Key</td><td>–</td></tr>
</table>

<h2>3. Empfohlener Ablauf: Weiterleitung mit PKCE</h2>
<ol class="steps">
  <li>Ihre Anwendung leitet den Browser auf
      <code>{$issuer}/usr/authorize?client_id=…&amp;redirect_uri=…&amp;state=…&amp;code_challenge=…&amp;code_challenge_method=S256</code>.
      Die <code>redirect_uri</code> muss im Konto exakt eingetragen sein.</li>
  <li>Der Nutzer meldet sich hier mit Passwort und TOTP an und bestätigt die Freigabe.</li>
  <li>Rückleitung mit <code>code</code> und <code>state</code>.</li>
  <li>Ihr Server ruft signiert <code>token</code> auf und erhält eine Assertion
      (Ed25519, <code>alg=EdDSA</code>, {$ttl}s gültig).</li>
  <li>Signatur lokal gegen den Public-Key aus <code>jwks</code> prüfen – fertig.</li>
</ol>
<pre><code>{
  "iss": "{$issuer}",
  "sub": "&lt;stabile Nutzerkennung&gt;",
  "aud": "&lt;client_id&gt;",
  "username": "alice",
  "name": "Alice",
  "email": "alice@example.com",
  "amr": ["pwd", "otp"],
  "auth_time": 1770000000,
  "iat": 1770000001, "nbf": 1770000000, "exp": 1770000301,
  "jti": "…"
}</code></pre>

<h2>4. Direkte Passwortprüfung</h2>
<p>Wenn die eigene Anwendung ein eigenes Anmeldeformular zeigt, lässt sich das in den
Einstellungen der Anbindung freischalten. Ablauf:</p>
<pre><code>POST auth/start   {"username":"alice","password":"…"}
  -> {"status":"mfa_required","challenge":"…","methods":["totp","recovery","sms"]}
POST auth/totp    {"challenge":"…","code":"123456"}
  -> {"status":"ok","assertion":"eyJ…"}</code></pre>
<p class="small muted">Diese Variante ist bewusst standardmäßig deaktiviert: Ihr Server
sieht dabei das Klartextpasswort. Die Weiterleitung ist die sicherere Wahl.</p>

<h2>5. Fehlerformat</h2>
<pre><code>HTTP 401
{"error":"bad_signature","message":"Signatur ungültig"}</code></pre>
<p class="small muted">Fehlercodes: <code>bad_request</code>, <code>bad_signature</code>,
<code>replay</code>, <code>clock_skew</code>, <code>unknown_client</code>,
<code>client_inactive</code>, <code>rate_limited</code>, <code>invalid_grant</code>,
<code>mfa_required</code>, <code>not_allowed</code>.</p>
HTML;
        Response::html(View::page('API-Dokumentation', $body, $user, 'docs'));
    }
}

// ---------------------------------------------------------------------------
// 23. MASCHINEN-API
// ---------------------------------------------------------------------------

final class Api
{
    private const MAX_BODY = 65536;

    public static function dispatch(string $sub): never
    {
        $sub = rtrim($sub, '/');
        $sub = $sub === '' ? '/' : $sub;

        // Einziger öffentlicher Endpunkt: der Signaturschlüssel.
        if ($sub === '/jwks') {
            Response::json(Assertion::jwks());
        }
        if (Router::method() !== 'POST') {
            Response::apiError(405, 'method_not_allowed', 'Nur POST (Ausnahme: GET /jwks).');
        }

        [$client, $payload] = self::authenticate();

        match ($sub) {
            '/ping'             => self::ping($client),
            '/token'            => self::token($client, $payload),
            '/assertion/verify' => self::assertionVerify($client, $payload),
            '/user/get'         => self::userGet($client, $payload),
            '/auth/start'       => self::authStart($client, $payload),
            '/auth/totp'        => self::authSecondFactor($client, $payload, 'totp'),
            '/auth/sms/send'    => self::authSmsSend($client, $payload),
            '/auth/sms/verify'  => self::authSecondFactor($client, $payload, 'sms'),
            default             => Response::apiError(404, 'unknown_endpoint', 'Endpunkt ' . $sub),
        };
        Response::apiError(500, 'internal_error');
    }

    private static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    return (string) $v;
                }
            }
        }
        return '';
    }

    /**
     * Prüft die Request-Signatur und liefert Client plus Nutzlast.
     *
     * @return array{0:array,1:array}
     */
    private static function authenticate(): array
    {
        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($body) > self::MAX_BODY) {
            Response::apiError(413, 'body_too_large');
        }
        $clientId = self::header('X-Sso-Client');
        $ts = (int) self::header('X-Sso-Timestamp');
        $nonce = self::header('X-Sso-Nonce');
        $signature = self::header('X-Sso-Signature');

        if (!preg_match('/^[a-f0-9]{32}$/', $clientId)
            || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)
            || $signature === '') {
            Response::apiError(400, 'bad_request', 'Signatur-Kopfzeilen fehlen oder sind ungültig.');
        }
        if (!RateLimit::hit('api:ip:' . Util::clientIp(), 600, 60)) {
            Response::apiError(429, 'rate_limited', 'Zu viele Anfragen von dieser Adresse.');
        }
        $client = Clients::get($clientId);
        if ($client === null) {
            Response::apiError(401, 'unknown_client');
        }
        if (($client['status'] ?? '') !== 'active') {
            Response::apiError(403, 'client_inactive', 'Die Anbindung ist nicht aktiv.');
        }
        if (!RateLimit::hit('api:client:' . $clientId, 600, 60)) {
            Response::apiError(429, 'rate_limited');
        }
        if (abs(time() - $ts) > API_CLOCK_SKEW) {
            Response::apiError(401, 'clock_skew',
                'Zeitstempel weicht mehr als ' . API_CLOCK_SKEW . ' Sekunden ab.');
        }
        $key = Clients::inboundKey($client);
        if ($key === null || $key === '') {
            Response::apiError(403, 'client_inactive', 'Für diese Anbindung existiert kein Schlüssel.');
        }
        $path = Router::basePath() . Router::path();
        $expected = Sig::signRequest($key, 'POST', $path, $ts, $nonce, $body);
        if (!Util::equals($expected, $signature)) {
            Audit::log('api.bad_signature', ['client' => $clientId, 'path' => $path]);
            Response::apiError(401, 'bad_signature', 'Signatur ungültig.');
        }
        // Erst nach gültiger Signatur - sonst könnte ein Angreifer fremde
        // Nonces mit ungültigen Anfragen verbrennen.
        if (!Sig::consumeNonce($clientId, $nonce)) {
            Response::apiError(409, 'replay', 'Diese Nonce wurde bereits verwendet.');
        }

        $payload = [];
        if ($body !== '') {
            try {
                $decoded = Util::jsonDecode($body);
            } catch (JsonException) {
                Response::apiError(400, 'bad_request', 'Body ist kein gültiges JSON.');
            }
            if (!is_array($decoded)) {
                Response::apiError(400, 'bad_request', 'Body muss ein JSON-Objekt sein.');
            }
            $payload = $decoded;
        }
        Clients::touch($client);
        return [$client, $payload];
    }

    private static function str(array $p, string $key, int $max = 512): string
    {
        $v = $p[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

    // -- Endpunkte ---------------------------------------------------------

    private static function ping(array $client): never
    {
        Response::json([
            'status'  => 'ok',
            'issuer'  => Util::baseUrl(),
            'client'  => (string) $client['id'],
            'time'    => time(),
            'version' => APP_VERSION,
        ]);
    }

    /** Autorisierungscode gegen eine Assertion tauschen (PKCE). */
    private static function token(array $client, array $payload): never
    {
        $code = self::str($payload, 'code', 64);
        $verifier = self::str($payload, 'code_verifier', 128);
        $redirect = self::str($payload, 'redirect_uri');
        if ($code === '' || $verifier === '') {
            Response::apiError(400, 'bad_request', 'code und code_verifier sind Pflicht.');
        }
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) {
            Response::apiError(400, 'bad_request', 'code_verifier entspricht nicht RFC 7636.');
        }
        $rec = Challenges::consume($code, 'sso_code');
        if ($rec === null) {
            Response::apiError(400, 'invalid_grant', 'Code unbekannt, verbraucht oder abgelaufen.');
        }
        if (!hash_equals((string) $rec['client'], (string) $client['id'])) {
            Audit::log('sso.code_wrong_client', ['client' => $client['id']]);
            Response::apiError(400, 'invalid_grant', 'Code gehört zu einer anderen Anwendung.');
        }
        if ($redirect !== '' && !hash_equals((string) $rec['redirect'], $redirect)) {
            Response::apiError(400, 'invalid_grant', 'redirect_uri stimmt nicht überein.');
        }
        $calc = Util::b64u(hash('sha256', $verifier, true));
        if (!Util::equals((string) $rec['challenge'], $calc)) {
            Audit::log('sso.pkce_failed', ['client' => $client['id']]);
            Response::apiError(400, 'invalid_grant', 'PKCE-Prüfung fehlgeschlagen.');
        }
        $user = Users::byId((string) $rec['uid']);
        if ($user === null || !empty($user['disabled'])) {
            Response::apiError(400, 'invalid_grant', 'Konto steht nicht zur Verfügung.');
        }
        $assertion = Assertion::issue($user, $client, [
            'amr'       => (array) ($rec['amr'] ?? ['pwd', 'otp']),
            'auth_time' => (int) ($rec['auth_time'] ?? time()),
            'scope'     => (string) ($rec['scope'] ?? 'profile'),
        ]);
        Audit::log('sso.token_issued', ['client' => $client['id']], (string) $user['id']);
        Response::json([
            'status'     => 'ok',
            'assertion'  => $assertion,
            'token_type' => 'Ed25519-JWT',
            'expires_in' => ASSERTION_TTL,
            'sub'        => (string) $user['id'],
            'username'   => (string) $user['username'],
        ]);
    }

    private static function assertionVerify(array $client, array $payload): never
    {
        $res = Assertion::verify(self::str($payload, 'assertion', 4096), (string) $client['id']);
        if (!$res['ok']) {
            Response::json(['status' => 'invalid', 'reason' => $res['error']], 200);
        }
        Response::json(['status' => 'valid', 'claims' => $res['claims']]);
    }

    private static function userGet(array $client, array $payload): never
    {
        $username = self::str($payload, 'username', 64);
        $sub = self::str($payload, 'sub', 64);
        $user = $username !== '' ? Users::byUsername($username) : Users::byId($sub);
        if ($user === null) {
            Response::apiError(404, 'not_found', 'Unbekannter Nutzer.');
        }
        // Ein Client darf nur Nutzer sehen, die ihm zugestimmt haben - oder
        // seinen eigenen Besitzer. Sonst wäre die API ein Nutzerverzeichnis.
        $approved = (array) ($user['approved'] ?? []);
        $isOwner = hash_equals((string) $client['owner'], (string) $user['id']);
        if (!$isOwner && !isset($approved[(string) $client['id']])) {
            Response::apiError(403, 'not_allowed', 'Keine Freigabe dieses Nutzers für diese Anwendung.');
        }
        Response::json(['status' => 'ok', 'user' => [
            'sub'          => (string) $user['id'],
            'username'     => (string) $user['username'],
            'name'         => (string) $user['display'],
            'email'        => (string) $user['email'],
            'mfa_enabled'  => (bool) $user['totp_enabled'],
            'sms_enabled'  => (bool) $user['sms_enabled'],
            'disabled'     => (bool) $user['disabled'],
            'created'      => (int) $user['created'],
            'last_login'   => $user['last_login'] ?? null,
        ]]);
    }

    /** Schritt 1 der direkten Anmeldung: Passwort. */
    private static function authStart(array $client, array $payload): never
    {
        if (empty($client['allow_password_api'])) {
            Response::apiError(403, 'not_allowed',
                'Passwortprüfung ist für diese Anbindung nicht freigeschaltet.');
        }
        $username = Util::normalizeUsername(self::str($payload, 'username', 64));
        $password = is_string($payload['password'] ?? null) ? (string) $payload['password'] : '';
        if ($username === '' || $password === '') {
            Response::apiError(400, 'bad_request', 'username und password sind Pflicht.');
        }
        if (!RateLimit::hit('apiauth:' . $client['id'], 60, 300)) {
            Response::apiError(429, 'rate_limited');
        }
        if (RateLimit::lockRemaining('login:' . $username) > 0) {
            Response::apiError(429, 'rate_limited', 'Konto ist vorübergehend gesperrt.');
        }
        $user = Users::byUsername($username);
        $ok = $user !== null && empty($user['disabled']) && Users::verifyPassword($user, $password);
        if (!$ok) {
            Users::dummyVerify($password);
            RateLimit::registerFailure('login:' . $username);
            Audit::log('api.auth_failed', ['client' => $client['id'], 'username' => $username]);
            Response::apiError(401, 'invalid_credentials', 'Nutzername oder Passwort ist falsch.');
        }
        RateLimit::clearFailures('login:' . $username);
        if (empty($user['totp_enabled'])) {
            Response::apiError(409, 'mfa_setup_required',
                'Für dieses Konto ist noch kein zweiter Faktor eingerichtet.');
        }
        $methods = ['totp', 'recovery'];
        if (!empty($user['sms_enabled']) && !empty($user['phone_ok']) && OdooSms::isReady()) {
            $methods[] = 'sms';
        }
        $challenge = Challenges::create('api_mfa', [
            'uid'    => (string) $user['id'],
            'client' => (string) $client['id'],
            'tries'  => 0,
        ], MFA_CHALLENGE_TTL);
        Audit::log('api.auth_password_ok', ['client' => $client['id']], (string) $user['id']);
        Response::json([
            'status'     => 'mfa_required',
            'challenge'  => $challenge,
            'methods'    => $methods,
            'expires_in' => MFA_CHALLENGE_TTL,
        ]);
    }

    /** Schritt 2: TOTP, Wiederherstellungscode oder SMS-Code. */
    private static function authSecondFactor(array $client, array $payload, string $kind): never
    {
        $handle = self::str($payload, 'challenge', 64);
        $code = self::str($payload, 'code', 32);
        $rec = Challenges::peek($handle, 'api_mfa');
        if ($rec === null || !hash_equals((string) $rec['client'], (string) $client['id'])) {
            Response::apiError(400, 'invalid_grant', 'Challenge unbekannt oder abgelaufen.');
        }
        if ((int) ($rec['tries'] ?? 0) >= 5) {
            Challenges::drop($handle);
            Response::apiError(429, 'rate_limited', 'Zu viele Fehlversuche.');
        }
        Challenges::update($handle, ['tries' => (int) ($rec['tries'] ?? 0) + 1]);

        $user = Users::byId((string) $rec['uid']);
        if ($user === null || !empty($user['disabled'])) {
            Challenges::drop($handle);
            Response::apiError(400, 'invalid_grant', 'Konto steht nicht zur Verfügung.');
        }
        if (!RateLimit::hit('mfa:' . $user['id'], 15, 300)) {
            Response::apiError(429, 'rate_limited');
        }

        $method = null;
        if ($kind === 'sms') {
            if (SmsOtp::verify($user, 'api_login', $code)) {
                $method = 'sms';
            }
        } else {
            if (Users::verifyTotp($user, $code)) {
                $method = 'otp';
            } elseif (Users::useRecoveryCode($user, $code)) {
                $method = 'recovery';
            }
        }
        if ($method === null) {
            Audit::log('api.mfa_failed', ['client' => $client['id']], (string) $user['id']);
            Response::apiError(401, 'invalid_code', 'Der Code stimmt nicht.');
        }

        Challenges::drop($handle);
        $user = Users::byId((string) $user['id']) ?? $user;
        $user['last_login'] = time();
        Users::save($user);
        $assertion = Assertion::issue($user, $client, [
            'amr'       => ['pwd', $method],
            'auth_time' => time(),
            'scope'     => 'profile',
        ]);
        Audit::log('api.auth_success', ['client' => $client['id'], 'method' => $method],
            (string) $user['id']);
        Response::json([
            'status'     => 'ok',
            'assertion'  => $assertion,
            'token_type' => 'Ed25519-JWT',
            'expires_in' => ASSERTION_TTL,
            'sub'        => (string) $user['id'],
            'username'   => (string) $user['username'],
            'amr'        => ['pwd', $method],
        ]);
    }

    private static function authSmsSend(array $client, array $payload): never
    {
        $handle = self::str($payload, 'challenge', 64);
        $rec = Challenges::peek($handle, 'api_mfa');
        if ($rec === null || !hash_equals((string) $rec['client'], (string) $client['id'])) {
            Response::apiError(400, 'invalid_grant', 'Challenge unbekannt oder abgelaufen.');
        }
        $user = Users::byId((string) $rec['uid']);
        if ($user === null || empty($user['sms_enabled']) || empty($user['phone_ok'])) {
            Response::apiError(409, 'not_allowed', 'SMS steht für dieses Konto nicht bereit.');
        }
        $res = SmsOtp::issue($user, 'api_login');
        if (!$res['ok']) {
            Response::apiError(502, 'sms_failed', $res['error']);
        }
        Response::json(['status' => 'sent', 'expires_in' => SMS_OTP_TTL]);
    }
}

// ---------------------------------------------------------------------------
// 24. EINSTIEGSPUNKT
// ---------------------------------------------------------------------------
// Beim Einbinden über die Kommandozeile (Tests, Wartungsskripte) wird nichts
// ausgeführt - nur ein echter Webaufruf startet das Routing.

if (PHP_SAPI !== 'cli') {
    Router::dispatch();
}
