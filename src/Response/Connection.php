<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Response;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * GraphQL Connection following the Complete Connection Model.
 *
 * A Connection represents a paginated list of items with pagination metadata.
 * It provides multiple views of the data:
 * - edges: Array of Edge objects (items with cursors)
 * - nodes: Array of raw items (without cursors)
 * - pageInfo: Pagination metadata
 * - totalCount: Optional total number of items (may require additional query)
 *
 * @implements IteratorAggregate<int, Edge>
 *
 * @see https://relay.dev/graphql/connections.htm#sec-Connection-Types
 *
 * @psalm-api
 */
final class Connection implements Countable, IteratorAggregate
{
    /**
     * @param array<Edge> $edges List of edges with cursors
     * @param array<mixed> $nodes List of raw items
     * @param PageInfo $pageInfo Pagination metadata
     * @param int|null $totalCount Optional total count of all items
     */
    public function __construct(
        /** @psalm-api */
        public readonly array $edges,
        /** @psalm-api */
        public readonly array $nodes,
        /** @psalm-api */
        public readonly PageInfo $pageInfo,
        /** @psalm-api */
        public readonly ?int $totalCount = null,
    ) {}

    /**
     * Get the number of edges in this connection.
     *
     * @return int Number of edges
     */
    #[\Override]
    public function count(): int
    {
        return count($this->edges);
    }

    /**
     * Get an iterator for the edges.
     *
     * @return Traversable<int, Edge>
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->edges);
    }
}
