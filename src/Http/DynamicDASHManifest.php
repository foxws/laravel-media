<?php

declare(strict_types=1);

namespace Foxws\Media\Http;

/**
 * Rewrites a DASH manifest per request so segments and init segments can point to their own
 * (signed) URL. Segment templates with $Number$ are expanded into segment lists, because a
 * template can't produce a different signed URL for every segment.
 */
class DynamicDASHManifest extends Manifest
{
    /**
     * Resolve segment and BaseURL URIs, e.g. to temporary URLs.
     *
     * @param  callable(string): string  $resolver  Receives the file's path on the disk.
     */
    public function resolveMediaUrlsUsing(callable $resolver): static
    {
        return $this->resolveUsing('media', $resolver);
    }

    /**
     * Resolve init segment URIs, e.g. to temporary URLs. Falls back to the media resolver.
     *
     * @param  callable(string): string  $resolver  Receives the file's path on the disk.
     */
    public function resolveInitUrlsUsing(callable $resolver): static
    {
        return $this->resolveUsing('init', $resolver);
    }

    public function get(): string
    {
        $manifest = $this->rewrite($this->expandSegmentTemplates($this->read($this->path())));

        return str_starts_with(ltrim($manifest), '<?xml') ? $manifest : '<?xml version="1.0" encoding="UTF-8"?>'."\n".$manifest;
    }

    protected function contentType(): string
    {
        return 'application/dash+xml';
    }

    protected function rewrite(string $manifest): string
    {
        $manifest = $this->replace('/<BaseURL>([^<]+)<\/BaseURL>/', fn (array $matches): string => '<BaseURL>'.$this->url('media', $matches[1]).'</BaseURL>', $manifest);

        $manifest = $this->replace('/\b(initialization|sourceURL)="([^"]+)"/', fn (array $matches): string => $matches[1].'="'.$this->url(isset($this->resolvers['init']) ? 'init' : 'media', $matches[2]).'"', $manifest);

        return $this->replace('/\bmedia="([^"]+)"/', fn (array $matches): string => 'media="'.$this->url('media', $matches[1]).'"', $manifest);
    }

    /**
     * Resolve an XML-escaped URI and escape the result again, so signed query strings stay valid.
     */
    protected function url(string $type, string $escaped): string
    {
        $uri = html_entity_decode($escaped, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        if (str_contains($uri, '$')) {
            return $escaped;
        }

        return htmlspecialchars($this->resolve($type, $uri), ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /**
     * Turn segment templates with $Number$ and a timeline into explicit segment lists.
     */
    protected function expandSegmentTemplates(string $manifest): string
    {
        return $this->replace('/<SegmentTemplate\s+([^>]*?)\s*(?:\/>|>(.*?)<\/SegmentTemplate>)/s', function (array $matches): string {
            $attributes = $matches[1];
            $timeline = preg_match('/<SegmentTimeline>.*?<\/SegmentTimeline>/s', $matches[2] ?? '', $timelineMatch) === 1 ? $timelineMatch[0] : null;
            $media = preg_match('/\bmedia="([^"]+)"/', $attributes, $mediaMatch) === 1 ? $mediaMatch[1] : '';

            if ($timeline === null || ! str_contains($media, '$Number$')) {
                return $matches[0];
            }

            $timescale = preg_match('/\btimescale="(\d+)"/', $attributes, $match) === 1 ? $match[1] : '1';
            $initialization = preg_match('/\binitialization="([^"]+)"/', $attributes, $match) === 1 ? $match[1] : null;
            $number = preg_match('/\bstartNumber="(\d+)"/', $attributes, $match) === 1 ? (int) $match[1] : 1;

            $list = "<SegmentList timescale=\"{$timescale}\">";
            $list .= $initialization !== null ? "<Initialization sourceURL=\"{$initialization}\"/>" : '';
            $list .= $timeline;

            preg_match_all('/<S\s+([^>]*?)\/?>/', $timeline, $segments);

            foreach ($segments[1] as $segment) {
                $duration = preg_match('/\bd="(\d+)"/', $segment, $match) === 1 ? $match[1] : null;
                $repeat = preg_match('/\br="(-?\d+)"/', $segment, $match) === 1 ? max(0, (int) $match[1]) : 0;

                if ($duration === null) {
                    continue;
                }

                for ($index = 0; $index <= $repeat; $index++) {
                    $list .= '<SegmentURL media="'.str_replace('$Number$', (string) $number++, $media)."\" duration=\"{$duration}\"/>";
                }
            }

            return $list.'</SegmentList>';
        }, $manifest);
    }
}
