<?php

declare(strict_types=1);

namespace Foxws\Media\Encoding;

enum VideoCodec: string
{
    case Copy = 'copy';
    case H264 = 'libx264';
    case Hevc = 'libx265';
    case Av1 = 'libsvtav1';
    case Vp9 = 'libvpx-vp9';
}
