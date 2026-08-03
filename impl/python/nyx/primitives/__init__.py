"""Kryptographische Primitive der Referenzimplementierung.

Alles hier ist reines Python und ohne Fremdpakete lauffaehig. Es ist auf
Lesbarkeit und Nachpruefbarkeit ausgelegt, nicht auf Geschwindigkeit oder
Seitenkanalfestigkeit — siehe docs/sicherheitshinweis.md.
"""

from .aead import AuthError, open_, seal
from .curve25519 import (
    ed25519_keypair,
    ed25519_public,
    ed25519_sign,
    ed25519_verify,
    x25519_keypair,
    x25519_public,
    x25519_shared,
)
from .kdf import Secret, hkdf, hmac_sha256, sha256, zeroize

__all__ = [
    "AuthError",
    "Secret",
    "ed25519_keypair",
    "ed25519_public",
    "ed25519_sign",
    "ed25519_verify",
    "hkdf",
    "hmac_sha256",
    "open_",
    "seal",
    "sha256",
    "x25519_keypair",
    "x25519_public",
    "x25519_shared",
    "zeroize",
]
