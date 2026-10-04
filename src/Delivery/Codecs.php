<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Probe\Probe;
use Foxws\Media\Probe\Stream;

/**
 * Codec strings (RFC 6381) for the CODECS attribute of HLS variants and the codecs of DASH representations.
 *
 * @internal
 */
final class Codecs
{
    /**
     * The codecs of a file's video and audio, or null when one can't be described, so the attribute is left out.
     */
    public static function for(Probe $probe): ?string
    {
        return self::join([
            ...($probe->videoStream() !== null ? [self::video($probe->videoStream())] : []),
            ...($probe->audioStream() !== null ? [self::audio($probe->audioStream())] : []),
        ]);
    }

    /**
     * @param  list<string|null>  $codecs
     */
    public static function join(array $codecs): ?string
    {
        return $codecs === [] || in_array(null, $codecs, true) ? null : implode(',', $codecs);
    }

    public static function video(Stream $video): ?string
    {
        return match ($video->codecName) {
            'h264' => self::avc($video->get('profile'), $video->get('level')),
            'hevc' => self::hevc($video->get('profile'), $video->get('level')),
            'av1' => self::av1($video->get('profile'), $video->get('level'), $video->get('pix_fmt')),
            default => null,
        };
    }

    public static function audio(Stream $audio): ?string
    {
        return match ($audio->codecName) {
            'aac' => str_contains(strtolower((string) $audio->get('profile', 'LC')), 'he') ? 'mp4a.40.5' : 'mp4a.40.2',
            'mp3' => 'mp4a.40.34',
            'ac3' => 'ac-3',
            'eac3' => 'ec-3',
            'opus' => 'opus',
            'flac' => 'fLaC',
            default => null,
        };
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

    /**
     * HEVC as hvc1 (parameter sets in the sample entry), which fragmented MP4 segments are tagged with.
     */
    protected static function hevc(mixed $profile, mixed $level): ?string
    {
        $profileSpace = match (strtolower((string) $profile)) {
            'main' => '1.6',
            'main 10' => '2.4',
            default => null,
        };

        if ($profileSpace === null || ! is_numeric($level) || (int) $level <= 0) {
            return null;
        }

        return "hvc1.{$profileSpace}.L".(int) $level.'.B0';
    }

    /**
     * AV1 as av01.profile.level+tier.bit depth. ffprobe reports the level as the sequence level index
     * (8 is level 4.0) and leaves out the tier, so the Main tier is assumed.
     */
    protected static function av1(mixed $profile, mixed $level, mixed $pixelFormat): ?string
    {
        $profileIdc = match (strtolower((string) $profile)) {
            'main' => 0,
            'high' => 1,
            'professional' => 2,
            default => null,
        };

        if ($profileIdc === null || ! is_numeric($level) || (int) $level < 0 || (int) $level > 31) {
            return null;
        }

        $bitDepth = preg_match('/p(10|12)(le|be)?$/', (string) $pixelFormat, $matches) === 1 ? (int) $matches[1] : 8;

        return sprintf('av01.%d.%02dM.%02d', $profileIdc, (int) $level, $bitDepth);
    }
}
