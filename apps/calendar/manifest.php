<?php
/** App-Manifest – von src/registry.php automatisch erkannt. */
return [
    'id'    => 'calendar',
    'class' => 'Nexus\\Apps\\Calendar',
    'name'  => 'Kalender',
    'desc'  => 'Termine & Ereignisse',
    'icon'  => 'calendar',
    'color' => '#c25a5a',
    'tile'  => true,
    'min'   => 'active',   // pending | active | admin
    'order' => 50,
];
