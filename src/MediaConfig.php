<?php

declare(strict_types=1);

namespace Foxws\Media;

use Foxws\Media\Executables\Executable;

/**
 * The package configuration, read once from config/media.php by the service provider.
 */
final readonly class MediaConfig
{
    /**
     * @param  array<string, string>  $executables  Paths or command names, keyed by executable.
     * @param  string|false|null  $logChannel  A log channel, null for the default channel, or false for none.
     */
    public function __construct(
        public string $disk,
        public array $executables = [],
        public int $timeout = 14400,
        public string $ffmpegLogLevel = 'error',
        public string|false|null $logChannel = null,
        public bool $remoteInputs = true,
        public int $remoteInputUrlLifetime = 3600,
        public string $temporaryRoot = '/tmp/media',
        public ?string $cacheRoot = null,
        public int $temporaryMinFree = 0,
        public float $temporarySizeMultiplier = 1.5,
        public int $cacheMinFree = 0,
        public int $uploadConcurrency = 10,
        public int $multipartThreshold = 64 * 1024 * 1024,
        public int $multipartPartSize = 16 * 1024 * 1024,
        public int $multipartConcurrency = 5,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The "media" config array.
     * @param  string  $defaultDisk  The disk to use when the media config doesn't name one.
     */
    public static function fromArray(array $config, string $defaultDisk): self
    {
        $value = fn (string $key, mixed $default = null): mixed => data_get($config, $key, $default);

        $string = fn (string $key, ?string $default = null): ?string => is_string($value($key)) && $value($key) !== '' ? $value($key) : $default;

        $logChannel = $value('log_channel');

        /** @var array<string, string> $executables */
        $executables = array_filter((array) $value('executables', []), fn (mixed $path): bool => is_string($path) && $path !== '');

        return new self(
            disk: $string('disk', $defaultDisk) ?? $defaultDisk,
            executables: $executables,
            timeout: (int) $value('timeout', 14400),
            ffmpegLogLevel: $string('ffmpeg_log_level', 'error') ?? 'error',
            logChannel: $logChannel === false || $logChannel === 'false' ? false : (is_string($logChannel) && $logChannel !== '' ? $logChannel : null),
            remoteInputs: (bool) $value('remote_inputs.enabled', true),
            remoteInputUrlLifetime: (int) $value('remote_inputs.url_lifetime', 3600),
            temporaryRoot: $string('temporary_files.root', sys_get_temp_dir().'/media') ?? sys_get_temp_dir().'/media',
            cacheRoot: $string('temporary_files.cache_root'),
            temporaryMinFree: (int) $value('temporary_files.min_free', 0),
            temporarySizeMultiplier: (float) $value('temporary_files.size_multiplier', 1.5),
            cacheMinFree: (int) $value('temporary_files.cache_min_free', 0),
            uploadConcurrency: (int) $value('uploads.concurrency', 10),
            multipartThreshold: (int) $value('uploads.multipart_threshold', 64 * 1024 * 1024),
            multipartPartSize: (int) $value('uploads.multipart_part_size', 16 * 1024 * 1024),
            multipartConcurrency: (int) $value('uploads.multipart_concurrency', 5),
        );
    }

    /**
     * The configured path or command name of the executable, or its own name.
     */
    public function executable(Executable $executable): string
    {
        return $this->executables[$executable->value] ?? $executable->value;
    }

    /**
     * The temporary roots that downloads and outputs are written to.
     *
     * @return list<string>
     */
    public function temporaryRoots(): array
    {
        return array_values(array_filter([$this->temporaryRoot, $this->cacheRoot]));
    }
}
