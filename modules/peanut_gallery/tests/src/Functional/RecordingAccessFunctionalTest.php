<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that private-recording access is enforced on the real node page.
 *
 * Unlike the Kernel access tests, this hits the actual HTTP response codes
 * a visitor gets, proving the access model isn't just correct in theory
 * but actually wired up end to end (routing, access checkers, rendering).
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class RecordingAccessFunctionalTest extends BrowserTestBase {

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
   * An unrelated logged-in user gets 403 on a private recording's page.
   */
  public function testUnrelatedUserGetsAccessDenied(): void {
    $owner = $this->drupalCreateUser();
    $stranger = $this->drupalCreateUser();
    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);

    $this->drupalLogin($stranger);
    $this->drupalGet($node->toUrl());

    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A user listed in field_shared_with gets 200 on a private recording.
   */
  public function testSharedUserCanView(): void {
    $owner = $this->drupalCreateUser();
    $shared = $this->drupalCreateUser();
    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
      'field_shared_with' => [$shared],
    ]);

    $this->drupalLogin($shared);
    $this->drupalGet($node->toUrl());

    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Anonymous gets 403 on a private recording's page.
   */
  public function testAnonymousGetsAccessDenied(): void {
    $owner = $this->drupalCreateUser();
    $node = $this->createRecordingNode([
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);

    $this->drupalGet($node->toUrl());

    $this->assertSession()->statusCodeEquals(403);
  }

}
