<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the per-recording download link.
 *
 * The link is an offer, not a permission — it points at the same URL the
 * player already streams from, so the interesting cases are whether the
 * toggle controls its presence and, crucially, that offering it never
 * widens who can reach the audio.
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class RecordingDownloadLinkTest extends BrowserTestBase {

  use PeanutGalleryTestTrait;

  /**
   * {@inheritdoc}
   *
   * The player chrome — including this link — is rendered by the project's
   * own theme's node--recording template, not by the module. Testing under
   * stark would assert against markup that never exists in production.
   */
  protected $defaultTheme = 'peanut_gallery_theme';

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
   * A recording with the toggle off offers no download link.
   */
  public function testDownloadLinkAbsentByDefault(): void {
    $node = $this->createRecordingNode(['field_allow_download' => FALSE]);

    $this->drupalGet($node->toUrl());

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', 'a.pg-waveform-download');
  }

  /**
   * A recording with the toggle on offers a download link.
   */
  public function testDownloadLinkShownWhenEnabled(): void {
    $node = $this->createRecordingNode(['field_allow_download' => TRUE]);

    $this->drupalGet($node->toUrl());

    $this->assertSession()->statusCodeEquals(200);
    $link = $this->assertSession()->elementExists('css', 'a.pg-waveform-download');
    // The download attribute is what makes a browser save the file rather
    // than navigate to it, which for hours of audio is the whole point.
    $this->assertNotEmpty($link->getAttribute('download'));
  }

  /**
   * Offering a download does not expose a private recording to strangers.
   *
   * The regression this guards against is treating "downloadable" as a
   * visibility setting. It is not one: the node stays private, and an
   * unrelated user must still be refused both the page and the file.
   */
  public function testDownloadableStaysPrivateFromStrangers(): void {
    $owner = $this->drupalCreateUser();
    $stranger = $this->drupalCreateUser();
    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
      'field_allow_download' => TRUE,
    ]);

    /** @var \Drupal\file\FileInterface $file */
    $file = $node->get('field_audio_file')->entity;

    $this->drupalLogin($stranger);
    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(403);

    // 403, not 404: hook_file_download() returns -1 for a denied file, and
    // core's download controller renders that as forbidden. Only the peaks
    // endpoints deliberately 404 to hide a recording's existence.
    $this->drupalGet($file->createFileUrl(FALSE));
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A shared user on a private recording does get the link and the file.
   */
  public function testSharedUserCanDownload(): void {
    $owner = $this->drupalCreateUser();
    $guest = $this->drupalCreateUser();
    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
      'field_shared_with' => [$guest],
      'field_allow_download' => TRUE,
    ]);

    $this->drupalLogin($guest);
    $this->drupalGet($node->toUrl());

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', 'a.pg-waveform-download');
  }

}
