<?php

declare(strict_types=1);

namespace Foxws\Media\Packaging;

enum StreamType: string
{
    case Video = 'video';
    case Audio = 'audio';
    case Text = 'text';
}
