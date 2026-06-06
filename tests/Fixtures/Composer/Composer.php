<?php

declare(strict_types=1);

namespace Composer;

if (!class_exists(Composer::class)) {
    class Composer
    {
        protected Config $config;

        public function __construct(?Config $config = null)
        {
            $this->config = $config ?? new Config();
        }

        public function getConfig(): Config
        {
            return $this->config;
        }
    }
}
