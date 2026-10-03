<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use Aws\S3\S3Client;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\ForwardsCalls;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathPrefixer;
use ReflectionProperty;

/**
 * @mixin FilesystemAdapter
 */
class Disk
{
    use ForwardsCalls;

    protected ?FilesystemAdapter $adapter = null;

    protected ?PathPrefixer $s3PathPrefixer = null;

    /** @var array<string, mixed>|null */
    protected ?array $s3UploadOptions = null;

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
     * Whether the disk uses Laravel's S3 driver, which requires league/flysystem-aws-s3-v3.
     */
    public function isS3(): bool
    {
        return class_exists(AwsS3V3Adapter::class) && $this->filesystem() instanceof AwsS3V3Adapter;
    }

    /**
     * The AWS client of an S3 disk.
     */
    public function s3Client(): S3Client
    {
        /** @var AwsS3V3Adapter $adapter */
        $adapter = $this->filesystem();

        return $adapter->getClient();
    }

    /**
     * The bucket of an S3 disk.
     */
    public function s3Bucket(): string
    {
        return (string) new ReflectionProperty($this->filesystem()->getAdapter(), 'bucket')->getValue($this->filesystem()->getAdapter());
    }

    /**
     * The object key Flysystem would use for the path, including the disk's root prefix.
     */
    public function prefixS3Path(string $path): string
    {
        return $this->s3PathPrefixer()->prefixPath($path);
    }

    protected function s3PathPrefixer(): PathPrefixer
    {
        if ($this->s3PathPrefixer) {
            return $this->s3PathPrefixer;
        }

        $prefixer = new ReflectionProperty($this->filesystem()->getAdapter(), 'prefixer')->getValue($this->filesystem()->getAdapter());

        return $this->s3PathPrefixer = $prefixer instanceof PathPrefixer ? $prefixer : new PathPrefixer('');
    }

    /**
     * The disk's default object options (e.g. CacheControl), so direct uploads match writeStream().
     *
     * @return array<string, mixed>
     */
    public function s3UploadOptions(): array
    {
        return $this->s3UploadOptions ??= (array) new ReflectionProperty($this->filesystem()->getAdapter(), 'options')->getValue($this->filesystem()->getAdapter());
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
