<?php

declare(strict_types=1);

namespace Cardyo\Spiral\DataGrid\Specification\Cursor;

use Spiral\DataGrid\SpecificationInterface;

/**
 * Specification for limiting the number of items returned in cursor pagination.
 *
 * Represents the 'first' or 'last' parameters from GraphQL Relay Connection spec
 * and the 'page[size]' parameter from JSON:API cursor pagination profile.
 *
 * Unlike traditional limit/offset pagination, cursor pagination uses:
 * - 'first' N items after a cursor (forward pagination)
 * - 'last' N items before a cursor (backward pagination)
 * - 'page[size]' items (JSON:API format)
 *
 * Note: Writers should fetch N+1 items to determine if more pages exist.
 *
 * @see https://relay.dev/graphql/connections.htm#sec-Pagination-arguments
 * @see https://jsonapi.org/profiles/ethanresnick/cursor-pagination/#query-parameters
 */
final readonly class CursorLimit implements SpecificationInterface
{
    /**
     * @param int $limit The maximum number of items to return (must be positive)
     * @param bool $fetchExtra Whether to fetch N+1 items to determine hasNext/hasPrev
     */
    public function __construct(
        public int $limit,
        public bool $fetchExtra = true,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Cursor limit must be a positive integer');
        }
    }

    public function getValue(): int
    {
        return $this->limit;
    }

    /**
     * Get the actual limit to apply to the query (including extra item if needed).
     */
    public function getQueryLimit(): int
    {
        return $this->fetchExtra ? $this->limit + 1 : $this->limit;
    }

    /**
     * Check if the results should be truncated to the requested limit.
     */
    public function shouldTruncate(): bool
    {
        return $this->fetchExtra;
    }
}
