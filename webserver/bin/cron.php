<?php

declare(strict_types=1);

// Wartungslauf: Meldungs-Zustandswechsel und Aufräumarbeiten.
// Empfohlen: minütlich per Cron. Die Anwendung bleibt auch ohne Cron korrekt
// (gedrosselter Lazy-Lauf bei Seitenaufrufen).
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$factory = require dirname(__DIR__) . '/src/bootstrap.php';
$app = $factory();
$app->maintenance->tick();
echo "ok\n";
