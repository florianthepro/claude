<?php
/**
 * App-Registry – erkennt Apps automatisch anhand von apps/<id>/manifest.php.
 * Eine neue App = ein Ordner unter apps/ mit manifest.php + Klasse. Kein
 * zentraler Eintrag nötig.
 */
declare(strict_types=1);

function nx_apps(): array
{
    static $apps = null;
    if ($apps !== null) {
        return $apps;
    }
    $dir = dirname(__DIR__) . '/apps';
    $list = [];
    foreach (glob($dir . '/*/manifest.php') ?: [] as $manifest) {
        $m = require $manifest;
        if (is_array($m) && isset($m['id'], $m['class'])) {
            $list[$m['id']] = $m + ['tile' => false, 'min' => 'active', 'order' => 100, 'icon' => 'grid', 'color' => '#4d7ea8'];
        }
    }
    uasort($list, static fn($a, $b) => ($a['order'] ?? 100) <=> ($b['order'] ?? 100));
    return $apps = $list;
}
