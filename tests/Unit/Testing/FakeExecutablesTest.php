<?php

declare(strict_types=1);

use Foxws\Media\Executables\Executable;
use Foxws\Media\Testing\FakeExecutables;

it('reports every executable as installed under its own name', function (Executable $executable) {
    $executables = new FakeExecutables;

    expect($executables->available($executable))->toBeTrue()
        ->and($executables->path($executable))->toBe($executable->value);
})->with(Executable::cases());
