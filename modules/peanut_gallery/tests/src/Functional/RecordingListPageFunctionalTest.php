<?php

declare(strict_types=1);

namespace Drupal\Tests\peanut_gallery\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\peanut_gallery\Traits\PeanutGalleryTestTrait;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the /recordings listing page's access filtering.
 *
 * The listing is designed to be usable as a site front page, so the same
 * URL must show a different set of recordings to each visitor. These tests
 * assert on what actually renders over HTTP, which additionally proves the
 * per-user render caching is correct: a leak there would show one user's
 * listing to the next, and the sequential requests below would catch it.
 */
#[Group('peanut_gallery')]
#[RunTestsInSeparateProcesses]
final class RecordingListPageFunctionalTest extends BrowserTestBase {

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
   * Anonymous visitors see public recordings but never private ones.
   */
  public function testAnonymousSeesOnlyPublicRecordings(): void {
    $public = $this->createRecordingNode([
      'title' => 'Public tune',
      'field_visibility' => TRUE,
    ]);
    $private = $this->createRecordingNode([
      'title' => 'Secret tune',
      'field_visibility' => FALSE,
    ]);

    $this->drupalGet('/recordings');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($public->label());
    $this->assertSession()->pageTextNotContains($private->label());
  }

  /**
   * A shared friend sees the private recording shared with them.
   *
   * Also asserts they do not see a *different* owner's private recording
   * that nobody shared with them, so a passing result can't be explained
   * by the listing simply ignoring visibility altogether.
   */
  public function testSharedUserSeesSharedPrivateRecording(): void {
    $owner = $this->drupalCreateUser();
    $friend = $this->drupalCreateUser();

    $shared = $this->createRecordingNode([
      'title' => 'Shared mix',
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
      'field_shared_with' => [$friend],
    ]);
    $unshared = $this->createRecordingNode([
      'title' => 'Not for you',
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);

    $this->drupalLogin($friend);
    $this->drupalGet('/recordings');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($shared->label());
    $this->assertSession()->pageTextNotContains($unshared->label());
  }

  /**
   * An owner sees their own private recording without being shared with.
   */
  public function testOwnerSeesOwnPrivateRecording(): void {
    $owner = $this->drupalCreateUser();
    $own = $this->createRecordingNode([
      'title' => 'My own mix',
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);

    $this->drupalLogin($owner);
    $this->drupalGet('/recordings');

    $this->assertSession()->pageTextContains($own->label());
  }

  /**
   * The bypass permission reveals every private recording in the listing.
   */
  public function testBypassPermissionSeesEverything(): void {
    $owner = $this->drupalCreateUser();
    $private = $this->createRecordingNode([
      'title' => 'Everyones business',
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
    ]);

    $admin = $this->drupalCreateUser(['bypass peanut gallery private recording access']);
    $this->drupalLogin($admin);
    $this->drupalGet('/recordings');

    $this->assertSession()->pageTextContains($private->label());
  }

  /**
   * Unpublished recordings stay out of the listing, even for their owner.
   *
   * Node access grants govern view access but carry no notion of
   * publication status, so without an explicit status condition in the
   * query an owner's draft would leak into the listing of anyone holding a
   * matching grant. This is the regression test for that.
   */
  public function testUnpublishedRecordingIsExcluded(): void {
    $owner = $this->drupalCreateUser();
    $draft = $this->createRecordingNode([
      'title' => 'Unfinished business',
      'uid' => $owner->id(),
      'field_visibility' => TRUE,
      'status' => NodeInterface::NOT_PUBLISHED,
    ]);

    $this->drupalLogin($owner);
    $this->drupalGet('/recordings');

    $this->assertSession()->pageTextNotContains($draft->label());
  }

  /**
   * One user's cached listing is never served to the next user.
   *
   * Requests the same URL as two different accounts in sequence; if the
   * page were cached without varying on node grants, the second request
   * would be served the first user's rows.
   */
  public function testListingIsNotSharedBetweenUsers(): void {
    $owner = $this->drupalCreateUser();
    $friend = $this->drupalCreateUser();
    $stranger = $this->drupalCreateUser();

    $shared = $this->createRecordingNode([
      'title' => 'Only friends may hear this',
      'uid' => $owner->id(),
      'field_visibility' => FALSE,
      'field_shared_with' => [$friend],
    ]);

    $this->drupalLogin($friend);
    $this->drupalGet('/recordings');
    $this->assertSession()->pageTextContains($shared->label());

    $this->drupalLogin($stranger);
    $this->drupalGet('/recordings');
    $this->assertSession()->pageTextNotContains($shared->label());
  }

}
