<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Specification\Cursor;

use Spiral\DataGrid\SpecificationInterface;

/**
 * Specification for sort order in cursor pagination.
 *
 * Defines which fields to sort by and their direction (ASC/DESC).
 * For backward pagination, the direction is automatically reversed.
 *
 * Example:
 * ```php
 * // Forward pagination: ORDER BY created_at ASC, id ASC
 * $sort = new SortDirection(['created_at', 'id'], false);
 *
 * // Backward pagination: ORDER BY created_at DESC, id DESC
 * $sort = new SortDirection(['created_at', 'id'], true);
 * ```
 */
final class SortDirection implements SpecificationInterface
{
    public const ORDER_ASC = 'ASC';

    public const ORDER_DESC = 'DESC';

    /**
     * @param array<string> $fields Fields to sort by (in priority order)
     * @param bool $reversed Whether to reverse the sort direction (for backward pagination)
     * @param self::ORDER_* $defaultDirection Default sort direction when not reversed
     */
    public function __construct(
        private readonly array $fields,
        private readonly bool $reversed = false,
        private readonly string $defaultDirection = self::ORDER_ASC,
    ) {}

    /**
     * Get the fields to sort by.
     *
     * @return array<string>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Check if sort direction should be reversed.
     */
    public function isReversed(): bool
    {
        return $this->reversed;
    }

    /**
     * Get the effective sort direction.
     *
     * Returns DESC if reversed and defaultDirection is ASC, or vice versa.
     *
     * @return self::ORDER_*
     */
    public function getDirection(): string
    {
        if ($this->reversed) {
            return $this->defaultDirection === self::ORDER_ASC ? self::ORDER_DESC : self::ORDER_ASC;
        }

        return $this->defaultDirection;
    }

    /**
     * Get the default (non-reversed) direction.
     *
     * @return self::ORDER_*
     */
    public function getDefaultDirection(): string
    {
        return $this->defaultDirection;
    }

    #[\Override]
    public function getValue(): array
    {
        return [
            'fields' => $this->fields,
            'direction' => $this->getDirection(),
            'reversed' => $this->reversed,
        ];
    }
}
