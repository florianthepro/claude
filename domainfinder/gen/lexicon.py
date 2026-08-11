"""Eingebettetes Sprachmaterial.

Kein Netz, keine Datendateien: der Korpus fuer die Bigramm-Statistik und die
kuratierten Morphemlisten stehen hier. Das haelt den Lauf reproduzierbar.
"""

from __future__ import annotations

# --- Korpus fuer Bigramm-/Trigramm-Statistik ---------------------------------
# Gemischt englisch/deutsch, plus Infrastruktur- und Produktvokabular. Zweck ist
# nicht Vollstaendigkeit, sondern eine belastbare Verteilung ueblicher Uebergaenge.
CORPUS = """
about after again against all almost alone along already also although always among
another answer any appear around ask away back bad because become before begin behind
believe below best better between big body book both bring build business call can
carry case center certain change child choose city clear close come common company
consider continue control could country course create current data day decide deep
develop different direct door down draw during each early earth easy education effect
either enough enter even ever every example expect experience explain face fact fall
family far father feel field figure fill find fire first fish follow food foot force
form forward friend from front full game general get girl give glass good govern great
green ground group grow hand happen hard head hear heart heavy help high history hold
home hope horse hour house however human hundred idea important include increase
indicate industry inside interest into issue keep kind king know land language large
last late later laugh lead learn least leave left less letter level life light like
line list listen little live local long look lot love machine main major make man many
mark market master material matter mean measure meet member method middle might mind
minute miss model modern moment money month more morning most mother mountain move
much music must name nation nature near need never next night north note nothing
notice number object observe often only open order other paper part particular pass
past pattern people perhaps period person picture piece place plan plant play point
police political poor popular position possible power prepare present president press
pretty probable problem produce product program provide public pull purpose put
question quick quiet radio raise range rate reach read ready real reason receive record
red region remain remember report represent require rest result return right river road
rock room rule run safe same school science sea season seat second section seem sell
send sense separate serve service set several shape share short should side sign
similar simple since single sister sit size skin small social some son song sound
south space speak special stand standard star start state station stay step still stop
store story street strong student study subject success such suggest summer support
sure surface system table take talk teach team tell term test than then there these
thing think third though thought three through time today together too took top total
toward town trade train tree trouble true turn type under understand unit until upon
use usual value various very view visit voice wait walk want war watch water way weight
well west what when where which while white whole why wide will wind window wish with
within without woman wonder wood word work world would write year young
aber alle allein alles also alt andere anfang antwort arbeit auch auf aus bald bauen
bedeuten beginnen bei beide beispiel bekommen benutzen berg beruf besser bild bis
bitte bleiben boden bringen buch dann denken denn deutsch dienst ding doch dort drei
druck dunkel durch eigen eine einfach einmal ende endlich entwickeln erde erst fahren
fall fassen fehlen feld fenster fertig finden folgen form frage frei freund frueh
fuehren fuenf ganz gebaeude geben gedanke gefahr gehen geld gemein genau gerade gericht
gern geschichte gesetz gestalt gesund gewinnen glauben gleich glueck grad gross grund
gut haben halb halten hand handel hart haus heben heute hoch hoeren hoffen jahr jeder
jetzt kalt kaum kein kennen kind klar klein kommen koennen kopf kraft kreis kurz lang
lassen laufen leben legen leicht leisten lernen lesen letzte leute licht liegen linie
machen mann meist mensch messen mitte mittel moeglich morgen muessen nach nahe name
neben nehmen nennen neu nicht noch norden nur oben oder offen ohne ordnung platz
punkt recht regel reich reihe richtig sache sagen schaffen schein schnell schon
schreiben schritt sehen sehr sein seit selbst setzen sicher sinn sitzen sollen sonder
spiel sprache stadt stark stehen stelle stern stoff strasse stunde suchen system tag
teil tragen treffen tun ueber uhr und unter ursache verstehen viel vier voll vorne
waehrend wahr wandel wasser weg weil weit welt wenden wenig werden werk wert wesen
wetter wichtig wieder wille wirken wissen wohnen wollen wort zahl zeigen zeit ziehen
ziel zimmer zusammen zustand zwei zwischen
server cluster kernel daemon socket buffer packet router switch bridge tunnel proxy
cache index query schema table column record commit branch merge rebase deploy build
runtime compile binary library module import export handler request response session
token secret cipher digest signature certificate keypair rotate revoke audit policy
quota tenant region shard replica primary standby failover backup restore snapshot
volume mount inode journal partition filesystem storage archive checksum integrity
monitor metric logging tracing sampling alerting dashboard threshold baseline anomaly
pipeline scheduler worker queue broker stream topic offset consumer producer ingest
gateway ingress egress subnet netmask firewall balancer resolver registrar registry
container image layer manifest digest overlay namespace control plane node pod service
grafana traefik komodo portainer immich caddy nomad consul vault packer terraform
ansible puppet salt nginx apache postgres redis kafka minio ceph gluster zfs btrfs
docker podman kubernetes helm argo flux istio linkerd envoy prometheus loki tempo
""".split()

# --- Morpheme fuer Quelle B ---------------------------------------------------
# Bau-, Bergbau-, Schiffs- und Geologievokabular. Konkret, kein Marketing.
# Nicht diktiersichere Eintraege bleiben absichtlich drin: der harte Filter
# raeumt sie weg, und der Ueberlappungs-Merge kann sie trotzdem nutzbar machen.
MORPHEMES_MATTER: tuple[str, ...] = (
    # Tragwerk und Bau
    "purlin", "lintel", "kingpost", "mortise", "tenon", "girder", "rafter", "joist",
    "gable", "dormer", "strut", "flange", "spigot", "abutment", "parapet", "pilaster",
    "mantel", "transom", "stringer", "corbel", "batten", "brace", "plinth", "soffit",
    "truss", "beam", "post", "stud", "sill", "ridge", "eaves", "gusset", "haunch",
    "footing", "pier", "span", "arch", "keystone", "buttress", "camber", "splice",
    # Schiff
    "keel", "keelson", "mast", "topmast", "foremast", "spar", "helm", "tiller", "bilge",
    "outhaul", "halyard", "fairlead", "bulkhead", "gunnel", "thole", "oakum", "stern",
    "prow", "hull", "rudder", "boom", "gaff", "sheet", "shroud", "stay", "chock",
    # Gestein und Grund
    "basalt", "granite", "slate", "shale", "marl", "loam", "karst", "moraine", "talus",
    "feldspar", "dolomite", "stratum", "sinter", "tundra", "gabbro", "gneiss", "pumice",
    "quarry", "outcrop", "bedrock", "sediment", "alluvium", "silt", "gravel", "flint",
    "anvil", "pylon", "cairn", "scarp", "ledge", "seam", "lode", "adit", "gallery",
    # Werkzeug und Handwerk
    "auger", "chisel", "mallet", "plumb", "trammel", "caliper", "gauge", "lathe",
    "forge", "temper", "anneal", "solder", "rivet", "dowel", "shim", "gasket",
)

# Wolken, Wind und Wetter. Fuer eine Cloud-Domain ist das die naheliegende
# Bildspende -- `cloud` selbst ist im diktiersicheren Alphabet unmoeglich
# (c verboten, ou verboten), das Bild dahinter aber reich an brauchbaren
# Morphemen. Lateinische Wolkengattungen tragen ausserdem von Haus aus den
# Klang, den ein Produktname braucht.
MORPHEMES_SKY: tuple[str, ...] = (
    # Wolkengattungen und Atmosphaere
    "nimbus", "stratus", "kumulus", "kirrus", "strato", "nimbo", "nebula",
    "nebel", "dunst", "atem", "luft", "halo", "aura", "orbit", "apogee",
    "gipfel", "alpin", "firn", "graupel", "reif", "tau", "rime",
    # Winde
    "orkan", "passat", "monsun", "bora", "boreas", "notus", "brise", "bise",
    "sturm", "regen", "front", "flaute", "traube",
    # Hoehe und Weite
    "altus", "apex", "kuppe", "grat", "kamm", "hoehe", "zenit", "traufe",
    "segel", "drachen", "ballon", "hangar", "flug", "start", "landung",
)

MORPHEMES_IT: tuple[str, ...] = (
    "kernel", "daemon", "socket", "subnet", "netmask", "bastion", "inode", "initrd",
    "rootfs", "tmpfs", "fstab", "dmesg", "sudo", "mount", "pidfile", "hardlink",
    "snapshot", "failsafe", "hotplug", "logrotate", "bitrot", "hotspare", "runlevel",
    "resolver", "registrar", "gateway", "ingress", "egress", "router", "bridge",
    "tunnel", "packet", "buffer", "sector", "cluster", "shard", "replica", "quorum",
    "raft", "paxos", "gossip", "heartbeat", "leader", "follower", "commit", "journal",
    "ledger", "digest", "cipher", "keyring", "salt", "nonce", "seal", "unseal",
    "port", "host", "node", "link", "lane", "grid", "mesh", "core", "base", "stack",
    "forge", "depot", "haven", "atlas", "prism", "helio", "borea", "tundra",
)

# --- Quelle C: Fachbegriffe aus Standards -------------------------------------
# (label, kurze Herkunft). Der harte Filter entscheidet, was davon uebrig bleibt.
JARGON: tuple[tuple[str, str], ...] = (
    # DNS-Rcodes, RFC 1035 / 2136 / 6891
    ("noerror", "DNS-Rcode 0"), ("formerr", "DNS-Rcode 1"), ("servfail", "DNS-Rcode 2"),
    ("nxdomain", "DNS-Rcode 3"), ("notimp", "DNS-Rcode 4"), ("refused", "DNS-Rcode 5"),
    ("yxdomain", "DNS-Rcode 6"), ("nxrrset", "DNS-Rcode 8"), ("notauth", "DNS-Rcode 9"),
    ("notzone", "DNS-Rcode 10"), ("badvers", "DNS-Rcode 16"), ("badsig", "DNS-Rcode 16"),
    ("badkey", "DNS-Rcode 17"), ("badtime", "DNS-Rcode 18"), ("badmode", "DNS-Rcode 19"),
    ("badname", "DNS-Rcode 20"), ("badalg", "DNS-Rcode 21"), ("badtrunc", "DNS-Rcode 22"),
    ("badcookie", "DNS-Rcode 23"), ("nodata", "DNS: NOERROR ohne Antwortsatz"),
    ("lameref", "DNS: lame delegation"), ("glueless", "DNS: fehlender Glue"),
    ("nullmx", "RFC 7505: Domain nimmt keine Mail an"),
    ("negcache", "RFC 2308: negatives Caching"), ("rootzone", "DNS-Rootzone"),
    ("delegate", "DNS-Delegation"), ("apexrec", "Apex-Record"),
    # SMTP / RFC 3463 / 5321
    ("helo", "SMTP-Kommando"), ("ehlo", "SMTP-Kommando"), ("mailfrom", "SMTP-Envelope"),
    ("tarpit", "SMTP-Tarpit"), ("relaying", "SMTP-Relay"), ("bounced", "SMTP-Bounce"),
    ("nosuchbox", "SMTP 550"), ("mailloop", "SMTP-Schleife"), ("submit", "Port 587"),
    ("postmaster", "RFC 5321 Pflichtadresse"), ("dsnreport", "Delivery Status Notification"),
    ("greeting", "SMTP-Banner"), ("startls", "STARTTLS"),
    # SPF / DKIM / DMARC, RFC 7208 / 7489
    ("softfail", "SPF-Ergebnis"), ("hardfail", "SPF-Ergebnis"), ("permerror", "SPF-Ergebnis"),
    ("temperror", "SPF-Ergebnis"), ("neutral", "SPF-Ergebnis"), ("noneresult", "SPF none"),
    ("dmarcfail", "DMARC-Ergebnis"), ("alignment", "DMARC-Alignment"),
    ("selector", "DKIM-Selector"), ("relaxed", "DKIM-Canonicalization"),
    ("mailauth", "RFC 8601 Authentication-Results"),
    # errno, POSIX
    ("eperm", "errno 1"), ("enoent", "errno 2"), ("eintr", "errno 4"), ("ebadf", "errno 9"),
    ("eagain", "errno 11"), ("enomem", "errno 12"), ("efault", "errno 14"),
    ("ebusy", "errno 16"), ("enodev", "errno 19"), ("enotdir", "errno 20"),
    ("eisdir", "errno 21"), ("einval", "errno 22"), ("enfile", "errno 23"),
    ("emfile", "errno 24"), ("efbig", "errno 27"), ("espipe", "errno 29"),
    ("erofs", "errno 30"), ("emlink", "errno 31"), ("epipe", "errno 32"),
    ("edom", "errno 33"), ("erange", "errno 34"), ("eidrm", "errno 43"),
    ("enostr", "errno 60"), ("etime", "errno 62"), ("enodata", "errno 61"),
    ("enolink", "errno 67"), ("eproto", "errno 71"), ("ebadmsg", "errno 74"),
    ("enotsup", "errno 95"), ("enobufs", "errno 105"), ("etimedout", "errno 110"),
    ("estale", "errno 116"), ("eremote", "errno 66"), ("edotdot", "errno 73"),
    ("enotnam", "errno 118"), ("ebadslt", "errno 57"), ("eusers", "errno 87"),
    ("ebade", "errno 52"), ("ebadr", "errno 53"), ("enotblk", "errno 15"),
    # HTTP-Statustexte
    ("notfound", "HTTP 404"), ("gone", "HTTP 410"), ("teapot", "HTTP 418"),
    ("timeout", "HTTP 408/504"), ("upgrade", "HTTP 426"), ("partial", "HTTP 206"),
    ("nocontent", "HTTP 204"), ("seeother", "HTTP 303"), ("notmodif", "HTTP 304"),
    ("payload", "HTTP 413"), ("tooearly", "HTTP 425"), ("failedep", "HTTP 424"),
    ("misdirect", "HTTP 421"), ("ratelimit", "HTTP 429"), ("badgate", "HTTP 502"),
    ("noroute", "kein Pfad zum Ziel"), ("unreach", "Ziel nicht erreichbar"),
    # POSIX-Signale
    ("sighup", "SIGHUP"), ("sigint", "SIGINT"), ("sigtrap", "SIGTRAP"),
    ("sigterm", "SIGTERM"), ("sigpipe", "SIGPIPE"), ("sigalrm", "SIGALRM"),
    ("sigstop", "SIGSTOP"), ("sigbus", "SIGBUS"), ("sigfpe", "SIGFPE"),
    ("sigabrt", "SIGABRT"), ("sighold", "sighold(3)"), ("hangup", "SIGHUP im Klartext"),
    ("sigusr", "SIGUSR1/2"), ("sigprof", "SIGPROF"), ("sigttin", "SIGTTIN"),
    # BGP / Routing / TCP
    ("holdtime", "BGP Hold Timer"), ("flapping", "Route Flapping"), ("blackhole", "Blackhole-Route"),
    ("martian", "Martian-Paket"), ("bogon", "Bogon-Praefix"), ("dampening", "Route Damping"),
    ("halfopen", "TCP halb offen"), ("finrst", "TCP FIN/RST"), ("backoff", "Exponential Backoff"),
    ("jitter", "Jitter"), ("mtupath", "Path MTU"), ("blackout", "Ausfallfenster"),
    ("splitdns", "Split-Horizon DNS"), ("loopback", "127.0.0.1"), ("linklocal", "169.254/16"),
    # Speicher / Dateisystem
    ("orphaned", "verwaiste Inode"), ("dangling", "dangling symlink"), ("staleness", "Stale Handle"),
    ("fsckpass", "fsck-Durchlauf"), ("silentbit", "Silent Bit Rot"), ("bitrot", "Bit Rot"),
    ("scrubbing", "ZFS Scrub"), ("resilver", "ZFS Resilver"), ("degraded", "degradiertes RAID"),
    # TCP-Zustaende und Transportverhalten
    ("lastack", "TCP-Zustand LAST_ACK"), ("finwait", "TCP-Zustand FIN_WAIT"),
    ("fastopen", "TCP Fast Open, RFC 7413"), ("nagle", "Nagle-Algorithmus, RFC 896"),
    ("pathmtu", "Path MTU Discovery, RFC 1191"), ("retransmit", "TCP-Retransmission"),
    ("reorder", "Paketumordnung"), ("halfopen", "halb offene Verbindung"),
    ("slostart", "Slow Start"), ("dupack", "Duplicate ACK"),
    # BGP und Betrieb
    ("nexthop", "BGP NEXT_HOP"), ("aspath", "BGP AS_PATH"), ("prepend", "AS-Path Prepending"),
    ("dampen", "Route Damping"), ("peering", "BGP-Peering"), ("transit", "IP-Transit"),
    ("lastmile", "Last Mile"), ("darkfiber", "unbeschaltete Faser"), ("tierone", "Tier-1-Netz"),
    ("netsplit", "Netzsplit"), ("splitbrain", "Split-Brain im Cluster"),
    ("fencing", "Fencing im HA-Cluster"), ("failstop", "Fail-Stop-Modell"),
    ("failback", "Failback"), ("airgap", "Air Gap"), ("onprem", "on premises"),
    # Kernel, Rechte, Dateisystem
    ("segfault", "Segmentation Fault"), ("setuid", "setuid-Bit"), ("setgid", "setgid-Bit"),
    ("umask", "umask(2)"), ("urandom", "/dev/urandom"), ("netboot", "Netzwerkstart"),
    ("bootfile", "BOOTP/DHCP bootfile"), ("rebind", "DHCP-Zustand REBINDING"),
    ("leasetime", "DHCP Lease Time"), ("driftfile", "NTP driftfile"),
    ("slewrate", "NTP Slew"), ("stratum", "NTP-Stratum"), ("holdup", "Hold-up-Zeit"),
    ("bitflip", "Bitkipper im Speicher"), ("postmortem", "Post-mortem"),
    ("runbook", "Runbook"), ("sinkhole", "DNS-Sinkhole"), ("honeypot", "Honeypot"),
    ("hairpin", "NAT-Hairpinning"), ("badglue", "fehlerhafte Glue-Records"),
    ("negttl", "negative TTL, RFC 2308"), ("minttl", "Minimum TTL im SOA"),
    ("soattl", "TTL des SOA-Records"), ("nullroute", "Null-Route"),
)

CATEGORY_BY_SOURCE = {"A": "zufall", "B": "marke", "C": "fachwitz",
                      "D": "it", "E": "marke"}
