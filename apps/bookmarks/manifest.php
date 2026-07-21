<?php
/** App-Manifest – von src/registry.php automatisch erkannt. */
return [
    'id'    => 'bookmarks',
    'class' => 'Nexus\\Apps\\Bookmarks',
    'name'  => 'Lesezeichen',
    'desc'  => 'Links & Kacheln',
    'icon'  => 'link',
    'color' => '#4d7ea8',
    'tile'  => true,
    'min'   => 'active',   // pending | active | admin
    'order' => 80,
];
