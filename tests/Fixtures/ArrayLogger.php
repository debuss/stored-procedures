<?php

namespace Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keep the log records in memory, to assert on them.
 */
final class ArrayLogger extends AbstractLogger
{

    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
