<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Specification\Cursor;

use Cardyo\SpiralCursorPagination\Cursor\CursorDirection;
use Spiral\DataGrid\SpecificationInterface;

/**
 * Specification for cursor-aware sorting.
 *
 * Represents an ORDER BY clause that may need to be reversed for backward pagination.
 * When paginating backward, the sort order is reversed to fetch records in the
 * opposite direction, then results are reversed back to maintain consistent ordering.
 *
 * Example:
 * ```php
 * // Forward pagination
 * $sort = new SortDirection(['created_at' => 'DESC', 'id' => 'ASC'], CursorDirection::FORWARD);
 * // Generates: ORDER BY created_at DESC, id ASC
 *
 * // Backward pagination (reversed)
 * $sort = new SortDirection(['created_at' => 'DESC', 'id' => 'ASC'], CursorDirection::BACKWARD);
 * // Generates: ORDER BY created_at ASC, id DESC
 * ```
 */
final class SortDirection implements SpecificationInterface
{
    /**
     * @param array<string, string> $fields Field name => direction ('ASC' or 'DESC')
     * @param CursorDirection $paginationDirection Pagination direction
     */
    public function __construct(
        private readonly array $fields,
        private readonly CursorDirection $paginationDirection,
    ) {}

    /**
     * Get the fields with their sort directions.
     *
     * @return array<string, string> Field name => direction
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Get the pagination direction.
     */
    public function getPaginationDirection(): CursorDirection
    {
        return $this->paginationDirection;
    }

    /**
     * Check if sort order should be reversed.
     */
    public function shouldReverse(): bool
    {
        return $this->paginationDirection->isBackward();
    }

    /**
     * Get the effective sort directions (reversed if backward pagination).
     *
     * @return array<string, string>
     */
    public function getEffectiveDirections(): array
    {
        if (!$this->shouldReverse()) {
            return $this->fields;
        }

        return array_map(
            fn(string $direction): string => $direction === 'ASC' ? 'DESC' : 'ASC',
            $this->fields,
        );
    }

    #[\Override]
    public function getValue(): array
    {
        return [
            'fields' => $this->fields,
            'paginationDirection' => $this->paginationDirection->value,
            'effectiveDirections' => $this->getEffectiveDirections(),
        ];
    }
}
