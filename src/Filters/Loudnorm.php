<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Normalise loudness (EBU R128), so clips from different sources sound equally loud.
 */
final readonly class Loudnorm implements Filter
{
    /**
     * @param  float  $integrated  Target integrated loudness in LUFS.
     * @param  float  $truePeak  Maximum true peak in dBTP.
     * @param  float  $range  Target loudness range in LU.
     */
    public function __construct(
        public float $integrated = -16.0,
        public float $truePeak = -1.5,
        public float $range = 11.0,
    ) {}

    public function type(): FilterType
    {
        return FilterType::Audio;
    }

    public function __toString(): string
    {
        return sprintf('loudnorm=I=%s:TP=%s:LRA=%s', Number::format($this->integrated), Number::format($this->truePeak), Number::format($this->range));
    }
}
