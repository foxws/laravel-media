<?php

declare(strict_types=1);

namespace Foxws\Media\Testing;

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

    protected function execute(Executable $executable, array $command, int $timeout, ?callable $onOutput): array
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
            Executable::Packager => [0, $this->packager($arguments), ''],
            default => [0, '', ''],
        };
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
            file_put_contents($file, 'fake media');
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
     * Write placeholder segments and manifests for the outputs of a packager run.
     *
     * @param  list<string>  $arguments
     */
    protected function packager(array $arguments): string
    {
        foreach ($arguments as $argument) {
            if (preg_match('/(?:^|,)output=([^,]+)/', $argument, $matches) === 1
                || preg_match('/^--(?:mpd_output|hls_master_playlist_output)=(.+)$/', $argument, $matches) === 1) {
                new Filesystem()->ensureDirectoryExists(dirname($matches[1]));
                file_put_contents($matches[1], 'fake media');
            }
        }

        return '';
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
