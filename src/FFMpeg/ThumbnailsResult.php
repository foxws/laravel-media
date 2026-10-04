<?php

declare(strict_types=1);

namespace Foxws\Media\FFMpeg;

use Foxws\Media\Casts\ThumbnailsResultCast;
use Foxws\Media\Filesystem\Disk;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Support\Arrayable;

/**
 * The sprite sheets and WebVTT file of a thumbnails export. Cast a JSON column with
 * `ThumbnailsResult::class` to keep it on a model.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ThumbnailsResult implements Arrayable, Castable
{
    /**
     * @param  list<string>  $sprites
     * @param  int  $width  The width of one thumbnail.
     * @param  int  $height  The height of one thumbnail.
     */
    public function __construct(
        public Disk $disk,
        public array $sprites,
        public string $vtt,
        public float $interval,
        public int $count,
        public int $columns = 10,
        public int $rows = 10,
        public int $width = 160,
        public int $height = 90,
    ) {}

    /**
     * Restore a result stored with toArray(), e.g. from a JSON column, on its disk or another one.
     *
     * @param  array<string, mixed>  $result
     */
    public static function fromArray(array $result, Disk|Filesystem|string|null $disk = null): self
    {
        /** @var list<string> $sprites */
        $sprites = array_values(array_map(strval(...), (array) ($result['sprites'] ?? [])));

        return new self(
            disk: Disk::make($disk ?? (string) ($result['disk'] ?? '')),
            sprites: $sprites,
            vtt: (string) ($result['vtt'] ?? ''),
            interval: (float) ($result['interval'] ?? 0),
            count: (int) ($result['count'] ?? 0),
            columns: (int) ($result['columns'] ?? 10),
            rows: (int) ($result['rows'] ?? 10),
            width: (int) ($result['width'] ?? 160),
            height: (int) ($result['height'] ?? 90),
        );
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public static function castUsing(array $arguments): ThumbnailsResultCast
    {
        return new ThumbnailsResultCast;
    }

    /**
     * Every written path: the sprite sheets, then the WebVTT file.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return [...$this->sprites, $this->vtt];
    }

    /**
     * The number of thumbnails on a full sheet.
     */
    public function perSheet(): int
    {
        return $this->columns * $this->rows;
    }

    /**
     * The seconds a sheet covers; the last sheet may hold fewer thumbnails.
     */
    public function sheetDuration(int $sheet): float
    {
        $thumbnails = min($this->perSheet(), max(0, $this->count - $sheet * $this->perSheet()));

        return $thumbnails * $this->interval;
    }

    /**
     * The image format of the sheets, from their extension.
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->sprites[0] ?? '', PATHINFO_EXTENSION)) ?: 'jpg';
    }

    /**
     * @return array{disk: string, sprites: list<string>, vtt: string, interval: float, count: int, columns: int, rows: int, width: int, height: int}
     */
    public function toArray(): array
    {
        return [
            'disk' => $this->disk->name(),
            'sprites' => $this->sprites,
            'vtt' => $this->vtt,
            'interval' => $this->interval,
            'count' => $this->count,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
