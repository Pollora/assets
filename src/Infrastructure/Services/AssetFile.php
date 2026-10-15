<?php

declare(strict_types=1);

namespace Pollora\Asset\Infrastructure\Services;

use Closure;
use Pollora\Asset\Domain\Contracts\AssetFileInterface;
use Pollora\Asset\Domain\Models\AssetFile as DomainAssetFile;

/**
 * Infrastructure implementation for asset file URL resolution (Vite, containers, etc.).
 *
 * This class extends the domain AssetFile and implements the AssetFileInterface contract.
 * It resolves the public URL of an asset using the configured container and Vite integration.
 */
class AssetFile extends DomainAssetFile implements AssetFileInterface
{
    /**
     * What turns a file and the name of its container into a URL, set by the environment.
     *
     * @var (Closure(string, string): string)|null
     */
    private static ?Closure $resolver = null;

    /**
     * Set what resolves the URL of an asset file, null to remove it.
     *
     * An AssetFile is built with `new` wherever a theme names a file, so what resolves it is global.
     *
     * @param  (Closure(string, string): string)|null  $resolver  Receives the file and the container name
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Converts the asset file to its URL string representation using infrastructure logic.
     *
     * @return string The generated asset URL
     */
    public function __toString(): string
    {
        return self::$resolver instanceof Closure ? (self::$resolver)($this->filename, $this->assetContainer) : '';
    }
}
