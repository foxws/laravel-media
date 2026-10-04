<?php

declare(strict_types=1);

namespace Foxws\Media;

use Foxws\Media\Commands\CleanCommand;
use Foxws\Media\Commands\InfoCommand;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Exporter;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Packaging\PackagerManager;
use Foxws\Media\Probe\Prober;
use Foxws\Media\Process\Runner;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class MediaServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/media.php', 'media');

        $this->app->singleton(Executables::class);
        $this->app->singleton(Exporter::class);
        $this->app->singleton(Prober::class);
        $this->app->singleton(MediaFactory::class);
        $this->app->singleton(PackagerManager::class);

        $this->app->singleton(TemporaryDirectories::class, fn (): TemporaryDirectories => new TemporaryDirectories(
            root: Config::string('media.temporary_files.root', sys_get_temp_dir()),
            cacheRoot: Config::get('media.temporary_files.cache_root'),
            minFreeBytes: Config::integer('media.temporary_files.min_free', 0),
            sizeMultiplier: Config::float('media.temporary_files.size_multiplier', 1.5),
            cacheMinFreeBytes: Config::integer('media.temporary_files.cache_min_free', 0),
        ));

        $this->app->singleton(Runner::class, fn (Application $app): Runner => new Runner(
            executables: $app->make(Executables::class),
            logger: $this->logger($app),
            timeout: Config::integer('media.timeout', 14400),
        ));

        $this->app->bind(Opener::class, fn (Application $app): Opener => new Opener(
            disk: Disk::make(Config::get('media.disk') ?: Config::string('filesystems.default')),
            directories: $app->make(TemporaryDirectories::class),
            prober: $app->make(Prober::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->terminating(fn () => $this->app->make(TemporaryDirectories::class)->deleteAll());

        $this->cleanUpAfterQueueJobs();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            CleanCommand::class,
            InfoCommand::class,
        ]);

        AboutCommand::add('Media', fn (): array => $this->aboutSection());

        $this->publishes([
            __DIR__.'/../config/media.php' => config_path('media.php'),
        ], ['media', 'media-config']);
    }

    /**
     * The "Media" section of "php artisan about": where media is read and written, and which executables were found.
     *
     * @return array<string, string>
     */
    protected function aboutSection(): array
    {
        $executables = $this->app->make(Executables::class);
        $disk = Config::get('media.disk');

        $section = [
            'Disk' => is_string($disk) && $disk !== '' ? $disk : Config::string('filesystems.default'),
            'Temporary files' => Config::string('media.temporary_files.root'),
            'Timeout' => Config::integer('media.timeout', 14400).'s',
        ];

        foreach (Executable::cases() as $executable) {
            $section[ucfirst($executable->value)] = $executables->available($executable)
                ? $executables->path($executable)
                : 'not found';
        }

        return $section;
    }

    /**
     * Queue workers don't terminate between jobs, so delete each job's temporary directories when
     * it finishes. When a job times out, the worker kills itself right after, so stop the running
     * ffmpeg first, which would otherwise keep running as an orphan.
     */
    protected function cleanUpAfterQueueJobs(): void
    {
        $events = $this->app->make('events');

        $events->listen([JobProcessed::class, JobExceptionOccurred::class], function (): void {
            if (Config::boolean('media.temporary_files.cleanup_after_jobs', true)) {
                $this->app->make(TemporaryDirectories::class)->deleteAll();
            }
        });

        $events->listen([JobTimedOut::class, WorkerStopping::class], function (): void {
            if ($this->app->resolved(Runner::class)) {
                $this->app->make(Runner::class)->stopRunning();
            }

            $this->app->make(TemporaryDirectories::class)->deleteAll();
        });
    }

    protected function logger(Application $app): ?LoggerInterface
    {
        $channel = Config::get('media.log_channel');

        if ($channel === false || $channel === 'false') {
            return null;
        }

        return $app->make('log')->channel(is_string($channel) && $channel !== '' ? $channel : null);
    }
}
