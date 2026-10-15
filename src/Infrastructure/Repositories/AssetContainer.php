<?php

declare(strict_types=1);

namespace Pollora\Asset\Infrastructure\Repositories;

/**
 * Infrastructure class for managing configuration and paths for a group of related assets.
 *
 * Handles configuration settings for asset containers, including paths for hot reloading,
 * build directories, manifest files, and base asset paths. Used by infrastructure services
 * to resolve asset locations and integration with tools like Vite.
 */
class AssetContainer
{
    /**
     * Array of asset directory paths.
     */
    public array $assetDir;

    /**
     * The base path for assets in this container.
     */
    public string $basePath;

    /**
     * The path to the hot reload file used by Vite.
     */
    protected string $hotFile;

    /**
     * The directory where built assets are stored.
     */
    protected string $buildDirectory;

    /**
     * The path to the Vite manifest file.
     */
    protected string $manifestPath;

    /**
     * The directory `build_directory` is relative to, when it is the container's own and not one shared by all.
     */
    protected ?string $publicPath;

    /**
     * The URL of that directory.
     */
    protected ?string $publicUrl;

    /**
     * Creates a new asset container instance.
     *
     * @param  string  $name  The unique identifier for this container
     * @param  array  $config  Configuration options for the container
     */
    public function __construct(protected string $name, protected array $config = [])
    {
        $this->hotFile = $config['hot_file'] ?? $this->name.'.hot';
        $this->buildDirectory = $config['build_directory'] ?? 'build/'.$this->name;
        $this->manifestPath = $config['manifest_path'] ?? 'manifest.json';
        $this->basePath = $config['base_path'] ?? '';
        $this->publicPath = isset($config['public_path']) ? rtrim((string) $config['public_path'], '/') : null;
        $this->publicUrl = isset($config['public_url']) ? rtrim((string) $config['public_url'], '/') : null;
    }

    /**
     * The directory the build directory is relative to, when the container has its own.
     *
     * A Pollora application has one public directory for every container and Laravel resolves the build in it.
     * A classic theme or a plugin is its own root: it says where that is, and the URL it is served from.
     */
    public function getPublicPath(): ?string
    {
        return $this->publicPath;
    }

    /**
     * The URL of the public directory of the container, when it has its own.
     */
    public function getPublicUrl(): ?string
    {
        return $this->publicUrl;
    }

    /**
     * Gets the base path for assets in this container.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Gets the unique name of this asset container.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the path to the hot reload file used by Vite.
     */
    public function getHotFile(): string
    {
        return $this->hotFile;
    }

    /**
     * Gets the build directory for assets.
     */
    public function getBuildDirectory(): string
    {
        return $this->buildDirectory;
    }

    /**
     * Gets the path to the Vite manifest file.
     */
    public function getManifestPath(): string
    {
        return $this->manifestPath;
    }

    /**
     * Gets the asset directory array.
     */
    public function getAssetDir(): array
    {
        return $this->assetDir;
    }
}
