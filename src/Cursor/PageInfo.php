<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Cursor;

/**
 * Pagination metadata compliant with GraphQL Cursor Connection specification.
 *
 * Contains information about the current page of results and whether
 * additional pages exist in either direction.
 *
 * @see https://relay.dev/graphql/connections.htm
 */
final readonly class PageInfo
{
    /**
     * @param bool $hasNextPage Whether more items exist after the current page
     * @param bool $hasPreviousPage Whether more items exist before the current page
     * @param string|null $startCursor Cursor of the first item in the current page (null if empty)
     * @param string|null $endCursor Cursor of the last item in the current page (null if empty)
     * @param int|null $totalCount Total number of items in the entire dataset (optional, may be expensive to compute)
     */
    public function __construct(
        public bool $hasNextPage,
        public bool $hasPreviousPage,
        public ?string $startCursor = null,
        public ?string $endCursor = null,
        public ?int $totalCount = null,
    ) {
    }

    /**
     * Create a PageInfo with no pagination information.
     * Useful when pagination is not applicable or the result set is empty.
     */
    public static function empty(): self
    {
        return new self(
            hasNextPage: false,
            hasPreviousPage: false,
            startCursor: null,
            endCursor: null,
            totalCount: 0,
        );
    }

    /**
     * Check if the current page is empty.
     */
    public function isEmpty(): bool
    {
        return $this->startCursor === null && $this->endCursor === null;
    }

    /**
     * Convert to array representation for JSON:API format.
     *
     * @return array{hasNext: bool, hasPrevious: bool, startCursor?: string, endCursor?: string, totalCount?: int}
     */
    public function toJsonApiArray(): array
    {
        $result = [
            'hasNext' => $this->hasNextPage,
            'hasPrevious' => $this->hasPreviousPage,
        ];

        if ($this->startCursor !== null) {
            $result['startCursor'] = $this->startCursor;
        }

        if ($this->endCursor !== null) {
            $result['endCursor'] = $this->endCursor;
        }

        if ($this->totalCount !== null) {
            $result['totalCount'] = $this->totalCount;
        }

        return $result;
    }

    /**
     * Convert to array representation for GraphQL format.
     *
     * @return array{hasNextPage: bool, hasPreviousPage: bool, startCursor: string|null, endCursor: string|null, totalCount?: int}
     */
    public function toGraphQLArray(): array
    {
        $result = [
            'hasNextPage' => $this->hasNextPage,
            'hasPreviousPage' => $this->hasPreviousPage,
            'startCursor' => $this->startCursor,
            'endCursor' => $this->endCursor,
        ];

        if ($this->totalCount !== null) {
            $result['totalCount'] = $this->totalCount;
        }

        return $result;
    }
}
