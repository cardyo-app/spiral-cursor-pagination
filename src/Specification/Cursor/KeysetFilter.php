<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Specification\Cursor;

use Cardyo\SpiralCursorPagination\Cursor\CursorData;
use Spiral\DataGrid\SpecificationInterface;

/**
 * Specification for keyset-based cursor filtering.
 *
 * Represents a WHERE clause for keyset pagination (e.g., WHERE (created_at, id) > (cursor_date, cursor_id)).
 * This enables efficient pagination without OFFSET by using indexed column comparisons.
 *
 * Example:
 * ```php
 * // Forward pagination: fetch items after cursor
 * $filter = new KeysetFilter(
 *     new CursorData(['created_at' => '2024-01-01', 'id' => 123]),
 *     'forward',
 *     ['created_at', 'id']
 * );
 * // Generates: WHERE created_at > '2024-01-01' OR (created_at = '2024-01-01' AND id > 123)
 * ```
 */
final class KeysetFilter implements SpecificationInterface
{
    public const DIRECTION_FORWARD = 'forward';

    public const DIRECTION_BACKWARD = 'backward';

    /**
     * @param CursorData $cursorData Cursor values to filter from
     * @param self::DIRECTION_* $direction Pagination direction
     * @param array<string> $fields Fields used in cursor (must match sort order)
     */
    public function __construct(
        private readonly CursorData $cursorData,
        private readonly string $direction,
        private readonly array $fields,
    ) {}

    /**
     * Get the cursor data.
     */
    public function getCursorData(): CursorData
    {
        return $this->cursorData;
    }

    /**
     * Get the pagination direction.
     *
     * @return self::DIRECTION_*
     */
    public function getDirection(): string
    {
        return $this->direction;
    }

    /**
     * Get the fields used in the cursor.
     *
     * @return array<string>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Check if this is forward pagination.
     */
    public function isForward(): bool
    {
        return $this->direction === self::DIRECTION_FORWARD;
    }

    /**
     * Check if this is backward pagination.
     */
    public function isBackward(): bool
    {
        return $this->direction === self::DIRECTION_BACKWARD;
    }

    #[\Override]
    public function getValue(): array
    {
        return [
            'cursorData' => $this->cursorData->toArray(),
            'direction' => $this->direction,
            'fields' => $this->fields,
        ];
    }
}
