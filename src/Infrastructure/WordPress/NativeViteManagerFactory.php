<?php

declare(strict_types=1);

namespace Pollora\Asset\Infrastructure\WordPress;

use Pollora\Asset\Application\Services\MissingBuilds;
use Pollora\Asset\Domain\Contracts\ViteManagerFactory;
use Pollora\Asset\Domain\Contracts\ViteManagerInterface;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;

/**
 * Resolves a container from its own manifest and hot file.
 */
final readonly class NativeViteManagerFactory implements ViteManagerFactory
{
    public function __construct(private ?MissingBuilds $missingBuilds = null) {}

    public function make(AssetContainer $container): ViteManagerInterface
    {
        return new NativeViteManager($container, $this->missingBuilds);
    }
}
