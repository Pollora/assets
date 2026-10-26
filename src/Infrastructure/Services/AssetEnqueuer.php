<?php

declare(strict_types=1);

namespace Pollora\Asset\Infrastructure\Services;

use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Domain\Contracts\RuntimeContext;
use Pollora\Asset\Domain\Contracts\ViteManagerFactory;
use Pollora\Asset\Domain\Contracts\ViteManagerInterface;
use Pollora\Asset\Domain\Exceptions\AssetContainerNotFound;
use Pollora\Asset\Domain\Exceptions\AssetException;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;
use Pollora\Hook\Domain\Contract\Action as HookAction;
use Pollora\Hook\Domain\Contract\Filter as HookFilter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles the registration and enqueuing of CSS and JavaScript assets in WordPress.
 *
 * This class provides a fluent interface for managing assets, supporting both traditional
 * WordPress enqueuing and Vite.js integration. It handles various asset loading scenarios
 * including dependencies, versioning, and conditional loading.
 */
class AssetEnqueuer
{
    /**
     * Hooks on whose pages WordPress prints script modules, after its import map.
     *
     * A Vite entry is an ES module. Enqueued as a classic script it was printed in
     * the head, before the import map — which Firefox and Safari then ignore, so any
     * WordPress module on the page (the navigation block's, for one) failed to
     * resolve `@wordpress/interactivity`. Enqueued as a script module, WordPress
     * places it itself: after the import map, in the head of a block theme (or its
     * footer with loadInFooter()), always in the footer of a classic theme.
     *
     * The editor, the login screen and the Customizer print no script modules, so
     * Vite entries there stay classic scripts.
     */
    private const array SCRIPT_MODULE_HOOKS = ['wp_enqueue_scripts', 'admin_enqueue_scripts'];

    /**
     * The asset path or array of paths.
     *
     * @var string|array<string>
     */
    protected string|array $path;

    /**
     * The type of asset ('css' or 'js').
     */
    protected string $type;

    /**
     * Array of asset handles that this asset depends on.
     *
     * @var array<string>
     */
    protected array $dependencies = [];

    /**
     * The asset container instance.
     */
    protected ?AssetContainer $container = null;

    /**
     * The Vite manager instance.
     */
    protected ?ViteManagerInterface $viteManager = null;

    /**
     * Whether to use Vite.js for asset handling.
     */
    protected bool $useVite = false;

    /**
     * The version string for cache busting.
     */
    protected ?string $version = null;

    /**
     * The media query for stylesheet (e.g., 'all', 'print', 'screen').
     */
    protected string $media = 'all';

    /**
     * Whether to load the script in the footer.
     */
    protected bool $loadInFooter = false;

    /**
     * The loading strategy for scripts (e.g., 'defer', 'async').
     */
    protected ?string $loadStrategy = null;

    /**
     * The content to be added inline with the asset.
     */
    protected ?string $inlineContent = null;

    /**
     * The position for inline content ('before' or 'after').
     */
    protected ?string $inlinePosition = null;

    /**
     * Array of WordPress hooks where the asset should be enqueued.
     *
     * @var array<string>
     */
    protected array $hooks = [];

    /**
     * The asset handle (unique identifier).
     */
    protected string $handle;

    /**
     * Stored localization data to apply after enqueuing.
     *
     * @var array<string, array>
     */
    protected array $localizationData = [];

    /**
     * @param  AssetManager  $assetManager  Resolves the containers by name
     * @param  HookAction|null  $action  Registers the enqueue callbacks on WordPress hooks
     * @param  HookFilter|null  $filter  Registers the tag and script attribute filters
     * @param  ViteManagerFactory|null  $viteManagers  Builds the Vite resolver of a container for useVite()
     * @param  RuntimeContext|null  $runtime  Tells the command line, where nothing is enqueued
     * @param  LoggerInterface|null  $logger  Where a failure of the deferred enqueue is reported
     */
    public function __construct(
        protected AssetManager $assetManager,
        protected ?HookAction $action = null,
        protected ?HookFilter $filter = null,
        protected ?ViteManagerFactory $viteManagers = null,
        protected ?RuntimeContext $runtime = null,
        protected ?LoggerInterface $logger = null,
    ) {}

    /**
     * The hook service the enqueuer registers on.
     *
     * @throws AssetException When the enqueuer was built without one
     */
    protected function hookAction(): HookAction
    {
        return $this->action ?? throw new AssetException('No hook service is set: the enqueuer was built without an Action.');
    }

    /**
     * The filter service the enqueuer registers on.
     *
     * @throws AssetException When the enqueuer was built without one
     */
    protected function hookFilter(): HookFilter
    {
        return $this->filter ?? throw new AssetException('No filter service is set: the enqueuer was built without a Filter.');
    }

    /**
     * Sets the asset handle (unique identifier).
     *
     * @param  string  $handle  Unique identifier for the asset\n     */
    public function handle(string $handle): self
    {
        $this->handle = $handle;

        return $this;
    }

    /**
     * Sets the asset path and determines the type.
     *
     * @param  string  $path  Path to the asset file\n     */
    public function path(string $path): self
    {
        $this->path = $path;
        $this->type = $this->determineFileType($path);

        return $this;
    }

    /**
     * Sets the asset container by name.
     *
     * @param  string  $containerName  Name of the container
     *
     * @throws \RuntimeException If the container is not found
     */
    public function container(string $containerName): self
    {
        if ($this->runtime?->isCommandLine()) {
            return $this;
        }

        $container = $this->assetManager->getContainer($containerName);

        if (! $container instanceof AssetContainer) {
            throw AssetContainerNotFound::named($containerName);
        }

        $this->container = $container;

        return $this;
    }

    /**
     * Sets asset dependencies.
     *
     * @param  array  $dependencies  Array of dependency handles\n     */
    public function dependencies(array $dependencies): self
    {
        $this->dependencies = $dependencies;

        return $this;
    }

    /**
     * Enables Vite.js integration for this asset.\n     *
     *
     * @throws \RuntimeException If no asset container is defined
     */
    public function useVite(): self
    {
        if ($this->runtime?->isCommandLine()) {
            return $this;
        }

        if (! $this->container instanceof AssetContainer) {
            throw AssetContainerNotFound::beforeUseVite();
        }

        $this->useVite = true;
        if (! $this->viteManagers instanceof ViteManagerFactory) {
            throw new AssetException('No Vite resolver is set: the enqueuer was built without a ViteManagerFactory.');
        }

        $this->viteManager = $this->viteManagers->make($this->container);

        return $this;
    }

    /**
     * Sets the asset version for cache busting.
     *
     * @param  string  $version  Version string\n     */
    public function version(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Sets the media type for stylesheets.
     *
     * @param  string  $media  Media query string\n     */
    public function media(string $media): self
    {
        $this->media = $media;

        return $this;
    }

    /**
     * Sets script to load in the footer.\n     */
    public function loadInFooter(): self
    {
        $this->loadInFooter = true;

        return $this;
    }

    /**
     * Sets the loading strategy for scripts.
     *
     * @param  string  $strategy  Loading strategy (e.g., 'defer', 'async')\n     */
    public function loadStrategy(string $strategy): self
    {
        $this->loadStrategy = $strategy;

        return $this;
    }

    /**
     * Sets the asset type manually.
     *
     * @param  string  $type  The asset type ('css' or 'js')\n     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Enqueues the asset in WordPress frontend (wp_enqueue_scripts).\n     */
    public function toFrontend(): self
    {
        return $this->addHook('wp_enqueue_scripts');
    }

    /**
     * Enqueues the asset in WordPress admin area (admin_enqueue_scripts).\n     */
    public function toBackend(): self
    {
        return $this->addHook('admin_enqueue_scripts');
    }

    /**
     * Enqueues the asset on the login screen (login_enqueue_scripts).\n     */
    public function toLoginScreen(): self
    {
        return $this->addHook('login_enqueue_scripts');
    }

    /**
     * Enqueues the asset in the customizer preview (customize_preview_init).\n     */
    public function toCustomizer(): self
    {
        return $this->addHook('customize_preview_init');
    }

    /**
     * Enqueues the asset in the block editor (enqueue_block_editor_assets).\n     */
    public function toEditor(): self
    {
        return $this->addHook('enqueue_block_editor_assets');
    }

    /**
     * Localizes a JavaScript file with data.
     *
     * @param  string  $objectName  JavaScript object name
     * @param  array  $data  Data to localize\n     */
    public function localize(string $objectName, array $data): self
    {
        if ($this->type === 'js') {
            $this->localizationData[$objectName] = $data;
        }

        return $this;
    }

    /**
     * Adds inline content to the asset.
     *
     * @param  string  $content  Inline CSS/JS content
     * @param  string  $position  Position ('before' or 'after')\n     */
    public function inline(string $content, string $position = 'after'): self
    {
        $this->inlineContent = $content;
        $this->inlinePosition = $position;

        return $this;
    }

    /**
     * Handles the actual enqueuing of the asset.
     * Called automatically when the object is destroyed.
     */
    public function __destruct()
    {
        try {
            $this->hooks = $this->hooks !== [] ? $this->hooks : ['wp_enqueue_scripts'];

            if ($this->useVite) {
                $this->configureViteAssets();
            }

            foreach ($this->hooks as $hook) {
                if ($this->needToLoadViteClient()) {
                    $this->loadViteClient($hook);
                }

                $this->hookAction()->add($hook, fn () => $this->enqueueStyleOrScript($hook), 99);
            }
        } catch (\Throwable $throwable) {
            $this->report('Error in AssetEnqueuer::__destruct', ['error' => $throwable->getMessage(), 'hooks' => $this->hooks, 'path' => $this->path ?? null]);
        }
    }

    /**
     * Report a failure of the deferred enqueue.
     *
     * Called from the destructor, which may run when the application is already gone
     * (end of the request, a test that built the enqueuer): a logger that cannot log then must not throw.
     *
     * @param  array<string, mixed>  $context
     */
    private function report(string $message, array $context): void
    {
        try {
            ($this->logger ?? new NullLogger)->error($message, $context);
        } catch (\Throwable) {
            // Nothing left to report to
        }
    }

    /**
     * Enqueues all styles and scripts for the current asset.
     */
    public function enqueueStyleOrScript(?string $hook = null): void
    {
        $paths = $this->getAssetPaths();
        foreach ($paths as $type => $pathList) {
            foreach ($pathList as $path) {
                $this->enqueueAsset((string) $type, $this->forceFullUrl($path), $hook);
            }
        }
    }

    /**
     * Adds a WordPress hook for asset enqueuing.
     *
     * @param  string  $hook  WordPress hook name\n     */
    protected function addHook(string $hook): self
    {
        $this->hooks[] = $hook;

        return $this;
    }

    /**
     * Loads the Vite client script when in development mode.
     *
     * @param  string  $hook  WordPress hook to attach the client script
     */
    protected function loadViteClient(string $hook): void
    {
        if ($this->printsScriptModules($hook)) {
            // The client is a module too: printed ahead of the import map, it would void it.
            $this->hookAction()->add($hook, function (): void {
                $url = $this->viteManager instanceof ViteManagerInterface ? $this->viteManager->clientUrl() : '';

                if ($url !== '') {
                    // No version: the modules Vite serves import /@vite/client by its bare URL,
                    // and a ?ver= copy would be a second client, with its own HMR connection.
                    wp_enqueue_script_module('vite-client/'.md5($url), $url, [], null);
                }
            }, 1);

            return;
        }

        $this->hookAction()->add($hook, function (): void {
            if ($this->viteManager instanceof ViteManagerInterface && $this->viteManager->isRunningHot()) {
                echo $this->viteManager->getViteClientHtml();
            }
        }, 1);
    }

    /**
     * Configures asset paths for Vite integration.
     *
     * @throws AssetException When Vite manager is not initialized
     */
    public function configureViteAssets(): void
    {
        if (! $this->viteManager instanceof ViteManagerInterface) {
            throw new AssetException('Vite manager not initialized. Call useVite() first.');
        }

        $asset = $this->viteManager->isRunningHot()
            ? $this->viteManager->asset($this->path)
            : $this->viteManager->getAssetUrls([$this->path]);
        $this->path = $asset;
    }

    /**
     * Determines if the Vite client needs to be loaded.
     *
     * @return bool True if Vite client should be loaded
     */
    protected function needToLoadViteClient(): bool
    {
        return $this->useVite && $this->viteManager instanceof ViteManagerInterface && $this->viteManager->isRunningHot();
    }

    /**
     * Gets the processed asset paths based on Vite configuration.
     *
     * @return array Array of asset paths grouped by type
     */
    protected function getAssetPaths(): array
    {
        if ($this->useVite && ! $this->viteManager->isRunningHot()) {

            return $this->path;
        }

        return [$this->type => [$this->path]];
    }

    /**
     * Enqueues an individual asset based on its type.
     *
     * @param  string  $type  Asset type (css or js)
     * @param  string  $path  Asset path
     *
     * @throws \InvalidArgumentException When asset type is not supported
     */
    protected function enqueueAsset(string $type, string $path, ?string $hook = null): void
    {
        $handle = $this->useVite && ! $this->viteManager->isRunningHot() ? $this->handle.'/'.sanitize_title(basename($path)) : $this->handle;
        match ($type) {
            'css' => $this->enqueueStyle($path, $handle),
            'js' => $this->useVite && $this->printsScriptModules($hook)
                ? $this->enqueueScriptModule($path, $handle)
                : $this->enqueueScript($path, $handle),
            default => throw new \InvalidArgumentException('Unsupported asset type: '.$type)
        };
    }

    /**
     * Enqueues a JavaScript file with WordPress.
     *
     * @param  string  $path  Path to the JavaScript file
     */
    protected function enqueueScript(string $path, string $handle): void
    {
        wp_enqueue_script($handle, $path, $this->dependencies, $this->version, $this->loadInFooter);

        foreach ($this->localizationData as $objectName => $data) {
            wp_localize_script($handle, $objectName, $data);
        }

        if ($this->useVite) {
            $this->addViteScriptAttributes($handle);
        }

        if (! in_array($this->loadStrategy, [null, '', '0'], true)) {
            wp_script_add_data($handle, 'defer', true);
        }

        if (! in_array($this->inlineContent, [null, '', '0'], true)) {
            wp_add_inline_script($handle, $this->inlineContent, $this->inlinePosition);
        }
    }

    /**
     * Whether a Vite entry enqueued on this hook is printed by WordPress as a script module.
     */
    protected function printsScriptModules(?string $hook): bool
    {
        return in_array($hook, self::SCRIPT_MODULE_HOOKS, true) && function_exists('wp_enqueue_script_module');
    }

    /**
     * Enqueues a Vite entry as a WordPress script module.
     *
     * A module can only depend on modules, and takes no localized data or inline
     * script. What the asset declares of those goes on a classic companion script,
     * `{handle}-data`, printed in the head: it runs before the module, which the
     * browser defers.
     */
    protected function enqueueScriptModule(string $path, string $handle): void
    {
        // null, not false: false appends WordPress's version, and a module is identified by its
        // exact URL — a chunk importing the entry back would load a second copy of it.
        wp_enqueue_script_module($handle, $path, [], $this->version, ['in_footer' => $this->loadInFooter]);

        $this->hookFilter()->add('wp_script_attributes', fn (array $attributes): array => ($attributes['id'] ?? null) === $handle.'-js-module'
            ? [...$attributes, 'crossorigin' => true]
            : $attributes);

        $hasInlineContent = ! in_array($this->inlineContent, [null, '', '0'], true);

        if ($this->dependencies === [] && $this->localizationData === [] && ! $hasInlineContent) {
            return;
        }

        $companion = $handle.'-data';
        // No source: a registered handle that prints only its dependencies and inline data.
        wp_register_script($companion, false, $this->dependencies, $this->version, false);
        wp_enqueue_script($companion);

        foreach ($this->localizationData as $objectName => $data) {
            wp_localize_script($companion, $objectName, $data);
        }

        if ($hasInlineContent) {
            wp_add_inline_script($companion, $this->inlineContent, $this->inlinePosition);
        }
    }

    /**
     * Enqueues a CSS file with WordPress.
     *
     * @param  string  $path  Path to the CSS file
     * @param  string  $handle  Unique identifier for the stylesheet
     */
    protected function enqueueStyle(string $path, string $handle): void
    {
        wp_enqueue_style($handle, $path, $this->dependencies, $this->version, $this->media);
        if (! in_array($this->inlineContent, [null, '', '0'], true)) {
            wp_add_inline_style($handle, $this->inlineContent);
        }
    }

    /**
     * Adds module and crossorigin attributes to Vite script tags.
     */
    protected function addViteScriptAttributes(string $handle): void
    {
        $this->hookFilter()->add('script_loader_tag', fn ($tag, $scriptHandle, $src) => $scriptHandle === $handle
            ? '<script type="module" crossorigin src="'.esc_url($src).'"></script>'
            : $tag, 10, 3);
    }

    /**
     * Ensures a full URL is used for the asset path.
     *
     * @param  string  $path  Asset path
     * @return string Full URL to the asset
     */
    protected function forceFullUrl(string $path): string
    {
        if (str_contains($path, '://')) {
            return $path;
        }

        $basePath = $this->container instanceof AssetContainer ? $this->container->getBasePath() : '';
        $fullPath = $basePath.'/'.ltrim($path, '/');

        return home_url($fullPath);
    }

    /**
     * Determines the file type from the path extension.
     *
     * @param  string  $path  File path
     * @return string File type (css or js)
     *
     * @throws \InvalidArgumentException When file type is not supported
     */
    protected function determineFileType(string $path): string
    {
        $type = pathinfo($path, PATHINFO_EXTENSION);

        // JSX/TSX/TS are JavaScript variants (transpiled by Vite)
        if (in_array($type, ['jsx', 'tsx', 'ts'], true)) {
            return 'js';
        }

        if (! in_array($type, ['css', 'js'], true)) {
            throw new \InvalidArgumentException('Unsupported file type: '.$type);
        }

        return $type;
    }
}
