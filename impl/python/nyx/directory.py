"""Das Verzeichnis: eine nur anhaengbare Kette von Schluesselereignissen.

In die Kette gehen keine Nachrichten. Nur vier Eintragsarten:

  BIND     bindet einen X25519-Handshakeschluessel an eine Identitaet
  PREKEY   veroeffentlicht ein signiertes Prekey-Buendel
  REVOKE   widerruft einen Schluessel
  RECOVER  benennt eine Nachfolge-Identitaet

Der Zweck ist nicht, das Unterschieben eines Schluessels unmoeglich zu
machen, sondern es sichtbar und dauerhaft nachweisbar zu machen. Wer einen
fremden Schluessel unterschiebt, hinterlaesst einen Eintrag, den jeder
sehen und niemand nachtraeglich entfernen kann — genau das leistet
``key_history()``.
"""

from __future__ import annotations

import json
import time
from dataclasses import dataclass, field

from .identity import Identity, PublicIdentity, address_matches_key, verify_signature
from .primitives import sha256

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
