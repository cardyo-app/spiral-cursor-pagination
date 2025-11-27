<?php

declare(strict_types=1);

namespace Cardyo\SpiralCursorPagination\Response;

/**
 * GraphQL pagination metadata following the Complete Connection Model.
 *
 * Provides information about the current page and available navigation:
 * - hasNextPage: Whether more items exist after the current page
 * - hasPreviousPage: Whether more items exist before the current page
 * - startCursor: Cursor of the first item in the current page
 * - endCursor: Cursor of the last item in the current page
 *
 * @see https://relay.dev/graphql/connections.htm#sec-undefined.PageInfo
 *
 * @psalm-api
 */
final class PageInfo
{
    /**
     * @param bool $hasNextPage Whether more items exist after this page
     * @param bool $hasPreviousPage Whether more items exist before this page
     * @param string|null $startCursor Cursor of the first edge, null if no edges
     * @param string|null $endCursor Cursor of the last edge, null if no edges
     */
    public function __construct(
        /** @psalm-api */
        public readonly bool $hasNextPage,
        /** @psalm-api */
        public readonly bool $hasPreviousPage,
        /** @psalm-api */
        public readonly ?string $startCursor,
        /** @psalm-api */
        public readonly ?string $endCursor,
    ) {}
}
