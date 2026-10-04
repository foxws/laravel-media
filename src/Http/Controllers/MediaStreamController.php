<?php

declare(strict_types=1);

namespace Foxws\Media\Http\Controllers;

use Foxws\Media\Delivery\DirectStream;
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
 * DASH with the same fragmented MP4 segments, and the keys of encrypted MPEG-TS streams.
 */
class MediaStreamController
{
    protected const array STREAM_PARAMETERS = ['mediaStream', 'variant', 'track', 'segment', 'period'];

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
            fn (int $variant, ?Track $track): string => $track !== null
                ? $this->url($request, $definition, 'track-playlist', ['variant' => $variant, 'track' => $track->value])
                : $this->url($request, $definition, 'playlist', ['variant' => $variant]),
        ));
    }

    /**
     * The media playlist of a variant with MPEG-TS segments.
     */
    public function playlist(Request $request): Response
    {
        [$definition, $stream] = $this->resolve($request);

        return $this->playlistResponse($stream->fragmented(false)->mediaPlaylist(
            $this->number($request, 'variant'),
            fn (Segment $segment, int $variant): string => $this->url($request, $definition, 'segment', ['variant' => $variant, 'segment' => $segment->index]),
        ));
    }

    /**
     * The media playlist of a track with fragmented MP4 segments.
     */
    public function trackPlaylist(Request $request): Response
    {
        [$definition, $stream] = $this->resolve($request);
        $track = $this->track($request);

        return $this->playlistResponse($stream->mediaPlaylist(
            $this->number($request, 'variant'),
            fn (Segment $segment, int $variant): string => $this->url($request, $definition, 'fragment', ['variant' => $variant, 'track' => $track->value, 'segment' => $segment->index]),
            $track,
            fn (int $variant, Track $track): string => $this->url($request, $definition, 'init', ['variant' => $variant, 'track' => $track->value]),
        ));
    }

    public function dash(Request $request): Response
    {
        [$definition, $stream] = $this->resolve($request);

        return new Response($stream->dashManifest(
            fn (int $variant, Track $track): string => $this->url($request, $definition, 'init', ['variant' => $variant, 'track' => $track->value]),
            fn (Segment $segment, int $variant, Track $track): string => $this->url($request, $definition, 'fragment', ['variant' => $variant, 'track' => $track->value, 'segment' => $segment->index]),
        ), 200, [
            'Content-Type' => 'application/dash+xml',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    public function init(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        return $stream->initSegmentResponse($this->number($request, 'variant'), $this->track($request));
    }

    public function fragment(Request $request): Response
    {
        [, $stream] = $this->resolve($request);

        return $stream->segmentResponse($this->number($request, 'variant'), $this->number($request, 'segment'), $this->track($request));
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
            $stream->keyUrlsUsing(fn (int $period, int $variant): string => $this->url($request, $definition, 'key', ['variant' => $variant, 'period' => $period]));
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

    protected function playlistResponse(string $playlist): Response
    {
        return new Response($playlist, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    protected function track(Request $request): Track
    {
        return Track::tryFrom($this->parameter($request, 'track')) ?? throw new NotFoundHttpException('Unknown track.');
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
