"""Datenuebertragung.

Eine Datei geht nicht als Ganzes ueber den Kanal, sondern in Bloecken
fester Groesse. Jeder Block wird einzeln durch die Ratsche verschluesselt
und einzeln in n Teile zerlegt abgelegt. Der Empfaenger bekommt zuerst ein
Verzeichnis (Manifest) mit den Pruefsummen und laedt dann die Bloecke.

Feste Blockgroesse ist hier kein Detail: sie sorgt dafuer, dass die Anzahl
der Ablagen nur die ungefaehre Groesse der Datei verraet und nicht ihre
Struktur — und dass eine Datei von aussen wie eine Folge von
Kurznachrichten aussieht.
"""

from __future__ import annotations

import base64
import os
from dataclasses import dataclass, field

from ..primitives import sha256

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
