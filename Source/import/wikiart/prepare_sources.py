"""Preparation step for issue #16: save the WikiArt source pages used by the import.

This is the only part of the WikiArt work that uses the network. It is run once
by a person preparing the fixtures, not by the extractor, the gallery or the
tests, which read only the files it saves:

    python import/wikiart/prepare_sources.py --reference "<folder>"

<folder> is the "artwiki sample web pages" folder from Prof. Min's
"artwiki web pages.zip" (Box, Project-Task2). Edouard Manet's artist page and
painting thumbnails are copied from it unchanged. For Berthe Morisot and
Alfred Sisley the artist page is downloaded, and for all three artists the five
selected painting pages and each painting's "Large" image are downloaded.

Downloaded pages are saved with their <script> and <style> elements removed,
so a saved copy holds the page's data but nothing that can run. manifest.json
records, for every saved file, the source URL, the retrieval time (UTC), the
SHA-256 of the bytes received and of the bytes saved, and the file size.
Running it again replaces the downloaded files and the manifest.
"""
import argparse
import datetime
import hashlib
import html
import json
from pathlib import Path
import re
import shutil
import sys
import time
import urllib.request

HERE = Path(__file__).resolve().parent
SITE = 'https://www.wikiart.org'
USER_AGENT = 'Mozilla/5.0 (compatible; team43-art-gallery coursework fixture preparation)'
REFERENCE_PAGE = 'Edouard Manet - 235 artworks - painting.html'
REFERENCE_ORIGIN = "Prof. Min, 'artwiki web pages.zip' (Box: cs4485 shared resources / Project-Task2), saved 2026-10-29"

# Five distinct paintings per artist, each listed on that artist's saved page.
# Manet's five are not yet in the gallery (it already holds seven other Manets).
SELECTION = [
    {'slug': 'edouard-manet', 'reference': True, 'paintings': [
        'the-fifer-1866', 'lola-de-valence-1862', 'the-monet-family-in-their-garden-at-argenteuil-1874',
        'the-house-at-rueil-1882', 'effect-of-snow-at-petit-montrouge-1870']},
    {'slug': 'berthe-morisot', 'paintings': [
        'the-cradle-1872', 'the-old-track-to-auvers-1863', 'after-luncheon',
        'child-in-the-rose-garden', 'julie-daydreaming']},
    {'slug': 'alfred-sisley', 'paintings': [
        'flood-at-port-marly-1876', 'molesey-weir-at-hampton-court-morning-1874',
        'under-the-bridge-at-hampton-court-1874', 'the-dam-loing-canal-at-saint-mammes-1884',
        'moret-sur-loing-1892-2']},
]

def sha256(data):
    return hashlib.sha256(data).hexdigest()

def now():
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat().replace('+00:00', 'Z')

def fetch(url):
    time.sleep(1)  # be polite to the site: one request per second
    request = urllib.request.Request(url, headers={'User-Agent': USER_AGENT})
    with urllib.request.urlopen(request, timeout=60) as response:
        if response.status != 200:
            raise RuntimeError(url + ' returned HTTP ' + str(response.status))
        return response.read()

def without_scripts(page):
    text = page.decode('utf-8')
    text = re.sub(r'(?is)<script\b[^>]*>.*?</script\s*>', '', text)
    text = re.sub(r'(?is)<style\b[^>]*>.*?</style\s*>', '', text)
    return text.encode('utf-8')

def large_image_url(page):
    match = re.search(r'<img itemprop="image" src="([^"]+)"', page.decode('utf-8'))
    if not match or not match.group(1).startswith('https://'):
        raise RuntimeError('no painting image on the page')
    return html.unescape(match.group(1))

def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('--reference', required=True, type=Path, help="Prof. Min's 'artwiki sample web pages' folder")
    parser.add_argument('--out', type=Path, default=HERE / 'sources', help='where to save (default: sources/ beside this script)')
    args = parser.parse_args()
    reference = args.reference.resolve()
    if not (reference / REFERENCE_PAGE).is_file():
        sys.exit('The reference folder must contain "' + REFERENCE_PAGE + '".')
    out = args.out.resolve()
    files = {}

    def save(relative, data, url, origin, received=None):
        target = out / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        files[relative] = {'url': url, 'origin': origin, 'retrieved_at': now() if received is None else received,
                           'response_sha256': sha256(received_bytes[relative]) if relative in received_bytes else sha256(data),
                           'sha256': sha256(data), 'bytes': len(data)}

    received_bytes = {}
    artists = []
    for artist in SELECTION:
        slug = artist['slug']
        artist_url = SITE + '/en/' + slug
        entry = {'key': 'wikiart:/en/' + slug, 'url': artist_url, 'paintings': []}
        if artist.get('reference'):
            page_path = slug + '/' + REFERENCE_PAGE
            page = (reference / REFERENCE_PAGE).read_bytes()
            save(page_path, page, artist_url, REFERENCE_ORIGIN + '; copied unchanged (time is the ZIP entry time)', '2026-10-29T23:52:00Z')
        else:
            page_path = slug + '/artist.html'
            raw = fetch(artist_url)
            received_bytes[page_path] = raw
            save(page_path, without_scripts(raw), artist_url, 'prepare_sources.py download; script and style elements removed')
        entry['page'] = page_path
        listing = page.decode('utf-8') if artist.get('reference') else raw.decode('utf-8')
        for painting in artist['paintings']:
            painting_url = artist_url + '/' + painting
            if ('/en/' + slug + '/' + painting) not in listing:
                sys.exit(painting_url + ' is not listed on the saved artist page.')
            item = {'key': 'wikiart:/en/' + slug + '/' + painting, 'url': painting_url,
                    'page': slug + '/paintings/' + painting + '.html', 'image': slug + '/images/' + painting + '.jpg'}
            raw_page = fetch(painting_url)
            received_bytes[item['page']] = raw_page
            save(item['page'], without_scripts(raw_page), painting_url, 'prepare_sources.py download; script and style elements removed')
            image_url = large_image_url(raw_page)
            image = fetch(image_url)
            if not image.startswith(b'\xff\xd8\xff'):
                sys.exit(image_url + ' is not a JPEG image.')
            save(item['image'], image, image_url, 'prepare_sources.py download; saved unchanged')
            item['image_url'] = image_url
            if artist.get('reference'):
                # The thumbnail shown for this painting on Prof. Min's saved page.
                match = re.search(r'<img[^>]+src="\./([^"]+)"[^>]*>(?:(?!</li>).)*?href="' + re.escape(painting_url) + '"', listing, re.S)
                if not match:
                    sys.exit('No saved thumbnail for ' + painting_url + ' on the reference page.')
                thumb = html.unescape(match.group(1))
                thumb_path = slug + '/' + thumb
                save(thumb_path, (reference / thumb).read_bytes(), artist_url,
                     REFERENCE_ORIGIN + '; thumbnail saved by the browser with that page, copied unchanged', '2026-10-29T23:52:00Z')
                item['reference_image'] = thumb_path
            entry['paintings'].append(item)
            print('saved', painting_url, flush=True)
        artists.append(entry)
    manifest = {
        'format': 1,
        'description': 'Saved WikiArt sources for the Project Task 2 offline import (issue #16). Paths are relative to this folder.',
        'expected': {'artists': 3, 'paintings_per_artist': 5},
        'prepared_by': 'import/wikiart/prepare_sources.py',
        'artists': artists,
        'files': dict(sorted(files.items())),
    }
    (out / 'manifest.json').write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    print('wrote', out / 'manifest.json', len(files), 'files', flush=True)

if __name__ == '__main__':
    main()
