<?php

declare(strict_types=1);

namespace Pollora\Asset\Infrastructure\WordPress;

use Pollora\Asset\Application\Services\MissingBuilds;
use Pollora\Asset\Domain\Contracts\ViteManagerInterface;
use Pollora\Asset\Domain\Exceptions\AssetException;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;

/**
 * Resolves the assets of a container from its Vite manifest and hot file, with no framework.
 *
 * It answers what Pollora's Laravel resolver answers, to the letter: the same
 * URLs for the same manifest and hot file, the same empty answers without a
 * build. Where Laravel builds a URL from the application, this builds it from
 * the URL of the build directory the container names, which a classic theme or
 * a Bedrock site gets from WordPress (`get_theme_file_uri()`, `plugins_url()`).
 *
 * The container says what its `build_directory` is relative to, on disk and as
 * a URL: `public_path` and `public_url`, the pair Laravel gets from `public_path()`
 * and `asset()`.
 */
final class NativeViteManager implements ViteManagerInterface
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $manifest = null;

    /**
     * @throws AssetException When the container does not say where its build is
     */
    public function __construct(
        private readonly AssetContainer $container,
        private readonly ?MissingBuilds $missingBuilds = null,
    ) {
        if ($container->getPublicPath() === null || $container->getPublicUrl() === null) {
            throw new AssetException(sprintf("The asset container '%s' has no public_path and public_url: the native Vite resolver needs both.", $container->getName()));
        }
    }

    public function container(): AssetContainer
    {
        return $this->container;
    }

    /**
     * The URLs of the entry points, by type.
     *
     * An entry point the manifest does not know is left out; without a build there are none.
     *
     * @param  array<int, string>  $entrypoints
     * @return array{js: list<string>, css: list<string>}
     *
     * @throws AssetException When there are no entry points
     */
    public function getAssetUrls(array $entrypoints): array
    {
        if ($entrypoints === []) {
            throw new AssetException('Entry points array cannot be empty.');
        }

        if ($this->isMissingBuild()) {
            return ['js' => [], 'css' => []];
        }

        $manifest = $this->manifest();
        $assets = ['js' => [], 'css' => []];

        foreach ($entrypoints as $entrypoint) {
            $chunk = $manifest[$this->container->getBasePath().ltrim($entrypoint, '/')] ?? null;

            if (! is_array($chunk) || ! isset($chunk['file'])) {
                continue;
            }

            $file = (string) $chunk['file'];
            $assets[pathinfo($file, PATHINFO_EXTENSION) === 'css' ? 'css' : 'js'][] = $this->builtUrl($file);

            foreach ((array) ($chunk['css'] ?? []) as $css) {
                $assets['css'][] = $this->builtUrl((string) $css);
            }
        }

        return [
            'js' => array_values(array_unique($assets['js'])),
            'css' => array_values(array_unique($assets['css'])),
        ];
    }

    /**
     * The URL of a file of the container: the dev server's while Vite runs hot, the built one otherwise.
     *
     * Without a build, an empty string: see isMissingBuild().
     *
     * @throws AssetException When the manifest does not list the file
     */
    public function asset(string $path): string
    {
        if ($this->isMissingBuild()) {
            return '';
        }

        $file = $this->container->getBasePath().$path;

        if ($this->isRunningHot()) {
            return $this->hotAsset($file);
        }

        $chunk = $this->manifest()[$file] ?? null;

        if (! is_array($chunk) || ! isset($chunk['file'])) {
            throw new AssetException(sprintf('Unable to locate file in Vite manifest: %s.', $file));
        }

        return $this->builtUrl((string) $chunk['file']);
    }

    /**
     * Whether the container has neither a running dev server nor a manifest.
     *
     * The missing build is recorded, so the site can say so instead of failing.
     */
    public function isMissingBuild(): bool
    {
        if ($this->isRunningHot() || is_file($this->manifestFile())) {
            return false;
        }

        $this->missingBuilds?->record($this->container->getName(), $this->manifestFile());

        return true;
    }

    public function isRunningHot(): bool
    {
        return is_file($this->container->getHotFile());
    }

    /**
     * The URL of the Vite client on the dev server, or an empty string when Vite is not running hot.
     */
    public function clientUrl(): string
    {
        return $this->isRunningHot() ? $this->hotAsset('@vite/client') : '';
    }

    /**
     * The script tag of the Vite client, or an empty string when Vite is not running hot.
     */
    public function getViteClientHtml(): string
    {
        return $this->isRunningHot() ? sprintf('<script type="module" src="%s"></script>', $this->clientUrl()) : '';
    }

    private function hotAsset(string $file): string
    {
        return rtrim((string) file_get_contents($this->container->getHotFile())).'/'.$file;
    }

    private function builtUrl(string $file): string
    {
        return $this->container->getPublicUrl().'/'.$this->buildDirectory().'/'.ltrim($file, '/');
    }

    private function buildDirectory(): string
    {
        return trim($this->container->getBuildDirectory(), '/');
    }

    private function manifestFile(): string
    {
        return $this->container->getPublicPath().'/'.$this->buildDirectory().'/'.$this->container->getManifestPath();
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $decoded = json_decode((string) file_get_contents($this->manifestFile()), true);

        return $this->manifest = is_array($decoded) ? $decoded : [];
    }
}
