<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * Convert HDR video (PQ or HLG, BT.2020) to SDR BT.709, so it doesn't look washed out
 * on regular screens. Uses zscale, which needs an ffmpeg built with libzimg, as most
 * static and distribution builds are.
 */
final readonly class Tonemap implements Filter
{
    /**
     * @param  float  $desaturation  How much bright highlights are desaturated (0 keeps their colour).
     * @param  float  $peak  The nominal peak brightness in nits used for linearisation.
     */
    public function __construct(
        public ToneMapAlgorithm $algorithm = ToneMapAlgorithm::Hable,
        public float $desaturation = 0.0,
        public float $peak = 100.0,
    ) {}

    public function type(): FilterType
    {
        return FilterType::Video;
    }

    public function __toString(): string
    {
        return implode(',', [
            'zscale=t=linear:npl='.Number::format($this->peak),
            'format=gbrpf32le',
            'zscale=p=bt709',
            "tonemap=tonemap={$this->algorithm->value}:desat=".Number::format($this->desaturation),
            'zscale=t=bt709:m=bt709:r=tv',
            'format=yuv420p',
        ]);
    }
}
