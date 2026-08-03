<?php
declare(strict_types=1);

namespace Nyx;

/**
 * Speicherknoten auf dem Dateisystem.
 *
 * Was auf der Platte liegt: Dateien mit Zufallsnamen, deren Inhalt unter
 * einem Schluessel versiegelt ist, den der Knoten nach der Herausgabe aus
 * seinem eigenen Schluesselmaterial entfernt. Es gibt kein Zugriffs-
 * protokoll und keine Zuordnung von Tags zu Absendern, Empfaengern oder
 * Nachrichten — der Knoten kann sie gar nicht herstellen.
 */
final class Store
{
    public const TAG_LEN = 32;
    public const MAX_BLOB = 1048576;

    private string $dir;
    private Puncturable $key;

    public function __construct(
        private string $name = 'php-knoten',
        string $dataDir = '/tmp/nyx-store',
        private int $ttl = 604800,
        private int $powBits = 12,
    ) {
        $this->dir = rtrim($dataDir, '/');
        @mkdir($this->dir . '/drops', 0700, true);
        $this->key = $this->loadKey();
    }

    // -- Schluesselzustand ------------------------------------------------

    private function keyPath(): string
    {
        return $this->dir . '/key.json';
    }

    private function loadKey(): Puncturable
    {
        if (is_file($this->keyPath())) {
            $data = json_decode((string) file_get_contents($this->keyPath()), true);
            if (is_array($data)) {
                return Puncturable::fromArray($data);
            }
        }
        $key = new Puncturable(random_bytes(32));
        $this->persistKey($key);
        return $key;
    }

    private function persistKey(?Puncturable $key = null): void
    {
        $key ??= $this->key;
        file_put_contents($this->keyPath(), json_encode($key->toArray()), LOCK_EX);
        @chmod($this->keyPath(), 0600);
    }

    // -- Ablegen -----------------------------------------------------------

    public function put(string $tag, string $blob, ?int $ttl, ?int $nonce): array
    {
        $raw = @hex2bin($tag);
        if ($raw === false || strlen($raw) !== self::TAG_LEN) {
            throw new \InvalidArgumentException('Tag muss 32 Byte sein');
        }
        if (strlen($blob) > self::MAX_BLOB) {
            throw new \InvalidArgumentException('Ablage zu gross');
        }
        if (is_file($this->path($tag))) {
            throw new \InvalidArgumentException('Tag bereits belegt');
        }
        if ($this->powBits > 0 && !$this->powOk($tag, $blob, $nonce)) {
            throw new \InvalidArgumentException('Arbeitsnachweis fehlt oder ist zu schwach');
        }

        $key = $this->key->derive($raw);
        if ($key === null) {
            throw new \InvalidArgumentException('Tag wurde bereits verbraucht');
        }

        $expires = time() + ($ttl ?? $this->ttl);
        $sealed = sodium_crypto_aead_chacha20poly1305_ietf_encrypt(
            $blob, $tag, $this->nonce($tag), $key
        );
        file_put_contents($this->path($tag),
            pack('J', $expires) . $sealed, LOCK_EX);

        return ['tag' => $tag, 'expires' => $expires, 'node' => $this->name];
    }

    private function powOk(string $tag, string $blob, ?int $nonce): bool
    {
        if ($nonce === null) {
            return false;
        }
        $digest = Canonical::sha256('nyx/v1/put-pow', $tag, $blob, pack('J', $nonce));
        return Canonical::leadingZeroBits($digest) >= $this->powBits;
    }

    // -- Abholen -----------------------------------------------------------

    public function get(string $tag): ?string
    {
        $raw = @hex2bin($tag);
        if ($raw === false || strlen($raw) !== self::TAG_LEN) {
            return null;
        }
        $path = $this->path($tag);
        if (!is_file($path)) {
            return null;
        }

        $data = (string) file_get_contents($path);
        $expires = unpack('J', substr($data, 0, 8))[1];
        $sealed = substr($data, 8);
        if ($expires <= time()) {
            $this->drop($tag, $raw);
            return null;
        }

        $key = $this->key->derive($raw);
        if ($key === null) {
            return null;
        }
        $blob = @sodium_crypto_aead_chacha20poly1305_ietf_decrypt(
            $sealed, $tag, $this->nonce($tag), $key
        );

        // Erst herausgeben, dann vergessen — in dieser Reihenfolge.
        $this->drop($tag, $raw);
        return $blob === false ? null : $blob;
    }

    /** @param string[] $tags */
    public function getBatch(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $blob = $this->get((string) $tag);
            $out[(string) $tag] = $blob === null ? null : bin2hex($blob);
        }
        return $out;
    }

    /** Entwertet einen Tag, ohne ihn auszuliefern (Whitepaper 7.6). */
    public function void(string $tag): bool
    {
        $raw = @hex2bin($tag);
        if ($raw === false || strlen($raw) !== self::TAG_LEN) {
            return false;
        }
        $existed = is_file($this->path($tag));
        $this->drop($tag, $raw);
        return $existed;
    }

    private function drop(string $tag, string $raw): void
    {
        $path = $this->path($tag);
        if (is_file($path)) {
            // Ueberschreiben vor dem Loeschen. Auf journalisierenden
            // Dateisystemen ist das keine Garantie — die Garantie liefert
            // die Punktierung in der Zeile darunter.
            $size = (int) filesize($path);
            if ($size > 0) {
                file_put_contents($path, random_bytes($size), LOCK_EX);
            }
            @unlink($path);
        }
        $this->key->puncture($raw);
        $this->persistKey();
    }

    // -- Verfall -----------------------------------------------------------

    public function sweep(): int
    {
        $removed = 0;
        foreach (glob($this->dir . '/drops/*.bin') ?: [] as $path) {
            $head = (string) file_get_contents($path, false, null, 0, 8);
            if (strlen($head) < 8) {
                continue;
            }
            if (unpack('J', $head)[1] <= time()) {
                $tag = basename($path, '.bin');
                $this->drop($tag, (string) hex2bin($tag));
                $removed++;
            }
        }
        return $removed;
    }

    // -- Aufbewahrungsnachweis (Whitepaper 7.5) ---------------------------

    public function proveRetrievability(string $challenge): array
    {
        $this->sweep();
        $files = glob($this->dir . '/drops/*.bin') ?: [];
        sort($files);
        if (!$files) {
            return ['node' => $this->name, 'count' => 0,
                    'proof' => bin2hex(Canonical::sha256('nyx/v1/por-empty', $challenge))];
        }

        $acc = Canonical::sha256('nyx/v1/por', $challenge);
        $sampled = min(8, count($files));
        for ($i = 0; $i < $sampled; $i++) {
            $pick = unpack('N', substr(Canonical::hmac($challenge, pack('N', $i)), 0, 4))[1];
            $path = $files[$pick % count($files)];
            $tag = basename($path, '.bin');
            $acc = Canonical::sha256($acc, $tag, substr((string) file_get_contents($path), 8));
        }
        return ['node' => $this->name, 'count' => count($files),
                'sampled' => $sampled, 'proof' => bin2hex($acc)];
    }

    // -- Kleinkram ---------------------------------------------------------

    private function path(string $tag): string
    {
        return $this->dir . '/drops/' . $tag . '.bin';
    }

    private function nonce(string $tag): string
    {
        return substr(Canonical::sha256('nyx/v1/store-nonce', $tag), 0, 12);
    }

    public function count(): int
    {
        return count(glob($this->dir . '/drops/*.bin') ?: []);
    }

    public function info(): array
    {
        return ['name' => $this->name, 'pow_bits' => $this->powBits,
                'ttl' => $this->ttl, 'entries' => $this->count()];
    }
}
