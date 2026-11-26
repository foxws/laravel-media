<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

use Foxws\Media\Exceptions\InvalidMediaException;

/**
 * The loudness of an audio stream, measured by loudnorm's first pass (EBU R128).
 * Values ffmpeg reports as "-inf", as for silence, are null.
 */
final readonly class LoudnessMeasurement
{
    /**
     * @param  ?float  $integrated  Integrated loudness in LUFS.
     * @param  ?float  $truePeak  True peak in dBTP.
     * @param  float  $range  Loudness range in LU.
     * @param  ?float  $threshold  Gating threshold in LUFS.
     * @param  float  $targetOffset  Offset gain in LU that loudnorm applies after its gain, to reach the target.
     */
    public function __construct(
        public ?float $integrated,
        public ?float $truePeak,
        public float $range,
        public ?float $threshold,
        public float $targetOffset = 0.0,
    ) {}

    /**
     * Read the JSON block loudnorm prints with print_format=json from ffmpeg's error output.
     *
     * @throws InvalidMediaException
     */
    public static function fromOutput(string $output): self
    {
        preg_match_all('/\{[^{}]*"input_i"[^{}]*\}/', $output, $matches);

        $values = json_decode((string) end($matches[0]), true);

        if (! is_array($values)) {
            throw InvalidMediaException::noLoudness();
        }

        return new self(
            integrated: self::number($values['input_i'] ?? null),
            truePeak: self::number($values['input_tp'] ?? null),
            range: self::number($values['input_lra'] ?? null) ?? 0.0,
            threshold: self::number($values['input_thresh'] ?? null),
            targetOffset: self::number($values['target_offset'] ?? null) ?? 0.0,
        );
    }

    /**
     * Whether nothing above the gate was measured, e.g. silence. Then there's nothing to normalise.
     */
    public function isSilent(): bool
    {
        return $this->integrated === null || $this->truePeak === null || $this->threshold === null;
    }

    /**
     * Whether loudnorm can reach the target with one gain for the whole stream (linear mode),
     * which keeps the dynamics. It falls back to dynamic mode when the gain would push the
     * true peak over the target, or the measured loudness range is wider than the target's.
     */
    public function allowsLinear(Loudnorm $target): bool
    {
        if ($this->isSilent() || $this->integrated === 0.0 || $this->range === 0.0 || $this->threshold === -70.0) {
            return false;
        }

        $gain = $target->integrated - (float) $this->integrated;

        return (float) $this->truePeak + $gain <= $target->truePeak && $this->range <= $target->range;
    }

    protected static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
