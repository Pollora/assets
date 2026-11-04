<?php

declare(strict_types=1);

use Pollora\Asset\Domain\Exceptions\AssetContainerNotFoundException;

it('names the container nobody registered, and says how to add it', function (): void {
    $exception = AssetContainerNotFoundException::named('theme');

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toBe("Asset container 'theme' not found. Make sure you have added it via AssetManager::addContainer().");
});

it('asks for a container before useVite()', function (): void {
    expect(AssetContainerNotFoundException::beforeUseVite())->toBeInstanceOf(RuntimeException::class)
        ->and(AssetContainerNotFoundException::beforeUseVite()->getMessage())->toContain("->container('theme') before ->useVite()");
});
