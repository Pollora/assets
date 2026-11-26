<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Models;

use Pollora\Asset\Domain\Exceptions\AssetException;
use Stringable;

/**
 * Configuration and paths for a group of related assets.
 *
 * Handles configuration settings for asset containers, including paths for hot reloading,
 * build directories, manifest files, and base asset paths. Used by infrastructure services
 * to resolve asset locations and integration with tools like Vite.
 */
final class AssetContainer
{
    /**
     * Array of asset directory paths.
     *
     * @var array<array-key, mixed>
     */
    public array $assetDir;

    /**
     * The base path for assets in this container.
     */
    public string $basePath;

    /**
     * The path to the hot reload file used by Vite.
     */
    private readonly string $hotFile;

    /**
     * The directory where built assets are stored.
     */
    private readonly string $buildDirectory;

    /**
     * The path to the Vite manifest file.
     */
    private readonly string $manifestPath;

    /**
     * The directory `build_directory` is relative to, when it is the container's own and not one shared by all.
     */
    private readonly ?string $publicPath;

    /**
     * The URL of that directory.
     */
    private readonly ?string $publicUrl;

    /**
     * Creates a new asset container instance.
     *
     * @param  string  $name  The unique identifier for this container
     * @param  array<string, mixed>  $config  Configuration options for the container
     */
    public function __construct(private readonly string $name, array $config = [])
    {
        $this->hotFile = $this->text($config, 'hot_file') ?? $this->name.'.hot';
        $this->buildDirectory = $this->text($config, 'build_directory') ?? 'build/'.$this->name;
        $this->manifestPath = $this->text($config, 'manifest_path') ?? 'manifest.json';
        $this->basePath = $this->text($config, 'base_path') ?? '';

        $publicPath = $this->text($config, 'public_path');
        $publicUrl = $this->text($config, 'public_url');
        $this->publicPath = $publicPath === null ? null : rtrim($publicPath, '/');
        $this->publicUrl = $publicUrl === null ? null : rtrim($publicUrl, '/');
    }

    /**
     * An option of the configuration, null when it is not given.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws AssetException When the option is not text
     */
    private function text(array $config, string $option): ?string
    {
        $value = $config[$option] ?? null;

        if ($value === null) {
            return null;
        }

        return is_scalar($value) || $value instanceof Stringable
            ? (string) $value
            : throw new AssetException(sprintf("The option '%s' of the asset container '%s' must be a string.", $option, $this->name));
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
     *
     * @return array<array-key, mixed>
     */
    public function getAssetDir(): array
    {
        return $this->assetDir;
    }
}

/*
 * @deprecated The former class name keeps working, and is removed in the next
 * major version. The alias is declared here, with the class, so that it exists
 * as soon as an instance does: a type declaration naming the former class
 * accepts the object without anything having autoloaded the old file.
 */
class_alias(AssetContainer::class, 'Pollora\Asset\Infrastructure\Repositories\AssetContainer');
