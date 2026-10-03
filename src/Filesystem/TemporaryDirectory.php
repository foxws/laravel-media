<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use Stringable;

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
