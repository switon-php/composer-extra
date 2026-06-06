<?php

declare(strict_types=1);

namespace Composer;

if (!class_exists(Config::class)) {
    class Config
    {
        /** @var array<string, mixed> */
        protected array $values;

        /**
         * @param array<string, mixed> $values
         */
        public function __construct(array $values = [])
        {
            $this->values = $values;
        }

        public function get(string $name): mixed
        {
            return $this->values[$name] ?? null;
        }
    }
}
