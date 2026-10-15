<?php

declare(strict_types=1);

use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Application\Services\AssetRetrievalService;
use Pollora\Asset\Domain\Contracts\PublicPath;
use Pollora\Asset\Domain\Exceptions\AssetException;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;
use Pollora\Asset\Infrastructure\Services\AssetEnqueuer;

/** A public directory at a known place */
function publicPathAt(string $root): PublicPath
{
    return new class($root) implements PublicPath
    {
        public function __construct(private readonly string $root) {}

        public function path(string $to = ''): string
        {
            return rtrim($this->root.'/'.$to, '/');
        }
    };
}

describe('AssetManager containers', function (): void {
    it('lists every container, keyed by name', function (): void {
        $manager = new AssetManager(Mockery::mock(AssetRetrievalService::class));
        $manager->addContainer('theme', ['hot_file' => 'theme.hot']);
        $manager->addContainer('acme-plugin', []);

        $containers = $manager->containers();

        expect(array_keys($containers))->toBe(['theme', 'acme-plugin'])
            ->and($containers['theme'])->toBeInstanceOf(AssetContainer::class)
            ->and($containers['theme']->getName())->toBe('theme');
    });

    it('lists nothing before a container is added', function (): void {
        expect((new AssetManager(Mockery::mock(AssetRetrievalService::class)))->containers())->toBe([]);
    });

    it('puts the hot file of a container that gives none in the public directory', function (): void {
        $manager = new AssetManager(Mockery::mock(AssetRetrievalService::class), publicPathAt('/var/www/public'));
        $manager->addContainer('acme', []);
        $manager->addContainer('theme', ['hot_file' => '/elsewhere/theme.hot']);

        expect($manager->getContainer('acme')->getHotFile())->toBe('/var/www/public/acme.hot')
            ->and($manager->getContainer('theme')->getHotFile())->toBe('/elsewhere/theme.hot');
    });

    it('leaves the hot file as the container names it when it has no public directory to ask', function (): void {
        $manager = new AssetManager(Mockery::mock(AssetRetrievalService::class));
        $manager->addContainer('acme', []);

        expect($manager->getContainer('acme')->getHotFile())->toBe('acme.hot');
    });

    it('keeps the default container apart from the others', function (): void {
        $manager = new AssetManager(Mockery::mock(AssetRetrievalService::class));
        $manager->addContainer('root', ['hot_file' => 'hot']);
        $manager->addContainer('theme', ['hot_file' => 'theme.hot']);

        expect($manager->getDefaultContainer())->toBeNull();

        $manager->setDefaultContainer('theme');

        expect($manager->getDefaultContainer()->getName())->toBe('theme')
            ->and($manager->getContainer('missing'))->toBeNull();
    });
});

describe('AssetManager::add()', function (): void {
    it('starts the enqueuer the environment builds, with the handle and the file', function (): void {
        $enqueuer = Mockery::mock(AssetEnqueuer::class);
        $enqueuer->shouldReceive('handle')->once()->with('theme/app')->andReturnSelf();
        $enqueuer->shouldReceive('path')->once()->with('app.js')->andReturnSelf();

        $manager = new AssetManager(Mockery::mock(AssetRetrievalService::class), null, fn (): AssetEnqueuer => $enqueuer);

        expect($manager->add('theme/app', 'app.js'))->toBe($enqueuer);
    });

    it('says so when the manager was built without a way to make an enqueuer', function (): void {
        $manager = new AssetManager(Mockery::mock(AssetRetrievalService::class));

        expect(fn () => $manager->add('theme/app', 'app.js'))->toThrow(AssetException::class, 'No asset enqueuer is set');
    });
});
