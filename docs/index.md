---
title: Introduction
metadata:
  role: Media
  group: media
  eyebrow: "Video · HLS/DASH · FFmpeg"
  desc: "Probe, encode, package and stream audio and video with ffmpeg on any Laravel disk."
  requires: "PHP ^8.4"
  laravel: "13.x"
  runtime: "FFmpeg, FFprobe"
  licence: MIT
  used_by:
    name: Stry
    desc: "A self-hosted video streaming app."
    href: "https://github.com/francoism90/stry"
---

# Introduction

This package runs ffprobe and ffmpeg from Laravel on files from any Laravel disk. It probes, encodes and processes audio and video, and streams it as HLS and DASH, either straight from the stored files or packaged ahead of time.

```php
use Foxws\Media\Encoding\Format;
use Foxws\Media\Facades\Media;

Media::fromDisk('s3')
    ->open('videos/clip.mp4')
    ->ffmpeg()
    ->clip(from: 12.5, to: 40)
    ->inFormat(Format::h264(crf: 22))
    ->toDisk('clips')
    ->save('intro.mp4');
```

It builds ffmpeg commands directly, without php-ffmpeg underneath, so any ffmpeg option is available without waiting on a package release.

## Features

- **Probe** files into typed streams, chapters and formats, and validate uploads by what they really contain.
- **Encode** with presets, filters, watermarks, HDR to SDR tone mapping, several outputs in one run, and rendition ladders with optional GPU encoding.
- **Process** clips, frames, subtitles, scene detection, reels and seek-preview thumbnail sprites.
- **Stream** HLS (CMAF or MPEG-TS) and DASH straight from the stored files, cutting segments on request, with per-request encryption, subtitles, thumbnails, chapters, trick play and multiple audio languages.
- **Package** renditions into static HLS and DASH with ClearKey encryption, and serve private manifests with signed URLs.
- **Export** to local or S3 disks with concurrent multipart uploads, and follow progress, events and retryable failures from queued jobs.
- **Test** without ffmpeg using `Media::fake()`.

## Streaming or packaging

Most apps don't need a packaging step. [Streaming](streaming.md) serves the stored files as HLS and DASH directly: each segment is copied out of the file the first time a player asks for it, and cached. [Packaging](packaging.md) writes every segment and manifest to a disk ahead of time, for when files have to be served without the app, for example from a CDN.

Both need files encoded for streaming: H.264 or HEVC with AAC, at a few sizes. A [rendition ladder](encoding.md#rendition-ladders) encodes those in one run.

## Requirements

- PHP 8.4 or higher, with the OpenSSL extension
- Laravel 13
- ffmpeg and ffprobe

## Pages

- [Installation](installation.md)
- [Opening and probing](probing.md)
- [Encoding](encoding.md)
- [Streaming](streaming.md)
- [Packaging](packaging.md)
- [Queues, progress and errors](queues.md)
- [Testing](testing.md)
- [Configuration](configuration.md)
- [Extending](extending.md)
