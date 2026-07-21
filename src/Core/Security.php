<?php
declare(strict_types=1);

namespace Nexus\Core;

/** CSRF, Sicherheits-Header und symmetrische Verschlüsselung. */
final class Security
{
    /* ---------------- Sicherheits-Header ---------------- */
    public static function headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header("Content-Security-Policy: "
            . "default-src 'self'; img-src 'self' data:; "
            . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; "
            . "connect-src 'self'; frame-src 'self'; object-src 'none'; "
            . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');
        header_remove('X-Powered-By');
    }

    /* ---------------- CSRF ---------------- */
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }

    /** Prüft POST-Token; bricht bei Fehler ab. */
    public static function verifyPost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        $t = $_POST['_csrf'] ?? '';
        if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
            http_response_code(419);
            exit('Sitzung abgelaufen oder ungültiges Token. Bitte Seite neu laden.');
        }
    }

    /** Prüft Token in GET-Aktionen (Links). */
    public static function verifyGet(): void
    {
        if (!hash_equals($_SESSION['csrf'] ?? '', param('_csrf'))) {
            http_response_code(419);
            exit('Ungültiges Token.');
        }
    }

    /* ---------------- Verschlüsselung (AES-256-GCM) ---------------- */
    private static function key(): string
    {
        return hash('sha256', (string) @file_get_contents(NX_SECRET), true);
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key();
        if (function_exists('openssl_encrypt')) {
            $iv  = random_bytes(12);
            $tag = '';
            $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return 'v1:' . base64_encode($iv . $tag . $ct);
        }
        return 'x1:' . base64_encode($plain ^ str_pad('', strlen($plain), $key));
    }

    public static function decrypt(string $blob): string
    {
        $key = self::key();
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
}
