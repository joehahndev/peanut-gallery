<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use Drupal\comment\Entity\Comment;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests comment isolation in the rendered page, not just the access API.
 *
 * These assertions are deliberately about HTML. hook_comment_access() was
 * already correct and still the inline list printed every comment, because
 * core's CommentDefaultFormatter renders the thread with viewMultiple(),
 * which never consults entity access. A test that only asked
 * $comment->access('view', $user) passed throughout that leak.
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class CommentIsolationRenderTest extends BrowserTestBase {

  use PeanutGalleryTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'comment',
    'file',
    'user',
    'field',
    'filter',
    'text',
    'peanut_gallery',
  ];

  /**
   * With isolation on, a listener never sees another listener's comment.
   */
  public function testIsolatedListenerSeesOnlyOwnAndOwnerComments(): void {
    $owner = $this->drupalCreateUser(['access comments', 'post comments']);
    $alice = $this->drupalCreateUser(['access comments', 'post comments']);
    $bob = $this->drupalCreateUser(['access comments', 'post comments']);

    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
      'field_shared_with' => [$alice, $bob],
      'field_comments_visible_to_shared' => FALSE,
    ]);

    $this->postComment($node, $owner, 'owner-said-this');
    $this->postComment($node, $alice, 'alice-said-this');
    $this->postComment($node, $bob, 'bob-said-this');

    $this->drupalLogin($alice);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('alice-said-this');
    $this->assertSession()->pageTextContains('owner-said-this');
    $this->assertSession()->pageTextNotContains('bob-said-this');

    // The second request matters as much as the first: a render cache that
    // did not vary per user would hand alice's filtered page to bob.
    $this->drupalLogin($bob);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('bob-said-this');
    $this->assertSession()->pageTextContains('owner-said-this');
    $this->assertSession()->pageTextNotContains('alice-said-this');

    // The owner is the exception and sees the lot.
    $this->drupalLogin($owner);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('alice-said-this');
    $this->assertSession()->pageTextContains('bob-said-this');
  }

  /**
   * With isolation off, shared listeners see each other's comments.
   */
  public function testNonIsolatedListenersSeeEachOther(): void {
    $owner = $this->drupalCreateUser(['access comments', 'post comments']);
    $alice = $this->drupalCreateUser(['access comments', 'post comments']);
    $bob = $this->drupalCreateUser(['access comments', 'post comments']);

    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
      'field_shared_with' => [$alice, $bob],
      'field_comments_visible_to_shared' => TRUE,
    ]);

    $this->postComment($node, $bob, 'bob-said-this');

    $this->drupalLogin($alice);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('bob-said-this');
  }

  /**
   * Saves a published comment authored by a given user.
   */
  private function postComment(NodeInterface $node, UserInterface $author, string $body): void {
    Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_comment',
      'comment_type' => 'recording_comment',
      'subject' => 'test',
      'comment_body' => ['value' => $body, 'format' => 'plain_text'],
      'status' => 1,
      'uid' => $author->id(),
    ])->save();
  }

}
