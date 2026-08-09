<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Layers the anonymous-comments override onto core's comment.reply route.
 *
 * The classic procedural hook_route_alter() is no longer invoked in modern
 * Drupal — route altering happens via this event-subscriber base class
 * instead (RoutingEvents::ALTER).
 *
 * @see \Drupal\peanut_gallery\Access\RecordingCommentReplyAccessCheck
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('comment.reply');
    if ($route) {
      $route->setRequirement('_peanut_gallery_recording_comment_reply_access', 'TRUE');
    }
  }

}
