# Changelog

All notable changes to `pollora/assets` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The asset classes of the Pollora framework as a package of their own, class names unchanged: `AssetManager`, `AssetContainer`, `AssetEnqueuer` (`Asset::add()->container()->useVite()->toFrontend()`), `AssetFile`, the build-missing record and notice.
- Three contracts for what binds them to an environment: `ViteManagerFactory`, `RuntimeContext` and `PublicPath`. The framework implements them for Laravel.
- A native Vite resolver, `NativeViteManager`, for WordPress without Laravel: the URLs of a manifest or of the dev server in a hot file, the same as the framework's resolver answers for the same files.
- `Pollora\Asset\Asset`: the framework's `Asset` facade for a project with no Laravel (`Asset::addContainer()`, `Asset::add()`, `Asset::url()`…), forwarding to the asset manager wired to WordPress: its hooks, its URLs, the notice about builds that are missing. A classic theme, a plugin or a Bedrock site declares its containers with the keys of a Pollora container, and builds them with `@pollora/vite-config` and `layout: 'standalone'`.
- `AssetContainer` can name the directory its `build_directory` is relative to, and that directory's URL (`public_path`, `public_url`): the pair Laravel gets from `public_path()` and `asset()`, which a classic theme or a plugin, being its own root, gives itself.
