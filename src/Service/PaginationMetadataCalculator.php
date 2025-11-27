<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Service;

use Cardyo\SpiralCursorPagination\Response\PageInfo;

/**
 * Service for calculating pagination metadata (hasNextPage, hasPreviousPage).
 *
 * Uses the "limit+1" trick: fetch one extra record to detect if more pages exist,
 * then remove it before building the final result.
 */
final class PaginationMetadataCalculator
{
    /**
     * Calculate PageInfo from pagination state.
     *
     * @param int $resultCount Number of results fetched (including extra if any)
     * @param int $requestedLimit Requested page size (without extra)
     * @param bool $isBackward Whether this is backward pagination (last/before)
     * @param bool $hasCursor Whether a cursor was provided (after or before)
     * @param string|null $startCursor Cursor of first edge
     * @param string|null $endCursor Cursor of last edge
     */
    public function calculatePageInfo(
        int $resultCount,
        int $requestedLimit,
        bool $isBackward,
        bool $hasCursor,
        ?string $startCursor,
        ?string $endCursor,
    ): PageInfo {
        // Check if we got more results than requested (indicates more pages exist)
        $hasMore = $resultCount > $requestedLimit;

        // Forward pagination (first/after)
        return new PageInfo(
            hasNextPage: $hasMore,
            hasPreviousPage: $hasCursor,
            startCursor: $startCursor,
            endCursor: $endCursor,
        );
    }
}
