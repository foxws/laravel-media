<?php

declare(strict_types=1);

namespace Foxws\Media\Packaging;

use Foxws\Media\Filesystem\Media;

/**
 * One elementary stream to package, taken from an input file.
 */
final readonly class PackagingStream
{
    /**
     * @param  string  $output  The output file, relative to the export directory.
     * @param  array<string, string>  $options  Extra driver-specific stream fields, e.g. ['dash_roles' => 'subtitle'] for Shaka.
     */
    public function __construct(
        public StreamType $type,
        public Media $media,
        public string $output,
        public ?string $language = null,
        public array $options = [],
    ) {}
}
