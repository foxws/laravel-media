<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\ExecutableNotFoundException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;

it('uses a configured absolute path to an executable file', function () {
    $path = fakeExecutable(Executable::Packager);

    expect(Executables::make()->path(Executable::Packager))->toBe($path)
        ->and(Executables::make()->available(Executable::Packager))->toBeTrue();
});

it('finds a command name in the PATH', function () {
    config(['media.executables.ffmpeg' => 'sh']);

    expect(pathinfo(Executables::make()->path(Executable::FFMpeg), PATHINFO_FILENAME))->toBe('sh');
});

it('reports a missing executable with the environment key to set', function () {
    config(['media.executables.ab-av1' => 'laravel-media-missing-ab-av1']);

    expect(Executables::make()->available(Executable::AbAv1))->toBeFalse();

    Executables::make()->path(Executable::AbAv1);
})->throws(ExecutableNotFoundException::class, 'set MEDIA_AB_AV1_PATH to its path');

it('does not accept a configured path that is not executable', function () {
    $path = tempnam(sys_get_temp_dir(), 'media');
    config(['media.executables.ffprobe' => $path]);

    expect(Executables::make()->available(Executable::FFProbe))->toBeFalse();
})->skipOnWindows();

it('accepts scripts such as .bat files on Windows', function () {
    $path = tempnam(sys_get_temp_dir(), 'media').'.cmd';
    touch($path);
    config(['media.executables.ffprobe' => $path]);

    expect(Executables::make()->path(Executable::FFProbe))->toBe($path);
})->onlyOnWindows();
