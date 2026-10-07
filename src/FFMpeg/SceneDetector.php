<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filters\Number;
use Foxws\Media\Process\Runner;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Finds scene changes with ffmpeg's scene score.
 */
class SceneDetector
{
    use ResolvesFromContainer;

    public function __construct(protected Runner $runner) {}

    /**
     * @param  float  $threshold  The minimum scene score (0-1) for a change; lower finds more scenes.
     * @return list<Scene>
     */
    public function detect(Media $media, float $duration, float $threshold = 0.3): array
    {
        if ($threshold <= 0 || $threshold >= 1) {
            throw new InvalidArgumentException('The scene threshold must be between 0 and 1.');
        }

        $result = $this->runner->run(Executable::FFMpeg, [
            '-hide_banner',
            '-nostdin',
            '-loglevel', Config::string('media.ffmpeg_log_level', 'error'),
            ...$media->inputArguments(),
            '-i', $media->inputPath(),
            '-map', '0:v:0',
            '-vf', "scale=320:-2,select='gt(scene,".Number::format($threshold).")',metadata=print:file=-",
            '-an',
            '-f', 'null',
            '-',
        ]);

        return $this->scenes($this->changes($result->output), $duration);
    }

    /**
     * The scene changes printed by the metadata filter, as [seconds => score].
     *
     * @return array<string, float>
     */
    public function changes(string $output): array
    {
        preg_match_all('/pts_time:(\d+(?:\.\d+)?)\s+lavfi\.scene_score=(\d+(?:\.\d+)?)/', $output, $matches, PREG_SET_ORDER);

        $changes = [];

        foreach ($matches as [, $time, $score]) {
            $changes[$time] = (float) $score;
        }

        return $changes;
    }

    /**
     * @param  array<string, float>  $changes
     * @return list<Scene>
     */
    protected function scenes(array $changes, float $duration): array
    {
        $scenes = [];
        $start = 0.0;
        $score = null;

        foreach ($changes as $time => $changeScore) {
            if ((float) $time <= $start) {
                continue;
            }

            $scenes[] = new Scene($start, (float) $time, $score);
            $start = (float) $time;
            $score = $changeScore;
        }

        if ($duration > $start) {
            $scenes[] = new Scene($start, $duration, $score);
        }

        return $scenes;
    }
}
