<?php

declare(strict_types=1);

// Demo-Population für Vorführungen: simulierte Pseudonyme + zufällige Stimmen.
// Nur CLI und nur im Mock-Modus – niemals in einem echten Betrieb.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$factory = require dirname(__DIR__) . '/src/bootstrap.php';
$app = $factory();

if (!$app->eid->isMock()) {
    fwrite(STDERR, "Abbruch: Demo-Daten sind nur im Mock-Modus erlaubt.\n");
    exit(1);
}

$count = isset($argv[1]) && preg_match('/^\d{1,5}$/', $argv[1]) === 1 ? (int) $argv[1] : 400;
$result = (new Stimmwerk\Domain\Seeder($app->db))->demoPopulation($count);
printf("Demo-Nutzer angelegt: %d, Stimmen erzeugt: %d\n", $result['users'], $result['votes']);
