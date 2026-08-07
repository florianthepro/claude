<?php
/* ============================================================================
 *  HOLLOWAY POINT TIDAL STATION — records, transferred
 *  smtstrange.com
 * ----------------------------------------------------------------------------
 *  The station held the datum from 1961 to 1997. It was required to report a
 *  single figure. From 3 November 1971 it reported two, and neither was struck.
 *  Nothing below explains this. The papers are as they were received.
 * ========================================================================== */

@ini_set('display_errors', '0');
error_reporting(0);
date_default_timezone_set('UTC');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

define('SMT_ROOT', __DIR__);

/* ---------------------------------------------------------------------------
 *  The papers do not assume they are at the root of a domain. They work from
 *  whatever directory they are put in.
 * ------------------------------------------------------------------------- */
function smt_base() {
    static $b = null;
    if ($b !== null) return $b;
    $sn = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $d  = str_replace('\\', '/', dirname($sn));
    $b  = ($d === '/' || $d === '.' || $d === '') ? '' : rtrim($d, '/');
    return $b;
}
/* every link on every page is built through this */
function u($p = '') {
    $p = ltrim((string) $p, '/');
    return smt_base() . '/' . $p;
}

/* --- the engine restores its own doors if they are taken away ------------ */
(function () {
    $ht = SMT_ROOT . '/.htaccess';
    $marker = '# smt-portable-v3';
    $have = @is_file($ht) ? (string) @file_get_contents($ht) : null;
    if ($have !== null && strpos($have, $marker) !== false) return;   // current
    if ($have !== null && strpos($have, 'smt') === false) return;      // not ours; leave it
    if (!@is_writable(SMT_ROOT)) return;
    /* no RewriteBase: the rules resolve against whatever directory this is in,
       so the papers may be put at the root or in a folder, and work either way */
    $r = $marker . "\n"
       . "Options -Indexes +FollowSymLinks -MultiViews\n"
       . "DirectoryIndex index.php\nAddDefaultCharset utf-8\n"
       . "<Files \".htaccess\">\n    Require all denied\n</Files>\n"
       . "<IfModule mod_rewrite.c>\n    RewriteEngine On\n"
       . "    RewriteRule ^index\\.html?$ ./ [R=302,L]\n"
       . "    RewriteRule (^|/)\\.git(/|$) - [F,L]\n"
       . "    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteCond %{REQUEST_FILENAME} !-d\n"
       . "    RewriteRule ^ index.php [L]\n</IfModule>\n";
    @file_put_contents($ht, $r);
})();

/* ===========================================================================
 *  DETERMINISM
 * ========================================================================= */
function smt_imul($a, $b) {
    $a &= 0xffffffff; $b &= 0xffffffff;
    $ah = ($a >> 16) & 0xffff; $al = $a & 0xffff;
    return ((($ah * $b) & 0xffff) << 16) + ($al * $b) & 0xffffffff;
}
function smt_fnv($s) {
    $h = 2166136261;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) { $h ^= ord($s[$i]); $h = smt_imul($h, 16777619); }
    return $h & 0xffffffff;
}
function smt_rng($seed) {
    $s = $seed & 0xffffffff;
    return function () use (&$s) {
        $s = ($s + 0x6D2B79F5) & 0xffffffff; $t = $s;
        $t = smt_imul($t ^ ($t >> 15), $t | 1) & 0xffffffff;
        $t = ($t ^ ($t + smt_imul($t ^ ($t >> 7), $t | 61))) & 0xffffffff;
        return (($t ^ ($t >> 14)) & 0xffffffff) / 4294967296.0;
    };
}
function smt_pick($r, $a) { return $a ? $a[(int) floor($r() * count($a)) % count($a)] : ''; }
function smt_int($r, $lo, $hi) { return $lo + (int) floor($r() * ($hi - $lo + 1)); }
function smt_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/* ===========================================================================
 *  THE WORLD
 * ========================================================================= */

$W = array(
'people' => array(
    'rell'    => array('A. Rell',    'keeper', 1961, 1979),
    'hoyle'   => array('M. Hoyle',   'surveyor', 1971, 1984),
    'beazley' => array('E. Beazley', 'for the Board', 1963, 1990),
    'tarn'    => array('J. Tarn',    'assistant keeper', 1988, 1997),
    'clerk'   => array('the clerk',  'transcribing', 1961, 1997),
),
'objects' => array(
    'BM-41', 'the north stair', 'the float', 'the gauge house', 'plate 12 of 9',
    'the brass key', 'the barograph', 'the second register', 'the datum',
),
'places' => array(
    'Holloway Point', 'the gauge house', 'the north stair', 'the light',
    'the coomb', 'the strand', 'Bearing 041',
),
);

/* twelve series, each with its own paper */
$SERIES = array(
 1 => array('Establishment and Warrant',   'admin',  'warrant'),
 2 => array('Station Orders',              'admin',  'order'),
 3 => array('Tide Registers',              'table',  'register'),
 4 => array('Benchmark Observations',      'table',  'benchmark'),
 5 => array('Instrument Dockets',          'docket', 'docket'),
 6 => array('Maintenance and Works',       'docket', 'works'),
 7 => array('Correspondence, Official',    'letter', 'official'),
 8 => array('Correspondence, Private',     'letter', 'private'),
 9 => array('Incident Papers',             'report', 'incident'),
10 => array('Transcripts, Damaged',        'damaged','transcript'),
11 => array('Photographic Plates',         'plate',  'plate'),
12 => array('Withdrawn, Retained',         'admin',  'withdrawn'),
);

function smt_person($key) { global $W; return $W['people'][$key][0]; }

/* a reference that looks like an archive reference and behaves like one */
function smt_ref($s, $sub, $item) {
    return $s . '/' . $sub . '/' . strtoupper($item);
}
function smt_item_code($seed, $series) {
    $L = array('A','B','C','D','F','K','R','T');
    return $L[($seed >> 3) % 8] . '-' . str_pad((string) (($seed % 320) + 1), 3, '0', STR_PAD_LEFT);
}

/* a date inside the station's life, stable per seed */
function smt_date($seed, $lo = 1961, $hi = 1997) {
    $r = smt_rng($seed ^ 0x5aa5);
    $y = smt_int($r, $lo, $hi);
    $m = smt_int($r, 1, 12);
    $d = smt_int($r, 1, 28);
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}
function smt_longdate($iso) {
    $t = strtotime($iso . ' UTC');
    return $t ? gmdate('j F Y', $t) : $iso;
}

/* the benchmark drift — the one number the whole archive circles */
function smt_bm_height($year) {
    if ($year < 1971) return 4.1073 - ($year - 1961) * 0.00002;
    if ($year == 1971) return 4.1412;
    return 4.1412 + ($year - 1971) * 0.00009;
}

/* ===========================================================================
 *  DOCUMENT METADATA — cheap, so the finding aid can search thousands
 * ========================================================================= */

/* Title and text are one thing. A paper is never given a heading that
   belongs to a different paper — that is the tell of a machine. */
function smt_papers($kind) {
    static $P = null;
    if ($P === null) $P = smt_paper_table();
    return isset($P[$kind]) ? $P[$kind] : $P['warrant'];
}
function smt_titles($kind) {
    $out = array();
    foreach (smt_papers($kind) as $p) $out[] = $p[0];
    return $out;
}
/* a paper is (title, body) or (title, author-key, body) where the voice is fixed */
function smt_paper_text($p) { return count($p) === 3 ? $p[2] : $p[1]; }
function smt_paper_voice($p) { return count($p) === 3 ? $p[1] : null; }

/* Papers that speak of the doubling cannot predate the night it began.
   The station was ordinary until 3 November 1971. */
function smt_post_incident($kind, $title) {
    if (in_array($kind, array('register','benchmark','docket','official','private','transcript','withdrawn'), true))
        return true;
    return in_array($title, array(
        'Instrument of Transfer', 'Retention Notice', 'Deed of Deposit',
        'Note as to Arrangement', 'Certificate of Closure', 'Warrant, Amended',
        'Memorandum of Custody', 'Schedule of Papers Received',
        'Order as to Hours', 'Order, Rescinded', 'Order as to the Second Copy',
        'Order as to Absence', 'Order, Never Issued', 'Order as to the Stair',
        'Standing Order, Consolidated',
        'Survey of the North Stair', 'Works, Completed Twice', 'Works, Estimate Only',
        'Demolition, Not Carried Out', 'Fencing of the Stair Head',
        'Plate, Withdrawn', 'Plate, Not Taken', 'Plate, Catalogue: Summary',
    ), true);
}

function smt_meta($s, $sub, $item) {
    global $SERIES, $W;
    if (!isset($SERIES[$s])) return null;
    $seed = smt_fnv('doc::' . $s . '::' . $sub . '::' . strtolower($item));
    $r    = smt_rng($seed);
    $kind = $SERIES[$s][2];

    /* who is on this paper is a function of when it was written */
    $iso  = smt_date($seed);
    $year = (int) substr($iso, 0, 4);
    if ($kind === 'incident') { $iso = '1971-11-0' . smt_int($r, 3, 9); $year = 1971; }
    $cast = array();
    foreach ($W['people'] as $k => $p) if ($year >= $p[2] && $year <= $p[3]) $cast[] = $k;
    if (!$cast) $cast = array('clerk');
    $author = $cast[$seed % count($cast)];
    if ($kind === 'official') $author = 'beazley';
    $papers = smt_papers($kind);
    $voice  = smt_paper_voice($papers[$seed % count($papers)]);
    if ($voice !== null) $author = $voice;

    /* a paper cannot be dated outside the hand that signed it, nor before
       the night the thing it describes began */
    if ($kind !== 'incident') {
        $lo = $W['people'][$author][2]; $hi = $W['people'][$author][3];
        $tl = smt_titles($kind);
        if (smt_post_incident($kind, $tl[$seed % count($tl)])) $lo = max($lo, 1972);
        if ($lo > $hi) $lo = $hi;
        if ($year < $lo || $year > $hi) {
            $iso  = smt_date($seed ^ 0x1f1f, $lo, $hi);
            $year = (int) substr($iso, 0, 4);
        }
    }

    $obj = $W['objects'][($seed >> 7) % count($W['objects'])];

    $tl = smt_titles($kind);
    return array(
        'series' => $s, 'sub' => $sub, 'item' => strtoupper($item), 'seed' => $seed,
        'ref' => smt_ref($s, $sub, $item), 'kind' => $kind, 'form' => $SERIES[$s][1],
        'title' => $tl[$seed % count($tl)], 'date' => $iso, 'year' => $year,
        'author' => $author, 'author_name' => smt_person($author), 'object' => $obj,
        'series_title' => $SERIES[$s][0],
    );
}

/* the items that actually exist in a subseries */
function smt_items($s, $sub) {
    $seed = smt_fnv('sub::' . $s . '::' . $sub);
    $r = smt_rng($seed);
    $n = smt_int($r, 4, 11);
    $out = array();
    for ($i = 0; $i < $n; $i++) $out[] = smt_item_code(smt_fnv('it::' . $s . '::' . $sub . '::' . $i), $s);
    return array_values(array_unique($out));
}
function smt_subs($s) {
    $n = smt_int(smt_rng(smt_fnv('ser::' . $s)), 3, 9);
    return range(1, $n);
}

/* references cited by a document — every one of them resolves */
function smt_citations($m) {
    global $SERIES;
    $r = smt_rng($m['seed'] ^ 0x2b1d);
    $out = array();
    $n = smt_int($r, 2, 4);
    for ($i = 0; $i < $n; $i++) {
        $s2 = smt_int($r, 1, 11);
        $subs = smt_subs($s2); $sub2 = $subs[smt_int($r, 0, count($subs) - 1)];
        $items = smt_items($s2, $sub2); $it2 = $items[smt_int($r, 0, count($items) - 1)];
        $out[] = array('ref' => smt_ref($s2, $sub2, $it2), 'path' => u("f/$s2/$sub2/" . strtolower($it2)));
    }
    /* some papers cite a withdrawal into the sealed series. those are the way in. */
    if (smt_int($r, 0, 99) < 34) {
        $subs = smt_subs(12); $sub2 = $subs[smt_int($r, 0, count($subs) - 1)];
        $items = smt_items(12, $sub2); $it2 = $items[smt_int($r, 0, count($items) - 1)];
        $out[] = array('ref' => smt_ref(12, $sub2, $it2), 'path' => null, 'withdrawn' => true);
    }
    return $out;
}

/* ===========================================================================
 *  DAMAGE — deeper papers are in worse condition. unbounded.
 * ========================================================================= */
function smt_damage($text, $level, $seed) {
    if ($level <= 0) return smt_h($text);
    $r = smt_rng($seed ^ 0x0d0d);
    $words = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $p = min(0.62, 0.11 * $level);
    $out = ''; $run = 0;
    foreach ($words as $w) {
        if (trim($w) === '') { $out .= $w; continue; }
        if ($r() < $p) {
            $run++;
            if ($run > 1) { $out .= ''; continue; }
            $out .= '<span style="opacity:.34">[&nbsp;&nbsp;&nbsp;]</span>';
        } else { $run = 0; $out .= smt_h($w); }
    }
    return $out;
}

/* ===========================================================================
 *  THE PAPERS. authored in pairs: a heading and the text that belongs to it.
 *  shape: (title, body) or (title, voice-key, body) where the hand is fixed.
 *  slots: {A} author  {d} long date  {bm} height this year  {bmOld} 1970
 * ========================================================================= */
function smt_paper_table() {
return array(

'warrant' => array(
array('Warrant of Establishment',
"By warrant of the Board dated 12 March 1961 a station is established at Holloway Point for the observation of the tide and the maintenance of the datum. The keeper shall observe at the appointed hours and shall enter one figure against each observation.\n\nThe register shall be kept in duplicate. The second copy shall be retained at the station and shall not be sent."),
array('Schedule of Duties',
"The station is charged with the datum and with nothing else. The keeper shall not form an opinion as to the cause of any difference between observations.\n\nWhere a difference arises he shall enter the observation and refer the difference upward. He shall not enter both."),
array('Instrument of Transfer',
"Transferred to this repository on {d} under the schedule appended. The papers are as received.\n\nTwo registers were received for the years 1971 to 1979 inclusive. No instruction accompanied them and none has since been obtained. Both are retained."),
array('Retention Notice',
"The papers of the station are retained in perpetuity, the station being closed and the datum having been transferred elsewhere.\n\nThe mark itself was not transferred. It remains at Holloway Point and is not maintained."),
array('Certificate of Datum',
"I certify that the datum of the station is the datum described in the warrant, that it has not been altered, and that it cannot be altered except by warrant.\n\nI am asked to certify further that the heights observed against it are consistent with it. I am not able to certify that, and the certificate is issued without it."),
array('Schedule of Papers Received',
"Received: twelve series, in forty-one boxes.\n\nThe schedule as sent lists forty boxes. A forty-first was present at delivery and is described in no schedule. It has been given a number and retained. Its contents are consistent with the rest and add nothing that was missing."),
array('Deed of Deposit',
"The depositor conveys the papers absolutely and reserves no right of recall.\n\nThe depositor is described in the deed as the Board. The Board's minute authorising the deposit has not been produced, the Board having by then been dissolved, and the deed is therefore executed by a body which did not exist on the day it signed."),
array('Note as to Arrangement',
"The papers are kept in the order in which they were received, which is not the order in which they were made.\n\nNo attempt has been made to interleave the duplicate series with the first. To interleave them would require a decision as to which sheet precedes the other where both bear the same date and hour, and that decision is not the archivist's to take."),
array('Certificate of Closure',
"The station was closed on 31 December 1997. The keys were surrendered. The instruments were removed under separate schedule.\n\nThe gauge house was left standing at the request of the surveyor's department, which has since been unable to say who made the request or on what ground."),
array('Warrant, Amended',
"The warrant is amended by the substitution, in the fourth article, of the words 'one figure' for the words 'the figure observed'.\n\nThe amendment was moved in 1974 and is expressed to take effect from 1961. Papers made under the unamended article are not thereby made irregular, the Board having resolved that they were always irregular."),
array('Memorandum of Custody',
"Custody of the second register was assumed by this repository on {d}.\n\nThe register was received sealed. The seal was found to be intact and was broken here, in the presence of two officers, in the ordinary way. The volume within is a fair copy in the keeper's hand and is four leaves longer than the volume it copies."),
),

'order' => array(
array('Standing Order',
"Observations at the gauge house are to be taken at the hours appointed and entered at once, in ink, in the presence of the register. No observation is to be entered from memory.\n\nWhere the observer is uncertain of the reading he shall take it again and enter the second reading. He shall not enter the mean."),
array('Order as to the Register',
"From this date the register is to be signed at the foot of each sheet by the observer, and by a second hand where one is present.\n\nSheets bearing two signatures against a single observation are to be sent up without comment."),
array('Order as to Hours',
"The night observation is to be taken at 03:00 and not at 03:11. The clock in the gauge house is to be compared weekly with the light. Discrepancy of more than eleven seconds is to be reported.\n\nThe clock has been compared. The discrepancy is eleven seconds, and has been eleven seconds since the comparison began."),
array('Order, Rescinded',
"The order of {d} requiring the north stair to be used for access to the gauge house is rescinded. Access is by the strand at low water.\n\nThe stair is not to be used pending survey. No survey has been appointed."),
array('Order as to Ink',
"Entries are to be made in the ink supplied and in no other. Pencil entries are to be inked over by the observer who made them, and by no other hand.\n\nWhere a pencil entry is found which no observer will ink over, it is to be left in pencil and the sheet endorsed accordingly. Four sheets are so endorsed."),
array('Order as to the Second Copy',
"The second copy is to be written at the same sitting as the first and from the same reading. It is not to be written from the first copy.\n\nThe keeper is reminded that a copy made from a copy is not a check upon it. He has replied that he is aware of this, and that it is the reason the two copies differ."),
array('Order as to Absence',
"The station is not to be left unmanned between the hours of 22:00 and 06:00.\n\nWhere the keeper is alone he is not to leave the gauge house during the night observation. He is not to leave it and return to it within the same minute. The wording of this article was settled by the Board and is not the keeper's."),
array('Order as to Reporting',
"Occurrences are to be reported on the day they occur and not afterwards.\n\nWhere an occurrence is reported twice, both reports are to be forwarded. The station is not to determine which report is the report."),
array('Order, Never Issued',
"Draft. Not issued.\n\n'The keeper shall, where two figures are obtained, strike the figure he believes to be in error and initial the striking.'\n\nMinuted across the foot in another hand: this would require him to believe one of them to be in error. He does not. Withdrawn before issue."),
array('Order as to the Stair',
"The north stair is closed. It is to be closed at the head by the gate provided and the gate is to be kept locked.\n\nThe key is kept on the board in the corridor. It is reported to be warm when taken down. The report has been made by three keepers in succession and is not the subject of an order."),
array('Standing Order, Consolidated',
"The standing orders are consolidated as at {d}. Articles rescinded are printed in their place and struck through, so that the volume may be read as it stood at any date.\n\nArticle 9, as to hours, is printed twice, in two forms, neither struck. The consolidation does not say which form was in force. It was compiled in 1975 from the station's own copies, of which there were two."),
),

'register' => array(
array('Tide Register',
"Register sheet for the month. Heights in metres above the datum of the station. Kept by {A}.\n\nThe duplicate of this sheet is retained at the station and differs from it in the entries marked with an asterisk."),
array('Register, Duplicate',
"The station's copy, retained under the warrant and not sent.\n\nThis sheet carries entries against hours at which no observation was appointed. The hand is the keeper's. The keeper has been asked and does not account for them."),
array('Register of Predictions',
"Predictions for the month, computed ashore and sent down for comparison.\n\nThe comparison has been made. Agreement is close throughout except at the entries marked, where the observed exceeds the predicted by a constant amount. A constant difference is not a prediction error and has been referred upward."),
array('Register, Second Copy',
"Second copy. Fuller than the first by four entries.\n\nThe four entries are in the same hand and the same ink as the rest of the sheet. No corresponding entries appear in the first copy, which was written at the same table on the same night."),
array('Register of High Waters',
"High waters only, extracted for the year and totalled.\n\nThe total of the extracted sheet does not agree with the total of the register from which it was extracted. The extraction has been checked three times by three hands and the arithmetic is right each time. The two totals differ by the same amount as everything else."),
array('Register, Endorsed',
"Endorsed by the inspecting officer: 'Kept in good order. Entries legible. Observations regular. I am unable to certify the figures.'\n\nThe endorsement is in the printed form except for the last sentence, which is written in above the signature and is not part of the form."),
array('Night Register',
"Night observations only, 22:00 to 06:00, kept separately at the Board's direction from 1973.\n\nThe direction was given so that the night figures might be examined apart from the day figures. When examined apart they are regular. They are irregular only in company with the day figures, which are themselves regular."),
array('Register, Recopied',
"Recopied from a sheet damaged by water. The damaged sheet is retained with this one.\n\nThe recopying was done by {A} from the original where legible and from the duplicate where not. He has marked in the margin which source he took each entry from. The marks show that he took nine entries from the duplicate which are legible in the original, and he does not say why."),
array('Register, Unsigned',
"Sheet complete in every particular except the signature.\n\nThe foot of the sheet carries the ruled line for the observer's name and the line is blank. The sheet was kept in the bound volume between two signed sheets and cannot have been inserted, the volume being sewn."),
array('Comparison Sheet',
"The station's register set against the Board's copy of the same month, entry by entry.\n\nSixty-one entries. Sixty-one agreements as to hour. Sixty-one disagreements as to height, each of 0.0339 m, each in the same direction.\n\nA difference which is identical in every case is not an error of observation. It has been minuted as an error of observation."),
array('Register, Abandoned',
"Begun in the ordinary way and abandoned at the eleventh entry.\n\nThe eleventh entry is complete. The twelfth is begun and stops after the hour. Below it, in the same hand: 'I will not enter this one twice.' The volume was not used again and a fresh one was opened the same night."),
),

'benchmark' => array(
array('Observation of BM-41',
"Levelling to BM-41 from the auxiliary marks, closing on the gauge house. Observed by {A} on {d}. Height above datum: {bm} m.\n\nThe mark was found sound, undisturbed, and clean. The staff was read twice, and by two hands where a second was present.\n\nThe figure obtained in 1970 was {bmOld} m. The difference is not accounted for by the instrument, which was compared before and after and found in adjustment; nor by the mark, which has not moved; nor by the datum, which is by definition fixed. The difference is entered because it was observed."),
array('Re-observation',
"Re-observation of BM-41, the previous observation having been questioned. Observed by {A}. Height above datum: {bm} m, agreeing with the observation questioned to within four ten-thousandths of a metre.\n\nThe observation questioned is therefore confirmed. It remains in disagreement with the figure the Board holds.\n\nI am directed to say which figure is correct. I am able to say only which figure was observed, and I have now said it twice."),
array('Comparison of Levels',
"Comparison of the levels held at the station with those held by the Board.\n\nThe two sets agree for all years to 1970 and for no year after. The divergence does not increase or decrease. It appears whole in 1971 and is thereafter carried forward without change by both parties.\n\nEach party carries it forward as an error of the other."),
array('Levelling Sheet',
"Levelling sheet, closing error 0.0002 m, within tolerance. Observed by {A}.\n\nThe sheet is submitted without remark. The remark that would ordinarily be made here has been made eleven times in this series and has not been answered."),
array('Examination of the Mark',
"The mark was uncovered, cleaned, photographed and measured in section.\n\nIt is a gunmetal bolt set in sound rock, of the pattern used throughout, and it is sound. There is no sign of settlement, of frost, of working, or of disturbance of any kind. The rock about it is undisturbed for two metres in every direction.\n\nThe mark is where it was put. This is stated positively because it has been asked five times."),
array('Auxiliary Marks',
"The three auxiliary marks were levelled among themselves, without reference to BM-41.\n\nThey agree with one another to within three ten-thousandths, as they have always done. They agree with BM-41 as it now stands. They do not agree with BM-41 as it stood in 1970, from which they were originally set out.\n\nThey have not moved and it has not moved and they no longer agree with what they were made from."),
array('Height Above Datum',
"Height above datum, {bm} m, entered for the year.\n\nEntered also, on the same sheet and in the same hand, the height {bmOld} m, with the word 'or' between them. The Board has asked for the word to be struck. It has not been struck. It is the only honest word on the sheet."),
array('Instrument Comparison',
"The level was compared against the standard at the depot before the observation and after it. It was found in adjustment on both occasions, the collimation error being 2 seconds and 2 seconds.\n\nAn instrument which is in adjustment before and after cannot have gone out of adjustment between. This has been demonstrated, at length, four times, to a Board which has never asserted that it did."),
array('Levelling, Repeated by a Second Hand',
"Levelled independently by {A}, the first observer standing off and taking no part.\n\nThe second hand obtained {bm} m. The first hand had obtained {bm} m. The observers did not confer and the sheets were sealed separately.\n\nThis is submitted as a check. It is not a check. Two observers obtaining the same figure prove only that the figure is what is there."),
array('Abstract of Heights',
"Abstract of all observations of BM-41, 1961 to date, in one column.\n\nThe column is regular to 1970, steps once in 1971, and is regular after. It resembles nothing so much as a record kept faithfully across a change which was never recorded.\n\nThe abstract has been laid before the Board twice. On neither occasion was the step discussed."),
array('Observation, Refused',
"The observation appointed for this date was not taken.\n\n{A} reports that he went out to the mark with the staff, that the mark was where it has always been, and that he did not read it. He gives no reason and has declined to give one when asked in writing.\n\nHe has taken every observation since."),
array('Certificate of the Mark',
"I certify that BM-41 is the mark described in the warrant; that it is in the position described; that it is undisturbed; and that its height above the datum of the station is {bm} m.\n\nI am asked to certify that this is the height it has always had. I decline, and I have set out my reasons in the letter accompanying, which is not part of this certificate."),
),

'docket' => array(
array('Instrument Docket',
"Docket opened by {A}. The float was withdrawn, examined and found sound. The well was sounded and found clear. The clock was compared with the light and found eleven seconds slow, as before.\n\nNothing was found to be at fault and the docket is therefore closed without a remedy.\n\nThe occurrence which gave rise to the docket is not thereby explained. It is recorded in the incident papers and is not the business of this docket."),
array('Docket, Unclosed',
"Opened on {d} against a discrepancy of 0.34 m in the record of the night observation.\n\nThe instrument was found in order at every examination. The docket cannot be closed without a fault, and no fault has been found. It is carried forward, and has been carried forward at every inspection since."),
array('Gauge Docket',
"The gauge was found to have recorded a rise and a fall of equal magnitude within two minutes, the sea being calm and no wave observed from the light. The trace is attached.\n\nThe trace is not disputed. What is disputed is the figure entered against it, of which there are two, in two hands, neither struck."),
array('Clock Docket',
"The clock was stopped, cleaned, and restarted against the light. It was found to be eleven seconds slow before the work and eleven seconds slow after it.\n\nThe maker's man attended and reports that a clock which is cleaned and remains slow by the same amount is not slow. He has not been asked to explain the entry at 03:11 and did not offer to."),
array('Float Docket',
"The float was withdrawn, weighed dry, immersed, and weighed again.\n\nDry: 2.41 kg. Wet: 2.41 kg. The second weighing was made in the presence of a witness because the first was not believed by the man who made it.\n\nThe float is of copper and is sound. A sound copper float which does not gain weight when wet has been reported before, in this station, and nowhere else."),
array('Well Docket',
"The well was sounded from the gauge house floor to the bed and found to be 4.12 m.\n\nThe drawings give 4.09 m. The drawings are the drawings from which the well was dug. The difference is 0.03 m, which is the thickness of the floor the sounding was taken through."),
array('Barograph Docket',
"The barograph was found to have drawn a flat trace through a night in which the mercury fell 11 hPa.\n\nThe pen was full, the drum turned, the linkage was free, and the instrument recorded normally before that night and after it. The trace for the night is flat and unbroken, which is not the trace of an instrument that has stopped. A stopped instrument leaves a dot."),
array('Docket, Closed in Error',
"Closed on {d} as 'no fault found'.\n\nReopened on the same day by the officer who closed it, with the note: 'no fault found is a finding as to the instrument. It has been taken as a finding as to the occurrence. It is not one.'\n\nClosed again, in the same terms, by a later hand."),
array('Docket of Spares',
"Spares held at the station: two floats, one clock movement, four staffs, one level.\n\nThe schedule as sent lists one float. Two are held and both are marked as the station's, in the station's paint, in the station's number. The number is the same on both.\n\nThe second float has not been requisitioned and cannot be accounted for. It is in every respect a proper float."),
array('Docket, Annual Return',
"Return of instrument defects for the year: nil.\n\nReturn of instrument examinations for the year: forty-one.\n\nThe Board has asked why an instrument with no defects was examined forty-one times. The station has replied that it was examined forty-one times because it had no defects."),
array('Docket, Referred',
"Referred to the maker with the question: can an instrument record an event which did not occur, without fault, and without leaving any sign of the fault?\n\nThe maker replies that it cannot. The maker further replies, in a hand written below the typed answer, that he has read the trace enclosed and would be glad not to be consulted again on this matter."),
),

'works' => array(
array('Survey of the North Stair',
"The north stair is to be surveyed before it is used. The surveyor attended on {d} and reports that the stair cannot be surveyed while it is closed, and that it is closed pending survey.\n\nThe docket is carried forward. It has been carried forward, in this form, at every inspection since. The wording has not been altered, no party having been able to say which of the two conditions should be lifted first."),
array('Repairs to the Gauge House',
"The roof was made good and the door rehung.\n\nThe keeper reports that the door, being rehung, now closes against a frame which is not square; that the frame was square when measured before the work; and that it is square when measured after. Both measurements are attached and neither is in error."),
array('Works, Deferred',
"The measurement of the gauge house interior, requested by the Board, is deferred.\n\nThe keeper reports that the interior measures 4.12 m where the exterior allows 4.09 m, and that he has measured both three times. He asks for a second hand to be sent. No second hand has been sent."),
array('Pointing of the Sea Wall',
"The sea wall was pointed between the fourth and eleventh courses. Materials as scheduled.\n\nThe mason reports that he pointed forty-one metres and was paid for thirty-nine, the wall being thirty-nine metres long. He has been paid for forty-one on the certificate of the keeper, who measured it with him."),
array('Steps, North Stair',
"The stair has twelve steps. The drawings show nine.\n\nThe stair was built to the drawings by a contractor who is dead, under a clerk of works who is dead, and paid for on a certificate which describes nine. The three additional steps are of the same stone, laid in the same lime, and weather identically.\n\nNo works order exists for them."),
array('Painting Schedule',
"The gauge house was painted inside and out. Two coats.\n\nThe quantity taken is that proper to an interior of 4.12 m. The estimate was framed on 4.09 m and was exceeded. The overspend is 3 per cent and has been allowed without comment, the surveyor's department having declined to remeasure."),
array('Works, Completed Twice',
"The works were certified complete on 14 May and certified complete again on 14 May, by the same officer, on two certificates.\n\nThe certificates are identical except in the ink. Neither has been withdrawn. The contractor has been paid once and has not raised the matter."),
array('Fencing of the Stair Head',
"A gate was hung at the head of the stair and a lock fitted. The key was hung on the board in the corridor.\n\nThe joiner reports that the gate frame was found already fixed, in sound condition, of the same pattern as the gate he brought, and that he hung his gate in it. He has been paid for the gate only."),
array('Drainage',
"The channel behind the gauge house was cleared and relaid to fall seaward.\n\nWater standing behind the gauge house after the work was found to be standing at the same level as before it. The fall was checked with a level and is true. The water is not running against the fall; it is not running at all."),
array('Works, Estimate Only',
"Estimate for the survey of the north stair: £41 including the erection of a light staging.\n\nThe estimate has been renewed annually since 1972 in the same sum, the surveyor's department having decided that to revise it would be to imply an intention to carry it out."),
array('Demolition, Not Carried Out',
"Instruction to take down the gauge house on closure of the station.\n\nCountermanded by telephone on the day appointed. The countermand is minuted as received from the surveyor's department. The surveyor's department has since been asked, twice, who made it, and has replied that no such call is recorded and that the building should nevertheless be left standing."),
),

'official' => array(
array('Requisition for a Single Figure',
"Sir,\n\nThe Board is unable to accept two figures against a single observation. You will reconcile the register and return a single value for the night of 3 November by the 30th instant.\n\nThe Board does not require an explanation and will not entertain one. It requires a figure.\n\nThe matter of the stair is not before the Board.\n\nI am, Sir, your obedient servant,\nE. Beazley"),
array('Letter from the Board',
"Sir,\n\nYour letter of {d} is acknowledged. The Board notes that you decline to strike either entry.\n\nThe Board does not ask you to say which entry is false. It asks you to say which entry is the station's.\n\nFailing a reply by the quarter day, the older figure will be taken as the station's and the matter closed on that footing.\n\nE. Beazley"),
array('Minute, Reconciliation',
"The station's returns for the year are received. They are in duplicate and the duplicates differ. The Board has taken the older figure throughout, that being the practice, and has closed the year.\n\nIt is to be noted for the record that the practice of taking the older figure was adopted for convenience and has never been the subject of a decision.\n\nE. Beazley"),
array('Board Minute',
"Minuted: that the keeper's conduct is not in question; that his figures are not in question; that the Board's figures are not in question; and that the difference between them is therefore not attributable.\n\nMinuted further: that an unattributable difference is to be carried at the foot of the account and not discussed at the table.\n\nE. Beazley"),
array('Letter as to the Second Register',
"Sir,\n\nThe Board is informed that the station holds a second register containing entries not appearing in the first.\n\nThe Board does not require its production. The warrant provides that the second copy shall be retained at the station, and the Board does not propose to disturb an arrangement of its own making merely because it has become inconvenient.\n\nYou will continue to retain it. You will not refer to it in correspondence.\n\nE. Beazley"),
array('Minute as to Attendance',
"The keeper's request for a second hand at the night observation is refused.\n\nThe establishment of the station is one keeper. The Board observes that the difficulty complained of has arisen when the keeper was alone and would not be resolved by a witness, since a witness could only confirm what the keeper says, and the Board does not doubt what the keeper says.\n\nE. Beazley"),
array('Letter, Final',
"Sir,\n\nThis correspondence is closed.\n\nThe Board has before it eleven letters from you upon a difference of 0.0339 m. The Board is satisfied that you have acted throughout in good faith and with unusual care. It is precisely because of your care that the Board is unable to proceed further: a difference which survives your attention is not one the Board can resolve at this distance.\n\nYou will enter the older figure. You will make no note of this letter in the register.\n\nE. Beazley"),
array('Minute, Establishment',
"On the retirement of the keeper the Board considered whether the station should be continued.\n\nResolved: that it be continued; that the incoming keeper be given the standing orders and the register; and that he be given no account of the correspondence of 1971 to 1979, which is not necessary to the performance of his duties.\n\nE. Beazley"),
array('Requisition, Returned',
"Returned herewith, unactioned.\n\nThe requisition asks for a ruling as to which of two entries is the station's entry. The Board rules upon differences between the station and the Board. It does not rule upon differences within the station.\n\nThe station will settle the matter internally and inform the Board of the result. The station has been asked to do this on four previous occasions.\n\nE. Beazley"),
array('Minute on Closure',
"The station is to close on 31 December next. The datum is transferred to the standard at the depot.\n\nThe Board records its appreciation of the service of the keepers. The Board records nothing further.\n\nE. Beazley"),
array('Letter, Personal',
"Rell,\n\nOff the file, and burn it.\n\nI have read every letter you sent me and I have answered none of them in the terms they deserved. You are not mistaken and I have never thought you were. But there is no minute I can write which does not either call you unreliable or call the datum uncertain, and I am not permitted to write the second.\n\nSo I have written the first, eleven times, and I am sorry for it.\n\nEnter the older figure. It is the only instruction I have that costs you nothing.\n\nBeazley"),
),

'private' => array(
array('Letter','rell',
"You ask me why I keep the older figure.\n\nBecause I took it. And because the newer one is also mine, in my hand, in my ink, and I cannot have taken both. One of them was taken by the man who was in the gauge house at eleven minutes past three, and the other by the man who came back into it at eleven minutes past three, and I have not been able to establish which of these I am.\n\nI have not written this to the Board and I do not intend to. They want a figure. I have two, and they are both honest.\n\n{A}"),
array('Note, Unsent','rell',
"I have stopped comparing the two registers. It is not that they disagree. It is that they disagree by exactly the same amount in every place, and have done since that night, and a disagreement which does not vary is not a mistake. A mistake wanders. This sits.\n\nHoyle says I should strike mine. He would say that; his is the newer. But he was not on the stair and I was, and I know what the count was, because I took it twice and it did not settle."),
array('Notebook Page','rell',
"Not for the register.\n\nThe mark is at forty-one. It has always been at forty-one. I have levelled to it eleven times this year and it has been at forty-one every time, and the figure I write down is not the figure it was in 1970, and both of these things are true and I have written them both down.\n\nI am not frightened of the mark. I am frightened of how calmly I have written this."),
array('Letter, Returned','rell',
"Returned undelivered. The addressee has left the station.\n\nI only wanted to say that the second register — the copy that was not to be sent — is fuller than the first. Not different. Fuller. There are entries in it against hours at which no observation was appointed, in a hand I take to be my own, and I have no memory of the hours.\n\nBurn this or file it, I don't mind which. Filing it is worse.\n\n{A}"),
array('Notebook, Later','rell',
"Twenty-two years of this.\n\nI have worked out what it is that tires me, and it is not the figures. It is that nothing has ever happened twice. One night, one occurrence, one step in the column, and then twenty-two years of a station running perfectly well with a fault in it that will not repeat and will not go away.\n\nIf it came again I would know what to do. It does not come again. It simply stays."),
array('Letter to Hoyle','rell',
"Hoyle,\n\nYou were right to enter your figure and I was right to enter mine and the Board is right that it cannot have both. Three correct positions and no way through.\n\nI am not asking you to withdraw. I am asking you to write down, somewhere that is not the register, what you saw between the two entries. Not what you read. What you saw.\n\nYou have never once told me, in nine years, and I have never once asked you in writing until now.\n\nRell"),
array('Reply','hoyle',
"Rell,\n\nI saw you write. Then I saw the sheet, and there was nothing on it. Then I saw you write again.\n\nI entered my figure because I had read the staff and because I could not enter yours, which was not on the sheet when I looked and was on it afterwards in ink that was dry.\n\nI have not written this down anywhere until now and I would take it as a kindness if you did not put it in the register. You will put it in the register.\n\nHoyle"),
array('Note, Torn','rell',
"— found among the loose papers, upper half only —\n\n...and if the interior is larger than the exterior by the thickness of the door, then the door is inside the room and outside it, and there is no arithmetic that fixes this, and I have stopped doing arithmetic about the door.\n\nThe stair is the same. Twelve steps up, twelve steps down, and the drawings say nine, and I have counted them going up and going down and got twelve both times, and the drawings are not wrong either..."),
array('Letter to his Sister','rell',
"You ask what it is like here, and I have started this letter four times.\n\nIt is like keeping a shop that is honest, in a street where the money is honest, and finding at the end of every day that the till is over by the same coin. Not more each day. The same coin. You would not report it after the first month. You would simply come to know that your shop has a coin in it.\n\nI am well. The weather has been fair. Do not worry about the figures; nobody else does."),
array('Notebook, Last Page','rell',
"I hand over on Friday.\n\nI have shown Tarn the instruments, the register, the orders, and the mark. I have not shown him the second register and I have not told him about the night, and I have thought about this for a month and I am still not certain I am right.\n\nHe will find it. He will find it in about a year, the way I found it, by adding a column twice. And then he will be where I am, and he will at least not have been told what to think about it, which is the only thing I was never given."),
array('Note, Found in the Register','tarn',
"Found laid in the volume at the entry for 3 November 1971, in Rell's hand, undated:\n\n'Whoever reads this will want to know which figure is right. Both are. That is not a paradox, it is an inconvenience, and the difference between those two words is the whole of what I have learned here.'\n\nI have left it where it was."),
array('Letter, Unfinished','tarn',
"To the Board.\n\nOn taking over I was told the station keeps one register. It keeps two. I was told the difference in the 1971 levels is under investigation. It is not; the file was closed in 1979.\n\nI do not write to reopen anything. I write because in nine years nobody has told me a single thing about this station that turned out to be\n\n— breaks off —"),
),

'incident' => array(
array('Report of Occurrence','rell',
"REPORT OF OCCURRENCE — 3 November 1971\n\nAt 03:11 the gauge recorded a rise of 0.34 m and, within the two minutes following, a fall of the same amount. The sea was calm. No wave was observed from the light, and the light was manned. The float was withdrawn within the hour and found sound.\n\nI entered the figure at 03:14. M. Hoyle, who was present, entered a different figure against the same observation. Neither entry has been struck. Both are in the register in ink and both are signed.\n\nI record further, because it will be asked, that the north stair was dry at 03:11 and wet at 03:20, and that no tide reached it on either occasion.\n\nA. Rell, keeper"),
array('Statement of the Keeper','rell',
"I was in the gauge house from 02:40. At 03:11 the pen lifted and returned to the same figure. I state this because the trace shows a rise and a fall, and I state it knowing that the trace and I do not agree.\n\nI have been asked whether I left the gauge house between 03:11 and 03:11. The question is put in those terms in the occurrence book and I have not altered it.\n\nMy answer is that I did.\n\nA. Rell, keeper"),
array('Occurrence Book','rell',
"Entries for the night.\n\n02:59 — glass falling. no cloud. wind none.\n03:00 — observation taken and entered. one hand.\n03:11 — see report.\n03:11 — see report.\n03:20 — stair wet. no tide.\n04:00 — glass falling. no weather came.\n\nThe two entries at 03:11 are as written. The book was not ruled for two, and the second has been fitted into the margin."),
array('Statement, Second','hoyle',
"I was present. I entered a figure. The keeper entered another.\n\nI wish it recorded that I do not say the keeper is mistaken. I say that I read the staff and wrote what I read, and that he did the same, and that we were standing at the same instrument.\n\nI have read this statement over. It is not the statement I intended to make when I came in.\n\nM. Hoyle"),
array('Report, Supplementary','rell',
"Supplementary to my report of the 3rd.\n\nI am asked for the state of the sea. It was calm. I am asked for the wind. There was none. I am asked whether any vessel passed. None passed; the light would have logged it.\n\nI am asked, last, whether anything unusual occurred. I have thought about this question for four days. Nothing unusual occurred. A rise and a fall were recorded which had no cause, two figures were entered which cannot both be the reading, and the stair was wet without a tide. None of it was unusual. It was all perfectly quiet.\n\nA. Rell, keeper"),
array('Note of the Light Keeper','rell',
"Taken down from the light keeper by telephone, 4 November, and read back to him.\n\n'Calm all night. I'd have seen a wave; I was looking that way from two until four, it being clear. I saw the gauge house lamp go out about eleven minutes past three and come on again directly. I took it he'd knocked it. I didn't log it because a lamp going out is not a matter for the log.'\n\nThe lamp is oil and was found next morning full and lit."),
array('Examination of the Trace','rell',
"The trace for the night of 3 November, examined under glass by two officers.\n\nThe rise is smooth and the fall is smooth and both are of the pen's ordinary character. There is no jog, no scratch, no double line, and no sign of the drum having been touched.\n\nAt the top of the rise, where the pen turned, the ink is heavier than elsewhere, as it is wherever the pen has stood still. It stood still for eleven seconds."),
array('Occurrence Book, Second Volume','rell',
"The second volume for the same night, kept under the warrant and not sent.\n\nIt is written in the same hand at the same table and it is fuller. Between the two entries at 03:11 it carries a third line which does not appear in the first volume:\n\n'03:11 — the gauge house door stood open. I did not open it. I closed it and entered the reading again.'\n\nThis line has never been transcribed into any return."),
array('Minute of Enquiry','rell',
"The enquiry sat on 19 January 1972 and heard the keeper and the surveyor.\n\nBoth witnesses were found truthful. Neither account was preferred. The enquiry found that an occurrence took place, that its cause could not be determined, and that no fault attached to any person.\n\nThe enquiry was not asked, and did not consider, what the occurrence was."),
array('Report, Not Forwarded','rell',
"Written 3 November, not sent.\n\n'At 03:11 I was on the north stair, which was dry, and I saw the gauge house door open and myself come out of it. I did not call out. He went down the stair past me and did not look at me and I have never in my life been so certain that I would not have wanted him to.\n\nI went in and the reading was on the sheet in my hand and the ink was dry.'\n\nThe report was replaced by the report of the same date now in this file."),
),

'transcript' => array(
array('Transcript',
"...and the count was taken twice and did not settle, and I have entered both because to enter one is to say the other was not taken, and it was taken, I took it..."),
array('Transcript, Partial',
"...the stair was dry and then the stair was wet and nothing had come up it. I want that written down in the plain words, because when it is written in the Board's words it becomes a discrepancy, and it is not a discrepancy, it is a stair that was dry and then was wet..."),
array('Recovered Sheet',
"...he asks which of us is right. Neither of us is wrong. He cannot hold those two sentences at once and I have held them for nine years..."),
array('Transcript, Water-damaged',
"...the second register is fuller. I have said this and it has been minuted as an allegation. It is not an allegation. It is a thing anybody may go and look at, and nobody has gone and looked at it..."),
array('Sheet, Recovered from the Well',
"...forty-one. forty-one. it is at forty-one and the number I write is not the number I wrote and the mark has not moved, and I am to reconcile these, and I have been asked politely, and I have been asked twice..."),
array('Transcript of a Telephone Call',
"...no, I am not asking you to send anyone. I am asking you to write down that I telephoned. That is all. Write down that at ten past three in the morning on the third of November the keeper at Holloway telephoned the depot and asked for the time, and that you gave it, and that it agreed with his clock to the second...\n\n...I know what it sounds like. Write it down anyway..."),
array('Transcript, Reverse of a Chart',
"...if I am the man who came back then the man who went out is not accounted for, and if I am the man who went out then I have been keeping this station for twenty-two years without ever having come back, and I do not find that either of these is the frightening one. The frightening one is that the register balances..."),
array('Transcript, Two Hands',
"...— that the door was open —\n— that the door was shut and I opened it —\n\n...both of these are written across each other on the same line and the second is in the surveyor's hand, and I have not been able to say which was written first, and neither has he..."),
array('Sheet, Foxed',
"...and the coin, I keep coming back to the coin, a till over by the same coin every night for twenty-two years is not a till that is wrong, it is a till with a coin in it, and you do not report a coin, you learn to count around it..."),
array('Transcript, Undated',
"...they will say it was the instrument. It was not the instrument; I took the instrument apart. They will say it was me. It was not me; there were two of us. They will say it was the sea. The sea was flat and the light was watching it.\n\nWhat is left is the thing that actually happened, and there is no form for it, and so there is no way to report it, and so it did not happen..."),
array('Transcript, Last Sheet',
"...I am not leaving a warning because there is nothing to warn anybody about. Nobody was hurt. Nothing was lost. The station kept a good register for thirty-six years and the datum was held.\n\nI am leaving a note because it happened and because I was the only one who wrote it down twice..."),
array('Sheet, Illegible but for the Foot',
"...\n\n— and at the foot, in a firm hand, complete and undamaged —\n\n'Both figures are the station's. The station is not one man.'"),
),

'plate' => array(
array('Plate',
"The north stair from the strand, at low water. The stair is dry to the seventh step. The exposure is 41 seconds and no light was available.\n\nCatalogued as complete."),
array('Plate, Catalogue Entry',
"Plate numbered 12 of 9. The numbering is as found on the plate itself and has not been corrected, there being no ninth plate, and no series in which this plate is the twelfth.\n\nCondition: complete."),
array('Plate, Withdrawn',
"The gauge house interior. Withdrawn from the sequence on {d}.\n\nThe catalogue card is retained in the sequence in its place, and reads as it read before the withdrawal."),
array('Plate: the Mark',
"BM-41 in section, with a scale laid alongside.\n\nThe scale reads 41 mm across the head of the bolt. The bolt is of the standard pattern, which is 38 mm. The plate has been examined for distortion and the scale for shrinkage and neither accounts for it.\n\nCatalogued as complete. The measurement is not catalogued."),
array('Plate: the Gauge House, Exterior',
"South elevation, taken from the strand at midday.\n\nThe building is of one storey with one door and one window. The shadow of the building falls to the north-east, which at midday at this latitude it cannot do.\n\nThe plate is not retouched. The negative is held."),
array('Plate: the Keeper',
"A. Rell at the door of the gauge house, 1968. Taken by the surveyor.\n\nThe keeper is standing with his hand on the door frame. The exposure is long and his face is sharp throughout, which requires that he did not move for 41 seconds, which he is not recorded as having been asked to do."),
array('Plate, Double Exposure',
"The gauge house interior, exposed twice by accident on the same plate.\n\nBoth exposures are of the same room from the same position. In the first the room is empty. In the second the room is empty. The furniture stands in a different place in each and there is no record of it having been moved between."),
array('Plate: the Stair, from Above',
"Taken from the head of the stair looking down. Twelve steps are visible and countable.\n\nThe catalogue card, written by the photographer on the day, reads: 'north stair, nine steps'.\n\nBoth the plate and the card are retained. Neither has been amended."),
array('Plate, Not Taken',
"Card retained for a plate which does not exist.\n\n'Plate 41. The north stair at 03:11, 3 November 1971. Exposure 41 s.'\n\nThere was no camera at the station on that date; it was at the depot for repair, and the repair docket confirms it. The card is in the photographer's hand and in the ink he used that year."),
array('Plate, Catalogue: Summary',
"The photographic series comprises forty-one plates.\n\nForty are numbered 1 to 40 in sequence. The remaining plate is numbered 12 of 9.\n\nThe series is complete. It has been checked against the catalogue three times and the catalogue against the series, and on each occasion the count has been forty-one and the sequence has been unbroken."),
),

'withdrawn' => array(
array('Withdrawal Notice',
"The item described has been withdrawn from public production and retained under the Board's instruction of {d}. The description is retained and is reproduced above.\n\nThe instruction does not state a ground. Where no ground is stated, the practice is to retain the instruction rather than the item, and this has been done.\n\nProduction may be had on a retrieval reference. The reference is cited in the papers which cite this item."),
array('Certificate of Retention',
"I certify that the item was received, that it was examined, that it was found to be as described, and that it has been retained.\n\nI certify further that a second copy of the item was received at the same time, and that the two do not agree. Both are retained under this reference.\n\nThe certificate does not say which is produced on retrieval."),
array('Retained Item',
"Produced under reference. The item is a single sheet, undated, in the keeper's hand, and reads in full:\n\n'I have been asked to say which figure is the station's. The station has two. I am the station.'\n\nThe sheet is retained. A further withdrawal notice is filed with it and is cited below."),
array('Schedule of Retained Items',
"The retained series comprises the items scheduled. The schedule is itself retained.\n\nA reader who has this schedule has therefore been produced an item of the retained series, and the schedule provides that production of any item requires a reference to that item.\n\nThe reference to the schedule is not scheduled."),
array('Instruction to Retain',
"The instruction, produced under reference.\n\n'Retain: the second register, 1971-1979; the plate numbered 12 of 9; the report of occurrence not forwarded; the correspondence marked personal.\n\nGround: none stated. This instruction is itself to be retained.'\n\nInitialled. The initials are not those of any member of the Board then sitting."),
array('Withdrawal, Reversed',
"The item was withdrawn on 4 March and restored to production on 4 March.\n\nBoth minutes are on the file, in the same hand, timed 11:20 and 11:20. The item has been in continuous public production since and is also recorded as retained.\n\nIt is produced. It is also here."),
array('Retained: the Second Register',
"Produced under reference. Four leaves longer than the volume it copies.\n\nThe four additional leaves carry observations against hours at which no observation was appointed. The readings on them are consistent with the tide as predicted for those hours, to within the accuracy of the station's instrument.\n\nWhoever wrote them was reading a gauge."),
array('Certificate as to Two Copies',
"Where two copies of an item are retained and they do not agree, this repository produces the older.\n\nThe practice was adopted from the Board, which adopted it for convenience, and which never made it the subject of a decision.\n\nIt is recorded here because a reader has now asked, and because the answer to why the older is produced is that nobody ever chose."),
array('Retained: Correspondence, Personal',
"Produced under reference. One letter, from the Board's officer to the keeper, marked personal and off the file.\n\nIt was on the file. It has been on the file since 1979, in the envelope, with the instruction to burn it written on the envelope in the keeper's hand and not carried out.\n\nBoth men are dead. The letter is produced."),
array('Withdrawal Notice, Final',
"The item described is withdrawn and retained.\n\nThe item described is this notice.\n\nProduction may be had on a retrieval reference. The reference is cited below and is the reference of this notice."),
),

);
}

/* ---------------------------------------------------------------------------
 *  WHAT IS ENCLOSED. an appendix is not another whole report; it is a slip,
 *  a minute, a torn half-sheet. these are their own papers.
 * ------------------------------------------------------------------------- */
function smt_enclosure_table() {
return array(

'near' => array(   /* enclosure, appendix, schedule, minute, duplicate */
array('Slip, laid in',
"A slip of paper, laid in loose at this place. Not part of the sewn volume.\n\n'Ask him what the lamp was doing. Nobody has asked him what the lamp was doing.'"),
array('Minute attached',
"Minuted on the face of the item by a later hand:\n\n'Seen. No action. The file is not to be enlarged by comment upon comment.'\n\nBeneath, in a third hand: 'Seen. Agreed. This is comment upon comment.'"),
array('Schedule appended',
"Appended schedule, in tabular form, of the papers said to accompany this item.\n\nSeven papers are scheduled. Six are present. The seventh is scheduled as 'the enclosure hereto', which describes the schedule itself, and the schedule is present."),
array('Duplicate sheet',
"The station's copy of the sheet above, retained under the warrant.\n\nIt agrees with the sheet above in every particular except the total, which is greater by the amount by which everything at this station is greater, and except that it is signed twice."),
array('Docket, cross-referenced',
"Cross-reference slip. 'For the instrument, see the docket. For the occurrence, see the incident papers. For the figure, see the register.'\n\nAnd below, in pencil, unsigned: 'For what happened, see nothing.'"),
array('Extract',
"Extract taken for the Board and certified a true extract.\n\nIt is a true extract. It omits the two lines on either side of the passage extracted, and with them omitted the passage means the opposite of what it means in place. The certificate is nevertheless correct."),
array('Receipt',
"Receipt for the item, signed on delivery at the depot.\n\nThe signature is illegible. The time is 03:11. The date is a Sunday, on which the depot was closed, and the depot's own book shows nothing received that week."),
array('Envelope',
"The envelope in which the item was received. Retained because it is endorsed.\n\nEndorsed, in the keeper's hand: 'Do not open this until you have read the register for the same month. It will otherwise seem to be about nothing.'"),
),

'mid' => array(    /* verso, draft, second copy, marginalia */
array('Verso, in another hand',
"On the reverse of the sheet, written across the grain of the paper in pencil:\n\n'He has read this over four times. I have watched him do it. He is not checking the figures — he knows the figures. He is checking whether it still says the same thing.'\n\nUnsigned. The hand is the surveyor's."),
array('Draft, not sent',
"The draft of the letter above, with the passages struck out.\n\nStruck: 'I am not able to sleep in the building.'\nStruck: 'I have asked for a transfer twice and withdrawn it twice.'\nStruck: 'If you send anyone, send someone who will not be kind to me about it.'\n\nWhat was sent contains none of this and is three lines long."),
array('The second copy',
"The station's copy of the item above.\n\nIt is longer. It says everything the first says, in the same words, and then continues for a further paragraph which the first does not have and for which there is no room on the first, the first being full to the foot of the sheet.\n\nThe paragraph reads: 'I have written this twice so that if one of us is wrong there will be a record of the other.'"),
array('Marginalia, loose',
"Marginal notes, cut from a volume and kept loose.\n\n'again, and slower'\n'this is the third time he has written the word calm'\n'the arithmetic is right'\n'the arithmetic is right'\n'the arithmetic is right and I have checked it in a different order and it is still right'"),
array('Fair copy',
"A fair copy, made for the file, of a rough which is not on the file.\n\nThe fair copy is complete and clean and initialled. The rough from which it was made is described in the minute as 'not fit to be bound', which is not a description of its condition; the same minute records that it was in good condition."),
array('Note pinned to the item',
"Pinned, and the pin has rusted through the sheet.\n\n'Rell came in with this at four in the morning and waited while I read it. I have never known him wait. I told him it was properly made out. He said that was not what he had asked.'"),
),

'deep' => array(   /* fragment, recovered, foxed, waterline, illegible */
array('Fragment',
"— lower portion of a sheet, the upper part lost —\n\n...and so the honest thing, the only honest thing, is to write down both and let whoever comes after decide, and I know what they will decide, they will decide that one of the two of us miscounted, and it will be a reasonable decision, and it will be wrong, and there will be nothing in the file to stop them..."),
array('Recovered sheet',
"— recovered from the well during the works of 1984; dried and pressed —\n\n...twelve steps. I have counted them in the dark, going up, with my hand on the wall, and I have counted them in daylight with a rule, and it is twelve, and the drawing is not a mistake either, because I have found the mason's account and he was paid for nine and he was an honest man and he built what he was paid for..."),
array('Sheet, foxed',
"— heavily spotted; legible in the lower third —\n\n...he asked me, in the end, the only question that was ever worth asking, which was not which figure is right but which of us wrote first. And I could not tell him. I was there and I could not tell him..."),
array('Below the waterline',
"— the sheet has been submerged; the ink has run downward, so that the lower lines are heavier —\n\n...not a haunting. I want that understood by whoever is reading this in whatever year it is. Nothing here wants anything from anybody. It is a fault in the arithmetic of the place, and it is a small fault, and it has been carried faithfully in the books for twenty-two years by men who were paid to carry things faithfully..."),
array('Sheet, largely illegible',
"— four lines only remain, at the head —\n\n...the datum is held. whatever else is true, the datum is held. I have held it for twenty-two years and I am handing it on at the figure I found and not at the figure I was given...\n\n— remainder lost —"),
array('Wrapper',
"— the wrapper only; the contents are not in the file —\n\nEndorsed: 'The above.'\n\nNothing else is written on it. It is docketed, numbered, and cross-referenced to three items, all of which cross-reference back to it."),
array('Sheet, blank',
"— a sheet, blank, retained —\n\nIt is retained because it is numbered, sewn into the volume, and endorsed on the reverse in the keeper's hand: 'left blank deliberately. see the second copy.'\n\nThe corresponding sheet in the second copy is also blank and carries the same endorsement."),
array('The last legible line',
"— the sheet is destroyed but for one line near the foot —\n\n'...and it is still, after everything, a very ordinary place.'"),
),

);
}

function smt_body($m, $depth) {
    $papers = smt_papers($m['kind']);
    $idx    = $m['seed'] % count($papers);
    $text   = smt_paper_text($papers[$idx]);
    $y      = $m['year'];
    return strtr($text, array(
        '{A}'     => $m['author_name'],
        '{d}'     => smt_longdate($m['date']),
        '{bm}'    => number_format(smt_bm_height($y), 4),
        '{bmOld}' => number_format(smt_bm_height(1970), 4),
    ));
}

/* the tables carry their own weight — real numbers, drifting */
function smt_table($m) {
    $r = smt_rng($m['seed'] ^ 0x3311);
    if ($m['kind'] === 'register') {
        $rows = ''; $n = smt_int($r, 8, 14);
        $mon = (int) substr($m['date'], 5, 2); $yr = $m['year'];
        for ($i = 1; $i <= $n; $i++) {
            $hw1 = 3.9 + $r() * 0.5; $lw1 = 0.2 + $r() * 0.4;
            $star = ($yr >= 1971 && smt_int($r, 0, 99) < 22) ? ' <b>*</b>' : '';
            $rows .= sprintf('<tr><td>%02d</td><td>%02d:%02d</td><td class="n">%+.2f</td><td>%02d:%02d</td><td class="n">%+.2f</td><td>%s</td></tr>',
                $i, smt_int($r,0,11), smt_int($r,0,59), $hw1, smt_int($r,12,23), smt_int($r,0,59), $lw1, $star);
        }
        if ($m['year'] === 1971 && $mon === 11)
            $rows .= '<tr style="color:#8a3b2b"><td>03</td><td>27:—</td><td class="n">+4.—</td><td>——:——</td><td class="n">————</td><td><b>*</b></td></tr>';
        return '<table class="reg"><tr><th>d</th><th>HW</th><th>m</th><th>LW</th><th>m</th><th></th></tr>' . $rows
             . '</table><div class="fine">* entry differs in the duplicate retained at the station.</div>';
    }
    if ($m['kind'] === 'benchmark') {
        $rows = '';
        foreach (array(1961, 1965, 1970, 1971, 1975, 1982, 1990, 1997) as $y) {
            $hl = ($y === 1971) ? ' style="background:rgba(160,60,40,.10)"' : '';
            $rows .= '<tr' . $hl . '><td>' . $y . '</td><td class="n">' . number_format(smt_bm_height($y), 4) . '</td><td>'
                  . ($y < 1971 ? 'undisturbed' : ($y === 1971 ? 'undisturbed' : 'undisturbed')) . '</td></tr>';
        }
        return '<table class="reg"><tr><th>year</th><th>height / m</th><th>state of mark</th></tr>' . $rows
             . '</table><div class="fine">The mark was found undisturbed at every observation, including that of 1971.</div>';
    }
    return '';
}

/* ===========================================================================
 *  PRESENTATION — each series is a different kind of paper
 * ========================================================================= */

function smt_paper_css($form, $mobile) {
    $base = "*{box-sizing:border-box}html,body{margin:0;padding:0}"
          . "body{padding:" . ($mobile ? "1.1rem 1rem 5.5rem" : "3.2rem 2rem 5rem") . "}"
          . ".doc{max-width:" . ($mobile ? "none" : "42em") . ";margin:0 auto}"
          . ".ref{font:11px/1.6 'DejaVu Sans Mono',monospace;letter-spacing:.16em;text-transform:uppercase}"
          . "h1{margin:.15em 0 .1em}.sub{margin:0 0 1.6em}"
          . "p{margin:0 0 1.05em}.fine{font-size:12px;opacity:.62;margin-top:.5em}"
          . "table.reg{border-collapse:collapse;width:100%;margin:1.2em 0;font:" . ($mobile ? "12px" : "13px") . "/1.5 'DejaVu Sans Mono',monospace}"
          . "table.reg th{text-align:left;font-weight:400;opacity:.55;border-bottom:1px solid currentColor;padding:.25em .5em .25em 0}"
          . "table.reg td{padding:.18em .5em .18em 0;border-bottom:1px solid rgba(128,128,128,.16)}"
          . "table.reg td.n{text-align:right;font-variant-numeric:tabular-nums}"
          . ".cites{margin-top:2.4em;padding-top:1em;border-top:1px solid rgba(128,128,128,.28)}"
          . ".cites a,.cites span.wd{display:block;padding:" . ($mobile ? ".85em 0" : ".3em 0") . ";font:13px/1.5 'DejaVu Sans Mono',monospace}"
          . ".foot{margin-top:2.6em;font:11px/1.8 'DejaVu Sans Mono',monospace;opacity:.5}";

    switch ($form) {
    case 'letter':   // typed on a machine, onto headed paper
        return $base . "body{background:#e9e4d6;color:#221e18;font:" . ($mobile ? "16px" : "15.5px") . "/1.95 'Courier New',Courier,monospace}"
             . ".doc{background:#f4f0e4;padding:" . ($mobile ? "1.6rem 1.3rem" : "3.4rem 3.2rem") . ";box-shadow:0 1px 0 #cfc7ae,0 14px 34px rgba(60,50,25,.13)}"
             . "h1{font:400 17px/1.4 'Courier New',monospace;letter-spacing:.02em}.sub{font-size:12px;opacity:.6}"
             . ".ref{color:#8a7f62}a{color:#4a4030}p{white-space:pre-line}";
    case 'report':   // carbon copy, second sheet
        return $base . "body{background:#dfe0da;color:#26261f;font:" . ($mobile ? "15.5px" : "15px") . "/1.85 'Courier New',monospace}"
             . ".doc{background:#eceee4;padding:" . ($mobile ? "1.6rem 1.2rem" : "3rem 3rem") . ";border:1px solid #c3c5b8}"
             . "h1{font:700 16px/1.4 'Courier New',monospace;letter-spacing:.14em}.sub{font-size:12px;opacity:.6}"
             . ".ref{color:#6f7263}a{color:#3d4034}p{white-space:pre-line}";
    case 'table':    // ruled register, bound
        return $base . "body{background:#cfd6cd;color:#1c221c;font:" . ($mobile ? "15px" : "14.5px") . "/1.8 Georgia,'Times New Roman',serif}"
             . ".doc{background:#f6f8f2;padding:" . ($mobile ? "1.5rem 1.1rem" : "2.8rem 2.6rem") . ";border-left:3px double #93a693;box-shadow:0 10px 30px rgba(30,50,30,.10)}"
             . "h1{font:400 20px/1.3 Georgia,serif}.sub{font-size:12.5px;opacity:.62}.ref{color:#67795f}a{color:#2f4a2f}";
    case 'damaged':  // recovered sheet, foxed
        return $base . "body{background:#181410;color:#b9ab90;font:" . ($mobile ? "16px" : "16px") . "/2.1 Georgia,serif}"
             . ".doc{background:linear-gradient(157deg,#241d15,#1b1610 55%,#221b13);padding:" . ($mobile ? "1.8rem 1.2rem" : "3.4rem 3rem") . ";border:1px solid #33291c;box-shadow:inset 0 0 90px rgba(0,0,0,.65)}"
             . "h1{font:400 19px/1.4 Georgia,serif;color:#cdbf9f}.sub{font-size:12px;opacity:.5}.ref{color:#6d6046}a{color:#a08a5e}";
    case 'docket':   // pre-printed form, blue-grey
        return $base . "body{background:#d5dbe1;color:#1d2329;font:" . ($mobile ? "15.5px" : "14.5px") . "/1.8 'Helvetica Neue',Arial,sans-serif}"
             . ".doc{background:#fff;padding:" . ($mobile ? "1.5rem 1.1rem" : "2.8rem 2.8rem") . ";border-top:5px solid #5a7189}"
             . "h1{font:600 18px/1.3 'Helvetica Neue',Arial,sans-serif}.sub{font-size:12px;opacity:.6}.ref{color:#5a7189}a{color:#2f4a63}";
    case 'plate':    // photographic mount
        return $base . "body{background:#101012;color:#c9c6bf;font:" . ($mobile ? "15.5px" : "15px") . "/1.85 Georgia,serif}"
             . ".doc{background:#1a1a1c;padding:" . ($mobile ? "1.5rem 1.2rem" : "2.8rem 2.8rem") . ";border:1px solid #2c2c30}"
             . "h1{font:400 19px/1.3 Georgia,serif;color:#e2ded4}.sub{font-size:12px;opacity:.5}.ref{color:#7d7a72}a{color:#a9a396}";
    default:         // admin: plain foolscap
        return $base . "body{background:#e4e2dc;color:#23231f;font:" . ($mobile ? "16px" : "15.5px") . "/1.9 Georgia,'Times New Roman',serif}"
             . ".doc{background:#f7f6f1;padding:" . ($mobile ? "1.6rem 1.2rem" : "3rem 3rem") . ";border:1px solid #d3d0c6}"
             . "h1{font:400 20px/1.35 Georgia,serif}.sub{font-size:12.5px;opacity:.6}.ref{color:#7a776c}a{color:#3f4a3f}";
    }
}

/* each series is a different stock. the front table shows them mixed. */
function smt_stock($form) {
    switch ($form) {
    case 'letter':  return array('#f0ecdf', '#241f18', '#d3cab0', '#6d6350');
    case 'report':  return array('#e8eae0', '#26261f', '#c6c8bb', '#65685c');
    case 'table':   return array('#f2f5ee', '#1c221c', '#bfcbbd', '#5f7159');
    case 'damaged': return array('#241d15', '#b9ab90', '#3a2f20', '#7d6f52');
    case 'docket':  return array('#f7f9fb', '#1d2329', '#c3ccd6', '#5a7189');
    case 'plate':   return array('#1a1a1c', '#c9c6bf', '#33333a', '#7d7a72');
    default:        return array('#f5f4ef', '#23231f', '#d8d5c9', '#75736a');
    }
}

function smt_bar($mobile) {
    if (!$mobile) return '';
    return '<div style="position:fixed;left:0;right:0;bottom:0;display:flex;background:rgba(14,13,11,.97);border-top:1px solid #2b2620;padding-bottom:env(safe-area-inset-bottom);z-index:20">'
        . '<a href="' . smt_base() . '/" style="flex:1;text-align:center;padding:1em .2em;color:#8a8272;font:11px/1.4 \'DejaVu Sans Mono\',monospace;text-decoration:none;letter-spacing:.08em">the papers</a>'
        . '<a href="' . smt_base() . '/f" style="flex:1;text-align:center;padding:1em .2em;color:#8a8272;font:11px/1.4 \'DejaVu Sans Mono\',monospace;text-decoration:none;letter-spacing:.08em;border-left:1px solid #241f19">series</a>'
        . '<a href="' . smt_base() . '/finding-aid" style="flex:1;text-align:center;padding:1em .2em;color:#c8a44a;font:11px/1.4 \'DejaVu Sans Mono\',monospace;text-decoration:none;letter-spacing:.08em;border-left:1px solid #241f19">finding aid</a>'
        . '</div>';
}

function smt_page($title, $css, $inner, $comment = '', $mobile = false) {
    echo "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1,viewport-fit=cover\">";
    echo "<meta name=\"robots\" content=\"noindex,nofollow,noarchive\">";
    echo "<title>" . smt_h($title) . "</title>";
    if ($comment !== '') echo "\n<!-- " . $comment . " -->\n";
    echo "<style>" . $css . "</style></head><body>" . $inner . smt_bar($mobile) . "</body></html>";
}

function smt_headers($code = 200, $ct = 'text/html; charset=utf-8', $x = array()) {
    http_response_code($code);
    header('Content-Type: ' . $ct);
    header('Referrer-Policy: no-referrer');
    foreach ($x as $k => $v) header($k . ': ' . $v);
}

/* ===========================================================================
 *  THE DOCUMENT
 * ========================================================================= */

function smt_render_doc($s, $sub, $item, $tail, $mobile) {
    global $SERIES;
    $m = smt_meta($s, $sub, $item);
    if (!$m) { smt_render_404($mobile); return; }

    $depth = count($tail);                       // enclosures below the item
    $seed  = smt_fnv($m['ref'] . '::' . implode('/', $tail));

    /* deeper than the item itself: enclosures, then verso, then wreckage */
    if ($depth > 0) { smt_render_enclosure($m, $tail, $mobile); return; }

    $body = smt_body($m, 0);
    $paras = '';
    foreach (preg_split('/\n\n+/', $body) as $p) $paras .= '<p>' . nl2br(smt_h(trim($p))) . '</p>';
    $paras = str_replace(array('&lt;b&gt;', '&lt;/b&gt;'), array('<b>', '</b>'), $paras);

    $cites = smt_citations($m);
    $ch = '';
    foreach ($cites as $c) {
        if (isset($c['withdrawn'])) {
            $ch .= '<span class="wd">' . smt_h($c['ref']) . ' &nbsp;<span style="opacity:.6">withdrawn &mdash; production on a retrieval reference</span></span>';
        } else {
            $ch .= '<a href="' . smt_h($c['path']) . '">' . smt_h($c['ref']) . '</a>';
        }
    }

    /* the enclosures are the way down, and they go down without a floor */
    $encN = smt_int(smt_rng($seed ^ 0x51), 1, 3);
    $enc = '';
    for ($i = 1; $i <= $encN; $i++) {
        $nm = smt_enc_name($seed, $i, 1);
        $enc .= '<a href="' . smt_base() . '/f/' . $s . '/' . $sub . '/' . strtolower($item) . '/' . rawurlencode($nm[0]) . '">'
             . smt_h($nm[1]) . '</a>';
    }

    $inner = '<div class="doc">'
        . '<div class="ref">' . smt_h($m['ref']) . ' &middot; series ' . $s . ', ' . smt_h($m['series_title']) . '</div>'
        . '<h1>' . smt_h($m['title']) . '</h1>'
        . '<div class="sub">' . smt_h(smt_longdate($m['date'])) . ' &middot; ' . smt_h($m['author_name']) . '</div>'
        . $paras
        . smt_table($m)
        . ($enc !== '' ? '<div class="cites"><div class="ref" style="opacity:.55;margin-bottom:.4em">enclosed with this item</div>' . $enc . '</div>' : '')
        . '<div class="cites"><div class="ref" style="opacity:.55;margin-bottom:.4em">cited in this item</div>' . $ch . '</div>'
        . '<div class="foot"><a href="' . smt_base() . '/f/' . $s . '/' . $sub . '">' . smt_h(smt_ref($s, $sub, '')) . '</a> &middot; '
        . '<a href="' . smt_base() . '/f/' . $s . '">series ' . $s . '</a> &middot; <a href="' . smt_base() . '/finding-aid">finding aid</a></div>'
        . '</div>';

    smt_headers(200, 'text/html; charset=utf-8', array('X-Reference' => $m['ref']));
    smt_page($m['ref'] . ' — ' . $m['title'], smt_paper_css($m['form'], $mobile), $inner,
        smt_h($m['ref']) . '  received as found', $mobile);
}

/* what is enclosed is drawn from its own papers, never from the parent's */
function smt_enc_set($level) { return $level <= 1 ? 'near' : ($level <= 3 ? 'mid' : 'deep'); }

function smt_enc_slug($title) {
    $t = strtolower($title);
    $t = preg_replace('/[^a-z0-9]+/', '-', $t);
    return trim($t, '-');
}

/* the i-th thing enclosed at this level: (slug, title, index) */
function smt_enc_name($seed, $i, $level, $avoid = null) {
    $set   = smt_enc_set($level);
    $table = smt_enclosure_table();
    $papers = $table[$set];
    $idx = (smt_fnv($seed . '::' . $i . '::' . $level) + $i * 7) % count($papers);
    if ($avoid !== null && $idx === $avoid) $idx = ($idx + 1) % count($papers);
    $title = $papers[$idx][0];
    return array(smt_enc_slug($title) . '-' . ($i + 1), $title, $idx);
}

/* enclosures: the register turns private, then damaged, without end */
function smt_render_enclosure($m, $tail, $mobile) {
    $level = count($tail);
    $seed  = smt_fnv($m['ref'] . '::' . implode('/', $tail));
    $r     = smt_rng($seed);

    /* an enclosure is its own paper. it is never the parent served again. */
    $set    = smt_enc_set($level);
    $table  = smt_enclosure_table();
    $papers = $table[$set];
    $slug   = (string) end($tail);
    $idx    = null;
    foreach ($papers as $k => $p) {
        if (smt_enc_slug($p[0]) . '-' . '' === '') continue;
        if (strpos($slug, smt_enc_slug($p[0])) === 0) { $idx = $k; break; }
    }
    if ($idx === null) $idx = $seed % count($papers);
    $encTitle = $papers[$idx][0];
    $text     = $papers[$idx][1];

    $damage = max(0, $level - 2);
    $paras = '';
    foreach (preg_split('/\n\n+/', $text) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $paras .= '<p>' . ($damage > 0 ? smt_damage($p, $damage, $seed ^ crc32($p)) : nl2br(smt_h($p))) . '</p>';
    }

    $last = $encTitle;
    $form = $level <= 1 ? $m['form'] : ($level <= 3 ? 'letter' : 'damaged');

    /* it descends further. always. */
    $kidN = smt_int($r, 1, 3);
    $kids = '';
    for ($i = 1; $i <= $kidN; $i++) {
        $nm = smt_enc_name($seed, $i, $level + 1, smt_enc_set($level + 1) === $set ? $idx : null);
        $kids .= '<a href="' . smt_base() . '/f/' . $m['series'] . '/' . $m['sub'] . '/' . strtolower($m['item']) . '/'
              . implode('/', array_map('rawurlencode', $tail)) . '/' . rawurlencode($nm[0]) . '">' . smt_h($nm[1]) . '</a>';
    }

    $up = $tail; array_pop($up);
    $uphref = u('f/' . $m['series'] . '/' . $m['sub'] . '/' . strtolower($m['item']) . ($up ? '/' . implode('/', $up) : ''));

    $cond = $level <= 1 ? 'complete' : ($level <= 3 ? 'complete, unsigned' : ($level <= 5 ? 'foxed; partially legible' : 'water-damaged; legible in parts'));

    $inner = '<div class="doc">'
        . '<div class="ref">' . smt_h($m['ref']) . ' &middot; ' . smt_h(implode(' / ', $tail)) . '</div>'
        . '<h1>' . smt_h($last) . '</h1>'
        . '<div class="sub">enclosed at depth ' . $level . ' &middot; condition: ' . $cond . '</div>'
        . $paras
        . '<div class="cites"><div class="ref" style="opacity:.55;margin-bottom:.4em">enclosed with this</div>' . $kids . '</div>'
        . '<div class="foot"><a href="' . smt_h($uphref) . '">back one</a> &middot; '
        . '<a href="' . smt_base() . '/f/' . $m['series'] . '/' . $m['sub'] . '/' . strtolower($m['item']) . '">' . smt_h($m['ref']) . '</a> &middot; '
        . '<a href="' . smt_base() . '/finding-aid">finding aid</a></div></div>';

    smt_headers(200, 'text/html; charset=utf-8', array('X-Depth' => (string) $level));
    smt_page($m['ref'] . ' — ' . $last, smt_paper_css($form, $mobile), $inner, 'condition: ' . $cond, $mobile);
}

/* ===========================================================================
 *  LISTINGS
 * ========================================================================= */

function smt_listing_css($mobile) {
    return "*{box-sizing:border-box}html,body{margin:0}body{background:#14120f;color:#c3bbaa;"
        . "font:" . ($mobile ? "16px" : "15px") . "/1.75 Georgia,serif;padding:" . ($mobile ? "1.2rem 1rem 5.5rem" : "3rem 2rem 5rem") . "}"
        . ".doc{max-width:" . ($mobile ? "none" : "44em") . ";margin:0 auto}"
        . ".ref{font:11px/1.6 'DejaVu Sans Mono',monospace;letter-spacing:.18em;text-transform:uppercase;color:#6d6552}"
        . "h1{font:400 " . ($mobile ? "23px" : "26px") . "/1.25 Georgia,serif;color:#e2d9c4;margin:.2em 0 1.2em}"
        . "a{color:#c4a869;text-decoration:none}"
        . ".row{display:block;padding:" . ($mobile ? ".95em 0" : ".55em 0") . ";border-bottom:1px solid #241f19;font:14px/1.5 'DejaVu Sans Mono',monospace}"
        . ".row .t{color:#cdbf9f;font-family:Georgia,serif;font-size:" . ($mobile ? "16px" : "15px") . "}"
        . ".row .s{display:block;color:#6d6552;font-size:12px;margin-top:.15em}"
        . ".foot{margin-top:2.6em;font:11px/1.9 'DejaVu Sans Mono',monospace;color:#5b5445}"
        . ".note{color:#8a8170;font-size:" . ($mobile ? "15px" : "14px") . ";margin:0 0 1.8em}";
}

function smt_render_series_list($mobile) {
    global $SERIES;
    $rows = '';
    foreach ($SERIES as $n => $S) {
        $sealed = ($n === 12 && !smt_retrieved());
        $subs = count(smt_subs($n));
        $rows .= '<a class="row" href="' . smt_base() . '/f/' . $n . '">' . str_pad((string) $n, 2, '0', STR_PAD_LEFT)
              . ' &nbsp;<span class="t">' . smt_h($S[0]) . '</span>'
              . '<span class="s">' . $subs . ' subseries' . ($sealed ? ' &middot; retained; production on a retrieval reference' : '') . '</span></a>';
    }
    $inner = '<div class="doc"><div class="ref">Holloway Point Tidal Station &middot; 1961&ndash;1997</div>'
        . '<h1>Arrangement of the papers</h1>'
        . '<p class="note">The papers were received in twelve series and have been kept in the order in which they were received. '
        . 'Where two copies of a paper were received both are retained.</p>'
        . $rows
        . '<div class="foot"><a href="' . smt_base() . '/">the papers as displayed</a> &middot; <a href="' . smt_base() . '/finding-aid">finding aid</a></div></div>';
    smt_headers();
    smt_page('Arrangement of the papers', smt_listing_css($mobile), $inner, '', $mobile);
}

function smt_render_series($s, $mobile) {
    global $SERIES;
    if (!isset($SERIES[$s])) { smt_render_404($mobile); return; }
    if ($s === 12 && !smt_retrieved()) { smt_render_sealed('f/12', $mobile); return; }
    $rows = '';
    foreach (smt_subs($s) as $sub) {
        $n = count(smt_items($s, $sub));
        $rows .= '<a class="row" href="' . smt_base() . '/f/' . $s . '/' . $sub . '">' . $s . '/' . $sub
              . '<span class="s">' . $n . ' items</span></a>';
    }
    $inner = '<div class="doc"><div class="ref">series ' . $s . '</div><h1>' . smt_h($SERIES[$s][0]) . '</h1>'
        . $rows . '<div class="foot"><a href="' . smt_base() . '/f">all series</a> &middot; <a href="' . smt_base() . '/finding-aid">finding aid</a></div></div>';
    smt_headers();
    smt_page('Series ' . $s, smt_listing_css($mobile), $inner, '', $mobile);
}

function smt_render_sub($s, $sub, $mobile) {
    global $SERIES;
    if (!isset($SERIES[$s])) { smt_render_404($mobile); return; }
    if ($s === 12 && !smt_retrieved()) { smt_render_sealed("f/$s/$sub", $mobile); return; }
    $rows = '';
    foreach (smt_items($s, $sub) as $it) {
        $m = smt_meta($s, $sub, $it);
        $rows .= '<a class="row" href="' . smt_base() . '/f/' . $s . '/' . $sub . '/' . strtolower($it) . '">' . smt_h($m['ref'])
              . ' &nbsp;<span class="t">' . smt_h($m['title']) . '</span>'
              . '<span class="s">' . smt_h(smt_longdate($m['date'])) . ' &middot; ' . smt_h($m['author_name']) . '</span></a>';
    }
    $inner = '<div class="doc"><div class="ref">' . $s . '/' . $sub . '</div><h1>' . smt_h($SERIES[$s][0]) . '</h1>'
        . $rows . '<div class="foot"><a href="' . smt_base() . '/f/' . $s . '">series ' . $s . '</a> &middot; <a href="' . smt_base() . '/finding-aid">finding aid</a></div></div>';
    smt_headers();
    smt_page($s . '/' . $sub, smt_listing_css($mobile), $inner, '', $mobile);
}

/* ===========================================================================
 *  THE FINDING AID — a real search over the real corpus
 * ========================================================================= */

function smt_search($q, $limit = 60) {
    global $SERIES, $W;
    $q = trim(mb_strtolower($q));
    if ($q === '') return array();
    $hits = array();
    /* an exact reference goes straight there */
    if (preg_match('#^(\d{1,2})\s*/\s*(\d{1,2})\s*/\s*([a-z]-?\d{1,3})$#i', str_replace(' ', '', $q), $mm)) {
        $s = (int) $mm[1]; $sub = (int) $mm[2]; $it = strtoupper($mm[3]);
        if (strpos($it, '-') === false) $it = substr($it, 0, 1) . '-' . str_pad(substr($it, 1), 3, '0', STR_PAD_LEFT);
        $m = smt_meta($s, $sub, $it);
        if ($m && in_array($it, smt_items($s, $sub), true)) return array($m);
    }
    foreach ($SERIES as $s => $S) {
        foreach (smt_subs($s) as $sub) {
            foreach (smt_items($s, $sub) as $it) {
                $m = smt_meta($s, $sub, $it);
                if (!$m) continue;
                $hay = mb_strtolower($m['ref'] . ' ' . $m['title'] . ' ' . $m['series_title'] . ' '
                     . $m['author_name'] . ' ' . $m['date'] . ' ' . smt_longdate($m['date']) . ' ' . $m['object']);
                if (mb_strpos($hay, $q) !== false) {
                    $hits[] = $m;
                    if (count($hits) >= $limit) return $hits;
                }
            }
        }
    }
    return $hits;
}

function smt_render_finding_aid($mobile) {
    global $W;
    $q = isset($_GET['q']) ? substr((string) $_GET['q'], 0, 80) : '';
    $hits = $q !== '' ? smt_search($q) : array();

    $rows = '';
    if ($q !== '') {
        if (!$hits) {
            $rows = '<p class="note">Nothing in the papers answers to that. The papers are not complete; '
                  . 'the finding aid is complete as to what is here.</p>';
        } else {
            foreach ($hits as $m) {
                $sealed = ($m['series'] === 12 && !smt_retrieved());
                if ($sealed) {
                    $rows .= '<span class="row" style="opacity:.72">' . smt_h($m['ref'])
                          . ' &nbsp;<span class="t">' . smt_h($m['title']) . '</span>'
                          . '<span class="s">retained &middot; production on a retrieval reference</span></span>';
                } else {
                    $rows .= '<a class="row" href="' . smt_base() . '/f/' . $m['series'] . '/' . $m['sub'] . '/' . strtolower($m['item']) . '">'
                          . smt_h($m['ref']) . ' &nbsp;<span class="t">' . smt_h($m['title']) . '</span>'
                          . '<span class="s">' . smt_h(smt_longdate($m['date'])) . ' &middot; ' . smt_h($m['author_name'])
                          . ' &middot; series ' . $m['series'] . '</span></a>';
                }
            }
            $rows = '<div class="ref" style="margin:1.4em 0 .4em">' . count($hits) . ' item'
                  . (count($hits) === 1 ? '' : 's') . '</div>' . $rows;
        }
    }

    $names = '';
    foreach ($W['people'] as $k => $p)
        $names .= '<a href="' . smt_base() . '/finding-aid?q=' . rawurlencode($p[0]) . '" style="margin-right:1.2em;white-space:nowrap">' . smt_h($p[0]) . '</a> ';
    $objs = '';
    foreach (array('BM-41', '1971', 'north stair', 'plate 12 of 9', 'the second register') as $o)
        $objs .= '<a href="' . smt_base() . '/finding-aid?q=' . rawurlencode($o) . '" style="margin-right:1.2em;white-space:nowrap">' . smt_h($o) . '</a> ';

    $inner = '<div class="doc"><div class="ref">finding aid</div><h1>Search the papers</h1>'
        . '<form method="get" action="' . smt_base() . '/finding-aid" style="margin:0 0 1.6em">'
        . '<input name="q" value="' . smt_h($q) . '" autocomplete="off" autocapitalize="off" spellcheck="false" '
        . 'placeholder="a name, a year, a reference" style="width:100%;padding:' . ($mobile ? '.95em' : '.7em') . ';'
        . 'font:' . ($mobile ? '17px' : '15px') . "/1.4 'DejaVu Sans Mono',monospace;background:#0d0b09;color:#d8c9a4;border:1px solid #332c22\">"
        . '<button style="margin-top:.7em;' . ($mobile ? 'width:100%;' : '') . 'padding:' . ($mobile ? '.95em 1em' : '.6em 1.6em')
        . ';font:' . ($mobile ? '16px' : '14px') . "/1 'DejaVu Sans Mono',monospace;background:#1c1811;color:#c8b48a;border:1px solid #332c22;cursor:pointer\">search</button></form>"
        . '<p class="note" style="font:13px/1.9 \'DejaVu Sans Mono\',monospace">hands: ' . $names . '<br style="line-height:2.4">'
        . 'in the papers: ' . $objs . '</p>'
        . $rows
        . '<div class="foot"><a href="' . smt_base() . '/f">arrangement of the papers</a> &middot; <a href="' . smt_base() . '/">the papers as displayed</a></div></div>';

    smt_headers();
    smt_page('Finding aid', smt_listing_css($mobile), $inner, '', $mobile);
}

/* ===========================================================================
 *  THE SEALED SERIES — opened by a reference you found cited
 * ========================================================================= */

function smt_retrieved() { return isset($_COOKIE['smt_r']) && $_COOKIE['smt_r'] === '1'; }

/* a reference is valid if it really is an item of series 12 */
function smt_valid_retrieval($q) {
    $q = strtoupper(str_replace(' ', '', trim((string) $q)));
    if (!preg_match('#^12/(\d{1,2})/([A-Z])-?(\d{1,3})$#', $q, $m)) return null;
    $sub = (int) $m[1];
    $it  = $m[2] . '-' . str_pad($m[3], 3, '0', STR_PAD_LEFT);
    if (!in_array($sub, smt_subs(12), true)) return null;
    if (!in_array($it, smt_items(12, $sub), true)) return null;
    return array($sub, $it);
}

function smt_render_sealed($path, $mobile, $msg = '') {
    $r = smt_rng(smt_fnv('sealed::' . $path));
    $n = smt_int($r, 6, 23);
    $rows = '';
    for ($i = 0; $i < $n; $i++) {
        $subs = smt_subs(12); $sub = $subs[smt_int($r, 0, count($subs) - 1)];
        $items = smt_items(12, $sub); $it = $items[smt_int($r, 0, count($items) - 1)];
        $rows .= '<tr><td>' . smt_ref(12, $sub, $it) . '</td><td class="n">' . number_format(smt_int($r, 1, 214)) . ' ff.</td>'
              . '<td style="opacity:.6">retained</td></tr>';
    }
    $css = "*{box-sizing:border-box}html,body{margin:0}body{background:#0f0d0c;color:#b8ada0;"
         . "font:" . ($mobile ? "16px" : "15px") . "/1.8 Georgia,serif;padding:" . ($mobile ? "1.2rem 1rem 5.5rem" : "3rem 2rem") . "}"
         . ".doc{max-width:" . ($mobile ? "none" : "40em") . ";margin:0 auto}"
         . ".ref{font:11px/1.6 'DejaVu Sans Mono',monospace;letter-spacing:.18em;text-transform:uppercase;color:#7a5f4f}"
         . "h1{font:400 " . ($mobile ? "22px" : "25px") . "/1.3 Georgia,serif;color:#d8c2ad;margin:.2em 0 1em}"
         . "table{border-collapse:collapse;width:100%;font:" . ($mobile ? "12px" : "13px") . "/1.6 'DejaVu Sans Mono',monospace;margin:1.4em 0}"
         . "td{padding:.2em .6em .2em 0;border-bottom:1px solid rgba(128,128,128,.14)}td.n{text-align:right}"
         . "a{color:#c09a6a}.foot{margin-top:2.4em;font:11px/1.9 'DejaVu Sans Mono',monospace;color:#5f564b}";

    $inner = '<div class="doc"><div class="ref">403 &middot; /' . smt_h($path) . '</div>'
        . '<h1>Series 12 is retained</h1>'
        . '<p>The series is not produced on demand and is not listed. The schedule of what is retained is not itself retained, and is reproduced here:</p>'
        . '<table>' . $rows . '</table>'
        . '<p>An item is produced on a retrieval reference. References into this series are cited in the papers of the other eleven, '
        . 'in the ordinary way, under the note <i>withdrawn</i>.</p>'
        . '<form method="post" action="' . smt_base() . '/retrieve" style="margin:1.4em 0">'
        . '<input name="r" placeholder="12/0/A-000" autocomplete="off" autocapitalize="characters" spellcheck="false" '
        . 'style="width:' . ($mobile ? '100%' : '14em') . ';padding:' . ($mobile ? '.95em' : '.6em')
        . ";font:" . ($mobile ? '18px' : '15px') . "/1.3 'DejaVu Sans Mono',monospace;background:#0a0806;color:#e0c88a;border:1px solid #3a3020;text-align:center\">"
        . '<button style="' . ($mobile ? 'display:block;width:100%;margin-top:.8em;padding:.95em' : 'margin-left:.6em;padding:.62em 1.4em')
        . ";font:" . ($mobile ? '16px' : '14px') . "/1 'DejaVu Sans Mono',monospace;background:#1c1811;color:#c8b48a;border:1px solid #3a3020;cursor:pointer\">apply for production</button>"
        . ($msg !== '' ? '<div style="margin-top:1em;font:' . ($mobile ? '15px' : '13px') . '/1.7 \'DejaVu Sans Mono\',monospace;color:#b07a5a">' . smt_h($msg) . '</div>' : '')
        . '</form>'
        . '<div class="foot"><a href="' . smt_base() . '/f">arrangement of the papers</a> &middot; <a href="' . smt_base() . '/finding-aid">finding aid</a></div></div>';

    smt_headers(403, 'text/html; charset=utf-8', array(
        'X-Retained' => (string) $n,
        'Retry-After' => gmdate('D, d M Y H:i:s', 4102444800) . ' GMT',
    ));
    smt_page('Series 12 is retained', $css, $inner, 'production on a retrieval reference', $mobile);
}

function smt_handle_retrieve($mobile) {
    $q = isset($_POST['r']) ? (string) $_POST['r'] : '';
    $v = smt_valid_retrieval($q);
    if ($v === null) {
        $shown = trim($q) === '' ? 'No reference was applied for.'
               : 'There is no item ' . strtoupper(trim($q)) . ' in series 12. The reference is not in the schedule.';
        smt_render_sealed('f/12', $mobile, $shown);
        return;
    }
    @setcookie('smt_r', '1', time() + 31536000, '/');
    $_COOKIE['smt_r'] = '1';
    header('Location: ' . smt_base() . '/f/12/' . $v[0] . '/' . strtolower($v[1]), true, 303);
    exit;
}

/* ===========================================================================
 *  THE SURFACE — what the domain shows before you know it is an archive
 * ========================================================================= */

function smt_render_front($mobile) {
    global $SERIES;
    $day = smt_fnv('front::' . gmdate('Y-z'));
    $r   = smt_rng($day);

    /* three real papers, drawn from the real corpus, shown whole-ish */
    $picks = array(); $seenPaper = array();
    $want = array(9, 8, 4, 7, 6, 10, 11, 2);
    $need = $mobile ? 3 : 4;
    for ($t = 0; $t < 240 && count($picks) < $need; $t++) {
        $s = $want[($day + $t * 7) % count($want)];
        $subs  = smt_subs($s);  $sub = $subs[($day + $t * 3) % count($subs)];
        $items = smt_items($s, $sub); $it = $items[($day + $t * 5) % count($items)];
        $m = smt_meta($s, $sub, $it);
        if (!$m) continue;
        /* never lay the same paper on the table twice */
        $sig = $m['kind'] . ':' . ($m['seed'] % count(smt_papers($m['kind'])));
        if (isset($seenPaper[$sig])) continue;
        $seenPaper[$sig] = 1;
        $picks[] = $m;
    }

    $css = "*{box-sizing:border-box}html,body{margin:0}"
        . "body{background:#131110;color:#b9b1a2;font:" . ($mobile ? "16px" : "16px") . "/1.85 Georgia,serif;"
        . "padding:" . ($mobile ? "1.2rem 1rem 5.5rem" : "4.5rem 2rem 6rem") . "}"
        . ".w{max-width:" . ($mobile ? "none" : "40em") . ";margin:0 auto}"
        . ".mono{font-family:'DejaVu Sans Mono',monospace}"
        . ".ref{font:11px/1.7 'DejaVu Sans Mono',monospace;letter-spacing:.18em;text-transform:uppercase;color:#6b6352}"
        . "a{color:#c4a869;text-decoration:none;border-bottom:1px solid rgba(196,168,105,.28)}"
        . ".card{margin:2.6rem 0;padding:" . ($mobile ? "1.3rem 1.1rem" : "2rem 2.2rem") . ";border:1px solid #262019}"
        . ".card h2{font:400 " . ($mobile ? "18px" : "19px") . "/1.35 Georgia,serif;color:#ded3ba;margin:.2em 0 .1em}"
        . ".card .m{font:11px/1.7 'DejaVu Sans Mono',monospace;color:#6b6352;letter-spacing:.1em;text-transform:uppercase}"
        . ".card p{margin:.9em 0 0;color:#a89f8e}"
        . ".big{font:400 " . ($mobile ? "26px" : "34px") . "/1.25 Georgia,serif;color:#e6dcc6;margin:.1em 0 .6em}"
        . ".dim{color:#7d7566}";

    $h = '<div class="w">';
    $h .= '<div class="ref">Holloway Point &middot; 47&deg;18&prime;43&Prime;N 7&deg;01&prime;16&Prime;E &middot; station closed 1997</div>';
    $h .= '<div class="big">The papers of a station that was asked for one figure.</div>';
    $h .= '<p class="dim">Twelve series were received. Series 12 is retained. Where two copies of a paper were received, '
        . 'both are kept, including where they do not agree.</p>';
    $h .= '<p><a href="' . smt_base() . '/f">Arrangement of the papers</a> &nbsp;&middot;&nbsp; <a href="' . smt_base() . '/finding-aid">Finding aid</a></p>';

    foreach ($picks as $m) {
        if (!$m) continue;
        $lim = $mobile ? 330 : 430;
        $ex = ''; 
        foreach (preg_split('/\n\n+/', smt_body($m, 0)) as $pp) {
            $pp = trim($pp);
            if ($pp === '') continue;
            if ($ex !== '' && mb_strlen($ex) + mb_strlen($pp) > $lim) break;
            $ex .= ($ex === '' ? '' : "\n\n") . $pp;
            if (mb_strlen($ex) >= $lim) break;
        }
        if (mb_strlen($ex) > $lim) $ex = rtrim(mb_substr($ex, 0, $lim)) . '\u{2026}';
        list($bg, $ink, $rule, $faint) = smt_stock($m['form']);
        $serif = in_array($m['form'], array('letter', 'report'), true)
               ? "'Courier New',Courier,monospace" : "Georgia,'Times New Roman',serif";
        $h .= '<div class="card" style="background:' . $bg . ';color:' . $ink . ';border-color:' . $rule
           . ';font-family:' . $serif . '">'
           . '<div class="m" style="color:' . $faint . '">' . smt_h($m['ref']) . ' &middot; ' . smt_h($m['series_title']) . '</div>'
           . '<h2 style="color:' . $ink . ';font-family:' . $serif . '">' . smt_h($m['title']) . '</h2>'
           . '<div class="m" style="text-transform:none;letter-spacing:0;color:' . $faint . '">'
           . smt_h(smt_longdate($m['date'])) . ' &middot; ' . smt_h($m['author_name']) . '</div>'
           . '<p style="color:' . $ink . ';white-space:pre-line">' . smt_h($ex) . '</p>'
           . '<p><a style="color:' . $faint . ';border-bottom-color:' . $rule . '" href="' . smt_base() . '/f/' . $m['series'] . '/' . $m['sub']
           . '/' . strtolower($m['item']) . '">' . smt_h($m['ref']) . '</a></p></div>';
    }

    $h .= '<div class="ref" style="margin-top:3rem">the station\'s own note, found loose in series 1</div>';
    $h .= '<p class="dim" style="font-style:italic">The register shall be kept in duplicate. The second copy shall be retained at the station and shall not be sent.</p>';
    $h .= '<div class="ref" style="margin-top:3rem"><a href="' . smt_base() . '/f">series</a> &middot; <a href="' . smt_base() . '/finding-aid">finding aid</a> &middot; '
        . '<a href="' . smt_base() . '/humans.txt">the hands</a></div>';
    $h .= '</div>';

    smt_headers();
    smt_page('Holloway Point Tidal Station — papers', $css, $h,
        'the second copy was not sent. it is here.', $mobile);
}

/* ===========================================================================
 *  ODDS
 * ========================================================================= */

function smt_render_404($mobile) {
    $inner = '<div class="doc"><div class="ref">404</div><h1>Not in the papers</h1>'
        . '<p class="note">No item answers to that reference. The arrangement is at '
        . '<a href="' . smt_base() . '/f">series level</a>; the <a href="' . smt_base() . '/finding-aid">finding aid</a> searches what is here.</p></div>';
    smt_headers(404);
    smt_page('Not in the papers', smt_listing_css($mobile), $inner, '', $mobile);
}

function smt_render_humans() {
    smt_headers(200, 'text/plain; charset=utf-8');
    echo "HANDS ON THE PAPERS\n\n";
    echo "  A. Rell      keeper, 1961-1979. kept the older figure.\n";
    echo "  M. Hoyle     surveyor, from 1971. entered the other one.\n";
    echo "  E. Beazley   for the Board. required a single value.\n";
    echo "  J. Tarn      assistant keeper, 1988-1997. locked up.\n";
    echo "  the clerk    transcribed. unnamed throughout.\n\n";
    echo "THE MARK\n  BM-41, at Holloway Point. found undisturbed at every observation,\n";
    echo "  including the observation of 1971, at which the height changed.\n\n";
    echo "OUTSTANDING\n  survey of the north stair: pending. the stair is closed pending survey.\n";
    echo "  reconciliation of the register: requested 1971, requested 1972, requested 1974.\n";
    echo "  one figure: not returned.\n";
}

function smt_render_robots() {
    smt_headers(200, 'text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: " . smt_base() . "/f/12/\nCrawl-delay: 20\n";
    echo "# series 12 is retained. it is not hidden; it is not produced.\n";
}

/* ===========================================================================
 *  ROUTER
 * ========================================================================= */

function smt_path() {
    if (isset($_GET['p'])) return trim(preg_replace('#//+#', '/', (string) $_GET['p']), '/');
    $uri = $_SERVER['REDIRECT_URL'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
    $p = parse_url($uri, PHP_URL_PATH);
    if ($p === false || $p === null) $p = '/';
    $p = rawurldecode($p);
    $b = smt_base();
    if ($b !== '' && strncmp($p, $b, strlen($b)) === 0) $p = substr($p, strlen($b));
    $p = preg_replace('#^/index\.php#', '', $p);
    $p = preg_replace('#//+#', '/', $p);
    return trim($p, '/');
}

function smt_is_mobile() {
    if (isset($_GET['m'])) {
        $v = $_GET['m'] === '1' ? '1' : '0';
        @setcookie('smt_m', $v, time() + 31536000, '/');
        return $v === '1';
    }
    if (isset($_COOKIE['smt_m'])) return $_COOKIE['smt_m'] === '1';
    return (bool) preg_match('/Android|iPhone|iPod|iPad|Mobile|Silk|Opera Mini|IEMobile|BlackBerry|webOS/i',
        $_SERVER['HTTP_USER_AGENT'] ?? '');
}

$mobile = smt_is_mobile();
$path   = smt_path();
$err    = isset($_GET['__err']) ? (int) $_GET['__err'] : 0;
$seg    = $path === '' ? array() : explode('/', $path);

if ($path === 'retrieve' && $_SERVER['REQUEST_METHOD'] === 'POST') { smt_handle_retrieve($mobile); exit; }
if ($path === 'retrieve') { header('Location: ' . smt_base() . '/f/12', true, 303); exit; }

if ($err === 403) { smt_render_sealed('f/12', $mobile); exit; }

if ($path === 'favicon.ico') { http_response_code(204); exit; }
if ($path === 'index.html' || $path === 'index.htm') { header('Location: ' . smt_base() . '/', true, 302); exit; }
if ($path === 'robots.txt')  { smt_render_robots(); exit; }
if ($path === 'humans.txt')  { smt_render_humans(); exit; }
if ($path === 'finding-aid') { smt_render_finding_aid($mobile); exit; }
if ($path === '')            { smt_render_front($mobile); exit; }

if ($seg[0] === 'f') {
    $n = count($seg);
    if ($n === 1) { smt_render_series_list($mobile); exit; }
    $s = (int) $seg[1];
    if ($s === 12 && !smt_retrieved()) { smt_render_sealed($path, $mobile); exit; }
    if ($n === 2) { smt_render_series($s, $mobile); exit; }
    $sub = (int) $seg[2];
    if ($n === 3) { smt_render_sub($s, $sub, $mobile); exit; }
    $item = strtoupper($seg[3]);
    if (strpos($item, '-') === false && preg_match('/^([A-Z])(\d+)$/', $item, $mm))
        $item = $mm[1] . '-' . str_pad($mm[2], 3, '0', STR_PAD_LEFT);
    if (!in_array($item, smt_items($s, $sub), true)) { smt_render_404($mobile); exit; }
    smt_render_doc($s, $sub, $item, array_slice($seg, 4), $mobile);
    exit;
}

smt_render_404($mobile);
