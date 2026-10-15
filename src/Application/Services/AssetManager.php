<?php

declare(strict_types=1);

namespace Pollora\Asset\Application\Services;

use Closure;
use Pollora\Asset\Domain\Contracts\PublicPath;
use Pollora\Asset\Domain\Exceptions\AssetException;
use Pollora\Asset\Domain\Models\Asset;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;
use Pollora\Asset\Infrastructure\Services\AssetEnqueuer;
use Pollora\Asset\Infrastructure\Services\AssetFile;

/**
 * Application service for managing asset containers and asset file resolution.
 *
 * This class provides methods to add, retrieve, and manage asset containers,
 * as well as to generate asset URLs through the infrastructure layer (Vite, manifest, etc).
 */
class AssetManager
{
    /**
     * List of registered asset containers.
     *
     * @var array<string, AssetContainer>
     */
    protected array $containers = [];

    /**
     * The default asset container name.
     */
    protected ?string $defaultContainer = null;

    /**
     * Initializes the asset manager with the retrieval service.
     *
     * @param  AssetRetrievalService  $retrievalService  Service for retrieving assets
     * @param  PublicPath|null  $publicPath  Where the hot file of a container without one lives
     * @param  (Closure(): AssetEnqueuer)|null  $enqueuers  Builds the enqueuer of an asset
     */
    public function __construct(
        private readonly AssetRetrievalService $retrievalService,
        private readonly ?PublicPath $publicPath = null,
        private readonly ?Closure $enqueuers = null,
    ) {}

    /**
     * Factory method: returns an AssetEnqueuer (builder) for fluent asset management and enqueueing.
     *
     * @param  string  $handle  Asset handle
     * @param  string  $file  Asset file path
     * @return AssetEnqueuer Asset enqueuer instance
     */
    public function add(string $handle, string $file): AssetEnqueuer
    {
        if (! $this->enqueuers instanceof Closure) {
            throw new AssetException('No asset enqueuer is set: the asset manager was built without one.');
        }

        return ($this->enqueuers)()
            ->handle($handle)
            ->path($file);
    }

    /**
     * Retrieves an asset by name.
     *
     * @param  string  $name  Asset name
     * @return Asset|null Asset instance or null if not found
     */
    public function get(string $name): ?Asset
    {
        return $this->retrievalService->get($name);
    }

    /**
     * Retrieves all registered assets.
     *
     * @return array List of asset instances
     */
    public function all(): array
    {
        return $this->retrievalService->all();
    }

    /**
     * Adds a new asset container.
     *
     * @param  string  $name  Name of the container
     * @param  array  $config  Configuration for the container
     */
    public function addContainer(string $name, array $config): void
    {
        if (! isset($config['hot_file']) && $this->publicPath instanceof PublicPath) {
            $config['hot_file'] = $this->publicPath->path($name.'.hot');
        }

        $this->containers[$name] = new AssetContainer($name, $config);
    }

    /**
     * Retrieves an asset container by name.
     *
     * @param  string  $name  Name of the container
     * @return AssetContainer|null The asset container instance or null if not found
     */
    public function getContainer(string $name): ?AssetContainer
    {
        return $this->containers[$name] ?? null;
    }

    /**
     * Every asset container, keyed by name: the theme's, each plugin's and
     * module's, for tools that list where a page's assets come from.
     *
     * @return array<string, AssetContainer>
     */
    public function containers(): array
    {
        return $this->containers;
    }

    /**
     * Sets the default asset container.
     *
     * @param  string  $name  Name of the container to set as default
     */
    public function setDefaultContainer(string $name): void
    {
        $this->defaultContainer = $name;
    }

    /**
     * Gets the default asset container instance.
     *
     * @return AssetContainer|null The default asset container instance or null if not set
     */
    public function getDefaultContainer(): ?AssetContainer
    {
        return in_array($this->defaultContainer, [null, '', '0'], true) ? null : $this->getContainer($this->defaultContainer);
    }

    /**
     * Infrastructure factory for asset file URL resolution (Vite, containers, etc.).
     *
     * @param  string  $file  Asset file path
     * @return AssetFile Asset file instance for URL resolution
     */
    public function url(string $file): AssetFile
    {
        return new AssetFile($file);
    }
}
