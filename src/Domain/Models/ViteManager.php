<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Models;

use Pollora\Asset\Domain\Contracts\ViteManagerInterface;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;

/**
 * Domain-only (mock or stub) implementation of ViteManagerInterface.
 *
 * Use for testing or pure domain logic.
 */
class ViteManager implements ViteManagerInterface
{
    /**
     * Returns a stub asset container instance.
     */
    public function container(): AssetContainer
    {
        return new AssetContainer('stub', []);
    }

    /**
     * Returns an empty array for entry point URLs (stub).
     */
    public function getAssetUrls(array $entrypoints): array
    {
        return [];
    }

    /**
     * Returns the asset path as-is (stub).
     */
    public function asset(string $path): string
    {
        return $path;
    }

    /**
     * Always returns false (stub for domain logic/testing).
     */
    public function isRunningHot(): bool
    {
        return false;
    }

    /**
     * Returns an empty string for the Vite client HTML (stub).
     */
    public function getViteClientHtml(): string
    {
        return '';
    }

    /**
     * Returns an empty string for the Vite client URL (stub).
     */
    public function clientUrl(): string
    {
        return '';
    }
}
