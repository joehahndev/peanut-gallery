<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\peanut_gallery\Peaks\PeaksGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves precomputed waveform peaks so the player renders instantly instead
 * of decoding a potentially hour-long file in the browser.
 *
 * Peaks are generated lazily on first request and cached in Drupal's
 * keyvalue store (plain DB rows) keyed by file id — which means a database
 * sync from dev to production carries already-generated peaks along with
 * it, so production hosting never needs ffmpeg installed. When it isn't
 * available (or generation fails for any reason), this 404s and the
 * frontend transparently falls back to wavesurfer.js's normal client-side
 * decoding.
 */
class PeaksController extends ControllerBase {

  public function __construct(
    protected readonly PeaksGenerator $peaksGenerator,
    protected readonly KeyValueFactoryInterface $keyValueFactory,
    protected readonly LockBackendInterface $lock,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('peanut_gallery.peaks_generator'),
      $container->get('keyvalue'),
      $container->get('lock'),
      $container->get('current_user'),
    );
  }

  /**
   * Returns {peaks: float[], duration: float} for a recording's audio.
   *
   * Deliberately throws a 404 — not a 403 — for both "no such recording"
   * and "not allowed to view this recording", so a private recording's
   * existence can't be inferred from the response. Matches
   * peanut_gallery_file_download()'s access posture.
   */
  public function peaks(NodeInterface $node): CacheableJsonResponse {
    if ($node->bundle() !== 'recording' || !$node->access('view', $this->currentUser)) {
      throw new NotFoundHttpException();
    }
    if (!$node->hasField('field_audio_file') || $node->get('field_audio_file')->isEmpty()) {
      throw new NotFoundHttpException();
    }
    $file = $node->get('field_audio_file')->entity;
    if (!$file instanceof FileInterface) {
      throw new NotFoundHttpException();
    }

    $store = $this->keyValueFactory->get('peanut_gallery.peaks');
    $key = 'file:' . $file->id();
    $data = $store->get($key);

    if (!$data) {
      // Lock so concurrent first-plays of the same track don't each spawn
      // their own ffmpeg process.
      $lockAcquired = $this->lock->acquire($key, 30);
      if (!$lockAcquired) {
        $this->lock->wait($key, 10);
        $data = $store->get($key);
      }
      if (!$data) {
        $data = $this->peaksGenerator->generate($file);
        if ($data) {
          $store->set($key, $data);
        }
        if ($lockAcquired) {
          $this->lock->release($key);
        }
      }
      if (!$data) {
        throw new NotFoundHttpException();
      }
    }

    $response = new CacheableJsonResponse($data);
    $meta = new CacheableMetadata();
    // Replacing the audio file means saving the node, which invalidates
    // this via the node's own cache tags.
    $meta->addCacheTags($node->getCacheTags());
    // Access varies per user (private recordings) — never let a shared
    // cache serve one visitor's response to another.
    $meta->setCacheContexts(['user.permissions', 'user']);
    $response->addCacheableDependency($meta);
    return $response;
  }

}
