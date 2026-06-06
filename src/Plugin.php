<?php

declare(strict_types=1);

namespace Switon\ComposerExtra;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\InstalledVersions;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Switon\ComposerExtra\Exception\CacheWriteException;
use Throwable;

/**
 * Generates and persists a cache of installed package <code>extra</code> metadata.
 *
 * Guidance: This package generates Composer metadata cache only; Switon runtime code should read the generated file via <code>\Switon\ComposerExtra\ComposerExtra</code>. Direct file I/O is intentional here because Composer plugins run before framework runtime services exist.
 *
 * Road-signs:
 * - activation comes from package <code>composer.json</code> plugin metadata
 * - <code>post-install-cmd</code> and <code>post-update-cmd</code> rebuild the cache
 * - cache file is <code>vendor/switon/composer-extra.json</code>
 * - runtime cache consumption lives in <code>\Switon\ComposerExtra\ComposerExtra</code>
 * - failures are best-effort and do not break Composer flow
 *
 * @see \Switon\ComposerExtra\ComposerExtraPluginInterface
 * @see \Switon\ComposerExtra\ComposerExtra
 */
class Plugin implements PluginInterface, EventSubscriberInterface, ComposerExtraPluginInterface
{
    protected Composer $composer;
    protected IOInterface $io;

    /**
     * Composer lifecycle hook: stores Composer and IO instances for later use.
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Composer lifecycle hook for plugin deactivation.
     */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // No cleanup needed
    }

    /**
     * Composer lifecycle hook for plugin uninstall.
     */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // No cleanup needed
    }

    /**
     * Subscribes to command-level events that run once per install/update command.
     *
     * @return array<string, string> Composer event map (<code>event => method</code>)
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'generateExtraCache',
            ScriptEvents::POST_UPDATE_CMD => 'generateExtraCache',
        ];
    }

    /**
     * Rebuilds the extra cache after install/update completes.
     *
     * Best-effort behavior: failures are swallowed so Composer operations do not fail.
     */
    public function generateExtraCache(Event $event): void
    {
        try {
            $extraData = $this->extractExtraData();
            $this->writeCacheFile($extraData);
        } catch (Throwable $e) {
            $this->reportFailure($e);
        }
    }

    /**
     * Collects non-empty package <code>extra</code> data from installed packages.
     *
     * Resolution order:
     * 1. <code>InstalledVersions::getAllRawData()</code> package info <code>extra</code>
     * 2. Installed package <code>composer.json</code> fallback
     *
     * Filters out empty extra data and <code>branch-alias</code>-only entries.
     *
     * @return array<string, array<string, mixed>> Package name => extra mapping
     */
    protected function extractExtraData(): array
    {
        $extraData = [];

        if (!class_exists(InstalledVersions::class)) {
            return $extraData;
        }

        $allRawData = InstalledVersions::getAllRawData();

        // getAllRawData() returns an array of data sources, each containing versions
        foreach ($allRawData as $sourceData) {
            if (!isset($sourceData['versions']) || !is_array($sourceData['versions'])) {
                continue;
            }

            foreach ($sourceData['versions'] as $packageName => $packageInfo) {
                if (!is_array($packageInfo)) {
                    continue;
                }

                // Try to get extra from package info first
                $extra = $packageInfo['extra'] ?? null;

                // If not in package info, try reading from composer.json
                if ($extra === null && isset($packageInfo['install_path'])) {
                    $composerJsonPath = $packageInfo['install_path'] . '/composer.json';
                    if (file_exists($composerJsonPath)) {
                        $content = file_get_contents($composerJsonPath);
                        if ($content !== false) {
                            $composerJson = json_decode($content, true);
                            $extra = $composerJson['extra'] ?? null;
                        }
                    }
                }

                // Only include packages with non-empty extra data
                // Exclude packages that only have branch-alias
                if (is_array($extra) && !empty($extra) && !$this->isOnlyBranchAlias($extra)) {
                    $extraData[$packageName] = $extra;
                }
            }
        }

        return $extraData;
    }

    /**
     * Checks whether an extra payload only contains <code>branch-alias</code>.
     *
     * @param array<string, mixed> $extra
     */
    protected function isOnlyBranchAlias(array $extra): bool
    {
        $keys = array_keys($extra);
        return count($keys) === 1 && isset($extra['branch-alias']);
    }

    /**
     * Writes extracted data to <code>vendor/switon/composer-extra.json</code>.
     *
     * @param array<string, array<string, mixed>> $extraData
     */
    protected function writeCacheFile(array $extraData): void
    {
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $switonDir = $vendorDir . '/switon';

        // Ensure switon directory exists (@mkdir: avoid E_WARNING when path exists as file or races)
        if (!is_dir($switonDir) && !@mkdir($switonDir, 0755, true) && !is_dir($switonDir)) {
            CacheWriteException::raise('Failed to create cache directory: {directory}', ['directory' => $switonDir]);
        }

        $cacheFile = $switonDir . '/composer-extra.json';

        $json = json_encode($extraData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            CacheWriteException::raise('Failed to encode extra data to JSON: {error}', ['error' => json_last_error_msg()]);
        }

        $result = @file_put_contents($cacheFile, $json);

        if ($result === false) {
            CacheWriteException::raise('Failed to write extra cache file: {cacheFile}', ['cacheFile' => $cacheFile]);
        }
    }

    /**
     * Report cache refresh failure without interrupting Composer workflow.
     */
    protected function reportFailure(Throwable $e): void
    {
        if (!isset($this->io)) {
            return;
        }

        try {
            $this->io->writeError('<warning>[switon/composer-extra] Failed to refresh composer-extra cache: '
                . $e->getMessage() . '</warning>');
        } catch (Throwable) {
            // Keep best-effort semantics: cache refresh failures must not break Composer flow.
        }
    }
}
