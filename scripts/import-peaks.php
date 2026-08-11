<?php

/**
 * @file
 * Imports a peaks JSON file into the peanut_gallery keyvalue store.
 *
 * The counterpart to gen-peaks.php: decoding an hours-long recording is
 * far too heavy for shared hosting, so the work happens on a development
 * machine and only the result travels.
 *
 * Usage: drush php:script import-peaks.php -- <peaks.json> <fid>
 */

$args = $extra ?? [];
$json = $args[0] ?? NULL;
$fid = $args[1] ?? NULL;

if (!$json || !$fid) {
  print "usage: <peaks.json> <fid>\n";
  return;
}
if (!is_file($json)) {
  printf("missing %s\n", $json);
  return;
}

$data = json_decode(file_get_contents($json), TRUE);
if (!is_array($data) || !isset($data['peaks'], $data['detail'], $data['duration'])) {
  print "not a peaks payload\n";
  return;
}

\Drupal::keyValue('peanut_gallery.peaks')->set('file:' . $fid, $data);

printf(
  "stored file:%s — %d overview, %d detail, %.1fs\n",
  $fid,
  count($data['peaks']),
  count($data['detail']),
  $data['duration'],
);
