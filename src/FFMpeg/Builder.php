<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Foxws\Media\Concerns\HasSaveCallbacks;
use Foxws\Media\Encoding\Format;
use Foxws\Media\Encoding\VideoCodec;
use Foxws\Media\Exceptions\InvalidFilterException;
use Foxws\Media\Exceptions\InvalidFormatException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Exporter;
use Foxws\Media\Filesystem\ExportResult;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Filters\Filter;
use Foxws\Media\Filters\FilterType;
use Foxws\Media\Filters\Number;
use Foxws\Media\Filters\Position;
use Foxws\Media\Opener;
use Foxws\Media\Process\Runner;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Traits\Conditionable;

/**
 * Builds and runs an ffmpeg command with the opened media as inputs.
 */
class Builder
{
    use Conditionable;
    use HasSaveCallbacks;

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

    protected ?Disk $targetDisk = null;

    protected ?string $visibility = null;

    public function __construct(
        protected Opener $opener,
        protected Runner $runner,
        protected TemporaryDirectories $directories,
        protected Exporter $exporter,
    ) {}

    /**
     * Only process the part between the given seconds. Seeks on the input, so
     * with stream copy the clip starts at the nearest keyframe before $from.
     */
    public function clip(float $from, ?float $to = null): static
    {
        $this->inputArguments = [...$this->inputArguments, '-ss', $this->seconds($from)];

        if ($to !== null) {
            $this->arguments = [...$this->arguments, '-t', $this->seconds(max(0.0, $to - $from))];
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
     * Overlay an image on the video, e.g. a logo. The image is read from the given disk,
     * or the disk the media was opened from, and scaled to $width pixels wide if given.
     */
    public function watermark(string $path, Disk|Filesystem|string|null $disk = null, Position $position = Position::BottomRight, int $margin = 16, ?int $width = null): static
    {
        $this->watermark = new Media($disk !== null ? Disk::make($disk) : $this->opener->disk(), $path, $this->directories);
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
    public function arguments(string $output, array $passArguments = []): array
    {
        $inputs = array_merge(...array_map(
            fn (Media $media): array => [...$this->inputArguments, '-i', $media->inputPath()],
            $this->opener->media(),
        ));

        return [
            '-y',
            '-hide_banner',
            '-nostdin',
            '-loglevel', 'error',
            ...$inputs,
            ...($this->watermark !== null ? ['-i', $this->watermark->inputPath()] : []),
            ...$this->filterArguments(),
            ...($this->format?->toArguments() ?? []),
            ...$this->arguments,
            ...$passArguments,
            $output,
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
        $video = $this->chain(FilterType::Video);
        $audio = $this->chain(FilterType::Audio);

        if ($this->watermark === null) {
            return [
                ...$this->maps,
                ...($video !== '' ? ['-vf', $video] : []),
                ...($audio !== '' ? ['-af', $audio] : []),
            ];
        }

        if ($this->maps !== []) {
            throw InvalidFilterException::watermarkWithMaps();
        }

        $watermarkInput = count($this->opener->media());

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

    protected function chain(FilterType $type): string
    {
        return implode(',', array_map(
            strval(...),
            array_filter($this->filters, fn (Filter $filter): bool => $filter->type() === $type),
        ));
    }

    /**
     * The full command line for writing to the given output path, with sensitive values redacted.
     * For a two-pass format, this is the command of the final pass without its pass options.
     */
    public function command(string $output): string
    {
        return $this->runner->commandLine(Executable::FFMpeg, $this->arguments($output));
    }

    /**
     * Run ffmpeg and save the output to the target disk.
     *
     * @throws InvalidFormatException
     */
    public function save(string $path): ExportResult
    {
        $this->ensureValidPasses();

        $this->runBeforeSavingCallbacks();

        $directory = $this->directories->create();

        try {
            $this->encode($directory->path(basename($path)));

            $target = $this->disk();

            $paths = $this->exporter->export($directory->path(), $target, dirname($path) === '.' ? '' : dirname($path), $this->visibility, move: true);
        } finally {
            $directory->delete();
            $this->watermark?->cleanup();
        }

        $result = new ExportResult($target, $paths);

        $this->runAfterSavingCallbacks($result);

        return $result;
    }

    /**
     * Run ffmpeg once, or twice for a two-pass format: the first pass only analyses
     * the video into a log file, kept outside the output directory.
     */
    protected function encode(string $output): void
    {
        if ($this->format?->passes !== 2) {
            $this->runner->run(Executable::FFMpeg, $this->arguments($output));

            return;
        }

        $logDirectory = $this->directories->create();
        $log = $logDirectory->path('ffmpeg2pass');

        try {
            $this->runner->run(Executable::FFMpeg, $this->arguments(
                PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null',
                ['-pass', '1', '-passlogfile', $log, '-an', '-f', 'null'],
            ));

            $this->runner->run(Executable::FFMpeg, $this->arguments($output, ['-pass', '2', '-passlogfile', $log]));
        } finally {
            $logDirectory->delete();
        }
    }

    /**
     * @throws InvalidFormatException
     */
    protected function ensureValidPasses(): void
    {
        if ($this->format?->passes !== 2) {
            return;
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
