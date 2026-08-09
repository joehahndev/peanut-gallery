<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests peanut_gallery_file_download() via RecordingFileLocator.
 *
 * @see \Drupal\peanut_gallery\Access\RecordingFileLocator
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class RecordingFileDownloadAccessTest extends KernelTestBase {

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

    // Bare Kernel tests don't run an install profile, so anonymous lacks
    // the 'access content' permission NodeAccessControlHandler checks
    // before ever consulting our grants.
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
  }

  /**
   * The owner of a private recording can download its audio file.
   */
  public function testOwnerCanDownload(): void {
    $owner = $this->createUser();
    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);
    \Drupal::currentUser()->setAccount($owner);

    $result = peanut_gallery_file_download($this->getAudioUri($node));

    $this->assertIsArray($result);
    $this->assertArrayHasKey('Content-Type', $result);
    $this->assertSame('private', $result['Cache-Control']);
  }

  /**
   * An unrelated user is denied.
   */
  public function testUnrelatedUserDenied(): void {
    $owner = $this->createUser();
    $stranger = $this->createUser();
    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);
    \Drupal::currentUser()->setAccount($stranger);

    $this->assertSame(-1, peanut_gallery_file_download($this->getAudioUri($node)));
  }

  /**
   * Anonymous is denied on a private recording's audio.
   */
  public function testAnonymousDeniedOnPrivateRecording(): void {
    $owner = $this->createUser();
    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);
    \Drupal::currentUser()->setAccount(new AnonymousUserSession());

    $this->assertSame(-1, peanut_gallery_file_download($this->getAudioUri($node)));
  }

  /**
   * A public recording's audio is downloadable by anonymous.
   */
  public function testPublicRecordingDownloadableByAnonymous(): void {
    $node = $this->createRecordingNode(['field_visibility' => TRUE]);
    \Drupal::currentUser()->setAccount(new AnonymousUserSession());

    $result = peanut_gallery_file_download($this->getAudioUri($node));

    $this->assertIsArray($result);
  }

  /**
   * A private file with no file-usage record at all is ignored (abstains).
   *
   * Guards against this module interfering with unrelated private files
   * that don't belong to any Recording.
   */
  public function testUnrelatedPrivateFileWithNoUsageIsIgnored(): void {
    $orphan_file = $this->createRecordingAudioFile();
    \Drupal::currentUser()->setAccount($this->createUser());

    $this->assertNull(peanut_gallery_file_download($orphan_file->getFileUri()));
  }

  /**
   * Gets the URI of a recording's attached audio file.
   */
  protected function getAudioUri(NodeInterface $node): string {
    /** @var \Drupal\file\FileInterface $file */
    $file = $node->get('field_audio_file')->entity;
    return $file->getFileUri();
  }

}
