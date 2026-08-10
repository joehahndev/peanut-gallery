<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

/**
 * Lists every published recording the current user is allowed to view.
 *
 * Intended to work as a site front page: one visitor sees only the public
 * recordings, while a logged-in friend additionally sees the private ones
 * shared with them, from the same URL.
 *
 * Deliberately a plain controller rather than a View: the listing has to
 * be access-correct above all else, and a hand-written entity query with
 * accessCheck(TRUE) is far easier to unit-test and reason about than a
 * Views config entity that a site builder could later edit into an
 * insecure state. It also means this feature ships zero config entities,
 * so deploying it to an existing site can never disturb that site's
 * block layout or other configuration.
 */
final class RecordingListController extends ControllerBase {

  /**
   * How many recordings to show before paging.
   */
  private const ITEMS_PER_PAGE = 12;

  /**
   * Builds the access-filtered recording listing.
   *
   * @return array<string, mixed>
   *   A render array of recording teasers plus a pager.
   */
  public function page(): array {
    $storage = $this->entityTypeManager()->getStorage('node');

    $nids = $storage->getQuery()
      // The whole point: let the node grants system decide what this user
      // may see, rather than re-deriving visibility rules here and risking
      // them drifting from RecordingGrantsBuilder's.
      ->accessCheck(TRUE)
      ->condition('type', 'recording')
      // Node access grants govern *view* access but say nothing about
      // publication status — core's query alter joins {node_access}
      // without adding a status condition, and this module's grants set
      // grant_view on a node's records regardless of whether it is
      // published. Without this condition an owner's unpublished draft
      // would surface in everyone's listing who holds a matching grant.
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC')
      ->pager(self::ITEMS_PER_PAGE)
      ->execute();

    $build = [
      '#cache' => [
        'contexts' => [
          // Two different users can get different rows out of the same
          // query, so the rendered page must be cached per grant set, not
          // globally. Without this, whoever warms the cache first would
          // have their listing served to everyone else.
          'user.node_grants:view',
          'url.query_args.pagers:0',
        ],
        'tags' => ['node_list:recording'],
      ],
    ];

    if (!$nids) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['pg-recording-list__empty']],
        '#value' => $this->t('No recordings to listen to yet.'),
      ];
      return $build;
    }

    $build['recordings'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['pg-recording-list']],
      'items' => $this->entityTypeManager()
        ->getViewBuilder('node')
        ->viewMultiple($storage->loadMultiple($nids), 'teaser'),
    ];
    $build['pager'] = ['#type' => 'pager'];

    return $build;
  }

}
