# Changelog

All notable changes to `laravel-media` will be documented in this file.

## 0.1.1 - 2026-10-05

<!-- Release notes generated using configuration in .github/release.yml at 2e9004fcb3abe65c6a3ace2df3f6953c572a5dcb -->
### What's Changed

#### Other Changes

* Fit the thumbnail grid to a single sheet's thumbnails by @francoism90 in https://github.com/foxws/laravel-media/pull/44

**Full Changelog**: https://github.com/foxws/laravel-media/compare/0.1.0...0.1.1

## 0.1.0 - 2026-10-05

<!-- Release notes generated using configuration in .github/release.yml at cdf916008b40d36a1a91ed526d3b0775a78b347d -->
### What's Changed

#### Other Changes

* Add command inspection and save callbacks by @francoism90 in https://github.com/foxws/laravel-media/pull/1
* Upload results to S3 concurrently by @francoism90 in https://github.com/foxws/laravel-media/pull/2
* Add bitrate, two-pass and stream options to formats by @francoism90 in https://github.com/foxws/laravel-media/pull/3
* Add video and audio filters with watermark overlays by @francoism90 in https://github.com/foxws/laravel-media/pull/4
* Write several outputs from one ffmpeg run by @francoism90 in https://github.com/foxws/laravel-media/pull/5
* Generate thumbnail sprite sheets with a WebVTT file by @francoism90 in https://github.com/foxws/laravel-media/pull/6
* Detect scenes and join clips or files into reels by @francoism90 in https://github.com/foxws/laravel-media/pull/7
* Resolve configured executable paths and run the tests on Windows by @francoism90 in https://github.com/foxws/laravel-media/pull/8
* Accept any configured file as executable on Windows by @francoism90 in https://github.com/foxws/laravel-media/pull/9
* Report progress while ffmpeg runs by @francoism90 in https://github.com/foxws/laravel-media/pull/10
* Tone map HDR video to SDR by @francoism90 in https://github.com/foxws/laravel-media/pull/11
* Add Media::fake() for testing apps without ffmpeg by @francoism90 in https://github.com/foxws/laravel-media/pull/12
* Classify failures, report their context and add per-call timeouts by @francoism90 in https://github.com/foxws/laravel-media/pull/13
* Clean up after queue jobs and stop ffmpeg before a worker is killed by @francoism90 in https://github.com/foxws/laravel-media/pull/15
* Dispatch export and progress events, and cancel from progress callbacks by @francoism90 in https://github.com/foxws/laravel-media/pull/16
* Add a MediaFile validation rule and a Media section to php artisan about by @francoism90 in https://github.com/foxws/laravel-media/pull/17
* Package encoded media into HLS and DASH with Shaka Packager by @francoism90 in https://github.com/foxws/laravel-media/pull/18
* Encrypt packaged segments with AES keys by @francoism90 in https://github.com/foxws/laravel-media/pull/19
* Add typed Shaka Packager options for DRM, live DASH and base URLs by @francoism90 in https://github.com/foxws/laravel-media/pull/20
* Serve HLS and DASH manifests with signed URLs per request by @francoism90 in https://github.com/foxws/laravel-media/pull/21
* Index keyframes and plan copyable segments by @francoism90 in https://github.com/foxws/laravel-media/pull/22
* Stream HLS straight from stored files, packaging segments on request by @francoism90 in https://github.com/foxws/laravel-media/pull/23
* Encrypt direct HLS segments per request with AES-128 by @francoism90 in https://github.com/foxws/laravel-media/pull/24
* Serve direct HLS through Route::mediaStream() and prune the segment cache by @francoism90 in https://github.com/foxws/laravel-media/pull/25
* Stream fragmented MP4 and DASH straight from stored files by @francoism90 in https://github.com/foxws/laravel-media/pull/26
* Offer WebVTT subtitles in direct HLS and DASH streams by @francoism90 in https://github.com/foxws/laravel-media/pull/27
* Offer thumbnail sprite sheets as image tracks in direct streams by @francoism90 in https://github.com/foxws/laravel-media/pull/28
* Offer chapters, scenes and markers in direct HLS and DASH streams by @francoism90 in https://github.com/foxws/laravel-media/pull/29
* Remember probes per file version for direct streams by @francoism90 in https://github.com/foxws/laravel-media/pull/30
* Package direct stream segments ahead of their requests by @francoism90 in https://github.com/foxws/laravel-media/pull/31
* Write a track's initialization segment once by @francoism90 in https://github.com/foxws/laravel-media/pull/32
* Sample thumbnails from keyframes only by @francoism90 in https://github.com/foxws/laravel-media/pull/33
* Encrypt CMAF and DASH direct streams with ClearKey by @francoism90 in https://github.com/foxws/laravel-media/pull/35
* Let add-on packages bring their own executables and macros by @francoism90 in https://github.com/foxws/laravel-media/pull/36
* Add a native packager that needs only ffmpeg by @francoism90 in https://github.com/foxws/laravel-media/pull/37
* Remove the Shaka Packager driver by @francoism90 in https://github.com/foxws/laravel-media/pull/38
* Encode rendition ladders in one ffmpeg run by @francoism90 in https://github.com/foxws/laravel-media/pull/39
* Serve direct stream chapters as WebVTT by @francoism90 in https://github.com/foxws/laravel-media/pull/40
* Add trick play with I-frame playlists by @francoism90 in https://github.com/foxws/laravel-media/pull/41
* Offer every audio stream as a track to pick by language by @francoism90 in https://github.com/foxws/laravel-media/pull/42
* Document the package by @francoism90 in https://github.com/foxws/laravel-media/pull/43

### New Contributors

* @francoism90 made their first contribution in https://github.com/foxws/laravel-media/pull/1

**Full Changelog**: https://github.com/foxws/laravel-media/commits/0.1.0
