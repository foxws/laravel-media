<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Exceptions\MediaNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;

class Exporter
{
    use ResolvesFromContainer;

    /**
     * Copy every file in a local directory to a directory on the target disk.
     *
     * @return list<string> The written paths on the target disk.
     */
    public function export(string $localDirectory, Disk $target, string $targetDirectory = '', ?string $visibility = null): array
    {
        $targetDirectory = trim($targetDirectory, '/');

        $files = new Filesystem()->allFiles($localDirectory);

        usort($files, fn (SplFileInfo $a, SplFileInfo $b): int => strcmp($a->getRelativePathname(), $b->getRelativePathname()));

        return array_map(function (SplFileInfo $file) use ($target, $targetDirectory, $visibility): string {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $path = $targetDirectory !== '' ? "{$targetDirectory}/{$relativePath}" : $relativePath;

            $stream = fopen($file->getPathname(), 'rb')
                ?: throw MediaNotFoundException::unreadable($file->getPathname());

            try {
                $target->writeStream($path, $stream, $visibility !== null ? ['visibility' => $visibility] : []);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return $path;
        }, $files);
    }
}
