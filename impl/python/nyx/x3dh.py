"""Sitzungsaufbau (Whitepaper 5.1).

Der Sender laedt ein signiertes Prekey-Buendel aus dem Verzeichnis, prueft
die Signatur gegen den Identitaetsschluessel und berechnet vier
Diffie-Hellman-Werte. Alle Zwischenergebnisse und der ephemere private
Schluessel werden unmittelbar danach ueberschrieben.
"""

from __future__ import annotations

import os
import time
from dataclasses import dataclass

from .identity import Identity, PublicIdentity, verify_signature
from .primitives import Secret, hkdf, sha256, x25519_keypair, x25519_shared, zeroize

BUNDLE_LABEL = b"nyx/v1/prekey-bundle"
ROOT_LABEL = "nyx/v1/x3dh-root"

SPK_LIFETIME = 7 * 24 * 3600
DEFAULT_OPK_COUNT = 64


@dataclass
class PrekeyBundle:
    """Was im Verzeichnis steht — nur oeffentliche Werte."""

    address: str
    idk_pub: bytes
    spk_pub: bytes
    spk_sig: bytes
    valid_until: int
    opk_pub: bytes | None = None
    opk_id: int | None = None

    def signed_part(self) -> bytes:
        return BUNDLE_LABEL + self.spk_pub + str(self.valid_until).encode()

    def verify(self, ik_pub: bytes) -> bool:
        """Ein Buendel ohne gueltige Signatur wird nicht benutzt."""
        if self.valid_until < time.time():
            return False
        return verify_signature(ik_pub, self.signed_part(), self.spk_sig)

    def to_body(self, opks: list[tuple[int, bytes]] | None = None) -> dict:
        return {
            "spk_pub": self.spk_pub.hex(),
            "spk_sig": self.spk_sig.hex(),
            "valid_until": self.valid_until,
            "opks": [{"id": i, "pub": p.hex()} for i, p in (opks or [])],
        }

    @staticmethod
    def from_body(address: str, idk_pub: bytes, body: dict,
                  opk: dict | None = None) -> "PrekeyBundle":
        return PrekeyBundle(
            address=address,
            idk_pub=idk_pub,
            spk_pub=bytes.fromhex(body["spk_pub"]),
            spk_sig=bytes.fromhex(body["spk_sig"]),
            valid_until=body["valid_until"],
            opk_pub=bytes.fromhex(opk["pub"]) if opk else None,
            opk_id=opk["id"] if opk else None,
        )


class PrekeyStore:
    """Die private Seite der Prekeys.

    Ein Einmal-Prekey wird bei Gebrauch geloescht, nicht archiviert
    (Whitepaper 5.3, Punkt 4). ``take`` gibt ihn genau einmal heraus.
    """

    def __init__(self, identity: Identity):
        self.identity = identity
        self._spk_priv = bytearray()
        self.spk_pub = b""
        self.spk_valid_until = 0
        self._opks: dict[int, bytearray] = {}
        self._used: set[int] = set()
        self._next_id = 0

    def rotate_signed_prekey(self, lifetime: int = SPK_LIFETIME) -> None:
        zeroize(self._spk_priv)
        priv, pub = x25519_keypair()
        self._spk_priv = bytearray(priv)
        self.spk_pub = pub
        self.spk_valid_until = int(time.time()) + lifetime

    def generate_one_time(self, count: int = DEFAULT_OPK_COUNT) -> list[tuple[int, bytes]]:
        out = []
        for _ in range(count):
            priv, pub = x25519_keypair()
            self._opks[self._next_id] = bytearray(priv)
            out.append((self._next_id, pub))
            self._next_id += 1
        return out

    def take(self, opk_id: int | None) -> bytes | None:
        """Holt einen Einmal-Prekey und vernichtet ihn dabei."""
        if opk_id is None:
            return None
        if opk_id in self._used or opk_id not in self._opks:
            # Wiedereinspielung mit demselben Prekey: abgelehnt.
            return None
        buf = self._opks.pop(opk_id)
        raw = bytes(buf)
        zeroize(buf)
        self._used.add(opk_id)
        return raw

    @property
    def remaining(self) -> int:
        return len(self._opks)

    def spk_priv(self) -> bytes:
        return bytes(self._spk_priv)

    # -- Aufbewahrung ----------------------------------------------------
    # Der private Teil der Prekeys muss den Neustart eines Geraets
    # ueberleben. Sonst kann der Empfaenger den Handshake zu einem bereits
    # veroeffentlichten Buendel nicht mehr abschliessen und ist fuer
    # Erstkontakte unerreichbar, bis er neu ankuendigt.

    def export_state(self) -> str:
        import json
        return json.dumps({
            "v": 1,
            "spk_priv": bytes(self._spk_priv).hex(),
            "spk_pub": self.spk_pub.hex(),
            "spk_valid_until": self.spk_valid_until,
            "opks": {str(i): bytes(p).hex() for i, p in self._opks.items()},
            "used": sorted(self._used),
            "next_id": self._next_id,
        })

    def import_state(self, blob: str) -> "PrekeyStore":
        import json
        d = json.loads(blob)
        self._spk_priv = bytearray(bytes.fromhex(d["spk_priv"]))
        self.spk_pub = bytes.fromhex(d["spk_pub"])
        self.spk_valid_until = d["spk_valid_until"]
        self._opks = {int(i): bytearray(bytes.fromhex(p)) for i, p in d["opks"].items()}
        self._used = set(d.get("used", []))
        self._next_id = d["next_id"]
        return self

    def publish(self) -> tuple[dict, list[tuple[int, bytes]]]:
        """Erzeugt den Koerper eines PREKEY-Eintrags fuer das Verzeichnis."""
        if not self.spk_pub:
            self.rotate_signed_prekey()
        opks = self.generate_one_time()
        sig = self.identity.sign(
            BUNDLE_LABEL + self.spk_pub + str(self.spk_valid_until).encode())
        bundle = PrekeyBundle(self.identity.address, self.identity.idk_pub,
                              self.spk_pub, sig, self.spk_valid_until)
        return bundle.to_body(opks), opks


@dataclass
class InitialHeader:
    """Der Kopf der ersten Nachricht — er ersetzt jede Vorabsprache."""

    ik_pub: bytes
    idk_pub: bytes
    ek_pub: bytes
    spk_pub: bytes
    opk_id: int | None

    def to_dict(self) -> dict:
        return {
            "ik_pub": self.ik_pub.hex(),
            "idk_pub": self.idk_pub.hex(),
            "ek_pub": self.ek_pub.hex(),
            "spk_pub": self.spk_pub.hex(),
            "opk_id": self.opk_id,
        }

    @staticmethod
    def from_dict(d: dict) -> "InitialHeader":
        return InitialHeader(
            bytes.fromhex(d["ik_pub"]), bytes.fromhex(d["idk_pub"]),
            bytes.fromhex(d["ek_pub"]), bytes.fromhex(d["spk_pub"]), d["opk_id"])


def initiate(sender: Identity, peer: PublicIdentity,
             bundle: PrekeyBundle) -> tuple[Secret, InitialHeader]:
    """Senderseite: vier DH, ein Wurzelschluessel, danach alles loeschen."""
    if not bundle.verify(peer.ik_pub):
        raise ValueError("Prekey-Buendel traegt keine gueltige Signatur")

    ek_priv, ek_pub = x25519_keypair()
    ek_buf = bytearray(ek_priv)

    dh1 = bytearray(x25519_shared(sender.idk_priv, bundle.spk_pub))
    dh2 = bytearray(x25519_shared(ek_priv, peer.idk_pub))
    dh3 = bytearray(x25519_shared(ek_priv, bundle.spk_pub))
    dh4 = bytearray(x25519_shared(ek_priv, bundle.opk_pub)) if bundle.opk_pub else bytearray()

    root = Secret(hkdf(bytes(dh1) + bytes(dh2) + bytes(dh3) + bytes(dh4), ROOT_LABEL, 64))

    for buf in (dh1, dh2, dh3, dh4, ek_buf):
        zeroize(buf)

    header = InitialHeader(sender.ik_pub, sender.idk_pub, ek_pub,
                           bundle.spk_pub, bundle.opk_id)
    return root, header


def respond(receiver: Identity, store: PrekeyStore, header: InitialHeader) -> Secret:
    """Empfaengerseite: derselbe Wurzelschluessel, dann Einmal-Prekey weg."""
    if header.spk_pub != store.spk_pub:
        raise ValueError("Buendel bezieht sich auf einen abgeloesten Prekey")

    opk_priv = store.take(header.opk_id)
    if header.opk_id is not None and opk_priv is None:
        raise ValueError("Einmal-Prekey bereits verbraucht — Wiedereinspielung")

    spk = store.spk_priv()
    dh1 = bytearray(x25519_shared(spk, header.idk_pub))
    dh2 = bytearray(x25519_shared(receiver.idk_priv, header.ek_pub))
    dh3 = bytearray(x25519_shared(spk, header.ek_pub))
    dh4 = bytearray(x25519_shared(opk_priv, header.ek_pub)) if opk_priv else bytearray()

    root = Secret(hkdf(bytes(dh1) + bytes(dh2) + bytes(dh3) + bytes(dh4), ROOT_LABEL, 64))

    for buf in (dh1, dh2, dh3, dh4):
        zeroize(buf)
    if opk_priv:
        zeroize(bytearray(opk_priv))
    return root


def safety_number(a: PublicIdentity, b: PublicIdentity) -> str:
    """Kurzer Vergleichswert fuer den Fall, dass zwei Menschen sich
    ausserhalb des Netzes vergewissern wollen.

    Das Verzeichnis macht diesen Abgleich weitgehend entbehrlich; wir
    bieten ihn trotzdem an, weil er die einzige Pruefung ist, die ohne
    jede Annahme ueber das Netz auskommt.
    """
    first, second = sorted([a.ik_pub + a.idk_pub, b.ik_pub + b.idk_pub])
    digest = sha256(b"nyx/v1/safety", first, second)
    number = int.from_bytes(digest[:15], "big")
    digits = str(number).zfill(36)[:36]
    return " ".join(digits[i:i + 6] for i in range(0, 36, 6))
