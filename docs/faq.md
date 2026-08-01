# Composer Extra FAQ {#composer-extra-faq}
Short answers for the cache, the runtime reader, and Composer plugin setup.

> **Prerequisites:** [Composer Extra Package](README.md)

## Getting Started {#getting-started}

### How do I read package `extra` data at runtime? {#use-extra}

Inject `Switon\ComposerExtra\ComposerExtraInterface` and call `get()`, `all()`, `has()`, `collect()`, or `health()`. See [Quick Start](README.md#quick-start).

### Why do I need `allow-plugins`? {#allow-plugins}

Composer 2.2+ blocks plugins until the root project allows them. Add `"switon/composer-extra": true` under `config.allow-plugins`. See [Configuration](README.md#configuration).

### What does `get()` return when the package is missing? {#get-miss}

An empty array `[]`, not `null`. Use `has($packageName)` for an existence check that ignores empty `extra`.

### How do I load path-installed package overlays? {#path-overlay}

Nothing to do. Path and symlink installs are detected during cache load and merged from each package's live `composer.json` until the runtime restarts or the cache rebuilds.

## Cache and Runtime {#cache-and-runtime}

### When is the cache regenerated? {#when-regenerated}

After `composer install` and `composer update`, through the `post-install-cmd` and `post-update-cmd` hooks.

### How do I check cache health quickly? {#cache-health-check}

Call `ComposerExtraInterface::health()` and inspect `ok` and `reason`. The non-`ok` reasons are `cache_missing`, `cache_unreadable`, `invalid_json`, `invalid_shape`, and `cache_empty`.

### What happens if the cache file is missing? {#cache-missing}

`health()` returns `ok: false` with `reason: cache_missing`. `all()` and `collect()` throw `Switon\ComposerExtra\Exception\ComposerExtraNotFoundException` because the cache is required for read paths.

### Can I edit the generated cache by hand? {#manual-edit}

No. The plugin overwrites it on the next install or update.
