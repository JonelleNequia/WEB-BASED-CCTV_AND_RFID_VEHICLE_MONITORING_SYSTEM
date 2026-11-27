"""Real local transport around a complete recovery, separate from hardware/native services."""
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import time
import unittest
import urllib.request
import urllib.error
from fixture import Installation, ROOT, PHP
from test_security import packet


class RecoveryHttp(unittest.TestCase):
    def test_retained_picture_delivery_retries_over_real_http_after_restore(self):
        f = Installation(); self.addCleanup(f.close)
        payload = dict(f.php()['payload'], external_event_key='d5b-http-picture-pass')
        image = f.p['targets']['storage'] / 'app/private/occurrence-media/11111111-1111-4111-8111-111111111111.png'
        operation_id = f.box.enqueue('crossing', payload, {'snapshot': image.read_bytes()}); original = packet(f.box, operation_id)
        router = f.directory / 'router.php'
        # Trusted fixture harness code; incoming archives are never used as routers.
        router.write_text('<?php\nrequire '+repr(str(ROOT / 'vendor/autoload.php'))+';\n$app=require '+repr(str(ROOT / 'bootstrap/app.php'))+';\n$app->useStoragePath('+repr(str(f.p['targets']['storage']))+');\n$kernel=$app->make(Illuminate\\Contracts\\Http\\Kernel::class);\n$r=Illuminate\\Http\\Request::capture();$response=$kernel->handle($r);$response->send();$kernel->terminate($r,$response);\n')
        def send():
            with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port = sock.getsockname()[1]
            log = (f.directory / 'http.log').open('a')
            server = subprocess.Popen([PHP, '-S', '127.0.0.1:'+str(port), str(router)], env=f.env, cwd=ROOT, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
            try:
                url = 'http://127.0.0.1:'+str(port)
                for _ in range(60):
                    try:
                        if urllib.request.urlopen(url+'/up', timeout=1).status == 200: break
                    except urllib.error.HTTPError as error:
                        body = error.read().decode(errors='replace'); error.close()
                        self.fail('Private health HTTP error: ' + body[:4000])
                    except (urllib.error.URLError, OSError): pass
                    time.sleep(.05)
                else: self.fail('Private HTTP application did not become ready: ' + (f.directory / 'http.log').read_text()[-6000:])
                request = urllib.request.Request(url+'/api/v1/integration/deliveries', data=json.dumps(original).encode(), headers={'Content-Type':'application/json','Accept':'application/json','X-Api-Key':'synthetic-d5b-key'})
                with urllib.request.urlopen(request, timeout=20) as response:
                    self.assertIn(response.status, (200,201))
                    return json.load(response)
            finally:
                # Actual server is stopped before entering the synthetic owned adapter.
                os.killpg(server.pid, signal.SIGTERM); server.wait(timeout=10); log.close()
        first = send(); before = f.php(); self.assertEqual('pending', f.box.inspect(operation_id)['state'])
        backup = f.backup(); operation = f.restore(backup); f.recovery.accept(operation); f.recovery.resume(operation)
        retry = send(); self.assertTrue(retry['duplicate']); self.assertEqual(first['delivery_ack'], retry['delivery_ack'])
        after = f.php(); self.assertEqual((before['events'],before['crossings'],before['receipts']), (after['events'],after['crossings'],after['receipts']))
        again = send(); self.assertEqual(first['delivery_ack'], again['delivery_ack'])
