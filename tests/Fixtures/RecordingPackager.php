<?php

declare(strict_types=1);

namespace Foxws\Media\Tests\Fixtures;

use Foxws\Media\Filesystem\TemporaryDirectory;
use Foxws\Media\Packaging\Packager;
use Foxws\Media\Packaging\PackagingSpec;
use Foxws\Media\Packaging\PackagingStream;
use RuntimeException;

/**
 * A packager driver that records what it was asked to package and writes placeholder files.
 */
class RecordingPackager implements Packager
{
    /** @var list<PackagingSpec> */
    public array $packaged = [];

    public ?string $failWith = null;

    public function package(PackagingSpec $spec, TemporaryDirectory $directory, ?int $timeout = null): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }

        $this->packaged[] = $spec;

        foreach ([...$spec->manifests(), ...array_map(fn (PackagingStream $stream): string => $stream->output, $spec->streams)] as $file) {
            $directory->put($file, 'packaged');
        }
    }

    public function command(PackagingSpec $spec, string $directory): string
    {
        return "recording {$directory}: ".implode(', ', array_map(fn (PackagingStream $stream): string => $stream->output, $spec->streams));
    }

    public function spec(): PackagingSpec
    {
        return $this->packaged[array_key_last($this->packaged) ?? throw new RuntimeException('Nothing was packaged.')];
    }
}
