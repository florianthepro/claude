"""Identitaet, Verzeichnis, Handshake und Ratsche im Zusammenspiel."""

import time
import unittest

from nyx import directory as d
from nyx import x3dh
from nyx.identity import Identity, address_matches_key, address_valid
from nyx.primitives import AuthError
from nyx.ratchet import MAX_SKIPPED_KEYS, Ratchet, SkippedKeys


def paired_session(alice: Identity, bob: Identity):
    """Vollstaendiger Sitzungsaufbau, wie ihn die Clients durchfuehren."""
    store = x3dh.PrekeyStore(bob)
    body, opks = store.publish()
    bundle = x3dh.PrekeyBundle.from_body(
        bob.address, bob.idk_pub, body, {"id": opks[0][0], "pub": opks[0][1].hex()})

    root_a, header = x3dh.initiate(alice, bob.public, bundle)
    root_b = x3dh.respond(bob, store, header)

    ra = Ratchet.as_sender(root_a, bundle.spk_pub)
    rb = Ratchet.as_receiver(root_b, store.spk_priv(), store.spk_pub)
    return ra, rb, store, header


class TestIdentity(unittest.TestCase):
    def test_address_is_the_key(self):
        ident = Identity.create()
        self.assertTrue(address_valid(ident.address))
        self.assertTrue(address_matches_key(ident.address, ident.ik_pub))
        self.assertEqual(len(ident.address), 36)

        other = Identity.create()
        self.assertFalse(address_matches_key(ident.address, other.ik_pub))
        self.assertNotEqual(ident.address, other.address)

    def test_checksum_catches_typos(self):
        addr = Identity.create().address
        broken = ("a" if addr[0] != "a" else "b") + addr[1:]
        self.assertFalse(address_valid(broken))

    def test_export_import_roundtrip(self):
        a = Identity.create()
        b = Identity.import_private(a.export_private())
        self.assertEqual(a.address, b.address)
        self.assertEqual(a.idk_pub, b.idk_pub)


class TestDirectory(unittest.TestCase):
    def setUp(self):
        self.chain = d.Chain(bits=8)
        self.ident = Identity.create()

    def test_bind_and_resolve(self):
        self.chain.submit(d.bind_record(self.ident))
        self.chain.seal()
        resolved = self.chain.resolve(self.ident.address)
        self.assertIsNotNone(resolved)
        self.assertEqual(resolved.idk_pub, self.ident.idk_pub)
        self.assertTrue(self.chain.validate())

    def test_rejects_forged_record(self):
        rec = d.bind_record(self.ident)
        rec["body"]["idk_pub"] = "00" * 32
        self.assertFalse(d.verify_record(rec))
        with self.assertRaises(ValueError):
            self.chain.submit(rec)

    def test_rejects_record_whose_address_is_not_its_key(self):
        rec = d.bind_record(self.ident)
        rec["addr"] = Identity.create().address
        self.assertFalse(d.verify_record(rec))

    def test_tampering_with_a_sealed_block_is_detected(self):
        self.chain.submit(d.bind_record(self.ident))
        self.chain.seal()
        self.chain.blocks[-1].records[0]["ts"] += 1
        self.assertFalse(self.chain.validate())

    def test_chain_cannot_be_rewritten_without_redoing_work(self):
        self.chain.submit(d.bind_record(self.ident))
        self.chain.seal()
        self.chain.submit(d.bind_record(Identity.create()))
        self.chain.seal()
        # Ein Block in der Mitte wird ausgetauscht: der Nachfolger zeigt
        # danach auf einen Hash, den es nicht mehr gibt.
        self.chain.blocks[1].nonce += 1
        self.assertFalse(self.chain.validate())

    def test_key_change_is_publicly_visible(self):
        self.chain.submit(d.bind_record(self.ident))
        self.chain.seal()

        # Ein Angreifer mit Zugriff auf das Verzeichnis kann einen neuen
        # Schluessel eintragen — aber nur sichtbar.
        self.ident._idk_priv = bytearray(b"\x11" * 32)
        from nyx.primitives import x25519_public
        self.ident.idk_pub = x25519_public(bytes(self.ident._idk_priv))
        self.chain.submit(d.bind_record(self.ident))
        self.chain.seal()

        history = self.chain.key_history(self.ident.address)
        self.assertEqual(len([h for h in history if h["type"] == "BIND"]), 2)

    def test_revocation_takes_effect(self):
        self.chain.submit(d.bind_record(self.ident))
        self.chain.seal()
        self.chain.submit(d.revoke_record(self.ident, self.ident.idk_pub.hex(), "verloren"))
        self.chain.seal()
        self.assertIsNone(self.chain.resolve(self.ident.address))

    def test_merkle_proof_lets_a_light_client_verify(self):
        for _ in range(5):
            self.chain.submit(d.bind_record(Identity.create()))
        self.chain.submit(d.bind_record(self.ident))
        self.chain.seal()

        proof = self.chain.proof_for(self.ident.address, "BIND")
        self.assertIsNotNone(proof)
        root = bytes.fromhex(proof["header"]["merkle_root"])
        self.assertTrue(d.verify_merkle_proof(proof["record"], proof["proof"], root))

        forged = dict(proof["record"])
        forged["ts"] += 1
        self.assertFalse(d.verify_merkle_proof(forged, proof["proof"], root))

    def test_recovery_needs_both_signatures(self):
        successor = Identity.create()
        rec = d.recover_record(self.ident, successor.address)
        self.assertTrue(d.verify_recover(rec, self.ident.recovery_pub))
        self.assertFalse(d.verify_recover(rec, Identity.create().recovery_pub))


class TestHandshake(unittest.TestCase):
    def test_both_sides_derive_the_same_session(self):
        alice, bob = Identity.create(), Identity.create()
        ra, rb, _, _ = paired_session(alice, bob)
        header, boxed = ra.encrypt(b"erste nachricht")
        self.assertEqual(rb.decrypt(header, boxed), b"erste nachricht")

    def test_unsigned_bundle_is_refused(self):
        alice, bob = Identity.create(), Identity.create()
        store = x3dh.PrekeyStore(bob)
        body, opks = store.publish()
        bundle = x3dh.PrekeyBundle.from_body(bob.address, bob.idk_pub, body)
        bundle.spk_sig = b"\x00" * 64
        with self.assertRaises(ValueError):
            x3dh.initiate(alice, bob.public, bundle)

    def test_expired_bundle_is_refused(self):
        alice, bob = Identity.create(), Identity.create()
        store = x3dh.PrekeyStore(bob)
        body, _ = store.publish()
        body["valid_until"] = int(time.time()) - 1
        bundle = x3dh.PrekeyBundle.from_body(bob.address, bob.idk_pub, body)
        with self.assertRaises(ValueError):
            x3dh.initiate(alice, bob.public, bundle)

    def test_one_time_prekey_is_consumed_exactly_once(self):
        alice, bob = Identity.create(), Identity.create()
        _, _, store, header = paired_session(alice, bob)
        self.assertIsNone(store.take(header.opk_id))
        with self.assertRaises(ValueError):
            x3dh.respond(bob, store, header)

    def test_safety_number_is_symmetric_and_specific(self):
        a, b, c = Identity.create(), Identity.create(), Identity.create()
        self.assertEqual(x3dh.safety_number(a.public, b.public),
                         x3dh.safety_number(b.public, a.public))
        self.assertNotEqual(x3dh.safety_number(a.public, b.public),
                            x3dh.safety_number(a.public, c.public))


class TestRatchet(unittest.TestCase):
    def setUp(self):
        self.alice, self.bob = Identity.create(), Identity.create()
        self.ra, self.rb, _, _ = paired_session(self.alice, self.bob)

    def test_conversation_in_both_directions(self):
        h, c = self.ra.encrypt(b"hallo")
        self.assertEqual(self.rb.decrypt(h, c), b"hallo")
        h, c = self.rb.encrypt(b"auch hallo")
        self.assertEqual(self.ra.decrypt(h, c), b"auch hallo")
        for i in range(10):
            h, c = self.ra.encrypt(f"a{i}".encode())
            self.assertEqual(self.rb.decrypt(h, c), f"a{i}".encode())
            h, c = self.rb.encrypt(f"b{i}".encode())
            self.assertEqual(self.ra.decrypt(h, c), f"b{i}".encode())

    def test_every_message_gets_its_own_key(self):
        seen = set()
        for i in range(20):
            h, c = self.ra.encrypt(b"gleicher klartext")
            seen.add(c)
            self.rb.decrypt(h, c)
        self.assertEqual(len(seen), 20)

    def test_out_of_order_delivery(self):
        msgs = [self.ra.encrypt(f"nachricht {i}".encode()) for i in range(5)]
        for h, c in reversed(msgs):
            pass
        order = [3, 0, 4, 1, 2]
        got = {}
        for i in order:
            h, c = msgs[i]
            got[i] = self.rb.decrypt(h, c)
        for i in range(5):
            self.assertEqual(got[i], f"nachricht {i}".encode())

    def test_a_message_key_is_used_exactly_once(self):
        h, c = self.ra.encrypt(b"nur einmal")
        self.assertEqual(self.rb.decrypt(h, c), b"nur einmal")
        # Wiedereinspielung derselben Nachricht: der Schluessel ist weg.
        with self.assertRaises((ValueError, AuthError)):
            self.rb.decrypt(h, c)

    def test_tampered_ciphertext_is_rejected(self):
        h, c = self.ra.encrypt(b"unveraendert")
        broken = bytearray(c)
        broken[0] ^= 0x80
        with self.assertRaises(AuthError):
            self.rb.decrypt(h, bytes(broken))

    def test_tampered_header_is_rejected(self):
        h, c = self.ra.encrypt(b"unveraendert")
        h.n += 1
        with self.assertRaises((AuthError, ValueError)):
            self.rb.decrypt(h, c)

    def test_destroy_leaves_nothing_usable(self):
        h, c = self.ra.encrypt(b"letzte")
        self.rb.decrypt(h, c)
        self.rb.destroy()
        h2, c2 = self.ra.encrypt(b"danach")
        with self.assertRaises((ValueError, AuthError, TypeError)):
            self.rb.decrypt(h2, c2)


class TestSkippedKeyLimits(unittest.TestCase):
    def test_cache_is_bounded(self):
        cache = SkippedKeys(max_keys=4)
        for i in range(10):
            cache.put(b"\x01" * 32, i, bytes(32))
        self.assertLessEqual(len(cache), 4)

    def test_key_expires_and_message_becomes_unreadable(self):
        cache = SkippedKeys(max_keys=10, max_age=0)
        cache.put(b"\x01" * 32, 0, b"\x02" * 32)
        time.sleep(0.01)
        self.assertIsNone(cache.take(b"\x01" * 32, 0))

    def test_stored_key_works_once_then_is_gone(self):
        cache = SkippedKeys()
        cache.put(b"\x01" * 32, 7, b"\x03" * 32)
        self.assertEqual(cache.take(b"\x01" * 32, 7), b"\x03" * 32)
        self.assertIsNone(cache.take(b"\x01" * 32, 7))

    def test_limits_match_the_whitepaper(self):
        self.assertEqual(MAX_SKIPPED_KEYS, 1000)

    def test_flood_of_skips_is_refused(self):
        alice, bob = Identity.create(), Identity.create()
        ra, rb, _, _ = paired_session(alice, bob)
        for _ in range(500):
            ra.encrypt(b"verworfen")
        h, c = ra.encrypt(b"weit vorne")
        with self.assertRaises(ValueError):
            rb.decrypt(h, c)


if __name__ == "__main__":
    unittest.main()
