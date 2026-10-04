<?php

declare(strict_types=1);

namespace Foxws\Media;

use Foxws\Media\Delivery\DirectStream;
use Foxws\Media\Delivery\KeyframeIndex;
use Foxws\Media\Delivery\KeyframeIndexer;
use Foxws\Media\Exceptions\MediaNotFoundException;
use Foxws\Media\FFMpeg\FFMpegBuilder;
use Foxws\Media\FFMpeg\Scene;
use Foxws\Media\FFMpeg\SceneDetector;
use Foxws\Media\FFMpeg\Thumbnails;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Http\DynamicDASHManifest;
use Foxws\Media\Http\DynamicHLSPlaylist;
use Foxws\Media\Packaging\PackagingBuilder;
use Foxws\Media\Probe\Probe;
use Foxws\Media\Probe\Prober;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Traits\Macroable;

/**
 * One or more opened media files on a disk. Packages add their own tools with macros, e.g.
 * Opener::macro('abAv1', fn () => new AbAv1Builder($this)).
 */
class Opener
{
    use Macroable;

    /** @var array<string, Media> */
    protected array $media = [];

    /** @var array<string, Probe> */
    protected array $probes = [];

    /** @var array<string, list<Scene>> */
    protected array $scenes = [];

    /** @var array<string, KeyframeIndex> */
    protected array $keyframes = [];

    protected bool $rememberProbes = false;

    public function __construct(
        protected Disk $disk,
        protected TemporaryDirectories $directories,
        protected Prober $prober,
    ) {}

    public function fromDisk(Disk|Filesystem|string $disk): static
    {
        $this->disk = Disk::make($disk);

        return $this;
    }

    /**
     * @param  string|list<string>  ...$paths
     */
    public function open(string|array ...$paths): static
    {
        foreach (array_merge(...array_map(fn (string|array $path): array => (array) $path, $paths)) as $path) {
            $this->media[$path] = $this->makeMedia($this->disk, $path);
        }

        return $this;
    }

    /**
     * A file on any disk, set up like the opened files, e.g. a watermark image or a subtitle file.
     */
    public function makeMedia(Disk $disk, string $path): Media
    {
        return new Media($disk, $path, $this->directories);
    }

    /**
     * The disk media is opened from.
     */
    public function disk(): Disk
    {
        return $this->disk;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->media);
    }

    /**
     * @return list<Media>
     *
     * @throws MediaNotFoundException
     */
    public function media(): array
    {
        return $this->media !== [] ? array_values($this->media) : throw MediaNotFoundException::noPaths();
    }

    /**
     * An opened file (the first one by default).
     *
     * @throws MediaNotFoundException
     */
    public function mediaFor(?string $path = null): Media
    {
        return $path !== null
            ? ($this->media[$path] ?? throw MediaNotFoundException::unreadable($path))
            : $this->media()[0];
    }

    /**
     * Probe an opened file (the first one by default). Results are cached on this opener, and in
     * the media.delivery.cache_store per file version after rememberProbes().
     *
     * @throws MediaNotFoundException
     */
    public function probe(?string $path = null): Probe
    {
        $media = $this->mediaFor($path);

        return $this->probes[$media->path()] ??= $this->rememberProbes ? $this->rememberedProbe($media) : $this->prober->probe($media);
    }

    /**
     * Keep probes in the media.delivery.cache_store per file version, like keyframe indexes, so
     * later requests for the same files don't run ffprobe again. stream() turns this on.
     */
    public function rememberProbes(bool $remember = true): static
    {
        $this->rememberProbes = $remember;

        return $this;
    }

    /**
     * Probe every opened file, keyed by path.
     *
     * @return array<string, Probe>
     */
    public function probeAll(): array
    {
        $probes = [];

        foreach ($this->paths() as $path) {
            $probes[$path] = $this->probe($path);
        }

        return $probes;
    }

    /**
     * Build an ffmpeg command with the opened files as inputs.
     */
    public function ffmpeg(): FFMpegBuilder
    {
        return app(FFMpegBuilder::class, ['opener' => $this]);
    }

    /**
     * The keyframes of an opened file (the first one by default), cached on this opener and in
     * the media.delivery.cache_store per file version, to split it into copyable segments.
     */
    public function keyframes(?string $path = null): KeyframeIndex
    {
        $media = $this->mediaFor($path);
        $probe = $this->probe($media->path());

        return $this->keyframes[$media->path()] ??= KeyframeIndexer::make()->index($media, $probe->duration(), $probe->hasVideo());
    }

    /**
     * Stream the opened files as HLS straight from where they're stored, packaging each segment
     * when it's first requested. Every opened file is one variant, e.g. renditions of one video.
     */
    public function stream(): DirectStream
    {
        return app(DirectStream::class, ['opener' => $this->rememberProbes()]);
    }

    protected function rememberedProbe(Media $media): Probe
    {
        /** @var array<string, mixed> $probe */
        $probe = Cache::store(Config::get('media.delivery.cache_store'))->remember(
            'media:probe:'.$media->versionKey(),
            Config::integer('media.delivery.index_lifetime', 604800),
            fn (): array => $this->prober->probe($media)->raw,
        );

        return Probe::fromArray($probe);
    }

    /**
     * Find the scenes of an opened video (the first one by default), split where the
     * picture changes by at least the threshold (0-1). Results are cached on this opener.
     *
     * @return list<Scene>
     */
    public function scenes(float $threshold = 0.3, ?string $path = null): array
    {
        $media = $this->mediaFor($path);

        return $this->scenes[$media->path().'@'.$threshold] ??= SceneDetector::make()->detect(
            $media,
            $this->probe($media->path())->duration(),
            $threshold,
        );
    }

    /**
     * Package the opened, already-encoded files into DASH and HLS.
     */
    public function package(): PackagingBuilder
    {
        return app(PackagingBuilder::class, ['opener' => $this]);
    }

    /**
     * Package the video and audio of every opened file into an HLS playlist (master.m3u8).
     */
    public function exportAsHLS(string $playlist = 'master.m3u8'): PackagingBuilder
    {
        return $this->package()->addStreamsFrom()->withHlsPlaylist($playlist)->forVod();
    }

    /**
     * Package the video and audio of every opened file into a DASH manifest (manifest.mpd).
     */
    public function exportAsDASH(string $manifest = 'manifest.mpd'): PackagingBuilder
    {
        return $this->package()->addStreamsFrom()->withDashManifest($manifest)->forVod();
    }

    /**
     * Package the video and audio of every opened file into both HLS and DASH, from the same segments.
     */
    public function exportAsStreams(string $playlist = 'master.m3u8', string $manifest = 'manifest.mpd'): PackagingBuilder
    {
        return $this->package()->addStreamsFrom()->withHlsPlaylist($playlist)->withDashManifest($manifest)->forVod();
    }

    /**
     * Rewrite the opened HLS playlist (the first opened path) per request, e.g. to sign its URIs.
     */
    public function hlsPlaylist(): DynamicHLSPlaylist
    {
        return new DynamicHLSPlaylist($this->disk)->open($this->mediaFor()->path());
    }

    /**
     * Rewrite the opened DASH manifest (the first opened path) per request, e.g. to sign its URIs.
     */
    public function dashManifest(): DynamicDASHManifest
    {
        return new DynamicDASHManifest($this->disk)->open($this->mediaFor()->path());
    }

    /**
     * Sample the first opened video into thumbnail sprite sheets with a WebVTT file.
     */
    public function thumbnails(): Thumbnails
    {
        return new Thumbnails($this);
    }

    /**
     * Delete local copies made of files on remote disks.
     */
    public function cleanupTemporaryFiles(): void
    {
        foreach ($this->media as $media) {
            $media->cleanup();
        }
    }
}
