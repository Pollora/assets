<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Asset\Application\Services\AssetFile;
use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Application\Services\MissingBuilds;
use Pollora\Asset\Asset;
use Pollora\Asset\Domain\Contracts\ViteManagerFactory;
use Pollora\Asset\Domain\Contracts\ViteManagerInterface;
use Pollora\Asset\Domain\Exceptions\AssetException;
use Pollora\Asset\Domain\Models\AssetContainer;
use Pollora\Asset\Infrastructure\WordPress\NativeViteManager;
use Pollora\Asset\Infrastructure\WordPress\StandaloneAssetFileResolver;
use Pollora\Asset\UI\Http\MissingBuildNotice;
use Psr\Log\AbstractLogger;

describe('the notice of missing builds, as printed', function (): void {
    beforeEach(function (): void {
        $this->ddev = getenv('IS_DDEV_PROJECT');
        putenv('IS_DDEV_PROJECT=false');
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_html__')->returnArg();

        $this->notice = function (MissingBuilds $missing, string $root = '', ?string $diagnosis = null): string {
            ob_start();
            (new MissingBuildNotice($missing, $root, $diagnosis))->render();

            return (string) ob_get_clean();
        };
        $this->missing = new MissingBuilds;
        $this->missing->record('theme', '/srv/site/public/build/theme/manifest.json');
    });

    afterEach(function (): void {
        putenv($this->ddev === false ? 'IS_DDEV_PROJECT' : 'IS_DDEV_PROJECT='.$this->ddev);
    });

    it('prints the whole notice, with no hint when it has no diagnosis command', function (): void {
        expect(($this->notice)($this->missing, '/srv/site'))->toBe(
            '<div class="notice notice-warning"><p><strong>Pollora: assets not built</strong></p>'
            .'<p>These Vite builds are missing, so their scripts and styles are left out: the site renders unstyled and blocks have no editor scripts.</p>'
            .'<ul><li><code>theme</code> — no manifest at <code>public/build/theme/manifest.json</code></li></ul>'
            .'<p>Build them from the folder of the theme, plugin or module:<br><code>npm install && npm run build</code></p></div>'
        );
    });

    it('adds the command that names the folder to build, plain outside DDEV', function (): void {
        expect(($this->notice)($this->missing, '/srv/site', 'php artisan pollora:doctor'))->toEndWith(
            '<p>Not sure which folder? The builds check names it:<br><code>php artisan pollora:doctor</code></p></div>'
        );
    });

    it('runs the commands through DDEV inside a DDEV project', function (): void {
        putenv('IS_DDEV_PROJECT=true');

        $notice = ($this->notice)($this->missing, '/srv/site', 'php artisan pollora:doctor');

        expect($notice)
            ->toContain('<code>ddev npm install && ddev npm run build</code>')
            ->toEndWith('<code>ddev exec php artisan pollora:doctor</code></p></div>');
    });

    it('lists one line for each manifest, with every container that shares it', function (): void {
        $this->missing->record('theme.blocks', '/srv/site/public/build/theme/manifest.json');
        $this->missing->record('plugin.acme', '/srv/site/public/build/plugin/manifest.json');

        expect(($this->notice)($this->missing, '/srv/site'))->toContain(
            '<ul><li><code>theme, theme.blocks</code> — no manifest at <code>public/build/theme/manifest.json</code></li>'
            .'<li><code>plugin.acme</code> — no manifest at <code>public/build/plugin/manifest.json</code></li></ul>'
        );
    });

    it('takes the root off the paths whether or not it ends with a slash, and only at their start', function (): void {
        $this->missing->record('elsewhere', '/srv/sitemap/build/manifest.json');

        expect(($this->notice)($this->missing, '/srv/site/'))
            ->toContain('<code>public/build/theme/manifest.json</code>')
            ->toContain('<code>/srv/sitemap/build/manifest.json</code>');
    });
});

describe('the manifest of a native Vite resolver', function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir().'/pollora-assets-behaviour-'.uniqid();
        mkdir($this->root.'/build', 0755, true);
        $this->manifest = fn (array $manifest) => file_put_contents($this->root.'/build/manifest.json', json_encode($manifest));
        $this->manager = fn (?MissingBuilds $missing = null, array $config = []): NativeViteManager => new NativeViteManager(new AssetContainer('theme', [
            'hot_file' => $this->root.'/hot',
            'build_directory' => 'build',
            'public_path' => $this->root,
            'public_url' => 'https://site.test/theme',
            'base_path' => 'src/',
            ...$config,
        ]), $missing);
    });

    afterEach(function (): void {
        foreach ([$this->root.'/build/manifest.json', $this->root.'/hot'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->root.'/build');
        rmdir($this->root);
    });

    it('does not read a manifest that is not there', function (): void {
        set_error_handler(function (int $level, string $message): never {
            throw new RuntimeException($message);
        });

        try {
            $urls = ($this->manager)()->getAssetUrls(['app.js']);
        } finally {
            restore_error_handler();
        }

        expect($urls)->toBe(['js' => [], 'css' => []]);
    });

    it('records a missing build only when somebody listens, and does not fail without', function (): void {
        $missing = new MissingBuilds;

        expect(($this->manager)()->isMissingBuild())->toBeTrue()
            ->and(($this->manager)($missing)->isMissingBuild())->toBeTrue()
            ->and($missing->all())->toBe(['theme' => $this->root.'/build/manifest.json']);
    });

    it('finds an entry point given with a leading slash', function (): void {
        ($this->manifest)(['src/app.js' => ['file' => 'app.js']]);

        expect(($this->manager)()->getAssetUrls(['/app.js']))->toBe(['js' => ['https://site.test/theme/build/app.js'], 'css' => []]);
    });

    it('leaves out an entry that has no file, as a chunk without one is not an asset', function (): void {
        ($this->manifest)(['src/imports.js' => ['css' => ['x.css']], 'src/app.js' => ['file' => 'app.js']]);

        expect(($this->manager)()->getAssetUrls(['imports.js', 'app.js']))->toBe(['js' => ['https://site.test/theme/build/app.js'], 'css' => []]);
    });

    it('lists each URL once, in order, with sequential keys', function (): void {
        ($this->manifest)([
            'src/a.js' => ['file' => 'a.js', 'css' => ['shared.css', 'a.css']],
            'src/b.js' => ['file' => 'b.js', 'css' => ['shared.css', 'b.css']],
        ]);

        expect(($this->manager)()->getAssetUrls(['a.js', 'a.js', 'b.js']))->toBe([
            'js' => ['https://site.test/theme/build/a.js', 'https://site.test/theme/build/b.js'],
            'css' => ['https://site.test/theme/build/shared.css', 'https://site.test/theme/build/a.css', 'https://site.test/theme/build/b.css'],
        ]);
    });

    it('takes the CSS of a chunk given as one string, and the file of a chunk given as a number', function (): void {
        ($this->manifest)(['src/a.js' => ['file' => 'a.js', 'css' => 'only.css'], 'src/n.js' => ['file' => 7, 'css' => [9]]]);

        expect(($this->manager)()->getAssetUrls(['a.js', 'n.js']))->toBe([
            'js' => ['https://site.test/theme/build/a.js', 'https://site.test/theme/build/7'],
            'css' => ['https://site.test/theme/build/only.css', 'https://site.test/theme/build/9'],
        ]);
    });

    it('refuses a chunk of the manifest that has no file when a file is asked for', function (): void {
        ($this->manifest)(['src/imports.js' => ['css' => ['x.css']]]);

        expect(fn () => ($this->manager)()->asset('imports.js'))->toThrow(AssetException::class, 'Unable to locate file in Vite manifest: src/imports.js.');
    });

    it('gives the built URL of a file whatever slashes the manifest puts in front', function (): void {
        ($this->manifest)(['src/a.js' => ['file' => '/a.js'], 'src/n.js' => ['file' => 12]]);

        expect(($this->manager)()->asset('a.js'))->toBe('https://site.test/theme/build/a.js')
            ->and(($this->manager)()->asset('n.js'))->toBe('https://site.test/theme/build/12');
    });
});

describe('the file resolver of the standalone assets', function (): void {
    beforeEach(function (): void {
        $this->logger = new class extends AbstractLogger
        {
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $this->assets = new AssetManager(emptyRetrievalService());
    });

    it('is the empty string, and says nothing, for a container nobody registered', function (): void {
        $factory = Mockery::mock(ViteManagerFactory::class);
        $factory->shouldReceive('make')->never();

        expect((new StandaloneAssetFileResolver($this->assets, $factory, $this->logger))('logo.svg', 'ghost'))->toBe('')
            ->and($this->logger->records)->toBe([]);
    });

    it('is what the resolver of the container says of the file', function (): void {
        $this->assets->addContainer('theme', []);
        $container = $this->assets->getContainer('theme');
        $vite = Mockery::mock(ViteManagerInterface::class);
        $vite->shouldReceive('asset')->with('logo.svg')->andReturn('https://site.test/logo.svg');
        $factory = Mockery::mock(ViteManagerFactory::class);
        $factory->shouldReceive('make')->with($container)->andReturn($vite);

        expect((new StandaloneAssetFileResolver($this->assets, $factory, $this->logger))('logo.svg', 'theme'))->toBe('https://site.test/logo.svg');
    });

    it('is the empty string and a line in the log when the resolver fails', function (): void {
        $this->assets->addContainer('theme', []);
        $vite = Mockery::mock(ViteManagerInterface::class);
        $vite->shouldReceive('asset')->andThrow(new AssetException('not in the manifest'));
        $factory = Mockery::mock(ViteManagerFactory::class);
        $factory->shouldReceive('make')->andReturn($vite);

        expect((new StandaloneAssetFileResolver($this->assets, $factory, $this->logger))('logo.svg', 'theme'))->toBe('')
            ->and($this->logger->records)->toBe([['error', 'Error in AssetFile::__toString', ['error' => 'not in the manifest', 'path' => 'logo.svg']]]);
    });

    it('is the empty string when the resolver fails and nobody listens', function (): void {
        $this->assets->addContainer('theme', []);
        $factory = Mockery::mock(ViteManagerFactory::class);
        $factory->shouldReceive('make')->andThrow(new RuntimeException('boom'));

        expect((new StandaloneAssetFileResolver($this->assets, $factory))('logo.svg', 'theme'))->toBe('');
    });
});

describe('the default container of the manager', function (): void {
    beforeEach(function (): void {
        $this->manager = new AssetManager(emptyRetrievalService());
        $this->manager->addContainer('', ['base_path' => 'empty']);
        $this->manager->addContainer('0', ['base_path' => 'zero']);
        $this->manager->addContainer('theme', ['base_path' => 'theme']);
    });

    it('is none until one is set', function (): void {
        expect($this->manager->getDefaultContainer())->toBeNull();
    });

    it('is the container that was set', function (): void {
        $this->manager->setDefaultContainer('theme');

        expect($this->manager->getDefaultContainer()?->getBasePath())->toBe('theme');
    });

    it('is none when the name set is empty or zero', function (string $name): void {
        $this->manager->setDefaultContainer($name);

        expect($this->manager->getDefaultContainer())->toBeNull();
    })->with(['', '0']);
});

describe('the defaults of a container', function (): void {
    it('puts the build of a container in build/ and its name, with a manifest.json and no base path', function (): void {
        $container = new AssetContainer('plugin.acme');

        expect($container->getBuildDirectory())->toBe('build/plugin.acme')
            ->and($container->getManifestPath())->toBe('manifest.json')
            ->and($container->getBasePath())->toBe('')
            ->and($container->getHotFile())->toBe('plugin.acme.hot')
            ->and($container->getPublicPath())->toBeNull()
            ->and($container->getPublicUrl())->toBeNull();
    });

    it('keeps what it is given, and takes the trailing slashes off its public path and URL', function (): void {
        $container = new AssetContainer('x', ['build_directory' => 'dist', 'manifest_path' => '.vite/manifest.json', 'base_path' => 'src/', 'public_path' => '/var/www//', 'public_url' => 'https://x.test/a/']);

        expect($container->getBuildDirectory())->toBe('dist')
            ->and($container->getManifestPath())->toBe('.vite/manifest.json')
            ->and($container->getBasePath())->toBe('src/')
            ->and($container->getPublicPath())->toBe('/var/www')
            ->and($container->getPublicUrl())->toBe('https://x.test/a');
    });
});

it('forgets the resolver of the asset files when the facade is reset', function (): void {
    AssetFile::resolveUsing(fn (string $file): string => 'https://site.test/'.$file);

    Asset::reset();

    expect((string) new AssetFile('logo.svg'))->toBe('');
});
