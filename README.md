# Peanut Gallery

Timestamped comments on very long audio recordings, for Drupal 10.3+ / 11.

Upload a recording, share it with a handful of specific people, and let them
leave comments pinned to an exact moment in it. Clicking a comment's
timestamp jumps playback there.

---

## Why it exists

The prompting use case is one long DJ mix — hours, not minutes — shared
privately with two friends who want to say "this transition at 1:42:07."
That one sentence sets nearly every design constraint in the project:

- **Recordings are hours long, and files are huge.** A 929 MB MP3 is a
  normal input, and lossless FLAC is larger still. Nothing may load the
  whole file into memory, and seeking must not mean downloading everything
  up to that point.
- **Sharing is with named people, not "anyone with the link."** Private
  means private, enforced at the node access layer, not hidden by a URL
  nobody guessed.
- **Comments belong to a moment, not a page.** A comment with no timestamp
  is close to useless on an eight-hour mix, so a timestamp is required.
- **The audience is a few friends, not the public.** Which is why invite
  links and passwordless sign-in exist: nobody should have to invent a
  password to say one thing about one mix.

If you only want to comment on a three-minute track, most of what follows
is overkill. It is all in service of the long-recording case.

## What it does

### Waveform player

- **wavesurfer.js v7**, vendored — no CDN, no build step.
- **Server-side peaks.** ffmpeg decodes the file once, streaming, and stores
  a normalized peak series. Without this, the browser would have to download
  and decode the entire recording before drawing anything. When ffmpeg isn't
  installed the module degrades to client-side decoding rather than failing.
- **Two synchronized views of one player.** A full-length overview plus a
  zoomed detail view showing roughly a minute either side of the playhead,
  scrolling with playback and held centred. Both wavesurfer instances share a
  single `<audio>` element, so there is one playback position and one buffer —
  the second view is a second *rendering*, not a second player.
- **Transport controls** sized for long recordings: play/pause plus −30s, −5s,
  +5s, +30s. On an eight-hour mix a single pixel of the overview is several
  seconds, so dragging the waveform can't land on a moment.
- **Elapsed / total time** with hours when the recording warrants it, and file
  metadata (name, size, format, upload date) alongside.

### Comments pinned to a moment

- Each comment carries a **timestamp field**, auto-filled from the current
  playhead position as you start typing, and **required** — enforced
  server-side so it can't fail silently when the field is visually hidden.
- The timestamp renders as a button; **clicking it seeks** the player there.
- **Newest first**, so a long-running conversation doesn't bury the latest.
- **Posting is AJAX.** Submitting a comment does not reload the page, because
  a reload would drop you back to 0:00 and lose the buffer you just spent
  thirty seconds filling.
- **Enter submits, Shift+Enter inserts a newline** — with IME composition
  respected, so this doesn't break for anyone typing via an input method
  editor.

### Access control

- **Per-recording visibility**: public, or private with an explicit
  shared-with list of users.
- Enforced through **node access grants**, which are an allow layer OR'd into
  Drupal's decision — the grants add view access, and never widen edit or
  delete beyond what permissions already say.
- **Private audio is served through `hook_file_download()`**, so the file
  itself is protected, not just the page linking to it. Denied requests
  return 404 rather than 403, so an unauthorized visitor cannot distinguish
  "private" from "no such recording." The peaks endpoints take the same
  posture.
- **Optional X-Sendfile** (`peanut_gallery_xsendfile` in settings.php) hands
  the byte-serving to the web server instead of PHP. Measured on shared
  hosting: 975 KB/s → 4.3 MB/s, which is the difference between a five-second
  seek and an instant one.
- **Per-recording anonymous comments** toggle.
- **Per-recording download link.** Off by default; when on, listeners get a
  link to the original file next to the player. It is an offer rather than a
  permission — the link points at the audio the player already streams, so it
  never widens who can reach a private recording. The module settings page
  carries the default for newly created recordings.
- **Per-recording comment isolation**: when enabled, each commenter sees only
  their own comments. This is filtered in the render path *and* carries a
  per-user cache context, because Drupal's default comment formatter renders
  via `viewMultiple()` and ignores entity access — the naive implementation
  leaks other people's comments straight into the HTML.

### Invitations and passwordless accounts

- **Single-use invite links** for one recording. Issuing one requires edit
  access to that recording plus the `issue peanut gallery invites` permission.
- Redeeming an invite **creates an account with no password** and
  **auto-shares** that recording with the new user.
- Unknown, expired, and already-redeemed codes are treated identically, so
  the accept page can't be used to probe which codes exist.
- Sign-in afterwards is by **one-time login link** over email.

### Notifications

- **Email** to one or more configured addresses on every new or edited
  comment.
- **Web push** to subscribed browsers, including mobile — a service worker,
  VAPID keys, and a queue worker that cron drains. Useful when the whole
  point is being told your friend just said something about minute 92.
- Both channels respect comment isolation: a notification never reveals a
  comment its recipient could not have seen on the site.

### Theme

A custom starterkit-based theme (`peanut_gallery_theme`) — JetBrains Mono,
self-hosted fonts, a full-bleed waveform that breaks out of the reading
column while its controls stay aligned to it, and labels above fields so
the comment form's inputs line up.

## Requirements

- Drupal 10.3+ or 11
- PHP 8.3+
- **ffmpeg and ffprobe** — optional but strongly recommended; without them
  peaks fall back to client-side decoding, which is impractical for long
  recordings
- **Cron** — required for push notification delivery
- **mod_xsendfile** — optional, for fast seeking on private recordings

## Layout

```
modules/peanut_gallery/       the module
themes/peanut_gallery_theme/  the theme
scripts/                      out-of-band peaks and colour generation
```

Further reading:

- **[ARCHITECTURE.md](ARCHITECTURE.md)** — how it is built, what each service
  does, and the measurements behind the decisions that look arbitrary. Start
  here before changing anything non-trivial.
- **[CLAUDE.md](CLAUDE.md)** — the short version: the load-bearing rules and the
  commands, for an agent picking this up cold.
- **[scripts/README.md](scripts/README.md)** — generating waveform data for long
  recordings without taking the host down.

The module ships the Recording content type, its fields, the
`recording_comment` comment type, and view/form displays as install config.
An optional Pathauto pattern gives recordings `/recordings/[node:title]`
URLs.

## Settings

`/admin/config/media/peanut-gallery` — notification recipients, default
anonymous-comment setting for new recordings, and push configuration.

Permissions:

| Permission | Grants |
| --- | --- |
| `bypass peanut gallery private recording access` | View, comment on, and download any recording regardless of visibility |
| `administer peanut gallery settings` | Change module settings |
| `issue peanut gallery invites` | Create invite links (also needs edit access to the recording) |
| `receive peanut gallery push notifications` | Subscribe a browser to push |

## Tests

```
vendor/bin/phpunit -c web/core/phpunit.xml web/modules/custom/peanut_gallery/tests
```

44 tests / 513 assertions, covering the access grants (including regression
tests that private-recording grants never widen edit or delete), comment
isolation in the rendered output, notification isolation, and the
required-timestamp behaviour.
