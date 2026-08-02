<?php

declare(strict_types=1);

namespace Stimmwerk\Eid;

/**
 * Simulierte Ausweisprüfung für Entwicklung und Vorführungen.
 *
 * Eine frei wählbare Test-Kennung steht stellvertretend für die physische
 * Karte: dieselbe Kennung ergibt deterministisch dasselbe Pseudonym –
 * so wie dieselbe Karte beim echten eID-Verfahren dasselbe
 * dienstespezifische Kennzeichen liefert. Im UI ist der Modus deutlich als
 * Simulation gekennzeichnet; produktiv ist er nicht zu verwenden.
 */
final class MockEidProvider implements EidProvider
{
    public const MIN_SECRET_LENGTH = 8;

    public function completeAuth(array $input): ?string
    {
        $secret = $input['card_secret'] ?? '';
        if (!is_string($secret)) {
            return null;
        }
        $secret = trim($secret);
        if (strlen($secret) < self::MIN_SECRET_LENGTH || strlen($secret) > 128) {
            return null;
        }
        return 'MOCK-' . hash('sha256', 'stimmwerk-restricted-id|' . $secret);
    }

    public function isMock(): bool
    {
        return true;
    }

    /** Erzeugt eine gut lesbare, zufällige Test-Kennung. */
    public static function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            if ($i > 0 && $i % 4 === 0) {
                $out .= '-';
            }
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
