<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

/**
 * The text subtitle codecs ffmpeg converts to WebVTT, by their ffprobe codec name. Bitmap
 * subtitles (PGS, DVD, DVB) can't be converted without OCR.
 */
enum TextSubtitleCodec: string
{
    case WebVtt = 'webvtt';
    case SubRip = 'subrip';
    case MovText = 'mov_text';
    case Ass = 'ass';
    case Ssa = 'ssa';
    case Text = 'text';

    /**
     * Whether a subtitle stream with this ffprobe codec name can be converted to WebVTT.
     */
    public static function supports(?string $codecName): bool
    {
        return $codecName !== null && self::tryFrom($codecName) !== null;
    }
}
