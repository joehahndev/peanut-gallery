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

    // Bypass holders can always *view* any node — not just recordings — so
    // a scoped "trusted" role can be granted this without needing the much
    // broader core "bypass content access control" permission. It grants no
    // edit or delete rights; see record() for why grants must never carry
    // those.
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
   * Builds one view-only grant record for the given realm/gid.
   *
   * Both grant_update and grant_delete MUST stay 0, and that is a security
   * requirement rather than a style choice. Node access grants are an
   * *allow* layer: when a row matches, NodeGrantDatabaseStorage::access()
   * returns an allowed result, which the entity access handler ORs into
   * the final decision. Grants never filter, and never AND with the node
   * permissions.
   *
   * So setting grant_update/grant_delete to 1 on the 'peanut_gallery_view'
   * realm — held by gid 0, meaning everyone, anonymous included — hands
   * every visitor edit and delete rights over every public recording, no
   * matter what the role permissions say. An earlier version of this file
   * did exactly that, on the mistaken assumption that core's "delete any
   * recording content" permission still had to agree.
   *
   * With both at 0 the grants system stays neutral on update/delete, and
   * those operations are decided purely by core's node permissions through
   * node's own hook_node_access() — the behaviour this module always
   * intended and documented.
   *
   * @return array<string, mixed>
   *   A single view-only grant record.
   */
  protected function record(string $realm, int $gid): array {
    return [
      'realm' => $realm,
      'gid' => $gid,
      'grant_view' => 1,
      'grant_update' => 0,
      'grant_delete' => 0,
      'priority' => 0,
    ];
  }

}
