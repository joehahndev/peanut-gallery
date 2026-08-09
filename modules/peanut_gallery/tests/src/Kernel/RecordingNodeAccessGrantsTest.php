<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the node grants built by RecordingGrantsBuilder.
 *
 * @see \Drupal\peanut_gallery\Access\RecordingGrantsBuilder
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class RecordingNodeAccessGrantsTest extends KernelTestBase {

  use PeanutGalleryTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'file',
    'comment',
    'node',
    'peanut_gallery',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $this->registerPrivateStreamWrapper($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['filter', 'user', 'node', 'comment', 'peanut_gallery']);
    mkdir($this->siteDirectory . '/private', 0775);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');

    // Bare Kernel tests don't run an install profile, so neither role has
    // any of the permissions a profile would normally grant by default —
    // without 'access content', NodeAccessControlHandler::access() denies
    // before our grants logic is ever consulted, regardless of what our
    // own grants say.
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['access content']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * A public recording is viewable by anonymous.
   */
  public function testPublicRecordingViewableByAnonymous(): void {
    $node = $this->createRecordingNode(['field_visibility' => TRUE]);

    $this->assertTrue((bool) $node->access('view', new AnonymousUserSession()));
  }

  /**
   * Owner and shared users are allowed on a private recording; others aren't.
   */
  public function testPrivateRecordingAccess(): void {
    $owner = $this->createUser();
    $shared = $this->createUser();
    $stranger = $this->createUser();

    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
      'field_shared_with' => [$shared],
    ]);

    $this->assertTrue((bool) $node->access('view', $owner), 'Owner can view their own private recording.');
    $this->assertTrue((bool) $node->access('view', $shared), 'A user listed in field_shared_with can view.');
    $this->assertFalse((bool) $node->access('view', $stranger), 'An unrelated authenticated user is denied.');
    $this->assertFalse((bool) $node->access('view', new AnonymousUserSession()), 'Anonymous is denied.');
  }

  /**
   * The bypass permission grants access regardless of ownership/sharing.
   */
  public function testBypassPermissionGrantsAccess(): void {
    $owner = $this->createUser();
    $bypass_user = $this->createUser(['bypass peanut gallery private recording access']);

    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);

    $this->assertTrue((bool) $node->access('view', $bypass_user));
  }

  /**
   * Critical regression test: non-recording content stays visible.
   *
   * Implementing hook_node_access_records() at all makes {node_access}
   * authoritative for the *entire site*. If RecordingGrantsBuilder ever
   * stopped emitting the universal gid-0 grant for non-recording nodes,
   * plain content (articles, pages, ...) would silently disappear from
   * anonymous (and even most authenticated) view the moment this module is
   * installed. This exercises buildAccessRecords() via the same per-node
   * save path a real node_access rebuild uses.
   *
   * @see \Drupal\peanut_gallery\Access\RecordingGrantsBuilder
   */
  public function testUnrelatedNodeTypeUnaffectedByOurGrants(): void {
    $article = Node::create([
      'type' => 'article',
      'title' => 'Just an article',
      'status' => NodeInterface::PUBLISHED,
    ]);
    $article->save();

    $anonymous = new AnonymousUserSession();
    $this->assertTrue((bool) $article->access('view', $anonymous));

    // Re-saving recomputes and rewrites grants exactly as a full
    // node_access rebuild would for every existing node on the site after
    // this module is installed. The universal grant must survive it.
    $article->save();
    \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();
    $this->assertTrue((bool) $article->access('view', $anonymous));
  }

  /**
   * Grants are recomputed when field_shared_with changes on an existing node.
   */
  public function testGrantsUpdateWhenSharedWithChanges(): void {
    $owner = $this->createUser();
    $stranger = $this->createUser();

    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);

    $this->assertFalse((bool) $node->access('view', $stranger));

    $node->set('field_shared_with', [$stranger->id()]);
    $node->save();
    \Drupal::entityTypeManager()->getAccessControlHandler('node')->resetCache();

    $this->assertTrue((bool) $node->access('view', $stranger));
  }

}
