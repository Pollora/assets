<?php

declare(strict_types=1);

namespace Pollora\Asset\Infrastructure\Repositories;

use Pollora\Asset\Domain\Contracts\AssetRepositoryInterface;
use Pollora\Asset\Domain\Models\Asset;

/**
 * Infrastructure repository for storing assets in memory.
 *
 * This implementation of AssetRepositoryInterface is intended for testing,
 * prototyping, or scenarios where persistence is not required. Assets are
 * stored in a simple associative array keyed by their name/handle.
 */
class InMemoryAssetRepository implements AssetRepositoryInterface
{
    /**
     * Internal array of assets, keyed by asset name/handle.
     *
     * @var array<string, Asset>
     */
    private array $assets = [];

    /**
     * Finds an asset by its name/handle.
     *
     * @param  string  $name  The asset name/handle
     * @return Asset|null The asset instance, or null if not found
     */
    public function findByName(string $name): ?Asset
    {
        return $this->assets[$name] ?? null;
    }

    /**
     * Saves an asset instance to the repository.
     *
     * @param  Asset  $asset  The asset instance to save
     */
    public function save(Asset $asset): void
    {
        $this->assets[$asset->getName()] = $asset;
    }

    /**
     * Retrieves all assets from the repository.
     *
     * @return array<int, Asset> List of all asset instances
     */
    public function all(): array
    {
        return array_values($this->assets);
    }
}
