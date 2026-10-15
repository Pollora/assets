<?php

declare(strict_types=1);

use Pollora\Asset\Infrastructure\Services\AssetFile;

afterEach(function (): void {
    AssetFile::resolveUsing(null);
});

describe('AssetFile', function (): void {
    it('is its URL, as the environment resolves it for the container it names', function (): void {
        AssetFile::resolveUsing(fn (string $file, string $container): string => 'https://site.test/'.$container.'/'.$file);

        expect((string) (new AssetFile('images/logo.svg'))->from('plugin.acme'))->toBe('https://site.test/plugin.acme/images/logo.svg')
            ->and((string) new AssetFile('images/logo.svg'))->toBe('https://site.test/theme/images/logo.svg');
    });

    it('is an empty string while nothing resolves it', function (): void {
        expect((string) new AssetFile('images/logo.svg'))->toBe('');
    });

    it('goes back to an empty string when the resolver is removed', function (): void {
        AssetFile::resolveUsing(fn (): string => 'https://site.test/x');
        AssetFile::resolveUsing(null);

        expect((string) new AssetFile('x.svg'))->toBe('');
    });
});
