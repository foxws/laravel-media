<?php

declare(strict_types=1);

namespace Foxws\Media\Testing;

use Closure;
use Foxws\Media\Executables\Binary;
use Foxws\Media\Executables\Executable;
use Foxws\Media\MediaFactory;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Replaces the Media facade in tests: nothing is executed, probes return fake data,
 * ffmpeg writes placeholder files, and every command is recorded for assertions.
 */
class MediaFake extends MediaFactory
{
    /** @var list<array{executable: Binary, arguments: list<string>}> */
    protected array $commands = [];

    /** @var array<string, list<float>> */
    protected array $scenes = [];

    /** @var array<string, list<string>> */
    protected array $failures = [];

    /** @var array<string, Closure(list<string>): (string|ProcessResult)> */
    protected array $responses = [];

    /**
     * @param  array<string, array<string, mixed>>  $probes  ffprobe output keyed by (the end of) a media path; see FakeProbe.
     */
    public function __construct(protected array $probes = []) {}

    /**
     * Fake ffprobe output for media whose path ends with the given path, or "*" for any media,
     * e.g. uploads, which have random temporary names.
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
    public function failNext(Binary $executable = Executable::FFMpeg, string $error = 'Conversion failed!'): static
    {
        $this->failures[$executable->identifier()][] = $error;

        return $this;
    }

    /**
     * @param  list<string>  $arguments
     *
     * @internal
     */
    public function record(Binary $executable, array $arguments): void
    {
        $this->commands[] = ['executable' => $executable, 'arguments' => $arguments];
    }

    /**
     * @internal
     */
    public function takeFailure(Binary $executable): ?string
    {
        if (($this->failures[$executable->identifier()] ?? []) === []) {
            return null;
        }

        return array_shift($this->failures[$executable->identifier()]);
    }

    /**
     * Fake the runs of an executable from another package: the callback receives the arguments
     * and returns the output, or a Process::result() with the output, error output and exit code,
     * and can write the files the executable would. Both outputs are streamed to the run's
     * callbacks, and a non-zero exit code fails the run.
     *
     * @param  Closure(list<string>): (string|ProcessResult)  $respond
     */
    public function respondUsing(Binary $executable, Closure $respond): static
    {
        $this->responses[$executable->identifier()] = $respond;

        return $this;
    }

    /**
     * The result of a faked run of an executable from another package.
     *
     * @param  list<string>  $arguments
     *
     * @internal
     */
    public function responseFor(Binary $executable, array $arguments): string|ProcessResult
    {
        return isset($this->responses[$executable->identifier()]) ? ($this->responses[$executable->identifier()])($arguments) : '';
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
    public function commands(?Binary $executable = null): array
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
    public function assertRan(Binary $executable, ?Closure $callback = null): void
    {
        PHPUnit::assertTrue(
            $this->ran($executable, $callback),
            "The expected [{$executable->identifier()}] command was not run.",
        );
    }

    /**
     * @param  (Closure(list<string>): bool)|null  $callback
     */
    public function assertNotRan(Binary $executable, ?Closure $callback = null): void
    {
        PHPUnit::assertFalse(
            $this->ran($executable, $callback),
            "An unexpected [{$executable->identifier()}] command was run.",
        );
    }

    public function assertRanTimes(Binary $executable, int $times): void
    {
        PHPUnit::assertCount($times, $this->commands($executable), "The [{$executable->identifier()}] command was expected to run {$times} times.");
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
    protected function ran(Binary $executable, ?Closure $callback): bool
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

        return $path === '*' || $input === $path || str_ends_with($input, '/'.ltrim($path, '/'));
    }

    protected function defaultDisk(): string
    {
        $disk = Config::get('media.disk');

        return is_string($disk) && $disk !== '' ? $disk : Config::string('filesystems.default');
    }
}
