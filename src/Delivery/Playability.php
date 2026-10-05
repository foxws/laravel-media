<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Encoding\AudioCodec;
use Foxws\Media\Encoding\Format;
use Foxws\Media\Encoding\VideoCodec;
use Foxws\Media\Probe\AudioStream;
use Foxws\Media\Probe\Probe;
use Foxws\Media\Probe\VideoStream;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Whether browsers can play a file through a direct stream, by the codecs in media.playback, and
 * which of its streams have to be re-encoded for that.
 */
final readonly class Playability
{
    protected const array EightBit = ['yuv420p', 'yuvj420p'];

    protected const array TenBit = ['yuv420p10le', 'yuv420p10be'];

    public function __construct(
        public Probe $probe,
    ) {}

    public function isPlayable(): bool
    {
        return ! $this->needsVideoEncoding() && $this->audioNeedingEncoding() === [];
    }

    /**
     * Whether the video, leaving out attached pictures such as cover art, has a codec or pixel
     * format browsers can't decode.
     */
    public function needsVideoEncoding(): bool
    {
        $video = $this->probe->videoStream();

        return $video !== null && ! $this->playsVideo($video);
    }

    /**
     * The positions, among the audio streams, of those browsers can't decode.
     *
     * @return list<int>
     */
    public function audioNeedingEncoding(): array
    {
        $codecs = Config::array('media.playback.audio_codecs');

        return array_keys(array_filter($this->probe->audioStreams(), fn (AudioStream $stream): bool => ! in_array($stream->codecName, $codecs, true)));
    }

    /**
     * The output format that copies the streams that play and re-encodes the others: the video
     * with media.playback.video_codec, and each audio stream that doesn't play as AAC.
     */
    public function format(): Format
    {
        $codec = $this->needsVideoEncoding() ? $this->videoCodec() : VideoCodec::Copy;
        $bitrate = (string) Config::integer('media.playback.audio_bitrate', 192).'k';

        return new Format(
            videoCodec: $codec,
            audioCodec: AudioCodec::Copy,
            crf: $codec === VideoCodec::Copy ? null : $this->crf($codec),
            preset: $codec === VideoCodec::Copy ? null : $this->preset($codec),
            arguments: [
                ...($codec === VideoCodec::H264 ? ['-pix_fmt', 'yuv420p'] : []),
                ...($codec === VideoCodec::Hevc ? ['-tag:v', 'hvc1'] : []),
                ...array_merge(...array_map(fn (int $position): array => ["-c:a:{$position}", AudioCodec::Aac->value, "-b:a:{$position}", $bitrate], $this->audioNeedingEncoding())),
                '-c:s', 'copy',
            ],
        );
    }

    protected function playsVideo(VideoStream $video): bool
    {
        if (! in_array($video->codecName, Config::array('media.playback.video_codecs'), true)) {
            return false;
        }

        if ($video->pixelFormat === null) {
            return true;
        }

        return in_array($video->pixelFormat, $video->codecName === 'h264' ? self::EightBit : [...self::EightBit, ...self::TenBit], true);
    }

    protected function videoCodec(): VideoCodec
    {
        $codec = VideoCodec::tryFrom(Config::string('media.playback.video_codec', VideoCodec::H264->value));

        if (! in_array($codec, [VideoCodec::H264, VideoCodec::Hevc, VideoCodec::Av1], true)) {
            throw new InvalidArgumentException('media.playback.video_codec has to be libx264, libx265 or libsvtav1.');
        }

        return $codec;
    }

    protected function crf(VideoCodec $codec): int
    {
        $crf = Config::get('media.playback.crf');

        if (is_numeric($crf)) {
            return (int) $crf;
        }

        return match ($codec) {
            VideoCodec::Hevc => 24,
            VideoCodec::Av1 => 30,
            default => 20,
        };
    }

    protected function preset(VideoCodec $codec): string|int
    {
        $preset = Config::get('media.playback.preset');

        if (is_string($preset) && $preset !== '') {
            return $codec === VideoCodec::Av1 ? (int) $preset : $preset;
        }

        return $codec === VideoCodec::Av1 ? 8 : 'medium';
    }
}
