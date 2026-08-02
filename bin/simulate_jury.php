<?php

declare(strict_types=1);

// Vorführ-Hilfe: gibt für laufende Meldungen die Stimmen der DEMO-Pseudonyme
// (is_seed = 1) zufällig ab, damit sich das Jury-Verfahren zeigen lässt.
// Nur CLI und nur im Mock-Modus. Echte Nutzer sind nie betroffen.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$factory = require dirname(__DIR__) . '/src/bootstrap.php';
$app = $factory();

if (!$app->eid->isMock()) {
    fwrite(STDERR, "Abbruch: Simulation ist nur im Mock-Modus erlaubt.\n");
    exit(1);
}

$app->maintenance->tick();
$seats = $app->db->all(
    "SELECT rj.report_id, rj.user_id
     FROM report_jurors rj
     JOIN reports r ON r.id = rj.report_id
     JOIN users u   ON u.id = rj.user_id
     WHERE r.status = 'voting' AND rj.vote IS NULL AND u.is_seed = 1"
);

$cast = 0;
foreach ($seats as $seat) {
    if (random_int(1, 100) > 80) {
        continue; // ein Teil der Jury stimmt (noch) nicht ab
    }
    $roll = random_int(1, 100);
    $vote = $roll <= 55 ? 'confirm' : ($roll <= 85 ? 'reject' : 'neutral');
    try {
        $app->jury->castVote((int) $seat['report_id'], (int) $seat['user_id'], $vote);
        $cast++;
    } catch (DomainException $e) {
        // Meldung wurde zwischenzeitlich entschieden – unkritisch.
    }
}
printf("Simulierte Jury-Stimmen: %d\n", $cast);
