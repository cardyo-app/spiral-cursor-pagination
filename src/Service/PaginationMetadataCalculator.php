<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Service;

use Cardyo\SpiralCursorPagination\Response\PageInfo;

/**
 * Service for calculating pagination metadata.
 *
 * Determines hasNextPage and hasPreviousPage based on result counts and cursors.
 */
final class PaginationMetadataCalculator
{
    /**
     * Calculate PageInfo from pagination state.
     *
     * @param int $resultCount Number of results fetched (includes extra record if any)
     * @param int $requestedLimit Requested page size
     * @param bool $isBackward Whether this is backward pagination
     * @param bool $hasCursor Whether a cursor was provided
     * @param string|null $startCursor First cursor in results
     * @param string|null $endCursor Last cursor in results
     * @return PageInfo Pagination metadata
     */
    public function calculatePageInfo(
        int $resultCount,
        int $requestedLimit,
        bool $isBackward,
        bool $hasCursor,
        ?string $startCursor,
        ?string $endCursor,
    ): PageInfo {
        $hasMore = $resultCount > $requestedLimit;

        if ($isBackward) {
            $hasNextPage = $hasCursor;
            $hasPreviousPage = $hasMore;
        } else {
            $hasNextPage = $hasMore;
            $hasPreviousPage = $hasCursor;
        }

        return new PageInfo(
            hasNextPage: $hasNextPage,
            hasPreviousPage: $hasPreviousPage,
            startCursor: $startCursor,
            endCursor: $endCursor,
        );
    }
}
