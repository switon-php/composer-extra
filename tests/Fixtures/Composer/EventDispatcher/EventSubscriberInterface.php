<?php

declare(strict_types=1);

namespace Composer\EventDispatcher;

if (!interface_exists(EventSubscriberInterface::class)) {
    interface EventSubscriberInterface
    {
        public static function getSubscribedEvents(): array;
    }
}
