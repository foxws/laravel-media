<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

/**
 * The codecs fragmented MP4 segments can carry without re-encoding, by their ffprobe codec name.
 */
enum FragmentedMp4Codec: string
{
    case H264 = 'h264';
    case Hevc = 'hevc';
    case Av1 = 'av1';
    case Vp9 = 'vp9';
    case Aac = 'aac';
    case Mp3 = 'mp3';
    case Ac3 = 'ac3';
    case Eac3 = 'eac3';
    case Opus = 'opus';
    case Flac = 'flac';

    /**
     * Whether a stream with this ffprobe codec name can be copied into fragmented MP4.
     */
    public static function supports(?string $codecName): bool
    {
        return $codecName !== null && self::tryFrom($codecName) !== null;
    }

    /**
     * Whether segments with this codec can be encrypted as they're served. AV1 and VP9 need their
     * frame headers left readable, which takes parsing them.
     */
    public function isEncryptable(): bool
    {
        return ! in_array($this, [self::Av1, self::Vp9], true);
    }
}
