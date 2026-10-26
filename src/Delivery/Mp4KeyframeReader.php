<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

/**
 * Reads the keyframe timestamps of an MP4 or MOV file from the sample tables in its moov box,
 * without reading the media data, so indexing a large file reads a few megabytes instead of all
 * of it. The timestamps are what ffprobe reports as packet pts_time: decode time plus composition
 * offset, moved by the edit list. Returns null for files it can't read exactly like ffprobe
 * (fragmented files, several video tracks, complex edit lists, open-GOP sample groups), so the
 * indexer lists the packets with ffprobe instead.
 *
 * @internal
 */
final class Mp4KeyframeReader
{
    /**
     * The largest moov box that is read into memory. A two-hour video with B-frames has about 2 MB.
     */
    public const int MAX_MOOV_SIZE = 256 * 1024 * 1024;

    /**
     * What editStart() returns for a track without an edit list.
     */
    protected const int NO_EDIT_LIST = -1;

    /**
     * Top-level boxes an MP4 or MOV file can start with; anything else isn't one.
     */
    protected const array TOP_LEVEL_BOXES = ['ftyp', 'moov', 'mdat', 'free', 'skip', 'wide', 'pnot', 'uuid', 'meta', 'pdin'];

    /**
     * @return list<float>|null Keyframe timestamps in seconds, ascending, or null to list packets instead.
     */
    public static function read(string $path): ?array
    {
        $moov = self::moov($path);

        return $moov !== null ? self::keyframes($moov) : null;
    }

    /**
     * Walk the top-level boxes by their headers until the moov box, and read only that one.
     */
    protected static function moov(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            return self::findMoov($handle, (int) filesize($path));
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    protected static function findMoov(mixed $handle, int $fileSize): ?string
    {
        $offset = 0;

        while (fseek($handle, $offset) === 0) {
            $header = fread($handle, 16);

            if ($header === false || strlen($header) < 8) {
                return null;
            }

            /** @var array{1: int} $size */
            $size = unpack('N', $header);
            $type = substr($header, 4, 4);
            $boxSize = $size[1];
            $headerSize = 8;

            if (! in_array($type, self::TOP_LEVEL_BOXES, true)) {
                return null;
            }

            if ($boxSize === 1) {
                if (strlen($header) < 16) {
                    return null;
                }

                /** @var array{1: int} $largeSize */
                $largeSize = unpack('J', $header, 8);
                $boxSize = $largeSize[1];
                $headerSize = 16;
            }

            if ($type === 'moov') {
                if ($boxSize === 0) {
                    $boxSize = $fileSize - $offset;
                }

                if ($boxSize <= $headerSize || $boxSize > self::MAX_MOOV_SIZE || fseek($handle, $offset + $headerSize) !== 0) {
                    return null;
                }

                $moov = stream_get_contents($handle, $boxSize - $headerSize);

                return is_string($moov) && strlen($moov) === $boxSize - $headerSize ? $moov : null;
            }

            if ($boxSize < $headerSize) {
                return null;
            }

            $offset += $boxSize;
        }

        return null;
    }

    /**
     * @return list<float>|null
     */
    protected static function keyframes(string $moov): ?array
    {
        $boxes = self::children($moov, 0, strlen($moov));

        if (isset($boxes['mvex'])) {
            return null;
        }

        $videoTracks = array_values(array_filter(
            self::all($moov, 0, strlen($moov), 'trak'),
            fn (array $trak): bool => self::handler($moov, $trak) === 'vide',
        ));

        if (count($videoTracks) !== 1) {
            return null;
        }

        return self::trackKeyframes($moov, $videoTracks[0]);
    }

    /**
     * @param  array{0: int, 1: int}  $trak
     * @return list<float>|null
     */
    protected static function trackKeyframes(string $moov, array $trak): ?array
    {
        $mdia = self::children($moov, ...$trak)['mdia'] ?? null;
        $minf = $mdia !== null ? self::children($moov, ...$mdia)['minf'] ?? null : null;
        $stbl = $minf !== null ? self::children($moov, ...$minf)['stbl'] ?? null : null;
        $mdhd = $mdia !== null ? self::children($moov, ...$mdia)['mdhd'] ?? null : null;

        if ($stbl === null || $mdhd === null) {
            return null;
        }

        $tables = self::children($moov, ...$stbl);
        $timescale = self::timescale($moov, $mdhd);

        // Without a sync sample table, or with an empty one, ffmpeg falls back on parsing the
        // bitstream, and open-GOP random access points mark more samples as keyframes.
        if ($timescale === null || ! isset($tables['stts'], $tables['stss']) || self::hasRandomAccessGroups($moov, $stbl)) {
            return null;
        }

        $editStart = self::editStart($moov, $trak);

        if ($editStart === null) {
            return null;
        }

        $sync = self::syncSamples($moov, $tables['stss']);
        $times = self::presentationTimes($moov, $tables['stts'], $tables['ctts'] ?? null);

        if ($sync === [] || $times === null) {
            return null;
        }

        // With an edit list, ffmpeg moves the timestamps so the first sample presented in the
        // edit starts at zero. Without one, it keeps them as they are.
        $offset = $editStart === self::NO_EDIT_LIST ? 0 : min(array_filter($times, fn (int $time): bool => $time >= $editStart) ?: [$editStart]);

        $keyframes = [];

        foreach ($sync as $sample) {
            if (! isset($times[$sample - 1])) {
                return null;
            }

            $keyframes[] = round(($times[$sample - 1] - $offset) / $timescale, 6);
        }

        sort($keyframes);

        return array_values(array_unique($keyframes, SORT_REGULAR));
    }

    /**
     * The media time a single normal edit starts the track at, NO_EDIT_LIST without an edit list,
     * or null for edit lists ffprobe treats in more complex ways (empty edits, several edits,
     * other rates).
     *
     * @param  array{0: int, 1: int}  $trak
     */
    protected static function editStart(string $moov, array $trak): ?int
    {
        $edts = self::children($moov, ...$trak)['edts'] ?? null;
        $elst = $edts !== null ? self::children($moov, ...$edts)['elst'] ?? null : null;

        if ($elst === null) {
            return self::NO_EDIT_LIST;
        }

        [$start] = $elst;
        $version = ord($moov[$start]);
        /** @var array{1: int} $count */
        $count = unpack('N', $moov, $start + 4);

        if ($count[1] !== 1) {
            return null;
        }

        $entry = $start + 8;

        if ($entry + ($version === 1 ? 20 : 12) > $elst[1]) {
            return null;
        }

        /** @var array{duration: int, time: int, rate: int} $values */
        $values = unpack($version === 1 ? 'Jduration/Jtime/Nrate' : 'Nduration/Ntime/Nrate', $moov, $entry);

        // 64-bit values come out signed already; 32-bit ones are signed by hand.
        $time = $version === 1 || $values['time'] < 0x80000000 ? $values['time'] : $values['time'] - 0x100000000;

        return $time >= 0 && $values['rate'] === 0x00010000 ? $time : null;
    }

    /**
     * Each sample's presentation time in the media timescale: its decode time from stts plus its
     * composition offset from ctts.
     *
     * @param  array{0: int, 1: int}  $stts
     * @param  array{0: int, 1: int}|null  $ctts
     * @return list<int>|null
     */
    protected static function presentationTimes(string $moov, array $stts, ?array $ctts): ?array
    {
        $offsets = [];

        foreach ($ctts !== null ? self::entries($moov, $ctts) : [] as [$count, $offset]) {
            $offset = $offset >= 0x80000000 ? $offset - 0x100000000 : $offset;

            for ($i = 0; $i < $count; $i++) {
                $offsets[] = $offset;
            }
        }

        $times = [];
        $time = 0;

        foreach (self::entries($moov, $stts) as [$count, $delta]) {
            for ($i = 0; $i < $count; $i++) {
                $times[] = $time + ($offsets[count($times)] ?? 0);
                $time += $delta;
            }
        }

        // Composition offsets for samples that don't exist mean the tables don't agree.
        return count($offsets) <= count($times) ? $times : null;
    }

    /**
     * The 1-based numbers of the sync samples (keyframes).
     *
     * @param  array{0: int, 1: int}  $stss
     * @return list<int>
     */
    protected static function syncSamples(string $moov, array $stss): array
    {
        [$start, $end] = $stss;
        /** @var array{1: int} $count */
        $count = unpack('N', $moov, $start + 4);
        $length = min($count[1], intdiv($end - $start - 8, 4));

        if ($length <= 0) {
            return [];
        }

        /** @var array<int, int> $samples */
        $samples = unpack("N{$length}", $moov, $start + 8);

        return array_values($samples);
    }

    /**
     * The (count, value) pairs of an stts or ctts table.
     *
     * @param  array{0: int, 1: int}  $box
     * @return list<array{0: int, 1: int}>
     */
    protected static function entries(string $moov, array $box): array
    {
        [$start, $end] = $box;
        /** @var array{1: int} $count */
        $count = unpack('N', $moov, $start + 4);
        $length = min($count[1], intdiv($end - $start - 8, 8));

        if ($length <= 0) {
            return [];
        }

        /** @var array<int, int> $values */
        $values = unpack('N'.($length * 2), $moov, $start + 8);

        return array_map(fn (array $pair): array => [$pair[0], $pair[1]], array_chunk(array_values($values), 2));
    }

    /**
     * Whether the sample tables group samples as random access points, which ffmpeg marks as
     * keyframes on top of the sync samples, e.g. the open-GOP CRA frames of HEVC.
     *
     * @param  array{0: int, 1: int}  $stbl
     */
    protected static function hasRandomAccessGroups(string $moov, array $stbl): bool
    {
        foreach (self::all($moov, $stbl[0], $stbl[1], 'sbgp') as [$start]) {
            if (in_array(substr($moov, $start + 4, 4), ['rap ', 'sync'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The timescale of an mdhd box, in units per second.
     *
     * @param  array{0: int, 1: int}  $box
     */
    protected static function timescale(string $moov, array $box): ?int
    {
        [$start] = $box;
        $position = $start + (ord($moov[$start]) === 1 ? 20 : 12);

        if ($position + 4 > strlen($moov)) {
            return null;
        }

        /** @var array{1: int} $timescale */
        $timescale = unpack('N', $moov, $position);

        return $timescale[1] > 0 ? $timescale[1] : null;
    }

    /**
     * The handler type of a track: "vide", "soun", "subt", "text" and so on.
     *
     * @param  array{0: int, 1: int}  $trak
     */
    protected static function handler(string $moov, array $trak): ?string
    {
        $mdia = self::children($moov, ...$trak)['mdia'] ?? null;
        $hdlr = $mdia !== null ? self::children($moov, ...$mdia)['hdlr'] ?? null : null;

        return $hdlr !== null ? substr($moov, $hdlr[0] + 8, 4) : null;
    }

    /**
     * The first child box of each type, as the start and end of its content.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    protected static function children(string $data, int $start, int $end): array
    {
        $children = [];

        foreach (self::boxes($data, $start, $end) as [$type, $contentStart, $contentEnd]) {
            $children[$type] ??= [$contentStart, $contentEnd];
        }

        return $children;
    }

    /**
     * Every child box of the type, as the start and end of its content.
     *
     * @return list<array{0: int, 1: int}>
     */
    protected static function all(string $data, int $start, int $end, string $type): array
    {
        $boxes = [];

        foreach (self::boxes($data, $start, $end) as [$boxType, $contentStart, $contentEnd]) {
            if ($boxType === $type) {
                $boxes[] = [$contentStart, $contentEnd];
            }
        }

        return $boxes;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}>
     */
    protected static function boxes(string $data, int $start, int $end): array
    {
        $boxes = [];
        $offset = $start;

        while ($offset + 8 <= $end) {
            /** @var array{1: int} $size */
            $size = unpack('N', $data, $offset);
            $type = substr($data, $offset + 4, 4);
            $boxSize = $size[1];
            $headerSize = 8;

            if ($boxSize === 1 && $offset + 16 <= $end) {
                /** @var array{1: int} $largeSize */
                $largeSize = unpack('J', $data, $offset + 8);
                $boxSize = $largeSize[1];
                $headerSize = 16;
            } elseif ($boxSize === 0) {
                $boxSize = $end - $offset;
            }

            if ($boxSize < $headerSize || $offset + $boxSize > $end) {
                break;
            }

            $boxes[] = [$type, $offset + $headerSize, $offset + $boxSize];
            $offset += $boxSize;
        }

        return $boxes;
    }
}
