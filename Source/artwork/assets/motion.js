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
    const stage = viewer.querySelector('.viewer-stage');
    const image = stage.querySelector('img');
    const zoomIn = viewer.querySelector('[data-viewer-zoom="in"]');
    const zoomOut = viewer.querySelector('[data-viewer-zoom="out"]');
    const fitButton = viewer.querySelector('[data-viewer-fit]');
    const actualButton = viewer.querySelector('[data-viewer-actual]');
    const status = viewer.querySelector('[data-viewer-status]');
    let scale = 1, fitScale = 1, x = 0, y = 0;
    const pointers = new Map();
    const ready = () => image?.complete && image.naturalWidth > 0 && stage.clientWidth > 0 && stage.clientHeight > 0;
    const render = () => {
      if (!ready()) return;
      const maxX = Math.max(0, (image.naturalWidth * scale - stage.clientWidth) / 2);
      const maxY = Math.max(0, (image.naturalHeight * scale - stage.clientHeight) / 2);
      x = Math.max(-maxX, Math.min(maxX, x));
      y = Math.max(-maxY, Math.min(maxY, y));
      image.style.width = `${image.naturalWidth}px`;
      image.style.height = `${image.naturalHeight}px`;
      image.style.transform = `translate(-50%, -50%) translate(${x}px, ${y}px) scale(${scale})`;
      stage.classList.toggle('is-zoomed', scale > fitScale + 0.001);
      // Expose current state for assistive feedback, without announcing every pan.
      const text = `${Math.round(scale * 100)}%${scale <= fitScale + 0.001 ? ' · Fit' : ''}${scale >= 0.999 ? ' · Actual pixels' : ''}`;
      if (status.textContent !== text) status.textContent = text;
      zoomIn.disabled = actualButton.disabled = scale >= 0.999;
      zoomOut.disabled = scale <= fitScale + 0.001;
      fitButton.disabled = false;
    };
    const fit = () => {
      if (!ready()) return;
      fitScale = Math.min(1, stage.clientWidth / image.naturalWidth, stage.clientHeight / image.naturalHeight);
      scale = fitScale; x = y = 0;
      render();
    };
    const zoom = (nextScale, anchorX = 0, anchorY = 0) => {
      if (!ready()) return;
      const previous = scale;
      scale = Math.max(fitScale, Math.min(1, nextScale));
      x = anchorX - (anchorX - x) * scale / previous;
      y = anchorY - (anchorY - y) * scale / previous;
      render();
    };
    const point = event => {
      const rect = stage.getBoundingClientRect();
      return { x: event.clientX - rect.left - rect.width / 2, y: event.clientY - rect.top - rect.height / 2 };
    };
    const gesture = () => {
      const [a, b] = [...pointers.values()];
      return b ? { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, distance: Math.hypot(a.x - b.x, a.y - b.y) } : a;
    };
    let previousGesture;
    stage.addEventListener('pointerdown', event => {
      if (event.pointerType === 'mouse' && event.button !== 0) return;
      if (!ready() || pointers.size >= 2) return;
      stage.focus({ preventScroll: true });
      pointers.set(event.pointerId, point(event));
      stage.setPointerCapture(event.pointerId);
      stage.classList.add('is-dragging');
      previousGesture = gesture();
    });
    stage.addEventListener('pointermove', event => {
      if (!pointers.has(event.pointerId)) return;
      pointers.set(event.pointerId, point(event));
      const current = gesture();
      if (current.distance && previousGesture.distance) {
        zoom(scale * current.distance / previousGesture.distance, previousGesture.x, previousGesture.y);
      }
      x += current.x - previousGesture.x;
      y += current.y - previousGesture.y;
      previousGesture = current;
      render();
    });
    const release = event => {
      pointers.delete(event.pointerId);
      previousGesture = gesture();
      if (!pointers.size) stage.classList.remove('is-dragging');
    };
    stage.addEventListener('pointerup', release);
    stage.addEventListener('pointercancel', release);
    stage.addEventListener('lostpointercapture', release);
    stage.addEventListener('dragstart', event => event.preventDefault());
    stage.addEventListener('wheel', event => {
      if (!ready()) return;
      event.preventDefault();
      const anchor = point(event);
      const delta = event.deltaY * (event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? stage.clientHeight : 1);
      zoom(scale * Math.exp(-Math.max(-200, Math.min(200, delta)) * 0.005), anchor.x, anchor.y);
    }, { passive: false });
    stage.addEventListener('dblclick', event => {
      const anchor = point(event);
      if (scale > fitScale + 0.001) fit();
      else zoom(1, anchor.x, anchor.y);
    });
    zoomIn.addEventListener('click', () => zoom(scale * 1.25));
    zoomOut.addEventListener('click', () => zoom(scale / 1.25));
    actualButton.addEventListener('click', () => zoom(1));
    fitButton.addEventListener('click', fit);
    stage.addEventListener('keydown', event => {
      if (event.ctrlKey || event.metaKey || event.altKey) return;
      const step = event.shiftKey ? 120 : 40;
      switch (event.key) {
        case '+': case '=': zoom(scale * 1.25); break;
        case '-': case '_': zoom(scale / 1.25); break;
        case '0': case 'Home': fit(); break;
        case '1': zoom(1); break;
        case 'ArrowLeft': x += step; render(); break;
        case 'ArrowRight': x -= step; render(); break;
        case 'ArrowUp': y += step; render(); break;
        case 'ArrowDown': y -= step; render(); break;
        default: return;
      }
      event.preventDefault();
    });
    const imageReady = () => { if (viewer.open) fit(); };
    image?.addEventListener('load', imageReady);
    const imageFailed = () => {
      status.textContent = 'Image unavailable. Close the viewer and try again.';
      [zoomIn, zoomOut, fitButton, actualButton].forEach(button => { button.disabled = true; });
    };
    image?.addEventListener('error', imageFailed);
    [zoomIn, zoomOut, fitButton, actualButton].forEach(button => { button.disabled = true; });
    if (image?.complete && image.naturalWidth === 0) imageFailed();
    if (typeof ResizeObserver === 'function') new ResizeObserver(() => {
      if (!viewer.open || !ready()) return;
      const wasFit = scale <= fitScale + 0.001;
      fitScale = Math.min(1, stage.clientWidth / image.naturalWidth, stage.clientHeight / image.naturalHeight);
      if (wasFit) fit();
      else { scale = Math.max(fitScale, scale); render(); }
    }).observe(stage);
    trigger.addEventListener('click', event => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      if (!viewer.open) viewer.showModal();
      root.classList.add('viewer-open');
      fit();
    });
    viewer.querySelector('[data-viewer-close]').addEventListener('click', () => viewer.close());
    viewer.addEventListener('close', () => {
      if (viewer.open) return; // A queued close event must not undo a rapid reopen.
      root.classList.remove('viewer-open');
      pointers.clear();
      stage.classList.remove('is-dragging');
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
