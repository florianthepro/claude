<?php
/**
 * Nexus – Front Controller.
 * Docroot sollte auf dieses Verzeichnis (public/) zeigen. data/ und src/
 * liegen eine Ebene höher und sind damit nicht per Web erreichbar.
 */
declare(strict_types=1);

// Beim eingebauten PHP-Server (php -S) statische Dateien direkt ausliefern.
// Unter Apache greift dieser Block nicht – dort liefert der Server sie selbst.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

nx_bootstrap();
Nexus\Core\Kernel::handle();
