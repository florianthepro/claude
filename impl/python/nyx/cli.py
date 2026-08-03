"""Terminal-Client.

    python3 -m nyx.cli init                     Identitaet anlegen
    python3 -m nyx.cli wer                      eigene Adresse zeigen
    python3 -m nyx.cli knoten [--port 8443]     Knoten betreiben
    python3 -m nyx.cli senden <adresse> <text>
    python3 -m nyx.cli holen
    python3 -m nyx.cli pruefen <adresse>

Der Zustand liegt unter ~/.nyx: die Identitaet, die privaten Prekeys und
die laufenden Sitzungen. Nachrichten werden nicht gespeichert.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

from .client import Client
from .drops import DropNetwork
from .identity import Identity, address_valid
from .node import NodeService, NodeServer, RemoteChain, RemoteStore
from .x3dh import safety_number

HOME = Path(os.environ.get("NYX_HOME", Path.home() / ".nyx"))
DEFAULT_NODE = os.environ.get("NYX_NODE", "http://127.0.0.1:8443")


def ensure_home() -> Path:
    HOME.mkdir(parents=True, exist_ok=True)
    os.chmod(HOME, 0o700)
    return HOME


def load_identity() -> Identity:
    path = HOME / "identitaet.json"
    if not path.is_file():
        sys.exit("Keine Identitaet. Zuerst: python3 -m nyx.cli init")
    return Identity.import_private(path.read_text())


def save_identity(identity: Identity) -> None:
    path = ensure_home() / "identitaet.json"
    path.write_text(identity.export_private())
    os.chmod(path, 0o600)


def build_client(url: str) -> Client:
    """Der Client spricht mit einem Knoten; im Betrieb ueber das Mixnetz."""
    identity = load_identity()
    store = RemoteStore(url)
    nodes = [_Named(store, f"{store.name}-{i}") for i in range(3)]
    client = Client(identity, RemoteChain(url), DropNetwork(nodes))

    state = HOME / "prekeys.json"
    if state.is_file():
        client.prekeys.import_state(state.read_text())
    return client


def save_prekeys(client: Client) -> None:
    path = ensure_home() / "prekeys.json"
    path.write_text(client.prekeys.export_state())
    os.chmod(path, 0o600)


class _Named:
    """Ein Knoten unter eigenem Namen.

    Im Betrieb traegt man hier mehrere Adressen ein, damit die Teile
    tatsaechlich auf verschiedene Rechner gehen.
    """

    def __init__(self, store: RemoteStore, name: str):
        self.store, self.name = store, name
        self.pow_bits, self.ttl = store.pow_bits, store.ttl

    def put(self, *args):
        return self.store.put(*args)

    def get(self, tag):
        return self.store.get(tag)

    def get_batch(self, tags):
        return self.store.get_batch(tags)

    def void(self, tag):
        return self.store.void(tag)

    def void_batch(self, tags):
        return self.store.void_batch(tags)

    def has(self, tag):
        raise NotImplementedError

    def try_read_hoarded(self, tag):
        return None

    @property
    def count(self):
        return self.store.count


# -- Befehle ---------------------------------------------------------------

def cmd_init(args) -> int:
    if (HOME / "identitaet.json").is_file() and not args.force:
        sys.exit("Es gibt bereits eine Identitaet. Mit --force ueberschreiben "
                 "(die alte ist danach verloren).")
    identity = Identity.create()
    save_identity(identity)

    client = build_client(args.node)
    client.announce()
    save_prekeys(client)

    print(f"Adresse: {identity.address}")
    print(f"Gesichert in {HOME / 'identitaet.json'} — ohne diese Datei ist die")
    print("Identitaet verloren. Es gibt keine Wiederherstellung durch Dritte.")
    return 0


def cmd_wer(args) -> int:
    print(load_identity().address)
    return 0


def cmd_knoten(args) -> int:
    from .directory import Chain
    from .store import StorageNode

    data = ensure_home() / "knoten"
    data.mkdir(exist_ok=True)
    service = NodeService(StorageNode(args.name, pow_bits=args.pow),
                          Chain(bits=args.bits))
    server = NodeServer(service, args.host, args.port)
    print(f"Knoten {args.name} auf {server.url}")
    print("Kein Zugriffsprotokoll. Beenden mit Strg-C.")
    try:
        server.start()
        server.thread.join()
    except KeyboardInterrupt:
        server.stop()
    return 0


def cmd_senden(args) -> int:
    if not address_valid(args.adresse):
        sys.exit("Das ist keine gueltige Adresse — die Pruefsumme stimmt nicht.")
    client = build_client(args.node)
    _load_sessions(client)
    result = client.send(args.adresse, {"t": "chat", "text": args.text})
    _save_sessions(client)
    save_prekeys(client)
    print(f"Gesendet in {result['zellen']} Zelle(n)"
          + (" ueber das Postfach (Erstkontakt)" if result["erstkontakt"] else ""))
    return 0


def cmd_holen(args) -> int:
    client = build_client(args.node)
    _load_sessions(client)
    empfangen = client.poll()
    _save_sessions(client)
    save_prekeys(client)

    if not empfangen:
        print("Nichts Neues.")
        return 0
    for absender, umschlag in empfangen:
        if umschlag.get("t") == "chat":
            print(f"{absender[:16]}…  {umschlag['text']}")
        else:
            print(f"{absender[:16]}…  [{umschlag.get('t', '?')}]")
    return 0


def cmd_pruefen(args) -> int:
    client = build_client(args.node)
    peer = client.chain.resolve(args.adresse)
    if peer is None:
        sys.exit("Diese Adresse steht nicht im Verzeichnis.")

    print("Vergleichswert:")
    print(" ", safety_number(client.identity.public, peer))
    history = [h for h in client.chain.key_history(args.adresse) if h["type"] == "BIND"]
    print(f"\nBindungen im Verzeichnis: {len(history)}")
    for entry in history:
        print(f"  Block {entry['height']}: {entry['key'][:16]}…")
    if len(history) > 1:
        print("\nMehr als eine Bindung. Ein Schluesselwechsel kann harmlos sein")
        print("(neues Geraet) oder nicht. Das Verzeichnis zeigt ihn, es bewertet")
        print("ihn nicht.")
    return 0


# -- Sitzungen -------------------------------------------------------------
# Nur der Zaehlerstand und die Tag-Geheimnisse werden aufbewahrt. Der
# Ratschenzustand bleibt im Arbeitsspeicher: er ueber Neustarts hinweg auf
# die Platte zu schreiben, wuerde die Vorwaertsgeheimhaltung aushebeln, die
# er herstellen soll. Ein Neustart kostet deshalb die laufende Sitzung.

def _load_sessions(client: Client) -> None:
    path = HOME / "sitzungen.json"
    if path.is_file():
        client.inbox_cursor = json.loads(path.read_text()).get("inbox", {})


def _save_sessions(client: Client) -> None:
    path = ensure_home() / "sitzungen.json"
    path.write_text(json.dumps({"inbox": client.inbox_cursor}))
    os.chmod(path, 0o600)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="nyx", description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--node", default=DEFAULT_NODE, help="Adresse eines Knotens")
    sub = parser.add_subparsers(dest="befehl", required=True)

    p = sub.add_parser("init", help="Identitaet anlegen und ankuendigen")
    p.add_argument("--force", action="store_true")
    p.set_defaults(func=cmd_init)

    sub.add_parser("wer", help="eigene Adresse zeigen").set_defaults(func=cmd_wer)

    p = sub.add_parser("knoten", help="Knoten betreiben")
    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--port", type=int, default=8443)
    p.add_argument("--name", default="knoten")
    p.add_argument("--bits", type=int, default=10)
    p.add_argument("--pow", type=int, default=12)
    p.set_defaults(func=cmd_knoten)

    p = sub.add_parser("senden", help="Nachricht senden")
    p.add_argument("adresse")
    p.add_argument("text")
    p.set_defaults(func=cmd_senden)

    sub.add_parser("holen", help="Wartendes abholen").set_defaults(func=cmd_holen)

    p = sub.add_parser("pruefen", help="Schluessel einer Adresse pruefen")
    p.add_argument("adresse")
    p.set_defaults(func=cmd_pruefen)

    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
