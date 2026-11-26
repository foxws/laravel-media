<?php

declare(strict_types=1);

namespace Foxws\Media\Commands;

use Foxws\Media\Executables\Binary;
use Foxws\Media\Executables\Executables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'media:info')]
class InfoCommand extends Command
{
    protected $signature = 'media:info';

    protected $description = 'Show which media executables are available, with their paths and versions';

    public function handle(Executables $executables): int
    {
        $rows = array_map(function (Binary $executable) use ($executables): array {
            if (! $executables->available($executable)) {
                return [$executable->identifier(), '<fg=red>missing</>', "set {$executable->environmentKey()}", ''];
            }

            $path = $executables->path($executable);

            return [$executable->identifier(), '<fg=green>found</>', $path, $this->version($path, $executable)];
        }, $executables->all());

        $this->table(['Executable', 'Status', 'Path', 'Version'], $rows);

        return self::SUCCESS;
    }

    /**
     * The first line of the version output, or why the executable couldn't report it,
     * such as a shared library its build needs but the system doesn't have.
     */
    protected function version(string $path, Binary $executable): string
    {
        $result = Process::timeout(10)->run([$path, ...$executable->versionArguments()]);

        if ($result->failed()) {
            $reason = trim(Str::afterLast(trim($result->errorOutput()), "\n"));

            return $reason === '' ? "failed (exit {$result->exitCode()})" : "failed (exit {$result->exitCode()}): {$reason}";
        }

        $firstLine = strtok(trim($result->output()), "\n");

        return $firstLine !== false ? $firstLine : 'unknown';
    }
}
