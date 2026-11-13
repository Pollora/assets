<?php

declare(strict_types=1);

use Pollora\Asset\Application\Services\AssetRegistrationService;
use Pollora\Asset\Application\Services\AssetRetrievalService;
use Pollora\Asset\Domain\Models\Asset;
use Pollora\Asset\Domain\Services\AssetContainerManager;
use Pollora\Asset\Infrastructure\Repositories\InMemoryAssetRepository;
use Pollora\Asset\Infrastructure\WordPress\WordPressRuntimeContext;

beforeEach(function (): void {
    $manager = new AssetContainerManager(new InMemoryAssetRepository);
    $this->registration = new AssetRegistrationService($manager);
    $this->retrieval = new AssetRetrievalService($manager);
});

it('returns a registered asset by its name', function (): void {
    $registration = $this->registration;
    $retrieval = $this->retrieval;

    $registration->register('app', 'js/app.js');

    expect($retrieval->get('app'))->toBeInstanceOf(Asset::class)
        ->and($retrieval->get('app')->getName())->toBe('app');
});

it('returns null for an asset that was never registered', function (): void {
    $retrieval = $this->retrieval;

    expect($retrieval->get('missing'))->toBeNull();
});

it('lists every registered asset', function (): void {
    $registration = $this->registration;
    $retrieval = $this->retrieval;

    $registration->register('a', 'a.js');
    $registration->register('b', 'b.css');

    expect(array_map(fn (Asset $asset) => $asset->getName(), $retrieval->all()))->toBe(['a', 'b']);
});

it('replaces an asset registered twice under the same name', function (): void {
    $registration = $this->registration;
    $retrieval = $this->retrieval;

    $registration->register('app', 'old.js');
    $registration->register('app', 'new.js');

    expect($retrieval->all())->toHaveCount(1);
});

it('instantiates the asset class given at registration', function (): void {
    $registration = $this->registration;
    $retrieval = $this->retrieval;
    $custom = new class('x', 'x.js') extends Asset {};

    $registration->register('custom', 'custom.js', [], $custom::class);

    expect($retrieval->get('custom'))->toBeInstanceOf($custom::class);
});

it('reports a command line context when running under the CLI', function (): void {
    expect((new WordPressRuntimeContext)->isCommandLine())->toBeTrue();
});
