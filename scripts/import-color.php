<?php

/**
 * @file
 * Imports colour data into the peanut_gallery colour keyvalue store.
 *
 * Stored under its own collection, not merged into the peaks payload, so
 * importing colour can never disturb peaks that are already generated and
 * working.
 *
 * Usage: drush php:script import-color.php -- <color.json> <fid>
 */

$args = $extra ?? [];
$json = $args[0] ?? NULL;
$fid = $args[1] ?? NULL;

if (!$json || !$fid) {
  print "usage: <color.json> <fid>\n";
  return;
}
if (!is_file($json)) {
  printf("missing %s\n", $json);
  return;
}

$data = json_decode(file_get_contents($json), TRUE);
if (!is_array($data) || !isset($data['overview'], $data['detail'], $data['points'])) {
  print "not a colour payload\n";
  return;
}

\Drupal::keyValue('peanut_gallery.peaks_color')->set('file:' . $fid, $data);

printf(
  "stored colour for file:%s — %d points, %.2f MB detail, %.1fs\n",
  $fid,
  $data['points'],
  strlen($data['detail']) / 1048576,
  $data['duration'] ?? 0,
);
