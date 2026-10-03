<?php

declare(strict_types=1);

namespace Foxws\Media\Process;

/**
 * Turns an executable's streamed output into progress updates.
 */
interface ProgressParser
{
    /**
     * Feed the next chunk of output and get the progress updates it completed.
     *
     * @return list<Progress>
     */
    public function feed(string $output): array;
}
