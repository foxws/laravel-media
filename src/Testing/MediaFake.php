<?php

declare(strict_types=1);

namespace Foxws\Media\Testing;

use Closure;
use Foxws\Media\Executables\Executable;
use Foxws\Media\MediaFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Replaces the Media facade in tests: nothing is executed, probes return fake data,
 * ffmpeg writes placeholder files, and every command is recorded for assertions.
 */
class MediaFake extends MediaFactory
{
    /** @var list<array{executable: Executable, arguments: list<string>}> */
    protected array $commands = [];

    /** @var array<string, list<float>> */
    protected array $scenes = [];

    /** @var array<string, list<string>> */
    protected array $failures = [];

    /**
     * @param  array<string, array<string, mixed>>  $probes  ffprobe output keyed by (the end of) a media path; see FakeProbe.
     */
    public function __construct(protected array $probes = []) {}

    /**
     * Fake ffprobe output for media whose path ends with the given path.
     *
     * @param  array<string, mixed>  $probe
     */
    public function probe(string $path, array $probe): static
    {
        $this->probes[$path] = $probe;

        return $this;
    }

    /**
     * Fake the scene changes (in seconds) found in media whose path ends with the given path.
     *
     * @param  list<float>  $changes
     */
    public function scenes(string $path, array $changes): static
    {
        $this->scenes[$path] = $changes;

        return $this;
    }

    /**
     * Make the next run of the executable fail with the given error output.
     */
    public function failNext(Executable $executable = Executable::FFMpeg, string $error = 'Conversion failed!'): static
    {
        $this->failures[$executable->value][] = $error;

        return $this;
    }

    /**
     * @param  list<string>  $arguments
     *
     * @internal
     */
    public function record(Executable $executable, array $arguments): void
    {
        $this->commands[] = ['executable' => $executable, 'arguments' => $arguments];
    }

    /**
     * @internal
     */
    public function takeFailure(Executable $executable): ?string
    {
        if (($this->failures[$executable->value] ?? []) === []) {
            return null;
        }

        return array_shift($this->failures[$executable->value]);
    }

    /**
     * @return array<string, mixed>
     *
     * @internal
     */
    public function probeFor(string $input): array
    {
        return $this->match($this->probes, $input) ?? FakeProbe::video();
    }

    /**
     * @return list<float>
     *
     * @internal
     */
    public function scenesFor(string $input): array
    {
        return $this->match($this->scenes, $input) ?? [];
    }

    /**
     * The arguments of every recorded run, optionally of one executable.
     *
     * @return list<list<string>>
     */
    public function commands(?Executable $executable = null): array
    {
        return array_values(array_map(
            fn (array $command): array => $command['arguments'],
            array_filter($this->commands, fn (array $command): bool => $executable === null || $command['executable'] === $executable),
        ));
    }

    /**
     * Assert the executable ran, optionally with arguments the callback accepts.
     *
     * @param  (Closure(list<string>): bool)|null  $callback
     */
    public function assertRan(Executable $executable, ?Closure $callback = null): void
    {
        PHPUnit::assertTrue(
            $this->ran($executable, $callback),
            "The expected [{$executable->value}] command was not run.",
        );
    }

    /**
     * @param  (Closure(list<string>): bool)|null  $callback
     */
    public function assertNotRan(Executable $executable, ?Closure $callback = null): void
    {
        PHPUnit::assertFalse(
            $this->ran($executable, $callback),
            "An unexpected [{$executable->value}] command was run.",
        );
    }

    public function assertRanTimes(Executable $executable, int $times): void
    {
        PHPUnit::assertCount($times, $this->commands($executable), "The [{$executable->value}] command was expected to run {$times} times.");
    }

    public function assertNothingRan(): void
    {
        PHPUnit::assertSame([], $this->commands, 'Media commands were run unexpectedly.');
    }

    /**
     * Assert media whose path ends with the given path was probed.
     */
    public function assertProbed(string $path): void
    {
        PHPUnit::assertTrue(
            $this->ran(Executable::FFProbe, fn (array $arguments): bool => $this->matches($path, (string) end($arguments))),
            "The media [{$path}] was not probed.",
        );
    }

    /**
     * Assert a file was saved to the disk (the media default disk when not given).
     */
    public function assertSaved(string $path, ?string $disk = null): void
    {
        Storage::disk($disk ?? $this->defaultDisk())->assertExists($path);
    }

    public function assertNotSaved(string $path, ?string $disk = null): void
    {
        Storage::disk($disk ?? $this->defaultDisk())->assertMissing($path);
    }

    /**
     * @param  (Closure(list<string>): bool)|null  $callback
     */
    protected function ran(Executable $executable, ?Closure $callback): bool
    {
        foreach ($this->commands($executable) as $arguments) {
            if ($callback === null || $callback($arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template TValue
     *
     * @param  array<string, TValue>  $values
     * @return TValue|null
     */
    protected function match(array $values, string $input): mixed
    {
        foreach ($values as $path => $value) {
            if ($this->matches($path, $input)) {
                return $value;
            }
        }

        return null;
    }

    protected function matches(string $path, string $input): bool
    {
        $input = str_replace('\\', '/', strtok($input, '?') ?: $input);

        return $input === $path || str_ends_with($input, '/'.ltrim($path, '/'));
    }

    protected function defaultDisk(): string
    {
        $disk = Config::get('media.disk');

        return is_string($disk) && $disk !== '' ? $disk : Config::string('filesystems.default');
    }
}
