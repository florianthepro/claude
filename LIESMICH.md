# Nyx — mitmachen

Alles, was man braucht, um Teil des Netzes zu werden. Vier Dateien, keine
Installation, keine Fremdpakete, keine Registrierung.

```
mitmachen.sh      Startet Knoten und Client auf diesem Rechner
nyx.py            Knoten und Client für die Kommandozeile
nyx-knoten.php    Knoten für jeden Webspace mit PHP
nyx.html          Client für den Browser
```

Jede der drei Programmdateien läuft für sich allein. Man braucht nicht alle.

---

## In einer Minute anschauen

```bash
python3 nyx.py demo
```

Legt zwei Teilnehmer an, schickt eine Nachricht über zwanzig Knoten und
zeigt, was danach im Netz übrig ist. Kein Netz nötig, nichts wird
gespeichert.

## Mitmachen

```bash
./mitmachen.sh
```

Startet einen Knoten, legt bei Bedarf eine Identität an und stellt den
Web-Client bereit. Danach steht im Terminal, unter welcher Adresse beides
erreichbar ist.

Solange das Fenster offen ist, ist dieser Rechner ein Knoten und speichert
Teile fremder Nachrichten — lesen kann er sie nicht.

Weitere Aufrufe:

```bash
./mitmachen.sh pruefen     Voraussetzungen anzeigen
./mitmachen.sh knoten      nur den Knoten, ohne Client
./mitmachen.sh demo        nur die Vorführung
```

Ports und Verzeichnisse über `NYX_PORT`, `NYX_WEB_PORT`, `NYX_DATEN`.

---

## Die drei Wege einzeln

### Kommandozeile

```bash
export NYX_NODE=http://127.0.0.1:8443

python3 nyx.py init                        # Identität anlegen
python3 nyx.py wer                         # eigene Adresse
python3 nyx.py senden <adresse> "Hallo"
python3 nyx.py holen
python3 nyx.py pruefen <adresse>           # Schlüssel prüfen
```

Der Zustand liegt in `~/.nyx` (verlegbar über `NYX_HOME`): Identität,
private Prekeys, Zählerstände. Nachrichten werden nicht gespeichert.

Braucht Python 3.11. Sonst nichts.

### Eigener Knoten auf einem Webspace

`nyx-knoten.php` hochladen, zum Beispiel als `index.php`, und ein
Datenverzeichnis angeben, das von außen nicht erreichbar ist:

```
NYX_DATA=/pfad/ausserhalb/des/webroots
```

Keine Datenbank, kein Composer, keine Anmeldung. Gebraucht wird PHP 8 mit
der Erweiterung `sodium` — die ist fast überall vorhanden.

Zum Ausprobieren genügt lokal:

```bash
NYX_DATA=/tmp/nyx php -S 0.0.0.0:8080 nyx-knoten.php
```

Einstellbar über `NYX_NAME`, `NYX_DATA`, `NYX_TTL`, `NYX_POW_BITS`,
`NYX_BITS`.

### Browser

`nyx.html` über einen Webserver öffnen — nicht per `file://`, das erlauben
ES-Module nicht:

```bash
python3 -m http.server 8080
```

Dann `http://127.0.0.1:8080/nyx.html`. Beim ersten Start die Adresse eines
Knotens eintragen.

Gebraucht wird ein Browser mit X25519 in der WebCrypto-API: Chrome ab 133,
Firefox ab 130, Safari ab 17.

---

## Was passiert, wenn man eine Nachricht schickt

1. Sie wird unter einem Schlüssel verschlüsselt, den es nur für diese eine
   Nachricht gibt.
2. Sie wird in zwanzig Teile zerlegt. Zehn genügen zum Zusammensetzen,
   neun ergeben nichts — nicht ein Bruchstück, nichts.
3. Jeder Teil geht unter einem eigenen Zufalls-Tag an einen anderen Knoten.
   Kein Knoten weiß, wer sendet, wer empfängt oder welche Teile
   zusammengehören.
4. Der Empfänger holt zehn Teile, setzt zusammen, entschlüsselt — und
   löscht den Schlüssel sofort.
5. Alle zwanzig Tags werden entwertet. Ab diesem Moment ist das, was bei
   den Knoten liegen könnte, von Zufallsrauschen nicht zu unterscheiden.

Ein Knoten, der entgegen der Absprache aufbewahrt, hält danach Rauschen
fest. Das ist der Punkt: die Sicherheit hängt nicht daran, dass ein fremder
Rechner löscht.

---

## Adressen

Eine Adresse ist der öffentliche Schlüssel, in 36 Zeichen:

```
7iyuvwyn56zv6vludb77qauoxveqhggr7d3a
```

Sie entsteht auf dem eigenen Gerät. Keine Telefonnummer, keine E-Mail, keine
Anmeldung. Wer die Adresse hat, kann schreiben — mehr ist nicht nötig, und
mehr gibt es nicht.

**Ohne Sicherung der Identitätsdatei ist die Identität verloren**, wenn das
Gerät verloren geht. Es gibt keine Wiederherstellung durch Dritte; das ist
eine Eigenschaft des Entwurfs, kein Fehler darin. Im Browser: Einstellungen
→ Identität sichern. In der Kommandozeile: `~/.nyx/identitaet.json`
kopieren.

---

## Ein Netz braucht Knoten

Die Zerlegung ist auf zehn von zwanzig eingestellt. Damit die Rechnung
aufgeht, sollten die zwanzig Knoten wirklich unabhängig sein: verschiedene
Betreiber, verschiedene Netze, verschiedene Rechtsräume.

Mit weniger Knoten läuft alles weiter, aber ein Knoten hält dann mehrere
Teile — wer ihn beschlagnahmt, bekommt entsprechend mehr. Deshalb ist ein
eigener Knoten der eigentliche Beitrag: er kostet wenig und macht das Netz
für alle besser.

---

## Was das hier nicht ist

Eine Referenzimplementierung, kein Produkt. Sie hat keine unabhängige
Prüfung durchlaufen. Bekannte Schwächen, vollständig:

- Die Primitive in `nyx.py` sind reines Python und **nicht
  laufzeitkonstant**. Wer den Rechner mitbenutzt oder Laufzeiten misst, kann
  daraus Schlüsselmaterial gewinnen. Für den ernsthaften Betrieb sind sie
  durch libsodium zu ersetzen. Die PHP-Fassung benutzt bereits libsodium.
- Der Client spricht **direkt** mit den Knoten. Ein Knoten sieht damit die
  IP-Adresse — genau das, was das Mixnetz verhindern soll. Das Mixnetz ist
  gebaut und getestet, aber noch nicht angeschlossen.
- Der Arbeitsnachweis des Verzeichnisses steht auf Demo-Niveau.
- Anrufe: die Signalisierung ist geschützt, der Ton läuft direkt zwischen
  den Geräten. Beide Seiten sehen dabei die IP-Adresse der anderen. Der
  Client sagt das, bevor der Anruf beginnt.
- Das Postfach für Erstkontakte ist für jeden adressierbar, der die Adresse
  kennt. Ab der zweiten Nachricht ist die Unterhaltung unverkettbar.
- Die Identitätsdatei liegt unverschlüsselt auf der Platte (Rechte 0600).

Wer damit etwas schützt, das tatsächlich geschützt werden muss, sollte das
wissen. Der vollständige Text steht im Projekt unter
`docs/sicherheitshinweis.md`.

---

## Herkunft

Diese Dateien sind erzeugt, nicht von Hand gepflegt. Quelltext, Entwurf,
Protokollspezifikation und 123 Tests liegen im Projekt:

```
whitepaper/nyx-whitepaper.md   der Entwurf
docs/protokoll.md              verbindliche Drahtformate
impl/                          Quelltext in Python, PHP und JavaScript
tools/buendeln.py              erzeugt genau diese vier Dateien
```
