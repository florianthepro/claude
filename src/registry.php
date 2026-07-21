<?php
/**
 * Zentrale App-Registry. Jede On-Site-App wird hier deklariert; neue Apps
 * lassen sich durch einen Eintrag + eine Klasse unter src/Apps/ ergänzen.
 *
 * Felder:
 *   name    Anzeigename
 *   desc    Kurzbeschreibung (Kachel)
 *   icon    Icon-Name (siehe Core\Icons)
 *   color   Akzent für Kachel/Punkt
 *   tile    Auf der Startseite als Kachel zeigen?
 *   class   App-Klasse (Nexus\Apps\...)
 *   min     Mindest-Status: 'pending' (jeder eingeloggte) | 'active' | 'admin'
 */
declare(strict_types=1);

function nx_apps(): array
{
    static $apps = null;
    if ($apps !== null) {
        return $apps;
    }
    return $apps = [
        'home' => [
            'name' => 'Startseite', 'desc' => 'Übersicht & Schnellzugriffe',
            'icon' => 'grid', 'color' => '#4d7ea8', 'tile' => false,
            'class' => 'Nexus\\Apps\\Home', 'min' => 'pending',
        ],
        'mail' => [
            'name' => 'Mail', 'desc' => 'Postfach & interne Tickets',
            'icon' => 'mail', 'color' => '#4d7ea8', 'tile' => true,
            'class' => 'Nexus\\Apps\\Mail', 'min' => 'pending',
        ],
        'notes' => [
            'name' => 'Notizen', 'desc' => 'Gedanken, Listen & Snippets',
            'icon' => 'note', 'color' => '#b3893f', 'tile' => true,
            'class' => 'Nexus\\Apps\\Notes', 'min' => 'active',
        ],
        'tasks' => [
            'name' => 'Aufgaben', 'desc' => 'To-dos mit Fälligkeit',
            'icon' => 'check', 'color' => '#4a9d6f', 'tile' => true,
            'class' => 'Nexus\\Apps\\Tasks', 'min' => 'active',
        ],
        'calendar' => [
            'name' => 'Kalender', 'desc' => 'Termine & Ereignisse',
            'icon' => 'calendar', 'color' => '#c25a5a', 'tile' => true,
            'class' => 'Nexus\\Apps\\Calendar', 'min' => 'active',
        ],
        'contacts' => [
            'name' => 'Kontakte', 'desc' => 'Adressbuch',
            'icon' => 'user', 'color' => '#8a7fb0', 'tile' => true,
            'class' => 'Nexus\\Apps\\Contacts', 'min' => 'active',
        ],
        'files' => [
            'name' => 'Dateien', 'desc' => 'Persönlicher Speicher',
            'icon' => 'folder', 'color' => '#4a8ca0', 'tile' => true,
            'class' => 'Nexus\\Apps\\Files', 'min' => 'active',
        ],
        'bookmarks' => [
            'name' => 'Lesezeichen', 'desc' => 'Links & Kacheln',
            'icon' => 'link', 'color' => '#4d7ea8', 'tile' => true,
            'class' => 'Nexus\\Apps\\Bookmarks', 'min' => 'active',
        ],
        'admin' => [
            'name' => 'Verwaltung', 'desc' => 'Nutzer, Freischaltung & Quota',
            'icon' => 'shield', 'color' => '#c25a5a', 'tile' => true,
            'class' => 'Nexus\\Apps\\Admin', 'min' => 'admin',
        ],
        'settings' => [
            'name' => 'Einstellungen', 'desc' => 'Profil, Konten & Aussehen',
            'icon' => 'cog', 'color' => '#7a828e', 'tile' => true,
            'class' => 'Nexus\\Apps\\Settings', 'min' => 'pending',
        ],
    ];
}
