<?php

declare(strict_types=1);

use Foxws\Media\Delivery\HlsCodecs;
use Foxws\Media\Probe\Probe;

function codecsFor(array $video = [], array $audio = []): ?string
{
    return HlsCodecs::for(Probe::fromArray(['streams' => array_values(array_filter([
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
