<?php

declare(strict_types=1);

namespace Foxws\Media\Concerns;

use Foxws\Media\Process\Progress;

trait ReportsProgress
{
    /** @var list<callable(Progress): mixed> */
    protected array $progressCallbacks = [];

    /**
     * Receive progress updates while the process runs, about twice a second.
     *
     * @param  callable(Progress): mixed  $callback
     */
    public function onProgress(callable $callback): static
    {
        $this->progressCallbacks[] = $callback;

        return $this;
    }

    protected function reportsProgress(): bool
    {
        return $this->progressCallbacks !== [];
    }

    protected function reportProgress(Progress $progress): void
    {
        foreach ($this->progressCallbacks as $callback) {
            $callback($progress);
        }
    }
}
