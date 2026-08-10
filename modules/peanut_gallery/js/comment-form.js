/**
 * @file
 * Wires the recording comment form's timestamp field to the waveform
 * player: clicking the waveform fills it in and shows a friendly
 * "Commenting at 1:23" indicator instead of a bare number input.
 *
 * The plain number field (with its label/description) is always the real
 * fallback for JS-disabled visitors — this behavior only adds a nicer
 * presentation on top when JS is available, toggled via core's own
 * html.js/html.no-js class, so there is no separate "no-JS" code path to
 * keep in sync here.
 */

((Drupal, once) => {
  /**
   * Receives CommentPostedCommand and re-broadcasts it as a real event.
   *
   * Drupal's own AJAX commands go through jQuery; this module's JS does
   * not use jQuery, so the signal is converted once here rather than
   * pulling jQuery into every listener.
   */
  Drupal.AjaxCommands.prototype.peanutGalleryCommentPosted = function peanutGalleryCommentPosted() {
    document.dispatchEvent(new CustomEvent('pg:comment-posted'));
  };

  Drupal.behaviors.peanutGalleryCommentTimestamp = {
    attach(context) {
      once('pg-comment-form', '.pg-timestamp-field-wrapper', context).forEach((wrapper) => {
        initTimestampField(wrapper);
      });
    },
  };

  /**
   * @param {HTMLElement} wrapper
   */
  function initTimestampField(wrapper) {
    const input = wrapper.querySelector('input[name="field_timestamp[0][value]"]');
    const form = wrapper.closest('form');
    if (!input || !form) {
      return;
    }

    let currentSeconds = input.value !== '' ? parseFloat(input.value) : null;

    const indicator = document.createElement('div');
    indicator.className = 'pg-timestamp-indicator';
    wrapper.insertBefore(indicator, wrapper.firstChild);

    const render = () => {
      indicator.hidden = false;
      indicator.innerHTML = '';

      if (currentSeconds === null) {
        const prompt = document.createElement('span');
        prompt.className = 'pg-timestamp-indicator__label pg-timestamp-indicator__label--prompt';
        prompt.textContent = Drupal.t('Click the waveform above to set when this comment happened.');
        indicator.appendChild(prompt);
        return;
      }

      const label = document.createElement('span');
      label.className = 'pg-timestamp-indicator__label';
      label.textContent = Drupal.t('Commenting at @time', { '@time': formatTime(currentSeconds) });
      indicator.appendChild(label);

      const clearButton = document.createElement('button');
      clearButton.type = 'button';
      clearButton.className = 'pg-timestamp-indicator__clear';
      clearButton.textContent = Drupal.t('Clear');
      clearButton.addEventListener('click', () => setSeconds(null));
      indicator.appendChild(clearButton);
    };

    const setSeconds = (seconds) => {
      // The field is decimal(10,3) / step="0.001" — wavesurfer's raw time
      // values have far more float precision than that, which fails the
      // input's HTML5 step constraint and silently blocks submission
      // (the constraint-violation tooltip has nothing visible to anchor
      // to, since this field is visually hidden once JS is active).
      currentSeconds = seconds === null ? null : Math.round(seconds * 1000) / 1000;
      input.value = currentSeconds === null ? '' : currentSeconds;
      render();
    };

    document.addEventListener('pg:waveform-seek', (event) => {
      setSeconds(event.detail.seconds);
      wrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    // The AJAX post empties the fields but cannot reset this indicator,
    // which is JS state — without this it would keep claiming a moment
    // that belongs to the comment just submitted.
    document.addEventListener('pg:comment-posted', () => {
      setSeconds(null);
    });

    // Defensive second layer: re-write the field from the value this
    // behavior is holding right before submission, in case anything else
    // touched the input in between.
    form.addEventListener('submit', () => {
      if (currentSeconds !== null) {
        input.value = currentSeconds;
      }
    });

    render();
  }

  /**
   * Formats seconds as m:ss.t, matching _peanut_gallery_format_timestamp().
   *
   * Tenths matter here: this is the moment a comment is pinned to, and a
   * whole second is a long time in a mix. Rounding before splitting out
   * the minutes keeps 59.97 rendering as 1:00.0, not 0:60.0.
   *
   * @param {number} seconds
   * @return {string}
   */
  function formatTime(seconds) {
    const tenths = Math.max(0, Math.round((seconds || 0) * 10) / 10);
    const hours = Math.floor(tenths / 3600);
    const minutes = Math.floor((tenths - hours * 3600) / 60);
    const remaining = (tenths - hours * 3600 - minutes * 60).toFixed(1).padStart(4, '0');

    // Matches _peanut_gallery_format_timestamp(): hours only when present.
    return hours > 0
      ? `${hours}:${String(minutes).padStart(2, '0')}:${remaining}`
      : `${minutes}:${remaining}`;
  }
})(Drupal, once);
