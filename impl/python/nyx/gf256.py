"""Arithmetik in GF(2^8) und Matrizen darueber.

Grundlage der Reed-Solomon-Kodierung in dispersal.py. Das Feld ist
GF(2^8) mit dem ueblichen Polynom 0x11d; alle Werte sind Bytes, alle
Rechnungen exakt.
"""

from __future__ import annotations

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
