<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Closure;
use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Exceptions\InvalidMediaException;
use InvalidArgumentException;

/**
 * Common Encryption with the cenc scheme (AES-128-CTR) for fragmented MP4 segments, applied as they're
 * served, so the cached segments stay unencrypted. Audio samples are encrypted whole. H.264 and HEVC
 * samples are encrypted per NAL unit (subsample encryption): lengths, NAL headers and non-video NAL
 * units stay readable, and each protected range is a whole number of AES blocks.
 *
 * @internal
 */
final class CommonEncryption
{
    /**
     * The W3C Common PSSH system ID, which ClearKey reads the key IDs from.
     */
    protected const string COMMON_SYSTEM_ID = '1077efecc0b24d02ace33c1e52e2fb4b';

    protected const int IV_SIZE = 8;

    protected const int MAX_CLEAR_BYTES = 0xFFFF;

    protected const int SAIO_SIZE = 20;

    /**
     * Mark the track of an initialization segment as encrypted with the key: its sample entry becomes
     * encv or enca with a protection scheme (sinf), and a Common PSSH box lists the key ID.
     *
     * @throws InvalidMediaException
     */
    public static function init(string $init, EncryptionKey $key): string
    {
        // Refuse codecs that can't be encrypted before rewriting anything.
        self::sampleEntry($init);

        $init = self::rewrite($init, ['moov', 'trak', 'mdia', 'minf', 'stbl', 'stsd'], function (string $stsd) use ($key): string {
            $entries = substr($stsd, 16);
            $protected = '';

            foreach (self::boxes($entries) as $entry) {
                $protected .= self::protectSampleEntry(substr($entries, $entry['offset'], $entry['size']), $entry['type'], $key);
            }

            return self::box('stsd', substr($stsd, 8, 8).$protected);
        });

        return self::rewrite($init, ['moov'], fn (string $moov): string => self::box('moov', substr($moov, 8).self::pssh($key)));
    }

    /**
     * Encrypt the samples of a media segment and describe them in each track fragment with senc, saiz and saio boxes.
     *
     * @param  string  $init  The track's unencrypted initialization segment.
     * @param  string  $context  Unique per track and segment under the key, e.g. "0|video|12", to derive the sample IVs from.
     *
     * @throws InvalidMediaException
     */
    public static function segment(string $init, string $segment, EncryptionKey $key, string $context): string
    {
        $track = self::track($init);
        $boxes = self::boxes($segment);
        $sample = 0;
        $fragments = [];
        $samples = [];

        foreach ($boxes as $box) {
            if ($box['type'] === 'moof') {
                $fragments[$box['offset']] = self::protectFragment($segment, $box['offset'], $box['size'], $track, $key->binary(), $context, $sample, $samples);
            }
        }

        ksort($samples);
        $output = '';

        foreach ($boxes as $box) {
            $output .= $fragments[$box['offset']] ?? self::withSamples($segment, $box['offset'], $box['size'], $samples);
        }

        return $output;
    }

    /**
     * A box of the segment with the encrypted samples that fall within it.
     *
     * @param  array<int, string>  $samples  The encrypted samples by their position in the segment, in order.
     *
     * @throws InvalidMediaException
     */
    protected static function withSamples(string $segment, int $offset, int $size, array $samples): string
    {
        $output = '';
        $position = $offset;
        $end = $offset + $size;

        foreach ($samples as $start => $data) {
            if ($start < $offset || $start >= $end) {
                continue;
            }

            if ($start < $position || $start + strlen($data) > $end) {
                throw InvalidMediaException::notFragmented();
            }

            $output .= substr($segment, $position, $start - $position).$data;
            $position = $start + strlen($data);
        }

        return $output.substr($segment, $position, $end - $position);
    }

    /**
     * Encrypt the samples one movie fragment points at, and return the fragment with the encryption
     * boxes added to each track fragment.
     *
     * @param  array{nalHeaderSize: int|null, nalLengthSize: int, defaultSampleSize: int}  $track
     * @param  array<int, string>  $samples  Receives the encrypted samples by their position in the segment.
     *
     * @throws InvalidMediaException
     */
    protected static function protectFragment(string $segment, int $start, int $size, array $track, string $key, string $context, int &$sample, array &$samples): string
    {
        $moof = substr($segment, $start, $size);
        $children = self::boxes($moof, 8);
        $subsampled = $track['nalHeaderSize'] !== null;
        $information = [];

        foreach ($children as $child) {
            if ($child['type'] !== 'traf') {
                continue;
            }

            $entries = [];

            foreach (self::samples(substr($moof, $child['offset'], $child['size']), $track['defaultSampleSize']) as [$offset, $length]) {
                $position = $start + $offset;

                if ($position + $length > strlen($segment)) {
                    throw InvalidMediaException::notFragmented();
                }

                $iv = substr(hash('xxh128', "{$context}|{$sample}", true), 0, self::IV_SIZE);
                [$samples[$position], $subsamples] = self::protectSample(substr($segment, $position, $length), $key, $iv, $track['nalHeaderSize'], $track['nalLengthSize']);
                $entries[] = ['iv' => $iv, 'subsamples' => $subsamples];
                $sample++;
            }

            $information[$child['offset']] = self::sampleInformation($entries, $subsampled);
        }

        // The media data follows the movie fragment, so every data offset moves by the added boxes.
        $added = array_sum(array_map(fn (array $boxes): int => strlen($boxes['saiz']) + self::SAIO_SIZE + strlen($boxes['senc']), $information));
        $output = '';

        foreach ($children as $child) {
            $box = substr($moof, $child['offset'], $child['size']);

            if (isset($information[$child['offset']])) {
                ['saiz' => $saiz, 'senc' => $senc] = $information[$child['offset']];
                $trafChildren = self::shiftDataOffsets(substr($box, 8), $added);

                // saio points at the first IV in senc, after its header, version, flags and sample count.
                $saio = self::fullBox('saio', 0, 0, pack('NN', 1, 8 + strlen($output) + 8 + strlen($trafChildren) + strlen($saiz) + self::SAIO_SIZE + 16));
                $box = self::box('traf', $trafChildren.$saiz.$saio.$senc);
            }

            $output .= $box;
        }

        return self::box('moof', $output);
    }

    /**
     * The saiz and senc boxes of a track fragment: senc holds each sample's IV and subsamples, which
     * saiz gives the sizes of.
     *
     * @param  list<array{iv: string, subsamples: list<array{int, int}>}>  $entries
     * @return array{saiz: string, senc: string}
     *
     * @throws InvalidMediaException
     */
    protected static function sampleInformation(array $entries, bool $subsampled): array
    {
        $information = array_map(fn (array $entry): string => $entry['iv'].($subsampled
            ? pack('n', count($entry['subsamples'])).implode('', array_map(fn (array $range): string => pack('nN', ...$range), $entry['subsamples']))
            : ''), $entries);
        $sizes = array_map(strlen(...), $information);

        if (max([0, ...$sizes]) > 0xFF) {
            throw InvalidMediaException::notEncryptable('samples with this many NAL units');
        }

        $default = count(array_unique($sizes)) === 1 ? $sizes[0] : 0;

        return [
            'saiz' => self::fullBox('saiz', 0, 0, pack('CN', $default, count($entries)).($default === 0 ? pack('C*', ...$sizes) : '')),
            'senc' => self::fullBox('senc', 0, $subsampled ? 0x2 : 0, pack('N', count($entries)).implode('', $information)),
        ];
    }

    /**
     * Where the samples of a track fragment are, as offsets from the start of the movie fragment and lengths.
     *
     * @return list<array{int, int}>
     *
     * @throws InvalidMediaException
     */
    protected static function samples(string $traf, int $defaultSampleSize): array
    {
        $tfhd = self::find($traf, ['tfhd'], 8) ?? throw InvalidMediaException::notFragmented();
        $flags = self::flags($tfhd);

        // Offsets have to be relative to the movie fragment, so they can shift with it.
        if (($flags & 0x1) !== 0 || ($flags & 0x020000) === 0) {
            throw InvalidMediaException::notFragmented();
        }

        $fields = 16 + (($flags & 0x2) !== 0 ? 4 : 0) + (($flags & 0x8) !== 0 ? 4 : 0);
        $defaultSize = ($flags & 0x10) !== 0 ? self::uint32($tfhd, $fields) : $defaultSampleSize;
        $samples = [];
        $next = 0;

        foreach (self::boxes($traf, 8) as $box) {
            if ($box['type'] !== 'trun') {
                continue;
            }

            $trun = substr($traf, $box['offset'], $box['size']);
            $flags = self::flags($trun);
            $count = self::uint32($trun, 12);
            $position = 16;

            if (($flags & 0x1) !== 0) {
                $next = self::uint32($trun, $position);
                $position += 4;
            }

            $position += ($flags & 0x4) !== 0 ? 4 : 0;
            $fieldSize = 4 * count(array_filter([0x100, 0x200, 0x400, 0x800], fn (int $flag): bool => ($flags & $flag) !== 0));

            for ($i = 0; $i < $count; $i++) {
                $sizeOffset = $position + $i * $fieldSize + (($flags & 0x100) !== 0 ? 4 : 0);
                $size = ($flags & 0x200) !== 0 ? self::uint32($trun, $sizeOffset) : $defaultSize;
                $samples[] = [$next, $size];
                $next += $size;
            }
        }

        return $samples;
    }

    /**
     * Move the data offsets of every track run in a track fragment by the bytes added before the media data.
     */
    protected static function shiftDataOffsets(string $children, int $added): string
    {
        $output = '';

        foreach (self::boxes($children) as $box) {
            $data = substr($children, $box['offset'], $box['size']);

            if ($box['type'] === 'trun' && (self::flags($data) & 0x1) !== 0) {
                $data = substr_replace($data, pack('N', self::uint32($data, 16) + $added), 16, 4);
            }

            $output .= $data;
        }

        return $output;
    }

    /**
     * Encrypt a sample whole, or the slice data of its video NAL units, as one AES-CTR stream.
     *
     * @return array{string, list<array{int, int}>} The encrypted sample and its subsamples as clear and protected byte counts.
     */
    protected static function protectSample(string $sample, string $key, string $iv, ?int $nalHeaderSize, int $nalLengthSize): array
    {
        if ($nalHeaderSize === null) {
            return [self::encrypt($sample, $key, $iv), []];
        }

        $ranges = [];
        $position = 0;
        $length = strlen($sample);

        while ($position + $nalLengthSize <= $length) {
            $nalSize = (int) hexdec(bin2hex(substr($sample, $position, $nalLengthSize)));
            $unitSize = min($nalLengthSize + $nalSize, $length - $position);
            $protected = 0;

            if ($nalSize > $nalHeaderSize && self::isSliceData($sample[$position + $nalLengthSize] ?? "\0", $nalHeaderSize)) {
                $protected = intdiv($unitSize - $nalLengthSize - $nalHeaderSize, 16) * 16;
            }

            $ranges[] = [$unitSize - $protected, $protected];
            $position += $unitSize;
        }

        if ($position < $length) {
            $ranges[] = [$length - $position, 0];
        }

        $subsamples = self::subsamples($ranges);
        $plain = '';
        $position = 0;

        foreach ($subsamples as [$clear, $protected]) {
            $plain .= substr($sample, $position + $clear, $protected);
            $position += $clear + $protected;
        }

        $cipher = self::encrypt($plain, $key, $iv);
        $output = '';
        $position = 0;
        $cipherPosition = 0;

        foreach ($subsamples as [$clear, $protected]) {
            $output .= substr($sample, $position, $clear).substr($cipher, $cipherPosition, $protected);
            $position += $clear + $protected;
            $cipherPosition += $protected;
        }

        return [$output, $subsamples];
    }

    /**
     * Join clear ranges with the protected range that follows, splitting clear runs a subsample can't hold.
     *
     * @param  list<array{int, int}>  $ranges
     * @return list<array{int, int}>
     */
    protected static function subsamples(array $ranges): array
    {
        $subsamples = [];
        $clear = 0;

        foreach ($ranges as [$clearBytes, $protected]) {
            $clear += $clearBytes;

            if ($protected === 0) {
                continue;
            }

            for (; $clear > self::MAX_CLEAR_BYTES; $clear -= self::MAX_CLEAR_BYTES) {
                $subsamples[] = [self::MAX_CLEAR_BYTES, 0];
            }

            $subsamples[] = [$clear, $protected];
            $clear = 0;
        }

        for (; $clear > 0; $clear -= min($clear, self::MAX_CLEAR_BYTES)) {
            $subsamples[] = [min($clear, self::MAX_CLEAR_BYTES), 0];
        }

        return $subsamples;
    }

    /**
     * Whether a NAL unit carries slice data, by the type in its header: 1 to 5 for H.264, 0 to 31 for HEVC.
     */
    protected static function isSliceData(string $header, int $nalHeaderSize): bool
    {
        $type = $nalHeaderSize === 1 ? ord($header) & 0x1F : (ord($header) >> 1) & 0x3F;

        return $nalHeaderSize === 1 ? $type >= 1 && $type <= 5 : $type <= 31;
    }

    protected static function encrypt(string $data, string $key, string $iv): string
    {
        if ($data === '') {
            return '';
        }

        return openssl_encrypt($data, 'aes-128-ctr', $key, OPENSSL_RAW_DATA, str_pad($iv, 16, "\0"))
            ?: throw new InvalidArgumentException('The sample could not be encrypted.');
    }

    /**
     * The sample entry as encv or enca, with a protection scheme naming the original format, the
     * cenc scheme and the key ID.
     *
     * @throws InvalidMediaException
     */
    protected static function protectSampleEntry(string $entry, string $type, EncryptionKey $key): string
    {
        $protected = self::nalHeaderSize($type) !== null ? 'encv' : 'enca';

        $tenc = self::fullBox('tenc', 0, 0, "\0\0\1".chr(self::IV_SIZE).hex2bin($key->keyId));
        $sinf = self::box('sinf', self::box('frma', $type).self::fullBox('schm', 0, 0, 'cenc'.pack('N', 0x00010000)).self::box('schi', $tenc));

        return self::box($protected, substr($entry, 8).$sinf);
    }

    protected static function pssh(EncryptionKey $key): string
    {
        return self::fullBox('pssh', 1, 0, hex2bin(self::COMMON_SYSTEM_ID).pack('N', 1).hex2bin($key->keyId).pack('N', 0));
    }

    /**
     * What encrypting the track's samples needs from its initialization segment.
     *
     * @return array{nalHeaderSize: int|null, nalLengthSize: int, defaultSampleSize: int}
     *
     * @throws InvalidMediaException
     */
    protected static function track(string $init): array
    {
        ['type' => $type, 'entry' => $entry] = self::sampleEntry($init);
        $nalHeaderSize = self::nalHeaderSize($type);
        $nalLengthSize = 4;

        if ($nalHeaderSize !== null) {
            // Video sample entries have 78 bytes of fields before their child boxes.
            $config = self::find($entry, [$nalHeaderSize === 1 ? 'avcC' : 'hvcC'], 86) ?? throw InvalidMediaException::notFragmented();
            $nalLengthSize = (ord($config[$nalHeaderSize === 1 ? 12 : 29] ?? "\3") & 0x3) + 1;
        }

        $trex = self::find($init, ['moov', 'mvex', 'trex']);

        return [
            'nalHeaderSize' => $nalHeaderSize,
            'nalLengthSize' => $nalLengthSize,
            'defaultSampleSize' => $trex !== null ? self::uint32($trex, 24) : 0,
        ];
    }

    /**
     * The first sample entry of the initialization segment, when its codec can be encrypted.
     *
     * @return array{type: string, entry: string}
     *
     * @throws InvalidMediaException
     */
    protected static function sampleEntry(string $init): array
    {
        $stsd = self::find($init, ['moov', 'trak', 'mdia', 'minf', 'stbl', 'stsd']) ?? throw InvalidMediaException::notFragmented();
        $entry = self::boxes($stsd, 16)[0] ?? throw InvalidMediaException::notFragmented();

        if (! in_array($entry['type'], ['avc1', 'avc3', 'hvc1', 'hev1', 'mp4a', 'ac-3', 'ec-3', 'Opus', 'fLaC'], true)) {
            throw InvalidMediaException::notEncryptable($entry['type']);
        }

        return ['type' => $entry['type'], 'entry' => substr($stsd, $entry['offset'], $entry['size'])];
    }

    /**
     * The size of the NAL unit header of a video sample entry, or null for audio.
     */
    protected static function nalHeaderSize(string $type): ?int
    {
        return match ($type) {
            'avc1', 'avc3' => 1,
            'hvc1', 'hev1' => 2,
            default => null,
        };
    }

    /**
     * Rewrite the boxes along a path, e.g. ['moov', 'trak'], passing each box at the end of the path to
     * the callback and updating the sizes of the boxes that contain it.
     *
     * @param  list<string>  $path
     * @param  Closure(string): string  $rewrite
     */
    protected static function rewrite(string $data, array $path, Closure $rewrite): string
    {
        $type = array_shift($path);
        $output = '';

        foreach (self::boxes($data) as $box) {
            $bytes = substr($data, $box['offset'], $box['size']);

            if ($box['type'] === $type) {
                $bytes = $path === [] ? $rewrite($bytes) : self::box($type, self::rewrite(substr($bytes, $box['header']), $path, $rewrite));
            }

            $output .= $bytes;
        }

        return $output;
    }

    /**
     * The first box along a path of boxes, e.g. ['moov', 'mvex', 'trex'], searched from the given offset.
     *
     * @param  list<string>  $path
     *
     * @throws InvalidMediaException
     */
    protected static function find(string $data, array $path, int $start = 0): ?string
    {
        $type = array_shift($path);

        foreach (self::boxes($data, $start) as $box) {
            if ($box['type'] === $type) {
                $bytes = substr($data, $box['offset'], $box['size']);

                return $path === [] ? $bytes : self::find($bytes, $path, $box['header']);
            }
        }

        return null;
    }

    /**
     * @return list<array{type: string, offset: int, size: int, header: int}>
     *
     * @throws InvalidMediaException
     */
    protected static function boxes(string $data, int $start = 0): array
    {
        $boxes = [];
        $offset = $start;
        $length = strlen($data);

        while ($offset + 8 <= $length) {
            $size = self::uint32($data, $offset);
            $header = 8;

            if ($size === 1 && $offset + 16 <= $length) {
                /** @var array{1: int} $largeSize */
                $largeSize = unpack('J', $data, $offset + 8);
                $size = $largeSize[1];
                $header = 16;
            } elseif ($size === 0) {
                $size = $length - $offset;
            }

            if ($size < $header || $offset + $size > $length) {
                throw InvalidMediaException::notFragmented();
            }

            $boxes[] = ['type' => substr($data, $offset + 4, 4), 'offset' => $offset, 'size' => $size, 'header' => $header];
            $offset += $size;
        }

        return $boxes;
    }

    protected static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }

    protected static function fullBox(string $type, int $version, int $flags, string $payload): string
    {
        return self::box($type, pack('N', ($version << 24) | $flags).$payload);
    }

    protected static function flags(string $box): int
    {
        return self::uint32($box, 8) & 0xFFFFFF;
    }

    protected static function uint32(string $data, int $offset): int
    {
        /** @var array{1: int}|false $value */
        $value = strlen($data) >= $offset + 4 ? unpack('N', $data, $offset) : false;

        return $value !== false ? $value[1] : throw InvalidMediaException::notFragmented();
    }
}
