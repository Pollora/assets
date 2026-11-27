# Changelog

All notable changes to `pollora/assets` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- PHPStan runs at level 10. A container option that is not text, and a Vite entry enqueued before its URLs were resolved, raise `AssetException` instead of a `TypeError` or an `Error`; a manifest entry whose file is not text is read as absent

### Fixed

- Development only: `antecedent/patchwork` is required at commit `d588654` of its `master` branch (antecedent/patchwork#235), aliased as 2.2.6, so that Pest mutants are no longer counted as caught when tests hang. This pin is temporary until the next Patchwork release
- An empty string in `dependencies()` is left out: WordPress never finds such a dependency and silently dropped the asset
- An asset declared with an empty handle is named after its file, instead of sharing the handle `''` with every other one

## [0.2.0] - 2026-11-23

### Changed

- `AssetFile` (application), `AssetRegistrationService`, `MissingBuilds`, `AssetException`, `ViteManager`, `AssetContainerManager`, `InMemoryAssetRepository` and `AssetEnqueuer` are now `final` (CONV-08). This is a restriction of the API for anyone who extended them: a class that is not an extension point can no longer be subclassed; compose them instead
- **BREAKING**: `AssetManager`, `AssetRetrievalService` and `AssetContainer` are now `final` (CONV-08), the second one also `readonly`. A class that extends one of them no longer loads; compose them instead. Code that mocked `AssetManager` or `AssetRetrievalService` builds the real object (`new AssetManager(new AssetRetrievalService(new AssetContainerManager(new InMemoryAssetRepository)))`) and registers its containers with `addContainer()`. `Asset` stays open on purpose: `AssetRegistrationService::register()` instantiates the class it is given as `$assetClass`
- `AssetContainer` moves from `Infrastructure\Repositories` to `Domain\Models` and `AssetFile` from `Infrastructure\Services` to `Application\Services`, so that the domain and application layers no longer depend on the infrastructure layer. Each former class name stays available as a deprecated alias, removed in the next major version
- `AssetManager::add()` returns the new `AssetEnqueuerInterface` contract, which the `AssetEnqueuer` implements, instead of the concrete `AssetEnqueuer`. The fluent methods are the same
- An unknown asset container, and `useVite()` called before a container is chosen, throw the named `AssetContainerNotFoundException` exception instead of a bare `\RuntimeException`. It extends `\RuntimeException` and the messages are unchanged

## [0.1.0] - 2026-10-15

### Added

- The asset classes of the Pollora framework as a package of their own, class names unchanged: `AssetManager`, `AssetContainer`, `AssetEnqueuer` (`Asset::add()->container()->useVite()->toFrontend()`), `AssetFile`, the build-missing record and notice.
- Three contracts for what binds them to an environment: `ViteManagerFactory`, `RuntimeContext` and `PublicPath`. The framework implements them for Laravel.
- A native Vite resolver, `NativeViteManager`, for WordPress without Laravel: the URLs of a manifest or of the dev server in a hot file, the same as the framework's resolver answers for the same files.
- `Pollora\Asset\Asset`: the framework's `Asset` facade for a project with no Laravel (`Asset::addContainer()`, `Asset::add()`, `Asset::url()`…), forwarding to the asset manager wired to WordPress: its hooks, its URLs, the notice about builds that are missing. A classic theme, a plugin or a Bedrock site declares its containers with the keys of a Pollora container, and builds them with `@pollora/vite-config` and `layout: 'standalone'`.
- `AssetContainer` can name the directory its `build_directory` is relative to, and that directory's URL (`public_path`, `public_url`): the pair Laravel gets from `public_path()` and `asset()`, which a classic theme or a plugin, being its own root, gives itself.
