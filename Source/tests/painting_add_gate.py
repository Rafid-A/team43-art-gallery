"""Painting Add integration regression in the subject gate's disposable Docker harness."""
import html
from html.parser import HTMLParser
import subprocess
import sys

sys.dont_write_bytecode = True
import subject_assignment_gate as gate


class Options(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.select = None
        self.values = {}

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'select':
            self.select = attrs.get('name')
        elif tag == 'option' and self.select and attrs.get('value'):
            self.values.setdefault(self.select, []).append(attrs['value'])

    def handle_endtag(self, tag):
        if tag == 'select':
            self.select = None


def exercise(container, base):
    def sql(query):
        return subprocess.run(['docker', 'exec', '-i', container, 'mariadb', '-uroot',
                               '-pgallery-local-root', '--default-character-set=utf8mb4', '--batch', '--skip-column-names', 'art'],
                              input=query, capture_output=True, encoding='utf-8', check=True).stdout

    def paintings():
        return sql('SELECT * FROM Paintings ORDER BY PaintingID;')

    def parents():
        return sql('SELECT * FROM Artists ORDER BY ArtistID; SELECT * FROM Galleries ORDER BY GalleryID;')

    browser = gate.Session(base).login()
    status, body, _ = browser.req('artwork/art-add.php')
    choices = Options()
    choices.feed(body)
    gate.check('authenticated Add form supplies parents and unused image', status == 200 and
               all(choices.values.get(name) for name in ('artist_id', 'gallery_id', 'image')))
    values = {'csrf': gate.token(body), 'title': 'Gate <art> & "quoted"',
              'artist_id': choices.values['artist_id'][0], 'gallery_id': choices.values['gallery_id'][0],
              'image': choices.values['image'][0], 'year': '2000',
              'medium': "Oil & 'ink' <canvas>", 'excerpt': 'Test & <description>'}
    before, held_parents = paintings(), parents()
    for field in ('artist_id', 'gallery_id'):
        for value in ('', '999999999'):
            status, response, _ = browser.req('artwork/art-add.php', dict(values, **{field: value}))
            gate.check(field + ' ' + repr(value) + ' rejected without writing', status == 200 and
                       ('Choose an artist.' if field == 'artist_id' else 'Choose a gallery.') in response and paintings() == before)
    for csrf in ('', 'x' * 64):
        status, _, _ = browser.req('artwork/art-add.php', dict(values, csrf=csrf))
        gate.check('invalid CSRF rejected without writing', status == 403 and paintings() == before)
    status, response, headers = browser.req('artwork/art-add.php', values)
    print('Valid Add status:', status, 'Location:', headers.get('Location'), flush=True)
    gate.check('valid Add redirects to collection', status == 303 and headers.get('Location') == 'art-main.php?artist=0&q=&page=1')
    after = paintings()
    rows = [row.split('\t') for row in after.splitlines() if row not in before.splitlines()]
    expected = [values['artist_id'], values['gallery_id'], values['image'].encode().hex().upper(),
                values['title'].encode().hex().upper(), values['year'], values['medium'].encode().hex().upper(),
                values['excerpt'].encode().hex().upper()]
    stored = [] if len(rows) != 1 else sql(
        'SELECT ArtistID, GalleryID, HEX(ImageFileName), HEX(Title), YearOfWork, HEX(Medium), HEX(Excerpt) '
        'FROM Paintings WHERE PaintingID=' + str(int(rows[0][0])) + ';').rstrip('\r\n').split('\t')
    gate.check('exactly one row stores all submitted values', len(after.splitlines()) == len(before.splitlines()) + 1 and
               len(rows) == 1 and stored == expected and all(row in after.splitlines() for row in before.splitlines()))
    status, body, _ = browser.req('artwork/' + headers['Location'])
    gate.check('success flash escapes and preserves title', status == 200 and
               'Added artwork: ' + html.escape(values['title'], quote=True) + '.' in body)
    status, body, _ = browser.req('artwork/art-add.php', values)
    gate.check('duplicate image rejected without writing', status == 200 and 'Choose an available image.' in body and paintings() == after)
    status, _, headers = browser.req('artwork/art-delete.php', {'csrf': values['csrf'], 'painting_id': rows[0][0]})
    gate.check('created painting can be deleted', status == 303 and headers.get('Location') == 'art-main.php?artist=0&q=&page=1')
    gate.check('deletion preserves unrelated paintings and parents', paintings() == before and parents() == held_parents)


gate.exercise = exercise
if __name__ == '__main__':
    gate.main()
