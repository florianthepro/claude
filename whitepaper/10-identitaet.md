# 10 Identität und Zugriff

## 10.1 Aufbau: Wahrheitsquelle, Protokollkopf, Versorgung

Die Identität einer Person ist ein Objekt im Sollzustand von atrium-core und nichts anderes. Der eingebettete Protokollkopf (Kanidm, Technologiefestlegung 9) hält kein eigenes Identitätsmodell, sondern eine materialisierte Kopie. Das Konto im Protokollkopf ist damit ein **Abgeleitetes Artefakt** der Art Fremdkonto im Sinne des Objektmodells: es trägt einen Quellverweis auf die Person, ist nicht direkt editierbar (INV-09) und verfällt mit der Quelle.

```
  Sollzustand (Raft, quorumpflichtig, auditiert)
  +---------------------------------------------------------------+
  | Person  (Kennung, Anmeldename, Authentisierungsmittel,         |
  |          Gueltigkeitszeitraum, token_nicht_vor)                |
  | Gruppe  (Mitgliedschaft, azyklisch)                            |
  | Rolle   (Rechteliste, Version)                                 |
  | Zuweisung (Subjekt -> Ziel -> Rolle, Gueltigkeit von/bis)      |
  +-------------------------------+-------------------------------+
                                  | Reconciler (einzige Schreibrichtung)
          +-----------------------+------------------------+
          |                       |                        |
  +-------v--------+     +--------v---------+     +--------v---------+
  | Protokollkopf  |     | SCIM-Server      |     | Konnektor je     |
  | OIDC | LDAPS   |     | (in atrium-core, |     | Fremdsystem      |
  | Passkey/MFA    |     |  Port 8405)      |     | (eigener Prozess)|
  +-------+--------+     +--------+---------+     +--------+---------+
          |                       |                        |
  Anmeldung von Personen   Versorgung durch     Versorgung in
  und Altsystem-Lesezugriff  fremde Quelle       fremde Ziele

  Rueckrichtung (Istzustand, knotenlokal, verdichtet repliziert):
  Sitzungen, Zaehlerstaende, letzte Anmeldung, Abweichungsbefund
```

Zwei Replikationssysteme laufen hier nebeneinander: das Änderungsprotokoll der Kontrollebene und der Speicher des Protokollkopfs. Sie können divergieren, und die Divergenz ist nicht durch Sorgfalt ausschließbar, sondern nur durch ständigen Abgleich erkennbar (Technologiefestlegung 9d). Der Reconciler vergleicht je Person Anmeldename, Zustand, Gruppenzugehörigkeit und Menge der registrierten Authentisierungsmittel mit dem Protokollkopf und korrigiert jede Abweichung in Richtung Kern. Die Abweichung wird vorher als Auditereignis "Abweichung korrigiert" geschrieben (INV-02), nicht stillschweigend geglättet, weil eine wiederkehrende Abweichung derselben Person auf einen Schreibweg hindeutet, der nicht existieren darf.

Ein Sonderfall bricht die einfache Richtung "Kern schreibt, Kopf gehorcht": der öffentliche Schlüssel eines Passkeys entsteht auf dem Authentikator der Person und fließt in die Gegenrichtung. Der Entwurf löst das, indem die Registrierung eines Authentikators ein **Vorgang** ist, der den Sollzustand ändert, und der Protokollkopf das Ergebnis erst danach über den Reconciler erhält. Die Folge ist unbequem und wird hier benannt: bei eingefrorenem Sollzustand (INV-04, fehlendes Quorum) ist die **Anmeldung weiterhin möglich**, weil der Protokollkopf seine Kopie hält, die **Registrierung eines neuen Authentikators aber nicht**. Eine Person, die im selben Zeitfenster ihr einziges Gerät verliert, kann bis zur Wiederherstellung des Quorums nicht wieder hineinkommen. Der Entwurf mildert das über die Pflicht zu mehreren Authentikatoren (Abschnitt 10.2), er beseitigt es nicht.

Hochfrequente Daten gehören nicht in den Sollzustand. Der Signaturzähler eines Authentikators, der Zeitpunkt der letzten Anmeldung und der Sitzungsdatensatz sind **Istzustand**: knotenlokal gehalten, verdichtet repliziert (≤ 1 KB je Objekt nach K-12), datiert (INV-28) und nie Eingabe für eine Entscheidung außer zur Abweichungsanzeige. Andernfalls schriebe jede Anmeldung in ein quorumpflichtiges Protokoll. **Rechnung:** Annahme 500 Personen, Annahme 3 aktive Sitzungen je Person, Annahme 8 Anmeldevorgänge je Person und Arbeitstag ergibt 4.000 Anmeldungen je Tag = 0,046/s im Mittel. Als Konsensschreibvorgänge wären das 1,46 Millionen Protokolleinträge je Jahr; bei 200 Byte je Eintrag sind das 292 MB jährlich allein für Anmeldezeitpunkte, gegenüber einem Gesamtsollzustand von ≤ 50 MB (K-12). Die Einordnung als Istzustand ist damit nicht Geschmackssache, sondern rechnerisch erzwungen.

### Protokolle und ihre Zuständigkeit

| Protokoll | Zweck in Atrium | Typische Gegenstelle | Einschränkungen |
|---|---|---|---|
| OpenID Connect Core 1.0 über OAuth 2.0 (RFC 6749) mit PKCE (RFC 7636) | Anmeldung von Personen an der Atrium Console und an allen Diensten, die es können; Primärweg | Katalogeinträge mit OIDC-Unterstützung, Atrium Console selbst | Für Gruppenangaben existiert kein normierter Anspruch; die Abbildung erfolgt je Katalogeintrag und ist Teil der Produktgrenzdeklaration (INV-30). Abmeldung über mehrere Dienste hinweg ist nicht verlässlich normiert. |
| OAuth 2.0 Device Authorization Grant (RFC 8628) | Anmeldung auf Geräten ohne Browser und ohne Eingabemöglichkeit | Fernseher, Messgeräte, Kommandozeilenwerkzeuge | Der Benutzercode ist ein kurzlebiges Geheimnis mit geringer Entropie; er erfordert dieselbe Ratenbegrenzung wie der Kopplungscode und ist gegen Weiterleitungsbetrug nur durch eine ausdrückliche Anzeige des anfragenden Dienstes zu schützen. |
| OAuth 2.0 Token Exchange (RFC 8693) | Weitergabe einer Berechtigung von einem Dienst an einen nachgelagerten Dienst ohne Weitergabe des ursprünglichen Tokens | Dienst-zu-Dienst-Aufrufe innerhalb eines Mandanten | Erzeugt eine Delegationskette, die vollständig protokolliert werden muss, sonst ist im Audit nicht mehr erkennbar, in wessen Auftrag ein Aufruf erfolgte. |
| OAuth 2.0 Dynamic Client Registration (RFC 7591) | Die Anmeldeeinbindung eines Dienstes entsteht als abgeleitetes Artefakt aus dem Dienst, nicht durch Handeintrag | Von Atrium bereitgestellte Dienste | Nur für Dienste aus dem Katalog; eine offene dynamische Registrierung wäre ein unauthentisierter Schreibweg und ist abgeschaltet. |
| JWT (RFC 7519) im Profil für Zugriffstoken (RFC 9068) | Format der Zugriffstoken, lokal prüfbar am Eingang ohne Rückfrage | Envoy als Datenebene, Dienste mit Tokenprüfung | Lokale Prüfbarkeit und sofortiger Widerruf schließen einander aus; die Auflösung steht in Abschnitt 10.6. |
| SAML 2.0 (OASIS) | Nur über einen zuschaltbaren Protokollvermittler OIDC → SAML (Technologiefestlegung 9c) | Ältere kaufmännische Anwendungen, die ausschließlich SAML sprechen | Eigenwillige Signatur- und Verschlüsselungsprofile, uneinheitliche Attributformate, kein verlässliches Einzelabmelden. Die Grenze wird in der Konsole am Dienst angezeigt, nicht verschwiegen. |
| LDAPv3 (RFC 4511) über LDAPS auf 636/tcp | Lesender Verzeichniszugriff für Altsysteme, die weder OIDC noch SCIM sprechen | Netzwerkdrucker, Speichersysteme, ältere Fachanwendungen | Ausschließlich lesend; 389/tcp bleibt geschlossen; verschachtelte Gruppen werden für den Lesezugriff flachgeklopft; ein Simple Bind setzt ein Kennwort voraus und ist deshalb nur mit der Ausnahmeregel aus Abschnitt 10.5 zulässig. Nur intern erreichbar. |
| SCIM Schema und Protokoll (RFC 7643, RFC 7644), Server und Client in atrium-core auf 8405/tcp | Versorgung: Atrium als Ziel einer fremden Quelle und als Quelle für fremde Ziele | Personalsystem, Entra ID, Google Workspace, Fachanwendungen mit SCIM-Aufnahme | Kein normiertes Rollenkonzept, keine Verweigerungssemantik, kein Gültigkeitszeitraum je Mitgliedschaft; die Filtersyntax wird von Zielsystemen nur teilweise umgesetzt. Was dabei verlorengeht, steht in Abschnitt 10.9. |
| RADIUS (RFC 2865) mit EAP (RFC 3748) und EAP-TLS (RFC 5216), über Weitverkehr RadSec (RFC 6614) | Netzzugang von Geräten, nicht von Personen | Schalter und Funkzugangspunkte nach IEEE 802.1X | Prüft ausschließlich Gerätezertifikate gegen die interne Ausgabe-CA und deren Sperrliste (Technologiefestlegung 9b); kennwortbasierte EAP-Verfahren sind nicht vorgesehen. Siehe [Kapitel 13](13-geraeteverwaltung.md). |
| Kerberos v5 (RFC 4120) | Nicht angeboten. Begründung unten. | — | — |

### Abgrenzung gegen Kerberos

Kerberos v5 wird nicht erbracht, und zwar aus drei Gründen, die jeweils für sich tragen.

1. Ein Schlüsselverteilzentrum führt eine eigene Prinzipaldatenbank mit Langzeitschlüsseln, die bei Personen aus Kennwörtern abgeleitet werden. Das wäre ein zweiter Geheimnisspeicher neben dem Protokollkopf und eine Rückkehr zu genau dem Anmeldemittel, das Abschnitt 10.2 abschafft.
2. Ein Kerberos-Dienstticket ist ein Inhaberausweis mit typischerweise mehrstündiger Laufzeit und ohne Ursprungsbindung. Die Eigenschaft, die Passkeys gegen Weiterleitungsangriffe wirksam macht, fehlt ihm strukturell.
3. Die praktische Nachfrage richtet sich nicht auf Kerberos, sondern auf die Aufnahme von Windows-Arbeitsplätzen in eine Domäne. Das verlangt zusätzlich ein Verzeichnisschema mit den Objektklassen eines Verzeichnisdienstes eines bestimmten Herstellers, die zugehörigen Dienstlokalisierungseinträge im DNS, ein Replikationsprotokoll zwischen Domänencontrollern und eine Richtlinienverteilung an Arbeitsplätze. Das ist ein eigenes Produkt und nicht ein Protokollkopf mehr.

Die Folge wird ausdrücklich benannt und nicht beschönigt: **Atrium nimmt keine Windows-Arbeitsplätze in eine Domäne auf, und einmaliges Anmelden an klassischen SMB-Dateifreigaben über Kerberos ist nicht abgedeckt.** Wer das braucht, betreibt weiterhin einen Domänencontroller und verbindet ihn nach Abschnitt 10.9 als Föderationspartner oder über den SCIM-Weg. Ersatzweise deckt Atrium ab: Verzeichnislesezugriff über LDAPS, Netzzugang über EAP-TLS mit Gerätezertifikat, Anwendungsanmeldung über OIDC und Maschinenidentität über Zertifikate nach Abschnitt 10.11.

**Anforderungen**

- **R-10-01** — Das Konto einer Person im Protokollkopf trägt einen Quellverweis auf die Personenkennung und ist über die öffentliche API nicht schreibbar. Prüfbar: die API-Fassade weist für Protokollkopfkonten 0 Schreiboperationen aus (INV-01, INV-09).
- **R-10-02** — Eine Handänderung im Protokollkopf ist nach spätestens einem Volllauf des Reconcilers (K-16, ≤ 60 min) korrigiert und hat genau 1 Auditereignis vom Typ "Abweichung korrigiert" erzeugt. Prüfbar: Abweichungstest mit injizierter Direktänderung (INV-02).
- **R-10-03** — Bei eingefrorenem Sollzustand gelingen bestehende Anmeldungen weiter, und jeder Versuch, einen Authentikator zu registrieren, wird mit einer Meldung abgelehnt, die den Zustand benennt und keinen Konsolenbefehl als Lösung nennt. Prüfbar: Partitionstest gegen die Minderheitsseite (INV-04, INV-17).
- **R-10-04** — Signaturzähler, Zeitpunkt der letzten Anmeldung und Sitzungsdatensätze erscheinen in 0 Protokolleinträgen des Sollzustands. Prüfbar: Inhaltsprüfung eines Sollzustandsexports gegen Muster dieser Felder.
- **R-10-05** — Der LDAP-Protokollkopf beantwortet 0 Schreiboperationen und ist auf 389/tcp nicht erreichbar. Prüfbar: Protokolltest mit Änderungsversuch und Portabtastung aus jeder Netzzone.

## 10.2 Passkeys als Vorgabe

Das vorgegebene Anmeldemittel ist ein Passkey nach WebAuthn Level 3 mit CTAP2 als Geräteschnittstelle. Kennwörter sind kein Rückfallweg, sondern eine ausdrücklich zu aktivierende Ausnahme (Abschnitt 10.5). Drei Argumente tragen die Entscheidung, und nur zwei davon betreffen Entropie.

**Erstes Argument, Entropie.** Der private Schlüssel eines Passkeys wird vom Authentikator aus einer kryptographischen Zufallsquelle erzeugt und erreicht das Sicherheitsniveau des Verfahrens, also 128 Bit Klasse. Ein Kennwort erreicht bestenfalls die Entropie seiner Erzeugungsregel. **Rechnung:** ein zufälliges Kennwort aus 12 Zeichen über 95 druckbaren Zeichen trägt 12 · log₂(95) = 12 · 6,57 = 78,8 Bit. Eine Passphrase aus 6 Wörtern einer Liste mit 7.776 Einträgen trägt 6 · log₂(7.776) = 6 · 12,925 = 77,6 Bit. Beide liegen um mehr als 40 Bit unter dem Passkey. Der Abstand ist groß, aber nicht das entscheidende Argument, denn 78 Bit sind gegen Raten ausreichend.

**Zweites Argument, Ursprungsbindung.** Entropie hilft gegen Raten, nicht gegen Weiterleitung. Ein Kennwort mit 78 Bit ist, sobald die Person es auf einer nachgebauten Anmeldeseite eingibt, genauso verloren wie eines mit 20 Bit. WebAuthn bindet die Signatur an den Ursprung der aufrufenden Seite; der Authentikator erzeugt für einen fremden Ursprung schlicht keine Antwort. Der Angriff scheitert nicht an der Aufmerksamkeit der Person, sondern am Protokoll. Das ist der eigentliche Grund der Festlegung.

**Drittes Argument, keine serverseitige Geheimnisdatenbank.** Der Protokollkopf speichert öffentliche Schlüssel. Ein vollständiger Diebstahl seines Speichers erlaubt keinen Offline-Angriff, weil nichts zu raten ist. Bei Kennwörtern existiert eine Datenbank, deren Diebstahl den Angreifer in eine Lage bringt, in der ihm nur noch Rechenzeit fehlt. Die gesamte Parameterwahl in Abschnitt 10.5 dient allein dazu, diesen Fall teurer zu machen; bei Passkeys existiert der Fall nicht.

### Authentikatorklassen

| Klasse | Wo der private Schlüssel liegt | Stärke | Schwäche | Verwendung in Atrium |
|---|---|---|---|---|
| Plattformgebunden | Im Sicherheitsbaustein des Geräts (TPM 2.0 oder gleichwertig), nicht auslesbar | Kann bescheinigt und damit an ein **Gerät**-Objekt gebunden werden; Besitz und Gerätezustand fallen zusammen | Geht mit dem Gerät verloren; nicht auf ein Ersatzgerät übertragbar | Vorgabe auf verwalteten Geräten; einzige Klasse, die eine gerätegebundene Richtlinie erfüllen kann |
| Übertragbar (Sicherheitsschlüssel) | Auf einem eigenständigen Gegenstand, nicht auslesbar | Vom Gerät unabhängig; im Tresor verwahrbar; bescheinigbar | Physisch verlierbar; Beschaffung und Verteilung sind ein logistischer Vorgang | Vorgeschriebener Zweitauthentikator für Rollen mit Plattform- oder Mandantenrechten |
| Anbietersynchronisiert | Im Schlüsselbund eines Anbieters, über dessen Konten der Person repliziert | Überlebt Geräteverlust ohne Zutun; hohe Alltagstauglichkeit | Der Schutz hängt an der Kontosicherheit eines Dritten; eine Bescheinigung ist in der Regel nicht verfügbar, das Schlüsselmaterial ist damit keinem Gerät zuordenbar | Zulässig für Personen ohne erhöhte Rechte, sofern die Richtlinie des Mandanten es erlaubt; nicht ausreichend für Administratorrollen |

Die Richtlinie je Mandant legt fest, welche Klassen für welche Rolle genügen. Die Prüfung erfolgt beim Setzen der Zuweisung, nicht bei der Anmeldung: eine Administratorrolle lässt sich einer Person, die die Klassenvorgabe nicht erfüllt, gar nicht erst zuweisen, und die Wirkungsvorschau nennt den Grund. Das ist bewusst die härtere Variante, weil eine Prüfung bei der Anmeldung eine Person mit erhöhten Rechten erst dann aussperrt, wenn sie gebraucht wird.

**Bescheinigung ist eine Dauerlast, keine einmalige Einstellung.** Um eine plattformgebundene von einer synchronisierten Anmeldung zu unterscheiden, muss die Kontrollebene die Kennung des Authentikatormodells gegen eine Liste bekannter Modelle prüfen. Diese Liste veraltet. Eine Installation ohne Internetzugang kann sie nicht auffrischen und stuft danach neue, an sich zulässige Authentikatoren als unbekannt ein. Der Entwurf behandelt eine unbekannte Modellkennung nicht als Ablehnung, sondern als Einstufung "nicht bescheinigt" mit sichtbarer Folge an der Rolle. Das ist eine Milderung und keine Lösung; die Pflege der Liste bleibt ein offener Punkt.

### Registrierung und Verlustfall

Die Registrierung eines Authentikators ist ein Vorgang mit genau zwei Entscheidungen (Klasse und Benennung), sie ist damit innerhalb von INV-14. Sie verlangt eine bestehende, nicht ältere als 5 Minuten alte Authentisierung oder einen Erstregistrierungscode nach Abschnitt 10.7. Jede Registrierung erzeugt eine Benachrichtigung an alle hinterlegten Kanäle der Person, und zwar **bevor** der neue Authentikator benutzbar wird, mit einer Widerspruchsmöglichkeit innerhalb einer Frist. Die Reihenfolge ist wesentlich: eine Benachrichtigung nach der Wirksamkeit meldet der Person nur noch, dass sie bereits übernommen wurde.

Beim Verlust eines Geräts ist die Sperrung des Geräts der führende Vorgang, nicht die Sperrung des Passkeys. Der Ablauf folgt dem Objektmodell: Gerät auf "verloren/gesperrt" setzen, Gerätezertifikate sperren, Sperrliste sofort verteilen (siehe [Kapitel 11](11-pki.md)), und der auf diesem Gerät registrierte Authentikator wird als Authentisierungsmittel der Person entwertet. Verbleibt danach mindestens ein gültiger Authentikator, ist der Fall abgeschlossen und die Person arbeitet weiter. Verbleibt keiner, greift Abschnitt 10.3.

**Anforderungen**

- **R-10-06** — Eine Zuweisung einer Rolle mit Plattform- oder Mandantengeltung an eine Person mit weniger als zwei registrierten Authentikatoren oder ohne einen Authentikator der von der Richtlinie geforderten Klasse wird abgelehnt; die Ablehnung nennt die fehlende Klasse. Prüfbar: Zuweisungstest je Klassenkombination.
- **R-10-07** — Eine WebAuthn-Antwort, die für einen anderen Ursprung als den der Atrium Console erzeugt wurde, wird abgelehnt und erzeugt 1 Auditereignis. Prüfbar: Protokolltest mit manipuliertem Ursprungsfeld.
- **R-10-08** — Der Speicher des Protokollkopfs enthält 0 Werte, aus denen sich ein Anmeldegeheimnis einer Person offline ableiten lässt, solange für diese Person keine Kennwortausnahme nach Abschnitt 10.5 aktiv ist. Prüfbar: Inhaltsprüfung des Speichers gegen Muster von Ableitungsausgaben.
- **R-10-09** — Die Benachrichtigung über eine Authentikatorregistrierung geht nachweislich vor der Wirksamkeit des Authentikators heraus. Prüfbar: Reihenfolgeprüfung anhand der Zeitstempel in Auditstrom und Benachrichtigungsprotokoll.
- **R-10-10** — Ein Authentikator mit unbekannter Modellkennung wird als "nicht bescheinigt" geführt und erfüllt 0 Richtlinien, die eine Gerätebindung verlangen. Prüfbar: Registrierung mit unbekannter Kennung, anschließender Zuweisungsversuch.

## 10.3 Kontowiederherstellung ohne Kennwort

Kontowiederherstellung ist das schwierigste Problem dieses Kapitels, weil jede Lösung eine Tür ist. Der Entwurf geht von zwei Sätzen aus, die alles Weitere bestimmen.

**Satz 1: Wiederherstellung ist kein Anmeldeweg.** Sie ist ein Vorgang, der die Authentisierungsmittel einer Person im Sollzustand ändert. Damit unterliegt sie automatisch der Wirkungsvorschau, der Freigabepflicht, dem Audit und der Rücknahme (INV-03, INV-08, INV-23). Es gibt keinen Programmpfad, der aus einem Wiederherstellungsnachweis eine Sitzung erzeugt.

**Satz 2: Die Wiederherstellung darf nie schneller, leiser oder schwächer sein als der reguläre Weg.** Sonst ist sie der Angriffsweg, und zwar der bevorzugte. Konkret heißt das: sie prüft mindestens so viele unabhängige Merkmale, sie erzeugt mehr Benachrichtigungen, und sie dauert länger.

### Verfahren nach verbleibendem Nachweis

| Klasse | Was der Person geblieben ist | Verfahren | Wartezeit (Zielwert) | Freigabe |
|---|---|---|---|---|
| W1 | Mindestens ein weiterer gültiger Authentikator | Selbstbedienung: mit dem verbleibenden Authentikator einen neuen registrieren | 0 | keine, nur Benachrichtigung an alle Kanäle |
| W2 | Kein Authentikator, aber ein verwaltetes Gerät mit gültigem, nicht gesperrtem Gerätezertifikat | Registrierung eines neuen plattformgebundenen Authentikators auf genau diesem Gerät, zusätzlich Bestätigung über einen zweiten, vorab hinterlegten Kanal | 1 h | keine, aber Vier-Augen-Pflicht bei Personen mit Administratorrolle |
| W3 | Kein Authentikator, kein verwaltetes Gerät, aber eine benannte Vertretung | Vertretung stellt Antrag, Person bestätigt über einen hinterlegten Kanal, Ausgabe eines Erstregistrierungscodes über einen anderen Kanal als den Antrag | 8 h | Vier-Augen: Vertretung plus eine Person mit der Rolle "Wiederherstellung freigeben" |
| W4 | Nichts davon | Antrag durch eine Person mit der Rolle "Wiederherstellung freigeben", Identitätsfeststellung außerhalb des Systems mit Protokollpflicht, Erstregistrierungscode nur an eine im Sollzustand bereits hinterlegte Anschrift | 24 h | Vier-Augen durch zwei Personen mit dieser Rolle, die nicht identisch mit dem Antragsteller sein dürfen |
| W5 | Person mit Plattformrolle, Klasse W3 oder W4 | wie W3/W4, zusätzlich Entzug aller Plattformrechte für die Dauer des Verfahrens und erneute Zuweisung als eigener Vorgang nach Abschluss | 24 h | Vier-Augen plus Benachrichtigung aller Plattformadministratoren |

Die Rolle "Wiederherstellung freigeben" ist von der Rolle "Personen verwalten" getrennt. Wer Personen anlegt, darf deren Anmeldemittel nicht ersetzen, und umgekehrt. Ohne diese Trennung genügt ein einziges kompromittiertes Administratorkonto, um jede beliebige Identität zu übernehmen.

**Herleitung der Wartezeit.** Die Wartezeit hat genau einen Zweck: der rechtmäßigen Person Zeit zum Widerspruch zu geben. Sie ist daher an die Reaktionszeit zu bemessen, nicht an ein Gefühl. **Annahme:** Benachrichtigung über zwei voneinander unabhängige Kanäle; **Annahme:** die betroffene Person bemerkt eine Benachrichtigung an Werktagen innerhalb von 8 h in 95 % der Fälle. Daraus folgt W3 mit 8 h als die Stufe, bei der die Person selbst mitgewirkt hat und der Widerspruch zusätzlich abgesichert ist. W4 setzt 24 h an, weil dort keine Mitwirkung der Person vorliegt und ein Wochenende oder eine Abwesenheit überbrückt werden muss; 24 h decken einen Werktag plus Reserve ab, nicht jedoch einen Urlaub. Das ist die Grenze des Verfahrens: gegen einen Angreifer, der den Urlaubszeitraum kennt, wirkt die Wartezeit nicht. Die Gegenmaßnahme ist nicht mehr Wartezeit, sondern die Vertretungsregel, die den Angriff auf ein zweites Konto ausweitet.

**Vertretungsregel.** Jede Person kann eine Vertretung benennen; die Benennung ist ein Objekt im Sollzustand, sichtbar auf der eigenen Seite der Person und in deren Verlauf. Die Vertretung kann einen Wiederherstellungsantrag stellen, nicht freigeben. Der Entwurf benennt die Kehrseite: eine Vertretung ist eine zusätzliche Angriffsfläche, weil sie eine zweite Person in den Vertrauenskreis eines Kontos zieht. Deshalb ist die Vertretung nie allein handlungsfähig, sie wird über jede Nutzung ihrer Rolle benachrichtigt, und die betroffene Person sieht die Benennung dauerhaft, statt sie einmal zu bestätigen und zu vergessen.

**Angriffsbetrachtung.** Ein Angreifer, der Klasse W4 ausnutzen will, muss vier Bedingungen gleichzeitig erfüllen: zwei unabhängige Konten mit der Rolle "Wiederherstellung freigeben" übernehmen, eine Identitätsfeststellung außerhalb des Systems bestehen, die Zustellung an eine im Sollzustand hinterlegte Anschrift abfangen und die Benachrichtigungen 24 h lang unbemerkt lassen. **Rechnung mit benannten Annahmen:** Annahme, ein einzelnes Administratorkonto mit Passkey-Pflicht und zwei Authentikatoren wird innerhalb eines Jahres mit Wahrscheinlichkeit q = 0,01 übernommen; Annahme, die Übernahmen sind unabhängig, was bei gemeinsamem Arbeitsplatz oder gemeinsamer Zustellkette nicht gilt und die Rechnung optimistisch macht. Bei 5 Trägern der Rolle ist die Wahrscheinlichkeit, dass mindestens zwei betroffen sind, 1 − (1 − q)⁵ − 5q(1 − q)⁴ = 1 − 0,95099 − 0,04803 = 0,00098, also rund 1 zu 1.020 je Jahr. Ohne Vier-Augen-Pflicht wäre es 1 − (1 − q)⁵ = 0,049, also rund 1 zu 20. Der Faktor beträgt 50. Das ist eine Modellrechnung, keine Messung, und ihr schwächstes Glied ist die Unabhängigkeitsannahme.

**Was nicht wiederherstellbar ist.** Wo Daten mit Schlüsseln geschützt sind, die ausschließlich auf dem verlorenen Authentikator lagen, stellt kein Verfahren sie wieder her. Der Entwurf zieht daraus eine Konsequenz für die Plattform: Atrium legt keine Nutzdaten hinter Schlüssel, die nur auf einem Authentikator existieren. Wo ein Katalogeintrag das dennoch tut, ist das in seiner Produktgrenzdeklaration (INV-30) auszuweisen, und die Konsole benennt vor der Wiederherstellung ausdrücklich, welche Daten dabei verloren gehen. Eine Wiederherstellung, die stillschweigend Daten vernichtet, ist ein Fehler, keine Funktion.

**Anforderungen**

- **R-10-11** — Kein Wiederherstellungsverfahren erzeugt eine Sitzung; jedes endet mit der Registrierung eines Authentikators durch die Person selbst. Prüfbar: die API kennt für Wiederherstellungsvorgänge 0 Operationen, die ein Token ausgeben.
- **R-10-12** — Die Rollen "Personen verwalten" und "Wiederherstellung freigeben" sind derselben Person nicht gleichzeitig zuweisbar; ein Versuch wird abgelehnt und auditiert. Prüfbar: Zuweisungstest.
- **R-10-13** — Ein Wiederherstellungsvorgang der Klasse W3 oder W4 wird vor Ablauf der Wartezeit nicht ausführbar, auch nicht durch eine Person mit Plattformrolle. Prüfbar: Ausführungsversuch nach Ablauf von 50 % der Wartezeit.
- **R-10-14** — Jeder Wiederherstellungsvorgang erzeugt Benachrichtigungen an alle hinterlegten Kanäle der betroffenen Person bei Antragstellung, bei Freigabe und bei Abschluss, also genau 3 Zustellungen je Kanal. Prüfbar: Zustellprotokoll je Verfahrensdurchlauf.
- **R-10-15** — Die Wirkungsvorschau eines Wiederherstellungsvorgangs nennt jeden Dienst, dessen Daten dabei unwiederbringlich verloren gehen, namentlich. Prüfbar: Vorschauprüfung gegen die Produktgrenzdeklarationen der zugewiesenen Dienste (INV-08, INV-30).

## 10.4 Notfallzugang zur Plattform

Die Wiederherstellung einer Person löst nicht den Fall, dass niemand mehr die Plattform selbst verwalten kann. Dafür existiert der **Notzugang** mit dem bei der Erstinstallation erzeugten **Wiederherstellungscode** (256 Bit Entropie, zum Ausdrucken, im System nur als Verifikationswert vorhanden).

### Verwahrung als geteiltes Geheimnis

Ein einzelnes Blatt Papier hat zwei Fehlermodi: es verschwindet, und es wird gefunden. Der Entwurf teilt den Code deshalb optional in n Anteile auf, von denen k zur Rekonstruktion genügen. Die Wahl von k und n ist rechenbar, wenn man beide Fehlermodi getrennt bewertet.

**Rechnung Verfügbarkeit.** Annahme: ein Anteilsträger ist im Notfall mit Wahrscheinlichkeit p = 0,9 unabhängig erreichbar. Wahrscheinlichkeit, dass mindestens k von n erreichbar sind:

```
k=2, n=3 : C(3,2)p^2(1-p) + p^3
         = 3 * 0,81 * 0,1 + 0,729
         = 0,243 + 0,729 = 0,972

k=3, n=5 : C(5,3)p^3(1-p)^2 + C(5,4)p^4(1-p) + p^5
         = 10 * 0,729 * 0,01 + 5 * 0,6561 * 0,1 + 0,59049
         = 0,0729 + 0,32805 + 0,59049 = 0,99144
```

**Rechnung Missbrauch.** Annahme: ein Anteilsträger wird unabhängig mit q = 0,01 kompromittiert oder handelt böswillig. Wahrscheinlichkeit, dass mindestens k Anteile in einer Hand zusammenkommen:

```
k=2, n=3 : 3 * q^2 * (1-q) + q^3
         = 3 * 1e-4 * 0,99 + 1e-6 = 2,98e-4

k=3, n=5 : 10 * q^3 * (1-q)^2 + 5 * q^4 * (1-q) + q^5
         = 10 * 1e-6 * 0,9801 + 5 * 1e-8 * 0,99 + 1e-10
         = 9,801e-6 + 4,95e-8 + 1e-10 = 9,85e-6
```

**Deutung.** 3-von-5 ist der 2-von-3-Aufteilung in beiden Kriterien überlegen: um den Faktor 1,020 bei der Verfügbarkeit (0,99144 gegen 0,972, also 8,6 statt 28 Ausfälle je 1.000 Notfälle) und um den Faktor 30 beim Missbrauch (9,85 · 10⁻⁶ gegen 2,98 · 10⁻⁴). Der Preis ist organisatorisch, nicht technisch: es müssen fünf Personen benannt, geschult und bei Personalwechsel nachgeführt werden. **Zielwert:** 3-von-5, Rückfall 2-von-3, wenn der Mandant keine fünf Träger benennen kann. Ein ungeteilter Code ist zulässig, wird aber in der Konsole dauerhaft als degradierter Zustand geführt (INV-18). Das ist ein Modell mit gesetzten Annahmen; p und q sind nicht gemessen, und die Unabhängigkeitsannahme ist bei Anteilen im selben Gebäude falsch, weshalb die Konsole beim Anlegen nach getrennten Verwahrorten fragt.

### Auslösung, Alarmierung, Nachbereitung

```
Ausloesung
  1. Physische oder Fernkonsole eines Knotens, Neustart in den Wiederherstellungsmodus
  2. Eingabe der k Anteile; Pruefung gegen den Verifikationswert in konstanter Zeit
  3. Erzwungene Wartezeit (Zielwert 15 min) mit Klartextwarnung; in dieser Zeit laufen
     Alarmierungen bereits heraus und koennen den Vorgang noch abbrechen
  4. Zeitlich begrenzte Notzugangssitzung (Zielwert 60 min, nicht verlaengerbar,
     nur an dieser Konsole gueltig, kein Fernzugang)

Alarmierung, nicht unterdrueckbar
  - 1 Auditereignis vom Typ notzugang.ausgeloest, ausserhalb des replizierten
    Kernzustands geschrieben (INV-23), also auch ohne Quorum vorhanden
  - Zustellung an alle Traeger einer Plattformrolle, alle Anteilstraeger und
    das hinterlegte Alarmziel
  - Dauerhaftes Banner im Ueberblick, bis die Nachbereitung abgeschlossen ist

Nachbereitung, erzwungen
  - Alle in der Notzugangssitzung erzeugten Vorgaenge tragen die Markierung
    "unter Notzugang" und sind im Verlauf gefiltert abrufbar
  - Frist 72 h: schriftliche Begruendung am Vorgang, sonst bleibt das Banner stehen
    und jede weitere Notzugangsausloesung verlangt zusaetzlich Vier-Augen
  - Pflichtwechsel des Wiederherstellungscodes; die alten Anteile werden entwertet
  - Widerruf aller Sitzungen, die waehrend des Fensters entstanden sind
    (token_nicht_vor je betroffener Person, siehe 10.6)
```

Die Begrenzung auf die physische oder die Fernkonsole eines Knotens ist die eigentliche Sicherung. Der Code allein genügt nicht; es muss jemand an einer Maschine sein. Damit ist ein reiner Fernangriff über das Netz auf diesen Weg ausgeschlossen, und ein Herstellerfernzugang existiert nicht.

**Zwei Schwächen werden hier benannt und nicht gelöst.** Erstens: wer physischen Zugang zu einem Knoten hat, kann den Knoten zerstören, die Datenträger entnehmen oder ihn abschalten. Der Notzugang schützt die Vertraulichkeit des Sollzustands, nicht die Verfügbarkeit der Hardware; dagegen wirkt nur Verschlüsselung der Datenträger mit TPM-Bindung und physische Zugangskontrolle, die außerhalb dieses Produkts liegt. Zweitens: bei gemieteten Servern ist die Fernkonsole anbieterseitig erreichbar. Für diesen Fall ist die Bindung an "physische Konsole" faktisch eine Bindung an "Anbieterzugang", und der Vorteil gegenüber einem reinen Fernweg schrumpft. Die Konsole kennzeichnet einen Knoten mit anbieterseitig mitlesbarer Fernkonsole entsprechend, und die Empfehlung lautet, den Notzugang auf einen Knoten unter eigener physischer Kontrolle zu beschränken.

**Anforderungen**

- **R-10-16** — Der Wiederherstellungscode ist im laufenden System, in jedem Sollzustandsexport und in jedem Wiederherstellungspunkt ausschließlich als Verifikationswert vorhanden. Prüfbar: Inhaltsprüfung gegen das Codemuster; ein Treffer bricht den Bau (INV-20).
- **R-10-17** — Die Prüfung der Anteile erfolgt laufzeitkonstant; die Antwortzeit unterscheidet sich zwischen richtigem und falschem Anteil um weniger als die Messauflösung. Prüfbar: Zeitmessreihe über je 10.000 richtige und falsche Anteile.
- **R-10-18** — Eine Notzugangsauslösung erzeugt auch ohne Quorum genau 1 Auditereignis und mindestens 1 Zustellung je hinterlegtem Alarmziel. Prüfbar: Auslösung auf der Minderheitsseite einer Partition (INV-23).
- **R-10-19** — Nach Ablauf von 72 h ohne abgeschlossene Nachbereitung verlangt jede weitere Notzugangsauslösung Vier-Augen, und das Banner im Überblick ist unverändert sichtbar. Prüfbar: Fristüberschreitungstest.

## 10.5 Kennwörter, wo sie unvermeidbar sind

Kennwörter bleiben an genau zwei Stellen: beim Simple Bind eines Altsystems gegen den LDAP-Protokollkopf und bei einer Person, deren Arbeitsmittel WebAuthn nachweislich nicht beherrschen. Alle anderen Stellen sind durch die Portmatrix ausgeschlossen, insbesondere die Einlieferung und der Postfachzugriff, die über Bearer Token nach RFC 6750 oder Passkey-gebundene Anmeldung laufen (siehe [Kapitel 14](14-mail.md)).

Ein Kennwort entsteht nur als **Kennwortausnahme**: ein Objekt mit Person, Grund im Klartext, Geltungsbereich (welcher Zugang genau) und Überprüfungsfrist (Zielwert 12 Monate). Die Ausnahme ist an der Person und im Überblick als degradierter Zustand sichtbar, nicht in einem Bericht (INV-18). Das Anlegen kostet zwei Entscheidungen (Person, Grund); Geltungsbereich und Frist folgen aus der Richtlinie (INV-15).

### Argon2id-Parameterwahl

Das Ableitungsverfahren ist Argon2id nach RFC 9106. Die Parameter folgen aus drei Nebenbedingungen, nicht aus einer Empfehlung.

| Nebenbedingung | Herkunft | Folge für die Parameter |
|---|---|---|
| Spitzenspeicher der Ableitung ≤ 1 GiB | K-19 begrenzt Kontrollebene, Eingang, DNS, Resolver und Protokollkopf zusammen auf ≤ 4 GB; ein Viertel davon ist die Obergrenze für eine einzelne Funktion | m · c ≤ 1 GiB, mit c als Zahl gleichzeitiger Ableitungen |
| Beitrag zur Anmeldelatenz ≤ 1 s | K-18 setzt p95 ≤ 300 ms für Listen; eine Anmeldung ist kein Listenaufruf, 1 s ist der gesetzte Höchstwert | Laufzeit einer Ableitung ≤ 0,5 s bei einem Kern |
| Gleichzeitigkeit c muss die Spitzenlast tragen | Little: L = λ · W | c aus der Anmelderate |

**Rechnung zur Gleichzeitigkeit.** Annahme: 500 Personen (K-12), Annahme: höchstens 5 % mit Kennwortausnahme = 25 Personen, Annahme: 2 Anmeldungen je Person und Arbeitstag = 50 Ableitungen je 8-Stunden-Tag = 50 / 28.800 s = 1,74 · 10⁻³/s im Mittel; Annahme: Spitzenfaktor 10 gegenüber dem Mittel ergibt λ = 0,0174/s. Mit W = 0,5 s folgt L = 0,0174 · 0,5 = 0,0087 gleichzeitige Ableitungen. Die gesetzte Grenze c = 4 liegt um den Faktor 460 darüber und dient der Abwehr einer Lastspitze, nicht der Normallast. Aus c = 4 und m · c ≤ 1 GiB folgt **m = 256 MiB**.

**Zeit und Parallelität.** Der Zeitparameter t skaliert Verteidiger und Angreifer gleich; er kauft keinen Vorteil, sondern nur Latenz. Der Parallelitätsparameter p darf die Zahl der Kerne nicht überschreiten, sonst konkurrieren die Bahnen um dieselbe Recheneinheit; bei ≤ 2 Kernen nach K-19 und c = 4 gleichzeitigen Ableitungen ist **p = 1** die einzige Wahl, die die Latenzzusage unter Last hält. t wird so gesetzt, dass die Laufzeit bei m = 256 MiB und p = 1 die Grenze von 0,5 s erreicht; **Zielwert t = 4**, mit der ausdrücklichen Auflage, den Wert auf der Zielhardware zu messen und nachzuziehen, statt ihn zu glauben. Der Salzwert ist 16 Byte aus einer kryptographischen Zufallsquelle je Kennwort, die Ausgabe 32 Byte.

**Pfeffer.** Die Ableitungsausgabe wird vor der Speicherung mit einem Schlüssel gehasht, der im TPM des Knotens liegt und diesen nicht verlässt (INV-20). Ein gestohlener Speicherauszug erlaubt damit keinen Offline-Angriff, solange der TPM-Schlüssel nicht mitgestohlen wurde. Der Preis ist ein zusätzlicher Wiederherstellungsfall: geht der Knoten verloren, sind die Kennwortprüfwerte unbrauchbar. Da der Pfefferschlüssel ein Geheimnis je Mandant ist und über die reguläre Geheimnisverwaltung repliziert wird, ist der Fall behandelt; die Abhängigkeit wird hier trotzdem genannt, weil sie beim Wiederaufbau aus einem Sollzustandsexport auffällt, der Geheimnisse nur als Referenz führt.

**Rechnung Angreiferkosten.** Argon2id mit m = 256 MiB und t = 4 bewegt je Auswertung rund 2 · t · m = 2 GiB Speicherverkehr. Annahme: Angreiferhardware mit 1 TB/s Speicherbandbreite entspricht 931 GiB/s, also 931 / 2 = 465 Auswertungen/s je Einheit; Annahme: 100 solcher Einheiten ergeben 46.500 Auswertungen/s.

```
Kandidatenraum                          Versuche       Zeit bei 46.500/s
--------------------------------------  -------------  -----------------
Nutzergewaehltes Kennwort, 28 bit        2,68e8         5.773 s  = 1,6 h
Zufaellig 12 Zeichen aus 95, 78,8 bit    5,40e23        1,16e19 s
Passphrase 5 Woerter aus 7.776, 64,6 bit 2,86e19        6,14e14 s = 1,95e7 a
Passphrase 6 Woerter aus 7.776, 77,6 bit 2,21e23        4,76e18 s
```

**Deutung.** Das Ableitungsverfahren verwandelt ein nutzergewähltes Kennwort nicht in ein sicheres; es kauft Stunden. Nur Entropie kauft Jahre. Daraus folgt die Festlegung: **Kennwörter werden vom System erzeugt, nicht von der Person gewählt.** Erzeugt wird eine Passphrase aus 6 Wörtern einer Liste mit 7.776 Einträgen (77,6 Bit). Fünf Wörter genügen gegen den oben gerechneten Angreifer, fallen aber bei einem um den Faktor 10⁴ stärkeren Angreifer auf 6,14 · 10¹⁰ s ≈ 1.950 Jahre; sechs Wörter halten dort 4,76 · 10¹⁴ s. Der Aufpreis ist ein Wort. Die Passphrase ist abschreibbar und vorlesbar, ein zufälliges 12-Zeichen-Kennwort gleicher Entropie ist es nicht.

### Sperr- und Verzögerungslogik

Eine harte Dauersperre nach n Fehlversuchen ist ein Dienstverweigerungsangriff gegen die Person: der Angreifer braucht nur zu raten, um sie auszusperren. Der Entwurf verzögert stattdessen und sperrt erst weit oben.

```
Verzoegerung je Konto, n = Zahl der Fehlversuche seit letztem Erfolg
  n <= 3            : keine Verzoegerung
  4 <= n <= 8       : Verzoegerung = 2^(n-3) s        (2, 4, 8, 16, 32 s)
  n > 8             : Verzoegerung = 32 s             (Deckel)

Zusaetzlich je Quelladresse: Tokeneimer, Zielwert 10 Versuche je Minute,
Nachfuellrate 1 je 6 s, unabhaengig vom betroffenen Konto

Sperre mit Zweitkanalpflicht: ab 100 Fehlversuchen je Konto in 24 h;
  keine Dauersperre, sondern Fortsetzung nur nach Bestaetigung ueber einen
  hinterlegten Kanal; die Person wird in jedem Fall benachrichtigt
```

**Rechnung Online-Raten.** Mit dem Deckel von 32 s sind je Konto höchstens 86.400 / 32 = 2.700 Versuche je Tag möglich. Gegen die erzeugte 6-Wort-Passphrase (2,21 · 10²³ Kandidaten) ist das bedeutungslos. Gegen ein hypothetisch nutzergewähltes Kennwort mit 20 Bit (1.048.576 Kandidaten) ergäbe sich 1.048.576 / 2.700 = 388 Tage bis zur vollständigen Durchsuchung, im Mittel 194 Tage. Die Verzögerungslogik allein trägt also bereits gegen schwache Geheimnisse, solange der Angreifer nicht nur einen einzigen, bereits bekannten Versuch braucht. Genau dafür existiert der Leckabgleich.

### Abgleich gegen bekannte geleakte Kennwörter

Der Abgleich erfolgt lokal und nie über eine Onlineabfrage bei einem Dritten. Eine Onlineabfrage gäbe ein Präfix des Geheimnisses aus der Hand, erzeugte eine Außenabhängigkeit für einen Vorgang, der offline funktionieren muss, und wäre ein Kanal, über den sich beobachten ließe, wann in einer Installation Kennwörter gesetzt werden.

**Rechnung Speicherbedarf.** Ein Bloomfilter benötigt bei Falschtrefferrate P rund log₂(1/P) · 1,4427 Bit je Eintrag. Für P = 10⁻² sind das 6,644 · 1,4427 = 9,59 Bit = 1,20 Byte je Eintrag.

```
10^7 Eintraege : 1,20e7 Byte  = 11,4 MiB   -> Teil des Systemabbilds
10^9 Eintraege : 1,20e9 Byte  = 1,12 GiB   -> eigener Speicherbereich, optional
```

**Entscheidung:** mitgeliefert wird die Menge mit 10⁷ Einträgen (11,4 MiB, signiert, mit dem Abbild verteilt und mit ihm versioniert). Größere Mengen sind ein eigener, ausdrücklich eingerichteter Speicherbereich. Ein Falschtreffer führt zur Verwerfung einer erzeugten Passphrase und zur Erzeugung einer neuen; bei P = 10⁻² betrifft das 1 % der Erzeugungen und ist folgenlos, weil die Person die verworfene Passphrase nie gesehen hat. Beim seltenen Fall eines von der Person eingebrachten Kennworts ist ein Falschtreffer eine unbegründete Ablehnung; die Meldung nennt deshalb nur, dass das Kennwort nicht verwendbar ist, ohne die Trefferquelle zu benennen.

### Sichere Praxis an der Schnittstelle

- Die Antwort auf einen fehlgeschlagenen Anmeldeversuch ist für unbekannten Anmeldenamen und falsches Kennwort textgleich und zeitgleich; bei unbekanntem Anmeldenamen wird eine Ableitung gegen einen festen Blindwert gerechnet, damit die Antwortzeit keine Benutzeraufzählung erlaubt.
- Der Vergleich des Ableitungsergebnisses erfolgt laufzeitkonstant.
- Die Fehlermeldung nennt weder den Zustand des Kontos noch die verbleibende Zahl der Versuche; die Einzelheiten stehen im Auditereignis.
- Kennwörter erscheinen in 0 Protokollzeilen, 0 Metriken und 0 Fehlerberichten; die Ausgabeprüfung nach INV-20 schließt die Anmeldepfade ein.
- Der Anmeldeendpunkt validiert seine Eingabe gegen ein Schema am Rand, bevor eine Ableitung gestartet wird, damit eine überlange Eingabe nicht zu einer speicherintensiven Rechnung führt; die Obergrenze für die Kennworteingabe ist ein gesetzter Wert (Zielwert 1.024 Byte).

**Anforderungen**

- **R-10-20** — Ein Kennwort existiert nur bei aktiver Kennwortausnahme mit Grund im Klartext und Überprüfungsfrist; ohne Ausnahme lehnt die API das Setzen ab. Prüfbar: Setzversuch ohne Ausnahmeobjekt.
- **R-10-21** — Die Kennwortableitung verwendet Argon2id mit m = 256 MiB, t = 4, p = 1, 16 Byte Salz je Kennwort und einen TPM-gebundenen Pfefferschlüssel; der Spitzenspeicher überschreitet 1 GiB nicht. Prüfbar: Parameterprüfung plus Lasttest mit 8 gleichzeitigen Anmeldungen und Speichermessung.
- **R-10-22** — Die Antwortzeit eines fehlgeschlagenen Anmeldeversuchs unterscheidet sich zwischen unbekanntem Anmeldenamen und falschem Kennwort um weniger als 5 % des Mittelwerts. Prüfbar: Zeitmessreihe über je 10.000 Versuche.
- **R-10-23** — Ein erzeugtes Kennwort besteht aus mindestens 6 Wörtern der mitgelieferten Liste und wird vor der Ausgabe gegen die Leckmenge geprüft; ein Treffer führt zur Neuerzeugung. Prüfbar: Erzeugungstest mit eingeschleustem Treffer.
- **R-10-24** — Nach 8 Fehlversuchen beträgt die erzwungene Verzögerung mindestens 32 s, und es entsteht 0 Dauersperre ohne Zweitkanalweg. Prüfbar: Fehlversuchsreihe mit Zeitmessung und anschließendem Anmeldeversuch mit richtigem Kennwort.

## 10.6 Sitzungen und Token

### Einordnung von Sitzungen

Eine Sitzung ist eine Beobachtung, keine Absicht. Sie gehört deshalb in den Istzustand: knotenlokal geführt, verdichtet repliziert, datiert. Der **Widerruf** dagegen ist eine Absicht und gehört in den Sollzustand. Der Entwurf löst das mit einem einzigen Feld je Person, `token_nicht_vor` (Zeitpunkt): jedes Token, das vor diesem Zeitpunkt ausgestellt wurde, ist ungültig. Ein Klick auf "Zugang sofort sperren" ist damit **ein** Schreibvorgang in den Sollzustand, der jede Partition, jeden Führungswechsel und jeden Neustart überlebt.

**Rechnung zur Begründung dieser Aufteilung.** Annahme 500 Personen, Annahme 3 gleichzeitige Sitzungen je Person = 1.500 Sitzungen à ≤ 1 KB = 1,5 MB Istzustand, was innerhalb des Modells von K-12 liegt. Läge derselbe Bestand im Sollzustand, käme zu den 30 MB Objektbestand aus K-12 ein Bereich mit hoher Änderungsrate hinzu: bei Erneuerung alle 5 min und 8 aktiven Stunden je Tag sind das 1.500 · (480 / 5) = 144.000 Schreibvorgänge je Tag = 1,67/s dauerhaft im Konsens. Das ist die Begründung, nicht der Geschmack.

### Tokenarten, Lebensdauern und Bindung

| Token | Zweck | Lebensdauer (Zielwert) | Bindung | Widerruf |
|---|---|---|---|---|
| Zugriffstoken | Aufrufe gegen API und Dienste; lokal prüfbar am Eingang | 10 min | An den Schlüssel des aufrufenden Clients (Besitznachweis je Aufruf) oder an das Clientzertifikat; auf verwalteten Geräten zusätzlich an die Gerätekennung | Über `token_nicht_vor` und über die Widerrufsmenge; ohne beides durch Ablauf |
| Erneuerungstoken | Erzeugt Zugriffstoken ohne erneute Authentisierung | Gleitend 8 h Inaktivität, absolut 7 d; bei Rollen mit Plattform- oder Mandantengeltung gleitend 30 min, absolut 12 h | An Sitzungsfamilie, Gerät und Quellnetzzone | Rotation bei jeder Nutzung; Wiederverwendung eines verbrauchten Tokens widerruft die gesamte Familie |
| Konsolensitzung (Cookie) | Bedienung der Atrium Console | Wie Erneuerungstoken | `__Host-`-Präfix, `Secure`, `HttpOnly`, `SameSite=Strict`; Kennungswechsel bei jeder Rechteerhöhung | wie oben |
| Erstregistrierungscode | Einmalige Registrierung des ersten Authentikators | 72 h, einmal verwendbar | An Personenkennung, nicht übertragbar | Verbrauch oder Ablauf; 5 Fehlversuche vernichten ihn |
| Dienstkontotoken | Automatisierung ohne Person | Pflichtablauf, Zielwert 24 h | An Dienstkontozertifikat und erlaubte Quelladressen | Zertifikatssperrung, `token_nicht_vor` je Dienstkonto |

### Expositionsfenster bei Tokendiebstahl

Wird ein Token zum Zeitpunkt t_d entwendet, so gilt für das Zeitfenster E, in dem es dem Angreifer nützt:

```
E = min( T_a - (t_d - t_aus) ,  T_erkennung + T_verteilung )

  T_a          Lebensdauer des Zugriffstokens
  t_aus        Ausstellungszeitpunkt
  T_erkennung  Zeit bis zur Erkennung des Diebstahls
  T_verteilung Zeit bis der Widerruf an allen pruefenden Stellen wirkt
```

**Fall A, unbemerkter Diebstahl eines ungebundenen Zugriffstokens.** Es gibt keine Erkennung, also E = Restlaufzeit. Bei gleichverteiltem Diebstahlzeitpunkt ist der Erwartungswert T_a/2, die Obergrenze T_a. Mit T_a = 600 s: **E_erwartet = 300 s, E_max = 600 s.**

**Fall B, Diebstahl eines Erneuerungstokens.** Der rechtmäßige Client erneuert bei 50 % der Lebensdauer, also spätestens nach T_a/2 = 300 s. Sobald entweder er oder der Angreifer das rotierte Token zum zweiten Mal vorlegt, erkennt die Wiederverwendungsprüfung den Diebstahl. T_erkennung ≤ 300 s. Die Verteilung des Widerrufs an alle prüfenden Stellen läuft über denselben Kanal wie die Konfiguration des Eingangs; **Zielwert T_verteilung ≤ 5 s** (vergleichbar mit K-17, p95 ≤ 10 s für die Wirksamkeit einer Veröffentlichung). Damit **E ≤ 305 s**.

**Fall C, gebundenes Zugriffstoken.** Das Token allein ist wertlos, weil jeder Aufruf einen Besitznachweis über den zugehörigen Schlüssel verlangt. Der Diebstahl des Tokens aus einem Protokoll, einem Zwischenspeicher oder einer fehlgeleiteten Anfrage führt zu E = 0. Die Bindung hilft **nicht** gegen Schadsoftware auf dem Gerät der Person, die den Schlüssel mitbenutzt; dagegen wirkt nur der Geräteschutz aus [Kapitel 13](13-geraeteverwaltung.md).

**Ableitung der Lebensdauer T_a.** Nach unten begrenzt T_a die Betriebsfestigkeit: eine Erneuerung muss eine Schreibpause bei Führungswechsel (K-05, ≤ 5 s) und den Neustart eines Verwaltungsknotens überstehen. Bei Erneuerung auf halber Strecke bleibt ein Puffer von T_a/2; mit T_a = 600 s sind das 300 s und damit der 60-fache Wert der Schreibpause. Nach oben begrenzt T_a das Expositionsfenster aus Fall A. Der Zielwert **T_a = 600 s** ist der Schnittpunkt dieser beiden Grenzen; T_a = 60 s hielte das Fenster kleiner, erzeugte aber 1.500/30 = 50 Erneuerungen/s und machte jede Kontrollebenenstörung von mehr als 30 s für alle Sitzungen gleichzeitig sichtbar.

**Rechnung Widerrufsmenge.** Die Widerrufsmenge muss einen Eintrag nur so lange führen, wie ein vor dem Widerruf ausgestelltes Zugriffstoken noch gültig sein könnte, also T_a = 600 s. Annahme 50 Einzelwiderrufe je Tag: mittlere Menge = 50 · 600 / 86.400 = 0,35 Einträge. Die Menge ist damit klein genug, um sie vollständig an jede prüfende Stelle zu verteilen, statt eine Rückfrage je Aufruf zu verlangen. Das ist die eigentliche Rechtfertigung der kurzen Lebensdauer: sie macht lokale Prüfbarkeit und schnellen Widerruf gleichzeitig möglich, die sich sonst ausschließen.

### Erneute Authentisierung vor kritischen Handlungen

Vor jeder nicht rücknehmbaren Handlung (INV-11), vor jeder Änderung an Authentisierungsmitteln und vor jeder Freigabe eines Wiederherstellungsvorgangs verlangt die Konsole eine Authentisierung, die nicht älter als 5 Minuten ist. Das Zeitfenster ist so gewählt, dass eine mehrschrittige Aufgabe nicht unterbrochen wird, ein unbeaufsichtigter Arbeitsplatz aber nicht für eine ganze Sitzungsdauer offensteht. Die erneute Authentisierung ist ein Passkey-Vorgang, kein Kennwortdialog.

**Anforderungen**

- **R-10-25** — Ein Widerruf über `token_nicht_vor` wirkt nach einem Neustart aller Knoten und nach einem Führungswechsel unverändert; ein danach vorgelegtes älteres Token wird abgelehnt. Prüfbar: Widerruf, Neustart, Vorlage eines vorher ausgestellten Tokens.
- **R-10-26** — Die Vorlage eines bereits rotierten Erneuerungstokens widerruft die gesamte Sitzungsfamilie und erzeugt 1 Auditereignis mit Angabe beider Quelladressen. Prüfbar: Wiederverwendungstest.
- **R-10-27** — Ein Zugriffstoken ohne gültigen Besitznachweis des gebundenen Schlüssels wird an jeder prüfenden Stelle abgelehnt. Prüfbar: Wiedereinspielung eines mitgeschnittenen Tokens von einer anderen Quelle.
- **R-10-28** — Die Verteilung eines Einzelwiderrufs an alle prüfenden Stellen ist p95 ≤ 5 s abgeschlossen. Prüfbar: Zeitmessung über 1.000 Widerrufe bei laufender Last.
- **R-10-29** — Eine nicht rücknehmbare Handlung ohne Authentisierung innerhalb der letzten 5 Minuten wird abgelehnt; die Ablehnung nennt die erforderliche Handlung und keinen Konsolenbefehl. Prüfbar: Ausführungsversuch mit künstlich gealterter Sitzung (INV-11, INV-17).

## 10.7 Lebenszyklus: Eintritt, Wechsel, Austritt

### Eintritt

```
Schritt                                            Ausloeser        Frist (Zielwert)
-------------------------------------------------  ---------------  ----------------
1  Person anlegen (2 Entscheidungen nach K-03)     Bediener         --
2  Anmeldename ableiten, kollisionsaufloesend      automatisch      < 1 s
3  Gruppen aus Richtlinie vorbelegen (INV-15)      automatisch      < 1 s
4  Zuweisungen aus Gruppen berechnen               automatisch      < 1 s
5  Versorgung je Zielsystem anstossen              automatisch      p50 5 s, p95 60 s,
                                                                    hart 15 min (K-15)
6  Erstregistrierungscode erzeugen und zustellen   automatisch      < 5 s
7  Person registriert ersten Authentikator         Person           innerhalb 72 h
8  Person registriert zweiten Authentikator        Person           innerhalb 14 d,
                                                                    Pflicht bei Rollen
                                                                    mit Administratorgeltung
9  Zustand "aktiv"                                 automatisch      nach Schritt 7
```

Zwischen Schritt 5 und Schritt 7 existiert ein Konto, das versorgt, aber noch von niemandem in Besitz genommen ist. Das ist die gefährlichste Phase des gesamten Lebenszyklus, weil das Konto bereits Rechte in Fremdsystemen trägt, aber noch kein Authentisierungsmittel gebunden ist. Der Entwurf begrenzt sie mit vier Maßnahmen: der Code ist einmalig und 72 h gültig, er ist an die Personenkennung gebunden und nicht übertragbar, 5 Fehlversuche vernichten ihn (dasselbe Muster wie INV-27 für Kopplungscodes), und **vor Abschluss von Schritt 7 ist keine Anmeldung möglich** — das versorgte Fremdkonto ist angelegt, aber nicht anmeldefähig, soweit das Zielsystem diesen Zustand kennt. Wo ein Zielsystem das nicht kann, weist die Produktgrenzdeklaration des Katalogeintrags das aus, und die Wirkungsvorschau nennt es vor der Versorgung. Auf einem verwalteten Gerät entfällt der Code vollständig: die Erstregistrierung läuft dann gegen das Gerätezertifikat, und das Besitzmerkmal ist das Gerät selbst.

### Wechsel

Ein Wechsel ist eine Änderung der Gruppenmitgliedschaft oder der Zuweisungen, kein eigener Objekttyp. Die Reihenfolge ist festgelegt und nicht umkehrbar:

1. **Entzug zuerst.** Alle Zuweisungen, die nach dem Wechsel entfallen, werden zuerst entzogen. Wer zuerst hinzufügt und danach entzieht, erzeugt ein Zeitfenster kombinierter Rechte, das bei Funktionstrennung genau der Zustand ist, den die Trennung verhindern soll.
2. **Sitzungswiderruf.** `token_nicht_vor` wird gesetzt, damit bestehende Sitzungen die entzogenen Rechte nicht weitertragen.
3. **Hinzufügen.** Die neuen Zuweisungen werden gesetzt und versorgt.
4. **Datenübergabe als eigener Vorgang.** Postfachzugriff, Speicherbereiche und laufende Freigaben wechseln nicht automatisch mit.

Schritt 4 bleibt bewusst manuell. Eine Richtlinie kann wissen, welche Rolle eine Person hat, aber nicht, welche der laufenden Vorgänge dieser Person an wen übergehen sollen. Eine automatische Übergabe wäre eine Weitergabe fremder Daten ohne Entscheidung und ist deshalb als Vorgang mit Wirkungsvorschau und Freigabe ausgelegt.

### Austritt

```
Zeitpunkt   Schritt                                              Rueckholbar
----------  ---------------------------------------------------  -----------
T + 0 s     token_nicht_vor setzen: alle Sitzungen ungueltig      ja
T + 0 s     Zustand "gesperrt": Anmeldung ueberall aus,           ja
            Daten bleiben unveraendert
T + 0 s     Geraetezertifikate sperren, Sperrliste verteilen      nein (nur Neuausstellung)
T + 0 s     Ausstehende Freigaben an die Vertretung umhaengen     ja
T + <=15min Zuweisungen auf "abgelaufen"; Fremdkonten nach        ja
            Manifest deaktiviert, nicht geloescht (INV-13)
T + 1 d     Postfach: Weiterleitung oder Delegation an eine       ja
            benannte Person, Entscheidung ist manuell
T + 90 d    Postfach archivieren                                  ja
T + 12 Mon  Mailadresse bleibt gesperrt (K-25), danach neu        --
            vergebbar
T + Aufbe-  Loeschung als ausdruecklicher, als nicht rueck-       nein
wahrungs-   nehmbar gekennzeichneter Vorgang mit vollstaendiger
frist       Auswirkungsliste (INV-11); Auditkette bleibt (INV-23)
```

Zwei Entscheidungen bleiben manuell, und beide aus demselben Grund: sie sind nicht ableitbar. Die erste ist die Benennung der Person, die das Postfach übernimmt — kein Attribut im Objektgraphen beantwortet die Frage, wer fachlich zuständig ist. Die zweite ist die endgültige Löschung. Automatisches Löschen nach Frist wäre bequem und ist ausgeschlossen, weil eine Frist keine Prüfung ersetzt, ob noch ein Aufbewahrungsgrund besteht; siehe [Kapitel 22](22-compliance.md). Der Entwurf zeigt beide als offene Aufgabe im Überblick an, bis sie entschieden sind, statt sie verstreichen zu lassen.

Der Zustand "ausgeschieden" löscht Zuweisungen nicht, er lässt sie ablaufen. Der Unterschied ist der Nachweis: eine abgelaufene Zuweisung belegt, dass die Person den Zugang hatte und wann er endete. Eine gelöschte belegt nichts.

**Anforderungen**

- **R-10-30** — Vor Abschluss der Erstregistrierung ist für die Person an keinem von Atrium versorgten Zielsystem eine Anmeldung möglich, soweit das Zielsystem einen nicht anmeldefähigen Zustand kennt; wo es das nicht kennt, weist die Wirkungsvorschau das vor der Versorgung aus. Prüfbar: Anmeldeversuch je Zielsystemklasse, Vorschauprüfung für die Ausnahmefälle.
- **R-10-31** — Bei einem Wechsel liegt der Zeitpunkt jedes Entzugs vor dem Zeitpunkt jeder Hinzufügung; der zeitliche Abstand ist ≥ 0 und wird im Vorgangsprotokoll geführt. Prüfbar: Reihenfolgeprüfung anhand der Zeitstempel im Vorgang.
- **R-10-32** — Beim Austritt sind Sitzungswiderruf, Anmeldesperre und Zertifikatssperrung innerhalb von 60 s nach Freigabe wirksam; die Versorgung der Zielsysteme folgt K-15. Prüfbar: Zeitmessung über alle drei Wirkungen plus Anmeldeversuch.
- **R-10-33** — Eine Löschung einer Person ohne ausdrückliche Einzelbestätigung mit vollständiger Auswirkungsliste existiert in der API nicht, auch nicht als Massenaktion. Prüfbar: Endpunktprüfung gegen die API-Fassade (INV-01, INV-11).

## 10.8 Gruppen, Rollen, Zuweisungen

Die **Zuweisung** ist das einzige Objekt, das Rechte erzeugt. Eine Gruppenmitgliedschaft erzeugt für sich keine Rechte; sie erzeugt sie nur, weil eine Zuweisung an der Gruppe hängt. Diese Trennung ist der Grund, weshalb ein vollständiger Rückbau möglich ist: entfernt man die Zuweisung, verfallen alle daraus abgeleiteten Artefakte, unabhängig davon, über welchen Gruppenpfad sie entstanden sind.

### Auswertungsreihenfolge

```
funktion wirksame_rechte(person P, zeitpunkt T) -> Menge von (Recht, Quellenliste)
  1  G := transitive_huelle(gruppen_von(P))        // azyklisch erzwungen, Tiefe <= 8
  2  Z := { z aus Zuweisungen : subjekt(z) in ({P} vereinigt G)
                                und mandant(z) = mandant(P)          // INV-19
                                und gueltig_von(z) <= T <= gueltig_bis(z) }
  3  E := leere Abbildung Recht -> Quellenliste
  4  fuer z in Z mit wirkung(z) = gewaehrung:
         fuer r in rechte(rolle(z)): E[r] := E[r] vereinigt {z}
  5  fuer z in Z mit wirkung(z) = ausschluss:
         fuer r in rechte(rolle(z)): E[r] := VERWEIGERT mit Quelle z
  6  gib E zurueck
```

Drei Eigenschaften dieses Verfahrens sind Entwurfsentscheidungen.

**Verweigerung gewinnt unbedingt.** Schritt 5 läuft nach Schritt 4 und ist nicht überschreibbar. Das macht das Ergebnis **reihenfolgeunabhängig**: es gibt keine Prioritätszahlen, keine "spezifischere Regel gewinnt"-Heuristik und keine Auswertung, deren Ausgang von der Sortierung der Eingabe abhängt. Der Preis ist bekannt und wird benannt: eine einzige weit gefasste Verweigerung kann eine große Gruppe unerwartet aussperren, und die Ursache ist ohne Werkzeug schwer zu finden. Die Gegenmaßnahme ist Schritt 6, nicht der Verzicht auf den Vorrang.

**Jedes Recht trägt seine Quellenliste.** E bildet nicht auf einen Wahrheitswert ab, sondern auf die Menge der Zuweisungen, die es erzeugt haben. Daraus folgt das Werkzeug "warum darf diese Person das" und, wichtiger, "warum darf sie es nicht": die Konsole nennt die verweigernde Zuweisung, die Gruppe, über die sie greift, und den Pfad in der Gruppenhierarchie. Ohne die Quellenliste ist ein Verweigerungsvorrang unbedienbar.

**Konflikte sind ein sichtbarer Zustand, kein Fehler.** Ein Recht, das gleichzeitig gewährt und verweigert wird, ist normal in jeder gewachsenen Organisation. Die Konsole zeigt den Konflikt an der Person, an der Gruppe und an beiden Zuweisungen, jeweils mit dem Ergebnis "verweigert" und der Gegenquelle. Ein Konflikt blockiert nichts, er wird ausgewiesen.

### Wirkungsvorschau auf wirksame Rechte

Die Wirkungsvorschau einer Änderung an Gruppen oder Zuweisungen zeigt nicht die Änderung am Graphen, sondern die Differenz der **wirksamen Rechte** je betroffener Person: welche Rechte hinzukommen, welche entfallen, wie viele Personen betroffen sind. Der Unterschied ist bedienungsentscheidend. "Gruppe A wird Mitglied von Gruppe B" ist für niemanden bewertbar; "47 Personen erhalten zusätzlich Schreibzugriff auf Dienst X, 3 Personen verlieren Y" ist es.

**Rechnung Aufwand.** Annahme: 500 Personen, Annahme: transitive Hülle ≤ 20 Gruppen je Person, Annahme: ≤ 10 Zuweisungen je Gruppe oder Person, Annahme: ≤ 50 Rechte je Rolle. Je Person: 20 · 10 · 50 = 10.000 Einträge in die Abbildung. Annahme: 100 ns je Eintrag einschließlich Mengenvereinigung ergibt 1,0 ms je Person. Für die Vorschau einer Änderung an einer Gruppe mit 500 betroffenen Personen: 500 · 1,0 ms = 0,5 s, innerhalb der Zusage von K-18 (p95 ≤ 2 s für die Wirkungsvorschau). Eine Änderung an einer Gruppe, die alle 500 Personen enthält, ist damit der rechnerisch teuerste Fall und liegt bei 25 % des Budgets. Das ist eine Rechnung aus Annahmen, keine Messung; die Annahme von 100 ns je Eintrag ist die unsicherste Größe.

**Tiefenbegrenzung.** Die Verschachtelung von Gruppen ist auf 8 Ebenen begrenzt (Zielwert). Die Begrenzung ist nicht rechnerisch nötig, sondern bedienungsseitig: ein Pfad über mehr als 8 Ebenen ist in einer Oberfläche nicht mehr in einem Blick darstellbar, und die Frage "warum hat diese Person dieses Recht" wird unbeantwortbar. Die Azyklizität wird beim Schreiben erzwungen, nicht beim Lesen geprüft.

**Anforderungen**

- **R-10-34** — Das Ergebnis von `wirksame_rechte` ist unabhängig von der Reihenfolge der Eingabemenge; zwei Berechnungen über dieselbe Menge in unterschiedlicher Sortierung liefern dieselbe Ausgabe. Prüfbar: Permutationstest über 1.000 zufällige Sortierungen.
- **R-10-35** — Jedes in der Konsole angezeigte Recht nennt auf Abruf die vollständige Quellenliste einschließlich des Gruppenpfads; ein Recht ohne Quellenliste bricht den Bau. Prüfbar: Darstellungstest (INV-15).
- **R-10-36** — Eine Gruppenmitgliedschaft, die einen Zyklus erzeugen würde, wird beim Schreiben abgelehnt; die Ablehnung nennt den Pfad. Prüfbar: Zyklusinjektion über 8 Ebenen.
- **R-10-37** — Die Wirkungsvorschau einer Gruppen- oder Zuweisungsänderung nennt die Zahl der betroffenen Personen und je Person die hinzukommenden und entfallenden Rechte; p95 ≤ 2 s bei 500 betroffenen Personen. Prüfbar: Vorschau auf eine Gruppe mit 500 Mitgliedern mit Zeitmessung (INV-08, K-18).

## 10.9 Föderation mit externen Identitätsanbietern

Vier Betriebsarten sind zu unterscheiden, und ihre Verwechslung ist die häufigste Quelle unklarer Erwartungen.

| Betriebsart | Wer beweist die Identität | Wer besitzt die Identitätsdaten | Typischer Anlass |
|---|---|---|---|
| **F1 Atrium als Anbieter** | Atrium | Atrium | Normalfall; Dienste im Katalog melden Personen gegen Atrium an |
| **F2 Fremder Anbieter als Anmeldeweg** | Entra ID, Google Workspace oder ein anderer OIDC-Anbieter | Atrium | Der Kunde hat bereits einen Anmeldeanbieter und will ihn behalten; Atrium führt weiterhin Rechte und Zuweisungen |
| **F3 Fremde Quelle als Verzeichnisquelle** | Atrium oder fremd | teilweise fremd | Ein Personalsystem oder ein Verzeichnis legt Personen an; Atrium übernimmt Stammdaten über SCIM |
| **F4 Atrium als Versorgungsquelle** | Atrium | Atrium | Atrium legt Konten in Entra ID, Google Workspace oder einer Fachanwendung an; der Regelfall für Zuweisungen |

In F2 wird ausschließlich die **Authentisierung** delegiert, nie die Autorisierung. Die fremde Identität wird zu einem Anmeldemittel am bestehenden **Person**-Objekt, nicht zu einer zweiten Person. Die Verknüpfung erfolgt über einen unveränderlichen Kennzeichner des fremden Anbieters, nie über die Mailadresse; eine Mailadresse wechselt bei Heirat, Umfirmierung und Domänenwechsel, und eine Verknüpfung über sie ist eine Übernahmemöglichkeit, sobald der fremde Anbieter eine Adresse neu vergibt.

In F3 entstehen zwei Schreiber auf dasselbe Objekt. Der Entwurf löst das nicht mit einer Sonderregel, sondern mit dem bestehenden Mechanismus: das Konnektormanifest erklärt je Feld, wer es besitzt (INV-13). Felder in fremdem Besitz überschreibt der Reconciler nicht; Felder in Atriums Besitz setzt er. Für Felder, die nur bei Erstanlage gesetzt werden, gilt weder das eine noch das andere. Damit ist F3 kein neuer Fall, sondern eine Konnektorbindung mit einer bestimmten Eigentumsabbildung.

### Attributabbildung

| Atrium | OIDC-Anspruch | SCIM-Attribut | Bemerkung zur Abbildung |
|---|---|---|---|
| Kennung (ULID) | `sub` | `id` | Stabil, unveränderlich, nie die Mailadresse |
| Anmeldename | `preferred_username` | `userName` | Nach Erzeugung unveränderlich; Zielsysteme mit eigenen Namensregeln erzwingen eine Ableitung je Bindung |
| Anzeigename | `name` | `displayName` | NFC-normalisiert; Zielsysteme mit Längenbegrenzung kürzen sichtbar |
| Mandant | eigener Anspruch | eigene Erweiterung | Ohne Normentsprechung; siehe Verlustliste |
| Gruppenmitgliedschaft | eigener Anspruch | `groups` | Verschachtelung geht verloren; siehe Verlustliste |
| Rolle einer Zuweisung | eigener Anspruch | — | SCIM kennt kein Rollenmodell; Abbildung auf Gruppen |
| Gültigkeit von/bis | — | `active` (nur wahr/falsch) | Der Zeitraum geht verloren; siehe Verlustliste |
| Primäre Mailadresse | `email` | `emails[primary]` | Nie als Verknüpfungsschlüssel, weil eine Adresse bei Heirat, Umfirmierung und Domänenwechsel wechselt; verknüpft wird über den unveränderlichen Kennzeichner des fremden Anbieters (R-10-38) |
| Authentikatorklasse | — | — | Nicht abbildbar; siehe Verlustliste |

### Was an der Grenze verloren geht

1. **Der Verweigerungsvorrang.** Kein Zielsystem kennt eine Verweigerung mit Vorrang. Vor der Versorgung muss jede Verweigerung zur **Abwesenheit einer Gewährung** aufgelöst werden. Damit geht der Grund verloren: im Zielsystem ist nicht mehr unterscheidbar, ob ein Recht nie gewährt oder ausdrücklich entzogen wurde. Wer im Zielsystem von Hand gewährt, hebt eine Verweigerung auf, ohne es zu merken. Der Reconciler korrigiert das beim nächsten Lauf und erzeugt ein Auditereignis, aber zwischen beiden Zeitpunkten besteht das Recht.
2. **Der Gültigkeitszeitraum.** SCIM kennt nur aktiv oder inaktiv. Ein Zuweisungsende wird zu einem Entzug zum Zeitpunkt X; im Zielsystem steht vorher nirgends, dass er bevorsteht. Ein Fremdsystemadministrator sieht das Ende nicht kommen.
3. **Die Gruppenverschachtelung.** Die transitive Hülle wird vor der Versorgung flachgeklopft. Eine Änderung an einer tief liegenden Gruppe erzeugt im Zielsystem eine Massenänderung von Einzelmitgliedschaften ohne erkennbaren gemeinsamen Grund.
4. **Der Mandantenbezug.** Fremdsysteme führen ein Verzeichnis. Die Trennung nach INV-19 endet an der Bindung. Der Entwurf zieht die Grenze dort, wo sie technisch haltbar ist: **je Mandant und Bindung ein eigener Konnektorprozess mit eigener Ausgangs-Positivliste** (INV-21), und wo das Fremdsystem selbst nicht trennen kann, wird das in der Produktgrenzdeklaration ausgewiesen.
5. **Die Authentikatorklasse.** Ob sich eine Person mit einem bescheinigten, gerätegebundenen Authentikator angemeldet hat, ist in F2 nicht mehr feststellbar, wenn der fremde Anbieter diese Angabe nicht führt oder nicht signiert weitergibt. Eine Richtlinie, die eine Klasse verlangt, ist in F2 daher nicht durchsetzbar. Das ist die härteste der fünf Einschränkungen, weil sie die Zusage aus Abschnitt 10.2 an genau der Stelle aushebelt, an der sie am meisten wert ist.

### Abhängigkeitsrisiko

In F2 hängt jede Anmeldung an der Erreichbarkeit eines fremden Dienstes. Ist er gestört, meldet sich niemand an — einschließlich der Person, die die Störung beheben müsste. Der Entwurf setzt daher eine harte Regel: **mindestens eine Person mit Plattformrolle muss ein lokales, von der Föderation unabhängiges Anmeldemittel führen.** Die Konsole verweigert die Umstellung auf reine Föderation, wenn das die letzte solche Person beträfe, und weist die Zahl der verbleibenden lokal anmeldefähigen Plattformadministratoren dauerhaft im Überblick aus. Die zweite Folge betrifft die Verfügbarkeitsrechnung: die Verfügbarkeit der Anmeldung ist in F2 das Produkt aus der Verfügbarkeit der Kontrollebene und der des fremden Anbieters. **Rechnung:** Annahme Kontrollebene 0,999702 bei 3 Stimmknoten (K-04), Annahme fremder Anbieter 0,999 ergibt 0,999702 · 0,999 = 0,998702, also rund 11,4 h Ausfall je Jahr gegenüber 2,6 h ohne Föderation. Der Zielwert der Plattform gilt in F2 nicht mehr, und die Konsole muss das an der Föderationsbindung anzeigen, statt die Zahl aus K-04 weiter zu behaupten.

**Anforderungen**

- **R-10-38** — Die Verknüpfung einer fremden Identität mit einer Person erfolgt über einen unveränderlichen Kennzeichner des fremden Anbieters; eine Verknüpfung über eine Mailadresse ist in der API nicht möglich. Prüfbar: Endpunktprüfung plus Test mit wechselnder Mailadresse bei gleichem Kennzeichner.
- **R-10-39** — Eine Verweigerung wird vor jeder Versorgung in die Abwesenheit einer Gewährung aufgelöst; die Wirkungsvorschau nennt jede so aufgelöste Verweigerung einzeln. Prüfbar: Vorschauprüfung bei gemischter Gewährung und Verweigerung.
- **R-10-40** — Die Umstellung auf ausschließlich föderierte Anmeldung wird abgelehnt, wenn danach 0 Personen mit Plattformrolle ein lokales Anmeldemittel hätten. Prüfbar: Umstellungsversuch in dieser Konstellation.
- **R-10-41** — Bei aktiver Föderation zeigt die Konsole an der Bindung die zusammengesetzte Verfügbarkeit und die je Mandant und Bindung getrennten Konnektorprozesse; ein gemeinsamer Prozess über zwei Mandanten hinweg existiert nicht. Prüfbar: Prozessprüfung je Bindung (INV-21) und Darstellungstest.

## 10.10 Kunden- und Gastidentitäten

Ein Gast ist keine eigene Entität, sondern eine **Person** mit drei Abweichungen von der Vorbelegung: der Gültigkeitszeitraum ist ein Pflichtfeld mit Ende, die Mitgliedschaft in abgeleiteten Gruppen mit organisationsweiter Mitgliedschaftsregel ist ausgeschlossen, und die Kennzeichnung als Gast ist an jeder Darstellung der Person sichtbar. Die dritte Abweichung ist die wichtigste: eine Person, die in einer Liste nicht als extern erkennbar ist, wird in Massenzuweisungen mitgenommen.

| Merkmal | Vorbelegung (Zielwert) | Obergrenze ohne erneute Freigabe |
|---|---|---|
| Gültigkeitsdauer | 30 d | 90 d |
| Zuweisungen | ausschließlich die bei der Einladung genannten | keine Vererbung aus organisationsweiten Gruppen |
| Authentikator | Passkey, Klasse frei | — |
| Verlängerung | durch den einladenden Bürgen, nicht durch den Gast | Verlängerung ist ein eigener Vorgang mit Wirkungsvorschau |

**Einladung.** Der Bürge ist eine Person mit dem Recht, Gäste einzuladen; er bleibt für die Dauer der Gastidentität in der Verantwortung und ist an der Gastperson namentlich vermerkt. Die Einladung kostet zwei Entscheidungen (Zieladresse, Dienst oder Gruppe), die Dauer folgt aus der Richtlinie (INV-15), damit INV-14 eingehalten ist. Der Einladungslink ist einmalig, 72 h gültig, an die Zieladresse gebunden und nach 5 Fehlversuchen vernichtet; er endet in einer Passkey-Registrierung, nicht in einer Sitzung.

**Ablauf.** 7 Tage vor Ablauf erhält **der Bürge** eine Aufgabe, nicht der Gast. Ein Gast kann seine eigene Gültigkeit nicht verlängern; täte er es, wäre die Befristung wirkungslos. Mit Ablauf entfallen die Zuweisungen und die Anmeldefähigkeit; die Person bleibt für die Aufbewahrungsfrist bestehen, damit der Auditstrom auf ein existierendes Objekt verweist, und wird danach nach demselben Verfahren wie jede andere Person gelöscht (INV-11).

**Kundenidentitäten** unterscheiden sich vom Gast durch die Zahl: sie entstehen nicht durch Einladung, sondern durch Selbstbedienung, wo eine Richtlinie des Mandanten das für einen bestimmten Dienst erlaubt. Der Entwurf zieht dafür drei Grenzen. Erstens gehören Kundenidentitäten in eine eigene Gruppe, die nie Ziel einer organisationsweiten Zuweisung sein kann. Zweitens ist die Selbstbedienungsregistrierung an eine **Veröffentlichung** gebunden, und ihre Erreichbarkeit folgt damit demselben Objekt wie jede andere Erreichbarkeit (INV-10); es gibt keinen offenen Registrierungsendpunkt neben der Veröffentlichung. Drittens unterliegt die Registrierung einer Ratenbegrenzung je Quelladresse und einer Obergrenze je Zeitfenster, die in der Richtlinie steht; ohne sie ist der Endpunkt ein Weg, den Objektbestand und damit den Sollzustand beliebig wachsen zu lassen. **Rechnung:** bei 2 KB je Person (K-12) und einer nicht begrenzten Registrierung erzeugen 10.000 Selbstregistrierungen 20 MB Sollzustand und damit 40 % des Zielwerts von 50 MB aus einer einzigen unauthentisierten Quelle. Die Ratenbegrenzung ist deshalb keine Bequemlichkeit, sondern eine Voraussetzung der Kennzahl.

### Selbstbedienung: was eine Person über sich selbst darf

| Erlaubt ohne Administrator | Nicht erlaubt |
|---|---|
| Authentikator hinzufügen und entfernen, solange mindestens einer verbleibt | Den letzten Authentikator entfernen |
| Eigene Vertretung benennen und widerrufen | Eigene Gruppenmitgliedschaften ändern |
| Eigene Sitzungen einsehen und einzeln oder gesamt widerrufen | Eigene Zuweisungen setzen oder entziehen |
| Eigene Geräte einsehen und als verloren melden | Eigenen Anmeldenamen ändern (unveränderlich nach Erzeugung) |
| Eigenen Auditstrom einsehen, ausschließlich die eigenen Ereignisse | Eigene Gültigkeit verlängern |
| Anzeigename und Sprache ändern, soweit die Richtlinie es zulässt | Eigene Kennwortausnahme aktivieren |

Die Zeile "eigenen Auditstrom einsehen" ist eine bewusste Entscheidung mit einer Kehrseite: sie gibt einer Person die Möglichkeit zu erkennen, dass ihr Konto beobachtet wird. Der Entwurf hält das für richtig, weil dieselbe Ansicht der Person erlaubt, eine fremde Anmeldung zu bemerken, und das ist der häufigere Fall. Untersuchungsbezogene Ereignisse eines Mandantenadministrators sind in dieser Ansicht nicht enthalten; die Trennung liegt in [Kapitel 19](19-mandanten-rechte-audit.md).

**Anforderungen**

- **R-10-42** — Eine Gastperson hat ein Gültigkeitsende; ein Anlegen ohne Ende wird abgelehnt. Prüfbar: Anlegeversuch ohne Endefeld.
- **R-10-43** — Eine Gastperson ist in 0 abgeleiteten Gruppen mit organisationsweiter Mitgliedschaftsregel enthalten und in jeder Liste als extern gekennzeichnet. Prüfbar: Mitgliedschaftsprüfung nach Gastanlage plus Darstellungstest.
- **R-10-44** — Ein Gast kann seine eigene Gültigkeit nicht verlängern; die Aufgabe zur Verlängerung geht 7 Tage vor Ablauf an den Bürgen. Prüfbar: Verlängerungsversuch als Gast, Zustellprüfung beim Bürgen.
- **R-10-45** — Die Selbstbedienungsregistrierung ist ausschließlich über eine Veröffentlichung erreichbar und unterliegt einer Ratenbegrenzung je Quelladresse; ein Registrierungsendpunkt ohne zugehörige Veröffentlichung existiert nicht. Prüfbar: Erreichbarkeitstest ohne Veröffentlichung, Lasttest gegen die Grenze (INV-10).

## 10.11 Dienst- und Arbeitslastidentitäten

Zwei Arten nicht-menschlicher Identität sind zu trennen.

| | **Dienstkonto** | **Arbeitslastidentität** |
|---|---|---|
| Wofür | Automatisierung, die im Auftrag einer Organisation handelt und Zuweisungen trägt | Ein laufender Dienst, der sich gegenüber anderen Diensten und der Kontrollebene ausweist |
| Träger | Objekt **Dienstkonto** im Sollzustand | Objekt **Dienst**, kein eigener Identitätsdatensatz |
| Anmeldemittel | Zertifikat oder Token mit Pflichtablauf, erlaubte Quelladressen | Kurzlebiges Zertifikat aus der Zwischen-CA des Mandanten |
| Ausstellung | Vorgang mit Freigabe | Automatisch aus dem Sollzustand, ohne Bedienereingriff |
| Sichtbar in | Personen & Gruppen | Netz & Namen (Zertifikatsübersicht), am Dienst |

Die Arbeitslastidentität ersetzt statische Zugangsdaten zwischen Atrium-Komponenten vollständig. Der Kennzeichner folgt der Namenskonvention und wird als alternativer Name in das Zertifikat geschrieben:

```
spiffe://<basisdomaene>/mandant/<mandant-ulid>/dienst/<dienst-ulid>
```

Die Form folgt SPIFFE, die Kennungen folgen dem Identifikatorformat des Kanons (ULID, Verweise nie über Namen). Eine Umbenennung des Dienstes ist damit folgenlos, was bei einem namensbasierten Kennzeichner nicht der Fall wäre.

**Laufzeit der Arbeitslastzertifikate.** Jede Ausstellung ist ein Ereignis im replizierten Protokoll (Technologiefestlegung PKI-Ort). Damit begrenzt die Konsensschreibrate die Laufzeit nach unten:

```
Ausstellungsrate  r = 2N / T      (N Arbeitslasten, Laufzeit T, Erneuerung bei T/2)
Bedingung         r <= 1/s        (gesetzte Obergrenze fuer diesen Pfad)
Folge             T >= 2N Sekunden

N = 150 Dienste (K-12)   ->  T >= 300 s  = 5 min
N = 1.500 Dienste        ->  T >= 3.000 s = 50 min
```

**Zielwert T = 24 h mit Erneuerung ab 12 h.** Bei N = 150 ergibt das r = 300/86.400 = 0,0035/s und damit 300 Protokolleinträge je Tag, gegenüber der Grenze von 86.400. Die Reserve beträgt den Faktor 288. Zum Vergleich: die 500 veröffentlichten Namen mit 90 d Laufzeit nach K-13 erzeugen 5,6 Erneuerungen je Tag. Beide Ströme zusammen liegen unter 310 Ereignissen je Tag und sind für den Sollzustand unerheblich.

**Hinweis auf eine Kanonlücke.** K-13 nennt für Dienstzertifikate 90 Tage. Dieser Wert gilt dem namenstragenden Serverzertifikat einer Veröffentlichung. Die hier beschriebene Arbeitslastidentität ist eine andere Zertifikatsklasse mit anderem Zweck und anderer Laufzeit. Der Entwurf erfindet keinen Widerspruch zu K-13, sondern stellt fest, dass der Kanon diese Klasse bisher nicht führt; die Aufnahme ist als offener Punkt vermerkt und in [Kapitel 11](11-pki.md) zu entscheiden.

**mTLS-Pfad.** Innerhalb einer Installation ist jede Verbindung zwischen Komponenten gegenseitig authentisiertes TLS 1.3 (RFC 8446) mit Zertifikaten aus der internen PKI: atrium-core zu atrium-node auf 8402/tcp, Raft-Verkehr auf 8401/tcp, xDS auf 8404/tcp, Telemetrie auf 8408/tcp. Der Konnektorvertrag läuft über einen Unix-Socket ohne Port und ohne TLS; dort tragen Dateisystemrechte und ein eigener Systembenutzer je Konnektorprozess die Abgrenzung. Das ist keine Schwächung: ein lokaler Socket mit Rechteprüfung ist für einen Prozess auf demselben Knoten die engere Grenze als ein Zertifikat, das ein Angreifer mit Leserechten auf die Schlüsseldatei ohnehin mitnähme.

**Wo die Kette endet: Konnektorgeheimnisse.** Ein Fremdsystem akzeptiert keine Atrium-Zertifikate. Die Konnektorbindung braucht deshalb ein Geheimnis des Fremdsystems — einen Schlüssel, ein Zugangstoken, ein Kennwort. Das ist die harte Grenze des Zertifikatsmodells und lässt sich nicht wegentwerfen, nur eingrenzen:

- Das Geheimnis ist im Sollzustand nur als **Referenz** vorhanden und wird nie im Klartext ausgegeben (INV-20).
- Der Konnektorprozess erhält es als kurzlebige, auftragsgebundene Referenz, nicht als Umgebungsvariable und nicht als Datei mit Bestand über den Auftrag hinaus.
- Der Prozess läuft unter eigenem Systembenutzer mit gehärteter Unit und eigenem Netznamensraum; seine Ausgangs-Positivliste erlaubt genau das Fremdsystem seiner Bindung und nicht die API der Kontrollebene (INV-21).
- Das Geheimnis trägt eine Wechselfrist; das Erreichen der Frist ist eine sichtbare Aufgabe am Objekt, kein Bericht. **Zielwert 180 d**, wo das Fremdsystem einen programmatischen Wechsel erlaubt; wo nicht, bleibt die Frist bestehen und die Aufgabe wird manuell erledigt.
- Wo die Gegenstelle statt eines Geheimnisses einen Vertrauensanker akzeptiert — Anmeldung mit einem signierten Nachweis statt mit einem geteilten Wert —, entfällt das Langzeitgeheimnis. Ob eine konkrete Gegenstelle das kann, ist eine Fähigkeit aus `describe` und steht im Konnektormanifest; siehe [Kapitel 09](09-konnektoren.md). Der Entwurf legt sich nicht darauf fest, welche Fremdsysteme das beherrschen, weil das eine Produkteigenschaft Dritter ist und sich ändert.

**Anforderungen**

- **R-10-46** — Jedes Dienstkonto trägt einen Pflichtablauf; ein Anlegen ohne Ablauf wird abgelehnt, und ein abgelaufenes Dienstkonto wird nicht stillschweigend verlängert. Prüfbar: Anlegeversuch ohne Ablauf, Ablaufprüfung mit Aufrufversuch danach.
- **R-10-47** — Jeder Dienst weist sich gegenüber der Kontrollebene mit einem Zertifikat aus, dessen alternativer Name Mandanten- und Dienstkennung als ULID trägt; ein namensbasierter Kennzeichner kommt in 0 ausgestellten Arbeitslastzertifikaten vor. Prüfbar: Inhaltsprüfung aller ausgestellten Zertifikate.
- **R-10-48** — Die Ausstellungsrate für Arbeitslastzertifikate bleibt bei der eingestellten Laufzeit unter 1 Konsensschreibvorgang je Sekunde; eine Laufzeit, die diese Grenze verletzen würde, wird beim Setzen abgelehnt. Prüfbar: Rechenprüfung beim Setzen der Laufzeit plus Zählung über 24 h im Lasttest.
- **R-10-49** — Ein Konnektorprozess erreicht aus seinem Netznamensraum 0 Adressen außerhalb seiner Ausgangs-Positivliste und in 0 Fällen die API der Kontrollebene über das Netz. Prüfbar: Netznamensraumtest mit Zugriffsversuchen auf 8400/tcp und auf eine nicht gelistete Adresse (INV-21).

## Akzeptanzkriterien

| Kriterium | Anforderung | Prüfverfahren |
|---|---|---|
| Die API-Fassade weist für Konten im Protokollkopf 0 Schreiboperationen aus | R-10-01 | Fassadenbau (INV-01) |
| Eine Handänderung im Protokollkopf ist nach ≤ 60 min korrigiert und hat genau 1 Auditereignis erzeugt | R-10-02 | Abweichungstest mit Direktänderung |
| Auf der Minderheitsseite einer Partition gelingt die Anmeldung, die Authentikatorregistrierung wird mit benanntem Zustand abgelehnt | R-10-03 | Partitionstest |
| Ein Sollzustandsexport enthält 0 Treffer gegen Muster für Signaturzähler, letzte Anmeldung und Sitzungsdatensatz | R-10-04 | Inhaltsprüfung |
| Der LDAP-Kopf beantwortet 0 Schreiboperationen; 389/tcp ist aus jeder Netzzone geschlossen | R-10-05 | Protokolltest und Portabtastung |
| Eine Administratorrolle ist einer Person mit 1 Authentikator nicht zuweisbar; die Ablehnung nennt die fehlende Klasse | R-10-06 | Zuweisungstest je Klassenkombination |
| Eine WebAuthn-Antwort mit fremdem Ursprung wird abgelehnt und erzeugt 1 Auditereignis | R-10-07 | Protokolltest mit manipuliertem Ursprung |
| Der Speicher des Protokollkopfs enthält ohne aktive Kennwortausnahme 0 offline angreifbare Werte | R-10-08 | Inhaltsprüfung gegen Ableitungsmuster |
| Die Benachrichtigung über eine Registrierung trägt einen Zeitstempel vor dem Wirksamkeitszeitstempel | R-10-09 | Reihenfolgeprüfung über beide Ströme |
| Ein Authentikator mit unbekannter Modellkennung erfüllt 0 gerätegebundene Richtlinien | R-10-10 | Registrierung und Zuweisungsversuch |
| Die API kennt für Wiederherstellungsvorgänge 0 tokenausgebende Operationen | R-10-11 | Endpunktprüfung |
| "Personen verwalten" und "Wiederherstellung freigeben" sind gleichzeitig nicht zuweisbar | R-10-12 | Zuweisungstest |
| Eine Freigabe vor Ablauf der Wartezeit scheitert auch mit Plattformrolle | R-10-13 | Ausführungsversuch bei 50 % der Wartezeit |
| Je Wiederherstellungsverfahren entstehen genau 3 Zustellungen je hinterlegtem Kanal | R-10-14 | Zustellprotokollprüfung |
| Die Vorschau eines Wiederherstellungsvorgangs nennt jeden Dienst mit unwiederbringlichem Datenverlust namentlich | R-10-15 | Vorschauprüfung gegen Produktgrenzdeklarationen |
| Export und Wiederherstellungspunkt enthalten 0 Treffer gegen das Muster des Wiederherstellungscodes | R-10-16 | Inhaltsprüfung; ein Treffer bricht den Bau |
| Die Laufzeitdifferenz zwischen richtigem und falschem Anteil liegt unter der Messauflösung | R-10-17 | Zeitmessreihe über je 10.000 Prüfungen |
| Eine Notzugangsauslösung ohne Quorum erzeugt 1 Auditereignis und ≥ 1 Zustellung je Alarmziel | R-10-18 | Auslösung auf der Minderheitsseite |
| Nach 72 h ohne Nachbereitung verlangt die nächste Auslösung Vier-Augen, das Banner bleibt sichtbar | R-10-19 | Fristüberschreitungstest |
| Ein Kennwort ohne Ausnahmeobjekt lässt sich nicht setzen | R-10-20 | Setzversuch |
| Die Ableitung hält m = 256 MiB, t = 4, p = 1 ein und überschreitet 1 GiB Spitzenspeicher nicht | R-10-21 | Parameterprüfung und Lasttest mit 8 gleichzeitigen Anmeldungen |
| Die Antwortzeiten für unbekannten Namen und falsches Kennwort unterscheiden sich um < 5 % | R-10-22 | Zeitmessreihe über je 10.000 Versuche |
| Ein erzeugtes Kennwort hat ≥ 6 Wörter und ist gegen die Leckmenge geprüft | R-10-23 | Erzeugungstest mit eingeschleustem Treffer |
| Nach 8 Fehlversuchen beträgt die Verzögerung ≥ 32 s; es entsteht 0 Dauersperre | R-10-24 | Fehlversuchsreihe mit Zeitmessung |
| Ein vor dem Widerruf ausgestelltes Token wird nach Neustart aller Knoten abgelehnt | R-10-25 | Widerruf, Neustart, Vorlage |
| Die Vorlage eines rotierten Erneuerungstokens widerruft die Familie und erzeugt 1 Auditereignis mit beiden Quelladressen | R-10-26 | Wiederverwendungstest |
| Ein von fremder Quelle vorgelegtes gebundenes Token wird an jeder prüfenden Stelle abgelehnt | R-10-27 | Wiedereinspielungstest |
| Ein Einzelwiderruf ist p95 ≤ 5 s an allen prüfenden Stellen wirksam | R-10-28 | Zeitmessung über 1.000 Widerrufe unter Last |
| Eine nicht rücknehmbare Handlung ohne frische Authentisierung wird abgelehnt, ohne einen Konsolenbefehl zu nennen | R-10-29 | Ausführungsversuch mit gealterter Sitzung |
| Vor der Erstregistrierung gelingt an keinem Zielsystem mit diesem Zustandsbegriff eine Anmeldung | R-10-30 | Anmeldeversuch je Zielsystemklasse |
| In jedem Wechselvorgang liegt jeder Entzugszeitstempel vor jedem Hinzufügungszeitstempel | R-10-31 | Reihenfolgeprüfung im Vorgangsprotokoll |
| Sitzungswiderruf, Anmeldesperre und Zertifikatssperrung sind ≤ 60 s nach Freigabe wirksam | R-10-32 | Zeitmessung plus Anmeldeversuch |
| Eine Personenlöschung ohne Einzelbestätigung mit Auswirkungsliste existiert in der API nicht | R-10-33 | Endpunktprüfung gegen die Fassade |
| 1.000 Permutationen derselben Eingabemenge liefern dasselbe Rechteergebnis | R-10-34 | Permutationstest |
| Jedes angezeigte Recht liefert auf Abruf die vollständige Quellenliste mit Gruppenpfad | R-10-35 | Darstellungstest; fehlende Quelle bricht den Bau |
| Eine zyklische Gruppenmitgliedschaft über 8 Ebenen wird beim Schreiben mit Pfadangabe abgelehnt | R-10-36 | Zyklusinjektion |
| Die Vorschau bei 500 betroffenen Personen nennt Zahl und Rechtedifferenz, p95 ≤ 2 s | R-10-37 | Vorschau auf eine Gruppe mit 500 Mitgliedern |
| Eine Verknüpfung über die Mailadresse ist in der API nicht möglich; ein Adresswechsel bei gleichem Kennzeichner bleibt folgenlos | R-10-38 | Endpunktprüfung und Wechseltest |
| Jede aufgelöste Verweigerung erscheint einzeln in der Wirkungsvorschau | R-10-39 | Vorschauprüfung bei gemischter Regelmenge |
| Die Umstellung auf reine Föderation scheitert, wenn danach 0 Plattformadministratoren lokal anmeldefähig wären | R-10-40 | Umstellungsversuch |
| Je Mandant und Bindung existiert genau 1 Konnektorprozess; die zusammengesetzte Verfügbarkeit wird angezeigt | R-10-41 | Prozessprüfung und Darstellungstest |
| Eine Gastperson ohne Gültigkeitsende lässt sich nicht anlegen | R-10-42 | Anlegeversuch |
| Eine Gastperson ist in 0 organisationsweiten abgeleiteten Gruppen enthalten und überall als extern gekennzeichnet | R-10-43 | Mitgliedschafts- und Darstellungsprüfung |
| Eine Selbstverlängerung durch den Gast scheitert; der Bürge erhält die Aufgabe 7 d vorher | R-10-44 | Verlängerungsversuch und Zustellprüfung |
| Ohne Veröffentlichung ist der Selbstbedienungsendpunkt aus 0 Netzzonen erreichbar | R-10-45 | Erreichbarkeits- und Lasttest |
| Ein Dienstkonto ohne Ablauf lässt sich nicht anlegen und wird nach Ablauf nicht stillschweigend verlängert | R-10-46 | Anlege- und Ablauftest |
| 0 ausgestellte Arbeitslastzertifikate tragen einen namensbasierten Kennzeichner | R-10-47 | Inhaltsprüfung aller Zertifikate |
| Eine Laufzeit, die 2N/T > 1/s ergäbe, wird beim Setzen abgelehnt | R-10-48 | Rechenprüfung und Zählung über 24 h |
| Ein Konnektorprozess erreicht 8400/tcp in 0 Versuchen | R-10-49 | Netznamensraumtest |

## Offene Punkte

1. **Pflege der Authentikator-Modellliste.** Die Unterscheidung zwischen plattformgebundenen, übertragbaren und anbietersynchronisierten Authentikatoren hängt an einer Liste bekannter Modellkennungen, die veraltet und in einer Installation ohne Internetzugang nicht auffrischbar ist. Offen ist, ob die Liste als signiertes Artefakt mit dem Systemabbild verteilt wird (dann gilt für sie die Ausrollkadenz aus K-23 und eine neue Hardwaregeneration ist bis zum nächsten Abbild "nicht bescheinigt"), als eigener Katalogeintrag mit eigener Aktualisierung, oder ob die Klassenprüfung ganz entfällt und R-10-06 und R-10-10 zurückgenommen werden. Alle drei Wege haben einen benennbaren Preis; entschieden ist keiner.

2. **Zertifikatsklasse für Arbeitslastidentitäten fehlt im Kanon.** K-13 kennt Dienstzertifikate mit 90 Tagen, Gerätezertifikate mit 365 Tagen und die CA-Laufzeiten, aber keine Klasse für kurzlebige Arbeitslastzertifikate. Abschnitt 10.11 rechnet mit 24 h Laufzeit und begründet die Untergrenze über die Konsensschreibrate. Ob K-13 um diese Klasse ergänzt wird, mit welcher Laufzeit und ob die Ausstellung tatsächlich in jedem Fall ein Konsensereignis sein muss oder eine verdichtete Form genügt, ist in [Kapitel 11](11-pki.md) zu entscheiden. Vorher ist R-10-48 an eine gesetzte, nicht an eine kanonische Grenze gebunden.

3. **Durchsetzbarkeit von Authentikatorrichtlinien unter Föderation.** In Betriebsart F2 ist nicht feststellbar, mit welcher Authentikatorklasse sich eine Person beim fremden Anbieter angemeldet hat. Damit ist jede Richtlinie, die eine Klasse verlangt, für föderierte Personen wirkungslos. Offen ist, ob Atrium in F2 für Rollen mit Plattform- oder Mandantengeltung einen zusätzlichen lokalen Passkey-Nachweis verlangt (verdoppelt den Anmeldeaufwand genau für die Personen, die ihn am häufigsten leisten müssen), ob die Richtlinie in F2 ausdrücklich als nicht durchsetzbar angezeigt wird, oder ob föderierte Personen von diesen Rollen ausgeschlossen bleiben.

4. **Wartezeit gegen Abwesenheit.** Die Wartezeit von 24 h in Klasse W4 schützt gegen einen Angreifer, der den Widerspruch der betroffenen Person nicht verhindern kann. Sie wirkt nicht gegen einen Angreifer, der einen Urlaubszeitraum kennt. Eine längere Wartezeit macht das Verfahren für den legitimen Fall unbrauchbar. Offen ist, ob Abwesenheiten als Objekt geführt werden (das wäre eine zusätzliche, personenbezogene Datenkategorie mit eigener Rechtsfolge, siehe [Kapitel 22](22-compliance.md)) und ob die Wartezeit während einer bekannten Abwesenheit ausgesetzt oder verlängert wird.

5. **Kennwortprüfwerte und Pfefferschlüssel bei Knotenverlust.** Der Pfefferschlüssel liegt im TPM eines Knotens und wird als Geheimnis je Mandant repliziert. Der Sollzustandsexport führt Geheimnisse nur als Referenz. Damit ist nach einem Wiederaufbau aus dem Export offen, woher der Pfefferschlüssel kommt, und ob die Kennwortprüfwerte danach ungültig sind. Der naheliegende Ausweg — nach dem Wiederaufbau alle Kennwörter neu setzen — ist tragbar, solange Kennwörter eine Ausnahme sind, und wäre bei größerem Bestand nicht tragbar. Das Verfahren ist nicht festgelegt.

6. **Belastbarkeit des Zeitparameters t = 4.** Der Wert folgt aus der Latenzzusage von 0,5 s bei m = 256 MiB und p = 1 und ist eine Annahme über die Rechenleistung der Zielhardware, keine Messung. Auf langsamer Hardware verletzt er die Latenzzusage, auf schneller verschenkt er Angreiferkosten. Offen ist, ob der Parameter bei der Erstinstallation kalibriert wird (dann unterscheiden sich Installationen, und ein Sollzustandsexport zwischen ihnen trägt unterschiedliche Parameter mit sich) oder ob er fest bleibt und die Latenz schwankt.

7. **Ordnung der Benachrichtigungskanäle.** Mehrere Verfahren dieses Kapitels verlangen "zwei voneinander unabhängige Kanäle" und "einen anderen Kanal als den Antrag". Der Entwurf legt nicht fest, welche Kanäle als unabhängig gelten und wer das feststellt. Eine Mailadresse im selben Postfachsystem und eine Nachricht an dasselbe Gerät sind erkennbar nicht unabhängig; eine private Zweitadresse und eine Mobilfunknummer sind es vermutlich, aber das ist eine Annahme über die Lebensführung der Person. Ohne eine Festlegung ist R-10-14 formal erfüllbar, ohne die beabsichtigte Wirkung zu erreichen.

8. **Gastanzahl und Sollzustandsgröße.** K-12 rechnet mit 500 Personen. Kunden- und Gastidentitäten sind Personen und zählen mit. Eine Installation mit 500 Mitarbeitern und 20.000 Kundenidentitäten sprengt den Zielwert von 50 MB um ein Vielfaches. Offen ist, ob Kundenidentitäten ein eigener, schlankerer Objekttyp außerhalb des Sollzustands werden (dann gelten Zuweisung, Audit und Rückbau für sie nicht in derselben Form), ob K-12 um eine zweite Größenklasse ergänzt wird, oder ob eine Obergrenze für Selbstbedienungsidentitäten je Mandant festgeschrieben wird.

9. **Abmeldung über mehrere Dienste hinweg.** Weder OIDC noch SAML lösen das verlässlich. `token_nicht_vor` beendet jede Sitzung gegen Atrium, beendet aber keine Sitzung, die ein Dienst nach erfolgreicher Anmeldung selbst führt. Die Wirkung eines Austritts ist damit an der Grenze zum Dienst von dessen eigener Sitzungsdauer abhängig. Offen ist, ob die Produktgrenzdeklaration je Katalogeintrag eine maximale Dienstsitzungsdauer verlangt, ob die Konsole die Restwirkung je Dienst ausweist, und wie mit Diensten umgegangen wird, die keine Obergrenze kennen. R-10-32 misst derzeit nur die Atrium-Seite.

10. **Prüfung des Wechsels ohne Rechtelücke.** Die Regel "Entzug vor Hinzufügung" verhindert ein Fenster kombinierter Rechte, erzeugt aber in der Gegenrichtung ein Fenster, in dem die Person weder die alten noch die neuen Rechte hat. Bei einer Versorgungslatenz von p95 60 s und einer harten Obergrenze von 15 min (K-15) kann dieses Fenster spürbar sein. Offen ist, ob für Rechte ohne Funktionstrennungsrelevanz die umgekehrte Reihenfolge zulässig ist, und wenn ja, wer festlegt, welche Rechte das sind.
