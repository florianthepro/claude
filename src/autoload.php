<?php
/**
 * Minimaler PSR-4-Autoloader für den Namespace `Nexus\`.
 * Kein Composer nötig – Nexus braucht weiterhin nur Apache + PHP.
 *
 *   Nexus\Core\Database  ->  src/Core/Database.php
 *   Nexus\Apps\Notes     ->  src/Apps/Notes.php
 */
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Nexus\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Globale Helferfunktionen (kein Namespace) + App-Registry.
require __DIR__ . '/helpers.php';
