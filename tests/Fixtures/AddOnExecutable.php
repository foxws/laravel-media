<?php

declare(strict_types=1);

namespace Foxws\Media\Tests\Fixtures;

use Foxws\Media\Executables\Binary;
use Illuminate\Support\Facades\Config;

/**
 * An executable another package would add, configured under its own config key.
 */
enum AddOnExecutable: string implements Binary
{
    case Encoder = 'encoder';

    public function identifier(): string
    {
        return $this->value;
    }

    public function configuredPath(): string
    {
        return Config::string("add-on.executables.{$this->value}", $this->value);
    }

    public function environmentKey(): string
    {
        return 'ADD_ON_ENCODER_PATH';
    }

    public function versionArguments(): array
    {
        return ['--version'];
    }
}
