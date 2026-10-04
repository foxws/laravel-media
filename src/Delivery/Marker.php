<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\FFMpeg\Scene;
use Foxws\Media\Probe\Chapter;
use InvalidArgumentException;

/**
 * A named time range of a direct stream, like a chapter, a scene or an intro, offered as an
 * #EXT-X-DATERANGE in HLS and an Event in DASH. Without an end, it marks a single moment.
 */
final readonly class Marker
{
    /**
     * The schemeIdUri of the DASH EventStreams.
     */
    public const string SCHEME = 'urn:foxws:media:marker';

    /**
     * @param  string  $class  Groups markers of one kind: the CLASS in HLS and the EventStream's value in DASH.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public float $start,
        public ?float $end = null,
        public ?string $title = null,
        public string $class = 'marker',
    ) {
        if ($start < 0 || ($end !== null && $end < $start)) {
            throw new InvalidArgumentException("A marker can't start before 0 or end before it starts, got {$start} to {$end}.");
        }

        if ($class === '') {
            throw new InvalidArgumentException('A marker needs a class.');
        }
    }

    public static function fromChapter(Chapter $chapter): self
    {
        return new self($chapter->start, $chapter->end, $chapter->title, 'chapter');
    }

    public static function fromScene(Scene $scene): self
    {
        return new self($scene->start, $scene->end, class: 'scene');
    }

    public function duration(): ?float
    {
        return $this->end !== null ? $this->end - $this->start : null;
    }
}
