<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Contracts;

use Pollora\Asset\Infrastructure\Repositories\AssetContainer;

/**
 * Contract for Vite asset management services.
 *
 * Defines the required methods for resolving asset URLs, checking hot status, and retrieving the container.
 */
interface ViteManagerInterface
{
    /**
     * Returns the asset container instance.
     */
    public function container(): AssetContainer;

    /**
     * Gets the URLs for the specified entry points.
     *
     * @param  array  $entrypoints  List of entry points to process
     * @return array Array of asset URLs grouped by type (js/css)
     */
    public function getAssetUrls(array $entrypoints): array;

    /**
     * Gets the URL for a specific asset path.
     *
     * @param  string  $path  The asset path
     * @return string The complete asset URL
     */
    public function asset(string $path): string;

    /**
     * Checks if Vite is running in hot module replacement mode.
     *
     * @return bool True if HMR is active, false otherwise
     */
    public function isRunningHot(): bool;

    /**
     * Gets the Vite client HTML script tag.
     *
     * @return string The HTML script tag for Vite client
     */
    public function getViteClientHtml(): string;

    /**
     * The URL of the Vite client on the dev server, or an empty string when Vite is not running hot.
     */
    public function clientUrl(): string;
}
