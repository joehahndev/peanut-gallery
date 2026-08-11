<?php

/**
 * @file
 * Generates peaks for a local audio file, out of band, and writes JSON.
 *
 * Run inside the DDEV demo site so the module's own PeaksGenerator does the
 * work — reimplementing the bucketing here would risk producing a series
 * that disagrees with what production expects.
 *
 * Usage: drush php:script gen-peaks.php -- <absolute-path> <output.json>
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

// An unsaved File entity is enough: the generator only asks for the URI,
// and nothing here should end up in the demo site's database.
$file = \Drupal::entityTypeManager()->getStorage('file')->create([
  'uri' => $path,
  'filename' => basename($path),
  'filemime' => 'audio/mpeg',
  'filesize' => filesize($path),
  'status' => 1,
]);

$start = microtime(TRUE);
$peaks = \Drupal::service('peanut_gallery.peaks_generator')->generate($file);

if (!$peaks) {
  print "generation failed\n";
  return;
}

file_put_contents($out, json_encode($peaks));

printf("generated in %.1fs\n", microtime(TRUE) - $start);
printf("overview points: %d\n", count($peaks['peaks']));
printf("detail points:   %d\n", count($peaks['detail']));
printf("duration:        %.1fs\n", $peaks['duration']);
printf("json size:       %.2f MB\n", filesize($out) / 1048576);
printf("peak memory:     %.1f MB\n", memory_get_peak_usage(TRUE) / 1048576);
