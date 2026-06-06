<?php

declare(strict_types=1);

namespace Switon\ComposerExtra;

use Switon\ComposerExtra\Exception\ComposerExtraNotFoundException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\Json;
use Switon\Core\Runtime;
use Throwable;

use function array_key_exists;
use function array_values;
use function dirname;
use function explode;
use function file_exists;
use function is_array;
use function is_link;
use function is_string;

/**
 * Reads merged `extra` metadata from the Composer cache.
 *
 * Guidance: Use `extra.switon.*` for framework discovery only; app classes belong to each component's own config or scanners.
 *
 * Road-signs:
 * - cache file: `vendor/switon/composer-extra.json`
 * - lazy load on first read
 * - discovery hooks: providers, commands, listeners, tasks, jobs
 * - missing cache raises `ComposerExtraNotFoundException`
 * - plugin-generated cache is the only runtime source
 *
 * Core rules:
 * - reads the plugin-generated cache, not live `composer.json`
 * - package order is preserved during class collection
 * - duplicate string values are preserved in package order
 *
 * @see \Switon\ComposerExtra\Plugin
 * @see \Switon\Kernel\ServiceBootstrapper::bootstrap()
 * @see \Switon\ComposerExtra\ComposerExtra::all()
 * @see \Switon\ComposerExtra\ComposerExtra::collect()
 * @see \Switon\Command\CommandDiscovery::discover()
 */
class ComposerExtra implements ComposerExtraInterface
{
    /** @var array<string, array<string, mixed>>|null Cached package extra map. */
    protected ?array $cache = null;

    /** Absolute path to cache file. */
    protected string $cacheFile;

    /**
     * @param string|null $cacheFile Override cache file path; default is <code>{root}/vendor/switon/composer-extra.json</code>
     */
    public function __construct(?string $cacheFile = null)
    {
        if ($cacheFile !== null) {
            $this->cacheFile = $cacheFile;
        } else {
            $root = Runtime::getRoot();
            $this->cacheFile = "$root/vendor/switon/composer-extra.json";
        }
    }

    /** @return array<string, mixed> */
    public function get(string $packageName): array
    {
        $all = $this->all();
        return $all[$packageName] ?? [];
    }

    /**
     * Returns all package extra metadata.
     *
     * Loads and validates the cache file on first access, then reuses in-memory data.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = $this->loadCache();

        return $this->cache;
    }

    /** Check whether package has non-empty extra metadata. */
    public function has(string $packageName): bool
    {
        $all = $this->all();
        return !empty($all[$packageName]);
    }

    /**
     * Returns cache health status without throwing.
     *
     * @return array{
     *   ok: bool,
     *   reason: 'ok'|'cache_missing'|'cache_unreadable'|'invalid_json'|'invalid_shape'|'cache_empty',
     *   cacheFile: string,
     *   packageCount: int
     * }
     */
    public function health(): array
    {
        $status = [
            'ok' => false,
            'reason' => 'cache_missing',
            'cacheFile' => $this->cacheFile,
            'packageCount' => 0,
        ];

        if (!file_exists($this->cacheFile)) {
            return $status;
        }

        $content = @file_get_contents($this->cacheFile);
        if ($content === false) {
            $status['reason'] = 'cache_unreadable';
            return $status;
        }

        try {
            $data = Json::parse($content);
        } catch (Throwable) {
            $status['reason'] = 'invalid_json';
            return $status;
        }

        if (!is_array($data)) {
            $status['reason'] = 'invalid_shape';
            return $status;
        }

        if (empty($data)) {
            $status['reason'] = 'cache_empty';
            return $status;
        }

        $status['ok'] = true;
        $status['reason'] = 'ok';
        $status['packageCount'] = count($data);

        return $status;
    }

    /**
     * Collects string values from the leaf at a dot-separated path under each package root.
     *
     * The leaf must be either a non-empty string or an array whose values are non-empty strings
     * (indexed list or string-keyed map; keys are ignored). Non-string values (including nested arrays) are
     * skipped. Other leaf types are ignored.
     *
     * Path is relative to each value returned by {@see all()} (per-package merged extra). Empty segments from
     * consecutive dots are ignored. Packages are merged in iteration order and values are appended as-is.
     *
     * @param string $path Dot-separated keys, e.g. <code>switon.listeners</code>
     *
     * @return list<string>
     */
    public function collect(string $path): array
    {
        $segments = $this->dotPathSegments($path);
        if ($segments === []) {
            return [];
        }

        $out = [];

        foreach ($this->all() as $packageRoot) {
            if (!is_array($packageRoot)) {
                continue;
            }

            $leaf = $this->valueAtDotPath($packageRoot, $segments);

            if (is_string($leaf)) {
                if ($leaf !== '') {
                    $out[] = $leaf;
                }
                continue;
            }

            if (!is_array($leaf)) {
                continue;
            }

            foreach ($leaf as $value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $root
     * @param list<string> $segments
     */
    protected function valueAtDotPath(array $root, array $segments): mixed
    {
        $current = $root;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @return list<string>
     */
    protected function dotPathSegments(string $path): array
    {
        if ($path === '') {
            return [];
        }

        return array_values(array_filter(explode('.', $path), static fn (string $s): bool => $s !== ''));
    }

    /**
     * Load and validate cache data from disk.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws ComposerExtraNotFoundException
     * @throws \Switon\Core\Exception\JsonException
     * @throws RuntimeException
     */
    protected function loadCache(): array
    {
        if (!file_exists($this->cacheFile)) {
            ComposerExtraNotFoundException::raise(
                'Composer extra cache file not found: {cacheFile}. '
                . 'This file is generated by switon/composer-extra during composer install/update. '
                . 'Fix: install switon/composer-extra and run composer install or composer update.',
                ['cacheFile' => $this->cacheFile]
            );
        }

        // @: unreadable/missing file may emit E_WARNING; false is handled below
        $content = @file_get_contents($this->cacheFile);
        if ($content === false) {
            RuntimeException::raise('Cannot read cache file: {cacheFile}', ['cacheFile' => $this->cacheFile]);
        }

        $data = Json::parse($content);

        if (!is_array($data)) {
            RuntimeException::raise('Cache file invalid (not array): {cacheFile}', [
                'cacheFile' => $this->cacheFile,
            ]);
        }

        if (empty($data)) {
            RuntimeException::raise('Composer extra cache is empty: {cacheFile}', [
                'cacheFile' => $this->cacheFile,
            ]);
        }

        return $this->overlayPathPackageExtras($data);
    }

    /**
     * Overlay live extra metadata for path-installed packages during local development.
     *
     * Composer only refreshes <code>vendor/switon/composer-extra.json</code> on install/update.
     * For monorepo path packages, reading the current package <code>composer.json</code> keeps
     * newly added providers, commands, or listeners available without forcing a Composer rerun.
     *
     * @param array<string, array<string, mixed>> $data
     *
     * @return array<string, array<string, mixed>>
     */
    protected function overlayPathPackageExtras(array $data): array
    {
        $installedFile = $this->getInstalledPackagesFile();
        if (!file_exists($installedFile)) {
            return $data;
        }

        $content = @file_get_contents($installedFile);
        if ($content === false) {
            return $data;
        }

        try {
            $installed = Json::parse($content);
        } catch (Throwable) {
            return $data;
        }

        $packages = $installed['packages'] ?? $installed;
        if (!is_array($packages)) {
            return $data;
        }

        foreach ($packages as $package) {
            if (!is_array($package) || !$this->shouldOverlayPackage($package)) {
                continue;
            }

            $name = $package['name'] ?? null;
            $composerJson = $this->resolveInstalledComposerJson($installedFile, $package);
            if (!is_string($name) || $name === '' || $composerJson === null) {
                continue;
            }

            $extra = $this->readPackageExtra($composerJson);
            if ($extra === null) {
                continue;
            }

            if ($extra === []) {
                unset($data[$name]);
                continue;
            }

            $data[$name] = $extra;
        }

        return $data;
    }

    protected function getInstalledPackagesFile(): string
    {
        return dirname($this->cacheFile, 2) . '/composer/installed.json';
    }

    /**
     * @param array<string, mixed> $package
     */
    protected function shouldOverlayPackage(array $package): bool
    {
        $installPath = $this->resolveInstalledPackagePath($this->getInstalledPackagesFile(), $package);
        if ($installPath === null) {
            return false;
        }

        $dist = $package['dist'] ?? null;
        if (is_array($dist) && ($dist['type'] ?? null) === 'path') {
            return true;
        }

        $transport = $package['transport-options'] ?? null;
        if (is_array($transport) && !empty($transport['symlink'])) {
            return true;
        }

        return is_link($installPath);
    }

    /**
     * @param array<string, mixed> $package
     */
    protected function resolveInstalledComposerJson(string $installedFile, array $package): ?string
    {
        $installPath = $this->resolveInstalledPackagePath($installedFile, $package);
        if ($installPath === null) {
            return null;
        }

        $composerJson = $installPath . '/composer.json';
        return file_exists($composerJson) ? $composerJson : null;
    }

    /**
     * @param array<string, mixed> $package
     */
    protected function resolveInstalledPackagePath(string $installedFile, array $package): ?string
    {
        $installPath = $package['install-path'] ?? null;
        if (!is_string($installPath) || $installPath === '') {
            return null;
        }

        return dirname($installedFile) . '/' . $installPath;
    }

    /**
     * @return array<string, mixed>|null Empty array means "live package has no usable extra".
     */
    protected function readPackageExtra(string $composerJson): ?array
    {
        $content = @file_get_contents($composerJson);
        if ($content === false) {
            return null;
        }

        try {
            $package = Json::parse($content);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($package)) {
            return null;
        }

        $extra = $package['extra'] ?? [];
        if (!is_array($extra) || $this->isOnlyBranchAlias($extra)) {
            return [];
        }

        return $extra;
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function isOnlyBranchAlias(array $extra): bool
    {
        $keys = array_keys($extra);
        return count($keys) === 1 && isset($extra['branch-alias']);
    }
}
