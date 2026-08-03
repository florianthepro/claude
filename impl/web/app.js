// Oberflaeche.
//
// Die Anwendung haelt drei Dinge: die Identitaet, die Kontakte und den
// Verlauf. Alles davon liegt lokal. Der Verlauf steht bewusst nur im
// Arbeitsspeicher — er ist nach dem Schliessen des Fensters weg. Was
// dauerhaft gespeichert wird, ist ausschliesslich die Identitaet und die
// Liste der Adressen; beides in localStorage, beides ohne Nachrichteninhalt.

import {
  Client, DropNetwork, Identity, NodeClient, addressValid, hex, safetyNumber, unhex,
} from './nyx-protocol.js';

const $ = (id) => document.getElementById(id);
const app = $('app');

const SPEICHER = { identitaet: 'nyx.identitaet', knoten: 'nyx.knoten', kontakte: 'nyx.kontakte' };

let identitaet = null;
let client = null;
let knotenUrl = localStorage.getItem(SPEICHER.knoten) || 'http://127.0.0.1:8478';
let kontakte = JSON.parse(localStorage.getItem(SPEICHER.kontakte) || '[]');
let aktiv = null;
let pollTimer = null;

const verlauf = new Map();     // Adresse -> Nachrichten, nur im Speicher
const anrufe = new Map();

// -- Zustandsanzeige --------------------------------------------------------

function zeige(text, fehler = false) {
  const el = $('status');
  el.textContent = text;
  el.classList.toggle('error', fehler);
  el.hidden = !text;
  if (text && !fehler) setTimeout(() => { if (el.textContent === text) el.hidden = true; }, 4000);
}

// -- Start ------------------------------------------------------------------

async function start() {
  const gespeichert = localStorage.getItem(SPEICHER.identitaet);
  if (gespeichert) {
    identitaet = await Identity.import(gespeichert);
    await verbinden();
  } else {
    $('start-knoten').value = knotenUrl;
    $('dlg-start').showModal();
  }
}

$('dlg-start').addEventListener('close', async (e) => {
  const dlg = $('dlg-start');
  knotenUrl = $('start-knoten').value.trim() || knotenUrl;
  localStorage.setItem(SPEICHER.knoten, knotenUrl);

  if (dlg.returnValue === 'import') {
    $('import-datei').click();
    return;
  }
  identitaet = await Identity.create();
  localStorage.setItem(SPEICHER.identitaet, identitaet.export());
  await verbinden(true);
});

$('import-datei').addEventListener('change', async (e) => {
  const datei = e.target.files[0];
  if (!datei) return;
  try {
    identitaet = await Identity.import(await datei.text());
    localStorage.setItem(SPEICHER.identitaet, identitaet.export());
    await verbinden();
  } catch {
    zeige('Die Datei enthält keine gültige Identität.', true);
    $('dlg-start').showModal();
  }
});

async function verbinden(neuAnkuendigen = false) {
  $('meine-adresse').textContent = identitaet.address;
  $('einst-adresse').textContent = identitaet.address;

  try {
    const knoten = new NodeClient(knotenUrl);
    await knoten.info();
    const drops = await new DropNetwork([knoten]).ready();
    client = new Client(identitaet, drops, knoten);

    if (neuAnkuendigen || !localStorage.getItem('nyx.angekuendigt')) {
      await client.announce();
      localStorage.setItem('nyx.angekuendigt', '1');
    }
    zeige('Verbunden.');
    zeichneKontakte();
    starteAbholung();
  } catch (err) {
    zeige(`Knoten nicht erreichbar: ${err.message}`, true);
  }
}

// -- Abholung ---------------------------------------------------------------

function starteAbholung() {
  clearInterval(pollTimer);
  pollTimer = setInterval(abholen, 4000);
  abholen();
}

let laeuft = false;

async function abholen() {
  if (!client || laeuft) return;
  laeuft = true;
  try {
    for (const [absender, umschlag] of await client.poll()) {
      merkeKontakt(absender);
      verarbeite(absender, umschlag);
    }
  } catch (err) {
    zeige(`Abholen fehlgeschlagen: ${err.message}`, true);
  } finally {
    laeuft = false;
  }
}

function verarbeite(absender, umschlag) {
  switch (umschlag.t) {
    case 'chat':
      eintragen(absender, { text: umschlag.text, out: false, ts: umschlag.ts * 1000 });
      break;
    case 'file':
      dateiEmpfangen(absender, umschlag);
      break;
    case 'call':
      anrufSignal(absender, umschlag);
      break;
    case 'mail':
      eintragen(absender, { text: `${umschlag.subject}\n\n${umschlag.body}`,
                            out: false, ts: umschlag.ts * 1000 });
      break;
    default:
      break;
  }
}

// -- Verlauf ----------------------------------------------------------------

function eintragen(adresse, eintrag) {
  if (!verlauf.has(adresse)) verlauf.set(adresse, []);
  verlauf.get(adresse).push({ ts: Date.now(), ...eintrag });
  if (adresse === aktiv) zeichneVerlauf();
  zeichneKontakte();
}

function zeichneVerlauf() {
  const liste = $('verlauf');
  liste.innerHTML = '';
  for (const eintrag of verlauf.get(aktiv) || []) {
    const el = document.createElement('div');
    el.className = eintrag.system ? 'msg system' : `msg ${eintrag.out ? 'out' : 'in'}`;
    el.textContent = eintrag.text;
    if (!eintrag.system) {
      const meta = document.createElement('div');
      meta.className = 'msg-meta';
      meta.textContent = new Date(eintrag.ts).toLocaleTimeString('de-DE',
        { hour: '2-digit', minute: '2-digit' });
      el.append(meta);
    }
    liste.append(el);
  }
  liste.scrollTop = liste.scrollHeight;
}

function zeichneKontakte() {
  const box = $('kontakte');
  box.innerHTML = '';
  for (const adresse of kontakte) {
    const eintraege = verlauf.get(adresse) || [];
    const letzte = eintraege.filter(e => !e.system).at(-1);

    const btn = document.createElement('button');
    btn.className = 'contact';
    btn.setAttribute('aria-current', String(adresse === aktiv));
    btn.innerHTML = `<div class="contact-name">${adresse.slice(0, 16)}…</div>`;
    const last = document.createElement('div');
    last.className = 'contact-last';
    last.textContent = letzte ? letzte.text : 'Noch keine Nachricht';
    btn.append(last);
    btn.onclick = () => oeffne(adresse);
    box.append(btn);
  }
}

function merkeKontakt(adresse) {
  if (kontakte.includes(adresse)) return;
  kontakte.push(adresse);
  localStorage.setItem(SPEICHER.kontakte, JSON.stringify(kontakte));
  zeichneKontakte();
}

function oeffne(adresse) {
  aktiv = adresse;
  $('titel').textContent = adresse;
  $('leer').hidden = true;
  $('verlauf').hidden = false;
  $('composer').hidden = false;
  $('btn-anrufen').hidden = false;
  $('btn-pruefen').hidden = false;
  $('btn-zurueck').hidden = false;
  app.dataset.view = 'gespraech';
  zeichneVerlauf();
  zeichneKontakte();
  $('eingabe').focus();
}

// -- Senden -----------------------------------------------------------------

$('composer').addEventListener('submit', async (e) => {
  e.preventDefault();
  const feld = $('eingabe');
  const text = feld.value.trim();
  if (!text || !aktiv) return;

  feld.value = '';
  feld.style.height = 'auto';
  eintragen(aktiv, { text, out: true, ts: Date.now() });
  try {
    await client.send(aktiv, { t: 'chat', text, ts: Math.floor(Date.now() / 1000) });
  } catch (err) {
    zeige(`Senden fehlgeschlagen: ${err.message}`, true);
  }
});

$('eingabe').addEventListener('input', (e) => {
  e.target.style.height = 'auto';
  e.target.style.height = Math.min(e.target.scrollHeight, 160) + 'px';
});

$('eingabe').addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    $('composer').requestSubmit();
  }
});

// -- Dateien ----------------------------------------------------------------

const CHUNK = 1024;
const empfangen = new Map();

$('btn-datei').onclick = () => $('datei').click();

$('datei').addEventListener('change', async (e) => {
  const datei = e.target.files[0];
  if (!datei || !aktiv) return;
  e.target.value = '';

  const daten = new Uint8Array(await datei.arrayBuffer());
  const id = hex(crypto.getRandomValues(new Uint8Array(8)));
  const teile = Math.max(1, Math.ceil(daten.length / CHUNK));
  const digest = hex(new Uint8Array(await crypto.subtle.digest('SHA-256', daten)));

  eintragen(aktiv, { text: `Datei gesendet: ${datei.name} (${daten.length} Byte)`,
                     out: true, ts: Date.now() });

  await client.send(aktiv, { t: 'file', k: 'manifest', id, name: datei.name,
                             size: daten.length, chunks: teile, digest });
  for (let i = 0; i < teile; i++) {
    await client.send(aktiv, { t: 'file', k: 'chunk', id, i,
                               d: hex(daten.slice(i * CHUNK, (i + 1) * CHUNK)) });
  }
});

function dateiEmpfangen(absender, umschlag) {
  if (umschlag.k === 'manifest') {
    empfangen.set(umschlag.id, { ...umschlag, teile: new Map() });
    return;
  }
  const stand = empfangen.get(umschlag.id);
  if (!stand) return;

  stand.teile.set(umschlag.i, unhex(umschlag.d));
  if (stand.teile.size < stand.chunks) return;

  const gesamt = new Uint8Array(stand.size);
  let at = 0;
  for (let i = 0; i < stand.chunks; i++) { gesamt.set(stand.teile.get(i), at); at += stand.teile.get(i).length; }
  empfangen.delete(umschlag.id);

  const url = URL.createObjectURL(new Blob([gesamt]));
  const a = document.createElement('a');
  a.href = url;
  a.download = stand.name;
  a.textContent = `Datei empfangen: ${stand.name}`;
  eintragen(absender, { text: `Datei empfangen: ${stand.name} (${stand.size} Byte)`,
                        out: false, ts: Date.now() });
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 30000);
}

// -- Anrufe -----------------------------------------------------------------
//
// Die Signalisierung laeuft ueber denselben Kanal wie alles andere. Der
// Medienstrom laeuft direkt zwischen den Geraeten — das ist schnell, gibt
// den Gespraechspartnern aber gegenseitig ihre IP-Adresse preis. Der Hinweis
// dazu steht im Verlauf, nicht im Kleingedruckten.

let peer = null;
let laufenderAnruf = null;

async function anrufStarten() {
  if (!aktiv) return;
  const id = hex(crypto.getRandomValues(new Uint8Array(8)));
  laufenderAnruf = { id, peer: aktiv, outgoing: true };

  eintragen(aktiv, { text: 'Anruf: die Medienverbindung ist direkt und zeigt beiden '
                         + 'Seiten die IP-Adresse der anderen.', system: true });
  zeigeAnrufleiste('Wählt …', false);

  peer = neuerPeer(aktiv, id);
  const strom = await medien();
  strom?.getTracks().forEach(t => peer.addTrack(t, strom));
  const angebot = await peer.createOffer();
  await peer.setLocalDescription(angebot);

  await client.send(aktiv, { t: 'call', k: 'offer', id, relayed: false,
                             sdp: { type: angebot.type, sdp: angebot.sdp } });
}

function neuerPeer(adresse, id) {
  // Ohne STUN-Server: nur Kandidaten aus dem lokalen Netz. Ein oeffentlicher
  // STUN-Server waere ein Dritter, der die Verbindung sieht — das waere
  // genau der Punkt, den dieses Protokoll vermeidet.
  const pc = new RTCPeerConnection({ iceServers: [] });
  pc.onicecandidate = (e) => {
    if (e.candidate) {
      client.send(adresse, { t: 'call', k: 'candidate', id, c: e.candidate.toJSON() })
        .catch(() => {});
    }
  };
  pc.ontrack = (e) => {
    let audio = document.getElementById('fern-ton');
    if (!audio) {
      audio = document.createElement('audio');
      audio.id = 'fern-ton';
      audio.autoplay = true;
      document.body.append(audio);
    }
    audio.srcObject = e.streams[0];
  };
  pc.onconnectionstatechange = () => {
    if (pc.connectionState === 'connected') zeigeAnrufleiste('Verbunden', false);
    if (['failed', 'closed', 'disconnected'].includes(pc.connectionState)) beenden(false);
  };
  return pc;
}

async function medien() {
  try {
    return await navigator.mediaDevices.getUserMedia({ audio: true });
  } catch {
    zeige('Kein Zugriff auf das Mikrofon — Anruf ohne Ton.', true);
    return null;
  }
}

async function anrufSignal(absender, umschlag) {
  if (umschlag.k === 'offer') {
    laufenderAnruf = { id: umschlag.id, peer: absender, outgoing: false, sdp: umschlag.sdp };
    zeigeAnrufleiste(`Anruf von ${absender.slice(0, 12)}…`, true);
    return;
  }
  if (!laufenderAnruf || umschlag.id !== laufenderAnruf.id) return;

  if (umschlag.k === 'answer' && peer) {
    await peer.setRemoteDescription(umschlag.sdp);
  } else if (umschlag.k === 'candidate' && peer) {
    try { await peer.addIceCandidate(umschlag.c); } catch { /* verspaetet */ }
  } else if (umschlag.k === 'bye') {
    beenden(false);
  }
}

$('btn-annehmen').onclick = async () => {
  if (!laufenderAnruf || laufenderAnruf.outgoing) return;
  peer = neuerPeer(laufenderAnruf.peer, laufenderAnruf.id);
  const strom = await medien();
  strom?.getTracks().forEach(t => peer.addTrack(t, strom));

  await peer.setRemoteDescription(laufenderAnruf.sdp);
  const antwort = await peer.createAnswer();
  await peer.setLocalDescription(antwort);
  await client.send(laufenderAnruf.peer, { t: 'call', k: 'answer', id: laufenderAnruf.id,
                                           sdp: { type: antwort.type, sdp: antwort.sdp } });
  zeigeAnrufleiste('Verbunden', false);
};

$('btn-auflegen').onclick = () => beenden(true);

function beenden(melden) {
  if (melden && laufenderAnruf) {
    client.send(laufenderAnruf.peer, { t: 'call', k: 'bye', id: laufenderAnruf.id })
      .catch(() => {});
  }
  peer?.close();
  peer = null;
  laufenderAnruf = null;
  $('anrufleiste').hidden = true;
}

function zeigeAnrufleiste(text, annehmbar) {
  $('anruf-text').textContent = text;
  $('btn-annehmen').hidden = !annehmbar;
  $('anrufleiste').hidden = false;
}

$('btn-anrufen').onclick = anrufStarten;

// -- Blenden ----------------------------------------------------------------

$('btn-neuer-kontakt').onclick = () => {
  $('kontakt-adresse').value = '';
  $('dlg-kontakt').showModal();
};

$('dlg-kontakt').addEventListener('close', async (e) => {
  if ($('dlg-kontakt').returnValue !== 'ok') return;
  const adresse = $('kontakt-adresse').value.trim().toLowerCase();

  if (!await addressValid(adresse)) {
    zeige('Das ist keine gültige Adresse — die Prüfsumme stimmt nicht.', true);
    return;
  }
  merkeKontakt(adresse);
  oeffne(adresse);
});

$('btn-einstellungen').onclick = () => {
  $('einst-knoten').value = knotenUrl;
  $('dlg-einstellungen').showModal();
};

$('dlg-einstellungen').addEventListener('close', async () => {
  const wahl = $('dlg-einstellungen').returnValue;

  if (wahl === 'export') {
    const url = URL.createObjectURL(new Blob([identitaet.export()],
                                             { type: 'application/json' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = `nyx-identitaet-${identitaet.address.slice(0, 8)}.json`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
    return;
  }

  if (wahl === 'loeschen') {
    if (!confirm('Identität, Kontakte und Verlauf werden gelöscht. '
               + 'Ohne Sicherung ist die Identität danach verloren.')) return;
    localStorage.clear();
    location.reload();
    return;
  }

  const neu = $('einst-knoten').value.trim();
  if (neu && neu !== knotenUrl) {
    knotenUrl = neu;
    localStorage.setItem(SPEICHER.knoten, knotenUrl);
    await verbinden();
  }
});

$('btn-pruefen').onclick = async () => {
  if (!aktiv) return;
  try {
    const { identity, history } = await client.dir.resolve(aktiv);
    if (!identity) {
      zeige('Diese Adresse steht nicht im Verzeichnis.', true);
      return;
    }
    $('pruef-zahl').textContent = await safetyNumber(
      identitaet.ikPub, identitaet.idkPub, unhex(identity.ik_pub), unhex(identity.idk_pub));

    const wechsel = (history || []).filter(h => h.type === 'BIND');
    $('pruef-verlauf').textContent = wechsel.length > 1
      ? wechsel.map(h => `Block ${h.height}: ${h.key.slice(0, 16)}…`).join('\n')
        + '\n\nMehr als eine Bindung. Ein Schlüsselwechsel kann harmlos sein '
        + '(neues Gerät) oder nicht. Das Verzeichnis zeigt ihn, es bewertet ihn nicht.'
      : 'Eine Bindung, unverändert seit der Ankündigung.';
    $('dlg-pruefen').showModal();
  } catch (err) {
    zeige(`Prüfung fehlgeschlagen: ${err.message}`, true);
  }
};

$('btn-zurueck').onclick = () => { app.dataset.view = 'liste'; };

$('meine-adresse').onclick = async () => {
  try {
    await navigator.clipboard.writeText(identitaet.address);
    zeige('Adresse kopiert.');
  } catch {
    zeige('Kopieren nicht möglich — Adresse bitte von Hand übernehmen.', true);
  }
};

start();
