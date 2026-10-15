<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Contracts;

/**
 * What the assets need to know about how the code runs.
 */
interface RuntimeContext
{
    /**
     * Whether the code runs on the command line (Artisan or WP-CLI): nothing is enqueued there.
     */
    public function isCommandLine(): bool;
}
