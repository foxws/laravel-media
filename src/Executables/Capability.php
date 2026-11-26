<?php

declare(strict_types=1);

namespace Foxws\Media\Executables;

/**
 * A kind of component ffmpeg can be built with, listed by one of its "-encoders", "-filters"... options.
 */
enum Capability: string
{
    case Encoders = 'encoders';
    case Decoders = 'decoders';
    case Muxers = 'muxers';
    case Demuxers = 'demuxers';
    case Filters = 'filters';
    case Protocols = 'protocols';
    case BitstreamFilters = 'bsfs';
    case HardwareAccelerations = 'hwaccels';

    /**
     * The ffmpeg option that lists the components of this kind.
     */
    public function argument(): string
    {
        return "-{$this->value}";
    }

    /**
     * The name of one component of this kind, as used by the media:doctor options.
     */
    public function singular(): string
    {
        return match ($this) {
            self::Encoders => 'encoder',
            self::Decoders => 'decoder',
            self::Muxers => 'muxer',
            self::Demuxers => 'demuxer',
            self::Filters => 'filter',
            self::Protocols => 'protocol',
            self::BitstreamFilters => 'bsf',
            self::HardwareAccelerations => 'hwaccel',
        };
    }

    /**
     * The component names in ffmpeg's listing. Encoders, decoders, formats and filters are
     * a table below a "---" line, with the flags before the name; demuxers that share
     * one entry are comma separated ("mov,mp4,m4a"). The others are one name per line
     * below headings that end with a colon ("Input:", "Output:").
     *
     * @return list<string>
     */
    public function parse(string $output): array
    {
        $lines = array_map(trim(...), preg_split('/\R/', $output) ?: []);

        $names = match ($this) {
            self::Protocols, self::BitstreamFilters, self::HardwareAccelerations => array_filter(
                $lines,
                fn (string $line): bool => $line !== '' && ! str_ends_with($line, ':'),
            ),
            default => $this->tableNames($lines),
        };

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    protected function tableNames(array $lines): array
    {
        $separator = array_find_key($lines, fn (string $line): bool => preg_match('/^-{2,}$/', $line) === 1);

        if ($separator === null) {
            return [];
        }

        $names = [];

        foreach (array_slice($lines, $separator + 1) as $line) {
            $columns = preg_split('/\s+/', $line, 3) ?: [];

            if (count($columns) >= 2) {
                array_push($names, ...explode(',', $columns[1]));
            }
        }

        return $names;
    }
}
