<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Application\Services\AssetRetrievalService;
use Pollora\Asset\Assets;
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
    Assets::reset();

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

describe('Assets for a classic theme or a plugin', function (): void {
    it('adds the container of a theme inside the theme: its build, its hot file, its entry points', function (): void {
        $container = Assets::theme($this->root, 'https://site.test/content/themes/x/')->getContainer('theme');

        expect($container->getHotFile())->toBe($this->root.'/hot')
            ->and($container->getBuildDirectory())->toBe('build')
            ->and($container->getPublicPath())->toBe($this->root)
            ->and($container->getPublicUrl())->toBe('https://site.test/content/themes/x')
            ->and($container->getManifestPath())->toBe('manifest.json')
            ->and($container->getBasePath())->toBe('resources/assets/');
    });

    it('adds the container of a plugin under its slug, and lets the layout be changed', function (): void {
        $manager = Assets::plugin('acme', '/srv/plugins/acme', 'https://site.test/plugins/acme', ['build' => 'dist', 'hot' => '.hot', 'base_path' => 'assets/']);

        $container = $manager->getContainer('plugin.acme');

        expect($container->getHotFile())->toBe('/srv/plugins/acme/.hot')
            ->and($container->getBuildDirectory())->toBe('dist')
            ->and($container->getPublicPath())->toBe('/srv/plugins/acme')
            ->and($container->getPublicUrl())->toBe('https://site.test/plugins/acme')
            ->and($container->getBasePath())->toBe('assets/')
            ->and($manager)->toBe(Assets::manager());
    });

    it('keeps one manager, and starts again after a reset', function (): void {
        $first = Assets::manager();

        expect(Assets::manager())->toBe($first);

        Assets::reset();

        expect(Assets::manager())->not->toBe($first);
    });

    it('tells the admin about a missing build', function (): void {
        Brain\Monkey\Actions\expectAdded('admin_notices')->once();

        Assets::manager();
    });

    it('resolves the URL of a theme file for AssetFile, from the build or from the dev server', function (): void {
        Assets::theme($this->root, 'https://site.test/content/themes/x');

        expect((string) (new AssetFile('images/logo.svg'))->from('theme'))->toBe('https://site.test/content/themes/x/build/assets/logo-DDD.svg')
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
        // The container Assets::theme() adds
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
