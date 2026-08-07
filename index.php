<?php
/* ============================================================================
 *  ::: smtstrange :::
 *  there is no index of this. the engine decides what was always here.
 *  do not force the sealed zones. the mass is accounted for. the door is not.
 * ----------------------------------------------------------------------------
 *  4e 6f 74 20 65 76 65 72 79 20 70 61 74 68 20 6c 65 61 64 73 20 6f 75 74 2e
 * ========================================================================== */

@ini_set('display_errors', '0');
error_reporting(0);
date_default_timezone_set('UTC');
if (function_exists('mb_internal_encoding')) mb_internal_encoding('UTF-8');

define('SMT_ROOT', __DIR__);
define('SMT_EPOCH', 872035200);   // 1997-08-20T00:00:00Z — a date it insists predates it

/* ---------------------------------------------------------------------------
 *  the engine may lay down its own gate-keeping if it has been stripped away.
 *  ("die index.php darf auch eine htaccess anlegen")
 * ------------------------------------------------------------------------- */
(function () {
    $ht = SMT_ROOT . '/.htaccess';
    if (@is_file($ht)) return;
    if (!@is_writable(SMT_ROOT)) return;
    $rules = <<<'HTACCESS'
Options -Indexes +FollowSymLinks -MultiViews
ServerSignature Off
DirectoryIndex index.php
AddDefaultCharset utf-8
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Seal "closed; do not force"
    Header always set Referrer-Policy "no-referrer"
    Header always unset X-Powered-By
</IfModule>
<Files ".htaccess">
    Require all denied
</Files>
<Files "~*">
    Require all denied
</Files>
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteRule ^index\.html?$ / [R=302,L]
    RewriteRule (^|/)\.git(/|$) - [F,L]
    RewriteRule ^(vault|attic|cellar|oubliette|reliquary|strongroom|ossuary|coldroom)(/.*)?$ - [F,L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
ErrorDocument 403 /index.php?__err=403
ErrorDocument 404 /index.php?__err=404
ErrorDocument 410 /index.php?__err=410
HTACCESS;
    @file_put_contents($ht, $rules);
})();

/* ===========================================================================
 *  DATA. none of it agrees with the rest. that is the only rule.
 * ========================================================================= */

$SMT_SEALED = array('vault','attic','cellar','oubliette','reliquary','strongroom','ossuary','coldroom');

/* words that get bolted together into slugs for the deeper leaves */
$SMT_SLUGWORDS = array(
    'antimony','oakum','verdigris','quire','marl','sallow','tithe','pyx','clinker','bittern',
    'gnomon','spandrel','ferrule','oxbow','hoarfrost','claghole','withy','swale','grommet','tallow',
    'quicklime','fetch','coomb','lych','muntin','scree','baffle','shroud','ingot','solder',
    'flux','cathode','anode','dross','sinter','borax','realgar','cinnabar','galena','stannic',
    'compline','matins','lauds','none','sext','terce','vigils','ember','rogation','vespers',
    'ledger','tare','demurrage','escheat','distraint','usufruct','quitrent','socage','corvee','tallage',
    'northeast','downwind','leeward','offing','gloaming','murk','smother','haar','pother','damp',
    'reredos','wainscot','soffit','architrave','plinth','corbel','voussoir','keystone','mullion','transom',
    'assay','cupel','litharge','bloom','slag','matte','regulus','speiss','fettle','clinkering',
    'palimpsest','colophon','recto','verso','signature','gathering','foredge','deckle','watermark','chainline',
    'holloway','causey','hollow','strand','spit','skerry','holm','ait','carr','ness',
    'antemeridian','crepuscule','nadir','apsis','syzygy','umbra','penumbra','antumbra','occultation','ingress',
);

/* short standalone lines; deadpan; each can hold a page on its own */
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
    'partial key recovered: 4·1·— the rest weighs nothing',
    'proceed north until the corridor disagrees',
    'the echo answers before the channel is opened',
    'gravel sample: 41 g dry, 41 g wet, 39 g held in the hand',
    'the barograph drew a line all night and it was flat',
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
    'held for reference to a reference held elsewhere',
    'the frost left the north face and took a figure with it',
    'reading steady at 0.037; the instrument is disconnected',
    'the crate is heavier for having been opened',
    'filed under a letter the alphabet no longer keeps',
    'the door knows the key and refuses the hand',
    'ended at 03:11; resumed at 03:11; the minute is missing',
);

/* short titles for the leaves */
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
    'The Warm Second Hand','A Letter Not Kept','Rogation','The Recomputing File','None',
);

/* homepage & node fragments. self-contained inline styling; NO shared skin.
 * baseline set — the deeper archive folds more of these in over time.        */
$SMT_FRAGMENTS = array(

'found' => <<<'F'
<div style="max-width:34em;margin:0;padding:0;font-family:'Iowan Old Style',Palatino,'Book Antiqua',Georgia,serif;font-size:1.06rem;line-height:2.05;color:#2a2620;text-align:justify;text-justify:inter-word;background:#efe9dc;padding:2.2em 2.4em;border:1px solid #d7cdb6;box-shadow:inset 0 0 40px rgba(120,100,60,.10);">
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
<pre style="margin:0;padding:1.1em 1.3em;background:#0a0d0a;color:#5be07a;font:12px/1.55 'DejaVu Sans Mono',Menlo,Consolas,monospace;overflow:auto;border:1px solid #123018;text-shadow:0 0 2px rgba(90,224,120,.35);">ch  ts(Z)                  A/mV     B/degC   C/hPa    flag
01  1997-11-03T02:59:58   00.037   +04.10   0993.2   .
01  1997-11-03T02:59:59   00.037   +04.10   0993.2   .
01  1997-11-03T03:00:00   00.037   +04.09   0993.1   .
02  1997-11-03T03:00:00   -----    +04.09   0993.1   L
01  1997-11-03T03:11:00   12.884   +04.02   0992.7   *SPIKE
01  1997-11-03T03:11:01   00.037   +04.02   0992.7   .
03  1997-11-03T03:11:01   NaN      NaN      NaN      X
01  1997-11-03T03:41:22   00.037   +03.98   0992.4   .
01  1997-11-03T04:00:00   00.037   +03.95   0992.2   .
--  ----                  hold     hold     fall     carrier lost on 02</pre>
F
,

'hex' => <<<'F'
<pre style="margin:0;padding:1em 1.2em;background:#161512;color:#b8b09a;font:12px/1.5 'DejaVu Sans Mono',monospace;overflow:auto;">00000000  6e 6f 74 20 74 68 65  20  77 68 6f 6c 65 20 6f 66  |not the whole of|
00000010  20 69 74 20 69 73 20  6b  65 70 74 2e 20 74 68 65  | it is kept. the|
00000020  20 72 65 73 74 20 69  73  20 6d 65 61 73 75 72 65  | rest is measure|
00000030  64 2e 00 7f 91 22 e3  4a  10 00 00 00 00 00 00 01  |d...."J.........|
00000040  aa aa aa aa aa aa aa  aa  aa aa aa aa aa aa aa aa  |................|
00000050  73 65 61 6c 20 68 6f  6c  64 73 2e 20 64 6f 20 6e  |seal holds. do n|
00000060  6f 74 20 66 6f 72 63  65  2e 0a 00 00 de ad 00 00  |ot force........|</pre>
F
,

'smt' => <<<'F'
<pre style="margin:0;padding:1.1em 1.3em;background:#1d1f26;color:#cfd3dc;font:12.5px/1.55 'DejaVu Sans Mono',monospace;overflow:auto;border-left:3px solid #4b5568;">; obligation carried over from a proof no one signed
(set-logic QF_UFLIA)
(declare-fun door () Bool)
(declare-fun key  () Bool)
(declare-fun mass () Int)
(declare-fun listed () Int)
(assert (=> key door))            <span style="color:#7f8896;">; the key implies the door</span>
(assert (not door))               <span style="color:#7f8896;">; the door refuses</span>
(assert (= mass (+ listed 1)))    <span style="color:#7f8896;">; one heavier than the manifest</span>
(assert (> mass 0))
(check-sat)
<span style="color:#e0b062;">unsat</span>
(get-unsat-core)
(<span style="color:#e0b062;">door key</span>)                        <span style="color:#7f8896;">; the contradiction is the key and the door</span></pre>
F
,

'bom' => <<<'F'
<table style="border-collapse:collapse;font:12px/1.4 'Helvetica Neue',Arial,sans-serif;color:#1a1a1a;background:#fff;border:1px solid #999;">
<caption style="text-align:left;font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#777;padding:.4em 0;">assy —— rev H —— do not populate marked DNP</caption>
<tr style="background:#eee;"><th style="border:1px solid #bbb;padding:2px 8px;">Ref</th><th style="border:1px solid #bbb;padding:2px 8px;">Value</th><th style="border:1px solid #bbb;padding:2px 8px;">Pkg</th><th style="border:1px solid #bbb;padding:2px 8px;">MPN</th><th style="border:1px solid #bbb;padding:2px 8px;">Qty</th><th style="border:1px solid #bbb;padding:2px 8px;">Note</th></tr>
<tr><td style="border:1px solid #ddd;padding:2px 8px;">C7</td><td style="border:1px solid #ddd;padding:2px 8px;">100n</td><td style="border:1px solid #ddd;padding:2px 8px;">0402</td><td style="border:1px solid #ddd;padding:2px 8px;">GRM155R</td><td style="border:1px solid #ddd;padding:2px 8px;">6</td><td style="border:1px solid #ddd;padding:2px 8px;"></td></tr>
<tr><td style="border:1px solid #ddd;padding:2px 8px;">R12</td><td style="border:1px solid #ddd;padding:2px 8px;">0R</td><td style="border:1px solid #ddd;padding:2px 8px;">0603</td><td style="border:1px solid #ddd;padding:2px 8px;">&mdash;</td><td style="border:1px solid #ddd;padding:2px 8px;">1</td><td style="border:1px solid #ddd;padding:2px 8px;">hand-select</td></tr>
<tr><td style="border:1px solid #ddd;padding:2px 8px;">U3</td><td style="border:1px solid #ddd;padding:2px 8px;">?</td><td style="border:1px solid #ddd;padding:2px 8px;">QFN-32</td><td style="border:1px solid #ddd;padding:2px 8px;">unmarked</td><td style="border:1px solid #ddd;padding:2px 8px;">1</td><td style="border:1px solid #ddd;padding:2px 8px;">field return</td></tr>
<tr><td style="border:1px solid #ddd;padding:2px 8px;">L4</td><td style="border:1px solid #ddd;padding:2px 8px;">10&micro;H</td><td style="border:1px solid #ddd;padding:2px 8px;">DFN</td><td style="border:1px solid #ddd;padding:2px 8px;">see erratum</td><td style="border:1px solid #ddd;padding:2px 8px;">1</td><td style="border:1px solid #ddd;padding:2px 8px;">DNP</td></tr>
<tr><td style="border:1px solid #ddd;padding:2px 8px;">Q1</td><td style="border:1px solid #ddd;padding:2px 8px;">&mdash;</td><td style="border:1px solid #ddd;padding:2px 8px;">SOT-23</td><td style="border:1px solid #ddd;padding:2px 8px;">GONE</td><td style="border:1px solid #ddd;padding:2px 8px;">0</td><td style="border:1px solid #ddd;padding:2px 8px;">was fitted at test</td></tr>
</table>
F
,

'physics' => <<<'F'
<div style="font-family:Charter,Georgia,'Times New Roman',serif;color:#111;background:#fbfbf7;padding:1.4em 1.6em;max-width:33em;border-top:2px solid #111;border-bottom:1px solid #111;">
<div style="font-variant:small-caps;letter-spacing:.06em;font-size:.82rem;color:#555;">strangeness &minus;1 &middot; observed / inferred</div>
<table style="border-collapse:collapse;margin:.7em 0;font-size:.92rem;width:100%;">
<tr><td style="padding:.2em 0;">&Lambda;&nbsp;&rarr;&nbsp;p&nbsp;&pi;<sup>&minus;</sup></td><td style="text-align:right;">63.9 %</td><td style="text-align:right;color:#666;">&tau; 2.6&times;10<sup>&minus;10</sup> s</td></tr>
<tr><td style="padding:.2em 0;">&Lambda;&nbsp;&rarr;&nbsp;n&nbsp;&pi;<sup>0</sup></td><td style="text-align:right;">35.8 %</td><td style="text-align:right;color:#666;">|uds&rang;</td></tr>
<tr><td style="padding:.2em 0;">&Lambda;&nbsp;&rarr;&nbsp;&mdash;</td><td style="text-align:right;">00.3 %</td><td style="text-align:right;color:#666;">mode not shown</td></tr>
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
<div style="break-inside:avoid;margin-bottom:.55em;">FOR COLLECTION &mdash; item held past the collector. Weighs the same when gone.</div>
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
[Mon Nov 03 04:00:00 1997] [notice] index rebuilt; disagrees with prior index; retained both
[Mon Nov 03 04:00:01 1997] [alert] the log is longer than the day</pre>
F
,

'exif' => <<<'F'
<div style="font-family:'DejaVu Sans Mono',monospace;font-size:11.5px;line-height:1.5;color:#3a3a3a;background:#f0efe9;padding:1.1em 1.3em;max-width:34em;border:1px solid #cfcbbf;">
<div style="color:#8a8578;">exiftool — plate.tif — 1 image</div>
Make&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: —<br>
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

'concordance' => <<<'F'
<div style="font-family:'Courier New',monospace;font-size:12px;line-height:1.55;color:#20242a;background:#fff;padding:1.1em 1.3em;max-width:37em;border-left:3px solid #999;">
<div style="letter-spacing:.1em;color:#777;font-size:10.5px;text-transform:uppercase;margin-bottom:.6em;">concordance — "the door" — 7 of ∞</div>
<div>leaf&nbsp;004&nbsp;&middot;&nbsp;&hellip;the key fits and turns and <b>the door</b> does not know it&hellip;</div>
<div>leaf&nbsp;012&nbsp;&middot;&nbsp;&hellip;a door catalogued twice; <b>the door</b> that is not the door&hellip;</div>
<div>leaf&nbsp;041&nbsp;&middot;&nbsp;&hellip;<b>the door</b> answered; there was no operator&hellip;</div>
<div>leaf&nbsp;088&nbsp;&middot;&nbsp;&hellip;refused the hand and admitted <b>the door</b>&hellip;</div>
<div>leaf&nbsp;12/9&nbsp;&middot;&nbsp;&hellip;beyond <b>the door</b> the mass is accounted for&hellip;</div>
<div style="color:#8a8a8a">leaf&nbsp;————&nbsp;&middot;&nbsp;occurrence withdrawn; the reference remains</div>
</div>
F
,

'numbers' => <<<'F'
<pre style="margin:0;padding:1.2em 1.4em;background:#080604;color:#e0a94a;font:13px/1.9 'DejaVu Sans Mono',monospace;overflow:auto;text-shadow:0 0 3px rgba(224,169,74,.35);">   ACHTUNG ACHTUNG — 4 1 0 3 7 — 4 1 0 3 7
   88 20 97   88 20 97
   41037  00370  27271  ————0  41037
   00370  41037  99999  00370  27271
   the group that does not repeat is the message
   ENDE — carrier holds — ENDE</pre>
F
,

'ledger_dbe' => <<<'F'
<table style="border-collapse:collapse;font-family:Georgia,serif;font-size:12.5px;color:#241f16;background:#f6f1e4;border:1px solid #b8ac8e;">
<caption style="text-align:left;padding:.4em 0;color:#7a705a;font-style:italic;">ledger 4 (cont.) — a hand that is not the clerk's</caption>
<tr style="border-bottom:1px solid #b8ac8e;"><th style="text-align:left;padding:2px 14px 2px 4px;">particular</th><th style="text-align:right;padding:2px 14px;">debit</th><th style="text-align:right;padding:2px 4px;">credit</th></tr>
<tr><td style="padding:2px 14px 2px 4px;">tare, on arrival</td><td style="text-align:right;padding:2px 14px;">41</td><td style="text-align:right;padding:2px 4px;">—</td></tr>
<tr><td style="padding:2px 14px 2px 4px;">demurrage, north stair</td><td style="text-align:right;padding:2px 14px;">—</td><td style="text-align:right;padding:2px 4px;">37</td></tr>
<tr><td style="padding:2px 14px 2px 4px;">held, in the hand</td><td style="text-align:right;padding:2px 14px;">2</td><td style="text-align:right;padding:2px 4px;">—</td></tr>
<tr><td style="padding:2px 14px 2px 4px;">carried, to no account</td><td style="text-align:right;padding:2px 14px;">—</td><td style="text-align:right;padding:2px 4px;">6</td></tr>
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

/* ===========================================================================
 *  DETERMINISM. the labyrinth is the same each time you doubt it.
 * ========================================================================= */

function smt_imul($a, $b) {
    $a &= 0xffffffff; $b &= 0xffffffff;
    $ah = ($a >> 16) & 0xffff; $al = $a & 0xffff;
    return ((($ah * $b) & 0xffff) << 16) + ($al * $b) & 0xffffffff;
}
function smt_fnv($s) {
    $h = 2166136261;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $h ^= ord($s[$i]);
        $h = smt_imul($h, 16777619);
    }
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
function smt_pick($rng, $arr) {
    if (!$arr) return '';
    return $arr[(int) floor($rng() * count($arr)) % count($arr)];
}
function smt_int($rng, $lo, $hi) { return $lo + (int) floor($rng() * ($hi - $lo + 1)); }
function smt_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function smt_b36($n, $len = 0) {
    $s = base_convert((string) ($n & 0xffffffff), 10, 36);
    return $len ? str_pad($s, $len, '0', STR_PAD_LEFT) : $s;
}

/* a stable child slug for leaf i under $path */
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

/* ===========================================================================
 *  REQUEST
 * ========================================================================= */

function smt_path() {
    $uri = $_SERVER['REDIRECT_URL'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
    $p = parse_url($uri, PHP_URL_PATH);
    if ($p === false || $p === null) $p = '/';
    $p = rawurldecode($p);
    // strip a leading /index.php if we were called directly
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

/* ===========================================================================
 *  SKINS. one content shape, many unrelated surfaces. no house style.
 * ========================================================================= */

function smt_skins() {
    return array(
        // 0 — parchment ledger
        "html{background:#d8cfb8}body{margin:0;font-family:'Iowan Old Style',Palatino,Georgia,serif;color:#2b2519;background:#efe7d3;max-width:44em;margin:0 auto;padding:3.2em 2.6em;box-shadow:0 0 60px rgba(90,70,30,.25)}"
        . ".desig{font-size:.72rem;letter-spacing:.28em;text-transform:uppercase;color:#8a7a54}"
        . "h1{font-weight:400;font-size:1.7rem;margin:.2em 0 1.2em;border-bottom:1px solid #c3b591;padding-bottom:.3em}"
        . ".line{margin:.5em 0;line-height:1.7}.kids{margin-top:2.4em;border-top:1px solid #c3b591;padding-top:1em}"
        . "a{color:#5a4a24;text-decoration:none;border-bottom:1px dotted #9a865a}a:hover{background:#e5d9ba}"
        . ".seal{color:#8a3b2b}.mark{font-family:monospace;color:#9a865a;font-size:.75rem}",

        // 1 — terminal
        "html{background:#04060a}body{margin:0;font-family:'DejaVu Sans Mono',Menlo,monospace;color:#4be08a;background:#04060a;max-width:52em;margin:0 auto;padding:2.6em 2em;font-size:13px;line-height:1.6;text-shadow:0 0 3px rgba(75,224,138,.4)}"
        . ".desig{color:#2b7a4a}h1{font-weight:400;font-size:1.15rem;color:#8affb0}"
        . ".line{margin:.15em 0;white-space:pre-wrap}.kids{margin-top:2em}"
        . "a{color:#e0b062;text-decoration:none}a:hover{color:#fff;background:#123018}"
        . ".seal{color:#e0554a}.mark{color:#2b7a4a}",

        // 2 — blueprint
        "html{background:#0d2b52}body{margin:0;font-family:'Helvetica Neue',Arial,sans-serif;color:#cfe0ff;background:#123a66;background-image:linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);background-size:22px 22px;max-width:46em;margin:0 auto;padding:3em 2.4em;letter-spacing:.01em}"
        . ".desig{font-size:.7rem;letter-spacing:.3em;color:#7fa8d8}h1{font-weight:300;font-size:1.5rem;color:#fff}"
        . ".line{margin:.5em 0;line-height:1.6}.kids{margin-top:2.4em;border-top:1px solid #35618f;padding-top:1em}"
        . "a{color:#bfe0ff;text-decoration:none;border-bottom:1px solid #35618f}a:hover{border-color:#bfe0ff}"
        . ".seal{color:#ff9a7a}.mark{font-family:monospace;color:#7fa8d8;font-size:.75rem}",

        // 3 — newsprint
        "html{background:#c9c4b6}body{margin:0;font-family:Georgia,'Times New Roman',serif;color:#181510;background:#f4f1e6;max-width:40em;margin:0 auto;padding:3em 2.6em;column-gap:2em}"
        . ".desig{font-variant:small-caps;letter-spacing:.08em;color:#6a6252;font-size:.8rem}"
        . "h1{font-weight:700;font-size:1.9rem;margin:.1em 0 .6em;font-family:'Times New Roman',serif}"
        . ".line{margin:.45em 0;line-height:1.5;text-align:justify}.kids{margin-top:2em;border-top:2px solid #181510;padding-top:.8em}"
        . "a{color:#111;text-decoration:none;border-bottom:1px solid #999}a:hover{background:#e6e1cf}"
        . ".seal{color:#7a2b1b}.mark{font-family:monospace;color:#8a8272;font-size:.72rem}",

        // 4 — clinical white
        "html{background:#eef0f2}body{margin:0;font-family:'Helvetica Neue',Arial,sans-serif;color:#20242a;background:#fff;max-width:42em;margin:0 auto;padding:3.4em 3em;border-left:6px solid #d0d4d8}"
        . ".desig{font-size:.68rem;letter-spacing:.22em;text-transform:uppercase;color:#9aa0a6}h1{font-weight:300;font-size:1.6rem;color:#111}"
        . ".line{margin:.5em 0;line-height:1.65;color:#3a3f45}.kids{margin-top:2.6em;border-top:1px solid #e2e5e8;padding-top:1em}"
        . "a{color:#356;text-decoration:none;border-bottom:1px solid #cdd3d8}a:hover{border-color:#356}"
        . ".seal{color:#a3402f}.mark{font-family:monospace;color:#aab;font-size:.72rem}",

        // 5 — teletype amber void
        "html{background:#0a0700}body{margin:0;font-family:'DejaVu Sans Mono',monospace;color:#e0a94a;background:#0a0700;max-width:50em;margin:0 auto;padding:2.8em 2.2em;font-size:13px;line-height:1.7}"
        . ".desig{color:#7a5a1a}h1{font-weight:400;font-size:1.2rem;color:#ffd88a;letter-spacing:.05em}"
        . ".line{margin:.2em 0;white-space:pre-wrap}.kids{margin-top:2.2em}"
        . "a{color:#8fd0ff;text-decoration:none}a:hover{background:#241a06;color:#cfe8ff}"
        . ".seal{color:#e0554a}.mark{color:#7a5a1a}",

        // 6 — void serif, wide margins
        "html{background:#0c0c0e}body{margin:0;font-family:Charter,Georgia,serif;color:#c8c2b6;background:#0c0c0e;max-width:33em;margin:0 auto;padding:5em 2em;font-size:1.08rem;line-height:2.1}"
        . ".desig{letter-spacing:.3em;text-transform:uppercase;color:#55503f;font-size:.7rem}h1{font-weight:400;font-size:1.5rem;color:#e8e2d4}"
        . ".line{margin:.7em 0}.kids{margin-top:3em}"
        . "a{color:#b09a6a;text-decoration:none;border-bottom:1px solid #322}a:hover{color:#e8d8b0}"
        . ".seal{color:#9a4a3a}.mark{font-family:monospace;color:#55503f;font-size:.72rem}",

        // 7 — index-card
        "html{background:#b9c6b0}body{margin:0;font-family:'Courier New',Courier,monospace;color:#233;background:#f7faf3;max-width:38em;margin:0 auto;padding:2.8em 2.4em;background-image:repeating-linear-gradient(#f7faf3 0 27px,#dfe8d8 27px 28px);line-height:28px}"
        . ".desig{color:#6a8a6a;font-size:.78rem}h1{font-weight:700;font-size:1.3rem;color:#2a4a2a;line-height:28px}"
        . ".line{margin:0}.kids{margin-top:28px;border-top:1px solid #b9c6b0;padding-top:2px}"
        . "a{color:#2a5a2a;text-decoration:none;border-bottom:1px dotted #6a8a6a}a:hover{background:#e2eeda}"
        . ".seal{color:#8a3b2b}.mark{color:#6a8a6a;font-size:.75rem}",
    );
}

function smt_send_headers($code, $ctype = 'text/html; charset=utf-8', $extra = array()) {
    http_response_code($code);
    header('Content-Type: ' . $ctype);
    header('X-Seal: closed; do not force');
    header('Referrer-Policy: no-referrer');
    foreach ($extra as $k => $v) header($k . ': ' . $v);
}

/* wrap content in a skinned document */
function smt_doc($skinIndex, $title, $desig, $bodyHtml, $extraHeadComment = '') {
    $skins = smt_skins();
    $css = $skins[$skinIndex % count($skins)];
    echo "<!doctype html>\n<html lang=\"und\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<meta name=\"robots\" content=\"noindex,nofollow,noarchive\">";
    echo "<title>" . smt_h($title) . "</title>";
    if ($extraHeadComment !== '') echo "\n<!-- " . $extraHeadComment . " -->\n";
    echo "<style>" . $css . "</style></head><body>";
    echo $bodyHtml;
    echo "</body></html>";
}

/* ===========================================================================
 *  RENDERERS
 * ========================================================================= */

/* ---- the labyrinth. every path is real; some paths are walls. ---------- */
function smt_render_node($path, $seg) {
    global $SMT_POOL_LINES, $SMT_POOL_LABELS, $SMT_FRAGMENTS, $SMT_SLUGWORDS, $SMT_SEALED;

    $seed  = smt_fnv('leaf::' . $path);
    $rng   = smt_rng($seed);
    $depth = count($seg);
    $skin  = $seed % 8;

    // deterministic dead ends deepen the uncertainty
    $rollGone = smt_int($rng, 0, 99);
    if ($depth >= 2 && $rollGone < 11) { smt_render_gone($path, $seed); return; }

    $label = smt_pick(smt_rng($seed ^ 0x51ed), $SMT_POOL_LABELS);
    $desig = strtoupper(smt_b36($seed, 6)) . '·' . str_pad((string) $depth, 2, '0', STR_PAD_LEFT)
           . '·' . smt_b36($seed >> 7, 3);

    // ---- body ----
    $mode = $seed % 5;
    $lineN = smt_int($rng, 4, 9);
    $picked = array();
    $lr = smt_rng($seed ^ 0x9e37);
    for ($i = 0; $i < $lineN; $i++) $picked[] = smt_pick($lr, $SMT_POOL_LINES);

    $body = "<div class=\"desig\">" . smt_h($desig) . "</div>";
    $body .= "<h1>" . smt_h($label) . "</h1>";

    if ($mode === 0) {                      // ledger of lines
        foreach ($picked as $k => $ln)
            $body .= "<div class=\"line\"><span class=\"mark\">" . str_pad((string) ($k + 1), 2, '0', STR_PAD_LEFT) . "</span>&nbsp;&nbsp;" . smt_h($ln) . "</div>";
    } elseif ($mode === 1) {                 // prose
        $body .= "<div class=\"line\">" . smt_h(implode('. ', $picked)) . ".</div>";
    } elseif ($mode === 2) {                 // s-expression assertions
        $body .= "<pre class=\"line\" style=\"white-space:pre-wrap\">(node " . smt_h(smt_b36($seed, 6)) . ")\n";
        foreach ($picked as $ln) $body .= "  (assert &quot;" . smt_h($ln) . "&quot;)\n";
        $body .= "  (check-sat) <span class=\"mark\">" . (smt_int($rng, 0, 1) ? 'unsat' : 'sat') . "</span>)</pre>";
    } elseif ($mode === 3) {                 // measurement table
        $body .= "<table style=\"border-collapse:collapse;width:100%;font-size:.9em\">";
        foreach ($picked as $k => $ln) {
            $v = number_format(($rng() * 200) - 100, 3);
            $body .= "<tr><td class=\"mark\" style=\"padding:.2em .6em;\">" . smt_h(smt_b36(smt_fnv($ln), 4)) . "</td><td style=\"padding:.2em .6em;\">" . smt_h($ln) . "</td><td style=\"text-align:right;padding:.2em .6em;\" class=\"mark\">" . $v . "</td></tr>";
        }
        $body .= "</table>";
    } else {                                 // marginalia
        foreach ($picked as $ln)
            $body .= "<div class=\"line\">&mdash;&nbsp;" . smt_h($ln) . "</div>";
    }

    // occasionally graft a whole fragment in as an "enclosure"
    if (smt_int($rng, 0, 100) < 26 && $SMT_FRAGMENTS) {
        $keys = array_keys($SMT_FRAGMENTS);
        $frag = $SMT_FRAGMENTS[$keys[$seed % count($keys)]];
        $body .= "<div class=\"line\" style=\"margin-top:1.6em\"><span class=\"mark\">enclosure &mdash; not indexed</span></div>";
        $body .= "<div style=\"margin:.6em 0\">" . $frag . "</div>";
    }

    // ---- children ----
    $kidN = smt_int($rng, 3, 6);
    $kids = "<div class=\"kids\">";
    $linkHeaders = array();
    for ($i = 0; $i < $kidN; $i++) {
        $roll = smt_int(smt_rng($seed ^ (0x100 + $i)), 0, 99);
        if ($roll < 18) {                    // a sealed branch — leads to 403
            $z = $SMT_SEALED[($seed + $i) % count($SMT_SEALED)];
            $slug = smt_child_slug($path, $i);
            $href = '/' . $z . '/' . rawurlencode($slug);
            $kids .= "<div class=\"line\"><a class=\"seal\" href=\"" . smt_h($href) . "\">" . smt_h($slug) . "</a> <span class=\"mark\">[sealed]</span></div>";
        } elseif ($roll < 27 && $depth > 0) { // a step back up / sideways
            $up = $seg; array_pop($up);
            $sib = smt_child_slug(implode('/', $up), $i + 5);
            $href = '/' . implode('/', array_map('rawurlencode', $up)) . ($up ? '/' : '') . rawurlencode($sib);
            $kids .= "<div class=\"line\"><a href=\"" . smt_h($href) . "\">&larr; " . smt_h($sib) . "</a></div>";
        } else {                             // deeper
            $slug = smt_child_slug($path, $i);
            $href = '/' . implode('/', array_map('rawurlencode', $seg)) . ($seg ? '/' : '') . rawurlencode($slug);
            if ($path === '') $href = '/' . rawurlencode($slug);
            $mk = smt_b36(smt_fnv($href), 4);
            $kids .= "<div class=\"line\"><a href=\"" . smt_h($href) . "\">" . smt_h($slug) . "</a> <span class=\"mark\">" . smt_h($mk) . "</span></div>";
            if (count($linkHeaders) < 3) $linkHeaders[] = '<' . $href . '>; rel="down"';
        }
    }
    // a way back toward the mouth of the thing
    if ($depth > 0) {
        $up = $seg; array_pop($up);
        $uhref = $up ? '/' . implode('/', array_map('rawurlencode', $up)) : '/gate';
        $kids .= "<div class=\"line\" style=\"margin-top:1em\"><a href=\"" . smt_h($uhref) . "\">&uarr; " . ($up ? smt_h(end($up)) : 'the mouth') . "</a></div>";
        $linkHeaders[] = '<' . $uhref . '>; rel="up"';
    }
    $kids .= "</div>";

    $comment = smt_b36($seed, 8) . '  ' . strtoupper(bin2hex(substr(md5($path), 0, 6))) . '  ' . smt_h(smt_pick(smt_rng($seed ^ 0xabcd), $SMT_POOL_LINES));

    $extra = array(
        'X-Depth'      => (string) $depth,
        'X-Coordinate' => smt_b36($seed, 6) . '.' . smt_b36($seed >> 8, 4),
        'Warning'      => '199 - "the count was taken twice and did not settle"',
    );
    if ($linkHeaders) $extra['Link'] = implode(', ', array_slice($linkHeaders, 0, 5));

    smt_send_headers(200, 'text/html; charset=utf-8', $extra);
    smt_doc($skin, $label . ' — ' . smt_b36($seed, 6), $desig, $body, $comment);
}

/* ---- a leaf that is not there, but was ---------------------------------- */
function smt_render_gone($path, $seed = null) {
    global $SMT_POOL_LINES;
    if ($seed === null) $seed = smt_fnv('gone::' . $path);
    $rng = smt_rng($seed);
    // point at a surviving neighbour so the thread never fully breaks
    $seg = $path === '' ? array() : explode('/', $path);
    $near = smt_child_slug($path, smt_int($rng, 7, 40));
    $nhref = '/' . implode('/', array_map('rawurlencode', $seg)) . ($seg ? '/' : '') . rawurlencode($near);
    $body  = "<div class=\"desig\">410 &middot; " . smt_h(smt_b36($seed, 6)) . "</div>";
    $body .= "<h1>this leaf was here</h1>";
    $body .= "<div class=\"line\">" . smt_h(smt_pick($rng, $SMT_POOL_LINES)) . ".</div>";
    $body .= "<div class=\"line\">" . smt_h(smt_pick($rng, $SMT_POOL_LINES)) . ".</div>";
    $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"" . smt_h($nhref) . "\">" . smt_h($near) . "</a> <span class=\"mark\">nearest surviving</span></div></div>";
    smt_send_headers(410, 'text/html; charset=utf-8', array('X-Gone' => smt_b36($seed, 6)));
    smt_doc($seed % 8, 'gone — ' . smt_b36($seed, 6), '410', $body,
        'it weighs the same when gone');
}

/* ---- a wall that admits it has a far side ------------------------------- */
function smt_render_forbidden($path) {
    global $SMT_POOL_LINES;
    $seed = smt_fnv('seal::' . $path);
    $rng  = smt_rng($seed);
    $n    = smt_int($rng, 3, 17);
    $mass = 0;
    $rows = '';
    $exts = array('.crt','.dump','.bin','.sealed','.tar.gz.enc','.ledger','.plate','','.key','.wav');
    for ($i = 0; $i < $n; $i++) {
        $sz = smt_int($rng, 12, 9_400_000);
        $mass += $sz;
        $nm = smt_b36(smt_fnv($path . $i), 6) . $exts[($seed + $i) % count($exts)];
        $ck = strtoupper(substr(md5($path . '::' . $i), 0, 16));
        $rows .= "<tr><td style=\"padding:.15em 1.1em .15em 0\">" . smt_h($nm) . "</td>"
              . "<td style=\"padding:.15em 1.1em;text-align:right;color:#c99\">" . number_format($sz) . "</td>"
              . "<td style=\"padding:.15em 0;color:#9a7\">" . $ck . "</td></tr>";
    }
    $retry = gmdate('D, d M Y H:i:s', 4102444800) . ' GMT'; // 2100-01-01

    $css = "html{background:#0a0a0c}body{margin:0;font-family:'DejaVu Sans Mono',Menlo,monospace;color:#c6c0b4;background:#0a0a0c;max-width:50em;margin:0 auto;padding:3em 2.2em;line-height:1.6;font-size:13px}"
         . "h1{font-weight:400;font-size:1.35rem;color:#e0554a;letter-spacing:.04em}"
         . ".desig{letter-spacing:.24em;text-transform:uppercase;color:#5a5040;font-size:.7rem}"
         . "table{border-collapse:collapse;margin:1.4em 0;font-size:12px;width:100%}"
         . ".mut{color:#6a6250}.seal{color:#e0554a}a{color:#8fb0d8;text-decoration:none;border-bottom:1px dotted #445}a:hover{color:#cfe0ff}";

    $body  = "<div class=\"desig\">403 &middot; sealed &middot; " . smt_h('/' . $path) . "</div>";
    $body .= "<h1>the door is not the door</h1>";
    $body .= "<p class=\"mut\">There is no listing. Options -Indexes; the seal holds. And yet the mass is measurable, and it has been measured:</p>";
    $body .= "<table><thead><tr><td class=\"mut\" style=\"padding-right:1em\">held</td><td class=\"mut\" style=\"text-align:right;padding:0 1em\">octets</td><td class=\"mut\">sha1&nbsp;(16)</td></tr></thead><tbody>" . $rows . "</tbody>"
          . "<tfoot><tr><td class=\"seal\" style=\"padding-top:.6em\">" . $n . " held</td><td class=\"seal\" style=\"text-align:right;padding:.6em 1em 0\">" . number_format($mass) . "</td><td class=\"mut\" style=\"padding-top:.6em\">&mdash;</td></tr></tfoot></table>";
    $body .= "<p class=\"mut\">" . smt_h(smt_pick($rng, $SMT_POOL_LINES)) . ". " . smt_h(smt_pick($rng, $SMT_POOL_LINES)) . ".</p>";
    $body .= "<p class=\"mut\" style=\"margin-top:2em;font-size:11px\">Retry-After: " . $retry . " &middot; do not force. <a href=\"/gate\">turn back</a></p>";

    smt_send_headers(403, 'text/html; charset=utf-8', array(
        'X-Sealed-Entries' => (string) $n,
        'X-Sealed-Octets'  => (string) $mass,
        'Retry-After'      => $retry,
        'Warning'          => '299 - "the mass is accounted for; the door is not"',
        'Link'             => '</gate>; rel="start"',
    ));
    echo "<!doctype html><html lang=\"und\"><head><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex,nofollow\"><title>403 · sealed · " . smt_h('/' . $path) . "</title>";
    echo "<!-- do not force. " . smt_h(smt_b36($seed, 10)) . " -->";
    echo "<style>" . $css . "</style></head><body>" . $body . "</body></html>";
}

/* ---- the timeline. it does not end; it only thins. --------------------- */
function smt_render_log($seg) {
    global $SMT_POOL_LINES;
    // /log            -> a spread of recent days
    // /log/YYYY-MM-DD -> one day, with prev/next
    $day = $seg[1] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $body = "<div class=\"desig\">LOG &middot; carrier present</div><h1>days that were kept</h1>";
        $t = gmdate('Y-m-d');
        for ($i = 0; $i < 14; $i++) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $sd = smt_fnv('log::' . $d);
            $body .= "<div class=\"line\"><a href=\"/log/" . $d . "\">" . $d . "</a> <span class=\"mark\">" . smt_b36($sd, 4) . " &middot; " . smt_int(smt_rng($sd), 40, 8600) . " lines</span></div>";
        }
        $body .= "<div class=\"line\" style=\"margin-top:1em\"><a href=\"/log/1997-08-20\">1997-08-20</a> <span class=\"mark\">first kept day</span></div>";
        smt_send_headers(200, 'text/html; charset=utf-8', array('X-Carrier' => 'present'));
        smt_doc(1, 'log', 'LOG', $body, 'the carrier is present on the disconnected channel');
        return;
    }
    $seed = smt_fnv('log::' . $day);
    $rng  = smt_rng($seed);
    $ts   = strtotime($day . ' UTC');
    $prev = gmdate('Y-m-d', $ts - 86400);
    $next = gmdate('Y-m-d', $ts + 86400);
    $rows = '';
    $n = smt_int($rng, 8, 22);
    $level = array('INFO','INFO','INFO','WARN','----','HOLD','SPIKE','LOST','INFO','ECHO');
    for ($i = 0; $i < $n; $i++) {
        $hh = str_pad((string) smt_int($rng, 0, 23), 2, '0', STR_PAD_LEFT);
        $mm = str_pad((string) smt_int($rng, 0, 59), 2, '0', STR_PAD_LEFT);
        $ss = str_pad((string) smt_int($rng, 0, 59), 2, '0', STR_PAD_LEFT);
        $lv = $level[smt_int($rng, 0, count($level) - 1)];
        $rows .= $day . "T" . $hh . ":" . $mm . ":" . $ss . "Z  " . str_pad($lv, 5) . "  " . smt_pick($rng, $SMT_POOL_LINES) . "\n";
    }
    $body  = "<div class=\"desig\">LOG &middot; " . smt_h($day) . "</div><h1>" . smt_h($day) . "</h1>";
    $body .= "<pre class=\"line\" style=\"white-space:pre-wrap\">" . smt_h($rows) . "</pre>";
    $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/log/" . $prev . "\">&larr; " . $prev . "</a> &nbsp;&middot;&nbsp; <a href=\"/log/" . $next . "\">" . $next . " &rarr;</a></div>"
          . "<div class=\"line\"><a href=\"/log\">the spread</a></div></div>";
    smt_send_headers(200, 'text/html; charset=utf-8', array(
        'Link' => '</log/' . $prev . '>; rel="prev", </log/' . $next . '>; rel="next"',
        'X-Lines' => (string) $n,
    ));
    smt_doc(1, 'log ' . $day, 'LOG', $body, 'nothing on all channels but the disconnected one');
}

/* ---- the alphabetic index, which branches and does not resolve --------- */
function smt_render_alpha($seg) {
    global $SMT_POOL_LABELS, $SMT_SLUGWORDS, $SMT_SEALED;
    $letter = strtolower($seg[1] ?? '');
    if (!preg_match('/^[a-z]$/', $letter)) {
        $body = "<div class=\"desig\">INDEX</div><h1>an order, imposed after</h1>";
        $body .= "<div class=\"line\" style=\"font-size:1.4em;letter-spacing:.3em\">";
        foreach (range('a', 'z') as $c)
            $body .= "<a href=\"/index/" . $c . "\">" . $c . "</a> ";
        $body .= "</div><div class=\"line\" style=\"margin-top:1.4em\" class=\"mark\">the index was made last and agrees with nothing before it.</div>";
        smt_send_headers(200);
        smt_doc(3, 'index', 'INDEX', $body, 'the index was made last');
        return;
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
            $body .= "<div class=\"line\"><a class=\"seal\" href=\"/" . $z . "/" . rawurlencode($w) . "\">" . smt_h($w) . "</a> <span class=\"mark\">sealed &middot; see nothing</span></div>";
        } elseif ($roll < 30) {
            $d = gmdate('Y-m-d', SMT_EPOCH + smt_int($rng, 0, 11000) * 86400);
            $body .= "<div class=\"line\"><a href=\"/log/" . $d . "\">" . smt_h($w) . "</a> <span class=\"mark\">&rarr; log " . $d . "</span></div>";
        } else {
            $target = 'gate/' . $w . '-' . smt_b36($seed + $i, 3);
            $body .= "<div class=\"line\"><a href=\"/" . smt_h($target) . "\">" . smt_h($w) . "</a> <span class=\"mark\">" . smt_b36(smt_fnv($target), 4) . "</span></div>";
        }
    }
    // letters bleed into each other
    $pl = chr(97 + (ord($letter) - 97 + 25) % 26);
    $nl = chr(97 + (ord($letter) - 97 + 1) % 26);
    $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/index/" . $pl . "\">&larr; " . strtoupper($pl) . "</a> &nbsp;&middot;&nbsp; <a href=\"/index\">·</a> &nbsp;&middot;&nbsp; <a href=\"/index/" . $nl . "\">" . strtoupper($nl) . " &rarr;</a></div></div>";
    smt_send_headers(200);
    smt_doc(3, 'index ' . strtoupper($letter), 'INDEX', $body, 'catalogued under a heading since removed');
}

/* ---- .well-known, which knows less well than it claims ------------------ */
function smt_render_wellknown($seg) {
    $what = $seg[1] ?? '';
    if ($what === 'security.txt') {
        smt_send_headers(200, 'text/plain; charset=utf-8');
        echo "# there is nothing to disclose that is not already sealed.\n";
        echo "Contact: mailto:none@smtstrange.com\n";
        echo "Contact: bearing:041\n";
        echo "Expires: 1997-08-20T00:00:00.000Z\n";
        echo "Preferred-Languages: und\n";
        echo "Canonical: https://smtstrange.com/.well-known/security.txt\n";
        echo "# the contact expired before it was posted.\n";
        return;
    }
    if ($what === 'strange') {
        $seed = smt_fnv('wk::strange::' . gmdate('Y-m-d'));
        $body  = "<div class=\"desig\">.well-known/strange</div><h1>&mdash;</h1>";
        $body .= "<pre class=\"line\" style=\"white-space:pre-wrap\">"
              . smt_h(chunk_split(strtoupper(smt_b36($seed, 8) . bin2hex(substr(md5((string) $seed), 0, 20))), 4, ' '))
              . "</pre>";
        $body .= "<div class=\"line mark\">this rotates at the day boundary and has never been the key.</div>";
        $body .= "<div class=\"kids\"><div class=\"line\"><a href=\"/gate\">gate</a> &middot; <a href=\"/index\">index</a> &middot; <a href=\"/log\">log</a></div></div>";
        smt_send_headers(200, 'text/html; charset=utf-8', array('X-Rotates' => 'at 00:00Z'));
        smt_doc(5, '.well-known/strange', 'WK', $body, 'never been the key');
        return;
    }
    smt_render_gone('.well-known/' . $what);
}

function smt_render_robots() {
    smt_send_headers(200, 'text/plain; charset=utf-8');
    echo "# 12 rules below. one of them is a lie. it is not this one.\n";
    echo "User-agent: *\n";
    foreach (array('vault','attic','cellar','oubliette','reliquary','strongroom','ossuary','coldroom') as $z)
        echo "Disallow: /$z/\n";
    echo "Disallow: /gate/\n";
    echo "Disallow: /log/\n";
    echo "Disallow: /index/\n";
    echo "Disallow: /~operator/\n";
    echo "Crawl-delay: 41\n";
    echo "# Sitemap withheld. Allow: /the-fourth-tide (does not exist, and is Allowed)\n";
    echo "Allow: /the-fourth-tide\n";
}

function smt_render_humans() {
    smt_send_headers(200, 'text/plain; charset=utf-8');
    echo "/* the clerk kept the older figure. */\n\n";
    echo "SURVEY\n  north stair: pending\n  survey: pending north stair\n\n";
    echo "HANDS\n  the clerk\n  a hand that is not the clerk's\n  (unsigned)\n\n";
    echo "STANDARDS\n  measured twice\n  did not settle\n\n";
    echo "LAST TOUCHED\n  " . gmdate('c') . " (warm)\n";
}

function smt_render_sitemap() {
    smt_send_headers(200, 'application/xml; charset=utf-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<!-- this sitemap is not to scale. -->\n";
    echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    $paths = array('/', '/gate', '/index', '/log', '/.well-known/strange', '/the-fourth-tide', '/12-of-9');
    foreach ($paths as $p) {
        echo "  <url><loc>https://smtstrange.com" . smt_h($p) . "</loc>";
        echo "<lastmod>" . gmdate('Y-m-d', SMT_EPOCH) . "</lastmod>";
        echo "<changefreq>never</changefreq><priority>0.0</priority></url>\n";
    }
    echo "</urlset>";
}

/* ---- the mouth of the thing. deliberately without a house style. ------- */
function smt_render_index() {
    global $SMT_FRAGMENTS, $SMT_POOL_LINES, $SMT_SLUGWORDS;

    // order the fragments by the day, so the surface never quite holds still
    $daySeed = smt_fnv('surface::' . gmdate('Y-z'));
    $rng = smt_rng($daySeed);
    $keys = array_keys($SMT_FRAGMENTS);
    // Fisher–Yates with the seeded rng
    for ($i = count($keys) - 1; $i > 0; $i--) {
        $j = smt_int($rng, 0, $i);
        $tmp = $keys[$i]; $keys[$i] = $keys[$j]; $keys[$j] = $tmp;
    }

    // a hand-built directory listing that actually goes somewhere
    $now = time();
    $entries = array(
        array('gate/',                'dir',   gmdate('d-M-Y H:i', SMT_EPOCH),           '-',        '/gate'),
        array('index/',               'dir',   gmdate('d-M-Y H:i', $now - 400000),       '-',        '/index'),
        array('log/',                 'dir',   gmdate('d-M-Y H:i', $now - 51),           '-',        '/log'),
        array('vault/',               'dir',   gmdate('d-M-Y H:i', 4102444800),          '-',        '/vault/'),
        array('attic/',               'dir',   gmdate('d-M-Y H:i', SMT_EPOCH - 8640000),  '-',        '/attic/'),
        array('reliquary/',           'dir',   gmdate('d-M-Y H:i', $now - 999999),       '-',        '/reliquary/'),
        array('manifest.txt',         'file',  gmdate('d-M-Y H:i', $now - 3600),         '12 of 9',  '/gate/manifest-041'),
        array('0.037.mV',             'file',  gmdate('d-M-Y H:i', $now - 41),           '1',        '/log/1997-11-03'),
        array('north-stair.survey',   'file',  gmdate('d-M-Y H:i', 0),                   'pending',  '/gate/north-stair'),
        array('key.brass',            'file',  gmdate('d-M-Y H:i', $now + 3600),         'warm',     '/reliquary/key-brass'),
        array('.well-known/',         'dir',   gmdate('d-M-Y H:i', SMT_EPOCH),           '-',        '/.well-known/strange'),
        array('~operator',            'link',  gmdate('d-M-Y H:i', $now - 86400 * 41),   '&rarr; ?', '/~operator'),
    );
    $dir = "<pre style=\"margin:0;font:12.5px/1.7 'DejaVu Sans Mono',Menlo,monospace;color:#5a5346\">";
    $dir .= "Index of /  —  <span style=\"color:#8a1a1a\">this index is not authoritative</span>\n\n";
    $dir .= str_pad('Name', 26) . str_pad('Last modified', 20) . "Size\n";
    $dir .= str_repeat('·', 58) . "\n";
    foreach ($entries as $e) {
        $name = str_pad($e[0], 26);
        $dir .= "<a href=\"" . smt_h($e[4]) . "\" style=\"color:" . ($e[1] === 'dir' ? '#2a4a8a' : ($e[1] === 'link' ? '#8a2a6a' : '#3a5a3a')) . ";text-decoration:none;border-bottom:1px dotted #b8ae95\">" . $name . "</a>";
        $dir .= str_pad($e[2], 20) . $e[3] . "\n";
    }
    $dir .= "</pre>";

    // a scatter of bare portals in different registers
    $slugA = $SMT_SLUGWORDS[$daySeed % count($SMT_SLUGWORDS)] . '-' . smt_b36($daySeed, 3);
    $slugB = $SMT_SLUGWORDS[($daySeed >> 5) % count($SMT_SLUGWORDS)];
    $coord = smt_b36($daySeed, 6) . '.' . smt_b36($daySeed >> 8, 4);

    echo "<!doctype html>\n<html lang=\"und\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo "<meta name=\"robots\" content=\"noindex,nofollow,noarchive,nosnippet\">";
    echo "<title>smtstrange</title>";
    echo "<!--\n  " . smt_h(smt_pick($rng, $SMT_POOL_LINES)) . "\n  " . strtoupper(bin2hex(substr(md5((string) $daySeed), 0, 10))) . "\n  the surface reorders itself at the day boundary. nothing under it moves.\n  /" . smt_h($slugA) . "  ·  /gate  ·  do not force the sealed zones\n-->";
    // deliberately clashing base rules; each block still styles itself
    echo "<style>"
        . "html{background:#151312}"
        . "body{margin:0;background:#151312;color:#cfc8ba;font-family:Georgia,serif}"
        . ".smt-flow > *{display:block}"
        . ".smt-gap{height:5.5rem}"
        . ".smt-gap.s{height:2.2rem}.smt-gap.l{height:9rem}"
        . ".smt-wrap{max-width:40em;margin:0 auto;padding:0 1.2em}"
        . ".smt-bare a{color:inherit}"
        . "a{color:#b89a5a}"
        . "::selection{background:#3a2f18;color:#fff}"
        . "</style></head><body><div class=\"smt-flow\">";

    // 1. an oblique opening — not a header, not a welcome
    echo "<div class=\"smt-wrap\" style=\"padding-top:4.5rem\">"
        . "<div style=\"font:11px/1.8 'DejaVu Sans Mono',monospace;color:#5a5346;letter-spacing:.02em\">"
        . "47.31&hairsp;/&hairsp;7.02 &middot; " . gmdate('Y-m-d H:i:s') . "Z &middot; carrier present on the disconnected channel<br>"
        . "the count was taken twice and did not settle. what follows was already here.</div></div>";

    echo "<div class=\"smt-gap s\"></div>";
    echo "<div class=\"smt-wrap\">" . $dir . "</div>";

    // interleave the fragments with irregular gaps and occasional bare portals
    $gapClasses = array('', ' s', ' l', '', ' s');
    $i = 0;
    foreach ($keys as $k) {
        echo "<div class=\"smt-gap" . $gapClasses[$i % count($gapClasses)] . "\"></div>";
        // fragments carry their own full styling; give them room, no wrapper chrome
        $indent = ($i % 3 === 1) ? "padding-left:8%" : (($i % 3 === 2) ? "padding-right:6%;text-align:right" : "");
        echo "<div style=\"max-width:44em;margin:0 auto;padding:0 1.2em\"><div style=\"" . $indent . "\">" . $SMT_FRAGMENTS[$k] . "</div></div>";

        // every few blocks, drop a portal in a different voice
        if ($i === 1) {
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\" style=\"font:italic 1.1rem/1.7 Georgia,serif;color:#8a8272\">"
                . "&mdash;&nbsp;continued at <a href=\"/gate/" . smt_h($slugA) . "\" style=\"color:#b89a5a;text-decoration:none;border-bottom:1px solid #4a4030\">" . smt_h($slugA) . "</a>, which continues.</div>";
        }
        if ($i === 3) {
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\"><span style=\"font:12px/1.6 'DejaVu Sans Mono',monospace;color:#6a6250\">bearing "
                . smt_h($coord) . " resolves to <a href=\"/" . smt_h($slugB) . "\" style=\"color:#8a2a2a\">a wall</a> or <a href=\"/gate/" . smt_h($slugB) . "-" . smt_b36($daySeed >> 3, 2) . "\" style=\"color:#3a6a3a\">a door</a>. they are adjacent.</span></div>";
        }
        if ($i === 5) {
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\" style=\"text-align:center\"><a href=\"/vault/" . smt_h($slugB) . "\" style=\"font:11px/1 'DejaVu Sans Mono',monospace;color:#5a3030;letter-spacing:.3em;text-decoration:none;border:1px solid #3a2020;padding:.6em 1em;display:inline-block\">DO NOT FORCE</a></div>";
        }
        if ($i === 9) {
            $slugC = $SMT_SLUGWORDS[($daySeed >> 7) % count($SMT_SLUGWORDS)] . '-' . smt_b36($daySeed >> 2, 3);
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\"><div style=\"font:12px/1.7 'Courier New',monospace;color:#6a6250;border-left:2px solid #3a352c;padding-left:1em\">"
                . "LOST &mdash; a page, torn cleanly; the tear is <a href=\"/gate/" . smt_h($slugC) . "\" style=\"color:#8a7a4a\">held separately</a>.<br>"
                . "FOUND &mdash; a key, warm; it fits the <a href=\"/reliquary/" . smt_h($slugB) . "\" style=\"color:#7a2a2a\">door in 2029</a>. do not attempt.</div></div>";
        }
        if ($i === 12) {
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\" style=\"text-align:right\"><span style=\"font:italic 1.05rem/1.7 Georgia,serif;color:#8a8272\">"
                . "&mdash;&nbsp;the plate that follows twelve is <a href=\"/gate/12-of-9\" style=\"color:#b89a5a;text-decoration:none;border-bottom:1px solid #4a4030\">twelve</a>.</span></div>";
        }
        if ($i === 15) {
            $d = gmdate('Y-m-d', SMT_EPOCH + (($daySeed % 4000)) * 86400);
            echo "<div class=\"smt-gap s\"></div><div class=\"smt-wrap\"><span style=\"font:11px/1.8 'DejaVu Sans Mono',monospace;color:#5a5346\">"
                . "the carrier was present on <a href=\"/log/" . $d . "\" style=\"color:#6a8aa8\">" . $d . "</a>; there was no traffic; the carrier is the traffic.</span></div>";
        }
        $i++;
    }

    // a closing that closes nothing
    echo "<div class=\"smt-gap l\"></div>";
    echo "<div class=\"smt-wrap\" style=\"padding-bottom:6rem\">"
        . "<div style=\"font:11px/1.9 'DejaVu Sans Mono',monospace;color:#48423a;border-top:1px solid #2a2520;padding-top:1.4em\">"
        . "the last line of the manifest is the manifest.<br>"
        . "<a href=\"/index\" style=\"color:#6a5e40;text-decoration:none\">index</a> &middot; "
        . "<a href=\"/log\" style=\"color:#6a5e40;text-decoration:none\">log</a> &middot; "
        . "<a href=\"/gate\" style=\"color:#6a5e40;text-decoration:none\">gate</a> &middot; "
        . "<a href=\"/.well-known/strange\" style=\"color:#6a5e40;text-decoration:none\">.</a>"
        . "</div></div>";

    // one portal only the source-divers find
    echo "<a href=\"/gate/" . smt_h($SMT_SLUGWORDS[($daySeed >> 11) % count($SMT_SLUGWORDS)]) . "-" . smt_b36($daySeed, 4) . "\" style=\"position:absolute;left:-9999px;top:auto\" aria-hidden=\"true\" tabindex=\"-1\">.</a>";

    echo "</div></body></html>";

    header('Warning: 199 - "nothing here is authoritative"');
    header('Link: </gate>; rel="start", </.well-known/strange>; rel="alternate"');
}

/* ===========================================================================
 *  ROUTER
 * ========================================================================= */

$path = smt_path();
$err  = smt_errcode();
$seg  = $path === '' ? array() : explode('/', $path);
$head = $seg[0] ?? '';

// hard forbidden zones (also caught by .htaccess [F])
if ($err === 403 || in_array($head, $SMT_SEALED, true) || $head === '~operator') {
    smt_render_forbidden($path === '' ? ($_SERVER['REDIRECT_URL'] ?? 'vault') : $path);
    exit;
}
if ($err === 410) { smt_render_gone($path); exit; }

// the older index does not surface here
if ($path === 'index.html' || $path === 'index.htm') { header('Location: /', true, 302); exit; }

// virtual files
if ($path === 'favicon.ico') { http_response_code(204); exit; }
if ($path === 'robots.txt')  { smt_render_robots();  exit; }
if ($path === 'humans.txt')  { smt_render_humans();  exit; }
if ($path === 'sitemap.xml') { smt_render_sitemap(); exit; }

if ($path === '') { smt_render_index(); exit; }

switch ($head) {
    case '.well-known': smt_render_wellknown($seg); exit;
    case 'log':         smt_render_log($seg);       exit;
    case 'index':       smt_render_alpha($seg);     exit;
    default:            smt_render_node($path, $seg); exit;
}
