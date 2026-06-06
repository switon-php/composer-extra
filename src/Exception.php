<?php

declare(strict_types=1);

namespace Switon\ComposerExtra;

/**
 * Base exception for composer-extra specific failures.
 *
 * Use this as the parent for runtime cache-read and plugin-write failures.
 *
 * @see \Switon\ComposerExtra\Plugin
 */
class Exception extends \Switon\Core\Exception\RuntimeException
{
}
