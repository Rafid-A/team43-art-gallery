(() => {
  const feature = document.querySelector('[data-landing-feature]');
  const reduce = matchMedia('(prefers-reduced-motion: reduce)');
  const tokens = getComputedStyle(document.body);
  const easing = tokens.getPropertyValue('--ease-out').trim();
  const duration = name => Number.parseFloat(tokens.getPropertyValue(name)) || 0;
  const effects = new Set();
  const track = effect => {
    effects.add(effect);
    effect.finished.then(() => effects.delete(effect), () => effects.delete(effect));
  };
  let ghost = null;
  let fade = null;
  let pending = null;
  const stopFade = () => {
    fade?.cancel();
    fade = null;
    ghost?.remove();
    ghost = null;
  };
  const stopMotion = () => {
    stopFade();
    effects.forEach(effect => effect.cancel());
    effects.clear();
  };
  const stopSelection = () => {
    pending?.cancel();
    pending = null;
    stopMotion();
  };
  const interruptMotion = () => {
    // Changing input or motion preference suppresses effects without silently
    // discarding the artwork the visitor is still waiting to see.
    if (pending) pending.animate = false;
    stopMotion();
  };
  document.addEventListener('keydown', interruptMotion, true);
  reduce.addEventListener('change', interruptMotion);
  window.addEventListener('pagehide', stopSelection);

  // A first-session arrival, never a loading gate. If storage is unavailable,
  // leave the complete server-rendered page visible without a repeated effect.
  let firstEntry = false;
  try {
    firstEntry = !sessionStorage.getItem('library-landing-seen');
    sessionStorage.setItem('library-landing-seen', 'true');
  } catch { firstEntry = false; /* The public page works without browser storage. */ }
  // A direct first visit may have a quiet arrival. An internal keyboard link
  // must remain instant, even when this is the session's first landing visit.
  const keyboardNavigation = document.referrer
    && new URL(document.referrer).origin === location.origin
    && document.documentElement.classList.contains('keyboard-mode');
  if (firstEntry && !keyboardNavigation && !reduce.matches && typeof Element.prototype.animate === 'function') {
    document.querySelectorAll('[data-landing-arrival]').forEach((element, index) => {
      const effect = element.animate([
        { opacity: .55, transform: 'translateY(12px)' },
        { opacity: 1, transform: 'translateY(0)' },
      ], { duration: duration('--duration-arrival') - index * 40, delay: index * 40, easing });
      track(effect);
    });
  }
  // Rotating strip (Task 8): CSS moves the track; this only prepares it. One
  // hidden copy of the works makes the loop seamless, and the visible Pause
  // control keeps continuous motion stoppable (WCAG 2.2.2). Reduced motion and
  // no-JavaScript visitors get the still, scrollable row instead.
  // Everything waits for the page's load event, so the strip's images never
  // compete with the hero painting for bandwidth (motion.js holds them back;
  // a cloned <img> would start loading at once, so cloning waits too).
  const strip = document.querySelector('[data-landing-strip]');
  const startStrip = () => {
    document.documentElement.classList.remove('strip-pending');
    if (!strip) return;
    const track = strip.querySelector('.landing-strip-track');
    const toggle = strip.querySelector('[data-strip-toggle]');
    [...track.children].forEach(item => {
      const copy = item.cloneNode(true);
      copy.setAttribute('data-strip-clone', '');
      copy.setAttribute('aria-hidden', 'true');
      copy.querySelectorAll('a').forEach(link => { link.tabIndex = -1; });
      copy.querySelectorAll('img').forEach(image => { image.alt = ''; });
      track.append(copy);
    });
    // Moving images are never offscreen for long, so load them all now.
    track.querySelectorAll('img').forEach(image => { image.loading = 'eager'; });
    // Constant speed (about 40px a second) whatever the number or size of works;
    // widths come from each image's width/height attributes, so no reflow wait.
    const half = track.scrollWidth / 2;
    if (half) track.style.setProperty('--strip-duration', Math.max(30, Math.round(half / 40)) + 's');
    strip.setAttribute('data-rotating', '');
    // Focus pauses the strip (CSS). Move the paused track so the focused work is
    // in view: the clipped window cannot scroll back past its start by itself.
    track.addEventListener('focusin', event => {
      const item = event.target.closest('li');
      const motion = item && track.getAnimations()[0];
      if (!motion || !half) return;
      const offset = item.getBoundingClientRect().left - track.getBoundingClientRect().left;
      motion.currentTime = (offset / half) * motion.effect.getComputedTiming().duration;
      track.parentElement.scrollLeft = 0;
    });
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
      const paused = strip.toggleAttribute('data-paused');
      toggle.textContent = paused ? 'Resume rotation' : 'Pause rotation';
    });
  };
  if (document.readyState === 'complete') startStrip();
  else window.addEventListener('load', startStrip, { once: true });

  if (!feature) return;

  const choices = [...feature.querySelectorAll('[data-feature-choice]')];
  const images = [...feature.querySelectorAll('[data-feature-image]')];
  const captions = [...feature.querySelectorAll('[data-feature-caption]')];
  const wall = feature.querySelector('.landing-art-wall');
  const status = document.createElement('div');
  status.className = 'landing-load-status';
  status.setAttribute('role', 'status');
  feature.append(status);
  images.forEach(link => {
    const image = link.querySelector('img');
    const failed = () => {
      image.hidden = true;
      link.querySelector('.landing-image-failure').hidden = false;
    };
    image.addEventListener('error', failed);
    // A hidden lazy image can be complete before the browser has requested it.
    // Only a selected resource with zero pixels represents an initial failure.
    if (image.complete && image.currentSrc && image.naturalWidth === 0) failed();
  });

  const commit = (choice, animateChoice) => {
    const key = choice.dataset.featureChoice;
    const next = images.find(image => image.dataset.featureImage === key);
    const nextCaption = captions.find(caption => caption.dataset.featureCaption === key);
    const previous = images.find(image => !image.hidden);
    stopMotion();

    // Commit a ready image (or explicit failure fallback) and all its associated
    // content together. The old image is only decorative after this point.
    const oldImage = previous?.querySelector('img');
    if (animateChoice && oldImage?.naturalWidth > 0
        && !oldImage.hidden && typeof oldImage.animate === 'function') {
      ghost = document.createElement('span');
      ghost.className = 'landing-fade-ghost';
      ghost.setAttribute('aria-hidden', 'true');
      const copy = oldImage.cloneNode();
      copy.alt = '';
      copy.removeAttribute('fetchpriority');
      ghost.append(copy);
      wall.append(ghost);
    }
    images.forEach(image => { image.hidden = image !== next; });
    captions.forEach(caption => { caption.hidden = caption.dataset.featureCaption !== key; });
    choices.forEach(link => {
      if (link === choice) link.setAttribute('aria-current', 'true');
      else link.removeAttribute('aria-current');
    });
    if (animateChoice && typeof nextCaption?.animate === 'function') {
      // Fade only the new current caption. Its content and links are already
      // current, with no decorative duplicate links entering the focus order.
      track(nextCaption.animate([{ opacity: .65 }, { opacity: 1 }], {
        duration: duration('--duration-artwork'), easing,
      }));
    }
    // Keep a shareable no-JavaScript selection without adding history stops
    // for each comparison. Native back/forward navigation still works.
    const url = new URL(choice.href);
    try { history.replaceState(history.state, '', url.pathname + url.search + location.hash); } catch { /* Selection still works. */ }
    if (ghost) {
      const currentGhost = ghost;
      const currentFade = ghost.animate([{ opacity: 1 }, { opacity: 0 }], {
        duration: duration('--duration-artwork'), easing,
      });
      fade = currentFade;
      currentFade.finished.then(() => {
        currentGhost.remove();
        if (fade === currentFade) { fade = null; ghost = null; }
      }, () => currentGhost.remove());
    }
  };

  choices.forEach(choice => choice.addEventListener('click', event => {
    // Modified clicks keep the native URL, including the selected artwork.
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
    const next = images.find(image => image.dataset.featureImage === choice.dataset.featureChoice);
    if (!next) return;
    event.preventDefault();
    stopSelection();
    status.textContent = '';
    if (!next.hidden) return;
    const image = next.querySelector('img');
    const animateChoice = event.detail > 0 && !reduce.matches;
    // Cached choices, keyboard actions and reduced motion get no artificial
    // wait. A previously failed work has an honest, linked fallback already.
    if ((image.complete && image.naturalWidth > 0) || image.hidden) {
      commit(choice, animateChoice && !image.hidden);
      return;
    }

    const title = captions.find(caption => caption.dataset.featureCaption === choice.dataset.featureChoice)
      ?.querySelector('h2').textContent.trim();
    const cancel = () => {
      image.removeEventListener('load', loaded);
      image.removeEventListener('error', failed);
      choice.removeAttribute('aria-busy');
      status.textContent = '';
    };
    const finish = success => {
      // Removing listeners also prevents superseded loads from committing. A
      // decode already in flight is guarded by the same request identity.
      if (pending !== selection) return;
      cancel();
      pending = null;
      if (!success) {
        image.hidden = true;
        next.querySelector('.landing-image-failure').hidden = false;
        status.textContent = `Image unavailable for ${title}.`;
      }
      commit(choice, success && selection.animate && !reduce.matches);
    };
    const loaded = () => {
      if (typeof image.decode === 'function') image.decode().then(() => finish(true), () => finish(false));
      else finish(image.naturalWidth > 0);
    };
    const failed = () => finish(false);
    const selection = { cancel, animate: animateChoice };
    pending = selection;
    choice.setAttribute('aria-busy', 'true');
    status.textContent = `Loading ${title}…`;
    image.addEventListener('load', loaded);
    image.addEventListener('error', failed);
    // Hidden lazy images otherwise wait forever for visibility. Keep the
    // current work visible while requesting the actual responsive target.
    image.loading = 'eager';
    if (image.complete && image.currentSrc) {
      if (image.naturalWidth > 0) loaded();
      else failed();
    }
  }));
})();
