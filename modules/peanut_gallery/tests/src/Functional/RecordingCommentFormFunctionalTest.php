<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the anonymous-comments toggle is enforced server-side.
 *
 * Not just hidden in the UI: a crafted raw POST straight to the comment
 * submission route, bypassing the rendered form entirely, must still be
 * denied when the per-recording flag is off.
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class RecordingCommentFormFunctionalTest extends BrowserTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Anonymous holds the core permissions site-wide; any denial observed
    // in these tests is attributable to peanut_gallery's own per-recording
    // flag, never to a missing site-wide permission.
    /** @var \Drupal\user\RoleInterface $anonymous_role */
    $anonymous_role = Role::load(RoleInterface::ANONYMOUS_ID);
    $anonymous_role->grantPermission('access comments');
    $anonymous_role->grantPermission('post comments');
    $anonymous_role->save();
  }

  /**
   * The anonymous comment form is absent when the flag is off.
   */
  public function testAnonymousCommentFormAbsentWhenFlagOff(): void {
    $node = $this->createRecordingNode([
      'field_visibility' => TRUE,
      'field_allow_anonymous_comments' => FALSE,
    ]);

    $this->drupalGet($node->toUrl());

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldNotExists('comment_body[0][value]');
    $this->assertSession()->fieldNotExists('field_timestamp[0][value]');
  }

  /**
   * A raw POST to the comment route is denied without the form ever rendering.
   *
   * This is the test that most directly proves the requirement: access is
   * actually enforced, not merely a matter of hiding the form/link in the
   * UI. Uses the real HTTP client (bypassing Mink/the rendered page
   * entirely) to POST straight to core's comment.reply route.
   */
  public function testRawPostToCommentReplyRouteIsDeniedWhenFlagOff(): void {
    $node = $this->createRecordingNode([
      'field_visibility' => TRUE,
      'field_allow_anonymous_comments' => FALSE,
    ]);

    $url = Url::fromRoute('comment.reply', [
      'entity_type' => 'node',
      'entity' => $node->id(),
      'field_name' => 'field_comment',
    ], ['absolute' => TRUE])->toString();

    $response = $this->getHttpClient()->request('POST', $url, [
      'form_params' => [
        'field_timestamp[0][value]' => '12.5',
        'comment_body[0][value]' => 'Sneaking in without the rendered form.',
        'op' => 'Save',
      ],
      'http_errors' => FALSE,
    ]);

    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Anonymous comments persist field_timestamp correctly when allowed.
   */
  public function testAnonymousCommentAcceptedAndPersistsTimestampWhenFlagOn(): void {
    $node = $this->createRecordingNode([
      'field_visibility' => TRUE,
      'field_allow_anonymous_comments' => TRUE,
    ]);

    $this->drupalGet($node->toUrl());
    $this->submitForm([
      'comment_body[0][value]' => 'Great moment right here.',
      'field_timestamp[0][value]' => '42.5',
    ], 'Save');

    $comments = \Drupal::entityTypeManager()->getStorage('comment')->loadByProperties([
      'entity_id' => $node->id(),
      'entity_type' => 'node',
    ]);
    $comment = reset($comments);

    $this->assertNotFalse($comment);
    $this->assertEquals(42.5, (float) $comment->get('field_timestamp')->value);
  }

  /**
   * A comment with no timestamp is refused, visibly, and is not saved.
   *
   * Both halves matter. The timestamp is enforced server-side rather than
   * by marking the field required, because the widget is visually hidden
   * and HTML5 `required` on a hidden control makes the browser refuse to
   * submit while pointing at something nobody can see — the form dies with
   * no message at all. This asserts the error is actually in the page, not
   * merely that the comment failed to save.
   */
  public function testCommentWithoutTimestampIsRefusedVisibly(): void {
    $node = $this->createRecordingNode([
      'field_visibility' => TRUE,
      'field_allow_anonymous_comments' => TRUE,
    ]);

    $this->drupalGet($node->toUrl());
    $this->submitForm([
      'comment_body[0][value]' => 'A thought about nothing in particular.',
      'field_timestamp[0][value]' => '',
    ], 'Save');

    $this->assertSession()->pageTextContains('Choose the moment this comment is about');

    $comments = \Drupal::entityTypeManager()->getStorage('comment')->loadByProperties([
      'entity_id' => $node->id(),
      'entity_type' => 'node',
    ]);
    $this->assertCount(0, $comments, 'No comment is stored when the moment is missing.');
  }

}
