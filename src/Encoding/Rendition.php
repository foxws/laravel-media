<?php

declare(strict_types=1);

namespace Foxws\Media\Encoding;

use InvalidArgumentException;

/**
 * One rung of a ladder: the size of the short side (1080 for 1080p, also for portrait video) and
 * the bitrates to encode it at.
 */
final readonly class Rendition
{
    /**
     * @param  int  $height  The short side in pixels, e.g. 720.
     * @param  int  $bitrate  The target video bitrate in kbit/s.
     * @param  int|null  $maxBitrate  The peak video bitrate in kbit/s; 7% above the target by default.
     * @param  int|null  $bufferSize  The rate control buffer in kbit; twice the target by default.
     */
    public function __construct(
        public int $height,
        public int $bitrate,
        public ?int $maxBitrate = null,
        public ?int $bufferSize = null,
    ) {
        if ($height < 2 || $height % 2 !== 0) {
            throw new InvalidArgumentException("A rendition height must be a positive even number, not [{$height}].");
        }

        if ($bitrate < 1 || ($maxBitrate !== null && $maxBitrate < $bitrate) || ($bufferSize !== null && $bufferSize < 1)) {
            throw new InvalidArgumentException('A rendition needs a positive bitrate, with a peak bitrate and buffer size that are at least as large.');
        }
    }

    public function peakBitrate(): int
    {
        return $this->maxBitrate ?? (int) ceil($this->bitrate * 1.07);
    }

    public function buffer(): int
    {
        return $this->bufferSize ?? $this->bitrate * 2;
    }
}
