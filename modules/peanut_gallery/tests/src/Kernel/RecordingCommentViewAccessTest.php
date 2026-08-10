<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Kernel;

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests hook_comment_access() re-checks the host recording's view access.
 *
 * Guards against a private recording's comments leaking via a permalink,
 * a Views block, or JSON:API even though the recording node itself is
 * protected.
 *
 * @see peanut_gallery_comment_access()
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class RecordingCommentViewAccessTest extends KernelTestBase {

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
    // Saving a comment fires the push-queue hook, which reads this table.
    $this->installSchema('peanut_gallery', ['peanut_gallery_push_subscription']);
    $this->installConfig(['filter', 'user', 'node', 'comment', 'peanut_gallery']);
    mkdir($this->siteDirectory . '/private', 0775);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * A comment on a private recording is only viewable by permitted users.
   */
  public function testCommentViewAccessFollowsHostRecording(): void {
    $owner = $this->createUser(['access content']);
    // Both accounts hold the core "access content"/"access comments"
    // permissions, so any denial observed below is attributable to
    // peanut_gallery's own hook, never to a missing site-wide permission.
    $shared = $this->createUser(['access content', 'access comments']);
    $stranger = $this->createUser(['access content', 'access comments']);

    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
      'field_shared_with' => [$shared],
    ]);

    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_comment',
      'comment_type' => 'recording_comment',
      'comment_body' => [
        'value' => 'Nice bit at the bridge.',
        'format' => 'plain_text',
      ],
      'field_timestamp' => 92.5,
      'uid' => $owner->id(),
      'status' => CommentInterface::PUBLISHED,
    ]);
    $comment->save();

    $this->assertFalse((bool) $comment->access('view', $stranger), 'Unrelated user with "access comments" is still denied.');
    $this->assertTrue((bool) $comment->access('view', $shared), 'Shared user can view the comment.');
  }

  /**
   * Isolated comments hide other listeners but show the owner and self.
   */
  public function testIsolatedCommentsHideOtherListenersButShowOwnerAndSelf(): void {
    $owner = $this->createUser(['access content']);
    $listenerA = $this->createUser(['access content', 'access comments']);
    $listenerB = $this->createUser(['access content', 'access comments']);
    $bypasser = $this->createUser([
      'access content',
      'access comments',
      'bypass peanut gallery private recording access',
    ]);

    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
      'field_shared_with' => [$listenerA, $listenerB],
      'field_comments_visible_to_shared' => FALSE,
    ]);

    $ownerComment = $this->createRecordingComment($node, $owner, 10.0);
    $listenerAComment = $this->createRecordingComment($node, $listenerA, 20.0);
    $listenerBComment = $this->createRecordingComment($node, $listenerB, 30.0);

    $this->assertTrue((bool) $listenerAComment->access('view', $listenerA), 'A listener can always see their own comment.');
    $this->assertTrue((bool) $ownerComment->access('view', $listenerA), 'A listener can see the owner\'s comment.');
    $this->assertFalse((bool) $listenerBComment->access('view', $listenerA), 'A listener cannot see another listener\'s comment when isolated.');

    $this->assertTrue((bool) $listenerAComment->access('view', $owner), 'The owner sees every comment regardless of isolation.');
    $this->assertTrue((bool) $listenerBComment->access('view', $owner), 'The owner sees every comment regardless of isolation.');

    $this->assertTrue((bool) $listenerBComment->access('view', $bypasser), 'A bypass-permission holder sees every comment regardless of isolation.');
  }

  /**
   * Creates a published recording_comment authored by $account.
   */
  private function createRecordingComment(NodeInterface $node, AccountInterface $account, float $timestamp): CommentInterface {
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_comment',
      'comment_type' => 'recording_comment',
      'comment_body' => ['value' => 'A comment.', 'format' => 'plain_text'],
      'field_timestamp' => $timestamp,
      'uid' => $account->id(),
      'status' => CommentInterface::PUBLISHED,
    ]);
    $comment->save();
    return $comment;
  }

}
