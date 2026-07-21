#!/usr/bin/env python3
"""
server.py — tiny stdlib HTTP server for the crack-wifi web interface.

No third-party dependencies. Serves the static UI from CRACK_WEBROOT and exposes
a small JSON API backed by lib/engine.py.

API:
    GET  /api/status                      -> {mode, wordlist, hostname}
    GET  /api/scan                        -> {networks: [...]}
    POST /api/attack   {bssid, options}   -> {job_id}
    GET  /api/attack?id=..&since=N        -> job snapshot (events since N)
    POST /api/attack/stop  {id}           -> {stopped: bool}

Environment:
    CRACK_MODE      demo|real   (default demo)
    CRACK_WORDLIST  path to default wordlist
    CRACK_WEBROOT   directory holding index.html/style.css/app.js
"""

import os
import sys
import json
import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

# Make lib/ importable regardless of CWD.
HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, os.path.join(ROOT, "lib"))

import engine as engine_mod   # noqa: E402

MODE = os.environ.get("CRACK_MODE", "demo")
WORDLIST = os.environ.get("CRACK_WORDLIST") or None
WEBROOT = os.environ.get("CRACK_WEBROOT") or os.path.join(ROOT, "web")

ENGINE = engine_mod.build_engine(MODE, wordlist=WORDLIST)

_STATIC = {
    "/": ("index.html", "text/html; charset=utf-8"),
    "/index.html": ("index.html", "text/html; charset=utf-8"),
    "/style.css": ("style.css", "text/css; charset=utf-8"),
    "/app.js": ("app.js", "application/javascript; charset=utf-8"),
}


class Handler(BaseHTTPRequestHandler):
    server_version = "crack-wifi/1.0"

    # keep the console clean
    def log_message(self, fmt, *args):
        pass

    # -- helpers ----------------------------------------------------------
    def _send_json(self, obj, code=200):
        body = json.dumps(obj).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def _send_static(self, filename, ctype):
        path = os.path.join(WEBROOT, filename)
        try:
            with open(path, "rb") as fh:
                body = fh.read()
        except OSError:
            self.send_error(404, "Not found")
            return
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _read_json(self):
        length = int(self.headers.get("Content-Length") or 0)
        if length == 0:
            return {}
        try:
            return json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            return {}

    # -- routing ----------------------------------------------------------
    def do_GET(self):
        parsed = urlparse(self.path)
        route = parsed.path

        if route in _STATIC:
            fname, ctype = _STATIC[route]
            return self._send_static(fname, ctype)

        if route == "/api/status":
            return self._send_json({
                "mode": ENGINE.mode,
                "wordlist": WORDLIST,
                "hostname": self.headers.get("Host", "crack-wifi.local"),
            })

        if route == "/api/scan":
            try:
                nets = ENGINE.scan()
            except Exception as e:  # keep the UI alive on scan failure
                return self._send_json({"error": str(e), "networks": []}, 500)
            return self._send_json({"networks": nets})

        if route == "/api/attack":
            qs = parse_qs(parsed.query)
            job_id = (qs.get("id") or [None])[0]
            since = int((qs.get("since") or ["0"])[0])
            if not job_id:
                return self._send_json({"error": "missing id"}, 400)
            snap = ENGINE.get_job(job_id, since=since)
            if snap is None:
                return self._send_json({"error": "unknown job"}, 404)
            return self._send_json(snap)

        self.send_error(404, "Not found")

    def do_POST(self):
        route = urlparse(self.path).path
        data = self._read_json()

        if route == "/api/attack":
            bssid = data.get("bssid")
            options = data.get("options") or {}
            if not bssid:
                return self._send_json({"error": "missing bssid"}, 400)
            job_id = ENGINE.start_attack(bssid, options)
            return self._send_json({"job_id": job_id})

        if route == "/api/attack/stop":
            job_id = data.get("id")
            stopped = ENGINE.stop_job(job_id) if job_id else False
            return self._send_json({"stopped": stopped})

        self.send_error(404, "Not found")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=8777)
    args = ap.parse_args()

    httpd = ThreadingHTTPServer((args.host, args.port), Handler)
    print(f"[server] crack-wifi UI listening on http://{args.host}:{args.port} "
          f"(mode={ENGINE.mode})", flush=True)
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        httpd.server_close()


if __name__ == "__main__":
    main()
