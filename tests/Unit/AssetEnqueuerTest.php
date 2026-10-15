<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Domain\Contracts\RuntimeContext;
use Pollora\Asset\Domain\Contracts\ViteManagerFactory;
use Pollora\Asset\Domain\Contracts\ViteManagerInterface;
use Pollora\Asset\Domain\Exceptions\AssetException;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;
use Pollora\Asset\Infrastructure\Services\AssetEnqueuer;
use Pollora\Hook\Domain\Contract\Action as HookAction;
use Pollora\Hook\Domain\Contract\Filter as HookFilter;

beforeEach(function (): void {
    $this->assetManager = Mockery::mock(AssetManager::class);

    // Hook registration is mocked, so the deferred enqueue of a destroyed builder cannot fail
    $this->hookAction = Mockery::mock(HookAction::class);
    $this->hookAction->shouldReceive('add')->byDefault();
    $this->hookFilter = Mockery::mock(HookFilter::class);
    $this->hookFilter->shouldReceive('add')->byDefault();

    // The command line, unless a test says otherwise
    $this->commandLine = true;
    $this->runtime = new class($this) implements RuntimeContext
    {
        public function __construct(private readonly object $test) {}

        public function isCommandLine(): bool
        {
            return $this->test->commandLine;
        }
    };
    $this->viteFactory = null;

    /** An enqueuer built the way the environment would build it */
    $this->make = fn (): AssetEnqueuer => new AssetEnqueuer(
        $this->assetManager,
        $this->hookAction,
        $this->hookFilter,
        $this->viteFactory,
        $this->runtime,
    );
});

describe('AssetEnqueuer', function (): void {
    describe('fluent builder', function (): void {
        it('chains handle and path', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer->handle('my-script')->path('assets/app.js');

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('chains dependencies', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer->dependencies(['jquery']);

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('chains version', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer->version('1.2.3');

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('chains media', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer->media('print');

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('chains loadInFooter', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer->loadInFooter();

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('chains loadStrategy', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer->loadStrategy('defer');

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('chains setType', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer->setType('css');

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('chains inline', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer->inline('body { color: red; }', 'after');

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);
        });
    });

    describe('context hooks', function (): void {
        it('toFrontend sets wp_enqueue_scripts hook', function (): void {
            $enqueuer = ($this->make)();

            expect($enqueuer->toFrontend())->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('toBackend sets admin_enqueue_scripts hook', function (): void {
            $enqueuer = ($this->make)();

            expect($enqueuer->toBackend())->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('toLoginScreen sets login_enqueue_scripts hook', function (): void {
            $enqueuer = ($this->make)();

            expect($enqueuer->toLoginScreen())->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('toCustomizer sets customize_preview_init hook', function (): void {
            $enqueuer = ($this->make)();

            expect($enqueuer->toCustomizer())->toBeInstanceOf(AssetEnqueuer::class);
        });

        it('toEditor sets enqueue_block_editor_assets hook', function (): void {
            $enqueuer = ($this->make)();

            expect($enqueuer->toEditor())->toBeInstanceOf(AssetEnqueuer::class);
        });
    });

    describe('determineFileType', function (): void {
        it('detects CSS files', function (): void {
            $enqueuer = ($this->make)();
            $enqueuer->path('assets/style.css');

            $reflection = new ReflectionProperty($enqueuer, 'type');
            expect($reflection->getValue($enqueuer))->toBe('css');
        });

        it('detects JS files', function (): void {
            $enqueuer = ($this->make)();
            $enqueuer->path('assets/app.js');

            $reflection = new ReflectionProperty($enqueuer, 'type');
            expect($reflection->getValue($enqueuer))->toBe('js');
        });

        it('treats JSX as JS', function (): void {
            $enqueuer = ($this->make)();
            $enqueuer->path('components/App.jsx');

            $reflection = new ReflectionProperty($enqueuer, 'type');
            expect($reflection->getValue($enqueuer))->toBe('js');
        });

        it('treats TSX as JS', function (): void {
            $enqueuer = ($this->make)();
            $enqueuer->path('components/App.tsx');

            $reflection = new ReflectionProperty($enqueuer, 'type');
            expect($reflection->getValue($enqueuer))->toBe('js');
        });

        it('treats TS as JS', function (): void {
            $enqueuer = ($this->make)();
            $enqueuer->path('assets/app.ts');

            $reflection = new ReflectionProperty($enqueuer, 'type');
            expect($reflection->getValue($enqueuer))->toBe('js');
        });

        it('throws on unsupported file type', function (): void {
            $enqueuer = ($this->make)();

            expect(fn () => $enqueuer->path('image.png'))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('localize', function (): void {
        it('stores localization data for JS assets', function (): void {
            $enqueuer = ($this->make)();
            $enqueuer->path('app.js');

            $result = $enqueuer->localize('myData', ['key' => 'value']);

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);

            $reflection = new ReflectionProperty($enqueuer, 'localizationData');
            expect($reflection->getValue($enqueuer))->toHaveKey('myData');
        });

        it('ignores localization for CSS assets', function (): void {
            $enqueuer = ($this->make)();
            $enqueuer->path('style.css');

            $enqueuer->localize('myData', ['key' => 'value']);

            $reflection = new ReflectionProperty($enqueuer, 'localizationData');
            expect($reflection->getValue($enqueuer))->toBeEmpty();
        });
    });

    describe('container', function (): void {
        it('skips in console mode', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer->container('theme');

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);

            $reflection = new ReflectionProperty($enqueuer, 'container');
            expect($reflection->getValue($enqueuer))->toBeNull();
        });
    });

    describe('useVite', function (): void {
        it('skips in console mode', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer->useVite();

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);

            $reflection = new ReflectionProperty($enqueuer, 'useVite');
            expect($reflection->getValue($enqueuer))->toBeFalse();
        });
    });

    describe('the Vite resolver of useVite()', function (): void {
        beforeEach(function (): void {
            // Not the command line: useVite() acts
            $this->commandLine = false;

            $this->container = new AssetContainer('theme', ['hot_file' => '/tmp/none.hot', 'build_directory' => 'build/theme/x']);
            $this->assetManager->shouldReceive('getContainer')->with('theme')->andReturn($this->container);
        });

        it('asks the factory for the resolver of the container it was given', function (): void {
            $resolver = Mockery::mock(ViteManagerInterface::class)->shouldIgnoreMissing();
            $factory = Mockery::mock(ViteManagerFactory::class);
            $factory->shouldReceive('make')->once()->with($this->container)->andReturn($resolver);
            $this->viteFactory = $factory;

            $enqueuer = ($this->make)()->handle('theme/script')->path('app.js')->container('theme')->useVite();

            expect((new ReflectionProperty($enqueuer, 'viteManager'))->getValue($enqueuer))->toBe($resolver);
        });

        it('says so when no resolver is set', function (): void {
            expect(fn () => ($this->make)()->handle('theme/script')->path('app.js')->container('theme')->useVite())
                ->toThrow(AssetException::class, 'No Vite resolver is set');
        });
    });

    describe('Vite entries as script modules', function (): void {
        beforeEach(function (): void {
            $this->filters = [];
            $hookFilter = Mockery::mock(HookFilter::class);
            $hookFilter->shouldReceive('add')->andReturnUsing(function (string $hook, Closure $callback) use ($hookFilter): HookFilter {
                $this->filters[$hook] = $callback;

                return $hookFilter;
            });
            $this->hookFilter = $hookFilter;

            $this->modules = [];
            $this->scripts = [];
            $this->registered = [];
            Functions\when('wp_enqueue_script_module')->alias(function (string $id, string $src = '', array $deps = [], $version = false, array $args = []): void {
                $this->modules[$id] = ['src' => $src, 'deps' => $deps, 'version' => $version, 'args' => $args];
            });
            Functions\when('wp_register_script')->alias(function (string $handle, $src, array $deps = []): void {
                $this->registered[$handle] = ['src' => $src, 'deps' => $deps];
            });
            Functions\when('wp_enqueue_script')->alias(function (string $handle, $src = '', array $deps = []): void {
                $this->scripts[$handle] = ['src' => $src, 'deps' => $deps];
            });
            Functions\when('sanitize_title')->alias(fn (string $title): string => strtolower(str_replace('.', '-', $title)));

            $this->viteManager = Mockery::mock(ViteManagerInterface::class);
            $this->viteManager->shouldReceive('isRunningHot')->andReturn(false)->byDefault();
            $this->viteManager->shouldReceive('getAssetUrls')->andReturn([])->byDefault();
        });

        /** An enqueuer for a built Vite entry, set up the way useVite() would outside the console. */
        function viteEnqueuer(AssetEnqueuer $enqueuer, ViteManagerInterface $viteManager, array $settings = []): AssetEnqueuer
        {
            $enqueuer->handle('buzz/script');

            foreach (['useVite' => true, 'viteManager' => $viteManager, 'path' => ['js' => ['https://site.test/build/app-abc.js']], ...$settings] as $property => $value) {
                (new ReflectionProperty($enqueuer, $property))->setValue($enqueuer, $value);
            }

            return $enqueuer;
        }

        it('enqueues the entry as a module on the front end, with no version appended', function (): void {
            viteEnqueuer(($this->make)(), $this->viteManager)->enqueueStyleOrScript('wp_enqueue_scripts');

            expect($this->modules)->toHaveKey('buzz/script/app-abc-js')
                ->and($this->modules['buzz/script/app-abc-js'])
                ->toMatchArray(['src' => 'https://site.test/build/app-abc.js', 'version' => null, 'args' => ['in_footer' => false]])
                ->and($this->scripts)->toBe([]);
        });

        it('passes loadInFooter() on as in_footer, as WordPress reads it for its own modules', function (): void {
            viteEnqueuer(($this->make)(), $this->viteManager, ['loadInFooter' => true])->enqueueStyleOrScript('wp_enqueue_scripts');

            expect($this->modules['buzz/script/app-abc-js']['args'])->toBe(['in_footer' => true]);
        });

        it('enqueues the entry as a module in the admin', function (): void {
            viteEnqueuer(($this->make)(), $this->viteManager)->enqueueStyleOrScript('admin_enqueue_scripts');

            expect($this->modules)->toHaveKey('buzz/script/app-abc-js');
        });

        it('keeps a classic script where WordPress prints no modules, such as the editor', function (): void {
            viteEnqueuer(($this->make)(), $this->viteManager)->enqueueStyleOrScript('enqueue_block_editor_assets');

            expect($this->modules)->toBe([])
                ->and($this->scripts)->toHaveKey('buzz/script/app-abc-js');
        });

        it('marks the module tag crossorigin, and no other tag', function (): void {
            viteEnqueuer(($this->make)(), $this->viteManager)->enqueueStyleOrScript('wp_enqueue_scripts');

            $filter = $this->filters['wp_script_attributes'];

            expect($filter(['id' => 'buzz/script/app-abc-js-js-module']))->toHaveKey('crossorigin', true)
                ->and($filter(['id' => 'something-else-js-module']))->not->toHaveKey('crossorigin');
        });

        it('puts dependencies, localized data and inline script on a classic companion', function (): void {
            $localized = [];
            $inline = [];
            Functions\when('wp_localize_script')->alias(function (string $handle, string $name) use (&$localized): void {
                $localized[$handle][] = $name;
            });
            Functions\when('wp_add_inline_script')->alias(function (string $handle, string $code) use (&$inline): void {
                $inline[$handle] = $code;
            });

            viteEnqueuer(($this->make)(), $this->viteManager, [
                'dependencies' => ['jquery'],
                'localizationData' => ['buzzData' => ['a' => 1]],
                'inlineContent' => 'window.ready = true;',
            ])->enqueueStyleOrScript('wp_enqueue_scripts');

            expect($this->modules['buzz/script/app-abc-js']['deps'])->toBe([])
                ->and($this->registered['buzz/script/app-abc-js-data'])->toBe(['src' => false, 'deps' => ['jquery']])
                ->and($this->scripts)->toHaveKey('buzz/script/app-abc-js-data')
                ->and($localized)->toBe(['buzz/script/app-abc-js-data' => ['buzzData']])
                ->and($inline)->toBe(['buzz/script/app-abc-js-data' => 'window.ready = true;']);
        });

        it('enqueues the Vite client as a module while Vite runs hot', function (): void {
            $this->viteManager->shouldReceive('isRunningHot')->andReturn(true);
            $this->viteManager->shouldReceive('clientUrl')->andReturn('https://site.test:5173/@vite/client');

            $callbacks = [];
            $hookAction = $this->hookAction;
            $hookAction->shouldReceive('add')->andReturnUsing(function (string $hook, callable $callback, int $priority = 10) use (&$callbacks, $hookAction): HookAction {
                $callbacks[] = [$hook, $priority, $callback];

                return $hookAction;
            });

            (function (): void {
                $this->loadViteClient('wp_enqueue_scripts');
            })->call(viteEnqueuer(($this->make)(), $this->viteManager));

            [$hook, $priority, $callback] = $callbacks[0];
            $callback();

            expect([$hook, $priority])->toBe(['wp_enqueue_scripts', 1])
                ->and(array_column($this->modules, 'src'))->toBe(['https://site.test:5173/@vite/client'])
                ->and(array_column($this->modules, 'version'))->toBe([null]);
        });
    });

    describe('full chain', function (): void {
        it('supports complete fluent configuration', function (): void {
            $enqueuer = ($this->make)();

            $result = $enqueuer
                ->handle('my-app')
                ->path('assets/app.js')
                ->dependencies(['jquery'])
                ->version('1.0.0')
                ->loadInFooter()
                ->loadStrategy('defer')
                ->toFrontend();

            expect($result)->toBeInstanceOf(AssetEnqueuer::class);
        });
    });
});
