Petrichor project: 10 — Peanut Gallery

# Peanut Gallery

A Drupal 11 module + custom theme: upload hours-long audio recordings, play them
in a wavesurfer.js waveform, and let people comment pinned to an exact moment.
Recordings are public or private with a per-recording shared-with list.

**Read [ARCHITECTURE.md](ARCHITECTURE.md) before changing anything non-trivial.**
[README.md](README.md) is the what-and-why; ARCHITECTURE.md is the how, including
the measurements behind decisions that look arbitrary. There is no site in this
repo — it is a module + theme developed against a separate DDEV site.

## The constraint everything follows from

The recordings are **hours long** and the files are **gigabytes** (the working
test inputs are a 907 MB MP3 and a 2.75 GB FLAC of the same 8.5-hour set). Never
load a whole file into memory, never decode audio in a web request, and never
assume a page reload is cheap for a listener.

## Rules that are load-bearing

- **Never generate peaks or colour inside a web request.** Doing so once took
  production to load average 165 and made it unreachable. Use
  `drush peanut-gallery:peaks-backfill`, or `scripts/gen-*.php` for long audio.
- **Node grants are allow-only.** They OR into Drupal's decision; they never
  filter. Granting update/delete in a public realm hands it to everyone.
- **Deny with 404, not 403**, so private recordings can't be probed.
- **Colour and amplitude data are measured, not guessed.** The sample rates,
  band splits and reductions in `src/Peaks/` each fix a specific failure that is
  documented at the point of the code. Re-derive before "simplifying".
- **Always `--chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r` when rsyncing to production**, and
  one source per call. Without it every asset 404s while looking byte-perfect.
- **Credentials live only in `.private/`.** Never commit them, never paste them
  into code or chat — including into a terminal that echoes them back.
- **`uploads/` is gitignored.** Check `git status` before `git add -A`; multi-GB
  audio has been swept into commits twice.

## Commands

Run from `~/dev/peanut-gallery-demo` in WSL (the DDEV site symlinks this repo):

```bash
ddev exec 'php vendor/drush/drush/drush.php cr'
ddev exec 'vendor/bin/phpcs  --standard=/mnt/c/dev/peanut-gallery/phpcs.xml.dist  /mnt/c/dev/peanut-gallery/modules/peanut_gallery /mnt/c/dev/peanut-gallery/scripts'
ddev exec 'vendor/bin/phpstan analyse --configuration=/mnt/c/dev/peanut-gallery/phpstan.neon /mnt/c/dev/peanut-gallery/modules/peanut_gallery'
ddev exec 'cd web && ../vendor/bin/phpunit -c core/phpunit.xml modules/custom/peanut_gallery/tests'
```

Baseline **44 tests / 513 assertions**. Run phpcs and phpstan before claiming
Drupal code is done, and report their actual output.

On production, drush needs the 8.4 binary explicitly — the default CLI PHP fails
composer's platform check:

```bash
ssh peanut-gallery-prod
cd ~/peanutgallery.joehahn.dev && /usr/local/php84/bin/php vendor/drush/drush/drush.php cr
```

## Verifying UI changes

Use Playwright/chromium, not the Chrome extension: its screenshot tool resizes
the window, re-triggering wavesurfer's render and capturing half-painted
canvases. Confirm CSS actually applied with `getComputedStyle` — Drupal will
happily serve cached CSS and make a failed experiment look like a result.
