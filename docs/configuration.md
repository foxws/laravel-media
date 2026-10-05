# Configuration

Publish the config file with `php artisan vendor:publish --tag="media-config"`. Every key has an environment variable, so most apps only set those.

## General

| Key | Variable | Default | Purpose |
| --- | --- | --- | --- |
| `disk` | `MEDIA_DISK` | the app's default disk | The disk `Media::open()` uses |
| `executables.ffmpeg` | `MEDIA_FFMPEG_PATH` | `ffmpeg` | Path or command name |
| `executables.ffprobe` | `MEDIA_FFPROBE_PATH` | `ffprobe` | Path or command name |
| `packager.default` | `MEDIA_PACKAGER` | `native` | The [packaging](packaging.md) driver |
| `timeout` | `MEDIA_TIMEOUT` | `14400` | Seconds a process may run; keep it at or below your jobs' `$timeout` |
| `log_channel` | `MEDIA_LOG_CHANNEL` | the default channel | Where runs are logged; `false` turns logging off |
| `ffmpeg_log_level` | `MEDIA_FFMPEG_LOG_LEVEL` | `error` | ffmpeg's `-loglevel`; `warning` logs warnings of successful runs |

## Ladders

| Key | Variable | Default | Purpose |
| --- | --- | --- | --- |
| `ladder.hardware` | `MEDIA_LADDER_HARDWARE` | `none` | `none`, `vaapi`, `nvenc` or `qsv` |
| `ladder.vaapi_device` | `MEDIA_LADDER_VAAPI_DEVICE` | `/dev/dri/renderD128` | The render device for VAAPI |

## Streaming

| Key | Variable | Default | Purpose |
| --- | --- | --- | --- |
| `delivery.segment_duration` | `MEDIA_DELIVERY_SEGMENT_DURATION` | `6` | Target segment length in seconds |
| `delivery.cache_disk` | `MEDIA_DELIVERY_CACHE_DISK` | `local` | Where packaged segments are kept |
| `delivery.cache_path` | `MEDIA_DELIVERY_CACHE_PATH` | `media-segments` | The directory on that disk |
| `delivery.url_lifetime` | `MEDIA_DELIVERY_URL_LIFETIME` | `3600` | Lifetime of signed and temporary URLs, and of cache headers |
| `delivery.lock_timeout` | `MEDIA_DELIVERY_LOCK_TIMEOUT` | `120` | How long requests wait for a segment that's being packaged |
| `delivery.cache_store` | `MEDIA_DELIVERY_CACHE_STORE` | the default store | Where probes and keyframe indexes are kept |
| `delivery.index_lifetime` | `MEDIA_DELIVERY_INDEX_LIFETIME` | `604800` | How long they're kept |
| `delivery.look_ahead` | `MEDIA_DELIVERY_LOOK_AHEAD` | `2` | Segments packaged ahead of the player |
| `delivery.look_ahead_via` | `MEDIA_DELIVERY_LOOK_AHEAD_VIA` | `queue` | `queue`, `defer` (after the response) or `null` (off) |
| `delivery.look_ahead_connection` | `MEDIA_DELIVERY_LOOK_AHEAD_CONNECTION` | the default connection | Queue connection of the look-ahead job |
| `delivery.look_ahead_queue` | `MEDIA_DELIVERY_LOOK_AHEAD_QUEUE` | the default queue | Queue of the look-ahead job |

## Remote inputs

| Key | Variable | Default | Purpose |
| --- | --- | --- | --- |
| `remote_inputs.enabled` | `MEDIA_REMOTE_INPUTS` | `true` | Read disks with temporary URLs, like S3, through signed URLs instead of downloading |
| `remote_inputs.url_lifetime` | `MEDIA_REMOTE_INPUTS_URL_LIFETIME` | `3600` | Lifetime of those URLs |

## Temporary files

| Key | Variable | Default | Purpose |
| --- | --- | --- | --- |
| `temporary_files.root` | `MEDIA_TEMPORARY_FILES_ROOT` | `storage/app/media/temp` | Where downloads and outputs are written before they're copied to their disk |
| `temporary_files.min_free` | `MEDIA_TEMPORARY_FILES_MIN_FREE` | `0` | Bytes that must stay free; `0` turns the check off |
| `temporary_files.size_multiplier` | `MEDIA_TEMPORARY_FILES_SIZE_MULTIPLIER` | `1.5` | Expected output size relative to the input, for that check |
| `temporary_files.cache_root` | `MEDIA_CACHE_FILES_ROOT` | the regular root | Where small files like keys are written, e.g. `/dev/shm` |
| `temporary_files.cache_min_free` | `MEDIA_CACHE_FILES_MIN_FREE` | `0` | Bytes that must stay free there |
| `temporary_files.cleanup_after_jobs` | `MEDIA_CLEANUP_AFTER_JOBS` | `true` | Delete temporary files after every queue job |

## Uploads

| Key | Variable | Default | Purpose |
| --- | --- | --- | --- |
| `uploads.concurrency` | `MEDIA_UPLOADS_CONCURRENCY` | `10` | Files uploaded to S3 at once |
| `uploads.multipart_threshold` | `MEDIA_UPLOADS_MULTIPART_THRESHOLD` | 64 MB | Files from this size are uploaded in parts |
| `uploads.multipart_part_size` | `MEDIA_UPLOADS_MULTIPART_PART_SIZE` | 16 MB | Size of each part, at least 5 MB |
| `uploads.multipart_concurrency` | `MEDIA_UPLOADS_MULTIPART_CONCURRENCY` | `5` | Parts uploaded at once |
| `uploads.rollback_on_failure` | `MEDIA_UPLOADS_ROLLBACK_ON_FAILURE` | `true` | Delete what an export already uploaded when another file fails |
