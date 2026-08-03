"""Blinde Ablagen ueber mehrere Knoten (Whitepaper 7.4 bis 7.6).

Hier laufen Zerlegung, Tags und Speicherknoten zusammen. Der Client legt
eine Nachricht als n Teile bei n Knoten ab, jeweils unter einem eigenen,
nicht verkettbaren Tag, und holt sie mit k Teilen wieder ab.

Zwei Tags derselben Nachricht sind fuer Dritte nicht als zusammengehoerig
erkennbar, und zwei Nachrichten derselben Unterhaltung erst recht nicht.
"""

from __future__ import annotations

import os
import random
from dataclasses import dataclass

from .dispersal import DEFAULT_K, DEFAULT_N, Share, disperse, reassemble
from .primitives import hkdf, hmac_sha256
from .store import StorageNode, solve_pow

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
