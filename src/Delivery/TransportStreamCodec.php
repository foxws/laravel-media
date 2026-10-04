<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

/**
 * The codecs MPEG-TS segments can carry without re-encoding, by their ffprobe codec name.
 */
enum TransportStreamCodec: string
{
    case H264 = 'h264';
    case Hevc = 'hevc';
    case Aac = 'aac';
    case Mp3 = 'mp3';
    case Ac3 = 'ac3';
    case Eac3 = 'eac3';

    /**
     * Whether a stream with this ffprobe codec name can be copied into MPEG-TS.
     */
    public static function supports(?string $codecName): bool
    {
        return $codecName !== null && self::tryFrom($codecName) !== null;
    }
}
