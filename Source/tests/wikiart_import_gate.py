"""Disposable integration test for the offline WikiArt import (issue #16).

    python tests/wikiart_import_gate.py

Requires Docker Compose. Like the other gates, it creates a uniquely named
project and volume and never connects to an existing gallery. It checks:

1. Extraction in containers with no network and PHP's network functions
   disabled: exact artists, paintings, full dates and image mappings for the
   saved fixtures, and a clear diagnostic for each damaged-source case.
2. The insertion SQL: run twice on two copies of the database (one with
   NO_BACKSLASH_ESCAPES), with quotes, backslashes, Unicode and a long biography.
3. The administrator page: access control, a forced failure and a held lock
   that roll back completely, the import itself, a repeat import, re-importing
   one deleted artwork, the missing-upgrade gate, and that every record that
   existed before the import is unchanged.
"""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
SOURCES = ROOT / 'import' / 'wikiart' / 'sources'
tag = uuid.uuid4().hex
project = 'wikiartgate' + tag
volume = project + '_disposable_db'
count = 0

EXPECTED = {
    'Edouard Manet': ('1832-01-23', '1883-04-30', ['The Fifer', 'Lola de Valence', 'The Monet family in their garden at Argenteuil',
                                                   'The House at Rueil', 'Effect of Snow at Petit-Montrouge']),
    'Berthe Morisot': ('1841-01-14', '1895-03-02', ['The Cradle', 'The Old Track to Auvers', 'After Luncheon',
                                                    'Child in the Rose Garden', 'Julie Daydreaming']),
    'Alfred Sisley': ('1839-10-30', '1899-01-29', ['Flood at Port Marly', 'Molesey Weir at Hampton Court Morning',
                                                   'Under the bridge at Hampton Court', 'The Dam, Loing Canal at Saint Mammes', 'Moret sur Loing']),
}
NO_NETWORK = ['-d', 'allow_url_fopen=0', '-d',
              'disable_functions=fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect,curl_init,curl_exec']


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


def lines(text):
    """Rows of --batch output: values escape newlines, but may hold other line separators."""
    return [line for line in text.split('\n') if line]


def manifest(directory):
    return json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))


def save_manifest(directory, data):
    (directory / 'manifest.json').write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding='utf-8')


def rehash(directory, relative):
    """Record a deliberately edited fixture's new SHA-256, so the parser itself is tested."""
    import hashlib
    data = manifest(directory)
    data['files'][relative]['sha256'] = hashlib.sha256((directory / relative).read_bytes()).hexdigest()
    save_manifest(directory, data)


def edit(directory, relative, old, new, count_expected=1):
    path = directory / relative
    text = path.read_text(encoding='utf-8')
    if text.count(old) != count_expected:
        raise RuntimeError('fixture edit did not apply to ' + relative)
    path.write_text(text.replace(old, new), encoding='utf-8')
    rehash(directory, relative)


def main():
    global count
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        port = s.getsockname()[1]
    env = dict(os.environ, GALLERY_PORT=str(port), GALLERY_ROOT_PASSWORD='gallery-local-root')
    print('Loopback gallery port:', port, flush=True)
    with tempfile.TemporaryDirectory(prefix=project) as temp:
        temp = Path(temp)
        override = temp / 'override.yaml'
        override.write_text('volumes:\n  gallery-db:\n    external: true\n    name: ' + volume + '\n')
        compose = ['docker', 'compose', '-p', project, '-f', str(ROOT / 'compose.yaml'), '-f', str(override)]
        existing = run(['docker', 'volume', 'ls', '-q'], capture_output=True).stdout.splitlines()
        if volume in existing:
            raise RuntimeError('Disposable volume already exists; refusing to use it')
        created = False
        try:
            run(['docker', 'volume', 'create', '--label', 'wikiart-gate-owner=' + tag, volume], capture_output=True)
            created = True
            details = json.loads(run(['docker', 'volume', 'inspect', volume], capture_output=True).stdout)[0]
            if details['Name'] != volume or details['Labels'].get('wikiart-gate-owner') != tag:
                raise RuntimeError('Volume ownership check failed')
            run(compose + ['up', '-d', '--build', '--wait', 'db', 'web'], cwd=ROOT, env=env)
            db = project + '-db-1'
            web = project + '-web-1'
            web_image = project + '-web'

            def sql(statement, database='art', mode=None):
                prefix = ("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_BACKSLASH_ESCAPES');\n" if mode == 'nbe' else '')
                return run(['docker', 'exec', '-i', db, 'mariadb', '-uroot', '-pgallery-local-root', '--default-character-set=utf8mb4',
                            '--batch', '--skip-column-names', database], input=prefix + statement, capture_output=True).stdout

            def extract(directory, out=None):
                """Runs the CLI with no network on a copy of `directory`; returns (status, JSON, stdout)."""
                name = project + '-extract-' + uuid.uuid4().hex[:8]
                command = ['docker', 'create', '--name', name, '--network', 'none', web_image, 'php'] + NO_NETWORK + [
                    '/var/www/html/artwork/wikiart-cli.php', 'extract', '--source', '/src', '--json'] + (['--out', '/out'] if out else [])
                run(command, capture_output=True)
                try:
                    run(['docker', 'cp', str(directory) + '/.', name + ':/src'], capture_output=True)
                    result = subprocess.run(['docker', 'start', '-a', name], capture_output=True, text=True)
                    if out:
                        run(['docker', 'cp', name + ':/out', str(out)], capture_output=True)
                    data = json.loads(result.stdout) if result.stdout.strip().startswith('{') else None
                    return result.returncode, data, result.stdout + result.stderr
                finally:
                    subprocess.run(['docker', 'rm', '-f', name], capture_output=True)

            def fixture(label):
                directory = temp / ('case-' + re.sub(r'\W+', '-', label))
                shutil.copytree(SOURCES, directory)
                return directory

            def messages(data):
                return [(d['file'] or '') + ': ' + d['message'] for d in data['diagnostics'] if d['level'] == 'error']

            # ---------------------------------------------------------- 1. extraction
            status, data, _ = extract(SOURCES)
            check('extraction succeeds with no network', status == 0 and data and data['ok'] and not messages(data))
            check('exactly 3 artists and 15 paintings', data['counts'] == {'artists': 3, 'paintings': 15})
            files = manifest(SOURCES)['files']
            for artist in data['artists']:
                birth, death, titles = EXPECTED[artist['name']]
                check(artist['name'] + ' full dates', artist['birth']['iso'] == birth and artist['death']['iso'] == death)
                check(artist['name'] + ' five distinct expected paintings', [p['title'] for p in artist['paintings']] == titles)
                check(artist['name'] + ' biography extracted', len(artist['biography']) > 1000)
                check(artist['name'] + ' page traced to manifest', files[artist['page']]['sha256'] == artist['page_sha256'] and files[artist['page']]['url'] == artist['url'])
                for painting in artist['paintings']:
                    saved = painting['image']
                    check(painting['title'] + ' image and page traced', files[saved['file']]['sha256'] == saved['sha256']
                          and files[saved['file']]['url'] == saved['url'] and files[painting['page']]['url'] == painting['url']
                          and painting['key'] == 'wikiart:' + painting['url'].replace('https://www.wikiart.org', ''))
            manet = data['artists'][0]
            check('Manet paintings trace to Prof. Min reference thumbnails', all(p['reference_image'] and 'painting_files/' in p['reference_image']['file'] for p in manet['paintings']))
            check('all 15 images distinct', len({p['image']['sha256'] for a in data['artists'] for p in a['paintings']}) == 15)

            cases = []
            d = fixture('missing page'); (d / 'berthe-morisot/paintings/the-cradle-1872.html').unlink()
            cases.append(('missing painting page', d, 'berthe-morisot/paintings/the-cradle-1872.html: The painting page is missing.'))
            d = fixture('missing image'); (d / 'alfred-sisley/images/moret-sur-loing-1892-2.jpg').unlink()
            cases.append(('missing image', d, 'alfred-sisley/images/moret-sur-loing-1892-2.jpg: The painting image is missing.'))
            d = fixture('changed page')
            with open(d / 'alfred-sisley/artist.html', 'a', encoding='utf-8') as f:
                f.write('<!-- edited -->')
            cases.append(('changed page', d, 'alfred-sisley/artist.html: The artist page has changed since it was saved'))
            d = fixture('cut image'); path = d / 'berthe-morisot/images/after-luncheon.jpg'; path.write_bytes(path.read_bytes()[:20000]); rehash(d, 'berthe-morisot/images/after-luncheon.jpg')
            cases.append(('cut-off image', d, 'berthe-morisot/images/after-luncheon.jpg: The painting image could not be read completely'))
            d = fixture('not jpeg'); (d / 'edouard-manet/images/the-fifer-1866.jpg').write_bytes(b'<html>not an image</html>'); rehash(d, 'edouard-manet/images/the-fifer-1866.jpg')
            cases.append(('not a JPEG', d, 'edouard-manet/images/the-fifer-1866.jpg: The painting image is not a JPEG image.'))
            d = fixture('no birth'); edit(d, 'berthe-morisot/artist.html', 'itemprop="birthDate" >January 14, 1841</span>', 'class="x">January 14, 1841</span>')
            cases.append(('missing birth date', d, 'berthe-morisot/artist.html: The birth date was not found on the artist page.'))
            d = fixture('cut html'); path = d / 'alfred-sisley/paintings/flood-at-port-marly-1876.html'
            text = path.read_text(encoding='utf-8'); path.write_text(text[:text.index('<article')], encoding='utf-8'); rehash(d, 'alfred-sisley/paintings/flood-at-port-marly-1876.html')
            cases.append(('cut-off HTML', d, 'alfred-sisley/paintings/flood-at-port-marly-1876.html: The painting information block was not found'))
            d = fixture('bad utf8'); path = d / 'berthe-morisot/paintings/julie-daydreaming.html'; path.write_bytes(path.read_bytes().replace(b'Julie Daydreaming</h3>', b'Julie \xff\xfe Daydreaming</h3>')); rehash(d, 'berthe-morisot/paintings/julie-daydreaming.html')
            cases.append(('invalid UTF-8', d, 'berthe-morisot/paintings/julie-daydreaming.html: The painting page could not be parsed as UTF-8 HTML.'))
            d = fixture('emoji'); edit(d, 'berthe-morisot/paintings/the-cradle-1872.html', '<h3>The Cradle</h3>', '<h3>The Cradle \U0001F3A8</h3>')
            cases.append(('unstorable emoji', d, 'berthe-morisot/paintings/the-cradle-1872.html: The painting title contains emoji'))
            d = fixture('escape'); m = manifest(d); m['artists'][1]['paintings'][0]['page'] = '../../../etc/passwd'; save_manifest(d, m)
            cases.append(('path outside the folder', d, '../../../etc/passwd: The painting page path is not inside the source folder'))
            d = fixture('remote'); m = manifest(d); m['artists'][2]['paintings'][0]['image'] = 'https://uploads0.wikiart.org/images/alfred-sisley/flood-at-port-marly-1876.jpg'; save_manifest(d, m)
            cases.append(('remote address refused', d, 'https://uploads0.wikiart.org/images/alfred-sisley/flood-at-port-marly-1876.jpg: The painting image path is not inside the source folder'))
            d = fixture('bad json'); (d / 'manifest.json').write_text('{"format": 1, ', encoding='utf-8')
            cases.append(('invalid manifest', d, 'manifest.json: The manifest is not valid JSON.'))
            d = fixture('unlisted'); edit(d, 'berthe-morisot/artist.html', '&quot;paintingUrl&quot; : &quot;/en/berthe-morisot/julie-daydreaming&quot;', '&quot;paintingUrl&quot; : &quot;/en/berthe-morisot/other&quot;')
            edit(d, 'berthe-morisot/artist.html', 'href="/en/berthe-morisot/julie-daydreaming"', 'href="/en/berthe-morisot/other"')
            cases.append(('painting not on the artist page', d, 'berthe-morisot/paintings/julie-daydreaming.html: The painting is not listed on the saved artist page'))
            d = fixture('four'); m = manifest(d); m['artists'][2]['paintings'].pop(); save_manifest(d, m)
            cases.append(('wrong painting count', d, 'manifest.json: https://www.wikiart.org/en/alfred-sisley has 4 paintings listed; 5 are expected.'))
            d = fixture('wrong image'); m = manifest(d); p = m['artists'][1]['paintings']; p[0]['image'], p[1]['image'] = p[1]['image'], p[0]['image']; save_manifest(d, m)
            cases.append(('image mapped to the wrong painting', d, 'berthe-morisot/images/the-old-track-to-auvers-1863.jpg: The saved image is not the image shown on this painting page.'))
            for label, directory, expected in cases:
                status, result, output = extract(directory)
                found = messages(result) if result else []
                check('diagnostic: ' + label, status == 1 and result and not result['ok'] and any(m.startswith(expected) for m in found))

            d = fixture('scripts')
            edit(d, 'berthe-morisot/paintings/the-cradle-1872.html', '<h3>The Cradle</h3>',
                 '<h3>The Cradle</h3><script>document.querySelector("h3").textContent = "Changed by script"; fetch("https://example.invalid/")</script>'
                 '<img src="x" onerror="document.title = \'Changed\'">')
            status, result, _ = extract(d)
            cradle = result['artists'][1]['paintings'][0]
            check('saved scripts are data, never run', status == 0 and cradle['title'] == 'The Cradle')

            # ---------------------------------------------------------- 2. insertion SQL
            d = fixture('sql values')
            tricky = 'O\'Keeffe wrote "light" in C:\\new\\table; a back\\slash, 100% _wild_ \'quotes\' and Unicode: Édouard, ʁ, “curly”. '
            edit(d, 'berthe-morisot/artist.html', '<div id="info-tab-wikipediaArticle" class="wiki-layout-artist-info-tab selected">\n                            <p >',
                 '<div id="info-tab-wikipediaArticle" class="wiki-layout-artist-info-tab selected">\n                            <p >'
                 + tricky.replace('&', '&amp;').replace('<', '&lt;') + ('Long text. ' * 1200) + '<br /><br />')
            out = temp / 'sql-out'
            status, result, _ = extract(d, out)
            biography = result['artists'][1]['biography']
            check('tricky biography extracted', status == 0 and biography.startswith(tricky.strip()) and len(biography) > 15000)
            script = (out / 'import.sql').read_text(encoding='utf-8')
            images = sorted(p.name for p in (out / 'images').iterdir())
            check('SQL names one re-encoded image per painting', len(images) == 15 and all(re.fullmatch(r'upload-[0-9a-f]{32}\.jpg', n) for n in images)
                  and all(n[:-4] in script for n in images))
            dump = run(['docker', 'exec', db, 'mariadb-dump', '-uroot', '-pgallery-local-root', '--routines', 'art'], capture_output=True).stdout
            routes = {}
            for database, mode in [('art_sql_a', None), ('art_sql_b', 'nbe')]:
                sql('CREATE DATABASE ' + database + ';')
                run(['docker', 'exec', '-i', db, 'mariadb', '-uroot', '-pgallery-local-root', database], input=dump, capture_output=True)
                before = sql('SELECT COUNT(*) FROM Artists; SELECT COUNT(*) FROM Paintings;', database)
                sql(script, database, mode)
                first = sql('SELECT COUNT(*) FROM Artists; SELECT COUNT(*) FROM Paintings; SELECT RecordType, Outcome, COUNT(*) FROM art_import_sources GROUP BY 1, 2 ORDER BY 1, 2;', database)
                sql(script, database, mode)
                second = sql('SELECT COUNT(*) FROM Artists; SELECT COUNT(*) FROM Paintings; SELECT RecordType, Outcome, COUNT(*) FROM art_import_sources GROUP BY 1, 2 ORDER BY 1, 2;', database)
                b = [int(x) for x in before.split()]
                check('SQL adds 2 artists and 15 paintings (' + database + ')', first.split()[:2] == [str(b[0] + 2), str(b[1] + 15)])
                check('SQL links Manet and records 18 sources (' + database + ')', 'artist\tcreated\t2' in first and 'artist\tmatched\t1' in first and 'painting\tcreated\t15' in first)
                check('SQL run twice adds nothing (' + database + ')', second == first)
                stored = bytes.fromhex(sql("SELECT HEX(Details) FROM Artists WHERE LastName = 'Morisot';", database).strip()).decode('utf-8')
                check('quotes, backslashes, Unicode and long text stored exactly (' + database + ')', stored == biography)
                routes[database] = sql('SELECT a.LastName, p.Title, p.YearOfWork, IFNULL(p.Width, \'-\'), IFNULL(p.Height, \'-\'), p.Medium, p.Excerpt, p.CopyrightText, IFNULL(p.GalleryID, \'-\') '
                                       'FROM art_import_sources s JOIN Paintings p ON p.PaintingID = s.PaintingID JOIN Artists a ON a.ArtistID = p.ArtistID ORDER BY s.SourceKey;', database)

            # ---------------------------------------------------------- 3. administrator page
            base = 'http://127.0.0.1:' + str(port) + '/'

            def client():
                return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

            def req(opener, path, data=None):
                try:
                    response = opener.open(base + path, None if data is None else urllib.parse.urlencode(data).encode(), timeout=60)
                except urllib.error.HTTPError as err:
                    response = err
                return response.status, response.read().decode('utf-8', 'replace'), response.headers

            def token(body):
                return re.search(r'name="csrf" value="([^"]+)', body).group(1)

            def login(user, password):
                opener = client()
                _, body, _ = req(opener, 'artwork/art-login.php')
                status, _, _ = req(opener, 'artwork/authenticate.php', {'csrf': token(body), 'userid': user, 'password': password})
                check('login ' + user, status == 303)
                return opener

            def tables():
                names = sql("SELECT table_name FROM information_schema.tables WHERE table_schema = 'art' AND table_type = 'BASE TABLE' ORDER BY table_name;").split()
                return {line.split('\t')[0].split('.', 1)[1]: line.split('\t')[1] for line in sql('CHECKSUM TABLE ' + ', '.join('`' + n + '`' for n in names) + ';').strip().splitlines()}

            def uploads():
                return sorted(run(['docker', 'exec', web, 'ls', '/var/www/html/artwork/images/uploads'], capture_output=True).stdout.split())

            # Records an import must leave alone: a different "Alfred Sisley" born 1840 and
            # a Picasso artwork titled "The Cradle".
            sql("INSERT INTO Artists (FirstName, LastName, Nationality, YearOfBirth, YearOfDeath, Details) VALUES ('Alfred', 'Sisley', 'England', 1840, 1900, 'A different person.');"
                "INSERT INTO Paintings (ArtistID, GalleryID, ImageFileName, Title, YearOfWork, Medium) VALUES (1, 20, 'wikiart-gate-unrelated', 'The Cradle', 1872, 'Oil on canvas');")
            keys = {'Artists': 'ArtistID', 'Paintings': 'PaintingID', 'PaintingGenres': 'PaintingGenreID'}

            def row_hashes(table, maximum=None):
                """One SHA-256 per row over every column, so text with line breaks compares safely."""
                columns = sql("SELECT column_name FROM information_schema.columns WHERE table_schema = 'art' AND table_name = '" + table + "' ORDER BY ordinal_position;").split()
                digest = 'SHA2(CONCAT_WS(\',\', ' + ', '.join("IFNULL(HEX(`" + c + "`), 'NULL')" for c in columns) + '), 256)'
                where = '' if maximum is None else ' WHERE ' + keys[table] + ' <= ' + str(maximum)
                return sql('SELECT ' + keys[table] + ', ' + digest + ' FROM ' + table + where + ' ORDER BY ' + keys[table] + ';')

            existing = {table: row_hashes(table) for table in keys}
            baseline = tables()
            baseline_uploads = uploads()

            status, _, headers = req(client(), 'artwork/import.php')
            check('signed-out visitor sent to login', status == 303 and headers.get('Location') == 'art-login.php')
            visitor = login('user2', 'pass2')
            status, body, _ = req(visitor, 'artwork/import.php')
            check('client gets 403 on the import page', status == 403 and 'Administrators only' in body)
            status, _, _ = req(visitor, 'artwork/import.php', {'csrf': token(req(visitor, 'artwork/account.php')[1])})
            check('client POST refused', status == 403)
            admin = login('user1', 'pass1')
            status, body, _ = req(admin, 'artwork/import.php')
            check('administrator preview', status == 200 and 'Import 18 records' in body and 'Already in the library: will be linked, not changed' in body)
            check('preview shows full birth dates', 'January 14, 1841, Bourges, France' in body and 'October 30, 1839, Paris, France' in body)
            csrf = token(body)
            check('POST without a form token refused', req(admin, 'artwork/import.php', {'csrf': 'x'})[0] == 403)
            check('GET does not change data', tables() == baseline)

            sql("DELIMITER //\nCREATE TRIGGER wikiart_gate_fail BEFORE INSERT ON Paintings FOR EACH ROW BEGIN "
                "IF NEW.Title = 'Julie Daydreaming' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END//\nDELIMITER ;\n")
            status, body, _ = req(admin, 'artwork/import.php', {'csrf': csrf})
            check('forced failure reported', status == 500 and 'rolled back and nothing was added' in body)
            check('forced failure rolled back every table', tables() == baseline)
            check('forced failure removed its stored images', uploads() == baseline_uploads)
            sql('DROP TRIGGER wikiart_gate_fail;')

            holder = subprocess.Popen(['docker', 'exec', '-i', db, 'mariadb', '-uroot', '-pgallery-local-root', 'art', '-e',
                                       "SELECT GET_LOCK('team43_wikiart_import', 0), SLEEP(6);"], stdout=subprocess.DEVNULL)
            time.sleep(2)
            status, body, _ = req(admin, 'artwork/import.php', {'csrf': csrf})
            holder.wait()
            check('a running import blocks a second one', status == 409 and 'Another import is running' in body)
            check('blocked import changed nothing', tables() == baseline and uploads() == baseline_uploads)

            status, _, headers = req(admin, 'artwork/import.php', {'csrf': csrf})
            check('import succeeds', status == 303 and headers.get('Location') == 'import.php')
            status, body, _ = req(admin, 'artwork/import.php')
            check('import summary', 'added 2 artists and 15 artworks, and linked 1 existing record' in body and 'Everything is imported' in body)
            counts = sql("SELECT RecordType, Outcome, COUNT(*) FROM art_import_sources GROUP BY 1, 2 ORDER BY 1, 2;")
            check('18 sources recorded', counts == 'artist\tcreated\t2\nartist\tmatched\t1\npainting\tcreated\t15\n')
            check('Manet linked to existing artist 12', sql("SELECT ArtistID FROM art_import_sources WHERE SourceKey = 'wikiart:/en/edouard-manet';").strip() == '12')
            for table, rows in existing.items():
                maximum = max(int(line.split('\t')[0]) for line in lines(rows))
                check(table + ': every existing row unchanged', row_hashes(table, maximum) == rows)
            after = tables()
            for table in baseline:
                if table not in ('Artists', 'Paintings', 'PaintingGenres', 'art_import_sources'):
                    check('unchanged table ' + table, after[table] == baseline[table])
            check('2 artists and 15 paintings added', sql("SELECT (SELECT COUNT(*) FROM Artists) - " + str(len(lines(existing['Artists']))) + ", (SELECT COUNT(*) FROM Paintings) - "
                                                          + str(len(lines(existing['Paintings']))) + ";").split() == ['2', '15'])
            check('each new painting has one genre', sql('SELECT COUNT(*) FROM PaintingGenres j JOIN art_import_sources s ON s.PaintingID = j.PaintingID;').strip() == '15')
            check('provenance keeps full dates', '"iso":"1841-01-14"' in sql("SELECT Extracted FROM art_import_sources WHERE SourceKey = 'wikiart:/en/berthe-morisot';"))
            imported = [line.split('\t') for line in sql('SELECT p.PaintingID, p.ImageFileName, p.Title, a.LastName FROM art_import_sources s JOIN Paintings p ON p.PaintingID = s.PaintingID '
                                                         'JOIN Artists a ON a.ArtistID = p.ArtistID ORDER BY s.SourceKey;').strip().splitlines()]
            check('15 imported paintings with expected titles', sorted(t for _, _, t, _ in imported) == sorted(t for v in EXPECTED.values() for t in v[2]))
            new_uploads = sorted(set(uploads()) - set(baseline_uploads))
            check('15 new upload files, one per painting', new_uploads == sorted(name + '.jpg' for _, name, _, _ in imported)
                  and all(re.fullmatch(r'upload-[0-9a-f]{32}\.jpg', n) for n in new_uploads))
            for painting_id, name, title, _ in imported:
                status, body, _ = req(admin, 'artwork/painting.php?id=' + painting_id)
                check('artwork page shows ' + title, status == 200 and 'images/uploads/' + name + '.jpg' in body)
                image = urllib.request.urlopen(base + 'artwork/images/uploads/' + name + '.jpg', timeout=20)
                check('local image served for ' + title, image.status == 200 and image.headers['Content-Type'] == 'image/jpeg' and image.read(3) == b'\xff\xd8\xff')
            morisot = sql("SELECT ArtistID FROM Artists WHERE LastName = 'Morisot';").strip()
            status, body, _ = req(admin, 'artwork/objects.php?type=artists&id=' + morisot)
            check('Morisot artist page lists her five artworks', status == 200 and all(t.replace("'", '&#039;') in body for t in EXPECTED['Berthe Morisot'][2]))
            check('unrelated Sisley and Cradle kept', sql("SELECT COUNT(*) FROM Artists WHERE LastName = 'Sisley';").strip() == '2'
                  and sql("SELECT ArtistID FROM Paintings WHERE ImageFileName = 'wikiart-gate-unrelated';").strip() == '1')
            web_route = sql('SELECT a.LastName, p.Title, p.YearOfWork, IFNULL(p.Width, \'-\'), IFNULL(p.Height, \'-\'), p.Medium, p.Excerpt, p.CopyrightText, IFNULL(p.GalleryID, \'-\') '
                            'FROM art_import_sources s JOIN Paintings p ON p.PaintingID = s.PaintingID JOIN Artists a ON a.ArtistID = p.ArtistID ORDER BY s.SourceKey;')
            check('page import and SQL store the same artwork values', web_route == routes['art_sql_a'] == routes['art_sql_b'])
            check('health still ready', urllib.request.urlopen(base + 'health.php', timeout=20).read() == b'ready\n')

            snapshot = tables()
            status, _, _ = req(admin, 'artwork/import.php', {'csrf': csrf})
            status, body, _ = req(admin, 'artwork/import.php')
            check('repeat import adds nothing', 'Nothing new to import' in body and tables() == snapshot and set(uploads()) == set(baseline_uploads) | set(new_uploads))

            fifer = sql("SELECT PaintingID FROM art_import_sources WHERE SourceKey = 'wikiart:/en/edouard-manet/the-fifer-1866';").strip()
            status, _, _ = req(admin, 'artwork/art-delete.php', {'csrf': csrf, 'painting_id': fifer})
            check('imported artwork deleted through the gallery', status == 303 and sql('SELECT COUNT(*) FROM Paintings WHERE PaintingID = ' + fifer + ';').strip() == '0')
            check('its provenance row went with it', sql("SELECT COUNT(*) FROM art_import_sources WHERE SourceKey = 'wikiart:/en/edouard-manet/the-fifer-1866';").strip() == '0')
            status, body, _ = req(admin, 'artwork/import.php')
            check('preview offers only the deleted artwork', 'Import 1 record' in body)
            req(admin, 'artwork/import.php', {'csrf': csrf})
            check('deleted artwork imported again, once', sql("SELECT COUNT(*) FROM Paintings WHERE Title = 'The Fifer';").strip() == '1'
                  and sql('SELECT COUNT(*) FROM art_import_sources;').strip() == '18')

            snapshot = tables()
            sql('RENAME TABLE art_import_sources TO art_import_sources_hidden;')
            status, body, _ = req(admin, 'artwork/import.php')
            check('missing upgrade explained', status == 200 and 'Database upgrade required' in body and 'name="csrf"' not in body.split('<main', 1)[1].split('</main>')[0])
            check('missing upgrade refuses POST', req(admin, 'artwork/import.php', {'csrf': csrf})[0] == 409)
            check('rest of gallery works without the upgrade', req(admin, 'artwork/art-main.php')[0] == 200 and urllib.request.urlopen(base + 'health.php', timeout=20).read() == b'ready\n')
            sql('RENAME TABLE art_import_sources_hidden TO art_import_sources;')
            for attempt in (1, 2):
                run(['docker', 'exec', db, 'sh', '-c', 'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" < /docker-entrypoint-initdb.d/11-wikiart-import.sql'])
            check('upgrade 11 is safe to repeat', tables() == snapshot and 'Everything is imported' in req(admin, 'artwork/import.php')[1])
            print('COMPLETE:', count, 'checks', flush=True)
        finally:
            if created:
                run(compose + ['down', '--volumes', '--remove-orphans', '--rmi', 'local'], cwd=ROOT, env=env)
                details = json.loads(run(['docker', 'volume', 'inspect', volume], capture_output=True).stdout)[0]
                if details['Name'] != volume or details['Labels'].get('wikiart-gate-owner') != tag:
                    raise RuntimeError('Volume ownership changed; refusing cleanup')
                run(['docker', 'volume', 'rm', volume])
                print('Cleaned owned project and volume:', project, volume, flush=True)


if __name__ == '__main__':
    main()
