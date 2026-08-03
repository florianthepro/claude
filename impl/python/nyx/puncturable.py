"""Punktierbare PRF (Whitepaper 7.7, Schicht 2).

Der Speicherknoten legt jeden Teil unter einem Schluessel ab, den er aus
seinem eigenen Speicherschluessel ableitet. Nach der Herausgabe entfernt er
aus diesem Schluessel die Faehigkeit, genau diesen Tag abzuleiten — und
behaelt alle uebrigen. Wird der Knoten danach beschlagnahmt, kann er den
herausgegebenen Teil selbst dann nicht mehr entschluesseln, wenn das
Chiffrat noch auf seinem Datentraeger liegt und der Angreifer den
vollstaendigen Speicherinhalt samt Schluesselmaterial hat.

Der Aufbau ist der uebliche GGM-Baum: aus einem Saatgut werden zwei
Kindsaatgueter abgeleitet, die Ableitung ist eine Einbahnstrasse. Der
Knoten haelt eine Menge von Teilbaum-Saatguetern, die zusammen alle
Blaetter ausser den punktierten abdecken. Punktieren heisst: den Pfad zum
Blatt aufklappen, die Geschwister behalten, das Blatt fallen lassen.

Kosten: pro Punktierung waechst die Schluesselmenge um hoechstens ``depth``
Eintraege. Das ist der Preis dafuer, dass Vergessen hier nicht auf einem
Versprechen beruht, sondern auf einer Einwegfunktion.
"""

from __future__ import annotations

from .primitives.kdf import hmac_sha256, sha256

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
