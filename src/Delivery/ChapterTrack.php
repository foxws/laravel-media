<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

/**
 * The chapters of a direct stream as a WebVTT track, for players that show chapters on their seek
 * bar, like Shaka Player's addChaptersTrack(). Each cue ends where the next one starts, and the gaps
 * between them, and after the last one, can get a cue of their own, so players don't show the title
 * of the last chapter until the end of the video.
 */
final readonly class ChapterTrack
{
    /**
     * @param  list<Marker>  $markers
     * @param  float  $duration  The duration of the stream; the last chapter without an end ends there.
     * @param  string|null  $gapTitle  The title of the cues that fill the gaps; null leaves them empty.
     */
    public function __construct(
        public array $markers,
        public float $duration,
        public ?string $gapTitle = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->markers === [];
    }

    public function toWebVtt(): string
    {
        $markers = $this->markers;
        usort($markers, fn (Marker $a, Marker $b): int => $a->start <=> $b->start);

        $cues = ['WEBVTT'];
        $position = 0.0;
        $gaps = 0;

        foreach ($markers as $index => $marker) {
            $bound = isset($markers[$index + 1]) ? $markers[$index + 1]->start : ($this->duration > 0 ? $this->duration : null);
            $end = $marker->end !== null && $bound !== null ? min($marker->end, $bound) : $marker->end ?? $bound;

            if ($end === null || $end <= $marker->start) {
                continue;
            }

            if ($this->gapTitle !== null && $marker->start > $position) {
                $cues[] = $this->cue('gap-'.$gaps++, $position, $marker->start, $this->gapTitle);
            }

            $cues[] = $this->cue("{$marker->class}-{$index}", $marker->start, $end, $marker->title);
            $position = max($position, $end);
        }

        if ($this->gapTitle !== null && $position > 0 && $this->duration > $position) {
            $cues[] = $this->cue('gap-'.$gaps, $position, $this->duration, $this->gapTitle);
        }

        return implode("\n\n", $cues)."\n";
    }

    protected function cue(string $id, float $start, float $end, ?string $title): string
    {
        $text = trim((string) preg_replace('/\s*\R\s*/', ' ', str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string) $title)));

        return implode("\n", array_filter([$id, $this->timestamp($start).' --> '.$this->timestamp($end), $text], fn (string $line): bool => $line !== ''));
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
