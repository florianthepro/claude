<?php
/** App-Manifest – von src/registry.php automatisch erkannt. */
return [
    'id'    => 'settings',
    'class' => 'Nexus\\Apps\\Settings',
    'name'  => 'Einstellungen',
    'desc'  => 'Profil, Konten & Aussehen',
    'icon'  => 'cog',
    'color' => '#7a828e',
    'tile'  => true,
    'min'   => 'pending',   // pending | active | admin
    'order' => 100,
];
