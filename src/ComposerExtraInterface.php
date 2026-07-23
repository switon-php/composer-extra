<?php

declare(strict_types=1);

namespace Switon\ComposerExtra;

/**
 * Cached Composer extra metadata reader.
 *
 * Use when framework packages need package-published discovery metadata at runtime.
 *
 * Road-signs:
 * - <code>collect()</code> merges discovery leaves under <code>extra.switon.*</code>
 *   (int keys append; string keys map; later packages win on string keys)
 * - <code>all()</code> returns the validated per-package extra map
 * - <code>health()</code> reports cache status without throwing
 * - runtime reads only; cache generation lives in the Composer plugin
 *
 * @see \Switon\ComposerExtra\ComposerExtra
 * @see \Switon\Kernel\ServiceBootstrapper
 * @see \Switon\ComposerExtra\Plugin
 */
interface ComposerExtraInterface
{
    /** @return array<string, mixed> Per-package merged extra metadata or empty array */
    public function get(string $packageName): array;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array;

    /** Check whether a package has non-empty extra metadata. */
    public function has(string $packageName): bool;

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
    public function health(): array;

    /**
     * Merge string leaves at a dot path under each package root.
     *
     * For each array leaf entry: int key → append value; non-empty string key →
     * <code>$out[$key] = $value</code> (later packages overwrite). A bare non-empty
     * string leaf appends like a list item.
     *
     * @param string $path Dot-separated keys, e.g. <code>switon.listeners</code> / <code>switon.tools</code>
     *
     * @return array<int|string, string>
     */
    public function collect(string $path): array;
}
