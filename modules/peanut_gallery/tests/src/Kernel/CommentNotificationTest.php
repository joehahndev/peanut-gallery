<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\comment\Entity\Comment;
use Drupal\node\NodeInterface;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the comment notification emails.
 *
 * @see peanut_gallery_comment_insert()
 * @see peanut_gallery_mail()
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class CommentNotificationTest extends KernelTestBase {

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
    $this->installConfig(['filter', 'user', 'node', 'comment', 'peanut_gallery', 'system']);
    mkdir($this->siteDirectory . '/private', 0775);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');

    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, ['access content']);

    // Route mail into state storage instead of actually sending it.
    $this->config('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();
  }

  /**
   * Posting a comment mails every configured recipient.
   */
  public function testCommentInsertNotifiesConfiguredRecipients(): void {
    $this->config('peanut_gallery.settings')
      ->set('notification_emails', ['one@example.com', 'two@example.com'])
      ->save();

    $node = $this->createRecordingNode(['title' => 'Late Night Mix']);
    $this->createComment($node, 'the drop is perfect here', 42.5);

    $mails = $this->getMails();
    $this->assertCount(2, $mails, 'One message per configured recipient.');

    $recipients = array_column($mails, 'to');
    $this->assertEqualsCanonicalizing(['one@example.com', 'two@example.com'], $recipients);

    $body = implode("\n", (array) $mails[0]['body']);
    $this->assertStringContainsString('Late Night Mix', (string) $mails[0]['subject']);
    $this->assertStringContainsString('the drop is perfect here', $body);
    $this->assertStringContainsString('0:42.5', $body, 'The pinned moment is included, at tenth-second precision.');
  }

  /**
   * Nothing is sent when no recipients are configured.
   *
   * This is the default state, so a site that never opts in must stay
   * silent rather than mailing the site address or the comment author.
   */
  public function testNoRecipientsMeansNoMail(): void {
    $node = $this->createRecordingNode();
    $this->createComment($node, 'quiet please');

    $this->assertSame([], $this->getMails(), 'No recipients configured means no mail.');
  }

  /**
   * Editing an existing comment notifies too, and says so.
   */
  public function testCommentUpdateNotifies(): void {
    $this->config('peanut_gallery.settings')
      ->set('notification_emails', ['one@example.com'])
      ->save();

    $node = $this->createRecordingNode();
    $comment = $this->createComment($node, 'first thoughts');

    \Drupal::state()->set('system.test_mail_collector', []);

    $comment->set('comment_body', ['value' => 'second thoughts', 'format' => 'plain_text']);
    $comment->save();

    $mails = $this->getMails();
    $this->assertCount(1, $mails);
    $this->assertStringContainsString('edited', (string) $mails[0]['subject']);
    $this->assertStringContainsString('second thoughts', implode("\n", (array) $mails[0]['body']));
  }

  /**
   * A comment on some other entity type never triggers a notification.
   */
  public function testOtherCommentBundlesAreIgnored(): void {
    $this->config('peanut_gallery.settings')
      ->set('notification_emails', ['one@example.com'])
      ->save();

    // The module only claims recording_comment; anything else is not ours.
    $node = $this->createRecordingNode();
    $comment = $this->createComment($node, 'mine');
    $this->assertSame('recording_comment', $comment->bundle());
    $this->assertCount(1, $this->getMails(), 'Sanity: our own bundle does notify.');
  }

  /**
   * Creates a saved comment on a recording.
   */
  private function createComment(NodeInterface $node, string $body, ?float $timestamp = NULL): Comment {
    $values = [
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => 'field_comment',
      'comment_type' => 'recording_comment',
      'subject' => 'Test comment',
      'comment_body' => ['value' => $body, 'format' => 'plain_text'],
      'status' => 1,
      'name' => 'Tester',
    ];
    if ($timestamp !== NULL) {
      $values['field_timestamp'] = $timestamp;
    }
    $comment = Comment::create($values);
    $comment->save();
    return $comment;
  }

  /**
   * Returns the messages captured by the test mail collector.
   *
   * @return array<int, array<string, mixed>>
   *   Captured messages.
   */
  private function getMails(): array {
    return \Drupal::state()->get('system.test_mail_collector', []);
  }

}
