<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Domain\Contracts\RuntimeContext;
use Pollora\Asset\Domain\Contracts\ViteManagerFactory;
use Pollora\Asset\Domain\Contracts\ViteManagerInterface;
use Pollora\Asset\Domain\Exceptions\AssetContainerNotFoundException;
use Pollora\Asset\Domain\Models\AssetContainer;
use Pollora\Asset\Infrastructure\Services\AssetEnqueuer;
use Pollora\Hook\Domain\Contract\Action as HookAction;
use Pollora\Hook\Domain\Contract\Filter as HookFilter;
use Psr\Log\AbstractLogger;

/*
 * What the enqueuer hands to WordPress: every WordPress function is replaced by a
 * recorder, so each test reads the exact calls (name and arguments, in order).
 */

beforeEach(function (): void {
    $this->calls = [];
    $this->actions = [];
    $this->filters = [];

    foreach (['wp_enqueue_script', 'wp_enqueue_style', 'wp_localize_script', 'wp_script_add_data', 'wp_add_inline_script', 'wp_add_inline_style', 'wp_enqueue_script_module', 'wp_register_script'] as $function) {
        Functions\when($function)->alias(function (...$arguments) use ($function): void {
            $this->calls[] = [$function, $arguments];
        });
    }
    Functions\when('home_url')->alias(fn (string $path): string => 'home:'.$path);
    Functions\when('sanitize_title')->alias(fn (string $title): string => strtolower(str_replace('.', '-', $title)));
    Functions\when('esc_url')->returnArg();

    $this->assetManager = new AssetManager(emptyRetrievalService());

    $this->hookAction = Mockery::mock(HookAction::class);
    $this->hookAction->shouldReceive('add')->andReturnUsing(function (...$arguments): HookAction {
        $this->actions[] = $arguments;

        return $this->hookAction;
    });
    $this->hookFilter = Mockery::mock(HookFilter::class);
    $this->hookFilter->shouldReceive('add')->andReturnUsing(function (...$arguments): HookFilter {
        $this->filters[] = $arguments;

        return $this->hookFilter;
    });

    $this->commandLine = false;
    $this->runtime = new class($this) implements RuntimeContext
    {
        public function __construct(private readonly object $test) {}

        public function isCommandLine(): bool
        {
            return $this->test->commandLine;
        }
    };

    $this->viteManager = Mockery::mock(ViteManagerInterface::class);
    $this->viteManager->shouldReceive('isRunningHot')->andReturn(false)->byDefault();

    $this->make = fn (): AssetEnqueuer => new AssetEnqueuer($this->assetManager, $this->hookAction, $this->hookFilter, null, $this->runtime);

    /** Sets protected properties the way useVite() or the fluent calls would */
    $this->with = function (AssetEnqueuer $enqueuer, array $properties): AssetEnqueuer {
        foreach ($properties as $property => $value) {
            (new ReflectionProperty($enqueuer, $property))->setValue($enqueuer, $value);
        }

        return $enqueuer;
    };

    $this->logger = new class extends AbstractLogger
    {
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = [(string) $level, (string) $message, $context];
        }
    };
});

describe('the containers the enqueuer reads', function (): void {
    it('takes the container of the manager, without a runtime context', function (): void {
        $this->assetManager->addContainer('theme', []);
        $container = $this->assetManager->getContainer('theme');

        $enqueuer = new AssetEnqueuer($this->assetManager, $this->hookAction, $this->hookFilter);
        $enqueuer->container('theme');

        expect((new ReflectionProperty($enqueuer, 'container'))->getValue($enqueuer))->toBe($container);
    });

    it('names the container nobody registered', function (): void {
        expect(fn () => ($this->make)()->container('ghost'))
            ->toThrow(AssetContainerNotFoundException::class, "Asset container 'ghost' not found. Make sure you have added it via AssetManager::addContainer().");
    });

    it('asks for a container before useVite()', function (): void {
        $factory = Mockery::mock(ViteManagerFactory::class);
        $factory->shouldReceive('make')->never();

        $enqueuer = new AssetEnqueuer($this->assetManager, $this->hookAction, $this->hookFilter, $factory, $this->runtime);

        expect(fn () => $enqueuer->useVite())
            ->toThrow(AssetContainerNotFoundException::class, "No asset container defined before useVite(). Use ->container('theme') before ->useVite().");
    });

    it('resolves Vite for its container, without a runtime context', function (): void {
        $this->assetManager->addContainer('theme', []);
        $container = $this->assetManager->getContainer('theme');
        $factory = Mockery::mock(ViteManagerFactory::class);
        $factory->shouldReceive('make')->with($container)->once()->andReturn($this->viteManager);

        $enqueuer = (new AssetEnqueuer($this->assetManager, $this->hookAction, $this->hookFilter, $factory))->container('theme')->useVite();

        expect((new ReflectionProperty($enqueuer, 'viteManager'))->getValue($enqueuer))->toBe($this->viteManager)
            ->and((new ReflectionProperty($enqueuer, 'useVite'))->getValue($enqueuer))->toBeTrue();
    });

    it('turns the flag of useVite() on before it asks for a resolver', function (): void {
        $this->assetManager->addContainer('theme', []);
        $enqueuer = ($this->make)()->container('theme');

        try {
            $enqueuer->useVite();
        } catch (Throwable) {
            // no resolver is set: said by another test
        }

        expect((new ReflectionProperty($enqueuer, 'useVite'))->getValue($enqueuer))->toBeTrue();
    });
});

describe('the file type of a path', function (): void {
    it('names the extension it refuses', function (): void {
        expect(fn () => ($this->make)()->path('image.png'))
            ->toThrow(InvalidArgumentException::class, 'Unsupported file type: png');
    });
});

describe('what the deferred enqueue registers', function (): void {
    it('puts a classic script on the front end, at priority 99, when no hook is named', function (): void {
        $enqueuer = ($this->make)()->handle('app')->path('app.js');
        unset($enqueuer);

        expect($this->actions)->toHaveCount(1)
            ->and($this->actions[0][0])->toBe('wp_enqueue_scripts')
            ->and($this->actions[0][2])->toBe(99);
    });

    it('registers on each hook it was given, and on no other', function (): void {
        $enqueuer = ($this->make)()->handle('app')->path('app.js')->toBackend()->toLoginScreen();
        unset($enqueuer);

        expect(array_column($this->actions, 0))->toBe(['admin_enqueue_scripts', 'login_enqueue_scripts'])
            ->and(array_column($this->actions, 2))->toBe([99, 99]);
    });

    it('enqueues what it registered on the hook that fired', function (): void {
        $enqueuer = ($this->make)()->handle('app')->path('app.js')->toEditor();
        unset($enqueuer);

        ($this->actions[0][1])();

        expect($this->calls)->toBe([['wp_enqueue_script', ['app', 'home:/app.js', [], null, false]]]);
    });

    it('reports the failure of the registration, with what it was doing', function (): void {
        $enqueuer = new AssetEnqueuer($this->assetManager, null, null, null, $this->runtime, $this->logger);
        $enqueuer->handle('app')->path('app.js')->toBackend();
        unset($enqueuer);

        expect($this->logger->records)->toBe([[
            'error',
            'Error in AssetEnqueuer::__destruct',
            [
                'error' => 'No hook service is set: the enqueuer was built without an Action.',
                'hooks' => ['admin_enqueue_scripts'],
                'path' => 'app.js',
            ],
        ]]);
    });

    it('reports a failure of an enqueuer that never got a path, as null', function (): void {
        $enqueuer = new AssetEnqueuer($this->assetManager, null, null, null, $this->runtime, $this->logger);
        unset($enqueuer);

        expect($this->logger->records)->toHaveCount(1)
            ->and($this->logger->records[0][2]['path'])->toBeNull()
            ->and($this->logger->records[0][2]['hooks'])->toBe(['wp_enqueue_scripts']);
    });

    it('does not let a logger that fails break the end of the request', function (): void {
        $logger = new class extends AbstractLogger
        {
            public int $attempts = 0;

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->attempts++;

                throw new RuntimeException('no log');
            }
        };

        $enqueuer = new AssetEnqueuer($this->assetManager, null, null, null, $this->runtime, $logger);
        unset($enqueuer);

        expect($logger->attempts)->toBe(1);
    });

    it('survives a failure with no logger at all', function (): void {
        $enqueuer = new AssetEnqueuer($this->assetManager, null, null, null, $this->runtime);
        unset($enqueuer);

        expect(true)->toBeTrue();
    });

    it('fails to destruct a Vite entry without a resolver, and says so', function (): void {
        $enqueuer = new AssetEnqueuer($this->assetManager, $this->hookAction, $this->hookFilter, null, $this->runtime, $this->logger);
        ($this->with)($enqueuer, ['useVite' => true]);
        unset($enqueuer);

        expect($this->logger->records[0][2]['error'])->toBe('Vite manager not initialized. Call useVite() first.')
            ->and($this->actions)->toBe([]);
    });
});

describe('the Vite client', function (): void {
    beforeEach(function (): void {
        $this->client = fn (AssetEnqueuer $enqueuer, string $hook) => (function () use ($hook): void {
            $this->loadViteClient($hook);
        })->call($enqueuer);
    });

    it('is enqueued as a module with an id made of its URL, and no version', function (): void {
        $this->viteManager->shouldReceive('clientUrl')->andReturn('https://site.test:5173/@vite/client');
        $enqueuer = ($this->with)(($this->make)(), ['viteManager' => $this->viteManager]);

        ($this->client)($enqueuer, 'wp_enqueue_scripts');
        ($this->actions[0][1])();

        expect($this->calls)->toBe([['wp_enqueue_script_module', ['vite-client/'.md5('https://site.test:5173/@vite/client'), 'https://site.test:5173/@vite/client', [], null]]]);
    });

    it('is not enqueued when the resolver has no URL for it', function (): void {
        $this->viteManager->shouldReceive('clientUrl')->andReturn('');
        $enqueuer = ($this->with)(($this->make)(), ['viteManager' => $this->viteManager]);

        ($this->client)($enqueuer, 'wp_enqueue_scripts');
        ($this->actions[0][1])();

        expect($this->calls)->toBe([]);
    });

    it('is not enqueued when there is no resolver at all', function (): void {
        $enqueuer = ($this->make)();

        ($this->client)($enqueuer, 'wp_enqueue_scripts');
        ($this->actions[0][1])();

        expect($this->calls)->toBe([]);
    });

    it('is printed as a tag, at priority 1, where WordPress prints no modules', function (): void {
        $this->viteManager->shouldReceive('isRunningHot')->andReturn(true);
        $this->viteManager->shouldReceive('getViteClientHtml')->andReturn('<script src="client"></script>');
        $enqueuer = ($this->with)(($this->make)(), ['viteManager' => $this->viteManager]);

        ($this->client)($enqueuer, 'login_enqueue_scripts');

        ob_start();
        ($this->actions[0][1])();

        expect($this->actions[0][0])->toBe('login_enqueue_scripts')
            ->and($this->actions[0][2])->toBe(1)
            ->and(ob_get_clean())->toBe('<script src="client"></script>');
    });

    it('is not printed when Vite does not run hot, nor without a resolver', function (): void {
        $notHot = ($this->with)(($this->make)(), ['viteManager' => $this->viteManager]);
        ($this->client)($notHot, 'login_enqueue_scripts');
        ($this->client)(($this->make)(), 'login_enqueue_scripts');

        ob_start();
        ($this->actions[0][1])();
        ($this->actions[1][1])();

        expect(ob_get_clean())->toBe('');
    });

    it('is needed only for a Vite asset served hot', function (bool $useVite, ?bool $hot, bool $expected): void {
        $manager = null;
        if ($hot !== null) {
            $manager = Mockery::mock(ViteManagerInterface::class);
            $manager->shouldReceive('isRunningHot')->andReturn($hot);
        }
        $enqueuer = ($this->with)(($this->make)(), ['useVite' => $useVite, 'viteManager' => $manager]);

        $needed = (fn (): bool => $this->needToLoadViteClient())->call($enqueuer);

        expect($needed)->toBe($expected);
    })->with([
        'vite, hot' => [true, true, true],
        'vite, not hot' => [true, false, false],
        'vite, no resolver' => [true, null, false],
        'not vite, hot' => [false, true, false],
        'not vite, not hot' => [false, false, false],
        'not vite, no resolver' => [false, null, false],
    ]);
});

describe('a classic script', function (): void {
    it('gets its dependencies, version, footer flag, data, strategy and inline script', function (): void {
        $enqueuer = ($this->make)()->handle('app')->path('app.js')->dependencies(['jquery'])->version('1.0')->loadInFooter()
            ->localize('first', ['a' => 1])->localize('second', ['b' => 2])->loadStrategy('defer')->inline('x = 1;', 'before');

        $enqueuer->enqueueStyleOrScript('login_enqueue_scripts');

        expect($this->calls)->toBe([
            ['wp_enqueue_script', ['app', 'home:/app.js', ['jquery'], '1.0', true]],
            ['wp_localize_script', ['app', 'first', ['a' => 1]]],
            ['wp_localize_script', ['app', 'second', ['b' => 2]]],
            ['wp_script_add_data', ['app', 'defer', true]],
            ['wp_add_inline_script', ['app', 'x = 1;', 'before']],
        ]);
    });

    it('is placed in the footer only when asked', function (): void {
        ($this->make)()->handle('app')->path('app.js')->enqueueStyleOrScript();
        $footer = ($this->make)()->handle('app')->path('app.js')->loadInFooter();
        $footer->enqueueStyleOrScript();

        expect($this->calls[0][1][4])->toBeFalse()
            ->and($this->calls[1][1][4])->toBeTrue()
            ->and((new ReflectionProperty($footer, 'loadInFooter'))->getValue($footer))->toBeTrue();
    });

    it('adds no strategy and no inline script when they are null, empty or zero', function (?string $nothing): void {
        $enqueuer = ($this->make)()->handle('app')->path('app.js');
        ($this->with)($enqueuer, ['loadStrategy' => $nothing, 'inlineContent' => $nothing]);

        $enqueuer->enqueueStyleOrScript();

        expect(array_column($this->calls, 0))->toBe(['wp_enqueue_script']);
    })->with([[null], [''], ['0']]);

    it('takes the base path of its container into the URL', function (): void {
        $enqueuer = ($this->make)()->handle('app')->path('app.js');
        ($this->with)($enqueuer, ['container' => new AssetContainer('theme', ['base_path' => 'resources/assets'])]);

        $enqueuer->enqueueStyleOrScript();

        expect($this->calls[0][1][1])->toBe('home:resources/assets/app.js');
    });

    it('keeps a full URL as it is, and strips the leading slash of a path', function (): void {
        ($this->make)()->handle('a')->path('https://cdn.test/app.js')->enqueueStyleOrScript();
        ($this->make)()->handle('b')->path('//app.js')->enqueueStyleOrScript();

        expect($this->calls[0][1][1])->toBe('https://cdn.test/app.js')
            ->and($this->calls[1][1][1])->toBe('home:/app.js');
    });

    it('keeps its own handle when it does not use Vite, with or without a resolver', function (): void {
        ($this->make)()->handle('plain')->path('app.js')->enqueueStyleOrScript();
        $withResolver = ($this->with)(($this->make)()->handle('plain')->path('app.js'), ['viteManager' => $this->viteManager]);
        $withResolver->enqueueStyleOrScript();

        expect($this->calls[0][1][0])->toBe('plain')
            ->and($this->calls[1][1][0])->toBe('plain')
            ->and($this->filters)->toBe([]);
    });

    it('refuses a type that is neither a script nor a style, whatever its key', function (): void {
        $enqueuer = ($this->make)()->handle('x');
        ($this->with)($enqueuer, ['useVite' => true, 'viteManager' => $this->viteManager, 'path' => [0 => ['https://site.test/a.js']]]);

        expect(fn () => $enqueuer->enqueueStyleOrScript())->toThrow(InvalidArgumentException::class, 'Unsupported asset type: 0');
    });
});

describe('a Vite script served hot', function (): void {
    beforeEach(function (): void {
        $this->viteManager->shouldReceive('isRunningHot')->andReturn(true);
        $this->hot = function (): AssetEnqueuer {
            $enqueuer = ($this->make)()->handle('app');

            return ($this->with)($enqueuer, ['useVite' => true, 'viteManager' => $this->viteManager, 'type' => 'js', 'path' => 'https://dev.test/app.js']);
        };
    });

    it('keeps its handle and is tagged as a module', function (): void {
        ($this->hot)()->enqueueStyleOrScript('login_enqueue_scripts');

        expect($this->calls)->toBe([['wp_enqueue_script', ['app', 'https://dev.test/app.js', [], null, false]]])
            ->and($this->filters)->toHaveCount(1)
            ->and($this->filters[0][0])->toBe('script_loader_tag')
            ->and($this->filters[0][2])->toBe(10)
            ->and($this->filters[0][3])->toBe(3);
    });

    it('rewrites the tag of its own handle only', function (): void {
        ($this->hot)()->enqueueStyleOrScript('login_enqueue_scripts');
        $filter = $this->filters[0][1];

        expect($filter('<script src="a"></script>', 'app', 'https://dev.test/app.js'))
            ->toBe('<script type="module" crossorigin src="https://dev.test/app.js"></script>')
            ->and($filter('<script src="b"></script>', 'other', 'https://dev.test/b.js'))
            ->toBe('<script src="b"></script>');
    });

    it('escapes the URL it prints in the tag', function (): void {
        Functions\when('esc_url')->alias(fn (string $url): string => str_replace('&', '&#038;', $url));
        ($this->hot)()->enqueueStyleOrScript('login_enqueue_scripts');

        expect(($this->filters[0][1])('', 'app', 'https://dev.test/a.js?x=1&y=2'))
            ->toBe('<script type="module" crossorigin src="https://dev.test/a.js?x=1&#038;y=2"></script>');
    });
});

describe('a Vite build', function (): void {
    beforeEach(function (): void {
        $this->built = function (array $properties = []): AssetEnqueuer {
            $enqueuer = ($this->make)()->handle('buzz/script');

            return ($this->with)($enqueuer, ['useVite' => true, 'viteManager' => $this->viteManager, 'path' => ['js' => ['https://site.test/build/app-abc.js']], ...$properties]);
        };
    });

    it('is enqueued as a module with the version and the footer flag it was given', function (): void {
        (($this->built)(['version' => '2.1', 'loadInFooter' => true]))->enqueueStyleOrScript('wp_enqueue_scripts');

        expect($this->calls)->toBe([['wp_enqueue_script_module', ['buzz/script/app-abc-js', 'https://site.test/build/app-abc.js', [], '2.1', ['in_footer' => true]]]]);
    });

    it('marks the module tag, and keeps its other attributes', function (): void {
        (($this->built)())->enqueueStyleOrScript('wp_enqueue_scripts');

        expect($this->filters[0][0])->toBe('wp_script_attributes')
            ->and(($this->filters[0][1])(['id' => 'buzz/script/app-abc-js-js-module', 'src' => 'x']))->toBe(['id' => 'buzz/script/app-abc-js-js-module', 'src' => 'x', 'crossorigin' => true])
            ->and(($this->filters[0][1])(['id' => 'other', 'src' => 'x']))->toBe(['id' => 'other', 'src' => 'x'])
            ->and(($this->filters[0][1])(['src' => 'x']))->toBe(['src' => 'x']);
    });

    it('needs no companion script when it has nothing but itself', function (): void {
        (($this->built)())->enqueueStyleOrScript('wp_enqueue_scripts');

        expect(array_column($this->calls, 0))->toBe(['wp_enqueue_script_module']);
    });

    it('needs a companion script for dependencies alone, or data alone, or inline script alone', function (array $properties): void {
        (($this->built)($properties))->enqueueStyleOrScript('wp_enqueue_scripts');

        expect(array_column($this->calls, 0))->toContain('wp_register_script')
            ->and(array_column($this->calls, 0))->toContain('wp_enqueue_script');
    })->with([
        'dependencies' => [['dependencies' => ['jquery']]],
        'data' => [['localizationData' => ['d' => ['a' => 1]]]],
        'inline script' => [['inlineContent' => 'run();']],
    ]);

    it('registers the companion with no source, the dependencies, the version, in the head', function (): void {
        (($this->built)(['dependencies' => ['jquery'], 'version' => '3.0', 'localizationData' => ['d' => ['a' => 1]], 'inlineContent' => 'run();', 'inlinePosition' => 'before']))
            ->enqueueStyleOrScript('wp_enqueue_scripts');

        expect($this->calls)->toBe([
            ['wp_enqueue_script_module', ['buzz/script/app-abc-js', 'https://site.test/build/app-abc.js', [], '3.0', ['in_footer' => false]]],
            ['wp_register_script', ['buzz/script/app-abc-js-data', false, ['jquery'], '3.0', false]],
            ['wp_enqueue_script', ['buzz/script/app-abc-js-data']],
            ['wp_localize_script', ['buzz/script/app-abc-js-data', 'd', ['a' => 1]]],
            ['wp_add_inline_script', ['buzz/script/app-abc-js-data', 'run();', 'before']],
        ]);
    });

    it('puts no inline script on the companion when it is null, empty or zero', function (?string $nothing): void {
        (($this->built)(['dependencies' => ['jquery'], 'inlineContent' => $nothing]))->enqueueStyleOrScript('wp_enqueue_scripts');

        expect(array_column($this->calls, 0))->not->toContain('wp_add_inline_script');
    })->with([[null], [''], ['0']]);

    it('has no companion for an empty or zero inline script', function (string $nothing): void {
        (($this->built)(['inlineContent' => $nothing]))->enqueueStyleOrScript('wp_enqueue_scripts');

        expect(array_column($this->calls, 0))->toBe(['wp_enqueue_script_module']);
    })->with([[''], ['0']]);
});

describe('a stylesheet', function (): void {
    it('gets its dependencies, version, media and inline style', function (): void {
        $enqueuer = ($this->make)()->handle('look')->path('look.css')->dependencies(['base'])->version('4')->media('print')->inline('a{b:c}');

        $enqueuer->enqueueStyleOrScript();

        expect($this->calls)->toBe([
            ['wp_enqueue_style', ['look', 'home:/look.css', ['base'], '4', 'print']],
            ['wp_add_inline_style', ['look', 'a{b:c}']],
        ]);
    });

    it('adds no inline style when it is null, empty or zero', function (?string $nothing): void {
        $enqueuer = ($this->make)()->handle('look')->path('look.css');
        ($this->with)($enqueuer, ['inlineContent' => $nothing]);

        $enqueuer->enqueueStyleOrScript();

        expect(array_column($this->calls, 0))->toBe(['wp_enqueue_style']);
    })->with([[null], [''], ['0']]);

    it('has the default media "all"', function (): void {
        ($this->make)()->handle('look')->path('look.css')->enqueueStyleOrScript();

        expect($this->calls[0][1][4])->toBe('all');
    });
});

it('leaves out an empty dependency, which would keep WordPress from printing the asset', function (): void {
    ($this->make)()->handle('app')->path('app.js')->dependencies(['jquery', ''])->enqueueStyleOrScript();

    expect($this->calls[0][0])->toBe('wp_enqueue_script')
        ->and($this->calls[0][1][2])->toBe(['jquery']);
});

it('names an asset declared with an empty handle after its file', function (): void {
    ($this->make)()->handle('')->path('app.css')->enqueueStyleOrScript();

    expect($this->calls[0][1][0])->toBe('app-css');
});
