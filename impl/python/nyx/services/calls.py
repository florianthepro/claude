"""Anrufe: Signalisierung und Medienschluessel.

Ein Anruf hat zwei Teile, und nur der erste gehoert in dieses Protokoll:

  Signalisierung  Angebot, Antwort, Wegvorschlaege und Auflegen laufen als
                  gewoehnliche Umschlaege durch die Ratsche und die blinden
                  Ablagen. Ein Beobachter sieht dieselben Ablagen wie bei
                  einer Kurznachricht.

  Medien          Der Ton- und Bildstrom laeuft nicht ueber die Ablagen —
                  dafuer waeren sie zu langsam — sondern direkt zwischen den
                  Endgeraeten, verschluesselt unter einem Schluessel, der aus
                  der laufenden Ratsche abgeleitet wird.

Was dieses Modul liefert: die vollstaendige Signalisierung und die
Ableitung der Medienschluessel. Was es nicht liefert: einen Audiostapel.
Der Web-Client in impl/web setzt darauf WebRTC auf und nutzt genau diese
Signalisierung; die Python-Fassung ist die Referenz fuer den
Protokollablauf und fuer Tests.

Wichtig fuer die Anonymitaet: eine direkte Medienverbindung offenbart den
Gespraechspartnern gegenseitig ihre IP-Adresse. Wer das nicht will, muss
den Strom ueber das Mixnetz fuehren und die zusaetzliche Verzoegerung in
Kauf nehmen. Beide Betriebsarten sind hier vorgesehen; die Vorgabe ist die
sichere.
"""

from __future__ import annotations

import os
import time
from dataclasses import dataclass, field
from enum import Enum

from ..primitives import hkdf

MEDIA_LABEL = "nyx/v1/call-media"
CALL_TIMEOUT = 60


class CallState(Enum):
    IDLE = "bereit"
    RINGING = "klingelt"
    OUTGOING = "waehlt"
    ACTIVE = "verbunden"
    ENDED = "beendet"


@dataclass
class Call:
    call_id: str
    peer: str
    outgoing: bool
    state: CallState = CallState.IDLE
    started: float = field(default_factory=time.time)
    connected: float | None = None
    media_key: bytes | None = None
    relayed: bool = True          # Vorgabe: ueber das Mixnetz, keine direkte IP
    candidates: list[dict] = field(default_factory=list)

    @property
    def duration(self) -> float:
        return 0.0 if self.connected is None else time.time() - self.connected


class CallService:
    def __init__(self, client):
        self.client = client
        self.calls: dict[str, Call] = {}

    # -- Schluessel ------------------------------------------------------

    def _media_key(self, address: str, call_id: str) -> bytes:
        """Medienschluessel aus dem Sitzungszustand.

        Er wird nicht uebertragen, sondern auf beiden Seiten aus derselben
        Ratsche abgeleitet, und er gilt nur fuer diesen einen Anruf. Nach
        dem Auflegen wird er verworfen.
        """
        session = self.client.sessions[address]
        # Beide Seiten sehen dieselben zwei Geheimnisse in vertauschten
        # Rollen. Sortieren macht die Ableitung richtungsunabhaengig.
        first, second = sorted([session.send_secret, session.recv_secret])
        return hkdf(first + second, f"{MEDIA_LABEL}/{call_id}", 32)

    # -- Waehlen ---------------------------------------------------------

    def dial(self, address: str, relayed: bool = True, offer: dict | None = None) -> Call:
        call_id = os.urandom(8).hex()
        call = Call(call_id, address, outgoing=True, state=CallState.OUTGOING,
                    relayed=relayed)
        self.calls[call_id] = call
        self.client.send(address, {
            "t": "call", "k": "offer", "id": call_id,
            "relayed": relayed, "sdp": offer,
        })
        return call

    def accept(self, call_id: str, answer: dict | None = None) -> Call:
        call = self.calls[call_id]
        call.state = CallState.ACTIVE
        call.connected = time.time()
        call.media_key = self._media_key(call.peer, call_id)
        self.client.send(call.peer, {
            "t": "call", "k": "answer", "id": call_id, "sdp": answer,
        })
        return call

    def add_candidate(self, call_id: str, candidate: dict) -> None:
        call = self.calls[call_id]
        self.client.send(call.peer, {
            "t": "call", "k": "candidate", "id": call_id, "c": candidate,
        })

    def hang_up(self, call_id: str, reason: str = "beendet") -> Call | None:
        call = self.calls.get(call_id)
        if call is None:
            return None
        self.client.send(call.peer, {
            "t": "call", "k": "bye", "id": call_id, "reason": reason,
        })
        return self._end(call)

    @staticmethod
    def _end(call: Call) -> Call:
        call.state = CallState.ENDED
        call.media_key = None       # der Medienschluessel ueberlebt den Anruf nicht
        return call

    # -- Empfangen -------------------------------------------------------

    def handle(self, address: str, envelope: dict) -> Call | None:
        if envelope.get("t") != "call":
            return None
        kind, call_id = envelope.get("k"), envelope.get("id")

        if kind == "offer":
            call = Call(call_id, address, outgoing=False, state=CallState.RINGING,
                        relayed=envelope.get("relayed", True))
            self.calls[call_id] = call
            return call

        call = self.calls.get(call_id)
        if call is None:
            return None

        if kind == "answer":
            call.state = CallState.ACTIVE
            call.connected = time.time()
            call.media_key = self._media_key(address, call_id)
        elif kind == "candidate":
            call.candidates.append(envelope.get("c", {}))
        elif kind == "bye":
            self._end(call)
        return call

    def expire(self) -> int:
        """Nicht angenommene Anrufe verfallen und hinterlassen keinen Eintrag."""
        now = time.time()
        stale = [c for c in self.calls.values()
                 if c.state in (CallState.RINGING, CallState.OUTGOING)
                 and now - c.started > CALL_TIMEOUT]
        for call in stale:
            self._end(call)
        return len(stale)
