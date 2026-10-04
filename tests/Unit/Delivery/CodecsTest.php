<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Codecs;
use Foxws\Media\Probe\Probe;

function codecsFor(array $video = [], array $audio = []): ?string
{
    return Codecs::for(Probe::fromArray(['streams' => array_values(array_filter([
        $video !== [] ? ['index' => 0, 'codec_type' => 'video', ...$video] : null,
        $audio !== [] ? ['index' => 1, 'codec_type' => 'audio', ...$audio] : null,
    ]))]));
}

it('describes h264 profiles and levels with aac', function () {
    expect(codecsFor(['codec_name' => 'h264', 'profile' => 'High', 'level' => 40], ['codec_name' => 'aac', 'profile' => 'LC']))->toBe('avc1.640028,mp4a.40.2')
        ->and(codecsFor(['codec_name' => 'h264', 'profile' => 'Main', 'level' => 31]))->toBe('avc1.4d001f')
        ->and(codecsFor(['codec_name' => 'h264', 'profile' => 'Constrained Baseline', 'level' => 30]))->toBe('avc1.42e01e')
        ->and(codecsFor(audio: ['codec_name' => 'aac', 'profile' => 'HE-AAC']))->toBe('mp4a.40.5')
        ->and(codecsFor(audio: ['codec_name' => 'mp3']))->toBe('mp4a.40.34');
});

it('leaves the codecs out when one of them cannot be described', function () {
    expect(codecsFor(['codec_name' => 'hevc'], ['codec_name' => 'aac']))->toBeNull()
        ->and(codecsFor(['codec_name' => 'h264', 'profile' => 'High 4:4:4 Predictive', 'level' => 40]))->toBeNull()
        ->and(codecsFor(['codec_name' => 'h264', 'profile' => 'High']))->toBeNull()
        ->and(codecsFor())->toBeNull();
});

it('describes hevc as hvc1 and the audio codecs of fragmented mp4', function () {
    expect(codecsFor(['codec_name' => 'hevc', 'profile' => 'Main', 'level' => 120]))->toBe('hvc1.1.6.L120.B0')
        ->and(codecsFor(['codec_name' => 'hevc', 'profile' => 'Main 10', 'level' => 153]))->toBe('hvc1.2.4.L153.B0')
        ->and(codecsFor(audio: ['codec_name' => 'opus']))->toBe('opus')
        ->and(codecsFor(audio: ['codec_name' => 'flac']))->toBe('fLaC');
});

it('joins codecs unless one of them is unknown', function () {
    expect(Codecs::join(['avc1.640028', 'mp4a.40.2']))->toBe('avc1.640028,mp4a.40.2')
        ->and(Codecs::join(['avc1.640028', null]))->toBeNull()
        ->and(Codecs::join([]))->toBeNull();
});

it('describes av1 profiles, levels and bit depths', function () {
    expect(codecsFor(['codec_name' => 'av1', 'profile' => 'Main', 'level' => 8, 'pix_fmt' => 'yuv420p']))->toBe('av01.0.08M.08')
        ->and(codecsFor(['codec_name' => 'av1', 'profile' => 'Main', 'level' => 13, 'pix_fmt' => 'yuv420p10le']))->toBe('av01.0.13M.10')
        ->and(codecsFor(['codec_name' => 'av1', 'profile' => 'High', 'level' => 9, 'pix_fmt' => 'yuv444p']))->toBe('av01.1.09M.08')
        ->and(codecsFor(['codec_name' => 'av1', 'profile' => 'Main', 'level' => -99]))->toBeNull()
        ->and(codecsFor(['codec_name' => 'av1']))->toBeNull();
});
