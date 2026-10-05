<?php

declare(strict_types=1);

use Foxws\Media\Probe\Probe;
use Foxws\Media\Testing\FakeProbe;

it('builds a video probe with audio, subtitles and hdr', function () {
    $probe = Probe::fromArray(FakeProbe::video(duration: 90, width: 3840, height: 2160, subtitles: ['eng'], transfer: 'smpte2084'));

    expect($probe->duration())->toBe(90.0)
        ->and($probe->videoStream())->width->toBe(3840)->height->toBe(2160)->frameRate->toBe(30.0)
        ->and($probe->videoStream()?->isHdr())->toBeTrue()
        ->and($probe->hasAudio())->toBeTrue()
        ->and($probe->subtitleStreams()[0]->language)->toBe('eng');
});

it('builds a silent video and an audio-only file', function () {
    expect(Probe::fromArray(FakeProbe::video(audio: false))->hasAudio())->toBeFalse()
        ->and(Probe::fromArray(FakeProbe::audio(codec: 'opus')))
        ->hasVideo()->toBeFalse()
        ->audioStream()->codecName->toBe('opus');
});

it('builds a video with an audio stream per language, the first as the default', function () {
    $streams = Probe::fromArray(FakeProbe::video(audioLanguages: ['eng', 'jpn']))->audioStreams();

    expect($streams)->toHaveCount(2)
        ->and($streams[0])->language->toBe('eng')->get('disposition.default')->toBe(1)
        ->and($streams[1])->language->toBe('jpn')->get('disposition.default')->toBe(0)
        ->and(Probe::fromArray(FakeProbe::video(audio: false, audioLanguages: ['eng']))->hasAudio())->toBeFalse();
});
