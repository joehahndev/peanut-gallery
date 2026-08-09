<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Access;

use Drupal\Core\Session\AccountInterface;
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

  /**
   * Whether shared listeners can see each other's comments.
   *
   * Defaults to TRUE (visible to all) if the field is somehow missing,
   * matching the field's own shipped default — callers never have to
   * remember that fallback themselves.
   */
  public function commentsVisibleToAllShared(NodeInterface $recording): bool {
    if (!$recording->hasField('field_comments_visible_to_shared') || $recording->get('field_comments_visible_to_shared')->isEmpty()) {
      return TRUE;
    }
    return (bool) $recording->get('field_comments_visible_to_shared')->value;
  }

  /**
   * Whether $viewer may see a comment authored by $commentAuthorUid.
   *
   * Applies only the "comments visible to shared listeners" business rule
   * — it assumes the caller has already confirmed $viewer can view the
   * recording at all, and that bypass-permission holders are let through
   * before ever calling this (that's a permission check, not a business
   * rule, so it belongs at the call site, not here).
   *
   * When isolated (the field is off), a non-owner viewer only sees
   * comments they authored themselves or that the recording's owner
   * authored.
   */
  public function canViewCommentUnderIsolation(NodeInterface $recording, AccountInterface $viewer, int $commentAuthorUid): bool {
    if ($this->commentsVisibleToAllShared($recording)) {
      return TRUE;
    }

    $ownerId = (int) $recording->getOwnerId();
    if ($ownerId === (int) $viewer->id()) {
      return TRUE;
    }

    return $commentAuthorUid === (int) $viewer->id() || $commentAuthorUid === $ownerId;
  }

}
