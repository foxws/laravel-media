<?php

declare(strict_types=1);

namespace Foxws\Media\Encoding;

use Foxws\Media\Executables\Executable;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filters\Custom;
use Foxws\Media\Filters\Filter;
use Foxws\Media\Filters\Scale;
use Foxws\Media\Probe\VideoStream;
use Foxws\Media\Process\Runner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Throwable;

/**
 * Where a ladder decodes, scales and encodes: on the CPU, or on the GPU with VAAPI (Intel and AMD on
 * Linux), NVENC (NVIDIA) or Quick Sync (Intel). Frames stay on the GPU between those steps. Sources
 * the GPU can't decode, such as AV1 or 10-bit video on many GPUs, or may not, such as those made
 * playable, are decoded on the CPU and uploaded. VAAPI and Quick Sync open the render device in
 * media.ladder.vaapi_device.
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
     * The acceleration set in media.delivery.hardware for renditions encoded on request, or
     * media.ladder.hardware when that's null.
     */
    public static function forDelivery(): self
    {
        $hardware = Config::get('media.delivery.hardware');

        return is_string($hardware) && $hardware !== '' ? self::tryFrom($hardware) ?? self::None : self::configured();
    }

    /**
     * The render device VAAPI and Quick Sync open: renderD128 for the first GPU, renderD129 for
     * a second one, and so on.
     */
    public static function device(): string
    {
        return Config::string('media.ladder.vaapi_device', '/dev/dri/renderD128');
    }

    /**
     * Whether ffmpeg can open the GPU, checked by opening its device once and remembered for five
     * minutes in the media.delivery.cache_store. The CPU always is.
     */
    public function isAvailable(): bool
    {
        if ($this === self::None) {
            return true;
        }

        return (bool) Cache::store(Config::get('media.delivery.cache_store'))->remember(
            "media:hardware:{$this->value}:".hash('xxh128', self::device()),
            300,
            function (): bool {
                try {
                    app(Runner::class)->run(Executable::FFMpeg, [
                        '-hide_banner',
                        '-nostdin',
                        '-loglevel', 'error',
                        ...$this->deviceArguments(),
                        '-f', 'lavfi',
                        '-i', 'nullsrc=s=64x64',
                        '-frames:v', '1',
                        '-f', 'null',
                        '-',
                    ], timeout: 30);

                    return true;
                } catch (Throwable) {
                    return false;
                }
            },
        );
    }

    /**
     * This acceleration when its GPU can be opened, otherwise the CPU, so a missing or inaccessible
     * device only costs speed. The failed check is logged with ffmpeg's error.
     */
    public function orCpu(): self
    {
        return $this->isAvailable() ? $this : self::None;
    }

    /**
     * Whether the GPU decodes a source and scales its frames, checked by decoding and scaling its
     * first frame once per codec, profile and pixel format, and remembered for an hour in the
     * media.delivery.cache_store. When the GPU can't, ffmpeg falls back to frames in memory that
     * the GPU's scale filter refuses, so those sources are decoded on the CPU and uploaded instead.
     */
    public function canDecode(Media $media, VideoStream $source): bool
    {
        if ($this === self::None) {
            return true;
        }

        $kind = [self::device(), $source->codecName, $source->get('profile'), $source->pixelFormat];

        return (bool) Cache::store(Config::get('media.delivery.cache_store'))->remember(
            "media:hardware:{$this->value}:decode:".hash('xxh128', serialize($kind)),
            3600,
            function () use ($media): bool {
                try {
                    app(Runner::class)->run(Executable::FFMpeg, [
                        '-hide_banner',
                        '-nostdin',
                        '-loglevel', 'error',
                        '-xerror',
                        ...$this->inputArguments(),
                        ...$media->inputArguments(),
                        '-i', $media->inputPath(),
                        '-map', '0:v:0',
                        '-frames:v', '1',
                        '-filter:v', (string) $this->scale(-2, 64),
                        '-f', 'null',
                        '-',
                    ], timeout: 60);

                    return true;
                } catch (Throwable) {
                    return false;
                }
            },
        );
    }

    /**
     * @return list<string>
     */
    public function inputArguments(): array
    {
        return match ($this) {
            self::None => [],
            self::Vaapi => ['-hwaccel', 'vaapi', '-hwaccel_output_format', 'vaapi', '-vaapi_device', self::device()],
            self::Nvenc => ['-hwaccel', 'cuda', '-hwaccel_output_format', 'cuda'],
            self::Qsv => [...$this->deviceArguments(), '-hwaccel', 'qsv', '-hwaccel_device', 'hw', '-hwaccel_output_format', 'qsv'],
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
            self::Vaapi => ['-vaapi_device', self::device()],
            self::Qsv => [...$this->deviceArguments(), '-filter_hw_device', 'hw'],
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
     * The arguments that open the GPU as the device named "hw".
     *
     * @return list<string>
     */
    protected function deviceArguments(): array
    {
        return match ($this) {
            self::None => [],
            self::Vaapi => ['-init_hw_device', 'vaapi=hw:'.self::device()],
            self::Nvenc => ['-init_hw_device', 'cuda=hw'],
            self::Qsv => ['-init_hw_device', 'qsv=hw,child_device='.self::device()],
        };
    }

    /**
     * Scale to a size, with -2 for the side that follows the aspect ratio. GPU frames are scaled to
     * 8-bit 4:2:0, which every hardware encoder takes, also from 10-bit sources. Uploaded, frames
     * decoded on the CPU are moved to the GPU first, see uploadArguments().
     */
    public function scale(int $width, int $height, bool $uploaded = false): Filter
    {
        $scale = $this->scaleFilter($width, $height);

        if (! $uploaded || $this === self::None) {
            return $scale;
        }

        $upload = $this === self::Nvenc ? 'format=nv12,hwupload_cuda' : (string) $this->upload();

        return Custom::video("{$upload},{$scale}");
    }

    protected function scaleFilter(int $width, int $height): Filter
    {
        return match ($this) {
            self::None => Scale::to($width > 0 ? $width : null, $height > 0 ? $height : null),
            self::Vaapi => Custom::video("scale_vaapi=w={$width}:h={$height}:format=nv12"),
            self::Nvenc => Custom::video("scale_cuda={$width}:{$height}:format=yuv420p"),
            self::Qsv => Custom::video("scale_qsv=w={$width}:h={$height}:format=nv12"),
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
