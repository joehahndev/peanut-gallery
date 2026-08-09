/**
 * @file
 * Renders a wavesurfer.js waveform player over a recording's audio, with a
 * clickable marker for every existing timestamped comment.
 *
 * Deliberately DOM-first: the audio URL and every comment's timestamp are
 * read from data-* attributes Drupal already rendered server-side (the
 * waveform container, and the comment list built by the comment field) —
 * there is no separate JSON endpoint to keep in sync.
 *
 * If cached peaks exist for this recording (see
 * peanut_gallery.peaks_generator / PeaksController, /peanut-gallery/peaks/{node}),
 * they're used to draw the waveform instantly via wavesurfer's
 * `peaks`/`duration` options, skipping a full client-side decode of a
 * potentially hour-long file. If they don't exist yet (ffmpeg unavailable,
 * or not generated yet) the endpoint 404s and wavesurfer just decodes
 * normally — slower first paint, but nothing breaks.
 */

((Drupal, once) => {
  Drupal.behaviors.peanutGalleryWaveformPlayer = {
    attach(context) {
      once('pg-waveform', '.pg-waveform', context).forEach((container) => {
        initPlayer(container);
      });
    },
  };

  /**
   * @param {HTMLElement} container
   */
  async function initPlayer(container) {
    const audioUrl = container.dataset.audioUrl;
    if (!audioUrl || typeof WaveSurfer === 'undefined') {
      return;
    }

    const style = getComputedStyle(container);
    const createOptions = {
      container,
      url: audioUrl,
      // MediaElement (not WebAudio decode-to-buffer) so an hour-long file
      // streams for playback instead of being fully downloaded/decoded.
      backend: 'MediaElement',
      waveColor: readToken(style, '--pg-waveform-wave-color', '#999'),
      progressColor: readToken(style, '--pg-waveform-progress-color', '#555'),
      cursorColor: readToken(style, '--pg-waveform-cursor-color', '#222'),
      height: 96,
      normalize: true,
    };

    const nodeId = container.dataset.nodeId;
    if (nodeId) {
      const cached = await fetchPeaks(Drupal.url(`peanut-gallery/peaks/${nodeId}`));
      if (cached) {
        // wavesurfer expects one array of sample values per audio channel —
        // this endpoint always returns a single (mono) channel.
        createOptions.peaks = [cached.peaks];
        createOptions.duration = cached.duration;
      }
    }

    const player = WaveSurfer.create(createOptions);
    const regions = player.registerPlugin(WaveSurfer.Regions.create());

    const markerColor = readToken(style, '--pg-waveform-marker-color', '#c0392b');
    const markerActiveColor = readToken(style, '--pg-waveform-marker-active-color', '#e74c3c');

    player.on('ready', () => {
      findCommentEls(container).forEach(({ el, id, timestamp }) => {
        regions.addRegion({
          id: `pg-marker-${id}`,
          start: timestamp,
          end: timestamp,
          drag: false,
          resize: false,
          color: markerColor,
        });
      });
    });

    regions.on('region-clicked', (region, e) => {
      e.stopPropagation();
      player.setTime(region.start);
      highlightComment(region.id.replace('pg-marker-', ''), markerActiveColor);
    });

    // A direct click/drag on the waveform itself (not a marker) seeks
    // playback there and asks the comment form to prefill that moment.
    player.on('interaction', (newTime) => {
      document.dispatchEvent(
        new CustomEvent('pg:waveform-seek', {
          detail: { seconds: newTime, playerId: container.dataset.playerId },
        }),
      );
    });
  }

  /**
   * @param {string} url
   * @return {Promise<{peaks: number[][], duration: number}|null>}
   */
  async function fetchPeaks(url) {
    try {
      const response = await fetch(url, { credentials: 'same-origin' });
      if (!response.ok) {
        return null;
      }
      const data = await response.json();
      if (!Array.isArray(data.peaks) || typeof data.duration !== 'number') {
        return null;
      }
      return data;
    } catch (e) {
      return null;
    }
  }

  /**
   * @param {HTMLElement} container
   * @return {{el: HTMLElement, id: string, timestamp: number}[]}
   */
  function findCommentEls(container) {
    const scope = container.closest('.pg-recording') || document;
    return Array.from(scope.querySelectorAll('[data-comment-id][data-timestamp]')).map((el) => ({
      el,
      id: el.dataset.commentId,
      timestamp: parseFloat(el.dataset.timestamp),
    }));
  }

  /**
   * Scrolls to and briefly highlights the comment a marker belongs to.
   *
   * @param {string} commentId
   * @param {string} color
   */
  function highlightComment(commentId) {
    const el = document.querySelector(`[data-comment-id="${commentId}"]`);
    if (!el) {
      return;
    }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('pg-comment--highlighted');
    window.setTimeout(() => el.classList.remove('pg-comment--highlighted'), 2000);
  }

  /**
   * @param {CSSStyleDeclaration} style
   * @param {string} token
   * @param {string} fallback
   * @return {string}
   */
  function readToken(style, token, fallback) {
    const value = style.getPropertyValue(token).trim();
    return value || fallback;
  }
})(Drupal, once);
