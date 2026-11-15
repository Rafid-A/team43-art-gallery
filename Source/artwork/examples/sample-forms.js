const sampleSubmitLabels = new WeakMap();

document.querySelectorAll('form[data-pending]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            return;
        }

        const button = event.submitter || form.querySelector('[type="submit"]');
        if (button) {
            sampleSubmitLabels.set(button, button.value);
            button.disabled = true;
            button.value = form.dataset.pending;
        }
        form.setAttribute('aria-busy', 'true');
    });
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[aria-busy="true"]').forEach((form) => {
        form.removeAttribute('aria-busy');
        const button = form.querySelector('[type="submit"]');
        if (button) {
            button.disabled = false;
            button.value = sampleSubmitLabels.get(button)
                || button.defaultValue;
            sampleSubmitLabels.delete(button);
        }
    });
});
