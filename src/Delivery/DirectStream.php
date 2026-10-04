<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Closure;
use Foxws\Media\Encryption\EncryptionKey;
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
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the opened files as HLS straight from where they're stored, like nginx-vod-module:
 * playlists are built from the keyframe index, and each segment is copied into MPEG-TS the first
 * time it's requested, then kept on a cache disk. Every opened file is one variant.
 */
class DirectStream
{
    protected ?float $segmentDuration = null;

    protected ?Disk $cacheDisk = null;

    /** @var (Closure(int): EncryptionKey)|null */
    protected ?Closure $keys = null;

    /** @var (Closure(int, int): string)|null */
    protected ?Closure $keyUrl = null;

    protected ?int $rotateEvery = null;

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
     * Encrypt segments with AES-128 for each request. The cached segments stay unencrypted, so they
     * can be served with any key. Players fetch the key from the key URL; serve it with keyResponse().
     * Route::mediaStream() sets the key URL itself.
     *
     * @param  EncryptionKey|callable(int): EncryptionKey  $key  A key, or a resolver that receives the rotation period,
     *                                                           e.g. fn (int $period) => EncryptionKey::derive($secret, "video:1:{$period}").
     * @param  (callable(int, int): string)|null  $keyUrl  Receives the rotation period and the variant, and returns the key's URL.
     * @param  int|null  $rotateEvery  Use a new key every this many segments; null for one key per playlist.
     */
    public function withEncryption(EncryptionKey|callable $key, ?callable $keyUrl = null, ?int $rotateEvery = null): static
    {
        if ($rotateEvery !== null && $rotateEvery < 1) {
            throw new InvalidArgumentException('Keys must rotate after at least one segment.');
        }

        $this->keys = $key instanceof EncryptionKey ? fn (): EncryptionKey => $key : $key(...);
        $this->keyUrl = $keyUrl !== null ? $keyUrl(...) : null;
        $this->rotateEvery = $rotateEvery;

        return $this;
    }

    /**
     * Where players fetch the keys of an encrypted stream.
     *
     * @param  callable(int, int): string  $keyUrl  Receives the rotation period and the variant, and returns the key's URL.
     */
    public function keyUrlsUsing(callable $keyUrl): static
    {
        $this->keyUrl = $keyUrl(...);

        return $this;
    }

    public function isEncrypted(): bool
    {
        return $this->keys !== null;
    }

    /**
     * The key of a rotation period.
     *
     * @throws InvalidArgumentException
     */
    public function key(int $period = 0): EncryptionKey
    {
        if ($this->keys === null) {
            throw new InvalidArgumentException('This stream is not encrypted. Call withEncryption() first.');
        }

        return ($this->keys)($period);
    }

    /**
     * The raw key of a rotation period, as HLS players fetch it from the key URL. Authorize the request first.
     */
    public function keyResponse(int $period = 0): Response
    {
        return new Response($this->key($period)->binary(), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
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
     * @throws InvalidArgumentException
     */
    public function mediaPlaylist(int $variant, callable $segmentUrl): string
    {
        if ($this->keys !== null && $this->keyUrl === null) {
            throw new InvalidArgumentException('Encrypted streams need a key URL. Pass one to withEncryption() or keyUrlsUsing().');
        }

        $index = $this->index($variant);
        $segments = $index->segments($this->targetDuration());

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:'.(int) ceil($index->longestSegment($this->targetDuration())),
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
        ];

        $period = null;

        foreach ($segments as $segment) {
            if ($this->keys !== null && $this->keyUrl !== null && $period !== $this->period($segment->index)) {
                $period = $this->period($segment->index);

                $lines[] = '#EXT-X-KEY:METHOD=AES-128,URI="'.($this->keyUrl)($period, $variant).'"';
            }

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
     * (e.g. S3), otherwise the file itself. Encrypted streams always respond with the segment,
     * encrypted with the key of its rotation period.
     *
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function segmentResponse(int $variant, int $index): Response
    {
        $path = $this->segment($variant, $index);
        $disk = $this->cacheDisk();
        $lifetime = Config::integer('media.delivery.url_lifetime', 3600);

        if ($this->keys !== null) {
            return new Response($this->encrypt((string) $disk->get($path), $index), 200, [
                'Content-Type' => 'video/mp2t',
                'Cache-Control' => "private, max-age={$lifetime}",
            ]);
        }

        if (! $disk->isLocal() && $disk->providesTemporaryUrls()) {
            return new RedirectResponse($disk->temporaryUrl($path, now()->addSeconds($lifetime)));
        }

        return $disk->filesystem()->response($path, basename($path), [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => "public, max-age={$lifetime}, immutable",
        ]);
    }

    /**
     * AES-128-CBC with PKCS#7 padding, as HLS defines it. Playlists leave out the IV, so players use the
     * segment's media sequence number as a 16-byte big-endian IV, and the sequence starts at zero.
     */
    protected function encrypt(string $segment, int $index): string
    {
        $iv = str_pad(pack('J', $index), 16, "\0", STR_PAD_LEFT);

        return openssl_encrypt($segment, 'aes-128-cbc', $this->key($this->period($index))->binary(), OPENSSL_RAW_DATA, $iv)
            ?: throw new InvalidArgumentException('The segment could not be encrypted.');
    }

    protected function period(int $index): int
    {
        return $this->rotateEvery !== null ? intdiv($index, $this->rotateEvery) : 0;
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
            if ($stream !== null && ! TransportStreamCodec::supports($stream->codecName)) {
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
