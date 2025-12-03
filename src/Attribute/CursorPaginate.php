<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Attribute;

use Attribute;

/**
 * Attribute for automatic cursor pagination in controller methods.
 *
 * Similar to DataGrid's integration, this attribute marks a parameter
 * that should receive a Connection response with cursor-paginated data.
 *
 * Example usage:
 * ```php
 * class CustomerController
 * {
 *     public function index(
 *         #[CursorPaginate(
 *             entity: Customer::class,
 *             schema: CustomerGridSchema::class,
 *             mapper: [CustomerDTO::class, 'fromEntity']
 *         )]
 *         Connection $connection
 *     ): Connection {
 *         return $connection;
 *     }
 * }
 * ```
 *
 * Or with minimal configuration:
 * ```php
 * public function index(
 *     #[CursorPaginate(Customer::class)]
 *     Connection $connection
 * ): Connection {
 *     return $connection;
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CursorPaginate
{
    /**
     * @param class-string $entity Entity class name
     * @param class-string|null $schema GridSchema class name (optional, will try to auto-detect)
     * @param callable|array|string|null $mapper Mapper function to transform entities to DTOs
     * @param int|null $pageSize Default page size (overrides helper default)
     * @param int|null $maxPageSize Maximum page size (overrides helper max)
     * @param bool $countTotal Whether to count total records (expensive operation)
     */
    public function __construct(
        public readonly string $entity,
        public readonly ?string $schema = null,
        public readonly mixed $mapper = null,
        public readonly ?int $pageSize = null,
        public readonly ?int $maxPageSize = null,
        public readonly bool $countTotal = false,
    ) {}
}
