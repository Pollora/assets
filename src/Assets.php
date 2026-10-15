<?php

declare(strict_types=1);

namespace Pollora\Asset;

use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Application\Services\AssetRetrievalService;
use Pollora\Asset\Application\Services\MissingBuilds;
use Pollora\Asset\Domain\Services\AssetContainerManager;
use Pollora\Asset\Infrastructure\Repositories\InMemoryAssetRepository;
use Pollora\Asset\Infrastructure\Services\AssetEnqueuer;
use Pollora\Asset\Infrastructure\Services\AssetFile;
use Pollora\Asset\Infrastructure\WordPress\NativeViteManagerFactory;
use Pollora\Asset\Infrastructure\WordPress\StandaloneAssetFileResolver;
use Pollora\Asset\Infrastructure\WordPress\WordPressRuntimeContext;
use Pollora\Asset\UI\Http\MissingBuildNotice;
use Pollora\Hook\Adapter\Out\WordPress\Action;
use Pollora\Hook\Adapter\Out\WordPress\Filter;
use Psr\Log\LoggerInterface;

/**
 * Assets for a WordPress theme or plugin that is not a Pollora application: a classic theme, a plugin, a Bedrock site.
 *
 * ```php
 * Assets::theme(get_stylesheet_directory(), get_stylesheet_directory_uri())
 *     ->add('my-theme/app', 'app.js')->container('theme')->useVite()->toFrontend();
 * ```
 *
 * The build and the hot file live inside the theme or plugin (what
 * `@pollora/vite-config` writes with `layout: 'standalone'`): `build/` and
 * `hot`, with the entry points under `resources/assets/`. Nothing here needs
 * Laravel, a public directory or a service container.
 */
final class Assets
{
    private static ?AssetManager $manager = null;

    private static ?LoggerInterface $logger = null;

    /**
     * Where failures are reported (a failed enqueue, an unresolved file). Set it before the first container is added.
     */
    public static function useLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * The asset manager, wired to WordPress: its hooks, its URLs, the notice about builds that are missing.
     */
    public static function manager(): AssetManager
    {
        return self::$manager ??= self::build();
    }

    /**
     * Add the container of the active theme: `theme`.
     *
     * @param  string  $directory  The directory of the theme
     * @param  string  $url  Its URL, without a trailing slash
     * @param  array<string, string>  $options  `build` (the build directory in the theme, `build`), `hot` (the hot file, `hot`), `manifest` (`manifest.json`), `base_path` (`resources/assets/`)
     */
    public static function theme(string $directory, string $url, array $options = []): AssetManager
    {
        return self::container('theme', $directory, $url, $options);
    }

    /**
     * Add the container of a plugin: `plugin.{slug}`.
     *
     * @param  array<string, string>  $options  The same as theme()
     */
    public static function plugin(string $slug, string $directory, string $url, array $options = []): AssetManager
    {
        return self::container('plugin.'.$slug, $directory, $url, $options);
    }

    /**
     * Add the container of a directory, under the name you give it.
     *
     * @param  array<string, string>  $options  The same as theme()
     */
    public static function container(string $name, string $directory, string $url, array $options = []): AssetManager
    {
        $manager = self::manager();
        $directory = rtrim($directory, '/');
        $build = trim($options['build'] ?? 'build', '/');

        $manager->addContainer($name, [
            'hot_file' => $directory.'/'.($options['hot'] ?? 'hot'),
            'build_directory' => $build,
            'build_path' => $directory.'/'.$build,
            'build_url' => rtrim($url, '/').'/'.$build,
            'manifest_path' => $options['manifest'] ?? 'manifest.json',
            'base_path' => $options['base_path'] ?? 'resources/assets/',
        ]);

        return $manager;
    }

    /**
     * Forget everything: the manager, its containers and the file resolver. For tests.
     */
    public static function reset(): void
    {
        self::$manager = null;
        self::$logger = null;
        AssetFile::resolveUsing(null);
    }

    private static function build(): AssetManager
    {
        $action = new Action;
        $filter = new Filter;
        $runtime = new WordPressRuntimeContext;
        $missing = new MissingBuilds(self::$logger);
        $viteManagers = new NativeViteManagerFactory($missing);

        $manager = null;
        $manager = new AssetManager(
            new AssetRetrievalService(new AssetContainerManager(new InMemoryAssetRepository)),
            null,
            static function () use (&$manager, $action, $filter, $viteManagers, $runtime): AssetEnqueuer {
                return new AssetEnqueuer($manager, $action, $filter, $viteManagers, $runtime, self::$logger);
            },
        );

        AssetFile::resolveUsing(\Closure::fromCallable(new StandaloneAssetFileResolver($manager, $viteManagers, self::$logger)));
        $action->add('admin_notices', [new MissingBuildNotice($missing), 'render']);

        return $manager;
    }
}
