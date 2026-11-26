<?php

declare(strict_types=1);

use Pollora\Asset\Domain\Exceptions\AssetException;
use Pollora\Asset\Domain\Models\AssetContainer;

it('names the option of a container that is not text', function (string $option): void {
    expect(fn (): AssetContainer => new AssetContainer('theme', [$option => ['not', 'text']]))
        ->toThrow(AssetException::class, sprintf("The option '%s' of the asset container 'theme' must be a string.", $option));
})->with(['hot_file', 'build_directory', 'manifest_path', 'base_path', 'public_path', 'public_url']);

it('takes a stringable option as its text', function (): void {
    $path = new class implements Stringable
    {
        public function __toString(): string
        {
            return '/srv/theme/';
        }
    };

    expect((new AssetContainer('theme', ['public_path' => $path]))->getPublicPath())->toBe('/srv/theme');
});
