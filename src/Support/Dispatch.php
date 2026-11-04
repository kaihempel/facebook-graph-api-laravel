<?php

declare(strict_types=1);

namespace KaiHempel\MetaGraph\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Löst den Event-Dispatcher erst zum Zeitpunkt des Feuerns auf,
 * damit Event::fake() auch für bereits erzeugte Clients greift.
 */
final class Dispatch
{
    public static function event(object $event): void
    {
        $container = Container::getInstance();

        if ($container->bound(Dispatcher::class) || $container->bound('events')) {
            $container->make(Dispatcher::class)->dispatch($event);
        }
    }
}
