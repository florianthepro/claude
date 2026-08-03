"""Kurznachrichten.

Der einfachste Dienst: ein Umschlag mit Text. Bemerkenswert ist nur, was
fehlt — es gibt keine Nachrichten-ID, die ueber Sitzungen hinweg gilt,
keinen Zeitstempel des Servers und keine Lesebestaetigung, die der
Empfaenger nicht selbst ausloest.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field


@dataclass
class Message:
    peer: str
    text: str
    outgoing: bool
    ts: float = field(default_factory=time.time)
    delivered: bool = False


class ChatService:
    def __init__(self, client):
        self.client = client
        self.history: dict[str, list[Message]] = {}
        # Die Historie liegt nur im Arbeitsspeicher. Wer sie behalten will,
        # muss das ausdruecklich einschalten — Vergessen ist die Vorgabe.
        self.persist = False

    def send(self, address: str, text: str) -> Message:
        self.client.send(address, {"t": "chat", "text": text,
                                   "ts": int(time.time())})
        msg = Message(address, text, outgoing=True)
        self.history.setdefault(address, []).append(msg)
        return msg

    def acknowledge(self, address: str, ts: int) -> None:
        """Lesebestaetigung — nur wenn der Nutzer sie will."""
        self.client.send(address, {"t": "receipt", "ts": ts})

    def handle(self, address: str, envelope: dict) -> Message | None:
        if envelope.get("t") == "chat":
            msg = Message(address, envelope.get("text", ""), outgoing=False,
                          ts=envelope.get("ts", time.time()))
            self.history.setdefault(address, []).append(msg)
            return msg
        if envelope.get("t") == "receipt":
            for m in self.history.get(address, []):
                if m.outgoing and int(m.ts) <= envelope.get("ts", 0):
                    m.delivered = True
        return None

    def conversation(self, address: str) -> list[Message]:
        return list(self.history.get(address, []))

    def forget(self, address: str | None = None) -> None:
        """Loescht die lokale Historie. Auf der Gegenseite aendert das nichts —
        das kann kein Messenger, und wir behaupten es auch nicht."""
        if address is None:
            self.history.clear()
        else:
            self.history.pop(address, None)
