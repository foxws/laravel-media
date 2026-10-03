<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Foxws\Media\Encoding\Format;
use Foxws\Media\Filters\Filter;

/**
 * An extra output of an ffmpeg run, with its own streams, format and filters.
 */
class Output
{
    /** @var list<string> */
    protected array $maps = [];

    protected ?Format $format = null;

    /** @var list<Filter> */
    protected array $filters = [];

    /** @var list<string> */
    protected array $arguments = [];

    /**
     * @param  string  $path  The path on the target disk.
     */
    public function __construct(public readonly string $path) {}

    /**
     * Select input streams, e.g. "0:2" for the stream with index 2.
     */
    public function map(string ...$specifiers): static
    {
        foreach ($specifiers as $specifier) {
            $this->maps = [...$this->maps, '-map', $specifier];
        }

        return $this;
    }

    public function inFormat(Format $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function addFilter(Filter ...$filters): static
    {
        $this->filters = [...$this->filters, ...array_values($filters)];

        return $this;
    }

    /**
     * @param  list<string>  $arguments
     */
    public function addArgs(array $arguments): static
    {
        $this->arguments = [...$this->arguments, ...$arguments];

        return $this;
    }

    /**
     * The ffmpeg arguments for this output, ending with the file it writes to.
     *
     * @return list<string>
     */
    public function toArguments(string $file): array
    {
        return [
            ...$this->maps,
            ...FilterChain::arguments($this->filters),
            ...($this->format?->toArguments() ?? []),
            ...$this->arguments,
            $file,
        ];
    }
}
