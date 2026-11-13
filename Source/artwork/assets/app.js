// Native disclosures remain usable without JavaScript. Desktop shows the full
// navigation/toolbar; mobile starts compact without duplicating any controls.
document.querySelectorAll('[data-mobile-disclosure]').forEach((details) => {
  const summary = details.querySelector(':scope > summary');
  const mobile = matchMedia(details.classList.contains('site-menu') ? '(max-width: 1024px)' : '(max-width: 768px)');
  const reflect = () => summary.setAttribute('aria-expanded', String(details.open));
  const resize = () => {
    if (mobile.matches && details.contains(document.activeElement)) summary.focus();
    details.open = !mobile.matches;
    reflect();
  };
  details.addEventListener('toggle', reflect);
  details.addEventListener('keydown', (event) => {
    // A suggestion popover consumes its own first Escape.
    if (event.key === 'Escape' && mobile.matches && details.open) {
      event.preventDefault();
      details.open = false;
      reflect();
      summary.focus();
    }
  });
  mobile.addEventListener('change', resize);
  resize();
  details.dataset.enhanced = 'true';
});

document.querySelectorAll('[data-combined-sort]').forEach((select) => {
  const form = select.closest('form');
  const sort = form.querySelector('[name="sort"]');
  const direction = form.querySelector('[name="dir"]');
  if (!sort || !direction) return;
  const reflect = () => { select.value = `${sort.value}:${direction.value}`; };
  reflect();
  select.addEventListener('change', () => {
    const [field, dir] = select.value.split(':');
    sort.value = field;
    direction.value = dir;
  });
  sort.addEventListener('change', reflect);
  direction.addEventListener('change', reflect);
  form.querySelectorAll('[data-native-sort]').forEach(field => { field.hidden = true; });
  select.closest('.filter-field').hidden = false;
});

const collectionView = document.querySelector('[data-collection-view]');
if (collectionView) {
  const controls = document.querySelector('[data-density-controls]');
  const setDensity = (value) => {
    const density = value === 'compact' ? 'compact' : 'comfortable';
    collectionView.dataset.density = density;
    controls?.querySelectorAll('[data-density]').forEach(button => {
      button.setAttribute('aria-pressed', String(button.dataset.density === density));
    });
  };
  let storedDensity;
  try { storedDensity = sessionStorage.getItem('library-gallery-density'); } catch { /* Optional preference. */ }
  setDensity(storedDensity);
  if (controls) {
    controls.hidden = false;
    controls.addEventListener('click', (event) => {
      const button = event.target.closest('[data-density]');
      if (!button) return;
      setDensity(button.dataset.density);
      try { sessionStorage.setItem('library-gallery-density', collectionView.dataset.density); } catch { /* Works for this page. */ }
    });
  }

  // Return URLs are built by the server's existing artwork context helpers.
  // Keep the literal collection URL as well, so Back restores its exact view.
  const storageKey = 'library-collection-positions';
  const viewKey = location.pathname + location.search;
  let positions = {};
  try { positions = JSON.parse(sessionStorage.getItem(storageKey) || '{}') || {}; } catch { /* Storage may be denied. */ }
  if (typeof positions !== 'object' || Array.isArray(positions)) positions = {};
  const save = (keys, returning = false) => {
    const entry = { y: window.scrollY, returning };
    keys.forEach(key => { delete positions[key]; positions[key] = entry; });
    positions = Object.fromEntries(Object.entries(positions).slice(-20));
    try { sessionStorage.setItem(storageKey, JSON.stringify(positions)); } catch { /* Browser Back still works. */ }
  };
  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || (link.target && link.target !== '_self')) return;
    const target = new URL(link.href);
    if (target.origin !== location.origin || !/\/(painting|objects)\.php$/.test(target.pathname)) return;
    const canonical = new URL(collectionView.dataset.collectionView, location.href);
    save([viewKey, canonical.pathname + canonical.search], true);
  });
  window.addEventListener('pagehide', () => {
    // Don't overwrite a pending breadcrumb return recorded by the link handler.
    if (!positions[viewKey]?.returning) save([viewKey]);
  });
  window.addEventListener('pageshow', (event) => {
    const entry = positions[viewKey];
    const back = performance.getEntriesByType('navigation')[0]?.type === 'back_forward';
    if (!entry || !Number.isFinite(entry.y) || entry.y < 0 || location.hash || (!entry.returning && !back)) return;
    if (!event.persisted) requestAnimationFrame(() => window.scrollTo({ top: entry.y, behavior: 'instant' }));
    entry.returning = false;
    try { sessionStorage.setItem(storageKey, JSON.stringify(positions)); } catch { /* Optional restoration. */ }
  });
}

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
  const input = document.getElementById(button.dataset.passwordToggle);
  if (!input || !['password', 'text'].includes(input.type)) return;
  button.type = 'button';
  button.hidden = false;
  button.setAttribute('aria-controls', input.id);
  const reflect = () => {
    const visible = input.type === 'text';
    button.setAttribute('aria-pressed', String(visible));
    button.textContent = visible ? 'Hide password' : 'Show password';
  };
  reflect();
  button.addEventListener('click', () => {
    input.type = input.type === 'password' ? 'text' : 'password';
    reflect();
  });
});

document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (pendingForms.has(form) || !window.confirm(form.dataset.confirm)) {
      event.preventDefault();
    }
  });
});

const pendingForms = new Map();

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (event.defaultPrevented) return;
  if (pendingForms.has(form)) {
    event.preventDefault();
    return;
  }

  const button = event.submitter;
  pendingForms.set(form, { button, label: button?.textContent });
  form.setAttribute('aria-busy', 'true');
  if (button) {
    button.setAttribute('aria-disabled', 'true');
    if (button.tagName === 'BUTTON') {
      const action = form.getAttribute('action') || '';
      const label = action.includes('delete') ? 'Deleting…' :
        action.includes('logout') ? 'Logging out…' : 'Loading…';
      button.textContent = form.dataset.submittingLabel || label;
    }
  }
});

const resetPendingForms = () => {
  pendingForms.forEach(({ button, label }, form) => {
    form.removeAttribute('aria-busy');
    if (button) {
      button.removeAttribute('aria-disabled');
      if (button.tagName === 'BUTTON') button.textContent = label;
    }
  });
  pendingForms.clear();
};
window.addEventListener('pageshow', resetPendingForms);

const imageSelect = document.querySelector('select[data-image-base]');
const imagePreview = document.querySelector('#image-preview');

if (imageSelect && imagePreview) {
  const image = imagePreview.querySelector('img');
  const caption = imagePreview.querySelector('figcaption');
  const fileInput = document.querySelector('#upload');
  const sources = document.querySelectorAll('input[name="image_source"]');
  let objectUrl = null;
  let previewCaption = '';
  const releasePreview = () => {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = null;
  };

  image.addEventListener('load', () => {
    image.hidden = false;
    imagePreview.removeAttribute('aria-busy');
    caption.textContent = previewCaption;
  });
  image.addEventListener('error', () => {
    image.hidden = true;
    imagePreview.removeAttribute('aria-busy');
    releasePreview();
    image.removeAttribute('src');
    caption.textContent = 'Preview unavailable. Choose another image or try again.';
  });

  const showImage = () => {
    releasePreview();
    image.removeAttribute('src');
    const uploading = fileInput && !fileInput.disabled;
    const file = uploading ? fileInput.files[0] : null;
    const filename = imageSelect.value;
    const selected = uploading ? !!file : !!filename;
    imagePreview.hidden = !selected;
    image.hidden = true;
    if (selected) {
      imagePreview.setAttribute('aria-busy', 'true');
      caption.textContent = 'Loading image preview…';
      previewCaption = uploading
        ? `${file.name} — selected file preview. The server validates it and saves a new JPEG.`
        : filename + '.jpg';
      image.alt = uploading ? 'Selected upload preview' : 'Selected artwork image ' + filename;
      if (uploading) {
        objectUrl = URL.createObjectURL(file);
        image.src = objectUrl;
      } else {
        image.src = imageSelect.dataset.imageBase + encodeURIComponent(filename) + '.jpg';
      }
    } else {
      image.removeAttribute('src');
      imagePreview.removeAttribute('aria-busy');
      caption.textContent = '';
    }
  };
  imageSelect.addEventListener('change', showImage);
  fileInput?.addEventListener('change', showImage);
  window.addEventListener('pagehide', releasePreview);
  window.addEventListener('pageshow', (event) => { if (event.persisted) showImage(); });

  // Upload or existing image: only the chosen control is submitted.
  if (sources.length > 1 && fileInput) {
    const syncSource = () => {
      const existing = document.querySelector('input[name="image_source"][value="existing"]').checked;
      imageSelect.disabled = !existing;
      fileInput.disabled = existing;
      showImage();
    };
    sources.forEach((source) => source.addEventListener('change', syncSource));
    syncSource();
  } else showImage();
}

const addForm = document.querySelector('form[data-unsaved-warning]');

if (addForm) {
  // Capture after image-source controls are configured. Empty file inputs
  // produce fresh File objects; normalize them, and compare selected metadata.
  const formValues = () => JSON.stringify(Array.from(new FormData(addForm), ([key, value]) => [key,
    value instanceof File
      ? (value.name === '' && value.size === 0 ? null : [value.name, value.size, value.type, value.lastModified])
      : value
  ]));
  const initialValues = formValues();
  window.addEventListener('beforeunload', (event) => {
    const dirty = addForm.dataset.hasUnsavedValues === 'true' ||
      formValues() !== initialValues;
    if (dirty && !pendingForms.has(addForm)) {
      resetPendingForms();
      event.preventDefault();
      event.returnValue = '';
    }
  });
  addForm.querySelector('[aria-invalid="true"]')?.focus();
}

// Library tables store 3-byte UTF-8 only; mirror the server's emoji check so
// the message appears before submitting. The server check remains the guard.
document.querySelectorAll('form.add-form').forEach((form) => {
  const unstorable = /[\u{10000}-\u{10FFFF}]/u;
  form.querySelectorAll('input:not([type]), input[type="text"], input[type="url"], textarea').forEach((field) => {
    const check = () => field.setCustomValidity(unstorable.test(field.value)
      ? 'Remove emoji or other special symbols; the gallery cannot store them.' : '');
    field.addEventListener('input', check);
    check();
  });
});

const artistForm = document.querySelector('#artist-form');
if (artistForm) {
  const birth = artistForm.querySelector('#YearOfBirth');
  const death = artistForm.querySelector('#YearOfDeath');
  const checkChronology = () => {
    const invalid = birth.value !== '' && death.value !== '' && Number(birth.value) !== 0 && Number(death.value) !== 0 && Number(death.value) < Number(birth.value);
    death.setCustomValidity(invalid ? 'Year of death must not precede year of birth.' : '');
  };
  birth.addEventListener('input', checkChronology);
  death.addEventListener('input', checkChronology);
  checkChronology();
}
document.querySelectorAll('[data-suggestions]').forEach((input) => {
  const wrap = input.closest('.suggestion-wrap');
  const list = wrap.querySelector('[role="listbox"]');
  const status = wrap.querySelector('[role="status"]');
  const type = input.dataset.suggestions;
  input.closest('.artist-lookup')?.removeAttribute('hidden');
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-controls', list.id);
  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('autocomplete', 'off');
  let sequence = 0, controller = null, timer = null, results = [], active = -1;
  function invalidate() {
    sequence++;
    clearTimeout(timer);
    controller?.abort();
    controller = null;
  }
  function dismiss() {
    invalidate();
    results = []; active = -1;
    list.replaceChildren(); list.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    status.textContent = '';
  }
  function highlight(index) {
    active = index;
    Array.from(list.children).forEach((item, i) => item.setAttribute('aria-selected', String(i === index)));
    if (index < 0) input.removeAttribute('aria-activedescendant');
    else {
      input.setAttribute('aria-activedescendant', list.children[index].id);
      list.children[index].scrollIntoView({ block: 'nearest' });
    }
  }
  function choose(index) {
    const item = results[index];
    if (!item) return;
    // The server contract permits only these local detail destinations.
    const expected = type === 'title' ? `painting.php?id=${item.id}` : `objects.php?type=artists&id=${item.id}`;
    if (item.url !== expected) { dismiss(); status.textContent = 'Suggestions are unavailable. Please try again.'; return; }
    dismiss();
    const context = new URLSearchParams(input.closest('[data-browse-context]')?.dataset.browseContext || '');
    if (type === 'title') {
      window.location.assign(expected + '&' + context.toString());
    } else {
      const origin = new URLSearchParams({origin: 'collection'});
      context.forEach((value, key) => origin.set('origin_' + key, value));
      window.location.assign(expected + '&' + origin.toString());
    }
  }
  input.addEventListener('input', () => {
    dismiss();
    const prefix = input.value.trim();
    if (!prefix) return;
    const current = sequence;
    status.textContent = 'Loading suggestions…';
    timer = setTimeout(async () => {
      controller = new AbortController();
      try {
        const response = await fetch('suggestions.php?' + new URLSearchParams({type, prefix}), {signal: controller.signal, headers: {Accept: 'application/json'}});
        if (!response.ok) throw new Error(response.status === 401 ? 'login' : 'request');
        const data = await response.json();
        if (current !== sequence) return;
        if (!Array.isArray(data.results) || data.results.length > 10 || data.results.some(item => !item || !Number.isSafeInteger(item.id) || item.id <= 0 || typeof item.label !== 'string' || typeof item.url !== 'string')) throw new Error('invalid');
        results = data.results;
        if (!results.length) { status.textContent = 'No matches found.'; return; }
        results.forEach((item, i) => {
          const option = document.createElement('li');
          option.id = `${list.id}-${i}`;
          option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false');
          option.textContent = item.label;
          option.addEventListener('pointerdown', event => event.preventDefault());
          option.addEventListener('click', () => choose(i));
          list.append(option);
        });
        list.hidden = false; input.setAttribute('aria-expanded', 'true');
        status.textContent = `${results.length} suggestions available. Use arrow keys to browse.`;
      } catch (error) {
        if (current !== sequence || error.name === 'AbortError') return;
        status.textContent = error.message === 'login' ? 'Please log in again to use suggestions.' : 'Suggestions are unavailable. Please try again.';
      } finally {
        if (current === sequence) controller = null;
      }
    }, 200);
  });
  input.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      const showingSuggestions = !list.hidden || status.textContent !== '';
      dismiss();
      event.preventDefault();
      if (showingSuggestions) event.stopPropagation();
      return;
    }
    if (event.key === 'Tab') { dismiss(); return; }
    if (event.key === 'ArrowDown' && results.length) { event.preventDefault(); highlight((active + 1) % results.length); }
    else if (event.key === 'ArrowUp' && results.length) { event.preventDefault(); highlight(active <= 0 ? results.length - 1 : active - 1); }
    else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); choose(active); }
  });
  input.addEventListener('blur', dismiss);
});
