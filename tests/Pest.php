<?php

declare(strict_types=1);

use Brain\Monkey;
use Pollora\Asset\Application\Services\AssetRetrievalService;
use Pollora\Asset\Domain\Services\AssetContainerManager;
use Pollora\Asset\Infrastructure\Repositories\InMemoryAssetRepository;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| No framework to boot. WordPress functions are stubbed with Brain Monkey,
| and Mockery's expectations count as assertions.
|
*/

pest()->beforeEach(function (): void {
    Monkey\setUp();
})->afterEach(function (): void {
    if ($container = Mockery::getContainer()) {
        $this->addToAssertionCount($container->mockery_getExpectationCount());
    }

    Monkey\tearDown();
})->in('Unit');

/**
 * A real retrieval service over an empty in-memory repository.
 *
 * The services are final, so a test builds them instead of mocking them.
 */
function emptyRetrievalService(): AssetRetrievalService
{
    return new AssetRetrievalService(new AssetContainerManager(new InMemoryAssetRepository));
}
