<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Response;

/**
 * GraphQL edge following the Complete Connection Model.
 *
 * An edge represents an item in a paginated list along with its cursor.
 * The cursor is an opaque string that can be used to fetch items before
 * or after this edge.
 *
 * @see https://relay.dev/graphql/connections.htm#sec-Edge-Types
 */
final class Edge
{
    /**
     * @param mixed $node The actual data item (entity, array, etc.)
     * @param string $cursor Opaque cursor string for this edge
     */
    public function __construct(
        public readonly mixed $node,
        public readonly string $cursor,
    ) {}
}
