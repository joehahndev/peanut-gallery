# Out-of-band peaks generation

Decoding an hours-long recording is far too heavy for shared hosting. A
single 8.5-hour mix takes ~74 seconds of solid CPU; doing that on the
production host takes the site down. So the decode happens on a
development machine and only the result — about 1.7 MB of JSON — travels.

This pair of scripts is that workflow. Both run through `drush php:script`,
and `gen-peaks.php` deliberately calls the module's own `PeaksGenerator`
rather than reimplementing the bucketing, so the series it produces cannot
drift from what production expects.

## Generate (on a machine with ffmpeg and CPU to spare)

```
drush php:script scripts/gen-peaks.php -- /path/to/recording.mp3 /tmp/peaks.json
```

Prints point counts, duration, JSON size and peak memory so you can sanity
check the result before it goes anywhere.

## Import (on the target site)

Find the file ID first:

```
drush sqlq "SELECT fid, uri FROM file_managed WHERE uri LIKE '%your-file%'"
```

Then:

```
scp /tmp/peaks.json prod:~/
drush php:script scripts/import-peaks.php -- ~/peaks.json <fid>
```

## When you can skip all this

For ordinary short recordings, `drush peanut-gallery:peaks-backfill` on the
target host is fine and simpler. The rule of thumb is duration, not file
size: anything over roughly ten minutes on shared hosting deserves the
out-of-band route.

Peaks are never generated inside a web request. See the comment in
`PeaksController::respond()` for what happened the one time they were.
