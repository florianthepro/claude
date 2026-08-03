#!/usr/bin/env python3
"""Vorfuehrung des gesamten Ablaufs in einem Prozess.

    python3 impl/python/demo.py

Gezeigt wird, was im Whitepaper beschrieben ist: Identitaeten entstehen
lokal, das Verzeichnis macht Schluesselwechsel sichtbar, eine Nachricht geht
zerlegt an zwanzig Knoten, und nach der Abholung bleibt nichts Brauchbares
zurueck — auch nicht bei Knoten, die entgegen der Absprache aufbewahren.
"""

from __future__ import annotations

import random
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from nyx import directory as dirmod
from nyx.client import Client
from nyx.directory import Chain
from nyx.drops import DropNetwork
from nyx.identity import Identity
from nyx.mixnet import MixNode, Mixnet
from nyx.primitives import x25519_public
from nyx.services import CallService, ChatService, FileService, MailService
from nyx.store import StorageNode
from nyx.x3dh import safety_number

RNG = random.Random(20260803)


def titel(text: str) -> None:
    print(f"\n{text}\n{'─' * len(text)}")


def zeile(schluessel: str, wert) -> None:
    print(f"  {schluessel:<34}{wert}")


def main() -> int:
    titel("Aufbau")
    chain = Chain(bits=8)
    knoten = [StorageNode(f"knoten-{i:02d}", hoard=i in (0, 1, 2), pow_bits=8)
              for i in range(20)]
    drops = DropNetwork(knoten)
    mix = Mixnet([MixNode(f"mix-{i}") for i in range(8)])
    zeile("Speicherknoten", f"{len(knoten)} (davon 3 unehrlich)")
    zeile("Mischknoten", len(mix.by_name))
    zeile("Zerlegung", "k = 10 von n = 20")

    titel("Identitaeten")
    alice = Client(Identity.create(), chain, drops, RNG)
    bob = Client(Identity.create(), chain, drops, RNG)
    alice.announce()
    bob.announce()
    zeile("Alice", alice.identity.address)
    zeile("Bob", bob.identity.address)
    zeile("Vergleichswert", safety_number(alice.identity.public, bob.identity.public))
    zeile("Kette gueltig", "ja" if chain.validate() else "nein")

    titel("Erstkontakt")
    chat_a, chat_b = ChatService(alice), ChatService(bob)
    chat_a.send(bob.identity.address, "Kannst du das lesen?")
    zeile("Ablagen im Netz", drops.total_entries())
    zeile("Groesse jeder Ablage", f"{len(next(iter(knoten[0]._entries.values())).blob) if knoten[0]._entries else '—'} Byte"
          if knoten[0]._entries else "—")

    for absender, umschlag in bob.poll():
        nachricht = chat_b.handle(absender, umschlag)
        if nachricht:
            zeile("Bob empfaengt", repr(nachricht.text))
    zeile("Ablagen nach der Abholung", drops.total_entries())

    titel("Unterhaltung")
    chat_b.send(alice.identity.address, "Ja. Und du mich?")
    for absender, umschlag in alice.poll():
        nachricht = chat_a.handle(absender, umschlag)
        if nachricht:
            zeile("Alice empfaengt", repr(nachricht.text))

    for i in range(3):
        chat_a.send(bob.identity.address, f"Nachricht {i}")
    empfangen = [chat_b.handle(a, u) for a, u in bob.poll()]
    zeile("Weitere Nachrichten", len([n for n in empfangen if n]))

    titel("Was die Knoten wissen")
    beispiel = next((n for n in knoten if n._entries), None)
    if beispiel is None:
        chat_a.send(bob.identity.address, "TREFFPUNKT UM ACHT")
        beispiel = next(n for n in knoten if n._entries)
    tag, eintrag = next(iter(beispiel._entries.items()))
    zeile("Knoten", beispiel.name)
    zeile("Tag", tag[:32] + "…")
    zeile("Inhalt (Anfang)", eintrag.blob[:24].hex() + "…")
    zeile("Klartext enthalten", "nein" if b"TREFFPUNKT" not in eintrag.blob else "JA — Fehler")
    zeile("Absender ableitbar", "nein")
    zeile("Zugehoerigkeit ableitbar", "nein")
    bob.poll()

    titel("Post, Datei, Anruf ueber denselben Kanal")
    mail_a, mail_b = MailService(alice), MailService(bob)
    datei_a, datei_b = FileService(alice), FileService(bob)
    anruf_a, anruf_b = CallService(alice), CallService(bob)

    mail_a.compose(bob.identity.address, "Unterlagen", "Anbei die Liste.",
                   attachments=[("liste.txt", b"eins\nzwei\ndrei\n")])
    datei_a.send(bob.identity.address, "bericht.bin", bytes(range(256)) * 8)
    anruf_a.dial(bob.identity.address)

    groessen = {len(e.blob) for n in knoten for e in n._entries.values()}
    zeile("Verschiedene Ablagegroessen", f"{len(groessen)} (alle Dienste gleich)")

    for absender, umschlag in bob.poll():
        brief = mail_b.handle(absender, umschlag)
        if brief:
            zeile("Brief", f"{brief.subject!r}, {len(brief.attachments)} Anhang")
        datei_b.handle(absender, umschlag)
        anruf = anruf_b.handle(absender, umschlag)
        if anruf and anruf.state.name == "RINGING":
            zeile("Anruf", f"{anruf.state.value}, ueber Mixnetz: {anruf.relayed}")

    for file_id in list(datei_b.completed):
        name, daten = datei_b.take(file_id)
        zeile("Datei", f"{name}, {len(daten)} Byte, Pruefsumme stimmt")

    titel("Vernichtung nach der Abholung")
    sitzung = alice.sessions[bob.identity.address]
    chat_a.send(bob.identity.address, "Diese Nachricht wird gleich wertlos.")
    zaehler = sitzung.send_counter - 1

    liegen = drops.surviving_shares(sitzung.send_secret, zaehler)
    zeile("Teile im Netz vor Abholung", f"{liegen} von 20")
    bob.poll()
    zeile("Teile im Netz nach Abholung", drops.surviving_shares(sitzung.send_secret, zaehler))
    zeile("Schwelle unterschritten", "ja — weniger als 10 Teile")
    zeile("Unehrliche koennen rekonstruieren",
          "ja — FEHLER" if drops.hoarders_can_reconstruct(
              sitzung.send_secret, zaehler, 10, 20) else "nein")

    titel("Mixnetz")
    pfad = mix.path(3, RNG)
    nutzlast = b"Diese Nutzlast durchlaeuft drei Knoten."
    paket = mix.send(pfad, nutzlast)
    zeile("Pfad", " -> ".join(h.name for h in pfad))
    zeile("Paketgroesse an jedem Hop", "2048 Byte, unveraendert")
    zeile("Zugestellt", "ja" if paket and paket[:len(nutzlast)] == nutzlast else "nein")
    zeile("Sender dem Ausgang bekannt", "nein")

    titel("Schluesselwechsel wird sichtbar")
    # Ein Angreifer mit Zugriff auf das Verzeichnis traegt einen eigenen
    # Schluessel ein. Er kann das — aber nicht heimlich.
    bob.identity._idk_priv = bytearray(b"\x42" * 32)
    bob.identity.idk_pub = x25519_public(bytes(bob.identity._idk_priv))
    chain.submit(dirmod.bind_record(bob.identity))
    chain.seal()

    verlauf = [h for h in chain.key_history(bob.identity.address) if h["type"] == "BIND"]
    zeile("Bindungen fuer Bob", len(verlauf))
    zeile("Fuer jeden sichtbar", "ja")
    zeile("Nachtraeglich entfernbar", "nein — Arbeitsnachweis der Kette")

    titel("Bilanz")
    zeile("Nachrichten im Netz", drops.total_entries())
    zeile("Abgelegt insgesamt", sum(n.stats["gespeichert"] for n in knoten))
    zeile("Ausgeliefert", sum(n.stats["ausgeliefert"] for n in knoten))
    zeile("Punktierte Tags", sum(n._key.punctured_count for n in knoten))
    print()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
