"""ChaCha20-Poly1305 (RFC 8439) in reinem Python.

Gleiche Vorbehalte wie in curve25519.py: nicht laufzeitkonstant, fuer den
Produktivbetrieb durch libsodium zu ersetzen. Die Byte-Ebene ist identisch
zu ``sodium_crypto_aead_chacha20poly1305_ietf_*`` (PHP) und zu den
gaengigen Browser-Implementierungen, damit alle drei Clients dieselben
Chiffrate lesen.
"""

from __future__ import annotations

import struct

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
