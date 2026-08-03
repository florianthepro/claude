<?php
declare(strict_types=1);

namespace Nyx;

/**
 * Verzeichnis: Eintraege pruefen, Kette fuehren.
 *
 * Die Pruefregeln sind dieselben wie in nyx/directory.py. Wichtig ist vor
 * allem die zweite: die Adresse ist der Schluessel. Ein Eintrag, dessen
 * Adresse sich nicht aus seinem Identitaetsschluessel ergibt, ist wertlos,
 * egal wie gueltig seine Signatur ist.
 */
final class Directory
{
    private const RECORD_LABEL = 'nyx/v1/record';
    private const BLOCK_LABEL = 'nyx/v1/block';
    private const LEAF = "\x00";
    private const NODE = "\x01";
    private const TYPES = ['BIND', 'PREKEY', 'REVOKE', 'RECOVER'];

    private string $path;

    public function __construct(string $dataDir = '/tmp/nyx-store', private int $bits = 12)
    {
        @mkdir($dataDir, 0700, true);
        $this->path = rtrim($dataDir, '/') . '/chain.json';
        if (!is_file($this->path)) {
            $this->save([$this->mine([
                'height' => 0, 'prev' => str_repeat('0', 64),
                'merkle_root' => str_repeat('0', 64), 'ts' => 0,
                'bits' => $this->bits, 'nonce' => 0, 'records' => [],
            ])]);
        }
    }

    // -- Adressen ---------------------------------------------------------

    public static function addressFromKey(string $ikPub): string
    {
        $body = substr(Canonical::sha256('nyx/v1/addr', $ikPub), 0, 20);
        $sum = substr(Canonical::sha256('nyx/v1/addrsum', $body), 0, 2);
        return strtolower(rtrim(self::base32($body . $sum), '='));
    }

    private static function base32(string $raw): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    // -- Eintraege ---------------------------------------------------------

    public static function verifyRecord(array $record): bool
    {
        if (!in_array($record['type'] ?? '', self::TYPES, true)) {
            return false;
        }
        foreach (['addr', 'ik_pub', 'sig', 'ts', 'body'] as $field) {
            if (!array_key_exists($field, $record)) {
                return false;
            }
        }
        $ikPub = @hex2bin((string) $record['ik_pub']);
        $sig = @hex2bin((string) $record['sig']);
        if ($ikPub === false || $sig === false || strlen($ikPub) !== 32 || strlen($sig) !== 64) {
            return false;
        }
        if (self::addressFromKey($ikPub) !== $record['addr']) {
            return false;
        }

        $unsigned = $record;
        unset($unsigned['sig'], $unsigned['recovery_sig'], $unsigned['height']);
        $message = self::RECORD_LABEL . Canonical::encode($unsigned);
        return sodium_crypto_sign_verify_detached($sig, $message, $ikPub);
    }

    // -- Kette -------------------------------------------------------------

    public function blocks(): array
    {
        return json_decode((string) file_get_contents($this->path), true) ?: [];
    }

    private function save(array $blocks): void
    {
        file_put_contents($this->path, json_encode($blocks), LOCK_EX);
    }

    public function headers(): array
    {
        return array_map(fn(array $b) => self::header($b), $this->blocks());
    }

    private static function header(array $block): array
    {
        return [
            'height' => $block['height'], 'prev' => $block['prev'],
            'merkle_root' => $block['merkle_root'], 'ts' => $block['ts'],
            'bits' => $block['bits'], 'nonce' => $block['nonce'],
        ];
    }

    public static function blockHash(array $block): string
    {
        return bin2hex(Canonical::sha256(self::BLOCK_LABEL,
            Canonical::encode(self::header($block))));
    }

    public static function merkleRoot(array $records): string
    {
        if (!$records) {
            return str_repeat("\0", 32);
        }
        $level = array_map(
            fn(array $r) => Canonical::sha256(self::LEAF, Canonical::encode($r)),
            $records
        );
        while (count($level) > 1) {
            if (count($level) % 2) {
                $level[] = end($level);
            }
            $next = [];
            for ($i = 0; $i < count($level); $i += 2) {
                $next[] = Canonical::sha256(self::NODE, $level[$i], $level[$i + 1]);
            }
            $level = $next;
        }
        return $level[0];
    }

    private function mine(array $block): array
    {
        while (Canonical::leadingZeroBits(
            Canonical::sha256(self::BLOCK_LABEL, Canonical::encode(self::header($block)))
        ) < $block['bits']) {
            $block['nonce']++;
        }
        return $block;
    }

    public function submit(array $record, bool $seal = true): int
    {
        if (!self::verifyRecord($record)) {
            throw new \InvalidArgumentException('Eintrag ist nicht gueltig signiert');
        }
        $blocks = $this->blocks();
        $prev = $blocks[count($blocks) - 1];

        $block = $this->mine([
            'height' => count($blocks),
            'prev' => self::blockHash($prev),
            'merkle_root' => bin2hex(self::merkleRoot([$record])),
            'ts' => time(),
            'bits' => $this->bits,
            'nonce' => 0,
            'records' => [$record],
        ]);
        $blocks[] = $block;
        $this->save($blocks);
        return $block['height'];
    }

    public function validate(): bool
    {
        $blocks = $this->blocks();
        foreach ($blocks as $i => $block) {
            if ($block['height'] !== $i) {
                return false;
            }
            if ($i > 0 && $block['prev'] !== self::blockHash($blocks[$i - 1])) {
                return false;
            }
            if (Canonical::leadingZeroBits(
                Canonical::sha256(self::BLOCK_LABEL, Canonical::encode(self::header($block)))
            ) < $block['bits']) {
                return false;
            }
            if ($block['merkle_root'] !== bin2hex(self::merkleRoot($block['records']))) {
                return false;
            }
            foreach ($block['records'] as $record) {
                if (!self::verifyRecord($record)) {
                    return false;
                }
            }
        }
        return true;
    }

    // -- Lesen -------------------------------------------------------------

    public function recordsFor(string $address, ?string $type = null): array
    {
        $out = [];
        foreach ($this->blocks() as $block) {
            foreach ($block['records'] as $record) {
                if ($record['addr'] === $address && ($type === null || $record['type'] === $type)) {
                    $out[] = $record + ['height' => $block['height']];
                }
            }
        }
        return $out;
    }

    private function revoked(string $address): array
    {
        $keys = [];
        foreach ($this->recordsFor($address, 'REVOKE') as $record) {
            if (isset($record['body']['key'])) {
                $keys[] = $record['body']['key'];
            }
        }
        return $keys;
    }

    public function resolve(string $address): ?array
    {
        $revoked = $this->revoked($address);
        foreach (array_reverse($this->recordsFor($address, 'BIND')) as $record) {
            $idk = $record['body']['idk_pub'] ?? null;
            if ($idk !== null && !in_array($idk, $revoked, true)) {
                return ['address' => $address, 'ik_pub' => $record['ik_pub'], 'idk_pub' => $idk];
            }
        }
        return null;
    }

    public function latestBundle(string $address): ?array
    {
        $revoked = $this->revoked($address);
        foreach (array_reverse($this->recordsFor($address, 'PREKEY')) as $record) {
            if (!in_array($record['body']['spk_pub'] ?? '', $revoked, true)) {
                return $record['body'];
            }
        }
        return null;
    }

    /**
     * Jeder Schluesselwechsel mit Blockhoehe und Zeitpunkt.
     *
     * Der praktische Nutzen der Kette: ein untergeschobener Schluessel
     * erscheint hier als zusaetzlicher Eintrag und ist damit sichtbar,
     * ohne dass jemand Pruefsummen von Hand vergleichen muesste.
     */
    public function keyHistory(string $address): array
    {
        return array_map(fn(array $r) => [
            'height' => $r['height'], 'type' => $r['type'], 'ts' => $r['ts'],
            'key' => $r['body']['idk_pub'] ?? $r['body']['spk_pub'] ?? $r['body']['key'] ?? null,
        ], $this->recordsFor($address));
    }

    public function height(): int
    {
        return count($this->blocks()) - 1;
    }
}
