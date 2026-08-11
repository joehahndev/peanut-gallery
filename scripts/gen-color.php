<?php

/**
 * @file
 * Generates Serato-style colour data for a local audio file, out of band.
 *
 * The colour pass decodes at 22.05 kHz rather than the mono pass's 8 kHz,
 * so it is roughly three times the work — firmly a workstation job. See
 * scripts/README.md for the whole workflow.
 *
 * Usage: drush php:script gen-color.php -- <absolute-path> <output.json>
 */

$args = $extra ?? [];
$path = $args[0] ?? NULL;
$out = $args[1] ?? NULL;

if (!$path || !$out) {
  print "usage: <absolute-path> <output.json>\n";
  return;
}
if (!is_file($path)) {
  printf("missing %s\n", $path);
  return;
}

$file = \Drupal::entityTypeManager()->getStorage('file')->create([
  'uri' => $path,
  'filename' => basename($path),
  'filemime' => 'audio/mpeg',
  'filesize' => filesize($path),
  'status' => 1,
]);

$start = microtime(TRUE);
$color = \Drupal::service('peanut_gallery.color_peaks_generator')->generate($file);

if (!$color) {
  print "generation failed\n";
  return;
}

file_put_contents($out, json_encode($color));

printf("generated in %.1fs\n", microtime(TRUE) - $start);
printf("points:      %d\n", $color['points']);
printf("duration:    %.1fs\n", $color['duration']);
printf("json size:   %.2f MB\n", filesize($out) / 1048576);
printf("peak memory: %.1f MB\n", memory_get_peak_usage(TRUE) / 1048576);

// A quick legibility check. If the three bands come back with nearly
// identical averages the split is not doing anything and the waveform will
// render as a flat grey-white; if one is zero, a filter or the channel
// interleave is wrong.
$bytes = base64_decode($color['overview']);
$sums = [0, 0, 0];
$n = intdiv(strlen($bytes), 3);
for ($i = 0; $i < $n; $i++) {
  for ($band = 0; $band < 3; $band++) {
    $sums[$band] += ord($bytes[$i * 3 + $band]);
  }
}
printf(
  "mean overview low/mid/high: %.1f / %.1f / %.1f\n",
  $sums[0] / max(1, $n),
  $sums[1] / max(1, $n),
  $sums[2] / max(1, $n),
);

// Chroma: how far each point is from grey, averaged. This is the number
// that actually predicts whether the waveform looks like Serato or like a
// smudge — a series can have a fine spread per band and still render grey
// if the three bands rise and fall together.
foreach (['overview', 'detail'] as $series) {
  $raw = base64_decode($color[$series]);
  $count = intdiv(strlen($raw), 3);
  $chroma = 0;
  $level = 0;
  for ($i = 0; $i < $count; $i++) {
    $r = ord($raw[$i * 3]);
    $g = ord($raw[$i * 3 + 1]);
    $b = ord($raw[$i * 3 + 2]);
    $chroma += max($r, $g, $b) - min($r, $g, $b);
    $level += ($r + $g + $b) / 3;
  }
  printf(
    "%-8s mean chroma %.1f (0=grey, 255=pure), mean level %.1f\n",
    $series,
    $chroma / max(1, $count),
    $level / max(1, $count),
  );
}

// Spread check: colour is only informative if the values actually vary.
// A near-constant series means the split or the scaling is wrong, and it
// is far cheaper to see that here than to squint at a rendered waveform.
$detail = base64_decode($color['detail']);
$n = intdiv(strlen($detail), 3);
for ($band = 0; $band < 3; $band++) {
  $values = [];
  for ($i = 0; $i < $n; $i++) {
    $values[] = ord($detail[$i * 3 + $band]);
  }
  sort($values);
  printf(
    "band %d detail p10/p50/p90: %d / %d / %d\n",
    $band,
    $values[(int) ($n * 0.1)],
    $values[(int) ($n * 0.5)],
    $values[(int) ($n * 0.9)],
  );
}
