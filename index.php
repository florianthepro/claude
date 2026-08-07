<?php
/* ===========================================================================
 *  smtstrange — a room of apparatus. nothing here agrees with anything else.
 *  each thing is operated, not read.
 * ========================================================================= */

@ini_set('display_errors', '0');
error_reporting(0);
date_default_timezone_set('UTC');
define('SMT_ROOT', __DIR__);

/* --- works from a domain root or any folder ------------------------------ */
function smt_base() {
    static $b = null;
    if ($b !== null) return $b;
    $d = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $b = ($d === '/' || $d === '.' || $d === '') ? '' : rtrim($d, '/');
    return $b;
}
function u($p = '') { return smt_base() . '/' . ltrim((string) $p, '/'); }
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/* --- it lays its own rewrite rules if they are missing -------------------- */
(function () {
    $ht = SMT_ROOT . '/.htaccess'; $mark = '# smt-portable-v3';
    $have = @is_file($ht) ? (string) @file_get_contents($ht) : null;
    if ($have !== null && (strpos($have, $mark) !== false || strpos($have, 'smt') === false)) return;
    if (!@is_writable(SMT_ROOT)) return;
    @file_put_contents($ht, $mark . "\nOptions -Indexes +FollowSymLinks -MultiViews\nDirectoryIndex index.php\n"
      . "AddDefaultCharset utf-8\n<Files \".htaccess\">\n    Require all denied\n</Files>\n"
      . "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^index\\.html?$ ./ [R=302,L]\n"
      . "    RewriteRule (^|/)\\.git(/|$) - [F,L]\n"
      . "    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteCond %{REQUEST_FILENAME} !-d\n"
      . "    RewriteRule ^ index.php [L]\n</IfModule>\n");
})();

function smt_path() {
    if (isset($_GET['p'])) return trim(preg_replace('#//+#', '/', (string) $_GET['p']), '/');
    $uri = $_SERVER['REDIRECT_URL'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
    $p = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
    $b = smt_base();
    if ($b !== '' && strncmp($p, $b, strlen($b)) === 0) $p = substr($p, strlen($b));
    return trim(preg_replace('#//+#', '/', preg_replace('#^/index\.php#', '', $p)), '/');
}
function smt_mobile() {
    if (isset($_GET['m'])) { @setcookie('m', $_GET['m'] === '1' ? '1' : '0', time()+31536000, '/'); return $_GET['m'] === '1'; }
    if (isset($_COOKIE['m'])) return $_COOKIE['m'] === '1';
    return (bool) preg_match('/Android|iPhone|iPod|iPad|Mobile|Silk|IEMobile|BlackBerry/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
}
/* ---------------------------------------------------------------------------
 *  Every visitor carries their own seed. While it lives in their browser the
 *  same path gives them the same place; another visitor gets another world.
 * ------------------------------------------------------------------------- */
function smt_seed() {
    static $s = null;
    if ($s !== null) return $s;
    $ok = '/^[a-z0-9]{6,20}$/';
    if (isset($_GET['s']) && preg_match($ok, (string) $_GET['s'])) {
        $s = (string) $_GET['s'];
        @setcookie('smt_s', $s, time() + 31536000, '/');
    } elseif (isset($_COOKIE['smt_s']) && preg_match($ok, (string) $_COOKIE['smt_s'])) {
        $s = (string) $_COOKIE['smt_s'];
    } else {
        try { $n = random_int(0, PHP_INT_MAX); } catch (Exception $e) { $n = mt_rand(); }
        $s = substr(base_convert((string) $n, 10, 36) . base_convert((string) mt_rand(), 10, 36), 0, 12);
        @setcookie('smt_s', $s, time() + 31536000, '/');
        $_COOKIE['smt_s'] = $s;
    }
    return $s;
}
/* the seed is folded into every derivation */
function pseed($what) { return fnv(smt_seed() . '|' . $what); }

/* the browser keeps it too, and hands it back if the cookie is lost */
function keeper() {
    return '<script>(function(){try{var m=document.cookie.match(/(?:^|; )smt_s=([a-z0-9]+)/),'
        . 'l=localStorage.getItem("smt_s");'
        . 'if(m){if(l!==m[1])localStorage.setItem("smt_s",m[1]);}'
        . 'else if(l&&!sessionStorage.getItem("smt_r")){sessionStorage.setItem("smt_r","1");'
        . 'document.cookie="smt_s="+l+";path=/;max-age=31536000";location.reload();}'
        . '}catch(e){}})();</script>';
}

function fnv($s) { $h = 2166136261; for ($i=0,$n=strlen($s);$i<$n;$i++){ $h ^= ord($s[$i]); $h = ($h * 16777619) & 0xffffffff; } return $h; }

/* ===========================================================================
 *  THE APPARATUS. sixteen of them. no two are built alike.
 * ========================================================================= */

$APP = array(
 'dial'    => 'three dials',
 'grid'    => 'the field',
 'tumbler' => 'five tumblers',
 'wheel'   => 'the ring',
 'keyer'   => 'the key',
 'scope'   => 'the trace',
 'pans'    => 'the balance',
 'slide'   => 'nine tiles',
 'stars'   => 'the figure',
 'mixer'   => 'three taps',
 'sort'    => 'the column',
 'well'    => 'the well',
 'strings' => 'the strings',
 'steps'   => 'the sequence',
 'pairs'   => 'the faces',
 'term'    => 'the console',
);

/* a bare shell — every apparatus brings its own everything */
function shell($title, $css, $body, $js = '', $mob = false) {
    echo "<!doctype html><html lang=\"und\"><head><meta charset=\"utf-8\">"
       . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1,viewport-fit=cover,user-scalable=no\">"
       . "<meta name=\"robots\" content=\"noindex,nofollow\"><title>" . h($title) . "</title><style>"
       . "*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}html,body{margin:0;padding:0}"
       . "body{overflow-x:hidden;touch-action:manipulation}.bk{position:fixed;left:0;bottom:0;z-index:99;"
       . "font:10px/1 monospace;letter-spacing:.2em;padding:1em 1.2em;color:#666;text-decoration:none}"
       . $css . "</style></head><body>" . $body
       . apparatus_ways($title)
       . ($js !== '' ? "<script>" . $js . "</script>" : "") . keeper() . "</body></html>";
}

/* an instrument is somewhere, not an endpoint: it has its own ways on */
function apparatus_ways($title) {
    $r  = rng(pseed('inst:' . $title));
    $ex = exits(rp($r, $GLOBALS['WORDS']), $r, ri($r, 6, 14));
    $o  = '<div style="position:fixed;left:0;right:0;bottom:0;display:flex;flex-wrap:wrap;gap:.2em 1.1em;'
        . 'padding:.6em .9em;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);font:10px/1.9 monospace;'
        . 'letter-spacing:.12em;z-index:99">';
    $o .= '<a href="' . h(u()) . '" style="color:#8a8a92;text-decoration:none">&lt;</a>';
    foreach ($ex as $e)
        $o .= '<a href="' . h($e[0]) . '" style="color:#7a7a84;text-decoration:none;opacity:.75">' . h($e[1]) . '</a>';
    return $o . '</div>';
}

/* ---------------------------------------------------------------- 1 dials */
function ap_dial($mob) {
    $css = "body{background:#101418;display:flex;align-items:center;justify-content:center;min-height:100vh;gap:"
        . ($mob ? "18px" : "46px") . ";flex-wrap:wrap;padding:20px}"
        . ".d{width:" . ($mob ? "100px" : "150px") . ";height:" . ($mob ? "100px" : "150px")
        . ";border-radius:50%;background:radial-gradient(circle at 38% 32%,#3a444e,#161b21 72%);"
        . "border:2px solid #2c353e;position:relative;cursor:grab;touch-action:none;box-shadow:0 8px 30px #0008}"
        . ".d:active{cursor:grabbing}.d i{position:absolute;left:50%;top:8%;width:2px;height:36%;background:#d8a24a;"
        . "transform-origin:50% 100%;margin-left:-1px;display:block}"
        . ".d b{position:absolute;left:0;right:0;bottom:-26px;text-align:center;font:11px monospace;color:#5d6b78;font-weight:400}"
        . "#o{position:fixed;top:0;left:0;right:0;text-align:center;font:" . ($mob?"26px":"34px") . "/2.2 monospace;color:#d8a24a;letter-spacing:.4em}";
    $b = "<div id=o>— — —</div>";
    for ($i = 0; $i < 3; $i++) $b .= "<div class=d data-i=$i><i></i><b>" . array('I','II','III')[$i] . "</b></div>";
    $js = <<<'JS'
var ds=document.querySelectorAll('.d'),v=[0,0,0],o=document.getElementById('o');
function paint(){ds.forEach(function(d,i){d.querySelector('i').style.transform='rotate('+(v[i]*9)+'deg)';});
o.textContent=v.map(function(x){return String(x).padStart(2,'0');}).join(' ');
if(v[0]===4&&v[1]===1&&v[2]===0){o.style.color='#7ad86a';o.textContent+='  ·';}else{o.style.color='#d8a24a';}}
ds.forEach(function(d,i){var drag=false,last=0;
function ang(e){var r=d.getBoundingClientRect();return Math.atan2((e.clientY-(r.top+r.height/2)),(e.clientX-(r.left+r.width/2)))*180/Math.PI;}
d.addEventListener('pointerdown',function(e){drag=true;last=ang(e);d.setPointerCapture(e.pointerId);});
d.addEventListener('pointermove',function(e){if(!drag)return;var a=ang(e),dd=a-last;if(dd>180)dd-=360;if(dd<-180)dd+=360;
if(Math.abs(dd)>4.5){var st=Math.round(dd/9);v[i]=((v[i]+st)%40+40)%40;last=a;paint();}});
d.addEventListener('pointerup',function(){drag=false;});d.addEventListener('pointercancel',function(){drag=false;});});
paint();
JS;
    shell('dials', $css, $b, $js, $mob);
}

/* ----------------------------------------------------------------- 2 field */
function ap_grid($mob) {
    $n = $mob ? 14 : 22;
    $css = "body{background:#f4f2ec;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;gap:14px}"
        . "#g{display:grid;grid-template-columns:repeat($n," . ($mob?"20px":"22px") . ");gap:1px;background:#ddd9cf;padding:1px;touch-action:none}"
        . "#g div{width:" . ($mob?"20px":"22px") . ";height:" . ($mob?"20px":"22px") . ";background:#fbfaf6}"
        . "#g div.on{background:#1b1b1b}#g div.q{background:#b2452f}"
        . "#c{font:12px monospace;color:#8a857a;letter-spacing:.24em}";
    $b = "<div id=g></div><div id=c>0</div>";
    $js = "var N=$n;" . <<<'JS'
var g=document.getElementById('g'),c=document.getElementById('c'),cells=[],paint=false,mode=true;
for(var i=0;i<N*N;i++){var d=document.createElement('div');d.dataset.i=i;g.appendChild(d);cells.push(d);}
function count(){var k=0;cells.forEach(function(d){if(d.classList.contains('on'))k++;});
c.textContent=String(k);
// rows that fill completely go over
for(var r=0;r<N;r++){var full=true;for(var x=0;x<N;x++){if(!cells[r*N+x].classList.contains('on')){full=false;break;}}
 for(var x2=0;x2<N;x2++){cells[r*N+x2].classList.toggle('q',full);}}}
function hit(e){var el=document.elementFromPoint(e.clientX,e.clientY);
if(el&&el.parentNode===g){el.classList.toggle('on',mode);count();}}
g.addEventListener('pointerdown',function(e){var el=document.elementFromPoint(e.clientX,e.clientY);
mode=!(el&&el.classList.contains('on'));paint=true;hit(e);g.setPointerCapture(e.pointerId);});
g.addEventListener('pointermove',function(e){if(paint)hit(e);});
window.addEventListener('pointerup',function(){paint=false;});
count();
JS;
    shell('field', $css, $b, $js, $mob);
}

/* --------------------------------------------------------------- 3 tumblers */
function ap_tumbler($mob) {
    $css = "body{background:#1a1512;display:flex;align-items:center;justify-content:center;min-height:100vh}"
        . "#t{display:flex;gap:" . ($mob?"6px":"12px") . ";padding:" . ($mob?"14px":"26px")
        . ";background:#241d18;border:1px solid #3a2f26;border-radius:4px}"
        . ".w{width:" . ($mob?"46px":"62px") . ";height:" . ($mob?"150px":"200px")
        . ";overflow:hidden;background:linear-gradient(#0f0c0a,#2a221c 18%,#3a2f27 50%,#2a221c 82%,#0f0c0a);"
        . "border:1px solid #453a30;position:relative;touch-action:none;cursor:ns-resize}"
        . ".w ul{margin:0;padding:0;list-style:none;position:absolute;left:0;right:0;transition:top .12s}"
        . ".w li{height:" . ($mob?"30px":"40px") . ";line-height:" . ($mob?"30px":"40px")
        . ";text-align:center;font:" . ($mob?"18px":"24px") . " monospace;color:#c9ab7e}"
        . "#s{position:fixed;left:0;right:0;top:" . ($mob?"14%":"20%") . ";text-align:center;font:11px monospace;color:#5b4a3c;letter-spacing:.3em}"
        . ".ok .w{border-color:#7a6a3a;box-shadow:0 0 18px #7a6a3a55}";
    $b = "<div id=s>&nbsp;</div><div id=t>";
    for ($i=0;$i<5;$i++){ $b .= "<div class=w data-i=$i><ul>"; for($k=0;$k<10;$k++) $b .= "<li>$k</li>"; $b .= "</ul></div>"; }
    $b .= "</div>";
    $js = "var H=" . ($mob?30:40) . ",VIS=" . ($mob?5:5) . ";" . <<<'JS'
var ws=document.querySelectorAll('.w'),v=[0,0,0,0,0],t=document.getElementById('t'),s=document.getElementById('s');
var TARGET=[4,1,0,3,7];
function place(){ws.forEach(function(w,i){w.querySelector('ul').style.top=(-(v[i]*H)+H*2)+'px';});
var ok=v.every(function(x,i){return x===TARGET[i];});t.classList.toggle('ok',ok);
s.textContent=ok?'· · ·':' ';}
ws.forEach(function(w,i){var y0=0,dr=false;
w.addEventListener('pointerdown',function(e){dr=true;y0=e.clientY;w.setPointerCapture(e.pointerId);});
w.addEventListener('pointermove',function(e){if(!dr)return;var d=e.clientY-y0;
if(Math.abs(d)>=H*0.6){var st=d>0?-1:1;v[i]=((v[i]+st)%10+10)%10;y0=e.clientY;place();}});
w.addEventListener('pointerup',function(){dr=false;});
w.addEventListener('pointercancel',function(){dr=false;});});
place();
JS;
    shell('tumblers', $css, $b, $js, $mob);
}

/* ------------------------------------------------------------------ 4 ring */
function ap_wheel($mob) {
    $sz = $mob ? 300 : 420;
    $css = "body{background:#0d0f0e;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;gap:18px}"
        . "#r{width:{$sz}px;height:{$sz}px;position:relative;touch-action:none;cursor:grab;max-width:94vw}"
        . "#out{font:" . ($mob?"14px":"17px") . "/1.9 monospace;color:#8fbf9a;max-width:min(94vw,34em);text-align:center;letter-spacing:.05em;padding:0 12px}"
        . "text{font:13px monospace}";
    $ct = $sz/2; $r1 = $sz/2-14; $r2 = $sz/2-56;
    $b = "<div id=r><svg viewBox=\"0 0 $sz $sz\" width=\"100%\" height=\"100%\">"
       . "<circle cx=$ct cy=$ct r=$r1 fill=none stroke=\"#243028\" stroke-width=1 />"
       . "<circle cx=$ct cy=$ct r=$r2 fill=none stroke=\"#243028\" stroke-width=1 /><g id=outer>";
    for ($i=0;$i<26;$i++){ $a=$i*360/26-90; $x=$ct+cos(deg2rad($a))*($r1-16); $y=$ct+sin(deg2rad($a))*($r1-16);
      $b .= "<text x=$x y=$y fill=\"#5f7a66\" text-anchor=middle dominant-baseline=central>".chr(65+$i)."</text>"; }
    $b .= "</g><g id=inner>";
    for ($i=0;$i<26;$i++){ $a=$i*360/26-90; $x=$ct+cos(deg2rad($a))*($r2-16); $y=$ct+sin(deg2rad($a))*($r2-16);
      $b .= "<text x=$x y=$y fill=\"#c8a44a\" text-anchor=middle dominant-baseline=central>".chr(97+$i)."</text>"; }
    $b .= "</g><line x1=$ct y1=8 x2=$ct y2=" . ($sz/2-$r2+8) . " stroke=\"#b2452f\" stroke-width=2 /></svg></div><div id=out></div>";
    $js = "var CT=$ct;" . <<<'JS'
var CIPH="tsj ymnsl ymfy mfx st jsi";
var inner=document.getElementById('inner'),r=document.getElementById('r'),out=document.getElementById('out'),k=0;
function dec(){var s='';for(var i=0;i<CIPH.length;i++){var c=CIPH[i];
if(c>='a'&&c<='z'){s+=String.fromCharCode(97+((c.charCodeAt(0)-97-k)%26+26)%26);}else s+=c;}
out.textContent=s;out.style.color=(k===5)?'#9ee0a8':'#4f6a58';}
function set(a){k=((Math.round(a/(360/26))%26)+26)%26;inner.setAttribute('transform','rotate('+(k*360/26)+' '+CT+' '+CT+')');dec();}
var dr=false;
function ang(e){var b=r.getBoundingClientRect();return Math.atan2(e.clientY-(b.top+b.height/2),e.clientX-(b.left+b.width/2))*180/Math.PI+90;}
r.addEventListener('pointerdown',function(e){dr=true;r.setPointerCapture(e.pointerId);set(ang(e));});
r.addEventListener('pointermove',function(e){if(dr)set(ang(e));});
r.addEventListener('pointerup',function(){dr=false;});
dec();
JS;
    shell('ring', $css, $b, $js, $mob);
}

/* ------------------------------------------------------------------- 5 key */
function ap_keyer($mob) {
    $css = "body{background:#07090b;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;gap:26px}"
        . "#k{width:" . ($mob?"180px":"230px") . ";height:" . ($mob?"180px":"230px") . ";border-radius:50%;"
        . "background:radial-gradient(circle at 40% 34%,#2c3540,#0e1216);border:2px solid #1d252c;touch-action:none;cursor:pointer}"
        . "#k.d{background:radial-gradient(circle at 40% 34%,#4a5a68,#161d24);border-color:#3b98d8}"
        . "#raw{font:16px monospace;color:#3b98d8;letter-spacing:.35em;min-height:22px}"
        . "#txt{font:" . ($mob?"22px":"28px") . " monospace;color:#dfe6ec;letter-spacing:.3em;min-height:34px}";
    $b = "<div id=txt></div><div id=raw></div><div id=k></div>";
    $js = <<<'JS'
var M={'.-':'A','-...':'B','-.-.':'C','-..':'D','.':'E','..-.':'F','--.':'G','....':'H','..':'I','.---':'J',
'-.-':'K','.-..':'L','--':'M','-.':'N','---':'O','.--.':'P','--.-':'Q','.-.':'R','...':'S','-':'T','..-':'U',
'...-':'V','.--':'W','-..-':'X','-.--':'Y','--..':'Z'};
var k=document.getElementById('k'),raw=document.getElementById('raw'),txt=document.getElementById('txt');
var t0=0,buf='',gap=null;
function flush(){if(buf){txt.textContent=(txt.textContent+(M[buf]||'·')).slice(-22);buf='';raw.textContent='';}}
function down(e){e.preventDefault();t0=Date.now();k.classList.add('d');if(gap){clearTimeout(gap);gap=null;}}
function up(){if(!t0)return;var d=Date.now()-t0;t0=0;k.classList.remove('d');
buf+=(d<220?'.':'-');raw.textContent=buf;gap=setTimeout(flush,700);}
k.addEventListener('pointerdown',down);window.addEventListener('pointerup',up);
window.addEventListener('keydown',function(e){if(e.code==='Space'&&!t0)down(e);});
window.addEventListener('keyup',function(e){if(e.code==='Space')up();});
JS;
    shell('key', $css, $b, $js, $mob);
}

/* ----------------------------------------------------------------- 6 trace */
function ap_scope($mob) {
    $css = "body{background:#050806;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;gap:16px;padding:14px}"
        . "canvas{background:#060d08;border:1px solid #16301c;max-width:96vw;image-rendering:pixelated}"
        . ".s{display:flex;align-items:center;gap:10px;font:10px monospace;color:#3f7a4f;letter-spacing:.2em}"
        . "input[type=range]{width:" . ($mob?"200px":"280px") . ";accent-color:#4be07a}";
    $w = $mob ? 340 : 620; $hh = $mob ? 190 : 260;
    $b = "<canvas id=c width=$w height=$hh></canvas>";
    foreach (array('f'=>'FREQ','a'=>'AMPL','p'=>'PHAS','n'=>'NOIS') as $k=>$l)
        $b .= "<div class=s><span>$l</span><input type=range id=$k min=0 max=100 value=" . ($k==='n'?'6':'40') . "></div>";
    $js = <<<'JS'
var c=document.getElementById('c'),x=c.getContext('2d'),t=0;
function g(i){return document.getElementById(i).value/100;}
function frame(){var W=c.width,H=c.height;x.fillStyle='#060d08';x.fillRect(0,0,W,H);
x.strokeStyle='#0e2413';x.lineWidth=1;x.beginPath();
for(var i=0;i<=10;i++){x.moveTo(i*W/10,0);x.lineTo(i*W/10,H);x.moveTo(0,i*H/10);x.lineTo(W,i*H/10);}x.stroke();
var f=0.4+g('f')*7,a=g('a')*(H*0.42),p=g('p')*Math.PI*2,n=g('n')*22;
x.strokeStyle='#4be07a';x.lineWidth=1.6;x.beginPath();
for(var px=0;px<W;px++){var v=Math.sin((px/W)*Math.PI*2*f+p+t)*a+(Math.random()-0.5)*n;
 px?x.lineTo(px,H/2+v):x.moveTo(px,H/2+v);}x.stroke();
x.strokeStyle='rgba(75,224,122,.18)';x.lineWidth=5;x.stroke();
t+=0.035;requestAnimationFrame(frame);}
frame();
JS;
    shell('trace', $css, $b, $js, $mob);
}

/* --------------------------------------------------------------- 7 balance */
function ap_pans($mob) {
    $css = "body{background:#e8e4d8;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;gap:8px;font-family:Georgia,serif}"
        . "#beam{width:" . ($mob?"280px":"420px") . ";height:6px;background:#5a4a34;transform-origin:50% 50%;transition:transform .45s;border-radius:3px;position:relative}"
        . ".pan{position:absolute;top:6px;width:" . ($mob?"104px":"140px") . ";min-height:64px;background:#cfc4a8;border:1px solid #a8977a;"
        . "display:flex;flex-wrap:wrap;gap:4px;padding:6px;align-content:flex-start}"
        . "#pl{left:" . ($mob?"-26px":"-34px") . "}#pr{right:" . ($mob?"-26px":"-34px") . "}"
        . "#tray{display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin-top:" . ($mob?"120px":"140px") . ";max-width:94vw}"
        . ".wt{background:#7a6a4a;color:#f1ead8;font:12px monospace;padding:7px 9px;border-radius:2px;cursor:grab;touch-action:none;user-select:none}"
        . "#rd{font:12px monospace;color:#6a5f48;letter-spacing:.2em;margin-top:10px}";
    $b = "<div id=beam><div class=pan id=pl></div><div class=pan id=pr></div></div><div id=tray>";
    foreach (array(1,2,3,5,8,13,21,34) as $g) $b .= "<div class=wt draggable=false data-g=$g>{$g}</div>";
    $b .= "</div><div id=rd>0 : 0</div>";
    $js = <<<'JS'
var beam=document.getElementById('beam'),pl=document.getElementById('pl'),pr=document.getElementById('pr'),
tray=document.getElementById('tray'),rd=document.getElementById('rd');
function sum(p){var s=0;p.querySelectorAll('.wt').forEach(function(w){s+=+w.dataset.g;});return s;}
function upd(){var a=sum(pl),b=sum(pr),d=Math.max(-14,Math.min(14,(b-a)*1.1));
beam.style.transform='rotate('+d+'deg)';rd.textContent=a+' : '+b;
rd.style.color=(a===b&&a>0)?'#3f7a3f':'#6a5f48';}
var drag=null,ox=0,oy=0;
document.addEventListener('pointerdown',function(e){var w=e.target.closest('.wt');if(!w)return;
drag=w;var r=w.getBoundingClientRect();ox=e.clientX-r.left;oy=e.clientY-r.top;
w.style.position='fixed';w.style.zIndex=50;w.style.left=(e.clientX-ox)+'px';w.style.top=(e.clientY-oy)+'px';
document.body.appendChild(w);w.setPointerCapture(e.pointerId);});
document.addEventListener('pointermove',function(e){if(!drag)return;
drag.style.left=(e.clientX-ox)+'px';drag.style.top=(e.clientY-oy)+'px';});
document.addEventListener('pointerup',function(e){if(!drag)return;
drag.style.position='';drag.style.left='';drag.style.top='';drag.style.zIndex='';
var t=document.elementFromPoint(e.clientX,e.clientY),host=tray;
if(t){var p=t.closest('.pan');if(p)host=p;}
host.appendChild(drag);drag=null;upd();});
upd();
JS;
    shell('balance', $css, $b, $js, $mob);
}

/* ----------------------------------------------------------------- 8 tiles */
function ap_slide($mob) {
    $s = $mob ? 88 : 116;
    $css = "body{background:#161a1d;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;gap:16px}"
        . "#p{display:grid;grid-template-columns:repeat(3,{$s}px);gap:5px}"
        . "#p div{height:{$s}px;background:#e6e2d6;color:#20242a;display:flex;align-items:center;justify-content:center;"
        . "font:" . ($mob?"30px":"38px") . " Georgia,serif;cursor:pointer;transition:background .1s}"
        . "#p div.e{background:transparent;cursor:default}#p div:active{background:#cfc9b8}"
        . "#m{font:11px monospace;color:#5b6570;letter-spacing:.26em}";
    $b = "<div id=p></div><div id=m>0</div>";
    $js = <<<'JS'
var p=document.getElementById('p'),m=document.getElementById('m'),a=[1,2,3,4,5,6,7,8,0],mv=0;
function inv(x){var c=0;for(var i=0;i<9;i++)for(var j=i+1;j<9;j++)if(x[i]&&x[j]&&x[i]>x[j])c++;return c;}
do{for(var i=a.length-1;i>0;i--){var j=Math.floor(Math.random()*(i+1)),t=a[i];a[i]=a[j];a[j]=t;}}while(inv(a)%2!==0||a[8]===0&&inv(a)===0);
function draw(){p.innerHTML='';a.forEach(function(v,i){var d=document.createElement('div');
if(v===0){d.className='e';}else{d.textContent=v;}d.dataset.i=i;p.appendChild(d);});
m.textContent=String(mv);
if(a.join()==='1,2,3,4,5,6,7,8,0'){m.textContent=mv+'  ·';m.style.color='#7ad86a';}}
p.addEventListener('click',function(e){var d=e.target.closest('div');if(!d)return;var i=+d.dataset.i,z=a.indexOf(0);
if(Math.abs(i-z)===3||(Math.abs(i-z)===1&&Math.floor(i/3)===Math.floor(z/3))){a[z]=a[i];a[i]=0;mv++;draw();}});
draw();
JS;
    shell('tiles', $css, $b, $js, $mob);
}

/* ---------------------------------------------------------------- 9 figure */
function ap_stars($mob) {
    $css = "body{background:#05060a;margin:0}svg{display:block;width:100vw;height:100vh;touch-action:manipulation}"
        . "circle{cursor:pointer}#n{position:fixed;left:0;right:0;top:16px;text-align:center;font:11px monospace;color:#3a4356;letter-spacing:.3em}";
    $pts = array();
    $r = pseed('stars');
    for ($i=0;$i<14;$i++){ $r = ($r * 1103515245 + 12345) & 0x7fffffff;
      $pts[] = array(6 + ($r % 88), 12 + (($r >> 9) % 74)); }
    $b = "<div id=n>&nbsp;</div><svg viewBox=\"0 0 100 100\" preserveAspectRatio=\"none\"><g id=l></g>";
    foreach ($pts as $i=>$p) $b .= "<circle cx={$p[0]} cy={$p[1]} r=1.5 fill=\"#8fa0c8\" data-i=$i />";
    $b .= "</svg>";
    $js = <<<'JS'
var seq=[],l=document.getElementById('l'),n=document.getElementById('n');
document.querySelectorAll('circle').forEach(function(c){c.addEventListener('click',function(){
var i=+c.dataset.i;if(seq.length&&seq[seq.length-1]===i)return;
if(seq.indexOf(i)>=0){seq=[];l.innerHTML='';n.textContent=' ';document.querySelectorAll('circle').forEach(function(q){q.setAttribute('fill','#8fa0c8');q.setAttribute('r',1.5);});return;}
if(seq.length){var a=document.querySelector('circle[data-i="'+seq[seq.length-1]+'"]');
var ln=document.createElementNS('http://www.w3.org/2000/svg','line');
ln.setAttribute('x1',a.getAttribute('cx'));ln.setAttribute('y1',a.getAttribute('cy'));
ln.setAttribute('x2',c.getAttribute('cx'));ln.setAttribute('y2',c.getAttribute('cy'));
ln.setAttribute('stroke','#4a5a80');ln.setAttribute('stroke-width','.35');l.appendChild(ln);}
seq.push(i);c.setAttribute('fill','#e8d9a0');c.setAttribute('r',2.1);
n.textContent=seq.length+' / 14';
if(seq.length===14){n.textContent='· · ·';n.style.color='#e8d9a0';}});});
JS;
    shell('figure', $css, $b, $js, $mob);
}

/* ------------------------------------------------------------------ 10 taps */
function ap_mixer($mob) {
    $css = "body{margin:0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;transition:background .18s}"
        . ".t{display:flex;align-items:center;gap:12px}"
        . "input{-webkit-appearance:none;appearance:none;width:" . ($mob?"46px":"58px") . ";height:" . ($mob?"200px":"260px")
        . ";writing-mode:vertical-lr;direction:rtl;background:rgba(255,255,255,.16);outline:none;border-radius:2px}"
        . "input::-webkit-slider-thumb{-webkit-appearance:none;width:" . ($mob?"46px":"58px") . ";height:14px;background:#fff;cursor:ns-resize}"
        . "#nm{font:13px monospace;letter-spacing:.3em;mix-blend-mode:difference;color:#fff}"
        . "#row{display:flex;gap:" . ($mob?"14px":"26px") . "}";
    $b = "<div id=row>";
    foreach (array('r','g','b') as $k) $b .= "<div class=t><input type=range id=$k min=0 max=255 value=".rand(40,210)."></div>";
    $b .= "</div><div id=nm></div>";
    $js = <<<'JS'
var N=[[0,0,0,'pitch'],[255,255,255,'flare'],[120,20,20,'oxide'],[20,60,120,'cobalt'],[60,90,40,'verdigris'],
[200,180,90,'litharge'],[150,150,160,'galena'],[220,120,40,'realgar'],[40,40,60,'slag'],[190,60,110,'cinnabar']];
function up(){var r=+R.value,g=+G.value,b=+B.value;document.body.style.background='rgb('+r+','+g+','+b+')';
var best=0,bd=1e9;N.forEach(function(c,i){var d=(c[0]-r)*(c[0]-r)+(c[1]-g)*(c[1]-g)+(c[2]-b)*(c[2]-b);if(d<bd){bd=d;best=i;}});
document.getElementById('nm').textContent=N[best][3]+'  '+r.toString(16).padStart(2,'0')+g.toString(16).padStart(2,'0')+b.toString(16).padStart(2,'0');}
var R=document.getElementById('r'),G=document.getElementById('g'),B=document.getElementById('b');
[R,G,B].forEach(function(s){s.addEventListener('input',up);});up();
JS;
    shell('taps', $css, $b, $js, $mob);
}

/* ---------------------------------------------------------------- 11 column */
function ap_sort($mob) {
    $css = "body{background:#fbfaf7;color:#1c1c1a;font:" . ($mob?"13px":"13.5px") . "/1.6 'Helvetica Neue',Arial,sans-serif;"
        . "display:flex;align-items:center;justify-content:center;min-height:100vh;padding:16px}"
        . "table{border-collapse:collapse;min-width:" . ($mob?"96vw":"520px") . "}"
        . "th{text-align:left;border-bottom:2px solid #1c1c1a;padding:7px 10px;cursor:pointer;user-select:none;font-weight:600;white-space:nowrap}"
        . "th:hover{background:#eee}td{border-bottom:1px solid #e2e0da;padding:7px 10px;font-variant-numeric:tabular-nums}"
        . "tr.x td{color:#a2331f}";
    $rows = array(array('oakum',41,'0.037'),array('marl',12,'0.412'),array('scree',88,'1.004'),
      array('bittern',7,'0.219'),array('tallow',63,'0.884'),array('galena',29,'0.556'),
      array('withy',54,'0.301'),array('flux',96,'0.142'),array('sinter',3,'0.978'),array('pyx',41,'0.037'));
    $b = "<table><thead><tr><th data-k=0>name</th><th data-k=1>n</th><th data-k=2>v</th></tr></thead><tbody id=tb>";
    foreach ($rows as $i=>$r) $b .= "<tr" . ($i===9?" class=x":"") . "><td>{$r[0]}</td><td>{$r[1]}</td><td>{$r[2]}</td></tr>";
    $b .= "</tbody></table>";
    $js = <<<'JS'
var tb=document.getElementById('tb'),dir={};
document.querySelectorAll('th').forEach(function(th){th.addEventListener('click',function(){
var k=+th.dataset.k;dir[k]=!dir[k];
var rs=Array.from(tb.querySelectorAll('tr'));
var pin=rs.find(function(r){return r.classList.contains('x');});
rs=rs.filter(function(r){return r!==pin;});
rs.sort(function(a,b){var x=a.children[k].textContent,y=b.children[k].textContent;
var n=parseFloat(x),m=parseFloat(y);
var c=(!isNaN(n)&&!isNaN(m))?n-m:x.localeCompare(y);return dir[k]?c:-c;});
tb.innerHTML='';rs.forEach(function(r,i){if(i===rs.length-1&&pin)tb.appendChild(pin);tb.appendChild(r);});
if(pin&&!tb.contains(pin))tb.appendChild(pin);});});
JS;
    shell('column', $css, $b, $js, $mob);
}

/* ------------------------------------------------------------------ 12 well */
function ap_well($mob) {
    $css = "body{background:#0a0a0c;color:#4a4a52;font:" . ($mob?"13px":"13px") . "/2.4 monospace;margin:0}"
        . "#w{padding:40vh 8vw}#w div{letter-spacing:.1em}"
        . "#d{position:fixed;right:0;top:0;padding:12px 16px;font:11px monospace;color:#6a6a76;letter-spacing:.3em}";
    $b = "<div id=d>0.0 m</div><div id=w></div>";
    $js = <<<'JS'
var w=document.getElementById('w'),d=document.getElementById('d'),n=0;
var F=['drip','—','.','stone','·','water','','—','cold','.','·','','rope ends','·','.','—','','·'];
function add(){for(var i=0;i<40;i++){var e=document.createElement('div');
var t=F[Math.floor(Math.random()*F.length)];
e.textContent=t?(String(n).padStart(5,'0')+'   '+t):'';
e.style.opacity=Math.max(.08,1-n/900);e.style.paddingLeft=(Math.sin(n/9)*30+30)+'px';w.appendChild(e);n++;}
d.textContent=(n*0.31).toFixed(1)+' m';}
add();add();
window.addEventListener('scroll',function(){
if(window.innerHeight+window.scrollY>document.body.offsetHeight-1200)add();
d.textContent=((window.scrollY/24)*0.31+0.0).toFixed(1)+' m';});
JS;
    shell('well', $css, $b, $js, $mob);
}

/* --------------------------------------------------------------- 13 strings */
function ap_strings($mob) {
    $css = "body{background:#12100e;margin:0;overflow:hidden}svg{width:100vw;height:100vh;display:block;touch-action:none}"
        . "circle{cursor:grab}#i{position:fixed;left:0;right:0;bottom:38px;text-align:center;font:10px monospace;color:#4a423a;letter-spacing:.3em}";
    $b = "<svg id=s viewBox=\"0 0 100 100\" preserveAspectRatio=none><g id=ln></g><g id=nd></g></svg><div id=i>cut: click a line</div>";
    $js = <<<'JS'
var S=document.getElementById('s'),LN=document.getElementById('ln'),ND=document.getElementById('nd');
var N=[],L=[],NS='http://www.w3.org/2000/svg';
for(var i=0;i<11;i++)N.push({x:12+Math.random()*76,y:12+Math.random()*76});
for(var i=0;i<11;i++){L.push([i,(i+1)%11]);if(i%3===0)L.push([i,(i+5)%11]);}
var lel=L.map(function(){var l=document.createElementNS(NS,'line');l.setAttribute('stroke','#4a3f30');
l.setAttribute('stroke-width','.4');LN.appendChild(l);return l;});
var nel=N.map(function(p,i){var c=document.createElementNS(NS,'circle');c.setAttribute('r','1.8');
c.setAttribute('fill','#c8a44a');c.dataset.i=i;ND.appendChild(c);return c;});
function draw(){N.forEach(function(p,i){nel[i].setAttribute('cx',p.x);nel[i].setAttribute('cy',p.y);});
L.forEach(function(e,i){if(!lel[i])return;lel[i].setAttribute('x1',N[e[0]].x);lel[i].setAttribute('y1',N[e[0]].y);
lel[i].setAttribute('x2',N[e[1]].x);lel[i].setAttribute('y2',N[e[1]].y);});}
function relax(){for(var k=0;k<3;k++)L.forEach(function(e,i){if(!lel[i])return;
var a=N[e[0]],b=N[e[1]],dx=b.x-a.x,dy=b.y-a.y,d=Math.hypot(dx,dy)||1,f=(d-22)/d*0.06;
if(!a.f){a.x+=dx*f;a.y+=dy*f;}if(!b.f){b.x-=dx*f;b.y-=dy*f;}});
N.forEach(function(p){p.x=Math.max(3,Math.min(97,p.x));p.y=Math.max(3,Math.min(97,p.y));});
draw();requestAnimationFrame(relax);}
var drag=null;
function pt(e){var r=S.getBoundingClientRect();return{x:(e.clientX-r.left)/r.width*100,y:(e.clientY-r.top)/r.height*100};}
S.addEventListener('pointerdown',function(e){var c=e.target.closest('circle');
if(c){drag=+c.dataset.i;N[drag].f=1;S.setPointerCapture(e.pointerId);return;}
var l=e.target.closest('line');if(l){var i=lel.indexOf(l);if(i>=0){l.remove();lel[i]=null;}}});
S.addEventListener('pointermove',function(e){if(drag===null)return;var p=pt(e);N[drag].x=p.x;N[drag].y=p.y;});
S.addEventListener('pointerup',function(){if(drag!==null){N[drag].f=0;drag=null;}});
relax();
JS;
    shell('strings', $css, $b, $js, $mob);
}

/* -------------------------------------------------------------- 14 sequence */
function ap_steps($mob) {
    $cw = $mob ? 32 : 46;
    $css = "body{background:#14161a;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;gap:12px}"
        . "#g{display:grid;grid-template-columns:repeat(8,{$cw}px);gap:4px}"
        . "#g div{height:{$cw}px;background:#20242b;border:1px solid #2b313a;cursor:pointer}"
        . "#g div.on{background:#c8a44a;border-color:#e0bc63}#g div.hit{outline:2px solid #4be07a}"
        . "#tr{display:grid;grid-template-columns:repeat(8,{$cw}px);gap:4px}"
        . "#tr span{height:5px;background:#20242b}#tr span.p{background:#4be07a}";
    $b = "<div id=tr>"; for($i=0;$i<8;$i++) $b .= "<span></span>"; $b .= "</div><div id=g></div>";
    $js = <<<'JS'
var g=document.getElementById('g'),tr=document.getElementById('tr'),cells=[];
for(var r=0;r<4;r++)for(var c=0;c<8;c++){var d=document.createElement('div');d.dataset.r=r;d.dataset.c=c;g.appendChild(d);cells.push(d);}
g.addEventListener('click',function(e){var d=e.target.closest('div');if(d)d.classList.toggle('on');});
var step=0;
setInterval(function(){
tr.querySelectorAll('span').forEach(function(s,i){s.classList.toggle('p',i===step);});
cells.forEach(function(d){d.classList.toggle('hit',+d.dataset.c===step&&d.classList.contains('on'));});
step=(step+1)%8;},300);
JS;
    shell('sequence', $css, $b, $js, $mob);
}

/* ----------------------------------------------------------------- 15 faces */
function ap_pairs($mob) {
    $sz = $mob ? 68 : 88;
    $css = "body{background:#1d1a24;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;gap:14px}"
        . "#b{display:grid;grid-template-columns:repeat(4,{$sz}px);gap:6px}"
        . "#b div{height:{$sz}px;background:#2c2736;display:flex;align-items:center;justify-content:center;"
        . "font:" . ($mob?"24px":"30px") . " monospace;color:transparent;cursor:pointer;user-select:none;border:1px solid #383044}"
        . "#b div.up{background:#e7e2ee;color:#241f2c}#b div.go{background:#3a4a3a;color:#8fc08f;cursor:default}"
        . "#s{font:11px monospace;color:#6a6076;letter-spacing:.3em}";
    $b = "<div id=b></div><div id=s>0</div>";
    $js = <<<'JS'
var G=['†','§','¶','⊕','Ω','∫','△','∴'],a=G.concat(G),b=document.getElementById('b'),s=document.getElementById('s');
for(var i=a.length-1;i>0;i--){var j=Math.floor(Math.random()*(i+1)),t=a[i];a[i]=a[j];a[j]=t;}
var open=[],n=0,lock=false;
a.forEach(function(v,i){var d=document.createElement('div');d.textContent=v;d.dataset.v=v;b.appendChild(d);
d.addEventListener('click',function(){if(lock||d.classList.contains('up')||d.classList.contains('go'))return;
d.classList.add('up');open.push(d);
if(open.length===2){n++;s.textContent=n;lock=true;
if(open[0].dataset.v===open[1].dataset.v){open.forEach(function(o){o.classList.remove('up');o.classList.add('go');});
open=[];lock=false;if(b.querySelectorAll('.go').length===16){s.textContent=n+'  ·';s.style.color='#8fc08f';}}
else{setTimeout(function(){open.forEach(function(o){o.classList.remove('up');});open=[];lock=false;},620);}}});});
JS;
    shell('faces', $css, $b, $js, $mob);
}

/* --------------------------------------------------------------- 16 console */
function ap_term($mob) {
    $css = "body{background:#04060a;color:#4be08a;font:" . ($mob?"14px":"13px") . "/1.7 monospace;margin:0;padding:16px;min-height:100vh}"
        . "#o{white-space:pre-wrap;margin-bottom:8px}#l{display:flex;gap:8px}"
        . "input{flex:1;min-width:0;background:transparent;border:0;color:#8affb0;font:inherit;outline:none}";
    $b = "<div id=o>? for what is understood</div><div id=l><span>&gt;</span><input id=i autocomplete=off autocapitalize=off spellcheck=false></div>";
    $js = "var B='" . u() . "';" . <<<'JS'
var o=document.getElementById('o'),i=document.getElementById('i'),hist=[];
var R={'?':'ls  echo  sum <n..>  hex <s>  rev <s>  rand  time  drop  open <name>',
'ls':'dial grid tumbler wheel keyer scope pans slide stars mixer sort well strings steps pairs term'};
function p(t){hist.push(t);if(hist.length>18)hist.shift();o.textContent=hist.join('\n');}
i.addEventListener('keydown',function(e){if(e.key!=='Enter')return;
var v=i.value.trim();i.value='';if(!v)return;p('> '+v);
var a=v.split(/\s+/),c=a[0].toLowerCase(),r=a.slice(1);
if(R[c]!==undefined)p(R[c]);
else if(c==='echo')p(r.join(' '));
else if(c==='sum')p(String(r.reduce(function(s,x){return s+(parseFloat(x)||0);},0)));
else if(c==='hex')p(r.join(' ').split('').map(function(ch){return ch.charCodeAt(0).toString(16);}).join(' '));
else if(c==='rev')p(r.join(' ').split('').reverse().join(''));
else if(c==='rand')p(String(Math.floor(Math.random()*1e6)));
else if(c==='time')p(new Date().toISOString());
else if(c==='drop'){document.body.style.transition='transform 1.2s';document.body.style.transform='translateY(60vh)';
setTimeout(function(){location.href=B+'well';},900);}
else if(c==='open'&&r[0])location.href=B+r[0].replace(/[^a-z]/g,'');
else p('?');});
i.focus();document.addEventListener('click',function(){i.focus();});
JS;
    shell('console', $css, $b, $js, $mob);
}

/* ===========================================================================
 *  PLACES. every path is one. no two are laid out the same way and there is
 *  no list of them anywhere.
 * ========================================================================= */

define('EX_MIN', 12);
define('EX_MAX', 48);

/* the exits of a place, derived once and identically wherever they are needed */
function place_exits($path) {
    $r = rng(pseed('place:' . $path));
    $roll = ri($r, 0, 99);
    $n = ri($r, EX_MIN, EX_MAX);
    return array('roll' => $roll, 'ex' => exits($path, $r, $n), 'r' => $r);
}

function rng($seed) {
    $s = $seed & 0xffffffff;
    return function () use (&$s) {
        $s = ($s + 0x6D2B79F5) & 0xffffffff; $t = $s;
        $t = (($t ^ ($t >> 15)) * ($t | 1)) & 0xffffffff;
        $t = ($t ^ ($t + (($t ^ ($t >> 7)) * ($t | 61)))) & 0xffffffff;
        return (($t ^ ($t >> 14)) & 0xffffffff) / 4294967296.0;
    };
}
function ri($r, $a, $b) { return $a + (int) floor($r() * ($b - $a + 1)); }
function rp($r, $a) { return $a[(int) floor($r() * count($a)) % count($a)]; }

$WORDS = array('oakum','marl','scree','bittern','tallow','galena','withy','flux','sinter','pyx','quire','gnomon',
'spandrel','ferrule','oxbow','hoarfrost','claghole','swale','grommet','quicklime','fetch','coomb','lych','muntin',
'baffle','shroud','ingot','solder','cathode','anode','dross','borax','realgar','cinnabar','stannic','compline',
'matins','lauds','sext','terce','vigils','ember','rogation','vespers','ledger','tare','escheat','distraint',
'socage','corvee','leeward','offing','gloaming','murk','smother','haar','pother','damp','reredos','wainscot',
'soffit','plinth','corbel','voussoir','keystone','mullion','transom','assay','cupel','litharge','bloom','slag',
'matte','regulus','speiss','fettle','palimpsest','colophon','recto','verso','deckle','watermark','chainline',
'holloway','causey','strand','skerry','holm','carr','ness','spit','hollow','fenland','nadir','apsis','syzygy',
'umbra','penumbra','occultation','ingress','crepuscule','antemeridian','tithe','clinker','antimony','verdigris');

$GLYPH = array('†','§','¶','⊕','Ω','∫','△','∴','◊','¤','℥','☌','☍','⊘','⋈','∎','⌘','℈','♁','☿','⚲','⊟','⧉','⌇');

/* a great many surfaces. same room, different day, different skin. */
$PAL = array(
 array('#0b0b0d','#c8c4bc','#c8a44a','#26242a',"Georgia,serif"),
 array('#f4f1e8','#1c1a16','#8a2f1e','#d8d2c2',"'Times New Roman',serif"),
 array('#04060a','#4be08a','#e0b062','#0e2216',"'DejaVu Sans Mono',monospace"),
 array('#12233a','#cfe0ff','#7fd0ff','#24405f',"'Helvetica Neue',Arial,sans-serif"),
 array('#e8e4d8','#241f18','#5a6a34','#c4bda8',"Palatino,Georgia,serif"),
 array('#1a0f12','#e0c0c8','#d8506a','#3a1e26',"Georgia,serif"),
 array('#fbfbf7','#20242a','#2a5a8a','#dcdcd4',"'Helvetica Neue',Arial,sans-serif"),
 array('#0a0700','#e0a94a','#8fd0ff','#241a06',"'Courier New',monospace"),
 array('#151a14','#c4d4be','#8ac86a','#26301f',"'DejaVu Sans Mono',monospace"),
 array('#2a2118','#e8d8b8','#c86a2a','#443421',"'Book Antiqua',Georgia,serif"),
 array('#f0eef4','#241c2c','#6a3a9a','#d6d0e0',"Verdana,Geneva,sans-serif"),
 array('#06080c','#8fa0c8','#e8d9a0','#141a26',"Charter,Georgia,serif"),
 array('#fff8e8','#3a2a10','#a03020','#e8dcc0',"'Courier New',monospace"),
 array('#101418','#9ec8e0','#d8a24a','#1e2a34',"'Helvetica Neue',sans-serif"),
 array('#241d2c','#d8cde8','#9a7ad8','#372c44',"Georgia,serif"),
 array('#0d1a12','#a8d8b8','#e0e050','#1a2e20',"'DejaVu Sans Mono',monospace"),
 array('#e4e0d0','#2a2418','#7a1f1f','#ccc6b2',"'Hoefler Text',Baskerville,serif"),
 array('#0c0c0e','#b8b0a0','#8a8a96','#1e1e22',"'Segoe UI',system-ui,sans-serif"),
 array('#1c1410','#d8c0a0','#c05a2a','#2e231c',"Georgia,serif"),
 array('#f6f6f2','#141414','#0a6a4a','#dedede',"'Arial Narrow',Arial,sans-serif"),
 array('#08101a','#bcd8e8','#ff9a5a','#16283a',"'DejaVu Sans Mono',monospace"),
 array('#2c2c30','#e0e0e4','#f0d040','#3e3e44',"Impact,'Arial Black',sans-serif"),
 array('#fdf6ec','#2c1810','#1a5a7a','#e6dccc',"Palatino,serif"),
 array('#000','#d8d8d8','#ff2a4a','#1a1a1a',"'Arial Black',sans-serif"),
);

/* a step somewhere else. sometimes labelled, sometimes not. */
function exits($path, $r, $n) {
    global $WORDS, $APP, $GLYPH;
    $seg = $path === '' ? array() : explode('/', $path);
    $out = array();
    for ($i = 0; $i < $n; $i++) {
        $k = ri($r, 0, 99);
        if ($k < 8 && $APP) {                                  // an apparatus, unannounced
            $slugs = array_keys($APP);
            $s = $slugs[ri($r, 0, count($slugs) - 1)];
            $out[] = array(u($s), rp($r, array(rp($r,$GLYPH), (string) ri($r,2,97), rp($r,$WORDS), '·', '—')));
            continue;
        }
        if ($k < 13) {                                          // a shut door
            $z = rp($r, array('vault','attic','cellar','oubliette','strongroom','ossuary'));
            $out[] = array(u($z . '/' . rp($r, $WORDS)), rp($r, array('—', rp($r,$GLYPH), rp($r,$WORDS))));
            continue;
        }
        if ($k < 18 && count($seg) > 1) {                       // sideways, never back to the mouth
            $up = $seg; array_pop($up);
            $out[] = array(u(implode('/', $up) . '/' . rp($r, $WORDS) . ri($r,2,89)), rp($r, $WORDS));
            continue;
        }
        $mode = ri($r, 0, 5);
        if ($mode === 0)      $slug = rp($r, $WORDS);
        elseif ($mode === 1)  $slug = rp($r, $WORDS) . '-' . rp($r, $WORDS);
        elseif ($mode === 2)  $slug = (string) ri($r, 2, 9999);
        elseif ($mode === 3)  $slug = dechex(ri($r, 4096, 1048575));
        elseif ($mode === 4)  $slug = rp($r, $WORDS) . ri($r, 2, 97);
        else                  $slug = sprintf('%04d-%02d-%02d', ri($r,1961,2031), ri($r,1,12), ri($r,1,28));
        $out[] = array(u($path . ($path ? '/' : '') . $slug), $slug);
    }
    return $out;
}

/* links never look the same twice */
function lk($r, $href, $label, $acc) {
    switch (ri($r, 0, 9)) {
    case 0: return "<a href=\"" . h($href) . "\" style=\"color:$acc;text-decoration:none;border-bottom:1px solid $acc\">" . h($label) . "</a>";
    case 1: return "<a href=\"" . h($href) . "\" style=\"color:inherit;text-decoration:none\">[" . h($label) . "]</a>";
    case 2: return "<a href=\"" . h($href) . "\" style=\"color:$acc;text-decoration:none;border:1px solid $acc;padding:.15em .5em;display:inline-block\">" . h($label) . "</a>";
    case 3: return "<a href=\"" . h($href) . "\" style=\"color:inherit;text-decoration:none;background:$acc;padding:0 .3em\">" . h($label) . "</a>";
    case 4: return "<a href=\"" . h($href) . "\" style=\"color:inherit;text-decoration:none;opacity:.32\">" . h($label) . "</a>";
    case 5: return "<a href=\"" . h($href) . "\" style=\"color:$acc;text-decoration:underline wavy\">" . h($label) . "</a>";
    case 6: return "<a href=\"" . h($href) . "\" style=\"color:inherit;text-decoration:none;font-family:monospace;letter-spacing:.3em\">" . h($label) . "</a>";
    case 7: return "<a href=\"" . h($href) . "\" style=\"color:$acc;text-decoration:none;font-style:italic\">" . h($label) . "</a>";
    case 8: return "<a href=\"" . h($href) . "\" style=\"color:inherit;text-decoration:none\" title=\"\">" . h($label) . "<sup style=\"color:$acc\">·</sup></a>";
    default:return "<a href=\"" . h($href) . "\" style=\"color:$acc;text-decoration:none\">" . h($label) . "</a>";
    }
}

/* ===========================================================================
 *  MATTER. what is actually lying about in a place.
 * ========================================================================= */

$LINES = array(
'the count was taken twice and did not settle','reading held at 0.037 mV for the length of the shift',
'do not open before the frost has left the north face','ledger 4 continues in a hand that is not the clerk\'s',
'item withdrawn; the accession card remains','checksum agrees; the contents do not',
'moved to a room that is no longer on the plan','the barometer fell all night and no weather came',
'signed for by a name struck through twice','three of the eleven crates are heavier empty',
'measured at 41 mm along the fracture, both times','a smell of solder in a building with no power',
'return to sender: sender not established','the tide table lists a fourth tide',
'kept for reference; reference lost','annotation in the margin: again, and slower',
'logged out at 03:11 and back in at 03:11','stair sealed pending survey; survey pending stair',
'the plate is numbered 12 of 9','weight on arrival exceeds weight at dispatch by a hand',
'this entry supersedes an entry that was never made','the key fits and turns and the door does not know it',
'left running; nobody remembers to what end','the second witness could not be found to disagree',
'catalogued under a heading since removed','the drip is regular to the second and answers nothing',
'quiet on all channels except the disconnected one','a page torn out, the tear catalogued separately',
'the register skips from 88 to 90 and is complete','held for collection past the collector',
'the coordinate resolves to a wall','do not reconcile these figures; they were never apart',
'the lamp is warm and has not been lit','measured drift: one degree per year, toward nothing named',
'the interior is larger by the width of the door','stamped RECEIVED over a stamp that says RETURNED',
'the last line of the manifest is the manifest','sealed with the seal missing',
'not here. was moved. moved from where it is now.','the echo answers before the channel is opened',
'41 g dry, 41 g wet, 39 g held in the hand','nichts unter der oberfläche bewegt sich',
'quod mensum est bis, non convenit','the plate that follows 12 is 12',
'a door catalogued twice under two thicknesses','coordinate withheld; it resolves inward',
'carrier present; no traffic; the carrier is the traffic','return path exists and does not return',
'weighed on collection; the collector was included','the index disagrees with all it indexes',
'read the margin first; the margin was added last','the corridor is longer returning than going',
'the room grows by the door each frost','a hand struck the name and signed the striking',
'checksum recomputed; the file had recomputed first','the fourth prong is claimed by no particle',
'filed under a letter the alphabet no longer keeps','ended at 03:11; resumed at 03:11; the minute is missing',
);

$SPEC = array('nail, iron, hand-forged','plate, gunmetal','float, copper','staff, painted','bolt, set in rock',
'quire, unbound','sheet, foxed','key, brass, warm','lens, cracked','gauge, unmarked','rope, tarred',
'clamp, brass','vessel, stoppered','tile, glazed','core, drilled','wire, twisted','seal, broken',
'card, punched','disc, unlabelled','rod, graduated');

/* ---- one piece of matter. eighteen kinds. ------------------------------ */
function cblock($r, $pal, $mob, $i) {
    global $LINES, $SPEC, $WORDS, $GLYPH;
    list($bg,$ink,$acc,$rule,$font) = $pal;
    $k = ri($r, 0, 17);
    $pad = $mob ? '.9em' : '1.2em';

    switch ($k) {

    case 0: { /* a run of measurements */
        $o = "<table style=\"border-collapse:collapse;font:" . ($mob?"11px":"12px") . "/1.5 monospace;width:100%;margin:1em 0\">";
        $o .= "<tr style=\"opacity:.5\"><td>ch</td><td>t</td><td style=text-align:right>value</td><td>flag</td></tr>";
        for ($j=0;$j<ri($r,6,18);$j++) {
            $o .= "<tr><td style=\"padding:.1em .8em .1em 0;opacity:.55\">" . sprintf('%02d',ri($r,1,12)) . "</td>"
               . "<td style=\"padding-right:.8em;opacity:.55\">" . sprintf('%02d:%02d:%02d',ri($r,0,23),ri($r,0,59),ri($r,0,59)) . "</td>"
               . "<td style=\"text-align:right;padding-right:.8em\">" . rp($r,array(number_format($r()*100-50,3),'—','NaN','----',number_format($r()*9,4))) . "</td>"
               . "<td style=\"color:$acc\">" . rp($r,array('.','.','.','*','L','X','hold','')) . "</td></tr>";
        }
        return $o . "</table>"; }

    case 1: { /* a dump */
        $o = "<pre style=\"font:" . ($mob?"10.5px":"12px") . "/1.5 monospace;overflow-x:auto;opacity:.85;margin:1em 0;border-left:2px solid $rule;padding-left:.8em\">";
        $msg = rp($r,$LINES);
        $mi = 0;
        for ($ln=0;$ln<ri($r,5,14);$ln++) {
            $o .= sprintf('%08x  ', $ln*16 + ri($r,0,3)*4096);
            $asc='';
            for ($c=0;$c<16;$c++) {
                if ($ln>1 && $ln<6 && $mi<strlen($msg)) { $ch=$msg[$mi++]; $o.=sprintf('%02x ',ord($ch)); $asc.=h($ch); }
                else { $v=ri($r,0,255); $o.=sprintf('%02x ',$v); $asc .= ($v>31&&$v<127)?h(chr($v)):'.'; }
                if ($c===7) $o.=' ';
            }
            $o .= ' |' . $asc . "|\n";
        }
        return $o . "</pre>"; }

    case 2: { /* a plot */
        $w=$mob?300:520; $hh=$mob?110:150; $pts=''; $n=ri($r,14,40);
        $y=$hh/2;
        for($j=0;$j<$n;$j++){ $y += ($r()-0.5)*$hh*0.3; $y=max(6,min($hh-6,$y)); $pts .= ($j?' ':'') . round($j*$w/$n) . ',' . round($y); }
        return "<svg viewBox=\"0 0 $w $hh\" style=\"width:100%;height:auto;margin:1em 0;border:1px solid $rule\">"
           . "<line x1=0 y1=" . ($hh/2) . " x2=$w y2=" . ($hh/2) . " stroke=\"$rule\" stroke-width=.6 />"
           . "<polyline points=\"$pts\" fill=none stroke=\"$acc\" stroke-width=1.2 /></svg>"; }

    case 3: { /* a drawing of a thing */
        $w=$mob?280:420; $hh=$mob?180:250; $o='';
        for($j=0;$j<ri($r,4,10);$j++){
            $x=ri($r,10,$w-90); $y=ri($r,10,$hh-70); $ww=ri($r,30,90); $hgt=ri($r,20,60);
            $o .= "<rect x=$x y=$y width=$ww height=$hgt fill=none stroke=\"$ink\" stroke-width=.7 opacity=.7 />";
            if(ri($r,0,1)) $o .= "<line x1=$x y1=$y x2=" . ($x+$ww) . " y2=" . ($y+$hgt) . " stroke=\"$rule\" stroke-width=.5 />";
            $o .= "<text x=" . ($x+3) . " y=" . ($y-3) . " fill=\"$acc\" font-size=8 font-family=monospace>" . h(rp($r,$WORDS)) . "</text>";
        }
        for($j=0;$j<ri($r,2,6);$j++){ $y=ri($r,10,$hh-10);
            $o .= "<line x1=6 y1=$y x2=" . ($w-6) . " y2=$y stroke=\"$rule\" stroke-width=.4 stroke-dasharray='3 3' />"; }
        return "<svg viewBox=\"0 0 $w $hh\" style=\"width:100%;height:auto;margin:1em 0\">$o</svg>"; }

    case 4: { /* specimens */
        $o = "<div style=\"margin:1em 0;font-size:" . ($mob?"13px":"13px") . "\">";
        for($j=0;$j<ri($r,4,12);$j++)
            $o .= "<div style=\"padding:.28em 0;border-bottom:1px solid $rule;display:flex;gap:1em;justify-content:space-between\">"
               . "<span style=\"opacity:.5;font-family:monospace;font-size:11px\">" . sprintf('%04d.%03d',ri($r,1900,2039),ri($r,1,400)) . "</span>"
               . "<span style=\"flex:1\">" . h(rp($r,$SPEC)) . "</span>"
               . "<span style=\"opacity:.55;font-family:monospace;font-size:11px\">" . ri($r,3,900) . " mm</span></div>";
        return $o . "</div>"; }

    case 5: { /* a figure in characters */
        $rows=ri($r,5,11); $cols=$mob?26:44; $o='';
        for($y=0;$y<$rows;$y++){ $l='';
            for($x=0;$x<$cols;$x++){ $d=abs($x-$cols/2)/($cols/2)+abs($y-$rows/2)/($rows/2);
                $l .= $d < 0.5 ? rp($r,array('#','8','&','%')) : ($d<0.9 ? rp($r,array('+','=','-',':')) : rp($r,array(' ','.',' ',' '))); }
            $o .= $l . "\n"; }
        return "<pre style=\"font:" . ($mob?"9px":"11px") . "/1.05 monospace;color:$acc;margin:1em 0;overflow-x:auto\">" . h($o) . "</pre>"; }

    case 6: { /* a timetable */
        $o = "<table style=\"border-collapse:collapse;font-size:" . ($mob?"12px":"12.5px") . ";margin:1em 0;width:100%\">";
        for($j=0;$j<ri($r,5,13);$j++)
            $o .= "<tr><td style=\"padding:.2em .8em .2em 0;font-family:monospace;color:$acc\">" . sprintf('%02d:%02d',ri($r,0,23),ri($r,0,59)) . "</td>"
               . "<td style=\"padding:.2em .8em .2em 0\">" . h(rp($r,$WORDS)) . "</td>"
               . "<td style=\"opacity:.5;text-align:right\">" . rp($r,array('+'.ri($r,1,40),'—','on time','not run','—')) . "</td></tr>";
        return $o . "</table>"; }

    case 7: /* found text */
        return "<div style=\"margin:1.4em 0;padding:$pad;border-left:3px solid $acc;font-style:italic;font-size:"
           . ($mob?"15px":"15px") . ";line-height:1.85;opacity:.9\">&hellip;" . h(rp($r,$LINES)) . ", "
           . h(rp($r,$LINES)) . "&hellip;</div>";

    case 8: { /* notation */
        $o=''; for($j=0;$j<ri($r,3,7);$j++)
            $o .= "<div style=\"margin:.35em 0\">" . h(rp($r,$WORDS)) . "<sub style=\"opacity:.6\">" . ri($r,0,9) . "</sub> "
               . rp($r,array('=','≈','≠','→','⊢','∴')) . " " . number_format($r()*1000,ri($r,1,4))
               . " <span style=\"opacity:.5\">" . rp($r,array('mV','mm','hPa','g','s','—','counts/s')) . "</span></div>";
        return "<div style=\"font-family:monospace;font-size:" . ($mob?"12px":"13px") . ";margin:1em 0;padding:$pad;border:1px solid $rule\">$o</div>"; }

    case 9: { /* a small country */
        $w=$mob?280:420; $hh=$mob?160:220; $o=''; $prev=null;
        for($j=0;$j<ri($r,6,16);$j++){ $x=ri($r,8,$w-8); $y=ri($r,8,$hh-8);
            $o .= "<circle cx=$x cy=$y r=" . ri($r,1,3) . " fill=\"$acc\" />";
            if($prev && ri($r,0,2)) $o .= "<line x1={$prev[0]} y1={$prev[1]} x2=$x y2=$y stroke=\"$rule\" stroke-width=.5 />";
            if(ri($r,0,2)===0) $o .= "<text x=" . ($x+4) . " y=" . ($y-3) . " fill=\"$ink\" font-size=7 opacity=.6 font-family=monospace>" . h(rp($r,$WORDS)) . "</text>";
            $prev=array($x,$y); }
        return "<svg viewBox=\"0 0 $w $hh\" style=\"width:100%;height:auto;margin:1em 0;border:1px solid $rule\">$o</svg>"; }

    case 10: { /* bars */
        $n=ri($r,6,16); $w=$mob?300:480; $hh=$mob?120:160; $o=''; $bw=$w/$n;
        for($j=0;$j<$n;$j++){ $v=ri($r,6,$hh-10);
            $o .= "<rect x=" . round($j*$bw+1) . " y=" . ($hh-$v) . " width=" . round($bw-3) . " height=$v fill=\"" . (ri($r,0,6)?$rule:$acc) . "\" />"; }
        return "<svg viewBox=\"0 0 $w $hh\" style=\"width:100%;height:auto;margin:1em 0\">$o</svg>"; }

    case 11: { /* tallies */
        $o=''; $x=0; $w=$mob?300:460;
        while($x<$w-4){ $bw=ri($r,1,4); $o .= "<rect x=$x y=0 width=$bw height=40 fill=\"$ink\" />"; $x += $bw + ri($r,1,5); }
        return "<svg viewBox=\"0 0 $w 40\" style=\"width:100%;height:" . ($mob?"30px":"40px") . ";margin:1em 0;opacity:.8\">$o</svg>"
           . "<div style=\"font-family:monospace;font-size:10px;opacity:.45;letter-spacing:.3em\">" . strtoupper(dechex(ri($r,1048576,16777215))) . "</div>"; }

    case 12: /* marginalia */
        return "<div style=\"margin:1.2em 0;font-family:cursive,Georgia,serif;font-size:" . ($mob?"15px":"16px")
           . ";transform:rotate(" . (ri($r,-2,2)) . "deg);opacity:.8;color:$acc\">" . h(rp($r,$LINES)) . "</div>";

    case 13: { /* readouts */
        $o=''; for($j=0;$j<ri($r,3,8);$j++)
            $o .= "<div style=\"border:1px solid $rule;padding:" . ($mob?".6em":".8em") . ";min-width:" . ($mob?"78px":"96px") . "\">"
               . "<div style=\"font-size:9px;opacity:.45;letter-spacing:.2em;text-transform:uppercase\">" . h(rp($r,$WORDS)) . "</div>"
               . "<div style=\"font:" . ($mob?"18px":"22px") . " monospace;color:$acc\">" . rp($r,array(number_format($r()*99,2),ri($r,0,9999),'—','--.--')) . "</div></div>";
        return "<div style=\"display:flex;flex-wrap:wrap;gap:6px;margin:1em 0\">$o</div>"; }

    case 14: { /* a plate */
        $w=$mob?280:400; $hh=$mob?180:260; $o='';
        for($j=0;$j<ri($r,60,220);$j++)
            $o .= "<rect x=" . ri($r,0,$w) . " y=" . ri($r,0,$hh) . " width=" . ri($r,1,7) . " height=" . ri($r,1,7)
               . " fill=\"$ink\" opacity=\"" . round($r()*0.4,2) . "\" />";
        return "<figure style=\"margin:1.2em 0\"><svg viewBox=\"0 0 $w $hh\" style=\"width:100%;height:auto;background:$rule;display:block\">$o</svg>"
           . "<figcaption style=\"font-size:11px;opacity:.5;margin-top:.4em;font-family:monospace\">plate " . ri($r,1,120) . " of " . ri($r,1,9)
           . " &middot; exposure " . ri($r,1,90) . " s &middot; " . h(rp($r,$WORDS)) . "</figcaption></figure>"; }

    case 15: { /* inventory */
        $o = "<div style=\"display:grid;grid-template-columns:repeat(auto-fill,minmax(" . ($mob?"84px":"104px") . ",1fr));gap:4px;margin:1em 0\">";
        for($j=0;$j<ri($r,8,30);$j++)
            $o .= "<div style=\"border:1px solid $rule;padding:.5em;font-size:10px;font-family:monospace\">"
               . "<div style=\"color:$acc\">" . strtoupper(substr(dechex(ri($r,4096,65535)),0,4)) . "</div>"
               . "<div style=\"opacity:.55\">" . h(rp($r,$WORDS)) . "</div></div>";
        return $o . "</div>"; }

    case 16: { /* log excerpt */
        $o=''; for($j=0;$j<ri($r,5,16);$j++)
            $o .= sprintf('%04d-%02d-%02dT%02d:%02d:%02dZ  %-5s  ',ri($r,1961,2031),ri($r,1,12),ri($r,1,28),
                  ri($r,0,23),ri($r,0,59),ri($r,0,59),rp($r,array('INFO','WARN','HOLD','LOST','ECHO','----')))
               . rp($r,$LINES) . "\n";
        return "<pre style=\"font:" . ($mob?"10px":"11.5px") . "/1.7 monospace;overflow-x:auto;margin:1em 0;opacity:.8\">" . h($o) . "</pre>"; }

    default: { /* an outline that goes nowhere */
        $o=''; for($j=0;$j<ri($r,5,14);$j++){ $d=ri($r,0,4);
            $o .= "<div style=\"padding-left:" . ($d*($mob?14:22)) . "px;opacity:" . (1-$d*0.13) . ";font-size:"
               . ($mob?"13px":"13.5px") . ";padding-top:.15em\">" . str_repeat('&mdash;', max(0,$d)) . " "
               . h(rp($r,$WORDS)) . " <span style=\"opacity:.45;font-family:monospace;font-size:11px\">"
               . rp($r,array(ri($r,1,999),'—','?',ri($r,1,9).'/'.ri($r,1,9))) . "</span></div>"; }
        return "<div style=\"margin:1em 0\">$o</div>"; }
    }
}

/* a heap of matter for one place */
function matter($r, $pal, $mob, $n) {
    global $LINES;
    list($bg,$ink,$acc,$rule,$font) = $pal;
    $o = '';
    for ($i = 0; $i < $n; $i++) {
        if (ri($r,0,3) === 0)
            $o .= "<h2 style=\"font-weight:400;font-size:" . ($mob?"15px":"16px") . ";margin:1.6em 0 .3em;"
               . "letter-spacing:.14em;text-transform:uppercase;opacity:.55\">" . h(rp($r, $GLOBALS['WORDS'])) . " " . ri($r,2,99) . "</h2>";
        $o .= cblock($r, $pal, $mob, $i);
        if (ri($r,0,2) === 0)
            $o .= "<p style=\"margin:.7em 0;font-size:" . ($mob?"14px":"14px") . ";line-height:1.75;opacity:.78\">"
               . h(rp($r,$LINES)) . ". " . h(rp($r,$LINES)) . ".</p>";
    }
    return $o;
}

/* ===========================================================================
 *  2113 — the way to see where you are. it is not linked from anywhere.
 * ========================================================================= */
function menu($path, $mob) {
    global $APP, $WORDS;
    $seg = $path === '' ? array() : explode('/', $path);
    $me  = place_exits($path);
    $ex  = $me['ex'];

    /* siblings are the parent's exits */
    $sib = array();
    if ($seg) {
        $par = implode('/', array_slice($seg, 0, -1));
        $pe  = place_exits($par);
        $sib = $pe['ex'];
    }

    /* what each target actually turns out to be, without going there */
    $state = function ($href) {
        $p = trim(substr($href, strlen(smt_base())), '/');
        global $APP;
        $head = $p === '' ? '' : explode('/', $p)[0];
        if (isset($APP[$head])) return array('instrument', '#7ad8a8');
        if (in_array($head, array('vault','attic','cellar','oubliette','strongroom','ossuary'), true)) return array('403', '#c05a48');
        if ($p === '') return array('the way in', '#6a6a76');
        $q = place_exits($p);
        if ($q['roll'] < 5) return array('410', '#8a6a3a');
        if ($q['roll'] < 8) return array('403', '#c05a48');
        return array(count($q['ex']) . ' out', '#6a6a76');
    };

    $c = "html,body{margin:0}body{background:#0b0b0c;color:#d8d8dc;font:" . ($mob?"14px":"13px")
       . "/1.65 'DejaVu Sans Mono',Menlo,monospace;padding:" . ($mob?"1.1rem .9rem 3rem":"2.4rem 2.6rem") . "}"
       . "h1{font:400 " . ($mob?"15px":"14px") . "/1.4 monospace;letter-spacing:.34em;text-transform:uppercase;color:#7ad8a8;margin:0 0 1.6em}"
       . "h2{font:400 11px/1.4 monospace;letter-spacing:.28em;text-transform:uppercase;color:#6a6a76;margin:2.2em 0 .6em;"
       . "border-bottom:1px solid #23232a;padding-bottom:.4em}"
       . "a{color:#d8d8dc;text-decoration:none;display:block;padding:" . ($mob?".62em .5em":".3em .5em") . ";border-bottom:1px solid #17171c}"
       . "a:hover{background:#16161c;color:#7ad8a8}.p{color:#5a5a66}.t{color:#c8a44a}"
       . "ul{list-style:none;margin:0;padding:0}.g{display:grid;grid-template-columns:repeat(auto-fill,minmax("
       . ($mob?"140px":"180px") . ",1fr));gap:0 14px}"
       . ".c{color:#6a6a76;font-size:11px;margin:0 0 1.4em;line-height:1.8}";

    $b = "<h1>2113</h1>";
    $b .= "<div class=c>you are at <span class=t>/" . h($path) . "</span><br>"
       . count($ex) . " ways out of here &middot; " . count($sib) . " beside it &middot; depth " . count($seg) . "<br>"
       . "this listing is not reachable from any page. append <span class=t>?=2113</span> to any path.</div>";

    /* ancestors */
    if ($seg) {
        $b .= "<h2>above</h2><ul>";
        $acc2 = '';
        $b .= "<li><a href=\"" . h(u()) . "?=2113\"><span class=p>/</span></a></li>";
        foreach ($seg as $i => $s) {
            $acc2 .= ($acc2 === '' ? '' : '/') . $s;
            $b .= "<li><a href=\"" . h(u($acc2)) . "?=2113\"><span class=p>" . str_repeat('· ', $i+1) . "</span>" . h($s) . "</a></li>";
        }
        $b .= "</ul>";
    }

    $b .= "<h2>from here (" . count($ex) . ")</h2><ul class=g>";
    foreach ($ex as $e) {
        list($lab, $col) = $state($e[0]);
        $b .= "<li><a href=\"" . h($e[0]) . "?=2113\">" . h($e[1] === '' ? '—' : $e[1])
           . "<span class=p style=\"display:block;font-size:10px;overflow:hidden;text-overflow:ellipsis\">" . h($e[0])
           . "</span><span style=\"display:block;font-size:10px;color:$col\">" . h($lab) . "</span></a></li>";
    }
    $b .= "</ul>";

    if ($sib) {
        $b .= "<h2>beside it (" . count($sib) . ")</h2><ul class=g>";
        foreach ($sib as $e) {
            list($lab, $col) = $state($e[0]);
            $b .= "<li><a href=\"" . h($e[0]) . "?=2113\">" . h($e[1] === '' ? '—' : $e[1])
               . "<span class=p style=\"display:block;font-size:10px;overflow:hidden;text-overflow:ellipsis\">" . h($e[0])
               . "</span><span style=\"display:block;font-size:10px;color:$col\">" . h($lab) . "</span></a></li>";
        }
        $b .= "</ul>";
    }

    $b .= "<h2>the instruments (" . count($APP) . ")</h2><ul class=g>";
    foreach ($APP as $s => $l)
        $b .= "<li><a href=\"" . h(u($s)) . "\">" . h($l) . "<span class=p style=\"display:block;font-size:10px\">/" . h($s) . "</span></a></li>";
    $b .= "</ul>";

    $b .= "<h2>shut</h2><ul class=g>";
    foreach (array('vault','attic','cellar','oubliette','strongroom','ossuary') as $z)
        $b .= "<li><a href=\"" . h(u($z)) . "\">" . h($z) . "<span class=p style=\"display:block;font-size:10px\">403</span></a></li>";
    $b .= "</ul>";

    $b .= "<h2>elsewhere</h2><ul class=g>";
    foreach (array_slice($WORDS, 0, 24) as $w)
        $b .= "<li><a href=\"" . h(u($w)) . "?=2113\">" . h($w) . "</a></li>";
    $b .= "</ul>";

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo "<!doctype html><html lang=en><head><meta charset=utf-8>"
       . "<meta name=viewport content=\"width=device-width,initial-scale=1\">"
       . "<meta name=robots content=\"noindex,nofollow\"><title>2113 · /" . h($path) . "</title>"
       . "<style>$c</style></head><body>$b" . keeper() . "</body></html>";
}

/* ---- the twenty ways a place can be put together ---------------------- */
function place($path, $mob) {
    global $PAL, $WORDS, $GLYPH;
    $seed = pseed('place:' . $path);
    $r    = rng($seed);
    $pal  = $PAL[$seed % count($PAL)];
    list($bg, $ink, $acc, $rule, $font) = $pal;
    $arch = ($seed >> 5) % 20;

    /* some places are not places */
    $roll = ri($r, 0, 99);
    if ($path !== '' && $roll < 5)  { gone($path, $mob); return; }
    if ($path !== '' && $roll < 8)  { sealed($path, $mob); return; }

    $n  = ri($r, EX_MIN, EX_MAX);
    $ex = exits($path, $r, $n);
    $heap = matter($r, $pal, $mob, $path === '' ? ri($r, 18, 30) : ri($r, 10, 26));
    $css = "html,body{margin:0}body{background:$bg;color:$ink;font-family:$font;min-height:100vh;"
         . "font-size:" . ($mob ? "16px" : "15px") . ";line-height:1.6;overflow-x:hidden}a{color:$acc}";
    $b = '';

    switch ($arch) {

    case 0: /* cards thrown down */
        $css .= "#d{position:relative;min-height:100vh}.c{position:absolute;background:$bg;border:1px solid $rule;"
              . "padding:.7em .9em;box-shadow:0 6px 24px #0007;font-size:" . ($mob?"13px":"14px") . "}";
        $b = "<div id=d>";
        foreach ($ex as $i => $e) {
            $x = ri($r, 1, 78); $y = ri($r, 1, 88); $rot = ri($r, -14, 14);
            $b .= "<div class=c style=\"left:{$x}%;top:{$y}vh;transform:rotate({$rot}deg)\">" . lk($r,$e[0],$e[1],$acc) . "</div>";
        }
        $b .= "</div>"; break;

    case 1: /* a listing that is not the listing */
        $css .= "pre{padding:" . ($mob?"1em":"2.4em") . ";font-family:monospace;font-size:" . ($mob?"12px":"13px") . ";line-height:1.9}";
        $b = "<pre>Index of /" . h($path) . "\n\n";
        foreach ($ex as $e) $b .= str_pad('', ri($r,0,3)) . lk($r,$e[0],$e[1],$acc)
             . str_repeat(' ', max(1, 26 - strlen($e[1]))) . sprintf('%02d-%s-%04d  %7s', ri($r,1,28),
               rp($r,array('Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec')), ri($r,1961,2031),
               rp($r,array('-', number_format(ri($r,12,940000)), '?'))) . "\n";
        $b .= "</pre>"; break;

    case 2: /* a form */
        $css .= "form{padding:" . ($mob?"1.2em":"3em") . ";max-width:34em}fieldset{border:1px solid $rule;margin:0 0 1.2em;padding:1em}"
              . "legend{font-size:11px;letter-spacing:.3em;text-transform:uppercase;opacity:.6}"
              . "label{display:block;font-size:12px;opacity:.7;margin:.7em 0 .2em}"
              . "input,select{width:100%;padding:.5em;background:transparent;border:1px solid $rule;color:$ink;font:inherit}"
              . "button{margin-top:1em;padding:.7em 1.6em;background:$acc;color:$bg;border:0;font:inherit;cursor:pointer}";
        $b = "<form method=get action=\"" . h($ex[0][0]) . "\"><fieldset><legend>" . h(rp($r,$WORDS)) . "</legend>";
        for ($i = 0; $i < ri($r,3,7); $i++) {
            $b .= "<label>" . h(rp($r,$WORDS)) . " " . ri($r,2,99) . "</label>";
            $b .= ri($r,0,3) ? "<input name=f$i value=\"" . h(ri($r,0,1)?'':rp($r,$WORDS)) . "\">"
                             : "<select name=f$i><option>" . h(rp($r,$WORDS)) . "<option>" . h(rp($r,$WORDS)) . "</select>";
        }
        $b .= "<button>" . h(rp($r,array('proceed','enter','submit','—','go on'))) . "</button></fieldset>";
        foreach (array_slice($ex,1,6) as $e) $b .= "<div style=\"margin:.3em 0;font-size:12px\">" . lk($r,$e[0],$e[1],$acc) . "</div>";
        $b .= "</form>"; break;

    case 3: /* a wall of them */
        $css .= "#w{display:flex;flex-wrap:wrap;gap:2px;padding:" . ($mob?"10px":"26px") . "}"
              . "#w a{width:" . ($mob?"32px":"38px") . ";height:" . ($mob?"32px":"38px") . ";display:flex;align-items:center;"
              . "justify-content:center;border:1px solid $rule;font-size:11px;text-decoration:none;color:$ink}"
              . "#w a:hover{background:$acc;color:$bg}";
        $b = "<div id=w>";
        for ($i = 0; $i < ri($r, 60, 190); $i++) {
            $e = $ex[$i % count($ex)];
            $b .= "<a href=\"" . h($e[0]) . "\">" . h(rp($r, array((string)ri($r,0,99), rp($r,$GLYPH), strtoupper(substr($e[1],0,2))))) . "</a>";
        }
        $b .= "</div>"; break;

    case 4: /* strips that will not hold still */
        $css .= ".m{overflow:hidden;white-space:nowrap;border-bottom:1px solid $rule;padding:.6em 0}"
              . ".m span{display:inline-block;animation:s linear infinite}"
              . "@keyframes s{from{transform:translateX(0)}to{transform:translateX(-50%)}}";
        for ($k = 0; $k < ri($r,5,11); $k++) {
            $d = ri($r, 14, 60); $inner = '';
            for ($j = 0; $j < 14; $j++) { $e = $ex[ri($r,0,count($ex)-1)]; $inner .= "&nbsp;&nbsp;" . lk($r,$e[0],$e[1],$acc) . "&nbsp;&nbsp;" . rp($r,$GLYPH); }
            $b .= "<div class=m style=\"font-size:" . ri($r,12,30) . "px\"><span style=\"animation-duration:{$d}s\">$inner$inner</span></div>";
        }
        break;

    case 5: /* a spiral */
        $css .= "#s{position:relative;height:100vh;overflow:hidden}#s a{position:absolute;text-decoration:none;color:$ink;white-space:nowrap}";
        $b = "<div id=s>";
        foreach ($ex as $i => $e) {
            $a = $i * 0.72; $rad = 4 + $i * 2.6;
            $x = 50 + cos($a) * $rad; $y = 50 + sin($a) * $rad * 0.86;
            $b .= "<a href=\"" . h($e[0]) . "\" style=\"left:{$x}%;top:{$y}%;font-size:" . (9 + $i % 12) . "px;"
               . "transform:rotate(" . round($a * 57.3) . "deg);opacity:" . (1 - $i * 0.02) . "\">" . h($e[1]) . "</a>";
        }
        $b .= "</div>"; break;

    case 6: /* columns */
        $css .= "#c{column-count:" . ($mob?2:ri($r,3,5)) . ";column-gap:2em;padding:" . ($mob?"1.2em":"3em") . ";text-align:justify;font-size:" . ($mob?"13px":"13.5px") . "}";
        $b = "<div id=c>";
        foreach ($ex as $e) $b .= rp($r,$WORDS) . " " . ri($r,2,900) . " " . lk($r,$e[0],$e[1],$acc) . " " . rp($r,$WORDS) . ", "
             . ri($r,2,99) . " " . rp($r,$GLYPH) . " ";
        $b .= "</div>"; break;

    case 7: /* boxes inside boxes */
        $css .= ".f{border:1px solid $rule;padding:" . ($mob?"10px":"18px") . ";margin:0}";
        $inner = '';
        foreach ($ex as $e) $inner .= "<div style=\"margin:.25em 0\">" . lk($r,$e[0],$e[1],$acc) . "</div>";
        $b = $inner;
        for ($k = 0; $k < ri($r,3,7); $k++) $b = "<div class=f>" . ($k===1?$inner:'') . $b . "</div>";
        break;

    case 8: /* a country */
        $css .= "svg{display:block;width:100vw;height:100vh}path,circle{cursor:pointer}text{font-size:3px;fill:$ink}";
        $b = "<svg viewBox='0 0 100 100' preserveAspectRatio=none>";
        foreach ($ex as $i => $e) {
            $cx = ri($r,8,92); $cy = ri($r,8,92); $rr = ri($r,3,13);
            $b .= "<a href=\"" . h($e[0]) . "\"><circle cx=$cx cy=$cy r=$rr fill=\"$rule\" stroke=\"$acc\" stroke-width=.25 />"
               . "<text x=$cx y=" . ($cy + $rr + 3) . " text-anchor=middle>" . h($e[1]) . "</text></a>";
        }
        for ($i=0;$i<ri($r,4,12);$i++) $b .= "<line x1=" . ri($r,0,100) . " y1=" . ri($r,0,100) . " x2=" . ri($r,0,100)
             . " y2=" . ri($r,0,100) . " stroke=\"$rule\" stroke-width=.15 />";
        $b .= "</svg>"; break;

    case 9: /* a session */
        $css .= "pre{padding:" . ($mob?"1em":"2.4em") . ";font-family:monospace;font-size:" . ($mob?"12.5px":"13px") . ";line-height:1.8;white-space:pre-wrap}";
        $b = "<pre>";
        foreach ($ex as $e) $b .= "$ " . rp($r,array('open','read','stat','walk','list','get')) . " " . h($e[1]) . "\n  "
             . rp($r,array('ok','?','—','' . ri($r,100,999),'no')) . "  " . lk($r,$e[0],$e[1],$acc) . "\n";
        $b .= "$ </pre>"; break;

    case 10: /* a table */
        $css .= "table{border-collapse:collapse;margin:" . ($mob?"1em":"3em") . ";font-size:" . ($mob?"12px":"13px") . "}"
              . "td{border-bottom:1px solid $rule;padding:.3em 1.2em .3em 0}";
        $b = "<table>";
        foreach ($ex as $i => $e) $b .= "<tr><td style=\"opacity:.45\">" . str_pad((string)$i,3,'0',STR_PAD_LEFT) . "</td><td>"
             . lk($r,$e[0],$e[1],$acc) . "</td><td style=\"opacity:.45\">" . number_format(ri($r,1,9400000)) . "</td><td style=\"opacity:.45\">"
             . rp($r,$GLYPH) . "</td></tr>";
        $b .= "</table>"; break;

    case 11: /* one word */
        $css .= "#p{display:flex;align-items:center;justify-content:center;min-height:100vh;flex-direction:column}"
              . "#p b{font-size:" . ($mob?"48px":"110px") . ";font-weight:400;letter-spacing:-.03em;line-height:1}"
              . "#p div{margin-top:2.4em;font-size:11px;letter-spacing:.4em;opacity:.5}";
        $w = rp($r,$WORDS);
        $b = "<div id=p><b>" . lk($r,$ex[0][0],$w,$acc) . "</b><div>";
        foreach (array_slice($ex,1,ri($r,2,6)) as $e) $b .= lk($r,$e[0],$e[1],$acc) . " &nbsp; ";
        $b .= "</div></div>"; break;

    case 12: /* bands */
        foreach ($ex as $i => $e) {
            $p2 = $PAL[($seed + $i * 7) % count($PAL)];
            $b .= "<div style=\"background:{$p2[0]};color:{$p2[1]};font-family:{$p2[4]};padding:" . ri($r,8,54) . "px "
               . ($mob?12:40) . "px;font-size:" . ri($r,12,34) . "px\">" . lk($r,$e[0],$e[1],$p2[2]) . "</div>";
        }
        break;

    case 13: /* a ring of ways out */
        $css .= "#o{position:relative;height:100vh}#o a{position:absolute;text-decoration:none;color:$ink;transform-origin:center}";
        $b = "<div id=o>";
        foreach ($ex as $i => $e) {
            $a = $i * 6.2832 / count($ex);
            $x = 50 + cos($a) * ri($r,26,44); $y = 50 + sin($a) * ri($r,24,42);
            $b .= "<a href=\"" . h($e[0]) . "\" style=\"left:{$x}%;top:{$y}%;font-size:" . ri($r,10,22) . "px\">" . h($e[1]) . "</a>";
        }
        $b .= "</div>"; break;

    case 14: /* noise, with things in it */
        $css .= "#n{padding:" . ($mob?"1em":"2.4em") . ";font-family:monospace;font-size:" . ($mob?"12px":"13px")
              . ";line-height:1.5;word-break:break-all;opacity:.9}";
        $b = "<div id=n>";
        $k = 0;
        for ($i = 0; $i < ri($r, 900, 2600); $i++) {
            if ($i % ri($r,80,190) === 0 && $k < count($ex)) { $b .= " " . lk($r,$ex[$k][0],$ex[$k][1],$acc) . " "; $k++; }
            else $b .= rp($r, array('0','1','·','.',' ','x','—','/','\\','|',rp($r,$GLYPH)));
        }
        $b .= "</div>"; break;

    case 15: /* a panel */
        $css .= "#pl{display:grid;grid-template-columns:repeat(auto-fill,minmax(" . ($mob?"120px":"170px") . ",1fr));gap:1px;background:$rule;padding:1px}"
              . ".s{background:$bg;padding:" . ($mob?"14px":"20px") . ";display:flex;align-items:center;justify-content:space-between;gap:10px}"
              . ".s i{width:34px;height:18px;border:1px solid $rule;position:relative;flex:none}"
              . ".s i:after{content:'';position:absolute;top:1px;width:14px;height:14px;background:$acc}"
              . ".s.b i:after{right:1px}.s.a i:after{left:1px}";
        $b = "<div id=pl>";
        foreach ($ex as $e) $b .= "<div class=\"s " . (ri($r,0,1)?'a':'b') . "\"><span style=\"font-size:12px\">"
             . lk($r,$e[0],$e[1],$acc) . "</span><i></i></div>";
        $b .= "</div>"; break;

    case 16: /* going down */
        $css .= "#st{padding:" . ($mob?"1.2em":"3em") . "}#st div{white-space:nowrap}";
        $b = "<div id=st>";
        foreach ($ex as $i => $e) $b .= "<div style=\"padding-left:" . ($i * ri($r,10,26)) . "px;font-size:"
             . max(9, 22 - $i) . "px;opacity:" . max(0.25, 1 - $i * 0.045) . "\">" . lk($r,$e[0],$e[1],$acc) . "</div>";
        $b .= "</div>"; break;

    case 17: /* almost nothing */
        $css .= "#e{display:flex;align-items:center;justify-content:center;min-height:100vh}"
              . "#e a{width:" . ri($r,4,14) . "px;height:" . ri($r,4,14) . "px;background:$acc;display:block;border-radius:50%}";
        $b = "<div id=e><a href=\"" . h($ex[0][0]) . "\"></a></div>";
        /* it looks empty. it is not a dead end. */
        $spots = array('left:8px;top:8px','right:8px;top:8px','left:8px;bottom:8px','right:8px;bottom:8px',
                       'left:50%;top:6px','left:6px;top:50%','right:6px;top:50%','left:50%;bottom:16px',
                       'left:24%;top:30%','right:22%;bottom:28%','left:70%;top:18%','left:16%;bottom:34%');
        foreach (array_slice($ex, 1, 11) as $j => $e)
            $b .= "<a href=\"" . h($e[0]) . "\" style=\"position:fixed;" . $spots[$j % count($spots)]
               . ";font:9px monospace;color:$ink;opacity:" . (0.12 + ($j % 5) * 0.06)
               . ";text-decoration:none;letter-spacing:.2em\">" . h($e[1]) . "</a>";
        break;

    case 18: /* dense mosaic */
        $css .= "#mo{display:grid;grid-template-columns:repeat(" . ($mob?4:ri($r,6,12)) . ",1fr);grid-auto-rows:"
              . ($mob?"56px":"70px") . ";gap:1px;background:$rule}"
              . "#mo a{background:$bg;display:flex;align-items:center;justify-content:center;text-decoration:none;color:$ink;font-size:11px;padding:4px;text-align:center}";
        $b = "<div id=mo>";
        foreach ($ex as $e) {
            $cs = ri($r,1,3); $rs = ri($r,1,2);
            $b .= "<a href=\"" . h($e[0]) . "\" style=\"grid-column:span $cs;grid-row:span $rs\">" . h($e[1]) . "</a>";
        }
        $b .= "</div>"; break;

    default: /* a slow fall */
        $css .= "#t{height:100vh;overflow:hidden;position:relative}#t div{position:absolute;left:0;right:0;text-align:center;"
              . "animation:f linear infinite}@keyframes f{from{top:100vh}to{top:-20vh}}";
        $b = "<div id=t>";
        foreach ($ex as $i => $e) $b .= "<div style=\"animation-duration:" . ri($r,12,44) . "s;animation-delay:-" . ri($r,0,30)
             . "s;font-size:" . ri($r,12,30) . "px\">" . lk($r,$e[0],$e[1],$acc) . "</div>";
        $b .= "</div>";
    }

    /* the matter and the ways out are not arranged the same way twice */
    $wrap = "padding:" . ($mob ? "1.1rem .9rem 4rem" : "2.6rem 3rem 5rem") . ";max-width:"
          . ($mob ? "none" : rp($r, array('46em','60em','none','38em','72em'))) . ";margin:0 auto";
    $mat  = "<div style=\"$wrap\">" . $heap . "</div>";
    switch (ri($r, 0, 5)) {
    case 0: $b = $mat . $b; break;
    case 1: $b = $b . $mat; break;
    case 2: $b = "<div style=\"display:flex;flex-wrap:wrap;align-items:flex-start\">"
               . "<div style=\"flex:1 1 " . ($mob ? "100%" : "50%") . ";min-width:0\">" . $b . "</div>"
               . "<div style=\"flex:1 1 " . ($mob ? "100%" : "44%") . ";min-width:0\">" . $mat . "</div></div>"; break;
    case 3: $half = matter($r, $pal, $mob, ri($r, 5, 13));
            $b = "<div style=\"$wrap\">" . $half . "</div>" . $b . $mat; break;
    case 4: $b = $mat . $b . "<div style=\"$wrap\">" . matter($r, $pal, $mob, ri($r, 6, 16)) . "</div>"; break;
    default: $b = $b . $mat;
    }

    /* a way back, but not always, and never in the same corner */
    if (ri($r,0,100) < 38 && $path !== '' && substr_count($path, '/') >= 1) {
        $seg = explode('/', $path); array_pop($seg);
        $pos = rp($r, array('left:6px;bottom:6px','right:6px;bottom:6px','left:6px;top:6px','right:6px;top:6px',
                            'left:50%;bottom:4px','right:14px;top:44%'));
        $b .= "<a href=\"" . h(u(implode('/', $seg))) . "\" style=\"position:fixed;$pos;font:9px monospace;"
           . "color:$ink;opacity:.3;text-decoration:none;letter-spacing:.2em;z-index:80\">"
           . rp($r, array('&lt;','·','—','back','^','[]')) . "</a>";
    }

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html lang=und><head><meta charset=utf-8>"
       . "<meta name=viewport content=\"width=device-width,initial-scale=1,viewport-fit=cover\">"
       . "<meta name=robots content=\"noindex,nofollow\"><title>"
       . h(rp($r, array($path === '' ? 'smtstrange' : $path, rp($r,$WORDS), (string) ri($r,100,99999), rp($r,$GLYPH), '·'))) . "</title>"
       . "<style>$css</style></head><body>$b" . keeper() . "</body></html>";
}

function gone($path, $mob) {
    $r = rng(pseed('gone:' . $path));
    $ex = exits($path, $r, ri($r,7,18));
    $b = '';
    foreach ($ex as $e) $b .= "<span style=\"display:inline-block;margin:0 1em .6em 0\">"
        . lk($r, $e[0], $e[1], '#8a7a52') . "</span>";
    http_response_code(410);
    echo "<!doctype html><html><head><meta charset=utf-8><meta name=viewport content=\"width=device-width,initial-scale=1\">"
       . "<title>410</title><style>html,body{margin:0}body{background:#121110;color:#4a453c;font:13px/1.9 monospace;"
       . "display:flex;align-items:center;justify-content:center;min-height:100vh;flex-direction:column}</style></head><body>"
       . "<div style=\"opacity:.5;margin-bottom:1.4em\">410 &middot; /" . h($path) . "</div>"
       . "<div style=\"max-width:40em;text-align:center\">$b</div>" . keeper() . "</body></html>";
}

/* the one door that does not open. it is still not a dead end. */
function sealed($p, $mob) {
    $r = rng(pseed('sealed' . $p));
    $rows = '';
    for ($i = 0; $i < ri($r, 8, 26); $i++)
        $rows .= "<tr><td>" . strtoupper(base_convert((string) ri($r, 60466, 60466175), 10, 36)) . "</td>"
              . "<td style=\"text-align:right;opacity:.6\">" . number_format(ri($r, 12, 9400000)) . "</td>"
              . "<td style=\"opacity:.45\">" . rp($r, array('held','held','retained','—','sealed')) . "</td></tr>";
    $ex = exits($p, $r, ri($r, 9, 22));
    $ways = '';
    foreach ($ex as $e) $ways .= "<span style=\"display:inline-block;margin:0 1.1em .5em 0\">"
        . lk($r, $e[0], $e[1], '#c08a6a') . "</span>";
    $css = "html,body{margin:0}body{background:#0d0b0b;color:#8a7a72;font:" . ($mob?"14px":"13px")
         . "/1.75 monospace;padding:" . ($mob?"1.1rem .9rem 3rem":"2.6rem 3rem") . "}"
         . "h1{font:400 " . ($mob?"20px":"22px") . " monospace;color:#c05a48;letter-spacing:.1em;margin:0 0 1em}"
         . "table{border-collapse:collapse;margin:1.4em 0;width:100%;max-width:44em;font-size:" . ($mob?"11px":"12px") . "}"
         . "td{padding:.15em 1.4em .15em 0;border-bottom:1px solid #1e1a1a}"
         . "h2{font:400 11px monospace;letter-spacing:.3em;text-transform:uppercase;opacity:.4;margin:2.4em 0 .8em}";
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html lang=und><head><meta charset=utf-8>"
       . "<meta name=viewport content=\"width=device-width,initial-scale=1\">"
       . "<meta name=robots content=\"noindex,nofollow\"><title>403</title><style>$css</style></head><body>"
       . "<h1>403</h1><div style=\"opacity:.55\">/" . h($p) . " &mdash; the mass is measured. the door is not.</div>"
       . "<table>$rows</table>"
       . "<h2>not through here</h2><div>$ways</div>"
       . keeper() . "</body></html>";
}

/* ===========================================================================
 *  ROUTER
 * ========================================================================= */
$mob  = smt_mobile();
$path = smt_path();
$seg  = $path === '' ? array() : explode('/', $path);
$head = $seg[0] ?? '';

/* the way to see where you are. ?=2113 on any path. PHP drops empty
   parameter names, so the query string is read as it arrived. */
$qs = $_SERVER['QUERY_STRING'] ?? '';
if ($qs !== '' && preg_match('/(^|[?&=])2113($|&)/', $qs)) { menu($path, $mob); exit; }

if ($path === 'favicon.ico') { http_response_code(204); exit; }
if ($path === 'index.html' || $path === 'index.htm') { header('Location: ' . u(), true, 302); exit; }
if ($path === 'robots.txt') { header('Content-Type: text/plain'); echo "User-agent: *\nDisallow: /\n# there is no index of this\n"; exit; }
if (in_array($head, array('vault','attic','cellar','oubliette','strongroom','ossuary'), true)) { sealed($path, $mob); exit; }

/* an apparatus, if the path happens to name one */
if (isset($APP[$head]) && count($seg) === 1) {
    $fn = 'ap_' . $head;
    if (function_exists($fn)) { $fn($mob); exit; }
}

/* everything else, including the way in, is a place */
place($path, $mob);
