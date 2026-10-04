<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Closure;
use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Exceptions\SegmentNotFoundException;
use Foxws\Media\Executables\Executable;
use Foxws\Media\FFMpeg\Scene;
use Foxws\Media\FFMpeg\ThumbnailsResult;
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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Illuminate\Support\defer;

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

    /** @var (Closure(): string)|null */
    protected ?Closure $licenseUrl = null;

    protected ?int $rotateEvery = null;

    protected bool $fragmented = false;

    /** @var list<Subtitle> */
    protected array $externalSubtitles = [];

    protected bool $embeddedSubtitles = false;

    /** @var list<Subtitle>|null */
    protected ?array $subtitles = null;

    protected ?ThumbnailsResult $thumbnails = null;

    /** @var list<Marker> */
    protected array $markers = [];

    protected bool $chapters = false;

    /** @var list<string>|null */
    protected ?array $chapterClasses = ['chapter'];

    protected ?string $chapterGapTitle = null;

    protected ?int $lookAhead = null;

    protected ?LookAheadStrategy $lookAheadStrategy = null;

    /** @var array{video: list<int>, audio: int|null}|null */
    protected ?array $tracks = null;

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
     * Package this many segments after each requested one ahead of time, instead of
     * media.delivery.look_ahead; 0 turns it off. Without a strategy, media.delivery.look_ahead_via
     * picks one.
     */
    public function lookAhead(int $segments, ?LookAheadStrategy $strategy = null): static
    {
        $this->lookAhead = max(0, $segments);
        $this->lookAheadStrategy = $strategy;

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

    /**
     * Pick which variants give the video tracks of fragmented streams and which one gives the shared
     * audio track, instead of every variant with video and the first one with audio.
     *
     * @param  list<int>  $videoVariants
     */
    public function tracksFrom(array $videoVariants, ?int $audioVariant): static
    {
        $this->tracks = ['video' => $videoVariants, 'audio' => $audioVariant];

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
            ...$this->programDateTime(),
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
        return new Response($this->subtitleContents($subtitle, $timestampOffset), 200, [
            'Content-Type' => 'text/vtt; charset=utf-8',
            'Cache-Control' => 'public, max-age='.Config::integer('media.delivery.url_lifetime', 3600),
        ]);
    }

    /**
     * The WebVTT content of a subtitle track, mapped onto segment timestamps when an offset is given,
     * as subtitleResponse() serves it.
     */
    public function subtitleContents(int $subtitle, ?float $timestampOffset = null): string
    {
        $content = $this->subtitle($subtitle);

        if ($timestampOffset === null) {
            return $content;
        }

        $map = 'X-TIMESTAMP-MAP=MPEGTS:'.(int) round($timestampOffset * 90000).',LOCAL:00:00:00.000';
        $content = (string) preg_replace('/^X-TIMESTAMP-MAP=.*\R/m', '', $content);

        return str_starts_with(ltrim($content, "\u{FEFF}"), 'WEBVTT')
            ? (string) preg_replace('/^\x{FEFF}?(WEBVTT[^\r\n]*)/u', "\$1\n{$map}", $content, 1)
            : "WEBVTT\n{$map}\n\n{$content}";
    }

    /**
     * Offer sprite sheets made with thumbnails() as an image track, for seek previews from the
     * manifest. Make them ahead, e.g. in the job that stores the video, and keep the result with
     * toArray(); sampling a whole video is too slow for a request.
     */
    public function withThumbnails(?ThumbnailsResult $thumbnails): static
    {
        $this->thumbnails = $thumbnails;

        return $this;
    }

    public function thumbnails(): ?ThumbnailsResult
    {
        return $this->thumbnails;
    }

    /**
     * The HLS image media playlist of the thumbnails: one entry per sheet, with its grid in #EXT-X-TILES.
     *
     * @param  callable(int): string  $sheetUrl  Receives a sheet's index and returns its URL.
     *
     * @throws SegmentNotFoundException
     */
    public function thumbnailPlaylist(callable $sheetUrl): string
    {
        $thumbnails = $this->thumbnails ?? throw SegmentNotFoundException::noThumbnails();
        $longest = max([0.0, ...array_map($thumbnails->sheetDuration(...), array_keys($thumbnails->sprites))]);

        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:7',
            '#EXT-X-TARGETDURATION:'.(int) ceil($longest),
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-PLAYLIST-TYPE:VOD',
            '#EXT-X-IMAGES-ONLY',
            ...$this->programDateTime(),
        ];

        foreach (array_keys($thumbnails->sprites) as $sheet) {
            $lines[] = '#EXTINF:'.number_format($thumbnails->sheetDuration($sheet), 6, '.', '').',';
            $lines[] = "#EXT-X-TILES:RESOLUTION={$thumbnails->width}x{$thumbnails->height},LAYOUT={$thumbnails->columns}x{$thumbnails->rows},DURATION=".number_format($thumbnails->interval, 3, '.', '');
            $lines[] = $sheetUrl($sheet);
        }

        $lines[] = '#EXT-X-ENDLIST';

        return implode("\n", $lines)."\n";
    }

    /**
     * A response for a sprite sheet: a redirect to a temporary URL when its disk provides them, otherwise the file.
     *
     * @throws SegmentNotFoundException
     */
    public function thumbnailResponse(int $sheet): Response
    {
        $thumbnails = $this->thumbnails ?? throw SegmentNotFoundException::noThumbnails();
        $path = $thumbnails->sprites[$sheet] ?? throw SegmentNotFoundException::forSheet($sheet);

        return $this->fileResponse($path, $this->imageType($thumbnails), $thumbnails->disk);
    }

    /**
     * Offer named time ranges, like an intro or the credits, as #EXT-X-DATERANGE tags in the HLS media
     * playlists and as Events in the DASH manifest.
     *
     * @param  list<Marker>  $markers
     */
    public function withMarkers(array $markers): static
    {
        $this->markers = [...$this->markers, ...$markers];

        return $this;
    }

    /**
     * Offer the chapters of the first opened file as markers of the class "chapter".
     */
    public function withChapters(bool $chapters = true): static
    {
        $this->chapters = $chapters;

        return $this;
    }

    /**
     * Offer scenes as markers of the class "scene". Detect them ahead with scenes(), e.g. in the job
     * that stores the video, and keep them with toArray(); detection decodes the whole video.
     *
     * @param  list<Scene>  $scenes
     */
    public function withScenes(array $scenes): static
    {
        return $this->withMarkers(array_map(Marker::fromScene(...), $scenes));
    }

    /**
     * The markers of the stream, chapters included, in order of their start.
     *
     * @return list<Marker>
     */
    public function markers(): array
    {
        $markers = $this->markers;

        if ($this->chapters && ($path = $this->opener->paths()[0] ?? null) !== null) {
            $markers = [...array_map(Marker::fromChapter(...), $this->opener->probe($path)->chapters()), ...$markers];
        }

        usort($markers, fn (Marker $a, Marker $b): int => $a->start <=> $b->start);

        return $markers;
    }

    /**
     * Which markers the chapter track lists: those of the given classes, or every marker with null.
     * With a gap title, the gaps between them and after the last one get a cue with that title.
     *
     * @param  list<string>|null  $classes
     */
    public function chapterTrackFrom(?array $classes = ['chapter'], ?string $gapTitle = null): static
    {
        $this->chapterClasses = $classes;
        $this->chapterGapTitle = $gapTitle;

        return $this;
    }

    /**
     * The chapters as a WebVTT track, from the markers chapterTrackFrom() selects, which are the
     * chapters of withChapters() by default.
     */
    public function chapterTrack(): ChapterTrack
    {
        $markers = array_filter($this->markers(), fn (Marker $marker): bool => $this->chapterClasses === null || in_array($marker->class, $this->chapterClasses, true));

        return new ChapterTrack(array_values($markers), $this->duration(), $this->chapterGapTitle);
    }

    /**
     * @throws SegmentNotFoundException
     */
    public function chapterTrackResponse(): Response
    {
        $track = $this->chapterTrack();

        if ($track->isEmpty()) {
            throw SegmentNotFoundException::noChapters();
        }

        return new Response($track->toWebVtt(), 200, [
            'Content-Type' => 'text/vtt; charset=utf-8',
            'Cache-Control' => 'public, max-age='.Config::integer('media.delivery.url_lifetime', 3600),
        ]);
    }

    /**
     * Encrypt segments for each request; the cached segments stay unencrypted, so they can be served
     * with any key. MPEG-TS segments are encrypted whole with AES-128, and HLS players fetch the key
     * from the key URL (keyResponse()). Fragmented MP4 segments use Common Encryption (cenc) with one
     * key: CMAF playlists fetch it from the key URL too, and DASH players request it as a ClearKey
     * license from the license URL (licenseResponse()). Route::mediaStream() sets both URLs itself.
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

    /**
     * Where DASH players request the ClearKey license of an encrypted stream.
     *
     * @param  callable(): string  $licenseUrl
     */
    public function licenseUrlUsing(callable $licenseUrl): static
    {
        $this->licenseUrl = $licenseUrl(...);

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
     * The ClearKey license of an encrypted fragmented stream: its key as a JSON Web Key Set, for any
     * license request. Authorize the request first.
     */
    public function licenseResponse(): Response
    {
        return new JsonResponse(['keys' => [$this->key()->toJsonWebKey()], 'type' => 'temporary'], 200, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The master playlist, listing every opened file as a variant. Fragmented streams list the video
     * track of every file with video, and the audio track of the first file with audio as the audio rendition.
     *
     * @param  callable(int, Track|null): string  $playlistUrl  Receives the variant's index and its track (null for MPEG-TS) and returns its media playlist URL.
     * @param  (callable(int): string)|null  $subtitleUrl  Receives a subtitle track's index and returns its media playlist URL. Required with subtitles.
     * @param  (callable(): string)|null  $thumbnailUrl  Returns the URL of the thumbnails' image playlist. Required with thumbnails.
     *
     * @throws InvalidArgumentException
     */
    public function masterPlaylist(callable $playlistUrl, ?callable $subtitleUrl = null, ?callable $thumbnailUrl = null): string
    {
        $subtitles = $this->subtitleRenditions($subtitleUrl);
        $group = $subtitles !== [] ? 'subtitles' : null;
        $images = $this->imageStream($thumbnailUrl);

        if (! $this->fragmented) {
            $lines = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-INDEPENDENT-SEGMENTS', ...$subtitles];

            foreach ($this->opener->paths() as $variant => $path) {
                $lines[] = $this->streamInf($path, Codecs::for($this->opener->probe($path)), subtitleGroup: $group);
                $lines[] = $playlistUrl($variant, null);
            }

            return implode("\n", [...$lines, ...$images])."\n";
        }

        $this->ensureOneKey();

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

        return implode("\n", [...$lines, ...$images])."\n";
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
            $this->ensureOneKey();

            $track ??= $this->probe($variant)->hasVideo() ? Track::Video : Track::Audio;
            $this->ensureTrack($variant, $track);
            $this->ensureEncryptable($variant, $track);

            $map = $initUrl !== null
                ? $initUrl($variant, $track)
                : throw new InvalidArgumentException('Fragmented streams need the URL of the initialization segment.');
        }

        $keyUrl = $this->keys !== null
            ? $this->keyUrl ?? throw new InvalidArgumentException('Encrypted streams need a key URL. Pass one to withEncryption() or keyUrlsUsing().')
            : null;

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

            // Players read the key ID from the initialization segment and use the key as a ClearKey.
            if ($keyUrl !== null) {
                $lines[] = '#EXT-X-KEY:METHOD=SAMPLE-AES-CTR,URI="'.$keyUrl(0, $variant).'",KEYFORMAT="identity",KEYFORMATVERSIONS="1"';
            }

            $lines[] = '#EXT-X-MAP:URI="'.$map.'"';
        }

        $lines = [...$lines, ...$this->dateRanges()];
        $period = null;

        foreach ($segments as $segment) {
            if ($map === null && $keyUrl !== null && $period !== $this->period($segment->index)) {
                $period = $this->period($segment->index);

                $lines[] = '#EXT-X-KEY:METHOD=AES-128,URI="'.$keyUrl($period, $variant).'"';
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
     * @param  (callable(int): string)|null  $thumbnailUrl  Receives a sprite sheet's index and returns its URL. Required with thumbnails.
     *
     * @throws InvalidArgumentException
     */
    public function dashManifest(callable $initUrl, callable $segmentUrl, ?callable $subtitleUrl = null, ?callable $thumbnailUrl = null): string
    {
        $this->ensureOneKey();

        if ($this->subtitles() !== [] && $subtitleUrl === null) {
            throw new InvalidArgumentException('Streams with subtitles need the URLs of their WebVTT files.');
        }

        $duration = $this->duration();
        $sets = [];

        if (($videos = $this->videoVariants()) !== []) {
            foreach ($videos as $variant) {
                $this->ensureEncryptable($variant, Track::Video);
            }

            $representations = array_map(fn (int $variant): string => $this->representation($variant, Track::Video, $initUrl, $segmentUrl), $videos);

            $sets[] = implode("\n", ['    <AdaptationSet id="0" contentType="video" mimeType="video/mp4" startWithSAP="1">', ...$this->contentProtection(), ...$representations, '    </AdaptationSet>']);
        }

        if (($audio = $this->audioVariant()) !== null) {
            $this->ensureEncryptable($audio, Track::Audio);

            $language = $this->probe($audio)->audioStream()?->language;
            $lang = $language !== null && $language !== 'und' ? ' lang="'.$this->xml($language).'"' : '';

            $sets[] = implode("\n", ['    <AdaptationSet id="1" contentType="audio" mimeType="audio/mp4"'.$lang.' startWithSAP="1">', ...$this->contentProtection(), $this->representation($audio, Track::Audio, $initUrl, $segmentUrl), '    </AdaptationSet>']);
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

        if ($this->thumbnails !== null) {
            $sets[] = $this->thumbnailAdaptationSet(
                $this->thumbnails,
                count($this->subtitles()) + 2,
                $thumbnailUrl ?? throw new InvalidArgumentException('Streams with thumbnails need the URLs of their sprite sheets.'),
            );
        }

        return implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011"'.($this->keys !== null ? ' xmlns:cenc="urn:mpeg:cenc:2013" xmlns:dashif="https://dashif.org/CPS"' : '').' profiles="urn:mpeg:dash:profile:isoff-main:2011" type="static" mediaPresentationDuration="'.$this->isoDuration($duration).'" minBufferTime="'.$this->isoDuration($this->targetDuration()).'">',
            '  <Period id="0" start="PT0S">',
            ...$this->eventStreams(),
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
        if ($this->keys !== null) {
            $content = $this->initSegmentContents($variant, $track);

            $this->packageAhead($variant, 1, $track);

            return $this->encryptedResponse($content, $track->contentType());
        }

        $path = $this->initSegment($variant, $track);

        $this->packageAhead($variant, 1, $track);

        return $this->fileResponse($path, $track->contentType());
    }

    /**
     * The bytes of a track's initialization segment, marked as encrypted when the stream is.
     *
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function initSegmentContents(int $variant, Track $track): string
    {
        if ($this->keys !== null) {
            $this->ensureOneKey();
            $this->ensureEncryptable($variant, $track);
        }

        $content = $this->cachedInitSegment($variant, $track);

        return $this->keys !== null ? CommonEncryption::init($content, $this->key()) : $content;
    }

    /**
     * A response for a segment: a redirect to a temporary URL when the cache disk provides them
     * (e.g. S3), otherwise the file itself. Encrypted streams always respond with the segment,
     * encrypted with the key of its rotation period, or with Common Encryption when fragmented.
     *
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function segmentResponse(int $variant, int $index, ?Track $track = null): Response
    {
        $contentType = $track?->contentType() ?? 'video/mp2t';

        if ($this->keys !== null) {
            $content = $this->segmentContents($variant, $index, $track);

            $this->packageAhead($variant, $index + 1, $track);

            return $this->encryptedResponse($content, $contentType);
        }

        $path = $this->segment($variant, $index, $track);

        $this->packageAhead($variant, $index + 1, $track);

        return $this->fileResponse($path, $contentType);
    }

    /**
     * The bytes of a segment, encrypted when the stream is: with AES-128 for MPEG-TS, or with Common
     * Encryption for a fragmented track.
     *
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    public function segmentContents(int $variant, int $index, ?Track $track = null): string
    {
        if ($this->keys !== null && $track !== null) {
            $this->ensureOneKey();
            $this->ensureEncryptable($variant, $track);
        }

        $content = (string) $this->cacheDisk()->get($this->segment($variant, $index, $track));

        if ($this->keys === null) {
            return $content;
        }

        return $track !== null
            ? CommonEncryption::segment($this->cachedInitSegment($variant, $track), $content, $this->key(), "{$variant}|{$track->value}|{$index}")
            : $this->encrypt($content, $index);
    }

    /**
     * @throws SegmentNotFoundException
     * @throws InvalidMediaException
     */
    protected function cachedInitSegment(int $variant, Track $track): string
    {
        return (string) $this->cacheDisk()->get($this->initSegment($variant, $track));
    }

    protected function encryptedResponse(string $content, string $contentType): Response
    {
        return new Response($content, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age='.Config::integer('media.delivery.url_lifetime', 3600),
        ]);
    }

    /**
     * Package the look-ahead number of segments from the given one that aren't cached yet, in a
     * queued PackageSegments job or after the response. Streams whose disks can't be named in a
     * job, or whose queue runs synchronously, use the response instead.
     *
     * @throws SegmentNotFoundException
     */
    public function packageAhead(int $variant, int $from, ?Track $track = null): void
    {
        $count = $this->lookAhead ?? Config::integer('media.delivery.look_ahead', 0);
        $strategy = $this->lookAheadStrategy ?? LookAheadStrategy::tryFrom((string) Config::get('media.delivery.look_ahead_via'));

        if ($count < 1 || $strategy === null) {
            return;
        }

        $media = $this->media($variant);
        $missing = [];

        foreach (array_slice($this->segments($variant), max(0, $from), $count) as $segment) {
            if (! $this->cacheDisk()->exists($this->segmentPath($media, $segment, $track))) {
                $missing[] = $segment->index;
            }
        }

        if ($missing === []) {
            return;
        }

        if ($strategy === LookAheadStrategy::Queue && $this->canQueue($media)) {
            $connection = Config::get('media.delivery.look_ahead_connection');
            $queue = Config::get('media.delivery.look_ahead_queue');

            Bus::dispatch(
                new PackageSegments($media->disk()->name(), $media->path(), $missing, $track, $this->targetDuration(), $this->cacheDisk()->name())
                    ->onConnection(is_string($connection) ? $connection : null)
                    ->onQueue(is_string($queue) ? $queue : null),
            );

            return;
        }

        defer(function () use ($variant, $missing, $track): void {
            foreach ($missing as $index) {
                try {
                    $this->segment($variant, $index, $track);
                } catch (Throwable $exception) {
                    report($exception);

                    return;
                }
            }
        });
    }

    /**
     * Package the first look-ahead segments of every track in the DASH manifest, so playback can
     * start from the cache.
     *
     * @throws SegmentNotFoundException
     */
    public function packageStart(): void
    {
        foreach ($this->videoVariants() as $variant) {
            $this->packageAhead($variant, 0, Track::Video);
        }

        if (($audio = $this->audioVariant()) !== null) {
            $this->packageAhead($audio, 0, Track::Audio);
        }
    }

    /**
     * Whether a job can open the media and the cache disk by name, on a queue that doesn't run
     * the job straight away.
     */
    protected function canQueue(Media $media): bool
    {
        $connection = Config::get('media.delivery.look_ahead_connection') ?? Config::get('queue.default');

        return Config::has('filesystems.disks.'.$media->disk()->name())
            && Config::has('filesystems.disks.'.$this->cacheDisk()->name())
            && is_string($connection)
            && Config::get("queue.connections.{$connection}.driver") !== 'sync';
    }

    protected function fileResponse(string $path, string $contentType, ?Disk $disk = null): Response
    {
        $disk ??= $this->cacheDisk();
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

            // Every fragment of a track carries the same initialization segment, so it's written once.
            if (! $this->cacheDisk()->exists($this->initPath($media, $track))) {
                file_put_contents($directory->path('init.mp4'), $parts['init']);
            }

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
     * Anchors the playlist at the epoch, so marker dates map to seconds of the stream. Only added with
     * markers, since #EXT-X-DATERANGE needs it.
     *
     * @return list<string>
     */
    protected function programDateTime(): array
    {
        return $this->markers() !== [] ? ['#EXT-X-PROGRAM-DATE-TIME:'.$this->date(0.0)] : [];
    }

    /**
     * The #EXT-X-DATERANGE lines of the markers, after the #EXT-X-PROGRAM-DATE-TIME they're relative to.
     *
     * @return list<string>
     */
    protected function dateRanges(): array
    {
        $lines = $this->programDateTime();

        foreach ($this->markers() as $index => $marker) {
            $attributes = [
                'ID' => '"'.$this->quoted("{$marker->class}-{$index}").'"',
                'CLASS' => '"'.$this->quoted($marker->class).'"',
                'START-DATE' => '"'.$this->date($marker->start).'"',
                'DURATION' => $marker->duration() !== null ? number_format($marker->duration(), 3, '.', '') : null,
                'X-TITLE' => $marker->title !== null ? '"'.$this->quoted($marker->title).'"' : null,
            ];

            $attributes = array_filter($attributes, fn (?string $value): bool => $value !== null);

            $lines[] = '#EXT-X-DATERANGE:'.implode(',', array_map(fn (string $key, string $value): string => "{$key}={$value}", array_keys($attributes), $attributes));
        }

        return $lines;
    }

    /**
     * One EventStream per marker class, with the times in milliseconds from the start of the Period.
     *
     * @return list<string>
     */
    protected function eventStreams(): array
    {
        $classes = [];

        foreach ($this->markers() as $index => $marker) {
            $duration = $marker->duration() !== null ? ' duration="'.(int) round($marker->duration() * 1000).'"' : '';
            $event = '      <Event id="'.$index.'" presentationTime="'.(int) round($marker->start * 1000).'"'.$duration;

            $classes[$marker->class][] = $marker->title !== null ? $event.'>'.$this->xml($marker->title).'</Event>' : $event.'/>';
        }

        $lines = [];

        foreach ($classes as $class => $events) {
            $lines[] = '    <EventStream schemeIdUri="'.Marker::SCHEME.'" value="'.$this->xml((string) $class).'" timescale="1000">';
            $lines = [...$lines, ...$events];
            $lines[] = '    </EventStream>';
        }

        return $lines;
    }

    /**
     * An ISO 8601 date the given seconds after the epoch.
     */
    protected function date(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);

        return gmdate('Y-m-d\\TH:i:s', intdiv($milliseconds, 1000)).sprintf('.%03dZ', $milliseconds % 1000);
    }

    /**
     * A value for a quoted-string attribute, which can't hold double quotes or line breaks.
     */
    protected function quoted(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $value);
    }

    /**
     * The #EXT-X-IMAGE-STREAM-INF line of the thumbnails, with the size of a whole sheet.
     *
     * @param  (callable(): string)|null  $thumbnailUrl
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    protected function imageStream(?callable $thumbnailUrl): array
    {
        if ($this->thumbnails === null) {
            return [];
        }

        if ($thumbnailUrl === null) {
            throw new InvalidArgumentException('Streams with thumbnails need the URL of their image playlist.');
        }

        $thumbnails = $this->thumbnails;

        return [sprintf(
            '#EXT-X-IMAGE-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%dx%d,CODECS="%s",URI="%s"',
            $this->thumbnailBandwidth($thumbnails),
            $thumbnails->width * $thumbnails->columns,
            $thumbnails->height * $thumbnails->rows,
            $thumbnails->extension() === 'webp' ? 'webp' : 'jpeg',
            $thumbnailUrl(),
        )];
    }

    /**
     * A DASH image adaptation set with the DASH-IF thumbnail tile property, listing every sheet.
     *
     * @param  callable(int): string  $thumbnailUrl
     */
    protected function thumbnailAdaptationSet(ThumbnailsResult $thumbnails, int $id, callable $thumbnailUrl): string
    {
        $timeline = [];
        $start = 0;

        foreach (array_keys($thumbnails->sprites) as $sheet) {
            $duration = (int) round($thumbnails->sheetDuration($sheet) * 1000);
            $last = array_key_last($timeline);

            if ($last !== null && $timeline[$last]['d'] === $duration) {
                $timeline[$last]['r']++;
            } else {
                $timeline[] = ['t' => $start, 'd' => $duration, 'r' => 0];
            }

            $start += $duration;
        }

        return implode("\n", [
            '    <AdaptationSet id="'.$id.'" contentType="image" mimeType="'.$this->imageType($thumbnails).'">',
            '      <Representation id="thumbnails" bandwidth="'.$this->thumbnailBandwidth($thumbnails).'" width="'.($thumbnails->width * $thumbnails->columns).'" height="'.($thumbnails->height * $thumbnails->rows).'">',
            '        <EssentialProperty schemeIdUri="http://dashif.org/thumbnail_tile" value="'.$thumbnails->columns.'x'.$thumbnails->rows.'"/>',
            '        <SegmentList timescale="1000">',
            '          <SegmentTimeline>',
            ...array_map(fn (array $s): string => '            <S t="'.$s['t'].'" d="'.$s['d'].'"'.($s['r'] > 0 ? ' r="'.$s['r'].'"' : '').'/>', $timeline),
            '          </SegmentTimeline>',
            ...array_map(fn (int $sheet): string => '          <SegmentURL media="'.$this->xml($thumbnailUrl($sheet)).'"/>', array_keys($thumbnails->sprites)),
            '        </SegmentList>',
            '      </Representation>',
            '    </AdaptationSet>',
        ]);
    }

    /**
     * An estimate of the sheets' bandwidth in bits per second, at about half a bit per pixel.
     */
    protected function thumbnailBandwidth(ThumbnailsResult $thumbnails): int
    {
        $bits = $thumbnails->width * $thumbnails->height * $thumbnails->perSheet() / 2;

        return (int) ceil($bits / max(1.0, $thumbnails->perSheet() * $thumbnails->interval));
    }

    protected function imageType(ThumbnailsResult $thumbnails): string
    {
        return $thumbnails->extension() === 'webp' ? 'image/webp' : 'image/jpeg';
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
     * Fragmented MP4 segments name their key in the initialization segment, which every segment of a track shares.
     *
     * @throws InvalidArgumentException
     */
    protected function ensureOneKey(): void
    {
        if ($this->keys !== null && $this->rotateEvery !== null) {
            throw new InvalidArgumentException('Fragmented MP4 and DASH streams are encrypted with one key. Rotate keys with MPEG-TS segments only.');
        }
    }

    /**
     * @throws InvalidMediaException
     */
    protected function ensureEncryptable(int $variant, Track $track): void
    {
        $probe = $this->probe($variant);
        $codec = ($track === Track::Video ? $probe->videoStream() : $probe->audioStream())?->codecName;

        if ($this->keys !== null && FragmentedMp4Codec::tryFrom((string) $codec)?->isEncryptable() === false) {
            throw InvalidMediaException::notEncryptable((string) $codec);
        }
    }

    /**
     * The ContentProtection descriptors of an encrypted adaptation set: Common Encryption with the key
     * ID, and ClearKey with the license URL when there is one. Without it, players need the key in
     * their ClearKey configuration.
     *
     * @return list<string>
     */
    protected function contentProtection(): array
    {
        if ($this->keys === null) {
            return [];
        }

        $clearKey = '      <ContentProtection schemeIdUri="urn:uuid:e2719d58-a985-b3c9-781a-b030af78d30e" value="ClearKey1.0"';

        return [
            '      <ContentProtection schemeIdUri="urn:mpeg:dash:mp4protection:2011" value="cenc" cenc:default_KID="'.$this->key()->keyIdUuid().'"/>',
            ...($this->licenseUrl !== null
                ? [$clearKey.'>', '        <dashif:Laurl>'.$this->xml(($this->licenseUrl)()).'</dashif:Laurl>', '      </ContentProtection>']
                : [$clearKey.'/>']),
        ];
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
        if ($this->tracks !== null) {
            return $this->tracks['video'];
        }

        return array_values(array_filter(array_keys($this->opener->paths()), fn (int $variant): bool => $this->probe($variant)->hasVideo()));
    }

    /**
     * The first variant with audio, whose audio track all video tracks of a fragmented stream share.
     */
    protected function audioVariant(): ?int
    {
        if ($this->tracks !== null) {
            return $this->tracks['audio'];
        }

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
