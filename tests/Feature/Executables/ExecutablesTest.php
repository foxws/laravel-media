<?php

declare(strict_types=1);

use Foxws\Media\Exceptions\ExecutableNotFoundException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Tests\Fixtures\AddOnExecutable;

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
    config(['add-on.executables.encoder' => 'laravel-media-missing-encoder']);

    expect(Executables::make()->available(AddOnExecutable::Encoder))->toBeFalse();

    Executables::make()->path(AddOnExecutable::Encoder);
})->throws(ExecutableNotFoundException::class, 'The encoder executable [laravel-media-missing-encoder] could not be found. Install it, or set ADD_ON_ENCODER_PATH to its path.');

it('resolves executables of other packages from their own configuration', function () {
    config(['add-on.executables.encoder' => 'sh']);

    expect(pathinfo(Executables::make()->path(AddOnExecutable::Encoder), PATHINFO_FILENAME))->toBe('sh');
})->skipOnWindows();

it('lists its own executables and the registered ones', function () {
    expect(Executables::make()->all())->toBe(Executable::cases());

    Executables::make()->register(AddOnExecutable::Encoder)->register(AddOnExecutable::Encoder);

    expect(Executables::make()->all())->toBe([...Executable::cases(), AddOnExecutable::Encoder]);
});

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
