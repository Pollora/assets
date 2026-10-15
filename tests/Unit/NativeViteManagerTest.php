<?php

declare(strict_types=1);

use Pollora\Asset\Application\Services\MissingBuilds;
use Pollora\Asset\Domain\Exceptions\AssetException;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;
use Pollora\Asset\Infrastructure\WordPress\NativeViteManager;
use Pollora\Asset\Infrastructure\WordPress\NativeViteManagerFactory;

/*
 * The native resolver answers what Pollora's Laravel resolver answers: these
 * are the manifests and expectations of the framework's container contract
 * test (AssetContainersContractTest), with URLs built from the URL of the
 * build directory instead of the application's.
 */

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/pollora-assets-'.uniqid();
    mkdir($this->root.'/theme/build', 0755, true);
    mkdir($this->root.'/plugin/build', 0755, true);

    file_put_contents($this->root.'/theme/build/manifest.json', json_encode([
        'resources/assets/app.js' => ['file' => 'assets/app-AAA.js', 'css' => ['assets/app-BBB.css']],
        'resources/assets/app.css' => ['file' => 'assets/app-CCC.css'],
        'resources/assets/images/logo.svg' => ['file' => 'assets/logo-DDD.svg'],
        'resources/views/blocks/card/index.jsx' => ['file' => 'assets/index-FFF.js', 'css' => ['assets/index-GGG.css']],
    ]));
    file_put_contents($this->root.'/plugin/build/manifest.json', json_encode([
        'resources/assets/admin.js' => ['file' => 'assets/admin-EEE.js'],
    ]));

    $this->container = function (string $name, string $kind, array $overrides = []): AssetContainer {
        return new AssetContainer($name, [
            'hot_file' => $this->root.'/'.$kind.'/hot',
            'build_path' => $this->root.'/'.$kind.'/build',
            'build_url' => 'https://site.test/content/'.$kind.'s/x/build',
            'manifest_path' => 'manifest.json',
            'base_path' => 'resources/assets/',
            ...$overrides,
        ]);
    };
    $this->theme = fn (): NativeViteManager => new NativeViteManager(($this->container)('theme', 'theme'), $this->missing ??= new MissingBuilds);
    $this->plugin = fn (): NativeViteManager => new NativeViteManager(($this->container)('plugin.acme', 'plugin'));
});

afterEach(function (): void {
    foreach ([$this->root.'/theme/build/manifest.json', $this->root.'/plugin/build/manifest.json', $this->root.'/theme/hot', $this->root.'/plugin/hot'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (['theme/build', 'plugin/build', 'theme', 'plugin', ''] as $directory) {
        if (is_dir($this->root.'/'.$directory)) {
            rmdir($this->root.'/'.$directory);
        }
    }
});

describe('NativeViteManager built for production', function (): void {
    it('gives the URLs of an entry point, its CSS chunks apart', function (): void {
        expect(($this->theme)()->getAssetUrls(['app.js']))->toBe([
            'js' => ['https://site.test/content/themes/x/build/assets/app-AAA.js'],
            'css' => ['https://site.test/content/themes/x/build/assets/app-BBB.css'],
        ])
            ->and(($this->theme)()->getAssetUrls(['app.css']))->toBe([
                'js' => [],
                'css' => ['https://site.test/content/themes/x/build/assets/app-CCC.css'],
            ]);
    });

    it('keeps the build of each container apart', function (): void {
        expect(($this->plugin)()->getAssetUrls(['admin.js']))->toBe([
            'js' => ['https://site.test/content/plugins/x/build/assets/admin-EEE.js'],
            'css' => [],
        ]);
    });

    it('resolves a file the way the manifest names it, under the base path of its container', function (): void {
        expect(($this->theme)()->asset('images/logo.svg'))->toBe('https://site.test/content/themes/x/build/assets/logo-DDD.svg');
    });

    it('resolves block entries from a container with an empty base path', function (): void {
        $blocks = new NativeViteManager(($this->container)('theme.blocks', 'theme', ['base_path' => '']));

        expect($blocks->getAssetUrls(['resources/views/blocks/card/index.jsx']))->toBe([
            'js' => ['https://site.test/content/themes/x/build/assets/index-FFF.js'],
            'css' => ['https://site.test/content/themes/x/build/assets/index-GGG.css'],
        ]);
    });

    it('leaves out an entry point the manifest does not know, and lists a URL once', function (): void {
        expect(($this->theme)()->getAssetUrls(['nothing.js', 'app.js', 'app.js']))->toBe([
            'js' => ['https://site.test/content/themes/x/build/assets/app-AAA.js'],
            'css' => ['https://site.test/content/themes/x/build/assets/app-BBB.css'],
        ]);
    });

    it('refuses an empty list of entry points, and a file the manifest does not list', function (): void {
        expect(fn () => ($this->theme)()->getAssetUrls([]))->toThrow(AssetException::class, 'Entry points array cannot be empty.')
            ->and(fn () => ($this->theme)()->asset('images/nothing.svg'))->toThrow(AssetException::class, 'Unable to locate file in Vite manifest: resources/assets/images/nothing.svg.');
    });

    it('is not running hot, and has no Vite client', function (): void {
        $theme = ($this->theme)();

        expect($theme->isRunningHot())->toBeFalse()
            ->and($theme->clientUrl())->toBe('')
            ->and($theme->getViteClientHtml())->toBe('')
            ->and($theme->isMissingBuild())->toBeFalse();
    });

    it('does not trust a trailing slash on the build URL or the build path', function (): void {
        $manager = new NativeViteManager(($this->container)('theme', 'theme', [
            'build_url' => 'https://site.test/content/themes/x/build/',
            'build_path' => $this->root.'/theme/build/',
        ]));

        expect($manager->asset('images/logo.svg'))->toBe('https://site.test/content/themes/x/build/assets/logo-DDD.svg');
    });
});

describe('NativeViteManager served by the dev server', function (): void {
    beforeEach(function (): void {
        // As @pollora/vite-config writes them: the origin and the path of the build
        file_put_contents($this->root.'/theme/hot', 'https://site.test:5173/build/theme/x'."\n");
        file_put_contents($this->root.'/plugin/hot', 'https://site.test:5174/build/plugin/x');
    });

    it('sends each container to its own dev server', function (): void {
        expect(($this->theme)()->isRunningHot())->toBeTrue()
            ->and(($this->theme)()->asset('app.js'))->toBe('https://site.test:5173/build/theme/x/resources/assets/app.js')
            ->and(($this->plugin)()->asset('admin.js'))->toBe('https://site.test:5174/build/plugin/x/resources/assets/admin.js');
    });

    it('gives each served container the Vite client of its own server', function (): void {
        expect(($this->theme)()->clientUrl())->toBe('https://site.test:5173/build/theme/x/@vite/client')
            ->and(($this->plugin)()->clientUrl())->toBe('https://site.test:5174/build/plugin/x/@vite/client')
            ->and(($this->theme)()->getViteClientHtml())->toBe('<script type="module" src="https://site.test:5173/build/theme/x/@vite/client"></script>');
    });

    it('does not count a served container as missing its build', function (): void {
        unlink($this->root.'/theme/build/manifest.json');

        expect(($this->theme)()->isMissingBuild())->toBeFalse()
            ->and($this->missing->all())->toBe([]);
    });

    it('leaves a container that is not served on its build', function (): void {
        unlink($this->root.'/plugin/hot');

        expect(($this->plugin)()->asset('admin.js'))->toBe('https://site.test/content/plugins/x/build/assets/admin-EEE.js');
    });
});

describe('NativeViteManager without a build', function (): void {
    beforeEach(function (): void {
        unlink($this->root.'/theme/build/manifest.json');
    });

    it('gives no asset URLs instead of throwing', function (): void {
        $theme = ($this->theme)();

        expect($theme->getAssetUrls(['app.js']))->toBe(['js' => [], 'css' => []])
            ->and($theme->asset('images/logo.svg'))->toBe('')
            ->and($theme->isMissingBuild())->toBeTrue();
    });

    it('records the missing build, with its manifest', function (): void {
        ($this->theme)()->getAssetUrls(['app.js']);

        expect($this->missing->all())->toBe(['theme' => $this->root.'/theme/build/manifest.json']);
    });
});

describe('NativeViteManager and its container', function (): void {
    it('needs to know where the build is and where it is served from', function (): void {
        expect(fn () => new NativeViteManager(new AssetContainer('theme', ['build_path' => '/x'])))
            ->toThrow(AssetException::class, "The asset container 'theme' has no build_path and build_url")
            ->and(fn () => new NativeViteManager(new AssetContainer('theme', ['build_url' => 'https://x.test/build'])))
            ->toThrow(AssetException::class);
    });

    it('is the one its factory makes for a container', function (): void {
        $manager = (new NativeViteManagerFactory)->make(($this->container)('theme', 'theme'));

        expect($manager)->toBeInstanceOf(NativeViteManager::class)
            ->and($manager->container()->getName())->toBe('theme');
    });
});
