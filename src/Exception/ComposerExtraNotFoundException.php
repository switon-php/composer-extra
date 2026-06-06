<?php

declare(strict_types=1);

namespace Switon\ComposerExtra\Exception;

use Switon\ComposerExtra\Exception as BaseException;

/**
 * Signals missing Composer extra metadata cache.
 *
 * Use when runtime code needs cached metadata but the generated file is missing.
 *
 * @see \Switon\ComposerExtra\ComposerExtra
 */
class ComposerExtraNotFoundException extends BaseException
{
}
