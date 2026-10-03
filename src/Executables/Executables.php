<?php

declare(strict_types=1);

namespace Foxws\Media\Executables;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Exceptions\ExecutableNotFoundException;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\ExecutableFinder;

class Executables
{
    use ResolvesFromContainer;

    /** @var array<string, string|null> */
    protected array $resolved = [];

    /**
     * The resolved path of the executable.
     *
     * @throws ExecutableNotFoundException
     */
    public function path(Executable $executable): string
    {
        return $this->find($executable)
            ?? throw ExecutableNotFoundException::for($executable, $this->configured($executable));
    }

    public function available(Executable $executable): bool
    {
        return $this->find($executable) !== null;
    }

    /**
     * Forget resolved paths, e.g. after the configuration changed.
     */
    public function flush(): void
    {
        $this->resolved = [];
    }

    protected function find(Executable $executable): ?string
    {
        if (array_key_exists($executable->value, $this->resolved)) {
            return $this->resolved[$executable->value];
        }

        $configured = $this->configured($executable);

        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            return $this->resolved[$executable->value] = $this->isExecutableFile($configured) ? $configured : null;
        }

        return $this->resolved[$executable->value] = new ExecutableFinder()->find($configured, null, [base_path()]);
    }

    /**
     * Whether the path is a file that can be run. On Windows, is_executable() only accepts
     * real binaries, so any file counts, like Symfony's ExecutableFinder does.
     */
    protected function isExecutableFile(string $path): bool
    {
        return is_file($path) && (PHP_OS_FAMILY === 'Windows' || is_executable($path));
    }

    protected function configured(Executable $executable): string
    {
        $configured = Config::get("media.executables.{$executable->value}");

        return is_string($configured) && $configured !== '' ? $configured : $executable->value;
    }
}
