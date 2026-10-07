<?php

declare(strict_types=1);

use Foxws\Media\Encoding\Format;
use Foxws\Media\Encoding\HardwareAcceleration;
use Foxws\Media\Events\ExportCompleted;
use Foxws\Media\Events\ExportFailed;
use Foxws\Media\Events\ProgressReported;
use Foxws\Media\Exceptions\FailureReason;
use Foxws\Media\Exceptions\InvalidFilterException;
use Foxws\Media\Exceptions\InvalidFormatException;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Exceptions\MediaNotFoundException;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\FFMpeg\Clip;
use Foxws\Media\FFMpeg\Output;
use Foxws\Media\Filters\Fade;
use Foxws\Media\Filters\Loudnorm;
use Foxws\Media\Filters\Position;
use Foxws\Media\Filters\Scale;
use Foxws\Media\Filters\Tonemap;
use Foxws\Media\Filters\ToneMapAlgorithm;
use Foxws\Media\Filters\Volume;
use Foxws\Media\Process\Progress;
use Foxws\Media\Testing\FakeProbe;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
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
        '-ss', '12.5', '-t', '27.5', '-i', diskPath('videos', 'video.mp4'),
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

    expect($command)->toBe($ffmpeg.' -y -hide_banner -nostdin -loglevel error -ss 2 -t 2 -i '.diskPath('videos', 'video.mp4').' out.mp4');
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
            && end($process->command) === (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
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
        '-i', diskPath('branding', 'logo.png'),
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

    expect(array_slice($arguments, 5, 6))->toBe(['-ss', '10', '-t', '10', '-i', diskPath('videos', 'video.mp4')])
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

    expect($command)->toEndWith('-i '.diskPath('videos', 'video.mp4').' -vn -c:a aac -b:a 160k -sn -f ipod audio.m4a');
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

it('joins matching files without re-encoding through a concat list', function () {
    fakeProbes(['a.mp4' => videoProbe(), "it's.mp4" => videoProbe()]);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open(['a.mp4', "it's.mp4"])->ffmpeg()->concat()->arguments('joined.mp4');

    expect(array_slice($arguments, 5))->toBe([
        '-f', 'concat', '-safe', '0', '-protocol_whitelist', 'file,http,https,tcp,tls,crypto', '-i', 'concat.txt',
        '-c:v', 'copy', '-c:a', 'copy',
        'joined.mp4',
    ]);
});

it('writes the concat list with escaped paths when saving and removes it afterwards', function () {
    fakeExecutable(Executable::FFProbe);
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    $lists = [];
    Process::fake(['*' => function (PendingProcess $process) use (&$lists) {
        if (runs($process, Executable::FFProbe)) {
            return Process::result(output: (string) json_encode(videoProbe()));
        }

        $list = $process->command[array_search('-i', $process->command, true) + 1];
        $lists[$list] = file_get_contents($list);
        file_put_contents(end($process->command), 'joined');

        return Process::result();
    }]);

    Media::fromDisk('videos')->open(['a.mp4', "it's.mp4"])->ffmpeg()->concat()->save('joined.mp4');

    expect($lists)->toHaveCount(1)
        ->and(array_values($lists)[0])->toBe(
            "file '".diskPath('videos', 'a.mp4')."'\n"
            ."file '".str_replace("'", "'\\''", diskPath('videos', "it's.mp4"))."'\n",
        )
        ->and(array_key_first($lists))->toEndWith('/concat.txt')->not->toBeFile();
    Storage::disk('videos')->assertExists('joined.mp4');
});

it('refuses to concatenate files that differ without re-encoding', function () {
    fakeProbes(['a.mp4' => videoProbe(1920, 1080), 'b.mp4' => videoProbe(1280, 720)]);
    Storage::fake('videos');

    Media::fromDisk('videos')->open(['a.mp4', 'b.mp4'])->ffmpeg()->concat()->arguments('joined.mp4');
})->throws(InvalidMediaException::class, "can't be joined without re-encoding. Use clips()");

it('reports progress against the probed duration', function () {
    fakeProbes(['video.mp4' => videoProbe(duration: 40)], "out_time_us=10000000\nspeed=2x\nprogress=continue\nout_time_us=40000000\nspeed=2x\nprogress=end\n");
    Storage::fake('videos');
    $percentages = [];

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->onProgress(function (Progress $progress) use (&$percentages) {
            $percentages[] = $progress->percentage();
        })
        ->save('out.mp4');

    expect($percentages)->toBe([25.0, 100.0]);
    Process::assertRan(fn ($process) => runs($process, Executable::FFMpeg) && array_slice($process->command, 1, 3) === ['-progress', 'pipe:1', '-nostats']);
});

it('does not ask ffmpeg for progress without a progress callback', function () {
    fakeProbes(['video.mp4' => videoProbe()]);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->save('out.mp4');

    Process::assertDidntRun(fn ($process) => in_array('-progress', $process->command, true));
    Process::assertDidntRun(fn ($process) => runs($process, Executable::FFProbe));
});

it('expects the length of a clip, a reel or concatenated files', function () {
    fakeProbes(['a.mp4' => videoProbe(duration: 60), 'b.mp4' => videoProbe(duration: 30)]);
    Storage::fake('videos');
    $builder = fn () => Media::fromDisk('videos')->open(['a.mp4', 'b.mp4'])->ffmpeg();

    expect($builder()->expectedDuration())->toBe(60.0)
        ->and($builder()->clip(10, 25)->expectedDuration())->toBe(15.0)
        ->and($builder()->clip(50)->expectedDuration())->toBe(10.0)
        ->and($builder()->clips([Clip::make(0, 2), Clip::make(5, 9, 'b.mp4')])->expectedDuration())->toBe(6.0)
        ->and($builder()->concat()->expectedDuration())->toBe(90.0)
        ->and($builder()->frame(at: 5)->expectedDuration())->toBeNull();
});

it('reports both passes of a two-pass encode as one percentage', function () {
    fakeProbes(['video.mp4' => videoProbe(duration: 40)], "out_time_us=20000000\nprogress=continue\n");
    Storage::fake('videos');
    $percentages = [];

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->inFormat(Format::h264()->bitrate(2000)->twoPass())
        ->onProgress(function (Progress $progress) use (&$percentages) {
            $percentages[] = $progress->percentage();
        })
        ->save('out.mp4');

    expect($percentages)->toBe([25.0, 75.0]);
});

it('tone maps an hdr source before the other video filters', function () {
    fakeProbes(['video.mp4' => videoProbe(transfer: 'smpte2084')]);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->toneMap()
        ->addFilter(Scale::to(1280))
        ->arguments('out.mp4');

    expect($arguments[array_search('-vf', $arguments, true) + 1])->toBe((new Tonemap).',scale=1280:-2');
});

it('leaves an sdr source alone when tone mapping is asked for', function () {
    fakeProbes(['video.mp4' => videoProbe(transfer: 'bt709')]);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->toneMap()->arguments('out.mp4');

    expect($arguments)->not->toContain('-vf');
});

it('tone maps inside the watermark graph', function () {
    fakeProbes(['video.mp4' => videoProbe(transfer: 'arib-std-b67')]);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->toneMap(new Tonemap(ToneMapAlgorithm::Mobius))
        ->watermark('logo.png')
        ->arguments('out.mp4');

    expect($arguments[array_search('-filter_complex', $arguments, true) + 1])
        ->toStartWith('[0:v]'.new Tonemap(ToneMapAlgorithm::Mobius).'[base];');
});

it('runs ffmpeg with its own timeout instead of the configured one', function () {
    fakeExecutable(Executable::FFMpeg);
    config(['media.timeout' => 14400]);
    Storage::fake('videos');
    fakeFFMpegWriting();

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->timeout(120)->save('out.mp4');

    Process::assertRan(fn ($process) => $process->timeout === 120);
});

it('uses the configured ffmpeg log level', function () {
    fakeExecutable(Executable::FFMpeg);
    config(['media.ffmpeg_log_level' => 'warning']);
    Storage::fake('videos');

    $arguments = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->arguments('out.mp4');

    expect(array_slice($arguments, 3, 2))->toBe(['-loglevel', 'warning']);
});

it('dispatches an export completed event with the context, paths and duration', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    fakeFFMpegWriting();
    Event::fake([ExportCompleted::class, ExportFailed::class]);

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->withContext(['video_id' => 1])
        ->withContext(['step' => 'clip'])
        ->save('clip.mp4');

    Event::assertDispatched(ExportCompleted::class, fn (ExportCompleted $event) => $event->context === ['video_id' => 1, 'step' => 'clip']
        && $event->result->paths() === ['clip.mp4']
        && $event->duration > 0);
    Event::assertNotDispatched(ExportFailed::class);
});

it('dispatches an export failed event when ffmpeg fails', function () {
    fakeExecutable(Executable::FFMpeg);
    Storage::fake('videos');
    Process::fake(['*' => Process::result(errorOutput: 'Invalid data found when processing input', exitCode: 1)]);
    Event::fake([ExportCompleted::class, ExportFailed::class]);

    rescue(fn () => Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->withContext(['video_id' => 1])->save('clip.mp4'), report: false);

    Event::assertDispatched(ExportFailed::class, fn (ExportFailed $event) => $event->context === ['video_id' => 1]
        && $event->exception instanceof ProcessFailedException);
    Event::assertNotDispatched(ExportCompleted::class);
});

it('reports progress events when something listens, even without a callback', function () {
    fakeProbes(['video.mp4' => videoProbe(duration: 40)], "out_time_us=10000000\nprogress=continue\n");
    Storage::fake('videos');
    $reported = [];
    Event::listen(ProgressReported::class, function (ProgressReported $event) use (&$reported) {
        $reported[] = [$event->progress->percentage(), $event->context];
    });

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->withContext(['video_id' => 1])->save('out.mp4');

    expect($reported)->toBe([[25.0, ['video_id' => 1]]]);
});

it('cancels the export when a progress callback returns false', function () {
    fakeProbes(['video.mp4' => videoProbe(duration: 40)], "out_time_us=10000000\nprogress=continue\nout_time_us=20000000\nprogress=continue\n");
    Storage::fake('videos');
    Event::fake([ExportFailed::class]);
    $seen = 0;

    $save = function () use (&$seen) {
        Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
            ->onProgress(function () use (&$seen) {
                $seen++;

                return false;
            })
            ->save('out.mp4');
    };

    expect($save)->toThrow(fn (ProcessFailedException $exception) => expect($exception->reason)->toBe(FailureReason::Cancelled));

    expect($seen)->toBe(1);
    Storage::disk('videos')->assertMissing('out.mp4');
    Event::assertDispatched(ExportFailed::class);
});

it('encodes on the gpu after decoding and filtering on the cpu', function () {
    Media::fake(['video.mp4' => FakeProbe::video()]);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->hardware(HardwareAcceleration::Vaapi)
        ->addFilter(Scale::to(1280))
        ->inFormat(Format::h264(crf: 22))
        ->save('encoded.mp4');

    $arguments = Media::commands(Executable::FFMpeg)[1];

    expect(array_slice($arguments, 5, 3))->toBe(['-vaapi_device', '/dev/dri/renderD128', '-i'])
        ->and($arguments)->toContain('-vf', 'scale=1280:-2,format=nv12,hwupload', 'h264_vaapi', '-qp', '22')
        ->not->toContain('-hwaccel', 'libx264', '-crf', '-pix_fmt');
});

it('encodes on the configured gpu', function () {
    config(['media.ladder.hardware' => 'qsv']);
    Media::fake(['video.mp4' => FakeProbe::video()]);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->hardware()->inFormat(Format::hevc())->save('encoded.mp4');

    expect(Media::commands(Executable::FFMpeg)[1])->toContain('-filter_hw_device', 'format=nv12,hwupload=extra_hw_frames=64', 'hevc_qsv', '-global_quality');
});

it('encodes on the cpu when the gpu cannot be opened', function () {
    Media::fake(['video.mp4' => FakeProbe::video()])->failNext(Executable::FFMpeg, 'No VA display found');
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->hardware(HardwareAcceleration::Vaapi)->inFormat(Format::h264())->save('encoded.mp4');

    expect(Media::commands(Executable::FFMpeg)[1])->toContain('libx264', '-crf', '-pix_fmt')
        ->not->toContain('-vaapi_device', 'format=nv12,hwupload', 'h264_vaapi');
});

it('uploads watermarked video to the gpu after the overlay', function () {
    Media::fake(['video.mp4' => FakeProbe::video()]);
    Storage::fake('videos');

    $builder = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->hardware(HardwareAcceleration::Vaapi)
        ->watermark('logo.png')
        ->inFormat(Format::h264());

    $arguments = $builder->arguments('out.mp4');

    expect($arguments[array_search('-filter_complex', $arguments, true) + 1])->toEndWith(',format=nv12,hwupload[v]');
});

it('skips the gpu when the video is not encoded', function () {
    Media::fake(['video.mp4' => FakeProbe::video()]);
    Storage::fake('videos');

    $builder = Media::fromDisk('videos')->open('video.mp4')->ffmpeg()->hardware(HardwareAcceleration::Vaapi);

    expect($builder->inFormat(Format::copy('mp4'))->acceleration())->toBe(HardwareAcceleration::None)
        ->and($builder->inFormat(Format::aac())->arguments('audio.m4a'))->not->toContain('-vaapi_device', '-vf');
    Media::assertRanTimes(Executable::FFMpeg, 0);
});

it('does not encode two passes on the gpu', function () {
    Media::fake(['video.mp4' => FakeProbe::video()]);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->ffmpeg()
        ->hardware(HardwareAcceleration::Nvenc)
        ->inFormat(Format::h264()->bitrate(2000)->twoPass())
        ->save('encoded.mp4');
})->throws(InvalidFormatException::class, "can't be combined with hardware()");
