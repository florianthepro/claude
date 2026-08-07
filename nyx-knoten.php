<?php
declare(strict_types=1);

/**
 * Nyx — Knoten und Verzeichnis. Alles in einer Datei.
 *
 * Aufsetzen:
 *   1. Diese Datei auf einen Webspace mit PHP 8 und der Erweiterung sodium
 *      legen, zum Beispiel als index.php.
 *   2. Ein beschreibbares Datenverzeichnis angeben (NYX_DATA), das von
 *      aussen nicht erreichbar ist.
 *   3. Fertig. Keine Datenbank, kein Composer, keine Anmeldung.
 *
 * Zum Ausprobieren genuegt:
 *   NYX_DATA=/tmp/nyx php -S 0.0.0.0:8080 nyx-knoten.php
 *
 * Der Knoten fuehrt kein Zugriffsprotokoll. Das ist keine Nachlaessigkeit:
 * wer wann welchen Tag abgefragt hat, waere genau die Metadatenspur, die
 * das uebrige System vermeidet.
 *
 * Erzeugt aus impl/php.
 */

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

/**
 * Punktierbare PRF (Whitepaper 7.7, Schicht 2).
 *
 * Byteweise identisch zu nyx/puncturable.py: derselbe GGM-Baum, dieselben
 * Ableitungslabel, dieselbe Tiefe. Ein Knoten kann dadurch von der einen
 * auf die andere Implementierung wechseln, ohne seinen Bestand zu verlieren.
 *
 * Nach der Herausgabe eines Teils entfernt der Knoten aus seinem eigenen
 * Schluessel die Faehigkeit, genau diesen Tag abzuleiten. Wird er danach
 * beschlagnahmt, kommt er an das bei ihm liegende Chiffrat nicht mehr heran.
 */
final class Puncturable
{
    public const DEPTH = 48;
    private const LEFT = "\x00";
    private const RIGHT = "\x01";
    private const LEAF = "\x02";

    /** @var array<int, array{prefix: int, len: int, seed: string}> */
    private array $cover;
    private int $punctured = 0;

    public function __construct(string $master)
    {
        $this->cover = [['prefix' => 0, 'len' => 0, 'seed' => $master]];
    }

    /**
     * Blattnummer eines Tags: die oberen DEPTH Bits des Hashwerts.
     *
     * Bei DEPTH = 48 sind das genau die ersten sechs Bytes. Der Wert passt
     * damit in einen vorzeichenbehafteten 64-Bit-Integer, und die
     * Umrechnung kommt ohne bcmath aus — die Erweiterung ist auf vielen
     * PHP-Installationen nicht vorhanden.
     */
    public static function leafIndex(string $tagRaw): int
    {
        if (self::DEPTH !== 48) {
            throw new \LogicException('leafIndex ist auf DEPTH = 48 ausgelegt');
        }
        $digest = Canonical::sha256('nyx/v1/punct-index', $tagRaw);
        $high = unpack('N', substr($digest, 0, 4))[1];
        $low = unpack('n', substr($digest, 4, 2))[1];
        return $high * 65536 + $low;
    }

    private static function bitAt(int $index, int $level): int
    {
        return ($index >> (self::DEPTH - 1 - $level)) & 1;
    }

    private static function child(string $seed, int $bit): string
    {
        return Canonical::hmac($seed, $bit ? self::RIGHT : self::LEFT);
    }

    private static function prefixOf(int $index, int $len): int
    {
        return $len === 0 ? 0 : $index >> (self::DEPTH - $len);
    }

    private function covering(int $index): ?int
    {
        foreach ($this->cover as $i => $entry) {
            if ($entry['len'] === 0 || self::prefixOf($index, $entry['len']) === $entry['prefix']) {
                return $i;
            }
        }
        return null;
    }

    public function derive(string $tagRaw): ?string
    {
        $index = self::leafIndex($tagRaw);
        $slot = $this->covering($index);
        if ($slot === null) {
            return null;
        }
        $seed = $this->cover[$slot]['seed'];
        for ($level = $this->cover[$slot]['len']; $level < self::DEPTH; $level++) {
            $seed = self::child($seed, self::bitAt($index, $level));
        }
        return Canonical::hmac($seed, self::LEAF . $tagRaw);
    }

    public function puncture(string $tagRaw): bool
    {
        $index = self::leafIndex($tagRaw);
        $slot = $this->covering($index);
        if ($slot === null) {
            return false;
        }
        $entry = $this->cover[$slot];
        unset($this->cover[$slot]);
        $this->cover = array_values($this->cover);

        $seed = $entry['seed'];
        $prefix = $entry['prefix'];
        for ($level = $entry['len']; $level < self::DEPTH; $level++) {
            $bit = self::bitAt($index, $level);
            $this->cover[] = [
                'prefix' => ($prefix << 1) | (1 - $bit),
                'len' => $level + 1,
                'seed' => self::child($seed, 1 - $bit),
            ];
            $seed = self::child($seed, $bit);
            $prefix = ($prefix << 1) | $bit;
        }
        // Das Blattsaatgut wird nicht aufbewahrt — das ist die Punktierung.
        $this->punctured++;
        return true;
    }

    public function puncturedCount(): int
    {
        return $this->punctured;
    }

    public function toArray(): array
    {
        return [
            'punctured' => $this->punctured,
            'cover' => array_map(
                fn(array $e) => ['prefix' => $e['prefix'], 'len' => $e['len'],
                                 'seed' => bin2hex($e['seed'])],
                $this->cover
            ),
        ];
    }

    public static function fromArray(array $data): self
    {
        $key = new self(random_bytes(32));
        $key->punctured = $data['punctured'] ?? 0;
        $key->cover = array_map(
            fn(array $e) => ['prefix' => (int) $e['prefix'], 'len' => (int) $e['len'],
                             'seed' => (string) hex2bin($e['seed'])],
            $data['cover'] ?? []
        );
        return $key;
    }
}

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

// ==========================================================================
// Schnittstelle
// ==========================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!extension_loaded('sodium')) {
    http_response_code(500);
    echo json_encode(['error' => 'Erweiterung sodium fehlt']);
    exit;
}

$dataDir = getenv('NYX_DATA') ?: '/tmp/nyx-store';
$store = new Store(
    getenv('NYX_NAME') ?: 'php-knoten',
    $dataDir,
    (int) (getenv('NYX_TTL') ?: 604800),
    (int) (getenv('NYX_POW_BITS') ?: 12),
);
$directory = new Directory($dataDir, (int) (getenv('NYX_BITS') ?: 12));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$raw = file_get_contents('php://input') ?: '';
$body = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
if (!$body && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $body = $_GET;
}

/** Die Antwort geht ohne Zugriffsprotokoll heraus — siehe Modulkopf. */
function reply(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    switch ($path) {
        case '/v1/info':
            reply(200, ['version' => 'nyx/1', 'height' => $directory->height()]
                       + $store->info());

        case '/v1/store':
            reply(200, ['ok' => true] + $store->put(
                (string) ($body['tag'] ?? ''),
                (string) hex2bin((string) ($body['blob'] ?? '')),
                isset($body['ttl']) ? (int) $body['ttl'] : null,
                isset($body['nonce']) ? (int) $body['nonce'] : null,
            ));

        case '/v1/fetch':
            $tags = $body['tags'] ?? [];
            if (count($tags) > 256) {
                reply(400, ['error' => 'zu viele Tags in einer Anfrage']);
            }
            reply(200, ['results' => $store->getBatch($tags)]);

        case '/v1/void':
            $voided = 0;
            foreach ($body['tags'] ?? [] as $tag) {
                $voided += $store->void((string) $tag) ? 1 : 0;
            }
            reply(200, ['voided' => $voided]);

        case '/v1/proof':
            reply(200, $store->proveRetrievability(
                (string) hex2bin((string) ($body['challenge'] ?? ''))));

        case '/v1/dir/headers':
            reply(200, ['headers' => $directory->headers()]);

        case '/v1/dir/submit':
            reply(200, ['ok' => true,
                        'height' => $directory->submit($body['record'] ?? [])]);

        case '/v1/dir/resolve':
            $addr = (string) ($body['addr'] ?? '');
            reply(200, [
                'identity' => $directory->resolve($addr),
                'bundle' => $directory->latestBundle($addr),
                'history' => $directory->keyHistory($addr),
            ]);

        case '/v1/sweep':
            reply(200, ['expired' => $store->sweep()]);

        default:
            reply(404, ['error' => 'unbekannter Pfad']);
    }
} catch (\InvalidArgumentException $e) {
    reply(400, ['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    // Keine Einzelheiten nach aussen: eine Fehlermeldung, die den Zustand
    // des Knotens verraet, ist selbst eine Metadatenquelle.
    reply(500, ['error' => 'interner Fehler']);
}
