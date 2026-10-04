<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Exceptions\SegmentNotFoundException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Exporter;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Filters\Number;
use Foxws\Media\Opener;
use Foxws\Media\Process\Runner;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the opened files as HLS straight from where they're stored, like nginx-vod-module:
 * playlists are built from the keyframe index, and each segment is copied into MPEG-TS the first
 * time it's requested, then kept on a cache disk. Every opened file is one variant.
 */
class DirectStream
{
    /** Video and audio codecs MPEG-TS segments can carry without re-encoding. */
    protected const array STREAMABLE_CODECS = ['h264', 'hevc', 'aac', 'mp3', 'ac3', 'eac3'];

    protected ?float $segmentDuration = null;

    protected ?Disk $cacheDisk = null;

    public function __construct(
        protected Opener $opener,
        protected Runner $runner,
        protected TemporaryDirectories $directories,
        protected Exporter $exporter,
    ) {}

    /**
     * The target segment length in seconds, instead of media.delivery.segment_duration.
     */
    public function segmentDuration(float $seconds): static
    {
        $this->segmentDuration = $seconds;

        return $this;
    }

    /**
     * The disk segments are cached on, instead of media.delivery.cache_disk.
     */
    public function toCache(Disk|Filesystem|string $disk): static
    {
        $this->cacheDisk = Disk::make($disk);

        return $this;
    }

    /**
     * The master playlist, listing every opened file as a variant.
     *
     * @param  callable(int): string  $playlistUrl  Receives the variant's index and returns its media playlist URL.
     */
    public function masterPlaylist(callable $playlistUrl): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-INDEPENDENT-SEGMENTS'];

        foreach ($this->opener->paths() as $variant => $path) {
            $probe = $this->opener->probe($path);
            $video = $probe->videoStream();
            $codecs = HlsCodecs::for($probe);

            $attributes = array_filter([
                'BANDWIDTH' => (string) $this->bandwidth($path),
                'RESOLUTION' => $video?->width !== null && $video->height !== null ? "{$video->width}x{$video->height}" : null,
                'FRAME-RATE' => $video?->frameRate !== null ? number_format($video->frameRate, 3, '.', '') : null,
                'CODECS' => $codecs !== null ? "\"{$codecs}\"" : null,
            ]);

            $lines[] = '#EXT-X-STREAM-INF:'.implode(',', array_map(fn (string $key, string $value): string => "{$key}={$value}", array_keys($attributes), $attributes));
            $lines[] = $playlistUrl($variant);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The media playlist of a variant, with one entry per segment.
     *
     * @param  callable(Segment, int): string  $segmentUrl  Receives the segment and the variant's index and returns its URL.
     *
     * @throws SegmentNotFoundException
     */
    public function mediaPlaylist(int $variant, callable $segmentUrl): string
    {
        $index = $this->index($variant);
        $segments = $index->segments($this->targetDuration());

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:'.(int) ceil($index->longestSegment($this->targetDuration())),
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
        ];

        foreach ($segments as $segment) {
            $lines[] = '#EXTINF:'.number_format($segment->duration, 6, '.', '').',';
            $lines[] = $segmentUrl($segment, $variant);
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines)."\n";
    }

    /**
     * The segments of a variant.
     *
     * @return list<Segment>
     *
     * @throws SegmentNotFoundException
     */
    public function segments(int $variant): array
    {
        return $this->index($variant)->segments($this->targetDuration());
    }

    /**
     * The path of a segment on the cache disk, packaging it first if it isn't cached yet. Packaging
     * runs under a lock, so concurrent requests for the same segment package it once.
     *
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function segment(int $variant, int $index): string
    {
        $media = $this->media($variant);
        $segment = $this->segments($variant)[$index] ?? throw SegmentNotFoundException::for($variant, $index);
        $path = $this->segmentPath($media, $segment);
        $disk = $this->cacheDisk();

        if ($disk->exists($path)) {
            return $path;
        }

        $this->ensureStreamable($media);

        return Cache::lock("media:segment:{$path}", Config::integer('media.delivery.lock_timeout', 120))
            ->block(Config::integer('media.delivery.lock_timeout', 120), function () use ($media, $segment, $path): string {
                // Another request may have packaged it while this one waited for the lock.
                if (! $this->cacheDisk()->exists($path)) {
                    $this->package($media, $segment, $path);
                }

                return $path;
            });
    }

    /**
     * A response for a segment: a redirect to a temporary URL when the cache disk provides them
     * (e.g. S3), otherwise the file itself.
     *
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function segmentResponse(int $variant, int $index): Response
    {
        $path = $this->segment($variant, $index);
        $disk = $this->cacheDisk();
        $lifetime = Config::integer('media.delivery.url_lifetime', 3600);

        if (! $disk->isLocal() && $disk->providesTemporaryUrls()) {
            return new RedirectResponse($disk->temporaryUrl($path, now()->addSeconds($lifetime)));
        }

        return $disk->filesystem()->response($path, basename($path), [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => "public, max-age={$lifetime}, immutable",
        ]);
    }

    public function cacheDisk(): Disk
    {
        return $this->cacheDisk ??= Disk::make(Config::string('media.delivery.cache_disk', 'local'));
    }

    protected function package(Media $media, Segment $segment, string $path): void
    {
        $directory = $this->directories->create();

        try {
            $this->runner->run(Executable::FFMpeg, [
                '-y',
                '-hide_banner',
                '-nostdin',
                '-loglevel', Config::string('media.ffmpeg_log_level', 'error'),
                '-ss', Number::format($segment->start),
                '-t', Number::format($segment->duration),
                '-copyts',
                '-i', $media->inputPath(),
                '-map', '0:v:0?',
                '-map', '0:a:0?',
                '-c', 'copy',
                '-muxdelay', '0',
                '-muxpreload', '0',
                '-f', 'mpegts',
                $directory->path(basename($path)),
            ]);

            $this->exporter->export($directory->path(), $this->cacheDisk(), dirname($path), move: true);
        } finally {
            $directory->delete();
        }
    }

    /**
     * Where a segment is cached: per file version, so a changed file gets new segments.
     */
    protected function segmentPath(Media $media, Segment $segment): string
    {
        $prefix = trim(Config::string('media.delivery.cache_path', 'media-segments'), '/');
        $duration = Number::format($this->targetDuration());

        return ltrim("{$prefix}/{$media->versionKey()}/{$duration}/{$segment->index}.ts", '/');
    }

    /**
     * @throws InvalidMediaException
     */
    protected function ensureStreamable(Media $media): void
    {
        $probe = $this->opener->probe($media->path());

        foreach ([$probe->videoStream(), $probe->audioStream()] as $stream) {
            if ($stream !== null && ! in_array($stream->codecName, self::STREAMABLE_CODECS, true)) {
                throw InvalidMediaException::notStreamable($media->path(), (string) $stream->codecName);
            }
        }
    }

    /**
     * @throws SegmentNotFoundException
     */
    protected function index(int $variant): KeyframeIndex
    {
        return $this->opener->keyframes($this->media($variant)->path());
    }

    /**
     * @throws SegmentNotFoundException
     */
    protected function media(int $variant): Media
    {
        $path = $this->opener->paths()[$variant] ?? throw SegmentNotFoundException::for($variant, 0);

        return $this->opener->mediaFor($path);
    }

    /**
     * The variant's bandwidth in bits per second, from the container's bit rate or its size and duration.
     */
    protected function bandwidth(string $path): int
    {
        $probe = $this->opener->probe($path);

        $bitRate = $probe->format()->bitRate
            ?? ($probe->format()->size !== null && $probe->duration() > 0 ? (int) ($probe->format()->size * 8 / $probe->duration()) : null);

        return (int) ceil(($bitRate ?? 0) * 1.1);
    }

    protected function targetDuration(): float
    {
        return $this->segmentDuration ?? Config::float('media.delivery.segment_duration', 6.0);
    }
}
