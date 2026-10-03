<?php

declare(strict_types=1);

namespace Foxws\Media\Tests;

use Foxws\Media\MediaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            MediaServiceProvider::class,
        ];
    }
}
