# Sicherheitshinweis

Dieser Text sagt, was der Code ist und was er nicht ist. Er steht hier, weil
die Alternative — es nicht zu sagen — der eigentliche Fehler waere.

---

## Was das hier ist

Eine vollstaendige, lauffaehige Referenzimplementierung des Entwurfs aus
`whitepaper/nyx-whitepaper.md`. Sie ist dazu da, den Entwurf pruefbar zu
machen: jede Behauptung des Papiers hat einen Testfall, und die drei
Fassungen in Python, PHP und JavaScript belegen ueber Sprachgrenzen hinweg,
dass die Formate eindeutig beschrieben sind.

## Was das hier nicht ist

**Kein Produkt.** Es hat keine unabhaengige Pruefung durchlaufen, keine
Belastungsprobe und keinen Betrieb mit echten Nutzern. Wer damit etwas
schuetzt, das tatsaechlich geschuetzt werden muss, geht ein Risiko ein, das
niemand beziffert hat.

---

## Konkrete Schwaechen dieser Implementierung

### Die Primitive sind nicht laufzeitkonstant

`impl/python/nyx/primitives` ist reines Python. X25519, Ed25519 und
ChaCha20-Poly1305 rechnen dort mit gewoehnlichen Integern; Zeitverhalten und
Cache-Zugriffe haengen vom Schluessel ab. Wer den Rechner mitbenutzt oder
Laufzeiten misst, kann daraus Schluesselmaterial gewinnen.

Fuer den Betrieb ist das durch libsodium zu ersetzen. Die Schnittstelle in
`primitives/__init__.py` ist dafuer absichtlich schmal gehalten. Dasselbe
gilt fuer `nyx-crypto.js`; die PHP-Fassung benutzt bereits libsodium.

Warum dann reines Python? Weil `cryptography` in der Umgebung, in der dieser
Code entstand, nicht ladbar war, und weil eine Referenzimplementierung ohne
Fremdpakete leichter nachzurechnen ist. Es ist eine bewusste Entscheidung
mit einem bekannten Preis, keine Nachlaessigkeit.

### Loeschen im Arbeitsspeicher ist nur teilweise moeglich

`zeroize()` ueberschreibt genau den Puffer, den es bekommt. Was der
Interpreter vorher kopiert hat — beim Entpacken von Bytes, beim Umkopieren
von Listen, beim Einlagern in den Speicher der Auslagerungsdatei — erreicht
es nicht. In JavaScript ist die Lage schlechter: `fill(0)` wirkt auf das
Objekt, nicht auf die Kopien, die die Laufzeit angelegt haben mag.

Die Vorwaertsgeheimhaltung des Protokolls beruht deshalb nicht auf dem
Loeschen einzelner Puffer, sondern auf der Einwegfunktion der Kette: der
Schluessel von gestern ist aus dem Zustand von heute nicht berechenbar. Das
Ueberschreiben verkleinert das Zeitfenster, es traegt die Eigenschaft nicht.

### Loeschung auf fremder Hardware bleibt unbeweisbar

Ein Speicherknoten kann eine Kopie behalten, und kein Protokoll kann das
feststellen. Das ist keine Luecke dieser Implementierung, sondern eine
Eigenschaft der Wirklichkeit. Der Entwurf zieht daraus die Konsequenz, dass
keine Garantie am Loeschversprechen haengt (Whitepaper 7.8) — aber wer die
Schwellenannahme aus 10.3 nicht akzeptiert, muss davon ausgehen, dass jedes
je abgelegte Chiffrat dauerhaft existiert.

### Das Erstkontakt-Postfach ist adressierbar

Die Tags der ersten Nachricht an einen Empfaenger leiten sich aus seinem
oeffentlichen Schluessel ab. Wer die Adresse kennt, kann sie berechnen — und
damit sehen, ob jemand unabgeholte Erstkontakte hat, und das Postfach
zumuellen. Dagegen steht nur der Arbeitsnachweis je Ablage. Ab der zweiten
Nachricht ist die Unterhaltung unverkettbar.

### Das Mixnetz ist nicht angeschlossen

`nyx/mixnet.py` ist vollstaendig und getestet, aber der Client spricht in
dieser Fassung direkt mit den Knoten. Damit sieht ein Knoten die IP-Adresse
des Clients — genau das, was das Mixnetz verhindern soll. Fuer einen
ernsthaften Betrieb muss der Transport durch das Mixnetz gefuehrt werden
oder durch ein bestehendes (Tor).

### Der Arbeitsnachweis der Kette ist Spielzeug

Die Schwierigkeit liegt bei 10 bis 12 Bit, damit die Demo auf einem Rechner
laeuft. Das schuetzt gegen Tippfehler, nicht gegen einen Angreifer. Die
Sicherheit des Verzeichnisses aus Whitepaper 4.3 setzt eine Kette voraus,
gegen die ein Umschreiben tatsaechlich teuer ist.

### Anrufe gehen an der Anonymitaet vorbei

Die Signalisierung laeuft ueber den geschuetzten Kanal, der Medienstrom
nicht: WebRTC verbindet die Endgeraete direkt, beide Seiten sehen die
IP-Adresse der anderen. Der Web-Client sagt das im Verlauf, bevor der Anruf
beginnt. Ein Medienstrom durch das Mixnetz ist vorgesehen (`relayed`), aber
nicht implementiert.

### Kein Schutz des Ratschenzustands auf der Platte

Die CLI schreibt Identitaet und private Prekeys unverschluesselt nach
`~/.nyx` (Rechte 0600). Wer die Datei hat, hat die Identitaet. Eine
Verschluesselung mit einem Kennwort fehlt.

### Weiteres

- Die punktierbare PRF waechst um bis zu 48 Eintraege je Punktierung. Ein
  Knoten mit vielen Abholungen bekommt einen grossen Schluesselzustand;
  ein Verfahren zum Zusammenfassen fehlt.
- Der Web-Client haelt die Identitaet in `localStorage` — lesbar fuer
  jeden Code, der im selben Ursprung laeuft.
- Es gibt keine Gruppen in der Implementierung. Whitepaper 9 beschreibt
  sie, `impl` enthaelt sie nicht.
- Reparatur ausgefallener Teile (Whitepaper 7.5) ist beschrieben, aber
  nicht implementiert; faellt ein Knoten aus, sinkt die Teilezahl, bis die
  Schwelle unterschritten ist.

---

## Was gegen den Nutzer weiterhin gilt

Kein Protokoll schuetzt gegen:

- ein kompromittiertes Endgeraet — dort steht der Klartext,
- die Preisgabe des eigenen Namens im geschuetzten Kanal,
- Schreibstil, Zeitmuster und wiederverwendete Identitaeten ueber getrennte
  Zusammenhaenge hinweg,
- einen Beobachter, der das gesamte Netz gleichzeitig sieht.

Der letzte Punkt ist keine Nachlaessigkeit dieses Entwurfs. Kein Mixnetz mit
geringer Latenz loest ihn.

---

## Wenn Sie das ernsthaft benutzen wollen

1. Primitive gegen libsodium tauschen.
2. Transport durch ein Mixnetz oder Tor fuehren.
3. Kettenschwierigkeit auf einen Wert setzen, der Umschreiben teuer macht.
4. Identitaetsdatei mit einem Kennwort verschluesseln.
5. Knoten in verschiedenen Rechtsraeumen und bei verschiedenen Betreibern
   betreiben — sonst gilt die Rechnung aus Whitepaper 10.3 nicht.
6. Den Code von jemandem pruefen lassen, der nicht daran beteiligt war.

Punkt 6 ist der wichtigste.
