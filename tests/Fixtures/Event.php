<?php

namespace Tests\Fixtures;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class Event
{

    public function __construct(
        public int $eventId,
        public Status $status,
        public DateTimeImmutable $startsAt,
        public ?DateTimeInterface $endsAt = null,
        public string $label = 'none'
    ) {}
}
