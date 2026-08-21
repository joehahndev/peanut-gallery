# Architecture

Orientation for someone — human or agent — picking this up cold. [README.md](README.md)
covers *what it does and why*; this covers *how it is built and what will bite you*.

Read the constraint first, because almost every decision below follows from it:
**the recordings are hours long and the files are gigabytes.** A 907 MB MP3 and a
2.75 GB FLAC of the same 8.5-hour DJ set are the working test inputs. Nothing may
load a whole file into memory, nothing may decode audio during a web request, and
seeking must not mean downloading everything up to that point.

---

## Repository layout

```
modules/peanut_gallery/       the module — all behaviour
themes/peanut_gallery_theme/  starterkit-based theme — all presentation
scripts/                      out-of-band peaks/colour generation (see scripts/README.md)
uploads/                      local source audio; gitignored, never commit
.private/                     production credentials; gitignored, never read aloud
```

There is no site in this repo. It is a module + theme developed against a separate
Drupal site (below).

---

## Environments

**Local dev** — a DDEV Drupal 11 site at `~/dev/peanut-gallery-demo` (WSL), which
symlinks `web/modules/custom/peanut_gallery` → this repo. It has its own
`DEPLOYMENT.md`. PHP 8.3 in-container; `peanut_gallery_demo` there provides seed
content.

```bash
# All commands run from ~/dev/peanut-gallery-demo inside WSL.
ddev exec 'php vendor/drush/drush/drush.php cr'
ddev exec 'vendor/bin/phpcs  --standard=/mnt/c/dev/peanut-gallery/phpcs.xml.dist  /mnt/c/dev/peanut-gallery/modules/peanut_gallery /mnt/c/dev/peanut-gallery/scripts'
ddev exec 'vendor/bin/phpstan analyse --configuration=/mnt/c/dev/peanut-gallery/phpstan.neon /mnt/c/dev/peanut-gallery/modules/peanut_gallery'
ddev exec 'cd web && ../vendor/bin/phpunit -c core/phpunit.xml modules/custom/peanut_gallery/tests'
```

Baseline: **54 tests / 621 assertions**, 2 deprecations (both from contrib
requirements hooks, not this module). `core/phpunit.xml` (not
`phpunit.xml.dist`) — only the former has `SIMPLETEST_DB` set.

**Production** — `peanutgallery.joehahn.dev`, DreamHost shared hosting. SSH alias
`peanut-gallery-prod` (key auth already configured). Web PHP is 8.4; the default
CLI `php` is 8.2/8.3 and **fails composer's platform check**, so drush must be run
as `/usr/local/php84/bin/php vendor/drush/drush/drush.php`. The `drush` wrapper
script is not on `PATH`.

### Deploying

```bash
rsync -avz --delete --chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r \
  --exclude='.git' --exclude='tests' \
  modules/peanut_gallery/ peanut-gallery-prod:~/peanutgallery.joehahn.dev/web/modules/custom/peanut_gallery/
```

Two rules, both learned the hard way:

- **`--chmod` is mandatory.** The source is a Windows drive mounted in WSL, whose
  permission bits are meaningless. Without it, directories land as `drwxr-----`,
  Apache loses traversal, and every asset 404s — while the files themselves are
  byte-perfect, so it looks like a caching problem. Tell-tale:
  `X-Drupal-Dynamic-Cache: UNCACHEABLE (404)` on a static asset.
- **One source per rsync call.** Multiple trailing-slash sources into one
  destination merge their *contents* instead of creating subdirectories.

Then `drush cr` on the server. Verify by md5-comparing the served files against
`git show master:<path>` — that check has caught real mistakes more than once.

---

## The module

### Access control

Everything routes through two services so the node page, the audio download, and
comment visibility cannot drift apart.

- `Access/RecordingAccessChecker` — the single "may this account view this
  recording" computation.
- `Access/RecordingGrantsBuilder` — turns that into node access grants, via
  `hook_node_access_records()` / `hook_node_grants()`.
- `Access/RecordingFileLocator` — maps a file back to its recording, for
  `hook_file_download()`.

**Node grants are an allow-only layer OR'd into Drupal's decision — never a
filter.** A grant that sets `grant_update`/`grant_delete` hands those rights to
everyone in that realm. This module's grants are view-only (`grant_view => 1`,
update/delete `0`); there are regression tests pinning that. This was a real
privilege-escalation bug once.

**The peaks endpoints deny with 404, not 403**, so a private recording's existence
can't be inferred from them — `PeaksController` throws `NotFoundHttpException` for
both "no such recording" and "not allowed". The node page and the private file
route are ordinary Drupal denials and return **403**: `hook_file_download()`
returns `-1`, which core renders as forbidden. Tests pin all three, so don't
"fix" one to match the others without deciding which posture you actually want
site-wide.

Per-recording fields drive it all: `field_visibility` (public/private),
`field_shared_with` (user list), `field_allow_anonymous_comments`,
`field_comments_visible_to_shared` (comment isolation), `field_allow_download`
(offer the original file).

**`field_allow_download` is presentation, not access.** The link it controls
points at the same URL the player already streams from, gated by the same
`hook_file_download()`; anyone who can listen can already save what they are
hearing. The toggle decides whether the site *offers* the file, not who may
reach it. Do not reach for it as a security control — a test pins that a
downloadable private recording still refuses strangers.

Two of the site-wide settings in `peanut_gallery.settings` are *defaults for
new recordings only* — `default_allow_anonymous_comments` and
`default_allow_downloads`. They seed the widget on the node form when the node
is new and are never consulted again, so changing a default cannot silently
re-open (or close) recordings that already exist.

**Comment isolation is enforced twice on purpose.** Drupal's default comment
formatter renders through `viewMultiple()` and *ignores entity access*, so
`hook_comment_access()` alone leaks other people's comments into the HTML.
`peanut_gallery_preprocess_field__comment()` filters the render array, and
`peanut_gallery_node_view_alter()` adds a `user` cache context so one visitor's
render can't be served to another.

### Peaks and colour

| | class | keyvalue collection | resolution |
|---|---|---|---|
| Amplitude | `Peaks/PeaksGenerator` | `peanut_gallery.peaks` | 800 overview buckets + 10 pts/sec detail (cap 400k) |
| Colour | `Peaks/ColorPeaksGenerator` | `peanut_gallery.peaks_color` | same, packed as base64 RGB bytes |

Both stream ffmpeg output through `popen()` in constant memory. Measured on the
2.75 GB FLAC: peaks 22s / 32 MB, colour 156s / 92 MB.

Non-obvious properties, each of which was arrived at by measurement and will
regress if "simplified":

- **Peaks decode at a constant 8 kHz.** Lowering the sample rate for long files
  does not merely sample less often — the resampler low-passes at the new Nyquist
  first, returning a flat line. A two-hour test came back with 1 of 800 buckets
  non-silent. Constant memory comes from streaming, not from decoding less.
- **Colour uses RMS energy, not peak amplitude.** Peak-per-bucket saturates on
  dense music (measured 254.7/252.8 of 255 → one flat colour).
- **Colour bands come from `acrossover` at 8th order** (48 dB/oct), not
  `lowpass`/`highpass`, which default to 2 poles and leak so badly that all three
  bands move together and the result is grey.
- **The overview averages; the detail series doesn't.** Max-of-RMS over a
  nine-second bucket pins it at 254 (chroma 2.7); the mean holds chroma 32.
- **Colour is packed base64** (one byte per band per point) — 1.2 MB against
  5.5 MB as JSON floats for an eight-hour set.

`Controller/PeaksController` serves `/peanut-gallery/peaks/{node}` (overview) and
`…/detail` (dense). The overview deliberately omits the dense series.

> **Never generate peaks inside a web request.** A regeneration fallback in the
> detail endpoint once ran on *every* request, spawning a full ffmpeg decode of a
> 907 MB file each time; it took the shared host to load average 165 and made it
> unreachable over HTTP *and* SSH. Killing the parent left orphaned ffmpeg
> children, and the next page load spawned a fresh one — the code had to change
> before it stopped. A missing series now returns empty and the view hides itself.
> Backfill with `drush peanut-gallery:peaks-backfill` (`Drush/Commands/PeaksCommands`)
> or, for anything over ~10 minutes of audio, the out-of-band scripts.

### Front end

`js/waveform-player.js` — wavesurfer.js v7, vendored (no CDN, no build step).

- **Two views, one player.** The overview and the zoomed detail view are two
  wavesurfer instances handed the *same* `<audio>` element via the `media` option.
  One playback position, one buffer, nothing to sync. The detail view uses
  `minPxPerSec` + `fillParent: false` + `autoScroll` + `autoCenter` for a ~60s
  window with the playhead centred.
- **Measure after revealing.** The detail container ships `hidden`; a hidden
  element reports `clientWidth` 0, and a zoom of 0 px/sec silently renders the
  whole recording at overview scale.
- **`renderFunction` draws the colour.** It receives each canvas chunk's context
  with `style.left` already set, which is how a chunk maps its pixels back to the
  full-length colour series. Supplying it **bypasses wavesurfer's own `normalize`**,
  so scaling is done here — against the whole series, never per chunk, or quiet
  stretches draw as tall as loud ones with a step at every canvas boundary.
- **`attachProgressVeil()` marks played audio.** wavesurfer does not dim the
  played region, it *removes* it: `renderProgress()` sets a `clip-path` on the
  wave layer and shows a `source-in`-recoloured canvas clone instead. No
  `progressColor` value can mean "same colours, dimmed" through that. The veil
  cancels the clip (`!important` — `renderProgress` rewrites the inline style on
  every `timeupdate`), hides the clone, and paints the `.progress` element as a
  translucent background over the real waveform.
- `Drupal.peanutGallery.getPlayheadSeconds()` is published for the comment form;
  `pg:seek-request`, `pg:waveform-seek` and `pg:comment-posted` are the events
  that connect the player, the comment list and the form.

`js/comment-form.js` — timestamp field, Enter-to-post, AJAX posting.

- **Drupal binds AJAX submits to `mousedown`, not `click`.** A synthesised click
  posts the form the old way and reloads the page, losing the listener's place.
  This applies to tests too.
- Timestamps are **required, server-side only** (`_peanut_gallery_validate_timestamp_present()`).
  Marking the field `#required` while it is visually hidden produces a silent,
  unexplained submit failure.
- The timestamp input is `step="0.001"`; raw wavesurfer times have more float
  precision than that and fail the HTML5 step constraint invisibly.

### Notifications, invites, push

- `_peanut_gallery_announce_comment()` fans out to email
  (`peanut_gallery_mail()`) and the push queue. Both channels respect comment
  isolation — a notification must never reveal a comment its recipient could not
  see on the site.
- `Invite/InviteStore` + `Form/InviteCreateForm` + `Form/InviteAcceptForm` —
  single-use codes that create a passwordless account and auto-share one
  recording. Unknown, expired and redeemed codes are treated identically so the
  page can't be used to probe. Sign-in afterwards uses
  `OneTimeAuthentication::generateOneTimeLoginUrl()` (`user_pass_reset_url()` is
  deprecated as of 11.4; there is a fallback because the module supports 10.3+).
- `Push/*` + `Plugin/QueueWorker/PushNotificationWorker` + `Controller/PushController`
  — VAPID keys in state, subscriptions in `peanut_gallery_push_subscription`,
  delivery drained by cron. Subscribe/unsubscribe require a CSRF header token.
- `EventSubscriber/XSendfileSubscriber` — hands byte-serving of private audio to
  the web server. Off unless `Settings::get('peanut_gallery_xsendfile')`.
  Measured 975 KB/s → 4.3 MB/s. On DreamHost, `XSendFile On` is allowed in
  `.htaccess` but `XSendFilePath` is **not**, and the feature is a per-site
  checkbox in the panel.

### Custom DB tables

`peanut_gallery_push_subscription`, `peanut_gallery_invite` — both in
`peanut_gallery.install`, alongside `hook_update_N` 10001–10005.

---

## The theme

Starterkit-generated, so most templates are stock overrides. The ones that carry
real behaviour:

- `templates/content/node--recording.html.twig` — full-bleed `.pg-player`, the
  two waveform containers, the transport row (play/pause, ±5s, ±30s), file
  metadata.
- `templates/content/comment--recording-comment.html.twig` — the timestamp is a
  `<button data-pg-seek>`.
- `templates/field/field--comment.html.twig` — `[data-pg-comment-list]`, the AJAX
  replacement target.
- `css/tokens.css` — **the contract with the module's JS**, which reads
  `--pg-waveform-*` via `getComputedStyle()` rather than hardcoding colour. Change
  colours here, not in JS.

---

## Gotchas that have already cost a day

- **`DependencySerializationTrait` supports neither `private` nor `readonly`
  properties.** `FormBase` also already declares an untyped `$requestStack`;
  redeclaring it with a type is a fatal error.
- **Drupal rejects symlinks that escape the files directory** (traversal guard),
  so you cannot symlink large test audio into `private://` — copy it.
- **Verify CSS experiments with `getComputedStyle`.** Drupal served cached CSS
  through two diagnostic changes here, and both "results" looked identical to the
  previous value, nearly leading to the wrong conclusion.
- **Prefer Playwright/chromium for visual checks.** The Chrome-extension
  screenshot tool resizes the window, which re-triggers wavesurfer's render and
  captures half-painted canvases.
- **`git add -A` has swept multi-GB audio into commits twice.** `uploads/` is now
  gitignored. The 907 MB MP3 is still in history (`.git` ≈ 902 MB); removing it
  needs a rewrite, which is the owner's call.
- The site runs the **`minimal` install profile**, so there is no `administrator`
  role — that comes from `standard`. uid 1 bypasses everything.
