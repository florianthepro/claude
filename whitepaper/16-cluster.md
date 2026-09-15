# 16 Mehrknotenbetrieb: Kopplung, Rollen, Redundanz

## 16.1 Was der zweite Knoten ändert und was nicht

Der Ein-Knoten-Betrieb ist eine Konsensgruppe mit einem Stimmmitglied und kein Sondermodus (KANON 4.1). Daraus folgt unmittelbar, dass der Übergang zum Verbund keine Umkonfiguration, keine Datenmigration und keinen zweiten Betriebsmodus verlangt, sondern eine Mitgliedschaftsänderung im laufenden Protokoll. Der Preis dieser Entscheidung ist, dass auch der Einzelknoten den vollständigen Konsensapparat trägt; der Gegenwert ist, dass es keinen Pfad gibt, den nur der Verbund benutzt und der deshalb im Einzelbetrieb ungetestet bliebe.

| Eigenschaft | Ein Knoten | Verbund | Ursache |
|---|---|---|---|
| API, Objektmodell, Reconciler | identisch | identisch | INV-01, INV-02 |
| Schreibweg | Quorum von 1 | Quorum der Mehrheit | INV-04 |
| Platzierung | genau ein zulässiges Ziel | Bewertung über alle zulässigen Ziele | KANON 4.2a |
| Datensicherheitsstufe | nur **Lokal** erfüllbar | **Gespiegelt** ab 2, **Synchron gespiegelt** ab 3 oder 2+Zeuge | KANON 4.3, 4.3a |
| Redundanzanzeige | dauerhaft "Redundanz: keine" | Zahl der vertragenen Knotenausfälle | INV-18 |
| Kopplungsendpunkt | geschlossen | geschlossen, außer am Knoten im Wartemodus | INV-27, KANON 7 |

Die Kontrollebene selbst wird durch den zweiten Knoten **nicht** verfügbarer; sie wird es erst durch den dritten. Diese Aussage ist rechnerisch belegt (16.8) und ist der Grund, weshalb der Rollendialog den Wunsch "Redundanz" nicht als eine Entscheidung behandeln kann (16.7).

**Anforderungen**

- **R-16-01** — Es existiert kein Codepfad, der ausschließlich im Mehrknotenbetrieb ausgeführt wird und im Ein-Knoten-Betrieb ungetestet bleibt. Prüfbar: Abdeckungsmessung des Konsens- und Platzierungspfades in einer Ein-Knoten-Installation ≥ 95 % der im Verbund ausgeführten Zweige (KANON 4.1).
- **R-16-02** — Der Übergang von 1 auf 3 Stimmknoten erfolgt ohne Neustart der Kontrollebene, ohne Export/Import des Sollzustands und ohne Unterbrechung laufender Dienste. Prüfbar: Durchlauf mit fortlaufender Schreiblast; Zahl abgelehnter Schreibvorgänge = 0 außerhalb der Schreibpause nach K-05.

## 16.2 Knotenrollen

Die Rolle eines Knotens ist eine **Menge**, kein Aufzählungswert. Ein Knoten kann gleichzeitig Stimmknoten, Dienstträger und Speicherträger sein; ein Zeuge ist definiert als Stimmknoten, dessen Menge keine weitere Rolle enthält. Diese Modellierung verhindert die sonst übliche Kategorienverwechslung, bei der Verfügbarkeit der Kontrollebene und Verfügbarkeit der Daten in eine einzige Knotenklasse gepresst werden.

| Rolle (normativ) | Konsolenwort | Aufgabe | Stimmrecht | Ressourcenbedarf (Zielwert) | Ausfallfolge |
|---|---|---|---|---|---|
| **Stimmknoten** | Verwaltungsknoten | Führt oder bestätigt Einträge des Änderungsprotokolls; hält das materialisierte Lesemodell; erbringt API und Konsole; erzeugt die autoritativen Zoneninstanzen | ja | ≤ 4 GB RAM, ≤ 2 Kerne für Kontrollebene, Eingang, autoritativen DNS, Resolver und Protokollkopf (K-19) | Bei Verlust der Mehrheit: Sollzustand eingefroren (INV-04); Dienste laufen weiter (INV-25) |
| **Mitleser** | Verwaltungsknoten (mitlesend) | Empfängt das Protokoll vollständig, stimmt nicht ab, kann sofort befördert werden; bedient lesende API-Zugriffe und ist autoritativ für DNS | nein | wie Stimmknoten | Keine Wirkung auf die Beschlussfähigkeit; lesende Last verteilt sich auf weniger Knoten |
| **Zeuge** | Verwaltungsknoten (nur Stimme) | Stimmt ab, trägt weder Dienst noch Speicherbereich; zählt zugleich als Quorumszeuge der Blockreplikation (KANON 4.1b) | ja | ≤ 1 GB RAM, 1 Kern, ≥ 32 GB Datenträger (2 × 8 GB Systemabbild, ≤ 2 GB Momentaufnahme, Reserve) | Bei 2+Zeuge: Rückfall auf die Zwei-Knoten-Lage, ab dann keine automatische Übernahme mehr |
| **Dienstträger** | Dienstknoten | Führt Dienste aus; hält lokale Abbilder der zulässigen Katalogeinträge vor | nein (aus der Rolle) | nach Dienstbudget; ≤ 3 Dauerprozesse (K-20) | Dienste ohne zweite Replik sind bis zur Rückkehr nicht verfügbar; Dienste mit Replik werden umgeplant (16.11) |
| **Speicherträger** | Speicherknoten | Hält Speicherbereiche und deren Replikate; führt Momentaufnahmen und Auslagerung aus | nein (aus der Rolle) | nach Speicherbereichsgröße; eigenes Speichernetz erst ab 8 Knoten (KANON 4.3) | Replikationsgrad sinkt; Datensicherheitsstufe wird als degradiert angezeigt (INV-18) |
| **Eingangsträger** | Eingang | Terminiert TLS, führt die dynamisch programmierte L7-Datenebene | nein (aus der Rolle) | ≤ 512 MB RSS bei 500 Hostnamen und 2.000 Routen (K-19) | Veröffentlichungen über diesen Knoten sind nicht erreichbar, bis der Name auf einen anderen Eingangsträger zeigt |

Der **Ankerknoten** ist keine dieser Rollen, sondern eine Herkunftsangabe: der Knoten, auf dem die Kontrollebene zuerst konstituiert wurde (KANON 1). Er trägt nach der Aufnahme des dritten Stimmknotens kein Sonderrecht mehr; insbesondere ist er nicht zwingend der Führer, nicht der einzige Aussteller und nicht Voraussetzung für die Aufnahme weiterer Knoten. Die Konsole zeigt die Angabe weiterhin an, weil sie für die Rekonstruktion eines Vorfalls nützlich ist, und kennzeichnet sie ausdrücklich als Herkunft, nicht als Rolle. Die Alternative, den Ankerknoten dauerhaft zu privilegieren, ist verworfen, weil sie eine gemeinsame Ausfallursache erzeugt, die keine Verfügbarkeitsrechnung mehr einfängt.

Die Konsolenwörter **Dienstknoten** und **Speicherknoten** sind in KANON 1 nicht festgelegt; sie werden hier als Vorschlag geführt und sind vor der ersten Oberflächenumsetzung in den Kanon aufzunehmen. Ohne Festlegung besteht die Gefahr, dass Oberfläche und Fachtext auseinanderlaufen.

**Anforderungen**

- **R-16-03** — Die Rolle eines Knotens ist als Menge modelliert; die API akzeptiert jede Kombination aus Stimmknoten/Mitleser, Dienstträger, Speicherträger und Eingangsträger und lehnt ausschließlich die Kombination Zeuge mit Dienstträger oder Speicherträger ab. Prüfbar: Kombinationstest über alle Rollenmengen (KANON 3).
- **R-16-04** — Der Ankerknoten besitzt nach Erreichen von 3 Stimmknoten kein Recht, das ein anderer Stimmknoten nicht besitzt. Prüfbar: Abschalttest des Ankerknotens; alle Standardaufgaben einschließlich Knotenaufnahme und Zertifikatsausstellung bleiben ausführbar.
- **R-16-05** — Ein Zeuge hält 0 Speicherbereiche und führt 0 Dienste aus. Prüfbar: Platzierungstest mit erzwungener Zielwahl auf einen Zeugen; die Platzierung wird mit benannter Begründung abgelehnt.

## 16.3 Der Kopplungsvorgang

### 16.3.1 Ausgangslage, Codeformat und Entropie

Der Kopplungscode besteht aus 12 Zeichen Crockford-Base32, davon 10 Zufallszeichen und 2 Prüfzeichen, dargestellt als `XXXX-XXXX-XXXX` (KANON 3). Crockford-Base32 lässt die Zeichen I, L, O und U aus und bildet Klein- auf Großbuchstaben ab, wodurch die häufigsten Abschreibfehler bei der Eingabe von einem Bildschirm entfallen.

```
Entropie des Kopplungscodes
  Alphabetgroesse            32
  Entropie je Zeichen        log2(32) = 5 bit
  Zufallszeichen             10
  Gesamtentropie             10 x 5 bit = 50 bit
  Suchraum                   2^50 = 1.125.899.906.842.624 = 1,1259 x 10^15
  Pruefzeichen               2 Zeichen = 32^2 = 1.024 Pruefwerte
  Wirkung der Pruefzeichen   Ein zufaelliger Tippfehler besteht die Pruefung mit
                             1/1024 = 9,77 x 10^-4; ein Angreifer berechnet die
                             Pruefzeichen selbst und gewinnt dadurch nichts.
```

Die letzte Zeile ist die wichtigste: Prüfzeichen tragen **keine** Entropie gegenüber einem Angreifer, weil die Prüffunktion öffentlich ist. Sie reduzieren ausschließlich die Zahl der Protokollläufe, die durch Tippfehler verbraucht werden — bei einer Versuchsgrenze von 5 ist das der Unterschied zwischen einem bedienbaren und einem unbedienbaren Verfahren. Der Code wird vor jeder Verwendung normalisiert: Bindestriche entfernt, Kleinbuchstaben angehoben, I und L auf 1, O auf 0 abgebildet.

Der Code entsteht auf dem **wartenden Knoten**, weil er dort angezeigt wird, bevor irgendein Kontakt zur Kontrollebene besteht. Das Objekt **Kopplungsvorgang** entsteht dagegen auf der Kontrollebene, wenn der Bediener den Code eingibt (KANON 3). Daraus folgen zwei getrennte Fehlversuchszähler mit unterschiedlicher Bedeutung, und diese Trennung ist kein Implementierungsdetail: der Zähler auf dem wartenden Knoten begrenzt den Angreifer, der Zähler auf der Kontrollebene begrenzt den Bediener, der sich vertippt. Beide sind auf 5 gesetzt (INV-27).

### 16.3.2 Wie die Kontrollebene den wartenden Knoten findet

Die Aufnahme eines Knotens kostet zwei Entscheidungen: Code und Zweck (K-03). Eine Adresseingabe wäre eine dritte und ist deshalb ausgeschlossen. Der wartende Knoten kündigt sich stattdessen im lokalen Netzsegment an, mit Zielwert einer Ankündigung je Sekunde über die gesamte Codegültigkeit von 15 Minuten.

```
Ankuendigung (Klartext, unsigniert, nur lokales Segment)
  sitzung_id            16 byte Zufall, unabhaengig vom Code
  bootstrap_fp          SHA-256 des selbstsignierten Bootstrapzertifikats
  abbild_kennung        Kennung und Version der aktiven Abbildhaelfte
  hardware_kurz         Herstellerbezeichnung und Seriennummer, selbst behauptet
  schema_version        Protokollversion des Kopplungsverfahrens
```

Die Ankündigung enthält **nichts**, was vom Code abhängt. Eine naheliegende Alternative — eine aus dem Code abgeleitete Sitzungskennung, damit die Kontrollebene die Gegenstelle direkt bestimmen kann — ist verworfen: sie erlaubt ein Offline-Wörterbuch über 2^50 Werte, und 10^15 Hashwerte sind mit handelsüblicher Beschleunigerhardware in Tagen durchrechenbar. Der Preis der Verwerfung ist, dass die Kontrollebene bei mehreren gleichzeitig wartenden Knoten nicht weiß, welcher gemeint ist. Der Entwurf löst das so:

| Lage | Verhalten | Entscheidungen |
|---|---|---|
| Genau eine Ankündigung im Segment | Die Kontrollebene verbindet sich direkt; der Bediener bestätigt die angezeigte Maschinenkennung | 2 (Code, Zweck) |
| Mehrere Ankündigungen | Die Konsole zeigt die Liste; der Bediener wählt die Maschine | 3 — ausdrücklich eine Abweichung vom Zielwert, hier benannt |
| Keine Ankündigung, anderes Segment | Der Bediener gibt zusätzlich die Adresse ein | 3 — ausdrücklich eine Abweichung |

Beide Abweichungen sind reale Schwächen und werden nicht dadurch beseitigt, dass man sie als Sonderfall deklariert. K-03 bindet die Standardaufgabe; der Mehrknotenaufbau in einem Segment ist der Standardfall, die Kopplung über Segmentgrenzen ist es nicht. Die Alternative, den Kopplungsendpunkt über das lokale Segment hinaus zu öffnen, ist verworfen, weil sie den einzigen unauthentisierten Endpunkt des Systems (KANON 7, [Kapitel 12](12-dns-netzwerk.md)) routbar machte.

Ein Angreifer kann Ankündigungen fälschen und damit die Auswahlliste fluten. Das ist eine Dienstverweigerung gegen die Bedienbarkeit, keine Kompromittierung: der Angreifer kennt den Code nicht und besteht die Bestätigung nicht. Die Gegenmaßnahme ist eine Ratenbegrenzung je Quelladresse und eine Obergrenze der angezeigten Ankündigungen (Zielwert 8), darüber hinaus eine benannte Störungsmeldung statt einer unbrauchbar langen Liste.

### 16.3.3 Zustandsautomat des wartenden Knotens

```
  [INSTALLIERT]
      | Zeitguete belegt (INV-32), Bootstrapzertifikat mit Restlaufzeit <= 60 min
      v
  [WARTEMODUS] -- Code c erzeugt und angezeigt, 8403/tcp offen, Ankuendigung 1/s
      |      \                                   \
      |       \ 15 min ohne Erfolg                \ 5 fehlgeschlagene Bestaetigungen
      |        v                                   v
      |     [ABGELAUFEN]                       [GESPERRT]
      |      Neuer Code nur durch Handlung      Kopplungsendpunkt dauerhaft zu;
      |      an der Konsole des Knotens         Rueckkehr nur durch Handlung an
      |      -> [WARTEMODUS]                    der Konsole des Knotens
      |
      | eingehende Verbindung
      v
  [KANAL_OFFEN] -- TLS 1.3, Bootstrapzertifikat, Exporter berechnet
      |
      | SPAKE2-Nachricht der Gegenseite empfangen, eigene gesendet
      v
  [PAKE_LAUFEND]
      |      \ Bestaetiger falsch -> Zaehler +1, Verbindung zu, Wartezeit 2 s
      |       \                      -> [WARTEMODUS]
      | Bestaetiger beidseitig geprueft (laufzeitkonstant)
      v
  [KANAL_AUTHENTISIERT] -- K_kanal aktiv, ChaCha20-Poly1305 (RFC 8439)
      |
      | Schluesselpaar im TPM erzeugt, CSR mit Bescheinigung gesendet
      v
  [ZERTIFIKAT_ERWARTET]
      |      \ Abbruch oder 60 s ohne Antwort -> [WARTEMODUS], Code bleibt gueltig,
      |       \                                  Zaehler unveraendert
      | Knotenzertifikat und Kette empfangen, Kette gegen Wurzelfingerabdruck geprueft
      v
  [GEKOPPELT] -- Code vernichtet, 8403/tcp geschlossen, ab hier nur mTLS
      v
  [PRODUKTIV] -- nach dem ersten vollstaendigen Sollzustandsabgleich
```

Der Übergang von `[ZERTIFIKAT_ERWARTET]` zurück nach `[WARTEMODUS]` erhöht den Fehlversuchszähler **nicht**. Begründung: bis zu diesem Punkt hat die Gegenstelle den Codebesitz bereits bewiesen, ein Abbruch danach ist ein Netz- oder Stromereignis und kein Rateversuch. Würde er gezählt, ließe sich eine Kopplung durch fünf abgebrochene Verbindungen dauerhaft verhindern, ohne den Code zu kennen.

### 16.3.4 Zustandsautomat der Kontrollebene

```
  [LEERLAUF]
      | Bediener gibt Code ein (Entscheidung 1 von 2)
      v
  [K_ANGELEGT] -- Kopplungsvorgang mit Codehashwert, Ablauf = Restgueltigkeit,
      |            Fehlversuchszaehler 0, vorgesehener Zweck noch leer
      | Ziel bestimmt (16.3.2)
      v
  [VERBUNDEN] -- TLS zum wartenden Knoten, Exporter berechnet
      v
  [PAKE_LAUFEND]
      |      \ Bestaetiger falsch -> Zaehler +1; bei 5 -> [GESPERRT], Vorgang endet
      | Bestaetiger geprueft
      v
  [KANAL_AUTHENTISIERT]
      |      \ Hardwarekennung bereits als gekoppelter Knoten bekannt
      |       \   -> [ABGELEHNT] mit benanntem Grund und Angebot "Knoten ersetzen"
      |      \ Knotenhoechstzahl erreicht -> [ABGELEHNT] (R-05-22)
      |      \ kein Quorum -> [ABGELEHNT] (INV-04)
      | CSR, Bescheinigung und Hardwareinventar geprueft
      v
  [AUSSTELLUNG] -- genau ein Protokolleintrag traegt beides:
      |            Zertifikatsausstellung und Zustandswechsel K = verwendet
      v
  [ZUSTELLUNG] -- Zertifikat und Kette im Kanal; erneute Zustellung fuer denselben
      |            oeffentlichen Schluessel ist idempotent (INV-07)
      |      \ kein mTLS-Kontakt binnen 10 min -> Zertifikat gesperrt,
      |       \                                  [FEHLGESCHLAGEN], Auditereignis
      v
  [ROLLENDIALOG] -- Zweck waehlen (Entscheidung 2 von 2), siehe 16.7
      v
  [ABGESCHLOSSEN] -- Knotenobjekt produktiv, Vorgang beendet
```

Die Atomarität in `[AUSSTELLUNG]` ist eine bewusste Entwurfsentscheidung. Zertifikatsausstellung und Verbrauch des Kopplungsvorgangs liegen in **einem** Protokolleintrag, sodass kein Zustand existiert, in dem ein Knotenzertifikat ausgestellt ist, ohne dass der zugehörige Kopplungsvorgang als verbraucht gilt. Die Alternative — zwei Einträge — erzeugt ein Fenster, in dem ein Absturz der Führung einen gültigen Kopplungsvorgang und ein bereits ausgestelltes Zertifikat zurücklässt.

### 16.3.5 Nachrichtenfolge

```
Wartender Knoten W                                  Kontrollebene A
------------------                                  ----------------
 0  c erzeugt, angezeigt
    w = Argon2id(norm(c), salz = "atrium/kopplung/v1" || sitzung_id)
        mod Gruppenordnung (edwards25519, RFC 9382, RFC 9106)
    -------------- Ankuendigung {sitzung_id, bootstrap_fp, ...} -------------->
                                                    (Multicast, lokales Segment)

 1                                                  Bediener gibt c ein
                                                    w identisch berechnet
                                                    Kopplungsvorgang K angelegt

 2  <------------- TLS 1.3 Handshake (Bootstrapzertifikat, ungeprueft) --------
 3  exp = TLS-Exporter("atrium/kopplung/v1", 32 byte)   beidseitig identisch,
                                                        genau dann wenn keine
                                                        Zwischenstelle terminiert

 4  Identitaetszeichenketten fuer das SPAKE2-Transkript:
      idA = "atrium-kontrollebene" || installations_ulid || exp
      idW = "atrium-wartemodus"    || sitzung_id         || exp

 5  <------------------------- SPAKE2 pA = w*M + X ------------------------
 6  -------------------------> SPAKE2 pW = w*N + Y ----------------------->
      (beide Nachrichten sind unabhaengig, ein Umlauf genuegt)

 7  beidseitig: Transkript TT = idA || idW || pA || pW || K_punkt || w
                Ke || Ka   = H(TT)                       (RFC 9382)
                KcA || KcW = KDF(Ka, "ConfirmationKeys") (RFC 9382)
                K_kanal    = HKDF(Ke, salz = exp,
                                  info = "atrium/kopplung/v1/kanal") (RFC 5869)

 8  <------------------------- MAC_A = MAC(KcA, TT) ----------------------
 9  -------------------------> MAC_W = MAC(KcW, TT) --------------------->
      Pruefung beidseitig laufzeitkonstant; Abweichung => Zaehler +1, Abbruch

10  ab hier: jede Nutzlast unter K_kanal, ChaCha20-Poly1305 (RFC 8439),
    Zaehler als Nonce, Richtung im zugehoerigen Klartext

11  Schluesselpaar im TPM erzeugt (privater Teil verlaesst den Knoten nie, INV-20)
    -----> CSR + TPM-Bescheinigung + Hardwareinventar + Fehlerzonenvorschlag --->

12                                                  Bescheinigung geprueft,
                                                    Hardwarekennung gegen Bestand,
                                                    Betreff und SAN aus dem
                                                    Knotenobjekt gesetzt, nicht
                                                    aus dem CSR uebernommen
    <----- Knotenzertifikat + Ausgabe-CA-Kette + Fingerabdruck der Wurzel --------

13  Kette geprueft, Schluessel an das Zertifikat gebunden, Wartemodus verlassen
    -----> Abschlussquittung {zertifikat_seriennummer, zeit} ------------------>

14  K_kanal verworfen; ab hier ausschliesslich gegenseitig authentisiertes TLS
    auf 8401/tcp und 8402/tcp im Knoten-Overlay
```

### 16.3.6 Ableitung des Kanalschlüssels und Bindung an die Verbindung

Die Kanalbindung erfolgt über die **Identitätszeichenketten des Transkripts**, nicht über die Ableitung des Passworts. Eine Zwischenstelle, die TLS auf beiden Seiten terminiert, erzeugt zwei verschiedene Exporterwerte; damit unterscheiden sich `idA` und `idW` auf beiden Teilstrecken, die Transkripte weichen ab, und die Bestätiger aus Schritt 8 und 9 schlagen fehl. Die verworfene Alternative — den Exporter als Argon2-Salz zu verwenden — erreicht dieselbe Bindung, zwingt aber zu einer vollständigen Argon2-Ableitung je eingehender Verbindung und macht den wartenden Knoten zu einem Rechenlastverstärker. Mit der gewählten Variante wird `w` einmal je Code berechnet und gehalten; eine neue Verbindung kostet nur eine Punktoperation.

Die Argon2id-Parameter sind Teil der Protokollversion und werden **nicht** ausgehandelt, weil jede Aushandlung ein Herabstufungsziel ist. Sie sind so zu wählen, dass eine Ableitung auf der kleinsten unterstützten Knotenklasse den Zielwert von 500 ms nicht überschreitet; der konkrete Parametersatz ist an dieser Hardware zu kalibrieren und steht hier bewusst nicht, weil eine erfundene Zahl keine Kalibrierung ersetzt. Der Beitrag von Argon2 zur Sicherheit ist gering — der Code ist online-begrenzt — und liegt allein darin, dass ein ausgelesener Codehashwert nicht sofort verwertbar ist.

### 16.3.7 Absicherung der Zertifikatsausstellung

| Maßnahme | Wirkung | Grundlage |
|---|---|---|
| Schlüsselerzeugung im TPM des wartenden Knotens | Kein privater Knotenschlüssel existiert außerhalb des Knotens | INV-20, [Kapitel 11](11-pki.md) |
| Betreff und SAN werden aus dem Knotenobjekt gesetzt, Angaben im CSR verworfen | Ein manipulierter CSR kann keinen fremden Namen erlangen | RFC 5280, INV-09 |
| TPM-Bescheinigung wird geprüft; ohne TPM wird `hardwarebindung: keine` gesetzt | Kein stillschweigendes Hochstufen einer unbelegten Bindung | INV-18, analog [Kapitel 13](13-geraeteverwaltung.md) |
| Ausstellung ist ein Eintrag im replizierten Protokoll | Vollständig rekonstruierbar; ohne Quorum keine Ausstellung | INV-22, INV-04 |
| Ausstellung erst nach geprüftem Bestätiger | Kein Zertifikat für eine Gegenstelle ohne Codebesitz | RFC 9382 |
| Erneute Zustellung desselben Zertifikats ist idempotent | Kein zweites Zertifikat nach verlorener Antwort | INV-07 |
| Zustellung über den authentisierten Kanal, Kette gegen mitgelieferten Wurzelfingerabdruck geprüft | Kein Vertrauensanker aus unbestätigter Quelle | RFC 5280 |

Die vierte Zeile hat eine Folge, die ausgesprochen werden muss: **ohne Quorum ist keine Kopplung möglich.** Ein Verbund, der die Mehrheit verloren hat, lässt sich nicht dadurch reparieren, dass ein neuer Knoten aufgenommen wird; der Weg zurück führt über die Rückkehr eines Mitglieds oder über die erzwungene Neukonstituierung ([Kapitel 08](08-kontrollebene.md), Fall B). Das ist eine unangenehme, aber unvermeidbare Eigenschaft jedes mehrheitsbasierten Verfahrens, und die Konsole benennt sie im Störungstext, statt eine Aufnahme anzubieten, die scheitern muss.

### 16.3.8 Ratenbegrenzung, Gültigkeit, Abbruch, Wiederholung, doppelte Kopplung

| Fall | Verhalten | Begründung |
|---|---|---|
| Gültigkeitsdauer | 15 min ab Anzeige; danach `[ABGELAUFEN]` | INV-27 |
| Versuchsgrenze | 5 fehlgeschlagene Bestätiger je Code; danach Code vernichtet, Endpunkt geschlossen | INV-27 |
| Mindestabstand zwischen Läufen | Zielwert 2 s je Quelladresse, 5 s global auf dem wartenden Knoten | Begrenzt den Durchsatz eines Angreifers auf höchstens 12 Läufe je Minute, bevor die Versuchsgrenze greift |
| Vergleich der Bestätiger | laufzeitkonstant; einheitlicher Fehlertext ohne Unterscheidung von falschem Code, Formatfehler und Protokollversion | Verhindert Seitenkanal und Informationspreisgabe |
| Nachrichtenprüfung | Schemavalidierung mit festen Längengrenzen vor jedem Parsen; keine dynamische Auswertung; kein Aufruf eines Unterprozesses mit Eingabedaten | Angriffsfläche des einzigen unauthentisierten Endpunkts |
| Abbruch vor dem Bestätiger | Kein Zustand auf beiden Seiten außer dem erhöhten Zähler | Kein Teilzustand (INV-12) |
| Abbruch nach dem Bestätiger, vor der Ausstellung | Kontrollebene verwirft den Kanal, Kopplungsvorgang bleibt offen bis zum Ablauf | Wiederholung ohne Neuanzeige des Codes möglich |
| Abbruch nach der Ausstellung, vor der Quittung | Knoten wiederholt den Lauf mit demselben Code; dieselbe Zertifikatsausstellung wird erneut zugestellt, kein zweites Zertifikat | INV-07 |
| Knoten meldet sich nach der Ausstellung nicht | Nach Zielwert 10 min wird das Zertifikat gesperrt, der Knoten geht in `Aufnahme fehlgeschlagen`, Auditereignis `knoten.aufnahme_fehlgeschlagen` | Kein dauerhaft gültiges Zertifikat ohne erreichbaren Inhaber |
| Wiederholte Verwendung eines erfolgreichen Codes | Unmöglich: Code vernichtet, Endpunkt geschlossen, Kopplungsvorgang `verwendet` | INV-27 |
| Zwei Kontrollebenen greifen auf denselben wartenden Knoten zu | Läufe sind serialisiert; die erste erfolgreiche Bestätigung gewinnt, die zweite erhält `nicht mehr im Wartemodus` | Der Knoten führt genau einen Lauf gleichzeitig |
| Bereits gekoppelte Hardware wird erneut gekoppelt | Ablehnung mit benanntem Grund; Angebot "Knoten ersetzen" als eigener Vorgang (Entkoppeln, Zertifikat sperren, dann koppeln) | INV-11: keine implizite Löschung des bestehenden Knotenobjekts |
| Hardwarekennung ohne TPM | Duplikaterkennung stützt sich auf selbst behauptete Angaben und ist fälschbar; der Knoten wird mit `hardwarebindung: keine` geführt | Ehrliche Kennzeichnung statt unbelegter Zusage |

**Anforderungen**

- **R-16-06** — Der Kopplungsendpunkt ist ausschließlich im Zustand Wartemodus und ausschließlich im lokalen Netzsegment erreichbar; in jedem anderen Knotenzustand antwortet 8403/tcp nicht. Prüfbar: Portabtastung in allen Knotenzuständen (INV-27, KANON 7).
- **R-16-07** — Ein Kopplungscode ist einmalig, ≤ 15 min gültig und nach 5 fehlgeschlagenen Bestätigern vernichtet; ein Abbruch nach erfolgreichem Bestätiger erhöht den Zähler nicht. Prüfbar: Wiederverwendungs-, Raten- und Abbruchtest (INV-27).
- **R-16-08** — Ein Zwischenangriff, der TLS auf beiden Seiten terminiert, führt zu 0 ausgestellten Knotenzertifikaten. Prüfbar: Angriffssimulation mit vorgeschalteter Gegenstelle; beide Bestätiger schlagen fehl (RFC 9382).
- **R-16-09** — Die Kontrollebene übernimmt aus einem CSR ausschließlich den öffentlichen Schlüssel; Betreff, SAN und Verwendungszweck werden aus dem Knotenobjekt gesetzt. Prüfbar: CSR mit fremdem SAN; das ausgestellte Zertifikat enthält den fremden Namen nicht (RFC 5280).
- **R-16-10** — Zertifikatsausstellung und Verbrauch des Kopplungsvorgangs liegen in einem Protokolleintrag. Prüfbar: Absturzinjektion zwischen beiden Wirkungen; die Zahl der Zustände mit ausgestelltem Zertifikat und unverbrauchtem Kopplungsvorgang ist 0.
- **R-16-11** — Die Antwortzeit des Kopplungsendpunkts ist unabhängig vom Grad der Übereinstimmung des Codes. Prüfbar: Zeitmessung über 10.000 Läufe mit 0, 5 und 9 übereinstimmenden Zeichen; die Verteilungen sind nicht unterscheidbar.
- **R-16-12** — Ohne Quorum wird jede Kopplung abgelehnt; die Ablehnung nennt den Grund und den Weg zurück, ohne auf ein Kommandozeilenwerkzeug zu verweisen. Prüfbar: Partitionstest mit Kopplungsversuch auf der Minderheitsseite (INV-04, INV-17).

## 16.4 Sicherheitsanalyse des Kopplungsvorgangs

| Angreifer | Fähigkeit | Wirkung ohne Gegenmaßnahme | Gegenmaßnahme | Restrisiko |
|---|---|---|---|---|
| Passiv im Netz | Mitschnitt aller Nachrichten | Offline-Raten des Codes | SPAKE2 nach RFC 9382: ein Mitschnitt erlaubt keinen Offline-Angriff | Verkehrsanalyse; der Angreifer weiß, dass und wann gekoppelt wurde |
| Aktiv im Netz, Zwischenstelle | Terminiert TLS beidseitig, verändert Nachrichten | Übernahme des Knotens | Exporterbindung im Transkript; beide Bestätiger schlagen fehl | Dienstverweigerung: der Angreifer kann jede Kopplung verhindern |
| Aktiv im Netz, Fälschung von Ankündigungen | Flutet die Auswahlliste | Bediener wählt die falsche Maschine | Obergrenze der angezeigten Ankündigungen, Ratenbegrenzung je Quelle, Anzeige der Maschinenkennung zur Bestätigung | Ein Bediener, der die Bestätigung nicht liest, wählt falsch |
| Aktiv, Rateversuche gegen den Endpunkt | Beliebig viele Verbindungen | Erraten des Codes | 5 Versuche je Code, Mindestabstand, Vernichtung des Codes | Dienstverweigerung: fünf Fehlversuche sperren die Kopplung bis zu einer Handlung am Knoten |
| Sicht auf den Bildschirm | Schulterblick, Kamera, Bildschirmfreigabe, Fotoweitergabe | Vollständige Übernahme binnen 15 min | Keine kryptografische; nur Gültigkeitsdauer, Einmaligkeit, Auditereignis und sichtbare Knotenaufnahme im Überblick | **Nicht behebbar.** Ein angezeigtes Geheimnis ist für jeden lesbar, der den Bildschirm sieht |
| Fernkonsole eines Mietservers | Anbieter liest die Konsolenausgabe mit | Vollständige Übernahme | Sonderweg mit Aufnahmetoken (16.5); Kennzeichnung als schwächeres Verfahren | **Nicht behebbar.** Wer die Fernkonsole stellt, stellt auch die Hardware und kann den Arbeitsspeicher lesen |
| Wiedereinspielung | Erneutes Senden mitgeschnittener SPAKE2-Nachrichten | Übernahme ohne Codekenntnis | Exporter je Verbindung frisch; Transkript enthält `sitzung_id` und Exporter; Code einmalig | Keine |
| Vertauschung der Gegenstelle | Bediener koppelt die falsche Maschine oder in den falschen Verbund | Knoten landet im fremden Verbund | Beidseitige Anzeige: die Konsole zeigt Maschinenkennung und Bootstrap-Fingerabdruck, der Knotenbildschirm zeigt Name und Kurzkennung des aufnehmenden Verbunds | Wer den Code per Foto erhält, führt die Gegenprüfung nicht durch |

Zwei Zeilen dieser Tabelle sind mit "nicht behebbar" gekennzeichnet, und das ist keine Formulierungsschwäche. Ein Verfahren, das ein kurzes Geheimnis auf einem Bildschirm anzeigt, ist gegen jeden sicher, der den Bildschirm nicht sieht, und gegen niemanden, der ihn sieht. Der gesamte kryptografische Aufwand schützt die **Leitung**, nicht die **Anzeige**. Die einzige Verbesserung, die der Entwurf anbietet, ist Entdeckung statt Verhinderung: eine erfolgreiche Kopplung erzeugt ein Auditereignis, erscheint im Überblick und ändert die angezeigte Knotenzahl, sodass eine fremde Aufnahme auffällt, wenn jemand hinsieht.

Die beidseitige Anzeige verdient eine Erläuterung, weil sie die einzige Abwehr gegen die Vertauschung der Gegenstelle ist. SPAKE2 beweist, dass beide Seiten denselben Code kennen — nicht, dass es die Maschine ist, die der Bediener meint. Im Regelfall hat der Bediener ohnehin die Konsole des wartenden Knotens vor sich, weil er von dort den Code abliest; die Gegenprüfung der Kurzkennung kostet dann keine zusätzliche Handlung und ist eine Bestätigung, keine Entscheidung (INV-14). Sobald der Code über einen zweiten Kanal weitergegeben wird, entfällt die Prüfung, und genau dieser Fall ist im Whitepaper als Schwäche zu führen, nicht als Randnotiz.

**Anforderungen**

- **R-16-13** — Vor Abschluss der Kopplung zeigt die Konsole Maschinenkennung und Fingerabdruck der Gegenstelle, und der Bildschirm des wartenden Knotens zeigt Name und Kurzkennung des aufnehmenden Verbunds. Prüfbar: Darstellungstest beider Seiten; eine fehlende Anzeige bricht den Bau.
- **R-16-14** — Jede erfolgreiche und jede fehlgeschlagene Kopplung erzeugt ein Auditereignis mit Maschinenkennung, Aufnahmeweg, Akteur und Ergebnis. Prüfbar: Auditstromprüfung nach beiden Ausgängen (INV-23).

## 16.5 Erfolgswahrscheinlichkeit des Erratens

```
Modell, keine Messung. Annahme: der Angreifer raet gleichverteilt und
unabhaengig; er kennt Codeformat und Pruefzeichenfunktion.

(1) Je Code, bei 5 zugelassenen Versuchen
      P1 = 5 / 2^50 = 5 / 1,1259 x 10^15 = 4,441 x 10^-15

(2) Ueber ein Jahr, Annahme: Kopplungsendpunkt ist ununterbrochen offen,
    jeder Code lebt die vollen 15 min
      Codes je Jahr   = 4 x 24 x 365 = 35.040
      Versuche        = 35.040 x 5   = 175.200
      P2 = 175.200 / 1,1259 x 10^15  = 1,556 x 10^-10
      (Vereinigungsschranke; der exakte Wert 1 - (1 - P1)^35040 stimmt
       in den ersten fuenf gueltigen Ziffern ueberein)

(3) Ueber ein Jahr, realistische Annahme fuer eine Installation mit 32 Knoten
    und vollstaendigem Hardwarewechsel binnen 3 Jahren
      Kopplungen je Jahr = 32 / 3 = 10,7 -> aufgerundet 11
      Versuche           = 11 x 5 = 55
      P3 = 55 / 1,1259 x 10^15 = 4,885 x 10^-14

(4) Hypothetisch ohne jede Begrenzung, 1.000 vollstaendige Protokolllaeufe je
    Sekunde
      Erwartungswert = 2^49 / 1.000 = 5,630 x 10^11 s
                     = 5,630 x 10^11 / 31.536.000 = 17.850 Jahre
```

Die Werte (1) und (2) entsprechen K-14. Der Wert (3) ist die praktisch zutreffende Zahl und liegt um den Faktor 3.186 unter (2), weil (2) einen Endpunkt annimmt, der nie geschlossen ist. Der Entwurf zitiert dennoch (2) als Zusage, weil eine Zusage die ungünstigere Annahme tragen muss.

Die Deutung ist unbequem: alle vier Zahlen sind gegenüber dem Risiko aus 16.4 bedeutungslos. Ein Angreifer, der den Bildschirm sieht, hat die Erfolgswahrscheinlichkeit 1; ein Angreifer, der raten muss, hat 10^-14. Die Entropierechnung belegt, dass das Raten nicht der Angriffspfad ist — sie belegt nicht, dass das Verfahren sicher ist.

## 16.6 Sonderweg: signiertes Aufnahmetoken

Für unbeaufsichtigte Ausrollung über Netzstart und Erstkonfiguration per Abbild sowie für gemietete Server mit anbieterseitig mitlesbarer Fernkonsole existiert ein zweiter Aufnahmeweg (KANON 4.4a). Er ist kein gleichwertiges Verfahren, sondern ein ausdrücklich schwächeres.

```
Aufnahmetoken (signiert mit dem Ausgabeschluessel der Kontrollebene,
Ed25519 nach RFC 8032; kanonische Serialisierung nach RFC 8785)

  installations_ulid      Ziel-Installation
  ausgabe_ca_fp           SHA-256 des Ausgabe-CA-Zertifikats  (Pinning)
  wurzel_ca_fp            SHA-256 des Wurzelzertifikats       (Pinning)
  einmal_kennung          ULID; genau ein Knoten je Token
  gueltig_bis             Zielwert <= 24 h, bis 7 d nur mit Begruendung
  erlaubte_quellnetze     Praefixliste; leere Liste ist unzulaessig
  vorgesehener_zweck      Rollenvorgabe, hoechstens Dienstträger
  erreichbare_adressen    Adressen der Kontrollebene
```

Der Ablauf kehrt die Verbindungsrichtung um: der neue Knoten öffnet **keinen** Endpunkt, sondern verbindet sich nach dem Start selbst zur Kontrollebene, prüft deren Kette gegen `ausgabe_ca_fp` und `wurzel_ca_fp`, **bevor** er das Token überträgt, und sendet dann Token, CSR und Bescheinigung. Die Kontrollebene prüft Signatur, Einmaligkeit, Gültigkeit und Quellpräfix und stellt in einem Protokolleintrag aus. Ein Wartemodus und ein angezeigter Code existieren in diesem Weg nicht.

| Warum schwächer | Begrenzung | Sichtbarkeit |
|---|---|---|
| Das Token ist ein Inhabergeheimnis; wer es hat, wird Knoten | Einmalig; Gültigkeit Zielwert ≤ 24 h; an Quellpräfixe gebunden | Vorgang "Aufnahmetoken ausgestellt" mit Freigabepflicht im Review-Bereich |
| Es liegt ruhend in einem Abbild oder in einem Metadatendienst | Es wird nie in ein Abbild eingebacken, das mehr als einen Knoten bedient; je Knoten ein Token | Token trägt `einmal_kennung`; die Konsole zeigt ausgestellte, verbrauchte und verfallene Token |
| Kein Mensch bestätigt die Gegenstelle | Pinning beider CA-Fingerabdrücke verhindert die Umlenkung auf eine fremde Kontrollebene | Der Knoten führt dauerhaft `aufnahmeweg: token` |
| Ein token-gekoppelter Knoten ist nicht durch Anwesenheit belegt | Ein solcher Knoten erhält **niemals** automatisch Stimmrecht; die Beförderung zum Stimmknoten ist eine eigene Entscheidung eines Menschen | Der Überblick zählt Knoten mit `aufnahmeweg: token` dauerhaft (INV-18) |
| Der Anbieter eines Mietservers kann das Token vor dem ersten Start kopieren | Keine. Wer das Abbild liest, gewinnt das Rennen | Der verbrauchte Token nennt Hardwarekennung und Zeitpunkt; ein Verbrauch ohne passenden erwarteten Knoten ist eine Störung mit eigenem Ereignistyp |

Die letzte Zeile ist der Kern: das Token schützt nicht gegen den, der die Ablage kontrolliert. Es schützt gegen die Umlenkung auf eine fremde Kontrollebene und es macht einen Missbrauch **entdeckbar**, weil der legitime Knoten dann ein verbrauchtes Token vorfindet und die Konsole eine Aufnahme meldet, die niemand veranlasst hat. Entdeckung ist weniger als Verhinderung, und der Text sagt das, statt es zu verschleiern.

Das Attribut `aufnahmeweg` ist am Knotenobjekt unveränderlich und überlebt Neustarts, Abbildwechsel und Zertifikatserneuerungen. Eine Umdeklaration auf `kopplungscode` ist nur durch Entkoppeln und erneute Aufnahme über einen Kopplungscode möglich. Ohne diese Unveränderlichkeit wäre die Kennzeichnung nach wenigen Betriebsmonaten wertlos.

**Anforderungen**

- **R-16-15** — Ein Aufnahmetoken ist genau einmal verwendbar, an mindestens ein Quellpräfix gebunden und trägt die Fingerabdrücke von Wurzel- und Ausgabe-CA. Ein Token ohne Quellpräfixliste wird von der API abgelehnt. Prüfbar: Wiederverwendungstest und Ausstellungstest mit leerer Liste (KANON 4.4a).
- **R-16-16** — Der über ein Token aufgenommene Knoten prüft die Zertifikatskette gegen die gepinnten Fingerabdrücke, bevor er ein Byte des Tokens überträgt. Prüfbar: Aufnahmeversuch gegen eine Gegenstelle mit abweichender Kette; übertragene Tokenbytes = 0.
- **R-16-17** — Ein Knoten mit `aufnahmeweg: token` erhält kein Stimmrecht ohne eine eigene, protokollierte Entscheidung eines Menschen. Prüfbar: Rollenvergabetest; automatische Beförderungen solcher Knoten = 0.
- **R-16-18** — Das Attribut `aufnahmeweg` ist nach der Aufnahme unveränderlich und wird am Knoten und im Überblick angezeigt. Prüfbar: Schreibversuch über die API wird abgelehnt; Darstellungstest (INV-18).

## 16.7 Der Rollendialog

Nach der erfolgreichen Kopplung stellt die Konsole genau eine Frage. Alles andere wird abgeleitet und mit benannter Quelle angezeigt (INV-15).

| Gestellte Frage | Vorbelegung | Quelle der Vorbelegung |
|---|---|---|
| "Wozu dient dieser Knoten?" mit den Antworten *Redundanz*, *weiterer Verwaltungsknoten*, *Dienste verteilen* | *Redundanz*, solange weniger als 3 Stimmknoten bestehen; sonst *Dienste verteilen* | Aktuelle Stimmzahl und Zahl der Knoten je Fehlerzone |

Nicht gefragt, sondern abgeleitet werden: Mandantenzugehörigkeit (Plattform, bis eine Isolationsstufe M3 etwas anderes verlangt), technischer Name (`<knoten>.knoten.<basisdomäne>`), Netzzonen, Knotenklasse (aus dem übermittelten Hardwareinventar), Fehlerzone (Vorschlag des Knotens aus Netzumgebung und Adressbereich, bestätigungspflichtig, aber keine Entscheidung, weil vorbelegt), Zertifikatsprofil, Abbildkanal und Ausrollstufe. Die Fehlerzone ist der einzige dieser Werte, dessen Vorbelegung falsch sein kann, ohne dass das System es merkt: zwei Maschinen im selben Rack sehen aus Netzsicht wie zwei Fehlerzonen, wenn sie an verschiedenen Zugangsschaltern hängen. Die Konsole zeigt deshalb bei jeder Fehlerzonenvorbelegung die Herleitung an und kennzeichnet sie als Vermutung.

### Abbildung der drei Wünsche auf Rollen

| Antwort | Rollenmenge des neuen Knotens | Stimmrecht | Weitere Wirkung |
|---|---|---|---|
| **Redundanz** | Dienstträger + Speicherträger; bei Knotenklasse unterhalb der Dienstschwelle stattdessen Zeuge | nach der Regel unten | Erfüllbare Datensicherheitsstufen ändern sich; die Konsole zeigt das neue RPO je Dienst |
| **Weiterer Verwaltungsknoten** | Stimmknoten oder Mitleser, zusätzlich Eingangsträger | nach der Regel unten | Konsole, API und autoritativer DNS werden auch dort erbracht |
| **Dienste verteilen** | Dienstträger | nein | Der Platzierungsraum wächst; die Verfügbarkeit der Kontrollebene ändert sich nicht |

```
Deterministische Stimmrechtsregel (angewandt nach der Antwort)
  s = Zahl der Stimmknoten vor der Aufnahme
  n = Zahl der Verwaltungsknoten nach der Aufnahme (Stimmknoten + Mitleser)

  s = 1 und n < 3   -> Mitleser.   Anzeige: "Kontrollebene weiterhin ohne
                                   Redundanz; ein dritter Verwaltungsknoten
                                   oder ein Zeuge stellt sie her."
  s = 1 und n >= 3  -> Beförderung auf 3 Stimmknoten in Einzelschritten
                       (Verfahren in Kapitel 08, R-08-28)
  s = 3 und n < 5   -> Mitleser (INV-05 verbietet 4)
  s = 3 und n >= 5  -> Mitleser; Beförderung auf 5 nur auf ausdrueckliche
                       Wahl (K-04)
  s = 5             -> Mitleser
  Knotenklasse unterhalb der Dienstschwelle und s ungerade < 5
                    -> Zeuge, sofern dadurch s ungerade bleibt
```

### Warum Redundanz keine Entscheidung ist

"Redundanz" ist kein Schalter, weil sie das Ergebnis zweier voneinander unabhängiger Größen ist.

| Achse | Was sie bestimmt | Wodurch sie steigt | Was sie **nicht** bewirkt |
|---|---|---|---|
| Stimmrecht | Ob der Sollzustand weiter beschrieben werden kann | Stimmzahl 1 → 3 → 5 | Sie macht keine Daten verfügbar; ein fünfter Stimmknoten ohne Replikat rettet keinen Speicherbereich |
| Speicherklasse | Ob die Daten eines Dienstes anderswo vorliegen | Datensicherheitsstufe Lokal → Gespiegelt → Synchron gespiegelt, gestützt auf Speicherträger in verschiedenen Fehlerzonen | Sie macht die Kontrollebene nicht beschlussfähig; drei Replikate auf zwei Stimmknoten ändern nichts am Einfrieren |

Die vertragene Ausfallzahl ist das Minimum beider Achsen. Ein Verbund mit 5 Stimmknoten und zweifach gespiegelten Speicherbereichen verträgt genau **einen** Ausfall, nicht zwei. Diese Zahl steht im Überblick (16.13), und sie ist der Grund, weshalb der Rollendialog nach dem Zweck fragt und nicht nach der Redundanz: der Zweck ist eine Absicht, die Redundanz ist ein Rechenergebnis.

Der Zwei-Knoten-Fall macht das konkret. Wer als zweiten Knoten "Redundanz" wählt, erhält Datenspiegelung mit RPO ≤ 15 min (K-09) und eine Kontrollebene, die weiterhin einen einzigen Stimmknoten hat. Die Konsole schreibt genau das hin, statt "Redundanz eingerichtet" zu melden, und benennt den Zeugen als nächsten Schritt. Eine Oberfläche, die hier eine Zusage macht, die die Technik nicht einlöst, verstößt gegen INV-18.

**Anforderungen**

- **R-16-19** — Die Aufnahme eines Knotens kostet genau zwei Entscheidungen: Code und Zweck. Jedes weitere Feld ist vorbelegt und nennt seine Quelle. Prüfbar: Abgleich der Formulardefinition gegen die Aufgabendefinition im Bau (INV-14, INV-15, K-03).
- **R-16-20** — Stimmrecht wird ausschließlich nach der deterministischen Regel vergeben; eine gerade Zielstimmzahl entsteht nie. Prüfbar: Aufnahmetest über die Knotenzahlen 1 bis 6 mit allen drei Antworten; Zahl der Zustände mit gerader Stimmzahl außerhalb der Änderungstransaktion = 0 (INV-05).
- **R-16-21** — Die Konsole zeigt nach jedem Rollendialog die resultierende Zahl vertragener Knotenausfälle als Minimum aus Stimm- und Datenachse. Prüfbar: Zustandsmatrixtest über die Kombinationen aus 1/3/5 Stimmknoten und den drei Datensicherheitsstufen (INV-18).
- **R-16-22** — Eine Fehlerzonenvorbelegung wird mit ihrer Herleitung angezeigt und als Vermutung gekennzeichnet. Prüfbar: Darstellungstest; eine Vorbelegung ohne Herleitung bricht den Bau (INV-15).

## 16.8 Quorum und Verfügbarkeit

```
Annahme: Einzelknotenverfuegbarkeit A = 0,99 einschliesslich Wartungsfenstern.
Annahme: Ausfaelle sind unabhaengig.  Beobachtungszeitraum 8.760 h/a.
Mehrheitsbedingung: verfuegbar sind mindestens floor(n/2) + 1 Stimmknoten.

A   = 0,99          A^2 = 0,9801        A^3 = 0,970299
A^4 = 0,96059601    A^5 = 0,9509900499

n = 1, Schwelle 1
    V1 = A = 0,99
    Ausfallzeit = 0,01 x 8.760 = 87,6 h/a = 3,65 d/a
    vertragene Ausfaelle f = 0

n = 2, Schwelle 2   (durch INV-05 verboten, hier nur zum Vergleich)
    V2 = A^2 = 0,9801
    Ausfallzeit = 0,0199 x 8.760 = 174,3 h/a
    vertragene Ausfaelle f = 0
    -> schlechter als ein einzelner Knoten, bei doppelter Hardware

n = 3, Schwelle 2
    V3 = A^3 + 3 A^2 (1-A) = 0,970299 + 0,029403 = 0,999702
    Ausfallzeit = 0,000298 x 8.760 = 2,610 h/a
    vertragene Ausfaelle f = 1

n = 4, Schwelle 3
    V4 = A^4 + 4 A^3 (1-A) = 0,96059601 + 0,03881196 = 0,99940797
    Ausfallzeit = 0,00059203 x 8.760 = 5,186 h/a
    vertragene Ausfaelle f = 1
    -> gleiche Toleranz wie n = 3, aber 2,576 h/a mehr Ausfall

n = 5, Schwelle 3
    V5 = A^5 + 5 A^4 (1-A) + 10 A^3 (1-A)^2
       = 0,9509900499 + 0,0480298005 + 0,000970299 = 0,9999901494
    Ausfallzeit = 0,0000098506 x 8.760 = 0,0863 h/a = 5,18 min/a
    vertragene Ausfaelle f = 2

Gewinn 3 -> 5 = 2,610 - 0,086 = 2,524 h/a   (K-04 nennt gerundet 2,6 h/a)
```

Daraus folgt die Regel aus INV-05 unmittelbar. Die vertragene Ausfallzahl ist `f = floor((n-1)/2)`; sie steigt nur beim Übergang auf eine ungerade Zahl. Eine gerade Stimmzahl `2f+2` hat dieselbe Toleranz wie `2f+1`, aber einen zusätzlichen Knoten, der ausfallen kann, und erhöht die Mehrheitsschwelle von `f+1` auf `f+2`. Für jedes `A > 0,5` ist die Verfügbarkeit bei gerader Stimmzahl deshalb strikt kleiner als bei der nächstkleineren ungeraden. Der Fall `n = 2` ist der anschaulichste: zwei Maschinen sind mit 174,3 h/a doppelt so lange nicht beschlussfähig wie eine einzelne mit 87,6 h/a.

### Der Zwei-Knoten-Fall mit Zeugen

```
Annahme: zwei Dienstknoten mit A1 = A2 = 0,99 an zwei Standorten,
         ein Zeuge mit A3 = 0,98 (kleinere Hardware, dritter Standort,
         schlechtere Anbindung). Schwelle 2 von 3.

  alle drei          0,99 x 0,99 x 0,98               = 0,960498
  ohne Zeugen        0,99 x 0,99 x 0,02               = 0,019602
  ohne Knoten 2      0,99 x 0,01 x 0,98               = 0,009702
  ohne Knoten 1      0,01 x 0,99 x 0,98               = 0,009702
  Summe Vz                                            = 0,999504
  Ausfallzeit        0,000496 x 8.760                 = 4,345 h/a

  Vergleich: drei gleichwertige Stimmknoten 0,999702 -> 2,610 h/a
             Differenz 0,000198 -> 1,735 h/a Mehrausfall durch den
             schwaecheren Zeugen
             Vergleich zu zwei Stimmknoten: 174,3 h/a -> 4,345 h/a,
             Faktor 40 besser
```

Der Zeuge ist damit rechnerisch die wirksamste Einzelmaßnahme im gesamten Kapitel: eine Maschine mit 1 GB RAM senkt die rechnerische Ausfallzeit der Kontrollebene um den Faktor 40. Er zählt zugleich als Quorumszeuge der Blockreplikation (KANON 4.1b), sodass die Datensicherheitsstufe *Synchron gespiegelt* bei zwei Speicherträgern erfüllbar wird. Die verworfene Alternative — zwei Stimmknoten mit Handentscheidung im Störfall — legt dem Bediener eine Entscheidung auf, die er im Störfall unter Zeitdruck und mit unvollständiger Information treffen müsste, und ist genau die Art von Rückfrage, die dieses Produkt vermeiden soll.

### Die Unabhängigkeitsannahme

Die Annahme unabhängiger Ausfälle ist die schwächste Stelle jeder dieser Zahlen. Sie ist in der Praxis regelmäßig verletzt, und zwar korreliert nach oben, nicht nach unten.

| Gemeinsame Ursache | Wirkt auf | Gegenmaßnahme im Entwurf | Verbleibende Wirkung |
|---|---|---|---|
| Stromkreis | alle Knoten einer Fehlerzone | Antiaffinität entlang Fehlerzonen; Fehlerzone ist Pflichtattribut des Knotens | Vorbelegung der Fehlerzone ist eine Vermutung (16.7) |
| Netzkomponente, ein Zugangsschalter | alle daran hängenden Knoten | Fehlerzonenmodell; getrenntes Speichernetz erst ab 8 Knoten | Ein einzelner Uplink bleibt in kleinen Installationen gemeinsame Ursache |
| Gleiche Abbildversion | alle Knoten gleichzeitig | Gestaffelte Ausrollung, Beobachtungsfenster 30 min, Verwaltungsknoten zuletzt und nie gleichzeitig (K-23, [Kapitel 21](21-betrieb-updates.md)) | Ein Fehler, der erst nach dem Beobachtungsfenster auftritt, erreicht alle |
| Gleiche Hardwareserie und Firmware | alle Knoten einer Beschaffung | Keine technische; Hinweis in der Konsole, wenn alle Knoten dieselbe Hardwarekennung tragen | Nicht behoben |
| Gemeinsamer Zeitfehler | alle Knoten | Mindestens zwei gesicherte externe Zeitquellen; Selbstabschottung bei unbekannter Zeitgüte (INV-32, K-30) | Eine fehlerhafte, aber authentisierte Zeitquelle wirkt auf alle |
| Bedienfehler, fehlerhafte Richtlinie | alle Mandanten | Wirkungsvorschau, Freigabewege, Rücknahme (INV-08, INV-11) | Nicht behoben |

```
Wirkung einer gemeinsamen Ursache, additives Modell
  U_gesamt ~ U_mehrheit + q,  q = Wahrscheinlichkeit eines gemeinsamen Ausfalls

  Schwelle, ab der q den gesamten Unterschied zwischen 3 und 5 Stimmknoten
  ueberwiegt:
      q* = (V5 - V3) = 0,999990 - 0,999702 = 2,88 x 10^-4
         = 2,88 x 10^-4 x 8.760 = 2,52 h/a

  Beispiel q = 1 x 10^-3  (8,76 h/a gemeinsame Ursache, Annahme)
      n = 3: U = 0,000298 + 0,001 = 0,001298 -> 11,37 h/a
      n = 5: U = 0,0000099 + 0,001 = 0,0010099 ->  8,85 h/a
      Unterschied weiterhin 2,52 h/a, aber der gemeinsame Anteil betraegt
      77 % bzw. 99 % der Gesamtausfallzeit
```

Die Deutung ist eine Entwurfsregel: wer 2,5 Stunden Verfügbarkeit im Jahr sucht, findet sie billiger in der Trennung der Fehlerzonen und in der gestaffelten Ausrollung als im fünften Stimmknoten. Deshalb ist 3 der Normalfall und 5 nur auf ausdrückliche Wahl (K-04). Sämtliche Zahlen dieses Abschnitts sind Rechenergebnisse aus benannten Annahmen und keine Messungen; sie sind Obergrenzen, weil das Modell die gemeinsamen Ursachen nur in der letzten Rechnung und dort nur pauschal enthält.

**Anforderungen**

- **R-16-23** — Die API lehnt jede Mitgliedschaftsänderung mit gerader Zielstimmzahl ab. Prüfbar: Mutationstest über alle Zielstimmzahlen von 1 bis 6 (INV-05).
- **R-16-24** — Ein Verbund mit zwei Standorten erreicht automatische Übernahme ausschließlich mit einem dritten Stimmknoten oder einem Zeugen; ohne diesen zeigt die Konsole dauerhaft, dass kein Knotenausfall vertragen wird. Prüfbar: Ausfalltest ohne Zeugen; automatische Übernahmen = 0, Anzeige geprüft (INV-18).

## 16.9 Ausfallerkennung und Abschottung

Eine feste Zeitgrenze für den Verdacht ist in zwei Richtungen falsch: in einem ruhigen Netz wartet sie zu lange, in einem belasteten Netz erklärt sie funktionierende Knoten für ausgefallen. Der Entwurf trennt deshalb **Verdacht** von **Sicherheit**: der Verdacht ist adaptiv, die Lease ist fest.

```
Verdachtsmass mit gleitender Statistik
  Fenster        Zielwert 200 letzte Zwischenankunftszeiten des Herzschlags
  Schaetzung     Mittelwert mu, Standardabweichung sigma aus dem Fenster
  Verdachtsmass  phi(t) = -log10( P(naechste Ankunft spaeter als t) )
  Schwelle       phi >= 8, also P <= 10^-8

  Rechnung, Normalmodell, einseitige Schranke:
    phi = 8  ->  z = 5,61
    ruhiges Netz  mu = 5 s, sigma = 0,5 s -> t = 5 + 5,61 x 0,5  =  7,81 s
    belastetes Netz mu = 5 s, sigma = 2,0 s -> t = 5 + 5,61 x 2,0 = 16,22 s
    harte Obergrenze t_max = 25 s; sie greift ab
      sigma > (25 - 5) / 5,61 = 3,57 s

  Das ist ein Modell, keine Messung. Das Normalmodell ist fuer
  Zwischenankunftszeiten nur eine Naeherung; die Verteilung ist in der
  Praxis rechtsschief, wodurch das Modell zu frueh Verdacht schoepft.
```

Die harte Obergrenze von 25 s existiert, damit der Verdacht immer **vor** der Übernahmefreigabe von 30 s (K-07) liegt. Ohne sie könnte die Statistik bei anhaltend gestörtem Netz den Verdacht hinter die Übernahme schieben und die Erkennung wirkungslos machen. Umgekehrt darf die Statistik die Lease **niemals** verkürzen: der Verdacht bestimmt, wann die Konsole eine Störung zeigt und wann die Umplanung geprüft wird, die Lease bestimmt, ob ein Knoten arbeiten darf.

| Größe | Wert | Rolle | Grundlage |
|---|---|---|---|
| Herzschlag | 5 s | Trägt die Leaseerneuerung | K-07 |
| Lease | 20 s | Hart. Ablauf erzwingt Selbstabschottung | INV-06, K-07 |
| Verdacht | adaptiv, 7,8 s bis 25 s | Weich. Steuert Anzeige und Prüfung der Umplanung | dieses Kapitel |
| Übernahmefreigabe | 30 s | Frühester Zeitpunkt, an dem eine Last anderswo starten darf | K-07 |
| Reserve | 30 s − 20 s = 10 s | Deckt Uhrenabweichung und Verarbeitungszeit | K-07 |
| Zugelassene Uhrenabweichung | ≤ 500 ms | 1/20 der Reserve | K-30, INV-32 |

Die Lease wird lokal auf einer **monotonen** Uhr geführt, nicht auf der Wanduhr. Ein Zeitsprung der Wanduhr kann eine Lease damit weder verlängern noch verkürzen. Die Wanduhrgüte wird dennoch gebraucht, und zwar für drei andere Zwecke: Ordnung der Auditereignisse, Gültigkeitsprüfung von Zertifikaten und die Bewertung, ob eine Beobachtung aktuell ist (INV-28). Ein Knoten mit unbekannter oder zu großer Abweichung führt nicht, stellt nicht aus, schreibt keine Auditereignisse und schottet sich ab (INV-32).

Die Selbstabschottung ist gegenstandsbezogen, und hier liegt eine Spannung zwischen zwei Invarianten, die aufgelöst werden muss. INV-06 verlangt, dass ein Knoten ohne gültige Lease seine Arbeitslasten beendet; INV-25 verlangt, dass ein Dienst den Ausfall der gesamten Kontrollebene überlebt und lokal neu startet. Beide gelten, weil sie verschiedene Lasten betreffen:

| Datensicherheitsstufe des Speicherbereichs | Verhalten bei Leaseablauf | Begründung |
|---|---|---|
| **Lokal** | Dienst läuft weiter und startet lokal neu | Es existiert keine zweite Kopie, also kann keine zweite Instanz gestartet werden; eine Abschaltung erzeugte einen Ausfall ohne Sicherheitsgewinn (INV-25) |
| **Gespiegelt** | Dienst wird beendet, Speicherbereich ausgehängt | Eine zweite Kopie existiert und kann nach 30 s übernommen werden; zwei Schreiber erzeugen divergente Stände |
| **Synchron gespiegelt** | Dienst wird beendet, Blockreplikationsrolle abgegeben | Wie oben, zusätzlich erzwingt das Replikationsquorum den Schreibentzug |
| Zustandslos, ohne Speicherbereich | Dienst läuft weiter | Doppelte Ausführung ist kein Datenproblem; die Erreichbarkeit hängt am Eingang, und der Eingang der abgeschotteten Seite kann seine Routen ohne Quorum nicht ändern |

Die Selbstabschottung durch den Knoten selbst ist notwendig, aber nicht hinreichend. Ein Knoten, dessen virtuelle Maschine angehalten und später fortgesetzt wird, kann glauben, seine Lease sei noch gültig, obwohl die Übernahme längst erfolgt ist. Gegenmaßnahme: der Knoten vergleicht bei jedem Leasecheck auch den Fortschritt der Wanduhr und wertet einen erkannten Sprung von mehr als 1 s als Leaseverlust. Die harte Absicherung liegt jedoch nicht beim Knoten, sondern beim Speicher: für synchron replizierte Speicherbereiche entzieht das Replikationsquorum dem zurückkehrenden Knoten das Schreibrecht, sodass er auch bei falscher Selbsteinschätzung nicht schreiben kann. Für die Stufe *Gespiegelt* existiert diese Absicherung nicht; dort wird ein divergenter Stand erkannt, benannt und als eigener Wiederherstellungspunkt aufbewahrt, aber nicht verhindert. Das ist eine Schwäche der asynchronen Stufe und der Preis dafür, dass sie ohne drittes Stimmglied auskommt.

**Anforderungen**

- **R-16-25** — Das Verdachtsmaß ist adaptiv, verkürzt die Lease unter keinen Umständen und liegt stets unterhalb der Übernahmefreigabe. Prüfbar: Simulation mit Standardabweichungen von 0,1 s bis 10 s; die Verdachtszeit bleibt in [Herzschlag, 25 s] (INV-06, K-07).
- **R-16-26** — Ein Knoten ohne gültige Lease beendet innerhalb von 1 s alle Dienste mit Speicherbereichen der Stufen *Gespiegelt* und *Synchron gespiegelt* und hängt deren Speicherbereiche aus. Prüfbar: Partitionsinjektion mit Zeitmessung (INV-06).
- **R-16-27** — Die Übernahmefrist ist stets größer als Leasefrist plus zugelassene Uhrenabweichung. Prüfbar: Simulationstest mit injizierter Partition und Uhrensprung vor jeder Freigabe (INV-06, INV-32).
- **R-16-28** — Ein zurückkehrender Knoten erhält für synchron replizierte Speicherbereiche kein Schreibrecht, bevor das Replikationsquorum es ihm zuteilt. Prüfbar: Anhalte- und Fortsetztest einer virtuellen Maschine über die Übernahmefrist hinaus; Schreibvorgänge der zurückgekehrten Instanz = 0.

## 16.10 Netzpartition

| Gegenstand | Mehrheitsseite | Minderheitsseite |
|---|---|---|
| Sollzustand | schreibbar | **Eingefroren** (INV-04) |
| Lesende Zugriffe | aktuell | aus dem lokalen Lesemodell, mit Beobachtungszeitpunkt und Versionsstand (INV-28) |
| Dienste mit Speicherbereich *Lokal* | laufen | laufen weiter, starten lokal neu (INV-25) |
| Dienste mit Speicherbereich *Gespiegelt* / *Synchron gespiegelt* | Übernahme ab 30 s, sofern Replikat vorhanden | beendet bei Leaseablauf (INV-06) |
| Zustandslose Dienste | können zusätzlich gestartet werden | laufen weiter, nur von der Minderheitsseite erreichbar |
| Zertifikatsausstellung und -erneuerung | möglich | unmöglich; Ausstellung ist ein Protokolleintrag (INV-22) |
| Auditereignisse | geschrieben und versiegelt | lokal gepuffert, nach Zusammenführung angehängt, Kette bleibt prüfbar (INV-23) |
| Neue Kopplungen | möglich | abgelehnt mit benanntem Grund |
| Löschungen und Verlagerungen | möglich | abgelehnt (INV-04) |

Die Minderheitsseite gibt sich nicht als gesund aus. Sie zeigt dauerhaft, dass Änderungen gesperrt sind, seit wann, welcher Sollzustandsstand zuletzt bestätigt wurde und welche Vorgänge deshalb warten. Der Konsolentext nennt weder Quorum noch Raft (INV-16); er beschreibt die Lage als fehlende Verbindung zur Mehrheit der Verwaltungsknoten und nennt als Weg zurück die Wiederherstellung der Verbindung, nicht einen Befehl (INV-17). Auf der Mehrheitsseite erscheint die Gegenseite als Störung mit Knotenliste, Zeitpunkt des letzten Herzschlags, laufender Übernahmefrist und der Angabe, welche Dienste übernommen werden und welche nicht — Letzteres mit Begründung, weil "wird nicht übernommen" ohne Grund eine unbrauchbare Meldung ist.

Eine Partition, die länger dauert als das Erneuerungsfenster der Zertifikate, führt auf der Minderheitsseite zum TLS-Ausfall. **Rechnung:** Dienstzertifikate laufen 90 d, die Erneuerung beginnt ab Tag 60 (K-13); das Fenster beträgt 90 − 60 = 30 d. Eine Partition bis 30 Tage ist überstehbar, eine längere nicht. Das ist kein Entwurfsfehler, sondern die Folge davon, dass die Ausstellung ein quorumpflichtiges Ereignis ist; die Alternative wäre ein Ausstellungsrecht ohne Quorum, und das wäre eine Hintertür in die PKI.

### Zusammenführung nach dem Ende der Partition

```
1. Verbindung wieder hergestellt, Herzschlag stabil
2. Minderheitsseite gleicht das Aenderungsprotokoll ab.
   Sie hatte keine bestaetigten Eintraege, weil sie nicht beschlussfaehig war;
   nicht bestaetigte Eintraege werden verworfen.
3. Lesemodell wird aus dem Protokoll neu materialisiert (deterministisch).
4. Gepufferte Auditereignisse werden angehaengt; die Hashkette wird geprueft.
5. Karenzzeit: Zielwert 10 min stabiler Herzschlag und gueltige Lease,
   bevor der Knoten wieder Platzierungsziel wird (16.11).
6. Speicherbereiche:
   Synchron gespiegelt -> Wiederangleich aus der Mehrheitskopie, gedrosselt
   Gespiegelt          -> falls beide Seiten geschrieben haben, entsteht ein
                          Objekt "divergierender Stand"; die Minderheitskopie
                          wird als schreibgeschuetzter Wiederherstellungspunkt
                          fuer die Aufbewahrungsfrist (K-25: 30 d) gehalten
                          und nur durch einen ausdruecklichen Vorgang verworfen
   Lokal               -> keine Zusammenfuehrung noetig
```

Die Behandlung der Wiederherstellungspunkte und der Replikationsstände im Einzelnen steht in [Kapitel 17](17-speicher-backup.md). Atrium führt Anwendungsdaten **nicht** zusammen. Es kann nicht wissen, ob zwei divergente Stände eines Datenbankspeicherbereichs fachlich vereinbar sind. Der Entwurf leistet Erkennung, Benennung und Aufbewahrung; die Entscheidung trifft ein Mensch, und eine stillschweigende Verwerfung findet nicht statt (INV-11).

**Anforderungen**

- **R-16-29** — Auf der Minderheitsseite werden alle schreibenden Operationen am Sollzustand abgelehnt, alle lesenden Ansichten tragen Beobachtungszeitpunkt und Versionsstand, und die Sperre ist dauerhaft sichtbar. Prüfbar: Partitionstest über alle API-Operationen (INV-04, INV-28, INV-18).
- **R-16-30** — Ein divergenter Stand eines asynchron gespiegelten Speicherbereichs wird als eigenes Objekt geführt und für die Aufbewahrungsfrist schreibgeschützt gehalten; automatische Verwerfungen = 0. Prüfbar: Partitionstest mit Schreiblast auf beiden Seiten (INV-11, K-25).
- **R-16-31** — Der Konsolentext beider Seiten enthält keinen der in KANON 1 verbotenen Begriffe und verweist nicht auf ein Kommandozeilenwerkzeug. Prüfbar: Begriffsprüfung gegen die Positivliste und Musterprüfung auf Befehlsangaben (INV-16, INV-17).

## 16.11 Umplanung

| Fall | Wird verschoben | Begründung |
|---|---|---|
| Knoten hat die Übernahmefrist überschritten, Replikat vorhanden | ja, automatisch | Verfügbarkeitszusage K-08/K-09 |
| Knoten wird geräumt | ja, geordnet, nacheinander | Räumen ist eine sichtbare eigene Handlung (KANON 4.2a) |
| Zwangsbedingung verletzt (Antiaffinität, Fehlerzone, M3-Exklusivität, Budget) | ja | Die Platzierung ist sonst unzulässig |
| Knoten ist verdächtig, Lease noch gültig | nein | Die Lease ist die Entscheidungsgrundlage, nicht der Verdacht |
| Reine Lastungleichverteilung | nein | Eine Verschiebung kostet Datenbewegung und Unterbrechung; die Bewertungsfunktion selbst steht in [Kapitel 15](15-dienste-software.md) |
| Besserer Bewertungswert unterhalb der Schwelle | nein | Siehe Flatterschutz |
| Minderheitsseite einer Partition | nein | Keine Verlagerung ohne Quorum (INV-04) |
| Speicherbereich der Stufe *Lokal* | nein, nicht automatisch | Es existiert keine zweite Kopie; die Verschiebung ist ein Vorgang mit ausdrücklicher Entscheidung über die Daten (INV-11) |

```
Flatterschutz
  Verschiebeschwelle   Score_neu - Score_alt > 0,20 bei auf [0,1] normierter
                       Bewertung. Ein marginal besseres Ziel loest nichts aus.
  Karenzzeit           Zielwert 10 min stabiler Herzschlag und gueltige Lease,
                       bevor ein zurueckgekehrter Knoten Platzierungsziel wird
  Ruecknahme-Backoff   Wiederholtes Kommen und Gehen verlaengert die Karenzzeit
                       10 -> 20 -> 40 min, Obergrenze 4 h, Ruecksetzung nach
                       24 h stabilem Betrieb
  Sperrfrist je Dienst Zielwert 30 min nach einer Verschiebung; Ausnahme nur bei
                       verletzter Zwangsbedingung
  Obergrenze           Zielwert 3 automatische Verschiebungen je Knoten und
                       Stunde; darueber hinaus keine weiteren Verschiebungen,
                       sondern eine benannte Stoerung
```

```
Drosselung der Datenbewegung
  Annahme  Speichernetz 1 Gbit/s = 125 MB/s
  Annahme  30 % Reserve fuer Produktionsverkehr
  Budget   0,7 x 125 MB/s = 87,5 MB/s je Knoten

  Ein Speicherbereich von 200 GB:
      200.000 MB / 87,5 MB/s = 2.286 s = 38,1 min
  Zehn solche Bereiche nacheinander:
      10 x 38,1 min = 381 min = 6,35 h
  Zielwert: hoechstens 2 gleichzeitige Datenbewegungen je Knoten, damit die
  Einzeldauer vorhersagbar bleibt und der Produktionsverkehr nicht verdraengt
  wird. Die Konsole zeigt die berechnete Restdauer je Bereich.
```

Während des Wiederangleichs ist die Redundanz nicht wiederhergestellt. Die Konsole zeigt das als degradierten Zustand mit Restdauer an, nicht als laufende Aufgabe ohne Zustandsangabe (INV-18). Die Reihenfolge der Umplanung ist deterministisch und erklärbar:

```
Reihenfolge der Umplanung
  1. Dienste ohne verbleibende Datenreplik            (Datenverfuegbarkeit)
  2. Dienste, von denen andere Dienste abhaengen      (Datenbank vor Anwendung)
  3. Veroeffentlichte Dienste vor rein internen
  4. Aelteste Stoerung zuerst
  5. Gleichstandsbruch: kleinere Dienst-ULID zuerst   (deterministisch)
```

**Anforderungen**

- **R-16-32** — Eine automatische Verschiebung erfolgt nur, wenn der Bewertungsvorteil die Verschiebeschwelle überschreitet oder eine Zwangsbedingung verletzt ist; die Zahl automatischer Verschiebungen je Knoten und Stunde ist auf den Zielwert begrenzt. Prüfbar: Flattertest mit periodisch ausfallendem Knoten über 24 h; die Verschiebungszahl bleibt unter der Grenze.
- **R-16-33** — Ein Dienst mit Speicherbereich der Stufe *Lokal* wird nie automatisch verschoben; eine Verschiebung ist ein Vorgang mit ausdrücklicher Entscheidung über die Daten. Prüfbar: Knotenausfall mit lokal gespeichertem Dienst; automatische Verschiebungen = 0 (INV-11).
- **R-16-34** — Jede laufende Datenbewegung zeigt die berechnete Restdauer und den nicht wiederhergestellten Redundanzzustand an. Prüfbar: Darstellungstest während eines Wiederangleichs (INV-18).

## 16.12 Skalierungs- und Latenzgrenzen, geografische Verteilung

Die Knotenhöchstzahl von 32 folgt aus [Kapitel 05](05-systemarchitektur.md); die Vollvermaschung des Overlays umfasst dann 32 · 31 / 2 = 496 Tunnel. Der Konsensverkehr ist dabei nicht bindend. Bindend werden Latenz und Datenbewegung, sobald Knoten geografisch verteilt liegen.

```
Ausbreitung im Lichtwellenleiter: v ~ 2 x 10^8 m/s

  100 km Faserweg   -> 0,5 ms je Richtung, 1,0 ms Umlauf (reine Ausbreitung)
  1.000 km Faserweg -> 5,0 ms je Richtung, 10,0 ms Umlauf
  Annahme: Vermittlung und Warteschlangen verdoppeln den Umlauf
  -> 1.000 km ergeben rund 20 ms Umlauf

Bestaetigungsrate des Aenderungsprotokolls ueber 1.000 km
  Annahme: Schreibsicherung auf dem Datentraeger 1 ms
  Bestaetigungszeit = 20 ms + 1 ms = 21 ms
  seriell: 1 / 0,021 = 47,6 bestaetigte Aenderungen je Sekunde
  Zielwert aus Kapitel 05: >= 50 je Sekunde -> nicht erreicht
  Mit Buendelung von 32 Aenderungen je Eintrag: 47,6 x 32 = 1.523 je Sekunde
  Preis: bis zu 21 ms zusaetzliche Latenz fuer die einzelne Aenderung
```

Der Konsens ist damit über 1.000 km betreibbar, sofern Änderungen gebündelt werden. Die härtere Grenze liegt bei der synchronen Blockreplikation, weil jede Schreibbestätigung eines Dienstes den Umlauf trägt:

```
Synchrone Replikation ueber die Strecke
  Bei 20 ms Umlauf begrenzt jede fsync-abhaengige Anwendung auf
      1 / 0,020 = 50 serielle Schreibbestaetigungen je Sekunde.
  Eine Datenbank mit Transaktionsprotokoll erreicht damit hoechstens
  50 Transaktionen je Sekunde seriell.

Ableitung der Entfernungsgrenze
  Zielwert: Umlauf <= 2 ms fuer Speicherbereiche der Stufe
  "Synchron gespiegelt".
  Davon Annahme 1 ms fuer Vermittlung -> 1 ms reine Ausbreitung im Umlauf
  -> 0,5 ms je Richtung -> 0,5 x 10^-3 s x 2 x 10^8 m/s = 100 km Faserweg.
  Jenseits von rund 100 km ist nur noch "Gespiegelt" (asynchron, RPO <= 15 min
  nach K-09) sinnvoll.
```

| Verteilungsmuster | Zulässig | Grenze |
|---|---|---|
| Ein Standort, mehrere Fehlerzonen | ja | Empfohlener Normalfall |
| Zwei Standorte innerhalb rund 100 km, dritter Standort nur mit dem Zeugen | ja | *Synchron gespiegelt* bleibt erfüllbar; der Zeuge darf weit entfernt liegen |
| Zwei Standorte über 100 km | ja, mit *Gespiegelt* | RPO ≤ 15 min statt 0 |
| Verwaltungsknoten über mehr als 1.000 km | eingeschränkt | Bündelung erforderlich; Einzeländerungslatenz steigt auf rund 21 ms |
| Mehrere unabhängige Regionen mit je eigener Kontrollebene | nicht Teil dieses Entwurfs | Eine zweite Kontrollebene ist eine zweite Installation, kein Verteilungsmuster |

Der Zeuge ist der Grund, weshalb ein dritter Standort mit schlechter Anbindung nutzbar bleibt. Bei drei Stimmknoten beträgt die Mehrheit zwei, und der Führer ist selbst einer davon; er benötigt genau eine weitere Bestätigung und nimmt die des nächstgelegenen Knotens. Ein Zeuge mit einem Umlauf von bis zu 100 ms (Zielwert) verlangsamt den Nennbetrieb deshalb nicht — er wird erst gebraucht, wenn einer der beiden Hauptstandorte fehlt, und dann bestimmt seine Latenz die Bestätigungszeit. Das ist ein echter, rechnerisch belegbarer Vorteil der Zeugenrolle gegenüber einem vollwertigen dritten Standort.

Die Grenze der geografischen Verteilung ist damit nicht technisch scharf, sondern das Ergebnis von drei Zusagen, die gleichzeitig gelten sollen: RPO 0 bei *Synchron gespiegelt* (K-08), ≥ 50 bestätigte Änderungen je Sekunde und eine Bedienoberfläche, deren Vorschau in ≤ 2 s antwortet (K-18). Wer eine dieser Zusagen aufgibt, kann weiter verteilen. Der Entwurf gibt keine auf und begrenzt stattdessen die Entfernung.

## 16.13 Die Übersicht "wo läuft was"

Die Übersicht ist eine Verbindung aus Sollzustand und Istzustand und **kein** eigener Speicher. Aus dem Sollzustand kommen Platzierung, Rollenmenge, Replikatstandorte und Datensicherheitsstufe; aus dem Istzustand kommen Beobachtung und Beobachtungszeitpunkt. Ein eigener Speicher wäre eine dritte Wahrheitsquelle neben Soll und Ist und verstieße gegen INV-02.

| Zustand je Paar (Dienst, Knoten) | Bedeutung | Darstellung |
|---|---|---|
| geplant | Sollzustand nennt den Knoten, es liegt noch keine Beobachtung vor | neutral, mit Wartezeit |
| wird bereitgestellt | Abbild wird geholt oder Speicherbereich eingebunden | Fortschritt mit Restdauer |
| läuft | Beobachtung bestätigt den Sollzustand | normal |
| läuft mit Abweichung | Beobachtung weicht vom Sollzustand ab | Abweichung benannt, Quelle verlinkt (INV-28) |
| wird verschoben | Umplanung aktiv | Quelle, Ziel, Restdauer der Datenbewegung |
| angehalten | ausdrücklich angehalten | mit Urheber und Vorgang |
| **unbekannt** | keine Beobachtung seit mehr als zwei Beobachtungsintervallen | ausdrücklich als unbekannt, **nie** als laufend |

| Zustand je Knoten | Bedeutung |
|---|---|
| produktiv | Lease gültig, Herzschlag im Fenster |
| im Verdacht | Verdachtsmaß über der Schwelle, Lease noch gültig |
| abgeschottet | Lease abgelaufen, Lasten beendet (INV-06) |
| eingefroren | Ohne Verbindung zur Mehrheit, Sollzustand gesperrt (INV-04) |
| wird geräumt | Geordnete Verlagerung läuft |
| nicht erreichbar | Kein Herzschlag, Übernahmefrist abgelaufen |

Der Zustand *unbekannt* ist der einzige, der die Übersicht ehrlich macht. Ein System, das eine fehlende Beobachtung als "läuft" darstellt, meldet Gesundheit genau dann, wenn es nichts weiß. Deshalb gilt: jede Ansicht nennt ihren Beobachtungszeitpunkt (INV-28), und eine überalterte Beobachtung wechselt den Zustand, statt den alten Wert weiterzuzeigen.

```
Dauerhaft sichtbare Degradation (INV-18)
  f_stimme = floor((s - 1) / 2)                 s = Zahl der Stimmknoten
  f_daten  = min ueber alle Speicherbereiche der vertragenen Ausfaelle,
             die sich aus Replikatzahl und Replikationsquorum ergeben
  k        = min(f_stimme, f_daten)             angezeigte vertragene Ausfaelle

  Beispiele
    1 Knoten, Lokal                  s=1 f_stimme=0  f_daten=0  -> k=0
                                     Anzeige: "Redundanz: keine"
    2 Knoten + Zeuge, synchron       s=3 f_stimme=1  f_daten=1  -> k=1
    3 Knoten, synchron 3 Replikate   s=3 f_stimme=1  f_daten=1  -> k=1
    5 Stimmknoten, 3 Replikate       s=5 f_stimme=2  f_daten=1  -> k=1
```

Die letzte Zeile ist der quantitative Beleg für die Aussage aus 16.7: zwei zusätzliche Stimmknoten heben die vertragene Ausfallzahl nicht, wenn die Datenachse bei eins bleibt. Die Übersicht zeigt deshalb nicht nur `k`, sondern auch, welche der beiden Achsen begrenzt, und verlinkt auf das begrenzende Objekt — bei der Datenachse auf den Speicherbereich mit der kleinsten Toleranz.

Die Darstellungsregeln des Überblicks im Übrigen stehen in [Kapitel 18](18-bedienkonzept.md). Ein degradierter Zustand endet, wenn die Bedingung endet, nicht wenn jemand ihn bestätigt. Es existiert keine Quittierung, die eine Degradation ausblendet. Das ist unbequem und beabsichtigt: eine ausblendbare Degradationsanzeige wird ausgeblendet und ist danach wirkungslos.

Die Grenze der Übersicht ist die Produktgrenze (INV-30). Sie zeigt, wo ein Dienst läuft, ob er startet, ob sein Speicherbereich repliziert ist und ob seine Beobachtung frisch ist. Sie zeigt **nicht**, warum ein Fremdprodukt intern langsam ist; das steht in dessen eigener Oberfläche. Die Übersicht enthält außerdem keine Kennzahl ohne Handlungsbezug (KANON 5); eine Auslastungskurve ohne verlinktes Objekt gehört nicht hierher.

**Anforderungen**

- **R-16-35** — Die Übersicht wird ausschließlich aus Sollzustand und Istzustand berechnet; ein eigener persistenter Speicher für Platzierungsanzeigen existiert nicht. Prüfbar: Datenmodellprüfung im Bau (INV-02).
- **R-16-36** — Eine Beobachtung, die älter als zwei Beobachtungsintervalle ist, führt zum Zustand *unbekannt*; die Zahl der als laufend dargestellten Dienste ohne frische Beobachtung ist 0. Prüfbar: Beobachtungsausfall mit Darstellungsprüfung (INV-28).
- **R-16-37** — Die Übersicht zeigt dauerhaft `k` als Minimum aus Stimm- und Datenachse sowie die begrenzende Achse mit verlinktem Objekt. Prüfbar: Zustandsmatrixtest über alle Kombinationen aus Stimmzahl und Datensicherheitsstufe (INV-18).
- **R-16-38** — Es existiert keine Bedienhandlung, die eine Degradationsanzeige ausblendet. Prüfbar: Endpunktprüfung; Operationen zum Quittieren von Degradationszuständen = 0 (INV-18).

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Der Konsens- und Platzierungspfad erreicht in einer Ein-Knoten-Installation ≥ 95 % der im Verbund ausgeführten Zweige | R-16-01 | Abdeckungsmessung im Bau, Vergleich beider Läufe |
| Der Übergang 1 → 3 Stimmknoten erzeugt 0 abgelehnte Schreibvorgänge außerhalb der Schreibpause nach K-05 | R-16-02 | Mitgliedschaftsänderung unter fortlaufender Schreiblast |
| Jede Rollenmenge außer Zeuge mit Dienst- oder Speicherlast wird akzeptiert; diese Kombination wird abgelehnt | R-16-03, R-16-05 | Kombinationstest über alle Rollenmengen |
| Nach Abschaltung des Ankerknotens bleiben alle Standardaufgaben ausführbar | R-16-04 | Abschalttest mit vollständigem Aufgabenkatalog |
| 8403/tcp antwortet in jedem Knotenzustand außer Wartemodus nicht und ist außerhalb des lokalen Segments nicht erreichbar | R-16-06 | Portabtastung über alle Knotenzustände und aus einem fremden Segment |
| Ein zum zweiten Mal verwendeter Kopplungscode erzeugt 0 Zertifikate; 5 fehlgeschlagene Bestätiger vernichten den Code; 5 Abbrüche nach erfolgreichem Bestätiger vernichten ihn nicht | R-16-07 | Wiederverwendungs-, Raten- und Abbruchtest |
| Ein Zwischenangriff mit beidseitiger TLS-Terminierung erzeugt 0 Knotenzertifikate | R-16-08 | Angriffssimulation mit vorgeschalteter Gegenstelle |
| Ein CSR mit fremdem SAN führt zu einem Zertifikat, das den fremden Namen nicht enthält | R-16-09 | Ausstellungstest mit manipuliertem CSR |
| Absturzinjektion zwischen Ausstellung und Vorgangsverbrauch erzeugt 0 Zustände mit gültigem Zertifikat und unverbrauchtem Kopplungsvorgang | R-16-10 | Fehlerinjektion an der Führung über 1.000 Läufe |
| Die Antwortzeiten bei 0, 5 und 9 übereinstimmenden Codezeichen sind statistisch nicht unterscheidbar | R-16-11 | Zeitmessung über 10.000 Läufe je Gruppe |
| Ein Kopplungsversuch auf der Minderheitsseite endet abgelehnt, mit benanntem Grund und ohne Befehlsangabe im Text | R-16-12, R-16-31 | Partitionstest mit Textprüfung |
| Beide Bildschirme zeigen die Gegenstellenkennung vor Abschluss der Kopplung | R-16-13 | Darstellungstest beider Seiten |
| Jede erfolgreiche und jede fehlgeschlagene Kopplung erzeugt genau ein Auditereignis mit Aufnahmeweg | R-16-14 | Auditstromprüfung nach beiden Ausgängen |
| Ein Aufnahmetoken ohne Quellpräfixliste wird abgelehnt; ein zweites Mal verwendetes Token erzeugt 0 Zertifikate | R-16-15 | Ausstellungs- und Wiederverwendungstest |
| Bei abweichender Zertifikatskette überträgt der aufzunehmende Knoten 0 Bytes des Tokens | R-16-16 | Angriffssimulation mit fremder Kontrollebene |
| Knoten mit `aufnahmeweg: token` erhalten 0 automatische Beförderungen zum Stimmknoten | R-16-17 | Rollenvergabetest über alle Aufnahmewege |
| `aufnahmeweg` ist über die API nicht schreibbar und wird am Knoten und im Überblick angezeigt | R-16-18 | Schreibversuch und Darstellungstest |
| Das Formular "Knoten aufnehmen" enthält genau 2 Pflichtfelder; jedes vorbelegte Feld nennt seine Quelle | R-16-19, R-16-22 | Abgleich der Formulardefinition gegen die Aufgabendefinition im Bau |
| Über die Knotenzahlen 1 bis 6 und alle drei Antworten entsteht 0-mal eine gerade Zielstimmzahl | R-16-20, R-16-23 | Aufnahme- und Mutationstest |
| Nach jedem Rollendialog entspricht die angezeigte Zahl vertragener Ausfälle dem Minimum aus Stimm- und Datenachse | R-16-21, R-16-37 | Zustandsmatrixtest über Stimmzahl × Datensicherheitsstufe |
| Ohne dritten Stimmknoten oder Zeugen erfolgen bei Ausfall eines von zwei Knoten 0 automatische Übernahmen, und die Anzeige nennt 0 vertragene Ausfälle | R-16-24 | Ausfalltest im Zwei-Knoten-Aufbau |
| Die Verdachtszeit liegt bei Standardabweichungen von 0,1 s bis 10 s stets zwischen Herzschlag und 25 s und verkürzt die Lease nie | R-16-25 | Simulation über den Parameterbereich |
| Ein Knoten ohne Lease beendet binnen 1 s alle Dienste mit gespiegelten Speicherbereichen | R-16-26 | Partitionsinjektion mit Zeitmessung |
| Bei injiziertem Uhrensprung bleibt die Übernahmefrist größer als Lease plus Uhrenabweichung | R-16-27 | Simulationstest vor jeder Freigabe |
| Eine über die Übernahmefrist hinaus angehaltene und fortgesetzte virtuelle Maschine führt 0 Schreibvorgänge auf synchron replizierten Bereichen aus | R-16-28 | Anhalte- und Fortsetztest |
| Auf der Minderheitsseite werden 100 % der schreibenden Operationen abgelehnt und 100 % der lesenden Ansichten mit Beobachtungszeitpunkt versehen | R-16-29 | Partitionstest über alle API-Operationen |
| Beidseitige Schreiblast während einer Partition erzeugt ein Objekt "divergierender Stand" und 0 automatische Verwerfungen | R-16-30 | Partitionstest mit Schreiblast |
| Ein periodisch ausfallender Knoten erzeugt über 24 h weniger Verschiebungen als die stündliche Obergrenze | R-16-32 | Flattertest |
| Der Ausfall eines Knotens mit lokal gespeichertem Dienst erzeugt 0 automatische Verschiebungen | R-16-33 | Ausfalltest mit Datensicherheitsstufe *Lokal* |
| Jede laufende Datenbewegung zeigt Restdauer und nicht wiederhergestellte Redundanz | R-16-34 | Darstellungstest während des Wiederangleichs |
| Die Übersicht besitzt keinen eigenen persistenten Speicher | R-16-35 | Datenmodellprüfung im Bau |
| Nach Ausbleiben der Beobachtung wechselt der Zustand binnen zweier Beobachtungsintervalle auf *unbekannt*; als laufend dargestellte Dienste ohne frische Beobachtung = 0 | R-16-36 | Beobachtungsausfall mit Darstellungsprüfung |
| Die API kennt 0 Operationen zum Quittieren eines Degradationszustands | R-16-38 | Endpunktprüfung gegen die öffentliche API |

## Offene Punkte

1. **Die Auswahl bei mehreren wartenden Knoten kostet eine dritte Entscheidung.** Der Entwurf nimmt das hin und benennt es (16.3.2). Die Alternativen sind alle schlechter: eine aus dem Code abgeleitete Sitzungskennung öffnet ein Offline-Wörterbuch, eine Adresseingabe ist ebenfalls eine dritte Entscheidung, und eine Vorauswahl nach Ankündigungszeitpunkt ist eine Vermutung, die in genau dem Fall falsch liegt, in dem zwei Knoten gleichzeitig installiert werden. Ob die Standardaufgabe stattdessen auf "ein wartender Knoten je Segment" eingeschränkt wird, ist nicht entschieden.

2. **Die Argon2id-Parameter sind nicht festgelegt.** Sie müssen auf der kleinsten unterstützten Knotenklasse kalibriert werden, sind Teil der Protokollversion und dürfen nicht ausgehandelt werden. Vor dieser Kalibrierung ist der Zielwert von 500 ms je Ableitung eine Vorgabe ohne Beleg, und die Gesamtzusage aus K-02 (Kopplung ≤ 60 s) hängt daran mit.

3. **Für die Fernkonsole eines Mietservers existiert keine Lösung, sondern nur eine Verlagerung.** Das Aufnahmetoken nimmt den Code vom Bildschirm, legt dafür aber ein Inhabergeheimnis in eine Ablage, die derselbe Anbieter kontrolliert. Ob die Konsequenz lautet, dass Mietserver ohne dedizierten TPM-Zugang für Verwaltungsknoten und für Mandanten der Stufen M2 und M3 ausgeschlossen werden, ist eine Produktentscheidung und noch nicht getroffen.

4. **Die Fehlerzone wird vermutet, nicht gemessen.** Zwei Maschinen im selben Rack an verschiedenen Zugangsschaltern erscheinen als zwei Fehlerzonen. Jede Antiaffinitätsregel und jede Verfügbarkeitsrechnung dieses Kapitels steht auf dieser Vermutung. Ein belastbares Verfahren — etwa die Auswertung von Nachbarschaftsmeldungen der Netzgeräte oder eine Pflichtangabe bei der Aufnahme — ist nicht gewählt; die Pflichtangabe kostet eine Entscheidung und kollidiert mit K-03.

5. **Die Abschottung bei der Stufe *Gespiegelt* ist unvollständig.** Ein zurückkehrender Knoten kann dort einen divergenten Stand erzeugt haben, und der Entwurf erkennt und bewahrt ihn, verhindert ihn aber nicht. Ob die Stufe *Gespiegelt* deshalb einen leichtgewichtigen Schreibentzug erhält (etwa über einen quorumgebundenen Marker im Speicherbereich), oder ob die Divergenz als Eigenschaft der Stufe stehenbleibt, ist offen.

6. **Das Normalmodell des Verdachtsmaßes ist die falsche Verteilung.** Zwischenankunftszeiten von Herzschlägen sind rechtsschief; ein Normalmodell schöpft deshalb systematisch zu früh Verdacht. Die übliche Alternative ist eine Exponential- oder Lognormalanpassung, die aber eine Parameterschätzung mit mehr Aufwand und einer eigenen Anlaufphase verlangt. Welches Modell mit welchem Fenster benutzt wird, ist vor der ersten Messung nicht entscheidbar.

7. **Der Zielwert von ≥ 50 bestätigten Änderungen je Sekunde ist über 1.000 km nur mit Bündelung erreichbar.** Bündelung erhöht die Latenz einer Einzeländerung und damit die Antwortzeit der Wirkungsvorschau. Ob die Bündelgröße fest (32) oder lastabhängig ist und wie sie mit dem Zielwert aus K-18 verrechnet wird, ist nicht entschieden.

8. **Die Zusammenführung von Anwendungsdaten nach einer Partition bleibt Handarbeit.** Atrium erkennt und bewahrt, es verschmilzt nicht. Für Katalogeinträge mit mehreren schreibenden Instanzen — insbesondere Datenbankdienste — ist damit im Partitionsfall ein manueller Eingriff vorgesehen, der die Zusage "Bedienung ohne Terminal" auf den Fachdienst abwälzt. Ob der Katalogeintrag je Produkt eine Zusammenführungsvorschrift deklarieren muss, ist offen.

9. **Die Konsolenwörter für Dienstträger und Speicherträger fehlen im Kanon.** Bis sie dort eingetragen sind, kann die Oberfläche die Rollen nicht benennen, ohne eine Festlegung vorwegzunehmen. Das ist eine formale, aber blockierende Lücke.
