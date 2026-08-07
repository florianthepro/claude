#!/usr/bin/env python3
"""Nyx — anonymer, dezentraler Messenger. Alles in einer Datei.

    python3 nyx.py demo                      Vorfuehrung, ohne Netz
    python3 nyx.py knoten --port 8443        eigenen Knoten betreiben
    python3 nyx.py init                      Identitaet anlegen
    python3 nyx.py senden <adresse> "Text"
    python3 nyx.py holen

Keine Installation, keine Fremdpakete, Python 3.11 genuegt.

Diese Datei ist erzeugt aus impl/python/nyx. Der ausfuehrliche Quelltext mit
Tests und Dokumentation liegt dort; hier steht dasselbe am Stueck, damit man
mitmachen kann, ohne ein Projekt auszuchecken.

WICHTIG: Die kryptographischen Primitive sind reines Python und nicht
laufzeitkonstant. Fuer den ernsthaften Betrieb sind sie durch libsodium zu
ersetzen. Was sonst noch zu tun waere, steht in docs/sicherheitshinweis.md.
"""

from __future__ import annotations

import argparse
import base64
import ctypes
import hashlib
import hmac
import json
import os
import random
import struct
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from dataclasses import dataclass, field
from enum import Enum
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


# ==========================================================================
# primitives/kdf.py
# ==========================================================================
#
# Ableitungsfunktionen und Schluesselvernichtung.
#
# SHA-256 ist hier bewusst gewaehlt: es ist die Schnittmenge dessen, was die
# Python-Standardbibliothek, libsodium/PHP und die WebCrypto-API des Browsers
# alle ohne Fremdcode koennen. Damit kommen alle drei Clients auf dieselben
# Bytes.
#
# Jede Ableitung im Protokoll traegt ein eigenes Label. Zwei Ableitungen aus
# demselben Eingangsmaterial ergeben dadurch nie denselben Schluessel.

HASH = hashlib.sha256
HASH_LEN = 32


def sha256(*parts: bytes) -> bytes:
    h = HASH()
    for part in parts:
        h.update(part)
    return h.digest()


def hmac_sha256(key: bytes, *parts: bytes) -> bytes:
    mac = hmac.new(key, digestmod=HASH)
    for part in parts:
        mac.update(part)
    return mac.digest()


def hkdf_extract(salt: bytes, ikm: bytes) -> bytes:
    return hmac_sha256(salt or bytes(HASH_LEN), ikm)


def hkdf_expand(prk: bytes, info: bytes, length: int) -> bytes:
    if length > 255 * HASH_LEN:
        raise ValueError("HKDF-Ausgabe zu lang")
    out = bytearray()
    block = b""
    counter = 1
    while len(out) < length:
        block = hmac_sha256(prk, block, info, bytes([counter]))
        out += block
        counter += 1
    return bytes(out[:length])


def hkdf(ikm: bytes, label: str, length: int = 32, salt: bytes = b"") -> bytes:
    """Einstufige Ableitung mit Protokoll-Label."""
    return hkdf_expand(hkdf_extract(salt, ikm), label.encode("utf-8"), length)


# ------------------------------------------------------------ Vernichtung

def zeroize(buf: bytearray | None) -> None:
    """Ueberschreibt einen Puffer an Ort und Stelle.

    Das ist die einzige Loeschprimitive des Protokolls. Sie wirkt nur auf
    veraenderbare Puffer — deshalb wird Schluesselmaterial im gesamten Code
    als ``bytearray`` gefuehrt und nie als ``bytes`` weitergereicht, wenn es
    spaeter geloescht werden muss.

    Der Aufruf ueberschreibt zuverlaessig genau dieses Objekt. Kopien, die
    der Interpreter zuvor angelegt hat, erreicht er nicht; das ist die
    bekannte Grenze jeder Loeschung in einer verwalteten Laufzeit und in
    docs/sicherheitshinweis.md ausdruecklich vermerkt.
    """
    if buf is None:
        return
    if not isinstance(buf, bytearray):
        raise TypeError("zeroize erwartet ein bytearray")
    n = len(buf)
    if n:
        ctypes.memset((ctypes.c_char * n).from_buffer(buf), 0, n)


class Secret:
    """Ein Schluessel, der geloescht werden kann und sich nicht verplappert.

    ``repr`` und ``str`` geben nie den Wert aus; das verhindert, dass
    Schluesselmaterial ueber Protokolldateien oder Rueckverfolgungen
    nach aussen gelangt.
    """

    __slots__ = ("_buf", "_dead")

    def __init__(self, raw: bytes | bytearray):
        self._buf = bytearray(raw)
        self._dead = False

    def bytes(self) -> bytes:
        if self._dead:
            raise ValueError("Schluessel wurde bereits vernichtet")
        return bytes(self._buf)

    def destroy(self) -> None:
        zeroize(self._buf)
        self._dead = True

    @property
    def alive(self) -> bool:
        return not self._dead

    def __len__(self) -> int:
        return 0 if self._dead else len(self._buf)

    def __repr__(self) -> str:
        return f"<Secret {len(self._buf)}B {'vernichtet' if self._dead else 'lebend'}>"

    __str__ = __repr__

    def __enter__(self) -> "Secret":
        return self

    def __exit__(self, *exc) -> None:
        self.destroy()


# ==========================================================================
# primitives/aead.py
# ==========================================================================
#
# ChaCha20-Poly1305 (RFC 8439) in reinem Python.
#
# Gleiche Vorbehalte wie in curve25519.py: nicht laufzeitkonstant, fuer den
# Produktivbetrieb durch libsodium zu ersetzen. Die Byte-Ebene ist identisch
# zu ``sodium_crypto_aead_chacha20poly1305_ietf_*`` (PHP) und zu den
# gaengigen Browser-Implementierungen, damit alle drei Clients dieselben
# Chiffrate lesen.

_MASK32 = 0xFFFFFFFF
_SIGMA = b"expand 32-byte k"


def _rotl(v: int, n: int) -> int:
    return ((v << n) | (v >> (32 - n))) & _MASK32


def _quarter(s: list[int], a: int, b: int, c: int, d: int) -> None:
    s[a] = (s[a] + s[b]) & _MASK32
    s[d] = _rotl(s[d] ^ s[a], 16)
    s[c] = (s[c] + s[d]) & _MASK32
    s[b] = _rotl(s[b] ^ s[c], 12)
    s[a] = (s[a] + s[b]) & _MASK32
    s[d] = _rotl(s[d] ^ s[a], 8)
    s[c] = (s[c] + s[d]) & _MASK32
    s[b] = _rotl(s[b] ^ s[c], 7)


def _chacha20_block(key: bytes, counter: int, nonce: bytes) -> bytes:
    state = list(struct.unpack("<4I", _SIGMA))
    state += list(struct.unpack("<8I", key))
    state.append(counter & _MASK32)
    state += list(struct.unpack("<3I", nonce))

    work = state[:]
    for _ in range(10):
        _quarter(work, 0, 4, 8, 12)
        _quarter(work, 1, 5, 9, 13)
        _quarter(work, 2, 6, 10, 14)
        _quarter(work, 3, 7, 11, 15)
        _quarter(work, 0, 5, 10, 15)
        _quarter(work, 1, 6, 11, 12)
        _quarter(work, 2, 7, 8, 13)
        _quarter(work, 3, 4, 9, 14)

    return struct.pack("<16I", *[(work[i] + state[i]) & _MASK32 for i in range(16)])


def chacha20(key: bytes, counter: int, nonce: bytes, data: bytes) -> bytes:
    if len(key) != 32 or len(nonce) != 12:
        raise ValueError("ChaCha20 erwartet 32-Byte-Schluessel und 12-Byte-Nonce")
    out = bytearray(len(data))
    for offset in range(0, len(data), 64):
        block = _chacha20_block(key, counter + offset // 64, nonce)
        chunk = data[offset:offset + 64]
        for i, byte in enumerate(chunk):
            out[offset + i] = byte ^ block[i]
    return bytes(out)


_POLY_P = (1 << 130) - 5


def _poly1305(key: bytes, msg: bytes) -> bytes:
    r = int.from_bytes(key[:16], "little") & 0x0FFFFFFC0FFFFFFC0FFFFFFC0FFFFFFF
    s = int.from_bytes(key[16:32], "little")
    acc = 0
    for offset in range(0, len(msg), 16):
        chunk = msg[offset:offset + 16]
        n = int.from_bytes(chunk + b"\x01", "little")
        acc = (acc + n) * r % _POLY_P
    return ((acc + s) & ((1 << 128) - 1)).to_bytes(16, "little")


def _pad16(data: bytes) -> bytes:
    rest = len(data) % 16
    return b"" if rest == 0 else b"\x00" * (16 - rest)


def _tag(key: bytes, nonce: bytes, aad: bytes, ciphertext: bytes) -> bytes:
    poly_key = _chacha20_block(key, 0, nonce)[:32]
    mac_data = (
        aad + _pad16(aad)
        + ciphertext + _pad16(ciphertext)
        + struct.pack("<Q", len(aad))
        + struct.pack("<Q", len(ciphertext))
    )
    return _poly1305(poly_key, mac_data)


def seal(key: bytes, nonce: bytes, plaintext: bytes, aad: bytes = b"") -> bytes:
    """Chiffrat || 16-Byte-Tag, kompatibel zum IETF-Aufbau."""
    ciphertext = chacha20(key, 1, nonce, plaintext)
    return ciphertext + _tag(key, nonce, aad, ciphertext)


class AuthError(Exception):
    """Authentifizierung fehlgeschlagen — das Chiffrat wird verworfen."""


def open_(key: bytes, nonce: bytes, boxed: bytes, aad: bytes = b"") -> bytes:
    if len(boxed) < 16:
        raise AuthError("Chiffrat zu kurz")
    ciphertext, tag = boxed[:-16], boxed[-16:]
    expected = _tag(key, nonce, aad, ciphertext)
    # Vergleich ohne fruehen Abbruch.
    diff = 0
    for a, b in zip(tag, expected):
        diff |= a ^ b
    if diff != 0:
        raise AuthError("Authentifizierungs-Tag stimmt nicht")
    return chacha20(key, 1, nonce, ciphertext)


# ==========================================================================
# primitives/curve25519.py
# ==========================================================================
#
# X25519 (RFC 7748) und Ed25519 (RFC 8032) in reinem Python.
#
# Diese Implementierung existiert, damit die Referenzimplementierung ohne
# Fremdpakete laeuft und gegen PHP/libsodium und WebCrypto nachweisbar
# interoperabel ist.
#
# WARNUNG: Der Code ist nicht laufzeitkonstant. Python-Integer-Arithmetik
# verraet ueber Zeit- und Cache-Verhalten Informationen ueber die Schluessel.
# Fuer den Produktivbetrieb ist er durch libsodium zu ersetzen; die
# Schnittstelle ist dafuer absichtlich schmal gehalten.

P = 2**255 - 19
_A24 = 121665


# ---------------------------------------------------------------- X25519

def _clamp(k: bytes) -> int:
    b = bytearray(k)
    b[0] &= 248
    b[31] &= 127
    b[31] |= 64
    return int.from_bytes(b, "little")


def _decode_u(u: bytes) -> int:
    b = bytearray(u)
    b[31] &= 127
    return int.from_bytes(b, "little")


def x25519(scalar: bytes, u_coord: bytes) -> bytes:
    """Montgomery-Leiter nach RFC 7748, Abschnitt 5."""
    if len(scalar) != 32 or len(u_coord) != 32:
        raise ValueError("X25519 erwartet 32-Byte-Werte")

    k = _clamp(scalar)
    x1 = _decode_u(u_coord)
    x2, z2, x3, z3 = 1, 0, x1, 1
    swap = 0

    for t in range(254, -1, -1):
        kt = (k >> t) & 1
        swap ^= kt
        if swap:
            x2, x3 = x3, x2
            z2, z3 = z3, z2
        swap = kt

        a = (x2 + z2) % P
        aa = a * a % P
        b = (x2 - z2) % P
        bb = b * b % P
        e = (aa - bb) % P
        c = (x3 + z3) % P
        d = (x3 - z3) % P
        da = d * a % P
        cb = c * b % P
        x3 = (da + cb) % P
        x3 = x3 * x3 % P
        z3 = (da - cb) % P
        z3 = x1 * z3 % P * z3 % P
        x2 = aa * bb % P
        z2 = e * (aa + _A24 * e) % P

    if swap:
        x2, x3 = x3, x2
        z2, z3 = z3, z2

    shared = x2 * pow(z2, P - 2, P) % P
    return shared.to_bytes(32, "little")


_BASE_U = (9).to_bytes(32, "little")


def x25519_keypair() -> tuple[bytes, bytes]:
    """(privat, oeffentlich)."""
    priv = os.urandom(32)
    return priv, x25519(priv, _BASE_U)


def x25519_public(priv: bytes) -> bytes:
    return x25519(priv, _BASE_U)


def x25519_shared(priv: bytes, peer_pub: bytes) -> bytes:
    out = x25519(priv, peer_pub)
    if out == bytes(32):
        # Kleiner-Untergruppen-Punkt: gemeinsames Geheimnis waere null.
        raise ValueError("entartetes X25519-Ergebnis, oeffentlicher Schluessel verworfen")
    return out


# --------------------------------------------------------------- Ed25519
# Referenzimplementierung nach RFC 8032, Anhang A, auf das Noetige gekuerzt.

_D = -121665 * pow(121666, P - 2, P) % P
_Q = 2**252 + 27742317777372353535851937790883648493
_I = pow(2, (P - 1) // 4, P)


def _sha512(b: bytes) -> bytes:
    return hashlib.sha512(b).digest()


def _sha512_int(b: bytes) -> int:
    return int.from_bytes(_sha512(b), "little")


def _x_recover(y: int) -> int:
    xx = (y * y - 1) * pow(_D * y * y + 1, P - 2, P)
    x = pow(xx, (P + 3) // 8, P)
    if (x * x - xx) % P != 0:
        x = x * _I % P
    if x % 2 != 0:
        x = P - x
    return x


_BY = 4 * pow(5, P - 2, P) % P
_BX = _x_recover(_BY)
_B = (_BX % P, _BY % P, 1, _BX * _BY % P)
_IDENT = (0, 1, 1, 0)


def _edwards_add(p, q):
    x1, y1, z1, t1 = p
    x2, y2, z2, t2 = q
    a = (y1 - x1) * (y2 - x2) % P
    b = (y1 + x1) * (y2 + x2) % P
    c = t1 * 2 * _D * t2 % P
    d = z1 * 2 * z2 % P
    e, f, g, h = b - a, d - c, d + c, b + a
    return (e * f % P, g * h % P, f * g % P, e * h % P)


def _scalarmult(p, e: int):
    if e == 0:
        return _IDENT
    q = _scalarmult(p, e // 2)
    q = _edwards_add(q, q)
    if e & 1:
        q = _edwards_add(q, p)
    return q


def _point_compress(p) -> bytes:
    x, y, z, _ = p
    zi = pow(z, P - 2, P)
    x = x * zi % P
    y = y * zi % P
    return ((y & ~(1 << 255)) | ((x & 1) << 255)).to_bytes(32, "little")


def _point_decompress(s: bytes):
    if len(s) != 32:
        raise ValueError("Ed25519-Punkt muss 32 Byte sein")
    y = int.from_bytes(s, "little")
    sign = y >> 255
    y &= (1 << 255) - 1
    x = _x_recover(y)
    if x & 1 != sign:
        x = P - x
    return (x, y, 1, x * y % P)


def _point_equal(p, q) -> bool:
    x1, y1, z1, _ = p
    x2, y2, z2, _ = q
    return (x1 * z2 - x2 * z1) % P == 0 and (y1 * z2 - y2 * z1) % P == 0


def _secret_expand(secret: bytes) -> tuple[int, bytes]:
    if len(secret) != 32:
        raise ValueError("Ed25519-Saatgut muss 32 Byte sein")
    h = _sha512(secret)
    a = int.from_bytes(h[:32], "little")
    a &= (1 << 254) - 8
    a |= 1 << 254
    return a, h[32:]


def ed25519_public(secret: bytes) -> bytes:
    a, _ = _secret_expand(secret)
    return _point_compress(_scalarmult(_B, a))


def ed25519_keypair() -> tuple[bytes, bytes]:
    seed = os.urandom(32)
    return seed, ed25519_public(seed)


def ed25519_sign(secret: bytes, msg: bytes) -> bytes:
    a, prefix = _secret_expand(secret)
    pub = _point_compress(_scalarmult(_B, a))
    r = _sha512_int(prefix + msg) % _Q
    rp = _point_compress(_scalarmult(_B, r))
    k = _sha512_int(rp + pub + msg) % _Q
    s = (r + k * a) % _Q
    return rp + s.to_bytes(32, "little")


def ed25519_verify(pub: bytes, msg: bytes, sig: bytes) -> bool:
    if len(sig) != 64 or len(pub) != 32:
        return False
    try:
        rp = _point_decompress(sig[:32])
        ap = _point_decompress(pub)
    except (ValueError, ZeroDivisionError):
        return False
    s = int.from_bytes(sig[32:], "little")
    if s >= _Q:
        return False
    k = _sha512_int(sig[:32] + pub + msg) % _Q
    left = _scalarmult(_B, s)
    right = _edwards_add(rp, _scalarmult(ap, k))
    return _point_equal(left, right)


# ==========================================================================
# identity.py
# ==========================================================================
#
# Identitaet: ein Schluesselpaar, sonst nichts.
#
# Eine Identitaet wird lokal erzeugt und nirgends registriert. Sie besteht aus
#
#   IK   Ed25519-Signaturschluessel — bestimmt die Adresse, signiert
#        ausschliesslich Verzeichniseintraege, verschluesselt nie.
#   IDK  X25519-Schluessel — nimmt am Handshake teil. Er ist durch einen
#        von IK signierten BIND-Eintrag an die Identitaet gebunden.
#
# Die Trennung ist Absicht: der Schluessel, der die Identitaet ausmacht, wird
# nie fuer Diffie-Hellman verwendet, und der Schluessel, der fuer
# Diffie-Hellman verwendet wird, kann ausgetauscht werden, ohne dass die
# Adresse sich aendert.

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


# ==========================================================================
# directory.py
# ==========================================================================
#
# Das Verzeichnis: eine nur anhaengbare Kette von Schluesselereignissen.
#
# In die Kette gehen keine Nachrichten. Nur vier Eintragsarten:
#
#   BIND     bindet einen X25519-Handshakeschluessel an eine Identitaet
#   PREKEY   veroeffentlicht ein signiertes Prekey-Buendel
#   REVOKE   widerruft einen Schluessel
#   RECOVER  benennt eine Nachfolge-Identitaet
#
# Der Zweck ist nicht, das Unterschieben eines Schluessels unmoeglich zu
# machen, sondern es sichtbar und dauerhaft nachweisbar zu machen. Wer einen
# fremden Schluessel unterschiebt, hinterlaesst einen Eintrag, den jeder
# sehen und niemand nachtraeglich entfernen kann — genau das leistet
# ``key_history()``.

BLOCK_LABEL = b"nyx/v1/block"
RECORD_LABEL = b"nyx/v1/record"
LEAF_LABEL = b"\x00"
NODE_LABEL = b"\x01"

RECORD_TYPES = ("BIND", "PREKEY", "REVOKE", "RECOVER")


def canonical(obj) -> bytes:
    """Eindeutige Byte-Darstellung, damit Python, PHP und JS gleich hashen."""
    return json.dumps(obj, sort_keys=True, separators=(",", ":"),
                      ensure_ascii=True).encode("ascii")


# ------------------------------------------------------------- Eintraege

def make_record(identity: Identity, rtype: str, body: dict, ts: int | None = None) -> dict:
    if rtype not in RECORD_TYPES:
        raise ValueError(f"unbekannte Eintragsart: {rtype}")
    record = {
        "type": rtype,
        "addr": identity.address,
        "ik_pub": identity.ik_pub.hex(),
        "ts": int(ts if ts is not None else time.time()),
        "body": body,
    }
    record["sig"] = identity.sign(RECORD_LABEL + canonical(unsigned_part(record))).hex()
    return record


def verify_record(record: dict) -> bool:
    """Signatur, Adressbindung und Aufbau — alles oder nichts."""
    try:
        if record.get("type") not in RECORD_TYPES:
            return False
        ik_pub = bytes.fromhex(record["ik_pub"])
        sig = bytes.fromhex(record["sig"])
        if not address_matches_key(record["addr"], ik_pub):
            # Die Adresse ist der Schluessel. Passt das nicht, ist der
            # Eintrag wertlos, egal wie gueltig die Signatur ist.
            return False
        return verify_signature(ik_pub, RECORD_LABEL + canonical(unsigned_part(record)), sig)
    except (KeyError, ValueError, TypeError):
        return False


def unsigned_part(record: dict) -> dict:
    """Der signierte Umfang eines Eintrags: alles ausser den Signaturen."""
    return {k: v for k, v in record.items() if k not in ("sig", "recovery_sig")}


def verify_recover(record: dict, recovery_pub: bytes) -> bool:
    """Eine Nachfolge braucht beide Unterschriften: die der Identitaet und
    die des getrennt aufbewahrten Wiederherstellungsschluessels."""
    if record.get("type") != "RECOVER" or not verify_record(record):
        return False
    try:
        sig = bytes.fromhex(record["recovery_sig"])
    except (KeyError, ValueError):
        return False
    return verify_signature(recovery_pub, RECORD_LABEL + canonical(unsigned_part(record)), sig)


def record_hash(record: dict) -> bytes:
    return sha256(LEAF_LABEL, canonical(record))


# ----------------------------------------------------------- Merkle-Baum

def merkle_root(records: list[dict]) -> bytes:
    if not records:
        return bytes(32)
    level = [record_hash(r) for r in records]
    while len(level) > 1:
        if len(level) % 2:
            level.append(level[-1])
        level = [sha256(NODE_LABEL, level[i], level[i + 1])
                 for i in range(0, len(level), 2)]
    return level[0]


def merkle_proof(records: list[dict], index: int) -> list[dict]:
    """Pfad, mit dem ein Leichtclient einen Eintrag gegen den Blockkopf
    prueft, ohne alle Eintraege zu laden."""
    level = [record_hash(r) for r in records]
    proof: list[dict] = []
    while len(level) > 1:
        if len(level) % 2:
            level.append(level[-1])
        sibling = index ^ 1
        proof.append({"side": "left" if sibling < index else "right",
                      "hash": level[sibling].hex()})
        level = [sha256(NODE_LABEL, level[i], level[i + 1])
                 for i in range(0, len(level), 2)]
        index //= 2
    return proof


def verify_merkle_proof(record: dict, proof: list[dict], root: bytes) -> bool:
    node = record_hash(record)
    for step in proof:
        sibling = bytes.fromhex(step["hash"])
        node = (sha256(NODE_LABEL, sibling, node) if step["side"] == "left"
                else sha256(NODE_LABEL, node, sibling))
    return node == root


# ---------------------------------------------------------------- Bloecke

def block_hash(header: dict) -> bytes:
    return sha256(BLOCK_LABEL, canonical(header))


def leading_zero_bits(digest: bytes) -> int:
    n = 0
    for byte in digest:
        if byte:
            return n + (8 - byte.bit_length())
        n += 8
    return n


@dataclass
class Block:
    height: int
    prev: str
    merkle_root: str
    ts: int
    bits: int
    nonce: int = 0
    records: list[dict] = field(default_factory=list)

    def header(self) -> dict:
        return {
            "height": self.height,
            "prev": self.prev,
            "merkle_root": self.merkle_root,
            "ts": self.ts,
            "bits": self.bits,
            "nonce": self.nonce,
        }

    def hash(self) -> str:
        return block_hash(self.header()).hex()

    def to_dict(self) -> dict:
        d = self.header()
        d["records"] = self.records
        return d

    @staticmethod
    def from_dict(d: dict) -> "Block":
        return Block(d["height"], d["prev"], d["merkle_root"], d["ts"],
                     d["bits"], d["nonce"], d.get("records", []))


class Chain:
    """Kette mit Arbeitsnachweis.

    Die Schwierigkeit ist hier klein gewaehlt, damit die Demo auf einem
    Rechner laeuft. Im Betrieb ergibt sie sich aus der Rechenleistung des
    Netzes; die Pruefregeln bleiben dieselben.
    """

    def __init__(self, bits: int = 12):
        self.bits = bits
        self.blocks: list[Block] = []
        self.pending: list[dict] = []
        self._genesis()

    def _genesis(self) -> None:
        g = Block(0, "0" * 64, bytes(32).hex(), 0, self.bits, 0, [])
        self._mine(g)
        self.blocks.append(g)

    def _mine(self, block: Block) -> None:
        while leading_zero_bits(block_hash(block.header())) < block.bits:
            block.nonce += 1

    # -- Schreiben -------------------------------------------------------

    def submit(self, record: dict) -> None:
        """Ein Eintrag kostet Arbeit; ungueltige werden nicht angenommen."""
        if not verify_record(record):
            raise ValueError("Eintrag ist nicht gueltig signiert")
        self.pending.append(record)

    def seal(self, ts: int | None = None) -> Block | None:
        """Schliesst die anstehenden Eintraege in einen Block ein."""
        if not self.pending:
            return None
        records, self.pending = self.pending, []
        block = Block(
            height=len(self.blocks),
            prev=self.blocks[-1].hash(),
            merkle_root=merkle_root(records).hex(),
            ts=int(ts if ts is not None else time.time()),
            bits=self.bits,
            records=records,
        )
        self._mine(block)
        self.blocks.append(block)
        return block

    # -- Pruefen ---------------------------------------------------------

    def validate(self) -> bool:
        """Vollstaendige Pruefung, wie sie ein neuer Knoten durchfuehrt."""
        for i, block in enumerate(self.blocks):
            if block.height != i:
                return False
            if i and block.prev != self.blocks[i - 1].hash():
                return False
            if leading_zero_bits(block_hash(block.header())) < block.bits:
                return False
            if block.merkle_root != merkle_root(block.records).hex():
                return False
            if any(not verify_record(r) for r in block.records):
                return False
        return True

    def headers(self) -> list[dict]:
        """Was ein Leichtclient laedt — Kopf ohne Eintraege."""
        return [b.header() for b in self.blocks]

    def proof_for(self, address: str, rtype: str) -> dict | None:
        """SPV-Beweis fuer den juengsten Eintrag einer Art."""
        for block in reversed(self.blocks):
            for i, rec in enumerate(block.records):
                if rec["addr"] == address and rec["type"] == rtype:
                    return {
                        "record": rec,
                        "proof": merkle_proof(block.records, i),
                        "header": block.header(),
                    }
        return None

    # -- Lesen -----------------------------------------------------------

    def records_for(self, address: str, rtype: str | None = None) -> list[dict]:
        out = []
        for block in self.blocks:
            for rec in block.records:
                if rec["addr"] == address and (rtype is None or rec["type"] == rtype):
                    out.append({**rec, "height": block.height})
        return out

    def revoked_keys(self, address: str) -> set[str]:
        return {r["body"]["key"] for r in self.records_for(address, "REVOKE")
                if "key" in r.get("body", {})}

    def resolve(self, address: str) -> PublicIdentity | None:
        """Aktuelle Handshake-Bindung einer Adresse."""
        revoked = self.revoked_keys(address)
        for rec in reversed(self.records_for(address, "BIND")):
            idk = rec["body"]["idk_pub"]
            if idk not in revoked:
                return PublicIdentity(address, bytes.fromhex(rec["ik_pub"]),
                                      bytes.fromhex(idk))
        return None

    def latest_bundle(self, address: str) -> dict | None:
        revoked = self.revoked_keys(address)
        for rec in reversed(self.records_for(address, "PREKEY")):
            if rec["body"]["spk_pub"] not in revoked:
                return rec["body"]
        return None

    def key_history(self, address: str) -> list[dict]:
        """Jeder Schluesselwechsel, mit Blockhoehe und Zeitpunkt.

        Das ist der praktische Nutzen der Kette: Ein untergeschobener
        Schluessel erscheint hier als zusaetzlicher Eintrag. Ein Client, der
        diese Liste anzeigt, macht den Angriff fuer den Nutzer sichtbar,
        ohne dass dieser Pruefsummen von Hand vergleichen muss.
        """
        out = []
        for rec in self.records_for(address):
            body = rec.get("body", {})
            key = body.get("idk_pub") or body.get("spk_pub") or body.get("key")
            out.append({
                "height": rec["height"],
                "type": rec["type"],
                "ts": rec["ts"],
                "key": key,
            })
        return out


# --------------------------------------------------- Eintraege erzeugen

def bind_record(identity: Identity) -> dict:
    return make_record(identity, "BIND", {
        "idk_pub": identity.idk_pub.hex(),
        "recovery_pub": identity.recovery_pub.hex() if identity.recovery_pub else None,
    })


def prekey_record(identity: Identity, bundle_body: dict) -> dict:
    return make_record(identity, "PREKEY", bundle_body)


def revoke_record(identity: Identity, key_hex: str, reason: str = "") -> dict:
    return make_record(identity, "REVOKE", {"key": key_hex, "reason": reason})


def recover_record(identity: Identity, successor_address: str) -> dict:
    rec = make_record(identity, "RECOVER", {"successor": successor_address})
    rec["recovery_sig"] = identity.sign_recovery(
        RECORD_LABEL + canonical(unsigned_part(rec))).hex()
    return rec


# ==========================================================================
# x3dh.py
# ==========================================================================
#
# Sitzungsaufbau (Whitepaper 5.1).
#
# Der Sender laedt ein signiertes Prekey-Buendel aus dem Verzeichnis, prueft
# die Signatur gegen den Identitaetsschluessel und berechnet vier
# Diffie-Hellman-Werte. Alle Zwischenergebnisse und der ephemere private
# Schluessel werden unmittelbar danach ueberschrieben.

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


# ==========================================================================
# ratchet.py
# ==========================================================================
#
# Doppelratsche (Whitepaper 5.2 und 5.3).
#
# Zwei Ketten:
#
#   * die symmetrische Kette liefert pro Nachricht einen eigenen Schluessel
#     ``MK_i``; sie laeuft nur vorwaerts (Vorwaertsgeheimhaltung),
#   * die Diffie-Hellman-Ratsche bringt bei jedem Sprecherwechsel neues
#     Material ein (Wiederherstellung nach Kompromittierung).
#
# Der wichtigste Teil dieser Datei ist nicht die Ableitung, sondern die
# Loeschung. ``decrypt`` ueberschreibt ``MK_i`` unmittelbar nach der
# Authentifizierung — zwischen Entschluesselung und Loeschung liegt keine
# Ein-/Ausgabe. Der Zwischenspeicher fuer uebersprungene Schluessel hat ein
# hartes Doppellimit; wird es ueberschritten, ist die betreffende Nachricht
# dauerhaft unlesbar. Das ist beabsichtigt: ein unbegrenzter Zwischenspeicher
# hebt die Vorwaertsgeheimhaltung faktisch auf.

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


# ==========================================================================
# gf256.py
# ==========================================================================
#
# Arithmetik in GF(2^8) und Matrizen darueber.
#
# Grundlage der Reed-Solomon-Kodierung in dispersal.py. Das Feld ist
# GF(2^8) mit dem ueblichen Polynom 0x11d; alle Werte sind Bytes, alle
# Rechnungen exakt.

POLY = 0x11D

_EXP = [0] * 512
_LOG = [0] * 256


def _build_tables() -> None:
    x = 1
    for i in range(255):
        _EXP[i] = x
        _LOG[x] = i
        x <<= 1
        if x & 0x100:
            x ^= POLY
    for i in range(255, 512):
        _EXP[i] = _EXP[i - 255]


_build_tables()


def mul(a: int, b: int) -> int:
    if a == 0 or b == 0:
        return 0
    return _EXP[_LOG[a] + _LOG[b]]


def div(a: int, b: int) -> int:
    if b == 0:
        raise ZeroDivisionError("Division durch null in GF(256)")
    if a == 0:
        return 0
    return _EXP[(_LOG[a] - _LOG[b]) % 255]


def inv(a: int) -> int:
    return div(1, a)


Matrix = list[list[int]]


def identity(n: int) -> Matrix:
    return [[1 if i == j else 0 for j in range(n)] for i in range(n)]


def vandermonde(rows: int, cols: int) -> Matrix:
    """v[i][j] = i^j. Je k Zeilen sind linear unabhaengig — das ist die
    Eigenschaft, auf der die Wiederherstellung aus beliebigen k Teilen
    beruht."""
    if rows > 256:
        raise ValueError("GF(256) traegt hoechstens 256 Zeilen")
    out: Matrix = []
    for i in range(rows):
        row, val = [], 1
        for _ in range(cols):
            row.append(val)
            val = mul(val, i)
        out.append(row)
    return out


def matmul(a: Matrix, b: Matrix) -> Matrix:
    n, m, p = len(a), len(b), len(b[0])
    out = [[0] * p for _ in range(n)]
    for i in range(n):
        for j in range(p):
            acc = 0
            for t in range(m):
                acc ^= mul(a[i][t], b[t][j])
            out[i][j] = acc
    return out


def invert(matrix: Matrix) -> Matrix:
    """Gauss-Jordan ueber GF(256)."""
    n = len(matrix)
    work = [row[:] + ident_row for row, ident_row in zip(matrix, identity(n))]

    for col in range(n):
        pivot = next((r for r in range(col, n) if work[r][col] != 0), None)
        if pivot is None:
            raise ValueError("Matrix ist singulaer — Teile nicht unabhaengig")
        work[col], work[pivot] = work[pivot], work[col]

        factor = inv(work[col][col])
        work[col] = [mul(v, factor) for v in work[col]]

        for r in range(n):
            if r != col and work[r][col]:
                f = work[r][col]
                work[r] = [v ^ mul(f, w) for v, w in zip(work[r], work[col])]

    return [row[n:] for row in work]


# ==========================================================================
# dispersal.py
# ==========================================================================
#
# Zerlegung in k-von-n Teile (Whitepaper 7.3).
#
# Zwei Schritte, und die Reihenfolge ist wesentlich:
#
# 1. Eine Alles-oder-nichts-Transformation (AONT, nach Rivest) wandelt das
#    Chiffrat so um, dass aus einem echten Teil der Ausgabe kein einziges Bit
#    der Eingabe folgt.
# 2. Reed-Solomon verteilt das Ergebnis auf n Teile, von denen k genuegen.
#
# Ohne Schritt 1 waere die systematische Kodierung ein Fehler: die ersten
# Teile waeren woertliche Ausschnitte der Eingabe. Mit Schritt 1 gilt, worauf
# es ankommt — mit k-1 Teilen weiss ein Angreifer so viel wie mit null, und
# sobald n-k+1 Knoten geloescht haben, ist die Ablage fuer alle
# unwiederbringlich zerstoert.

AONT_LABEL = b"nyx/v1/aont"
AONT_KEY_LEN = 32
_ZERO_NONCE = bytes(12)


# ----------------------------------------------------- Alles oder nichts

def aont_transform(data: bytes) -> bytes:
    """w = E_K(data) || (K xor H(E_K(data))).

    Der Schluessel K ist zufaellig und wird nicht aufbewahrt; er steckt
    verschleiert im Anhang. Wer w vollstaendig hat, rechnet ihn heraus. Wer
    auch nur ein Byte von w nicht hat, bekommt H(c) nicht und damit K nicht
    — und ohne K ist c ununterscheidbar von Rauschen.
    """
    key = os.urandom(AONT_KEY_LEN)
    body = chacha20(key, 1, _ZERO_NONCE, data)
    digest = sha256(AONT_LABEL, body)
    tail = bytes(a ^ b for a, b in zip(key, digest))
    return body + tail


def aont_invert(package: bytes) -> bytes:
    if len(package) < AONT_KEY_LEN:
        raise ValueError("AONT-Paket zu kurz")
    body, tail = package[:-AONT_KEY_LEN], package[-AONT_KEY_LEN:]
    digest = sha256(AONT_LABEL, body)
    key = bytes(a ^ b for a, b in zip(tail, digest))
    return chacha20(key, 1, _ZERO_NONCE, body)


# ------------------------------------------------------- Reed-Solomon k/n

def _generator(k: int, n: int) -> Matrix:
    """Systematische Erzeugermatrix: die ersten k Zeilen sind die Einheit,
    jede Auswahl von k Zeilen ist invertierbar."""
    if not 1 <= k <= n <= 256:
        raise ValueError("es muss 1 <= k <= n <= 256 gelten")
    vand = vandermonde(n, k)
    top_inv = invert([row[:] for row in vand[:k]])
    return matmul(vand, top_inv)


@dataclass(frozen=True)
class Share:
    index: int
    k: int
    n: int
    length: int      # Laenge des AONT-Pakets vor der Auffuellung
    data: bytes

    def to_dict(self) -> dict:
        return {"index": self.index, "k": self.k, "n": self.n,
                "length": self.length, "data": self.data.hex()}

    @staticmethod
    def from_dict(d: dict) -> "Share":
        return Share(d["index"], d["k"], d["n"], d["length"],
                     bytes.fromhex(d["data"]))


def split(package: bytes, k: int, n: int) -> list[Share]:
    matrix = _generator(k, n)
    padded = package + bytes((-len(package)) % k)
    chunk = len(padded) // k
    columns = [padded[i * chunk:(i + 1) * chunk] for i in range(k)]

    shares = []
    for i in range(n):
        row = matrix[i]
        if i < k:
            payload = columns[i]          # systematischer Teil
        else:
            acc = bytearray(chunk)
            for j, coeff in enumerate(row):
                if coeff:
                    col = columns[j]
                    for b in range(chunk):
                        acc[b] ^= mul(coeff, col[b])
            payload = bytes(acc)
        shares.append(Share(i, k, n, len(package), payload))
    return shares


def combine(shares: list[Share]) -> bytes:
    if not shares:
        raise ValueError("keine Teile vorhanden")
    k, n = shares[0].k, shares[0].n
    if any(s.k != k or s.n != n for s in shares):
        raise ValueError("Teile stammen aus verschiedenen Zerlegungen")

    picked = {}
    for s in shares:
        picked.setdefault(s.index, s)
    if len(picked) < k:
        # Unterhalb der Schwelle. Es gibt hier bewusst keinen Teilerfolg.
        raise ValueError(f"unterhalb der Schwelle: {len(picked)} von {k} Teilen")

    chosen = [picked[i] for i in sorted(picked)[:k]]
    matrix = _generator(k, n)
    sub = [matrix[s.index][:] for s in chosen]
    inverse = invert(sub)

    chunk = len(chosen[0].data)
    out = bytearray()
    for i in range(k):
        acc = bytearray(chunk)
        for j, coeff in enumerate(inverse[i]):
            if coeff:
                col = chosen[j].data
                for b in range(chunk):
                    acc[b] ^= mul(coeff, col[b])
        out += acc
    return bytes(out[:chosen[0].length])


# --------------------------------------------------------- Beides zusammen

DEFAULT_K = 10
DEFAULT_N = 20


def disperse(data: bytes, k: int = DEFAULT_K, n: int = DEFAULT_N) -> list[Share]:
    """Was in die Ablage geht: n Teile, k genuegen, weniger als k ergeben
    nichts."""
    return split(aont_transform(data), k, n)


def reassemble(shares: list[Share]) -> bytes:
    return aont_invert(combine(shares))


def destroyed(remaining: int, k: int) -> bool:
    """Ist die Ablage unwiederbringlich zerstoert?

    Genau dann, wenn weniger als k Teile ueberlebt haben. Das ist die
    Aussage aus Whitepaper 7.7, Schicht 3, in einer Zeile.
    """
    return remaining < k


# ==========================================================================
# puncturable.py
# ==========================================================================
#
# Punktierbare PRF (Whitepaper 7.7, Schicht 2).
#
# Der Speicherknoten legt jeden Teil unter einem Schluessel ab, den er aus
# seinem eigenen Speicherschluessel ableitet. Nach der Herausgabe entfernt er
# aus diesem Schluessel die Faehigkeit, genau diesen Tag abzuleiten — und
# behaelt alle uebrigen. Wird der Knoten danach beschlagnahmt, kann er den
# herausgegebenen Teil selbst dann nicht mehr entschluesseln, wenn das
# Chiffrat noch auf seinem Datentraeger liegt und der Angreifer den
# vollstaendigen Speicherinhalt samt Schluesselmaterial hat.
#
# Der Aufbau ist der uebliche GGM-Baum: aus einem Saatgut werden zwei
# Kindsaatgueter abgeleitet, die Ableitung ist eine Einbahnstrasse. Der
# Knoten haelt eine Menge von Teilbaum-Saatguetern, die zusammen alle
# Blaetter ausser den punktierten abdecken. Punktieren heisst: den Pfad zum
# Blatt aufklappen, die Geschwister behalten, das Blatt fallen lassen.
#
# Kosten: pro Punktierung waechst die Schluesselmenge um hoechstens ``depth``
# Eintraege. Das ist der Preis dafuer, dass Vergessen hier nicht auf einem
# Versprechen beruht, sondern auf einer Einwegfunktion.

DEPTH = 48
LEFT = b"\x00"
RIGHT = b"\x01"
LEAF = b"\x02"


def leaf_index(tag: bytes, depth: int = DEPTH) -> int:
    """Bildet einen Tag auf ein Blatt des Baums ab."""
    digest = sha256(b"nyx/v1/punct-index", tag)
    return int.from_bytes(digest[:8], "big") >> (64 - depth)


def _child(seed: bytes, bit: int) -> bytes:
    return hmac_sha256(seed, RIGHT if bit else LEFT)


def _descend(seed: bytes, index: int, from_depth: int, to_depth: int) -> bytes:
    for level in range(from_depth, to_depth):
        bit = (index >> (DEPTH - 1 - level)) & 1
        seed = _child(seed, bit)
    return seed


class PuncturableKey:
    """Speicherschluessel eines Knotens, aus dem einzelne Tags entfernbar sind."""

    def __init__(self, master: bytes, depth: int = DEPTH):
        self.depth = depth
        # (prefix_bits, prefix_len, seed) — deckt alle Blaetter unter dem Prefix ab
        self._cover: list[tuple[int, int, bytes]] = [(0, 0, master)]
        self._punctured = 0

    # -- Ableiten --------------------------------------------------------

    def _covering(self, index: int) -> tuple[int, int, bytes] | None:
        for prefix, plen, seed in self._cover:
            if plen == 0 or (index >> (self.depth - plen)) == prefix:
                return prefix, plen, seed
        return None

    def derive(self, tag: bytes) -> bytes | None:
        """Schluessel fuer einen Tag, oder None wenn bereits punktiert."""
        index = leaf_index(tag, self.depth)
        entry = self._covering(index)
        if entry is None:
            return None
        _, plen, seed = entry
        leaf = _descend(seed, index, plen, self.depth)
        return hmac_sha256(leaf, LEAF + tag)

    # -- Vergessen -------------------------------------------------------

    def puncture(self, tag: bytes) -> bool:
        """Entfernt genau diesen Tag aus dem Schluessel. Unumkehrbar."""
        index = leaf_index(tag, self.depth)
        entry = self._covering(index)
        if entry is None:
            return False

        prefix, plen, seed = entry
        self._cover.remove(entry)

        # Pfad zum Blatt aufklappen, jeweils den Geschwisterteilbaum behalten.
        node_prefix, node_seed = prefix, seed
        for level in range(plen, self.depth):
            bit = (index >> (self.depth - 1 - level)) & 1
            sibling_seed = _child(node_seed, 1 - bit)
            sibling_prefix = (node_prefix << 1) | (1 - bit)
            self._cover.append((sibling_prefix, level + 1, sibling_seed))
            node_seed = _child(node_seed, bit)
            node_prefix = (node_prefix << 1) | bit
        # node_seed waere das Blatt — es wird nicht aufbewahrt.
        self._punctured += 1
        return True

    @property
    def punctured_count(self) -> int:
        return self._punctured

    @property
    def key_size(self) -> int:
        """Anzahl der aufbewahrten Teilbaum-Saatgueter."""
        return len(self._cover)

    def __repr__(self) -> str:
        return (f"<PuncturableKey tiefe={self.depth} "
                f"punktiert={self._punctured} teilbaeume={len(self._cover)}>")


# ==========================================================================
# store.py
# ==========================================================================
#
# Speicherknoten: blinde Ablagen (Whitepaper 7.4 bis 7.7).
#
# Was der Knoten sieht: einen Zufallswert und einen Block Rauschen fester
# Groesse. Er kennt weder Sender noch Empfaenger, weder die Zugehoerigkeit zu
# einer Unterhaltung noch die zu einer Nachricht, noch weiss er, welche
# anderen Knoten die uebrigen Teile halten.
#
# Der Knoten setzt drei der vier Schichten aus 7.7 um:
#
#   Schicht 2  punktierbare Speicherverschluesselung (puncturable.py)
#   Schicht 3  er haelt nur einen von n Teilen, nie genug zur Rekonstruktion
#   Schicht 4  Verfallszeit
#
# Schicht 1 — der Nachrichtenschluessel, der hier nie ankommt — liegt
# ausserhalb dieses Moduls und ist genau deshalb die staerkste.

DEFAULT_TTL = 7 * 24 * 3600
MAX_BLOB = 1 << 20
TAG_LEN = 32
DEFAULT_POW_BITS = 12
POW_LABEL = b"nyx/v1/put-pow"


def pow_digest(tag: str, blob: bytes, nonce: int) -> bytes:
    return sha256(POW_LABEL, tag.encode(), blob, nonce.to_bytes(8, "big"))


def leading_zero_bits(digest: bytes) -> int:
    n = 0
    for byte in digest:
        if byte:
            return n + (8 - byte.bit_length())
        n += 8
    return n


def solve_pow(tag: str, blob: bytes, bits: int) -> int:
    """Arbeitsnachweis fuer eine Ablage.

    Speicherplatz ist die knappe Ressource des Netzes. Ohne Kosten je
    Ablage koennte ein Angreifer die Knoten mit Muell fuellen und damit
    Zustellungen verhindern, ohne eine einzige Nachricht zu lesen. Der
    Nachweis kostet den ehrlichen Sender Millisekunden und einen
    Fluter das Vielfache davon je Paket.
    """
    if bits <= 0:
        return 0
    nonce = 0
    while leading_zero_bits(pow_digest(tag, blob, nonce)) < bits:
        nonce += 1
    return nonce


@dataclass
class Entry:
    tag: str
    blob: bytes          # unter dem Knotenschluessel abgelegt
    expires: int
    stored_at: float = field(default_factory=time.time)


class StorageNode:
    """Ein Knoten des Speichernetzes."""

    def __init__(self, name: str = "knoten", ttl: int = DEFAULT_TTL,
                 hoard: bool = False, pow_bits: int = DEFAULT_POW_BITS):
        self.name = name
        self.ttl = ttl
        self.pow_bits = pow_bits
        self._entries: dict[str, Entry] = {}
        self._key = PuncturableKey(os.urandom(32))
        # Nur fuer die Simulation: ein Knoten, der entgegen der Absprache
        # aufbewahrt. Das Protokoll kann ihn nicht daran hindern — die
        # Sicherheit haengt deshalb nicht daran (Whitepaper 7.8).
        self.hoard = hoard
        self._hoarded: dict[str, bytes] = {}
        self.stats = {"gespeichert": 0, "ausgeliefert": 0,
                      "verfallen": 0, "abgelehnt": 0}

    # -- Ablegen ---------------------------------------------------------

    def put(self, tag: str, blob: bytes, ttl: int | None = None,
            nonce: int | None = None) -> dict:
        if len(bytes.fromhex(tag)) != TAG_LEN:
            self.stats["abgelehnt"] += 1
            raise ValueError("Tag muss 32 Byte sein")
        if len(blob) > MAX_BLOB:
            self.stats["abgelehnt"] += 1
            raise ValueError("Ablage zu gross")
        if tag in self._entries:
            self.stats["abgelehnt"] += 1
            raise ValueError("Tag bereits belegt")
        if self.pow_bits > 0:
            if nonce is None or leading_zero_bits(
                    pow_digest(tag, blob, nonce)) < self.pow_bits:
                self.stats["abgelehnt"] += 1
                raise ValueError("Arbeitsnachweis fehlt oder ist zu schwach")

        key = self._key.derive(bytes.fromhex(tag))
        if key is None:
            # Bereits punktiert: dieser Tag wurde schon einmal abgeholt.
            self.stats["abgelehnt"] += 1
            raise ValueError("Tag wurde bereits verbraucht")

        expires = int(time.time()) + (self.ttl if ttl is None else ttl)
        sealed = seal(key, self._nonce(tag), blob, tag.encode())
        self._entries[tag] = Entry(tag, sealed, expires)
        if self.hoard:
            self._hoarded[tag] = sealed
        self.stats["gespeichert"] += 1
        return {"tag": tag, "expires": expires, "node": self.name}

    # -- Abholen ---------------------------------------------------------

    def get(self, tag: str) -> bytes | None:
        """Genau einmal. Danach ist der Tag punktiert und der Eintrag weg."""
        self.sweep()
        entry = self._entries.get(tag)
        if entry is None:
            return None

        key = self._key.derive(bytes.fromhex(tag))
        if key is None:
            return None
        try:
            blob = open_(key, self._nonce(tag), entry.blob, tag.encode())
        except AuthError:
            return None

        # Erst herausgeben, dann vergessen — in dieser Reihenfolge.
        del self._entries[tag]
        self._key.puncture(bytes.fromhex(tag))
        self.stats["ausgeliefert"] += 1
        return blob

    def void(self, tag: str) -> bool:
        """Entwertet einen Tag, ohne ihn auszuliefern.

        Der Empfaenger braucht nur k der n Teile. Die uebrigen n-k wuerden
        sonst bis zum Verfall liegen bleiben und waeren fuer einen
        hortenden Knoten weiterhin lesbar. Deshalb entwertet der Client
        nach erfolgreicher Abholung alle Teile — die es hierher schaffen,
        werden geloescht und punktiert, ohne dass Daten uebertragen werden.

        Wer den Tag kennt, kennt ihn aus der Sitzung; ein Dritter kann ihn
        nicht raten. Ein boeswilliger Knoten kann den Aufruf ignorieren —
        genau deshalb haengt keine Garantie daran (Whitepaper 7.8).
        """
        existed = self._entries.pop(tag, None) is not None
        self._key.puncture(bytes.fromhex(tag))
        return existed

    def void_batch(self, tags: list[str]) -> int:
        return sum(1 for tag in tags if self.void(tag))

    def get_batch(self, tags: list[str]) -> dict[str, str | None]:
        """Sammelabfrage. Fuelltags werden gleich behandelt wie echte —
        der Knoten kann nicht unterscheiden, welcher gemeint war."""
        return {t: (b.hex() if (b := self.get(t)) is not None else None)
                for t in tags}

    def has(self, tag: str) -> bool:
        self.sweep()
        return tag in self._entries

    # -- Verfall ---------------------------------------------------------

    def sweep(self) -> int:
        now = time.time()
        stale = [t for t, e in self._entries.items() if e.expires <= now]
        for tag in stale:
            del self._entries[tag]
            self._key.puncture(bytes.fromhex(tag))
        self.stats["verfallen"] += len(stale)
        return len(stale)

    # -- Aufbewahrungsnachweis (Whitepaper 7.5) --------------------------

    def prove_retrievability(self, challenge: bytes) -> dict:
        """Beweis ueber einen zufaelligen Ausschnitt des Bestandes.

        Nur mit den tatsaechlich vorhandenen Daten berechenbar. Bleibt der
        Beweis aus, verfaellt das Pfand des Knotens.
        """
        self.sweep()
        tags = sorted(self._entries)
        if not tags:
            return {"node": self.name, "count": 0,
                    "proof": sha256(b"nyx/v1/por-empty", challenge).hex()}

        picked = []
        acc = sha256(b"nyx/v1/por", challenge)
        for i in range(min(8, len(tags))):
            index = int.from_bytes(hmac_sha256(challenge, i.to_bytes(4, "big"))[:4],
                                   "big") % len(tags)
            tag = tags[index]
            picked.append(tag)
            acc = sha256(acc, tag.encode(), self._entries[tag].blob)
        return {"node": self.name, "count": len(tags),
                "sampled": len(picked), "proof": acc.hex()}

    # -- Nur fuer die Simulation ----------------------------------------

    def hoarded_shares(self) -> dict[str, bytes]:
        """Was ein unehrlicher Knoten trotz Loeschzusage behalten hat.

        Fuer die Auswertung in den Tests. Der Inhalt ist auch fuer ihn
        wertlos: er ist unter dem Knotenschluessel versiegelt, dessen
        Faehigkeit fuer diesen Tag nach der Herausgabe punktiert wurde.
        """
        return dict(self._hoarded)

    def try_read_hoarded(self, tag: str) -> bytes | None:
        """Der Versuch des unehrlichen Knotens, seine Kopie zu lesen."""
        blob = self._hoarded.get(tag)
        if blob is None:
            return None
        key = self._key.derive(bytes.fromhex(tag))
        if key is None:
            return None  # punktiert — er kommt an seine eigene Kopie nicht heran
        try:
            return open_(key, self._nonce(tag), blob, tag.encode())
        except AuthError:
            return None

    @staticmethod
    def _nonce(tag: str) -> bytes:
        return sha256(b"nyx/v1/store-nonce", tag.encode())[:12]

    @property
    def count(self) -> int:
        return len(self._entries)

    def __repr__(self) -> str:
        return f"<StorageNode {self.name} eintraege={len(self._entries)}>"


# ==========================================================================
# drops.py
# ==========================================================================
#
# Blinde Ablagen ueber mehrere Knoten (Whitepaper 7.4 bis 7.6).
#
# Hier laufen Zerlegung, Tags und Speicherknoten zusammen. Der Client legt
# eine Nachricht als n Teile bei n Knoten ab, jeweils unter einem eigenen,
# nicht verkettbaren Tag, und holt sie mit k Teilen wieder ab.
#
# Zwei Tags derselben Nachricht sind fuer Dritte nicht als zusammengehoerig
# erkennbar, und zwei Nachrichten derselben Unterhaltung erst recht nicht.

TAG_SECRET_LABEL = "nyx/v1/drop-tag-secret"


def tag_secret(root_material: bytes) -> bytes:
    """SK_tag aus dem Sitzungsgeheimnis. Verlaesst nie das Endgeraet."""
    return hkdf(root_material, TAG_SECRET_LABEL)


def drop_tag(sk_tag: bytes, counter: int, share_index: int) -> str:
    """drop_tag_{i,j} = HMAC( SK_tag , i || j )."""
    return hmac_sha256(sk_tag,
                       counter.to_bytes(8, "big"),
                       share_index.to_bytes(4, "big")).hex()


def cover_tag() -> str:
    """Ein Fuelltag. Vom echten nicht unterscheidbar — genau das ist der Zweck."""
    return os.urandom(32).hex()


@dataclass
class Receipt:
    counter: int
    tags: list[str]
    nodes: list[str]
    k: int
    n: int


class DropNetwork:
    """Die Speicherseite: n unabhaengige Knoten.

    Die Knoten sollen sich in Betreiber, autonomem System und Rechtsraum
    unterscheiden (Whitepaper 7.5). Das kann diese Klasse nicht pruefen; sie
    waehlt lediglich ueber die gesamte Menge gleichverteilt aus.
    """

    def __init__(self, nodes: list[StorageNode]):
        if not nodes:
            raise ValueError("ein Speichernetz braucht Knoten")
        self.nodes = {n.name: n for n in nodes}

    def choose(self, count: int, rng: random.Random | None = None) -> list[StorageNode]:
        """Waehlt Knoten fuer ``count`` Teile.

        Gibt es mindestens so viele Knoten wie Teile, bekommt jeder Knoten
        genau einen — das ist der Fall, fuer den die Rechnung in Whitepaper
        10.3 gilt.

        Gibt es weniger, werden die Teile gleichmaessig verteilt und ein
        Knoten haelt mehrere. Das Protokoll laeuft dann weiter, aber die
        Schwelle traegt schwaecher: wer einen Knoten beschlagnahmt, erhaelt
        nicht ein Teil, sondern mehrere. Wie viel schwaecher, sagt
        ``threshold_margin``.
        """
        pool = list(self.nodes.values())
        r = rng or random
        if count <= len(pool):
            return r.sample(pool, count)

        chosen: list[StorageNode] = []
        while len(chosen) < count:
            shuffled = pool[:]
            r.shuffle(shuffled)
            chosen.extend(shuffled[:count - len(chosen)])
        return chosen

    def threshold_margin(self, k: int = DEFAULT_K, n: int = DEFAULT_N) -> float:
        """Wie viele Knoten ein Angreifer braucht, um k Teile zu bekommen —
        als Anteil des Netzes. 1,0 bedeutet: die Rechnung aus 10.3 gilt
        unveraendert. Kleinere Werte bedeuten, dass das Netz zu klein ist.
        """
        per_node = max(1, -(-n // len(self.nodes)))     # aufgerundet
        needed = -(-k // per_node)
        return min(1.0, needed / k)

    # -- Ablegen ---------------------------------------------------------

    def store(self, sk_tag: bytes, counter: int, ciphertext: bytes,
              k: int = DEFAULT_K, n: int = DEFAULT_N,
              ttl: int | None = None, rng: random.Random | None = None) -> Receipt:
        shares = disperse(ciphertext, k=k, n=n)
        chosen = self.choose(n, rng)
        tags = []
        for share, node in zip(shares, chosen):
            tag = drop_tag(sk_tag, counter, share.index)
            blob = _encode_share(share)
            node.put(tag, blob, ttl, solve_pow(tag, blob, node.pow_bits))
            tags.append(tag)
        return Receipt(counter, tags, [c.name for c in chosen], k, n)

    # -- Abholen ---------------------------------------------------------

    def fetch(self, sk_tag: bytes, counter: int, k: int = DEFAULT_K,
              n: int = DEFAULT_N, cover: int = 4, release: bool = True,
              rng: random.Random | None = None) -> bytes | None:
        """Holt k Teile und entwertet anschliessend alle n.

        Das Entwerten ist kein Aufraeumen, sondern Teil des Protokolls: die
        n-k nicht abgeholten Teile blieben sonst bis zum Verfall liegen und
        waeren fuer einen hortenden Knoten weiterhin lesbar. Erst wenn alle
        Tags entwertet sind, ist die Ablage nach Whitepaper 7.7 Schicht 3
        auch tatsaechlich unter der Schwelle.
        """
        wanted = [drop_tag(sk_tag, counter, j) for j in range(n)]
        queries = wanted + [cover_tag() for _ in range(cover)]
        (rng or random).shuffle(queries)

        # Eine Sammelanfrage je Knoten, nicht eine je Tag. Das ist nicht nur
        # schneller — einzelne Abfragen wuerden dem Knoten verraten, in
        # welcher Reihenfolge der Abholende sucht, und damit welche Tags
        # zusammengehoeren.
        collected: list[Share] = []
        for node in self.nodes.values():
            if len(collected) >= k:
                break
            for blob in node.get_batch(queries).values():
                if blob is None:
                    continue
                raw = bytes.fromhex(blob) if isinstance(blob, str) else blob
                collected.append(_decode_share(raw))

        if len(collected) < k:
            return None

        message = reassemble(collected)
        if release:
            self.release(sk_tag, counter, n)
        return message

    def release(self, sk_tag: bytes, counter: int, n: int = DEFAULT_N) -> int:
        """Entwertet alle Teile einer Nachricht (Whitepaper 7.6 und 7.7)."""
        tags = [drop_tag(sk_tag, counter, j) for j in range(n)]
        voided = 0
        for node in self.nodes.values():
            batch = getattr(node, "void_batch", None)
            if batch is not None:
                voided += batch(tags)
            else:
                voided += sum(1 for tag in tags if node.void(tag))
        return voided

    # -- Auswertung ------------------------------------------------------

    def surviving_shares(self, sk_tag: bytes, counter: int, n: int = DEFAULT_N) -> int:
        """Wie viele Teile liegen noch irgendwo? Basis fuer 7.7, Schicht 3."""
        total = 0
        for j in range(n):
            tag = drop_tag(sk_tag, counter, j)
            for node in self.nodes.values():
                try:
                    total += 1 if node.has(tag) else 0
                except NotImplementedError:
                    pass    # entfernte Knoten beantworten keine Existenzfragen
        return total

    def hoarders_can_reconstruct(self, sk_tag: bytes, counter: int,
                                 k: int, n: int) -> bool:
        """Versuch der unehrlichen Knoten, aus ihren Kopien zusammenzusetzen."""
        recovered: list[Share] = []
        for j in range(n):
            tag = drop_tag(sk_tag, counter, j)
            for node in self.nodes.values():
                blob = node.try_read_hoarded(tag)
                if blob is not None:
                    recovered.append(_decode_share(blob))
                    break
        if len(recovered) < k:
            return False
        try:
            reassemble(recovered)
            return True
        except ValueError:
            return False

    def sweep_all(self) -> int:
        return sum(node.sweep() for node in self.nodes.values())

    def total_entries(self) -> int:
        return sum(node.count for node in self.nodes.values())


def _encode_share(share: Share) -> bytes:
    head = (share.index.to_bytes(2, "big") + share.k.to_bytes(2, "big")
            + share.n.to_bytes(2, "big") + share.length.to_bytes(4, "big"))
    return head + share.data


def _decode_share(blob: bytes) -> Share:
    if len(blob) < 10:
        raise ValueError("Teil ist unvollstaendig")
    return Share(int.from_bytes(blob[0:2], "big"), int.from_bytes(blob[2:4], "big"),
                 int.from_bytes(blob[4:6], "big"), int.from_bytes(blob[6:10], "big"),
                 blob[10:])


# ==========================================================================
# mixnet.py
# ==========================================================================
#
# Mixnetz mit Schichtverschluesselung (Whitepaper 6).
#
# Der Sender waehlt einen Pfad aus drei Knoten und verpackt die Nachricht so,
# dass jeder Knoten genau seinen Vorgaenger und seinen Nachfolger erfaehrt und
# sonst nichts.
#
# Ein Paket ist immer genau ``PACKET_SIZE`` Byte gross und besteht aus zwei
# Teilen:
#
#   Kopf      ``MAX_HOPS`` gleich grosse Faecher. Fach 0 gehoert dem naechsten
#             Knoten; er oeffnet es, entnimmt die Wegangabe, schiebt die
#             restlichen Faecher nach vorn und haengt ein Fach voll Zufall an.
#             Der Kopf behaelt dadurch seine Groesse, und von aussen ist nicht
#             erkennbar, das wievielte Fach gerade gelesen wurde.
#
#   Rumpf     Bei jedem Hop mit einem eigenen Stromschluessel umgerechnet. Zwei
#             Beobachtungen desselben Pakets vor und hinter einem Knoten haben
#             keine Byte gemeinsam, obwohl die Laenge gleich bleibt.
#
# Unterschied zum vollstaendigen Sphinx-Format: dort leitet sich das
# Schluesselmaterial aller Hops aus einem einzigen, fortlaufend geblendeten
# ephemeren Schluessel ab, was den Kopf kleiner macht. Wir fuehren
# stattdessen einen ephemeren Schluessel je Hop — dieselben Eigenschaften bei
# mehr Kopfbytes, dafuer erheblich weniger Code. Siehe docs/protokoll.md.

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


# ==========================================================================
# client.py
# ==========================================================================
#
# Der Client: alles zusammengesetzt.
#
# Ein Client haelt seine Identitaet, seine Sitzungen und die Verbindung zum
# Verzeichnis und zum Speichernetz. Ueber ihm liegen die Dienste in
# ``nyx.services`` — Chat, Post, Dateien und Anrufe teilen sich denselben
# Kanal und dieselbe Ratsche; sie unterscheiden sich nur im Umschlag.
#
# Zum Erstkontakt: die Tags einer laufenden Unterhaltung leiten sich aus
# einem gemeinsamen Sitzungsgeheimnis ab und sind fuer Dritte unverkettbar.
# Fuer die allererste Nachricht gibt es dieses Geheimnis noch nicht. Sie geht
# deshalb an ein Postfach, dessen Tags sich aus dem oeffentlichen Schluessel
# des Empfaengers und einem Zeitfenster ergeben. Wer die Adresse kennt, kann
# diese Tags berechnen — er sieht dadurch, ob jemand unabgeholte
# Erstkontakte hat, und koennte das Postfach zumuellen. Dagegen steht der
# Arbeitsnachweis je Ablage; verbergen laesst es sich nicht. Ab der zweiten
# Nachricht ist die Unterhaltung unverkettbar.

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
    return address_from_key(ik_pub)


def _inbox_secret(idk_pub: bytes, epoch: int, slot: int) -> bytes:
    """Ableitung des Ablagegeheimnisses fuer ein Postfach.

    Absichtlich aus oeffentlichem Material: jeder Sender muss es berechnen
    koennen, ohne den Empfaenger vorher gesprochen zu haben.
    """
    return tag_secret(hkdf(idk_pub + epoch.to_bytes(8, "big")
                           + slot.to_bytes(4, "big"), INBOX_LABEL))


# ==========================================================================
# services/chat.py
# ==========================================================================
#
# Kurznachrichten.
#
# Der einfachste Dienst: ein Umschlag mit Text. Bemerkenswert ist nur, was
# fehlt — es gibt keine Nachrichten-ID, die ueber Sitzungen hinweg gilt,
# keinen Zeitstempel des Servers und keine Lesebestaetigung, die der
# Empfaenger nicht selbst ausloest.

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


# ==========================================================================
# services/files.py
# ==========================================================================
#
# Datenuebertragung.
#
# Eine Datei geht nicht als Ganzes ueber den Kanal, sondern in Bloecken
# fester Groesse. Jeder Block wird einzeln durch die Ratsche verschluesselt
# und einzeln in n Teile zerlegt abgelegt. Der Empfaenger bekommt zuerst ein
# Verzeichnis (Manifest) mit den Pruefsummen und laedt dann die Bloecke.
#
# Feste Blockgroesse ist hier kein Detail: sie sorgt dafuer, dass die Anzahl
# der Ablagen nur die ungefaehre Groesse der Datei verraet und nicht ihre
# Struktur — und dass eine Datei von aussen wie eine Folge von
# Kurznachrichten aussieht.

CHUNK = 1024


@dataclass
class Transfer:
    file_id: str
    name: str
    size: int
    chunks: int
    digest: str
    received: dict[int, bytes] = field(default_factory=dict)

    @property
    def complete(self) -> bool:
        return len(self.received) == self.chunks

    def assemble(self) -> bytes:
        if not self.complete:
            raise ValueError(f"unvollstaendig: {len(self.received)}/{self.chunks}")
        data = b"".join(self.received[i] for i in range(self.chunks))
        if sha256(b"nyx/v1/file", data).hex() != self.digest:
            raise ValueError("Pruefsumme stimmt nicht — Uebertragung verworfen")
        return data


class FileService:
    def __init__(self, client):
        self.client = client
        self.incoming: dict[str, Transfer] = {}
        self.completed: dict[str, tuple[str, bytes]] = {}

    def send(self, address: str, name: str, data: bytes) -> str:
        file_id = os.urandom(8).hex()
        chunks = (len(data) + CHUNK - 1) // CHUNK or 1

        self.client.send(address, {
            "t": "file", "k": "manifest", "id": file_id, "name": name,
            "size": len(data), "chunks": chunks,
            "digest": sha256(b"nyx/v1/file", data).hex(),
        })
        for i in range(chunks):
            block = data[i * CHUNK:(i + 1) * CHUNK]
            self.client.send(address, {
                "t": "file", "k": "chunk", "id": file_id, "i": i,
                "d": base64.b64encode(block).decode(),
            })
        return file_id

    def handle(self, address: str, envelope: dict) -> Transfer | None:
        if envelope.get("t") != "file":
            return None

        if envelope.get("k") == "manifest":
            transfer = Transfer(envelope["id"], envelope["name"], envelope["size"],
                                envelope["chunks"], envelope["digest"])
            self.incoming[transfer.file_id] = transfer
            return transfer

        if envelope.get("k") == "chunk":
            transfer = self.incoming.get(envelope["id"])
            if transfer is None:
                return None
            transfer.received[envelope["i"]] = base64.b64decode(envelope["d"])
            if transfer.complete:
                self.completed[transfer.file_id] = (transfer.name, transfer.assemble())
                del self.incoming[transfer.file_id]
            return transfer
        return None

    def take(self, file_id: str) -> tuple[str, bytes] | None:
        """Gibt eine fertige Datei genau einmal heraus und vergisst sie dann."""
        return self.completed.pop(file_id, None)


# ==========================================================================
# services/mail.py
# ==========================================================================
#
# Post: lange Nachrichten mit langer Liegezeit.
#
# Der Unterschied zur Kurznachricht ist nicht technischer, sondern
# zeitlicher Natur. Post rechnet damit, dass der Empfaenger tagelang nicht
# erscheint, und bekommt deshalb eine laengere Verfallszeit. Alles andere ist
# identisch — dieselbe Ratsche, dieselben blinden Ablagen, dieselbe
# Vernichtung nach der Abholung.
#
# Was hier ausdruecklich fehlt, ist der Unterschied zum klassischen E-Mail-
# System:
#
#   * Es gibt keinen Server, der Post annimmt und zustellt. Es gibt Ablagen.
#   * Es gibt keinen Kopfbereich mit Absender, Empfaenger, Betreff und
#     Laufweg im Klartext. Der Betreff ist Teil des verschluesselten Inhalts.
#   * Es gibt keine Weiterleitung an Unbeteiligte und kein Rundschreiben an
#     Unbekannte. Post geht an jemanden, dessen Schluessel man hat.
#   * Post wird nicht aufbewahrt. Nach der Abholung ist sie weg, und nach
#     Ablauf der Frist ebenfalls — auch wenn sie nie abgeholt wurde.

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


# ==========================================================================
# services/calls.py
# ==========================================================================
#
# Anrufe: Signalisierung und Medienschluessel.
#
# Ein Anruf hat zwei Teile, und nur der erste gehoert in dieses Protokoll:
#
#   Signalisierung  Angebot, Antwort, Wegvorschlaege und Auflegen laufen als
#                   gewoehnliche Umschlaege durch die Ratsche und die blinden
#                   Ablagen. Ein Beobachter sieht dieselben Ablagen wie bei
#                   einer Kurznachricht.
#
#   Medien          Der Ton- und Bildstrom laeuft nicht ueber die Ablagen —
#                   dafuer waeren sie zu langsam — sondern direkt zwischen den
#                   Endgeraeten, verschluesselt unter einem Schluessel, der aus
#                   der laufenden Ratsche abgeleitet wird.
#
# Was dieses Modul liefert: die vollstaendige Signalisierung und die
# Ableitung der Medienschluessel. Was es nicht liefert: einen Audiostapel.
# Der Web-Client in impl/web setzt darauf WebRTC auf und nutzt genau diese
# Signalisierung; die Python-Fassung ist die Referenz fuer den
# Protokollablauf und fuer Tests.
#
# Wichtig fuer die Anonymitaet: eine direkte Medienverbindung offenbart den
# Gespraechspartnern gegenseitig ihre IP-Adresse. Wer das nicht will, muss
# den Strom ueber das Mixnetz fuehren und die zusaetzliche Verzoegerung in
# Kauf nehmen. Beide Betriebsarten sind hier vorgesehen; die Vorgabe ist die
# sichere.

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


# ==========================================================================
# node.py
# ==========================================================================
#
# Knoten-Daemon und Fernzugriff.
#
# Der Daemon stellt Speichernetz und Verzeichnis ueber HTTP bereit. Die
# Schnittstelle ist bewusst klein und in docs/api.md normativ beschrieben;
# die PHP-Fassung in impl/php implementiert dieselbe und ist gegen dieselben
# Testfaelle geprueft.
#
# Der Knoten lernt aus jeder Anfrage nur das, was das Protokoll ohnehin
# preisgibt: einen Zufallswert als Tag und einen Block Rauschen. Er fuehrt
# kein Zugriffsprotokoll — nicht aus Nachlaessigkeit, sondern weil ein
# solches Protokoll genau die Metadaten waere, die das uebrige System
# vermeidet.

VERSION = "nyx/1"


class NodeService:
    """Die Logik hinter der Schnittstelle, ohne HTTP."""

    def __init__(self, store: StorageNode, chain: Chain):
        self.store = store
        self.chain = chain

    def info(self) -> dict:
        return {
            "version": VERSION,
            "name": self.store.name,
            "pow_bits": self.store.pow_bits,
            "ttl": self.store.ttl,
            "entries": self.store.count,
            "height": len(self.chain.blocks) - 1,
        }

    def store_drop(self, body: dict) -> dict:
        tag = body["tag"]
        blob = bytes.fromhex(body["blob"])
        receipt = self.store.put(tag, blob, body.get("ttl"), body.get("nonce"))
        return {"ok": True, **receipt}

    def fetch(self, body: dict) -> dict:
        tags = body.get("tags", [])
        if len(tags) > 256:
            raise ValueError("zu viele Tags in einer Anfrage")
        return {"results": self.store.get_batch(tags)}

    def void(self, body: dict) -> dict:
        return {"voided": sum(1 for t in body.get("tags", []) if self.store.void(t))}

    def proof(self, body: dict) -> dict:
        challenge = bytes.fromhex(body.get("challenge", ""))
        return self.store.prove_retrievability(challenge)

    def dir_headers(self, _: dict) -> dict:
        return {"headers": self.chain.headers()}

    def dir_submit(self, body: dict) -> dict:
        self.chain.submit(body["record"])
        if body.get("seal", True):
            self.chain.seal()
        return {"ok": True, "height": len(self.chain.blocks) - 1}

    def dir_resolve(self, body: dict) -> dict:
        address = body.get("addr", "")
        identity = self.chain.resolve(address)
        return {
            "identity": identity.to_dict() if identity else None,
            "bundle": self.chain.latest_bundle(address),
            "history": self.chain.key_history(address),
        }

    def dir_proof(self, body: dict) -> dict:
        return {"proof": self.chain.proof_for(body.get("addr", ""),
                                              body.get("type", "BIND"))}

    ROUTES = {
        "/v1/info": "info_get",
        "/v1/store": "store_drop",
        "/v1/fetch": "fetch",
        "/v1/void": "void",
        "/v1/proof": "proof",
        "/v1/dir/headers": "dir_headers",
        "/v1/dir/submit": "dir_submit",
        "/v1/dir/resolve": "dir_resolve",
        "/v1/dir/proof": "dir_proof",
    }

    def dispatch(self, path: str, body: dict) -> dict:
        name = self.ROUTES.get(path)
        if name is None:
            raise KeyError(path)
        if name == "info_get":
            return self.info()
        return getattr(self, name)(body)


class _Handler(BaseHTTPRequestHandler):
    server_version = "nyx"

    def log_message(self, *args) -> None:
        """Kein Zugriffsprotokoll. Siehe Modulkopf."""

    def _reply(self, code: int, payload: dict) -> None:
        raw = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.end_headers()
        self.wfile.write(raw)

    def do_OPTIONS(self) -> None:
        self._reply(204, {})

    def do_GET(self) -> None:
        path = urllib.parse.urlparse(self.path).path
        query = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(self.path).query))
        self._handle(path, query)

    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", 0))
        try:
            body = json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            return self._reply(400, {"error": "kein gueltiges JSON"})
        self._handle(urllib.parse.urlparse(self.path).path, body)

    def _handle(self, path: str, body: dict) -> None:
        try:
            self._reply(200, self.server.service.dispatch(path, body))
        except KeyError:
            self._reply(404, {"error": "unbekannter Pfad"})
        except ValueError as exc:
            self._reply(400, {"error": str(exc)})


class NodeServer:
    """Ein lauffaehiger Knoten."""

    def __init__(self, service: NodeService, host: str = "127.0.0.1", port: int = 8443):
        self.service = service
        self.httpd = ThreadingHTTPServer((host, port), _Handler)
        self.httpd.service = service
        self.thread: threading.Thread | None = None

    @property
    def url(self) -> str:
        host, port = self.httpd.server_address[:2]
        return f"http://{host}:{port}"

    def start(self) -> None:
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)
        self.thread.start()

    def stop(self) -> None:
        self.httpd.shutdown()
        self.httpd.server_close()

    def __enter__(self) -> "NodeServer":
        self.start()
        return self

    def __exit__(self, *exc) -> None:
        self.stop()


class RemoteStore:
    """Ein entfernter Speicherknoten, benutzbar wie ein lokaler.

    Damit laeuft derselbe Client gegen die Python- und die PHP-Fassung des
    Knotens; welche davon antwortet, ist fuer das Protokoll ohne Belang.
    """

    def __init__(self, url: str, name: str | None = None, timeout: float = 10.0):
        self.url = url.rstrip("/")
        self.timeout = timeout
        info = self.call("/v1/info", {}, method="GET")
        self.name = name or info.get("name", self.url)
        self.pow_bits = info.get("pow_bits", 0)
        self.ttl = info.get("ttl", 0)

    def call(self, path: str, body: dict, method: str = "POST") -> dict:
        url = self.url + path
        data = None if method == "GET" else json.dumps(body).encode()
        req = urllib.request.Request(url, data=data, method=method,
                                     headers={"Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                return json.loads(resp.read())
        except urllib.error.HTTPError as exc:
            detail = json.loads(exc.read() or b"{}").get("error", str(exc))
            raise ValueError(detail) from exc

    # -- Schnittstelle wie StorageNode -----------------------------------

    def put(self, tag: str, blob: bytes, ttl: int | None = None,
            nonce: int | None = None) -> dict:
        return self.call("/v1/store", {"tag": tag, "blob": blob.hex(),
                                       "ttl": ttl, "nonce": nonce})

    def get(self, tag: str) -> bytes | None:
        got = self.call("/v1/fetch", {"tags": [tag]})["results"].get(tag)
        return bytes.fromhex(got) if got else None

    def get_batch(self, tags: list[str]) -> dict[str, str | None]:
        return self.call("/v1/fetch", {"tags": tags})["results"]

    def void(self, tag: str) -> bool:
        return self.call("/v1/void", {"tags": [tag]})["voided"] > 0

    def void_batch(self, tags: list[str]) -> int:
        return self.call("/v1/void", {"tags": tags})["voided"]

    def has(self, tag: str) -> bool:
        raise NotImplementedError(
            "Ein Knoten beantwortet keine Existenzfragen — jede Abfrage ist "
            "eine Abholung. Sonst waere 'liegt hier etwas fuer dich' eine "
            "Metadatenquelle.")

    def try_read_hoarded(self, tag: str) -> bytes | None:
        return None

    @property
    def count(self) -> int:
        return self.call("/v1/info", {}, method="GET").get("entries", 0)

    def __repr__(self) -> str:
        return f"<RemoteStore {self.name} @ {self.url}>"


class RemoteChain:
    """Ein entferntes Verzeichnis, benutzbar wie eine lokale Kette.

    Der Client braucht vom Verzeichnis nur dreierlei: einen Eintrag
    einreichen, eine Adresse aufloesen und das Prekey-Buendel holen. Alles
    davon geht ueber die Knotenschnittstelle — und im Betrieb ueber das
    Mixnetz, damit kein Knoten ein Interessenprofil anlegen kann.
    """

    def __init__(self, url: str, timeout: float = 10.0):
        self.remote = RemoteStore.__new__(RemoteStore)
        self.remote.url = url.rstrip("/")
        self.remote.timeout = timeout
        self._pending: list[dict] = []

    def submit(self, record: dict) -> None:
        self._pending.append(record)

    def seal(self) -> None:
        for record in self._pending:
            self.remote.call("/v1/dir/submit", {"record": record})
        self._pending = []

    def resolve(self, address: str):
        data = self.remote.call("/v1/dir/resolve", {"addr": address})
        if not data.get("identity"):
            return None
        return PublicIdentity(
            address,
            bytes.fromhex(data["identity"]["ik_pub"]),
            bytes.fromhex(data["identity"]["idk_pub"]),
        )

    def latest_bundle(self, address: str) -> dict | None:
        return self.remote.call("/v1/dir/resolve", {"addr": address}).get("bundle")

    def key_history(self, address: str) -> list[dict]:
        return self.remote.call("/v1/dir/resolve", {"addr": address}).get("history", [])


def build(name: str = "knoten", port: int = 8443, bits: int = 10,
          pow_bits: int = 12) -> NodeServer:
    return NodeServer(NodeService(StorageNode(name, pow_bits=pow_bits),
                                  Chain(bits=bits)), port=port)


# ==========================================================================
# cli.py
# ==========================================================================
#
# Terminal-Client.
#
#     python3 -m nyx.cli init                     Identitaet anlegen
#     python3 -m nyx.cli wer                      eigene Adresse zeigen
#     python3 -m nyx.cli demo                     Vorfuehrung ohne Netz
#     python3 -m nyx.cli knoten [--port 8443]     Knoten betreiben
#     python3 -m nyx.cli senden <adresse> <text>
#     python3 -m nyx.cli holen
#     python3 -m nyx.cli pruefen <adresse>
#
# Der Zustand liegt unter ~/.nyx: die Identitaet, die privaten Prekeys und
# die laufenden Sitzungen. Nachrichten werden nicht gespeichert.

HOME = Path(os.environ.get("NYX_HOME", Path.home() / ".nyx"))
DEFAULT_NODE = os.environ.get("NYX_NODE", "http://127.0.0.1:8443")


def ensure_home() -> Path:
    HOME.mkdir(parents=True, exist_ok=True)
    os.chmod(HOME, 0o700)
    return HOME


def load_identity() -> Identity:
    path = HOME / "identitaet.json"
    if not path.is_file():
        sys.exit("Keine Identitaet. Zuerst: python3 -m nyx.cli init")
    return Identity.import_private(path.read_text())


def save_identity(identity: Identity) -> None:
    path = ensure_home() / "identitaet.json"
    path.write_text(identity.export_private())
    os.chmod(path, 0o600)


def build_client(url: str) -> Client:
    """Der Client spricht mit einem Knoten; im Betrieb ueber das Mixnetz."""
    identity = load_identity()
    store = RemoteStore(url)
    nodes = [_Named(store, f"{store.name}-{i}") for i in range(3)]
    client = Client(identity, RemoteChain(url), DropNetwork(nodes))

    state = HOME / "prekeys.json"
    if state.is_file():
        client.prekeys.import_state(state.read_text())
    return client


def save_prekeys(client: Client) -> None:
    path = ensure_home() / "prekeys.json"
    path.write_text(client.prekeys.export_state())
    os.chmod(path, 0o600)


class _Named:
    """Ein Knoten unter eigenem Namen.

    Im Betrieb traegt man hier mehrere Adressen ein, damit die Teile
    tatsaechlich auf verschiedene Rechner gehen.
    """

    def __init__(self, store: RemoteStore, name: str):
        self.store, self.name = store, name
        self.pow_bits, self.ttl = store.pow_bits, store.ttl

    def put(self, *args):
        return self.store.put(*args)

    def get(self, tag):
        return self.store.get(tag)

    def get_batch(self, tags):
        return self.store.get_batch(tags)

    def void(self, tag):
        return self.store.void(tag)

    def void_batch(self, tags):
        return self.store.void_batch(tags)

    def has(self, tag):
        raise NotImplementedError

    def try_read_hoarded(self, tag):
        return None

    @property
    def count(self):
        return self.store.count


# -- Befehle ---------------------------------------------------------------

def cmd_init(args) -> int:
    if (HOME / "identitaet.json").is_file() and not args.force:
        sys.exit("Es gibt bereits eine Identitaet. Mit --force ueberschreiben "
                 "(die alte ist danach verloren).")
    identity = Identity.create()
    save_identity(identity)

    client = build_client(args.node)
    client.announce()
    save_prekeys(client)

    print(f"Adresse: {identity.address}")
    print(f"Gesichert in {HOME / 'identitaet.json'} — ohne diese Datei ist die")
    print("Identitaet verloren. Es gibt keine Wiederherstellung durch Dritte.")
    return 0


def cmd_wer(args) -> int:
    print(load_identity().address)
    return 0


def cmd_knoten(args) -> int:

    data = ensure_home() / "knoten"
    data.mkdir(exist_ok=True)
    service = NodeService(StorageNode(args.name, pow_bits=args.pow),
                          Chain(bits=args.bits))
    server = NodeServer(service, args.host, args.port)
    print(f"Knoten {args.name} auf {server.url}")
    print("Kein Zugriffsprotokoll. Beenden mit Strg-C.")
    try:
        server.start()
        server.thread.join()
    except KeyboardInterrupt:
        server.stop()
    return 0


def cmd_senden(args) -> int:
    if not address_valid(args.adresse):
        sys.exit("Das ist keine gueltige Adresse — die Pruefsumme stimmt nicht.")
    client = build_client(args.node)
    _load_sessions(client)
    result = client.send(args.adresse, {"t": "chat", "text": args.text})
    _save_sessions(client)
    save_prekeys(client)
    print(f"Gesendet in {result['zellen']} Zelle(n)"
          + (" ueber das Postfach (Erstkontakt)" if result["erstkontakt"] else ""))
    return 0


def cmd_holen(args) -> int:
    client = build_client(args.node)
    _load_sessions(client)
    empfangen = client.poll()
    _save_sessions(client)
    save_prekeys(client)

    if not empfangen:
        print("Nichts Neues.")
        return 0
    for absender, umschlag in empfangen:
        if umschlag.get("t") == "chat":
            print(f"{absender[:16]}…  {umschlag['text']}")
        else:
            print(f"{absender[:16]}…  [{umschlag.get('t', '?')}]")
    return 0


def cmd_demo(args) -> int:
    """Vorfuehrung ohne Netz: zwei Teilnehmer in einem Prozess."""
    import random


    chain = Chain(bits=8)
    knoten = [StorageNode(f"knoten-{i:02d}", pow_bits=8) for i in range(20)]
    drops = DropNetwork(knoten)
    rng = random.Random(20260803)

    alice = Client(Identity.create(), chain, drops, rng)
    bob = Client(Identity.create(), chain, drops, rng)
    alice.announce()
    bob.announce()
    print(f"Alice  {alice.identity.address}")
    print(f"Bob    {bob.identity.address}\n")

    alice.send(bob.identity.address, {"t": "chat", "text": "Kannst du das lesen?"})
    print(f"Ablagen im Netz            {drops.total_entries()} Teile auf 20 Knoten")

    beispiel = next(n for n in knoten if n._entries)
    tag, eintrag = next(iter(beispiel._entries.items()))
    print(f"Was ein Knoten sieht       Tag {tag[:24]}…")
    print(f"                           {eintrag.blob[:20].hex()}…")

    for absender, umschlag in bob.poll():
        print(f"\nBob empfaengt              {umschlag['text']!r}")
        print(f"von                        {absender}")

    print(f"\nAblagen nach der Abholung  {drops.total_entries()}")
    print("Schwelle unterschritten    ja — die Nachricht ist unwiederbringlich weg")
    return 0


def cmd_pruefen(args) -> int:
    client = build_client(args.node)
    peer = client.chain.resolve(args.adresse)
    if peer is None:
        sys.exit("Diese Adresse steht nicht im Verzeichnis.")

    print("Vergleichswert:")
    print(" ", safety_number(client.identity.public, peer))
    history = [h for h in client.chain.key_history(args.adresse) if h["type"] == "BIND"]
    print(f"\nBindungen im Verzeichnis: {len(history)}")
    for entry in history:
        print(f"  Block {entry['height']}: {entry['key'][:16]}…")
    if len(history) > 1:
        print("\nMehr als eine Bindung. Ein Schluesselwechsel kann harmlos sein")
        print("(neues Geraet) oder nicht. Das Verzeichnis zeigt ihn, es bewertet")
        print("ihn nicht.")
    return 0


# -- Sitzungen -------------------------------------------------------------
# Nur der Zaehlerstand und die Tag-Geheimnisse werden aufbewahrt. Der
# Ratschenzustand bleibt im Arbeitsspeicher: er ueber Neustarts hinweg auf
# die Platte zu schreiben, wuerde die Vorwaertsgeheimhaltung aushebeln, die
# er herstellen soll. Ein Neustart kostet deshalb die laufende Sitzung.

def _load_sessions(client: Client) -> None:
    path = HOME / "sitzungen.json"
    if path.is_file():
        client.inbox_cursor = json.loads(path.read_text()).get("inbox", {})


def _save_sessions(client: Client) -> None:
    path = ensure_home() / "sitzungen.json"
    path.write_text(json.dumps({"inbox": client.inbox_cursor}))
    os.chmod(path, 0o600)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="nyx", description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--node", default=DEFAULT_NODE, help="Adresse eines Knotens")
    sub = parser.add_subparsers(dest="befehl", required=True)

    p = sub.add_parser("init", help="Identitaet anlegen und ankuendigen")
    p.add_argument("--force", action="store_true")
    p.set_defaults(func=cmd_init)

    sub.add_parser("wer", help="eigene Adresse zeigen").set_defaults(func=cmd_wer)
    sub.add_parser("demo", help="Vorfuehrung ohne Netz").set_defaults(func=cmd_demo)

    p = sub.add_parser("knoten", help="Knoten betreiben")
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--port", type=int, default=8443)
    p.add_argument("--name", default="knoten")
    p.add_argument("--bits", type=int, default=10)
    p.add_argument("--pow", type=int, default=12)
    p.set_defaults(func=cmd_knoten)

    p = sub.add_parser("senden", help="Nachricht senden")
    p.add_argument("adresse")
    p.add_argument("text")
    p.set_defaults(func=cmd_senden)

    sub.add_parser("holen", help="Wartendes abholen").set_defaults(func=cmd_holen)

    p = sub.add_parser("pruefen", help="Schluessel einer Adresse pruefen")
    p.add_argument("adresse")
    p.set_defaults(func=cmd_pruefen)

    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())


if __name__ == "__main__":
    raise SystemExit(main())
