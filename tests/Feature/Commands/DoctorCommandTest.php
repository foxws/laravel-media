<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Facades\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Tests\Fixtures\AddOnExecutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/laravel-media-doctor-'.getmypid();
    config(['media.temporary_files.root' => $this->root, 'media.temporary_files.cache_root' => null]);
    app()->instance(TemporaryDirectories::class, new TemporaryDirectories($this->root));
});

afterEach(function () {
    @rmdir($this->root);
});

it('passes when the executables run and the temporary files root is usable', function () {
    $ffmpeg = fakeExecutable(Executable::FFMpeg);
    $ffprobe = fakeExecutable(Executable::FFProbe);
    Process::fake([
        '*ffmpeg*' => Process::result(output: "ffmpeg version 7.1.1\nbuilt with gcc"),
        '*' => Process::result(output: 'ffprobe version 7.1.1'),
    ]);

    $this->artisan('media:doctor')
        ->expectsTable(['Check', 'Name', 'Status', 'Details'], [
            ['executable', 'ffmpeg', '<fg=green>ok</>', "{$ffmpeg} (ffmpeg version 7.1.1)"],
            ['executable', 'ffprobe', '<fg=green>ok</>', "{$ffprobe} (ffprobe version 7.1.1)"],
            ['temporary files', 'root', '<fg=green>ok</>', $this->root],
        ])
        ->assertSuccessful();

    expect(glob("{$this->root}/*"))->toBe([]);
});

it('warns about a missing executable of another package, but fails without ffmpeg', function () {
    fakeExecutable(Executable::FFProbe);
    config(['media.executables.ffmpeg' => 'laravel-media-missing-ffmpeg', 'add-on.executables.encoder' => 'laravel-media-missing-encoder']);
    app(Executables::class)->flush();
    app(Executables::class)->register(AddOnExecutable::Encoder);
    Process::fake(['*' => Process::result(output: 'ffprobe version 7.1.1')]);

    $this->artisan('media:doctor')
        ->expectsOutputToContain('not found, set MEDIA_FFMPEG_PATH')
        ->expectsOutputToContain('not found, set ADD_ON_ENCODER_PATH')
        ->assertFailed();

    Artisan::call('media:doctor', ['--json' => true]);
    $statuses = array_column(json_decode(Artisan::output(), true)['checks'], 'status', 'name');

    expect($statuses)->toMatchArray(['ffmpeg' => 'failed', 'ffprobe' => 'ok', 'encoder' => 'warning']);
});

it('fails when an executable cannot report its version', function (string $errorOutput, string $details) {
    $ffmpeg = fakeExecutable(Executable::FFMpeg);
    fakeExecutable(Executable::FFProbe);
    Process::fake([
        '*ffmpeg*' => Process::result(errorOutput: $errorOutput, exitCode: 127),
        '*' => Process::result(output: 'ffprobe version 7.1.1'),
    ]);

    $this->artisan('media:doctor')
        ->expectsOutputToContain(str_replace('{path}', $ffmpeg, $details))
        ->assertFailed();
})->with([
    'missing library' => [
        'ffmpeg: error while loading shared libraries: libx264.so.164: cannot open shared object file: No such file or directory',
        '{path} exited with code 127: ffmpeg: error while loading shared libraries: libx264.so.164',
    ],
    'no output' => ['', '{path} exited with code 127'],
]);

it('fails when the temporary files root lacks the minimum free space', function () {
    fakeExecutable(Executable::FFMpeg);
    fakeExecutable(Executable::FFProbe);
    Process::fake(['*' => Process::result(output: 'version 7.1.1')]);
    app()->instance(TemporaryDirectories::class, new TemporaryDirectories($this->root, minFreeBytes: PHP_INT_MAX));

    $this->artisan('media:doctor')
        ->expectsOutputToContain('Insufficient storage space')
        ->assertFailed();
});

it('checks the cache root when one is configured', function () {
    fakeExecutable(Executable::FFMpeg);
    fakeExecutable(Executable::FFProbe);
    Process::fake(['*' => Process::result(output: 'version 7.1.1')]);
    $cacheRoot = "{$this->root}-cache";
    config(['media.temporary_files.cache_root' => $cacheRoot]);
    app()->instance(TemporaryDirectories::class, new TemporaryDirectories($this->root, cacheRoot: $cacheRoot));

    Artisan::call('media:doctor', ['--json' => true]);

    expect(json_decode(Artisan::output(), true)['checks'])->toContain(['check' => 'temporary files', 'name' => 'cache_root', 'status' => 'ok', 'details' => $cacheRoot]);

    @rmdir($cacheRoot);
});

it('checks what ffmpeg was built with and fails when something is missing', function () {
    Process::fake(['*' => Process::result(output: 'version 7.1.1')]);
    Media::fake()->withCapabilities(['encoders' => ['libx264', 'aac'], 'hwaccels' => ['cuda']]);

    $this->artisan('media:doctor', ['--encoder' => ['libx264', 'libsvtav1'], '--hwaccel' => ['vaapi']])
        ->expectsTable(['Check', 'Name', 'Status', 'Details'], [
            ['executable', 'ffmpeg', '<fg=green>ok</>', 'ffmpeg (version 7.1.1)'],
            ['executable', 'ffprobe', '<fg=green>ok</>', 'ffprobe (version 7.1.1)'],
            ['temporary files', 'root', '<fg=green>ok</>', $this->root],
            ['encoder', 'libx264', '<fg=green>ok</>', 'compiled in'],
            ['encoder', 'libsvtav1', '<fg=red>failed</>', 'not compiled in'],
            ['hwaccel', 'vaapi', '<fg=red>failed</>', 'not compiled in'],
        ])
        ->assertFailed();

    $this->artisan('media:doctor', ['--encoder' => ['libx264', 'aac']])->assertSuccessful();
});

it('prints the checks as json', function () {
    Process::fake(['*' => Process::result(output: 'version 7.1.1')]);
    Media::fake()->withCapabilities(['encoders' => ['libx264']]);

    expect(Artisan::call('media:doctor', ['--encoder' => ['libx264', 'libsvtav1'], '--json' => true]))->toBe(1);

    expect(json_decode(Artisan::output(), true))->toBe([
        'healthy' => false,
        'checks' => [
            ['check' => 'executable', 'name' => 'ffmpeg', 'status' => 'ok', 'details' => 'ffmpeg (version 7.1.1)'],
            ['check' => 'executable', 'name' => 'ffprobe', 'status' => 'ok', 'details' => 'ffprobe (version 7.1.1)'],
            ['check' => 'temporary files', 'name' => 'root', 'status' => 'ok', 'details' => $this->root],
            ['check' => 'encoder', 'name' => 'libx264', 'status' => 'ok', 'details' => 'compiled in'],
            ['check' => 'encoder', 'name' => 'libsvtav1', 'status' => 'failed', 'details' => 'not compiled in'],
        ],
    ]);
});

it('fails every requirement with the reason when ffmpeg is not found', function () {
    config(['media.executables.ffmpeg' => 'laravel-media-missing-ffmpeg']);
    fakeExecutable(Executable::FFProbe);
    app(Executables::class)->flush();
    Process::fake(['*' => Process::result(output: 'version 7.1.1')]);

    Artisan::call('media:doctor', ['--muxer' => ['dash'], '--json' => true]);

    expect(json_decode(Artisan::output(), true)['checks'])->toContain(['check' => 'muxer', 'name' => 'dash', 'status' => 'failed', 'details' => 'The ffmpeg executable [laravel-media-missing-ffmpeg] could not be found. Install it, or set MEDIA_FFMPEG_PATH to its path.']);
});
