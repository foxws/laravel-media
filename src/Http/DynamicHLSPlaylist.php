<?php

declare(strict_types=1);

namespace Foxws\Media\Http;

/**
 * Rewrites an HLS playlist per request: media playlists, segments, init segments and
 * encryption keys can each point to their own (signed) URL.
 */
class DynamicHLSPlaylist extends Manifest
{
    /**
     * Resolve encryption key URIs (#EXT-X-KEY), e.g. to an authorized key route.
     *
     * @param  callable(string): string  $resolver  Receives the key's path on the disk.
     */
    public function resolveKeyUrlsUsing(callable $resolver): static
    {
        return $this->resolveUsing('key', $resolver);
    }

    /**
     * Resolve segment and init segment URIs, e.g. to temporary URLs.
     *
     * @param  callable(string): string  $resolver  Receives the segment's path on the disk.
     */
    public function resolveMediaUrlsUsing(callable $resolver): static
    {
        return $this->resolveUsing('media', $resolver);
    }

    /**
     * Resolve media playlist URIs, e.g. to a signed route that rewrites them with this class too.
     *
     * @param  callable(string): string  $resolver  Receives the playlist's path on the disk.
     */
    public function resolvePlaylistUrlsUsing(callable $resolver): static
    {
        return $this->resolveUsing('playlist', $resolver);
    }

    public function get(): string
    {
        return $this->process($this->path());
    }

    /**
     * The opened playlist and the media playlists it references, rewritten and keyed by disk path.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $playlists = [$this->path() => $this->get()];

        foreach ($this->lines($this->read($this->path())) as $line) {
            foreach ($this->playlistUris($line) as $uri) {
                $path = $this->pathFrom($this->path(), $uri);
                $playlists[$path] ??= $this->process($path);
            }
        }

        return $playlists;
    }

    /**
     * Rewrite the playlist at the path on the disk.
     */
    public function process(string $path): string
    {
        return implode("\n", array_map(fn (string $line): string => $this->rewrite($line, $path), $this->lines($this->read($path))));
    }

    protected function contentType(): string
    {
        return 'application/vnd.apple.mpegurl';
    }

    protected function rewrite(string $line, string $from): string
    {
        if ($line === '') {
            return $line;
        }

        if ($line[0] !== '#') {
            return $this->resolve(str_ends_with(strtok($line, '?') ?: $line, '.m3u8') ? 'playlist' : 'media', $line, $from);
        }

        $type = match (true) {
            str_starts_with($line, '#EXT-X-KEY:'), str_starts_with($line, '#EXT-X-SESSION-KEY:') => 'key',
            str_starts_with($line, '#EXT-X-MEDIA:'), str_starts_with($line, '#EXT-X-I-FRAME-STREAM-INF:') => 'playlist',
            str_starts_with($line, '#EXT-X-MAP:') => 'media',
            default => null,
        };

        if ($type === null) {
            return $line;
        }

        return $this->replace('/URI="([^"]+)"/', fn (array $matches): string => 'URI="'.$this->resolve($type, $matches[1], $from).'"', $line);
    }

    /**
     * The media playlists a line references.
     *
     * @return list<string>
     */
    protected function playlistUris(string $line): array
    {
        if ($line !== '' && $line[0] !== '#') {
            return str_ends_with(strtok($line, '?') ?: $line, '.m3u8') ? [$line] : [];
        }

        if ((str_starts_with($line, '#EXT-X-MEDIA:') || str_starts_with($line, '#EXT-X-I-FRAME-STREAM-INF:')) && preg_match('/URI="([^"]+)"/', $line, $matches) === 1) {
            return [$matches[1]];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    protected function lines(string $content): array
    {
        return preg_split('/\r\n|\r|\n/', $content) ?: [];
    }
}
