<?php

declare(strict_types=1);

namespace Stimmwerk\Domain;

use Stimmwerk\Core\Clock;
use Stimmwerk\Core\Database;

/**
 * Grunddaten: Kategorien über das gesamte politische Spektrum sowie neutral
 * formulierte Startthemen "in alle Richtungen" (Debatten der letzten Jahre),
 * damit die Plattform von Beginn an erkennbar unparteiisch ist.
 * Startthemen gehören dem System-Konto und sind als Startthema gekennzeichnet.
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

    /** [Kategorie-Slug, Ebene, Gebiet, Titel, Ziel, Begründung] */
    private const START_TOPICS = [
        ['verkehr', 'bund', null,
            'Tempolimit 130 km/h auf Autobahnen einführen',
            'Ein allgemeines Tempolimit von 130 km/h auf Bundesautobahnen.',
            'Befürworter erwarten weniger schwere Unfälle und geringeren Kraftstoffverbrauch; Gegner sehen einen Eingriff ohne ausreichenden Nutzen. Ein Stimmungsbild schafft Klarheit.'],
        ['verkehr', 'bund', null,
            'Planungs- und Genehmigungsverfahren für Infrastruktur halbieren',
            'Straßen-, Schienen- und Netzprojekte sollen in höchstens der halben bisherigen Zeit genehmigt werden.',
            'Lange Verfahren verteuern Projekte und verzögern Modernisierung. Vereinfachte Verfahren stehen in der Kritik, Beteiligungsrechte zu verkürzen.'],
        ['energie', 'bund', null,
            'Rückkehr zur Kernenergie prüfen',
            'Eine ergebnisoffene Prüfung, ob neue Kernkraftwerke oder der Weiterbetrieb bestehender Anlagen wieder Teil des Strommixes werden sollen.',
            'Nach dem Atomausstieg wird über Versorgungssicherheit, Preise und Klimabilanz weiter gestritten.'],
        ['energie', 'bund', null,
            'Ausbau von Wind- und Solarenergie beschleunigen',
            'Genehmigungen für Erneuerbare vereinfachen und Ausbauziele verbindlich absichern.',
            'Erneuerbare gelten als Schlüssel zu Klimazielen und Unabhängigkeit; Kritiker verweisen auf Netzkosten und Flächenkonflikte.'],
        ['wohnen', 'bund', null,
            'Mietpreisbremse verschärfen und entfristen',
            'Strengere Kappungsgrenzen in angespannten Wohnungsmärkten, dauerhaft statt befristet.',
            'Mieten steigen vielerorts schneller als Einkommen. Gegner warnen, dass Regulierung den Neubau bremst.'],
        ['wohnen', 'bund', null,
            'Bauvorschriften vereinfachen, um Neubau zu beschleunigen',
            'Weniger und einfachere Bauauflagen, damit schneller und günstiger gebaut werden kann.',
            'Hohe Standards und lange Verfahren verteuern das Bauen; strittig ist, welche Standards verzichtbar sind.'],
        ['arbeit-soziales', 'bund', null,
            'Mindestlohn spürbar erhöhen',
            'Anhebung des gesetzlichen Mindestlohns über die Beschlüsse der Mindestlohnkommission hinaus.',
            'Befürworter sehen Kaufkraft und Existenzsicherung, Gegner Risiken für Beschäftigung und Tarifautonomie.'],
        ['arbeit-soziales', 'bund', null,
            'Sanktionen bei Ablehnung zumutbarer Arbeit verschärfen',
            'Wer im Bürgergeld zumutbare Arbeit wiederholt ablehnt, soll stärkere Leistungskürzungen erhalten.',
            'Die Balance zwischen Fördern und Fordern ist umstritten; das Stimmungsbild zeigt, wo die Mehrheit steht.'],
        ['familie-jugend', 'bund', null,
            'Kindergrundsicherung einführen',
            'Bündelung von Familienleistungen zu einer automatisch ausgezahlten Grundsicherung für Kinder.',
            'Befürworter erwarten weniger Kinderarmut und Bürokratie; Kritiker bezweifeln Wirkung und Finanzierbarkeit.'],
        ['migration-integration', 'bund', null,
            'Irreguläre Migration stärker begrenzen',
            'Konsequentere Rückführungen, Verfahren an den Außengrenzen und Abkommen mit Herkunftsstaaten.',
            'Kommunen berichten von Überlastung; strittig ist, welche Maßnahmen wirksam und rechtsstaatlich sind.'],
        ['migration-integration', 'bund', null,
            'Einbürgerung und Anerkennung für Fachkräfte beschleunigen',
            'Schnellere Verfahren für Arbeitsvisa, Berufsanerkennung und Einbürgerung qualifizierter Zuwanderer.',
            'Der Arbeitskräftemangel wächst; umstritten ist, wie stark Zuwanderung ihn lösen kann.'],
        ['innere-sicherheit', 'bund', null,
            'Videoüberwachung an Bahnhöfen und Brennpunkten ausweiten',
            'Mehr Kameras mit klaren Löschfristen an Kriminalitätsschwerpunkten.',
            'Befürworter erwarten Abschreckung und Aufklärung; Kritiker sehen Eingriffe in die Privatsphäre bei begrenztem Nutzen.'],
        ['justiz-buergerrechte', 'bund', null,
            'Anlasslose Vorratsdatenspeicherung dauerhaft ausschließen',
            'Verbindungsdaten aller Bürger sollen nicht ohne konkreten Anlass gespeichert werden dürfen.',
            'Gerichte haben anlasslose Speicherung mehrfach begrenzt; Sicherheitsbehörden fordern dennoch Zugriffsmöglichkeiten.'],
        ['digitales', 'bund', null,
            'Alle Verwaltungsleistungen vollständig online anbieten',
            'Jede Behördenleistung soll digital, medienbruchfrei und bundesweit einheitlich verfügbar sein.',
            'Die Digitalisierung der Verwaltung kommt langsamer voran als geplant; Präsenzwege sollen erhalten bleiben.'],
        ['gesundheit-pflege', 'bund', null,
            'Cannabis-Teillegalisierung zurücknehmen',
            'Besitz und Anbau von Cannabis sollen wieder umfassend verboten werden.',
            'Nach der Teillegalisierung stehen Jugendschutz und Entlastung der Justiz gegen gesundheitliche Bedenken.'],
        ['gesundheit-pflege', 'bund', null,
            'Bezahlung und Personalschlüssel in der Pflege verbessern',
            'Verbindliche Personaluntergrenzen und bessere Vergütung in Kranken- und Altenpflege.',
            'Der Personalmangel in der Pflege ist unbestritten; offen ist die Finanzierung.'],
        ['verteidigung', 'bund', null,
            'Wehrpflicht wieder einführen',
            'Rückkehr zu einer allgemeinen Dienstpflicht (militärisch oder zivil).',
            'Die Bundeswehr sucht Personal, die sicherheitspolitische Lage hat sich verändert; ein Pflichtdienst greift zugleich in Lebensläufe ein.'],
        ['verteidigung', 'bund', null,
            'Verteidigungsausgaben dauerhaft bei mindestens 2 % des BIP halten',
            'Das NATO-Ziel von 2 % soll gesetzlich abgesichert werden.',
            'Befürworter sehen Bündnisfähigkeit, Kritiker konkurrierende Prioritäten im Haushalt.'],
        ['finanzen-steuern', 'bund', null,
            'Schuldenbremse für Investitionen reformieren',
            'Kreditfinanzierte Zukunftsinvestitionen (Infrastruktur, Bildung, Klima) sollen von der Schuldenbremse ausgenommen werden können.',
            'Investitionsstau trifft auf Sorge vor wachsender Staatsverschuldung.'],
        ['finanzen-steuern', 'bund', null,
            'Schuldenbremse unverändert einhalten',
            'Die geltenden Kreditgrenzen des Grundgesetzes sollen ohne Ausnahmen bestehen bleiben.',
            'Solide Haushalte schützen kommende Generationen; Kritiker halten die Regel für zu starr.'],
        ['landwirtschaft', 'bund', null,
            'Steuervergünstigung für Agrardiesel wieder einführen',
            'Landwirtschaftliche Betriebe sollen die Rückvergütung auf Diesel dauerhaft zurückerhalten.',
            'Die Streichung führte zu breiten Protesten; strittig ist die Vereinbarkeit mit Klimazielen.'],
        ['umwelt-klima', 'bund', null,
            'Klimageld auszahlen',
            'Einnahmen aus dem CO2-Preis sollen als Pro-Kopf-Zahlung an alle zurückfließen.',
            'Das Klimageld ist angekündigt, aber nicht umgesetzt; es soll CO2-Preise sozial ausgleichen.'],
        ['europa-aussen', 'bund', null,
            'Schutz der EU-Außengrenzen ausbauen',
            'Mehr Personal und Technik für Frontex sowie beschleunigte Verfahren an den Außengrenzen.',
            'Kontrolle der Außengrenzen gilt als Voraussetzung offener Binnengrenzen; humanitäre Standards müssen gewahrt bleiben.'],
        ['demokratie', 'bund', null,
            'Volksentscheide auf Bundesebene einführen',
            'Bürger sollen über Grundsatzfragen auch zwischen Wahlen verbindlich abstimmen können.',
            'Mehr direkte Beteiligung steht gegen die Sorge vor Vereinfachung komplexer Fragen.'],
        ['rente', 'bund', null,
            'Renteneintrittsalter nicht weiter anheben',
            'Das gesetzliche Renteneintrittsalter soll bei 67 Jahren gedeckelt bleiben.',
            'Längere Lebensarbeitszeit entlastet die Rentenkasse, belastet aber körperlich arbeitende Berufe besonders.'],
        ['bildung-forschung', 'bundesland', 'Nordrhein-Westfalen',
            'Ganztagsbetreuung an Grundschulen flächendeckend ausbauen',
            'Jedes Grundschulkind soll einen wohnortnahen Ganztagsplatz erhalten.',
            'Der Rechtsanspruch ab 2026 trifft auf Personal- und Raummangel.'],
        ['energie', 'bundesland', 'Bayern',
            'Abstandsregeln für Windkraft lockern',
            'Die pauschalen Mindestabstände für Windräder sollen reduziert werden.',
            'Strenge Abstandsregeln gelten als Haupthemmnis des Windausbaus; Anwohner fürchten Beeinträchtigungen.'],
        ['verkehr', 'landkreis', 'Landkreis Harburg',
            'ÖPNV im ländlichen Raum ausbauen',
            'Stundentakt auf Hauptachsen und bedarfsgesteuerte Rufbusse in der Fläche.',
            'Ohne Auto ist Mobilität auf dem Land kaum möglich; Finanzierung und Auslastung sind die Streitpunkte.'],
        ['verkehr', 'kommune', 'Leipzig',
            'Radwegenetz ausbauen und sicherer machen',
            'Ein durchgängiges, baulich getrenntes Radwegenetz auf den Hauptrouten.',
            'Mehr Radverkehr entlastet Straßen und Umwelt; Parkplätze und Fahrspuren stehen zur Diskussion.'],
        ['kommunales', 'kommune', 'München',
            'Grundsteuer-Hebesatz senken',
            'Die Kommune soll den Hebesatz der Grundsteuer B spürbar senken.',
            'Nach der Grundsteuerreform steigen vielerorts die Belastungen; zugleich brauchen Kommunen stabile Einnahmen.'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** Legt Kategorien, System-Konto und Startthemen an, falls noch leer. */
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
            $this->db->run(
                'INSERT INTO users (pseudonym_hash, lang, is_system, created_at) VALUES (?, ?, 1, ?)',
                ['system', 'de', $now]
            );
            $systemId = $this->db->lastInsertId();

            // Ein Startthema pro Tag rückwärts datiert – erfüllt auch für das
            // System-Konto die 1-Thema-pro-Tag-Invariante der Datenbank.
            $count = count(self::START_TOPICS);
            foreach (self::START_TOPICS as $i => [$slug, $level, $scopeName, $title, $goal, $reasoning]) {
                $categoryId = (int) $this->db->val('SELECT id FROM categories WHERE slug = ?', [$slug]);
                $daysBack = $count - $i;
                $createdAt = Clock::addDaysStr($now, -$daysBack);
                $this->db->run(
                    'INSERT INTO topics (author_id, title, goal, reasoning, category_id,
                                         scope_level, scope_name, created_at, created_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$systemId, $title, $goal, $reasoning, $categoryId, $level, $scopeName,
                     $createdAt, substr($createdAt, 0, 10)]
                );
            }
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
