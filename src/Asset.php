<?php

declare(strict_types=1);

namespace Pollora\Asset;

use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Application\Services\AssetRetrievalService;
use Pollora\Asset\Application\Services\MissingBuilds;
use Pollora\Asset\Domain\Services\AssetContainerManager;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;
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
 * The asset manager of a WordPress theme or plugin that is not a Pollora application: a classic theme, a plugin, a Bedrock site.
 *
 * It is the framework's `Asset` facade for a project with no Laravel: the same
 * calls, forwarded to the same AssetManager, wired to WordPress. The framework
 * declares the containers of a theme, the plugins and the modules itself; here
 * you declare them once, with `addContainer()`.
 *
 * ```php
 * Asset::addContainer('theme', [...]);
 *
 * Asset::add('my-theme/app', 'app.js')->container('theme')->useVite()->toFrontend();
 * ```
 *
 * @method static AssetEnqueuer add(string $handle, string $file) Start the enqueue of an asset
 * @method static AssetFile url(string $file) The URL of a file of a container, once cast to a string
 * @method static void addContainer(string $name, array<string, mixed> $config) Declare a container
 * @method static AssetContainer|null getContainer(string $name)
 * @method static array<string, AssetContainer> containers()
 * @method static void setDefaultContainer(string $name)
 * @method static AssetContainer|null getDefaultContainer()
 * @method static \Pollora\Asset\Domain\Models\Asset|null get(string $name)
 * @method static array<int, \Pollora\Asset\Domain\Models\Asset> all()
 */
final class Asset
{
    private static ?AssetManager $manager = null;

    private static ?LoggerInterface $logger = null;

    /**
     * @param  array<int, mixed>  $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::manager()->{$method}(...$arguments);
    }

    /**
     * The asset manager, wired to WordPress: its hooks, its URLs, the notice about builds that are missing.
     */
    public static function manager(): AssetManager
    {
        return self::$manager ??= self::build();
    }

    /**
     * Where failures are reported (a failed enqueue, an unresolved file). Set it before the first call.
     */
    public static function useLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
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
