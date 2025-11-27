<?php

declare(strict_types=1);

namespace Cardyo\Tests\SpiralCursorPagination\Fixtures;

use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\Customer;
use Cardyo\Tests\SpiralCursorPagination\Fixtures\Entity\User;
use DateTimeImmutable;

final class EntityFactory
{
    private static int $counter = 0;

    /**
     * Create a User entity with optional overrides.
     *
     * @param array<string, mixed> $attributes
     */
    public static function createUser(array $attributes = []): User
    {
        ++self::$counter;

        $defaults = [
            'name' => 'User ' . self::$counter,
            'email' => 'user' . self::$counter . '@example.com',
            'createdAt' => new DateTimeImmutable(),
            'updatedAt' => null,
            'activatedAt' => null,
            'lastActivityAt' => null,
            'loginCount' => 0,
        ];

        $merged = array_merge($defaults, $attributes);

        return new User(
            id: $merged['id'] ?? null,
            name: $merged['name'],
            email: $merged['email'],
            createdAt: $merged['createdAt'],
            updatedAt: $merged['updatedAt'],
            activatedAt: $merged['activatedAt'],
            lastActivityAt: $merged['lastActivityAt'],
            loginCount: $merged['loginCount'],
        );
    }

    /**
     * Create multiple User entities with sequential timestamps.
     *
     * @return array<User>
     */
    public static function createUsers(int $count, ?DateTimeImmutable $startDate = null): array
    {
        $startDate ??= new DateTimeImmutable('2024-01-01 00:00:00');
        $users = [];

        for ($i = 0; $i < $count; ++$i) {
            $createdAt = $startDate->modify('+' . $i . ' seconds');
            $users[] = self::createUser([
                'createdAt' => $createdAt,
            ]);
        }

        return $users;
    }

    /**
     * Create users with specific created_at timestamps.
     *
     * @param array<DateTimeImmutable> $timestamps
     * @return array<User>
     */
    public static function createUsersWithTimestamps(array $timestamps): array
    {
        $users = [];

        foreach ($timestamps as $timestamp) {
            $users[] = self::createUser([
                'createdAt' => $timestamp,
            ]);
        }

        return $users;
    }

    /**
     * Create a Customer entity with optional overrides.
     *
     * @param array<string, mixed> $attributes
     */
    public static function createCustomer(array $attributes = []): Customer
    {
        ++self::$counter;

        $defaults = [
            'uuid' => sprintf('550e8400-e29b-41d4-a716-4466554400%02d', self::$counter),
            'name' => 'Customer ' . self::$counter,
            'email' => 'customer' . self::$counter . '@example.com',
            'createdAt' => new DateTimeImmutable(),
            'updatedAt' => null,
            'lastActivityAt' => null,
        ];

        $merged = array_merge($defaults, $attributes);

        return new Customer(
            id: $merged['id'] ?? null,
            uuid: $merged['uuid'],
            name: $merged['name'],
            email: $merged['email'],
            createdAt: $merged['createdAt'],
            updatedAt: $merged['updatedAt'],
            lastActivityAt: $merged['lastActivityAt'],
        );
    }

    /**
     * Create multiple Customer entities with sequential timestamps.
     *
     * @return array<Customer>
     */
    public static function createCustomers(int $count, ?DateTimeImmutable $startDate = null): array
    {
        $startDate ??= new DateTimeImmutable('2024-01-01 00:00:00');
        $customers = [];

        for ($i = 0; $i < $count; ++$i) {
            $createdAt = $startDate->modify('+' . $i . ' days');
            $customers[] = self::createCustomer([
                'createdAt' => $createdAt,
            ]);
        }

        return $customers;
    }

    /**
     * Reset the counter (useful for test isolation).
     */
    public static function reset(): void
    {
        self::$counter = 0;
    }
}
