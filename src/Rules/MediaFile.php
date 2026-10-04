<?php

declare(strict_types=1);

namespace Foxws\Media\Rules;

use Closure;
use Foxws\Media\Exceptions\ProcessFailedException;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Probe\Probe;
use Foxws\Media\Probe\Prober;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use SplFileInfo;

/**
 * Validates an uploaded file by probing it with ffprobe, so checks rely on what the file
 * really contains rather than its extension or MIME type.
 */
final class MediaFile implements ValidationRule
{
    protected bool $requiresVideo = false;

    protected bool $requiresAudio = false;

    protected ?float $minDuration = null;

    protected ?float $maxDuration = null;

    protected ?int $minWidth = null;

    protected ?int $maxWidth = null;

    protected ?int $minHeight = null;

    protected ?int $maxHeight = null;

    /** @var list<string> */
    protected array $videoCodecs = [];

    /** @var list<string> */
    protected array $audioCodecs = [];

    /**
     * Any file ffprobe can read as media.
     */
    public static function any(): self
    {
        return new self;
    }

    /**
     * A file with a video stream (cover art doesn't count).
     */
    public static function video(): self
    {
        $rule = new self;
        $rule->requiresVideo = true;

        return $rule;
    }

    /**
     * A file with an audio stream.
     */
    public static function audio(): self
    {
        $rule = new self;
        $rule->requiresAudio = true;

        return $rule;
    }

    public function withAudio(): static
    {
        $this->requiresAudio = true;

        return $this;
    }

    public function minDuration(float $seconds): static
    {
        $this->minDuration = $seconds;

        return $this;
    }

    public function maxDuration(float $seconds): static
    {
        $this->maxDuration = $seconds;

        return $this;
    }

    public function minDimensions(int $width, int $height): static
    {
        $this->minWidth = $width;
        $this->minHeight = $height;

        return $this;
    }

    public function maxDimensions(int $width, int $height): static
    {
        $this->maxWidth = $width;
        $this->maxHeight = $height;

        return $this;
    }

    /**
     * Allowed video codecs, as ffprobe names them, e.g. ['h264', 'hevc', 'av1', 'vp9'].
     *
     * @param  list<string>  $codecs
     */
    public function videoCodecs(array $codecs): static
    {
        $this->videoCodecs = $codecs;

        return $this;
    }

    /**
     * Allowed audio codecs, as ffprobe names them, e.g. ['aac', 'opus', 'mp3'].
     *
     * @param  list<string>  $codecs
     */
    public function audioCodecs(array $codecs): static
    {
        $this->audioCodecs = $codecs;

        return $this;
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $probe = $value instanceof SplFileInfo ? $this->probe($value) : null;

        if ($probe === null) {
            $fail('The :attribute must be a readable media file.');

            return;
        }

        foreach ($this->failures($probe) as $message) {
            $fail($message);
        }
    }

    /**
     * @return list<string>
     */
    protected function failures(Probe $probe): array
    {
        $video = $probe->videoStream();
        $audio = $probe->audioStream();
        $duration = $probe->duration();

        return array_values(array_filter([
            $this->requiresVideo && $video === null ? 'The :attribute must be a video.' : null,
            $this->requiresAudio && $audio === null ? 'The :attribute must contain audio.' : null,
            $this->minDuration !== null && $duration < $this->minDuration ? "The :attribute must be at least {$this->seconds($this->minDuration)} long." : null,
            $this->maxDuration !== null && $duration > $this->maxDuration ? "The :attribute may not be longer than {$this->seconds($this->maxDuration)}." : null,
            $video !== null && $this->tooSmall($video->width, $video->height) ? "The :attribute must be at least {$this->minWidth}×{$this->minHeight} pixels." : null,
            $video !== null && $this->tooLarge($video->width, $video->height) ? "The :attribute may not be larger than {$this->maxWidth}×{$this->maxHeight} pixels." : null,
            $video !== null && $this->videoCodecs !== [] && ! in_array($video->codecName, $this->videoCodecs, true) ? 'The :attribute must use one of these video codecs: '.implode(', ', $this->videoCodecs).'.' : null,
            $audio !== null && $this->audioCodecs !== [] && ! in_array($audio->codecName, $this->audioCodecs, true) ? 'The :attribute must use one of these audio codecs: '.implode(', ', $this->audioCodecs).'.' : null,
        ]));
    }

    protected function probe(SplFileInfo $file): ?Probe
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            return null;
        }

        $media = new Media(Disk::local(dirname($path)), basename($path), app(TemporaryDirectories::class));

        try {
            return Prober::make()->probe($media);
        } catch (ProcessFailedException) {
            return null;
        }
    }

    protected function tooSmall(?int $width, ?int $height): bool
    {
        return ($this->minWidth !== null && ($width ?? 0) < $this->minWidth)
            || ($this->minHeight !== null && ($height ?? 0) < $this->minHeight);
    }

    protected function tooLarge(?int $width, ?int $height): bool
    {
        return ($this->maxWidth !== null && ($width ?? 0) > $this->maxWidth)
            || ($this->maxHeight !== null && ($height ?? 0) > $this->maxHeight);
    }

    protected function seconds(float $seconds): string
    {
        return $seconds >= 60 && fmod($seconds, 60) === 0.0
            ? sprintf('%d %s', $seconds / 60, $seconds === 60.0 ? 'minute' : 'minutes')
            : rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.').' seconds';
    }
}
