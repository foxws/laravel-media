<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Normalise loudness (EBU R128), so clips from different sources sound equally loud.
 * With a measurement from Opener::loudness() it's the second of two passes, which is
 * more accurate and, when the measurement allows it, keeps the dynamics (linear mode).
 */
final readonly class Loudnorm implements Filter
{
    /**
     * @param  float  $integrated  Target integrated loudness in LUFS.
     * @param  float  $truePeak  Maximum true peak in dBTP.
     * @param  float  $range  Target loudness range in LU.
     * @param  ?LoudnessMeasurement  $measurement  The first pass, for two-pass normalisation.
     * @param  ?int  $sampleRate  The sample rate to resample to afterwards, since dynamic mode works at
     *                            192 kHz and encoders would keep that; null keeps loudnorm's rate.
     */
    public function __construct(
        public float $integrated = -16.0,
        public float $truePeak = -1.5,
        public float $range = 11.0,
        public ?LoudnessMeasurement $measurement = null,
        public ?int $sampleRate = 48000,
    ) {}

    /**
     * The same target, normalised with the measurement of a first pass.
     */
    public function withMeasurement(LoudnessMeasurement $measurement): self
    {
        return new self($this->integrated, $this->truePeak, $this->range, $measurement, $this->sampleRate);
    }

    /**
     * Whether loudnorm will apply one gain to the whole stream instead of adjusting it dynamically.
     */
    public function isLinear(): bool
    {
        return $this->measurement?->allowsLinear($this) ?? false;
    }

    public function type(): FilterType
    {
        return FilterType::Audio;
    }

    public function __toString(): string
    {
        $filter = sprintf('loudnorm=I=%s:TP=%s:LRA=%s', Number::format($this->integrated), Number::format($this->truePeak), Number::format($this->range));

        $measurement = $this->measurement;

        if ($measurement !== null && ! $measurement->isSilent()) {
            $filter .= sprintf(
                ':measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true',
                Number::format((float) $measurement->integrated),
                Number::format((float) $measurement->truePeak),
                Number::format($measurement->range),
                Number::format((float) $measurement->threshold),
                Number::format($measurement->targetOffset),
            );
        }

        return $this->sampleRate === null ? $filter : "{$filter},aresample={$this->sampleRate}";
    }
}
