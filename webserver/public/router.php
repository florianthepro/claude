<?php

declare(strict_types=1);

// Router für den eingebauten PHP-Server (nur Entwicklung):
//   php -S 127.0.0.1:8080 -t public public/router.php
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false; // statische Datei ausliefern
}
require __DIR__ . '/index.php';
