# Stimmwerk — Whitepaper

**Digitale Bürgerbeteiligung mit dem Personalausweis. Themen einbringen, abstimmen, gemeinsam prüfen.**

| | |
|---|---|
| Arbeitstitel / Marke | **Stimmwerk** |
| Domain (vorgeschlagen) | **stimmwerk.de** (Alternativen: mitstimme.de, buergerwerk.de, stimmwerk.eu) |
| Status | Konzept & technischer Prototyp (Testbetrieb, keine offizielle Seite einer Behörde) |
| Sprachen | Deutsch (Standard), Englisch |
| Version | 1.0 · August 2026 |

> **Wichtiger Hinweis:** Stimmwerk ist ein privates Konzept- und Demonstrationsprojekt.
> Es ist **keine offizielle Seite der Bundesregierung oder einer Behörde** und erhebt
> nicht den Anspruch, staatliche Verfahren rechtlich zu ersetzen. Der Prototyp zeigt,
> wie eine solche Plattform funktionieren könnte. Solange der Testbetrieb läuft, zeigt
> die Seite dauerhaft ein entsprechendes Hinweisbanner an (im Code über die
> Konfigurationsvariable `show_test_banner = true` gesteuert). Eine frische
> Installation startet im **Testmodus**; er wird über die Oberfläche beendet und
> löscht dabei alle bis dahin entstandenen Testdaten (Kapitel 5.3b).

---

## 1. Zusammenfassung

Stimmwerk ist eine Plattform für direkte, fortlaufende Bürgerbeteiligung. Jede Person
mit deutschem Personalausweis kann:

- **Themen einbringen** — maximal eines pro Tag, mit Ziel, Begründung, Kategorie und
  räumlicher Ebene (Kommune, Landkreis, Bundesland, Deutschland),
- **abstimmen** — dafür oder dagegen; wer sich enthält, stimmt schlicht nicht ab,
- **rechtswidrige Inhalte melden** — geprüft nicht von einer Redaktion, sondern von
  einer zufällig ausgelosten **Bürger-Jury** (1 % aller aktiven Ausweis-Pseudonyme),
- **seine Gesamtansicht abrufen** — durch Auflegen des Ausweises (NFC) erscheinen alle
  eigenen Themen, Stimmen und Favoriten.

Die Identität wird ausschließlich über die **eID-Funktion des Personalausweises**
nachgewiesen: Der Chip beweist kryptographisch die Echtheit der Karte (privater
Schlüssel im Chip). Die Plattform kennt als Identität nur den **öffentlichen
Schlüssel** — keinen Namen, keine Adresse, kein Geburtsdatum, kein abgeleitetes
Pseudonym. Eine Karte = ein Konto. Das persönliche Profil wird an diesen
öffentlichen Schlüssel **verschlüsselt** und vom Server **signiert**
(manipulationssicher); die Stimmen werden **unverkettbar** gespeichert, sodass
niemand rückschließen kann, wer wie gestimmt hat.

Die Plattform ist bewusst **schlicht und amtlich-neutral** gestaltet, funktioniert
gleichwertig auf Smartphone und PC, passt sich hellem und dunklem Systemdesign an und
ist auch an öffentlichen Geräten (z. B. in Gemeindebüros oder Bibliotheken) nutzbar —
für Menschen ohne eigenes Gerät.

---

## 2. Marke, Domain, Gestaltung

### 2.1 Name

**Stimmwerk** — kurz, deutsch, merkfähig. „Stimme“ (Abstimmung, Mitsprache) und
„Werk“ (etwas Solides, Gemeinschaftliches). Der Name klingt seriös, ist aber bewusst
**nicht** mit staatlichen Kennzeichen verwechselbar: kein Bundesadler, keine
Bundesfarben-Imitation, kein „bund.de“-Look. Vor einem echten Betrieb sind Marken-
und Domainrecherche erforderlich.

### 2.2 Domain

Vorschlag: **stimmwerk.de** (kurz, aussprechbar, .de passt zum Zweck).
Ausweichoptionen: mitstimme.de, buergerwerk.de, stimmwerk.eu, stimmwerk.org.

### 2.3 Gestaltungsprinzipien

1. **Schlicht wie eine App:** ruhige graue Fläche, weiße Karten mit weichen
   Ecken, Haarlinien statt Rahmen, Systemschrift, **ein** blauer Akzent —
   die Formensprache verbreiteter Messenger-/Systemoberflächen (iOS, Signal).
   Keine Verläufe, keine Deko-Effekte, keine „KI-Regenbogen“-Ästhetik; Rot
   bleibt destruktiven Aktionen (Löschen) vorbehalten, Gelb allein dem
   Testbetrieb-Banner.
2. **Keine KI-Hinweistexte** auf der Seite. Die Seite spricht als Produkt, nüchtern
   und in Sie-Form. Es steht nur Text da, der eine Entscheidung trägt:
   Erklärsätze unter selbsterklärenden Überschriften, Wiederholungen von Zahlen
   und Beschriftungen wie „Eingebracht am“ entfallen.
3. **Hell/Dunkel automatisch:** Das Design folgt ausschließlich der
   Systemeinstellung (`prefers-color-scheme`) — es wird bewusst nichts im
   Browser gespeichert, auch keine Design-Präferenz.
4. **Symbolhafter Einstieg:** Beim Sitzungsbeginn nur der Schriftzug und die
   Sprachwahl — **Deutsch/English als Knöpfe mit Flagge**, ohne Bildmarke; die
   Anmeldung führt ein Ausweis-Piktogramm mit NFC-Wellen an, Text bleibt
   minimal. Die Kopfzeile der Seite trägt weder Wortmarke noch Anmeldestatus,
   sondern nur die nötigen Bedienelemente; die Fußzeile nur Impressum und
   Datenschutz.

   **Bildmarke (Tab-/App-Icon):** ein **Wahlkreuz im Feld** — scharfe Kanten,
   keine abgerundeten Ecken, keine Farbe. Als SVG folgt es der
   Systemeinstellung (schwarze Fläche mit weißem Kreuz im Hellmodus, weiße
   Fläche mit schwarzem Kreuz im Dunkelmodus); PNG und ICO für Safari/iOS
   liefern dieselbe Marke als geschlossene dunkle Kachel.
5. **Responsiv:** eine Codebasis für Smartphone, Tablet, PC und Terminals; alle
   Funktionen sind ohne JavaScript nutzbar (JavaScript verbessert nur Details:
   Countdown und NFC-Auslösung am Smartphone).
6. **Testbetrieb-Banner:** Solange die Konfigurationsvariable
   `show_test_banner` auf `true` steht (Auslieferungszustand), zeigt jede Seite oben
   ein deutliches Banner: *„Testbetrieb — keine offizielle Seite der
   Bundesregierung oder einer Behörde.“* Der **Testmodus** selbst ist keine
   Variable, sondern ein Zustand der Installation (Kapitel 5.3b).
7. **Barrierearmut:** semantisches HTML, Tastaturbedienung, ausreichende Kontraste.
   Die Abstimmungsbalken nutzen **Akzentblau gegen Neutralgrau** — auf
   Farbfehlsichtigkeit geprüft (CVD-Abstand ΔE 18,3 hell / 16,6 dunkel) — und
   tragen immer Beschriftung samt Prozentwert; Bedeutung hängt nie an Farbe
   allein. Vollständige BITV-Konformität ist Ziel der Ausbaustufe.

---

## 3. Leitidee und Einordnung

Stimmwerk versteht sich als **ständiger Meinungsbildungs-Kanal**: Statt alle vier
Jahre ein Kreuz zu machen, können Bürgerinnen und Bürger laufend Anliegen einbringen
und gewichten — von der Radweg-Frage in der Gemeinde bis zur bundespolitischen
Grundsatzfrage. Die Ergebnisse sind ein präzises, manipulationsarmes Stimmungsbild,
das Politik auf allen Ebenen nutzen kann.

Bewusste Abgrenzung:

- Stimmwerk **ersetzt keine rechtlich bindenden Verfahren** (Wahlen, Volksentscheide).
  Ergebnisse sind Meinungsbilder; eine rechtliche Bindung wäre ein politischer
  Folgeschritt, kein technischer.
- Die Plattform ist **strikt neutral**: Zum Start existieren ausschließlich
  Kategorien über das gesamte politische Spektrum, keine vorbefüllten Themen
  (siehe 6.3); die Moderation erfolgt durch geloste Jurys nach
  strafrechtlichen — nicht politischen — Kriterien.

---

## 4. Zugänge und Zielgruppen

- **Smartphone:** Ausweis per NFC ans Gerät halten, PIN in der eID-App eingeben,
  fertig. Der Browser selbst berührt die Karte nie.
- **PC:** eID-Client (z. B. AusweisApp) mit USB-Kartenleser oder gekoppeltem
  Smartphone als Leser.
- **Öffentliche Geräte:** Gemeinden, Bürgerbüros und Bibliotheken können Terminals
  bereitstellen. Dafür ausgelegt: kurze Sitzungen, automatische Abmeldung nach
  Inaktivität, keine lokalen Datenspuren, prominenter Abmelden-Knopf.
- **Fremde Geräte:** Da die Anmeldung nur mit physischer Karte + PIN funktioniert und
  keine Passwörter existieren, kann bedenkenlos das Gerät einer anderen Person
  genutzt werden.

Sprachen: **Deutsch und Englisch**. Die Wahl erfolgt beim Sitzungsbeginn,
gilt für die Sitzung und wird **nirgends gespeichert** — weder im Browser noch
am Konto. Ohne aufgelegten Ausweis ist die Seite nicht sichtbar (nur Anmeldung
und Rechtliches sind offen).

---

## 5. Identität: die eID des Personalausweises

### 5.1 Prinzip

Jeder deutsche Personalausweis (seit 2010) enthält einen Chip mit
Online-Ausweisfunktion (eID). Die Echtheit beweist der Chip kryptographisch: Er
besitzt **private Schlüssel, die das Chipmaterial nie verlassen**, und die Gegenseite
prüft die zugehörigen Zertifikate gegen die **staatliche Zertifikatskette** (Country
Verifying CA / Document Verifier, BSI-Standards TR-03110, TR-03130). Kopierte oder
gefälschte Karten fallen bei dieser Prüfung durch.

Für Stimmwerk entscheidend ist die Funktion **„dienstespezifisches Kennzeichen“
(Restricted Identification)**: Der Chip errechnet pro Dienst ein stabiles Pseudonym.

- Dasselbe Pseudonym bei jedem Anhalten derselben Karte bei Stimmwerk → **ein
  Konto pro Karte**, Wiedererkennung ohne Klarnamen.
- Ein anderes Pseudonym bei jedem anderen Dienst → **keine Verkettbarkeit** über
  Dienste hinweg.
- Stimmwerk fragt **keine** Klardaten ab (kein Name, keine Anschrift, kein
  Geburtsdatum). Es wird auch **kein eigenes Pseudonym erzeugt oder
  zugeordnet**: Identität ist unmittelbar der öffentliche Schlüssel („on
  the go“); Stimmen werden auf dem Server direkt für diesen Schlüssel
  eingetragen.

### 5.2 Ablauf (Produktion)

1. Nutzer wählt „Ausweis auflegen“. Die Plattform startet über den eID-Client
   (AusweisApp, Schnittstelle nach TR-03124) eine Authentisierung beim zugelassenen
   **eID-Server** (TR-03130).
2. Nutzer hält die Karte an das NFC-Smartphone bzw. den Kartenleser und gibt die
   sechsstellige **Ausweis-PIN** ein.
3. Chip und eID-Server authentisieren sich gegenseitig (PACE, Terminal- und
   Chip-Authentisierung); der eID-Server prüft Echtheit und Sperrliste und übermittelt
   Stimmwerk **nur das Pseudonym**.
4. Stimmwerk bildet daraus den HMAC und meldet die Sitzung an.

Voraussetzung für den Echtbetrieb ist ein **Berechtigungszertifikat** der
Vergabestelle für Berechtigungszertifikate (Bundesverwaltungsamt) mit dem
Berechtigungsumfang „pseudonymer Zugang“ sowie ein zertifizierter eID-Server
(eigenbetrieben oder als Dienstleistung).

### 5.3 Prototyp (dieses Repository)

Der Prototyp bildet das Verfahren originalgetreu nach: Eine simulierte
Testkarte im Browser übernimmt die Rolle des Chips und hält ein echtes
Ed25519-Schlüsselpaar (libsodium). Beim „Ausweis auflegen“ signiert der
private Schlüssel eine Zufallsnachricht; der Server prüft die Signatur
gegen den öffentlichen Schlüssel und leitet daraus das Pseudonym ab.
**Zwei getrennte Vorgänge:**

1. **Profil laden (initiales Anhalten):** Die statische Challenge ist der
   öffentliche Schlüssel selbst, zeitgebunden versiegelt. Der Server öffnet
   den Nachweis mit dem öffentlichen Schlüssel und liefert die
   **profil.yaml** (alle eigenen Stimmen, Themen, Favoriten, Jury-Status)
   in den Browser; der Abmelde-Knopf löscht sie dort wieder. Der Nachweis
   ist **TOTP-artig zeitbegrenzt** (5-Minuten-Fenster, Anmeldung gilt
   höchstens zwei Fenster) — danach ist erneutes Anhalten nötig.
2. **Stimmabgabe und jede andere Änderung (unabhängig davon):** Die Karte
   erstellt je Vorgang einen **versiegelten Umschlag** (kombinierte
   Signatur über Aktion + Zeitfenster + Zufallswert); der Server
   **öffnet ihn mit dem öffentlichen Schlüssel** und trägt die Stimme für
   genau diesen Schlüssel ein. Derselbe Vorgang ergibt zu anderer Zeit
   einen anderen Umschlag; alte Umschläge verfallen.

Zusätzlich trägt jedes Formular ein **Einmal-Token** (beim Einlösen
verbraucht — Wiederholungen laufen ins Leere), und im Browser liegt außer
der Sitzungs-ID nur die bewusst geladene profil.yaml. Am Smartphone ist
der NFC-Leser auf der Anmeldeseite automatisch scharf: Das Auflegen des
(echten) Personalausweises löst die Anmeldung direkt aus (Web NFC; der
Knopf bleibt Rückfall und Berechtigungsgeste).

### 5.3b Testmodus als Zustand, nicht als Variable

Eine frische Installation startet **im Testmodus**. Er ist kein Schalter in
der Konfigurationsdatei, sondern ein Zustand in der Datenbank
(`schema_info.test_mode`), damit eine Vorführung ohne Codeänderung möglich
ist und der Übergang in den Echtbetrieb protokolliert wird.

- **Im Testmodus** zeigt die Anmeldeseite **genau einen Knopf**. Er erzeugt
  eine zufällige, als gültig behandelte Sitzung; Ausweis-Aufforderungen bei
  Änderungen entfallen. Das Banner bleibt unabhängig davon sichtbar.
- **Beendet wird er über die Oberfläche**: ein Chip „Testmodus“ in der
  Kopfzeile, ein Bestätigungsfenster mit Haken. Das Beenden **löscht alle bis
  dahin entstandenen Daten** — Themen, Stimmen, Favoriten, Meldungen,
  Jury-Sitze, Konten, Test-Ausweise und die Allowlist — und schaltet dauerhaft
  in den Echtbetrieb. Kategorien und System-Konto bleiben. Der Schritt ist
  bewusst nicht umkehrbar.
- **Danach** existiert kein Weg mehr in die Anwendung, der ohne Ausweis
  auskommt: Die Anmeldeseite bietet nur noch die Ausweis-Apps an.

Der Wechsel auf einen echten eID-Server ersetzt nur den Karten-Block; Regeln
und Abläufe bleiben identisch.

### 5.3c Anbindung der AusweisApp (TR-03124/TR-03130)

Die Anmeldung mit der AusweisApp ist direkt eingebaut und braucht auf der
Serverseite nichts als den Webserver und diese Datei:

1. Der Knopf „Mit AusweisApp anmelden“ leitet den Browser auf die
   **Aktivierungsadresse des eID-Clients** nach TR-03124:
   `http://127.0.0.1:24727/eID-Client?tcTokenURL=…`. Die AusweisApp — am PC wie
   am Smartphone — fängt diese Adresse ab.
2. Die App holt das **tcToken** unter `/eid/tctoken` ab. Sie tut das als
   eigener HTTP-Client **ohne Browser-Cookie**; deshalb trägt die tcTokenURL
   einen **Einmal-Nonce** (10 Minuten gültig), der Browsersitzung und
   Ausweis-Vorgang verbindet.
3. Mit hinterlegtem **eID-Server** (TR-03130, `eid_server_url`) fordert der
   Server dort per `useID` eine Sitzung an und liefert der App ServerAddress,
   SessionIdentifier und RefreshAddress. Die App liest den Chip PIN-geschützt
   aus und schickt den Browser zurück auf `/eid/callback`.
4. **Ohne** eID-Server liefert das tcToken bewusst nur eine
   `CommunicationErrorAddress`. Die AusweisApp bricht sauber ab, der Browser
   kehrt zurück — **angemeldet wird niemand**. Das ist die ehrliche Grenze:
   Einen eID-Server darf nur betreiben, wer ein **Berechtigungszertifikat des
   BVA** besitzt (Kapitel 5.3a).

### 5.3a Autorisierte Schlüssel (Allowlist) und ehrliche Grenzen der eID

Anmelden kann sich ausschließlich, wessen **öffentlicher Schlüssel in einer
serverseitigen Allowlist** steht (`data/authorized_keys.yaml`) und wer den
passenden **privaten Schlüssel** besitzt (zeitgebundene Signatur-Challenge).
Damit ist ausgeschlossen, dass ein Knopfdruck ohne Ausweis oder mit einem
fremden/gefälschten Schlüssel Zugang gewährt (fail-closed).

Ehrliche Einordnung zur oft gewünschten „Liste aller Ausweis-Schlüssel“:
Eine solche staatliche Liste **existiert nicht** und wäre auch nicht
wünschenswert (sie wäre ein Register der kryptographischen Identität aller
Bürgerinnen und Bürger). Der Personalausweis weist sich stattdessen über die
**BSI-Zertifikatskette** (TR-03110: Chip-Authentisierung gegen CVCA/DV) aus,
geprüft durch einen zertifizierten **eID-Server** (TR-03130); das Auslesen des
Chips erfolgt PIN-geschützt ausschließlich über die **AusweisApp** (ein Browser
kann den Chip nicht lesen). Im Prototyp ist die Allowlist deshalb der
Platzhalter für genau diese Vertrauensprüfung: Der Modus `eid` übergibt an den
eID-Server/die AusweisApp; der Modus `demo` nutzt per `issue-card` ausgegebene,
autorisierte Test-Ausweise. Die Funktion `sync-keys` ist der Anschlusspunkt für
eine eigene Trust-Liste bzw. eID-Server-Anbindung – kein Zugriff auf ein
Behördenregister.

### 5.4 Grenzen und Missbrauchsszenarien

- **Karte ≠ Person am Gerät:** Wie bei jeder eID-Nutzung kann eine Person freiwillig
  ihre Karte + PIN einer anderen überlassen. Das ist rechtswidrig, skaliert aber
  schlecht (physische Karte nötig) — genau dadurch werden Bot-Netze und
  Massen-Manipulation wirksam verhindert.
- **Verlorene/gesperrte Ausweise** fallen über die Sperrlistenprüfung des eID-Servers
  heraus.
- **Alters-/Staatsangehörigkeitsfragen:** Die eID steht auch Unions­bürgern (eAT,
  eID-Karte) offen; ob deren Teilnahme gewünscht ist, ist eine politische
  Konfigurationsentscheidung (Berechtigungszertifikat kann die Kartentypen
  einschränken). Der Prototyp behandelt alle Karten gleich.

---

## 6. Funktionen im Einzelnen

### 6.1 Themen einbringen — „1 Thema pro Tag“

- Jedes Pseudonym kann **ein Thema pro Kalendertag** (Zeitzone Europe/Berlin)
  erstellen; um **00:00 Uhr** beginnt der nächste Tag. Die Regel ist zusätzlich zur
  Anwendungslogik als **Datenbank-Constraint** verankert (UNIQUE über Autor +
  Erstelldatum) — sie kann also auch durch Programmierfehler nicht umgangen werden.
- Pflichtfelder beim Erstellen:
  - **Titel** (kurz, prägnant),
  - **Ziel** — was soll konkret erreicht werden?
  - **Begründung** — warum?
  - **Kategorie** (siehe 6.3),
  - **Geltungsbereich** — eine hierarchische **Auswahl statt Freitext**,
    wie auf Behördenseiten: Deutschland → Bundesland → Landkreis/kreisfreie
    Stadt (eingebaute Gebietsliste mit allen 16 Ländern und rund 400
    Kreisen; die Gemeindeebene folgt in der Ausbaustufe über das amtliche
    Gemeindeverzeichnis, ARS/Destatis).
- Themen sind nach Veröffentlichung **unveränderlich** (keine stille Umdeutung nach
  bereits abgegebenen Stimmen). Tippfehler-Korrekturen wären eine Ausbaustufe mit
  Versionsanzeige.

### 6.2 Abstimmen

- Pro Thema und Ausweis genau eine Stimme: **dafür** oder **dagegen**.
- **Neutral = nicht abstimmen.** Enthaltung wird nicht als eigene Stimmart gezählt.
- Die eigene Stimme ist **24 Stunden lang** änderbar oder zurückziehbar; danach
  ist sie fest (in „Meine Übersicht“ als eigene Gruppe „kürzlich abgestimmt,
  noch änderbar“ sichtbar).
- **Stimmen sind unverkettbar gespeichert:** In der Datenbank steht kein
  Ausweis-Bezug, sondern nur ein HMAC aus Thema + öffentlichem Schlüssel mit
  serverseitigem Geheimnis. Doppelstimmen sind ausgeschlossen, doch ohne das
  Geheimnis lässt sich nicht rückschließen, welcher Ausweis wie gestimmt hat.
- **Abstimmungsende je Thema — Datum, Zielwert oder beides:** Beim Einbringen
  sind zwei Bedingungen ankreuzbar: ein **Enddatum** und ein **Zielwert**
  (Einheit „X Stimmen“ für eine feste Zahl oder „% Stimmen“ für einen Anteil
  der registrierten Ausweise).
  Gesetzt werden darf eine von beiden **oder beide zugleich** — dann endet die
  Abstimmung, **was zuerst eintritt**. Prozentangaben werden beim Anlegen in
  eine absolute Zahl umgerechnet (mindestens 10 Stimmen), damit das Ziel im
  Verlauf nicht mit der Nutzerzahl wandert. Danach ist das Thema beendet;
  Verfasser können ihr Thema **bearbeiten und löschen**.
- Ergebnisse sind live sichtbar (Anzahl dafür/dagegen, Anteil, Balkendarstellung mit
  Textbeschriftung).

### 6.3 Kategorien — Neutralität durch Breite, keine vorbefüllten Themen

Damit die Plattform von Beginn an nicht als politisch gefärbt wahrgenommen wird,
gilt das **Breitenprinzip auf Kategorien-Ebene**: Zum Start existieren
ausschließlich die Kategorien — bewusst viele, quer über das gesamte politische
Spektrum. **Themen werden nicht vorbefüllt**; jeder einzelne Inhalt der
Plattform stammt aus der Bürgerschaft. So kann keine Startauswahl als
redaktionelle oder politische Setzung gelesen werden.

Kategorien (Start-Satz, erweiterbar):

Umwelt & Klima · Energie · Wirtschaft & Mittelstand · Arbeit & Soziales · Rente &
Alterssicherung · Gesundheit & Pflege · Bildung & Forschung · Familie & Jugend ·
Migration & Integration · Innere Sicherheit · Justiz & Bürgerrechte · Digitales &
Verwaltung · Verkehr & Infrastruktur · Wohnen & Mieten · Landwirtschaft & Ernährung ·
Finanzen & Steuern · Europa & Außenpolitik · Verteidigung · Kultur, Medien & Sport ·
Verbraucherschutz · Kommunales & Ehrenamt · Demokratie & Beteiligung

### 6.4 Favoriten und Gesamtansicht („Meine Übersicht“)

- Kategorien und Gebiete lassen sich **merken**: Am Thema steht dafür ein
  **Lesezeichen-Symbol**, das ein kleines Auswahlfeld mit den beiden konkreten
  Namen öffnet (Kategorie und Gebiet); Gemerktes ist im Feld hervorgehoben und
  wird durch erneutes Antippen wieder entfernt. Gemerkte Einträge filtern die
  Themenlisten und stehen — am Schlüssel gespeichert — auf jedem Gerät bereit.
- Nach dem Anhalten des Ausweises zeigt **„Meine Übersicht“**:
  - alle Themen, für die man gestimmt hat (mit eigener Stimme und aktuellem Stand),
  - alle selbst eingebrachten Themen (mit Status),
  - Favoriten,
  - anstehende Jury-Aufgaben und die eigene Sprach­einstellung.
- Konto & Daten lassen sich jederzeit löschen (siehe 9).

### 6.5 Sprache

Deutsch ist Standard; Englisch vollwertig verfügbar. Die Wahl wird in der Sitzung
und — bei angemeldeten Nutzern — **am Pseudonym** gespeichert.

---

## 7. Meldung rechtswidriger Inhalte und Bürger-Jury

### 7.1 Grundsatz: Recht statt Richtung

Gemeldet werden können **mutmaßlich rechtswidrige Inhalte** — nicht politische
Meinungen. Zur Einordnung, weil danach oft gefragt wird: Auch extreme politische
Auffassungen sind in Deutschland **nicht als solche strafbar**; die
Meinungsfreiheit (Art. 5 GG) schützt auch scharfe und radikale Positionen. Strafbar
sind konkrete Delikte — und genau diese bildet der Kriterienkatalog ab:

| Kriterium | Anknüpfung |
|---|---|
| Volksverhetzung | § 130 StGB |
| Verwenden verbotener Kennzeichen | §§ 86, 86a StGB |
| Aufruf zu Gewalt oder Straftaten | §§ 111, 126 StGB |
| Terror-Propaganda / Werbung für verbotene Organisationen | §§ 86, 129a/b StGB |
| Beleidigung, üble Nachrede, Verleumdung | §§ 185–187 StGB |
| Bedrohung | § 241 StGB |
| Veröffentlichung privater Daten (Doxxing) | § 126a StGB, DSGVO |
| Sonstiger mutmaßlich strafbarer Inhalt | Auffangtatbestand |

Eine Meldung besteht aus genau **einem Grund: dem Verstoß gegen ein
Gesetz**. Beim Melden führt ein Suchfeld (Schlagwort oder
Paragraphennummer) zum eingebauten Gesetzesregister; der gewählte
Paragraph wird **wortgleich (1:1) zitiert** und der Jury unverändert
vorgelegt. Freitext gibt es nicht — bewertet wird ausschließlich am
zitierten Gesetz.

### 7.2 Bürger-Jury statt Redaktionsmoderation

Über die Meldung entscheidet keine Redaktion, sondern eine **zufällig geloste Jury
aus der Nutzerschaft** — das Losverfahren (wie beim Schöffenamt) macht gezielte
Beeinflussung praktisch unmöglich.

**Auswahl:**

- **1 % aller bereits auf der Plattform verwendeten Ausweis-Pseudonyme** wird per
  kryptographisch sicherem Zufall (CSPRNG) gezogen; Mindestgröße konfigurierbar
  (Standard: 5), damit das Verfahren auch bei kleiner Nutzerschaft funktioniert.
- **Ausgeschlossen** sind: Pseudonyme, die bereits einer **laufenden** Meldung als
  Jury zugeteilt sind; die meldende Person; die Autorin/der Autor des gemeldeten
  Themas; sowie Pseudonyme in der **Karenzzeit** — wer eine abgeschlossene
  Jury-Runde hinter sich hat, ist erst **nach 3 Tagen** wieder losbar.

**Ablauf und Fristen (alle Zeiten Europe/Berlin):**

1. Meldung wird erstellt → Jury wird sofort gelost, die Abstimmung **startet zur
   nächsten Mitternacht (00:00)**.
2. Ab Start läuft die reguläre Abstimmungsfrist von **24 Stunden**.
3. Nach Ablauf der 24 Stunden wird entschieden, **sofern mindestens 0,5 %** (bezogen
   auf die Nutzerschaft, d. h. die Hälfte der Jury; Mindestquorum konfigurierbar)
   ihre Stimme abgegeben haben.
4. Ist das Quorum nicht erreicht, **läuft die Meldung weiter**, bis es erreicht ist;
   dann wird unmittelbar entschieden.

**Stimmoptionen der Jury:** *bestätigen* (Inhalt verstößt), *ablehnen* (kein
Verstoß), *Enthaltung* (zählt für das Quorum, nicht für die Mehrheit).
**Entscheidung:** einfache Mehrheit bestätigen > ablehnen → Inhalt wird entfernt
(Platzhalterseite „nach Gemeinschaftsprüfung entfernt“); sonst bleibt er stehen.
Bei Gleichstand bleibt der Inhalt stehen (im Zweifel für die Meinungsfreiheit).

**Mitwirkungspflicht:** Wer ausgelost wurde und noch nicht abgestimmt hat, wird beim
**nächsten Anhalten des Ausweises** (Sitzungsbeginn) zuerst zur Jury-Entscheidung
geführt und kann die übrigen Funktionen erst danach nutzen — oder wartet, bis die
Meldung abgeschlossen ist. Enthaltung ist ausdrücklich zulässig; niemand wird zu
einem inhaltlichen Urteil gezwungen.

**Schutz vor Melde-Missbrauch:** je Pseudonym maximal 3 Meldungen pro Tag; je Thema
höchstens **eine offene** Meldung (weitere Meldungen desselben Themas werden auf die
laufende Prüfung verwiesen); dasselbe Pseudonym kann dasselbe Thema nur einmal
melden. Alle Grenzwerte sind serverseitig durchgesetzt.

### 7.3 Grenzen des Verfahrens

Die Bürger-Jury ist eine **Plattform-Moderation**, kein Strafverfahren. Offenkundig
strafbare Inhalte können unabhängig vom Juryergebnis zusätzlich den
Strafverfolgungsbehörden gemeldet werden; gesetzliche Melde- und Löschpflichten
(insb. **Digital Services Act**, ggf. NetzDG-Nachfolgeregeln) gelten neben dem
Juryverfahren und erfordern im Echtbetrieb einen benannten Zustellungsbevollmächtigten
und Melde­wege für Behörden. Das ist im Betriebskonzept der Ausbaustufe zu verankern.

---

## 8. Sicherheitsarchitektur

Stimmwerk wäre im Echtbetrieb ein **hochwertiges Angriffsziel** (politische
Stimmungsbilder, staatsnahe Wahrnehmung). Der Prototyp ist deshalb von Grund auf
konservativ gebaut: wenig Code, wenig Abhängigkeiten, restriktive Standardwerte.

### 8.1 Prinzipien

- **Datenminimierung als Verteidigung:** Was nicht gespeichert ist, kann nicht
  gestohlen werden. Keine Namen, keine E-Mail-Adressen, keine Passwörter, keine
  Klar-IP-Adressen in der Datenbank.
- **Keine fremden Laufzeit-Abhängigkeiten:** Der Prototyp nutzt ausschließlich
  PHP-Bordmittel (kein Framework, kein Composer-Paket, kein CDN, keine externen
  Fonts/Skripte). Das eliminiert Lieferketten-Risiken und macht den gesamten Code
  in einem Durchgang auditierbar.
- **Sichere Standardwerte:** Testbanner an, Mock-eID nur explizit, restriktivste
  HTTP-Header, alle Grenzwerte serverseitig.
- **Tiefenstaffelung:** Kernregeln (1 Thema/Tag, eine Stimme pro Thema, eine offene
  Meldung pro Thema, ein Juror nur einmal je Meldung) sind zusätzlich zur
  Anwendungslogik als **Datenbank-Constraints** erzwungen.

### 8.2 Maßnahmen im Code (Prototyp)

| Bereich | Maßnahme |
|---|---|
| SQL | Ausschließlich PDO-**Prepared Statements**, kein String-SQL mit Nutzerdaten |
| XSS | Konsequentes Output-Escaping (`htmlspecialchars`, ENT_QUOTES) in allen Templates; keine Inline-Skripte/-Styles |
| CSP | `default-src 'none'` + explizite Freigaben nur für eigene Skripte/Styles/Bilder; `frame-ancestors 'none'`, `base-uri 'none'`, `form-action 'self'` |
| Clickjacking | `X-Frame-Options: DENY` + CSP frame-ancestors |
| Weitere Header | `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, restriktive `Permissions-Policy`, COOP/CORP; HSTS im HTTPS-Betrieb |
| CSRF/Replay | **Einmal-Token** auf jedem POST-Formular: beim Einlösen verbraucht — jede Aktion ist genau einmal gültig |
| Sessions | HttpOnly, SameSite, Secure (bei HTTPS), ID-Rotation bei An-/Abmeldung, Inaktivitäts- (30 min) und absolutes Timeout (8 h) — wichtig für öffentliche Terminals |
| Identität | Nur HMAC-SHA-256-Pseudonym-Hashes; Server-Pepper wird beim ersten Start kryptographisch erzeugt und liegt außerhalb des Webroots (0600) |
| Zufall | Jury-Losverfahren mit CSPRNG (`random_int`, Fisher-Yates), nicht mit SQL-`RANDOM()` |
| Rate-Limits | Serverseitig je Pseudonym bzw. gehashter IP: Anmeldeversuche, Stimmen, Meldungen, Formular-POSTs |
| Eingaben | Whitelist-Validierung (Enums, Längen, UTF-8-Prüfung, Kontrollzeichen-Filter); keine Datei-Uploads |
| Fehlerbilder | Keine Stacktraces oder Pfade nach außen; generische Fehlerseiten; Sicherheitsereignisse werden ohne personenbezogene Daten protokolliert |
| Struktur | Nur `public/` liegt im Webroot; Datenbank, Geheimnisse und Logs außerhalb; `.htaccess`-Fallback verweigert Verzeichnislisten |
| Betrieb | Selbsttest (`php index.php selftest`, 97 Prüfungen) deckt die Kernregeln automatisiert ab: Tagesgrenze, Abstimmungsende, Jury-Ausschlüsse, Quorum, Fristen, Karenz, Allowlist, Testmodus-Ende, Sprachtabellen |

### 8.3 Bedrohungsmodell (Auszug)

| Bedrohung | Antwort |
|---|---|
| Bot-/Sockenpuppen-Armeen | eID: eine physische Karte = ein Konto; ohne Karte keine Stimme |
| Stimmenkauf/-zwang im großen Stil | Physische Karte + PIN nötig; skaliert schlecht; rechtlich sanktioniert |
| Manipulation der Jury-Auswahl | CSPRNG-Losung serverseitig; Ausschlusslisten; keine Selbstmeldung zur Jury möglich |
| Melde-Spam zur Zensur | Tageslimit, eine offene Meldung je Thema, Quorum + Mehrheit nötig, Gleichstand erhält Inhalt |
| SQL-Injection / XSS / CSRF | Siehe 8.2 — durchgängig parametrisiert, escaped, tokenisiert |
| Session-Diebstahl am Terminal | Kurze Timeouts, Abmelde-Knopf, keine Persistenz im Browser, HttpOnly-Cookies |
| Datenbank-Diebstahl | Enthält nur Pseudonym-Hashes (ohne Pepper wertlos), Themen und Stimmen — keine Klaridentitäten |
| Lieferkette (Pakete/CDN) | Keine externen Abhängigkeiten im Prototyp |
| DDoS / Lastspitzen | Ausbaustufe: CDN/Anycast vor statischen Assets, horizontale Skalierung, siehe 10.3 |

### 8.4 Echtbetrieb zusätzlich (Pflichtprogramm)

TLS ausschließlich (HSTS + Preload), getrennte Umgebungen, Härtung nach
BSI-IT-Grundschutz, externe Penetrationstests **vor** Start, laufendes
Schwachstellen-Management + Bug-Bounty, signierte Deployments, Backups mit
Wiederanlaufübungen, 24/7-Monitoring, DDoS-Schutz, und ein unabhängiges
Sicherheits-Audit des Jury-Losverfahrens (nachvollziehbare, aber nicht
vorhersagbare Ziehung — z. B. via veröffentlichtem Ziehungs-Commitment).

---

## 9. Datenschutz (DSGVO)

- **Datenminimierung:** gespeichert werden nur: Pseudonym-Hash, Spracheinstellung,
  Zeitstempel, eigene Themen/Stimmen/Favoriten/Meldungen/Jury-Zuteilungen. Keine
  Klaridentität, keine IP-Adressen im Klartext (nur kurzlebige Hashes für
  Rate-Limits), keine Tracker, keine Cookies außer dem Sitzungs-Cookie.
- **Rechtsgrundlage** (Echtbetrieb): Art. 6 Abs. 1 lit. a/e DSGVO je nach
  Trägerschaft; eID-Nutzung nach eIDAS/PAuswG mit Berechtigungszertifikat.
- **Betroffenenrechte:** „Meine Übersicht“ = Auskunft auf Knopfdruck; **Konto
  löschen** entfernt Stimmen und Favoriten vollständig und entkoppelt eingebrachte
  Themen vom Pseudonym (Inhalte bleiben als anonyme Beiträge erhalten — wie bei
  Foren üblich; alternativ konfigurierbar: Volllöschung).
- **Speicherbegrenzung:** Rate-Limit-Einträge verfallen automatisch;
  Sicherheitslogs ohne Personenbezug.
- Für den Echtbetrieb: Datenschutz-Folgenabschätzung (Art. 35 DSGVO) zwingend,
  benannte(r) DSB, Verzeichnis von Verarbeitungstätigkeiten.

---

## 10. Technik

### 10.1 Prototyp-Stack

- **PHP ≥ 8.2** (strict types), keine externen Pakete.
- **SQLite** (WAL-Modus) als Prototyp-Datenbank — eine Datei, einfach zu prüfen;
  die Datenzugriffsschicht ist so geschrieben, dass PostgreSQL in der Ausbaustufe
  ein Austausch der Verbindung ist.
- **Server-gerendertes HTML**, ein CSS-File, zwei kleine JS-Dateien (Theme,
  Countdown) — alle Funktionen laufen auch ohne JavaScript.
- Betrieb hinter Apache/nginx (nur `public/` als DocumentRoot) oder für Demos mit
  dem eingebauten PHP-Server.

### 10.2 Hintergrundläufe

Zustandswechsel (Meldung „wartet“ → „läuft“ um 00:00, Entscheidung nach 24 h +
Quorum, Karenzzeiten) verarbeitet ein idempotenter **Maintenance-Tick**: er läuft
per Cron (`bin/cron.php`) **und** zusätzlich lazy bei Seitenaufrufen (gedrosselt),
sodass der Prototyp auch ohne Cron korrekt bleibt.

### 10.3 Skalierungspfad

PostgreSQL + Read-Replicas → zustandslose PHP-Knoten hinter Load-Balancer →
CDN für Assets → Warteschlange für Jury-Benachrichtigungen → mandantenfähige
Gebietsdaten (amtliche Gemeindeschlüssel AGS/ARS statt Freitext-Gebieten).

---

## 11. Parameter (konfigurierbar)

| Parameter | Standard | Bedeutung |
|---|---|---|
| `show_test_banner` | **true** | Testbetrieb-Banner auf jeder Seite |
| `eid_mode` | `demo` | `demo` (ausgegebene Test-Ausweise) oder `eid` (nur Ausweis-Apps) |
| `eid_client_url` | `http://127.0.0.1:24727/eID-Client` | Aktivierungsadresse des eID-Clients (TR-03124) |
| `eid_server_url` | leer | SOAP-Endpunkt des eigenen eID-Servers (TR-03130); leer = fail-closed |
| `default_lang` | `de` | Standardsprache |
| `jury_share` | 1 % | Anteil der Nutzerschaft je Jury |
| `jury_min` | 5 | Mindest-Jurygröße |
| `quorum_share` | 0,5 % | Quorum als Anteil der Nutzerschaft |
| `quorum_min` | 3 | Mindest-Quorum |
| `report_vote_hours` | 24 | Reguläre Jury-Abstimmungsdauer |
| `jury_cooldown_days` | 3 | Karenz nach abgeschlossener Jury-Runde |
| `reports_per_day` | 3 | Meldungen je Pseudonym und Tag |
| `timezone` | Europe/Berlin | Bezugszeitzone aller Tagesgrenzen |

---

## 12. Roadmap

1. **Prototyp (dieses Repository):** vollständige Fachlogik mit Mock-eID,
   Zwei-Sprachen-UI, Jury-Verfahren, Selbsttests.
2. **Pilot:** Anbindung echter eID-Server (Berechtigungszertifikat, AusweisApp-Flow),
   externes Sicherheits-Audit + Pentest, DSFA, Barrierefreiheit nach BITV,
   PostgreSQL.
3. **Kommunal-Pilotgemeinden:** Terminals in Bürgerbüros, amtliche Gebietsschlüssel,
   Auswertungs-Exporte für Räte und Verwaltungen.
4. **Ausbau:** Delegations-/Sachverständigen-Funktionen, strukturierte
   Ergebnisberichte an Parlamente, offene Schnittstellen (Open Data) mit
   Differential-Privacy-Schutz kleiner Gebiete.

---

## 13. Offene Punkte

- Marken-/Domainrecherche „Stimmwerk“ und finale Namensentscheidung.
- Trägerschaft und Finanzierung (Verein/Stiftung empfohlen: neutralitätssichernd).
- Rechtsgutachten: eID-Berechtigungsumfang, DSA-Pflichtenkatalog, Jugendliche
  (Ausweispflicht ab 16 — Teilnahmealter ist Konfigurationsfrage).
- Verifizierbare Jury-Ziehung (öffentliches Commitment-Verfahren) — Konzept in 8.4
  skizziert, Umsetzung in der Pilotphase.

---

## Anhang: Installation und Betrieb

Der technische Prototyp ist **eine einzige Datei**. Es genügt ein Webserver
mit PHP — kein Framework, kein Paketmanager, keine externen Dienste.

1. `index.php` in das Webverzeichnis legen (Hauptverzeichnis oder Unterordner;
   der Basispfad wird erkannt).
2. PHP **8.0 oder neuer** mit `pdo_sqlite`, `mbstring` und `sodium` — alle drei
   gehören zur Standardausstattung.
3. Seite aufrufen. Beim ersten Aufruf legt die Datei selbst an: `data/`
   (SQLite-Datenbank, Server-Geheimnis, Protokolle, Zugriffssperre),
   `.htaccess` (saubere Adressen und Schutz interner Dateien) und `robots.txt`.

Meldet die Seite „Fast geschafft“, fehlen dem Verzeichnis Schreibrechte
(755/775 setzen, neu laden). Ohne `.htaccess`-Unterstützung — etwa unter nginx —
arbeitet die Anwendung über automatisch erzeugte `/index.php/…`-Adressen
weiter; eine gleichwertige `try_files`-Regel stellt die sauberen Adressen her.

Der Auslieferungszustand ist der **Testmodus** (Kapitel 5.3b): ein Knopf meldet
ohne Ausweis an, damit sich die Plattform vorführen lässt. Das Beenden über die
Oberfläche löscht alle dabei entstandenen Daten und schaltet dauerhaft auf die
strenge Ausweisprüfung um.

Wartung und Prüfung laufen über dieselbe Datei auf der Kommandozeile:

| Aufruf | Zweck |
|---|---|
| `php index.php selftest` | 97 automatisierte Prüfungen der Fachregeln |
| `php index.php cron` | Wartungslauf (sonst beiläufig bei Seitenaufrufen) |
| `php index.php seed 400` | Demo-Stimmen, anonym wie im Echtbetrieb |
| `php index.php jurysim` | Demo-Jury stimmt in laufenden Prüfungen ab |
| `php index.php issue-card 3` | autorisierte Demo-Ausweise samt Abhol-Verweis |
| `php index.php sync-keys` | Allowlist aus der konfigurierten Trust-Liste |
| `php -S 127.0.0.1:8080 index.php` | lokale Vorführung ohne Webserver |
