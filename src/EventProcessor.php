<?php

declare(strict_types=1);

namespace XInfra;

/**
 * Adds data to an event before it is buffered. Returning null drops the event.
 */
interface EventProcessor
{
    public function process(Event $event): ?Event;
}
