#!/usr/bin/env python3
"""Rechnet die Tabellen aus Whitepaper 10 nach.

    python3 tools/berechnungen.py

Das Papier behauptet Zahlen; dieses Skript rechnet sie aus. Ausserdem
simuliert es dieselben Groessen mit Zufallsziehungen — wenn Formel und
Simulation auseinanderlaufen, ist eine von beiden falsch.
"""

from __future__ import annotations

import math
import random

RNG = random.Random(20260803)
RUNDEN = 200_000


def titel(text: str) -> None:
    print(f"\n{text}\n{'─' * len(text)}")


def prozent(p: float) -> str:
    if p >= 0.9999:
        return "≈ 1"
    if p >= 0.01:
        return f"{p * 100:.2f} %".replace(".", ",")
    return f"{p:.2e}"


def binom_ab(n: int, k: int, p: float) -> float:
    """P(X >= k) fuer X ~ Bin(n, p)."""
    return sum(math.comb(n, i) * p**i * (1 - p)**(n - i) for i in range(k, n + 1))


# -- 10.1 Verkettung von Sender und Empfaenger ------------------------------

def pfad_ohne_waechter(q: float, n: int) -> float:
    return 1 - (1 - q * q) ** n


def pfad_mit_waechter(q: float, n: int) -> float:
    return q * (1 - (1 - q) ** n)


def simuliere_pfade(q: float, nachrichten: int, waechter: bool,
                    runden: int = 2000) -> float:
    treffer = 0
    for _ in range(runden):
        guard_boese = RNG.random() < q
        erwischt = False
        for _ in range(nachrichten):
            eingang = guard_boese if waechter else RNG.random() < q
            ausgang = RNG.random() < q
            if eingang and ausgang:
                erwischt = True
                break
        treffer += erwischt
    return treffer / runden


def abschnitt_10_1() -> None:
    titel("10.1  Verkettung von Sender und Empfaenger")
    print("  P(mindestens einmal erfasst) = 1 - (1 - q²)^n\n")
    print(f"  {'q':>6} {'n = 100':>12} {'n = 1 000':>12} {'n = 10 000':>12}")
    for q in (0.01, 0.05, 0.10, 0.20):
        werte = "".join(f"{prozent(pfad_ohne_waechter(q, n)):>13}"
                        for n in (100, 1000, 10000))
        print(f"  {q:>6.2f}{werte}")

    print("\n  Mit Eingangswaechter: P = q · (1 - (1-q)^n) ≤ q\n")
    print(f"  {'q':>6} {'zufaellig':>14} {'mit Waechter':>14} {'Simulation':>12}")
    for q in (0.01, 0.05, 0.10, 0.20):
        ohne = pfad_ohne_waechter(q, 10000)
        mit = pfad_mit_waechter(q, 10000)
        sim = simuliere_pfade(q, 200, waechter=True, runden=3000)
        print(f"  {q:>6.2f}{prozent(ohne):>15}{prozent(mit):>15}{prozent(sim):>13}")

    print("\n  Aus einer Gewissheit ueber die Zeit wird ein einmaliger Muenzwurf.")


# -- 10.2 und 10.3: dieselbe Schwelle, zwei Lesarten ------------------------

def abschnitt_10_2(k: int = 10, n: int = 20) -> None:
    titel(f"10.2  Verfuegbarkeit der Ablage (k = {k}, n = {n})")
    print("  P(verfuegbar) = Σ C(n,i)·a^i·(1-a)^(n-i)  fuer i >= k\n")
    print(f"  {'a':>6} {'verfuegbar':>14} {'Verlust':>14} {'Simulation':>14}")
    for a in (0.50, 0.70, 0.80, 0.90):
        p = binom_ab(n, k, a)
        sim = sum(1 for _ in range(RUNDEN // 20)
                  if sum(RNG.random() < a for _ in range(n)) >= k) / (RUNDEN // 20)
        print(f"  {a:>6.2f}{prozent(p):>15}{prozent(1 - p):>15}{prozent(sim):>15}")

    print("\n  Speicheraufwand: Faktor n/k = "
          f"{n / k:.1f}. Fuer dieselbe Verlustwahrscheinlichkeit bei a = 0,90")
    kopien = math.ceil(math.log(1 - binom_ab(n, k, 0.9)) / math.log(0.1))
    print(f"  braeuchte man {kopien} vollstaendige Kopien — jede davon fuer sich")
    print("  vollstaendig und damit fuer einen Angreifer wertvoll.")


def abschnitt_10_3(k: int = 10, n: int = 20) -> None:
    titel(f"10.3  Wertlosigkeit nach der Abholung (k = {k}, n = {n})")
    print("  P(rekonstruierbar) = Σ C(n,i)·q^i·(1-q)^(n-i)  fuer i >= k")
    print("  Dieselbe Formel wie 10.2, mit q an der Stelle von a.\n")
    print(f"  {'q':>6} {'rekonstruierbar':>18} {'Simulation':>14}")
    for q in (0.10, 0.20, 0.30, 0.50):
        p = binom_ab(n, k, q)
        sim = sum(1 for _ in range(RUNDEN // 20)
                  if sum(RNG.random() < q for _ in range(n)) >= k) / (RUNDEN // 20)
        print(f"  {q:>6.2f}{prozent(p):>19}{prozent(sim):>15}")

    print("\n  Verfuegbarkeit und Vernichtbarkeit sind zwei Lesarten derselben")
    print("  Schwelle. k/n entscheidet ueber beide zugleich.\n")

    print(f"  {'k/n':>8} {'verfuegbar (a=0,9)':>20} {'sicher (q=0,2)':>18}")
    for kk in (4, 6, 8, 10, 12, 14, 16):
        verf = binom_ab(n, kk, 0.90)
        sicher = 1 - binom_ab(n, kk, 0.20)
        print(f"  {kk:>3}/{n:<4}{prozent(verf):>21}{prozent(sicher):>19}")
    print("\n  k = n/2 ist das Gleichgewicht, in dem beide Werte gleichzeitig")
    print("  gut sind — solange ehrliche Knoten deutlich in der Mehrheit sind.")


def abschnitt_zerstoerung(k: int = 10, n: int = 20) -> None:
    titel("7.7  Zerstoerung durch Schwelle")
    print(f"  Bei k = {k}, n = {n} genuegen {n - k + 1} Loeschungen.\n")
    print(f"  {'geloescht':>10} {'uebrig':>8} {'Zustand':>28}")
    for geloescht in range(n - k - 1, n - k + 3):
        uebrig = n - geloescht
        zustand = "wiederherstellbar" if uebrig >= k else "unwiederbringlich zerstoert"
        print(f"  {geloescht:>10} {uebrig:>8} {zustand:>28}")
    print("\n  Die Schwelle ist scharf: ein Teil mehr oder weniger entscheidet.")


def abschnitt_verzeichnis() -> None:
    titel("4.3  Entdeckung eines untergeschobenen Schluessels")
    print("  P(entdeckt) = 1 - (1 - p)^m,  m unabhaengige Beobachter\n")
    print(f"  {'m':>4} {'p = 0,10':>12} {'p = 0,25':>12} {'p = 0,50':>12}")
    for m in (1, 3, 5, 10, 20):
        werte = "".join(f"{prozent(1 - (1 - p) ** m):>13}" for p in (0.10, 0.25, 0.50))
        print(f"  {m:>4}{werte}")
    print("\n  Anders als beim Verkehrsangriff hinterlaesst dieser Angriff einen")
    print("  dauerhaften Beweis — der Eintrag steht in der Kette.")


def main() -> int:
    print("\nNyx — Nachrechnung zum Whitepaper")
    print("=" * 40)
    abschnitt_10_1()
    abschnitt_10_2()
    abschnitt_10_3()
    abschnitt_zerstoerung()
    abschnitt_verzeichnis()
    print()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
