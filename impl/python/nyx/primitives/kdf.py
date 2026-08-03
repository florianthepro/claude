"""Ableitungsfunktionen und Schluesselvernichtung.

SHA-256 ist hier bewusst gewaehlt: es ist die Schnittmenge dessen, was die
Python-Standardbibliothek, libsodium/PHP und die WebCrypto-API des Browsers
alle ohne Fremdcode koennen. Damit kommen alle drei Clients auf dieselben
Bytes.

Jede Ableitung im Protokoll traegt ein eigenes Label. Zwei Ableitungen aus
demselben Eingangsmaterial ergeben dadurch nie denselben Schluessel.
"""

from __future__ import annotations

import ctypes
import hashlib
import hmac

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
