<?php

declare(strict_types=1);

/**
 * pollora/assets runs without Laravel: it uses the hook contracts, PSR-3 and
 * WordPress, nothing else. What binds it to Laravel's Vite, container and
 * helpers lives in the framework.
 */
arch('assets stays free of Laravel')
    ->expect('Pollora\Asset')
    ->not->toUse(['Illuminate', 'Laravel']);

arch('assets uses only what it declares')
    ->expect('Pollora\Asset')
    ->toOnlyUse([
        'Pollora\Asset',
        'Pollora\Hook\Domain\Contract',
        'Pollora\Hook\Adapter\Out\WordPress',
        'Psr\Log',
        'Closure',
        'Stringable',
        // WordPress
        '__',
        'add_action',
        'current_user_can',
        'esc_html',
        'esc_html__',
        'esc_url',
        'home_url',
        'sanitize_title',
        'wp_add_inline_script',
        'wp_add_inline_style',
        'wp_enqueue_script',
        'wp_enqueue_script_module',
        'wp_enqueue_style',
        'wp_localize_script',
        'wp_register_script',
        'wp_register_style',
    ])
    ->ignoring(['WP_Post', 'WP_Scripts']);
