# Out-of-band waveform analysis

Decoding an hours-long recording is far too heavy for shared hosting. The
8.5-hour reference mix takes ~74 seconds of solid CPU for amplitude peaks
and ~156 seconds for colour; doing either on the production host takes the
site down. So the decode happens on a development machine and only the
result travels — about 1.7 MB of JSON for peaks, 1.2 MB for colour.

Two independent pairs of scripts, because the two data sets are stored
separately and either can exist without the other:

| | generate | import | keyvalue collection |
|---|---|---|---|
| Amplitude peaks | `gen-peaks.php` | `import-peaks.php` | `peanut_gallery.peaks` |
| Serato-style colour | `gen-color.php` | `import-color.php` | `peanut_gallery.peaks_color` |

Peaks are what the waveform needs to draw at all. Colour is optional — a
recording without it draws in a single colour exactly as before.

All four run through `drush php:script`, and the generators deliberately
call the module's own `PeaksGenerator` / `ColorPeaksGenerator` rather than
reimplementing the bucketing, so what they produce cannot drift from what
the site expects.

## Generate (on a machine with ffmpeg and CPU to spare)

```
drush php:script scripts/gen-peaks.php -- /path/to/recording.flac /tmp/peaks.json
drush php:script scripts/gen-color.php -- /path/to/recording.flac /tmp/color.json
```

Both print point counts, duration, payload size and peak memory. `gen-color.php`
additionally prints **mean chroma** — average distance from grey, 0–255 — and
per-band percentiles. That number is the whole point of the diagnostic: it
predicts whether the waveform will look like Serato or like a smudge far better
than reading the code does, and it caught three separate wrong implementations.
Healthy values on real music are roughly 60 (overview) and 90+ (detail). A
chroma near zero means the bands are moving together and the colour carries no
information.

## Import (on the target site)

Find the file ID first:

```
drush sqlq "SELECT fid, uri FROM file_managed WHERE uri LIKE '%your-file%'"
```

Then:

```
scp /tmp/peaks.json /tmp/color.json prod:~/
drush php:script scripts/import-peaks.php -- ~/peaks.json <fid>
drush php:script scripts/import-color.php -- ~/color.json <fid>
```

Importing colour never touches the peaks payload, so it cannot disturb a
waveform that is already working.

## When you can skip all this

For ordinary short recordings, `drush peanut-gallery:peaks-backfill` on the
target host is fine and simpler. The rule of thumb is duration, not file
size: anything over roughly ten minutes on shared hosting deserves the
out-of-band route. (There is no backfill command for colour — it is heavier
still, so it is always out of band.)

Neither series is ever generated inside a web request. See the comment in
`PeaksController::respond()` for what happened the one time peaks were.
