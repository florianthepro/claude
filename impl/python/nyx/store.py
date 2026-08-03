"""Speicherknoten: blinde Ablagen (Whitepaper 7.4 bis 7.7).

Was der Knoten sieht: einen Zufallswert und einen Block Rauschen fester
Groesse. Er kennt weder Sender noch Empfaenger, weder die Zugehoerigkeit zu
einer Unterhaltung noch die zu einer Nachricht, noch weiss er, welche
anderen Knoten die uebrigen Teile halten.

Der Knoten setzt drei der vier Schichten aus 7.7 um:

  Schicht 2  punktierbare Speicherverschluesselung (puncturable.py)
  Schicht 3  er haelt nur einen von n Teilen, nie genug zur Rekonstruktion
  Schicht 4  Verfallszeit

Schicht 1 — der Nachrichtenschluessel, der hier nie ankommt — liegt
ausserhalb dieses Moduls und ist genau deshalb die staerkste.
"""

from __future__ import annotations

import os
import time
from dataclasses import dataclass, field

from .primitives import AuthError, hmac_sha256, open_, seal, sha256
from .puncturable import PuncturableKey

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
