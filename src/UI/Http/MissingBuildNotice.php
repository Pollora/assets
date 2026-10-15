<?php

declare(strict_types=1);

namespace Pollora\Asset\UI\Http;

use Pollora\Asset\Application\Services\MissingBuilds;

/**
 * Says in wp-admin which Vite builds are missing, and how to build them.
 *
 * Without a build the site renders unstyled and its blocks have no editor
 * scripts, with nothing else to say why.
 */
final readonly class MissingBuildNotice
{
    /**
     * @param  string  $root  The project root, taken off the manifest paths the notice shows; empty to show them whole
     */
    public function __construct(private MissingBuilds $missingBuilds, private string $root = '') {}

    public function render(): void
    {
        $missing = $this->missingBuilds->all();

        if ($missing === [] || ! current_user_can('manage_options')) {
            return;
        }

        $ddev = getenv('IS_DDEV_PROJECT') === 'true';
        $build = $ddev ? 'ddev npm install && ddev npm run build' : 'npm install && npm run build';
        $doctor = ($ddev ? 'ddev exec ' : '').'php artisan pollora:doctor';

        // A theme's blocks share its build: one line per manifest
        $containersByManifest = [];
        foreach ($missing as $container => $manifest) {
            $containersByManifest[$manifest][] = $container;
        }

        $items = '';
        foreach ($containersByManifest as $manifest => $containers) {
            $items .= sprintf(
                '<li><code>%s</code> — %s <code>%s</code></li>',
                esc_html(implode(', ', $containers)),
                esc_html__('no manifest at', 'pollora'),
                esc_html($this->relative($manifest))
            );
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong></p><p>%s</p><ul>%s</ul><p>%s<br><code>%s</code></p><p>%s<br><code>%s</code></p></div>',
            esc_html__('Pollora: assets not built', 'pollora'),
            esc_html__('These Vite builds are missing, so their scripts and styles are left out: the site renders unstyled and blocks have no editor scripts.', 'pollora'),
            $items,
            esc_html__('Build them from the folder of the theme, plugin or module:', 'pollora'),
            esc_html($build),
            esc_html__('Not sure which folder? The builds check names it:', 'pollora'),
            esc_html($doctor)
        );
    }

    private function relative(string $path): string
    {
        if ($this->root === '') {
            return $path;
        }

        $base = rtrim($this->root, '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
