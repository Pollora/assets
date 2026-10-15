<?php

declare(strict_types=1);

namespace Pollora\Asset\Application\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The asset containers asked for assets this request whose Vite build is missing.
 *
 * A theme, plugin or module that was never built (npm missing, a failed
 * build, `public/build` deleted) has neither a hot file nor a manifest. Its
 * assets then resolve to nothing instead of throwing, and the site stays
 * reachable: each missing build is logged once per request and listed in
 * wp-admin by `MissingBuildNotice`.
 */
class MissingBuilds
{
    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    /**
     * Manifest path of each container whose build is missing, by container name.
     *
     * @var array<string, string>
     */
    private array $missing = [];

    public function record(string $container, string $manifest): void
    {
        if (isset($this->missing[$container])) {
            return;
        }

        $this->missing[$container] = $manifest;

        ($this->logger ?? new NullLogger)->warning(sprintf('Pollora: the "%s" assets are not built, no Vite manifest at %s. Its scripts and styles are left out until you run npm run build.', $container, $manifest), [
            'container' => $container,
            'manifest' => $manifest,
        ]);
    }

    /**
     * @return array<string, string> Manifest path by container name
     */
    public function all(): array
    {
        return $this->missing;
    }
}
