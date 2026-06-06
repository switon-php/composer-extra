<?php

declare(strict_types=1);

namespace Composer\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;

if (!interface_exists(PluginInterface::class)) {
    interface PluginInterface
    {
        public function activate(Composer $composer, IOInterface $io): void;

        public function deactivate(Composer $composer, IOInterface $io): void;

        public function uninstall(Composer $composer, IOInterface $io): void;
    }
}
