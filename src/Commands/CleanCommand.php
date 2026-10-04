<?php

declare(strict_types=1);

namespace Foxws\Media\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'media:clean')]
class CleanCommand extends Command
{
    protected $signature = 'media:clean
        {--older-than= : Minutes since a temporary directory was last written to (default: the media timeout plus an hour)}
        {--dry-run : List the directories without deleting them}';

    protected $description = 'Delete temporary media directories left behind by crashed or killed jobs';

    public function handle(Filesystem $filesystem): int
    {
        $minutes = $this->option('older-than') !== null
            ? max(0, (int) $this->option('older-than'))
            : intdiv(Config::integer('media.timeout', 14400), 60) + 60;

        $cutoff = time() - $minutes * 60;
        $deleted = 0;

        foreach ($this->roots() as $root) {
            foreach (array_map(fn (string $directory): string => str_replace('\\', '/', $directory), $filesystem->directories($root)) as $directory) {
                if (preg_match('/^[0-9a-f]{16}$/', basename($directory)) !== 1 || $this->lastModified($directory) > $cutoff) {
                    continue;
                }

                $this->line(($this->option('dry-run') ? 'Would delete ' : 'Deleted ').$directory);

                if (! $this->option('dry-run')) {
                    $filesystem->deleteDirectory($directory);
                }

                $deleted++;
            }
        }

        $this->components->info(sprintf(
            '%s %d temporary %s older than %d minutes.',
            $this->option('dry-run') ? 'Found' : 'Deleted',
            $deleted,
            $deleted === 1 ? 'directory' : 'directories',
            $minutes,
        ));

        return self::SUCCESS;
    }

    /**
     * The existing temporary and cache roots. Only the package's own 16-character hex directories
     * inside them are touched, because the cache root may be a shared mount such as /dev/shm.
     *
     * @return list<string>
     */
    protected function roots(): array
    {
        $roots = [Config::get('media.temporary_files.root'), Config::get('media.temporary_files.cache_root')];

        return array_values(array_unique(array_filter($roots, fn (mixed $root): bool => is_string($root) && $root !== '' && is_dir($root))));
    }

    /**
     * The newest modification time of the directory or anything in it, since writing to a file
     * doesn't change the modification time of the directory that holds it.
     */
    protected function lastModified(string $directory): int
    {
        $latest = (int) filemtime($directory);

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $latest = max($latest, (int) $file->getMTime());
        }

        return $latest;
    }
}
