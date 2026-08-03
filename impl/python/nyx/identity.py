"""Identitaet: ein Schluesselpaar, sonst nichts.

Eine Identitaet wird lokal erzeugt und nirgends registriert. Sie besteht aus

  IK   Ed25519-Signaturschluessel — bestimmt die Adresse, signiert
       ausschliesslich Verzeichniseintraege, verschluesselt nie.
  IDK  X25519-Schluessel — nimmt am Handshake teil. Er ist durch einen
       von IK signierten BIND-Eintrag an die Identitaet gebunden.

Die Trennung ist Absicht: der Schluessel, der die Identitaet ausmacht, wird
nie fuer Diffie-Hellman verwendet, und der Schluessel, der fuer
Diffie-Hellman verwendet wird, kann ausgetauscht werden, ohne dass die
Adresse sich aendert.
"""

from __future__ import annotations

import base64
import json
import os
from dataclasses import dataclass

from .primitives import (
    ed25519_public,
    ed25519_sign,
    ed25519_verify,
    sha256,
    x25519_public,
)

ADDR_LABEL = b"nyx/v1/addr"
ADDR_SUM_LABEL = b"nyx/v1/addrsum"
ADDR_BODY = 20
ADDR_SUM = 2


def address_from_key(ik_pub: bytes) -> str:
    """Adresse = base32( H(IK_pub)[0..19] || pruefsumme ), 36 Zeichen."""
    body = sha256(ADDR_LABEL, ik_pub)[:ADDR_BODY]
    checksum = sha256(ADDR_SUM_LABEL, body)[:ADDR_SUM]
    return base64.b32encode(body + checksum).decode("ascii").rstrip("=").lower()


def address_valid(addr: str) -> bool:
    """Prueft nur die Pruefsumme — gegen Tippfehler, nicht gegen Faelschung."""
    try:
        raw = base64.b32decode(addr.upper() + "=" * (-len(addr) % 8))
    except Exception:
        return False
    if len(raw) != ADDR_BODY + ADDR_SUM:
        return False
    return sha256(ADDR_SUM_LABEL, raw[:ADDR_BODY])[:ADDR_SUM] == raw[ADDR_BODY:]


def address_matches_key(addr: str, ik_pub: bytes) -> bool:
    """Der eigentliche Beweis: die Adresse ist der Schluessel."""
    return address_from_key(ik_pub) == addr


@dataclass(frozen=True)
class PublicIdentity:
    """Was andere von einer Identitaet kennen."""

    address: str
    ik_pub: bytes
    idk_pub: bytes

    def verify_self_consistent(self) -> bool:
        return address_matches_key(self.address, self.ik_pub)

    def to_dict(self) -> dict:
        return {
            "address": self.address,
            "ik_pub": self.ik_pub.hex(),
            "idk_pub": self.idk_pub.hex(),
        }

    @staticmethod
    def from_dict(d: dict) -> "PublicIdentity":
        return PublicIdentity(
            address=d["address"],
            ik_pub=bytes.fromhex(d["ik_pub"]),
            idk_pub=bytes.fromhex(d["idk_pub"]),
        )


class Identity:
    """Eine vollstaendige Identitaet samt privatem Material."""

    def __init__(self, ik_seed: bytes, idk_priv: bytes, recovery_seed: bytes | None = None):
        self._ik_seed = bytearray(ik_seed)
        self._idk_priv = bytearray(idk_priv)
        self._recovery_seed = bytearray(recovery_seed) if recovery_seed else None
        self.ik_pub = ed25519_public(bytes(self._ik_seed))
        self.idk_pub = x25519_public(bytes(self._idk_priv))
        self.address = address_from_key(self.ik_pub)

    @classmethod
    def create(cls) -> "Identity":
        """Eine Identitaet entsteht hier — nicht bei einer Registrierung."""
        return cls(os.urandom(32), os.urandom(32), os.urandom(32))

    @property
    def public(self) -> PublicIdentity:
        return PublicIdentity(self.address, self.ik_pub, self.idk_pub)

    @property
    def idk_priv(self) -> bytes:
        return bytes(self._idk_priv)

    def sign(self, data: bytes) -> bytes:
        """Signiert Verzeichniseintraege. Nichts anderes."""
        return ed25519_sign(bytes(self._ik_seed), data)

    @property
    def recovery_pub(self) -> bytes | None:
        if self._recovery_seed is None:
            return None
        return ed25519_public(bytes(self._recovery_seed))

    def sign_recovery(self, data: bytes) -> bytes:
        if self._recovery_seed is None:
            raise ValueError("keine Wiederherstellung vorbereitet")
        return ed25519_sign(bytes(self._recovery_seed), data)

    # -- Aufbewahrung ----------------------------------------------------
    # Die Datei enthaelt den gesamten Wert der Identitaet. Sie gehoert auf
    # ein verschluesseltes Dateisystem; das Protokoll kann das nicht
    # erzwingen und behauptet es auch nicht.

    def export_private(self) -> str:
        return json.dumps(
            {
                "v": 1,
                "ik_seed": bytes(self._ik_seed).hex(),
                "idk_priv": bytes(self._idk_priv).hex(),
                "recovery_seed": bytes(self._recovery_seed).hex() if self._recovery_seed else None,
            },
            indent=2,
        )

    @classmethod
    def import_private(cls, blob: str) -> "Identity":
        d = json.loads(blob)
        rec = d.get("recovery_seed")
        return cls(
            bytes.fromhex(d["ik_seed"]),
            bytes.fromhex(d["idk_priv"]),
            bytes.fromhex(rec) if rec else None,
        )

    def __repr__(self) -> str:
        return f"<Identity {self.address}>"


def verify_signature(ik_pub: bytes, data: bytes, sig: bytes) -> bool:
    return ed25519_verify(ik_pub, data, sig)
