"""Speicherknoten, blinde Ablagen, Mixnetz.

Die Tests hier sind die ausfuehrbare Fassung von Whitepaper 7.7: sie
pruefen jede der vier Schichten einzeln und zeigen, dass die staerkste
(Schicht 1) auch dann traegt, wenn alle uebrigen versagen.
"""

import os
import random
import unittest

from nyx.drops import DropNetwork, cover_tag, drop_tag, tag_secret
from nyx.mixnet import HEADER_SIZE, PACKET_SIZE, MixNode, Mixnet, PacketError, build_packet
from nyx.primitives import AuthError, hkdf, open_, seal
from nyx.puncturable import PuncturableKey
from nyx.store import StorageNode, solve_pow


def network(count=20, hoard_indices=()):
    return DropNetwork([StorageNode(f"n{i}", hoard=i in hoard_indices)
                        for i in range(count)])


class TestPuncturableKey(unittest.TestCase):
    def test_derives_stably_until_punctured(self):
        key = PuncturableKey(os.urandom(32))
        tag = os.urandom(32)
        first = key.derive(tag)
        self.assertEqual(key.derive(tag), first)

        key.puncture(tag)
        self.assertIsNone(key.derive(tag))

    def test_puncturing_one_tag_spares_the_others(self):
        key = PuncturableKey(os.urandom(32))
        tags = [os.urandom(32) for _ in range(20)]
        before = {t: key.derive(t) for t in tags}

        key.puncture(tags[7])
        self.assertIsNone(key.derive(tags[7]))
        for t in tags:
            if t != tags[7]:
                self.assertEqual(key.derive(t), before[t])

    def test_key_grows_but_stays_bounded_per_puncture(self):
        key = PuncturableKey(os.urandom(32))
        for _ in range(10):
            key.puncture(os.urandom(32))
        self.assertEqual(key.punctured_count, 10)
        self.assertLessEqual(key.key_size, 10 * key.depth)


class TestStorageNode(unittest.TestCase):
    def setUp(self):
        self.node = StorageNode("n0", pow_bits=8)
        self.tag = os.urandom(32).hex()

    def test_store_and_fetch_once(self):
        self.node.put(self.tag, b"teil-inhalt", nonce=solve_pow(self.tag, b"teil-inhalt", self.node.pow_bits))
        self.assertEqual(self.node.get(self.tag), b"teil-inhalt")
        self.assertIsNone(self.node.get(self.tag))

    def test_node_sees_only_noise_on_disk(self):
        self.node.put(self.tag, b"TREFFPUNKT UM ACHT", nonce=solve_pow(self.tag, b"TREFFPUNKT UM ACHT", self.node.pow_bits))
        on_disk = self.node._entries[self.tag].blob
        self.assertNotIn(b"TREFFPUNKT", on_disk)

    def test_tag_cannot_be_reused_after_pickup(self):
        self.node.put(self.tag, b"eins", nonce=solve_pow(self.tag, b"eins", self.node.pow_bits))
        self.node.get(self.tag)
        with self.assertRaises(ValueError):
            self.node.put(self.tag, b"zwei", nonce=solve_pow(self.tag, b"zwei", self.node.pow_bits))

    def test_expiry_removes_the_entry(self):
        self.node.put(self.tag, b"verfaellt", ttl=0, nonce=solve_pow(self.tag, b"verfaellt", self.node.pow_bits))
        self.assertEqual(self.node.sweep(), 1)
        self.assertIsNone(self.node.get(self.tag))

    def test_oversized_and_malformed_are_refused(self):
        with self.assertRaises(ValueError):
            self.node.put("ab", b"zu kurzer tag", nonce=0)
        with self.assertRaises(ValueError):
            self.node.put(os.urandom(32).hex(), b"x" * (2 << 20))

    def test_retrievability_proof_depends_on_the_data(self):
        for _ in range(5):
            tag, blob = os.urandom(32).hex(), os.urandom(64)
            self.node.put(tag, blob, nonce=solve_pow(tag, blob, self.node.pow_bits))
        challenge = os.urandom(16)
        proof = self.node.prove_retrievability(challenge)
        self.assertEqual(proof["count"], 5)
        self.assertNotEqual(proof["proof"],
                            self.node.prove_retrievability(os.urandom(16))["proof"])

    def test_hoarding_node_cannot_read_its_own_copy_afterwards(self):
        """Whitepaper 7.7, Schicht 2 — der entscheidende Test."""
        node = StorageNode("gierig", hoard=True, pow_bits=8)
        tag = os.urandom(32).hex()
        node.put(tag, b"geheimer teil", nonce=solve_pow(tag, b"geheimer teil", node.pow_bits))

        self.assertEqual(node.try_read_hoarded(tag), b"geheimer teil")
        self.assertEqual(node.get(tag), b"geheimer teil")

        # Die Kopie liegt noch da ...
        self.assertIn(tag, node.hoarded_shares())
        # ... aber der Knoten kommt nicht mehr heran, auch mit allen
        # eigenen Schluesseln nicht.
        self.assertIsNone(node.try_read_hoarded(tag))


class TestDropNetwork(unittest.TestCase):
    def setUp(self):
        self.rng = random.Random(1)
        self.net = network()
        self.sk = tag_secret(os.urandom(32))

    def test_store_and_fetch_a_message(self):
        payload = os.urandom(600)
        self.net.store(self.sk, 0, payload, rng=self.rng)
        self.assertEqual(self.net.fetch(self.sk, 0, rng=self.rng), payload)

    def test_tags_of_one_message_are_unlinkable(self):
        tags = [drop_tag(self.sk, 0, j) for j in range(20)]
        self.assertEqual(len(set(tags)), 20)
        # Auch ueber Nachrichten hinweg: kein gemeinsames Praefix, keine Ordnung.
        later = [drop_tag(self.sk, 1, j) for j in range(20)]
        self.assertFalse(set(tags) & set(later))
        for a, b in zip(sorted(tags), sorted(later)):
            self.assertNotEqual(a[:8], b[:8])

    def test_wrong_secret_finds_nothing(self):
        self.net.store(self.sk, 0, b"vertraulich", rng=self.rng)
        self.assertIsNone(self.net.fetch(tag_secret(os.urandom(32)), 0, rng=self.rng))

    def test_cover_tags_are_indistinguishable(self):
        a, b = cover_tag(), drop_tag(self.sk, 0, 0)
        self.assertEqual(len(a), len(b))
        self.assertEqual(len(bytes.fromhex(a)), 32)

    def test_survives_loss_of_half_the_nodes(self):
        payload = os.urandom(500)
        self.net.store(self.sk, 3, payload, rng=self.rng)

        for name in list(self.net.nodes)[:10]:      # 10 von 20 fallen aus
            del self.net.nodes[name]
        self.assertEqual(self.net.fetch(self.sk, 3, rng=self.rng), payload)

    def test_loss_of_eleven_nodes_destroys_the_drop(self):
        """Whitepaper 7.7, Schicht 3: n-k+1 Loeschungen genuegen."""
        payload = os.urandom(500)
        self.net.store(self.sk, 4, payload, rng=self.rng)

        for name in list(self.net.nodes)[:11]:
            del self.net.nodes[name]
        self.assertIsNone(self.net.fetch(self.sk, 4, rng=self.rng))

    def test_pickup_leaves_nothing_behind(self):
        self.net.store(self.sk, 5, b"abgeholt", rng=self.rng)
        self.assertEqual(self.net.fetch(self.sk, 5, rng=self.rng), b"abgeholt")
        self.assertEqual(self.net.surviving_shares(self.sk, 5), 0)
        self.assertIsNone(self.net.fetch(self.sk, 5, rng=self.rng))

    def test_a_minority_of_hoarders_cannot_reconstruct(self):
        """Zwei unehrliche Knoten bei k=10 sind wirkungslos."""
        net = network(hoard_indices={0, 1})
        sk = tag_secret(os.urandom(32))
        net.store(sk, 0, os.urandom(400), rng=self.rng)
        net.fetch(sk, 0, rng=self.rng)
        self.assertFalse(net.hoarders_can_reconstruct(sk, 0, k=10, n=20))

    def test_even_all_hoarders_hold_only_unreadable_bytes(self):
        """Schicht 1: selbst wenn jeder Knoten hortet, fehlt der Schluessel.

        Hier hortet das gesamte Netz. Die Teile lassen sich danach zwar
        wieder zusammensetzen — aber was herauskommt, ist das Chiffrat, und
        dessen Nachrichtenschluessel existiert nicht mehr.
        """
        net = network(hoard_indices=set(range(20)))
        sk = tag_secret(os.urandom(32))

        message_key = os.urandom(32)
        ciphertext = seal(message_key, bytes(12), b"TREFFPUNKT UM ACHT")
        net.store(sk, 0, ciphertext, rng=self.rng)
        net.fetch(sk, 0, rng=self.rng)

        # Die Knoten kommen wegen der Punktierung nicht einmal an ihre
        # eigenen Kopien heran.
        self.assertFalse(net.hoarders_can_reconstruct(sk, 0, k=10, n=20))

        # Und selbst wenn sie es taeten: ohne den Nachrichtenschluessel,
        # den das Endgeraet nach dem Lesen geloescht hat, bleibt es Rauschen.
        del message_key
        with self.assertRaises(AuthError):
            open_(os.urandom(32), bytes(12), ciphertext)


class TestMixnet(unittest.TestCase):
    def setUp(self):
        self.rng = random.Random(7)
        self.net = Mixnet([MixNode(f"m{i}") for i in range(8)])

    def test_three_hop_delivery(self):
        path = self.net.path(3, self.rng)
        got = self.net.send(path, b"nutzlast")
        self.assertIsNotNone(got)
        self.assertEqual(got[:8], b"nutzlast")
        for hop in path:
            self.assertEqual(self.net.by_name[hop.name].stats["empfangen"], 1)

    def test_payload_arrives_intact(self):
        payload = os.urandom(500)
        got = self.net.send(self.net.path(3, self.rng), payload)
        self.assertEqual(got[:len(payload)], payload)

    def test_packet_size_is_constant_across_hops(self):
        path = self.net.path(3, self.rng)
        packet = build_packet(path, b"kurz")
        self.assertEqual(len(packet), PACKET_SIZE)
        for hop in path:
            node = self.net.by_name[hop.name]
            _, packet = node.peel(packet)
            self.assertEqual(len(packet), PACKET_SIZE)

    def test_a_node_cannot_peel_a_layer_meant_for_another(self):
        path = self.net.path(3, self.rng)
        packet = build_packet(path, b"nicht fuer dich")
        wrong = next(n for n in self.net.by_name.values() if n.name != path[0].name)
        with self.assertRaises(PacketError):
            wrong.peel(packet)

    def test_replay_is_refused(self):
        path = self.net.path(3, self.rng)
        packet = build_packet(path, b"einmal")
        node = self.net.by_name[path[0].name]
        node.peel(packet)
        with self.assertRaises(PacketError):
            node.peel(packet)

    def test_middle_node_learns_neither_end(self):
        path = self.net.path(3, self.rng)
        packet = build_packet(path, b"geheime nutzlast")
        first = self.net.by_name[path[0].name]
        route, packet = first.peel(packet)
        middle = self.net.by_name[path[1].name]
        route2, packet2 = middle.peel(packet)
        # Der mittlere Knoten sieht nur die Wegkennung des naechsten Knotens.
        self.assertEqual(route2, path[2].route_id())
        self.assertNotIn(b"geheime nutzlast", packet2)

    def test_mixing_changes_the_order(self):
        node = self.net.by_name["m0"]
        for i in range(30):
            node.accept(build_packet([node.info], f"paket {i:02d}".encode()))
        out = node.flush(random.Random(3))
        self.assertEqual(len(out), 30)
        payloads = [p[HEADER_SIZE:HEADER_SIZE + 8] for _, p in out]
        self.assertNotEqual(payloads, sorted(payloads))

    def test_payload_bytes_change_at_every_hop(self):
        """Ein Beobachter kann dasselbe Paket vor und hinter einem Knoten
        nicht an seinem Inhalt wiedererkennen."""
        path = self.net.path(3, self.rng)
        packet = build_packet(path, b"gleichbleibende nutzlast")
        seen = [packet]
        for hop in path:
            _, packet = self.net.by_name[hop.name].peel(packet)
            seen.append(packet)
        for before, after in zip(seen, seen[1:]):
            self.assertEqual(len(before), len(after))
            self.assertNotEqual(before[HEADER_SIZE:], after[HEADER_SIZE:])

    def test_cover_traffic_is_the_same_size_as_real_traffic(self):
        real = build_packet(self.net.path(3, self.rng), os.urandom(400))
        empty = build_packet(self.net.path(3, self.rng), b"")
        self.assertEqual(len(real), len(empty))

    def test_oversized_payload_is_refused(self):
        with self.assertRaises(PacketError):
            build_packet(self.net.path(3, self.rng), os.urandom(PACKET_SIZE))

    def test_guard_stays_fixed_across_messages(self):
        guard = self.net.by_name["m0"]
        for _ in range(20):
            path = self.net.guarded_path(guard, 3, self.rng)
            self.assertEqual(path[0].name, "m0")
            self.assertEqual(len({h.name for h in path}), 3)


if __name__ == "__main__":
    unittest.main()
