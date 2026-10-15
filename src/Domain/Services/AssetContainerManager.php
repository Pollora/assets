<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Services;

use Pollora\Asset\Domain\Contracts\AssetRepositoryInterface;
use Pollora\Asset\Domain\Models\Asset;

/**
 * Domain service for managing asset containers and asset registration/retrieval.
 *
 * This service provides methods to register assets, retrieve a single asset by name,
 * and fetch all registered assets via the configured repository implementation.
 */
class AssetContainerManager
{
    /**
     * Initializes the container manager with a repository implementation.
     *
     * @param  AssetRepositoryInterface  $repository  The asset repository
     */
    public function __construct(private readonly AssetRepositoryInterface $repository) {}

    /**
     * Registers an asset in the repository.
     *
     * @param  Asset  $asset  The asset instance to register
     */
    public function registerAsset(Asset $asset): void
    {
        $this->repository->save($asset);
    }

    /**
     * Retrieves a single asset by name.
     *
     * @param  string  $name  Asset name/handle
     * @return Asset|null The asset instance, or null if not found
     */
    public function getAsset(string $name): ?Asset
    {
        return $this->repository->findByName($name);
    }

    /**
     * Retrieves all registered assets.
     *
     * @return array List of all asset instances
     */
    public function getAllAssets(): array
    {
        return $this->repository->all();
    }
}
