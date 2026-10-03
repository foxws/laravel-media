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
