<?php
/**
 * Minimaler Autoloader für den Namespace `Nexus\` – kein Composer nötig.
 *
 *   Nexus\Core\Database  ->  src/Core/Database.php
 *   Nexus\Services\Auth  ->  src/Services/Auth.php
 *   Nexus\Apps\Notes     ->  apps/notes/Notes.php   (Ordner = Klein-App-ID)
 */
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Nexus\\', 6) !== 0) {
        return;
    }
    $rest = substr($class, 6);

    if (strncmp($rest, 'Apps\\', 5) === 0) {
        $name = substr($rest, 5);                    // z. B. "Notes"
        $file = dirname(__DIR__) . '/apps/' . strtolower($name) . '/' . $name . '.php';
    } else {
        $file = __DIR__ . '/' . str_replace('\\', '/', $rest) . '.php';
    }
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';
