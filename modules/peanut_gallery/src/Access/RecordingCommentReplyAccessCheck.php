<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Layers the anonymous-comments override onto core's comment.reply route.
 *
 * The module's own hook_comment_create_access() alone is not sufficient:
 * \Drupal\comment\Controller\CommentController::replyFormAccess() — the
 * access callback that actually gates the route real visitors submit
 * comments through — builds its own bespoke AccessResult and never calls
 * the comment access handler's createAccess(), so that hook is simply
 * never consulted for this route. This checker is registered as an
 * additional route requirement (see RouteSubscriber) specifically to
 * close that gap; it only ever adds a restriction, never a grant — host
 * entity view access and the base 'post comments' permission are already
 * correctly enforced by core's own requirement on this route.
 *
 * IMPORTANT: unlike hook_ENTITY_TYPE_access(), where neutral() means
 * "abstain, don't affect the outcome", route-level access checks are
 * combined by \Drupal\Core\Access\AccessManager::check() starting from
 * AccessResult::allowed() and AND-ing in every applicable checker's
 * result. AccessResult::allowed()->andIf(AccessResult::neutral()) yields
 * neutral(), and neutral()->isAllowed() is FALSE — so returning neutral()
 * here when this checker has nothing to restrict would deny the route
 * outright. The "I'm not restricting this" result must be
 * AccessResult::allowed(), not neutral().
 */
class RecordingCommentReplyAccessCheck implements AccessInterface {

  /**
   * Checks access for the comment.reply route.
   */
  public function access(AccountInterface $account, ?EntityInterface $entity = NULL): AccessResultInterface {
    if (!$account->isAuthenticated() && $entity instanceof NodeInterface && $entity->bundle() === 'recording') {
      if (!$entity->hasField('field_allow_anonymous_comments') || !(bool) $entity->get('field_allow_anonymous_comments')->value) {
        return AccessResult::forbidden('Anonymous comments are not allowed on this recording.')
          ->addCacheableDependency($entity);
      }
      return AccessResult::allowed()->addCacheableDependency($entity);
    }

    return AccessResult::allowed();
  }

}
