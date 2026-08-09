"""HTTP-Schicht gegen einen echten lokalen Server.

Ohne diesen Test bleibt ein falsch verdrahteter Opener unbemerkt, bis ein Lauf
ueber Stunden nichts tut -- genau das ist einmal passiert.
"""

import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest

from domainfinder.net import HttpError, RateLimiter, request
from domainfinder.stages import StageBroken, _fail_fast

STATE = {"429_left": 0, "500_left": 0, "hits": 0}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):  # pragma: no cover - Testrauschen
        pass

    def _send(self, code, payload=b"", headers=None):
        self.send_response(code)
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.send_header("content-length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self):
        STATE["hits"] += 1
        if self.path == "/ok":
            return self._send(200, json.dumps({"Status": 3}).encode())
        if self.path == "/missing":
            return self._send(404, b'{"errorCode":404}')
        if self.path == "/teapot":
            return self._send(418, b"nope")
        if self.path == "/flaky429":
            if STATE["429_left"] > 0:
                STATE["429_left"] -= 1
                return self._send(429, b"slow down", {"retry-after": "0"})
            return self._send(200, b'{"ok":true}')
        if self.path == "/flaky500":
            if STATE["500_left"] > 0:
                STATE["500_left"] -= 1
                return self._send(503, b"later")
            return self._send(200, b'{"ok":true}')
        return self._send(404, b"")

    def do_POST(self):
        length = int(self.headers.get("content-length", 0))
        body = self.rfile.read(length)
        self._send(200, json.dumps({"success": True, "echo": json.loads(body)}).encode())


@pytest.fixture(scope="module")
def server():
    srv = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    t = threading.Thread(target=srv.serve_forever, daemon=True)
    t.start()
    yield f"http://127.0.0.1:{srv.server_address[1]}"
    srv.shutdown()


def test_request_holt_wirklich_daten(server):
    """Regression: der Opener muss aufrufbar sein, nicht nur konstruierbar."""
    resp = request(f"{server}/ok", accept_status=(200,), timeout=5)
    assert resp.status == 200
    assert resp.json()["Status"] == 3


def test_404_ist_ein_ergebnis_kein_fehler(server):
    resp = request(f"{server}/missing", accept_status=(200, 404), timeout=5)
    assert resp.status == 404


def test_unerwarteter_status_wirft(server):
    with pytest.raises(HttpError) as exc:
        request(f"{server}/teapot", accept_status=(200, 404), timeout=5)
    assert exc.value.status == 418


def test_429_wird_wiederholt(server):
    STATE["429_left"] = 2
    resp = request(f"{server}/flaky429", accept_status=(200,), timeout=5,
                   limiter=RateLimiter(50))
    assert resp.status == 200
    assert STATE["429_left"] == 0


def test_5xx_wird_wiederholt(server):
    STATE["500_left"] = 2
    resp = request(f"{server}/flaky500", accept_status=(200,), timeout=5, max_attempts=5)
    assert resp.status == 200


def test_aufgeben_nach_max_versuchen(server):
    STATE["500_left"] = 99
    with pytest.raises(HttpError, match="aufgegeben"):
        request(f"{server}/flaky500", accept_status=(200,), timeout=5, max_attempts=2)
    STATE["500_left"] = 0


def test_post_mit_body(server):
    resp = request(f"{server}/any", method="POST",
                   headers={"content-type": "application/json"},
                   body=json.dumps({"domains": ["a.com"]}).encode(),
                   accept_status=(200,), timeout=5)
    assert resp.json()["echo"]["domains"] == ["a.com"]


def test_fehler_enthaelt_keine_header(server):
    """Der Authorization-Header darf in keiner Fehlermeldung landen."""
    with pytest.raises(HttpError) as exc:
        request(f"{server}/teapot", headers={"authorization": "Bearer streng-geheim"},
                accept_status=(200,), timeout=5)
    assert "streng-geheim" not in str(exc.value)
    assert "streng-geheim" not in repr(exc.value)


# --- Notbremse ---------------------------------------------------------------
def test_fail_fast_schweigt_bei_gesunden_antworten():
    _fail_fast("1", {"free": 100, "taken": 90, "unknown": 10}, 200, "ok")


def test_fail_fast_schlaegt_bei_systematischem_fehler_an():
    with pytest.raises(StageBroken, match="unbrauchbar"):
        _fail_fast("1", {"unknown": 200}, 200, "TypeError: kaputt")


def test_fail_fast_wartet_die_stichprobe_ab():
    _fail_fast("1", {"unknown": 10}, 10, "noch zu frueh")


def test_penalise_summiert_sich_nicht_auf():
    lim = RateLimiter(10.0)
    lim.penalise(2.0)
    tief = lim._tokens
    for _ in range(5):
        lim.penalise(2.0)
    assert lim._tokens == tief
