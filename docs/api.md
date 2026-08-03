# Knoten-Schnittstelle

Ein Knoten bietet zwei Dinge an: Ablagen und das Verzeichnis. Beides ueber
dieselbe HTTP-Schnittstelle, alles JSON, kein Zustand zwischen Anfragen,
keine Anmeldung.

Implementiert von `impl/python/nyx/node.py` und `impl/php/public/index.php`.
Beide sind gegen dieselben Testfaelle geprueft; ein Client muss nicht
wissen, womit er spricht.

---

## Was ein Knoten nicht tut

Diese Liste gehoert zur Schnittstelle, nicht zum Kleingedruckten:

- **Kein Zugriffsprotokoll.** Wer wann welchen Tag abgefragt hat, waere
  genau die Metadatenspur, die das uebrige System vermeidet.
- **Keine Existenzauskunft.** Es gibt kein „liegt hier etwas fuer dich".
  Jede Abfrage ist eine Abholung, und eine Abholung entwertet den Tag.
- **Keine sprechenden Fehler.** Eine Meldung, die den Zustand des Knotens
  verraet, ist selbst eine Metadatenquelle. Nach aussen geht der Grund einer
  Ablehnung, nicht der Zustand.
- **Keine Zuordnung.** Der Knoten kann Tags weder Absendern noch Empfaengern
  noch einander zuordnen. Er koennte es nicht einmal, wenn er wollte.

---

## Allgemeines

Alle Antworten sind JSON. Fehler:

```json
{ "error": "Arbeitsnachweis fehlt oder ist zu schwach" }
```

| Code | Bedeutung |
|---|---|
| 200 | in Ordnung |
| 400 | Anfrage abgelehnt (Grund in `error`) |
| 404 | unbekannter Pfad |
| 500 | interner Fehler, ohne Einzelheiten |

CORS ist offen (`Access-Control-Allow-Origin: *`), damit ein Web-Client
beliebige Knoten ansprechen kann. Das ist unbedenklich: es gibt nichts,
worauf ein fremder Ursprung Zugriff bekaeme, das er nicht ohnehin haette.

---

## `GET /v1/info`

```json
{ "version": "nyx/1", "name": "php-knoten", "pow_bits": 12,
  "ttl": 604800, "entries": 143, "height": 27 }
```

`pow_bits` muss der Client vor dem ersten `store` lesen — er bestimmt den
Arbeitsnachweis.

---

## `POST /v1/store`

Legt einen Teil ab.

```json
{ "tag": "<64 hex>", "blob": "<hex>", "ttl": 604800, "nonce": 91123 }
```

`ttl` darf `null` sein (Vorgabe des Knotens). `nonce` loest den
Arbeitsnachweis aus Abschnitt 6.5 des Protokolls.

```json
{ "ok": true, "tag": "…", "expires": 1786900000, "node": "php-knoten" }
```

Abgelehnt wird: Tag nicht 32 Byte, Ablage groesser als 1 MiB, Tag bereits
belegt, Tag bereits verbraucht, Arbeitsnachweis zu schwach.

---

## `POST /v1/fetch`

Sammelabfrage, hoechstens 256 Tags.

```json
{ "tags": ["<hex>", "<hex>", …] }
```

```json
{ "results": { "<hex>": "<hex>", "<hex>": null } }
```

**Jeder gelieferte Tag ist danach verbraucht**: der Eintrag wird geloescht
und der Speicherschluessel des Knotens an dieser Stelle punktiert. Ein
zweiter Abruf liefert `null`.

Fuelltags werden genauso behandelt wie echte. Der Knoten kann nicht
unterscheiden, welcher Tag den Anfragenden interessiert hat.

---

## `POST /v1/void`

Entwertet Tags, ohne Daten zu uebertragen.

```json
{ "tags": ["<hex>", …] }      →      { "voided": 9 }
```

Der Empfaenger braucht nur k der n Teile. Die uebrigen wuerden bis zum
Verfall liegen bleiben und waeren fuer einen hortenden Knoten weiterhin
lesbar. Deshalb entwertet der Client nach jeder erfolgreichen Abholung
**alle** n Tags.

Ein boeswilliger Knoten kann den Aufruf ignorieren. Genau deshalb haengt
keine Garantie des Systems daran — siehe Whitepaper 7.8.

---

## `POST /v1/proof`

Aufbewahrungsnachweis ueber einen zufaelligen Ausschnitt des Bestandes.

```json
{ "challenge": "<hex>" }
```

```json
{ "node": "php-knoten", "count": 143, "sampled": 8, "proof": "<hex>" }
```

Nur mit den tatsaechlich vorhandenen Daten berechenbar. Bleibt der Nachweis
aus, verfaellt das Pfand des Knotens.

---

## Verzeichnis

### `GET /v1/dir/headers`

```json
{ "headers": [ { "height": 0, "prev": "00…", "merkle_root": "…",
                 "ts": 0, "bits": 12, "nonce": 4711 }, … ] }
```

Was ein Leichtclient laedt: Kopfzeilen ohne Eintraege.

### `POST /v1/dir/submit`

```json
{ "record": { … } }      →      { "ok": true, "height": 28 }
```

Der Knoten prueft den Eintrag nach den vier Regeln aus Protokoll 3.1 und
lehnt ihn andernfalls mit 400 ab.

### `POST /v1/dir/resolve`

```json
{ "addr": "<36 zeichen>" }
```

```json
{
  "identity": { "address": "…", "ik_pub": "…", "idk_pub": "…" },
  "bundle":   { "spk_pub": "…", "spk_sig": "…", "valid_until": 1786900000,
                "opks": [ { "id": 0, "pub": "…" }, … ] },
  "history":  [ { "height": 3, "type": "BIND", "ts": …, "key": "…" }, … ]
}
```

`history` ist der praktische Nutzen der Kette. Ein untergeschobener
Schluessel erscheint dort als zusaetzlicher Eintrag. Ein Client, der diese
Liste anzeigt, macht den Angriff sichtbar, ohne dass der Nutzer Pruefsummen
von Hand vergleichen muss.

Abfragen an das Verzeichnis gehoeren im Betrieb ueber das Mixnetz — sonst
kann ein Knoten ablesen, fuer wen sich jemand interessiert.

### `POST /v1/dir/proof` (nur Python-Knoten)

```json
{ "addr": "…", "type": "BIND" }
```

Liefert Eintrag, Merkle-Pfad und Blockkopf, damit ein Leichtclient ohne die
vollstaendige Kette pruefen kann.

---

## Ein eigener Knoten

```bash
# Python
python3 -m nyx.cli knoten --port 8443 --pow 12

# PHP
NYX_DATA=/var/lib/nyx NYX_POW_BITS=12 php -S 0.0.0.0:8080 -t impl/php/public
```

Hinter Apache oder nginx: `impl/php/public` als DocumentRoot, alle Anfragen
auf `index.php`. Erforderlich ist die Erweiterung `sodium`; eine Datenbank
wird nicht gebraucht.

Umgebungsvariablen der PHP-Fassung: `NYX_NAME`, `NYX_DATA`, `NYX_TTL`,
`NYX_POW_BITS`, `NYX_BITS`.
