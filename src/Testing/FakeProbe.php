<?php

declare(strict_types=1);

namespace Foxws\Media\Testing;

/**
 * Builds ffprobe output for Media::fake().
 */
final class FakeProbe
{
    /**
     * A video file, by default a minute of 1080p H.264 with stereo AAC.
     *
     * @param  list<string>  $subtitles  Languages of subtitle tracks, e.g. ['eng', 'nld'].
     * @param  string|null  $transfer  A colour transfer such as "smpte2084" to make the video HDR.
     * @return array<string, mixed>
     */
    public static function video(
        float $duration = 60.0,
        int $width = 1920,
        int $height = 1080,
        string $codec = 'h264',
        bool $audio = true,
        array $subtitles = [],
        ?string $transfer = null,
        float $frameRate = 30.0,
    ): array {
        $streams = [[
            'index' => 0,
            'codec_type' => 'video',
            'codec_name' => $codec,
            'width' => $width,
            'height' => $height,
            'pix_fmt' => 'yuv420p',
            'avg_frame_rate' => "{$frameRate}/1",
            'duration' => (string) $duration,
            ...($transfer !== null ? ['color_transfer' => $transfer, 'color_primaries' => 'bt2020', 'color_space' => 'bt2020nc'] : []),
        ]];

        if ($audio) {
            $streams[] = self::audioStream(count($streams), $duration);
        }

        foreach ($subtitles as $language) {
            $streams[] = ['index' => count($streams), 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'tags' => ['language' => $language]];
        }

        return self::file($streams, $duration, 'mov,mp4,m4a,3gp,3g2,mj2');
    }

    /**
     * An audio-only file, by default a minute of stereo AAC.
     *
     * @return array<string, mixed>
     */
    public static function audio(float $duration = 60.0, string $codec = 'aac'): array
    {
        return self::file([self::audioStream(0, $duration, $codec)], $duration, 'mov,mp4,m4a,3gp,3g2,mj2');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function audioStream(int $index, float $duration, string $codec = 'aac'): array
    {
        return [
            'index' => $index,
            'codec_type' => 'audio',
            'codec_name' => $codec,
            'sample_rate' => '48000',
            'channels' => 2,
            'channel_layout' => 'stereo',
            'duration' => (string) $duration,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $streams
     * @return array<string, mixed>
     */
    protected static function file(array $streams, float $duration, string $format): array
    {
        return [
            'streams' => $streams,
            'chapters' => [],
            'format' => ['format_name' => $format, 'duration' => (string) $duration],
        ];
    }
}
