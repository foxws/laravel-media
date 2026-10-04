<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Disk
    |--------------------------------------------------------------------------
    |
    | The disk media is opened from when Media::open() is called without
    | fromDisk(). Set to null to use the application's default disk.
    |
    */

    'disk' => env('MEDIA_DISK'),

    /*
    |--------------------------------------------------------------------------
    | Executables
    |--------------------------------------------------------------------------
    |
    | The path or command name of each executable. An absolute path is used
    | as-is. A command name is looked up in the PATH and in the application's
    | base path, so a static binary placed in the project root is found too.
    |
    | Executables are resolved when they are first used, so only the tools
    | you actually call need to be installed.
    |
    */

    'executables' => [
        'ffmpeg' => env('MEDIA_FFMPEG_PATH', 'ffmpeg'),
        'ffprobe' => env('MEDIA_FFPROBE_PATH', 'ffprobe'),
        'packager' => env('MEDIA_PACKAGER_PATH', 'packager'),
        'ab-av1' => env('MEDIA_AB_AV1_PATH', 'ab-av1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum number of seconds a single process may run.
    |
    */

    'timeout' => (int) env('MEDIA_TIMEOUT', 14400),

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | The log channel used for process logging. Set to false to disable
    | logging, or null to use the application's default channel.
    |
    */

    'log_channel' => env('MEDIA_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | FFmpeg Log Level
    |--------------------------------------------------------------------------
    |
    | What ffmpeg writes to its error output. Successful runs that still wrote
    | something are logged as warnings on the log channel. Set this to
    | "warning" to see why output from damaged files looks wrong.
    |
    */

    'ffmpeg_log_level' => env('MEDIA_FFMPEG_LOG_LEVEL', 'error'),

    /*
    |--------------------------------------------------------------------------
    | Remote Inputs
    |--------------------------------------------------------------------------
    |
    | When enabled, media on remote disks that provide temporary URLs (such as
    | S3) is read by ffmpeg and ffprobe through a short-lived signed URL
    | instead of being downloaded first. ffprobe then only fetches the parts
    | of the file it needs.
    |
    */

    'remote_inputs' => [
        'enabled' => (bool) env('MEDIA_REMOTE_INPUTS', true),
        'url_lifetime' => (int) env('MEDIA_REMOTE_INPUTS_URL_LIFETIME', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary Files
    |--------------------------------------------------------------------------
    |
    | Where downloaded inputs and process outputs are written before they
    | are copied to their target disk.
    |
    | The cache root is used for small files such as encryption keys, and can
    | point to a RAM disk like /dev/shm. Set it to null to use the regular root.
    |
    | The minimum free space settings (in bytes) make a job fail early when a
    | size-limited mount is full. Set them to 0 to disable the check.
    |
    */

    'temporary_files' => [
        'root' => env('MEDIA_TEMPORARY_FILES_ROOT', storage_path('app/media/temp')),
        'min_free' => (int) env('MEDIA_TEMPORARY_FILES_MIN_FREE', 0),
        'size_multiplier' => (float) env('MEDIA_TEMPORARY_FILES_SIZE_MULTIPLIER', 1.5),
        'cache_root' => env('MEDIA_CACHE_FILES_ROOT'),
        'cache_min_free' => (int) env('MEDIA_CACHE_FILES_MIN_FREE', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | How results are copied to S3 disks. Up to `concurrency` files upload at
    | the same time. Files of at least `multipart_threshold` bytes are sent as
    | a multipart upload of `multipart_part_size` byte parts (at least 5 MB),
    | `multipart_concurrency` parts at a time. Multipart uploads are required
    | for objects over 5 GB, and failed ones are aborted.
    |
    | Other disks receive files one at a time.
    |
    */

    'uploads' => [
        'concurrency' => (int) env('MEDIA_UPLOADS_CONCURRENCY', 10),
        'multipart_threshold' => (int) env('MEDIA_UPLOADS_MULTIPART_THRESHOLD', 64 * 1024 * 1024),
        'multipart_part_size' => (int) env('MEDIA_UPLOADS_MULTIPART_PART_SIZE', 16 * 1024 * 1024),
        'multipart_concurrency' => (int) env('MEDIA_UPLOADS_MULTIPART_CONCURRENCY', 5),
    ],

];
