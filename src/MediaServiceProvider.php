<?php

declare(strict_types=1);

namespace Foxws\Media;

use Foxws\Media\Commands\InfoCommand;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Exporter;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Probe\Prober;
use Foxws\Media\Process\Runner;
use Illuminate\Contracts\Foundation\Application;
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

        $this->app->singleton(MediaConfig::class, fn (Application $app): MediaConfig => MediaConfig::fromArray(
            (array) $app->make('config')->get('media', []),
            (string) $app->make('config')->get('filesystems.default', 'local'),
        ));

        $this->app->singleton(Executables::class);
        $this->app->singleton(Exporter::class);
        $this->app->singleton(Prober::class);
        $this->app->singleton(MediaFactory::class);

        $this->app->singleton(TemporaryDirectories::class, function (Application $app): TemporaryDirectories {
            $config = $app->make(MediaConfig::class);

            return new TemporaryDirectories(
                root: $config->temporaryRoot,
                cacheRoot: $config->cacheRoot,
                minFreeBytes: $config->temporaryMinFree,
                sizeMultiplier: $config->temporarySizeMultiplier,
                cacheMinFreeBytes: $config->cacheMinFree,
            );
        });

        $this->app->singleton(Runner::class, fn (Application $app): Runner => new Runner(
            executables: $app->make(Executables::class),
            logger: $this->logger($app),
            timeout: $app->make(MediaConfig::class)->timeout,
        ));

        $this->app->bind(Opener::class, fn (Application $app): Opener => new Opener(
            disk: Disk::make($app->make(MediaConfig::class)->disk),
            directories: $app->make(TemporaryDirectories::class),
            prober: $app->make(Prober::class),
            config: $app->make(MediaConfig::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->terminating(fn () => $this->app->make(TemporaryDirectories::class)->deleteAll());

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InfoCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/media.php' => config_path('media.php'),
        ], ['media', 'media-config']);
    }

    protected function logger(Application $app): ?LoggerInterface
    {
        $channel = $app->make(MediaConfig::class)->logChannel;

        return $channel === false ? null : $app->make('log')->channel($channel);
    }
}
