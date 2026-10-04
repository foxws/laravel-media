<?php

declare(strict_types=1);

namespace Foxws\Media\Packaging;

enum HlsPlaylistType: string
{
    /** A complete playlist for on-demand playback. */
    case Vod = 'VOD';

    /** A live playlist that only grows, keeping every segment. */
    case Event = 'EVENT';

    /** A live playlist with a sliding window of segments. */
    case Live = 'LIVE';
}
