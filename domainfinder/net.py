"""HTTP-Grundlagen: TLS-Pruefung, Timeouts, Token-Bucket, Backoff.

Nur die Standardbibliothek. `urllib` verifiziert Zertifikate gegen den
System-Store beziehungsweise SSL_CERT_FILE und beachtet HTTPS_PROXY.
"""

from __future__ import annotations

import json
import random
import ssl
import threading
import time
import urllib.error
import urllib.request
from dataclasses import dataclass

USER_AGENT = "domainfinder/1.0 (+availability research; contact: local operator)"
DEFAULT_TIMEOUT = 15.0
MAX_ATTEMPTS = 6

_SSL_CTX = ssl.create_default_context()
_SSL_CTX.check_hostname = True
_SSL_CTX.verify_mode = ssl.CERT_REQUIRED

# Der SSL-Kontext gehoert an den Handler. OpenerDirector.open() kennt kein
# context-Argument -- das hat nur urlopen().
_opener = urllib.request.build_opener(
    urllib.request.ProxyHandler(),
    urllib.request.HTTPSHandler(context=_SSL_CTX),
)


class RateLimiter:
    """Token-Bucket, thread-sicher, blockierend."""

    def __init__(self, rate_per_sec: float, burst: float | None = None):
        self.rate = max(rate_per_sec, 0.01)
        self.capacity = burst if burst is not None else max(1.0, rate_per_sec)
        self._tokens = self.capacity
        self._last = time.monotonic()
        self._lock = threading.Lock()

    def acquire(self, amount: float = 1.0) -> None:
        while True:
            with self._lock:
                now = time.monotonic()
                self._tokens = min(self.capacity, self._tokens + (now - self._last) * self.rate)
                self._last = now
                if self._tokens >= amount:
                    self._tokens -= amount
                    return
                wait = (amount - self._tokens) / self.rate
            time.sleep(min(wait, 5.0))

    def penalise(self, seconds: float) -> None:
        """Nach 429 die Ausschuettung fuer alle Worker gemeinsam bremsen.

        Melden mehrere Worker gleichzeitig 429, darf sich die Bremse nicht
        aufsummieren -- sonst steht der Lauf minutenlang statt Sekunden.
        """
        debt = -abs(seconds) * self.rate
        with self._lock:
            if self._tokens > debt:
                self._tokens = debt


@dataclass
class Response:
    status: int
    body: bytes
    headers: dict[str, str]

    def json(self) -> dict:
        return json.loads(self.body.decode("utf-8", "replace") or "{}")


class HttpError(RuntimeError):
    """Fehler nach Ausschoepfen aller Versuche. Enthaelt nie Header oder Tokens."""

    def __init__(self, url: str, status: int | None, message: str):
        self.url = url
        self.status = status
        super().__init__(f"{url} -> {status or 'kein Status'}: {message}")


def request(
    url: str,
    *,
    method: str = "GET",
    headers: dict[str, str] | None = None,
    body: bytes | None = None,
    limiter: RateLimiter | None = None,
    timeout: float = DEFAULT_TIMEOUT,
    accept_status: tuple[int, ...] = (200, 404),
    max_attempts: int = MAX_ATTEMPTS,
) -> Response:
    """Fuehrt eine Anfrage mit exponentiellem Backoff bei 429 und 5xx aus.

    Statuscodes in `accept_status` gelten als Endergebnis und werden
    zurueckgegeben, nicht als Fehler behandelt.
    """
    hdrs = {"user-agent": USER_AGENT, **(headers or {})}
    last_status: int | None = None
    last_msg = "unbekannt"

    for attempt in range(max_attempts):
        if limiter:
            limiter.acquire()
        req = urllib.request.Request(url, data=body, headers=hdrs, method=method)
        try:
            with _opener.open(req, timeout=timeout) as resp:
                return Response(resp.status, resp.read(), dict(resp.headers))
        except urllib.error.HTTPError as exc:
            last_status = exc.code
            payload = b""
            try:
                payload = exc.read()
            except Exception:  # pragma: no cover - Body ist optional
                pass
            if exc.code in accept_status:
                return Response(exc.code, payload, dict(exc.headers or {}))
            last_msg = f"HTTP {exc.code}"
            if exc.code == 429 or 500 <= exc.code < 600:
                retry_after = (exc.headers or {}).get("retry-after")
                delay = _backoff(attempt, retry_after)
                if limiter and exc.code == 429:
                    limiter.penalise(delay)
                time.sleep(delay)
                continue
            raise HttpError(url, exc.code, last_msg) from None
        except (urllib.error.URLError, TimeoutError, ssl.SSLError, ConnectionError, OSError) as exc:
            last_msg = type(exc).__name__
            time.sleep(_backoff(attempt, None))
            continue

    raise HttpError(url, last_status, f"nach {max_attempts} Versuchen aufgegeben ({last_msg})")


def _backoff(attempt: int, retry_after: str | None) -> float:
    if retry_after:
        try:
            return min(float(retry_after), 60.0)
        except ValueError:
            pass
    return min(2.0 ** attempt, 32.0) * (0.7 + 0.6 * random.random())
