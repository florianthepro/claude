# 14 Mail: Anbindung fremder und eigener Postfachsysteme

## 14.1 Was Atrium an Mail verwaltet und was nicht

Ein Mailsystem beantwortet drei Fragen, die technisch und kaufmännisch getrennt sind: wer unter welcher Adresse sendet und empfängt (Verwaltung), wo die Nachrichten liegen (Ablage), und unter welchen Bedingungen fremde Server Post dieser Domäne annehmen (Nachweis und Transport). Atrium beansprucht die erste Frage vollständig, die dritte weitgehend und die zweite nur dann, wenn ein Katalogeintrag den Postfachdienst selbst erbringt.

| Aufgabe | Träger | Objekt im Sollzustand | Was Atrium besitzt |
|---|---|---|---|
| Adressvergabe, Zugehörigkeit, Sperrfrist | Atrium | Mailadresse, Postfach | vollständig |
| Berechtigung an einem Postfach | Atrium als Absicht, Fremdsystem als Wirkung | Zuweisung, Postfach.berechtigte | Absicht vollständig, Wirkung je nach Konnektorfähigkeit |
| Ablage der Nachrichten, Ordner, Regeln, Suchindex | Fremdsystem oder eigener Postfachdienst | keines | nichts; Atrium führt keine Nachrichteninhalte |
| Namensnachweis der Domäne (SPF, DKIM, DMARC, MTA-STS, TLS-RPT) | Atrium als DNS-Autorität | Maildomäne, abgeleitete DNS-Einträge | vollständig, sofern die Domäne bei Atrium autoritativ ist |
| Transport zwischen Servern | Absender- und Empfängerserver | keines | nur bei eigenem Postfachdienst |
| Lizenz- und Kontingentzuweisung | Fremdsystem | Zuweisung mit deklarierter Kostenwirkung | die Auslösung, nie die Preisbildung |

Daraus folgt die Objektkette. Sie ist bereits in KANON 3 festgelegt; dieses Kapitel benutzt sie unverändert und ergänzt nur die Richtung der Ableitung.

```
Person ----besitzt----> Postfach (art=persoenlich)
Gruppe ----besitzt----> Postfach (art=geteilt)
                            |
                            | ablageort
                            v
                     Konnektorbindung ----> Fremdsystem (M365 | Exchange | Google | IMAP | eigener Dienst)
                            ^
                            | anbieterbindung
Domaene --> Maildomaene ----+
   |            |
   |            +--> abgeleitete DNS-Eintraege: MX, SPF, DKIM(sN), DMARC, MTA-STS, TLS-RPT
   |
   +--> DNS-Eintrag (Sicht intern | extern)

Mailadresse --> genau ein Postfach, genau eine Maildomaene
```

Eine Mailadresse ist vom Postfach getrennt, weil beide unterschiedliche Lebenszyklen haben: eine Adresse wird umgeleitet, entfernt und nach Sperrfrist neu vergeben, ein Postfach wird archiviert und gelöscht. Die verworfene Alternative, die Adresse als Feld des Postfachs zu führen, macht Zweitadressen zu Listenfeldern ohne eigenen Zustand und die Sperrfrist nach K-25 unabbildbar.

Die Schwäche dieser Rollenteilung wird sofort sichtbar: Atrium verwaltet Absichten über Systeme, deren Ausführung es nicht kontrolliert. Jede Aussage der Konsole über ein fremdgehostetes Postfach ist eine Aussage über den zuletzt beobachteten Istzustand mit Beobachtungszeitpunkt (INV-28), nie eine Zusicherung. Abschnitt 14.9 zieht daraus die Grenze der Betriebssicht.

## 14.2 Konnektoren im Vergleich

Die Einstufung T0/T1/T2 folgt den Kriterien aus [Kapitel 09](09-konnektoren.md). Die Spalte "Feldeigentum" nennt die Deklaration nach INV-13; `atrium` bedeutet, dass der Reconciler das Feld überschreibt, `fremd` bedeutet, dass er es nie anfasst, `erstanlage` bedeutet, dass er es genau einmal setzt.

| Merkmal | Microsoft 365 | Exchange vor Ort | Google Workspace | Generisches IMAP/SMTP mit Verwaltungsschnittstelle | Eigener Postfachdienst als Katalogeintrag |
|---|---|---|---|---|---|
| **Anbindungsweg** | HTTPS-Verwaltungsschnittstelle des Anbieters, JSON, ressourcenorientiert; Einstufung T1 | administrative Fernschnittstelle des Produkts im eigenen Netz, kein JSON-Ressourcenmodell; Einstufung T2 | HTTPS-Verwaltungsschnittstelle des Anbieters, JSON; Einstufung T1 | produktspezifisch: HTTP/JSON ⇒ T1, produkteigenes Protokoll ⇒ T2; ohne Verwaltungsschnittstelle kein Konnektor, nur Buchführung | lokaler Dienst, Verwaltung über den Katalogeintrag; T1 oder T2 je Produkt |
| **Authentisierung** | OAuth 2.0 (RFC 6749) mit Dienstkonto-Zugangsdaten und einmaliger Mandantenzustimmung; Zugriffstoken als JWT (RFC 7519, RFC 9068) | Dienstkonto im Verzeichnis, Kerberos v5 (RFC 4120) oder TLS-Clientzertifikat, sofern das Produkt es annimmt | OAuth 2.0 mit Dienstkonto und mandantenweiter Delegation | je Produkt; Zielvorgabe ist OAuth 2.0 oder TLS-Clientzertifikat, Kennwort nur als deklarierter Rückfall | TLS-Clientzertifikat aus der Mandanten-Zwischen-CA ([Kapitel 11](11-pki.md)) |
| **Anlegbar** | Konto, Postfach über Lizenzzuweisung, Zweitadressen, geteiltes Postfach, Verteiler, Mitgliedschaften | Postfach, Zweitadressen, geteiltes Postfach, Verteiler, Ordnerrechte, Absenderrechte | Konto, Zweitadressen, Gruppen als Verteiler, Gruppenpostfach, Delegation | abhängig von der Schnittstelle: meist Konto, Alias und Weiterleitung; Ordnerrechte selten | alles, was der Katalogeintrag führt |
| **Nicht anlegbar** | Domäne ohne Namensnachweis; Lizenz ohne freies Kontingent; Anbieteranwendung ohne Zustimmung | nichts Grundsätzliches, solange das Dienstkonto die Rolle trägt; Grenze ist die Rollenvergabe im Fremdsystem | Domäne ohne Namensnachweis; Lizenz ohne Kontingent; ein Objekt mit der Semantik "geteiltes Postfach ohne Lizenz" | geteiltes Postfach als eigenes Objekt; Absenderrechte; Ordnerrechte | keine Grenze aus der Anbindung; die Grenze ist das Produkt |
| **Feldeigentum (Deklaration)** | `atrium`: Adressen, Anzeigename, Zugehörigkeit, Aktivierungszustand. `fremd`: Ablageort, Kontingentgrenze, Lizenzpreis, Postfachinhalt. `erstanlage`: Sprache, Zeitzone | `atrium`: Adressen, Zugehörigkeit, Absender- und Ordnerrechte. `fremd`: Datenbankzuordnung, Kontingent. `erstanlage`: Anzeigename | wie Microsoft 365, zusätzlich `fremd` für die Gruppensemantik | `atrium`: Adressen, Weiterleitung. `fremd`: alles Übrige. Bei fehlender Schnittstelle: kein Feld in Atriums Eigentum | `atrium`: alles außer Nachrichteninhalt |
| **Kaufmännische Folge** | Lizenzzuweisung ist kostenwirksam und wird nach INV-29 vor der Bestätigung angezeigt; Zweitadressen und geteilte Postfächer sind es nach Anbieterregel nicht | keine laufende Zuweisungskosten; Kosten entstehen an Server, Speicher und Produktlizenz, nicht je Vorgang | Lizenzzuweisung kostenwirksam; Gruppen in der Regel nicht | keine, sofern das Produkt keine Kontenzählung führt | Kosten entstehen an Speicherbereich und Knoten, sichtbar über das Ressourcenbudget des Dienstes |
| **Was Atrium beobachten kann** | Kontenzustand, Adressen, Lizenzzustand, Kontingentgrenze; keine Warteschlange, keine Zustellprotokolle je Nachricht | Kontenzustand, Adressen, Rechte, Warteschlangenkennzahlen, sofern die Schnittstelle sie führt | Kontenzustand, Adressen, Lizenzzustand | Kontenliste; sonst nichts | alles, was der Dienst meldet |

Der Entwurf legt keinen Postfachdienst als Katalogeintrag fest. KANON 4 nennt für Mail keine Technologieentscheidung, und dieses Kapitel erfindet keine. Die Spalte "Eigener Postfachdienst" beschreibt deshalb die Anforderung an einen künftigen Katalogeintrag, nicht ein ausgewähltes Produkt; die Auswahl gehört in KANON 4 und ist in den offenen Punkten vermerkt.

## 14.3 Realitätsprüfung: was "über die Adresse anbinden" wirklich leistet

Die Forderung, ein Mailsystem allein über Adresse und Zugangsdaten anzubinden, trifft für einen Teil der Vorhaben zu und für einen anderen Teil nicht. Die Trennlinie verläuft nicht zwischen "eigenes Netz" und "Internet", sondern zwischen Zugriff auf Daten und Änderung der Verwaltungswirklichkeit eines fremden Mandanten.

| Vorhaben | Genügen Adresse und Zugangsdaten? | Zwingend zusätzlich | Grund |
|---|---|---|---|
| Nachricht an eine fremde Domäne zustellen (SMTP nach RFC 5321, 25/tcp) | ja | nichts | Die Serverübergabe ist anonym; geprüft wird die Absenderdomäne, nicht der Absenderserver. |
| Nachricht im Auftrag eines Postfachs einliefern (587/tcp, 465/tcp) | teilweise | bei Anbietern ein Zugriffstoken statt eines Kennworts, also eine Anwendungsregistrierung | Kennwortbasierte Einlieferung wird von Anbietern abgeschaltet; ein Token setzt eine registrierte Anwendung voraus. |
| Postfachinhalt lesen und schreiben (IMAP nach RFC 3501, 993/tcp) | ja bei eigenem Server und bei Exchange vor Ort; nein bei Anbietern | bei Anbietern Anwendungsregistrierung und Zustimmung | Gleiche Ursache wie bei der Einlieferung. |
| Verzeichnis im eigenen Netz lesen (LDAPv3 nach RFC 4511, 636/tcp) | ja | Dienstkonto mit Leserecht | Das Verzeichnis ist im Besitz des Kunden; die Zugangsdaten sind der vollständige Berechtigungsnachweis. |
| Postfach auf einem Exchange im eigenen Netz anlegen | ja | Dienstkonto mit der passenden Produktrolle | Dieselbe Ursache; die Rollenvergabe erfolgt im Fremdprodukt und ist ein einmaliger Schritt je Installation. |
| Postfach bei Microsoft 365 oder Google Workspace anlegen | **nein** | Anwendungsregistrierung im Mandanten des Kunden und Zustimmung eines Mandantenadministrators | Die Autorisierungsgrenze des Anbieters ist keine Netzgrenze. Ein Administratorkennwort ist nicht dasselbe wie die Zustimmung des Mandanten zu einer fremden Anwendung. |
| Domäne beim Anbieter hinzufügen | **nein** | Nachweis der Namenskontrolle über einen DNS-Eintrag | Der Nachweis belegt Kontrolle über den Namensraum, nicht Kenntnis eines Geheimnisses. Kein Zugangsdatum kann ihn ersetzen. |
| Lizenz zuweisen | **nein** | Zustimmung plus freies Kontingent im Vertrag des Kunden | Kaufmännische Wirkung setzt eine Vertragsbeziehung voraus, die Atrium nicht hat. |
| DMARC- und TLS-Berichte empfangen (RFC 7489, RFC 8460) | ja | ein DNS-Eintrag und eine Empfangsadresse | Berichte gehen an die Adresse in der veröffentlichten Richtlinie; es gibt keine Registrierung. |
| MX einer Domäne auf ein anderes System zeigen | ja, mit DNS-Hoheit | der Zielserver muss die Domäne kennen | Ein MX ohne Annahmebereitschaft des Ziels erzeugt Rückläufer, keine Zustellung. |

Die Konsequenz für die Bedienung ist ein einziger zusätzlicher Einrichtungsschritt, und er fällt genau einmal je Mandant und Anbieter an: **Verbindung freischalten**. Der Schritt besteht aus einem Zustimmungsablauf im Anbieterportal, den Atrium anstößt, dessen Ergebnis es entgegennimmt und in eine Konnektorbindung überführt. Wo das Fremdsystem einen browserlosen Weg anbietet, benutzt Atrium das Device Authorization Grant (RFC 8628), damit der Ablauf auch von einem Gerät ohne Anzeige startbar ist; wo dynamische Registrierung angeboten wird, benutzt Atrium RFC 7591 und erspart dem Bediener das Eintragen einer Anwendungskennung.

Der Schritt ist aus drei Gründen nicht wegzukürzen. Erstens ist die Zustimmung eines Mandanten eine Willenserklärung im fremden System und kein technischer Handschlag; sie ist per Konstruktion nicht durch einen Aufruf ersetzbar, den Atrium allein ausführen könnte. Zweitens hat die naheliegende Umgehung — Atrium hinterlegt ein Administratorkennwort und bedient die Fremdoberfläche automatisiert — drei disqualifizierende Eigenschaften: sie erzeugt ein dauerhaft gespeichertes Geheimnis mit maximalen Rechten statt eines auf Zwecke begrenzten Tokens, sie bricht bei jeder Oberflächenänderung des Anbieters, und sie ist keine vom Anbieter vorgesehene Schnittstelle. Der Entwurf lehnt sie ausdrücklich ab. Drittens ändert eine mandantenfähige Anwendungsregistrierung auf Herstellerseite nur die Gestalt des Schritts, nicht seine Existenz: auch dann bleibt die Zustimmung je Kundenmandant erforderlich.

Rechnung zum Aufwand. Annahme: 20 Mandanten, je ein Anbieter, Zustimmungsablauf 5 min einschließlich Anmeldung im Anbieterportal. Aufwand einmalig 20 × 5 min = 100 min. Annahme: 3 Domänen je Mandant, Nachweiseintrag je Domäne. Ist Atrium für die Domäne autoritativ, erzeugt es den Nachweiseintrag selbst und wiederholt die Prüfung selbsttätig; Bedienaufwand 0 min, Wartezeit bis zur Anbieterprüfung nach Annahme ≤ 15 min. Ist die Domäne anderswo delegiert, muss der Eintrag dort von Hand gesetzt werden; Aufwand je Domäne nach Annahme 5 min, also 20 × 3 × 5 min = 300 min. Deutung: Die DNS-Hoheit von Atrium senkt den Einrichtungsaufwand um den größeren der beiden Posten. Das ist ein Modell, keine Messung; die Wartezeit auf die Anbieterprüfung ist der unkontrollierbare Anteil.

## 14.4 Der Ablauf "Mail hinzufügen"

Der Ablauf beginnt an der Person im Bereich Personen & Gruppen und nirgends sonst. Es gibt keinen Mailbereich in der Navigation (KANON 5).

### Schrittfolge mit ausgelösten Aufrufen

```
1  Person öffnen
   GET /v1/personen/{person}
   -> Antwort enthaelt postfaecher[], mailadressen[], versorgungszustand je Zielsystem

2  Handlung "Mail hinzufuegen"
   GET /v1/maildomaenen?mandant={mandant}&zustand=aktiv&q={eingabe}
   -> Trefferliste, nach letzter Verwendung sortiert
   -> Vorbelegung, wenn genau eine aktive Maildomaene existiert (Quelle: "einzige aktive
      Maildomaene des Mandanten", INV-15)
   [ENTSCHEIDUNG 1: Maildomaene]

3  Lokalen Teil eingeben
   Vorbelegung aus Richtlinie namensschema_mail (Quelle wird am Feld angezeigt)
   [ENTSCHEIDUNG 2: lokaler Teil]

4  Pruefung waehrend der Eingabe, entprellt, hoechstens alle 300 ms
   POST /v1/mailadressen:pruefen { lokaler_teil, maildomaene }
   -> { frei: bool, sperrfrist_ende?: Zeitpunkt, domaene_zustand: eingerichtet|verifiziert|
        aktiv|gestoert, kontingent: { frei: n, gesamt: m } | unbekannt }
   Kein Schreibzugriff, kein Konnektoraufruf mit Wirkung (INV-08)

5  Wirkungsvorschau
   POST /v1/vorgaenge { art: "mail_hinzufuegen", entwurf: true, subjekt, maildomaene,
                        lokaler_teil }
   -> je beteiligter Konnektorbindung ein plan()-Aufruf ueber den Unix-Socket
   -> Rueckgabe: wirkungen[], kostenwirkung[], fehlende_voraussetzungen[]

6  Bestaetigen (zaehlt nicht als Entscheidung)
   POST /v1/vorgaenge/{vorgang}:freigeben      # nur wenn die Richtlinie Freigabe verlangt
   POST /v1/vorgaenge/{vorgang}:ausfuehren

7  Ausfuehrung
   apply() je Bindung mit Idempotenzschluessel je Schritt (INV-07)
   Reihenfolge: Postfach sicherstellen -> Adresse setzen -> Zugehoerigkeit setzen

8  Fortschritt
   GET /v1/vorgaenge/{vorgang}
   -> Teilzustand je Zielsystem; Zielwert p50 <= 5 s, p95 <= 60 s, harte Grenze 15 min (K-15)
```

### Wirkungsvorschau

Die Vorschau zeigt vor der Bestätigung genau die Wirkungen, die eintreten werden, jeweils mit Zielsystem und Quelle der Vorbelegung. Für den Normalfall "Person erhält ihre erste Adresse auf einer bereits aktiven Maildomäne bei einem Anbieter" sind das:

| Wirkung | Zielsystem | Kostenwirkung | Quelle |
|---|---|---|---|
| Postfach anlegen, Art persönlich | Konnektorbindung der Maildomäne | Lizenzzuweisung nach Standard-Lizenzprofil des Mandanten, kostenwirksam | Richtlinie `lizenzprofil_mail` |
| Adresse setzen, primär | dieselbe Bindung | keine | Erstadresse ist primär |
| Zugehörigkeit setzen (Mitgliedschaft in den Verteilern der Person) | dieselbe Bindung | keine | Gruppenmitgliedschaften der Person |
| Kontingent belegen: 1 von n | dieselbe Bindung | siehe Lizenzzeile | Beobachtung aus `observe` |
| DNS-Einträge | keines | keine | bereits vorhanden, da Maildomäne aktiv |

Die Lizenzzeile ist der Kern von INV-29 in diesem Kapitel. Sie nennt das Lizenzprofil im Klartext und die Tatsache, dass die Zuweisung kostenwirksam ist; sie nennt keinen Preis, weil Atrium den Vertragspreis des Kunden nicht kennt und ihn nicht erfinden darf.

### Warum das Lizenzprofil keine dritte Entscheidung ist

Bietet ein Mandantenvertrag mehrere Lizenzarten an, wäre die Auswahl eine dritte Entscheidung und der Ablauf bräche INV-14. Der Entwurf löst das durch eine Pflichtrichtlinie: je Maildomäne ist genau ein Standard-Lizenzprofil festgelegt. Ist keines festgelegt, wird der Ablauf nicht um ein Pflichtfeld erweitert, sondern die Handlung "Mail hinzufügen" ist an dieser Maildomäne gesperrt, mit dem Hinweis auf die fehlende Richtlinie und einem Verweis auf die Stelle, an der sie gesetzt wird. Die verworfene Alternative — Auswahlfeld im Formular — verschiebt eine Mandantenentscheidung in jeden Einzelvorgang und macht sie damit 500-mal statt einmal.

### Entscheidungszählung

| Feld | Entscheidung? | Herkunft, wenn keine |
|---|---|---|
| Maildomäne | ja (1) | entfällt; bei genau einer aktiven Maildomäne vorbelegt und damit 0 |
| Lokaler Teil | ja (2) | Vorbelegung aus `namensschema_mail`, Änderung zulässig |
| Postfachart | nein | abgeleitet: Subjekt ist eine Person ⇒ persönlich |
| Ablageort | nein | abgeleitet aus der Anbieterbindung der Maildomäne |
| Primär oder zusätzlich | nein | abgeleitet: erste Adresse des Postfachs ist primär |
| Lizenzprofil | nein | Richtlinie `lizenzprofil_mail` |
| Kontingent | nein | Beobachtung |
| Berechtigte | nein | bei persönlichen Postfächern leer |
| MX, SPF, DKIM, DMARC, MTA-STS, TLS-RPT | nein | abgeleitete Artefakte der Maildomäne (INV-09) |
| Sprache, Zeitzone | nein | `erstanlage` aus den Personendaten |
| Notiz | nein | Freitext, zählt nach INV-14 nicht |

Ergebnis: 2 Entscheidungen, im Sonderfall der einzigen aktiven Maildomäne 1. Die Obergrenze 3 aus INV-14 und der Entwurfsstand 2 aus K-03 sind eingehalten. Die Prüfung erfolgt im Bau gegen die maschinenlesbare Aufgabendefinition; ein zusätzliches Pflichtfeld bricht den Bau.

### Fehlerfälle

| Fall | Erkennung | Anzeige und angebotene Handlung |
|---|---|---|
| Adresse bereits vergeben | Schritt 4, `frei=false`, kein Sperrfristende | Nennung des besitzenden Postfachs mit Verweis; angeboten wird ein abgeleiteter freier Vorschlag nach dem Namensschema |
| Adresse in Sperrfrist | Schritt 4, `sperrfrist_ende` gesetzt | Klartext mit Datum des Fristendes und Begründung (fremde Post an die Vorbesitzerin); Freigabe vor Fristende ist ein eigener, freigabepflichtiger Vorgang, nicht ein Schalter im Formular |
| Maildomäne eingerichtet, aber nicht verifiziert | Schritt 4, `domaene_zustand=eingerichtet` | Adresse wird angelegt und als "wartet auf Namensnachweis" geführt; angeboten wird der Nachweisschritt der Maildomäne mit direktem Verweis |
| Kontingent erschöpft | Schritt 5, Konnektorfehlerklasse `kontingent` | Nennung von belegtem und vorhandenem Kontingent, Angabe der Stelle, an der der Vertrag erweitert wird; der Vorgang bleibt als "blockiert" bestehen und wird bei freiem Kontingent fortgesetzt |
| Fehlendes Fremdrecht | Schritt 5 oder 7, Klasse `rechte` | Nennung des fehlenden Rechts aus dem Manifest im Klartext und der Konnektorbindung, an der es hinterlegt wird |
| Bindung gestört oder Zustimmung widerrufen | `healthcheck` schlägt fehl | Handlung "Mail hinzufügen" ist an dieser Maildomäne gesperrt; angeboten wird das erneute Freischalten der Verbindung |
| Fremdseitig existiert bereits ein Objekt mit dieser Adresse | Schritt 7, Klasse `konflikt` | Der Vorgang wird zur Entscheidung vorgelegt: bestehendes Fremdobjekt übernehmen oder Vorgang abbrechen; automatische Übernahme findet nicht statt |
| Teilerfolg über mehrere Zielsysteme | Schritt 8 | Zustand "teilweise fehlgeschlagen" mit Nennung des ausstehenden Zielsystems, des Grundes und der Wiederholungsmöglichkeit (INV-12); der Vorgang erreicht nie "fertig" |
| Zeitüberschreitung der Vorschau bei einer Bindung | Schritt 5 | "Vorschau für <Zielsystem> nicht rechtzeitig verfügbar"; die Freigabe bleibt möglich und trägt den Vermerk, dass eine Bindung ungeprüft ist |

Keiner dieser Fälle nennt einen Befehl oder einen Dateipfad als Lösungsweg (INV-17). Fremdtexte erreichen die Oberfläche nicht; sie gehen als `fremdsignal` in das Betriebsprotokoll, während die Konsole den übersetzten `schluessel` zeigt.

## 14.5 Geteilte Postfächer und Verteiler

### Gemeinsames Modell

Ein geteiltes Postfach ist ein Postfach der Art `geteilt`, dessen Eigentümer eine Gruppe ist. Ein Verteiler ist eine Gruppe der Art "Verteiler" mit mindestens einer Mailadresse und ohne Postfach. Die Unterscheidung ist damit nicht eine Einstellung, sondern die Existenz oder Nichtexistenz eines Postfachobjekts.

```
Postfach(art=geteilt)
  eigentuemer        -> Gruppe                       # Pflicht
  berechtigte[]      -> { subjekt, recht }           # Herkunft: direkt oder ueber Gruppe
  recht in { lesen, senden_als, senden_im_auftrag }
  ablageort          -> Konnektorbindung

Gruppe(art=verteiler)
  mitglieder[]       -> Person | Postfach | Gruppe   # azyklisch erzwungen
  mailadressen[]     -> Mailadresse
  annahme            -> intern | authentisiert | offen
```

Die drei Rechte sind bewusst getrennt. `lesen` erlaubt Zugriff auf den Inhalt, `senden_als` lässt die Nachricht so erscheinen, als käme sie vom Postfach, `senden_im_auftrag` macht die handelnde Person in der Nachricht sichtbar. Die Vermischung von `lesen` und `senden_als` in einem einzigen Schalter ist die verworfene Alternative; sie erzeugt genau den Fall, in dem eine Urlaubsvertretung ungewollt unter fremdem Namen sendet.

Das Anlegen eines geteilten Postfachs kostet drei Entscheidungen: Anzeigename, Maildomäne, lokaler Teil. Die besitzende Gruppe wird aus dem Anzeigenamen abgeleitet und mitangelegt; die Berechtigten werden danach als eigene Handlung an der Gruppe gepflegt. Das ist eine bewusste Aufteilung in zwei Aufgaben. Die Alternative, die Berechtigten im Anlegeformular abzufragen, hielte INV-14 nur, wenn die Mitgliederliste als optionales Feld geführt würde — und ein optionales Feld an dieser Stelle führt zu geteilten Postfächern ohne Berechtigte, die niemand bemerkt. Der Preis der gewählten Lösung ist ein zweiter Schritt; er wird im Abschluss des ersten Vorgangs als offene Aufgabe angezeigt.

### Abbildung auf die Backends

| Merkmal | Microsoft 365 | Exchange vor Ort | Google Workspace | Generisches IMAP/SMTP | Eigener Postfachdienst |
|---|---|---|---|---|---|
| Geteiltes Postfach als eigenes Objekt | vorhanden | vorhanden | **verlustbehaftet**: Abbildung auf ein Gruppenpostfach mit abweichender Semantik für Lesemarken und Ablage | **verlustbehaftet**: Abbildung auf ein zusätzliches Konto mit geteilten Zugangsdaten | abhängig vom Katalogeintrag |
| `lesen` je Person | vorhanden | vorhanden | vorhanden | nur gemeinsam für alle, die die Zugangsdaten haben | abhängig |
| `senden_als` | vorhanden | vorhanden | vorhanden (Delegation) | nur wenn die Verwaltungsschnittstelle Absenderrechte kennt | abhängig |
| `senden_im_auftrag` | vorhanden | vorhanden | **verlustbehaftet**: abweichende Kopfzeilensemantik | **nicht abbildbar** | abhängig |
| Ordnerweise Rechte | vorhanden | vorhanden | abweichendes Modell | nicht abbildbar | abhängig |
| Verteiler ohne Ablage | vorhanden | vorhanden | vorhanden | nur als Alias oder Weiterleitung | vorhanden |
| Mitgliedschaft aus Atrium gesteuert | ja | ja | ja | nur mit Verwaltungsschnittstelle | ja |

Die drei mit **verlustbehaftet** und **nicht abbildbar** markierten Felder sind der ehrliche Kern dieses Abschnitts. Der Fall "generisches IMAP ohne Objektbegriff für ein geteiltes Postfach" ist nicht nur ungenau, sondern sicherheitsmindernd: ein gemeinsames Konto mit gemeinsamen Zugangsdaten macht jede Handlung unzuordenbar und verstößt gegen die Nachweisführung, die [Kapitel 19](19-mandanten-rechte-audit.md) verlangt. Der Entwurf zieht daraus eine harte Regel: Ein Konnektormanifest deklariert je Merkmal `vollstaendig`, `verlustbehaftet` oder `nicht_abbildbar`; jede Abbildung, die nicht `vollstaendig` ist, erscheint in der Wirkungsvorschau als eigene Zeile mit Klartextfolge, und `nicht_abbildbar` verhindert die Ausführung, statt sie stillschweigend zu verkleinern. Eine Abstufung ohne Anzeige wäre ein stiller Rechteverlust oder ein stiller Rechtezuwachs, und beides ist schlimmer als eine abgelehnte Handlung.

Die tatsächliche Semantik je Anbieter ist zum Entwurfszeitpunkt nicht erhoben. Dieser Abschnitt legt fest, wie die Erhebung abzubilden ist, und nicht, wie sie ausfällt.

## 14.6 Domänennachweis und Zustellsicherheit

### Erzeugte Einträge

Eine Maildomäne erzeugt ausschließlich abgeleitete DNS-Einträge (INV-09). Sie sind in der Konsole sichtbar, tragen den Verweis auf die Maildomäne und sind dort nicht editierbar. Die Erzeugung, Signierung und Sichtzuordnung der Einträge folgt den Regeln aus [Kapitel 12](12-dns-netzwerk.md); dieses Kapitel legt nur fest, welche Einträge eine Maildomäne erzeugt und wann sie sich ändern.

| Eintrag | Standard | Quelle des Inhalts | Sicht |
|---|---|---|---|
| MX | RFC 5321 | Anbieterbindung der Maildomäne | extern |
| SPF (TXT) | RFC 7208 | Sendewege der Maildomäne, berechnet | extern |
| DKIM (TXT je Selektor) | RFC 6376 | öffentlicher Teil der Schlüssel aus der Schlüsselverwaltung | extern |
| DMARC (TXT) | RFC 7489 | Richtlinienstufe der Maildomäne und Berichtsadresse | extern |
| MTA-STS (TXT mit Kennung) | RFC 8461 | Version der veröffentlichten Richtlinie | extern |
| TLS-RPT (TXT) | RFC 8460 | Berichtsadresse | extern |
| TLSA | RFC 7672 | nur bei eigenem Postfachdienst, siehe unten | extern |

Der SPF-Eintrag wird beim Planen gegen die Nachschlagegrenze von zehn DNS-Auflösungen aus RFC 7208 geprüft. Überschreitet die berechnete Aussage die Grenze, wird sie nicht veröffentlicht; die Konsole nennt die Zahl der verursachenden Sendewege und den überschreitenden Weg. Das naheliegende Gegenmittel, die eingebetteten Verweise zu Adresslisten aufzulösen, wird nicht automatisch angewandt: eine aufgelöste Liste veraltet stillschweigend, sobald der Anbieter seine Absenderadressen ändert, und erzeugt dann Zustellausfälle ohne erkennbare Ursache. Der Entwurf zieht die sichtbare Verweigerung der stillen Fehlerquelle vor.

### DKIM-Schlüsselwechsel

Rechnung mit offengelegten Annahmen. Externe TTL 3600 s (K-17). Ablauf: neuen Selektor veröffentlichen, warten, Signatur umstellen, warten, alten Selektor entfernen.

```
t0            neuen Selektor s(n+1) veroeffentlichen
t0 + 2*TTL    = t0 + 7.200 s = 2 h   Signatur auf s(n+1) umstellen
              (2*TTL, damit auch ein unmittelbar vor t0 gefuellter Zwischenspeicher abgelaufen ist)
t0 + 2 h + W  alten Selektor s(n) entfernen
              W = maximale plausible Pruefverzoegerung
              Annahme: Warteschlangenhaltung bei Empfaengern 5 d + Berichtserzeugung 1 d = 6 d
Gesamtdauer   6 d 2 h
```

Bei einem Wechselintervall von 180 d (Zielwert) sind zwei Wechsel je Maildomäne und Jahr fällig. Der Anteil der Zeit mit zwei veröffentlichten Selektoren beträgt (6 d 2 h) / 180 d = 3,4 %. Bei 20 Maildomänen fallen 40 Wechsel im Jahr an, also im Mittel alle 9,1 Tage einer. Deutung: Ein Wechsel, den ein Mensch ausführt, findet bei dieser Frequenz entweder nicht statt oder nicht vollständig; die Automatisierung ist keine Bequemlichkeit, sondern die Bedingung dafür, dass das Intervall überhaupt gehalten wird. Das ist ein Modell, keine Messung.

Atrium signiert mit zwei Selektoren gleichzeitig, einem mit RSA und einem mit Ed25519 (RFC 8032). Annahme: Ed25519-Signaturen werden nicht flächendeckend geprüft. RFC 6376 lässt mehrere Signaturen je Nachricht zu, und eine nicht geprüfte Signatur schadet nicht. Der Preis sind vier statt zwei veröffentlichte Selektoren im Wechselfenster und eine zweite Schlüsselreihe in der Schlüsselverwaltung.

Private DKIM-Schlüssel werden auf dem Knoten erzeugt, der sie benutzt, und verlassen ihn nicht (INV-20). Bei fremdgehosteten Postfächern signiert der Anbieter, nicht Atrium; dann besitzt Atrium den Schlüssel nicht und führt den DKIM-Eintrag als abgeleitetes Artefakt mit der Quelle "Anbieter". Der Wechsel liegt dann beim Anbieter, und Atrium kann ihn nur beobachten. Diese Asymmetrie ist eine Grenze des Entwurfs und wird in der Konsole je Maildomäne benannt.

### Einführungsautomat für DMARC

| Stufe | Veröffentlichte Richtlinie | Mindestbeobachtung | Freigabekriterium für die nächste Stufe |
|---|---|---|---|
| 0 | keine | — | Maildomäne aktiv, Berichtsadresse erreichbar, mindestens ein vollständiger Berichtstag eingegangen |
| 1 | keine Maßnahme, Berichte an | 14 d | Anteil ausgerichtet bestandener Prüfungen ≥ 99,0 % über 14 d **und** Anteil Nachrichten aus nicht zugeordneten Quellen ≤ 0,1 % über die letzten 7 d **und** 0 offene Quellen ohne Zuordnungsentscheidung |
| 2a | Quarantäne, 25 % | 7 d | keine Verschlechterung gegenüber Stufe 1 um mehr als 0,2 Prozentpunkte |
| 2b | Quarantäne, 50 % | 7 d | wie 2a |
| 2c | Quarantäne, 100 % | 14 d | Anteil bestanden ≥ 99,5 % über 14 d |
| 3 | Zurückweisung | dauerhaft | — |

Rückwärtsschaltung: Fällt der Anteil bestandener Prüfungen an drei aufeinanderfolgenden Berichtstagen unter den Schwellenwert der aktuellen Stufe, schaltet der Automat eine Stufe zurück, erzeugt einen Vorgang mit Begründung und benennt die verursachende Quelle. Die Rücknahme ist automatisch, das Wiederaufsteigen nicht: die Stufe steigt erst nach erneuter voller Beobachtungsdauer.

Volumenabhängigkeit, ehrlich gerechnet. Annahme: eine Maildomäne versendet 50.000 Nachrichten im Monat; Annahme: 70 % davon werden von berichtenden Empfängern erfasst, also 35.000 im Monat oder 1.167 am Tag. Bei einem Schwellenwert von 99,5 % entsprechen 0,5 % rund 6 nicht bestandene Nachrichten am Tag, über 14 d rund 82 Nachrichten, die einzeln zuzuordnen sind. Das ist handhabbar. Bei einer kleinen Maildomäne mit 50 erfassten Nachrichten am Tag entspricht eine einzige nicht bestandene Nachricht 2 % und blockiert jeden Aufstieg; umgekehrt ist ein Anteil von 100 % über 14 d bei 50 Nachrichten am Tag statistisch schwach, weil eine selten benutzte legitime Quelle im Fenster gar nicht auftaucht. Der Automat trägt deshalb eine Volumenbedingung: unterhalb von 500 erfassten Nachrichten im Beobachtungsfenster steigt er nicht selbsttätig auf, sondern legt die Entscheidung mit der vollständigen Quellenliste einem Menschen vor. Die verworfene Alternative, denselben Prozentsatz unabhängig vom Volumen anzuwenden, erzeugt bei kleinen Domänen entweder Dauerblockade oder unbegründetes Vertrauen.

Die Grenze der Messung wird in der Konsole genannt: Berichte nach RFC 7489 liefern nur berichtende Empfänger, und der Anteil ist weder bekannt noch steuerbar. Der angezeigte Wert ist damit ein Anteil unter den berichteten Nachrichten, nicht unter allen. Die Konsole beschriftet ihn entsprechend.

### MTA-STS, TLS-RPT und DANE

MTA-STS nach RFC 8461 verlangt eine über HTTPS abrufbare Richtlinie unter einem festen Namen. Das ist in Atriums Modell eine gewöhnliche Veröffentlichung mit Zertifikat aus der ACME-Ausstellung; der TXT-Eintrag trägt die Kennung der Richtlinienversion und ändert sich mit ihr. Die Einführung erfolgt zweistufig: zuerst der Prüfmodus, in dem Empfänger Verstöße melden, aber nicht ablehnen, danach der erzwingende Modus. Freigabekriterium für die zweite Stufe: über 14 d 0 gemeldete Verbindungsfehler in den Berichten nach RFC 8460 gegen die eigenen Empfangsserver.

DANE nach RFC 7672 setzt DNSSEC voraus. Atrium signiert seine Zonen (KANON 4.8), erfüllt die Voraussetzung also für eigene Namen. Für einen eigenen Postfachdienst veröffentlicht Atrium TLSA-Einträge und koppelt sie an die Zertifikatserneuerung: der Eintrag für das Nachfolgezertifikat wird vor dem Wechsel veröffentlicht, der alte erst nach Ablauf entfernt, analog zum DKIM-Wechsel. Für fremdgehostete Postfächer veröffentlicht Atrium **keine** TLSA-Einträge für den MX des Anbieters. Der Grund ist zwingend: ein TLSA-Eintrag bindet das Zertifikat eines Servers, den Atrium nicht kontrolliert und dessen Zertifikatswechsel es nicht erfährt; ein Wechsel ohne vorherige Eintragsanpassung führt zur vollständigen Zustellablehnung. Die Konsole zeigt für solche Maildomänen "DANE: nicht anwendbar, Anbieter kontrolliert das Zertifikat" statt eines abschaltbaren Schalters.

## 14.7 Umzug bestehender Postfächer

### Verfahren

```
1  Ziel vorbereiten: Maildomaene beim Zielanbieter eingerichtet und verifiziert,
   Postfaecher und Adressen angelegt, Adressen noch nicht im MX wirksam
2  Erstsynchronisation: vollstaendige Kopie Quelle -> Ziel, Postfach fuer Postfach,
   parallelisiert; Quelle bleibt produktiv
3  Deltalaeufe: wiederholte inkrementelle Laeufe bis zum Umschaltzeitpunkt
4  Vorbereitung des Umschaltens: MX-TTL absenken (siehe Rechnung)
5  Umschalten: MX auf das Ziel, Einlieferung der Nutzer auf das Ziel
6  Nachlaufdelta: ein letzter Lauf ueber alle Postfaecher
7  Quelle auf lesend setzen, Weiterleitung Quelle -> Ziel aktivieren
8  Abschluss nach Aufbewahrungsfrist: Quelle abbauen
```

### Zeitrechnung

Annahmen: 200 Postfächer, Durchschnittsgröße 25 GB, Gesamtvolumen 5.000 GB = 5.120.000 MB; 40.000 Nachrichten je Postfach, gesamt 8.000.000 Nachrichten; effektiver Durchsatz 5 MB/s je Verbindung; 8 gleichzeitige Verbindungen, weil Anbieter die Parallelität begrenzen; Bearbeitungszeit 40 ms je Nachricht und Verbindung.

```
Volumengrenze:      5.120.000 MB / (8 * 5 MB/s) = 5.120.000 / 40 = 128.000 s = 35,6 h
Nachrichtengrenze:  8.000.000 / (8 / 0,040 s) = 8.000.000 / 200 = 40.000 s = 11,1 h
Bindend:            max(35,6 h; 11,1 h) = 35,6 h
```

Deutung: Die Erstsynchronisation passt in ein Wochenende (Zielwert ≤ 48 h). Die Zahl kippt mit der Parallelität, die der Anbieter zulässt: bei 2 statt 8 Verbindungen ergibt die Volumengrenze 5.120.000 / 10 = 512.000 s = 142 h = 5,9 d, und der Umzug wird zu einem mehrwöchigen Vorhaben mit vielen Deltaläufen. Die Parallelität ist damit die einzige Größe, die vor der Planung erhoben werden muss; sie ist anbieterseitig gesetzt und nicht verhandelbar. Deltalauf: Annahme 1 % Änderung je Tag = 51.200 MB / 40 MB/s = 1.280 s = 21 min. Sämtlich Annahmen, keine Messungen.

### Wiederaufnahme nach Abbruch

Der Umzug ist ein Vorgang mit Fortschrittsmarke je Postfach. Die Marke liegt im knotenlokalen Fortschrittsbereich und wird nur verdichtet repliziert (Zähler je Postfach, ≤ 1 KB), damit das Änderungsprotokoll nicht geflutet wird und K-12 hält. Bei Ausfall des ausführenden Knotens nimmt ein anderer Knoten den Vorgang ab der letzten replizierten Marke auf. Daraus folgt die Schwäche offen: die Marke kann um bis zu einen Stapel veraltet sein, und der wiederaufgenommene Lauf würde Nachrichten doppelt schreiben. Der Entwurf begegnet dem mit einer Ziel-seitigen Prüfung je Nachricht über Nachrichtenkennung nach RFC 5322 und einen Hashwert über die normalisierten Kopfzeilen und den Rumpf; eine Nachricht, die dort bereits vorliegt, wird übersprungen. Nachrichten ohne Nachrichtenkennung — es gibt sie — werden ausschließlich über den Hashwert erkannt, und zwei identische Nachrichten in einem Postfach sind dann nicht unterscheidbar. Der Entwurf löst diesen Fall nicht; er zählt ihn und weist ihn im Abschlussbericht des Umzugs aus.

### Umschaltfenster

```
Externe TTL im Normalbetrieb:  3.600 s (K-17)
t -24 h   MX-TTL auf 300 s senken
          Wirksam wird die Senkung erst nach Ablauf der alten TTL, also nach <= 3.600 s;
          24 h Vorlauf enthalten Reserve fuer Empfaenger mit eigenwilliger Zwischenspeicherung
t 0       MX auf das Ziel umstellen
t +300 s  Aufloesung konvergiert bei regelkonformen Empfaengern
t +2 h    Nachlaufdelta abgeschlossen
t +7 d    Weiterleitung Quelle -> Ziel bleibt aktiv (Empfaenger mit ueberlanger Speicherung)
t +30 d   Quelle lesend, danach Abbau (Aufbewahrungsfrist K-25)
t +24 h   MX-TTL zurueck auf 3.600 s
```

### Rückfallplan

Bis t+7 d ist der Rückfall die Rückstellung des MX auf die Quelle; die Quelle ist bis dahin unverändert vorhanden und empfängt wieder. Der Preis ist benannt und nicht wegzurechnen: Nachrichten, die zwischen Umschalten und Rückfall im Ziel angekommen sind, liegen dort und nicht in der Quelle. Der Rückfall erzeugt damit eine geteilte Historie, die anschließend durch einen Rücksynchronisationslauf Ziel → Quelle zusammengeführt werden muss. Die Konsole benennt das vor der Auslösung des Rückfalls im Klartext mit der Zahl der betroffenen Nachrichten aus der Beobachtung. Nach t+7 d ist ein Rückfall kein Rückfall mehr, sondern ein erneuter Umzug in die Gegenrichtung; die Konsole bezeichnet ihn auch so.

## 14.8 Archivierung, Aufbewahrung, rechtlicher Zugriff, Ausscheiden

Atrium erbringt keine Archivierung. Es führt am Postfach, wo die Archivierung stattfindet, und mit welcher Frist. Drei Fälle sind zu unterscheiden.

| Fall | Ort der Archivierung | Was Atrium leistet | Was Atrium nicht leistet |
|---|---|---|---|
| Anbieterseitige Aufbewahrung | Fremdsystem | Setzen und Beobachten der Aufbewahrungsmerkmale, soweit das Manifest sie führt | Keine eigene Kopie, keine Prüfung der Unveränderlichkeit |
| Archivdienst als Katalogeintrag | eigener Dienst mit Speicherbereich | Einrichtung des Journalempfängers als Zuweisung, Speicherbereich mit Datensicherheitsstufe, Sicherungsplan | Keine Aussage über die revisionssichere Eignung des Fremdprodukts |
| Keine Archivierung | — | dauerhafte Kennzeichnung am Postfach (INV-18) | — |

Eine Aufbewahrungspflicht wird am Postfach als Merkmal gesetzt. Innerhalb von Atrium wirkt sie hart: das Postfach ist nicht löschbar, und die Löschhandlung existiert für dieses Objekt nicht, solange das Merkmal gesetzt ist (INV-11). Nach außen wirkt sie nur so weit, wie der Konnektor sie durchsetzen kann. Deklariert ein Manifest keine Aufbewahrungsfähigkeit, zeigt die Konsole "dokumentiert, im Zielsystem nicht durchsetzbar" und nicht etwa einen erfüllten Zustand. Eine Zusicherung, die die Technik nicht einlöst, ist eine Falschaussage in der Oberfläche.

Ausscheiden einer Person, als Vorgang mit Wirkungsvorschau:

```
1  Person -> Zustand "ausgeschieden": Anmeldung ueberall aus, Daten bleiben
2  Postfach -> delegiert: benannte Person erhaelt "lesen", befristet (Vorgabe 90 d,
   Richtlinie), keine Sendeberechtigung
3  Primaeradresse -> umgeleitet auf die benannte Person, Abwesenheitshinweis gesetzt
4  Mitgliedschaften in Verteilern -> entfernt, einzeln aufgefuehrt
5  Export erzeugt: eine signierte Datei je Postfach, Nachrichten unveraendert
   einschliesslich aller Kopfzeilen nach RFC 5322, dazu ein Inhaltsverzeichnis mit
   Hashwert je Nachricht und eine Gesamtsignatur (Ed25519, RFC 8032)
6  Nach Fristende: Postfach -> archiviert
7  Adresse -> entfernt und fuer 12 Monate gesperrt (K-25)
8  Loeschung: eigener, ausdruecklich als nicht ruecknehmbar gekennzeichneter Vorgang
```

Der Export ist das Objekt, an dem Auskunfts- und Herausgabeverlangen erfüllt werden. Er ist ein Auszug aus dem Fremdsystem, kein Atrium-Datenbestand; entsprechend hängt seine Vollständigkeit von der Leserechtreichweite der Konnektorbindung ab, und der Bericht nennt, welche Ordner nicht gelesen werden konnten. Ein rechtlicher Zugriff auf ein Postfach ist ein freigabepflichtiger Vorgang mit Vier-Augen-Prinzip und einem nicht unterdrückbaren Auditereignis; er erzeugt keine stille Berechtigung, sondern eine befristete, sichtbare Delegation. Der Konflikt zwischen einem Löschverlangen nach DSGVO und einer Aufbewahrungspflicht wird nicht technisch entschieden: Atrium zeigt beide Merkmale am Objekt, verweigert die Löschung und legt den Fall als Entscheidung vor. [Kapitel 22](22-compliance.md) führt die Abwägung.

## 14.9 Betriebssicht

Die entscheidende Aussage dieses Abschnitts ist eine Verzichtserklärung: Liegt das Postfach fremd, sieht Atrium von der Zustellung fast nichts. Die folgende Tabelle trennt, was beobachtbar ist, von dem, was es nicht ist.

| Beobachtungsgegenstand | Eigener Postfachdienst | Exchange vor Ort | Microsoft 365, Google Workspace | Generisches IMAP/SMTP |
|---|---|---|---|---|
| Länge der Ausgangswarteschlange | ja | ja, wenn die Schnittstelle sie führt | nein | nein |
| Alter der ältesten wartenden Nachricht | ja | wie oben | nein | nein |
| Zustellfehler je Nachricht | ja | wie oben | nein | nein |
| Rückläuferquote | ja | wie oben | nein | nein |
| TLS-Fehler beim Versand | ja | wie oben | nein, nur indirekt über Berichte fremder Empfänger | nein |
| DMARC-Ergebnisse fremder Empfänger | ja (Berichte, RFC 7489) | ja | ja | ja |
| TLS-Ergebnisse fremder Empfänger | ja (Berichte, RFC 8460) | ja | ja | ja |
| Kontingent- und Lizenzstand | entfällt | teilweise | ja | selten |
| Erreichbarkeit und Rechtelage der Bindung | ja | ja | ja | ja |

Der Grund für die Nein-Spalten ist kein Konnektormangel: Anbieter veröffentlichen ihren internen Warteschlangenzustand nicht, und eine Zustellauskunft je Nachricht würde eine Nachrichtenkennung voraussetzen, die Atrium nicht besitzt, weil es die Nachricht nicht gesehen hat. Der Entwurf zeigt an dieser Stelle keine ersatzweise berechneten Werte. Eine "Zustellrate", die aus Berichten fremder Empfänger hochgerechnet wird, wäre eine erfundene Zahl.

Reputation wird nicht als Punktwert dargestellt. Es existiert kein quelloffener, nachprüfbarer Reputationswert, den Atrium berechnen könnte, und die Wiedergabe eines fremden Werts wäre eine Zahl ohne Herleitung. Stattdessen zeigt die Konsole drei herleitbare Größen je Maildomäne: den Anteil bestandener ausgerichteter Prüfungen unter den berichteten Nachrichten, die Zahl der in TLS-Berichten gemeldeten fehlgeschlagenen Verbindungsversuche, und die Zahl der Sendequellen ohne Zuordnung. Jede der drei Größen trägt ihren Beobachtungszeitpunkt (INV-28).

Alarmierung, als Zielwerte:

| Auslöser | Schwelle (Zielwert) | Wirkung |
|---|---|---|
| Anteil bestandener Prüfungen unter dem Stufenschwellenwert | 3 aufeinanderfolgende Berichtstage | Rückstufung der DMARC-Stufe als Vorgang, Meldung im Überblick |
| Neue Sendequelle ohne Zuordnung | ≥ 1 im Berichtszeitraum | Aufgabe "Quelle zuordnen oder ablehnen", verlinkt auf die Maildomäne |
| Fehlgeschlagene TLS-Verbindungen in Berichten | > 0 an 2 aufeinanderfolgenden Tagen für dieselbe Gegenstelle | Meldung mit Gegenstelle und Fehlerart |
| DKIM-Schlüsselalter | > 180 d | Aufgabe "Schlüssel wechseln"; der Wechsel selbst läuft automatisch |
| Restlaufzeit des Zertifikats der MTA-STS-Veröffentlichung | ≤ 15 d, Eskalation ≤ 7 d | wie jede Zertifikatswarnung (K-13) |
| Konnektorbindung gestört | > 15 min | Sperre der Mailhandlungen an den betroffenen Maildomänen mit Klartextgrund |
| Ausgangswarteschlange, nur eigener Dienst | > 100 Nachrichten oder älteste > 30 min | Meldung am Dienst mit Verweis auf die Warteschlange |

Alle Konnektoraufrufe dieses Kapitels laufen unter den Bedingungen aus [Kapitel 09](09-konnektoren.md): eigener Systembenutzer, eigener Netznamensraum, Ausgangs-Positivliste aus der Bindung (INV-21), Geheimnisse nur als kurzlebige auftragsgebundene Referenz (INV-20). Eingaben aus der Oberfläche werden am Rand gegen ein Schema validiert; der lokale Teil einer Adresse wird gegen eine Positivliste zulässiger Zeichen geprüft, nie gegen eine Ausschlussliste, und nie in eine Abfrage oder einen Kommandoaufruf interpoliert. Der Vergleich von Bestätigungsgeheimnissen des Nachweisablaufs erfolgt laufzeitkonstant. Fehlermeldungen nennen den betroffenen Objekttyp und die Handlung, nie den Fremdtext und nie die Existenz oder Nichtexistenz eines Kontos gegenüber einem unauthentisierten Aufrufer.

## Anforderungen

| ID | Anforderung | Folgt aus |
|---|---|---|
| R-14-01 | Mailadresse und Postfach sind getrennte Objekte. Das Entfernen einer Adresse löscht 0 Postfächer; das Archivieren eines Postfachs entfernt 0 Adressen ohne eigenen Vorgang. | INV-11, KANON 3 |
| R-14-02 | Jedes Postfach trägt genau einen Ablageort als Verweis auf eine Konnektorbindung. Ein Postfach ohne Ablageort ist von der API nicht anlegbar. | INV-19, KANON 3 |
| R-14-03 | Die Konsole enthält keinen Navigationsbereich für Mail. Adressen werden ausschließlich an einer Person oder an einer Gruppe hinzugefügt. | KANON 5 |
| R-14-04 | "Mail hinzufügen" kostet genau 2 Entscheidungen (Maildomäne, lokaler Teil), bei genau einer aktiven Maildomäne des Mandanten 1. Jedes weitere Feld ist vorbelegt und nennt seine Quelle. | INV-14, INV-15, K-03 |
| R-14-05 | Existiert für eine Maildomäne keine Richtlinie `lizenzprofil_mail`, ist die Handlung "Mail hinzufügen" an dieser Maildomäne gesperrt. Das Formular erhält kein zusätzliches Pflichtfeld. | INV-14, INV-15 |
| R-14-06 | Die Wirkungsvorschau eines Mailvorgangs nennt jede kostenwirksame Zuweisung mit dem Namen des Lizenzprofils und der Angabe, dass sie kostenwirksam ist; sie nennt keinen Preis. | INV-29, INV-08 |
| R-14-07 | `POST /v1/mailadressen:pruefen` verändert 0 Objekte in Atrium und 0 Objekte in Fremdsystemen und löst 0 `apply`-Aufrufe aus. | INV-08 |
| R-14-08 | Eine entfernte Mailadresse ist 12 Monate gesperrt und in diesem Zeitraum nicht neu vergebbar. Eine vorzeitige Freigabe ist ein eigener, freigabepflichtiger Vorgang mit Begründungspflicht. | K-25, INV-11 |
| R-14-09 | Jeder Fehlerfall des Mailablaufs zeigt eine in der Konsole ausführbare Folgehandlung. Kein Fehlertext enthält einen Befehl, einen Dateipfad oder einen unübersetzten Fremdtext. | INV-17, INV-16 |
| R-14-10 | Ein Mailvorgang über mehrere Zielsysteme erreicht den Zustand "abgeschlossen" nur, wenn jedes Zielsystem bestätigt hat; andernfalls gilt "teilweise fehlgeschlagen" mit benanntem Rest. | INV-12 |
| R-14-11 | Jedes Mailkonnektormanifest deklariert je Feld das Eigentum (`atrium`, `fremd`, `erstanlage`). Ein Manifest ohne vollständige Deklaration wird beim Import abgelehnt. | INV-13 |
| R-14-12 | Jedes Mailkonnektormanifest deklariert je Berechtigungsmerkmal (`lesen`, `senden_als`, `senden_im_auftrag`, Ordnerrechte, geteiltes Postfach, Verteiler) einen der Werte `vollstaendig`, `verlustbehaftet`, `nicht_abbildbar`. | INV-13, INV-30 |
| R-14-13 | Eine Berechtigung, deren Abbildung `verlustbehaftet` ist, erscheint in der Wirkungsvorschau als eigene Zeile mit Klartextfolge. Eine Berechtigung mit `nicht_abbildbar` wird nicht ausgeführt, sondern abgelehnt. | INV-08, INV-12 |
| R-14-14 | "Geteiltes Postfach anlegen" kostet genau 3 Entscheidungen (Anzeigename, Maildomäne, lokaler Teil). Die besitzende Gruppe wird abgeleitet; die Pflege der Berechtigten ist eine eigene Handlung und erscheint nach Abschluss als offene Aufgabe. | INV-14, K-03 |
| R-14-15 | Die Anbindung eines Anbieters erfordert genau einen Zustimmungsablauf je Mandant und Anbieter. Atrium speichert kein Administratorkennwort eines Fremdanbieters und bedient keine Fremdoberfläche automatisiert. | INV-20 |
| R-14-16 | Ist Atrium für eine Domäne autoritativ, erzeugt es den Nachweiseintrag des Anbieters selbst und wiederholt die Anbieterprüfung selbsttätig ohne Bedienerhandlung. Andernfalls zeigt es den zu setzenden Eintrag und den Ort, an dem er zu setzen ist. | INV-09, K-17 |
| R-14-17 | MX, SPF, DKIM, DMARC, MTA-STS, TLS-RPT und TLSA einer Maildomäne sind abgeleitete Artefakte mit Quellverweis. Die API kennt für sie keine Schreiboperation. | INV-09 |
| R-14-18 | Ein SPF-Eintrag mit mehr als 10 erforderlichen DNS-Auflösungen wird nicht veröffentlicht. Die Konsole nennt die Zahl der Sendewege und den überschreitenden Weg. Eine automatische Auflösung eingebetteter Verweise zu Adresslisten findet nicht statt. | RFC 7208, INV-18 |
| R-14-19 | Der DKIM-Schlüsselwechsel läuft ohne Bedienerhandlung: neuen Selektor veröffentlichen, nach 2 externen TTL umstellen, alten Selektor nach 6 d entfernen. Das Wechselintervall beträgt 180 d. | K-17, K-13 |
| R-14-20 | Private DKIM-Schlüssel entstehen auf dem Knoten, der sie benutzt, und verlassen ihn nicht. Es existiert keine API-Operation, die einen privaten DKIM-Schlüssel entgegennimmt oder ausgibt. | INV-20 |
| R-14-21 | Der DMARC-Einführungsautomat schaltet nur bei erfülltem Freigabekriterium und erfüllter Mindestbeobachtungsdauer eine Stufe höher und schaltet bei Unterschreitung an 3 aufeinanderfolgenden Berichtstagen selbsttätig eine Stufe zurück. | INV-18, INV-12 |
| R-14-22 | Unterhalb von 500 im Beobachtungsfenster erfassten Nachrichten steigt der Automat nicht selbsttätig auf, sondern legt die Entscheidung mit vollständiger Quellenliste vor. | INV-18 |
| R-14-23 | Die Konsole beschriftet den Anteil bestandener Prüfungen als Anteil unter den berichteten Nachrichten und nennt, dass der Anteil berichtender Empfänger unbekannt ist. | INV-16, INV-18 |
| R-14-24 | Für Maildomänen mit fremdgehostetem MX veröffentlicht Atrium 0 TLSA-Einträge. Die Konsole zeigt den Grund im Klartext statt eines Schalters. | INV-18 |
| R-14-25 | MTA-STS wird zweistufig eingeführt: Prüfmodus, dann erzwingender Modus nach 14 d mit 0 gemeldeten Verbindungsfehlern gegen die eigenen Empfangsserver. | RFC 8461, RFC 8460 |
| R-14-26 | Ein Umzug ist ein Vorgang mit Fortschrittsmarke je Postfach; die Marke wird ausschließlich verdichtet repliziert (≤ 1 KB je Postfach). Nach Ausfall des ausführenden Knotens setzt ein anderer Knoten ohne Bedienerhandlung fort. | INV-25, K-12 |
| R-14-27 | Der Umzug prüft je Nachricht zielseitig auf Vorhandensein über Nachrichtenkennung und Hashwert über normalisierte Kopfzeilen und Rumpf. Nachrichten ohne Nachrichtenkennung werden gezählt und im Abschlussbericht ausgewiesen. | INV-07 |
| R-14-28 | Vor einem Rückfall nach dem Umschalten nennt die Konsole die Zahl der bereits im Ziel eingegangenen Nachrichten und die Folge der geteilten Historie im Klartext. | INV-08, INV-12 |
| R-14-29 | Ein Postfach mit gesetzter Aufbewahrungspflicht ist in Atrium nicht löschbar. Deklariert das Manifest keine Aufbewahrungsfähigkeit, zeigt die Konsole "dokumentiert, im Zielsystem nicht durchsetzbar". | INV-11, INV-18, INV-30 |
| R-14-30 | Der Ausscheidevorgang erzeugt je Postfach einen signierten Export mit Inhaltsverzeichnis, Hashwert je Nachricht und Gesamtsignatur und weist nicht lesbare Ordner einzeln aus. | INV-12, INV-23 |
| R-14-31 | Ein rechtlicher Zugriff auf ein fremdes Postfach ist ein freigabepflichtiger Vorgang mit Vier-Augen-Prinzip, befristeter Delegation und nicht unterdrückbarem Auditereignis. | INV-23, KANON 5 |
| R-14-32 | Die Konsole zeigt für fremdgehostete Postfächer 0 berechnete Zustellraten, 0 Warteschlangenwerte und 0 Reputationspunktwerte. Angezeigt werden ausschließlich die drei herleitbaren Größen je Maildomäne, jeweils mit Beobachtungszeitpunkt. | INV-28, KANON 6 |
| R-14-33 | Der lokale Teil einer Adresse wird gegen eine Positivliste zulässiger Zeichen geprüft und niemals in eine Abfrage, einen Kommandoaufruf oder eine Schale interpoliert. Der Vergleich von Bestätigungsgeheimnissen läuft laufzeitkonstant. | INV-20, KANON 4 Sprachen |

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Das Entfernen einer von drei Adressen erzeugt 0 gelöschte Postfächer; das Archivieren eines Postfachs mit drei Adressen erzeugt 3 einzeln aufgeführte Adresswirkungen | R-14-01 | Lebenszyklustest über Postfach und Adressen |
| Ein Anlegeversuch eines Postfachs ohne Ablageort wird von der API abgelehnt | R-14-02 | Schemaprüfung und Mutationstest |
| Die Navigationsdefinition enthält 0 Bereiche mit Mailbezug; die Aufgabe "Mail hinzufügen" ist ausschließlich an Person und Gruppe erreichbar | R-14-03 | Abgleich des Oberflächenbaums gegen KANON 5 im Bau |
| Das Formular "Mail hinzufügen" enthält genau 2 Pflichtfelder, bei einer einzigen aktiven Maildomäne genau 1; jedes vorbelegte Feld nennt seine Quelle | R-14-04 | Abgleich der Formulardefinition gegen die Aufgabendefinition (INV-14) |
| Bei fehlender Richtlinie `lizenzprofil_mail` ist die Handlung gesperrt und das Formular unverändert zweifeldrig | R-14-05 | Richtlinienraumdurchlauf mit und ohne gesetzte Richtlinie |
| Die Wirkungsvorschau einer lizenzpflichtigen Mailzuweisung enthält 1 Kostenzeile mit Profilnamen und 0 Preisangaben | R-14-06 | Vorschautest gegen eine Bindung mit deklarierter Kostenwirkung |
| Nach 1.000 Prüfaufrufen ist der Istzustand des Fremdsystems unverändert und die Zahl der `apply`-Aufrufe 0 | R-14-07 | Konnektor-Vertragstest mit Istzustandsvergleich (INV-08) |
| Eine in den letzten 12 Monaten entfernte Adresse wird bei erneuter Anlage abgelehnt; die Ablehnung nennt das Fristende | R-14-08 | Zeitraffertest über die Sperrfrist |
| Über alle Mailfehlermeldungen: 0 Treffer auf Befehls- und Dateipfadmuster, 0 unübersetzte Fremdtexte, 100 % mit ausführbarer Folgehandlung | R-14-09 | Musterprüfung aller Meldungstexte im Bau (K-27) |
| Bei Fehlerinjektion in eines von zwei Zielsystemen meldet der Vorgang "teilweise fehlgeschlagen" und nennt das ausstehende System | R-14-10 | Fehlerinjektion in eine von zwei Bindungen |
| Ein Mailmanifest ohne vollständige Eigentums- oder Merkmalsdeklaration wird beim Import abgelehnt | R-14-11, R-14-12 | Importtest mit fünf unvollständigen Manifesten |
| Eine `verlustbehaftet` deklarierte Berechtigung erscheint in 100 % der Vorschauen als eigene Zeile; eine `nicht_abbildbar` deklarierte erzeugt 0 Wirkungen | R-14-13 | Vorschau- und Ausführungstest gegen ein Manifest mit beiden Werten |
| Das Formular "Geteiltes Postfach anlegen" enthält genau 3 Pflichtfelder; nach Abschluss steht genau 1 offene Aufgabe "Berechtigte pflegen" | R-14-14 | Abgleich gegen die Aufgabendefinition plus Abschlussprüfung |
| Der Geheimnisspeicher enthält 0 Einträge vom Typ Administratorkennwort eines Fremdanbieters; das Anbindungsverfahren enthält 0 Oberflächenautomatisierungen | R-14-15 | Typprüfung des Geheimnisspeichers und Quelltextprüfung der Konnektoren |
| Für eine bei Atrium autoritative Domäne erfolgt der Anbieternachweis mit 0 Bedienerhandlungen; für eine fremd delegierte Domäne zeigt die Konsole den Eintrag und den Ort | R-14-16 | Nachweislauf in beiden Konstellationen |
| Die API kennt für MX, SPF, DKIM, DMARC, MTA-STS, TLS-RPT und TLSA 0 Schreiboperationen | R-14-17 | Fassadenbau gegen die öffentliche API (INV-01) |
| Ein konstruierter Sendewegsatz mit 11 erforderlichen Auflösungen wird nicht veröffentlicht; die Meldung nennt den überschreitenden Weg | R-14-18 | Grenzwerttest der SPF-Berechnung |
| Über einen simulierten Wechselzyklus sind alte und neue Selektoren im Fenster gleichzeitig auflösbar; nach 6 d ist der alte entfernt; Bedienerhandlungen: 0 | R-14-19 | Zeitraffertest des Wechsels mit DNS-Beobachtung |
| Die Endpunktliste enthält 0 Operationen, die einen privaten DKIM-Schlüssel entgegennehmen oder ausgeben | R-14-20 | Fassadenbau und Ausgabeprüfung gegen Geheimnismuster |
| Bei eingespielten Berichtsreihen steigt der Automat nur bei erfülltem Kriterium und schaltet bei 3 Tagen Unterschreitung zurück; falsche Aufstiege: 0 | R-14-21 | Wiedergabe von 12 konstruierten Berichtsreihen |
| Bei einer Reihe mit 300 erfassten Nachrichten im Fenster steigt der Automat 0-mal selbsttätig auf und legt 1 Entscheidung vor | R-14-22 | Kleinvolumenreihe im selben Prüfstand |
| Die Konsolentexte zur Zustellgüte enthalten 100 % Beschriftungen mit Bezug auf berichtete Nachrichten und 0 unqualifizierte Anteilsangaben | R-14-23 | Musterprüfung der Oberflächentexte |
| Für 5 Maildomänen mit fremdem MX sind 0 TLSA-Einträge veröffentlicht und 5 Klartextbegründungen sichtbar | R-14-24 | Zonenauszug plus Darstellungsprüfung |
| Der erzwingende MTA-STS-Modus wird bei 1 gemeldetem Verbindungsfehler im Fenster nicht erreicht | R-14-25 | Berichtseinspielung mit injiziertem Fehler |
| Nach Abbruch des ausführenden Knotens bei 60 % Fortschritt setzt ein anderer Knoten ohne Bedienerhandlung fort; doppelt geschriebene Nachrichten: 0 bei vorhandener Nachrichtenkennung | R-14-26, R-14-27 | Umzugstest mit injiziertem Knotenausfall über 200 Postfächer |
| Ein Umzug mit 100 Nachrichten ohne Nachrichtenkennung weist im Abschlussbericht die Zahl 100 aus | R-14-27 | Umzug eines präparierten Postfachs |
| Die Rückfallbestätigung nennt die Zahl der im Ziel eingegangenen Nachrichten; ohne diese Zahl ist der Rückfall nicht auslösbar | R-14-28 | Rückfalltest 4 h nach dem Umschalten |
| Ein Löschversuch an einem Postfach mit Aufbewahrungspflicht erzeugt 0 Wirkungen; bei einem Manifest ohne Aufbewahrungsfähigkeit zeigt die Konsole den Hinweistext | R-14-29 | Löschversuch in beiden Konstellationen |
| Der Export eines Postfachs mit einem nicht lesbaren Ordner enthält diesen Ordner einzeln benannt und gilt als unvollständig | R-14-30 | Export gegen ein Postfach mit entzogenem Ordnerrecht |
| Ein Zugriffsvorgang ohne zweite Freigabe erzeugt 0 Delegationen und 1 Auditereignis vom Typ "abgelehnt" | R-14-31 | Freigabetest |
| Die Mailansichten einer fremdgehosteten Maildomäne enthalten 0 Warteschlangenwerte, 0 Zustellraten, 0 Punktwerte und 3 datierte Größen | R-14-32 | Darstellungsprüfung gegen eine Anbieterbindung |
| Fuzzing des Feldes "lokaler Teil" erzeugt 0 Zustandsänderungen und 0 Kommandoaufrufe; die Antwortzeit des Geheimnisvergleichs ist unabhängig vom Grad der Übereinstimmung | R-14-33 | Fuzzing plus Zeitmessung über 10.000 Läufe |

## Offene Punkte

1. **Es gibt keinen festgelegten eigenen Postfachdienst.** KANON 4 trifft für Mail keine Technologieentscheidung. Dieses Kapitel beschreibt die Anforderungen an einen Katalogeintrag "eigener Postfachdienst" — vollständiges Feldeigentum, Warteschlangenbeobachtung, DKIM-Signatur auf dem Knoten, DANE-fähige Zertifikatskopplung —, benennt aber kein Produkt. Ohne diese Entscheidung bleibt die gesamte linke Spalte der Betriebssicht eine Zusage ohne Träger. Die Entscheidung gehört in KANON 4 und ist vor der Umsetzung dieses Kapitels zu treffen, weil sie bestimmt, ob Atrium überhaupt jemals Transport sieht.
2. **Die tatsächliche Semantik geteilter Postfächer je Anbieter ist nicht erhoben.** Die Abbildungstabelle in 14.5 legt fest, wie eine Abweichung zu deklarieren und anzuzeigen ist, nicht welche Abweichungen bestehen. Insbesondere ist offen, ob das Merkmal `senden_im_auftrag` bei Google Workspace und bei Exchange vor Ort dieselbe Kopfzeilenwirkung hat; wenn nicht, sendet dieselbe Zuweisung je nach Backend unterschiedlich sichtbare Nachrichten. Die Erhebung ist Voraussetzung für die Manifeste und kann nicht aus der Dokumentation allein erfolgen, weil sie das beobachtbare Verhalten betrifft.
3. **Der Zugriff auf Postfachinhalte ist beim Umzug ein Kontrollproblem.** Ein Umzug erfordert Lesezugriff auf sämtliche Nachrichten aller betroffenen Personen. Das ist der weitreichendste Zugriff, den Atrium jemals ausübt, und er ist mit dem Modell "Atrium führt keine Nachrichteninhalte" nur vereinbar, solange die Daten den Umzugsprozess nur durchlaufen. Ob dieser Durchlauf eine eigene Freigabestufe, eine eigene Netzzone, eine eigene Speicherbereichsklasse für Zwischenpuffer und eine eigene Aufbewahrungsregel für Fehlerfälle braucht, ist nicht entschieden. Ohne Entscheidung entsteht ein Prozess mit Vollzugriff und ohne eigene Schranke.
4. **Der DMARC-Automat entscheidet auf unvollständiger Datengrundlage.** Der Anteil berichtender Empfänger ist unbekannt und schwankt mit der Empfängerpopulation. Damit ist jeder Schwellenwert ein Schwellenwert auf einer Stichprobe unbekannter Verzerrung; eine Domäne kann 100 % bestandene Prüfungen unter den berichteten Nachrichten zeigen und trotzdem eine nicht berichtende Empfängergruppe systematisch verlieren. Ob der Automat bei fehlender Stichprobenbreite überhaupt bis zur Zurückweisung führen darf oder bei Quarantäne stehen bleiben muss, ist offen und nur mit Betriebserfahrung entscheidbar.
5. **Die Abhängigkeit von Zustimmungen ist ein dauerhafter Betriebszustand, kein Einrichtungsschritt.** Eine widerrufene oder abgelaufene Anwendungszustimmung legt sämtliche Mailhandlungen eines Mandanten still, und der Widerruf kann im Fremdsystem ohne Kenntnis von Atrium erfolgen. Der Entwurf erkennt das über `healthcheck`, aber erst nach bis zu 300 s, und er kann es nicht verhindern. Ob Atrium eine zweite, unabhängige Bindung je Mandant unterhalten soll, um einen Einzelwiderruf zu überstehen, und wie das mit "ein Prozess je Mandant und Bindung" zusammengeht, ist nicht entschieden.
6. **JMAP ist im Kanon nicht abgebildet.** Die Portmatrix in KANON 7 kennt IMAP und SMTP, nicht aber einen Zugangsweg nach RFC 8620 und RFC 8621. Ein eigener Postfachdienst mit JMAP-Zugang würde über eine Veröffentlichung auf 443/tcp laufen und wäre damit formal abgedeckt, aber die Matrix nennt ihn nicht, und die Konnektoreinstufung eines JMAP-fähigen Fremdsystems ist ungeklärt. Das ist eine Lücke im Kanon, keine Entwurfsentscheidung dieses Kapitels.
7. **Für Postfachsysteme ohne Verwaltungsschnittstelle bleibt Atrium eine Buchhaltung.** In dieser Konstellation besitzt Atrium kein einziges Feld im Zielsystem; die Adressliste in der Konsole beschreibt dann eine Wirklichkeit, die jemand anders herstellt, und jede Abweichung ist nur bemerkbar, wenn der Konnektor wenigstens lesen darf. Ob solche Bindungen überhaupt angeboten werden sollen — mit der Gefahr, dass die Konsole Zustände zeigt, die sie nicht verantworten kann — oder ob sie als "nicht verwaltbar" abzulehnen sind, ist eine Produktentscheidung, die dieses Kapitel nicht trifft.
