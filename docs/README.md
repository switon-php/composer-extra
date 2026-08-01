# Composer Extra Package {#composer-extra-package}
`switon/composer-extra` turns installed package `extra` metadata into one cache file that runtime code reads without touching Composer internals on every request. Choose it when framework discovery (providers, commands, listeners, tasks, jobs) needs to be fast and stable at runtime.

**Key features:**

- **Runtime reader:** `Switon\ComposerExtra\ComposerExtraInterface` reads the generated cache through one DI-injected service.
- **Discovery lists:** `collect()` walks `extra.switon.*` paths and returns ordered string values across packages.
- **Composer refresh hooks:** `post-install-cmd` and `post-update-cmd` rebuild the cache automatically.
- **Local overlay:** path-installed packages can appear with current `composer.json` metadata during development.
- **Best-effort refresh:** cache write failures are reported without aborting Composer.

## Installation {#installation}

**Requirements:** PHP 8.3+, `ext-json`, Composer 2.0+, `composer-plugin-api` ^2.0.

```bash
composer require switon/composer-extra
```

Composer 2.2+ also requires the plugin in the root project's allowlist — see [Configuration](#configuration).

## Quick Start {#quick-start}

Inject `ComposerExtraInterface` and use `collect()` for discovery lists or `get()` / `all()` for raw `extra` data.

```php
namespace App\Service;

use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;

final class PackageRegistry
{
    #[Autowired] protected ComposerExtraInterface $composerExtra;

    public function providers(): array
    {
        return $this->composerExtra->collect('switon.providers');
    }

    public function cacheHealth(): array
    {
        return $this->composerExtra->health();
    }
}
```

## Core Concepts {#core-concepts}

### Cache flow {#cache-flow}

The plugin writes the cache during Composer hooks; runtime only reads.

- `post-install-cmd` and `post-update-cmd` invoke the plugin.
- The plugin reads each installed package's `extra` block from Composer metadata.
- The cache is written to `vendor/switon/composer-extra.json`.
- `Switon\ComposerExtra\ComposerExtra` lazy-loads that file on first read and reuses it in memory.
- For path-installed packages, the live `composer.json` overlay merges in during cache load so local edits surface without a second Composer run.

### Reader surface {#reader-surface}

- `get(string $packageName): array` — one package's `extra` data, or `[]` when missing.
- `all(): array` — full package map; throws when the cache file is missing or invalid.
- `has(string $packageName): bool` — existence check that returns `false` for empty `extra`.
- `health(): array` — non-throwing status with `ok`, `reason`, `cacheFile`, and `packageCount` (`reason` values: `ok`, `cache_missing`, `cache_unreadable`, `invalid_json`, `invalid_shape`, `cache_empty`).

### Discovery lists {#discovery-lists}

`collect(string $path)` walks a dot-separated path inside each package's merged `extra` and returns a flat list of non-empty strings.

```php
$providers = $composerExtra->collect('switon.providers');
$listeners = $composerExtra->collect('switon.listeners');
```

- The leaf may be one string or an array of strings (indexed list or string-keyed map; keys are ignored).
- Non-string values are skipped; empty path segments are ignored.
- Values are appended in package iteration order; duplicates are preserved.

## Configuration {#configuration}

There is no `switon.yml` block. The only setup most projects need is plugin allowlisting in the root `composer.json`:

```json
{
  "config": {
    "allow-plugins": {
      "switon/composer-extra": true
    }
  }
}
```

## Keep in Mind {#keep-in-mind}

- Do not edit `vendor/switon/composer-extra.json` by hand; the next install or update overwrites it.
- Use `collect()` for framework discovery metadata under `extra.switon.*`. App-level classes belong to each component's own scanner or config.
- `health()` is the quickest signal when install/update hooks were skipped or path-package overlays misbehave.

## Next {#next}

- **For cache failures and edge cases:** [FAQ](faq.md#composer-extra-faq)
- **For trade-offs and rationale:** [Design Rationale](design.md#design-rationale)
