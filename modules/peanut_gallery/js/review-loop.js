/**
 * @file
 * The review loop: everything that makes working through feedback
 * effortless. Karaoke-style now-playing sync, a review mode that tours the
 * comments, keyboard shortcuts, copy-a-moment links, and the resolve
 * toggle.
 *
 * Talks to the player only through the public surface waveform-player.js
 * publishes (Drupal.peanutGallery.*) and the pg:* events — never into
 * wavesurfer directly.
 */

((Drupal, drupalSettings, once) => {
  Drupal.behaviors.peanutGalleryReviewLoop = {
    attach(context) {
      once('pg-review-loop', '.pg-recording', context).forEach((recording) => {
        initReviewLoop(recording);
      });
      once('pg-comment-tools', '[data-comment-id][data-timestamp]', context).forEach((el) => {
        addCommentTools(el);
      });
    },
  };

  /**
   * Timestamped comment elements, sorted by their moment.
   *
   * Read fresh each time rather than cached: comments arrive via AJAX and
   * resolve state changes without a page load.
   *
   * @param {HTMLElement} recording
   *   The recording wrapper.
   * @return {{el: HTMLElement, seconds: number}[]}
   *   Sorted comment handles.
   */
  function timedComments(recording) {
    return Array.from(recording.querySelectorAll('[data-comment-id][data-timestamp]'))
      .map((el) => ({ el, seconds: parseFloat(el.dataset.timestamp) }))
      .filter(({ seconds }) => !Number.isNaN(seconds))
      .sort((a, b) => a.seconds - b.seconds);
  }

  /**
   * @param {HTMLElement} recording
   *   The recording wrapper containing player, transport and comments.
   */
  function initReviewLoop(recording) {
    initKaraokeSync(recording);
    const tour = initReviewMode(recording);
    initKeyboard(recording, tour);
    initResolveFilter(recording);
  }

  /**
   * Highlights the comment the playhead most recently passed.
   *
   * Auto-scrolls the list to keep it in view — but backs off for ten
   * seconds whenever the visitor scrolls themselves, because fighting a
   * human for the scrollbar is the fastest way to get a feature turned
   * off.
   *
   * @param {HTMLElement} recording
   *   The recording wrapper.
   */
  function initKaraokeSync(recording) {
    let currentEl = null;
    let lastManualScroll = 0;

    ['wheel', 'touchmove'].forEach((type) => {
      window.addEventListener(type, () => {
        lastManualScroll = Date.now();
      }, { passive: true });
    });

    document.addEventListener('pg:waveform-position', (event) => {
      const seconds = event.detail.seconds;
      const passed = timedComments(recording).filter((c) => c.seconds <= seconds);
      const next = passed.length ? passed[passed.length - 1].el : null;
      if (next === currentEl) {
        return;
      }
      if (currentEl) {
        currentEl.classList.remove('pg-comment--current');
      }
      currentEl = next;
      if (!currentEl) {
        return;
      }
      currentEl.classList.add('pg-comment--current');
      if (Date.now() - lastManualScroll > 10000) {
        currentEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  }

  /**
   * Adds the review-mode toggle: a guided tour through every comment.
   *
   * Plays each comment's context in order — pre-roll before the moment,
   * a few seconds after it — then advances to the next. Listening to your
   * feedback like a playlist.
   *
   * @param {HTMLElement} recording
   *   The recording wrapper.
   * @return {{active: () => boolean, stop: () => void, next: () => void, prev: () => void}}
   *   Handle the keyboard layer uses to steer the tour.
   */
  function initReviewMode(recording) {
    const POSTROLL_SECONDS = 5;
    const transport = recording.querySelector('.pg-waveform-transport');
    let index = -1;
    let stops = [];

    const active = () => index >= 0;

    const stop = () => {
      index = -1;
      stops = [];
      if (button) {
        button.textContent = Drupal.t('Review comments');
        button.classList.remove('is-active');
      }
    };

    const goTo = (i) => {
      if (i < 0 || i >= stops.length) {
        stop();
        return;
      }
      index = i;
      Drupal.peanutGallery.jumpToMoment(stops[i].seconds);
      stops[i].el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      stops[i].el.classList.add('pg-comment--highlighted');
      window.setTimeout(() => stops[i]?.el.classList.remove('pg-comment--highlighted'), 2000);
    };

    const start = () => {
      stops = timedComments(recording);
      if (!stops.length) {
        return;
      }
      if (button) {
        button.textContent = Drupal.t('Stop review');
        button.classList.add('is-active');
      }
      goTo(0);
    };

    let button = null;
    if (transport) {
      button = document.createElement('button');
      button.type = 'button';
      button.className = 'pg-review-toggle';
      button.textContent = Drupal.t('Review comments');
      button.addEventListener('click', () => (active() ? stop() : start()));
      transport.appendChild(button);
    }

    document.addEventListener('pg:waveform-position', (event) => {
      if (!active()) {
        return;
      }
      const target = stops[index];
      if (target && event.detail.seconds > target.seconds + POSTROLL_SECONDS) {
        goTo(index + 1);
      }
    });

    return {
      active,
      stop,
      next: () => (active() ? goTo(index + 1) : null),
      prev: () => (active() ? goTo(Math.max(0, index - 1)) : null),
    };
  }

  /**
   * Keyboard shortcuts for hands-on-keyboard listening.
   *
   * space play/pause · ←/→ ±5s (shift: ±30s) · j/k next/prev comment ·
   * c focus the comment box · ? this list. Never intercepts typing.
   *
   * @param {HTMLElement} recording
   *   The recording wrapper.
   * @param {object} tour
   *   The review-mode handle, so j/k steer the tour while it runs.
   */
  function initKeyboard(recording, tour) {
    let cursor = -1;

    const jumpRelative = (direction) => {
      if (tour.active()) {
        direction > 0 ? tour.next() : tour.prev();
        return;
      }
      const comments = timedComments(recording);
      if (!comments.length) {
        return;
      }
      const playhead = Drupal.peanutGallery?.getPlayheadSeconds?.() ?? 0;
      // Anchor on the playhead, not the last cursor: the listener may have
      // scrubbed anywhere since the last jump.
      const passed = comments.filter((c) => c.seconds < playhead - 0.5).length;
      cursor = direction > 0 ? Math.min(passed, comments.length - 1) : Math.max(passed - 2, 0);
      const target = comments[cursor];
      Drupal.peanutGallery.jumpToMoment(target.seconds);
      target.el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      target.el.classList.add('pg-comment--highlighted');
      window.setTimeout(() => target.el.classList.remove('pg-comment--highlighted'), 2000);
    };

    document.addEventListener('keydown', (event) => {
      const target = event.target;
      const typing = target instanceof HTMLElement
        && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);

      if (event.key === 'Escape') {
        hideShortcutOverlay();
        if (typing) {
          target.blur();
        }
        return;
      }
      if (typing || event.altKey || event.ctrlKey || event.metaKey) {
        return;
      }

      switch (event.key) {
        case ' ':
          event.preventDefault();
          Drupal.peanutGallery?.playPause?.();
          break;

        case 'ArrowLeft':
          event.preventDefault();
          Drupal.peanutGallery?.seekBy?.(event.shiftKey ? -30 : -5);
          break;

        case 'ArrowRight':
          event.preventDefault();
          Drupal.peanutGallery?.seekBy?.(event.shiftKey ? 30 : 5);
          break;

        case 'j':
          jumpRelative(1);
          break;

        case 'k':
          jumpRelative(-1);
          break;

        case 'c': {
          const body = recording.querySelector('form textarea');
          if (body) {
            event.preventDefault();
            body.focus();
          }
          break;
        }

        case '?':
          toggleShortcutOverlay();
          break;

        default:
      }
    });
  }

  /**
   * The ?-key cheat sheet.
   */
  let overlayEl = null;

  function toggleShortcutOverlay() {
    if (overlayEl) {
      hideShortcutOverlay();
      return;
    }
    overlayEl = document.createElement('div');
    overlayEl.className = 'pg-shortcuts-overlay';
    overlayEl.setAttribute('role', 'dialog');
    overlayEl.setAttribute('aria-label', Drupal.t('Keyboard shortcuts'));

    const rows = [
      [Drupal.t('Space'), Drupal.t('Play / pause')],
      ['← / →', Drupal.t('Back / forward 5s (Shift: 30s)')],
      ['j / k', Drupal.t('Next / previous comment')],
      ['c', Drupal.t('Write a comment (pins the current moment)')],
      ['Esc', Drupal.t('Close / leave the comment box')],
      ['?', Drupal.t('Show or hide this list')],
    ];
    const list = document.createElement('dl');
    rows.forEach(([key, label]) => {
      const dt = document.createElement('dt');
      dt.textContent = key;
      const dd = document.createElement('dd');
      dd.textContent = label;
      list.append(dt, dd);
    });
    overlayEl.appendChild(list);
    overlayEl.addEventListener('click', hideShortcutOverlay);
    document.body.appendChild(overlayEl);
  }

  function hideShortcutOverlay() {
    overlayEl?.remove();
    overlayEl = null;
  }

  /**
   * Per-comment tools: copy-a-moment link and the resolve toggle.
   *
   * Both are progressive enhancements on server-rendered markup: the
   * moment link is a real <a> that works without JS, and the resolve
   * button only exists in the markup for people allowed to use it.
   *
   * @param {HTMLElement} el
   *   A comment element.
   */
  function addCommentTools(el) {
    const link = el.querySelector('.pg-comment-link');
    if (link && navigator.clipboard) {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        const absolute = new URL(link.getAttribute('href'), window.location.href).toString();
        navigator.clipboard.writeText(absolute).then(() => {
          const original = link.textContent;
          link.textContent = Drupal.t('Copied');
          window.setTimeout(() => {
            link.textContent = original;
          }, 1500);
        });
      });
    }

    const resolve = el.querySelector('[data-pg-resolve]');
    if (resolve) {
      resolve.addEventListener('click', async () => {
        resolve.disabled = true;
        try {
          const response = await fetch(resolve.dataset.pgResolve, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': drupalSettings.peanutGallery?.csrfToken || '' },
          });
          if (!response.ok) {
            return;
          }
          const state = await response.json();
          el.classList.toggle('pg-comment--resolved', state.resolved);
          resolve.textContent = state.resolved ? Drupal.t('Reopen') : Drupal.t('Resolve');
          document.dispatchEvent(new CustomEvent('pg:comment-resolved'));
        }
        finally {
          resolve.disabled = false;
        }
      });
    }
  }

  /**
   * The "hide resolved" filter and open-count above the comment list.
   *
   * @param {HTMLElement} recording
   *   The recording wrapper.
   */
  function initResolveFilter(recording) {
    const list = recording.querySelector('[data-pg-comment-list]');
    if (!list) {
      return;
    }

    const bar = document.createElement('div');
    bar.className = 'pg-resolve-filter';
    bar.hidden = true;

    const count = document.createElement('span');
    count.className = 'pg-resolve-filter__count';

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'pg-resolve-filter__toggle';
    toggle.addEventListener('click', () => {
      list.classList.toggle('pg-comments--hide-resolved');
      update();
    });

    bar.append(count, toggle);
    list.parentElement.insertBefore(bar, list);

    const update = () => {
      const total = recording.querySelectorAll('[data-comment-id]').length;
      const resolved = recording.querySelectorAll('.pg-comment--resolved').length;
      // The bar earns its screen space only once resolution is in use.
      bar.hidden = resolved === 0;
      count.textContent = Drupal.t('@open of @total open', {
        '@open': total - resolved,
        '@total': total,
      });
      toggle.textContent = list.classList.contains('pg-comments--hide-resolved')
        ? Drupal.t('Show resolved')
        : Drupal.t('Hide resolved');
    };

    update();
    document.addEventListener('pg:comment-resolved', update);
    document.addEventListener('pg:comment-posted', update);
  }
})(Drupal, drupalSettings, once);
