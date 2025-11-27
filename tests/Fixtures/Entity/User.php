<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity;

use DateTimeImmutable;

class User
{
    public function __construct(private ?int $id = null, private string $name = '', private string $email = '', private ?DateTimeImmutable $createdAt = new DateTimeImmutable(), private ?DateTimeImmutable $updatedAt = null, private ?DateTimeImmutable $activatedAt = null, private ?DateTimeImmutable $lastActivityAt = null, private int $loginCount = 0) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getActivatedAt(): ?DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(?DateTimeImmutable $activatedAt): void
    {
        $this->activatedAt = $activatedAt;
    }

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?DateTimeImmutable $lastActivityAt): void
    {
        $this->lastActivityAt = $lastActivityAt;
    }

    public function getLoginCount(): int
    {
        return $this->loginCount;
    }

    public function setLoginCount(int $loginCount): void
    {
        $this->loginCount = $loginCount;
    }
}
