<?php

declare(strict_types=1);

namespace Pollora\Asset\Domain\Contracts;

/**
 * Fluent builder of an asset that WordPress enqueues.
 *
 * The application layer hands one out without knowing what enqueues it: the
 * infrastructure layer implements it with WordPress.
 */
interface AssetEnqueuerInterface
{
    /**
     * Sets the asset handle (unique identifier).
     */
    public function handle(string $handle): self;

    /**
     * Sets the asset path and determines the type.
     */
    public function path(string $path): self;

    /**
     * Sets the asset container by name.
     *
     * @throws \RuntimeException If the container is not found
     */
    public function container(string $containerName): self;

    /**
     * Sets asset dependencies.
     *
     * @param  array  $dependencies  Array of dependency handles
     */
    public function dependencies(array $dependencies): self;

    /**
     * Enables Vite.js integration for this asset.
     *
     * @throws \RuntimeException If no asset container is defined
     */
    public function useVite(): self;

    /**
     * Sets the asset version for cache busting.
     */
    public function version(string $version): self;

    /**
     * Sets the media type for stylesheets.
     */
    public function media(string $media): self;

    /**
     * Sets script to load in the footer.
     */
    public function loadInFooter(): self;

    /**
     * Sets the loading strategy for scripts.
     */
    public function loadStrategy(string $strategy): self;

    /**
     * Sets the asset type manually ('css' or 'js').
     */
    public function setType(string $type): self;

    /**
     * Enqueues the asset in WordPress frontend (wp_enqueue_scripts).
     */
    public function toFrontend(): self;

    /**
     * Enqueues the asset in WordPress admin area (admin_enqueue_scripts).
     */
    public function toBackend(): self;

    /**
     * Enqueues the asset on the login screen (login_enqueue_scripts).
     */
    public function toLoginScreen(): self;

    /**
     * Enqueues the asset in the customizer preview (customize_preview_init).
     */
    public function toCustomizer(): self;

    /**
     * Enqueues the asset in the block editor (enqueue_block_editor_assets).
     */
    public function toEditor(): self;

    /**
     * Localizes a JavaScript file with data.
     *
     * @param  array<string, mixed>  $data
     */
    public function localize(string $objectName, array $data): self;

    /**
     * Adds inline code to the asset.
     */
    public function inline(string $content, string $position = 'after'): self;

    /**
     * Enqueues all styles and scripts for the current asset.
     */
    public function enqueueStyleOrScript(?string $hook = null): void;

    /**
     * Configures asset paths for Vite integration.
     */
    public function configureViteAssets(): void;
}
