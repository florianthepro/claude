"""Quelle D -- IT-Wurzel in genau fuenf Zeichen.

Quelle A wuerfelt sprechbare Formen, aber `pebad` und `kiged` haben keinen
IT-Bezug. Quelle B blendet zwei Morpheme und wird dabei fast immer laenger als
fuenf Zeichen. Fuer ein kurzes Label mit erkennbarem Fachbezug braucht es einen
eigenen Bau: eine echte IT-Wurzel bleibt sichtbar, der Rest fuellt zur Silbe auf.

    node + s        -> nodus ist nicht dabei (Wurzel bliebe nicht am Stueck)
    node -> nodes   Wurzel vorn, ein Buchstabe hinten
    s + node        -> snode
    bit + ra        -> bitra
    ra + bit        -> rabit

Es wird nichts gekreuzt, was die Wurzel zerstoert: die Wurzel steht immer
zusammenhaengend am Anfang oder am Ende.
"""

from __future__ import annotations

from collections.abc import Iterator

from ..filters import passes

TARGET_LEN = 5

# Wurzeln, die ein Mensch mit Serverbezug ohne Erklaerung erkennt.
# Buchstaben ausserhalb des diktiersicheren Alphabets sind hier schon
# ausgesiebt: kein root/boot (oo), kein hash/mesh (sh), kein sync (y).
IT_ROOTS: tuple[tuple[str, str], ...] = (
    ("bit", "Bit"), ("net", "Netz"), ("dat", "Daten"), ("log", "Log"),
    ("sub", "Subnetz"), ("bin", "Binaerdatei"), ("num", "Nummer"),
    ("dir", "Verzeichnis"), ("tmp", "temporaer"), ("usr", "/usr"),
    ("opt", "/opt"), ("lib", "Bibliothek"), ("pid", "Prozess-ID"),
    ("uid", "Benutzer-ID"), ("gid", "Gruppen-ID"), ("run", "/run"),
    ("git", "Git"), ("hub", "Hub"), ("ops", "Betrieb"), ("dev", "/dev"),
    ("pod", "Pod"), ("nat", "NAT"), ("lan", "LAN"), ("nas", "NAS"),
    ("mta", "Mail Transfer Agent"), ("ptr", "PTR-Record"),
    ("soa", "SOA-Record"), ("ttl", "Time to live"), ("mtu", "MTU"),
    ("disk", "Datentraeger"), ("dump", "Dump"), ("port", "Port"),
    ("host", "Host"), ("node", "Knoten"), ("link", "Link"),
    ("sudo", "sudo"), ("grep", "grep"), ("perl", "Perl"),
    ("ping", "ping"), ("stat", "stat(2)"), ("temp", "Temperatur, /tmp"),
    ("unit", "systemd-Unit"), ("pipe", "Pipe"), ("fork", "fork(2)"),
    ("salt", "kryptografisches Salt"), ("repo", "Repository"),
    ("helm", "Helm"), ("kube", "Kubernetes"), ("raid", "RAID"),
    ("halt", "halt(8)"), ("hold", "Hold Timer"), ("init", "init"),
    ("kern", "Kernel"), ("load", "Load"), ("mail", "Mail"),
    ("meta", "Metadaten"), ("mode", "Modus"), ("page", "Speicherseite"),
    ("path", "Pfad"), ("peer", "Peer"), ("rate", "Rate"),
    ("ring", "Ringpuffer"), ("rule", "Regel"), ("send", "senden"),
    ("sign", "Signatur"), ("site", "Site"), ("slot", "Slot"),
    ("snap", "Snapshot"), ("span", "Span"), ("spin", "Spinlock"),
    ("stop", "SIGSTOP"), ("swap", "Swap"), ("task", "Task"),
    ("term", "SIGTERM"), ("test", "Test"), ("tune", "tuning"),
    ("user", "Benutzer"), ("idle", "idle"), ("trap", "Trap"),
    ("fault", "Fault"), ("inode", "Inode"), ("proto", "Protokoll"),
    ("mount", "mount(8)"), ("stale", "Stale Handle"), ("umask", "umask(2)"),
    # Zweite Runde: die erste Wurzelliste war zu knapp. Praktisch jeder
    # Fuenfzeichner mit vierbuchstabiger Wurzel war vergeben, deshalb hier
    # deutlich mehr Material -- Paketwelt, Krypto, Netz, Hardware, Datenformate.
    ("apt", "apt"), ("rpm", "RPM"), ("deb", "Debian-Paket"), ("iso", "ISO-Abbild"),
    ("img", "Abbild"), ("pem", "PEM"), ("pgp", "PGP"), ("gpg", "GnuPG"),
    ("aes", "AES"), ("rsa", "RSA"), ("sha", "SHA"), ("otp", "Einmalkennwort"),
    ("mfa", "Mehrfaktor"), ("idp", "Identity Provider"), ("iam", "IAM"),
    ("bgp", "BGP"), ("rip", "RIP"), ("gre", "GRE-Tunnel"), ("tun", "TUN-Gerät"),
    ("tap", "TAP-Gerät"), ("sfp", "SFP-Modul"), ("poe", "Power over Ethernet"),
    ("ups", "USV"), ("pdu", "PDU"), ("psu", "Netzteil"), ("sata", "SATA"),
    ("sas", "SAS"), ("numa", "NUMA"), ("smp", "SMP"), ("dma", "DMA"),
    ("mmu", "MMU"), ("tlb", "TLB"), ("ini", "INI-Datei"), ("toml", "TOML"),
    ("rest", "REST"), ("soap", "SOAP"), ("ospf", "OSPF"), ("mpls", "MPLS"),
    ("blob", "Blob"), ("bulk", "Bulk"), ("flag", "Flag"), ("fold", "Ordner"),
    ("gate", "Gateway"), ("glob", "Glob-Muster"), ("grid", "Grid"),
    ("hint", "Root Hints"), ("input", "Eingabe"), ("label", "Label"),
    ("limit", "Limit"), ("line", "Zeile"), ("list", "Liste"), ("mark", "Marke"),
    ("mask", "Netzmaske"), ("meter", "Messwert"), ("model", "Modell"),
    ("multi", "multi"), ("probe", "Probe"), ("pulse", "Puls"), ("purge", "Purge"),
    ("range", "Bereich"), ("rank", "Rang"), ("redo", "Redo-Log"),
    ("reset", "Reset"), ("round", "Round Robin"), ("route", "Route"),
    ("smart", "S.M.A.R.T."), ("solid", "solid state"), ("sort", "Sortierung"),
    ("spike", "Lastspitze"), ("split", "Split"), ("stage", "Stage"),
    ("state", "Zustand"), ("step", "Schritt"), ("store", "Speicher"),
    ("strip", "Stripe"), ("table", "Tabelle"), ("tape", "Band"),
    ("tile", "Kachel"), ("timer", "Timer"), ("trunk", "Trunk"),
    ("tuple", "Tupel"), ("union", "Union"), ("usage", "Auslastung"),
    ("daemon", "Daemon"), ("shard", "Shard"), ("token", "Token"),
    ("parse", "Parser"), ("build", "Build"), ("patch", "Patch"),
)

LETTERS = "abdefghiklmnoprstu"


def _fillers(n: int) -> Iterator[str]:
    """Alle Fuellungen der Laenge n aus dem diktiersicheren Alphabet."""
    if n == 0:
        yield ""
        return
    if n == 1:
        yield from LETTERS
        return
    for a in LETTERS:
        for b in LETTERS:
            yield a + b


def generate() -> Iterator[tuple[str, str]]:
    """Liefert (label, herkunft) fuer alle gueltigen Fuenfzeichner."""
    seen: set[str] = set()
    for root, meaning in IT_ROOTS:
        gap = TARGET_LEN - len(root)
        if gap < 0:
            continue
        for fill in _fillers(gap):
            for label, where in ((root + fill, "vorn"), (fill + root, "hinten")):
                if len(label) != TARGET_LEN or label in seen:
                    continue
                seen.add(label)
                if passes(label):
                    yield label, f"{root} ({meaning}) steht {where}"


def roots_in(label: str) -> list[str]:
    """Welche IT-Wurzeln stehen zusammenhaengend am Rand des Labels?"""
    return [r for r, _ in IT_ROOTS if label.startswith(r) or label.endswith(r)]
