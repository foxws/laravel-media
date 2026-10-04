<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Closure;
use Foxws\Media\Concerns\HasContext;
use Foxws\Media\Concerns\HasSaveCallbacks;
use Foxws\Media\Concerns\ReportsProgress;
use Foxws\Media\Encoding\Format;
use Foxws\Media\Encoding\VideoCodec;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filters\Custom;
use Foxws\Media\Filters\Number;
use Foxws\Media\Filters\Scale;
use Foxws\Media\Filters\Tonemap;
use Foxws\Media\Opener;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * Samples a video into sprite sheets of thumbnails with a WebVTT file that
 * points at each thumbnail, as used by video players for seek previews.
 */
class Thumbnails
{
    use HasContext;
    use HasSaveCallbacks;
    use ReportsProgress;

    protected ?float $every = null;

    protected int $count = 100;

    protected float $minimumInterval = 1.0;

    protected int $width = 160;

    protected int $height = 90;

    protected int $columns = 10;

    protected int $rows = 10;

    protected string $extension = 'jpg';

    protected ?int $quality = null;

    protected ?Disk $targetDisk = null;

    protected ?string $visibility = null;

    protected ?int $timeout = null;

    protected ?Closure $urlResolver = null;

    protected ?Tonemap $toneMap;

    public function __construct(protected Opener $opener)
    {
        $this->toneMap = new Tonemap;
    }

    /**
     * HDR videos are tone mapped to SDR by default, so thumbnails aren't washed out. Only the
     * sampled frames are tone mapped, which is much cheaper than mapping the whole video.
     * Pass another tone map to change the algorithm, or null to keep the source colours.
     */
    public function toneMap(?Tonemap $toneMap): static
    {
        $this->toneMap = $toneMap;

        return $this;
    }

    /**
     * Take a thumbnail every given number of seconds.
     */
    public function every(float $seconds): static
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('The thumbnail interval must be greater than zero.');
        }

        $this->every = $seconds;

        return $this;
    }

    /**
     * Spread about this many thumbnails over the video (the default is 100), never closer
     * together than the minimum interval in seconds, so short videos don't repeat frames.
     */
    public function count(int $amount, float $minimumInterval = 1.0): static
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('The thumbnail count must be at least one.');
        }

        $this->every = null;
        $this->count = $amount;
        $this->minimumInterval = $minimumInterval;

        return $this;
    }

    /**
     * The size of each thumbnail. Videos are letterboxed to keep their aspect ratio.
     */
    public function size(int $width, int $height): static
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * How many thumbnails each sprite sheet holds. More thumbnails continue on another sheet.
     */
    public function grid(int $columns, int $rows): static
    {
        $this->columns = max(1, $columns);
        $this->rows = max(1, $rows);

        return $this;
    }

    /**
     * Write JPEG (quality 2-31, lower is better) or WebP (quality 0-100, higher is better) sheets.
     */
    public function format(string $extension, ?int $quality = null): static
    {
        if (! in_array($extension, ['jpg', 'webp'], true)) {
            throw new InvalidArgumentException("Thumbnail sheets can be jpg or webp, not [{$extension}].");
        }

        $this->extension = $extension;
        $this->quality = $quality;

        return $this;
    }

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
     */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Resolve the image URL written in each cue, from a sprite sheet's path on the target disk.
     * By default cues use the sheet's file name, relative to the WebVTT file.
     *
     * @param  callable(string): string  $resolver
     */
    public function withUrl(callable $resolver): static
    {
        $this->urlResolver = $resolver(...);

        return $this;
    }

    /**
     * The seconds between thumbnails for the given duration.
     */
    public function interval(float $duration): float
    {
        return $this->every ?? max($this->minimumInterval, $duration / $this->count);
    }

    /**
     * Sample the video and save the sheets as "{$name}_001.jpg" etc. with "{$name}.vtt" next to them.
     *
     * @throws InvalidMediaException
     */
    public function save(string $name): ThumbnailsResult
    {
        $source = $this->opener->paths()[0] ?? '';
        $probe = $this->opener->probe();
        $duration = $probe->duration();

        if (! $probe->hasVideo()) {
            throw InvalidMediaException::noVideo($source);
        }

        if ($duration <= 0) {
            throw InvalidMediaException::unknownDuration($source);
        }

        $this->runBeforeSavingCallbacks();

        $interval = $this->interval($duration);
        $count = (int) ceil($duration / $interval);
        $sheets = (int) ceil($count / ($this->columns * $this->rows));

        $exported = $this->opener->ffmpeg()
            ->map('0:v:0')
            ->addFilter(...array_values(array_filter([
                Custom::video('fps=1/'.Number::format($interval)),
                $probe->videoStream()?->isHdr() === true ? $this->toneMap : null,
                Scale::fit($this->width, $this->height),
                Custom::video("tile={$this->columns}x{$this->rows}"),
            ])))
            ->inFormat($this->sheetFormat($sheets))
            ->toDisk($this->disk())
            ->when($this->visibility !== null, fn (FFMpegBuilder $builder) => $builder->withVisibility((string) $this->visibility))
            ->withContext($this->context)
            ->when($this->timeout !== null, fn (FFMpegBuilder $builder) => $builder->timeout((int) $this->timeout))
            ->when($this->reportsProgress(), fn (FFMpegBuilder $builder) => $builder->onProgress($this->reportProgress(...)))
            ->save("{$name}_%03d.{$this->extension}");

        $sprites = array_map(fn (int $sheet): string => $this->sheetPath($name, $sheet), range(1, $sheets));
        $sprites = array_values(array_intersect($sprites, $exported->paths()));

        $vtt = "{$name}.vtt";

        $this->disk()->put($vtt, $this->webVtt($name, $duration, $interval, $count), $this->visibility !== null ? ['visibility' => $this->visibility] : []);

        $result = new ThumbnailsResult($this->disk(), $sprites, $vtt, $interval, $count, $this->columns, $this->rows, $this->width, $this->height);

        $this->runAfterSavingCallbacks($result);

        return $result;
    }

    /**
     * The WebVTT cues, each pointing at a tile of a sprite sheet with a media fragment.
     */
    public function webVtt(string $name, float $duration, float $interval, int $count): string
    {
        $perSheet = $this->columns * $this->rows;
        $cues = ['WEBVTT'];

        for ($index = 0; $index < $count; $index++) {
            $tile = $index % $perSheet;
            $sheet = $this->sheetPath($name, intdiv($index, $perSheet) + 1);

            $cues[] = sprintf(
                "%s --> %s\n%s#xywh=%d,%d,%d,%d",
                $this->timestamp($index * $interval),
                $this->timestamp(min(($index + 1) * $interval, $duration)),
                $this->urlResolver !== null ? ($this->urlResolver)($sheet) : basename($sheet),
                ($tile % $this->columns) * $this->width,
                intdiv($tile, $this->columns) * $this->height,
                $this->width,
                $this->height,
            );
        }

        return implode("\n\n", $cues)."\n";
    }

    protected function sheetFormat(int $sheets): Format
    {
        $arguments = $this->extension === 'webp'
            ? ['-c:v', VideoCodec::WebP->value, '-quality', (string) ($this->quality ?? 80)]
            : ['-q:v', (string) ($this->quality ?? 4)];

        return new Format('image2', withoutAudio: true, withoutSubtitles: true, arguments: [...$arguments, '-frames:v', (string) $sheets]);
    }

    protected function sheetPath(string $name, int $sheet): string
    {
        return sprintf('%s_%03d.%s', $name, $sheet, $this->extension);
    }

    protected function disk(): Disk
    {
        return $this->targetDisk ?? $this->opener->disk();
    }

    protected function timestamp(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);

        return sprintf(
            '%02d:%02d:%02d.%03d',
            intdiv($milliseconds, 3_600_000),
            intdiv($milliseconds % 3_600_000, 60_000),
            intdiv($milliseconds % 60_000, 1000),
            $milliseconds % 1000,
        );
    }
}
