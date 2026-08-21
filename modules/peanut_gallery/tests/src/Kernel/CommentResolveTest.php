<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;
use Drupal\peanut_gallery\Controller\CommentResolveController;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests who may resolve a recording comment, and that resolving works.
 *
 * @see \Drupal\peanut_gallery\Controller\CommentResolveController
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class CommentResolveTest extends KernelTestBase {

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

    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content', 'access comments']);
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['access content', 'access comments']);

    // Consume uid 1 so no test account is the superuser.
    $this->createUser([], 'uid1-superuser-placeholder');
  }

  /**
   * The shipped config includes the resolved field.
   */
  public function testResolvedFieldInstalled(): void {
    $field = FieldConfig::loadByName('comment', 'recording_comment', 'field_resolved');
    $this->assertNotNull($field);
    $this->assertFalse((bool) $field->get('required'));
  }

  /**
   * Only the recording owner and the comment author may resolve.
   */
  public function testWhoMayResolve(): void {
    $owner = $this->createUser();
    $author = $this->createUser();
    $listener = $this->createUser();
    $recording = $this->createRecordingNode(['uid' => $owner->id()]);
    $comment = $this->createRecordingComment($recording, $author);

    $this->assertTrue(CommentResolveController::userMayResolve($comment, $owner), 'The recording owner may resolve.');
    $this->assertTrue(CommentResolveController::userMayResolve($comment, $author), 'The comment author may resolve.');
    $this->assertFalse(CommentResolveController::userMayResolve($comment, $listener), 'Another listener may not resolve.');
    $this->assertFalse(CommentResolveController::userMayResolve($comment, new AnonymousUserSession()), 'Anonymous may never resolve.');
  }

  /**
   * An anonymous-authored comment is resolvable by the owner only.
   *
   * Uid 0 must never make every anonymous visitor "the author".
   */
  public function testAnonymousAuthoredComment(): void {
    $owner = $this->createUser();
    $recording = $this->createRecordingNode(['uid' => $owner->id()]);
    $comment = $this->createRecordingComment($recording, NULL);

    $this->assertTrue(CommentResolveController::userMayResolve($comment, $owner));
    $this->assertFalse(CommentResolveController::userMayResolve($comment, new AnonymousUserSession()));
  }

  /**
   * Route access also requires view access to the comment.
   *
   * The author of a comment on a private recording they have since lost
   * access to must not be able to keep toggling it.
   */
  public function testAccessRequiresViewAccess(): void {
    $owner = $this->createUser();
    $author = $this->createUser();
    // Private recording NOT shared with the author.
    $recording = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);
    $comment = $this->createRecordingComment($recording, $author);

    $this->setCurrentUser($author);
    $controller = CommentResolveController::create($this->container);
    $this->assertFalse($controller->access($comment)->isAllowed(), 'No view access, no resolve — whatever userMayResolve says.');

    $this->setCurrentUser($owner);
    $this->assertTrue($controller->access($comment)->isAllowed(), 'The owner of the private recording can resolve.');
  }

  /**
   * Toggling flips the flag and reports the new state.
   */
  public function testToggle(): void {
    $owner = $this->createUser();
    $recording = $this->createRecordingNode(['uid' => $owner->id()]);
    $comment = $this->createRecordingComment($recording, $owner);

    $controller = CommentResolveController::create($this->container);

    $response = $controller->toggle($comment);
    $state = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($state['resolved']);
    $reloaded = Comment::load($comment->id());
    $this->assertTrue((bool) $reloaded->get('field_resolved')->value);

    $response = $controller->toggle($reloaded);
    $state = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($state['resolved']);
  }

  /**
   * Moment URLs carry both the seek parameter and the comment fragment.
   */
  public function testMomentUrl(): void {
    $owner = $this->createUser();
    $recording = $this->createRecordingNode(['uid' => $owner->id()]);
    $comment = $this->createRecordingComment($recording, $owner, 93.4);

    $url = _peanut_gallery_comment_moment_url($comment);
    $this->assertStringContainsString('t=93.4', $url);
    $this->assertStringContainsString('#comment-' . $comment->id(), $url);
  }

  /**
   * Creates a published recording comment.
   *
   * @param \Drupal\node\NodeInterface $recording
   *   The host recording.
   * @param \Drupal\user\UserInterface|null $author
   *   The author, or NULL for an anonymous-authored comment.
   * @param float $timestamp
   *   The pinned moment.
   *
   * @return \Drupal\comment\CommentInterface
   *   The saved comment.
   */
  protected function createRecordingComment(NodeInterface $recording, ?UserInterface $author, float $timestamp = 12.345): CommentInterface {
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $recording->id(),
      'field_name' => 'field_comment',
      'comment_type' => 'recording_comment',
      'uid' => $author ? $author->id() : 0,
      'name' => $author ? NULL : 'Anonymous listener',
      'comment_body' => ['value' => 'A note about this moment.', 'format' => 'plain_text'],
      'field_timestamp' => $timestamp,
      'status' => 1,
    ]);
    $comment->save();
    return $comment;
  }

}
