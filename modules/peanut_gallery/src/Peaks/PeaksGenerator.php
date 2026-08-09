<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Peaks;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;

/**
 * Decodes an audio file with ffmpeg and downsamples it to display peaks.
 *
 * This is an optional enhancement, not a hard dependency: when ffmpeg isn't
 * installed, generate() returns NULL and callers fall back to letting
 * wavesurfer.js decode the file client-side, exactly as if this class
 * didn't exist. That degradation is deliberate — it means production
 * hosting (which may not allow shelling out to ffmpeg at all, e.g. shared
 * hosting) never needs it: run the Drush backfill in dev/DDEV, where ffmpeg
 * is easy to install, and let a database sync carry the generated peaks
 * (plain DB rows in the keyvalue store) to production.
 */
class PeaksGenerator {

  /**
   * Downsampled PCM sample rate used for peak extraction.
   *
   * Low on purpose — this is for a visual waveform, not audio quality.
   */
  protected const SAMPLE_RATE = 8000;

  /**
   * Number of peak buckets returned, independent of recording length.
   */
  protected const BUCKETS = 800;

  public function __construct(
    protected readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Whether ffmpeg is available on this host.
   */
  public function isAvailable(): bool {
    return trim((string) shell_exec('command -v ffmpeg')) !== '';
  }

  /**
   * Decodes $file with ffmpeg and returns normalized display peaks.
   *
   * @return array{peaks: float[], duration: float}|null
   *   NULL if ffmpeg is unavailable or decoding fails for any reason —
   *   callers must treat that as "no peaks yet", never as an error to
   *   surface to the visitor.
   */
  public function generate(FileInterface $file): ?array {
    $ffmpeg = trim((string) shell_exec('command -v ffmpeg'));
    if ($ffmpeg === '') {
      return NULL;
    }

    $uri = $file->getFileUri();
    $local = $this->fileSystem->realpath($uri);
    $cleanup = NULL;
    if (!$local) {
      // Remote stream wrapper (e.g. S3) — ffmpeg needs a local path.
      $bytes = @file_get_contents($uri);
      if ($bytes === FALSE) {
        return NULL;
      }
      $temp = $this->fileSystem->tempnam('temporary://', 'peanut_gallery_peaks_');
      $local = $this->fileSystem->realpath($temp) ?: $temp;
      file_put_contents($local, $bytes);
      $cleanup = $local;
    }

    $cmd = sprintf(
      '%s -v error -i %s -ac 1 -ar %d -f s16le pipe:1',
      escapeshellcmd($ffmpeg),
      escapeshellarg($local),
      self::SAMPLE_RATE,
    );
    $pcm = shell_exec($cmd);
    if ($cleanup) {
      @unlink($cleanup);
    }
    if (!$pcm || strlen($pcm) < 2) {
      return NULL;
    }

    return $this->downsample($pcm);
  }

  /**
   * Reduces raw 16-bit mono PCM to a fixed number of min/max display peaks.
   *
   * @return array{peaks: float[], duration: float}
   *   The downsampled peaks and the recording's duration in seconds.
   */
  protected function downsample(string $pcm): array {
    $sampleCount = intdiv(strlen($pcm), 2);
    $duration = $sampleCount / self::SAMPLE_RATE;
    $bucketSize = max(1, intdiv($sampleCount, self::BUCKETS));

    $peaks = [];
    for ($bucket = 0; $bucket < self::BUCKETS; $bucket++) {
      $start = $bucket * $bucketSize;
      if ($start >= $sampleCount) {
        break;
      }
      $end = min($start + $bucketSize, $sampleCount);
      // Stride within the bucket: a handful of probes is plenty for a
      // display-only peak, and keeps this fast even on hour-long files.
      $stride = max(1, intdiv($end - $start, 8));

      $max = 0;
      for ($i = $start; $i < $end; $i += $stride) {
        $sample = unpack('s', substr($pcm, $i * 2, 2))[1];
        $abs = abs($sample);
        if ($abs > $max) {
          $max = $abs;
        }
      }
      $peaks[] = round($max / 32768, 3);
    }

    return ['peaks' => $peaks, 'duration' => round($duration, 2)];
  }

}
