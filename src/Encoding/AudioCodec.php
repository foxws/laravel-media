<?php

declare(strict_types=1);

namespace Foxws\Media\Encoding;

enum AudioCodec: string
{
    case Copy = 'copy';
    case Aac = 'aac';
    case Opus = 'libopus';
    case Mp3 = 'libmp3lame';
    case Flac = 'flac';
}
