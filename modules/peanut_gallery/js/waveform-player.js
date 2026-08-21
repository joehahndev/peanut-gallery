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

((Drupal, drupalSettings, once) => {
  /**
   * Every player instantiated on this page.
   *
   * A listing page renders one player per recording, and two recordings
   * playing over each other is never what someone wants, so starting one
   * pauses the rest.
   *
   * @type {object[]}
   */
  const players = [];

  /**
   * Marker regions by player id, so comments added later can be pinned.
   *
   * @type {Object<string, object>}
   */
  const markerLayers = {};

  Drupal.behaviors.peanutGalleryWaveformPlayer = {
    attach(context) {
      once('pg-waveform', '.pg-waveform', context).forEach((container) => {
        initPlayer(container);
      });

      // Runs for AJAX-inserted content too, which is how a comment posted
      // without a page reload gets its marker: the player is already
      // running and must not be touched, so only the new comment element
      // is processed.
      once('pg-marker', '[data-comment-id][data-timestamp]', context).forEach((el) => {
        addMarkerFor(el);
        wireTimestampJump(el);
      });
    },
  };

  /**
   * Makes a comment's timestamp badge jump playback to that moment.
   *
   * The badge is a real button in the markup, so this only has to attach
   * behaviour — keyboard activation and focus come free rather than being
   * bolted onto a span.
   *
   * @param {HTMLElement} el
   *   A comment carrying data-comment-id and data-timestamp.
   */
  function wireTimestampJump(el) {
    const badge = el.querySelector('[data-pg-seek]');
    if (!badge) {
      return;
    }

    badge.addEventListener('click', () => {
      document.dispatchEvent(
        new CustomEvent('pg:seek-request', {
          detail: { seconds: parseFloat(el.dataset.timestamp) },
        }),
      );
    });
  }

  /**
   * Adds a waveform marker for one comment element.
   *
   * @param {HTMLElement} el
   *   A comment carrying data-comment-id and data-timestamp.
   */
  function addMarkerFor(el) {
    const timestamp = parseFloat(el.dataset.timestamp);
    if (Number.isNaN(timestamp)) {
      return;
    }

    // With one recording per page there is a single player; find whichever
    // marker layer is ready. A comment can arrive before 'ready' fires, in
    // which case initPlayer's own pass picks it up instead.
    const layer = Object.values(markerLayers)[0];
    if (!layer) {
      return;
    }

    const region = layer.regions.addRegion({
      id: `pg-marker-${el.dataset.commentId}`,
      start: timestamp,
      end: timestamp,
      drag: false,
      resize: false,
      color: layer.color,
    });
    titleMarker(region, el);
  }

  /**
   * Gives a marker a hover tooltip naming its comment.
   *
   * Best-effort on purpose: the marker element is wavesurfer-internal, so
   * if a future vendored build stops exposing it the marker still works,
   * it just loses its tooltip.
   *
   * @param {object} region
   *   The regions-plugin region just created.
   * @param {HTMLElement} el
   *   The comment element the marker belongs to.
   */
  function titleMarker(region, el) {
    if (!region || !region.element) {
      return;
    }
    const author = el.querySelector('.pg-comment__author')?.textContent?.trim() || '';
    const body = el.querySelector('.pg-comment__body')?.textContent?.trim().replace(/\s+/g, ' ') || '';
    const snippet = body.length > 80 ? `${body.slice(0, 80)}…` : body;
    region.element.title = author && snippet ? `${author}: ${snippet}` : author || snippet;
  }

  /**
   * Seconds of context to play before a comment's moment when jumping.
   *
   * @return {number}
   */
  function prerollSeconds() {
    const value = drupalSettings.peanutGallery?.prerollSeconds;
    return typeof value === 'number' && value >= 0 ? value : 3;
  }

  /**
   * @param {HTMLElement} container
   */
  async function initPlayer(container) {
    const audioUrl = container.dataset.audioUrl;
    if (!audioUrl || typeof WaveSurfer === 'undefined') {
      return;
    }

    const style = getComputedStyle(container);

    // One media element, shared by however many views this recording has.
    // Two wavesurfer instances pointed at the same <audio> stay in step for
    // free, because there is only one thing actually playing — no position
    // syncing to write, and nothing to drift.
    const media = new Audio();
    media.preload = 'metadata';
    media.src = audioUrl;

    const createOptions = {
      container,
      media,
      // MediaElement (not WebAudio decode-to-buffer) so an hour-long file
      // streams for playback instead of being fully downloaded/decoded.
      backend: 'MediaElement',
      waveColor: readToken(style, '--pg-waveform-wave-color', '#999'),
      // Transparent on purpose — the played region is marked by a veil
      // (attachProgressVeil) rather than wavesurfer's own progress layer.
      // That layer redraws the waveform through a canvas clone and a
      // `source-in` fill, and on the colour waveform the result on screen
      // lost far more of the hue than the configured alpha accounted for.
      // A plain translucent div composites in one obvious step instead:
      // what shows is exactly colour x (1 - alpha).
      progressColor: 'rgba(0, 0, 0, 0)',
      cursorColor: readToken(style, '--pg-waveform-cursor-color', '#222'),
      // Height comes from the theme too, so a compact context (e.g. the
      // teaser cards on the /recordings listing) can shrink the waveform
      // without this module knowing anything about listing layouts.
      height: parseInt(readToken(style, '--pg-waveform-height', '96'), 10) || 96,
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

        const colors = decodeColors(cached.color, cached.colorPoints);
        if (colors) {
          createOptions.renderFunction = makeColorRenderer(colors, maxOf(cached.peaks));
        }
      }
    }

    const player = WaveSurfer.create(createOptions);
    const regions = player.registerPlugin(WaveSurfer.Regions.create());

    attachProgressVeil(container, readToken(style, '--pg-waveform-progress-color', 'rgba(0, 0, 0, 0.5)'));

    initDetailView(container, media, style);

    const markerColor = readToken(style, '--pg-waveform-marker-color', '#c0392b');
    const markerActiveColor = readToken(style, '--pg-waveform-marker-active-color', '#e74c3c');
    const timeEl = document.querySelector(`.pg-waveform-time[data-player-id="${container.dataset.playerId}"]`);
    const toggleEl = document.querySelector(`.pg-waveform-toggle[data-player-id="${container.dataset.playerId}"]`);
    const transportEl = document.querySelector(`.pg-waveform-transport[data-player-id="${container.dataset.playerId}"]`);

    // The whole transport is hidden in the markup and revealed here, so a
    // visitor without JavaScript is never shown controls that do nothing.
    if (transportEl) {
      transportEl.hidden = false;
      transportEl.querySelectorAll('[data-pg-skip]').forEach((button) => {
        button.addEventListener('click', () => {
          const delta = parseFloat(button.dataset.pgSkip);
          const duration = player.getDuration() || 0;
          // Clamping matters at both ends: seeking past the end stops
          // playback dead, and a negative time is simply invalid.
          const target = Math.min(Math.max(player.getCurrentTime() + delta, 0), Math.max(duration - 0.1, 0));
          player.setTime(target);
        });
      });
    }

    players.push(player);
    if (toggleEl) {
      wireToggle(player, toggleEl);
    }

    // Published so comments posted later (via AJAX, without a reload) can
    // add their own marker without re-initialising anything.
    markerLayers[container.dataset.playerId] = { regions, color: markerColor };

    // Let the comment form ask where the playhead is at any moment. An
    // event-only channel would go quiet whenever playback is paused, and
    // "paused halfway through, writing a comment about that bit" is the
    // normal case rather than an edge one.
    Drupal.peanutGallery = Drupal.peanutGallery || {};
    Drupal.peanutGallery.getPlayheadSeconds = () => player.getCurrentTime();

    // The rest of the public surface, consumed by review-loop.js (keyboard
    // shortcuts, review mode) so it never has to reach into wavesurfer.
    Drupal.peanutGallery.getDurationSeconds = () => player.getDuration();
    Drupal.peanutGallery.playPause = () => player.playPause();
    Drupal.peanutGallery.seekTo = (seconds, play = false) => {
      const duration = player.getDuration() || 0;
      player.setTime(Math.min(Math.max(seconds, 0), Math.max(duration - 0.1, 0)));
      if (play && !player.isPlaying()) {
        player.play();
      }
    };
    Drupal.peanutGallery.seekBy = (delta) => {
      Drupal.peanutGallery.seekTo(player.getCurrentTime() + delta);
    };
    // The canonical "jump to a comment's moment": land pre-roll seconds
    // early, already playing, so the listener hears the context leading
    // into the moment instead of starting dead on it.
    Drupal.peanutGallery.jumpToMoment = (seconds) => {
      Drupal.peanutGallery.seekTo(Math.max(0, seconds - prerollSeconds()), true);
    };

    player.on('ready', () => {
      findCommentEls(container).forEach(({ el, id, timestamp }) => {
        const region = regions.addRegion({
          id: `pg-marker-${id}`,
          start: timestamp,
          end: timestamp,
          drag: false,
          resize: false,
          color: markerColor,
        });
        titleMarker(region, el);
      });

      if (timeEl) {
        updateTransportTime(timeEl, player.getCurrentTime(), player.getDuration());
      }

      applyDeepLink(player);
    });

    if (timeEl) {
      player.on('timeupdate', (currentTime) => {
        updateTransportTime(timeEl, currentTime, player.getDuration());
      });
      player.on('interaction', (newTime) => {
        updateTransportTime(timeEl, newTime, player.getDuration());
      });
    }

    regions.on('region-clicked', (region, e) => {
      e.stopPropagation();
      // Same pre-roll treatment as clicking the comment itself: a marker
      // is just the comment's handle on the waveform.
      Drupal.peanutGallery.jumpToMoment(region.start);
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

    // Publish the play position so the comment form can pin a comment to
    // wherever the listener actually is, without them having to click the
    // waveform first.
    player.on('timeupdate', (currentTime) => {
      document.dispatchEvent(
        new CustomEvent('pg:waveform-position', {
          detail: { seconds: currentTime, playerId: container.dataset.playerId },
        }),
      );
    });

    // Clicking a comment's timestamp jumps there and plays, which is the
    // point of pinning comments to moments in the first place. The jump
    // lands pre-roll seconds early so the moment arrives in context.
    document.addEventListener('pg:seek-request', (event) => {
      const seconds = event.detail.seconds;
      if (typeof seconds !== 'number' || Number.isNaN(seconds)) {
        return;
      }
      Drupal.peanutGallery.jumpToMoment(seconds);
    });
  }

  /**
   * Seeks to the moment named by the URL, if any.
   *
   * Two forms: ?t=93.4 (a copied moment link) and #comment-N (a comment
   * permalink, e.g. from a notification email — those also carry ?t=, but
   * the fragment alone is enough). Seeks paused: browsers block autoplay
   * with sound before a user gesture, and silently swallowing play() would
   * leave the transport claiming a state the audio isn't in.
   *
   * @param {object} player
   *   The wavesurfer instance.
   */
  function applyDeepLink(player) {
    const params = new URLSearchParams(window.location.search);
    let seconds = parseFloat(params.get('t'));

    if (Number.isNaN(seconds)) {
      const match = window.location.hash.match(/^#comment-(\d+)$/);
      if (match) {
        const el = document.querySelector(`[data-comment-id="${match[1]}"][data-timestamp]`);
        seconds = el ? parseFloat(el.dataset.timestamp) : NaN;
      }
    }

    if (Number.isNaN(seconds)) {
      return;
    }
    player.setTime(Math.max(0, seconds - prerollSeconds()));
  }

  /**
   * Turns the server-rendered toggle into a working play/pause control.
   *
   * The button ships with a `hidden` attribute so it is never a dead
   * control without JavaScript; it is revealed here, and stays disabled
   * until wavesurfer reports it can actually play.
   *
   * @param {object} player
   *   The wavesurfer instance.
   * @param {HTMLButtonElement} toggleEl
   *   The button rendered next to this player.
   */
  function wireToggle(player, toggleEl) {
    toggleEl.disabled = true;

    player.on('ready', () => {
      toggleEl.disabled = false;
    });

    toggleEl.addEventListener('click', () => {
      player.playPause();
    });

    const setPlaying = (playing) => {
      toggleEl.textContent = playing ? Drupal.t('Pause') : Drupal.t('Play');
      toggleEl.classList.toggle('is-playing', playing);
    };

    player.on('play', () => {
      setPlaying(true);
      players.forEach((other) => {
        if (other !== player && other.isPlaying()) {
          other.pause();
        }
      });
    });
    player.on('pause', () => setPlaying(false));
    player.on('finish', () => setPlaying(false));
  }

  /**
   * Builds the zoomed, scrolling view beneath the overview.
   *
   * Shares the overview's media element, so playback, seeking and the two
   * cursors stay in step without any syncing code. It needs its own, far
   * denser peaks: the overview's 800 buckets span the whole recording, so
   * at this zoom barely one of them would fall inside the visible window.
   *
   * @param {HTMLElement} overviewContainer
   *   The overview waveform's container, used to find the detail one.
   * @param {HTMLMediaElement} media
   *   The audio element both views share.
   * @param {CSSStyleDeclaration} style
   *   Computed style carrying the theme's colour tokens.
   */
  async function initDetailView(overviewContainer, media, style) {
    const playerId = overviewContainer.dataset.playerId;
    const container = document.querySelector(`.pg-waveform-detail[data-player-id="${playerId}"]`);
    const nodeId = overviewContainer.dataset.nodeId;
    if (!container || !nodeId) {
      return;
    }

    const dense = await fetchPeaks(Drupal.url(`peanut-gallery/peaks/${nodeId}/detail`));
    if (!dense || !dense.peaks.length) {
      // Without dense peaks this view would be a flat line, which is worse
      // than not offering it.
      return;
    }

    const detailColors = decodeColors(dense.color, dense.colorPoints);
    const windowSeconds = parseFloat(container.dataset.windowSeconds) || 60;

    // Reveal before measuring: a hidden element reports clientWidth 0, and
    // a zoom of zero px/sec silently renders the whole recording at
    // overview scale instead of the intended window.
    container.hidden = false;
    const pxPerSec = (container.clientWidth || overviewContainer.clientWidth) / windowSeconds;

    const detail = WaveSurfer.create({
      container,
      media,
      backend: 'MediaElement',
      peaks: [dense.peaks],
      duration: dense.duration,
      waveColor: readToken(style, '--pg-waveform-wave-color', '#999'),
      // Transparent for the same reason as the overview: the played region
      // is marked by the veil below, not by wavesurfer's progress layer.
      progressColor: 'rgba(0, 0, 0, 0)',
      cursorColor: readToken(style, '--pg-waveform-cursor-color', '#222'),
      height: parseInt(readToken(style, '--pg-waveform-detail-height', '96'), 10) || 96,
      normalize: true,
      // Zoom so the requested window fills the width, then let wavesurfer
      // scroll it and keep the cursor centred. fillParent must be off or
      // it would squeeze the whole recording back into view.
      minPxPerSec: pxPerSec,
      fillParent: false,
      autoScroll: true,
      autoCenter: true,
      hideScrollbar: true,
      ...(detailColors ? { renderFunction: makeColorRenderer(detailColors, maxOf(dense.peaks)) } : {}),
    });

    // Same played-region treatment as the overview, so the two views read
    // as one instrument rather than two behaviours.
    attachProgressVeil(container, readToken(style, '--pg-waveform-progress-color', 'rgba(0, 0, 0, 0.5)'));

    // Clicking here pins a comment to that moment, exactly as clicking the
    // overview does. It needs saying twice because `interaction` is raised
    // by the instance the pointer actually landed on: the shared media
    // element means the *seek* propagates to the overview for free, but the
    // event does not, so without this the precise view — the one worth
    // clicking, at 122x the overview's resolution — was the one that could
    // not set a timestamp.
    detail.on('interaction', (newTime) => {
      document.dispatchEvent(
        new CustomEvent('pg:waveform-seek', {
          detail: { seconds: newTime, playerId },
        }),
      );
    });

    // Re-zoom on resize so the window stays a minute wide rather than
    // however many minutes the original width happened to imply.
    let resizeTimer = null;
    window.addEventListener('resize', () => {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(() => {
        detail.zoom(container.clientWidth / windowSeconds);
      }, 150);
    });
  }

  /**
   * Turns the packed colour payload into bytes the renderer can index.
   *
   * @param {string|undefined} encoded
   *   Base64 of low/mid/high triplets, one byte per band per point.
   * @param {number|undefined} points
   *   How many points those bytes describe.
   * @return {{bytes: Uint8Array, points: number}|null}
   *   NULL whenever colour is absent or malformed, which is a supported
   *   state: the waveform simply draws in one colour.
   */
  function decodeColors(encoded, points) {
    if (typeof encoded !== 'string' || !encoded || !points) {
      return null;
    }

    try {
      const binary = atob(encoded);
      if (binary.length < points * 3) {
        return null;
      }
      const bytes = new Uint8Array(points * 3);
      for (let i = 0; i < bytes.length; i++) {
        bytes[i] = binary.charCodeAt(i);
      }
      return { bytes, points };
    }
    catch (error) {
      return null;
    }
  }

  /**
   * Marks the played region with a translucent veil instead of a redraw.
   *
   * wavesurfer does not dim the played region — it *removes* it. Its
   * renderProgress() sets a clip-path on the wave layer so only the
   * unplayed side of the real waveform displays, and shows a recoloured
   * canvas clone (a `source-in` fill) on the played side. With an opaque
   * progressColor that clone is a flat silhouette; with a translucent one
   * it is a faint tint over the page background — because the coloured
   * waveform was never underneath it in the first place. No progressColor
   * value can produce "the same colours, dimmed" through that pipeline.
   *
   * So: cancel the clip so the true waveform stays visible edge to edge
   * (!important beats the inline style renderProgress writes on every
   * timeupdate), hide the clone, and paint the `.progress` element — which
   * wavesurfer keeps sized to the played width — as a translucent veil
   * over the real thing. One obvious composite: the colour underneath
   * shows through at exactly 1 - alpha, identically in both themes.
   *
   * Reaching into the shadow root is deliberate and contained here; the
   * selectors and behaviour are pinned by the vendored wavesurfer build,
   * so they cannot drift underneath us.
   *
   * @param {HTMLElement} container
   *   The element wavesurfer rendered into.
   * @param {string} color
   *   The veil colour, normally translucent black from the theme.
   */
  function attachProgressVeil(container, color) {
    const host = [...container.children].find((node) => node.shadowRoot);
    if (!host) {
      return;
    }
    const veil = document.createElement('style');
    veil.textContent = `
      .canvases { clip-path: none !important; }
      .progress { background: ${color}; }
      .progress canvas { visibility: hidden; }
    `;
    host.shadowRoot.appendChild(veil);
  }

  /**
   * Largest value in a peak series, without blowing the stack.
   *
   * Math.max(...array) is the idiomatic one-liner and it throws on the
   * dense series — spreading three hundred thousand arguments exceeds the
   * engine's argument limit.
   *
   * @param {number[]} values
   * @return {number}
   */
  function maxOf(values) {
    let max = 0;
    for (let i = 0; i < values.length; i++) {
      if (values[i] > max) {
        max = values[i];
      }
    }
    return max;
  }

  /**
   * Lookup table lifting the mid-tones of the stored colour bytes.
   *
   * The stored values are honest band energies scaled against the loudest
   * part of the recording, and on a mix with a lot of headroom that leaves
   * the typical point sitting low — measured at a mean of 79 of 255 on an
   * eight-hour set, which draws as murky brown rather than anything you
   * would recognise as a DJ waveform. A gamma curve lifts those mid-tones
   * while preserving both ordering and the ratios between bands, so the
   * hue is untouched and only the brightness changes.
   *
   * Deliberately applied here rather than baked into the stored data: the
   * decode that produces it takes minutes, and this is exactly the kind of
   * value worth being able to retune without paying that again.
   *
   * @return {Uint8Array}
   */
  function colorGammaTable() {
    const table = new Uint8Array(256);
    for (let i = 0; i < 256; i++) {
      table[i] = Math.round(255 * Math.pow(i / 255, 0.6));
    }
    return table;
  }

  /**
   * Builds a wavesurfer renderFunction that draws the waveform in colour.
   *
   * Each vertical slice is coloured by how the energy at that moment splits
   * across low, mid and high — the convention DJ software uses, and the
   * reason it is worth doing: amplitude alone cannot tell a kick from a
   * cymbal, but colour can.
   *
   * The awkward part is knowing *where* in the recording a given canvas is.
   * wavesurfer splits a long waveform across several canvases and hands
   * this function only the slice of channel data for one of them. It does,
   * however, position each canvas before calling us, so the canvas's own
   * `style.left` and `style.width` say which horizontal span of the whole
   * waveform it covers — enough to map every pixel back to a colour index
   * without wavesurfer telling us directly.
   *
   * @param {{bytes: Uint8Array, points: number}} colors
   * @param {number} maxPeak
   *   The largest value in the whole series. Supplying a custom
   *   renderFunction bypasses wavesurfer's own `normalize` handling, so the
   *   scaling has to happen here — and it has to come from the entire
   *   series, not from the slice this canvas received. A long waveform is
   *   split across several canvases, and normalising each against its own
   *   contents would make a quiet stretch as tall as a loud one, with a
   *   visible step at every canvas boundary.
   * @return {function(Array<Float32Array|number[]>, CanvasRenderingContext2D): void}
   */
  function makeColorRenderer(colors, maxPeak) {
    const gamma = colorGammaTable();
    const scale = maxPeak > 0 ? 1 / maxPeak : 1;

    return (channelData, ctx) => {
      const canvas = ctx.canvas;
      const samples = channelData[0];
      if (!samples || !samples.length) {
        return;
      }

      const left = parseFloat(canvas.style.left) || 0;
      const cssWidth = parseFloat(canvas.style.width) || canvas.width;
      // The wrapper spans the whole waveform, so it is the only thing that
      // knows the total width this canvas is a slice of.
      const totalWidth = canvas.parentElement?.scrollWidth || cssWidth;
      const deviceWidth = canvas.width;
      const height = canvas.height;
      const middle = height / 2;

      ctx.clearRect(0, 0, deviceWidth, height);

      for (let x = 0; x < deviceWidth; x++) {
        const fraction = x / deviceWidth;

        // Amplitude comes from this canvas's own slice of the peaks, so it
        // stays in step with the uncoloured rendering.
        const sample = Math.abs(samples[Math.min(samples.length - 1, Math.floor(fraction * samples.length))] || 0);

        // Colour comes from the full-length series, so it needs the
        // absolute position rather than the position within this slice.
        const absolute = (left + fraction * cssWidth) / totalWidth;
        const point = Math.min(colors.points - 1, Math.max(0, Math.floor(absolute * colors.points)));
        const offset = point * 3;

        ctx.fillStyle = `rgb(${gamma[colors.bytes[offset]]}, ${gamma[colors.bytes[offset + 1]]}, ${gamma[colors.bytes[offset + 2]]})`;

        const barHeight = Math.max(1, Math.min(1, sample * scale) * height);
        ctx.fillRect(x, middle - barHeight / 2, 1, barHeight);
      }
    };
  }

  /**
   * @param {string} url
   * @return {Promise<{peaks: number[][], duration: number, color: string|undefined, colorPoints: number|undefined}|null>}
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

  /**
   * Updates the "current / total" transport time readout next to a player.
   *
   * @param {HTMLElement} timeEl
   * @param {number} currentSeconds
   * @param {number} durationSeconds
   */
  function updateTransportTime(timeEl, currentSeconds, durationSeconds) {
    timeEl.textContent = `${formatTime(currentSeconds)} / ${formatTime(durationSeconds)}`;
  }

  /**
   * Formats seconds as m:ss.t, matching _peanut_gallery_format_timestamp().
   *
   * The transport readout carries tenths too, so the time someone reads
   * while positioning matches the precision a comment is pinned at.
   *
   * @param {number} seconds
   * @return {string}
   */
  function formatTime(seconds) {
    const tenths = Math.max(0, Math.round((seconds || 0) * 10) / 10);
    const hours = Math.floor(tenths / 3600);
    const minutes = Math.floor((tenths - hours * 3600) / 60);
    const remaining = (tenths - hours * 3600 - minutes * 60).toFixed(1).padStart(4, '0');

    // Hours only once there are hours — otherwise an eight-hour mix reads
    // as "509:00.0", which is minutes and unreadable.
    return hours > 0
      ? `${hours}:${String(minutes).padStart(2, '0')}:${remaining}`
      : `${minutes}:${remaining}`;
  }
})(Drupal, drupalSettings, once);
