<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Specification\Cursor;

use Spiral\DataGrid\SpecificationInterface;

/**
 * Limit specification for cursor pagination with metadata.
 *
 * Similar to Spiral's Limit specification, but with additional context
 * for cursor pagination. The fetchExtra flag indicates that an extra
 * record should be fetched to determine if more pages exist.
 *
 * Example:
 * ```php
 * // Fetch 11 records to check if there's a next page
 * $limit = new CursorLimit(10, fetchExtra: true);
 * // Later: hasNextPage = (count($results) > 10)
 * ```
 */
final class CursorLimit implements SpecificationInterface
{
    /**
     * @param int $value Maximum number of records to return (before extra)
     * @param bool $fetchExtra Whether to fetch one extra record for hasNext detection
     */
    public function __construct(
        private readonly int $value,
        private readonly bool $fetchExtra = false,
    ) {}

    /**
     * Get the limit value.
     *
     * If fetchExtra is true, this returns value + 1 for the actual query limit.
     * Otherwise, returns the original value.
     *
     * @return int Maximum number of records to fetch
     */
    #[\Override]
    public function getValue(): int
    {
        return $this->value;
    }

    /**
     * Get the actual limit to apply to the query.
     *
     * This includes the extra record if fetchExtra is enabled.
     *
     * @return int Actual limit for the query
     */
    public function getQueryLimit(): int
    {
        return $this->fetchExtra ? $this->value + 1 : $this->value;
    }

    /**
     * Check if extra record should be fetched.
     *
     * @return bool True if should fetch limit+1 records
     */
    public function shouldFetchExtra(): bool
    {
        return $this->fetchExtra;
    }

    /**
     * Get the requested page size (without extra).
     *
     * @return int Requested page size
     */
    public function getPageSize(): int
    {
        return $this->value;
    }
}
