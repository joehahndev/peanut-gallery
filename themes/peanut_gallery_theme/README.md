# Peanut Gallery theme

A modern, buildless companion theme for the [`peanut_gallery`](../../modules/peanut_gallery) module.

Generated from Drupal core's `starterkit_theme` and trimmed down. No CSS
framework, no JS/CSS build step — plain CSS using custom-property design
tokens (`css/tokens.css`), with a `prefers-color-scheme: dark` override for
the same token names.

## Templates

- `templates/content/node--recording.html.twig` / `node--recording--teaser.html.twig` —
  render the waveform player container the `peanut_gallery` module's JS
  attaches to.
- `templates/content/comment--recording-comment.html.twig` — renders each
  timestamped comment with `data-comment-id`/`data-timestamp` attributes and
  a plain-text `m:ss` badge (only becomes an interactive seek control once
  JS attaches).

Everything else was inherited from `starterkit_theme` as a working baseline
for standard Drupal admin UI elements (forms, tables, messages, etc.) and
can be trimmed further as needed.
