<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Access;

use Drupal\node\NodeInterface;

/**
 * Answers "who is permitted to view this recording?".
 *
 * The single source of truth that RecordingGrantsBuilder,
 * hook_file_download(), and the comment access hooks all ultimately defer
 * to.
 */
class RecordingAccessChecker {

  /**
   * Whether a recording is publicly visible.
   */
  public function isPublic(NodeInterface $recording): bool {
    return $recording->bundle() === 'recording'
      && $recording->hasField('field_visibility')
      && (bool) $recording->get('field_visibility')->value;
  }

  /**
   * The uids explicitly permitted to view a private recording.
   *
   * That's the owner plus everyone listed in field_shared_with.
   *
   * @return int[]
   *   Deduplicated user IDs.
   */
  public function getPermittedUserIds(NodeInterface $recording): array {
    $uids = [(int) $recording->getOwnerId()];

    if ($recording->hasField('field_shared_with')) {
      foreach ($recording->get('field_shared_with')->referencedEntities() as $user) {
        $uids[] = (int) $user->id();
      }
    }

    return array_values(array_unique(array_filter($uids)));
  }

}
