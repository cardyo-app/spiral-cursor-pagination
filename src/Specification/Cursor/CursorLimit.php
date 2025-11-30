<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Specification\Cursor;

use InvalidArgumentException;
use Spiral\DataGrid\SpecificationInterface;

/**
 * Specification for cursor pagination limit.
 *
 * Represents a LIMIT clause with an extra record for hasMore detection.
 * The limit is automatically incremented by 1 to fetch an extra record,
 * which is later removed from results to detect if more pages exist.
 *
 * Example:
 * ```php
 * $limit = new CursorLimit(10);
 * // Generates: LIMIT 11 (10 requested + 1 for detection)
 * ```
 */
final class CursorLimit implements SpecificationInterface
{
    /**
     * @param int $limit Number of records to fetch (will be incremented by 1)
     * @throws InvalidArgumentException If limit is not positive
     */
    public function __construct(
        private readonly int $limit,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be a positive integer');
        }
    }

    /**
     * Get the actual limit (requested + 1 for detection).
     */
    public function getLimit(): int
    {
        return $this->limit + 1;
    }

    /**
     * Get the requested limit (without the +1).
     */
    public function getRequestedLimit(): int
    {
        return $this->limit;
    }

    #[\Override]
    public function getValue(): int
    {
        return $this->getLimit();
    }
}
