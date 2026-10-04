<?php

declare(strict_types=1);

namespace Foxws\Media\Filters;

/**
 * How bright HDR highlights are compressed into the SDR range.
 */
enum ToneMapAlgorithm: string
{
    /** Preserves detail in highlights and shadows; a good default for film. */
    case Hable = 'hable';

    /** Keeps in-range colours exact and only compresses the brightest highlights. */
    case Mobius = 'mobius';

    case Reinhard = 'reinhard';

    case Gamma = 'gamma';

    case Linear = 'linear';

    /** Cuts off everything out of range; fast, but highlights lose detail. */
    case Clip = 'clip';
}
