<?php

declare(strict_types=1);

namespace Foxws\Media\Packaging;

use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Filesystem\TemporaryDirectory;

/**
 * Packages already-encoded streams into DASH and HLS, e.g. with Shaka Packager.
 */
interface Packager
{
    /**
     * Write the packaged segments and manifests of the spec into the directory.
     *
     * @throws ProcessFailedException
     */
    public function package(PackagingSpec $spec, TemporaryDirectory $directory, ?int $timeout = null): void;

    /**
     * The command line the packager would run for the spec, with sensitive values redacted.
     */
    public function command(PackagingSpec $spec, string $directory): string;
}
