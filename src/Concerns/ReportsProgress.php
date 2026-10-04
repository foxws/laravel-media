<?php

declare(strict_types=1);

namespace Foxws\Media\Concerns;

use Foxws\Media\Events\ProgressReported;
use Foxws\Media\Process\Progress;
use Illuminate\Support\Facades\Event;

trait ReportsProgress
{
    /** @var list<callable(Progress): mixed> */
    protected array $progressCallbacks = [];

    /**
     * Receive progress updates while the process runs, about twice a second.
     * Return false from the callback to cancel: the process is stopped and a
     * ProcessFailedException with reason Cancelled is thrown.
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
        return $this->progressCallbacks !== [] || Event::hasListeners(ProgressReported::class);
    }

    /**
     * Pass the progress to the callbacks. Returns false when one of them asked to cancel.
     */
    protected function reportProgress(Progress $progress): bool
    {
        $continue = true;

        foreach ($this->progressCallbacks as $callback) {
            if ($callback($progress) === false) {
                $continue = false;
            }
        }

        return $continue;
    }
}
