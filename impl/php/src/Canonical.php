<?php
declare(strict_types=1);

namespace Nyx;

/**
 * Eindeutige Byte-Darstellung von JSON.
 *
 * Python, PHP und JavaScript muessen denselben Eintrag zu denselben Bytes
 * machen, sonst stimmen die Signaturen und Merkle-Wurzeln nicht ueberein.
 * Drei Festlegungen genuegen dafuer:
 *
 *   - Schluessel rekursiv sortiert
 *   - keine Leerzeichen zwischen den Elementen
 *   - Schraegstriche nicht maskiert, Zeichen ausserhalb ASCII als \uXXXX
 *
 * Das entspricht json.dumps(sort_keys=True, separators=(',', ':'),
 * ensure_ascii=True) in Python.
 */
final class Canonical
{
    public const FLAGS = JSON_UNESCAPED_SLASHES;

    public static function encode(mixed $value): string
    {
        return json_encode(self::sorted($value), self::FLAGS);
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sorted($v);
        }
        if (!$isList) {
            ksort($out, SORT_STRING);
        }
        return $out;
    }

    public static function sha256(string ...$parts): string
    {
        return hash('sha256', implode('', $parts), true);
    }

    public static function hmac(string $key, string ...$parts): string
    {
        return hash_hmac('sha256', implode('', $parts), $key, true);
    }

    /** HKDF mit Protokoll-Label, wie in nyx/primitives/kdf.py. */
    public static function hkdf(string $ikm, string $label, int $length = 32,
                                string $salt = ''): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt !== '' ? $salt : str_repeat("\0", 32), true);
        $out = '';
        $block = '';
        $counter = 1;
        while (strlen($out) < $length) {
            $block = hash_hmac('sha256', $block . $label . chr($counter), $prk, true);
            $out .= $block;
            $counter++;
        }
        return substr($out, 0, $length);
    }

    public static function leadingZeroBits(string $digest): int
    {
        $bits = 0;
        for ($i = 0, $n = strlen($digest); $i < $n; $i++) {
            $byte = ord($digest[$i]);
            if ($byte !== 0) {
                return $bits + (8 - (int) floor(log($byte, 2) + 1));
            }
            $bits += 8;
        }
        return $bits;
    }
}
