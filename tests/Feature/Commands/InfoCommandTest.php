<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Illuminate\Support\Facades\Process;

it('lists found and missing executables', function () {
    $ffmpeg = fakeExecutable(Executable::FFMpeg);
    $ffprobe = fakeExecutable(Executable::FFProbe);
    $packager = fakeExecutable(Executable::Packager);
    config(['media.executables.ab-av1' => 'laravel-media-missing-ab-av1']);
    Process::fake([
        '*ffmpeg*' => Process::result(output: "ffmpeg version 7.1.1\nbuilt with gcc"),
        '*' => Process::result(output: 'version 3.4.2'),
    ]);

    $this->artisan('media:info')
        ->expectsTable(['Executable', 'Status', 'Path', 'Version'], [
            ['ffmpeg', '<fg=green>found</>', $ffmpeg, 'ffmpeg version 7.1.1'],
            ['ffprobe', '<fg=green>found</>', $ffprobe, 'version 3.4.2'],
            ['packager', '<fg=green>found</>', $packager, 'version 3.4.2'],
            ['ab-av1', '<fg=red>missing</>', 'set MEDIA_AB_AV1_PATH', ''],
        ])
        ->assertSuccessful();
});
