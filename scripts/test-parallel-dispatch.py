"""Threaded loopback SMS fixture. Never reads credentials or contacts a provider."""
import json
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

ROOT = Path(__file__).resolve().parents[1]
STATE = {}
LOCK = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        parsed = urlsplit(self.path)
        if parsed.path != '/stats':
            self.send_error(404)
            return
        run = parse_qs(parsed.query).get('run', [''])[0]
        with LOCK:
            data = STATE.get(run, {'peak': 0, 'active': 0, 'requests': []})
            body = json.dumps(data).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        parsed = urlsplit(self.path)
        if parsed.path != '/send':
            self.send_error(404)
            return
        params = parse_qs(parsed.query)
        run, mode = params['run'][0], params['mode'][0]
        data = parse_qs(self.rfile.read(int(self.headers['Content-Length'])).decode())
        unique = data['Unique'][0]
        message_id = int(unique.removeprefix('portal-message-'))
        with LOCK:
            state = STATE.setdefault(run, {'peak': 0, 'active': 0, 'requests': []})
            state['active'] += 1
            state['peak'] = max(state['peak'], state['active'])
            state['requests'].append({'id': message_id, 'unique': unique, 'dcheck': data['DCheck'][0], 'started': time.monotonic()})
        try:
            delay = .30 + (message_id % 3) * .025
            if mode == 'fast':
                delay = .015
            elif mode == 'out-of-order':
                delay = {1: .35, 2: .05, 3: .15}[message_id]
            elif mode == 'heartbeat':
                delay = 1.2
            elif mode == 'timeout':
                delay = .35 if message_id == 2 else .015
            elif mode == 'settlement-failure':
                delay = .15 if message_id == 1 else .45
            time.sleep(delay)
            body = f'<Message><Id>{100000 + message_id}</Id><Status>SENT</Status><MessageCost>1</MessageCost><Parts>1</Parts><IdList /></Message>'
            if message_id == 2:
                if mode == 'html':
                    body = '<html><body>Unverified fixture acknowledgement</body></html>'
                elif mode == 'oversize':
                    body = 'x' * 70000
                elif mode == 'reject':
                    body = '<Message><Id>0</Id><Status>Error</Status><MessageCost>0</MessageCost><Parts>0</Parts><IdList>0</IdList><Reason>Invalid mobile format</Reason></Message>'
            raw = body.encode()
            with LOCK:
                state['active'] -= 1
            self.send_response(200)
            self.send_header('Content-Type', 'application/xml')
            self.send_header('Content-Length', str(len(raw)))
            self.end_headers()
            self.wfile.write(raw)
        except (BrokenPipeError, ConnectionResetError, ConnectionAbortedError):
            pass


def main():
    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    server.daemon_threads = True
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        result = subprocess.run(['php', str(ROOT / 'api/tests/run-parallel-dispatch.php'), f'http://127.0.0.1:{server.server_port}'], cwd=ROOT)
        return result.returncode
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2)


if __name__ == '__main__':
    raise SystemExit(main())
