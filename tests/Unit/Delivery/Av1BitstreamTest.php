<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Av1Bitstream;
use Foxws\Media\Exceptions\InvalidMediaException;

it('finds the tiles of a frame after its header and tile sizes', function () {
    $header = av1Bits(av1KeyFrameHeader());
    $sample = av1SequenceHeader().av1Obu(6, $header.av1Bits([[0, 1]]).pack('v', 39).str_repeat('a', 40).str_repeat('b', 23));
    $payload = strlen($sample) - strlen($header) - 1 - 2 - 63;

    // The tile group header is one byte, then the first tile's 2-byte size.
    expect(new Av1Bitstream()->tiles($sample))->toBe([
        [$payload + strlen($header) + 1 + 2, 40],
        [$payload + strlen($header) + 1 + 2 + 40, 23],
    ]);
});

it('reads the sequence header from the configuration obus', function () {
    $frame = av1Obu(6, av1Bits(av1KeyFrameHeader(small: true)).str_repeat('t', 50));

    expect(new Av1Bitstream(av1SequenceHeader())->tiles($frame))->toBe([[strlen($frame) - 50, 50]]);
});

it('finds the tiles of tile groups that follow a frame header', function () {
    $tileGroup = fn (int $tile, string $data): string => av1Obu(4, av1Bits([[1, 1], [$tile, 1], [$tile, 1]]).$data);
    $header = av1Obu(3, av1Bits(av1KeyFrameHeader()));
    $first = $tileGroup(0, str_repeat('a', 30));
    $second = $tileGroup(1, str_repeat('b', 20));

    $sample = av1Obu(2, '').$header.$first.av1Obu(7, av1Bits(av1KeyFrameHeader())).$second;
    $offset = strlen($sample) - strlen($second);

    expect(new Av1Bitstream(av1SequenceHeader())->tiles($sample))->toBe([
        [2 + strlen($header) + 3, 30],
        [$offset + 3, 20],
    ]);
});

it('reads the frame size of an inter frame from the frame it refers to', function () {
    $bitstream = new Av1Bitstream(av1SequenceHeader());
    $bitstream->tiles(av1Obu(6, av1Bits(av1KeyFrameHeader(small: true)).'key frame tile'));

    // 640x360 has 10 superblock columns, so after four more tile columns the column count can't grow.
    $header = av1Bits([
        [0, 1], [1, 2], [1, 1], [0, 1], [0, 1], [0, 1], // inter frame, shown, not error resilient, cdf updates, no screen content
        [1, 1], [1, 7], [0, 3], [1, 8],                 // frame size override, order hint, primary reference, refresh slot 0
        [0, 1], ...array_fill(0, 7, [0, 3]),            // references signalled in full, all slot 0
        [1, 1],                                         // the size of the first reference
        [0, 1], [1, 1], [0, 1], [0, 1], [0, 1],         // mv precision, switchable filter, motion mode, ref frame mvs, frame end cdf update
        [1, 1], [1, 1], [1, 1], [1, 1], [1, 1], [0, 1], // uniform tiles, 10 columns of one superblock, one row
        [0, 4], [0, 2],                                 // context tile, 1-byte tile sizes
        ...av1FrameTools(false),
    ]);
    $tiles = av1Bits([[0, 1]]).implode('', array_map(fn (int $tile): string => chr(9).str_repeat((string) $tile, 10), range(0, 8))).str_repeat('9', 12);
    $sample = av1Obu(6, $header.$tiles);
    $start = strlen($sample) - strlen($tiles) + 1;

    expect($bitstream->tiles($sample))->toBe([
        ...array_map(fn (int $tile): array => [$start + 11 * $tile + 1, 10], range(0, 8)),
        [$start + 99, 12],
    ]);
});

it('finds no tiles in obus without tile data', function () {
    $showExisting = av1Obu(3, av1Bits([[1, 1], [0, 3]]));

    expect(new Av1Bitstream(av1SequenceHeader())->tiles(av1Obu(2, '').av1Obu(5, 'metadata').$showExisting.av1Obu(15, 'padding')))->toBe([]);
});

it('refuses frames it cannot read', function (string $sample) {
    new Av1Bitstream()->tiles($sample);
})->throws(InvalidMediaException::class, "The [av1] bitstream of the segment couldn't be read to encrypt it.")->with([
    'without a sequence header' => [av1Obu(6, av1Bits(av1KeyFrameHeader()).'tile')],
    'truncated header' => [av1SequenceHeader().av1Obu(6, substr(av1Bits(av1KeyFrameHeader()), 0, 4))],
    'tile beyond the obu' => [av1SequenceHeader().av1Obu(6, av1Bits(av1KeyFrameHeader()).av1Bits([[0, 1]]).pack('v', 99).'tile')],
    'obu beyond the sample' => [substr(av1SequenceHeader(), 0, -2)],
    'tile group without a frame header' => [av1SequenceHeader().av1Obu(4, 'tile')],
]);
