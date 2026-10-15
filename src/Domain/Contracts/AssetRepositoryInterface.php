<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Contracts;

use Pollora\Asset\Domain\Models\Asset;

/**
 * Contract for asset repository implementations.
 *
 * This interface defines the required methods for any asset repository,
 * including finding, saving, and retrieving all assets. Implementations
 * may use in-memory, database, or other storage mechanisms.
 */
interface AssetRepositoryInterface
{
    /**
     * Finds an asset by its name/handle.
     *
     * @param  string  $name  The asset name/handle
     * @return Asset|null The asset instance, or null if not found
     */
    public function findByName(string $name): ?Asset;

    /**
     * Saves an asset instance to the repository.
     *
     * @param  Asset  $asset  The asset instance to save
     */
    public function save(Asset $asset): void;

    /**
     * Retrieves all assets from the repository.
     *
     * @return array List of all asset instances
     */
    public function all(): array;
}
