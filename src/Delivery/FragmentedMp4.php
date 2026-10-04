<?php

declare(strict_types=1);

namespace Foxws\Media\Delivery;

use Foxws\Media\Exceptions\InvalidMediaException;

/**
 * Splits a fragmented MP4 file into its initialization segment (ftyp and moov) and its media
 * segment (the moof and mdat boxes), by walking the top-level boxes.
 *
 * @internal
 */
final class FragmentedMp4
{
    /**
     * Seconds added to every timestamp of fragmented segments. Segments are muxed one at a time
     * with their original timestamps, and decode timestamps of videos with B-frames start just
     * below zero, which fragment headers can't hold. The same offset in every segment keeps
     * video and audio in sync; DASH manifests take it off again with presentationTimeOffset.
     */
    public const int TIMESTAMP_OFFSET = 10;

    /**
     * @return array{init: string, media: string}
     *
     * @throws InvalidMediaException
     */
    public static function split(string $file): array
    {
        $offset = 0;
        $length = strlen($file);
        $mediaStart = null;

        while ($offset + 8 <= $length) {
            /** @var array{1: int} $size */
            $size = unpack('N', $file, $offset);
            $type = substr($file, $offset + 4, 4);
            $boxSize = $size[1];

            if ($boxSize === 1 && $offset + 16 <= $length) {
                /** @var array{1: int} $largeSize */
                $largeSize = unpack('J', $file, $offset + 8);
                $boxSize = $largeSize[1];
            } elseif ($boxSize === 0) {
                $boxSize = $length - $offset;
            }

            if ($boxSize < 8) {
                break;
            }

            if (in_array($type, ['moof', 'styp', 'sidx'], true)) {
                $mediaStart = $offset;

                break;
            }

            $offset += $boxSize;
        }

        $init = $mediaStart !== null ? substr($file, 0, $mediaStart) : '';

        if ($mediaStart === null || ! str_contains($init, 'moov')) {
            throw InvalidMediaException::notFragmented();
        }

        return ['init' => $init, 'media' => substr($file, $mediaStart)];
    }
}
