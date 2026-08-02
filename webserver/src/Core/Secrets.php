<?php

declare(strict_types=1);

namespace Stimmwerk\Core;

/**
 * Serverseitige Geheimnisse. Der Pepper wird beim ersten Start kryptographisch
 * erzeugt und außerhalb des Webroots mit restriktiven Rechten abgelegt.
 * Er verlässt den Server nie; ohne ihn sind die gespeicherten
 * Pseudonym-Hashes wertlos.
 */
final class Secrets
{
    private string $pepper;

    public function __construct(string $dataDir)
    {
        $file = rtrim($dataDir, '/') . '/secret.key';
        if (!is_file($file)) {
            if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
                throw new \RuntimeException('Datenverzeichnis nicht anlegbar.');
            }
            $pepper = bin2hex(random_bytes(32));
            if (file_put_contents($file, $pepper, LOCK_EX) === false) {
                throw new \RuntimeException('Geheimnis-Datei nicht schreibbar.');
            }
            @chmod($file, 0600);
        }
        $pepper = trim((string) file_get_contents($file));
        if (strlen($pepper) < 32) {
            throw new \RuntimeException('Geheimnis-Datei beschädigt.');
        }
        $this->pepper = $pepper;
    }

    /** HMAC-SHA-256 über einen Wert mit dem Server-Pepper. */
    public function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, $this->pepper);
    }
}
