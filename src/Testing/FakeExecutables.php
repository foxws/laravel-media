<?php

declare(strict_types=1);

namespace Foxws\Media\Testing;

use Foxws\Media\Executables\Binary;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;

/**
 * Reports every executable as installed, under its own name.
 */
class FakeExecutables extends Executables
{
    public function path(Binary $executable): string
    {
        return $executable->identifier();
    }

    public function available(Binary $executable): bool
    {
        return true;
    }
}
