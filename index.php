<?php
/* ============================================================================
 *  ::: smtstrange :::  the apparatus
 * ----------------------------------------------------------------------------
 *  Doors answer to ward-sets. Ward-sets are recovered a ward at a time, by
 *  walking. The answering is honest: the seal reports which positions agree.
 *  Everything here is solvable. Nothing here concludes.
 *  4e 6f 74 20 65 76 65 72 79 20 70 61 74 68 20 6c 65 61 64 73 20 6f 75 74 2e
 * ========================================================================== */

@ini_set('display_errors', '0');
error_reporting(0);
date_default_timezone_set('UTC');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

define('SMT_ROOT', __DIR__);
define('SMT_EPOCH', 872035200);        // 1997-08-20
define('SMT_WARDS', 5);                // wards per first-order door
define('SMT_WARDS2', 7);               // wards per second-order door
define('SMT_ALPHA', 'abcdefghijklmnopqrstuvwxyz0123456789');

$SMT_SEALED   = array('vault','attic','cellar','oubliette','reliquary','strongroom','ossuary','coldroom');
$SMT_OPENABLE = array('vault','attic','cellar','oubliette','reliquary','strongroom');

/* ---------------------------------------------------------------------------
 *  the apparatus lays its own doors back down if they are ever taken away
 * ------------------------------------------------------------------------- */
(function () {
    $ht = SMT_ROOT . '/.htaccess';
    if (@is_file($ht) || !@is_writable(SMT_ROOT)) return;
    $r = "Options -Indexes +FollowSymLinks -MultiViews\nServerSignature Off\nDirectoryIndex index.php\nAddDefaultCharset utf-8\n"
       . "<Files \".htaccess\">\n    Require all denied\n</Files>\n"
       . "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteBase /\n"
       . "    RewriteRule ^index\\.html?$ / [R=302,L]\n"
       . "    RewriteRule (^|/)\\.git(/|$) - [F,L]\n";
    foreach (array('vault','attic','cellar','oubliette','reliquary','strongroom') as $z)
        $r .= "    RewriteCond %{HTTP_COOKIE} !smt_w_$z=1 [NC]\n    RewriteRule ^$z(/.*)?$ - [F,L]\n";
    $r .= "    RewriteRule ^(ossuary|coldroom)(/.*)?$ - [F,L]\n"
       . "    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteCond %{REQUEST_FILENAME} !-d\n"
       . "    RewriteRule ^ index.php [L]\n</IfModule>\n"
       . "ErrorDocument 403 /index.php?__err=403\nErrorDocument 404 /index.php?__err=404\nErrorDocument 410 /index.php?__err=410\n";
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
        $s = ($s + 0x6D2B79F5) & 0xffffffff;
        $t = $s;
        $t = smt_imul($t ^ ($t >> 15), $t | 1) & 0xffffffff;
        $t = ($t ^ ($t + smt_imul($t ^ ($t >> 7), $t | 61))) & 0xffffffff;
        return (($t ^ ($t >> 14)) & 0xffffffff) / 4294967296.0;
    };
}
function smt_pick($rng, $arr) { return $arr ? $arr[(int) floor($rng() * count($arr)) % count($arr)] : ''; }
function smt_int($rng, $lo, $hi) { return $lo + (int) floor($rng() * ($hi - $lo + 1)); }
function smt_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function smt_b36($n, $len = 0) {
    $s = base_convert((string) ($n & 0xffffffff), 10, 36);
    return $len ? str_pad($s, $len, '0', STR_PAD_LEFT) : $s;
}

/* ===========================================================================
 *  TEXT POOLS
 * ========================================================================= */

$SMT_SLUGWORDS = array(
    'antimony','oakum','verdigris','quire','marl','sallow','tithe','pyx','clinker','bittern',
    'gnomon','spandrel','ferrule','oxbow','hoarfrost','claghole','withy','swale','grommet','tallow',
    'quicklime','fetch','coomb','lych','muntin','scree','baffle','shroud','ingot','solder',
    'flux','cathode','anode','dross','sinter','borax','realgar','cinnabar','galena','stannic',
    'compline','matins','lauds','sext','terce','vigils','ember','rogation','vespers','nocturn',
    'ledger','tare','demurrage','escheat','distraint','usufruct','quitrent','socage','corvee','tallage',
    'northeast','downwind','leeward','offing','gloaming','murk','smother','haar','pother','damp',
    'reredos','wainscot','soffit','architrave','plinth','corbel','voussoir','keystone','mullion','transom',
    'assay','cupel','litharge','bloom','slag','matte','regulus','speiss','fettle','clinkering',
    'palimpsest','colophon','recto','verso','gathering','foredge','deckle','watermark','chainline','quireman',
    'holloway','causey','strand','skerry','holm','carr','ness','spit','hollow','fenland',
    'nadir','apsis','syzygy','umbra','penumbra','antumbra','occultation','ingress','crepuscule','antemeridian',
);

$SMT_POOL_LINES = array(
    'the count was taken twice and did not settle',
    'reading held at 0.037 mV for the length of the shift',
    'do not open before the frost has left the north face',
    'ledger 4 continues in a hand that is not the clerk\'s',
    'item withdrawn; the accession card remains',
    'checksum agrees; the contents do not',
    'moved to a room that is no longer on the plan',
    'the barometer fell all night and no weather came',
    'signed for by a name struck through twice',
    'three of the eleven crates are heavier empty',
    'measured at 41 mm along the fracture, both times',
    'a smell of solder in a building with no power',
    'the clause is satisfiable only if line 9 is a lie',
    'return to sender: sender not established',
    'the tide table lists a fourth tide',
    'kept for reference; reference lost',
    'annotation in the margin: "again, and slower"',
    'the sample logged itself out at 03:11 and back in at 03:11',
    'north stair sealed pending survey; survey pending north stair',
    'the plate is numbered 12 of 9',
    'weight on arrival exceeds weight at dispatch by a hand',
    'this entry supersedes an entry that was never made',
    'the key fits and turns and the door does not know it',
    'left running; nobody remembers to what end',
    'the second witness could not be found to disagree',
    'catalogued under a heading that has since been removed',
    'the drip is regular to the second and answers nothing',
    'quiet on all channels except the one that is disconnected',
    'a page torn out with the tear catalogued separately',
    'the register skips from 88 to 90 and is complete',
    'held for collection past the collector',
    'the coordinate resolves to a wall',
    'do not reconcile these figures; they were never apart',
    'the lamp is warm and has not been lit',
    'the file was closed by someone still filing it',
    'measured drift: one degree per year, toward nothing named',
    'the interior is larger by the width of the door',
    'stamped RECEIVED over a stamp that says RETURNED',
    'the last line of the manifest is the manifest',
    'sealed with the seal missing',
    'continued three leaves down, in the same hand, wetter',
    'not here. was moved. moved from where it is now.',
    'the fourth tide is not on the table and comes anyway',
    'proceed north until the corridor disagrees',
    'the echo answers before the channel is opened',
    'gravel sample: 41 g dry, 41 g wet, 39 g held in the hand',
    'observed at compline; not observed; observed again',
    'nichts unter der oberfläche bewegt sich',
    'quod mensum est bis, non convenit',
    'the plate that follows 12 is 12',
    'a door catalogued twice under two thicknesses',
    'the clerk\'s second hand is warmer than the first',
    'coordinate withheld; it resolves inward',
    'log carrier present; no traffic; the carrier is the traffic',
    'the survey found the stair and could not survey it',
    'return path exists and does not return',
    'weighed on collection; the collector was included',
    'the index disagrees with everything it indexes',
    'held against a claim that was never made',
    'the sample logged a temperature the room never reached',
    'read the margin first; the margin was added last',
    'do not reconcile; they were filed apart to stay together',
    'the lamp is warm on the side away from the wall',
    'entry sealed pending the entry that seals it',
    'the corridor is longer returning than going',
    'measured inward: the room grows by the door each frost',
    'a hand struck the name and signed the striking',
    'the tide came in at the wrong stair and left dry',
    'checksum recomputed; the file had recomputed first',
    'the fourth prong of the track is claimed by no particle',
    'the frost left the north face and took a figure with it',
    'reading steady at 0.037; the instrument is disconnected',
    'the crate is heavier for having been opened',
    'filed under a letter the alphabet no longer keeps',
    'the door knows the key and refuses the hand',
    'ended at 03:11; resumed at 03:11; the minute is missing',
);

$SMT_POOL_LABELS = array(
    'Fourth Tide','Cold Store 2','Ledger 4 (cont.)','The Struck Name','Plate 12 of 9',
    'North Stair','Channel Disconnected','Accession Withdrawn','The Warm Lamp','Terce',
    'Register 88–90','The Heavier Empty','Marginal Hand','Survey Pending','Room Off Plan',
    'Return, No Sender','Reading 0.037','Solder, No Power','The Second Witness','Compline',
    'Wall Coordinate','Dispatch Weight','The Regular Drip','Quire 7','Held Past Collection',
    'Tare & Demurrage','Frost, North Face','The Torn Page','Distraint','Offing',
    'Fourth Prong','The Wetter Hand','Leaf 40-odd','Sext','The Included Collector',
    'Carrier, No Traffic','Room Grows Inward','The Missing Minute','Plate After 12','Vigils',
    'The Flat Barograph','Corridor, Returning','Two Thicknesses','The Struck Signature','Lauds',
    'Reference, Held Elsewhere','The Inward Coordinate','Ember Day','Wrong Stair','Matins',
);

/* ===========================================================================
 *  WARDS — the honest lock
 * ========================================================================= */

/* the ward-set a door answers to. stable forever. */
function smt_zone_key($zone, $order = 1) {
    $n = $order === 2 ? SMT_WARDS2 : SMT_WARDS;
    $rng = smt_rng(smt_fnv('wardset::' . $order . '::' . $zone));
    $k = '';
    for ($i = 0; $i < $n; $i++) $k .= SMT_ALPHA[(int) floor($rng() * 36) % 36];
    return $k;
}

/* does this leaf hold a ward? deterministic. roughly one in six. */
function smt_ward_at($path) {
    global $SMT_OPENABLE;
    if ($path === '') return null;
    $seed = smt_fnv('wardat::' . $path);
    if (($seed % 6) !== 0) return null;
    $depth = substr_count($path, '/');
    // the shallow leaves all belong to the first door; the rest scatter
    $zone = ($depth <= 1) ? 'vault' : $SMT_OPENABLE[($seed >> 5) % count($SMT_OPENABLE)];
    $order = ($depth >= 4 && (($seed >> 3) & 1)) ? 2 : 1;
    $n = $order === 2 ? SMT_WARDS2 : SMT_WARDS;
    $pos = ($seed >> 11) % $n;
    $key = smt_zone_key($zone, $order);
    return array('zone' => $zone, 'pos' => $pos, 'order' => $order, 'char' => $key[$pos]);
}

/* find a leaf that really does hold a given ward (used by the cipher) */
function smt_find_ward_leaf($zone, $pos, $order = 1) {
    global $SMT_SLUGWORDS;
    $sfx = array('', '-1', '-2', '-3', '-4', '-5', '-7', '-9');
    foreach ($SMT_SLUGWORDS as $w) {
        foreach ($sfx as $s) {
            $p = 'gate/' . $w . $s;
            $wd = smt_ward_at($p);
            if ($wd && $wd['zone'] === $zone && $wd['pos'] === $pos && $wd['order'] === $order) return $p;
        }
    }
    return null;
}

/* positional, honest comparison — this is what makes the lock solvable */
function smt_check_key($zone, $input, $order = 1) {
    $key = smt_zone_key($zone, $order);
    $n   = strlen($key);
    $in  = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $input));
    $in  = substr(str_pad($in, $n, '.'), 0, $n);
    $agree = array(); $ok = true;
    for ($i = 0; $i < $n; $i++) {
        $a = ($in[$i] === $key[$i]);
        $agree[] = $a;
        if (!$a) $ok = false;
    }
    return array('key' => $key, 'in' => $in, 'agree' => $agree, 'ok' => $ok, 'n' => $n);
}

/* ===========================================================================
 *  STATE — carried in cookies. walking accumulates.
 * ========================================================================= */

function smt_state() {
    static $st = null;
    if ($st !== null) return $st;
    $st = array('w' => array(), 'seen' => 0, 'depth' => 0, 'open' => array());
    $raw = isset($_COOKIE['smt']) ? (string) $_COOKIE['smt'] : '';
    if ($raw !== '' && strlen($raw) < 6000) {
        $p = explode('|', $raw);
        if (count($p) === 5 && $p[0] === '1' && $p[4] === smt_b36(smt_fnv($p[1] . $p[2] . $p[3]), 6)) {
            foreach (explode(';', $p[1]) as $t) {
                if (preg_match('/^([a-z]+)\.([12])\.(\d+)\.([a-z0-9])$/', $t, $m))
                    $st['w'][$m[1] . '.' . $m[2] . '.' . $m[3]] = $m[4];
            }
            $st['seen']  = min(999999, (int) $p[2]);
            $st['depth'] = min(999, (int) $p[3]);
        }
    }
    global $SMT_OPENABLE;
    foreach ($SMT_OPENABLE as $z)
        if (isset($_COOKIE['smt_w_' . $z]) && $_COOKIE['smt_w_' . $z] === '1') $st['open'][] = $z;
    return $st;
}

function smt_state_save($st) {
    $t = array();
    foreach ($st['w'] as $k => $v) $t[] = $k . '.' . $v;
    sort($t);
    $w = implode(';', $t);
    $s = (string) $st['seen'];
    $d = (string) $st['depth'];
    $raw = '1|' . $w . '|' . $s . '|' . $d . '|' . smt_b36(smt_fnv($w . $s . $d), 6);
    if (!headers_sent()) @setcookie('smt', $raw, time() + 31536000, '/');
    $_COOKIE['smt'] = $raw;
}

function smt_state_note_leaf($path, $depth) {
    $st = smt_state();
    $st['seen']++;
    if ($depth > $st['depth']) $st['depth'] = $depth;
    $found = null;
    $wd = smt_ward_at($path);
    if ($wd) {
        $k = $wd['zone'] . '.' . $wd['order'] . '.' . $wd['pos'];
        if (!isset($st['w'][$k])) $found = $wd;
        $st['w'][$k] = $wd['char'];
    }
    smt_state_save($st);
    return $found;
}

function smt_held($zone, $order = 1) {
    $st = smt_state();
    $n = $order === 2 ? SMT_WARDS2 : SMT_WARDS;
    $out = array_fill(0, $n, null);
    for ($i = 0; $i < $n; $i++) {
        $k = $zone . '.' . $order . '.' . $i;
        if (isset($st['w'][$k])) $out[$i] = $st['w'][$k];
    }
    return $out;
}
function smt_held_count($zone, $order = 1) {
    $c = 0; foreach (smt_held($zone, $order) as $v) if ($v !== null) $c++;
    return $c;
}
function smt_is_open($zone) { return in_array($zone, smt_state()['open'], true); }
function smt_open_zone($zone) {
    if (!headers_sent()) @setcookie('smt_w_' . $zone, '1', time() + 31536000, '/');
    $_COOKIE['smt_w_' . $zone] = '1';
}

/* ===========================================================================
 *  CIPHER — a real Vigenère. keyed at compline. it really decodes.
 * ========================================================================= */

function smt_vigenere($text, $key, $dir = 1) {
    $out = ''; $ki = 0; $kl = strlen($key);
    for ($i = 0, $n = strlen($text); $i < $n; $i++) {
        $c = $text[$i];
        if ($c >= 'a' && $c <= 'z') {
            $sh = ord($key[$ki % $kl]) - 97;
            $out .= chr(97 + ((ord($c) - 97 + $dir * $sh) + 260) % 26);
            $ki++;
        } else $out .= $c;
    }
    return $out;
}
/* the plaintext points at a leaf that genuinely holds the named ward */
function smt_cipher_plain() {
    $pos  = 2;
    $leaf = smt_find_ward_leaf('vault', $pos, 1);
    if ($leaf === null) $leaf = 'gate/marl';
    return 'the third ward of the vault is held under /' . $leaf . ' and nowhere else';
}
function smt_cipher_text() { return smt_vigenere(smt_cipher_plain(), 'compline', 1); }

/* ===========================================================================
 *  SURFACES — no house style. each block answers to itself.
 * ========================================================================= */

$SMT_FRAGMENTS = array(

'found' => <<<'F'
<div style="max-width:34em;font-family:'Iowan Old Style',Palatino,'Book Antiqua',Georgia,serif;font-size:1.06rem;line-height:2.05;color:#2a2620;text-align:justify;background:#efe9dc;padding:2.2em 2.4em;border:1px solid #d7cdb6;box-shadow:inset 0 0 40px rgba(120,100,60,.10);">
&hellip;and were told the room had been measured wrong, that the wall
[&nbsp;&nbsp;&nbsp;] closer each spring, that this was a settling and not
an approach. The clerk kept the older figure. When asked which was true he
said <em>both, for a while</em>, and returned the key to the board where a
second key already hung on the same hook &mdash; warm, as if just handled,
though the board is in a corridor no one uses after the &mdash;
</div>
F
,
'telemetry' => <<<'F'
<pre style="margin:0;padding:1.1em 1.3em;background:#0a0d0a;color:#5be07a;font:12px/1.55 'DejaVu Sans Mono',Menlo,Consolas,monospace;overflow:auto;border:1px solid #123018;">ch  ts(Z)                  A/mV     B/degC   C/hPa    flag
01  1997-11-03T02:59:59   00.037   +04.10   0993.2   .
01  1997-11-03T03:00:00   00.037   +04.09   0993.1   .
02  1997-11-03T03:00:00   -----    +04.09   0993.1   L
01  1997-11-03T03:11:00   12.884   +04.02   0992.7   *SPIKE
03  1997-11-03T03:11:01   NaN      NaN      NaN      X
01  1997-11-03T03:41:22   00.037   +03.98   0992.4   .
--  ----                  hold     hold     fall     carrier lost on 02</pre>
F
,
'hex' => <<<'F'
<pre style="margin:0;padding:1em 1.2em;background:#161512;color:#b8b09a;font:12px/1.5 'DejaVu Sans Mono',monospace;overflow:auto;">00000000  6e 6f 74 20 74 68 65  20  77 68 6f 6c 65 20 6f 66  |not the whole of|
00000010  20 69 74 20 69 73 20  6b  65 70 74 2e 20 74 68 65  | it is kept. the|
00000020  20 72 65 73 74 20 69  73  20 6d 65 61 73 75 72 65  | rest is measure|
00000030  64 2e 00 7f 91 22 e3  4a  10 00 00 00 00 00 00 01  |d...."J.........|
00000050  73 65 61 6c 20 68 6f  6c  64 73 2e 20 64 6f 20 6e  |seal holds. do n|
00000060  6f 74 20 66 6f 72 63  65  2e 0a 00 00 de ad 00 00  |ot force........|</pre>
F
,
'smt' => <<<'F'
<pre style="margin:0;padding:1.1em 1.3em;background:#1d1f26;color:#cfd3dc;font:12.5px/1.55 'DejaVu Sans Mono',monospace;overflow:auto;border-left:3px solid #4b5568;">; obligation carried over from a proof no one signed
(set-logic QF_UFLIA)
(declare-fun door () Bool) (declare-fun key () Bool)
(declare-fun mass () Int)  (declare-fun listed () Int)
(assert (=> key door))            <span style="color:#7f8896;">; the key implies the door</span>
(assert (not door))               <span style="color:#7f8896;">; the door refuses</span>
(assert (= mass (+ listed 1)))    <span style="color:#7f8896;">; one heavier than the manifest</span>
(check-sat)
<span style="color:#e0b062;">unsat</span>
(get-unsat-core) (<span style="color:#e0b062;">door key</span>)   <span style="color:#7f8896;">; the contradiction is the key and the door</span></pre>
F
,
'bom' => <<<'F'
<table style="border-collapse:collapse;font:12px/1.4 'Helvetica Neue',Arial,sans-serif;color:#1a1a1a;background:#fff;border:1px solid #999;">
<caption style="text-align:left;font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#777;padding:.4em 0;">assy —— rev H —— do not populate marked DNP</caption>
<tr style="background:#eee;"><th style="border:1px solid #bbb;padding:2px 8px;">Ref</th><th style="border:1px solid #bbb;padding:2px 8px;">Value</th><th style="border:1px solid #bbb;padding:2px 8px;">Pkg</th><th style="border:1px solid #bbb;padding:2px 8px;">MPN</th><th style="border:1px solid #bbb;padding:2px 8px;">Note</th></tr>
<tr><td style="border:1px solid #ddd;padding:2px 8px;">C7</td><td style="border:1px solid #ddd;padding:2px 8px;">100n</td><td style="border:1px solid #ddd;padding:2px 8px;">0402</td><td style="border:1px solid #ddd;padding:2px 8px;">GRM155R</td><td style="border:1px solid #ddd;padding:2px 8px;"></td></tr>
<tr><td style="border:1px solid #ddd;padding:2px 8px;">R12</td><td style="border:1px solid #ddd;padding:2px 8px;">0R</td><td style="border:1px solid #ddd;padding:2px 8px;">0603</td><td style="border:1px solid #ddd;padding:2px 8px;">&mdash;</td><td style="border:1px solid #ddd;padding:2px 8px;">hand-select</td></tr>
<tr><td style="border:1px solid #ddd;padding:2px 8px;">U3</td><td style="border:1px solid #ddd;padding:2px 8px;">?</td><td style="border:1px solid #ddd;padding:2px 8px;">QFN-32</td><td style="border:1px solid #ddd;padding:2px 8px;">unmarked</td><td style="border:1px solid #ddd;padding:2px 8px;">field return</td></tr>
<tr><td style="border:1px solid #ddd;padding:2px 8px;">Q1</td><td style="border:1px solid #ddd;padding:2px 8px;">&mdash;</td><td style="border:1px solid #ddd;padding:2px 8px;">SOT-23</td><td style="border:1px solid #ddd;padding:2px 8px;">GONE</td><td style="border:1px solid #ddd;padding:2px 8px;">was fitted at test</td></tr>
</table>
F
,
'physics' => <<<'F'
<div style="font-family:Charter,Georgia,'Times New Roman',serif;color:#111;background:#fbfbf7;padding:1.4em 1.6em;max-width:33em;border-top:2px solid #111;border-bottom:1px solid #111;">
<div style="font-variant:small-caps;letter-spacing:.06em;font-size:.82rem;color:#555;">strangeness &minus;1 &middot; observed / inferred</div>
<table style="border-collapse:collapse;margin:.7em 0;font-size:.92rem;width:100%;">
<tr><td>&Lambda;&nbsp;&rarr;&nbsp;p&nbsp;&pi;<sup>&minus;</sup></td><td style="text-align:right;">63.9 %</td><td style="text-align:right;color:#666;">&tau; 2.6&times;10<sup>&minus;10</sup> s</td></tr>
<tr><td>&Lambda;&nbsp;&rarr;&nbsp;n&nbsp;&pi;<sup>0</sup></td><td style="text-align:right;">35.8 %</td><td style="text-align:right;color:#666;">|uds&rang;</td></tr>
<tr><td>&Lambda;&nbsp;&rarr;&nbsp;&mdash;</td><td style="text-align:right;">00.3 %</td><td style="text-align:right;color:#666;">mode not shown</td></tr>
</table>
<pre style="margin:.3em 0 0;font-size:.8rem;color:#333;line-height:1.3;">   p            the track ends and is
    \           logged as ending; the
     *---------  chamber keeps a fourth
    /            prong nobody claims
  pi-</pre>
</div>
F
,
'guestbook' => <<<'F'
<div style="font-family:Verdana,Geneva,sans-serif;font-size:12px;color:#334;background:#e8eef3;border:2px groove #9fb2c4;padding:.6em .9em;max-width:34em;">
<div style="border-bottom:1px dashed #9fb2c4;padding:.25em 0;"><b>hollow_key</b> &middot; 1998-02-14 &middot; still warm.</div>
<div style="border-bottom:1px dashed #9fb2c4;padding:.25em 0;"><b>(unsigned)</b> &middot; 2001-09-30 &middot; </div>
<div style="border-bottom:1px dashed #9fb2c4;padding:.25em 0;"><b>marl</b> &middot; 2004-06-01 &middot; die Zahl stimmt nicht.</div>
<div style="border-bottom:1px dashed #9fb2c4;padding:.25em 0;"><b>surveyor</b> &middot; 2011-03-19 &middot; measured it again. same. wrong.</div>
<div style="border-bottom:1px dashed #9fb2c4;padding:.25em 0;"><b>&mdash;</b> &middot; 2029-12-31 &middot; ante diem, adhuc.</div>
<div style="padding:.25em 0;color:#889;">entries after this point were not kept.</div>
</div>
F
,
'classifieds' => <<<'F'
<div style="column-count:2;column-gap:1.6em;font-family:Georgia,'Times New Roman',serif;font-size:11.5px;line-height:1.45;color:#1a1712;background:#f3efe4;padding:1.1em 1.3em;max-width:36em;border:1px solid #cbc1a8;">
<div style="break-inside:avoid;margin-bottom:.55em;">OFFERED &mdash; one door, complete with frame, never hung. Heavier than listed. Box 41-N.</div>
<div style="break-inside:avoid;margin-bottom:.55em;">FOUND &mdash; key, brass, warm to the touch. Fits nothing on this floor. Ask at terce.</div>
<div style="break-inside:avoid;margin-bottom:.55em;">WANTED &mdash; the second witness. Need not agree. Bearing 041&deg;, initials M.H.</div>
<div style="break-inside:avoid;margin-bottom:.55em;">NOTICE &mdash; the north stair is not sealed. Do not use the north stair.</div>
<div style="break-inside:avoid;margin-bottom:.55em;">LOST &mdash; a page, torn cleanly. The tear is held separately. Tel. 0&mdash;&mdash; 4&mdash;1&mdash;</div>
</div>
F
,
'changelog' => <<<'F'
<pre style="margin:0;padding:1.1em 1.3em;background:#111;color:#c8c8c8;font:12px/1.6 'DejaVu Sans Mono',monospace;overflow:auto;">v9.0.0 — 2029-11-02
  - sealed &#9608;&#9608;&#9608;&#9608;&#9608;; reopened; sealed
  - cannot reproduce the fourth tide (deferred)
v8.4.1 — 2011-03-19
  - survey of north stair: pending (see #0041)
  - #0041 closed as duplicate of #0041
v3.0.0 — 2001-09-30
  - removed the heading. entries retained under no heading.
v1.0.0 — 1997-08-20
  - it was here.</pre>
F
,
'catalog' => <<<'F'
<div style="font-family:'Hoefler Text',Baskerville,Georgia,serif;font-size:13px;line-height:1.55;color:#20242a;background:#fff;padding:1.2em 1.5em;max-width:35em;border:1px solid #ccc;">
<div style="font-variant:small-caps;letter-spacing:.05em;color:#777;font-size:11px;margin-bottom:.7em;">accessions — partial — grid ref withheld</div>
<div style="margin:0 0 .6em;text-indent:-1.4em;padding-left:1.4em;"><b>1997.041</b> — nail, iron, hand-forged; 41 mm; from the north face; heavier dry than wet.</div>
<div style="margin:0 0 .6em;text-indent:-1.4em;padding-left:1.4em;"><b>1997.088</b> — plate, numbered <i>12 of 9</i>; provenance: the clerk; condition: complete, impossible.</div>
<div style="margin:0 0 .6em;text-indent:-1.4em;padding-left:1.4em;"><b>2004.006</b> — key, brass; warm; fits the door in 2029.xxx; do not attempt.</div>
<div style="margin:0 0 .6em;text-indent:-1.4em;padding-left:1.4em;color:#8a3b3b;"><b>2029.???</b> — <s>withdrawn</s>; the accession card remains; see card.</div>
</div>
F
,
'tide' => <<<'F'
<pre style="margin:0;padding:1.1em 1.3em;background:#0d1a24;color:#9ec8e0;font:12px/1.6 'DejaVu Sans Mono',monospace;overflow:auto;border:1px solid #1e3648;">TIDE TABLE — station withheld — heights in m above datum
date        HW        m     LW        m
1997-11-03  03:41   +4.10   09:58   +0.37
1997-11-03  16:12   +4.02   22:19   +0.41
1997-11-03  <span style="color:#e0b062">27:—</span>   <span style="color:#e0b062">+4.—</span>   <span style="color:#e0b062">——:——</span>   <span style="color:#e0b062">————</span>   <span style="color:#7f96a8">(fourth tide; not predicted)</span>
1997-11-04  04:29   +4.08   10:46   +0.35
datum uncertain; the fourth tide arrives at the north stair and leaves it dry</pre>
F
,
'apache_error' => <<<'F'
<pre style="margin:0;padding:1em 1.2em;background:#0b0b0b;color:#b0b0a8;font:11.5px/1.55 'DejaVu Sans Mono',monospace;overflow:auto;">[Mon Nov 03 03:11:00 1997] [notice] carrier present on ch/02 (disconnected)
[Mon Nov 03 03:11:01 1997] [error] [client 41.0.0.0] attempt to force seal: /vault/ — denied, mass unchanged
[Mon Nov 03 03:11:01 1997] [error] File does not exist: /var/www/the-fourth-tide
[Mon Nov 03 03:41:22 1997] [warn] child 0 returned to a pool it did not leave
[Mon Nov 03 04:00:00 1997] [error] [client -] request for /~operator: no such operator; the door answered
[Mon Nov 03 04:00:01 1997] [alert] the log is longer than the day</pre>
F
,
'exif' => <<<'F'
<div style="font-family:'DejaVu Sans Mono',monospace;font-size:11.5px;line-height:1.5;color:#3a3a3a;background:#f0efe9;padding:1.1em 1.3em;max-width:34em;border:1px solid #cfcbbf;">
<div style="color:#8a8578;">exiftool — plate.tif — 1 image</div>
Model&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: plate-camera, unmarked<br>
Create Date&nbsp;&nbsp;: 1997:08:20 00:00:00<br>
Modify Date&nbsp;&nbsp;: 1997:08:20 00:00:00 (before creation on some readers)<br>
Image Description : north stair, sealed<br>
GPS Position&nbsp;: 47 18 43 N, 7 01 16 E &mdash; resolves to a wall<br>
Orientation&nbsp;&nbsp;: inward<br>
Exposure&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: 41 s, no light recorded<br>
Software&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: the clerk<br>
Warning&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: [minor] the description is longer than the image
</div>
F
,
'ledger_dbe' => <<<'F'
<table style="border-collapse:collapse;font-family:Georgia,serif;font-size:12.5px;color:#241f16;background:#f6f1e4;border:1px solid #b8ac8e;">
<caption style="text-align:left;padding:.4em 0;color:#7a705a;font-style:italic;">ledger 4 (cont.) — a hand that is not the clerk's</caption>
<tr style="border-bottom:1px solid #b8ac8e;"><th style="text-align:left;padding:2px 14px 2px 4px;">particular</th><th style="text-align:right;padding:2px 14px;">debit</th><th style="text-align:right;padding:2px 4px;">credit</th></tr>
<tr><td style="padding:2px 14px 2px 4px;">tare, on arrival</td><td style="text-align:right;padding:2px 14px;">41</td><td style="text-align:right;padding:2px 4px;">—</td></tr>
<tr><td style="padding:2px 14px 2px 4px;">demurrage, north stair</td><td style="text-align:right;padding:2px 14px;">—</td><td style="text-align:right;padding:2px 4px;">37</td></tr>
<tr><td style="padding:2px 14px 2px 4px;">held, in the hand</td><td style="text-align:right;padding:2px 14px;">2</td><td style="text-align:right;padding:2px 4px;">—</td></tr>
<tr style="border-top:1px solid #241f16;font-weight:bold;"><td style="padding:2px 14px 2px 4px;">balance</td><td style="text-align:right;padding:2px 14px;">43</td><td style="text-align:right;padding:2px 4px;">43&nbsp;&minus;&nbsp;<span style="color:#8a3b2b;">a hand</span></td></tr>
</table>
F
,
'weather' => <<<'F'
<div style="font-family:Charter,Georgia,serif;font-size:12.5px;line-height:1.6;color:#2a2a2a;background:#eef1f3;padding:1.2em 1.5em;max-width:33em;border-top:3px double #6a7a86;">
<div style="font-variant:small-caps;letter-spacing:.05em;color:#5a6a76;">observations — the barograph drew all night</div>
<div style="margin-top:.5em;">2159&nbsp;&mdash;&nbsp;falling. 993.2 hPa. no cloud noted.</div>
<div>0011&nbsp;&mdash;&nbsp;falling. 992.7 hPa. wind: none, from the northeast.</div>
<div>0311&nbsp;&mdash;&nbsp;993.—; pen lifted; returned to the same figure.</div>
<div>0600&nbsp;&mdash;&nbsp;falling. 992.2 hPa. and no weather came.</div>
<div style="margin-top:.5em;color:#6a7a86;">the trace is flat and the glass fell. both were recorded.</div>
</div>
F
,
'marginalia' => <<<'F'
<div style="font-family:'Segoe Script','Bradley Hand',cursive,serif;font-size:14px;line-height:1.7;color:#3a3226;background:#efe7d0;padding:1.6em 1.8em;max-width:30em;border:1px solid #d3c7a6;transform:rotate(-.6deg);box-shadow:2px 3px 10px rgba(80,60,20,.14);">
<div style="transform:rotate(.4deg);">again, and slower.</div>
<div style="margin-left:2.4em;transform:rotate(-.5deg);">&mdash; the figure is the clerk's, the doubt is not</div>
<div style="margin-top:.6em;transform:rotate(.3deg);">do not open before the frost leaves.</div>
<div style="margin-top:.6em;margin-left:1.2em;color:#6a3b2b;transform:rotate(-.2deg);">(it left. the crate is heavier.)</div>
<div style="margin-top:.8em;text-align:right;transform:rotate(.6deg);color:#7a6a4a;">see leaf 12 of 9</div>
</div>
F
,
);

/* narrow surfaces for the hand-held apparatus. wide <pre> does not travel. */
$SMT_FRAG_M = array(
'm_found' => '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.85;color:#2a2620;background:#efe9dc;padding:1.3em 1.2em;">&hellip;the room had been measured wrong, the wall [&nbsp;&nbsp;] closer each spring. The clerk kept the older figure. Asked which was true he said <em>both, for a while</em>, and returned the key to a board where a second key already hung, warm.</div>',
'm_tele'  => '<pre style="margin:0;padding:1em .9em;background:#0a0d0a;color:#5be07a;font:11px/1.6 monospace;overflow-x:auto;">03:00:00  00.037  +04.09  .
03:00:00  -----   +04.09  L
03:11:00  12.884  +04.02  *SPIKE
03:11:01  NaN     NaN     X
03:41:22  00.037  +03.98  .
carrier lost on 02</pre>',
'm_tide'  => '<pre style="margin:0;padding:1em .9em;background:#0d1a24;color:#9ec8e0;font:11px/1.7 monospace;overflow-x:auto;">HW 03:41  +4.10
LW 09:58  +0.37
HW 16:12  +4.02
<span style="color:#e0b062">-- 27:--  +4.--  (fourth)</span>
arrives at the north stair
and leaves it dry</pre>',
'm_cat'   => '<div style="font-family:Georgia,serif;font-size:14px;line-height:1.6;color:#20242a;background:#fff;padding:1.1em 1.2em;"><div style="font-variant:small-caps;color:#777;font-size:11px;">accessions — partial</div><div style="margin-top:.6em;"><b>1997.041</b> nail, iron; 41 mm; heavier dry than wet.</div><div style="margin-top:.5em;"><b>1997.088</b> plate, numbered <i>12 of 9</i>; complete, impossible.</div><div style="margin-top:.5em;color:#8a3b3b;"><b>2029.???</b> <s>withdrawn</s>; the card remains.</div></div>',
'm_marg'  => '<div style="font-family:cursive,serif;font-size:16px;line-height:1.8;color:#3a3226;background:#efe7d0;padding:1.3em 1.2em;">again, and slower.<div style="margin-left:1.4em;font-size:14px;">&mdash; the figure is the clerk\'s, the doubt is not</div><div style="margin-top:.5em;">do not open before the frost leaves.</div><div style="margin-top:.4em;color:#6a3b2b;">(it left. the crate is heavier.)</div></div>',
'm_ledg'  => '<div style="font-family:Georgia,serif;font-size:14px;color:#241f16;background:#f6f1e4;padding:1.1em 1.2em;"><div style="font-style:italic;color:#7a705a;">ledger 4 (cont.)</div><div style="margin-top:.5em;display:flex;justify-content:space-between;"><span>tare, on arrival</span><span>41</span></div><div style="display:flex;justify-content:space-between;"><span>demurrage</span><span>&minus;37</span></div><div style="display:flex;justify-content:space-between;"><span>held, in the hand</span><span>2</span></div><div style="display:flex;justify-content:space-between;border-top:1px solid #241f16;margin-top:.3em;padding-top:.3em;font-weight:bold;"><span>balance</span><span>43 &minus; <span style="color:#8a3b2b">a hand</span></span></div></div>',
'm_chg'   => '<pre style="margin:0;padding:1em .9em;background:#111;color:#c8c8c8;font:11px/1.7 monospace;">v9.0.0 2029-11-02
 - sealed &#9608;&#9608;&#9608;; reopened; sealed
 - cannot reproduce the fourth
   tide (deferred)
v1.0.0 1997-08-20
 - it was here.</pre>',
'm_wthr'  => '<div style="font-family:Georgia,serif;font-size:14px;line-height:1.7;color:#2a2a2a;background:#eef1f3;padding:1.1em 1.2em;"><div style="font-variant:small-caps;color:#5a6a76;">the barograph drew all night</div>2159 &mdash; falling. 993.2 hPa.<br>0311 &mdash; 993.&mdash;; pen lifted; same figure.<br>0600 &mdash; falling. 992.2 hPa.<div style="margin-top:.4em;color:#6a7a86;">the trace is flat and the glass fell.</div></div>',
);

/* ===========================================================================
 *  REQUEST
 * ========================================================================= */

function smt_path() {
    $uri = $_SERVER['REDIRECT_URL'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
    $p = parse_url($uri, PHP_URL_PATH);
    if ($p === false || $p === null) $p = '/';
    $p = rawurldecode($p);
    $p = preg_replace('#^/index\.php#', '', $p);
    $p = preg_replace('#//+#', '/', $p);
    return trim($p, '/');
}
function smt_errcode() {
    if (isset($_GET['__err'])) return (int) $_GET['__err'];
    if (isset($_SERVER['REDIRECT_STATUS']) && (int) $_SERVER['REDIRECT_STATUS'] >= 400)
        return (int) $_SERVER['REDIRECT_STATUS'];
    return 0;
}
/* the hand-held apparatus is a different apparatus */
function smt_is_mobile() {
    if (isset($_GET['m'])) {
        $v = $_GET['m'] === '1' ? '1' : '0';
        if (!headers_sent()) @setcookie('smt_m', $v, time() + 31536000, '/');
        return $v === '1';
    }
    if (isset($_COOKIE['smt_m'])) return $_COOKIE['smt_m'] === '1';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool) preg_match('/Android|iPhone|iPod|iPad|Mobile|Silk|Opera Mini|IEMobile|BlackBerry|webOS/i', $ua);
}

function smt_child_slug($path, $i) {
    global $SMT_SLUGWORDS;
    $seed = smt_fnv($path . "\x1f" . $i);
    $rng  = smt_rng($seed);
    $w1   = $SMT_SLUGWORDS[$seed % count($SMT_SLUGWORDS)];
    $mode = smt_int($rng, 0, 4);
    if ($mode === 0) return $w1 . '-' . smt_b36($seed, 3);
    if ($mode === 1) return $w1;
    if ($mode === 2) return $w1 . '-' . smt_pick($rng, $SMT_SLUGWORDS);
    if ($mode === 3) return smt_b36($seed >> 3, 4);
    return $w1 . str_pad((string) smt_int($rng, 2, 97), 2, '0', STR_PAD_LEFT);
}

function smt_send_headers($code, $ctype = 'text/html; charset=utf-8', $extra = array()) {
    http_response_code($code);
    header('Content-Type: ' . $ctype);
    header('X-Seal: closed; do not force');
    header('Referrer-Policy: no-referrer');
    foreach ($extra as $k => $v) header($k . ': ' . $v);
}

/* ===========================================================================
 *  THE APPARATUS — the one thing that persists across every surface
 * ========================================================================= */

/* small, honest readout: what is held, what is answered, what is not */
function smt_apparatus_html($mobile = false) {
    global $SMT_OPENABLE;
    $st = smt_state();
    $rows = '';
    foreach ($SMT_OPENABLE as $z) {
        $held = smt_held($z, 1);
        $n = 0; $cells = '';
        foreach ($held as $i => $c) {
            if ($c !== null) { $n++; $cells .= '<b style="color:#d8c48a">' . smt_h($c) . '</b>'; }
            else $cells .= '<span style="color:#4a4438">·</span>';
        }
        $open = smt_is_open($z);
        $rows .= '<div style="display:flex;justify-content:space-between;gap:.8em;padding:' . ($mobile ? '.55em 0' : '.2em 0') . ';border-bottom:1px solid #241f18">'
              . '<span style="letter-spacing:.06em">' . smt_h($z) . '</span>'
              . '<span style="letter-spacing:.3em;font-family:monospace">' . $cells . '</span>'
              . '<span style="color:' . ($open ? '#6a9a5a' : ($n === SMT_WARDS ? '#c8a44a' : '#5a5346')) . ';font-size:' . ($mobile ? '13px' : '11px') . '">'
              . ($open ? 'answered' : $n . '/' . SMT_WARDS) . '</span></div>';
    }
    $fs = $mobile ? '15px' : '11.5px';
    return '<div style="font:' . $fs . '/1.7 \'DejaVu Sans Mono\',monospace;color:#8a8272;background:#100e0c;border:1px solid #2a241c;padding:' . ($mobile ? '1em' : '.9em 1.1em') . '">'
         . '<div style="color:#5a5346;letter-spacing:.2em;font-size:' . ($mobile ? '11px' : '10px') . ';text-transform:uppercase;margin-bottom:.5em">wards recovered &middot; ' . (int) $st['seen'] . ' leaves walked &middot; depth ' . (int) $st['depth'] . '</div>'
         . $rows
         . '<div style="margin-top:.7em;font-size:' . ($mobile ? '13px' : '11px') . ';color:#5a5346">a ward is recorded where it lies. walking is the only method.</div></div>';
}

/* the seal: a real form, real positional feedback */
function smt_seal_form_html($zone, $order = 1, $feedback = null, $mobile = false) {
    $n = $order === 2 ? SMT_WARDS2 : SMT_WARDS;
    $held = smt_held($zone, $order);
    $pre = '';
    foreach ($held as $c) $pre .= ($c === null ? '' : $c);
    $big = $mobile ? 'font-size:20px;padding:.7em;letter-spacing:.5em' : 'font-size:15px;padding:.5em;letter-spacing:.4em';
    $h = '<form method="post" action="/seal" style="margin:1.2em 0">'
       . '<input type="hidden" name="z" value="' . smt_h($zone) . '">'
       . '<input type="hidden" name="o" value="' . (int) $order . '">'
       . '<div style="font:' . ($mobile ? '13px' : '11px') . '/1.6 monospace;color:#7a7264;margin-bottom:.5em">'
       . $n . ' wards. order is fixed. positions you have not recovered may be guessed.</div>'
       . '<input name="k" maxlength="' . $n . '" autocomplete="off" autocapitalize="off" spellcheck="false" '
       . 'value="' . smt_h(isset($_POST['k']) ? substr((string) $_POST['k'], 0, $n) : $pre) . '" '
       . 'style="' . $big . ';width:' . ($mobile ? '100%' : '11em') . ';box-sizing:border-box;font-family:monospace;background:#0a0806;color:#e0c88a;border:1px solid #3a3020;text-align:center">'
       . '<button style="' . ($mobile ? 'display:block;width:100%;margin-top:.8em;font-size:18px;padding:.8em' : 'margin-left:.6em;font-size:14px;padding:.55em 1.4em')
       . ';font-family:monospace;background:#1a1610;color:#c8b48a;border:1px solid #3a3020;cursor:pointer">present</button>';
    if ($feedback !== null) {
        $cells = '';
        foreach ($feedback['agree'] as $i => $a) {
            $ch = $feedback['in'][$i];
            $cells .= '<span style="display:inline-block;width:' . ($mobile ? '2em' : '1.6em') . ';text-align:center;color:'
                    . ($a ? '#7ac86a' : '#b05a4a') . '">' . smt_h($ch) . '</span>';
        }
        $k = 0; foreach ($feedback['agree'] as $a) if ($a) $k++;
        $h .= '<div style="margin-top:1em;font:' . ($mobile ? '15px' : '12.5px') . '/1.8 monospace;color:#8a8272">'
           . '<div style="letter-spacing:.3em;font-size:' . ($mobile ? '20px' : '16px') . '">' . $cells . '</div>'
           . $k . ' of ' . $feedback['n'] . ' positions agree. the seal does not lie about this.</div>';
    }
    return $h . '</form>';
}

/* the console. commands are few and they are real. */
function smt_console_html($ctx, $mobile = false) {
    $j = htmlspecialchars(json_encode($ctx), ENT_QUOTES, 'UTF-8');
    $fs = $mobile ? '15px' : '12.5px';
    return '<div style="font:' . $fs . '/1.65 \'DejaVu Sans Mono\',monospace;color:#7a9a8a;background:#070a08;border:1px solid #16241c;padding:' . ($mobile ? '.9em' : '.8em 1em') . '">'
        . '<div id="cout" style="white-space:pre-wrap;min-height:' . ($mobile ? '7em' : '5.5em') . ';color:#5a7a6a">the following are understood on this floor:
count · weigh · listen · wards · bearing &lt;n&gt; · open &lt;key&gt;</div>'
        . '<div style="display:flex;gap:.5em;margin-top:.6em;align-items:center"><span style="color:#3a5a4a">&gt;</span>'
        . '<input id="cin" autocomplete="off" autocapitalize="off" spellcheck="false" style="flex:1;min-width:0;background:transparent;border:none;border-bottom:1px solid #16241c;color:#8ac8a8;font-family:monospace;font-size:' . $fs . ';padding:.4em 0;outline:none"></div>'
        . '<script>(function(){var C=' . $j . ',o=document.getElementById("cout"),i=document.getElementById("cin");'
        . 'function p(t){o.textContent=t;}'
        . 'i.addEventListener("keydown",function(e){if(e.key!=="Enter")return;var v=i.value.trim().toLowerCase();i.value="";'
        . 'var a=v.split(/\s+/),c=a[0],x=a.slice(1).join("");'
        . 'if(c==="count"){p(C.seen+" leaves walked. "+C.wards+" wards recorded. depth "+C.depth+".");}'
        . 'else if(c==="weigh"){p("mass on this floor: "+C.mass+" octets. the figure does not settle.");}'
        . 'else if(c==="listen"){p(C.listen);}'
        . 'else if(c==="wards"){p(C.wardline);}'
        . 'else if(c==="bearing"){var n=parseInt(x,10);if(isNaN(n)){p("a bearing is a number.");}else{location.href="/gate/"+C.slugs[Math.abs(n)%C.slugs.length];}}'
        . 'else if(c==="open"){if(!x){p("present a ward-set.");}else{var f=document.createElement("form");f.method="post";f.action="/seal";'
        . 'f.innerHTML=\'<input name="z" value="\'+C.zone+\'"><input name="o" value="1"><input name="k">\';f.k.value=x;document.body.appendChild(f);f.submit();}}'
        . 'else if(c==="help"){p("count · weigh · listen · wards · bearing <n> · open <key>");}'
        . 'else if(c===""){}'
        . 'else{p("not on this floor.");}});})();</script></div>';
}

function smt_console_ctx($path, $zone = 'vault') {
    global $SMT_POOL_LINES, $SMT_SLUGWORDS, $SMT_OPENABLE;
    $st = smt_state();
    $seed = smt_fnv('ctx::' . $path);
    $wl = array();
    foreach ($SMT_OPENABLE as $z) {
        $h = smt_held($z, 1); $s = '';
        foreach ($h as $c) $s .= ($c === null ? '·' : $c);
        $wl[] = $z . ' ' . $s;
    }
    return array(
        'seen'  => (int) $st['seen'],
        'depth' => (int) $st['depth'],
        'wards' => count($st['w']),
        'mass'  => number_format(smt_int(smt_rng($seed), 12000, 94000000)),
        'listen' => smt_pick(smt_rng($seed ^ 0x77), $SMT_POOL_LINES) . '.',
        'wardline' => implode("\n", $wl),
        'zone'  => $zone,
        'slugs' => array_slice($SMT_SLUGWORDS, 0, 48),
    );
}

/* redactions that really do come off */
function smt_reveal_js() {
    return '<script>document.addEventListener("click",function(e){var t=e.target;'
         . 'if(t&&t.dataset&&t.dataset.r){t.textContent=t.dataset.r;t.style.color="#c8a44a";t.style.background="none";delete t.dataset.r;}});</script>';
}
function smt_redact($text) {
    return '<span data-r="' . smt_h($text) . '" style="background:#2a241c;color:#2a241c;cursor:pointer;padding:0 .15em;user-select:none">'
         . str_repeat('&#9608;', max(3, min(18, (int) (mb_strlen($text) * 0.8)))) . '</span>';
}

/* ===========================================================================
 *  SKINS
 * ========================================================================= */

function smt_skins() {
    return array(
        "html{background:#d8cfb8}body{margin:0;font-family:'Iowan Old Style',Palatino,Georgia,serif;color:#2b2519;background:#efe7d3;max-width:44em;margin:0 auto;padding:3.2em 2.6em}"
        . ".desig{font-size:.72rem;letter-spacing:.28em;text-transform:uppercase;color:#8a7a54}h1{font-weight:400;font-size:1.7rem;margin:.2em 0 1.2em;border-bottom:1px solid #c3b591;padding-bottom:.3em}"
        . ".line{margin:.5em 0;line-height:1.7}.kids{margin-top:2.4em;border-top:1px solid #c3b591;padding-top:1em}"
        . "a{color:#5a4a24;text-decoration:none;border-bottom:1px dotted #9a865a}a:hover{background:#e5d9ba}.seal{color:#8a3b2b}.mark{font-family:monospace;color:#9a865a;font-size:.75rem}",

        "html{background:#04060a}body{margin:0;font-family:'DejaVu Sans Mono',Menlo,monospace;color:#4be08a;background:#04060a;max-width:52em;margin:0 auto;padding:2.6em 2em;font-size:13px;line-height:1.6}"
        . ".desig{color:#2b7a4a}h1{font-weight:400;font-size:1.15rem;color:#8affb0}.line{margin:.15em 0;white-space:pre-wrap}.kids{margin-top:2em}"
        . "a{color:#e0b062;text-decoration:none}a:hover{color:#fff;background:#123018}.seal{color:#e0554a}.mark{color:#2b7a4a}",

        "html{background:#0d2b52}body{margin:0;font-family:'Helvetica Neue',Arial,sans-serif;color:#cfe0ff;background:#123a66;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:22px 22px;max-width:46em;margin:0 auto;padding:3em 2.4em}"
        . ".desig{font-size:.7rem;letter-spacing:.3em;color:#7fa8d8}h1{font-weight:300;font-size:1.5rem;color:#fff}.line{margin:.5em 0;line-height:1.6}"
        . ".kids{margin-top:2.4em;border-top:1px solid #35618f;padding-top:1em}a{color:#bfe0ff;text-decoration:none;border-bottom:1px solid #35618f}.seal{color:#ff9a7a}.mark{font-family:monospace;color:#7fa8d8;font-size:.75rem}",

        "html{background:#c9c4b6}body{margin:0;font-family:Georgia,'Times New Roman',serif;color:#181510;background:#f4f1e6;max-width:40em;margin:0 auto;padding:3em 2.6em}"
        . ".desig{font-variant:small-caps;letter-spacing:.08em;color:#6a6252;font-size:.8rem}h1{font-weight:700;font-size:1.9rem;margin:.1em 0 .6em}"
        . ".line{margin:.45em 0;line-height:1.5;text-align:justify}.kids{margin-top:2em;border-top:2px solid #181510;padding-top:.8em}"
        . "a{color:#111;text-decoration:none;border-bottom:1px solid #999}.seal{color:#7a2b1b}.mark{font-family:monospace;color:#8a8272;font-size:.72rem}",

        "html{background:#eef0f2}body{margin:0;font-family:'Helvetica Neue',Arial,sans-serif;color:#20242a;background:#fff;max-width:42em;margin:0 auto;padding:3.4em 3em;border-left:6px solid #d0d4d8}"
        . ".desig{font-size:.68rem;letter-spacing:.22em;text-transform:uppercase;color:#9aa0a6}h1{font-weight:300;font-size:1.6rem;color:#111}"
        . ".line{margin:.5em 0;line-height:1.65;color:#3a3f45}.kids{margin-top:2.6em;border-top:1px solid #e2e5e8;padding-top:1em}"
        . "a{color:#356;text-decoration:none;border-bottom:1px solid #cdd3d8}.seal{color:#a3402f}.mark{font-family:monospace;color:#aab;font-size:.72rem}",

        "html{background:#0a0700}body{margin:0;font-family:'DejaVu Sans Mono',monospace;color:#e0a94a;background:#0a0700;max-width:50em;margin:0 auto;padding:2.8em 2.2em;font-size:13px;line-height:1.7}"
        . ".desig{color:#7a5a1a}h1{font-weight:400;font-size:1.2rem;color:#ffd88a}.line{margin:.2em 0;white-space:pre-wrap}.kids{margin-top:2.2em}"
        . "a{color:#8fd0ff;text-decoration:none}a:hover{background:#241a06}.seal{color:#e0554a}.mark{color:#7a5a1a}",

        "html{background:#0c0c0e}body{margin:0;font-family:Charter,Georgia,serif;color:#c8c2b6;background:#0c0c0e;max-width:33em;margin:0 auto;padding:5em 2em;font-size:1.08rem;line-height:2.1}"
        . ".desig{letter-spacing:.3em;text-transform:uppercase;color:#55503f;font-size:.7rem}h1{font-weight:400;font-size:1.5rem;color:#e8e2d4}"
        . ".line{margin:.7em 0}.kids{margin-top:3em}a{color:#b09a6a;text-decoration:none;border-bottom:1px solid #322}.seal{color:#9a4a3a}.mark{font-family:monospace;color:#55503f;font-size:.72rem}",

        "html{background:#b9c6b0}body{margin:0;font-family:'Courier New',Courier,monospace;color:#233;background:#f7faf3;max-width:38em;margin:0 auto;padding:2.8em 2.4em;background-image:repeating-linear-gradient(#f7faf3 0 27px,#dfe8d8 27px 28px);line-height:28px}"
        . ".desig{color:#6a8a6a;font-size:.78rem}h1{font-weight:700;font-size:1.3rem;color:#2a4a2a;line-height:28px}.line{margin:0}"
        . ".kids{margin-top:28px;border-top:1px solid #b9c6b0;padding-top:2px}a{color:#2a5a2a;text-decoration:none;border-bottom:1px dotted #6a8a6a}.seal{color:#8a3b2b}.mark{color:#6a8a6a;font-size:.75rem}",
    );
}

function smt_doc($skinIndex, $title, $bodyHtml, $comment = '', $mobile = false) {
    $skins = smt_skins();
    $css = $skins[$skinIndex % count($skins)];
    if ($mobile) {
        // the hand-held apparatus overrides the width and the touch targets
        $css .= "body{max-width:none;padding:1.4em 1.1em 6em;font-size:16px}h1{font-size:1.45rem;line-height:1.3}"
             . ".line{line-height:1.75}.kids a{display:block;padding:.85em 0;border-bottom:1px solid rgba(128,128,128,.25)}"
             . "pre{overflow-x:auto;-webkit-overflow-scrolling:touch}table{max-width:100%}";
    }
    echo "<!doctype html>\n<html lang=\"und\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<meta name=\"robots\" content=\"noindex,nofollow,noarchive\">";
    echo "<title>" . smt_h($title) . "</title>";
    if ($comment !== '') echo "\n<!-- " . $comment . " -->\n";
    echo "<style>" . $css . "</style></head><body>";
    echo $bodyHtml;
    echo smt_reveal_js();
    echo "</body></html>";
}

/* ===========================================================================
 *  THE LABYRINTH
 * ========================================================================= */

function smt_render_node($path, $seg, $mobile) {
    global $SMT_POOL_LINES, $SMT_POOL_LABELS, $SMT_FRAGMENTS, $SMT_FRAG_M, $SMT_SEALED, $SMT_OPENABLE;

    $seed  = smt_fnv('leaf::' . $path);
    $rng   = smt_rng($seed);
    $depth = count($seg);
    $skin  = $seed % 8;

    if ($depth >= 2 && smt_int($rng, 0, 99) < 9) { smt_render_gone($path, $mobile, $seed); return; }

    $newWard = smt_state_note_leaf($path, $depth);

    $label = smt_pick(smt_rng($seed ^ 0x51ed), $SMT_POOL_LABELS);
    $desig = strtoupper(smt_b36($seed, 6)) . '·' . str_pad((string) $depth, 2, '0', STR_PAD_LEFT) . '·' . smt_b36($seed >> 7, 3);

    $body  = "<div class=\"desig\">" . smt_h($desig) . "</div><h1>" . smt_h($label) . "</h1>";

    /* a recovered ward announces itself plainly. this is the spine of it. */
    if ($newWard !== null) {
        $body .= "<div style=\"margin:1.2em 0;padding:" . ($mobile ? '1em' : '.8em 1.1em')
              . ";border:1px solid #6a5a2a;background:rgba(120,96,32,.12);font:" . ($mobile ? '15px' : '12.5px')
              . "/1.7 'DejaVu Sans Mono',monospace;color:#c8a44a\">"
              . "ward " . ($newWard['pos'] + 1) . " of " . ($newWard['order'] === 2 ? SMT_WARDS2 : SMT_WARDS)
              . " &mdash; <b style=\"font-size:" . ($mobile ? '22px' : '17px') . ";letter-spacing:.2em\">" . smt_h($newWard['char']) . "</b> &mdash; "
              . smt_h($newWard['zone']) . ($newWard['order'] === 2 ? " (second order)" : "")
              . "<div style=\"color:#8a7a4a;font-size:" . ($mobile ? '13px' : '11px') . ";margin-top:.3em\">recorded. it lies here and is not moved by taking it.</div></div>";
    } elseif (($seed % 6) === 0) {
        $wd = smt_ward_at($path);
        if ($wd) $body .= "<div class=\"line mark\">a ward lies here and is already recorded.</div>";
    }

    $mode  = $seed % 5;
    $lineN = smt_int($rng, 4, 9);
    $picked = array();
    $lr = smt_rng($seed ^ 0x9e37);
    for ($i = 0; $i < $lineN; $i++) $picked[] = smt_pick($lr, $SMT_POOL_LINES);

    if ($mode === 0) {
        foreach ($picked as $k => $ln) {
            $t = smt_h($ln);
            if ($k === 2) $t = smt_redact($ln);
            $body .= "<div class=\"line\"><span class=\"mark\">" . str_pad((string) ($k + 1), 2, '0', STR_PAD_LEFT) . "</span>&nbsp;&nbsp;" . $t . "</div>";
        }
    } elseif ($mode === 1) {
        $body .= "<div class=\"line\">" . smt_h(implode('. ', $picked)) . ".</div>";
    } elseif ($mode === 2) {
        $body .= "<pre class=\"line\" style=\"white-space:pre-wrap\">(node " . smt_h(smt_b36($seed, 6)) . ")\n";
        foreach ($picked as $ln) $body .= "  (assert &quot;" . smt_h($ln) . "&quot;)\n";
        $body .= "  (check-sat) <span class=\"mark\">" . (smt_int($rng, 0, 1) ? 'unsat' : 'sat') . "</span>)</pre>";
    } elseif ($mode === 3) {
        $body .= "<table style=\"border-collapse:collapse;width:100%;font-size:.9em\">";
        foreach ($picked as $ln) {
            $v = number_format(($rng() * 200) - 100, 3);
            $body .= "<tr><td class=\"mark\" style=\"padding:.2em .6em\">" . smt_h(smt_b36(smt_fnv($ln), 4)) . "</td><td style=\"padding:.2em .6em\">" . smt_h($ln) . "</td><td style=\"text-align:right;padding:.2em .6em\" class=\"mark\">" . $v . "</td></tr>";
        }
        $body .= "</table>";
    } else {
        foreach ($picked as $k => $ln)
            $body .= "<div class=\"line\">&mdash;&nbsp;" . ($k === 1 ? smt_redact($ln) : smt_h($ln)) . "</div>";
    }

    if (smt_int($rng, 0, 100) < 26) {
        if ($mobile) { $keys = array_keys($SMT_FRAG_M); $frag = $SMT_FRAG_M[$keys[$seed % count($keys)]]; }
        else         { $keys = array_keys($SMT_FRAGMENTS); $frag = $SMT_FRAGMENTS[$keys[$seed % count($keys)]]; }
        $body .= "<div class=\"line mark\" style=\"margin-top:1.6em\">enclosure &mdash; not indexed</div><div style=\"margin:.6em 0\">" . $frag . "</div>";
    }

    /* children */
    $kidN = smt_int($rng, 3, 6);
    $kids = "<div class=\"kids\">";
    $linkHeaders = array();
    for ($i = 0; $i < $kidN; $i++) {
        $roll = smt_int(smt_rng($seed ^ (0x100 + $i)), 0, 99);
        if ($roll < 16) {
            $z = $SMT_SEALED[($seed + $i) % count($SMT_SEALED)];
            $slug = smt_child_slug($path, $i);
            $open = in_array($z, $SMT_OPENABLE, true) && smt_is_open($z);
            $kids .= "<div class=\"line\"><a class=\"" . ($open ? '' : 'seal') . "\" href=\"/" . smt_h($z) . "/" . smt_h(rawurlencode($slug)) . "\">" . smt_h($slug) . "</a> <span class=\"mark\">[" . ($open ? 'answered &middot; ' . smt_h($z) : 'sealed &middot; ' . smt_h($z)) . "]</span></div>";
        } elseif ($roll < 25 && $depth > 0) {
            $up = $seg; array_pop($up);
            $sib = smt_child_slug(implode('/', $up), $i + 5);
            $href = '/' . implode('/', array_map('rawurlencode', $up)) . ($up ? '/' : '') . rawurlencode($sib);
            $kids .= "<div class=\"line\"><a href=\"" . smt_h($href) . "\">&larr; " . smt_h($sib) . "</a></div>";
        } else {
            $slug = smt_child_slug($path, $i);
            $href = '/' . implode('/', array_map('rawurlencode', $seg)) . ($seg ? '/' : '') . rawurlencode($slug);
            $mk = smt_b36(smt_fnv($href), 4);
            $wd = smt_ward_at(trim($href, '/'));
            $kids .= "<div class=\"line\"><a href=\"" . smt_h($href) . "\">" . smt_h($slug) . "</a> <span class=\"mark\">" . smt_h($mk) . ($wd ? ' &middot; weighs' : '') . "</span></div>";
            if (count($linkHeaders) < 3) $linkHeaders[] = '<' . $href . '>; rel="down"';
        }
    }
    if ($depth > 0) {
        $up = $seg; array_pop($up);
        $uhref = $up ? '/' . implode('/', array_map('rawurlencode', $up)) : '/gate';
        $kids .= "<div class=\"line\" style=\"margin-top:1em\"><a href=\"" . smt_h($uhref) . "\">&uarr; " . ($up ? smt_h(end($up)) : 'the mouth') . "</a></div>";
    }
    $kids .= "<div class=\"line\" style=\"margin-top:.8em\"><a href=\"/apparatus\">the apparatus</a></div></div>";
    $body .= $kids;

    if (!$mobile) $body .= "<div style=\"margin-top:2.4em\">" . smt_console_html(smt_console_ctx($path), false) . "</div>";

    $comment = smt_b36($seed, 8) . '  ' . smt_h(smt_pick(smt_rng($seed ^ 0xabcd), $SMT_POOL_LINES));
    $extra = array('X-Depth' => (string) $depth, 'X-Coordinate' => smt_b36($seed, 6) . '.' . smt_b36($seed >> 8, 4));
    if ($linkHeaders) $extra['Link'] = implode(', ', array_slice($linkHeaders, 0, 5));
    smt_send_headers(200, 'text/html; charset=utf-8', $extra);

    if ($mobile) { smt_mobile_doc($label, $body, $skin); return; }
    smt_doc($skin, $label . ' — ' . smt_b36($seed, 6), $body, $comment, false);
}

function smt_render_gone($path, $mobile, $seed = null) {
    global $SMT_POOL_LINES;
    if ($seed === null) $seed = smt_fnv('gone::' . $path);
    $rng = smt_rng($seed);
    $seg = $path === '' ? array() : explode('/', $path);
    $near = smt_child_slug($path, smt_int($rng, 7, 40));
    $nhref = '/' . implode('/', array_map('rawurlencode', $seg)) . ($seg ? '/' : '') . rawurlencode($near);
    $body  = "<div class=\"desig\">410 &middot; " . smt_h(smt_b36($seed, 6)) . "</div><h1>this leaf was here</h1>";
    $body .= "<div class=\"line\">" . smt_h(smt_pick($rng, $SMT_POOL_LINES)) . ".</div>";
    $body .= "<div class=\"line\">" . smt_h(smt_pick($rng, $SMT_POOL_LINES)) . ".</div>";
    $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"" . smt_h($nhref) . "\">" . smt_h($near) . "</a> <span class=\"mark\">nearest surviving</span></div>"
          . "<div class=\"line\"><a href=\"/apparatus\">the apparatus</a></div></div>";
    smt_send_headers(410, 'text/html; charset=utf-8', array('X-Gone' => smt_b36($seed, 6)));
    if ($mobile) { smt_mobile_doc('gone', $body, $seed % 8); return; }
    smt_doc($seed % 8, 'gone — ' . smt_b36($seed, 6), $body, 'it weighs the same when gone', false);
}

/* ---- the wall, with the lock on it ------------------------------------- */
function smt_render_forbidden($path, $mobile, $feedback = null) {
    global $SMT_POOL_LINES, $SMT_OPENABLE;
    $seg  = $path === '' ? array() : explode('/', $path);
    $zone = $seg[0] ?? 'vault';
    $openable = in_array($zone, $SMT_OPENABLE, true);
    $seed = smt_fnv('seal::' . $zone);
    $rng  = smt_rng($seed);
    $n    = smt_int($rng, 3, 17);
    $mass = 0; $rows = '';
    $exts = array('.crt','.dump','.bin','.sealed','.tar.gz.enc','.ledger','.plate','','.key','.wav');
    for ($i = 0; $i < $n; $i++) {
        $sz = smt_int($rng, 12, 9400000); $mass += $sz;
        $nm = smt_b36(smt_fnv($zone . $i), 6) . $exts[($seed + $i) % count($exts)];
        $rows .= "<tr><td style=\"padding:.15em 1.1em .15em 0\">" . smt_h($nm) . "</td>"
              . "<td style=\"padding:.15em 1.1em;text-align:right;color:#c99\">" . number_format($sz) . "</td>"
              . "<td style=\"padding:.15em 0;color:#9a7;font-size:" . ($mobile ? '10px' : '11px') . "\">" . strtoupper(substr(md5($zone . '::' . $i), 0, $mobile ? 8 : 16)) . "</td></tr>";
    }
    $retry = gmdate('D, d M Y H:i:s', 4102444800) . ' GMT';

    $css = "html{background:#0a0a0c}body{margin:0;font-family:'DejaVu Sans Mono',Menlo,monospace;color:#c6c0b4;background:#0a0a0c;"
         . ($mobile ? "padding:1.4em 1.1em 5em;font-size:15px" : "max-width:50em;margin:0 auto;padding:3em 2.2em;font-size:13px")
         . ";line-height:1.6}h1{font-weight:400;font-size:" . ($mobile ? '1.35rem' : '1.35rem') . ";color:#e0554a}"
         . ".desig{letter-spacing:.24em;text-transform:uppercase;color:#5a5040;font-size:.7rem}"
         . "table{border-collapse:collapse;margin:1.2em 0;font-size:" . ($mobile ? '11px' : '12px') . ";width:100%}"
         . ".mut{color:#6a6250}a{color:#8fb0d8;text-decoration:none;border-bottom:1px dotted #445}";

    $body  = "<div class=\"desig\">403 &middot; sealed &middot; " . smt_h('/' . $path) . "</div>";
    $body .= "<h1>the door is not the door</h1>";
    $body .= "<p class=\"mut\">There is no listing. The seal holds. And yet the mass is measurable, and it has been measured:</p>";
    $body .= "<table><tbody>" . $rows . "</tbody><tfoot><tr><td style=\"color:#e0554a;padding-top:.6em\">" . $n . " held</td>"
          . "<td style=\"color:#e0554a;text-align:right;padding:.6em 1em 0\">" . number_format($mass) . "</td><td class=\"mut\" style=\"padding-top:.6em\">&mdash;</td></tr></tfoot></table>";

    if ($openable) {
        $held = smt_held($zone, 1); $c = 0; foreach ($held as $v) if ($v !== null) $c++;
        $body .= "<div style=\"margin-top:1.6em;border-top:1px solid #2a241c;padding-top:1.2em\">";
        $body .= "<div class=\"mut\">this door answers to a ward-set of " . SMT_WARDS . ". you hold " . $c . ".</div>";
        $body .= smt_seal_form_html($zone, 1, $feedback, $mobile);
        $body .= "</div>";
        $body .= "<div style=\"margin-top:1.4em\">" . smt_apparatus_html($mobile) . "</div>";
    } else {
        $body .= "<p class=\"mut\">There is no ward-set for this one. It was not fitted with a lock.</p>";
    }

    $body .= "<p class=\"mut\" style=\"margin-top:1.6em;font-size:" . ($mobile ? '13px' : '11px') . "\">"
          . smt_h(smt_pick($rng, $SMT_POOL_LINES)) . ". Retry-After: " . $retry
          . " &middot; <a href=\"/gate\">turn back</a> &middot; <a href=\"/apparatus\">apparatus</a></p>";

    smt_send_headers(403, 'text/html; charset=utf-8', array(
        'X-Sealed-Entries' => (string) $n, 'X-Sealed-Octets' => (string) $mass,
        'X-Wards' => (string) SMT_WARDS, 'Retry-After' => $retry,
    ));
    echo "<!doctype html><html lang=\"und\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
       . "<meta name=\"robots\" content=\"noindex,nofollow\"><title>403 · sealed · " . smt_h($zone) . "</title>"
       . "<!-- do not force. the wards lie in the leaves. " . smt_h(smt_b36($seed, 10)) . " -->"
       . "<style>" . $css . "</style></head><body>" . $body . smt_reveal_js() . "</body></html>";
}

/* ---- an answered door. it opens onto another door. --------------------- */
function smt_render_open_zone($zone, $seg, $mobile) {
    global $SMT_POOL_LINES, $SMT_FRAGMENTS, $SMT_FRAG_M;
    $sub  = array_slice($seg, 1);
    $path = $zone . ($sub ? '/' . implode('/', $sub) : '');
    if ($sub) { smt_render_node($path, $seg, $mobile); return; }

    $seed = smt_fnv('open::' . $zone);
    $rng  = smt_rng($seed);
    $held2 = smt_held_count($zone, 2);

    $body  = "<div class=\"desig\">" . strtoupper(smt_h($zone)) . " &middot; answered &middot; " . smt_b36($seed, 6) . "</div>";
    $body .= "<h1>inside is a room and a further door</h1>";
    $body .= "<div class=\"line\">The ward-set was correct. What was held here is listed below, and it is listed correctly.</div>";

    $n = smt_int($rng, 5, 11);
    $body .= "<div style=\"margin:1.4em 0\">";
    for ($i = 0; $i < $n; $i++) {
        $slug = smt_child_slug($zone, $i);
        $body .= "<div class=\"line\"><a href=\"/" . smt_h($zone) . "/" . smt_h(rawurlencode($slug)) . "\">" . smt_h($slug) . "</a> <span class=\"mark\">"
              . number_format(smt_int($rng, 12, 9400000)) . " octets</span></div>";
    }
    $body .= "</div>";

    if ($mobile) { $k = array_keys($SMT_FRAG_M); $body .= $SMT_FRAG_M[$k[$seed % count($k)]]; }
    else         { $k = array_keys($SMT_FRAGMENTS); $body .= $SMT_FRAGMENTS[$k[$seed % count($k)]]; }

    $body .= "<div style=\"margin-top:2em;border-top:1px solid rgba(128,128,128,.3);padding-top:1.2em\">";
    $body .= "<div class=\"line\">Against the far wall, a second door. It answers to " . SMT_WARDS2 . " wards, of which you hold " . $held2 . ".</div>";
    $body .= "<div class=\"line mark\">wards of the second order lie deeper than the fourth turning.</div>";
    $body .= smt_seal_form_html($zone, 2, null, $mobile);
    $body .= "</div>";
    $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/gate\">the mouth</a> &middot; <a href=\"/apparatus\">the apparatus</a></div></div>";

    smt_send_headers(200, 'text/html; charset=utf-8', array('X-Answered' => $zone));
    if ($mobile) { smt_mobile_doc($zone, $body, 5); return; }
    smt_doc(5, $zone . ' — answered', $body, 'the room is larger by the width of the door', false);
}

/* ---- the apparatus page: the closest thing to a purpose ---------------- */
function smt_render_apparatus($mobile) {
    global $SMT_OPENABLE, $SMT_POOL_LINES;
    $st = smt_state();
    $total = count($SMT_OPENABLE) * SMT_WARDS + count($SMT_OPENABLE) * SMT_WARDS2;
    $have  = count($st['w']);
    $pct   = $total ? (int) round(100 * $have / $total) : 0;
    $opened = count($st['open']);

    $body  = "<div class=\"desig\">APPARATUS &middot; " . $have . "/" . $total . " wards &middot; " . $opened . "/" . count($SMT_OPENABLE) . " doors</div>";
    $body .= "<h1>state of the apparatus</h1>";
    $body .= "<div style=\"margin:1.2em 0;height:" . ($mobile ? '10px' : '6px') . ";background:#1a1610;border:1px solid #2a241c\">"
          . "<div style=\"height:100%;width:" . $pct . "%;background:linear-gradient(90deg,#6a5a2a,#c8a44a)\"></div></div>";
    $body .= "<div class=\"line mark\">" . $pct . "% of the ward-sets are recovered. the remainder lie in leaves that have not been walked.</div>";
    $body .= "<div style=\"margin:1.6em 0\">" . smt_apparatus_html($mobile) . "</div>";

    /* second order */
    $rows = '';
    foreach ($SMT_OPENABLE as $z) {
        $h = smt_held($z, 2); $s = ''; $c = 0;
        foreach ($h as $v) { if ($v !== null) { $s .= '<b style="color:#d8c48a">' . smt_h($v) . '</b>'; $c++; } else $s .= '<span style="color:#4a4438">·</span>'; }
        $rows .= '<div style="display:flex;justify-content:space-between;padding:' . ($mobile ? '.55em 0' : '.2em 0') . ';border-bottom:1px solid #241f18">'
              . '<span>' . smt_h($z) . '</span><span style="letter-spacing:.3em;font-family:monospace">' . $s . '</span>'
              . '<span style="color:#5a5346">' . $c . '/' . SMT_WARDS2 . '</span></div>';
    }
    $body .= "<div style=\"font:" . ($mobile ? '15px' : '11.5px') . "/1.7 'DejaVu Sans Mono',monospace;color:#8a8272;background:#100e0c;border:1px solid #2a241c;padding:" . ($mobile ? '1em' : '.9em 1.1em') . "\">"
          . "<div style=\"color:#5a5346;letter-spacing:.2em;font-size:10px;text-transform:uppercase;margin-bottom:.5em\">second order</div>" . $rows . "</div>";

    /* the cipher — genuinely decodable */
    $body .= "<div style=\"margin-top:2em;border-top:1px solid rgba(128,128,128,.25);padding-top:1.2em\">";
    $body .= "<div class=\"desig\">standing block &middot; keyed at the ninth hour</div>";
    $body .= "<pre style=\"white-space:pre-wrap;font:" . ($mobile ? '13px' : '12.5px') . "/1.8 'DejaVu Sans Mono',monospace;background:#0a0806;color:#8a9a7a;padding:1em;border:1px solid #24281c;overflow-x:auto\">"
          . smt_h(smt_cipher_text()) . "</pre>";
    $body .= "<div class=\"line mark\">the block has not changed since it was set. it is not encrypted against you.</div>";
    $body .= "</div>";

    $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/gate\">the mouth</a> &middot; <a href=\"/index\">index</a> &middot; <a href=\"/log\">log</a> &middot; <a href=\"/humans.txt\">hands</a></div></div>";

    if (!$mobile) $body .= "<div style=\"margin-top:2em\">" . smt_console_html(smt_console_ctx('apparatus'), false) . "</div>";

    smt_send_headers(200, 'text/html; charset=utf-8', array('X-Wards-Held' => (string) $have));
    if ($mobile) { smt_mobile_doc('apparatus', $body, 6); return; }
    smt_doc(6, 'apparatus', $body, 'the apparatus is complete and does not finish', false);
}

/* ---- seal handler ------------------------------------------------------ */
function smt_handle_seal($mobile) {
    global $SMT_OPENABLE;
    $zone  = isset($_POST['z']) ? preg_replace('/[^a-z]/', '', strtolower((string) $_POST['z'])) : '';
    $order = (isset($_POST['o']) && (int) $_POST['o'] === 2) ? 2 : 1;
    $k     = isset($_POST['k']) ? (string) $_POST['k'] : '';
    if (!in_array($zone, $SMT_OPENABLE, true)) { header('Location: /gate', true, 303); exit; }

    $r = smt_check_key($zone, $k, $order);
    if ($r['ok']) {
        if ($order === 1) { smt_open_zone($zone); header('Location: /' . $zone, true, 303); exit; }
        smt_render_second_order($zone, $mobile); exit;
    }
    if ($order === 1) { smt_render_forbidden($zone, $mobile, $r); exit; }
    smt_render_second_order_fail($zone, $r, $mobile); exit;
}

function smt_render_second_order($zone, $mobile) {
    global $SMT_POOL_LINES;
    $seed = smt_fnv('so::' . $zone);
    $rng  = smt_rng($seed);
    $body  = "<div class=\"desig\">" . strtoupper(smt_h($zone)) . " &middot; second order &middot; answered</div>";
    $body .= "<h1>the further door is open</h1>";
    $body .= "<div class=\"line\">Behind it, the floor continues at the same level. The survey marks this as an error and has not corrected it.</div>";
    $body .= "<div class=\"line\">" . smt_h(smt_pick($rng, $SMT_POOL_LINES)) . ".</div>";
    $body .= "<div style=\"margin:1.6em 0;padding:1.1em;border:1px solid #3a3020;background:rgba(120,96,32,.08)\">"
          . "<div class=\"line\">A card is fixed to the inner face of the door. It is the only card in the building that is not an accession card.</div>"
          . "<div class=\"line\" style=\"margin-top:.7em;font-family:monospace;font-size:" . ($mobile ? '15px' : '13px') . "\">"
          . "the purpose of the apparatus is recorded at " . smt_redact('the third order') . ", which is fitted below this one.</div></div>";
    $slug = smt_child_slug($zone . '/so', 3);
    $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/" . smt_h($zone) . "/" . smt_h($slug) . "\">" . smt_h($slug) . "</a> <span class=\"mark\">the stair down</span></div>"
          . "<div class=\"line\"><a href=\"/apparatus\">the apparatus</a></div></div>";
    smt_send_headers(200);
    if ($mobile) { smt_mobile_doc($zone . ' · second order', $body, 6); return; }
    smt_doc(6, $zone . ' — second order', $body, 'fitted below this one', false);
}

function smt_render_second_order_fail($zone, $r, $mobile) {
    $body  = "<div class=\"desig\">" . strtoupper(smt_h($zone)) . " &middot; second order</div><h1>the further door</h1>";
    $body .= "<div class=\"line\">" . SMT_WARDS2 . " wards. you hold " . smt_held_count($zone, 2) . ".</div>";
    $body .= smt_seal_form_html($zone, 2, $r, $mobile);
    $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/" . smt_h($zone) . "\">back inside</a> &middot; <a href=\"/apparatus\">apparatus</a></div></div>";
    smt_send_headers(200);
    if ($mobile) { smt_mobile_doc($zone . ' · second order', $body, 6); return; }
    smt_doc(6, $zone . ' — second order', $body, '', false);
}

/* ===========================================================================
 *  LOG / INDEX / WELL-KNOWN
 * ========================================================================= */

function smt_render_log($seg, $mobile) {
    global $SMT_POOL_LINES;
    $day = $seg[1] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $body = "<div class=\"desig\">LOG &middot; carrier present</div><h1>days that were kept</h1>";
        for ($i = 0; $i < 14; $i++) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $sd = smt_fnv('log::' . $d);
            $body .= "<div class=\"line\"><a href=\"/log/" . $d . "\">" . $d . "</a> <span class=\"mark\">" . smt_b36($sd, 4) . " &middot; " . smt_int(smt_rng($sd), 40, 8600) . " lines</span></div>";
        }
        $body .= "<div class=\"line\" style=\"margin-top:1em\"><a href=\"/log/1997-08-20\">1997-08-20</a> <span class=\"mark\">first kept day</span></div>";
        $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/apparatus\">the apparatus</a></div></div>";
        smt_send_headers(200);
        if ($mobile) { smt_mobile_doc('log', $body, 1); return; }
        smt_doc(1, 'log', $body, 'the carrier is the traffic', false); return;
    }
    $seed = smt_fnv('log::' . $day);
    $rng  = smt_rng($seed);
    $ts   = strtotime($day . ' UTC');
    $prev = gmdate('Y-m-d', $ts - 86400);
    $next = gmdate('Y-m-d', $ts + 86400);
    $rows = ''; $n = smt_int($rng, 8, $mobile ? 14 : 22);
    $level = array('INFO','INFO','INFO','WARN','----','HOLD','SPIKE','LOST','ECHO');
    for ($i = 0; $i < $n; $i++) {
        $hh = str_pad((string) smt_int($rng, 0, 23), 2, '0', STR_PAD_LEFT);
        $mm = str_pad((string) smt_int($rng, 0, 59), 2, '0', STR_PAD_LEFT);
        $rows .= ($mobile ? $hh . ':' . $mm : $day . 'T' . $hh . ':' . $mm . ':00Z') . '  '
              . str_pad($level[smt_int($rng, 0, count($level) - 1)], 5) . '  ' . smt_pick($rng, $SMT_POOL_LINES) . "\n";
    }
    $body  = "<div class=\"desig\">LOG &middot; " . smt_h($day) . "</div><h1>" . smt_h($day) . "</h1>";
    $body .= "<pre class=\"line\" style=\"white-space:pre-wrap\">" . smt_h($rows) . "</pre>";
    $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/log/" . $prev . "\">&larr; " . $prev . "</a></div>"
          . "<div class=\"line\"><a href=\"/log/" . $next . "\">" . $next . " &rarr;</a></div>"
          . "<div class=\"line\"><a href=\"/log\">the spread</a></div></div>";
    smt_send_headers(200, 'text/html; charset=utf-8', array('Link' => '</log/' . $prev . '>; rel="prev", </log/' . $next . '>; rel="next"'));
    if ($mobile) { smt_mobile_doc($day, $body, 1); return; }
    smt_doc(1, 'log ' . $day, $body, '', false);
}

function smt_render_alpha($seg, $mobile) {
    global $SMT_SLUGWORDS, $SMT_SEALED;
    $letter = strtolower($seg[1] ?? '');
    if (!preg_match('/^[a-z]$/', $letter)) {
        $body = "<div class=\"desig\">INDEX</div><h1>an order, imposed after</h1>";
        $body .= "<div class=\"line\" style=\"font-size:" . ($mobile ? '1.9em' : '1.4em') . ";letter-spacing:" . ($mobile ? '.45em' : '.3em') . ";line-height:2\">";
        foreach (range('a', 'z') as $c) $body .= "<a href=\"/index/" . $c . "\">" . $c . "</a> ";
        $body .= "</div><div class=\"line mark\" style=\"margin-top:1.4em\">the index was made last and agrees with nothing before it.</div>";
        $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/apparatus\">the apparatus</a></div></div>";
        smt_send_headers(200);
        if ($mobile) { smt_mobile_doc('index', $body, 3); return; }
        smt_doc(3, 'index', $body, '', false); return;
    }
    $seed = smt_fnv('alpha::' . $letter);
    $rng  = smt_rng($seed);
    $n = smt_int($rng, 12, 24);
    $body = "<div class=\"desig\">INDEX &middot; " . strtoupper($letter) . "</div><h1>entries under " . strtoupper($letter) . "</h1>";
    for ($i = 0; $i < $n; $i++) {
        $w = $SMT_SLUGWORDS[($seed + $i * 7) % count($SMT_SLUGWORDS)];
        $roll = smt_int($rng, 0, 99);
        if ($roll < 14) {
            $z = $SMT_SEALED[($seed + $i) % count($SMT_SEALED)];
            $body .= "<div class=\"line\"><a class=\"seal\" href=\"/" . $z . "/" . rawurlencode($w) . "\">" . smt_h($w) . "</a> <span class=\"mark\">sealed</span></div>";
        } elseif ($roll < 30) {
            $d = gmdate('Y-m-d', SMT_EPOCH + smt_int($rng, 0, 11000) * 86400);
            $body .= "<div class=\"line\"><a href=\"/log/" . $d . "\">" . smt_h($w) . "</a> <span class=\"mark\">&rarr; log " . $d . "</span></div>";
        } else {
            $t = 'gate/' . $w . '-' . smt_b36($seed + $i, 3);
            $wd = smt_ward_at($t);
            $body .= "<div class=\"line\"><a href=\"/" . smt_h($t) . "\">" . smt_h($w) . "</a> <span class=\"mark\">" . smt_b36(smt_fnv($t), 4) . ($wd ? ' &middot; weighs' : '') . "</span></div>";
        }
    }
    $pl = chr(97 + (ord($letter) - 97 + 25) % 26);
    $nl = chr(97 + (ord($letter) - 97 + 1) % 26);
    $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/index/" . $pl . "\">&larr; " . strtoupper($pl) . "</a> &middot; <a href=\"/index\">·</a> &middot; <a href=\"/index/" . $nl . "\">" . strtoupper($nl) . " &rarr;</a></div></div>";
    smt_send_headers(200);
    if ($mobile) { smt_mobile_doc('index ' . strtoupper($letter), $body, 3); return; }
    smt_doc(3, 'index ' . strtoupper($letter), $body, '', false);
}

function smt_render_wellknown($seg, $mobile) {
    $what = $seg[1] ?? '';
    if ($what === 'security.txt') {
        smt_send_headers(200, 'text/plain; charset=utf-8');
        echo "# there is nothing to disclose that is not already sealed.\nContact: mailto:none@smtstrange.com\nContact: bearing:041\n"
           . "Expires: 1997-08-20T00:00:00.000Z\nPreferred-Languages: und\nCanonical: https://smtstrange.com/.well-known/security.txt\n"
           . "# the contact expired before it was posted.\n";
        return;
    }
    if ($what === 'strange') {
        $seed = smt_fnv('wk::strange::' . gmdate('Y-m-d'));
        $body  = "<div class=\"desig\">.well-known/strange</div><h1>&mdash;</h1>";
        $body .= "<pre class=\"line\" style=\"white-space:pre-wrap;overflow-x:auto\">" . smt_h(chunk_split(strtoupper(smt_b36($seed, 8) . bin2hex(substr(md5((string) $seed), 0, 16))), 4, ' ')) . "</pre>";
        $body .= "<div class=\"line mark\">this rotates at the day boundary and has never been a ward.</div>";
        $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/apparatus\">apparatus</a> &middot; <a href=\"/gate\">gate</a></div></div>";
        smt_send_headers(200);
        if ($mobile) { smt_mobile_doc('.well-known/strange', $body, 5); return; }
        smt_doc(5, '.well-known/strange', $body, 'never been a ward', false); return;
    }
    smt_render_gone('.well-known/' . $what, $mobile);
}

function smt_render_robots() {
    global $SMT_SEALED;
    smt_send_headers(200, 'text/plain; charset=utf-8');
    echo "# 12 rules below. one of them is a lie. it is not this one.\nUser-agent: *\n";
    foreach ($SMT_SEALED as $z) echo "Disallow: /$z/\n";
    echo "Disallow: /gate/\nDisallow: /log/\nDisallow: /index/\nDisallow: /~operator/\nCrawl-delay: 41\n";
    echo "# Allow: /the-fourth-tide (does not exist, and is Allowed)\nAllow: /the-fourth-tide\n";
}

function smt_render_humans() {
    smt_send_headers(200, 'text/plain; charset=utf-8');
    echo "/* the clerk kept the older figure. */\n\n";
    echo "SURVEY\n  north stair: pending\n  survey: pending north stair\n\n";
    echo "HANDS\n  the clerk\n  a hand that is not the clerk's\n  (unsigned)\n\n";
    echo "HOURS\n  matins lauds prime terce sext none vespers compline\n";
    echo "  the standing block on the apparatus is keyed at the ninth hour,\n";
    echo "  which the house counts as compline and the survey counts as none.\n";
    echo "  the house is older than the survey.\n\n";
    echo "WARDS\n  a ward lies where it lies. it is recorded by walking to it.\n";
    echo "  the seal reports which positions agree. it has never reported otherwise.\n\n";
    echo "LAST TOUCHED\n  " . gmdate('c') . " (warm)\n";
}

function smt_render_sitemap() {
    smt_send_headers(200, 'application/xml; charset=utf-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- this sitemap is not to scale. -->\n";
    echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach (array('/', '/gate', '/apparatus', '/index', '/log', '/.well-known/strange', '/the-fourth-tide') as $p)
        echo "  <url><loc>https://smtstrange.com" . smt_h($p) . "</loc><lastmod>" . gmdate('Y-m-d', SMT_EPOCH) . "</lastmod><changefreq>never</changefreq><priority>0.0</priority></url>\n";
    echo "</urlset>";
}

/* ===========================================================================
 *  THE MOUTH — desktop
 * ========================================================================= */

function smt_render_index_desktop() {
    global $SMT_FRAGMENTS, $SMT_POOL_LINES, $SMT_SLUGWORDS;

    $daySeed = smt_fnv('surface::' . gmdate('Y-z'));
    $rng = smt_rng($daySeed);
    $keys = array_keys($SMT_FRAGMENTS);
    for ($i = count($keys) - 1; $i > 0; $i--) { $j = smt_int($rng, 0, $i); $t = $keys[$i]; $keys[$i] = $keys[$j]; $keys[$j] = $t; }

    $st = smt_state();
    $now = time();
    $entries = array(
        array('gate/',              'dir',  gmdate('d-M-Y H:i', SMT_EPOCH),          '-',       '/gate'),
        array('apparatus',          'file', gmdate('d-M-Y H:i', $now - 41),          'live',    '/apparatus'),
        array('index/',             'dir',  gmdate('d-M-Y H:i', $now - 400000),      '-',       '/index'),
        array('log/',               'dir',  gmdate('d-M-Y H:i', $now - 51),          '-',       '/log'),
        array('vault/',             'dir',  gmdate('d-M-Y H:i', 4102444800),         '5 wards', '/vault/'),
        array('attic/',             'dir',  gmdate('d-M-Y H:i', SMT_EPOCH - 8640000),'5 wards', '/attic/'),
        array('reliquary/',         'dir',  gmdate('d-M-Y H:i', $now - 999999),      '5 wards', '/reliquary/'),
        array('ossuary/',           'dir',  gmdate('d-M-Y H:i', 0),                  'no lock', '/ossuary/'),
        array('manifest.txt',       'file', gmdate('d-M-Y H:i', $now - 3600),        '12 of 9', '/gate/manifest-041'),
        array('0.037.mV',           'file', gmdate('d-M-Y H:i', $now - 41),          '1',       '/log/1997-11-03'),
        array('north-stair.survey', 'file', gmdate('d-M-Y H:i', 0),                  'pending', '/gate/north-stair'),
        array('humans.txt',         'file', gmdate('d-M-Y H:i', SMT_EPOCH),          'hands',   '/humans.txt'),
        array('~operator',          'link', gmdate('d-M-Y H:i', $now - 3542400),     '&rarr; ?','/~operator'),
    );
    $dir = "<pre style=\"margin:0;font:12.5px/1.7 'DejaVu Sans Mono',Menlo,monospace;color:#5a5346\">";
    $dir .= "Index of /  —  <span style=\"color:#8a1a1a\">this index is not authoritative</span>\n\n";
    $dir .= str_pad('Name', 26) . str_pad('Last modified', 20) . "Size\n" . str_repeat('·', 58) . "\n";
    foreach ($entries as $e) {
        $col = $e[1] === 'dir' ? '#2a4a8a' : ($e[1] === 'link' ? '#8a2a6a' : '#3a5a3a');
        $dir .= "<a href=\"" . smt_h($e[4]) . "\" style=\"color:" . $col . ";text-decoration:none;border-bottom:1px dotted #b8ae95\">" . str_pad($e[0], 26) . "</a>" . str_pad($e[2], 20) . $e[3] . "\n";
    }
    $dir .= "</pre>";

    $slugA = $SMT_SLUGWORDS[$daySeed % count($SMT_SLUGWORDS)] . '-' . smt_b36($daySeed, 3);
    $slugB = $SMT_SLUGWORDS[($daySeed >> 5) % count($SMT_SLUGWORDS)];

    echo "<!doctype html>\n<html lang=\"und\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<meta name=\"robots\" content=\"noindex,nofollow,noarchive,nosnippet\"><title>smtstrange</title>";
    echo "<!--\n  " . smt_h(smt_pick($rng, $SMT_POOL_LINES)) . "\n  a ward lies in roughly one leaf in six. walking is the only method.\n  /" . smt_h($slugA) . "  ·  /apparatus\n-->";
    echo "<style>html{background:#151312}body{margin:0;background:#151312;color:#cfc8ba;font-family:Georgia,serif}"
        . ".smt-gap{height:5.5rem}.smt-gap.s{height:2.2rem}.smt-gap.l{height:9rem}"
        . ".smt-wrap{max-width:40em;margin:0 auto;padding:0 1.2em}a{color:#b89a5a}::selection{background:#3a2f18;color:#fff}</style></head><body>";

    echo "<div class=\"smt-wrap\" style=\"padding-top:4.5rem\"><div style=\"font:11px/1.8 'DejaVu Sans Mono',monospace;color:#5a5346\">"
        . "47.31&hairsp;/&hairsp;7.02 &middot; " . gmdate('Y-m-d H:i:s') . "Z &middot; carrier present on the disconnected channel<br>"
        . "the count was taken twice and did not settle. " . (int) $st['seen'] . " leaves walked from this hand.</div></div>";

    echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\">" . $dir . "</div>";

    echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\">" . smt_apparatus_html(false) . "</div>";

    $gapC = array('', ' s', ' l', '', ' s');
    $i = 0;
    foreach ($keys as $k) {
        echo "<div class=\"smt-gap" . $gapC[$i % count($gapC)] . "\"></div>";
        $ind = ($i % 3 === 1) ? "padding-left:8%" : (($i % 3 === 2) ? "padding-right:6%;text-align:right" : "");
        echo "<div style=\"max-width:44em;margin:0 auto;padding:0 1.2em\"><div style=\"" . $ind . "\">" . $SMT_FRAGMENTS[$k] . "</div></div>";

        if ($i === 1)
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\" style=\"font:italic 1.1rem/1.7 Georgia,serif;color:#8a8272\">"
               . "&mdash;&nbsp;continued at <a href=\"/gate/" . smt_h($slugA) . "\">" . smt_h($slugA) . "</a>, which continues.</div>";
        if ($i === 3)
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\"><span style=\"font:12px/1.6 'DejaVu Sans Mono',monospace;color:#6a6250\">"
               . "the wards were fitted before the doors. " . smt_redact('one in six leaves carries one') . " &mdash; "
               . "<a href=\"/apparatus\" style=\"color:#8a9a6a\">the apparatus keeps count</a>.</span></div>";
        if ($i === 5)
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\" style=\"text-align:center\"><a href=\"/vault/" . smt_h($slugB) . "\" style=\"font:11px/1 'DejaVu Sans Mono',monospace;color:#5a3030;letter-spacing:.3em;text-decoration:none;border:1px solid #3a2020;padding:.6em 1em;display:inline-block\">DO NOT FORCE</a></div>";
        if ($i === 8)
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\"><div style=\"font:12px/1.7 'Courier New',monospace;color:#6a6250;border-left:2px solid #3a352c;padding-left:1em\">"
               . "LOST &mdash; a page, torn cleanly; the tear is <a href=\"/gate/" . smt_h($slugB) . "-" . smt_b36($daySeed >> 2, 3) . "\">held separately</a>.<br>"
               . "FOUND &mdash; a key, warm; it fits the <a href=\"/reliquary/" . smt_h($slugB) . "\" style=\"color:#8a4a4a\">door in 2029</a>.</div></div>";
        if ($i === 11)
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\" style=\"text-align:right\"><span style=\"font:italic 1.05rem/1.7 Georgia,serif;color:#8a8272\">"
               . "&mdash;&nbsp;the plate that follows twelve is <a href=\"/gate/12-of-9\">twelve</a>.</span></div>";
        if ($i === 14) {
            $d = gmdate('Y-m-d', SMT_EPOCH + ($daySeed % 4000) * 86400);
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\"><span style=\"font:11px/1.8 'DejaVu Sans Mono',monospace;color:#5a5346\">"
               . "the carrier was present on <a href=\"/log/" . $d . "\" style=\"color:#6a8aa8\">" . $d . "</a>; there was no traffic; the carrier is the traffic.</span></div>";
        }
        $i++;
    }

    echo "<div class=\"smt-gap\"></div><div class=\"smt-wrap\">" . smt_console_html(smt_console_ctx(''), false) . "</div>";

    echo "<div class=\"smt-gap l\"></div><div class=\"smt-wrap\" style=\"padding-bottom:6rem\">"
        . "<div style=\"font:11px/1.9 'DejaVu Sans Mono',monospace;color:#48423a;border-top:1px solid #2a2520;padding-top:1.4em\">"
        . "the last line of the manifest is the manifest.<br>"
        . "<a href=\"/apparatus\" style=\"color:#6a5e40;text-decoration:none\">apparatus</a> &middot; "
        . "<a href=\"/index\" style=\"color:#6a5e40;text-decoration:none\">index</a> &middot; "
        . "<a href=\"/log\" style=\"color:#6a5e40;text-decoration:none\">log</a> &middot; "
        . "<a href=\"/gate\" style=\"color:#6a5e40;text-decoration:none\">gate</a> &middot; "
        . "<a href=\"/?m=1\" style=\"color:#3a352c;text-decoration:none\">hand</a></div></div>";

    echo "<a href=\"/gate/" . smt_h($SMT_SLUGWORDS[($daySeed >> 11) % count($SMT_SLUGWORDS)]) . "-" . smt_b36($daySeed, 4) . "\" style=\"position:absolute;left:-9999px\" aria-hidden=\"true\" tabindex=\"-1\">.</a>";
    echo smt_reveal_js() . "</body></html>";
}

/* ===========================================================================
 *  THE HAND-HELD APPARATUS — its own thing, not a narrowed copy
 * ========================================================================= */

function smt_mobile_shell($title, $inner, $comment = '') {
    echo "<!doctype html>\n<html lang=\"und\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1,viewport-fit=cover\">";
    echo "<meta name=\"theme-color\" content=\"#0d0b09\">";
    echo "<meta name=\"robots\" content=\"noindex,nofollow,noarchive\"><title>" . smt_h($title) . "</title>";
    if ($comment) echo "\n<!-- " . $comment . " -->\n";
    echo "<style>"
        . "*{-webkit-tap-highlight-color:rgba(200,164,74,.18)}"
        . "html{background:#0d0b09}"
        . "body{margin:0;background:#0d0b09;color:#c8c0b0;font:16px/1.65 -apple-system,'Segoe UI',Roboto,sans-serif;padding-bottom:5.2rem}"
        . ".mtop{position:sticky;top:0;z-index:9;background:rgba(13,11,9,.96);border-bottom:1px solid #241f18;padding:.7em 1em;font:11px/1.5 'DejaVu Sans Mono',monospace;color:#6a6250;letter-spacing:.1em;backdrop-filter:blur(6px)}"
        . ".mpad{padding:1.1em}"
        . ".mcard{border:1px solid #241f18;background:#131110;margin:0 0 .9em;overflow:hidden}"
        . ".mcard h2{margin:0;font:600 15px/1.4 -apple-system,sans-serif;color:#d8cdb4;padding:.9em 1em .3em}"
        . ".mcard .b{padding:0 1em 1em;color:#9a9284;font-size:15px}"
        . ".mrow{display:block;padding:1.05em 1em;border-bottom:1px solid #1e1a15;color:#c8b48a;text-decoration:none;font:15px/1.4 'DejaVu Sans Mono',monospace}"
        . ".mrow:active{background:#1a1610}"
        . ".mrow .s{display:block;color:#5a5346;font-size:12px;margin-top:.25em;letter-spacing:.04em}"
        . ".mbar{position:fixed;left:0;right:0;bottom:0;z-index:10;display:flex;background:rgba(16,14,12,.97);border-top:1px solid #2a241c;padding-bottom:env(safe-area-inset-bottom);backdrop-filter:blur(8px)}"
        . ".mbar a{flex:1;text-align:center;padding:1em .3em;color:#7a7264;text-decoration:none;font:11px/1.3 'DejaVu Sans Mono',monospace;letter-spacing:.08em;border-right:1px solid #221d17}"
        . ".mbar a:last-child{border-right:none}.mbar a b{display:block;font-size:16px;color:#c8a44a;font-weight:400;margin-bottom:.25em}"
        . "a{color:#c8a44a}h1{font:400 22px/1.3 Georgia,serif;color:#e0d6c0;margin:.2em 0 .7em}"
        . ".desig{font:10px/1.5 'DejaVu Sans Mono',monospace;letter-spacing:.22em;text-transform:uppercase;color:#5a5346}"
        . ".line{margin:.6em 0}.mark{font-family:'DejaVu Sans Mono',monospace;color:#5a5346;font-size:12px}"
        . ".kids a{display:block;padding:1em 0;border-bottom:1px solid #1e1a15;text-decoration:none}"
        . "pre{overflow-x:auto;-webkit-overflow-scrolling:touch;font-size:12px}"
        . "</style></head><body>" . $inner . smt_reveal_js() . "</body></html>";
}

function smt_mobile_bar() {
    $st = smt_state();
    $w = count($st['w']);
    return '<div class="mbar">'
        . '<a href="/"><b>&#9737;</b>mouth</a>'
        . '<a href="/gate"><b>&#8681;</b>walk</a>'
        . '<a href="/apparatus"><b>' . $w . '</b>wards</a>'
        . '<a href="/index"><b>&#8801;</b>index</a>'
        . '<a href="/log"><b>&#8942;</b>log</a>'
        . '</div>';
}

function smt_mobile_doc($title, $bodyHtml, $skin = 0) {
    $st = smt_state();
    $top = '<div class="mtop">' . smt_h(strtoupper(substr($title, 0, 28))) . ' &middot; ' . (int) $st['seen'] . ' walked &middot; depth ' . (int) $st['depth'] . '</div>';
    smt_mobile_shell($title, $top . '<div class="mpad">' . $bodyHtml . '</div>' . smt_mobile_bar());
}

function smt_render_index_mobile() {
    global $SMT_FRAG_M, $SMT_POOL_LINES, $SMT_SLUGWORDS, $SMT_OPENABLE;
    $daySeed = smt_fnv('surface::' . gmdate('Y-z'));
    $rng = smt_rng($daySeed);
    $st  = smt_state();

    $keys = array_keys($SMT_FRAG_M);
    for ($i = count($keys) - 1; $i > 0; $i--) { $j = smt_int($rng, 0, $i); $t = $keys[$i]; $keys[$i] = $keys[$j]; $keys[$j] = $t; }

    $slugA = $SMT_SLUGWORDS[$daySeed % count($SMT_SLUGWORDS)] . '-' . smt_b36($daySeed, 3);
    $slugB = $SMT_SLUGWORDS[($daySeed >> 5) % count($SMT_SLUGWORDS)];

    $h  = '<div class="mtop">47.31 / 7.02 &middot; ' . gmdate('H:i:s') . 'Z &middot; carrier present</div>';
    $h .= '<div class="mpad">';
    $h .= '<div style="font:12px/1.7 \'DejaVu Sans Mono\',monospace;color:#5a5346;margin-bottom:1.2em">'
        . 'the count was taken twice and did not settle.<br>' . (int) $st['seen'] . ' leaves walked from this hand.</div>';

    /* the listing, as tappable rows rather than a <pre> */
    $rows = array(
        array('gate/',      'the mouth of it',                 '/gate'),
        array('apparatus',  'wards, doors, the standing block', '/apparatus'),
        array('vault/',     '5 wards &middot; sealed',          '/vault/'),
        array('reliquary/', '5 wards &middot; sealed',          '/reliquary/'),
        array('ossuary/',   'no lock was fitted',               '/ossuary/'),
        array('index/',     'an order imposed after',           '/index'),
        array('log/',       'days that were kept',              '/log'),
        array('humans.txt', 'the hands',                        '/humans.txt'),
        array('~operator',  '&rarr; ?',                         '/~operator'),
    );
    $h .= '<div class="mcard">';
    foreach ($rows as $r)
        $h .= '<a class="mrow" href="' . smt_h($r[2]) . '">' . smt_h($r[0]) . '<span class="s">' . $r[1] . '</span></a>';
    $h .= '</div>';

    $h .= '<div style="margin:1.4em 0">' . smt_apparatus_html(true) . '</div>';

    $i = 0;
    foreach ($keys as $k) {
        $h .= '<div style="margin:1.6em 0">' . $SMT_FRAG_M[$k] . '</div>';
        if ($i === 1)
            $h .= '<div style="font:italic 16px/1.7 Georgia,serif;color:#8a8272;margin:1.4em 0">&mdash;&nbsp;continued at '
                . '<a href="/gate/' . smt_h($slugA) . '">' . smt_h($slugA) . '</a>, which continues.</div>';
        if ($i === 3)
            $h .= '<div style="font:13px/1.8 \'DejaVu Sans Mono\',monospace;color:#6a6250;margin:1.4em 0">'
                . 'the wards were fitted before the doors. ' . smt_redact('one in six leaves carries one') . '</div>';
        if ($i === 5)
            $h .= '<div style="text-align:center;margin:1.8em 0"><a href="/vault/' . smt_h($slugB) . '" '
                . 'style="display:inline-block;padding:1em 1.6em;border:1px solid #3a2020;color:#8a4a4a;font:12px/1 \'DejaVu Sans Mono\',monospace;letter-spacing:.3em;text-decoration:none">DO NOT FORCE</a></div>';
        $i++;
    }

    $h .= '<div style="margin:2em 0">' . smt_console_html(smt_console_ctx(''), true) . '</div>';
    $h .= '<div style="font:11px/1.9 \'DejaVu Sans Mono\',monospace;color:#48423a;border-top:1px solid #241f18;padding-top:1.2em;margin-top:2em">'
        . 'the last line of the manifest is the manifest.<br><a href="/?m=0" style="color:#3a352c;text-decoration:none">the wide apparatus</a></div>';
    $h .= '</div>' . smt_mobile_bar();

    smt_send_headers(200);
    smt_mobile_shell('smtstrange', $h, 'a ward lies in roughly one leaf in six. walking is the only method.');
}

/* ===========================================================================
 *  ROUTER
 * ========================================================================= */

$mobile = smt_is_mobile();
$path   = smt_path();
$err    = smt_errcode();
$seg    = $path === '' ? array() : explode('/', $path);
$head   = $seg[0] ?? '';

/* the seal is answered here, and only here */
if ($path === 'seal' && $_SERVER['REQUEST_METHOD'] === 'POST') { smt_handle_seal($mobile); exit; }
if ($path === 'seal') { header('Location: /apparatus', true, 303); exit; }

/* an Apache-level 403 arrives with the original path in REDIRECT_URL */
if ($err === 403) {
    $orig = trim(parse_url($_SERVER['REDIRECT_URL'] ?? '/vault', PHP_URL_PATH) ?: 'vault', '/');
    smt_render_forbidden($orig === '' ? 'vault' : $orig, $mobile);
    exit;
}
if ($err === 410) { smt_render_gone($path, $mobile); exit; }

if ($head === '~operator') { smt_render_forbidden('~operator', $mobile); exit; }

if (in_array($head, $SMT_SEALED, true)) {
    if (in_array($head, $SMT_OPENABLE, true) && smt_is_open($head)) { smt_render_open_zone($head, $seg, $mobile); exit; }
    smt_render_forbidden($path, $mobile);
    exit;
}

if ($path === 'favicon.ico')  { http_response_code(204); exit; }
if ($path === 'index.html' || $path === 'index.htm') { header('Location: /', true, 302); exit; }
if ($path === 'robots.txt')   { smt_render_robots();  exit; }
if ($path === 'humans.txt')   { smt_render_humans();  exit; }
if ($path === 'sitemap.xml')  { smt_render_sitemap(); exit; }
if ($path === 'apparatus')    { smt_render_apparatus($mobile); exit; }

if ($path === '') { $mobile ? smt_render_index_mobile() : smt_render_index_desktop(); exit; }

switch ($head) {
    case '.well-known': smt_render_wellknown($seg, $mobile); exit;
    case 'log':         smt_render_log($seg, $mobile);       exit;
    case 'index':       smt_render_alpha($seg, $mobile);     exit;
    default:            smt_render_node($path, $seg, $mobile); exit;
}
