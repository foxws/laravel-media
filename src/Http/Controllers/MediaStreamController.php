<?php

declare(strict_types=1);

namespace Foxws\Media\Http\Controllers;

use Foxws\Media\Delivery\DirectStream;
use Foxws\Media\Delivery\FragmentedMp4;
use Foxws\Media\Delivery\Segment;
use Foxws\Media\Delivery\StreamDefinition;
use Foxws\Media\Delivery\StreamRegistry;
use Foxws\Media\Delivery\Track;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the routes of Route::mediaStream(): HLS with fragmented MP4 (CMAF) or MPEG-TS segments,
 * DASH with the same fragmented MP4 segments, WebVTT subtitles and chapters, thumbnail sprite sheets, and the keys and
 * ClearKey license of encrypted streams.
 */
class MediaStreamController
{
    protected const array STREAM_PARAMETERS = ['mediaStream', 'variant', 'track', 'segment', 'period', 'subtitle', 'format', 'sheet', 'extension'];

    public function __construct(protected StreamRegistry $streams) {}

    /**
     * The HLS master playlist with fragmented MP4 (CMAF) segments, shared with the DASH manifest.
     */
    public function cmaf(Request $request): Response
    {
        return $this->masterPlaylist($request, fragmented: true);
    }

    /**
     * The HLS master playlist with MPEG-TS segments.
     */
    public function hls(Request $request): Response
    {
        return $this->masterPlaylist($request, fragmented: false);
    }

    protected function masterPlaylist(Request $request, bool $fragmented): Response
    {
        [$definition, $stream] = $this->resolve($request);

        return $this->playlistResponse($stream->fragmented($fragmented)->masterPlaylist(
            fn (int $variant, ?Track $track, int $stream): string => $track !== null
                ? $this->url($request, $definition, 'track-playlist', ['variant' => $variant, 'track' => $track->name($stream)])
                : $this->url($request, $definition, 'playlist', ['variant' => $variant]),
            fn (int $subtitle): string => $this->url($request, $definition, 'subtitle-playlist', ['subtitle' => $subtitle, 'format' => $fragmented ? 'cmaf' : 'hls']),
            fn (): string => $this->url($request, $definition, 'thumbnail-playlist', []),
        ));
    }

    /**
     * The media playlist of a variant with MPEG-TS segments.
     */
    public function playlist(Request $request): Response
    {
        [$definition, $stream] = $this->resolve($request);
        $variant = $this->number($request, 'variant');

        $response = $this->playlistResponse($stream->fragmented(false)->mediaPlaylist(
            $variant,
            fn (Segment $segment, int $variant): string => $this->url($request, $definition, 'segment', ['variant' => $variant, 'segment' => $segment->index]),
        ));

        $stream->packageAhead($variant, 0);

        return $response;
    }

    /**
     * The media playlist of a track with fragmented MP4 segments.
     */
    public function trackPlaylist(Request $request): Response
    {
        [$definition, $stream] = $this->resolve($request);
        [$track, $position] = $this->track($request);
        $variant = $this->number($request, 'variant');

        $response = $this->playlistResponse($stream->mediaPlaylist(
            $variant,
            fn (Segment $segment, int $variant): string => $this->url($request, $definition, 'fragment', ['variant' => $variant, 'track' => $track->name($position), 'segment' => $segment->index]),
            $track,
            fn (int $variant, Track $track): string => $this->url($request, $definition, 'init', ['variant' => $variant, 'track' => $track->name($position)]),
            $position,
        ));

        $stream->packageAhead($variant, 0, $track, $position);

        return $response;
    }

    public function dash(Request $request): Response
    {
        [$definition, $stream] = $this->resolve($request);

        $response = new Response($stream->dashManifest(
            fn (int $variant, Track $track, int $stream): string => $this->url($request, $definition, 'init', ['variant' => $variant, 'track' => $track->name($stream)]),
            fn (Segment $segment, int $variant, Track $track, int $stream): string => $this->url($request, $definition, 'fragment', ['variant' => $variant, 'track' => $track->name($stream), 'segment' => $segment->index]),
            fn (int $subtitle): string => $this->url($request, $definition, 'subtitle', ['subtitle' => $subtitle]),
            fn (int $sheet): string => $this->thumbnailUrl($request, $definition, $stream, $sheet),
        ), 200, [
            'Content-Type' => 'application/dash+xml',
            'Cache-Control' => 'private, no-cache',
        ]);

        $stream->packageStart();

        return $response;
    }

    /**
     * The HLS media playlist of a subtitle track, for CMAF or MPEG-TS playlists.
     */
    public function subtitlePlaylist(Request $request): Response
    {
        [$definition, $stream] = $this->resolve($request);
        $subtitle = $this->number($request, 'subtitle');

        return $this->playlistResponse($stream->subtitlePlaylist(
            $subtitle,
            $this->url($request, $definition, 'hls-subtitle', ['subtitle' => $subtitle, 'format' => $this->parameter($request, 'format')]),
        ));
    }

    /**
     * A subtitle track for HLS, mapped onto the timestamps of the CMAF or MPEG-TS segments.
     */
    public function hlsSubtitle(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        return $stream->subtitleResponse(
            $this->number($request, 'subtitle'),
            $this->parameter($request, 'format') === 'cmaf' ? FragmentedMp4::TIMESTAMP_OFFSET : 0,
        );
    }

    /**
     * A subtitle track for DASH, timed from the start of the presentation.
     */
    public function subtitle(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        return $stream->subtitleResponse($this->number($request, 'subtitle'));
    }

    /**
     * The HLS image playlist of the thumbnails.
     */
    public function thumbnailPlaylist(Request $request): Response
    {
        [$definition, $stream] = $this->resolve($request);

        return $this->playlistResponse($stream->thumbnailPlaylist(
            fn (int $sheet): string => $this->thumbnailUrl($request, $definition, $stream, $sheet),
        ));
    }

    public function thumbnail(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        return $stream->thumbnailResponse($this->number($request, 'sheet'));
    }

    /**
     * The chapters as a WebVTT track.
     */
    public function chapters(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        return $stream->chapterTrackResponse();
    }

    public function init(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        [$track, $position] = $this->track($request);

        return $stream->initSegmentResponse($this->number($request, 'variant'), $track, $position);
    }

    public function fragment(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        [$track, $position] = $this->track($request);

        return $stream->segmentResponse($this->number($request, 'variant'), $this->number($request, 'segment'), $track, $position);
    }

    public function segment(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        return $stream->segmentResponse($this->number($request, 'variant'), $this->number($request, 'segment'));
    }

    public function key(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        if (! $stream->isEncrypted()) {
            throw new NotFoundHttpException('This stream is not encrypted.');
        }

        return $stream->keyResponse($this->number($request, 'period'));
    }

    /**
     * The ClearKey license DASH players request for encrypted fragmented MP4 segments.
     */
    public function license(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        if (! $stream->isEncrypted()) {
            throw new NotFoundHttpException('This stream is not encrypted.');
        }

        return $stream->licenseResponse();
    }

    /**
     * @return array{StreamDefinition, DirectStream}
     */
    protected function resolve(Request $request): array
    {
        $route = $this->route($request);
        $definition = $this->streams->definition($this->parameter($request, 'mediaStream'));

        if ($definition->isSigned() && ! $request->hasValidSignature()) {
            throw new InvalidSignatureException;
        }

        $stream = $definition->resolve(Arr::except($route->parameters(), self::STREAM_PARAMETERS));

        if ($stream->isEncrypted()) {
            $stream->keyUrlsUsing(fn (int $period, int $variant): string => $this->url($request, $definition, 'key', ['variant' => $variant, 'period' => $period]))
                ->licenseUrlUsing(fn (): string => $this->url($request, $definition, 'license', []));
        }

        return [$definition, $stream];
    }

    /**
     * The URL of a sibling route of the same stream, with the request's own route parameters.
     *
     * @param  array<string, int|string>  $parameters
     */
    protected function url(Request $request, StreamDefinition $definition, string $name, array $parameters): string
    {
        $route = $this->route($request);
        $name = Str::beforeLast((string) $route->getName(), '.').'.'.$name;
        $parameters = [...Arr::except($route->originalParameters(), self::STREAM_PARAMETERS), ...$parameters];

        return $definition->isSigned()
            ? URL::temporarySignedRoute($name, now()->addSeconds($definition->lifetime()), $parameters)
            : URL::route($name, $parameters);
    }

    protected function thumbnailUrl(Request $request, StreamDefinition $definition, DirectStream $stream, int $sheet): string
    {
        return $this->url($request, $definition, 'thumbnail', ['sheet' => $sheet, 'extension' => $stream->thumbnails()?->extension() ?? 'jpg']);
    }

    protected function playlistResponse(string $playlist): Response
    {
        return new Response($playlist, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /**
     * The track and the position of its stream, from a track name like "audio" or "audio-1".
     *
     * @return array{Track, int}
     */
    protected function track(Request $request): array
    {
        if (preg_match('/^([a-z]+)(?:-([1-9]\d*))?$/', $this->parameter($request, 'track'), $matches) !== 1 || ($track = Track::tryFrom($matches[1])) === null) {
            throw new NotFoundHttpException('Unknown track.');
        }

        return [$track, (int) ($matches[2] ?? 0)];
    }

    protected function number(Request $request, string $parameter): int
    {
        return (int) $this->parameter($request, $parameter);
    }

    /**
     * A route parameter as matched from the URI, or one of the route's defaults.
     */
    protected function parameter(Request $request, string $parameter): string
    {
        $value = $this->route($request)->parameter($parameter);

        return is_string($value) ? $value : throw new NotFoundHttpException("The [{$parameter}] route parameter is missing.");
    }

    protected function route(Request $request): Route
    {
        /** @var Route */
        return $request->route();
    }
}
