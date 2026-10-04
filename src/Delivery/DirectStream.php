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
use Foxws\Media\Probe\AudioStream;
use Foxws\Media\Probe\Probe;
use Foxws\Media\Probe\VideoStream;
use Foxws\Media\Process\Runner;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the opened files as HLS or DASH straight from where they're stored, like nginx-vod-module:
 * playlists are built from the keyframe index, and each segment is copied into MPEG-TS, or into
 * fragmented MP4 per track, the first time it's requested, then kept on a cache disk. Every opened
 * file is one variant.
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

    protected bool $fragmented = false;

    /** @var list<Subtitle> */
    protected array $externalSubtitles = [];

    protected bool $embeddedSubtitles = false;

    /** @var list<Subtitle>|null */
    protected ?array $subtitles = null;

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
     * Serve HLS with fragmented MP4 (CMAF) segments instead of MPEG-TS: video and audio become separate
     * tracks, shared with dashManifest(), and the audio of the first file with audio is the audio rendition.
     * Route::mediaStream() picks the format per route, so only call this when serving playlists yourself.
     */
    public function fragmented(bool $fragmented = true): static
    {
        $this->fragmented = $fragmented;

        return $this;
    }

    public function isFragmented(): bool
    {
        return $this->fragmented;
    }

    /**
     * Add a WebVTT subtitle file, from the disk the media was opened from unless another is given.
     */
    public function withSubtitles(string $path, ?string $language = null, ?string $label = null, Disk|Filesystem|string|null $disk = null): static
    {
        $this->externalSubtitles[] = new Subtitle(
            label: $label ?? $language ?? 'Subtitles '.(count($this->externalSubtitles) + 1),
            language: $language,
            disk: $disk !== null ? Disk::make($disk) : $this->opener->disk(),
            path: $path,
        );

        $this->subtitles = null;

        return $this;
    }

    /**
     * Also offer the text subtitle streams of the first opened file (SubRip, MP4 text, ASS, ...),
     * converted to WebVTT when first requested. Bitmap subtitles are left out.
     */
    public function withEmbeddedSubtitles(bool $embedded = true): static
    {
        $this->embeddedSubtitles = $embedded;
        $this->subtitles = null;

        return $this;
    }

    /**
     * The subtitle tracks: the added files first, then the embedded streams.
     *
     * @return list<Subtitle>
     */
    public function subtitles(): array
    {
        if ($this->subtitles !== null) {
            return $this->subtitles;
        }

        $subtitles = $this->externalSubtitles;

        if ($this->embeddedSubtitles) {
            foreach ($this->probe(0)->subtitleStreams() as $stream) {
                if (TextSubtitleCodec::supports($stream->codecName)) {
                    $subtitles[] = new Subtitle(
                        label: $stream->tags['title'] ?? $stream->language ?? 'Subtitles '.(count($subtitles) + 1),
                        language: $stream->language,
                        stream: $stream->index,
                    );
                }
            }
        }

        return $this->subtitles = $subtitles;
    }

    /**
     * The HLS media playlist of a subtitle track: one segment with the whole WebVTT file.
     *
     * @throws SegmentNotFoundException
     */
    public function subtitlePlaylist(int $subtitle, string $url): string
    {
        $this->subtitleFor($subtitle);
        $duration = $this->duration();

        return implode("\n", [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-TARGETDURATION:'.(int) ceil($duration),
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
            '#EXTINF:'.number_format($duration, 6, '.', '').',',
            $url,
            '#EXT-X-ENDLIST',
        ])."\n";
    }

    /**
     * The WebVTT content of a subtitle track. Embedded streams are converted once, under a lock,
     * and cached on the cache disk.
     *
     * @throws SegmentNotFoundException
     */
    public function subtitle(int $subtitle): string
    {
        $track = $this->subtitleFor($subtitle);

        if (! $track->isEmbedded()) {
            return $track->disk?->get((string) $track->path) ?? throw SegmentNotFoundException::forSubtitle($subtitle);
        }

        $media = $this->media(0);
        $path = $this->cachePrefix($media, withDuration: false)."/subtitles/{$track->stream}.vtt";

        if (! $this->cacheDisk()->exists($path)) {
            Cache::lock("media:segment:{$path}", Config::integer('media.delivery.lock_timeout', 120))
                ->block(Config::integer('media.delivery.lock_timeout', 120), function () use ($media, $track, $path): void {
                    if (! $this->cacheDisk()->exists($path)) {
                        $this->convertSubtitle($media, (int) $track->stream, $path);
                    }
                });
        }

        return (string) $this->cacheDisk()->get($path);
    }

    /**
     * A WebVTT response for a subtitle track. HLS players line up cues with the media through an
     * X-TIMESTAMP-MAP header, so pass the timestamp offset of the segments the playlist uses:
     * 0 for MPEG-TS, FragmentedMp4::TIMESTAMP_OFFSET for fragmented MP4, and null for DASH.
     *
     * @throws SegmentNotFoundException
     */
    public function subtitleResponse(int $subtitle, ?float $timestampOffset = null): Response
    {
        $content = $this->subtitle($subtitle);

        if ($timestampOffset !== null) {
            $map = 'X-TIMESTAMP-MAP=MPEGTS:'.(int) round($timestampOffset * 90000).',LOCAL:00:00:00.000';
            $content = (string) preg_replace('/^X-TIMESTAMP-MAP=.*\R/m', '', $content);
            $content = str_starts_with(ltrim($content, "\u{FEFF}"), 'WEBVTT')
                ? (string) preg_replace('/^\x{FEFF}?(WEBVTT[^\r\n]*)/u', "\$1\n{$map}", $content, 1)
                : "WEBVTT\n{$map}\n\n{$content}";
        }

        return new Response($content, 200, [
            'Content-Type' => 'text/vtt; charset=utf-8',
            'Cache-Control' => 'public, max-age='.Config::integer('media.delivery.url_lifetime', 3600),
        ]);
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
     * The master playlist, listing every opened file as a variant. Fragmented streams list the video
     * track of every file with video, and the audio track of the first file with audio as the audio rendition.
     *
     * @param  callable(int, Track|null): string  $playlistUrl  Receives the variant's index and its track (null for MPEG-TS) and returns its media playlist URL.
     * @param  (callable(int): string)|null  $subtitleUrl  Receives a subtitle track's index and returns its media playlist URL. Required with subtitles.
     *
     * @throws InvalidArgumentException
     */
    public function masterPlaylist(callable $playlistUrl, ?callable $subtitleUrl = null): string
    {
        $subtitles = $this->subtitleRenditions($subtitleUrl);
        $group = $subtitles !== [] ? 'subtitles' : null;

        if (! $this->fragmented) {
            $lines = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-INDEPENDENT-SEGMENTS', ...$subtitles];

            foreach ($this->opener->paths() as $variant => $path) {
                $lines[] = $this->streamInf($path, Codecs::for($this->opener->probe($path)), subtitleGroup: $group);
                $lines[] = $playlistUrl($variant, null);
            }

            return implode("\n", $lines)."\n";
        }

        $this->ensureUnencrypted();

        $lines = ['#EXTM3U', '#EXT-X-VERSION:7', '#EXT-X-INDEPENDENT-SEGMENTS', ...$subtitles];
        $audio = $this->audioVariant();
        $audioCodec = $audio !== null ? Codecs::audio($this->probe($audio)->audioStream() ?? throw SegmentNotFoundException::for($audio, 0)) : null;
        $videos = $this->videoVariants();

        if ($audio !== null && $videos !== []) {
            $lines[] = '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Audio",DEFAULT=YES,AUTOSELECT=YES,URI="'.$playlistUrl($audio, Track::Audio).'"';
        }

        foreach ($videos as $variant) {
            $video = $this->probe($variant)->videoStream();
            $codecs = Codecs::join([$video !== null ? Codecs::video($video) : null, ...($audio !== null ? [$audioCodec] : [])]);

            $lines[] = $this->streamInf($this->opener->paths()[$variant], $codecs, $audio !== null ? 'audio' : null, $group);
            $lines[] = $playlistUrl($variant, Track::Video);
        }

        if ($videos === [] && $audio !== null) {
            $lines[] = $this->streamInf($this->opener->paths()[$audio], $audioCodec, subtitleGroup: $group);
            $lines[] = $playlistUrl($audio, Track::Audio);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The media playlist of a variant, or of one of its tracks when the stream is fragmented or a track is given, with one entry per segment.
     *
     * @param  callable(Segment, int, Track|null): string  $segmentUrl  Receives the segment, the variant's index and the track (null for MPEG-TS) and returns its URL.
     * @param  Track|null  $track  The track of a fragmented stream; defaults to the variant's video, or its audio.
     * @param  (callable(int, Track): string)|null  $initUrl  Receives the variant's index and the track, and returns the URL of the initialization segment. Required for fragmented streams.
     *
     * @throws SegmentNotFoundException
     * @throws InvalidArgumentException
     */
    public function mediaPlaylist(int $variant, callable $segmentUrl, ?Track $track = null, ?callable $initUrl = null): string
    {
        $map = null;

        if ($this->fragmented || $track !== null) {
            $this->ensureUnencrypted();

            $track ??= $this->probe($variant)->hasVideo() ? Track::Video : Track::Audio;
            $this->ensureTrack($variant, $track);

            $map = $initUrl !== null
                ? $initUrl($variant, $track)
                : throw new InvalidArgumentException('Fragmented streams need the URL of the initialization segment.');
        }

        if ($this->keys !== null && $this->keyUrl === null) {
            throw new InvalidArgumentException('Encrypted streams need a key URL. Pass one to withEncryption() or keyUrlsUsing().');
        }

        $index = $this->index($variant);
        $segments = $index->segments($this->targetDuration());

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:'.($map !== null ? 7 : 3),
            '#EXT-X-TARGETDURATION:'.(int) ceil($index->longestSegment($this->targetDuration())),
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
        ];

        if ($map !== null) {
            $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';
            $lines[] = '#EXT-X-MAP:URI="'.$map.'"';
        }

        $period = null;

        foreach ($segments as $segment) {
            if ($this->keys !== null && $this->keyUrl !== null && $period !== $this->period($segment->index)) {
                $period = $this->period($segment->index);

                $lines[] = '#EXT-X-KEY:METHOD=AES-128,URI="'.($this->keyUrl)($period, $variant).'"';
            }

            $lines[] = '#EXTINF:'.number_format($segment->duration, 6, '.', '').',';
            $lines[] = $segmentUrl($segment, $variant, $map !== null ? $track : null);
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines)."\n";
    }

    /**
     * A static DASH manifest with fragmented MP4 segments: one video representation per file with video,
     * and the audio of the first file with audio. Every segment is listed, so each URL can be signed.
     *
     * @param  callable(int, Track): string  $initUrl  Receives the variant's index and the track, and returns the URL of the initialization segment.
     * @param  callable(Segment, int, Track): string  $segmentUrl  Receives the segment, the variant's index and the track, and returns its URL.
     * @param  (callable(int): string)|null  $subtitleUrl  Receives a subtitle track's index and returns the URL of its WebVTT file. Required with subtitles.
     *
     * @throws InvalidArgumentException
     */
    public function dashManifest(callable $initUrl, callable $segmentUrl, ?callable $subtitleUrl = null): string
    {
        $this->ensureUnencrypted();

        if ($this->subtitles() !== [] && $subtitleUrl === null) {
            throw new InvalidArgumentException('Streams with subtitles need the URLs of their WebVTT files.');
        }

        $duration = $this->duration();
        $sets = [];

        if (($videos = $this->videoVariants()) !== []) {
            $representations = array_map(fn (int $variant): string => $this->representation($variant, Track::Video, $initUrl, $segmentUrl), $videos);

            $sets[] = '    <AdaptationSet id="0" contentType="video" mimeType="video/mp4" startWithSAP="1">'."\n".implode("\n", $representations)."\n".'    </AdaptationSet>';
        }

        if (($audio = $this->audioVariant()) !== null) {
            $language = $this->probe($audio)->audioStream()?->language;
            $lang = $language !== null && $language !== 'und' ? ' lang="'.$this->xml($language).'"' : '';

            $sets[] = '    <AdaptationSet id="1" contentType="audio" mimeType="audio/mp4"'.$lang.' startWithSAP="1">'."\n".$this->representation($audio, Track::Audio, $initUrl, $segmentUrl)."\n".'    </AdaptationSet>';
        }

        // Text sets point straight at the WebVTT file, without SegmentBase, which players load as one segment.
        foreach ($this->subtitles() as $index => $subtitle) {
            $sets[] = implode("\n", [
                '    <AdaptationSet id="'.($index + 2).'" contentType="text" mimeType="text/vtt"'.($subtitle->language !== null && $subtitle->language !== 'und' ? ' lang="'.$this->xml($subtitle->language).'"' : '').'>',
                '      <Label>'.$this->xml($subtitle->label).'</Label>',
                '      <Role schemeIdUri="urn:mpeg:dash:role:2011" value="subtitle"/>',
                '      <Representation id="text-'.$index.'" bandwidth="256">',
                '        <BaseURL>'.$this->xml($subtitleUrl !== null ? $subtitleUrl($index) : '').'</BaseURL>',
                '      </Representation>',
                '    </AdaptationSet>',
            ]);
        }

        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-main:2011" type="static" mediaPresentationDuration="'.$this->isoDuration($duration).'" minBufferTime="'.$this->isoDuration($this->targetDuration()).'">',
            '  <Period id="0" start="PT0S">',
            ...$sets,
            '  </Period>',
            '</MPD>',
        ])."\n";
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
     * The path of a segment on the cache disk, packaging it first if it isn't cached yet: MPEG-TS
     * with video and audio, or fragmented MP4 with one track. Packaging runs under a lock, so
     * concurrent requests for the same segment package it once.
     *
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function segment(int $variant, int $index, ?Track $track = null): string
    {
        $media = $this->media($variant);
        $segment = $this->segments($variant)[$index] ?? throw SegmentNotFoundException::for($variant, $index);
        $path = $this->segmentPath($media, $segment, $track);
        $disk = $this->cacheDisk();

        if ($disk->exists($path)) {
            return $path;
        }

        if ($track !== null) {
            $this->ensureTrack($variant, $track);
        }

        $this->ensureStreamable($media, $track);

        return Cache::lock("media:segment:{$path}", Config::integer('media.delivery.lock_timeout', 120))
            ->block(Config::integer('media.delivery.lock_timeout', 120), function () use ($media, $segment, $path, $track): string {
                // Another request may have packaged it while this one waited for the lock.
                if (! $this->cacheDisk()->exists($path)) {
                    $track !== null ? $this->packageFragment($media, $segment, $track, $path) : $this->package($media, $segment, $path);
                }

                return $path;
            });
    }

    /**
     * The path of a track's initialization segment on the cache disk. It's written with every
     * segment of the track, so the first segment is packaged when it isn't cached yet.
     *
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function initSegment(int $variant, Track $track): string
    {
        $media = $this->media($variant);
        $path = $this->initPath($media, $track);

        if (! $this->cacheDisk()->exists($path)) {
            $this->segment($variant, 0, $track);
        }

        return $path;
    }

    /**
     * A response for a track's initialization segment, served like segmentResponse().
     *
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function initSegmentResponse(int $variant, Track $track): Response
    {
        return $this->fileResponse($this->initSegment($variant, $track), $track->contentType());
    }

    /**
     * A response for a segment: a redirect to a temporary URL when the cache disk provides them
     * (e.g. S3), otherwise the file itself. Encrypted streams always respond with the segment,
     * encrypted with the key of its rotation period.
     *
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function segmentResponse(int $variant, int $index, ?Track $track = null): Response
    {
        if ($track !== null) {
            $this->ensureUnencrypted();

            return $this->fileResponse($this->segment($variant, $index, $track), $track->contentType());
        }

        $path = $this->segment($variant, $index);

        if ($this->keys !== null) {
            return new Response($this->encrypt((string) $this->cacheDisk()->get($path), $index), 200, [
                'Content-Type' => 'video/mp2t',
                'Cache-Control' => 'private, max-age='.Config::integer('media.delivery.url_lifetime', 3600),
            ]);
        }

        return $this->fileResponse($path, 'video/mp2t');
    }

    protected function fileResponse(string $path, string $contentType): Response
    {
        $disk = $this->cacheDisk();
        $lifetime = Config::integer('media.delivery.url_lifetime', 3600);

        if (! $disk->isLocal() && $disk->providesTemporaryUrls()) {
            return new RedirectResponse($disk->temporaryUrl($path, now()->addSeconds($lifetime)));
        }

        return $disk->filesystem()->response($path, basename($path), [
            'Content-Type' => $contentType,
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
     * Copy one track of a segment into a fragmented MP4 file, then split it into the media segment
     * and the track's initialization segment. Timestamps are kept (-copyts) and shifted by the same
     * offset in every segment, so segments muxed one at a time line up as if muxed in one run.
     *
     * @throws InvalidMediaException
     */
    protected function packageFragment(Media $media, Segment $segment, Track $track, string $path): void
    {
        $directory = $this->directories->create();
        $output = $directory->path('fragment.mp4');

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
                '-map', $track->map(),
                '-c', 'copy',
                ...($track === Track::Video && $this->opener->probe($media->path())->videoStream()?->codecName === 'hevc' ? ['-tag:v', 'hvc1'] : []),
                '-output_ts_offset', (string) FragmentedMp4::TIMESTAMP_OFFSET,
                '-avoid_negative_ts', 'disabled',
                '-use_editlist', '0',
                '-movflags', '+frag_keyframe+empty_moov+default_base_moof+frag_discont',
                '-fflags', '+bitexact',
                '-f', 'mp4',
                $output,
            ]);

            $parts = FragmentedMp4::split((string) file_get_contents($output));
            unlink($output);

            file_put_contents($directory->path(basename($path)), $parts['media']);
            file_put_contents($directory->path('init.mp4'), $parts['init']);

            $this->exporter->export($directory->path(), $this->cacheDisk(), dirname($path), move: true);
        } finally {
            $directory->delete();
        }
    }

    /**
     * Where a segment is cached: per file version, so a changed file gets new segments.
     */
    protected function segmentPath(Media $media, Segment $segment, ?Track $track = null): string
    {
        return $track !== null
            ? "{$this->cachePrefix($media)}/{$track->value}/{$segment->index}.m4s"
            : "{$this->cachePrefix($media)}/{$segment->index}.ts";
    }

    protected function initPath(Media $media, Track $track): string
    {
        return "{$this->cachePrefix($media)}/{$track->value}/init.mp4";
    }

    protected function cachePrefix(Media $media, bool $withDuration = true): string
    {
        $prefix = trim(Config::string('media.delivery.cache_path', 'media-segments'), '/');
        $duration = $withDuration ? '/'.Number::format($this->targetDuration()) : '';

        return ltrim("{$prefix}/{$media->versionKey()}{$duration}", '/');
    }

    /**
     * Convert an embedded text subtitle stream to WebVTT on the cache disk.
     */
    protected function convertSubtitle(Media $media, int $stream, string $path): void
    {
        $directory = $this->directories->create();

        try {
            $this->runner->run(Executable::FFMpeg, [
                '-y',
                '-hide_banner',
                '-nostdin',
                '-loglevel', Config::string('media.ffmpeg_log_level', 'error'),
                '-i', $media->inputPath(),
                '-map', "0:{$stream}",
                '-c:s', 'webvtt',
                '-f', 'webvtt',
                $directory->path(basename($path)),
            ]);

            $this->exporter->export($directory->path(), $this->cacheDisk(), dirname($path), move: true);
        } finally {
            $directory->delete();
        }
    }

    /**
     * @throws SegmentNotFoundException
     */
    protected function subtitleFor(int $subtitle): Subtitle
    {
        return $this->subtitles()[$subtitle] ?? throw SegmentNotFoundException::forSubtitle($subtitle);
    }

    /**
     * The #EXT-X-MEDIA lines of the subtitle tracks.
     *
     * @param  (callable(int): string)|null  $subtitleUrl
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    protected function subtitleRenditions(?callable $subtitleUrl): array
    {
        $lines = [];

        foreach ($this->subtitles() as $index => $subtitle) {
            if ($subtitleUrl === null) {
                throw new InvalidArgumentException('Streams with subtitles need the URLs of their playlists.');
            }

            $lines[] = '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subtitles",NAME="'.str_replace('"', "'", $subtitle->label).'"'
                .($subtitle->language !== null && $subtitle->language !== 'und' ? ',LANGUAGE="'.str_replace('"', '', $subtitle->language).'"' : '')
                .',DEFAULT=NO,AUTOSELECT=YES,URI="'.$subtitleUrl($index).'"';
        }

        return $lines;
    }

    /**
     * The longest duration of the opened files.
     */
    protected function duration(): float
    {
        return max([0.0, ...array_map(fn (string $path): float => $this->opener->probe($path)->duration(), $this->opener->paths())]);
    }

    /**
     * @throws InvalidMediaException
     */
    protected function ensureStreamable(Media $media, ?Track $track = null): void
    {
        $probe = $this->opener->probe($media->path());

        $streams = match ($track) {
            null => [$probe->videoStream(), $probe->audioStream()],
            Track::Video => [$probe->videoStream()],
            Track::Audio => [$probe->audioStream()],
        };

        foreach ($streams as $stream) {
            if ($stream === null) {
                continue;
            }

            if ($track === null && ! TransportStreamCodec::supports($stream->codecName)) {
                throw InvalidMediaException::notStreamable($media->path(), (string) $stream->codecName);
            }

            if ($track !== null && ! FragmentedMp4Codec::supports($stream->codecName)) {
                throw InvalidMediaException::notFragmentable($media->path(), (string) $stream->codecName);
            }
        }
    }

    /**
     * @throws SegmentNotFoundException
     */
    protected function ensureTrack(int $variant, Track $track): void
    {
        $probe = $this->probe($variant);

        if (! ($track === Track::Video ? $probe->hasVideo() : $probe->hasAudio())) {
            throw SegmentNotFoundException::forTrack($variant, $track->value);
        }
    }

    /**
     * Per-request AES-128 encrypts whole MPEG-TS segments; fragmented MP4 would need sample encryption (CENC).
     *
     * @throws InvalidArgumentException
     */
    protected function ensureUnencrypted(): void
    {
        if ($this->keys !== null) {
            throw new InvalidArgumentException('Encrypted direct streams use MPEG-TS segments. Package with encryption for encrypted fragmented MP4 or DASH.');
        }
    }

    /**
     * A DASH representation of a track, with its segments listed on a millisecond timeline.
     *
     * @param  callable(int, Track): string  $initUrl
     * @param  callable(Segment, int, Track): string  $segmentUrl
     */
    protected function representation(int $variant, Track $track, callable $initUrl, callable $segmentUrl): string
    {
        $probe = $this->probe($variant);
        $stream = ($track === Track::Video ? $probe->videoStream() : $probe->audioStream()) ?? throw SegmentNotFoundException::forTrack($variant, $track->value);
        $codecs = $track === Track::Video ? Codecs::video($stream) : Codecs::audio($stream);
        $segments = $this->segments($variant);
        $offset = FragmentedMp4::TIMESTAMP_OFFSET * 1000;

        $attributes = array_filter([
            'id' => "{$track->value}-{$variant}",
            'codecs' => $codecs,
            'bandwidth' => (string) ($track === Track::Audio ? ($stream->bitRate ?? 128000) : $this->bandwidth($this->opener->paths()[$variant])),
            'width' => $stream instanceof VideoStream && $stream->width !== null ? (string) $stream->width : null,
            'height' => $stream instanceof VideoStream && $stream->height !== null ? (string) $stream->height : null,
            'frameRate' => $stream instanceof VideoStream && preg_match('#^[1-9]\d*(/[1-9]\d*)?$#', (string) $stream->get('avg_frame_rate')) === 1 ? (string) $stream->get('avg_frame_rate') : null,
            'audioSamplingRate' => $stream instanceof AudioStream && $stream->sampleRate !== null ? (string) $stream->sampleRate : null,
        ]);

        $timeline = [];

        foreach ($segments as $segment) {
            $start = (int) round($segment->start * 1000);
            $duration = (int) round($segment->end() * 1000) - $start;
            $last = array_key_last($timeline);

            if ($last !== null && $timeline[$last]['d'] === $duration) {
                $timeline[$last]['r']++;
            } else {
                $timeline[] = ['t' => $start + $offset, 'd' => $duration, 'r' => 0];
            }
        }

        $lines = [
            '      <Representation '.implode(' ', array_map(fn (string $key, string $value): string => "{$key}=\"{$this->xml($value)}\"", array_keys($attributes), $attributes)).'>',
            '        <SegmentList timescale="1000" presentationTimeOffset="'.$offset.'">',
            '          <Initialization sourceURL="'.$this->xml($initUrl($variant, $track)).'"/>',
            '          <SegmentTimeline>',
            ...array_map(fn (array $s): string => '            <S t="'.$s['t'].'" d="'.$s['d'].'"'.($s['r'] > 0 ? ' r="'.$s['r'].'"' : '').'/>', $timeline),
            '          </SegmentTimeline>',
            ...array_map(fn (Segment $segment): string => '          <SegmentURL media="'.$this->xml($segmentUrl($segment, $variant, $track)).'"/>', $segments),
            '        </SegmentList>',
            '      </Representation>',
        ];

        return implode("\n", $lines);
    }

    /**
     * The variants with video, for the video tracks of fragmented streams.
     *
     * @return list<int>
     */
    protected function videoVariants(): array
    {
        return array_values(array_filter(array_keys($this->opener->paths()), fn (int $variant): bool => $this->probe($variant)->hasVideo()));
    }

    /**
     * The first variant with audio, whose audio track all video tracks of a fragmented stream share.
     */
    protected function audioVariant(): ?int
    {
        foreach (array_keys($this->opener->paths()) as $variant) {
            if ($this->probe($variant)->hasAudio()) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @throws SegmentNotFoundException
     */
    protected function probe(int $variant): Probe
    {
        return $this->opener->probe($this->media($variant)->path());
    }

    /**
     * An #EXT-X-STREAM-INF line with the variant's bandwidth, resolution, frame rate and codecs.
     */
    protected function streamInf(string $path, ?string $codecs, ?string $audioGroup = null, ?string $subtitleGroup = null): string
    {
        $video = $this->opener->probe($path)->videoStream();

        $attributes = array_filter([
            'BANDWIDTH' => (string) $this->bandwidth($path),
            'RESOLUTION' => $video?->width !== null && $video->height !== null ? "{$video->width}x{$video->height}" : null,
            'FRAME-RATE' => $video?->frameRate !== null ? number_format($video->frameRate, 3, '.', '') : null,
            'CODECS' => $codecs !== null ? "\"{$codecs}\"" : null,
            'AUDIO' => $audioGroup !== null ? "\"{$audioGroup}\"" : null,
            'SUBTITLES' => $subtitleGroup !== null ? "\"{$subtitleGroup}\"" : null,
        ]);

        return '#EXT-X-STREAM-INF:'.implode(',', array_map(fn (string $key, string $value): string => "{$key}={$value}", array_keys($attributes), $attributes));
    }

    protected function isoDuration(float $seconds): string
    {
        return 'PT'.number_format($seconds, 3, '.', '').'S';
    }

    protected function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
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
