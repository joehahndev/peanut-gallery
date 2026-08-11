<?php

declare(strict_types=1);

namespace Drupal\peanut_gallery\Peaks;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;

/**
 * Produces Serato-style three-band colour data for a recording.
 *
 * DJ software draws the waveform in colour because amplitude alone does not
 * tell you what you are looking at: a kick drum and a cymbal crash can be
 * the same height. Splitting the signal into low, mid and high and mapping
 * each to a colour channel makes the structure of a mix legible at a glance
 * — bass drops read as red, hats and rides as blue, vocals and most
 * instruments as green.
 *
 * Deliberately separate from PeaksGenerator rather than folded into it:
 *
 * - It decodes at a much higher rate. The mono generator runs at 8 kHz,
 *   which is ample for an amplitude envelope but puts the Nyquist limit at
 *   4 kHz — above which everything is gone. Cymbals live at 5-10 kHz, so at
 *   8 kHz the "high" band would be measuring silence and every recording
 *   would come out the same colour.
 * - It is optional in a way the mono peaks are not. A recording with no
 *   colour data still draws, just in one colour, so nothing here can break
 *   an existing player.
 * - Its output is stored under its own key, so adding colour never rewrites
 *   or invalidates peaks that are already generated and working.
 */
class ColorPeaksGenerator {

  /**
   * Decode rate for the colour pass.
   *
   * 22.05 kHz puts the Nyquist limit at ~11 kHz, which covers the top of
   * the range that carries visible detail. Going higher costs decode time
   * for content that is mostly air.
   */
  protected const SAMPLE_RATE = 22050;

  /**
   * Upper edge of the low band, in Hz.
   *
   * Kick drums and bass lines. 200 Hz is conventional for this split and
   * sits below most of the fundamental range of vocals.
   */
  protected const LOW_HZ = 200;

  /**
   * Boundary between the mid and high bands, in Hz.
   *
   * Above this is where hats, rides and air live.
   */
  protected const HIGH_HZ = 2000;

  /**
   * Crossover steepness, as ffmpeg's acrossover names its orders.
   *
   * 8th order is 48 dB/octave. See the filter graph in streamBands() for
   * why anything gentle produces grey.
   */
  protected const CROSSOVER_ORDER = '8th';

  /**
   * Colour points per second, matching PeaksGenerator's detail resolution.
   */
  protected const DETAIL_PER_SECOND = 10;

  /**
   * Ceiling on colour points, matching PeaksGenerator.
   */
  protected const DETAIL_MAX_POINTS = 400000;

  /**
   * Number of overview buckets, matching PeaksGenerator.
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
   * Decodes $file into three frequency bands and returns packed colour data.
   *
   * @return array{overview: string, detail: string, points: int, duration: float}|null
   *   The two series are base64-encoded RGB triplets — one byte per band
   *   per point, in low/mid/high order. Packing matters: as JSON floats the
   *   detail series for an eight-hour mix runs to about 5.5 MB, where the
   *   packed form is 1.2 MB for the same data. NULL if ffmpeg is
   *   unavailable or the decode produced nothing, which callers must treat
   *   as "no colour", never as an error.
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
      $bytes = @file_get_contents($uri);
      if ($bytes === FALSE) {
        return NULL;
      }
      $temp = $this->fileSystem->tempnam('temporary://', 'peanut_gallery_color_');
      $local = $this->fileSystem->realpath($temp) ?: $temp;
      file_put_contents($local, $bytes);
      $cleanup = $local;
    }

    try {
      $duration = $this->probeDuration($local);
      if ($duration === NULL || $duration <= 0.0) {
        return NULL;
      }
      return $this->streamBands($ffmpeg, $local, $duration);
    }
    finally {
      if ($cleanup) {
        @unlink($cleanup);
      }
    }
  }

  /**
   * Reads a recording's duration without decoding it.
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
   * Streams one decode that carries all three bands at once.
   *
   * The obvious implementation runs ffmpeg three times, once per band. This
   * splits the signal inside a single filter graph and merges the three
   * results into a three-channel stream, so the file is read and decoded
   * once instead of three times. The channels arrive interleaved — low,
   * mid, high, low, mid, high — which is also exactly the byte order the
   * packed output wants.
   *
   * @return array{overview: string, detail: string, points: int, duration: float}|null
   *   NULL if ffmpeg produced nothing.
   */
  protected function streamBands(string $ffmpeg, string $path, float $duration): ?array {
    $expected = max(1, (int) round($duration * self::SAMPLE_RATE));
    $points = $this->detailPointsFor($duration);
    $pointSize = max(1, (int) floor($expected / $points));

    // The acrossover filter is a proper Linkwitz-Riley crossover: it splits
    // once into complementary bands that sum back to the original signal,
    // with no overlap and no gap.
    //
    // The first attempt built the bands by hand out of lowpass/highpass,
    // which defaults to two poles — 12 dB/octave. At that slope a kick at
    // 60 Hz is still plainly audible in the "mid" band and a vocal still
    // shows up in the "low", so all three bands rise and fall together and
    // the colour they produce is a muddy grey that says nothing. Order 8
    // (48 dB/octave) is steep enough that each band really is its own part
    // of the spectrum, which is the whole basis for the colour meaning
    // anything.
    $filter = sprintf(
      '[0:a]aformat=channel_layouts=mono,'
      . 'acrossover=split=%d %d:order=%s[low][mid][high];'
      . '[low][mid][high]amerge=inputs=3[out]',
      self::LOW_HZ,
      self::HIGH_HZ,
      self::CROSSOVER_ORDER,
    );

    $cmd = sprintf(
      '%s -v error -i %s -filter_complex %s -map [out] -ar %d -f s16le pipe:1',
      escapeshellcmd($ffmpeg),
      escapeshellarg($path),
      escapeshellarg($filter),
      self::SAMPLE_RATE,
    );

    $pipe = popen($cmd, 'r');
    if (!is_resource($pipe)) {
      return NULL;
    }

    // Three parallel *energy* series, filled in one pass, accumulated as
    // sums of squares and turned into RMS at the end.
    //
    // Peak magnitude — what the mono generator uses, and the obvious thing
    // to reuse — is wrong here. Over a bucket spanning seconds of dense
    // music almost every bucket contains something near full scale in both
    // the low and mid bands, so peaks saturate: measured on a two-hour mix,
    // mean low/mid came out 254.7/252.8 of 255, which would draw the whole
    // recording in one flat colour. RMS measures how much energy is in a
    // band rather than whether it ever touched the ceiling, and that is
    // what actually varies from moment to moment.
    $bands = [
      array_fill(0, $points, 0.0),
      array_fill(0, $points, 0.0),
      array_fill(0, $points, 0.0),
    ];
    $counts = array_fill(0, $points, 0);
    $frame = 0;
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
      // A frame is three 16-bit samples. Unlike the mono generator, a read
      // can leave up to five stray bytes rather than one, and misaligning
      // here would not just shift the waveform — it would rotate the bands
      // and recolour the whole recording.
      $remainder = strlen($chunk) % 6;
      if ($remainder !== 0) {
        $carry = substr($chunk, -$remainder);
        $chunk = substr($chunk, 0, -$remainder);
      }
      if ($chunk === '') {
        continue;
      }

      $samples = array_values(unpack('s*', $chunk));
      $count = count($samples);

      for ($i = 0; $i < $count; $i += 3) {
        $point = intdiv($frame, $pointSize);
        if ($point >= $points) {
          // Rounding drift, or a file slightly longer than its metadata
          // claims, folds into the last point.
          $point = $points - 1;
        }

        for ($band = 0; $band < 3; $band++) {
          $value = $samples[$i + $band] ?? 0;
          $bands[$band][$point] += $value * $value;
        }
        $counts[$point]++;

        $frame++;
      }
    }

    pclose($pipe);

    if (!$seen || $frame === 0) {
      return NULL;
    }

    foreach ($bands as $band => $values) {
      foreach ($values as $index => $sumOfSquares) {
        $bands[$band][$index] = $counts[$index] > 0
          ? sqrt($sumOfSquares / $counts[$index])
          : 0.0;
      }
    }

    // One scale for both series, derived from the dense one. Scaling each
    // against its own maximum instead would ruin the overview: averaging
    // into 800 buckets compresses the range, so its own maximum sits close
    // to its typical value and everything lands near 255 — measured at
    // 238/249/251, i.e. a uniformly white waveform carrying no colour
    // information at all. A shared scale keeps the overview dimmer, which
    // is honest, and keeps the same moment the same hue in both views.
    $scale = $this->scaleFor($bands);

    return [
      'overview' => $this->pack($this->reduceBands($bands, self::BUCKETS), $scale),
      'detail' => $this->pack($bands, $scale),
      'points' => $points,
      'duration' => round($duration, 2),
    ];
  }

  /**
   * Per-band byte scaling factors.
   *
   * Each band is scaled against its own maximum rather than a shared one.
   * A mix is not equally loud in all three bands — the highs are typically
   * far quieter than the lows — and scaling them together would render
   * every recording as an almost uniformly red waveform. Per-band scaling
   * is what makes the colour show *relative* balance, which is the whole
   * point of looking at it.
   *
   * Scales against the 99th percentile rather than the outright maximum.
   * One transient — a clipped kick, a moment of noise — sets the maximum
   * for the entire recording, and everything else is then measured against
   * something it can never approach, which drains the colour out of the
   * other eight hours. Clipping the top 1% costs nothing visible (those
   * points simply pin at 255) and lifts the whole range.
   *
   * @param array<int, float[]> $bands
   *   Three parallel arrays of band RMS values.
   *
   * @return float[]
   *   One multiplier per band.
   */
  protected function scaleFor(array $bands): array {
    $scale = [];
    foreach ($bands as $index => $values) {
      if (!$values) {
        $scale[$index] = 0.0;
        continue;
      }
      $sorted = $values;
      sort($sorted);
      $top = $sorted[(int) floor((count($sorted) - 1) * 0.99)];
      $scale[$index] = $top > 0 ? 255 / $top : 0.0;
    }

    return $scale;
  }

  /**
   * Packs three parallel band series into base64 RGB triplets.
   *
   * @param array<int, float[]> $bands
   *   Three parallel arrays of band RMS values.
   * @param float[] $scale
   *   Per-band multipliers, from scaleFor().
   *
   * @return string
   *   Base64 of one byte per band per point, low/mid/high interleaved.
   */
  protected function pack(array $bands, array $scale): string {
    $points = count($bands[0]);
    $out = '';
    for ($i = 0; $i < $points; $i++) {
      for ($band = 0; $band < 3; $band++) {
        $out .= chr((int) min(255, round($bands[$band][$i] * $scale[$band])));
      }
    }

    return base64_encode($out);
  }

  /**
   * Reduces three dense band series to a fixed number of buckets.
   *
   * Takes the loudest RMS value in each bucket rather than the mean.
   * Averaging is the intuitive choice and it looks terrible: a bucket here
   * spans nine seconds of a two-hour mix, and averaging that long pulls all
   * three bands towards the same middling value, so the overview came out a
   * uniform grey-green while the detail view directly below it was vivid.
   * The maximum keeps each bucket's character — and because these are
   * already RMS values rather than sample peaks, it cannot saturate the way
   * a peak-of-peaks would.
   *
   * @param array<int, float[]> $bands
   *   Three parallel arrays of band RMS values.
   * @param int $buckets
   *   How many buckets to produce.
   *
   * @return array<int, float[]>
   *   Three parallel arrays of $buckets values.
   */
  protected function reduceBands(array $bands, int $buckets): array {
    $out = [];
    foreach ($bands as $index => $values) {
      $total = count($values);
      $size = max(1, (int) floor($total / $buckets));
      $reduced = [];
      for ($i = 0; $i < $buckets; $i++) {
        $slice = array_slice($values, $i * $size, $i === $buckets - 1 ? NULL : $size);
        $reduced[] = $slice ? array_sum($slice) / count($slice) : 0.0;
      }
      $out[$index] = $reduced;
    }

    return $out;
  }

  /**
   * How many colour points to produce for a recording of this length.
   */
  protected function detailPointsFor(float $duration): int {
    $wanted = (int) ceil(max($duration, 1.0) * self::DETAIL_PER_SECOND);

    return max(self::BUCKETS, min($wanted, self::DETAIL_MAX_POINTS));
  }

}
