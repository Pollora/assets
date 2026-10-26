<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when an asset is addressed to a container nobody registered.
 */
final class AssetContainerNotFound extends RuntimeException
{
    /**
     * @param  string  $containerName  The name that matches no container
     */
    public static function named(string $containerName): self
    {
        return new self(sprintf(
            "Asset container '%s' not found. Make sure you have added it via AssetManager::addContainer().",
            $containerName,
        ));
    }

    /**
     * useVite() needs a container to read the manifest of.
     */
    public static function beforeUseVite(): self
    {
        return new self("No asset container defined before useVite(). Use ->container('theme') before ->useVite().");
    }
}
