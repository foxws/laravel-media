<?php

declare(strict_types=1);

use Foxws\Media\Encoding\Format;
use Foxws\Media\Exceptions\InvalidFilterException;
use Foxws\Media\Exceptions\InvalidFormatException;
use Foxws\Media\Exceptions\MediaNotFoundException;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\FFMpeg\Output;
use Foxws\Media\Filters\Fade;
use Foxws\Media\Filters\Loudnorm;
use Foxws\Media\Filters\Position;
use Foxws\Media\Filters\Scale;
use Foxws\Media\Filters\Volume;
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
        '-ss', '12.5', '-t', '27.5', '-i', Storage::disk('videos')->path('video.mp4'),
        '-c:v', 'copy', '-c:a', 'copy', '-f', 'mp4',
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

    expect($command)->toBe($ffmpeg.' -y -hide_banner -nostdin -loglevel error -ss 2 -t 2 -i '.Storage::disk('videos')->path('video.mp4').' out.mp4');
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

it('encodes in two passes with a shared log file outside the output', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    fakeFFMpegWriting();

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->inFormat(Format::h264()->bitrate(2000)->twoPass())
        ->save('encoded.mp4');

    Process::assertRanTimes(fn () => true, 2);
    Process::assertRan(function ($process): bool {
        $passArguments = array_slice($process->command, -8, 7);

        return $passArguments[0] === '-pass' && $passArguments[1] === '1'
            && array_slice($passArguments, 4) === ['-an', '-f', 'null']
            && end($process->command) === '/dev/null';
    });
    Process::assertRan(fn ($process) => array_slice($process->command, -5, 2) === ['-pass', '2']
        && str_ends_with(end($process->command), '/encoded.mp4'));
    Storage::disk('videos')->assertExists('encoded.mp4');
    Storage::disk('videos')->assertMissing('ffmpeg2pass-0.log');
});

it('rejects two-pass encoding for codecs that do not support it', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    Process::fake();

    expect(fn () => Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->inFormat(Format::av1()->bitrate(2000)->twoPass())
        ->save('encoded.mp4'))
        ->toThrow(InvalidFormatException::class, 'Two-pass encoding is supported for libx264 and libvpx-vp9, not [libsvtav1].');

    Process::assertNothingRan();
});

it('rejects two-pass encoding without a target bitrate', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    Process::fake();

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->inFormat(Format::h264()->twoPass())->save('encoded.mp4');
})->throws(InvalidFormatException::class, 'Two-pass encoding needs a target bitrate.');

it('applies video and audio filters as separate chains in order', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->addFilter(Scale::to(1280), Fade::audioIn(1), Fade::in(1))
        ->addFilter(new Loudnorm)
        ->inFormat(Format::h264())
        ->arguments('out.mp4');

    expect(array_slice($arguments, 7, 4))->toBe(['-vf', 'scale=1280:-2,fade=t=in:st=0:d=1', '-af', 'afade=t=in:st=0:d=1,loudnorm=I=-16:TP=-1.5:LRA=11'])
        ->and($arguments[11])->toBe('-c:v');
});

it('overlays a watermark from another disk in a complex filter graph', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    Storage::fake('branding');

    $arguments = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->addFilter(Scale::to(1280))
        ->watermark('logo.png', 'branding', Position::TopRight, margin: 24, width: 200)
        ->arguments('out.mp4');

    expect(array_slice($arguments, 7))->toBe([
        '-i', Storage::disk('branding')->path('logo.png'),
        '-filter_complex', '[0:v]scale=1280:-2[base];[1:v]scale=200:-1,format=rgba[wm];[base][wm]overlay=x=W-w-24:y=24[v]',
        '-map', '[v]', '-map', '0:a?',
        'out.mp4',
    ]);
});

it('filters the audio inside the graph when a watermark is used', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->addFilter(Volume::times(0.5))
        ->watermark('logo.png')
        ->arguments('out.mp4');

    expect(array_slice($arguments, 9, 6))->toBe([
        '-filter_complex', '[0:v]null[base];[1:v]format=rgba[wm];[base][wm]overlay=x=W-w-16:y=H-h-16[v];[0:a]volume=0.5[a]',
        '-map', '[v]', '-map', '[a]',
    ]);
});

it('does not combine a watermark with stream maps', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->map('0:v')->watermark('logo.png')->arguments('out.mp4');
})->throws(InvalidFilterException::class, "can't be combined with map()");

it('writes several outputs in one run and saves them all', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    Storage::fake('captions');
    Process::fake(['*' => function (PendingProcess $process) {
        foreach (['/eng.vtt', '/nld.vtt'] as $suffix) {
            foreach ($process->command as $argument) {
                if (str_ends_with($argument, $suffix)) {
                    file_put_contents($argument, 'WEBVTT');
                }
            }
        }

        return Process::result();
    }]);

    $result = Media::fromDisk('videos')->open('video.mkv')->ffmpeg()
        ->addOutput('subtitles/nld.vtt', fn (Output $output) => $output->map('0:2')->inFormat(Format::webVtt()))
        ->addOutput('subtitles/eng.vtt', fn (Output $output) => $output->map('0:3')->inFormat(Format::webVtt()))
        ->toDisk('captions')
        ->save();

    expect($result->paths())->toBe(['subtitles/nld.vtt', 'subtitles/eng.vtt']);
    Storage::disk('captions')->assertExists(['subtitles/nld.vtt', 'subtitles/eng.vtt']);
    Process::assertRanTimes(fn () => true, 1);
});

it('places each output after its own options', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->clip(from: 10, to: 20)
        ->inFormat(Format::h264())
        ->addOutput('preview.mp4', fn (Output $output) => $output->addFilter(Scale::to(480))->inFormat(Format::h264(crf: 30)->withoutAudio()))
        ->addOutput('audio.m4a', fn (Output $output) => $output->inFormat(Format::aac()))
        ->arguments('full.mp4');

    expect(array_slice($arguments, 5, 6))->toBe(['-ss', '10', '-t', '10', '-i', Storage::disk('videos')->path('video.mp4')])
        ->and(array_slice($arguments, 11))->toBe([
            '-c:v', 'libx264', '-crf', '23', '-preset', 'medium', '-c:a', 'aac', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-f', 'mp4',
            'full.mp4',
            '-vf', 'scale=480:-2', '-c:v', 'libx264', '-crf', '30', '-preset', 'medium', '-an', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-f', 'mp4',
            'preview.mp4',
            '-vn', '-c:a', 'aac', '-b:a', '160k', '-sn', '-f', 'ipod',
            'audio.m4a',
        ]);
});

it('skips the main output when saving without a path', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');

    $command = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->addOutput('audio.m4a', fn (Output $output) => $output->inFormat(Format::aac()))
        ->command();

    expect($command)->toEndWith('-i '.Storage::disk('videos')->path('video.mp4').' -vn -c:a aac -b:a 160k -sn -f ipod audio.m4a');
});

it('fails to save without a path or outputs', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->save();
})->throws(MediaNotFoundException::class, 'Nothing to save.');

it('does not combine extra outputs with two-pass encoding or a watermark', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    $builder = fn () => Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->addOutput('audio.m4a');

    expect(fn () => $builder()->inFormat(Format::h264()->bitrate(2000)->twoPass())->save('out.mp4'))
        ->toThrow(InvalidFormatException::class, "can't be combined with addOutput()")
        ->and(fn () => $builder()->watermark('logo.png')->command('out.mp4'))
        ->toThrow(InvalidFilterException::class, "can't be combined with addOutput()");
});
