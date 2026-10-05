---
section: Usage
order: 6
---

# Testing

`Media::fake()` runs nothing, so tests don't need ffmpeg. Probes return fake data, ffmpeg writes placeholder files to the target disk, and every command is recorded. Events, progress and failures behave as they do in production.

```php
use Foxws\Media\Executables\Executable;
use Foxws\Media\Facades\Media;
use Foxws\Media\Testing\FakeProbe;

it('creates a clip', function () {
    Storage::fake('videos');
    Storage::fake('clips');

    Media::fake([
        'uploads/movie.mkv' => FakeProbe::video(duration: 120, subtitles: ['eng', 'nld']),
        'uploads/song.mp3' => FakeProbe::audio(duration: 200),
    ]);

    CreateClip::run($video);

    Media::assertProbed('uploads/movie.mkv');
    Media::assertSaved('clips/1.mp4', 'clips');
    Media::assertRan(Executable::FFMpeg, fn (array $arguments) => in_array('libx264', $arguments, true));
});
```

## Fake probes

- Probes are matched against the end of the opened path. Unknown paths probe as a one-minute 1080p H.264 video with AAC audio.
- `'*'` fakes every probe, e.g. for uploads with random temporary names: `Media::fake(['*' => FakeProbe::video(duration: 5)])`.
- `FakeProbe::video()` takes `duration`, `width`, `height`, `codec`, `frameRate`, `audio: false`, `subtitles`, `audioLanguages` (one audio stream per language) and `transfer: 'smpte2084'` for HDR. `FakeProbe::audio()` takes `duration` and `codec`.
- `$fake->scenes('movie.mkv', [12.5, 40.0])` fakes scene changes, where `$fake` is what `Media::fake()` returned. Calling `Media::fake()` again starts a new fake.

## Failures

`$fake->failNext(Executable::FFMpeg, 'Invalid data found')` makes the next run throw `ProcessFailedException`, to test failure handling and retries.

## Assertions

- `assertRan()`, `assertNotRan()`, `assertRanTimes()` and `assertNothingRan()` check the commands.
- `assertProbed()`, `assertSaved()` and `assertNotSaved()` check files.
- `$fake->commands(Executable::FFMpeg)` lists the recorded arguments.
- `onProgress()` callbacks receive 50% and 100%.
