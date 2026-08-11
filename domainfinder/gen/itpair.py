"""Quelle H -- zwei IT-Kuerzel, sechs Zeichen.

Quelle D setzt eine IT-Wurzel und fuellt mit beliebigen Buchstaben auf. Bei
fuenf Zeichen bleibt davon `libuf`, `datpe`, `gidke` -- die Wurzel steht da,
aber der Rest ist Rauschen, und der Fachbezug verschwindet darin.

Sechs Zeichen erlauben etwas Besseres: **zwei** Kuerzel, die beide fuer sich
etwas bedeuten. Dann ist nichts mehr Fuellsel, und der Bezug ist auf den
ersten Blick da.

    bit + log   -> bitlog
    net + hub   -> nethub
    pod + net   -> podnet

Ausserdem die echten Begriffe dieser Laenge, die es wirklich gibt: `setuid`,
`initrd`, `bitmap`, `bitset`, `sigint`, `gitops`. Die stehen nicht in der
Kombinatorik, weil sie keine sind.
"""

from __future__ import annotations

from collections.abc import Iterator

from ..filters import passes

# Kuerzel, die ein Mensch mit Serverbezug ohne Erklaerung liest. Drei Zeichen,
# damit ein Paar genau sechs ergibt.
KUERZEL: dict[str, str] = {
    "bit": "Bit", "net": "Netz", "log": "Log", "dat": "Daten", "dir": "Verzeichnis",
    "sub": "Subnetz", "bin": "Binaerdatei", "num": "Nummer", "pid": "Prozess-ID",
    "uid": "Benutzer-ID", "gid": "Gruppen-ID", "git": "Git", "hub": "Hub",
    "ops": "Betrieb", "pod": "Pod", "nat": "NAT", "lan": "LAN", "nas": "NAS",
    "tun": "TUN-Geraet", "tap": "TAP-Geraet", "sfp": "SFP-Modul",
    "poe": "Power over Ethernet", "ups": "USV", "pdu": "PDU", "psu": "Netzteil",
    "dma": "DMA", "mmu": "MMU", "tlb": "TLB", "api": "API", "url": "URL",
    "uri": "URI", "tld": "TLD", "idn": "IDN", "mtu": "MTU", "ttl": "Time to live",
    "soa": "SOA-Record", "ptr": "PTR-Record", "mta": "Mail Transfer Agent",
    "aes": "AES", "rsa": "RSA", "sha": "SHA", "otp": "Einmalkennwort",
    "mfa": "Mehrfaktor", "idp": "Identity Provider", "iam": "IAM", "bgp": "BGP",
    "rip": "RIP", "gre": "GRE-Tunnel", "ini": "INI-Datei", "deb": "Debian-Paket",
    "rpm": "RPM", "apt": "apt", "iso": "ISO-Abbild", "img": "Abbild",
    "pem": "PEM", "pgp": "PGP", "gpg": "GnuPG", "run": "/run", "usr": "/usr",
    "opt": "/opt", "lib": "Bibliothek", "tmp": "/tmp", "raid": "RAID",
}

# Begriffe dieser Laenge, die es wirklich gibt. Keine Kombinatorik.
ECHTE: tuple[tuple[str, str], ...] = (
    ("setuid", "setuid-Bit"), ("setgid", "setgid-Bit"), ("initrd", "initrd"),
    ("bitmap", "Bitmap"), ("bitset", "Bitset"), ("bitrot", "Bit Rot"),
    ("bitnet", "BITNET, historisches Wissenschaftsnetz"),
    ("gitops", "GitOps"), ("netops", "NetOps"), ("linkup", "Link Up"),
    ("sigint", "SIGINT"), ("sigbus", "SIGBUS"), ("sigfpe", "SIGFPE"),
    ("sighup", "SIGHUP"), ("badsig", "DNS-Rcode 16, BADSIG"),
    ("badalg", "DNS-Rcode 21, BADALG"), ("notimp", "DNS-Rcode 4, NOTIMP"),
    ("badmode", "DNS-Rcode 19"), ("notdir", "errno 20, ENOTDIR"),
    ("enoent", "errno 2"), ("emfile", "errno 24"), ("enfile", "errno 23"),
    ("espipe", "errno 29"), ("emlink", "errno 31"), ("eproto", "errno 71"),
    ("estale", "errno 116"), ("erange", "errno 34"), ("eidrm", "errno 43"),
    ("netmap", "netmap"), ("bitrig", "Bitrig, BSD-Abkoemmling"),
    ("subnet", "Subnetz"), ("router", "Router"), ("daemon", "Daemon"),
    ("kernel", "Kernel"), ("inode", "Inode"), ("umask", "umask(2)"),
    ("fstab", "/etc/fstab"), ("dmesg", "dmesg"), ("nagle", "Nagle-Algorithmus"),
    ("bogon", "Bogon-Praefix"), ("proto", "Protokoll"), ("runit", "runit"),
    ("nohup", "nohup(1)"), ("pidof", "pidof(8)"), ("iotop", "iotop"),
    ("snapd", "snapd"), ("efbig", "errno 27, EFBIG"), ("erofs", "errno 30"),
    ("isdir", "errno 21, EISDIR"), ("noent", "errno 2, Kurzform"),
    ("nomem", "errno 12, Kurzform"), ("stale", "Stale File Handle"),
    ("netfs", "Netzdateisystem"), ("logfs", "LogFS"), ("tarpit", "SMTP-Tarpit"),
    ("hairpin", "NAT-Hairpinning"), ("nexthop", "BGP NEXT_HOP"),
)


def generate(laengen: tuple[int, ...] = (5, 6)) -> Iterator[tuple[str, str]]:
    """Liefert (label, herkunft). Erst die echten Begriffe, dann die Paare."""
    seen: set[str] = set()

    for label, bedeutung in ECHTE:
        if len(label) in laengen and label not in seen:
            seen.add(label)
            # Echte Begriffe duerfen eine Morphemfuge tragen (sig|hup).
            if passes(label, compound=True):
                yield label, f"echter Begriff: {bedeutung}"

    for a, bed_a in KUERZEL.items():
        for b, bed_b in KUERZEL.items():
            if a == b:
                continue
            label = a + b
            if len(label) not in laengen or label in seen:
                continue
            seen.add(label)
            if passes(label, compound=True):
                yield label, f"{a} ({bed_a}) + {b} ({bed_b})"
