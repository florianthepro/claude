<?php
declare(strict_types=1);

namespace Nyx;

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
