<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

/**
 * Where a direct stream packages the segments after the requested one.
 */
enum LookAheadStrategy: string
{
    /** A PackageSegments job on the configured connection and queue. */
    case Queue = 'queue';

    /** The same process, after the response has been sent. */
    case Defer = 'defer';
}
