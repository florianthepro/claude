"""X25519 (RFC 7748) und Ed25519 (RFC 8032) in reinem Python.

Diese Implementierung existiert, damit die Referenzimplementierung ohne
Fremdpakete laeuft und gegen PHP/libsodium und WebCrypto nachweisbar
interoperabel ist.

WARNUNG: Der Code ist nicht laufzeitkonstant. Python-Integer-Arithmetik
verraet ueber Zeit- und Cache-Verhalten Informationen ueber die Schluessel.
Fuer den Produktivbetrieb ist er durch libsodium zu ersetzen; die
Schnittstelle ist dafuer absichtlich schmal gehalten.
"""

from __future__ import annotations

import hashlib
import os

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
