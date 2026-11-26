<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\MissingCapabilitiesException;
use Foxws\Media\Executables\Capabilities;
use Foxws\Media\Executables\Capability;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Illuminate\Support\Facades\Process;

it('asks ffmpeg for every list', function () {
    fakeExecutable(Executable::FFMpeg);
    Process::fake([
        '*-encoders*' => Process::result(output: "Encoders:\n ------\n V....D libx264  libx264 H.264\n A....D aac  AAC\n"),
        '*-hwaccels*' => Process::result(output: "Hardware acceleration methods:\nvaapi\n"),
        '*' => Process::result(output: ''),
    ]);

    $capabilities = Media::capabilities();

    expect($capabilities->has(Capability::Encoders, 'libx264'))->toBeTrue()
        ->and($capabilities->has(Capability::HardwareAccelerations, 'vaapi'))->toBeTrue()
        ->and($capabilities->has(Capability::HardwareAccelerations, 'cuda'))->toBeFalse()
        ->and($capabilities->all(Capability::Encoders))->toBe(['libx264', 'aac']);
    Process::assertRanTimes(fn ($process) => runs($process, Executable::FFMpeg) && $process->command[1] === '-hide_banner', count(Capability::cases()));
});

it('lists what is missing and throws when required names are', function () {
    $capabilities = new Capabilities(['encoders' => ['libx264', 'aac'], 'muxers' => ['mp4']]);

    expect($capabilities->missing(['encoders' => ['libx264', 'libsvtav1'], 'muxers' => ['mp4'], 'hwaccels' => ['vaapi']]))
        ->toBe(['encoders' => ['libsvtav1'], 'hwaccels' => ['vaapi']])
        ->and($capabilities->require(['encoders' => ['aac']]))->toBe($capabilities);

    expect(fn () => $capabilities->require(['encoders' => ['libsvtav1'], 'hwaccels' => ['vaapi']]))
        ->toThrow(fn (MissingCapabilitiesException $exception) => expect($exception)
            ->getMessage()->toBe('The ffmpeg build is missing encoders [libsvtav1], hwaccels [vaapi].')
            ->missing->toBe(['encoders' => ['libsvtav1'], 'hwaccels' => ['vaapi']]));
});

it('rejects an unknown kind', function () {
    new Capabilities()->missing(['codecs' => ['h264']]);
})->throws(ValueError::class);

it('fakes what ffmpeg was built with', function () {
    Media::fake()->withCapabilities(['encoders' => ['libx264'], 'hwaccels' => ['vaapi']]);

    expect(Media::capabilities()->missing(['encoders' => ['libx264', 'libsvtav1'], 'hwaccels' => ['vaapi']]))
        ->toBe(['encoders' => ['libsvtav1']]);
    Media::assertNothingRan();
});
