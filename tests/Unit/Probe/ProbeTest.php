<?php

declare(strict_types=1);

use Foxws\Media\Probe\AudioStream;
use Foxws\Media\Probe\Probe;
use Foxws\Media\Probe\SubtitleStream;
use Foxws\Media\Probe\VideoStream;

it('maps ffprobe output to typed streams', function () {
    $probe = Probe::fromJson(file_get_contents(fixture('ffprobe.json')));

    expect($probe->streams)->sequence(
        fn ($stream) => $stream->toBeInstanceOf(VideoStream::class),
        fn ($stream) => $stream->toBeInstanceOf(AudioStream::class),
        fn ($stream) => $stream->toBeInstanceOf(SubtitleStream::class),
        fn ($stream) => $stream->toBeInstanceOf(VideoStream::class),
    );
});

it('reads the video stream properties', function () {
    $video = Probe::fromJson(file_get_contents(fixture('ffprobe.json')))->videoStream();

    expect($video)
        ->index->toBe(0)
        ->codecName->toBe('h264')
        ->width->toBe(1920)
        ->height->toBe(1080)
        ->pixelFormat->toBe('yuv420p')
        ->bitRate->toBe(4500000)
        ->frameRate->toEqualWithDelta(29.97, 0.001);
});

it('skips attached pictures such as cover art when picking the video stream', function () {
    $probe = Probe::fromArray(['streams' => [
        ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'mjpeg', 'disposition' => ['attached_pic' => 1]],
    ]]);

    expect($probe->videoStream())->toBeNull()
        ->and($probe->hasVideo())->toBeFalse()
        ->and($probe->videoStreams())->toHaveCount(1);
});

it('reads the audio and subtitle stream properties', function () {
    $probe = Probe::fromJson(file_get_contents(fixture('ffprobe.json')));

    expect($probe->audioStream())
        ->channels->toBe(2)
        ->channelLayout->toBe('stereo')
        ->sampleRate->toBe(48000)
        ->language->toBe('eng');

    expect($probe->subtitleStreams()[0])
        ->language->toBe('nld')
        ->forced()->toBeTrue();
});

it('reads the container format and chapters', function () {
    $probe = Probe::fromJson(file_get_contents(fixture('ffprobe.json')));

    expect($probe->format())
        ->longName->toBe('QuickTime / MOV')
        ->size->toBe(68000000)
        ->tags->toBe(['title' => 'Sample']);

    expect($probe->chapters())->sequence(
        fn ($chapter) => $chapter->title->toBe('Intro')->start->toBe(0.0)->end->toBe(60.0),
        fn ($chapter) => $chapter->title->toBeNull()->duration()->toEqualWithDelta(60.12, 0.0001),
    );
});

it('falls back to the longest stream when the container has no duration', function () {
    $probe = Probe::fromArray(['streams' => [
        ['index' => 0, 'codec_type' => 'video', 'duration' => '10.5'],
        ['index' => 1, 'codec_type' => 'audio', 'duration' => '12.25'],
    ]]);

    expect($probe->duration())->toBe(12.25);
});

it('finds a stream by its index', function () {
    $probe = Probe::fromJson(file_get_contents(fixture('ffprobe.json')));

    expect($probe->stream(2))->toBeInstanceOf(SubtitleStream::class)
        ->and($probe->stream(9))->toBeNull();
});

it('rejects output that is not json', function () {
    Probe::fromJson('not json');
})->throws(JsonException::class);
