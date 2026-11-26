<?php

declare(strict_types=1);

namespace Foxws\Media\Commands;

use Foxws\Media\Exceptions\ExecutableNotFoundException;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Binary;
use Foxws\Media\Executables\Capability;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Facades\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'media:info')]
class InfoCommand extends Command
{
    protected $signature = 'media:info
        {--encoder=* : An encoder ffmpeg must have, e.g. libx264 (repeatable)}
        {--decoder=* : A decoder ffmpeg must have (repeatable)}
        {--muxer=* : A muxer ffmpeg must have, e.g. dash (repeatable)}
        {--demuxer=* : A demuxer ffmpeg must have (repeatable)}
        {--filter=* : A filter ffmpeg must have, e.g. zscale (repeatable)}
        {--protocol=* : A protocol ffmpeg must have, e.g. https (repeatable)}
        {--bsf=* : A bitstream filter ffmpeg must have (repeatable)}
        {--hwaccel=* : A hardware acceleration ffmpeg must be built with, e.g. vaapi (repeatable)}
        {--json : Print the result as JSON}';

    protected $description = 'Show which media executables are available, with their paths and versions, and check what ffmpeg was built with';

    public function handle(Executables $executables): int
    {
        $found = array_map(function (Binary $executable) use ($executables): array {
            if (! $executables->available($executable)) {
                return ['name' => $executable->identifier(), 'found' => false, 'path' => null, 'version' => null, 'environment_key' => $executable->environmentKey()];
            }

            $path = $executables->path($executable);

            return ['name' => $executable->identifier(), 'found' => true, 'path' => $path, 'version' => $this->version($path, $executable), 'environment_key' => $executable->environmentKey()];
        }, $executables->all());

        $required = $this->requiredCapabilities();
        [$missing, $error] = $this->missingCapabilities($required);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'executables' => $found,
                'required' => (object) $required,
                'missing' => (object) $missing,
                'error' => $error,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $missing === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['Executable', 'Status', 'Path', 'Version'], array_map(fn (array $executable): array => $executable['found']
            ? [$executable['name'], '<fg=green>found</>', $executable['path'], $executable['version']]
            : [$executable['name'], '<fg=red>missing</>', "set {$executable['environment_key']}", ''], $found));

        if ($required !== []) {
            $this->table(['Capability', 'Name', 'Status'], $this->capabilityRows($required, $missing));
        }

        if ($error !== null) {
            $this->components->error($error);
        }

        return $missing === [] ? self::SUCCESS : self::FAILURE;
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
     * The required names ffmpeg lacks, and the error when ffmpeg couldn't be asked: then all are missing.
     *
     * @param  array<string, list<string>>  $required
     * @return array{array<string, list<string>>, ?string}
     */
    protected function missingCapabilities(array $required): array
    {
        if ($required === []) {
            return [[], null];
        }

        try {
            return [Media::capabilities()->missing($required), null];
        } catch (ExecutableNotFoundException|ProcessFailedException $exception) {
            return [$required, $exception->getMessage()];
        }
    }

    /**
     * @param  array<string, list<string>>  $required
     * @param  array<string, list<string>>  $missing
     * @return list<list<string>>
     */
    protected function capabilityRows(array $required, array $missing): array
    {
        $rows = [];

        foreach ($required as $kind => $names) {
            foreach ($names as $name) {
                $rows[] = [Capability::from($kind)->singular(), $name, in_array($name, $missing[$kind] ?? [], true) ? '<fg=red>missing</>' : '<fg=green>found</>'];
            }
        }

        return $rows;
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
