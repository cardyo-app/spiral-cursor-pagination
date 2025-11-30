<?php

namespace Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity;

class Customer
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly string $email,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly ?\DateTimeImmutable $lastActivityAt = null,
        public readonly int $loginCount = 0,
    ) {

    }
}
