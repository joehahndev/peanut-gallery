<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\peanut_gallery\Peaks\PeaksGenerator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Waveform peaks backfill.
 *
 * Peaks live in the peanut_gallery.peaks keyvalue store — plain DB rows —
 * so running this in dev (where ffmpeg is easy to install) and then
 * syncing the database to production ships every waveform along with it.
 * Production never needs ffmpeg installed at all.
 */
class PeaksCommands extends DrushCommands {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly PeaksGenerator $peaksGenerator,
    protected readonly KeyValueFactoryInterface $keyValueFactory,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('peanut_gallery.peaks_generator'),
      $container->get('keyvalue'),
    );
  }

  /**
   * Generates waveform peaks for every recording that doesn't have them yet.
   */
  #[CLI\Command(name: 'peanut-gallery:peaks-backfill', aliases: ['pg-peaks'])]
  #[CLI\Option(name: 'force', description: 'Regenerate even when peaks already exist.')]
  public function backfill(array $options = ['force' => FALSE]): void {
    if (!$this->peaksGenerator->isAvailable()) {
      $this->logger()->warning(dt('ffmpeg was not found on this host — nothing to do. Run this from an environment that has ffmpeg (e.g. your DDEV dev site); the resulting peaks can then travel to production via a normal database sync.'));
      return;
    }

    $store = $this->keyValueFactory->get('peanut_gallery.peaks');
    $storage = $this->entityTypeManager->getStorage('node');

    $nids = $storage->getQuery()
      ->condition('type', 'recording')
      ->exists('field_audio_file')
      ->accessCheck(FALSE)
      ->execute();

    $done = 0;
    $skipped = 0;
    $failed = 0;
    foreach ($storage->loadMultiple($nids) as $node) {
      $file = $node->get('field_audio_file')->entity;
      if (!$file) {
        continue;
      }
      $key = 'file:' . $file->id();
      if (!$options['force'] && $store->get($key)) {
        $skipped++;
        continue;
      }
      $data = $this->peaksGenerator->generate($file);
      if ($data) {
        $store->set($key, $data);
        $done++;
      }
      else {
        $failed++;
        $this->logger()->warning(dt('Failed: @title (nid @nid)', ['@title' => $node->label(), '@nid' => $node->id()]));
      }
    }

    $this->logger()->success(dt('Peaks backfill: @done generated, @skipped already had peaks, @failed failed.', [
      '@done' => $done,
      '@skipped' => $skipped,
      '@failed' => $failed,
    ]));
  }

}
