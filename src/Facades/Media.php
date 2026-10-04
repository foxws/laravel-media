<?php

declare(strict_types=1);

namespace Foxws\Media\Facades;

use Foxws\Media\Executables\Executables;
use Foxws\Media\MediaConfig;
use Foxws\Media\MediaFactory;
use Foxws\Media\Probe\Prober;
use Foxws\Media\Process\Runner;
use Foxws\Media\Testing\FakeExecutables;
use Foxws\Media\Testing\FakeRunner;
use Foxws\Media\Testing\MediaFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Foxws\Media\Opener fromDisk(\Foxws\Media\Filesystem\Disk|\Illuminate\Contracts\Filesystem\Filesystem|string $disk)
 * @method static \Foxws\Media\Opener open(string|list<string> ...$paths)
 * @method static void assertRan(\Foxws\Media\Executables\Executable $executable, (\Closure(list<string>): bool)|null $callback = null)
 * @method static void assertNotRan(\Foxws\Media\Executables\Executable $executable, (\Closure(list<string>): bool)|null $callback = null)
 * @method static void assertRanTimes(\Foxws\Media\Executables\Executable $executable, int $times)
 * @method static void assertNothingRan()
 * @method static void assertProbed(string $path)
 * @method static void assertSaved(string $path, ?string $disk = null)
 * @method static void assertNotSaved(string $path, ?string $disk = null)
 *
 * @see MediaFactory
 */
class Media extends Facade
{
    /**
     * Fake media processing: nothing is executed, probes return fake data and ffmpeg writes
     * placeholder files. Fake the disks you save to with Storage::fake() as usual.
     *
     * @param  array<string, array<string, mixed>>  $probes  ffprobe output keyed by (the end of) a media path; see FakeProbe.
     */
    public static function fake(array $probes = []): MediaFake
    {
        $app = static::getFacadeApplication();

        if ($app === null) {
            return new MediaFake($probes);
        }

        $config = $app->make(MediaConfig::class);
        $fake = new MediaFake($probes, $config->disk);
        $executables = new FakeExecutables($config);

        $app->instance(Executables::class, $executables);
        $app->instance(Runner::class, new FakeRunner($fake, $executables, $config));
        $app->forgetInstance(Prober::class);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return MediaFactory::class;
    }
}
