<?php

declare(strict_types=1);

// Router für den eingebauten PHP-Server (nur lokale Entwicklung/Demos):
//   php -S 127.0.0.1:8080 router.php
// Auf einem echten Webserver wird diese Datei nicht benötigt
// (.htaccess leitet alle Anfragen an index.php).
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if ($path !== '/' && $path !== '/index.php' && is_file(__DIR__ . $path)) {
    return false; // statische Datei ausliefern
}
$_SERVER['SW_CLEAN_URLS'] = '1'; // dieser Router übernimmt die Rewrite-Rolle
require __DIR__ . '/index.php';
