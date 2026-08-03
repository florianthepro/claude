"""Knoten-Daemon und Fernzugriff.

Der Daemon stellt Speichernetz und Verzeichnis ueber HTTP bereit. Die
Schnittstelle ist bewusst klein und in docs/api.md normativ beschrieben;
die PHP-Fassung in impl/php implementiert dieselbe und ist gegen dieselben
Testfaelle geprueft.

Der Knoten lernt aus jeder Anfrage nur das, was das Protokoll ohnehin
preisgibt: einen Zufallswert als Tag und einen Block Rauschen. Er fuehrt
kein Zugriffsprotokoll — nicht aus Nachlaessigkeit, sondern weil ein
solches Protokoll genau die Metadaten waere, die das uebrige System
vermeidet.
"""

from __future__ import annotations

import json
import threading
import urllib.error
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from .directory import Chain
from .identity import PublicIdentity
from .store import StorageNode

VERSION = "nyx/1"


class NodeService:
    """Die Logik hinter der Schnittstelle, ohne HTTP."""

    def __init__(self, store: StorageNode, chain: Chain):
        self.store = store
        self.chain = chain

    def info(self) -> dict:
        return {
            "version": VERSION,
            "name": self.store.name,
            "pow_bits": self.store.pow_bits,
            "ttl": self.store.ttl,
            "entries": self.store.count,
            "height": len(self.chain.blocks) - 1,
        }

    def store_drop(self, body: dict) -> dict:
        tag = body["tag"]
        blob = bytes.fromhex(body["blob"])
        receipt = self.store.put(tag, blob, body.get("ttl"), body.get("nonce"))
        return {"ok": True, **receipt}

    def fetch(self, body: dict) -> dict:
        tags = body.get("tags", [])
        if len(tags) > 256:
            raise ValueError("zu viele Tags in einer Anfrage")
        return {"results": self.store.get_batch(tags)}

    def void(self, body: dict) -> dict:
        return {"voided": sum(1 for t in body.get("tags", []) if self.store.void(t))}

    def proof(self, body: dict) -> dict:
        challenge = bytes.fromhex(body.get("challenge", ""))
        return self.store.prove_retrievability(challenge)

    def dir_headers(self, _: dict) -> dict:
        return {"headers": self.chain.headers()}

    def dir_submit(self, body: dict) -> dict:
        self.chain.submit(body["record"])
        if body.get("seal", True):
            self.chain.seal()
        return {"ok": True, "height": len(self.chain.blocks) - 1}

    def dir_resolve(self, body: dict) -> dict:
        address = body.get("addr", "")
        identity = self.chain.resolve(address)
        return {
            "identity": identity.to_dict() if identity else None,
            "bundle": self.chain.latest_bundle(address),
            "history": self.chain.key_history(address),
        }

    def dir_proof(self, body: dict) -> dict:
        return {"proof": self.chain.proof_for(body.get("addr", ""),
                                              body.get("type", "BIND"))}

    ROUTES = {
        "/v1/info": "info_get",
        "/v1/store": "store_drop",
        "/v1/fetch": "fetch",
        "/v1/void": "void",
        "/v1/proof": "proof",
        "/v1/dir/headers": "dir_headers",
        "/v1/dir/submit": "dir_submit",
        "/v1/dir/resolve": "dir_resolve",
        "/v1/dir/proof": "dir_proof",
    }

    def dispatch(self, path: str, body: dict) -> dict:
        name = self.ROUTES.get(path)
        if name is None:
            raise KeyError(path)
        if name == "info_get":
            return self.info()
        return getattr(self, name)(body)


class _Handler(BaseHTTPRequestHandler):
    server_version = "nyx"

    def log_message(self, *args) -> None:
        """Kein Zugriffsprotokoll. Siehe Modulkopf."""

    def _reply(self, code: int, payload: dict) -> None:
        raw = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.end_headers()
        self.wfile.write(raw)

    def do_OPTIONS(self) -> None:
        self._reply(204, {})

    def do_GET(self) -> None:
        path = urllib.parse.urlparse(self.path).path
        query = dict(urllib.parse.parse_qsl(urllib.parse.urlparse(self.path).query))
        self._handle(path, query)

    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", 0))
        try:
            body = json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            return self._reply(400, {"error": "kein gueltiges JSON"})
        self._handle(urllib.parse.urlparse(self.path).path, body)

    def _handle(self, path: str, body: dict) -> None:
        try:
            self._reply(200, self.server.service.dispatch(path, body))
        except KeyError:
            self._reply(404, {"error": "unbekannter Pfad"})
        except ValueError as exc:
            self._reply(400, {"error": str(exc)})


class NodeServer:
    """Ein lauffaehiger Knoten."""

    def __init__(self, service: NodeService, host: str = "127.0.0.1", port: int = 8443):
        self.service = service
        self.httpd = ThreadingHTTPServer((host, port), _Handler)
        self.httpd.service = service
        self.thread: threading.Thread | None = None

    @property
    def url(self) -> str:
        host, port = self.httpd.server_address[:2]
        return f"http://{host}:{port}"

    def start(self) -> None:
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)
        self.thread.start()

    def stop(self) -> None:
        self.httpd.shutdown()
        self.httpd.server_close()

    def __enter__(self) -> "NodeServer":
        self.start()
        return self

    def __exit__(self, *exc) -> None:
        self.stop()


class RemoteStore:
    """Ein entfernter Speicherknoten, benutzbar wie ein lokaler.

    Damit laeuft derselbe Client gegen die Python- und die PHP-Fassung des
    Knotens; welche davon antwortet, ist fuer das Protokoll ohne Belang.
    """

    def __init__(self, url: str, name: str | None = None, timeout: float = 10.0):
        self.url = url.rstrip("/")
        self.timeout = timeout
        info = self.call("/v1/info", {}, method="GET")
        self.name = name or info.get("name", self.url)
        self.pow_bits = info.get("pow_bits", 0)
        self.ttl = info.get("ttl", 0)

    def call(self, path: str, body: dict, method: str = "POST") -> dict:
        url = self.url + path
        data = None if method == "GET" else json.dumps(body).encode()
        req = urllib.request.Request(url, data=data, method=method,
                                     headers={"Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                return json.loads(resp.read())
        except urllib.error.HTTPError as exc:
            detail = json.loads(exc.read() or b"{}").get("error", str(exc))
            raise ValueError(detail) from exc

    # -- Schnittstelle wie StorageNode -----------------------------------

    def put(self, tag: str, blob: bytes, ttl: int | None = None,
            nonce: int | None = None) -> dict:
        return self.call("/v1/store", {"tag": tag, "blob": blob.hex(),
                                       "ttl": ttl, "nonce": nonce})

    def get(self, tag: str) -> bytes | None:
        got = self.call("/v1/fetch", {"tags": [tag]})["results"].get(tag)
        return bytes.fromhex(got) if got else None

    def get_batch(self, tags: list[str]) -> dict[str, str | None]:
        return self.call("/v1/fetch", {"tags": tags})["results"]

    def void(self, tag: str) -> bool:
        return self.call("/v1/void", {"tags": [tag]})["voided"] > 0

    def void_batch(self, tags: list[str]) -> int:
        return self.call("/v1/void", {"tags": tags})["voided"]

    def has(self, tag: str) -> bool:
        raise NotImplementedError(
            "Ein Knoten beantwortet keine Existenzfragen — jede Abfrage ist "
            "eine Abholung. Sonst waere 'liegt hier etwas fuer dich' eine "
            "Metadatenquelle.")

    def try_read_hoarded(self, tag: str) -> bytes | None:
        return None

    @property
    def count(self) -> int:
        return self.call("/v1/info", {}, method="GET").get("entries", 0)

    def __repr__(self) -> str:
        return f"<RemoteStore {self.name} @ {self.url}>"


class RemoteChain:
    """Ein entferntes Verzeichnis, benutzbar wie eine lokale Kette.

    Der Client braucht vom Verzeichnis nur dreierlei: einen Eintrag
    einreichen, eine Adresse aufloesen und das Prekey-Buendel holen. Alles
    davon geht ueber die Knotenschnittstelle — und im Betrieb ueber das
    Mixnetz, damit kein Knoten ein Interessenprofil anlegen kann.
    """

    def __init__(self, url: str, timeout: float = 10.0):
        self.remote = RemoteStore.__new__(RemoteStore)
        self.remote.url = url.rstrip("/")
        self.remote.timeout = timeout
        self._pending: list[dict] = []

    def submit(self, record: dict) -> None:
        self._pending.append(record)

    def seal(self) -> None:
        for record in self._pending:
            self.remote.call("/v1/dir/submit", {"record": record})
        self._pending = []

    def resolve(self, address: str):
        data = self.remote.call("/v1/dir/resolve", {"addr": address})
        if not data.get("identity"):
            return None
        return PublicIdentity(
            address,
            bytes.fromhex(data["identity"]["ik_pub"]),
            bytes.fromhex(data["identity"]["idk_pub"]),
        )

    def latest_bundle(self, address: str) -> dict | None:
        return self.remote.call("/v1/dir/resolve", {"addr": address}).get("bundle")

    def key_history(self, address: str) -> list[dict]:
        return self.remote.call("/v1/dir/resolve", {"addr": address}).get("history", [])


def build(name: str = "knoten", port: int = 8443, bits: int = 10,
          pow_bits: int = 12) -> NodeServer:
    return NodeServer(NodeService(StorageNode(name, pow_bits=pow_bits),
                                  Chain(bits=bits)), port=port)
