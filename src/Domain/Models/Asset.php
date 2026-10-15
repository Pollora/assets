<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Models;

/**
 * Domain model representing an asset (CSS, JS, image, etc.).
 *
 * Encapsulates the asset's name, file path, and additional attributes (such as type, version, dependencies, etc.).
 * Used throughout the domain and application layers to represent a single asset entity.
 */
class Asset
{
    /**
     * Initializes a new asset instance.
     *
     * @param  string  $name  The unique name or handle of the asset
     * @param  string  $path  The file path or URL to the asset
     * @param  array  $attributes  Additional attributes (optional)
     */
    public function __construct(private readonly string $name, private readonly string $path, private readonly array $attributes = []) {}

    /**
     * Gets the asset's unique name or handle.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the file path or URL to the asset.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Gets the asset's additional attributes.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}
