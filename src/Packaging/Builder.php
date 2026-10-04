<?php

declare(strict_types=1);

namespace Foxws\Media\Packaging;

use Foxws\Media\Concerns\HasContext;
use Foxws\Media\Concerns\HasSaveCallbacks;
use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Encryption\ProtectionScheme;
use Foxws\Media\Events\ExportCompleted;
use Foxws\Media\Events\ExportFailed;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Filesystem\Disk;
use Foxws\Media\Filesystem\Exporter;
use Foxws\Media\Filesystem\ExportResult;
use Foxws\Media\Filesystem\Media;
use Foxws\Media\Filesystem\TemporaryDirectories;
use Foxws\Media\Opener;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Traits\Conditionable;
use Throwable;

/**
 * Packages already-encoded media into DASH and HLS. It doesn't transcode: encode
 * renditions first (e.g. with the ffmpeg builder), then package them here.
 */
class Builder
{
    use Conditionable;
    use HasContext;
    use HasSaveCallbacks;

    /** @var list<PackagingStream> */
    protected array $streams = [];

    protected ?string $dashManifest = null;

    protected ?string $hlsPlaylist = null;

    protected ?HlsPlaylistType $hlsPlaylistType = null;

    protected ?float $segmentDuration = null;

    protected ?float $fragmentDuration = null;

    protected ?string $defaultLanguage = null;

    protected ?string $defaultTextLanguage = null;

    protected bool $allowCodecSwitching = false;

    protected bool $approximateSegmentTimeline = false;

    /** @var array<string, string|int|float|bool|null> */
    protected array $options = [];

    protected ?Encryption $encryption = null;

    protected ?string $driver = null;

    protected ?Disk $targetDisk = null;

    protected ?string $visibility = null;

    protected ?int $timeout = null;

    public function __construct(
        protected Opener $opener,
        protected PackagerManager $packagers,
        protected TemporaryDirectories $directories,
        protected Exporter $exporter,
    ) {}

    /**
     * Add the video stream of a file (the first opened one by default).
     *
     * @param  array<string, string>  $options  Extra driver-specific stream fields.
     */
    public function addVideoStream(?string $path = null, ?string $output = null, array $options = []): static
    {
        return $this->addStream(StreamType::Video, $path, $output ?? $this->defaultOutput(StreamType::Video), null, $options);
    }

    /**
     * Add the audio stream of a file (the first opened one by default).
     *
     * @param  array<string, string>  $options  Extra driver-specific stream fields.
     */
    public function addAudioStream(?string $path = null, ?string $output = null, ?string $language = null, array $options = []): static
    {
        return $this->addStream(StreamType::Audio, $path, $output ?? $this->defaultOutput(StreamType::Audio), $language, $options);
    }

    /**
     * Add a subtitle file (WebVTT, or text in MP4) from the disk the media was opened from. Package it
     * as fragmented MP4 (an ".mp4" output) for DASH, because a plain ".vtt" output gets no segment index.
     *
     * @param  array<string, string>  $options  Extra driver-specific stream fields, e.g. ['dash_roles' => 'subtitle'].
     */
    public function addTextStream(string $path, ?string $output = null, ?string $language = null, array $options = []): static
    {
        return $this->addStream(StreamType::Text, $path, $output ?? $this->defaultOutput(StreamType::Text), $language, $options);
    }

    /**
     * Add the video and audio streams every opened file (or the given ones) contains, found by probing them.
     *
     * @param  list<string>|null  $paths
     */
    public function addStreamsFrom(?array $paths = null): static
    {
        foreach ($paths ?? $this->opener->paths() as $index => $path) {
            $probe = $this->opener->probe($path);
            $audio = $probe->audioStream();

            if ($probe->hasVideo()) {
                $this->addVideoStream($path, "{$index}_video.mp4");
            }

            if ($audio !== null) {
                $this->addAudioStream($path, "{$index}_audio.mp4", $audio->language !== 'und' ? $audio->language : null);
            }
        }

        return $this;
    }

    public function withDashManifest(string $path = 'manifest.mpd'): static
    {
        $this->dashManifest = ltrim($path, '/');

        return $this;
    }

    public function withHlsPlaylist(string $path = 'master.m3u8', HlsPlaylistType $type = HlsPlaylistType::Vod): static
    {
        $this->hlsPlaylist = ltrim($path, '/');
        $this->hlsPlaylistType = $type;

        return $this;
    }

    /**
     * Settings for on-demand playback: a VOD HLS playlist, codec switching and an approximate
     * segment timeline, which avoids DASH errors from small timestamp gaps in encoded media.
     */
    public function forVod(): static
    {
        $this->hlsPlaylistType = HlsPlaylistType::Vod;

        return $this->allowCodecSwitching()->approximateSegmentTimeline();
    }

    public function segmentDuration(float $seconds): static
    {
        $this->segmentDuration = $seconds;

        return $this;
    }

    public function fragmentDuration(float $seconds): static
    {
        $this->fragmentDuration = $seconds;

        return $this;
    }

    public function defaultLanguage(string $language): static
    {
        $this->defaultLanguage = $language;

        return $this;
    }

    public function defaultTextLanguage(string $language): static
    {
        $this->defaultTextLanguage = $language;

        return $this;
    }

    /**
     * Let players switch between renditions with different codecs, e.g. AV1 and H.264.
     */
    public function allowCodecSwitching(bool $allow = true): static
    {
        $this->allowCodecSwitching = $allow;

        return $this;
    }

    public function approximateSegmentTimeline(bool $approximate = true): static
    {
        $this->approximateSegmentTimeline = $approximate;

        return $this;
    }

    /**
     * Encrypt the segments with AES (Common Encryption), using a new random key unless one is given.
     * The raw key is written next to the segments as the key file, and HLS playlists point to it.
     * Keep that disk private and serve the key through an authorized route or a short-lived signed
     * URL, or pass keyFile: null and keyUri to serve a stored key yourself. DASH manifests have no
     * key URI, so DASH players need the key themselves (e.g. Shaka Player's drm.clearKeys).
     */
    public function withEncryption(
        ?EncryptionKey $key = null,
        ?ProtectionScheme $scheme = null,
        ?string $keyFile = 'key',
        ?string $keyUri = null,
        ?string $label = null,
    ): static {
        $this->encryption = new Encryption(
            key: $key ?? EncryptionKey::generate(),
            scheme: $scheme,
            keyFile: $keyFile,
            keyUri: $keyUri,
            rotation: $this->encryption?->rotation,
            clearLead: $this->encryption->clearLead ?? 0.0,
            label: $label,
        );

        return $this;
    }

    /**
     * Use a new key every given number of seconds. Shaka Packager derives later keys from the first
     * one, and only the first key is returned, so test full playback before relying on it.
     */
    public function withKeyRotation(int $seconds): static
    {
        $this->encryption = $this->encryptionOrNew()->with(['rotation' => $seconds]);

        return $this;
    }

    /**
     * Leave the first seconds unencrypted, so playback can start before the key is fetched.
     */
    public function withClearLead(float $seconds): static
    {
        $this->encryption = $this->encryptionOrNew()->with(['clearLead' => $seconds]);

        return $this;
    }

    /**
     * The key the segments will be encrypted with, if encryption is enabled.
     */
    public function encryptionKey(): ?EncryptionKey
    {
        return $this->encryption?->key;
    }

    /**
     * Set an option the package has no method for, passed to the driver as is,
     * e.g. withOption('hls_base_url', 'https://cdn.test/') for Shaka.
     */
    public function withOption(string $key, string|int|float|bool|null $value = true): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Set several driver options at once, e.g. withOptions(ShakaOptions::make()->lowLatencyDashMode()).
     *
     * @param  array<string, string|int|float|bool|null>|Arrayable<string, string|int|float|bool|null>  $options
     */
    public function withOptions(array|Arrayable $options): static
    {
        foreach ($options instanceof Arrayable ? $options->toArray() : $options as $key => $value) {
            $this->withOption($key, $value);
        }

        return $this;
    }

    /**
     * Package with another driver than the configured media.packager.default.
     */
    public function using(string $driver): static
    {
        $this->driver = $driver;

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
     * The maximum seconds the packager may run, instead of the configured media.timeout.
     */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * The disk the packaged files are saved to. Defaults to the disk the media was opened from.
     */
    public function disk(): Disk
    {
        return $this->targetDisk ?? $this->opener->disk();
    }

    public function media(): Opener
    {
        return $this->opener;
    }

    public function packager(): Packager
    {
        return $this->packagers->driver($this->driver);
    }

    public function spec(): PackagingSpec
    {
        return new PackagingSpec(
            streams: $this->streams,
            dashManifest: $this->dashManifest,
            hlsPlaylist: $this->hlsPlaylist,
            hlsPlaylistType: $this->hlsPlaylistType,
            segmentDuration: $this->segmentDuration,
            fragmentDuration: $this->fragmentDuration,
            defaultLanguage: $this->defaultLanguage,
            defaultTextLanguage: $this->defaultTextLanguage,
            allowCodecSwitching: $this->allowCodecSwitching,
            approximateSegmentTimeline: $this->approximateSegmentTimeline,
            options: $this->options,
            encryption: $this->encryption,
        );
    }

    /**
     * The full packager command line, with sensitive values redacted.
     */
    public function command(string $directory = 'output'): string
    {
        return $this->packager()->command($this->spec(), $directory);
    }

    /**
     * Package the streams and save the segments and manifests to a directory on the target disk.
     * The result lists the manifests first.
     *
     * @throws InvalidMediaException
     */
    public function save(string $directory = ''): ExportResult
    {
        if ($this->streams === []) {
            throw InvalidMediaException::noStreams();
        }

        $this->runBeforeSavingCallbacks();

        $startedAt = hrtime(true);

        try {
            $result = $this->export(trim($directory, '/'));
        } catch (Throwable $exception) {
            Event::dispatch(new ExportFailed($exception, $this->context));

            throw $exception;
        }

        $this->runAfterSavingCallbacks($result);

        Event::dispatch(new ExportCompleted($result, $this->context, (hrtime(true) - $startedAt) / 1e9));

        return $result;
    }

    protected function export(string $directory): ExportResult
    {
        $spec = $this->spec();
        $output = $this->directories->create();

        try {
            foreach ([...$spec->manifests(), ...array_map(fn (PackagingStream $stream): string => $stream->output, $spec->streams)] as $file) {
                $output->makeDirectory(dirname($file));
            }

            $this->packager()->package($spec, $output, $this->timeout);

            if ($spec->encryption?->keyFile !== null) {
                $output->put($spec->encryption->keyFile, $spec->encryption->key->binary());
            }

            $written = $this->exporter->export($output->path(), $this->disk(), $directory, $this->visibility, move: true);
        } finally {
            $output->delete();
        }

        $manifests = array_map(fn (string $manifest): string => ltrim("{$directory}/{$manifest}", '/'), $spec->manifests());

        return new ExportResult($this->disk(), array_values(array_unique([
            ...array_intersect($manifests, $written),
            ...$written,
        ])), $spec->encryption?->key);
    }

    /**
     * @param  array<string, string>  $options
     */
    protected function addStream(StreamType $type, ?string $path, string $output, ?string $language, array $options): static
    {
        $this->streams[] = new PackagingStream($type, $this->resolve($path), ltrim($output, '/'), $language, $options);

        return $this;
    }

    /**
     * An opened file, or another file on the disk the media was opened from.
     */
    protected function resolve(?string $path): Media
    {
        if ($path === null || in_array($path, $this->opener->paths(), true)) {
            return $this->opener->mediaFor($path);
        }

        return $this->opener->makeMedia($this->opener->disk(), $path);
    }

    protected function encryptionOrNew(): Encryption
    {
        return $this->encryption ?? new Encryption(EncryptionKey::generate());
    }

    protected function defaultOutput(StreamType $type): string
    {
        $count = count(array_filter($this->streams, fn (PackagingStream $stream): bool => $stream->type === $type));

        return "{$type->value}_{$count}.mp4";
    }
}
