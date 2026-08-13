<?php

/**
 * @file
 * Seeds one *real* multi-track recording, for the v1.1 review-loop pass.
 *
 * The ordinary seed (seed-dev.php) writes a 2KB placeholder, which is enough
 * to exercise access and forms but cannot be listened to — so karaoke sync,
 * pre-roll and review-mode auto-advance stay unverified. This script installs
 * a genuine ~22 minute mix instead, with comments pinned to the moments where
 * one track hands over to the next: those handovers are audible, so "did the
 * player land where the comment says" has an answer you can hear.
 *
 * Build the source mix first (any long real recording works; the container has
 * ffmpeg and the site root is /var/www/html):
 * @code
 *   ddev exec ffmpeg -f concat -safe 0 -i .tmp-audio/list.txt \
 *     -c:a libmp3lame -b:a 192k -ar 44100 -ac 2 .tmp-audio/dev-real-mix.mp3
 * @endcode
 *
 * Then:
 * @code
 *   ddev exec 'php vendor/drush/drush/drush.php php:script \
 *     /mnt/c/dev/mix-comments-saas/scripts/seed-real-audio.php -- [source.mp3]'
 * @endcode
 *
 * Peaks are NOT generated here. Decoding audio in a web request once took
 * production to load average 165; generate them out of band afterwards with
 * peanut-gallery/scripts/gen-peaks.php + import-peaks.php (see that README).
 */

declare(strict_types=1);

use Drupal\comment\Entity\Comment;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;

$source = $extra[0] ?? '/var/www/html/.tmp-audio/dev-real-mix.mp3';
if (!is_file($source)) {
  print 'Source audio not found: ' . $source . PHP_EOL;
  return;
}

// Run as the owner: private files are only visible to their owner, and the
// comment/node save paths resolve file access as the current user.
$account = \Drupal::entityTypeManager()->getStorage('user')->load(1);
\Drupal::currentUser()->setAccount($account);

$directory = 'private://recordings';
\Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
$file = \Drupal::service('file.repository')->writeData(
  (string) file_get_contents($source),
  'private://recordings/dev-real-mix.mp3',
  FileExists::Replace,
);
$file->setPermanent();
$file->save();

$title = 'Real audio review pass';
$existing = \Drupal::entityQuery('node')->accessCheck(FALSE)
  ->condition('type', 'recording')->condition('title', $title)->execute();

if ($existing) {
  $node = Node::load(reset($existing));
  $node->set('field_audio_file', ['target_id' => $file->id()]);
  $node->save();
}
else {
  $node = Node::create([
    'type' => 'recording',
    'title' => $title,
    'uid' => 1,
    'status' => 1,
    'field_visibility' => TRUE,
    'field_audio_file' => ['target_id' => $file->id()],
    'field_allow_anonymous_comments' => TRUE,
  ]);
  $node->save();
}

// Timestamps sit on the track handovers in the concatenated mix (plus one
// early, one mid-track and one near the end), so every jump has an audible
// landmark to check the player against.
$comments = [
  [8.5, 'Intro runs long — can we get to the first drop faster?'],
  [191.4, 'Handover 1: the transition here is abrupt, needs a blend.'],
  [300.0, 'Mid-track: bass is masking the kick through this section.'],
  [406.9, 'Handover 2: level jumps up, pull this back ~2dB.'],
  [580.4, 'Handover 3: nice, this one lands. Leave it.', TRUE],
  [779.4, 'Handover 4: hats are harsh on headphones right here.'],
  [1006.2, 'Handover 5: check phase — sounds narrow in mono.'],
  [1290.0, 'Outro: fade feels rushed, give it another 8 bars.'],
];

$has_resolved = \Drupal::service('entity_field.manager')
  ->getFieldDefinitions('comment', 'recording_comment');
$has_resolved = isset($has_resolved['field_resolved']);

$existing_comments = \Drupal::entityQuery('comment')->accessCheck(FALSE)
  ->condition('entity_id', $node->id())
  ->condition('entity_type', 'node')
  ->count()->execute();

if (!$existing_comments) {
  foreach ($comments as [$timestamp, $body, $resolved]) {
    $values = [
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_comment',
      'comment_type' => 'recording_comment',
      'uid' => 1,
      'comment_body' => ['value' => $body, 'format' => 'plain_text'],
      'field_timestamp' => $timestamp,
      'status' => 1,
    ];
    if ($has_resolved) {
      $values['field_resolved'] = !empty($resolved);
    }
    Comment::create($values)->save();
  }
}

print 'node ' . $node->id() . '  fid ' . $file->id() . PHP_EOL;
print 'url  /node/' . $node->id() . PHP_EOL;
print 'comments ' . ($existing_comments ?: count($comments)) . PHP_EOL;
print 'next: generate peaks out of band --' . PHP_EOL;
print '  drush php:script /mnt/c/dev/peanut-gallery/scripts/gen-peaks.php -- ' . $source . ' /tmp/peaks.json' . PHP_EOL;
print '  drush php:script /mnt/c/dev/peanut-gallery/scripts/import-peaks.php -- /tmp/peaks.json ' . $file->id() . PHP_EOL;
