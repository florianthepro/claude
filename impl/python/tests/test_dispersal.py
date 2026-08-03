"""Zerlegung, Schwelle und Alles-oder-nichts.

Die Tests hier pruefen beide Richtungen derselben Schwelle: dass k Teile
genuegen (Verfuegbarkeit) und dass k-1 Teile nichts hergeben
(Wertlosigkeit).
"""

import itertools
import os
import unittest

from nyx import gf256
from nyx.dispersal import (
    DEFAULT_K,
    DEFAULT_N,
    Share,
    aont_invert,
    aont_transform,
    combine,
    destroyed,
    disperse,
    reassemble,
    split,
)


class TestField(unittest.TestCase):
    def test_multiplication_and_inverse(self):
        for a in range(1, 256):
            self.assertEqual(gf256.mul(a, gf256.inv(a)), 1)
        self.assertEqual(gf256.mul(0, 123), 0)

    def test_matrix_inversion(self):
        m = gf256.vandermonde(6, 6)
        product = gf256.matmul(m, gf256.invert(m))
        self.assertEqual(product, gf256.identity(6))

    def test_singular_matrix_is_reported(self):
        with self.assertRaises(ValueError):
            gf256.invert([[0, 0], [0, 0]])


class TestAont(unittest.TestCase):
    def test_roundtrip(self):
        for size in (0, 1, 31, 32, 33, 1024, 5000):
            data = os.urandom(size)
            self.assertEqual(aont_invert(aont_transform(data)), data)

    def test_output_differs_every_time(self):
        data = b"derselbe klartext"
        self.assertNotEqual(aont_transform(data), aont_transform(data))

    def test_one_missing_byte_destroys_everything(self):
        data = b"A" * 512
        package = bytearray(aont_transform(data))
        package[0] ^= 0x01                       # ein einziges Bit
        recovered = aont_invert(bytes(package))
        # Kein Teilklartext, keine Struktur — der gesamte Ausgang kippt.
        self.assertNotEqual(recovered, data)
        matching = sum(1 for a, b in zip(recovered, data) if a == b)
        self.assertLess(matching, len(data) * 0.05)


class TestSplitCombine(unittest.TestCase):
    def test_any_k_of_n_suffice(self):
        data = os.urandom(2000)
        shares = disperse(data, k=3, n=6)
        for subset in itertools.combinations(shares, 3):
            self.assertEqual(reassemble(list(subset)), data)

    def test_default_parameters_roundtrip(self):
        data = os.urandom(4096)
        shares = disperse(data)
        self.assertEqual(len(shares), DEFAULT_N)
        self.assertEqual(reassemble(shares[:DEFAULT_K]), data)
        self.assertEqual(reassemble(shares[-DEFAULT_K:]), data)

    def test_below_threshold_yields_nothing(self):
        data = os.urandom(1000)
        shares = disperse(data, k=5, n=10)
        for count in range(5):
            with self.assertRaises(ValueError):
                reassemble(shares[:count])

    def test_k_minus_one_shares_reveal_no_plaintext(self):
        """Der eigentliche Punkt der AONT: kein Bruchstueck, sondern nichts."""
        plaintext = b"TREFFPUNKT UM ACHT" * 40
        shares = disperse(plaintext, k=4, n=8)
        visible = b"".join(s.data for s in shares[:3])
        self.assertNotIn(b"TREFFPUNKT", visible)
        self.assertNotIn(b"ACHT", visible)

    def test_deleting_n_minus_k_plus_one_shares_destroys_the_drop(self):
        """Whitepaper 7.7, Schicht 3: es muessen nicht alle loeschen."""
        data = os.urandom(800)
        k, n = 10, 20
        shares = disperse(data, k=k, n=n)

        surviving = shares[: k - 1]          # 11 von 20 haben geloescht
        self.assertTrue(destroyed(len(surviving), k))
        with self.assertRaises(ValueError):
            reassemble(surviving)

        # Eine Loeschung weniger, und sie ist noch da — die Schwelle ist scharf.
        self.assertFalse(destroyed(k, k))
        self.assertEqual(reassemble(shares[:k]), data)

    def test_corrupted_share_does_not_yield_the_original(self):
        data = os.urandom(600)
        shares = disperse(data, k=4, n=8)
        broken = list(shares[:4])
        payload = bytearray(broken[1].data)
        payload[0] ^= 0xFF
        broken[1] = Share(broken[1].index, broken[1].k, broken[1].n,
                          broken[1].length, bytes(payload))
        self.assertNotEqual(reassemble(broken), data)

    def test_share_serialisation_roundtrip(self):
        shares = disperse(b"unterwegs", k=2, n=4)
        wire = [s.to_dict() for s in shares]
        back = [Share.from_dict(d) for d in wire]
        self.assertEqual(reassemble(back[:2]), b"unterwegs")

    def test_storage_overhead_is_n_over_k(self):
        data = os.urandom(1000)
        shares = disperse(data, k=10, n=20)
        total = sum(len(s.data) for s in shares)
        self.assertLess(total, len(data) * 2.2)

    def test_shares_are_indistinguishable_from_noise_in_size(self):
        shares = disperse(os.urandom(1000), k=5, n=10)
        self.assertEqual(len({len(s.data) for s in shares}), 1)


class TestSplitValidation(unittest.TestCase):
    def test_bad_parameters(self):
        with self.assertRaises(ValueError):
            split(b"x", 0, 5)
        with self.assertRaises(ValueError):
            split(b"x", 6, 5)

    def test_mixed_dispersals_are_refused(self):
        a = disperse(b"eins", k=2, n=4)
        b = disperse(b"zwei", k=3, n=6)
        with self.assertRaises(ValueError):
            combine([a[0], b[0], b[1]])


if __name__ == "__main__":
    unittest.main()
