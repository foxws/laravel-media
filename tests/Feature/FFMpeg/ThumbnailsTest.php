<?php

declare(strict_types=1);

use Foxws\Media\Events\ExportCompleted;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\Filters\Tonemap;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Fake ffprobe with a video of the given duration, and ffmpeg writing the number of sheets it was asked for.
 */
function fakeThumbnailProcesses(float $duration, bool $video = true, ?string $transfer = null): void
{
    fakeExecutable(Executable::FFProbe);
    fakeExecutable(Executable::FFMpeg);

    Process::fake(['*' => function (PendingProcess $process) use ($duration, $video, $transfer) {
        if (runs($process, Executable::FFProbe)) {
            return Process::result(output: json_encode([
                'streams' => $video ? [['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'color_transfer' => $transfer]] : [],
                'format' => ['duration' => (string) $duration],
            ]));
        }

        $sheets = (int) $process->command[array_search('-frames:v', $process->command, true) + 1];

        foreach (range(1, $sheets) as $sheet) {
            file_put_contents(sprintf(end($process->command), $sheet), 'sheet');
        }

        return Process::result();
    }]);
}

it('samples the video into sprite sheets with a webvtt file', function () {
    fakeThumbnailProcesses(duration: 25);
    Storage::fake('videos');
    Storage::fake('storyboards');

    $result = Media::fromDisk('videos')->open('video.mp4')->thumbnails()
        ->every(10)
        ->toDisk('storyboards')
        ->save('1/storyboard');

    expect($result->sprites)->toBe(['1/storyboard_001.jpg'])
        ->and($result->vtt)->toBe('1/storyboard.vtt')
        ->and($result->interval)->toBe(10.0)
        ->and($result->count)->toBe(3);
    expect(Storage::disk('storyboards')->get('1/storyboard.vtt'))->toBe(<<<'VTT'
        WEBVTT

        00:00:00.000 --> 00:00:10.000
        storyboard_001.jpg#xywh=0,0,160,90

        00:00:10.000 --> 00:00:20.000
        storyboard_001.jpg#xywh=160,0,160,90

        00:00:20.000 --> 00:00:25.000
        storyboard_001.jpg#xywh=320,0,160,90

        VTT);
});

it('runs ffmpeg once with time based sampling, letterboxed tiles, a grid fitted to the thumbnails and the sheet count', function () {
    fakeThumbnailProcesses(duration: 25);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->thumbnails()->every(10)->size(320, 180)->grid(5, 4)->save('storyboard');

    Process::assertRan(fn ($process) => runs($process, Executable::FFMpeg)
        && array_slice($process->command, 8, -1) === [
            '-map', '0:v:0',
            '-vf', 'fps=1/10,scale=320:180:force_original_aspect_ratio=decrease,pad=320:180:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,tile=3x1',
            '-an', '-sn', '-q:v', '4', '-frames:v', '1', '-f', 'image2',
        ]
        && str_ends_with(end($process->command), '/storyboard_%03d.jpg'));
});

it('fits the grid of a single sheet to its thumbnails', function () {
    fakeThumbnailProcesses(duration: 25);
    Storage::fake('videos');

    $result = Media::fromDisk('videos')->open('video.mp4')->thumbnails()->every(2)->grid(10, 10)->save('storyboard');

    expect([$result->count, $result->columns, $result->rows])->toBe([13, 10, 2])
        ->and(Storage::disk('videos')->get('storyboard.vtt'))->toContain("00:00:24.000 --> 00:00:25.000\nstoryboard_001.jpg#xywh=320,90,160,90");
    Process::assertRan(fn ($process) => runs($process, Executable::FFMpeg) && str_contains(implode(' ', $process->command), ',tile=10x2 '));
});

it('decodes only keyframes when asked', function () {
    fakeThumbnailProcesses(duration: 25);
    Storage::fake('videos');

    Media::fromDisk('videos')->open('video.mp4')->thumbnails()->every(10)->save('storyboard');
    Media::fromDisk('videos')->open('video.mp4')->thumbnails()->every(10)->keyframesOnly()->save('keyframes');

    Process::assertRan(fn ($process) => runs($process, Executable::FFMpeg)
        && str_ends_with(end($process->command), '/storyboard_%03d.jpg')
        && ! in_array('-skip_frame', $process->command, true));
    Process::assertRan(fn ($process) => runs($process, Executable::FFMpeg)
        && str_ends_with(end($process->command), '/keyframes_%03d.jpg')
        && array_slice($process->command, array_search('-i', $process->command, true) - 2, 2) === ['-skip_frame', 'nokey']);
});

it('continues on the next sheet when the grid is full', function () {
    fakeThumbnailProcesses(duration: 60);
    Storage::fake('videos');

    $result = Media::fromDisk('videos')->open('video.mp4')->thumbnails()->every(10)->grid(2, 2)->save('storyboard');

    $vtt = Storage::disk('videos')->get('storyboard.vtt');

    expect($result->sprites)->toBe(['storyboard_001.jpg', 'storyboard_002.jpg'])
        ->and($result->count)->toBe(6)
        ->and([$result->columns, $result->rows, $result->width, $result->height])->toBe([2, 2, 160, 90])
        ->and($vtt)->toContain("00:00:30.000 --> 00:00:40.000\nstoryboard_001.jpg#xywh=160,90,160,90")
        ->and($vtt)->toContain("00:00:40.000 --> 00:00:50.000\nstoryboard_002.jpg#xywh=0,0,160,90");
});

it('spreads a number of thumbnails over the video, but not closer than the minimum interval', function () {
    fakeThumbnailProcesses(duration: 3600);
    Storage::fake('videos');
    $thumbnails = Media::fromDisk('videos')->open('video.mp4')->thumbnails();

    expect($thumbnails->interval(3600))->toBe(36.0)
        ->and($thumbnails->count(10)->interval(3600))->toBe(360.0)
        ->and($thumbnails->count(100, minimumInterval: 5)->interval(60))->toBe(5.0);
});

it('writes webp sheets and resolves cue urls', function () {
    fakeThumbnailProcesses(duration: 5);
    Storage::fake('videos');

    $result = Media::fromDisk('videos')->open('video.mp4')->thumbnails()
        ->every(5)
        ->format('webp', quality: 70)
        ->withUrl(fn (string $sprite) => "https://cdn.test/{$sprite}")
        ->save('storyboard');

    expect($result->sprites)->toBe(['storyboard_001.webp'])
        ->and(Storage::disk('videos')->get('storyboard.vtt'))->toContain('https://cdn.test/storyboard_001.webp#xywh=0,0,160,90');
    Process::assertRan(fn ($process) => in_array('libwebp', $process->command, true) && in_array('70', $process->command, true));
});

it('fails for media without a video stream or duration', function (float $duration, bool $video, string $message) {
    fakeThumbnailProcesses($duration, $video);
    Storage::fake('videos');

    expect(fn () => Media::fromDisk('videos')->open('song.mp3')->thumbnails()->save('storyboard'))
        ->toThrow(InvalidMediaException::class, $message);
})->with([
    'no video' => [120, false, 'song.mp3 has no video stream.'],
    'no duration' => [0, true, 'The duration of song.mp3 is unknown'],
]);

it('rejects unsupported sheet formats and intervals', function () {
    Storage::fake('videos');
    $thumbnails = Media::fromDisk('videos')->open('video.mp4')->thumbnails();

    expect(fn () => $thumbnails->format('png'))->toThrow(InvalidArgumentException::class, 'jpg or webp, not [png]')
        ->and(fn () => $thumbnails->every(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $thumbnails->count(0))->toThrow(InvalidArgumentException::class);
});

it('reports the progress of sampling', function () {
    fakeThumbnailProcesses(duration: 25);
    Storage::fake('videos');
    Media::fromDisk('videos')->open('video.mp4')->thumbnails()->every(10)
        ->onProgress(fn () => null)
        ->save('storyboard');

    Process::assertRan(fn ($process) => runs($process, Executable::FFMpeg) && array_slice($process->command, 1, 3) === ['-progress', 'pipe:1', '-nostats']);
});

it('tone maps hdr videos by default, unless turned off', function () {
    fakeThumbnailProcesses(duration: 25, transfer: 'smpte2084');
    Storage::fake('videos');
    $opener = Media::fromDisk('videos')->open('video.mp4');

    $opener->thumbnails()->every(10)->save('mapped');
    $opener->thumbnails()->every(10)->toneMap(null)->save('original');

    Process::assertRan(fn ($process) => runs($process, Executable::FFMpeg)
        && str_ends_with(end($process->command), 'mapped_%03d.jpg')
        && str_starts_with($process->command[array_search('-vf', $process->command, true) + 1], 'fps=1/10,'.new Tonemap.','));
    Process::assertRan(fn ($process) => runs($process, Executable::FFMpeg)
        && str_ends_with(end($process->command), 'original_%03d.jpg')
        && ! str_contains($process->command[array_search('-vf', $process->command, true) + 1], 'tonemap'));
});

it('passes its context to the export events', function () {
    fakeThumbnailProcesses(duration: 25);
    Storage::fake('videos');
    Event::fake([ExportCompleted::class]);

    Media::fromDisk('videos')->open('video.mp4')->thumbnails()->every(10)->withContext(['video_id' => 7])->save('storyboard');

    Event::assertDispatched(ExportCompleted::class, fn (ExportCompleted $event) => $event->context === ['video_id' => 7]);
});
