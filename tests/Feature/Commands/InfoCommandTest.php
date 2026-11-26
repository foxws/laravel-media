<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Facades\Media;
use Foxws\Media\Tests\Fixtures\AddOnExecutable;
use Illuminate\Support\Facades\Artisan;
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

it('checks what ffmpeg was built with and fails when something is missing', function () {
    Process::fake(['*' => Process::result(output: 'version 7.1.1')]);
    Media::fake()->withCapabilities(['encoders' => ['libx264', 'aac'], 'hwaccels' => ['cuda']]);

    $this->artisan('media:info', ['--encoder' => ['libx264', 'libsvtav1'], '--hwaccel' => ['vaapi']])
        ->expectsTable(['Executable', 'Status', 'Path', 'Version'], [
            ['ffmpeg', '<fg=green>found</>', 'ffmpeg', 'version 7.1.1'],
            ['ffprobe', '<fg=green>found</>', 'ffprobe', 'version 7.1.1'],
        ])
        ->expectsTable(['Capability', 'Name', 'Status'], [
            ['encoder', 'libx264', '<fg=green>found</>'],
            ['encoder', 'libsvtav1', '<fg=red>missing</>'],
            ['hwaccel', 'vaapi', '<fg=red>missing</>'],
        ])
        ->assertFailed();

    $this->artisan('media:info', ['--encoder' => ['libx264', 'aac']])->assertSuccessful();
});

it('prints the result as json', function () {
    Process::fake(['*' => Process::result(output: 'version 7.1.1')]);
    Media::fake()->withCapabilities(['encoders' => ['libx264']]);

    expect(Artisan::call('media:info', ['--encoder' => ['libx264', 'libsvtav1'], '--json' => true]))->toBe(1);

    $json = json_decode(Artisan::output(), true);

    expect($json['executables'][0])->toBe(['name' => 'ffmpeg', 'found' => true, 'path' => 'ffmpeg', 'version' => 'version 7.1.1', 'environment_key' => 'MEDIA_FFMPEG_PATH'])
        ->and($json['required'])->toBe(['encoders' => ['libx264', 'libsvtav1']])
        ->and($json['missing'])->toBe(['encoders' => ['libsvtav1']])
        ->and($json['error'])->toBeNull();
});

it('reports every requirement as missing when ffmpeg is not found', function () {
    config(['media.executables.ffmpeg' => 'laravel-media-missing-ffmpeg', 'media.executables.ffprobe' => 'laravel-media-missing-ffprobe']);
    app(Executables::class)->flush();

    $this->artisan('media:info', ['--muxer' => ['dash']])
        ->expectsTable(['Capability', 'Name', 'Status'], [['muxer', 'dash', '<fg=red>missing</>']])
        ->expectsOutputToContain('could not be found')
        ->assertFailed();
});
