<?php

declare(strict_types=1);

namespace Switon\ComposerExtra\Tests\Unit;

require_once dirname(__DIR__) . '/TestCase.php';

use Composer\Composer;
use Composer\Config;
use Composer\InstalledVersions;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use RuntimeException;
use Switon\ComposerExtra\ComposerExtraPluginInterface;
use Switon\ComposerExtra\Exception\CacheWriteException;
use Switon\ComposerExtra\Plugin;
use Switon\ComposerExtra\Tests\TestCase;

use function bin2hex;
use function chmod;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

class PluginTest extends TestCase
{
    public function testPluginImplementsComposerExtraPluginMarkerInterface(): void
    {
        $this->assertInstanceOf(ComposerExtraPluginInterface::class, new Plugin());
    }

    public function testActivateStoresComposerAndIoInstancesForLaterUse(): void
    {
        $plugin = new TestablePluginForHelpers();
        $composer = $this->makeComposer('/tmp/vendor');
        $io = new \Composer\IO\NullIO();

        $plugin->activate($composer, $io);

        $this->assertSame($composer, $plugin->getComposerForTest());
        $this->assertSame($io, $plugin->getIoForTest());
    }

    public function testDeactivateAndUninstallAreNoopLifecycleHooks(): void
    {
        $plugin = new Plugin();
        $composer = $this->makeComposer('/tmp/vendor');
        $io = new \Composer\IO\NullIO();

        $plugin->deactivate($composer, $io);
        $plugin->uninstall($composer, $io);

        $this->addToAssertionCount(1);
    }

    public function testGetSubscribedEventsMapsInstallAndUpdateHooks(): void
    {
        $this->assertSame(
            [
                ScriptEvents::POST_INSTALL_CMD => 'generateExtraCache',
                ScriptEvents::POST_UPDATE_CMD => 'generateExtraCache',
            ],
            Plugin::getSubscribedEvents()
        );
    }

    public function testGenerateExtraCacheWritesExtractedPayload(): void
    {
        $plugin = new TestablePluginForGenerate();
        $plugin->extracted = ['switon/core' => ['switon' => ['x' => 1]]];

        $composer = $this->makeComposer('/tmp/vendor');
        $io = new \Composer\IO\NullIO();
        $plugin->generateExtraCache($this->makeEvent($composer, $io));

        $this->assertSame($plugin->extracted, $plugin->written);
    }

    public function testGenerateExtraCacheSwallowsExtractFailures(): void
    {
        $plugin = new TestablePluginForGenerate();
        $plugin->throwOnExtract = true;

        $composer = $this->makeComposer('/tmp/vendor');
        $io = new \Composer\IO\NullIO();
        $plugin->generateExtraCache($this->makeEvent($composer, $io));

        $this->assertNull($plugin->written);
    }

    public function testGenerateExtraCacheSwallowsWriteFailures(): void
    {
        $plugin = new TestablePluginForGenerate();
        $plugin->extracted = ['switon/http' => ['switon' => ['y' => 2]]];
        $plugin->throwOnWrite = true;

        $composer = $this->makeComposer('/tmp/vendor');
        $io = new \Composer\IO\NullIO();
        $plugin->generateExtraCache($this->makeEvent($composer, $io));

        $this->assertSame($plugin->extracted, $plugin->written);
    }

    public function testIsOnlyBranchAliasRecognizesExactShape(): void
    {
        $plugin = new TestablePluginForHelpers();

        $this->assertTrue($plugin->isOnlyBranchAliasPublic(['branch-alias' => ['dev-main' => '1.x-dev']]));
        $this->assertFalse($plugin->isOnlyBranchAliasPublic([]));
        $this->assertFalse(
            $plugin->isOnlyBranchAliasPublic(['branch-alias' => ['dev-main' => '1.x-dev'], 'switon' => ['a' => 1]])
        );
    }

    public function testIsOnlyBranchAliasAcceptsBranchAliasKeyRegardlessOfInnerShape(): void
    {
        $plugin = new TestablePluginForHelpers();

        $this->assertTrue($plugin->isOnlyBranchAliasPublic(['branch-alias' => 'invalid-shape']));
    }

    public function testWriteCacheFileCreatesSwitonDirectoryAndPersistsJson(): void
    {
        $plugin = new TestablePluginForHelpers();
        $tmp = sys_get_temp_dir() . '/switon-plugin-' . bin2hex(random_bytes(4));
        $vendorDir = $tmp . '/vendor';
        $cacheFile = $vendorDir . '/switon/composer-extra.json';
        $composer = $this->makeComposer($vendorDir);

        $plugin->setComposerForTest($composer);

        try {
            $plugin->writeCacheFilePublic([
                'switon/http-cache' => ['switon' => ['listeners' => ['A', 'B']]],
            ]);

            $this->assertTrue(file_exists($cacheFile));
            $decoded = json_decode((string)file_get_contents($cacheFile), true);
            $this->assertSame(
                ['switon/http-cache' => ['switon' => ['listeners' => ['A', 'B']]]],
                $decoded
            );
        } finally {
            $this->removeDirectory($tmp);
        }
    }

    public function testWriteCacheFileThrowsWhenJsonEncodingFails(): void
    {
        $plugin = new TestablePluginForHelpers();
        $tmp = sys_get_temp_dir() . '/switon-plugin-' . bin2hex(random_bytes(4));
        $composer = $this->makeComposer($tmp . '/vendor');
        $plugin->setComposerForTest($composer);

        try {
            $this->expectException(CacheWriteException::class);
            $this->expectExceptionMessage('Failed to encode extra data to JSON');
            $plugin->writeCacheFilePublic(['switon/pkg' => ['nan' => NAN]]);
        } finally {
            $this->removeDirectory($tmp);
        }
    }

    public function testReportFailureWritesWarningToIoWhenGenerateExtraCacheFails(): void
    {
        $plugin = new TestablePluginThrowingExtract();
        $composer = $this->makeComposer('/tmp/vendor');
        $io = new class () extends \Composer\IO\NullIO {
            /** @var list<string> */
            public array $errors = [];

            public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL): void
            {
                $this->errors[] = (string)$messages;
            }
        };

        $plugin->activate($composer, $io);
        $plugin->generateExtraCache($this->makeEvent($composer, $io));

        $this->assertNotEmpty($io->errors);
        $this->assertStringContainsString('[switon/composer-extra]', $io->errors[0]);
        $this->assertStringContainsString('extract failed', $io->errors[0]);
    }

    public function testReportFailureSwallowsWhenIoWriteErrorThrows(): void
    {
        $plugin = new TestablePluginThrowingExtract();
        $composer = $this->makeComposer('/tmp/vendor');
        $io = new class () extends \Composer\IO\NullIO {
            public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL): void
            {
                throw new RuntimeException('io write broken');
            }
        };

        $plugin->activate($composer, $io);
        $plugin->generateExtraCache($this->makeEvent($composer, $io));

        $this->addToAssertionCount(1);
    }

    public function testWriteCacheFileThrowsWhenSwitonPathIsBlockedByFile(): void
    {
        $plugin = new TestablePluginForHelpers();
        $tmp = sys_get_temp_dir() . '/switon-plugin-' . bin2hex(random_bytes(4));
        $vendorDir = $tmp . '/vendor';
        mkdir($vendorDir, 0755, true);
        file_put_contents($vendorDir . '/switon', '');

        $composer = $this->makeComposer($vendorDir);
        $plugin->setComposerForTest($composer);

        try {
            $this->expectException(CacheWriteException::class);
            $this->expectExceptionMessage('Failed to create cache directory:');
            $plugin->writeCacheFilePublic(['switon/pkg' => ['switon' => ['a' => 1]]]);
        } finally {
            $this->removeDirectory($tmp);
        }
    }

    public function testWriteCacheFileThrowsWhenFilePutContentsFails(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Requires Unix chmod to make cache directory non-writable');
        }

        $plugin = new TestablePluginForHelpers();
        $tmp = sys_get_temp_dir() . '/switon-plugin-' . bin2hex(random_bytes(4));
        $vendorDir = $tmp . '/vendor';
        $switonDir = $vendorDir . '/switon';
        mkdir($switonDir, 0755, true);

        $composer = $this->makeComposer($vendorDir);
        $plugin->setComposerForTest($composer);

        try {
            $this->assertTrue(chmod($switonDir, 0555));
            clearstatcache(true, $switonDir);

            $this->expectException(CacheWriteException::class);
            $this->expectExceptionMessage('Failed to write extra cache file:');
            $plugin->writeCacheFilePublic(['switon/pkg' => ['switon' => ['a' => 1]]]);
        } finally {
            chmod($switonDir, 0755);
            $this->removeDirectory($tmp);
        }
    }

    public function testExtractExtraDataIncludesCurrentPackageExtra(): void
    {
        $backup = InstalledVersions::getAllRawData();
        $tainted = $backup;
        $tainted['versions']['switon/composer-extra'] = [
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'test-ref',
            'type' => 'composer-plugin',
            'install_path' => dirname(__DIR__, 2),
            'extra' => [
                'class' => Plugin::class,
            ],
        ];

        try {
            InstalledVersions::reload($tainted);
            $plugin = new TestablePluginForExtract();
            $extra = $plugin->extractExtraDataPublic();

            $this->assertIsArray($extra);
            $this->assertArrayHasKey('switon/composer-extra', $extra);
            $this->assertSame(Plugin::class, $extra['switon/composer-extra']['class'] ?? null);
        } finally {
            InstalledVersions::reload($backup);
        }
    }

    public function testGenerateExtraCacheIntegrationWritesComposerExtraJson(): void
    {
        $backup = InstalledVersions::getAllRawData();
        $tainted = $backup;
        $tainted['versions']['switon/composer-extra'] = [
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'test-ref',
            'type' => 'composer-plugin',
            'install_path' => dirname(__DIR__, 2),
            'extra' => [
                'class' => Plugin::class,
            ],
        ];

        $tmp = sys_get_temp_dir() . '/switon-plugin-' . bin2hex(random_bytes(4));
        $vendorDir = $tmp . '/vendor';

        $plugin = new Plugin();
        $composer = $this->makeComposer($vendorDir);
        $io = new \Composer\IO\NullIO();

        try {
            InstalledVersions::reload($tainted);
            $plugin->activate($composer, $io);
            $plugin->generateExtraCache($this->makeEvent($composer, $io));

            $cacheFile = $vendorDir . '/switon/composer-extra.json';
            $this->assertFileExists($cacheFile);
            $decoded = json_decode((string)file_get_contents($cacheFile), true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('switon/composer-extra', $decoded);
            $this->assertSame(Plugin::class, $decoded['switon/composer-extra']['class'] ?? null);
        } finally {
            InstalledVersions::reload($backup);
            $this->removeDirectory($tmp);
        }
    }

    public function testReportFailureReturnsEarlyWhenIoNotActivated(): void
    {
        $plugin = new TestablePluginThrowingWrite();
        $composer = $this->makeComposer('/tmp/vendor');
        $plugin->setComposerForTest($composer);

        $plugin->generateExtraCache($this->makeEvent($composer, new \Composer\IO\NullIO()));

        $this->addToAssertionCount(1);
    }

    public function testExtractExtraDataSkipsNonArrayPackageInfo(): void
    {
        $backup = InstalledVersions::getAllRawData();
        $tainted = $backup;
        $tainted['versions']['__switon/edge-non-array'] = 'not-array';

        try {
            InstalledVersions::reload($tainted);
            $plugin = new TestablePluginForExtract();
            $extra = $plugin->extractExtraDataPublic();
            $this->assertArrayNotHasKey('__switon/edge-non-array', $extra);
        } finally {
            InstalledVersions::reload($backup);
        }
    }

    public function testExtractExtraDataFallsBackToComposerJsonWhenExtraIsMissing(): void
    {
        $backup = InstalledVersions::getAllRawData();
        $tainted = $backup;

        $tmp = sys_get_temp_dir() . '/switon-plugin-pkg-' . bin2hex(random_bytes(4));
        $installPath = $tmp . '/pkg';
        mkdir($installPath, 0755, true);
        file_put_contents($installPath . '/composer.json', json_encode([
            'name' => 'switon/fallback-pkg',
            'extra' => [
                'switon' => [
                    'listeners' => ['A'],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $tainted['versions']['switon/fallback-pkg'] = [
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'install_path' => $installPath,
            // extra intentionally missing to force composer.json fallback
        ];

        try {
            InstalledVersions::reload($tainted);
            $plugin = new TestablePluginForExtract();
            $extra = $plugin->extractExtraDataPublic();

            $this->assertArrayHasKey('switon/fallback-pkg', $extra);
            $this->assertSame(['listeners' => ['A']], $extra['switon/fallback-pkg']['switon'] ?? null);
        } finally {
            InstalledVersions::reload($backup);
            $this->removeDirectory($tmp);
        }
    }

    public function testExtractExtraDataSkipsEmptyExtraAndBranchAliasOnlyFromComposerJsonFallback(): void
    {
        $backup = InstalledVersions::getAllRawData();
        $tainted = $backup;

        $tmp = sys_get_temp_dir() . '/switon-plugin-pkg-' . bin2hex(random_bytes(4));
        $pkgEmpty = $tmp . '/empty';
        $pkgBranch = $tmp . '/branch-only';
        mkdir($pkgEmpty, 0755, true);
        mkdir($pkgBranch, 0755, true);

        file_put_contents($pkgEmpty . '/composer.json', json_encode([
            'name' => 'switon/empty-extra',
            'extra' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        file_put_contents($pkgBranch . '/composer.json', json_encode([
            'name' => 'switon/branch-only',
            'extra' => [
                'branch-alias' => [
                    'dev-main' => '1.x-dev',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $tainted['versions']['switon/empty-extra'] = [
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'install_path' => $pkgEmpty,
        ];

        $tainted['versions']['switon/branch-only'] = [
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'install_path' => $pkgBranch,
        ];

        try {
            InstalledVersions::reload($tainted);
            $plugin = new TestablePluginForExtract();
            $extra = $plugin->extractExtraDataPublic();

            $this->assertArrayNotHasKey('switon/empty-extra', $extra);
            $this->assertArrayNotHasKey('switon/branch-only', $extra);
        } finally {
            InstalledVersions::reload($backup);
            $this->removeDirectory($tmp);
        }
    }

    public function testExtractExtraDataSkipsPackageWhenComposerJsonIsInvalid(): void
    {
        $backup = InstalledVersions::getAllRawData();
        $tainted = $backup;

        $tmp = sys_get_temp_dir() . '/switon-plugin-pkg-' . bin2hex(random_bytes(4));
        $pkgInvalid = $tmp . '/invalid-json';
        mkdir($pkgInvalid, 0755, true);
        file_put_contents($pkgInvalid . '/composer.json', '{invalid-json');

        $tainted['versions']['switon/invalid-json'] = [
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'install_path' => $pkgInvalid,
        ];

        try {
            InstalledVersions::reload($tainted);
            $plugin = new TestablePluginForExtract();
            $extra = $plugin->extractExtraDataPublic();

            $this->assertArrayNotHasKey('switon/invalid-json', $extra);
        } finally {
            InstalledVersions::reload($backup);
            $this->removeDirectory($tmp);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    private function makeComposer(string $vendorDir): Composer
    {
        $baseDir = dirname($vendorDir);
        $config = new Config(false, $baseDir);
        $config->merge(['config' => ['vendor-dir' => basename($vendorDir)]]);
        $composer = new Composer();
        $composer->setConfig($config);

        return $composer;
    }

    private function makeEvent(Composer $composer, IOInterface $io): Event
    {
        return new Event('post-install-cmd', $composer, $io);
    }

}

class TestablePluginForGenerate extends Plugin
{
    /** @var array<string, array<string, mixed>> */
    public array $extracted = [];
    /** @var array<string, array<string, mixed>>|null */
    public ?array $written = null;
    public bool $throwOnExtract = false;
    public bool $throwOnWrite = false;

    protected function extractExtraData(): array
    {
        if ($this->throwOnExtract) {
            throw new RuntimeException('extract failed');
        }

        return $this->extracted;
    }

    protected function writeCacheFile(array $extraData): void
    {
        $this->written = $extraData;
        if ($this->throwOnWrite) {
            throw new RuntimeException('write failed');
        }
    }
}

class TestablePluginForHelpers extends Plugin
{
    public function isOnlyBranchAliasPublic(array $extra): bool
    {
        return $this->isOnlyBranchAlias($extra);
    }

    /**
     * @param array<string, array<string, mixed>> $extraData
     */
    public function writeCacheFilePublic(array $extraData): void
    {
        $this->writeCacheFile($extraData);
    }

    public function getComposerForTest(): Composer
    {
        return $this->composer;
    }

    public function getIoForTest(): IOInterface
    {
        return $this->io;
    }

    public function setComposerForTest(Composer $composer): void
    {
        $this->composer = $composer;
    }
}

class TestablePluginThrowingExtract extends Plugin
{
    protected function extractExtraData(): array
    {
        throw new RuntimeException('extract failed');
    }
}

class TestablePluginForExtract extends Plugin
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function extractExtraDataPublic(): array
    {
        return parent::extractExtraData();
    }
}

class TestablePluginThrowingWrite extends Plugin
{
    public function setComposerForTest(Composer $composer): void
    {
        $this->composer = $composer;
    }

    protected function extractExtraData(): array
    {
        return ['switon/test' => ['switon' => ['a' => 1]]];
    }

    protected function writeCacheFile(array $extraData): void
    {
        throw new RuntimeException('write boom');
    }
}
