# Nyx — Protokoll v1

Verbindliche Beschreibung der Drahtformate. Wo eine Implementierung von
diesem Dokument abweicht, ist die Implementierung falsch.

Bezugspunkt ist `impl/python/nyx`; die Fassungen in PHP und JavaScript sind
gegen dieselben Testfaelle geprueft (`impl/python/tests/test_interop.py`,
`impl/python/tests/test_crosslang.py`).

---

## 1. Grundlagen

### 1.1 Primitive

| Zweck | Verfahren | Warum |
|---|---|---|
| Hash | SHA-256 | Schnittmenge von Python-Standardbibliothek, libsodium und WebCrypto |
| Ableitung | HKDF-SHA256 mit Label | ebenso |
| AEAD | ChaCha20-Poly1305 (IETF, 12-Byte-Nonce) | in PHP nativ, in JS nachgebaut, kein AES-NI noetig |
| Schluesselaustausch | X25519 | |
| Signatur | Ed25519 | |

SHA-256 statt BLAKE3: BLAKE3 gibt es im Browser nicht ohne Fremdcode. Die
Wahl ist eine Interoperabilitaets-, keine Sicherheitsentscheidung.

### 1.2 Ableitungslabel

Jede Ableitung traegt ein eigenes Label. Zwei Ableitungen aus demselben
Eingangsmaterial ergeben dadurch nie denselben Schluessel.

```
nyx/v1/addr              Adressbildung
nyx/v1/addrsum           Pruefsumme der Adresse
nyx/v1/record            signierter Umfang eines Verzeichniseintrags
nyx/v1/block             Blockhash
nyx/v1/prekey-bundle     signierter Umfang eines Prekey-Buendels
nyx/v1/x3dh-root         Wurzelschluessel aus dem Handshake
nyx/v1/ratchet-root      Wurzelschritt der Doppelratsche
nyx/v1/ratchet-chain     Kettenschritt
nyx/v1/ratchet-message   Nachrichtenschluessel
nyx/v1/drop-tag-secret   SK_tag
nyx/v1/tags/initiator    Tag-Geheimnis Richtung A->B
nyx/v1/tags/responder    Tag-Geheimnis Richtung B->A
nyx/v1/inbox             Postfach fuer den Erstkontakt
nyx/v1/aont              Alles-oder-nichts-Transformation
nyx/v1/store-nonce       Nonce der Knotenablage
nyx/v1/punct-index       Blattnummer der punktierbaren PRF
nyx/v1/put-pow           Arbeitsnachweis je Ablage
nyx/v1/por               Aufbewahrungsnachweis
nyx/v1/mix-hop           Fachschluessel im Mixnetz
nyx/v1/mix-stream        Rumpfschluessel im Mixnetz
nyx/v1/call-media/<id>   Medienschluessel eines Anrufs
nyx/v1/safety            Vergleichswert
```

### 1.3 Kanonisches JSON

Signaturen und Merkle-Wurzeln werden ueber Bytes gebildet, nicht ueber
Objekte. Verbindlich ist:

1. Schluessel rekursiv aufsteigend sortiert
2. keine Leerzeichen (`,` und `:` als Trenner)
3. Schraegstriche **nicht** maskiert
4. Zeichen ausserhalb ASCII als `\uXXXX`

Entspricht `json.dumps(obj, sort_keys=True, separators=(",", ":"),
ensure_ascii=True)`.

In PHP: `json_encode($sortiert, JSON_UNESCAPED_SLASHES)` nach rekursivem
`ksort`. In JavaScript: `JSON.stringify` nach rekursivem Sortieren, danach
alle Zeichen ab U+0080 zu `\uXXXX` ersetzen.

---

## 2. Identitaet

Eine Identitaet besteht aus zwei Schluesselpaaren:

| | Verfahren | Zweck |
|---|---|---|
| `IK` | Ed25519 | bestimmt die Adresse, signiert Verzeichniseintraege, verschluesselt nie |
| `IDK` | X25519 | nimmt am Handshake teil, per BIND an `IK` gebunden |

Die Trennung erlaubt es, den Handshakeschluessel zu wechseln, ohne dass sich
die Adresse aendert.

### 2.1 Adresse

```
body     = SHA256("nyx/v1/addr"    || IK_pub)[0..19]      20 Byte
checksum = SHA256("nyx/v1/addrsum" || body)[0..1]          2 Byte
addr     = base32(body || checksum), Kleinbuchstaben, ohne Fuellzeichen
```

Ergibt 36 Zeichen. Die Pruefsumme faengt Tippfehler, nicht Faelschungen —
gegen Faelschung hilft nur `addr == addressFromKey(IK_pub)`, und genau das
prueft jede Implementierung bei jedem Eintrag.

---

## 3. Verzeichnis

### 3.1 Eintrag

```json
{
  "type": "BIND" | "PREKEY" | "REVOKE" | "RECOVER",
  "addr": "<36 Zeichen>",
  "ik_pub": "<64 hex>",
  "ts": 1786300000,
  "body": { ... },
  "sig": "<128 hex>",
  "recovery_sig": "<128 hex>"        // nur bei RECOVER
}
```

`sig` = Ed25519 ueber `"nyx/v1/record" || canonical(eintrag ohne sig und
recovery_sig)`.

Ein Eintrag ist genau dann gueltig, wenn **alle** vier Bedingungen gelten:

1. `type` ist einer der vier
2. `ik_pub` ist 32 Byte, `sig` ist 64 Byte
3. `addressFromKey(ik_pub) == addr` — die Adresse ist der Schluessel
4. die Signatur prueft durch

Bedingung 3 ist die wichtigste: ohne sie koennte jeder unter fremder Adresse
schreiben.

### 3.2 Koerper je Art

```
BIND     { "idk_pub": hex, "recovery_pub": hex|null }
PREKEY   { "spk_pub": hex, "spk_sig": hex, "valid_until": int,
           "opks": [{ "id": int, "pub": hex }, …] }
REVOKE   { "key": hex, "reason": string }
RECOVER  { "successor": "<adresse>" }
```

`spk_sig` = Ed25519 ueber `"nyx/v1/prekey-bundle" || spk_pub ||
ascii(valid_until)`.

### 3.3 Block

```json
{ "height": int, "prev": hex, "merkle_root": hex,
  "ts": int, "bits": int, "nonce": int, "records": [ … ] }
```

`blockhash = SHA256("nyx/v1/block" || canonical(kopf ohne records))`, und
dieser Hash muss mindestens `bits` fuehrende Nullbits haben.

Merkle mit Bereichstrennung: Blatt `SHA256(0x00 || canonical(eintrag))`,
Knoten `SHA256(0x01 || links || rechts)`, letzter Eintrag bei ungerader
Anzahl verdoppelt.

Ein Leichtclient laedt nur die Kopfzeilen und prueft einen Eintrag ueber
seinen Merkle-Pfad.

---

## 4. Sitzung

### 4.1 Handshake

```
DH1 = X25519(IDK_S,  SPK_E)
DH2 = X25519(EK_S,   IDK_E)
DH3 = X25519(EK_S,   SPK_E)
DH4 = X25519(EK_S,   OPK_E)          entfaellt ohne Einmal-Prekey
SK  = HKDF(DH1||DH2||DH3||DH4, "nyx/v1/x3dh-root", 64)
```

Danach werden `DH1..DH4` und `EK_priv` ueberschrieben; der verbrauchte
Einmal-Prekey wird geloescht und nicht erneut ausgegeben.

Kopf der ersten Nachricht:

```json
{ "ik_pub": hex, "idk_pub": hex, "ek_pub": hex, "spk_pub": hex,
  "opk_id": int|null }
```

### 4.2 Doppelratsche

```
Wurzelschritt   material = HKDF(dh_out, "nyx/v1/ratchet-root", 64, salt=root)
                root = material[0..31], chain = material[32..63]
Kettenschritt   chain' = HKDF(chain, "nyx/v1/ratchet-chain", 32)
                mk     = HKDF(chain, "nyx/v1/ratchet-message", 32)
Nonce           0x00000000 || uint64be(n)
```

Kopf auf der Leitung, genau 40 Byte, hexkodiert:

```
dh_pub    32 Byte
pn         4 Byte  uint32be
n          4 Byte  uint32be
```

Der Kopf ist zusaetzliche authentifizierte Daten des AEAD.

**Loeschung.** `mk` wird unmittelbar nach der Authentifizierung
ueberschrieben; zwischen Entschluesselung und Loeschung liegt keine
Ein-/Ausgabe. Uebersprungene Schluessel: hoechstens 1000 je Sitzung,
hoechstens 7 Tage, hoechstens 256 auf einmal ableitbar. Was darueber
hinausgeht, ist dauerhaft unlesbar — das ist beabsichtigt.

---

## 5. Nachricht

### 5.1 Paket

```json
{ "hdr": "<80 hex>", "ct": "<hex>", "init": { … } }
```

`init` steht nur in der allerersten Nachricht einer Sitzung.

### 5.2 Zellen

Das Paket wird gerahmt und auf ein Vielfaches von 1024 Byte aufgefuellt:

```
uint32be(laenge) || paketbytes || nullfuellung
```

Jede Zelle geht als eigene Ablage unter dem naechsten Zaehlerstand. Dadurch
hat **jede** Ablage im Netz dieselbe Groesse; Kurznachricht, Brief,
Dateiblock und Anrufsignal sind an der Ablage nicht unterscheidbar.
Erkennbar bleibt die Anzahl der Zellen, also die ungefaehre Groesse.

Obergrenze: 4096 Zellen je Nachricht.

---

## 6. Ablage

### 6.1 Zerlegung

```
w        = AONT(chiffrat)
s_0..s_n = ReedSolomon_{k,n}(w),  Vorgabe k = 10, n = 20
```

**AONT** (Rivest, Package Transform):

```
K    = 32 zufaellige Byte
c    = ChaCha20(K, counter=1, nonce=0^12, daten)
tail = K XOR SHA256("nyx/v1/aont" || c)
w    = c || tail
```

Ohne vollstaendiges `w` ist `K` nicht berechenbar und `c` von Rauschen nicht
zu unterscheiden. Deshalb ergeben `k-1` Teile nicht ein Bruchstueck, sondern
nichts.

**Reed-Solomon** ueber GF(2^8), Polynom 0x11d. Erzeugermatrix: Vandermonde
`v[i][j] = i^j` (n×k), multipliziert mit der Inversen ihrer oberen k Zeilen
— dadurch systematisch und jede Auswahl von k Zeilen invertierbar.
Auffuellung auf ein Vielfaches von k, die urspruengliche Laenge steht im
Teilkopf.

### 6.2 Teil auf der Leitung

```
uint16be index | uint16be k | uint16be n | uint32be laenge | nutzdaten
```

### 6.3 Tags

```
drop_tag(SK_tag, i, j) = HMAC-SHA256(SK_tag, uint64be(i) || uint32be(j))
```

`i` Nachrichtenzaehler, `j` Teilindex. Fuelltags sind 32 zufaellige Byte und
von echten nicht unterscheidbar.

Erstkontakt-Postfach:

```
SK_inbox(IDK_pub, epoche, fach)
  = HKDF(HKDF(IDK_pub || uint64be(epoche) || uint32be(fach), "nyx/v1/inbox"),
         "nyx/v1/drop-tag-secret")
epoche = floor(unixzeit / 3600),  fach in 0..7
```

Diese Tags kann jeder berechnen, der die Adresse kennt. Das ist der Preis
dafuer, dass ein Erstkontakt ohne Vorabsprache moeglich ist; ab der zweiten
Nachricht ist die Unterhaltung unverkettbar. Gegen Zumuellen steht der
Arbeitsnachweis.

### 6.4 Ablage beim Knoten

Der Knoten legt jeden Teil unter einem eigenen Schluessel ab:

```
k_tag = PuncturablePRF(MSK, tag)
blob  = ChaCha20-Poly1305(k_tag, nonce = SHA256("nyx/v1/store-nonce" || tag)[0..11],
                          teil, aad = tag)
```

Nach Herausgabe oder Entwertung punktiert er `MSK` an dieser Stelle.

**Punktierbare PRF** (GGM-Baum, Tiefe 48):

```
blatt(tag) = obere 48 Bit von SHA256("nyx/v1/punct-index" || tag)
kind(seed, 0) = HMAC(seed, 0x00)
kind(seed, 1) = HMAC(seed, 0x01)
k_tag         = HMAC(blattseed, 0x02 || tag)
```

Der Knoten haelt eine Menge von Teilbaum-Saatguetern, die alle Blaetter
ausser den punktierten abdecken. Punktieren heisst: den Pfad zum Blatt
aufklappen, die Geschwisterteilbaeume behalten, das Blatt fallen lassen.
Kosten: bis zu 48 zusaetzliche Eintraege je Punktierung.

### 6.5 Arbeitsnachweis je Ablage

```
SHA256("nyx/v1/put-pow" || tag || blob || uint64be(nonce))
```

muss mindestens `pow_bits` fuehrende Nullbits haben (Vorgabe 12). Der
Knoten nennt seinen Wert unter `/v1/info`.

---

## 7. Mixnetz

Ein Paket ist immer 2048 Byte:

```
Kopf   3 Faecher zu je 80 Byte  = 240 Byte
Rumpf                            1808 Byte
```

Ein Fach: `eph_pub(32) || AEAD(fachschluessel, wegangabe(32))`, Nonce
`SHA256("nyx/v1/mix-nonce" || eph_pub)[0..11]`.

Je Hop aus `X25519(eph_priv, knoten_pub)`:
`fachschluessel = HKDF(s, "nyx/v1/mix-hop")`,
`rumpfschluessel = HKDF(s, "nyx/v1/mix-stream")`.

Ein Knoten oeffnet Fach 0, schiebt die uebrigen nach vorn, haengt 80
Zufallsbytes an und rechnet den Rumpf mit ChaCha20 um. Wegangabe
`0x00…00` bedeutet: Endpunkt. Wegkennung eines Knotens:
`SHA256("nyx/v1/mix-node" || name)[0..31]`.

Unterschied zum vollstaendigen Sphinx: dort leiten sich alle Hops aus einem
fortlaufend geblendeten ephemeren Schluessel ab, was den Kopf kleiner macht.
Hier ist es ein ephemerer Schluessel je Hop — gleiche Eigenschaften, mehr
Kopfbytes, erheblich weniger Code.

---

## 8. Umschlaege

Der entschluesselte Klartext ist ein JSON-Objekt mit dem Feld `t`:

```
{"t":"chat",    "text": string, "ts": int}
{"t":"receipt", "ts": int}
{"t":"mail",    "id": hex, "subject": string, "body": string, "ts": int,
                "reply": hex|null, "att": [{"name": string, "d": base64}]}
{"t":"file",    "k":"manifest", "id": hex, "name": string, "size": int,
                "chunks": int, "digest": hex}
{"t":"file",    "k":"chunk", "id": hex, "i": int, "d": base64}
{"t":"call",    "k":"offer"|"answer"|"candidate"|"bye", "id": hex, …}
```

Der Medienschluessel eines Anrufs wird nicht uebertragen, sondern auf beiden
Seiten abgeleitet:

```
media_key = HKDF(min(SK_send,SK_recv) || max(SK_send,SK_recv),
                 "nyx/v1/call-media/<call_id>", 32)
```

Sortiert, damit beide Richtungen denselben Wert ergeben. Nach dem Auflegen
wird er verworfen.

---

## 9. Vergleichswert

```
digest = SHA256("nyx/v1/safety" || min(A,B) || max(A,B))
```

mit `A = IK_pub_A || IDK_pub_A`. Die ersten 15 Byte als Dezimalzahl, auf 36
Stellen aufgefuellt, in sechs Gruppen zu sechs Ziffern.

---

## 10. Vorgaben

| Groesse | Wert |
|---|---|
| Zerlegung | k = 10, n = 20 |
| Zellengroesse | 1024 Byte |
| Mixnetz-Paket | 2048 Byte, 3 Hops |
| Verfallszeit Ablage | 7 Tage |
| Verfallszeit Post | 30 Tage |
| Gueltigkeit signierter Prekey | 7 Tage |
| Einmal-Prekeys je Ankuendigung | 64 (Python), 32 (Web) |
| Uebersprungene Schluessel | 1000 je Sitzung, 7 Tage |
| Arbeitsnachweis je Ablage | 12 Bit |
| Postfachfaecher | 8 je Stunde |
