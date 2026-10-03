<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Foxws\Media\Filters\Filter;
use Foxws\Media\Filters\FilterType;
use Foxws\Media\Filters\Fps;
use Foxws\Media\Filters\Number;
use Foxws\Media\Filters\Scale;
use Foxws\Media\Opener;

/**
 * Builds the inputs and filter graph that join clips into one video.
 *
 * Each clip is its own input, seeked with -ss and -t, which is fast and
 * frame-accurate when transcoding. Clips from different files are scaled
 * to one size, because the concat filter needs matching dimensions.
 *
 * @internal
 */
final readonly class Reel
{
    /**
     * @param  list<Clip>  $clips
     */
    public function __construct(
        protected Opener $opener,
        protected array $clips,
        protected ?int $width = null,
        protected ?int $height = null,
        protected ?float $fps = null,
    ) {}

    /**
     * @return list<string>
     */
    public function inputs(): array
    {
        return array_merge(...array_map(fn (Clip $clip): array => [
            '-ss', Number::format($clip->from),
            '-t', Number::format($clip->duration()),
            '-i', $this->opener->mediaFor($clip->path)->inputPath(),
        ], $this->clips));
    }

    /**
     * The filter graph and maps, with the given filters applied to the joined result.
     *
     * @param  list<Filter>  $filters
     * @return list<string>
     */
    public function arguments(array $filters): array
    {
        $withAudio = $this->hasAudio();
        $normalize = $this->normalizeFilter();

        $graph = [];
        $joined = '';

        foreach ($this->clips as $index => $clip) {
            $graph[] = "[{$index}:v:0]setpts=PTS-STARTPTS{$normalize}[v{$index}]";

            if ($withAudio) {
                $graph[] = "[{$index}:a:0]asetpts=PTS-STARTPTS[a{$index}]";
            }

            $joined .= "[v{$index}]".($withAudio ? "[a{$index}]" : '');
        }

        $video = FilterChain::of($filters, FilterType::Video);
        $audio = FilterChain::of($filters, FilterType::Audio);

        $graph[] = sprintf(
            '%sconcat=n=%d:v=1:a=%d%s%s',
            $joined,
            count($this->clips),
            $withAudio ? 1 : 0,
            $video !== '' ? '[joined]' : '[v]',
            $withAudio ? ($audio !== '' ? '[joineda]' : '[a]') : '',
        );

        if ($video !== '') {
            $graph[] = "[joined]{$video}[v]";
        }

        if ($withAudio && $audio !== '') {
            $graph[] = "[joineda]{$audio}[a]";
        }

        return [
            '-filter_complex', implode(';', $graph),
            '-map', '[v]',
            ...($withAudio ? ['-map', '[a]'] : ['-an']),
        ];
    }

    /**
     * Audio is joined only when every clip's file has audio; otherwise the reel is silent.
     */
    public function hasAudio(): bool
    {
        foreach ($this->paths() as $path) {
            if (! $this->opener->probe($path)->hasAudio()) {
                return false;
            }
        }

        return true;
    }

    protected function normalizeFilter(): string
    {
        $width = $this->width;
        $height = $this->height;

        if (($width === null || $height === null) && count($this->paths()) > 1) {
            $video = $this->opener->probe($this->paths()[0])->videoStream();

            $width ??= $video?->width !== null ? $video->width - ($video->width % 2) : null;
            $height ??= $video?->height !== null ? $video->height - ($video->height % 2) : null;
        }

        return implode('', [
            $width !== null && $height !== null ? ','.Scale::fit($width, $height) : '',
            $this->fps !== null ? ','.new Fps($this->fps) : '',
        ]);
    }

    /**
     * @return list<string>
     */
    protected function paths(): array
    {
        return array_values(array_unique(array_map(
            fn (Clip $clip): string => $this->opener->mediaFor($clip->path)->path(),
            $this->clips,
        )));
    }
}
