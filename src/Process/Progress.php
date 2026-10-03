<?php

declare(strict_types=1);

namespace Foxws\Media\Process;

/**
 * How far a running process is, as reported by the executable.
 */
final readonly class Progress
{
    /**
     * @param  float  $seconds  Seconds of media processed in the current pass.
     * @param  float|null  $duration  Seconds of media the pass will process, when known.
     * @param  float|null  $speed  Processing speed relative to real time, e.g. 2.5 for 2.5x.
     */
    public function __construct(
        public float $seconds,
        public ?float $duration = null,
        public ?float $speed = null,
        public ?float $fps = null,
        public ?int $frame = null,
        public bool $finished = false,
        public int $pass = 1,
        public int $passes = 1,
    ) {}

    /**
     * Overall progress from 0 to 100 across all passes, or null when the duration is unknown.
     */
    public function percentage(): ?float
    {
        if ($this->finished && $this->pass === $this->passes) {
            return 100.0;
        }

        if ($this->duration === null || $this->duration <= 0) {
            return null;
        }

        $pass = min(1.0, max(0.0, $this->seconds / $this->duration));

        return round((($this->pass - 1) + $pass) / $this->passes * 100, 2);
    }

    /**
     * Estimated seconds until all passes finish, or null when the duration or speed is unknown.
     */
    public function remaining(): ?float
    {
        if ($this->duration === null || $this->speed === null || $this->speed <= 0) {
            return null;
        }

        $media = max(0.0, $this->duration - $this->seconds) + ($this->passes - $this->pass) * $this->duration;

        return round($media / $this->speed, 2);
    }

    /**
     * A copy for one pass of a multi-pass run.
     */
    public function forPass(int $pass, int $passes): self
    {
        return new self($this->seconds, $this->duration, $this->speed, $this->fps, $this->frame, $this->finished, $pass, $passes);
    }
}
