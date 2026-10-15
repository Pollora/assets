<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Application\Services\AssetRetrievalService;
use Pollora\Asset\Asset;
use Pollora\Asset\Domain\Contracts\RuntimeContext;
use Pollora\Asset\Domain\Services\AssetContainerManager;
use Pollora\Asset\Infrastructure\Repositories\InMemoryAssetRepository;
use Pollora\Asset\Infrastructure\Services\AssetEnqueuer;
use Pollora\Asset\Infrastructure\Services\AssetFile;
use Pollora\Asset\Infrastructure\WordPress\NativeViteManagerFactory;
use Pollora\Hook\Domain\Contract\Action as HookAction;
use Pollora\Hook\Domain\Contract\Filter as HookFilter;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/pollora-assets-theme-'.uniqid();
    mkdir($this->root.'/build', 0755, true);
    file_put_contents($this->root.'/build/manifest.json', json_encode([
        'resources/assets/app.js' => ['file' => 'assets/app-AAA.js', 'css' => ['assets/app-BBB.css']],
        'resources/assets/images/logo.svg' => ['file' => 'assets/logo-DDD.svg'],
    ]));
});

afterEach(function (): void {
    Asset::reset();

    foreach ([$this->root.'/build/manifest.json', $this->root.'/hot'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (['/build', ''] as $directory) {
        if (is_dir($this->root.$directory)) {
            rmdir($this->root.$directory);
        }
    }
});

describe('Asset, the facade of a theme or a plugin outside a Pollora project', function (): void {
    beforeEach(function (): void {
        $this->theme = fn (): array => [
            'hot_file' => $this->root.'/hot',
            'build_directory' => 'build',
            'manifest_path' => 'manifest.json',
            'base_path' => 'resources/assets/',
            'public_path' => $this->root,
            'public_url' => 'https://site.test/content/themes/x',
        ];
    });

    it('declares a container with the keys of a Pollora container, and gives it back', function (): void {
        Asset::addContainer('theme', ($this->theme)());

        $container = Asset::getContainer('theme');

        expect($container->getHotFile())->toBe($this->root.'/hot')
            ->and($container->getBuildDirectory())->toBe('build')
            ->and($container->getManifestPath())->toBe('manifest.json')
            ->and($container->getBasePath())->toBe('resources/assets/')
            ->and($container->getPublicPath())->toBe($this->root)
            ->and($container->getPublicUrl())->toBe('https://site.test/content/themes/x')
            ->and(array_keys(Asset::containers()))->toBe(['theme'])
            ->and(Asset::getContainer('plugin.acme'))->toBeNull();
    });

    it('forwards the calls of the framework facade to one asset manager', function (): void {
        Asset::addContainer('theme', ($this->theme)());
        Asset::setDefaultContainer('theme');

        expect(Asset::manager())->toBe(Asset::manager())
            ->and(Asset::getDefaultContainer()->getName())->toBe('theme')
            ->and(Asset::add('my-theme/app', 'app.js'))->toBeInstanceOf(AssetEnqueuer::class);
    });

    it('starts again after a reset', function (): void {
        Asset::addContainer('theme', ($this->theme)());
        $first = Asset::manager();

        Asset::reset();

        expect(Asset::manager())->not->toBe($first)
            ->and(Asset::containers())->toBe([]);
    });

    it('tells the admin about a missing build', function (): void {
        Brain\Monkey\Actions\expectAdded('admin_notices')->once();

        Asset::manager();
    });

    it('resolves the URL of a theme file for AssetFile, from the build or from the dev server', function (): void {
        Asset::addContainer('theme', ($this->theme)());

        expect((string) Asset::url('images/logo.svg')->from('theme'))->toBe('https://site.test/content/themes/x/build/assets/logo-DDD.svg')
            ->and((string) (new AssetFile('images/logo.svg'))->from('plugin.unknown'))->toBe('')
            ->and((string) (new AssetFile('images/nothing.svg'))->from('theme'))->toBe('');

        file_put_contents($this->root.'/hot', 'https://site.test:5173/theme');

        expect((string) (new AssetFile('images/logo.svg'))->from('theme'))->toBe('https://site.test:5173/theme/resources/assets/images/logo.svg');
    });
});

describe('an enqueued asset, from the container of a theme to WordPress', function (): void {
    beforeEach(function (): void {
        $this->modules = [];
        $this->styles = [];
        Functions\when('wp_enqueue_script_module')->alias(function (string $id, string $src = '', array $deps = [], $version = false, array $args = []): void {
            $this->modules[$id] = $src;
        });
        Functions\when('wp_enqueue_style')->alias(function (string $handle, string $src = ''): void {
            $this->styles[$handle] = $src;
        });
        Functions\when('wp_enqueue_script')->justReturn(null);
        Functions\when('wp_register_script')->justReturn(null);
        Functions\when('sanitize_title')->alias(fn (string $title): string => strtolower(str_replace('.', '-', $title)));

        $this->callbacks = [];
        $action = Mockery::mock(HookAction::class);
        $action->shouldReceive('add')->andReturnUsing(function (string $hook, callable $callback, int $priority = 10) use ($action): HookAction {
            $this->callbacks[] = [$hook, $priority, $callback];

            return $action;
        });
        $filter = Mockery::mock(HookFilter::class);
        $filter->shouldReceive('add')->andReturn($filter);

        $this->manager = new AssetManager(
            new AssetRetrievalService(new AssetContainerManager(new InMemoryAssetRepository)),
            null,
            fn (): AssetEnqueuer => new AssetEnqueuer($this->manager, $action, $filter, new NativeViteManagerFactory, new class implements RuntimeContext
            {
                public function isCommandLine(): bool
                {
                    return false;
                }
            }),
        );
        // The container of a theme, as a Pollora project declares it
        $this->manager->addContainer('theme', [
            'hot_file' => $this->root.'/hot',
            'build_directory' => 'build',
            'public_path' => $this->root,
            'public_url' => 'https://site.test/content/themes/x',
            'base_path' => 'resources/assets/',
        ]);

        /** Enqueue app.js of the theme on the front end, then let WordPress fire its hook */
        $this->enqueueOnFront = function (): void {
            $this->manager->add('theme/app', 'app.js')->container('theme')->useVite()->toFrontend();

            foreach ($this->callbacks as [$hook, , $callback]) {
                if ($hook === 'wp_enqueue_scripts') {
                    $callback();
                }
            }
        };
    });

    it('enqueues the built entry as a script module, and its CSS, at the URL of the build', function (): void {
        ($this->enqueueOnFront)();

        expect(array_values($this->modules))->toContain('https://site.test/content/themes/x/build/assets/app-AAA.js')
            ->and(array_values($this->styles))->toContain('https://site.test/content/themes/x/build/assets/app-BBB.css');
    });

    it('enqueues the entry from the dev server, and its client, while Vite runs hot', function (): void {
        file_put_contents($this->root.'/hot', 'https://site.test:5173/build/theme/x');

        ($this->enqueueOnFront)();

        expect(array_values($this->modules))
            ->toContain('https://site.test:5173/build/theme/x/resources/assets/app.js')
            ->toContain('https://site.test:5173/build/theme/x/@vite/client');
    });
});
