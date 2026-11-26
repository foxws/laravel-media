<?php

declare(strict_types=1);

use Foxws\Media\Executables\Capability;

it('reads the names below the table of encoders, formats and filters', function (Capability $capability, string $output, array $names) {
    expect($capability->parse($output))->toBe($names);
})->with([
    'encoders' => [Capability::Encoders, <<<'TXT'
        Encoders:
         V..... = Video
         A..... = Audio
         .....D = Supports direct rendering method 1
         ------
         V....D libx264              libx264 H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (codec h264)
         A....D aac                  AAC (Advanced Audio Coding)
        TXT, ['libx264', 'aac']],
    'muxers' => [Capability::Muxers, <<<'TXT'
        Formats:
         D.. = Demuxing supported
         .E. = Muxing supported
         ---
          E  dash            DASH Muxer
          E  mp4             MP4 (MPEG-4 Part 14)
        TXT, ['dash', 'mp4']],
    'demuxers sharing an entry' => [Capability::Demuxers, <<<'TXT'
        Formats:
         D.. = Demuxing supported
         ---
         D   matroska,webm   Matroska / WebM
         D   mov,mp4,m4a,3gp,3g2,mj2 QuickTime / MOV
        TXT, ['matroska', 'webm', 'mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2']],
    'filters' => [Capability::Filters, <<<'TXT'
        Filters:
          T.. = Timeline support
          | = Source or sink filter
          ------
         TS aap               AA->A      Apply Affine Projection algorithm to first audio stream.
         .. scale             V->V       Scale the input video size and/or convert the image format.
        TXT, ['aap', 'scale']],
    'no table' => [Capability::Encoders, 'Encoders:', []],
]);

it('reads the names below the headings of protocols, bitstream filters and hardware accelerations', function (Capability $capability, string $output, array $names) {
    expect($capability->parse($output))->toBe($names);
})->with([
    'protocols in and out' => [Capability::Protocols, "Supported file protocols:\nInput:\n  file\n  https\nOutput:\n  file\n  pipe\n", ['file', 'https', 'pipe']],
    'bitstream filters' => [Capability::BitstreamFilters, "Bitstream filters:\naac_adtstoasc\nh264_mp4toannexb\n", ['aac_adtstoasc', 'h264_mp4toannexb']],
    'hardware accelerations' => [Capability::HardwareAccelerations, "Hardware acceleration methods:\ncuda\nvaapi\n\n", ['cuda', 'vaapi']],
]);

it('names the option and the media:doctor flag of each kind', function () {
    expect(Capability::Encoders->argument())->toBe('-encoders')
        ->and(Capability::BitstreamFilters->argument())->toBe('-bsfs')
        ->and(Capability::HardwareAccelerations->singular())->toBe('hwaccel')
        ->and(array_map(fn (Capability $capability) => $capability->singular(), Capability::cases()))
        ->toBe(['encoder', 'decoder', 'muxer', 'demuxer', 'filter', 'protocol', 'bsf', 'hwaccel']);
});
