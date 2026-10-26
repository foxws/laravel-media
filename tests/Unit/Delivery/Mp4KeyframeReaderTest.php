<?php

declare(strict_types=1);

use Foxws\Media\Delivery\Mp4KeyframeReader;

/**
 * Read the keyframes of an MP4 file's bytes.
 *
 * @return list<float>|null
 */
function readMp4Keyframes(string $contents): ?array
{
    $path = tempnam(sys_get_temp_dir(), 'mp4');
    file_put_contents($path, $contents);

    try {
        return Mp4KeyframeReader::read($path);
    } finally {
        unlink($path);
    }
}

it('reads the keyframes from the sync sample table', function () {
    expect(readMp4Keyframes(mp4File(samples: 10, sync: [1, 6])))->toBe([0.0, 0.166833]);
});

it('reads the moov box after the media data', function () {
    expect(readMp4Keyframes(mp4File(sync: [1, 6], moovFirst: false)))->toBe([0.0, 0.166833]);
});

it('adds composition offsets to the decode times', function () {
    // An IBBP pattern: decode order I P B B, each presented two frames after its decode time minus reordering.
    $keyframes = readMp4Keyframes(mp4File(samples: 8, sync: [1, 5], offsets: [2002, 5005, 0, 0, 2002, 5005, 0, 0]));

    expect($keyframes)->toBe([0.066733, 0.200200]);
});

it('starts the first presented sample at zero with an edit list', function () {
    $keyframes = readMp4Keyframes(mp4File(samples: 8, sync: [1, 5], offsets: [2002, 5005, 0, 0, 2002, 5005, 0, 0], edits: [[2002, 0x00010000]]));

    expect($keyframes)->toBe([0.0, 0.133467]);
});

it('starts at zero when the edit begins before the first presented sample', function () {
    $keyframes = readMp4Keyframes(mp4File(samples: 8, sync: [1, 5], offsets: [2002, 5005, 0, 0, 2002, 5005, 0, 0], edits: [[0, 0x00010000]]));

    expect($keyframes)->toBe([0.0, 0.133467]);
});

it('leaves files to ffprobe that it cannot read exactly like ffprobe', function (string $contents) {
    expect(readMp4Keyframes($contents))->toBeNull();
})->with([
    'fragmented' => fn () => mp4File(fragmented: true),
    'two video tracks' => fn () => mp4File(videoTracks: 2),
    'no video track' => fn () => mp4File(videoTracks: 0),
    'no sync sample table' => fn () => mp4File(sync: null),
    'an empty sync sample table' => fn () => mp4File(sync: []),
    'several edits' => fn () => mp4File(edits: [[0, 0x00010000], [1001, 0x00010000]]),
    'an empty edit' => fn () => mp4File(edits: [[-1, 0x00010000]]),
    'another rate' => fn () => mp4File(edits: [[0, 0x00020000]]),
    'open-GOP random access groups' => fn () => mp4File(sampleGroup: 'rap '),
    'a sync sample past the samples' => fn () => mp4File(samples: 4, sync: [1, 9]),
    'matroska' => fn () => "\x1A\x45\xDF\xA3".str_repeat("\0", 64),
    'an empty file' => fn () => '',
]);

it('leaves files it cannot open to ffprobe', function () {
    expect(Mp4KeyframeReader::read(sys_get_temp_dir().'/missing-'.uniqid().'.mp4'))->toBeNull();
});
