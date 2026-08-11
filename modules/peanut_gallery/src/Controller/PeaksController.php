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
 * Serves precomputed waveform peaks so the player renders instantly.
 *
 * Avoids decoding a potentially hour-long file in the browser. Peaks are
 * generated lazily on first request and cached in Drupal's
 * keyvalue store (plain DB rows) keyed by file id — which means a database
 * sync from dev to production carries already-generated peaks along with
 * it, so production hosting never needs ffmpeg installed. When it isn't
 * available (or generation fails for any reason), this 404s and the
 * frontend transparently falls back to wavesurfer.js's normal client-side
 * decoding.
 */
final class PeaksController extends ControllerBase {

  /**
   * Overview bucket count, mirroring PeaksGenerator::BUCKETS.
   *
   * The colour payload has to say how many points it carries so the client
   * can map a pixel to a colour without assuming a resolution.
   */
  protected const OVERVIEW_BUCKETS = 800;

  public function __construct(
    protected readonly PeaksGenerator $peaksGenerator,
    protected readonly KeyValueFactoryInterface $keyValueFactory,
    protected readonly LockBackendInterface $lock,
    protected readonly AccountInterface $account,
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
    return $this->respond($node, FALSE);
  }

  /**
   * Serves the dense series used by the zoomed detail view.
   *
   * A separate route, not an extra key in the overview response: this is
   * megabytes on a long recording, and every page load needs the overview
   * while only a scrolling detail view needs this.
   */
  public function detail(NodeInterface $node): CacheableJsonResponse {
    return $this->respond($node, TRUE);
  }

  /**
   * Shared body for both peak responses.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The recording.
   * @param bool $detail
   *   TRUE for the dense series, FALSE for the 800-bucket overview.
   */
  protected function respond(NodeInterface $node, bool $detail): CacheableJsonResponse {
    if ($node->bundle() !== 'recording' || !$node->access('view', $this->account)) {
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

    // The overview response deliberately omits the dense series, which can
    // run to megabytes on a long recording and is only ever wanted by the
    // detail view.
    $payload = $detail
      ? ['peaks' => $data['detail'] ?? [], 'duration' => $data['duration'] ?? 0]
      : ['peaks' => $data['peaks'] ?? [], 'duration' => $data['duration'] ?? 0];

    // Three-band colour, when it has been generated. Optional throughout:
    // a recording without it draws in a single colour exactly as before,
    // so this can never break a player that is already working.
    $color = $this->keyValueFactory->get('peanut_gallery.peaks_color')->get($key);
    if ($color) {
      $series = $detail ? ($color['detail'] ?? '') : ($color['overview'] ?? '');
      if ($series !== '') {
        $payload['color'] = $series;
        $payload['colorPoints'] = $detail ? ($color['points'] ?? 0) : self::OVERVIEW_BUCKETS;
      }
    }

    // A recording whose peaks predate the detail series returns an empty
    // detail payload, and the detail view stays hidden. It is tempting to
    // regenerate here instead — that is exactly what this code used to do,
    // and it was a mistake: unlike the branch above it cannot be reached
    // with the store empty, so the result is never cached against the miss,
    // and every single page view spawns a fresh full-length ffmpeg decode.
    // On a 929 MB recording that took the production host to a load average
    // of 165 and made the site unreachable. Backfill peaks out of band
    // (drush peanut-gallery:peaks-backfill), never in a web request.
    $response = new CacheableJsonResponse($payload);
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
