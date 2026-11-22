<?php

declare(strict_types=1);

namespace Foxws\Media\Http;

use Closure;
use Foxws\Media\Exceptions\ManifestException;
use Foxws\Media\Filesystem\Disk;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * A streaming manifest read from a disk and rewritten per request, so every URI in it can
 * point to a short-lived signed URL while the segments themselves stay private.
 */
abstract class Manifest implements Responsable
{
    protected Disk $disk;

    protected ?string $path = null;

    /** @var array<string, Closure(string): string> */
    protected array $resolvers = [];

    /** @var array<string, array<string, string>> */
    protected array $resolved = [];

    protected ?string $directory = null;

    public function __construct(Disk|Filesystem|string|null $disk = null)
    {
        $this->fromDisk($disk ?? Config::string('filesystems.default'));
    }

    /**
     * The manifest's content type.
     */
    abstract protected function contentType(): string;

    /**
     * The rewritten manifest.
     */
    abstract public function get(): string;

    public function fromDisk(Disk|Filesystem|string $disk): static
    {
        $this->disk = Disk::make($disk);
        $this->resolved = [];

        return $this;
    }

    /**
     * Open the manifest at the path on the disk.
     */
    public function open(string $path): static
    {
        $this->path = ltrim(str_replace('\\', '/', $path), '/');
        $this->resolved = [];

        return $this;
    }

    /**
     * The directory on the disk the manifest's URIs must stay in, instead of the manifest's own,
     * e.g. the stream's directory when opening "videos/1/audio/main.m3u8", which links to "../key".
     */
    public function within(string $directory): static
    {
        $this->directory = trim(str_replace('\\', '/', $directory), '/');
        $this->resolved = [];

        return $this;
    }

    public function disk(): Disk
    {
        return $this->disk;
    }

    public function path(): string
    {
        return $this->path ?? throw ManifestException::notOpened();
    }

    public function toResponse($request): Response
    {
        return ResponseFactory::make($this->get(), 200, ['Content-Type' => $this->contentType()]);
    }

    /**
     * @param  callable(string): string  $resolver
     */
    protected function resolveUsing(string $type, callable $resolver): static
    {
        $this->resolvers[$type] = $resolver(...);
        $this->resolved[$type] = [];

        return $this;
    }

    /**
     * Resolve a URI from the manifest at $from. Resolvers receive the file's path on the disk,
     * e.g. "videos/1/video_0.mp4", so they can sign it directly. URIs stay as they are without a
     * resolver, and absolute URLs are never touched.
     */
    protected function resolve(string $type, string $uri, ?string $from = null): string
    {
        $resolver = $this->resolvers[$type] ?? null;

        if ($resolver === null || preg_match('#^[a-z][a-z0-9+.-]*://#i', $uri) === 1) {
            return $uri;
        }

        $path = $this->pathFrom($from ?? $this->path(), $uri);

        return $this->resolved[$type][$path] ??= $resolver($path);
    }

    /**
     * The disk path of a URI relative to the file that contains it. Resolvers sign what this
     * returns, so it may not leave the manifest's directory.
     *
     * @throws ManifestException
     */
    protected function pathFrom(string $file, string $uri): string
    {
        // Flysystem reads backslashes as separators, so they count as separators here too.
        $uri = str_replace('\\', '/', strtok($uri, '?#') ?: $uri);
        $directory = $this->directory ?? $this->directoryOf($this->path ?? $file);

        $segments = [];

        foreach (explode('/', "{$this->directoryOf($file)}/{$uri}") as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        $path = implode('/', $segments);

        if ($directory !== '' && ! str_starts_with($path, "{$directory}/")) {
            throw ManifestException::outsideDirectory($uri, $directory);
        }

        return $path;
    }

    protected function directoryOf(string $file): string
    {
        $directory = dirname($file);

        return $directory === '.' ? '' : trim($directory, '/');
    }

    /**
     * The contents of a file on the disk.
     */
    protected function read(string $path): string
    {
        // Opened paths often end in a route parameter, which could climb out of the stream's directory.
        if (in_array('..', explode('/', $path), true)) {
            throw ManifestException::outsideDirectory($path, $this->directory ?? $this->directoryOf($path));
        }

        return $this->disk->get($path) ?? throw ManifestException::unreadable($path);
    }

    /**
     * @param  callable(array<int|string, string>): string  $callback
     */
    protected function replace(string $pattern, callable $callback, string $subject): string
    {
        return preg_replace_callback($pattern, $callback, $subject) ?? throw ManifestException::unprocessable(preg_last_error_msg());
    }
}
