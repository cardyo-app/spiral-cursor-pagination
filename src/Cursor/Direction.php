<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Cursor;

/**
 * Represents the direction of cursor pagination.
 *
 * Forward pagination moves through the result set in the natural sort order.
 * Backward pagination moves through the result set in reverse sort order.
 */
enum Direction: string
{
    /**
     * Forward pagination (using 'after' cursor).
     * Fetches items that come after the cursor position in the sort order.
     */
    case FORWARD = 'forward';

    /**
     * Backward pagination (using 'before' cursor).
     * Fetches items that come before the cursor position in the sort order.
     */
    case BACKWARD = 'backward';

    /**
     * Check if this direction is forward.
     */
    public function isForward(): bool
    {
        return $this === self::FORWARD;
    }

    /**
     * Check if this direction is backward.
     */
    public function isBackward(): bool
    {
        return $this === self::BACKWARD;
    }

    /**
     * Get the opposite direction.
     */
    public function opposite(): self
    {
        return match ($this) {
            self::FORWARD => self::BACKWARD,
            self::BACKWARD => self::FORWARD,
        };
    }
}
