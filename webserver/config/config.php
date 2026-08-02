<?php

declare(strict_types=1);

/**
 * Stimmwerk – zentrale Konfiguration.
 *
 * Sicherheitsrelevante Grenzwerte werden ausschließlich hier definiert und
 * serverseitig durchgesetzt. Der Server-Pepper (HMAC-Geheimnis für
 * Pseudonym-Hashes) liegt bewusst NICHT in dieser Datei: er wird beim ersten
 * Start kryptographisch erzeugt und unter data/secret.key (0600) abgelegt.
 */
return [
    'app_name' => 'Stimmwerk',
    'domain'   => 'stimmwerk.de',

    // Testbetrieb-Banner: solange true, zeigt jede Seite den Hinweis, dass dies
    // eine Test-/Entwicklungsversion und keine offizielle Seite der
    // Bundesregierung oder einer Behörde ist. Erst für einen echten,
    // abgenommenen Betrieb auf false setzen.
    'show_test_banner' => true,

    // Identitätsprüfung: 'mock' = simulierte Testkarten (nur Entwicklung),
    // 'tr03130' = echter eID-Server nach BSI TR-03130 (Ausbaustufe).
    'eid_provider' => 'mock',

    'timezone'     => 'Europe/Berlin',
    'default_lang' => 'de',
    'langs'        => ['de', 'en'],

    // Bürger-Jury
    'jury_share'         => 0.01,  // 1 % der Nutzerschaft je Meldung
    'jury_min'           => 5,     // Mindest-Jurygröße
    'quorum_share'       => 0.005, // 0,5 % der Nutzerschaft als Quorum
    'quorum_min'         => 3,     // Mindest-Quorum
    'report_vote_hours'  => 24,    // reguläre Abstimmungsdauer nach Start (00:00)
    'jury_cooldown_days' => 3,     // Karenz nach abgeschlossener Jury-Runde
    'reports_per_day'    => 3,     // Meldungen je Pseudonym und Tag

    // Sitzungen (öffentliche Terminals!)
    'session_idle_minutes' => 30,
    'session_max_hours'    => 8,

    // Anzeige
    'page_size' => 20,

    // Pfade (außerhalb des Webroots)
    'data_dir' => dirname(__DIR__) . '/data',
    'db_path'  => getenv('STIMMWERK_DB') ?: dirname(__DIR__) . '/data/stimmwerk.sqlite',
];
