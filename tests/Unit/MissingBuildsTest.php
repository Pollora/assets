<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Asset\Application\Services\MissingBuilds;
use Pollora\Asset\UI\Http\MissingBuildNotice;
use Psr\Log\AbstractLogger;

/** A PSR-3 logger that keeps what it is given */
function recordingLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };
}

describe('MissingBuilds', function (): void {
    it('records a container once, with its manifest, and warns once', function (): void {
        $logger = recordingLogger();
        $missing = new MissingBuilds($logger);

        $missing->record('theme', '/var/www/public/build/theme/x/manifest.json');
        $missing->record('theme', '/var/www/public/build/theme/x/manifest.json');
        $missing->record('plugin.acme', '/var/www/public/build/plugin/acme/manifest.json');

        expect($missing->all())->toBe([
            'theme' => '/var/www/public/build/theme/x/manifest.json',
            'plugin.acme' => '/var/www/public/build/plugin/acme/manifest.json',
        ])
            ->and($logger->records)->toHaveCount(2)
            ->and($logger->records[0]['level'])->toBe('warning')
            ->and($logger->records[0]['context'])->toBe(['container' => 'theme', 'manifest' => '/var/www/public/build/theme/x/manifest.json']);
    });

    it('records without a logger', function (): void {
        $missing = new MissingBuilds;
        $missing->record('theme', '/x/manifest.json');

        expect($missing->all())->toBe(['theme' => '/x/manifest.json']);
    });
});

describe('MissingBuildNotice', function (): void {
    beforeEach(function (): void {
        // The commands it shows depend on DDEV: pin the plain ones
        $this->ddev = getenv('IS_DDEV_PROJECT');
        putenv('IS_DDEV_PROJECT=false');

        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_html__')->returnArg();
    });

    afterEach(function (): void {
        putenv($this->ddev === false ? 'IS_DDEV_PROJECT' : 'IS_DDEV_PROJECT='.$this->ddev);
    });

    it('names the missing build and the commands to run, with the manifest from the project root', function (): void {
        $missing = new MissingBuilds;
        $missing->record('theme', '/srv/site/public/build/theme/default/manifest.json');
        $missing->record('theme.blocks', '/srv/site/public/build/theme/default/manifest.json');

        ob_start();
        (new MissingBuildNotice($missing, '/srv/site'))->render();
        $notice = (string) ob_get_clean();

        expect($notice)
            ->toContain('Pollora: assets not built')
            ->toContain('theme, theme.blocks')
            ->and(substr_count($notice, 'public/build/theme/default/manifest.json'))->toBe(1)
            ->and($notice)->not->toContain('/srv/site/public')
            ->toContain('npm install && npm run build');
    });

    it('shows the whole path when it has no project root', function (): void {
        $missing = new MissingBuilds;
        $missing->record('theme', '/srv/site/public/build/theme/default/manifest.json');

        ob_start();
        (new MissingBuildNotice($missing))->render();

        expect((string) ob_get_clean())->toContain('/srv/site/public/build/theme/default/manifest.json');
    });

    it('stays silent when every build is there, and for those who cannot manage options', function (): void {
        ob_start();
        (new MissingBuildNotice(new MissingBuilds))->render();
        $silent = (string) ob_get_clean();

        Functions\when('current_user_can')->justReturn(false);
        $missing = new MissingBuilds;
        $missing->record('theme', '/x/manifest.json');
        ob_start();
        (new MissingBuildNotice($missing))->render();

        expect($silent)->toBe('')->and((string) ob_get_clean())->toBe('');
    });
});
