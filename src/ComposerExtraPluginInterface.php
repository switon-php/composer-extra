<?php

declare(strict_types=1);

namespace Switon\ComposerExtra;

/**
 * Marker contract for the Switon composer-extra package.
 *
 * Guidance: Do not use this interface as a runtime metadata reader; Switon runtime code reads the generated cache via <code>\Switon\ComposerExtra\ComposerExtra</code>.
 *
 * Road-signs:
 * - plugin activation belongs to Composer
 * - cache generation is write-only here
 * - runtime reads use ComposerExtra
 *
 * @see \Switon\ComposerExtra\Plugin
 * @see \Switon\ComposerExtra\ComposerExtra
 */
interface ComposerExtraPluginInterface
{
}
