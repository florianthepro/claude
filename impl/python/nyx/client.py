"""Der Client: alles zusammengesetzt.

Ein Client haelt seine Identitaet, seine Sitzungen und die Verbindung zum
Verzeichnis und zum Speichernetz. Ueber ihm liegen die Dienste in
``nyx.services`` — Chat, Post, Dateien und Anrufe teilen sich denselben
Kanal und dieselbe Ratsche; sie unterscheiden sich nur im Umschlag.

Zum Erstkontakt: die Tags einer laufenden Unterhaltung leiten sich aus
einem gemeinsamen Sitzungsgeheimnis ab und sind fuer Dritte unverkettbar.
Fuer die allererste Nachricht gibt es dieses Geheimnis noch nicht. Sie geht
deshalb an ein Postfach, dessen Tags sich aus dem oeffentlichen Schluessel
des Empfaengers und einem Zeitfenster ergeben. Wer die Adresse kennt, kann
diese Tags berechnen — er sieht dadurch, ob jemand unabgeholte
Erstkontakte hat, und koennte das Postfach zumuellen. Dagegen steht der
Arbeitsnachweis je Ablage; verbergen laesst es sich nicht. Ab der zweiten
Nachricht ist die Unterhaltung unverkettbar.
"""

from __future__ import annotations

import json
import random
import time
from dataclasses import dataclass, field

from .directory import Chain, bind_record, prekey_record
from .drops import DropNetwork, drop_tag, tag_secret
from .identity import Identity, PublicIdentity
from .primitives import AuthError, hkdf, hmac_sha256
from .ratchet import MessageHeader, Ratchet
from .x3dh import InitialHeader, PrekeyBundle, PrekeyStore, initiate, respond

INBOX_LABEL = "nyx/v1/inbox"
INBOX_EPOCH = 3600
INBOX_SLOTS = 8
DIR_A2B = "nyx/v1/tags/initiator"
DIR_B2A = "nyx/v1/tags/responder"

CELL = 1024
MAX_CELLS = 4096
LENGTH_PREFIX = 4


def inbox_tag(idk_pub: bytes, epoch: int, slot: int) -> str:
    """Postfach-Tag fuer den Erstkontakt."""
    seed = hkdf(idk_pub, INBOX_LABEL)
    return hmac_sha256(seed, epoch.to_bytes(8, "big"), slot.to_bytes(4, "big")).hex()


def current_epoch(now: float | None = None) -> int:
    return int((now if now is not None else time.time()) // INBOX_EPOCH)


@dataclass
class Session:
    """Eine laufende Unterhaltung."""

    peer: PublicIdentity
    ratchet: Ratchet
    send_secret: bytes
    recv_secret: bytes
    send_counter: int = 0
    recv_counter: int = 0
    initial_header: dict | None = None
    sent_initial: bool = False
    established: float = field(default_factory=time.time)

    def destroy(self) -> None:
        self.ratchet.destroy()


class Client:
    """Ein Endgeraet."""

    def __init__(self, identity: Identity, chain: Chain, drops: DropNetwork,
                 rng: random.Random | None = None):
        self.identity = identity
        self.chain = chain
        self.drops = drops
        self.rng = rng or random.Random()
        self.prekeys = PrekeyStore(identity)
        self.sessions: dict[str, Session] = {}
        self.inbox_cursor: dict[int, int] = {}

    # -- Ankuendigen -----------------------------------------------------

    def announce(self) -> None:
        """Veroeffentlicht BIND und PREKEY im Verzeichnis."""
        self.chain.submit(bind_record(self.identity))
        body, _ = self.prekeys.publish()
        self.chain.submit(prekey_record(self.identity, body))
        self.chain.seal()

    # -- Sitzungsaufbau --------------------------------------------------

    def _bundle_for(self, address: str) -> tuple[PublicIdentity, PrekeyBundle]:
        peer = self.chain.resolve(address)
        if peer is None:
            raise ValueError(f"Adresse {address} steht nicht im Verzeichnis")
        body = self.chain.latest_bundle(address)
        if body is None:
            raise ValueError(f"kein Prekey-Buendel fuer {address}")
        opks = body.get("opks") or []
        opk = self.rng.choice(opks) if opks else None
        return peer, PrekeyBundle.from_body(address, peer.idk_pub, body, opk)

    def start_session(self, address: str) -> Session:
        if address in self.sessions:
            return self.sessions[address]

        peer, bundle = self._bundle_for(address)
        root, header = initiate(self.identity, peer, bundle)
        material = root.bytes()
        session = Session(
            peer=peer,
            ratchet=Ratchet.as_sender(root, bundle.spk_pub),
            send_secret=tag_secret(hkdf(material, DIR_A2B)),
            recv_secret=tag_secret(hkdf(material, DIR_B2A)),
            initial_header=header.to_dict(),
        )
        self.sessions[address] = session
        return session

    def _accept_session(self, header: InitialHeader) -> Session:
        address = _address_of(header.ik_pub)
        peer = self.chain.resolve(address) or PublicIdentity(
            address, header.ik_pub, header.idk_pub)

        root = respond(self.identity, self.prekeys, header)
        material = root.bytes()
        session = Session(
            peer=peer,
            ratchet=Ratchet.as_receiver(root, self.prekeys.spk_priv(),
                                        self.prekeys.spk_pub),
            send_secret=tag_secret(hkdf(material, DIR_B2A)),
            recv_secret=tag_secret(hkdf(material, DIR_A2B)),
        )
        self.sessions[address] = session
        return session

    # -- Senden ----------------------------------------------------------

    def send(self, address: str, envelope: dict, ttl: int | None = None) -> dict:
        """Verschluesselt einen Umschlag und legt ihn im Speichernetz ab."""
        session = self.sessions.get(address) or self.start_session(address)
        plaintext = json.dumps(envelope, separators=(",", ":")).encode()
        header, boxed = session.ratchet.encrypt(plaintext)

        packet = {"hdr": header.to_bytes().hex(), "ct": boxed.hex()}
        first = not session.sent_initial and session.initial_header
        if first:
            packet["init"] = session.initial_header
        blob = json.dumps(packet, separators=(",", ":")).encode()

        if first:
            epoch, slot = current_epoch(), self.rng.randrange(INBOX_SLOTS)
            secret, start = _inbox_secret(session.peer.idk_pub, epoch, slot), 0
            session.sent_initial = True
        else:
            secret, start = session.send_secret, session.send_counter

        cells = self._store_frames(secret, start, blob, ttl)
        if not first:
            session.send_counter += cells
        return {"zellen": cells, "erstkontakt": bool(first)}

    # -- Zellen ----------------------------------------------------------
    # Jede Ablage ist genau CELL Byte gross. Eine kurze Nachricht, ein
    # Brief, ein Dateiblock und das Signalisieren eines Anrufs sehen im
    # Speichernetz deshalb identisch aus; erkennbar ist nur, wie viele
    # Ablagen entstanden sind. Das ist der in Whitepaper 6.2 benannte
    # Kompromiss: die ungefaehre Groesse laesst sich nicht verbergen, die
    # Art des Dienstes schon.

    def _store_frames(self, secret: bytes, start: int, blob: bytes,
                      ttl: int | None) -> int:
        framed = len(blob).to_bytes(LENGTH_PREFIX, "big") + blob
        padding = (-len(framed)) % CELL
        framed += bytes(padding)
        cells = len(framed) // CELL
        if cells > MAX_CELLS:
            raise ValueError("Nachricht zu gross fuer eine Zustellung")

        for i in range(cells):
            self.drops.store(secret, start + i, framed[i * CELL:(i + 1) * CELL],
                             ttl=ttl, rng=self.rng)
        return cells

    def _fetch_frames(self, secret: bytes, start: int) -> tuple[bytes, int] | None:
        """Holt eine vollstaendige Nachricht ab Zaehlerstand ``start``."""
        first = self.drops.fetch(secret, start, rng=self.rng)
        if first is None:
            return None
        total = int.from_bytes(first[:LENGTH_PREFIX], "big")
        if total > MAX_CELLS * CELL:
            return None

        buf = bytearray(first)
        cells = 1
        while len(buf) - LENGTH_PREFIX < total:
            nxt = self.drops.fetch(secret, start + cells, rng=self.rng)
            if nxt is None:
                return None          # unvollstaendig: nichts liefern
            buf += nxt
            cells += 1
        return bytes(buf[LENGTH_PREFIX:LENGTH_PREFIX + total]), cells

    # -- Empfangen -------------------------------------------------------

    def poll(self) -> list[tuple[str, dict]]:
        """Holt alles Wartende: Erstkontakte und laufende Unterhaltungen."""
        out = self._poll_inbox()
        for address in list(self.sessions):
            out.extend(self._poll_session(address))
        return out

    def _poll_inbox(self) -> list[tuple[str, dict]]:
        out = []
        epoch = current_epoch()
        for e in (epoch, epoch - 1):
            for slot in range(INBOX_SLOTS):
                secret = _inbox_secret(self.identity.idk_pub, e, slot)
                got = self._fetch_frames(secret, 0)
                if got is None:
                    continue
                try:
                    out.append(self._open_first(got[0]))
                except (ValueError, AuthError, KeyError, json.JSONDecodeError):
                    continue
        return out

    def _open_first(self, blob: bytes) -> tuple[str, dict]:
        packet = json.loads(blob)
        header = InitialHeader.from_dict(packet["init"])
        session = self._accept_session(header)
        envelope = self._open(session, packet)
        return _address_of(header.ik_pub), envelope

    def _poll_session(self, address: str) -> list[tuple[str, dict]]:
        session = self.sessions[address]
        out = []
        while True:
            got = self._fetch_frames(session.recv_secret, session.recv_counter)
            if got is None:
                break
            blob, cells = got
            session.recv_counter += cells
            try:
                out.append((address, self._open(session, json.loads(blob))))
            except (ValueError, AuthError, json.JSONDecodeError):
                continue
        return out

    @staticmethod
    def _open(session: Session, packet: dict) -> dict:
        header = MessageHeader.from_bytes(bytes.fromhex(packet["hdr"]))
        plaintext = session.ratchet.decrypt(header, bytes.fromhex(packet["ct"]))
        return json.loads(plaintext)

    # -- Ende ------------------------------------------------------------

    def close(self, address: str) -> None:
        session = self.sessions.pop(address, None)
        if session:
            session.destroy()

    def shutdown(self) -> None:
        for address in list(self.sessions):
            self.close(address)

    def __repr__(self) -> str:
        return f"<Client {self.identity.address} sitzungen={len(self.sessions)}>"


def _address_of(ik_pub: bytes) -> str:
    from .identity import address_from_key
    return address_from_key(ik_pub)


def _inbox_secret(idk_pub: bytes, epoch: int, slot: int) -> bytes:
    """Ableitung des Ablagegeheimnisses fuer ein Postfach.

    Absichtlich aus oeffentlichem Material: jeder Sender muss es berechnen
    koennen, ohne den Empfaenger vorher gesprochen zu haben.
    """
    return tag_secret(hkdf(idk_pub + epoch.to_bytes(8, "big")
                           + slot.to_bytes(4, "big"), INBOX_LABEL))


