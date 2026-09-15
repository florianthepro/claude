#!/usr/bin/env python3
"""
PTY-Supervisor fuer den Claude-Code-Agenten unter systemd.

Warum das noetig ist:
  Claude Code Remote Control ist eine Terminal-Anwendung und erwartet ein PTY.
  Der naheliegende Wrapper `script -qfec CMD /dev/null` liefert zwar ein PTY und
  reicht mit -e den Exit-Code durch, killt sein Kind bei SIGTERM aber hart
  ("Session terminated, killing shell... ...killed") - ein sauberes Herunterfahren
  ist damit nicht moeglich, und in-flight-Arbeit geht verloren.

Dieser Supervisor macht beides richtig:
  * legt ein echtes PTY an (pty.fork setzt setsid + TIOCSCTTY) und setzt eine
    definierte Fenstergroesse, damit die TUI nicht in einen 0x0-Zustand laeuft,
  * leitet SIGTERM/SIGINT saeuberlich an das Kind weiter und raeumt erst nach
    einer Gnadenfrist mit SIGKILL auf,
  * beendet sich mit dem Exit-Status des Kindes, damit systemd echte Abstuerze
    von einem geordneten Stopp unterscheiden kann,
  * schreibt die Ausgabe zeilenweise nach stdout (journald), optional ohne
    ANSI-Steuerzeichen, damit `journalctl` lesbar bleibt.

Aufruf:
    claude-agent-run.py [--grace SEKUNDEN] [--keep-ansi] -- BEFEHL [ARGS...]
"""

import argparse
import errno
import fcntl
import os
import pty
import re
import select
import signal
import struct
import sys
import termios
import time

ANSI_RE = re.compile(rb"\x1b\[[0-9;?]*[ -/]*[@-~]|\x1b[@-Z\\-_]|\r")
COLS, ROWS = 200, 50


def set_winsize(fd: int, rows: int, cols: int) -> None:
    """Definierte Fenstergroesse setzen - eine TUI ohne Groesse rendert unbrauchbar."""
    try:
        fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack("HHHH", rows, cols, 0, 0))
    except OSError:
        pass


def main() -> int:
    ap = argparse.ArgumentParser(add_help=True)
    ap.add_argument("--grace", type=float, default=25.0,
                    help="Sekunden, die dem Kind nach SIGTERM bleiben (Default: 25)")
    ap.add_argument("--keep-ansi", action="store_true",
                    help="ANSI-Steuerzeichen im Log behalten")
    ap.add_argument("cmd", nargs=argparse.REMAINDER,
                    help="-- gefolgt vom auszufuehrenden Befehl")
    args = ap.parse_args()

    cmd = args.cmd[1:] if args.cmd and args.cmd[0] == "--" else args.cmd
    if not cmd:
        print("claude-agent-run: kein Befehl angegeben", file=sys.stderr)
        return 2

    pid, master_fd = pty.fork()
    if pid == 0:
        # Kindprozess: hat jetzt ein eigenes PTY als kontrollierendes Terminal.
        try:
            os.execvp(cmd[0], cmd)
        except OSError as exc:
            # Direkt auf fd 2 schreiben - print() koennte hier schon gepuffert sein.
            os.write(2, f"claude-agent-run: exec fehlgeschlagen: {exc}\n".encode())
            os._exit(127)

    set_winsize(master_fd, ROWS, COLS)

    stopping = False
    deadline = None

    def request_stop(signum, _frame):
        """SIGTERM/SIGINT an das Kind weiterreichen statt es hart zu killen."""
        nonlocal stopping, deadline
        if stopping:
            return
        stopping = True
        deadline = time.monotonic() + args.grace
        sys.stdout.write(
            f"[supervisor] Signal {signum} empfangen, "
            f"leite SIGTERM an pid {pid} weiter (Gnadenfrist {args.grace:.0f}s)\n")
        sys.stdout.flush()
        try:
            os.killpg(pid, signal.SIGTERM)
        except (ProcessLookupError, PermissionError):
            try:
                os.kill(pid, signal.SIGTERM)
            except ProcessLookupError:
                pass

    signal.signal(signal.SIGTERM, request_stop)
    signal.signal(signal.SIGINT, request_stop)
    signal.signal(signal.SIGHUP, signal.SIG_IGN)

    out = sys.stdout.buffer
    buf = b""
    status = None

    while True:
        # Kind einsammeln, sobald es weg ist.
        if status is None:
            try:
                wpid, wstatus = os.waitpid(pid, os.WNOHANG)
                if wpid == pid:
                    status = wstatus
            except ChildProcessError:
                status = 0

        try:
            ready, _, _ = select.select([master_fd], [], [], 0.25)
        except InterruptedError:
            continue
        except OSError as exc:
            if exc.errno == errno.EBADF:
                break
            raise

        if ready:
            try:
                chunk = os.read(master_fd, 65536)
            except OSError:
                chunk = b""      # PTY zu: das Kind hat sich verabschiedet
            if chunk:
                buf += chunk
                # Zeilenweise ausgeben, damit journald saubere Eintraege bekommt.
                while b"\n" in buf:
                    line, buf = buf.split(b"\n", 1)
                    if not args.keep_ansi:
                        line = ANSI_RE.sub(b"", line)
                    line = line.rstrip()
                    if line:
                        out.write(line + b"\n")
                out.flush()
            elif status is not None:
                break

        if status is not None and not ready:
            break

        # Gnadenfrist abgelaufen: jetzt hart beenden.
        if stopping and deadline and time.monotonic() > deadline and status is None:
            sys.stdout.write("[supervisor] Gnadenfrist abgelaufen, sende SIGKILL\n")
            sys.stdout.flush()
            try:
                os.killpg(pid, signal.SIGKILL)
            except (ProcessLookupError, PermissionError):
                try:
                    os.kill(pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
            deadline = None

    # Rest des Puffers ausgeben.
    if buf:
        if not args.keep_ansi:
            buf = ANSI_RE.sub(b"", buf)
        buf = buf.rstrip()
        if buf:
            out.write(buf + b"\n")
    out.flush()

    try:
        os.close(master_fd)
    except OSError:
        pass

    if status is None:
        try:
            _, status = os.waitpid(pid, 0)
        except ChildProcessError:
            status = 0

    # Exit-Status so zurueckgeben, dass systemd Absturz und Stopp unterscheiden kann.
    if os.WIFSIGNALED(status):
        return 128 + os.WTERMSIG(status)
    return os.WEXITSTATUS(status)


if __name__ == "__main__":
    sys.exit(main())
