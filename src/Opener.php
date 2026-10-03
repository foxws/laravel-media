<?php

declare(strict_types=1);

namespace Foxws\Media;

use Foxws\Media\Exceptions\MediaNotFoundException;
use Foxws\Media\FFMpeg\Builder;
use Foxws\Media\FFMpeg\Scene;
use Foxws\Media\FFMpeg\SceneDetector;
use Foxws\Media\FFMpeg\Thumbnails;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Probe\Probe;
use Foxws\Media\Probe\Prober;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * One or more opened media files on a disk.
 */
class Opener
{
    /** @var array<string, Media> */
    protected array $media = [];

    /** @var array<string, Probe> */
    protected array $probes = [];

    /** @var array<string, list<Scene>> */
    protected array $scenes = [];

    public function __construct(
        protected Disk $disk,
        protected TemporaryDirectories $directories,
        protected Prober $prober,
    ) {}

    public function fromDisk(Disk|Filesystem|string $disk): static
    {
        $this->disk = Disk::make($disk);

        return $this;
    }

    /**
     * @param  string|list<string>  ...$paths
     */
    public function open(string|array ...$paths): static
    {
        foreach (array_merge(...array_map(fn (string|array $path): array => (array) $path, $paths)) as $path) {
            $this->media[$path] = new Media($this->disk, $path, $this->directories);
        }

        return $this;
    }

    /**
     * The disk media is opened from.
     */
    public function disk(): Disk
    {
        return $this->disk;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->media);
    }

    /**
     * @return list<Media>
     *
     * @throws MediaNotFoundException
     */
    public function media(): array
    {
        return $this->media !== [] ? array_values($this->media) : throw MediaNotFoundException::noPaths();
    }

    /**
     * An opened file (the first one by default).
     *
     * @throws MediaNotFoundException
     */
    public function mediaFor(?string $path = null): Media
    {
        return $path !== null
            ? ($this->media[$path] ?? throw MediaNotFoundException::unreadable($path))
            : $this->media()[0];
    }

    /**
     * Probe an opened file (the first one by default). Results are cached on this opener.
     *
     * @throws MediaNotFoundException
     */
    public function probe(?string $path = null): Probe
    {
        $media = $this->mediaFor($path);

        return $this->probes[$media->path()] ??= $this->prober->probe($media);
    }

    /**
     * Probe every opened file, keyed by path.
     *
     * @return array<string, Probe>
     */
    public function probeAll(): array
    {
        $probes = [];

        foreach ($this->paths() as $path) {
            $probes[$path] = $this->probe($path);
        }

        return $probes;
    }

    /**
     * Build an ffmpeg command with the opened files as inputs.
     */
    public function ffmpeg(): Builder
    {
        return app(Builder::class, ['opener' => $this]);
    }

    /**
     * Find the scenes of an opened video (the first one by default), split where the
     * picture changes by at least the threshold (0-1). Results are cached on this opener.
     *
     * @return list<Scene>
     */
    public function scenes(float $threshold = 0.3, ?string $path = null): array
    {
        $media = $this->mediaFor($path);

        return $this->scenes[$media->path().'@'.$threshold] ??= SceneDetector::make()->detect(
            $media,
            $this->probe($media->path())->duration(),
            $threshold,
        );
    }

    /**
     * Sample the first opened video into thumbnail sprite sheets with a WebVTT file.
     */
    public function thumbnails(): Thumbnails
    {
        return new Thumbnails($this);
    }

    /**
     * Delete local copies made of files on remote disks.
     */
    public function cleanupTemporaryFiles(): void
    {
        foreach ($this->media as $media) {
            $media->cleanup();
        }
    }
}
