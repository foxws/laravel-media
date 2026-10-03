<?php

declare(strict_types=1);

namespace Foxws\Media\Executables;

enum Executable: string
{
    case FFMpeg = 'ffmpeg';
    case FFProbe = 'ffprobe';
    case Packager = 'packager';
    case AbAv1 = 'ab-av1';

    /**
     * The environment variable that configures this executable's path.
     */
    public function environmentKey(): string
    {
        return match ($this) {
            self::FFMpeg => 'MEDIA_FFMPEG_PATH',
            self::FFProbe => 'MEDIA_FFPROBE_PATH',
            self::Packager => 'MEDIA_PACKAGER_PATH',
            self::AbAv1 => 'MEDIA_AB_AV1_PATH',
        };
    }

    /**
     * @return list<string>
     */
    public function versionArguments(): array
    {
        return match ($this) {
            self::FFMpeg, self::FFProbe => ['-version'],
            self::Packager, self::AbAv1 => ['--version'],
        };
    }
}
