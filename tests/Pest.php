<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Foxws\Media\Encryption\EncryptionKey;
use Foxws\Media\Executables\Executable;
use Foxws\Media\Executables\Executables;
use Foxws\Media\Facades\Media;
use Foxws\Media\Packaging\PackagerManager;
use Foxws\Media\Tests\Fixtures\RecordingPackager;
use Foxws\Media\Tests\Fixtures\RemoteAdapter;
use Foxws\Media\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

uses(TestCase::class)->in(__DIR__);

/**
 * Point the executable's config at an executable file, so it resolves without being installed.
 */
function fakeExecutable(Executable $executable): string
{
    $directory = sys_get_temp_dir().'/laravel-media-executables-'.getmypid();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = PHP_OS_FAMILY === 'Windows'
        ? "{$directory}/{$executable->value}.bat"
        : "{$directory}/{$executable->value}";

    file_put_contents($path, PHP_OS_FAMILY === 'Windows' ? "@exit /b 0\r\n" : "#!/bin/sh\nexit 0\n");
    chmod($path, 0755);

    config(["media.executables.{$executable->value}" => $path]);

    app(Executables::class)->flush();

    return $path;
}

/**
 * A disk that isn't local (like S3), backed by a local directory, that signs temporary URLs.
 */
function remoteDisk(string $root): FilesystemAdapter
{
    $adapter = new RemoteAdapter(new LocalFilesystemAdapter($root));

    $disk = new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $root]);

    $disk->buildTemporaryUrlsUsing(fn (string $path): string => "https://remote.test/{$path}?signature=abc");

    return $disk;
}

/**
 * A real S3-driver disk whose requests are answered in-process and recorded,
 * because Storage::fake() always swaps in a local disk.
 *
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $commands  Filled with each issued command.
 * @param  string|null  $failOn  Command name to reject, e.g. 'UploadPart'.
 */
function recordingS3Disk(array &$commands = [], ?string $failOn = null): Filesystem
{
    config(['filesystems.disks.recording-s3' => [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'us-east-1',
        'bucket' => 'test-bucket',
        'root' => 'segments',
        'handler' => function (CommandInterface $command) use (&$commands, $failOn) {
            $commands[] = ['name' => $command->getName(), 'args' => $command->toArray()];

            if ($command->getName() === $failOn) {
                return Create::rejectionFor(new S3Exception("{$failOn} failed", $command));
            }

            return Create::promiseFor(new Result(match ($command->getName()) {
                'CreateMultipartUpload' => ['UploadId' => 'upload-1'],
                'UploadPart' => ['ETag' => '"etag"'],
                default => [],
            }));
        },
    ]]);

    Storage::forgetDisk('recording-s3');

    return Storage::disk('recording-s3');
}

/**
 * A temporary directory with the given files.
 *
 * @param  array<string, string>  $files
 */
function directoryWith(array $files): string
{
    $directory = sys_get_temp_dir().'/laravel-media-export-'.bin2hex(random_bytes(4));

    foreach ($files as $path => $contents) {
        @mkdir(dirname("{$directory}/{$path}"), 0777, true);
        file_put_contents("{$directory}/{$path}", $contents);
    }

    return $directory;
}

/**
 * Fake ffprobe with probe data per input file name, and ffmpeg with the given output.
 *
 * @param  array<string, array<string, mixed>>  $probes  ffprobe output keyed by the input's file name.
 */
function fakeProbes(array $probes, string $ffmpegOutput = ''): void
{
    fakeExecutable(Executable::FFProbe);
    fakeExecutable(Executable::FFMpeg);

    Process::fake(['*' => function (PendingProcess $process) use ($probes, $ffmpegOutput) {
        if (! runs($process, Executable::FFProbe)) {
            return Process::result(output: $ffmpegOutput);
        }

        return Process::result(output: (string) json_encode($probes[basename(end($process->command))] ?? []));
    }]);
}

/**
 * ffprobe output for a video with the given properties.
 *
 * @return array<string, mixed>
 */
function videoProbe(int $width = 1920, int $height = 1080, bool $audio = true, string $codec = 'h264', float $duration = 60, ?string $transfer = null): array
{
    return [
        'streams' => array_values(array_filter([
            ['index' => 0, 'codec_type' => 'video', 'codec_name' => $codec, 'width' => $width, 'height' => $height, ...($transfer !== null ? ['color_transfer' => $transfer, 'color_primaries' => 'bt2020'] : [])],
            $audio ? ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'aac', 'sample_rate' => '48000', 'channels' => 2] : null,
        ])),
        'format' => ['duration' => (string) $duration],
    ];
}

/**
 * Whether a faked process runs the given executable, on any platform.
 */
function runs(PendingProcess $process, Executable $executable): bool
{
    return is_array($process->command) && pathinfo((string) $process->command[0], PATHINFO_FILENAME) === $executable->value;
}

/**
 * A file's path on a disk as the package reports it, with forward slashes.
 */
function diskPath(string $disk, string $path = ''): string
{
    return str_replace('\\', '/', Storage::disk($disk)->path($path));
}

/**
 * Fake ffprobe with probe data per input file name, and a packager driver that records each spec and
 * writes placeholder manifests and stream outputs.
 *
 * @param  array<string, array<string, mixed>>  $probes  ffprobe output keyed by the input's file name.
 */
function fakePackaging(array $probes = []): RecordingPackager
{
    Media::fake($probes);

    $packager = new RecordingPackager;
    app(PackagerManager::class)->extend('recording', fn () => $packager);
    config(['media.packager.default' => 'recording']);

    return $packager;
}

/**
 * Store the fixture playlists on a faked disk under videos/1.
 */
function storeStreams(string $disk = 'streams'): void
{
    Storage::fake($disk);

    foreach (['master.m3u8', 'stream_0.m3u8', 'stream_1.m3u8', 'captions/stream_2.m3u8', 'manifest.mpd'] as $file) {
        Storage::disk($disk)->put("videos/1/{$file}", file_get_contents(fixture("streams/{$file}")));
    }
}

function mp4Box(string $type, string $payload = ''): string
{
    return pack('N', 8 + strlen($payload)).$type.$payload;
}

/**
 * A fragmented MP4 track with one fragment of the samples, as its initialization and media segment.
 *
 * @param  list<string>  $samples
 * @param  string  $configObus  The OBUs of the av1C box of an av01 track.
 * @return array{init: string, media: string}
 */
function fragmentedTrack(string $sampleEntry, array $samples, string $configObus = ''): array
{
    $video = in_array($sampleEntry, ['avc1', 'hvc1', 'av01', 'vp09'], true);
    $config = match ($sampleEntry) {
        'avc1' => mp4Box('avcC', "\x01\x64\x00\x1f\xff"),
        'hvc1' => mp4Box('hvcC', "\x01".str_repeat("\0", 20)."\x0f"),
        'av01' => mp4Box('av1C', "\x81\x08\x0c\x00".$configObus),
        default => mp4Box('esds', "\0\0\0\0"),
    };

    $stsd = mp4Box('stsd', pack('NN', 0, 1).mp4Box($sampleEntry, str_repeat("\0", $video ? 78 : 28).$config));
    $trak = mp4Box('trak', mp4Box('tkhd', str_repeat("\0", 84)).mp4Box('mdia', mp4Box('mdhd', str_repeat("\0", 24)).mp4Box('minf', mp4Box('stbl', $stsd))));
    $init = mp4Box('ftyp', 'iso5').mp4Box('moov', mp4Box('mvhd', str_repeat("\0", 100)).$trak.mp4Box('mvex', mp4Box('trex', pack('N6', 0, 1, 1, 0, 0, 0))));

    $moof = fn (int $dataOffset): string => mp4Box('moof', mp4Box('mfhd', pack('NN', 0, 1)).mp4Box('traf', implode('', [
        mp4Box('tfhd', pack('NN', 0x020000, 1)),
        mp4Box('tfdt', pack('NN', 0, 0)),
        mp4Box('trun', pack('NNN', 0x201, count($samples), $dataOffset).pack('N*', ...array_map(strlen(...), $samples))),
    ])));

    return ['init' => $init, 'media' => $moof(strlen($moof(0)) + 8).mp4Box('mdat', implode('', $samples))];
}

/**
 * The first box of each type among the boxes of the data.
 *
 * @return array<string, string>
 */
function mp4Boxes(string $data): array
{
    $boxes = [];

    for ($offset = 0; $offset + 8 <= strlen($data); $offset += $size) {
        $size = unpack('N', $data, $offset)[1];
        $boxes[substr($data, $offset + 4, 4)] ??= substr($data, $offset, $size);
    }

    return $boxes;
}

/**
 * Decrypt every fragment of a segment encrypted with Common Encryption (cenc), from the sample
 * positions in trun and the IVs and subsamples in senc, the way a player would.
 *
 * @return array{samples: list<string>, ivs: list<string>, subsamples: list<list<array{int, int}>>}
 */
function decryptCenc(string $segment, EncryptionKey $key): array
{
    $result = ['samples' => [], 'ivs' => [], 'subsamples' => []];

    for ($start = 0; $start + 8 <= strlen($segment); $start += $size) {
        $size = unpack('N', $segment, $start)[1];

        if (substr($segment, $start + 4, 4) !== 'moof') {
            continue;
        }

        $moof = substr($segment, $start, $size);
        $traf = mp4Boxes(substr(mp4Boxes(substr($moof, 8))['traf'], 8));
        $trun = $traf['trun'];
        $senc = $traf['senc'];
        $count = unpack('N', $trun, 12)[1];
        $position = $start + unpack('N', $trun, 16)[1];
        $subsampled = (unpack('N', $senc, 8)[1] & 0x2) !== 0;
        $info = 16;

        expect(substr($moof, unpack('N', $traf['saio'], 16)[1], 8))->toBe(substr($senc, 16, 8));

        for ($i = 0; $i < $count; $i++) {
            $sample = substr($segment, $position, unpack('N', $trun, 20 + 4 * $i)[1]);
            $iv = substr($senc, $info, 8);
            $info += 8;
            $ranges = [[0, strlen($sample)]];

            if ($subsampled) {
                $ranges = [];

                for ($n = unpack('n', $senc, $info)[1], $info += 2; $n > 0; $n--, $info += 6) {
                    $ranges[] = array_values(unpack('nclear/Nprotected', $senc, $info));
                }
            }

            $cipher = '';
            $offset = 0;

            foreach ($ranges as [$clear, $protected]) {
                $cipher .= substr($sample, $offset + $clear, $protected);
                $offset += $clear + $protected;
            }

            $plain = (string) openssl_decrypt($cipher, 'aes-128-ctr', $key->binary(), OPENSSL_RAW_DATA, $iv.str_repeat("\0", 8));
            $decrypted = '';
            $offset = 0;
            $plainOffset = 0;

            foreach ($ranges as [$clear, $protected]) {
                $decrypted .= substr($sample, $offset, $clear).substr($plain, $plainOffset, $protected);
                $offset += $clear + $protected;
                $plainOffset += $protected;
            }

            $result['samples'][] = $decrypted.substr($sample, $offset);
            $result['ivs'][] = $iv;
            $result['subsamples'][] = $subsampled ? $ranges : [];
            $position += strlen($sample);
        }
    }

    return $result;
}

/**
 * Bits written most significant first, as [value, bit count] pairs, zero-padded to whole bytes.
 *
 * @param  list<array{int, int}>  $fields
 */
function av1Bits(array $fields): string
{
    $bits = implode('', array_map(fn (array $field): string => $field[1] > 0 ? str_pad(decbin($field[0]), $field[1], '0', STR_PAD_LEFT) : '', $fields));
    $bits = str_pad($bits, (int) ceil(strlen($bits) / 8) * 8, '0');

    return implode('', array_map(fn (string $byte): string => chr(bindec($byte)), str_split($bits, 8) ?: []));
}

function av1Obu(int $type, string $payload): string
{
    $size = '';

    for ($length = strlen($payload); $length >= 0x80; $length >>= 7) {
        $size .= chr(($length & 0x7F) | 0x80);
    }

    return chr(($type << 3) | 0x2).$size.chr($length).$payload;
}

/**
 * A sequence header for 1920x1080 8-bit 4:2:0 video with order hints, CDEF and loop restoration.
 */
function av1SequenceHeader(): string
{
    return av1Obu(1, av1Bits([
        [0, 3], [0, 1], [0, 1],              // profile, still picture, reduced still picture header
        [0, 1], [0, 1], [0, 5],              // timing info, initial display delay, one operating point
        [0, 12], [8, 5], [0, 1],             // operating point idc, level, tier
        [10, 4], [10, 4], [1919, 11], [1079, 11],
        [0, 1], [0, 1], [1, 1], [1, 1],      // frame ids, 128x128 superblocks, filter intra, intra edge
        [1, 1], [1, 1], [1, 1], [1, 1],      // interintra, masked compound, warped motion, dual filter
        [1, 1], [1, 1], [1, 1],              // order hints, jnt comp, ref frame mvs
        [1, 1], [1, 1], [6, 3],              // screen content tools and integer mv selected per frame, 7 order hint bits
        [0, 1], [1, 1], [1, 1],              // superres, cdef, restoration
        [0, 1], [0, 1], [0, 1], [0, 1], [0, 2], [0, 1], // 8-bit, colour, no description, limited range, chroma position, uv delta q
        [0, 1],                              // film grain
    ]));
}

/**
 * The quantization, segmentation, loop filter, CDEF, restoration and transform fields of a frame header.
 *
 * @return list<array{int, int}>
 */
function av1FrameTools(bool $intra): array
{
    return [
        [100, 8], [0, 1], [0, 1], [0, 1], [0, 1], // base q index, no deltas, no quantizer matrix
        [0, 1], [0, 1],                             // segmentation, delta q
        [10, 6], [10, 6], [5, 6], [5, 6], [0, 3],   // loop filter levels and sharpness
        [1, 1], [1, 1], ...array_fill(0, 10, [0, 1]), // loop filter deltas, none updated
        [0, 2], [0, 2], [0, 12],                    // cdef
        [1, 2], [0, 2], [0, 2], [0, 1],             // restoration for luma
        [1, 1],                                     // tx mode select
        ...($intra ? [] : [[0, 1], [0, 1]]),        // reference select, warped motion
        [0, 1],                                     // reduced tx set
        ...($intra ? [] : array_fill(0, 7, [0, 1])), // no global motion
    ];
}

/**
 * The header of a shown key frame, at the sequence's size (two tile columns) or at 640x360 (one tile).
 *
 * @return list<array{int, int}>
 */
function av1KeyFrameHeader(bool $small = false): array
{
    return [
        [0, 1], [0, 2], [1, 1], [0, 1], [0, 1],   // not an existing frame, key frame, shown, cdf updates, no screen content
        [$small ? 1 : 0, 1], [0, 7],              // frame size override, order hint
        ...($small ? [[639, 11], [359, 11]] : []),
        [0, 1], [0, 1],                           // render size, frame end cdf update
        ...($small ? [[1, 1], [0, 1], [0, 1]] : [[1, 1], [1, 1], [0, 1], [0, 1], [0, 1], [1, 2]]), // uniform tiles, 1 or 2 columns, 2-byte tile sizes
        ...av1FrameTools(true),
    ];
}
