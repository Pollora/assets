<?php

declare(strict_types=1);

namespace Pollora\Asset\Application\Services;

use Pollora\Asset\Domain\Models\Asset;
use Pollora\Asset\Domain\Services\AssetContainerManager;

/**
 * Application service for registering assets into asset containers.
 *
 * This service acts as a use case boundary for asset registration, ensuring
 * that assets are properly instantiated and delegated to the container manager.
 */
class AssetRegistrationService
{
    /**
     * Initializes the registration service with the container manager.
     *
     * @param  AssetContainerManager  $manager  The asset container manager
     */
    public function __construct(private readonly AssetContainerManager $manager) {}

    /**
     * Registers a new asset in the container manager.
     *
     * @param  string  $name  The asset name/handle
     * @param  string  $path  The asset file path
     * @param  array  $attributes  Optional attributes (e.g., dependencies, type)
     */
    public function register(string $name, string $path, array $attributes = [], string $assetClass = Asset::class): void
    {
        $asset = new $assetClass($name, $path, $attributes);
        $this->manager->registerAsset($asset);
    }
}
