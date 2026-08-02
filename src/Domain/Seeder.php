<?php

declare(strict_types=1);

namespace Stimmwerk\Domain;

use Stimmwerk\Core\Clock;
use Stimmwerk\Core\Database;

/**
 * Grunddaten: ausschließlich Kategorien über das gesamte politische Spektrum,
 * damit die Plattform von Beginn an erkennbar unparteiisch ist. Themen werden
 * nicht vorbefüllt – alle Inhalte kommen aus der Bürgerschaft.
 */
final class Seeder
{
    private const CATEGORIES = [
        ['umwelt-klima',        'Umwelt & Klima',            'Environment & Climate'],
        ['energie',             'Energie',                   'Energy'],
        ['wirtschaft',          'Wirtschaft & Mittelstand',  'Economy & Business'],
        ['arbeit-soziales',     'Arbeit & Soziales',         'Labour & Social Affairs'],
        ['rente',               'Rente & Alterssicherung',   'Pensions'],
        ['gesundheit-pflege',   'Gesundheit & Pflege',       'Health & Care'],
        ['bildung-forschung',   'Bildung & Forschung',       'Education & Research'],
        ['familie-jugend',      'Familie & Jugend',          'Family & Youth'],
        ['migration-integration', 'Migration & Integration', 'Migration & Integration'],
        ['innere-sicherheit',   'Innere Sicherheit',         'Domestic Security'],
        ['justiz-buergerrechte', 'Justiz & Bürgerrechte',    'Justice & Civil Rights'],
        ['digitales',           'Digitales & Verwaltung',    'Digital Affairs & Administration'],
        ['verkehr',             'Verkehr & Infrastruktur',   'Transport & Infrastructure'],
        ['wohnen',              'Wohnen & Mieten',           'Housing & Rents'],
        ['landwirtschaft',      'Landwirtschaft & Ernährung', 'Agriculture & Food'],
        ['finanzen-steuern',    'Finanzen & Steuern',        'Finance & Taxes'],
        ['europa-aussen',       'Europa & Außenpolitik',     'Europe & Foreign Policy'],
        ['verteidigung',        'Verteidigung',              'Defence'],
        ['kultur-medien-sport', 'Kultur, Medien & Sport',    'Culture, Media & Sports'],
        ['verbraucherschutz',   'Verbraucherschutz',         'Consumer Protection'],
        ['kommunales',          'Kommunales & Ehrenamt',     'Local Affairs & Volunteering'],
        ['demokratie',          'Demokratie & Beteiligung',  'Democracy & Participation'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Legt Kategorien und das System-Konto an, falls noch leer.
     * Bewusst keine vorbefüllten Themen: Zum Start existieren nur die
     * neutralen Kategorien; alle Inhalte kommen aus der Bürgerschaft.
     */
    public function baselineIfEmpty(): void
    {
        if ((int) $this->db->val('SELECT COUNT(*) FROM categories') > 0) {
            return;
        }
        $this->db->tx(function (): void {
            $now = Clock::nowStr();
            foreach (self::CATEGORIES as $i => [$slug, $de, $en]) {
                $this->db->run(
                    'INSERT INTO categories (slug, name_de, name_en, sort_order) VALUES (?, ?, ?, ?)',
                    [$slug, $de, $en, $i]
                );
            }
            // System-Konto: Ziel für DSGVO-entkoppelte Beiträge, nie anmeldbar.
            $this->db->run(
                'INSERT INTO users (pseudonym_hash, lang, is_system, created_at) VALUES (?, ?, 1, ?)',
                ['system', 'de', $now]
            );
        });
    }

    /**
     * Demo-Population für Vorführungen: simulierte Ausweis-Pseudonyme samt
     * zufälliger Stimmen. Nur über die CLI und nur im Mock-Modus aufrufbar.
     */
    public function demoPopulation(int $userCount = 400): array
    {
        $created = 0;
        $votes = 0;
        $this->db->tx(function () use ($userCount, &$created, &$votes): void {
            $now = Clock::nowStr();
            for ($i = 0; $i < $userCount; $i++) {
                $hash = 'seed-' . bin2hex(random_bytes(28));
                $this->db->run(
                    'INSERT INTO users (pseudonym_hash, lang, is_seed, created_at) VALUES (?, ?, 1, ?)',
                    [$hash, 'de', $now]
                );
                $created++;
            }
            $seedIds = array_map(
                static fn (array $r): int => (int) $r['id'],
                $this->db->all('SELECT id FROM users WHERE is_seed = 1')
            );
            $topics = $this->db->all("SELECT id FROM topics WHERE status = 'active'");
            foreach ($topics as $topic) {
                $turnout = random_int(15, 60) / 100;
                $forShare = random_int(25, 75) / 100;
                foreach ($seedIds as $userId) {
                    if (random_int(1, 100) > (int) ($turnout * 100)) {
                        continue;
                    }
                    $choice = random_int(1, 100) <= (int) ($forShare * 100) ? 'for' : 'against';
                    $this->db->run(
                        'INSERT OR IGNORE INTO votes (topic_id, user_id, choice, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?)',
                        [(int) $topic['id'], $userId, $choice, $now, $now]
                    );
                    $votes++;
                }
            }
        });
        return ['users' => $created, 'votes' => $votes];
    }
}
