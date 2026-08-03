<?php
declare(strict_types=1);

/**
 * Knoten-Schnittstelle in PHP.
 *
 * Dieselben Pfade und dieselben Datenformate wie der Python-Daemon, damit
 * ein Client nicht wissen muss, womit er spricht. Der Vertrag steht in
 * docs/api.md.
 *
 * Betrieb:
 *   php -S 127.0.0.1:8080 -t impl/php/public
 * oder als DocumentRoot hinter Apache/nginx. Es gibt keine Datenbank und
 * keine Abhaengigkeiten ausser der Erweiterung sodium.
 *
 * Konfiguration ueber Umgebungsvariablen:
 *   NYX_NAME      Knotenname                 (Vorgabe php-knoten)
 *   NYX_DATA      Datenverzeichnis           (Vorgabe /tmp/nyx-store)
 *   NYX_TTL       Verfallszeit in Sekunden   (Vorgabe 604800)
 *   NYX_POW_BITS  Arbeitsnachweis je Ablage  (Vorgabe 12)
 *   NYX_BITS      Schwierigkeit der Kette    (Vorgabe 12)
 */

require __DIR__ . '/../src/Canonical.php';
require __DIR__ . '/../src/Puncturable.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/Directory.php';

use Nyx\Directory;
use Nyx\Store;

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
