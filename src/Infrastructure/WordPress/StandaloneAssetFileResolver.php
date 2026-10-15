<?php

declare(strict_types=1);

namespace Pollora\Asset\Infrastructure\WordPress;

use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Domain\Contracts\ViteManagerFactory;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Turns the file of an AssetFile into its URL, through the container it names.
 *
 * An unknown container, or any error while resolving, gives an empty string and a line in the log.
 */
final readonly class StandaloneAssetFileResolver
{
    public function __construct(
        private AssetManager $assets,
        private ViteManagerFactory $viteManagers,
        private ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(string $file, string $container): string
    {
        try {
            $assetContainer = $this->assets->getContainer($container);

            if (! $assetContainer instanceof AssetContainer) {
                return '';
            }

            return $this->viteManagers->make($assetContainer)->asset($file);
        } catch (\Throwable $throwable) {
            ($this->logger ?? new NullLogger)->error('Error in AssetFile::__toString', [
                'error' => $throwable->getMessage(),
                'path' => $file,
            ]);

            return '';
        }
    }
}
