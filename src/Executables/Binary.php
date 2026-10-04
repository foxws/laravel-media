<?php

declare(strict_types=1);

namespace Foxws\Media\Executables;

/**
 * A program the Runner can run. Packages that add an executable implement it, usually on an enum,
 * and register it with Executables::register() to list it in media:info and "php artisan about".
 */
interface Binary
{
    /**
     * The executable's name, as shown in logs, errors and media:info.
     */
    public function identifier(): string;

    /**
     * The configured path or name of the executable, looked up in the PATH when it has no directory.
     */
    public function configuredPath(): string;

    /**
     * The environment variable that configures this executable's path.
     */
    public function environmentKey(): string;

    /**
     * The arguments that make the executable print its version.
     *
     * @return list<string>
     */
    public function versionArguments(): array;
}
