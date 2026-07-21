<?php
/** App-Manifest – von src/registry.php automatisch erkannt. */
return [
    'id'    => 'admin',
    'class' => 'Nexus\\Apps\\Admin',
    'name'  => 'Verwaltung',
    'desc'  => 'Nutzer, Freischaltung & Quota',
    'icon'  => 'shield',
    'color' => '#c25a5a',
    'tile'  => true,
    'min'   => 'admin',   // pending | active | admin
    'order' => 90,
];
