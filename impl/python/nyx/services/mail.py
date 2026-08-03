"""Post: lange Nachrichten mit langer Liegezeit.

Der Unterschied zur Kurznachricht ist nicht technischer, sondern
zeitlicher Natur. Post rechnet damit, dass der Empfaenger tagelang nicht
erscheint, und bekommt deshalb eine laengere Verfallszeit. Alles andere ist
identisch — dieselbe Ratsche, dieselben blinden Ablagen, dieselbe
Vernichtung nach der Abholung.

Was hier ausdruecklich fehlt, ist der Unterschied zum klassischen E-Mail-
System:

  * Es gibt keinen Server, der Post annimmt und zustellt. Es gibt Ablagen.
  * Es gibt keinen Kopfbereich mit Absender, Empfaenger, Betreff und
    Laufweg im Klartext. Der Betreff ist Teil des verschluesselten Inhalts.
  * Es gibt keine Weiterleitung an Unbeteiligte und kein Rundschreiben an
    Unbekannte. Post geht an jemanden, dessen Schluessel man hat.
  * Post wird nicht aufbewahrt. Nach der Abholung ist sie weg, und nach
    Ablauf der Frist ebenfalls — auch wenn sie nie abgeholt wurde.
"""

from __future__ import annotations

import base64
import os
import time
from dataclasses import dataclass, field

MAIL_TTL = 30 * 24 * 3600


@dataclass
class Letter:
    mail_id: str
    peer: str
    subject: str
    body: str
    outgoing: bool
    ts: int
    attachments: list[tuple[str, bytes]] = field(default_factory=list)
    in_reply_to: str | None = None

    def summary(self) -> str:
        who = "an" if self.outgoing else "von"
        stamp = time.strftime("%d.%m.%Y %H:%M", time.localtime(self.ts))
        anhang = f"  [{len(self.attachments)} Anhang]" if self.attachments else ""
        return f"{stamp}  {who} {self.peer[:12]}…  {self.subject}{anhang}"


class MailService:
    def __init__(self, client):
        self.client = client
        self.inbox: list[Letter] = []
        self.sent: list[Letter] = []

    def compose(self, address: str, subject: str, body: str,
                attachments: list[tuple[str, bytes]] | None = None,
                in_reply_to: str | None = None, ttl: int = MAIL_TTL) -> Letter:
        mail_id = os.urandom(8).hex()
        payload = {
            "t": "mail", "id": mail_id, "subject": subject, "body": body,
            "ts": int(time.time()), "reply": in_reply_to,
            "att": [{"name": n, "d": base64.b64encode(d).decode()}
                    for n, d in (attachments or [])],
        }
        self.client.send(address, payload, ttl=ttl)
        letter = Letter(mail_id, address, subject, body, True,
                        payload["ts"], list(attachments or []), in_reply_to)
        self.sent.append(letter)
        return letter

    def reply(self, letter: Letter, body: str) -> Letter:
        subject = letter.subject if letter.subject.startswith("Re: ") \
            else f"Re: {letter.subject}"
        return self.compose(letter.peer, subject, body, in_reply_to=letter.mail_id)

    def handle(self, address: str, envelope: dict) -> Letter | None:
        if envelope.get("t") != "mail":
            return None
        letter = Letter(
            envelope["id"], address, envelope.get("subject", ""),
            envelope.get("body", ""), False, envelope.get("ts", int(time.time())),
            [(a["name"], base64.b64decode(a["d"])) for a in envelope.get("att", [])],
            envelope.get("reply"),
        )
        self.inbox.append(letter)
        return letter

    def thread(self, mail_id: str) -> list[Letter]:
        """Alle Briefe, die an einem Ausgangsbrief haengen."""
        chain = [m for m in self.inbox + self.sent
                 if m.mail_id == mail_id or m.in_reply_to == mail_id]
        return sorted(chain, key=lambda m: m.ts)

    def delete(self, mail_id: str) -> bool:
        """Loescht lokal. Beim Speichernetz war die Post nach der Abholung
        ohnehin schon weg."""
        before = len(self.inbox) + len(self.sent)
        self.inbox = [m for m in self.inbox if m.mail_id != mail_id]
        self.sent = [m for m in self.sent if m.mail_id != mail_id]
        return before != len(self.inbox) + len(self.sent)
