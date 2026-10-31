(() => {
  const root = document.documentElement;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const activeEffects = new Set();
  const readInputMode = () => {
    try { return sessionStorage.getItem('library-input-mode') || 'keyboard'; }
    catch { return 'keyboard'; }
  };
  let inputMode;
  const setInputMode = (mode) => {
    if (inputMode === mode) return;
    inputMode = mode;
    root.classList.toggle('pointer-mode', mode === 'pointer');
    root.classList.toggle('keyboard-mode', mode === 'keyboard');
    try { sessionStorage.setItem('library-input-mode', mode); } catch { /* Motion is optional. */ }
    if (mode === 'keyboard') activeEffects.forEach(effect => effect.cancel());
  };
  setInputMode(readInputMode());
  document.addEventListener('keydown', () => setInputMode('keyboard'), true);
  document.addEventListener('pointerdown', () => setInputMode('pointer'), true);
  document.addEventListener('pointermove', () => setInputMode('pointer'), { passive: true });
  document.addEventListener('click', event => {
    if (event.detail === 0) setInputMode('keyboard');
  }, true);
  reducedMotion.addEventListener('change', () => activeEffects.forEach(effect => effect.cancel()));

  const isBrowsing = (url) => url.origin === location.origin &&
    /\/(art-main|painting|objects|browse)\.php$/.test(url.pathname) &&
    url.searchParams.get('manage') !== '1';
  const skipTransition = (transition) => {
    transition.ready.catch(() => {});
    transition.skipTransition();
  };
  window.addEventListener('pageswap', event => {
    if (!event.viewTransition) return;
    const destination = event.activation?.entry?.url;
    if (reducedMotion.matches || inputMode === 'keyboard' || !destination ||
        !isBrowsing(new URL(location.href)) || !isBrowsing(new URL(destination))) {
      skipTransition(event.viewTransition);
    }
  });
  window.addEventListener('pagereveal', event => {
    if (event.viewTransition && (reducedMotion.matches || readInputMode() === 'keyboard' || !isBrowsing(new URL(location.href)))) {
      skipTransition(event.viewTransition);
    }
  });
  const navigationStyle = document.querySelector('#browse-motion');
  if (navigationStyle && 'onpageswap' in window && 'onpagereveal' in window) navigationStyle.disabled = false;

  const enter = (element, keyframes, duration) => {
    if (inputMode === 'keyboard' || typeof element.animate !== 'function') return;
    const easing = getComputedStyle(root).getPropertyValue('--ease-out').trim();
    if (!easing) return;
    const effect = element.animate(keyframes, {
      duration,
      easing
    });
    activeEffects.add(effect);
    effect.finished.then(() => activeEffects.delete(effect), () => activeEffects.delete(effect));
  };

  const initialize = () => {
    // Visible by default. Cached, failed, and reduced-motion images never wait on JS.
    document.querySelectorAll('.art-image img, .painting-image img, .login-intro img, #image-preview img').forEach(image => {
      image.addEventListener('load', () => {
        if (image.naturalWidth > 0 && !reducedMotion.matches) enter(image, [{ opacity: 0 }, { opacity: 1 }], 180);
      });
    });
    document.querySelectorAll('.notice.success').forEach(notice => {
      const frames = reducedMotion.matches
        ? [{ opacity: 0 }, { opacity: 1 }]
        : [{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'translateY(0)' }];
      enter(notice, frames, reducedMotion.matches ? 100 : 180);
    });

    const trigger = document.querySelector('[data-artwork-viewer]');
    const viewer = document.querySelector('#artwork-viewer');
    if (!trigger || !viewer || typeof viewer.showModal !== 'function') return;
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-controls', viewer.id);
    trigger.addEventListener('click', event => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      if (!viewer.open) viewer.showModal();
      root.classList.add('viewer-open');
    });
    viewer.querySelector('[data-viewer-close]').addEventListener('click', () => viewer.close());
    viewer.addEventListener('close', () => {
      if (viewer.open) return; // A queued close event must not undo a rapid reopen.
      root.classList.remove('viewer-open');
      trigger.focus({ preventScroll: true });
    });
    const outside = (event) => {
      const bounds = viewer.getBoundingClientRect();
      return event.clientX < bounds.left || event.clientX > bounds.right ||
        event.clientY < bounds.top || event.clientY > bounds.bottom;
    };
    let startedOutside = false;
    viewer.addEventListener('pointerdown', event => { startedOutside = event.target === viewer && outside(event); });
    viewer.addEventListener('click', event => {
      if (startedOutside && event.target === viewer && outside(event)) viewer.close();
      startedOutside = false;
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
})();
