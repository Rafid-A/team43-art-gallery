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

const addForm = document.querySelector('form[data-unsaved-warning]');

if (addForm) {
  const formValues = () => JSON.stringify(Array.from(new FormData(addForm)));
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

const artistForm = document.querySelector('#artist-form');
if (artistForm) {
  const birth = artistForm.querySelector('#YearOfBirth');
  const death = artistForm.querySelector('#YearOfDeath');
  const checkChronology = () => {
    const invalid = birth.value !== '' && death.value !== '' && Number(death.value) < Number(birth.value);
    death.setCustomValidity(invalid ? 'Year of death must not precede year of birth.' : '');
  };
  birth.addEventListener('input', checkChronology);
  death.addEventListener('input', checkChronology);
  checkChronology();
}

const imageSelect = document.querySelector('select[data-image-base]');
const imagePreview = document.querySelector('#image-preview');

if (imageSelect && imagePreview) {
  const image = imagePreview.querySelector('img');
  const caption = imagePreview.querySelector('figcaption');

  image.addEventListener('load', () => {
    image.hidden = false;
    imagePreview.removeAttribute('aria-busy');
    caption.textContent = imageSelect.value + '.jpg';
  });
  image.addEventListener('error', () => {
    image.hidden = true;
    imagePreview.removeAttribute('aria-busy');
    caption.textContent = 'Preview unavailable. Choose another image or try again.';
  });

  const showImage = () => {
    const filename = imageSelect.value;
    imagePreview.hidden = !filename;
    image.hidden = true;
    if (filename) {
      imagePreview.setAttribute('aria-busy', 'true');
      caption.textContent = 'Loading image preview…';
      image.src = imageSelect.dataset.imageBase + encodeURIComponent(filename) + '.jpg';
      image.alt = 'Selected artwork image ' + filename;
    } else {
      image.removeAttribute('src');
      imagePreview.removeAttribute('aria-busy');
      caption.textContent = '';
    }
  };
  imageSelect.addEventListener('change', showImage);
  showImage();
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
    if (event.key === 'Escape') { event.preventDefault(); dismiss(); return; }
    if (event.key === 'Tab') { dismiss(); return; }
    if (event.key === 'ArrowDown' && results.length) { event.preventDefault(); highlight((active + 1) % results.length); }
    else if (event.key === 'ArrowUp' && results.length) { event.preventDefault(); highlight(active <= 0 ? results.length - 1 : active - 1); }
    else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); choose(active); }
  });
  input.addEventListener('blur', dismiss);
});
