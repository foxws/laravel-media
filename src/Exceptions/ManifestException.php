<?php

declare(strict_types=1);

namespace Foxws\Media\Exceptions;

use RuntimeException;

class ManifestException extends RuntimeException
{
    public static function outsideDirectory(string $uri, string $directory): self
    {
        return new self("The manifest references [{$uri}], outside of [{$directory}]. Call within() with the directory of the whole stream if its manifests are nested.");
    }

    public static function notOpened(): self
    {
        return new self('No manifest has been opened. Call open() with its path first.');
    }

    public static function unreadable(string $path): self
    {
        return new self("The manifest [{$path}] doesn't exist on its disk.");
    }

    public static function unprocessable(string $error): self
    {
        return new self("The manifest couldn't be processed: {$error}.");
    }
}
