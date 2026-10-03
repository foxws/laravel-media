<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use Foxws\Media\Exceptions\InsufficientStorageException;
use Illuminate\Filesystem\Filesystem;

class TemporaryDirectories
{
    /** @var list<string> */
    protected array $directories = [];

    public function __construct(
        protected string $root,
        protected ?string $cacheRoot = null,
        protected int $minFreeBytes = 0,
        protected float $sizeMultiplier = 1.5,
        protected int $cacheMinFreeBytes = 0,
    ) {
        $this->root = rtrim($root, '/');
        $this->cacheRoot = $cacheRoot !== null && $cacheRoot !== '' ? rtrim($cacheRoot, '/') : null;
    }

    /**
     * Create a new temporary directory and return its full path.
     *
     * @param  int  $expectedBytes  Combined size of the job's input files, if known, to check
     *                              there is room for the output on top of the minimum free space.
     *
     * @throws InsufficientStorageException
     */
    public function create(int $expectedBytes = 0): string
    {
        $requiredBytes = $expectedBytes > 0 ? (int) ceil($expectedBytes * $this->sizeMultiplier) : 0;

        $this->ensureSufficientSpace($this->root, max($requiredBytes, $this->minFreeBytes));

        return $this->makeDirectory($this->root);
    }

    /**
     * Create a directory for small files in cache storage (e.g. a RAM disk),
     * falling back to the regular root when no cache root is configured.
     *
     * @throws InsufficientStorageException
     */
    public function createCache(): string
    {
        $root = $this->cacheRoot ?? $this->root;

        $this->ensureSufficientSpace($root, $this->cacheMinFreeBytes);

        return $this->makeDirectory($root);
    }

    public function delete(string $directory): void
    {
        new Filesystem()->deleteDirectory($directory);

        $this->directories = array_values(array_diff($this->directories, [$directory]));
    }

    public function deleteAll(): void
    {
        foreach ($this->directories as $directory) {
            new Filesystem()->deleteDirectory($directory);
        }

        $this->directories = [];
    }

    protected function makeDirectory(string $root): string
    {
        $directory = $root.'/'.bin2hex(random_bytes(8));

        mkdir($directory, 0777, true);

        return $this->directories[] = $directory;
    }

    /**
     * Fail before work starts when a (size-limited) mount doesn't have enough room left.
     */
    protected function ensureSufficientSpace(string $path, int $requiredBytes): void
    {
        if ($requiredBytes <= 0) {
            return;
        }

        $free = @disk_free_space($this->nearestExistingPath($path));

        if ($free === false) {
            return;
        }

        if ($free < $requiredBytes) {
            throw InsufficientStorageException::in($path, (int) $free, $requiredBytes);
        }
    }

    /**
     * disk_free_space() fails for a path that doesn't exist yet, so walk up to the nearest existing parent.
     */
    protected function nearestExistingPath(string $path): string
    {
        while ($path !== '' && $path !== DIRECTORY_SEPARATOR && ! is_dir($path)) {
            $path = dirname($path);
        }

        return $path === '' ? DIRECTORY_SEPARATOR : $path;
    }
}
