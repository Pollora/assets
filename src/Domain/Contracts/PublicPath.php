<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Contracts;

/**
 * Where built assets and hot files live on disk.
 */
interface PublicPath
{
    /**
     * The absolute path of a file or directory under the public directory.
     */
    public function path(string $to = ''): string;
}
