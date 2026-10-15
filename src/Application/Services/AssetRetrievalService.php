<?php

declare(strict_types=1);

namespace Pollora\Asset\Application\Services;

use Pollora\Asset\Domain\Models\Asset;
use Pollora\Asset\Domain\Services\AssetContainerManager;

/**
 * Application service for retrieving assets from asset containers.
 *
 * This service acts as a use case boundary for asset retrieval, providing methods
 * to fetch individual assets or all assets registered in the container manager.
 */
class AssetRetrievalService
{
    /**
     * Initializes the retrieval service with the container manager.
     *
     * @param  AssetContainerManager  $manager  The asset container manager
     */
    public function __construct(private readonly AssetContainerManager $manager) {}

    /**
     * Retrieves a single asset by name.
     *
     * @param  string  $name  Asset name/handle
     * @return Asset|null The asset instance, or null if not found
     */
    public function get(string $name): ?Asset
    {
        return $this->manager->getAsset($name);
    }

    /**
     * Retrieves all registered assets.
     *
     * @return array List of all asset instances
     */
    public function all(): array
    {
        return $this->manager->getAllAssets();
    }
}
