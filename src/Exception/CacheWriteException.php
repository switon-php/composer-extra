<?php

declare(strict_types=1);

namespace Switon\ComposerExtra\Exception;

use Switon\ComposerExtra\Exception as BaseException;

/**
 * Exception for failures while encoding or writing composer-extra cache.
 *
 * Use when the Composer plugin cannot persist the generated cache file.
 *
 * @see \Switon\ComposerExtra\Plugin::writeCacheFile()
 */
class CacheWriteException extends BaseException
{
}
