# Testbestand für die Bürgerabstimmung

Erzeugt `data.zip` mit 500 blanko Themen für
[florianthepro/buergerabstimmung](https://github.com/florianthepro/buergerabstimmung).
Das Archiv wird neben `index.php` ausgepackt, so dass `data/` direkt daneben liegt.

## Inhalt des Archivs

| Datei | Zweck |
| --- | --- |
| `data/buergerabstimmung.sqlite` | 500 Themen, 500 Autorenkonten, 22 Kategorien, Systemkonto |
| `data/.htaccess` | sperrt den Ordner für Zugriffe aus dem Netz |
| `data/LIESMICH.txt` | Kurzbeschreibung im Archiv selbst |

Schlüssel (`secret.key`, `server_sign.key`) liegen bewusst **nicht** im Archiv –
die Anwendung erzeugt sie beim ersten Aufruf selbst. Der Ordner muss für den
Webserver beschreibbar sein.

## Stand der Daten

* **Blanko:** keine Stimmen, keine Favoriten, keine Meldungen, keine Wertung im Text.
* **Benennung:** jedes Thema nennt den Gegenstand so, wie er in der politischen
  Beratung heißt (Gesetz, Programm, Vorhaben), nicht in umgangssprachlicher Fassung.
* **Aufbau je Thema:** Titel (Gegenstand), Ziel (was zur Abstimmung steht),
  Begründung (Sachstand: geltendes Recht, was sich ändern würde, Zuständigkeit).
* **Geltungsbereich:** durchgehend `bund`, passend zur Einstellung `nur_bund` in `index.php`.
* **Autoren:** Testkonten mit `is_seed = 1`. Beim Beenden des Testbetriebs löscht
  die Anwendung Themen und Konten selbsttätig.
* **Fristen:** alle Themen sind `active`; Enddaten liegen zwischen 45 und 330 Tagen
  in der Zukunft, damit im Testbetrieb nichts sofort schließt.
  Verteilung der Endarten: 300 × `date`, 125 × `count`, 75 × `both`.

## Neu bauen

```
php build.php --index /pfad/zu/index.php --out data.zip
```

`build.php` legt den Ordner `data/` nicht von Hand an, sondern kopiert `index.php`
in ein Arbeitsverzeichnis und ruft dort `php index.php lists` auf. Schema,
Kategorien und Systemkonto entstehen dadurch genau so, wie die laufende Seite sie
erwartet; erst danach werden die Themen eingetragen. Das Repo der Anwendung wird
dabei nur gelesen.

## Themen bearbeiten

Ein JSON je Kategorie unter `themen/`, Dateiname = Kategorie-Slug aus
`categories.json`. Felder: `titel`, `ziel`, `begruendung`.

`build.php` prüft beim Bauen:

* Feldgrenzen aus `index.php` (Titel 8–120, Ziel 10–500, Begründung 10–4000 Zeichen)
* doppelte Titel
* genau 500 Themen
* wie viele Titelpaare die Seite über `topics_similar()` als „ähnliche Themen"
  anzeigen würde (derzeit 11, alle inhaltlich verwandt)

Der letzte Punkt ist ein Hinweis, kein Abbruch: Titel mit nur zwei Wörtern ab vier
Zeichen erreichen schon über ein gemeinsames Allerweltswort die Ähnlichkeitsschwelle
der Seite.
