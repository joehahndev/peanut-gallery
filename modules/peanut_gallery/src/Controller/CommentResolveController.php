<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Controller;

use Drupal\comment\CommentInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Toggles a recording comment's resolved state.
 *
 * POST-only, CSRF-header-protected (see the route). Resolution is a
 * working state on the feedback list, not content — so it is toggled from
 * the comment list where feedback is being worked through, never from the
 * comment form.
 */
class CommentResolveController extends ControllerBase {

  /**
   * Decides who may resolve a comment.
   *
   * The recording owner (working through their feedback) and the comment's
   * own author (retracting a note that no longer applies) may toggle;
   * everyone else may not, however much of the recording they can see.
   * Anonymous authors can never resolve: uid 0 would make every anonymous
   * visitor "the author" of every anonymous comment.
   *
   * @param \Drupal\comment\CommentInterface $comment
   *   The comment being toggled.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access decision.
   */
  public function access(CommentInterface $comment): AccessResultInterface {
    $account = $this->currentUser();

    // Never reveal more through this route than the comment itself would.
    $view_access = $comment->access('view', $account, TRUE);
    if (!$view_access->isAllowed()) {
      return $view_access;
    }

    return AccessResult::allowedIf(static::userMayResolve($comment, $account))
      ->cachePerUser()
      ->addCacheableDependency($comment)
      ->andIf($view_access);
  }

  /**
   * The resolve business rule, shared with the comment preprocess.
   *
   * Does NOT include the view-access check — route access and rendering
   * each already establish visibility before asking this.
   *
   * @param \Drupal\comment\CommentInterface $comment
   *   The comment in question.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account wanting to resolve it.
   *
   * @return bool
   *   TRUE when the account is the recording owner or the comment author.
   */
  public static function userMayResolve(CommentInterface $comment, AccountInterface $account): bool {
    if ($comment->bundle() !== 'recording_comment' || !$comment->hasField('field_resolved') || !$account->isAuthenticated()) {
      return FALSE;
    }
    $commented = $comment->getCommentedEntity();
    $is_recording_owner = $commented instanceof NodeInterface
      && (int) $commented->getOwnerId() === (int) $account->id();
    $is_author = (int) $comment->getOwnerId() === (int) $account->id();
    return $is_recording_owner || $is_author;
  }

  /**
   * Flips the resolved flag and reports the new state.
   *
   * @param \Drupal\comment\CommentInterface $comment
   *   The comment being toggled.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The new state: {"commentId": int, "resolved": bool}.
   */
  public function toggle(CommentInterface $comment): JsonResponse {
    $resolved = !(bool) $comment->get('field_resolved')->value;
    $comment->set('field_resolved', $resolved);
    $comment->save();

    return new JsonResponse([
      'commentId' => (int) $comment->id(),
      'resolved' => $resolved,
    ]);
  }

}
