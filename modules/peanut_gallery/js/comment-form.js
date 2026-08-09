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
      if (currentSeconds === null) {
        indicator.innerHTML = '';
        indicator.hidden = true;
        return;
      }
      indicator.hidden = false;
      indicator.innerHTML = '';

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
      currentSeconds = seconds;
      input.value = seconds === null ? '' : seconds;
      render();
    };

    document.addEventListener('pg:waveform-seek', (event) => {
      setSeconds(event.detail.seconds);
      wrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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
   * @param {number} seconds
   * @return {string}
   */
  function formatTime(seconds) {
    const whole = Math.max(0, Math.round(seconds));
    const minutes = Math.floor(whole / 60);
    const remaining = whole % 60;
    return `${minutes}:${String(remaining).padStart(2, '0')}`;
  }
})(Drupal, once);
