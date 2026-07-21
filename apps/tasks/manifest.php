<?php
/** App-Manifest – von src/registry.php automatisch erkannt. */
return [
    'id'    => 'tasks',
    'class' => 'Nexus\\Apps\\Tasks',
    'name'  => 'Aufgaben',
    'desc'  => 'To-dos mit Fälligkeit',
    'icon'  => 'check',
    'color' => '#4a9d6f',
    'tile'  => true,
    'min'   => 'active',   // pending | active | admin
    'order' => 40,
];
