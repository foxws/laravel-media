<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Foxws\Media\Concerns\HasSaveCallbacks;
use Foxws\Media\Concerns\ReportsProgress;
use Foxws\Media\Encoding\Format;
use Foxws\Media\Encoding\VideoCodec;
use Foxws\Media\Exceptions\InvalidFilterException;
use Foxws\Media\Exceptions\InvalidFormatException;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Exceptions\MediaNotFoundException;
use Foxws\Media\Exceptions\TemporaryFileException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Exporter;
use Foxws\Media\Filesystem\ExportResult;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Filesystem\TemporaryDirectory;
use Foxws\Media\Filters\Filter;
use Foxws\Media\Filters\FilterType;
use Foxws\Media\Filters\Number;
use Foxws\Media\Filters\Position;
use Foxws\Media\Filters\Tonemap;
use Foxws\Media\MediaConfig;
use Foxws\Media\Opener;
use Foxws\Media\Process\Runner;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;
use Throwable;

/**
 * Builds and runs an ffmpeg command with the opened media as inputs.
 */
class Builder
{
    use Conditionable;
    use HasSaveCallbacks;
    use ReportsProgress;

    /** @var list<string> */
    protected array $inputArguments = [];

    /** @var list<string> */
    protected array $arguments = [];

    /** @var list<string> */
    protected array $maps = [];

    protected ?Format $format = null;

    /** @var list<Filter> */
    protected array $filters = [];

    protected ?Media $watermark = null;

    protected string $watermarkFilter = '';

    /** @var list<Output> */
    protected array $outputs = [];

    protected ?Tonemap $toneMap = null;

    protected ?Reel $reel = null;

    protected bool $concat = false;

    /** @var array{from: float, to: float|null}|null */
    protected ?array $clipped = null;

    protected bool $singleFrame = false;

    protected ?TemporaryDirectory $concatDirectory = null;

    protected ?Disk $targetDisk = null;

    protected ?string $visibility = null;

    protected ?int $timeout = null;

    public function __construct(
        protected Opener $opener,
        protected Runner $runner,
        protected TemporaryDirectories $directories,
        protected Exporter $exporter,
        protected MediaConfig $config,
    ) {}

    /**
     * Only process the part between the given seconds, for every output. Seeks on the
     * input, so with stream copy the clip starts at the nearest keyframe before $from.
     */
    public function clip(float $from, ?float $to = null): static
    {
        $this->inputArguments = [...$this->inputArguments, '-ss', $this->seconds($from)];
        $this->clipped = ['from' => $from, 'to' => $to];

        if ($to !== null) {
            $this->inputArguments = [...$this->inputArguments, '-t', $this->seconds(max(0.0, $to - $from))];
        }

        return $this;
    }

    /**
     * Grab a single frame at the given second. Saves as JPEG unless another format is set.
     */
    public function frame(float $at): static
    {
        $this->inputArguments = [...$this->inputArguments, '-ss', $this->seconds($at)];
        $this->arguments = [...$this->arguments, '-frames:v', '1'];
        $this->format ??= Format::jpeg();
        $this->singleFrame = true;

        return $this;
    }

    /**
     * Select input streams, e.g. "0:2" for the stream with index 2.
     */
    public function map(string ...$specifiers): static
    {
        foreach ($specifiers as $specifier) {
            $this->maps = [...$this->maps, '-map', $specifier];
        }

        return $this;
    }

    public function inFormat(Format $format): static
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Add output arguments, e.g. ['-vf', 'scale=1280:-2'].
     *
     * @param  list<string>  $arguments
     */
    public function addArgs(array $arguments): static
    {
        $this->arguments = [...$this->arguments, ...$arguments];

        return $this;
    }

    /**
     * Add arguments placed before each input.
     *
     * @param  list<string>  $arguments
     */
    public function addInputArgs(array $arguments): static
    {
        $this->inputArguments = [...$this->inputArguments, ...$arguments];

        return $this;
    }

    /**
     * Apply filters to the output, in order. Video and audio filters form separate chains.
     */
    public function addFilter(Filter ...$filters): static
    {
        $this->filters = [...$this->filters, ...array_values($filters)];

        return $this;
    }

    /**
     * Convert HDR video to SDR when the source is HDR, before any other video filter.
     * SDR sources are left alone, so it's safe to always call for files of unknown origin.
     */
    public function toneMap(?Tonemap $toneMap = null): static
    {
        $this->toneMap = $toneMap ?? new Tonemap;

        return $this;
    }

    /**
     * Join clips of the opened files into one video, e.g. a reel of highlights. Clips are
     * re-encoded, so cuts are frame-accurate and files may differ in codec and size: they're
     * fitted to the given size, or to the first file's size when clips come from several files.
     * Filters apply to the joined video, and audio is dropped when a file has none.
     *
     * @param  list<Clip>  $clips
     */
    public function clips(array $clips, ?int $width = null, ?int $height = null, ?float $fps = null): static
    {
        if ($clips === []) {
            throw InvalidMediaException::noClips();
        }

        $this->reel = new Reel($this->opener, $clips, $width, $height, $fps);

        return $this;
    }

    /**
     * Join the opened files end to end without re-encoding. They must share codecs and
     * dimensions; use clips() to join files that differ. Streams are copied unless
     * another format is set.
     */
    public function concat(): static
    {
        $this->concat = true;

        return $this;
    }

    /**
     * Write another output in the same ffmpeg run, e.g. each subtitle track to its own file.
     * The callback configures its streams, format and filters.
     *
     * @param  (callable(Output): mixed)|null  $configure
     */
    public function addOutput(string $path, ?callable $configure = null): static
    {
        $output = new Output($path);

        if ($configure !== null) {
            $configure($output);
        }

        $this->outputs[] = $output;

        return $this;
    }

    /**
     * Overlay an image on the video, e.g. a logo. The image is read from the given disk,
     * or the disk the media was opened from, and scaled to $width pixels wide if given.
     */
    public function watermark(string $path, Disk|Filesystem|string|null $disk = null, Position $position = Position::BottomRight, int $margin = 16, ?int $width = null): static
    {
        $this->watermark = $this->opener->makeMedia($disk !== null ? Disk::make($disk) : $this->opener->disk(), $path);
        $this->watermarkFilter = ($width !== null ? "scale={$width}:-1," : '').'format=rgba';
        $this->watermarkFilter .= '[wm];[base][wm]overlay='.$position->overlay($margin);

        return $this;
    }

    /**
     * The disk to save to. Defaults to the disk the media was opened from.
     */
    public function toDisk(Disk|Filesystem|string $disk): static
    {
        $this->targetDisk = Disk::make($disk);

        return $this;
    }

    public function withVisibility(string $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    /**
     * The maximum seconds ffmpeg may run, instead of the configured media.timeout.
     * Keep it at or below the queue job's $timeout, so the job can handle the failure.
     */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * The disk the output is saved to.
     */
    public function disk(): Disk
    {
        return $this->targetDisk ?? $this->opener->disk();
    }

    public function media(): Opener
    {
        return $this->opener;
    }

    /**
     * The ffmpeg arguments for writing to the given output path.
     *
     * @param  list<string>  $passArguments  Arguments for one pass of a two-pass encode.
     * @return list<string>
     */
    public function arguments(?string $output, array $passArguments = [], ?TemporaryDirectory $directory = null): array
    {
        $inputs = match (true) {
            $this->reel !== null => $this->reel->inputs(),
            $this->concat => [...$this->inputArguments, ...$this->concatInput()],
            default => array_merge(...array_map(
                fn (Media $media): array => [...$this->inputArguments, '-i', $media->inputPath()],
                $this->opener->media(),
            )),
        };

        $format = $this->format ?? ($this->concat ? Format::copy() : null);

        $outputs = array_merge(...array_map(
            fn (Output $extra): array => $extra->toArguments($directory?->path($extra->path) ?? $extra->path),
            $this->outputs,
        ));

        return [
            '-y',
            '-hide_banner',
            '-nostdin',
            '-loglevel', $this->config->ffmpegLogLevel,
            ...$inputs,
            ...($this->watermark !== null ? ['-i', $this->watermark->inputPath()] : []),
            ...($output !== null ? [
                ...$this->filterArguments(),
                ...($format?->toArguments() ?? []),
                ...$this->arguments,
                ...$passArguments,
                $output,
            ] : []),
            ...$outputs,
        ];
    }

    /**
     * The maps and filter arguments. Plain -vf/-af chains, or a complex graph
     * with labelled outputs when a watermark adds a second video input.
     *
     * @return list<string>
     *
     * @throws InvalidFilterException
     */
    protected function filterArguments(): array
    {
        $reel = $this->reel;

        if ($reel !== null) {
            $this->ensureReelIsAlone();

            return $reel->arguments($this->filters, $this->toneMap);
        }

        $filters = $this->sourceFilters();

        if ($this->watermark === null) {
            return [...$this->maps, ...FilterChain::arguments($filters)];
        }

        if ($this->maps !== []) {
            throw InvalidFilterException::watermarkWithMaps();
        }

        if ($this->outputs !== []) {
            throw InvalidFilterException::watermarkWithOutputs();
        }

        $video = FilterChain::of($filters, FilterType::Video);
        $audio = FilterChain::of($filters, FilterType::Audio);

        $watermarkInput = $this->concat ? 1 : count($this->opener->media());

        $graph = sprintf(
            '[0:v]%s[base];[%d:v]%s[v]',
            $video !== '' ? $video : 'null',
            $watermarkInput,
            $this->watermarkFilter,
        );

        if ($audio !== '') {
            $graph .= ";[0:a]{$audio}[a]";
        }

        return ['-filter_complex', $graph, '-map', '[v]', '-map', $audio !== '' ? '[a]' : '0:a?'];
    }

    /**
     * The full command line, with sensitive values redacted. Without a path, only
     * the outputs added with addOutput() are included. For a two-pass format, this
     * is the command of the final pass without its pass options.
     */
    public function command(?string $output = null): string
    {
        return $this->runner->commandLine(Executable::FFMpeg, $this->arguments($output));
    }

    /**
     * Run ffmpeg and save every output to the target disk, keeping their relative
     * paths. Without a path, only the outputs added with addOutput() are written.
     *
     * @throws InvalidFormatException
     * @throws MediaNotFoundException
     */
    public function save(?string $path = null): ExportResult
    {
        if ($path === null && $this->outputs === []) {
            throw MediaNotFoundException::noOutputs();
        }

        $this->ensureValidPasses();

        $this->runBeforeSavingCallbacks();

        $directory = $this->directories->create();

        try {
            $declared = array_values(array_filter([$path, ...array_map(fn (Output $output): string => $output->path, $this->outputs)], is_string(...)));

            foreach ($declared as $file) {
                $directory->makeDirectory(dirname($file));
            }

            if ($this->concat) {
                $this->writeConcatList();
            }

            $this->encode($path !== null ? $directory->path($path) : null, $directory);

            $target = $this->disk();

            $written = $this->exporter->export($directory->path(), $target, visibility: $this->visibility, move: true);
        } finally {
            $directory->delete();
            $this->watermark?->cleanup();
            $this->concatDirectory?->delete();
            $this->concatDirectory = null;
        }

        $paths = array_values(array_unique([...array_map(fn (string $file): string => ltrim($file, '/'), $declared), ...$written]));

        $result = new ExportResult($target, array_values(array_intersect($paths, $written)));

        $this->runAfterSavingCallbacks($result);

        return $result;
    }

    /**
     * The filters for the main output, starting with the tone map when the (first) source is HDR.
     *
     * @return list<Filter>
     */
    protected function sourceFilters(): array
    {
        if ($this->toneMap === null || $this->opener->probe()->videoStream()?->isHdr() !== true) {
            return $this->filters;
        }

        return [$this->toneMap, ...$this->filters];
    }

    /**
     * The concat demuxer input, after checking the opened files can be joined without re-encoding.
     * The list file itself is written when saving, so inspecting the command has no side effects.
     *
     * @return list<string>
     *
     * @throws InvalidMediaException
     */
    protected function concatInput(): array
    {
        $this->ensureConcatenable();

        return [
            '-f', 'concat',
            '-safe', '0',
            '-protocol_whitelist', 'file,http,https,tcp,tls,crypto',
            '-i', $this->concatDirectory?->path('concat.txt') ?? 'concat.txt',
        ];
    }

    /**
     * Write the concat demuxer's list of opened files, outside the output directory.
     *
     * @throws TemporaryFileException
     */
    protected function writeConcatList(): void
    {
        $this->concatDirectory = $this->directories->createCache();

        $this->concatDirectory->put('concat.txt', implode('', array_map(
            fn (Media $media): string => "file '".str_replace("'", "'\\''", $media->inputPath())."'\n",
            $this->opener->media(),
        )));
    }

    /**
     * @throws InvalidMediaException
     */
    protected function ensureConcatenable(): void
    {
        $signatures = array_map(function (string $path): string {
            $probe = $this->opener->probe($path);
            $video = $probe->videoStream();
            $audio = $probe->audioStream();

            return implode('|', [$video?->codecName, $video?->width, $video?->height, $audio?->codecName, $audio?->sampleRate, $audio?->channels]);
        }, $this->opener->paths());

        if (count(array_unique($signatures)) > 1) {
            throw InvalidMediaException::notConcatenable($this->opener->paths());
        }
    }

    /**
     * @throws InvalidFilterException
     */
    protected function ensureReelIsAlone(): void
    {
        $conflict = match (true) {
            $this->maps !== [] => 'map()',
            $this->watermark !== null => 'watermark()',
            $this->outputs !== [] => 'addOutput()',
            $this->concat => 'concat()',
            $this->inputArguments !== [] => 'clip(), frame() or addInputArgs()',
            default => null,
        };

        if ($conflict !== null) {
            throw InvalidFilterException::clipsWith($conflict);
        }
    }

    /**
     * Run ffmpeg once, or twice for a two-pass format: the first pass only analyses
     * the video into a log file, kept outside the output directory.
     */
    protected function encode(?string $output, TemporaryDirectory $directory): void
    {
        if ($output === null || $this->format?->passes !== 2) {
            $this->run($this->arguments($output, directory: $directory));

            return;
        }

        $logDirectory = $this->directories->create();
        $log = $logDirectory->path('ffmpeg2pass');

        try {
            $this->run($this->arguments(
                PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null',
                ['-pass', '1', '-passlogfile', $log, '-an', '-f', 'null'],
            ), pass: 1, passes: 2);

            $this->run($this->arguments($output, ['-pass', '2', '-passlogfile', $log]), pass: 2, passes: 2);
        } finally {
            $logDirectory->delete();
        }
    }

    /**
     * Run ffmpeg, streaming its progress to the progress callbacks when there are any.
     *
     * @param  list<string>  $arguments
     */
    protected function run(array $arguments, int $pass = 1, int $passes = 1): void
    {
        if (! $this->reportsProgress()) {
            $this->runner->run(Executable::FFMpeg, $arguments, $this->timeout);

            return;
        }

        $parser = new FFMpegProgressParser($this->expectedDuration());

        $this->runner->run(
            Executable::FFMpeg,
            ['-progress', 'pipe:1', '-nostats', ...$arguments],
            timeout: $this->timeout,
            onOutput: function (string $output) use ($parser, $pass, $passes): void {
                foreach ($parser->feed($output) as $progress) {
                    $this->reportProgress($progress->forPass($pass, $passes));
                }
            },
        );
    }

    /**
     * The seconds of media this run will process, used to turn ffmpeg's position into a percentage.
     */
    public function expectedDuration(): ?float
    {
        if ($this->singleFrame) {
            return null;
        }

        if ($this->reel !== null) {
            return $this->reel->duration();
        }

        try {
            $duration = $this->concat
                ? array_sum(array_map(fn (string $path): float => $this->opener->probe($path)->duration(), $this->opener->paths()))
                : $this->opener->probe()->duration();
        } catch (Throwable) {
            return null;
        }

        if ($this->clipped !== null) {
            $end = $this->clipped['to'] !== null ? min($this->clipped['to'], $duration) : $duration;

            return max(0.0, $end - $this->clipped['from']);
        }

        return $duration > 0 ? $duration : null;
    }

    /**
     * @throws InvalidFormatException
     */
    protected function ensureValidPasses(): void
    {
        if ($this->format?->passes !== 2) {
            return;
        }

        if ($this->outputs !== []) {
            throw InvalidFormatException::twoPassWithOutputs();
        }

        if ($this->format->withoutVideo || ! in_array($this->format->videoCodec, [VideoCodec::H264, VideoCodec::Vp9], true)) {
            throw InvalidFormatException::twoPassUnsupported($this->format->withoutVideo ? null : $this->format->videoCodec);
        }

        if ($this->format->videoBitrate === null) {
            throw InvalidFormatException::twoPassWithoutBitrate();
        }
    }

    protected function seconds(float $seconds): string
    {
        return Number::format($seconds);
    }
}
