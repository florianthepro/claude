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
       . "<a class=\"bk\" href=\"" . h(u()) . "\">&lt;</a>"
       . ($js !== '' ? "<script>" . $js . "</script>" : "") . "</body></html>";
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
    $r = fnv('stars');
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
 *  THE ROOM — doorways, each drawn in its own hand
 * ========================================================================= */
function front($mob) {
    global $APP;
    $css = "body{background:#0c0c0e;margin:0;min-height:100vh}"
        . "#r{display:grid;grid-template-columns:repeat(auto-fill,minmax(" . ($mob?"140px":"190px") . ",1fr));gap:1px;background:#17171b}"
        . "a{display:block;position:relative;aspect-ratio:1;text-decoration:none;overflow:hidden;background:#0c0c0e}"
        . "a span{position:absolute;left:10px;bottom:8px;font:10px monospace;letter-spacing:.22em;color:#6a6a72;z-index:2}"
        . "a:hover span{color:#e0e0e8}svg{position:absolute;inset:0;width:100%;height:100%}"
        . "#h{position:fixed;right:10px;top:8px;font:10px monospace;color:#3a3a42;letter-spacing:.3em;z-index:9}";
    /* each doorway carries a mark of its own apparatus. sixteen hands. */
    $MARK = array(
    'dial' => "<circle cx=26 cy=40 r=13 fill=none stroke='#9a9aa6' stroke-width=.8 /><line x1=26 y1=40 x2=26 y2=29 stroke='#c8a44a' stroke-width=1.1 />"
            . "<circle cx=54 cy=40 r=13 fill=none stroke='#9a9aa6' stroke-width=.8 /><line x1=54 y1=40 x2=63 y2=34 stroke='#c8a44a' stroke-width=1.1 />"
            . "<circle cx=40 cy=68 r=13 fill=none stroke='#9a9aa6' stroke-width=.8 /><line x1=40 y1=68 x2=33 y2=78 stroke='#c8a44a' stroke-width=1.1 />",
    'grid' => (function(){ $o=''; for($y=0;$y<6;$y++) for($x=0;$x<6;$x++){ $f=(($x*7+$y*3)%5<2)?"#9a9aa6":"none";
                $o.="<rect x=".(16+$x*12)." y=".(16+$y*12)." width=10 height=10 fill='$f' stroke='#5a5a66' stroke-width=.5 />"; } return $o; })(),
    'tumbler' => (function(){ $o=''; for($i=0;$i<5;$i++){ $x=14+$i*15;
                $o.="<rect x=$x y=22 width=11 height=56 fill=none stroke='#9a9aa6' stroke-width=.7 />"
                  . "<line x1=$x y1=44 x2=".($x+11)." y2=44 stroke='#c8a44a' stroke-width=.7 />"
                  . "<line x1=$x y1=56 x2=".($x+11)." y2=56 stroke='#5a5a66' stroke-width=.5 />"; } return $o; })(),
    'wheel' => "<circle cx=50 cy=50 r=34 fill=none stroke='#9a9aa6' stroke-width=.8 /><circle cx=50 cy=50 r=20 fill=none stroke='#c8a44a' stroke-width=.8 />"
             . (function(){ $o=''; for($i=0;$i<16;$i++){ $a=$i*22.5*M_PI/180;
                $o.="<line x1=".(50+cos($a)*34)." y1=".(50+sin($a)*34)." x2=".(50+cos($a)*29)." y2=".(50+sin($a)*29)." stroke='#9a9aa6' stroke-width=.5 />"; } return $o; })()
             . "<line x1=50 y1=8 x2=50 y2=16 stroke='#b2452f' stroke-width=1.2 />",
    'keyer' => "<circle cx=50 cy=62 r=17 fill=none stroke='#9a9aa6' stroke-width=1 />"
             . "<circle cx=22 cy=24 r=2.4 fill='#c8a44a'/><rect x=30 y=22 width=12 height=4.6 fill='#c8a44a'/>"
             . "<circle cx=50 cy=24 r=2.4 fill='#c8a44a'/><circle cx=60 cy=24 r=2.4 fill='#c8a44a'/><rect x=68 y=22 width=12 height=4.6 fill='#c8a44a'/>",
    'scope' => "<rect x=10 y=22 width=80 height=56 fill=none stroke='#5a5a66' stroke-width=.5 />"
             . "<line x1=10 y1=50 x2=90 y2=50 stroke='#3a3a46' stroke-width=.5 /><line x1=50 y1=22 x2=50 y2=78 stroke='#3a3a46' stroke-width=.5 />"
             . "<path d='M10,50 Q20,20 30,50 T50,50 T70,50 T90,50' fill=none stroke='#4be07a' stroke-width=1.1 />",
    'pans' => "<line x1=50 y1=24 x2=50 y2=40 stroke='#9a9aa6' stroke-width=.8 /><line x1=16 y1=36 x2=84 y2=44 stroke='#9a9aa6' stroke-width=1.4 />"
            . "<path d='M8,38 L28,38 L22,52 L14,52 Z' fill=none stroke='#c8a44a' stroke-width=.7 />"
            . "<path d='M72,46 L92,46 L86,58 L78,58 Z' fill=none stroke='#c8a44a' stroke-width=.7 />"
            . "<line x1=44 y1=80 x2=56 y2=80 stroke='#9a9aa6' stroke-width=1 /><line x1=50 y1=24 x2=50 y2=80 stroke='#5a5a66' stroke-width=.5 />",
    'slide' => (function(){ $o=''; $k=0; for($y=0;$y<3;$y++) for($x=0;$x<3;$x++){ $k++;
                if($k===9) continue;
                $o.="<rect x=".(20+$x*21)." y=".(20+$y*21)." width=18 height=18 fill='none' stroke='#9a9aa6' stroke-width=.7 />"; } return $o; })()
             . "<rect x=62 y=62 width=18 height=18 fill='#1a1a20' stroke='#3a3a46' stroke-width=.5 stroke-dasharray='2 2'/>",
    'stars' => "<polyline points='18,70 32,34 48,58 66,20 84,46' fill=none stroke='#4a5a80' stroke-width=.7 />"
             . "<circle cx=18 cy=70 r=2.6 fill='#e8d9a0'/><circle cx=32 cy=34 r=2.6 fill='#e8d9a0'/><circle cx=48 cy=58 r=2.6 fill='#e8d9a0'/>"
             . "<circle cx=66 cy=20 r=2.6 fill='#8fa0c8'/><circle cx=84 cy=46 r=2.6 fill='#8fa0c8'/><circle cx=74 cy=76 r=2 fill='#8fa0c8'/>",
    'mixer' => (function(){ $o=''; $c=array('#b2452f','#4a8a4a','#3b6ad8'); $h=array(30,58,44);
                for($i=0;$i<3;$i++){ $x=28+$i*22;
                $o.="<line x1=$x y1=18 x2=$x y2=82 stroke='#5a5a66' stroke-width=3 stroke-linecap='round'/>"
                  . "<rect x=".($x-7)." y=".$h[$i]." width=14 height=5 fill='".$c[$i]."'/>"; } return $o; })(),
    'sort' => (function(){ $o="<line x1=14 y1=26 x2=86 y2=26 stroke='#9a9aa6' stroke-width=1.2 />";
                for($i=0;$i<5;$i++){ $y=36+$i*10; $o.="<line x1=14 y1=$y x2=".(60+($i*7)%26)." y2=$y stroke='#5a5a66' stroke-width=.8 />"; }
                return $o."<path d='M76,34 L82,44 L70,44 Z' fill='#c8a44a'/>"; })(),
    'well' => (function(){ $o=''; for($i=0;$i<7;$i++){ $w=76-$i*10; $y=14+$i*10;
                $o.="<rect x=".(50-$w/2)." y=$y width=$w height=8 fill=none stroke='#9a9aa6' stroke-width=.5 opacity='".(1-$i*0.12)."'/>"; } return $o; })(),
    'strings' => "<path d='M20,26 Q50,44 80,24' fill=none stroke='#4a3f30' stroke-width=.8 /><path d='M20,26 Q34,58 46,74' fill=none stroke='#4a3f30' stroke-width=.8 />"
               . "<path d='M80,24 Q72,56 46,74' fill=none stroke='#4a3f30' stroke-width=.8 /><path d='M20,26 Q56,50 80,24' fill=none stroke='#4a3f30' stroke-width=.5 />"
               . "<circle cx=20 cy=26 r=3 fill='#c8a44a'/><circle cx=80 cy=24 r=3 fill='#c8a44a'/><circle cx=46 cy=74 r=3 fill='#c8a44a'/>",
    'steps' => (function(){ $o=''; for($r=0;$r<4;$r++) for($c=0;$c<8;$c++){ $on=(($c*3+$r*5)%7<2);
                $o.="<rect x=".(9+$c*11)." y=".(30+$r*11)." width=9 height=9 fill='".($on?"#c8a44a":"none")."' stroke='#4a4a56' stroke-width=.5 />"; }
                return $o."<rect x=42 y=20 width=9 height=5 fill='#4be07a'/>"; })(),
    'pairs' => (function(){ $o=''; $k=0; for($y=0;$y<4;$y++) for($x=0;$x<4;$x++){ $k++;
                $up=($k===6||$k===11);
                $o.="<rect x=".(14+$x*19)." y=".(14+$y*19)." width=16 height=16 rx=1 fill='".($up?"#e7e2ee":"none")."' stroke='#6a6076' stroke-width=.6 />"; }
                return $o; })(),
    'term' => "<rect x=10 y=18 width=80 height=64 fill=none stroke='#1e3a2a' stroke-width=.6 />"
            . "<text x=18 y=40 fill='#4be08a' font-family='monospace' font-size='11'>&gt;</text>"
            . "<rect x=28 y=32 width=26 height=2 fill='#4be08a'/><rect x=18 y=48 width=44 height=2 fill='#2a6a46'/>"
            . "<rect x=18 y=58 width=30 height=2 fill='#2a6a46'/><rect x=28 y=68 width=8 height=9 fill='#4be08a'/>",
    );
    $b = "<div id=h>" . count($APP) . "</div><div id=r>";
    foreach ($APP as $slug => $label) {
        $g = isset($MARK[$slug]) ? $MARK[$slug] : '';
        $b .= "<a href=\"" . h(u($slug)) . "\"><svg viewBox='0 0 100 100' opacity=.62>$g</svg><span>" . h($label) . "</span></a>";
    }
    $b .= "</div>";
    shell('smtstrange', $css, $b, '', $mob);
    /* the back-link is meaningless here */
    echo "<style>.bk{display:none}</style>";
}

/* the one door that does not open */
function sealed($p, $mob) {
    $s = fnv('sealed' . $p); $rows = '';
    for ($i=0;$i<10;$i++){ $s = ($s*1103515245+12345)&0x7fffffff;
      $rows .= "<tr><td>" . strtoupper(base_convert((string)($s%60466175),10,36)) . "</td><td>" . number_format($s%9400000) . "</td></tr>"; }
    $css = "body{background:#0d0b0b;color:#8a7a72;font:13px/1.7 monospace;padding:" . ($mob?"18px":"3rem 2rem") . "}"
        . "h1{font:400 20px monospace;color:#c05a48;letter-spacing:.1em}table{border-collapse:collapse;margin:1.4em 0}"
        . "td{padding:.15em 1.4em .15em 0;border-bottom:1px solid #1e1a1a}";
    http_response_code(403);
    shell('403', $css, "<h1>403</h1><table>$rows</table><div style='opacity:.5'>the mass is measured. the door is not.</div>", '', $mob);
}

/* ===========================================================================
 *  ROUTER
 * ========================================================================= */
$mob  = smt_mobile();
$path = smt_path();
$seg  = $path === '' ? array() : explode('/', $path);
$head = $seg[0] ?? '';

if ($path === 'favicon.ico') { http_response_code(204); exit; }
if ($path === 'index.html' || $path === 'index.htm') { header('Location: ' . u(), true, 302); exit; }
if ($path === 'robots.txt') { header('Content-Type: text/plain'); echo "User-agent: *\nDisallow: " . u('vault') . "\n"; exit; }
if ($path === '') { front($mob); exit; }
if (in_array($head, array('vault','attic','cellar','oubliette'), true)) { sealed($path, $mob); exit; }

if (isset($APP[$head])) {
    $fn = 'ap_' . $head;
    if (function_exists($fn)) { $fn($mob); exit; }
}

http_response_code(404);
shell('—', "body{background:#0c0c0e;color:#3a3a42;font:12px monospace;display:flex;align-items:center;"
    . "justify-content:center;min-height:100vh;letter-spacing:.3em}", "<div>—</div>", '', $mob);
