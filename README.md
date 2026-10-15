<p align="center">
  <a href="https://pollora.dev">
    <img src="https://raw.githubusercontent.com/Pollora/.github/main/brand/banners/assets.png" width="100%" alt="Pollora Assets: scripts, styles and Vite builds for WordPress">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/pollora/assets"><img src="https://img.shields.io/packagist/v/pollora/assets" alt="Latest version"></a>
  <a href="https://packagist.org/packages/pollora/assets"><img src="https://img.shields.io/packagist/dt/pollora/assets" alt="Total downloads"></a>
  <a href="https://github.com/Pollora/assets/actions/workflows/tests.yml"><img src="https://github.com/Pollora/assets/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/Pollora/assets" alt="License"></a>
</p>

Scripts, styles and Vite builds for WordPress: enqueue an asset with a fluent API, and let it resolve its URL from the Vite manifest or the dev server, per theme, plugin or module. The same code serves a built file in production and the dev server while Vite runs, with hot reload.

> Part of [Pollora](https://pollora.dev), the Laravel framework for WordPress, where it is installed and wired for you: see [Assets & Vite](https://pollora.dev/theming/assets-vite/). It runs without Laravel too: in a classic theme, a plugin or a Bedrock site.

## Installation

```bash
composer require pollora/assets
```

Requires PHP 8.3+ and WordPress. It builds on [`pollora/hook`](https://github.com/Pollora/hook) for the WordPress hooks.

## In a theme or a plugin, with or without Bedrock

`Asset` is the framework's `Asset` facade for a project with no Laravel: the same calls, forwarded to the same asset manager, wired to WordPress. In a Pollora project the framework declares the containers of the theme, the plugins and the modules; here you declare yours once.

```php
// functions.php of a classic theme, or web/app/themes/my-theme/functions.php in Bedrock
use Pollora\Asset\Asset;

Asset::addContainer('theme', [
    'hot_file' => get_stylesheet_directory().'/hot',       // exists while the dev server runs
    'build_directory' => 'build',                          // where Vite built, relative to the public directory
    'manifest_path' => 'manifest.json',                    // in the build directory
    'base_path' => 'resources/assets/',                    // what add('…', 'app.js') is relative to
    'public_path' => get_stylesheet_directory(),           // the public directory, on disk
    'public_url' => get_stylesheet_directory_uri(),        // and as a URL
]);

Asset::add('my-theme/app', 'app.js')->container('theme')->useVite()->toFrontend();
```

The first four keys are the ones the framework sets for a theme, a plugin or a module. The last two are the only addition: Laravel has one public directory for the whole application (`public_path()`, `asset()`), where a classic theme or a plugin is its own root and gives its own. A plugin does the same under its own name, `plugin.acme`.

A container is a folder with a Vite build in it:

```
my-theme/
├── build/               what Vite built: manifest.json and assets/
├── hot                  exists only while the dev server runs
├── resources/assets/    the entry points: app.js, app.css…
└── vite.config.js
```

Build it with [`@pollora/vite-config`](https://github.com/Pollora/vite-config), whose standalone layout writes exactly that:

```js
// vite.config.js
import { defineConfig } from 'vite';
import pollora from '@pollora/vite-config';

export default defineConfig({
    plugins: [pollora({ type: 'theme', layout: 'standalone' })],
});
```

`npm run build` writes `build/`; `npm run dev` starts the dev server and writes `hot`. `add('my-theme/app', 'app.js')` names the entry `resources/assets/app.js`: files are looked up under the `base_path` of the container.

## What it does

| In production | While Vite runs (`hot` exists) |
| --- | --- |
| The entry is enqueued as a script module, at the URL of the built file with its hash, and its CSS next to it | The entry is enqueued from the dev server, and the Vite client with it: save a file and the page updates |
| Several containers build independently: a theme, a plugin and a block can each have their own build | Each container follows its own dev server: a theme on `:5173` and a plugin on `:5174` run at once |
| No build: nothing is enqueued, the log says which manifest is missing and wp-admin shows a notice, instead of a fatal error on every page | |

## Enqueuing

```php
Asset::add('my-theme/app', 'app.js')->container('theme')->useVite()->toFrontend();
Asset::add('my-theme/editor', 'editor.css')->container('theme')->useVite()->toEditor();
Asset::add('my-theme/admin', 'admin.js')->container('theme')->useVite()->toBackend()->dependencies(['wp-api-fetch']);
Asset::add('my-theme/login', 'login.css')->container('theme')->useVite()->toLoginScreen();
```

| | |
| --- | --- |
| `container('theme')` | Which container the file belongs to |
| `useVite()` | Resolve the file through the manifest (or the dev server) |
| `toFrontend()`, `toBackend()`, `toLoginScreen()`, `toCustomizer()`, `toEditor()` | The WordPress hook it is enqueued on; `toFrontend()` when you give none |
| `dependencies([...])`, `version()`, `media()`, `loadInFooter()`, `loadStrategy()` | What `wp_enqueue_script()` and `wp_enqueue_style()` take |
| `localize($name, $data)`, `inline($code, 'after')` | `wp_localize_script()` and an inline script or style |
| `setType('js' or 'css')` | When the extension does not say it |

The enqueue happens when the builder goes out of scope, so end the chain with a call and do not keep the object. The command line (WP-CLI) enqueues nothing.

A Vite entry is an ES module: on the front end and in wp-admin it is enqueued with `wp_enqueue_script_module()`, so WordPress prints the import map, and its CSS with `wp_enqueue_style()`. Where WordPress prints no modules (the block editor), the script keeps a classic tag with `type="module"`.

## A file's URL

```php
use Pollora\Asset\Infrastructure\Services\AssetFile;

$logo = (string) (new AssetFile('images/logo.svg'))->from('theme');
```

The URL of a file the manifest lists: the hashed built file, or the dev server's. An unknown container or file gives an empty string, and a line in the log.

## Without a build

Where there is neither a `hot` file nor a manifest (the build was never run, or failed), the container resolves nothing: no URL, no fatal error. The missing build is recorded, written once to the log if you gave one (`Asset::useLogger($psr3Logger)`), and named in wp-admin with the command to run.

## In a Pollora project

The framework builds the containers of the theme, the plugins and the modules itself, shares one public directory between them (`public/build/<type>/<name>`), and resolves URLs through Laravel's Vite, so `ASSET_URL`, a CDN and the application URL keep working. See [Assets & Vite](https://pollora.dev/theming/assets-vite/).

What binds the package to an environment sits behind three small contracts, which the framework implements for Laravel and this package for WordPress:

| Contract | Does | Here (WordPress) |
| --- | --- | --- |
| `ViteManagerFactory` | Resolves a container's files from its manifest and hot file | `NativeViteManagerFactory`, from the `public_path` and `public_url` of the container |
| `RuntimeContext` | Tells the command line, where nothing is enqueued | `WordPressRuntimeContext` |
| `PublicPath` | Where a container's hot file goes when it names none | not needed: a container names its own |

## License

MIT
