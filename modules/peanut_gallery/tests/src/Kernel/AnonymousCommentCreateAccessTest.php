<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests hook_comment_create_access() enforcement of the anonymous toggle.
 *
 * @see peanut_gallery_comment_create_access()
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class AnonymousCommentCreateAccessTest extends KernelTestBase {

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

    // Grant the anonymous role the core permissions this feature composes
    // with, so any denial observed below is attributable to
    // peanut_gallery's own hook, never to a missing site-wide permission.
    /** @var \Drupal\user\RoleInterface $anonymous_role */
    $anonymous_role = Role::load(RoleInterface::ANONYMOUS_ID);
    $anonymous_role->grantPermission('access content');
    $anonymous_role->grantPermission('access comments');
    $anonymous_role->grantPermission('post comments');
    $anonymous_role->save();
  }

  /**
   * Checks comment create access for the given account on $node.
   */
  protected function checkCreateAccess(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    $context = ['commented_entity' => $node, 'field_name' => 'field_comment'];
    return \Drupal::entityTypeManager()
      ->getAccessControlHandler('comment')
      ->createAccess('recording_comment', $account, $context, TRUE);
  }

  /**
   * Anonymous is denied when the flag is off, despite "post comments".
   *
   * This is the core assertion for the whole feature: forbidden() must be
   * returned (not merely a lack of allowed()), since it needs to be sticky
   * against the site-wide "post comments" permission.
   */
  public function testAnonymousDeniedWhenFlagOff(): void {
    $node = $this->createRecordingNode([
      'field_visibility' => TRUE,
      'field_allow_anonymous_comments' => FALSE,
    ]);

    $access = $this->checkCreateAccess($node, new AnonymousUserSession());

    $this->assertTrue($access->isForbidden());
  }

  /**
   * Anonymous is allowed when the flag is on and the recording is public.
   */
  public function testAnonymousAllowedWhenFlagOnAndPublic(): void {
    $node = $this->createRecordingNode([
      'field_visibility' => TRUE,
      'field_allow_anonymous_comments' => TRUE,
    ]);

    $access = $this->checkCreateAccess($node, new AnonymousUserSession());

    $this->assertTrue($access->isAllowed());
  }

  /**
   * Anonymous is still denied on a private recording, even with the flag on.
   *
   * Proves composition with node view access: the anonymous-comments flag
   * alone can never open up a private recording's comments.
   */
  public function testAnonymousStillDeniedOnPrivateRecordingEvenWithFlagOn(): void {
    $node = $this->createRecordingNode([
      'field_visibility' => FALSE,
      'field_allow_anonymous_comments' => TRUE,
    ]);

    $access = $this->checkCreateAccess($node, new AnonymousUserSession());

    $this->assertFalse($access->isAllowed());
  }

  /**
   * An authenticated shared user is unaffected by the anonymous-only flag.
   */
  public function testAuthenticatedSharedUserUnaffectedByAnonymousFlag(): void {
    $shared = $this->createUser(['access comments', 'post comments']);
    $node = $this->createRecordingNode([
      'field_visibility' => FALSE,
      'field_allow_anonymous_comments' => FALSE,
      'field_shared_with' => [$shared],
    ]);

    $access = $this->checkCreateAccess($node, $shared);

    $this->assertTrue($access->isAllowed());
  }

}
