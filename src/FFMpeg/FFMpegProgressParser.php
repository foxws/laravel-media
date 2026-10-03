<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Foxws\Media\Process\Progress;
use Foxws\Media\Process\ProgressParser;

/**
 * Parses the key=value blocks ffmpeg writes with "-progress pipe:1", each ending in "progress=continue" or "progress=end".
 */
class FFMpegProgressParser implements ProgressParser
{
    protected string $buffer = '';

    /** @var array<string, string> */
    protected array $block = [];

    public function __construct(protected ?float $duration = null) {}

    public function feed(string $output): array
    {
        $this->buffer .= $output;

        $updates = [];

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = trim(substr($this->buffer, 0, $newline));
            $this->buffer = substr($this->buffer, $newline + 1);

            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map(trim(...), explode('=', $line, 2));

            $this->block[$key] = $value;

            if ($key === 'progress') {
                $updates[] = $this->progress();
                $this->block = [];
            }
        }

        return $updates;
    }

    protected function progress(): Progress
    {
        $microseconds = $this->number($this->block['out_time_us'] ?? $this->block['out_time_ms'] ?? null);
        $speed = $this->number(rtrim($this->block['speed'] ?? '', 'x'));
        $frame = $this->number($this->block['frame'] ?? null);

        return new Progress(
            seconds: $microseconds !== null ? max(0.0, $microseconds / 1_000_000) : 0.0,
            duration: $this->duration,
            speed: $speed,
            fps: $this->number($this->block['fps'] ?? null),
            frame: $frame !== null ? (int) $frame : null,
            finished: ($this->block['progress'] ?? null) === 'end',
        );
    }

    protected function number(?string $value): ?float
    {
        return $value !== null && is_numeric($value) ? (float) $value : null;
    }
}
