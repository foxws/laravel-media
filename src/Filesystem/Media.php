<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use Foxws\Media\Exceptions\MediaNotFoundException;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * A file on a disk that executables can read.
 */
class Media
{
    protected ?Disk $temporaryDisk = null;

    protected ?TemporaryDirectory $temporaryDirectory = null;

    public function __construct(
        protected Disk $disk,
        protected string $path,
        protected TemporaryDirectories $directories,
    ) {}

    public function disk(): Disk
    {
        return $this->disk;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function filename(): string
    {
        return pathinfo($this->path, PATHINFO_BASENAME);
    }

    public function extension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }

    /**
     * The path of the file on the local filesystem, downloading it from a
     * remote disk to a temporary directory the first time it is needed.
     *
     * @throws MediaNotFoundException
     */
    public function localPath(): string
    {
        if ($this->disk->isLocal()) {
            return $this->disk->path($this->path);
        }

        $temporaryDisk = $this->temporaryDisk();

        if (! $temporaryDisk->exists($this->path)) {
            $stream = $this->disk->readStream($this->path)
                ?? throw MediaNotFoundException::unreadable($this->path);

            $temporaryDisk->writeStream($this->path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $temporaryDisk->path($this->path);
    }

    /**
     * The input executables should read: a short-lived signed URL for remote
     * disks that support them (when enabled), otherwise a local path.
     */
    public function inputPath(): string
    {
        if ($this->readsFromUrl()) {
            return $this->disk->temporaryUrl(
                $this->path,
                now()->addSeconds(Config::integer('media.remote_inputs.url_lifetime', 3600)),
            );
        }

        return $this->localPath();
    }

    /**
     * The protocol options for reading inputPath() over HTTPS: trusting a CA
     * file, or skipping certificate verification, for storage behind a
     * private or self-signed certificate. Empty for other inputs, as ffmpeg
     * rejects these options for local files.
     *
     * @return array<string, string>
     */
    public function inputOptions(): array
    {
        if (! $this->readsFromUrl() || ! str_starts_with($this->inputPath(), 'https://')) {
            return [];
        }

        if (! Config::boolean('media.remote_inputs.verify_tls', true)) {
            return ['tls_verify' => '0'];
        }

        $caFile = Config::get('media.remote_inputs.ca_file');

        return is_string($caFile) && $caFile !== '' ? ['ca_file' => $caFile] : [];
    }

    /**
     * The input options as ffmpeg and ffprobe arguments, to put before the input.
     *
     * @return list<string>
     */
    public function inputArguments(): array
    {
        $arguments = [];

        foreach ($this->inputOptions() as $option => $value) {
            array_push($arguments, "-{$option}", $value);
        }

        return $arguments;
    }

    protected function readsFromUrl(): bool
    {
        return ! $this->disk->isLocal()
            && Config::boolean('media.remote_inputs.enabled', true)
            && $this->disk->providesTemporaryUrls();
    }

    /**
     * The size of the file in bytes, or 0 when it can't be determined.
     */
    public function size(): int
    {
        try {
            return (int) $this->disk->size($this->path);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * A key that changes when the file changes: its disk, path, size and modification time.
     */
    public function versionKey(): string
    {
        try {
            $modified = $this->disk->lastModified($this->path);
        } catch (Throwable) {
            $modified = 0;
        }

        return hash('xxh128', implode('|', [$this->disk->name(), $this->path, $this->size(), $modified]));
    }

    /**
     * Delete the local copy of a remote file, if one was made.
     */
    public function cleanup(): void
    {
        $this->temporaryDirectory?->delete();

        $this->temporaryDirectory = null;
        $this->temporaryDisk = null;
    }

    protected function temporaryDisk(): Disk
    {
        if ($this->temporaryDisk) {
            return $this->temporaryDisk;
        }

        $this->temporaryDirectory = $this->directories->create($this->size());

        return $this->temporaryDisk = Disk::local($this->temporaryDirectory->path());
    }
}
