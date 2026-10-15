<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Contracts;

/**
 * Contract for asset file value objects.
 *
 * This interface defines the required methods for asset file representations
 * in the domain and infrastructure layers.
 */
interface AssetFileInterface
{
    /**
     * Gets the asset file name or path.
     */
    public function getFilename(): string;

    /**
     * Gets the asset container identifier.
     */
    public function getAssetContainer(): string;

    /**
     * Sets the asset container.
     */
    public function from(string $container): static;

    /**
     * String representation (for URL or path).
     */
    public function __toString(): string;
}
