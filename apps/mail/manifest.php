<?php
/** App-Manifest – von src/registry.php automatisch erkannt. */
return [
    'id'    => 'mail',
    'class' => 'Nexus\\Apps\\Mail',
    'name'  => 'Mail',
    'desc'  => 'Postfach & interne Tickets',
    'icon'  => 'mail',
    'color' => '#4d7ea8',
    'tile'  => true,
    'min'   => 'pending',   // pending | active | admin
    'order' => 20,
];
