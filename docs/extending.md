---
section: Advanced
order: 1
---

# Extending

## Macros

`Opener` and `MediaFactory` are macroable, and the `Media` facade forwards `MediaFactory` macros:

```php
use Foxws\Media\Opener;

Opener::macro('poster', fn (float $at = 5.0) => $this->ffmpeg()->frame(at: $at));

Media::fromDisk('videos')->open('clip.mp4')->poster()->save('poster.jpg');
```

## Other executables

A package can run its own executable through the same runner as ffmpeg, with progress, cancelling, events, logging, redacted keys and `Media::fake()` support. Implement `Foxws\Media\Executables\Binary`, usually on an enum:

```php
use Foxws\Media\Executables\Binary;
use Illuminate\Support\Facades\Config;

enum EncoderExecutable: string implements Binary
{
    case Encoder = 'encoder';

    public function identifier(): string
    {
        return $this->value;
    }

    public function configuredPath(): string
    {
        return Config::string('encoder.path', 'encoder');
    }

    public function environmentKey(): string
    {
        return 'ENCODER_PATH';
    }

    public function versionArguments(): array
    {
        return ['--version'];
    }
}
```

Register it in a service provider, so `media:info` and `about` list it:

```php
use Foxws\Media\Executables\Executables;

$this->app->make(Executables::class)->register(EncoderExecutable::Encoder);
```

Then run it with `Foxws\Media\Process\Runner`:

```php
$result = app(Runner::class)->run(EncoderExecutable::Encoder, ['--input', $path], environment: ['ENCODER_LOG' => '1']);
```

`onOutput` receives the standard output and `onErrorOutput` the error output as they stream in, for example to parse progress; throw `ProcessCancelledException` from either to stop the run. Successful runs that wrote to the error output are logged as warnings. Pass `logWarnings: false` for programs that report their progress or results there:

```php
app(Runner::class)->run(
    EncoderExecutable::Encoder,
    ['--input', $path],
    onErrorOutput: fn (string $chunk) => $parser->feed($chunk),
    logWarnings: false,
);
```

In tests, `$fake->respondUsing(EncoderExecutable::Encoder, fn (array $arguments) => 'output')` fakes its output, and the assertions accept any `Binary`.

## Packager drivers

Packaging drivers implement `Foxws\Media\Packaging\Packager`: `package()` receives a `PackagingSpec` with the streams, settings and encryption, and writes into a temporary directory that's exported like any other output, and `command()` returns the redacted command line for the builder's `command()`:

```php
use Foxws\Media\Packaging\PackagerManager;

$this->app->make(PackagerManager::class)->extend('custom', fn () => new CustomPackager);
```

Pick it per export with `->using('custom')`, or for every export with `MEDIA_PACKAGER=custom`. Driver-specific settings are passed through `withOption()` and `withOptions()`.
