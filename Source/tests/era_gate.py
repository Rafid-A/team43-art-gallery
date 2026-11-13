"""Disposable integration test: python tests/era_gate.py [--baseline]. Requires Docker Compose.

Creates a uniquely named volume and project; never connects to an existing gallery.
--baseline demonstrates the pre-fix defect and is expected to pass only before the fix.
"""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
tag = uuid.uuid4().hex
project = 'eragate' + tag
volume = project + '_disposable_db'
count = 0

def run(args, **kwargs):
    print('+', subprocess.list2cmdline(args), flush=True)
    return subprocess.run(args, check=True, text=True, **kwargs)

def check(label, condition):
    global count
    if not condition:
        raise AssertionError(label)
    count += 1
    print('PASS', label, flush=True)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def main():
    global count
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        port = s.getsockname()[1]
    env = dict(os.environ, GALLERY_PORT=str(port), GALLERY_ROOT_PASSWORD='gallery-local-root')
    print('Loopback gallery port:', port, flush=True)
    with tempfile.TemporaryDirectory(prefix=project) as temp:
        override = Path(temp) / 'override.yaml'
        override.write_text('volumes:\n  gallery-db:\n    external: true\n    name: ' + volume + '\n')
        compose = ['docker', 'compose', '-p', project, '-f', str(ROOT / 'compose.yaml'), '-f', str(override)]
        existing = run(['docker', 'volume', 'ls', '-q'], capture_output=True).stdout.splitlines()
        if volume in existing:
            raise RuntimeError('Disposable volume already exists; refusing to use it')
        created = False
        try:
            run(['docker', 'volume', 'create', '--label', 'era-gate-owner=' + tag, volume], capture_output=True)
            created = True
            details = json.loads(run(['docker', 'volume', 'inspect', volume], capture_output=True).stdout)[0]
            if details['Name'] != volume or details['Labels'].get('era-gate-owner') != tag:
                raise RuntimeError('Volume ownership check failed')
            print('Verified new isolated volume:', volume, flush=True)
            run(compose + ['up', '-d', '--build', '--wait', 'db', 'web'], cwd=ROOT, env=env)
            container = project + '-db-1'
            def sql(statement):
                return run(['docker', 'exec', '-i', container, 'mariadb', '-uroot', '-pgallery-local-root', '--batch', '--skip-column-names', 'art'], input=statement, capture_output=True).stdout
            def rows():
                return sql('SELECT EraID, HEX(EraName), IFNULL(HEX(EraYears), "NULL") FROM Eras ORDER BY EraID;')
            base = 'http://127.0.0.1:' + str(port) + '/'
            client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
            def req(path, data=None):
                try:
                    response = client.open(base + path, None if data is None else urllib.parse.urlencode(data).encode(), timeout=20)
                except urllib.error.HTTPError as err:
                    response = err
                return response.status, response.read().decode('utf-8', 'replace'), response.headers
            def token(body):
                return re.search(r'name="csrf" value="([^"]+)', body).group(1)
            status, body, _ = req('artwork/art-login.php')
            check('login page', status == 200)
            status, _, headers = req('artwork/authenticate.php', {'csrf': token(body), 'userid': 'user1', 'password': 'pass1'})
            check('login', status == 303 and headers.get('Location') == 'admin.php')
            status, body, _ = req('artwork/object-add.php?type=eras')
            check('canonical data enables add', status == 200 and 'name="EraName"' in body)
            csrf = token(body)
            original = rows()
            check('six supplied eras', len(original.splitlines()) == 6)
            status, _, headers = req('artwork/object-add.php?type=eras', {'csrf': csrf, 'EraName': 'Test custom', 'EraYears': 'text'})
            match = re.search(r'id=(\d+)', headers.get('Location', ''))
            check('normal add allocates custom ID', status == 303 and match is not None and int(match.group(1)) > 6)
            custom_id = int(match.group(1))
            check('normal delete confirmation', req('artwork/object-delete.php?type=eras&id=' + str(custom_id))[0] == 200)
            check('normal delete', req('artwork/object-delete.php?type=eras&id=' + str(custom_id), {'csrf': csrf})[0] == 303 and rows() == original)
            sql("UPDATE Eras SET EraName='Conflict' WHERE EraID=1;")
            before = rows()
            status, body, _ = req('artwork/object-add.php?type=eras')
            if '--baseline' in sys.argv:
                check('baseline defect: conflict enables form', status == 200 and 'name="EraName"' in body)
                check('baseline check is read-only', rows() == before)
                return
            check('conflicting name disables form', status == 200 and 'name="EraName"' not in body)
            check('add POST refused', req('artwork/object-add.php?type=eras', {'csrf': csrf, 'EraName': 'Should not add', 'EraYears': 'x'})[0] == 422)
            check('delete POST refused', req('artwork/object-delete.php?type=eras&id=2', {'csrf': csrf})[0] == 409)
            status, body, _ = req('artwork/objects.php?type=eras')
            check('view-only list works', status == 200 and 'Conflict' in body)
            status, body, _ = req('artwork/objects.php?type=eras&id=1')
            check('conflict remains readable by ID', status == 200 and 'Conflict' in body)
            check('conflict hides delete action', 'class="button danger"' not in body)
            check('conflict delete page explains identity problem', 'reserved Era identities conflict' in req('artwork/object-delete.php?type=eras&id=2')[1])
            check('other gallery works', req('artwork/objects.php?type=artists')[0] == 200)
            check('health available', req('health.php')[:2] == (200, 'ready\n'))
            status, body, _ = req('artwork/integrity.php')
            check('integrity explains conflict', status == 200 and 'reserved Era identities conflict' in body)
            check('requests did not mutate rows', rows() == before)
            sql("UPDATE Eras SET EraName='Renaissance', EraYears='1400-1550' WHERE EraID=1;")
            before = rows()
            status, body, _ = req('artwork/objects.php?type=eras')
            check('conflicting duplicate names retain ID links', status == 200 and 'type=eras&amp;id=1' in body and 'type=eras&amp;id=2' in body)
            for era_id in (1, 2):
                status, body, _ = req('artwork/objects.php?type=eras&id=' + str(era_id))
                check('duplicate text remains readable by ID ' + str(era_id), status == 200 and 'Renaissance' in body)
            check('duplicate-text reads preserve data', rows() == before)
            for label, change in [('year', "EraName='Medieval', EraYears='wrong'"), ('null year', "EraYears=NULL"), ('case', "EraName='medieval', EraYears='Before 1400'"), ('space', "EraName='Medieval ', EraYears='Before 1400'")]:
                sql('UPDATE Eras SET ' + change + ' WHERE EraID=1;')
                before = rows()
                check(label + ' conflict gated', 'name="EraName"' not in req('artwork/object-add.php?type=eras')[1])
                check(label + ' check read-only', rows() == before)
            sql("UPDATE Eras SET EraName='Medieval', EraYears='Before 1400' WHERE EraID=1;")
            for era_id in range(1, 7):
                sql("UPDATE Eras SET EraName=CONCAT(EraName, '!') WHERE EraID=" + str(era_id))
                check('reserved ID ' + str(era_id) + ' conflict gated', 'name="EraName"' not in req('artwork/object-add.php?type=eras')[1])
                sql('UPDATE Eras SET EraName=LEFT(EraName, CHAR_LENGTH(EraName)-1) WHERE EraID=' + str(era_id))
            sql("INSERT INTO Eras (EraID, EraName, EraYears) VALUES (99, 'Custom', 'sometime');")
            for era_id in range(1, 7):
                sql('DELETE FROM Eras WHERE EraID=' + str(era_id))
                check('deletion of seed ' + str(era_id) + ' accepted', 'name="EraName"' in req('artwork/object-add.php?type=eras')[1])
            before = rows()
            check('deleted seed and custom ID accepted', 'name="EraName"' in req('artwork/object-add.php?type=eras')[1])
            check('accepted check read-only', rows() == before)
            sql("RENAME TABLE Eras TO Eras_test_identified; CREATE TABLE Eras (EraName VARCHAR(255), EraYears VARCHAR(255)) ENGINE=InnoDB; INSERT INTO Eras VALUES ('Legacy era', 'ancient');")
            before = sql('SELECT EraName, EraYears FROM Eras;')
            status, body, _ = req('artwork/object-add.php?type=eras')
            check('legacy schema explains upgrade requirement', status == 200 and 'Era identity upgrade is required' in body and 'name="EraName"' not in body)
            check('legacy add POST refused', req('artwork/object-add.php?type=eras', {'csrf': csrf, 'EraName': 'Should not add', 'EraYears': 'x'})[0] == 422)
            status, body, _ = req('artwork/objects.php?type=eras&name=Legacy+era&years=ancient')
            check('legacy textual selection still works', status == 200 and 'Legacy era' in body)
            status, body, _ = req('artwork/object-delete.php?type=eras&id=1', {'csrf': csrf})
            check('legacy delete POST explains upgrade requirement', status == 409 and 'Era identity upgrade is required' in body)
            check('legacy requests preserve data', sql('SELECT EraName, EraYears FROM Eras;') == before)
            print('COMPLETE:', count, 'checks', flush=True)
        finally:
            if created:
                run(compose + ['down', '--remove-orphans', '--rmi', 'local'], cwd=ROOT, env=env)
                details = json.loads(run(['docker', 'volume', 'inspect', volume], capture_output=True).stdout)[0]
                if details['Name'] != volume or details['Labels'].get('era-gate-owner') != tag:
                    raise RuntimeError('Volume ownership changed; refusing cleanup')
                run(['docker', 'volume', 'rm', volume])
                print('Cleaned owned project and volume:', project, volume, flush=True)

if __name__ == '__main__':
    main()
