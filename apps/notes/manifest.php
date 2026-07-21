<?php
/** App-Manifest – von src/registry.php automatisch erkannt. */
return [
    'id'    => 'notes',
    'class' => 'Nexus\\Apps\\Notes',
    'name'  => 'Notizen',
    'desc'  => 'Gedanken, Listen & Snippets',
    'icon'  => 'note',
    'color' => '#b3893f',
    'tile'  => true,
    'min'   => 'active',   // pending | active | admin
    'order' => 30,
];
