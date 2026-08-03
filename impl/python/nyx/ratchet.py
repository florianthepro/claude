"""Doppelratsche (Whitepaper 5.2 und 5.3).

Zwei Ketten:

  * die symmetrische Kette liefert pro Nachricht einen eigenen Schluessel
    ``MK_i``; sie laeuft nur vorwaerts (Vorwaertsgeheimhaltung),
  * die Diffie-Hellman-Ratsche bringt bei jedem Sprecherwechsel neues
    Material ein (Wiederherstellung nach Kompromittierung).

Der wichtigste Teil dieser Datei ist nicht die Ableitung, sondern die
Loeschung. ``decrypt`` ueberschreibt ``MK_i`` unmittelbar nach der
Authentifizierung — zwischen Entschluesselung und Loeschung liegt keine
Ein-/Ausgabe. Der Zwischenspeicher fuer uebersprungene Schluessel hat ein
hartes Doppellimit; wird es ueberschritten, ist die betreffende Nachricht
dauerhaft unlesbar. Das ist beabsichtigt: ein unbegrenzter Zwischenspeicher
hebt die Vorwaertsgeheimhaltung faktisch auf.
"""

from __future__ import annotations

import time
from dataclasses import dataclass

from .primitives import AuthError, Secret, hkdf, open_, seal, x25519_keypair, x25519_shared, zeroize

ROOT_STEP = "nyx/v1/ratchet-root"
CHAIN_STEP = "nyx/v1/ratchet-chain"
MSG_STEP = "nyx/v1/ratchet-message"

MAX_SKIPPED_KEYS = 1000          # Whitepaper 5.3, N_max
MAX_SKIPPED_AGE = 7 * 24 * 3600  # Whitepaper 5.3, T_max
MAX_SKIP_PER_MESSAGE = 256       # Schutz gegen erzwungene Ableitungslast


@dataclass
class MessageHeader:
    dh_pub: bytes
    pn: int
    n: int

    def to_bytes(self) -> bytes:
        return self.dh_pub + self.pn.to_bytes(4, "big") + self.n.to_bytes(4, "big")

    @staticmethod
    def from_bytes(raw: bytes) -> "MessageHeader":
        if len(raw) != 40:
            raise ValueError("Nachrichtenkopf muss 40 Byte sein")
        return MessageHeader(raw[:32], int.from_bytes(raw[32:36], "big"),
                             int.from_bytes(raw[36:40], "big"))


class SkippedKeys:
    """Schluessel fuer Nachrichten, die ausser der Reihe eintreffen."""

    def __init__(self, max_keys: int = MAX_SKIPPED_KEYS, max_age: int = MAX_SKIPPED_AGE):
        self.max_keys = max_keys
        self.max_age = max_age
        self._keys: dict[tuple[bytes, int], tuple[bytearray, float]] = {}

    def put(self, dh_pub: bytes, n: int, mk: bytes) -> None:
        self.expire()
        while len(self._keys) >= self.max_keys:
            oldest = min(self._keys, key=lambda k: self._keys[k][1])
            self._drop(oldest)
        self._keys[(dh_pub, n)] = (bytearray(mk), time.time())

    def take(self, dh_pub: bytes, n: int) -> bytes | None:
        self.expire()
        entry = self._keys.pop((dh_pub, n), None)
        if entry is None:
            return None
        buf, _ = entry
        raw = bytes(buf)
        zeroize(buf)  # genau einmal verwendbar
        return raw

    def expire(self) -> int:
        """Loescht alles, was aelter als ``max_age`` ist. Diese Nachrichten
        sind danach unwiederbringlich verloren — so ist es gemeint."""
        cutoff = time.time() - self.max_age
        stale = [k for k, (_, ts) in self._keys.items() if ts < cutoff]
        for key in stale:
            self._drop(key)
        return len(stale)

    def _drop(self, key) -> None:
        buf, _ = self._keys.pop(key)
        zeroize(buf)

    def destroy(self) -> None:
        for key in list(self._keys):
            self._drop(key)

    def __len__(self) -> int:
        return len(self._keys)


def _kdf_root(root: bytes, dh_out: bytes) -> tuple[bytes, bytes]:
    material = hkdf(dh_out, ROOT_STEP, 64, salt=root)
    return material[:32], material[32:]


def _kdf_chain(chain: bytes) -> tuple[bytes, bytes]:
    return hkdf(chain, CHAIN_STEP, 32), hkdf(chain, MSG_STEP, 32)


class Ratchet:
    """Der Sitzungszustand einer Unterhaltung."""

    def __init__(self):
        self._root = bytearray(32)
        self._send_chain: bytearray | None = None
        self._recv_chain: bytearray | None = None
        self._dh_priv: bytearray | None = None
        self.dh_pub: bytes | None = None
        self.peer_dh: bytes | None = None
        self.n_send = 0
        self.n_recv = 0
        self.pn = 0
        self.skipped = SkippedKeys()

    # -- Aufbau ----------------------------------------------------------

    @classmethod
    def as_sender(cls, root: Secret, peer_spk: bytes) -> "Ratchet":
        r = cls()
        r._root = bytearray(root.bytes()[:32])
        root.destroy()
        priv, pub = x25519_keypair()
        r._dh_priv, r.dh_pub, r.peer_dh = bytearray(priv), pub, peer_spk
        new_root, chain = _kdf_root(bytes(r._root), x25519_shared(priv, peer_spk))
        zeroize(r._root)
        r._root, r._send_chain = bytearray(new_root), bytearray(chain)
        return r

    @classmethod
    def as_receiver(cls, root: Secret, spk_priv: bytes, spk_pub: bytes) -> "Ratchet":
        r = cls()
        r._root = bytearray(root.bytes()[:32])
        root.destroy()
        r._dh_priv, r.dh_pub = bytearray(spk_priv), spk_pub
        return r

    # -- Senden ----------------------------------------------------------

    def encrypt(self, plaintext: bytes, aad: bytes = b"") -> tuple[MessageHeader, bytes]:
        if self._send_chain is None:
            raise ValueError("Sendekette nicht bereit")
        chain, mk = _kdf_chain(bytes(self._send_chain))
        zeroize(self._send_chain)
        self._send_chain = bytearray(chain)

        header = MessageHeader(self.dh_pub, self.pn, self.n_send)
        self.n_send += 1

        mk_buf = bytearray(mk)
        try:
            boxed = seal(bytes(mk_buf), self._nonce(header), plaintext,
                         aad + header.to_bytes())
        finally:
            zeroize(mk_buf)  # der Sender behaelt den Schluessel nicht
        return header, boxed

    # -- Empfangen -------------------------------------------------------

    def decrypt(self, header: MessageHeader, boxed: bytes, aad: bytes = b"") -> bytes:
        stored = self.skipped.take(header.dh_pub, header.n)
        if stored is not None:
            return self._open_and_wipe(stored, header, boxed, aad)

        if self.peer_dh != header.dh_pub:
            self._skip(self.pn if self.peer_dh is None else header.pn)
            self._dh_ratchet(header)

        self._skip(header.n)
        if self._recv_chain is None:
            raise ValueError("Empfangskette nicht bereit")

        chain, mk = _kdf_chain(bytes(self._recv_chain))
        zeroize(self._recv_chain)
        self._recv_chain = bytearray(chain)
        self.n_recv += 1
        return self._open_and_wipe(mk, header, boxed, aad)

    def _open_and_wipe(self, mk: bytes, header: MessageHeader,
                       boxed: bytes, aad: bytes) -> bytes:
        """Entschluesseln, pruefen, Schluessel ueberschreiben. In dieser
        Reihenfolge und ohne etwas dazwischen."""
        mk_buf = bytearray(mk)
        try:
            return open_(bytes(mk_buf), self._nonce(header), boxed,
                         aad + header.to_bytes())
        finally:
            zeroize(mk_buf)

    def _skip(self, until: int) -> None:
        if self._recv_chain is None:
            return
        if until - self.n_recv > MAX_SKIP_PER_MESSAGE:
            raise ValueError("zu viele uebersprungene Nachrichten auf einmal")
        while self.n_recv < until:
            chain, mk = _kdf_chain(bytes(self._recv_chain))
            zeroize(self._recv_chain)
            self._recv_chain = bytearray(chain)
            self.skipped.put(self.peer_dh, self.n_recv, mk)
            self.n_recv += 1

    def _dh_ratchet(self, header: MessageHeader) -> None:
        self.pn, self.n_send, self.n_recv = self.n_send, 0, 0
        self.peer_dh = header.dh_pub

        new_root, recv_chain = _kdf_root(bytes(self._root),
                                         x25519_shared(bytes(self._dh_priv), self.peer_dh))
        zeroize(self._root)
        if self._recv_chain is not None:
            zeroize(self._recv_chain)
        self._root, self._recv_chain = bytearray(new_root), bytearray(recv_chain)

        zeroize(self._dh_priv)
        priv, pub = x25519_keypair()
        self._dh_priv, self.dh_pub = bytearray(priv), pub

        new_root, send_chain = _kdf_root(bytes(self._root), x25519_shared(priv, self.peer_dh))
        zeroize(self._root)
        if self._send_chain is not None:
            zeroize(self._send_chain)
        self._root, self._send_chain = bytearray(new_root), bytearray(send_chain)

    @staticmethod
    def _nonce(header: MessageHeader) -> bytes:
        return b"\x00" * 4 + header.n.to_bytes(8, "big")

    # -- Ende ------------------------------------------------------------

    def destroy(self) -> None:
        """Beendet die Sitzung und laesst nichts zurueck."""
        for buf in (self._root, self._send_chain, self._recv_chain, self._dh_priv):
            if buf is not None:
                zeroize(buf)
        self.skipped.destroy()
        self._send_chain = self._recv_chain = self._dh_priv = None

    def __enter__(self) -> "Ratchet":
        return self

    def __exit__(self, *exc) -> None:
        self.destroy()


__all__ = ["AuthError", "MessageHeader", "Ratchet", "SkippedKeys",
           "MAX_SKIPPED_KEYS", "MAX_SKIPPED_AGE"]
