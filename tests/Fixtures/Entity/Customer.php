<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity;

/**
 * Customer entity for testing pagination with UUID sorting.
 *
 * Demonstrates cursor pagination with non-sequential identifiers.
 */
class Customer
{
    public function __construct(
        private ?int $id = null,
        private readonly string $uuid = '',
        private readonly string $name = '',
        private readonly string $email = '',
        private readonly ?\DateTimeImmutable $createdAt = null,
        private readonly ?\DateTimeImmutable $updatedAt = null,
        private readonly ?\DateTimeImmutable $lastActivityAt = null,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }
}
