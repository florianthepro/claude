<?php
/**
 * Nexus – Einstiegspunkt & Setup.
 *
 * Diese Datei ist alles, was im Webroot liegen muss. Sie lädt die Engine aus
 * src/ und die Apps aus apps/, legt beim ersten Aufruf Datenverzeichnisse,
 * Datenbank und das erste (Admin-)Konto an ("Setup-Interface" wie in der
 * Single-File-Version) und dient anschließend als Front Controller.
 *
 * Voraussetzung: Apache + PHP (>= 7.4, pdo_sqlite). Mehr nicht.
 */
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

// Statische Assets (CSS/JS) werden über setup.php ausgeliefert – so bleibt das
// Wurzelverzeichnis schlank und src/ kann komplett gesperrt werden.
if (isset($_GET['asset'])) {
    $which = ($_GET['asset'] === 'js') ? 'js' : 'css';
    header('Content-Type: ' . ($which === 'js' ? 'application/javascript' : 'text/css') . '; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile(NX_SRC . '/assets/app.' . $which);
    exit;
}

// Setup-Gate: fehlen zwingende Voraussetzungen, klar melden statt zu crashen.
if ($missing = nx_requirements()) {
    nx_setup_page($missing);
    exit;
}

nx_bootstrap();
Nexus\Core\Kernel::handle();
