<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Specification\Cursor;

use Cardyo\Spiral\DataGrid\Cursor\Direction;
use Spiral\DataGrid\SpecificationInterface;

/**
 * Specification indicating the direction of cursor pagination.
 *
 * Forward direction (AFTER cursor):
 * - Items are returned in the natural sort order
 * - Query: WHERE (sort_fields) > (cursor_values)
 * - ORDER BY: sort_fields ASC
 *
 * Backward direction (BEFORE cursor):
 * - Items are returned in reverse sort order (then reversed again)
 * - Query: WHERE (sort_fields) < (cursor_values)
 * - ORDER BY: sort_fields DESC
 * - Results are reversed after fetching
 *
 * This specification helps writers apply the correct sort order and filtering
 * logic for cursor-based pagination.
 */
final readonly class CursorDirection implements SpecificationInterface
{
    /**
     * @param Direction $direction The pagination direction
     */
    public function __construct(
        public Direction $direction,
    ) {
    }

    public function getValue(): Direction
    {
        return $this->direction;
    }

    /**
     * Check if this is forward pagination.
     */
    public function isForward(): bool
    {
        return $this->direction->isForward();
    }

    /**
     * Check if this is backward pagination.
     */
    public function isBackward(): bool
    {
        return $this->direction->isBackward();
    }
}
