<?php

declare(strict_types=1);

namespace Foxws\Media\Filesystem;

use Aws\CommandInterface;
use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3ClientInterface;
use Foxws\Media\Concerns\ResolvesFromContainer;
use Foxws\Media\Exceptions\ExportFailedException;
use Foxws\Media\MediaConfig;
use Generator;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Filesystem\Filesystem;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

class Exporter
{
    use ResolvesFromContainer;

    public function __construct(protected MediaConfig $config) {}

    /**
     * Copy every file in a local directory to a directory on the target disk.
     *
     * S3 disks receive files concurrently, using multipart uploads for large
     * files. With $move, files are renamed onto a local target disk instead of
     * copied, so the local directory is emptied.
     *
     * @return list<string> The written paths on the target disk.
     *
     * @throws ExportFailedException
     */
    public function export(string $localDirectory, Disk $target, string $targetDirectory = '', ?string $visibility = null, bool $move = false): array
    {
        $targetDirectory = trim($targetDirectory, '/');

        $files = new Filesystem()->allFiles($localDirectory);

        usort($files, fn (SplFileInfo $a, SplFileInfo $b): int => strcmp($a->getRelativePathname(), $b->getRelativePathname()));

        $operations = array_map(function (SplFileInfo $file) use ($targetDirectory): FileOperation {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            return new FileOperation(
                absolutePath: $file->getPathname(),
                targetPath: $targetDirectory !== '' ? "{$targetDirectory}/{$relativePath}" : $relativePath,
            );
        }, $files);

        $failures = match (true) {
            $target->isS3() => $this->uploadToS3($operations, $target, $visibility),
            $move && $target->isLocal() => $this->moveLocally($operations, $target, $visibility),
            default => $this->copySequentially($operations, $target, $visibility),
        };

        if ($failures !== []) {
            throw ExportFailedException::copyFailed($target->name(), $failures);
        }

        return array_map(fn (FileOperation $operation): string => $operation->targetPath, $operations);
    }

    /**
     * Upload files concurrently with the AWS SDK's async operations, sending
     * large files as multipart uploads. The disk's root prefix and default
     * object options are applied, so the result matches writeStream().
     *
     * @param  list<FileOperation>  $operations
     * @return list<CopyFailure>
     */
    protected function uploadToS3(array $operations, Disk $disk, ?string $visibility): array
    {
        $client = $disk->s3Client();
        $bucket = $disk->s3Bucket();
        $options = $disk->s3UploadOptions();
        $multipartThreshold = $this->config->multipartThreshold;

        $acl = match ($visibility) {
            'public' => 'public-read',
            'private' => 'private',
            default => null,
        };

        $failures = [];

        $uploads = (function () use ($operations, $client, $bucket, $disk, $options, $acl, $multipartThreshold, &$failures): Generator {
            foreach ($operations as $operation) {
                $stream = @fopen($operation->absolutePath, 'rb');

                if ($stream === false) {
                    $failures[] = new CopyFailure($operation->absolutePath, $operation->targetPath, 'Failed to open the file.');

                    continue;
                }

                $key = $disk->prefixS3Path($operation->targetPath);

                $parameters = [...$options, 'ContentType' => $this->contentType($key)];

                $promise = (int) filesize($operation->absolutePath) >= $multipartThreshold
                    ? $this->multipartUpload($client, $stream, $bucket, $key, $acl, $parameters)
                    : $client->putObjectAsync([
                        ...$parameters,
                        'Bucket' => $bucket,
                        'Key' => $key,
                        'Body' => $stream,
                        ...($acl !== null ? ['ACL' => $acl] : []),
                    ]);

                yield $promise->then(
                    function () use ($stream): void {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    },
                    function (mixed $reason) use ($stream, $operation, &$failures): void {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }

                        $failures[] = new CopyFailure(
                            $operation->absolutePath,
                            $operation->targetPath,
                            $reason instanceof Throwable ? $reason->getMessage() : (is_scalar($reason) ? (string) $reason : 'Upload failed.'),
                        );
                    },
                );
            }
        })();

        new EachPromise($uploads, ['concurrency' => $this->config->uploadConcurrency])->promise()->wait();

        return $failures;
    }

    /**
     * Start a multipart upload, aborting it on failure so orphaned parts don't keep taking up (billed) storage.
     *
     * @param  resource  $stream
     * @param  array<string, mixed>  $parameters
     */
    protected function multipartUpload(S3ClientInterface $client, mixed $stream, string $bucket, string $key, ?string $acl, array $parameters): PromiseInterface
    {
        $uploader = new MultipartUploader($client, $stream, [
            'part_size' => $this->config->multipartPartSize,
            'concurrency' => $this->config->multipartConcurrency,
            'bucket' => $bucket,
            'key' => $key,
            'acl' => $acl,
            'before_initiate' => function (CommandInterface $command) use ($parameters): void {
                foreach ($parameters as $name => $value) {
                    $command[$name] = $value;
                }
            },
        ]);

        return $uploader->promise()->otherwise(function (mixed $reason) use ($client): PromiseInterface {
            if ($reason instanceof MultipartUploadException && filled($reason->getState()->getId()['UploadId'] ?? null)) {
                try {
                    $client->abortMultipartUpload($reason->getState()->getId());
                } catch (Throwable) {
                    // The upload already failed; a bucket lifecycle rule can remove what's left.
                }
            }

            return Create::rejectionFor($reason);
        });
    }

    /**
     * Rename files onto a local disk, which is near-instant on the same filesystem.
     * Falls back to a stream copy when the rename fails.
     *
     * @param  list<FileOperation>  $operations
     * @return list<CopyFailure>
     */
    protected function moveLocally(array $operations, Disk $disk, ?string $visibility): array
    {
        $failures = [];

        foreach ($operations as $operation) {
            try {
                $directory = dirname($operation->targetPath);

                if ($directory !== '.') {
                    $disk->makeDirectory($directory);
                }

                if (! @rename($operation->absolutePath, $disk->path($operation->targetPath))) {
                    $this->writeFile($operation, $disk, $visibility);

                    continue;
                }

                if ($visibility !== null) {
                    $disk->setVisibility($operation->targetPath, $visibility);
                }
            } catch (Throwable $exception) {
                $failures[] = new CopyFailure($operation->absolutePath, $operation->targetPath, $exception->getMessage());
            }
        }

        return $failures;
    }

    /**
     * @param  list<FileOperation>  $operations
     * @return list<CopyFailure>
     */
    protected function copySequentially(array $operations, Disk $disk, ?string $visibility): array
    {
        $failures = [];

        foreach ($operations as $operation) {
            try {
                $this->writeFile($operation, $disk, $visibility);
            } catch (Throwable $exception) {
                $failures[] = new CopyFailure($operation->absolutePath, $operation->targetPath, $exception->getMessage());
            }
        }

        return $failures;
    }

    protected function writeFile(FileOperation $operation, Disk $disk, ?string $visibility): void
    {
        $stream = @fopen($operation->absolutePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Failed to open the file.');
        }

        try {
            if (! $disk->writeStream($operation->targetPath, $stream, $visibility !== null ? ['visibility' => $visibility] : [])) {
                throw new RuntimeException('The disk did not write the file.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Encryption keys are raw bytes, but the extension map would label .key files as Keynote documents.
     */
    protected function contentType(string $path): string
    {
        if (pathinfo($path, PATHINFO_EXTENSION) === 'key') {
            return 'application/octet-stream';
        }

        return new ExtensionMimeTypeDetector()->detectMimeTypeFromPath($path) ?? 'application/octet-stream';
    }
}
