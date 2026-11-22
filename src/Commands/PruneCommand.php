<?php

declare(strict_types=1);

namespace Foxws\Media\Commands;

use Foxws\Media\Delivery\DatabaseIndexStore;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'media:prune')]
class PruneCommand extends Command
{
    protected $signature = 'media:prune
        {--older-than=10080 : Minutes since a segment was packaged}
        {--dry-run : Count the segments without deleting them}';

    protected $description = 'Delete cached segments of direct streams, which are packaged again when requested, and the stored indexes of changed or deleted files';

    public function handle(): int
    {
        $minutes = max(0, (int) $this->option('older-than'));
        $cutoff = time() - $minutes * 60;
        $disk = Disk::make(Config::string('media.delivery.cache_disk', 'local'))->filesystem();
        $prefix = trim(Config::string('media.delivery.cache_path', 'media-segments'), '/');

        $files = array_filter(
            $disk->allFiles($prefix),
            fn (string $path): bool => $this->isCached($path, $prefix) && in_array(pathinfo($path, PATHINFO_EXTENSION), ['ts', 'm4s', 'mp4', 'vtt'], true),
        );

        $stale = array_values(array_filter($files, fn (string $path): bool => $disk->lastModified($path) <= $cutoff));

        // An init segment is written once, so it's older than segments packaged later, which need it.
        $inUse = array_flip(array_map(
            dirname(...),
            array_filter(array_diff($files, $stale), fn (string $path): bool => str_ends_with($path, '.m4s')),
        ));

        $stale = array_values(array_filter($stale, fn (string $path): bool => basename($path) !== 'init.mp4' || ! isset($inUse[dirname($path)])));

        if (! $this->option('dry-run')) {
            foreach (array_chunk($stale, 1000) as $paths) {
                $disk->delete($paths);
            }

            $this->deleteEmptyDirectories($prefix);
        }

        $this->components->info(sprintf(
            '%s %d cached %s older than %d minutes.',
            $this->option('dry-run') ? 'Found' : 'Deleted',
            count($stale),
            count($stale) === 1 ? 'segment' : 'segments',
            $minutes,
        ));

        if (Config::string('media.delivery.index_store', 'cache') === 'database') {
            $this->pruneIndexes(app(DatabaseIndexStore::class));
        }

        return self::SUCCESS;
    }

    /**
     * Delete the indexes of file versions that no longer exist: the file was changed, moved or
     * deleted. Files on disks that aren't configured are kept.
     */
    protected function pruneIndexes(DatabaseIndexStore $store): void
    {
        $directories = app(TemporaryDirectories::class);
        $stale = [];

        foreach ($store->versions() as $version) {
            if (! Config::has("filesystems.disks.{$version->disk}")) {
                continue;
            }

            if ((new Media(Disk::make($version->disk), $version->path, $directories))->versionKey() !== $version->version_key) {
                $stale[] = $version->version_key;
            }
        }

        if (! $this->option('dry-run')) {
            foreach (array_chunk($stale, 1000) as $keys) {
                $store->delete($keys);
            }
        }

        $this->components->info(sprintf(
            '%s %d stored %s of changed or deleted files.',
            $this->option('dry-run') ? 'Found' : 'Deleted',
            count($stale),
            count($stale) === 1 ? 'index' : 'indexes',
        ));
    }

    /**
     * Whether the path is part of the segment cache. Without a cache path that's the whole disk, where
     * only the directories named by a version key are the package's.
     */
    protected function isCached(string $path, string $prefix): bool
    {
        return $prefix !== '' || preg_match('#^[0-9a-f]{32}(/|$)#', $path) === 1;
    }

    /**
     * Local disks keep the directories of deleted segments; object storage has no real directories.
     */
    protected function deleteEmptyDirectories(string $prefix): void
    {
        $disk = Disk::make(Config::string('media.delivery.cache_disk', 'local'));

        if (! $disk->isLocal()) {
            return;
        }

        $directories = array_values(array_filter(
            $disk->filesystem()->allDirectories($prefix),
            fn (string $directory): bool => $this->isCached($directory, $prefix),
        ));

        usort($directories, fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));

        foreach ($directories as $directory) {
            if ($disk->filesystem()->files($directory) === [] && $disk->filesystem()->directories($directory) === []) {
                $disk->filesystem()->deleteDirectory($directory);
            }
        }
    }
}
