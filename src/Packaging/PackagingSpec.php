<?php

declare(strict_types=1);

namespace Foxws\Media\Packaging;

/**
 * What to package, independent of the packager that does it.
 */
final readonly class PackagingSpec
{
    /**
     * @param  list<PackagingStream>  $streams
     * @param  string|null  $dashManifest  The DASH manifest, relative to the export directory.
     * @param  string|null  $hlsPlaylist  The HLS master playlist, relative to the export directory.
     * @param  array<string, string|int|float|bool|null>  $options  Extra driver-specific options, e.g. ['low_latency_dash_mode' => true].
     */
    public function __construct(
        public array $streams,
        public ?string $dashManifest = null,
        public ?string $hlsPlaylist = null,
        public ?HlsPlaylistType $hlsPlaylistType = null,
        public ?float $segmentDuration = null,
        public ?float $fragmentDuration = null,
        public ?string $defaultLanguage = null,
        public ?string $defaultTextLanguage = null,
        public bool $allowCodecSwitching = false,
        public bool $approximateSegmentTimeline = false,
        public array $options = [],
        public ?Encryption $encryption = null,
    ) {}

    /**
     * The manifests the export writes, HLS first.
     *
     * @return list<string>
     */
    public function manifests(): array
    {
        return array_values(array_filter([$this->hlsPlaylist, $this->dashManifest]));
    }
}
