"""Mixnetz mit Schichtverschluesselung (Whitepaper 6).

Der Sender waehlt einen Pfad aus drei Knoten und verpackt die Nachricht so,
dass jeder Knoten genau seinen Vorgaenger und seinen Nachfolger erfaehrt und
sonst nichts.

Ein Paket ist immer genau ``PACKET_SIZE`` Byte gross und besteht aus zwei
Teilen:

  Kopf      ``MAX_HOPS`` gleich grosse Faecher. Fach 0 gehoert dem naechsten
            Knoten; er oeffnet es, entnimmt die Wegangabe, schiebt die
            restlichen Faecher nach vorn und haengt ein Fach voll Zufall an.
            Der Kopf behaelt dadurch seine Groesse, und von aussen ist nicht
            erkennbar, das wievielte Fach gerade gelesen wurde.

  Rumpf     Bei jedem Hop mit einem eigenen Stromschluessel umgerechnet. Zwei
            Beobachtungen desselben Pakets vor und hinter einem Knoten haben
            keine Byte gemeinsam, obwohl die Laenge gleich bleibt.

Unterschied zum vollstaendigen Sphinx-Format: dort leitet sich das
Schluesselmaterial aller Hops aus einem einzigen, fortlaufend geblendeten
ephemeren Schluessel ab, was den Kopf kleiner macht. Wir fuehren
stattdessen einen ephemeren Schluessel je Hop — dieselben Eigenschaften bei
mehr Kopfbytes, dafuer erheblich weniger Code. Siehe docs/protokoll.md.
"""

from __future__ import annotations

import os
import random
from dataclasses import dataclass

from .primitives import AuthError, hkdf, open_, seal, sha256, x25519_keypair, x25519_shared
from .primitives.aead import chacha20

PACKET_SIZE = 2048
MAX_HOPS = 3
ROUTE_LEN = 32
SLOT_SIZE = 32 + ROUTE_LEN + 16          # eph + Wegangabe + AEAD-Tag
HEADER_SIZE = SLOT_SIZE * MAX_HOPS
PAYLOAD_SIZE = PACKET_SIZE - HEADER_SIZE

HOP_LABEL = "nyx/v1/mix-hop"
STREAM_LABEL = "nyx/v1/mix-stream"
FINAL = b"\x00" * ROUTE_LEN


class PacketError(Exception):
    pass


@dataclass
class MixNodeInfo:
    name: str
    pub: bytes

    def route_id(self) -> bytes:
        return sha256(b"nyx/v1/mix-node", self.name.encode())[:ROUTE_LEN]


def _keys(shared: bytes) -> tuple[bytes, bytes]:
    """(Fachschluessel, Rumpfschluessel) eines Hops."""
    return hkdf(shared, HOP_LABEL), hkdf(shared, STREAM_LABEL)


def _slot_nonce(eph: bytes) -> bytes:
    return sha256(b"nyx/v1/mix-nonce", eph)[:12]


class MixNode:
    """Ein Weiterleitungsknoten."""

    def __init__(self, name: str):
        self.name = name
        self._priv, self.pub = x25519_keypair()
        self.queue: list[tuple[bytes, bytes]] = []
        self.seen: set[bytes] = set()
        self.stats = {"empfangen": 0, "weitergeleitet": 0, "verworfen": 0,
                      "zugestellt": 0}

    @property
    def info(self) -> MixNodeInfo:
        return MixNodeInfo(self.name, self.pub)

    def route_id(self) -> bytes:
        return self.info.route_id()

    def peel(self, packet: bytes) -> tuple[bytes, bytes]:
        """Entfernt eine Schicht. Rueckgabe: (Wegangabe, Paket gleicher Groesse)."""
        if len(packet) != PACKET_SIZE:
            raise PacketError("Paket hat nicht die Einheitsgroesse")

        header, payload = packet[:HEADER_SIZE], packet[HEADER_SIZE:]
        slot, rest = header[:SLOT_SIZE], header[SLOT_SIZE:]
        eph = slot[:32]

        marker = sha256(b"nyx/v1/mix-replay", slot)
        if marker in self.seen:
            self.stats["verworfen"] += 1
            raise PacketError("Wiedereinspielung")

        try:
            slot_key, stream_key = _keys(x25519_shared(self._priv, eph))
            route = open_(slot_key, _slot_nonce(eph), slot[32:])
        except (AuthError, ValueError) as exc:
            self.stats["verworfen"] += 1
            raise PacketError("Fach gehoert nicht zu diesem Knoten") from exc

        self.seen.add(marker)
        self.stats["empfangen"] += 1

        # Kopf nachruecken und mit Zufall auffuellen, Rumpf umrechnen.
        new_header = rest + os.urandom(SLOT_SIZE)
        new_payload = chacha20(stream_key, 1, bytes(12), payload)
        return route, new_header + new_payload

    def accept(self, packet: bytes) -> tuple[bytes, bytes] | None:
        try:
            route, forwarded = self.peel(packet)
        except PacketError:
            return None
        self.queue.append((route, forwarded))
        return route, forwarded

    def flush(self, rng: random.Random | None = None) -> list[tuple[bytes, bytes]]:
        """Gibt die gesammelten Pakete in zufaelliger Reihenfolge aus.

        Mischen statt Weiterleiten: die Ausgangsreihenfolge ist von der
        Eingangsreihenfolge unabhaengig.
        """
        out, self.queue = self.queue, []
        (rng or random).shuffle(out)
        return out

    def __repr__(self) -> str:
        return f"<MixNode {self.name}>"


def build_packet(path: list[MixNodeInfo], payload: bytes) -> bytes:
    """Baut ein Zwiebelpaket fuer den angegebenen Pfad."""
    if not path:
        raise PacketError("leerer Pfad")
    if len(path) > MAX_HOPS:
        raise PacketError(f"hoechstens {MAX_HOPS} Hops")
    if len(payload) > PAYLOAD_SIZE:
        raise PacketError(f"Nutzlast zu gross: {len(payload)} > {PAYLOAD_SIZE}")

    slots, stream_keys = [], []
    for i, hop in enumerate(path):
        eph_priv, eph_pub = x25519_keypair()
        slot_key, stream_key = _keys(x25519_shared(eph_priv, hop.pub))
        route = path[i + 1].route_id() if i + 1 < len(path) else FINAL
        slots.append(eph_pub + seal(slot_key, _slot_nonce(eph_pub), route))
        stream_keys.append(stream_key)

    header = b"".join(slots) + os.urandom(SLOT_SIZE * (MAX_HOPS - len(path)))

    # Rumpf: auf feste Groesse auffuellen, dann von innen nach aussen
    # verschluesseln, damit jeder Hop genau eine Lage entfernt.
    body = payload + os.urandom(PAYLOAD_SIZE - len(payload))
    for stream_key in reversed(stream_keys):
        body = chacha20(stream_key, 1, bytes(12), body)

    packet = header + body
    if len(packet) != PACKET_SIZE:
        raise PacketError("interner Fehler: Paketgroesse stimmt nicht")
    return packet


class Mixnet:
    """Ein kleines Netz aus Mischknoten fuer Demo und Tests."""

    def __init__(self, nodes: list[MixNode]):
        self.nodes = {n.route_id(): n for n in nodes}
        self.by_name = {n.name: n for n in nodes}
        self.delivered: list[bytes] = []

    def path(self, length: int = 3, rng: random.Random | None = None) -> list[MixNodeInfo]:
        chosen = (rng or random).sample(list(self.by_name.values()), length)
        return [n.info for n in chosen]

    def guarded_path(self, guard: MixNode, length: int = 3,
                     rng: random.Random | None = None) -> list[MixNodeInfo]:
        """Pfad mit festem Eingangswaechter (Whitepaper 6.2 und 10.1).

        Der erste Knoten wird nicht pro Nachricht neu gewaehlt. Aus einer
        Gewissheit ueber die Zeit wird dadurch ein einmaliger Muenzwurf.
        """
        rest = [n for n in self.by_name.values() if n is not guard]
        return [guard.info] + [n.info for n in (rng or random).sample(rest, length - 1)]

    def send(self, path: list[MixNodeInfo], payload: bytes) -> bytes | None:
        """Schickt ein Paket den Pfad entlang und gibt die Nutzlast zurueck."""
        packet = build_packet(path, payload)
        route = path[0].route_id()

        for _ in range(len(path)):
            node = self.nodes.get(route)
            if node is None:
                return None
            route, packet = node.peel(packet)
            if route == FINAL:
                node.stats["zugestellt"] += 1
                body = packet[HEADER_SIZE:]
                self.delivered.append(body)
                return body
            node.stats["weitergeleitet"] += 1
        return None

    def cover_traffic(self, count: int = 1, rng: random.Random | None = None) -> int:
        """Deckverkehr: Pakete ohne Inhalt, von echten nicht unterscheidbar."""
        for _ in range(count):
            self.send(self.path(rng=rng), b"")
        return count
