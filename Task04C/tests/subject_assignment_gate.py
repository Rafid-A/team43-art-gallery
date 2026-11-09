"""Disposable integration test: python tests/subject_assignment_gate.py. Requires Docker Compose.

Creates a uniquely named volume and project; never connects to an existing gallery.
Fresh subject IDs and names are checked against the authors' versioned mapping.
Page checks compare each assignment row with its own stored values. Migration
checks cover existing IDs, custom data, reruns, conflicts, and guarded rollback.
"""
import html
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import threading
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
tag = uuid.uuid4().hex
project = 'subjectgate' + tag
volume = project + '_disposable_db'
OWNER_LABEL = 'subject-gate-owner'
SEEDED_DUPLICATE_PAIRS = 7
SESSIONS = 6
ROUNDS = 5
count = 0
reports = []


def run(args, **kwargs):
    print('+', subprocess.list2cmdline(args), flush=True)
    return subprocess.run(args, check=True, text=True, **kwargs)


def check(label, condition):
    global count
    if not condition:
        raise AssertionError(label)
    count += 1
    print('PASS', label, flush=True)


def report(message):
    reports.append(message)
    print('REPORT', message, flush=True)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def esc(value):
    return html.escape(value, quote=True).replace('&#x27;', '&#039;')


class AssignmentRows(HTMLParser):
    """Collect each assignment directory link and its own heading."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.rows = []
        self.current = None
        self.in_heading = False

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'a' and 'directory-row' in attrs.get('class', '').split():
            query = urllib.parse.parse_qs(urllib.parse.urlsplit(attrs.get('href', '')).query)
            if query.get('type') == ['painting-subjects'] and len(query.get('id', [])) == 1:
                self.current = [query['id'][0], []]
        elif tag == 'h2' and self.current is not None:
            self.in_heading = True

    def handle_data(self, data):
        if self.in_heading and self.current is not None:
            self.current[1].append(data)

    def handle_endtag(self, tag):
        if tag == 'h2':
            self.in_heading = False
        elif tag == 'a' and self.current is not None:
            self.rows.append((self.current[0], ''.join(self.current[1])))
            self.current = None


def assignment_rows(body):
    parser = AssignmentRows()
    parser.feed(body)
    parser.close()
    return parser.rows


def assignment_row_matches(rows, assignment, expected_heading):
    return [heading for row_id, heading in rows if row_id == assignment] == [expected_heading]


def token(body):
    return re.search(r'name="csrf" value="([^"]+)', body).group(1)


class Session:
    """One browser: its own cookie jar, so its own PHP session and CSRF token."""

    def __init__(self, base):
        self.base = base
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
        self.csrf = None

    def req(self, path, data=None):
        try:
            response = self.client.open(self.base + path, None if data is None else urllib.parse.urlencode(data).encode(), timeout=60)
        except urllib.error.HTTPError as err:
            response = err
        return response.status, response.read().decode('utf-8', 'replace'), response.headers

    def login(self):
        _, body, _ = self.req('artwork/art-login.php')
        status, _, headers = self.req('artwork/authenticate.php', {'csrf': token(body), 'userid': 'user1', 'password': 'pass1'})
        if status != 303 or headers.get('Location') != 'art-main.php':
            raise AssertionError('login failed with status ' + str(status))
        self.csrf = token(self.req('artwork/object-add.php?type=painting-subjects')[1])
        return self

    def assign(self, painting, subject, query=''):
        return self.req('artwork/object-add.php?type=painting-subjects' + query, {'csrf': self.csrf, 'PaintingID': painting, 'SubjectID': subject})


def simultaneous(sessions, jobs):
    """Run one job per session at once; a barrier releases them together."""
    barrier = threading.Barrier(len(jobs))
    results = [None] * len(jobs)

    def worker(index):
        barrier.wait(timeout=60)
        try:
            status, body, _ = jobs[index](sessions[index])
            results[index] = (status, body)
        except Exception as ex:  # A dropped request must fail the check, not vanish with its thread.
            results[index] = ('exception', repr(ex))

    threads = [threading.Thread(target=worker, args=(i,)) for i in range(len(jobs))]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join()
    return results


def main():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        port = s.getsockname()[1]
    env = dict(os.environ, GALLERY_PORT=str(port))
    print('Loopback gallery port:', port, flush=True)
    with tempfile.TemporaryDirectory(prefix=project) as temp:
        override = Path(temp) / 'override.yaml'
        override.write_text('volumes:\n  task04c-db:\n    external: true\n    name: ' + volume + '\n')
        compose = ['docker', 'compose', '-p', project, '-f', str(ROOT / 'compose.yaml'), '-f', str(override)]
        existing = run(['docker', 'volume', 'ls', '-q'], capture_output=True).stdout.splitlines()
        if volume in existing:
            raise RuntimeError('Disposable volume already exists; refusing to use it')
        created = False
        try:
            run(['docker', 'volume', 'create', '--label', OWNER_LABEL + '=' + tag, volume], capture_output=True)
            created = True
            details = json.loads(run(['docker', 'volume', 'inspect', volume], capture_output=True).stdout)[0]
            if details['Name'] != volume or details['Labels'].get(OWNER_LABEL) != tag:
                raise RuntimeError('Volume ownership check failed')
            print('Verified new isolated volume:', volume, flush=True)
            run(compose + ['up', '-d', '--build', '--wait', 'db', 'web'], cwd=ROOT, env=env)
            exercise(project + '-db-1', 'http://127.0.0.1:' + str(port) + '/')
            for line in reports:
                print('REPORT', line, flush=True)
            print('COMPLETE:', count, 'checks', flush=True)
        finally:
            if created:
                run(compose + ['down', '--remove-orphans', '--rmi', 'local'], cwd=ROOT, env=env)
                details = json.loads(run(['docker', 'volume', 'inspect', volume], capture_output=True).stdout)[0]
                if details['Name'] != volume or details['Labels'].get(OWNER_LABEL) != tag:
                    raise RuntimeError('Volume ownership changed; refusing cleanup')
                run(['docker', 'volume', 'rm', volume])
                print('Cleaned owned project and volume:', project, volume, flush=True)


def exercise(container, base):
    def sql(statement):
        return subprocess.run(['docker', 'exec', '-i', container, 'mariadb', '-uroot', '-ptask04c-local-root', '--batch', '--skip-column-names', 'art'], input=statement, capture_output=True, text=True, check=True).stdout

    def table(statement):
        return [line.split('\t') for line in sql(statement).splitlines()]

    def links():
        return [tuple(row) for row in table('SELECT PaintingSubjectID, PaintingID, SubjectID FROM PaintingSubjects ORDER BY PaintingSubjectID;')]

    def parents():
        return sql('SELECT PaintingID, HEX(Title) FROM Paintings ORDER BY PaintingID; SELECT SubjectID, HEX(SubjectName) FROM Subjects ORDER BY SubjectID;')

    def duplicates():
        return table('SELECT PaintingID, SubjectID, GROUP_CONCAT(PaintingSubjectID ORDER BY PaintingSubjectID) FROM PaintingSubjects GROUP BY PaintingID, SubjectID HAVING COUNT(*)>1 ORDER BY PaintingID, SubjectID;')

    def subject_name(subject):
        return table('SELECT SubjectName FROM Subjects WHERE SubjectID=' + str(int(subject)) + ';')[0][0]

    def new_pairs(limit):
        """Existing artwork/subject pairs that have no assignment yet."""
        return table('SELECT p.PaintingID, s.SubjectID FROM Paintings p CROSS JOIN Subjects s WHERE NOT EXISTS (SELECT 1 FROM PaintingSubjects j WHERE j.PaintingID=p.PaintingID AND j.SubjectID=s.SubjectID) ORDER BY p.PaintingID, s.SubjectID LIMIT ' + str(int(limit)) + ';')

    def pair_rows(painting, subject):
        return [row for row in links() if row[1] == str(painting) and row[2] == str(subject)]

    def integrity_count(body, label):
        match = re.search('<dt>' + re.escape(label) + r'</dt><dd[^>]*>(\d+) ', body)
        return int(match.group(1)) if match else None

    browser = Session(base).login()
    check('login and CSRF token', bool(browser.csrf))

    # 7 (first half): state of a fresh initialization, before any action below.
    fresh_duplicates = duplicates()
    if len(fresh_duplicates) != SEEDED_DUPLICATE_PAIRS:
        raise AssertionError('expected ' + str(SEEDED_DUPLICATE_PAIRS) + ' seeded duplicate pairs, found ' + str(len(fresh_duplicates)))
    check('fresh init keeps the seeded duplicate pairs', True)
    before = links()
    status, body, _ = browser.req('artwork/integrity.php')
    check('integrity reports duplicate pairs', status == 200 and integrity_count(body, 'Duplicate subject pairs') == SEEDED_DUPLICATE_PAIRS)
    check('integrity does not repair duplicates', links() == before and duplicates() == fresh_duplicates)

    # 1. Views show the stored name for each ID.
    subjects = table('SELECT SubjectID, SubjectName FROM Subjects ORDER BY SubjectID;')
    check('subjects are present', len(subjects) > 0)
    check('fresh subject IDs and names match the author source', subjects == [
        ['1', 'Animals'], ['2', 'Landscape'], ['3', 'Music'], ['4', 'Sports'], ['5', 'Abstract'],
        ['6', 'Transportation'], ['7', 'Architecture'], ['9', 'Religion'], ['10', 'Still Life'],
        ['11', 'People'], ['12', 'Botanical'], ['13', 'Cuisine'], ['14', 'History'], ['15', 'Love'],
        ['16', 'Family'], ['17', 'Performing Arts'], ['18', 'Allegory'], ['19', 'Mythological'],
        ['20', 'Power'], ['21', 'Military'], ['22', 'Abstract']])
    status, body, _ = browser.req('artwork/object-add.php?type=painting-subjects')
    check('selector page loads', status == 200 and 'name="SubjectID"' in body)
    check('selector lists every subject with its ID', all(re.search('<option value="' + sid + r'"\s*(selected)?\s*>' + re.escape(esc(name)) + ' · #' + sid + '</option>', body) for sid, name in subjects))
    assignment, painting, subject = links()[0]
    name = subject_name(subject)
    subject_link = 'objects.php?type=subjects&amp;id=' + subject
    status, body, _ = browser.req('artwork/painting.php?id=' + painting)
    check('artwork page shows stored subject name', status == 200 and esc(name) + ' · #' + subject in body)
    check('artwork page links to the subject record', subject_link in body)
    status, body, _ = browser.req('artwork/objects.php?type=painting-subjects&id=' + assignment)
    check('assignment detail shows stored subject name', status == 200 and esc(name) + ' · #' + subject in body)
    check('assignment detail links to the subject record', subject_link in body)
    status, body, _ = browser.req('artwork/objects.php?type=painting-subjects')
    listed = assignment_rows(body)
    check('assignment list has rows', status == 200 and len(listed) > 0)
    expected = {row[0]: bytes.fromhex(row[1]).decode('utf-8') + ' · ' + bytes.fromhex(row[2]).decode('utf-8') + ' · #' + row[3] for row in table(
        'SELECT j.PaintingSubjectID, HEX(p.Title), HEX(s.SubjectName), s.SubjectID FROM PaintingSubjects j '
        'JOIN Paintings p ON p.PaintingID=j.PaintingID JOIN Subjects s ON s.SubjectID=j.SubjectID;')}
    check('each listed assignment row shows its own stored subject name',
          all(row_id in expected and assignment_row_matches(listed, row_id, expected[row_id]) for row_id, _ in listed))
    swapped = assignment_rows('<a class="directory-row" href="objects.php?type=painting-subjects&amp;id=1"><h2>Work · Wrong · #2</h2></a>'
                              '<a class="directory-row" href="objects.php?type=painting-subjects&amp;id=2"><h2>Work · Right &amp; sound · #1</h2></a>')
    check('a subject name in another assignment row cannot satisfy the check',
          not assignment_row_matches(swapped, '1', 'Work · Right & sound · #1')
          and assignment_row_matches(swapped, '2', 'Work · Right & sound · #1'))
    status, body, _ = browser.req('artwork/object-add.php?type=painting-subjects&painting=' + painting)
    check('artwork link preselects the artwork', status == 200 and re.search('<option value="' + painting + r'"\s*selected', body) is not None)

    # 2. A valid pair creates exactly one row with exactly those IDs.
    painting, subject = new_pairs(1)[0]
    before = links()
    held_parents = parents()
    status, _, headers = browser.assign(painting, subject)
    match = re.search(r'[?&]id=(\d+)', headers.get('Location', ''))
    check('valid pair is accepted', status == 303 and match is not None)
    new_rows = [row for row in links() if row not in before]
    check('valid pair creates exactly one row', len(links()) == len(before) + 1 and new_rows == [(match.group(1), painting, subject)])
    check('valid pair keeps all other rows', [row for row in links() if row in before] == before)
    check('valid pair changes no parents', parents() == held_parents)
    duplicate_painting, duplicate_subject = painting, subject

    painting, subject = new_pairs(1)[0]
    before = links()
    status, _, headers = browser.assign(painting, subject, '&painting=' + painting)
    check('assigning from an artwork returns to it', status == 303 and headers.get('Location') == 'painting.php?id=' + painting)
    check('assigning from an artwork creates one row', len(links()) == len(before) + 1 and len(pair_rows(painting, subject)) == 1)

    # 3. Rejected requests write nothing.
    painting, subject = new_pairs(1)[0]
    missing_painting = int(table('SELECT MAX(PaintingID) FROM Paintings;')[0][0]) + 1000
    missing_subject = int(table('SELECT MAX(SubjectID) FROM Subjects;')[0][0]) + 1000
    rejected = [
        ('duplicate pair', {'PaintingID': duplicate_painting, 'SubjectID': duplicate_subject}, 'already has'),
        ('nonexistent artwork', {'PaintingID': missing_painting, 'SubjectID': subject}, 'selected artwork no longer exists'),
        ('nonexistent subject', {'PaintingID': painting, 'SubjectID': missing_subject}, 'selected subject no longer exists'),
        ('omitted artwork', {'SubjectID': subject}, 'Choose an existing Artwork'),
        ('omitted subject', {'PaintingID': painting}, 'Choose an existing Subject'),
    ]
    for bad in ('abc', '0', '-1', '1.5', '', ' ', '1 OR 1=1'):
        rejected.append(('artwork ID ' + repr(bad), {'PaintingID': bad, 'SubjectID': subject}, 'Choose an existing Artwork'))
        rejected.append(('subject ID ' + repr(bad), {'PaintingID': painting, 'SubjectID': bad}, 'Choose an existing Subject'))
    before = links()
    held_parents = parents()
    for label, fields, message in rejected:
        status, body, _ = browser.req('artwork/object-add.php?type=painting-subjects', dict(fields, csrf=browser.csrf))
        check(label + ' rejected with its reason', status == 422 and message in body)
        check(label + ' writes nothing', links() == before)
    for label, csrf in (('wrong CSRF token', 'x' * 64), ('empty CSRF token', '')):
        status, _, _ = browser.req('artwork/object-add.php?type=painting-subjects', {'csrf': csrf, 'PaintingID': painting, 'SubjectID': subject})
        check(label + ' rejected', status == 403)
        check(label + ' writes nothing', links() == before)
    status, _, _ = browser.req('artwork/object-add.php?type=painting-subjects', {'PaintingID': painting, 'SubjectID': subject})
    check('omitted CSRF token rejected', status == 403)
    check('omitted CSRF token writes nothing', links() == before)
    check('rejected requests change no parents', parents() == held_parents)

    # 4. Separate sessions submitting one new pair together create one row.
    painting, subject = new_pairs(1)[0]
    sessions = [Session(base).login() for _ in range(SESSIONS)]
    before = links()
    results = simultaneous(sessions, [lambda session: session.assign(painting, subject)] * SESSIONS)
    statuses = sorted(str(result[0]) for result in results)
    print('Concurrent same-pair statuses:', statuses, flush=True)
    check('concurrent same pair creates one row', len(pair_rows(painting, subject)) == 1 and len(links()) == len(before) + 1)
    check('concurrent same pair accepts exactly one request', statuses.count('303') == 1)
    check('concurrent same pair rejects the rest', statuses.count('422') == SESSIONS - 1)
    check('concurrent same pair names the duplicate', sum('already has' in result[1] for result in results if result[0] == 422) == SESSIONS - 1)

    # Genre assignments share the same duplicate check.
    painting, genre = table('SELECT p.PaintingID, g.GenreID FROM Paintings p CROSS JOIN Genres g WHERE NOT EXISTS (SELECT 1 FROM PaintingGenres j WHERE j.PaintingID=p.PaintingID AND j.GenreID=g.GenreID) ORDER BY p.PaintingID, g.GenreID LIMIT 1;')[0]
    results = simultaneous(sessions, [lambda session: session.req('artwork/object-add.php?type=painting-genres', {'csrf': session.csrf, 'PaintingID': painting, 'GenreID': genre})] * SESSIONS)
    statuses = sorted(str(result[0]) for result in results)
    print('Concurrent same-genre statuses:', statuses, flush=True)
    check('concurrent same genre creates one row', table('SELECT COUNT(*) FROM PaintingGenres WHERE PaintingID=' + painting + ' AND GenreID=' + genre + ';') == [['1']])
    check('concurrent same genre accepts exactly one request', statuses.count('303') == 1 and statuses.count('422') == SESSIONS - 1)

    # 5. Distinct pairs on adjacent artworks with no assignments, so inserts share an
    # index gap. The seed has no such artworks, so the disposable database gets them.
    round_subjects = [row[0] for row in subjects[:4]]
    retries = 0
    for number in range(ROUNDS):
        names = ['gate-' + tag[:8] + '-' + str(number) + '-' + str(index) for index in range(len(round_subjects))]
        sql('INSERT INTO Paintings (ImageFileName, Title) VALUES ' + ', '.join("('" + value + "', '" + value + "')" for value in names) + ';')
        paintings = [row[0] for row in table("SELECT PaintingID FROM Paintings WHERE ImageFileName LIKE 'gate-" + tag[:8] + '-' + str(number) + "-%' ORDER BY PaintingID;")]
        check('round ' + str(number) + ' artworks are adjacent and unassigned', int(paintings[-1]) - int(paintings[0]) == len(paintings) - 1 and not [row for row in links() if row[1] in paintings])
        pairs = list(zip(paintings, round_subjects))
        before = links()
        jobs = [(lambda session, pair=pair: session.assign(pair[0], pair[1])) for pair in pairs]
        results = simultaneous(sessions[:len(pairs)], jobs)
        for pair, (status, body) in zip(pairs, results):
            rows = pair_rows(pair[0], pair[1])
            if status == 303:
                check('round ' + str(number) + ' accepted pair has one row', len(rows) == 1)
            elif status == 422 and 'could not be added' in body:
                retries += 1
                check('round ' + str(number) + ' retry error wrote nothing', rows == [])
            else:
                raise AssertionError('distinct pair got unexpected result ' + str(status))
        accepted = sum(status == 303 for status, _ in results)
        check('round ' + str(number) + ' adds only accepted rows', len(links()) == len(before) + accepted and [row for row in links() if row in before] == before)
    total = ROUNDS * len(round_subjects)
    check(str(total) + ' distinct concurrent assignments get no retry error (' + str(retries) + ' did)', retries == 0)

    # 6. Removing one link leaves its parents and every other link.
    held_duplicates = {identifier for row in duplicates() for identifier in row[2].split(',')}
    target = next(row for row in links() if row[0] not in held_duplicates and len([other for other in links() if other[1] == row[1]]) > 1)
    assignment = target[0]
    before = links()
    held_parents = parents()
    genres = sql('SELECT * FROM PaintingGenres ORDER BY PaintingGenreID;')
    delete_path = 'artwork/object-delete.php?type=painting-subjects&id=' + assignment
    status, body, _ = browser.req(delete_path)
    check('removal asks for confirmation', status == 200 and 'Only this assignment will be removed' in body)
    check('removal confirmation writes nothing', links() == before)
    check('removal with a bad CSRF token rejected', browser.req(delete_path, {'csrf': 'x' * 64})[0] == 403)
    check('rejected removal writes nothing', links() == before)
    check('removal is accepted', browser.req(delete_path, {'csrf': browser.csrf})[0] == 303)
    check('removal deletes only that row', links() == [row for row in before if row != target])
    check('removal keeps both parents', parents() == held_parents)
    check('removal keeps genre assignments', sql('SELECT * FROM PaintingGenres ORDER BY PaintingGenreID;') == genres)
    after = links()
    check('stale removal is handled', browser.req(delete_path, {'csrf': browser.csrf})[0] == 303)
    check('stale removal writes nothing', links() == after)

    # 8. Identically named subjects stay separate records.
    abstracts = [row[0] for row in table("SELECT SubjectID FROM Subjects WHERE SubjectName='Abstract' ORDER BY SubjectID;")]
    if len(abstracts) != 2:
        raise AssertionError("expected two subject records named 'Abstract', found " + str(len(abstracts)) + ': ' + str(abstracts))
    check('two Abstract records exist', True)
    for sid in abstracts:
        status, body, _ = browser.req('artwork/objects.php?type=subjects&id=' + sid)
        check('Abstract #' + sid + ' selectable by ID', status == 200 and '<dt>Record ID</dt><dd>' + sid + '</dd>' in body and 'Abstract' in body)
    status, body, _ = browser.req('artwork/object-add.php?type=painting-subjects')
    check('selector tells the Abstract records apart', all('Abstract · #' + sid + '</option>' in body for sid in abstracts))
    painting = table('SELECT p.PaintingID FROM Paintings p WHERE NOT EXISTS (SELECT 1 FROM PaintingSubjects j WHERE j.PaintingID=p.PaintingID AND j.SubjectID IN (' + ','.join(abstracts) + ')) ORDER BY p.PaintingID LIMIT 1;')[0][0]
    before = links()
    for sid in abstracts:
        check('assign Abstract #' + sid, browser.assign(painting, sid)[0] == 303)
        check('Abstract #' + sid + ' stored under its own ID', len(pair_rows(painting, sid)) == 1)
    check('each Abstract adds its own row', len(links()) == len(before) + 2)

    # 7 (second half): nothing above touched the seeded duplicates.
    check('seeded duplicates unchanged by unrelated actions', duplicates() == fresh_duplicates)
    painting, subject, identifiers = fresh_duplicates[0]
    removed, *twins = identifiers.split(',')
    before = links()
    status, body, _ = browser.assign(painting, subject)
    check('duplicated pair cannot gain another row', status == 422 and 'already has' in body and links() == before)
    check('removing one duplicate row is accepted', browser.req('artwork/object-delete.php?type=painting-subjects&id=' + removed, {'csrf': browser.csrf})[0] == 303)
    check('removing one duplicate row leaves its twin', links() == [row for row in before if row[0] != removed] and [row[0] for row in pair_rows(painting, subject)] == twins)
    check('other duplicate pairs are untouched', duplicates() == [row for row in fresh_duplicates[1:]] or duplicates() == [([painting, subject, ','.join(twins)] if row[:2] == [painting, subject] else row) for row in fresh_duplicates])
    status, body, _ = browser.req('artwork/integrity.php')
    expected = len(duplicates())
    check('integrity count follows the remaining duplicates', status == 200 and expected == SEEDED_DUPLICATE_PAIRS - 1 and integrity_count(body, 'Duplicate subject pairs') == expected)

    # 9. An assignment whose subject is missing is not linked to a page that would 404.
    orphan_subject = int(table('SELECT MAX(SubjectID) FROM Subjects;')[0][0]) + 5000
    painting = links()[0][1]
    # 05 adds a foreign key, so an orphan can only be created with the check off.
    orphan = table('SET FOREIGN_KEY_CHECKS=0; INSERT INTO PaintingSubjects (PaintingID, SubjectID) VALUES (' + painting + ', ' + str(orphan_subject) + '); SELECT LAST_INSERT_ID();')[0][0]
    before = links()
    orphan_link = 'objects.php?type=subjects&amp;id=' + str(orphan_subject)
    status, body, _ = browser.req('artwork/objects.php?type=painting-subjects&id=' + orphan)
    check('orphan assignment detail says Missing subject', status == 200 and 'Missing subject' in body)
    check('orphan assignment detail has no subject link', orphan_link not in body)
    status, body, _ = browser.req('artwork/painting.php?id=' + painting)
    check('orphan on artwork page says Missing subject', status == 200 and 'Missing subject' in body)
    check('orphan on artwork page has no subject link', orphan_link not in body)
    status, body, _ = browser.req('artwork/integrity.php')
    check('integrity reports the orphan', status == 200 and integrity_count(body, 'Subject assignments with missing subjects') == 1)
    check('orphan views write nothing', links() == before)

    # An assignment whose artwork is missing is not linked to a page that would 404.
    orphan_painting = int(table('SELECT MAX(PaintingID) FROM Paintings;')[0][0]) + 5000
    orphan_row = table('INSERT INTO PaintingSubjects (PaintingID, SubjectID) VALUES (' + str(orphan_painting) + ', 1); SELECT LAST_INSERT_ID();')[0][0]
    status, _, _ = browser.req('artwork/painting.php?id=' + str(orphan_painting))
    check('missing artwork page is a 404', status == 404)
    status, body, _ = browser.req('artwork/objects.php?type=painting-subjects&id=' + orphan_row)
    check('assignment detail says Missing artwork', status == 200 and 'Missing artwork #' + str(orphan_painting) in body)
    check('assignment detail has no artwork link', 'painting.php?id=' + str(orphan_painting) not in body)
    sql('DELETE FROM PaintingSubjects WHERE PaintingSubjectID=' + orphan_row + ';')

    # 10. Simulate an older identified volume, custom ID 22, and a deletion.
    up = (ROOT / 'artwork/sql/07-subject-names.sql').read_text(encoding='utf-8')
    down = (ROOT / 'artwork/sql/07-subject-names-down.sql').read_text(encoding='utf-8')
    def migrate(script):
        return subprocess.run(['docker', 'exec', '-i', container, 'mariadb', '-uroot', '-ptask04c-local-root', 'art'],
                              input=script, capture_output=True, text=True)
    def subjects_snapshot():
        return sql('SELECT SubjectID, HEX(SubjectName) FROM Subjects ORDER BY SubjectID;')
    def journal():
        return sql('SELECT SubjectID, HEX(OldName), HEX(NewName) FROM `_SubjectNameCorrection07` ORDER BY SubjectID;')
    check('fresh initialization records completion', '0\t' in journal())
    fresh_links = links()
    sql("UPDATE Subjects SET SubjectName='People' WHERE SubjectID=10;")
    edited = subjects_snapshot()
    check('completed fresh rerun preserves legacy-valued edit and links',
          migrate(up).returncode == 0 and subjects_snapshot() == edited and links() == fresh_links)
    sql("UPDATE Subjects SET SubjectName='Still Life' WHERE SubjectID=10;")
    check('fresh down clears completion', migrate(down).returncode == 0 and journal() == '')
    check('fresh reapply records completion', migrate(up).returncode == 0 and '0\t' in journal())
    check('fresh second down clears completion', migrate(down).returncode == 0 and journal() == '')
    sql('DELETE FROM PaintingSubjects WHERE PaintingSubjectID=' + orphan + ';'
        "DELETE FROM PaintingSubjects WHERE SubjectID IN (20,21);"
        "INSERT INTO Subjects (SubjectID, SubjectName) VALUES (8, 'Religion');"
        "UPDATE Subjects SET SubjectName='Custom 22' WHERE SubjectID=22;"
        "SET FOREIGN_KEY_CHECKS=0; DELETE FROM Subjects WHERE SubjectID=21; SET FOREIGN_KEY_CHECKS=1;"
        "UPDATE Subjects SET SubjectName=CASE SubjectID "
        "WHEN 9 THEN 'Still Life' WHEN 10 THEN 'People' WHEN 11 THEN 'Botanical' WHEN 12 THEN 'Cuisine' "
        "WHEN 13 THEN 'History' WHEN 14 THEN 'Love' WHEN 15 THEN 'Family' WHEN 16 THEN 'Performing Arts' "
        "WHEN 17 THEN 'Allegory' WHEN 18 THEN 'Mythological' WHEN 19 THEN 'Power' WHEN 20 THEN 'Military' "
        "ELSE SubjectName END;")
    old_names, old_links = subjects_snapshot(), links()
    identity = (ROOT / 'artwork/sql/05-subject-identities.sql').read_text(encoding='utf-8')
    check('identity migration accepts existing custom IDs and deletions',
          migrate(identity).returncode == 0 and subjects_snapshot() == old_names and links() == old_links)
    result = migrate(up)
    check('old identified volume upgrades', result.returncode == 0)
    corrected = subjects_snapshot()
    check('upgrade preserves links, legacy ID 8, custom ID 22, and deletion', links() == old_links and
          table('SELECT SubjectID, SubjectName FROM Subjects WHERE SubjectID IN (8,21,22) ORDER BY SubjectID;') == [['8', 'Religion'], ['22', 'Custom 22']])
    check('upgrade corrects surviving canonical IDs', table('SELECT SubjectID, SubjectName FROM Subjects WHERE SubjectID BETWEEN 9 AND 20 ORDER BY SubjectID;') == [
        ['9', 'Religion'], ['10', 'Still Life'], ['11', 'People'], ['12', 'Botanical'], ['13', 'Cuisine'], ['14', 'History'],
        ['15', 'Love'], ['16', 'Family'], ['17', 'Performing Arts'], ['18', 'Allegory'], ['19', 'Mythological'], ['20', 'Power']])
    check('upgrade rerun leaves data unchanged', migrate(up).returncode == 0 and subjects_snapshot() == corrected and links() == old_links)
    sql("UPDATE Subjects SET SubjectName='Modified after upgrade' WHERE SubjectID=10;")
    changed, marked = subjects_snapshot(), journal()
    check('rollback rejects subsequent edits without partial changes', migrate(down).returncode != 0 and subjects_snapshot() == changed and journal() == marked and links() == old_links)
    sql("UPDATE Subjects SET SubjectName='Still Life' WHERE SubjectID=10;")
    check('rollback restores exactly the original names and clears state', migrate(down).returncode == 0 and subjects_snapshot() == old_names and journal() == '' and links() == old_links)
    sql("UPDATE Subjects SET SubjectName='Custom conflicting name' WHERE SubjectID=10;")
    conflict = subjects_snapshot()
    check('upgrade rejects conflicts without partial changes', migrate(up).returncode != 0 and subjects_snapshot() == conflict and journal() == '' and links() == old_links)
    sql("UPDATE Subjects SET SubjectName='People' WHERE SubjectID=10;")
    check('legacy reapply records completion', migrate(up).returncode == 0 and subjects_snapshot() == corrected and '0\t' in journal())
    sql("INSERT INTO Subjects (SubjectID, SubjectName) VALUES (21, 'Abstract');")
    readded, marked = subjects_snapshot(), journal()
    check('completed rerun preserves readded legacy-valued ID', migrate(up).returncode == 0 and subjects_snapshot() == readded and journal() == marked and links() == old_links)
    sql('DELETE FROM Subjects WHERE SubjectID=21;')
    sql('DELETE FROM Subjects WHERE SubjectID=20;')
    deleted, marked = subjects_snapshot(), journal()
    check('rerun preserves a subject deleted after upgrade', migrate(up).returncode == 0 and subjects_snapshot() == deleted and journal() == marked and links() == old_links)
    check('rollback rejects a subsequent deletion without partial changes', migrate(down).returncode != 0 and subjects_snapshot() == deleted and journal() == marked and links() == old_links)



if __name__ == '__main__':
    main()
