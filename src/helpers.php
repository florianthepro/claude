<?php
/**
 * Globale Helferfunktionen (bewusst ohne Namespace für knappe Aufrufe).
 */
declare(strict_types=1);

require_once __DIR__ . '/registry.php';

/** HTML-escapen. */
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect + Ende. */
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/** GET/POST-Parameter als String. */
function param(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $_POST[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

/** Integer-Parameter. */
function param_int(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? $_POST[$key] ?? $default;
    return is_numeric($v) ? (int) $v : $default;
}

/** Flash-Nachricht setzen/lesen. */
function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** URL zu einer App (+ Query). */
function url(string $app, array $q = []): string
{
    return '?app=' . urlencode($app) . ($q ? '&' . http_build_query($q) : '');
}

/** Bytes menschenlesbar. */
function human_size(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $b = (float) $bytes;
    while ($b >= 1024 && $i < 4) {
        $b /= 1024;
        $i++;
    }
    return round($b, ($b < 10 && $i > 0) ? 1 : 0) . ' ' . $u[$i];
}
