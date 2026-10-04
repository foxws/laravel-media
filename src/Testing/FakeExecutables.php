<?php

declare(strict_types=1);

namespace Foxws\Media\Testing;

use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;

/**
 * Reports every executable as installed, under its own name.
 */
class FakeExecutables extends Executables
{
    public function path(Executable $executable): string
    {
        return $executable->value;
    }

    public function available(Executable $executable): bool
    {
        return true;
    }
}
