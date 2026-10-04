<?php

declare(strict_types=1);

namespace Foxws\Media\Packaging\Drivers\Native;

use Foxws\Media\Delivery\DirectStream;
use Foxws\Media\Delivery\FragmentedMp4;
use Foxws\Media\Delivery\Segment;
use Foxws\Media\Delivery\Track;
use Foxws\Media\Encryption\ProtectionScheme;
use Foxws\Media\Exceptions\InvalidMediaException;
use Foxws\Media\Exceptions\SegmentNotFoundException;
use Foxws\Media\Filesystem\TemporaryDirectory;
use Foxws\Media\MediaFactory;
use Foxws\Media\Packaging\HlsPlaylistType;
use Foxws\Media\Packaging\Packager;
use Foxws\Media\Packaging\PackagingSpec;
use Foxws\Media\Packaging\PackagingStream;
use Foxws\Media\Packaging\StreamType;
use InvalidArgumentException;

/**
 * Packages HLS (fragmented MP4) and DASH without Shaka Packager, with the same ffmpeg segmenting,
 * playlists and encryption as direct streams. Each stream's output name, without its extension, is
 * the directory of its segments: "0_video.mp4" gives 0_video/init.mp4, 0_video/{n}.m4s and the
 * 0_video.m3u8 media playlist. Segments already cut for direct play are reused from the cache disk.
 */
class NativePackager implements Packager
{
    /**
     * @throws InvalidArgumentException
     * @throws InvalidMediaException
     * @throws SegmentNotFoundException
     */
    public function package(PackagingSpec $spec, TemporaryDirectory $directory, ?int $timeout = null): void
    {
        $this->ensureSupported($spec);

        [$stream, $tracks, $texts] = $this->stream($spec);

        foreach ($tracks as ['variant' => $variant, 'track' => $track, 'base' => $base]) {
            $directory->put("{$base}/init.mp4", $stream->initSegmentContents($variant, $track));

            foreach ($stream->segments($variant) as $segment) {
                $directory->put("{$base}/{$segment->index}.m4s", $stream->segmentContents($variant, $segment->index, $track));
            }
        }

        if ($spec->hlsPlaylist !== null) {
            $this->writeHls($spec, $spec->hlsPlaylist, $stream, $tracks, $texts, $directory);
        }

        if ($spec->dashManifest !== null) {
            $this->writeDash($spec->dashManifest, $stream, $tracks, $texts, $directory);
        }
    }

    public function command(PackagingSpec $spec, string $directory): string
    {
        return 'native: ffmpeg -c copy per segment of '.implode(', ', array_map(fn (PackagingStream $stream): string => "{$stream->type->value} {$stream->media->path()}", $spec->streams))." into {$directory}";
    }

    /**
     * @param  list<array{variant: int, track: Track, base: string}>  $tracks
     * @param  list<string>  $texts  The base name of each subtitle track.
     */
    protected function writeHls(PackagingSpec $spec, string $master, DirectStream $stream, array $tracks, array $texts, TemporaryDirectory $directory): void
    {
        $bases = $this->bases($tracks);

        $directory->put($master, $stream->fragmented()->masterPlaylist(
            fn (int $variant, ?Track $track): string => $this->relative($master, $bases[$variant.':'.($track ?? Track::Video)->value].'.m3u8'),
            fn (int $subtitle): string => $this->relative($master, "{$texts[$subtitle]}.m3u8"),
        ));

        foreach ($tracks as ['variant' => $variant, 'track' => $track, 'base' => $base]) {
            $playlist = "{$base}.m3u8";

            if ($spec->encryption !== null) {
                $keyUri = $spec->encryption->keyUri ?? $this->relative($playlist, (string) $spec->encryption->keyFile);
                $stream->keyUrlsUsing(fn (): string => $keyUri);
            }

            $directory->put($playlist, $stream->mediaPlaylist(
                $variant,
                fn (Segment $segment): string => $this->relative($playlist, "{$base}/{$segment->index}.m4s"),
                $track,
                fn (): string => $this->relative($playlist, "{$base}/init.mp4"),
            ));
        }

        foreach ($texts as $subtitle => $base) {
            $directory->put("{$base}-hls.vtt", $stream->subtitleContents($subtitle, FragmentedMp4::TIMESTAMP_OFFSET));
            $directory->put("{$base}.m3u8", $stream->subtitlePlaylist($subtitle, $this->relative("{$base}.m3u8", "{$base}-hls.vtt")));
        }
    }

    /**
     * @param  list<array{variant: int, track: Track, base: string}>  $tracks
     * @param  list<string>  $texts
     */
    protected function writeDash(string $manifest, DirectStream $stream, array $tracks, array $texts, TemporaryDirectory $directory): void
    {
        $bases = $this->bases($tracks);

        foreach ($texts as $subtitle => $base) {
            $directory->put("{$base}.vtt", $stream->subtitleContents($subtitle));
        }

        $directory->put($manifest, $stream->dashManifest(
            fn (int $variant, Track $track): string => $this->relative($manifest, $bases["{$variant}:{$track->value}"].'/init.mp4'),
            fn (Segment $segment, int $variant, Track $track): string => $this->relative($manifest, $bases["{$variant}:{$track->value}"]."/{$segment->index}.m4s"),
            fn (int $subtitle): string => $this->relative($manifest, "{$texts[$subtitle]}.vtt"),
        ));
    }

    /**
     * A direct stream over the spec's files, with the variant, track and output base of every video
     * and audio stream, and the output base of every subtitle.
     *
     * @return array{DirectStream, list<array{variant: int, track: Track, base: string}>, list<string>}
     */
    protected function stream(PackagingSpec $spec): array
    {
        $byType = fn (StreamType $type): array => array_values(array_filter($spec->streams, fn (PackagingStream $stream): bool => $stream->type === $type));
        $videos = $byType(StreamType::Video);
        $audio = $byType(StreamType::Audio)[0] ?? null;
        $paths = array_values(array_unique(array_map(fn (PackagingStream $stream): string => $stream->media->path(), [...$videos, ...($audio !== null ? [$audio] : [])])));
        $variant = fn (PackagingStream $stream): int => (int) array_search($stream->media->path(), $paths, true);

        $stream = MediaFactory::make()->fromDisk(($videos[0] ?? $audio)?->media->disk() ?? throw new InvalidArgumentException('Add a video or audio stream to package.'))
            ->open($paths)
            ->stream()
            ->fragmented()
            ->lookAhead(0)
            ->tracksFrom(array_values(array_unique(array_map($variant, $videos))), $audio !== null ? $variant($audio) : null);

        if ($spec->segmentDuration !== null) {
            $stream->segmentDuration($spec->segmentDuration);
        }

        if ($spec->encryption !== null) {
            $stream->withEncryption($spec->encryption->key);
        }

        $tracks = [
            ...array_map(fn (PackagingStream $video): array => ['variant' => $variant($video), 'track' => Track::Video, 'base' => $this->base($video)], $videos),
            ...($audio !== null ? [['variant' => $variant($audio), 'track' => Track::Audio, 'base' => $this->base($audio)]] : []),
        ];

        $texts = [];

        foreach ($byType(StreamType::Text) as $text) {
            $stream->withSubtitles($text->media->path(), $text->language, disk: $text->media->disk());
            $texts[] = $this->base($text);
        }

        return [$stream, $tracks, $texts];
    }

    /**
     * @param  list<array{variant: int, track: Track, base: string}>  $tracks
     * @return array<string, string>
     */
    protected function bases(array $tracks): array
    {
        return array_column(array_map(fn (array $track): array => ['key' => "{$track['variant']}:{$track['track']->value}", 'base' => $track['base']], $tracks), 'base', 'key');
    }

    /**
     * The stream's output without its extension, where its segments are written.
     */
    protected function base(PackagingStream $stream): string
    {
        $directory = dirname($stream->output);

        return ($directory !== '.' ? "{$directory}/" : '').pathinfo($stream->output, PATHINFO_FILENAME);
    }

    /**
     * The path of a file relative to the file that links to it, both relative to the export directory.
     */
    protected function relative(string $from, string $to): string
    {
        $fromParts = array_values(array_filter(explode('/', dirname($from)), fn (string $part): bool => $part !== '.' && $part !== ''));
        $toParts = explode('/', $to);

        while ($fromParts !== [] && count($toParts) > 1 && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return str_repeat('../', count($fromParts)).implode('/', $toParts);
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function ensureSupported(PackagingSpec $spec): void
    {
        $encryption = $spec->encryption;

        $unsupported = array_filter([
            'driver options ['.implode(', ', array_keys($spec->options)).']' => $spec->options !== [],
            'stream options' => array_filter($spec->streams, fn (PackagingStream $stream): bool => $stream->options !== []) !== [],
            'live playlists' => ! in_array($spec->hlsPlaylistType, [null, HlsPlaylistType::Vod], true),
            'protection schemes other than cenc' => ! in_array($encryption?->scheme, [null, ProtectionScheme::Cenc], true),
            'key rotation' => $encryption?->rotation !== null,
            'a clear lead' => $encryption !== null && $encryption->clearLead > 0,
            'more than one audio stream' => count(array_filter($spec->streams, fn (PackagingStream $stream): bool => $stream->type === StreamType::Audio)) > 1,
            'HLS keys without a key file or key URI' => $encryption !== null && $spec->hlsPlaylist !== null && $encryption->keyUri() === null,
        ]);

        if ($unsupported !== []) {
            throw new InvalidArgumentException('The native packager does not support '.implode(', ', array_keys($unsupported)).'. Package with the shaka driver of foxws/laravel-shaka instead.');
        }
    }
}
