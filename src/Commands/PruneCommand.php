<?php

declare(strict_types=1);

namespace Foxws\Media\Commands;

use Foxws\Media\Filesystem\Disk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'media:prune')]
class PruneCommand extends Command
{
    protected $signature = 'media:prune
        {--older-than=10080 : Minutes since a segment was packaged}
        {--dry-run : Count the segments without deleting them}';

    protected $description = 'Delete cached segments of direct streams, which are packaged again when requested';

    public function handle(): int
    {
        $minutes = max(0, (int) $this->option('older-than'));
        $cutoff = time() - $minutes * 60;
        $disk = Disk::make(Config::string('media.delivery.cache_disk', 'local'))->filesystem();
        $prefix = trim(Config::string('media.delivery.cache_path', 'media-segments'), '/');

        $stale = array_values(array_filter(
            $disk->allFiles($prefix),
            fn (string $path): bool => str_ends_with($path, '.ts') && $disk->lastModified($path) <= $cutoff,
        ));

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

        return self::SUCCESS;
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

        $directories = $disk->filesystem()->allDirectories($prefix);

        usort($directories, fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));

        foreach ($directories as $directory) {
            if ($disk->filesystem()->files($directory) === [] && $disk->filesystem()->directories($directory) === []) {
                $disk->filesystem()->deleteDirectory($directory);
            }
        }
    }
}
