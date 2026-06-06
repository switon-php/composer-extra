<?php

declare(strict_types=1);

namespace Switon\ComposerExtra\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\ComposerExtra\ComposerExtra;
use Switon\ComposerExtra\Exception\ComposerExtraNotFoundException;
use Switon\ComposerExtra\Tests\TestCase;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\Json;

/**
 * Test cases for ComposerExtra class.
 *
 * Tests Composer package extra data cache reader functionality including
 * file reading, caching, error handling, and data access methods.
 */
#[AllowMockObjectsWithoutExpectations]
class ComposerExtraTest extends TestCase
{
    protected string $tempDir;
    protected string $cacheFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary directory for test files
        $this->tempDir = sys_get_temp_dir() . '/composer-extra-test-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        $this->cacheFile = $this->tempDir . '/composer-extra.json';
    }

    protected function tearDown(): void
    {
        // Clean up temporary files and directories more safely
        $this->cleanupTempFiles();

        parent::tearDown();
    }

    /**
     * Safely clean up temporary files and directories.
     */
    protected function cleanupTempFiles(): void
    {
        $this->deletePath($this->tempDir);
    }

    protected function deletePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = glob($path . '/*') ?: [];
        foreach ($items as $item) {
            $this->deletePath($item);
        }

        @rmdir($path);
    }

    /**
     * Test that ComposerExtra uses custom cache file path correctly.
     *
     * Verifies by writing data to custom path and reading it back.
     */
    public function testConstructorUsesCustomCacheFile(): void
    {
        // Arrange
        $cacheData = ['test/package' => ['version' => '1.0.0']];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        // Act
        $composerExtra = new ComposerExtra($this->cacheFile);

        // Assert - behavior verification through public API
        $this->assertTrue($composerExtra->has('test/package'));
        $this->assertEquals($cacheData, $composerExtra->all());
    }

    /**
     * Test that get() returns package extra data.
     */
    public function testGetReturnsPackageExtraData(): void
    {
        $cacheData = [
            'switon/core' => ['providers' => ['Switon\Di\ServiceProvider'], 'version' => '1.0.0'],
            'switon/http' => ['providers' => ['Switon\Http\ServiceProvider']],
        ];

        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertEquals(
            ['providers' => ['Switon\Di\ServiceProvider'], 'version' => '1.0.0'],
            $composerExtra->get('switon/core')
        );
    }

    /**
     * Test that get() returns empty array for non-existent package.
     */
    public function testGetReturnsEmptyArrayForNonExistentPackage(): void
    {
        $cacheData = ['switon/core' => ['version' => '1.0.0']];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertEquals([], $composerExtra->get('non-existent/package'));
    }

    /**
     * Test that has() returns true for existing package with data.
     */
    public function testHasReturnsTrueForExistingPackage(): void
    {
        $cacheData = ['switon/core' => ['version' => '1.0.0']];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertTrue($composerExtra->has('switon/core'));
    }

    /**
     * Test that has() returns false for non-existent package.
     */
    public function testHasReturnsFalseForNonExistentPackage(): void
    {
        $cacheData = ['switon/core' => ['version' => '1.0.0']];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertFalse($composerExtra->has('non-existent/package'));
    }

    /**
     * Test that has() returns false for package with empty data.
     */
    public function testHasReturnsFalseForPackageWithEmptyData(): void
    {
        $cacheData = ['switon/core' => []];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertFalse($composerExtra->has('switon/core'));
    }

    /**
     * Test that all() returns all package data.
     */
    public function testAllReturnsAllPackageData(): void
    {
        $cacheData = [
            'switon/core' => ['version' => '1.0.0'],
            'switon/http' => ['version' => '1.1.0'],
        ];

        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertEquals($cacheData, $composerExtra->all());
    }

    /**
     * Test that caching works - file is read only once.
     */
    public function testCachingWorks(): void
    {
        $cacheData = ['switon/core' => ['version' => '1.0.0']];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        // First call loads from file
        $result1 = $composerExtra->all();

        // Modify file content
        $modifiedData = ['switon/core' => ['version' => '2.0.0']];
        file_put_contents($this->cacheFile, Json::stringify($modifiedData));

        // Second call should return cached data, not modified file content
        $result2 = $composerExtra->all();

        $this->assertEquals($result1, $result2);
        $this->assertEquals($cacheData, $result2);
    }

    /**
     * Test that exception is thrown when cache file doesn't exist.
     */
    public function testExceptionThrownWhenCacheFileNotFound(): void
    {
        $nonExistentFile = $this->tempDir . '/non-existent.json';

        $this->expectException(ComposerExtraNotFoundException::class);
        $this->expectExceptionMessage('Composer extra cache file not found');

        $composerExtra = new ComposerExtra($nonExistentFile);
        $composerExtra->all();
    }

    /**
     * Test that exception is thrown when cache file contains invalid JSON.
     */
    public function testExceptionThrownWhenFileContainsInvalidJson(): void
    {
        // Create file with invalid JSON
        file_put_contents($this->cacheFile, 'invalid json content');

        $this->expectException(\Switon\Core\Exception\JsonException::class);
        $this->expectExceptionMessage('JSON parse failed');

        $composerExtra = new ComposerExtra($this->cacheFile);
        $composerExtra->all();
    }

    /**
     * Test that exception is thrown when cache file contains non-array data.
     */
    public function testExceptionThrownWhenFileContainsNonArrayData(): void
    {
        // Create file with valid JSON but not an array
        file_put_contents($this->cacheFile, Json::stringify('not an array'));

        $this->expectException(\Switon\Core\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Cache file invalid (not array)');

        $composerExtra = new ComposerExtra($this->cacheFile);
        $composerExtra->all();
    }

    /**
     * Test that exception is thrown when cache file is empty.
     */
    public function testExceptionThrownWhenCacheFileIsEmpty(): void
    {
        // Create file with empty array
        file_put_contents($this->cacheFile, Json::stringify([]));

        $this->expectException(\Switon\Core\Exception\RuntimeException::class);
        $this->expectExceptionMessage('Composer extra cache is empty');

        $composerExtra = new ComposerExtra($this->cacheFile);
        $composerExtra->all();
    }

    public function testGetClassesMergesAcrossPackagesWithoutDeduping(): void
    {
        $cacheData = [
            'pkg/a' => ['switon' => ['listeners' => ['App\\A', 'App\\B']]],
            'pkg/b' => ['switon' => ['listeners' => ['App\\B', 'App\\C']]],
        ];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertSame(
            ['App\\A', 'App\\B', 'App\\B', 'App\\C'],
            $composerExtra->collect('switon.listeners')
        );
    }

    public function testGetClassesEmptyPathReturnsEmptyList(): void
    {
        $cacheData = ['pkg' => ['switon' => ['listeners' => ['App\\X']]]];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertSame([], $composerExtra->collect(''));
    }

    public function testGetClassesSkipsMissingOrInvalidLeaf(): void
    {
        $cacheData = [
            'pkg/a' => ['switon' => []],
            'pkg/b' => ['switon' => ['listeners' => null]],
            'pkg/c' => ['switon' => ['listeners' => true]],
        ];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertSame([], $composerExtra->collect('switon.listeners'));
    }

    public function testGetClassesIgnoresEmptyStringSegmentsInPath(): void
    {
        $cacheData = ['pkg' => ['switon' => ['listeners' => ['App\\One']]]];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertSame(['App\\One'], $composerExtra->collect('switon..listeners'));
    }

    public function testGetClassesCollectsStringValuesFromAssociativeLeaf(): void
    {
        $cacheData = [
            'pkg' => [
                'switon' => [
                    'listeners' => [
                        'alias-a' => 'App\\A',
                        'alias-b' => 'App\\B',
                    ],
                ],
            ],
        ];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertSame(['App\\A', 'App\\B'], $composerExtra->collect('switon.listeners'));
    }

    public function testGetClassesSkipsNestedArrayValues(): void
    {
        $cacheData = [
            'pkg' => [
                'switon' => [
                    'listeners' => [
                        'group' => ['App\\A', 'App\\B'],
                    ],
                ],
            ],
        ];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertSame([], $composerExtra->collect('switon.listeners'));
    }

    public function testGetClassesAcceptsSingleStringLeaf(): void
    {
        $cacheData = [
            'pkg' => ['switon' => ['listeners' => 'App\\Only']],
        ];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertSame(['App\\Only'], $composerExtra->collect('switon.listeners'));
    }

    public function testGetClassesSkipsNonArrayPackageEntry(): void
    {
        $cacheData = [
            'bad' => 'not-an-array',
            'good' => ['switon' => ['listeners' => ['App\\Kept']]],
        ];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertSame(['App\\Kept'], $composerExtra->collect('switon.listeners'));
    }

    public function testGetClassesSkipsEmptyStringLeafAndEmptyStringItems(): void
    {
        $cacheData = [
            'pkg/a' => ['switon' => ['listeners' => '']],
            'pkg/b' => ['switon' => ['listeners' => ['App\\A', '']]],
        ];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertSame(['App\\A'], $composerExtra->collect('switon.listeners'));
    }

    public function testGetClassesSkipsNonStringValuesInsideLeafArray(): void
    {
        $cacheData = [
            'pkg' => [
                'switon' => [
                    'listeners' => [
                        'App\\A',
                        123,
                        true,
                        null,
                        ['nested'],
                        'App\\B',
                    ],
                ],
            ],
        ];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $composerExtra = new ComposerExtra($this->cacheFile);

        $this->assertSame(['App\\A', 'App\\B'], $composerExtra->collect('switon.listeners'));
    }

    public function testHealthReturnsOkForValidNonEmptyCache(): void
    {
        $cacheData = [
            'switon/core' => ['switon' => ['providers' => ['Switon\\Core\\ServiceProvider']]],
            'switon/http' => ['switon' => ['providers' => ['Switon\\Http\\ServiceProvider']]],
        ];
        file_put_contents($this->cacheFile, Json::stringify($cacheData));

        $health = (new ComposerExtra($this->cacheFile))->health();

        $this->assertSame(true, $health['ok']);
        $this->assertSame('ok', $health['reason']);
        $this->assertSame($this->cacheFile, $health['cacheFile']);
        $this->assertSame(2, $health['packageCount']);
    }

    public function testHealthReturnsCacheMissingWhenFileDoesNotExist(): void
    {
        $health = (new ComposerExtra($this->tempDir . '/missing.json'))->health();

        $this->assertSame(false, $health['ok']);
        $this->assertSame('cache_missing', $health['reason']);
        $this->assertSame(0, $health['packageCount']);
    }

    public function testHealthReturnsInvalidJsonForMalformedPayload(): void
    {
        file_put_contents($this->cacheFile, 'invalid-json');

        $health = (new ComposerExtra($this->cacheFile))->health();

        $this->assertSame(false, $health['ok']);
        $this->assertSame('invalid_json', $health['reason']);
        $this->assertSame(0, $health['packageCount']);
    }

    public function testHealthReturnsInvalidShapeWhenDecodedJsonIsNotArray(): void
    {
        file_put_contents($this->cacheFile, Json::stringify('not-an-array'));

        $health = (new ComposerExtra($this->cacheFile))->health();

        $this->assertSame(false, $health['ok']);
        $this->assertSame('invalid_shape', $health['reason']);
        $this->assertSame(0, $health['packageCount']);
    }

    public function testHealthReturnsCacheEmptyWhenDecodedArrayIsEmpty(): void
    {
        file_put_contents($this->cacheFile, Json::stringify([]));

        $health = (new ComposerExtra($this->cacheFile))->health();

        $this->assertSame(false, $health['ok']);
        $this->assertSame('cache_empty', $health['reason']);
        $this->assertSame(0, $health['packageCount']);
    }

    /**
     * @requires OSFAMILY Linux Darwin
     */
    public function testHealthReturnsCacheUnreadableWhenFileCannotBeRead(): void
    {
        $path = $this->tempDir . '/health-unreadable.json';
        file_put_contents($path, Json::stringify(['pkg' => ['version' => '1']]));
        $this->assertTrue(@chmod($path, 0000));

        try {
            $health = (new ComposerExtra($path))->health();
            $this->assertSame(false, $health['ok']);
            $this->assertSame('cache_unreadable', $health['reason']);
            $this->assertSame(0, $health['packageCount']);
        } finally {
            @chmod($path, 0644);
        }
    }

    /**
     * When the cache file exists but is not readable, loading must fail with a clear runtime error (not a JSON parse error).
     *
     * @requires OSFAMILY Linux Darwin
     */
    public function testAllRaisesWhenCacheFileIsUnreadable(): void
    {
        $path = $this->tempDir . '/unreadable.json';
        file_put_contents($path, Json::stringify(['pkg' => ['version' => '1']]));
        $this->assertTrue(@chmod($path, 0000));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot read cache file');

            (new ComposerExtra($path))->all();
        } finally {
            @chmod($path, 0644);
        }
    }

    public function testAllOverlaysMissingPathPackageExtraFromLiveComposerJson(): void
    {
        $root = $this->tempDir . '/overlay-missing';
        $cacheFile = $this->prepareComposerExtraLayout($root, [
            'switon/http' => ['switon' => ['providers' => ['Switon\\Http\\ServiceProvider']]],
        ], [
            [
                'name' => 'switon/validation',
                'install-path' => '../switon/validation',
                'dist' => ['type' => 'path'],
                'extra' => ['switon' => ['providers' => ['Switon\\Validating\\ServiceProvider']]],
            ],
        ]);

        $composerExtra = new ComposerExtra($cacheFile);

        $this->assertSame(
            ['Switon\\Http\\ServiceProvider', 'Switon\\Validating\\ServiceProvider'],
            $composerExtra->collect('switon.providers')
        );
    }

    public function testAllOverlaysStalePathPackageExtraFromLiveComposerJson(): void
    {
        $root = $this->tempDir . '/overlay-stale';
        $cacheFile = $this->prepareComposerExtraLayout($root, [
            'switon/validation' => ['switon' => ['providers' => ['Old\\ValidationProvider']]],
        ], [
            [
                'name' => 'switon/validation',
                'install-path' => '../switon/validation',
                'dist' => ['type' => 'path'],
                'extra' => ['switon' => ['providers' => ['Switon\\Validating\\ServiceProvider']]],
            ],
        ]);

        $composerExtra = new ComposerExtra($cacheFile);

        $this->assertSame(
            ['Switon\\Validating\\ServiceProvider'],
            $composerExtra->collect('switon.providers')
        );
    }

    /**
     * @param array<string, array<string, mixed>> $cacheData
     * @param list<array{name: string, install-path: string, dist?: array<string, mixed>, transport-options?: array<string, mixed>, extra?: array<string, mixed>}> $packages
     */
    protected function prepareComposerExtraLayout(string $root, array $cacheData, array $packages): string
    {
        $cacheFile = $root . '/vendor/switon/composer-extra.json';
        $installedFile = $root . '/vendor/composer/installed.json';

        mkdir(dirname($cacheFile), 0755, true);
        mkdir(dirname($installedFile), 0755, true);

        file_put_contents($cacheFile, Json::stringify($cacheData));

        $installedPackages = [];
        foreach ($packages as $package) {
            $installPath = dirname($installedFile) . '/' . $package['install-path'];
            mkdir($installPath, 0755, true);
            file_put_contents($installPath . '/composer.json', Json::stringify([
                'name' => $package['name'],
                'extra' => $package['extra'] ?? [],
            ]));

            $installedPackage = [
                'name' => $package['name'],
                'install-path' => $package['install-path'],
            ];

            if (isset($package['dist'])) {
                $installedPackage['dist'] = $package['dist'];
            }

            if (isset($package['transport-options'])) {
                $installedPackage['transport-options'] = $package['transport-options'];
            }

            $installedPackages[] = $installedPackage;
        }

        file_put_contents($installedFile, Json::stringify(['packages' => $installedPackages]));

        return $cacheFile;
    }

    /**
     * @requires OSFAMILY Linux Darwin
     */
    public function testAllIgnoresOverlayWhenComposerInstalledFileIsUnreadable(): void
    {
        $root = $this->tempDir . '/ce-overlay-installed-unreadable';
        $payload = ['switon/warm' => ['switon' => ['listeners' => ['App\\Cached']]]];
        $cacheFile = $this->prepareComposerExtraLayout($root, $payload, []);

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        $this->assertFileExists($installedFile);
        $this->assertTrue(@chmod($installedFile, 0000));

        try {
            $ce = new ComposerExtra($cacheFile);
            $this->assertSame(['App\\Cached'], $ce->collect('switon.listeners'));
        } finally {
            @chmod($installedFile, 0644);
        }
    }

    public function testAllIgnoresMalformedComposerInstalledOverlayPayload(): void
    {
        $root = $this->tempDir . '/ce-installed-bad-json';
        $payload = ['acme/fixture' => ['switon' => ['listeners' => ['App\\Stale']]]];
        $cacheFile = $this->prepareComposerExtraLayout($root, $payload, []);

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        file_put_contents($installedFile, '<<<not-json>>>');

        $ce = new ComposerExtra($cacheFile);
        $this->assertSame(['App\\Stale'], $ce->collect('switon.listeners'));
    }

    public function testAllIgnoresComposerInstalledPackagesWhenPackagesKeyIsWrongType(): void
    {
        $root = $this->tempDir . '/ce-installed-bad-shape';
        $payload = ['acme/fixture' => ['switon' => ['tasks' => ['App\\Task']]]];
        $cacheFile = $this->prepareComposerExtraLayout($root, $payload, []);

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        file_put_contents($installedFile, Json::stringify(['packages' => 'invalid']));

        $ce = new ComposerExtra($cacheFile);
        $this->assertSame(['App\\Task'], $ce->collect('switon.tasks'));
    }

    public function testAllDoesNotOverlayPlainComposerArtifactsWithoutSourceRepositoryHints(): void
    {
        $root = $this->tempDir . '/ce-dist-only';
        $payload = ['symfony/console' => ['switon' => ['providers' => ['Legacy\\ConsoleServiceProvider']]]];

        $cacheFile = $root . '/vendor/switon/composer-extra.json';
        $installedFile = $root . '/vendor/composer/installed.json';
        mkdir(dirname($cacheFile), 0755, true);
        mkdir(dirname($installedFile), 0755, true);
        file_put_contents($cacheFile, Json::stringify($payload));

        $installPath = dirname($installedFile) . '/symfony/console';
        mkdir($installPath, 0755, true);
        file_put_contents($installPath . '/composer.json', Json::stringify([
            'name' => 'symfony/console',
            'extra' => ['switon' => ['providers' => ['Remote\\ComposerOnlyProvider']]],
        ]));

        file_put_contents($installedFile, Json::stringify([
            'packages' => [[
                'name' => 'symfony/console',
                'install-path' => 'symfony/console',
            ]],
        ]));

        $ce = new ComposerExtra($cacheFile);
        $this->assertSame(['Legacy\\ConsoleServiceProvider'], $ce->collect('switon.providers'));

        $this->deletePath(dirname($installedFile));
    }

    /**
     * @requires OSFAMILY Linux Darwin
     */
    public function testAllOverlaysComposerPackageWhenVendorInstallPathPointsToSymlink(): void
    {
        $root = $this->tempDir . '/ce-symlink-overlay';
        $cachePayload = ['demo/overlay-pkg' => ['switon' => ['commands' => []]]];
        $cacheFile = $this->prepareComposerExtraLayout($root, $cachePayload, []);

        $livePkg = "{$root}/live/demo-overlay";
        mkdir($livePkg, 0755, true);
        file_put_contents("{$livePkg}/composer.json", Json::stringify([
            'name' => 'demo/overlay-pkg',
            'extra' => ['switon' => ['commands' => ['App\\Cli\\DemoCommand']]],
        ]));

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        $composerDir = dirname($installedFile);

        /** Same resolution algorithm as ComposerExtra::{@see ComposerExtra::resolveInstalledPackagePath()} */
        $vendorInstalledPath = $composerDir . '/../demo/overlay-pkg';
        mkdir(dirname($vendorInstalledPath), 0755, true);
        $this->deletePath($vendorInstalledPath);
        $this->assertTrue(symlink($livePkg, $vendorInstalledPath));

        file_put_contents($installedFile, Json::stringify([
            'packages' => [[
                'name' => 'demo/overlay-pkg',
                'install-path' => '../demo/overlay-pkg',
            ]],
        ]));

        try {
            $ce = new ComposerExtra($cacheFile);
            $this->assertSame(['App\\Cli\\DemoCommand'], $ce->collect('switon.commands'));
        } finally {
            if (is_link($vendorInstalledPath)) {
                @unlink($vendorInstalledPath);
            } else {
                $this->deletePath($vendorInstalledPath);
            }
        }
    }

    public function testAllDropCachedPackageExtraWhenComposerJsonOnlyDefinesBranchAliases(): void
    {
        $root = $this->tempDir . '/ce-branch-alias-overlay';
        $cachePayload = [
            'corp/demo' => ['switon' => ['listeners' => ['App\\CachedListener']]],
            'corp/other' => ['switon' => ['providers' => []]],
        ];

        $cacheFile = $this->prepareComposerExtraLayout($root, $cachePayload, [[
            'name' => 'corp/demo',
            'install-path' => '../corp/demo',
            'dist' => ['type' => 'path'],
        ]]);

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        $installPhysical = dirname($installedFile) . '/../corp/demo';
        file_put_contents("{$installPhysical}/composer.json", Json::stringify([
            'name' => 'corp/demo',
            'extra' => [
                'branch-alias' => [
                    'dev-main' => '1.0-dev',
                ],
            ],
        ]));

        $ce = new ComposerExtra($cacheFile);
        $this->assertSame([], $ce->collect('switon.listeners'));
        $this->assertSame([], $ce->get('corp/demo'));
        $this->assertNotSame([], $ce->get('corp/other'));

        @unlink("{$installPhysical}/composer.json");
        @rmdir($installPhysical);
    }

    /**
     * @requires OSFAMILY Linux Darwin
     */
    public function testOverlaySkipsLiveComposerJsonWhenUnreadable(): void
    {
        $root = $this->tempDir . '/ce-live-extra-unreadable';
        $payload = ['live/unread' => ['switon' => ['jobs' => ['App\\Queue\\CachedJob']]]];
        $cacheFile = $this->prepareComposerExtraLayout($root, $payload, [[
            'name' => 'live/unread',
            'install-path' => '../live/unread',
            'dist' => ['type' => 'path'],
        ]]);

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        $pkgDir = dirname($installedFile) . '/../live/unread';
        $composerLive = "{$pkgDir}/composer.json";
        file_put_contents($composerLive, Json::stringify([
            'name' => 'live/unread',
            'extra' => ['switon' => ['jobs' => ['App\\Queue\\RemoteJob']]],
        ]));
        $this->assertTrue(@chmod($composerLive, 0000));

        try {
            $ce = new ComposerExtra($cacheFile);
            $this->assertSame(['App\\Queue\\CachedJob'], $ce->collect('switon.jobs'));
        } finally {
            @chmod($composerLive, 0644);
            @unlink($composerLive);
            @rmdir($pkgDir);
        }
    }

    public function testOverlaySkipsPackageWhenComposerJsonMissingUnderPathRepo(): void
    {
        $root = $this->tempDir . '/ce-missing-composer-json';
        $payload = ['corp/missing' => ['switon' => ['listeners' => ['App\\CachedOnly']]]];
        $cacheFile = $this->prepareComposerExtraLayout($root, $payload, [[
            'name' => 'corp/missing',
            'install-path' => '../corp/missing',
            'dist' => ['type' => 'path'],
        ]]);

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        $pkgDir = dirname($installedFile) . '/../corp/missing';
        @unlink($pkgDir . '/composer.json');

        $ce = new ComposerExtra($cacheFile);
        $this->assertSame(['App\\CachedOnly'], $ce->collect('switon.listeners'));
    }

    public function testOverlaySkipsLiveComposerJsonWhenContentsAreInvalidJson(): void
    {
        $root = $this->tempDir . '/ce-live-invalid-json';
        $payload = ['corp/badjson' => ['switon' => ['tasks' => ['App\\Keep']]]];
        $cacheFile = $this->prepareComposerExtraLayout($root, $payload, [[
            'name' => 'corp/badjson',
            'install-path' => '../corp/badjson',
            'dist' => ['type' => 'path'],
        ]]);

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        $composerLive = dirname($installedFile) . '/../corp/badjson/composer.json';
        file_put_contents($composerLive, "{not-json");

        $ce = new ComposerExtra($cacheFile);
        $this->assertSame(['App\\Keep'], $ce->collect('switon.tasks'));

        @unlink($composerLive);
        @rmdir(dirname($composerLive));
    }

    public function testOverlaySkipsLiveComposerJsonWhenDecodedDocumentIsNotAnObject(): void
    {
        $root = $this->tempDir . '/ce-live-scalar-json';
        $payload = ['corp/scalar' => ['switon' => ['filters' => ['App\\Mw']]]];
        $cacheFile = $this->prepareComposerExtraLayout($root, $payload, [[
            'name' => 'corp/scalar',
            'install-path' => '../corp/scalar',
            'dist' => ['type' => 'path'],
        ]]);

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        $composerLive = dirname($installedFile) . '/../corp/scalar/composer.json';
        file_put_contents($composerLive, Json::stringify('scalar-document'));

        $ce = new ComposerExtra($cacheFile);
        $this->assertSame(['App\\Mw'], $ce->collect('switon.filters'));

        @unlink($composerLive);
        @rmdir(dirname($composerLive));
    }

    public function testAllOverlaysPackageMarkedSymlinkViaTransportOptionsWithoutDistPath(): void
    {
        $root = $this->tempDir . '/ce-transport-symlink-flag';
        $cachePayload = ['acme/flex' => ['switon' => ['listeners' => ['App\\StaleListener']]]];

        $cacheFile = $this->prepareComposerExtraLayout($root, $cachePayload, [[
            'name' => 'acme/flex',
            'install-path' => '../acme/flex',
            'transport-options' => ['symlink' => true],
            'extra' => ['switon' => ['listeners' => ['App\\OverlayListener']]],
        ]]);

        $ce = new ComposerExtra($cacheFile);
        $this->assertSame(['App\\OverlayListener'], $ce->collect('switon.listeners'));
    }

    public function testOverlaySkipsInstalledPackagesWithoutInstallPath(): void
    {
        $root = $this->tempDir . '/ce-no-install-path';
        $payload = ['stable/cache' => ['switon' => ['listeners' => ['App\\Cached']]]];
        $cacheFile = $this->prepareComposerExtraLayout($root, $payload, []);

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        file_put_contents($installedFile, Json::stringify([
            'packages' => [[
                'name' => 'broken/meta-only',
                'dist' => ['type' => 'path'],
            ]],
        ]));

        $ce = new ComposerExtra($cacheFile);
        $this->assertSame(['App\\Cached'], $ce->collect('switon.listeners'));
    }

    public function testOverlaySkipsInstalledPackagesWithEmptyInstallPath(): void
    {
        $root = $this->tempDir . '/ce-empty-install-path';
        $payload = ['stable/tasks' => ['switon' => ['tasks' => ['App\\Job']]]];
        $cacheFile = $this->prepareComposerExtraLayout($root, $payload, []);

        $installedFile = dirname($cacheFile, 2) . '/composer/installed.json';
        file_put_contents($installedFile, Json::stringify([
            'packages' => [[
                'name' => 'broken/empty-path',
                'install-path' => '',
                'dist' => ['type' => 'path'],
            ]],
        ]));

        $ce = new ComposerExtra($cacheFile);
        $this->assertSame(['App\\Job'], $ce->collect('switon.tasks'));
    }
}
