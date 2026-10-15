# Changelog

All notable changes to `pollora/assets` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The asset classes of the Pollora framework as a package of their own, class names unchanged: `AssetManager`, `AssetContainer`, `AssetEnqueuer` (`Asset::add()->container()->useVite()->toFrontend()`), `AssetFile`, the build-missing record and notice.
- Three contracts for what binds them to an environment: `ViteManagerFactory`, `RuntimeContext` and `PublicPath`. The framework implements them for Laravel.
- A native Vite resolver, `NativeViteManager`, for WordPress without Laravel: the URLs of a manifest or of the dev server in a hot file, the same as the framework's resolver answers for the same files.
- `Assets::theme()`, `Assets::plugin()` and `Assets::container()`: the containers of a classic theme, a plugin or a Bedrock site, with their build and hot file inside the folder. Built by `@pollora/vite-config` with `layout: 'standalone'`.
- `AssetContainer` can name where its build is on disk and where it is served from (`build_path`, `build_url`).
