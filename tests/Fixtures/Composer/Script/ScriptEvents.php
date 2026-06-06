<?php

declare(strict_types=1);

namespace Composer\Script;

if (!class_exists(ScriptEvents::class)) {
    class ScriptEvents
    {
        public const POST_INSTALL_CMD = 'post-install-cmd';
        public const POST_UPDATE_CMD = 'post-update-cmd';
    }
}
