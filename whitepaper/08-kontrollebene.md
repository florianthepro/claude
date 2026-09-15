# 08 Kontrollebene: Sollzustand, Abgleich, Vorgänge

## 8.1 Warum das Protokoll autoritativ ist und die Tabelle nicht

atrium-core hält den Sollzustand als hashverkettetes Änderungsprotokoll und leitet daraus ein lokales Lesemodell ab. Die Richtung ist nicht umkehrbar: das Protokoll ist die Quelle, das Lesemodell ist ein Zwischenergebnis. Diese Festlegung folgt aus vier Eigenschaften, die eine Tabelle als Wahrheitsquelle nicht besitzt.

| Eigenschaft | Protokoll als Quelle | Tabelle als Quelle |
|---|---|---|
| Replikation | Der Konsens ordnet Einträge; jedes Mitglied wendet dieselbe Folge an | Der Konsens müsste Zeilenzustände abgleichen; jede Abweichung ist unauflösbar, weil die Ursache fehlt |
| Nachweis | Jede Änderung trägt Urheber, Vorgang und Vorgängerhashwert; eine nachträgliche Einfügung bricht die Kette | Eine geänderte Zeile trägt keinen Beleg ihrer Vorgeschichte |
| Schemaänderung | Das Lesemodell wird verworfen und neu berechnet (INV-24) | Eine Migration ändert die Wahrheitsquelle selbst und ist nicht folgenlos rückrollbar |
| Wiederherstellung | Momentaufnahme plus Nachlauf rekonstruiert jeden Zwischenstand | Nur der letzte Stand existiert |

Ein Protokolleintrag ist eine Menge von Mutationen, die gemeinsam bestätigt werden. Er enthält keine Ausdrücke, keine Uhrbezüge und keine Zufallswerte, weil jedes dieser Elemente die Materialisierung von der Umgebung abhängig machen würde.

```
Protokolleintrag {
  index            : u64            // lueckenlos, streng monoton je Konsensgruppe
  amtszeit         : u64            // Raft-Amtszeit des annehmenden Fuehrers
  zeit             : Zeitpunkt      // aus gepruefter Quelle; Anzeige, nie Eingabe
  vorgang          : ULID           // Korrelationskennung
  schema_version   : "MAJOR.MINOR"
  mutationen       : [ Mutation ]   // geordnet, gemeinsam bestaetigt
  vorgaenger_hash  : [32]u8         // SHA-2 ueber die kanonische Form von Eintrag(index-1)
  eintrag_hash     : [32]u8         // SHA-2 ueber die kanonische Form ohne dieses Feld
}

Mutation {
  art       : anlegen | setzen | entfernen
  objekt    : URN                   // urn:atrium:<typ>:<ulid>
  feld_pfad : Text                  // nur bei "setzen"
  wert      : Konstante             // kein Ausdruck, kein "jetzt", kein Zufallswert
}
```

Die kanonische Serialisierung folgt RFC 8785, die Hashfunktion FIPS 180-4. Beides ist notwendig, damit zwei Knoten über denselben Eintrag denselben Hashwert bilden; ohne kanonische Form entscheidet die Feldreihenfolge der Serialisierungsbibliothek über die Kettenprüfung.

Die Materialisierung ist eine reine, totale Funktion über Momentaufnahme und Protokollpräfix:

```
funktion materialisiere(momentaufnahme M, eintraege E[a..b]) -> Lesemodell
  L := lade(M)                                     // M traegt index, ketten_hash, schema_version
  fuer eintrag in E aufsteigend nach index:
      wenn eintrag.vorgaenger_hash != hash(vorheriger): ABBRUCH "Kettenbruch"
      wenn eintrag.schema_version ausserhalb Kompatibilitaetsfenster: ABBRUCH "Schema"
      fuer mutation in eintrag.mutationen in Listenreihenfolge:
          wende_an(L, mutation)                    // ohne Uhr, ohne Zufall, ohne Ortszeit,
                                                   // ohne Gleitkomma, ohne Hashtabellen-Iteration
      L.index := eintrag.index
  L.zustands_hash := hash(kanonische_form(L))
  gib L zurueck
```

Das Lesemodell wird jederzeit neu erzeugt, indem es gelöscht und aus der jüngsten lokalen Momentaufnahme plus Protokollnachlauf neu berechnet wird. Das ist der Normalweg bei einem Schemawechsel des Lesemodells, bei einem Indexfehler und bei jedem Verdacht auf Divergenz. **Rechnung:** Annahme 15.000 Objekte (K-12), Annahme 20 µs Anwendungszeit je Mutation einschließlich Indexpflege, Annahme 1,5 Mutationen je Objekt beim Vollaufbau: 15.000 · 1,5 · 20 µs = 0,45 s. Der Zielwert für den Neubau liegt bei ≤ 60 s und enthält damit einen Faktor über 100 an Reserve für Indexerzeugung, Plattenzugriff und größere Installationen. Das ist eine Rechnung aus Annahmen, keine Messung.

Die Schwäche dieses Entwurfs liegt im Determinismus. Er ist eine Eigenschaft des Codes, nicht des Datenmodells, und statisch nicht vollständig prüfbar: eine einzige Iteration über eine Hashtabelle mit zufälliger Anfangsbelegung genügt, um zwei Knoten auseinanderlaufen zu lassen, ohne dass ein Test das zwangsläufig aufdeckt. Der Entwurf mildert das, statt es zu lösen: jeder Verwaltungsknoten bildet an jedem Momentaufnahmepunkt den Zustandshashwert des Lesemodells und meldet ihn; eine Abweichung zwischen Knoten ist ein Alarm mit sofortigem Neubau des abweichenden Lesemodells. Erkennung ersetzt keine Verhinderung.

**Anforderungen**

- **R-08-01** — Zwei Verwaltungsknoten bilden aus demselben Protokollpräfix denselben Zustandshashwert. Prüfbar: Hashvergleich an jedem Momentaufnahmepunkt im Integrationstest; eine Abweichung bricht den Bau.
- **R-08-02** — Das Lesemodell ist ohne Datenverlust löschbar und aus Momentaufnahme plus Protokoll neu erzeugbar; der Zustandshashwert nach dem Neubau ist identisch. Zielwert Neubauzeit ≤ 60 s bei 15.000 Objekten. Prüfbar: Neubautest mit Hashvergleich.
- **R-08-03** — Jeder Protokolleintrag trägt den Hashwert seines Vorgängers; die Kette wird bei jedem Start und bei jedem Export geprüft und ein Bruch gemeldet, nicht repariert. Prüfbar: Injektion eines veränderten Eintrags.
- **R-08-04** — Eine Mutation mit nicht konstantem Wert wird beim Anhängen abgelehnt. Prüfbar: Schemaprüfung am Protokolleingang mit Zufallsdatentest.

## 8.2 Der Reconciler

Der Reconciler läuft ausschließlich auf dem Raft-Führer mit gültiger Führungs-Lease. Er kennt zwei Pfade: einen ereignisgetriebenen für jede bestätigte Sollzustandsänderung und einen periodischen Volllauf als Driftpfad (K-16).

```
schleife reconciler(abgleichgruppe G):          // G = eine Konnektorbindung oder ein Knoten
  solange fuehrungs_lease_gueltig():
     aufgabe := warteschlange.entnehmen_oder_intervall(G)
     soll    := lesemodell.projiziere(aufgabe.geltungsbereich)
     ist     := observe(aufgabe.ziel, aufgabe.geltungsbereich)       // nur lesend, datiert
     diff    := differenz(soll, ist, feldeigentum(aufgabe.ziel))     // INV-13
     wenn diff leer:
         setze_beobachtungszeitpunkt(aufgabe)                        // kein Protokolleintrag
         weiter                                                      // INV-07
     plan    := plan(aufgabe.ziel, diff)                             // nebenwirkungsfrei, INV-08
     wenn plan.freigabepflichtig oder plan.kostenwirksam:
         lege_zur_freigabe_vor(plan); weiter                         // INV-29
     quittung := apply(aufgabe.ziel, plan, idempotenzschluessel(aufgabe, diff))
     ist2     := observe(aufgabe.ziel, aufgabe.geltungsbereich)      // Verifikation
     rest     := differenz(soll, ist2, feldeigentum(aufgabe.ziel))
     wenn rest leer:
         schreibe_zielsystemzustand(aufgabe, "wirksam")              // quorumpflichtig
     sonst:
         schreibe_zielsystemzustand(aufgabe, "teilweise", rest)      // INV-12
         plane_wiederholung(aufgabe, zurueckweichen(aufgabe.versuch))
```

Der Verifikationsschritt nach `apply` ist nicht redundant. Eine Quittung des Fremdsystems belegt die Annahme des Auftrags, nicht seine Wirkung; asynchrone Verarbeitung, nachgelagerte Regeln im Fremdsystem und stillschweigend abgeschnittene Feldwerte sind häufig genug, dass eine Zusicherung ohne Nachbeobachtung nicht belastbar ist.

**Konvergenzargument.** Drei Eigenschaften zusammen ergeben Konvergenz, und jede einzelne ist prüfbar.

1. **Fixpunkt.** Ist `diff` leer, verändert der Lauf nichts. Der Zustand mit `differenz(soll, ist, eigentum) = ∅` ist damit ein Fixpunkt der Abbildung, und nur dieser Zustand ist einer. Daraus folgt unmittelbar INV-07: der zweite Lauf über denselben Sollzustand erzeugt keinen Protokolleintrag, keinen Neustart und kein Änderungsereignis.
2. **Monotonie.** Jeder Lauf arbeitet auf einer festen Sollzustandsversion und reduziert die Menge der abweichenden Felder in eigenem Besitz, ohne neue zu erzeugen. Das gilt nur, weil der Reconciler ausschließlich Felder in eigenem Besitz schreibt; ohne INV-13 könnte ein Lauf ein fremdbesessenes Feld verändern und damit im Fremdsystem eine Folgeänderung auslösen, die im nächsten Lauf als neue Abweichung erscheint. Die Monotonie ist also eine Folge des Eigentumsmodells, keine Eigenschaft der Schleife.
3. **Terminierung.** Bei fester Sollzustandsversion ist die Zahl abweichender Felder endlich und nimmt je erfolgreichem Lauf um mindestens eins ab. Bei fortlaufend geänderter Sollzustandsversion terminiert die Schleife nicht, und das ist beabsichtigt: sie verfolgt ein bewegliches Ziel.

Das Argument trägt nicht überall. Es gilt nur, wenn `apply` für einen gegebenen `diff` deterministisch wirkt. Ein Fremdsystem mit eigenen Regeln, die ein von Atrium gesetztes Feld nach dem Schreiben wieder verändern, erzeugt einen Zyklus: Atrium setzt, das Fremdsystem korrigiert, der nächste Lauf setzt erneut. Der Entwurf erkennt diesen Fall über einen Zykluszähler je Feld und beendet ihn, statt ihn zu durchlaufen: nach Zielwert 3 aufeinanderfolgenden Korrekturen desselben Feldes innerhalb eines Zielwerts von 15 Minuten wird die Abgleichung dieses Feldes ausgesetzt, der Zustand als "wechselnd" benannt und eine Bedienerentscheidung verlangt. Ohne diese Grenze wäre ein solches Feld eine dauerhafte Schreiblast auf das Fremdsystem und auf den Konsens.

**Dauerhaft nicht erreichbares Ziel.** Der Abstand zwischen Wiederholungen wächst exponentiell und wird gestreut:

```
zurueckweichen(n) = min(basis · 2^(n-1), obergrenze) · (1 + s),   s ~ U(-0,25; +0,25)
basis      = 2 s        (Zielwert)
obergrenze = 900 s      (Zielwert)
```

**Rechnung.** Die Obergrenze greift, sobald `2 · 2^(n-1) ≥ 900`, also `2^(n-1) ≥ 450`, also ab `n = 10` (2^9 = 512, 2 · 512 = 1.024 > 900). Die ersten neun Versuche kosten 2 + 4 + 8 + 16 + 32 + 64 + 128 + 256 + 512 = 1.022 s ≈ 17 min. In den verbleibenden 86.400 − 1.022 = 85.378 s eines Tages folgen 85.378 / 900 = 94,9, also 94 weitere Versuche. Summe rund 103 Versuche je Aufgabe und Tag. Bei einem vollständig ausgefallenen Zielsystem mit 500 betroffenen Objekten sind das 500 · 103 = 51.500 Aufrufe je Tag oder 0,60 je Sekunde, gegenüber der Kapazitätsgrenze von 600 Konnektoraufrufen je Minute (10 je Sekunde) aus [Kapitel 05](05-systemarchitektur.md). Der Wiederholungspfad verbraucht damit 6 % der Konnektorkapazität. Das ist ein Modell aus benannten Annahmen, keine Messung.

Die Streuung ist kein Feinschliff, sondern der Grund, weshalb die Rechnung überhaupt gilt. Ohne sie kehren nach einem gemeinsamen Ausfall alle 500 Aufgaben im selben Takt wieder und erzeugen einen Lastberg von 500 gleichzeitigen Aufrufen alle 900 s; mit ±25 % verteilen sie sich über ein Fenster von 450 s, was die Spitzenlast auf 500 / 450 ≈ 1,1 Aufrufe je Sekunde senkt.

Obergrenzen gelten unterschiedlich je Pfad. Der vorgangsgebundene Pfad bricht nach der harten Obergrenze von 15 Minuten ab (K-15) und stellt den Vorgang auf "teilweise fehlgeschlagen" mit benanntem Rest; die Oberfläche zeigt niemals unbegrenzt "in Arbeit". Der Driftpfad weicht unbegrenzt weiter zurück, weil ein Aufgeben bedeuten würde, eine Abweichung stillschweigend zu akzeptieren; er zeigt die Bindung nach Zielwert 3 aufeinanderfolgenden Fehlläufen als "abweichend" an und nach Zielwert 24 Stunden ohne erfolgreiche Beobachtung als "ausgesetzt".

**Anforderungen**

- **R-08-05** — Ein Abgleichlauf ohne festgestellte Differenz erzeugt 0 `apply`-Aufrufe, 0 Protokolleinträge und 0 Auditereignisse vom Typ "geändert". Prüfbar: Doppellauf im Bau (INV-07).
- **R-08-06** — Wiederholungsabstände folgen der deklarierten Formel mit Streuung ±25 % und Obergrenze 900 s. Prüfbar: Zeitreihenauswertung über 24 h bei dauerhaft nicht erreichbarem Ziel; Abstände außerhalb des Streubands sind ein Fehlschlag.
- **R-08-07** — Nach 3 aufeinanderfolgenden Korrekturen desselben Feldes innerhalb von 15 min wird dessen Abgleichung ausgesetzt und der Zustand "wechselnd" am Objekt angezeigt. Prüfbar: Fremdsystemattrappe, die ein Feld nach jedem Schreiben zurücksetzt.
- **R-08-08** — Ein vorgangsgebundener Abgleich endet spätestens nach 15 min in einem Endzustand mit benanntem Rest. Prüfbar: Fehlerinjektion mit dauerhaft nicht antwortendem Zielsystem (K-15, INV-12).

## 8.3 Idempotenz und Idempotenzschlüssel

Der Schlüssel wird aus der Absicht gebildet, nicht aus dem Versuch:

```
idempotenzschluessel = crockford32( SHA-2( JCS( {
    "vorgang":    <ULID des Vorgangs>,
    "bindung":    <ULID der Konnektorbindung>,
    "zielobjekt": <stabile Fremdkennung, sonst Atrium-Kennung des Quellobjekts>,
    "operation":  "anlegen" | "setzen" | "entfernen" | <manifestdefiniert>,
    "absicht":    <kanonische Form der zu setzenden Felder in Atriums Besitz>
} ) ) )[0..25]
```

Nicht enthalten sind Versuchsnummer, Zeitstempel, Knotenkennung und Sollzustandsversion. Jede dieser Größen ändert sich zwischen zwei Versuchen desselben Auftrags und würde die Wiederholung in eine neue Operation verwandeln, womit der Schlüssel seinen Zweck verlöre. Enthalten ist dagegen die Absicht: eine geänderte Absicht ist eine andere Operation und erhält einen anderen Schlüssel. Daraus folgt eine Auflage, die der Entwurf ausdrücklich trägt: je Paar aus Konnektorbindung und Zielobjekt wird serialisiert ausgeführt, denn zwei Aufträge mit unterschiedlichen Schlüsseln auf dasselbe Feld dürfen sich nicht überholen.

Doppelte Anwendung wird auf drei Ebenen erkannt, in dieser Reihenfolge.

| Ebene | Voraussetzung | Erkennung | Güte |
|---|---|---|---|
| E1 | Das Fremdsystem führt einen Idempotenzschlüssel selbst | Das Fremdsystem antwortet mit dem Ergebnis des Erstlaufs | vollständig, ohne Zusatzannahme |
| E2 | Kein Schlüssel im Fremdsystem, aber ein lokales Quittungsjournal | Der Konnektor findet den Schlüssel im Journal und liefert das vermerkte Ergebnis | vollständig, solange das Journal existiert |
| E3 | Weder noch | `observe` vor `apply`; Anwendung nur bei tatsächlicher Abweichung | vollständig nur bei beobachtbarer Wirkung |

Das Quittungsjournal ist knotenlokal, nicht im Konsens und nicht im Lesemodell; es bildet Schlüssel auf Zeitpunkt, Ergebnis und erzeugte Fremdkennung ab, mit einem Zielwert von 7 Tagen Aufbewahrung. Die Ablage außerhalb des Konsens ist eine bewusste Entscheidung gegen Schreibverstärkung und mit einer benannten Folge: geht der Knoten verloren, geht die Erkennung auf Ebene E2 verloren, und die Wiederholung fällt auf E3 zurück.

**Genau-einmal ist nicht erreichbar.** Zwischen der Wirkung im Fremdsystem und dem dauerhaften Vermerk der Quittung in Atrium liegt ein Fenster. Bricht der Konnektorprozess, der Knoten oder die Verbindung in diesem Fenster ab, hat Atrium keine Information darüber, ob die Wirkung eingetreten ist. Eine gemeinsame atomare Transaktion über Atrium und das Fremdsystem würde das lösen, und keine der betrachteten Fremd-APIs bietet sie an. Die tatsächliche Zusicherung lautet deshalb: **mindestens-einmal-Zustellung mit idempotenter Wirkung, woraus genau-einmal im beobachtbaren Endzustand folgt, nicht genau-einmal in der Zustellung.** Abgesichert wird sie durch die drei Erkennungsebenen, durch die Nachbeobachtung nach jedem `apply` und durch den Zustand "unbestimmt", der einen Zeitüberschreitungsfall ausdrücklich von einem Fehlschlag unterscheidet.

**Rechnung zum Abbruchfenster.** Annahme: 200 ms zwischen Wirkung im Fremdsystem und dauerhaft vermerkter Quittung. Annahme: ein unplanmäßiges Prozess- oder Knotenende je 30 Tage je Konnektorprozess, also 1 / 2.592.000 s. Die Wahrscheinlichkeit, dass ein einzelner Aufruf in das Fenster fällt, beträgt 0,2 s / 2.592.000 s = 7,7 · 10⁻⁸. Bei den oben gerechneten 51.500 Aufrufen je Tag ergibt das 51.500 · 7,7 · 10⁻⁸ = 4,0 · 10⁻³ Treffer je Tag oder rund 1,4 je Jahr. Deutung: der Fall ist selten, aber nicht vernachlässigbar, und er tritt gehäuft genau dann auf, wenn ohnehin eine Störung vorliegt. Er muss behandelt und in der Oberfläche benannt werden, statt als Randfall wegdefiniert zu werden.

Für Operationen, deren Wirkung weder idempotent noch beobachtbar ist — Nachrichtenversand, kostenpflichtige Lizenzzuweisung, nicht abfragbare Erzeugung eines Einmalwerts — deklariert das Konnektormanifest `wiederholbarkeit: nicht_wiederholbar`. Nach einem unbestimmten Ausgang wird eine solche Operation nicht automatisch wiederholt. Der Vorgang endet "teilweise fehlgeschlagen", benennt die Operation, den letzten bekannten Stand und die möglichen Folgen einer Doppelausführung, und bietet die Wiederholung als ausdrückliche Bedienerentscheidung an.

**Anforderungen**

- **R-08-09** — Der Idempotenzschlüssel ist über beliebig viele Wiederholungen desselben Auftrags stabil und unabhängig von Versuchsnummer, Zeit und ausführendem Knoten. Prüfbar: 100 Wiederholungen ergeben denselben Schlüssel.
- **R-08-10** — Ein `apply` mit bereits verwendetem Schlüssel erzeugt keine zweite Wirkung im Fremdsystem. Prüfbar: Vertragstest je Konnektor mit Istzustandsvergleich vor und nach dem zweiten Aufruf (INV-07).
- **R-08-11** — Je Paar aus Konnektorbindung und Zielobjekt wird höchstens eine Operation gleichzeitig ausgeführt. Prüfbar: Nebenläufigkeitstest mit zwei konkurrierenden Änderungen desselben Feldes.
- **R-08-12** — Eine als nicht wiederholbar deklarierte Operation wird nach unbestimmtem Ausgang nicht automatisch wiederholt. Prüfbar: Abbruchinjektion zwischen Wirkung und Quittung; der Vorgang endet "teilweise fehlgeschlagen" ohne zweiten Aufruf.

## 8.4 Wirkungsvorschau

`plan` und `apply` teilen die Ableitungslogik und unterscheiden sich im Ausgang: `plan` gibt eine Liste konkreter Wirkungen zurück, `apply` führt sie aus. Jede Wirkungszeile nennt Zielsystem, Aktion, betroffenes Objekt, Umkehrbarkeit und Kostenwirkung (INV-29). Die Vorschau bindet sich an `vorschau_basis_version`; weicht die Sollzustandsversion bei der Freigabe davon ab, ist die Vorschau entwertet und wird neu berechnet, statt eine veraltete Wirkungsliste zu bestätigen.

Nebenwirkungsfreiheit bei Fremdsystemen ohne eigenen Trockenlauf wird über zwei Mittel erreicht, die sich ergänzen.

**Erstens Durchsetzung am Ausgang.** Für die Dauer eines `plan`-Aufrufs läuft der Konnektorprozess hinter einem Ausgangsvermittler, der schreibende Protokolloperationen ablehnt: bei HTTP nur `GET`, `HEAD` und `OPTIONS`; bei LDAP nur Such- und Vergleichsoperationen; bei SQL nur eine Sitzung mit gesetztem Nur-Lesen-Attribut und ohne Prozeduraufruf. Ein Versuch, eine schreibende Operation abzusetzen, ist eine Vertragsverletzung, wird abgelehnt, auditiert und führt zur Sperrung der Vertragsversion des Manifests.

**Zweitens Berechnung statt Ausführung.** Die Wirkung wird aus dem beobachteten Istzustand und den im Manifest deklarierten Abbildungsregeln berechnet. Das Manifest beschreibt für jede Operation, welche Felder sie setzt und welche Folgeobjekte sie erzeugt; die Vorschau wendet diese Beschreibung auf die Differenz an.

Die Durchsetzung greift nicht vollständig, und der Entwurf sagt das statt es zu verdecken. Wo die Schreibwirkung nicht an der Operation erkennbar ist — eine Fremd-API, die auf `GET` einen Zustand verändert, eine proprietäre RPC-Schicht, die alles über `POST` überträgt, eine gespeicherte Prozedur hinter einer Lesesicht —, kann der Vermittler die Nebenwirkung nicht verhindern. Deshalb deklariert jede Wirkungszeile ihre Güte, und der Import eines Manifests ohne vollständige Güteangabe wird abgelehnt.

| Güte | Bedeutung | Darstellung in der Konsole |
|---|---|---|
| `berechnet` | Aus Istzustand und deklarierter Abbildungsregel hergeleitet | Wirkung mit Wert |
| `beobachtet` | Vom Fremdsystem in einem eigenen Trockenlauf bestätigt | Wirkung mit Wert und Herkunftsangabe |
| `unsicher` | Das Fremdsystem kann die Wirkung vor der Ausführung nicht angeben | Ausdrücklich als nicht vorhersagbar ausgewiesen, mit Grund |

Eine `unsicher`-Zeile wird angezeigt und nicht weggelassen. Eine weggelassene Zeile erzeugt den Eindruck, es gebe keine Wirkung, und das ist die schlechtere Aussage. Der verbleibende Rest ist nicht durch Technik auflösbar: kein Verfahren zwingt ein Fremdsystem, seine Automatismen offenzulegen. Die Vertragsprüfung nach INV-08 prüft deshalb nach jedem `plan`-Aufruf den Istzustand des Fremdsystems auf Unverändertheit und ist der einzige Nachweis, der nicht auf einer Zusage des Fremdsystems beruht.

**Anforderungen**

- **R-08-13** — Ein `plan`-Aufruf verändert den Istzustand des Fremdsystems nicht. Prüfbar: Vertragstest mit vollständigem Istzustandsvergleich vor und nach dem Aufruf (INV-08).
- **R-08-14** — Der Ausgangsvermittler lehnt im Planmodus schreibende Protokolloperationen ab und auditiert den Versuch. Prüfbar: Konnektorattrappe, die eine schreibende Operation absetzt.
- **R-08-15** — Jede Wirkungszeile trägt eine Güteangabe; ein Manifest ohne vollständige Güteangabe wird beim Import abgelehnt. Prüfbar: Importtest mit unvollständigem Manifest.
- **R-08-16** — Weicht die Sollzustandsversion bei der Freigabe von `vorschau_basis_version` ab, wird die Vorschau neu berechnet und nicht bestätigt. Prüfbar: nebenläufige Änderung zwischen Vorschau und Freigabe.

## 8.5 Vorgänge über mehrere Zielsysteme als Saga

Ein Vorgang, der mehrere Zielsysteme berührt, ist eine geordnete Folge von Schritten mit Kompensationen. Eine verteilte Transaktion scheidet aus, weil kein Fremdsystem an einem zweiphasigen Festschreiben teilnimmt.

```
Schritt {
  bindung             : ULID
  operation           : Text
  absicht             : Objekt
  idempotenzschluessel: Text                          // im Vorgang gespeichert, nicht im Speicher
  kompensation        : Schritt | keine
  kompensierbarkeit   : umkehrbar | teilweise | nicht_umkehrbar
  zeitgrenze          : Dauer                         // Zielwert 30 s je Schritt
  zustand             : offen | laufend | wirksam | unbestimmt | fehlgeschlagen
}
```

**Reihenfolgeregel.** Schritte werden nach Kompensierbarkeit sortiert: `umkehrbar` vor `teilweise` vor `nicht_umkehrbar`, innerhalb einer Klasse nach fachlicher Abhängigkeit. Begründung: ein Fehlschlag trifft dann mit höherer Wahrscheinlichkeit einen Bereich, der noch vollständig zurückgenommen werden kann. Die Regel kollidiert mit der fachlichen Abhängigkeit, sobald ein nicht umkehrbarer Schritt Voraussetzung eines umkehrbaren ist — ein kostenpflichtiges Postfach muss vor der Mailadresse existieren. Wo die Abhängigkeit gewinnt, wird die Reihenfolge verletzt, und die Wirkungsvorschau weist genau diesen Punkt aus: ab welchem Schritt eine Rücknahme nicht mehr vollständig ist. Ein allgemeines Auflösungsverfahren existiert nicht.

**Zustandsführung.** Nach jedem Schritt wird der Zustand je Zielsystem in den Sollzustand geschrieben und ist damit quorumpflichtig. Das kostet einen Konsensschreibvorgang je Schritt und ist der Preis dafür, dass ein Führungswechsel mitten in einer Saga nichts verliert. Die Alternative — Sagazustand im Arbeitsspeicher des Führers — wäre billiger und würde bei jedem Führungswechsel einen Vorgang in unbekanntem Zustand hinterlassen.

**Fehlschlag und Kompensation.** Schlägt ein Schritt fehl, werden die vorangegangenen Schritte in umgekehrter Reihenfolge kompensiert, soweit sie eine Kompensation tragen. Ist ein nicht kompensierbarer Schritt bereits ausgeführt, findet keine automatische Rücknahme statt. Der Vorgang endet "teilweise fehlgeschlagen" mit je Zielsystem benanntem Grund, Wiederholbarkeit und der Angabe, was noch nicht wirkt (INV-12); er erreicht niemals den Zustand "abgeschlossen", solange ein Zielsystem aussteht.

**Zeitüberschreitungen.** Eine Zeitüberschreitung liefert keine Information über die Wirkung. Der Schritt geht deshalb in den Zustand `unbestimmt`, nicht in `fehlgeschlagen`. Aufgelöst wird `unbestimmt` ausschließlich durch `observe`: entweder die Wirkung ist eingetreten, dann gilt der Schritt als wirksam, oder sie ist es nicht, dann wird mit demselben Idempotenzschlüssel wiederholt. Für nicht beobachtbare Operationen bleibt `unbestimmt` bestehen und wird zur Bedienerentscheidung. Zielwerte: 10 s je Konnektoraufruf, 30 s je Schritt, 15 min je Saga als harte Obergrenze (K-15).

**Wiederaufnahme nach Neustart.** Der neue Führer übernimmt alle Vorgänge im Zustand "in Ausführung", führt für jeden Schritt in `laufend` oder `unbestimmt` zuerst `observe` aus und setzt danach fort. Voraussetzung ist, dass Idempotenzschlüssel und Schrittzustand im Vorgang persistiert sind; beides ist Sollzustandsbestandteil und überlebt den Wechsel.

**Anforderungen**

- **R-08-17** — Der Zustand jedes Sagaschritts ist vor dem nächsten Schritt im Sollzustand bestätigt. Prüfbar: Führungswechsel zwischen zwei Schritten; der neue Führer setzt an derselben Stelle fort.
- **R-08-18** — Eine Zeitüberschreitung setzt den Schritt auf `unbestimmt` und löst keine Wiederholung ohne vorhergehendes `observe` aus. Prüfbar: Verzögerungsinjektion mit Wirkungseintritt nach Ablauf der Zeitgrenze.
- **R-08-19** — Ein Vorgang mit ausstehendem Zielsystem erreicht den Zustand "abgeschlossen" nicht. Prüfbar: Fehlerinjektion in einen von mehreren Konnektoren (INV-12).
- **R-08-20** — Die Wirkungsvorschau weist den Schritt aus, ab dem eine vollständige Rücknahme nicht mehr möglich ist. Prüfbar: Vorschau eines Vorgangs mit mindestens einem nicht umkehrbaren Schritt.

## 8.6 Nebenläufigkeit

Jedes Objekt trägt eine Objektversion, abgeleitet aus dem Protokollindex seiner letzten Mutation. Schreibende API-Aufrufe führen die erwartete Version als bedingte Anfrage nach RFC 9110 mit. Stimmt sie nicht, wird der Aufruf vollständig abgelehnt; ein Teilschreiben existiert nicht. Die Ablehnung nennt das Feld, den Ausgangswert, den aktuellen Wert und den Urheber der zwischenzeitlichen Änderung.

**Warum Konfiguration nicht automatisch zusammengeführt wird.** Eine feldweise Zusammenführung setzt voraus, dass Felder unabhängig sind. Bei Konfiguration sind sie es nicht, und die Folge ist keine Unschärfe, sondern eine falsche Sicherheitsaussage. Zwei Beispiele:

- Bediener A ändert den Zugriffskreis einer Veröffentlichung von "Gruppe Vertrieb" auf "öffentlich", Bediener B gleichzeitig von "Gruppe Vertrieb" auf "Netzzone Standort Nord". Jede automatische Zusammenführung erzeugt eine dritte Aussage, die niemand getroffen hat, und mindestens eine davon ist weiter gefasst als beabsichtigt.
- Bediener A hebt die Isolationsstufe eines Mandanten, Bediener B setzt gleichzeitig eine Platzierungsvorgabe, die unter der neuen Stufe nicht mehr zulässig ist. Eine Zusammenführung erzeugt einen Zustand, den die Vorschau beider Bediener nie gezeigt hat.

Eine Zusammenführung müsste die Sicherheitswirkung des Ergebnisses kennen; sie kennt Feldnamen. Deshalb ist der Konflikt ein Bedienereignis: die Konsole stellt Ausgangswert, fremde Änderung und eigene Änderung je Feld gegenüber, der Bediener entscheidet feldweise, und aus der Entscheidung entsteht ein neuer Vorgang auf der neuen Basisversion. Die API kennt keine Operation, die zwei konkurrierende Änderungen desselben Feldes verschmilzt.

Für die Dauer seiner Ausführung hält ein Vorgang eine Reservierung auf seine Schreibmenge, begrenzt auf die Sagazeitgrenze von 15 Minuten und danach verfallend. Ein zweiter Vorgang auf derselben Menge wird abgelehnt und benennt den laufenden Vorgang samt Urheber. Die Reservierung ist keine Sperre im Datenbanksinn, sondern ein Objektzustand; sie überlebt einen Führungswechsel, weil sie im Sollzustand liegt.

**Rechnung zur Konfliktwahrscheinlichkeit.** Annahme: 1 Bedieneränderung je Sekunde als Spitzenlast (aus [Kapitel 05](05-systemarchitektur.md)), 15.000 Objekte (K-12), 60 s Bearbeitungsdauer eines Formulars. Bei Gleichverteilung der Änderungen über die Objekte beträgt die Wahrscheinlichkeit, dass eine zweite Änderung im selben Fenster dasselbe Objekt trifft, 60 s · 1 s⁻¹ · 1/15.000 = 4,0 · 10⁻³. Deutung: ein Konflikt tritt bei dieser Annahme etwa bei jeder 250. Bearbeitung auf — selten genug, dass eine Bedienerentscheidung zumutbar ist, häufig genug, dass der Fall ausgearbeitet sein muss. Die Gleichverteilung ist unrealistisch, weil sich Änderungen auf wenige Objekte häufen; der Wert ist deshalb eine Untergrenze, kein Erwartungswert.

**Anforderungen**

- **R-08-21** — Ein schreibender Aufruf ohne erwartete Objektversion oder mit abweichender Version wird vollständig abgelehnt. Prüfbar: Nebenläufigkeitstest; kein Teilschreiben in keinem Fall.
- **R-08-22** — Die API kennt keine Operation, die zwei konkurrierende Änderungen desselben Feldes automatisch zusammenführt. Prüfbar: Durchsicht der Endpunktliste gegen die Fassade; ein solcher Endpunkt bricht den Bau.
- **R-08-23** — Eine Vorgangsreservierung verfällt spätestens nach 15 min und überlebt einen Führungswechsel. Prüfbar: Führungswechsel während einer laufenden Reservierung.

## 8.7 Drift und Feldeigentum

Drift ist jede Abweichung zwischen Sollzustand und beobachtetem Istzustand, die nicht aus einem Vorgang stammt. Erkannt wird sie durch `observe` und Differenzbildung, bewertet ausschließlich entlang des im Konnektormanifest deklarierten Feldeigentums (INV-13).

| Eigentum | Beobachtung | Handlung | Ereignis |
|---|---|---|---|
| Atrium | Wert weicht ab | Überschreiben | `abweichung.korrigiert` |
| Atrium | Feld fehlt | Setzen | `abweichung.korrigiert` |
| Atrium | Zielobjekt fehlt vollständig | Neu anlegen | `abweichung.korrigiert` |
| Fremdsystem | Wert weicht ab | Keine Änderung; Wert in die Anzeige übernehmen | keines |
| Erstanlage | Wert weicht ab | Keine Änderung; Abweichung anzeigen | `abweichung.festgestellt` bei Zustandswechsel |
| — | Fremdobjekt ohne Sollzustandsentsprechung | Keine Löschung; als verwaist melden | `abweichung.festgestellt` |

Die letzte Zeile ist die wichtigste. Ein Fremdkonto ohne Entsprechung im Sollzustand kann ein Rest eines zurückgebauten Vorgangs sein oder ein von einem Fremdadministrator bewusst angelegtes Konto. Atrium kann die beiden Fälle nicht unterscheiden und löscht deshalb nicht, sondern meldet. Eine automatische Löschung wäre nach einem Wiederaufbau des Sollzustands aus einem älteren Export ein Datenverlust im Fremdsystem.

**Fälle ohne Korrektur.** Der Reconciler korrigiert nicht, wenn der Sollzustand eingefroren ist (INV-04), wenn die Konnektorbindung ausgesetzt ist, wenn die Vertragsversion des Manifests außerhalb des unterstützten Fensters liegt, wenn `plan` eine kostenwirksame Folge ohne vorliegende Freigabe meldet (INV-29), und wenn die Zahl der zu korrigierenden Objekte einen Schwellenwert überschreitet. Der Schwellenwert hat den Zielwert 50 Objekte je Lauf und Bindung; darüber wird die Korrektur als Vorgang zur Freigabe vorgelegt statt still ausgeführt. Ohne diese Grenze würde ein Fremdsystem, das nach einer Wiederherstellung einen alten Stand zeigt, eine stille Massenkorrektur über tausende Objekte auslösen.

**Erkennungsfenster.** Eine Handänderung im Fremdsystem erzeugt in Atrium kein Ereignis. Die Erkennung hängt damit vollständig am periodischen Volllauf: mittlere Erkennungszeit gleich halbes Volllaufintervall, bei 60 min Volllaufziel (K-16) also Zielwert 30 min, Obergrenze 60 min. Bietet ein Fremdsystem einen Änderungsstrom an, sinkt die mittlere Erkennungszeit auf dessen Zustellzeit; das ist eine Eigenschaft des einzelnen Manifests und keine Zusage des Kerns. Die Konsole zeigt das Erkennungsfenster je Bindung an, statt eine einheitliche Zusage zu behaupten.

**Anforderungen**

- **R-08-24** — Der Reconciler schreibt ausschließlich Felder, die das Manifest als in Atriums Besitz deklariert. Prüfbar: Abweichungstest mit handgeänderten Feldern beider Eigentumsklassen; fremdbesessene Felder bleiben unverändert (INV-13).
- **R-08-25** — Ein Fremdobjekt ohne Sollzustandsentsprechung wird nicht gelöscht, sondern als verwaist gemeldet. Prüfbar: Anlage eines Fremdkontos außerhalb von Atrium mit anschließendem Volllauf.
- **R-08-26** — Eine Korrektur über dem Schwellenwert von 50 Objekten je Lauf und Bindung wird zur Freigabe vorgelegt und nicht ausgeführt. Prüfbar: Zurücksetzen einer Fremdsysteminstanz auf einen alten Stand mit anschließendem Volllauf.
- **R-08-27** — Jede Ansicht eines Istzustands nennt den Beobachtungszeitpunkt und das Erkennungsfenster der zugehörigen Bindung. Prüfbar: Darstellungstest; eine Istansicht ohne beide Angaben bricht den Bau (INV-28).

## 8.8 Raft im Betrieb

**Führungswahl und Lease.** Die Konsensgruppe verwendet einen Heartbeat mit Zielwert 250 ms und eine randomisierte Wahlfrist von 1.000 bis 2.000 ms; daraus folgt die Schreibpause von ≤ 5 s bei Ausfall des Führers einschließlich eines fehlgeschlagenen Wahlgangs (K-05). Diese Fristen sind von den Knotenleases in K-07 (Heartbeat 5 s, Lease 20 s) zu unterscheiden: jene regeln die Selbstabschottung eines Dienstträgers, diese die Führung der Kontrollebene.

Die Führungs-Lease ist die Zeitspanne, für die ein Führer nach der letzten Mehrheitsbestätigung annehmen darf, dass kein zweiter Führer existiert. **Rechnung:** Lease = minimale Wahlfrist − zugelassene Uhrenabweichung = 1.000 ms − 500 ms (K-30) = 500 ms. Bei einem Heartbeat von 250 ms liegen genau zwei Erneuerungsversuche im Leasefenster. Die Schwäche ist offensichtlich und wird hier benannt: die zugelassene Uhrenabweichung verbraucht die Hälfte des Fensters. Eine Vergrößerung der Reserve erfordert entweder eine schärfere Zeitgüte als 500 ms oder eine längere Wahlfrist, und letztere verlängert die Schreibpause und gefährdet K-05.

**Mitgliedschaftsänderung in Einzelschritten.** Eine Konfigurationsänderung ändert genau ein Mitglied. Der Weg von 1 auf 3 Stimmknoten läuft in vier Teilschritten:

```
0. Neuer Knoten tritt als Mitleser bei; holt Momentaufnahme und Protokollnachlauf,
   bis der Rueckstand unter dem Zielwert von 1 s liegt.       (kein Stimmrecht, kein Risiko)
1. Beförderung des ersten Mitlesers zum Stimmknoten.          (Konfiguration 1 -> 2)
2. Beförderung des zweiten Mitlesers zum Stimmknoten.         (Konfiguration 2 -> 3)
3. Bestätigung der Zielkonfiguration; Zwischenzustand endet.
```

INV-05 verbietet 2 und 4 als **Zielstimmzahl**, nicht als Zwischenschritt innerhalb einer einzigen Änderungstransaktion. Die API lehnt eine Mitgliedschaftsänderung mit gerader Zielstimmzahl ab. Der Zwischenzustand ist zeitbegrenzt: Zielwert ≤ 5 s, danach Rückführung auf die Ausgangskonfiguration.

**Rechnung zum Zwischenzustand.** Bei 2 Stimmknoten ist die Mehrheitsschwelle 2, es muss also jeder verfügbar sein. Mit der Annahme A = 0,99 je Knoten (K-04) beträgt die Verfügbarkeit A² = 0,9801, gegenüber 0,99 bei einem Knoten und 0,999702 bei dreien. Der Zwischenzustand ist schlechter verfügbar als beide Endzustände, und genau deshalb existiert die Zeitgrenze. Das Vorschalten der Mitleserphase sorgt dafür, dass der Zwischenzustand nur die beiden Beförderungen umfasst und nicht die Aufholzeit.

**Momentaufnahmen und Protokollkürzung.** Eine Momentaufnahme wird ausgelöst nach Zielwert 10.000 Protokolleinträgen oder 15 min, je nachdem was zuerst eintritt. **Rechnung:** bei der Zielkapazität von ≥ 50 bestätigten Änderungen je Sekunde wären 10.000 Einträge nach 200 s erreicht, im Nennbetrieb mit ≤ 11 Änderungen je Sekunde nach 909 s ≈ 15 min. Beide Auslöser greifen im Nennbetrieb also etwa gleichzeitig; unter Last dominiert der Zähler.

Die Momentaufnahme enthält die kanonische Form des Sollzustands, den Protokollindex, den Kettenhashwert des letzten enthaltenen Eintrags und die `schema_version`. Sie enthält nicht das Lesemodell, nicht die Indizes, nicht den Istzustand, nicht die Metriken und nicht den Auditstrom. Begründung: alles Genannte ist entweder deterministisch neu berechenbar oder gehört per INV-23 nicht in den replizierten Kernzustand. **Rechnung:** bei ≤ 50 MB Sollzustand (K-12) und 1 Gbit/s ergibt die Übertragung an einen neuen Knoten 400 Mbit / 1.000 Mbit/s = 0,4 s. Die Obergrenze von 2 GB in K-12 bleibt damit im Nennbetrieb um den Faktor 40 unausgeschöpft und ist Reserve für Installationen oberhalb der angenommenen Objektzahl.

Die Protokollkürzung entfernt Einträge bis zum Index der jüngsten Momentaufnahme, die auf der Mehrheit vorliegt, abzüglich eines Nachlaufs mit Zielwert 1.000 Einträgen, damit ein kurz zurückliegender Mitleser ohne Momentaufnahmeübertragung aufholt. Die Hashkette bleibt über die Kürzung hinweg prüfbar, weil die Momentaufnahme den Kettenhashwert trägt; die Prüfkette lautet danach Momentaufnahme → nachfolgende Einträge. Der vollständige Verlauf vor der Kürzung liegt nicht mehr im Konsensspeicher, sondern in den signierten Sollzustandsexporten und im Auditstrom. Das ist eine ausdrückliche Verschiebung der Nachweisführung aus dem replizierten Zustand heraus, keine Nebenwirkung, und sie ist der Grund, weshalb die Exporte zum Nachweisverfahren gehören und nicht nur zur Sicherung (siehe [Kapitel 17](17-speicher-backup.md)).

**Wiederherstellung nach Totalausfall.** Drei Fälle mit unterschiedlichem Verfahren und unterschiedlichem Preis.

| Fall | Lage | Verfahren | Preis |
|---|---|---|---|
| A | Quorum verloren, Mitglieder intakt | Keine Handlung; Rückkehr eines Mitglieds stellt Quorum her. Bis dahin eingefroren (INV-04) | Keine Schreibvorgänge; Dienste laufen weiter (INV-25) |
| B | Mehrheit dauerhaft verloren, ein Stimmknoten intakt | Erzwungene Neukonstituierung mit Wiederherstellungscode, erzwungener Wartezeit (Zielwert 10 min), Klartextwarnung und nicht unterdrückbarem Auditereignis | Bestätigte Änderungen, die nur auf den verlorenen Mitgliedern lagen, gehen verloren |
| C | Kein Knoten intakt | Neuinstallation eines Ankerknotens, Import des signierten Sollzustandsexports, Konvergenz, Rückspielen der Nutzdaten je Speicherbereich | RTO ≤ 30 min (K-11); Stand des letzten Exports |

Fall B ist Datenverlust am Sollzustand, und die Konsole benennt ihn so. Die Zahl der verlorenen Einträge ist vor der Entscheidung nicht bestimmbar, weil die verlorenen Mitglieder nicht befragt werden können; die Oberfläche kann nur den letzten lokal bekannten Protokollindex nennen und offenlassen, wie weit die Mehrheit darüber hinaus war. Die erzwungene Wartezeit existiert, damit ein zurückkehrender Knoten die Entscheidung noch überflüssig machen kann. In Fall C wird die Auditkette aus der Auslagerung importiert und geprüft, nicht neu begonnen; eine neu begonnene Kette wäre ein Nachweisverlust.

**Anforderungen**

- **R-08-28** — Eine Mitgliedschaftsänderung ändert genau ein Mitglied je Konfigurationseintrag; eine gerade Zielstimmzahl wird abgelehnt; der Zwischenzustand dauert ≤ 5 s und wird sonst zurückgeführt. Prüfbar: Mitgliedschaftstest mit injizierter Verzögerung im zweiten Teilschritt (INV-05).
- **R-08-29** — Eine Momentaufnahme enthält kein Lesemodell, keine Indizes, keinen Istzustand, keine Metriken und keine Auditereignisse. Prüfbar: Inhaltsprüfung einer erzeugten Momentaufnahme (INV-23).
- **R-08-30** — Die Hashkette ist über eine Protokollkürzung hinweg prüfbar. Prüfbar: Kettenprüfung nach Kürzung und Neustart.
- **R-08-31** — Die erzwungene Neukonstituierung verlangt den Wiederherstellungscode, hält eine Wartezeit ein, zeigt eine Klartextwarnung über möglichen Verlust bestätigter Änderungen und erzeugt ein nicht unterdrückbares Auditereignis. Prüfbar: Durchlauf des Verfahrens im Testaufbau.

## 8.9 Last und Grenzen: Warteschlangenmodell der API

Die folgende Rechnung ist ein Modell, keine Messung. Sie dient dazu, die Ratenbegrenzung aus einer Zusage herzuleiten statt sie zu setzen.

**Annahmen.** Bedienrate des Lesepfads je Verwaltungsknoten μ = 200 Anfragen je Sekunde, hergeleitet aus einer angenommenen mittleren Bedienzeit von 5 ms je Anfrage (Indexzugriff und Serialisierung im Hauptspeicher, Arbeitssatz vollständig im Speicher nach K-19). Ankünfte poissonverteilt, Bedienzeiten exponentialverteilt, ein Bedienstrang, unbegrenzte Warteschlange. Das ist ein M/M/1-Modell.

**Formeln.** Auslastung ρ = λ/μ. Verweilzeit W = 1/(μ − λ). Wartezeit W_q = ρ/(μ − λ). Satz von Little L = λ · W. Die Verweilzeit ist exponentialverteilt mit Rate (μ − λ), woraus p95 = ln(20)/(μ − λ) = 2,9957/(μ − λ) und p99 = ln(100)/(μ − λ) = 4,6052/(μ − λ) folgen.

| ρ | λ [1/s] | μ − λ [1/s] | W | W_q | L = λ·W | p95 | p99 |
|---|---|---|---|---|---|---|---|
| 0,50 | 100 | 100 | 10,0 ms | 5,0 ms | 1,00 | 30,0 ms | 46,1 ms |
| 0,80 | 160 | 40 | 25,0 ms | 20,0 ms | 4,00 | 74,9 ms | 115,1 ms |
| 0,95 | 190 | 10 | 100,0 ms | 95,0 ms | 19,00 | 299,6 ms | 460,5 ms |

Die Little-Probe bestätigt die Zeilen: 100 · 0,010 = 1,00; 160 · 0,025 = 4,00; 190 · 0,100 = 19,00.

**Deutung.** K-18 fordert eine Erstanzeige mit p95 ≤ 300 ms. Bei ρ = 0,95 beträgt die p95-Verweilzeit allein in der Kontrollebene 299,6 ms und verbraucht das Budget vollständig, ohne dass Netzlaufzeit und Darstellung berücksichtigt sind. Bei ρ = 0,80 bleiben nach 74,9 ms noch 225 ms Budget. Daraus folgt die Zielauslastung ρ ≤ 0,80 und die Annahmeschwelle λ_max = 0,8 · 200 = 160 Anfragen je Sekunde und Verwaltungsknoten.

**Ratenbegrenzung.** Token-Bucket auf drei Ebenen, jeweils als Zielwert: 160/s je Verwaltungsknoten, 40/s je Mandant, 10/s je Dienstkonto; Eimertiefe 2 s · Rate als Stoßreserve. Die Ebenen sind kumulativ, die engste greift.

**Rückstau.** Die Warteschlange ist begrenzt auf L_q(0,8) · Sicherheitsfaktor 4 = 3,20 · 4 = 12,8, aufgerundet Zielwert 16 je Bedienstrang. Ein Überlauf führt zu einer Ablehnung nach RFC 9110 mit Wartezeitangabe — nie zu stillem Verwerfen und nie zu unbegrenztem Warten. Zusätzlich gilt Warteschlangenalterung: eine Anfrage, deren Wartezeit die Hälfte des Anzeigebudgets überschreitet (Zielwert 150 ms), wird abgelehnt statt bedient, weil ihre Antwort beim Aufrufer bereits wertlos ist und ihre Bedienung nur die Schlange verlängert.

**Schreibpfad getrennt gerechnet.** μ_schreib = 50 bestätigte Änderungen je Sekunde (Zielwert aus [Kapitel 05](05-systemarchitektur.md)), Bedarf ≤ 11 je Sekunde, also ρ = 0,22 und W = 1/(50 − 11) = 25,6 ms, L = 11 · 0,0256 = 0,28. Der Schreibpfad ist nach diesem Modell nicht die Engstelle. Die Engstelle ist der Konnektorpfad mit 600 Aufrufen je Minute, für den kein belastbares Modell angegeben wird, weil die Bedienzeit von der Fremd-API und deren eigener Ratenbegrenzung bestimmt wird und dafür keine Annahme tragfähig ist.

**Modellkritik.** Vier Annahmen des Modells sind falsch, und ihre Richtung ist bekannt. Erstens kommen Bedieneranfragen in Sitzungsbündeln statt poissonverteilt, was die Spitzen unterschätzt. Zweitens sind die Bedienzeiten nicht exponentialverteilt; bei deterministischer Bedienzeit gilt W_q = ρ/(2μ(1 − ρ)), bei ρ = 0,8 also 0,8/(2 · 200 · 0,2) = 10,0 ms statt 20,0 ms — das Modell ist hier um den Faktor 2 konservativ. Drittens existiert mehr als ein Bedienstrang. Viertens ist die Warteschlange begrenzt, weshalb die Verweilzeit bei Überlast nicht wächst, sondern die Ablehnungsrate.

Die eigentliche Folgerung aus dem Modell ist nicht die Zahl, sondern die Trennung der Anfrageklassen. K-18 nennt zwei Zusagen mit einem Verhältnis von 300 ms zu 2 s; eine Wirkungsvorschau von 2 s blockiert in einem gemeinsamen Strang 400 Listenabfragen à 5 ms. Lesepfad und Vorschaupfad erhalten deshalb getrennte Bedienstränge mit getrennten Kontingenten. Ohne diese Trennung ist die Listenzusage durch eine einzige laufende Vorschau verletzbar.

**Anforderungen**

- **R-08-32** — Die API lehnt Anfragen oberhalb der Annahmeschwelle mit Wartezeitangabe ab und verwirft keine Anfrage stillschweigend. Prüfbar: Lasttest oberhalb von λ_max; 0 stille Verwerfungen.
- **R-08-33** — Lesepfad und Vorschaupfad verwenden getrennte Bedienstränge mit getrennten Kontingenten. Prüfbar: Lasttest mit gleichzeitigen Vorschauen; die Listenzusage nach K-18 bleibt eingehalten.
- **R-08-34** — Eine Anfrage, deren Wartezeit 150 ms überschreitet, wird abgelehnt statt bedient. Prüfbar: Zeitmessung am Warteschlangenausgang unter Überlast.

## 8.10 Ereignis- und Auditpfad

Der Auditstrom liegt außerhalb des replizierten Kernzustands (INV-23). Vier Gründe tragen diese Trennung, und jeder allein wäre ausreichend.

**Erstens Volumen.** Annahme: 200 schreibende Operationen je Tag, 500 Personen mit 3 Anmeldungen je Tag = 1.500 Anmeldeereignisse, 5,6 Zertifikatsausstellungen je Tag (aus K-13: 500 veröffentlichte Namen / 90 Tage), zuzüglich Abweichungs- und Vorgangsereignissen. Zielwert rund 2.000 Ereignisse je Tag à 1 KB = 2 MB je Tag = 730 MB je Jahr. Der gesamte Sollzustand liegt bei ≤ 50 MB (K-12). Im Konsens gehalten, überstiege der Auditstrom den Sollzustand nach einem Jahr um den Faktor 15 und verlängerte jede Momentaufnahmeübertragung entsprechend.

**Zweitens Aufbewahrung.** Der Auditstrom wird 12 Monate vollständig aufbewahrt und danach verdichtet (K-25). Der Sollzustand hat keine Aufbewahrungsfrist, sondern einen aktuellen Stand. Zwei Objekte mit unterschiedlicher Lebensdauer in einem Speicher zwingen den ungünstigeren Umgang auf beide.

**Drittens Überleben der Löschung.** Ein Auditereignis überlebt sein Bezugsobjekt. Läge es im replizierten Zustand, müsste der Grabstein des gelöschten Objekts den Auditinhalt tragen, womit die Löschung nichts mehr löschte.

**Viertens Schreibbarkeit ohne Quorum.** Der Auditstrom muss auch ohne Quorum schreiben, denn gerade Quorumverlust, Notzugang und erzwungene Neukonstituierung sind die nachweispflichtigsten Vorgänge. Ein quorumpflichtiger Auditstrom wäre genau dann stumm, wenn er gebraucht wird.

Aus dem vierten Grund folgt unmittelbar eine Einschränkung, die der Entwurf benennt statt sie zu verschweigen: es gibt **keine globale Totalordnung** der Auditereignisse. Innerhalb eines Stroms ist die Ordnung durch die lückenlose Folgenummer bestimmt, zwischen Strömen nur bis zur Uhrengüte von ≤ 500 ms (K-30). Zwei Ereignisse aus verschiedenen Strömen innerhalb dieses Fensters sind nicht sicher ordenbar. Für die Frage "was ist geschehen" ist der Auditstrom damit geeignet, für die Frage "wer war zuerst" bei zwei gleichzeitigen Akteuren auf verschiedenen Knoten nicht. Die Zusammenführung über die Korrelationskennung ordnet innerhalb eines Vorgangs korrekt, weil dessen Schritte kausal verkettet sind.

**Zwei-Ereignis-Regel.** Auditereignistypen stehen im Perfekt und setzen einen abgeschlossenen Sachverhalt voraus. Ein Vorgang schreibt deshalb `vorgang.begonnen` vor der ersten Wirkung und `vorgang.abgeschlossen` beziehungsweise `vorgang.teilweise_fehlgeschlagen` nach der letzten. Ein begonnener Vorgang ohne Abschlussereignis ist damit eine erkennbare Lücke und erscheint als offener Punkt in der Konsole, statt unsichtbar zu bleiben. Ohne diese Regel wäre ein Abbruch zwischen Wirkung und Auditschreibung nicht von einem Vorgang zu unterscheiden, der nie begonnen hat.

**Trennung vom Betriebsprotokoll.** Betriebsereignisse, Metriken und Spuren laufen als OpenTelemetry über OTLP an 8408 in einen lokalen Zeitreihenspeicher je Verwaltungsknoten und niemals in den replizierten Zustand; die Ausleitung an ein externes Ziel ist eine Konnektorbindung. Der Auditstrom ist davon vollständig getrennt: nur anhängbar, hashverkettet mit SHA-2 nach FIPS 180-4 über die kanonische Form nach RFC 8785, periodisch mit einem Zeitstempel nach RFC 3161 versiegelt, ausgeleitet nach RFC 5424 über TLS. Die Kette wird bei jedem Start und bei jedem Export geprüft; ein Bruch wird gemeldet und nicht repariert, denn eine Reparatur wäre eine Änderung an einem unveränderlichen Nachweis. Das Zusammenspiel mit Rollen, Freigabewegen und Exportfiltern beschreibt [Kapitel 19](19-mandanten-rechte-audit.md).

**Anforderungen**

- **R-08-35** — Der Auditstrom ist im eingefrorenen Zustand schreibbar. Prüfbar: Partitionstest; Notzugang und abgelehnte Schreibversuche erzeugen auf der Minderheitsseite vollständige Auditereignisse (INV-23).
- **R-08-36** — Ein Vorgang erzeugt ein Beginn- und ein Abschlussereignis; ein Beginnereignis ohne Abschluss wird in der Konsole als offener Punkt angezeigt. Prüfbar: Abbruchinjektion während der Ausführung.
- **R-08-37** — Kein Auditereignis und keine Metrik erscheint im replizierten Kernzustand. Prüfbar: Inhaltsprüfung von Momentaufnahme und Protokoll gegen Auditereignis- und Metrikmuster.
- **R-08-38** — Ein Kettenbruch im Auditstrom wird bei Start und Export gemeldet und nicht repariert. Prüfbar: Injektion eines veränderten Ereignisses.

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Zwei Verwaltungsknoten melden an jedem Momentaufnahmepunkt denselben Zustandshashwert | R-08-01 | Hashvergleich im Integrationstest; Abweichung bricht den Bau |
| Nach Löschung und Neubau des Lesemodells ist der Zustandshashwert identisch, Dauer ≤ 60 s bei 15.000 Objekten | R-08-02 | Neubautest mit Zeitmessung |
| Ein veränderter Protokolleintrag wird beim Start als Kettenbruch gemeldet und nicht angewandt | R-08-03 | Injektionstest |
| 10^6 Mutationen mit nicht konstantem Wert führen zu 0 angenommenen Einträgen | R-08-04 | Zufallsdatentest am Protokolleingang |
| Ein zweiter Abgleichlauf ohne Differenz erzeugt 0 `apply`-Aufrufe und 0 Änderungsereignisse | R-08-05 | Doppellauf im Bau (INV-07) |
| Über 24 h bei nicht erreichbarem Ziel liegen alle Wiederholungsabstände im Streuband und unter 900 s, Versuchszahl 103 ± 5 | R-08-06 | Zeitreihenauswertung |
| Ein Feld, das die Fremdsystemattrappe nach jedem Schreiben zurücksetzt, wird nach 3 Korrekturen ausgesetzt und als "wechselnd" angezeigt | R-08-07 | Zyklustest |
| Ein Vorgang mit dauerhaft nicht antwortendem Zielsystem erreicht spätestens nach 15 min einen Endzustand mit benanntem Rest | R-08-08 | Fehlerinjektion (K-15) |
| 100 Wiederholungen desselben Auftrags erzeugen 100 identische Idempotenzschlüssel | R-08-09 | Schlüsselvergleich |
| Ein zweiter `apply`-Aufruf mit demselben Schlüssel lässt den Istzustand des Fremdsystems unverändert | R-08-10 | Vertragstest je Konnektor |
| Zwei konkurrierende Änderungen desselben Feldes erreichen das Fremdsystem serialisiert, nie überlappend | R-08-11 | Nebenläufigkeitstest mit Aufrufmitschnitt |
| Nach Abbruch zwischen Wirkung und Quittung erfolgt bei nicht wiederholbarer Operation 0 automatische Wiederholung | R-08-12 | Abbruchinjektion |
| Der Istzustand des Fremdsystems ist nach einem `plan`-Aufruf bitgleich zum Stand davor | R-08-13 | Vertragstest (INV-08) |
| Eine schreibende Protokolloperation im Planmodus wird abgelehnt und auditiert | R-08-14 | Konnektorattrappe |
| Ein Manifest mit einer Wirkungszeile ohne Güteangabe wird beim Import abgelehnt | R-08-15 | Importtest |
| Eine Freigabe auf veralteter Vorschaubasis wird abgelehnt und die Vorschau neu berechnet | R-08-16 | Nebenläufige Änderung zwischen Vorschau und Freigabe |
| Nach einem Führungswechsel zwischen zwei Sagaschritten setzt der neue Führer an derselben Stelle fort, ohne einen Schritt zu wiederholen | R-08-17 | Führungswechseltest mit Aufrufmitschnitt |
| Ein nach Ablauf der Zeitgrenze eintreffender Wirkungseintritt führt zu Zustand `unbestimmt` und zu `observe` statt zu einer blinden Wiederholung | R-08-18 | Verzögerungsinjektion |
| Ein Vorgang mit ausstehendem Zielsystem meldet "teilweise fehlgeschlagen", nie "abgeschlossen" | R-08-19 | Fehlerinjektion in einen von mehreren Konnektoren (INV-12) |
| Die Vorschau eines Vorgangs mit nicht umkehrbarem Schritt nennt den Schritt, ab dem die Rücknahme unvollständig wird | R-08-20 | Vorschauprüfung |
| Ein schreibender Aufruf mit falscher Objektversion hinterlässt 0 geänderte Felder | R-08-21 | Nebenläufigkeitstest |
| Die Endpunktliste enthält 0 Operationen zur automatischen Zusammenführung konkurrierender Feldänderungen | R-08-22 | Fassadenbau (INV-01) |
| Eine Vorgangsreservierung verfällt nach 15 min und ist nach einem Führungswechsel unverändert vorhanden | R-08-23 | Führungswechsel während laufender Reservierung |
| Nach Handänderung an je einem atrium- und einem fremdbesessenen Feld ist nur das erste korrigiert | R-08-24 | Abweichungstest (INV-13) |
| Ein außerhalb von Atrium angelegtes Fremdkonto besteht nach einem Volllauf weiter und ist als verwaist gemeldet | R-08-25 | Volllauftest |
| Ein auf einen alten Stand zurückgesetztes Fremdsystem erzeugt einen Freigabevorgang statt einer Massenkorrektur | R-08-26 | Wiederherstellungstest am Fremdsystem |
| Jede Istansicht nennt Beobachtungszeitpunkt und Erkennungsfenster; eine Ansicht ohne beide bricht den Bau | R-08-27 | Darstellungstest (INV-28) |
| Eine Mitgliedschaftsänderung auf 2 oder 4 Stimmknoten als Ziel wird abgelehnt; der Zwischenzustand dauert ≤ 5 s | R-08-28 | Mitgliedschaftstest mit Verzögerungsinjektion (INV-05) |
| Eine erzeugte Momentaufnahme enthält 0 Lesemodelltabellen, 0 Istzustandseinträge, 0 Metriken und 0 Auditereignisse | R-08-29 | Inhaltsprüfung |
| Die Kettenprüfung nach Protokollkürzung und Neustart meldet 0 Brüche | R-08-30 | Kürzungstest |
| Die erzwungene Neukonstituierung ohne Wiederherstellungscode scheitert; mit Code entsteht 1 nicht unterdrückbares Auditereignis nach eingehaltener Wartezeit | R-08-31 | Verfahrensdurchlauf |
| Bei Last oberhalb von λ_max entstehen 0 stille Verwerfungen; jede Ablehnung trägt eine Wartezeitangabe | R-08-32 | Lasttest |
| Die Listenzusage nach K-18 bleibt während gleichzeitig laufender Wirkungsvorschauen eingehalten | R-08-33 | Mischlasttest |
| Keine bediente Anfrage hat eine Wartezeit über 150 ms | R-08-34 | Zeitmessung am Warteschlangenausgang |
| Auf der Minderheitsseite einer Partition entstehen vollständige Auditereignisse für Notzugang und abgelehnte Schreibversuche | R-08-35 | Partitionstest (INV-23) |
| Ein während der Ausführung abgebrochener Vorgang erscheint als Beginn ohne Abschluss in der Konsole | R-08-36 | Abbruchinjektion |
| Momentaufnahme und Protokoll enthalten 0 Treffer gegen Auditereignis- und Metrikmuster | R-08-37 | Inhaltsprüfung |
| Ein verändertes Auditereignis wird bei Start und Export als Kettenbruch gemeldet und nicht repariert | R-08-38 | Injektionstest |

## Offene Punkte

1. **Volllaufgrenze.** Das Drifterkennungsfenster hängt vollständig am periodischen Volllauf, und dessen bindende Grenze von 7.200 abgleichpflichtigen Objekten ist in [Kapitel 05](05-systemarchitektur.md) offen. Die drei Auswege — höhere Parallelität, längeres Intervall, kürzere Prüfzeit je Objekt — verschieben die Kosten jeweils in einen anderen Zielwert. Die Entscheidung ist nicht getroffen und betrifft R-08-27 unmittelbar.
2. **Führungs-Lease gegen Zeitgüte.** Das Leasefenster von 500 ms und die zugelassene Uhrenabweichung von 500 ms stehen im Verhältnis 1:1. Ob die Zeitgüte verschärft (Auswirkung auf Hardwareanforderungen und Zeitquellenzahl) oder die Wahlfrist verlängert wird (Auswirkung auf K-05), ist offen.
3. **Quittungsjournal bei Knotenverlust.** Das Journal ist knotenlokal und wird nicht repliziert. Geht der Knoten verloren, fällt die Erkennung doppelter Anwendung auf die Beobachtung zurück, die bei nicht beobachtbaren Operationen nicht greift. Ob das Journal in den Istzustand repliziert oder in den Sollzustand aufgenommen wird, ist offen; Letzteres erzeugt Schreibverstärkung je Konnektoraufruf.
4. **Anteil unsicherer Vorschauzeilen.** Es existiert kein Verfahren, den Anteil der als `unsicher` deklarierten Wirkungszeilen je Katalogeintrag nach oben zu begrenzen. Ob ein Katalogeintrag mit überwiegend unsicherer Vorschau freigegeben wird und welche Schwelle gilt, ist nicht entschieden.
5. **Reihenfolgekonflikt in der Saga.** Die Regel "nicht kompensierbare Schritte zuletzt" und die fachliche Abhängigkeitsreihenfolge widersprechen sich in genau den Fällen, in denen ein kostenwirksamer Schritt Voraussetzung eines umkehrbaren ist. Ein allgemeines Auflösungsverfahren fehlt; derzeit gewinnt die Abhängigkeit, und die Vorschau weist den Punkt aus.
6. **Konfliktdarstellung strukturierter Felder.** Für skalare Felder ist die feldweise Gegenüberstellung klar. Für Listen, Mengen und verschachtelte Objekte ist nicht festgelegt, wie ein Konflikt dargestellt wird, ohne die Drei-Entscheidungs-Regel zu brechen (INV-14, siehe [Kapitel 18](18-bedienkonzept.md)).
7. **Verlustumfang bei erzwungener Neukonstituierung.** Die Zahl der verlorenen bestätigten Änderungen ist nicht bestimmbar. Ob eine nachträgliche Rekonstruktion aus den Auditströmen der verlorenen Knoten vorgesehen wird — technisch möglich, aber mit der Ordnungseinschränkung zwischen Strömen behaftet — ist offen.
8. **Belastbarkeit der Bedienrate.** Die Bedienrate μ = 200/s ist eine Annahme. Solange sie nicht gemessen ist, ist die Annahmeschwelle von 160/s eine gesetzte Zahl. Messverfahren, Messzeitpunkt und die Regel, nach der die Schwelle an eine Messung angepasst wird, sind nicht festgelegt.
9. **Schreibverstärkung der Sagazustandsführung.** Jeder Sagaschritt kostet einen Konsensschreibvorgang. Bei vielschrittigen Vorgängen über viele Zielsysteme ist offen, ob eine Verdichtung mehrerer Schrittzustände in einen Eintrag zulässig ist und wie sie mit der Wiederaufnahme nach Führungswechsel verträglich bleibt.
10. **Konnektorpfad ohne Modell.** Für den Konnektorpfad als eigentliche Engstelle existiert kein Warteschlangenmodell, weil die Bedienzeit von Fremd-APIs mit eigener Ratenbegrenzung bestimmt wird. Ob je Konnektorbindung ein gemessenes Bedienzeitprofil geführt und die Parallelität daraus abgeleitet wird, ist offen (siehe [Kapitel 09](09-konnektoren.md)).
