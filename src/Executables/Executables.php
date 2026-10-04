<?php

declare(strict_types=1);

namespace Foxws\Media\Executables;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Exceptions\ExecutableNotFoundException;
use Symfony\Component\Process\ExecutableFinder;

class Executables
{
    use ResolvesFromContainer;

    /** @var array<string, string|null> */
    protected array $resolved = [];

    /** @var array<string, Binary> */
    protected array $registered = [];

    /**
     * Add executables from other packages to media:info and "php artisan about".
     */
    public function register(Binary ...$binaries): static
    {
        foreach ($binaries as $binary) {
            $this->registered[$binary->identifier()] = $binary;
        }

        return $this;
    }

    /**
     * The package's own executables and the registered ones.
     *
     * @return list<Binary>
     */
    public function all(): array
    {
        return [...Executable::cases(), ...array_values($this->registered)];
    }

    /**
     * The resolved path of the executable.
     *
     * @throws ExecutableNotFoundException
     */
    public function path(Binary $executable): string
    {
        return $this->find($executable)
            ?? throw ExecutableNotFoundException::for($executable, $executable->configuredPath());
    }

    public function available(Binary $executable): bool
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

    protected function find(Binary $executable): ?string
    {
        if (array_key_exists($executable->identifier(), $this->resolved)) {
            return $this->resolved[$executable->identifier()];
        }

        $configured = $executable->configuredPath();

        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            return $this->resolved[$executable->identifier()] = $this->isExecutableFile($configured) ? $configured : null;
        }

        return $this->resolved[$executable->identifier()] = new ExecutableFinder()->find($configured, null, [base_path()]);
    }

    /**
     * Whether the path is a file that can be run. On Windows, is_executable() only accepts
     * real binaries, so any file counts, like Symfony's ExecutableFinder does.
     */
    protected function isExecutableFile(string $path): bool
    {
        return is_file($path) && (PHP_OS_FAMILY === 'Windows' || is_executable($path));
    }
}
