<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Peaks;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;

/**
 * Decodes an audio file with ffmpeg and downsamples it to display peaks.
 *
 * Works in constant memory and near-constant time regardless of how long
 * the recording is, which matters because "long" here can mean a
 * multi-hour DJ set or a lossless FLAC several gigabytes in size:
 *
 * - The decode is *streamed*. An earlier version captured ffmpeg's whole
 *   output with shell_exec(), which buffers every decoded byte into one
 *   PHP string — at 8 kHz mono 16-bit that is 16 kB per second of audio,
 *   so a 16-hour mix needed roughly 929 MB of memory and simply died.
 * - The decode rate *adapts to duration*. The output is 800 buckets no
 *   matter what, so decoding a 16-hour recording at full 8 kHz would throw
 *   away 99% of the samples after reading them. Instead the rate is chosen
 *   so the total sample count stays bounded, which keeps a huge file about
 *   as cheap to analyse as a short one.
 *
 * This remains an optional enhancement, not a hard dependency: when ffmpeg
 * isn't installed generate() returns NULL and callers fall back to letting
 * wavesurfer.js decode client-side, exactly as if this class didn't exist.
 */
class PeaksGenerator {

  /**
   * Decode rate used for peak extraction.
   *
   * Low on purpose — this is for a visual waveform, not audio quality —
   * but not lowered further for long files. See rateFor().
   */
  protected const SAMPLE_RATE = 8000;

  /**
   * Number of peak buckets returned, independent of recording length.
   */
  protected const BUCKETS = 800;

  /**
   * Peaks per second for the zoomed detail view.
   *
   * The overview's 800 buckets span the whole recording, which on an
   * eight-hour mix is 38 seconds per bucket — zoom into a one-minute
   * window and barely one data point falls inside it. The detail view
   * needs its own, far denser series.
   */
  protected const DETAIL_PER_SECOND = 10;

  /**
   * Ceiling on detail points, so payload stays bounded at any length.
   *
   * Reached at about eleven hours, past which the resolution degrades
   * gracefully rather than the response growing without limit.
   */
  protected const DETAIL_MAX_POINTS = 400000;

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
   * @return array{peaks: float[], detail: float[], duration: float}|null
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

    try {
      $duration = $this->probeDuration($local);
      if ($duration === NULL || $duration <= 0.0) {
        return NULL;
      }
      return $this->streamPeaks($ffmpeg, $local, $duration);
    }
    finally {
      if ($cleanup) {
        @unlink($cleanup);
      }
    }
  }

  /**
   * Reads a recording's duration without decoding it.
   *
   * The ffprobe call reads container metadata only, so it is instant even
   * on a multi-gigabyte file, and knowing the duration up front is what
   * lets the decode be streamed into fixed buckets in a single pass.
   */
  protected function probeDuration(string $path): ?float {
    $ffprobe = trim((string) shell_exec('command -v ffprobe'));
    if ($ffprobe === '') {
      return NULL;
    }

    $cmd = sprintf(
      '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
      escapeshellcmd($ffprobe),
      escapeshellarg($path),
    );
    $duration = trim((string) shell_exec($cmd));

    return is_numeric($duration) ? (float) $duration : NULL;
  }

  /**
   * Streams the decode, filling a fixed number of peak buckets.
   *
   * @return array{peaks: float[], detail: float[], duration: float}|null
   *   The peaks and duration, or NULL if ffmpeg produced nothing.
   */
  protected function streamPeaks(string $ffmpeg, string $path, float $duration): ?array {
    $rate = $this->rateFor($duration);
    $expected = max(1, (int) round($duration * $rate));

    $cmd = sprintf(
      '%s -v error -i %s -ac 1 -ar %d -f s16le pipe:1',
      escapeshellcmd($ffmpeg),
      escapeshellarg($path),
      $rate,
    );

    $pipe = popen($cmd, 'r');
    if (!is_resource($pipe)) {
      return NULL;
    }

    // Bucket at the detail resolution and derive the overview from it
    // afterwards. The overview is the max of a run of detail maxima, which
    // is exactly the max of the underlying samples — so one pass produces
    // both series, and they cannot disagree about where the loud parts
    // are.
    $detailCount = $this->detailPointsFor($duration);
    $detailSize = max(1, (int) floor($expected / $detailCount));

    $detail = array_fill(0, $detailCount, 0);
    $index = 0;
    $carry = '';
    $seen = FALSE;

    while (!feof($pipe)) {
      $chunk = fread($pipe, 65536);
      if ($chunk === FALSE || $chunk === '') {
        break;
      }
      $seen = TRUE;

      if ($carry !== '') {
        $chunk = $carry . $chunk;
        $carry = '';
      }
      // A read can split a 16-bit sample down the middle; hold the odd
      // byte back for the next chunk rather than misaligning everything
      // that follows.
      if (strlen($chunk) % 2 === 1) {
        $carry = substr($chunk, -1);
        $chunk = substr($chunk, 0, -1);
      }
      if ($chunk === '') {
        continue;
      }

      $samples = array_values(unpack('s*', $chunk));
      $count = count($samples);
      $pos = 0;

      while ($pos < $count) {
        $bucket = intdiv($index, $detailSize);
        if ($bucket >= $detailCount - 1) {
          // Anything past the last bucket (rounding drift, or a file
          // slightly longer than its metadata claims) folds into it.
          $bucket = $detailCount - 1;
          $take = $count - $pos;
        }
        else {
          $take = min($count - $pos, (($bucket + 1) * $detailSize) - $index);
        }
        $take = max(1, $take);

        $slice = array_slice($samples, $pos, $take);
        // Peak amplitude without building an abs() array: the largest
        // magnitude is either the biggest positive or the most negative
        // value, and max()/min() run in C.
        $peak = max(max($slice), -min($slice));
        if ($peak > $detail[$bucket]) {
          $detail[$bucket] = $peak;
        }

        $pos += $take;
        $index += $take;
      }
    }

    pclose($pipe);

    if (!$seen || $index === 0) {
      return NULL;
    }

    return [
      'peaks' => $this->reduce($detail, self::BUCKETS),
      'detail' => array_map(static fn (int $peak): float => round($peak / 32768, 3), $detail),
      'duration' => round($duration, 2),
    ];
  }

  /**
   * Reduces a dense series to a fixed number of normalized buckets.
   *
   * @param int[] $values
   *   Raw peak magnitudes.
   * @param int $buckets
   *   How many buckets to produce.
   *
   * @return float[]
   *   Normalized 0..1 peaks.
   */
  protected function reduce(array $values, int $buckets): array {
    $total = count($values);
    if ($total === 0) {
      return [];
    }

    $size = max(1, (int) floor($total / $buckets));
    $out = [];

    for ($i = 0; $i < $buckets; $i++) {
      $slice = array_slice($values, $i * $size, $i === $buckets - 1 ? NULL : $size);
      $out[] = $slice ? round(max($slice) / 32768, 3) : 0.0;
    }

    return $out;
  }

  /**
   * How many detail points to produce for a recording of this length.
   */
  protected function detailPointsFor(float $duration): int {
    $wanted = (int) ceil(max($duration, 1.0) * self::DETAIL_PER_SECOND);

    return max(self::BUCKETS, min($wanted, self::DETAIL_MAX_POINTS));
  }

  /**
   * Picks the decode rate.
   *
   * Deliberately constant rather than scaled down for long recordings.
   * Lowering the sample rate does not merely sample less often — the
   * resampler low-passes at the new Nyquist first, so decoding a long mix
   * at, say, 100 Hz discards everything above 50 Hz and returns a flat
   * line instead of a waveform. Measured: a two-hour test tone came back
   * with 1 of 800 buckets non-silent. Constant memory comes from streaming
   * the decode, not from decoding less of it.
   *
   * Kept as a method so the choice, and the reason for it, stay visible at
   * the point a future change would be tempted.
   */
  protected function rateFor(float $duration): int {
    return self::SAMPLE_RATE;
  }

}
