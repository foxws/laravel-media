<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Playability;
use Foxws\Media\Probe\Probe;
use Foxws\Media\Testing\FakeProbe;

/**
 * @param  array<string, mixed>  $video
 * @param  list<string>  $audio
 */
function playability(array $video = [], array $audio = ['aac']): Playability
{
    $probe = FakeProbe::video(audio: false, subtitles: ['eng']);
    $probe['streams'][0] = [...$probe['streams'][0], ...$video];

    foreach ($audio as $codec) {
        $probe['streams'][] = ['index' => count($probe['streams']), 'codec_type' => 'audio', 'codec_name' => $codec, 'channels' => 2];
    }

    return new Playability(Probe::fromArray($probe));
}

it('plays the codecs browsers decode', function () {
    expect(playability()->isPlayable())->toBeTrue()
        ->and(playability(['codec_name' => 'hevc', 'pix_fmt' => 'yuv420p10le'], ['opus', 'flac'])->isPlayable())->toBeTrue()
        ->and(playability(['codec_name' => 'av1', 'pix_fmt' => null])->isPlayable())->toBeTrue();
});

it('re-encodes video with a codec or pixel format browsers cannot decode', function (array $video) {
    expect(playability($video)->needsVideoEncoding())->toBeTrue()
        ->and(playability($video)->isPlayable())->toBeFalse();
})->with([
    'mpeg-2' => [['codec_name' => 'mpeg2video']],
    'mpeg-4 part 2' => [['codec_name' => 'mpeg4']],
    '10-bit h264' => [['pix_fmt' => 'yuv420p10le']],
    '4:4:4 hevc' => [['codec_name' => 'hevc', 'pix_fmt' => 'yuv444p']],
]);

it('lists the audio streams browsers cannot decode by their position', function () {
    $playability = playability(audio: ['aac', 'dts', 'truehd']);

    expect($playability->audioNeedingEncoding())->toBe([1, 2])
        ->and($playability->needsVideoEncoding())->toBeFalse()
        ->and($playability->isPlayable())->toBeFalse();
});

it('follows the configured codecs', function () {
    config(['media.playback.video_codecs' => ['h264'], 'media.playback.audio_codecs' => ['aac', 'ac3']]);

    expect(playability(['codec_name' => 'hevc'])->needsVideoEncoding())->toBeTrue()
        ->and(playability(audio: ['ac3'])->isPlayable())->toBeTrue();
});

it('copies what plays and encodes only the audio that does not', function () {
    expect(playability(audio: ['aac', 'dts'])->format()->toArguments())->toBe([
        '-c:v', 'copy', '-c:a', 'copy',
        '-c:a:1', 'aac', '-b:a:1', '192k',
        '-c:s', 'copy',
    ]);
});

it('leaves the video and subtitles out of an audio only format', function () {
    expect(playability(audio: ['aac', 'dts'])->format(audioOnly: true)->toArguments())->toBe([
        '-vn', '-c:a', 'copy', '-sn',
        '-c:a:1', 'aac', '-b:a:1', '192k',
    ]);
});

it('encodes the video with the configured codec', function () {
    expect(playability(['codec_name' => 'mpeg2video'])->format()->toArguments())->toBe([
        '-c:v', 'libx264', '-crf', '20', '-preset', 'medium', '-c:a', 'copy',
        '-pix_fmt', 'yuv420p', '-c:s', 'copy',
    ]);

    config(['media.playback.video_codec' => 'libx265', 'media.playback.crf' => '22', 'media.playback.preset' => 'slow', 'media.playback.audio_bitrate' => 160]);

    expect(playability(['codec_name' => 'mpeg2video'], ['dts'])->format()->toArguments())
        ->toContain('libx265', '22', 'slow', 'hvc1', '160k');

    config(['media.playback.video_codec' => 'libsvtav1', 'media.playback.crf' => null, 'media.playback.preset' => '6']);

    expect(array_slice(playability(['codec_name' => 'mpeg2video'])->format()->toArguments(), 0, 6))->toBe(['-c:v', 'libsvtav1', '-crf', '30', '-preset', '6']);

    config(['media.playback.preset' => null, 'media.playback.video_codec' => 'libx265']);

    expect(playability(['codec_name' => 'mpeg2video'])->format()->toArguments())->toContain('24', 'medium');
});

it('defaults av1 to preset 8', function () {
    config(['media.playback.video_codec' => 'libsvtav1']);

    expect(playability(['codec_name' => 'mpeg2video'])->format()->toArguments())->toContain('8');
});

it('refuses video codecs it cannot encode for playback', function () {
    config(['media.playback.video_codec' => 'libvpx-vp9']);

    playability(['codec_name' => 'mpeg2video'])->format();
})->throws(InvalidArgumentException::class, 'media.playback.video_codec has to be libx264, libx265 or libsvtav1.');
