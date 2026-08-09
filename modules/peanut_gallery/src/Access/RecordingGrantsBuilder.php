<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the node_access grant records/queries for our two hooks.
 *
 * Backs hook_node_access_records() and hook_node_grants(). Three realms
 * are used:
 * - peanut_gallery_view (gid 0): the "public viewer" bucket — granted to
 *   every account (including anonymous) for public recordings, and for
 *   every node that isn't a recording at all.
 * - peanut_gallery_uid (gid = a user's own uid): granted per uid to the
 *   owner and everyone in field_shared_with, only on private recordings.
 * - peanut_gallery_bypass (gid 1): granted to accounts holding the
 *   "bypass peanut gallery private recording access" permission, on every
 *   node.
 *
 * IMPORTANT: implementing hook_node_access_records() at all makes the
 * {node_access} table authoritative for the *entire site*, not just
 * Recordings — Drupal drops its implicit "everyone can view" fallback row
 * the moment any module supplies access records. That's why every
 * non-recording node, and every public recording, must get an explicit
 * peanut_gallery_view/gid-0 grant below: omitting it would silently hide
 * the rest of the site's content. See
 * tests/src/Kernel/RecordingNodeAccessGrantsTest::testUnrelatedNodeTypeUnaffectedByOurGrants().
 */
class RecordingGrantsBuilder {

  public function __construct(
    protected readonly RecordingAccessChecker $accessChecker,
  ) {}

  /**
   * Implements the logic behind hook_node_access_records().
   *
   * @return array<int, array<string, mixed>>
   *   The access records to write for this node.
   */
  public function buildAccessRecords(NodeInterface $node): array {
    $records = [];

    // Bypass holders can always view/update/delete, on every node — not
    // just recordings — so a scoped "trusted" role can be granted this
    // without needing the much broader core "bypass content access
    // control" permission.
    $records[] = $this->record('peanut_gallery_bypass', 1);

    if ($node->bundle() !== 'recording' || $this->accessChecker->isPublic($node)) {
      $records[] = $this->record('peanut_gallery_view', 0);
      return $records;
    }

    foreach ($this->accessChecker->getPermittedUserIds($node) as $uid) {
      $records[] = $this->record('peanut_gallery_uid', $uid);
    }

    return $records;
  }

  /**
   * Implements the logic behind hook_node_grants().
   *
   * @return array<string, int[]>
   *   Realm => list of gids this account should be checked against.
   */
  public function buildGrants(AccountInterface $account, string $op): array {
    $grants = [
      'peanut_gallery_view' => [0],
    ];

    if ($account->isAuthenticated()) {
      $grants['peanut_gallery_uid'] = [(int) $account->id()];
    }

    if ($account->hasPermission('bypass peanut gallery private recording access')) {
      $grants['peanut_gallery_bypass'] = [1];
    }

    return $grants;
  }

  /**
   * Builds one full-access grant record for the given realm/gid.
   *
   * Both grant_update and grant_delete are always set to 1 alongside
   * grant_view: this module never uses the grants table to restrict
   * edit/delete, which stays governed purely by core's own "edit own/any
   * recording content" permissions. That keeps this module unable to ever
   * lock an editor out of, or into, unrelated content types.
   *
   * @return array<string, mixed>
   *   A single full-access grant record.
   */
  protected function record(string $realm, int $gid): array {
    return [
      'realm' => $realm,
      'gid' => $gid,
      'grant_view' => 1,
      'grant_update' => 1,
      'grant_delete' => 1,
      'priority' => 0,
    ];
  }

}
