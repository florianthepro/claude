"""Ende-zu-Ende: zwei Clients, ein Verzeichnis, ein Speichernetz.

Diese Tests fahren den vollstaendigen Weg — Ankuendigung, Erstkontakt,
Unterhaltung, Datei, Post, Anruf — und pruefen dabei, was danach im Netz
zurueckbleibt.
"""

import random
import unittest

from nyx.client import Client
from nyx.directory import Chain
from nyx.drops import DropNetwork
from nyx.identity import Identity
from nyx.services import CallService, CallState, ChatService, FileService, MailService
from nyx.store import StorageNode


def world(nodes=20, hoard=()):
    chain = Chain(bits=6)
    drops = DropNetwork([StorageNode(f"n{i}", hoard=i in hoard, pow_bits=6)
                         for i in range(nodes)])
    return chain, drops


def two_clients(chain, drops):
    a = Client(Identity.create(), chain, drops, random.Random(11))
    b = Client(Identity.create(), chain, drops, random.Random(12))
    a.announce()
    b.announce()
    return a, b


class TestEndToEnd(unittest.TestCase):
    def setUp(self):
        self.chain, self.drops = world()
        self.alice, self.bob = two_clients(self.chain, self.drops)

    def test_first_contact_without_prior_arrangement(self):
        """Alice kennt nur Bobs Adresse — mehr braucht sie nicht."""
        self.alice.send(self.bob.address if hasattr(self.alice, "address")
                        else self.bob.identity.address,
                        {"t": "chat", "text": "hallo"})
        received = self.bob.poll()
        self.assertEqual(len(received), 1)
        sender, envelope = received[0]
        self.assertEqual(sender, self.alice.identity.address)
        self.assertEqual(envelope["text"], "hallo")

    def test_conversation_in_both_directions(self):
        addr_b = self.bob.identity.address
        addr_a = self.alice.identity.address

        self.alice.send(addr_b, {"t": "chat", "text": "erste"})
        self.assertEqual(self.bob.poll()[0][1]["text"], "erste")

        self.bob.send(addr_a, {"t": "chat", "text": "antwort"})
        self.assertEqual(self.alice.poll()[0][1]["text"], "antwort")

        for i in range(5):
            self.alice.send(addr_b, {"t": "chat", "text": f"a{i}"})
            self.bob.send(addr_a, {"t": "chat", "text": f"b{i}"})
        got_b = [e["text"] for _, e in self.bob.poll()]
        got_a = [e["text"] for _, e in self.alice.poll()]
        self.assertEqual(got_b, [f"a{i}" for i in range(5)])
        self.assertEqual(got_a, [f"b{i}" for i in range(5)])

    def test_network_is_empty_after_pickup(self):
        addr_b = self.bob.identity.address
        self.alice.send(addr_b, {"t": "chat", "text": "abholen und weg"})
        self.assertGreater(self.drops.total_entries(), 0)

        self.bob.poll()
        self.assertEqual(self.drops.total_entries(), 0)

    def test_stored_bytes_contain_no_plaintext(self):
        addr_b = self.bob.identity.address
        self.alice.send(addr_b, {"t": "chat", "text": "TREFFPUNKT UM ACHT"})
        for node in self.drops.nodes.values():
            for entry in node._entries.values():
                self.assertNotIn(b"TREFFPUNKT", entry.blob)

    def test_third_party_cannot_read_along(self):
        chain, drops = self.chain, self.drops
        eve = Client(Identity.create(), chain, drops, random.Random(13))
        eve.announce()
        self.alice.send(self.bob.identity.address, {"t": "chat", "text": "privat"})
        self.assertEqual(eve.poll(), [])


class TestChat(unittest.TestCase):
    def setUp(self):
        self.chain, self.drops = world()
        self.alice, self.bob = two_clients(self.chain, self.drops)
        self.chat_a = ChatService(self.alice)
        self.chat_b = ChatService(self.bob)

    def test_message_and_read_receipt(self):
        addr_b = self.bob.identity.address
        sent = self.chat_a.send(addr_b, "bist du da?")
        self.assertFalse(sent.delivered)

        for sender, env in self.bob.poll():
            self.chat_b.handle(sender, env)
        self.assertEqual(self.chat_b.conversation(self.alice.identity.address)[0].text,
                         "bist du da?")

        self.chat_b.acknowledge(self.alice.identity.address, int(sent.ts))
        for sender, env in self.alice.poll():
            self.chat_a.handle(sender, env)
        self.assertTrue(self.chat_a.conversation(addr_b)[0].delivered)

    def test_history_is_local_and_forgettable(self):
        addr_b = self.bob.identity.address
        self.chat_a.send(addr_b, "eins")
        self.chat_a.send(addr_b, "zwei")
        self.assertEqual(len(self.chat_a.conversation(addr_b)), 2)
        self.chat_a.forget(addr_b)
        self.assertEqual(self.chat_a.conversation(addr_b), [])


class TestFiles(unittest.TestCase):
    def test_transfer_and_checksum(self):
        chain, drops = world()
        alice, bob = two_clients(chain, drops)
        files_a, files_b = FileService(alice), FileService(bob)

        payload = bytes(range(256)) * 12          # 3072 Byte, vier Bloecke
        file_id = files_a.send(bob.identity.address, "notiz.bin", payload)

        for sender, env in bob.poll():
            files_b.handle(sender, env)

        got = files_b.take(file_id)
        self.assertIsNotNone(got)
        name, data = got
        self.assertEqual(name, "notiz.bin")
        self.assertEqual(data, payload)
        self.assertIsNone(files_b.take(file_id))   # genau einmal

    def test_missing_chunk_yields_nothing(self):
        chain, drops = world()
        alice, bob = two_clients(chain, drops)
        files_a, files_b = FileService(alice), FileService(bob)
        file_id = files_a.send(bob.identity.address, "x.bin", b"y" * 3000)

        envelopes = bob.poll()
        for sender, env in envelopes[:-1]:        # letzter Block fehlt
            files_b.handle(sender, env)
        self.assertIsNone(files_b.take(file_id))
        self.assertFalse(files_b.incoming[file_id].complete)


class TestMail(unittest.TestCase):
    def test_letter_with_attachment_and_reply(self):
        chain, drops = world()
        alice, bob = two_clients(chain, drops)
        mail_a, mail_b = MailService(alice), MailService(bob)

        mail_a.compose(bob.identity.address, "Treffen",
                       "Anbei die Unterlagen.",
                       attachments=[("liste.txt", b"eins\nzwei\n")])

        letters = [mail_b.handle(s, e) for s, e in bob.poll()]
        letter = next(l for l in letters if l)
        self.assertEqual(letter.subject, "Treffen")
        self.assertEqual(letter.attachments[0], ("liste.txt", b"eins\nzwei\n"))

        mail_b.reply(letter, "Habe ich, danke.")
        back = [mail_a.handle(s, e) for s, e in alice.poll()]
        answer = next(l for l in back if l)
        self.assertEqual(answer.subject, "Re: Treffen")
        self.assertEqual(answer.in_reply_to, letter.mail_id)

    def test_summary_line_is_readable(self):
        chain, drops = world()
        alice, bob = two_clients(chain, drops)
        mail_a = MailService(alice)
        letter = mail_a.compose(bob.identity.address, "Kurz", "Text")
        self.assertIn("Kurz", letter.summary())


class TestCalls(unittest.TestCase):
    def setUp(self):
        self.chain, self.drops = world()
        self.alice, self.bob = two_clients(self.chain, self.drops)
        self.calls_a, self.calls_b = CallService(self.alice), CallService(self.bob)

    def test_full_call_flow_and_shared_media_key(self):
        call = self.calls_a.dial(self.bob.identity.address, offer={"sdp": "…"})
        self.assertEqual(call.state, CallState.OUTGOING)

        incoming = [self.calls_b.handle(s, e) for s, e in self.bob.poll()]
        ringing = next(c for c in incoming if c)
        self.assertEqual(ringing.state, CallState.RINGING)

        self.calls_b.accept(ringing.call_id, answer={"sdp": "…"})
        for s, e in self.alice.poll():
            self.calls_a.handle(s, e)

        self.assertEqual(call.state, CallState.ACTIVE)
        # Beide Seiten leiten denselben Medienschluessel ab, ohne ihn zu senden.
        self.assertIsNotNone(call.media_key)
        self.assertEqual(call.media_key, self.calls_b.calls[call.call_id].media_key)

    def test_media_key_does_not_survive_hangup(self):
        call = self.calls_a.dial(self.bob.identity.address)
        incoming = next(c for c in
                        (self.calls_b.handle(s, e) for s, e in self.bob.poll()) if c)
        self.calls_b.accept(incoming.call_id)
        for s, e in self.alice.poll():
            self.calls_a.handle(s, e)

        self.calls_a.hang_up(call.call_id)
        self.assertEqual(call.state, CallState.ENDED)
        self.assertIsNone(call.media_key)

        for s, e in self.bob.poll():
            self.calls_b.handle(s, e)
        self.assertIsNone(self.calls_b.calls[call.call_id].media_key)

    def test_media_key_is_not_transmitted(self):
        self.calls_a.dial(self.bob.identity.address)
        for node in self.drops.nodes.values():
            for entry in node._entries.values():
                self.assertNotIn(b"media", entry.blob.lower())

    def test_relayed_is_the_default(self):
        call = self.calls_a.dial(self.bob.identity.address)
        self.assertTrue(call.relayed)

    def test_unanswered_call_expires(self):
        self.calls_a.dial(self.bob.identity.address)
        for call in self.calls_a.calls.values():
            call.started -= 120
        self.assertEqual(self.calls_a.expire(), 1)


class TestMixedTraffic(unittest.TestCase):
    def test_all_services_share_one_indistinguishable_channel(self):
        """Chat, Datei, Post und Anruf sehen im Speichernetz gleich aus."""
        chain, drops = world()
        alice, bob = two_clients(chain, drops)
        addr_b = bob.identity.address

        ChatService(alice).send(addr_b, "kurz")
        MailService(alice).compose(addr_b, "Betreff", "Langer Text")
        FileService(alice).send(addr_b, "d.bin", b"z" * 2000)
        CallService(alice).dial(addr_b)

        sizes = {len(e.blob) for node in drops.nodes.values()
                 for e in node._entries.values()}
        # Alle abgelegten Teile haben dieselbe Groesse — die Dienstart ist
        # an der Ablage nicht ablesbar.
        self.assertEqual(len(sizes), 1)


if __name__ == "__main__":
    unittest.main()
