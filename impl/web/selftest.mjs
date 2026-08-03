// Selbsttest des Web-Clients ausserhalb des Browsers.
//
// Node 22 bringt dieselbe WebCrypto-API mit wie der Browser, deshalb laeuft
// der unveraenderte Client-Code hier durch. Der Test prueft zuerst die
// Bausteine gegen sich selbst und faehrt dann ein vollstaendiges Gespraech
// gegen einen laufenden Knoten:
//
//   node impl/web/selftest.mjs http://127.0.0.1:8478
//
// Ohne Adresse laufen nur die Tests, die keinen Knoten brauchen.

import {
  Client, DropNetwork, Identity, NodeClient, addressFromKey, addressValid,
  aontInvert, aontTransform, canonical, disperse, dropTag, hex, reassemble,
  safetyNumber, tagSecret, unhex, verifyRecord, makeRecord, enc, dec,
} from './nyx-protocol.js';

let passed = 0, failed = 0;

async function check(name, fn) {
  try {
    await fn();
    passed++;
    console.log(`  ok    ${name}`);
  } catch (e) {
    failed++;
    console.log(`  FEHLT ${name}: ${e.message}`);
  }
}

const assert = (cond, msg) => { if (!cond) throw new Error(msg || 'Bedingung verletzt'); };
const same = (a, b, msg) => assert(hex(a) === hex(b), msg || `${hex(a)} != ${hex(b)}`);

console.log('\nBausteine');

await check('Adresse ist der Schluessel', async () => {
  const id = await Identity.create();
  assert(id.address.length === 36, 'Adresse hat 36 Zeichen');
  assert(await addressValid(id.address));
  assert(await addressFromKey(id.ikPub) === id.address);
});

await check('Pruefsumme faengt Tippfehler', async () => {
  const id = await Identity.create();
  const broken = (id.address[0] === 'a' ? 'b' : 'a') + id.address.slice(1);
  assert(!(await addressValid(broken)));
});

await check('Identitaet ist exportierbar', async () => {
  const id = await Identity.create();
  const back = await Identity.import(id.export());
  assert(back.address === id.address);
  same(back.idkPub, id.idkPub);
});

await check('Eintrag wird signiert und geprueft', async () => {
  const id = await Identity.create();
  const rec = await makeRecord(id, 'BIND', { idk_pub: hex(id.idkPub) });
  assert(await verifyRecord(rec), 'gueltiger Eintrag');
  rec.ts += 1;
  assert(!(await verifyRecord(rec)), 'veraenderter Eintrag muss durchfallen');
});

await check('Eintrag mit fremder Adresse faellt durch', async () => {
  const id = await Identity.create(), other = await Identity.create();
  const rec = await makeRecord(id, 'BIND', { idk_pub: hex(id.idkPub) });
  rec.addr = other.address;
  assert(!(await verifyRecord(rec)));
});

await check('AONT hin und zurueck', async () => {
  for (const size of [0, 1, 31, 32, 33, 2000]) {
    const data = crypto.getRandomValues(new Uint8Array(size));
    same(await aontInvert(await aontTransform(data)), data);
  }
});

await check('Ein gekipptes Bit zerstoert das ganze AONT-Paket', async () => {
  const data = enc.encode('A'.repeat(512));
  const pkg = await aontTransform(data);
  pkg[0] ^= 1;
  const back = await aontInvert(pkg);
  let matching = 0;
  for (let i = 0; i < data.length; i++) if (back[i] === data[i]) matching++;
  assert(matching < data.length * 0.05, 'kein Teilklartext erlaubt');
});

await check('k von n genuegen', async () => {
  const data = crypto.getRandomValues(new Uint8Array(1500));
  const shares = await disperse(data, 10, 20);
  same(await reassemble(shares.slice(0, 10)), data);
  same(await reassemble(shares.slice(10)), data);
  same(await reassemble(shares.slice(5, 15)), data);
});

await check('k-1 Teile ergeben nichts', async () => {
  const shares = await disperse(enc.encode('TREFFPUNKT UM ACHT'.repeat(20)), 4, 8);
  let threw = false;
  try { await reassemble(shares.slice(0, 3)); } catch { threw = true; }
  assert(threw, 'unterhalb der Schwelle muss scheitern');

  const visible = dec.decode(new Uint8Array(
    shares.slice(0, 3).flatMap(s => [...s.data])));
  assert(!visible.includes('TREFFPUNKT'), 'kein Klartext in den Teilen');
});

await check('Alle Teile sind gleich gross', async () => {
  const shares = await disperse(crypto.getRandomValues(new Uint8Array(999)), 5, 10);
  assert(new Set(shares.map(s => s.data.length)).size === 1);
});

await check('Tags sind unverkettbar', async () => {
  const sk = await tagSecret(crypto.getRandomValues(new Uint8Array(32)));
  const a = [], b = [];
  for (let j = 0; j < 20; j++) { a.push(await dropTag(sk, 0, j)); b.push(await dropTag(sk, 1, j)); }
  assert(new Set([...a, ...b]).size === 40, 'alle Tags verschieden');
});

await check('Vergleichswert ist symmetrisch', async () => {
  const a = await Identity.create(), b = await Identity.create();
  const one = await safetyNumber(a.ikPub, a.idkPub, b.ikPub, b.idkPub);
  const two = await safetyNumber(b.ikPub, b.idkPub, a.ikPub, a.idkPub);
  assert(one === two, 'beide Seiten sehen denselben Wert');
  assert(one.split(' ').length === 6);
});

// -- Gegen einen laufenden Knoten -----------------------------------------

const url = process.argv[2];
if (url) {
  console.log(`\nGegen den Knoten unter ${url}`);

  const node = new NodeClient(url);
  await node.info();
  const drops = await new DropNetwork([node]).ready();

  const alice = new Client(await Identity.create(), drops, node);
  const bob = new Client(await Identity.create(), drops, node);

  await check('Ankuendigung im Verzeichnis', async () => {
    await alice.announce();
    await bob.announce();
    const resolved = await node.resolve(bob.identity.address);
    assert(resolved.identity, 'Bob steht im Verzeichnis');
    assert(resolved.identity.idk_pub === hex(bob.identity.idkPub));
  });

  await check('Erstkontakt ohne Vorabsprache', async () => {
    await alice.send(bob.identity.address, { t: 'chat', text: 'hallo aus dem Browser' });
    const got = await bob.poll();
    assert(got.length === 1, `erwartet 1, bekommen ${got.length}`);
    assert(got[0][0] === alice.identity.address, 'Absender stimmt');
    assert(got[0][1].text === 'hallo aus dem Browser');
  });

  await check('Antwort in die Gegenrichtung', async () => {
    await bob.send(alice.identity.address, { t: 'chat', text: 'auch hallo' });
    const got = await alice.poll();
    assert(got.length === 1 && got[0][1].text === 'auch hallo');
  });

  await check('Laengeres Gespraech in Reihenfolge', async () => {
    for (let i = 0; i < 4; i++) {
      await alice.send(bob.identity.address, { t: 'chat', text: `a${i}` });
    }
    const got = await bob.poll();
    assert(got.map(([, e]) => e.text).join(',') === 'a0,a1,a2,a3',
           got.map(([, e]) => e.text).join(','));
  });

  await check('Mehrzellige Nachricht', async () => {
    const long = 'lang '.repeat(600);
    await alice.send(bob.identity.address, { t: 'mail', subject: 'Bericht', body: long });
    const got = await bob.poll();
    assert(got.length === 1 && got[0][1].body === long);
  });

  await check('Nach der Abholung liegt nichts mehr da', async () => {
    await alice.send(bob.identity.address, { t: 'chat', text: 'letzte' });
    await bob.poll();
    const before = (await node.info()).entries;
    await bob.poll();
    assert((await node.info()).entries <= before, 'kein Nachschlag');
  });
} else {
  console.log('\n(Kein Knoten angegeben — Netztests uebersprungen.)');
}

console.log(`\n${passed} bestanden, ${failed} fehlgeschlagen\n`);
process.exit(failed ? 1 : 0);
