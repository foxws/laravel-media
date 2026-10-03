<?php

declare(strict_types=1);

use Foxws\Media\Encoding\Format;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Fake ffmpeg by writing the output file it was asked to create.
 */
function fakeFFMpegWriting(string $contents = 'output'): void
{
    Process::fake(['*' => function (PendingProcess $process) use ($contents) {
        file_put_contents(end($process->command), $contents);

        return Process::result();
    }]);
}

it('clips the input and saves the result to the target disk', function () {
    $ffmpeg = fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    Storage::fake('clips');
    fakeFFMpegWriting('clip');

    $result = Media::fromDisk('videos')
        ->open('video.mp4')
        ->ffmpeg()
        ->clip(from: 12.5, to: 40)
        ->inFormat(Format::copy('mp4'))
        ->toDisk('clips')
        ->save('intro/clip.mp4');

    expect($result->disk()->name())->toBe('clips')
        ->and($result->paths())->toBe(['intro/clip.mp4']);
    Storage::disk('clips')->assertExists('intro/clip.mp4');
    expect(Storage::disk('clips')->get('intro/clip.mp4'))->toBe('clip');
    Process::assertRan(fn ($process) => array_slice($process->command, 0, -1) === [
        $ffmpeg, '-y', '-hide_banner', '-nostdin', '-loglevel', 'error',
        '-ss', '12.5', '-i', Storage::disk('videos')->path('video.mp4'),
        '-c:v', 'copy', '-c:a', 'copy', '-f', 'mp4',
        '-t', '27.5',
    ]);
});

it('saves to the source disk by default', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    fakeFFMpegWriting();

    $result = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->frame(at: 5)->save('thumb.jpg');

    expect($result->disk()->name())->toBe('videos');
    Storage::disk('videos')->assertExists('thumb.jpg');
    Process::assertRan(fn ($process) => array_slice($process->command, 5, 4) === ['error', '-ss', '5', '-i']
        && array_slice($process->command, -8, 7) === ['-an', '-q:v', '2', '-f', 'image2', '-frames:v', '1']);
});

it('maps a subtitle stream to webvtt', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    fakeFFMpegWriting();

    Media::fromDisk('videos')->open('video.mkv')->ffmpeg()->map('0:2')->inFormat(Format::webVtt())->save('captions/nld.vtt');

    Process::assertRan(fn ($process) => array_slice($process->command, -7, 6) === ['-map', '0:2', '-vn', '-an', '-f', 'webvtt']
        && str_ends_with(end($process->command), '/nld.vtt'));
});

it('adds every opened file as an input', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open(['a.mp4', 'b.mp4'])->ffmpeg()->addArgs(['-filter_complex', 'concat=n=2'])->arguments('out.mp4');

    expect(array_values(array_filter($arguments, fn (string $argument) => $argument === '-i')))->toHaveCount(2)
        ->and(array_slice($arguments, -3))->toBe(['-filter_complex', 'concat=n=2', 'out.mp4']);
});

it('removes its temporary output and writes nothing when ffmpeg fails', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    Process::fake(['*' => Process::result(errorOutput: 'Conversion failed!', exitCode: 1)]);

    expect(fn () => Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->save('out.mp4'))
        ->toThrow(ProcessFailedException::class, 'Conversion failed!');

    Storage::disk('videos')->assertMissing('out.mp4');
    Process::assertRan(fn ($process) => ! is_dir(dirname(end($process->command))));
});

it('shows the full command line it would run', function () {
    $ffmpeg = fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');

    $command = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->clip(from: 2, to: 4)->command('out.mp4');

    expect($command)->toBe($ffmpeg.' -y -hide_banner -nostdin -loglevel error -ss 2 -i '.Storage::disk('videos')->path('video.mp4').' -t 2 out.mp4');
});

it('hides decryption keys in the command line', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');

    $command = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->addInputArgs(['-decryption_key', '0123456789abcdef0123456789abcdef'])
        ->command('out.mp4');

    expect($command)->toContain('-decryption_key [REDACTED]')
        ->not->toContain('0123456789abcdef0123456789abcdef');
});

it('runs save callbacks around the export with the builder and result', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    fakeFFMpegWriting();
    $calls = [];

    $builder = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->beforeSaving(function ($builder) use (&$calls) {
            $calls[] = ['before', $builder];
            $builder->addArgs(['-an']);
        })
        ->afterSaving(function ($builder, $result) use (&$calls) {
            $calls[] = ['after', $builder, $result->paths(), Storage::disk('videos')->exists('out.mp4')];
        });

    $builder->save('out.mp4');

    expect($calls)->toBe([
        ['before', $builder],
        ['after', $builder, ['out.mp4'], true],
    ]);
    Process::assertRan(fn ($process) => in_array('-an', $process->command, true));
});

it('runs save callbacks only once', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    fakeFFMpegWriting();
    $count = 0;
    $builder = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->afterSaving(function () use (&$count) {
        $count++;
    });

    $builder->save('one.mp4');
    $builder->save('two.mp4');

    expect($count)->toBe(1);
});

it('does not run after saving callbacks when ffmpeg fails', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    Process::fake(['*' => Process::result(exitCode: 1)]);
    $called = false;

    rescue(fn () => Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->afterSaving(function () use (&$called) {
            $called = true;
        })
        ->save('out.mp4'), report: false);

    expect($called)->toBeFalse();
});
