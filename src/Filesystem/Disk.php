<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\ForwardsCalls;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * @mixin FilesystemAdapter
 */
class Disk
{
    use ForwardsCalls;

    protected ?FilesystemAdapter $adapter = null;

    public function __construct(protected Filesystem|string $disk) {}

    public static function make(self|Filesystem|string $disk): self
    {
        return $disk instanceof self ? $disk : new self($disk);
    }

    /**
     * A local disk rooted at the given directory.
     */
    public static function local(string $root): self
    {
        return new self(Storage::build([
            'driver' => 'local',
            'root' => $root,
        ]));
    }

    /**
     * The disk name, or a generated name for a disk built at runtime.
     */
    public function name(): string
    {
        if (is_string($this->disk)) {
            return $this->disk;
        }

        return $this->filesystem()->getAdapter()::class.'_'.spl_object_id($this->filesystem());
    }

    public function filesystem(): FilesystemAdapter
    {
        if ($this->adapter) {
            return $this->adapter;
        }

        /** @var FilesystemAdapter $adapter */
        $adapter = is_string($this->disk) ? Storage::disk($this->disk) : $this->disk;

        return $this->adapter = $adapter;
    }

    public function isLocal(): bool
    {
        return $this->filesystem()->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /**
     * Whether temporary URLs can be generated for files on this disk.
     */
    public function providesTemporaryUrls(): bool
    {
        return $this->filesystem()->providesTemporaryUrls();
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiration): string
    {
        return $this->filesystem()->temporaryUrl($path, $expiration);
    }

    /**
     * The full path of a file on the disk, using forward slashes on local disks.
     */
    public function path(string $path): string
    {
        $path = $this->filesystem()->path($path);

        return $this->isLocal() ? str_replace('\\', '/', $path) : $path;
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->filesystem(), $method, $parameters);
    }
}
