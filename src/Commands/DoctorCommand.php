<?php

declare(strict_types=1);

namespace Foxws\Media\Commands;

use Foxws\Media\Exceptions\ExecutableNotFoundException;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Binary;
use Foxws\Media\Executables\Capability;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Facades\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'media:doctor')]
class DoctorCommand extends Command
{
    protected $signature = 'media:doctor
        {--encoder=* : An encoder ffmpeg must have, e.g. libx264 (repeatable)}
        {--decoder=* : A decoder ffmpeg must have (repeatable)}
        {--muxer=* : A muxer ffmpeg must have, e.g. dash (repeatable)}
        {--demuxer=* : A demuxer ffmpeg must have (repeatable)}
        {--filter=* : A filter ffmpeg must have, e.g. zscale (repeatable)}
        {--protocol=* : A protocol ffmpeg must have, e.g. https (repeatable)}
        {--bsf=* : A bitstream filter ffmpeg must have (repeatable)}
        {--hwaccel=* : A hardware acceleration ffmpeg must be built with, e.g. vaapi (repeatable)}
        {--json : Print the checks as JSON}';

    protected $description = 'Check that the media executables run, the temporary files root is usable and ffmpeg has what the app needs';

    protected const string OK = 'ok';

    protected const string WARNING = 'warning';

    protected const string FAILED = 'failed';

    public function handle(Executables $executables, TemporaryDirectories $directories): int
    {
        $checks = [
            ...array_map(fn (Binary $executable): array => $this->checkExecutable($executables, $executable), $executables->all()),
            ...$this->checkTemporaryFiles($directories),
            ...$this->checkCapabilities($this->requiredCapabilities()),
        ];

        $healthy = array_all($checks, fn (array $check): bool => $check['status'] !== self::FAILED);

        if ($this->option('json')) {
            $this->line((string) json_encode(['healthy' => $healthy, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Name', 'Status', 'Details'], array_map(fn (array $check): array => [
                $check['check'],
                $check['name'],
                $this->status($check['status']),
                $check['details'],
            ], $checks));
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Whether the executable is found and reports its version. ffmpeg and ffprobe must be;
     * executables registered by other packages are only needed when they're used.
     *
     * @return array{check: string, name: string, status: string, details: string}
     */
    protected function checkExecutable(Executables $executables, Binary $executable): array
    {
        if (! $executables->available($executable)) {
            return $this->check('executable', $executable->identifier(), $executable instanceof Executable ? self::FAILED : self::WARNING, "not found, set {$executable->environmentKey()}");
        }

        $path = $executables->path($executable);
        $result = Process::timeout(10)->run([$path, ...$executable->versionArguments()]);

        if ($result->failed()) {
            $reason = trim(Str::afterLast(trim($result->errorOutput()), "\n"));

            return $this->check('executable', $executable->identifier(), self::FAILED, trim("{$path} exited with code {$result->exitCode()}: {$reason}", ' :'));
        }

        $version = strtok(trim($result->output()), "\n");

        return $this->check('executable', $executable->identifier(), self::OK, $version !== false ? "{$path} ({$version})" : $path);
    }

    /**
     * Whether a directory can be created in the temporary files root, and in the cache root
     * when one is configured, with the configured minimum of free space.
     *
     * @return list<array{check: string, name: string, status: string, details: string}>
     */
    protected function checkTemporaryFiles(TemporaryDirectories $directories): array
    {
        $roots = ['root' => fn () => $directories->create()];

        $cacheRoot = Config::get('media.temporary_files.cache_root');

        if (is_string($cacheRoot) && $cacheRoot !== '') {
            $roots['cache_root'] = fn () => $directories->createCache();
        }

        $checks = [];

        foreach ($roots as $name => $create) {
            $configured = (string) Config::get("media.temporary_files.{$name}");

            try {
                $directories->delete($create());

                $checks[] = $this->check('temporary files', $name, self::OK, $configured);
            } catch (Throwable $exception) {
                $checks[] = $this->check('temporary files', $name, self::FAILED, "{$configured}: {$exception->getMessage()}");
            }
        }

        return $checks;
    }

    /**
     * @param  array<string, list<string>>  $required
     * @return list<array{check: string, name: string, status: string, details: string}>
     */
    protected function checkCapabilities(array $required): array
    {
        if ($required === []) {
            return [];
        }

        try {
            $missing = Media::capabilities()->missing($required);
            $error = null;
        } catch (ExecutableNotFoundException|ProcessFailedException $exception) {
            $missing = $required;
            $error = $exception->getMessage();
        }

        $checks = [];

        foreach ($required as $kind => $names) {
            foreach ($names as $name) {
                $absent = in_array($name, $missing[$kind] ?? [], true);

                $checks[] = $this->check(
                    Capability::from($kind)->singular(),
                    $name,
                    $absent ? self::FAILED : self::OK,
                    $absent ? ($error ?? 'not compiled in') : 'compiled in',
                );
            }
        }

        return $checks;
    }

    /**
     * The names passed with the requirement options, keyed by Capability value.
     *
     * @return array<string, list<string>>
     */
    protected function requiredCapabilities(): array
    {
        $required = [];

        foreach (Capability::cases() as $capability) {
            $names = array_values(array_unique(array_filter((array) $this->option($capability->singular()), is_string(...))));

            if ($names !== []) {
                $required[$capability->value] = $names;
            }
        }

        return $required;
    }

    /**
     * @return array{check: string, name: string, status: string, details: string}
     */
    protected function check(string $check, string $name, string $status, string $details): array
    {
        return ['check' => $check, 'name' => $name, 'status' => $status, 'details' => $details];
    }

    protected function status(string $status): string
    {
        return match ($status) {
            self::OK => '<fg=green>ok</>',
            self::WARNING => '<fg=yellow>warning</>',
            default => '<fg=red>failed</>',
        };
    }
}
