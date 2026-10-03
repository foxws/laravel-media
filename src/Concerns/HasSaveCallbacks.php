<?php

declare(strict_types=1);

namespace Foxws\Media\Concerns;

trait HasSaveCallbacks
{
    /** @var list<callable> */
    protected array $beforeSavingCallbacks = [];

    /** @var list<callable> */
    protected array $afterSavingCallbacks = [];

    /**
     * Run a callback before saving, with this instance as its argument. It may still change the command.
     */
    public function beforeSaving(callable $callback): static
    {
        $this->beforeSavingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Run a callback after a successful save, with this instance and the result as its arguments.
     */
    public function afterSaving(callable $callback): static
    {
        $this->afterSavingCallbacks[] = $callback;

        return $this;
    }

    protected function runBeforeSavingCallbacks(): void
    {
        $callbacks = $this->beforeSavingCallbacks;

        $this->beforeSavingCallbacks = [];

        foreach ($callbacks as $callback) {
            $callback($this);
        }
    }

    protected function runAfterSavingCallbacks(mixed $result): void
    {
        $callbacks = $this->afterSavingCallbacks;

        $this->afterSavingCallbacks = [];

        foreach ($callbacks as $callback) {
            $callback($this, $result);
        }
    }
}
