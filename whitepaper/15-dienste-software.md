# 15 Softwarebereich: Katalog, Platzierung, Lebenszyklus

## 15.1 Drei Objekte und die Grenzen zwischen ihnen

Der Bereich **Dienste** aus KANON 5 arbeitet mit genau drei Objekten aus KANON 3. Ihre Trennung ist die Voraussetzung dafür, dass eine Auswahl im Katalog, eine laufende Instanz und ein erreichbarer Name unabhängig voneinander versioniert, ausgetauscht und zurückgebaut werden können.

| Objekt | Was es festlegt | Wer es schreibt | Was daraus abgeleitet wird |
|---|---|---|---|
| **Katalogeintrag** | Welches Fremdprodukt in welcher Fassung einsetzbar ist, mit welchem Abbild, welchem Speicherbedarf, welchen Konnektoren und welcher Produktgrenze | Der Hersteller des Katalogs bzw. eine Pflegestelle; Import nur mit gültiger Signatur | Nichts unmittelbar; er ist Vorlage, nicht Instanz |
| **Dienst** | Eine laufende Instanz eines Katalogeintrags bei genau einem Mandanten, mit Name, Platzierung, Budget und Datensicherheitsstufe | Der Bediener über einen Vorgang | Systemdienstbeschreibung auf dem Knoten, Speicherbereich, Konnektorbindungen, Fremdkonten |
| **Veröffentlichung** | Unter welchem Namen, in welcher Sicht und für welchen Zugriffskreis der Dienst erreichbar ist | Der Bediener über einen Vorgang | DNS-Eintrag, Proxyroute, Zertifikat, Netzfreigabe ([Kapitel 12](12-dns-netzwerk.md)) |

Ein Dienst ohne Veröffentlichung läuft und ist nicht erreichbar. Eine Veröffentlichung ohne Dienst existiert nicht, weil sie einen Dienstverweis als Pflichtfeld trägt. Dieselbe Trennung erlaubt es, einen Dienst zu verschieben, ohne den Namen zu ändern, und den Namen zu ändern, ohne den Dienst anzufassen.

Die Zuständigkeit dieses Kapitels endet an vier Stellen. Die Konnektorseite — Vertrag, Manifest, Feldeigentum, Einstufung, Sandkasten — steht in [Kapitel 09](09-konnektoren.md) und wird hier nur benutzt. Die Ableitung von Erreichbarkeit aus einer Veröffentlichung steht in [Kapitel 12](12-dns-netzwerk.md), die Zertifikatsausstellung in [Kapitel 11](11-pki.md), die Speichertechnik und die Sicherungsablage in [Kapitel 17](17-speicher-backup.md).

## 15.2 Der Katalogeintrag

### Schema

Der Katalogeintrag ist Daten und kein Quelltext. Er wird kanonisch nach RFC 8785 serialisiert, mit Ed25519 nach RFC 8032 signiert und am Rand gegen das JSON-Schema seiner Schemaversion validiert; ein unbekanntes Feld führt zur Ablehnung des Imports statt zu einer stillen Übernahme.

```
katalogeintrag:
  kennung:        urn:atrium:katalogeintrag:<ulid>
  schema_version: "<MAJOR.MINOR>"
  produkt_name:   "<Produktname>"
  produkt_reihe:  "<reihe>"                  # ticketsystem, datenbank, dateidienst, ...
  fassung:
    bezeichner:   "<Fassungsbezeichner des Herstellers>"   # unveraendert uebernommen
    kanal:        stabil | vorab
    vorgaenger:   <kennung|null>
  herkunft:
    quelltext_ursprung: "<Fundstelle der Quelle>"
    lizenz_spdx:        "<SPDX-Kennung>"
    bauverfahren:       reproduzierbar | nicht_reproduzierbar
    bauprotokoll_hash:  "<SHA-2>"
  signatur:
    verfahren:                  ed25519
    schluessel_kennung:         "<Kennung des Katalogschluessels>"
    wert:                       "<Signatur>"
    transparenzprotokoll_index: <n>
    zeitstempel:                "<Zeitstempeltoken nach RFC 3161>"
  stueckliste:
    spdx:       "<Verweis>"
    cyclonedx:  "<Verweis>"
  vertrauensstufe: A | B | C
  abbilder:
    - architektur: amd64 | arm64
      verweis:     "<OCI-Verweis>"
      digest:      "<SHA-2>"
  laufzeit:
    benutzerkennung:      eigen                 # je Dienstinstanz eigene Kennung
    wurzelrechte:         nein | benoetigt: "<Begruendung>"
    schreibbare_pfade:    [ "<pfad>", ... ]     # alles Uebrige nur lesbar
    selbstaktualisierung: aus                   # Pflichtwert, siehe unten
  standardbudget:
    arbeitsspeicher_weich_mb: <n>
    arbeitsspeicher_hart_mb:  <n>
    rechenanteil_millikerne:  <n>
    prozesse_max:             <n>
  speicherbereiche:
    - name:                 daten
      groesse_vorgabe_gb:   <n>
      datenklassenvorgabe:  lokal | gespiegelt | synchron_gespiegelt
      pfad_im_dienst:       "<pfad>"
      sicherungshaken:
        vorbereiten:        { aufruf: "<aufruf>", frist_s: 10 }
        freigeben:          { aufruf: "<aufruf>", frist_s: 5 }
        ohne_haken:         absturzkonsistent
  abhaengigkeiten:
    - katalogeintrag:       "<kennung>"
      art:                  benoetigt | empfiehlt
      bereitschaft_abwarten: ja | nein
  gesundheitsproben:
    start:        { pfad: "<pfad>", intervall_s: 5,  versuche_max: 60 }
    bereitschaft: { pfad: "<pfad>", intervall_s: 5,  erfolge_noetig: 2 }
    leben:        { pfad: "<pfad>", intervall_s: 10, fehlschlaege_max: 3 }
  standard_veroeffentlichung:
    protokoll:            https
    sichtbarkeit_vorgabe: nur_intern
    anmeldung:            oidc | kopfzeile_am_eingang | keine
  benoetigte_konnektoren:
    - manifest:        "<kennung>"
      vertrag_version: "<MAJOR.MINOR>"
      pflicht:         ja | nein
  produktgrenze:                        # INV-30; unvollstaendig => Freigabe abgelehnt
    atrium_besitzt: [ ... ]
    fremd_besitzt:  [ ... ]
    nur_erstanlage: [ ... ]
  migration:
    von_fassung:                   "<Bezeichner>"
    laeuft_beim_start:             ja | nein
    rueckrollbar:                  nein
    dauer_annahme_min:             <n>
    uebersprungene_hauptfassungen: nein
  abkuendigung:
    ab:        <datum|null>
    ende:      <datum|null>
    nachfolger: <kennung|null>
```

Das Feld `selbstaktualisierung` ist der Punkt, an dem der Katalog gegen INV-02 verteidigt wird. Ein Fremdprodukt, das sich selbst aktualisiert, ändert seinen Istzustand ohne Vorgang, ohne Wirkungsvorschau und ohne Auditereignis und macht damit die Fassungsangabe im Sollzustand zu einer Behauptung. Lässt sich die Selbstaktualisierung im Produkt nicht abschalten, wird der Eintrag nicht freigegeben; lässt sie sich nur durch fehlenden Netzzugang verhindern, wird der Eintrag mit dem Vermerk aufgenommen, dass die Ausgangs-Positivliste des Dienstes den Aktualisierungsweg sperrt, und die Konsole zeigt diese Abhängigkeit am Dienst. Die verworfene Alternative, die Selbstaktualisierung zu dulden und die Fassung nur zu beobachten, erzeugt bei jedem Volllauf eine Abweichungsmeldung, die niemand beheben kann.

### Herkunft, Signatur und Lieferkette

| Nachweis | Verfahren | Prüfzeitpunkt | Verhalten bei Fehlschlag |
|---|---|---|---|
| Echtheit des Eintrags | Ed25519 (RFC 8032) über die kanonische Form (RFC 8785) | Import in den Katalog und jeder Start von atrium-node | Eintrag nicht ladbar; Bestand unberührt |
| Nichtzurückdatierbarkeit | Eintrag im anhängbaren, hashverketteten Transparenzprotokoll mit Zeitstempel nach RFC 3161 | Import | Eintrag nicht ladbar |
| Abbildidentität | Digest nach FIPS 180-4 im Eintrag, gegen das geladene Abbild geprüft | vor jedem Start | Start unterbleibt; Dienst bleibt auf der bisherigen Fassung |
| Inhaltsnachweis | Stückliste sowohl als SPDX (ISO/IEC 5962) als auch als CycloneDX | Import | Eintrag nicht ladbar |
| Nachbaubarkeit | `bauverfahren: reproduzierbar` plus Bauprotokollhashwert | Begutachtung vor der Freigabe | Einstufung höchstens Vertrauensstufe B |

Ein nicht signierter Katalogeintrag ist nicht ladbar; eine Stufe unterhalb von C existiert nicht. Die Prüfung bei jedem Start und nicht nur beim Import ist notwendig, weil ein Import ein einmaliges Ereignis ist und die abgelegte Datei danach Jahre auf einem Knoten liegt.

### Vertrauensstufen

| Stufe | Voraussetzung | Zusage | Anzeige am Katalogeintrag und an jedem Dienst |
|---|---|---|---|
| **A geprüft** | Reproduzierbarer Bau; Stückliste vollständig; Migrationsschritte gegen eine Laborinstanz jeder freigegebenen Fassung durchlaufen; benannte Pflegestelle mit Sicherheitskontakt | Sicherheitsmeldung beantwortet in ≤ 72 h (Zielwert, angelehnt an K-23) | "geprüft, gepflegt von \<Stelle\>" |
| **B bestätigt** | Signatur und Stückliste vollständig; Migrationsschritte deklariert, aber nicht gegen jede Fassung durchlaufen; benannte Pflegestelle | Sicherheitsmeldung beantwortet in ≤ 14 Tagen (Zielwert) | "bestätigt, ohne Laborinstanz" |
| **C beigetragen** | Signatur, Schemavalidierung, Produktgrenzdeklaration vollständig; keine fortdauernde Pflegezusage | keine | "ohne zugesicherte Pflege" |

Die Buchstaben sind dieselben wie bei den Konnektorstufen in [Kapitel 09](09-konnektoren.md), die Kriterien sind es nicht: dort geht es um Vertragstests gegen eine Fremdschnittstelle, hier um Bau, Stückliste und Migrationspfad. **Die wirksame Stufe eines Dienstes ist das Minimum aus der Stufe seines Katalogeintrags und den Stufen aller als `pflicht: ja` deklarierten Konnektoren.** Ohne diese Regel zeigt die Konsole an einem Dienst "geprüft", während seine Versorgung über einen ungepflegten Konnektor läuft.

### Kanäle

| Kanal | Zweck | Wer bekommt ihn | Regeln |
|---|---|---|---|
| **stabil** | Regelbetrieb | alle Mandanten, Vorgabe aus der Richtlinie `kanal_vorgabe` | Eine Fassung erreicht `stabil` frühestens, nachdem sie im Kanal `vorab` einen Beobachtungszeitraum ohne Rückfall überstanden hat (Zielwert 14 Tage) |
| **vorab** | Erprobung der nächsten Fassung durch den Kunden selbst | ausdrücklich je Dienst geschaltet, nie je Mandant als Vorgabe | Ein Dienst im Kanal `vorab` zeigt das dauerhaft am Dienst (INV-18); der Wechsel von `vorab` nach `stabil` ist kein Rückschritt, sondern wartet auf die nächste stabile Fassung, die die Vorabfassung erreicht oder überholt |

Ein Wechsel eines laufenden Dienstes von `stabil` nach `vorab` ist ein Vorgang mit Wirkungsvorschau, weil er eine Datenmigration auslösen kann, die nicht rückrollbar ist. Der Rückweg von `vorab` nach `stabil` bei bereits gelaufener Migration ist keine Aktualisierung, sondern eine Wiederherstellung; das steht in 15.7 und wird in der Konsole an dieser Stelle im Klartext genannt.

### Aufnahme und Freigabe

| Aufnahmekriterium | Prüfung | Ablehnungsgrund |
|---|---|---|
| Signatur, Transparenzprotokolleintrag, beide Stücklistenformate | automatisch | nicht ladbar |
| Produktgrenzdeklaration vollständig über alle Objekte, die das Produkt anbietet | Abgleich gegen die Objektliste aus `describe` der Pflichtkonnektoren | INV-30 |
| Betrieb ohne Wurzelrechte, sonst begründet | Schemaprüfung und Begutachtung | Sicherheitsvorgabe |
| Selbstaktualisierung abschaltbar | Begutachtung an einer laufenden Instanz | INV-02 |
| Alle Schreibpfade benannt, alles Übrige nur lesbar | Laufprobe: Start mit nur lesbarem Wurzeldateisystem | Betriebsvorgabe |
| Gesundheitsproben lesend, ohne Geheimnis im Aufruf, ohne Preisgabe von Fassung, Pfad oder Fehlertext | Vertragstest gegen den Probenpfad | Sicherheitsvorgabe, INV-08 |
| Standardbudget angegeben und mit einer Laufprobe belegt | Laufprobe über 24 h Grundlast | Platzierung sonst nicht rechenbar |
| Migrationsschritte deklariert, einschließlich `rueckrollbar: nein` und Dauerannahme | Schemaprüfung | 15.7 nicht durchführbar |
| Datenklassenvorgabe je Speicherbereich | Schemaprüfung | Speicherentscheidung sonst nicht vorbelegbar (INV-15) |
| Rezept für eine Testinstanz beigelegt | Bau | Laufproben nicht ausführbar |

Der Freigabeprozess ist ein Vorgang wie jeder andere und läuft über den Freigabeweg aus [Kapitel 19](19-mandanten-rechte-audit.md), wenn ein Kunde einen eigenen Eintrag einbringt. Ein kundeneigener Eintrag wird mit einem kundeneigenen Schlüssel signiert und dauerhaft als "eigene Pflege, ohne Zusicherung" gekennzeichnet; das ist dieselbe bewusst in Kauf genommene Schwäche wie bei kundeneigenen Konnektormanifesten, weil damit ein zweiter Vertrauensanker neben dem Katalogschlüssel entsteht.

### Abkündigung und Rückzug

| Zustand | Auslöser | Neue Dienste anlegbar | Bestehende Dienste | Aktualisierung |
|---|---|---|---|---|
| `abgekuendigt` | geplantes Ende der Pflege, Vorlauf Zielwert 180 Tage | nein | laufen unverändert | bis zum Enddatum |
| `zurueckgezogen` | Pflege endet ohne Übernahme | nein | laufen unverändert, Kennzeichnung am Dienst | nein |
| `gesperrt` | Sicherheitsvorfall, Signatur widerrufen | nein | laufen weiter, Kennzeichnung "gesperrt" mit Grund | nein |

Kein Rückzugsschritt hält einen laufenden Dienst an und keiner löscht Daten. Der Grund ist derselbe wie bei Konnektoren: ein Fehler in der Lieferkette ist kein Anlass, den Betrieb des Kunden zu beenden. Die Konsole zeigt an jedem betroffenen Dienst das Enddatum, den Nachfolgereintrag, falls vorhanden, und die verbleibende Zeit; ein Dienst auf einem gesperrten Eintrag erscheint zusätzlich im Überblick als Störung mit Verweis auf das Dienstobjekt.

## 15.3 "Dienst hinzufügen" als Objektvorgang

### Das Formular und der Nachweis der Drei-Entscheidungs-Regel

| Feld | Art | Quelle der Vorbelegung (INV-15) | Zählt als Entscheidung |
|---|---|---|---|
| Produkt (Katalogeintrag) | Pflichtauswahl | keine; die Absicht ist nicht ableitbar | **ja (1)** |
| Name | Pflichtfeld | keine brauchbare; siehe Begründung unten | **ja (2)** |
| Veröffentlichung: nur intern / interne Domäne / externe Domäne | Pflichtauswahl, drei Werte | Richtlinie `veroeffentlichung_vorgabe` belegt vor, die Auswahl bleibt sichtbar und wird gezählt | **ja (3)** |
| Platzierung | vorbelegt "automatisch" | Bewertungsfunktion aus 15.4, dazu Richtlinie `platzierung_vorgabe` | nein |
| Speicherklasse | vorbelegt | `datensicherheitsstufe_vorgabe` des Mandanten, sonst `datenklassenvorgabe` des Katalogeintrags | nein |
| Ressourcenbudget | vorbelegt | `standardbudget` des Katalogeintrags | nein |
| Hostname | abgeleitet | technischer Name des Dienstes plus Standarddomäne des Mandanten | nein |
| Zugriffskreis | vorbelegt | Richtlinie `zugriffskreis_vorgabe`, Vorgabe: Netzzone des Mandanten | nein |
| Kanal | vorbelegt `stabil` | Richtlinie `kanal_vorgabe` | nein |
| Abhängige Dienste | abgeleitet | `abhaengigkeiten` des Katalogeintrags | nein |
| Anmeldeverfahren | abgeleitet | `standard_veroeffentlichung.anmeldung` | nein |
| Zertifikat | abgeleitet | Veröffentlichung und Mandanten-Zwischen-CA | nein |
| Notiz | Freitext, optional | entfällt | nein |

Der Name zählt als Entscheidung, obwohl eine Vorbelegung technisch möglich wäre. Der Anzeigename ist je Mandant und Entitätstyp eindeutig, er trägt die fachliche Bedeutung ("Ticketsystem Vertrieb") und nicht den Produktnamen, und eine Vorbelegung mit dem Produktnamen ist bei der zweiten Instanz kollisionsbehaftet und bei der ersten irreführend. Damit liegt die Aufgabe bei genau drei Entscheidungen und erfüllt INV-14 und den Entwurfsstand aus K-03.

Das Feld Platzierung ist der Punkt, an dem dieses Kapitel eine Spannung im Kanon auflöst. KANON 5 schließt eine Dienstplatzierung von Hand im Bereich Knoten & Speicher aus und lässt dort nur Vorgaben und Ausschlüsse zu. Die Auswahl eines Knotens im Bereitstellungsformular ist deshalb keine Umgehung der Bewertungsfunktion, sondern eine **harte Nebenbedingung, die in sie eingeht**: Scheitert der genannte Knoten an einer anderen harten Nebenbedingung, wird nicht still auf einen anderen Knoten ausgewichen, sondern die Platzierung als unlösbar mit benannter Ursache gemeldet. Die verworfene Alternative — Auswahl mit stillem Ausweichen — erzeugt den Fall, dass ein Bediener einen Knoten wählt und der Dienst anderswo läuft, ohne dass er es merkt.

Fehlt für den gewählten Katalogeintrag eine der Richtlinien, aus denen vorbelegt wird, erhält das Formular **kein zusätzliches Pflichtfeld**. Stattdessen ist die Handlung an diesem Katalogeintrag gesperrt, und die Konsole benennt die fehlende Richtlinie und den Weg, sie zu setzen. Dies ist dieselbe Regel wie beim Lizenzprofil in [Kapitel 14](14-mail.md) und der einzige Weg, INV-14 und INV-15 gleichzeitig zu halten.

### Schrittfolge

```
Bediener: Dienste -> Katalog -> Produkt waehlen -> "Hinzufuegen"
          Name eingeben, Veroeffentlichung waehlen -> "Wirkung anzeigen"

  1  POST /v1/dienste:plan            { katalogeintrag, anzeige_name, veroeffentlichung_art }
  2  Kern: Vorbelegungen aufloesen    (Richtlinien, Katalogeintrag, Objektgraph)
  3  Kern: Abhaengigkeiten aufloesen  (fehlende Pflichtdienste als Teilwirkung aufnehmen)
  4  Kern: Platzierung berechnen      (15.4; Ergebnis mit Begruendung, keine Wirkung)
  5  Kern: Speicherbereich planen     (Groesse, Datensicherheitsstufe, Replikatstandorte)
  6  Kern: Veroeffentlichung planen   (Hostname, Sicht, Zugriffskreis, Zertifikatsbedarf)
  7  Kern: je Pflichtkonnektor plan() (nebenwirkungsfrei, INV-08)
  8  -> Wirkungsvorschau an die Konsole; Vorgang im Zustand "zur Freigabe vorgelegt"

Bediener: bestaetigt (oder Freigabe durch eine zweite Person, je Richtlinie)

  9  POST /v1/dienste                 { ... , idempotenzschluessel }
 10  Sollzustand schreiben            (Quorum; INV-04)
 11  atrium-node: Abbild pruefen und laden, Speicherbereich anlegen, Dienst starten
 12  Startprobe -> Bereitschaftsprobe
 13  Zertifikat anfordern, DNS-Eintrag setzen, Route am Eingang setzen
 14  Vorgang "abgeschlossen" erst, wenn jeder Teilschritt bestaetigt ist (INV-12)
```

Schritt 13 läuft erst nach bestandener Bereitschaftsprobe. Die verworfene Alternative, Route und DNS-Eintrag sofort zu setzen, erzeugt ein Zeitfenster, in dem der Name auflöst und die Verbindung scheitert; der Bediener sieht dann einen Fehler, den er nicht von einem echten Ausfall unterscheiden kann.

### Wirkungsvorschau

```
Vorgang: Dienst "Ticketsystem Vertrieb" hinzufuegen
Mandant: Musterfirma            Korrelationskennung: 01J...

Neue Objekte
  Dienst              Ticketsystem Vertrieb (Znuny, Kanal stabil)
  Dienst              Datenbank fuer Ticketsystem Vertrieb   [Abhaengigkeit, Quelle: Katalogeintrag]
  Speicherbereich     20 GB, Synchron gespiegelt             [Quelle: Richtlinie des Mandanten]
  Speicherbereich     40 GB, Synchron gespiegelt             [Quelle: Katalogeintrag Datenbankdienst]
  Veroeffentlichung   ticketsystem-vertrieb.musterfirma.<basisdomaene>, nur intern
  Zertifikat          aus der eigenen Ausgabestelle, Laufzeit 90 Tage

Platzierung
  Dienst      -> Verwaltungsknoten "knoten-02"  (Fehlerzone B)
  Datenbank   -> Verwaltungsknoten "knoten-03"  (Fehlerzone C)
  Begruendung: freie Kapazitaet und getrennte Fehlerzonen; knoten-01 traegt bereits
               eine Instanz derselben Produktreihe.

Abgeleitete Artefakte
  1 Namenseintrag (interne Sicht), 1 Route am Eingang, 2 Netzfreigaben

Nicht enthalten
  Keine Zuweisung. Nach Abschluss ist der Dienst fuer 0 Personen nutzbar.

Redundanz nach diesem Vorgang: 1 Knotenausfall wird weiterhin vertragen.
```

Der Abschnitt "Nicht enthalten" ist nicht schmückend. Die häufigste Fehlvorstellung nach einer Bereitstellung ist, dass Personen den Dienst nun benutzen können; tatsächlich ist die Zuweisung ein eigener Vorgang, und ohne sie stellt der Protokollkopf für diesen Dienst kein Token aus.

### Fehlerfälle

| Fehler | Konsolentext (Muster) | Ausführbare Folgehandlung (INV-17) |
|---|---|---|
| Keine Platzierung möglich | "Kein Knoten erfüllt alle Bedingungen. 2 Knoten scheiden wegen Speicherklasse aus, 1 wegen Kapazität." | "Speicherklasse auf Gespiegelt ändern" oder "Knoten aufnehmen" |
| Abbild nicht ladbar | "Das Abbild konnte nicht geprüft werden. Die Signatur passt nicht zum Katalogeintrag." | "Katalog erneut laden", "Diesen Eintrag melden" |
| Pflichtkonnektor nicht eingerichtet | "Für dieses Produkt fehlt die Verbindung zu \<Fremdsystem\>." | "Verbindung einrichten" (führt in den Einrichtungsvorgang) |
| Startprobe erschöpft | "Der Dienst ist nach 5 Minuten nicht gestartet." mit den letzten 20 Zeilen der Dienstausgabe | "Erneut versuchen", "Auf vorherige Fassung setzen", "Betriebsprotokoll ansehen" |
| Name bereits vergeben | "Ein Dienst mit diesem Namen besteht bereits in diesem Mandanten." | Feld bleibt gefüllt, Cursor im Namensfeld |
| Richtlinie fehlt | "Für dieses Produkt ist keine Speichervorgabe gesetzt. Ohne sie ist die Bereitstellung gesperrt." | "Vorgabe setzen" (führt in die Richtlinie) |

Keine dieser Meldungen enthält einen Befehl, einen Dateipfad oder einen unübersetzten Fremdtext. Die Ausgabe des Fremdprodukts bei erschöpfter Startprobe ist die Ausnahme: sie wird als ausdrücklich gekennzeichneter Fremdtext in einem eigenen Bereich gezeigt, weil sie die einzige verwertbare Information über den Startfehler enthält.

## 15.4 Platzierung

### Harte Nebenbedingungen

Eine harte Nebenbedingung schließt einen Knoten aus. Sie wird vor jeder Bewertung geprüft, und ihr Ergebnis ist der Text, den der Bediener bei Unlösbarkeit sieht.

| Nebenbedingung | Prüfung | Ausschlusstext |
|---|---|---|
| **Knotenzustand** | produktiv, Lease gültig, nicht im Räumen, Abbildversion kompatibel | "Knoten wird gerade geräumt" |
| **Kapazität** | Summe der harten Speichergarantien plus Budget ≤ zuteilbarer Speicher; Rechenanteil analog | "Zu wenig freier Arbeitsspeicher" |
| **Datenlokalität (hart)** | bei `synchron_gespiegelt`: der Knoten trägt ein Replikat oder kann eines aufnehmen; bei `lokal`: der Knoten trägt den Speicherbereich | "Der Speicherbereich liegt nicht auf diesem Knoten" |
| **Anti-Affinität** | keine zweite Instanz derselben Anti-Affinitätsgruppe in derselben Fehlerzone | "In dieser Fehlerzone läuft bereits eine Instanz" |
| **Mandantenstufe** | ab M3 nur Knoten der exklusiv zugeordneten Knotenklasse; ab M1 muss die Netzzone des Mandanten auf dem Knoten bestehen | "Dieser Knoten gehört einem anderen Mandanten" |
| **Geräteanforderung** | Architektur des Abbilds, geforderte Hardwaremerkmale, Sicherheitsbaustein | "Die geforderte Hardware ist auf diesem Knoten nicht vorhanden" |
| **Standortbindung** | Fehlerzonenattribut Standort gegen die Richtlinie `standortbindung` | "Der Standort ist für diesen Mandanten nicht zugelassen" |
| **Bedienervorgabe** | ein im Formular genannter Knoten oder ein Ausschluss | "Ausgeschlossen durch Vorgabe" |

### Bewertungsfunktion

Für jeden zulässigen Knoten `k` und den zu platzierenden Dienst `d` gilt

```
S(d,k) = SUMME ueber i von  w_i * t_i(d,k),      t_i in [0,1],   SUMME w_i = 100
```

| i | Term `t_i` | Gewicht `w_i` (Zielwert) | Normierung |
|---|---|---|---|
| 1 | Datenlokalität | **40** | Anteil der bereits auf `k` liegenden Replikate des Speicherbereichs; 1,0 wenn vollständig lokal |
| 2 | Fehlerzonenverteilung | **25** | Abstand zur nächsten Anti-Affinitätsgrenze, normiert auf die Zahl der Fehlerzonen |
| 3 | Freie Kapazität | **15** | Minimum aus freiem Speicher- und freiem Rechenanteil nach der Platzierung, normiert auf die Knotenkapazität |
| 4 | Abbild bereits vorhanden | **10** | 1,0 wenn das Abbild mit passendem Digest lokal liegt, sonst 0 |
| 5 | Knotenklasseneignung | **5** | Passung weicher Hardwaremerkmale |
| 6 | Ortsstabilität | **3** | 1,0 für den bisherigen Knoten bei einer Neuberechnung, sonst 0 |
| 7 | Ausgleich der Instanzzahl | **2** | 1 − (Instanzen auf `k` / maximale Instanzen auf einem Knoten) |

Das Gewicht der Datenlokalität ist nicht gesetzt, sondern gerechnet. Nach 15.6 kostet die Verlagerung eines Speicherbereichs von 200 GB unter den dort genannten Annahmen rund 33 Minuten Hintergrundübertragung und bis zu 2 Minuten Unterbrechung; jeder andere Term beeinflusst Größen im Sekundenbereich — ein fehlendes Abbild kostet nach K-01 bei 300 MB und 100 Mbit/s 24 Sekunden. Das Verhältnis 2000 s zu 24 s beträgt rund 83 : 1. Ein Gewichtsverhältnis von 40 : 10 ist deutlich flacher als dieses Kostenverhältnis und damit bewusst konservativ: es lässt zu, dass eine Anti-Affinitätsverbesserung eine Datenverlagerung rechtfertigt, statt Daten unter allen Umständen festzunageln. Das ist ein Modell, keine Messung.

Der Gleichstandsbruch ist deterministisch: Bei gleichem `S` gewinnt der Knoten mit der lexikographisch kleinsten Kennung. Da Kennungen ULIDs mit Zeitanteil sind, bedeutet das faktisch "der älteste Knoten", was erklärbar und stabil ist. Die Begründung, die am Dienst angezeigt wird, nennt die beiden höchstgewichteten Terme, in denen der gewählte Knoten den Zweitplatzierten übertrifft, und die Gleichstandsregel, falls sie gegriffen hat.

### Suchverfahren

```
platziere(dienst d, knotenmenge K) -> Platzierung | Unloesbar:

  Z := {}                                  # zulaessige Knoten
  U := leere Abbildung Knoten -> erste verletzte Bedingung
  fuer jedes k in K:
      b := erste_verletzte_bedingung(d, k)     # feste Reihenfolge der Pruefungen
      wenn b = keine: Z := Z + {k}  sonst: U[k] := b

  wenn Z leer:
      gib Unloesbar( verdichte(U) )            # je Bedingung die Zahl der Knoten

  bewerte S(d,k) fuer jedes k in Z            # 7 Terme, ganzzahlige Festkommaarithmetik
  M := { k in Z : S(d,k) = max }
  k* := k in M mit kleinster Kennung
  gib Platzierung(k*, begruendung(d, k*, Z))
```

Das Verfahren ist gierig und nicht optimierend. Es platziert einen Dienst gegen den Bestand, nicht alle Dienste gegen alle Knoten. Die verworfene Alternative — ein optimierender Planer mit Verdrängung — findet bessere Gesamtbelegungen und verschiebt dabei Dienste, die niemand angefasst hat; das ist mit einer erklärbaren Begründung je Platzierung und mit INV-11 nicht vereinbar, weil eine Verdrängung eine Verlagerung fremder Daten ohne Vorgang wäre.

### Komplexität im Zielrahmen

| Größe | Wert | Herleitung |
|---|---|---|
| Knoten (Skalengrenze, gesetzt) | 32 | KANON 4, Entscheidung 2a |
| Dienstinstanzen (Skalengrenze, gesetzt) | 500 | KANON 4, Entscheidung 2a |
| Bewertungspaare bei vollständiger Neuberechnung | 32 × 500 = **16.000** | K-21 |
| Termauswertungen je Paar | 8 harte Prüfungen + 7 Gewichtsterme = **15** | 15.4 |
| Termauswertungen gesamt | 16.000 × 15 = **240.000** | Multiplikation |
| Zeitbudget einzelne Entscheidung | p95 ≤ 50 ms (K-21) bei 32 × 15 = 480 Auswertungen | 50 ms / 480 ≈ **104 µs je Auswertung** |
| Zeitbudget vollständige Neuberechnung | ≤ 200 ms (K-21) bei 240.000 Auswertungen | 200 ms / 240.000 ≈ **0,83 µs je Auswertung** |

Die Deutung der beiden letzten Zeilen fällt unterschiedlich aus, und das ist die ehrliche Aussage dieses Abschnitts. Für die Einzelentscheidung ist das Budget um Größenordnungen zu großzügig; die tatsächliche Zeit wird dort vom Einlesen des Istzustands bestimmt, nicht von der Arithmetik. Für die vollständige Neuberechnung ist 0,83 µs je Termauswertung nur erreichbar, wenn Kapazitäts-, Fehlerzonen- und Replikatvektoren als vorverdichtete Ganzzahlfelder im Hauptspeicher liegen und keine Auswertung eine Abfrage auslöst. Löst auch nur jede zehnte Auswertung eine Abfrage aus, ist der Zielwert um mindestens eine Größenordnung verfehlt. Daraus folgt eine Entwurfsauflage und keine Hoffnung: die Neuberechnung arbeitet ausschließlich auf einem Abzug, der vor dem Lauf einmal erstellt wird.

Die vollständige Neuberechnung ist ohnehin kein Regelvorgang. Sie läuft bei der Wirkungsvorschau eines Räumens und bei der Aufnahme eines Knotens, also nach Zielwert wenige Male je Monat. Ein laufendes Ausbalancieren des Bestandes findet **nicht** statt: Die verworfene Alternative, im Hintergrund zu optimieren, verschiebt Daten ohne Bedieneranlass und widerspricht der Ortsstabilität aus Term 6.

### Unlösbarkeit

Eine unlösbare Platzierung endet nie still und nie in einem Wiederholungszyklus. Der Vorgang endet im Zustand "fehlgeschlagen" mit benannter Ursache (INV-12), der Dienst bleibt im Zustand "ausgewählt" und nicht in "wird bereitgestellt", und die Konsole zeigt die Verdichtung aus `U`:

```
Keine Platzierung moeglich fuer "Ticketsystem Vertrieb".

  18 Knoten  Speicherklasse Synchron gespiegelt: kein Replikat moeglich
  11 Knoten  zu wenig freier Arbeitsspeicher (benoetigt 2,0 GB)
   2 Knoten  in dieser Fehlerzone laeuft bereits eine Instanz
   1 Knoten  wird gerade geraeumt

Kleinste Aenderung, die eine Platzierung erlaubt:
  - Speicherklasse auf "Gespiegelt" senken  -> 11 Knoten werden zulaessig
  - einen Knoten in Fehlerzone B aufnehmen  -> 1 Knoten wird zulaessig
```

Die Angabe der kleinsten Änderung wird berechnet, indem der Lauf für jede harte Nebenbedingung einmal ohne diese Bedingung wiederholt wird. Bei 8 Bedingungen sind das 8 zusätzliche Läufe über höchstens 32 Knoten, also 8 × 480 = 3.840 Termauswertungen — im Zeitbudget einer Wirkungsvorschau (K-18: p95 ≤ 2 s) vernachlässigbar.

## 15.5 Laufzeit

### Ressourcengrenzen

| Größe | Erzwingung | Verhalten bei Überschreitung |
|---|---|---|
| Arbeitsspeicher, weiche Grenze | Rückgewinnungsdruck | Dienst läuft weiter; Ereignis ab 15 min über der Grenze |
| Arbeitsspeicher, harte Grenze | harte Grenze des Betriebssystems | Abbruch des Prozesses, Neustart mit Zähler; Konsolentext: "Der Dienst hat mehr Arbeitsspeicher angefordert, als ihm zugeteilt ist." |
| Rechenanteil | Gewicht plus Obergrenze | Drosselung; Ereignis bei > 90 % der Obergrenze über 5 min |
| Schreib- und Leselast | Gewicht, keine harte Grenze | Ereignis bei anhaltender Verdrängung anderer Dienste |
| Prozesse, Dateideskriptoren | harte Grenze | Ablehnung innerhalb des Dienstes |

Die Überbuchungsregel lautet: Die Summe der **harten** Speichergrenzen aller Dienste eines Knotens überschreitet 85 % des zuteilbaren Speichers nicht; weiche Grenzen dürfen in Summe darüber liegen. Rechnung mit der Annahme eines Knotens mit 64 GB: K-19 reserviert höchstens 4 GB für die Grundinstallation, es verbleiben 60 GB; 85 % davon sind 51 GB. Bei einem Standardbudget von 2 GB harter Grenze je Dienstinstanz ergeben sich 51 / 2 ≈ 25 Instanzen je Knoten und damit 32 × 25 = 800 Instanzen im Verbund. Deutung: Die Skalengrenze von 500 Instanzen aus K-21 ist unter dieser Annahme nicht durch Speicher begrenzt, sondern gesetzt; der Speicher wird erst bei einem mittleren Budget oberhalb von 3,2 GB je Instanz zur bindenden Größe.

### Gesundheitsproben

| Probe | Frage | Zielwerte | Wirkung |
|---|---|---|---|
| **Startprobe** | Ist der Start abgeschlossen oder gescheitert? | Intervall 5 s, höchstens 60 Versuche = 300 s | Nach Erschöpfung: Zustand "Start gescheitert" mit der letzten Dienstausgabe |
| **Bereitschaftsprobe** | Darf Verkehr fließen? | Intervall 5 s, 2 aufeinanderfolgende Erfolge | Erst danach werden Route und Namenseintrag gesetzt |
| **Lebensprobe** | Hängt der Dienst? | Intervall 10 s, 3 aufeinanderfolgende Fehlschläge = 30 s | Neustart mit Zähler |

Anforderungen an jede Probe: lesend, ohne Nebenwirkung, ohne Anmeldung, ohne Geheimnis im Aufruf, auf einem eigenen Pfad, und mit einer Antwort, die weder Fassung noch Pfade noch Fehlertexte preisgibt. Eine Probe, die eine Datenbankabfrage mit Schreibwirkung auslöst, ist ein Weg, einen Dienst durch Beobachtung zu beschädigen; eine Probe, die die Fassung zurückgibt, ist eine Informationspreisgabe an jeden, der den Pfad erreicht.

### Abhängigkeiten und Startreihenfolge

Abhängigkeiten sind Kanten im Katalogeintrag (`abhaengigkeiten`), gerichtet und azyklisch erzwungen; ein Zyklus wird beim Import abgelehnt. Die Reihenfolge wird **auf einem Knoten** durchgesetzt und **über Knoten hinweg nicht**. Der Grund ist INV-25: Eine knotenübergreifende Startordnung braucht eine Instanz, die sie kennt, und genau diese Instanz ist nach einem Totalausfall nicht verfügbar.

Stattdessen wartet ein abhängiger Dienst auf Bereitschaft und läuft dabei in die Neustartfolge aus dem nächsten Abschnitt. Rechnung mit der Annahme, dass ein Datenbankdienst 45 s bis zur Bereitschaft braucht: Der abhängige Dienst versucht bei 1, 3, 7, 15, 31 und 63 Sekunden nach Start; der sechste Versuch bei 63 s trifft die bereite Datenbank. Gegenüber einer orchestrierten Reihenfolge, die bei 45 s starten würde, kostet das 18 s. Deutung: Der Preis der Entkopplung liegt unter einer halben Minute und rechtfertigt den Verzicht auf eine Startordnung, die im entscheidenden Fall nicht verfügbar wäre.

### Neustartverhalten

| Versuch | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 |
|---|---|---|---|---|---|---|---|---|---|---|
| Wartezeit vor dem Versuch (s) | 1 | 2 | 4 | 8 | 16 | 32 | 64 | 128 | 256 | 300 |

Die Summe der ersten neun Wartezeiten beträgt 1+2+4+8+16+32+64+128+256 = 511 s ≈ 8,5 min. Nach dem zehnten Fehlschlag geht der Dienst in den Zustand "wiederholt gescheitert" und wird ohne Bedienerhandlung nicht weiter gestartet. Die verworfene Alternative, unbegrenzt weiterzuversuchen, erzeugt bei einem dauerhaft fehlerhaften Dienst eine Dauerlast, einen unbegrenzt wachsenden Protokollstrom und eine Störungsmeldung, die sich alle fünf Minuten selbst erneuert. Der Zähler wird zurückgesetzt, sobald der Dienst 10 Minuten ohne Fehlschlag gelaufen ist.

### Knotenausfall

| Fall | Neuplatzierung | Zeit bis Wiederanlauf | Datenstand |
|---|---|---|---|
| Dienst ohne Speicherbereich | selbsttätig | ≤ 90 s (K-06) | entfällt |
| Speicherklasse **Synchron gespiegelt** | selbsttätig | RTO ≤ 5 min (K-08) | RPO 0 |
| Speicherklasse **Gespiegelt** | nach ausdrücklicher Übernahmeentscheidung | RTO ≤ 30 min (K-09) | bis zu 15 min alt (K-09) |
| Speicherklasse **Lokal** | nur über Wiederherstellung | RTO ≤ 4 h (K-10) | bis zu 24 h alt (K-10) |

Die Kette bis zur Übernahme folgt K-07 und INV-06: Heartbeat 5 s, Lease 20 s, Selbstabschottung bei Leaseablauf, Übernahme frühestens nach 30 s. Die Reserve von 10 s zwischen Leaseablauf und Übernahmefreigabe liegt um den Faktor 20 über der zugelassenen Uhrenabweichung von 500 ms. Bei Speicherklasse "Gespiegelt" ist die Übernahme bewusst eine Bedienerentscheidung: Eine selbsttätige Übernahme auf einen bis zu 15 Minuten alten Stand verwirft Daten, über deren Verlust niemand entschieden hat.

### Verhältnis zu INV-25

Ein einmal erfolgreich gestarteter Dienst überlebt den Ausfall der gesamten Kontrollebene und startet lokal neu. Die Beschreibung, aus der er startet, liegt auf dem Knoten; sie wird von atrium-node aus dem Sollzustand erzeugt und nicht bei jedem Start neu abgerufen. Was in diesem Zustand **nicht** geht, ist vollständig aufzählbar:

| Nicht verfügbar ohne Kontrollebene | Folge | Zeit bis zur Wirkung |
|---|---|---|
| Neuplatzierung nach Knotenausfall | Der Dienst bleibt aus | sofort |
| Neue oder geänderte Veröffentlichung | Erreichbarkeit friert ein | sofort |
| Zertifikatserneuerung | Die Veröffentlichung wird irgendwann TLS-ungültig | siehe Rechnung |
| Neue Zuweisungen, Tokenausstellung für neue Personen | Keine neuen Nutzer | sofort |
| Aktualisierung, Deinstallation, Speicherklassenwechsel | gesperrt (INV-04) | sofort |

Die Zertifikatsrechnung ist die eigentliche Grenze von INV-25. Nach K-13 laufen Dienstzertifikate 90 Tage und werden ab Tag 60 erneuert, also bei 30 Tagen Restlaufzeit. Fällt die Kontrollebene aus, liegt die Restlaufzeit eines beliebigen Dienstzertifikats gleichverteilt zwischen 30 und 90 Tagen; der Mittelwert beträgt 60 Tage, die garantierte Untergrenze 30 Tage. Deutung: Dienste überleben einen Kontrollebenenausfall in Bezug auf Start und Neustart unbegrenzt, in Bezug auf ihre TLS-gültige Erreichbarkeit mindestens 30 und im Mittel 60 Tage. Das ist eine belastbare Zusage und keine unbegrenzte; die Konsole nennt an jedem Dienst die kürzeste Restlaufzeit seiner Veröffentlichungen.

## 15.6 Daten

### Zuordnung

Jeder Speicherbereich eines Dienstes ist genau ein ZFS-Dataset nach dem Namensschema aus KANON 3 und trägt einen eigenen Datenschlüssel, der vom Hauptschlüssel des Mandanten umschlossen wird. Die Trennung je Speicherbereich ist nicht kosmetisch: Ohne einen eigenen Schlüssel je Speicherbereich ist die Vernichtung der Daten eines einzelnen Dienstes durch Schlüssellöschung nicht möglich, weil der Mandantenschlüssel weiterlebt (15.9).

| Speicherklasse | Technik nach Knotenzahl (KANON 4, Entscheidung 3) | RPO | RTO |
|---|---|---|---|
| Lokal | Momentaufnahmen plus externe Auslagerung | ≤ 24 h, wählbar bis 1 h (K-10) | ≤ 4 h |
| Gespiegelt | asynchrones ZFS send/recv ab 2 Knoten | ≤ 15 min (K-09) | ≤ 30 min |
| Synchron gespiegelt | DRBD/LINSTOR ab 3 Knoten oder 2 + Zeuge | 0 (K-08) | ≤ 5 min |

Der Bediener wählt ausschließlich die Klasse. Die Technik wird abgeleitet, und der daraus folgende RPO wird am Dienst angezeigt. Ist die gewählte Klasse mit der aktuellen Knotenzahl nicht erfüllbar, wird sie nicht still abgesenkt, sondern die Platzierung ist unlösbar mit der Ursache "Speicherklasse Synchron gespiegelt benötigt 3 Verwaltungsknoten oder 2 Verwaltungsknoten und einen Zeugen".

### Anwendungskonsistente Sicherungshaken

```
Ablauf je Speicherbereich mit deklarierten Haken:

  t0        atrium-node ruft  vorbereiten   (Frist 10 s)
            -> Dienst leert Puffer, setzt Schreibbarriere, bestaetigt
  t0+x      Momentaufnahme des Speicherbereichs  (atomar, Zielwert <= 1 s)
  t0+x+1    atrium-node ruft  freigeben     (Frist 5 s)
            -> Dienst nimmt Schreibvorgaenge wieder auf
  Ergebnis: Wiederherstellungspunkt, Kennzeichnung "anwendungskonsistent"

Fehlerfaelle:
  vorbereiten antwortet nicht in 10 s
     -> Barriere wird aufgehoben, Momentaufnahme wird trotzdem erstellt,
        Kennzeichnung "absturzkonsistent", Ereignis am Dienst (INV-18)
  freigeben antwortet nicht in 5 s
     -> Barriere wird zwangsweise aufgehoben, Ereignis mit Schweregrad Stoerung
```

Der obere Grenzwert des Stillstands beträgt 10 s + 1 s + 5 s = 16 s; der praktisch erwartete Wert liegt weit darunter, weil nur die Barriere hält und keine Daten kopiert werden. Fehlt der Haken im Katalogeintrag, ist jeder Wiederherstellungspunkt dieses Speicherbereichs dauerhaft als "absturzkonsistent" gekennzeichnet, und der Dienst zeigt das an sich selbst statt in einem Bericht. Für datenbankgestützte Katalogeinträge ist die Anforderung an den Haken benannt und die Umsetzung nicht erfunden: Der Haken muss einen Zustand herstellen, aus dem das Datenbankprodukt ohne Bedienerhandlung wieder anläuft — bei PostgreSQL ist das entweder ein konsistenter Basisstand mit fortgeschriebenem Transaktionsprotokoll oder ein logischer Export; welcher der beiden Wege der Katalogeintrag wählt, deklariert er, und dieses Kapitel legt ihn nicht fest.

### Wiederherstellung einer einzelnen Dienstinstanz

```
Wiederherstellen "Ticketsystem Vertrieb" auf Stand vom <Zeitpunkt>:

  1  Dienst anhalten (Vorgang, Wirkungsvorschau nennt die Unterbrechungsdauer)
  2  Wiederherstellungspunkt pruefen  (Signatur, Pruefsumme, Konsistenzkennzeichen)
  3  Speicherbereich aus dem Wiederherstellungspunkt zurueckschreiben
  4  Abgeleitete Artefakte NICHT zurueckschreiben, sondern aus dem
     aktuellen Sollzustand neu ableiten
  5  Dienst starten, Bereitschaftsprobe abwarten
  6  Abgleich gegen die aktuellen Zuweisungen; Abweichungen einzeln benennen
```

Schritt 4 ist der Kern und zugleich die Stelle mit der größten Fehlvorstellung. Eine Wiederherstellung setzt **Daten** zurück, nicht Konfiguration; Route, Namenseintrag, Zertifikat und Fremdkonten folgen weiterhin dem aktuellen Sollzustand. Daraus entsteht ein reales Problem: Personen, die zwischen dem Stand des Wiederherstellungspunkts und dem Zeitpunkt der Wiederherstellung eine Zuweisung erhalten haben, existieren im zurückgeschriebenen Datenbestand des Fremdprodukts nicht mehr, während ihre Zuweisung im Sollzustand weiterbesteht. Der Abgleich in Schritt 6 legt sie erneut an; Personen, deren Zuweisung im selben Zeitraum entzogen wurde, sind im Datenbestand wieder vorhanden und werden vom Abgleich erneut deaktiviert. Die Wirkungsvorschau nennt beide Mengen mit Anzahl vor der Bestätigung; was der Abgleich nicht heilen kann, sind fachliche Objekte innerhalb der Produktgrenze, die auf zwischenzeitlich entstandene Personen verweisen.

### Datenumzug bei Umplatzierung

Annahmen: Speicherbereich 200 GB; Nettodurchsatz der Strecke 800 Mbit/s bei 1 Gbit/s Anschluss; Änderungsrate 1 % des Bestandes je Stunde.

| Schritt | Rechnung | Ergebnis |
|---|---|---|
| Vollübertragung | 200 GB = 1.600 Gbit; 1.600 / 0,8 Gbit/s | **2.000 s = 33,3 min** |
| Anfall während der Vollübertragung | 33,3 min = 0,556 h; 0,556 × 1 % × 200 GB | 1,11 GB |
| Erstes Inkrement | 1,11 GB = 8,9 Gbit; 8,9 / 0,8 | **11,1 s** |
| Anfall während des ersten Inkrements | 11,1 s = 0,00308 h; × 1 % × 200 GB | 6,2 MB |
| Zweites Inkrement | 6,2 MB ≈ 0,05 Gbit; 0,05 / 0,8 | **0,06 s** |
| Umschaltfenster | Dienst anhalten + letztes Inkrement + Einbinden + Start + Bereitschaftsprobe | **Zielwert ≤ 2 min** |

Deutung: Der Umzug kostet rund 33 Minuten Hintergrundlast und unter zwei Minuten Unterbrechung. Die Unterbrechung ist unabhängig von der Datenmenge, solange die Änderungsrate kleiner ist als der Durchsatz; sie wird erst dann unbegrenzt, wenn die Änderungsrate den Durchsatz erreicht — bei 800 Mbit/s entspricht das 360 GB je Stunde, was die Annahme von 2 GB je Stunde um den Faktor 180 überschreitet. Das ist ein Modell, keine Messung. Für Speicherklasse "Synchron gespiegelt" entfällt die Vollübertragung, wenn der Zielknoten bereits ein Replikat trägt; dann besteht der Umzug nur aus dem Umschaltfenster.

## 15.7 Aktualisierung

### Reihenfolge und Wartungsfenster

| Schritt | Inhalt | Zielwert |
|---|---|---|
| 1 Vorabprüfung | Signatur, Digest, Stückliste, Schemaschritte, Budget, freie Kapazität, geprüfter Wiederherstellungspunkt vorhanden | ≤ 30 s, nebenwirkungsfrei |
| 2 Wiederherstellungspunkt | anwendungskonsistente Momentaufnahme unmittelbar vor der Aktualisierung | ≤ 16 s Stillstand (15.6) |
| 3 Erste Instanz | eine Instanz des Katalogeintrags im Verbund, vorzugsweise eine ausdrücklich benannte | Beobachtungsfenster 30 min (K-23) |
| 4 Restliche Instanzen | nacheinander, nie gleichzeitig, Anti-Affinitätsgruppen getrennt | — |
| 5 Abschluss | Fassung im Sollzustand fortgeschrieben, Rücksprungpunkt benannt | — |

Ein Wartungsfenster ist ein Attribut des Dienstes und nicht des Vorgangs: Es benennt, wann eine Aktualisierung beginnen darf. Die Vorabprüfung läuft außerhalb des Fensters, weil sie nebenwirkungsfrei ist; die Wirkungsvorschau nennt vor der Freigabe die erwartete Unterbrechungsdauer aus `migration.dauer_annahme_min` und kennzeichnet sie als Annahme des Katalogeintrags.

### Vorabprüfung

| Prüfung | Fehlverhalten ohne sie |
|---|---|
| Signatur und Digest der Zielfassung | Ein manipuliertes Abbild startet |
| Migrationspfad lückenlos von der laufenden zur Zielfassung | Eine übersprungene Hauptfassung führt zu einem nicht startenden Dienst mit halb migriertem Datenbestand |
| Budget der Zielfassung ≤ verfügbare Kapazität am aktuellen Ort | Die Aktualisierung endet in einer Neuplatzierung mitten im Vorgang |
| Geprüfter Wiederherstellungspunkt jünger als 24 h vorhanden | Der Rückweg existiert nicht |
| Konnektorvertragsversion der Zielfassung unterstützt | Die Versorgung bricht nach der Aktualisierung |
| Schemaschritt und Laufzeitwechsel getrennt deklariert | Zwei Fehlerquellen in einem nicht trennbaren Schritt (INV-24 sinngemäß) |

`uebersprungene_hauptfassungen: nein` wird erzwungen: Eine Aktualisierung über mehrere Hauptfassungen hinweg wird abgelehnt und als Folge von Einzelschritten angeboten, jeder mit eigenem Wiederherstellungspunkt.

### Datenbankmigrationen und die Grenze der Rückrollbarkeit

| Bestandteil der Aktualisierung | Rückrollbar | Weg zurück |
|---|---|---|
| Abbildfassung | ja | Vorherigen Digest setzen, Dienst neu starten |
| Erzeugte Dienstbeschreibung, Budget, Platzierung | ja | Vorherige Sollzustandsversion |
| Veröffentlichung, Route, Namenseintrag, Zertifikat | ja | Abgeleitet, wird neu berechnet |
| Konnektorbindung und Vertragsversion | ja | Vorherige Bindung |
| **Gelaufene Datenmigration** | **nein** | Wiederherstellung aus dem Wiederherstellungspunkt vor der Migration |

Dies ist die zentrale ehrliche Aussage dieses Abschnitts. Eine Datenmigration ist eine gerichtete Umformung des Bestandes; ein Rückweg existiert nur, wenn das Fremdprodukt ihn bereitstellt, und im quelloffenen Serverumfeld stellt er ihn in aller Regel nicht bereit. `migration.rueckrollbar` trägt deshalb den Festwert `nein`; ein Katalogeintrag, der `ja` behauptet, wird nur mit einer gegen eine Laborinstanz durchlaufenen Rückmigration aufgenommen.

Der tatsächliche Weg zurück ist eine Wiederherstellung und kostet Daten. Die Menge ist berechenbar:

```
Datenverlust beim Rueckweg = Betriebsdauer seit dem Wiederherstellungspunkt
```

| Zeitpunkt der Entscheidung | Verlust | Bezeichnung in der Konsole |
|---|---|---|
| innerhalb des Beobachtungsfensters (30 min, K-23), Dienst noch nicht freigegeben | ≤ 30 min | "Auf vorherige Fassung zurücksetzen" |
| nach Freigabe für Nutzer, 4 h Produktivbetrieb | 4 h | "Wiederherstellung mit Datenverlust: 4 Stunden" |
| nach 3 Tagen | 3 Tage | "Wiederherstellung mit Datenverlust: 3 Tage" |

Die Konsole benennt die Zeitspanne und, soweit das Fremdprodukt sie liefert, die Zahl der seither geänderten Fachobjekte. Sie liefert sie meist nicht; in diesem Fall steht dort die Zeitspanne und der Satz, dass die Zahl der betroffenen Vorgänge im Produkt nicht ermittelbar ist. Die verworfene Alternative — die Handlung weiterhin "zurückrollen" zu nennen — erzeugt genau die Fehlvorstellung, die den Schaden verursacht.

Viele Fremdprodukte führen ihre Migration beim Start aus. In diesem Fall sind Laufzeitwechsel und Datenmigration nicht trennbar, und die Trennung, die INV-24 für den Sollzustand vorschreibt, ist für den Dienst nicht herstellbar. Der Katalogeintrag deklariert das als `laeuft_beim_start: ja`, die Vorabprüfung erzwingt dann einen geprüften Wiederherstellungspunkt, die Wirkungsvorschau nennt die Nichttrennbarkeit im Klartext, und das Beobachtungsfenster beginnt erst nach bestandener Bereitschaftsprobe. Das ist eine Milderung und keine Lösung.

## 15.8 Produktgrenze je Katalogeintrag (INV-30)

### Drei Beispiele

| Gegenstand | Ticketsystem (Znuny) | Datenbankdienst (PostgreSQL) | Dateidienst (Nextcloud) |
|---|---|---|---|
| Konto einer Person | **Atrium** (über Zuweisung) | **Atrium** nur für Dienstkonten anderer Dienste; keine Personenkonten | **Atrium** (über Zuweisung) |
| Rollenmitgliedschaft | **Atrium** | **Atrium** (Rolle im Datenbankdienst je nutzendem Dienst) | **Atrium** |
| Feingranulare Rechte innerhalb des Produkts | **Fremd** (Warteschlangenzuordnung, Eskalation, automatische Antworten) | **Fremd** (Rechte auf Schemaebene, Zeilenfilter) | **Fremd** (Freigaben, Freigabelinks, Anwendungsrechte) |
| Fachobjekte | **Fremd** (Vorgänge, Textbausteine) | **Fremd** (Schemata, Tabellen, Daten) | **Fremd** (Dateien, Ordner, Kommentare) |
| Erreichbarkeit unter einem Namen | **Atrium** (Veröffentlichung) | keine Veröffentlichung; nur dienstinterne Erreichbarkeit | **Atrium** (Veröffentlichung) |
| Grundadresse im Produkt | **Atrium** (folgt der Veröffentlichung) | entfällt | **Atrium** (folgt der Veröffentlichung) |
| Zertifikat | **Atrium** | **Atrium** (gegenseitig authentisiertes TLS zwischen Diensten) | **Atrium** |
| Speicherbereich, Sicherung, Wiederherstellung | **Atrium** | **Atrium** | **Atrium** |
| Fassung und Aktualisierung | **Atrium** | **Atrium** | **Atrium** |
| Anmeldung | **Atrium** über OIDC | kein Personenzugang; Dienstzugang über Zertifikat oder Geheimnisreferenz | **Atrium** über OIDC |
| Produkteigene Verwaltungsoberfläche | bleibt erreichbar, für alles unter "Fremd" | keine; die Oberfläche ist das Drahtprotokoll | bleibt erreichbar, für alles unter "Fremd" |

Der Datenbankdienst ist der lehrreiche Fall. Seine "Fremdoberfläche" ist keine Oberfläche, sondern ein Protokoll; die Produktgrenze verläuft trotzdem an derselben Stelle, nämlich zwischen Zugangsobjekt und Inhalt. Atrium legt Rollen und Rechte auf Datenbankebene an, weil sie unmittelbar aus der Abhängigkeitskante eines anderen Dienstes folgen; es legt keine Schemata und keine Tabellen an, weil sie zum Fachbestand des nutzenden Produkts gehören. Ein Personenkonto in der Datenbank existiert nicht, weil kein Mensch sich direkt anmeldet — und wo jemand es doch tun muss, ist das ein befristeter Supportzugang aus [Kapitel 19](19-mandanten-rechte-audit.md) und kein Dauerzustand.

Die Feldnamen der drei Produkte werden hier bewusst nicht genannt. Verbindlich ist nicht der Name, sondern dass für jedes Objekt, das das Produkt anbietet, genau eine Eigentumsangabe existiert; der tatsächliche Objekt- und Feldsatz wird bei der Erstellung des Katalogeintrags gegen die eingesetzte Fassung erhoben. Dieser Entwurf erfindet keine Feldliste.

### Anmeldedurchreichung über OIDC

| Gegenstand | Herkunft | Wird eingegeben |
|---|---|---|
| Kennung des vertrauenden Dienstes | abgeleitet aus der Dienstkennung (ULID) | nein |
| Rücksprungadressen | abgeleitet aus allen Veröffentlichungen des Dienstes | nein |
| Geheimnis des vertrauenden Dienstes | erzeugt, als Geheimnisreferenz abgelegt, nie angezeigt (INV-20) | nein |
| Vertrauenswürdige Ausstellerangabe im Dienst | abgeleitet aus dem Protokollkopf des Mandanten | nein |
| Anspruch `sub` | Kennung der Person, stabil über Umbenennungen | nein |
| Gruppenangabe | ausschließlich Gruppen mit einer Zuweisung auf **diesen** Dienst | nein |

Die Ausstellung erbringt der Protokollkopf aus [Kapitel 10](10-identitaet.md) nach OpenID Connect Core 1.0 mit PKCE (RFC 7636); Zugriffstoken folgen RFC 9068. Ohne wirksame Zuweisung wird für diesen Dienst kein Token ausgestellt, auch dann nicht, wenn im Fremdprodukt ein Konto besteht. Damit ist der Zugang auch bei fehlerhafter Versorgung gesperrt und nicht offen. Für Produkte ohne brauchbare OIDC-Unterstützung deklariert der Katalogeintrag `anmeldung: kopfzeile_am_eingang`; dieser Weg ist schwächer, wird in der Konsole als schwächer gekennzeichnet, und der Dienst liegt dann in einer Netzzone, die nur den Eingang als Quelle zulässt.

### Rechteabbildung

| Ebene | Wer entscheidet | Beispiel |
|---|---|---|
| Darf die Person den Dienst überhaupt benutzen | Atrium, über die Zuweisung | Schalter "Ticketsystem" an der Person |
| Welche grobe Rolle hat sie im Produkt | Atrium, über die Rolle der Zuweisung | Bearbeiter oder Kunde |
| Was diese Rolle im Produkt konkret darf | das Produkt | welche Warteschlangen die Rolle sieht |

Atrium bildet ausschließlich die ersten beiden Ebenen ab. Die verworfene Alternative, die dritte Ebene mitzuverwalten, bedeutet, die Rechtemodelle von hunderten Produkten im Objektmodell nachzubauen; sie scheitert nicht an Aufwand allein, sondern daran, dass jedes Produkt sein Rechtemodell zwischen Fassungen ändert und Atrium damit bei jeder Aktualisierung eine Abbildungsmigration bräuchte.

### Handänderung in der Fremdoberfläche

| Fall | Verhalten | Sichtbarkeit |
|---|---|---|
| Feld im Eigentum von Atrium wird fremd geändert | Der nächste Abgleich setzt es zurück; Auditereignis "Abweichung korrigiert" (INV-02) | Am Dienst und im Verlauf, mit Beobachtungszeitpunkt (INV-28) |
| Feld im Eigentum des Fremdsystems wird geändert | Bleibt unverändert; Atrium liest es allenfalls | keine Meldung |
| Erstanlagefeld wird geändert | Bleibt unverändert; Abweichung wird gemeldet, nicht korrigiert | Hinweis am Objekt |
| Objekt wird in der Fremdoberfläche gelöscht, das Atrium besitzt | Der nächste Abgleich legt es neu an | Auditereignis mit Vorher/Nachher |

Die Verzögerung ist die ehrliche Zahl dieses Abschnitts. Änderungen am Sollzustand sind ereignisgetrieben und nach K-16 in ≤ 30 s im Istzustand sichtbar; **Änderungen im Fremdsystem erzeugen kein Ereignis** und werden erst vom periodischen Volllauf gefunden, der nach K-16 ≤ 60 min braucht. Bei gleichverteiltem Änderungszeitpunkt beträgt die mittlere Bestandsdauer einer fremdseitigen Handänderung 30 Minuten, die maximale 60 Minuten. Deutung: Wer in der Fremdoberfläche ein Recht vergibt, das Atrium besitzt, hat es im Mittel eine halbe Stunde lang. Das ist keine Sicherheitslücke im Sinne einer Rechteausweitung — die Person hätte das Recht auch ohne Atrium vergeben können —, aber es ist eine Zusicherungslücke, und die Konsole benennt sie am Katalogeintrag statt sie zu verschweigen. Eine kürzere Frist ist nur mit einem Ereigniskanal des Fremdprodukts erreichbar, den die wenigsten anbieten.

## 15.9 Deinstallation

### Reihenfolge

```
Vorgang "Dienst entfernen", Teilschritte in dieser Reihenfolge:

  1  Zuweisungen entziehen
     -> je Zielsystem sichtbar; bei Znuny Deaktivierung statt Loeschung (Kapitel 09)
  2  Veroeffentlichungen zurueckziehen
     -> Route entfernen, Namenseintrag entfernen, Zertifikat sperren,
        Netzfreigaben entfernen; Default-Deny bleibt in jedem Fehlerzustand (INV-10)
  3  Konnektorbindungen entscheiden
     -> "Fremdkonten deaktivieren" oder "Fremdkonten belassen"; ausdrueckliche Auswahl
  4  Dienst anhalten und Abhaengige pruefen
     -> ein Dienst mit abhaengigen Diensten wird nicht entfernt; die Liste wird gezeigt
  5  Abschliessenden Wiederherstellungspunkt erzeugen und pruefen
  6  Speicherbereiche freigeben  -> Zustand "freigegeben", Aufbewahrungsfrist 30 Tage
  7  Dienstobjekt in den Zustand "entfernt"; Kennung bleibt fuer den Auditstrom bestehen
  8  Nach 30 Tagen: Vernichtung (Datenschluessel loeschen), Nachweis erzeugen
```

Schritt 4 ist eine harte Sperre und keine Warnung. Ein Datenbankdienst mit drei abhängigen Diensten wird nicht entfernt; die Konsole zeigt die drei Dienste mit Verweis, und der Vorgang endet ohne Wirkung. Die verworfene Alternative — Entfernen mit Warnung — erzeugt drei gleichzeitig ausfallende Dienste aus einer einzigen Bestätigung, und INV-11 schließt genau das aus.

### Aufbewahrung und Vernichtung

| Größe | Wert | Quelle |
|---|---|---|
| Aufbewahrungsfrist freigegebener Datenträger | 30 Tage | K-25 |
| Aufbewahrung des letzten Wiederherstellungspunkts | nach Sicherungsplan des Mandanten, mindestens bis zum Aufbewahrungsende | Sicherungsplan |
| Auditereignisse zum entfernten Dienst | überleben die Löschung des Objekts | INV-23 |

Der Nachweis der Vernichtung besteht aus einem Auditereignis `speicherbereich.vernichtet` mit der Kennung des Speicherbereichs, der Referenz des gelöschten Datenschlüssels, der Liste der Ablageorte, einem Hashwert über diese Liste und einem Zeitstempel nach RFC 3161. Die Wirksamkeit beruht auf der Löschung des Datenschlüssels: Ohne ihn sind alle verbleibenden verschlüsselten Kopien unlesbar, gleich wo sie liegen.

Drei Einschränkungen gehören an diese Stelle und nicht in eine Fußnote. Erstens wirkt die Schlüssellöschung nur, wenn jeder Speicherbereich einen eigenen Datenschlüssel hat; deshalb ist das eine Anforderung und keine Option. Zweitens bleiben extern ausgelagerte Wiederherstellungspunkte bis zu ihrem eigenen Aufbewahrungsende bestehen — die Konsole nennt vor der Bestätigung die Zahl der noch bestehenden Kopien und das späteste Ende, statt "vernichtet" zu behaupten. Drittens ist die physische Nichtwiederherstellbarkeit auf dem Datenträger damit nicht belegt, sondern nur die kryptographische; für Datenträger, die das System verlassen, bleibt die physische Vernichtung eine organisatorische Maßnahme, die Atrium dokumentiert und nicht ausführt.

### Rückbau der abgeleiteten Artefakte

| Artefakt | Rückbau | Nachlaufzeit |
|---|---|---|
| Namenseintrag (interne Sicht) | entfernt mit der Veröffentlichung | bis zur TTL von 300 s in Auflösern zwischengespeichert |
| Namenseintrag (externe Sicht) | entfernt mit der Veröffentlichung | bis zur TTL von 3.600 s zwischengespeichert |
| Route am Eingang | entfernt, atomarer Tausch des Konfigurationsstandes | sofort |
| Netzfreigaben | entfernt, Regelsatz atomar getauscht, Default-Deny bleibt | sofort |
| Zertifikat | gesperrt, Sperrliste verteilt, OCSP-Antwort geändert | Verteilzeit der Sperrliste |
| Fremdkonten | je Auswahl aus Schritt 3 deaktiviert oder belassen; nie gelöscht, wenn das Manifest `loeschen` nicht unterstützt | Versorgungslatenz nach K-15 |
| Erzeugte Dienstbeschreibung auf dem Knoten | entfernt beim nächsten Abgleich | ≤ 30 s (K-16) |

Die externe TTL von 3.600 s ist die längste Nachlaufzeit und die einzige, die der Bediener bemerkt: Ein extern veröffentlichter Name kann bis zu einer Stunde nach dem Rückbau noch aufgelöst werden. Die Verbindung scheitert dann am Eingang und nicht an der Auflösung; die Konsole nennt diese Stunde in der Wirkungsvorschau.

## 15.10 Durchgehendes Beispiel: eine Znuny-Instanz

### Entscheidungen und Vorbelegungen

| Schritt | Entscheidungen | Vorbelegt aus |
|---|---|---|
| Dienst hinzufügen | 3: Produkt "Znuny", Name "Ticketsystem Vertrieb", Veröffentlichung "interne Domäne" | Platzierung (Bewertungsfunktion), Speicherklasse (Richtlinie des Mandanten), Budget (Katalogeintrag), Hostname (technischer Name + Standarddomäne), Zugriffskreis (Netzzone des Mandanten), Kanal (Richtlinie), Datenbankdienst (Abhängigkeit des Katalogeintrags) |
| Person versorgen | 0 zusätzliche Felder: der Schalter "Ticketsystem" an der Person ist die Handlung | Rolle aus der Richtlinie `standardrolle_je_dienst`; fehlt sie, ist der Schalter gesperrt und das Formular bleibt unverändert |

Die Abhängigkeit zum Datenbankdienst erzeugt keine vierte Entscheidung. Sie steht im Katalogeintrag, bringt ihr eigenes Standardbudget und ihre eigene Datenklassenvorgabe mit und erscheint in der Wirkungsvorschau als eigenes Objekt mit Quellenangabe (INV-15).

### Ablauf mit Zeitrechnung

```
t=0      Bestaetigung der Wirkungsvorschau, Sollzustand geschrieben (Quorum)
t+0,05 s Platzierung berechnet und bereits in der Vorschau angezeigt (K-21: p95 <= 50 ms)
t+24 s   Abbild geladen: 300 MB bei 100 Mbit/s = 2.400 Mbit / 100 Mbit/s = 24 s  (Annahme,
         vgl. K-01); liegt das Abbild lokal, entfaellt dieser Posten vollstaendig
t+54 s   Datenbankdienst gestartet, Bereitschaftsprobe bestanden (Annahme 30 s)
t+84 s   Schema durch den Erststart des Ticketsystems angelegt (Annahme 30 s)
t+114 s  Ticketsystem gestartet, Startprobe bestanden
t+124 s  Bereitschaftsprobe zweimal erfolgreich (Intervall 5 s, 2 Erfolge)
t+129 s  Namenseintrag in der internen Sicht aufloesbar (K-17: p95 <= 5 s)
t+134 s  Zertifikat aus der Mandanten-Zwischen-CA ausgestellt (Laufzeit 90 d, K-13)
t+139 s  Route am Eingang aktiv, TLS-gueltige Adresse erreichbar (K-17: p95 <= 10 s)
t+139 s  Vorgang "abgeschlossen"; kein Teilschritt offen (INV-12)

Summe: rund 2 Minuten 20 Sekunden. Zielwert fuer die Aufgabe: <= 5 min mit Reserve.
Die Einzelposten 30 s / 30 s sind Annahmen ueber das Fremdprodukt und keine Messungen.
```

### Was dabei entstanden ist

| Objekt | Art | Quelle |
|---|---|---|
| Dienst "Ticketsystem Vertrieb" | Sollzustand | Bedienerentscheidung |
| Dienst "Datenbank für Ticketsystem Vertrieb" | Sollzustand | Abhängigkeit des Katalogeintrags |
| 2 Speicherbereiche | Sollzustand | Katalogeintrag und Richtlinie |
| 1 Veröffentlichung, nur intern | Sollzustand | Bedienerentscheidung |
| 1 Namenseintrag in der internen Sicht | abgeleitet | Veröffentlichung |
| 1 Route am Eingang | abgeleitet | Veröffentlichung |
| 1 Zertifikat, 90 Tage | abgeleitet | Veröffentlichung |
| 2 Netzfreigaben | abgeleitet | Veröffentlichung und Zugriffskreis |
| 1 Konnektorbindung zum Ticketsystem | Sollzustand | Katalogeintrag, `pflicht: ja` |
| 1 Eintrag beim Protokollkopf für die Anmeldung | abgeleitet | Dienst und Veröffentlichung |

Kein abgeleitetes Artefakt ist editierbar (INV-09). Die Firewallsicht in Netz & Namen zeigt die zwei Netzfreigaben mit Quellverweis auf die Veröffentlichung; es gibt keinen Dialog, in dem eine dritte Regel entstünde.

### Versorgung einer Person

```
Bediener: Personen & Gruppen -> Person -> Schalter "Ticketsystem" ein

  1  POST /v1/zuweisungen:plan  { subjekt: person, ziel: dienst, rolle: <aus Richtlinie> }
  2  Wirkungsvorschau:
       Ticketsystem : Konto anlegen, Rolle "Bearbeiter" setzen
       Anmeldung    : Person erhaelt Zugang ueber die Anmeldung von Atrium
       Kosten       : keine kostenwirksame Aktion deklariert (INV-29)
  3  Bestaetigen -> POST /v1/zuweisungen
  4  Versorgung: p50 <= 5 s, p95 <= 60 s, harte Obergrenze 15 min (K-15)
  5  Anmeldung der Person: Weiterleitung zum Protokollkopf, Rueckkehr mit Token,
     Ticketsystem legt die Sitzung an; das Kennwortfeld des Produkts bleibt ungenutzt
```

Die Person meldet sich im Ticketsystem mit demselben Verfahren an wie an der Atrium Console. Das Ticketsystem bedient sie danach mit seiner eigenen Oberfläche; welche Warteschlange sie sieht, entscheidet die Ticketsystemverwaltung dort. Diese Grenze ist der praktische Kern von INV-30: Der Vorgang "Nutzer versorgen" ist ein Schalter, und die Fachkonfiguration bleibt unangetastet.

## Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| R-15-01 | Ein Katalogeintrag ohne gültige Signatur nach RFC 8032 über die kanonische Form nach RFC 8785 und ohne Eintrag im Transparenzprotokoll mit Zeitstempel nach RFC 3161 ist nicht ladbar. Die Prüfung läuft beim Import und bei jedem Start von atrium-node. | KANON 4 Lieferkette |
| R-15-02 | Ein Katalogeintrag ohne vollständige Produktgrenzdeklaration über alle Objekte, die die Pflichtkonnektoren in `describe` melden, wird bei der Freigabe abgelehnt. | INV-30 |
| R-15-03 | Ein Katalogeintrag, dessen Produkt eine nicht abschaltbare Selbstaktualisierung besitzt, wird nicht freigegeben; ist sie nur durch fehlenden Netzzugang verhinderbar, wird diese Abhängigkeit am Dienst angezeigt. | INV-02 |
| R-15-04 | Jeder Katalogeintrag deklariert je Speicherbereich eine Datenklassenvorgabe und je Sicherungshaken eine Frist. Fehlt der Haken, trägt jeder daraus erzeugte Wiederherstellungspunkt dauerhaft die Kennzeichnung "absturzkonsistent". | INV-18, K-25 |
| R-15-05 | Die wirksame Vertrauensstufe eines Dienstes ist das Minimum aus der Stufe seines Katalogeintrags und den Stufen aller als Pflicht deklarierten Konnektoren. Die Konsole zeigt diesen Wert am Dienst. | INV-18, INV-30 |
| R-15-06 | Ein Dienst im Kanal `vorab` zeigt das dauerhaft am Dienst. Der Kanal `vorab` ist nie Vorgabe eines Mandanten. | INV-18 |
| R-15-07 | Ein abgekündigter, zurückgezogener oder gesperrter Katalogeintrag hält 0 laufende Dienste an und löscht 0 Speicherbereiche. Neue Dienste sind aus ihm nicht anlegbar. | INV-11 |
| R-15-08 | Das Formular "Dienst hinzufügen" enthält genau 3 Pflichtfelder: Produkt, Name, Veröffentlichungsart. Jedes weitere Feld ist vorbelegt und nennt seine Quelle. | INV-14, INV-15, K-03 |
| R-15-09 | Fehlt eine Richtlinie, aus der ein Feld vorbelegt wird, erhält das Formular kein zusätzliches Pflichtfeld; die Handlung ist an diesem Katalogeintrag gesperrt und die fehlende Richtlinie wird benannt. | INV-14, INV-15 |
| R-15-10 | Eine im Formular genannte Platzierung wirkt als harte Nebenbedingung. Scheitert der genannte Knoten an einer anderen harten Nebenbedingung, weicht das System auf 0 andere Knoten aus, sondern meldet Unlösbarkeit mit Ursache. | INV-12, KANON 5 |
| R-15-11 | `POST /v1/dienste:plan` verändert 0 Objekte in Atrium und 0 Objekte in Fremdsystemen und löst 0 `apply`-Aufrufe aus. | INV-08 |
| R-15-12 | Die Wirkungsvorschau einer Bereitstellung nennt alle abgeleiteten Abhängigkeitsdienste mit Quellenangabe und den Satz, dass nach Abschluss 0 Personen den Dienst nutzen können. | INV-15, INV-08 |
| R-15-13 | Die Platzierung ist deterministisch: gleicher Eingangszustand ergibt dieselbe Entscheidung. Bei Gleichstand gewinnt die lexikographisch kleinste Knotenkennung. | KANON 4 Entscheidung 2a |
| R-15-14 | Jede Platzierung trägt eine in einem Satz anzeigbare Begründung, die die beiden höchstgewichteten unterscheidenden Terme nennt. | KANON 4 Entscheidung 2a |
| R-15-15 | Eine unlösbare Platzierung beendet den Vorgang im Zustand "fehlgeschlagen" mit einer nach Nebenbedingungen verdichteten Ursachenliste und mindestens einer benannten kleinsten Änderung, die eine Platzierung erlaubt. | INV-12, INV-17 |
| R-15-16 | Ein selbsttätiges Ausbalancieren des Bestandes findet nicht statt. Eine Umplatzierung erfolgt nur durch Bedienerhandlung, durch Räumen oder durch Knotenausfall. | INV-11 |
| R-15-17 | Die Summe der harten Speichergrenzen aller Dienste eines Knotens überschreitet 85 % des zuteilbaren Speichers nicht. Eine Platzierung, die diese Grenze verletzt, wird nicht vorgenommen. | K-19 |
| R-15-18 | Jede Gesundheitsprobe ist lesend, ohne Anmeldung, ohne Geheimnis im Aufruf, und ihre Antwort enthält weder Fassungsangabe noch Pfad noch Fehlertext. | INV-08, Sicherheitsvorgabe |
| R-15-19 | Route und Namenseintrag einer Veröffentlichung werden erst nach bestandener Bereitschaftsprobe gesetzt. | K-17 |
| R-15-20 | Nach 10 aufeinanderfolgenden Fehlstarts innerhalb von 10 Minuten geht ein Dienst in den Zustand "wiederholt gescheitert" und startet ohne Bedienerhandlung nicht erneut. | INV-18 |
| R-15-21 | Abhängigkeiten zwischen Diensten sind azyklisch; ein Zyklus wird beim Import des Katalogeintrags abgelehnt. Eine Startreihenfolge wird knotenübergreifend nicht erzwungen. | INV-25 |
| R-15-22 | Ein Dienst, der einmal erfolgreich gestartet wurde, startet ohne Verbindung zur Kontrollebene lokal neu. Die Konsole nennt an jedem Dienst die kürzeste Restlaufzeit seiner Zertifikate als Grenze dieses Zustands. | INV-25, K-13 |
| R-15-23 | Jeder Speicherbereich trägt einen eigenen Datenschlüssel, der vom Hauptschlüssel des Mandanten umschlossen wird. | INV-20 |
| R-15-24 | Eine Wiederherstellung schreibt ausschließlich Daten zurück; abgeleitete Artefakte werden aus dem aktuellen Sollzustand neu berechnet. Die Wirkungsvorschau nennt die Zahl der Personen, die der anschließende Abgleich neu anlegt, und die Zahl, die er deaktiviert. | INV-09, INV-08 |
| R-15-25 | Eine Umplatzierung mit Datenverlagerung nennt in der Wirkungsvorschau die berechnete Übertragungsdauer und die erwartete Unterbrechungsdauer, jeweils mit der zugrunde gelegten Annahme. | INV-08, INV-15 |
| R-15-26 | Eine Aktualisierung ohne geprüften Wiederherstellungspunkt jünger als 24 h wird nicht begonnen. | INV-11 |
| R-15-27 | Eine Aktualisierung über mehr als eine Hauptfassung wird abgelehnt und als Folge von Einzelschritten mit je eigenem Wiederherstellungspunkt angeboten. | INV-24 sinngemäß |
| R-15-28 | `migration.rueckrollbar` ist `nein`, sofern nicht eine Rückmigration gegen eine Laborinstanz durchlaufen wurde. Nach einer gelaufenen Migration benennt die Konsole die Handlung als "Wiederherstellung mit Datenverlust" und nennt die Zeitspanne. | INV-12, INV-16 |
| R-15-29 | Deklariert ein Katalogeintrag `laeuft_beim_start: ja`, nennt die Wirkungsvorschau die Nichttrennbarkeit von Fassungswechsel und Datenmigration im Klartext. | INV-08, INV-24 |
| R-15-30 | Eine fremdseitige Änderung an einem Feld im Eigentum von Atrium wird spätestens nach 60 Minuten zurückgesetzt und erzeugt ein Auditereignis "Abweichung korrigiert". Die Konsole nennt diese Frist am Katalogeintrag. | INV-02, K-16 |
| R-15-31 | Atrium schreibt in Fremdprodukten ausschließlich Zugangs- und Rollenobjekte. Für produktinterne Feinrechte existiert in der API keine Schreiboperation. | INV-30 |
| R-15-32 | Ein Dienst mit mindestens einem abhängigen Dienst ist nicht entfernbar; der Vorgang endet ohne Wirkung und listet die abhängigen Dienste auf. | INV-11 |
| R-15-33 | Die Deinstallation erfordert eine ausdrückliche Auswahl über die Fremdkonten (deaktivieren oder belassen). Ein Vorgabewert ohne Auswahl existiert nicht. | INV-11, INV-13 |
| R-15-34 | Freigegebene Speicherbereiche werden nach 30 Tagen durch Löschung des Datenschlüssels vernichtet; der Nachweis ist ein Auditereignis mit Schlüsselreferenz, Ablageortliste, Hashwert und Zeitstempel nach RFC 3161. | K-25, INV-23 |
| R-15-35 | Vor der Bestätigung einer Vernichtung nennt die Konsole die Zahl der noch bestehenden ausgelagerten Kopien und deren spätestes Aufbewahrungsende. | INV-18, INV-12 |
| R-15-36 | Eingaben aus Katalogeinträgen und Bedienerfeldern werden am Rand gegen ein Schema validiert und niemals in eine Abfrage, einen Aufruf oder eine Schale interpoliert. Der generische Treiber führt aus einem Katalogeintrag keinen Ausdruck aus. | KANON 4 Sprachen, INV-21 |

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Ein Katalogeintrag mit gültiger Signatur, aber ohne Transparenzprotokolleintrag wird abgelehnt; die abgelegte Datei wird nach Manipulation beim nächsten Start erneut abgelehnt | R-15-01 | Lieferkettentest mit manipulierter Datei |
| Ein Eintrag, dessen Produktgrenzdeklaration ein von `describe` gemeldetes Objekt auslässt, wird bei der Freigabe abgelehnt | R-15-02 | Vertragstest gegen die Objektliste |
| Ein Testeintrag mit aktivierter Selbstaktualisierung durchläuft die Freigabe nicht | R-15-03 | Laufprobe an einer Laborinstanz |
| Das Formular "Dienst hinzufügen" enthält genau 3 Pflichtfelder; jedes vorbelegte Feld nennt seine Quelle | R-15-08, R-15-09 | Abgleich der Formulardefinition gegen die Aufgabendefinition im Bau (INV-14) |
| Bei fehlender Richtlinie ist die Handlung gesperrt und das Formular unverändert dreifeldrig | R-15-09 | Richtlinienraumdurchlauf mit und ohne gesetzte Richtlinie |
| Eine Bedienervorgabe auf einen Knoten, der die Kapazitätsbedingung verletzt, ergibt Unlösbarkeit und 0 Ausweichplatzierungen | R-15-10, R-15-15 | Platzierungstest mit erschöpftem Zielknoten |
| `plan` auf eine Bereitstellung erzeugt 0 Änderungen im Bestand jedes beteiligten Fremdsystems | R-15-11 | Vorschauprobe: Bestandsabruf vor und nach `plan` ist feldgleich |
| 1.000 Platzierungsläufe mit identischem Eingangszustand ergeben 1.000 identische Entscheidungen; ein künstlich erzeugter Gleichstand wählt die kleinste Kennung | R-15-13 | Determinismustest |
| Jede Platzierung liefert eine Begründung mit genau zwei benannten Termen oder dem Vermerk der Gleichstandsregel | R-15-14 | Darstellungstest über alle Platzierungen des Testbestands |
| Bei leerer zulässiger Menge nennt die Ausgabe je Nebenbedingung die Zahl der ausgeschlossenen Knoten und mindestens eine kleinste Änderung | R-15-15 | Unlösbarkeitstest mit 32 künstlich ausgeschlossenen Knoten |
| Eine Platzierung, die die 85-Prozent-Grenze überschreiten würde, wird nicht vorgenommen | R-15-17 | Kapazitätstest mit gefüllten Knoten |
| Jede Probenantwort aller Katalogeinträge enthält 0 Fassungsangaben, 0 Pfade, 0 Fehlertexte | R-15-18 | Musterprüfung über alle Probenantworten im Bau |
| Route und Namenseintrag entstehen nach, nicht vor der bestandenen Bereitschaftsprobe | R-15-19 | Zeitreihentest mit verzögerter Bereitschaft |
| Ein dauerhaft fehlstartender Dienst erzeugt nach 10 Versuchen 0 weitere Startversuche | R-15-20 | Fehlerinjektionstest über 60 min |
| Ein Katalogeintrag mit zyklischer Abhängigkeit wird beim Import abgelehnt | R-15-21 | Schemaprüfung |
| Nach Abschaltung der gesamten Kontrollebene startet jeder zuvor laufende Dienst nach Knotenneustart erneut | R-15-22 | Abschalttest der Kontrollebene mit anschließendem Knotenneustart |
| Jeder Speicherbereich besitzt einen eigenen Datenschlüssel; nach dessen Löschung ist der Bestand aus keiner Kopie lesbar | R-15-23, R-15-34 | Schlüsseltrennungstest mit Leseversuch aus der ausgelagerten Kopie |
| Eine Wiederherstellung erzeugt 0 zurückgeschriebene abgeleitete Artefakte; die Vorschau nennt zwei Zahlen (neu angelegt, deaktiviert) | R-15-24 | Wiederherstellungstest mit zwischenzeitlich geänderten Zuweisungen |
| Eine Aktualisierung ohne geprüften Wiederherstellungspunkt jünger als 24 h wird abgelehnt | R-15-26 | Vorabprüfungstest |
| Eine Aktualisierung über zwei Hauptfassungen wird abgelehnt und als zwei Schritte angeboten | R-15-27 | Migrationspfadtest |
| Nach gelaufener Migration enthält kein Konsolentext das Wort "zurückrollen"; die Zeitspanne des Datenverlusts wird genannt | R-15-28 | Musterprüfung der Meldungstexte gegen die Begriffs-Positivliste |
| Eine fremdseitige Änderung an einem Feld im Eigentum von Atrium ist spätestens nach 60 min zurückgesetzt und erzeugt genau 1 Auditereignis | R-15-30 | Abweichungstest mit Handänderung in der Fremdoberfläche |
| Die API kennt für produktinterne Feinrechte 0 Schreiboperationen | R-15-31 | Schnittstellenprüfung gegen die Operationsliste |
| Der Versuch, einen Datenbankdienst mit 3 abhängigen Diensten zu entfernen, endet mit 0 Wirkungen und einer Liste von 3 Diensten | R-15-32 | Abhängigkeitstest |
| Die Deinstallation ohne Auswahl über die Fremdkonten ist von der API nicht ausführbar | R-15-33 | Schemaprüfung und Mutationstest |
| Vor jeder Vernichtung nennt die Konsole Zahl und spätestes Ende der ausgelagerten Kopien | R-15-35 | Darstellungstest gegen den Sicherungsplan |
| Ein Katalogeintrag mit einer Zeichenkette in einem Feld, das an ein Fremdsystem gereicht wird, erzeugt 0 Auswertungen dieser Zeichenkette als Ausdruck | R-15-36 | Einschleusungstest über alle Manifest- und Katalogfelder |

## Offene Punkte

1. **Das Zeitbudget der vollständigen Neuberechnung ist ohne einen vorverdichteten Abzug nicht haltbar.** Aus K-21 folgen 0,83 µs je Termauswertung. Dieser Wert ist nur erreichbar, wenn Kapazitäts-, Fehlerzonen- und Replikatvektoren vor dem Lauf einmal als Ganzzahlfelder erstellt werden und keine Auswertung eine Abfrage auslöst. Ob dieser Abzug bei 500 Instanzen und 32 Knoten in der verbleibenden Zeit überhaupt erstellbar ist und wie er mit einer währenddessen eintreffenden Sollzustandsänderung umgeht, ist nicht entschieden; die Alternativen sind ein höherer Zielwert in K-21 oder ein inkrementell gepflegter Abzug mit eigener Konsistenzfrage.

2. **Die Anti-Affinitätsgruppe ist kein Objekt im Kanon.** Term 2 der Bewertungsfunktion und die entsprechende harte Nebenbedingung setzen voraus, dass zwei Dienste als "nicht in derselben Fehlerzone" markierbar sind. KANON 3 kennt die Fehlerzone am Knoten, aber kein Objekt, das eine Gruppe von Dienstinstanzen zusammenfasst. Offen ist, ob die Gruppe aus dem Katalogeintrag folgt (alle Instanzen desselben Produkts), aus dem Mandanten, aus einer ausdrücklichen Markierung — die eine vierte Entscheidung wäre — oder aus der Abhängigkeitskante. Ohne Entscheidung ist die Nebenbedingung nicht implementierbar.

3. **Der Umgang mit Diensten, die mehrere Instanzen desselben Speicherbereichs teilen, ist ungeklärt.** Das Modell ordnet jeden Speicherbereich genau einem Dienst zu. Reale Einsatzfälle — ein Dateibestand, den zwei Dienste lesen, oder eine horizontal skalierte Instanzgruppe hinter einer Veröffentlichung — verletzen diese Zuordnung. Offen ist, ob Atrium solche Fälle ablehnt, ob eine Veröffentlichung auf mehrere Dienstinstanzen zeigen darf und wie die Datensicherheitsstufe dann definiert ist. Die Ablehnung schließt eine relevante Klasse quelloffener Produkte aus; die Zulassung bricht die Eins-zu-eins-Zuordnung, auf der Platzierung, Sicherung und Vernichtung aufbauen.

4. **Die Nichttrennbarkeit von Fassungswechsel und Datenmigration ist gemildert, nicht gelöst.** Wo ein Fremdprodukt beim Start migriert, existiert kein Zustand zwischen alter Laufzeit und neuem Datenbestand, in dem eine Prüfung stattfinden könnte. Der Entwurf erzwingt dann einen Wiederherstellungspunkt und benennt die Folge, aber er kann den Schaden einer fehlerhaften Migration nicht auf eine Fassungsrücknahme begrenzen. Ob Atrium für diese Klasse von Einträgen eine erzwungene Erprobung auf einer Kopie des Speicherbereichs vor der Aktualisierung des Produktivdienstes verlangen soll — mit dem Preis der doppelten Datenmenge und der doppelten Dauer — ist eine Produktentscheidung, die hier nicht getroffen wird.

5. **Die 60-Minuten-Frist für fremdseitige Handänderungen ist für Rechteentzüge zu lang und nicht verkürzbar.** Ein in der Fremdoberfläche vergebenes Recht besteht im Mittel 30 Minuten. Für die meisten Fälle ist das vertretbar, für einen Dienst mit hohem Schutzbedarf nicht. Eine Verkürzung setzt einen Ereigniskanal des Fremdprodukts voraus, den wenige Produkte anbieten, oder einen häufigeren Volllauf, dessen Kosten mit der Objektzahl und der Ratenbegrenzung der Fremd-API steigen. Offen ist, ob der Katalogeintrag eine Abgleichfrequenz je Objektklasse deklarieren darf und wie die daraus folgende Last gegen K-16 und K-22 gerechnet wird.

6. **Der Nachweis der Vernichtung deckt den Fall des kopierten umschlossenen Schlüssels nicht ab.** Die Schlüssellöschung wirkt gegen jede verschlüsselte Kopie der Daten. Sie wirkt nicht, wenn ein Angreifer oder ein früheres Sicherungsverfahren sowohl den umschlossenen Datenschlüssel als auch den Hauptschlüssel des Mandanten besitzt. Ob der Datenschlüssel deshalb zusätzlich an ein knotengebundenes Geheimnis gebunden werden muss — mit der Folge, dass eine Wiederherstellung auf Ersatzhardware einen ausdrücklichen Schlüsseltransport braucht und K-24 dadurch aufwendiger wird — ist nicht entschieden.

7. **Das Standardbudget aus dem Katalogeintrag ist eine Angabe des Einreichenden und keine Eigenschaft der Last.** Die Platzierung rechnet mit diesem Wert; die tatsächliche Last hängt von Nutzerzahl, Datenmenge und Fachkonfiguration innerhalb der Produktgrenze ab, über die Atrium per INV-30 gerade nichts weiß. Ein zu niedriges Budget führt zu wiederholten Abbrüchen an der harten Speichergrenze, ein zu hohes verschwendet Kapazität und verschiebt die Instanzgrenze je Knoten. Offen ist, ob Atrium ein beobachtetes Budget aus dem Istzustand vorschlagen darf — was eine Rückkopplung vom Istzustand in eine Entscheidung wäre und damit gegen die Trennung aus KANON 4, Entscheidung 10, verstößt — oder ob die Fehlangabe ein dauerhaft in Kauf genommener Zustand bleibt.

8. **Die Behandlung eines Dienstes ohne jede Veröffentlichung ist im Bedienmodell unterbestimmt.** Ein Datenbankdienst wird nicht veröffentlicht; er ist ausschließlich für abhängige Dienste erreichbar. Damit fehlt ihm die Quelle, aus der sonst Netzfreigabe und Zertifikat folgen, und die Erreichbarkeit entsteht stattdessen aus der Abhängigkeitskante. Ob die Kante ein vollwertiges Erreichbarkeitsobjekt mit eigener Wirkungsvorschau sein muss oder eine Sonderform der Veröffentlichung, ist offen; die jetzige Beschreibung erzeugt eine zweite, schwächer sichtbare Quelle abgeleiteter Artefakte und schwächt damit INV-09.
