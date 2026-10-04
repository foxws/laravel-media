<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use Foxws\Media\Encryption\EncryptionKey;

/**
 * The files an export wrote to its target disk.
 */
final readonly class ExportResult
{
    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        protected Disk $disk,
        protected array $paths,
        protected ?EncryptionKey $encryptionKey = null,
    ) {}

    public function disk(): Disk
    {
        return $this->disk;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * The key the output was encrypted with, to store and serve to players.
     */
    public function encryptionKey(): ?EncryptionKey
    {
        return $this->encryptionKey;
    }

    /**
     * The main output path, e.g. the encoded file or the manifest.
     */
    public function path(): ?string
    {
        return $this->paths[0] ?? null;
    }
}
