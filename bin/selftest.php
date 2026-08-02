<?php

declare(strict_types=1);

/**
 * Selbsttest der Fachlogik gegen Wegwerf-Datenbanken:
 * Tagesgrenze, Stimmen, Favoriten, Jury-Auslosung (Ausschlüsse, Karenz),
 * Fristen (00:00-Start, 24 h), Quorum und Entscheidung.
 * Aufruf: php bin/selftest.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

use Stimmwerk\Core\Clock;
use Stimmwerk\Domain\Seeder;

$tmpDir = sys_get_temp_dir() . '/stimmwerk-selftest-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);

$pass = 0;
$fail = 0;
$check = static function (string $description, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  ok  {$description}\n";
    } else {
        $fail++;
        echo "FAIL  {$description}\n";
    }
};

$factory = require dirname(__DIR__) . '/src/bootstrap.php';
$newApp = static function (string $name) use ($factory, $tmpDir): Stimmwerk\App {
    putenv('STIMMWERK_DB=' . $tmpDir . '/' . $name . '.sqlite');
    return $factory();
};
$addUsers = static function (Stimmwerk\App $app, int $count, string $prefix): array {
    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
        $app->db->run(
            'INSERT INTO users (pseudonym_hash, lang, created_at) VALUES (?, ?, ?)',
            [$prefix . '-' . $i, 'de', Clock::nowStr()]
        );
        $ids[] = $app->db->lastInsertId();
    }
    return $ids;
};

// Feste Startzeit: 12:00 lokale Zeit, damit "nächste Mitternacht" eindeutig ist.
$t0 = new DateTimeImmutable('2026-03-02 12:00:00', new DateTimeZone('Europe/Berlin'));
Clock::setTestNow($t0);
$warp = static function (string $modify) use (&$t0): void {
    $t0 = $t0->modify($modify);
    Clock::setTestNow($t0);
};

echo "== Grunddaten ==\n";
$app = $newApp('base');
$check('Kategorien angelegt', count($app->topics->categories()) >= 20);
$check('Startthemen angelegt', $app->topics->stats()['topics'] >= 25);
$check('System-Konto vorhanden', (int) $app->db->val('SELECT COUNT(*) FROM users WHERE is_system = 1') === 1);

echo "== Themen: 1 pro Tag ==\n";
[$alice] = $addUsers($app, 1, 'alice');
$categoryId = (int) $app->db->val('SELECT id FROM categories ORDER BY id LIMIT 1');
$topicId = $app->topics->create($alice, 'Testthema Nummer eins', 'Ein Ziel für den Selbsttest.', 'Eine Begründung für den Selbsttest.', $categoryId, 'bund', null);
$check('Erstes Thema angelegt', $topicId > 0);
try {
    $app->topics->create($alice, 'Zweites Thema am selben Tag', 'Noch ein Ziel für den Test.', 'Noch eine Begründung dazu.', $categoryId, 'kommune', 'Leipzig');
    $check('Zweites Thema am selben Tag abgelehnt', false);
} catch (DomainException $e) {
    $check('Zweites Thema am selben Tag abgelehnt', $e->getMessage() === 'flash.topic_daily_limit');
}
$warp('+1 day');
$secondTopic = $app->topics->create($alice, 'Thema am nächsten Tag', 'Ziel des zweiten Themas.', 'Begründung des zweiten Themas.', $categoryId, 'bundesland', 'Bayern');
$check('Thema am Folgetag erlaubt', $secondTopic > 0);

echo "== Stimmen ==\n";
[$bob] = $addUsers($app, 1, 'bob');
$app->votes->cast($bob, $topicId, 'for');
$check('Stimme dafür gespeichert', $app->topics->userVote($topicId, $bob) === 'for');
$app->votes->cast($bob, $topicId, 'against');
$check('Stimme änderbar', $app->topics->userVote($topicId, $bob) === 'against');
$found = $app->topics->find($topicId);
$check('Zähler korrekt', (int) $found['votes_against'] === 1 && (int) $found['votes_for'] === 0);
$app->votes->cast($bob, $topicId, 'none');
$check('Stimme zurückziehbar (neutral = keine Stimme)', $app->topics->userVote($topicId, $bob) === null);

echo "== Favoriten ==\n";
$slug = (string) $app->db->val('SELECT slug FROM categories ORDER BY id LIMIT 1');
$check('Favorit angelegt', $app->favorites->toggle($bob, 'category', $slug) === true);
$check('Favorit entfernt', $app->favorites->toggle($bob, 'category', $slug) === false);
try {
    $app->favorites->toggle($bob, 'category', 'gibt-es-nicht');
    $check('Ungültiger Favorit abgelehnt', false);
} catch (DomainException $e) {
    $check('Ungültiger Favorit abgelehnt', true);
}

echo "== Jury-Größe (1 %-Regel) ==\n";
$big = $newApp('big');
$addUsers($big, 600, 'crowd');
[$reporter600] = $addUsers($big, 1, 'rep');
$targetTopic = (int) $big->db->val("SELECT id FROM topics WHERE status = 'active' ORDER BY id LIMIT 1");
$big->reports->create($targetTopic, $reporter600, ['volksverhetzung'], null);
$bigReport = $big->db->one('SELECT * FROM reports ORDER BY id DESC LIMIT 1');
$check('Jury = 1 % bei 601 Nutzenden (7 Sitze, aufgerundet)', (int) $bigReport['jury_size'] === (int) ceil(601 * 0.01));
$check('Quorum = 0,5 % (mind. 3)', (int) $bigReport['quorum'] === max(3, (int) ceil(601 * 0.005)));

echo "== Meldung & Jury: Ausschlüsse, Fristen, Karenz ==\n";
$j = $newApp('jury');
$users = $addUsers($j, 12, 'u');
$topicRows = $j->db->all("SELECT id FROM topics WHERE status = 'active' ORDER BY id LIMIT 4");
[$tX, $tY, $tZ, $tW] = array_map(static fn (array $r): int => (int) $r['id'], $topicRows);
$jurorsOf = static fn (int $reportId): array => array_map(
    static fn (array $r): int => (int) $r['user_id'],
    $j->db->all('SELECT user_id FROM report_jurors WHERE report_id = ?', [$reportId])
);

$r1 = $j->reports->create($tX, $users[0], ['kennzeichen', 'gewalt'], 'Testmeldung.');
$j1 = $jurorsOf($r1);
$check('Jury 1: 5 Sitze (Mindestgröße)', count($j1) === 5);
$check('Jury 1: Melder nicht in der Jury', !in_array($users[0], $j1, true));
$r1Row = $j->db->one('SELECT * FROM reports WHERE id = ?', [$r1]);
$check('Meldung wartet bis Mitternacht', $r1Row['status'] === 'pending');
$expectedStart = Clock::nextLocalMidnightUtcStr();
$check('Start zur nächsten Mitternacht (00:00 lokal)', $r1Row['voting_starts_at'] === $expectedStart);

try {
    $j->reports->create($tX, $users[1], ['beleidigung'], null);
    $check('Zweite Meldung zum selben Thema abgelehnt', false);
} catch (DomainException $e) {
    $check('Zweite Meldung zum selben Thema abgelehnt', $e->getMessage() === 'flash.report_already_open');
}

$r2 = $j->reports->create($tY, $users[1], ['bedrohung'], null);
$j2 = $jurorsOf($r2);
$check('Jury 2 disjunkt zu laufender Jury 1', array_intersect($j1, $j2) === []);

$check('Kein Jury-Gate vor Abstimmungsstart', $j->jury->pendingDutyFor($j1[0]) === null);
$warp('+1 day'); // über Mitternacht
$j->jury->processDue();
$check('Abstimmung um 00:00 gestartet', $j->db->val('SELECT status FROM reports WHERE id = ?', [$r1]) === 'voting');
$check('Jury-Gate nach Start aktiv', $j->jury->pendingDutyFor($j1[0]) !== null);

$j->jury->castVote($r1, $j1[0], 'confirm');
$j->jury->castVote($r1, $j1[1], 'confirm');
$j->jury->castVote($r1, $j1[2], 'neutral');
$j->jury->processDue();
$check('Keine Entscheidung vor Ablauf der 24 h', $j->db->val('SELECT status FROM reports WHERE id = ?', [$r1]) === 'voting');
$check('Kein Doppel-Gate nach eigener Stimme', $j->jury->pendingDutyFor($j1[0]) === null);
try {
    $j->jury->castVote($r1, $j1[0], 'reject');
    $check('Doppelte Jury-Stimme abgelehnt', false);
} catch (DomainException $e) {
    $check('Doppelte Jury-Stimme abgelehnt', $e->getMessage() === 'flash.jury_already_voted');
}

$warp('+25 hours'); // Frist (24 h) abgelaufen, Quorum (3) erfüllt
$j->jury->processDue();
$r1Row = $j->db->one('SELECT * FROM reports WHERE id = ?', [$r1]);
$check('Entscheidung nach Frist + Quorum (2:0 bestätigt)', $r1Row['status'] === 'decided_removed');
$check('Thema entfernt', $j->db->val('SELECT status FROM topics WHERE id = ?', [$tX]) === 'removed');
$cooldowns = $j->db->all(
    'SELECT jury_cooldown_until FROM users WHERE id IN (' . implode(',', array_fill(0, count($j1), '?')) . ')',
    $j1
);
$expectedCooldown = Clock::addDaysStr((string) $r1Row['decided_at'], 3);
$check('Karenz (3 Tage) für alle Jury-Mitglieder gesetzt', array_unique(array_column($cooldowns, 'jury_cooldown_until')) === [$expectedCooldown]);

// r3: Melder aus Jury 2 -> ausgeschlossen sind J1 (Karenz) + J2 (laufend) + Melder.
// Es bleiben deterministisch genau die 2 Nutzenden, die in keiner Jury waren.
$r3 = $j->reports->create($tZ, $j2[0], ['privatdaten'], null);
$j3 = $jurorsOf($r3);
$expectedJ3 = array_values(array_diff(array_map('intval', $users), $j1, $j2));
sort($j3);
sort($expectedJ3);
$check('Karenz + laufende Jurys schließen korrekt aus (Restmenge = 2)', $j3 === $expectedJ3 && count($j3) === 2);

// Quorum nicht erreicht -> Meldung läuft über die 24 h hinaus weiter.
$warp('+1 day');
$j->jury->processDue();
$j->jury->castVote($r3, $j3[0], 'reject');
$warp('+25 hours');
$j->jury->processDue();
$check('Meldung läuft weiter, bis das Quorum erreicht ist', $j->db->val('SELECT status FROM reports WHERE id = ?', [$r3]) === 'voting');
$j->jury->castVote($r3, $j3[1], 'reject'); // Quorum erreicht -> sofortige Entscheidung
$check('Entscheidung sofort bei Quorum nach Fristablauf (behalten)', $j->db->val('SELECT status FROM reports WHERE id = ?', [$r3]) === 'decided_kept');
$check('Thema bleibt bei Ablehnung bestehen', $j->db->val('SELECT status FROM topics WHERE id = ?', [$tZ]) === 'active');

// Nach Ablauf der Karenz ist Jury 1 wieder losbar; alle anderen sind gebunden
// (Jury 2 stimmt noch ab, Jury 3 ist selbst frisch in der Karenz).
$warp('+2 days');
$r4 = $j->reports->create($tW, $j2[1], ['sonstiges'], null);
$j4 = $jurorsOf($r4);
sort($j1);
sort($j4);
$check('Nach 3 Tagen Karenz wieder losbar (Jury 4 = frühere Jury 1)', $j4 === $j1);

echo "== Kontolöschung (DSGVO) ==\n";
$app2 = $newApp('gdpr');
[$carol] = $addUsers($app2, 1, 'carol');
$catId2 = (int) $app2->db->val('SELECT id FROM categories ORDER BY id LIMIT 1');
$carolTopic = $app2->topics->create($carol, 'Thema von Carol zum Löschen', 'Ziel des Themas von Carol.', 'Begründung des Themas von Carol.', $catId2, 'bund', null);
$firstTopic = (int) $app2->db->val("SELECT id FROM topics WHERE status = 'active' AND id != ? ORDER BY id LIMIT 1", [$carolTopic]);
$app2->votes->cast($carol, $firstTopic, 'for');
$app2->favorites->toggle($carol, 'scope', 'bundesland:Bayern');
$app2->account->deleteAccount($carol);
$check('Nutzer gelöscht', $app2->db->val('SELECT COUNT(*) FROM users WHERE id = ?', [$carol]) === 0);
$check('Stimmen gelöscht', $app2->db->val('SELECT COUNT(*) FROM votes WHERE user_id = ?', [$carol]) === 0);
$check('Favoriten gelöscht', $app2->db->val('SELECT COUNT(*) FROM favorites WHERE user_id = ?', [$carol]) === 0);
$systemId = (int) $app2->db->val('SELECT id FROM users WHERE is_system = 1');
$check('Thema entkoppelt (System-Konto)', (int) $app2->db->val('SELECT author_id FROM topics WHERE id = ?', [$carolTopic]) === $systemId);

Clock::setTestNow(null);
putenv('STIMMWERK_DB');
array_map('unlink', glob($tmpDir . '/*') ?: []);
rmdir($tmpDir);

printf("\nErgebnis: %d bestanden, %d fehlgeschlagen.\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
