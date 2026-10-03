<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

use Stringable;

/**
 * An ffmpeg (libavfilter) filter, rendered as a filter graph string such as "scale=1280:-2".
 */
interface Filter extends Stringable
{
    public function type(): FilterType;
}
