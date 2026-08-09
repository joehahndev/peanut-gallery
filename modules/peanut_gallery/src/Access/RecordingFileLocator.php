<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Access;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * Maps a private:// file URI back to the Recording node it belongs to.
 *
 * Used by hook_file_download() so that a private audio file is gated by
 * exactly the same access decision as its node page — never a separate,
 * potentially-drifting copy of the logic.
 */
class RecordingFileLocator {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FileUsageInterface $fileUsage,
  ) {}

  /**
   * Finds the Recording node that owns the file at the given URI.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: \Drupal\file\FileInterface}|null
   *   A [node, file] pair, or NULL if this URI isn't a Peanut Gallery
   *   recording's audio file (including: no File entity for it, no
   *   recorded usage, or the referencing node isn't actually a recording —
   *   in every such case the caller should abstain, not deny).
   */
  public function getRecordingForUri(string $uri): ?array {
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
    $file = reset($files);
    if (!$file instanceof FileInterface) {
      return NULL;
    }

    // Core's file field implementation registers file usage automatically
    // whenever a file field references this File entity — no custom
    // bookkeeping needed here.
    $usage = $this->fileUsage->listUsage($file);
    $nids = array_keys($usage['file']['node'] ?? []);
    if (!$nids) {
      return NULL;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      if ($node->bundle() !== 'recording' || !$node->hasField('field_audio_file')) {
        continue;
      }
      $referenced = $node->get('field_audio_file')->entity;
      // Defensive check against a file coincidentally reused elsewhere:
      // only match if this node's *current* audio file is the one requested.
      if ($referenced instanceof FileInterface && (int) $referenced->id() === (int) $file->id()) {
        return [$node, $file];
      }
    }

    return NULL;
  }

}
