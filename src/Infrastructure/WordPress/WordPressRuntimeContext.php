<?php

declare(strict_types=1);

namespace Pollora\Asset\Infrastructure\WordPress;

use Pollora\Asset\Domain\Contracts\RuntimeContext;

/**
 * The command line, as WordPress runs it: WP-CLI, or any PHP CLI script.
 */
final class WordPressRuntimeContext implements RuntimeContext
{
    public function isCommandLine(): bool
    {
        return (defined('WP_CLI') && constant('WP_CLI')) || PHP_SAPI === 'cli';
    }
}
