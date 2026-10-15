<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Models;

use Pollora\Asset\Domain\Contracts\AssetFileInterface;

/**
 * Domain model representing a file asset.
 *
 * This class encapsulates the basic properties of an asset file (filename, content, container),
 * and provides methods for container assignment and content access. URL resolution and infrastructure
 * logic should be handled in the infrastructure layer.
 */
class AssetFile implements AssetFileInterface
{
    /**
     * The asset container identifier.
     */
    protected string $assetContainer = 'theme';

    /**
     * The asset file content (if applicable).
     */
    protected string $content;

    /**
     * Initializes a new asset file instance.
     *
     * @param  string  $filename  The file name or relative path
     */
    public function __construct(protected string $filename) {}

    /**
     * Gets the asset file name or path.
     *
     * @return string The file name or path
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * Gets the asset container identifier.
     *
     * @return string The container identifier
     */
    public function getAssetContainer(): string
    {
        return $this->assetContainer;
    }

    /**
     * Sets the asset container to use.
     *
     * @param  string  $container  The container identifier
     */
    public function from(string $container): static
    {
        $this->assetContainer = $container;

        return $this;
    }

    /**
     * Converts the asset file to its string representation (filename).
     *
     * @return string The file name or path
     */
    public function __toString(): string
    {
        return $this->filename;
    }
}
