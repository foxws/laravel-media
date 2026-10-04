<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Probe\Probe;

/**
 * The CODECS attribute of an HLS variant (RFC 6381), for the stream types MPEG-TS carries.
 *
 * @internal
 */
final class HlsCodecs
{
    /**
     * The codecs string, or null when a codec can't be described, so the attribute is left out.
     */
    public static function for(Probe $probe): ?string
    {
        $codecs = [];

        if (($video = $probe->videoStream()) !== null) {
            $codecs[] = match ($video->codecName) {
                'h264' => self::avc($video->get('profile'), $video->get('level')),
                default => null,
            };
        }

        if (($audio = $probe->audioStream()) !== null) {
            $codecs[] = match ($audio->codecName) {
                'aac' => str_contains(strtolower((string) $audio->get('profile', 'LC')), 'he') ? 'mp4a.40.5' : 'mp4a.40.2',
                'mp3' => 'mp4a.40.34',
                'ac3' => 'ac-3',
                'eac3' => 'ec-3',
                default => null,
            };
        }

        return $codecs === [] || in_array(null, $codecs, true) ? null : implode(',', $codecs);
    }

    protected static function avc(mixed $profile, mixed $level): ?string
    {
        $profileIdc = match (strtolower((string) $profile)) {
            'constrained baseline' => '42e0',
            'baseline' => '4200',
            'main' => '4d00',
            'high' => '6400',
            'high 10' => '6e00',
            default => null,
        };

        if ($profileIdc === null || ! is_numeric($level) || (int) $level <= 0) {
            return null;
        }

        return 'avc1.'.$profileIdc.sprintf('%02x', (int) $level);
    }
}
