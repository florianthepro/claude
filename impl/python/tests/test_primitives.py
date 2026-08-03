"""Testvektoren aus RFC 7748, RFC 8032 und RFC 8439.

Diese Vektoren sind der Nachweis, dass die reine Python-Implementierung
byteweise dasselbe rechnet wie libsodium (PHP) und WebCrypto (Browser).
Faellt einer dieser Tests, ist die Interoperabilitaet gebrochen.
"""

import unittest

from nyx.primitives import aead, curve25519 as c25519
from nyx.primitives.kdf import Secret, hkdf, zeroize


def h(s: str) -> bytes:
    return bytes.fromhex(s)


class TestX25519(unittest.TestCase):
    def test_rfc7748_vector1(self):
        # RFC 7748, Abschnitt 5.2
        k = h("a546e36bf0527c9d3b16154b82465edd62144c0ac1fc5a18506a2244ba449ac4")
        u = h("e6db6867583030db3594c1a424b15f7c726624ec26b3353b10a903a6d0ab1c4c")
        want = h("c3da55379de9c6908e94ea4df28d084f32eccf03491c71f754b4075577a28552")
        self.assertEqual(c25519.x25519(k, u), want)

    def test_rfc7748_vector2(self):
        k = h("4b66e9d4d1b4673c5ad22691957d6af5c11b6421e0ea01d42ca4169e7918ba0d")
        u = h("e5210f12786811d3f4b7959d0538ae2c31dbe7106fc03c3efc4cd549c715a493")
        want = h("95cbde9476e8907d7aade45cb4b873f88b595a68799fa152e6f8f7647aac7957")
        self.assertEqual(c25519.x25519(k, u), want)

    def test_rfc7748_diffie_hellman(self):
        alice_priv = h("77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a")
        bob_priv = h("5dab087e624a8a4b79e17f8b83800ee66f3bb1292618b6fd1c2f8b27ff88e0eb")
        alice_pub = h("8520f0098930a754748b7ddcb43ef75a0dbf3a0d26381af4eba4a98eaa9b4e6a")
        bob_pub = h("de9edb7d7b7dc1b4d35b61c2ece435373f8343c85b78674dadfc7e146f882b4f")
        shared = h("4a5d9d5ba4ce2de1728e3bf480350f25e07e21c947d19e3376f09b3c1e161742")

        self.assertEqual(c25519.x25519_public(alice_priv), alice_pub)
        self.assertEqual(c25519.x25519_public(bob_priv), bob_pub)
        self.assertEqual(c25519.x25519_shared(alice_priv, bob_pub), shared)
        self.assertEqual(c25519.x25519_shared(bob_priv, alice_pub), shared)

    def test_rejects_degenerate_point(self):
        priv, _ = c25519.x25519_keypair()
        with self.assertRaises(ValueError):
            c25519.x25519_shared(priv, bytes(32))


class TestEd25519(unittest.TestCase):
    def test_rfc8032_empty_message(self):
        seed = h("9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60")
        pub = h("d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a")
        sig = h(
            "e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e06522490155"
            "5fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b"
        )
        self.assertEqual(c25519.ed25519_public(seed), pub)
        self.assertEqual(c25519.ed25519_sign(seed, b""), sig)
        self.assertTrue(c25519.ed25519_verify(pub, b"", sig))

    def test_rfc8032_one_byte_message(self):
        seed = h("4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb")
        pub = h("3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c")
        msg = h("72")
        sig = h(
            "92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da"
            "085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00"
        )
        self.assertEqual(c25519.ed25519_public(seed), pub)
        self.assertEqual(c25519.ed25519_sign(seed, msg), sig)
        self.assertTrue(c25519.ed25519_verify(pub, msg, sig))

    def test_rejects_tampered_signature_and_message(self):
        seed, pub = c25519.ed25519_keypair()
        msg = b"prekey-buendel"
        sig = bytearray(c25519.ed25519_sign(seed, msg))
        self.assertTrue(c25519.ed25519_verify(pub, msg, bytes(sig)))

        sig[0] ^= 1
        self.assertFalse(c25519.ed25519_verify(pub, msg, bytes(sig)))
        self.assertFalse(c25519.ed25519_verify(pub, b"anderer text",
                                               c25519.ed25519_sign(seed, msg)))


class TestChaCha20Poly1305(unittest.TestCase):
    def test_rfc8439_aead_vector(self):
        # RFC 8439, Abschnitt 2.8.2
        key = h("808182838485868788898a8b8c8d8e8f909192939495969798999a9b9c9d9e9f")
        nonce = h("070000004041424344454647")
        aad = h("50515253c0c1c2c3c4c5c6c7")
        plain = (
            b"Ladies and Gentlemen of the class of '99: If I could offer you "
            b"only one tip for the future, sunscreen would be it."
        )
        want_ct = h(
            "d31a8d34648e60db7b86afbc53ef7ec2a4aded51296e08fea9e2b5a736ee62d6"
            "3dbea45e8ca9671282fafb69da92728b1a71de0a9e060b2905d6a5b67ecd3b36"
            "92ddbd7f2d778b8c9803aee328091b58fab324e4fad675945585808b4831d7bc"
            "3ff4def08e4b7a9de576d26586cec64b6116"
        )
        want_tag = h("1ae10b594f09e26a7e902ecbd0600691")

        boxed = aead.seal(key, nonce, plain, aad)
        self.assertEqual(boxed[:-16], want_ct)
        self.assertEqual(boxed[-16:], want_tag)
        self.assertEqual(aead.open_(key, nonce, boxed, aad), plain)

    def test_rejects_tampered_ciphertext_and_aad(self):
        key, nonce = bytes(range(32)), bytes(range(12))
        boxed = bytearray(aead.seal(key, nonce, b"nachricht", b"kopf"))
        self.assertEqual(aead.open_(key, nonce, bytes(boxed), b"kopf"), b"nachricht")

        with self.assertRaises(aead.AuthError):
            aead.open_(key, nonce, bytes(boxed), b"anderer kopf")

        boxed[2] ^= 0x40
        with self.assertRaises(aead.AuthError):
            aead.open_(key, nonce, bytes(boxed), b"kopf")


class TestKdf(unittest.TestCase):
    def test_label_separates_outputs(self):
        ikm = b"gemeinsames geheimnis"
        self.assertNotEqual(hkdf(ikm, "nyx/v1/chain"), hkdf(ikm, "nyx/v1/root"))
        self.assertEqual(hkdf(ikm, "nyx/v1/chain"), hkdf(ikm, "nyx/v1/chain"))
        self.assertEqual(len(hkdf(ikm, "nyx/v1/x", 64)), 64)

    def test_zeroize_overwrites_in_place(self):
        buf = bytearray(b"geheim")
        zeroize(buf)
        self.assertEqual(bytes(buf), bytes(6))

    def test_secret_destroys_and_never_prints_value(self):
        s = Secret(b"schluesselmaterial")
        self.assertIn(b"schluesselmaterial", s.bytes())
        self.assertNotIn("schluessel", repr(s).lower().replace("schluessel wurde", ""))
        s.destroy()
        self.assertFalse(s.alive)
        with self.assertRaises(ValueError):
            s.bytes()

    def test_secret_context_manager_destroys(self):
        with Secret(b"kurzlebig") as s:
            self.assertTrue(s.alive)
        self.assertFalse(s.alive)


if __name__ == "__main__":
    unittest.main()
