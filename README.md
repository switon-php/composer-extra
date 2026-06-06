# Switon Composer Extra Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/composer-extra/ci.yml?branch=main&label=CI)](https://github.com/switon-php/composer-extra/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's Composer plugin and runtime reader for cached `extra` metadata.

## Highlights

- **Cached package metadata:** composer install and update keep one shared metadata cache current.
- **Runtime access:** `ComposerExtraInterface` reads discovery data without extra parsing.
- **Discovery support:** `extra.switon.*` can feed providers, commands, listeners, tasks, and jobs.
- **Automatic refresh:** the Composer plugin keeps the cache fresh.
- **Safe health checks:** cache status can be checked without throwing.

## Installation

```bash
composer require switon/composer-extra
```

## Quick Start

```php
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;

final class PackageRegistry
{
    #[Autowired] protected ComposerExtraInterface $composerExtra;

    public function listeners(): array
    {
        return $this->composerExtra->collect('switon.listeners');
    }

    public function cacheHealth(): array
    {
        return $this->composerExtra->health();
    }
}
```

Docs: https://docs.switon.dev/latest/composer-extra

## License

MIT.
