<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Tests\Fixtures\AddOnExecutable;
use Illuminate\Support\Facades\Process;

it('lists found and missing executables, including registered ones', function () {
    $ffmpeg = fakeExecutable(Executable::FFMpeg);
    $ffprobe = fakeExecutable(Executable::FFProbe);
    config(['add-on.executables.encoder' => 'laravel-media-missing-encoder']);
    app(Executables::class)->register(AddOnExecutable::Encoder);
    Process::fake([
        '*ffmpeg*' => Process::result(output: "ffmpeg version 7.1.1\nbuilt with gcc"),
        '*' => Process::result(output: 'version 3.4.2'),
    ]);

    $this->artisan('media:info')
        ->expectsTable(['Executable', 'Status', 'Path', 'Version'], [
            ['ffmpeg', '<fg=green>found</>', $ffmpeg, 'ffmpeg version 7.1.1'],
            ['ffprobe', '<fg=green>found</>', $ffprobe, 'version 3.4.2'],
            ['encoder', '<fg=red>missing</>', 'set ADD_ON_ENCODER_PATH', ''],
        ])
        ->assertSuccessful();
});

it('shows why an executable could not report its version', function (string $errorOutput, string $version) {
    $ffmpeg = fakeExecutable(Executable::FFMpeg);
    $ffprobe = fakeExecutable(Executable::FFProbe);
    Process::fake([
        '*ffmpeg*' => Process::result(errorOutput: $errorOutput, exitCode: 127),
        '*' => Process::result(output: 'ffprobe version 7.1.1'),
    ]);

    $this->artisan('media:info')
        ->expectsTable(['Executable', 'Status', 'Path', 'Version'], [
            ['ffmpeg', '<fg=green>found</>', $ffmpeg, $version],
            ['ffprobe', '<fg=green>found</>', $ffprobe, 'ffprobe version 7.1.1'],
        ])
        ->assertSuccessful();
})->with([
    'missing library' => [
        'ffmpeg: error while loading shared libraries: libx264.so.164: cannot open shared object file: No such file or directory',
        'failed (exit 127): ffmpeg: error while loading shared libraries: libx264.so.164: cannot open shared object file: No such file or directory',
    ],
    'no output' => ['', 'failed (exit 127)'],
]);
