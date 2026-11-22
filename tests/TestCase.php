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

    /**
     * Tests compare whole commands, so the input whitelists are left out except in InputWhitelistTest.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('media.inputs.format_whitelist', null);
    }
}
