<?php

declare(strict_types=1);

/**
 * Baut data.zip fuer die Buergerabstimmung.
 *
 * Der Ordner data/ wird nicht von Hand zusammengesetzt, sondern von der
 * Anwendung selbst angelegt: index.php wird in ein Arbeitsverzeichnis kopiert
 * und dort einmal ueber die Kommandozeile aufgerufen. So entstehen Schema,
 * Kategorien und Systemnutzer genau so, wie die laufende Seite sie erwartet.
 * Erst danach werden die Testthemen eingetragen.
 *
 * Aufruf:  php build.php --index /pfad/zu/index.php [--out data.zip]
 */

const AUTOR_PRAEFIX   = 'seed-';
const ZEITZONE        = 'Europe/Berlin';
const THEMEN_SOLL     = 500;
const STREUUNG_TAGE   = 240;   // Themen werden ueber so viele Tage rueckwaerts verteilt
const LAUFZEIT_MIN    = 45;    // frueheste Frist, in Tagen ab heute
const LAUFZEIT_MAX    = 330;   // spaeteste Frist, in Tagen ab heute

function fehler(string $text): void
{
    fwrite(STDERR, 'Abbruch: ' . $text . "\n");
    exit(1);
}

function hinweis(string $text): void
{
    echo $text . "\n";
}

function rmtree(string $pfad): void
{
    if (!is_dir($pfad)) {
        return;
    }
    $eintraege = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pfad, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($eintraege as $eintrag) {
        $eintrag->isDir() ? rmdir($eintrag->getPathname()) : unlink($eintrag->getPathname());
    }
    rmdir($pfad);
}

function arg(array $argv, string $name, ?string $standard = null): ?string
{
    foreach ($argv as $i => $wert) {
        if ($wert === '--' . $name) {
            return $argv[$i + 1] ?? $standard;
        }
        if (strpos($wert, '--' . $name . '=') === 0) {
            return substr($wert, strlen($name) + 3);
        }
    }
    return $standard;
}

// ---------------------------------------------------------------- Themen laden

function themen_laden(string $ordner): array
{
    $dateien = glob($ordner . '/*.json') ?: [];
    sort($dateien);
    if ($dateien === []) {
        fehler('keine Themendateien in ' . $ordner);
    }
    $themen = [];
    $titel = [];
    foreach ($dateien as $datei) {
        $slug = basename($datei, '.json');
        $roh = json_decode((string) file_get_contents($datei), true);
        if (!is_array($roh)) {
            fehler('unlesbares JSON in ' . basename($datei) . ': ' . json_last_error_msg());
        }
        foreach ($roh as $nr => $eintrag) {
            $stelle = basename($datei) . ' #' . ($nr + 1);
            foreach (['titel', 'ziel', 'begruendung'] as $feld) {
                if (!isset($eintrag[$feld]) || trim((string) $eintrag[$feld]) === '') {
                    fehler($stelle . ': Feld ' . $feld . ' fehlt');
                }
            }
            $t = trim((string) $eintrag['titel']);
            $z = trim((string) $eintrag['ziel']);
            $b = trim((string) $eintrag['begruendung']);
            // Grenzen aus index.php: Titel 8..120, Ziel 10..500, Begruendung 10..4000
            if (mb_strlen($t) < 8 || mb_strlen($t) > 120) {
                fehler($stelle . ': Titel hat ' . mb_strlen($t) . ' Zeichen (erlaubt 8..120)');
            }
            if (mb_strlen($z) < 10 || mb_strlen($z) > 500) {
                fehler($stelle . ': Ziel hat ' . mb_strlen($z) . ' Zeichen (erlaubt 10..500)');
            }
            if (mb_strlen($b) < 10 || mb_strlen($b) > 4000) {
                fehler($stelle . ': Begruendung hat ' . mb_strlen($b) . ' Zeichen (erlaubt 10..4000)');
            }
            $schluessel = mb_strtolower($t);
            if (isset($titel[$schluessel])) {
                fehler($stelle . ': Titel doppelt (' . $titel[$schluessel] . ')');
            }
            $titel[$schluessel] = $stelle;
            $themen[] = ['slug' => $slug, 'titel' => $t, 'ziel' => $z, 'begruendung' => $b];
        }
    }
    return $themen;
}

// Die Seite schlaegt aehnliche Themen anhand gemeinsamer Woerter ab vier Zeichen
// vor (topics_similar in index.php). Titel mit nur zwei solchen Woertern reichen
// schon bei einem gemeinsamen Allerweltswort ueber die Schwelle. Das ist kein
// Fehler im Bestand, aber es sieht im Testbetrieb nach Zufall aus - deshalb der
// Hinweis.
function titel_stichworte(string $titel): array
{
    $rein = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($titel));
    $worte = [];
    foreach (preg_split('/\s+/', (string) $rein) as $wort) {
        if (mb_strlen($wort) >= 4) {
            $worte[$wort] = true;
        }
    }
    return array_slice(array_keys($worte), 0, 8);
}

function themen_pruefen(array $themen): void
{
    $worte = array_map(static fn(array $t): array => titel_stichworte($t['titel']), $themen);
    $duenn = [];
    foreach ($worte as $i => $w) {
        if (count($w) < 3) {
            $duenn[] = $themen[$i]['titel'];
        }
    }
    if ($duenn !== []) {
        hinweis('Hinweis: ' . count($duenn) . ' Titel mit weniger als drei Stichwoertern, z. B. ' . $duenn[0]);
    }
    $paare = 0;
    $anzahl = count($themen);
    for ($i = 0; $i < $anzahl; $i++) {
        for ($j = $i + 1; $j < $anzahl; $j++) {
            $gemein = count(array_intersect($worte[$i], $worte[$j]));
            if ($gemein === 0) {
                continue;
            }
            if ($gemein / max(1, min(count($worte[$i]), count($worte[$j]))) >= 0.5) {
                $paare++;
            }
        }
    }
    hinweis('Titelpaare, die die Seite als aehnlich anzeigt: ' . $paare);
}

// ------------------------------------------------------- Arbeitsordner anlegen

function daten_ordner_erzeugen(string $indexPfad, string $arbeit): string
{
    if (!is_file($indexPfad)) {
        fehler('index.php nicht gefunden: ' . $indexPfad);
    }
    mkdir($arbeit, 0755, true);
    copy($indexPfad, $arbeit . '/index.php');

    // "lists" ruft sw_setup() auf und geht dabei nicht ins Netz.
    $befehl = sprintf('cd %s && %s index.php lists 2>&1', escapeshellarg($arbeit), escapeshellarg(PHP_BINARY));
    exec($befehl, $ausgabe, $code);
    if ($code !== 0) {
        fehler("index.php lists ist fehlgeschlagen:\n" . implode("\n", $ausgabe));
    }
    $daten = $arbeit . '/data';
    if (!is_file($daten . '/buergerabstimmung.sqlite')) {
        fehler('die Anwendung hat keine Datenbank angelegt');
    }
    return $daten;
}

// ------------------------------------------------------------ Themen eintragen

function themen_eintragen(string $dbPfad, array $themen): array
{
    $db = new PDO('sqlite:' . $dbPfad, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');

    $kategorien = [];
    foreach ($db->query('SELECT id, slug FROM categories') as $zeile) {
        $kategorien[(string) $zeile['slug']] = (int) $zeile['id'];
    }

    $tz = new DateTimeZone(ZEITZONE);
    $utc = new DateTimeZone('UTC');
    $heute = new DateTimeImmutable('now', $tz);

    $anzahl = count($themen);
    $verteilung = [];

    $db->beginTransaction();

    $nutzer = $db->prepare(
        'INSERT INTO users (pseudonym_hash, lang, is_seed, created_at) VALUES (?, ?, 1, ?)'
    );
    $thema = $db->prepare(
        'INSERT INTO topics (author_id, title, goal, reasoning, category_id,
                             scope_level, scope_name, status, end_mode, end_date, end_target,
                             created_at, created_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($themen as $i => $eintrag) {
        if (!isset($kategorien[$eintrag['slug']])) {
            fehler('unbekannte Kategorie: ' . $eintrag['slug']);
        }

        // Ein Konto je Thema: die Tabelle laesst pro Konto und Tag nur ein Thema zu.
        $alter = (int) round($i * STREUUNG_TAGE / max(1, $anzahl - 1));
        $angelegtLokal = $heute->modify('-' . $alter . ' days')
            ->setTime(6 + ($i % 15), ($i * 7) % 60, ($i * 13) % 60);
        $angelegtUtc = $angelegtLokal->setTimezone($utc)->format('Y-m-d H:i:s');
        $kontoAngelegt = $angelegtLokal->modify('-' . (1 + $i % 30) . ' days')
            ->setTimezone($utc)->format('Y-m-d H:i:s');

        $nutzer->execute([AUTOR_PRAEFIX . bin2hex(random_bytes(28)), 'de', $kontoAngelegt]);
        $autorId = (int) $db->lastInsertId();

        // Frist: immer in der Zukunft, damit im Testbetrieb nichts sofort schliesst.
        $spanne = LAUFZEIT_MAX - LAUFZEIT_MIN;
        $frist = $heute->modify('+' . (LAUFZEIT_MIN + ($i * 37) % ($spanne + 1)) . ' days')
            ->format('Y-m-d');

        // Drei Arten, ein Thema zu beenden - alle drei kommen im Testbestand vor.
        $rest = $i % 20;
        if ($rest < 12) {
            $modus = 'date';
            $ziel = null;
        } elseif ($rest < 17) {
            $modus = 'count';
            $frist = null;
            $ziel = [1000, 2500, 5000, 10000, 25000][$i % 5];
        } else {
            $modus = 'both';
            $ziel = [5000, 20000, 50000][$i % 3];
        }

        $thema->execute([
            $autorId,
            $eintrag['titel'],
            $eintrag['ziel'],
            $eintrag['begruendung'],
            $kategorien[$eintrag['slug']],
            'bund',
            null,
            'active',
            $modus,
            $frist,
            $ziel,
            $angelegtUtc,
            $angelegtLokal->format('Y-m-d'),
        ]);

        $verteilung[$eintrag['slug']] = ($verteilung[$eintrag['slug']] ?? 0) + 1;
    }

    $db->commit();

    // Die Zeitmarke der Testmodus-Pruefung gehoert nicht in ein Auslieferungspaket.
    $db->exec("DELETE FROM schema_info WHERE k IN ('edit_check', 'last_tick')");

    // Ohne WAL bleibt eine einzige Datei uebrig, die sich sauber packen laesst.
    $db->exec('PRAGMA journal_mode = DELETE');
    $db->exec('VACUUM');
    $db = null;

    ksort($verteilung);
    return $verteilung;
}

// ----------------------------------------------------------- data/ aufraeumen

function daten_aufraeumen(string $daten): void
{
    // Geheimnisse gehoeren nicht in ein Paket, das viele Leute auspacken.
    // Die Anwendung legt sie beim ersten Aufruf selbst neu an.
    foreach (['secret.key', 'server_sign.key', 'app.log', 'php-error.log', 'setup.token', 'config.yaml'] as $datei) {
        @unlink($daten . '/' . $datei);
    }
    rmtree($daten . '/issued');
}

function liesmich(string $daten, int $anzahl, array $verteilung): void
{
    $zeilen = [
        'Testbestand fuer die Buergerabstimmung',
        '======================================',
        '',
        'Inhalt',
        '  buergerabstimmung.sqlite   Datenbank mit ' . $anzahl . ' Themen, ' . $anzahl . ' Autorenkonten,',
        '                             22 Kategorien und dem Systemkonto',
        '  .htaccess                  sperrt den Ordner fuer Zugriffe aus dem Netz',
        '',
        'Einbau',
        '  Das Archiv neben index.php auspacken, so dass der Ordner data/ direkt',
        '  neben index.php liegt. Der Ordner muss fuer den Webserver beschreibbar',
        '  sein. Schluessel (secret.key, server_sign.key) legt die Anwendung beim',
        '  ersten Aufruf selbst an; sie liegen bewusst nicht im Archiv.',
        '',
        'Stand der Daten',
        '  Alle Themen sind blanko: keine Stimmen, keine Favoriten, keine Meldungen,',
        '  keine Wertung im Text. Jedes Thema nennt den Gegenstand so, wie er in der',
        '  politischen Beratung heisst, dazu das zur Abstimmung stehende Vorhaben und',
        '  den Sachstand. Geltungsbereich ist durchgehend der Bund, passend zur',
        '  Einstellung nur_bund in index.php.',
        '  Die Autorenkonten sind Testkonten (is_seed = 1). Beim Beenden des',
        '  Testbetriebs loescht die Anwendung Themen und Konten selbsttaetig.',
        '',
        'Themen je Kategorie',
    ];
    foreach ($verteilung as $slug => $zahl) {
        $zeilen[] = sprintf('  %-24s %3d', $slug, $zahl);
    }
    $zeilen[] = '';
    file_put_contents($daten . '/LIESMICH.txt', implode("\n", $zeilen) . "\n");
}

function packen(string $arbeit, string $ziel): void
{
    @unlink($ziel);
    $befehl = sprintf(
        'cd %s && zip -r -q -X %s data',
        escapeshellarg($arbeit),
        escapeshellarg(realpath(dirname($ziel)) . '/' . basename($ziel))
    );
    exec($befehl, $ausgabe, $code);
    if ($code !== 0) {
        fehler("Packen fehlgeschlagen:\n" . implode("\n", $ausgabe));
    }
}

// ------------------------------------------------------------------- Ablauf

$wurzel = __DIR__;
$indexPfad = arg($argv, 'index', '');
if ((string) $indexPfad === '') {
    foreach ([$wurzel . '/index.php', $wurzel . '/../index.php'] as $kandidat) {
        if (is_file($kandidat)) {
            $indexPfad = $kandidat;
            break;
        }
    }
}
if ((string) $indexPfad === '') {
    fehler('index.php nicht gefunden. Aufruf: php build.php --index /pfad/zu/index.php');
}
$ausgabePfad = (string) arg($argv, 'out', $wurzel . '/data.zip');

$themen = themen_laden($wurzel . '/themen');
hinweis('Themen gelesen: ' . count($themen));
if (count($themen) !== THEMEN_SOLL) {
    fehler('erwartet werden genau ' . THEMEN_SOLL . ' Themen, gelesen: ' . count($themen));
}
themen_pruefen($themen);

$arbeit = sys_get_temp_dir() . '/ba-build-' . bin2hex(random_bytes(6));
rmtree($arbeit);

try {
    $daten = daten_ordner_erzeugen((string) $indexPfad, $arbeit);
    hinweis('Datenbank von der Anwendung angelegt.');
    $verteilung = themen_eintragen($daten . '/buergerabstimmung.sqlite', $themen);
    hinweis('Themen eingetragen.');
    daten_aufraeumen($daten);
    liesmich($daten, count($themen), $verteilung);
    packen($arbeit, $ausgabePfad);
    hinweis('Fertig: ' . $ausgabePfad . ' (' . number_format((float) filesize($ausgabePfad) / 1024, 1, ',', '.') . ' KiB)');
    foreach ($verteilung as $slug => $zahl) {
        hinweis(sprintf('  %-24s %3d', $slug, $zahl));
    }
} finally {
    rmtree($arbeit);
}
