<?php

declare(strict_types=1);

namespace Foxws\Media\Concerns;

trait HasContext
{
    /** @var array<string, mixed> */
    protected array $context = [];

    /**
     * Add context to the events of this export, e.g. ['video_id' => 1], so listeners know what it's about.
     *
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): static
    {
        $this->context = [...$this->context, ...$context];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
