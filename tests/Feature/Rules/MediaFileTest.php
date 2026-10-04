<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\Rules\MediaFile;
use Foxws\Media\Testing\FakeProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * @return list<string>
 */
function mediaErrors(MediaFile $rule, mixed $file = null): array
{
    $validator = Validator::make(['upload' => $file ?? UploadedFile::fake()->create('upload.mp4', 100)], ['upload' => [$rule]]);

    return $validator->errors()->get('upload');
}

it('passes a video that meets every requirement', function () {
    Media::fake(['*' => FakeProbe::video(duration: 90, width: 1920, height: 1080, codec: 'hevc')]);

    $rule = MediaFile::video()->withAudio()->minDuration(10)->maxDuration(600)
        ->minDimensions(1280, 720)->maxDimensions(3840, 2160)
        ->videoCodecs(['h264', 'hevc'])->audioCodecs(['aac']);

    expect(mediaErrors($rule))->toBe([]);
});

it('rejects media without the required streams', function (MediaFile $rule, array $probe, string $error) {
    Media::fake(['*' => $probe]);

    expect(mediaErrors($rule))->toBe([$error]);
})->with([
    'audio as video' => [MediaFile::video(), FakeProbe::audio(), 'The upload must be a video.'],
    'silent video' => [MediaFile::video()->withAudio(), FakeProbe::video(audio: false), 'The upload must contain audio.'],
]);

it('rejects media that is too short or too long', function () {
    Media::fake(['*' => FakeProbe::video(duration: 5)]);

    expect(mediaErrors(MediaFile::any()->minDuration(10)))->toBe(['The upload must be at least 10 seconds long.']);

    Media::fake(['*' => FakeProbe::video(duration: 900)]);

    expect(mediaErrors(MediaFile::any()->maxDuration(600)))->toBe(['The upload may not be longer than 10 minutes.']);
});

it('rejects videos outside the dimensions', function () {
    Media::fake(['*' => FakeProbe::video(width: 640, height: 360)]);

    expect(mediaErrors(MediaFile::video()->minDimensions(1280, 720)))->toBe(['The upload must be at least 1280×720 pixels.']);

    Media::fake(['*' => FakeProbe::video(width: 7680, height: 4320)]);

    expect(mediaErrors(MediaFile::video()->maxDimensions(3840, 2160)))->toBe(['The upload may not be larger than 3840×2160 pixels.']);
});

it('rejects codecs that are not allowed', function () {
    Media::fake(['*' => FakeProbe::video(codec: 'mpeg4')]);

    expect(mediaErrors(MediaFile::video()->videoCodecs(['h264', 'hevc'])->audioCodecs(['opus'])))->toBe([
        'The upload must use one of these video codecs: h264, hevc.',
        'The upload must use one of these audio codecs: opus.',
    ]);
});

it('rejects files ffprobe cannot read, and values that are not files', function () {
    Media::fake()->failNext(Executable::FFProbe, 'Invalid data found when processing input');

    expect(mediaErrors(MediaFile::any()))->toBe(['The upload must be a readable media file.'])
        ->and(mediaErrors(MediaFile::any(), 'not a file'))->toBe(['The upload must be a readable media file.']);
});

it('probes the uploaded file itself', function () {
    $fake = Media::fake();
    $upload = UploadedFile::fake()->create('upload.mp4', 100);

    mediaErrors(MediaFile::any(), $upload);

    Media::assertRan(Executable::FFProbe, fn (array $arguments) => end($arguments) === str_replace('\\', '/', $upload->getRealPath()));
    expect($fake->commands(Executable::FFProbe))->toHaveCount(1);
});
