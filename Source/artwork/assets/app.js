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
