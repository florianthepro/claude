"""Interoperabilitaet zwischen den Implementierungen.

Diese Tests laufen nur, wenn ein PHP-Knoten erreichbar ist:

    NYX_DATA=/tmp/nyx-php php -S 127.0.0.1:8477 -t impl/php/public
    NYX_PHP=http://127.0.0.1:8477 python3 -m unittest tests.test_interop

Sie sind der Nachweis, dass Python und PHP dieselben Bytes meinen: gleiche
kanonische JSON-Darstellung, gleiche Hashes, gleiche AEAD-Chiffrate, gleiche
punktierbare Ableitung. Ein Client kann dadurch mit beliebig gemischten
Knoten arbeiten, ohne zu wissen, womit er gerade spricht.
"""

from __future__ import annotations

import json
import os
import random
import subprocess
import unittest
import urllib.error
import urllib.request

from nyx.directory import Chain, bind_record, canonical, prekey_record
from nyx.drops import DropNetwork
from nyx.identity import Identity, address_from_key
from nyx.node import RemoteStore
from nyx.primitives import sha256
from nyx.store import solve_pow

PHP_URL = os.environ.get("NYX_PHP")
PHP_ROOT = os.path.join(os.path.dirname(__file__), "..", "..", "php")


def php_available() -> bool:
    if not PHP_URL:
        return False
    try:
        urllib.request.urlopen(PHP_URL + "/v1/info", timeout=3).read()
        return True
    except (urllib.error.URLError, OSError):
        return False


def run_php(code: str) -> str:
    """Fuehrt PHP-Code gegen dieselben Klassen aus, die der Knoten benutzt."""
    prelude = (
        f"require '{os.path.abspath(PHP_ROOT)}/src/Canonical.php';"
        f"require '{os.path.abspath(PHP_ROOT)}/src/Puncturable.php';"
        f"require '{os.path.abspath(PHP_ROOT)}/src/Directory.php';"
    )
    out = subprocess.run(["php", "-r", prelude + code],
                         capture_output=True, text=True, timeout=30)
    if out.returncode != 0:
        raise RuntimeError(out.stderr.strip() or "PHP-Fehler")
    return out.stdout.strip()


def php_binary_available() -> bool:
    try:
        subprocess.run(["php", "-v"], capture_output=True, timeout=10)
        return True
    except (FileNotFoundError, subprocess.SubprocessError):
        return False


@unittest.skipUnless(php_binary_available(), "php nicht vorhanden")
class TestCrossLanguagePrimitives(unittest.TestCase):
    """Ohne diese Uebereinstimmung stimmt keine Signatur ueber Sprachgrenzen."""

    def test_canonical_json_is_byte_identical(self):
        value = {"z": 1, "a": {"n": None, "m": [3, 2, 1]}, "s": "a/b",
                 "t": True, "u": "gruen"}
        mine = canonical(value).decode()
        theirs = run_php(
            "echo Nyx\\Canonical::encode(json_decode('"
            + json.dumps(value).replace("'", "\\'") + "', true));")
        self.assertEqual(mine, theirs)

    def test_sha256_with_domain_label_matches(self):
        mine = sha256(b"nyx/v1/addr", b"abc").hex()
        theirs = run_php("echo bin2hex(Nyx\\Canonical::sha256('nyx/v1/addr','abc'));")
        self.assertEqual(mine, theirs)

    def test_address_derivation_matches(self):
        ident = Identity.create()
        theirs = run_php(
            f"echo Nyx\\Directory::addressFromKey(hex2bin('{ident.ik_pub.hex()}'));")
        self.assertEqual(ident.address, theirs)
        self.assertEqual(address_from_key(ident.ik_pub), theirs)

    def test_php_verifies_a_python_signed_record(self):
        record = bind_record(Identity.create())
        blob = json.dumps(record).replace("'", "\\'")
        theirs = run_php(
            f"echo Nyx\\Directory::verifyRecord(json_decode('{blob}', true)) ? 'ja' : 'nein';")
        self.assertEqual(theirs, "ja")

    def test_php_rejects_a_tampered_record(self):
        record = bind_record(Identity.create())
        record["ts"] += 1
        blob = json.dumps(record).replace("'", "\\'")
        theirs = run_php(
            f"echo Nyx\\Directory::verifyRecord(json_decode('{blob}', true)) ? 'ja' : 'nein';")
        self.assertEqual(theirs, "nein")

    def test_php_rejects_a_record_whose_address_is_not_its_key(self):
        record = bind_record(Identity.create())
        record["addr"] = Identity.create().address
        blob = json.dumps(record).replace("'", "\\'")
        theirs = run_php(
            f"echo Nyx\\Directory::verifyRecord(json_decode('{blob}', true)) ? 'ja' : 'nein';")
        self.assertEqual(theirs, "nein")

    def test_puncturable_leaf_index_matches(self):
        tag = os.urandom(32)
        from nyx.puncturable import leaf_index
        theirs = run_php(f"echo Nyx\\Puncturable::leafIndex(hex2bin('{tag.hex()}'));")
        self.assertEqual(str(leaf_index(tag)), theirs)

    def test_puncturable_derivation_matches_and_puncturing_agrees(self):
        from nyx.puncturable import PuncturableKey
        master, tag = os.urandom(32), os.urandom(32)
        key = PuncturableKey(master)

        theirs = run_php(
            f"$k = new Nyx\\Puncturable(hex2bin('{master.hex()}'));"
            f"echo bin2hex($k->derive(hex2bin('{tag.hex()}')));")
        self.assertEqual(key.derive(tag).hex(), theirs)

        after = run_php(
            f"$k = new Nyx\\Puncturable(hex2bin('{master.hex()}'));"
            f"$k->puncture(hex2bin('{tag.hex()}'));"
            f"echo $k->derive(hex2bin('{tag.hex()}')) === null ? 'weg' : 'da';")
        key.puncture(tag)
        self.assertIsNone(key.derive(tag))
        self.assertEqual(after, "weg")

    def test_merkle_root_matches(self):
        records = [bind_record(Identity.create()) for _ in range(3)]
        from nyx.directory import merkle_root
        blob = json.dumps(records).replace("'", "\\'")
        theirs = run_php(
            f"echo bin2hex(Nyx\\Directory::merkleRoot(json_decode('{blob}', true)));")
        self.assertEqual(merkle_root(records).hex(), theirs)


@unittest.skipUnless(php_available(), "kein PHP-Knoten unter NYX_PHP erreichbar")
class TestAgainstPhpNode(unittest.TestCase):
    """Der Python-Client gegen einen laufenden PHP-Knoten."""

    def setUp(self):
        self.node = RemoteStore(PHP_URL)

    def test_store_fetch_once(self):
        tag, blob = os.urandom(32).hex(), b"von python nach php"
        self.node.put(tag, blob, None, solve_pow(tag, blob, self.node.pow_bits))
        self.assertEqual(self.node.get(tag), blob)
        self.assertIsNone(self.node.get(tag))

    def test_php_refuses_a_missing_proof_of_work(self):
        tag, blob = os.urandom(32).hex(), b"ohne nachweis"
        with self.assertRaises(ValueError):
            self.node.put(tag, blob, None, None)

    def test_php_refuses_a_reused_tag(self):
        tag, blob = os.urandom(32).hex(), b"einmal"
        self.node.put(tag, blob, None, solve_pow(tag, blob, self.node.pow_bits))
        self.node.get(tag)
        with self.assertRaises(ValueError):
            self.node.put(tag, blob, None, solve_pow(tag, blob, self.node.pow_bits))

    def test_void_makes_a_drop_unreachable(self):
        tag, blob = os.urandom(32).hex(), b"entwertet"
        self.node.put(tag, blob, None, solve_pow(tag, blob, self.node.pow_bits))
        self.assertTrue(self.node.void(tag))
        self.assertIsNone(self.node.get(tag))

    def test_php_directory_accepts_python_records(self):
        ident = Identity.create()
        answer = self.node.call("/v1/dir/submit", {"record": bind_record(ident)})
        self.assertTrue(answer["ok"])

        resolved = self.node.call("/v1/dir/resolve", {"addr": ident.address})
        self.assertEqual(resolved["identity"]["idk_pub"], ident.idk_pub.hex())

    def test_full_conversation_over_php_nodes(self):
        """Der eigentliche Beweis: ein Gespraech, dessen Ablagen in PHP liegen."""
        from nyx.client import Client

        chain = Chain(bits=6)
        drops = DropNetwork([_Namespaced(self.node, f"php{i}") for i in range(20)])
        alice = Client(Identity.create(), chain, drops, random.Random(5))
        bob = Client(Identity.create(), chain, drops, random.Random(6))
        alice.announce()
        bob.announce()

        alice.send(bob.identity.address, {"t": "chat", "text": "quer durch die Sprachen"})
        got = bob.poll()
        self.assertEqual(len(got), 1)
        self.assertEqual(got[0][1]["text"], "quer durch die Sprachen")


class _Namespaced:
    """Ein PHP-Knoten, der fuer den Test als mehrere auftritt.

    Im Betrieb sind das getrennte Rechner in getrennten Rechtsraeumen; hier
    genuegt ein Prozess, um die Byte-Ebene zu pruefen.
    """

    def __init__(self, node: RemoteStore, name: str):
        self.node, self.name = node, name
        self.pow_bits, self.ttl = node.pow_bits, node.ttl

    def put(self, tag, blob, ttl=None, nonce=None):
        return self.node.put(tag, blob, ttl, nonce)

    def get(self, tag):
        return self.node.get(tag)

    def void(self, tag):
        return self.node.void(tag)

    def has(self, tag):
        raise NotImplementedError

    def try_read_hoarded(self, tag):
        return None

    @property
    def count(self):
        return self.node.count


if __name__ == "__main__":
    unittest.main()
