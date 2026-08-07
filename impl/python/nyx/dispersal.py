"""Zerlegung in k-von-n Teile (Whitepaper 7.3).

Zwei Schritte, und die Reihenfolge ist wesentlich:

1. Eine Alles-oder-nichts-Transformation (AONT, nach Rivest) wandelt das
   Chiffrat so um, dass aus einem echten Teil der Ausgabe kein einziges Bit
   der Eingabe folgt.
2. Reed-Solomon verteilt das Ergebnis auf n Teile, von denen k genuegen.

Ohne Schritt 1 waere die systematische Kodierung ein Fehler: die ersten
Teile waeren woertliche Ausschnitte der Eingabe. Mit Schritt 1 gilt, worauf
es ankommt — mit k-1 Teilen weiss ein Angreifer so viel wie mit null, und
sobald n-k+1 Knoten geloescht haben, ist die Ablage fuer alle
unwiederbringlich zerstoert.
"""

from __future__ import annotations

import os
from dataclasses import dataclass

from .gf256 import Matrix, invert, matmul, mul, vandermonde
from .primitives.aead import chacha20
from .primitives.kdf import sha256

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
