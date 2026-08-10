/**
 * @file
 * Publishes the vertical scrollbar's width as --pg-scrollbar.
 *
 * The full-bleed player (waveform-player.css) needs to be exactly as wide
 * as the viewport's *content* area. `100vw` is the obvious candidate and is
 * wrong: it counts the vertical scrollbar's gutter too, so the element
 * overshoots by that much and leaves a sliver the page can be scrolled
 * horizontally into — invisible, but real enough that a trackpad swipe
 * nudges the whole layout sideways.
 *
 * CSS cannot measure the scrollbar, so this does, and the stylesheet
 * subtracts it. Until this runs the rule falls back to plain 100vw, which
 * `overflow-x: clip` on the root keeps harmless.
 */

((doc) => {
  const root = doc.documentElement;

  const publish = () => {
    const width = window.innerWidth - root.clientWidth;
    root.style.setProperty('--pg-scrollbar', `${Math.max(0, width)}px`);
  };

  publish();

  // The gutter appears and disappears as content grows past a screenful,
  // and changes width when the window is zoomed.
  window.addEventListener('resize', publish);
  if (typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(publish).observe(doc.body);
  }
})(document);
