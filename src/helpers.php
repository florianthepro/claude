<?php

declare(strict_types=1);

/**
 * Kleine, überall verwendete Hilfsfunktionen: Output-Escaping und
 * Whitelist-orientiertes Einlesen von Nutzereingaben.
 */

/** HTML-Escaping für JEDE Ausgabe von Nutzerdaten. */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Liest ein POST-Textfeld: trimmt, erzwingt gültiges UTF-8, entfernt
 * Kontrollzeichen (Zeilenumbrüche/Tab nur wenn erlaubt) und begrenzt die Länge.
 */
function post_str(string $key, int $maxLen, bool $multiline = false): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $pattern = $multiline ? '/[^\P{C}\n\t]/u' : '/\p{C}/u';
    $value = (string) preg_replace($pattern, '', $value);
    $value = trim($value);
    if (mb_strlen($value) > $maxLen) {
        $value = mb_substr($value, 0, $maxLen);
    }
    return $value;
}

function post_int(string $key): ?int
{
    $value = $_POST[$key] ?? null;
    if (is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1) {
        return (int) $value;
    }
    return null;
}

/** @return list<string> Nur Werte aus der Whitelist bleiben erhalten. */
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

/**
 * Basispfad der Installation ('' im Webroot, z. B. '/stimmwerk' im
 * Unterordner) – für statische Dateien (Assets).
 */
function asset_base(): string
{
    return defined('STIMMWERK_BASE') ? (string) STIMMWERK_BASE : '';
}

/**
 * URL-Präfix für anwendungsinterne Links. Sind Rewrite-Regeln aktiv,
 * entstehen saubere Pfade (/topics); andernfalls der überall lauffähige
 * Stil /index.php/topics (PATH_INFO) – verhindert 404 auf Servern ohne
 * mod_rewrite bzw. ohne .htaccess-Auswertung.
 */
function base_path(): string
{
    $clean = defined('STIMMWERK_CLEAN_URLS') && STIMMWERK_CLEAN_URLS;
    return asset_base() . ($clean ? '' : '/index.php');
}

/** Interne URL: Präfix + anwendungsinterner Pfad. */
function url(string $path): string
{
    $full = base_path() . $path;
    return $full === '' ? '/' : $full;
}

/** Interner Redirect – ausschließlich auf eigene, interne Pfade. */
function redirect(string $path): never
{
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        $path = '/';
    }
    header('Location: ' . url($path), true, 303);
    exit;
}
