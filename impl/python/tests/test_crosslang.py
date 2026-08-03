"""Ein Gespraech ueber drei Implementierungen hinweg.

Der Web-Client (JavaScript, hier unter Node ausgefuehrt) legt eine
Nachricht bei einem PHP-Knoten ab; der Python-Client holt sie dort ab. Alle
drei Fassungen muessen dafuer dieselben Bytes meinen — kanonisches JSON,
Adressen, Prekey-Signaturen, Zerlegung, Tags und AEAD.

Voraussetzung: ein laufender Knoten unter NYX_PHP und ein `node`-Binary.

    NYX_DATA=/tmp/nyx-x php -S 127.0.0.1:8479 -t impl/php/public
    NYX_PHP=http://127.0.0.1:8479 python3 -m unittest tests.test_crosslang
"""

from __future__ import annotations

import os
import random
import subprocess
import textwrap
import unittest
import urllib.error
import urllib.request

from nyx.client import Client
from nyx.drops import DropNetwork
from nyx.identity import Identity
from nyx.node import RemoteChain, RemoteStore

PHP_URL = os.environ.get("NYX_PHP")
WEB_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "web"))


def reachable() -> bool:
    if not PHP_URL:
        return False
    try:
        urllib.request.urlopen(PHP_URL + "/v1/info", timeout=3).read()
    except (urllib.error.URLError, OSError):
        return False
    try:
        subprocess.run(["node", "-v"], capture_output=True, timeout=10)
        return True
    except (FileNotFoundError, subprocess.SubprocessError):
        return False


class _Node:
    """Ein entfernter Knoten unter eigenem Namen.

    Im Betrieb sind das getrennte Rechner; fuer den Nachweis der Byte-Ebene
    genuegt einer.
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


@unittest.skipUnless(reachable(), "kein PHP-Knoten unter NYX_PHP oder kein node")
class TestJavaScriptToPython(unittest.TestCase):
    def setUp(self):
        store = RemoteStore(PHP_URL)
        self.drops = DropNetwork([_Node(store, f"n{i}") for i in range(3)])
        self.bob = Client(Identity.create(), RemoteChain(PHP_URL), self.drops,
                          random.Random(4))
        self.bob.announce()

    def send_from_javascript(self, text: str) -> str:
        """Faehrt den unveraenderten Web-Client unter Node und gibt dessen
        Adresse zurueck."""
        script = textwrap.dedent(f"""
            import {{ Client, DropNetwork, Identity, NodeClient }}
              from '{WEB_DIR}/nyx-protocol.js';

            const node = new NodeClient({PHP_URL!r});
            await node.info();
            const drops = await new DropNetwork([node]).ready();
            const alice = new Client(await Identity.create(), drops, node);
            await alice.announce();
            await alice.send({self.bob.identity.address!r},
                             {{ t: 'chat', text: {text!r} }});
            console.log(alice.identity.address);
        """).replace("'", '"')

        path = "/tmp/nyx-cross-sender.mjs"
        with open(path, "w") as fh:
            fh.write(script)
        out = subprocess.run(["node", path], capture_output=True, text=True, timeout=180)
        if out.returncode != 0:
            raise RuntimeError(out.stderr.strip())
        return out.stdout.strip()

    def test_message_written_in_javascript_is_read_in_python(self):
        text = "Diese Nachricht kommt aus JavaScript."
        sender = self.send_from_javascript(text)

        received = self.bob.poll()
        self.assertEqual(len(received), 1, "genau eine Nachricht erwartet")
        address, envelope = received[0]
        self.assertEqual(address, sender)
        self.assertEqual(envelope["text"], text)

    def test_reply_from_python_reaches_the_same_session(self):
        sender = self.send_from_javascript("erste Nachricht")
        self.assertTrue(self.bob.poll())

        # Die Antwort laeuft ueber die gemeinsame Ratsche, nicht mehr ueber
        # das Postfach — ab hier ist die Unterhaltung unverkettbar.
        result = self.bob.send(sender, {"t": "chat", "text": "Antwort aus Python"})
        self.assertFalse(result["erstkontakt"])


if __name__ == "__main__":
    unittest.main()
