<?php

declare(strict_types=1);

namespace Foxws\Media\Commands;

use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'media:info')]
class InfoCommand extends Command
{
    protected $signature = 'media:info';

    protected $description = 'Show which media executables are available, with their paths and versions';

    public function handle(Executables $executables): int
    {
        $rows = array_map(function (Executable $executable) use ($executables): array {
            if (! $executables->available($executable)) {
                return [$executable->value, '<fg=red>missing</>', "set {$executable->environmentKey()}", ''];
            }

            $path = $executables->path($executable);

            return [$executable->value, '<fg=green>found</>', $path, $this->version($path, $executable)];
        }, Executable::cases());

        $this->table(['Executable', 'Status', 'Path', 'Version'], $rows);

        return self::SUCCESS;
    }

    protected function version(string $path, Executable $executable): string
    {
        $result = Process::timeout(10)->run([$path, ...$executable->versionArguments()]);

        $firstLine = strtok(trim($result->output()), "\n");

        return $result->successful() && $firstLine !== false ? $firstLine : 'unknown';
    }
}
