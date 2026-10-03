<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use Foxws\Media\Exceptions\TemporaryFileException;
use Illuminate\Filesystem\Filesystem;
use Stringable;
use Throwable;

/**
 * A directory created by TemporaryDirectories for downloads or process output.
 */
final readonly class TemporaryDirectory implements Stringable
{
    public function __construct(
        protected string $root,
        protected TemporaryDirectories $directories,
    ) {}

    /**
     * The full path of the directory, or of a file or folder inside it.
     */
    public function path(string $path = ''): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        return $path === '' ? $this->root : "{$this->root}/{$path}";
    }

    /**
     * Create a folder inside the directory, including its parents, and return its full path.
     */
    public function makeDirectory(string $path): string
    {
        $directory = $this->path($path);

        new Filesystem()->ensureDirectoryExists($directory);

        return $directory;
    }

    /**
     * Write a file inside the directory, creating its folders, and return its full path.
     *
     * @throws TemporaryFileException
     */
    public function put(string $path, string $contents): string
    {
        $file = $this->path($path);

        try {
            $this->makeDirectory(dirname($path));

            $written = new Filesystem()->put($file, $contents, lock: true);
        } catch (Throwable $exception) {
            throw TemporaryFileException::unwritable($file, $exception);
        }

        if ($written === false) {
            throw TemporaryFileException::unwritable($file);
        }

        return $file;
    }

    /**
     * Delete the directory and everything in it.
     */
    public function delete(): void
    {
        $this->directories->delete($this);
    }

    public function __toString(): string
    {
        return $this->root;
    }
}
