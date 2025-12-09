<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Response;

/**
 * GraphQL Edge following the Complete Connection Model.
 *
 * An edge represents a single item in a paginated list along with its cursor.
 * The cursor uniquely identifies the edge's position in the full result set.
 *
 * @see https://relay.dev/graphql/connections.htm#sec-Edge-Types
 *
 * @psalm-api
 */
final class Edge
{
    /**
     * @param mixed $node The actual item/entity
     * @param string $cursor Opaque cursor identifying this edge's position
     */
    public function __construct(
        public readonly mixed $node,
        public readonly string $cursor,
    ) {}
}
