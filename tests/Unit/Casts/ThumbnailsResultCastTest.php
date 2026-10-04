<?php

declare(strict_types=1);

use Foxws\Media\Casts\ThumbnailsResultCast;
use Foxws\Media\FFMpeg\ThumbnailsResult;
use Foxws\Media\Filesystem\Disk;
use Illuminate\Database\Eloquent\Model;

/**
 * A model with a thumbnails column cast to a ThumbnailsResult.
 */
function thumbnailsModel(): Model
{
    return new class extends Model
    {
        protected $guarded = [];

        protected function casts(): array
        {
            return ['thumbnails' => ThumbnailsResult::class];
        }
    };
}

it('stores a result as json and reads it back', function () {
    $result = new ThumbnailsResult(Disk::make('videos'), ['sb_001.jpg', 'sb_002.jpg'], 'sb.vtt', 2.0, 6, columns: 2, rows: 2);
    $model = thumbnailsModel();

    $model->thumbnails = $result;

    expect(json_decode($model->getAttributes()['thumbnails'], true))->toBe($result->toArray())
        ->and($model->getAttributes()['thumbnails'])->toContain('"interval":2.0')
        ->and($model->thumbnails)->toBeInstanceOf(ThumbnailsResult::class)
        ->and($model->thumbnails->toArray())->toBe($result->toArray());
});

it('accepts a stored array and null', function () {
    $model = thumbnailsModel();

    $model->thumbnails = ['disk' => 'videos', 'sprites' => ['sb_001.jpg'], 'vtt' => 'sb.vtt', 'interval' => 5, 'count' => 10];

    expect($model->thumbnails->sprites)->toBe(['sb_001.jpg'])
        ->and($model->thumbnails->interval)->toBe(5.0);

    $model->thumbnails = null;

    expect($model->thumbnails)->toBeNull()
        ->and($model->getAttributes()['thumbnails'])->toBeNull();
});

it('reads empty columns as null', function (?string $value) {
    expect(new ThumbnailsResultCast()->get(thumbnailsModel(), 'thumbnails', $value, []))->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'empty json object' => ['{}'],
    'empty json array' => ['[]'],
]);
