<?php

declare(strict_types=1);

namespace Foxws\Media\Executables;

use Illuminate\Support\Facades\Config;

enum Executable: string implements Binary
{
    case FFMpeg = 'ffmpeg';
    case FFProbe = 'ffprobe';

    public function identifier(): string
    {
        return $this->value;
    }

    public function configuredPath(): string
    {
        $configured = Config::get("media.executables.{$this->value}");

        return is_string($configured) && $configured !== '' ? $configured : $this->value;
    }

    public function environmentKey(): string
    {
        return match ($this) {
            self::FFMpeg => 'MEDIA_FFMPEG_PATH',
            self::FFProbe => 'MEDIA_FFPROBE_PATH',
        };
    }

    public function versionArguments(): array
    {
        return ['-version'];
    }
}
