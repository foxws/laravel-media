<?php

declare(strict_types=1);

namespace Foxws\Media\Testing;

use Foxws\Media\Executables\Binary;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Process\Runner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;

/**
 * Answers ffprobe with fake probe data and makes ffmpeg write placeholder outputs,
 * while the real Runner still dispatches its events and throws on failures.
 */
class FakeRunner extends Runner
{
    public function __construct(
        protected MediaFake $fake,
        Executables $executables,
    ) {
        parent::__construct($executables);
    }

    protected function execute(Binary $executable, array $command, int $timeout, ?callable $onOutput, array $environment = []): array
    {
        $arguments = array_slice($command, 1);

        $this->fake->record($executable, $arguments);

        if (($error = $this->fake->takeFailure($executable)) !== null) {
            return [1, '', $error];
        }

        return match ($executable) {
            Executable::FFProbe => [0, in_array('packet=pts_time,flags', $arguments, true)
                ? $this->packets((string) end($arguments))
                : (string) json_encode($this->fake->probeFor((string) end($arguments))), ''],
            Executable::FFMpeg => [0, $this->ffmpeg($arguments, $onOutput), ''],
            default => $this->respond($executable, $arguments, $onOutput),
        };
    }

    /**
     * Stream the faked output and error output of an executable from another package to the callback.
     *
     * @param  list<string>  $arguments
     * @param  (callable(string, string=): mixed)|null  $onOutput
     * @return array{int, string, string}
     */
    protected function respond(Binary $executable, array $arguments, ?callable $onOutput): array
    {
        $result = $this->fake->responseFor($executable, $arguments);

        [$exitCode, $output, $errorOutput] = is_string($result)
            ? [0, $result, '']
            : [$result->exitCode() ?? 1, $result->output(), $result->errorOutput()];

        foreach (['out' => $output, 'err' => $errorOutput] as $type => $chunk) {
            if ($onOutput !== null && $chunk !== '') {
                $onOutput($chunk, $type);
            }
        }

        return [$exitCode, $output, $errorOutput];
    }

    /**
     * @param  list<string>  $arguments
     * @param  (callable(string): mixed)|null  $onOutput
     */
    protected function ffmpeg(array $arguments, ?callable $onOutput): string
    {
        $input = $arguments[array_search('-i', $arguments, true) + 1] ?? '';

        if (in_array('-f', $arguments, true) && in_array('null', $arguments, true) && $this->detectsScenes($arguments)) {
            return $this->sceneOutput($this->fake->scenesFor($input));
        }

        foreach ($this->outputs($arguments) as $output) {
            $file = str_contains($output, '%') ? sprintf($output, 1) : $output;

            new Filesystem()->ensureDirectoryExists(dirname($file));
            file_put_contents($file, $this->fragments($arguments) ? $this->fragmentedMp4() : 'fake media');
        }

        if (! in_array('-progress', $arguments, true)) {
            return '';
        }

        $duration = (float) data_get($this->fake->probeFor($input), 'format.duration', 0);
        $progress = $this->progressBlock($duration / 2, 'continue').$this->progressBlock($duration, 'end');

        if ($onOutput !== null) {
            $onOutput($progress);
        }

        return $progress;
    }

    /**
     * A packet list with a keyframe every two seconds of the faked duration.
     */
    protected function packets(string $input): string
    {
        $duration = (float) data_get($this->fake->probeFor($input), 'format.duration', 0);

        $lines = [];

        for ($time = 0.0; $time < $duration; $time += 2.0) {
            $lines[] = sprintf('%.6f,K__', $time);
            $lines[] = sprintf('%.6f,___', $time + 1.0);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $arguments
     */
    protected function fragments(array $arguments): bool
    {
        return array_any($arguments, fn (string $argument): bool => str_contains($argument, 'frag_keyframe'));
    }

    /**
     * A placeholder fragmented MP4: an initialization segment (ftyp, moov) and one fragment (moof, mdat).
     */
    protected function fragmentedMp4(): string
    {
        $box = fn (string $type, string $payload = ''): string => pack('N', 8 + strlen($payload)).$type.$payload;

        return $box('ftyp', 'isom').$box('moov').$box('moof').$box('mdat', 'fake media');
    }

    /**
     * Files ffmpeg would write: arguments inside a temporary root that aren't inputs or pass logs.
     *
     * @param  list<string>  $arguments
     * @return list<string>
     */
    protected function outputs(array $arguments): array
    {
        $roots = array_filter([
            Config::get('media.temporary_files.root'),
            Config::get('media.temporary_files.cache_root'),
        ], fn (mixed $root): bool => is_string($root) && $root !== '');

        $roots = array_map(fn (string $root): string => rtrim(str_replace('\\', '/', $root), '/').'/', $roots);

        $outputs = [];

        foreach ($arguments as $index => $argument) {
            $previous = $arguments[$index - 1] ?? null;
            $path = str_replace('\\', '/', $argument);

            if (in_array($previous, ['-i', '-passlogfile'], true)) {
                continue;
            }

            foreach ($roots as $root) {
                if (str_starts_with($path, $root)) {
                    $outputs[] = $argument;

                    break;
                }
            }
        }

        return $outputs;
    }

    /**
     * @param  list<string>  $arguments
     */
    protected function detectsScenes(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (str_contains($argument, 'metadata=print:file=-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<float>  $changes
     */
    protected function sceneOutput(array $changes): string
    {
        return implode('', array_map(
            fn (float $time, int $frame): string => "frame:{$frame} pts:0 pts_time:{$time}\nlavfi.scene_score=0.500000\n",
            $changes,
            array_keys($changes),
        ));
    }

    protected function progressBlock(float $seconds, string $state): string
    {
        return sprintf("out_time_us=%d\nspeed=2x\nprogress=%s\n", (int) round($seconds * 1_000_000), $state);
    }
}
