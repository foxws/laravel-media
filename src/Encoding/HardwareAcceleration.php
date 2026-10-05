<?php

declare(strict_types=1);

namespace Foxws\Media\Encoding;

use Foxws\Media\Filters\Custom;
use Foxws\Media\Filters\Filter;
use Foxws\Media\Filters\Scale;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Where a ladder decodes, scales and encodes: on the CPU, or on the GPU with VAAPI (Intel and AMD on
 * Linux), NVENC (NVIDIA) or Quick Sync (Intel). Frames stay on the GPU between those steps. Sources
 * the GPU may not decode, such as those made playable, are decoded on the CPU and uploaded.
 */
enum HardwareAcceleration: string
{
    case None = 'none';
    case Vaapi = 'vaapi';
    case Nvenc = 'nvenc';
    case Qsv = 'qsv';

    /**
     * The acceleration set in media.ladder.hardware.
     */
    public static function configured(): self
    {
        return self::tryFrom(Config::string('media.ladder.hardware', 'none')) ?? self::None;
    }

    /**
     * @return list<string>
     */
    public function inputArguments(): array
    {
        return match ($this) {
            self::None => [],
            self::Vaapi => ['-hwaccel', 'vaapi', '-hwaccel_output_format', 'vaapi', '-vaapi_device', Config::string('media.ladder.vaapi_device', '/dev/dri/renderD128')],
            self::Nvenc => ['-hwaccel', 'cuda', '-hwaccel_output_format', 'cuda'],
            self::Qsv => ['-hwaccel', 'qsv', '-hwaccel_output_format', 'qsv'],
        };
    }

    /**
     * The arguments to encode frames decoded on the CPU, placed before the input.
     *
     * @return list<string>
     */
    public function uploadArguments(): array
    {
        return match ($this) {
            self::None, self::Nvenc => [],
            self::Vaapi => ['-vaapi_device', Config::string('media.ladder.vaapi_device', '/dev/dri/renderD128')],
            self::Qsv => ['-init_hw_device', 'qsv=hw', '-filter_hw_device', 'hw'],
        };
    }

    /**
     * The filter that moves frames decoded on the CPU to the GPU as 8-bit 4:2:0, or null when the
     * encoder takes them from memory.
     */
    public function upload(): ?Filter
    {
        return match ($this) {
            self::None, self::Nvenc => null,
            self::Vaapi => Custom::video('format=nv12,hwupload'),
            self::Qsv => Custom::video('format=nv12,hwupload=extra_hw_frames=64'),
        };
    }

    /**
     * Constant quality in the encoder's own terms, lower is better: the CRF on the CPU, constant QP
     * with VAAPI, NVENC's CQ and Quick Sync's global quality.
     *
     * @return list<string>
     */
    public function quality(int $quality): array
    {
        return match ($this) {
            self::None => ['-crf', (string) $quality],
            self::Vaapi => ['-rc_mode', 'CQP', '-qp', (string) $quality],
            self::Nvenc => ['-rc', 'vbr', '-cq', (string) $quality, '-b:v', '0'],
            self::Qsv => ['-global_quality', (string) $quality],
        };
    }

    /**
     * Scale to a size, with -2 for the side that follows the aspect ratio.
     */
    public function scale(int $width, int $height): Filter
    {
        return match ($this) {
            self::None => Scale::to($width > 0 ? $width : null, $height > 0 ? $height : null),
            self::Vaapi => Custom::video("scale_vaapi=w={$width}:h={$height}"),
            self::Nvenc => Custom::video("scale_cuda={$width}:{$height}"),
            self::Qsv => Custom::video("scale_qsv=w={$width}:h={$height}"),
        };
    }

    /**
     * The ffmpeg encoder for a codec.
     *
     * @throws InvalidArgumentException
     */
    public function encoder(VideoCodec $codec): string
    {
        if ($this === self::None) {
            return $codec->value;
        }

        $name = match ($codec) {
            VideoCodec::H264 => 'h264',
            VideoCodec::Hevc => 'hevc',
            VideoCodec::Av1 => 'av1',
            default => throw new InvalidArgumentException("[{$codec->value}] has no {$this->value} encoder. Use H.264, HEVC or AV1."),
        };

        return "{$name}_{$this->value}";
    }
}
